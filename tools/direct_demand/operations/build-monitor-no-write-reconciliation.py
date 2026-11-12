"""Bundle the one exact original-operation recovery for the existing carrier."""
import hashlib
import json
from pathlib import Path
import sys

root = Path(__file__).parent
prefix = '<?php\ndeclare(strict_types=1);\n'
parts = []
for name in ('monitor-price-contract.php', 'monitor-price-hook-contract.php',
             'reconcile-monitor-price-no-write-20261112.php'):
    value = (root / name).read_text()
    assert value.startswith(prefix), name
    value = value[len(prefix):]
    value = value.replace("require_once __DIR__.'/monitor-price-contract.php';", '')
    value = value.replace("require_once __DIR__.'/monitor-price-hook-contract.php';", '')
    parts.append(value)
result = prefix + "if(PHP_SAPI!=='cli'){http_response_code(404);exit;}\n" + '\n'.join(parts)
Path(sys.argv[1]).write_text(result)
print(json.dumps({'bytes': len(result.encode()),
                  'sha256': hashlib.sha256(result.encode()).hexdigest(),
                  'scope': 'original_expired_no_write_TV1142_only'}))
