#!/usr/bin/env python3
"""Synthetic checks of timing, grouping and rejection; not buyer accuracy."""
import copy
import importlib.util
from pathlib import Path
import sys
import unittest
import subprocess
import json
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "tools"))
sys.path.insert(0, str(ROOT / "tests"))
import build_conversion_observations as base
import conversion_predictor as predictor
from run_conversion_observations import observation, item

def example(person, positive, start, prediction, end):
    r = observation()
    r.update(context_from=start, prediction_at=prediction, observed_through=end)
    r["subject"]["customer_key"] = person
    event = item("outcome:"+person, "booking_intent" if positive else "declined", end)
    event["subject"] = dict(r["subject"])
    r["evidence"] = [event]
    return r

class PredictorTests(unittest.TestCase):
    def setUp(self):
        self.clock = patch("conversion_predictor.utc_now", return_value="2026-10-17T00:00:00Z")
        self.clock.start()
        self.addCleanup(self.clock.stop)
    def dataset(self):
        train = [example("a", True,"2026-10-01T00:00:00Z","2026-10-01T12:00:00Z","2026-10-08T12:00:00Z"),
                 example("b", False,"2026-10-01T00:00:00Z","2026-10-01T12:00:00Z","2026-10-08T12:00:00Z")]
        hold = [example("c", True,"2026-10-09T00:00:00Z","2026-10-09T12:00:00Z","2026-10-16T12:00:00Z"),
                example("d", False,"2026-10-09T00:00:00Z","2026-10-09T12:00:00Z","2026-10-16T12:00:00Z")]
        doc = {"schema_version": 1, "observations": train + hold}
        groups = [base.build_observation(r)["group_key"] for r in train + hold]
        return doc, groups[:2], groups[2:]

    def test_real_builder_splits_and_metrics(self):
        doc, tr, te = self.dataset()
        got = predictor.fit_evaluate(doc,tr,te)
        self.assertEqual(got["baseline"]["count"],2)
        self.assertEqual(got["candidate"]["positives"],1)
        self.assertAlmostEqual(got["candidate"]["brier"],.25)
        self.assertAlmostEqual(got["candidate"]["log_loss"],__import__("math").log(2))
        self.assertFalse(got["export_allowed"])
        self.assertIsNone(got["purchase_probability"])

    def test_overlap_duplicate_future_and_unknown_rejected(self):
        doc,tr,te=self.dataset()
        with self.assertRaisesRegex(predictor.NotReady,"group_split_invalid"):
            predictor.fit_evaluate(doc,tr,te+[tr[0]])
        with self.assertRaisesRegex(base.InvalidObservation,"duplicate_observation"):
            predictor.fit_evaluate({"schema_version":1,"observations":doc["observations"]+[copy.deepcopy(doc["observations"][0])]},tr,te)
        with patch("conversion_predictor.utc_now", return_value="2026-10-10T00:00:00Z"):
            with self.assertRaisesRegex(predictor.NotReady,"future_observation"):
                predictor.fit_evaluate(doc,tr,te)
        unknown=copy.deepcopy(doc)
        for row in unknown["observations"]: row["evidence"]=[]
        with self.assertRaisesRegex(predictor.NotReady,"both_classes_required"):
            predictor.fit_evaluate(unknown,tr,te)

    def test_one_class_and_temporal_leak_rejected(self):
        doc,tr,te=self.dataset()
        one=copy.deepcopy(doc)
        one["observations"][1]["evidence"][0]["kind"]="booking_intent"
        with self.assertRaisesRegex(predictor.NotReady,"both_classes_required"):
            predictor.fit_evaluate(one,tr,te)
        leak=copy.deepcopy(doc)
        leak["observations"][0]["observed_through"]="2026-10-10T12:00:00Z"
        with self.assertRaisesRegex(predictor.NotReady,"training_outcomes_after_holdout_prediction"):
            predictor.fit_evaluate(leak,tr,te)

    def test_earliest_per_person_prevents_repeated_episode_weighting(self):
        doc,tr,te=self.dataset()
        first=predictor.fit_evaluate(doc,tr,te)
        duplicate=copy.deepcopy(doc["observations"][0])
        duplicate["subject"]["episode_key"]="trip:later"
        duplicate["subject"]["conversation_id"]="32"
        for event in duplicate["evidence"]: event["subject"]=dict(duplicate["subject"])
        duplicate["prediction_at"]="2026-10-01T13:00:00Z"
        duplicate["observed_through"]="2026-10-08T13:00:00Z"
        doc["observations"].append(duplicate)
        again=predictor.fit_evaluate(doc,tr,te)
        self.assertEqual(again["train_count"],first["train_count"])
        self.assertEqual(again["baseline_probability"],first["baseline_probability"])

    def test_unsupported_bucket_uses_explicit_baseline_fallback(self):
        doc,tr,te=self.dataset()
        got=predictor.fit_evaluate(doc,tr,te)
        self.assertEqual(got["buckets"][1]["n"],0)
        self.assertEqual(got["buckets"][1]["probability"],got["baseline_probability"])
        self.assertEqual(got["buckets"][1]["reason"],"no_training_support_baseline")

    def test_different_buckets_and_late_messages(self):
        doc,tr,te=self.dataset()
        for idx,row in enumerate(doc["observations"]):
            count=2 if idx in (0,2) else 1
            for n in range(count):
                event=item("q:%s:%s"%(idx,n),"customer_message",row["context_from"])
                event["subject"]=dict(row["subject"])
                row["evidence"].append(event)
        got=predictor.fit_evaluate(doc,tr,te)
        self.assertAlmostEqual(got["buckets"][0]["probability"],1/3)
        self.assertAlmostEqual(got["buckets"][1]["probability"],2/3)
        self.assertLess(got["candidate"]["brier"],got["baseline"]["brier"])
        before=copy.deepcopy(got)
        for row in doc["observations"]:
            late=item("late:"+row["subject"]["customer_key"],"customer_message",row["prediction_at"],row["observed_through"])
            late["subject"]=dict(row["subject"])
            row["evidence"].append(late)
        after=predictor.fit_evaluate(doc,tr,te)
        self.assertEqual(before["candidate"],after["candidate"])
        self.assertEqual(before["feature_fingerprints"],after["feature_fingerprints"])

    def test_predict_uses_real_clock_and_rejects_future_or_training_person(self):
        doc,tr,te=self.dataset()
        model=predictor.fit_evaluate(doc,tr,te)["model"]
        prospect=example("new",True,"2026-10-17T00:00:00Z","2026-10-17T12:00:00Z","2026-10-17T12:00:00Z")
        prospect["evidence"]=[]
        with patch("conversion_predictor.utc_now",return_value="2026-10-17T12:00:01Z"):
            result=predictor.predict(model,{"schema_version":1,"observations":[prospect]})
            self.assertEqual(result["prediction_at"],"2026-10-17T12:00:00Z")
            self.assertIsNone(result["purchase_probability"])
            self.assertFalse(result["export_allowed"])
            self.assertEqual(result["calculated_at"],"2026-10-17T12:00:01Z")
            reused=copy.deepcopy(prospect)
            reused["subject"]["customer_key"]="a"
            with self.assertRaisesRegex(predictor.NotReady,"prediction_not_admissible"):
                predictor.predict(model,{"schema_version":1,"observations":[reused]})
            delayed=item("late","customer_message",prospect["context_from"],"2026-10-17T12:00:01Z")
            delayed["subject"]=dict(prospect["subject"])
            prospect["evidence"]=[delayed]
            with self.assertRaisesRegex(predictor.NotReady,"future_prediction_evidence"):
                predictor.predict(model,{"schema_version":1,"observations":[prospect]})

    def test_model_integrity_readiness_and_cli_failure(self):
        doc,tr,te=self.dataset()
        model=predictor.fit_evaluate(doc,tr,te)["model"]
        model["baseline"]=.9
        with self.assertRaisesRegex(predictor.NotReady,"model_fingerprint_mismatch"):
            predictor.predict(model,doc)
        self.assertFalse(predictor.readiness(doc,tr,tr)["ready"])
        sentinel="PRIVATE TEXT MUST NOT BE ECHOED"
        run=subprocess.run([sys.executable,str(ROOT/"tools"/"conversion_predictor.py")],
                           input=json.dumps({"operation":"wrong","private":sentinel}),
                           text=True,capture_output=True)
        self.assertEqual(run.returncode,2)
        self.assertEqual(run.stdout,"")
        self.assertNotIn(sentinel,run.stderr)

    def test_cli_actual_fit_and_readiness(self):
        doc,tr,te=self.dataset()
        payload={"operation":"fit_evaluate","document":doc,"train_groups":tr,"holdout_groups":te}
        run=subprocess.run([sys.executable,str(ROOT/"tools"/"conversion_predictor.py")],
                           input=json.dumps(payload),text=True,capture_output=True)
        self.assertEqual(run.returncode,0,run.stderr)
        data=json.loads(run.stdout)
        self.assertEqual(data["candidate"]["count"],2)
        self.assertFalse(data["export_allowed"])
        self.assertEqual(data["evaluation_kind"],"retrospective_replay_not_prospective")
        payload["operation"]="readiness";payload["holdout_groups"]=tr
        run=subprocess.run([sys.executable,str(ROOT/"tools"/"conversion_predictor.py")],
                           input=json.dumps(payload),text=True,capture_output=True)
        self.assertEqual(run.returncode,0,run.stderr)
        self.assertFalse(json.loads(run.stdout)["ready"])

    def test_duplicate_raw_message_alias_rejected_for_fit_and_predict(self):
        doc,tr,te=self.dataset()
        event=item("message:first","customer_message",doc["observations"][0]["context_from"])
        event["subject"]=dict(doc["observations"][0]["subject"])
        duplicate=copy.deepcopy(event); duplicate["id"]="message:alias"
        doc["observations"][0]["evidence"] += [event, duplicate]
        with self.assertRaisesRegex(predictor.NotReady,"duplicate_customer_message_source"):
            predictor.fit_evaluate(doc,tr,te)
        clean,tr,te=self.dataset(); model=predictor.fit_evaluate(clean,tr,te)["model"]
        prospect=example("new",True,"2026-10-17T00:00:00Z","2026-10-17T12:00:00Z","2026-10-17T12:00:00Z")
        event=item("message:first","customer_message",prospect["context_from"])
        event["subject"]=dict(prospect["subject"])
        duplicate=copy.deepcopy(event); duplicate["id"]="message:alias"
        prospect["evidence"]=[event,duplicate]
        with self.assertRaisesRegex(predictor.NotReady,"duplicate_customer_message_source"):
            predictor.predict(model,{"schema_version":1,"observations":[prospect]})

    def test_rehashed_incoherent_support_rejected(self):
        doc,tr,te=self.dataset(); model=predictor.fit_evaluate(doc,tr,te)["model"]
        model["baseline"]=.9
        model["buckets"]=[{"n":0,"positives":0,"probability":.9,"reason":"no_training_support_baseline"} for _ in range(2)]
        model["model_sha256"]=predictor.fingerprint({k:v for k,v in model.items() if k!="model_sha256"})
        with self.assertRaisesRegex(predictor.NotReady,"invalid_model_support"):
            predictor.predict(model,doc)
        model=predictor.fit_evaluate(doc,tr,te)["model"]
        model["buckets"][0]["positives"]=0
        model["model_sha256"]=predictor.fingerprint({k:v for k,v in model.items() if k!="model_sha256"})
        with self.assertRaises(predictor.NotReady):
            predictor.predict(model,doc)

if __name__ == "__main__":
    unittest.main(verbosity=2)
