"""Offline checks for the exact one-time read bundle and HTML observations."""
import argparse
import hashlib
import json
from pathlib import Path
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parent
REPO = ROOT.parents[3]
WORKFLOW = '.github/workflows/max13-native-read-20261123.yml'


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    args = parser.parse_args()
    binding = json.loads((ROOT / 'binding.json').read_bytes())
    if binding['schema'] != 1 or binding['stage'] != 'read' or binding['operation'] != 'commercial-max13-amp-read-20261123-v1':
        raise ValueError('wrong_operation')
    if binding['source_comment']['id'] != 6961029169 or binding['approval_comment']['id'] != 7072422617:
        raise ValueError('wrong_authority')
    actual = sorted(str(p.relative_to(REPO)) for p in ROOT.rglob('*') if p.is_file() and '__pycache__' not in p.parts and p.name != 'binding.json')
    if actual != sorted(binding['source_files']):
        raise ValueError('source_inventory_changed')
    for name, expected in binding['source_files'].items():
        target = REPO / name
        if target.is_symlink() or hashlib.sha256(target.read_bytes()).hexdigest() != expected:
            raise ValueError('source_bytes_changed:' + name)
        if target.suffix == '.py':
            compile(target.read_bytes(), str(target), 'exec')
        elif target.suffix == '.php':
            subprocess.run([args.php, '-l', str(target)], check=True, capture_output=True)
    if hashlib.sha256((REPO / WORKFLOW).read_bytes()).hexdigest() != binding['workflow_sha256']:
        raise ValueError('workflow_bytes_changed')
    subprocess.run(['node', '--check', str(ROOT / 'admission.js')], check=True)
    import public_probe
    ordinary = public_probe.inspect_page('<meta name="description" content="Hotel &amp; Spa"><p>Hotel &amp; Spa</p>', 'Hotel & Spa')
    assert ordinary['metadata'][0]['equals_native'] is True
    assert ordinary['metadata'][0]['literal_amp_entity_count_after_html_parse'] == 0
    assert ordinary['server_rendered_text_literal_amp_entity_count'] == 0
    doubled = public_probe.inspect_page('<meta name="description" content="Hotel &amp;amp; Spa"><p>Hotel &amp;amp; Spa</p>', 'Hotel & Spa')
    assert doubled['metadata'][0]['equals_native'] is False
    assert doubled['metadata'][0]['equals_native_after_extra_unescape'] is True
    assert doubled['server_rendered_text_literal_amp_entity_count'] == 1
    bootstrap = public_probe.inspect_page('<script>message:{text:"Hotel &amp; Spa"}</script><p>Hotel &amp; Spa</p>', 'Hotel & Spa')
    assert bootstrap['bootstrap_equals_native'] is False
    assert bootstrap['bootstrap_equals_native_after_html_unescape'] is True
    assert bootstrap['server_rendered_text_literal_amp_entity_count'] == 0
    assert bootstrap['browser_javascript_render_observed'] is False
    print(json.dumps({'ok': True, 'exact_source_files': len(actual), 'public_parser_cases': 3, 'network_calls': 0}))


if __name__ == '__main__':
    main()
