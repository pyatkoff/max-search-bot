#!/usr/bin/env python3
"""Experimental, offline-only seven-day intent predictor; never a purchase model."""
import hashlib
import json
import math
from datetime import datetime, timezone
import build_conversion_observations as base

VERSION = "intent_7d_binary_count_v1"

class NotReady(ValueError):
    pass

def fingerprint(value):
    encoded = json.dumps(value, sort_keys=True, ensure_ascii=False, separators=(",", ":")).encode()
    return hashlib.sha256(encoded).hexdigest()

def bucket(row):
    return int(row["features"]["counts"]["customer_message"] >= 2)

def fit_evaluate(document, train_groups, holdout_groups, calculated_at=None):
    # Raw input is revalidated by the canonical builder, not accepted as asserted features.
    now = calculated_at or datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
    clock = base.moment(now)
    result = base.build_document(document)
    rows = result["observations"]
    train, holdout = set(train_groups), set(holdout_groups)
    if not train or not holdout or train & holdout:
        raise NotReady("group_split_invalid")
    if len(train) != len(train_groups) or len(holdout) != len(holdout_groups):
        raise NotReady("duplicate_group")
    if {r["group_key"] for r in rows} != train | holdout:
        raise NotReady("unassigned_or_missing_group")
    if any(base.moment(r["observed_through"]) > clock for r in rows):
        raise NotReady("future_observation")
    tr = [r for r in rows if r["group_key"] in train and r["eligible"] and r["label"]["value"] is not None]
    te = [r for r in rows if r["group_key"] in holdout and r["eligible"] and r["label"]["value"] is not None]
    if {r["label"]["value"] for r in tr} != {0, 1} or {r["label"]["value"] for r in te} != {0, 1}:
        raise NotReady("both_classes_required_in_each_split")
    if max(base.moment(r["observed_through"]) for r in tr) > min(base.moment(r["prediction_at"]) for r in te):
        raise NotReady("training_outcomes_after_holdout_prediction")
    baseline = (sum(r["label"]["value"] for r in tr) + 1) / (len(tr) + 2)
    probabilities = []
    for b in (0, 1):
        group = [r for r in tr if bucket(r) == b]
        probabilities.append((sum(r["label"]["value"] for r in group) + 1) / (len(group) + 2))
    def metrics(predictions, calibration_probabilities):
        n = len(te)
        labels = [r["label"]["value"] for r in te]
        return {"count": n, "positives": sum(labels),
                "brier": sum((p-y)**2 for p,y in zip(predictions, labels))/n,
                "log_loss": -sum(y*math.log(p)+(1-y)*math.log1p(-p) for p,y in zip(predictions, labels))/n,
                "calibration": [{"bucket": b, "count": sum(bucket(r)==b for r in te),
                                 "observed_positive": sum(r["label"]["value"] for r in te if bucket(r)==b),
                                 "predicted_probability": calibration_probabilities[b]} for b in (0, 1)]}
    return {"version": VERSION, "target": base.TARGET, "purchase_probability": None,
            "export_allowed": False, "calculated_at": now, "input_sha256": fingerprint(document),
            "feature_fingerprints": sorted(r["feature_fingerprint"] for r in rows),
            "unknown_count": sum(r["label"]["value"] is None for r in rows),
            "train_count": len(tr), "baseline_probability": baseline, "bucket_probabilities": probabilities,
            "baseline": metrics([baseline]*len(te), [baseline, baseline]),
            "candidate": metrics([probabilities[bucket(r)] for r in te], probabilities)}
