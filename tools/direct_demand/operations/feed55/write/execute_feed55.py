"""Finite, no-retry production executor for the exact FEED55 plan.

Preparation and each group are separate workflow steps.  Every native write
requires a fresh owner permit bound to the current run, plan, group, public
preimage and private route proof.  An unknown result stops the chain.
"""
from __future__ import annotations

import datetime
import hashlib
import json
import os
import pathlib
import re
import subprocess
import sys
import time
import urllib.request
import xml.etree.ElementTree as ET

import owner_permit_gate as gate

BRANCH = "audit/price660-27316-resume-readiness-20261117"
REPOSITORY = "pyatkoff/max-search-bot"
PRIVATE_REPOSITORY = "pyatkoff/tour-max"
ORDER = (1, 2, 4, 5, 9, 12, 13)
MAX_WAIT_SECONDS = 120
MAX_POLLS = 12
URLS = {
    "hotel-posts-telegram.xml": "https://app.anytoour.ru/feed/hotel-posts-telegram.xml",
    "hotel-posts-max.xml": "https://app.anytoour.ru/feed/hotel-posts-max.xml",
    "hotel-posts-all.xml": "https://app.anytoour.ru/feed/hotel-posts-all.xml",
}
DERIVED_URL = "https://app.anytoour.ru/hotel-posts-telegram-main19.php"


def need(value, reason):
    if not value:
        raise ValueError(reason)


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def compact(value):
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode()


def save(path, value):
    path.write_bytes(compact(value))


def api(path):
    result = subprocess.run(
        ["gh", "api", "--method", "GET", "repos/" + REPOSITORY + "/" + path],
        capture_output=True,
        timeout=90,
    )
    need(result.returncode == 0, "github_read_failed")
    return json.loads(result.stdout)


def fetch(url):
    request = urllib.request.Request(url, headers={"User-Agent": "AnyTour-FEED55/1"})
    with urllib.request.urlopen(request, timeout=45) as response:
        need(response.status == 200, "public_feed_http_changed")
        raw = response.read(12_000_001)
    need(0 < len(raw) <= 12_000_000, "public_feed_size_changed")
    return raw


def inspect_xml(raw):
    root = ET.fromstring(raw)
    offers = root.findall(".//offer")
    ids = [item.attrib.get("id") for item in offers]
    need(None not in ids and len(ids) == len(set(ids)), "public_offer_ids_invalid")
    return {"sha256": sha(raw), "offers": len(offers), "unique": len(ids)}


def public_snapshot():
    result = {}
    for name, url in URLS.items():
        raw = fetch(url)
        result[name] = inspect_xml(raw)
    return result


def timestamp(value):
    parsed = datetime.datetime.fromisoformat(value.replace("Z", "+00:00"))
    need(parsed.tzinfo is not None, "route_timestamp_timezone_required")
    return parsed


def origin(config):
    need(
        os.environ.get("GITHUB_REPOSITORY") == REPOSITORY
        and os.environ.get("GITHUB_ACTOR") == "pyatkoff"
        and os.environ.get("GITHUB_EVENT_NAME") == "push"
        and os.environ.get("GITHUB_RUN_ATTEMPT") == "1",
        "one_shot_origin_required",
    )
    event = json.loads(pathlib.Path(os.environ["GITHUB_EVENT_PATH"]).read_bytes())
    head = os.environ["GITHUB_SHA"]
    run_id = int(os.environ["GITHUB_RUN_ID"])
    need(event["before"] == config["parent_sha"], "event_parent_changed")
    need(subprocess.check_output(["git", "rev-parse", "HEAD"], text=True).strip() == head, "checkout_head_changed")
    need(subprocess.check_output(["git", "rev-parse", "HEAD^"], text=True).strip() == config["parent_sha"], "checkout_parent_changed")
    need(api("git/ref/heads/" + BRANCH)["object"]["sha"] == head, "carrier_head_changed")
    run = api("actions/runs/" + str(run_id))
    need(run["head_sha"] == head and run["run_attempt"] == 1 and run["event"] == "push", "authenticated_run_changed")
    need(api("git/ref/heads/main")["object"]["sha"] == config["runtime_sha"], "runtime_main_changed")
    for key in ("transfer", "acceptance"):
        descriptor = config[key]
        comment = api("issues/comments/" + str(descriptor["comment_id"]))
        need(comment["user"]["id"] == 226193297 and sha(comment["body"].encode()) == descriptor["body_sha256"], key + "_claim_changed")
    return {"repository": REPOSITORY, "run_id": run_id, "head_sha": head, "run_attempt": 1}


def public_exclusive(current_run):
    for status in gate.STATUSES:
        for repository, workflow_only in (
            (REPOSITORY, False),
            ("pyatkoff/poisk-turov-test", True),
        ):
            suffix = "actions/workflows/346454799/runs?" if workflow_only else "actions/runs?"
            result = subprocess.run(
                ["gh", "api", "--method", "GET", "repos/" + repository + "/" + suffix + "status=" + status + "&per_page=100"],
                capture_output=True,
                timeout=90,
            )
            need(result.returncode == 0, "public_run_list_failed")
            data = json.loads(result.stdout)
            runs = data["workflow_runs"]
            need(data["total_count"] == len(runs) and data["total_count"] <= 100, "ambiguous_public_runs")
            competing = [item for item in runs if not (repository == REPOSITORY and item["id"] == current_run)]
            need(not competing, "competing_public_writer_or_collector")


def validate_route_jit(config, now):
    route = config["route_jit"]
    need(
        route["state"] == "ROOT_VERIFIED_FEED55_ROUTE_JIT"
        and route["hotels"] == 55
        and route["map_count"] == 1055
        and re.fullmatch(r"[a-f0-9]{64}", route["proof_sha256"])
        and re.fullmatch(r"[a-f0-9]{40}", route["private_branch_sha"]),
        "route_jit_scope_changed",
    )
    observed = timestamp(route["observed_at"])
    expires = timestamp(route["expires_at"])
    need(0 <= (now - observed).total_seconds() <= 900 and now <= expires and 0 < (expires - observed).total_seconds() <= 900, "route_jit_expired_or_future")


def require_snapshot(snapshot, expected, pairs):
    factor = {"hotel-posts-telegram.xml": 1, "hotel-posts-max.xml": 1, "hotel-posts-all.xml": 2}
    for name in URLS:
        need(snapshot[name]["sha256"] == expected[name], "public_preimage_changed_" + name)
        need(snapshot[name]["offers"] == snapshot[name]["unique"] == pairs * factor[name], "public_count_changed_" + name)


def ssh_php(program):
    temp = pathlib.Path(os.environ["RUNNER_TEMP"])
    host = os.environ["DEPLOY_HOST"]
    user = os.environ["DEPLOY_USER"]
    need(re.fullmatch(r"[A-Za-z0-9_.:-]+", host) and not host.startswith("-"), "ssh_host_invalid")
    need(re.fullmatch(r"[A-Za-z0-9_-]+", user), "ssh_user_invalid")
    command = [
        "ssh", "-i", str(temp / "channel_key"), "-o", "BatchMode=yes",
        "-o", "ConnectTimeout=10", "-o", "ServerAliveInterval=15",
        "-o", "ServerAliveCountMax=2", "-o", "IdentitiesOnly=yes",
        "-o", "StrictHostKeyChecking=yes", "-o", "GlobalKnownHostsFile=/dev/null",
        "-o", "UserKnownHostsFile=" + str(temp / "channel_known_hosts"),
        user + "@" + host,
        "unset GIT_DIR GIT_WORK_TREE GIT_COMMON_DIR GIT_INDEX_FILE GIT_CONFIG_PARAMETERS GIT_CONFIG_COUNT GIT_OBJECT_DIRECTORY GIT_ALTERNATE_OBJECT_DIRECTORIES; cd /var/www/anytoour/data/www/app.anytoour.ru && GIT_OPTIONAL_LOCKS=0 php",
    ]
    return subprocess.run(command, input=program, capture_output=True, timeout=120)


def validate_receipt(receipt, group):
    need(
        receipt.get("ok") is True
        and receipt.get("operation") == group["operation"]
        and receipt.get("mode") == "publish_three_static_public_direct_yml_feeds",
        "native_writer_not_complete",
    )
    need(all(receipt.get(key) == 0 for key in ("db_writes", "provider_calls", "message_sends")), "native_writer_boundary_changed")
    got = receipt.get("files", [])
    need([item["name"] for item in got] == list(URLS), "native_writer_file_scope_changed")
    for actual, expected in zip(got, group["files"]):
        need(
            all(actual.get(key) == expected[key] for key in ("name", "sha256", "bytes", "rows"))
            and actual.get("write_action") == "replace",
            "native_writer_file_mismatch",
        )


def paths():
    root = pathlib.Path(os.environ["RUNNER_TEMP"]) / "feed55-production"
    return root, root / "state.json"


def prepare(config_path, compiled):
    config = json.loads(config_path.read_bytes())
    execution = origin(config)
    public_exclusive(execution["run_id"])
    validate_route_jit(config, datetime.datetime.now(datetime.timezone.utc))
    intent_raw = (compiled / "intent.json").read_bytes()
    intent = json.loads(intent_raw)
    need(intent["state"] == "FEED55_PREPARED_NOT_WRITTEN" and intent["hotels"] == 55, "compiled_plan_scope_changed")
    need([item["batch"] for item in intent["groups"]] == list(ORDER), "compiled_plan_order_changed")
    root, state_path = paths()
    root.mkdir(mode=0o700, exist_ok=False)
    (root / "config.json").write_bytes(config_path.read_bytes())
    (root / "intent.json").write_bytes(intent_raw)
    snapshot = public_snapshot()
    expected = intent["groups"][0]["previous_feed_sha256"]
    require_snapshot(snapshot, expected, 1000)
    anchor = sha(compact({"kind": "FEED55_INITIAL_PUBLIC_PREIMAGE_ANCHOR", "files": expected}))
    state = {
        "next_index": 0, "pairs": 1000, "completed": [], "expected_feeds": expected,
        "plan_sha256": sha(intent_raw), "previous_native_receipt_sha256": anchor,
        "execution": execution,
    }
    save(state_path, state)
    deliver = root / "deliver-plan"
    deliver.mkdir(mode=0o700)
    save(deliver / "plan.json", {
        "state": "FEED55_PRODUCTION_PLAN_READY_NO_WRITES", "execution": execution,
        "plan_sha256": state["plan_sha256"], "initial_preimages": expected,
        "groups": [{key: group[key] for key in ("batch", "operation", "hotels", "script_sha256", "files")} for group in intent["groups"]],
        "permit_comment_id": config["permit_comment_id"], "permit_ttl_seconds": 60,
        "route_jit": config["route_jit"], "writes": 0,
    })
    print("FEED55_PRODUCTION_PLAN_READY_NO_WRITES")


def step(index):
    root, state_path = paths()
    config = json.loads((root / "config.json").read_bytes())
    state = json.loads(state_path.read_bytes())
    intent_raw = (root / "intent.json").read_bytes()
    intent = json.loads(intent_raw)
    need(index == state["next_index"] and state["plan_sha256"] == sha(intent_raw), "step_replay_or_plan_changed")
    execution = state["execution"]
    need(
        execution["run_id"] == int(os.environ["GITHUB_RUN_ID"])
        and execution["head_sha"] == os.environ["GITHUB_SHA"],
        "local_execution_context_changed",
    )
    group = intent["groups"][index]
    need(group["batch"] == ORDER[index], "group_order_changed")
    need(state["pairs"] == 1000 + sum(item["hotels"] for item in intent["groups"][:index]), "pair_progress_changed")
    validate_route_jit(config, datetime.datetime.now(datetime.timezone.utc))
    public_exclusive(execution["run_id"])
    require_snapshot(public_snapshot(), state["expected_feeds"], state["pairs"])
    program_path = pathlib.Path(os.environ["RUNNER_TEMP"]) / "feed55" / "compiled" / ("p%02d" % group["batch"]) / "write.php"
    program = program_path.read_bytes()
    need(sha(program) == group["script_sha256"], "compiled_writer_changed")
    expected = {
        "permit_comment_id": config["permit_comment_id"],
        "private_branch_sha": config["route_jit"]["private_branch_sha"],
        "repository": REPOSITORY, "execution_run_id": execution["run_id"],
        "execution_head_sha": execution["head_sha"], "run_attempt": 1,
        "batch": group["batch"], "operation": group["operation"],
        "claim_id": config["acceptance"]["comment_id"],
        "plan_sha256": state["plan_sha256"], "script_sha256": group["script_sha256"],
        "preimage_sha256": state["expected_feeds"],
        "previous_native_receipt_sha256": state["previous_native_receipt_sha256"],
        "private_route_proof_sha256": config["route_jit"]["proof_sha256"],
    }
    expected["nonce"] = sha(compact({key: value for key, value in expected.items() if key != "permit_comment_id"}))
    evidence = root / "progress" / ("p%02d" % group["batch"])
    evidence.mkdir(mode=0o700, parents=True)
    save(evidence / "waiting.json", {"expected": expected, "bounded_wait_seconds": MAX_WAIT_SECONDS, "writer_started": False})
    deadline = time.monotonic() + MAX_WAIT_SECONDS
    permit = None
    polls = 0
    while time.monotonic() < deadline and polls < MAX_POLLS:
        polls += 1
        comment = api("issues/comments/" + str(config["permit_comment_id"]))
        try:
            permit = gate.admit(comment, expected, datetime.datetime.now(datetime.timezone.utc))
        except (ValueError, KeyError, TypeError, json.JSONDecodeError):
            permit = None
            if polls < MAX_POLLS and time.monotonic() < deadline:
                time.sleep(10)
            continue
        public_exclusive(execution["run_id"])
        require_snapshot(public_snapshot(), state["expected_feeds"], state["pairs"])
        latest = api("issues/comments/" + str(config["permit_comment_id"]))
        permit = gate.admit(latest, expected, datetime.datetime.now(datetime.timezone.utc))
        break
    need(permit is not None, "bounded_owner_permit_timeout_no_write")
    with (evidence / "started.json").open("x") as stream:
        json.dump({"expected": expected, "permit": permit, "no_retry": True}, stream)
        stream.flush()
        os.fsync(stream.fileno())
    try:
        result = ssh_php(program)
        (evidence / "native-result.json").write_bytes(result.stdout)
        need(result.returncode == 0, "native_writer_failed_no_retry")
        receipt = json.loads(result.stdout)
        validate_receipt(receipt, group)
        next_expected = {item["name"]: item["sha256"] for item in group["files"]}
        next_pairs = state["pairs"] + group["hotels"]
        observed = None
        for _ in range(6):
            try:
                observed = public_snapshot()
                require_snapshot(observed, next_expected, next_pairs)
                break
            except ValueError:
                observed = None
                time.sleep(10)
        need(observed is not None, "public_readback_not_observed_stop_no_retry")
        receipt_sha = sha(result.stdout)
        state.update(
            next_index=index + 1, pairs=next_pairs, expected_feeds=next_expected,
            previous_native_receipt_sha256=receipt_sha,
        )
        state["completed"].append(group["batch"])
        save(state_path, state)
        save(evidence / "verified.json", {
            "ok": True, "batch": group["batch"], "operation": group["operation"],
            "pairs": next_pairs, "native_result_sha256": receipt_sha,
            "permit_body_sha256": permit["permit_body_sha256"], "files": group["files"],
        })
    except Exception:
        save(evidence / "STOP_NO_RETRY.json", {
            "operation": group["operation"], "state": "INSPECT_PUBLIC_AND_NATIVE_RESULT_BEFORE_SUCCESSOR",
            "completed": state["completed"],
        })
        raise
    print("FEED55_STEP_VERIFIED batch=%d pairs=%d" % (group["batch"], next_pairs))


def finish():
    root, state_path = paths()
    state = json.loads(state_path.read_bytes())
    need(state["next_index"] == 7 and state["completed"] == list(ORDER) and state["pairs"] == 1055, "feed55_chain_incomplete")
    require_snapshot(public_snapshot(), state["expected_feeds"], 1055)
    derived = fetch(DERIVED_URL)
    inspection = inspect_xml(derived)
    need(inspection["offers"] == inspection["unique"] == 1055, "derived_offer_count_changed")
    need(derived.count(b"https://t.me/Any_tour_bot?startapp=") == 1055, "derived_miniapp_url_count_changed")
    complete = {
        "ok": True, "state": "FEED55_NATIVE_AND_PUBLIC_VERIFIED", "pairs": 1055,
        "max": 1055, "telegram": 1055, "all": 2110, "derived_miniapp_urls": 1055,
        "hotels": 55, "assignments": 110, "groups": list(ORDER),
        "original_1000_preserved": True, "new_posts": 0, "provider_calls": 0,
        "derived_sha256": inspection["sha256"],
    }
    save(root / "complete.json", complete)
    print("FEED55_CHAIN_COMPLETE pairs=1055 hotels=55 assignments=110")


def main():
    os.umask(0o077)
    mode = sys.argv[1]
    if mode == "prepare":
        prepare(pathlib.Path(sys.argv[2]), pathlib.Path(sys.argv[3]))
    elif mode == "step":
        step(int(sys.argv[2]))
    elif mode == "finish":
        finish()
    else:
        raise ValueError("mode_invalid")


if __name__ == "__main__":
    try:
        main()
    except Exception as error:
        code = str(error) if isinstance(error, ValueError) and re.fullmatch(r"[a-z0-9_.-]{1,140}", str(error)) else type(error).__name__
        print("FEED55_STOP " + code)
        sys.exit(2)
