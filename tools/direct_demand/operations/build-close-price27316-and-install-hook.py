"""Offline assembly only. Never executes or deploys the installer."""
import base64
import hashlib
import importlib.util
import json
from pathlib import Path
import sys

HERE = Path(__file__).parent
PREFIX = '<?php\ndeclare(strict_types=1);\n'
SENTINEL = "\n\n$inspect=getenv('ANYTOUR_MONITOR_HOOK_INSPECT')==='1'||($argv[1]??'')==='--inspect';"


def build(frozen, contract):
    spec = importlib.util.spec_from_file_location('upgrade', HERE / 'build-price27316-hook-upgrade.py')
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    upgraded, proof = module.upgrade(frozen, contract)
    source = upgraded.decode()
    if source.count(SENTINEL) != 1:
        raise ValueError('HOOK_MAIN_BOUNDARY_CHANGED')
    functions = source.split(SENTINEL, 1)[0]
    recovery = (HERE / 'reconcile-pending-27316.php').read_text()
    start = recovery.index('const RECONCILE_ROOT =')
    stop = recovery.index('\nfunction reconcile_identity(')
    helpers = recovery[start:stop]
    if any(token in helpers for token in ['\ntry {', 'new PDO(', 'MaxTransport::request(']):
        raise ValueError('RECOVERY_EXECUTION_INCLUDED')
    body = (HERE / 'close-price27316-and-install-hook.php').read_text()
    if not body.startswith(PREFIX):
        raise ValueError('INSTALLER_HEADER_CHANGED')
    constants = '\nconst CLOSE_UPGRADED_HOOK_B64=' + json.dumps(base64.b64encode(upgraded).decode()) + ';\n'
    constants += 'const CLOSE_UPGRADED_HOOK_SHA256=' + json.dumps(hashlib.sha256(upgraded).hexdigest()) + ';\n'
    constants += 'const CLOSE_DRIVER_SHA256=' + json.dumps(hashlib.sha256(body.encode()).hexdigest()) + ';\n'
    output = (functions + '\n' + helpers + constants + body[len(PREFIX):]).encode()
    if SENTINEL.encode() in output:
        raise ValueError('HOOK_CALLBACK_LOOP_INCLUDED')
    return output, proof


if __name__ == '__main__':
    if len(sys.argv) != 4:
        raise SystemExit('usage: build-close-price27316-and-install-hook.py FROZEN_HOOK CONTRACT OUTPUT')
    output, proof = build(Path(sys.argv[1]).read_bytes(), Path(sys.argv[2]).read_bytes())
    with Path(sys.argv[3]).open('xb') as f:
        f.write(output)
    print(json.dumps({'installer_sha256': hashlib.sha256(output).hexdigest(), 'bytes': len(output),
                      'hook_upgrade': proof, 'default_mode': 'inspect', 'self_test': '--self-test',
                      'apply_environment': 'ANYTOUR_PRICE27316_CLOSE_APPLY=1'}))
