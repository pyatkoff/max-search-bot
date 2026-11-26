#!/usr/bin/env bash
# Source in the checkout-sync shell. FD 8 holds the catalogue publisher's
# operation lock through the checkout switch. Shell exit also releases it.
deploy_hotel_catalogue_guard() {
  local app="$1" candidate="$2"
  local private_root="${app%/*}/max-search-bot-private-logs"
  local releases="$private_root/hotel-catalogue-releases"
  local lock="$releases/operation.lock"

  [[ "$candidate" =~ ^[0-9a-f]{40}$ && "$(pwd -P)" == "$app" ]] ||
    { printf '%s\n' 'hotel_catalogue_guard=invalid_checkout' >&2; return 1; }
  [[ -d "$private_root" && "$(realpath -e -- "$private_root")" == "$private_root" ]] ||
    { printf '%s\n' 'hotel_catalogue_guard=invalid_private_directory' >&2; return 1; }
  mkdir -p -m 0700 -- "$releases" || return
  [[ "$(realpath -e -- "$releases")" == "$releases" ]] ||
    { printf '%s\n' 'hotel_catalogue_guard=invalid_release_directory' >&2; return 1; }

  if [[ ! -e "$lock" && ! -L "$lock" ]]; then
    (umask 077; set -C; : > "$lock") 2>/dev/null || true
  fi
  [[ -f "$lock" && ! -L "$lock" ]] ||
    { printf '%s\n' 'hotel_catalogue_guard=invalid_lock' >&2; return 1; }
  exec 8< "$lock" || return
  flock --exclusive --timeout 15 --conflict-exit-code 75 8 ||
    { printf '%s\n' 'hotel_catalogue_guard=lock_unavailable' >&2; return 1; }
  local named_inode open_inode
  named_inode="$(stat -Lc '%d:%i' -- "$lock")" || return
  open_inode="$(stat -Lc '%d:%i' -- "/proc/$$/fd/8")" || return
  [[ -f "$lock" && ! -L "$lock" && "$named_inode" == "$open_inode" ]] ||
    { printf '%s\n' 'hotel_catalogue_guard=lock_replaced' >&2; return 1; }

  if [[ -e "$releases/pending.json" || -L "$releases/pending.json" ]]; then
    printf '%s\n' 'hotel_catalogue_guard=release_pending' >&2
    return 1
  fi
  if [[ ! -e "$releases/current" && ! -L "$releases/current" ]]; then
    printf '%s\n' 'hotel_catalogue_guard=bundled'
    return
  fi

  (
    local incoming
    incoming="$(mktemp)" || exit
    trap 'rm -f -- "$incoming"' EXIT
    git show "$candidate:services/ManagerHotelKnowledge.php" > "$incoming" || exit
    php tools/hotel_catalogue_compatibility.php "$app" "$incoming"
  )
}
