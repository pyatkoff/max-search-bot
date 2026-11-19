<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
ini_set('display_errors', '0');

const AS39_REPOST_ROOT = '/var/www/anytoour/data/www/app.anytoour.ru';
const AS39_REPOST_APP = 'fe623086b716b3077e4ec0d4551095fc63db37e3';
const AS39_REPOST_CHANNEL = '-80917954947727';
const AS39_REPOST_ATTEMPTS = [
    'device-iphone-18' => [1795051810000, 1795051817999, 44436183201],
    'device-ipad' => [1795055442000, 1795055453999, 44443766969],
];

function as39RepostCheck(bool $ok, string $reason): void
{
    if (!$ok) throw new RuntimeException($reason);
}

function as39RepostJson(array $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function as39RepostCall(object $publisher, string $method, ...$args)
{
    return (new ReflectionMethod($publisher, $method))->invoke($publisher, ...$args);
}

function as39ObserveOriginal(AppleService39PilotPublisher $publisher, AppleService39ChannelTransport $transport, array $row): void
{
    $answer = $transport->messagesAroundClaim((int)$row['started_ms']);
    $messages = $answer['data']['messages'] ?? null;
    as39RepostCheck(AppleService39ChannelTransport::accepted($answer) && is_array($messages)
        && array_is_list($messages) && count($messages) < 100, 'original_native_observation_unavailable');
    foreach ($messages as $message) {
        if (!is_array($message) || !as39RepostCall($publisher,'messageIdentity',$message)) continue;
        as39RepostCheck(($message['body']['text'] ?? null) !== $row['request']['native_text'], 'original_native_candidate_found');
    }
}

function as39SaveReplacement(PDO $db, AppleService39PilotPublicationStore $store, array $row, array $replacement): array
{
    $outcome = $row['outcome'];
    $outcome['owner_authorized_retry'] = $replacement;
    $save = $db->prepare("UPDATE appleservice39_pilot_operations SET outcome_json=?, updated_at=?
        WHERE id=? AND payload_sha256=? AND state='unknown' AND write_attempts=1 AND outcome_json=? AND external_message_id IS NULL AND public_url IS NULL");
    $save->execute([as39RepostJson($outcome), gmdate('Y-m-d H:i:s'), $row['id'], $row['payload_sha256'], $row['outcome_json']]);
    as39RepostCheck($save->rowCount() === 1, 'replacement_claim_changed');
    $saved = $store->operation($row['post_key'], 'create');
    as39RepostCheck(is_array($saved) && $saved['outcome'] === $outcome, 'replacement_claim_unconfirmed');
    return $saved;
}

try {
    as39RepostCheck($argc === 2 && $argv[1] === '--apply' && chdir(AS39_REPOST_ROOT), 'fixed_operation_required');
    $private = '/var/www/anytoour/data/config/max-search.php';
    as39RepostCheck(is_file($private) && !is_link($private), 'protected_connection_unavailable');
    $settingsHash = hash_file('sha256', $private);
    $safe = static function () use ($private, $settingsHash): bool {
        exec('git rev-parse HEAD', $head, $code);
        if ($code !== 0 || $head !== [AS39_REPOST_APP]) return false;
        exec('git status --porcelain --untracked-files=no', $dirty, $code);
        return $code === 0 && $dirty === [] && is_file($private) && !is_link($private) && hash_file('sha256', $private) === $settingsHash;
    };
    as39RepostCheck($safe(), 'installed_checkout_changed');
    require_once AS39_REPOST_ROOT.'/config.php';
    require_once AS39_REPOST_ROOT.'/services/ConversationDb.php';
    require_once AS39_REPOST_ROOT.'/services/AppleService39PilotPublisher.php';
    $db = ConversationDb::connection();
    as39RepostCheck(!$db->inTransaction(), 'autocommit_required');
    $store = new AppleService39PilotPublicationStore($db);
    $manifest = AppleService39PilotManifest::installed();
    $publisher = new AppleService39PilotPublisher($db, $manifest, AS39_REPOST_CHANNEL, $safe, null, null,
        new AppleService39CatalogueRevision($manifest), new AppleService39SearchIntentRevision($manifest));
    $store->assertBinding(as39RepostCall($publisher, 'preflight'));
    $connection = ProjectMaxConnection::resolve('appleservice39');
    as39RepostCheck($connection !== null, 'protected_connection_unavailable');
    $transport = new AppleService39ChannelTransport($connection, AS39_REPOST_CHANNEL);
    $before = $publisher->run('device_observe');
    as39RepostCheck(($before['verified_posts'] ?? null) === 0 && ($before['held_operations'] ?? null) === 2
        && empty($before['candidates']), 'original_group_changed');

    $originals = [];
    foreach (AppleService39DeviceRevision::POSTS as $id) {
        $request = $store->deviceRequest($id, 'create');
        $row = $store->operation($id, 'create');
        if (!isset(AS39_REPOST_ATTEMPTS[$id])) {
            as39RepostCheck($row === null, 'unattempted_device_changed');
            continue;
        }
        [$start, $end] = AS39_REPOST_ATTEMPTS[$id];
        as39RepostCheck(is_array($row) && $row['state'] === 'unknown' && (int)$row['write_attempts'] === 1
            && (int)$row['started_ms'] >= $start && (int)$row['started_ms'] <= $end
            && $row['request'] === $request && $row['payload_sha256'] === hash('sha256', as39RepostJson($request))
            && $row['external_message_id'] === null && $row['public_url'] === null
            && !isset($row['outcome']['owner_authorized_retry']), 'original_attempt_changed');
        as39RepostCheck($id !== 'device-ipad' || ($row['outcome']['reason'] ?? null) === 'provider_create_http_4xx', 'original_ipad_refusal_changed');
        as39ObserveOriginal($publisher, $transport, $row);
        $originals[$id] = $row;
    }

    foreach ($originals as $id => $row) {
        $store->assertBinding(as39RepostCall($publisher, 'preflight'));
        as39RepostCheck($safe() && $store->operation($id, 'create') === $row, 'replacement_preflight_changed');
        as39ObserveOriginal($publisher, $transport, $row);
        $request = $row['request'];
        $body = $request['body'];
        unset($body['notify']);
        $replacement = ['state'=>'write_started','original_run_id'=>AS39_REPOST_ATTEMPTS[$id][2],
            'original_started_ms'=>(int)$row['started_ms'],'original_outcome'=>$row['outcome'],
            'authorization'=>'Owner requested republication on 2026-11-19','replacement_write_attempts'=>1,
            'body_sha256'=>hash('sha256',as39RepostJson($body))];
        $row = as39SaveReplacement($db, $store, $row, $replacement);
        $response = $transport->create($body);
        $code = $response['data']['code'] ?? null;
        $replacement['provider_http'] = (int)($response['http'] ?? 0);
        $replacement['provider_code'] = is_string($code) && preg_match('/\A[A-Za-z0-9_.-]{1,100}\z/D', $code) ? $code : null;
        $replacement['state'] = 'response_received';
        $row = as39SaveReplacement($db, $store, $row, $replacement);
        echo as39RepostJson(['device'=>$id, 'http'=>$replacement['provider_http'], 'code'=>$replacement['provider_code']]).PHP_EOL;
        $message = $response['data']['message'] ?? null;
        as39RepostCheck(AppleService39ChannelTransport::accepted($response) && is_array($message)
            && as39RepostCall($publisher,'messageIdentity',$message), 'replacement_response_unconfirmed');
        $mid = $message['body']['mid'];
        as39RepostCheck(!in_array($mid,array_column($store->rows(),'external_message_id'),true), 'replacement_mid_collision');
        $url = as39RepostCall($publisher, 'verifyMessageContent', $message, $mid, $request['body'], $request);
        $photo = (string)(as39RepostCall($publisher,'imagePayload',$message)['photo_id'] ?? '');
        as39RepostCheck(AppleService39DeviceRevision::validNativePhotoId($photo), 'replacement_photo_unconfirmed');
        $store->rememberMessage($row, $mid, $url);
        $read = $transport->message($mid);
        $native = $read['data']['message'] ?? $read['data'] ?? null;
        as39RepostCheck(AppleService39ChannelTransport::accepted($read) && is_array($native)
            && as39RepostCall($publisher, 'verifyMessageContent', $native, $mid, $request['body'], $request) === $url
            && (string)(as39RepostCall($publisher,'imagePayload',$native)['photo_id'] ?? '') === $photo, 'replacement_native_readback_unconfirmed');
        $store->assertBinding(as39RepostCall($publisher, 'preflight'));
        as39RepostCheck($safe(), 'installed_checkout_changed');
        $row = $store->operation($id, 'create');
        $store->rememberCoverageImageReceipt($row, ['kind'=>'create_response','message_id'=>$mid,'photo_id'=>$photo,
            'source_sha256'=>$request['source_sha256'],'upload_token_sha256'=>$request['upload_token_sha256'],
            'request_sha256'=>$row['payload_sha256'],'revision_sha256'=>$request['revision_sha256']]);
        $replacement['state'] = 'verified';
        $replacement['url'] = $url;
        $store->finish($row, 'verified', ['readback'=>true,'revision_sha256'=>$request['revision_sha256'],'owner_authorized_retry'=>$replacement]);
        echo as39RepostJson(['device'=>$id,'verified_url'=>$url]).PHP_EOL;
    }
    // The other two still get their first sends via the installed publisher.
    $result = $publisher->run('device_apply');
    echo as39RepostJson(['ok'=>$result['device_publication_complete'], 'verified_posts'=>$result['verified_posts'],
        'published'=>$result['published']]).PHP_EOL;
    exit($result['device_publication_complete'] ? 0 : 1);
} catch (Throwable $error) {
    $reason = $error->getMessage();
    echo as39RepostJson(['ok'=>false,'reason'=>preg_match('/\A[a-z_]{1,80}\z/D', $reason) ? $reason : 'publication_stopped']).PHP_EOL;
    exit(1);
}
