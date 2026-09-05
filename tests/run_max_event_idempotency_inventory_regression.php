<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/services/IncomingUpdateDeduplicator.php';

$contract = json_decode((string)file_get_contents($root . '/docs/max-event-idempotency-contract.json'), true);
$deduplicator = (string)file_get_contents($root . '/services/IncomingUpdateDeduplicator.php');
$handler = (string)file_get_contents($root . '/handlers/MaxUpdateHandler.php');
$callback = (string)file_get_contents($root . '/services/CallbackController.php');
$guard = (string)file_get_contents($root . '/services/InteractionGuard.php');
$recorder = (string)file_get_contents($root . '/services/ConversationRecorder.php');
$migration = (string)file_get_contents($root . '/migrations/001_conversation_core.sql');
$managerCallback = (string)file_get_contents($root . '/actions/callbacks/ManagerCallbackAction.php');
$managerAction = (string)file_get_contents($root . '/actions/ManagerAction.php');
$sourceHandling = (string)file_get_contents($root . '/services/SourceHandlingService.php');
$failed = 0;

function maxIdempotencyCheck(string $name, bool $ok): void
{
    global $failed;
    if ($ok) { echo "PASS  {$name}\n"; return; }
    echo "FAIL  {$name}\n";
    $failed++;
}

maxIdempotencyCheck('inventory is read only and authorizes no runtime or migration', is_array($contract) && ($contract['schema_version'] ?? null) === 1 && ($contract['scope'] ?? null) === 'read_only_inventory' && ($contract['transport'] ?? null) === 'max' && empty($contract['runtime_change_authorized']) && empty($contract['migration_authorized']));

$callbackUpdate = ['update_type'=>'message_callback','callback'=>['callback_id'=>'callback-42']];
$messageUpdate = ['update_type'=>'message_created','message'=>['body'=>['mid'=>'message-42']]];
$startedUpdate = ['update_type'=>'bot_started','user'=>['user_id'=>42],'timestamp'=>1720000000,'payload'=>'campaign'];
maxIdempotencyCheck('callback key uses the external callback id', IncomingUpdateDeduplicator::key($callbackUpdate) === 'callback:callback-42' && ($contract['event_contracts']['message_callback']['key'] ?? null) === 'callback:<callback.callback_id>');
maxIdempotencyCheck('message key uses the external message mid', IncomingUpdateDeduplicator::key($messageUpdate) === 'message:message-42' && ($contract['event_contracts']['message_created']['key'] ?? null) === 'message:<message.body.mid>');
$expectedStarted = 'bot_started:' . hash('sha256', '42|1720000000|campaign');
maxIdempotencyCheck('bot started key uses the stable current composite', IncomingUpdateDeduplicator::key($startedUpdate) === $expectedStarted && ($contract['event_contracts']['bot_started']['key'] ?? null) === 'bot_started:sha256(user_id|timestamp|payload)');
maxIdempotencyCheck('missing identifiers remain explicitly fail open', IncomingUpdateDeduplicator::key(['update_type'=>'message_created']) === '' && IncomingUpdateDeduplicator::key(['update_type'=>'message_callback']) === '' && IncomingUpdateDeduplicator::key(['update_type'=>'bot_started']) === '' && ($contract['event_contracts']['message_created']['missing_key_behavior'] ?? null) === 'fail_open');

$claim = strpos($handler, 'IncomingUpdateDeduplicator::claim($update)');
$adapter = strpos($handler, 'MaxIncomingAdapter::user($update)');
$startedEffects = strpos($handler, "if (\$type === 'bot_started' && \$userId)");
maxIdempotencyCheck('MAX handler claims before adapter dispatch and bot started effects', $claim !== false && $adapter !== false && $startedEffects !== false && $claim < $adapter && $claim < $startedEffects && ($contract['primary_owner']['claim_order'] ?? null) === 'after_secret_and_shadow_checks_before_adapter_dispatch_or_bot_started_side_effects');

maxIdempotencyCheck('local store bounds and lock remain explicit', strpos($deduplicator, 'TTL_SECONDS = 86400') !== false && strpos($deduplicator, 'MAX_ENTRIES = 5000') !== false && strpos($deduplicator, "'/max-search-bot-update-dedupe.json'") !== false && strpos($deduplicator, 'flock($fh, LOCK_EX)') !== false && ($contract['store']['ttl_seconds'] ?? null) === 86400 && ($contract['store']['max_entries'] ?? null) === 5000 && empty($contract['store']['shared_between_hosts']));
$unavailable = sys_get_temp_dir() . '/max-search-missing-parent-' . getmypid() . '/dedupe.json';
maxIdempotencyCheck('unavailable primary store fails open without creating parent', IncomingUpdateDeduplicator::claim($callbackUpdate, $unavailable) === true && !is_file($unavailable) && ($contract['store']['open_or_lock_failure_behavior'] ?? null) === 'fail_open');
maxIdempotencyCheck('claim-before-effect limitation is recorded', !empty($contract['delivery_semantics']['claim_before_business_effects']) && ($contract['delivery_semantics']['crash_after_claim_before_effect'] ?? null) === 'event_can_be_lost_until_claim_expires' && empty($contract['delivery_semantics']['cross_host_exactly_once']));

$generationClaim = strpos($guard, "\$claimedValue = 'used:' . \$generation;");
$generationDispatch = strpos($guard, '$handled = (bool)$callback();');
maxIdempotencyCheck('generated final actions retain a secondary one-shot guard', strpos($callback, "['show_tours','manager_request','edit_params']") !== false && $generationClaim !== false && $generationDispatch !== false && $generationClaim < $generationDispatch && strpos($guard, 'MaxSearchApi::saveLastValue($chatId, $generationStatus, $generation);') !== false && ($contract['secondary_guards'][0]['actions'] ?? null) === ['show_tours','manager_request','edit_params']);

maxIdempotencyCheck('manager request entry paths are fully inventoried', count((array)($contract['manager_request_paths'] ?? [])) === 3 && strpos($managerCallback, "['manager_request','manager_after_tours','phone_manual']") !== false && strpos($managerAction, 'ManagerHandoffDispatchService::dispatch') !== false && strpos($sourceHandling, 'private static function handoff') !== false);
maxIdempotencyCheck('conversation mirror is not mistaken for an atomic barrier', strpos($recorder, 'SELECT id FROM messages WHERE conversation_id=? AND direction=? AND external_message_id=? LIMIT 1') !== false && strpos($recorder, 'INSERT INTO messages') !== false && strpos($migration, 'KEY idx_messages_external (channel, external_message_id)') !== false && strpos($migration, 'UNIQUE KEY idx_messages_external') === false && empty($contract['conversation_mirror']['atomic_uniqueness_guarantee']) && ($contract['conversation_mirror']['role'] ?? null) === 'best_effort_observability_not_business_event_idempotency_owner');

$gaps = (array)($contract['known_gaps'] ?? []);
maxIdempotencyCheck('known durability and crash gaps are explicit', in_array('the_local_tmp_store_does_not_coordinate_multiple_active_hosts', $gaps, true) && in_array('host_replacement_can_remove_the_claim_store', $gaps, true) && in_array('claim_before_effect_can_suppress_a_retry_after_a_process_crash', $gaps, true) && in_array('unversioned_and_message_driven_manager_requests_have_no_secondary_entrypoint_idempotency_key', $gaps, true));

$protected = (array)($contract['protected_non_goals'] ?? []);
maxIdempotencyCheck('protected product and release boundaries remain frozen', in_array('Yandex Metrica counters goals or goal semantics', $protected, true) && in_array('lead delivery destination or mechanism', $protected, true) && in_array('manager shifts or is_working state', $protected, true) && in_array('routing eligibility or bonuses', $protected, true) && in_array('URL payload or Tourvisor behavior', $protected, true) && in_array('strict MAX TLS behavior', $protected, true) && in_array('AI log boundary', $protected, true) && in_array('deploy provenance', $protected, true));

echo "\n--------------------------------\n";
echo $failed === 0 ? "MAX EVENT IDEMPOTENCY INVENTORY: OK\n" : "MAX EVENT IDEMPOTENCY INVENTORY: FAIL ({$failed})\n";
exit($failed === 0 ? 0 : 1);
