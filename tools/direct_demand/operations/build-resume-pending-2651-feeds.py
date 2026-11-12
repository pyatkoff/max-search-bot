"""Build one feed-only continuation from the accepted hook; never run the callback loop."""
from pathlib import Path
import base64
import hashlib
import json
import re
import sys

HOOK_SHA256 = 'c165dc1a01e46e5e74da119a205caf8cde32b04b03fead67ae530d64efb15727'
PHP_PREFIX = '<?php\ndeclare(strict_types=1);\n'
DRIVER_SENTINEL = "\n\n$inspect=getenv('ANYTOUR_MONITOR_HOOK_INSPECT')==='1'||($argv[1]??'')==='--inspect';"


def main():
    if len(sys.argv) != 3:
        raise SystemExit('usage: build-resume-pending-2651-feeds.py FROZEN_HOOK_FILE OUTPUT_FILE')
    raw = Path(sys.argv[1]).read_bytes()
    if hashlib.sha256(raw).hexdigest() != HOOK_SHA256:
        raise SystemExit('FROZEN_HOOK_BYTES_CHANGED')
    hook = raw.decode('utf-8')
    if not hook.startswith(PHP_PREFIX) or hook.count(DRIVER_SENTINEL) != 1:
        raise SystemExit('FROZEN_HOOK_BOUNDARY_CHANGED')
    prefix = hook.split(DRIVER_SENTINEL, 1)[0]
    match = re.search(r'^const HOOK_EDITOR_B64=("[A-Za-z0-9+/=]+");$', prefix, re.M)
    if match is None:
        raise SystemExit('FROZEN_EDITOR_CONSTANT_CHANGED')
    editor = base64.b64decode(json.loads(match.group(1)), validate=True).decode('utf-8')
    start = editor.find('function need(')
    stop = editor.find('\ntry {', start)
    if start < 0 or stop < 0 or editor.count('\ntry {') != 1:
        raise SystemExit('FROZEN_EDITOR_HELPER_BOUNDARY_CHANGED')
    helpers = editor[start:stop]
    if 'MaxTransport::request(' in helpers or 'APPROVED_UPDATE_OPERATION' in helpers:
        raise SystemExit('FROZEN_EDITOR_EXECUTION_NOT_EXCLUDED')
    body_path = Path(__file__).with_name('resume-pending-2651-feeds.php')
    body = body_path.read_bytes()
    if not body.decode('utf-8').startswith(PHP_PREFIX):
        raise SystemExit('RECONCILER_PREFIX_CHANGED')
    embedded_prefix = prefix + '\n\n' + helpers + '\n'
    proof_constants = ('const FEEDS_DRIVER_SHA256=' + json.dumps(hashlib.sha256(body).hexdigest()) + ';\n'
                       + 'const FEEDS_EMBEDDED_PREFIX_SHA256=' + json.dumps(hashlib.sha256(embedded_prefix.encode()).hexdigest()) + ';\n')
    result = (embedded_prefix + proof_constants + body.decode('utf-8')[len(PHP_PREFIX):]).encode('utf-8')
    if DRIVER_SENTINEL.encode() in result:
        raise SystemExit('CALLBACK_LOOP_INCLUDED')
    destination = Path(sys.argv[2])
    destination.write_bytes(result)
    print(json.dumps({'bytes': len(result), 'sha256': hashlib.sha256(result).hexdigest(),
                      'frozen_hook_sha256': HOOK_SHA256, 'driver_sha256': hashlib.sha256(body).hexdigest(),
                      'default_mode': 'inspect', 'callback_loop_included': False,
                      'self_test_argument': '--self-test',
                      'apply_environment': 'ANYTOUR_MONITOR_PENDING_FEED_APPLY=1'}))


if __name__ == '__main__':
    main()
