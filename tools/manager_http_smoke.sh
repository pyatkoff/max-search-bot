#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${MANAGER_BASE_URL:-https://anytour.online/max-search/manager}"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

request() {
  local name="$1"
  local url="$2"
  local method="${3:-GET}"
  local body_file="$TMP_DIR/${name}.body"
  local headers_file="$TMP_DIR/${name}.headers"
  local status

  if [[ "$method" == "POST" ]]; then
    status="$(curl -sS --connect-timeout 10 --max-time 20 --max-redirs 0 \
      -X POST -H 'Content-Type: application/json' --data '{"action":"me"}' \
      -D "$headers_file" -o "$body_file" -w '%{http_code}' "$url")"
  else
    status="$(curl -sS --connect-timeout 10 --max-time 20 --max-redirs 0 \
      -D "$headers_file" -o "$body_file" -w '%{http_code}' "$url")"
  fi

  printf '%s\t%s\t%s\n' "$name" "$status" "$url"
  printf '%s' "$status" > "$TMP_DIR/${name}.status"
}

assert_status() {
  local name="$1" expected="$2"
  local actual
  actual="$(cat "$TMP_DIR/${name}.status")"
  if [[ "$actual" != "$expected" ]]; then
    echo "FAIL $name: expected HTTP $expected, got $actual"
    cat "$TMP_DIR/${name}.headers" || true
    exit 1
  fi
}

assert_body_contains() {
  local name="$1" needle="$2"
  if ! grep -Fq "$needle" "$TMP_DIR/${name}.body"; then
    echo "FAIL $name: response body does not contain expected marker: $needle"
    head -c 1000 "$TMP_DIR/${name}.body" || true
    echo
    exit 1
  fi
}

request manager_root "$BASE_URL/"
request manager_index "$BASE_URL/index.php"
request manager_workspace_alias "$BASE_URL/workspace-v2.php"
request manager_api_me "$BASE_URL/api.php" POST

assert_status manager_root 200
assert_status manager_index 200
assert_status manager_workspace_alias 200
assert_status manager_api_me 401

assert_body_contains manager_root 'id="workspaceRoot"'
assert_body_contains manager_index 'id="workspaceRoot"'
assert_body_contains manager_workspace_alias 'id="workspaceRoot"'
assert_body_contains manager_api_me '"error":"unauthorized"'

echo 'MANAGER HTTP SMOKE: OK'
