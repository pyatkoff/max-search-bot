#!/usr/bin/env python3
"""Real PHP HTTP endpoint against isolated fixture data; no external requests."""
import base64
import importlib.util
import json
import hashlib
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
    request_audit = private_root / 'request-audit.jsonl'
    router = private_root / 'router.php'
    router.write_text("<?php file_put_contents(" + json.dumps(str(request_audit)) + ", json_encode(['method'=>$_SERVER['REQUEST_METHOD']??'', 'evidence'=>$_SERVER['HTTP_X_MAX_ATTRIBUTION_EVIDENCE']??'']).PHP_EOL, FILE_APPEND|LOCK_EX); return false;\n")
    with (private_root / 'server.log').open('wb') as log:
        server = subprocess.Popen(command + ['-S', f'127.0.0.1:{port}', '-t', str(site), str(router)], stdout=log, stderr=log)
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
            def request(body=None, method='POST', content_type='application/json', signed=None, evidence=None):
                headers = {'Content-Type': content_type}
                if signed is not None:
                    headers['X-Max-Attribution-Signature'] = signed
                if evidence is not None:
                    headers['X-Max-Attribution-Evidence'] = evidence
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
                ordinary_users = result['users']
                # The production client keeps its pinned HTTPS transport. Only this private
                # synthetic copy uses the already running local HTTP fixture and its fixture key.
                client_fixture = private_root / 'client'
                client_fixture.mkdir()
                client_source = (ROOT / 'services/MaxTrackerLookupClient.php').read_text()
                check(client_source.count('CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS') == 2, 'both pinned client protocols are substituted only in the isolated fixture')
                for option in ('CURLOPT_SSL_VERIFYPEER=>true', 'CURLOPT_SSL_VERIFYHOST=>2', 'CURLOPT_FOLLOWLOCATION=>false'):
                    check(client_source.count(option) == 2, 'both production transports retain their TLS and redirect guards: ' + option)
                check(client_source.count('MaxTrackerLookupProtocol::signature(') == 2 and client_source.count("'X-Max-Attribution-Signature: '.$signature") == 2, 'both production transports retain signed request authentication')
                (client_fixture / 'MaxTrackerLookupClient.php').write_text(client_source.replace('CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS', 'CURLOPT_PROTOCOLS=>CURLPROTO_HTTP', 2))
                (client_fixture / 'MaxTrackerLookupProtocol.php').write_text((ROOT / 'services/MaxTrackerLookupProtocol.php').read_text())
                (client_fixture / 'MaxTrackerLookupConfig.php').write_text("<?php final class MaxTrackerLookupConfig { public static function endpoint():string{return " + json.dumps(url) + ";} public static function privateKey():string{return file_get_contents(" + json.dumps(str(private_key)) + ");}}\n")
                intent_file = private_root / 'simulated-consumed-intent.json'
                runner = private_root / 'client-callback.php'
                runner.write_text("<?php declare(strict_types=1); require " + json.dumps(str(client_fixture / 'MaxTrackerLookupClient.php')) + "; "
                    + "$users=['123'];for($i=1;$i<=24;$i++)$users[]=(string)(990000+$i);$calls=0;"
                    + "$result=(new MaxTrackerLookupClient())->lookupWithCounterScopeEvidence('98615635',$users,'123',"
                    + "static function(string $hash)use(&$calls):bool{$calls++;file_put_contents(" + json.dumps(str(intent_file))
                    + ",json_encode(['state'=>'consumed_unknown','request_sha256'=>$hash],JSON_THROW_ON_ERROR));throw new RuntimeException('synthetic journal acknowledgement lost');});"
                    + "echo json_encode(['users'=>count($result['identities']),'first_client'=>$result['identities']['123']['client_id'],'calls'=>$calls,'evidence'=>$result['counter_scope_evidence']],JSON_THROW_ON_ERROR);")
                audit_before = request_audit.read_text().splitlines()
                callback_run = subprocess.run(command + [str(runner)], capture_output=True, text=True, timeout=20)
                check(callback_run.returncode == 0, 'optional callback failure preserves the actual ordinary client request')
                callback_result = json.loads(callback_run.stdout)
                audit_after = request_audit.read_text().splitlines()
                check(callback_result['users'] == 25 and callback_result['first_client'] == '777' and callback_result['calls'] == 1, 'throwing optional journal callback does not lose any of the normal 25 lookup results')
                check(len(audit_after) == len(audit_before) + 1 and json.loads(audit_after[-1]) == {'method': 'POST', 'evidence': ''}, 'callback failure makes exactly one ordinary POST with no evidence opt-in header')
                consumed_intent = json.loads(intent_file.read_text())
                check(consumed_intent['state'] == 'consumed_unknown' and consumed_intent['request_sha256'] == callback_result['evidence']['request_sha256'] and callback_result['evidence']['reason'] == 'intent_not_persisted', 'possibly persisted intent remains consumed unknown and is never retried')
                scoped = encoded({**data, 'counter_scope_evidence': {'v': 1, 'user_id': '123'}})
                status, plain_extended = request(scoped, signed=signature(scoped))
                check(status == 200 and plain_extended['users'] == ordinary_users and 'counter_scope_evidence' not in plain_extended, 'signed option without opt-in header is still one ordinary lookup')
                status, observed = request(scoped, signed=signature(scoped), evidence='counter-scope-v1')
                check(status == 200 and observed['users'] == ordinary_users, 'optional evidence failure never changes ordinary identities')
                extra = observed['counter_scope_evidence']
                check(extra['v'] == 1 and extra['counter_id'] == '98615635' and extra['request_sha256'] == hashlib.sha256(scoped).hexdigest(), 'same signed request hash binds the optional counter evidence')
                check('user_id' not in extra and 'client_id' not in extra and 'record_id' not in extra, 'counter evidence has no raw native or tracker identifiers')
                if extra['status'] == 'observed':
                    check(extra['complete_within_bound'] is True and 0 <= extra['scoped_rows'] <= 64 and extra['empty_client_id_rows'] + extra['noncanonical_client_id_rows'] + extra['canonical_client_id_rows'] == extra['scoped_rows'], 'native wire aggregate is complete within the fixed point bound')
                else:
                    check(extra['reason'] in {'point_index_unavailable', 'row_limit', 'storage_unavailable'} and extra['scoped_rows'] is None and extra['complete_within_bound'] is False, 'unavailable point evidence is unknown rather than zero')
                repository_path = endpoint / 'services/MaxTrackerLookupRepository.php'
                repository_source = repository_path.read_text()
                check(repository_source.count('public function counterScopeEvidence(') == 1, 'one optional receiver method')
                try:
                    repository_path.write_text(repository_source.replace('public function counterScopeEvidence(', 'public function unavailableCounterScopeEvidence(', 1))
                    failed_status, failed_optional = request(scoped, signed=signature(scoped), evidence='counter-scope-v1')
                    check(failed_status == 200 and failed_optional['users'] == ordinary_users and failed_optional['counter_scope_evidence']['reason'] == 'storage_unavailable', 'unexpected optional receiver failure preserves the ordinary identity response')
                finally:
                    repository_path.write_text(repository_source)
                invalid_subset = encoded({**data, 'counter_scope_evidence': {'v': 1, 'user_id': '789'}})
                status, invalid_extra = request(invalid_subset, signed=signature(invalid_subset), evidence='counter-scope-v1')
                check(status == 200 and invalid_extra['users'] == ordinary_users and invalid_extra['counter_scope_evidence']['reason'] == 'invalid_request', 'signed out-of-batch option cannot expand scope or poison ordinary lookup')
                tampered_subset = encoded({**data, 'counter_scope_evidence': {'v': 1, 'user_id': '404'}})
                check(request(tampered_subset, signed=signature(scoped), evidence='counter-scope-v1')[0] == 401, 'subset selection is covered by the existing body signature')
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
                history_extra = encoded({**data, 'user_ids': [], 'history_member_page_after': '0', 'counter_scope_evidence': {'v': 1, 'user_id': '123'}})
                extra_status, extra_history = request(history_extra, signed=signature(history_extra), evidence='counter-scope-v1')
                check(extra_status == 200 and set(extra_history) == set(result) and 'counter_scope_evidence' not in extra_history, 'optional header never changes strict history response keys')
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
