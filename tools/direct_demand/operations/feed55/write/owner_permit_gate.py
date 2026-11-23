"""Authenticate one exact owner permit for one native FEED55 write.

The private-repository checks are performed by the root coordinator.  This
module only accepts the authenticated issue-comment response returned by the
same-repository GitHub API.  It never creates or edits a comment.
"""
import datetime
import hashlib
import json
import re

STATUSES = ("in_progress", "queued", "waiting", "pending", "requested")
OWNER = 226193297
FIELDS = {
    "schema_version", "kind", "repository", "execution_run_id",
    "execution_head_sha", "run_attempt", "batch", "operation", "claim_id",
    "plan_sha256", "script_sha256", "preimage_sha256",
    "previous_native_receipt_sha256", "private_route_proof_sha256", "nonce",
    "observed_at", "expires_at", "private_repository", "private_branch_sha",
    "private_status_checks", "decision",
}


def require(value, reason):
    if not value:
        raise ValueError(reason)


def timestamp(value):
    parsed = datetime.datetime.fromisoformat(value.replace("Z", "+00:00"))
    require(parsed.tzinfo is not None, "timezone_required")
    return parsed


def admit(comment, expected, now):
    require(
        comment.get("id") == expected["permit_comment_id"]
        and comment.get("user", {}).get("id") == OWNER
        and comment["user"].get("type") == "User",
        "authenticated_owner_comment_required",
    )
    permit = json.loads(comment["body"])
    require(set(permit) == FIELDS, "permit_schema_changed")
    require(
        permit["schema_version"] == 1
        and permit["kind"] == "ROOT_CONNECTOR_ONE_NATIVE_FEED_WRITE"
        and permit["decision"] == "ALLOW_EXACT_ONE_WRITE",
        "one_write_permit_required",
    )
    bound = (
        "repository", "execution_run_id", "execution_head_sha", "run_attempt",
        "batch", "operation", "claim_id", "plan_sha256", "script_sha256",
        "preimage_sha256", "previous_native_receipt_sha256",
        "private_route_proof_sha256", "nonce",
    )
    for key in bound:
        require(permit[key] == expected[key], "permit_binding_changed_" + key)
    require(
        permit["repository"] == "pyatkoff/max-search-bot"
        and permit["run_attempt"] == 1
        and type(permit["execution_run_id"]) is int
        and permit["execution_run_id"] > 0,
        "one_shot_scope_required",
    )
    observed = timestamp(permit["observed_at"])
    expires = timestamp(permit["expires_at"])
    updated = timestamp(comment["updated_at"])
    require(
        0 <= (now - observed).total_seconds() <= 60
        and 0 < (expires - observed).total_seconds() <= 60
        and now <= expires
        and observed <= updated <= now,
        "permit_expired_or_future",
    )
    require(
        permit["private_repository"] == "pyatkoff/tour-max"
        and permit["private_branch_sha"] == expected["private_branch_sha"]
        and re.fullmatch(r"[a-f0-9]{40}", permit["private_branch_sha"]),
        "private_scope_changed",
    )
    checks = permit["private_status_checks"]
    require([item["status"] for item in checks] == list(STATUSES), "all_private_statuses_required")
    for item in checks:
        require(
            set(item) == {"status", "observed_at", "total_count", "returned_count", "response_sha256"}
            and item["total_count"] == item["returned_count"] == 0
            and observed <= timestamp(item["observed_at"]) <= updated
            and re.fullmatch(r"[a-f0-9]{64}", item["response_sha256"]),
            "private_check_not_clear",
        )
    return {
        "permit_body_sha256": hashlib.sha256(comment["body"].encode()).hexdigest(),
        "comment_id": comment["id"],
        "comment_updated_at": comment["updated_at"],
        "consumption": "ONE_LOCAL_EXCLUSIVE_STARTED_MARKER_BEFORE_UNCHANGED_WRITER",
        "root_connector_private_checks": True,
    }
