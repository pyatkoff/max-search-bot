#!/usr/bin/env python3
"""Synthetic checks of timing, grouping and rejection; not buyer accuracy."""
import copy
import importlib.util
from pathlib import Path
import sys
import unittest

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
        got = predictor.fit_evaluate(doc,tr,te,"2026-10-17T00:00:00Z")
        self.assertEqual(got["baseline"]["count"],2)
        self.assertEqual(got["candidate"]["positives"],1)
        self.assertAlmostEqual(got["candidate"]["brier"],.25)
        self.assertAlmostEqual(got["candidate"]["log_loss"],__import__("math").log(2))
        self.assertFalse(got["export_allowed"])
        self.assertIsNone(got["purchase_probability"])

    def test_overlap_duplicate_future_and_unknown_rejected(self):
        doc,tr,te=self.dataset()
        with self.assertRaisesRegex(predictor.NotReady,"group_split_invalid"):
            predictor.fit_evaluate(doc,tr,te+[tr[0]],"2026-10-17T00:00:00Z")
        with self.assertRaisesRegex(base.InvalidObservation,"duplicate_observation"):
            predictor.fit_evaluate({"schema_version":1,"observations":doc["observations"]+[copy.deepcopy(doc["observations"][0])]},tr,te,"2026-10-17T00:00:00Z")
        with self.assertRaisesRegex(predictor.NotReady,"future_observation"):
            predictor.fit_evaluate(doc,tr,te,"2026-10-10T00:00:00Z")
        unknown=copy.deepcopy(doc)
        for row in unknown["observations"]: row["evidence"]=[]
        with self.assertRaisesRegex(predictor.NotReady,"both_classes_required"):
            predictor.fit_evaluate(unknown,tr,te,"2026-10-17T00:00:00Z")

    def test_one_class_and_temporal_leak_rejected(self):
        doc,tr,te=self.dataset()
        one=copy.deepcopy(doc)
        one["observations"][1]["evidence"][0]["kind"]="booking_intent"
        with self.assertRaisesRegex(predictor.NotReady,"both_classes_required"):
            predictor.fit_evaluate(one,tr,te,"2026-10-17T00:00:00Z")
        leak=copy.deepcopy(doc)
        leak["observations"][0]["observed_through"]="2026-10-10T12:00:00Z"
        with self.assertRaisesRegex(predictor.NotReady,"training_outcomes_after_holdout_prediction"):
            predictor.fit_evaluate(leak,tr,te,"2026-10-17T00:00:00Z")

    def test_earliest_per_person_prevents_repeated_episode_weighting(self):
        doc,tr,te=self.dataset()
        first=predictor.fit_evaluate(doc,tr,te,"2026-10-17T00:00:00Z")
        duplicate=copy.deepcopy(doc["observations"][0])
        duplicate["subject"]["episode_key"]="trip:later"
        duplicate["subject"]["conversation_id"]="32"
        for event in duplicate["evidence"]: event["subject"]=dict(duplicate["subject"])
        duplicate["prediction_at"]="2026-10-01T13:00:00Z"
        duplicate["observed_through"]="2026-10-08T13:00:00Z"
        doc["observations"].append(duplicate)
        again=predictor.fit_evaluate(doc,tr,te,"2026-10-17T00:00:00Z")
        self.assertEqual(again["train_count"],first["train_count"])
        self.assertEqual(again["baseline_probability"],first["baseline_probability"])

    def test_unsupported_bucket_uses_explicit_baseline_fallback(self):
        doc,tr,te=self.dataset()
        got=predictor.fit_evaluate(doc,tr,te,"2026-10-17T00:00:00Z")
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
        got=predictor.fit_evaluate(doc,tr,te,"2026-10-17T00:00:00Z")
        self.assertAlmostEqual(got["buckets"][0]["probability"],1/3)
        self.assertAlmostEqual(got["buckets"][1]["probability"],2/3)
        self.assertLess(got["candidate"]["brier"],got["baseline"]["brier"])
        before=copy.deepcopy(got)
        for row in doc["observations"]:
            late=item("late:"+row["subject"]["customer_key"],"customer_message",row["prediction_at"],row["observed_through"])
            late["subject"]=dict(row["subject"])
            row["evidence"].append(late)
        after=predictor.fit_evaluate(doc,tr,te,"2026-10-17T00:00:00Z")
        self.assertEqual(before["candidate"],after["candidate"])
        self.assertEqual(before["feature_fingerprints"],after["feature_fingerprints"])

if __name__ == "__main__":
    unittest.main(verbosity=2)
