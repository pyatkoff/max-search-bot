<?php

declare(strict_types=1);

define('MAX_SEARCH_TOKEN', 'test-max-token');
require_once dirname(__DIR__) . '/services/StandaloneReadiness.php';
require_once dirname(__DIR__) . '/services/LeadBridgeConfig.php';
require_once dirname(__DIR__) . '/services/CatalogIdCompatibility.php';

function sr_assert(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

sr_assert(LeadBridgeConfig::receiverUrl() === 'https://anytour.online/max-search/lead-receiver.php', 'bridge must have canonical legacy receiver fallback');
sr_assert(LeadBridgeConfig::secret() !== '', 'bridge must derive a domain-separated HMAC key from the existing shared MAX token when no dedicated secret is configured');
sr_assert(LeadBridgeConfig::secret() !== MAX_SEARCH_TOKEN, 'derived bridge key must not equal the raw MAX token');
sr_assert(CatalogIdCompatibility::requiredDepartureIds() === [1,5,10,12], 'canonical departure IDs must match the dialogue contract');
sr_assert(CatalogIdCompatibility::requiredCountryIds() === [1,2,4,8,9,12], 'canonical country IDs must match the dialogue contract');

$ready = StandaloneReadiness::assess([
    'standalone_runtime_enabled' => true,'runtime_storage' => 'mysql','destination_storage' => 'mysql',
    'conversation_db_configured' => true,'catalog_db_configured' => true,'catalog_id_compatibility' => true,'lead_delivery' => 'bridge',
    'lead_bridge_url_configured' => true,'lead_bridge_secret_configured' => true,
]);
sr_assert($ready['ready'] === true, 'fully standalone facts must be ready');

$catalogBlocked = StandaloneReadiness::assess([
    'standalone_runtime_enabled' => true,'runtime_storage' => 'mysql','destination_storage' => 'mysql',
    'conversation_db_configured' => true,'catalog_db_configured' => true,'catalog_id_compatibility' => false,'lead_delivery' => 'bridge',
    'lead_bridge_url_configured' => true,'lead_bridge_secret_configured' => true,
]);
sr_assert($catalogBlocked['ready'] === false, 'catalog ID mismatch must block cutover');
sr_assert(in_array('catalog_id_compatibility', $catalogBlocked['blockers'], true), 'catalog ID blocker must be explicit');

$blocked = StandaloneReadiness::assess([
    'standalone_runtime_enabled' => true,'runtime_storage' => 'mysql','destination_storage' => 'mysql',
    'conversation_db_configured' => true,'catalog_db_configured' => true,'catalog_id_compatibility' => true,'lead_delivery' => 'bitrix',
    'lead_bridge_url_configured' => true,'lead_bridge_secret_configured' => true,
]);
sr_assert($blocked['ready'] === false, 'Bitrix lead delivery must block no-Bitrix cutover');
sr_assert(in_array('lead_delivery_standalone_safe', $blocked['blockers'], true), 'lead-delivery blocker must be explicit');

$missingBridge = StandaloneReadiness::assess([
    'standalone_runtime_enabled' => false,'runtime_storage' => 'legacy','destination_storage' => 'bitrix',
    'conversation_db_configured' => true,'catalog_db_configured' => true,'catalog_id_compatibility' => true,'lead_delivery' => 'bitrix',
    'lead_bridge_url_configured' => false,'lead_bridge_secret_configured' => false,
]);
sr_assert(in_array('lead_bridge_configured', $missingBridge['blockers'], true), 'bridge prerequisites must be reported before the driver is switched');

$unsupported = StandaloneReadiness::assess([
    'standalone_runtime_enabled' => true,'runtime_storage' => 'mysql','destination_storage' => 'mysql',
    'conversation_db_configured' => true,'catalog_db_configured' => true,'catalog_id_compatibility' => true,'lead_delivery' => 'standalone',
    'lead_bridge_url_configured' => true,'lead_bridge_secret_configured' => true,
]);
sr_assert($unsupported['ready'] === false, 'unknown non-Bitrix driver must not be standalone-safe');

$legacy = StandaloneReadiness::assess([]);
sr_assert($legacy['ready'] === false, 'legacy defaults must not report standalone-ready');
sr_assert(in_array('standalone_runtime_enabled', $legacy['blockers'], true), 'standalone runtime flag must be required');
sr_assert(in_array('runtime_storage_mysql', $legacy['blockers'], true), 'MySQL runtime storage must be required');
sr_assert(in_array('destination_storage_mysql', $legacy['blockers'], true), 'MySQL destination storage must be required');
sr_assert(in_array('catalog_id_compatibility', $legacy['blockers'], true), 'catalog ID compatibility must be required');
sr_assert(in_array('lead_bridge_configured', $legacy['blockers'], true), 'missing bridge prerequisites must never be hidden');

$tool = (string)file_get_contents(dirname(__DIR__) . '/tools/standalone_readiness.php');
sr_assert(strpos($tool, 'STANDALONE_READY=') !== false, 'CLI doctor must emit a stable readiness marker');
sr_assert(strpos($tool, 'CONVERSATION_DB_PASS') !== false, 'CLI doctor must validate conversation DB configuration');
sr_assert(strpos($tool, 'CatalogIdCompatibility::inspect()') !== false, 'CLI doctor must inspect canonical catalogue IDs');
sr_assert(strpos($tool, 'CATALOG_MISSING_DEPARTURE_IDS=') !== false, 'CLI doctor must expose missing departure IDs without secrets');
sr_assert(strpos($tool, 'CATALOG_MISSING_COUNTRY_IDS=') !== false, 'CLI doctor must expose missing country IDs without secrets');
sr_assert(strpos($tool, 'LeadBridgeConfig::receiverUrl()') !== false, 'CLI doctor must use shared receiver resolution');
sr_assert(strpos($tool, 'LeadBridgeConfig::secret()') !== false, 'CLI doctor must use shared bridge auth resolution');
sr_assert(strpos($tool, 'fwrite(STDOUT, (string)CONVERSATION_DB_PASS') === false, 'CLI doctor must never print DB password values');

$catalogSource = (string)file_get_contents(dirname(__DIR__) . '/services/CatalogIdCompatibility.php');
sr_assert(strpos($catalogSource, 'catalog_departures') !== false, 'catalog compatibility must query departures directly');
sr_assert(strpos($catalogSource, 'catalog_countries') !== false, 'catalog compatibility must query countries directly');
sr_assert(strpos($catalogSource, 'UF_DEPID') !== false && strpos($catalogSource, 'UF_CID') !== false, 'catalog compatibility must document legacy ID semantics');

echo "OK standalone readiness regression\n";
