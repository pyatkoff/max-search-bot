"""Prepared single-scope carrier; uses existing role and a NEW root-owned public key."""
import base64
import hashlib
import json
import os
import re
import subprocess
import tempfile
from pathlib import Path
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import padding
from cryptography.hazmat.primitives.ciphers.aead import AESGCM

PIN = 'SHA256:/5mPWmGBQ6UPnNQDEHLOCy7dCpxdz+FxQ4zVOjqE0F0'


class SafeStop(Exception):
    pass


def execute(work):
    if (os.environ.get('GITHUB_EVENT_NAME') != 'push'
            or os.environ.get('GITHUB_REF') != 'refs/heads/audit/max2-new-goals-20261121'
            or os.environ.get('GITHUB_RUN_ATTEMPT') != '1'
            or not re.fullmatch(r'[0-9a-f]{40}', os.environ.get('GITHUB_SHA', ''))):
        raise SafeStop('ORIGINAL_FIXED_BRANCH_PUSH_REQUIRED')
    try:
        event = json.loads(Path(os.environ['GITHUB_EVENT_PATH']).read_bytes())
    except Exception:
        raise SafeStop('ORIGINAL_BRANCH_CREATION_REQUIRED') from None
    if (event.get('created') is not True or event.get('forced') is not False
            or event.get('before') != '0' * 40
            or event.get('ref') != os.environ['GITHUB_REF']
            or event.get('after') != os.environ['GITHUB_SHA']):
        raise SafeStop('ORIGINAL_BRANCH_CREATION_REQUIRED')
    reader = Path(__file__).resolve().with_name('reader.py').read_bytes()
    expected = os.environ.get('READER_SHA256', '')
    if not re.fullmatch(r'[0-9a-f]{64}', expected) or hashlib.sha256(reader).hexdigest() != expected:
        raise SafeStop('REVIEWED_READER_CHANGED')
    compile(reader, 'anytour-max2-value-reader', 'exec')
    if not all(os.environ.get(k) for k in ('SSH_KEY', 'SSH_HOST', 'SSH_USER')):
        raise SafeStop('ESTABLISHED_SSH_SETTINGS_UNAVAILABLE')
    try:
        raw = os.environ['SSH_KEY'].replace('\r', '')
        raw = raw.replace('\\n', '\n') if 'PRIVATE KEY' in raw else base64.b64decode(raw).decode()
        key = work / 'key'
        key.write_text(raw.rstrip() + '\n')
        key.chmod(0o600)
    except Exception:
        raise SafeStop('SSH_KEY_FORMAT_INVALID') from None
    host = os.environ['SSH_HOST']
    user = os.environ['SSH_USER']
    if not re.fullmatch(r'[A-Za-z0-9_.:-]+', host) or host.startswith('-') or not re.fullmatch(r'[A-Za-z0-9_-]+', user):
        raise SafeStop('SSH_TARGET_INVALID')
    scan = subprocess.run(['ssh-keyscan', '-T', '10', '-t', 'ed25519,ecdsa,rsa', host],
                          capture_output=True, text=True, timeout=35)
    matches = []
    for line in scan.stdout.splitlines():
        fields = line.split()
        if len(fields) < 3 or fields[0].startswith('#'):
            continue
        try:
            fp = 'SHA256:' + base64.b64encode(hashlib.sha256(base64.b64decode(fields[2])).digest()).decode().rstrip('=')
        except Exception:
            continue
        if fp == PIN:
            matches.append(line)
    if not matches:
        raise SafeStop('HOST_CONTINUITY_PIN_MISMATCH_STOP')
    known = work / 'known_hosts'
    known.write_text('\n'.join(matches) + '\n')
    options = ['-i', str(key), '-o', 'IdentitiesOnly=yes', '-o', 'BatchMode=yes',
               '-o', 'StrictHostKeyChecking=yes', '-o', 'UserKnownHostsFile=' + str(known),
               '-o', 'GlobalKnownHostsFile=/dev/null', '-o', 'ConnectTimeout=15',
               '-o', 'ServerAliveInterval=15', '-o', 'ServerAliveCountMax=2']
    remote = 'cd /var/www/anytoour/data/www/d.anytoour.ru && .venv/bin/python -I -B -'
    with (work / 'report.json').open('wb') as output, (work / 'error').open('wb') as error:
        try:
            process = subprocess.run(['ssh', *options, user + '@' + host, remote], input=reader,
                                     stdout=output, stderr=error, timeout=100)
        except subprocess.TimeoutExpired:
            raise SafeStop('SSH_EXECUTION_TIMEOUT') from None
    if process.returncode:
        detail = (work / 'error').read_text(errors='replace')[:8000]
        if 'No such file or directory' in detail or "can't cd" in detail:
            raise SafeStop('REMOTE_DIRECTORY_OR_VENV_MISSING')
        if 'Permission denied' in detail:
            raise SafeStop('SSH_AUTHENTICATION_OR_PERMISSION_FAILED')
        if 'Host key verification failed' in detail:
            raise SafeStop('HOST_VERIFICATION_FAILED')
        raise SafeStop('SSH_EXECUTION_FAILED')
    path = work / 'report.json'
    if path.stat().st_size > 32_768:
        raise SafeStop('REPORT_SIZE_BOUND')
    report = json.loads(path.read_bytes())
    import runpy
    model = runpy.run_path(str(Path(__file__).resolve().with_name('reader.py')),
                          run_name='validate_retained_catalog')
    try:
        model['validate_projected_report'](report)
    except Exception:
        raise SafeStop('REPORT_SCOPE_OR_PROOF_INVALID') from None
    return report


def report_public_key():
    try:
        raw = base64.b64decode(os.environ['REPORT_PUBLIC_KEY_B64'], validate=True)
        expected = os.environ['REPORT_PUBLIC_KEY_SHA256']
        if not re.fullmatch(r'[0-9a-f]{64}', expected) or hashlib.sha256(raw).hexdigest() != expected:
            raise ValueError()
        public = serialization.load_pem_public_key(raw)
        from cryptography.hazmat.primitives.asymmetric import rsa
        if not isinstance(public, rsa.RSAPublicKey) or public.key_size != 3072:
            raise ValueError()
        return public
    except Exception:
        raise SafeStop('NEW_SCOPED_REPORT_PUBLIC_KEY_REQUIRED') from None


def main():
    os.umask(0o077)
    public = report_public_key()  # Fail before credentials/SSH if root has not prepared the key.
    with tempfile.TemporaryDirectory(prefix='anytour-max2-', dir=os.environ['RUNNER_TEMP']) as temp:
        try:
            report = execute(Path(temp))
        except SafeStop as error:
            report = {'status': 'TRANSPORT_OR_REPORT_FAILED', 'error_code': str(error)}
        except Exception:
            report = {'status': 'TRANSPORT_OR_REPORT_FAILED', 'error_code': 'RUNNER_FAILED'}
        data = json.dumps(report, ensure_ascii=False, allow_nan=False).encode()
        key, nonce = AESGCM.generate_key(bit_length=256), os.urandom(12)
        aad = ('anytour-max2-new-goals-98615635-20261121-' + os.environ['GITHUB_SHA']).encode()
        wrapped = public.encrypt(key, padding.OAEP(mgf=padding.MGF1(hashes.SHA256()), algorithm=hashes.SHA256(), label=None))
        enc = AESGCM(key).encrypt(nonce, data, aad)
        b64 = lambda value: base64.b64encode(value).decode()
        envelope = {'schema': 1, 'workflow_sha': os.environ['GITHUB_SHA'], 'aad': b64(aad),
                    'nonce': b64(nonce), 'wrapped_key': b64(wrapped), 'ciphertext': b64(enc)}
        output = Path('encrypted-max2-new-goals')
        output.mkdir(exist_ok=True)
        (output / 'anytour-max2-new-goals.enc.json').write_text(json.dumps(envelope))
        state = report.get('status')
        print('READ_RESULT=' + (state if state in ('COLLECTED', 'READ_FAILED', 'TRANSPORT_OR_REPORT_FAILED') else 'UNKNOWN'))
        print('ENCRYPTED_REPORT_READY')


if __name__ == '__main__':
    main()

