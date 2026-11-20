"""Single original audit read; private identity is RSA sealed before leaving PHP."""
import base64
import datetime as dt
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile

from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import padding, rsa
from cryptography.hazmat.primitives.ciphers.aead import AESGCM

COMPONENT = 'app'
REPO = 'pyatkoff/max-search-bot'
BRANCH = 'audit/max2-hotel-cohort-app-fixture-20261120'
BASE = 'b4b65daaa472c15426a244b87ede3e6c0394626b'
ROOT = Path('audit/max2-hotel-cohort-20261120/' + COMPONENT)
PIN = 'SHA256:/5mPWmGBQ6UPnNQDEHLOCy7dCpxdz+FxQ4zVOjqE0F0'
REMOTE = 'unset GIT_DIR GIT_WORK_TREE GIT_COMMON_DIR GIT_INDEX_FILE GIT_CONFIG_PARAMETERS GIT_CONFIG_COUNT GIT_OBJECT_DIRECTORY GIT_ALTERNATE_OBJECT_DIRECTORIES; cd /var/www/anytoour/data/www/app.anytoour.ru && GIT_OPTIONAL_LOCKS=0 php -d display_errors=0 -d log_errors=0'
KEY_MARKER = '__M2A_REPORT_PUBLIC_KEY_B64__'
MAX_OUTPUT = 16777216
WINDOW = {'from_utc': '2026-11-11T21:00:00Z', 'until_utc_exclusive': '2026-11-18T21:00:00Z'}
FORBIDDEN = frozenset(('user_decimal', 'cid_decimal', 'yclid_if_relevant', 'client_id', 'yclid', 'external_user_id', 'external_chat_id', 'UF_CHAT_ID', 'UF_CLIENT_ID', 'UF_YCLID', 'initData', 'raw_init_data', 'password', 'private_key', 'post_text', 'snapshot_json', 'profile_json', 'customer_key', 'customer_id', 'conversation_id'))

class Stop(Exception):
    pass

def need(condition, code):
    if not condition:
        raise Stop(code)

def sha(data):
    return hashlib.sha256(data).hexdigest()

def source_pins():
    result = {}
    for name, env in (('program.php', 'PROGRAM_SHA256'), ('tests.php', 'TESTS_SHA256'), ('runner.py', 'RUNNER_SHA256'), ('validation.py', 'VALIDATION_SHA256')):
        expected = os.environ.get(env, '')
        need(re.fullmatch('[a-f0-9]{64}', expected) is not None, 'SOURCE_PIN_MISSING')
        need(sha((ROOT / name).read_bytes()) == expected, 'SOURCE_CHANGED')
        result[name] = expected
    return result

def event_identity():
    e = os.environ
    need(e.get('GITHUB_REPOSITORY') == REPO and e.get('GITHUB_ACTOR') == 'pyatkoff'
         and e.get('GITHUB_EVENT_NAME') == 'push' and e.get('GITHUB_RUN_ATTEMPT') == '1'
         and e.get('GITHUB_REF') == 'refs/heads/' + BRANCH, 'ORIGINAL_EVENT_REQUIRED')
    head, run = e.get('GITHUB_SHA', ''), e.get('GITHUB_RUN_ID', '')
    need(re.fullmatch('[a-f0-9]{40}', head) is not None and re.fullmatch('[1-9][0-9]{0,19}', run) is not None, 'EVENT_ID_INVALID')
    event = json.loads(Path(e['GITHUB_EVENT_PATH']).read_bytes())
    need(event.get('created') is True and event.get('deleted') is False and event.get('forced') is False
         and event.get('before') == '0' * 40 and event.get('after') == head
         and event.get('ref') == e['GITHUB_REF'], 'ORIGINAL_CREATION_REQUIRED')
    return head, int(run)

def api(path):
    raw = subprocess.check_output(['gh', 'api', '--method', 'GET', 'repos/' + REPO + '/' + path], timeout=20, stderr=subprocess.DEVNULL)
    need(len(raw) <= 1000000, 'PROVENANCE_BOUND')
    return json.loads(raw)

def admission_file():
    return Path(os.environ['RUNNER_TEMP']) / ('max2-hotel-' + COMPONENT + '-admission.json')

def admit():
    head, run = event_identity()
    pins = source_pins()
    actual = subprocess.check_output(['git', 'rev-parse', 'HEAD'], text=True, timeout=10).strip()
    parent = subprocess.check_output(['git', 'rev-parse', 'HEAD^'], text=True, timeout=10).strip()
    need(actual == head and parent == BASE, 'CHECKOUT_PROVENANCE_MISMATCH')
    need(api('git/ref/heads/main')['object']['sha'] == BASE, 'BASE_MOVED')
    need(api('git/ref/heads/' + BRANCH)['object']['sha'] == head, 'CARRIER_MOVED')
    native = api('actions/runs/' + str(run))
    need(native.get('id') == run and native.get('head_sha') == head and native.get('run_attempt') == 1
         and native.get('event') == 'push' and native.get('head_branch') == BRANCH, 'RUN_PROVENANCE_MISMATCH')
    php = ['php', '-n']
    tokenizer = subprocess.run(php + ['-r', "exit(function_exists('token_get_all') ? 0 : 9);"], timeout=10,
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    if COMPONENT == 'tracker' and tokenizer.returncode == 9:
        php += ['-d', 'extension=tokenizer']
    for name in ('program.php', 'tests.php'):
        subprocess.run(php + ['-l', str(ROOT / name)], check=True, timeout=10, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    test_args = [str(ROOT / 'tests.php')] + ([str(Path.cwd())] if COMPONENT == 'app' else [])
    subprocess.run(php + test_args, check=True, timeout=40)
    admission_file().write_text(json.dumps({'state': 'SELECT_ONLY_ADMITTED', 'component': COMPONENT, 'head': head,
        'run': run, 'base': BASE, 'pins': pins, 'window': WINDOW, 'observed_at': dt.datetime.now(dt.timezone.utc).isoformat()}))
    print('SELECT_ONLY_ADMITTED')

def verify_admission():
    head, run = event_identity()
    a = json.loads(admission_file().read_bytes())
    need(a.get('state') == 'SELECT_ONLY_ADMITTED' and a.get('component') == COMPONENT and a.get('head') == head
         and a.get('run') == run and a.get('base') == BASE and a.get('pins') == source_pins()
         and a.get('window') == WINDOW, 'ADMISSION_BINDING_INVALID')
    age = (dt.datetime.now(dt.timezone.utc) - dt.datetime.fromisoformat(a['observed_at'])).total_seconds()
    need(0 <= age <= 120, 'ADMISSION_EXPIRED')
    return a

def public_key():
    encoded = os.environ.get('REPORT_PUBLIC_KEY_B64', '')
    need(re.fullmatch('[A-Za-z0-9+/=]+', encoded) is not None, 'REPORT_PUBLIC_KEY_INVALID')
    pem = base64.b64decode(encoded, validate=True)
    need(pem.startswith(b'-----BEGIN PUBLIC KEY-----') and b'PRIVATE' not in pem, 'REPORT_PUBLIC_KEY_INVALID')
    key = serialization.load_pem_public_key(pem)
    need(isinstance(key, rsa.RSAPublicKey) and key.key_size == 3072, 'REPORT_PUBLIC_KEY_INVALID')
    return encoded, key

def privacy_check(value, depth=0):
    need(depth <= 40, 'REPORT_DEPTH_BOUND')
    if isinstance(value, dict):
        need(not (set(value) & FORBIDDEN), 'PLAINTEXT_PRIVATE_FIELD')
        for key, child in value.items():
            need(isinstance(key, str) and len(key) <= 160, 'REPORT_KEY_INVALID')
            if key == 'identity_capsule_b64' and child is not None:
                need(isinstance(child, str), 'CAPSULE_TYPE_INVALID')
                need(len(base64.b64decode(child, validate=True)) == 384, 'CAPSULE_SIZE_INVALID')
            privacy_check(child, depth + 1)
    elif isinstance(value, list):
        need(len(value) <= 25001, 'REPORT_ROW_BOUND')
        for child in value:
            privacy_check(child, depth + 1)
    elif isinstance(value, str):
        need(len(value.encode('utf-8')) <= 8192, 'REPORT_STRING_BOUND')
    elif value is not None:
        need(type(value) in (int, float, bool), 'REPORT_SCALAR_INVALID')

def validate(report):
    need(isinstance(report, dict) and type(report.get('ok')) is bool, 'REPORT_SHAPE_INVALID')
    privacy_check(report)
    for name in ('db_writes', 'provider_calls', 'message_sends'):
        need(type(report.get(name)) is int and report[name] == 0, 'READ_ONLY_PROOF_MISSING')
    if report['ok']:
        need(isinstance(report.get('aggregate'), dict), 'AGGREGATE_MISSING')
        need(isinstance(report.get('bridge'), dict), 'BRIDGE_MISSING')
        need(report['bridge'].get('inner_scheme') == 'RSA-OAEP-SHA1', 'CAPSULE_SCHEME_INVALID')
    return report

def validate_component(report):
    spec = importlib.util.spec_from_file_location('audit_component_validation', ROOT / 'validation.py')
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    try:
        return getattr(module, 'validate_' + COMPONENT)(report)
    except module.ValidationError as exc:
        code = str(exc)
        need(re.fullmatch('[A-Za-z0-9_]{1,100}', code) is not None, 'COMPONENT_VALIDATION_FAILED')
        raise Stop(code) from None

def execute(work):
    admission = verify_admission()
    encoded, _ = public_key()
    need(all(os.environ.get(k) for k in ('SSH_KEY', 'SSH_HOST', 'SSH_USER')), 'ESTABLISHED_SSH_SETTINGS_UNAVAILABLE')
    raw = os.environ['SSH_KEY'].replace('\r', '')
    raw = raw.replace('\\n', '\n') if 'PRIVATE KEY' in raw else base64.b64decode(raw, validate=True).decode()
    keyfile = work / 'ssh-key'; keyfile.write_text(raw.rstrip() + '\n'); keyfile.chmod(0o600)
    host, user = os.environ['SSH_HOST'], os.environ['SSH_USER']
    need(re.fullmatch('[A-Za-z0-9_.:-]+', host) is not None and not host.startswith('-')
         and re.fullmatch('[A-Za-z0-9_-]+', user) is not None, 'SSH_DESTINATION_INVALID')
    scan = subprocess.run(['ssh-keyscan', '-T', '10', '-t', 'ed25519,ecdsa,rsa', host], capture_output=True, text=True, timeout=35)
    matches = []
    for line in scan.stdout.splitlines():
        fields = line.split()
        if len(fields) < 3 or fields[0].startswith('#'):
            continue
        try:
            fingerprint = 'SHA256:' + base64.b64encode(hashlib.sha256(base64.b64decode(fields[2], validate=True)).digest()).decode().rstrip('=')
        except Exception:
            continue
        if fingerprint == PIN:
            matches.append(line)
    need(bool(matches), 'HOST_CONTINUITY_PIN_MISMATCH')
    known = work / 'known_hosts'; known.write_text('\n'.join(matches) + '\n')
    args = ['-i', str(keyfile), '-o', 'IdentitiesOnly=yes', '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes',
        '-o', 'UserKnownHostsFile=' + str(known), '-o', 'GlobalKnownHostsFile=/dev/null', '-o', 'ConnectTimeout=10',
        '-o', 'ServerAliveInterval=15', '-o', 'ServerAliveCountMax=2']
    need((dt.datetime.now(dt.timezone.utc) - dt.datetime.fromisoformat(admission['observed_at'])).total_seconds() <= 120, 'ADMISSION_EXPIRED')
    program = (ROOT / 'program.php').read_text()
    need(program.count(KEY_MARKER) == 1, 'PUBLIC_KEY_MARKER_INVALID')
    program = program.replace(KEY_MARKER, encoded)
    with (work / 'stdout').open('wb') as out, (work / 'stderr').open('wb') as err:
        native = subprocess.run(['ssh', *args, user + '@' + host, REMOTE], input=program.encode(), stdout=out, stderr=err, timeout=75)
    stdout = work / 'stdout'
    need(0 < stdout.stat().st_size <= MAX_OUTPUT, 'NATIVE_OUTPUT_BOUND')
    report = validate_component(validate(json.loads(stdout.read_bytes())))
    need(native.returncode == 0 or report['ok'] is False, 'NATIVE_EXIT_MISMATCH')
    return report

def read():
    _, key = public_key()
    with tempfile.TemporaryDirectory(prefix='max2-hotel-' + COMPONENT + '-', dir=os.environ['RUNNER_TEMP']) as temporary:
        try:
            report = execute(Path(temporary))
        except Stop as exc:
            report = {'ok': False, 'transport_error': str(exc), 'db_writes': 0, 'provider_calls': 0, 'message_sends': 0}
        except Exception:
            report = {'ok': False, 'transport_error': 'NATIVE_READ_UNAVAILABLE', 'db_writes': 0, 'provider_calls': 0, 'message_sends': 0}
        data = json.dumps(report, ensure_ascii=False, allow_nan=False, separators=(',', ':')).encode()
        need(len(data) <= MAX_OUTPUT, 'ENCRYPTED_OUTPUT_BOUND')
        header = {'schema': 'max2_hotel_cohort_envelope_v1', 'component': COMPONENT, 'repository': REPO,
            'workflow_sha': os.environ['GITHUB_SHA'], 'run_id': int(os.environ['GITHUB_RUN_ID']), 'base_sha': BASE,
            'inner_scheme': 'RSA-OAEP-SHA1', 'outer_scheme': 'AES-256-GCM/RSA-OAEP-SHA256'}
        aad = json.dumps(header, sort_keys=True, separators=(',', ':')).encode()
        aes_key, nonce = AESGCM.generate_key(bit_length=256), os.urandom(12)
        wrapped = key.encrypt(aes_key, padding.OAEP(mgf=padding.MGF1(hashes.SHA256()), algorithm=hashes.SHA256(), label=None))
        enc = lambda b: base64.b64encode(b).decode()
        envelope = dict(header, aad=enc(aad), nonce=enc(nonce), wrapped_key=enc(wrapped), ciphertext=enc(AESGCM(aes_key).encrypt(nonce, data, aad)))
        destination = Path('encrypted-max2-hotel-' + COMPONENT); destination.mkdir(exist_ok=True)
        (destination / (COMPONENT + '.enc.json')).write_text(json.dumps(envelope))
        print('READ_RESULT=' + ('COLLECTED' if report['ok'] else 'READ_FAILED'))
        print('ENCRYPTED_REPORT_READY')

def main():
    os.umask(0o077)
    need(len(sys.argv) == 2 and sys.argv[1] in ('admit', 'read'), 'MODE_INVALID')
    if sys.argv[1] == 'admit':
        admit()
    else:
        read()

if __name__ == '__main__':
    try:
        main()
    except Exception:
        print('ANALYTICS_SAFE_STOP', file=sys.stderr)
        sys.exit(1)
