#!/usr/bin/env python3
"""Exercise the actual key initializer in an isolated replica of the host layout."""
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[1]
checks = 0

def check(value, name):
    global checks
    checks += 1
    if not value:
        raise AssertionError(name)

with tempfile.TemporaryDirectory(prefix='max-attribution-key-') as directory:
    data = Path(directory) / 'data'
    bot = data / 'www/bot'
    for relative in ('services/MaxTrackerLookupConfig.php', 'tools/max_attribution_signing_key.php'):
        dest = bot / relative
        dest.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(ROOT / relative, dest)
    command = json.loads(os.getenv('TEST_PHP_COMMAND_JSON', '["php"]')) + [str(bot / 'tools/max_attribution_signing_key.php'), 'init']
    first = subprocess.run(command, capture_output=True, check=True)
    check(first.stdout.startswith(b'-----BEGIN PUBLIC KEY-----'), 'only a public key is exported')
    check(b'PRIVATE' not in first.stdout, 'private key never printed')
    key = data / 'private/max-attribution/signing.pem'
    check(key.is_file() and not key.is_relative_to(bot), 'private key outside webroot')
    check(key.stat().st_mode & 0o777 == 0o600, 'private key mode 0600')
    check(key.parent.stat().st_mode & 0o777 == 0o700, 'feature directory mode 0700')
    digest = hashlib.sha256(key.read_bytes()).digest()
    second = subprocess.run(command, capture_output=True, check=True)
    check(second.stdout == first.stdout and hashlib.sha256(key.read_bytes()).digest() == digest, 'repeat init preserves key exactly')
    key.chmod(0o644)
    refused = subprocess.run(command, capture_output=True)
    check(refused.returncode != 0 and refused.stdout == b'', 'refuses permissive existing key')
    check(hashlib.sha256(key.read_bytes()).digest() == digest, 'failure never replaces key')
    key.chmod(0o600)
    key.write_bytes(b'invalid existing key')
    refused = subprocess.run(command, capture_output=True)
    check(refused.returncode != 0 and refused.stdout == b'', 'refuses invalid existing key')
    check(key.read_bytes() == b'invalid existing key', 'invalid existing key is preserved for operator review')
    key.unlink()
    first = subprocess.run(command, capture_output=True, check=True)
    check(first.stdout.startswith(b'-----BEGIN PUBLIC KEY-----'), 'initialization works after explicit operator removal')
print(f'PASS {checks} signing-key initialization checks')
