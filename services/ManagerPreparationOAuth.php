<?php

declare(strict_types=1);

require_once __DIR__.'/ConversationDb.php';
require_once __DIR__.'/ProjectConfig.php';

/** Narrow public-client OAuth boundary. This service never reads a transcript. */
final class ManagerPreparationOAuth
{
    public const ORIGIN = 'https://app.anytoour.ru';
    // RFC 8414 inserts the well-known segment before this issuer path. The
    // explicit PHP discovery route works without directory-index permissions.
    public const ISSUER = self::ORIGIN.'/manager/ai-oauth.php';
    public const RESOURCE = self::ORIGIN.'/manager/ai-mcp.php';
    public const ENDPOINT = self::ORIGIN.'/manager/ai-oauth.php';
    public const SCOPES = ['queue:read', 'prepared:write'];
    // RFC 9207 issuer identification permits this documented stable callback.
    public const REDIRECT_URI = 'https://chatgpt.com/connector_platform_oauth_redirect';
    private PDO $pdo;
    private $clock;

    public function __construct(?PDO $pdo = null, ?callable $clock = null)
    {
        $this->pdo = $pdo ?? ConversationDb::connection();
        $this->clock = $clock ?? static fn(): int => time();
    }

    public static function authorizationMetadata(): array
    {
        return [
            'issuer'=>self::ISSUER,
            'authorization_response_iss_parameter_supported'=>true,
            'authorization_endpoint'=>self::ENDPOINT.'?action=authorize',
            'token_endpoint'=>self::ENDPOINT.'?action=token',
            'registration_endpoint'=>self::ENDPOINT.'?action=register',
            'revocation_endpoint'=>self::ENDPOINT.'?action=revoke',
            'token_endpoint_auth_methods_supported'=>['none'],
            'revocation_endpoint_auth_methods_supported'=>['none'],
            'response_types_supported'=>['code'],
            'grant_types_supported'=>['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported'=>['S256'],
            'scopes_supported'=>self::SCOPES,
        ];
    }

    public static function resourceMetadata(): array
    {
        return ['resource'=>self::RESOURCE, 'authorization_servers'=>[self::ISSUER],
            'scopes_supported'=>self::SCOPES, 'bearer_methods_supported'=>['header']];
    }

    public static function authenticateBearer(string $authorization): ?int
    {
        try {
            return (new self())->verifyBearer($authorization);
        } catch (Throwable $error) {
            return null;
        }
    }

    public static function connectionIdForBearer(string $authorization): ?string
    {
        try {
            return (new self())->connectionForBearer($authorization);
        } catch (Throwable $error) {
            return null;
        }
    }

    public static function isConnectionActive(string $familyId, int $adminId): bool
    {
        try {
            return (new self())->connectionActive($familyId,$adminId);
        } catch (Throwable $error) {
            return false;
        }
    }

    /** Every use checks current administrator, current project and live grant. */
    public function verifyBearer(string $authorization): ?int
    {
        $grant=$this->grantForBearer($authorization);
        return $grant ? (int)$grant['admin_id'] : null;
    }

    public function connectionForBearer(string $authorization): ?string
    {
        $grant=$this->grantForBearer($authorization);
        return $grant ? (string)$grant['grant_id'] : null;
    }

    public function connectionActive(string $familyId, int $adminId): bool
    {
        if (!preg_match('/\A[a-f0-9]{32}\z/D',$familyId) || $adminId<=0) return false;
        $q=$this->pdo->prepare('SELECT g.* FROM manager_preparation_oauth_grants g JOIN manager_preparation_oauth_clients c ON c.client_id=g.client_id WHERE g.grant_id=? AND g.admin_id=? LIMIT 1');
        $q->execute([$familyId,$adminId]);
        $grant=$q->fetch(PDO::FETCH_ASSOC);
        return $grant && $this->validGrant($grant);
    }

    private function grantForBearer(string $authorization): ?array
    {
        if (!preg_match('/\ABearer ([A-Za-z0-9_-]{43})\z/Di', $authorization, $match)) return null;
        $q=$this->pdo->prepare("SELECT g.* FROM manager_preparation_oauth_tokens t
            JOIN manager_preparation_oauth_grants g ON g.grant_id=t.grant_id
            JOIN manager_preparation_oauth_clients c ON c.client_id=g.client_id
            WHERE t.token_hash=? AND t.token_kind='access' AND t.expires_at>?
            AND t.consumed_at IS NULL AND g.revoked_at IS NULL AND g.expires_at>? LIMIT 1");
        $q->execute([hash('sha256',$match[1]),$this->now(),$this->now()]);
        $grant=$q->fetch(PDO::FETCH_ASSOC);
        if (!$grant || !$this->validGrant($grant)) return null;
        return $grant;
    }

    public function registerClient(array $input, string $registrationKey): array
    {
        $redirects=$input['redirect_uris']??null;
        if ($redirects !== [self::REDIRECT_URI]
            || ($input['token_endpoint_auth_method']??'none') !== 'none'
            || ($input['response_types']??['code']) !== ['code']) $this->fail('invalid_client_metadata');
        $grants=$input['grant_types']??['authorization_code','refresh_token'];
        if (!is_array($grants) || array_filter($grants,static fn($value):bool=>!is_string($value)) || !in_array('authorization_code',$grants,true)
            || count($grants)!==count(array_unique($grants,SORT_REGULAR))
            || array_diff($grants,['authorization_code','refresh_token'])) $this->fail('invalid_client_metadata');
        if (isset($input['scope'])) $this->scope($input['scope']);
        // No client-supplied URI, logo, JWKS or metadata is ever fetched.
        $bucket=hash('sha256', $registrationKey);
        $q=$this->pdo->prepare('SELECT COUNT(*) FROM manager_preparation_oauth_clients WHERE registration_bucket=? AND created_at>?');
        $q->execute([$bucket,$this->now()-3600]);
        if ((int)$q->fetchColumn()>=30
            || (int)$this->pdo->query('SELECT COUNT(*) FROM manager_preparation_oauth_clients')->fetchColumn()>=5000) $this->fail('temporarily_unavailable');
        $id=bin2hex(random_bytes(24));
        $this->pdo->prepare('INSERT INTO manager_preparation_oauth_clients(client_id,redirect_uri,registration_bucket,created_at) VALUES(?,?,?,?)')
            ->execute([$id,self::REDIRECT_URI,$bucket,$this->now()]);
        return ['client_id'=>$id,'client_id_issued_at'=>$this->now(), 'redirect_uris'=>[self::REDIRECT_URI],
            'token_endpoint_auth_method'=>'none','grant_types'=>$grants,'response_types'=>['code'],
            'scope'=>implode(' ',self::SCOPES)];
    }

    public function validateAuthorization(array $request): array
    {
        $client=$this->text($request,'client_id',48);
        if (!preg_match('/\A[a-f0-9]{48}\z/D',$client) || !$this->client($client)) $this->fail('invalid_client');
        $redirect=$this->text($request,'redirect_uri',255);
        if ($redirect!==self::REDIRECT_URI) $this->fail('invalid_request');
        if ($this->text($request,'response_type',20)!=='code'
            || $this->text($request,'code_challenge_method',20)!=='S256') $this->fail('invalid_request');
        $challenge=$this->text($request,'code_challenge',43);
        if (!preg_match('/\A[A-Za-z0-9_-]{43}\z/D',$challenge)) $this->fail('invalid_request');
        $resource=$this->text($request,'resource',255);
        if ($resource!==self::RESOURCE) $this->fail('invalid_target');
        $state=$this->text($request,'state',1024);
        if ($state==='' || preg_match('/[\x00-\x1f\x7f]/',$state)) $this->fail('invalid_request');
        return ['client_id'=>$client,'redirect_uri'=>$redirect,'response_type'=>'code','code_challenge'=>$challenge,
            'code_challenge_method'=>'S256','resource'=>$resource,'state'=>$state,'scope'=>$this->scope($request['scope']??null)];
    }

    public function beginAuthorization(array &$session, array $request): string
    {
        $request=$this->validateAuthorization($request);
        $pending=$session['manager_preparation_oauth_pending']??[];
        if (!is_array($pending)) $pending=[];
        foreach ($pending as $id=>$value) if (!is_array($value) || (int)($value['expires_at']??0)<=$this->now()) unset($pending[$id]);
        if (count($pending)>=5) array_shift($pending);
        $id=bin2hex(random_bytes(24));
        $pending[$id]=['request'=>$request,'expires_at'=>$this->now()+600,'admin_id'=>0];
        $session['manager_preparation_oauth_pending']=$pending;
        return $id;
    }

    /** Bind a resumed request to the account viewing the explicit consent. */
    public function pendingAuthorization(array &$session, string $id, int $adminId = 0): array
    {
        if (!preg_match('/\A[a-f0-9]{48}\z/D',$id)) $this->fail('invalid_request');
        $pending=$session['manager_preparation_oauth_pending'][$id]??null;
        if (!is_array($pending) || (int)($pending['expires_at']??0)<=$this->now()) $this->fail('invalid_request');
        if ($adminId>0) {
            if (!$this->activeAdmin($adminId)) $this->fail('access_denied');
            if ((int)$pending['admin_id']!==0 && (int)$pending['admin_id']!==$adminId) $this->fail('access_denied');
            $session['manager_preparation_oauth_pending'][$id]['admin_id']=$adminId;
        }
        return $this->validateAuthorization($pending['request']);
    }

    public function completeAuthorization(array &$session, string $pendingId, int $adminId, string $csrf, bool $allow): string
    {
        $this->checkCsrf($session,$csrf);
        $request=$this->pendingAuthorization($session,$pendingId,$adminId);
        if ($adminId<=0 || !$this->activeAdmin($adminId)) $this->fail('access_denied');
        // Issuing a code always follows an explicit POST consent; no remembered auto-consent.
        if (!$allow) {
            unset($session['manager_preparation_oauth_pending'][$pendingId]);
            return $this->redirect($request,['error'=>'access_denied']);
        }
        $this->transactionStart();
        try {
            if (!$this->activeAdmin($adminId)) $this->fail('access_denied');
            $grant=bin2hex(random_bytes(16));
            $code=$this->opaque();
            $this->pdo->prepare('INSERT INTO manager_preparation_oauth_grants(grant_id,client_id,admin_id,project_key,resource,scopes,created_at,expires_at,revoked_at) VALUES(?,?,?,?,?,?,?,?,NULL)')
                ->execute([$grant,$request['client_id'],$adminId,ProjectConfig::projectId(),self::RESOURCE,$request['scope'],$this->now(),$this->now()+30*86400]);
            $this->pdo->prepare('INSERT INTO manager_preparation_oauth_codes(code_hash,grant_id,redirect_uri,code_challenge,expires_at,consumed_at) VALUES(?,?,?,?,?,NULL)')
                ->execute([hash('sha256',$code),$grant,$request['redirect_uri'],$request['code_challenge'],$this->now()+300]);
            $this->pdo->commit();
            unset($session['manager_preparation_oauth_pending'][$pendingId]);
            return $this->redirect($request,['code'=>$code]);
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function exchange(array $input): array
    {
        $client=$this->text($input,'client_id',48);
        if (!preg_match('/\A[a-f0-9]{48}\z/D',$client) || !$this->client($client)) $this->fail('invalid_client');
        if (isset($input['client_secret']) || isset($input['client_assertion'])) $this->fail('invalid_client');
        if ($this->text($input,'resource',255)!==self::RESOURCE) $this->fail('invalid_target');
        $type=$this->text($input,'grant_type',40);
        if (!in_array($type,['authorization_code','refresh_token'],true)) $this->fail('unsupported_grant_type');
        $secret=$this->text($input,$type==='authorization_code'?'code':'refresh_token',43);
        if (!preg_match('/\A[A-Za-z0-9_-]{43}\z/D',$secret)) $this->fail('invalid_grant');
        $verifier='';
        if ($type==='authorization_code') {
            if ($this->text($input,'redirect_uri',255)!==self::REDIRECT_URI) $this->fail('invalid_grant');
            $verifier=$this->text($input,'code_verifier',128);
            if (!preg_match('/\A[A-Za-z0-9._~-]{43,128}\z/D',$verifier)) $this->fail('invalid_grant');
        } elseif (isset($input['scope']) && $this->scope($input['scope'])!==implode(' ',self::SCOPES)) $this->fail('invalid_scope');
        $this->transactionStart();
        try {
            $table=$type==='authorization_code'?'manager_preparation_oauth_codes':'manager_preparation_oauth_tokens';
            $column=$type==='authorization_code'?'code_hash':'token_hash';
            $q=$this->pdo->prepare('SELECT * FROM '.$table.' WHERE '.$column.'=?'.$this->lockSuffix());
            $q->execute([hash('sha256',$secret)]);
            $credential=$q->fetch(PDO::FETCH_ASSOC);
            if (!$credential || ($type==='refresh_token' && $credential['token_kind']!=='refresh')) $this->fail('invalid_grant');
            $q=$this->pdo->prepare('SELECT * FROM manager_preparation_oauth_grants WHERE grant_id=?'.$this->lockSuffix());
            $q->execute([$credential['grant_id']]);
            $grant=$q->fetch(PDO::FETCH_ASSOC);
            if (!$grant || $grant['client_id']!==$client) $this->fail('invalid_grant');
            if ($type==='authorization_code' && $credential['redirect_uri']!==self::REDIRECT_URI) $this->fail('invalid_grant');
            if ($type==='authorization_code' && !hash_equals($credential['code_challenge'],$this->base64url(hash('sha256',$verifier,true)))) $this->fail('invalid_grant');
            // A replayed refresh or code revokes this consent's complete token family.
            if ($credential['consumed_at']!==null) {
                $this->pdo->prepare('UPDATE manager_preparation_oauth_grants SET revoked_at=? WHERE grant_id=? AND revoked_at IS NULL')
                    ->execute([$this->now(),$grant['grant_id']]);
                $this->pdo->commit();
                $this->fail('invalid_grant');
            }
            if ((int)$credential['expires_at']<=$this->now() || !$this->validGrant($grant)) $this->fail('invalid_grant');
            $q=$this->pdo->prepare('UPDATE '.$table.' SET consumed_at=? WHERE '.$column.'=? AND consumed_at IS NULL');
            $q->execute([$this->now(),hash('sha256',$secret)]);
            if ($q->rowCount()!==1) $this->fail('invalid_grant');
            $tokens=$this->issueTokens($grant);
            $this->pdo->commit();
            return $tokens;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    /** RFC 7009: always a non-disclosing success for unknown/client-mismatched tokens. */
    public function revoke(array $input): void
    {
        $token=$this->text($input,'token',43);
        $client=$this->text($input,'client_id',48);
        if (!preg_match('/\A[A-Za-z0-9_-]{43}\z/D',$token) || !preg_match('/\A[a-f0-9]{48}\z/D',$client)) return;
        $q=$this->pdo->prepare('SELECT g.grant_id FROM manager_preparation_oauth_tokens t JOIN manager_preparation_oauth_grants g ON g.grant_id=t.grant_id WHERE t.token_hash=? AND g.client_id=? LIMIT 1');
        $q->execute([hash('sha256',$token),$client]);
        $grant=$q->fetchColumn();
        if ($grant) $this->pdo->prepare('UPDATE manager_preparation_oauth_grants SET revoked_at=? WHERE grant_id=? AND revoked_at IS NULL')->execute([$this->now(),$grant]);
    }

    public function connections(int $adminId): array
    {
        if (!$this->activeAdmin($adminId)) $this->fail('access_denied');
        $q=$this->pdo->prepare('SELECT grant_id,created_at,expires_at FROM manager_preparation_oauth_grants WHERE admin_id=? AND project_key=? AND revoked_at IS NULL AND expires_at>? ORDER BY created_at DESC LIMIT 50');
        $q->execute([$adminId,ProjectConfig::projectId(),$this->now()]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function disconnect(array $session, int $adminId, string $grant, string $csrf): void
    {
        $this->checkCsrf($session,$csrf);
        if (!$this->activeAdmin($adminId)) $this->fail('access_denied');
        if (!preg_match('/\A[a-f0-9]{32}\z/D',$grant)) $this->fail('invalid_request');
        $this->pdo->prepare('UPDATE manager_preparation_oauth_grants SET revoked_at=? WHERE grant_id=? AND admin_id=? AND project_key=? AND revoked_at IS NULL')
            ->execute([$this->now(),$grant,$adminId,ProjectConfig::projectId()]);
    }

    public function activeAdmin(int $id): bool
    {
        if ($id<=0) return false;
        // The ordinary login remains the identity owner. Never bootstrap roles or access here.
        $project=ProjectConfig::projectId();
        $name=(string)ProjectConfig::get('brand.name',$project);
        $q=$this->pdo->prepare("SELECT 1 FROM managers m JOIN manager_projects mp ON mp.manager_id=m.id
            JOIN projects p ON p.id=mp.project_id WHERE m.id=? AND m.is_active=1 AND m.role='admin'
            AND p.project_key=? AND p.is_active=1 AND p.display_name=? LIMIT 1");
        $q->execute([$id,$project,$name!==''?$name:$project]);
        return (bool)$q->fetchColumn();
    }

    private function validGrant(array $grant): bool
    {
        return $grant['revoked_at']===null && (int)$grant['expires_at']>$this->now()
            && $grant['resource']===self::RESOURCE && $grant['project_key']===ProjectConfig::projectId()
            && $grant['scopes']===implode(' ',self::SCOPES) && $this->activeAdmin((int)$grant['admin_id']);
    }

    private function issueTokens(array $grant): array
    {
        $access=$this->opaque(); $refresh=$this->opaque();
        $ttl=min(3600,(int)$grant['expires_at']-$this->now());
        $q=$this->pdo->prepare('INSERT INTO manager_preparation_oauth_tokens(token_hash,grant_id,token_kind,expires_at,consumed_at) VALUES(?,?,?,?,NULL)');
        $q->execute([hash('sha256',$access),$grant['grant_id'],'access',$this->now()+$ttl]);
        $q->execute([hash('sha256',$refresh),$grant['grant_id'],'refresh',(int)$grant['expires_at']]);
        return ['access_token'=>$access,'token_type'=>'Bearer','expires_in'=>$ttl,'refresh_token'=>$refresh,'scope'=>$grant['scopes']];
    }

    private function client(string $id): bool
    {
        $q=$this->pdo->prepare('SELECT redirect_uri FROM manager_preparation_oauth_clients WHERE client_id=? LIMIT 1');
        $q->execute([$id]);
        return $q->fetchColumn()===self::REDIRECT_URI;
    }

    private function checkCsrf(array $session, string $provided): void
    {
        $expected=$session['csrf']??null;
        if (!is_string($expected) || $expected==='' || !hash_equals($expected,$provided)) $this->fail('csrf');
    }

    private function scope($value): string
    {
        if (!is_string($value) || strlen($value)>100) $this->fail('invalid_scope');
        $scopes=explode(' ',$value);
        if (count($scopes)!==2 || count(array_unique($scopes))!==2 || array_diff(self::SCOPES,$scopes) || array_diff($scopes,self::SCOPES)) $this->fail('invalid_scope');
        return implode(' ',self::SCOPES);
    }

    private function text(array $input, string $key, int $limit): string
    {
        if (!isset($input[$key]) || !is_string($input[$key]) || strlen($input[$key])>$limit) $this->fail('invalid_request');
        return $input[$key];
    }

    private function redirect(array $request, array $result): string
    {
        return self::REDIRECT_URI.'?'.http_build_query($result+['state'=>$request['state'],'iss'=>self::ISSUER],'','&',PHP_QUERY_RFC3986);
    }

    private function transactionStart(): void
    {
        if ($this->pdo->inTransaction()) $this->fail('temporarily_unavailable');
        $this->pdo->beginTransaction();
    }

    private function lockSuffix(): string { return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''; }
    private function now(): int { return (int)($this->clock)(); }
    private function opaque(): string { return $this->base64url(random_bytes(32)); }
    private function base64url(string $value): string { return rtrim(strtr(base64_encode($value),'+/','-_'),'='); }
    private function fail(string $code): never { throw new InvalidArgumentException($code); }
}
