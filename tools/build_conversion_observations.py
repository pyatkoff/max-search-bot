#!/usr/bin/env python3
"""Build private, offline model observations; never call a model or export goals."""

import argparse
import hashlib
import json
import re
import sys
from datetime import datetime, timedelta, timezone


TARGET = "option_selection_or_booking_intent_7d_v1"
WINDOW = timedelta(days=7)
MAX_BYTES = 2 * 1024 * 1024
MAX_OBSERVATIONS = 300
MAX_EVIDENCE = 500
SCOPE_FIELDS = ("project_key", "platform", "customer_key", "conversation_id", "episode_key")
RAW_KINDS = {"customer_message", "manager_message", "offer_sent"}
STAGES = {"request", "clarifying", "comparing", "offer_selected", "booking_intent",
          "booking_confirmed", "paid", "declined", "purchased_elsewhere"}
PROGRESS = {"offer_selected", "booking_intent", "booking_confirmed", "paid"}
NEGATIVE = {"declined", "purchased_elsewhere"}


class InvalidObservation(ValueError):
    pass


def require(condition, reason):
    if not condition:
        raise InvalidObservation(reason)


def fields(value, required, optional=()):
    require(isinstance(value, dict), "object_required")
    require(set(required) <= value.keys() and value.keys() <= set(required) | set(optional),
            "invalid_fields")


def key(value):
    require(isinstance(value, str) and re.fullmatch(r"[A-Za-z0-9._:-]{1,128}", value),
            "invalid_key")
    return value


def moment(value):
    require(isinstance(value, str) and re.fullmatch(r"\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z", value),
            "utc_seconds_required")
    try:
        return datetime.strptime(value, "%Y-%m-%dT%H:%M:%SZ").replace(tzinfo=timezone.utc)
    except ValueError:
        raise InvalidObservation("invalid_time") from None


def stamp(value):
    return value.isoformat(timespec="seconds").replace("+00:00", "Z")


def digest(value):
    wire = json.dumps(value, sort_keys=True, separators=(",", ":"), ensure_ascii=True)
    return hashlib.sha256(wire.encode("utf-8")).hexdigest()


def subject(value):
    fields(value, SCOPE_FIELDS)
    result = {name: key(value[name]) for name in SCOPE_FIELDS}
    require(result["platform"] in {"max", "telegram"}, "unsupported_platform")
    return result


def reference(value):
    fields(value, ("source", "id", "at", "available_at"))
    require(isinstance(value["source"], str) and value["source"] in {"message", "conversation_event", "selection_action"},
            "unsupported_evidence_source")
    key(value["id"])
    at, available = moment(value["at"]), moment(value["available_at"])
    require(available >= at, "source_available_before_occurrence")
    return at, available


def evidence(value, scope):
    fields(value, ("id", "subject", "kind", "at", "available_at", "confidence", "references"))
    key(value["id"])
    require(subject(value["subject"]) == scope, "cross_subject_evidence")
    require(isinstance(value["kind"], str) and value["kind"] in RAW_KINDS | STAGES, "unsupported_kind")
    require(isinstance(value["confidence"], str) and value["confidence"] in {"high", "medium", "low"}, "invalid_confidence")
    at, available = moment(value["at"]), moment(value["available_at"])
    require(available >= at, "available_before_occurrence")
    refs = value["references"]
    require(isinstance(refs, list) and 1 <= len(refs) <= 20, "evidence_references_required")
    seen = set()
    source_times = []
    for ref in refs:
        ref_at, ref_available = reference(ref)
        require(ref_at <= at and ref_available <= available, "future_source_reference")
        source_times.append(ref_at)
        ref_key = (ref["source"], ref["id"])
        require(ref_key not in seen, "duplicate_source_reference")
        seen.add(ref_key)
    require(at == max(source_times), "occurrence_must_match_latest_source")
    if value["kind"] in RAW_KINDS:
        require(value["confidence"] == "high", "canonical_event_confidence_required")
    normalized = {**value, "subject": scope,
                  "references": sorted(refs, key=lambda ref: (ref["source"], ref["id"]))}
    return normalized, at, available


def advertising_link(value, scope, cutoff):
    if value is None:
        return {"status": "missing", "entry_key": None}
    fields(value, ("id", "project_key", "platform", "customer_key", "source_id", "counter_id",
                   "at", "available_at", "verification"))
    for name in ("id", "source_id", "counter_id"):
        key(value[name])
    require(all(value[name] == scope[name] for name in ("project_key", "platform", "customer_key")),
            "cross_subject_advertising_link")
    at, available = moment(value["at"]), moment(value["available_at"])
    require(available >= at, "advertising_available_before_occurrence")
    require(isinstance(value["verification"], str) and value["verification"] in {"verified", "unverified"}, "invalid_verification")
    if at > cutoff or available > cutoff:
        return {"status": "missing", "entry_key": None}
    if value["verification"] != "verified":
        return {"status": "unverified", "entry_key": None}
    # This consumes a producer's verification assertion; it does not authenticate it.
    identity = {name: value[name] for name in
                ("id", "project_key", "platform", "customer_key", "source_id", "counter_id")}
    return {"status": "verified_by_producer", "entry_key": digest(identity)}


def build_observation(value):
    fields(value, ("subject", "context_from", "prediction_at", "observed_through",
                   "prefix_complete", "followup_complete", "evidence"), ("advertising_entry",))
    scope = subject(value["subject"])
    start = moment(value["context_from"])
    cutoff = moment(value["prediction_at"])
    through = moment(value["observed_through"])
    require(start <= cutoff <= through, "invalid_observation_interval")
    require(type(value["prefix_complete"]) is bool and type(value["followup_complete"]) is bool,
            "explicit_completeness_required")
    rows = value["evidence"]
    require(isinstance(rows, list) and len(rows) <= MAX_EVIDENCE, "evidence_limit")
    unique = {}
    for raw in rows:
        normalized, at, available = evidence(raw, scope)
        if at >= start:
            require(all(moment(ref["at"]) >= start for ref in normalized["references"]),
                    "source_precedes_current_trip")
        if normalized["id"] in unique:
            require(unique[normalized["id"]][0] == normalized, "conflicting_evidence_id")
        else:
            unique[normalized["id"]] = (normalized, at, available)
    # Validate every row first, including rows outside either time window.
    ordered = sorted(unique.values(), key=lambda row: (row[1], row[2], row[0]["id"]))
    prefix = [row for row in ordered if start <= row[1] <= cutoff and row[2] <= cutoff]
    known = [row for row in prefix if row[0]["kind"] in STAGES and row[0]["confidence"] == "high"]
    latest = [row for row in known if row[1] == known[-1][1]] if known else []
    latest_kinds = {row[0]["kind"] for row in latest}
    current_stage = next(iter(latest_kinds)) if len(latest_kinds) == 1 else "unknown"
    customer_times = [row[1] for row in prefix if row[0]["kind"] == "customer_message"]
    features = {
        "prefix_complete": value["prefix_complete"],
        "counts": {kind: sum(row[0]["kind"] == kind for row in prefix) for kind in sorted(RAW_KINDS)},
        "current_stage": current_stage,
        "stage_evidence_ids": sorted(row[0]["id"] for row in latest),
        "uncertain_stage_count": sum(row[0]["kind"] in STAGES and row[0]["confidence"] != "high"
                                     for row in prefix),
        "seconds_since_customer_message": int((cutoff - max(customer_times)).total_seconds())
            if customer_times else None,
        "advertising": advertising_link(value.get("advertising_entry"), scope, cutoff),
    }
    already_progressed = any(row[0]["kind"] in PROGRESS for row in known)
    eligible = value["prefix_complete"] and not already_progressed
    try:
        deadline = cutoff + WINDOW
    except OverflowError:
        raise InvalidObservation("invalid_observation_interval") from None
    future = [row for row in ordered if cutoff < row[1] <= deadline and row[2] <= through
              and row[0]["confidence"] == "high" and row[0]["kind"] in STAGES]
    positives = [row for row in future if row[0]["kind"] in PROGRESS]
    negatives = [row for row in future if row[0]["kind"] in NEGATIVE]
    label = {"value": None, "reason": "unknown", "evidence_ids": []}
    if not eligible:
        label["reason"] = "incomplete_prefix" if not value["prefix_complete"] else "target_already_reached"
    elif positives:
        label = {"value": 1, "reason": "observed_progress", "evidence_ids": [positives[0][0]["id"]]}
    elif through < deadline:
        label["reason"] = "window_not_mature"
    elif not value["followup_complete"]:
        label["reason"] = "incomplete_followup"
    elif negatives:
        label = {"value": 0, "reason": "explicit_terminal_without_progress",
                 "evidence_ids": [negatives[-1][0]["id"]]}
    else:
        label["reason"] = "no_confirmed_outcome"
    identity = {"subject": scope, "context_from": stamp(start), "prediction_at": stamp(cutoff),
                "target_version": TARGET}
    return {
        "observation_id": digest(identity),
        # A canonical person key groups their conversations and trips, including linked platforms.
        "group_key": digest({"project_key": scope["project_key"], "customer_key": scope["customer_key"]}),
        "project_key": scope["project_key"], "platform": scope["platform"],
        "prediction_at": stamp(cutoff), "window_ends_at": stamp(deadline),
        "observed_through": stamp(through), "eligible": eligible,
        "features": features,
        "feature_fingerprint": digest({"identity": identity, "features": features,
                                       "prefix": [row[0] for row in prefix]}),
        "label": label,
        "evidence_ids_at_prediction": [row[0]["id"] for row in prefix],
    }


def build_document(value):
    fields(value, ("schema_version", "observations"))
    require(type(value["schema_version"]) is int and value["schema_version"] == 1,
            "unsupported_schema")
    require(isinstance(value["observations"], list) and len(value["observations"]) <= MAX_OBSERVATIONS,
            "observation_limit")
    rows = [build_observation(row) for row in value["observations"]]
    require(len({row["observation_id"] for row in rows}) == len(rows), "duplicate_observation")
    return {"schema_version": 1, "target_version": TARGET, "mode": "shadow_only",
            "probability": None, "export_allowed": False, "observations": rows}


def unique_object(pairs):
    result = {}
    for name, value in pairs:
        require(name not in result, "duplicate_json_key")
        result[name] = value
    return result


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("input", nargs="?", default="-", help="private JSON snapshot, or stdin")
    args = parser.parse_args()
    try:
        if args.input == "-":
            raw = sys.stdin.buffer.read(MAX_BYTES + 1)
        else:
            with open(args.input, "rb") as handle:
                raw = handle.read(MAX_BYTES + 1)
        require(len(raw) <= MAX_BYTES, "input_byte_limit")
        result = build_document(json.loads(raw.decode("utf-8"), object_pairs_hook=unique_object))
    except (InvalidObservation, json.JSONDecodeError, UnicodeDecodeError, OSError, RecursionError) as exc:
        reason = str(exc) if isinstance(exc, InvalidObservation) else "input_unavailable_or_invalid_json"
        print(json.dumps({"ok": False, "error": reason}), file=sys.stderr)
        return 2
    # Serialize only after all observations pass; never emit a partial dataset on failure.
    print(json.dumps(result, sort_keys=True, ensure_ascii=True, separators=(",", ":")))
    return 0


if __name__ == "__main__":
    sys.exit(main())
