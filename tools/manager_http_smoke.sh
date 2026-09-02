#!/usr/bin/env bash
set -euo pipefail

LEGACY_MANAGER_BASE_URL="${LEGACY_MANAGER_BASE_URL:-https://app.anytoour.ru/manager}"
BASE_URL="${MANAGER_BASE_URL:-https://app.anytoour.ru/manager}"
CONSULTANT_BASE_URL="${CONSULTANT_BASE_URL:-https://app.anytoour.ru/web-consultant}"
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

assert_location() {
  local name="$1" expected="$2"
  local actual
  actual="$(awk 'BEGIN{IGNORECASE=1} /^location:/ {sub(/^[^:]+:[[:space:]]*/,""); sub(/\r$/,""); print; exit}' "$TMP_DIR/${name}.headers")"
  if [[ "$actual" != "$expected" ]]; then
    echo "FAIL $name: expected Location $expected, got ${actual:-<missing>}"
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

request legacy_manager_root "$LEGACY_MANAGER_BASE_URL/"
request legacy_manager_index "$LEGACY_MANAGER_BASE_URL/index.php"
request legacy_manager_api "$LEGACY_MANAGER_BASE_URL/api.php" POST
request legacy_manager_pipeline_api "$LEGACY_MANAGER_BASE_URL/pipeline-api.php" POST

assert_status legacy_manager_root 308
assert_status legacy_manager_index 308
assert_status legacy_manager_api 308
assert_status legacy_manager_pipeline_api 308
assert_location legacy_manager_root 'https://app.anytoour.ru/manager/'
assert_location legacy_manager_index 'https://app.anytoour.ru/manager/index.php'
assert_location legacy_manager_api 'https://app.anytoour.ru/manager/api.php'
assert_location legacy_manager_pipeline_api 'https://app.anytoour.ru/manager/pipeline-api.php'

request manager_root "$BASE_URL/"
request manager_index "$BASE_URL/index.php"
request manager_api_me "$BASE_URL/api.php" POST
request manager_pipeline_api "$BASE_URL/pipeline-api.php" POST

assert_status manager_root 200
assert_status manager_index 200
assert_status manager_api_me 401
assert_status manager_pipeline_api 401

assert_body_contains manager_root 'id="workspaceRoot"'
assert_body_contains manager_index 'id="workspaceRoot"'
assert_body_contains manager_api_me '"error":"unauthorized"'
assert_body_contains manager_pipeline_api '"error":"unauthorized"'

request consultant_root "$CONSULTANT_BASE_URL/"
request consultant_index "$CONSULTANT_BASE_URL/index.php"
request consultant_widget "$CONSULTANT_BASE_URL/widget.js"
request consultant_a11y "$CONSULTANT_BASE_URL/widget-a11y.js"
request consultant_context "$CONSULTANT_BASE_URL/widget-context.js"

assert_status consultant_root 200
assert_status consultant_index 200
assert_status consultant_widget 200
assert_status consultant_a11y 200
assert_status consultant_context 200
assert_body_contains consultant_root 'AnyTour'
assert_body_contains consultant_index 'widget.js'
assert_body_contains consultant_index 'widget-a11y.js'
assert_body_contains consultant_widget 'anytour-consultant-host'
assert_body_contains consultant_a11y 'anytour-consultant-dialog'
assert_body_contains consultant_a11y 'prefers-reduced-motion:reduce'
assert_body_contains consultant_context "action:'context'"
assert_body_contains consultant_context 'page_context'

echo 'MANAGER + WEB CONSULTANT HTTP SMOKE: OK'
