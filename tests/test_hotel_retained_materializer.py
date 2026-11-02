#!/usr/bin/env python3
"""Offline regressions for the retained-source converter; synthetic bytes only.

Run: python3 -m unittest discover -s tests -p test_hotel_retained_materializer.py
No retained archive, private inventory, network, provider or runtime is used.
"""
from __future__ import annotations

import argparse
import contextlib
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import socket
import stat
import tempfile
import unittest
from unittest import mock
import warnings
import zipfile


SOURCE = Path(__file__).resolve().parents[1] / 'tools' / 'hotel_retained_materializer.py'
SPEC = importlib.util.spec_from_file_location('hotel_retained_materializer', SOURCE)
materializer = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(materializer)


def digest(raw):
    return hashlib.sha256(raw).hexdigest()


def json_bytes(value):
    return json.dumps(value, ensure_ascii=False, separators=(',', ':')).encode('utf-8')


class SyntheticArchive:
    """Builds only disposable fixtures with deliberately synthetic provenance."""

    def __init__(self, root, *, fmt='text', excerpt=None, proof_edit=None,
                 duplicate=False, extra_member=None):
        self.root = root
        self.member = 'evidence/synthetic-excerpt.txt'
        self.proof_member = 'evidence/synthetic-proof.json'
        self.segments = [' First synthetic line. ', 'Вторая строка: 🌊\r\nend.']
        if fmt == 'segments':
            self.raw = '\n'.join(self.segments).encode('utf-8')
            self.proof = {
                'evidence_type': 'Short exact literal from actual retrieved lines; synthetic fixture',
                'literal_segments': self.segments,
                'literal_segment_joiner': 'newline; each segment exact, not claimed contiguous full response',
                'short_literal_ref': self.member,
                'short_literal_bytes_sha256': digest(self.raw),
                'read_completed_at_utc': '2026-01-02T03:04:05.123456+02:00',
            }
            self.selection = {
                'id': 'synthetic-segment', 'source_ref': 'country_EG',
                'format': 'literal_segments_with_hashed_companion',
                'proof_member': self.proof_member, 'segment_index': 1,
            }
        else:
            self.raw = excerpt if excerpt is not None else '  Синтетический текст 🌊\r\n tail  '.encode('utf-8')
            self.proof = {
                'evidence_kind': 'EXACT_RETRIEVAL_EXTRACT_TEXT_UTF8',
                'evidence_ref': self.member, 'evidence_bytes': len(self.raw),
                'evidence_sha256': digest(self.raw),
                'read_at_utc': '2026-01-02T03:04:05.123456+02:00',
            }
            self.selection = {
                'id': 'synthetic-text', 'source_ref': 'country_EG',
                'format': 'retrieval_text_with_proof',
                'proof_member': self.proof_member,
            }
        self.proof.update({'url': 'https://example.com/synthetic-evidence',
                           'source_id': 'SYNTHETIC-SOURCE', 'hotel_key': 'SYNTHETIC-HOTEL'})
        if proof_edit:
            self.proof.update(proof_edit)
        self.archive = root / 'preserved' / 'synthetic-country.zip'
        self.archive.parent.mkdir(parents=True, exist_ok=True)
        with zipfile.ZipFile(self.archive, 'w', zipfile.ZIP_DEFLATED) as z:
            z.writestr(self.member, self.raw)
            z.writestr(self.proof_member, json_bytes(self.proof))
            if duplicate:
                with warnings.catch_warnings():
                    warnings.simplefilter('ignore', UserWarning)
                    z.writestr(self.member, self.raw)
            if extra_member:
                z.writestr(*extra_member)
        self.metadata = {
            'name': 'anytour-hotels-EG-research-20260102.zip',
            'archive_path': str(self.archive.relative_to(root)),
            'size_bytes': self.archive.stat().st_size,
            'sha256': 'a' * 64,  # Synthetic preservation pin, deliberately not recomputed.
            'library_file_id': 'SYNTHETIC-NO-LIBRARY-OBJECT', 'version': 1,
            'metadata_provenance': 'synthetic regression fixture only',
        }

    @contextlib.contextmanager
    def open(self):
        # Tests do not require a filesystem with user xattrs.
        with mock.patch.object(materializer.os, 'getxattr', side_effect=OSError('synthetic no xattr')):
            source = materializer.ArchiveSource(self.root, 'country_EG', self.metadata)
        try:
            yield source
        finally:
            source.close()

    def args(self, examples=None):
        inventory = self.root / 'inventory.json'
        inventory.write_bytes(json_bytes({
            'schema': 'anytour.retained-source-inventory.v1',
            'sources': {'country_EG': self.metadata},
        }))
        selections = self.root / 'selections.json'
        selections.write_bytes(json_bytes({
            'schema': 'anytour.retained-literal-selections.v1',
            'examples': [self.selection] if examples is None else examples,
        }))
        return argparse.Namespace(root=self.root, inventory=inventory,
                                  selections=selections, output=self.root / 'output')


class MaterializerTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='hotel-synthetic-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name).resolve()
        self.network = mock.patch.object(socket, 'socket', side_effect=AssertionError('network forbidden'))
        self.network.start()
        self.addCleanup(self.network.stop)

    def assert_error(self, code, fn, *args):
        with self.assertRaises(materializer.InputError) as caught:
            fn(*args)
        self.assertEqual(code, caught.exception.code)

    def test_exact_text_utf8_whitespace_and_capture_provenance(self):
        fixture = SyntheticArchive(self.root)
        with fixture.open() as source:
            label, envelope, sidecar, raw = materializer.materialize(source, fixture.selection)
        record = envelope['sources'][0]
        self.assertEqual(fixture.raw, raw)
        self.assertEqual(raw, record['excerpt'].encode('utf-8'))
        self.assertEqual(digest(raw), record['sha256'])
        self.assertEqual(1767315845, record['captured_at'])
        self.assertEqual('2026-01-02T03:04:05.123456+02:00',
                         sidecar['capture_provenance']['original_value'])
        self.assertEqual(123456, sidecar['capture_provenance']['fractional_microseconds'])
        self.assertIsNone(sidecar['scope'])
        self.assertFalse(sidecar['ready_for_launch'])
        self.assertEqual(0, sidecar['new_acceptance_credit'])
        self.assertEqual({'url', 'excerpt', 'sha256', 'captured_at', 'kind'}, set(record))

    def test_exact_one_segment_and_escaped_json_pointer(self):
        fixture = SyntheticArchive(self.root, fmt='segments')
        with fixture.open() as source:
            _, envelope, sidecar, raw = materializer.materialize(source, fixture.selection)
        self.assertEqual(fixture.segments[1].encode('utf-8'), raw)
        self.assertEqual('/literal_segments/1', sidecar['selected_literal']['json_pointer_in_proof'])
        start = len(fixture.segments[0].encode('utf-8')) + 1
        self.assertEqual({'start_inclusive': start, 'end_exclusive': start + len(raw)},
                         sidecar['selected_literal']['byte_range'])
        self.assertNotIn(fixture.segments[0], envelope['sources'][0]['excerpt'])
        self.assertEqual('value', materializer.at({'a/b': {'~': ['value']}}, '/a~1b/~0/0', 'synthetic'))

    def test_tampered_original_text_digest_rejected(self):
        fixture = SyntheticArchive(self.root, proof_edit={'evidence_sha256': '0' * 64})
        with fixture.open() as source:
            self.assert_error('ORIGINAL_LITERAL_DIGEST_MISMATCH', materializer.materialize,
                              source, fixture.selection)

    def test_tampered_literal_companion_rejected(self):
        fixture = SyntheticArchive(self.root, fmt='segments',
                                   proof_edit={'short_literal_bytes_sha256': '0' * 64})
        with fixture.open() as source:
            self.assert_error('ORIGINAL_LITERAL_COMPANION_MISMATCH', materializer.materialize,
                              source, fixture.selection)

    def test_noncontiguous_joiner_is_not_reinterpreted(self):
        fixture = SyntheticArchive(self.root, fmt='segments',
                                   proof_edit={'literal_segment_joiner': 'space'})
        with fixture.open() as source:
            self.assert_error('ORIGINAL_LITERAL_JOINER_UNCONFIRMED', materializer.materialize,
                              source, fixture.selection)

    def test_utf8_split_is_not_repaired(self):
        fixture = SyntheticArchive(self.root, excerpt='ё'.encode('utf-8'))
        fixture.selection['byte_range'] = [0, 1]
        with fixture.open() as source:
            self.assert_error('UTF8_BOUNDARY_REQUIRED', materializer.materialize, source, fixture.selection)

    def test_boolean_or_out_of_bounds_byte_ranges_rejected(self):
        fixture = SyntheticArchive(self.root)
        with fixture.open() as source:
            for span in ([False, 2], [-1, 2], [0, len(fixture.raw) + 1], [2, 2]):
                with self.subTest(span=span):
                    self.assert_error('EXACT_UTF8_BYTE_RANGE_REQUIRED', materializer.materialize,
                                      source, {**fixture.selection, 'byte_range': span})

    def test_excerpt_limit_counts_bytes_not_characters(self):
        fixture = SyntheticArchive(self.root, excerpt=('я' * 2049).encode('utf-8'))
        with fixture.open() as source:
            self.assert_error('EXCERPT_BYTE_LIMIT', materializer.materialize, source, fixture.selection)

    def test_member_limit_and_duplicate_identity_rejected(self):
        fixture = SyntheticArchive(self.root, duplicate=True,
                                   extra_member=('evidence/oversized.txt', b'x' * 65537))
        with fixture.open() as source:
            self.assert_error('SOURCE_MEMBER_MISSING_OR_AMBIGUOUS', source.member, fixture.member)
            self.assert_error('SOURCE_MEMBER_BYTE_LIMIT_OR_ENCRYPTION', source.member, 'evidence/oversized.txt')

    def test_only_exact_public_source_members_are_read(self):
        fixture = SyntheticArchive(self.root)
        with fixture.open() as source:
            for name in ('/evidence/file', '../evidence/file', 'evidence/../file',
                         'evidence/claim-key.json', 'checkpoint/evidence/data.json', 'other/file.txt'):
                with self.subTest(name=name):
                    self.assert_error('NON_SOURCE_MEMBER_NOT_ALLOWED', source.member, name)
            self.assertEqual({}, source.cache)

    def test_nested_secret_field_aliases_rejected(self):
        for key in ('access_token', 'accessToken', 'Access-Token', 'Authorization',
                    'clientSecret', 'privateKey', 'claimKey', 'apiKey', 'refreshToken'):
            with self.subTest(key=key):
                self.assert_error('PRIVATE_FIELD_NOT_ALLOWED', materializer.parse_json,
                                  json_bytes({'nested': [{key: 'SYNTHETIC-DO-NOT-EXPORT'}]}), 'synthetic')

    def test_secret_field_markers_do_not_enter_envelope(self):
        for index, marker in enumerate(('accessToken', 'Authorization', 'client-secret', 'privateKey')):
            with self.subTest(marker=marker):
                directory = self.root / str(index)
                directory.mkdir()
                fixture = SyntheticArchive(directory, excerpt=(marker + ': SYNTHETIC-DO-NOT-EXPORT').encode())
                with fixture.open() as source:
                    self.assert_error('PRIVATE_FIELD_MARKER_IN_EXCERPT', materializer.materialize,
                                      source, fixture.selection)

    def test_credential_urls_and_nonpublic_shapes_rejected(self):
        for url in ('http://example.com/', 'https://user:pass@example.com/',
                    'https://127.0.0.1/', 'https://example.internal/', 'https://example.com:444/',
                    'https://example..com/', 'https://-bad.example/', 'HTTPS://example.com/',
                    'https://example.com/' + 'я' * 1100, 'https://example.com/\x7f',
                    'https://example.com../', 'https://' + '.'.join(['a' * 63] * 5) + '/',
                    'https://127.0.0.1./', 'https://example.internal./'):
            with self.subTest(url=url):
                with self.assertRaises(materializer.InputError):
                    materializer.source_url(url, 'synthetic')
        for key in ('accessToken', 'Authorization', 'private-key'):
            with self.subTest(key=key):
                self.assert_error('CREDENTIAL_QUERY_NOT_ALLOWED', materializer.source_url,
                                  'https://example.com/?' + key + '=SYNTHETIC', 'synthetic')
        for fragment in ('access_token=SYNTHETIC', 'accessToken=SYNTHETIC',
                         '%61ccess%5Ftoken=SYNTHETIC', 'Authorization:SYNTHETIC'):
            with self.subTest(fragment=fragment):
                self.assert_error('CREDENTIAL_FRAGMENT_NOT_ALLOWED', materializer.source_url,
                                  'https://example.com/#' + fragment, 'synthetic')
        exact = 'https://example.com/%D1%8F?view=1#section'
        self.assertEqual(exact, materializer.source_url(exact, 'synthetic'))
        self.assertEqual('https://example.com./', materializer.source_url('https://example.com./', 'synthetic'))

    def test_original_capture_time_required(self):
        for value in (None, '2026-01-02', '2026-01-02T03:04:05', '2026-02-30T00:00:00Z'):
            with self.subTest(value=value):
                self.assert_error('ORIGINAL_CAPTURE_TIMESTAMP_REQUIRED', materializer.capture_time,
                                  value, 'synthetic')

    def test_duplicate_json_keys_and_invalid_utf8_rejected(self):
        self.assert_error('DUPLICATE_JSON_KEY', materializer.parse_json, b'{"x":1,"x":2}', 'synthetic')
        self.assert_error('INVALID_UTF8_JSON', materializer.parse_json, b'{"x":"\xff"}', 'synthetic')

    def test_metadata_read_is_bounded_before_allocation(self):
        # Observe actual requested bytes without allocating an oversized input.
        class BoundedStream(io.BytesIO):
            def read(self, size=-1):
                if size < 0 or size > materializer.MAX_METADATA_BYTES + 1:
                    raise AssertionError('unbounded metadata read')
                return super().read(size)
        path = mock.Mock()
        path.open.return_value = BoundedStream(b'{}')
        self.assertEqual(b'{}', materializer.read_metadata(path, 'synthetic'))
        path.open.return_value = BoundedStream(b'x' * (materializer.MAX_METADATA_BYTES + 1))
        self.assert_error('JSON_BYTE_LIMIT', materializer.read_metadata, path, 'synthetic')

    def test_archive_country_identity_must_match_inventory_source(self):
        fixture = SyntheticArchive(self.root)
        fixture.metadata['name'] = 'anytour-hotels-VN-research-20260102.zip'
        self.assert_error('COUNTRY_ARCHIVE_IDENTITY_MISMATCH', materializer.ArchiveSource,
                          self.root, 'country_EG', fixture.metadata)

    def test_paths_cannot_escape_root_or_rewrite_preserved_archive(self):
        self.assert_error('PATH_OUTSIDE_RETAINED_ROOT', materializer.inside,
                          self.root, '../other', 'synthetic')
        fixture = SyntheticArchive(self.root)
        args = fixture.args()
        args.output = self.root / 'preserved' / 'derived'
        self.assert_error('OUTPUT_MUST_BE_SEPARATE_FROM_PRESERVED_SOURCES', materializer.run, args)

    def test_failure_in_second_selection_leaves_no_partial_output(self):
        fixture = SyntheticArchive(self.root)
        args = fixture.args([fixture.selection, {**fixture.selection, 'id': 'bad', 'format': 'unsupported'}])
        with mock.patch.object(materializer.os, 'getxattr', side_effect=OSError('synthetic no xattr')):
            self.assert_error('UNSUPPORTED_RETAINED_LITERAL_FORMAT', materializer.run, args)
        self.assertFalse(args.output.exists())

    def test_private_file_is_restrictive_before_first_write(self):
        path = self.root / 'private.json'
        observed = []
        original_fdopen = os.fdopen

        def observe(fd, *args, **kwargs):
            observed.append(stat.S_IMODE(os.fstat(fd).st_mode))
            return original_fdopen(fd, *args, **kwargs)

        old_umask = os.umask(0)
        try:
            with mock.patch.object(materializer.os, 'fdopen', side_effect=observe):
                materializer.write_private(path, {'synthetic': True})
        finally:
            os.umask(old_umask)
        self.assertEqual([0o600], observed)
        self.assertEqual(0o600, stat.S_IMODE(path.stat().st_mode))
        original = path.read_bytes()
        with self.assertRaises(FileExistsError):
            materializer.write_private(path, {'replacement': True})
        self.assertEqual(original, path.read_bytes())

    def test_offline_cli_readback_and_no_overwrite(self):
        fixture = SyntheticArchive(self.root)
        args = fixture.args()
        args.output.mkdir(mode=0o755)
        before = fixture.archive.read_bytes()
        with mock.patch.object(materializer.os, 'getxattr', side_effect=OSError('synthetic no xattr')):
            result = materializer.run(args)
        self.assertEqual('PASS_OFFLINE_LITERAL_EXTRACTION_ONLY', result['result'])
        self.assertEqual(before, fixture.archive.read_bytes())
        self.assertEqual(0o700, stat.S_IMODE(args.output.stat().st_mode))
        manifest = json.loads((args.output / 'manifest.json').read_bytes())
        for record in manifest['files']:
            saved = args.output / record['path']
            raw = saved.read_bytes()
            self.assertEqual(record['bytes'], len(raw))
            self.assertEqual(record['sha256'], digest(raw))
            self.assertEqual(0o600, stat.S_IMODE(saved.stat().st_mode))
        report = json.loads((args.output / 'readback.json').read_bytes())
        self.assertEqual(2, report['selected_source_members_read'])
        for field in ('network_calls', 'model_calls', 'production_calls',
                      'full_archive_or_backup_hashes_computed', 'original_files_written',
                      'new_acceptance_credit'):
            self.assertEqual(0, report[field])
        self.assertFalse(report['ready_for_launch'])
        self.assert_error('OUTPUT_NOT_EMPTY_NO_OVERWRITE', materializer.run, args)


if __name__ == '__main__':
    unittest.main(verbosity=2)
