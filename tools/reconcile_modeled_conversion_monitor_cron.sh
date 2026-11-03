#!/usr/bin/env bash
# Reconcile only the activated AnyTour pipeline monitor. Never execute a tick.
set -euo pipefail
[[ "$#" == "0" ]] || exit 2
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
umask 077
monitor_status="$(mktemp)"
monitor_before="$(mktemp)"
monitor_after="$(mktemp)"
monitor_current="$(mktemp)"
monitor_attempted=0
cleanup() {
  rm -f "$monitor_status" "$monitor_before" "$monitor_after" "$monitor_current"
}
rollback() {
  local failure=$?
  trap - ERR
  if [[ "$monitor_attempted" == "1" ]]; then crontab "$monitor_before" >/dev/null 2>&1 || true; fi
  exit "$failure"
}
trap cleanup EXIT
trap rollback ERR
/usr/bin/python3 "$root/tools/modeled_conversion_monitor_local.py" --status >"$monitor_status"
configured="$(/usr/bin/python3 - "$monitor_status" <<'STATUS_JSON'
import json,sys
with open(sys.argv[1],encoding='utf-8') as f: v=json.load(f)
if (v.get('schema_version')!=1 or v.get('project')!='anytour'
    or v.get('counter_id')!='98615635' or v.get('role')!='pipeline'
    or type(v.get('configured')) is not bool): raise SystemExit('invalid_monitor_configuration')
print('true' if v['configured'] else 'false')
STATUS_JSON
)"
crontab -l >"$monitor_before" 2>/dev/null
owned="$root/tools/modeled_conversion_monitor_local.py --cron"
owned_count="$(awk -v owned="$owned" 'index($0,owned)&&$0!~/^[[:space:]]*#/{n++} END{print n+0}' "$monitor_before")"
if [[ "$configured" == "false" ]]; then
  if [[ "$owned_count" != "0" ]]; then
    echo 'modeled_pipeline_monitor_activation_missing'
    exit 1
  fi
  echo 'MODELED_PIPELINE_MONITOR=inactive'
  exit 0
fi
private_dir="$(dirname "$root")/max-search-bot-modeled-pipeline"
[[ -d "$private_dir" && ! -L "$private_dir" ]]
[[ "$(stat -c '%a' "$private_dir")" == '700' ]]
[[ ! -L "$private_dir/cron.lock" ]]
if [[ ! -e "$private_dir/cron.lock" ]]; then : >"$private_dir/cron.lock"; fi
[[ -f "$private_dir/cron.lock" ]]
chmod 600 "$private_dir/cron.lock"
awk -v owned="$owned" '
  $0=="# max-search-bot modeled pipeline monitor"{next}
  index($0,owned){next}
  {print}
' "$monitor_before" >"$monitor_after"
printf '%s\n' '# max-search-bot modeled pipeline monitor' >>"$monitor_after"
printf '*/5 * * * * MODELED_MONITOR_TRIGGER=cron /usr/bin/flock -n %s/cron.lock /usr/bin/timeout 45s /usr/bin/python3 %s/tools/modeled_conversion_monitor_local.py --cron >/dev/null 2>&1\n' "$private_dir" "$root" >>"$monitor_after"
crontab -l >"$monitor_current" 2>/dev/null
if ! cmp -s "$monitor_before" "$monitor_current"; then
  echo 'modeled_pipeline_crontab_changed'
  exit 1
fi
monitor_attempted=1
crontab "$monitor_after"
crontab -l >"$monitor_current"
count="$(awk -v owned="$owned" 'index($0,owned)&&$0!~/^[[:space:]]*#/{n++} END{print n+0}' "$monitor_current")"
[[ "$count" == "1" ]]
echo 'MODELED_PIPELINE_MONITOR_CRON_COUNT=1'
