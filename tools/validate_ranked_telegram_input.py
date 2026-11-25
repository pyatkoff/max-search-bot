"""Compare a release-approved Telegram plan to the original read-only registry artifact."""
import argparse
import hashlib
import json
import re
import zipfile
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
APPROVALS = ROOT / "config/anytour_ranked_approved_indexes.json"


def require(condition, reason):
    if not condition:
        raise ValueError(reason)


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def document(item, limit, root=ROOT):
    path = item["path"]
    require(re.fullmatch(r"config/anytour_[a-z0-9_]+\.json", path), "invalid plan path")
    f = root / path
    require(f.resolve(strict=True) == f and f.is_file() and f.stat().st_size <= limit, "invalid plan file")
    raw = f.read_bytes()
    require(sha(raw) == item["sha256"], "approved bytes changed")
    return json.loads(raw), raw


def registry(zip_path, index):
    proof = index["native_preflight"]
    require(zip_path.stat().st_size == index["native_zip_bytes"] <= 4_000_000, "native ZIP size changed")
    require(sha(zip_path.read_bytes()) == proof["artifact_zip_sha256"], "native ZIP digest changed")
    with zipfile.ZipFile(zip_path) as z:
        require(z.namelist() == ["registry.json"] and 0 < z.infolist()[0].file_size <= 8_000_000, "native ZIP contents changed")
        raw = z.read("registry.json")
    require(sha(raw) == proof["registry_sha256"], "native registry bytes changed")
    d = json.loads(raw)
    require(d["schema"] == 1 and d["issue"] == 1566 and d["ok"] is True
            and d["operation"] in {"hotel_archive_telegram_46_registry", "hotel_archive_telegram_ranked_registry"}
            and d["native_run_id"] == proof["run_id"] and d["native_run_attempt"] == proof["run_attempt"] == 1
            and d["repository_sha"] == proof["repository_sha"] and d["application_sha"] == proof["application_sha"]
            and d["checkpoint_sha256"] == proof["checkpoint_sha256"]
            and all(d[k] == 0 for k in ("production_writes", "provider_writes", "bot_starts"))
            and d["price_editor_shared_read_lease"] is True, "native read identity changed")
    require(d["target_channel"]["chat_id"] == "-1001845835645" and d["target_channel"]["url"] == "https://t.me/anytour"
            and d["native_channel_rights"]["state"] == "ready" and d["native_channel_rights"]["receipt"]["write_attempts"] == 0
            and d["native_public_channel_binding"] == {"anytour": True, "any_toour": True}, "channel rights unverified")
    by_hotel = {}
    for row in d["rows"]:
        require(type(row["hotel_id"]) is int and row["hotel_id"] > 0 and row["hotel_id"] not in by_hotel, "duplicate native hotel")
        by_hotel[row["hotel_id"]] = row
    return by_hotel, d["target_channel"]


def compare(plan, row, channel):
    require(isinstance(plan["next_caption"], str) and not re.search(r"₽|💰\s*От\s+\d|Цена найдена мониторингом",
            plan["next_caption"], re.IGNORECASE), "historical monitor price in destination caption")
    require(row["preview_ready"] is True and row["preview_blockers"] == [] and row["source_matches"] is True
            and row["historical_target_matches"] is True and row["target_media_lock_free"] is True
            and row["prior_media_operation_directory_exists"] is False and row["target_current_pin_matches"] is False
            and row["existing_large_channel_hotel_registry_matches"] == [] and row["target_native"] is None, "native pair not eligible")
    s, t, n = row["source_public"], row["target_public"], row["source_native"]
    require(s["ok"] is True and t["ok"] is True and s["single_photo"] is True and t["single_photo"] is True
            and t["buttons"] == [] and s["url"] == n["post_url"] == "https://t.me/any_toour/" + row["source_message_id"]
            and t["url"] == "https://t.me/anytour/" + row["target_message_id"] and n["state"] == "sent"
            and n["current_token_resolves"] is True
            and n["native_claim_held_or_unknown"] is False and n["hotel_id"] == row["hotel_id"]
            and n["caption_sha256"] == s["caption_sha256"] and len(n["attachments"]) == 1, "source or target changed")
    # The editor creates its own destination button. The original token must resolve to this source.
    photo = n["attachments"][0]
    require(photo["type"] == "image" and photo["provider"] == "telegram" and photo["reusable_telegram_photo"] is True,
            "native image unavailable")
    fields = {
        "source_message_id": row["source_message_id"], "source_publication_id": n["publication_id"],
        "source_version_id": n["version_id"], "source_content_hash": n["content_hash"],
        "source_caption_sha256": s["caption_sha256"], "source_file_id_sha256": photo["file_id_sha256"],
        "source_file_unique_id_sha256": photo["file_unique_id_sha256"], "source_photo_marker_sha256": s["photo_marker_sha256"],
        "target_message_id": row["target_message_id"], "target_published_unixtime": t["published_unixtime"],
        "target_caption_sha256": t["caption_sha256"], "target_photo_marker_sha256": t["photo_marker_sha256"],
        "target_config_version": channel["config_version"], "button_text": channel["button_text"], "bot_url": channel["bot_url"],
    }
    for key, original in fields.items():
        require(type(plan[key]) is type(original) and plan[key] == original, "native pair mismatch: " + key)


def approved_input(index_sha, archive):
    approvals = [p for p in json.loads(APPROVALS.read_bytes()) if p["sha256"] == index_sha]
    require(len(approvals) == 1, "missing or ambiguous release approval")
    index, _ = document(approvals[0], 64000)
    return checked_index(index, archive, ROOT)


ROW_IDENTITY = ("hotel_id", "source_message_id", "target_message_id")
MAX_CHUNK_ROWS = 40
MAX_INSPECTION_ROWS = 100


def checked_index(index, archive, root, *, inspection=False):
    plans = index["plans"]
    if inspection:
        require(index["schema"] == 1 and 0 < len(plans) <= MAX_INSPECTION_ROWS, "invalid inspection index")
    else:
        require(index["schema"] == 1 and len(plans) == 1, "expected one independently executable chunk")
    by_hotel, channel = registry(archive, index)
    identities = {key: set() for key in ROW_IDENTITY}
    operations = set()
    chunks = []
    for item in plans:
        plan, raw = document(item, 64000, root)
        rows = plan["rows"]
        require(len(raw) == item["bytes"]
                and hashlib.sha1(b"blob " + str(len(raw)).encode() + b"\0" + raw).hexdigest() == item["git_blob"]
                and plan["native_preflight"] == index["native_preflight"]
                and 0 < len(rows) <= MAX_CHUNK_ROWS, "approved chunk changed")
        require(len(identities["hotel_id"]) + len(rows) <= MAX_INSPECTION_ROWS, "inspection row limit exceeded")
        operation = plan["operation"]
        if inspection:
            require(isinstance(operation, str) and bool(operation) and operation not in operations,
                    "invalid or duplicate inspection operation")
            operations.add(operation)
        for key, seen in identities.items():
            values = {row[key] for row in rows}
            require(len(values) == len(rows) and seen.isdisjoint(values), "duplicate approved " + key)
            seen.update(values)
        for row in rows:
            compare(row, by_hotel[row["hotel_id"]], channel)
        chunks.append(dict(sha256=item["sha256"], operation=operation,
                           hotels=[r["hotel_id"] for r in rows], messages=[r["target_message_id"] for r in rows]))
    return chunks


def inspect_input(index_sha, archive, root, index_path):
    """Check unapproved input bytes; this result never grants execution permission."""
    require(re.fullmatch(r"[a-f0-9]{64}", index_sha) is not None, "invalid index digest")
    require(root.is_absolute() and root.resolve(strict=True) == root and root.is_dir(),
            "invalid inspection root")
    index, _ = document({"path": index_path, "sha256": index_sha}, 64000, root)
    return {"evidence_only": True, "approval_verified": False, "write_admission": False,
            "index_sha256": index_sha, "chunks": checked_index(index, archive, root, inspection=True)}


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("approved_index_sha256")
    parser.add_argument("original_zip", type=Path)
    parser.add_argument("--inspect-root", type=Path,
                        help="Read-only preparation root; never an approval or APPLY input")
    parser.add_argument("--inspect-index", help="Relative config index inside the preparation root")
    args = parser.parse_args()
    if (args.inspect_root is None) != (args.inspect_index is None):
        parser.error("--inspect-root and --inspect-index must be supplied together")
    result = (approved_input(args.approved_index_sha256, args.original_zip)
              if args.inspect_root is None else
              inspect_input(args.approved_index_sha256, args.original_zip,
                            args.inspect_root, args.inspect_index))
    print(json.dumps(result, ensure_ascii=False))

