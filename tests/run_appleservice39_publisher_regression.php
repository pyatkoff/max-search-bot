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
    public string $channelId = '-12345';
    public bool $zeroUploadKey = false;
    public bool $reorderButtons = false;
    public bool $dropCreateImageId = false;
    public bool $failLandingReadOnce = false;
    public bool $preserveLandingTokens = true;
    public ?string $fixedMid = null;
    public ?string $fixedPhotoId = null;
    public ?string $fixedUrl = null;
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
            if ($this->mode === 'landing_upload_timeout' && str_contains($request['file'], '/artwork/')) return ['http'=>0,'transport_error'=>true,'data'=>null];
            $id = $query['id']; return $this->ok(['photos'=>[($this->zeroUploadKey ? '0' : $id)=>['token'=>'photo_'.$id]]]);
        }
        as39ProviderAssert($request['token'] === 'synthetic-apple-token-12345', 'API uses the exact project token');
        if ($method === 'GET' && $path === '/me') return $this->ok(['user_id'=>123,'is_bot'=>true,'username'=>$this->mode === 'wrong_bot' ? 'other_bot' : AppleService39PilotManifest::BOT]);
        if ($method === 'GET' && $path === '/chats/'.$this->channelId) return $this->ok(['chat_id'=>(int)$this->channelId,'type'=>'channel','status'=>'active','is_public'=>true,'link'=>$this->mode === 'wrong_channel' ? 'https://max.ru/other_channel' : AppleService39PilotManifest::CHANNEL]);
        if ($method === 'GET' && $path === '/chats/'.$this->channelId.'/members/me') {
            if ($this->membershipHook !== null) ($this->membershipHook)();
            return $this->ok(['user_id'=>123,'is_bot'=>true,'username'=>AppleService39PilotManifest::BOT,'is_admin'=>true,'permissions'=>$this->permissions]);
        }
        if ($method === 'POST' && $path === '/uploads') return $this->ok(['url'=>'https://'.$this->uploadHost.'/uploadImage?id='.(++$this->uploadId)]);
        if ($method === 'POST' && $path === '/messages') {
            as39ProviderAssert($query === ['chat_id'=>$this->channelId] && !isset($request['body']['notify']), 'create targets the channel with native notification semantics');
            $mid = $this->fixedMid ?? 'mid_'.(count($this->messages)+1);
            $this->messages[$mid] = $this->message($mid, $request['body']);
            if ($this->mode === 'create_timeout') return ['http'=>0,'transport_error'=>true,'data'=>null];
            $created = $this->messages[$mid];
            if ($this->dropCreateImageId) unset($created['body']['attachments'][0]['payload']['photo_id']);
            return $this->ok(['message'=>$created]);
        }
        if ($method === 'GET' && str_starts_with($path, '/messages/')) {
            if ($this->mode === 'readback_timeout' && !$this->failedRead) { $this->failedRead = true; return ['http'=>0,'transport_error'=>true,'data'=>null]; }
            $message = $this->messages[basename($path)] ?? null;
            if (!is_array($message)) return ['http'=>404,'transport_error'=>false,'data'=>['code'=>'not_found']];
            if ($this->failLandingReadOnce && isset($message['body']['markup'])) {
                $this->failLandingReadOnce = false;
                return ['http'=>0,'transport_error'=>true,'data'=>null];
            }
            if (isset($message['body']['markup']) && str_starts_with($this->mode, 'landing_')) {
                if ($this->mode === 'landing_foreign_image') $message['body']['attachments'][0]['payload'] = ['photo_id'=>'999999','token'=>'foreign-image'];
                if ($this->mode === 'landing_missing_image_id') unset($message['body']['attachments'][0]['payload']['photo_id']);
                if ($this->mode === 'landing_missing_token') unset($message['body']['attachments'][0]['payload']['token']);
            }
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
            if ($this->reorderButtons) {
                foreach ($message['body']['attachments'] as &$attachment) if ($attachment['type'] === 'inline_keyboard') {
                    foreach ($attachment['payload']['buttons'] as &$row) foreach ($row as &$button) $button = array_reverse($button, true);
                    unset($row,$button);
                }
                unset($attachment);
            }
            if ($this->mode === 'wrapped_readback') return $this->ok(['message'=>$message]);
            return $this->ok($message);
        }
        if ($method === 'PUT' && $path === '/messages') {
            $mid = $query['message_id'] ?? '';
            as39ProviderAssert(isset($this->messages[$mid]), 'edit targets an exact prior created MID');
            $priorImage = $this->messages[$mid]['body']['attachments'][0];
            if ($this->mode !== 'edit_unchanged_timeout') $this->messages[$mid] = $this->message($mid, $request['body']);
            if ($this->mode === 'landing_success_old_image') $this->messages[$mid]['body']['attachments'][0] = $priorImage;
            if (in_array($this->mode, ['edit_timeout','edit_unchanged_timeout'], true)) return ['http'=>0,'transport_error'=>true,'data'=>null];
            return $this->ok(['success'=>true]);
        }
        if ($path === '/chats/'.$this->channelId.'/pin') {
            if ($method === 'PUT') { $this->pinned = $request['body']['message_id']; return $this->ok(['success'=>true]); }
            return $this->ok(['message'=>$this->pinned === null ? null : $this->messages[$this->pinned]]);
        }
        throw new RuntimeException('Unexpected synthetic request');
    }

    private function message(string $mid, array $body): array
    {
        $attachments = $body['attachments'] ?? [];
        foreach ($attachments as &$attachment) if ($attachment['type'] === 'image') {
            $submitted = $attachment['payload']['token'];
            $attachment['payload']['photo_id'] = $this->fixedPhotoId ?? substr($attachment['payload']['token'], strlen('photo_'));
            $attachment['payload']['token'] = ($body['format'] ?? null) === 'html' && $this->preserveLandingTokens
                && (int)substr($submitted, strlen('photo_')) > 24 ? $submitted : 'renewed_'.$attachment['payload']['photo_id'];
        }
        unset($attachment);
        $native = ['mid'=>$mid,'text'=>$body['text'],'attachments'=>$attachments];
        if (($body['format'] ?? null) === 'html') {
            // Independent provider fixture: parse emitted tags, not the production projection.
            $plain = ''; $markup = []; $cursor = 0;
            preg_match_all('~<(b|a)(?: href="([^"]+)")?>(.*?)</\1>~s', $body['text'], $matches, PREG_SET_ORDER|PREG_OFFSET_CAPTURE);
            foreach ($matches as $match) {
                $plain .= html_entity_decode(substr($body['text'], $cursor, $match[0][1]-$cursor), ENT_QUOTES|ENT_HTML5, 'UTF-8');
                $label = html_entity_decode($match[3][0], ENT_QUOTES|ENT_HTML5, 'UTF-8');
                $item = ['type'=>$match[1][0] === 'b' ? 'strong' : 'link',
                    'from'=>mb_strlen($plain, 'UTF-8'),'length'=>mb_strlen($label, 'UTF-8')];
                if ($item['type'] === 'link') $item['url'] = html_entity_decode($match[2][0], ENT_QUOTES|ENT_HTML5, 'UTF-8');
                $markup[] = $item; $plain .= $label; $cursor = $match[0][1]+strlen($match[0][0]);
            }
            $native['text'] = $plain.html_entity_decode(substr($body['text'], $cursor), ENT_QUOTES|ENT_HTML5, 'UTF-8');
            $native['markup'] = $markup;
        }
        return ['recipient'=>['chat_id'=>(int)$this->channelId,'chat_type'=>'channel'],'body'=>$native,
            'url'=>$this->fixedUrl ?? 'https://max.ru/channel_appleservice39ru/post_'.substr($mid,4),'timestamp'=>1770000000000];
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
    return new AppleService39PilotPublisher($pdo, $manifest ?? AppleService39PilotManifest::installed(), $provider->channelId, static fn(): bool=>true, $provider, static function (): void {});
}


function as39LandingRegression(PDO $pdo, ApplePilotTestProvider $provider, AppleService39PilotPublisher $publisher): void
{
    $manifest = AppleService39PilotManifest::installed();
    $revision = new AppleService39LandingRevision($manifest);
    $store = new AppleService39PilotPublicationStore($pdo);
    $original = $store->rows();
    $writes = $provider->writes();
    $pin = $provider->pinned;
    $inspection = $publisher->run('landing_inspect');
    as39Expect($inspection['landing_verified_posts'] === 0 && count($inspection['landing_readback']) === 24
        && $provider->writes() === $writes && $store->rows() === $original, 'landing inspection changes no provider or durable state');

    $last = $manifest->posts()[23]['id']; $lastMid = $store->operation($last, 'create')['external_message_id'];
    $saved = $provider->messages[$lastMid];
    $provider->messages[$lastMid]['body']['text'] .= ' Manual intervening edit';
    as39Refuses(static fn()=>$publisher->run('landing_apply'), 'post_readback_mismatch');
    as39Expect($provider->writes() === $writes && $store->rows() === $original,
        'a changed final original post stops the entire batch before any revision claim or edit');
    $provider->messages[$lastMid] = $saved;
    $provider->pinned = null;
    as39Refuses(static fn()=>$publisher->run('landing_apply'), 'landing_menu_pin_changed');
    as39Expect($provider->writes() === $writes, 'landing never silently repins or replaces an operator pin');
    $provider->pinned = $pin;

    as39Refuses(static fn()=>$publisher->run('landing_apply'), 'landing_images_not_prepared');
    as39Expect($provider->writes() === $writes && $store->rows() === $original,
        'missing new uploads stop all edits without creating a revision claim');
    $publisher->run('landing_prepare');
    as39Expect($provider->count('POST', '/uploads') === 39 && $provider->count('PUT', '/messages') === 24,
        'the fifteen reviewed repair images upload in a separate phase without editing a post');
    $uploadWrites = $provider->writes(); $publisher->run('landing_prepare');
    as39Expect($provider->writes() === $uploadWrites, 'verified landing uploads are reused without another reservation or multipart send');
    foreach ($revision->posts() as $post) {
        $upload = $store->operation($post['id'], 'landing_upload'); $asset = $revision->artwork($post['id']);
        as39Expect($asset === null ? $upload === null : $upload !== null && $upload['state'] === 'verified'
            && (int)$upload['write_attempts'] === 1 && $upload['request']['source_sha256'] === $asset['sha256']
            && $upload['request']['mid'] === $store->operation($post['id'], 'create')['external_message_id'],
            'only the exact fifteen source-bound repair uploads acquire one claim');
    }
    $writes = $provider->writes();

    $provider->failLandingReadOnce = true;
    as39Refuses(static fn()=>$publisher->run('landing_apply'), 'landing_edit_held');
    $first = $manifest->posts()[0]['id']; $row = $store->operation($first, 'landing_edit');
    as39Expect($row['state'] === 'unknown' && (int)$row['write_attempts'] === 1 && $provider->writes() === $writes+1,
        'lost landing readback retains one exact same-MID edit attempt');
    as39Refuses(static fn()=>$publisher->run('landing_apply'), 'landing_edit_held');
    as39Expect($provider->writes() === $writes+1, 'a repeated apply cannot replay an uncertain landing edit');

    $mid = $row['external_message_id']; $saved = $provider->messages[$mid];
    $provider->messages[$mid]['body']['markup'][1]['url'] = 'https://max.ru/other_channel/borrowed';
    $held = $publisher->run('landing_reconcile');
    as39Expect(!$held['ok'] && $store->operation($first, 'landing_edit')['state'] === 'unknown'
        && $provider->writes() === $writes+1, 'changed native hyperlink refuses read-only landing reconciliation');
    $provider->messages[$mid] = $saved;
    $reconciled = $publisher->run('landing_reconcile');
    as39Expect($reconciled['ok'] && $reconciled['landing_verified_posts'] === 1 && $provider->writes() === $writes+1,
        'exact native formatted readback closes the original held edit without another PUT');

    $provider->mode = 'edit_timeout';
    $finished = $publisher->run('landing_apply');
    $provider->mode = '';
    as39Expect($finished['ok'] && $finished['landing_verified_posts'] === 24 && $finished['landing_menu_pin_preserved']
        && $provider->writes() === $writes+24 && $provider->pinned === $pin,
        'one coherent revision updates all original messages and preserves the existing menu pin');
    $publisher->run('landing_apply'); $publisher->run('landing_reconcile'); $publisher->run('landing_inspect');
    as39Expect($provider->writes() === $writes+24 && $provider->count('POST', '/messages') === 24
        && $provider->count('POST', '/uploads') === 39 && $provider->count('PUT', '/chats/-12345/pin') === 1,
        'completed revision repeats no edits, publications, uploads or pin operations');
    $oldRows = array_values(array_filter($store->rows(), static fn(array $r): bool => !str_starts_with($r['operation'], 'landing_')));
    as39Expect($oldRows === $original, 'all original campaign operations and image receipts remain byte-for-byte unchanged');

    $links = 0;
    foreach ($revision->posts() as $post) {
        $row = $store->operation($post['id'], 'landing_edit');
        $body = $provider->messages[$row['external_message_id']]['body'];
        $keyboards = array_values(array_filter($body['attachments'], static fn(array $a): bool=>$a['type'] === 'inline_keyboard'));
        $buttons = $keyboards[0]['payload']['buttons'] ?? [];
        as39Expect($row['state'] === 'verified' && (int)$row['write_attempts'] === 1 && count($keyboards) === 1
            && count($buttons) === 1 && count($buttons[0]) === 1 && $buttons[0][0]['text'] === $post['button_label']
            && $buttons[0][0]['url'] === 'https://max.ru/'.AppleService39PilotManifest::BOT.'?start=as39_'.$post['id'],
            'exactly one contextual bot button on '.$post['id']);
        $navigation = array_values(array_filter($body['markup'], static fn(array $m): bool=>$m['type'] === 'link'));
        as39Expect(count($navigation) === count($post['links']) && !str_contains($body['text'], '<a ')
            && !preg_match('~https?://~', $body['text']), 'navigation is native text hyperlinks, not visible URLs or extra buttons');
        foreach ($post['links'] as $i=>$link) {
            $expectedUrl = $store->operation($link['id'], 'create')['public_url'];
            $m = $navigation[$i];
            as39Expect($m['url'] === $expectedUrl
                && mb_substr($body['text'], $m['from'], $m['length'], 'UTF-8') === $link['label'],
                'native link span targets the exact original own post');
        }
        $links += count($navigation);
        $entry = AppleService39PilotCatalog::forPost($post['id']);
        if ($entry !== null) {
            $image = $row['outcome']['image_receipt'] ?? [];
            as39Expect(($image['kind'] ?? null) === 'exact_upload_token'
                && $image['source_sha256'] === $revision->artwork($post['id'])['sha256']
                && $image['photo_id'] !== $store->operation($post['id'], 'create')['outcome']['image_receipt']['photo_id']
                && $image['request_sha256'] === $row['payload_sha256'],
                'new native image is bound to its own source upload and exact same-MID edit');
        }
        if ($entry !== null) as39Expect(str_contains($body['text'], $entry['price_text']),
            'landing preserves the exact approved price qualification');
    }
    as39Expect($links === 86 && $finished['landing_verified_new_images'] === 15, 'all 86 navigation links and15 native source-image proofs survive the one-button revision');
    $markup = [['type'=>'link','from'=>2,'length'=>3,'url'=>'https://max.ru/channel_appleservice39ru/a']];
    as39Expect(AppleService39LandingRevision::sameMarkup([array_reverse($markup[0], true)], $markup),
        'native markup object key order is immaterial');
    foreach ([null,[],[['type'=>'link','from'=>'2','length'=>3,'url'=>$markup[0]['url']]],
        [['type'=>'link','from'=>2,'length'=>4,'url'=>$markup[0]['url']]],
        [array_merge($markup[0], ['extra'=>null])]] as $bad) {
        as39Expect(!AppleService39LandingRevision::sameMarkup($bad, $markup), 'changed markup type, span, count or fields refuses');
    }
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
    as39Expect(!str_contains($summary, 'synthetic-apple-token') && !preg_match('/"(?:photo|renewed)_[0-9]+"/', $summary), 'operator report excludes credentials and media tokens');
    as39LandingRegression($pdo, $provider, $publisher);
    $pdo->exec('DELETE FROM manager_projects WHERE manager_id=2');
    as39Refuses(static fn()=>$publisher->run('inspect'), 'active_project_admin_and_source_required');
}

function as39LandingImageSafetyRegression(): void
{
    foreach (['landing_foreign_image','landing_missing_image_id','landing_missing_token','landing_success_old_image','rotated_native_token'] as $mode) {
        ProjectMaxConnection::resetForTests(as39Registry()); $pdo = as39Sqlite();
        $provider = new ApplePilotTestProvider; $provider->zeroUploadKey = true;
        $publisher = as39Publisher($pdo, $provider);
        foreach (['prepare','publish','finalize','pin','landing_prepare'] as $phase) $publisher->run($phase);
        $store = new AppleService39PilotPublicationStore($pdo);
        $provider->mode = $mode; $provider->preserveLandingTokens = $mode !== 'rotated_native_token';
        $writes = $provider->writes();
        as39Refuses(static fn()=>$publisher->run('landing_apply'), 'landing_edit_held');
        $first = AppleService39PilotManifest::installed()->posts()[0]['id'];
        $row = $store->operation($first, 'landing_edit');
        as39Expect($row['state'] === 'unknown' && (int)$row['write_attempts'] === 1
            && !isset($row['outcome']['image_receipt']) && $provider->writes() === $writes+1,
            'ACK/changed photoID without exact source token cannot establish a new image receipt: '.$mode);
        $result = $publisher->run('landing_reconcile');
        as39Expect(!$result['ok'] && $result['landing_verified_new_images'] === 0 && $provider->writes() === $writes+1,
            'an arbitrary fresh image GET does not close or replay the held edit: '.$mode);
        as39Refuses(static fn()=>$publisher->run('landing_apply'), 'landing_edit_held');
        as39Expect($provider->writes() === $writes+1, 'held same-MID image edits never replay: '.$mode);
        // Supply the real unique token from this fixture's own recorded upload.
        $provider->mode = ''; $provider->preserveLandingTokens = true;
        $upload = $store->operation($first, 'landing_upload');
        $provider->messages[$row['external_message_id']]['body']['attachments'][0]['payload'] = [
            'photo_id'=>'5000001','token'=>$upload['outcome']['token']];
        $recovered = as39Publisher($pdo, $provider)->run('landing_reconcile');
        $saved = $store->operation($first, 'landing_edit');
        as39Expect($recovered['ok'] && $recovered['landing_verified_new_images'] === 1
            && $saved['state'] === 'verified' && (int)$saved['write_attempts'] === 1 && $provider->writes() === $writes+1,
            'only the exact original source token may reconcile the original image attempt by GET');
        $proof = $saved['outcome']['image_receipt']; $proof['source_sha256'] = str_repeat('f',64);
        as39Refuses(static fn()=>$store->finish($saved, 'unknown', ['image_receipt'=>$proof]), 'image_receipt_changed');
        as39Expect($store->operation($first, 'landing_edit') === $saved, 'completion/hold cannot overwrite immutable native image evidence');
    }

    ProjectMaxConnection::resetForTests(as39Registry()); $pdo = as39Sqlite(); $provider = new ApplePilotTestProvider;
    $publisher = as39Publisher($pdo, $provider);
    foreach (['prepare','publish','finalize','pin'] as $phase) $publisher->run($phase);
    $provider->mode = 'landing_upload_timeout'; $writes = $provider->writes();
    as39Refuses(static fn()=>$publisher->run('landing_prepare'), 'landing_upload_held');
    $store = new AppleService39PilotPublicationStore($pdo); $first = AppleService39PilotManifest::installed()->posts()[0]['id'];
    $held = $store->operation($first, 'landing_upload');
    as39Expect($held['state'] === 'unknown' && (int)$held['write_attempts'] === 1 && $provider->writes() === $writes+2,
        'lost image-upload response retains one reservation/multipart attempt');
    $provider->mode = '';
    as39Refuses(static fn()=>$publisher->run('landing_prepare'), 'landing_upload_held');
    $result = $publisher->run('landing_reconcile');
    as39Expect(!$result['ok'] && $provider->writes() === $writes+2 && $store->operation($first, 'landing_edit') === null,
        'new process/read-only reconcile cannot repeat or adopt an unknown upload');
    as39Refuses(static fn()=>$store->prepare($first, 'landing_upload', array_merge($held['request'], ['source_sha256'=>str_repeat('f',64)])), 'pilot_operation_changed');
}

if (!defined('APPLESERVICE39_PUBLISHER_FIXTURES_ONLY')) {
    as39FullPublicationRegression(as39Sqlite());
    as39LandingImageSafetyRegression();

    // Provider did not apply a timed-out edit: reconciliation must keep the original hold.
    ProjectMaxConnection::resetForTests(as39Registry());
    $landingPdo = as39Sqlite(); $landingProvider = new ApplePilotTestProvider;
    $landingPublisher = as39Publisher($landingPdo, $landingProvider);
    foreach (['prepare','publish','finalize','pin'] as $phase) $landingPublisher->run($phase);
    $landingPublisher->run('landing_prepare');
    $landingProvider->mode = 'edit_unchanged_timeout';
    $landingWrites = $landingProvider->writes();
    as39Refuses(static fn()=>$landingPublisher->run('landing_apply'), 'landing_edit_held');
    $landingHeld = $landingPublisher->run('landing_reconcile');
    as39Expect(!$landingHeld['ok'] && $landingHeld['held_operations'] === 1
        && $landingProvider->writes() === $landingWrites+1 && $landingHeld['landing_verified_posts'] === 0,
        'an unapplied timed-out revision remains held after read-only reconciliation');
    as39Refuses(static fn()=>$landingPublisher->run('landing_apply'), 'landing_edit_held');
    as39Expect($landingProvider->writes() === $landingWrites+1, 'an old body cannot reset an attempted landing edit');

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
        as39Expect(!str_contains($safe,'"photo_1"') && !str_contains($safe,'"renewed_1"') && !str_contains($safe,'synthetic-apple-token')
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
    // A photos-map key of zero is an upload index, not a message photoID.
    ProjectMaxConnection::resetForTests(as39Registry()); $pdo=as39Sqlite(); $provider=new ApplePilotTestProvider;
    $provider->zeroUploadKey=true; $provider->reorderButtons=true; $publisher=as39Publisher($pdo,$provider);
    $publisher->run('prepare'); $publisher->run('publish'); $publisher->run('finalize'); $publisher->run('pin');
    $store=new AppleService39PilotPublicationStore($pdo); $report=$publisher->run('inspect');
    as39Expect($report['ok'] && $provider->count('POST','/messages')===24 && $provider->count('PUT','/messages')===24,
        'zero upload keys and renewed read tokens retain24 native POST image receipts without resend');
    foreach (AppleService39PilotManifest::installed()->posts() as $post) {
        $created=$store->operation($post['id'],'create'); $uploaded=$store->operation($post['id'],'upload');
        as39Expect($uploaded['outcome']['photo_id']==='0' && $created['outcome']['image_receipt']['kind']==='create_response'
            && $created['outcome']['image_receipt']['photo_id']!=='0'
            && $created['outcome']['image_receipt']['request_sha256']===$created['payload_sha256'],
            'native image receipt is bound to its own create request rather than the upload map key');
    }
    $linked=array_values(array_filter($report['operations'],static fn(array $row):bool=>$row['operation']==='links'));
    as39Expect(count($linked)===24 && count(array_filter($linked,static fn(array $row):bool=>$row['button_object_key_order_normalized']===true))===24,
        'native JSON object-key reordering is accepted with exact row order and typed button values');

    ProjectMaxConnection::resetForTests(as39Registry()); $pdo=as39Sqlite(); $provider=new ApplePilotTestProvider;
    $provider->zeroUploadKey=true; $provider->dropCreateImageId=true; $publisher=as39Publisher($pdo,$provider); $publisher->run('prepare');
    as39Refuses(static fn()=>$publisher->run('publish'),'pilot_create_held');
    $provider->dropCreateImageId=false; $writes=$provider->writes(); $report=as39Publisher($pdo,$provider)->run('reconcile');
    as39Expect(!$report['ok'] && $report['held_operations']===1 && $provider->writes()===$writes
        && $provider->count('POST','/messages')===1,
        'a missing POST image receipt cannot be fabricated from an arbitrary known MID GET');
    as39Refuses(static fn()=>$publisher->run('publish'),'pilot_create_held');

    // The single legacy exception is the exact recorded own MID and reviewed native image.
    ProjectMaxConnection::resetForTests(as39Registry()); $pdo=as39Sqlite(); $provider=new ApplePilotTestProvider;
    $provider->channelId='-80917954947727'; $provider->fixedMid='mid.ffffb667d20a157101a1bd1d06ec5ef9';
    $provider->fixedPhotoId='46750893649'; $provider->fixedUrl='https://max.ru/channel_appleservice39ru/AaG9HQbsXvk';
    $provider->zeroUploadKey=true; $provider->dropCreateImageId=true; $provider->reorderButtons=true;
    $publisher=as39Publisher($pdo,$provider); $publisher->run('prepare');
    as39Refuses(static fn()=>$publisher->run('publish'),'pilot_create_held');
    $provider->dropCreateImageId=false; $provider->mode='wrong_image'; $writes=$provider->writes();
    $report=$publisher->run('reconcile');
    as39Expect(!$report['ok'] && $report['held_operations']===1 && $provider->writes()===$writes,
        'reviewed legacy proof cannot bind another native photoID');
    $provider->mode=''; $report=as39Publisher($pdo,$provider)->run('reconcile');
    $store=new AppleService39PilotPublicationStore($pdo); $first=AppleService39PilotManifest::installed()->posts()[0]['id'];
    $row=$store->operation($first,'create');
    as39Expect($report['ok'] && $row['state']==='verified' && (int)$row['write_attempts']===1
        && $row['external_message_id']===$provider->fixedMid && $row['public_url']===$provider->fixedUrl
        && $row['outcome']['image_receipt']['kind']==='reviewed_legacy_native_image' && $provider->writes()===$writes,
        'exact reviewed legacy MID reconciles by native GET with its original single attempt and disclosed proof kind');
    $publisher->run('reconcile');
    as39Expect($provider->count('POST','/messages')===1 && $provider->writes()===$writes,
        'repeated legacy reconciliation cannot publish or replace a message');

    $compare=new ReflectionMethod(AppleService39PilotPublisher::class,'sameButtons');
    $a=['type'=>'link','text'=>'A','url'=>'https://max.ru/channel_appleservice39ru/a'];
    $b=['type'=>'link','text'=>'B','url'=>'https://max.ru/channel_appleservice39ru/b'];
    as39Expect($compare->invoke(null,[[array_reverse($a,true)],[$b]],[[$a],[$b]]),
        'button object key order alone is immaterial');
    foreach ([[[$b],[$a]],[[array_merge($a,['url'=>1])],[$b]],[[array_merge($a,['extra'=>null])],[$b]],
        [[['type'=>'link','text'=>'A']],[$b]],[1=>[$a],2=>[$b]]] as $bad) {
        as39Expect(!$compare->invoke(null,$bad,[[$a],[$b]]),'changed button rows, fields, types and sparse arrays refuse');
    }
    as39Expect(!$compare->invoke(null,[[$b,$a]],[[$a,$b]]),'button order within a row remains exact');

    as39Expect(!AppleService39PilotPublisher::publicUrl('https://max.ru/other_channel/post_1','-12345')
        && !AppleService39PilotPublisher::publicUrl('https://max.ru/c/999/post_1','-12345')
        && !AppleService39PilotPublisher::publicUrl('https://max.ru/channel_appleservice39ru/post_1?token=secret','-12345'), 'only exact canonical provider URLs enter the link graph');
    ProjectMaxConnection::resetForTests();
    echo "AppleService39 publisher regression complete\n";
}
