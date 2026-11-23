#!/usr/bin/env python3
"""Build #1566 owner inputs offline from the original, successful 46-pair Actions artifact.

Usage: build_telegram_46.py --run run.json --artifact artifact.json --zip receipt.zip --out fresh-dir
run.json and artifact.json are the original GitHub REST run and artifact metadata.
The output is data only. The owner must admit the exact proof and allowed rows in the existing editor.
"""
import argparse
import hashlib
import io
import json
import os
from pathlib import Path
import re
import tempfile
from datetime import datetime
from zipfile import ZipFile

REPO = "pyatkoff/max-search-bot"
BRANCH = "ops/1566-telegram-46-native-read-20261123"
HEAD = "db1157a5091ff0f708d7825acf6ca4e1dc9a5339"
WORKFLOW = ".github/workflows/read-1566-telegram-46-20261123.yml"
BUTTON = "✈️ Рассчитать на мои даты"
BOT = "https://t.me/anyru_bot"
PACKET = [[241,21,220,"2023-12-07T12:09:28Z"],[15693,532,224,"2023-12-09T08:13:05Z"],[293,83,249,"2023-12-17T11:14:05Z"],[949,438,266,"2023-12-23T07:29:10Z"],[1972,391,316,"2024-01-09T12:12:17Z"],[2588,689,346,"2024-01-19T12:37:53Z"],[24909,188,372,"2024-01-27T12:20:17Z"],[32342,239,594,"2024-05-04T11:34:16Z"],[57028,76,626,"2024-05-19T08:21:52Z"],[65877,227,646,"2024-05-29T08:13:27Z"],[73343,562,716,"2024-06-30T09:15:44Z"],[43689,878,884,"2024-10-02T07:37:49Z"],[47025,271,901,"2024-10-16T13:28:13Z"],[139,140,905,"2024-10-18T13:06:24Z"],[435,96,906,"2024-10-19T12:25:50Z"],[513,217,908,"2024-10-22T14:05:27Z"],[2470,658,914,"2024-10-28T09:48:08Z"],[56092,99,915,"2024-10-29T08:07:35Z"],[59114,619,917,"2024-10-30T14:43:35Z"],[63191,674,918,"2024-10-31T11:18:04Z"],[76588,308,923,"2024-11-05T09:58:28Z"],[77914,45,924,"2024-11-08T09:35:10Z"],[106,468,928,"2024-11-14T08:58:46Z"],[111,134,930,"2024-11-18T12:10:16Z"],[114,469,948,"2024-11-27T14:53:37Z"],[140,209,949,"2024-11-29T08:00:47Z"],[178,79,951,"2024-12-03T09:25:20Z"],[195,617,952,"2024-12-05T08:52:50Z"],[206,622,963,"2024-12-20T11:26:06Z"],[231,598,969,"2025-01-03T13:15:00Z"],[246,106,970,"2025-01-05T12:12:46Z"],[263,90,999,"2025-01-27T15:11:03Z"],[266,757,1003,"2025-02-04T12:17:15Z"],[268,23,1012,"2025-02-09T12:23:25Z"],[310,11,1016,"2025-02-14T08:16:29Z"],[371,80,1037,"2025-03-07T10:04:31Z"],[381,13,1042,"2025-03-11T10:58:57Z"],[389,81,1049,"2025-03-15T07:59:06Z"],[416,42,1051,"2025-03-18T07:45:07Z"],[465,116,1052,"2025-03-19T07:49:50Z"],[497,597,1057,"2025-03-25T07:40:10Z"],[932,574,1059,"2025-03-27T07:35:13Z"],[948,582,1087,"2025-04-29T07:38:50Z"],[964,259,1110,"2025-05-30T07:31:39Z"],[975,254,1131,"2025-06-22T10:20:22Z"],[1013,591,920,"2024-11-03T14:23:42Z"]]
PACKET_DIGEST = "f028a9a421803577d8137eaf6c13235f510a25a6bc4e70e8e5666ba9dd6df47b"
PRICE_BLOCK = re.compile(
    r"💰\ufe0f?\s*От\s+[\d \xa0\u202f]+\s*₽\s+за двоих\s*"
    r"✈\ufe0f?\s*Вылет из [^\n✈👥]{1,100}?\d{2}\.\d{2}\.\d{4}\s*·\s*\d{1,2}\s+ноч[а-яё]+\s*"
    r"👥\ufe0f?\s*2\s+взрослых\s*🛏\ufe0f?\s*[^\n🍽]{1,160}\s*"
    r"🍽\ufe0f?\s*[^\n]{1,90}?\s*Цена найдена мониторингом\s+\d{2}\.\d{2}\.\d{4},\s*"
    r"\d{2}:\d{2}\s+МСК\.\s*Стоимость и наличие могут измениться\.", re.I)
STALE_PRICE = re.compile(r"[₽€$]|\b(?:руб(?:\.|лей|ля)?|RUB|USD|EUR)\b|Цена найдена мониторингом|💰", re.I)


def need(value, reason):
    if not value:
        raise ValueError(reason)


def raw_json(data):
    # Matches the source program's PHP JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES.
    s = json.dumps(data, ensure_ascii=False, separators=(",", ":"), allow_nan=False)
    return s.replace("\u2028", "\\u2028").replace("\u2029", "\\u2029").encode()


def digest(data):
    return hashlib.sha256(data).hexdigest()


def sha(value, length=64):
    return isinstance(value, str) and re.fullmatch("[0-9a-f]{" + str(length) + "}", value) is not None


def positive(value):
    return type(value) is int and value > 0


def caption(text):
    need(isinstance(text, str) and text.strip(), "source_caption_missing")
    matches = list(PRICE_BLOCK.finditer(text))
    need(len(matches) <= 1, "ambiguous_price_blocks")
    if matches:
        match = matches[0]
        # Preserve all descriptive and CTA text on either side of the recognised monitor block.
        text = "\n\n".join(part for part in (text[:match.start()].rstrip(), text[match.end():].lstrip()) if part)
    text = text.strip()
    need(not STALE_PRICE.search(text), "unrecognized_price_needs_review")
    need(text and len(text.encode("utf-16-le")) <= 2048, "telegram_caption_length")
    return text


def unpack(run, artifact, zipped):
    need(run.get("repository", {}).get("full_name") == REPO and run["repository"].get("id") == 1342493152
         and run.get("head_repository", {}).get("id") == 1342493152
         and run.get("actor", {}).get("id") == 226193297 and run["actor"].get("login") == "pyatkoff"
         and run.get("path") == WORKFLOW and run.get("head_branch") == BRANCH
         and run.get("head_sha") == HEAD and run.get("event") == "push"
         and run.get("run_attempt") == 1 and run.get("status") == "completed"
         and run.get("conclusion") == "success" and positive(run.get("id")), "original_run_not_confirmed")
    linked = artifact.get("workflow_run") or {}
    need(positive(artifact.get("id")) and artifact.get("name") == "issue-1566-telegram-46-preflight"
         and linked.get("id") == run["id"] and linked.get("head_sha") == HEAD
         and linked.get("repository_id") == 1342493152 and artifact.get("expired") is False
         and len(zipped) <= 8_000_000 and artifact.get("size_in_bytes") == len(zipped)
         and artifact.get("digest") == "sha256:" + digest(zipped), "original_artifact_not_confirmed")
    with ZipFile(io.BytesIO(zipped)) as z:
        need(z.namelist() == ["registry.json"] and z.infolist()[0].file_size <= 16_000_000, "archive_members")
        registry = z.read("registry.json")
    d = json.loads(registry)
    checkpoint = d.pop("checkpoint_sha256", None)
    need(checkpoint == digest(raw_json(d)), "native_checkpoint_changed")
    need(d.get("ok") is True and d.get("schema") == 1 and d.get("issue") == 1566
         and d.get("operation") == "hotel_archive_telegram_46_registry" and d.get("public_get_attempts") == 92
         and d.get("native_run_id") == str(run["id"]) and d.get("native_run_attempt") == 1
         and d.get("price_editor_shared_read_lease") is True
         and all(d.get(k) == 0 for k in ("production_writes", "provider_writes", "bot_starts"))
         and sha(d.get("repository_sha"), 40) and sha(d.get("application_sha"), 40), "receipt_scope")
    channel = d.get("target_channel", {})
    need(channel.get("registry_id") == 6 and channel.get("chat_id") == "-1001845835645"
         and channel.get("url") == "https://t.me/anytour", "channel_identity_changed")
    channel_ready = (channel.get("config_version") == 2 and channel.get("button_text") == BUTTON
                     and channel.get("bot_url") == BOT and d.get("native_channel_rights", {}).get("state") == "ready"
                     and d.get("native_public_channel_binding") == {"anytour": True, "any_toour": True})
    need(digest(raw_json(PACKET)) == PACKET_DIGEST and len(d["rows"]) == len(PACKET) == 46, "immutable_queue_changed")
    proof = dict(run_id=str(run["id"]), run_attempt=1, artifact_id=artifact["id"], artifact_zip_sha256=digest(zipped),
                 registry_sha256=digest(registry), checkpoint_sha256=checkpoint,
                 repository_sha=d["repository_sha"], application_sha=d["application_sha"])
    return d["rows"], proof, channel_ready


def editor_rows(record, approved):
    hotel, source_id, target_id, timestamp = approved
    need(record["source_matches"] is True and record["historical_target_matches"] is True
         and record["target_media_lock_free"] is True and record["prior_media_operation_directory_exists"] is False
         and record["prior_media_operation"]["state"] == "absent" and record["target_current_pin_matches"] is False,
         "native_state_requires_readback")
    need(not any(str(p["post_channel_id"]) == "6" for p in record["existing_large_channel_hotel_registry_matches"]),
         "already_in_large_channel")
    s, t, native = record["source_public"], record["target_public"], record["source_native"]
    need(s["ok"] is True and t["ok"] is True and s["single_photo"] is True and t["single_photo"] is True
         and s["url"] == "https://t.me/any_toour/" + str(source_id)
         and t["url"] == "https://t.me/anytour/" + str(target_id)
         and t["buttons"] == [] and t["published_unixtime"] == int(datetime.fromisoformat(timestamp).timestamp()),
         "public_observation_changed")
    need(native["state"] == "sent" and native["current_token_resolves"] is True
         and str(native["hotel_id"]) == str(hotel) and positive(native["publication_id"])
         and positive(native["version_id"]) and native["post_url"] == s["url"]
         and native["native_claim_held_or_unknown"] is False and len(native["attachments"]) == 1
         and native["attachments"][0]["reusable_telegram_photo"] is True, "source_registry_not_ready")
    # Telegram's embed often omits the native keyboard. A visible conflicting button still holds the row.
    visible = s["buttons"]
    need((not visible or (len(visible) == 1 and visible[0]["hotel_url"] == native["button_url"]))
         and re.fullmatch(r"https://t\.me/anyru_bot\?start=p_[A-Za-z0-9_-]{8,60}", native["button_url"]),
         "source_button_context_unconfirmed")
    photo = native["attachments"][0]
    checks = [native["content_hash"], native["caption_sha256"], s["photo_marker_sha256"], t["caption_sha256"],
              t["photo_marker_sha256"], photo["file_id_sha256"], photo["file_unique_id_sha256"]]
    need(all(sha(v) for v in checks) and native["caption_sha256"] == s["caption_sha256"] == digest(s["caption"].encode())
         and t["caption_sha256"] == digest(t["caption"].encode()), "native_content_hash_changed")
    text = caption(s["caption"])
    row = dict(hotel_id=hotel, source_message_id=str(source_id), source_publication_id=native["publication_id"],
               source_version_id=native["version_id"], source_content_hash=native["content_hash"],
               source_caption_sha256=s["caption_sha256"], source_file_id_sha256=photo["file_id_sha256"],
               source_file_unique_id_sha256=photo["file_unique_id_sha256"], target_message_id=str(target_id),
               target_published_unixtime=t["published_unixtime"], target_caption_sha256=t["caption_sha256"],
               target_photo_marker_sha256=t["photo_marker_sha256"], target_config_version=2, next_caption=text,
               next_caption_sha256=digest(text.encode()), button_text=BUTTON, bot_url=BOT, price_template="",
               price_record_id=None, source_photo_marker_sha256=s["photo_marker_sha256"])
    old = dict(message_id=str(target_id), hotel_id=hotel, source_message_id=str(source_id),
               source_publication_id=native["publication_id"], source_version_id=native["version_id"],
               source_content_hash=native["content_hash"], published_at=timestamp, caption=text)
    return row, old


def chunks(rows, make, limit):
    groups = []
    for row in rows:
        if not groups or len(raw_json(make(groups[-1] + [row], len(groups)))) > limit:
            groups.append([])
        groups[-1].append(row)
        need(len(raw_json(make(groups[-1], len(groups)))) <= limit, "single_row_too_large")
    return [make(group, i) for i, group in enumerate(groups, 1)]


def compile_inputs(records, proof, channel_ready, editor):
    chosen, held = [], []
    for record, approved in zip(records, PACKET, strict=True):
        h, s, t, _ = approved
        need([record.get("hotel_id"), record.get("source_message_id"), record.get("target_message_id")] ==
             [h, str(s), str(t)], "native_row_identity_changed")
        barriers = record.get("preview_blockers")
        need(isinstance(barriers, list) and all(isinstance(b, str) for b in barriers)
             and record.get("preview_ready") is (not barriers), "native_decision_ambiguous")
        if not channel_ready:
            barriers = [*barriers, "editor_channel_configuration_or_rights_changed"]
        if barriers:
            held.append(dict(hotel_id=h, target=t, reasons=barriers))
            continue
        try:
            nxt, old = editor_rows(record, approved)
            target = record["target_native"]
            if target is not None:
                need(editor == "historical", "installed_transfer_editor_requires_unregistered_target")
                need(target["state"] == "sent" and target["native_claim_held_or_unknown"] is False
                     and target["post_url"] == record["target_public"]["url"]
                     and target["caption_sha256"] == record["target_public"]["caption_sha256"]
                     and target["content_edit_journal"]["state"] == "absent"
                     and not target["monitor_price_record_id"] and len(target["attachments"]) == 1
                     and target["attachments"][0]["type"] == "image", "existing_target_needs_readback")
            if editor == "historical":
                need(40 <= len(old["caption"]) <= 900 and not re.search(r"https?://", old["caption"], re.I),
                     "historical_editor_caption_contract")
            chosen.append(old if editor == "historical" else nxt)
        except ValueError as e:
            held.append(dict(hotel_id=h, target=t, reasons=[str(e)]))

    def plan(rows, number):
        if editor == "historical":
            return dict(schema=1, runtime_sha=proof["application_sha"], rows=rows)
        return dict(schema=1, operation=f"anytour_hotel_transfer_46_20261123_{number:02d}",
                    execution="explicit_owner_apply_only", native_preflight=proof, monitor_source_url=None,
                    price_policy="omit_previous_price_keep_hotel_calculation_button", rows=rows)
    files = {}
    index = dict(editor=editor, native_preflight=proof, ready=[r["hotel_id"] for r in chosen], held=held,
                 plans={}, owner_source_messages={str(r["hotel_id"]):r["source_message_id"] for r in chosen})
    for i, group in enumerate(chunks(chosen, plan, 60000 if editor == "historical" else 64000), 1):
        key = f"{editor}-{i:02d}.json"
        files[key] = raw_json(group)
        index["plans"][key] = dict(sha256=digest(files[key]), bytes=len(files[key]),
                                   hotel_ids=[row["hotel_id"] for row in group["rows"]])
    files["index.json"] = raw_json(index)
    return files


def main():
    p = argparse.ArgumentParser(description=__doc__)
    for key in ["run", "artifact", "zip", "out"]:
        p.add_argument("--" + key, required=True, type=Path)
    p.add_argument("--editor", choices=["historical", "transfer"], default="historical")
    args = p.parse_args()
    need(not args.out.exists() and args.out.parent.is_dir(), "fresh_output_directory_required")
    need(args.zip.stat().st_size <= 8_000_000, "archive_too_large")
    rows, proof, channel_ready = unpack(json.loads(args.run.read_bytes()), json.loads(args.artifact.read_bytes()), args.zip.read_bytes())
    files = compile_inputs(rows, proof, channel_ready, args.editor)
    with tempfile.TemporaryDirectory(dir=args.out.parent) as stage:
        folder = Path(stage) / "ready"
        folder.mkdir()
        for name, data in files.items():
            (folder / name).write_bytes(data)
        os.rename(folder, args.out)
    index = json.loads(files["index.json"])
    print(f"{len(index['ready'])} ready, {len(index['held'])} held")


if __name__ == "__main__":
    main()
