<?php
declare(strict_types=1);

if (!defined('APPLESERVICE39_COVERAGE_FIXTURES_ONLY')) define('APPLESERVICE39_COVERAGE_FIXTURES_ONLY', true);
require_once __DIR__.'/run_appleservice39_coverage_regression.php';

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
    ProjectMaxConnection::resetForTests();
    echo "AppleService39 device publication regression complete\n";
}
as39DevicePublisherRegression();
