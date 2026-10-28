#!/usr/bin/env python3
"""Experimental, offline-only seven-day intent predictor; never a purchase model."""
import hashlib
import json
import math
import sys
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

def utc_now():
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")

def fit_evaluate(document, train_groups, holdout_groups):
    # Raw input is revalidated by the canonical builder, not accepted as asserted features.
    now = utc_now()
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
    # Freeze one earliest observation per person before inspecting labels.
    selected = {}
    for row in rows:
        group = row["group_key"]
        if group not in selected or (row["prediction_at"], row["observation_id"]) < (selected[group]["prediction_at"], selected[group]["observation_id"]):
            selected[group] = row
    rows = list(selected.values())
    tr = [r for r in rows if r["group_key"] in train and r["eligible"] and r["label"]["value"] is not None]
    te = [r for r in rows if r["group_key"] in holdout and r["eligible"] and r["label"]["value"] is not None]
    if {r["label"]["value"] for r in tr} != {0, 1} or {r["label"]["value"] for r in te} != {0, 1}:
        raise NotReady("both_classes_required_in_each_split")
    if max(base.moment(r["observed_through"]) for r in tr) > min(base.moment(r["prediction_at"]) for r in te):
        raise NotReady("training_outcomes_after_holdout_prediction")
    baseline = (sum(r["label"]["value"] for r in tr) + 1) / (len(tr) + 2)
    probabilities = []
    buckets = []
    for b in (0, 1):
        group = [r for r in tr if bucket(r) == b]
        p = (sum(r["label"]["value"] for r in group) + 1) / (len(group) + 2) if group else baseline
        probabilities.append(p)
        buckets.append({"n":len(group), "probability":p,
                        "reason":"smoothed_training" if group else "no_training_support_baseline"})
    def metrics(predictions, calibration_probabilities):
        n = len(te)
        labels = [r["label"]["value"] for r in te]
        return {"count": n, "positives": sum(labels),
                "brier": sum((p-y)**2 for p,y in zip(predictions, labels))/n,
                "log_loss": -sum(y*math.log(p)+(1-y)*math.log1p(-p) for p,y in zip(predictions, labels))/n,
                "calibration": [{"bucket": b, "count": sum(bucket(r)==b for r in te),
                                 "observed_positive": sum(r["label"]["value"] for r in te if bucket(r)==b),
                                 "predicted_probability": calibration_probabilities[b]} for b in (0, 1)]}
    model = {"version":VERSION, "target":base.TARGET, "fitted_at":now,
             "training_through":max(r["observed_through"] for r in tr),
             "training_groups":sorted(train), "training_input_sha256":fingerprint(tr),
             "baseline":baseline, "buckets":buckets, "export_allowed":False}
    model["model_sha256"] = fingerprint(model)
    return {"version": VERSION, "target": base.TARGET, "purchase_probability": None,
            "export_allowed": False, "calculated_at": now, "input_sha256": fingerprint(document),
            "feature_fingerprints": sorted(r["feature_fingerprint"] for r in rows),
            "unknown_count": sum(r["label"]["value"] is None for r in rows),
            "train_count": len(tr), "baseline_probability": baseline, "bucket_probabilities": probabilities,
            "buckets": buckets, "unit":"person_earliest_observation",
            "evaluation_kind":"retrospective_replay_not_prospective", "model":model,
            "baseline": metrics([baseline]*len(te), [baseline, baseline]),
            "candidate": metrics([probabilities[bucket(r)] for r in te], probabilities)}

def predict(model, document):
    required={"version","target","fitted_at","training_through","training_groups",
              "training_input_sha256","baseline","buckets","export_allowed","model_sha256"}
    if not isinstance(model,dict) or set(model)!=required or model["version"]!=VERSION or model["target"]!=base.TARGET or model["export_allowed"] is not False:
        raise NotReady("invalid_model")
    payload={k:v for k,v in model.items() if k!="model_sha256"}
    if fingerprint(payload)!=model["model_sha256"]:
        raise NotReady("model_fingerprint_mismatch")
    def valid_probability(value):
        return type(value) in (float,int) and math.isfinite(value) and 0 < value < 1
    if (not valid_probability(model["baseline"]) or not isinstance(model["training_groups"],list)
        or not model["training_groups"] or len(set(model["training_groups"]))!=len(model["training_groups"])
        or not all(isinstance(g,str) and len(g)==64 for g in model["training_groups"])
        or not isinstance(model["buckets"],list) or len(model["buckets"])!=2):
        raise NotReady("invalid_model_fields")
    for entry in model["buckets"]:
        if (not isinstance(entry,dict) or set(entry)!={"n","probability","reason"}
            or type(entry["n"]) is not int or entry["n"]<0 or not valid_probability(entry["probability"])
            or entry["reason"] != ("smoothed_training" if entry["n"] else "no_training_support_baseline")
            or (entry["n"]==0 and entry["probability"]!=model["baseline"])):
            raise NotReady("invalid_model_bucket")
    rows=base.build_document(document)["observations"]
    if len(rows)!=1:
        raise NotReady("one_observation_required")
    row=rows[0]
    now=utc_now()
    if base.moment(model["training_through"]) > base.moment(model["fitted_at"]) or base.moment(model["fitted_at"]) > base.moment(now):
        raise NotReady("invalid_model_clock")
    for event in document["observations"][0]["evidence"]:
        if base.moment(event["at"]) > base.moment(row["prediction_at"]) or base.moment(event["available_at"]) > base.moment(row["prediction_at"]):
            raise NotReady("future_prediction_evidence")
    if (not row["eligible"] or row["group_key"] in model["training_groups"]
        or base.moment(row["prediction_at"]) < base.moment(model["fitted_at"])
        or base.moment(row["observed_through"]) != base.moment(row["prediction_at"])
        or base.moment(row["prediction_at"]) > base.moment(now)):
        raise NotReady("prediction_not_admissible")
    chosen=model["buckets"][bucket(row)]
    return {"target":base.TARGET, "calculated_at":now, "prediction_at":row["prediction_at"],
            "feature_fingerprint":row["feature_fingerprint"], "model_sha256":model["model_sha256"],
            "proxy_probability":chosen["probability"], "support_n":chosen["n"],
            "support_reason":chosen["reason"], "purchase_probability":None, "export_allowed":False}

def readiness(document, train_groups, holdout_groups):
    try:
        result=fit_evaluate(document,train_groups,holdout_groups)
        return {"ready":True, "reason":"offline_research_only", "train_count":result["train_count"],
                "holdout_count":result["candidate"]["count"], "unknown_count":result["unknown_count"],
                "export_allowed":False}
    except (NotReady,base.InvalidObservation) as exc:
        return {"ready":False, "reason":str(exc), "export_allowed":False}

def main():
    try:
        raw=sys.stdin.buffer.read(base.MAX_BYTES+1)
        if len(raw)>base.MAX_BYTES:
            raise NotReady("input_byte_limit")
        data=json.loads(raw.decode("utf-8"),object_pairs_hook=base.unique_object)
        if not isinstance(data,dict) or data.get("operation") not in ("fit_evaluate","readiness","predict"):
            raise NotReady("invalid_operation")
        if data["operation"]=="predict" and set(data)=={"operation","model","document"}:
            out=predict(data["model"],data["document"])
        elif data["operation"] in ("fit_evaluate","readiness") and set(data)=={"operation","document","train_groups","holdout_groups"}:
            out=(fit_evaluate if data["operation"]=="fit_evaluate" else readiness)(data["document"],data["train_groups"],data["holdout_groups"])
        else:
            raise NotReady("invalid_fields")
        print(json.dumps(out,allow_nan=False,sort_keys=True,separators=(",",":")))
    except (NotReady,base.InvalidObservation,ValueError,TypeError,KeyError,UnicodeDecodeError):
        print(json.dumps({"ok":False,"error":"invalid_or_not_ready"}),file=sys.stderr)
        return 2
    return 0

if __name__=="__main__":
    raise SystemExit(main())
