#!/usr/bin/env python3
"""Prepare up to ten covers from exact, already accepted local photographs.

This is the existing 600x600 AnyTour composition: a 600x354 hero, two
298x190 lower photographs, the retained white logo, and a 52px blue title
strip. It does not fetch photographs, write captions, contact a provider,
or publish. A final cover still needs visual acceptance by the owner.

Input JSON (paths resolve relative to the input file):
  {"schema_version": 1, "rows": [{
    "hotel_id": 123, "title": "ACCEPTED HOTEL DISPLAY NAME",
    "photos": [
      {"role": "hero", "path": "photos/123-hero.jpg",
       "source_url": "https://hotel.example/hero.jpg", "sha256": "...",
       "crop_center": [0.5, 0.5]},
      {"role": "detail_left", "path": "photos/123-left.jpg",
       "source_url": "https://hotel.example/left.jpg", "sha256": "..."},
      {"role": "detail_right", "path": "photos/123-right.jpg",
       "source_url": "https://hotel.example/right.jpg", "sha256": "..."}
    ]
  }]}

All additional input fields (source pages, approval references, photo dates,
limitations) are retained in the output receipt. Display names, ages and any
category badges must already be accepted in the input; none are inferred.

Usage:
  python3 tools/direct_demand/operations/prepare-commercial-covers.py \
    --input approved-cover-input.json --expected-input-sha256 SHA256 \
    --output-dir prepared-covers

Dependencies: Pillow and Liberation Sans Bold (or an explicit --font).
The checked brand asset is repository-relative and shared by every batch.
"""

import argparse
import hashlib
import json
import math
import re
from pathlib import Path
from urllib.parse import urlsplit

from PIL import Image, ImageDraw, ImageFont, ImageOps


ROLES = ("hero", "detail_left", "detail_right")
BOXES = ((0, 0, 600, 354), (0, 358, 298, 190), (302, 358, 298, 190))
LOGO_RELATIVE_PATH = ".github/traffic-publish/brand/anytour-logo-white.png"
LOGO_SHA256 = "b27cda894047b1ecb4125eea2ecf0db9d1e1674472cad62f70edf1ef1a6c968f"
DEFAULT_FONT = "LiberationSans-Bold.ttf"
HEX64 = re.compile(r"[a-f0-9]{64}\Z")


def digest(raw):
    return hashlib.sha256(raw).hexdigest()


def require(ok, message):
    if not ok:
        raise ValueError(message)


def accepted_sources(input_path, expected_sha256):
    raw = input_path.read_bytes()
    require(HEX64.fullmatch(expected_sha256) is not None, "input_sha256_invalid")
    require(digest(raw) == expected_sha256, "approved_input_changed")
    data = json.loads(raw)
    require(isinstance(data, dict) and data.get("schema_version") == 1, "input_schema_invalid")
    rows = data.get("rows")
    require(isinstance(rows, list) and 1 <= len(rows) <= 10, "cover_batch_must_have_1_to_10_hotels")
    ids = set()
    loaded = []
    for row in rows:
        require(isinstance(row, dict), "cover_row_invalid")
        hotel_id, title = row.get("hotel_id"), row.get("title")
        require(type(hotel_id) is int and 0 < hotel_id < 1000000000 and hotel_id not in ids,
                "hotel_id_invalid_or_duplicate")
        ids.add(hotel_id)
        require(isinstance(title, str) and 1 <= len(title.strip()) <= 160 and
                not any(c in title for c in "\n\r\t"), "accepted_title_invalid")
        photos = row.get("photos")
        require(isinstance(photos, list) and len(photos) == 3, "three_source_photos_required")
        require(all(isinstance(p, dict) for p in photos), "source_photo_invalid")
        by_role = {p.get("role"): p for p in photos}
        require(set(by_role) == set(ROLES), "source_photo_roles_invalid")
        source_hashes, source_urls, rendered_sources = set(), set(), []
        for role in ROLES:
            photo = by_role[role]
            source_url, source_sha = photo.get("source_url"), photo.get("sha256")
            require(isinstance(source_url, str), "source_url_missing")
            url = urlsplit(source_url)
            require(url.scheme == "https" and url.hostname and not url.username and not url.password,
                    "source_url_invalid")
            require(isinstance(source_sha, str) and HEX64.fullmatch(source_sha), "source_sha256_invalid")
            require(source_url not in source_urls and source_sha not in source_hashes,
                    "source_photos_not_distinct")
            source_urls.add(source_url)
            source_hashes.add(source_sha)
            require(isinstance(photo.get("path"), str) and photo["path"], "source_photo_path_missing")
            path = (input_path.parent / photo["path"]).resolve()
            photo_raw = path.read_bytes()
            require(digest(photo_raw) == source_sha, "accepted_source_photo_changed")
            with Image.open(path) as original:
                original.load()
                dimensions, photo_format = list(original.size), original.format
                rgb = ImageOps.exif_transpose(original).convert("RGB")
            center = photo.get("crop_center", [0.5, 0.5])
            require(isinstance(center, (list, tuple)) and len(center) == 2 and
                    all(type(v) in (int, float) and 0 <= v <= 1 for v in center),
                    "crop_center_invalid")
            rendered_sources.append((rgb, tuple(center), {
                **photo,
                "source_bytes": len(photo_raw),
                "source_dimensions": dimensions,
                "source_format": photo_format,
                "actual_sha256": digest(photo_raw),
                "processing": "ordinary EXIF orientation, proportional resize and crop; no generated photo content",
            }))
        loaded.append((row, rendered_sources))
    return data, loaded


def render_cover(row, sources, logo, font_name):
    canvas = Image.new("RGB", (600, 600), "white")
    provenance = []
    for (photo, center, source), box in zip(sources, BOXES):
        fitted = ImageOps.fit(photo, (box[2], box[3]), method=Image.Resampling.LANCZOS,
                              centering=center)
        canvas.paste(fitted, box[:2])
        provenance.append(source)
    canvas.paste(logo, (426, 13), logo)
    draw = ImageDraw.Draw(canvas)
    draw.rectangle((0, 548, 600, 600), fill="#2743CB")
    title = row["title"].strip().upper()
    for size in range(28, 13, -1):
        font = ImageFont.truetype(font_name, size)
        if draw.textlength(title, font=font) <= 560:
            break
    require(draw.textlength(title, font=font) <= 560, "accepted_title_does_not_fit_banner")
    bbox = draw.textbbox((0, 0), title, font=font)
    y = 548 + (52 - (bbox[3] - bbox[1])) / 2 - bbox[1]
    draw.text((20, round(y)), title, font=font, fill="white")
    return canvas, provenance, {
        "title": title,
        "font_size": size,
        "text_width": draw.textlength(title, font=font),
        "font": font_name,
    }


def prepare(input_path, expected_sha256, output_dir, font_name=DEFAULT_FONT):
    data, loaded = accepted_sources(input_path, expected_sha256)
    repository_root = Path(__file__).resolve().parents[3]
    logo_path = repository_root / LOGO_RELATIVE_PATH
    logo_raw = logo_path.read_bytes()
    require(digest(logo_raw) == LOGO_SHA256, "canonical_brand_asset_changed")
    with Image.open(logo_path) as original:
        logo = original.convert("RGBA")
    require(logo.size == (152, 41), "canonical_brand_dimensions_changed")

    # Resolve every input and render every output before writing the batch.
    prepared = []
    for row, photos in loaded:
        canvas, provenance, banner = render_cover(row, photos, logo, font_name)
        prepared.append((row, canvas, provenance, banner))
    output_dir.mkdir(parents=True, exist_ok=True)
    receipt_rows = []
    for row, canvas, provenance, banner in prepared:
        filename = f"{row['hotel_id']}.png"
        path = output_dir / filename
        canvas.save(path, optimize=True)
        output_raw = path.read_bytes()
        receipt_rows.append({
            **row,
            "source_photos": provenance,
            "filename": filename,
            "sha256": digest(output_raw),
            "bytes": len(output_raw),
            "dimensions": [600, 600],
            "banner": banner,
        })

    contact = Image.new("RGB", (1500, math.ceil(len(prepared) / 5) * 325), "#edf2f6")
    draw = ImageDraw.Draw(contact)
    font = ImageFont.truetype(font_name, 16)
    for index, (row, canvas, _, _) in enumerate(prepared):
        x, y = index % 5 * 300, index // 5 * 325
        contact.paste(canvas.resize((300, 300), Image.Resampling.LANCZOS), (x, y + 25))
        draw.text((x + 5, y + 4), str(row["hotel_id"]), font=font, fill="black")
    contact.save(output_dir / "contact-sheet.jpg", quality=93)
    receipt = {
        **{k: v for k, v in data.items() if k != "rows"},
        "state": "PREPARED_AWAITING_VISUAL_ACCEPTANCE",
        "input_sha256": expected_sha256,
        "logo_asset": LOGO_RELATIVE_PATH,
        "logo_sha256": LOGO_SHA256,
        "rows": receipt_rows,
        "provider_calls": 0,
        "remote_writes": 0,
    }
    (output_dir / "cover-receipt.json").write_text(
        json.dumps(receipt, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    return {"ok": True, "prepared": len(receipt_rows), "output_dir": str(output_dir),
            "receipt": str(output_dir / "cover-receipt.json"), "provider_calls": 0,
            "remote_writes": 0}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--input", required=True, type=Path)
    parser.add_argument("--expected-input-sha256", required=True)
    parser.add_argument("--output-dir", required=True, type=Path)
    parser.add_argument("--font", default=DEFAULT_FONT)
    args = parser.parse_args()
    print(json.dumps(prepare(args.input.resolve(), args.expected_input_sha256,
                             args.output_dir.resolve(), args.font), ensure_ascii=False))


if __name__ == "__main__":
    main()
