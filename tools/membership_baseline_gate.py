#!/usr/bin/env python3
"""Offline synthetic contract only. No network, database or production admission."""
import json
import sys
from datetime import datetime, timezone

MAX_BYTES = 65536
MAX_ROWS = 500
CUTOFF = "2026-10-27T14:35:02Z"
SOURCES = {"channel_subscription_observed", "channel_retention_30m_observed"}

class Invalid(ValueError):
    pass

def moment(value):
    if not isinstance(value, str):
        raise Invalid("invalid_clock")
    try:
        parsed = datetime.strptime(value, "%Y-%m-%dT%H:%M:%SZ")
        if parsed.strftime("%Y-%m-%dT%H:%M:%SZ") != value:
            raise ValueError()
        return parsed.replace(tzinfo=timezone.utc)
    except ValueError:
        raise Invalid("invalid_clock") from None

def evaluate(data):
    """Validate supplied metadata; caller must independently prove every assertion."""
    if not isinstance(data, dict) or set(data) != {"scope", "admission", "rows", "protocol_bytes", "elapsed_ms"}:
        raise Invalid("invalid_contract")
    if type(data["protocol_bytes"]) is not int or not 0 <= data["protocol_bytes"] <= MAX_BYTES:
        raise Invalid("protocol_budget")
    if type(data["elapsed_ms"]) is not int or not 0 <= data["elapsed_ms"] <= 5000:
        raise Invalid("time_budget")
    scope, admission, rows = data["scope"], data["admission"], data["rows"]
    if not isinstance(scope, dict) or set(scope) != {"project", "platform", "person", "conversation"} or scope["project"] != "anytour" or scope["platform"] != "max" or any(not isinstance(scope[k], str) or not scope[k] for k in ("person", "conversation")):
        raise Invalid("scope")
    if not isinstance(admission, dict) or set(admission) != {"canonical_guard_authorized", "same_case_bound", "non_test", "non_owner", "not_revoked"}:
        raise Invalid("admission_contract")
    if any(admission[k] is not True for k in admission):
        raise Invalid("admission_unconfirmed")
    if not isinstance(rows, list) or len(rows) > MAX_ROWS:
        raise Invalid("row_budget")
    result = {"subscription": "unknown", "retention_30m": "unknown"}
    cutoff = moment(CUTOFF)
    accepted = {}
    for row in rows:
        fields = {"scope", "kind", "state", "period", "source_version", "occurred_at", "recorded_at", "first_available_at", "immutable", "origin"}
        if not isinstance(row, dict) or set(row) != fields or row["scope"] != scope:
            raise Invalid("scope_or_fields_mismatch")
        if row["kind"] not in SOURCES or row["origin"] != "immutable_journal_receipt" or row["immutable"] is not True:
            raise Invalid("unsupported_source")
        if not isinstance(row["period"], str) or not row["period"] or type(row["source_version"]) is not int or row["source_version"] < 1:
            raise Invalid("unverified_provenance")
        occurred, recorded, available = map(moment, (row["occurred_at"], row["recorded_at"], row["first_available_at"]))
        if not occurred <= recorded <= available:
            raise Invalid("clock_order")
        if available > cutoff:
            continue
        if row["kind"] in accepted:
            raise Invalid("ambiguous_receipt")
        accepted[row["kind"]] = row
    sub = accepted.get("channel_subscription_observed")
    ret = accepted.get("channel_retention_30m_observed")
    if sub:
        if sub["state"] != "subscribed":
            raise Invalid("invalid_subscription")
        result["subscription"] = "subscribed"
    if ret:
        if not sub or ret["period"] != sub["period"] or ret["state"] not in {"retained", "not_retained"}:
            raise Invalid("retention_mismatch")
        if (moment(ret["occurred_at"]) - moment(sub["occurred_at"])).total_seconds() < 1800:
            raise Invalid("retention_too_early")
        result["retention_30m"] = ret["state"]
    return {"baseline": result, "coverage": "supplied_metadata_only", "live_admission": "unproven",
            "purchase_probability": None, "export_allowed": False}

def main():
    try:
        raw = sys.stdin.buffer.read(MAX_BYTES + 1)
        if len(raw) > MAX_BYTES:
            raise Invalid("protocol_budget")
        data = json.loads(raw.decode("utf-8"))
        if data.get("protocol_bytes") != len(raw):
            raise Invalid("protocol_size_mismatch")
        print(json.dumps(evaluate(data), separators=(",", ":")))
        return 0
    except (Invalid, ValueError, TypeError, KeyError, UnicodeDecodeError):
        print('{"ok":false,"error":"invalid_or_not_admitted"}', file=sys.stderr)
        return 2

if __name__ == "__main__":
    raise SystemExit(main())
