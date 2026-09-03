#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORKFLOW="$ROOT/.github/workflows/deploy.yml"
PROVENANCE_TOOL="$ROOT/tools/verify_deploy_main_provenance.sh"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

fail() {
  echo "FAIL: $*" >&2
  exit 1
}

pass() {
  echo "PASS  $*"
}

line_number() {
  local pattern="$1"
  local line
  line="$(grep -nF -- "$pattern" "$WORKFLOW" | head -n 1 | cut -d: -f1)"
  [[ -n "$line" ]] || fail "workflow is missing: $pattern"
  printf '%s\n' "$line"
}

git init -q --bare "$TMP_DIR/remote.git"
git init -q "$TMP_DIR/source"
git -C "$TMP_DIR/source" config user.name "Provenance Regression"
git -C "$TMP_DIR/source" config user.email "provenance-regression@example.invalid"
git -C "$TMP_DIR/source" checkout -q -b main
printf 'first\n' > "$TMP_DIR/source/state.txt"
git -C "$TMP_DIR/source" add state.txt
git -C "$TMP_DIR/source" commit -q -m first
first_sha="$(git -C "$TMP_DIR/source" rev-parse HEAD)"
git -C "$TMP_DIR/source" remote add origin "$TMP_DIR/remote.git"
git -C "$TMP_DIR/source" push -q -u origin main
git -C "$TMP_DIR/remote.git" symbolic-ref HEAD refs/heads/main

git clone -q "$TMP_DIR/remote.git" "$TMP_DIR/checkout"
stale_origin_sha="$(git -C "$TMP_DIR/checkout" rev-parse refs/remotes/origin/main)"
[[ "$stale_origin_sha" == "$first_sha" ]] || fail "test setup did not create the expected origin/main"

printf 'second\n' >> "$TMP_DIR/source/state.txt"
git -C "$TMP_DIR/source" add state.txt
git -C "$TMP_DIR/source" commit -q -m second
second_sha="$(git -C "$TMP_DIR/source" rev-parse HEAD)"
git -C "$TMP_DIR/source" push -q origin main

if (
  cd "$TMP_DIR/checkout"
  EXPECTED_SHA="$first_sha" DEPLOY_MAIN_REMOTE="$TMP_DIR/remote.git" bash "$PROVENANCE_TOOL"
); then
  fail "non-current deployment SHA was accepted"
fi
pass "non-current deployment SHA is rejected after a fresh authoritative fetch"

[[ "$(git -C "$TMP_DIR/checkout" rev-parse refs/remotes/origin/main)" == "$first_sha" ]] \
  || fail "test no longer proves that local origin/main can remain stale"

current_output="$(
  cd "$TMP_DIR/checkout"
  EXPECTED_SHA="$second_sha" DEPLOY_MAIN_REMOTE="$TMP_DIR/remote.git" bash "$PROVENANCE_TOOL"
)"
[[ "$current_output" == *"deployment_main_provenance=verified"* ]] \
  || fail "current main deployment did not report verified provenance"
[[ "$current_output" == *"authoritative_main_sha=$second_sha"* ]] \
  || fail "verified provenance did not report the authoritative SHA"
pass "current authoritative main SHA is accepted even with stale local origin/main"

if (
  cd "$TMP_DIR/checkout"
  EXPECTED_SHA="short-sha" DEPLOY_MAIN_REMOTE="$TMP_DIR/remote.git" bash "$PROVENANCE_TOOL"
); then
  fail "malformed deployment SHA was accepted"
fi
pass "malformed deployment SHA is rejected"

provenance_line="$(line_number "Verify deployment SHA is current main")"
ssh_line="$(line_number "Prepare canonical SSH private key")"
bundle_line="$(line_number "Build exact deployment bundle")"
sync_line="$(line_number "Sync canonical production checkout")"
(( provenance_line < ssh_line && ssh_line < bundle_line && bundle_line < sync_line )) \
  || fail "provenance must run before credentials, bundle and production sync"
grep -Fq 'bash tools/verify_deploy_main_provenance.sh' "$WORKFLOW" \
  || fail "workflow does not execute the provenance verifier"
grep -Fq "if: steps.provenance.outcome == 'success'" "$WORKFLOW" \
  || fail "SSH preparation is not gated by provenance"
grep -Fq 'PROVENANCE_OUTCOME: ${{ steps.provenance.outcome }}' "$WORKFLOW" \
  || fail "deploy telemetry does not capture provenance outcome"
grep -Fq '"provenance":"%s"' "$WORKFLOW" \
  || fail "deploy status does not expose provenance outcome"
grep -Fq 'test "$PROVENANCE_OUTCOME" = "success"' "$WORKFLOW" \
  || fail "final deployment health gate does not require provenance"
pass "workflow gates credentials, bundle and sync on published provenance"

echo "DEPLOY MAIN PROVENANCE REGRESSION: OK"
