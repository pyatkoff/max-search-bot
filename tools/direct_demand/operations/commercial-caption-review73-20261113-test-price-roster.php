<?php
declare(strict_types=1);
// Pure contract fixture: reads local files, calls existing pure validators only.
// It performs no transport, DB work, journal mutation or collector invocation.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$checks = 0;
function proof_need(bool $ok, string $why): void {
    global $checks;
    $checks++;
    if (!$ok) throw new RuntimeException($why);
}
proof_need($argc === 4, 'usage_source_directory_roster_json_fixture_json');
$source = rtrim($argv[1], '/');
$rosterRaw = file_get_contents($argv[2]); $fixtureRaw = file_get_contents($argv[3]);
proof_need(is_string($rosterRaw) && is_string($fixtureRaw), 'fixture_files_missing');
$roster = json_decode($rosterRaw, true, 128, JSON_THROW_ON_ERROR);
$fixture = json_decode($fixtureRaw, true, 128, JSON_THROW_ON_ERROR);
$pins = [
    'monitor-price-contract.php' => 'ad20601d265e71d6cf58253bb6f263c1171a299130317f9ebbf7e576cf874fa7',
    'monitor-price-hook-contract.php' => '636c8e330009782d163e763d5c0d18859339f16085409d75cd1bbdb5de7834b2',
];
foreach ($pins as $name => $pin) {
    proof_need(hash_file('sha256', $source.'/'.$name) === $pin
        && ($fixture['source_contract_pins'][$name] ?? null) === $pin, 'existing_contract_changed');
}
require_once $source.'/monitor-price-hook-contract.php';
proof_need(($fixture['schema_version'] ?? null) === 1 && ($fixture['fixture_only'] ?? null) === true
    && hash('sha256', $rosterRaw) === ($fixture['updated_roster_sha256'] ?? null)
    && count($roster['rows']) === 604 && count($fixture['cases']) === 73 && count($fixture['template_only_cases']) === 3, 'fixture_scope_changed');
$rows = []; $specialCounts = ['legacy' => 0, 'caption' => 0];
foreach ($roster['rows'] as $row) {
    $tv = $row['tourvisor_hotel_id'];
    proof_need(is_int($tv) && $tv > 0 && !isset($rows[$tv]), 'roster_identity_duplicate');
    $rows[$tv] = $row;
    if (isset($row['legacy_proof'])) { monitor_price_legacy_binding($row); $specialCounts['legacy']++; }
    if (isset($row['caption_proof'])) { monitor_price_caption_binding($row); $specialCounts['caption']++; }
}
proof_need($specialCounts === ['legacy' => 5, 'caption' => 3], 'special_proof_scope_changed');
$seen = []; $priced = 0; $unpriced = 0; $drafts = 0; $maximum = 0; $negativeChecks = 0;
foreach ($fixture['cases'] as $case) {
    $tv = $case['tourvisor_hotel_id']; $row = $rows[$tv] ?? null;
    proof_need(is_array($row) && !isset($seen[$tv]) && !array_key_exists('legacy_proof', $row) && !array_key_exists('caption_proof', $row), 'target_proof_scope_changed');
    $seen[$tv] = true;
    $isPriced = $case['priced']; $prior = $case['prior'];
    proof_need(is_bool($isPriced) && ($isPriced ? is_array($prior) : $prior === null), 'prior_fixture_changed');
    if ($isPriced) $priced++; else $unpriced++;
    foreach (['max', 'telegram'] as $platform) {
        $publication = $case['publications'][$platform]; $text = $publication['snapshot']['text'];
        proof_need(hash('sha256', $text) === $row['expected_text_sha256'][$platform]
            && $publication['snapshot']['post_url'] === $row['posts'][$platform], 'current_context_changed');
        proof_need(monitor_hook_caption_allowed($publication, $row, $platform, $prior), 'confirmed_editor_baseline_not_admitted');
        $template = $row['templates'][$platform];
        proof_need(str_ends_with($template, "\n\nУзнать стоимость тура")
            && substr_count($template, 'Узнать стоимость тура') === 1, 'neutral_cta_changed');
        $draft = monitor_hook_draft($template, $case['probe_price']);
        monitor_price_validate_text($draft, $case['probe_price']);
        proof_need(str_starts_with($draft, $case['expected_bodies'][$platform]."\n\n💰 От ")
            && str_ends_with($draft, "\n\nУзнать стоимость тура"), 'editorial_description_lost');
        $maximum = max($maximum, (int)(strlen(mb_convert_encoding($draft, 'UTF-16LE', 'UTF-8')) / 2));
        $drafts++;

        $changed = $publication; $changed['snapshot']['text'] .= "\nChanged";
        proof_need(!monitor_hook_caption_allowed($changed, $row, $platform, $prior), 'changed_caption_was_admitted'); $negativeChecks++;
        if ($isPriced) {
            proof_need(isset($row['caption_correction_baseline'])
                && monitor_hook_caption_correction_allowed($publication, $row, $platform), 'exact_baseline_missing');
            $without = $row; unset($without['caption_correction_baseline']);
            proof_need(!monitor_hook_caption_allowed($publication, $without, $platform, $prior), 'missing_baseline_was_admitted'); $negativeChecks++;
            proof_need(!monitor_hook_caption_allowed($publication, $row, $platform, null), 'missing_prior_was_admitted'); $negativeChecks++;
            $changed = $publication; $changed['version_id']++;
            proof_need(!monitor_hook_caption_allowed($changed, $row, $platform, $prior), 'unknown_version_was_admitted'); $negativeChecks++;
            $changed = $publication; $changed['snapshot']['metadata']['caption_correction_operation'] .= '-unknown';
            proof_need(!monitor_hook_caption_allowed($changed, $row, $platform, $prior), 'unknown_operation_was_admitted'); $negativeChecks++;
            $changed = $publication; unset($changed['snapshot']['metadata']['monitor_price_record_id']);
            proof_need(!monitor_hook_caption_allowed($changed, $row, $platform, $prior), 'lost_monitor_record_was_admitted'); $negativeChecks++;
            $bad = $prior; $bad['complete'] = false;
            proof_need(!monitor_hook_caption_allowed($publication, $row, $platform, $bad), 'incomplete_prior_was_admitted'); $negativeChecks++;
            $bad = $prior; $bad['feed_binding']['destinations']['max'] .= '-changed';
            proof_need(!monitor_hook_caption_allowed($publication, $row, $platform, $bad), 'changed_feed_binding_was_admitted'); $negativeChecks++;
            $bad = $prior;
            foreach ($bad['posts'] as &$entry) $entry['state'] = 'unknown';
            unset($entry);
            proof_need(!monitor_hook_caption_allowed($publication, $row, $platform, $bad), 'unknown_prior_was_admitted'); $negativeChecks++;
            $bad = $prior;
            foreach ($bad['posts'] as &$entry) if ($entry['receipt']['platform'] === $platform) $entry['receipt']['text'] .= 'changed';
            unset($entry);
            proof_need(!monitor_hook_caption_allowed($publication, $row, $platform, $bad), 'corrupt_prior_was_admitted'); $negativeChecks++;
            $bad = $row; $bad['caption_correction_baseline'][$platform]['publication_id']++;
            proof_need(!monitor_hook_caption_allowed($publication, $bad, $platform, $prior), 'foreign_baseline_was_admitted'); $negativeChecks++;
        } else {
            proof_need(!array_key_exists('caption_correction_baseline', $row)
                && ($publication['snapshot']['metadata']['monitor_price_record_id'] ?? null) === null, 'unpriced_baseline_was_added');
            $bad = $row; $bad['caption_correction_baseline'] = [];
            proof_need(!monitor_hook_caption_allowed($publication, $bad, $platform, null), 'unpriced_blanket_baseline_was_admitted'); $negativeChecks++;
        }
    }
}
proof_need(count($seen) === 73 && $priced === 27 && $unpriced === 46 && $drafts === 146 && $maximum <= 1024, 'runtime_fixture_count_changed');
$ctaOnly = []; $ctaRejections = 0; $ctaDrafts = 0;
foreach ($fixture['template_only_cases'] as $case) {
    $tv = $case['tourvisor_hotel_id'];
    proof_need(in_array($tv, [2569, 22660, 73343], true) && !isset($seen[$tv]) && !isset($ctaOnly[$tv]), 'template_only_scope_changed');
    $ctaOnly[$tv] = true;
    foreach (['max', 'telegram'] as $platform) {
        $change = $case['platforms'][$platform]; $before = $change['before_template']; $after = $change['after_template'];
        $badClosing = '✈️ Рассчитаем полную стоимость тура с перелётом на ваши даты.';
        proof_need(str_ends_with($before, "\n\n".$badClosing)
            && $after === substr($before, 0, -strlen($badClosing)).'Узнать стоимость тура'
            && $after === $rows[$tv]['templates'][$platform], 'template_only_body_changed');
        $rejected = false;
        try { monitor_hook_draft($before, $case['probe_price']); }
        catch (RuntimeException $error) { $rejected = $error->getMessage() === 'monitor_text_conditions_mismatch'; }
        proof_need($rejected, 'original_cta_failure_not_reproduced'); $ctaRejections++;
        $draft = monitor_hook_draft($after, $case['probe_price']);
        monitor_price_validate_text($draft, $case['probe_price']);
        $maximum = max($maximum, (int)(strlen(mb_convert_encoding($draft, 'UTF-16LE', 'UTF-8')) / 2));
        $ctaDrafts++;
    }
}
proof_need(count($ctaOnly) === 3 && $ctaDrafts === 6 && $ctaRejections === 6 && $maximum <= 1024, 'template_only_fixture_count_changed');
echo json_encode(['ok' => true, 'fixture_only' => true, 'existing_contract_pins' => $pins,
    'original_caption_run_id' => $fixture['original_caption_run_id'], 'completion_caption_run_id' => $fixture['completion_caption_run_id'],
    'roster_sha256' => hash('sha256', $rosterRaw), 'fixture_sha256' => hash('sha256', $fixtureRaw),
    'editorial_pairs' => 73, 'template_only_pairs' => 3, 'drafts_validated' => $drafts + $ctaDrafts, 'original_cta_failures_reproduced' => $ctaRejections,
    'priced_baseline_pairs' => $priced, 'unpriced_pairs_without_baseline' => $unpriced,
    'special_proofs_validated' => $specialCounts, 'maximum_probe_utf16_units' => $maximum,
    'negative_admission_checks' => $negativeChecks, 'assertions' => $checks,
    'supplier_calls' => 0, 'provider_calls' => 0, 'database_calls' => 0, 'external_writes' => 0],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
