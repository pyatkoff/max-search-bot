<?php
declare(strict_types=1);

namespace AnyTour\Tests\RankedPublicReadback;

use RuntimeException;

require_once __DIR__.'/../tools/verify_ranked_telegram_public.php';

use function AnyTour\RankedPublicReadback\{verifiedCandidates, inspectPublic};
use function AnyTour\ExistingPostBatch\{selectRankedContinuationRows, transferPlanFromBytes};
use const AnyTour\ExistingPostBatch\RANKED_CONTINUATION_OPERATION;

function verify(string $scenario, bool $passed): void
{
    if (!$passed) throw new RuntimeException($scenario);
    echo "PASS $scenario\n";
}

function rejectsInvalidReceipt(callable $verifyReceipt): bool
{
    try {
        $verifyReceipt();
    } catch (RuntimeException) {
        return true;
    }
    return false;
}

function publicPageFixture(array $planned, array $native): string
{
    $caption = htmlspecialchars($planned['next_caption'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $caption = str_replace("\n", '<br>', $caption);
    $message = $native['message_id'];
    $date = gmdate(DATE_ATOM, $planned['target_published_unixtime']);
    return '<html><head><meta charset="utf-8"></head><body><div data-post="anytour/'.$message.'">'
        .'<div class="tgme_widget_message_text">'.$caption.'</div>'
        .'<a class="tgme_widget_message_photo_wrap 99999999" href="'.$native['url'].'" style="background-image:url(https://cdn-telegram.org/data)"></a>'
        .'<a class="tgme_widget_message_inline_button" href="'.$native['button_url'].'">'.$planned['button_text'].'</a>'
        .'<time datetime="'.$date.'"></time></div></body></html>';
}

function runCases(): void
{
    $root = dirname(__DIR__);
    $index = json_decode(file_get_contents($root.'/config/anytour_ranked_46_20261123.json'), true, 32, JSON_THROW_ON_ERROR);
    $planFile = $index['plans'][0];
    $digest = $planFile['sha256'];
    $plan = transferPlanFromBytes(file_get_contents($root.'/'.$planFile['path']), $digest, $digest);
    $rankedRows = selectRankedContinuationRows($plan, $digest)['rows'];
    $hotelIds = array_column($rankedRows, 'hotel_id');

    $check = [
        'operation'=>RANKED_CONTINUATION_OPERATION, 'ok'=>true, 'mode'=>'offline_plan_check',
        'production_writes'=>0, 'provider_writes'=>0, 'plan_sha256'=>$digest, 'rows'=>[],
    ];
    foreach ($rankedRows as $planned) {
        $check['rows'][] = [
            'hotel_id'=>$planned['hotel_id'], 'target_url'=>'https://t.me/anytour/'.$planned['target_message_id'],
            'caption_sha256'=>$planned['next_caption_sha256'],
        ];
    }
    $context = ['plan_sha256'=>$digest, 'write_operation'=>'hotel_ranked_37_continuation', 'run_id'=>'12345', 'application_sha'=>str_repeat('a', 40)];
    $receipt = [
        'operation'=>RANKED_CONTINUATION_OPERATION, 'native_run_id'=>$context['run_id'], 'application_sha'=>$context['application_sha'],
        'plan_sha256'=>$digest, 'supplier_requests'=>0, 'bot_starts'=>0, 'provider_write_attempts'=>7, 'results'=>[],
    ];
    $summary = [
        'state'=>'partial_requires_readback', 'operation'=>RANKED_CONTINUATION_OPERATION,
        'hotel_ids'=>$hotelIds, 'message_ids'=>array_column($rankedRows, 'target_message_id'),
        'unreached'=>array_slice($hotelIds, 7), 'confirmed'=>[], 'held'=>[],
    ];
    $successfulRows = [];
    foreach (array_slice($rankedRows, 0, 7) as $index => $planned) {
        $row = [
            'hotel_id'=>$planned['hotel_id'], 'message_id'=>$planned['target_message_id'],
            'url'=>'https://t.me/anytour/'.$planned['target_message_id'], 'provider_write_attempts'=>1,
        ];
        if (in_array($index, [2, 6], true)) {
            $receipt['results'][] = $row + ['ok'=>false, 'state'=>'response_saved_completion_requires_readback'];
            $summary['held'][] = ['hotel_id'=>$planned['hotel_id']];
            continue;
        }
        $receipt['results'][] = $row + [
            'ok'=>true, 'state'=>'native_and_registry_verified', 'hotel_id_verified'=>true,
            'photo_caption_button_verified'=>true, 'original_message_id_and_date_preserved'=>true,
            'publication_id'=>100+$index, 'version_id'=>200+$index, 'content_hash'=>str_repeat('b', 64),
            'button_url'=>'https://t.me/anyru_bot?start=p_fixture_value_'.$index,
        ];
        $summary['confirmed'][] = $planned['hotel_id'];
        $successfulRows[] = $planned;
    }
    $select = static fn(array $result, array $status, array $lineage): array => verifiedCandidates($plan, $check, $result, $status, $lineage);
    $candidates = $select($receipt, $summary, $context);
    verify('partial receipt selects only new native successes',
        array_column(array_column($candidates, 1), 'message_id') === array_column($successfulRows, 'target_message_id'));

    foreach (['run_id'=>'123', 'application_sha'=>str_repeat('f', 40)] as $field => $otherValue) {
        $wrongContext = array_replace($context, [$field=>$otherValue]);
        verify("different $field rejected", rejectsInvalidReceipt(fn() => $select($receipt, $summary, $wrongContext)));
    }
    $resumeConstant = 'AnyTour\\ExistingPostBatch\\RANKED_RESUME_OPERATION';
    if (defined($resumeConstant)) {
        $resumeOperation = constant($resumeConstant);
        $resumeCheck = array_replace($check, ['operation'=>$resumeOperation]);
        $resumeReceipt = array_replace($receipt, ['operation'=>$resumeOperation]);
        $resumeSummary = array_replace($summary, ['operation'=>$resumeOperation]);
        $resumeContext = array_replace($context, ['write_operation'=>'hotel_ranked_resume']);
        $resumeReceipt['results'][2] = array_replace($resumeReceipt['results'][2], [
            'provider_write_attempts'=>0, 'state'=>'held_before_provider', 'prior_terminal_state'=>'native_and_registry_verified',
        ]);
        --$resumeReceipt['provider_write_attempts'];
        $resume = verifiedCandidates($plan, $resumeCheck, $resumeReceipt, $resumeSummary, $resumeContext);
        verify('resume excludes prior verified writes from fresh readback', $resume === $candidates);
    }
    $operation = \AnyTour\ExistingPostBatch\RANKED_UNREACHED_OPERATION;
    $tail = array_slice($rankedRows, -2);
    $tailCheck = array_replace($check, ['operation'=>$operation, 'parent_run_id'=>'12344', 'rows'=>array_slice($check['rows'], -2)]);
    $tailNative = array_replace($receipt['results'][0], ['hotel_id'=>$tail[0]['hotel_id'],
        'message_id'=>$tail[0]['target_message_id'], 'url'=>$tailCheck['rows'][0]['target_url']]);
    $tailResult = array_replace($receipt, ['operation'=>$operation, 'parent_run_id'=>'12344',
        'results'=>[$tailNative], 'provider_write_attempts'=>1]);
    $tailSummary = array_replace($summary, ['operation'=>$operation, 'hotel_ids'=>array_column($tail, 'hotel_id'),
        'message_ids'=>array_column($tail, 'target_message_id'), 'unreached'=>[$tail[1]['hotel_id']],
        'confirmed'=>[$tail[0]['hotel_id']], 'held'=>[]]);
    $tailContext = array_replace($context, ['write_operation'=>'hotel_ranked_unreached', 'parent_run_id'=>'12344']);
    verify('success from the authenticated untouched suffix can be publicly verified',
        verifiedCandidates($plan, $tailCheck, $tailResult, $tailSummary, $tailContext) === [[$tail[0], $tailNative]]);
    verify('a different parent cannot supply the public report', rejectsInvalidReceipt(
        fn() => verifiedCandidates($plan, $tailCheck, $tailResult, $tailSummary, array_replace($tailContext, ['parent_run_id'=>'54321']))));
    $swapped = $tailSummary;
    $swapped['message_ids'][1] = $rankedRows[0]['target_message_id'];
    verify('an unvisited row with a wrong message ID invalidates suffix provenance', rejectsInvalidReceipt(
        fn() => verifiedCandidates($plan, $tailCheck, $tailResult, $swapped, $tailContext)));
    $wrongPost = $receipt;
    $wrongPost['results'][0]['url'] = 'https://t.me/other/316';
    verify('wrong post address rejected', rejectsInvalidReceipt(fn() => $select($wrongPost, $summary, $context)));

    $unverified = $receipt;
    $unverified['results'][1]['hotel_id_verified'] = false;
    verify('unverified hotel identity rejected', rejectsInvalidReceipt(fn() => $select($unverified, $summary, $context)));

    $incomplete = array_replace($summary, ['state'=>'invoked_requires_readback']);
    verify('inconclusive receipt rejected', rejectsInvalidReceipt(fn() => $select($receipt, $incomplete, $context)));
    $wrongSummary = $summary;
    $wrongSummary['confirmed'][] = $rankedRows[2]['hotel_id'];
    verify('unknown cannot enter confirmed', rejectsInvalidReceipt(fn() => $select($receipt, $wrongSummary, $context)));

    $emptyReceipt = array_replace($receipt, ['results'=>[], 'provider_write_attempts'=>0]);
    $emptySummary = array_replace($summary, ['held'=>[], 'confirmed'=>[], 'unreached'=>$hotelIds]);
    $emptyCandidates = $select($emptyReceipt, $emptySummary, $context);
    $reads = 0;
    $countReads = static function () use (&$reads): string { ++$reads; return ''; };
    $empty = inspectPublic($emptyCandidates, $digest, $countReads);
    verify('valid zero result performs zero public requests', $reads === 0 && $empty['verified_destinations'] === []);

    $pages = [];
    foreach ($candidates as [$planned, $native]) {
        $pages[$native['message_id']] = publicPageFixture($planned, $native);
    }
    $all = inspectPublic($candidates, $digest, fn(string $id): string => $pages[$id]);
    verify('all matching HTML pages accepted', $all['public_verified'] === count($successfulRows));

    [$delayedPlan, $delayedNative] = $candidates[0];
    $previousCaption = 'Original commercial post';
    $delayedPlan['target_caption_sha256'] = hash('sha256', $previousCaption);
    $delayedPlan['target_photo_marker_sha256'] = hash('sha256', 'tgme_widget_message_photo_wrap 88888888');
    $previousPage = publicPageFixture(array_replace($delayedPlan, ['next_caption'=>$previousCaption]), $delayedNative);
    $previousPage = str_replace('99999999', '88888888', $previousPage);
    $previousPage = preg_replace('~<a class="tgme_widget_message_inline_button"[^>]*>.*?</a>~', '', $previousPage);

    $delayedCandidates = $candidates;
    $delayedCandidates[0] = [$delayedPlan, $delayedNative];
    $attempts = [];
    $waits = [];
    $recordWait = static function (int $seconds) use (&$waits): void { $waits[] = $seconds; };
    $readDelayed = static function (string $message) use ($pages, $previousPage, $delayedNative, &$attempts): string {
        $attempts[$message] = ($attempts[$message] ?? 0) + 1;
        if ($message === $delayedNative['message_id'] && $attempts[$message] === 1) return $previousPage;
        return $pages[$message];
    };
    $afterPropagation = inspectPublic($delayedCandidates, $digest, $readDelayed, $recordWait);
    verify('only the exact earlier snapshot is read again and then admitted',
        $afterPropagation['public_verified'] === count($candidates) && $waits === [30]
        && $attempts[$delayedNative['message_id']] === 2 && array_sum($attempts) === count($candidates) + 1
        && $afterPropagation['results'][0]['earlier_public_html_sha256'] === [hash('sha256', $previousPage)]);

    $waits = [];
    $remaining = inspectPublic([[$delayedPlan, $delayedNative]], $digest, fn(): string => $previousPage, $recordWait);
    verify('old snapshot stays unverified after four bounded rechecks',
        $remaining['public_verified'] === 0 && $remaining['public_unverified'] === 1 && $waits === [30, 45, 60, 60]
        && count($remaining['results'][0]['earlier_public_html_sha256']) === 4);

    $waits = [];
    $unexpected = inspectPublic([[$delayedPlan, $delayedNative]], $digest,
        fn(): string => str_replace($previousCaption, 'Another update', $previousPage), $recordWait);
    verify('a different caption and old photo do not trigger retries',
        $unexpected['public_verified'] === 0 && $waits === [] && !isset($unexpected['results'][0]['earlier_public_html_sha256']));

    $waits = []; $reads = 0;
    $unavailableAfterOld = inspectPublic([[$delayedPlan, $delayedNative]], $digest,
        static function () use ($previousPage, &$reads): string {
            if (++$reads === 1) return $previousPage;
            throw new RuntimeException('public_history_unavailable');
        }, $recordWait);
    verify('a refused later read stops further waits and keeps the prior evidence',
        $unavailableAfterOld['public_verified'] === 0 && $reads === 2 && $waits === [30]
        && count($unavailableAfterOld['results'][0]['earlier_public_html_sha256']) === 1);

    $thirdMessage = $successfulRows[2]['target_message_id'];
    $readWithFailure = static function (string $message) use ($pages, $thirdMessage): string {
        if ($message === $thirdMessage) throw new RuntimeException('public_history_unavailable');
        return $pages[$message];
    };
    $partial = inspectPublic($candidates, $digest, $readWithFailure);
    $expectedHotels = array_column($successfulRows, 'hotel_id');
    array_splice($expectedHotels, 2, 1);
    verify('failed read in the middle preserves both earlier and later destinations',
        array_column($partial['verified_destinations'], 'hotel_id') === $expectedHotels && $partial['public_unverified'] === 1);

    $changes = [
        'button'=>fn(string $page): string => str_replace('anyru_bot', 'another_bot', $page),
        'caption'=>fn(string $page): string => str_replace('class="tgme_widget_message_text">', 'class="tgme_widget_message_text">X', $page),
        'date'=>fn(string $page): string => preg_replace('/datetime="[^"]+"/', 'datetime="2020-01-01T00:00:00Z"', $page),
        'photo'=>fn(string $page): string => str_replace('tgme_widget_message_photo_wrap', 'missing_photo', $page),
    ];
    $secondMessage = $successfulRows[1]['target_message_id'];
    foreach ($changes as $field => $changePage) {
        $changedPages = $pages;
        $changedPages[$secondMessage] = $changePage($pages[$secondMessage]);
        $report = inspectPublic($candidates, $digest, fn(string $id): string => $changedPages[$id]);
        $verifiedHotels = array_column($report['verified_destinations'], 'hotel_id');
        verify("wrong $field excludes only its hotel",
            count($verifiedHotels) === count($successfulRows)-1 && !in_array($successfulRows[1]['hotel_id'], $verifiedHotels, true));
    }
}
