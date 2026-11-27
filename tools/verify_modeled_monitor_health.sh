#!/usr/bin/env bash
set -euo pipefail
umask 077

if [[ ! "${EXPECTED_SHA:-}" =~ ^[0-9a-f]{40}$ ]]; then
  echo 'RELEASE_MONITOR_HEALTH_REFUSED=monitor_source_mismatch' >&2
  exit 1
fi

root='/var/www/anytoour/data/www/app.anytoour.ru'
timeout 20s ssh -i "$RUNNER_TEMP/deploy_key" -o BatchMode=yes -o ConnectTimeout=10 \
    -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null "$DEPLOY_USER@$DEPLOY_HOST" \
    "cd '$root' && { test \"\$(git rev-parse HEAD)\" = '$EXPECTED_SHA' || exit 41; } && timeout 15s php tools/modeled_conversion_monitor.php availability" \
    > "$RUNNER_TEMP/monitor-health.json" 2>/dev/null || {
  status=$?
  reason=monitor_transport_unavailable
  if [[ "$status" -eq 41 ]]; then
    reason=monitor_source_mismatch
  fi
  printf 'RELEASE_MONITOR_HEALTH_REFUSED=%s\n' "$reason" >&2
  exit 1
}

PYTHONPATH=tools python3 -B - "$RUNNER_TEMP/monitor-health.json" <<'MONITOR_HEALTH'
import json
import sys

from modeled_conversion_monitor import ROLE_FLAGS, health_view, scoped


def refuse(reason):
    raise SystemExit(f"RELEASE_MONITOR_HEALTH_REFUSED={reason}")


try:
    with open(sys.argv[1], "rb") as stream:
        raw = stream.read(8193)
    health = json.loads(raw) if len(raw) <= 8192 else None
except (OSError, ValueError, RecursionError):
    health = None

if not isinstance(health, dict):
    refuse("health_metadata_unavailable")
if not scoped(health) or type(health["schema_version"]) is not int:
    refuse("monitor_source_mismatch")

scope = health.get("monitor_scope")
status, flags = health_view(health)
if scope not in ("external", "legacy"):
    refuse("health_metadata_unavailable")
if scope == "external" and (set(flags) - ROLE_FLAGS["external"] or status in ("off", "degraded")):
    refuse("health_metadata_unavailable")
if status == "unavailable":
    refuse(",".join(flags) or "health_metadata_unavailable")

print(f"RELEASE_MONITOR_HEALTH={scope}:{status}")
MONITOR_HEALTH
