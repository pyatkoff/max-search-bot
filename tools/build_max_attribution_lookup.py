#!/usr/bin/env python3
"""Build the public-code-only tracker release. Never reads configuration or keys."""
import argparse
import hashlib
import json
from pathlib import Path
import shutil

ROOT = Path(__file__).resolve().parents[1]
FILES = {
    'lookup.php': 'max-attribution-lookup.php',
    **{f'services/{name}.php': f'services/{name}.php' for name in (
        'MaxTrackerLookupProtocol', 'MaxTrackerLookupRepository', 'MaxSubscriptionAttributionPolicy')},
}

def build(destination):
    dest = Path(destination).resolve()
    if dest.exists():
        raise ValueError('destination must not exist')
    dest.mkdir(mode=0o755, parents=True)
    manifest = {}
    for target, source in FILES.items():
        content = (ROOT / source).read_bytes()
        out = dest / target
        out.parent.mkdir(exist_ok=True)
        out.write_bytes(content)
        out.chmod(0o644)
        manifest[target] = hashlib.sha256(content).hexdigest()
    (dest / 'release.json').write_text(json.dumps({'version': 1, 'files': manifest}, indent=2) + '\n')
    return manifest

if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('destination')
    print(json.dumps({'ok': True, 'files': len(build(parser.parse_args().destination))}))
