#!/usr/bin/env python3
"""Verify historical receipts and rebind ONLY untouched operations 17–46; runner/local files only."""
from __future__ import annotations

import argparse
import base64
import copy
import hashlib
import json
import os
from pathlib import Path
import re
import runpy

FIRST, LAST = 17, 46
BRIDGE = "7538c0798d37c1530a519c3164c6858ff128f16da933c456ce150cc439dd493c"
PREFIX = "commercial-short-heading-20261108"
BRANCH = "audit/content100-bot-publication-20261101"
WORKFLOW = ".github/workflows/content100-bot-publication-20261101.yml"


def need(ok, reason):
    if not ok:
        raise ValueError(reason)


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def obj(raw):
    value = json.loads(raw)
    need(isinstance(value, dict), "json_object_required")
    return value


def encoded(value):
    return (json.dumps(value, ensure_ascii=False, indent=2) + "\n").encode()


def configuration():
    raw = base64.b64decode(os.environ["CONTINUATION_BINDINGS_BASE64"], validate=True)
    need(sha(raw) == os.environ["CONTINUATION_BINDINGS_SHA256"], "continuation_bindings_changed")
    value = obj(raw)
    need(value["current_source"] == os.environ["EXPECTED_PRODUCTION_SHA"]
         and value["continuation_index_sha256"] == os.environ["PREPARED_INDEX_SHA256"],
         "continuation_runtime_binding_changed")
    return value


def modules(ops):
    return (runpy.run_path(str(ops / "prepare-title-only.py")),
            runpy.run_path(str(ops / "verify-title-bulk-packet.py")))


def original_packet(members, config, builder):
    raw_index = members["manifests/plan-index.json"]
    need(sha(raw_index) == config["original_index_sha256"], "original_index_changed")
    old_snapshot_raw = members["snapshot.json"]
    manifests, index = builder["build"](obj(old_snapshot_raw), old_snapshot_raw, config["original_source"], PREFIX, 20)
    need(raw_index == builder["encoded"](index) and len(manifests) == 47,
         "original_packet_not_exact_builder_output")
    allow = {"snapshot.json", "manifests/plan-index.json", "intent.safe.json"}
    for number, (manifest, entry) in enumerate(zip(manifests, index["manifests"])):
        filename = "manifests/" + entry["file"]
        allow.add(filename)
        need(members.get(filename) == builder["encoded"](manifest)
             and sha(members[filename]) == entry["sha256"], "original_manifest_chain_changed")
        need(entry["posts"] == (2 if number == 0 else 14 if number == 46 else 20), "original_batch_scope_changed")
    need(set(members) == allow and index["manifests"][0]["sha256"] == config["canary_manifest_sha256"]
         and index["manifests"][16]["sha256"] == BRIDGE, "original_canary_or_bridge_changed")
    intent = obj(members["intent.safe.json"])
    need(intent.get("operation") == PREFIX + "-canary-v1" and intent.get("state") == "PREPARED_NOT_EXECUTED"
         and intent.get("manifest_sha256") == config["canary_manifest_sha256"]
         and intent.get("expected_installed_sha") == config["original_source"]
         and intent.get("post_count") == 2 and intent.get("once_no_replay") is True, "original_intent_changed")
    return manifests, index


def prior_receipts(members, canary_members, manifests, index, config, bulk):
    allow = {"admission.safe.json", "summary.safe.json"}
    for number in range(1, FIRST):
        allow.update((f"batch{number:03d}.started.safe.json", f"batch{number:03d}.result.safe.json"))
    need(set(members) == allow, "prior_archive_has_unexpected_or_dispatched_target")
    summary = obj(members["summary.safe.json"])
    expected = {"ok": False, "validated_bulk_posts": 320, "posts_in_completed_batches": 320,
                "confirmed_posts_in_incomplete_batches": 0, "validated_bulk_batches": list(range(1, FIRST)),
                "unresolved_dispatched_batches": [], "not_dispatched_batches": list(range(FIRST, LAST+1)),
                "original_canary_posts": 2, "final_read_verified": False, "final_verified_posts": 0}
    need(all(summary.get(key) == value and type(summary.get(key)) is type(value) for key, value in expected.items()),
         "prior_summary_does_not_prove_exact_unstarted_scope")
    admission = obj(members["admission.safe.json"])
    need(admission.get("ok") is True and admission.get("source") == config["original_source"]
         and admission.get("canary_run_id") == config["canary"]["run_id"]
         and admission.get("canary_commit_sha") == config["canary"]["commit"]
         and admission.get("prepared_artifact_id") == config["prepared"]["artifact_id"]
         and admission.get("zip_sha256") == config["prepared"]["zip_sha256"]
         and admission.get("index_sha256") == config["original_index_sha256"]
         and admission.get("canary_manifest_sha256") == config["canary_manifest_sha256"], "prior_admission_changed")
    confirmed = {}
    for number in range(1, FIRST):
        manifest, entry = manifests[number], index["manifests"][number]
        started = obj(members[f"batch{number:03d}.started.safe.json"])
        result = obj(members[f"batch{number:03d}.result.safe.json"])
        for record in (started, result):
            need(record.get("operation") == manifest["operation"] and record.get("manifest_sha256") == entry["sha256"]
                 and record.get("batch_index") == number and record.get("expected_installed_sha") == config["original_source"],
                 "prior_batch_binding_changed")
        need(started.get("state") == "SSH_DISPATCH_STARTED_OUTCOME_PENDING" and started.get("post_count") == 20
             and started.get("once_no_replay") is True, "prior_start_marker_changed")
        need(result.get("validated") is True and result.get("state") == "CONFIRMED"
             and result.get("runtime_reported_ok") is True and result.get("ssh_status") == 0
             and result.get("expected_posts") == result.get("confirmed_posts") == 20
             and all(type(result.get("reported_"+key)) is int and result["reported_"+key] == 20
                     for key in ("preflight_existing_messages", "post_write_attempts", "title_updates"))
             and all(type(result.get("reported_"+key)) is int and result["reported_"+key] == 0
                     for key in bulk["ZERO_FIELDS"]), "prior_batch_not_fully_confirmed")
        rows = {row["publication_id"]: row for row in manifest["rows"]}
        need(isinstance(result.get("posts"), list) and len(result["posts"]) == 20, "prior_receipts_missing")
        seen = set()
        for receipt in result["posts"]:
            pid = receipt.get("publication_id") if isinstance(receipt, dict) else None
            need(type(pid) is int and pid in rows and pid not in seen and pid not in confirmed
                 and bulk["receipt_matches"](receipt, rows[pid]), "prior_receipt_selector_changed")
            seen.add(pid); confirmed[pid] = receipt
    need(set(canary_members) == {"short-title-canary.safe.json"}, "canary_result_archive_changed")
    canary = obj(canary_members["short-title-canary.safe.json"])
    checked = bulk["safe_runtime_result"](json.dumps(canary).encode(), canary.get("ssh_status"),
                                          manifests[0], index["manifests"][0], config["original_source"])
    need(checked["validated"] and len(checked["posts"]) == 2, "original_canary_receipt_not_confirmed")
    for receipt in checked["posts"]:
        need(receipt["publication_id"] not in confirmed, "canary_overlaps_completed_bulk")
        confirmed[receipt["publication_id"]] = receipt
    need(len(confirmed) == 322, "completed_scope_not_322")
    return confirmed


def reconcile_fresh(fresh_raw, config, manifests, completed, builder):
    need(sha(fresh_raw) == config["fresh"]["json_sha256"], "fresh_snapshot_bytes_changed")
    fresh = obj(fresh_raw)
    posts = builder["validate_snapshot"](fresh, config["current_source"])
    need(fresh.get("unstarted_operation_directories_absent") == list(range(FIRST, LAST+1)),
         "unstarted_operation_directory_proof_missing")
    originals = {row["publication_id"]: row for manifest in manifests for row in manifest["rows"]}
    need(len(originals) == 916 and len(completed) == 322, "reconciliation_scope_changed")
    stable = ("hotel_id", "platform", "publication_id", "message_id", "post_url", "source_key",
              "config_version", "photo_signature", "button_url_sha256")
    pending_ids = {row["publication_id"] for manifest in manifests[FIRST:] for row in manifest["rows"]}
    need(len(pending_ids) == 594 and pending_ids.isdisjoint(completed), "pending_scope_overlaps_completed")
    for post in posts:
        pid = post["publication_id"]
        need(pid in originals, "unexpected_fresh_publication")
        row = originals[pid]
        need(all(post.get(key) == row[key] and type(post.get(key)) is type(row[key]) for key in stable),
             "fresh_identity_photo_button_or_config_changed")
        if pid in completed:
            receipt = completed[pid]
            need(post["version_id"] == receipt["version_id"] and post["caption"] == row["new_text"]
                 and post["caption_sha256"] == row["new_text_sha256"]
                 and post["attachments_sha256"] == receipt["attachments_sha256"]
                 and post["button_observed_in_snapshot"] is True, "completed_post_changed_after_receipt")
        else:
            need(pid in pending_ids and post["version_id"] == row["version_id"]
                 and post["caption"] == row["old_text"] and post["caption_sha256"] == row["old_text_sha256"]
                 and post["attachments_sha256"] == row["attachments_sha256"]
                 and post["button_observed_in_snapshot"] is row["button_observed_in_snapshot"],
                 "unstarted_post_changed_from_original_approval")
    need(sum(bool(re.search(r" с перел[её]том\Z", post["caption"].partition("\n")[0])) for post in posts) == 594,
         "fresh_short_long_counts_changed")
    return fresh


def derive(original_members, prior_members, canary_members, fresh_raw, config, builder, bulk):
    manifests, old_index = original_packet(original_members, config, builder)
    completed = prior_receipts(prior_members, canary_members, manifests, old_index, config, bulk)
    fresh = reconcile_fresh(fresh_raw, config, manifests, completed, builder)
    need(config["current_source"] != config["original_source"]
         and re.fullmatch(r"[a-f0-9]{40}", config["current_source"]), "new_source_binding_invalid")
    result_members = {"snapshot.json": fresh_raw}
    entries = copy.deepcopy(old_index["manifests"])
    previous = {"operation": entries[16]["operation"], "manifest_sha256": BRIDGE}
    snapshot_binding = {"operation": fresh["operation"], "sha256": sha(fresh_raw),
                        "installed_sha": config["current_source"], "observed_at_utc": fresh["observed_at_utc"], "post_count": 916}
    for number, original in enumerate(manifests):
        if number < FIRST:
            raw = original_members["manifests/" + entries[number]["file"]]
        else:
            updated = copy.deepcopy(original)
            updated["expected_installed_sha"] = config["current_source"]
            updated["snapshot"] = snapshot_binding
            updated["predecessor"] = previous
            need(updated["rows"] == original["rows"] and updated["operation"] == original["operation"]
                 and updated["batch_index"] == number, "unstarted_rows_or_operation_changed")
            raw = builder["encoded"](updated)
            entries[number]["sha256"] = sha(raw)
            previous = {"operation": original["operation"], "manifest_sha256": sha(raw)}
        result_members["manifests/" + entries[number]["file"]] = raw
    index = {"ok": True, "purpose": "only_never_dispatched_operations_17_to_46", "snapshot_sha256": sha(fresh_raw),
             "original_index_sha256": config["original_index_sha256"], "expected_installed_sha": config["current_source"],
             "snapshot_posts": 916, "selected_posts": 594, "completed_before_continuation": 322,
             "historical_manifest_indices": list(range(FIRST)), "write_batch_indices": list(range(FIRST, LAST+1)),
             "batch_size": 20, "max_batch_posts": 100, "throttle_microseconds": 3200000,
             "bridge": {"operation": entries[16]["operation"], "manifest_sha256": BRIDGE},
             "provider_calls": 0, "post_write_attempts": 0, "manifests": entries}
    result_members["manifests/plan-index.json"] = encoded(index)
    proof = {"ok": True, "source": config["current_source"], "state": "EXACT_UNSTARTED_CONTINUATION_VERIFIED",
             "original_source": config["original_source"], "prior_bulk_run_id": config["prior"]["run_id"],
             "prior_result_artifact_id": config["prior"]["artifact_id"], "prior_result_zip_sha256": config["prior"]["zip_sha256"],
             "fresh_read_run_id": config["fresh"]["run_id"], "fresh_snapshot_sha256": sha(fresh_raw),
             "original_index_sha256": config["original_index_sha256"], "continuation_index_sha256": sha(encoded(index)),
             "completed_before_run": 322, "bulk_posts": 594, "write_batch_indices": list(range(FIRST, LAST+1)),
             "bridge_operation": entries[16]["operation"], "bridge_manifest_sha256": BRIDGE,
             "post_write_attempts": 0, "provider_calls": 0}
    return result_members, proof


def authenticate_run(run, expected, conclusion):
    need(run.get("id") == expected["run_id"] and run.get("head_sha") == expected["commit"]
         and run.get("head_branch") == BRANCH and run.get("path") == WORKFLOW and run.get("event") == "push"
         and run.get("status") == "completed" and run.get("conclusion") == conclusion and run.get("run_attempt") == 1,
         "original_run_identity_or_outcome_changed")


def authenticate_artifact(artifact, expected, name):
    owner = artifact.get("workflow_run", {})
    need(artifact.get("id") == expected["artifact_id"] and artifact.get("name") == name
         and artifact.get("expired") is False and owner.get("id") == expected["run_id"]
         and owner.get("head_sha") == expected["commit"] and owner.get("head_branch") == BRANCH,
         "original_artifact_provenance_changed")


def prepare_runtime(private, safe, ops):
    config = configuration(); builder, bulk = modules(ops)
    authenticate_run(obj((private/"canary-run.json").read_bytes()), config["canary"], "success")
    authenticate_run(obj((private/"prior-run.json").read_bytes()), config["prior"], "failure")
    authenticate_run(obj((private/"fresh-run.json").read_bytes()), config["fresh"], "success")
    kinds = {"prepared": PREFIX+"-prepared", "canary": PREFIX+"-canary-result", "prior": PREFIX+"-bulk-result",
             "fresh": "commercial-short-heading-progress-20261108"}
    members = {}
    for key, name in kinds.items():
        authenticate_artifact(obj((private/f"{key}-artifact.json").read_bytes()), config[key], name)
        raw = (private/f"{key}.zip").read_bytes()
        need(sha(raw) == config[key]["zip_sha256"], "pinned_input_zip_changed")
        members[key] = bulk["archive_members"](raw)
    jobs = obj((private/"prior-jobs.json").read_bytes())
    need(jobs.get("total_count") == 1 and len(jobs.get("jobs", [])) == 1, "original_bulk_job_not_unique")
    job = jobs["jobs"][0]
    need(job.get("run_id") == config["prior"]["run_id"] and job.get("head_sha") == config["prior"]["commit"]
         and job.get("status") == "completed" and job.get("conclusion") == "failure", "original_bulk_job_changed")
    steps = {step["name"]: step for step in job["steps"]}
    for number in range(1, LAST+1):
        name = f"Batch {number:02d}/46 — shorten exactly {14 if number == 46 else 20} existing captions once"
        step = steps.get(name, {})
        need(step.get("status") == "completed" and step.get("conclusion") ==
             ("success" if number < FIRST else "failure" if number == FIRST else "skipped"),
             "original_step_dispatch_boundary_changed")
    need(set(members["fresh"]) == {"progress-readback.json"}, "fresh_snapshot_archive_scope_changed")
    packet, proof = derive(members["prepared"], members["prior"], members["canary"],
                           members["fresh"]["progress-readback.json"], config, builder, bulk)
    need(sha(packet["manifests/plan-index.json"]) == config["continuation_index_sha256"],
         "reviewed_continuation_packet_changed")
    destination = private/"packet"; destination.mkdir(mode=0o700, exist_ok=False)
    for name, raw in packet.items():
        path = destination/name; path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
        with path.open("xb") as file: file.write(raw)
    # These are authenticated historical receipts, copied unchanged for the
    # canonical predecessor/final checks. They are never dispatched again.
    for number in range(1, FIRST):
        for suffix in ("started", "result"):
            filename = f"batch{number:03d}.{suffix}.safe.json"
            with (safe/filename).open("xb") as file: file.write(members["prior"][filename])
    bulk["save"](safe/"admission.safe.json", proof)
    bulk["save"](safe/"historical-proof.safe.json", {"completed_before_run": 322,
        "historical_bulk_batches": list(range(1, FIRST)), "historical_receipts_are_not_new_dispatches": True,
        "prior_run": config["prior"], "canary_run": config["canary"]["run_id"]})
    print(json.dumps(proof, sort_keys=True))


def continuation_summary(safe, config):
    summary = obj((safe/"summary.safe.json").read_bytes())
    count = 0; confirmed = []
    for number in range(FIRST, LAST+1):
        path = safe/f"batch{number:03d}.result.safe.json"
        if path.is_file():
            result = obj(path.read_bytes()); count += len(result.get("posts", []))
            if result.get("validated") is True: confirmed.append(number)
    result = {"ok": summary.get("ok") is True and count == 594, "source": config["current_source"],
              "completed_before_this_run": 322, "confirmed_post_receipts_this_run": count,
              "confirmed_continuation_batches": confirmed,
              "unresolved_dispatched_batches": [i for i in summary.get("unresolved_dispatched_batches", []) if i >= FIRST],
              "not_dispatched_batches": [i for i in summary.get("not_dispatched_batches", []) if i >= FIRST],
              "final_read_verified": summary.get("final_read_verified") is True,
              "prior_bulk_run_id": config["prior"]["run_id"], "bridge_manifest_sha256": BRIDGE}
    with (safe/"continuation-summary.safe.json").open("x") as file: file.write(json.dumps(result, indent=2)+"\n")
    print(json.dumps(result, sort_keys=True))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("command", choices=("prepare", "summary"))
    parser.add_argument("--private-dir", type=Path, required=True)
    parser.add_argument("--safe-dir", type=Path, required=True)
    parser.add_argument("--ops-dir", type=Path, default=Path("tools/direct_demand/operations"))
    args = parser.parse_args()
    try:
        if args.command == "prepare": prepare_runtime(args.private_dir, args.safe_dir, args.ops_dir)
        else: continuation_summary(args.safe_dir, configuration())
    except Exception as error:
        reason = str(error)
        print(json.dumps({"ok": False, "error": reason if re.fullmatch(r"[a-z][a-z0-9_]{1,100}", reason)
                          else "bounded_continuation_verification_failed", "state": "STOP_NO_REPLAY"}))
        raise SystemExit(2)


if __name__ == "__main__":
    main()
