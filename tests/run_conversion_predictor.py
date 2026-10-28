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

if __name__ == "__main__":
    unittest.main(verbosity=2)
