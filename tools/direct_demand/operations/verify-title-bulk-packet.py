#!/usr/bin/env python3
"""Runner-local verification/compilation only. No network, provider calls, or production writes."""
from __future__ import annotations

import argparse
import base64
import hashlib
import io
import json
import os
from pathlib import Path, PurePosixPath
import re
import runpy
import stat
import zipfile

PREFIX = "commercial-short-heading-20261108"
BRANCH = "audit/content100-bot-publication-20261101"
WORKFLOW = ".github/workflows/content100-bot-publication-20261101.yml"
BUTTON = "✈️ Узнать стоимость тура"
ZERO_FIELDS = ("configuration_write_attempts", "button_write_attempts", "new_posts_created",
               "tour_search_calls", "price_queries")
CHECK_FLAGS = ("body_photo_button_verified", "formatting_verified", "existing_message_reused",
               "existing_token_resolves_to_current_version")


def need(ok, reason):
    if not ok:
        raise ValueError(reason)


def digest(raw):
    return hashlib.sha256(raw).hexdigest()


def data(raw):
    value = json.loads(raw)
    need(isinstance(value, dict), "json_object_required")
    return value


def save(path, value):
    path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    need(not path.is_symlink(), "unsafe_safe_result_path")
    with path.open("x", encoding="utf-8") as file:
        file.write(json.dumps(value, ensure_ascii=False, indent=2) + "\n")


def bindings():
    hashes = {name: os.environ[name] for name in (
        "EXPECTED_PRODUCTION_SHA", "CANARY_COMMIT_SHA", "PREPARED_ZIP_SHA256",
        "PREPARED_INDEX_SHA256", "CANARY_MANIFEST_SHA256", "TITLE_EXECUTOR_SHA256")}
    for key, value in hashes.items():
        length = 40 if key in ("EXPECTED_PRODUCTION_SHA", "CANARY_COMMIT_SHA") else 64
        need(re.fullmatch(r"[a-f0-9]{%d}" % length, value), "invalid_pinned_hash")
    for key in ("CANARY_RUN_ID", "PREPARED_ARTIFACT_ID"):
        need(re.fullmatch(r"[1-9][0-9]{0,19}", os.environ[key]), "invalid_pinned_id")
        hashes[key] = int(os.environ[key])
    return hashes


def archive_members(raw):
    need(len(raw) <= 20_000_000, "prepared_zip_too_large")
    members = {}
    with zipfile.ZipFile(io.BytesIO(raw)) as archive:
        infos = archive.infolist()
        need(1 <= len(infos) <= 100, "prepared_zip_member_count")
        need(sum(item.file_size for item in infos) <= 50_000_000, "prepared_zip_expansion_limit")
        seen = set()
        for item in infos:
            name = item.filename
            path = PurePosixPath(name)
            need(name not in seen and "\\" not in name and "\x00" not in name
                 and not path.is_absolute() and all(part not in (".", "..") for part in path.parts)
                 and path.as_posix() == name.rstrip("/"), "unsafe_prepared_zip_path")
            seen.add(name)
            need(not item.flag_bits & 1 and item.file_size <= 10_000_000,
                 "unsupported_prepared_zip_member")
            mode = item.external_attr >> 16
            kind = stat.S_IFMT(mode)
            need(kind in (0, stat.S_IFREG, stat.S_IFDIR) and not stat.S_ISLNK(mode),
                 "nonregular_prepared_zip_member")
            if item.is_dir():
                need(name == "manifests/", "unexpected_prepared_zip_directory")
                continue
            need(kind != stat.S_IFDIR, "zip_directory_type_changed")
            members[name] = archive.read(item)  # ZIP CRC is checked by zipfile.
    return members


def verify_packet(private, safe, prepare_script):
    b = bindings()
    run = data((private / "canary-run.json").read_bytes())
    need(run.get("id") == b["CANARY_RUN_ID"] and run.get("head_sha") == b["CANARY_COMMIT_SHA"]
         and run.get("head_branch") == BRANCH and run.get("path") == WORKFLOW
         and run.get("status") == "completed" and run.get("conclusion") == "success"
         and run.get("run_attempt") == 1 and run.get("event") == "push",
         "original_canary_not_successful_once")
    artifact = data((private / "prepared-artifact.json").read_bytes())
    need(artifact.get("id") == b["PREPARED_ARTIFACT_ID"] and artifact.get("name") == PREFIX + "-prepared"
         and artifact.get("expired") is False, "prepared_artifact_identity_changed")
    owner = artifact.get("workflow_run", {})
    need(owner.get("id") == b["CANARY_RUN_ID"] and owner.get("head_sha") == b["CANARY_COMMIT_SHA"]
         and owner.get("head_branch") == BRANCH, "prepared_artifact_run_binding_changed")
    raw = (private / "prepared.zip").read_bytes()
    need(digest(raw) == b["PREPARED_ZIP_SHA256"], "prepared_zip_sha256_changed")
    members = archive_members(raw)
    index_raw = members.get("manifests/plan-index.json", b"")
    need(digest(index_raw) == b["PREPARED_INDEX_SHA256"], "prepared_index_sha256_changed")
    index = data(index_raw)
    snapshot_raw = members.get("snapshot.json", b"")
    snapshot = data(snapshot_raw)
    builder = runpy.run_path(str(prepare_script))
    manifests, rebuilt_index = builder["build"](
        snapshot, snapshot_raw, b["EXPECTED_PRODUCTION_SHA"], PREFIX, 20)
    need(index_raw == builder["encoded"](rebuilt_index), "prepared_index_not_exact_builder_output")
    need(index.get("selected_posts") == 916 and index.get("no_matching_suffix_publication_ids") == []
         and len(manifests) == len(index.get("manifests", [])) == 47, "prepared_complete_scope_changed")
    allowlist = {"snapshot.json", "intent.safe.json", "manifests/plan-index.json"}
    for number, (manifest, entry) in enumerate(zip(manifests, index["manifests"])):
        expected_operation = PREFIX + ("-canary-v1" if number == 0 else f"-batch{number:03d}-v1")
        expected_file = f"{number:03d}-{expected_operation}.json"
        count = 2 if number == 0 else (14 if number == 46 else 20)
        need(entry.get("operation") == expected_operation and entry.get("file") == expected_file
             and entry.get("posts") == count and len(manifest["rows"]) == count,
             "prepared_batch_identity_changed")
        member = "manifests/" + expected_file
        allowlist.add(member)
        manifest_raw = members.get(member, b"")
        need(digest(manifest_raw) == entry["sha256"] and manifest_raw == builder["encoded"](manifest),
             "prepared_manifest_or_chain_changed")
    need(index["manifests"][0]["sha256"] == b["CANARY_MANIFEST_SHA256"], "approved_canary_manifest_changed")
    need({(row["platform"], row["publication_id"], row["version_id"]) for row in manifests[0]["rows"]}
         == {("max", 432, 668), ("telegram", 431, 669)}, "original_canary_pair_changed")
    intent = data(members.get("intent.safe.json", b""))
    need(intent.get("operation") == PREFIX + "-canary-v1"
         and intent.get("state") == "PREPARED_NOT_EXECUTED" and intent.get("post_count") == 2
         and intent.get("expected_installed_sha") == b["EXPECTED_PRODUCTION_SHA"]
         and intent.get("manifest_sha256") == b["CANARY_MANIFEST_SHA256"]
         and intent.get("once_no_replay") is True, "prepared_intent_changed")
    need(set(members) == allowlist, "unexpected_or_missing_prepared_member")
    packet = private / "packet"
    packet.mkdir(mode=0o700, exist_ok=False)
    for name, content in members.items():
        path = packet / name
        path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
        with path.open("xb") as file:
            file.write(content)
    admission = {"ok": True, "state": "ORIGINAL_CANARY_AND_PACKET_VERIFIED", "source": b["EXPECTED_PRODUCTION_SHA"],
                 "canary_run_id": b["CANARY_RUN_ID"], "canary_commit_sha": b["CANARY_COMMIT_SHA"],
                 "prepared_artifact_id": b["PREPARED_ARTIFACT_ID"], "zip_sha256": b["PREPARED_ZIP_SHA256"],
                 "index_sha256": b["PREPARED_INDEX_SHA256"], "canary_manifest_sha256": b["CANARY_MANIFEST_SHA256"],
                 "snapshot_sha256": digest(snapshot_raw), "manifest_count": 47,
                 "already_confirmed_canary_posts": 2, "bulk_posts": 914, "post_write_attempts": 0}
    save(safe / "admission.safe.json", admission)
    print(json.dumps(admission, sort_keys=True))


def get_batch(private, number):
    b = bindings()
    need(type(number) is int and 1 <= number <= 46, "canary_replay_or_invalid_batch")
    raw_index = (private / "packet/manifests/plan-index.json").read_bytes()
    need(digest(raw_index) == b["PREPARED_INDEX_SHA256"], "local_index_changed")
    index = data(raw_index)
    entry = index["manifests"][number]
    operation = f"{PREFIX}-batch{number:03d}-v1"
    need(entry["operation"] == operation and entry["file"] == f"{number:03d}-{operation}.json",
         "local_manifest_selector_changed")
    raw = (private / "packet/manifests" / entry["file"]).read_bytes()
    need(digest(raw) == entry["sha256"], "local_manifest_sha256_changed")
    manifest = data(raw)
    need(manifest["operation"] == operation and manifest["expected_installed_sha"] == b["EXPECTED_PRODUCTION_SHA"]
         and manifest["batch_index"] == number and manifest["batch_kind"] == "batch"
         and len(manifest["rows"]) == entry["posts"] == (14 if number == 46 else 20), "local_batch_scope_changed")
    return manifest, raw, entry


def compile_batch(private, number, ops):
    manifest, raw, entry = get_batch(private, number)
    source = (ops / "title-only-executor-template.php").read_bytes()
    need(digest(source) == bindings()["TITLE_EXECUTOR_SHA256"], "exact_executor_changed")
    constants = "\nconst EXPECTED_INSTALLED_SHA='" + bindings()["EXPECTED_PRODUCTION_SHA"] + "';\n"
    constants += "const APPROVED_UPDATE_OPERATION='" + manifest["operation"] + "';\n"
    constants += "const APPROVED_MANIFEST_SHA256='" + entry["sha256"] + "';\n"
    constants += "const MANIFEST_BASE64='" + base64.b64encode(raw).decode() + "';\n"
    text = source.decode("utf-8")
    need(text.count("declare(strict_types=1);") == 1, "executor_compile_marker_changed")
    with (private / f"batch{number:03d}.php").open("x", encoding="utf-8") as file:
        file.write(text.replace("declare(strict_types=1);", "declare(strict_types=1);" + constants, 1))
    print(json.dumps({"operation": manifest["operation"], "manifest_sha256": entry["sha256"],
                      "posts": entry["posts"], "state": "COMPILED_NOT_EXECUTED"}, sort_keys=True))


def start_batch(private, safe, number):
    manifest, _, entry = get_batch(private, number)
    admission = data((safe / "admission.safe.json").read_bytes())
    need(admission.get("ok") is True and admission.get("source") == bindings()["EXPECTED_PRODUCTION_SHA"],
         "packet_admission_missing")
    if number > 1:
        previous = data((safe / f"batch{number-1:03d}.result.safe.json").read_bytes())
        need(previous.get("validated") is True and previous.get("operation") == manifest["predecessor"]["operation"]
             and previous.get("manifest_sha256") == manifest["predecessor"]["manifest_sha256"],
             "previous_runner_batch_not_confirmed")
    save(safe / f"batch{number:03d}.started.safe.json", {
        "operation": manifest["operation"], "manifest_sha256": entry["sha256"], "batch_index": number,
        "expected_installed_sha": bindings()["EXPECTED_PRODUCTION_SHA"], "post_count": entry["posts"],
        "state": "SSH_DISPATCH_STARTED_OUTCOME_PENDING", "once_no_replay": True})


def receipt_matches(post, row):
    if not isinstance(post, dict):
        return False
    mappings = {"hotel_id": "hotel_id", "platform": "platform", "publication_id": "publication_id",
                "previous_version_id": "version_id", "message_id": "message_id", "post_url": "post_url",
                "text_sha256": "new_text_sha256", "previous_text_sha256": "old_text_sha256",
                "photo_signature": "photo_signature", "previous_attachments_sha256": "attachments_sha256",
                "button_url_sha256": "button_url_sha256", "source_key": "source_key"}
    return (all(post.get(key) == row[value] and type(post.get(key)) is type(row[value]) for key, value in mappings.items())
            and type(post.get("version_id")) is int and post["version_id"] > 0
            and post["version_id"] != row["version_id"] and post.get("state") == "sent"
            and post.get("button_text") == BUTTON and all(post.get(key) is True for key in CHECK_FLAGS)
            and isinstance(post.get("attachments_sha256"), str)
            and re.fullmatch(r"[a-f0-9]{64}", post["attachments_sha256"]) is not None)


def safe_runtime_result(raw, ssh_status, manifest, entry, expected_source):
    safe = {"operation": manifest["operation"], "manifest_sha256": entry["sha256"],
            "batch_index": manifest["batch_index"], "expected_installed_sha": expected_source,
            "expected_posts": len(manifest["rows"]), "ssh_status": ssh_status,
            "validated": False, "state": "UNKNOWN_STOP_NO_REPLAY", "posts": [],
            "raw_output_sha256": digest(raw), "error": "no_valid_original_runtime_result"}
    try:
        need(len(raw) <= 20_000_000, "runtime_result_too_large")
        lines = [line for line in raw.splitlines() if line.strip()]
        need(lines, "runtime_result_missing")
        result = data(lines[-1])
        need(result.get("operation") == manifest["operation"] and type(result.get("ok")) is bool
             and result.get("mode") == "existing_post_first_line_suffix_only", "runtime_final_receipt_identity_missing")
        safe["runtime_reported_ok"] = result["ok"]
        reason = result.get("error")
        if isinstance(reason, str) and re.fullmatch(r"[a-z][a-z0-9_]{1,100}", reason):
            safe["error"] = reason
        for key in ("post_write_attempts", "preflight_existing_messages", "title_updates") + ZERO_FIELDS:
            if type(result.get(key)) is int and 0 <= result[key] <= 100:
                safe["reported_" + key] = result[key]
        rows = {row["publication_id"]: row for row in manifest["rows"]}
        seen = set()
        posts = result.get("posts")
        need(isinstance(posts, list) and len(posts) <= len(rows), "runtime_receipt_count_invalid")
        valid_receipts = True
        for post in posts:
            pid = post.get("publication_id") if isinstance(post, dict) else None
            row = rows.get(pid) if type(pid) is int else None
            if row is None or pid in seen or not receipt_matches(post, row):
                valid_receipts = False
                continue
            seen.add(pid)
            # Only fixed fields from validated receipts cross into the artifact.
            allowed = ("hotel_id", "platform", "publication_id", "previous_version_id", "version_id",
                       "message_id", "post_url", "text_sha256", "previous_text_sha256", "photo_signature",
                       "previous_attachments_sha256", "attachments_sha256", "button_url_sha256", "button_text",
                       "source_key", "state") + CHECK_FLAGS
            safe["posts"].append({key: post[key] for key in allowed})
        n = len(rows)
        need(ssh_status == 0 and result["ok"] is True
             and result.get("installed_sha") == expected_source and result.get("manifest_sha256") == entry["sha256"]
             and all(type(result.get(key)) is int and result[key] == n for key in
                     ("preflight_existing_messages", "post_write_attempts", "title_updates"))
             and all(type(result.get(key)) is int and result[key] == 0 for key in ZERO_FIELDS)
             and valid_receipts and len(posts) == len(seen) == n, "runtime_outcome_not_fully_confirmed")
        safe.update(validated=True, state="CONFIRMED", error=None)
    except Exception as error:
        if safe["error"] == "no_valid_original_runtime_result":
            reason = str(error)
            safe["error"] = reason if re.fullmatch(r"[a-z][a-z0-9_]{1,100}", reason) else "runtime_result_invalid"
    safe["confirmed_posts"] = len(safe["posts"])
    return safe


def record_result(private, safe, number, ssh_status):
    manifest, _, entry = get_batch(private, number)
    path = private / f"batch{number:03d}.runtime.jsonl"
    raw = path.read_bytes() if path.is_file() else b""
    result = safe_runtime_result(raw, ssh_status, manifest, entry, bindings()["EXPECTED_PRODUCTION_SHA"])
    save(safe / f"batch{number:03d}.result.safe.json", result)
    print(json.dumps({key: value for key, value in result.items() if key != "posts"}, sort_keys=True))
    need(result["validated"], "batch_unconfirmed_stop_no_replay")


def compile_final(private, ops):
    raw = (ops / "short-heading-scope-20261107.json").read_bytes()
    source_raw = (ops / "read-short-heading-scope-20261108.php").read_bytes()
    need(digest(raw) == os.environ["TITLE_SCOPE_SHA256"]
         and digest(source_raw) == os.environ["TITLE_READER_SHA256"], "exact_final_reader_changed")
    source = source_raw.decode("utf-8")
    constants = "\nconst EXPECTED_INSTALLED_SHA='" + bindings()["EXPECTED_PRODUCTION_SHA"] + "';\n"
    constants += "const APPROVED_MANIFEST_SHA256='" + digest(raw) + "';\n"
    constants += "const MANIFEST_BASE64='" + base64.b64encode(raw).decode() + "';\n"
    need(source.count("declare(strict_types=1);") == 1, "reader_compile_marker_changed")
    with (private / "final-reader.php").open("x", encoding="utf-8") as file:
        file.write(source.replace("declare(strict_types=1);", "declare(strict_types=1);" + constants, 1))


def verify_final(private, safe, ssh_status):
    report = {"ok": False, "state": "FINAL_READ_UNCONFIRMED", "post_write_attempts": 0,
              "ssh_status": ssh_status, "expected_posts": 916}
    try:
        for number in range(1, 47):
            need(data((safe / f"batch{number:03d}.result.safe.json").read_bytes()).get("validated") is True,
                 "final_read_before_all_batches_confirmed")
        source = bindings()["EXPECTED_PRODUCTION_SHA"]
        snapshot = data((private / "final-snapshot.runtime.json").read_bytes())
        index_raw = (private / "packet/manifests/plan-index.json").read_bytes()
        need(digest(index_raw) == bindings()["PREPARED_INDEX_SHA256"], "final_index_binding_changed")
        index = data(index_raw)
        old_raw = (private / "packet/snapshot.json").read_bytes()
        need(digest(old_raw) == index["snapshot_sha256"], "final_original_snapshot_binding_changed")
        old = data(old_raw)
        need(ssh_status == 0 and snapshot.get("ok") is True and snapshot.get("installed_sha") == source
             and snapshot.get("operation") == old["operation"]
             and all(type(snapshot.get(key)) is int and snapshot[key] == 0 for key in ("post_write_attempts", "provider_calls"))
             and isinstance(snapshot.get("observed_at_utc"), str)
             and re.fullmatch(r"[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:Z|\+00:00)", snapshot["observed_at_utc"])
             and len(snapshot.get("posts", [])) == 916,
             "final_read_identity_or_scope_changed")
        expected = {}
        for entry in index["manifests"]:
            manifest_raw = (private / "packet/manifests" / entry["file"]).read_bytes()
            need(digest(manifest_raw) == entry["sha256"], "final_expected_manifest_binding_changed")
            manifest = data(manifest_raw)
            for row in manifest["rows"]:
                expected[row["publication_id"]] = row
        need(len(expected) == 916, "final_expected_scope_changed")
        seen, safe_rows = set(), []
        stable = ("hotel_id", "platform", "publication_id", "message_id", "post_url", "source_key",
                  "config_version", "photo_signature", "button_url_sha256")
        for post in snapshot["posts"]:
            need(isinstance(post, dict) and type(post.get("publication_id")) is int, "invalid_final_row")
            pid = post["publication_id"]
            need(pid in expected and pid not in seen, "unexpected_or_duplicate_final_row")
            seen.add(pid); row = expected[pid]
            need(all(post.get(key) == row[key] and type(post.get(key)) is type(row[key]) for key in stable),
                 "final_post_identity_photo_or_button_changed")
            need(post.get("status") == "sent" and post.get("button_resolves_to_current_post") is True
                 and post.get("button_observed_in_snapshot") is True
                 and type(post.get("media_count")) is int and post["media_count"] == 1
                 and post.get("media_types") == ["image"] and type(post.get("version_id")) is int
                 and post["version_id"] > 0 and post["version_id"] != row["version_id"], "final_post_not_confirmed")
            need(post.get("caption") == row["new_text"] and post.get("caption_sha256") == row["new_text_sha256"]
                 and digest(post["caption"].encode()) == row["new_text_sha256"]
                 and not re.search(r" с перел[её]том\Z", post["caption"].partition("\n")[0]),
                 "final_caption_or_body_changed")
            allowed = stable + ("status", "version_id", "caption", "caption_sha256", "media_count", "media_types",
                                "button_observed_in_snapshot", "button_resolves_to_current_post")
            safe_rows.append({key: post[key] for key in allowed})
        need(len(seen) == 916, "final_scope_incomplete")
        safe_snapshot = {"operation": snapshot["operation"], "ok": True, "installed_sha": source,
                         "observed_at_utc": snapshot.get("observed_at_utc"), "provider_calls": 0,
                         "post_write_attempts": 0, "posts": safe_rows}
        save(safe / "final-snapshot.safe.json", safe_snapshot)
        report.update(ok=True, state="ALL_916_EXISTING_POSTS_CONFIRMED_SHORT", verified_posts=916,
                      remaining_long_first_lines=0, source=source, observed_at_utc=snapshot.get("observed_at_utc"))
    except Exception as error:
        reason = str(error)
        report["error"] = reason if re.fullmatch(r"[a-z][a-z0-9_]{1,100}", reason) else "final_read_validation_failed"
    save(safe / "final-verification.safe.json", report)
    print(json.dumps(report, sort_keys=True))
    need(report["ok"], "final_read_unconfirmed")


def summarize(safe):
    safe.mkdir(mode=0o700, parents=True, exist_ok=True)
    def read(name):
        try:
            return data((safe / name).read_bytes())
        except Exception:
            return {}
    admission = read("admission.safe.json")
    confirmed, unresolved, unstarted, posts, partial_posts = [], [], [], [], []
    for number in range(1, 47):
        result = read(f"batch{number:03d}.result.safe.json")
        if result.get("validated") is True:
            confirmed.append(number)
            posts.extend(result.get("posts", []))
        elif (safe / f"batch{number:03d}.started.safe.json").exists():
            unresolved.append(number)
            partial_posts.extend(result.get("posts", []))
        else:
            unstarted.append(number)
    final = read("final-verification.safe.json")
    complete = len(confirmed) == 46 and len(posts) == 914 and final.get("ok") is True
    report = {"ok": complete, "state": "COMPLETE" if complete else "STOPPED_REVIEW_SAFE_RESULTS_NO_REPLAY",
              "validated_bulk_posts": len(posts) + len(partial_posts), "posts_in_completed_batches": len(posts),
              "confirmed_posts_in_incomplete_batches": len(partial_posts), "validated_bulk_batches": confirmed,
              "unresolved_dispatched_batches": unresolved, "not_dispatched_batches": unstarted,
              "original_canary_posts": 2 if admission.get("ok") is True else 0,
              "final_read_verified": final.get("ok") is True,
              "final_verified_posts": 916 if final.get("ok") is True else 0,
              "job_status_before_summary": os.environ.get("JOB_STATUS", "unknown")}
    save(safe / "summary.safe.json", report)
    print(json.dumps(report, sort_keys=True))


def self_test():
    def rejected_zip(name, mode=None):
        stream = io.BytesIO()
        with zipfile.ZipFile(stream, "w") as archive:
            member = zipfile.ZipInfo(name)
            if mode is not None:
                member.create_system = 3; member.external_attr = mode << 16
            archive.writestr(member, "test")
        try:
            archive_members(stream.getvalue())
        except ValueError:
            return
        raise AssertionError("unsafe_zip_accepted")
    for name in ("../outside", "/outside", "a\\b", "a/./b"):
        rejected_zip(name)
    rejected_zip("snapshot.json", stat.S_IFLNK | 0o777)
    row = {"hotel_id": 1, "platform": "max", "publication_id": 1, "version_id": 2, "message_id": "one",
           "post_url": "https://max.ru/channel_anytour/one", "source_key": "max_anytour_msk1",
           "new_text_sha256": "a"*64, "old_text_sha256": "b"*64, "photo_signature": "max:1",
           "attachments_sha256": "c"*64, "button_url_sha256": "d"*64}
    manifest = {"operation": PREFIX + "-batch001-v1", "batch_index": 1, "rows": [row]}
    entry = {"sha256": "e"*64}; source = "f"*40
    post = {key: row[value] for key, value in {
        "hotel_id": "hotel_id", "platform": "platform", "publication_id": "publication_id", "previous_version_id": "version_id",
        "message_id": "message_id", "post_url": "post_url", "text_sha256": "new_text_sha256",
        "previous_text_sha256": "old_text_sha256", "photo_signature": "photo_signature",
        "previous_attachments_sha256": "attachments_sha256", "button_url_sha256": "button_url_sha256", "source_key": "source_key"}.items()}
    post.update(version_id=3, state="sent", button_text=BUTTON, attachments_sha256="0"*64,
                **{key: True for key in CHECK_FLAGS})
    result = {"operation": manifest["operation"], "mode": "existing_post_first_line_suffix_only", "ok": True,
              "installed_sha": source, "manifest_sha256": entry["sha256"], "preflight_existing_messages": 1,
              "post_write_attempts": 1, "title_updates": 1, "posts": [post], **{key: 0 for key in ZERO_FIELDS},
              "provider_response_private": "DO_NOT_RETAIN", "start_payload": "DO_NOT_RETAIN"}
    good = safe_runtime_result(json.dumps(result).encode(), 0, manifest, entry, source)
    need(good["validated"] and "DO_NOT_RETAIN" not in json.dumps(good), "safe_result_allowlist_failed")
    for raw, status in ((b'{}', 0), (b'[]', 0), (b'{"progress":"existing_title_shortened"}', 0),
                        (b'{"operation":"wrong","ok":true}', 0), (b'{broken', 0),
                        (json.dumps(result).encode(), 255)):
        need(not safe_runtime_result(raw, status, manifest, entry, source)["validated"], "unsafe_runtime_result_accepted")
    post["button_url_sha256"] = "a"*64
    need(not safe_runtime_result(json.dumps(result).encode(), 0, manifest, entry, source)["validated"],
         "wrong_button_receipt_accepted")
    print(json.dumps({"ok": True, "archive_rejections": 5, "result_rejections": 7,
                      "safe_result_positive": 1, "network_calls": 0, "post_write_attempts": 0}))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("command", choices=("verify", "compile", "start", "result", "compile-final", "verify-final", "summary", "self-test"))
    parser.add_argument("--private-dir", type=Path)
    parser.add_argument("--safe-dir", type=Path)
    parser.add_argument("--ops-dir", type=Path, default=Path("tools/direct_demand/operations"))
    parser.add_argument("--batch", type=int)
    parser.add_argument("--ssh-status", type=int, default=255)
    args = parser.parse_args()
    if args.command == "self-test":
        self_test(); return
    need(args.private_dir is not None and args.safe_dir is not None, "runner_paths_missing")
    try:
        if args.command == "verify": verify_packet(args.private_dir, args.safe_dir, args.ops_dir / "prepare-title-only.py")
        elif args.command == "compile": compile_batch(args.private_dir, args.batch, args.ops_dir)
        elif args.command == "start": start_batch(args.private_dir, args.safe_dir, args.batch)
        elif args.command == "result": record_result(args.private_dir, args.safe_dir, args.batch, args.ssh_status)
        elif args.command == "compile-final": compile_final(args.private_dir, args.ops_dir)
        elif args.command == "verify-final": verify_final(args.private_dir, args.safe_dir, args.ssh_status)
        elif args.command == "summary": summarize(args.safe_dir)
    except Exception as error:
        reason = str(error)
        safe_reason = reason if re.fullmatch(r"[a-z][a-z0-9_]{1,100}", reason) else "bounded_bulk_packet_step_failed"
        print(json.dumps({"ok": False, "step": args.command, "error": safe_reason,
                          "state": "STOP_NO_REPLAY"}, sort_keys=True))
        raise SystemExit(2)


if __name__ == "__main__":
    main()
