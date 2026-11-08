#!/usr/bin/env python3
"""Offline bounded manifests for existing AnyTour captions; no network or writes outside output-dir."""
from __future__ import annotations

import argparse
import copy
import hashlib
import json
from pathlib import Path
import re
from datetime import datetime

SNAPSHOT_OPERATION = "commercial-short-heading-registry-audit-20261107-v1"
BUTTON = "✈️ Узнать стоимость тура"
SOURCES = {"max": "max_anytour_msk1", "telegram": "tg_anytour_msk"}
SNAPSHOT_POSTS = 916
MAX_BATCH_POSTS = 100
THROTTLE_US = 3_200_000


def need(value, reason):
    if not value:
        raise ValueError(reason)


def sha256(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def is_int(value) -> bool:
    return type(value) is int


def valid_sha(value, length=64) -> bool:
    return isinstance(value, str) and re.fullmatch(r"[a-f0-9]{%d}" % length, value) is not None


def short_title(text: str) -> str | None:
    """Remove only the two approved exact suffix spellings on the first LF-delimited line."""
    need(isinstance(text, str) and "\x00" not in text, "invalid_caption")
    head, separator, rest = text.partition("\n")
    suffix = re.search(r" с перел[её]том\Z", head)
    if suffix is None:
        return None
    need(suffix.start() > 0, "empty_short_heading")
    result = head[:suffix.start()] + separator + rest
    need(result != text, "unchanged_caption")
    need(len(result.encode("utf-16-le")) // 2 <= 1024, "caption_too_long")
    # The substring following the first newline is intentionally never normalized.
    need(result.partition("\n")[1:] == text.partition("\n")[1:], "body_changed")
    return result


def validate_snapshot(snapshot: dict, expected_sha: str) -> list[dict]:
    need(valid_sha(expected_sha, 40), "invalid_installed_sha")
    need(isinstance(snapshot, dict) and snapshot.get("operation") == SNAPSHOT_OPERATION,
         "snapshot_operation_changed")
    need(snapshot.get("ok") is True and snapshot.get("post_write_attempts") == 0
         and snapshot.get("provider_calls") == 0, "snapshot_not_successful_read_only")
    need(snapshot.get("installed_sha") == expected_sha, "snapshot_source_changed")
    observed = snapshot.get("observed_at_utc")
    need(isinstance(observed, str), "snapshot_time_missing")
    stamp = datetime.fromisoformat(observed.replace("Z", "+00:00"))
    need(stamp.utcoffset() is not None and stamp.utcoffset().total_seconds() == 0,
         "snapshot_time_not_utc")
    rows = snapshot.get("posts")
    need(isinstance(rows, list) and len(rows) == SNAPSHOT_POSTS, "snapshot_scope_not_916")
    publications, messages, pairs = set(), set(), {}
    for row in rows:
        need(isinstance(row, dict), "invalid_snapshot_row")
        platform = row.get("platform")
        hotel = row.get("hotel_id")
        need(platform in SOURCES and is_int(hotel) and hotel > 0, "invalid_hotel_platform")
        # Some already-sent legacy snapshots omit native keyboard metadata.
        # Preserve that fact; MAX provider preflight / TG edit response confirms
        # the actual button through the existing operational transport.
        need(row.get("status") == "sent" and type(row.get("button_observed_in_snapshot")) is bool
             and row.get("button_resolves_to_current_post") is True,
             "snapshot_post_not_confirmed")
        for key in ("publication_id", "version_id", "config_version"):
            need(is_int(row.get(key)) and row[key] > 0, "invalid_" + key)
        need(row["publication_id"] not in publications, "duplicate_publication")
        publications.add(row["publication_id"])
        need(row.get("source_key") == SOURCES[platform], "snapshot_source_key_changed")
        mid = row.get("message_id")
        need(isinstance(mid, str), "invalid_message_id")
        if platform == "telegram":
            need(re.fullmatch(r"[1-9][0-9]{0,19}", mid) is not None,
                 "unsupported_telegram_publication_identity")
            need(row.get("post_url") == "https://t.me/any_toour/" + mid,
                 "telegram_post_url_changed")
        else:
            need(re.fullmatch(r"[A-Za-z0-9_.-]{1,191}", mid) is not None,
                 "invalid_max_message_id")
            need(isinstance(row.get("post_url"), str) and re.fullmatch(
                r"https://max\.ru/channel_anytour/[A-Za-z0-9_-]+", row["post_url"]),
                "max_post_url_changed")
        need((platform, mid) not in messages, "duplicate_provider_message")
        messages.add((platform, mid))
        need(platform not in pairs.setdefault(hotel, set()), "duplicate_hotel_platform")
        pairs[hotel].add(platform)
        text = row.get("caption")
        need(isinstance(text, str) and "\x00" not in text, "invalid_caption")
        need(valid_sha(row.get("caption_sha256"))
             and sha256(text.encode("utf-8")) == row["caption_sha256"], "caption_hash_changed")
        need(row.get("media_count") == 1 and row.get("media_types") == ["image"],
             "unsupported_media_scope")
        signature = row.get("photo_signature")
        need(isinstance(signature, str) and re.fullmatch(
            re.escape(platform) + r":[A-Za-z0-9_-]+", signature), "photo_signature_missing")
        for key in ("attachments_sha256", "button_url_sha256"):
            need(valid_sha(row.get(key)), key + "_missing")
    need(len(pairs) == 458 and all(p == set(SOURCES) for p in pairs.values()),
         "snapshot_pair_scope_changed")
    return rows


def prepared_row(row: dict) -> dict | None:
    new_text = short_title(row["caption"])
    if new_text is None:
        return None
    fields = ("hotel_id", "platform", "publication_id", "version_id", "message_id",
              "post_url", "source_key", "config_version", "photo_signature",
              "attachments_sha256", "button_url_sha256", "button_observed_in_snapshot")
    result = {key: row[key] for key in fields}
    result.update(old_text=row["caption"], new_text=new_text,
                  old_text_sha256=row["caption_sha256"],
                  new_text_sha256=sha256(new_text.encode("utf-8")))
    return result


def encoded(value) -> bytes:
    return (json.dumps(value, ensure_ascii=False, indent=2) + "\n").encode("utf-8")


def build(snapshot: dict, snapshot_raw: bytes, expected_sha: str, prefix: str,
          batch_size: int = 20) -> tuple[list[dict], dict]:
    need(re.fullmatch(r"commercial-[a-z0-9][a-z0-9-]{0,90}", prefix) is not None,
         "invalid_operation_prefix")
    need(is_int(batch_size) and 1 <= batch_size <= MAX_BATCH_POSTS, "invalid_batch_size")
    rows = validate_snapshot(snapshot, expected_sha)
    prepared = [prepared_row(row) for row in rows]
    changed = [row for row in prepared if row is not None]
    unchanged = [row["publication_id"] for row, selected in zip(rows, prepared) if selected is None]
    canary = [row for row in changed if row["hotel_id"] == 1543]
    if changed:
        need(len(canary) == 2 and {row["platform"] for row in canary} == set(SOURCES),
             "susesi_canary_requires_two_current_long_titles")
    canary.sort(key=lambda row: 0 if row["platform"] == "max" else 1)
    remaining = [row for row in changed if row["hotel_id"] != 1543]
    chunks = ([canary] if changed else []) + [remaining[i:i+batch_size]
                                               for i in range(0, len(remaining), batch_size)]
    manifests, predecessor = [], None
    for index, chunk in enumerate(chunks):
        need(1 <= len(chunk) <= MAX_BATCH_POSTS, "batch_scope_outside_bounds")
        operation = prefix + ("-canary-v1" if index == 0 else f"-batch{index:03d}-v1")
        manifest = {
            "schema_version": 1,
            "operation": operation,
            "expected_installed_sha": expected_sha,
            "update_kind": "first_line_flight_suffix_only",
            "button_text": BUTTON,
            "throttle_microseconds": THROTTLE_US,
            "batch_kind": "canary" if index == 0 else "batch",
            "batch_index": index,
            "row_count": len(chunk),
            "predecessor": predecessor,
            "snapshot": {"operation": SNAPSHOT_OPERATION, "sha256": sha256(snapshot_raw),
                         "installed_sha": expected_sha,
                         "observed_at_utc": snapshot["observed_at_utc"],
                         "post_count": SNAPSHOT_POSTS},
            "rows": chunk,
        }
        manifests.append(manifest)
        predecessor = {"operation": operation, "manifest_sha256": sha256(encoded(manifest))}
    selected = [row["publication_id"] for manifest in manifests for row in manifest["rows"]]
    need(len(selected) == len(set(selected)) == len(changed), "plan_duplicate_or_missing_post")
    index = {
        "ok": True, "snapshot_sha256": sha256(snapshot_raw),
        "expected_installed_sha": expected_sha, "snapshot_posts": len(rows),
        "selected_posts": len(changed), "no_matching_suffix_publication_ids": unchanged,
        "batch_size": batch_size, "max_batch_posts": MAX_BATCH_POSTS,
        "throttle_microseconds": THROTTLE_US,
        "provider_calls": 0, "post_write_attempts": 0,
        "manifests": [{"operation": m["operation"], "file": f"{i:03d}-{m['operation']}.json",
                       "sha256": sha256(encoded(m)), "posts": len(m["rows"])}
                      for i, m in enumerate(manifests)],
    }
    return manifests, index


def self_test():
    cases = [
        ("✈️ Туры в Susesi Luxury Resort с перелётом\n📍 Белек, Турция.\n\nтекст с перелётом\n",
         "✈️ Туры в Susesi Luxury Resort\n📍 Белек, Турция.\n\nтекст с перелётом\n"),
        ("🏨 A😀e\u0301 с перелетом\n\nцена 1 000 ₽\r\nhttps://example.invalid/?a=1&b=2",
         "🏨 A😀e\u0301\n\nцена 1 000 ₽\r\nhttps://example.invalid/?a=1&b=2"),
        ("Hotel с перелётом", "Hotel"),
        ("Hotel\nс перелётом", None),
        ("Hotel с перелётом ", None),
        ("Hotel с перелётом и питанием", None),
    ]
    for before, after in cases:
        assert short_title(before) == after
        if after is not None:
            assert after.partition("\n")[1:] == before.partition("\n")[1:]
    expected = "a" * 40
    rows = []
    for i in range(458):
        hotel = 1543 if i == 0 else i
        for j, platform in enumerate(SOURCES):
            pub = 2 * i + j + 1
            text = f"✈️ Hotel {hotel} с перелётом\n{platform} body\n"
            rows.append({"hotel_id": hotel, "platform": platform, "publication_id": pub,
                         "version_id": pub + 1000, "message_id": str(pub), "config_version": 2,
                         "post_url": ("https://max.ru/channel_anytour/" if platform == "max"
                                      else "https://t.me/any_toour/") + str(pub),
                         "status": "sent", "caption": text, "caption_sha256": sha256(text.encode()),
                         "source_key": SOURCES[platform], "media_count": 1, "media_types": ["image"],
                         "photo_signature": platform + ":" + str(pub), "attachments_sha256": "b"*64,
                         "button_url_sha256": "c"*64, "button_observed_in_snapshot": True,
                         "button_resolves_to_current_post": True})
    snapshot = {"operation": SNAPSHOT_OPERATION, "ok": True, "post_write_attempts": 0,
                "provider_calls": 0, "installed_sha": expected,
                "observed_at_utc": "2026-11-08T03:00:00+00:00", "posts": rows}
    manifests, plan = build(snapshot, encoded(snapshot), expected, "commercial-short-heading-test")
    assert plan["selected_posts"] == 916 and len(manifests[0]["rows"]) == 2
    assert max(len(m["rows"]) for m in manifests[1:]) == 20
    assert manifests[0]["rows"][0]["new_text"] != manifests[0]["rows"][1]["new_text"]
    assert all(m["predecessor"]["manifest_sha256"] == sha256(encoded(manifests[i-1]))
               for i, m in enumerate(manifests) if i)
    for mutate in (lambda s: s["posts"].pop(),
                   lambda s: s["posts"].append(copy.deepcopy(s["posts"][0])),
                   lambda s: s["posts"][1].update(message_id="group:123"),
                   lambda s: s["posts"][2].update(publication_id=s["posts"][0]["publication_id"]),
                   lambda s: s["posts"][0].pop("attachments_sha256"),
                   lambda s: s["posts"][0].update(button_resolves_to_current_post=False),
                   lambda s: s.update(installed_sha="d"*40)):
        changed = copy.deepcopy(snapshot)
        mutate(changed)
        try:
            build(changed, encoded(changed), expected, "commercial-short-heading-test")
        except (ValueError, KeyError):
            pass
        else:
            raise AssertionError("unsafe_snapshot_admitted")
    for limit in (0, 101, True):
        try:
            build(snapshot, encoded(snapshot), expected, "commercial-short-heading-test", limit)
        except ValueError:
            pass
        else:
            raise AssertionError("unsafe_batch_limit_admitted")
    print(json.dumps({"ok": True, "transform_cases": len(cases), "snapshot_posts": len(rows),
                      "scope_rejections": 10, "provider_calls": 0, "post_write_attempts": 0}))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--self-test", action="store_true")
    parser.add_argument("--snapshot", type=Path)
    parser.add_argument("--expected-installed-sha")
    parser.add_argument("--operation-prefix")
    parser.add_argument("--batch-size", type=int, default=20)
    parser.add_argument("--output-dir", type=Path)
    args = parser.parse_args()
    if args.self_test:
        self_test()
        return
    need(all((args.snapshot, args.expected_installed_sha, args.operation_prefix, args.output_dir)),
         "missing_required_arguments")
    raw = args.snapshot.read_bytes()
    snapshot = json.loads(raw)
    manifests, index = build(snapshot, raw, args.expected_installed_sha, args.operation_prefix,
                            args.batch_size)
    # Never overwrite an earlier review packet or quietly change its manifest hashes.
    args.output_dir.mkdir(parents=True, exist_ok=False)
    for manifest, entry in zip(manifests, index["manifests"]):
        with (args.output_dir / entry["file"]).open("xb") as file:
            file.write(encoded(manifest))
    with (args.output_dir / "plan-index.json").open("xb") as file:
        file.write(encoded(index))
    print(json.dumps({key: index[key] for key in ("ok", "snapshot_posts", "selected_posts",
                                                "batch_size", "provider_calls", "post_write_attempts")},
                     ensure_ascii=False))


if __name__ == "__main__":
    main()
