#!/usr/bin/env bash
# Observe the existing two scheduled workers concurrently. Never run a tick.
# Exit 1 means hotel failed, 2 means development alone failed; other setup
# failures also require rollback of the still-unverified pair.
set -euo pipefail
[[ "$#" == "0" ]] || exit 3
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
umask 077
work="$(mktemp -d)"
hotel_pid=
development_pid=
cleanup() {
  local status=$?
  trap - EXIT HUP INT TERM
  # Only live jobs still owned by this shell may receive a signal.
  # A completed child PID can already have been reused by another process.
  for pid in $(jobs -pr); do
    kill "$pid" 2>/dev/null || true
  done
  for pid in "$hotel_pid" "$development_pid"; do
    if [[ -n "$pid" ]]; then
      wait "$pid" 2>/dev/null || true
    fi
  done
  rm -rf "$work"
  exit "$status"
}
trap cleanup EXIT
trap "exit 129" HUP
trap "exit 130" INT
trap "exit 143" TERM

/usr/bin/php "$root/tools/hotel_work.php" verify-cron >"$work/hotel" 2>&1 &
hotel_pid=$!
/usr/bin/php "$root/tools/development_work.php" verify-cron >"$work/development" 2>&1 &
development_pid=$!

hotel_result=0
wait "$hotel_pid" || hotel_result=$?
hotel_pid=
development_result=0
wait "$development_pid" || development_result=$?
development_pid=
cat "$work/hotel" "$work/development"

if [[ "$hotel_result" != "0" ]]; then
  exit 1
fi
if [[ "$development_result" != "0" ]]; then
  exit 2
fi
exit 0
