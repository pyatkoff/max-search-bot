<?php
declare(strict_types=1);

if (!defined('APPLESERVICE39_PUBLISHER_FIXTURES_ONLY')) define('APPLESERVICE39_PUBLISHER_FIXTURES_ONLY', true);
require_once __DIR__.'/run_appleservice39_publisher_regression.php';

/** Preserve the fixed historical identities while exercising real predecessor transitions. */
function as39SearchWire(ApplePilotTestProvider $provider, bool $predecessor = false): callable
{
    $revision = new AppleService39SearchIntentRevision(AppleService39PilotManifest::installed());
    $bindings = []; $byMid = [];
    foreach (AppleService39PilotManifest::installed()->posts() as $post) {
        $binding = $revision->historicalBinding($post['id']);
        $bindings[$post['id']] = $binding; $byMid[$binding['message_id']] = $post['id'];
    }
    return static function (array $request) use ($provider,$predecessor,$bindings,$byMid): array {
        $path = parse_url($request['url'], PHP_URL_PATH);
        parse_str((string)parse_url($request['url'], PHP_URL_QUERY), $query);
        $id = $request['method'] === 'PUT' ? ($byMid[$query['message_id'] ?? ''] ?? null) : null;
        if ($request['method'] === 'POST' && $path === '/messages') {
            foreach ($request['body']['attachments'] as $attachment) if ($attachment['type'] === 'inline_keyboard') {
                $url = $attachment['payload']['buttons'][0][0]['url'];
                if (preg_match('/[?&]start=as39_([a-z0-9_-]+)\z/D',$url,$match)) $id = $match[1];
            }
            // The original navigation CTA was generic; its fixed creation order identifies that source post.
            if ($predecessor) $id = array_keys($bindings)[count($provider->messages)];
        }
        if ($path === '/messages' && isset($bindings[$id ?? ''])) {
            $b = $bindings[$id]; $provider->fixedUrl = $b['url'];
            if ($request['method'] === 'POST') $provider->fixedMid = $b['message_id'];
            if ($predecessor) $provider->fixedPhotoId = ($request['body']['format'] ?? null) === 'html'
                || !str_starts_with($id,'repair-') ? $b['native_photo_id'] : (string)(8000000+array_search($id,array_keys($bindings),true));
        }
        try { return $provider($request); }
        finally { $provider->fixedMid = null; $provider->fixedPhotoId = null; $provider->fixedUrl = null; }
    };
}

function as39SearchPublisher(PDO $pdo, ApplePilotTestProvider $provider, ?callable $wire = null): AppleService39PilotPublisher
{
    $manifest = AppleService39PilotManifest::installed();
    return new AppleService39PilotPublisher($pdo,$manifest,$provider->channelId,static fn():bool=>true,
        $wire ?? as39SearchWire($provider),static function():void {},new AppleService39CatalogueRevision($manifest),
        new AppleService39SearchIntentRevision($manifest));
}

function as39SearchSeed(?PDO $pdo = null): array
{
    ProjectMaxConnection::resetForTests(as39Registry());
    $pdo ??= as39Sqlite(); $provider = new ApplePilotTestProvider; $provider->channelId = '-80917954947727';
    $manifest = AppleService39PilotManifest::installed(); $wire = as39SearchWire($provider,true);
    $publisher = new AppleService39PilotPublisher($pdo,$manifest,$provider->channelId,static fn():bool=>true,$wire,static function():void {});
    foreach (['prepare','publish','finalize','pin','landing_prepare','landing_apply'] as $mode) $publisher->run($mode);
    $catalogue = new AppleService39PilotPublisher($pdo,$manifest,$provider->channelId,static fn():bool=>true,$wire,
        static function():void {},new AppleService39CatalogueRevision($manifest));
    $catalogue->run('landing_apply');
    $store = new AppleService39PilotPublicationStore($pdo);
    as39Expect(count($store->rows()) === 136 && $catalogue->run('landing_inspect')['catalogue_verified_posts'] === 24,
        'search predecessor is the complete136-operation publisher result with fixed historical identities');
    return [$pdo,$provider,$store];
}

/** Reuse the same accepted synthetic predecessor; never fabricate successor receipts. */
function as39SearchClone(array $seed): array
{
    [$original,$provider] = $seed; $pdo = as39Sqlite();
    foreach (['appleservice39_pilot_campaigns','appleservice39_pilot_operations'] as $table) {
        foreach ($original->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $query = $pdo->prepare('INSERT INTO '.$table.' ('.implode(',',array_keys($row)).') VALUES ('.implode(',',array_fill(0,count($row),'?')).')');
            $query->execute(array_values($row));
        }
    }
    $provider = clone $provider; $provider->requests = [];
    return [$pdo,$provider,new AppleService39PilotPublicationStore($pdo)];
}

function as39SearchSuccess(array $fixture): void
{
    [$pdo,$provider,$store] = $fixture; $old = $store->rows(); $writes = $provider->writes();
    $creates = $provider->count('POST','/messages'); $edits = $provider->count('PUT','/messages');
    $uploads = $provider->count('POST','/uploads'); $pins = $provider->count('PUT','/chats/'.$provider->channelId.'/pin');
    $publisher = as39SearchPublisher($pdo,$provider);
    $initial = $publisher->run('search_inspect');
    as39Expect($initial['search_verified_posts'] === 0 && !$initial['search_publication_complete']
        && $store->rows() === $old && $provider->writes() === $writes, 'inspection is read-only and does not invent publication evidence');
    $provider->zeroUploadKey = true;
    $publisher->run('search_prepare'); $publisher->run('search_prepare');
    as39Expect($provider->count('POST','/uploads') === $uploads+8 && $provider->count('POST','/messages') === $creates,
        'eight source-bound uploads accept a zero map index and never repeat or create a message');
    $report = $publisher->run('search_apply');
    as39Expect($report['ok'] && $report['search_publication_complete'] && $report['search_verified_posts'] === 8
        && $report['post_count'] === 30 && $report['search_expected_navigation_links'] === 99
        && $report['search_menu_pin_preserved'], 'six final creates and two original-MID updates complete the30-post graph');
    as39Expect($provider->count('POST','/messages') === $creates+6 && $provider->count('PUT','/messages') === $edits+2
        && $provider->count('PUT','/chats/'.$provider->channelId.'/pin') === $pins, 'no temporary posts, legacy edits or new pin writes');
    as39Expect(array_slice($store->rows(),0,136) === $old && count($store->rows()) === 152,
        'all136 original rows, exact payloads, receipts, identifiers and timestamps remain immutable');
    $links = 0; $buttons = 0;
    foreach ($provider->messages as $message) {
        foreach ($message['body']['markup'] ?? [] as $span) if ($span['type'] === 'link') $links++;
        foreach ($message['body']['attachments'] as $attachment) if ($attachment['type'] === 'inline_keyboard') {
            as39Expect(count($attachment['payload']['buttons']) === 1 && count($attachment['payload']['buttons'][0]) === 1,
                'every final native post has one bot button');
            $buttons++;
        }
    }
    as39Expect($links === 99 && $buttons === 30, 'actual complete synthetic native graph has99 text links and30 single CTAs');
    $post = 'repair-iphone-16-display';
    as39Expect($store->verifiedPostUrl($post,10) === $store->operation($post,'create')['public_url']
        && $store->verifiedPostUrl($post,20) === null, 'new links use the existing project/source-bound verified create receipt');
    $all = $store->rows(); $writes = $provider->writes();
    foreach (['search_apply','search_prepare','search_reconcile','search_inspect'] as $mode) {
        as39Expect($publisher->run($mode)['search_publication_complete'], 'completed '.$mode.' freshly validates the same final result');
    }
    as39Expect($provider->writes() === $writes && $store->rows() === $all, 'completed execution and GET recovery cannot mutate or replay an accepted operation');
}

/** Synthetic source-review fixture only; restore every file, never ship an approval. */
function as39WithSearchImageProof(array $row, ApplePilotTestProvider $provider, ?callable $mutate, callable $check): void
{
    $directory = dirname(__DIR__).'/resources/appleservice39/search-intent-v1/reconciliation';
    $paths = [$directory.'/index.json',$directory.'/'.$row['post_key'].'.json',$directory.'/'.$row['post_key'].'.jpg'];
    $old = []; foreach ($paths as $path) $old[$path] = is_file($path) ? file_get_contents($path) : null;
    try {
        $jpeg = file_get_contents(dirname(__DIR__).'/resources/appleservice39/pilot/reconciliation/first-create-preview.jpg');
        file_put_contents($paths[2],$jpeg); $size = getimagesize($paths[2]);
        $message = $provider->messages[$row['external_message_id']]; $image = $message['body']['attachments'][0]['payload'];
        $proof = ['schema_version'=>1,'kind'=>'reviewed_native_replacement',
            'campaign_key'=>AppleService39PilotManifest::CAMPAIGN,'project_key'=>AppleService39PilotManifest::PROJECT,
            'bot_key'=>AppleService39PilotManifest::BOT,'channel_id'=>$provider->channelId,'post_id'=>$row['post_key'],
            'message_id'=>$row['external_message_id'],'public_url'=>$row['public_url'],
            'revision_sha256'=>$row['request']['revision_sha256'],'source_sha256'=>$row['request']['source_sha256'],
            'upload_operation_id'=>$row['request']['upload_operation_id'],
            'upload_request_sha256'=>$row['request']['upload_request_sha256'],
            'upload_token_sha256'=>$row['request']['upload_token_sha256'],
            'edit_operation_id'=>(int)$row['id'],'edit_request_sha256'=>$row['payload_sha256'],
            'edit_started_ms'=>(int)$row['started_ms'],'native_photo_id'=>(string)$image['photo_id'],
            'native_preview_sha256'=>hash('sha256',$jpeg),'native_preview_width'=>$size[0],'native_preview_height'=>$size[1],
            'native_payload_url_sha256'=>hash('sha256',$image['url']),'native_readback_run_id'=>1,'native_readback_job_id'=>2,
            'visual_review'=>['source_asset_opened'=>true,'native_preview_opened'=>true,'exact_layout_model_service_price_qualifiers'=>true]];
        if ($mutate !== null) $mutate($proof);
        $json = json_encode($proof,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        file_put_contents($paths[1],$json);
        file_put_contents($paths[0],json_encode(['schema_version'=>1,'revision_key'=>AppleService39SearchIntentRevision::KEY,
            'project_key'=>AppleService39PilotManifest::PROJECT,'policy'=>'exact_source_and_native_visual_review',
            'approvals'=>[['post_id'=>$row['post_key'],'proof_sha256'=>hash('sha256',$json),
                'native_preview_sha256'=>hash('sha256',$jpeg)]]],JSON_THROW_ON_ERROR));
        $check();
    } finally { foreach ($old as $path=>$bytes) { if ($bytes === null) @unlink($path); else file_put_contents($path,$bytes); } }
}

function as39SearchRegression(?PDO $native = null): void
{
    $seed = as39SearchSeed($native); as39SearchSuccess($native === null ? as39SearchClone($seed) : $seed);
    if ($native !== null) return;
    foreach (['upload_lost','create_lost','create_get_lost','edit_lost','edit_image_unproven'] as $fault) {
        [$pdo,$provider,$store] = as39SearchClone($seed); $wire = as39SearchWire($provider); $once = false;
        $faultWire = static function(array $request) use ($wire,$provider,$fault,&$once):array {
            $response = $wire($request); $path = parse_url($request['url'],PHP_URL_PATH);
            $fail = !$once && ($fault === 'upload_lost' && $request['file'] !== null
                || $fault === 'create_get_lost' && $request['method'] === 'GET' && str_starts_with($path,'/messages/mid_25'));
            if ($fail) { $once = true; return ['http'=>0,'transport_error'=>true,'data'=>null]; }
            return $response;
        };
        $publisher = as39SearchPublisher($pdo,$provider,$faultWire);
        if ($fault === 'upload_lost') as39Refuses(static fn()=>$publisher->run('search_prepare'),'search_operation_held');
        else {
            $publisher->run('search_prepare');
            if ($fault === 'create_lost') $provider->mode = 'create_timeout';
            if ($fault === 'edit_lost') $provider->mode = 'edit_timeout';
            if ($fault === 'edit_image_unproven') $provider->preserveLandingTokens = false;
            as39Refuses(static fn()=>$publisher->run('search_apply'),'search_operation_held');
        }
        $held = array_values(array_filter($store->rows(),static fn(array $r):bool=>$r['state'] === 'unknown'));
        as39Expect(count($held) === 1 && (int)$held[0]['write_attempts'] === 1, $fault.' retains exactly one original attempted hold');
        $row = $held[0]; $writes = $provider->writes(); $before = $store->rows();
        if ($fault === 'create_get_lost') {
            as39Refuses(static fn()=>$store->finish($row,'verified',[]),'search_image_receipt_required');
            $wrongReceipt = $row['outcome']['image_receipt']; $wrongReceipt['source_sha256'] = str_repeat('e',64);
            as39Refuses(static fn()=>$store->rememberSearchImageReceipt($row,$wrongReceipt),'invalid_search_image_receipt');
        }
        $publisher->run('search_inspect');
        as39Expect($store->rows() === $before && $provider->writes() === $writes, $fault.' inspection cannot accept or replay the hold');
        $provider->mode = ''; $recovered = $publisher->run('search_reconcile');
        as39Expect($provider->writes() === $writes, $fault.' reconciliation issues provider GET only');
        $fresh = $store->operation($row['post_key'],$row['operation']);
        as39Expect($fresh['id'] === $row['id'] && $fresh['request_json'] === $row['request_json']
            && $fresh['payload_sha256'] === $row['payload_sha256'] && $fresh['started_ms'] === $row['started_ms']
            && (int)$fresh['write_attempts'] === 1, $fault.' recovery retains original request, attempt and start');
        if (in_array($fault,['create_get_lost','edit_lost'],true)) {
            as39Expect($fresh['state'] === 'verified' && $recovered['ok'], $fault.' exact native/source evidence closes its own hold');
            as39Expect($publisher->run('search_apply')['search_publication_complete'], $fault.' resumes only the remaining unattempted posts');
            as39Expect($provider->count('POST','/messages') === 6 && $provider->count('PUT','/messages') === 2
                && $provider->count('POST','/uploads') === 8, $fault.' never duplicates any upload, create or edit');
        } else {
            as39Expect($fresh['state'] === 'unknown' && !$recovered['ok'], $fault.' missing original evidence stays held');
            as39Refuses(static fn()=>$publisher->run($fault === 'upload_lost' ? 'search_prepare' : 'search_apply'),'search_operation_held');
            as39Expect($provider->writes() === $writes, $fault.' repeated execution does not retry an uncertain write');
            if ($fault === 'edit_image_unproven') {
                foreach (['source_sha256','message_id','edit_request_sha256','upload_operation_id','edit_started_ms',
                    'native_photo_id','channel_id','native_readback_run_id'] as $field) {
                    as39WithSearchImageProof($fresh,$provider,static function(array &$proof) use ($field):void {
                        $proof[$field] = is_int($proof[$field]) ? $proof[$field]+1 : 'foreign-value';
                        if ($field === 'native_readback_run_id') $proof[$field] = 0;
                    },static function() use ($publisher,$store,$fresh,$provider,$writes,$field):void {
                        as39Expect(!$publisher->run('search_reconcile')['ok']
                            && $store->operation($fresh['post_key'],'search_v1_edit')['state'] === 'unknown'
                            && $provider->writes() === $writes, 'reviewed image rejects changed '.$field.' without replay');
                    });
                }
                as39WithSearchImageProof($fresh,$provider,null,static function() use ($publisher,$store,$fresh,$provider,$writes):void {
                    $before = $store->rows(); $publisher->run('search_inspect');
                    as39Expect($store->rows() === $before, 'registered image inspection still cannot accept its own hold');
                    as39Expect($publisher->run('search_reconcile')['ok'] && $provider->writes() === $writes
                        && $store->operation($fresh['post_key'],'search_v1_edit')['outcome']['image_receipt']['kind'] === 'reviewed_native_replacement',
                        'individual registered source/native proof reconciles exactly the original PUT by GET');
                    $provider->preserveLandingTokens = true;
                    as39Expect($publisher->run('search_apply')['search_publication_complete']
                        && $provider->count('POST','/messages') === 6 && $provider->count('PUT','/messages') === 2,
                        'after image acceptance only the remaining menu edit runs');
                });
            }
        }
    }
    foreach (['old_body','pin','authority','old_hold','wrong_channel','upload_source','upload_token','parent_drift_after_read'] as $fault) {
        [$pdo,$provider,$store] = as39SearchClone($seed); $publisher = as39SearchPublisher($pdo,$provider);
        $mode = 'search_prepare'; $reason = 'search_operation_held';
        $revision = new AppleService39SearchIntentRevision(AppleService39PilotManifest::installed());
        if ($fault === 'old_body') {
            $provider->messages[$revision->historicalBinding('model-iphone-x')['message_id']]['body']['text'] .= ' changed';
            $reason = 'post_readback_mismatch';
        } elseif ($fault === 'pin') { $provider->pinned = null; $reason = 'landing_menu_pin_changed'; }
        elseif ($fault === 'authority') {
            $provider->membershipHook = static fn()=>$pdo->exec('DELETE FROM manager_projects WHERE manager_id=2');
            $reason = 'active_project_admin_and_source_required';
        } elseif ($fault === 'old_hold') {
            $pdo->exec("UPDATE appleservice39_pilot_operations SET state='unknown' WHERE id=1"); $reason = 'search_parent_incomplete';
        } elseif ($fault === 'wrong_channel') {
            $provider->channelId = '-12345'; $publisher = as39SearchPublisher($pdo,$provider); $reason = 'campaign_binding_changed';
        } elseif ($fault === 'parent_drift_after_read') {
            $publisher->run('search_inspect');
            $pdo->exec("UPDATE appleservice39_pilot_operations SET outcome_json='{}' WHERE id=1");
            $reason = 'search_parent_changed';
        } else {
            $publisher->run('search_prepare'); $mode = 'search_apply';
            $row = $store->operation('repair-iphone-16-display','search_v1_upload');
            if ($fault === 'upload_source') {
                $request = $row['request']; $request['source_sha256'] = str_repeat('f',64);
                $pdo->prepare('UPDATE appleservice39_pilot_operations SET request_json=? WHERE id=?')->execute([json_encode($request),$row['id']]);
                $reason = 'search_revision_changed';
            } else {
                $token = $store->operation('repair-iphone-x-battery-replacement','upload')['outcome']['token'];
                $pdo->prepare('UPDATE appleservice39_pilot_operations SET outcome_json=? WHERE id=?')
                    ->execute([json_encode(['photo_id'=>'0','token'=>$token]),$row['id']]);
                $reason = 'search_upload_token_collision';
            }
        }
        $writes = $provider->writes();
        as39Refuses(static fn()=>$publisher->run($mode),$reason);
        as39Expect($provider->writes() === $writes, $fault.' fails before any dependent provider mutation');
    }
    [$pdo,$provider,$store] = as39SearchClone($seed); $publisher = as39SearchPublisher($pdo,$provider);
    $publisher->run('search_prepare');
    $upload = $store->operation('menu','search_v1_upload'); $bad = $upload['request']; $bad['source_sha256'] = str_repeat('a',64);
    as39Refuses(static fn()=>$store->prepare('menu','search_v1_upload',$bad),'invalid_search_operation');
    as39Refuses(static fn()=>$store->prepare('model-iphone-17','upload',['asset_sha256'=>str_repeat('b',64)]),'invalid_search_operation');
    as39Refuses(static fn()=>$store->prepare('unowned-post','search_v1_edit',[]),'invalid_search_operation');
    $forged = $upload; $forged['request'] = $bad;
    as39Refuses(static fn()=>$store->claim($forged),'invalid_search_operation');
    ProjectMaxConnection::resetForTests();
    echo "AppleService39 search publisher regression complete\n";
}

if (!defined('APPLESERVICE39_SEARCH_FIXTURES_ONLY')) as39SearchRegression();
