"""Offline packet fixtures: no provider, database, server, or approval mutations."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest
import zipfile

SPEC = importlib.util.spec_from_file_location('ranked_input', Path(__file__).resolve().parents[1] / 'tools/validate_ranked_telegram_input.py')
M = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(M)


def digest(b):
    return hashlib.sha256(b).hexdigest()


def wire(x):
    return json.dumps(x, ensure_ascii=False).encode()


class PacketInspection(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        (self.root / 'config').mkdir()
        self.archive = self.root / 'native.zip'
        self.plan_path = 'config/anytour_packet_plan.json'
        self.index_path = 'config/anytour_packet_index.json'
        self.channel = {'chat_id': '-1001845835645', 'url': 'https://t.me/anytour',
                        'config_version': 2, 'button_text': 'Calculate', 'bot_url': 'https://t.me/anyru_bot'}
        self.photo = {'type': 'image', 'provider': 'telegram', 'reusable_telegram_photo': True,
                      'file_id_sha256': '1' * 64, 'file_unique_id_sha256': '2' * 64}
        source = {'publication_id': 3, 'version_id': 4, 'content_hash': '3' * 64,
                  'state': 'sent', 'post_url': 'https://t.me/any_toour/1', 'hotel_id': 1,
                  'current_token_resolves': True, 'native_claim_held_or_unknown': False, 'attachments': [self.photo]}
        public_source = {'ok': True, 'single_photo': True, 'url': source['post_url'],
                         'caption_sha256': '4' * 64, 'photo_marker_sha256': '5' * 64}
        source['caption_sha256'] = public_source['caption_sha256']
        target = {'ok': True, 'single_photo': True, 'buttons': [], 'url': 'https://t.me/anytour/2',
                  'published_unixtime': 1, 'caption_sha256': '6' * 64, 'photo_marker_sha256': '7' * 64}
        self.row = {'hotel_id': 1, 'source_message_id': '1', 'target_message_id': '2',
                    'preview_ready': True, 'preview_blockers': [], 'source_matches': True,
                    'historical_target_matches': True, 'target_media_lock_free': True,
                    'prior_media_operation_directory_exists': False, 'target_current_pin_matches': False,
                    'existing_large_channel_hotel_registry_matches': [], 'target_native': None,
                    'source_native': source, 'source_public': public_source, 'target_public': target}
        self.native = {'schema': 1, 'issue': 1566, 'ok': True, 'operation': 'hotel_archive_telegram_ranked_registry',
                       'native_run_id': '1', 'native_run_attempt': 1, 'repository_sha': 'a' * 40,
                       'application_sha': 'a' * 40, 'checkpoint_sha256': 'b' * 64,
                       'production_writes': 0, 'provider_writes': 0, 'bot_starts': 0,
                       'price_editor_shared_read_lease': True, 'target_channel': self.channel,
                       'native_channel_rights': {'state': 'ready', 'receipt': {'write_attempts': 0}},
                       'native_public_channel_binding': {'anytour': True, 'any_toour': True}, 'rows': [self.row]}
        self.plan_row = {'hotel_id': 1, 'source_message_id': '1', 'source_publication_id': 3,
                         'source_version_id': 4, 'source_content_hash': '3' * 64,
                         'source_caption_sha256': '4' * 64, 'source_file_id_sha256': '1' * 64,
                         'source_file_unique_id_sha256': '2' * 64, 'source_photo_marker_sha256': '5' * 64,
                         'target_message_id': '2', 'target_published_unixtime': 1,
                         'target_caption_sha256': '6' * 64, 'target_photo_marker_sha256': '7' * 64,
                         'target_config_version': 2, 'button_text': 'Calculate', 'bot_url': 'https://t.me/anyru_bot',
                         'next_caption': 'Offline fixture'}
        self.write_packet()

    def write_packet(self):
        raw = wire(self.native)
        with zipfile.ZipFile(self.archive, 'w') as z:
            z.writestr('registry.json', raw)
        proof = {k: self.native[k] for k in ('repository_sha', 'application_sha', 'checkpoint_sha256')}
        proof.update(run_id='1', run_attempt=1, artifact_zip_sha256=digest(self.archive.read_bytes()), registry_sha256=digest(raw))
        plan = wire({'operation': 'offline_fixture', 'native_preflight': proof, 'rows': [self.plan_row]})
        (self.root / self.plan_path).write_bytes(plan)
        index = wire({'schema': 1, 'native_preflight': proof, 'native_zip_bytes': self.archive.stat().st_size,
                      'plans': [{'path': self.plan_path, 'sha256': digest(plan), 'bytes': len(plan),
                                 'git_blob': hashlib.sha1(b'blob ' + str(len(plan)).encode() + b'\0' + plan).hexdigest()}]})
        (self.root / self.index_path).write_bytes(index)
        self.index_sha = digest(index)

    def inspect(self):
        return M.inspect_input(self.index_sha, self.archive, self.root, self.index_path)

    def test_inspection_has_no_approval(self):
        result = self.inspect()
        self.assertTrue(result['evidence_only'])
        self.assertFalse(result['approval_verified'])
        self.assertFalse(result['write_admission'])
        self.assertEqual(result['chunks'][0]['hotels'], [1])
        self.assertFalse((self.root / 'config/anytour_ranked_approved_indexes.json').exists())

    def test_existing_approval_path_still_requires_allowlist(self):
        old_root, old_approvals = M.ROOT, M.APPROVALS
        M.ROOT = self.root
        M.APPROVALS = self.root / 'config/anytour_ranked_approved_indexes.json'
        self.addCleanup(lambda: setattr(M, 'ROOT', old_root))
        self.addCleanup(lambda: setattr(M, 'APPROVALS', old_approvals))
        M.APPROVALS.write_text('[]')
        with self.assertRaisesRegex(ValueError, 'release approval'):
            M.approved_input(self.index_sha, self.archive)
        M.APPROVALS.write_bytes(wire([{'path': self.index_path, 'sha256': self.index_sha}]))
        # document's default is the real release root, so pin an explicit wrapper for the legacy fixture.
        original = M.document
        M.document = lambda item, limit, root=None: original(item, limit, self.root if root is None else root)
        self.addCleanup(lambda: setattr(M, 'document', original))
        self.assertEqual(M.approved_input(self.index_sha, self.archive), self.inspect()['chunks'])

    def test_native_hold_is_not_turned_into_permission(self):
        self.row['preview_ready'] = False
        self.row['preview_blockers'] = ['source_not_confirmed']
        self.write_packet()
        with self.assertRaisesRegex(ValueError, 'native pair not eligible'):
            self.inspect()

    def test_price_lease_is_required(self):
        self.native['price_editor_shared_read_lease'] = False
        self.write_packet()
        with self.assertRaisesRegex(ValueError, 'native read identity changed'):
            self.inspect()

    def test_changed_plan_and_archive_are_rejected(self):
        (self.root / self.plan_path).write_text('{}')
        with self.assertRaisesRegex(ValueError, 'approved bytes changed'):
            self.inspect()
        self.write_packet()
        self.archive.write_bytes(b'changed')
        with self.assertRaisesRegex(ValueError, 'native ZIP size changed'):
            self.inspect()

    def test_nonregular_input_is_rejected_without_opening_it(self):
        fifo = self.root / 'config/anytour_fifo.json'
        os.mkfifo(fifo)
        with self.assertRaisesRegex(ValueError, 'invalid plan file'):
            M.inspect_input(self.index_sha, self.archive, self.root, 'config/anytour_fifo.json')

    def test_paths_and_symlinks_are_rejected(self):
        with self.assertRaisesRegex(ValueError, 'invalid plan path'):
            M.inspect_input(self.index_sha, self.archive, self.root, '../outside.json')
        link = self.root / 'alias'
        link.symlink_to(self.root, target_is_directory=True)
        with self.assertRaisesRegex(ValueError, 'invalid inspection root'):
            M.inspect_input(self.index_sha, self.archive, link, self.index_path)
        plan = self.root / self.plan_path
        moved = self.root / 'moved.json'
        plan.rename(moved)
        plan.symlink_to(moved)
        with self.assertRaisesRegex(ValueError, 'invalid plan file'):
            self.inspect()


if __name__ == '__main__':
    unittest.main()
