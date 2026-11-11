"""One owner-requested read: pinned SSH, two fixed readers, ciphertext only."""
import base64
import hashlib
import json
import os
import re
import subprocess
import tempfile
from pathlib import Path

PIN = 'SHA256:/5mPWmGBQ6UPnNQDEHLOCy7dCpxdz+FxQ4zVOjqE0F0'
ROOT = Path('audit/ad-region-20261111')
MAX_REPORT = 8_000_000


class SafeStop(Exception):
    pass


def ssh_options(work):
    if not all(os.environ.get(k) for k in ('SSH_KEY', 'SSH_HOST', 'SSH_USER')):
        raise SafeStop('ESTABLISHED_SSH_SETTINGS_UNAVAILABLE')
    try:
        raw = os.environ['SSH_KEY'].replace('\r', '')
        raw = raw.replace('\\n', '\n') if 'PRIVATE KEY' in raw else base64.b64decode(''.join(raw.split()), validate=True).decode()
        key = work / 'key'
        key.write_text(raw.rstrip() + '\n')
        key.chmod(0o600)
    except Exception:
        raise SafeStop('SSH_KEY_FORMAT_INVALID') from None
    host, user = os.environ['SSH_HOST'], os.environ['SSH_USER']
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
            fp = 'SHA256:' + base64.b64encode(hashlib.sha256(base64.b64decode(fields[2], validate=True)).digest()).decode().rstrip('=')
        except Exception:
            continue
        if fp == PIN:
            matches.append(line)
    if not matches:
        raise SafeStop('HOST_CONTINUITY_PIN_MISMATCH_STOP')
    known = work / 'known_hosts'
    known.write_text('\n'.join(matches) + '\n')
    return ['ssh', '-i', str(key), '-o', 'IdentitiesOnly=yes', '-o', 'BatchMode=yes',
            '-o', 'StrictHostKeyChecking=yes', '-o', 'UserKnownHostsFile=' + str(known),
            '-o', 'GlobalKnownHostsFile=/dev/null', '-o', 'ConnectTimeout=15',
            '-o', 'ServerAliveInterval=15', '-o', 'ServerAliveCountMax=2', user + '@' + host]


def read_once(ssh, work, name, filename, expected, command, timeout):
    source = (ROOT / filename).read_bytes()
    if not re.fullmatch('[0-9a-f]{64}', expected) or hashlib.sha256(source).hexdigest() != expected:
        raise SafeStop('REVIEWED_READER_CHANGED')
    out, err = work / (name + '.json'), work / (name + '.stderr')
    with out.open('wb') as output, err.open('wb') as error:
        try:
            result = subprocess.run([*ssh, command], input=source, stdout=output, stderr=error, timeout=timeout)
        except subprocess.TimeoutExpired:
            raise SafeStop('SSH_EXECUTION_TIMEOUT') from None
    if result.returncode not in (0,2) or (result.returncode == 2 and name != 'application') or (result.returncode and not out.stat().st_size):
        raise SafeStop('SSH_READER_FAILED')
    if out.stat().st_size > MAX_REPORT:
        raise SafeStop('REPORT_SIZE_BOUND')
    try:
        report = json.loads(out.read_bytes())
    except Exception:
        raise SafeStop('REMOTE_REPORT_INVALID') from None
    if not isinstance(report, dict) or report.get('status') not in ('COLLECTED','PARTIAL','READ_FAILED'):
        raise SafeStop('REMOTE_REPORT_INVALID')
    if result.returncode == 2 and report['status'] == 'COLLECTED':
        raise SafeStop('REMOTE_EXIT_STATUS_MISMATCH')
    if any(report.get(k) is not False for k in ('server_writes','advertising_writes','token_exported')):
        raise SafeStop('REMOTE_SAFETY_FLAGS_INVALID')
    if name == 'direct' and (report.get('audit_kind') != 'direct_advertising_region_v1' or report.get('database_opened') is not False or report.get('api_writes') is not False):
        raise SafeStop('DIRECT_SCOPE_INVALID')
    if name == 'application':
        if report.get('schema') != 'anytour-advertising-entry-inventory-v1' or report.get('database_changed') is not False:
            raise SafeStop('APPLICATION_SCOPE_INVALID')
        if report['status'] != 'READ_FAILED' and (report.get('application_sha') != os.environ['EXPECTED_APPLICATION_SHA'] or report.get('request_nonce') != os.environ['AUDIT_REQUEST_ID'] or report.get('customer_rows_exported') is not False):
            raise SafeStop('APPLICATION_CAPTURE_BINDING_INVALID')
    report['_ssh_exit_code'] = result.returncode
    return report


def main():
    os.umask(0o077)
    nonce = os.environ['AUDIT_REQUEST_ID']
    app = os.environ['EXPECTED_APPLICATION_SHA']
    if not re.fullmatch('[0-9a-f]{32}', nonce) or not re.fullmatch('[0-9a-f]{40}', app):
        raise SafeStop('AUDIT_SCOPE_INVALID')
    report = {'contract': 'advertising-region-audit-v1', 'request_id': nonce,
              'workflow_sha': os.environ['GITHUB_SHA'], 'expected_application_sha': app,
              'server_writes': False, 'advertising_writes': False, 'database_changed': False,
              'token_exported': False, 'reports': {}}
    with tempfile.TemporaryDirectory(prefix='ad-region-', dir=os.environ['RUNNER_TEMP']) as temp:
        work = Path(temp)
        try:
            ssh = ssh_options(work)
            tasks = [
                ('direct', 'direct_reader.py', os.environ['DIRECT_READER_SHA256'],
                 'cd /var/www/anytoour/data/www/d.anytoour.ru && .venv/bin/python -I -B -', 310),
                ('application', 'application_reader.php', os.environ['APP_READER_SHA256'],
                 'cd /var/www/anytoour/data/www/app.anytoour.ru && php /dev/stdin --root=/var/www/anytoour/data/www/app.anytoour.ru --expected-sha=' + app + ' --request-nonce=' + nonce, 100),
            ]
            for name, filename, expected, command, timeout in tasks:
                try:
                    report['reports'][name] = read_once(ssh, work, name, filename, expected, command, timeout)
                except SafeStop as error:
                    report['reports'][name] = {'status': 'TRANSPORT_OR_REPORT_FAILED', 'error_code': str(error)}
                except Exception:
                    report['reports'][name] = {'status': 'TRANSPORT_OR_REPORT_FAILED', 'error_code': 'READER_RUNNER_FAILED'}
        except SafeStop as error:
            report['error_code'] = str(error)
        except Exception:
            report['error_code'] = 'RUNNER_FAILED'
        raw = json.dumps(report, ensure_ascii=False, allow_nan=False).encode()
        if len(raw) > MAX_REPORT:
            raw = json.dumps({'contract': report['contract'], 'request_id': nonce,
                              'error_code': 'COMBINED_REPORT_SIZE_BOUND'}).encode()
        # Built-in Node cryptography keeps the established OAEP-SHA256/AES-GCM
        # envelope without installing any runner or production package.
        encrypted = subprocess.run(['node', str(ROOT / 'encrypt.cjs')], input=raw,
                                   capture_output=True, timeout=30, env={k: os.environ[k] for k in ('PATH','REPORT_PUBLIC_KEY_B64','GITHUB_SHA','AUDIT_REQUEST_ID')})
        if encrypted.returncode or len(encrypted.stdout) > 12_000_000:
            print('AUDIT_ENCRYPTION_FAILED')
            raise SystemExit(1)
        envelope = json.loads(encrypted.stdout)
        output = Path('encrypted-ad-region')
        output.mkdir(mode=0o700, exist_ok=False)
        (output / 'advertising-region.enc.json').write_text(json.dumps(envelope))
        print('ENCRYPTED_AUDIT_PACKET_READY')
        # Collection errors are encrypted too. Job success is never API success.


if __name__ == '__main__':
    main()
