#!/usr/bin/env bash
# Source in the checkout-sync shell. FD 8 holds the catalogue publishers'
# operation lock through the checkout switch. Shell exit also releases it.
deploy_appleservice39_catalogue_guard() (
  set -o pipefail
  local releases="$1" candidate="$2" pointer="$1/current"
  if [[ -e "$releases/pending.json" || -L "$releases/pending.json" ]]; then
    printf '%s\n' appleservice39_catalogue_guard=release_pending >&2
    return 1
  fi
  [[ -e "$pointer" || -L "$pointer" ]] || return 0
  local target version data_pin reader_pin actual_data incoming_reader incoming_catalogue bundled
  target="$(readlink -- "$pointer")" || return
  version="${target##*/}"
  [[ "$version" =~ ^([a-f0-9]{64})-([a-f0-9]{64})$ ]] || return 1
  data_pin="${BASH_REMATCH[1]}" reader_pin="${BASH_REMATCH[2]}"
  [[ "$target" == "$releases/$version" && "$(realpath -e -- "$target")" == "$target" &&
     -f "$target/catalogue.json" && ! -L "$target/catalogue.json" ]] || return 1
  actual_data="$(sha256sum -- "$target/catalogue.json")" || return
  incoming_reader="$(git show "$candidate:services/AppleService39PilotCatalog.php" | sha256sum)" || return
  incoming_catalogue="$(git show "$candidate:resources/appleservice39-pilot-entries.json" | sha256sum)" || return
  bundled="$(sha256sum -- resources/appleservice39-pilot-entries.json)" || return
  if [[ "${actual_data%% *}" != "$data_pin" || "${incoming_reader%% *}" != "$reader_pin" ||
        "${incoming_catalogue%% *}" != "${bundled%% *}" ]]; then
    printf '%s\n' appleservice39_catalogue_guard=incompatible_release >&2
    return 1
  fi
  printf '%s\n' appleservice39_catalogue_guard=compatible
)

deploy_manager2_style_guard() (
  set -o pipefail
  local releases="$1" candidate="$2" pointer="$1/current"
  if [[ -e "$releases/pending.json" || -L "$releases/pending.json" ]]; then
    printf '%s\n' manager2_style_guard=release_pending >&2
    return 1
  fi
  [[ -e "$pointer" || -L "$pointer" ]] || return 0
  local target version expected_css expected_contract actual_css incoming_contract paths file pin
  target="$(readlink -- "$pointer")" || return
  version="${target##*/}"
  [[ "$version" =~ ^([a-f0-9]{64})-([a-f0-9]{64})$ ]] || return 1
  expected_css="${BASH_REMATCH[1]}" expected_contract="${BASH_REMATCH[2]}"
  [[ "$target" == "$releases/$version" && "$(realpath -e -- "$target")" == "$target" &&
     -f "$target/style.css" && ! -L "$target/style.css" ]] || return 1
  actual_css="$(sha256sum -- "$target/style.css")" || return
  paths="$(php -r 'require "services/Manager2WorkspaceStyle.php"; echo implode("\n", Manager2WorkspaceStyle::CONTRACT_FILES);')" || return
  [[ -n "$paths" ]] || return 1
  incoming_contract="$(
    while IFS= read -r file; do
      pin="$(git show "$candidate:$file" | sha256sum)" || exit 1
      printf '%s:%s\n' "$file" "${pin%% *}"
    done <<< "$paths" | sha256sum
  )" || return
  if [[ "${actual_css%% *}" != "$expected_css" || "${incoming_contract%% *}" != "$expected_contract" ]]; then
    printf '%s\n' manager2_style_guard=incompatible_release >&2
    return 1
  fi
  printf '%s\n' manager2_style_guard=compatible
)

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

  deploy_appleservice39_catalogue_guard "$private_root/appleservice39-catalogue-releases" "$candidate" ||
    { printf '%s\n' 'appleservice39_catalogue_guard=refused' >&2; return 1; }
  deploy_manager2_style_guard "$private_root/manager2-style-releases" "$candidate" ||
    { printf '%s\n' 'manager2_style_guard=refused' >&2; return 1; }
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
