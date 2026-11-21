#!/usr/bin/env python3
"""Two exact original read-only admissions for the checked PR1644 candidate."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess

REPOSITORY = "pyatkoff/max-search-bot"
PR = 1644
BASE = "16631e06f4eca1f95ee7a5216a1c5e07e563d06d"
HEAD = "0892d9448f39d1e64bf51fb9406d0d14fe8d39c6"
HEAD_TREE = "49660f0e04f52a4ad8e759b426004cd9cc6f176d"
GROUP = "audit/forecast-cohort-admission-20261121"
WORKFLOW = ".github/workflows/forecast-cohort-admission-20261121.yml"
RUNNER = GROUP + "/runner.py"
REQUEST = GROUP + "/admission-request.json"
PHASES = {
    "before_publisher": ("audit/cohort1644-before-publisher-20261121", "audit(cohort1644): verify admission before publisher"),
    "bound_publisher": ("audit/cohort1644-bound-publisher-20261121", "audit(cohort1644): verify bound publisher admission"),
}
CORE = {
    "tools/release_priority.js": {"git_blob": "3c1b228d36785546d71fb9e21c68ee4b03961619", "bytes": 23624},
    "tools/deployment_release.js": {"git_blob": "9bd5d8b8e61742fc5e3e5e0852ba51a4d6e2dc6e", "bytes": 126173},
    "tools/release_slot_close.js": {"git_blob": "a81a0ef78d30af8d7c2a55bd99b5c08718043015", "bytes": 97640},
}


class Refusal(Exception):
    pass


def need(ok, code):
    if not ok:
        raise Refusal(code)


def unique_object(pairs):
    result = {}
    for key, value in pairs:
        need(key not in result, "DUPLICATE_JSON_KEY")
        result[key] = value
    return result


def read_json(path, maximum):
    need(path.is_file() and not path.is_symlink(), "INPUT_FILE_REQUIRED")
    raw = path.read_bytes()
    need(0 < len(raw) <= maximum, "INPUT_SIZE_BOUND")
    return json.loads(raw, object_pairs_hook=unique_object), raw


def positive(value):
    return type(value) is int and 0 < value <= 9007199254740991


def request(path):
    value, raw = read_json(path, 4096)
    need(type(value) is dict and set(value) == {"version", "repository", "pr_number", "phase", "head_sha", "base_sha", "coordinator_sha", "coordinator_core", "audit_source", "publisher"}, "REQUEST_SHAPE")
    need(type(value["version"]) is int and value["version"] == 1 and value["repository"] == REPOSITORY
         and type(value["pr_number"]) is int and value["pr_number"] == PR and value["head_sha"] == HEAD
         and value["base_sha"] == BASE and value["coordinator_sha"] == BASE
         and type(value["phase"]) is str and value["phase"] in PHASES, "EXACT_CANDIDATE_AND_BASE_REQUIRED")
    need(value["coordinator_core"] == CORE, "BYTE_EXACT_CANONICAL_PINS_REQUIRED")
    pins = value["audit_source"]
    need(type(pins) is dict and set(pins) == {WORKFLOW, RUNNER}, "AUDIT_PIN_SET")
    for pin in pins.values():
        need(type(pin) is dict and set(pin) == {"sha256", "bytes"}
             and type(pin["sha256"]) is str and re.fullmatch(r"[a-f0-9]{64}", pin["sha256"])
             and type(pin["bytes"]) is int and 0 < pin["bytes"] <= 65536, "AUDIT_PIN_SHAPE")
    p = value["publisher"]
    if value["phase"] == "before_publisher":
        need(p is None, "NO_PUBLISHER_BEFORE_ACQUISITION")
    else:
        need(type(p) is dict and set(p) == {"comment_id", "owner_id", "updated_at"}
             and positive(p["comment_id"]) and positive(p["owner_id"])
             and type(p["updated_at"]) is str
             and re.fullmatch(r"[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z", p["updated_at"]), "ACTUAL_PUBLISHER_READBACK_REQUIRED")
    return value, hashlib.sha256(raw).hexdigest()


def native_context(env, phase):
    branch, message = PHASES[phase]
    need(env.get("GITHUB_ACTIONS") == "true" and env.get("GITHUB_REPOSITORY") == REPOSITORY
         and env.get("GITHUB_EVENT_NAME") == "push" and env.get("GITHUB_ACTOR") == "pyatkoff"
         and env.get("GITHUB_RUN_ATTEMPT") == "1" and env.get("GITHUB_REF") == "refs/heads/" + branch
         and env.get("GITHUB_WORKFLOW_REF") == REPOSITORY + "/" + WORKFLOW + "@refs/heads/" + branch
         and env.get("GITHUB_JOB") == "canonical-readonly"
         and re.fullmatch(r"[a-f0-9]{40}", env.get("GITHUB_SHA", ""))
         and re.fullmatch(r"[1-9][0-9]*", env.get("GITHUB_RUN_ID", "")), "ORIGINAL_NATIVE_OWNER_PUSH_REQUIRED")
    event, _ = read_json(Path(env.get("GITHUB_EVENT_PATH", "")), 1048576)
    need(event.get("repository", {}).get("full_name") == REPOSITORY and event.get("ref") == "refs/heads/" + branch
         and event.get("after") == env["GITHUB_SHA"] and event.get("deleted") is False
         and event.get("forced") is False and event.get("created") is True and event.get("before") == "0" * 40
         and event.get("sender", {}).get("login") == "pyatkoff"
         and event.get("head_commit", {}).get("id") == env["GITHUB_SHA"]
         and event.get("head_commit", {}).get("message") == message, "ACTUAL_ORIGINAL_CREATION_REQUIRED")


def git(root, *args):
    env = {key: value for key, value in os.environ.items() if not key.startswith("GIT_")}
    result = subprocess.run(["git", "-C", str(root), *args], env=env, capture_output=True, timeout=15)
    need(result.returncode == 0 and len(result.stdout) <= 65536 and len(result.stderr) <= 65536, "CHECKOUT_READ_FAILED")
    return result.stdout.decode("utf-8").strip()


def verify_source(source, audit_sha, value):
    need(git(source, "rev-parse", "HEAD") == audit_sha, "AUDIT_CHECKOUT_CHANGED")
    need(git(source, "rev-list", "--parents", "-n1", "HEAD").split() == [audit_sha, BASE], "SINGLE_BASE_PARENT_REQUIRED")
    need(set(git(source, "diff", "--name-only", BASE, audit_sha).splitlines()) == {WORKFLOW, RUNNER, REQUEST}, "EXACT_THREE_AUDIT_PATHS_REQUIRED")
    need(not git(source, "status", "--porcelain", "--untracked-files=all"), "AUDIT_WORKTREE_CHANGED")
    need(Path(__file__).resolve() == source / RUNNER, "AUDIT_SOURCE_LOCATION")
    for relative, pin in value["audit_source"].items():
        path = source / relative
        need(path.is_file() and not path.is_symlink(), "AUDIT_SOURCE_FILE_REQUIRED")
        raw = path.read_bytes()
        need(len(raw) == pin["bytes"] and hashlib.sha256(raw).hexdigest() == pin["sha256"], "AUDIT_SOURCE_CHANGED")


def verify_core(root, expected_sha):
    need(git(root, "rev-parse", "HEAD") == expected_sha, "CHECKOUT_IDENTITY_CHANGED")
    need(not git(root, "status", "--porcelain", "--untracked-files=all"), "CANONICAL_WORKTREE_CHANGED")
    result = {}
    for relative, pin in CORE.items():
        path = root / relative
        need(path.is_file() and not path.is_symlink(), "CANONICAL_SOURCE_MISSING")
        raw = path.read_bytes()
        blob = hashlib.sha1(b"blob " + str(len(raw)).encode() + b"\0" + raw).hexdigest()
        need(len(raw) == pin["bytes"] and blob == pin["git_blob"] and git(root, "rev-parse", "HEAD:" + relative) == blob, "CANONICAL_GIT_BLOB_CHANGED")
        result[relative] = {**pin, "sha256": hashlib.sha256(raw).hexdigest()}
    return result


def command(value, canonical):
    result = ["node", str(canonical / "tools/release_priority.js"), "--admit", str(PR), HEAD, BASE]
    if value["phase"] == "bound_publisher":
        p = value["publisher"]
        result += ["--publisher", str(p["comment_id"]), str(p["owner_id"]), p["updated_at"]]
    return result


def canonical_result(code, stdout, stderr, value):
    need(len(stdout) <= 8192 and len(stderr) <= 8192, "CANONICAL_OUTPUT_BOUND")
    line = stdout.decode("utf-8")
    decision = None
    if line.startswith("release-priority: ") and line.endswith("\n") and line.count("\n") == 1:
        decision = json.loads(line[len("release-priority: "):], object_pairs_hook=unique_object)
        keys = {"allowed", "reason", "holder_pr", "expires_at", "active_reservations"}
        reasons = {"no_active_reservation", "priority_owned", "earlier_ready_release"}
        if value["phase"] == "bound_publisher":
            keys |= {"ignored_later_reservations", "publisher_comment", "publisher_acquired_at"}
            reasons = {"active_publisher", "earlier_ready_release"}
        need(type(decision) is dict and set(decision) == keys
             and type(decision["allowed"]) is bool and type(decision["active_reservations"]) is int
             and 0 <= decision["active_reservations"] <= 300 and decision["reason"] in reasons
             and (decision["holder_pr"] is None or positive(decision["holder_pr"]))
             and (decision["expires_at"] is None or (type(decision["expires_at"]) is str
                  and re.fullmatch(r"[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{3}Z", decision["expires_at"]))), "CANONICAL_DECISION_SHAPE")
        if value["phase"] == "bound_publisher":
            need(type(decision["ignored_later_reservations"]) is int
                 and 0 <= decision["ignored_later_reservations"] <= decision["active_reservations"]
                 and decision["publisher_comment"] == value["publisher"]["comment_id"]
                 and decision["publisher_acquired_at"] == value["publisher"]["updated_at"], "CANONICAL_PUBLISHER_TUPLE_CHANGED")
    if code == 0:
        need(stderr == b"" and decision is not None and decision["allowed"] is True, "CANONICAL_SUCCESS_REQUIRED")
        return {"status": "ADMITTED", "decision": decision}
    if decision is not None:
        need(decision["allowed"] is False, "CANONICAL_EXIT_CONFLICT")
        return {"status": "REFUSED", "decision": decision}
    match = re.fullmatch(r"(?:release-priority|deployment-release): ([a-z0-9_]+)(?: PR#[1-9][0-9]*)?\n?", stderr.decode("utf-8"))
    return {"status": "REFUSED", "reason": match.group(1) if match else "canonical_refusal"}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("mode", choices=["prepare", "run"])
    parser.add_argument("--source", required=True, type=Path)
    parser.add_argument("--request", required=True, type=Path)
    parser.add_argument("--candidate", type=Path)
    parser.add_argument("--canonical", type=Path)
    parser.add_argument("--expected-request-sha")
    args = parser.parse_args()
    report = {"schema": "forecast-cohort-release-admission-v1", "status": "NOT_RUN", "protected_writes": False,
              "ssh": False, "publisher_write": False, "merge_performed": False, "advertising_send": False}
    exit_code = 1
    try:
        value, digest = request(args.request)
        native_context(os.environ, value["phase"])
        source = args.source.resolve()
        need(args.request.resolve() == source / REQUEST, "EXACT_REQUEST_LOCATION")
        verify_source(source, os.environ["GITHUB_SHA"], value)
        report.update({"audit_sha": os.environ["GITHUB_SHA"], "run_id": int(os.environ["GITHUB_RUN_ID"]),
                       "attempt": 1, "job": os.environ["GITHUB_JOB"], "request": value, "request_sha256": digest})
        if args.mode == "prepare":
            with open(os.environ["GITHUB_OUTPUT"], "a", encoding="utf-8") as output:
                output.write("head_sha=" + HEAD + "\ncoordinator_sha=" + BASE + "\nrequest_sha256=" + digest + "\n")
            print(json.dumps({"status": "REQUEST_VALIDATED", "request_sha256": digest}))
            return 0
        need(args.expected_request_sha == digest and args.candidate is not None and args.canonical is not None, "EXACT_PREPARED_INPUTS_REQUIRED")
        candidate, canonical = args.candidate.resolve(), args.canonical.resolve()
        need(candidate != canonical and git(candidate, "rev-parse", "HEAD^{tree}") == HEAD_TREE, "EXACT_CANDIDATE_TREE_REQUIRED")
        report["coordinator"] = verify_core(canonical, BASE)
        need(verify_core(candidate, HEAD) == report["coordinator"], "CANDIDATE_CANONICAL_CORE_DIFFERS")
        need(bool(os.environ.get("GITHUB_TOKEN")) and shutil.which("node") is not None, "REAL_PLATFORM_READ_CAPABILITY_REQUIRED")
        env = {key: val for key, val in os.environ.items() if key not in {"NODE_OPTIONS", "NODE_PATH", "NODE_EXTRA_CA_CERTS"}}
        result = subprocess.run(command(value, canonical), cwd=canonical, env=env, capture_output=True, timeout=600)
        report.update(canonical_result(result.returncode, result.stdout, result.stderr, value))
        report["canonical_exit"] = result.returncode
        need(verify_core(canonical, BASE) == report["coordinator"] and verify_core(candidate, HEAD) == report["coordinator"], "CANONICAL_CHANGED_DURING_READ")
        verify_source(source, os.environ["GITHUB_SHA"], value)
        need(request(args.request)[1] == digest, "REQUEST_CHANGED_DURING_READ")
        exit_code = 0 if report["status"] == "ADMITTED" else 1
    except Exception as error:
        report["status"] = "REFUSED"
        report["reason"] = str(error) if isinstance(error, Refusal) else "canonical_timeout" if isinstance(error, subprocess.TimeoutExpired) else "audit_error"
    if args.mode == "run" or report["status"] == "REFUSED":
        encoded = json.dumps(report, sort_keys=True) + "\n"
        need(len(encoded.encode()) <= 16384, "REPORT_BOUND")
        (Path(os.environ.get("GITHUB_WORKSPACE", ".")) / "forecast-cohort-admission-result.json").write_text(encoded, encoding="utf-8")
        print(encoded, end="")
    return exit_code


if __name__ == "__main__":
    raise SystemExit(main())
