#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
corpus_test_env="$(mktemp -d)"
trap 'rm -rf "$corpus_test_env"' EXIT
python3 -m venv "$corpus_test_env"
"$corpus_test_env/bin/pip" install --disable-pip-version-check --only-binary=:all: --require-hashes -q -r tools/requirements-encrypted-evidence.txt
"$corpus_test_env/bin/python" tests/test_private_conversation_corpus_crypto.py
