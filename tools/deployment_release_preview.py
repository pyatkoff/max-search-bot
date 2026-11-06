#!/usr/bin/env python3
"""Offline release preview; never grants deployment or publisher authority.

No HTTP/SSH, writes, schedules, installation, status publication or release-slot
mutation. The live workflow adapters and proof checks are deliberately absent.
A preview cannot replace deploy_status.json or any existing technical gate.
"""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import re
from typing import Any

from deployment_content_plan import plan, read_json, validate_receipt

REPOSITORY = "pyatkoff/max-search-bot"
PROTOCOL = "anytour-development-release-preview-v1"
CONTEXT_KEYS = frozenset({"repository", "event_name", "ref", "sha", "run_id",
                          "run_attempt", "forced", "inputs"})


def canonical_digest(value: dict[str, Any]) -> str:
    wire = json.dumps(value, ensure_ascii=True, allow_nan=False,
                      sort_keys=True, separators=(",", ":")).encode("ascii")
    return hashlib.sha256(wire).hexdigest()


def preview(repository: Path, receipt: Any, candidate: str, event: Any) -> dict[str, Any]:
    result: dict[str, Any] = {
        "schema": 1, "protocol": PROTOCOL, "decision": "blocked",
        "skip_installation_authorized": False, "production_observed": False,
        "release_slot_mutation_authorized": False, "publication_performed": False,
        "reason": "unconfirmed_context",
    }
    if not isinstance(event, dict) or set(event) != CONTEXT_KEYS:
        return result
    if (event["repository"] != REPOSITORY or event["ref"] != "refs/heads/main"
            or event["sha"] != candidate
            or not isinstance(event["run_id"], str)
            or re.fullmatch(r"[1-9][0-9]{0,15}", event["run_id"]) is None
            or int(event["run_id"]) > 2**53 - 1
            or type(event["run_attempt"]) is not int or event["run_attempt"] != 1
            or type(event["forced"]) is not bool
            or not isinstance(event["inputs"], dict)):
        return result
    # Explicit or unknown operations must not be inferred away from file equality.
    if event["event_name"] == "workflow_dispatch":
        result.update(decision="ordinary_release_required", reason="explicit_manual_release")
        return result
    if event["event_name"] != "push":
        return result
    if event["forced"] or event["inputs"]:
        result.update(decision="ordinary_release_required", reason="explicit_or_forced_intent")
        return result

    comparison = plan(repository, receipt, candidate)
    result["comparison"] = comparison
    if comparison["classification"] == "unconfirmed":
        result["reason"] = comparison["reason"]
        return result
    if comparison["release_path"] != "content_candidate":
        result.update(decision="ordinary_release_required",
                      reason="runtime_change_or_deployment_intent")
        return result

    installed, installed_run = validate_receipt(receipt)
    if installed == candidate:
        result.update(decision="ordinary_release_required", reason="no_new_repository_revision")
        return result
    # Store the original installation as provenance, not as a claim that any
    # installation stage was executed for the new repository revision.
    original = {key: receipt[key] for key in (
        "schema", "sha", "run_id", "install_attempt", "smoke_attempt",
        "diagnostics_attempt", "reported_at")}
    original["receipt_digest"] = canonical_digest(receipt)
    result.update(decision="no_install_candidate", reason="live_integration_required")
    result["proposed_record"] = {
        "schema": 1, "kind": "application_unchanged_proposal", "completed": False,
        "repository_sha": candidate, "application_sha": installed,
        "candidate_run_id": event["run_id"], "installation_run_id": installed_run,
        "original_installation": original,
        "runtime_fingerprint": comparison["candidate_runtime_fingerprint"],
        "installation_performed": False,
        "pending": ["authoritative_installation_and_terminal_proof", "current_production_readback",
                    "runtime_configuration_and_component_drift", "fresh_release_admission",
                    "downstream_no_install_handling", "exact_record_publication"],
    }
    # No top-level sha/run_id or success stages: existing installation validators
    # must not mistake this preview for their successful schema-2 receipt.
    return result


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--repository", type=Path, required=True)
    parser.add_argument("--installed-receipt", type=Path, required=True)
    parser.add_argument("--event-context", type=Path, required=True)
    parser.add_argument("--candidate-sha", required=True)
    args = parser.parse_args()
    try:
        result = preview(args.repository, read_json(args.installed_receipt),
                         args.candidate_sha, read_json(args.event_context))
    except (OSError, UnicodeError, ValueError):
        result = {"schema": 1, "protocol": PROTOCOL, "decision": "blocked",
                  "reason": "unreadable_or_invalid_input", "skip_installation_authorized": False,
                  "production_observed": False, "release_slot_mutation_authorized": False,
                  "publication_performed": False}
    print(json.dumps(result, ensure_ascii=True, sort_keys=True, allow_nan=False))
    return 2 if result["decision"] == "blocked" else 0


if __name__ == "__main__":
    raise SystemExit(main())
