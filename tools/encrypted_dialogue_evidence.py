#!/usr/bin/env python3
"""Bounded, encrypted evidence from existing read-only MAX snapshots.

Only the public age recipient belongs in git. Never emit plaintext to stdout.
Message text remains private even after best-effort contact redaction.
"""
import argparse
import datetime as dt
import hashlib
import hmac
import json
import os
from pathlib import Path
import re
import sys

import pyrage

ROOT = Path(__file__).resolve().parents[1]
REPOSITORY = "pyatkoff/max-search-bot"
MAX_INPUT = 32 * 1024 * 1024
MAX_OUTPUT = 2 * 1024 * 1024
MAX_SESSIONS = 20
MAX_MESSAGES = 24
MAX_TEXT = 280
MAX_AI_SAMPLES = 10
MAX_AI_TEXT = 2000
AI_INVOCATION_LIMITS = {"file_bytes": 1048576, "frame_bytes": 65536, "header_bytes": 4096,
                        "retained_per_stream": 128, "parsed_per_stream": 4096,
                        "age_seconds": 3600, "future_seconds": 60}
AI_INVOCATION_READ_REASONS = {
    "log_directory_unavailable", "file_missing", "unsafe_file_type", "unsafe_permissions",
    "file_size_limit", "file_unreadable", "file_changed", "file_busy", "read_failed",
}
AI_INVOCATION_CUTOFF_REASONS = {
    "truncation_marker_in_legacy", "parsed_frame_limit", "header_size_limit", "incomplete_header",
    "invalid_header", "invalid_metadata_json", "invalid_metadata_fields", "duplicate_metadata_key",
    "unsupported_schema", "invalid_invocation_id", "invalid_stage", "invalid_timestamp",
    "frame_size_limit", "invalid_payload_digest", "payload_truncated", "payload_digest_mismatch",
    "payload_not_lf_aligned", "truncation_marker_in_payload",
    "context_read_header_size_limit", "invalid_context_read_header",
}
WEBSITE_CONTEXT_READ_OUTCOMES = {
    "available", "empty", "not_found", "read_failed",
    "not_attempted_invalid_chat", "load_failed", "not_recorded",
}
FLAGS = {
    "repeated_callback_input", "repeated_same_input", "excessive_turns",
    "rapid_date_reselection", "manager_requested_no_reply",
    "manager_taken_no_reply", "left_waiting_queue_without_manager_reply",
}
STATES = {"ai", "waiting_manager", "manager", "closed", "bot", "new"}
TIMES = ("started_at", "last_message_at", "manager_request_at", "manager_first_reply_at")
BOOLS = ("needs_collected", "tours_opened", "site_opened", "manager_requested",
         "manager_request_active", "manager_replied", "phone_received")


def timestamp(value):
    if not isinstance(value, str) or len(value) > 40:
        raise ValueError("invalid timestamp")
    parsed = dt.datetime.fromisoformat(value.replace("Z", "+00:00"))
    return parsed.replace(tzinfo=dt.timezone.utc) if parsed.tzinfo is None else parsed


def fresh(value, now, minutes=15):
    age = (now - timestamp(value)).total_seconds()
    if not -60 <= age <= minutes * 60:
        raise ValueError("stale evidence")


def redacted_text(value, limit=MAX_TEXT):
    text = value if isinstance(value, str) else ""
    text = re.sub(r"[\x00-\x1f\x7f]", " ", text)
    text = re.sub(r"(?:https?://|www\.)\S+", "[URL]", text, flags=re.I)
    text = re.sub(r"[\w.+-]+@[\w.-]+\.[\w-]+", "[EMAIL]", text)
    text = re.sub(r"(?<!\w)@[\w.]{3,}", "[HANDLE]", text)
    text = re.sub(r"(?<!\d)\+?\d[\d\s().-]{7,}\d(?!\d)",
                  lambda m: "[NUMBER]" if len(re.sub(r"\D", "", m[0])) >= 9 else m[0], text)
    text = re.sub(r"(?i)\b(?:token|authorization|password|api[_-]?key)\s*[:=]\s*\S+",
                  "[CREDENTIAL]", text)
    return " ".join(text.split())[:limit]


def read_bounded(path, limit):
    with Path(path).open("rb") as stream:
        data = stream.read(limit + 1)
    if len(data) > limit:
        raise ValueError("input limit")
    return data


def external_path(path):
    path = Path(path).absolute()
    if path.is_symlink() or path.resolve().is_relative_to(ROOT):
        raise ValueError("private output must be outside checkout")
    return path


def private_write(path, data):
    path = external_path(path)
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    try:
        with os.fdopen(fd, "wb") as stream:
            stream.write(data)
    except BaseException:
        path.unlink(missing_ok=True)
        raise


def manager_ai_feedback_evidence(production, sha, salt, now):
    """Allowlisted private projection; the source snapshot is never copied wholesale."""
    if not isinstance(production, dict) or production.get("ok") is not True:
        raise ValueError("invalid production snapshot")
    source = production.get("production")
    if not isinstance(source, dict) or source.get("sha") != sha:
        raise ValueError("wrong production provenance")
    fresh(production.get("generated_at"), now)
    summary = production.get("manager_ai_feedback")
    samples = production.get("manager_ai_feedback_private")
    if not isinstance(summary, dict) or type(summary.get("ok")) is not bool:
        raise ValueError("invalid feedback summary")
    fresh(summary.get("generated_at"), now)
    if not isinstance(samples, list) or len(samples) > MAX_AI_SAMPLES:
        raise ValueError("invalid feedback samples")
    if not summary["ok"] and samples:
        raise ValueError("unavailable feedback has samples")
    result = {
        "ok": summary["ok"],
        "generated_at": timestamp(summary["generated_at"]).isoformat(),
        "limits": {"samples": MAX_AI_SAMPLES, "characters_per_text": MAX_AI_TEXT},
        "coverage": "bounded latest verified linked messages; current sent text may differ after edits",
        "samples": [],
    }
    for sample in samples:
        if not isinstance(sample, dict):
            raise ValueError("invalid feedback sample")
        item = {}
        source = sample.get("source", "generated")
        if source not in ("generated", "prepared"):
            raise ValueError("invalid feedback source")
        item["source"] = source
        for field in ("initial_text_available", "recorded_text_matches_proposal", "edited",
                      "proposed_text_truncated", "current_sent_text_truncated"):
            if type(sample.get(field)) is not bool:
                raise ValueError("invalid feedback flag")
            item[field] = sample[field]
        for field in ("proposed_text", "current_sent_text"):
            value = sample.get(field)
            if not isinstance(value, str) or len(value) > MAX_AI_TEXT:
                raise ValueError("invalid feedback text")
            # Redaction can expand a short token. Preserve upstream truncation and
            # also report any truncation introduced by this final projection.
            redacted = redacted_text(value, None)
            item[field] = redacted[:MAX_AI_TEXT]
            item[field + "_truncated"] = item[field + "_truncated"] or len(redacted) > MAX_AI_TEXT
        for field in ("created_at", "sent_at"):
            item[field] = timestamp(sample.get(field)).isoformat()
        for field, alias in (("draft_id", "draft_ref"), ("conversation_id", "session_ref"),
                             ("message_id", "message_ref")):
            if field not in sample:
                continue
            value = sample[field]
            if field == "draft_id":
                if not isinstance(value, str) or not re.fullmatch(r"[0-9a-f]{32}", value):
                    raise ValueError("invalid feedback correlation")
                identifier = "draft:" + value
            else:
                if type(value) is not int or not 0 < value <= 2**63 - 1:
                    raise ValueError("invalid feedback correlation")
                identifier = str(value) if field == "conversation_id" else "message:" + str(value)
            item[alias] = hmac.new(salt, identifier.encode(), hashlib.sha256).hexdigest()[:16]
        result["samples"].append(item)
    return result


def ai_invocation_metadata_evidence(production, sha, salt, now):
    """Consume a distinct metadata projection, never reconstitute source frames.

    Payload/hash checks happened locally on the application server. These are
    source-reported checks, not independently authenticated original log bytes.
    The snapshot SHA describes the collector, not the version of every frame.
    """
    if (not isinstance(production, dict) or production.get("ok") is not True
            or not isinstance(production.get("production"), dict)
            or production["production"].get("sha") != sha):
        raise ValueError("wrong invocation snapshot provenance")
    fresh(production.get("generated_at"), now)
    source = production.get("ai_invocation_metadata_private")
    if source is None:
        return {"schema": "anytour.ai_invocation_metadata_evidence.v2", "available": False,
                "source_schema": None,
                "reason": "source_not_supplied", "runtime_trace_proven": False,
                "causal_diagnosis_performed": False, "session_link_available": False}

    def exact_keys(value, expected):
        if not isinstance(value, dict) or set(value) != set(expected):
            raise ValueError("invalid invocation projection fields")

    def integer(value, minimum, maximum):
        if type(value) is not int or not minimum <= value <= maximum:
            raise ValueError("invalid invocation projection count")

    exact_keys(source, {"schema", "generated_at", "ok", "projection", "trust", "runtime_trace_proven",
                        "causal_diagnosis_performed", "cross_stream_atomic", "source_history_complete",
                        "session_link_available", "limits", "streams", "records"})
    if (source["schema"] not in ("anytour.ai_invocation_metadata.v1", "anytour.ai_invocation_metadata.v2")
            or source["projection"] != "metadata_only" or source["trust"] != "unauthenticated_text_log"
            or type(source["ok"]) is not bool
            or any(source[key] is not False for key in ("runtime_trace_proven", "causal_diagnosis_performed",
                                                       "cross_stream_atomic", "source_history_complete", "session_link_available"))
            or not isinstance(source["limits"], dict) or source["limits"] != AI_INVOCATION_LIMITS
            or any(type(value) is not int for value in source["limits"].values())):
        raise ValueError("invalid invocation projection contract")
    source_v2 = source["schema"] == "anytour.ai_invocation_metadata.v2"
    fresh(source["generated_at"], now)
    if not isinstance(source["streams"], list) or len(source["streams"]) != 2:
        raise ValueError("invalid invocation streams")
    streams = {}
    for stream in source["streams"]:
        exact_keys(stream, {"stream", "read_status", "reason", "bytes_read", "valid_frames", "retained_frames",
                            "omitted_old_frames", "omitted_future_frames", "omitted_by_limit", "legacy_bytes",
                            "cutoff_reason", "untrusted_tail_bytes"})
        name = stream["stream"]
        if not isinstance(name, str) or name not in {"debug", "error"} or name in streams:
            raise ValueError("invalid invocation stream identity")
        if not isinstance(stream["read_status"], str) or stream["read_status"] not in {"available", "unavailable"}:
            raise ValueError("invalid invocation read status")
        if stream["read_status"] == "available":
            if stream["reason"] is not None:
                raise ValueError("invalid invocation available reason")
        elif not isinstance(stream["reason"], str) or stream["reason"] not in AI_INVOCATION_READ_REASONS:
            raise ValueError("invalid invocation unavailable reason")
        if stream["cutoff_reason"] is not None and (not isinstance(stream["cutoff_reason"], str) or stream["cutoff_reason"] not in AI_INVOCATION_CUTOFF_REASONS):
            raise ValueError("invalid invocation cutoff reason")
        for key in ("bytes_read", "legacy_bytes", "untrusted_tail_bytes"):
            integer(stream[key], 0, AI_INVOCATION_LIMITS["file_bytes"])
        for key in ("valid_frames", "omitted_old_frames", "omitted_future_frames", "omitted_by_limit"):
            integer(stream[key], 0, AI_INVOCATION_LIMITS["parsed_per_stream"])
        integer(stream["retained_frames"], 0, AI_INVOCATION_LIMITS["retained_per_stream"])
        if (stream["valid_frames"] != sum(stream[key] for key in ("retained_frames", "omitted_old_frames", "omitted_future_frames", "omitted_by_limit"))
                or stream["legacy_bytes"] + stream["untrusted_tail_bytes"] > stream["bytes_read"]
                or (stream["cutoff_reason"] is None) != (stream["untrusted_tail_bytes"] == 0)
                or (stream["read_status"] == "unavailable" and any(stream[key] != 0 for key in
                    ("bytes_read", "valid_frames", "retained_frames", "omitted_old_frames", "omitted_future_frames", "omitted_by_limit", "legacy_bytes", "untrusted_tail_bytes")))):
            raise ValueError("inconsistent invocation stream counts")
        streams[name] = dict(stream)
    expected_ok = all(s["read_status"] == "available" and s["cutoff_reason"] is None and s["omitted_by_limit"] == 0 for s in streams.values())
    if source["ok"] is not expected_ok:
        raise ValueError("inconsistent invocation availability")
    if not isinstance(source["records"], list) or len(source["records"]) > 2 * AI_INVOCATION_LIMITS["retained_per_stream"]:
        raise ValueError("invalid invocation record count")
    records, groups = [], {}
    seen = {name: [] for name in streams}
    for record in source["records"]:
        fields = {"stream", "frame_index", "invocation_id", "stage", "age_seconds", "payload_bytes",
                  "frame_bytes", "payload_sha256_verified", "shape_confirmed", "embedded_prefix_lines"}
        if source_v2:
            fields.add("website_context_read")
        exact_keys(record, fields)
        stream, identifier = record["stream"], record["invocation_id"]
        if not isinstance(stream, str) or stream not in streams or not isinstance(record["stage"], str) or record["stage"] not in {"input", "result", "exception"}:
            raise ValueError("invalid invocation record identity")
        if identifier is not None and (not isinstance(identifier, str) or re.fullmatch(r"[0-9a-f]{32}", identifier) is None):
            raise ValueError("invalid invocation correlation")
        website_read = record["website_context_read"] if source_v2 else ("not_recorded" if record["stage"] == "input" else None)
        if record["stage"] == "input":
            if not isinstance(website_read, str) or website_read not in WEBSITE_CONTEXT_READ_OUTCOMES:
                raise ValueError("invalid website context read outcome")
        elif website_read is not None:
            raise ValueError("website context read on a non-input record")
        integer(record["frame_index"], 1, streams[stream]["valid_frames"])
        integer(record["age_seconds"], -AI_INVOCATION_LIMITS["future_seconds"], AI_INVOCATION_LIMITS["age_seconds"])
        integer(record["payload_bytes"], 0, AI_INVOCATION_LIMITS["frame_bytes"])
        integer(record["frame_bytes"], record["payload_bytes"] + 1, AI_INVOCATION_LIMITS["frame_bytes"])
        integer(record["embedded_prefix_lines"], 0, record["payload_bytes"])
        if record["payload_sha256_verified"] is not True or type(record["shape_confirmed"]) is not bool:
            raise ValueError("invalid invocation source check")
        if seen[stream] and record["frame_index"] <= seen[stream][-1]:
            raise ValueError("duplicate or reordered invocation frame")
        seen[stream].append(record["frame_index"])
        alias = None if identifier is None else hmac.new(salt, ("ai-invocation:" + identifier).encode(), hashlib.sha256).hexdigest()[:16]
        # No native IDs, raw digests, timestamps, paths, text, prompt or model
        # fields cross this allowlist. Ages are relative to the source snapshot.
        item = {"record_ref": f"R{len(records) + 1:03d}", "invocation_ref": alias,
                **{key: record[key] for key in ("stream", "frame_index", "stage", "payload_bytes", "frame_bytes", "shape_confirmed", "embedded_prefix_lines")},
                "age_seconds_at_source_snapshot": record["age_seconds"],
                "website_context_read": website_read,
                "source_reported_payload_sha256_verified": True}
        records.append(item)
        if alias is not None:
            groups.setdefault(alias, []).append(item)
    if any(len(seen[name]) != stream["retained_frames"] for name, stream in streams.items()):
        raise ValueError("inconsistent invocation retained records")
    source_gaps = []
    if any(s["read_status"] != "available" for s in streams.values()): source_gaps.append("source_stream_unavailable")
    if any(s["cutoff_reason"] is not None for s in streams.values()): source_gaps.append("source_admission_stopped")
    if any(s["omitted_by_limit"] > 0 for s in streams.values()): source_gaps.append("record_retention_limit")
    projected_groups = []
    for alias, members in groups.items():
        inputs = [r for r in members if r["stage"] == "input"]
        terminals = [r for r in members if r["stage"] in {"result", "exception"}]
        reasons = list(source_gaps)
        if not inputs: reasons.append("input_record_missing")
        if not terminals: reasons.append("terminal_record_missing")
        if len(inputs) > 1: reasons.append("multiple_input_records")
        if len(terminals) > 1: reasons.append("multiple_terminal_records")
        if any(not r["shape_confirmed"] for r in members): reasons.append("payload_shape_unconfirmed")
        if any(r["stream"] != ("error" if r["stage"] == "exception" else "debug") for r in members): reasons.append("stage_in_unexpected_stream")
        if len(inputs) == len(terminals) == 1:
            before, after = inputs[0], terminals[0]
            if after["age_seconds_at_source_snapshot"] > before["age_seconds_at_source_snapshot"]: reasons.append("terminal_recording_precedes_input")
            if before["stream"] == after["stream"] and after["frame_index"] < before["frame_index"]: reasons.append("terminal_precedes_input_in_stream")
        status = "ambiguous" if len(inputs) > 1 or len(terminals) > 1 else "incomplete" if reasons else "candidate_correlated"
        projected_groups.append({"invocation_ref": alias, "status": status, "reason_codes": sorted(set(reasons)),
                                 "input_records": len(inputs), "terminal_records": len(terminals),
                                 "record_refs": [r["record_ref"] for r in members]})
    return {"schema": "anytour.ai_invocation_metadata_evidence.v2", "available": True, "ok": source["ok"],
            "source_schema": source["schema"],
            "source_generated_at": timestamp(source["generated_at"]).isoformat(), "projection": "metadata_only",
            "trust": "unauthenticated_text_log", "integrity_check_origin": "source_reported",
            "cross_stream_atomic": False, "source_history_complete": False, "session_link_available": False,
            "runtime_trace_proven": False, "causal_diagnosis_performed": False,
            "limits": dict(AI_INVOCATION_LIMITS), "streams": list(streams.values()),
            "records": records, "groups": projected_groups,
            "unlinked_null_id_records": sum(r["invocation_ref"] is None for r in records),
            "coverage": "bounded recent metadata from two sequential private log snapshots; rotation and omissions can hide events",
            "context_read_scope": "website page only; source-post and recent-dialogue retrieval outcomes are not recorded here",
            "version_scope": "production_sha identifies the capture code; individual frame code, prompt and model versions are unavailable"}


def build_evidence(reports, sha, recipient, now=None, production=None):
    now = now or dt.datetime.now(dt.timezone.utc)
    if not re.fullmatch(r"[0-9a-f]{40}", sha):
        raise ValueError("invalid production sha")
    salt = os.urandom(32)
    evidence = []
    omitted = 0
    windows = []
    for name, report in reports:
        if name not in {"live", "today", "yesterday"}:
            raise ValueError("invalid window")
        if report.get("ok") is not True or report.get("channel") != "max":
            raise ValueError("invalid snapshot")
        fresh(report.get("generated_at"), now)
        if name == "live":
            if report.get("window_type") != "rolling_hours" or report.get("window_hours") != 1:
                raise ValueError("unexpected rolling window")
        elif report.get("window_type") != "calendar_day" or report.get("timezone") != "Europe/Kaliningrad":
            raise ValueError("unexpected calendar window")
        windows.append({"name": name, "generated_at": timestamp(report["generated_at"]).isoformat(),
                        "since_utc": timestamp(report["since_utc"]).isoformat()})
        sessions = report.get("sessions")
        if not isinstance(sessions, list):
            raise ValueError("missing sessions")
        for session in reversed(sessions):
            if session.get("is_test") or session.get("channel") != "max":
                continue
            flags = [flag for flag in session.get("flags", []) if flag in FLAGS]
            if not flags or not session.get("message_tail"):
                continue
            if len(evidence) >= MAX_SESSIONS:
                omitted += 1
                continue
            identifier = session.get("conversation_id")
            if not isinstance(identifier, int) or isinstance(identifier, bool) or identifier <= 0:
                raise ValueError("missing correlation")
            alias = hmac.new(salt, str(identifier).encode(), hashlib.sha256).hexdigest()[:16]
            item = {"session_ref": alias, "window": name, "flags": flags,
                    "status": session.get("status") if session.get("status") in STATES else "unknown",
                    "messages": []}
            for field in TIMES:
                item[field] = timestamp(session[field]).isoformat() if session.get(field) else None
            for field in BOOLS:
                item[field] = session.get(field) is True
            for message in session["message_tail"][-MAX_MESSAGES:]:
                direction = message.get("direction")
                sender = message.get("sender_type")
                if direction not in {"inbound", "outbound"} or sender not in {"customer", "bot", "ai", "manager", "system"}:
                    continue
                item["messages"].append({"direction": direction, "sender_type": sender,
                                         "created_at": timestamp(message["created_at"]).isoformat(),
                                         "text": redacted_text(message.get("text"))})
            evidence.append(item)
    result = {"schema_version": 1, "repository": REPOSITORY, "production_sha": sha,
              "generated_at": now.isoformat(), "recipient": recipient, "windows": windows,
              "limits": {"sessions": MAX_SESSIONS, "messages_per_session": MAX_MESSAGES,
                         "characters_per_message": MAX_TEXT}, "omitted_sessions": omitted,
              "privacy": "private; contact redaction is best effort, not anonymization",
              "coverage": "flagged MAX sessions only; tails may be truncated; windows may overlap",
              "sessions": evidence}
    if production is not None:
        result["manager_ai_feedback"] = manager_ai_feedback_evidence(production, sha, salt, now)
        result["ai_invocation_metadata"] = ai_invocation_metadata_evidence(production, sha, salt, now)
    data = json.dumps(result, ensure_ascii=False, separators=(",", ":")).encode()
    if len(data) > MAX_OUTPUT:
        raise ValueError("output limit")
    return data


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="command", required=True)
    keygen = sub.add_parser("keygen")
    keygen.add_argument("--identity", required=True)
    keygen.add_argument("--recipient", required=True)
    encrypt = sub.add_parser("encrypt")
    encrypt.add_argument("--recipient", required=True)
    encrypt.add_argument("--sha", required=True)
    encrypt.add_argument("--live", required=True)
    encrypt.add_argument("--today", required=True)
    encrypt.add_argument("--yesterday", required=True)
    encrypt.add_argument("--production")
    encrypt.add_argument("--output", required=True)
    decrypt = sub.add_parser("decrypt")
    decrypt.add_argument("--identity", required=True)
    decrypt.add_argument("--input", required=True)
    decrypt.add_argument("--sha", required=True)
    decrypt.add_argument("--output", required=True)
    args = parser.parse_args()
    try:
        if args.command == "keygen":
            if Path(args.recipient).exists():
                raise ValueError("recipient already exists")
            identity = pyrage.x25519.Identity.generate()
            private_write(args.identity, (str(identity) + "\n").encode())
            with Path(args.recipient).open("x") as out:
                out.write(str(identity.to_public()) + "\n")
        elif args.command == "encrypt":
            recipient = pyrage.x25519.Recipient.from_str(read_bounded(args.recipient, 256).decode().strip())
            reports = [(name, json.loads(read_bounded(getattr(args, name), MAX_INPUT)))
                       for name in ("live", "today", "yesterday")]
            production = json.loads(read_bounded(args.production, MAX_INPUT)) if args.production else None
            if args.production and production is None:
                raise ValueError("invalid production snapshot")
            data = build_evidence(reports, args.sha, str(recipient), production=production)
            private_write(args.output, pyrage.encrypt(data, [recipient]))
        else:
            identity = pyrage.x25519.Identity.from_str(read_bounded(args.identity, 256).decode().strip())
            ciphertext = read_bounded(args.input, MAX_OUTPUT + 65536)
            data = pyrage.decrypt(ciphertext, [identity])
            report = json.loads(data)
            if report.get("repository") != REPOSITORY or report.get("schema_version") != 1:
                raise ValueError("wrong evidence schema")
            if report.get("production_sha") != args.sha or report.get("recipient") != str(identity.to_public()):
                raise ValueError("wrong evidence provenance")
            fresh(report.get("generated_at"), dt.datetime.now(dt.timezone.utc), 60)
            private_write(args.output, data)
        print("Encrypted evidence operation succeeded")
        return 0
    except Exception:
        # Neither payload content, key material nor exception repr belongs in Actions logs.
        print("Encrypted evidence operation failed; no plaintext was published", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
