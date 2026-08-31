<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/services/StandaloneReadiness.php';

function sr_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$ready = StandaloneReadiness::assess([
    'standalone_runtime_enabled' => true,
    'runtime_storage' => 'mysql',
    'destination_storage' => 'mysql',
    'conversation_db_configured' => true,
    'catalog_db_configured' => true,
    'lead_delivery' => 'bridge',
    'lead_bridge_url_configured' => true,
    'lead_bridge_secret_configured' => true,
]);
sr_assert($ready['ready'] === true, 'fully standalone facts must be ready');
sr_assert($ready['blockers'] === [], 'ready assessment must have no blockers');

$blocked = StandaloneReadiness::assess([
    'standalone_runtime_enabled' => true,
    'runtime_storage' => 'mysql',
    'destination_storage' => 'mysql',
    'conversation_db_configured' => true,
    'catalog_db_configured' => true,
    'lead_delivery' => 'bitrix',
]);
sr_assert($blocked['ready'] === false, 'Bitrix lead delivery must block no-Bitrix cutover');
sr_assert(in_array('lead_delivery_standalone_safe', $blocked['blockers'], true), 'lead-delivery blocker must be explicit');

$bridgeMissingSecret = StandaloneReadiness::assess([
    'standalone_runtime_enabled' => true,
    'runtime_storage' => 'mysql',
    'destination_storage' => 'mysql',
    'conversation_db_configured' => true,
    'catalog_db_configured' => true,
    'lead_delivery' => 'bridge',
    'lead_bridge_url_configured' => true,
    'lead_bridge_secret_configured' => false,
]);
sr_assert($bridgeMissingSecret['ready'] === false, 'bridge mode without secret must not report ready');
sr_assert(in_array('lead_bridge_configured', $bridgeMissingSecret['blockers'], true), 'bridge configuration blocker must be explicit');

$unsupported = StandaloneReadiness::assess([
    'standalone_runtime_enabled' => true,
    'runtime_storage' => 'mysql',
    'destination_storage' => 'mysql',
    'conversation_db_configured' => true,
    'catalog_db_configured' => true,
    'lead_delivery' => 'standalone',
    'lead_bridge_url_configured' => true,
    'lead_bridge_secret_configured' => true,
]);
sr_assert($unsupported['ready'] === false, 'unknown non-Bitrix driver must not be treated as standalone-safe');

$legacy = StandaloneReadiness::assess([]);
sr_assert($legacy['ready'] === false, 'legacy defaults must not report standalone-ready');
sr_assert(in_array('standalone_runtime_enabled', $legacy['blockers'], true), 'standalone runtime flag must be required');
sr_assert(in_array('runtime_storage_mysql', $legacy['blockers'], true), 'MySQL runtime storage must be required');
sr_assert(in_array('destination_storage_mysql', $legacy['blockers'], true), 'MySQL destination storage must be required');

$tool = (string)file_get_contents(dirname(__DIR__) . '/tools/standalone_readiness.php');
sr_assert(strpos($tool, 'STANDALONE_READY=') !== false, 'CLI doctor must emit a stable readiness marker');
sr_assert(strpos($tool, 'CONVERSATION_DB_PASS') !== false, 'CLI doctor must validate conversation DB configuration');
sr_assert(strpos($tool, 'MAX_SEARCH_LEAD_RECEIVER_URL') !== false, 'CLI doctor must validate lead receiver URL presence');
sr_assert(strpos($tool, 'MAX_SEARCH_LEAD_BRIDGE_SECRET') !== false, 'CLI doctor must validate lead bridge secret presence');
sr_assert(strpos($tool, 'fwrite(STDOUT, (string)CONVERSATION_DB_PASS') === false, 'CLI doctor must never print DB password values');
sr_assert(strpos($tool, 'fwrite(STDOUT, (string)MAX_SEARCH_LEAD_BRIDGE_SECRET') === false, 'CLI doctor must never print lead bridge secret values');

echo "OK standalone readiness regression\n";
