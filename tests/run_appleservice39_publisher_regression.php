<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/services/AppleService39PilotPublisher.php';

function as39Expect(bool $ok, string $label): void
{
    if (!$ok) throw new RuntimeException('FAIL: '.$label);
    echo 'PASS: '.$label.PHP_EOL;
}
function as39Refuses(callable $operation, string $reason): void
{
    try { $operation(); } catch (RuntimeException $error) { as39Expect($error->getMessage() === $reason, $reason); return; }
    throw new RuntimeException('FAIL: expected '.$reason);
}
function as39ProviderAssert(bool $ok, string $label): void
{
    if (!$ok) throw new RuntimeException('FAIL: '.$label);
}
function as39Registry(string $token = 'synthetic-apple-token-12345'): array
{
    return ['appleservice39'=>['project'=>['id'=>'appleservice39','business'=>['kind'=>'device_repair'],
        'brand'=>['name'=>'AppleService39'],'routing'=>['source_key'=>'max:appleservice39-main']],
        'max'=>['bot_key'=>'id390521129062_bot','token'=>$token,'webhook_secret'=>str_repeat('a',32)]]];
}
function as39Schema(PDO $pdo): void
{
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $id = $mysql ? 'BIGINT UNSIGNED' : 'INTEGER';
    $pdo->exec("CREATE TABLE projects (id $id PRIMARY KEY,project_key VARCHAR(64),is_active INTEGER)");
    $pdo->exec("CREATE TABLE managers (id $id PRIMARY KEY,role VARCHAR(16),is_active INTEGER)");
    $pdo->exec("CREATE TABLE manager_projects (manager_id $id,project_id $id)");
    $pdo->exec("CREATE TABLE conversation_sources (id $id PRIMARY KEY,project_id $id,source_key VARCHAR(96),channel VARCHAR(16),is_active INTEGER,handling_mode VARCHAR(16))");
    $sql = (string)file_get_contents(dirname(__DIR__).'/migrations/095_appleservice39_pilot_publications.sql');
    if (!$mysql) {
        $sql = preg_replace('/\bid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT\b/', 'id INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
        $sql = preg_replace('/^\s*PRIMARY KEY \(id\),?\s*$/m', '', $sql);
        $sql = preg_replace('/UNIQUE KEY [a-zA-Z0-9_]+/', 'UNIQUE', $sql);
        $sql = preg_replace('/ CHARACTER SET [a-zA-Z0-9_]+ COLLATE [a-zA-Z0-9_]+/', '', $sql);
        $sql = str_replace(' UNSIGNED', '', $sql);
        $sql = str_replace(') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;', ');', $sql);
    }
    foreach (preg_split('/;\s*(?:\n|$)/', $sql) as $statement) if (trim($statement) !== '') $pdo->exec($statement);
    $pdo->exec("INSERT INTO projects VALUES (1,'appleservice39',1),(2,'anytour',1)");
    $pdo->exec("INSERT INTO managers VALUES (2,'admin',1),(3,'manager',1)");
    $pdo->exec('INSERT INTO manager_projects VALUES (2,1),(3,2)');
    $pdo->exec("INSERT INTO conversation_sources VALUES (10,1,'max:appleservice39-main','max',1,'manager'),(20,2,'max:anytour','max',1,'manager')");
}
function as39Sqlite(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA foreign_keys=ON'); as39Schema($pdo); return $pdo;
}

final class ApplePilotTestProvider
{
    public array $requests = [];
    public array $messages = [];
    public string $mode = '';
    public array $permissions = ['read_all_messages','write','edit','pin_message'];
    public ?string $pinned = null;
    public string $uploadHost = 'iu.oneme.ru';
    public $membershipHook = null;
    private int $uploadId = 0;
    private bool $failedRead = false;

    public function __invoke(array $request): array
    {
        $this->requests[] = $request;
        $path = (string)parse_url($request['url'], PHP_URL_PATH); parse_str((string)parse_url($request['url'], PHP_URL_QUERY), $query);
        $method = $request['method'];
        if (str_starts_with($request['url'], 'https://iu.oneme.ru/')) {
            as39ProviderAssert($request['token'] === '' && $request['file'] !== null, 'upload never forwards bot credential');
            $id = $query['id']; return $this->ok(['photos'=>[$id=>['token'=>'photo_'.$id]]]);
        }
        as39ProviderAssert($request['token'] === 'synthetic-apple-token-12345', 'API uses the exact project token');
        if ($method === 'GET' && $path === '/me') return $this->ok(['user_id'=>123,'is_bot'=>true,'username'=>$this->mode === 'wrong_bot' ? 'other_bot' : AppleService39PilotManifest::BOT]);
        if ($method === 'GET' && $path === '/chats/-12345') return $this->ok(['chat_id'=>-12345,'type'=>'channel','status'=>'active','is_public'=>true,'link'=>$this->mode === 'wrong_channel' ? 'https://max.ru/other_channel' : AppleService39PilotManifest::CHANNEL]);
        if ($method === 'GET' && $path === '/chats/-12345/members/me') {
            if ($this->membershipHook !== null) ($this->membershipHook)();
            return $this->ok(['user_id'=>123,'is_bot'=>true,'username'=>AppleService39PilotManifest::BOT,'is_admin'=>true,'permissions'=>$this->permissions]);
        }
        if ($method === 'POST' && $path === '/uploads') return $this->ok(['url'=>'https://'.$this->uploadHost.'/uploadImage?id='.(++$this->uploadId)]);
        if ($method === 'POST' && $path === '/messages') {
            as39ProviderAssert($query === ['chat_id'=>'-12345'] && !isset($request['body']['notify']), 'create targets the channel with native notification semantics');
            $mid = 'mid_'.(count($this->messages)+1);
            $this->messages[$mid] = $this->message($mid, $request['body']);
            if ($this->mode === 'create_timeout') return ['http'=>0,'transport_error'=>true,'data'=>null];
            return $this->ok(['message'=>$this->messages[$mid]]);
        }
        if ($method === 'GET' && str_starts_with($path, '/messages/')) {
            if ($this->mode === 'readback_timeout' && !$this->failedRead) { $this->failedRead = true; return ['http'=>0,'transport_error'=>true,'data'=>null]; }
            $message = $this->messages[basename($path)] ?? null;
            if (!is_array($message)) return ['http'=>404,'transport_error'=>false,'data'=>['code'=>'not_found']];
            if ($this->mode === 'wrong_image') $message['body']['attachments'][0]['payload']['photo_id'] = '999999';
            if (in_array($this->mode, ['remapped_image','remapped_wrong_token','missing_id_same_token'], true)) {
                $image = &$message['body']['attachments'][0]['payload'];
                $originalId = (string)$image['photo_id'];
                $image['photo_id'] = '90000'.$originalId;
                $image['token'] = 'photo_'.$originalId;
                if ($this->mode === 'remapped_wrong_token') $image['token'] = 'different-token';
                if ($this->mode === 'missing_id_same_token') unset($image['photo_id']);
                unset($image);
            }
            if ($this->mode === 'wrong_url') $message['url'] = 'https://max.ru/other_channel/borrowed';
            if ($this->mode === 'credential_url') $message['url'] = 'https://private_user@max.ru/channel_appleservice39ru/post_1';
            if ($this->mode === 'dot_url') $message['url'] = 'https://max.ru/channel_appleservice39ru/mid.1';
            if ($this->mode === 'wrapped_readback') return $this->ok(['message'=>$message]);
            return $this->ok($message);
        }
        if ($method === 'PUT' && $path === '/messages') {
            $mid = $query['message_id'] ?? '';
            as39ProviderAssert(isset($this->messages[$mid]), 'edit targets an exact prior created MID');
            if ($this->mode !== 'edit_unchanged_timeout') $this->messages[$mid] = $this->message($mid, $request['body']);
            if (in_array($this->mode, ['edit_timeout','edit_unchanged_timeout'], true)) return ['http'=>0,'transport_error'=>true,'data'=>null];
            return $this->ok(['success'=>true]);
        }
        if ($path === '/chats/-12345/pin') {
            if ($method === 'PUT') { $this->pinned = $request['body']['message_id']; return $this->ok(['success'=>true]); }
            return $this->ok(['message'=>$this->pinned === null ? null : $this->messages[$this->pinned]]);
        }
        throw new RuntimeException('Unexpected synthetic request');
    }

    private function message(string $mid, array $body): array
    {
        $attachments = $body['attachments'] ?? [];
        foreach ($attachments as &$attachment) if ($attachment['type'] === 'image') {
            $attachment['payload']['photo_id'] = substr($attachment['payload']['token'], strlen('photo_'));
            $attachment['payload']['token'] = 'renewed_'.$attachment['payload']['photo_id'];
        }
        unset($attachment);
        return ['recipient'=>['chat_id'=>-12345,'chat_type'=>'channel'],'body'=>['mid'=>$mid,'text'=>$body['text'],'attachments'=>$attachments],
            'url'=>'https://max.ru/channel_appleservice39ru/post_'.substr($mid,4),'timestamp'=>1770000000000];
    }
    public function count(string $method, string $path): int
    {
        return count(array_filter($this->requests, static fn(array $request): bool => $request['method'] === $method && parse_url($request['url'], PHP_URL_PATH) === $path));
    }
    public function writes(): int { return count(array_filter($this->requests, static fn(array $request): bool => $request['method'] !== 'GET')); }
    private function ok(array $data): array { return ['http'=>200,'transport_error'=>false,'data'=>$data]; }
}

function as39Publisher(PDO $pdo, ApplePilotTestProvider $provider, ?AppleService39PilotManifest $manifest = null): AppleService39PilotPublisher
{
    return new AppleService39PilotPublisher($pdo, $manifest ?? AppleService39PilotManifest::installed(), '-12345', static fn(): bool=>true, $provider, static function (): void {});
}

function as39FullPublicationRegression(PDO $pdo): void
{
    ProjectMaxConnection::resetForTests(as39Registry());
    $manifest = AppleService39PilotManifest::installed();
    as39Expect(count($manifest->posts()) === 24 && count(array_filter($manifest->posts(), static fn(array $post): bool=>$post['kind']==='repair')) === 15, 'exact approved 24-post and 15-entry pilot');
    $provider = new ApplePilotTestProvider; $publisher = as39Publisher($pdo, $provider, $manifest);
    $publisher->run('inspect');
    as39Expect($provider->writes() === 0 && (new AppleService39PilotPublicationStore($pdo))->campaign() === null, 'inspect has no provider or campaign mutation');
    as39Refuses(static fn()=>$publisher->run('publish'), 'prepare_images_first');
    $publisher->run('prepare');
    as39Expect($provider->count('POST','/uploads') === 24, 'approved covers upload once');
    $publisher->run('prepare');
    as39Expect($provider->count('POST','/uploads') === 24, 'prepared cover receipts prevent repeated uploads');
    $publisher->run('publish');
    as39Expect($provider->count('POST','/messages') === 24 && count($provider->messages) === 24, 'one create per approved post');
    $publisher->run('publish');
    as39Expect($provider->count('POST','/messages') === 24, 'repeated publish cannot create duplicates');
    $provider->mode = 'edit_timeout';
    $publisher->run('finalize');
    as39Expect($provider->count('PUT','/messages') === 24, 'lost edit responses reconcile with one readback and no repeated PUT');
    $provider->mode = '';
    $publisher->run('finalize');
    as39Expect($provider->count('PUT','/messages') === 24, 'completed crosslinks are idempotent');
    $store = new AppleService39PilotPublicationStore($pdo);
    foreach ($manifest->posts() as $post) {
        $row = $store->operation($post['id'], 'create'); $messageBody = $provider->messages[$row['external_message_id']]['body'];
        as39Expect($messageBody['text'] === $post['text'] && !preg_match('~https?://~', $messageBody['text']), $post['kind'].' keeps approved text without raw navigation URLs');
        $keyboards = array_values(array_filter($messageBody['attachments'], static fn(array $attachment): bool=>$attachment['type']==='inline_keyboard'));
        as39Expect(count($keyboards)===1, $post['kind'].' has exactly one native keyboard');
        $expected = array_map(static fn(array $button): array=>[['type'=>'link','text'=>$button['text'],'url'=>$button['url']]], $post['buttons']);
        foreach ($post['links'] as $link) $expected[]=[['type'=>'link','text'=>$link['label'],'url'=>$store->operation($link['id'],'create')['public_url']]];
        as39Expect($keyboards[0]['payload']['buttons']===$expected && count($expected)<=9,
            $post['kind'].' keyboard keeps repair CTA first and exact verified targets one per row');
    }
    $publisher->run('pin'); $publisher->run('pin');
    as39Expect($provider->count('PUT','/chats/-12345/pin') === 1, 'menu pin follows all post and link readbacks exactly once');
    $summary = json_encode($publisher->run('inspect'), JSON_THROW_ON_ERROR);
    as39Expect(!str_contains($summary, 'synthetic-apple-token') && !str_contains($summary, 'photo_') && !str_contains($summary, 'renewed_'), 'operator report excludes credentials and media tokens');
    $pdo->exec('DELETE FROM manager_projects WHERE manager_id=2');
    as39Refuses(static fn()=>$publisher->run('inspect'), 'active_project_admin_and_source_required');
}

if (!defined('APPLESERVICE39_PUBLISHER_FIXTURES_ONLY')) {
    as39FullPublicationRegression(as39Sqlite());
    foreach (['wrong_bot'=>'canonical_bot_identity_mismatch','wrong_channel'=>'public_channel_identity_mismatch'] as $mode=>$reason) {
        ProjectMaxConnection::resetForTests(as39Registry()); $pdo=as39Sqlite(); $provider=new ApplePilotTestProvider; $provider->mode=$mode;
        as39Refuses(static fn()=>as39Publisher($pdo,$provider)->run('prepare'), $reason);
        as39Expect($provider->writes()===0, 'identity refusal precedes every external write');
    }
    ProjectMaxConnection::resetForTests(as39Registry()); $pdo=as39Sqlite(); $provider=new ApplePilotTestProvider; $provider->permissions=['read_all_messages','edit'];
    as39Refuses(static fn()=>as39Publisher($pdo,$provider)->run('prepare'), 'channel_publish_edit_read_permissions_required');
    as39Expect($provider->writes()===0, 'read/edit rights alone cannot publish');
    ProjectMaxConnection::resetForTests(as39Registry()); $pdo=as39Sqlite(); $provider=new ApplePilotTestProvider; $provider->uploadHost='iu.oneme.ru.evil.test';
    as39Refuses(static fn()=>as39Publisher($pdo,$provider)->run('prepare'), 'pilot_upload_held');
    as39Expect($provider->count('POST','/uploads')===1 && $provider->count('POST','/uploadImage')===0, 'documented image host lookalike receives no upload or credential');
    ProjectMaxConnection::resetForTests(as39Registry()); $pdo=as39Sqlite(); $provider=new ApplePilotTestProvider;
    $provider->membershipHook=static function () use($pdo):void {$pdo->exec('DELETE FROM manager_projects WHERE manager_id=2');};
    as39Refuses(static fn()=>as39Publisher($pdo,$provider)->run('prepare'), 'active_project_admin_and_source_required');
    as39Expect($provider->writes()===0, 'revoked access during native preflight cannot publish');

    ProjectMaxConnection::resetForTests(as39Registry()); $pdo=as39Sqlite(); $provider=new ApplePilotTestProvider; $publisher=as39Publisher($pdo,$provider);
    $publisher->run('prepare'); $provider->mode='create_timeout';
    as39Refuses(static fn()=>$publisher->run('publish'), 'pilot_create_held');
    $provider->mode=''; $publisher=as39Publisher($pdo,$provider); $publisher->run('reconcile');
    as39Refuses(static fn()=>$publisher->run('publish'), 'pilot_create_held');
    as39Expect($provider->count('POST','/messages')===1, 'lost MID remains durable hold after a new process and read-only reconcile');
    $first=AppleService39PilotManifest::installed()->posts()[0]['id']; $store=new AppleService39PilotPublicationStore($pdo);
    as39Expect($store->operation($first,'create')['external_message_id']===null && count($provider->messages)===1, 'a similar real post is never adopted by content hash');
    $binding=$store->campaign(); unset($binding['created_at']); $binding['manifest_sha256']=str_repeat('f',64);
    as39Refuses(static fn()=>$store->bind($binding), 'campaign_binding_changed');
    as39Expect((int)$store->operation($first,'create')['write_attempts']===1, 'a new manifest cannot reset a held create operation');
    $prepared=$store->prepare('transaction-fixture','create',['body'=>['text'=>'Synthetic transaction boundary']]);
    $pdo->beginTransaction();
    as39Refuses(static fn()=>$store->claim($prepared), 'pilot_requires_autocommit');
    $pdo->rollBack();
    as39Expect((int)$store->operation('transaction-fixture','create')['write_attempts']===0, 'an uncommitted outer transaction cannot authorize provider writes');
    ProjectMaxConnection::resetForTests(as39Registry('rotated-synthetic-apple-token'));
    as39Refuses(static fn()=>$publisher->run('publish'), 'pilot_connection_changed');

    ProjectMaxConnection::resetForTests(as39Registry()); $pdo=as39Sqlite(); $provider=new ApplePilotTestProvider; $publisher=as39Publisher($pdo,$provider);
    $publisher->run('prepare'); $provider->mode='readback_timeout';
    as39Refuses(static fn()=>$publisher->run('publish'), 'pilot_create_held');
    $provider->mode=''; $publisher->run('reconcile'); $publisher->run('publish');
    as39Expect($provider->count('POST','/messages')===24, 'known MID readback completes the original attempt without duplicate send');
    $provider->mode='edit_unchanged_timeout';
    as39Refuses(static fn()=>$publisher->run('finalize'), 'pilot_edit_held');
    $provider->mode=''; $publisher->run('reconcile');
    as39Refuses(static fn()=>$publisher->run('finalize'), 'pilot_edit_held');
    as39Expect($provider->count('PUT','/messages')===1, 'unknown unchanged edit is never automatically repeated');

    foreach (['wrong_image'=>'post_image_mismatch','wrong_url'=>'native_post_url_required',
        'wrapped_readback'=>'post_readback_mismatch','credential_url'=>'native_post_url_required',
        'dot_url'=>'native_post_url_required'] as $mode=>$readbackReason) {
        ProjectMaxConnection::resetForTests(as39Registry()); $pdo=as39Sqlite(); $provider=new ApplePilotTestProvider; $publisher=as39Publisher($pdo,$provider);
        $publisher->run('prepare'); $provider->mode=$mode;
        as39Refuses(static fn()=>$publisher->run('publish'), 'pilot_create_held');
        as39Expect($provider->count('POST','/messages')===1, 'foreign readback cannot become a verified own publication');
        $writes=$provider->writes(); $report=$publisher->run('reconcile'); $diagnostic=$report['readback_diagnostics'][0] ?? [];
        as39Expect(($diagnostic['reason'] ?? null)===$readbackReason && $report['held_operations']===1,
            'read-only reconcile identifies the exact failed predicate while retaining the hold');
        as39Expect($provider->writes()===$writes && $provider->count('POST','/messages')===1,
            'readback diagnosis cannot retry a provider write');
        $safe=json_encode($diagnostic,JSON_THROW_ON_ERROR);
        as39Expect(!str_contains($safe,'photo_') && !str_contains($safe,'renewed_') && !str_contains($safe,'synthetic-apple-token')
            && !str_contains($safe,'private_user') && !str_contains($safe,'other_channel') && !str_contains($safe,'Здравствуйте') && !str_contains($safe,'Замена аккумулятора'),
            'readback diagnosis omits body, media tokens, credentials and foreign URLs');
        as39Expect(($diagnostic['message_envelope'] ?? false)===($mode==='wrapped_readback'),
            'diagnosis reports native response envelope shape without adopting its content');
        as39Expect(($diagnostic['observed_own_url'] ?? null)===($mode==='dot_url' ? 'https://max.ru/channel_appleservice39ru/mid.1'
            : ($mode==='wrong_image' ? 'https://max.ru/channel_appleservice39ru/post_1' : null)),
            'only exact own-channel public URL candidates appear as unverified diagnostic evidence');
    }
    ProjectMaxConnection::resetForTests(as39Registry()); $pdo=as39Sqlite(); $provider=new ApplePilotTestProvider; $publisher=as39Publisher($pdo,$provider);
    $publisher->run('prepare'); $provider->mode='wrong_image';
    as39Refuses(static fn()=>$publisher->run('publish'), 'pilot_create_held');
    $first=AppleService39PilotManifest::installed()->posts()[0]['id']; $store=new AppleService39PilotPublicationStore($pdo);
    $originalMid=$store->operation($first,'create')['external_message_id']; $writes=$provider->writes();
    $provider->mode='remapped_image'; $report=$publisher->run('reconcile');
    as39Expect($report['ok'] && $report['held_operations']===0 && $provider->writes()===$writes,
        'exact original upload token verifies a remapped native image by GET without another send');
    $row=$store->operation($first,'create');
    as39Expect($row['state']==='verified' && $row['external_message_id']===$originalMid && (int)$row['write_attempts']===1,
        'image recovery preserves the exact original MID and single write attempt');
    $publisher->run('publish'); $publisher->run('finalize'); $publisher->run('pin');
    as39Expect($provider->count('POST','/messages')===24 && $provider->count('PUT','/messages')===24
        && $provider->count('PUT','/chats/-12345/pin')===1,
        'all24 remapped native images keep exact own links and one menu pin without duplicate creation');
    foreach (['remapped_wrong_token','missing_id_same_token'] as $mode) {
        ProjectMaxConnection::resetForTests(as39Registry()); $pdo=as39Sqlite(); $provider=new ApplePilotTestProvider; $publisher=as39Publisher($pdo,$provider);
        $publisher->run('prepare'); $provider->mode=$mode;
        as39Refuses(static fn()=>$publisher->run('publish'), 'pilot_create_held');
        $report=$publisher->run('reconcile');
        as39Expect(!$report['ok'] && $report['held_operations']===1 && $provider->count('POST','/messages')===1,
            'a foreign token or missing native photo ID cannot clear an image hold');
        $diagnostic=$report['readback_diagnostics'][0]['image_readback'] ?? [];
        as39Expect(($diagnostic['upload_photo_id'] ?? null)==='1'
            && ($diagnostic['native_photo_id'] ?? null)===($mode==='missing_id_same_token' ? null : '900001')
            && ($diagnostic['upload_token_matches'] ?? null)===($mode==='missing_id_same_token'),
            'image diagnosis exposes bounded identifiers and match status without tokens');
    }
    as39Expect(!AppleService39PilotPublisher::publicUrl('https://max.ru/other_channel/post_1','-12345')
        && !AppleService39PilotPublisher::publicUrl('https://max.ru/c/999/post_1','-12345')
        && !AppleService39PilotPublisher::publicUrl('https://max.ru/channel_appleservice39ru/post_1?token=secret','-12345'), 'only exact canonical provider URLs enter the link graph');
    ProjectMaxConnection::resetForTests();
    echo "AppleService39 publisher regression complete\n";
}
