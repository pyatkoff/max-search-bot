<?php

declare(strict_types=1);

$service = (string) file_get_contents(dirname(__DIR__) . '/services/CutoverLegacyHostDependency.php');
$tool = (string) file_get_contents(dirname(__DIR__) . '/tools/cutover_legacy_host_dependency.php');

function cutoverLegacyAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

cutoverLegacyAssert(strpos($service, 'LeadBridgeConfig::receiverUrl()') !== false, 'detector must inspect configured lead receiver');
cutoverLegacyAssert(strpos($service, "'anytour.online'") !== false, 'detector must recognize canonical legacy host');
cutoverLegacyAssert(strpos($service, "'lead_bridge'") !== false, 'detector must identify lead bridge dependency');
cutoverLegacyAssert(strpos($tool, "PHP_SAPI !== 'cli'") !== false, 'diagnostic must remain CLI-only');
cutoverLegacyAssert(strpos($tool, 'LEGACY_HOST_DEPENDENCY=') !== false, 'diagnostic must expose dependency result');
cutoverLegacyAssert(strpos($tool, 'LEAD_RECEIVER_HOST=') !== false, 'diagnostic must expose receiver host without secrets');
cutoverLegacyAssert(strpos($tool, 'MAX_SEARCH_LEAD_BRIDGE_SECRET') === false, 'diagnostic must never expose bridge secret');
cutoverLegacyAssert(strpos($tool, 'CONVERSATION_DB_PASS') === false, 'diagnostic must never expose DB password');

echo "OK cutover legacy host dependency regression\n";
