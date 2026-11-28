import base64
import hashlib
import io
import json
import sys
import tempfile
import unittest
import zipfile
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "tools"))
from merge_ranked_telegram_routes import LATE_PUBLIC_RECEIPTS, combine, observe_original
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


class LatePublicReceipt(unittest.TestCase):
    RUN = "45799877337"
    HEAD = "3d21cbcf0f04709a2b83898337b7718a7680a48c"
    BRANCH = "ops/1566-telegram-46-native-read-20261123"
    WORKFLOW = ".github/workflows/read-1566-telegram-46-20261123.yml"

    def setUp(self):
        owner = dict(id=226193297, login="pyatkoff")
        repo = dict(id=1342493152, full_name="pyatkoff/max-search-bot")
        self.run = dict(id=int(self.RUN), head_sha=self.HEAD, head_branch=self.BRANCH,
                        actor=dict(owner), triggering_actor=dict(owner), repository=repo,
                        head_repository=repo, event="push", path=self.WORKFLOW,
                        run_attempt=1, conclusion="success", status="completed")
        self.job = dict(id=139840014674, run_id=int(self.RUN), run_attempt=1, head_sha=self.HEAD,
                        status="completed", conclusion="success", name="registry", steps=[
                            dict(name=name, status="completed", conclusion="success")
                            for name in (
                                "Check out the trusted public verifier",
                                "Authenticate the original transfer, job and immutable artifact",
                                "Read and verify published Telegram cards",
                                "Preserve independent late public evidence separately",
                            )
                        ])
        archive = io.BytesIO()
        with zipfile.ZipFile(archive, "w") as output:
            output.writestr("ranked-public-readback.json", '{"synthetic_receipt":true}')
        self.body = archive.getvalue()
        self.pin = LATE_PUBLIC_RECEIPTS[self.RUN] | {"digest": "sha256:" + digest(self.body)}
        self.public = dict(id=15107016255, name="ranked-public-readback-late-45799514607",
                           size_in_bytes=len(self.body), expired=False, digest=self.pin["digest"],
                           workflow_run=dict(id=int(self.RUN), head_sha=self.HEAD, head_branch=self.BRANCH,
                                             repository_id=1342493152, head_repository_id=1342493152))

    def api(self, path, *, binary=False):
        path = path.split("?", 1)[0]
        run_path = f"actions/runs/{self.run['id']}"
        job_path = f"{run_path}/attempts/1/jobs"
        artifact_path = f"actions/artifacts/{self.public['id']}"
        if path == run_path:
            return self.run
        if path == job_path:
            return dict(total_count=1, jobs=[self.job])
        if path == f"{run_path}/artifacts":
            return dict(total_count=1, artifacts=[self.public])
        if path == f"{artifact_path}/zip" and binary:
            return self.body
        self.fail(f"unexpected GitHub read {path}")

    def observe(self, run_id=RUN):
        with patch.dict(LATE_PUBLIC_RECEIPTS, {self.RUN: self.pin}), \
                patch("merge_ranked_telegram_routes.api", side_effect=self.api):
            return observe_original(run_id)

    def test_admits_exact_original_late_receipt(self):
        observer, public, body, native = self.observe()
        self.assertEqual(observer["id"], int(self.RUN))
        self.assertEqual(public["id"], 15107016255)
        self.assertEqual(body, self.body)
        self.assertEqual(native, "45799514607")

    def test_arbitrary_push_does_not_enter_late_path(self):
        self.run["id"] += 1
        with self.assertRaises(ValueError):
            self.observe(str(self.run["id"]))

    def test_wrong_actor_or_triggering_actor_is_refused(self):
        for field in ("actor", "triggering_actor"):
            with self.subTest(field=field), patch.dict(self.run, {field: {"id": 9}}):
                with self.assertRaises(ValueError):
                    self.observe()

    def test_changed_head_or_attempt_is_refused(self):
        for mutation in ({"head_sha": "e" * 40}, {"run_attempt": 2}):
            with self.subTest(mutation=mutation), patch.dict(self.run, mutation):
                with self.assertRaises(ValueError):
                    self.observe()

    def test_changed_job_or_unsuccessful_upload_is_refused(self):
        with patch.dict(self.job, {"id": self.job["id"] + 1}):
            with self.assertRaises(ValueError):
                self.observe()
        self.job["steps"][-1]["conclusion"] = "failure"
        with self.assertRaises(ValueError):
            self.observe()

    def test_changed_artifact_identity_or_digest_is_refused(self):
        for mutation in ({"name": "ranked-public-readback-late-1"},
                         {"digest": "sha256:" + "0" * 64}):
            with self.subTest(mutation=mutation), patch.dict(self.public, mutation):
                with self.assertRaises(ValueError):
                    self.observe()
        self.body = b"x" + self.body[1:]
        with self.assertRaises(ValueError):
            self.observe()

    def test_valid_archive_with_unrecognized_digest_is_refused(self):
        self.pin["digest"] = "sha256:" + "0" * 64
        with self.assertRaises(ValueError):
            self.observe()


if __name__ == "__main__":
    unittest.main()
