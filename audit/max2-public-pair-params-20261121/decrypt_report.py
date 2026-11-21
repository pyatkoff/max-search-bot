"""Local-only authenticated decrypt of this exact scope. No network or key discovery."""
import argparse
import base64
import hashlib
import io
import json
import math
import os
import re
import runpy
import stat
import sys
import zipfile
from pathlib import Path

from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import padding, rsa
from cryptography.hazmat.primitives.ciphers.aead import AESGCM

HERE = Path(__file__).resolve().parent
BRANCH = 'audit/max2-public-pair-params-20261121'
ARTIFACT_NAME = 'max2-public-pair-98615635-20261121'
MEMBER = 'anytour-max2-public-pair.enc.json'
AAD_PREFIX = 'anytour-max2-public-pair-98615635-20261121-'
MAX_ARCHIVE, MAX_ENVELOPE, MAX_REPORT = 6_000_000, 5_500_000, 4_000_000
EXPECTED_FIELDS = {'schema', 'repository', 'branch', 'workflow_sha', 'run_id', 'job_id',
                   'run_attempt', 'artifact_id', 'artifact_name', 'archive_sha256',
                   'reader_sha256', 'runner_sha256', 'report_public_key_sha256'}
PRIVATE_FIELDS = {'client_id', 'clientid', 'user_id', 'userid', 'user_decimal', 'cid_decimal',
                  'yclid', 'initdata', 'init_data', 'raw_init_data', 'raw_url', 'start_url',
                  'starturl', 'startapp', 'raw_response', 'customer_id', 'conversation_id',
                  'email', 'phone', 'authorization', 'token', 'oauth_token', 'access_token'}

class SafeStop(Exception):
    pass

def digest(raw):
    return hashlib.sha256(raw).hexdigest()

def strict_json(raw):
    def pairs(items):
        result = {}
        for key, value in items:
            if key in result:
                raise SafeStop('DUPLICATE_JSON_KEY')
            result[key] = value
        return result
    def invalid(_):
        raise SafeStop('INVALID_JSON_NUMBER')
    try:
        return json.loads(raw, object_pairs_hook=pairs, parse_constant=invalid)
    except SafeStop:
        raise
    except Exception:
        raise SafeStop('INVALID_JSON') from None

def read_regular(path, bound, private=False):
    try:
        fd = os.open(os.fspath(path), os.O_RDONLY | os.O_NOFOLLOW)
        with os.fdopen(fd, 'rb') as handle:
            info = os.fstat(handle.fileno())
            if not stat.S_ISREG(info.st_mode) or info.st_size > bound:
                raise SafeStop('LOCAL_FILE_BOUND_OR_TYPE')
            if private and (info.st_uid != os.getuid() or info.st_mode & 0o077):
                raise SafeStop('PRIVATE_KEY_PERMISSIONS_REQUIRED')
            raw = handle.read(bound + 1)
            if len(raw) > bound:
                raise SafeStop('LOCAL_FILE_BOUND_OR_TYPE')
            return raw
    except SafeStop:
        raise
    except Exception:
        raise SafeStop('LOCAL_FILE_UNAVAILABLE_OR_SYMLINK') from None

def validate_expected(expected):
    if not isinstance(expected, dict) or set(expected) != EXPECTED_FIELDS:
        raise SafeStop('EXPECTED_PROVENANCE_SCHEMA')
    if (type(expected['schema']) is not int or expected['schema'] != 1
            or expected['repository'] != 'pyatkoff/max-search-bot'
            or expected['branch'] != BRANCH or expected['artifact_name'] != ARTIFACT_NAME
            or type(expected['run_attempt']) is not int or expected['run_attempt'] != 1):
        raise SafeStop('EXPECTED_SCOPE_MISMATCH')
    for field in ('run_id', 'job_id', 'artifact_id'):
        if not isinstance(expected[field], str) or not re.fullmatch(r'[1-9][0-9]{0,19}', expected[field]):
            raise SafeStop('EXPECTED_ID_INVALID')
    if not isinstance(expected['workflow_sha'], str) or not re.fullmatch(r'[0-9a-f]{40}', expected['workflow_sha']):
        raise SafeStop('EXPECTED_SOURCE_INVALID')
    for field in ('archive_sha256', 'reader_sha256', 'runner_sha256', 'report_public_key_sha256'):
        if not isinstance(expected[field], str) or not re.fullmatch(r'[0-9a-f]{64}', expected[field]):
            raise SafeStop('EXPECTED_DIGEST_INVALID')
    for name in ('reader', 'runner'):
        if digest(read_regular(HERE / (name + '.py'), 100_000)) != expected[name + '_sha256']:
            raise SafeStop('LOCAL_REVIEWED_SOURCE_CHANGED')

def decode_b64(value, maximum):
    if not isinstance(value, str) or len(value) > 4 * ((maximum + 2) // 3):
        raise SafeStop('ENVELOPE_FIELD_BOUND')
    try:
        raw = base64.b64decode(value, validate=True)
        if len(raw) > maximum:
            raise ValueError()
        return raw
    except Exception:
        raise SafeStop('ENVELOPE_ENCODING_INVALID') from None

def unpack_envelope(archive, expected):
    raw = read_regular(archive, MAX_ARCHIVE)
    if digest(raw) != expected['archive_sha256']:
        raise SafeStop('ARCHIVE_DIGEST_MISMATCH')
    try:
        with zipfile.ZipFile(io.BytesIO(raw)) as container:
            items = container.infolist()
            if len(items) != 1:
                raise SafeStop('ARTIFACT_MEMBER_COUNT')
            item = items[0]
            mode = item.external_attr >> 16
            if (item.filename != MEMBER or item.is_dir() or stat.S_ISLNK(mode)
                    or (stat.S_IFMT(mode) and not stat.S_ISREG(mode))
                    or item.flag_bits & 1 or item.file_size > MAX_ENVELOPE
                    or item.compress_size > MAX_ARCHIVE):
                raise SafeStop('ARTIFACT_MEMBER_INVALID')
            with container.open(item) as stream:
                body = stream.read(MAX_ENVELOPE + 1)
            if len(body) > MAX_ENVELOPE or len(body) != item.file_size:
                raise SafeStop('ENVELOPE_SIZE_BOUND')
    except SafeStop:
        raise
    except Exception:
        raise SafeStop('ARTIFACT_ZIP_INVALID') from None
    envelope = strict_json(body)
    if (not isinstance(envelope, dict)
            or set(envelope) != {'schema', 'workflow_sha', 'aad', 'nonce', 'wrapped_key', 'ciphertext'}
            or type(envelope['schema']) is not int or envelope['schema'] != 1
            or envelope['workflow_sha'] != expected['workflow_sha']):
        raise SafeStop('ENVELOPE_SOURCE_OR_SCHEMA')
    aad = decode_b64(envelope['aad'], 256)
    if aad != (AAD_PREFIX + expected['workflow_sha']).encode():
        raise SafeStop('ENVELOPE_AAD_MISMATCH')
    nonce = decode_b64(envelope['nonce'], 12)
    wrapped = decode_b64(envelope['wrapped_key'], 384)
    ciphertext = decode_b64(envelope['ciphertext'], MAX_REPORT + 16)
    if len(nonce) != 12 or len(wrapped) != 384 or not 16 <= len(ciphertext) <= MAX_REPORT + 16:
        raise SafeStop('ENVELOPE_CRYPTO_LENGTH')
    return aad, nonce, wrapped, ciphertext

def validate_report(report):
    nodes = 0
    def scan(value, depth=0):
        nonlocal nodes
        nodes += 1
        if depth > 32 or nodes > 50_000:
            raise SafeStop('REPORT_STRUCTURE_BOUND')
        if isinstance(value, dict):
            for key, child in value.items():
                if not isinstance(key, str) or key.lower() in PRIVATE_FIELDS:
                    raise SafeStop('PLAINTEXT_PRIVATE_FIELD')
                scan(child, depth + 1)
        elif isinstance(value, list):
            for child in value:
                scan(child, depth + 1)
        elif isinstance(value, float) and not math.isfinite(value):
            raise SafeStop('NONFINITE_REPORT_VALUE')
    scan(report)
    if not isinstance(report, dict):
        raise SafeStop('REPORT_SCHEMA')
    if report.get('status') == 'TRANSPORT_OR_REPORT_FAILED':
        if (set(report) != {'status', 'error_code'} or not isinstance(report['error_code'], str)
                or not re.fullmatch(r'[A-Z0-9_]{1,100}', report['error_code'])):
            raise SafeStop('TRANSPORT_FAILURE_SCHEMA')
        return
    if (report.get('status') not in ('COLLECTED', 'PARTIAL', 'READ_FAILED')
            or type(report.get('counter_id')) is not int or report['counter_id'] != 98615635
            or report.get('namespace') != 'max2_public_pair_v1'
            or report.get('date1') != '2026-11-21' or report.get('date2') != '2026-11-21'
            or report.get('timezone') != '+03:00'):
        raise SafeStop('REPORT_SCOPE_MISMATCH')
    if any(report.get(field) is not False for field in
           ('server_writes', 'advertising_writes', 'database_opened', 'token_exported')):
        raise SafeStop('REPORT_SAFETY_FLAGS_INVALID')
    requests = report.get('requests', [])
    if not isinstance(requests, list) or len(requests) > 6:
        raise SafeStop('REPORT_REQUEST_BOUND')
    model = runpy.run_path(str(HERE / 'reader.py'), run_name='local_validation_fixture')
    scopes = report.get('scopes', {})
    if not isinstance(scopes, dict) or not set(scopes) <= set(model['PLAN']):
        raise SafeStop('REPORT_SCOPE_NAMES')
    if report['status'] == 'COLLECTED' and set(scopes) != set(model['PLAN']):
        raise SafeStop('COLLECTED_SCOPES_INCOMPLETE')
    for name, scope in scopes.items():
        if not isinstance(scope, dict) or scope.get('request') != model['PLAN'][name]:
            raise SafeStop('REPORT_QUERY_CHANGED')
        values = scope.get('provider_totals')
        if not isinstance(values, dict) or set(values) != set(model['METRICS']):
            raise SafeStop('REPORT_METRICS_CHANGED')
        try:
            values = {key: model['finite_count'](value) for key, value in values.items()}
            if scope.get('counts') != model['named_counts'](values):
                raise SafeStop('REPORT_COUNTS_CHANGED')
        except SafeStop:
            raise
        except Exception:
            raise SafeStop('REPORT_COUNTS_CHANGED') from None
    groups = report.get('groups', {})
    if not isinstance(groups, dict) or not set(groups) <= {'pair_values'}:
        raise SafeStop('REPORT_GROUPS_CHANGED')
    if 'pair_values' in groups:
        rows = groups['pair_values'].get('rows')
        if not isinstance(rows, list) or len(rows) > 100:
            raise SafeStop('REPORT_GROUP_BOUND')
        seen = set()
        for row in rows:
            if not isinstance(row, dict) or set(row) != {'key', 'post_id', 'offer_id', 'counts'}:
                raise SafeStop('REPORT_GROUP_ROW_SCHEMA')
            pair = model['public_pair'](row['key'])
            if pair is None or any(row[key] != value for key, value in pair.items()) or row['key'] in seen:
                raise SafeStop('REPORT_PUBLIC_PAIR_INVALID')
            seen.add(row['key'])

def decrypt_archive(archive, private_key, expected):
    """Expected metadata is supplied by root AFTER authenticated GitHub observation."""
    validate_expected(expected)
    aad, nonce, wrapped, ciphertext = unpack_envelope(archive, expected)
    raw_key = read_regular(private_key, 16_384, private=True)
    try:
        key = serialization.load_pem_private_key(raw_key, password=None)
        if not isinstance(key, rsa.RSAPrivateKey) or key.key_size != 3072:
            raise ValueError()
        public = key.public_key().public_bytes(
            serialization.Encoding.PEM, serialization.PublicFormat.SubjectPublicKeyInfo)
        if digest(public) != expected['report_public_key_sha256']:
            raise SafeStop('PRIVATE_PUBLIC_KEY_BINDING_MISMATCH')
        symmetric = key.decrypt(wrapped, padding.OAEP(
            mgf=padding.MGF1(hashes.SHA256()), algorithm=hashes.SHA256(), label=None))
        if len(symmetric) != 32:
            raise ValueError()
        plaintext = AESGCM(symmetric).decrypt(nonce, ciphertext, aad)
    except SafeStop:
        raise
    except Exception:
        raise SafeStop('AUTHENTICATED_DECRYPT_FAILED') from None
    if len(plaintext) > MAX_REPORT:
        raise SafeStop('PLAINTEXT_SIZE_BOUND')
    report = strict_json(plaintext)
    validate_report(report)
    provenance = {**expected, 'plaintext_sha256': digest(plaintext),
                  'scheme': 'AES-256-GCM/RSA-OAEP-SHA256',
                  'metadata_basis': 'root_supplied_preverified_expected_metadata',
                  'network_performed_by_helper': False}
    return report, provenance

def write_exclusive(path, value):
    raw = json.dumps(value, ensure_ascii=False, allow_nan=False, indent=2).encode() + b'\n'
    fd = os.open(os.fspath(path), os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'wb') as handle:
        handle.write(raw)

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--archive', type=Path, required=True)
    parser.add_argument('--private-key', type=Path, required=True)
    parser.add_argument('--expected', type=Path, required=True)
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--provenance-output', type=Path, required=True)
    args = parser.parse_args()
    try:
        if args.output.exists() or args.output.is_symlink() or args.provenance_output.exists() or args.provenance_output.is_symlink():
            raise SafeStop('OUTPUT_ALREADY_EXISTS')
        report, provenance = decrypt_archive(args.archive, args.private_key,
                                              strict_json(read_regular(args.expected, 32_768)))
        write_exclusive(args.output, report)
        write_exclusive(args.provenance_output, provenance)
    except SafeStop as error:
        print('DECRYPT_FAILED=' + str(error), file=sys.stderr)
        return 1
    except Exception:
        print('DECRYPT_FAILED=LOCAL_DECRYPT_FAILED', file=sys.stderr)
        return 1
    print('AUTHENTICATED_REPORT_SAVED')
    return 0

if __name__ == '__main__':
    raise SystemExit(main())

