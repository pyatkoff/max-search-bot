#!/usr/bin/env bash
set -euo pipefail

if [[ $# != 1 ]]; then
  echo 'Expected one protected log path' >&2
  exit 2
fi
case "$1" in
  ai_debug.log|handlers/ai_debug.log|handlers/ai_errors.log|tmp_in.txt|tmp_out.txt|structured_events.log) ;;
  *) echo 'Unknown protected log path' >&2; exit 2 ;;
esac

url="https://app.anytoour.ru/$1"
for attempt in 1 2; do
  result=0
  timing=$(curl -I -sS --max-time 15 -o /dev/null \
    -w '%{http_code} %{time_namelookup} %{time_connect} %{time_appconnect} %{time_starttransfer} %{time_total}' \
    -- "$url") || result=$?
  read -r status dns connect tls first_byte total <<< "$timing"
  printf 'public_runtime_log_fence path=%s attempt=%s curl_exit=%s http=%s dns_s=%s connect_s=%s tls_s=%s first_byte_s=%s total_s=%s\n' \
    "$1" "$attempt" "$result" "${status:-unavailable}" "${dns:-unknown}" "${connect:-unknown}" \
    "${tls:-unknown}" "${first_byte:-unknown}" "${total:-unknown}" >&2

  if (( result == 0 )); then
    case "$status" in
      403|404) exit 0 ;;
      *) echo 'Public runtime log containment failed' >&2; exit 1 ;;
    esac
  fi
  if (( result != 28 || attempt == 2 )); then
    exit "$result"
  fi
  sleep 1
done
