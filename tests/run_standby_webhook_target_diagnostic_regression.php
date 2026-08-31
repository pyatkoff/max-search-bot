<?php

declare(strict_types=1);

$workflow = (string)file_get_contents(__DIR__ . '/../.github/workflows/standby-webhook-target-diagnostic.yml');
foreach (['workflow_dispatch:', 'tools/webhook_target_status.php', 'STANDBY_DEPLOY_SSH_KEY', 'STANDBY_DEPLOY_HOST', 'STANDBY_DEPLOY_USER'] as $needle) {
    if (!str_contains($workflow, $needle)) {
        fwrite(STDERR, "Missing standby webhook diagnostic contract: {$needle}\n");
        exit(1);
    }
}
foreach (['setWebhook', 'deleteWebhook', '/subscriptions', 'systemctl', 'crontab'] as $forbidden) {
    if (str_contains($workflow, $forbidden)) {
        fwrite(STDERR, "Standby webhook diagnostic is not read-only: {$forbidden}\n");
        exit(1);
    }
}
echo "standby webhook target diagnostic regression OK\n";