"""Bundle the existing editor/writer verbatim for the existing collector's CLI hook."""
import base64, gzip, hashlib, json, pathlib, re, sys

root = pathlib.Path(__file__).parent
prefix = '<?php\ndeclare(strict_types=1);\n'
def source(name):
    value = (root / name).read_text()
    assert value.startswith(prefix), name
    return value[len(prefix):]
contract = source('monitor-price-contract.php')
hook_contract = source('monitor-price-hook-contract.php').replace("require_once __DIR__.'/monitor-price-contract.php';", '')
editor = source('update-commercial-price-20261104-remaining1.php').replace("require_once __DIR__.'/monitor-price-contract.php';", '')
writer = source('publish-commercial-channel-hotel-feeds-20261103-1.php').replace("require_once __DIR__.'/monitor-price-contract.php';", '')
driver = source('monitor-price-after-collection.php').replace("require_once __DIR__.'/monitor-price-hook-contract.php';", '')
pins_source = editor.split('$accepted=[', 1)[1].split('foreach($accepted as', 1)[0]
pins = dict(re.findall(r'''["']([^"']+\.php|migrations/[^"']+\.sql)["']\s*=>\s*["']([a-f0-9]{40})["']''', pins_source))
assert len(pins) == 22
packed_roster = (root / 'monitor-price-expanded-roster-20261111.json.gz').read_bytes()
roster = gzip.decompress(packed_roster)
rows = json.loads(roster)['rows']
assert 1 <= len(rows) <= 1000
assert len({r['tourvisor_hotel_id'] for r in rows}) == len(rows)
assert len({r['posts'][c] for r in rows for c in ['max','telegram']}) == 2 * len(rows)
assert all(isinstance(r['tourvisor_hotel_id'], int) and r['tourvisor_hotel_id'] > 0 for r in rows)
values = {'HOOK_ACCEPTED_BASE': '8164359eba9a633f3f25d743e534386ecffe622f',
          'HOOK_ROSTER_COUNT': len(rows),
          'HOOK_ROSTER_GZIP_B64': base64.b64encode(packed_roster).decode(),
          'HOOK_RUNTIME_PINS_B64': base64.b64encode(json.dumps(pins).encode()).decode(),
          'HOOK_CONTRACT_B64': base64.b64encode(contract.encode()).decode(),
          'HOOK_EDITOR_B64': base64.b64encode(editor.encode()).decode(),
          'HOOK_WRITER_B64': base64.b64encode(writer.encode()).decode()}
# Reject HTTP before even defining embedded source constants.
result = prefix + "if(PHP_SAPI!=='cli'){http_response_code(404);exit;}\n"
result += ''.join('const '+key+'='+json.dumps(value)+';\n' for key, value in values.items())
result += contract + '\n' + hook_contract + '\n' + driver
dest = pathlib.Path(sys.argv[1]); dest.write_text(result)
print(json.dumps({'bytes': len(result.encode()), 'sha256': hashlib.sha256(result.encode()).hexdigest(), 'pairs': len(rows), 'supplier_calls': 0}))
