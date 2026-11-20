"""Offline, byte-preserving contract upgrade of the exact accepted PRICE27316 hook.

Usage: python3 build-price27316-hook-upgrade.py FROZEN_HOOK NEW_CONTRACT OUTPUT
No deployment, execution, network, or regeneration of roster/editor/writer/runtime pins.
The changed contract still requires independent review and tests before installation.
"""
import base64
import hashlib
import json
from pathlib import Path
import re
import sys

FROZEN_SHA256 = '41e9f0151f97691f91258ebb37714090ba0798c76db18c3870ff93bcc23d0c9a'
FROZEN_BYTES = 613581
CONTRACT_OFFSET = 537275
CONTRACT_BYTES = 41809
CONTRACT_SHA256 = '359a9d61033095c0a8abb3285734923d1d6cffe3dd365c59f7612b6229f9ca15'
PHP_PREFIX = b'<?php\ndeclare(strict_types=1);\n'
REQUIRE = b"require_once __DIR__.'/monitor-price-contract.php';"
DRIVER_SENTINEL = b"\n\n$inspect=getenv('ANYTOUR_MONITOR_HOOK_INSPECT')==='1'||($argv[1]??'')==='--inspect';"


def need(condition, reason):
    if not condition:
        raise ValueError(reason)


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def embedded_contract(raw):
    need(raw.startswith(PHP_PREFIX), 'CONTRACT_PHP_HEADER_CHANGED')
    need(raw.count(REQUIRE) == 1, 'CONTRACT_REQUIRE_BOUNDARY_CHANGED')
    body = raw[len(PHP_PREFIX):].replace(REQUIRE, b'')
    need(b'<?php' not in body and b'?>' not in body, 'CONTRACT_EXTRA_PHP_TAG')
    need(DRIVER_SENTINEL not in body, 'CONTRACT_CONTAINS_DRIVER')
    return body


def upgrade(frozen, source):
    need(len(frozen) == FROZEN_BYTES and sha(frozen) == FROZEN_SHA256,
         'FROZEN_HOOK_CHANGED')
    old = frozen[CONTRACT_OFFSET:CONTRACT_OFFSET + CONTRACT_BYTES]
    need(sha(old) == CONTRACT_SHA256 and frozen.count(old) == 1,
         'FROZEN_CONTRACT_BOUNDARY_CHANGED')
    new = embedded_contract(source)
    need(new != old, 'CONTRACT_UNCHANGED')
    before = frozen[:CONTRACT_OFFSET]
    after = frozen[CONTRACT_OFFSET + CONTRACT_BYTES:]
    result = before + new + after
    need(result[:len(before)] == before and result[len(before) + len(new):] == after,
         'NON_CONTRACT_BYTES_CHANGED')
    need(frozen.count(DRIVER_SENTINEL) == result.count(DRIVER_SENTINEL) == 1,
         'DRIVER_BOUNDARY_CHANGED')
    need(frozen.split(DRIVER_SENTINEL, 1)[1] == result.split(DRIVER_SENTINEL, 1)[1],
         'DRIVER_BYTES_CHANGED')
    constants = {}
    for name in ['HOOK_ACCEPTED_BASE', 'HOOK_ROSTER_COUNT', 'HOOK_ROSTER_GZIP_B64',
                 'HOOK_RUNTIME_PINS_B64', 'HOOK_CONTRACT_B64', 'HOOK_EDITOR_B64', 'HOOK_WRITER_B64']:
        pattern = rb'^const ' + name.encode() + rb'=(.*);$'
        old_matches = re.findall(pattern, frozen, re.M)
        new_matches = re.findall(pattern, result, re.M)
        need(len(old_matches) == 1 and old_matches == new_matches, 'FROZEN_CONSTANT_CHANGED_' + name)
        constants[name] = sha(old_matches[0])
    encoded_pins = re.search(rb'^const HOOK_RUNTIME_PINS_B64=(.*);$', frozen, re.M).group(1)
    pins = json.loads(base64.b64decode(json.loads(encoded_pins), validate=True))
    need(len(pins) == 23, 'RUNTIME_PIN_COUNT_CHANGED')
    return result, {
        'frozen_hook_sha256': FROZEN_SHA256, 'upgraded_hook_sha256': sha(result),
        'bytes': len(result), 'original_contract_sha256': sha(old),
        'new_contract_source_sha256': sha(source), 'new_embedded_contract_sha256': sha(new),
        'contract_offset': CONTRACT_OFFSET, 'old_contract_bytes': len(old), 'new_contract_bytes': len(new),
        'unchanged_prefix_sha256': sha(before), 'unchanged_suffix_sha256': sha(after),
        'preserved_constants_sha256': constants, 'runtime_pins': 23,
        'driver_preserved': True, 'roster_editor_writer_preserved': True,
        'production_writes': 0, 'execution_performed': False, 'installation_performed': False,
    }


def main():
    if len(sys.argv) != 4:
        raise SystemExit('usage: build-price27316-hook-upgrade.py FROZEN_HOOK NEW_CONTRACT OUTPUT')
    frozen_path, contract_path, output = map(Path, sys.argv[1:])
    need(output.resolve() not in {frozen_path.resolve(), contract_path.resolve()}, 'OUTPUT_OVERWRITES_INPUT')
    result, metadata = upgrade(frozen_path.read_bytes(), contract_path.read_bytes())
    # Refuse accidental replacement of a previous reviewed artifact.
    with output.open('xb') as handle:
        handle.write(result)
    need(sha(output.read_bytes()) == metadata['upgraded_hook_sha256'], 'OUTPUT_READBACK_CHANGED')
    print(json.dumps(metadata, sort_keys=True))


if __name__ == '__main__':
    main()
