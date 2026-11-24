<?php
declare(strict_types=1);

namespace AnyTour\RankedPublicReadback;

use Throwable;
use function AnyTour\ExistingPostBatch\{requirePost, parseTransferPublic, selectRankedContinuationRows, transferPlanFromBytes, transferReason};
use const AnyTour\ExistingPostBatch\{RANKED_CONTINUATION_OPERATION, RANKED_RESUME_OPERATION, RANKED_UNREACHED_OPERATION};

require_once __DIR__.'/anytour_existing_post_batch.php';

/** Use the same public history page that passed the independent five-post acceptance. */
function publicHistory(string $message): string
{
    $body = '';
    $request = curl_init('https://t.me/s/anytour?before='.((int)$message + 1));
    requirePost($request !== false, 'public_history_unavailable');
    curl_setopt_array($request, [
        CURLOPT_FOLLOWLOCATION=>false, CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>20,
        CURLOPT_USERAGENT=>'Mozilla/5.0', CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_WRITEFUNCTION=>static function ($unused, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > 1_000_000) return 0;
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);
    $ok = curl_exec($request);
    $http = curl_getinfo($request, CURLINFO_HTTP_CODE);
    curl_close($request);
    requirePost($ok !== false && $http === 200, 'public_history_unavailable');
    return $body;
}

/** The producer records a terminal summary only after validating even a partial receipt. */
function verifiedCandidates(array $plan, array $check, array $result, array $summary, array $context): array
{
    $digest = $context['plan_sha256'];
    $operation = match ($context['write_operation']) {
        'hotel_ranked_46_transfer' => $plan['operation'],
        'hotel_ranked_37_continuation' => RANKED_CONTINUATION_OPERATION,
        'hotel_ranked_resume' => RANKED_RESUME_OPERATION,
        'hotel_ranked_unreached' => RANKED_UNREACHED_OPERATION,
        default => throw new \RuntimeException('unknown_write_operation'),
    };
    requirePost($check['operation'] === $operation && $result['operation'] === $operation && $summary['operation'] === $operation
        && $check['ok'] === true && $check['mode'] === 'offline_plan_check' && $check['production_writes'] === 0 && $check['provider_writes'] === 0
        && $result['native_run_id'] === $context['run_id'] && $result['application_sha'] === $context['application_sha']
        && $check['plan_sha256'] === $digest && $result['plan_sha256'] === $digest
        && $result['supplier_requests'] === 0 && $result['bot_starts'] === 0
        && in_array($summary['state'], ['complete','partial_requires_readback'], true), 'native_receipt_context_invalid');

    $planned = array_column($plan['rows'], null, 'hotel_id');
    $hotelIds = $summary['hotel_ids'];
    $native = $result['results'];
    requirePost(is_array($native) && array_is_list($native) && is_array($hotelIds) && array_is_list($hotelIds)
        && count($hotelIds) === count(array_unique($hotelIds)) && count($native) <= count($hotelIds)
        && $hotelIds === array_column($check['rows'], 'hotel_id')
        && array_column($native, 'hotel_id') === array_slice($hotelIds, 0, count($native))
        && array_column($native, 'message_id') === array_slice($summary['message_ids'], 0, count($native))
        && $summary['unreached'] === array_slice($hotelIds, count($native))
        && $result['provider_write_attempts'] === array_sum(array_column($native, 'provider_write_attempts')),
        'native_receipt_rows_invalid');
    if ($context['write_operation'] === 'hotel_ranked_unreached') {
        $parent = $context['parent_run_id'] ?? null;
        requirePost(is_string($parent) && preg_match('/\A[1-9][0-9]{0,15}\z/D', $parent) === 1
            && $parent !== $context['run_id'] && ($check['parent_run_id'] ?? null) === $parent
            && ($result['parent_run_id'] ?? null) === $parent && $hotelIds !== [], 'native_parent_run_mismatch');
        $scope = selectRankedContinuationRows($plan, $digest)['rows'];
        $suffix = array_slice($scope, -count($hotelIds));
        requirePost(count($suffix) < count($scope) && array_column($suffix, 'hotel_id') === $hotelIds
            && array_column($suffix, 'target_message_id') === $summary['message_ids'], 'native_unreached_scope_invalid');
        foreach ($suffix as $i => $expected) {
            requirePost(($check['rows'][$i]['target_url'] ?? null) === 'https://t.me/anytour/'.$expected['target_message_id']
                && ($check['rows'][$i]['caption_sha256'] ?? null) === $expected['next_caption_sha256'], 'native_target_mismatch');
        }
    }
    $candidates = []; $held = [];
    foreach ($native as $i => $row) {
        $id = $row['hotel_id'];
        $p = $planned[$id] ?? [];
        requirePost(($p['target_message_id'] ?? null) === $row['message_id']
            && $row['url'] === 'https://t.me/anytour/'.$p['target_message_id']
            && $row['url'] === $check['rows'][$i]['target_url']
            && $check['rows'][$i]['caption_sha256'] === $p['next_caption_sha256']
            && is_bool($row['ok']), 'native_target_mismatch');
        if (!$row['ok']) {
            $held[] = $id;
            continue;
        }
        requirePost($row['state'] === 'native_and_registry_verified' && $row['provider_write_attempts'] === 1
            && $row['hotel_id_verified'] === true && $row['photo_caption_button_verified'] === true
            && $row['original_message_id_and_date_preserved'] === true && is_int($row['publication_id']) && $row['publication_id'] > 0
            && is_int($row['version_id']) && $row['version_id'] > 0
            && preg_match('/\A[a-f0-9]{64}\z/D', $row['content_hash']) === 1
            && preg_match('~\Ahttps://t\.me/anyru_bot\?start=p_[A-Za-z0-9_-]{8,60}\z~D', $row['button_url']) === 1,
            'native_verification_incomplete');
        $candidates[] = [$p, $row];
    }
    requirePost($summary['confirmed'] === array_map(static fn($p) => $p[1]['hotel_id'], $candidates)
        && array_column($summary['held'], 'hotel_id') === $held, 'native_summary_mismatch');
    return $candidates;
}

function inspectPublic(array $candidates, string $digest, callable $read): array
{
    $rows = []; $routes = [];
    foreach ($candidates as [$p, $n]) {
        $entry = ['hotel_id'=>$n['hotel_id'], 'message_id'=>$n['message_id'], 'url'=>$n['url'],
            'publication_id'=>$n['publication_id'], 'version_id'=>$n['version_id'], 'native_photo_identity_verified'=>true, 'applied'=>false];
        try {
            $observed = parseTransferPublic($read($n['message_id']), 'anytour', $n['message_id'], $digest);
            $buttons = $observed['buttons'];
            $entry['caption'] = $observed['caption_sha256'] === $p['next_caption_sha256'];
            $entry['public_new_photo_marker'] = $observed['single_photo'] && $observed['photo_marker_sha256'] !== $p['target_photo_marker_sha256'];
            $entry['original_date'] = $observed['published_unixtime'] === $p['target_published_unixtime'];
            $entry['hotel_button'] = count($buttons) === 1 && $buttons[0]['hotel_url'] === $n['button_url']
                && $buttons[0]['text'] === $p['button_text'];
            $entry['html_sha256'] = $observed['html_sha256'];
            $entry['applied'] = $entry['caption'] && $entry['public_new_photo_marker'] && $entry['original_date'] && $entry['hotel_button'];
            if ($entry['applied']) {
                $routes[] = ['hotel_id'=>$n['hotel_id'], 'source_url'=>'https://t.me/any_toour/'.$p['source_message_id'],
                    'post_url'=>$n['url'], 'publication_id'=>$n['publication_id'], 'version_id'=>$n['version_id']];
            }
        } catch (Throwable $e) {
            $entry['reason'] = transferReason($e);
        }
        $rows[] = $entry;
    }
    return ['results'=>$rows, 'public_verified'=>count($routes), 'public_unverified'=>count($rows)-count($routes),
        'verified_destinations'=>$routes];
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $folder = getenv('RUNNER_TEMP').'/native/';
    $load = static fn(string $file): array => json_decode(file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
    $index = $load(__DIR__.'/../config/anytour_ranked_46_20261123.json');
    $summary = $load($folder.'summary.json');
    $context = ['run_id'=>getenv('NATIVE_RUN_ID'), 'repository_sha'=>getenv('NATIVE_REPOSITORY_SHA'),
        'artifact_id'=>getenv('RECEIPT_ID'), 'artifact_sha256'=>getenv('RECEIPT_SHA256'), 'write_operation'=>getenv('WRITE_OPERATION')];
    if ($context['write_operation'] === 'hotel_ranked_unreached') {
        $context['parent_run_id'] = getenv('NATIVE_PARENT_RUN_ID');
    }
    requirePost(preg_match('/\A[1-9][0-9]{0,19}\z/D', $context['run_id']) === 1
        && preg_match('/\A[a-f0-9]{40}\z/D', $context['repository_sha']) === 1
        && preg_match('/\A[1-9][0-9]{0,19}\z/D', $context['artifact_id']) === 1
        && preg_match('/\A[a-f0-9]{64}\z/D', $context['artifact_sha256']) === 1, 'native_receipt_context_invalid');
    $groups = []; $unverified = 0;
    foreach ($index['plans'] as $item) {
        $digest = $item['sha256'];
        $plan = transferPlanFromBytes(file_get_contents(__DIR__.'/../'.$item['path']), $digest, $digest);
        $result = $load($folder.'result-'.$digest.'.json');
        // The native job writes its terminal summary only after comparing the result
        // to resolve-consumer's accepted application SHA (which may differ from repository SHA).
        $context['application_sha'] ??= $result['application_sha'] ?? '';
        requirePost(preg_match('/\A[a-f0-9]{40}\z/D', $context['application_sha']) === 1, 'native_application_unconfirmed');
        $groups[$digest] = verifiedCandidates($plan, $load($folder.'check-'.$digest.'.json'), $result,
            $summary[$digest], $context + ['plan_sha256'=>$digest]);
    }
    $report = $context + ['read_only'=>true, 'provider_write_attempts'=>0, 'plans'=>[]];
    foreach ($groups as $digest => $candidates) {
        $verified = inspectPublic($candidates, $digest, __NAMESPACE__.'\\publicHistory');
        $report['plans'][$digest] = $verified;
        $unverified += $verified['public_unverified'];
    }
    $report['verified_at_utc'] = gmdate(DATE_ATOM);
    file_put_contents('ranked-public-readback.json', json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX);
    exit($unverified === 0 ? 0 : 1);
}
