import base64
import hashlib
import json
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "tools"))
from merge_ranked_telegram_routes import combine
from prepare_ranked_telegram_routes import RESOLVER, digest, materialize_ranked_data


def git_blob(contents):
    return hashlib.sha1(f"blob {len(contents)}\0".encode() + contents).hexdigest()


def row(hotel, source, target):
    return dict(hotel_id=hotel, source_url=f"https://t.me/any_toour/{source}", post_url=f"https://t.me/anytour/{target}",
                publication_id=hotel + 100, version_id=hotel + 200, html_sha256="a" * 64, plan_sha256="b" * 64)


def package(run, rows):
    return dict(native=dict(run_id=str(run)), public=dict(run_id=str(run + 1000)), approved_index=dict(sha256="c" * 64),
                changes=rows, already_configured=[], held=[])


class OwnerBatch(unittest.TestCase):
    def setUp(self):
        self.work = tempfile.TemporaryDirectory()
        self.addCleanup(self.work.cleanup)
        self.feed = Path(self.work.name, "feed.xml")
        offers = "".join(f'<offer id="tg_{i}"><url>https://t.me/any_toour/{i}</url></offer>' for i in range(8001, 8006))
        self.feed.write_text("<yml_catalog><shop><offers>" + offers + "</offers></shop></yml_catalog>")
        self.resolver = ROOT / RESOLVER

    def test_independent_receipts_combine_on_one_base_and_replay_is_empty(self):
        groups = [package(1, [row(8001, 8001, 111)]), package(2, [row(8002, 8002, 112)]), package(3, [])]
        candidate, result = combine(self.feed, groups, self.resolver)
        self.assertEqual([r["native_run_id"] for r in result["changes"]], ["1", "2"])
        self.assertEqual(result["feed"]["unchanged"], 3)
        self.assertEqual(result["held"], [])
        installed = Path(self.work.name, "candidate.php")
        installed.write_bytes(candidate)
        second, report = combine(self.feed, groups, installed)
        self.assertEqual(second, candidate)
        self.assertEqual(report["changes"], [])
        self.assertEqual(len(report["already_configured"]), 2)

    def test_disputed_hotel_and_colliding_target_hold_only_affected_rows(self):
        groups = [package(1, [row(8001, 8001, 111), row(8003, 8003, 113)]),
                  package(2, [row(8001, 8001, 112), row(8002, 8002, 111)])]
        _, result = combine(self.feed, groups, self.resolver)
        self.assertEqual([r["hotel_id"] for r in result["changes"]], [8003])
        self.assertEqual(sorted(r["hotel_id"] for r in result["held"]), [8001, 8001, 8002])

    def test_chain_of_shared_sources_and_targets_does_not_restore_a_disputed_destination(self):
        groups = [package(1, [row(8001, 8001, 111), row(8004, 8004, 114)]),
                  package(2, [row(8001, 8002, 112), row(8002, 8002, 113)]),
                  package(3, [row(8003, 8003, 113)])]
        _, result = combine(self.feed, groups, self.resolver)
        self.assertEqual([r["hotel_id"] for r in result["changes"]], [8004])
        self.assertEqual(len(result["held"]), 4)

    def test_duplicate_native_run_requires_reconciliation(self):
        with self.assertRaisesRegex(ValueError, "same native run"):
            combine(self.feed, [package(1, []), package(1, [])], self.resolver)

    def test_detached_route_packet_is_recovered_from_exact_source(self):
        source = 'a' * 40
        list_path = 'config/anytour_ranked_approved_indexes.json'
        index_path = 'config/anytour_packet_index.json'
        plan_path = 'config/anytour_packet_plan.json'
        plan = b'{"rows":[{"hotel_id":8001}]}'
        index = json.dumps({'schema':1, 'plans':[{'path':plan_path,'bytes':len(plan),
                           'sha256':digest(plan), 'git_blob':git_blob(plan)}]}).encode()
        expected = {'path':index_path,'sha256':digest(index)}
        contents = {list_path:json.dumps([expected]).encode(), index_path:index, plan_path:plan}

        def api(path):
            relative, ref = path.removeprefix('contents/').split('?ref=')
            self.assertEqual(ref, source)
            raw = contents[relative]
            return {'type':'file','path':relative,'encoding':'base64','size':len(raw),
                    'content':base64.b64encode(raw).decode(), 'sha':git_blob(raw)}

        root = Path(self.work.name, 'detached')
        self.assertEqual(materialize_ranked_data(api, source, digest(index), root), expected)
        self.assertEqual((root / plan_path).read_bytes(), plan)
        contents[plan_path] += b' '
        bad = Path(self.work.name, 'changed')
        with self.assertRaisesRegex(ValueError, 'ranked data plan changed'):
            materialize_ranked_data(api, source, digest(index), bad)
        self.assertFalse(bad.exists())


if __name__ == "__main__":
    unittest.main()
