#!/usr/bin/env python3
"""Real PHP HTTP endpoint against isolated fixture data; no external requests."""
import base64
import importlib.util
import json
import os
from pathlib import Path
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('lookup_build', ROOT / 'tools/build_max_attribution_lookup.py')
build_module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(build_module)
checks = 0

def check(condition, name):
    global checks
    checks += 1
    if not condition:
        raise AssertionError(name)

with tempfile.TemporaryDirectory(prefix='max-attribution-http-') as directory:
    private_root = Path(directory)
    site = private_root / 'site'
    endpoint = site / 'new/max-attribution'
    manifest = build_module.build(endpoint)
    check(set(manifest) == {'lookup.php', 'services/MaxTrackerLookupProtocol.php', 'services/MaxTrackerLookupRepository.php', 'services/MaxSubscriptionAttributionPolicy.php'}, 'bundle contains only public code')
    private_key = private_root / 'private.pem'
    subprocess.run(['openssl', 'genpkey', '-algorithm', 'RSA', '-pkeyopt', 'rsa_keygen_bits:2048', '-out', str(private_key)], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    private_key.chmod(0o600)
    subprocess.run(['openssl', 'pkey', '-in', str(private_key), '-pubout', '-out', str(endpoint / 'verification.pem')], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    native = bool(os.getenv('MAX_ATTRIBUTION_TEST_MYSQL_DSN'))
    if native:
        check('dbname=max_attribution_test;' in os.environ['MAX_ATTRIBUTION_TEST_MYSQL_DSN'], 'isolated native database')
        settings = site / 'bitrix/.settings.php'
        settings.parent.mkdir()
        # Fixture credentials only; never production credentials or config.
        settings.write_text("<?php return ['connections'=>['value'=>['default'=>['host'=>'127.0.0.1','database'=>'max_attribution_test','login'=>'root','password'=>'fixture-only']]]];\n")
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    command = json.loads(os.getenv('TEST_PHP_COMMAND_JSON', '["php"]'))
    with (private_root / 'server.log').open('wb') as log:
        server = subprocess.Popen(command + ['-S', f'127.0.0.1:{port}', '-t', str(site)], stdout=log, stderr=log)
        try:
            for attempt in range(100):
                if server.poll() is not None:
                    raise RuntimeError('PHP fixture server failed to start')
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=0.1):
                        break
                except OSError:
                    time.sleep(0.03)
            else:
                raise RuntimeError('PHP fixture server unavailable')
            url = f'http://127.0.0.1:{port}/new/max-attribution/lookup.php'
            now = int(time.time())
            data = {'version': 1, 'counter_id': '98615635', 'user_ids': ['123', '404'], 'issued_at': now, 'nonce': 'a' * 32}
            def encoded(value):
                return json.dumps(value, separators=(',', ':')).encode()
            def signature(body, purpose=b'anytour-max-tracker-lookup-v1\n'):
                signed = subprocess.run(['openssl', 'dgst', '-sha256', '-sign', str(private_key)], input=purpose + body, check=True, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL).stdout
                return base64.b64encode(signed).decode()
            def request(body=None, method='POST', content_type='application/json', signed=None):
                headers = {'Content-Type': content_type}
                if signed is not None:
                    headers['X-Max-Attribution-Signature'] = signed
                req = urllib.request.Request(url, data=body, headers=headers, method=method)
                try:
                    response = urllib.request.urlopen(req, timeout=5)
                except urllib.error.HTTPError as error:
                    response = error
                with response:
                    raw = response.read(32769)
                    check(len(raw) <= 32768, 'bounded response')
                    check(response.headers.get('Cache-Control') == 'no-store', 'responses are not cached')
                    return response.status, json.loads(raw)
            body = encoded(data)
            check(request(None, 'GET')[0] == 405, 'GET never returns identities')
            check(request(body, content_type='text/plain')[0] == 415, 'JSON only')
            check(request(body, content_type='application/jsonevil')[0] == 415, 'media type must match exactly')
            check(request(b'x' * 8193)[0] == 413, 'body bound')
            check(request(body)[0] == 401, 'signature required before missing database')
            check(request(body, signed='invalid')[0] == 401, 'malformed signature rejected')
            check(request(body + b' ', signed=signature(body))[0] == 401, 'tampered body rejected')
            check(request(body, signed=signature(body, b'other-purpose\n'))[0] == 401, 'purpose separation')
            for changes in ({'issued_at': now - 91}, {'counter_id': '999'}, {'user_ids': ['1', '1']}, {'user_ids': ['-123']}, {'user_ids': list(map(str, range(1, 27)))}, {'user_ids': [123]}):
                bad = encoded({**data, **changes})
                check(request(bad, signed=signature(bad))[0] == 401, 'signed invalid body rejected')
            status, result = request(body, signed=signature(body))
            if native:
                check(status == 200, 'authenticated lookup reaches native MySQL')
                check(result['users'][0]['client_id'] == '22222222222222222222', 'wire latest correct-counter CID exact')
                check(result['users'][0]['record_id'] == '9007199254740992', 'wire large tracker ID exact')
                check(result['users'][1] == {'external_user_id': '404', 'found': False}, 'wire explicit miss')
                check(result['tracker_timezone'] == 'Europe/London', 'wire timezone contract')
                membership = encoded({**data, 'membership': {'not_before_unix': now - 3600, 'cutoff_unix': now}})
                status, result = request(membership, signed=signature(membership))
                check(status == 200, 'signed membership lookup reaches same endpoint')
                check(result['users'][0]['membership_receipts'][0]['kind'] == 'subscription', 'wire exact membership receipt')
                check(result['users'][0]['membership_receipts'][1]['kind'] == 'retention_30m', 'wire mature retention receipt')
                check(result['users'][0]['membership_receipts'][1]['state'] == 'retained', 'wire complete exact period retained')
                empty = encoded({**data, 'user_ids': []})
                status, result = request(empty, signed=signature(empty))
                check(status == 200 and result['users'] == [], 'signed empty probe')
            else:
                check(status == 503 and result.get('error') == 'lookup_unavailable', 'valid signature passes auth before unavailable fixture database')
            check(private_key.is_relative_to(private_root) and not private_key.is_relative_to(site), 'private key outside HTTP root')
            check(not any(path.name in {'config.php', '.settings.php', 'private.pem'} for path in endpoint.rglob('*')), 'no secret-bearing file in release')
        finally:
            server.terminate()
            try:
                server.wait(timeout=3)
            except subprocess.TimeoutExpired:
                server.kill()
                server.wait()
print(f'PASS {checks} native HTTP signature/bounds/lookup checks ({"mysql" if native else "auth-only"})')
