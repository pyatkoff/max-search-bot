#!/usr/bin/env python3
"""Transform admitted private journal snapshots; no source/DB/network/model access."""
import argparse
from datetime import datetime, timezone
import hashlib
import json
import re
from pathlib import Path
import sys

import build_conversion_observations as base
import extract_conversion_profiles as extractor

VERSION = "admitted_journal_profile_v1"
MAX_BYTES = 2 * 1024 * 1024
ADAPTER_SHA = hashlib.sha256(Path(__file__).read_bytes()).hexdigest()


def epoch(value):
    base.require(type(value) is int and 946684800 <= value <= 4102444800, "invalid_journal_clock")
    return datetime.fromtimestamp(value, timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def profile(document, extracted_at):
    base.fields(document, ("schema_version", "requested_at", "journal_cursor", "cases"))
    base.require(document["schema_version"] == 1 and type(document["schema_version"]) is int, "unsupported_schema")
    requested = base.moment(document["requested_at"])
    base.require(requested <= base.moment(extracted_at), "extraction_before_request")
    base.key(document["journal_cursor"])
    base.require(isinstance(document["cases"], list) and len(document["cases"]) <= 4, "profile_case_limit")
    output = []; seen = set()
    for case in document["cases"]:
        base.fields(case, ("subject", "rows", "window_limited", "access_denied"))
        base.fields(case["subject"], base.SCOPE_FIELDS[:-1])
        validated = base.subject({**case["subject"], "episode_key": "validation"})
        scope = {**validated, "episode_key": "unresolved-prefix:"+validated["conversation_id"]}
        base.require(scope["project_key"] == "anytour", "foreign_project")
        identity = base.digest(case["subject"])
        base.require(identity not in seen, "duplicate_profile_scope"); seen.add(identity)
        base.require(type(case["window_limited"]) is bool and type(case["access_denied"]) is bool, "explicit_coverage_required")
        base.require(isinstance(case["rows"], list) and len(case["rows"]) <= 50, "profile_window_limit")
        messages = []; refs = []; errors = {}; unique = set()

        def omitted(reason):
            errors[reason] = errors.get(reason, 0)+1

        for row in case["rows"]:
            base.fields(row, ("journal_id", "source_kind", "source_id", "source_version_no", "observed_at_unix", "snapshot"))
            for key in ("journal_id", "source_id"):
                base.require(isinstance(row[key], str) and re.fullmatch(r"[1-9][0-9]{0,19}", row[key]), "invalid_journal_identity")
            base.require(row["source_kind"] in {"message", "event"}, "invalid_journal_kind")
            base.require(row["journal_id"] not in unique, "duplicate_journal_revision"); unique.add(row["journal_id"])
            base.require(type(row["source_version_no"]) is int and row["source_version_no"] >= 1, "invalid_source_revision")
            if row["source_version_no"] > 1:
                omitted("source_revision_chronology_unresolved")
            received = epoch(row["observed_at_unix"])
            base.require(base.moment(received) <= requested, "future_journal_receipt")
            snap = row["snapshot"]
            base.require(isinstance(snap, dict) and snap.get("source_kind") == row["source_kind"]
                         and str(snap.get("source_id")) == str(row["source_id"]), "journal_source_mismatch")
            status = snap.get("source_status")
            base.require(status in {"present", "deleted", "scope_excluded"}, "invalid_source_status")
            refs.append({"journal_id": row["journal_id"], "source_kind": row["source_kind"],
                         "source_id": str(row["source_id"]), "source_version_no": row["source_version_no"], "observed_at": received,
                         "snapshot_sha256": base.digest(snap), "source_status": status})
            if status != "present":
                omitted(status); continue
            base.require(snap.get("project_key") == scope["project_key"] and snap.get("platform") == scope["platform"]
                         and str(snap.get("conversation_id")) == scope["conversation_id"]
                         and str(snap.get("customer_key")) == scope["customer_key"], "journal_scope_mismatch")
            if case["access_denied"]:
                omitted("scope_denied"); continue
            if row["source_kind"] != "message":
                omitted("uninterpreted_event"); continue
            if snap.get("payload_status") != "copied":
                omitted("payload_unavailable"); continue
            recorded = snap.get("source_created_at_unix")
            if (snap.get("source_timestamp_basis") != "database_session_timezone_to_unix"
                    or type(recorded) is not int or not 946684800 <= recorded <= row["observed_at_unix"]):
                omitted("source_clock_unresolved"); continue
            payload = snap.get("payload")
            base.require(isinstance(payload, dict), "invalid_message_payload")
            text = payload.get("text")
            if not isinstance(text, str) or len(text) > extractor.MAX_TEXT:
                omitted("text_unavailable_or_oversize"); continue
            try:
                metadata = json.loads(payload.get("metadata_json") or "{}", object_pairs_hook=base.unique_object)
                if metadata == []:
                    metadata = {}  # Canonical outbound PHP writer uses an empty array.
                if not isinstance(metadata, dict):
                    raise ValueError()
            except (ValueError, TypeError):
                omitted("invalid_message_metadata"); continue
            speaker = "other"
            if payload.get("direction") == "inbound" and payload.get("sender_type") == "customer":
                speaker = "customer"
            elif payload.get("direction") == "outbound" and payload.get("sender_type") == "manager":
                speaker = "manager"
            linked = metadata.get("linked_message")
            if isinstance(linked, dict) and linked.get("type") == "forward":
                speaker = "other"
                omitted("forwarded_statement_owner_unresolved")
            kind = metadata.get("type")
            # Canonical outboundForConversation writes ordinary manager text without type.
            is_text = kind == "message" or (speaker == "manager" and kind is None)
            if not is_text:
                omitted("action_or_unknown_message_type")
            messages.append({"id": "journal:"+str(row["journal_id"]), "speaker": speaker,
                             "kind": "text" if is_text else "action", "text": text,
                             "at": epoch(recorded), "available_at": received})
        fence = max((r["observed_at"] for r in refs), default=document["requested_at"])
        start = min((m["at"] for m in messages), default=fence)
        profile_case = {"subject": scope, "brand_key": "anytour", "context_from": start,
                        "prediction_at": extracted_at, "source_complete_through": None,
                        "source_fence_at": fence, "prefix_complete": False, "trip_status": "unknown", "messages": messages}
        result = extractor.extract_case(profile_case, extracted_at)
        # A denied current scope has no usable facts, even when older snapshots still exist.
        base.require(not case["access_denied"] or not result["prediction_features"]["facts"], "denied_scope_facts")
        output.append({"subject": case["subject"], "profile": result, "source_references": refs,
                       "adapter": {"version": VERSION, "sha256": ADAPTER_SHA},
                       "journal_cursor": document["journal_cursor"],
                       "access_status": "denied" if case["access_denied"] else "allowed",
                       "coverage": {"source_history_complete": False, "trip_status": "unknown",
                                    "window_limited": case["window_limited"], "errors": errors}})
    return {"schema_version": 1, "adapter_version": VERSION, "adapter_sha256": ADAPTER_SHA,
            "extractor_sha256": extractor.RULE_SHA, "journal_cursor": document["journal_cursor"],
            "requested_at": document["requested_at"], "extracted_at": extracted_at,
            "profiles": output, "export_allowed": False, "model_calls": 0, "conversion_uploads": 0}


def main():
    parser = argparse.ArgumentParser(description=__doc__); parser.add_argument("input")
    args = parser.parse_args()
    try:
        with open(args.input, "rb") as handle:
            raw = handle.read(MAX_BYTES+1)
        base.require(len(raw) <= MAX_BYTES, "input_byte_limit")
        document = json.loads(raw.decode("utf-8"), object_pairs_hook=base.unique_object)
        # Bind semantic availability to the actual completion second, never the old source fence.
        result = None
        for _ in range(3):
            stamp = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
            candidate = profile(document, stamp)
            if datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ") == stamp:
                result = candidate; break
        base.require(result is not None, "unstable_extraction_clock")
        text = json.dumps(result, ensure_ascii=False, separators=(",", ":"))
        base.require(len(text.encode()) <= MAX_BYTES, "output_byte_limit")
    except (base.InvalidObservation, OSError, UnicodeDecodeError, json.JSONDecodeError, RecursionError, OverflowError):
        print(json.dumps({"ok": False, "error": "journal_profile_invalid"}), file=sys.stderr)
        return 2
    print(text); return 0


if __name__ == "__main__":
    sys.exit(main())
