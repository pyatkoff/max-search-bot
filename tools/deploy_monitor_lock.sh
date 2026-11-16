#!/usr/bin/env bash
# Prefix the checkout-sync script with this file in the SAME remote bash process.
# FD 9 stays open until that entire script exits; running this as a child would
# release the lock before git reset. The monitor holds the same journal flock
# from its first state read through post edits, feed publication and final receipt.
set -euo pipefail

deploy_monitor_journal=/var/www/anytoour/data/channel-post-operations/monitor-price-after-collection-v1.jsonl
# Bound deployment waiting, without interrupting a running 1800-second price
# pass or changing its budget. Leave 300 seconds for its final operation to exit.
deploy_monitor_wait_seconds=2100

deploy_monitor_lock_fail() {
  printf 'ERROR: monitor journal lock: %s\n' "$1" >&2
  exit 1
}

deploy_monitor_check_inode() {
  [[ -f "$deploy_monitor_journal" && ! -L "$deploy_monitor_journal" ]] ||
    deploy_monitor_lock_fail 'existing regular journal required'
  local named_inode open_inode
  named_inode="$(stat -Lc '%d:%i' -- "$deploy_monitor_journal")" ||
    deploy_monitor_lock_fail 'cannot stat journal path'
  open_inode="$(stat -Lc '%d:%i' -- "/proc/$$/fd/9")" ||
    deploy_monitor_lock_fail 'cannot stat open journal descriptor'
  [[ "$named_inode" == "$open_inode" ]] ||
    deploy_monitor_lock_fail 'journal inode changed; checkout untouched'
}

[[ -f "$deploy_monitor_journal" && ! -L "$deploy_monitor_journal" ]] ||
  deploy_monitor_lock_fail 'existing regular journal required'
# Read-only open: never create, truncate, append, rotate or reconcile the journal.
exec 9< "$deploy_monitor_journal"
deploy_monitor_check_inode
printf 'monitor_journal_lock=waiting timeout_seconds=%s\n' "$deploy_monitor_wait_seconds"
if flock --exclusive --timeout "$deploy_monitor_wait_seconds" --conflict-exit-code 75 9; then
  :
else
  deploy_monitor_lock_status=$?
  printf 'ERROR: monitor journal lock unavailable (exit %s); checkout untouched\n' "$deploy_monitor_lock_status" >&2
  exit "$deploy_monitor_lock_status"
fi
# A waiter must not reset the checkout after locking a replaced/rotated inode.
deploy_monitor_check_inode
# This is a mutex only. A pending receipt does not forbid installing code needed
# for its recovery, and is neither interpreted nor changed by deployment.
printf 'monitor_journal_lock=acquired\n'
