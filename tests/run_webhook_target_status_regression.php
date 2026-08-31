<?php

declare(strict_types=1);

$tool = (string)file_get_contents(__DIR__ . '/../tools/webhook_target_status.php');
foreach (['WebhookTargetConfig::telegram()', 'WebhookTargetConfig::max()', 'TELEGRAM_WEBHOOK_TARGET_HOST=', 'MAX_WEBHOOK_TARGET_HOST='] as $needle) {
    if (!str_contains($tool, $needle)) {
        fwrite(STDERR, "Missing webhook target diagnostic contract: {$needle}\n");
        exit(1);
    }
}
if (preg_match('/TOKEN|SECRET|PASS|PASSWORD/', $tool)) {
    fwrite(STDERR, "Webhook target diagnostic may expose secrets\n");
    exit(1);
}
echo "webhook target status regression OK\n";