#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
evidence_test_env="$(mktemp -d)"
trap 'rm -rf "$evidence_test_env"' EXIT
python3 -m venv "$evidence_test_env"
"$evidence_test_env/bin/pip" install --disable-pip-version-check --only-binary=:all: --require-hashes -q -r tools/requirements-encrypted-evidence.txt
"$evidence_test_env/bin/python" tests/test_encrypted_dialogue_evidence.py
