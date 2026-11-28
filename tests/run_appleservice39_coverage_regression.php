<?php
declare(strict_types=1);
if (!defined('APPLESERVICE39_SEARCH_FIXTURES_ONLY')) define('APPLESERVICE39_SEARCH_FIXTURES_ONLY', true);
require_once __DIR__.'/run_appleservice39_search_publisher_regression.php';

function as39CoverageSeed(?PDO $pdo = null): array
{
    $seed = as39SearchSeed($pdo);
    as39SearchSuccess($seed);
    return $seed;
}

function as39CoverageSuccess(array $fixture, ?callable $beforeMenu = null): void
{
    [$pdo,$provider,$store] = $fixture;
    $publisher = as39SearchPublisher($pdo,$provider);
    $old = $store->rows(); $writes = $provider->writes(); $pin = $provider->pinned;
    $creates = $provider->count('POST','/messages'); $edits = $provider->count('PUT','/messages');
    $uploads = $provider->count('POST','/uploads'); $pins = $provider->count('PUT','/chats/'.$provider->channelId.'/pin');
    $initial = $publisher->run('coverage_inspect');
    as39Expect($initial['post_count'] === 30 && !$initial['coverage_publication_complete']
        && $store->rows() === $old && $provider->writes() === $writes, 'coverage inspection cannot prepare, claim or publish');
    $provider->zeroUploadKey = true;
    $publisher->run('coverage_prepare'); $publisher->run('coverage_prepare');
    as39Expect($provider->count('POST','/uploads') === $uploads+1 && $provider->count('POST','/messages') === $creates,
        'one approved neutral cover accepts zero upload map index and is not uploaded twice');
    $requestOffset = count($provider->requests);
    $priorHook = $provider->membershipHook; $captured = false;
    if ($beforeMenu !== null) $provider->membershipHook = static function () use ($provider,$store,$beforeMenu,$priorHook,&$captured): void {
        if ($priorHook !== null) $priorHook();
        $menu = $store->operation('menu',AppleService39CoverageRevision::MENU);
        $creates = array_filter($store->rows(),static fn(array $row): bool =>
            $row['operation'] === 'create' && $row['state'] === 'verified'
            && AppleService39CoverageRevision::isOperation($row['post_key'],$row['operation'],$row['request']));
        if (!$captured && count($creates) === 39 && $menu !== null
            && $menu['state'] === 'prepared' && (int)$menu['write_attempts'] === 0) {
            $captured = true; $beforeMenu();
        }
    };
    try { $report = $publisher->run('coverage_apply'); }
    finally { $provider->membershipHook = $priorHook; }
    as39Expect($report['ok'] && $report['coverage_publication_complete'] && $report['coverage_verified_posts'] === 39
        && $report['post_count'] === 69 && $report['expected_feed_offers'] === 67
        && $report['feed_verified_offers'] === 67 && count($report['feed_verified_routes']) === 67
        && $report['menu_verified'] && $report['menu_pin_preserved'], 'all39 new posts and original-menu text complete the69-post graph');
    as39Expect($provider->count('POST','/messages') === $creates+39 && $provider->count('PUT','/messages') === $edits+1
        && $provider->count('PUT','/chats/'.$provider->channelId.'/pin') === $pins && $provider->pinned === $pin,
        'coverage performs39 creates, one original-MID text edit and no pin writes');
    as39Expect(count($store->rows()) === 193 && array_slice($store->rows(),0,152) === $old,
        'all152 original requests, receipts, IDs, attempts and timestamps remain byte-for-byte unchanged');
    $urls = array_column($provider->messages,'url'); $ctaCount = 0; $newPhotoIds = [];
    foreach ($provider->messages as $mid=>$message) {
        $buttons = []; foreach ($message['body']['attachments'] as $attachment) if ($attachment['type'] === 'inline_keyboard') {
            $buttons = $attachment['payload']['buttons']; $ctaCount++;
        }
        as39Expect(count($buttons) === 1 && count($buttons[0]) === 1
            && str_starts_with($buttons[0][0]['url'],'https://max.ru/id390521129062_bot?start=as39_'),
            'each native post retains exactly one contextual bot CTA');
        foreach ($message['body']['markup'] as $span) if ($span['type'] === 'link') {
            as39Expect(in_array($span['url'],$urls,true),'every compiled navigation link points to an actually verified native post');
        }
    }
    $revision = new AppleService39CoverageRevision();
    foreach ($revision->order() as $id) {
        $row = $store->operation($id,'create'); $newPhotoIds[] = $row['outcome']['image_receipt']['photo_id'];
        as39Expect($row['state'] === 'verified' && (int)$row['write_attempts'] === 1
            && $store->verifiedPostUrl($id,10,true) === $row['public_url'], 'new feed destination uses its own one-attempt create receipt');
    }
    as39Expect($ctaCount === 69 && count(array_unique($newPhotoIds)) === 1,
        '69 single CTAs coexist with deliberate same-source neutral-cover reuse');
    foreach (array_slice($provider->requests,$requestOffset) as $request) {
        if ($request['method'] === 'POST' && parse_url($request['url'],PHP_URL_PATH) === '/messages') {
            as39Expect(!array_key_exists('notify', $request['body']), 'MAX channel create omits the unsupported notify field');
        }
        if ($request['method'] === 'PUT') as39Expect(array_keys($request['body']) === ['text','format'],
            'original menu update omits image, keyboard and pin fields');
    }
    $all = $store->rows(); $writes = $provider->writes();
    foreach (['coverage_inspect','coverage_prepare','coverage_apply','coverage_reconcile'] as $mode) {
        as39Expect($publisher->run($mode)['coverage_publication_complete'],'completed '.$mode.' validates the same native graph');
    }
    as39Expect($store->rows() === $all && $provider->writes() === $writes,'completed coverage cannot repeat any external mutation');
    as39Refuses(static fn()=>$publisher->run('search_apply'),'search_superseded');
    $bad = $store->operation($revision->order()[0],'create')['request']; $bad['body']['text'] .= ' changed';
    as39Refuses(static fn()=>$store->prepare($revision->order()[0],'create',$bad),'invalid_coverage_operation');
    $badRow = $store->operation($revision->order()[0],'create'); $badRow['request'] = $bad;
    as39Refuses(static fn()=>$store->claim($badRow),'invalid_coverage_operation');
    as39Refuses(static fn()=>$store->prepare('unowned','coverage_menu',[]),'invalid_coverage_operation');
    as39Expect($provider->writes() === $writes,'forged or legacy successor execution cannot bypass the exact Store request');
}

/** Count local SELECT preparations, not wall time or calls to a real provider. */
final class AppleCoverageReadCountingPDO extends PDO
{
    public int $selects = 0;
    public $beforeSelect = null;
    private function observe(string $query): void
    {
        if (!preg_match('/\A\s*SELECT\b/i', $query)) return;
        $this->selects++;
        if ($this->beforeSelect !== null) ($this->beforeSelect)($this->selects);
    }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->observe($query);
        return parent::prepare($query, $options);
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->observe($query);
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
}

/** Reuse genuine completed transitions; never seed a manufactured success receipt. */
function as39CoverageSnapshotRegression(array $completed, bool $localFiles): void
{
    [$pdo,$provider,$store] = $completed;
    $rows = $store->rows(); $requests = $provider->requests;
    $store->validateCoverageSnapshotRequests();
    $owned = array_filter($rows, static fn(array $row): bool =>
        AppleService39CoverageRevision::isOperation($row['post_key'], $row['operation'], $row['request']));
    foreach ($owned as $row) as39Expect($store->coverageRequest($row['post_key'], $row['operation']) === $row['request'],
        'snapshot accepts the same complete request as the independent fresh Store path');
    as39Expect(count($owned) === 41 && $store->rows() === $rows && $provider->requests === $requests,
        'snapshot request validation preserves all193 original rows and performs no provider operation');

    $first = $store->operation('model-iphone-8', 'create');
    $upload = $store->operation(AppleService39CoverageRevision::COVER, AppleService39CoverageRevision::UPLOAD);
    $cases = [
        'payload' => [$first, ['payload_sha256'=>str_repeat('f',64)], 'coverage_revision_changed'],
        'attempt' => [$first, ['write_attempts'=>2], 'coverage_revision_changed'],
        'state' => [$first, ['state'=>'rejected'], 'coverage_revision_changed'],
        'parent' => [$rows[0], ['state'=>'unknown'], 'coverage_parent_incomplete'],
    ];
    $forged = $first['request']; $forged['body']['text'] .= ' changed';
    $json = json_encode($forged, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $cases['request'] = [$first, ['request_json'=>$json, 'payload_sha256'=>hash('sha256',$json)], 'coverage_revision_changed'];
    $token = $store->operation('repair-iphone-x-battery-replacement','upload')['outcome']['token'];
    $cases['upload'] = [$upload, ['outcome_json'=>json_encode(['photo_id'=>'0','token'=>$token])], 'coverage_upload_collision'];
    foreach ($cases as $name=>[$row,$fields,$reason]) {
        $store->validateCoverageSnapshotRequests();
        $set = implode(',',array_map(static fn(string $field): string => $field.'=?',array_keys($fields)));
        $query = $pdo->prepare('UPDATE appleservice39_pilot_operations SET '.$set.' WHERE id=?');
        $query->execute([...array_values($fields),$row['id']]);
        try { as39Refuses(static fn()=>$store->validateCoverageSnapshotRequests(), $reason); }
        finally { $query->execute([...array_map(static fn(string $field) => $row[$field],array_keys($fields)),$row['id']]); }
        as39Expect($store->rows() === $rows && $provider->requests === $requests,
            $name.' mutation after a successful snapshot is refused without a write or provider request');
    }
    $pdo->exec('DELETE FROM manager_projects WHERE manager_id=2');
    try { as39Refuses(static fn()=>as39SearchPublisher($pdo,$provider)->run('coverage_inspect'), 'active_project_admin_and_source_required'); }
    finally { $pdo->exec('INSERT INTO manager_projects VALUES (2,1)'); }
    as39Expect($store->rows() === $rows && $provider->writes() === count(array_filter($requests,static fn(array $r): bool => $r['method'] !== 'GET')),
        'a previously accepted request snapshot cannot retain revoked publication authority');

    if (!$localFiles) return;
    $revision = new AppleService39CoverageRevision();
    foreach ([dirname(__DIR__).'/resources/appleservice39/coverage-v1/manifest.json'=>'coverage_source_changed',
        $revision->artwork()=>'coverage_image_changed'] as $path=>$reason) {
        $saved = file_get_contents($path);
        if (!is_string($saved)) throw new RuntimeException('Missing local snapshot source fixture');
        $store->validateCoverageSnapshotRequests();
        try {
            file_put_contents($path, $saved."\n"); clearstatcache(true,$path);
            as39Refuses(static fn()=>$store->validateCoverageSnapshotRequests(),$reason);
            as39Refuses(static fn()=>$store->coverageRequest($first['post_key'],$first['operation']),$reason);
        } finally { file_put_contents($path,$saved); clearstatcache(true,$path); }
        $store->validateCoverageSnapshotRequests();
        as39Expect($store->rows() === $rows,
            'changed source or artwork is re-read after a successful snapshot and never changes receipts');
    }

    $counted = new AppleCoverageReadCountingPDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $counted->exec('PRAGMA foreign_keys=ON'); as39Schema($counted);
    foreach (['appleservice39_pilot_campaigns','appleservice39_pilot_operations'] as $table) {
        foreach ($pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $query = $counted->prepare('INSERT INTO '.$table.' ('.implode(',',array_keys($row)).') VALUES ('.implode(',',array_fill(0,count($row),'?')).')');
            $query->execute(array_values($row));
        }
    }
    $copy = new AppleService39PilotPublicationStore($counted);
    $counted->selects = 0; $copy->validateCoverageSnapshotRequests(); $selects = $counted->selects;
    as39Expect($selects > 0 && $selects <= 8 && $copy->rows() === $rows,
        'the complete41-request snapshot uses bounded fresh SELECTs rather than per-row graph reads');

    // A coherent initial view does not authorize accepting a changed view at return.
    foreach (['campaign','parent'] as $drift) {
        $counted->selects = 0;
        $campaign = $copy->campaign(); $counted->selects = 0;
        $counted->beforeSelect = static function (int $select) use ($counted,$drift): void {
            if ($select !== 3) return;
            $counted->beforeSelect = null;
            if ($drift === 'campaign') $counted->exec("UPDATE appleservice39_pilot_campaigns SET manifest_sha256='changed'");
            else $counted->exec("UPDATE appleservice39_pilot_operations SET updated_at='changed' WHERE id=1");
        };
        try { as39Refuses(static fn()=>$copy->validateCoverageSnapshotRequests(),'coverage_revision_changed'); }
        finally {
            $counted->beforeSelect = null;
            $counted->prepare('UPDATE appleservice39_pilot_campaigns SET manifest_sha256=?')->execute([$campaign['manifest_sha256']]);
            $counted->prepare('UPDATE appleservice39_pilot_operations SET updated_at=? WHERE id=?')->execute([$rows[0]['updated_at'],$rows[0]['id']]);
        }
        $copy->validateCoverageSnapshotRequests();
        as39Expect($copy->rows() === $rows && $copy->campaign() === $campaign,
            $drift.' drift during one request snapshot is refused and no stale context survives');
    }
}

function as39CoverageRegression(?PDO $native = null): void
{
    $bodyGuard = new ReflectionMethod(AppleService39ChannelTransport::class,'body');
    foreach ([true,1,'false',null] as $invalidNotify) {
        as39Refuses(static fn()=>$bodyGuard->invoke(null,['text'=>'Reviewed','notify'=>$invalidNotify]),'invalid_channel_body');
    }
    as39Expect($bodyGuard->invoke(null,['text'=>'Reviewed','notify'=>false])['notify'] === false,
        'transport accepts only explicit false notification override');
    $seed = as39CoverageSeed($native);
    $completed = $native === null ? as39SearchClone($seed) : $seed;
    $beforeMenu = null;
    as39CoverageSuccess($completed,$native !== null ? null : static function () use ($completed,&$beforeMenu): void {
        $beforeMenu = as39SearchClone($completed);
        $beforeMenu[1]->membershipHook = null;
    });
    as39CoverageSnapshotRegression($completed, $native === null);
    if ($native !== null) return;
    as39Expect(is_array($beforeMenu) && $beforeMenu[2]->operation('menu',AppleService39CoverageRevision::MENU)['state'] === 'prepared',
        'menu recovery reuses an actual pre-claim publisher snapshot without rewinding or fabricating receipts');
    foreach (['upload_lost','create_lost','create_get_lost','menu_lost'] as $fault) {
        [$pdo,$provider,$store] = as39SearchClone($fault === 'menu_lost' ? $beforeMenu : $seed); $wire = as39SearchWire($provider); $once = false;
        $faultWire = static function(array $request) use($wire,$fault,&$once):array {
            $response = $wire($request); $path = parse_url($request['url'],PHP_URL_PATH);
            if (!$once && ($fault === 'upload_lost' && $request['file'] !== null
                || $fault === 'create_get_lost' && $request['method'] === 'GET' && $path === '/messages/mid_31')) {
                $once = true; return ['http'=>0,'transport_error'=>true,'data'=>null];
            }
            return $response;
        };
        $publisher = as39SearchPublisher($pdo,$provider,$faultWire);
        if ($fault === 'upload_lost') as39Refuses(static fn()=>$publisher->run('coverage_prepare'),'coverage_operation_held');
        else {
            $publisher->run('coverage_prepare');
            if ($fault === 'create_lost') $provider->mode = 'create_timeout';
            if ($fault === 'menu_lost') $provider->mode = 'edit_timeout';
            as39Refuses(static fn()=>$publisher->run('coverage_apply'),'coverage_operation_held');
        }
        $held = array_values(array_filter($store->rows(),static fn(array $r):bool=>$r['state'] === 'unknown'));
        as39Expect(count($held) === 1 && (int)$held[0]['write_attempts'] === 1,$fault.' holds exactly its original single attempt');
        $row = $held[0]; $before = $store->rows(); $writes = $provider->writes();
        $publisher->run('coverage_inspect');
        as39Expect($store->rows() === $before && $provider->writes() === $writes,$fault.' inspection is strictly read-only');
        $provider->mode = ''; $report = $publisher->run('coverage_reconcile');
        as39Expect($provider->writes() === $writes,$fault.' reconciliation sends provider GET only');
        $fresh = $store->operation($row['post_key'],$row['operation']);
        as39Expect($fresh['id'] === $row['id'] && $fresh['request_json'] === $row['request_json']
            && $fresh['payload_sha256'] === $row['payload_sha256'] && $fresh['started_ms'] === $row['started_ms']
            && (int)$fresh['write_attempts'] === 1,$fault.' keeps original identity, request and start');
        if (in_array($fault,['create_get_lost','menu_lost'],true)) {
            as39Expect($fresh['state'] === 'verified' && $report['ok'],$fault.' exact acknowledged native evidence closes its own hold');
            as39Expect($publisher->run('coverage_apply')['coverage_publication_complete']
                && $provider->count('POST','/messages') === ($fault === 'menu_lost' ? 0 : 39)
                && count($provider->messages) === 69 && $provider->count('PUT','/messages') === 1,
                $fault.' resumes only unattempted actions without duplicate posts');
        } else {
            as39Expect($fresh['state'] === 'unknown' && !$report['ok'],$fault.' unknown upload or lost create MID is never guessed');
            as39Refuses(static fn()=>$publisher->run($fault === 'upload_lost' ? 'coverage_prepare' : 'coverage_apply'),'coverage_operation_held');
            as39Expect($provider->writes() === $writes,$fault.' no uncertain provider write is retried');
        }
    }
    foreach (['pin','authority','old_hold','wrong_channel','upload_source','upload_token','native_photo'] as $fault) {
        [$pdo,$provider,$store] = as39SearchClone($fault === 'native_photo' ? $completed : $seed); $publisher = as39SearchPublisher($pdo,$provider);
        $mode = 'coverage_prepare'; $reason = 'coverage_operation_held';
        if ($fault === 'pin') { $provider->pinned = null; $reason = 'landing_menu_pin_changed'; }
        elseif ($fault === 'authority') {
            $provider->membershipHook = static fn()=>$pdo->exec('DELETE FROM manager_projects WHERE manager_id=2');
            $reason = 'active_project_admin_and_source_required';
        } elseif ($fault === 'old_hold') {
            $pdo->exec("UPDATE appleservice39_pilot_operations SET state='unknown' WHERE id=1"); $reason = 'coverage_parent_incomplete';
        } elseif ($fault === 'wrong_channel') {
            $provider->channelId = '-12345'; $publisher = as39SearchPublisher($pdo,$provider); $reason = 'campaign_binding_changed';
        } elseif ($fault === 'native_photo') {
            $mode = 'coverage_inspect'; $reason = 'invalid_coverage_image_receipt';
            $mid = $store->operation('model-iphone-8','create')['external_message_id'];
            $provider->messages[$mid]['body']['attachments'][0]['payload']['photo_id'] = '987654321';
        } else {
            $publisher->run('coverage_prepare'); $mode = 'coverage_apply';
            $row = $store->operation(AppleService39CoverageRevision::COVER,AppleService39CoverageRevision::UPLOAD);
            if ($fault === 'upload_source') {
                $request = $row['request']; $request['source_sha256'] = str_repeat('f',64);
                $pdo->prepare('UPDATE appleservice39_pilot_operations SET request_json=? WHERE id=?')->execute([json_encode($request),$row['id']]);
                $reason = 'coverage_revision_changed';
            } else {
                $token = $store->operation('repair-iphone-x-battery-replacement','upload')['outcome']['token'];
                $pdo->prepare('UPDATE appleservice39_pilot_operations SET outcome_json=? WHERE id=?')
                    ->execute([json_encode(['photo_id'=>'0','token'=>$token]),$row['id']]);
                $reason = 'coverage_upload_collision';
            }
        }
        $writes = $provider->writes();
        as39Refuses(static fn()=>$publisher->run($mode),$reason);
        as39Expect($provider->writes() === $writes,$fault.' refuses before a dependent provider write');
    }
    ProjectMaxConnection::resetForTests();
    echo "AppleService39 coverage publisher regression complete\n";
}
if (!defined('APPLESERVICE39_COVERAGE_FIXTURES_ONLY')) as39CoverageRegression();
