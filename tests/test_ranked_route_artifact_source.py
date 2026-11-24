import hashlib
import importlib.util
import io
import json
from pathlib import Path
import unittest
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


if __name__ == '__main__':
    unittest.main()
