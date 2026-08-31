<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/services/StandaloneReadiness.php';
require_once dirname(__DIR__) . '/services/RuntimeBootstrap.php';
require_once dirname(__DIR__) . '/services/RuntimeStorage.php';
require_once dirname(__DIR__) . '/services/DestinationCatalogRepository.php';
require_once dirname(__DIR__) . '/services/HotelDatabase.php';
require_once dirname(__DIR__) . '/services/LeadDeliveryGateway.php';
require_once dirname(__DIR__) . '/services/LeadBridgeConfig.php';
require_once dirname(__DIR__) . '/services/ConversationDb.php';

$conversationConfigured = defined('CONVERSATION_DB_HOST')
    && defined('CONVERSATION_DB_NAME')
    && defined('CONVERSATION_DB_USER')
    && defined('CONVERSATION_DB_PASS')
    && trim((string)CONVERSATION_DB_NAME) !== '';

$facts = [
    'standalone_runtime_enabled' => RuntimeBootstrap::isStandalone(),
    'runtime_storage' => RuntimeStorage::usesMysql() ? 'mysql' : 'legacy',
    'destination_storage' => DestinationCatalogRepository::storage(),
    'conversation_db_configured' => $conversationConfigured,
    'catalog_db_configured' => HotelDatabase::configured(),
    'lead_delivery' => LeadDeliveryGateway::driver(),
    'lead_bridge_url_configured' => LeadBridgeConfig::receiverUrl() !== '',
    'lead_bridge_secret_configured' => LeadBridgeConfig::secret() !== '',
];

$result = StandaloneReadiness::assess($facts);

fwrite(STDOUT, 'STANDALONE_READY=' . ($result['ready'] ? 'YES' : 'NO') . PHP_EOL);
foreach ($result['checks'] as $name => $ok) {
    fwrite(STDOUT, ($ok ? 'OK   ' : 'BLOCK') . ' ' . $name . PHP_EOL);
}

if (!$result['ready']) {
    fwrite(STDOUT, 'BLOCKERS=' . implode(',', $result['blockers']) . PHP_EOL);
    exit(2);
}

exit(0);
