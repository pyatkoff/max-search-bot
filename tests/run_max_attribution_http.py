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
            for changes in (
                {'history_member_page_after': '0'},
                {'user_ids': [], 'history_member_page_after': '0', 'member_page_after': '0'},
                {'user_ids': [], 'history_member_page_after': '0', 'membership': {'not_before_unix': now - 3600, 'cutoff_unix': now}},
                {'user_ids': [], 'history_member_page_after': 'mh1.invalid'},
                {'user_ids': [], 'history_member_page_after': 0},
            ):
                bad = encoded({**data, **changes})
                check(request(bad, signed=signature(bad))[0] == 401, 'history selector rejects users, mixed purposes, or malformed cursors before database access')
            status, result = request(body, signed=signature(body))
            if native:
                check(status == 200, 'authenticated lookup reaches native MySQL')
                check(result['users'][0]['client_id'] == '777', 'wire INT zero metric source uses the correct fallback CID')
                check(result['users'][0]['record_id'] == '9007199254740998', 'wire large tracker ID remains exact after INT source matching')
                check(result['users'][1] == {'external_user_id': '404', 'found': False}, 'wire explicit miss')
                check(result['tracker_timezone'] == 'Europe/London', 'wire timezone contract')
                membership = encoded({**data, 'membership': {'not_before_unix': now - 3600, 'cutoff_unix': now}})
                status, result = request(membership, signed=signature(membership))
                check(status == 200, 'signed membership lookup reaches same endpoint')
                check(result['users'][0]['membership_receipts'][0]['kind'] == 'subscription', 'wire exact membership receipt')
                check(result['users'][0]['membership_receipts'][1]['kind'] == 'retention_30m', 'wire mature retention receipt')
                check(result['users'][0]['membership_receipts'][1]['state'] == 'retained', 'wire complete exact period retained')
                member_page = encoded({**data, 'user_ids': [], 'member_page_after': '0'})
                status, result = request(member_page, signed=signature(member_page))
                check(status == 503 and result.get('error') == 'lookup_unavailable', 'signed legacy member page is explicitly unavailable')
                check('member_page' not in result and 'users' not in result, 'unavailable legacy page exposes no subscribers or billable member values')
                history = encoded({**data, 'user_ids': [], 'history_member_page_after': '0'})
                status, result = request(history, signed=signature(history))
                check(status == 200, 'signed observed-history lookup reaches the canonical endpoint')
                check(set(result) == {'ok', 'version', 'counter_id', 'tracker_timezone', 'history_member_page'}, 'history wire exposes a separate exact response without identity lookup or legacy members')
                page = result['history_member_page']
                page_keys = {'basis', 'rows', 'cutoff_unix', 'history_fence_id', 'next_cursor', 'has_more', 'scanned_event_count', 'omitted_unknown_count', 'coverage'}
                row_keys = {'external_user_id', 'source_id', 'state', 'last_event_record_id', 'last_event_recorded_at_unix', 'episode_join_record_id', 'episode_join_recorded_at_unix', 'entry_basis'}
                check(set(page) == page_keys, 'history wire page has only evidence and bounded coverage metadata')
                check(page['basis'] == 'retained_membership_history_v1' and page['coverage'] == 'bounded_retained_history_not_live_platform_verification', 'history wire states its source and live-verification limit')
                check(isinstance(page['rows'], list) and 1 <= len(page['rows']) <= 5, 'native history wire returns bounded real fixture observations')
                check(type(page['cutoff_unix']) is int and page['cutoff_unix'] <= int(time.time()) and isinstance(page['history_fence_id'], str), 'history wire preserves cutoff and exact fence types')
                check(type(page['has_more']) is bool and type(page['scanned_event_count']) is int and 0 <= page['scanned_event_count'] <= 64 and type(page['omitted_unknown_count']) is int and 0 <= page['omitted_unknown_count'] <= 64, 'history wire reports bounded scans and unknown omissions')
                seen_history = set()
                for row in page['rows']:
                    check(set(row) == row_keys and row['state'] == 'observed_subscribed', 'history wire has no CID, amounts, or live subscribed claim')
                    check(row['entry_basis'] in {'first_retained_join', 'after_observed_leave'}, 'history wire distinguishes retained first join and observed rejoin')
                    seen_history.add((row['external_user_id'], row['source_id']))
                check(len(seen_history) == len(page['rows']), 'history wire does not duplicate a person and channel within one page')
                if page['has_more']:
                    check(isinstance(page['next_cursor'], str) and page['next_cursor'].startswith('mh1.'), 'history wire continuation is purpose-specific')
                    continuation = encoded({**data, 'user_ids': [], 'history_member_page_after': page['next_cursor']})
                    status, continued = request(continuation, signed=signature(continuation))
                    check(status == 200 and set(continued) == set(result), 'signed history continuation keeps the same endpoint contract')
                    next_page = continued['history_member_page']
                    check(set(next_page) == page_keys and (next_page['cutoff_unix'], next_page['history_fence_id']) == (page['cutoff_unix'], page['history_fence_id']), 'history continuation preserves its initial snapshot')
                    check(all(set(row) == row_keys and row['state'] == 'observed_subscribed' and (row['external_user_id'], row['source_id']) not in seen_history for row in next_page['rows']), 'history continuation contains only distinct evidence rows without financial fields')
                else:
                    check(page['next_cursor'] is None, 'terminal history wire page has no continuation')
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
