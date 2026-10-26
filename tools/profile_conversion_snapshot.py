#!/usr/bin/env python3
"""Profile an already authorized canonical snapshot; never read a live source."""
import argparse
import json
import re
import sys

import build_conversion_observations as base
import extract_conversion_profiles as extractor

VERSION = "canonical_snapshot_profile_v1"
CLOCK = "database_persistence_normalized_to_utc_not_provider_occurrence"
SUBJECT = base.SCOPE_FIELDS[:-1]


def scope(value):
    base.fields(value, SUBJECT)
    return {k: base.subject({**value, "episode_key": "validation"})[k] for k in SUBJECT}


def normalize(document):
    base.fields(document, ("schema_version", "received_at", "extracted_at", "snapshot", "trips"))
    base.require(type(document["schema_version"]) is int and document["schema_version"] == 1, "unsupported_schema")
    received, extracted = map(base.moment, (document["received_at"], document["extracted_at"]))
    snapshot = document["snapshot"]
    base.fields(snapshot, ("schema_version", "captured_at", "database_writes", "conversion_uploads", "cases"),
                ("database_zone", "database_offset_seconds", "scope_cutoff_epoch", "lookback_days",
                 "candidate_count", "candidate_cap", "case_cap", "message_cap", "skipped"))
    base.require(type(snapshot["schema_version"]) is int and snapshot["schema_version"] == 1, "unsupported_snapshot_schema")
    captured = base.moment(snapshot["captured_at"])
    base.require(captured <= received <= extracted, "invalid_receipt_chronology")
    base.require(snapshot["database_writes"] is False and type(snapshot["conversion_uploads"]) is int
                 and snapshot["conversion_uploads"] == 0, "read_only_source_required")
    base.require(isinstance(snapshot["cases"], list) and len(snapshot["cases"]) <= extractor.MAX_CASES, "case_limit")
    base.require(isinstance(document["trips"], list) and len(document["trips"]) == len(snapshot["cases"]), "trip_mapping_required")
    trips = {}
    for trip in document["trips"]:
        base.fields(trip, ("subject", "brand_key", "trip_start_message_id"))
        identity = base.digest(scope(trip["subject"]))
        base.key(trip["brand_key"]); base.key(trip["trip_start_message_id"])
        base.require(identity not in trips, "duplicate_trip_mapping")
        trips[identity] = trip
    cases = []; seen = set()
    for source in snapshot["cases"]:
        base.fields(source, ("subject", "started_at", "messages_complete", "events_complete", "messages", "tour_choices", "source_clock"), ("events",))
        subject = scope(source["subject"]); identity = base.digest(subject)
        base.require(identity not in seen, "duplicate_source_subject")
        base.require(identity in trips, "trip_scope_mismatch"); seen.add(identity)
        base.require(source["source_clock"] == CLOCK, "unverified_source_clock")
        started = base.moment(source["started_at"])
        base.require(started <= captured, "source_start_after_capture")
        for field in ("messages_complete", "events_complete"):
            base.require(type(source[field]) is bool, "explicit_source_completeness_required")
        choices = source["tour_choices"]
        base.require(isinstance(choices, dict) and type(choices.get("available")) is bool, "explicit_choice_availability_required")
        if choices["available"]:
            base.require(type(choices.get("limited")) is bool, "explicit_choice_limit_required")
            base.require(isinstance(choices.get("offers"), list)
                         and len(choices["offers"]) <= extractor.MAX_MESSAGES, "choice_rows_required")
        rows = source["messages"]
        base.require(isinstance(rows, list) and len(rows) <= extractor.MAX_MESSAGES, "message_limit")
        normalized = []; unique = {}
        for row in rows:
            base.fields(row, ("id", "direction", "sender_type", "text", "recorded_at", "type", "metadata_revision"))
            base.key(row["id"])
            base.require(isinstance(row["direction"], str) and row["direction"] in {"inbound", "outbound"}, "invalid_source_direction")
            for key in ("sender_type", "type"):
                base.require(isinstance(row[key], str) and bool(re.fullmatch(r"[a-z_]{1,40}", row[key])), "invalid_source_role_or_type")
            base.require(isinstance(row["text"], str) and len(row["text"]) <= extractor.MAX_TEXT, "text_limit")
            base.require(isinstance(row["metadata_revision"], str) and bool(re.fullmatch(r"[a-f0-9]{64}", row["metadata_revision"])), "source_revision_required")
            at = base.moment(row["recorded_at"])
            base.require(started <= at <= captured, "source_row_outside_capture")
            base.require(row["id"] not in unique or unique[row["id"]] == row, "conflicting_message_id")
            if row["id"] in unique:
                continue
            unique[row["id"]] = row
            speaker = "other"
            if row["sender_type"] == "customer" and row["direction"] == "inbound":
                speaker = "customer"
            elif row["sender_type"] == "manager" and row["direction"] == "outbound":
                speaker = "manager"
            normalized.append({"id": row["id"], "speaker": speaker,
                               "kind": "text" if row["type"] == "message" else "action", "text": row["text"],
                               "at": row["recorded_at"], "available_at": document["received_at"]})
        trip = trips[identity]
        start = next((r for r in normalized if r["id"] == trip["trip_start_message_id"]), None)
        base.require(start is not None and start["speaker"] == "customer" and start["kind"] == "text"
                     and bool(start["text"].strip()), "current_trip_customer_anchor_required")
        complete = (source["messages_complete"] and source["events_complete"]
                    and choices["available"] and choices.get("limited") is False
                    and choices.get("offers") == [] and captured == extracted)
        cases.append({"subject": {**subject, "episode_key": "trip-message:"+start["id"]},
                      "brand_key": trip["brand_key"], "context_from": start["at"],
                      "prediction_at": document["extracted_at"], "source_complete_through": snapshot["captured_at"],
                      "prefix_complete": complete, "messages": normalized})
    base.require(seen == set(trips), "unused_trip_mapping")
    return {"schema_version": 1, "extracted_at": document["extracted_at"], "cases": cases}


def profile(document):
    normalized = normalize(document)
    result = extractor.extract_document(normalized)
    captured = base.moment(document["snapshot"]["captured_at"])
    received = base.moment(document["received_at"])
    extracted = base.moment(document["extracted_at"])
    return {**result, "adapter_version": VERSION, "source_receipt": {
        "input_sha256": base.digest(document), "captured_at": document["snapshot"]["captured_at"],
        "received_at": document["received_at"], "extracted_at": document["extracted_at"],
        "source_clock": CLOCK, "receipt_lag_seconds": int((received-captured).total_seconds()),
        "source_lag_at_prediction_seconds": int((extracted-captured).total_seconds()),
        "case_count": len(normalized["cases"]), "live_source_reads": 0, "model_calls": 0,
        "database_writes": False, "conversion_uploads": 0}}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("input", nargs="?", default="-")
    args = parser.parse_args()
    try:
        if args.input == "-":
            raw = sys.stdin.buffer.read(extractor.MAX_BYTES+1)
        else:
            with open(args.input, "rb") as handle:
                raw = handle.read(extractor.MAX_BYTES+1)
        base.require(len(raw) <= extractor.MAX_BYTES, "input_byte_limit")
        result = profile(json.loads(raw.decode("utf-8"), object_pairs_hook=base.unique_object))
    except (base.InvalidObservation, OSError, UnicodeDecodeError, json.JSONDecodeError, RecursionError) as exc:
        reason = str(exc) if isinstance(exc, base.InvalidObservation) else "input_unavailable_or_invalid_json"
        print(json.dumps({"ok": False, "error": reason}), file=sys.stderr)
        return 2
    print(json.dumps(result, ensure_ascii=True, sort_keys=True, separators=(",", ":")))
    return 0


if __name__ == "__main__":
    sys.exit(main())
