#!/usr/bin/env python3
"""Bounded, offline materializer for two documented retained evidence formats.

Reads existing source inventory and selected public-source ZIP members only.
No network, model, production DB, queue, claim, full-archive hash or admission.
"""
from __future__ import annotations

import argparse
import datetime as dt
import hashlib
import ipaddress
import json
import math
import os
from pathlib import Path, PurePosixPath
import re
import sys
import urllib.parse
import zipfile

MAX_EXCERPT_BYTES = 4096
MAX_ENVELOPE_BYTES = 8192
MAX_MEMBER_BYTES = 65536
MAX_METADATA_BYTES = 1048576
MAX_EXAMPLES = 2
DENIED_FIELDS = {
    'claim_key', 'claimkey', 'api_key', 'apikey', 'access_token',
    'refresh_token', 'client_secret', 'password', 'private_key',
    'secret_key', 'authorization', 'authorization_header', 'credentials',
}
# Equivalent spelling must not bypass private-field rejection.
DENIED_FIELD_KEYS = {re.sub(r'[^a-z0-9]', '', key) for key in DENIED_FIELDS}
PRIVATE_TEXT_MARKERS = tuple(
    re.compile(r'[\s_-]*'.join(re.escape(c) for c in key), re.IGNORECASE)
    for key in DENIED_FIELD_KEYS
)
ADMISSION = 'UNASSESSED_FINAL19_QUARANTINE_RESERVE_JOIN_REQUIRED'
REVIEW_URLS = [
    'https://github.com/pyatkoff/max-search-bot/pull/1274#pullrequestreview-5733725160',
    'https://github.com/pyatkoff/max-search-bot/blob/083626c17dfc5f07d33bf8ccbfd2de1e65d858a3/services/HotelServerAiProvider.php',
    'https://github.com/pyatkoff/max-search-bot/blob/083626c17dfc5f07d33bf8ccbfd2de1e65d858a3/docs/HOTEL_SERVER_AI_PROVIDER.md',
]


class InputError(Exception):
    def __init__(self, code, location):
        super().__init__(code)
        self.code = code
        self.location = location


def fail(code, location):
    raise InputError(code, location)


def sha(data):
    return hashlib.sha256(data).hexdigest()


def compact(value):
    return json.dumps(value, ensure_ascii=False, sort_keys=True,
                      separators=(',', ':'), allow_nan=False).encode('utf-8')


def no_duplicate_keys(pairs):
    out = {}
    for key, value in pairs:
        if key in out:
            fail('DUPLICATE_JSON_KEY', 'selected JSON')
        out[key] = value
    return out


def is_private_field(key):
    return re.sub(r'[^a-z0-9]', '', str(key).lower()) in DENIED_FIELD_KEYS


def no_private_fields(value, location):
    if isinstance(value, dict):
        for key, item in value.items():
            if is_private_field(key):
                fail('PRIVATE_FIELD_NOT_ALLOWED', location)
            no_private_fields(item, location)
    elif isinstance(value, list):
        for item in value:
            no_private_fields(item, location)


def parse_json(raw, location, limit=MAX_METADATA_BYTES):
    if len(raw) > limit:
        fail('JSON_BYTE_LIMIT', location)
    try:
        obj = json.loads(raw.decode('utf-8'), object_pairs_hook=no_duplicate_keys)
    except (UnicodeError, json.JSONDecodeError, RecursionError):
        fail('INVALID_UTF8_JSON', location)
    no_private_fields(obj, location)
    return obj


def read_metadata(path, location):
    # Check the stream before allocation, including files that grow during read.
    with path.open('rb') as handle:
        raw = handle.read(MAX_METADATA_BYTES + 1)
    if len(raw) > MAX_METADATA_BYTES:
        fail('JSON_BYTE_LIMIT', location)
    return raw


def at(document, pointer, location):
    if not isinstance(pointer, str) or (pointer and not pointer.startswith('/')):
        fail('INVALID_JSON_POINTER', location)
    result = document
    for encoded in pointer.split('/')[1:]:
        if re.search(r'~(?![01])', encoded):
            fail('INVALID_JSON_POINTER', location)
        token = encoded.replace('~1', '/').replace('~0', '~')
        try:
            if isinstance(result, list):
                if not re.fullmatch(r'0|[1-9][0-9]*', token):
                    fail('INVALID_ARRAY_POINTER', location)
                result = result[int(token)]
            elif isinstance(result, dict):
                result = result[token]
            else:
                fail('JSON_POINTER_NOT_FOUND', location + '#' + pointer)
        except (KeyError, IndexError):
            fail('JSON_POINTER_NOT_FOUND', location + '#' + pointer)
    return result


def inside(root, relative, label):
    pathpart = Path(relative)
    if pathpart.is_absolute() or '..' in pathpart.parts:
        fail('PATH_OUTSIDE_RETAINED_ROOT', label)
    p = (root / pathpart).resolve()
    if not p.is_relative_to(root):
        fail('PATH_OUTSIDE_RETAINED_ROOT', label)
    return p


def source_url(value, location):
    # Match the adapter's byte bound and require an already encoded ASCII URL.
    # Do not percent-encode or otherwise change retained evidence identifiers.
    if (not isinstance(value, str) or not value.isascii() or len(value.encode('utf-8')) > 2048
            or not value.startswith('https://') or any(ord(x) < 33 or ord(x) == 127 for x in value)
            or any(x in value for x in '\\<>"`{}|')):
        fail('EXACT_PUBLIC_HTTPS_URL_REQUIRED', location)
    try:
        parts = urllib.parse.urlsplit(value)
        host = parts.hostname or ''
        port = parts.port
    except ValueError:
        fail('EXACT_PUBLIC_HTTPS_URL_REQUIRED', location)
    dns_host = host[:-1] if host.endswith('.') else host
    if (parts.scheme != 'https' or parts.username is not None or parts.password is not None
            or port not in (None, 443) or not re.fullmatch(r'[A-Za-z0-9.-]+', host)
            or len(dns_host) > 253
            or not all(re.fullmatch(r'[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?', label)
                       for label in dns_host.split('.'))
            or '.' not in dns_host or re.search(r'(?:^|\.)(?:localhost|local|internal|invalid)$', dns_host, re.I)):
        fail('EXACT_PUBLIC_HTTPS_URL_REQUIRED', location)
    try:
        ipaddress.ip_address(dns_host)
    except ValueError:
        pass
    else:
        fail('LITERAL_IP_URL_NOT_ALLOWED', location)
    if any(is_private_field(k) for k, _ in urllib.parse.parse_qsl(parts.query)):
        fail('CREDENTIAL_QUERY_NOT_ALLOWED', location)
    # Fragment-based tokens must never be copied into an assigned source URL.
    if any(marker.search(urllib.parse.unquote(parts.fragment)) for marker in PRIVATE_TEXT_MARKERS):
        fail('CREDENTIAL_FRAGMENT_NOT_ALLOWED', location)
    return value  # No normalization, redirect, DNS or network check.


def capture_time(value, location):
    if not isinstance(value, str) or not re.fullmatch(
            r'\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})', value):
        fail('ORIGINAL_CAPTURE_TIMESTAMP_REQUIRED', location)
    try:
        instant = dt.datetime.fromisoformat(value.replace('Z', '+00:00'))
        stamp = math.floor(instant.timestamp())
    except (ValueError, OverflowError):
        fail('ORIGINAL_CAPTURE_TIMESTAMP_REQUIRED', location)
    if instant.tzinfo is None or stamp <= 0:
        fail('ORIGINAL_CAPTURE_TIMESTAMP_REQUIRED', location)
    return stamp, {
        'original_value': value,
        'conversion': 'floor ISO8601 offset-aware timestamp to integer Unix UTC seconds; original precision retained here',
        'fractional_microseconds': instant.microsecond,
        'freshness_policy_evaluated': False,
        'not_derived_from_zip_mtime_filename_or_current_time': True,
    }


class ArchiveSource:
    def __init__(self, root, source_ref, metadata):
        if not re.fullmatch(r'country_(EG|TH|VN|TR)', source_ref):
            fail('COUNTRY_ARCHIVE_SOURCE_REQUIRED', source_ref)
        name = metadata.get('name', '')
        archive_name = re.fullmatch(r'anytour-hotels-(EG|TH|VN|TR)-research-\d{8}\.zip', name)
        if not archive_name:
            fail('COUNTRY_ARCHIVE_FILENAME_REQUIRED', source_ref)
        if source_ref != 'country_' + archive_name.group(1):
            fail('COUNTRY_ARCHIVE_IDENTITY_MISMATCH', source_ref)
        if not re.fullmatch(r'[a-f0-9]{64}', metadata.get('sha256', '')):
            fail('PRESERVED_ARCHIVE_PIN_REQUIRED', source_ref)
        self.ref = source_ref
        self.meta = metadata
        self.path = inside(root, metadata['archive_path'], source_ref)
        if not self.path.is_file() or self.path.stat().st_size != metadata.get('size_bytes'):
            fail('PRESERVED_ARCHIVE_SIZE_MISMATCH', source_ref)
        self.identity_checked = {}
        for attribute, field in [('user.library-file-id', 'library_file_id'),
                                 ('user.library-file-version', 'version')]:
            try:
                current = os.getxattr(self.path, attribute).decode('utf-8')
            except OSError:
                self.identity_checked[field] = 'xattr_unavailable'
                continue
            if current != str(metadata[field]):
                fail('PRESERVED_LIBRARY_IDENTITY_MISMATCH', source_ref)
            self.identity_checked[field] = 'xattr_matches_inventory'
        self.zip = zipfile.ZipFile(self.path, 'r')
        self.cache = {}

    def close(self):
        self.zip.close()

    def member(self, name):
        if not isinstance(name, str) or not name:
            fail('EXACT_SOURCE_MEMBER_REQUIRED', self.ref)
        path = PurePosixPath(name)
        if (path.is_absolute() or '..' in path.parts or '\\' in name
                or not any(x in path.parts for x in ('evidence', 'source-extracts'))
                or re.search(r'credential|claim[-_]?key|checkpoint|coordination|secret', name, re.I)):
            fail('NON_SOURCE_MEMBER_NOT_ALLOWED', self.ref)
        if name in self.cache:
            return self.cache[name]
        entries = [i for i in self.zip.infolist() if i.filename == name]
        if len(entries) != 1:
            fail('SOURCE_MEMBER_MISSING_OR_AMBIGUOUS', name)
        info = entries[0]
        if info.is_dir() or info.flag_bits & 1 or not 0 < info.file_size <= MAX_MEMBER_BYTES:
            fail('SOURCE_MEMBER_BYTE_LIMIT_OR_ENCRYPTION', name)
        try:
            raw = self.zip.read(info)  # ZIP member CRC checked by stdlib; not all-archive hashing.
        except (OSError, RuntimeError, zipfile.BadZipFile):
            fail('SOURCE_MEMBER_READ_FAILED', name)
        if len(raw) != info.file_size:
            fail('SOURCE_MEMBER_SIZE_MISMATCH', name)
        self.cache[name] = raw
        return raw

    def json_member(self, name):
        return parse_json(self.member(name), name, MAX_MEMBER_BYTES)

    def member_identity(self, name):
        raw = self.member(name)
        return {'archive_member': name, 'bytes': len(raw), 'sha256': sha(raw),
                'hash_kind': 'exact_selected_uncompressed_member_bytes'}

    def provenance(self):
        return {
            'source_ref': self.ref,
            'filename': self.meta['name'],
            'preserved_relative_path': self.meta['archive_path'],
            'library_file_id': self.meta['library_file_id'],
            'version': self.meta['version'],
            'sha256': self.meta['sha256'],
            'bytes': self.meta['size_bytes'],
            'hash_observation': 'reuse previously verified preservation inventory pin; entire archive not rehashed here',
            'metadata_origin': self.meta.get('metadata_provenance'),
            'local_identity_checks': self.identity_checked,
        }


def materialize(source, selection):
    label = selection.get('id')
    if not isinstance(label, str) or not re.fullmatch(r'[a-z][a-z0-9-]{0,79}', label):
        fail('SAFE_EXAMPLE_ID_REQUIRED', 'selection')
    proof_name = selection.get('proof_member')
    proof = source.json_member(proof_name)
    fmt = selection.get('format')
    pointer = None
    proof_bindings = {}
    if fmt == 'retrieval_text_with_proof':
        if proof.get('evidence_kind') != 'EXACT_RETRIEVAL_EXTRACT_TEXT_UTF8':
            fail('EXACT_LITERAL_SOURCE_KIND_REQUIRED', proof_name + '#/evidence_kind')
        evidence_name = proof.get('evidence_ref')
        raw = source.member(evidence_name)
        if proof.get('evidence_bytes') != len(raw) or proof.get('evidence_sha256') != sha(raw):
            fail('ORIGINAL_LITERAL_DIGEST_MISMATCH', proof_name)
        start, end = selection.get('byte_range', [0, len(raw)])
        if (type(start) is not int or type(end) is not int
                or not 0 <= start < end <= len(raw)):
            fail('EXACT_UTF8_BYTE_RANGE_REQUIRED', label)
        excerpt_raw = raw[start:end]
        timestamp_pointer = '/read_at_utc'
        proof_bindings = {
            'evidence_kind_pointer': '/evidence_kind',
            'evidence_reference_pointer': '/evidence_ref',
            'original_member_hash_pointer': '/evidence_sha256',
            'original_member_bytes_pointer': '/evidence_bytes',
            'source_format_statement': proof['evidence_kind'],
            'original_retrieval_line_ranges': proof.get('line_ranges'),
            'original_retrieval_line_ranges_pointer': '/line_ranges',
            'selected_retrieval_ranges_are_not_a_claim_of_contiguous_full_page': True,
            'full_http_response_embedded': proof.get('full_http_response_embedded'),
            'source_response_sha256': proof.get('source_response_sha256'),
        }
    elif fmt == 'literal_segments_with_hashed_companion':
        text_kind = proof.get('evidence_type')
        if not isinstance(text_kind, str) or not text_kind.startswith('Short exact literal from actual retrieved lines'):
            fail('EXACT_LITERAL_SOURCE_KIND_REQUIRED', proof_name + '#/evidence_type')
        segments = proof.get('literal_segments')
        if not isinstance(segments, list) or not segments or not all(isinstance(s, str) and s for s in segments):
            fail('ORIGINAL_LITERAL_SEGMENTS_REQUIRED', proof_name + '#/literal_segments')
        if proof.get('literal_segment_joiner') != 'newline; each segment exact, not claimed contiguous full response':
            fail('ORIGINAL_LITERAL_JOINER_UNCONFIRMED', proof_name + '#/literal_segment_joiner')
        index = selection.get('segment_index')
        if type(index) is not int or not 0 <= index < len(segments):
            fail('EXACT_LITERAL_SEGMENT_INDEX_REQUIRED', label)
        evidence_name = proof.get('short_literal_ref')
        raw = source.member(evidence_name)
        expected = '\n'.join(segments).encode('utf-8')
        if raw != expected or proof.get('short_literal_bytes_sha256') != sha(raw):
            fail('ORIGINAL_LITERAL_COMPANION_MISMATCH', proof_name)
        start = sum(len(s.encode('utf-8')) + 1 for s in segments[:index])
        excerpt_raw = segments[index].encode('utf-8')
        end = start + len(excerpt_raw)
        if raw[start:end] != excerpt_raw:
            fail('LITERAL_POINTER_BYTE_RANGE_MISMATCH', label)
        pointer = '/literal_segments/' + str(index)
        timestamp_pointer = '/read_completed_at_utc'
        proof_bindings = {
            'literal_json_pointer': pointer,
            'evidence_reference_pointer': '/short_literal_ref',
            'original_member_hash_pointer': '/short_literal_bytes_sha256',
            'evidence_kind_pointer': '/evidence_type',
            'original_joiner_pointer': '/literal_segment_joiner',
            'source_format_statement': text_kind,
            'full_http_response_embedded': proof.get('full_http_response_saved'),
            'source_response_sha256': proof.get('source_response_sha256'),
            'only_one_original_segment_selected': True,
            'noncontiguous_segments_not_joined_into_excerpt': True,
        }
    else:
        fail('UNSUPPORTED_RETAINED_LITERAL_FORMAT', label)

    if not 0 < len(excerpt_raw) <= MAX_EXCERPT_BYTES:
        fail('EXCERPT_BYTE_LIMIT', label)
    try:
        excerpt = excerpt_raw.decode('utf-8')
    except UnicodeDecodeError:
        fail('UTF8_BOUNDARY_REQUIRED', label)
    if any(marker.search(excerpt) for marker in PRIVATE_TEXT_MARKERS):
        fail('PRIVATE_FIELD_MARKER_IN_EXCERPT', label)
    url = source_url(at(proof, '/url', proof_name), proof_name + '#/url')
    timestamp_value = proof.get(timestamp_pointer[1:])
    captured_at, capture = capture_time(timestamp_value, proof_name + '#' + timestamp_pointer)
    source_id = proof.get('source_id')
    if not isinstance(source_id, str) or not 0 < len(source_id) <= 256:
        fail('ORIGINAL_SOURCE_ID_REQUIRED', proof_name + '#/source_id')
    envelope = {'schema': 'hotel-assigned-source-slice-v1', 'sources': [{
        'url': url, 'excerpt': excerpt, 'sha256': sha(excerpt_raw),
        'captured_at': captured_at, 'kind': 'retained_excerpt',
    }]}
    encoded = compact(envelope) + b'\n'
    if len(encoded) > MAX_ENVELOPE_BYTES:
        fail('ENVELOPE_BYTE_LIMIT', label)
    sidecar = {
        'schema': 'anytour.retained-literal-materialization-provenance.v1',
        'example_id': label,
        'purpose': 'offline converter example; existing historical evidence may already have accepted coverage',
        'scope': None,
        'scope_admission': ADMISSION,
        'ready_for_launch': False,
        'new_research': False,
        'new_acceptance_credit': 0,
        'source_file': source.provenance(),
        'original_source_id': source_id,
        'source_id_provenance': {'archive_member': proof_name, 'json_pointer': '/source_id'},
        'url_provenance': {'archive_member': proof_name, 'json_pointer': '/url', 'original_value': url},
        'capture_provenance': {'archive_member': proof_name, 'json_pointer': timestamp_pointer, **capture},
        'selected_literal': {
            'member': source.member_identity(evidence_name),
            'byte_range': {'start_inclusive': start, 'end_exclusive': end},
            'bytes': len(excerpt_raw), 'sha256': sha(excerpt_raw),
            'encoding': 'UTF-8; exact original bytes; no trim, translation, normalization or paraphrase',
            'json_pointer_in_proof': pointer,
        },
        'proof_member': source.member_identity(proof_name),
        'original_binding_checks': proof_bindings,
        'capture_time_is_not_publication_date_or_future_validity': True,
        'web_page_authenticity_reverified': False,
        'envelope_bytes': len(encoded),
        'envelope_sha256': sha(encoded),
        'envelope_hash_includes_terminal_newline': True,
        'adapter_head_reviewed': '083626c17dfc5f07d33bf8ccbfd2de1e65d858a3',
        'review_references': REVIEW_URLS,
    }
    if isinstance(proof.get('hotel_key'), str):
        sidecar['original_hotel_key'] = proof['hotel_key']
        sidecar['hotel_key_provenance'] = {'archive_member': proof_name, 'json_pointer': '/hotel_key'}
    return label, envelope, sidecar, excerpt_raw


def write_private(path, value):
    data = compact(value) + b'\n'
    # Private from creation, even with a permissive process umask. Never overwrite.
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(fd, 'wb') as handle:
        handle.write(data)
    return {'path': path.name, 'bytes': len(data), 'sha256': sha(data)}


def run(args):
    root = args.root.resolve()
    inventory_path = args.inventory.resolve()
    if not inventory_path.is_relative_to(root):
        fail('INVENTORY_OUTSIDE_RETAINED_ROOT', str(inventory_path))
    inv_raw = read_metadata(inventory_path, 'source inventory')
    inv = parse_json(inv_raw, 'source inventory')
    if inv.get('schema') != 'anytour.retained-source-inventory.v1' or not isinstance(inv.get('sources'), dict):
        fail('PRESERVED_SOURCE_INVENTORY_REQUIRED', 'source inventory')
    selections_raw = read_metadata(args.selections, 'selection specification')
    spec = parse_json(selections_raw, 'selection specification')
    selections = spec.get('examples')
    if (spec.get('schema') != 'anytour.retained-literal-selections.v1'
            or not isinstance(selections, list) or not 1 <= len(selections) <= MAX_EXAMPLES):
        fail('ONE_OR_TWO_EXPLICIT_SELECTIONS_REQUIRED', 'selection specification')
    output = args.output.resolve()
    if not output.is_relative_to(root) or output.is_relative_to(root / 'preserved'):
        fail('OUTPUT_MUST_BE_SEPARATE_FROM_PRESERVED_SOURCES', str(output))
    if output.exists() and any(output.iterdir()):
        fail('OUTPUT_NOT_EMPTY_NO_OVERWRITE', str(output))

    archives = {}
    results = []
    try:
        for selection in selections:
            ref = selection.get('source_ref')
            if ref not in inv['sources']:
                fail('SOURCE_REF_NOT_IN_PRESERVATION_INVENTORY', str(ref))
            if ref not in archives:
                archives[ref] = ArchiveSource(root, ref, inv['sources'][ref])
            results.append(materialize(archives[ref], selection))
        if len({r[0] for r in results}) != len(results):
            fail('DUPLICATE_EXAMPLE_ID', 'selection specification')

        # No output is written until every selected real source has passed.
        output.mkdir(parents=True, exist_ok=True, mode=0o700)
        output.chmod(0o700)
        written = []
        for label, envelope, sidecar, _ in results:
            written.append(write_private(output / (label + '.envelope.json'), envelope))
            written.append(write_private(output / (label + '.provenance.json'), sidecar))

        # One meaningful write/readback: exact literal bytes, original bindings and schema bounds.
        checked = []
        for label, envelope, sidecar, excerpt_raw in results:
            actual = parse_json((output / (label + '.envelope.json')).read_bytes(), label)
            actual_sidecar = parse_json((output / (label + '.provenance.json')).read_bytes(), label)
            if actual != envelope or actual_sidecar != sidecar:
                fail('DERIVED_FILE_READBACK_MISMATCH', label)
            record = actual['sources'][0]
            if (set(actual) != {'schema', 'sources'}
                    or set(record) != {'url', 'excerpt', 'sha256', 'captured_at', 'kind'}
                    or record['excerpt'].encode('utf-8') != excerpt_raw
                    or record['sha256'] != sha(excerpt_raw)
                    or type(record['captured_at']) is not int):
                fail('DERIVED_LITERAL_READBACK_MISMATCH', label)
            checked.append({
                'example_id': label, 'excerpt_bytes': len(excerpt_raw),
                'excerpt_sha256': sha(excerpt_raw),
                'captured_at': record['captured_at'],
                'original_capture_timestamp': sidecar['capture_provenance']['original_value'],
                'envelope_bytes': (output / (label + '.envelope.json')).stat().st_size,
                'proof_hash_and_exact_member_binding_checked': True,
                'literal_readback_equal_to_original_selected_bytes': True,
                'scope_admission': ADMISSION,
            })
        report = {
            'schema': 'anytour.retained-literal-readback.v1',
            'result': 'PASS_OFFLINE_LITERAL_EXTRACTION_ONLY',
            'created_at_utc': dt.datetime.now(dt.timezone.utc).isoformat(),
            'examples': checked, 'scope_admission': ADMISSION,
            'freshness_policy_evaluated': False, 'new_research': False,
            'new_acceptance_credit': 0, 'ready_for_launch': False,
            'network_calls': 0, 'model_calls': 0, 'production_calls': 0,
            'full_archive_or_backup_hashes_computed': 0,
            'original_files_written': 0,
            'selected_source_members_read': sum(len(a.cache) for a in archives.values()),
            'selected_source_member_bytes_read': sum(len(v) for a in archives.values() for v in a.cache.values()),
            'candidate_projection_regenerated': False,
        }
        written.append(write_private(output / 'readback.json', report))
        manifest = {
            'schema': 'anytour.retained-literal-output-manifest.v1',
            'status': 'OFFLINE_EXAMPLES_NO_SCOPE_ADMISSION',
            'source_inventory': {'relative_path': str(inventory_path.relative_to(root)), 'sha256': sha(inv_raw)},
            'selection_sha256': sha(selections_raw),
            'script_sha256': sha(Path(__file__).read_bytes()),
            'files': list(written),
            'max_excerpt_bytes': MAX_EXCERPT_BYTES,
            'max_envelope_bytes': MAX_ENVELOPE_BYTES,
            'source_archive_hash_mode': 'reuse preservation inventory pins; selected members only hashed here',
            'scope_admission': ADMISSION,
            'review_references': REVIEW_URLS,
        }
        write_private(output / 'manifest.json', manifest)
        if parse_json((output / 'readback.json').read_bytes(), 'readback') != report:
            fail('REPORT_READBACK_MISMATCH', 'readback')
        if parse_json((output / 'manifest.json').read_bytes(), 'manifest') != manifest:
            fail('MANIFEST_READBACK_MISMATCH', 'manifest')
        return {'output': str(output), 'result': report['result'],
                'examples': checked, 'files': len(written) + 1,
                'bytes': sum(p.stat().st_size for p in output.iterdir() if p.is_file()),
                'scope_admission': ADMISSION}
    finally:
        for archive in archives.values():
            archive.close()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', required=True, type=Path)
    parser.add_argument('--inventory', required=True, type=Path)
    parser.add_argument('--selections', required=True, type=Path)
    parser.add_argument('--output', required=True, type=Path)
    try:
        result = run(parser.parse_args())
    except InputError as error:
        print(json.dumps({'status': 'INPUT_ERROR', 'error': error.code,
                          'location': error.location, 'ready_for_launch': False}))
        return 2
    except (OSError, ValueError, KeyError, TypeError, zipfile.BadZipFile):
        # Never print raw private input bytes or exception payload.
        print(json.dumps({'status': 'INPUT_ERROR', 'error': 'LOCAL_SOURCE_OR_SPECIFICATION_ERROR',
                          'ready_for_launch': False}))
        return 2
    print(json.dumps(result, ensure_ascii=False))
    return 0


if __name__ == '__main__':
    raise SystemExit(main())

