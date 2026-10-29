<?php

declare(strict_types=1);

require_once __DIR__.'/ConversationDb.php';
require_once __DIR__.'/ProjectConfig.php';

/** Fixed diagnostic categories only; never include request or credential values. */
final class ManagerPreparationOAuthRequestException extends InvalidArgumentException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('invalid_request');
    }
}

/** A technical forecast authorization failure is not a request for new consent. */
final class ManagerPreparationOAuthLookupUnavailable extends RuntimeException
{
    public function __construct() { parent::__construct('forecast_authorization_unavailable'); }
}

/** Narrow public-client OAuth boundary. This service never reads a transcript. */
final class ManagerPreparationOAuth
{
    public const ORIGIN = 'https://app.anytoour.ru';
    // RFC 8414 inserts the well-known segment before this issuer path. The
    // explicit PHP discovery route works without directory-index permissions.
    public const ISSUER = self::ORIGIN.'/manager/ai-oauth.php';
    public const RESOURCE = self::ORIGIN.'/manager/ai-mcp.php';
    public const ENDPOINT = self::ORIGIN.'/manager/ai-oauth.php';
    public const PROFILE_LEGACY = 'legacy';
    public const PROFILE_DEVELOPMENT = 'development';
    public const PROFILE_REPLIES = 'replies';
    public const PROFILE_HOTELS = 'hotels';
    public const DEVELOPMENT_ISSUER = self::ORIGIN.'/manager/development-oauth.php';
    public const DEVELOPMENT_RESOURCE = self::ORIGIN.'/manager/development-mcp.php';
    public const DEVELOPMENT_ENDPOINT = self::ORIGIN.'/manager/development-oauth.php';
    public const SCOPES = ['queue:read', 'prepared:write'];
    public const HOTEL_SCOPE = 'hotel_work:write';
    public const DEVELOPMENT_SCOPE = 'development_work:write';
    public const ARCHIVE_SCOPE = 'archive:read';
    public const FORECAST_SCOPE = 'forecasts:read';
    public const SUPPORTED_SCOPES = ['queue:read', 'prepared:write', self::HOTEL_SCOPE, self::ARCHIVE_SCOPE, self::DEVELOPMENT_SCOPE, self::FORECAST_SCOPE];
    // RFC 9207 issuer identification permits this documented stable callback.
    public const REDIRECT_URI = 'https://chatgpt.com/connector_platform_oauth_redirect';
    private PDO $pdo;
    private $clock;
    private ?ModeledConversionStatusBudget $statusReadBudget = null;

    public function __construct(?PDO $pdo = null, ?callable $clock = null, private string $profile = self::PROFILE_LEGACY)
    {
        self::requireProfile($profile);
        $this->pdo = $pdo ?? ConversationDb::connection();
        $this->clock = $clock ?? static fn(): int => time();
    }

    /** The private status path shares its execution/read budget with authorization. */
    public function withStatusReadBudget(?ModeledConversionStatusBudget $budget): self
    {
        $this->statusReadBudget=$budget;
        $budget?->check();
        return $this;
    }

    /** Fixed server-owned resource profiles; never selected from OAuth request data. */
    public static function profileDefinition(string $profile): array
    {
        $definitions = [
            self::PROFILE_LEGACY => ['path'=>'ai', 'prefix'=>'', 'scopes'=>self::SUPPORTED_SCOPES,
                'defaults'=>self::SCOPES, 'session'=>'manager_preparation_oauth_pending', 'label'=>'AnyTour'],
            self::PROFILE_DEVELOPMENT => ['path'=>'development', 'prefix'=>'dev_', 'scopes'=>[self::DEVELOPMENT_SCOPE],
                'defaults'=>[self::DEVELOPMENT_SCOPE], 'session'=>'manager_development_oauth_pending', 'label'=>'Разработка AnyTour'],
            self::PROFILE_REPLIES => ['path'=>'replies', 'prefix'=>'rep_', 'scopes'=>self::SCOPES,
                'defaults'=>self::SCOPES, 'session'=>'manager_replies_oauth_pending', 'label'=>'Ответы AnyTour'],
            self::PROFILE_HOTELS => ['path'=>'hotels', 'prefix'=>'hot_', 'scopes'=>[self::HOTEL_SCOPE],
                'defaults'=>[self::HOTEL_SCOPE], 'session'=>'manager_hotels_oauth_pending', 'label'=>'Отели AnyTour'],
        ];
        if (!isset($definitions[$profile])) throw new InvalidArgumentException('invalid_oauth_profile');
        $out=$definitions[$profile];
        $out['resource']=self::ORIGIN.'/manager/'.$out['path'].'-mcp.php';
        $out['issuer']=$out['endpoint']=self::ORIGIN.'/manager/'.$out['path'].'-oauth.php';
        return $out;
    }

    public static function authorizationMetadata(string $profile = self::PROFILE_LEGACY): array
    {
        $p=self::profileDefinition($profile);
        return ['issuer'=>$p['issuer'],'authorization_response_iss_parameter_supported'=>true,
            'authorization_endpoint'=>$p['endpoint'].'?action=authorize','token_endpoint'=>$p['endpoint'].'?action=token',
            'registration_endpoint'=>$p['endpoint'].'?action=register','revocation_endpoint'=>$p['endpoint'].'?action=revoke',
            'token_endpoint_auth_methods_supported'=>['none'],'revocation_endpoint_auth_methods_supported'=>['none'],
            'response_types_supported'=>['code'],'grant_types_supported'=>['authorization_code','refresh_token'],
            'code_challenge_methods_supported'=>['S256'],'scopes_supported'=>$p['scopes']];
    }

    public static function resourceMetadata(string $profile = self::PROFILE_LEGACY): array
    {
        $p=self::profileDefinition($profile);
        return ['resource'=>$p['resource'],'authorization_servers'=>[$p['issuer']],
            'scopes_supported'=>$p['scopes'],'bearer_methods_supported'=>['header']];
    }

    /** One fresh canonical grant lookup; caller never supplies its own scopes. */
    public static function bearerContext(string $authorization,?ModeledConversionStatusBudget $budget=null): ?array
    {
        try { return (new self())->withStatusReadBudget($budget)->contextForBearer($authorization); }
        catch (ModeledConversionStatusLimit $error) { throw $error; }
        catch (Throwable $error) {
            if ($budget!==null) throw new ManagerPreparationOAuthLookupUnavailable();
            return null;
        }
    }

    public function contextForBearer(string $authorization): ?array
    {
        $grant=$this->grantForBearer($authorization);
        return $grant ? ['admin_id'=>(int)$grant['admin_id'], 'connection_id'=>(string)$grant['grant_id'],
            'scopes'=>explode(' ', $grant['scopes'])] : null;
    }

    public static function hasScopes(array $granted, array $required): bool
    {
        return !array_diff($required,$granted);
    }

    public static function isConnectionAuthorized(string $familyId, int $adminId, array $required): bool
    {
        try { return (new self())->connectionAuthorized($familyId,$adminId,$required); }
        catch (Throwable $error) { return false; }
    }

    /** A failed active check is not proof of retirement: storage errors, absent
     * grants and permission changes must never authorize queue takeover. */
    public static function isConnectionRetired(string $familyId,int $adminId): bool
    {
        try { return (new self())->connectionRetired($familyId,$adminId); }
        catch (Throwable $error) { return false; }
    }

    public function connectionRetired(string $familyId,int $adminId): bool
    {
        $grant=$this->knownHotelConnectionGrant($familyId,$adminId);
        if ($grant===null) return false;
        $knownTime=static fn($value):bool=>(is_int($value)||is_string($value))
            &&preg_match('/\A[1-9][0-9]{0,18}\z/D',(string)$value)===1;
        return ($knownTime($grant['expires_at'])&&(int)$grant['expires_at']<=$this->now())
            ||($grant['revoked_at']!==null&&$knownTime($grant['revoked_at'])&&(int)$grant['revoked_at']<=$this->now());
    }

    /** Positive evidence of the old grant's hotel identity, not current access
     * authorization. READY transfer and retained-claim authority-only recovery
     * may use this predicate; neither authorizes an external worker dispatch.
     * current credentials still require connectionAuthorized before each call. */
    public function hotelConnectionKnown(string $familyId,int $adminId): bool
    {
        try { return $this->knownHotelConnectionGrant($familyId,$adminId)!==null; }
        catch (Throwable $error) { return false; }
    }

    private function knownHotelConnectionGrant(string $familyId,int $adminId): ?array
    {
        if (!in_array($this->profile,[self::PROFILE_LEGACY,self::PROFILE_HOTELS],true)) return null;
        if (!preg_match('/\A[a-f0-9]{32}\z/D',$familyId)||$adminId<=0) return null;
        $q=$this->pdo->prepare('SELECT g.scopes,g.expires_at,g.revoked_at,g.resource,g.client_id,g.project_key FROM manager_preparation_oauth_grants g
            JOIN manager_preparation_oauth_clients c ON c.client_id=g.client_id
            WHERE g.grant_id=? AND g.admin_id=? AND g.project_key=? AND c.redirect_uri=? LIMIT 1');
        $q->execute([$familyId,$adminId,ProjectConfig::projectId(),self::REDIRECT_URI]);
        $grant=$q->fetch(PDO::FETCH_ASSOC);
        if (!$grant) return null;
        $profile=match ($grant['resource']) {
            self::RESOURCE=>self::PROFILE_LEGACY,
            self::ORIGIN.'/manager/hotels-mcp.php'=>self::PROFILE_HOTELS,
            default=>null,
        };
        if ($profile===null) return null;
        $canonical=new self($this->pdo,$this->clock,$profile);
        if (!$canonical->grantMatchesProfile($grant)||!in_array(self::HOTEL_SCOPE,explode(' ',$grant['scopes']),true)) return null;
        return $grant;
    }

    /** Cron only: accept old or isolated resource for ONE domain, never for HTTP token authentication. */
    public function eventConnectionAuthorizedAcrossProfiles(string $domain,string $familyId,int $adminId): bool
    {
        if ($this->pdo->inTransaction()) throw new RuntimeException('event_authorization_transaction');
        [$profiles,$required]=match ($domain) {
            'replies'=>[[self::PROFILE_LEGACY,self::PROFILE_REPLIES],self::SCOPES],
            'hotels'=>[[self::PROFILE_LEGACY,self::PROFILE_HOTELS],[self::HOTEL_SCOPE]],
            default=>throw new InvalidArgumentException('invalid_event_domain'),
        };
        foreach ($profiles as $profile) {
            if ((new self($this->pdo,$this->clock,$profile))->connectionAuthorized($familyId,$adminId,$required)) return true;
        }
        return false;
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
        return $this->connectionGrant($familyId,$adminId)!==null;
    }

    public function connectionAuthorized(string $familyId, int $adminId, array $required): bool
    {
        $grant=$this->connectionGrant($familyId,$adminId);
        return $grant!==null && self::hasScopes(explode(' ',$grant['scopes']),$required);
    }

    /** Private self metadata for one current authenticated connection, never a queue owner lookup. */
    public function currentConnectionMetadata(string $familyId,int $adminId,array $required): ?array
    {
        if ($this->pdo->inTransaction()) return null;
        $grant=$this->connectionGrant($familyId,$adminId);
        if ($grant===null) return null;
        $scopes=explode(' ',$grant['scopes']);
        if (!self::hasScopes($scopes,$required)) return null;
        return ['connection_id'=>$grant['grant_id'],'client_id'=>$grant['client_id'],'active_project_admin'=>true,
            'capabilities'=>['reply_preparation'=>self::hasScopes($scopes,self::SCOPES),
                'hotel_work'=>in_array(self::HOTEL_SCOPE,$scopes,true),
                'archive_research'=>in_array(self::ARCHIVE_SCOPE,$scopes,true),
                'development_work'=>in_array(self::DEVELOPMENT_SCOPE,$scopes,true)]];
    }

    /** Private domain-separated cursor key for one fresh archive consent.
     * This never returns a credential or its stored hash. A refreshed/expired
     * access-token selection may invalidate prior cursors; no secret is added. */
    public function archiveCursorKey(string $familyId,int $adminId): string
    {
        if (ProjectConfig::projectId()!=='anytour' || $this->pdo->inTransaction()) throw new DomainException();
        $grant=$this->connectionGrant($familyId,$adminId);
        if ($grant===null || !in_array(self::ARCHIVE_SCOPE,explode(' ',$grant['scopes']),true)) throw new DomainException();
        $now=$this->now();
        $q=$this->pdo->prepare("SELECT t.token_hash FROM manager_preparation_oauth_tokens t
            JOIN manager_preparation_oauth_grants g ON g.grant_id=t.grant_id
            JOIN manager_preparation_oauth_clients c ON c.client_id=g.client_id
            WHERE t.grant_id=? AND g.admin_id=? AND g.project_key=? AND g.resource=? AND g.scopes=?
            AND g.revoked_at IS NULL AND g.expires_at>? AND c.redirect_uri=?
            AND t.token_kind='access' AND t.consumed_at IS NULL AND t.expires_at>?
            ORDER BY t.expires_at DESC,t.token_hash DESC LIMIT 1");
        $q->execute([$familyId,$adminId,'anytour',self::RESOURCE,$grant['scopes'],$now,self::REDIRECT_URI,$now]);
        $hash=$q->fetchColumn();
        if (!is_string($hash) || preg_match('/\A[a-f0-9]{64}\z/D',$hash)!==1) throw new DomainException();
        $key=hex2bin($hash);
        if ($key===false) throw new DomainException();
        return hash_hmac('sha256',"anytour.archive-research.cursor.v1\0".$adminId."\0".$familyId,$key,true);
    }

    private function connectionGrant(string $familyId, int $adminId): ?array
    {
        if (!preg_match('/\A[a-f0-9]{32}\z/D',$familyId) || $adminId<=0) return null;
        $grant=$this->authorizationRow('SELECT g.* FROM manager_preparation_oauth_grants g JOIN manager_preparation_oauth_clients c ON c.client_id=g.client_id WHERE g.grant_id=? AND g.admin_id=? LIMIT 1',[$familyId,$adminId]);
        return $grant && $this->validGrant($grant) ? $grant : null;
    }

    private function grantForBearer(string $authorization): ?array
    {
        if (!preg_match('/\ABearer ([A-Za-z0-9_-]{43})\z/Di', $authorization, $match)) return null;
        $grant=$this->authorizationRow("SELECT g.* FROM manager_preparation_oauth_tokens t
            JOIN manager_preparation_oauth_grants g ON g.grant_id=t.grant_id
            JOIN manager_preparation_oauth_clients c ON c.client_id=g.client_id
            WHERE t.token_hash=? AND t.token_kind='access' AND t.expires_at>?
            AND t.consumed_at IS NULL AND g.revoked_at IS NULL AND g.expires_at>? LIMIT 1",[hash('sha256',$match[1]),$this->now(),$this->now()]);
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
        $scopes=isset($input['scope']) ? $this->scope($input['scope']) : implode(' ',self::profileDefinition($this->profile)['defaults']);
        // No client-supplied URI, logo, JWKS or metadata is ever fetched.
        $bucket=hash('sha256', $registrationKey);
        $q=$this->pdo->prepare('SELECT COUNT(*) FROM manager_preparation_oauth_clients WHERE registration_bucket=? AND created_at>?');
        $q->execute([$bucket,$this->now()-3600]);
        if ((int)$q->fetchColumn()>=30
            || (int)$this->pdo->query('SELECT COUNT(*) FROM manager_preparation_oauth_clients')->fetchColumn()>=5000) $this->fail('temporarily_unavailable');
        $prefix=self::profileDefinition($this->profile)['prefix'];
        $id=$prefix.bin2hex(random_bytes(intdiv(48-strlen($prefix),2)));
        $this->pdo->prepare('INSERT INTO manager_preparation_oauth_clients(client_id,redirect_uri,registration_bucket,created_at) VALUES(?,?,?,?)')
            ->execute([$id,self::REDIRECT_URI,$bucket,$this->now()]);
        return ['client_id'=>$id,'client_id_issued_at'=>$this->now(), 'redirect_uris'=>[self::REDIRECT_URI],
            'token_endpoint_auth_method'=>'none','grant_types'=>$grants,'response_types'=>['code'],
            'scope'=>$scopes];
    }

    public function validateAuthorization(array $request): array
    {
        $client=$this->text($request,'client_id',48);
        if (!$this->clientNamespace($client) || !$this->client($client)) $this->fail('invalid_client');
        $redirect=$this->text($request,'redirect_uri',255);
        if ($redirect!==self::REDIRECT_URI) $this->fail('invalid_request');
        if ($this->text($request,'response_type',20)!=='code'
            || $this->text($request,'code_challenge_method',20)!=='S256') $this->fail('invalid_request');
        $challenge=$this->text($request,'code_challenge',43);
        if (!preg_match('/\A[A-Za-z0-9_-]{43}\z/D',$challenge)) $this->fail('invalid_request');
        $resource=$this->text($request,'resource',255);
        if ($resource!==$this->resource()) $this->fail('invalid_target');
        $state=$this->text($request,'state',1024);
        if ($state==='' || preg_match('/[\x00-\x1f\x7f]/',$state)) $this->fail('invalid_request');
        return ['client_id'=>$client,'redirect_uri'=>$redirect,'response_type'=>'code','code_challenge'=>$challenge,
            'code_challenge_method'=>'S256','resource'=>$resource,'state'=>$state,'scope'=>$this->scope($request['scope']??null)];
    }

    public function beginAuthorization(array &$session, array $request): string
    {
        $request=$this->validateAuthorization($request);
        $pending=$session[$this->pendingSessionKey()]??[];
        if (!is_array($pending)) $pending=[];
        foreach ($pending as $id=>$value) if (!is_array($value) || (int)($value['expires_at']??0)<=$this->now()) unset($pending[$id]);
        if (count($pending)>=5) array_shift($pending);
        $id=bin2hex(random_bytes(24));
        $pending[$id]=['request'=>$request,'expires_at'=>$this->now()+600,'admin_id'=>0];
        $session[$this->pendingSessionKey()]=$pending;
        return $id;
    }

    /** Bind a resumed request to the account viewing the explicit consent. */
    public function pendingAuthorization(array &$session, string $id, int $adminId = 0): array
    {
        if (!preg_match('/\A[a-f0-9]{48}\z/D',$id)) throw new ManagerPreparationOAuthRequestException('consent_request_invalid');
        $pending=$session[$this->pendingSessionKey()][$id]??null;
        if (!is_array($pending)) throw new ManagerPreparationOAuthRequestException('consent_request_missing');
        if ((int)($pending['expires_at']??0)<=$this->now()) throw new ManagerPreparationOAuthRequestException('consent_request_expired');
        if ($adminId>0) {
            if (!$this->activeAdmin($adminId)) $this->fail('access_denied');
            if ((int)$pending['admin_id']!==0 && (int)$pending['admin_id']!==$adminId) $this->fail('access_denied');
            $session[$this->pendingSessionKey()][$id]['admin_id']=$adminId;
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
            unset($session[$this->pendingSessionKey()][$pendingId]);
            return $this->redirect($request,['error'=>'access_denied']);
        }
        $this->transactionStart();
        try {
            if (!$this->activeAdmin($adminId)) $this->fail('access_denied');
            $grant=bin2hex(random_bytes(16));
            $code=$this->opaque();
            $this->pdo->prepare('INSERT INTO manager_preparation_oauth_grants(grant_id,client_id,admin_id,project_key,resource,scopes,created_at,expires_at,revoked_at) VALUES(?,?,?,?,?,?,?,?,NULL)')
                ->execute([$grant,$request['client_id'],$adminId,ProjectConfig::projectId(),$this->resource(),$request['scope'],$this->now(),$this->now()+30*86400]);
            $this->pdo->prepare('INSERT INTO manager_preparation_oauth_codes(code_hash,grant_id,redirect_uri,code_challenge,expires_at,consumed_at) VALUES(?,?,?,?,?,NULL)')
                ->execute([hash('sha256',$code),$grant,$request['redirect_uri'],$request['code_challenge'],$this->now()+300]);
            $this->pdo->commit();
            unset($session[$this->pendingSessionKey()][$pendingId]);
            return $this->redirect($request,['code'=>$code]);
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function exchange(array $input): array
    {
        $client=$this->text($input,'client_id',48);
        if (!$this->clientNamespace($client) || !$this->client($client)) $this->fail('invalid_client');
        if (isset($input['client_secret']) || isset($input['client_assertion'])) $this->fail('invalid_client');
        if ($this->text($input,'resource',255)!==$this->resource()) $this->fail('invalid_target');
        $type=$this->text($input,'grant_type',40);
        if (!in_array($type,['authorization_code','refresh_token'],true)) $this->fail('unsupported_grant_type');
        $secret=$this->text($input,$type==='authorization_code'?'code':'refresh_token',43);
        if (!preg_match('/\A[A-Za-z0-9_-]{43}\z/D',$secret)) $this->fail('invalid_grant');
        $verifier='';
        if ($type==='authorization_code') {
            if ($this->text($input,'redirect_uri',255)!==self::REDIRECT_URI) $this->fail('invalid_grant');
            $verifier=$this->text($input,'code_verifier',128);
            if (!preg_match('/\A[A-Za-z0-9._~-]{43,128}\z/D',$verifier)) $this->fail('invalid_grant');
        }
        $requestedScope=isset($input['scope']) ? $this->scope($input['scope']) : null;
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
            if (!$grant || $grant['client_id']!==$client || !$this->grantMatchesProfile($grant)) $this->fail('invalid_grant');
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
            // Refresh/code exchange may not add capabilities absent at consent.
            // Keep a grant's exact scope profile rather than silently narrowing
            // the family while its existing access tokens retain older rights.
            if ($requestedScope!==null && $requestedScope!==$grant['scopes']) $this->fail('invalid_scope');
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
        if (!preg_match('/\A[A-Za-z0-9_-]{43}\z/D',$token) || !$this->clientNamespace($client)) return;
        $q=$this->pdo->prepare('SELECT g.grant_id FROM manager_preparation_oauth_tokens t JOIN manager_preparation_oauth_grants g ON g.grant_id=t.grant_id WHERE t.token_hash=? AND g.client_id=? AND g.resource=? AND g.project_key=? AND '.$this->clientProfileSql('g.client_id').' LIMIT 1');
        $q->execute([hash('sha256',$token),$client,$this->resource(),ProjectConfig::projectId()]);
        $grant=$q->fetchColumn();
        if ($grant) $this->pdo->prepare('UPDATE manager_preparation_oauth_grants SET revoked_at=? WHERE grant_id=? AND client_id=? AND project_key=? AND resource=? AND '.$this->clientProfileSql('client_id').' AND revoked_at IS NULL')
            ->execute([$this->now(),$grant,$client,ProjectConfig::projectId(),$this->resource()]);
    }

    public function connections(int $adminId): array
    {
        if (!$this->activeAdmin($adminId)) $this->fail('access_denied');
        $q=$this->pdo->prepare('SELECT grant_id,created_at,expires_at,scopes FROM manager_preparation_oauth_grants WHERE admin_id=? AND project_key=? AND resource=? AND '.$this->clientProfileSql('client_id').' AND revoked_at IS NULL AND expires_at>? ORDER BY created_at DESC LIMIT 50');
        $q->execute([$adminId,ProjectConfig::projectId(),$this->resource(),$this->now()]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function disconnect(array $session, int $adminId, string $grant, string $csrf): void
    {
        $this->checkCsrf($session,$csrf);
        if (!$this->activeAdmin($adminId)) $this->fail('access_denied');
        if (!preg_match('/\A[a-f0-9]{32}\z/D',$grant)) $this->fail('invalid_request');
        $this->pdo->prepare('UPDATE manager_preparation_oauth_grants SET revoked_at=? WHERE grant_id=? AND admin_id=? AND project_key=? AND resource=? AND '.$this->clientProfileSql('client_id').' AND revoked_at IS NULL')
            ->execute([$this->now(),$grant,$adminId,ProjectConfig::projectId(),$this->resource()]);
    }

    public function activeAdmin(int $id): bool
    {
        if ($id<=0) return false;
        // The ordinary login remains the identity owner. Never bootstrap roles or access here.
        $project=ProjectConfig::projectId();
        $name=(string)ProjectConfig::get('brand.name',$project);
        return $this->authorizationRow("SELECT 1 FROM managers m JOIN manager_projects mp ON mp.manager_id=m.id
            JOIN projects p ON p.id=mp.project_id WHERE m.id=? AND m.is_active=1 AND m.role='admin'
            AND p.project_key=? AND p.is_active=1 AND p.display_name=? LIMIT 1",[$id,$project,$name!==''?$name:$project])!==false;
    }

    /** Selected grant/client columns are schema-bounded: one encoded row fits 4 KiB.
     * This path never copies token hashes or client registration metadata. */
    private function authorizationRow(string $sql,array $bindings):array|false
    {
        if($this->statusReadBudget!==null){
            if($this->statusReadBudget->remainingBytes()<4096)throw new ModeledConversionStatusLimit('byte_limit');
            $sql=str_replace('SELECT g.*','SELECT g.grant_id,g.admin_id,g.client_id,g.project_key,g.resource,g.scopes,g.created_at,g.expires_at,g.revoked_at',$sql);
            $query=$this->statusReadBudget->prepare($this->pdo,$sql);
        }else $query=$this->pdo->prepare($sql);
        $query->execute($bindings);$row=$query->fetch(PDO::FETCH_ASSOC);
        if($this->statusReadBudget!==null){
            $this->statusReadBudget->check();
            $this->statusReadBudget->consumeBytes(strlen(json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)));
        }
        return $row;
    }

    private function validGrant(array $grant): bool
    {
        return $this->grantMatchesProfile($grant) && $this->client($grant['client_id'])
            && $grant['revoked_at']===null && (int)$grant['expires_at']>$this->now()
            && $grant['project_key']===ProjectConfig::projectId() && $this->activeAdmin((int)$grant['admin_id']);
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
        if (!$this->clientNamespace($id)) return false;
        $row=$this->authorizationRow('SELECT redirect_uri FROM manager_preparation_oauth_clients WHERE client_id=? LIMIT 1',[$id]);
        return $row!==false&&$row['redirect_uri']===self::REDIRECT_URI;
    }

    private static function requireProfile(string $profile): void
    {
        self::profileDefinition($profile);
    }
    private function resource(): string { return self::profileDefinition($this->profile)['resource']; }
    private function issuer(): string { return self::profileDefinition($this->profile)['issuer']; }
    private function pendingSessionKey(): string { return self::profileDefinition($this->profile)['session']; }
    private function clientNamespace(string $client): bool
    {
        $prefix=self::profileDefinition($this->profile)['prefix'];
        return preg_match('/\A'.preg_quote($prefix,'/').'[a-f0-9]{'.(48-strlen($prefix)).'}\z/D',$client)===1;
    }
    /** Fixed column allowlist and hex prefix comparison: no wildcard client matching. */
    private function clientProfileSql(string $column): string
    {
        if (!in_array($column,['client_id','g.client_id'],true)) throw new LogicException('invalid_oauth_client_column');
        $prefix=self::profileDefinition($this->profile)['prefix'];
        $remaining=$prefix!==''?'SUBSTR('.$column.','.(strlen($prefix)+1).')':$column;
        foreach (str_split('0123456789abcdef') as $hex) $remaining="REPLACE(".$remaining.",'".$hex."','')";
        return 'LENGTH('.$column.')=48 AND '.($prefix!==''?"HEX(SUBSTR(".$column.",1,".strlen($prefix)."))='".strtoupper(bin2hex($prefix))."' AND ":'').$remaining."=''";
    }
    private function grantMatchesProfile(array $grant): bool
    {
        if (($grant['resource']??null)!==$this->resource()||($grant['project_key']??null)!==ProjectConfig::projectId()
            ||!is_string($grant['client_id']??null)||!$this->clientNamespace($grant['client_id'])) return false;
        try { $scope=$this->scope($grant['scopes']??null); }
        catch (InvalidArgumentException $invalid) { return false; }
        return $scope===($grant['scopes']??null);
    }

    /** Background-only canonical audience selection. HTTP callers choose one fixed profile. */
    public function developmentConnectionAuthorizedAcrossProfiles(string $familyId,int $adminId): bool
    {
        try {
            if ($this->pdo->inTransaction()||$adminId<1||preg_match('/\A[a-f0-9]{32}\z/D',$familyId)!==1) throw new RuntimeException();
            $read=function()use($familyId,$adminId):array {
                $q=$this->pdo->prepare('SELECT g.*,c.redirect_uri AS profile_redirect_uri FROM manager_preparation_oauth_grants g LEFT JOIN manager_preparation_oauth_clients c ON c.client_id=g.client_id WHERE g.grant_id=? AND g.admin_id=? LIMIT 1');
                if (!$q||!$q->execute([$familyId,$adminId])) throw new RuntimeException();
                $grant=$q->fetch(PDO::FETCH_ASSOC);
                if (!is_array($grant)||($grant['grant_id']??null)!==$familyId||($grant['profile_redirect_uri']??null)!==self::REDIRECT_URI
                    ||($grant['project_key']??null)!==ProjectConfig::projectId()) throw new RuntimeException();
                $profile=match ($grant['resource']??null) {
                    self::RESOURCE=>self::PROFILE_LEGACY,
                    self::DEVELOPMENT_RESOURCE=>self::PROFILE_DEVELOPMENT,
                    default=>throw new RuntimeException(),
                };
                $canonical=new self($this->pdo,$this->clock,$profile);
                if (!$canonical->grantMatchesProfile($grant)) throw new RuntimeException();
                $knownTime=static fn($value):bool=>(is_int($value)&&$value>=0)||(is_string($value)&&preg_match('/\A(?:0|[1-9][0-9]{0,17})\z/D',$value)===1);
                if (!$knownTime($grant['expires_at']??null)||!array_key_exists('revoked_at',$grant)
                    ||($grant['revoked_at']!==null&&!$knownTime($grant['revoked_at']))) throw new RuntimeException();
                return [$grant,$profile];
            };
            [$grant,$profile]=$read();
            $canonical=new self($this->pdo,$this->clock,$profile);
            $authorized=$canonical->connectionAuthorized($familyId,$adminId,[self::DEVELOPMENT_SCOPE]);
            if (!$authorized) {
                // A failed fresh authorization must not disguise missing storage or a changed audience.
                [$current,$currentProfile]=$read();
                if ($currentProfile!==$profile||$current['client_id']!==$grant['client_id']||$current['project_key']!==$grant['project_key']) throw new RuntimeException();
            }
            return $authorized;
        } catch (Throwable $unavailable) {
            throw new RuntimeException('development_oauth_authority_unavailable',0,$unavailable);
        }
    }

    private function checkCsrf(array $session, string $provided): void
    {
        $expected=$session['csrf']??null;
        if (!is_string($expected) || $expected==='' || !hash_equals($expected,$provided)) $this->fail('csrf');
    }

    private function scope($value): string
    {
        if (!is_string($value) || strlen($value)>100) $this->fail('invalid_scope');
        if ($this->profile!==self::PROFILE_LEGACY) {
            $required=self::profileDefinition($this->profile)['scopes'];
            $given=explode(' ',$value);
            if (count($given)!==count($required)||array_diff($given,$required)||array_diff($required,$given)) $this->fail('invalid_scope');
            return implode(' ',$required);
        }
        $scopes=explode(' ',$value);
        if (count(array_unique($scopes))!==count($scopes) || array_diff($scopes,self::SUPPORTED_SCOPES)) $this->fail('invalid_scope');
        $legacy=self::hasScopes($scopes,self::SCOPES);
        $hotel=in_array(self::HOTEL_SCOPE,$scopes,true);
        $development=in_array(self::DEVELOPMENT_SCOPE,$scopes,true);
        $archive=in_array(self::ARCHIVE_SCOPE,$scopes,true);
        $forecast=in_array(self::FORECAST_SCOPE,$scopes,true);
        // Reply read/write remain one capability. Hotel and development metadata
        // each require separate explicit consent; refresh never adds a scope.
        // Archive reads and aggregate forecast status each require fresh consent.
        if ((!$legacy && array_intersect($scopes,self::SCOPES)) || (!$legacy && !$hotel && !$development && !$archive && !$forecast)) $this->fail('invalid_scope');
        return implode(' ',array_values(array_intersect(self::SUPPORTED_SCOPES,$scopes)));
    }

    private function text(array $input, string $key, int $limit): string
    {
        if (!isset($input[$key]) || !is_string($input[$key]) || strlen($input[$key])>$limit) $this->fail('invalid_request');
        return $input[$key];
    }

    private function redirect(array $request, array $result): string
    {
        return self::REDIRECT_URI.'?'.http_build_query($result+['state'=>$request['state'],'iss'=>$this->issuer()],'','&',PHP_QUERY_RFC3986);
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
