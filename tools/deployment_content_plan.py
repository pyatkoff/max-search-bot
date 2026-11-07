#!/usr/bin/env python3
"""Read-only content comparison for the development-only release path.

This is NOT a deployment admission. It neither changes production nor authorizes
skipping an installation. Its caller must separately prove the installed receipt,
production drift/configuration, release intent, and downstream status semantics.
"""
from __future__ import annotations

import argparse
from datetime import datetime
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
from typing import Any

SCHEMA = 1
POLICY = "anytour-development-content-v1"
# Finite reviewed leaf, not a blanket tests/ or docs/ exemption. The two suites
# called by export_debug_logs.php, their fixtures, workflows, and unknown files
# remain production-relevant. Expansion requires a new dependency review.
DEVELOPMENT_ONLY = frozenset({"tests/run_purchase_study_runtime.py"})
STAGES = ("provenance", "key", "bundle", "sync", "migration", "max_webhook",
          "metrika_cron", "install_checks", "smoke", "diagnostics")
SHA = re.compile(r"[0-9a-f]{40}\Z")
MAX_RECEIPT = 128 * 1024
MAX_TREE_BYTES = 16 * 1024 * 1024
MAX_ENTRIES = 50000
MAX_LISTED_CHANGES = 100
MAX_INTENT_COMMITS = 256
MAX_COMMIT_BYTES = 65536
MAX_INTENT_BYTES = 4 * 1024 * 1024
# Read from the current activation/configuration owners, not guessed from paths.
# Markers are matched case-insensitively: GitHub expression contains() is not
# case-sensitive. Unknown deployment-marker families require the ordinary path.
DEPLOYMENT_MARKERS = (
    "[modeled-pipeline-monitor-activation-v1]",
    "[anytour-value-launch-1288]",
    "[anytour-value-history-1293]",
    "[anytour-value-history-fortnight-v1]",
)


class Unconfirmed(ValueError):
    """A bounded reason code, without Git errors or private file content."""


def require(value: bool, reason: str) -> None:
    if not value:
        raise Unconfirmed(reason)


def validate_receipt(value: Any) -> tuple[str, str]:
    require(isinstance(value, dict), "invalid_installed_receipt")
    require(type(value.get("schema")) is int and value["schema"] == 2,
            "unsupported_installed_receipt")
    installed = value.get("sha")
    require(isinstance(installed, str) and SHA.fullmatch(installed) is not None,
            "invalid_installed_sha")
    run_id = value.get("run_id")
    require(isinstance(run_id, str) and re.fullmatch(r"[1-9][0-9]{0,15}", run_id) is not None
            and int(run_id) <= 2**53 - 1, "invalid_installed_run")
    require(type(value.get("install_attempt")) is int and value["install_attempt"] == 1,
            "unconfirmed_install_attempt")
    for field in ("smoke_attempt", "diagnostics_attempt"):
        require(type(value.get(field)) is int and value[field] in (1, 2),
                "unconfirmed_verification_attempt")
    reported = value.get("reported_at")
    require(isinstance(reported, str) and re.fullmatch(r"[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z", reported) is not None,
            "invalid_installed_timestamp")
    try:
        datetime.strptime(reported, "%Y-%m-%dT%H:%M:%SZ")
    except ValueError as error:
        raise Unconfirmed("invalid_installed_timestamp") from error
    require(all(value.get(stage) == "success" for stage in STAGES),
            "incomplete_installed_receipt")
    return installed, run_id


class GitObjects:
    def __init__(self, repository: Path):
        self.repository = repository.resolve()
        # Ambient Git overrides/replace refs must not select another tree.
        self.environment = {k: v for k, v in os.environ.items() if not k.startswith("GIT_")}
        self.environment.update(GIT_NO_REPLACE_OBJECTS="1", GIT_CONFIG_NOSYSTEM="1",
                                GIT_CONFIG_GLOBAL=os.devnull, GIT_TERMINAL_PROMPT="0",
                                GIT_NO_LAZY_FETCH="1")

    def read(self, *args: str, allowed: tuple[int, ...] = (0,)) -> subprocess.CompletedProcess:
        try:
            result = subprocess.run(["git", "-c", "core.fsmonitor=false", *args],
                                    cwd=self.repository, env=self.environment,
                                    stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                    timeout=15, check=False)
        except (OSError, subprocess.TimeoutExpired) as error:
            raise Unconfirmed("git_read_unavailable") from error
        require(result.returncode in allowed, "git_read_failed")
        require(len(result.stdout) <= MAX_TREE_BYTES, "git_output_limit")
        return result

    def require_commit(self, sha: str) -> None:
        require(isinstance(sha, str) and SHA.fullmatch(sha) is not None, "invalid_commit_sha")
        require(self.read("cat-file", "-t", sha).stdout.strip() == b"commit", "not_a_commit")

    def tree(self, sha: str) -> dict[str, tuple[str, str]]:
        return parse_tree(self.read("ls-tree", "-r", "-z", "--full-tree", sha).stdout)



def scan_release_intents(git: GitObjects, installed: str, candidate: str) -> dict[str, Any]:
    """Inspect bounded raw commit messages without executing any candidate code.

    Looking only at HEAD misses earlier uninstalled marker commits. The complete
    installed-to-candidate DAG is inspected. This is deliberately conservative:
    a marker in a side-branch message retains the ordinary installation path.
    """
    grafts_path = git.read("rev-parse", "--git-path", "info/grafts").stdout.decode("utf-8").strip()
    grafts = Path(grafts_path)
    if not grafts.is_absolute():
        grafts = git.repository / grafts
    require(not grafts.exists() and not grafts.is_symlink(), "grafted_history")
    listing = git.read("rev-list", "--max-count=" + str(MAX_INTENT_COMMITS + 1),
                       candidate, "^" + installed, "--").stdout.decode("ascii").splitlines()
    require(len(listing) <= MAX_INTENT_COMMITS, "intent_history_limit")
    if candidate == installed:
        listing = [candidate]
    require(bool(listing) and len(set(listing)) == len(listing)
            and all(SHA.fullmatch(item) is not None for item in listing), "invalid_intent_history")
    hits = []
    total_bytes = 0
    for commit in listing:
        size = git.read("cat-file", "-s", commit).stdout.strip()
        require(size.isdigit() and int(size) <= MAX_COMMIT_BYTES, "intent_message_limit")
        raw = git.read("cat-file", "commit", commit).stdout
        total_bytes += len(raw)
        require(len(raw) <= MAX_COMMIT_BYTES and total_bytes <= MAX_INTENT_BYTES,
                "intent_message_limit")
        try:
            header, message_bytes = raw.split(b"\n\n", 1)
            message = message_bytes.decode("utf-8")
        except (ValueError, UnicodeError) as error:
            raise Unconfirmed("invalid_commit_message") from error
        require(header.startswith(b"tree ") and "\x00" not in message, "invalid_commit_message")
        lowered = message.lower()
        known = [token for token in DEPLOYMENT_MARKERS if token in lowered]
        remaining = lowered
        for token in DEPLOYMENT_MARKERS:
            remaining = remaining.replace(token, "")
        unknown = "[anytour-" in remaining or "[modeled-" in remaining
        # Never emit private commit messages, subject lines or unrecognized text.
        if known or unknown:
            hits.append({"commit_sha": commit, "markers": known,
                         "unknown_marker_present": unknown})
    return {"status": "deployment_intent_present" if hits else "no_registered_intent",
            "checked_commits": len(listing), "matched_commits": hits,
            "coverage": "registered_markers_and_reserved_families"}


def parse_tree(raw: bytes) -> dict[str, tuple[str, str]]:
    require(isinstance(raw, bytes) and len(raw) <= MAX_TREE_BYTES and raw.endswith(b"\0"),
            "invalid_tree_output")
    records = raw[:-1].split(b"\0")
    require(0 < len(records) <= MAX_ENTRIES, "tree_entry_limit")
    entries: dict[str, tuple[str, str]] = {}
    for record in records:
        try:
            metadata, path_raw = record.split(b"\t", 1)
            mode, kind, oid = metadata.decode("ascii").split(" ")
            path = path_raw.decode("utf-8")
        except (ValueError, UnicodeError) as error:
            raise Unconfirmed("invalid_tree_entry") from error
        require(mode in ("100644", "100755") and kind == "blob", "unsupported_tree_entry")
        require(SHA.fullmatch(oid) is not None, "invalid_tree_object")
        require(bool(path) and not path.startswith("/") and "\\" not in path
                and all(part not in ("", ".", "..") for part in path.split("/"))
                and not any(ord(char) < 32 or ord(char) == 127 for char in path),
                "invalid_tree_path")
        require(path not in entries, "duplicate_tree_path")
        entries[path] = (mode, oid)
    return entries


def runtime_fingerprint(entries: dict[str, tuple[str, str]]) -> str:
    rows = [(name, *entries[name]) for name in sorted(entries) if name not in DEVELOPMENT_ONLY]
    wire = json.dumps(rows, ensure_ascii=True, separators=(",", ":")).encode("ascii")
    return hashlib.sha256(wire).hexdigest()


def compare_trees(before: dict[str, tuple[str, str]], after: dict[str, tuple[str, str]]) -> dict[str, Any]:
    changed = sorted(name for name in before.keys() | after.keys() if before.get(name) != after.get(name))
    # Add/delete/rename/executable-bit changes are NOT audited leaf modifications.
    only_development = bool(changed) and all(
        name in DEVELOPMENT_ONLY and name in before and name in after
        and before[name][0] == after[name][0] == "100644" for name in changed)
    classification = ("identical_contents" if not changed else
                      "development_only" if only_development else "runtime_or_unknown")
    return {
        "classification": classification,
        "runtime_contents_unchanged": classification in ("identical_contents", "development_only"),
        "changed_path_count": len(changed),
        "changed_paths": changed[:MAX_LISTED_CHANGES],
        "changed_paths_truncated": len(changed) > MAX_LISTED_CHANGES,
        "installed_runtime_fingerprint": runtime_fingerprint(before),
        "candidate_runtime_fingerprint": runtime_fingerprint(after),
    }


def compare_range(repository: Path, installed: str, candidate_sha: str) -> dict[str, Any]:
    """Compare immutable sources; this pure proof does not grant release authority.

    The independent editor also uses this exact policy for the already accepted
    application-to-repository interval. It still admits its own component diff
    separately and obtains current repository authority through the shared resolver.
    """
    answer: dict[str, Any] = {
        "schema": SCHEMA, "policy": POLICY,
        "classification": "unconfirmed", "runtime_contents_unchanged": False,
        "skip_installation_authorized": False,
        "production_observed": False, "release_path": "unconfirmed",
    }
    try:
        git = GitObjects(repository)
        git.require_commit(installed)
        git.require_commit(candidate_sha)
        require(git.read("rev-parse", "--is-shallow-repository").stdout.strip() == b"false",
                "shallow_history")
        ancestor = git.read("merge-base", "--is-ancestor", installed, candidate_sha, allowed=(0, 1))
        require(ancestor.returncode == 0, "installed_source_not_ancestor")
        answer.update(installed_sha=installed, candidate_sha=candidate_sha)
        answer.update(compare_trees(git.tree(installed), git.tree(candidate_sha)))
        if answer["runtime_contents_unchanged"]:
            answer["intent_scan"] = scan_release_intents(git, installed, candidate_sha)
            answer["release_path"] = (
                "full_required" if answer["intent_scan"]["status"] == "deployment_intent_present"
                else "content_candidate")
        else:
            answer["release_path"] = "full_required"
            answer["intent_scan"] = {"status": "not_needed_for_runtime_change"}
        answer["reason"] = "content_and_intent_comparison_only"
    except Unconfirmed as error:
        # An incomplete intent scan must not leave a usable content-only verdict.
        answer.update(classification="unconfirmed", runtime_contents_unchanged=False,
                      release_path="unconfirmed", reason=str(error))
    except (OSError, UnicodeError):
        answer.update(classification="unconfirmed", runtime_contents_unchanged=False,
                      release_path="unconfirmed", reason="git_metadata_unreadable")
    return answer


def plan(repository: Path, receipt: Any, candidate_sha: str) -> dict[str, Any]:
    try:
        installed, run_id = validate_receipt(receipt)
    except Unconfirmed as error:
        return {"schema": SCHEMA, "policy": POLICY, "classification": "unconfirmed",
                "runtime_contents_unchanged": False, "skip_installation_authorized": False,
                "production_observed": False, "release_path": "unconfirmed", "reason": str(error)}
    answer = compare_range(repository, installed, candidate_sha)
    if "installed_sha" in answer:
        answer["installed_run_id"] = run_id
    return answer



def read_json(file: Path) -> Any:
    """Read bounded, unambiguous JSON; never treat duplicate fields as proof."""
    with file.open("rb") as stream:
        data = stream.read(MAX_RECEIPT + 1)
    require(len(data) <= MAX_RECEIPT, "input_limit")
    def unique(pairs):
        result = {}
        for key, value in pairs:
            if key in result:
                raise ValueError("duplicate_key")
            result[key] = value
        return result
    def invalid_constant(value):
        raise ValueError("invalid_constant")
    return json.loads(data, object_pairs_hook=unique, parse_constant=invalid_constant)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--repository", type=Path, default=Path.cwd())
    parser.add_argument("--installed-receipt", type=Path, required=True)
    parser.add_argument("--candidate-sha", required=True)
    args = parser.parse_args()
    try:
        receipt = read_json(args.installed_receipt)
    except (OSError, ValueError, UnicodeError):
        result = {"schema": SCHEMA, "policy": POLICY, "classification": "unconfirmed",
                  "runtime_contents_unchanged": False, "skip_installation_authorized": False,
                  "production_observed": False, "release_path": "unconfirmed",
                  "reason": "unreadable_installed_receipt"}
    else:
        result = plan(args.repository, receipt, args.candidate_sha)
    print(json.dumps(result, ensure_ascii=True, sort_keys=True))
    return 2 if result["classification"] == "unconfirmed" else 0


if __name__ == "__main__":
    raise SystemExit(main())
