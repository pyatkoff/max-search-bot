<?php
declare(strict_types=1);

if (!defined('APPLESERVICE39_COVERAGE_FIXTURES_ONLY')) define('APPLESERVICE39_COVERAGE_FIXTURES_ONLY', true);
require_once __DIR__.'/run_appleservice39_coverage_regression.php';

/** Model the later public-link maintenance operations on a separate SQLite copy.
 *  They retain the original create identity and carry their own edit readback.
 */
function as39DeviceMaintainedSeed(array $fixture): array
{
    [$pdo,$provider,$store] = as39SearchClone($fixture);
    $pin = $store->operation('menu','pin');
    as39Expect($provider->pinned === $pin['request']['mid'], 'the synthetic menu is already pinned');
    $pdo->prepare("UPDATE appleservice39_pilot_operations SET state='prepared', write_attempts=0, started_ms=NULL, outcome_json='{}' WHERE id=?")->execute([$pin['id']]);
    $store->confirmExistingPin($store->operation('menu','pin'));
    $digest = static fn($value): string => hash('sha256',json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    $updated = 0;
    foreach ($store->rows() as $create) {
        if ($create['operation'] !== 'create' || $create['post_key'] === 'model-iphone-x') continue;
        $id = $create['post_key']; $mid = $create['external_message_id'];
        $before = $provider->messages[$mid]['body'];
        $source = ['revision'=>'public-links-remaining68-20261116','target_post'=>$id,
            'message_id'=>$mid,'url'=>$create['public_url'],'before_text_sha256'=>hash('sha256',$before['text']),
            'before_markup_sha256'=>$digest($before['markup'] ?? []),'preserved_photo_id'=>$before['attachments'][0]['payload']['photo_id'],
            'preserved_buttons'=>$before['attachments'][1]['payload']['buttons'],'native_text'=>$before['text'],'native_markup'=>$before['markup'] ?? []];
        $row = $store->prepare('pl2-'.$id,'links',$source);
        if (!$store->claim($row)) throw new RuntimeException('failed synthetic public-link claim');
        $store->rememberMessage($row,$mid,$create['public_url']);
        $store->finish($row,'verified',['readback'=>true,'native_text_sha256'=>$source['before_text_sha256'],
            'native_markup_sha256'=>$source['before_markup_sha256'],'native_autolinks'=>count($source['native_markup']),
            'photo_preserved'=>true,'single_cta_preserved'=>true,'provider_acknowledged'=>true,'public_acceptance'=>'pending']);
        $updated++;
    }
    $pin = $store->operation('menu','pin');
    as39Expect($updated === 68 && (int)$pin['write_attempts'] === 0
        && $pin['outcome'] === ['readback'=>true,'already_present'=>true], 'fixture contains 68 later link receipts and an adopted native pin');
    return [$pdo,$provider,$store];
}

function as39DeviceMaintainedParents(array $fixture): void
{
    $seed = as39DeviceMaintainedSeed($fixture);
    [$pdo,$provider,$store] = as39SearchClone($seed);
    $old = $store->rows(); $native = $provider->messages; $pinned = $provider->pinned; $digest = $store->deviceParentDigest();
    $worker = as39SearchPublisher($pdo,$provider);
    $inspection = $worker->run('device_inspect');
    as39Expect($inspection['ok'] && $inspection['verified_posts'] === 0 && $store->rows() === $old && $provider->writes() === 0,
        'a settled variable-length production-shaped parent admits read-only device inspection');
    $worker->run('device_prepare');
    $complete = $worker->run('device_apply');
    as39Expect($complete['device_publication_complete'] && count($complete['published']) === 4
        && $provider->count('POST','/uploads') === 4 && $provider->count('POST','/messages') === 4
        && $provider->count('PUT','/messages') === 0 && $provider->pinned === $pinned && $store->deviceParentDigest() === $digest
        && array_intersect_key($provider->messages,$native) === $native
        && array_slice($store->rows(),0,count($old)) === $old && count($store->rows()) === count($old)+8,
        '69 accepted creates, the adopted pin and all historical edits remain byte-identical through device publication');

    foreach (['unknown','zero_attempt_edit','extra_create','replaced_create'] as $fault) {
        [$badPdo,$badProvider,$badStore] = as39SearchClone($seed);
        if ($fault === 'unknown' || $fault === 'zero_attempt_edit') {
            $edit = $badStore->operation('pl2-menu','links');
            $badPdo->prepare('UPDATE appleservice39_pilot_operations SET state=?,write_attempts=? WHERE id=?')
                ->execute([$fault === 'unknown' ? 'unknown' : 'verified',$fault === 'zero_attempt_edit' ? 0 : 1,$edit['id']]);
        } elseif ($fault === 'replaced_create') {
            $badPdo->exec("UPDATE appleservice39_pilot_operations SET post_key='foreign-fixture-post' WHERE post_key='model-iphone-x' AND operation='create'");
        } else {
            $extra = $badStore->prepare('foreign-fixture-post','create',['body'=>['text'=>'fixture']]);
            if (!$badStore->claim($extra)) throw new RuntimeException('failed synthetic extra create');
            $badStore->rememberMessage($extra,'foreign_fixture','https://max.ru/channel_appleservice39ru/foreign_fixture');
            $badStore->finish($extra,'verified',['readback'=>true]);
        }
        $prior = $badStore->rows();
        as39Refuses(static fn()=>as39SearchPublisher($badPdo,$badProvider)->run('device_prepare'), 'device_parent_incomplete');
        as39Expect($badStore->rows() === $prior && $badProvider->writes() === 0, $fault.' blocks the first new claim and provider write');
    }

    [$changingDb,$changingProvider,$changingStore] = as39SearchClone($seed);
    $first = (new AppleService39DeviceRevision())->order()[0]; $changed = false;
    $changingProvider->membershipHook = static function () use ($changingDb,$changingStore,$first,&$changed): void {
        if ($changed || ($changingStore->operation($first,AppleService39DeviceRevision::UPLOAD)['state'] ?? null) !== 'prepared') return;
        $edit = $changingStore->operation('pl2-menu','links');
        $outcome = $edit['outcome']; $outcome['public_acceptance'] = 'confirmed';
        $changingDb->prepare('UPDATE appleservice39_pilot_operations SET outcome_json=? WHERE id=?')
            ->execute([json_encode($outcome,JSON_THROW_ON_ERROR),$edit['id']]);
        $changed = true;
    };
    as39Refuses(static fn()=>as39SearchPublisher($changingDb,$changingProvider)->run('device_prepare'), 'coverage_parent_changed');
    $held = $changingStore->operation($first,AppleService39DeviceRevision::UPLOAD);
    as39Expect($changed && $held['state'] === 'prepared' && (int)$held['write_attempts'] === 0
        && $changingProvider->writes() === 0, 'a changed confirmed maintenance receipt after snapshot blocks the upload before claiming');
}

/** Exercise the new batch on receipts produced by the real predecessor state machines. */
function as39DevicePublisherRegression(): void
{
    $fixture = as39CoverageSeed();
    as39CoverageSuccess($fixture);
    [$pdo,$provider,$store] = as39SearchClone($fixture);
    $publisher = as39SearchPublisher($pdo, $provider);
    $revision = new AppleService39DeviceRevision();
    $ids = ['device-iphone-18','device-ipad','device-macbook','device-apple-watch'];
    $old = $store->rows();
    $oldMessages = $provider->messages;
    $oldPin = $provider->pinned;
    $parentDigest = $store->deviceParentDigest();
    $coverageDigest = $store->coverageParentDigest();
    $initial = $publisher->run('device_inspect');
    as39Expect(count($old) === 193 && count($oldMessages) === 69 && $initial['verified_posts'] === 0
        && !$initial['device_publication_complete'] && $initial['published'] === []
        && $provider->writes() === 0 && $store->rows() === $old, 'device inspection preserves the 69-post predecessor');
    foreach ($ids as $id) {
        as39Expect($store->verifiedPostUrl($id,10,true) === null, 'unpublished device has no usable destination');
    }

    $publisher->run('device_prepare');
    $preparedWrites = $provider->writes();
    $publisher->run('device_prepare');
    as39Expect($provider->count('POST','/uploads') === 4 && $provider->count('POST','/messages') === 0
        && $provider->writes() === $preparedWrites, 'four fixed images upload once without creating messages');
    $prepared = as39SearchClone([$pdo,$provider,$store]);
    $result = $publisher->run('device_apply');
    as39Expect($result['ok'] && $result['device_publication_complete'] && $result['verified_posts'] === 4
        && $result['held_operations'] === 0 && count($result['published']) === 4, 'all four devices have verified native destinations');
    as39Expect($provider->count('POST','/messages') === 4 && $provider->count('POST','/uploads') === 4
        && $provider->writes() === $preparedWrites + 4 && $provider->pinned === $oldPin,
        'only four silent native creates follow the four source-bound uploads');

    $photos = []; $sources = []; $urls = [];
    foreach ($ids as $id) {
        $row = $store->operation($id, 'create');
        $upload = $store->operation($id, AppleService39DeviceRevision::UPLOAD);
        $receipt = $row['outcome']['image_receipt'];
        $message = $provider->messages[$row['external_message_id']];
        $attachments = $message['body']['attachments'];
        $buttons = $attachments[1]['payload']['buttons'];
        as39Expect($row['state'] === 'verified' && (int)$row['write_attempts'] === 1 && $row['outcome']['readback'] === true
            && $upload['state'] === 'verified' && (int)$upload['write_attempts'] === 1
            && $row['request']['upload_operation_id'] === (int)$upload['id']
            && $receipt['source_sha256'] === $revision->assetSha($id)
            && $receipt['upload_token_sha256'] === hash('sha256',$upload['outcome']['token'])
            && $receipt['request_sha256'] === $row['payload_sha256']
            && $receipt['message_id'] === $row['external_message_id']
            && $row['request']['body']['notify'] === false && count($attachments) === 2 && $attachments[0]['type'] === 'image'
            && (string)$attachments[0]['payload']['photo_id'] === $receipt['photo_id']
            && $attachments[1]['type'] === 'inline_keyboard' && count($buttons) === 1 && count($buttons[0]) === 1
            && $buttons[0][0]['type'] === 'link' && trim($buttons[0][0]['text']) !== ''
            && $buttons[0][0]['url'] === 'https://max.ru/id390521129062_bot?start=as39_'.$id
            && $result['published'][$id] === $message['url'] && $row['public_url'] === $message['url']
            && $store->verifiedPostUrl($id,10,true) === $message['url'] && $store->verifiedPostUrl($id,20,true) === null,
            $id.' retains its intended image, single callback, project receipt and canonical URL');
        $photos[] = $receipt['photo_id']; $sources[] = $receipt['source_sha256']; $urls[] = $message['url'];
    }
    as39Expect(count(array_unique($photos)) === 4 && count(array_unique($sources)) === 4 && count(array_unique($urls)) === 4,
        'four distinct cover files, native photos and canonical post URLs');
    $all = $store->rows(); $writes = $provider->writes();
    foreach (['device_apply','device_prepare','device_reconcile','device_inspect'] as $mode) {
        as39Expect($publisher->run($mode)['device_publication_complete'], 'complete batch verifies on '.$mode);
    }
    $legacy = $publisher->run('coverage_inspect');
    as39Expect($legacy['coverage_publication_complete'] && $legacy['post_count'] === 69 && $legacy['feed_verified_offers'] === 67
        && count($all) === 201 && array_slice($all,0,193) === $old && $store->rows() === $all
        && $store->deviceParentDigest() === $parentDigest && $store->coverageParentDigest() === $coverageDigest
        && $provider->writes() === $writes && array_intersect_key($provider->messages,$oldMessages) === $oldMessages,
        'parent content, pin and all 193 records stay unchanged on replay');
    $first = $revision->order()[0];
    $bad = $store->operation($first,'create')['request'];
    $bad['body']['text'] .= ' changed';
    as39Refuses(static fn()=>$store->prepare($first,'create',$bad), 'invalid_device_operation');
    as39Refuses(static fn()=>$store->prepare('other-device',AppleService39DeviceRevision::UPLOAD,[]), 'invalid_device_operation');

    foreach (['lost_create','lost_readback'] as $fault) {
        [$faultPdo,$faultProvider,$faultStore] = as39SearchClone($prepared);
        $wire = as39SearchWire($faultProvider); $lost = false; $createdMid = null;
        $faultWire = static function (array $request) use ($wire,$fault,&$lost,&$createdMid): array {
            $response = $wire($request);
            $path = parse_url($request['url'], PHP_URL_PATH);
            if ($request['method'] === 'POST' && $path === '/messages') $createdMid = $response['data']['message']['body']['mid'] ?? null;
            if (!$lost && $createdMid !== null && (($fault === 'lost_create' && $request['method'] === 'POST' && $path === '/messages')
                || ($fault === 'lost_readback' && $request['method'] === 'GET' && $path === '/messages/'.$createdMid))) {
                $lost = true; return ['http'=>0,'transport_error'=>true,'data'=>null];
            }
            return $response;
        };
        $worker = as39SearchPublisher($faultPdo, $faultProvider, $faultWire);
        as39Refuses(static fn()=>$worker->run('device_apply'), 'coverage_operation_held');
        $unknown = $faultStore->operation($first,'create'); $afterFailure = $faultStore->rows();
        as39Expect($lost && $unknown['state'] === 'unknown' && (int)$unknown['write_attempts'] === 1
            && $faultStore->verifiedPostUrl($first,10,true) === null && $faultProvider->count('POST','/messages') === 1,
            $fault.' holds a single external attempt and withholds its feed URL');
        $inspected = $worker->run('device_inspect');
        $oldCoverage = $worker->run('coverage_inspect');
        as39Expect($inspected['held_operations'] === 1 && !$inspected['device_publication_complete']
            && $oldCoverage['coverage_publication_complete'] && $faultStore->rows() === $afterFailure,
            'inspection preserves the exact hold and the completed legacy batch');
        $reconciled = $worker->run('device_reconcile');
        $fresh = $faultStore->operation($first,'create');
        as39Expect($faultProvider->writes() === 1 && (int)$fresh['write_attempts'] === 1
            && $fresh['id'] === $unknown['id'] && $fresh['request_json'] === $unknown['request_json'],
            'only provider reads are used to review the original attempt');
        if ($fault === 'lost_create') {
            as39Expect($fresh['state'] === 'unknown' && $reconciled['held_operations'] === 1,
                'lost original message ID remains unknown');
            as39Refuses(static fn()=>$worker->run('device_apply'), 'coverage_operation_held');
            as39Expect($faultProvider->writes() === 1, 'ambiguous create is never sent twice');
        } else {
            as39Expect($fresh['state'] === 'verified' && $reconciled['held_operations'] === 0
                && $worker->run('device_apply')['device_publication_complete'] && $faultProvider->writes() === 4,
                'exact saved MID allows GET recovery and only three remaining creates');
        }
    }
    as39DeviceMaintainedParents($fixture);
    ProjectMaxConnection::resetForTests();
    echo "AppleService39 device publication regression complete\n";
}
as39DevicePublisherRegression();
