import hashlib
import importlib.util
import io
import json
from pathlib import Path
import sys
import tempfile
from datetime import datetime, timezone
from types import SimpleNamespace
import unittest
from unittest.mock import patch
import zipfile

script = Path(__file__).resolve().parents[1] / 'tools' / 'collect_ranked_route_manifests.py'
spec = importlib.util.spec_from_file_location('ranked_source', script)
source = importlib.util.module_from_spec(spec)
spec.loader.exec_module(source)


class RouteCandidateOrigin(unittest.TestCase):
    def artifact(self, observer='a'*40, candidate=b'verified source', extra=False):
        manifest = {'native': {'run_id': 123}, 'public': {'run_id': 456, 'repository_sha': observer},
                    'resolver': {'candidate_sha256': hashlib.sha256(b'verified source').hexdigest()},
                    'changes': []}
        buffer = io.BytesIO()
        with zipfile.ZipFile(buffer, 'w') as archive:
            archive.writestr('route-candidate.json', json.dumps(manifest))
            archive.writestr('hotel-posts-telegram-main19.php', candidate)
            if extra:
                archive.writestr('../other', 'invalid')
        data = buffer.getvalue()
        meta = {'name': 'ranked-route-candidate-123', 'size_in_bytes': len(data),
                'digest': 'sha256:'+hashlib.sha256(data).hexdigest()}
        return meta, {'id':456, 'head_sha':'a'*40}, data

    def test_valid_artifact(self):
        self.assertEqual(json.loads(source.verified(*self.artifact()))['native']['run_id'], 123)

    def test_digest_source_and_bounds(self):
        for args in [{'observer':'b'*40}, {'candidate':b'replaced'}, {'extra':True}]:
            with self.subTest(args=args), self.assertRaises(ValueError):
                source.verified(*self.artifact(**args))
        meta, observer, data = self.artifact()
        for wrong in [{'digest':'sha256:'+'0'*64}, {'name':'ranked-route-candidate-999'},
                      {'size_in_bytes':len(data)+1}]:
            with self.subTest(wrong=wrong), self.assertRaises(ValueError):
                source.verified(meta | wrong, observer, data)

    def test_late_recovery_suppresses_only_the_empty_early_candidate(self):
        for changes, staged in [([], True), ([{'offer_id': 'tg_123'}], True), ([], False)]:
            with self.subTest(changes=changes, staged=staged), tempfile.TemporaryDirectory() as work:
                candidate = {'id': 789, 'name': 'ranked-route-candidate-123', 'expired': False, 'size_in_bytes': 1}
                public = {'id': 101, 'name': 'ranked-public-readback-123', 'expired': False, 'digest': 'sha256:' + 'a'*64}
                manifest = {'native': {'run_id': '123'}, 'public': {'artifact_id': '101', 'artifact_sha256': 'a'*64},
                            'changes': changes, 'already_configured': [], 'held': []}
                original = {'id': 456, 'event': 'workflow_run', 'path': source.OBSERVER, 'status': 'completed',
                            'repository': {'full_name': source.REPO}, 'head_repository': {'full_name': source.REPO},
                            'head_sha': 'a'*40}

                def api(path, *, binary=False):
                    if binary:
                        return b'archive'
                    if path.startswith('actions/workflows/'):
                        return {'workflow_runs': [original]}
                    return {'total_count': 2, 'artifacts': [candidate, public]}

                def stage_original(*_):
                    if not staged:
                        raise ValueError('late archive unavailable')
                    return {'public': {'artifact_id': '202'}}

                merge = SimpleNamespace(LATE_PUBLIC_RECEIPTS={'567': {'observed_on': '2026-11-28',
                                                                    'early_observer': 456, 'native': '123'}},
                                        stage_original=stage_original)
                now = SimpleNamespace(now=lambda _: datetime(2026, 11, 28, tzinfo=timezone.utc))
                with patch.dict(sys.modules, {'merge_ranked_telegram_routes': merge}), patch.object(source, 'datetime', now), \
                        patch.object(source, 'api', side_effect=api), \
                        patch.object(source, 'verified', return_value=json.dumps(manifest).encode()):
                    output = Path(work) / 'collected'
                    records = source.collect(output, Path(work) / 'feed.xml')
                superseded = staged and not changes
                self.assertEqual((output / '456-789.json').exists(), not superseded)
                self.assertEqual((output / '567-late-original.json').exists(), staged)
                self.assertEqual(records[-1].get('superseded_empty_candidate', False), superseded)


if __name__ == '__main__':
    unittest.main()
