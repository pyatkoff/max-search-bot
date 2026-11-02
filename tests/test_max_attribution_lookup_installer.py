#!/usr/bin/env python3
"""Run the fixed installer PHP against disposable public-code fixtures only."""
import base64
import copy
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import stat
import subprocess
import tempfile
import textwrap

ROOT = Path(__file__).resolve().parents[1]
SITE_LITERAL = "/var/www/www-root/data/www/tour-max.ru"
PHP = json.loads(os.getenv('TEST_PHP_COMMAND_JSON', '["php"]'))
checks = 0


def check(value, name):
    global checks
    checks += 1
    if not value:
        raise AssertionError(name)


def module(name, relative):
    spec = importlib.util.spec_from_file_location(name, ROOT / relative)
    result = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(result)
    return result


installer = module('lookup_installer', 'tools/install_max_attribution_lookup.py')
builder = module('lookup_builder', 'tools/build_max_attribution_lookup.py')


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def public_key_fixture(last_byte=0x13):
    # A structurally valid 2048-bit RSA public key only. No private key is
    # generated, loaded, written or needed for installation guard tests.
    modulus = b'\x00\x80' + b'\x11' * 254 + bytes([last_byte])
    rsa = b'\x30\x82\x01\x0a' + b'\x02\x82\x01\x01' + modulus + b'\x02\x03\x01\x00\x01'
    algorithm = bytes.fromhex('300d06092a864886f70d0101010500')
    der = b'\x30\x82\x01\x22' + algorithm + b'\x03\x82\x01\x0f\x00' + rsa
    encoded = base64.b64encode(der).decode('ascii')
    pem = ('-----BEGIN PUBLIC KEY-----\n' + '\n'.join(textwrap.wrap(encoded, 64))
           + '\n-----END PUBLIC KEY-----\n').encode('ascii')
    return pem, sha(der)


PUBLIC_KEY, FIXTURE_KEY_PIN = public_key_fixture()


def fixture(case):
    site = case / 'site'
    dest = site / 'new/max-attribution'
    (dest / 'services').mkdir(parents=True)
    hashes = {}
    for name in installer.FILES:
        raw = ('<?php /* synthetic predecessor: ' + name + ' */\n').encode('ascii')
        (dest / name).write_bytes(raw)
        hashes[name] = sha(raw)
    manifest = (json.dumps({'version': 1, 'files': hashes}, indent=2) + '\n').encode('ascii')
    (dest / 'release.json').write_bytes(manifest)
    (dest / 'verification.pem').write_bytes(PUBLIC_KEY)
    preimage = {'version': 1, 'files': hashes, 'release_json_sha256': sha(manifest)}
    return site, dest, preimage


def tree_snapshot(case):
    result = {}
    for path in sorted(case.rglob('*')):
        info = path.lstat()
        name = str(path.relative_to(case))
        if stat.S_ISLNK(info.st_mode):
            result[name] = ('link', os.readlink(path))
        elif stat.S_ISREG(info.st_mode):
            result[name] = ('file', sha(path.read_bytes()), info.st_nlink, stat.S_IMODE(info.st_mode))
        else:
            result[name] = ('directory', stat.S_IMODE(info.st_mode))
    return result


def run_fixture(site, payload, preimage):
    # Production has no path option or alternate key pin. These exact literal
    # substitutions exist only in this isolated test process.
    script = installer._remote_script(payload, preimage)
    check(script.count(SITE_LITERAL) == 1, 'one fixed production destination')
    check(script.count(installer.PUBLIC_KEY_DER_SHA256) == 1, 'one fixed production key pin')
    script = script.replace(SITE_LITERAL, str(site))
    script = script.replace(installer.PUBLIC_KEY_DER_SHA256, FIXTURE_KEY_PIN)
    return subprocess.run(PHP + ['-d', 'display_errors=0', '-d', 'log_errors=0'],
                          input=script, text=True, capture_output=True, timeout=20)


def refused_unchanged(case, site, payload, preimage, name):
    before = tree_snapshot(case)
    result = run_fixture(site, payload, preimage)
    check(result.returncode == 2, name + ' refuses')
    receipt = json.loads(result.stdout)
    check(receipt.get('ok') is False, name + ' failure receipt')
    check(tree_snapshot(case) == before, name + ' zero changes')


with tempfile.TemporaryDirectory(prefix='max-attribution-install-') as directory:
    root = Path(directory)
    bundle = root / 'release'
    builder.build(bundle)
    payload = installer._read_bundle(bundle)
    check(set(payload) == set(installer.FILES) | {'release.json'}, 'only pinned public code and manifest')

    explicit = root / 'expected-preimage.json'
    explicit.write_text(json.dumps(installer.CHECKED_PREIMAGE))
    check(installer._read_preimage(explicit) == installer.CHECKED_PREIMAGE, 'explicit checked source candidate admitted')
    altered = copy.deepcopy(installer.CHECKED_PREIMAGE)
    altered['files']['lookup.php'] = '0' * 64
    explicit.write_text(json.dumps(altered))
    try:
        installer._read_preimage(explicit)
    except ValueError:
        check(True, 'arbitrary predecessor rejected locally')
    else:
        raise AssertionError('unchecked predecessor admitted')

    case = root / 'success'
    site, dest, preimage = fixture(case)
    key_before = (dest / 'verification.pem').read_bytes()
    result = run_fixture(site, payload, preimage)
    check(result.returncode == 0, 'correct preimage installs: ' + result.stdout + result.stderr)
    receipt = json.loads(result.stdout)
    check(receipt.get('ok') is True and receipt.get('preimage_verified') is True, 'verified upgrade receipt')
    for name, encoded in payload.items():
        check((dest / name).read_bytes() == base64.b64decode(encoded), 'exact target readback ' + name)
    check((dest / 'verification.pem').read_bytes() == key_before, 'public verification bytes preserved')
    check(set(str(p.relative_to(dest)) for p in dest.rglob('*') if p.is_file())
          == set(payload) | {'verification.pem'}, 'no extra file or staging remnant')

    case = root / 'wrong-preimage'
    site, dest, preimage = fixture(case)
    (dest / 'lookup.php').write_bytes(b'<?php /* unrelated existing version */\n')
    refused_unchanged(case, site, payload, preimage, 'wrong preimage')

    case = root / 'wrong-manifest'
    site, dest, preimage = fixture(case)
    (dest / 'release.json').write_bytes(b'{}\n')
    refused_unchanged(case, site, payload, preimage, 'wrong predecessor manifest')

    case = root / 'wrong-key'
    site, dest, preimage = fixture(case)
    (dest / 'verification.pem').write_bytes(public_key_fixture(0x15)[0])
    refused_unchanged(case, site, payload, preimage, 'unapproved verification key')

    directories = ('site', 'site/new', 'site/new/max-attribution', 'site/new/max-attribution/services')
    for index, relative in enumerate(directories):
        case = root / ('directory-link-' + str(index))
        site, dest, preimage = fixture(case)
        path = case / relative
        moved = path.with_name(path.name + '-real')
        path.rename(moved)
        path.symlink_to(moved, target_is_directory=True)
        refused_unchanged(case, site, payload, preimage, 'directory symlink ' + relative)

    public_names = tuple(installer.FILES) + ('release.json', 'verification.pem')
    for index, name in enumerate(public_names):
        case = root / ('file-link-' + str(index))
        site, dest, preimage = fixture(case)
        path = dest / name
        outside = case / 'outside-public-fixture'
        outside.write_bytes(path.read_bytes())
        path.unlink()
        path.symlink_to(outside)
        refused_unchanged(case, site, payload, preimage, 'file symlink ' + name)

        case = root / ('file-hardlink-' + str(index))
        site, dest, preimage = fixture(case)
        os.link(dest / name, case / 'outside-hardlink-fixture')
        refused_unchanged(case, site, payload, preimage, 'file hardlink ' + name)

    case = root / 'extra-subtree-member'
    site, dest, preimage = fixture(case)
    (dest / 'unexpected.txt').write_bytes(b'public fixture\n')
    refused_unchanged(case, site, payload, preimage, 'unexpected existing subtree file')

print(f'PASS {checks} guarded public installer fixture checks')
