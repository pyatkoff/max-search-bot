#!/usr/bin/env python3
"""Pure offline preparation of the exact review6706115450 correction. Never executes PHP."""
import argparse
import base64
import hashlib
import io
import json
import os
from pathlib import Path
import stat
import zipfile

MANIFEST_SHA256 = "605999f63f3c4600a7c880ab1897169344985938717f03e6e989d3528356db07"
TEMPLATE_SHA256 = "ce72f8f1e1731134552da61171c9573c1871db33ba0aee4c85cf39334c5ba70d"
OPERATION = "commercial-hotel-caption-review6706115450-20261107-1-v1"
TARGET_IDS = [1352, 1074, 462]
MAX_INPUT_BYTES = 262144

def need(condition, reason):
    if not condition:
        raise ValueError(reason)

def sha(data):
    return hashlib.sha256(data).hexdigest()

def pinned_file(path, expected):
    need(path.is_file() and path.stat().st_size <= MAX_INPUT_BYTES, "bounded_input_missing_or_too_large")
    data = path.read_bytes()
    need(sha(data) == expected, "pinned_input_hash_changed")
    return data

def receipt(path, source, installed):
    raw_zip = pinned_file(path, source["zip_sha256"])
    with zipfile.ZipFile(io.BytesIO(raw_zip)) as archive:
        need(archive.namelist() == [source["member"]], "original_zip_members_changed")
        info = archive.getinfo(source["member"])
        need(info.file_size <= MAX_INPUT_BYTES and not info.is_dir(), "original_receipt_size_changed")
        raw = archive.read(info)
    need(sha(raw) == source["member_sha256"], "original_receipt_bytes_changed")
    result = json.loads(raw.decode("utf-8").splitlines()[-1])
    need(result.get("operation") == source["operation"]
         and result.get("mode") == "publish_approved_commercial_batch"
         and result.get("installed_sha") == installed
         and result.get("ok") is True
         and result.get("new_posts") == source["original_new_posts"]
         and result.get("post_write_attempts") == source["original_post_write_attempts"]
         and result.get("reused") == 0, "original_full_batch_receipt_invalid")
    posts = result.get("posts")
    need(isinstance(posts, list) and len(posts) == source["original_new_posts"], "original_post_count_changed")
    expected = {(hotel, platform) for hotel in source["original_hotel_ids"] for platform in ("telegram", "max")}
    actual = [(p.get("hotel_id"), p.get("platform")) for p in posts]
    need(len(set(actual)) == len(actual) and set(actual) == expected, "original_full_batch_scope_changed")
    for p in posts:
        need(p.get("state") == "sent" and p.get("body_photo_button_verified") is True
             and p.get("reused") is False, "original_confirmation_changed")
    return result

def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--input", type=Path, default=Path(__file__).with_name("caption-correction-input.json"))
    ap.add_argument("--template", type=Path, default=Path(__file__).with_name("update-reviewed-three-captions-template.php"))
    ap.add_argument("--batch3-zip", type=Path, required=True)
    ap.add_argument("--batch4-zip", type=Path, required=True)
    ap.add_argument("--output", type=Path, required=True, help="new PHP file in a private directory outside Git")
    args = ap.parse_args()
    manifest = json.loads(pinned_file(args.input, MANIFEST_SHA256))
    template = pinned_file(args.template, TEMPLATE_SHA256).decode("utf-8")
    need(manifest["operation"] == OPERATION and [r["hotel_id"] for r in manifest["rows"]] == TARGET_IDS,
         "exact_scope_changed")
    need(manifest["status"] == "PREPARED_NOT_EXECUTED_NOT_FRESH"
         and manifest["operational_constraints"]["missing_expected_fields"] == [], "preparation_blocked")
    need(manifest["update_kind"] == "caption_only" and manifest["search_link_included"] is False
         and manifest["expected_existing_messages"] == 6, "caption_only_scope_changed")
    need([s["batch"] for s in manifest["source_receipts"]] == [3, 4], "source_batch_scope_changed")
    originals = []
    indexed = {}
    for path, source in zip((args.batch3_zip, args.batch4_zip), manifest["source_receipts"]):
        result = receipt(path, source, manifest["expected_installed_sha"])
        originals.append({**{k: source[k] for k in ("batch", "run_id", "artifact_id", "zip_sha256", "member_sha256")},
                          "result": result})
        indexed[source["batch"]] = {(p["hotel_id"], p["platform"]): p for p in result["posts"]}
    selected = 0
    for row in manifest["rows"]:
        need(row["key"] == row["logical_id"] and row["update_text"] is True
             and row["old_text"] != row["new_text"], "caption_identity_or_change_invalid")
        for field in ("old_text", "new_text"):
            need(sha(row[field].encode("utf-8")) == row[field + "_sha256"], "approved_caption_hash_changed")
        need(len(row["new_text"].encode("utf-16-le")) // 2 <= 1024, "caption_too_large")
        need(set(row["historical_expected_bindings"]) == {"max", "telegram"}, "target_platform_missing")
        for platform, binding in row["historical_expected_bindings"].items():
            proof = indexed[row["source_batch"]].get((row["hotel_id"], platform))
            need(isinstance(proof, dict), "selected_original_proof_missing")
            for key, value in binding.items():
                actual = sha(proof["button_url"].encode("utf-8")) if key == "button_url_sha256" else proof.get(key)
                need(actual == value, "historical_expected_binding_changed")
            need(proof["logical_id"] == row["logical_id"]
                 and proof["text_sha256"] == row["old_text_sha256"]
                 and proof["photo_sha256"] == row["photo_sha256"]
                 and proof["photo_url"] == row["photo_url"]
                 and proof["button_text"] == manifest["button_text"], "original_caption_photo_button_binding_changed")
            selected += 1
    need(selected == 6, "exact_six_original_targets_required")
    # Preserve both actual full receipts with their original 12/18 counts. No synthetic original receipt.
    payload = {**manifest, "original_publication_results": originals}
    payload_bytes = json.dumps(payload, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
    encoded = base64.b64encode(payload_bytes).decode("ascii")
    constants = (
        "\nconst EXPECTED_INSTALLED_SHA = '" + manifest["expected_installed_sha"] + "';"
        "\nconst APPROVED_UPDATE_OPERATION = '" + OPERATION + "';"
        "\nconst APPROVED_MANIFEST_SHA256 = '" + sha(payload_bytes) + "';"
        "\nconst MANIFEST_BASE64 = '" + encoded + "';\n"
    )
    marker = "declare(strict_types=1);"
    need(template.count(marker) == 1 and "const MANIFEST_BASE64" not in template, "template_injection_point_changed")
    compiled = template.replace(marker, marker + constants, 1).encode("utf-8")
    # The runtime payload contains retained button URLs. Never commit it or print it.
    parent = args.output.parent
    if not parent.exists():
        parent.mkdir(mode=0o700)
    need(not parent.is_symlink() and stat.S_ISDIR(parent.stat().st_mode)
         and stat.S_IMODE(parent.stat().st_mode) == 0o700
         and parent.stat().st_uid == os.geteuid(), "output_directory_must_be_private_0700")
    flags = os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_NOFOLLOW", 0)
    fd = os.open(args.output, flags, 0o600)
    with os.fdopen(fd, "wb") as output:
        output.write(compiled)
        output.flush()
        os.fsync(output.fileno())
    print(json.dumps({"status": "PREPARED_NOT_EXECUTED_NOT_FRESH", "operation": OPERATION,
                      "selected_historical_targets": selected, "original_receipt_counts": [12, 18],
                      "payload_sha256": sha(payload_bytes), "compiled_php_sha256": sha(compiled),
                      "output_mode": "0600"}, separators=(",", ":")))

if __name__ == "__main__":
    try:
        main()
    except (ValueError, KeyError, OSError, zipfile.BadZipFile) as exc:
        # Never interpolate raw input, a token, the payload, or an exception traceback.
        reason = str(exc) if isinstance(exc, ValueError) and str(exc).replace("_", "").isalnum() else type(exc).__name__
        raise SystemExit("BLOCKED: " + reason)

