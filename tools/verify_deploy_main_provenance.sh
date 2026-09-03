#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

expected_sha="${EXPECTED_SHA:-}"
remote="${DEPLOY_MAIN_REMOTE:-origin}"
source_ref="${DEPLOY_MAIN_SOURCE_REF:-refs/heads/main}"

if [[ ! "$expected_sha" =~ ^[0-9a-fA-F]{40}$ ]]; then
  echo "ERROR: EXPECTED_SHA must be a full 40-character Git commit SHA" >&2
  exit 2
fi

if [[ "$source_ref" != "refs/heads/main" ]]; then
  echo "ERROR: deployment provenance source must remain refs/heads/main" >&2
  exit 2
fi

# Fetch the authoritative ref explicitly. Do not read origin/main: a previous
# fetch or checkout may have left that local remote-tracking ref stale.
git fetch --no-tags --force "$remote" "$source_ref"
authoritative_sha="$(git rev-parse --verify 'FETCH_HEAD^{commit}')"

expected_sha="${expected_sha,,}"
authoritative_sha="${authoritative_sha,,}"

if [[ "$expected_sha" != "$authoritative_sha" ]]; then
  printf 'ERROR: deployment SHA %s is not current authoritative main %s\n' \
    "$expected_sha" "$authoritative_sha" >&2
  exit 1
fi

printf 'deployment_main_provenance=verified\nauthoritative_main_sha=%s\n' "$authoritative_sha"
