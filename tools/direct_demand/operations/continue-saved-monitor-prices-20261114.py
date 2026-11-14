#!/usr/bin/env python3
"""Bounded, sequential use of the unchanged default saved-price hook.

The existing workflow owns source/owner/install/host authentication. Every child
is one original SSH invocation, with its own hook lock and fresh saved snapshot.
No child is retried, resumed or killed to meet this runner's admission budget.
"""
import argparse
import hashlib
import json
import math
import os
from pathlib import Path
import re
import subprocess
import time

TOTAL_SECONDS = 1800
PASS_SECONDS = 480
FINISH_RESERVE = 300
PAIR_LIMIT = 30
MAX_PASSES = 21
ROSTER_COUNT = 604
APP_ROOT = "/var/www/anytoour/data/www/app.anytoour.ru"
HOOK_MARKER = b"MONITOR_POST_FEED_RESULT="
RESULT_MARKER = "MONITOR_SAVED_CONTINUATION_RESULT="
MAX_RESULT_BYTES = 16_000_000
COLLECTOR_ENV = ("ANYTOUR_MONITOR_COLLECTION_STARTED_AT", "ANYTOUR_MONITOR_COLLECTOR_RUN_ID", "ANYTOUR_MONITOR_HOOK_INSPECT")


class Stop(Exception):
    """A fixed safe reason; never carry SSH stderr or exception details."""


def need(condition, reason):
    if not condition:
        raise Stop(reason)


def integer(value, minimum=0, maximum=ROSTER_COUNT):
    return type(value) is int and minimum <= value <= maximum


def unique_object(pairs):
    result = {}
    for key, value in pairs:
        need(key not in result, "hook_result_duplicate_json_key")
        result[key] = value
    return result


def parse_result(raw_path, result_path, receipt):
    need(raw_path.stat().st_size <= MAX_RESULT_BYTES, "hook_stdout_oversize")
    raw = raw_path.read_bytes()
    candidates = [line[len(HOOK_MARKER):] for line in raw.splitlines() if line.startswith(HOOK_MARKER)]
    need(len(candidates) == 1, "hook_result_missing_or_ambiguous")
    # Preserve the complete original JSON before any parse or semantic rejection.
    with result_path.open("xb") as stream:
        stream.write(candidates[0] + b"\n")
    receipt["result_file"] = result_path.name
    receipt["result_sha256"] = hashlib.sha256(candidates[0]).hexdigest()
    try:
        result = json.loads(candidates[0], object_pairs_hook=unique_object,
                            parse_constant=lambda value: (_ for _ in ()).throw(Stop("hook_result_nonfinite_json")))
    except (ValueError, UnicodeError) as exc:
        raise Stop("hook_result_invalid_json") from exc
    need(type(result) is dict, "hook_result_invalid_shape")
    return result


def validate_result(result, expected_sha, seen_operations, seen_records):
    need(result.get("ok") is True, "hook_not_successful")
    need(result.get("mode") == "existing_monitor_post_feed_hook"
         and result.get("installed_sha") == expected_sha, "hook_mode_or_runtime_changed")
    need(result.get("budget_seconds") == PASS_SECONDS and type(result.get("budget_seconds")) is int
         and result.get("finish_reserve_seconds") == 0 and type(result.get("finish_reserve_seconds")) is int,
         "hook_default_policy_changed")
    need(not any(result.get(key) is not None for key in ("error", "child_failure", "preflight_block", "pending")),
         "hook_unresolved_outcome")
    need(result.get("error_counts") in ({}, []) and result.get("held_pairs") == 0
         and type(result.get("held_pairs")) is int, "hook_errors_or_holds")
    need(all(type(result.get(key)) is int and result[key] == 0
             for key in ("new_posts", "supplier_calls", "site_writes")), "hook_forbidden_effect")
    for key in ("updated_pairs", "unchanged_pairs", "eligible_pairs", "scanned_pairs", "remaining_unscanned_pairs", "roster_pairs"):
        need(integer(result.get(key)), "hook_invalid_counts")
    updated, scanned, remaining = (result[key] for key in ("updated_pairs", "scanned_pairs", "remaining_unscanned_pairs"))
    need(result["roster_pairs"] == ROSTER_COUNT and scanned + remaining == ROSTER_COUNT
         and updated <= PAIR_LIMIT and updated == result["eligible_pairs"]
         and updated + result["unchanged_pairs"] <= scanned, "hook_invalid_counts")
    updates, deferred = result.get("updates"), result.get("deferred")
    need(type(updates) is list and len(updates) == updated and type(deferred) is list
         and updated + result["unchanged_pairs"] + len(deferred) == scanned, "hook_invalid_counts")
    need(all(type(item) is dict and item.get("reason") in ("no_fresh_observation", "abandoned_before_write_record")
             for item in deferred), "hook_unexpected_deferred")
    need(integer(result.get("duration_seconds"), maximum=86400), "hook_invalid_duration")
    need(remaining == 0 or result.get("bounded_pass_stopped") is True, "hook_unexplained_partial_scan")
    operations, records, hotels, publications = set(), set(), set(), set()
    for update in updates:
        need(type(update) is dict, "hook_invalid_update")
        tv, record, operation = (update.get(key) for key in ("tourvisor_hotel_id", "record_id", "operation"))
        need(integer(tv, 1, 2**63 - 1) and type(record) is str and re.fullmatch(r"[a-f0-9]{64}", record)
             and operation == f"commercial-monitor-price-h{tv}-{record}", "hook_invalid_update_identity")
        need(operation not in seen_operations and record not in seen_records
             and operation not in operations and record not in records and tv not in hotels, "hook_duplicate_updated_record_or_operation")
        posts = update.get("posts")
        need(type(posts) is list and len(posts) == 2 and all(type(post) is dict for post in posts)
             and {post.get("platform") for post in posts} == {"max", "telegram"}, "hook_invalid_post_pair")
        for post in posts:
            need(post.get("state") == "sent" and post.get("existing_message_reused") is True
                 and post.get("body_photo_button_verified") is True
                 and integer(post.get("publication_id"), 1, 2**63 - 1)
                 and integer(post.get("version_id"), 1, 2**63 - 1)
                 and type(post.get("message_id")) is str and bool(post["message_id"])
                 and type(post.get("post_url")) is str and post["post_url"].startswith("https://"), "hook_unconfirmed_post")
            identity = (post["platform"], post["publication_id"])
            need(identity not in publications, "hook_duplicate_publication")
            publications.add(identity)
        feed = update.get("feed_operation")
        need(type(feed) is str and re.fullmatch(r"commercial-channel-hotel-feeds-[0-9]{8}-update[0-9]+", feed),
             "hook_invalid_feed_operation")
        need(feed.rsplit("update", 1)[1] == str(int(hashlib.sha256(operation.encode()).hexdigest()[:14], 16)),
             "hook_feed_operation_mismatch")
        operations.add(operation)
        records.add(record)
        hotels.add(tv)
    return operations, records, hotels


def run_continuation(output_dir, expected_sha, child, clock=time.monotonic):
    """Inject only clock/child in offline tests; the same admission/validation runs."""
    need(type(expected_sha) is str and re.fullmatch(r"[a-f0-9]{40}", expected_sha), "expected_runtime_invalid")
    output_dir = Path(output_dir)
    output_dir.mkdir(mode=0o700, parents=True, exist_ok=True)
    need(output_dir.is_dir() and not output_dir.is_symlink(), "output_directory_invalid")
    # A second invocation in the same artifact directory is never a resume.
    try:
        with (output_dir / "continuation-started.json").open("x") as stream:
            json.dump({"installed_sha": expected_sha, "total_budget_seconds": TOTAL_SECONDS}, stream)
    except FileExistsError as exc:
        raise Stop("continuation_output_already_used") from exc
    started = clock()
    summary = {"ok": False, "mode": "bounded_saved_monitor_continuation", "installed_sha": expected_sha,
               "total_budget_seconds": TOTAL_SECONDS, "pass_budget_seconds": PASS_SECONDS,
               "finish_reserve_seconds": FINISH_RESERVE, "max_passes": MAX_PASSES,
               "passes_attempted": 0, "passes_accepted": 0, "updated_pairs": 0, "unique_updated_hotels": 0,
               "scanned_pairs_total": 0, "last_accepted_remaining_unscanned_pairs": None,
               "counts_from_accepted_passes_only": True, "scan_complete": False, "partial": True,
               "stop_reason": "in_progress", "passes": []}
    operations, records, hotels = set(), set(), set()

    def save():
        elapsed = clock() - started
        need(math.isfinite(elapsed) and elapsed >= 0, "continuation_clock_invalid")
        summary["duration_seconds"] = round(elapsed, 3)
        summary["budget_overrun_seconds"] = round(max(0, elapsed - TOTAL_SECONDS), 3)
        path = output_dir / "continuation.json"
        temporary = output_dir / "continuation.json.tmp"
        temporary.write_text(json.dumps(summary, ensure_ascii=False, indent=2) + "\n")
        temporary.replace(path)

    def finish(reason, successful, complete=False):
        summary.update(ok=successful, stop_reason=reason, scan_complete=complete, partial=not complete)
        # Missing/failed child evidence is not a fabricated zero effect count.
        for key in ("new_posts", "supplier_calls", "site_writes"):
            summary[key] = 0 if successful else None
        save()
        return summary

    save()
    for number in range(1, MAX_PASSES + 1):
        elapsed = clock() - started
        need(math.isfinite(elapsed) and elapsed >= 0, "continuation_clock_invalid")
        if elapsed + PASS_SECONDS + FINISH_RESERVE > TOTAL_SECONDS:
            return finish("aggregate_admission_deadline", True)
        raw_path = output_dir / f"pass-{number:02d}.stdout"
        result_path = output_dir / f"pass-{number:02d}.json"
        receipt = {"pass": number, "stdout_file": raw_path.name, "status": "unknown_until_result"}
        summary["passes"].append(receipt)
        summary["passes_attempted"] += 1
        save()
        try:
            with raw_path.open("xb") as stream:
                try:
                    returncode = child(number, stream)
                except Exception:
                    returncode = None
                stream.flush()
            receipt["ssh_exit_code"] = returncode
            result = parse_result(raw_path, result_path, receipt)
            need(type(returncode) is int and returncode == 0, "ssh_failed_or_unknown")
            new_ops, new_records, new_hotels = validate_result(result, expected_sha, operations, records)
        except Stop as exc:
            receipt.update(status="rejected", error=str(exc))
            summary["error"] = str(exc)
            return finish("first_failed_or_unknown_pass", False)
        except Exception:
            receipt.update(status="rejected", error="continuation_capture_failed")
            summary["error"] = "continuation_capture_failed"
            return finish("first_failed_or_unknown_pass", False)
        operations.update(new_ops)
        records.update(new_records)
        hotels.update(new_hotels)
        receipt.update(status="accepted", updated_pairs=result["updated_pairs"], scanned_pairs=result["scanned_pairs"],
                       remaining_unscanned_pairs=result["remaining_unscanned_pairs"], duration_seconds=result["duration_seconds"])
        summary["passes_accepted"] += 1
        summary["updated_pairs"] += result["updated_pairs"]
        summary["unique_updated_hotels"] = len(hotels)
        summary["scanned_pairs_total"] += result["scanned_pairs"]
        summary["last_accepted_remaining_unscanned_pairs"] = result["remaining_unscanned_pairs"]
        save()
        if result["remaining_unscanned_pairs"] == 0:
            return finish("scan_complete", True, True)
        if result["updated_pairs"] < PAIR_LIMIT:
            return finish("partial_pass_budget_stopped", True)
    return finish("maximum_passes_reached", True)


def make_ssh_child(args, environment):
    expected = environment.get("EXPECTED_PRODUCTION_SHA", "")
    host, user = environment.get("DEPLOY_HOST", ""), environment.get("DEPLOY_USER", "")
    need(re.fullmatch(r"[a-f0-9]{40}", expected), "expected_runtime_invalid")
    need(re.fullmatch(r"[A-Za-z0-9_.:-]+", host) and not host.startswith("-"), "ssh_host_invalid")
    need(re.fullmatch(r"[A-Za-z0-9_-]+", user), "ssh_user_invalid")
    for path in (args.hook, args.key, args.known_hosts):
        need(path.is_file() and not path.is_symlink(), "existing_input_file_invalid")
    hook = args.hook.read_bytes()
    need(hook.startswith(b"<?php\ndeclare(strict_types=1);\n") and len(hook) <= 12_000_000, "validated_hook_invalid")
    child_env = {key: value for key, value in environment.items() if key not in COLLECTOR_ENV}
    # Values interpolated here are either fixed paths or a validated hexadecimal SHA.
    command = (f"set -e; cd {APP_ROOT}; current_head=$(git rev-parse --verify HEAD); test \"$current_head\" = '{expected}'; "
               "tracked_status=$(git --no-optional-locks status --porcelain --untracked-files=no); test -z \"$tracked_status\"; "
               "unset " + " ".join(COLLECTOR_ENV) + "; php")
    argv = ["ssh", "-i", str(args.key), "-o", "BatchMode=yes", "-o", "ConnectTimeout=10",
            "-o", "ConnectionAttempts=1", "-o", "IdentitiesOnly=yes", "-o", "StrictHostKeyChecking=yes",
            "-o", "GlobalKnownHostsFile=/dev/null", "-o", "UserKnownHostsFile=" + str(args.known_hosts),
            user + "@" + host, command]

    def invoke(number, stdout):
        # No timeout, retry, alternate transport, shell=True, helper or collector.
        return subprocess.run(argv, input=hook, stdout=stdout, stderr=subprocess.DEVNULL,
                              env=child_env, check=False).returncode
    return expected, invoke


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    for option in ("hook", "output-dir", "key", "known-hosts"):
        parser.add_argument("--" + option, type=Path, required=True)
    args = parser.parse_args()
    os.umask(0o077)
    try:
        expected, child = make_ssh_child(args, dict(os.environ))
        summary = run_continuation(args.output_dir, expected, child)
    except Exception as exc:
        summary = {"ok": False, "mode": "bounded_saved_monitor_continuation", "scan_complete": False,
                   "partial": True, "error": str(exc) if isinstance(exc, Stop) else "continuation_local_failure"}
    print(RESULT_MARKER + json.dumps(summary, ensure_ascii=False, separators=(",", ":")))
    return 0 if summary["ok"] else 2


if __name__ == "__main__":
    raise SystemExit(main())
