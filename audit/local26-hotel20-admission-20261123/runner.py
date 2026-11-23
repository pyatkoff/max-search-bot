#!/usr/bin/env python3
"""One original native invocation of the unchanged BASE release admission CLI."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import time

REPOSITORY = "pyatkoff/max-search-bot"
BASE = "9addf04716e648793e47849247ea823168fdf5fe"
PR_NUMBER = 1655
EXPECTED_HEAD = "e7b6d04d10764486c0e2f5a4c9c2fc9a9ead2fbd"
EXPECTED_REQUEST_SHA256 = "64716a43f117c9abe1bf3a50c04537f5fc718664e8f7a7d657b971a477c1eabc"
BRANCH = "audit/local26-hotel20-admission-20261123"
WORKFLOW = ".github/workflows/local26-hotel20-admission-20261123.yml"
MESSAGE = "audit(hotel): verify local26 hotel20 release admission"
CORE = {
    "tools/release_priority.js": ("3c1b228d36785546d71fb9e21c68ee4b03961619", 23624),
    "tools/deployment_release.js": ("ce8ca9b741ee973b533a61b2dc5df3474939d01b", 133707),
    "tools/release_slot_close.js": ("a81a0ef78d30af8d7c2a55bd99b5c08718043015", 97640),
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


def request(path):
    value, raw = read_json(path, 2048)
    need(type(value) is dict and set(value) == {"version", "repository", "pr_number", "head_sha", "base_sha"}, "REQUEST_SHAPE")
    need(value["version"] == 1 and type(value["version"]) is int and value["repository"] == REPOSITORY
         and value["base_sha"] == BASE and type(value["pr_number"]) is int and value["pr_number"] == PR_NUMBER
         and type(value["head_sha"]) is str and re.fullmatch(r"[a-f0-9]{40}", value["head_sha"])
         and value["head_sha"] == EXPECTED_HEAD and value["head_sha"] != BASE, "EXACT_REVIEWED_PR_HEAD_BASE_REQUIRED")
    digest = hashlib.sha256(raw).hexdigest()
    need(digest == EXPECTED_REQUEST_SHA256, "IMMUTABLE_REQUEST_DIGEST_REQUIRED")
    return value, digest


def native_context(env):
    need(env.get("GITHUB_ACTIONS") == "true" and env.get("GITHUB_REPOSITORY") == REPOSITORY
         and env.get("GITHUB_EVENT_NAME") == "push" and env.get("GITHUB_ACTOR") == "pyatkoff"
         and env.get("GITHUB_RUN_ATTEMPT") == "1" and env.get("GITHUB_REF") == "refs/heads/" + BRANCH
         and env.get("GITHUB_WORKFLOW_REF") == REPOSITORY + "/" + WORKFLOW + "@refs/heads/" + BRANCH
         and env.get("GITHUB_JOB") == "canonical-readonly"
         and re.fullmatch(r"[a-f0-9]{40}", env.get("GITHUB_SHA", ""))
         and re.fullmatch(r"[1-9][0-9]*", env.get("GITHUB_RUN_ID", "")), "ORIGINAL_NATIVE_OWNER_PUSH_REQUIRED")
    event, _ = read_json(Path(env.get("GITHUB_EVENT_PATH", "")), 1048576)
    need(event.get("repository", {}).get("full_name") == REPOSITORY and event.get("ref") == "refs/heads/" + BRANCH
         and event.get("after") == env["GITHUB_SHA"] and event.get("deleted") is False
         and event.get("forced") is False and event.get("created") is True and event.get("before") == "0" * 40
         and event.get("sender", {}).get("login") == "pyatkoff"
         and event.get("head_commit", {}).get("id") == env["GITHUB_SHA"]
         and event.get("head_commit", {}).get("message") == MESSAGE, "ACTUAL_PUSH_IDENTITY_REQUIRED")


def git(root, *args):
    env = {key: value for key, value in os.environ.items() if not key.startswith("GIT_")}
    result = subprocess.run(["git", "-C", str(root), *args], env=env, capture_output=True, timeout=15)
    need(result.returncode == 0 and len(result.stdout) <= 65536 and len(result.stderr) <= 65536, "CHECKOUT_READ_FAILED")
    return result.stdout.decode("utf-8").strip()


def verify_source(source, audit_sha):
    need(git(source, "rev-parse", "HEAD") == audit_sha, "AUDIT_CHECKOUT_CHANGED")
    group = Path(__file__).resolve().parent
    need(group == source / "audit/local26-hotel20-admission-20261123", "AUDIT_SOURCE_LOCATION")
    manifest, _ = read_json(group / "source-pins.json", 16384)
    files = manifest.get("files")
    need(type(manifest) is dict and set(manifest) == {"version", "files"} and type(manifest["version"]) is int
         and manifest["version"] == 1 and type(files) is dict
         and set(files) == {"runner.py", "workflow.yml"}, "EXACT_SOURCE_PINS_REQUIRED")
    need(all(not p.is_symlink() for p in group.rglob("*")), "AUDIT_SYMLINK_REJECTED")
    actual = {p.relative_to(group).as_posix() for p in group.rglob("*") if p.is_file()}
    need(actual == set(files) | {"source-pins.json", "admission-request.json"}, "AUDIT_SOURCE_FILE_SET")
    for name, pin in files.items():
        need(type(name) is str and name and not Path(name).is_absolute() and ".." not in Path(name).parts
             and type(pin) is dict and set(pin) == {"sha256", "bytes"}
             and type(pin["sha256"]) is str and re.fullmatch(r"[a-f0-9]{64}", pin["sha256"])
             and type(pin["bytes"]) is int and pin["bytes"] > 0, "AUDIT_PIN_SHAPE")
        p = group / name
        need(not p.is_symlink() and hashlib.sha256(p.read_bytes()).hexdigest() == pin["sha256"]
             and p.stat().st_size == pin["bytes"], "AUDIT_SOURCE_CHANGED")
    need((source / WORKFLOW).is_file() and not (source / WORKFLOW).is_symlink()
         and (source / WORKFLOW).read_bytes() == (group / "workflow.yml").read_bytes(), "WORKFLOW_COPY_CHANGED")
    for path in [source / WORKFLOW] + sorted(p for p in group.rglob("*") if p.is_file()):
        raw = path.read_bytes()
        blob = hashlib.sha1(b"blob " + str(len(raw)).encode() + b"\0" + raw).hexdigest()
        need(git(source, "rev-parse", "HEAD:" + path.relative_to(source).as_posix()) == blob, "AUDIT_TRACKED_BYTES_CHANGED")


def verify_core(canonical):
    need(git(canonical, "rev-parse", "HEAD") == BASE, "BASE_COORDINATOR_CHANGED")
    result = {}
    for relative, (blob, size) in CORE.items():
        path = canonical / relative
        need(path.is_file() and not path.is_symlink(), "CANONICAL_SOURCE_MISSING")
        raw = path.read_bytes()
        observed = hashlib.sha1(b"blob " + str(len(raw)).encode() + b"\0" + raw).hexdigest()
        need(len(raw) == size and observed == blob and git(canonical, "rev-parse", "HEAD:" + relative) == blob,
             "CANONICAL_GIT_BLOB_CHANGED")
        result[relative] = {"git_blob": blob, "bytes": size, "sha256": hashlib.sha256(raw).hexdigest()}
    # All local requires in these immutable modules are these two neighbours;
    # the entire checkout is BASE, never a candidate-controlled module search.
    return result


def command(value, canonical):
    return ["node", str(canonical / "tools/release_priority.js"), "--admit", str(value["pr_number"]), value["head_sha"], value["base_sha"]]


def canonical_result(code, stdout, stderr):
    need(len(stdout) <= 8192 and len(stderr) <= 8192, "CANONICAL_OUTPUT_BOUND")
    line = stdout.decode("utf-8")
    decision = None
    if line.startswith("release-priority: ") and line.endswith("\n") and line.count("\n") == 1:
        decision = json.loads(line[len("release-priority: "):], object_pairs_hook=unique_object)
        need(type(decision) is dict and set(decision) == {"allowed", "reason", "holder_pr", "expires_at", "active_reservations"}
             and type(decision["allowed"]) is bool and type(decision["active_reservations"]) is int
             and decision["active_reservations"] >= 0 and decision["reason"] in
             {"no_active_reservation", "priority_owned", "earlier_ready_release"}
             and (decision["holder_pr"] is None or (type(decision["holder_pr"]) is int and decision["holder_pr"] > 0))
             and (decision["expires_at"] is None or (type(decision["expires_at"]) is str
                  and re.fullmatch(r"[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{3}Z", decision["expires_at"]))),
             "CANONICAL_DECISION_SHAPE")
    if code == 0:
        need(stderr == b"" and decision is not None and decision["allowed"] is True, "CANONICAL_SUCCESS_REQUIRED")
        return {"status": "ADMITTED", "decision": decision}
    if decision is not None:
        need(decision["allowed"] is False, "CANONICAL_EXIT_CONFLICT")
        return {"status": "REFUSED", "decision": decision}
    # Do not publish arbitrary stderr, raw API data or any log URL.
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
    report = {"schema": "local26-hotel20-release-admission-v1", "status": "NOT_RUN", "writes": False,
              "ssh": False, "publisher_acquired": False, "merge_performed": False, "canonical_invocations": 0}
    exit_code = 1
    try:
        native_context(os.environ)
        verify_source(args.source.resolve(), os.environ["GITHUB_SHA"])
        value, digest = request(args.request)
        report.update({"audit_sha": os.environ["GITHUB_SHA"], "run_id": int(os.environ["GITHUB_RUN_ID"]),
                       "attempt": 1, "job": os.environ["GITHUB_JOB"], "request": value, "request_sha256": digest})
        if args.mode == "prepare":
            with open(os.environ["GITHUB_OUTPUT"], "a", encoding="utf-8") as output:
                output.write("head_sha=" + value["head_sha"] + "\nbase_sha=" + BASE + "\nrequest_sha256=" + digest + "\n")
            print(json.dumps({"status": "REQUEST_VALIDATED", "request_sha256": digest}))
            return 0
        need(args.expected_request_sha == digest and args.candidate is not None and args.canonical is not None, "EXACT_PREPARED_INPUTS_REQUIRED")
        candidate, canonical = args.candidate.resolve(), args.canonical.resolve()
        need(candidate != canonical and git(candidate, "rev-parse", "HEAD") == value["head_sha"], "CANDIDATE_CHECKOUT_CHANGED")
        report["coordinator_source_sha"] = BASE
        report["admitted_base_sha"] = value["base_sha"]
        report["coordinator"] = verify_core(canonical)
        need(bool(os.environ.get("GITHUB_TOKEN")) and shutil.which("node") is not None, "REAL_PLATFORM_READ_CAPABILITY_REQUIRED")
        # Preserve all actual native workflow identity. Remove only Node preload
        # overrides; no event, publisher tuple, clock or API answer is fabricated.
        env = {key: val for key, val in os.environ.items() if key not in {"NODE_OPTIONS", "NODE_PATH", "NODE_EXTRA_CA_CERTS"}}
        began = time.monotonic()
        report["canonical_invocations"] = 1
        result = subprocess.run(command(value, canonical), cwd=canonical, env=env, capture_output=True, timeout=600)
        report.update(canonical_result(result.returncode, result.stdout, result.stderr))
        report["canonical_exit"] = result.returncode
        report["elapsed_ms"] = int((time.monotonic() - began) * 1000)
        report["coordinator_after"] = verify_core(canonical)
        need(report["coordinator"] == report["coordinator_after"], "COORDINATOR_CHANGED_DURING_READ")
        exit_code = 0 if report["status"] == "ADMITTED" else 1
    except subprocess.TimeoutExpired:
        report["status"] = "UNKNOWN"
        report["reason"] = "CANONICAL_READ_TIMEOUT_NO_REPLAY"
    except Exception as error:
        report["status"] = "REFUSED"
        report["reason"] = str(error) if isinstance(error, Refusal) else type(error).__name__
    report_path = Path(os.environ.get("GITHUB_WORKSPACE", ".")) / "local26-hotel20-admission-result.json"
    if args.mode == "run" or report["status"] == "REFUSED":
        encoded = json.dumps(report, sort_keys=True) + "\n"
        need(len(encoded.encode()) <= 16384, "REPORT_BOUND")
        report_path.write_text(encoded, encoding="utf-8")
        print(encoded, end="")
    return exit_code


if __name__ == "__main__":
    sys.exit(main())
