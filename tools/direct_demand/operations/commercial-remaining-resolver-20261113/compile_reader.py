#!/usr/bin/env python3
"""Compile one PHP stdin reader for the already-authorized existing carrier."""
import argparse
import base64
import hashlib
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parent
parser = argparse.ArgumentParser()
parser.add_argument('--expected-installed-sha', required=True)
parser.add_argument('--output', type=Path, required=True)
args = parser.parse_args()
assert re.fullmatch('[a-f0-9]{40}', args.expected_installed_sha), 'exact fresh production SHA required'
scope_raw = (ROOT / 'scope.json').read_bytes()
scope = json.loads(scope_raw)
assert scope['operation'] == 'commercial-remaining-anex48-and-max3-read-20261113-v1'
assert scope['anex_count'] == len(scope['anex_ids']) == len(set(scope['anex_ids'])) == 48
assert {p['publication_id'] for p in scope['old_max_posts']} == {58, 59, 61}
assert {p['candidate_catalog_hotel_id'] for p in scope['old_max_posts']} == {319, 364, 496}
assert all(v == 0 for v in scope['permissions'].values())
sources = json.loads((ROOT / 'source_provenance.json').read_text())
for source in sources:
    raw = (ROOT / 'source' / source['name']).read_bytes()
    assert hashlib.sha256(raw).hexdigest() == source['sha256'], source['name']
    assert hashlib.sha1(b'blob ' + str(len(raw)).encode() + b'\0' + raw).hexdigest() == source['sha']
resolver = next(s for s in sources if s['name'] == 'anex-search-mapping-registry.php')
assert resolver['sha'] == 'cc135a95d2a6e0f73ce50be2141c9b8a26fddc58'
pins_raw = (ROOT / 'installed_source_pins.json').read_bytes()
pins = json.loads(pins_raw)
assert len(pins) == 24 and pins['services/HotelDatabase.php'] == '0973a849add7f0c594ae920c5eb0ef62c58dffb5'
assert pins['services/ChannelPostRegistryService.php'] == 'db8181426091a685a79ee55991cde22671f0d9dd'

def body(path):
    text = path.read_text()
    match = re.match(r'<\?php\s*declare\(strict_types=1\);\s*', text)
    assert match and '?>' not in text, f'PHP wrapper changed: {path.name}'
    return text[match.end():]

constants = {
    'EXPECTED_INSTALLED_SHA': args.expected_installed_sha,
    'REMAINING_SCOPE_B64': base64.b64encode(scope_raw).decode(),
    'REMAINING_SCOPE_SHA256': hashlib.sha256(scope_raw).hexdigest(),
    'REMAINING_RESOLVER_BLOB_SHA': resolver['sha'],
    'REMAINING_RESOLVER_SHA256': resolver['sha256'],
    'REMAINING_PINS_B64': base64.b64encode(pins_raw).decode(),
    'REMAINING_PINS_SHA256': hashlib.sha256(pins_raw).hexdigest(),
}
result = '<?php\ndeclare(strict_types=1);\n'
for key, value in constants.items():
    assert "'" not in value and '\\' not in value
    result += f"const {key} = '{value}';\n"
for path in [ROOT / 'source/anex-search-mapping-registry.php', ROOT / 'reader_lib.php', ROOT / 'reader_entry.php']:
    result += f'\n// Compiled from {path.name}; no eval and no server file creation.\n' + body(path) + '\n'
args.output.parent.mkdir(parents=True, exist_ok=True)
args.output.write_text(result)
manifest = {'reader_sha256': hashlib.sha256(result.encode()).hexdigest(), 'reader_bytes': len(result.encode()),
            'expected_installed_sha': args.expected_installed_sha, 'scope_sha256': constants['REMAINING_SCOPE_SHA256'],
            'resolver_sha256': resolver['sha256'], 'resolver_blob_sha': resolver['sha'],
            'native_php_lint_required_before_ssh': True, 'external_execution_performed': False}
args.output.with_suffix('.manifest.json').write_text(json.dumps(manifest, indent=2) + '\n')
print(json.dumps(manifest))
