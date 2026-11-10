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
    public $editHook = null;
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
            $priorAttachments = $this->messages[$mid]['body']['attachments'];
            $priorImage = $priorAttachments[0];
            if ($this->mode !== 'edit_unchanged_timeout') {
                $this->messages[$mid] = $this->message($mid, $request['body']);
                // MAX PUT leaves native attachments unchanged when the field is absent.
                if (!array_key_exists('attachments', $request['body'])) $this->messages[$mid]['body']['attachments'] = $priorAttachments;
            }
            if ($this->mode === 'landing_success_old_image') $this->messages[$mid]['body']['attachments'][0] = $priorImage;
            if ($this->editHook !== null) ($this->editHook)($mid);
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
            $attachment['payload']['url'] = 'https://i.oneme.ru/synthetic-image/'.$attachment['payload']['photo_id'].'.jpg';
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

function as39CataloguePublisher(PDO $pdo, ApplePilotTestProvider $provider, ?AppleService39CatalogueRevision $revision = null): AppleService39PilotPublisher
{
    $manifest = AppleService39PilotManifest::installed();
    return new AppleService39PilotPublisher($pdo, $manifest, $provider->channelId, static fn(): bool=>true,
        $provider, static function (): void {}, $revision ?? new AppleService39CatalogueRevision($manifest));
}

/** Build the accepted predecessor through real publisher transitions, never fabricated receipts. */
function as39CatalogueFixture(): array
{
    ProjectMaxConnection::resetForTests(as39Registry());
    $pdo = as39Sqlite(); $provider = new ApplePilotTestProvider; $publisher = as39Publisher($pdo, $provider);
    foreach (['prepare','publish','finalize','pin','landing_prepare','landing_apply'] as $phase) $publisher->run($phase);
    $store = new AppleService39PilotPublicationStore($pdo);
    as39Expect(count($store->rows()) === 112 && $publisher->run('landing_inspect')['landing_verified_posts'] === 24,
        'catalogue fixture has all112 original one-attempt verified operations');
    return [$pdo,$provider,$store];
}

function as39GroupedCatalogueRegression(): void
{
    [$pdo,$provider,$store] = as39CatalogueFixture();
    $before = $provider->messages; $priorRows = $store->rows(); $writes = $provider->writes();
    $requestOffset = count($provider->requests); $pinned = $provider->pinned;
    $publisher = as39CataloguePublisher($pdo,$provider);
    $result = $publisher->run('landing_apply');
    as39Expect($result['ok'] && $result['catalogue_verified_posts'] === 24 && $result['catalogue_preserved_images'] === 24
        && $result['catalogue_model_count'] === 33 && $result['catalogue_warranty_months'] === 3
        && $result['catalogue_new_uploads'] === 0 && $result['catalogue_new_images'] === 0
        && $result['catalogue_menu_pin_preserved'] && $provider->pinned === $pinned,
        '33-model catalogue updates all24 texts with3-month warranty and preserves24 native images and pin');
    $newWrites = array_values(array_filter(array_slice($provider->requests,$requestOffset), static fn(array $r): bool=>$r['method'] !== 'GET'));
    as39Expect(count($newWrites) === 24 && $provider->writes() === $writes+24, 'catalogue performs exactly24 edits with no uploads, creates or pin writes');
    foreach ($newWrites as $request) as39Expect($request['method'] === 'PUT'
        && parse_url($request['url'],PHP_URL_PATH) === '/messages' && array_keys($request['body']) === ['text','format']
        && $request['body']['format'] === 'html', 'catalogue PUT omits attachments and every non-text write field');
    $links = 0; $buttons = 0;
    foreach ($provider->messages as $mid=>$message) {
        as39Expect($message['body']['attachments'] === $before[$mid]['body']['attachments'] && $message['url'] === $before[$mid]['url'],
            'native image payload, keyboard, MID and URL survive the text-only edit');
        as39Expect(substr_count($message['body']['text'],AppleService39PilotCatalog::WARRANTY_TEXT) === 1,
            'every published caption contains the exact owner-confirmed warranty once');
        $links += count(array_filter($message['body']['markup'],static fn(array $m):bool=>$m['type']==='link'));
        foreach ($message['body']['attachments'] as $a) if ($a['type'] === 'inline_keyboard') $buttons += count($a['payload']['buttons']);
    }
    $oldRows = array_values(array_filter($store->rows(),static fn(array $r):bool=>$r['operation']!=='catalogue_v1'));
    $newRows = array_values(array_filter($store->rows(),static fn(array $r):bool=>$r['operation']==='catalogue_v1'));
    as39Expect($links === 86 && $buttons === 24 && $oldRows === $priorRows && count($newRows) === 24,
        'all86 text links,24 single bot buttons and112 immutable predecessor rows remain intact');
    foreach ($newRows as $row) as39Expect($row['state'] === 'verified' && (int)$row['write_attempts'] === 1
        && $row['outcome']['text_only'] === true, 'each catalogue edit has one immutable verified attempt');
    foreach (['landing_apply','landing_prepare','landing_inspect','landing_reconcile'] as $phase) as39Expect($publisher->run($phase)['catalogue_verified_posts'] === 24,
        'completed catalogue '.$phase.' validates current content');
    as39Expect($provider->writes() === $writes+24, 'completed catalogue never repeats an external mutation');
    foreach (AppleService39PilotManifest::installed()->posts() as $post) {
        $entry = AppleService39PilotCatalog::landingEntryForPost($post['id']);
        as39Expect($entry !== null && substr_count(AppleService39PilotCatalog::responseText($entry,'AppleService39'),AppleService39PilotCatalog::WARRANTY_TEXT) === 1,
            'all24 future entry greetings use the same three-month warranty');
    }

    // A different caption under the same durable operation cannot reopen an accepted claim.
    as39WithCatalogueMutation(static function(array &$manifest,array &$catalogue):void {
        $manifest['posts'][0]['paragraphs'][0] .= ' Новая неподтвержденная редакция.';
    }, static function(AppleService39CatalogueRevision $revision) use($pdo,$provider,$writes):void {
        as39Refuses(static fn()=>as39CataloguePublisher($pdo,$provider,$revision)->run('landing_apply'),'catalogue_revision_changed');
        as39Expect($provider->writes() === $writes+24,'changed catalogue hash cannot replay an accepted operation');
    });

    ProjectMaxConnection::resetForTests(as39Registry()); $empty = as39Sqlite(); $emptyProvider = new ApplePilotTestProvider;
    as39Refuses(static fn()=>as39CataloguePublisher($empty,$emptyProvider)->run('landing_apply'),'catalogue_parent_incomplete');
    as39Expect($emptyProvider->writes() === 0,'catalogue cannot bypass an incomplete predecessor');

    foreach (['edit_timeout','edit_unchanged_timeout'] as $mode) {
        [$heldPdo,$heldProvider,$heldStore] = as39CatalogueFixture(); $heldPublisher = as39CataloguePublisher($heldPdo,$heldProvider);
        $heldWrites = $heldProvider->writes(); $heldProvider->mode = $mode;
        as39Refuses(static fn()=>$heldPublisher->run('landing_apply'),'catalogue_edit_held');
        $first = AppleService39PilotManifest::installed()->posts()[0]['id']; $claim = $heldStore->operation($first,'catalogue_v1');
        as39Expect($claim['state'] === 'unknown' && (int)$claim['write_attempts'] === 1 && $heldProvider->writes() === $heldWrites+1,
            'lost catalogue ACK retains the original single attempted write and stops the batch');
        $heldProvider->mode = '';
        as39Refuses(static fn()=>$heldPublisher->run('landing_apply'),'catalogue_edit_held');
        $inspection = $heldPublisher->run('landing_inspect');
        as39Expect(!$inspection['ok'] && $heldStore->operation($first,'catalogue_v1')['state'] === 'unknown',
            'inspection never accepts or resets an ambiguous catalogue write');
        $reconciled = $heldPublisher->run('landing_reconcile');
        as39Expect($heldProvider->writes() === $heldWrites+1, 'catalogue reconciliation performs GET only');
        if ($mode === 'edit_timeout') {
            as39Expect($reconciled['ok'] && $reconciled['catalogue_verified_posts'] === 1
                && (int)$heldStore->operation($first,'catalogue_v1')['write_attempts'] === 1,
                'exact native readback reconciles the original lost ACK without a second attempt');
            as39Expect($heldPublisher->run('landing_apply')['catalogue_verified_posts'] === 24 && $heldProvider->writes() === $heldWrites+24,
                'after GET acceptance only the23 unattempted catalogue edits may continue');
        } else {
            as39Expect(!$reconciled['ok'] && $reconciled['held_operations'] === 1,'an unapplied timed-out caption stays held');
            as39Refuses(static fn()=>$heldPublisher->run('landing_apply'),'catalogue_edit_held');
            as39Expect($heldProvider->writes() === $heldWrites+1,'an unapplied caption is never sent a second time');
        }
    }

    foreach (['image','button'] as $damage) {
        [$badPdo,$badProvider,$badStore] = as39CatalogueFixture(); $badPublisher=as39CataloguePublisher($badPdo,$badProvider);
        $originalAttachments = $badProvider->messages['mid_1']['body']['attachments']; $badWrites = $badProvider->writes();
        $badProvider->editHook = static function(string $mid) use($badProvider,$damage):void {
            if ($damage === 'image') $badProvider->messages[$mid]['body']['attachments'][0]['payload']['photo_id']='888888';
            else $badProvider->messages[$mid]['body']['attachments'][1]['payload']['buttons'][0][0]['url']='https://max.ru/foreign_bot';
        };
        as39Refuses(static fn()=>$badPublisher->run('landing_apply'),'catalogue_edit_held');
        as39Expect(!$badPublisher->run('landing_reconcile')['ok'] && $badProvider->writes() === $badWrites+1,
            'changed native '.$damage.' blocks acceptance and all remaining edits');
        $badProvider->editHook = null; $badProvider->messages['mid_1']['body']['attachments']=$originalAttachments;
        as39Expect($badPublisher->run('landing_reconcile')['catalogue_verified_posts'] === 1 && $badProvider->writes() === $badWrites+1,
            'only exact restored native '.$damage.' can reconcile the same original attempt');
    }
}

/** A temporary reviewed-data copy exercises trust boundaries without mutating installed resources. */
function as39WithCatalogueMutation(callable $mutate, callable $check): void
{
    $source = dirname(__DIR__).'/resources/appleservice39/catalogue-v1';
    $directory = sys_get_temp_dir().'/as39-grouped-'.bin2hex(random_bytes(8)); mkdir($directory,0700);
    try {
        $manifest=json_decode((string)file_get_contents($source.'/manifest.json'),true,32,JSON_THROW_ON_ERROR);
        $catalogue=json_decode((string)file_get_contents($source.'/catalog.json'),true,32,JSON_THROW_ON_ERROR);
        $mutate($manifest,$catalogue);
        $json=json_encode($catalogue,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        file_put_contents($directory.'/catalog.json',$json); $manifest['catalogue_sha256']=hash('sha256',$json);
        file_put_contents($directory.'/manifest.json',json_encode($manifest,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        $check(new AppleService39CatalogueRevision(AppleService39PilotManifest::installed(),$directory.'/manifest.json'));
    } finally {
        foreach (['manifest.json','catalog.json'] as $file) if (is_file($directory.'/'.$file)) unlink($directory.'/'.$file);
        rmdir($directory);
    }
}

function as39CatalogueSourceRegression(): void
{
    $cases = [
        'catalogue_source_invalid'=>static function(array &$m,array &$c):void {$c['offers'][0]['warranty_months']=1;},
        'grouped_quote'=>static function(array &$m,array &$c):void {
            foreach($c['offers'] as &$offer) if(count($offer['model_ids'])>1) {$offer['price']['kind']='listed';$offer['price']['amount']=7000;break;} unset($offer);
        },
        'pending_price'=>static function(array &$m,array &$c):void {
            foreach($c['offers'] as &$offer) if($offer['price']['kind']==='unconfirmed') {$offer['price']['amount']=800;break;} unset($offer);
        },
        'catalogue_post_invalid'=>static function(array &$m,array &$c):void {$m['posts'][0]['button_label']='Другая кнопка';},
        'catalogue_links_changed'=>static function(array &$m,array &$c):void {$m['posts'][0]['links'][0]['label']='Другой переход';},
        'catalogue_reviewed_price_changed'=>static function(array &$m,array &$c):void {
            $price=AppleService39PilotCatalog::forPost($m['posts'][0]['id'])['price_text'];
            $m['posts'][0]['paragraphs']=array_map(static fn(string $p):string=>$p===$price?'Цена не согласована':$p,$m['posts'][0]['paragraphs']);
        },
    ];
    foreach($cases as $key=>$mutate) as39Refuses(static fn()=>as39WithCatalogueMutation($mutate,static function():void {}),
        in_array($key,['grouped_quote','pending_price'],true)?'catalogue_source_invalid':$key);
}


function as39LandingRegression(PDO $pdo, ApplePilotTestProvider $provider, AppleService39PilotPublisher $publisher): void
{
    $manifest = AppleService39PilotManifest::installed();
    $revision = new AppleService39LandingRevision($manifest);
    $firstArtwork = $revision->artwork($manifest->posts()[0]['id']);
    as39Expect($firstArtwork['width'] === 1072 && $firstArtwork['height'] === 1467
        && hash_file('sha256', dirname(__DIR__).'/resources/appleservice39/landing-v2/artwork/asset-manifest.json')
            === 'bfc0cf0812cd576dc7d778aeae60b518b900a6762533a60a80c83513c6508027',
        'current catalogue artwork uses its exact admitted manifest and1072x1467 dimensions');
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

/** Synthetic evidence only; restore every source byte even when a negative case fails. */
function as39ReviewedLandingRegression(PDO $pdo, ?callable $afterFirstReview = null): void
{
    $directory = dirname(__DIR__).'/resources/appleservice39/landing-v2/reconciliation';
    $id = AppleService39PilotManifest::installed()->posts()[0]['id'];
    $indexPath = $directory.'/index.json'; $proofPath = $directory.'/'.$id.'.json'; $previewPath = $directory.'/'.$id.'.jpg';
    $savedFiles = [];
    foreach ([$indexPath,$proofPath,$previewPath] as $path) $savedFiles[$path] = is_file($path) ? file_get_contents($path) : null;
    $index = json_decode((string)$savedFiles[$indexPath], true, 16, JSON_THROW_ON_ERROR); $index['approvals'] = [];
    $json = static fn(array $value): string => json_encode($value, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
    try {
        file_put_contents($indexPath, $json($index));
        ProjectMaxConnection::resetForTests(as39Registry()); $provider = new ApplePilotTestProvider;
        $provider->zeroUploadKey = true; $provider->preserveLandingTokens = false;
        $publisher = as39Publisher($pdo, $provider);
        foreach (['prepare','publish','finalize','pin','landing_prepare'] as $phase) $publisher->run($phase);
        $store = new AppleService39PilotPublicationStore($pdo);
        $writes = $provider->writes(); as39Refuses(static fn()=>$publisher->run('landing_apply'), 'landing_edit_held');
        $row = $store->operation($id, 'landing_edit'); $upload = $store->operation($id, 'landing_upload');
        $before = $store->rows(); $inspection = $publisher->run('landing_inspect');
        $observation = $inspection['landing_readback'][0]['held_image_observation'];
        as39Expect($observation['observation_only'] && $observation['native_identity_matches'] && $observation['native_url_matches']
            && $observation['edit_operation_id'] === (int)$row['id'] && $observation['upload_operation_id'] === (int)$upload['id']
            && $store->rows() === $before && $provider->writes() === $writes+1,
            'held native inspection exposes bound evidence without accepting it or changing a claim');
        $safe = json_encode($inspection, JSON_THROW_ON_ERROR);
        as39Expect(!str_contains($safe, 'photo_25') && !str_contains($safe, 'renewed_25')
            && !str_contains($safe, 'i.oneme.ru/synthetic-image'), 'held observations disclose hashes, never image URLs or tokens');
        // This old JPEG is deliberately a synthetic fixture, not a production approval of new artwork.
        $preview = file_get_contents(dirname(__DIR__).'/resources/appleservice39/pilot/reconciliation/first-create-preview.jpg');
        file_put_contents($previewPath, $preview); $size = getimagesize($previewPath);
        $proof = ['schema_version'=>1,'kind'=>'reviewed_native_replacement','campaign_key'=>AppleService39PilotManifest::CAMPAIGN,
            'project_key'=>AppleService39PilotManifest::PROJECT,'bot_key'=>AppleService39PilotManifest::BOT,'channel_id'=>$provider->channelId,
            'post_id'=>$id,'message_id'=>$row['external_message_id'],'public_url'=>$row['public_url'],
            'revision_sha256'=>$row['request']['revision_sha256'],'source_sha256'=>$row['request']['source_sha256'],
            'upload_operation_id'=>(int)$upload['id'],'upload_request_sha256'=>$upload['payload_sha256'],
            'upload_token_sha256'=>$row['request']['upload_token_sha256'],'edit_operation_id'=>(int)$row['id'],
            'edit_request_sha256'=>$row['payload_sha256'],'edit_started_ms'=>(int)$row['started_ms'],
            'native_photo_id'=>$observation['native_photo_id'],'native_preview_sha256'=>hash('sha256',$preview),
            'native_preview_width'=>$size[0],'native_preview_height'=>$size[1],
            'native_payload_url_sha256'=>$observation['native_payload_url_sha256'],
            'native_readback_run_id'=>101,'native_readback_job_id'=>102,
            'visual_review'=>['source_asset_opened'=>true,'native_preview_opened'=>true,'exact_layout_model_service_price_qualifiers'=>true]];
        $register = static function (array $value) use ($index,$indexPath,$proofPath,$json,$id,$preview): void {
            $raw = $json($value); file_put_contents($proofPath, $raw);
            $approved = $index; $approved['approvals'] = [['post_id'=>$id,'proof_sha256'=>hash('sha256',$raw),
                'native_preview_sha256'=>hash('sha256',$preview)]];
            file_put_contents($indexPath, $json($approved));
        };
        if ($afterFirstReview !== null) {
            $heldBefore = $store->rows(); $writesBefore = $provider->writes();
            as39Refuses(static fn()=>$publisher->run('landing_apply'), 'landing_edit_held');
            as39Expect($store->rows() === $heldBefore && $provider->writes() === $writesBefore,
                'an unreviewed original first UNKNOWN cannot unlock further repair edits');
            $register($proof);
            as39Refuses(static fn()=>$publisher->run('landing_apply'), 'landing_edit_held');
            as39Expect($store->rows() === $heldBefore && $provider->writes() === $writesBefore,
                'registered firstproof without actual successful GET reconciliation does not unlock batching');
            $anchor = $publisher->run('landing_reconcile');
            as39ProviderAssert($anchor['ok'] && $anchor['landing_verified_posts'] === 1,
                'batch fixture starts from an individually reviewed GET-reconciled first edit');
            $afterFirstReview($pdo,$provider,$publisher,$store,$proof);
            return;
        }
        file_put_contents($proofPath, $json($proof));
        $result = $publisher->run('landing_reconcile');
        as39Expect(!$result['ok'] && !isset($store->operation($id,'landing_edit')['outcome']['image_receipt']),
            'a plausible but unregistered review JSON/JPEG grants no image evidence');
        foreach (['message_id'=>'other_mid','public_url'=>'https://max.ru/channel_appleservice39ru/other',
            'source_sha256'=>str_repeat('f',64),'revision_sha256'=>str_repeat('f',64),
            'upload_operation_id'=>99999,'upload_request_sha256'=>str_repeat('f',64),'upload_token_sha256'=>str_repeat('f',64),
            'edit_operation_id'=>99999,'edit_request_sha256'=>str_repeat('f',64),'edit_started_ms'=>1,
            'native_photo_id'=>'99999','visual_review'=>['source_asset_opened'=>true,'native_preview_opened'=>false,'exact_layout_model_service_price_qualifiers'=>true]] as $key=>$bad) {
            $changed = $proof; $changed[$key] = $bad; $register($changed);
            $result = $publisher->run('landing_reconcile');
            as39Expect(!$result['ok'] && $store->operation($id,'landing_edit')['state'] === 'unknown'
                && $provider->writes() === $writes+1, 'self-consistent file hashes cannot approve foreign/missing review context: '.$key);
        }
        $register($proof); file_put_contents($previewPath, $preview."changed");
        as39Refuses(static fn()=>$publisher->run('landing_reconcile'), 'landing_review_evidence_changed');
        file_put_contents($previewPath, $preview);
        $native = $provider->messages[$row['external_message_id']];
        $provider->messages[$row['external_message_id']]['body']['text'] .= 'intervening change';
        $held = $publisher->run('landing_reconcile');
        as39Expect(!$held['ok'] && $store->operation($id,'landing_edit')['state'] === 'unknown',
            'admitted image review does not bypass the fresh complete native body guard');
        $provider->messages[$row['external_message_id']] = $native;
        $result = as39Publisher($pdo, $provider)->run('landing_reconcile');
        $receipt = $store->operation($id,'landing_edit')['outcome']['image_receipt'];
        as39Expect($result['ok'] && $result['landing_verified_new_images'] === 1 && $provider->writes() === $writes+1
            && $receipt['kind'] === 'reviewed_native_replacement' && $receipt['source_sha256'] === $proof['source_sha256']
            && $receipt['evidence_sha256'] === hash_file('sha256',$proofPath)
            && $receipt['native_preview_sha256'] === hash('sha256',$preview),
            'exact registered source/native file review closes only the original renewed-token edit by GET');
        $provider->messages[$row['external_message_id']]['body']['attachments'][0]['payload'] = [
            'photo_id'=>'99999','token'=>$upload['outcome']['token'],'url'=>'https://i.oneme.ru/synthetic-image/99999.jpg'];
        as39Refuses(static fn()=>$publisher->run('landing_inspect'), 'landing_review_evidence_mismatch');
        $provider->messages[$row['external_message_id']] = $native;
        $provider->preserveLandingTokens = true;
        $finished = $publisher->run('landing_apply');
        as39Expect($finished['ok'] && $finished['landing_verified_posts'] === 24 && $finished['landing_verified_new_images'] === 15
            && $provider->writes() === $writes+24 && (int)$store->operation($id,'landing_edit')['write_attempts'] === 1,
            'after exact review, apply continues only remaining edits and retains all24 URLs/pin');
        $publisher->run('landing_reconcile');
        as39Expect($provider->writes() === $writes+24, 'reviewed native receipts never authorize a repeated upload/edit/pin');
    } finally {
        foreach ($savedFiles as $path=>$bytes) { if ($bytes === null) @unlink($path); else file_put_contents($path, $bytes); }
    }
}

/** Synthetic independent files are restored; this creates no live approvals. */
function as39ImageReviewBatchRegression(): void
{
    as39ReviewedLandingRegression(as39Sqlite(), static function ($pdo,$provider,$publisher,$store,$firstProof): void {
        $before = $store->rows(); $writes = $provider->writes();
        $uploads = $provider->count('POST','/uploads'); $pin = $provider->pinned;
        $first = $store->operation($firstProof['post_id'],'landing_edit');
        $result = $publisher->run('landing_apply');
        $held = array_values(array_filter($store->rows(), static fn($r)=>$r['operation']==='landing_edit' && $r['state']==='unknown'));
        as39Expect(!$result['ok'] && $result['landing_image_review_holds']===14
            && $result['landing_verified_posts']===1 && $result['landing_verified_new_images']===1 && count($held)===14,
            'one reviewed first image permits14 individual UNKNOWN review holds, never24/24 acceptance');
        as39Expect($provider->writes()===$writes+14 && $provider->count('POST','/uploads')===$uploads
            && $provider->pinned===$pin && $store->operation($firstProof['post_id'],'landing_edit')===$first,
            'finite preparation sends14ownPUTs once, reuses15uploads and preserves original first proof/pin');
        foreach ($held as $row) as39Expect((int)$row['write_attempts']===1 && !isset($row['outcome']['image_receipt'])
            && $row['outcome']['image_review_observation']['source_identity_verified']===false
            && $row['outcome']['image_review_observation']['edit_request_sha256']===$row['payload_sha256'],
            'each new image is an immutable individual review hold, not ACK/new-ID/source identity');
        $navigation = array_filter(AppleService39PilotManifest::installed()->posts(), static fn($p)=>!str_starts_with($p['id'],'repair-'));
        foreach ($navigation as $post) as39Expect($store->operation($post['id'],'landing_edit')===null,
            'navigation remains unstarted while images await actual source review');
        $frozen = $store->rows(); $again = $publisher->run('landing_apply');
        as39Expect(!$again['ok'] && $store->rows()===$frozen && $provider->writes()===$writes+14,
            'repeated apply never rewrites/replays an existing image-review UNKNOWN');
        $one=$held[0];$mid=$one['external_message_id'];$originalImage=$provider->messages[$mid]['body']['attachments'][0]['payload'];
        $provider->messages[$mid]['body']['attachments'][0]['payload']['token']=$store->operation($one['post_key'],'landing_upload')['outcome']['token'];
        $tokenOnly=$publisher->run('landing_reconcile');
        as39Expect(!$tokenOnly['ok'] && $store->rows()===$frozen && $provider->writes()===$writes+14,
            'a later token coincidence cannot replace an individually required registered review');
        $provider->messages[$mid]['body']['attachments'][0]['payload']=$originalImage;
        $provider->messages[$mid]['body']['attachments'][0]['payload']['photo_id']='999999';
        as39Refuses(static fn()=>$publisher->run('landing_apply'),'landing_edit_held');
        as39Expect($store->rows()===$frozen && $provider->writes()===$writes+14,
            'a changed imageID on an existing pending attempt stops instead of adopting a fresh arbitrary GET');
        $provider->messages[$mid]['body']['attachments'][0]['payload']=$originalImage;
        $unapproved = $publisher->run('landing_reconcile');
        as39Expect(!$unapproved['ok'] && $unapproved['landing_image_review_holds']===14
            && $store->rows()===$frozen && $provider->writes()===$writes+14,
            'GET/new photoIDs without individual source proofs leave every original hold intact');
        $inspection = $publisher->run('landing_inspect'); $safe = json_encode($inspection,JSON_THROW_ON_ERROR);
        as39Expect($inspection['landing_image_review_holds']===14 && $store->rows()===$frozen
            && !str_contains($safe,'renewed_26') && !str_contains($safe,'i.oneme.ru/synthetic-image'),
            'one GET-only inspection discloses individual original/fresh hashes without tokens or media URLs');

        // These are explicitly synthetic approvals to exercise the unchanged
        // source-index path, not a claim that real remaining images were reviewed.
        $directory=dirname(__DIR__).'/resources/appleservice39/landing-v2/reconciliation';
        $indexPath=$directory.'/index.json'; $index=json_decode((string)file_get_contents($indexPath),true);
        $jpeg=file_get_contents(dirname(__DIR__).'/resources/appleservice39/pilot/reconciliation/first-create-preview.jpg');
        $size=getimagesizefromstring($jpeg); $restore=[];
        try {
            foreach ($held as $row) {
                $post=$row['post_key']; $observation=$row['outcome']['image_review_observation'];
                $proof=array_replace($firstProof,['post_id'=>$post,'message_id'=>$row['external_message_id'],
                    'public_url'=>$row['public_url'],'revision_sha256'=>$row['request']['revision_sha256'],
                    'source_sha256'=>$row['request']['source_sha256'],
                    'upload_operation_id'=>$observation['upload_operation_id'],
                    'upload_request_sha256'=>$observation['upload_request_sha256'],
                    'upload_token_sha256'=>$observation['upload_token_sha256'],
                    'edit_operation_id'=>(int)$row['id'],'edit_request_sha256'=>$row['payload_sha256'],
                    'edit_started_ms'=>(int)$row['started_ms'],'native_photo_id'=>$observation['native_photo_id'],
                    'native_payload_url_sha256'=>$observation['native_payload_url_sha256'],
                    'native_preview_sha256'=>hash('sha256',$jpeg),'native_preview_width'=>$size[0],'native_preview_height'=>$size[1]]);
                $raw=json_encode($proof,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
                foreach ([$directory.'/'.$post.'.json'=>$raw,$directory.'/'.$post.'.jpg'=>$jpeg] as $path=>$bytes) {
                    $restore[$path]=is_file($path)?file_get_contents($path):null; file_put_contents($path,$bytes);
                }
                $index['approvals'][]=['post_id'=>$post,'proof_sha256'=>hash('sha256',$raw),'native_preview_sha256'=>hash('sha256',$jpeg)];
            }
            file_put_contents($indexPath,json_encode($index,JSON_THROW_ON_ERROR));
            $reconciled=$publisher->run('landing_reconcile');
            as39Expect($reconciled['ok'] && $reconciled['landing_verified_new_images']===15
                && $reconciled['landing_verified_posts']===15 && $provider->writes()===$writes+14,
                'only individual registered proofs reconcile the SAME14original attempts by GET');
            foreach ($held as $original) {
                $now=$store->operation($original['post_key'],'landing_edit');
                as39Expect($now['state']==='verified' && $now['request_json']===$original['request_json']
                    && $now['payload_sha256']===$original['payload_sha256'] && $now['started_ms']===$original['started_ms']
                    && $now['write_attempts']===$original['write_attempts']
                    && $now['outcome']['image_review_observation']===$original['outcome']['image_review_observation'],
                    'native proof completion preserves original payload/attempt/start and first image observation');
            }
            $done=$publisher->run('landing_apply');
            as39Expect($done['ok'] && $done['landing_verified_posts']===24 && $done['landing_verified_new_images']===15
                && $provider->writes()===$writes+23 && $provider->count('POST','/uploads')===$uploads
                && $provider->pinned===$pin, 'after all15reviews apply edits only9unsent navigation pages');
            $publisher->run('landing_apply'); $publisher->run('landing_reconcile');
            as39Expect($provider->writes()===$writes+23,'completed finite preparation/recovery never repeats a mutation');
        } finally {
            foreach ($restore as $path=>$bytes) { if ($bytes===null) @unlink($path); else file_put_contents($path,$bytes); }
        }
    });

    foreach (['body','markup','button','url','channel','mid','old_image','missing_token','missing_locator','invalid_image_id','zero_image_id',
        'known_foreign_image','pin','authority','unknown_ack'] as $failure) {
        as39ReviewedLandingRegression(as39Sqlite(), static function ($pdo,$provider,$publisher,$store,$firstProof) use ($failure): void {
            $writes=$provider->writes(); $beforeFirst=$store->operation($firstProof['post_id'],'landing_edit');
            $provider->editHook=static function (string $mid) use ($provider,$pdo,$failure):void {
                if ($failure==='body') $provider->messages[$mid]['body']['text'].=' foreign';
                if ($failure==='markup') $provider->messages[$mid]['body']['markup'][1]['url']='https://max.ru/channel_appleservice39ru/foreign';
                if ($failure==='button') $provider->messages[$mid]['body']['attachments'][1]['payload']['buttons'][0][0]['url']='https://max.ru/foreign_bot';
                if ($failure==='url') $provider->messages[$mid]['url']='https://max.ru/channel_appleservice39ru/foreign';
                if ($failure==='channel') $provider->messages[$mid]['recipient']['chat_id']=-9999;
                if ($failure==='mid') $provider->messages[$mid]['body']['mid']='foreign_mid';
                if ($failure==='old_image') $provider->messages[$mid]['body']['attachments'][0]['payload']['photo_id']='2';
                if ($failure==='missing_token') unset($provider->messages[$mid]['body']['attachments'][0]['payload']['token']);
                if ($failure==='missing_locator') unset($provider->messages[$mid]['body']['attachments'][0]['payload']['url']);
                if ($failure==='invalid_image_id') $provider->messages[$mid]['body']['attachments'][0]['payload']['photo_id']='not-an-id';
                if ($failure==='zero_image_id') $provider->messages[$mid]['body']['attachments'][0]['payload']['photo_id']='0';
                if ($failure==='known_foreign_image') $provider->messages[$mid]['body']['attachments'][0]['payload']['photo_id']='25';
                if ($failure==='pin') $provider->pinned=null;
                if ($failure==='authority') $pdo->exec('DELETE FROM manager_projects WHERE manager_id=2');
                if ($failure==='unknown_ack') $provider->mode='edit_timeout';
            };
            as39Refuses(static fn()=>$publisher->run('landing_apply'),'landing_edit_held');
            $edits=array_values(array_filter($store->rows(),static fn($r)=>$r['operation']==='landing_edit' && $r['post_key']!==$firstProof['post_id']));
            as39Expect($provider->writes()===$writes+1 && count($edits)===1 && $edits[0]['state']==='unknown'
                && (int)$edits[0]['write_attempts']===1 && !isset($edits[0]['outcome']['image_review_observation'])
                && !isset($edits[0]['outcome']['image_receipt'])
                && $store->operation($firstProof['post_id'],'landing_edit')===$beforeFirst,
                'any non-image-evidence ambiguity STOPS without continuing or approving: '.$failure);
        });
    }

    as39ReviewedLandingRegression(as39Sqlite(), static function ($pdo,$provider,$publisher,$store):void {
        $targets=(new ReflectionMethod(AppleService39PilotPublisher::class,'landingTargets'))
            ->invoke($publisher,new AppleService39LandingRevision(AppleService39PilotManifest::installed()));
        $target=array_values($targets)[1]; $row=$store->prepare($target['post']['id'],'landing_edit',$target['request']);
        $store->claim($row);$store->rememberMessage($row,$target['mid'],$target['url']);
        $store->finish($row,'unknown',['reason'=>'historical_unclassified_unknown']);
        $frozen=$store->rows();$writes=$provider->writes();
        as39Refuses(static fn()=>$publisher->run('landing_apply'),'landing_edit_held');
        as39Expect($store->rows()===$frozen && $provider->writes()===$writes,
            'an older unclassified UNKNOWN is never upgraded/relabelled/replayed for batching');
    });
    as39ReviewedLandingRegression(as39Sqlite(), static function ($pdo,$provider,$publisher,$store):void {
        $pdo->exec("UPDATE appleservice39_pilot_operations SET state='unknown' WHERE operation='landing_upload' AND post_key='repair-iphone-x-front-glass-replacement'");
        $frozen=$store->rows();$writes=$provider->writes();
        as39Refuses(static fn()=>$publisher->run('landing_apply'),'landing_edit_held');
        as39Expect($store->rows()===$frozen && $provider->writes()===$writes,'a lost upload cannot become a batch review hold or authorize another write');
    });
    as39ReviewedLandingRegression(as39Sqlite(), static function ($pdo,$provider,$publisher,$store):void {
        $path=dirname(__DIR__).'/resources/appleservice39/landing-v2/reconciliation/index.json';
        $index=json_decode(file_get_contents($path),true);$index['approvals']=[];file_put_contents($path,json_encode($index));
        $frozen=$store->rows();$writes=$provider->writes();
        as39Refuses(static fn()=>$publisher->run('landing_apply'),'landing_native_image_evidence_required');
        as39Expect($store->rows()===$frozen && $provider->writes()===$writes,'a previously verified row without its admitted firstreview cannot unlock batching');
    });
    as39ReviewedLandingRegression(as39Sqlite(), static function ($pdo,$provider,$publisher,$store):void {
        $post=AppleService39PilotManifest::installed()->posts()[1]['id'];$mid=$store->operation($post,'create')['external_message_id'];
        $writes=$provider->writes();
        $provider->membershipHook=static function () use ($provider,$store,$post,$mid):void {
            $pending=$store->operation($post,'landing_edit');
            if ($pending!==null && $pending['state']==='unknown') {
                $provider->messages[$mid]['body']['markup'][1]['url']='https://max.ru/channel_appleservice39ru/intervening';
            }
        };
        as39Refuses(static fn()=>$publisher->run('landing_apply'),'post_readback_mismatch');
        $edits=array_values(array_filter($store->rows(),static fn($r)=>$r['operation']==='landing_edit' && $r['post_key']!==AppleService39PilotManifest::installed()->posts()[0]['id']));
        as39Expect($provider->writes()===$writes+1 && count($edits)===2 && $edits[0]['state']==='unknown'
            && $edits[1]['state']==='prepared' && (int)$edits[1]['write_attempts']===0,
            'an intervening failed guard on an earlier review hold stops before claiming the next edit');
    });
    as39ReviewedLandingRegression(as39Sqlite(), static function ($pdo,$provider,$publisher,$store):void {
        $post=AppleService39PilotManifest::installed()->posts()[1]['id'];$mid=$store->operation($post,'create')['external_message_id'];
        $provider->editHook=static function (string $edited) use ($provider,$mid):void {
            if ($edited===$mid) $provider->messages[$mid]['body']['attachments'][0]['payload']['photo_id']='999999';
        };
        $writes=$provider->writes();$result=$publisher->run('landing_apply');$row=$store->operation($post,'landing_edit');
        as39Expect(!$result['ok'] && $result['landing_verified_new_images']===1 && $row['state']==='unknown'
            && !isset($row['outcome']['image_receipt']) && $provider->writes()===$writes+14,
            'even a valid fresh arbitrary imageID is only an individual UNKNOWN, never verified identity');
    });
}

function as39CatalogueArtworkRegression(): void
{
    $root = dirname(__DIR__).'/resources/appleservice39/landing-v2';
    $temporary = sys_get_temp_dir().'/as39-catalog-'.bin2hex(random_bytes(8));
    mkdir($temporary.'/artwork', 0700, true);
    try {
        $manifest = json_decode((string)file_get_contents($root.'/manifest.json'), true, 32, JSON_THROW_ON_ERROR);
        $assets = json_decode((string)file_get_contents($root.'/artwork/asset-manifest.json'), true, 32, JSON_THROW_ON_ERROR);
        $assets['posts'][0]['width'] = 1122; $assets['posts'][0]['height'] = 1402;
        $raw = json_encode($assets, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        file_put_contents($temporary.'/artwork/asset-manifest.json', $raw);
        $manifest['artwork_manifest_sha256'] = hash('sha256', $raw);
        file_put_contents($temporary.'/manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        as39Refuses(static fn()=>new AppleService39LandingRevision(AppleService39PilotManifest::installed(), $temporary.'/manifest.json'),
            'landing_artwork_manifest_changed');
    } finally {
        @unlink($temporary.'/artwork/asset-manifest.json'); @unlink($temporary.'/manifest.json');
        @rmdir($temporary.'/artwork'); @rmdir($temporary);
    }
    foreach (['landing_upload','landing_edit'] as $operation) foreach (['prepared','unknown'] as $state) {
        ProjectMaxConnection::resetForTests(as39Registry()); $pdo = as39Sqlite(); $provider = new ApplePilotTestProvider;
        $publisher = as39Publisher($pdo,$provider);
        foreach (['prepare','publish','finalize','pin'] as $phase) $publisher->run($phase);
        $store = new AppleService39PilotPublicationStore($pdo); $original = AppleService39PilotManifest::installed();
        $id = $original->posts()[0]['id']; $created = $store->operation($id,'create');
        $request = ['revision'=>'landing-v2','revision_sha256'=>'5a763c91f6953ddc0a4248e9c512b2475de73c6ab322e8b78a9e0862c9ffce73',
            'mid'=>$created['external_message_id'],'url'=>$created['public_url'],
            'source_sha256'=>'40c306b32f9ac66464b10b8cb052defcfe16895ca3219dd80a2e835608f2ebe8'];
        if ($operation === 'landing_edit') {
            $revision = new AppleService39LandingRevision($original); $urls = [];
            foreach ($original->posts() as $post) $urls[$post['id']] = $store->operation($post['id'],'create')['public_url'];
            $plan = $revision->plan($revision->posts()[0], ['token'=>'retired-artwork-upload','photo_id'=>'0'], $urls);
            $request = ['revision'=>'landing-v2','revision_sha256'=>$request['revision_sha256'],
                'mid'=>$created['external_message_id'],'url'=>$created['public_url'],
                'prior_body_sha256'=>hash('sha256', json_encode($store->operation($id,'links')['request']['body'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),
                'body'=>$plan['body'],'native_text'=>$plan['native_text'],'native_markup'=>$plan['native_markup'],
                'source_sha256'=>$request['source_sha256'],'upload_token_sha256'=>hash('sha256','retired-artwork-upload')];
        }
        $old = $store->prepare($id,$operation,$request);
        if ($state === 'unknown') {
            as39Expect($store->claim($old), 'old-revision fixture owns its sole attempt');
            if ($operation === 'landing_edit') $store->rememberMessage($old,$created['external_message_id'],$created['public_url']);
            $store->finish($old,'unknown',['reason'=>'retired_revision_fixture']);
        }
        $before = $store->rows(); $writes = $provider->writes();
        as39Refuses(static fn()=>$publisher->run('landing_prepare'),'landing_revision_changed');
        as39Expect($store->rows() === $before && $provider->writes() === $writes,
            'catalogue replacement cannot rewrite or replay an existing '.$state.' '.$operation);
    }
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
    as39ReviewedLandingRegression(as39Sqlite());
    as39CatalogueArtworkRegression();
    as39LandingImageSafetyRegression();
    as39ImageReviewBatchRegression();
    as39CatalogueSourceRegression();
    as39GroupedCatalogueRegression();

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
