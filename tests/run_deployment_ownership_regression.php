<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$production = (string)file_get_contents($root . '/.github/workflows/deploy.yml');
$diagnostics = (string)file_get_contents($root . '/.github/workflows/publish-conversation-diagnostics.yml');
$standby = (string)file_get_contents($root . '/.github/workflows/deploy-standby.yml');

function deploymentOwnershipAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

deploymentOwnershipAssert(substr_count($production, 'php tools/conversation_db.php migrate') === 1, 'production deploy must be the single automatic migration owner');
deploymentOwnershipAssert(str_contains($production, "push:\n    branches: [main]"), 'production deploy must remain automatic on main');

deploymentOwnershipAssert(!str_contains($diagnostics, 'php tools/conversation_db.php migrate'), 'diagnostics must remain read-only');
deploymentOwnershipAssert(str_contains($diagnostics, 'workflow_run:'), 'production diagnostics must follow deploy completion');
deploymentOwnershipAssert(str_contains($diagnostics, 'workflows: [Deploy production]'), 'production diagnostics must follow the canonical deploy workflow');
deploymentOwnershipAssert(str_contains($diagnostics, "github.event.workflow_run.conclusion == 'success'"), 'production diagnostics must require successful deploy');
deploymentOwnershipAssert(str_contains($diagnostics, 'TARGET_SHA:'), 'production diagnostics must bind to the deployed SHA');

deploymentOwnershipAssert(str_contains($standby, 'workflow_dispatch:'), 'standby recovery must remain manually available');
deploymentOwnershipAssert(!str_contains($standby, "push:\n    branches: [main]"), 'retired standby deploy must not race every main push');

echo "deployment ownership contract OK\n";
