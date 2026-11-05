#!/usr/bin/env bash
set -euo pipefail

# Application verification only. Applying the AI profile, installing code,
# migrations, webhook/cron reconciliation, log containment and diagnostic-file
# lifecycle remain owned by the canonical deployment workflow. The existing
# metadata/authentication probes may record bounded diagnostic events; this is
# not a promise of zero filesystem or telemetry writes in their dependencies.
# This command is not a release admission or a recovery authorization.
fail_target() {
  printf '%s\n' 'DEPLOY_APPLICATION_CHECK=FAILED (invalid_target)' >&2
  exit 2
}
[[ $# -eq 1 ]] || fail_target
expected_sha="$1"
[[ "$expected_sha" =~ ^[0-9a-f]{40}$ ]] || fail_target
root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)" || fail_target
cd -- "$root"
require_source() {
  local actual top
  actual="$(git rev-parse --verify 'HEAD^{commit}' 2>/dev/null)" || return 1
  top="$(git rev-parse --show-toplevel 2>/dev/null)" || return 1
  [[ "$actual" == "$expected_sha" && "$top" == "$root" ]]
}
require_source || fail_target

# Preserve the complete original application check sequence and each exit code.
# No --apply, migration, setter, cron edit, cleanup or model invocation here.
php tools/ai_profile_rollout.php --verify
php -l webhook.php
php -l telegram_webhook.php
php -l telegram_webhook_admin.php
php tools/telegram_smoke_test.php
php tools/search_destination_smoke.php --http
bash tools/manager_http_smoke.sh
php tools/manager_preparation_smoke.php
php tools/virtual_reply_learning.php control-status

# Never report success if a concurrent checkout moved during verification.
require_source || fail_target
printf '%s\n' 'DEPLOY_APPLICATION_CHECK=OK'
