#!/usr/bin/env python3
"""Prepare six reviewed edits from intact original receipts; never execute PHP."""
import argparse
import base64
import hashlib
import io
import json
import os
from pathlib import Path
import stat
import zipfile

MANIFEST_SHA256 = "d08fd01b421e7cef975c390994311a10ea275610d97b05526bb8e548b4bd434c"
TEMPLATE_SHA256 = "84bac9d5b2d48fb92c290a622d863d2044938def55db080d6517d7e1290da495"
OPERATION = "commercial-hotel-caption-review6706115450-20261107-1-v1"
HOTEL_IDS = [1352, 1074, 462]
PUBLICATION_IDS = {467, 473, 479, 488, 485, 494}
MAX_BYTES = 2 * 1024 * 1024


def need(ok, reason):
    if not ok:
        raise ValueError(reason)


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def pinned_file(path, expected):
    need(path.is_file() and not path.is_symlink() and path.stat().st_size <= MAX_BYTES,
         "bounded_input_missing_or_too_large")
    raw = path.read_bytes()
    need(sha(raw) == expected, "pinned_input_hash_changed")
    return raw


def original_receipt(path, source):
    raw = pinned_file(path, source["zip_sha256"])
    with zipfile.ZipFile(io.BytesIO(raw)) as archive:
        need(archive.namelist() == [source["member"]], "original_zip_members_changed")
        info = archive.getinfo(source["member"])
        need(not info.is_dir() and info.file_size <= MAX_BYTES, "original_member_too_large")
        member = archive.read(info)
    need(sha(member) == source["member_sha256"], "original_member_bytes_changed")
    result = json.loads(member.decode("utf-8").splitlines()[-1])
    need(result.get("operation") == source["operation"] and result.get("ok") is True,
         "original_operation_not_confirmed")
    return result


def prepare(manifest, template, headings_zip, read_zip):
    block = manifest.get("execution_block")
    need(block is None, "prior_operation_is_non_replayable" if (block or {}).get("reconciliation", {}).get("ok") is True else "prior_operation_requires_reconciliation")
    need(manifest["operation"] == OPERATION and manifest["status"] == "PREPARED_NOT_EXECUTED"
         and manifest["expected_existing_messages"] == 6
         and manifest["update_kind"] == "caption_only"
         and manifest["search_link_included"] is False
         and manifest["button_text"] == "✈️ Узнать стоимость тура",
         "exact_operation_scope_changed")
    rows = manifest["rows"]
    need([r["hotel_id"] for r in rows] == HOTEL_IDS, "exact_hotel_scope_changed")
    source = manifest["source_caption_receipt"]
    original = original_receipt(headings_zip, source)
    need(original.get("installed_sha") == source["installed_sha"]
         and source["installed_sha"] == "57e5ca2d4e14b2e130e728f5a7e99518f89b4f29"
         and original.get("mode") == "update_existing_hotel_captions_no_price_or_search"
         and original.get("update_kind") == "caption_only"
         and original.get("new_posts_created") == 0,
         "original_caption_receipt_invalid")
    for key in ("post_write_attempts", "commercial_text_updates",
                "content_edit_attempts", "preflight_existing_messages"):
        need(original.get(key) == 72, "original_full_caption_count_changed")
    for key in ("configuration_write_attempts", "button_only_edit_attempts",
                "price_block_updates", "search_link_post_updates",
                "search_link_only_post_updates", "tour_search_calls", "price_queries"):
        need(original.get(key) == 0, "original_caption_scope_changed")
    posts = original.get("posts")
    need(isinstance(posts, list) and len(posts) == 72, "original_full_receipt_missing")
    expected = {(logical, platform) for logical in source["logical_ids"]
                for platform in ("max", "telegram")}
    actual = [(p.get("logical_id"), p.get("platform")) for p in posts]
    need(len(source["logical_ids"]) == 36 and len(expected) == len(set(actual)) == 72
         and set(actual) == expected, "original_full_receipt_identity_changed")
    for p in posts:
        need(p.get("state") == "sent" and p.get("body_photo_button_verified") is True
             and p.get("existing_message_reused") is True and p.get("content_updated") is True
             and p.get("photo_signature") == p.get("previous_photo_signature")
             and isinstance(p.get("photo_signature"), str) and p["photo_signature"],
             "original_caption_confirmation_changed")
    by_key = {(p["logical_id"], p["platform"]): p for p in posts}
    latest = original_receipt(read_zip, manifest["source_registry_read"])
    need(latest.get("post_write_attempts") == latest.get("provider_calls") == 0
         and isinstance(latest.get("posts"), list) and len(latest["posts"]) == 916,
         "original_registry_read_scope_changed")
    latest_ids = [p.get("publication_id") for p in latest["posts"]]
    need(len(set(latest_ids)) == 916, "original_registry_read_identity_changed")
    latest_by_id = {p["publication_id"]: p for p in latest["posts"]}
    selected = []
    for row in rows:
        need(row["key"] == row["logical_id"] and row["update_text"] is True,
             "caption_identity_changed")
        for field in ("old_text", "new_text"):
            need(isinstance(row[field], str)
                 and sha(row[field].encode("utf-8")) == row[field + "_sha256"],
                 "approved_caption_hash_changed")
        first, rest = row["old_text"].split("\n", 1)
        need(first == row["previous_heading"] and first.endswith(" с перелётом")
             and row["new_heading"] == first[:-len(" с перелётом")]
             and row["new_heading"].startswith("✈️ Туры в ")
             and rest.split("\n", 1)[0] == row["location"],
             "approved_heading_or_location_changed")
        candidate = row["new_heading"] + "\n" + rest
        for replacement in row["accepted_replacements"]:
            need(candidate.count(replacement["old"]) == 1, "accepted_change_not_unique")
            candidate = candidate.replace(replacement["old"], replacement["new"])
        need(candidate == row["new_text"] and row["new_text"] != row["old_text"]
             and 0 < len(row["new_text"].encode("utf-16-le")) // 2 <= 1024,
             "only_accepted_caption_changes_allowed")
        need(set(row["expected_bindings"]) == {"max", "telegram"}, "target_pair_incomplete")
        for platform, binding in row["expected_bindings"].items():
            proof = by_key[(row["logical_id"], platform)]
            for field, value in binding.items():
                observed = sha(proof["button_url"].encode()) if field == "button_url_sha256" else proof.get(field)
                need(observed == value, "original_target_binding_changed")
            need(proof["text_sha256"] == row["old_text_sha256"]
                 and proof["button_text"] == manifest["button_text"], "original_caption_button_changed")
            late = latest_by_id[binding["publication_id"]]
            for field in ("platform", "publication_id", "version_id", "message_id", "post_url"):
                need(late[field] == proof[field], "later_registry_binding_changed")
            need(late["hotel_id"] == row["hotel_id"] and late["status"] == "sent"
                 and late["caption"] == row["old_text"]
                 and late["caption_sha256"] == row["old_text_sha256"]
                 and late["media_count"] == 1 and late["media_types"] == ["image"]
                 and late["button_url_sha256"] == binding["button_url_sha256"]
                 and late["button_observed_in_snapshot"] is True
                 and late["button_resolves_to_current_post"] is True,
                 "later_registry_caption_or_button_changed")
            selected.append(proof)
    need(len(selected) == 6 and {p["publication_id"] for p in selected} == PUBLICATION_IDS,
         "exact_six_targets_required")
    # Preserve the complete actual72 receipt. Selection is never labelled an original six-post receipt.
    payload = {**manifest, "original_caption_result": original}
    payload_raw = json.dumps(payload, ensure_ascii=False, separators=(",", ":")).encode()
    constants = (
        "\nconst EXPECTED_INSTALLED_SHA = '" + manifest["expected_installed_sha"] + "';"
        "\nconst ORIGINAL_CAPTION_INSTALLED_SHA = '" + source["installed_sha"] + "';"
        "\nconst APPROVED_UPDATE_OPERATION = '" + OPERATION + "';"
        "\nconst APPROVED_MANIFEST_SHA256 = '" + sha(payload_raw) + "';"
        "\nconst MANIFEST_BASE64 = '" + base64.b64encode(payload_raw).decode() + "';\n"
    )
    marker = "declare(strict_types=1);"
    need(template.count(marker) == 1 and "const MANIFEST_BASE64" not in template,
         "template_injection_point_changed")
    compiled = template.replace(marker, marker + constants, 1).encode()
    return compiled, payload_raw


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--input", required=True, type=Path)
    parser.add_argument("--template", required=True, type=Path)
    parser.add_argument("--headings-zip", required=True, type=Path)
    parser.add_argument("--registry-read-zip", required=True, type=Path)
    parser.add_argument("--output-dir", required=True, type=Path)
    args = parser.parse_args()
    manifest = json.loads(pinned_file(args.input, MANIFEST_SHA256))
    template = pinned_file(args.template, TEMPLATE_SHA256).decode()
    compiled, payload = prepare(manifest, template, args.headings_zip, args.registry_read_zip)
    parent = args.output_dir
    parent.mkdir(mode=0o700, exist_ok=True)
    need(not parent.is_symlink() and stat.S_ISDIR(parent.stat().st_mode)
         and stat.S_IMODE(parent.stat().st_mode) == 0o700
         and parent.stat().st_uid == os.geteuid(), "output_directory_must_be_private_0700")
    for name, raw in (("channel-preflight.php", compiled), ("caption-correction-input.json", payload)):
        fd = os.open(parent / name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_NOFOLLOW", 0), 0o600)
        with os.fdopen(fd, "wb") as target:
            target.write(raw)
            target.flush()
            os.fsync(target.fileno())
    print(json.dumps({"status": "PREPARED_NOT_EXECUTED", "operation": OPERATION,
                      "selected_targets": 6, "original_caption_receipt_posts": 72,
                      "original_registry_read_posts": 916, "new_posts": 0,
                      "payload_sha256": sha(payload), "compiled_php_sha256": sha(compiled)}))


if __name__ == "__main__":
    try:
        main()
    except (ValueError, KeyError, TypeError, OSError, zipfile.BadZipFile) as exc:
        reason = str(exc) if isinstance(exc, ValueError) and str(exc).replace("_", "").isalnum() else type(exc).__name__
        raise SystemExit("BLOCKED: " + reason)

