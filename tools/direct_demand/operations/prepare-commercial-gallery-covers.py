#!/usr/bin/env python3
"""Prepare reviewable branded covers from a screened hotel intake."""

import argparse
from concurrent.futures import ThreadPoolExecutor
import hashlib
import io
import json
from pathlib import Path
import runpy

from PIL import Image, ImageDraw, ImageOps
from requests import RequestException


def render(row, logo, composer, directory):
    hotel_id = row["native_tourvisor_id"]
    result = {
        "native_tourvisor_id": hotel_id,
        "work_id": row["work_id"],
        "status": row["stage"],
        "publication_allowed": False,
    }
    if row["stage"] != "editorial_and_visual_review":
        return result

    urls = list(dict.fromkeys(row["source_photo_samples"]))
    photos, sources, failures = [], [], []
    try:
        for url in urls:
            try:
                if composer["check_photo_url"](url) != row["country_id"]:
                    raise ValueError("gallery country differs from the hotel profile")
                photo, source = composer["download_photo"](url)
                if source["sha256"] in {prior["sha256"] for prior in sources}:
                    continue
                photos.append(ImageOps.contain(photo, (1600, 1600), Image.Resampling.LANCZOS))
                sources.append(source)
                if len(photos) == 3:
                    break
            except (OSError, ValueError, RequestException) as error:
                failures.append({"url": url, "reason": str(error)[:180]})
        if len(photos) != 3:
            raise ValueError("fewer than three usable and distinct gallery photos")
        name = row.get("editorial", {}).get("display_name", row["name"])
        png = composer["encode_png"](composer["draw_cover"](name, photos, logo.copy()))
        path = directory / f"{hotel_id}.png"
        path.write_bytes(png)
        result.update(
            status="preview_ready",
            cover=f"covers/{path.name}",
            png_sha256=hashlib.sha256(png).hexdigest(),
            bytes=len(png),
        )
    except (OSError, ValueError) as error:
        result.update(status="cover_blocked", reason=str(error)[:180])
    result.update(photo_sources=sources, photo_failures=failures)
    return result


def make_sheets(results, output, composer):
    prepared = [row for row in results if row["status"] == "preview_ready"]
    for offset in range(0, len(prepared), 12):
        group = prepared[offset:offset + 12]
        sheet = Image.new("RGB", (1440, 386 * ((len(group) + 3) // 4)), "white")
        draw = ImageDraw.Draw(sheet)
        for index, row in enumerate(group):
            x, y = 360 * (index % 4), 386 * (index // 4)
            draw.text((x + 8, y + 4), f"TV {row['native_tourvisor_id']}", font=composer["font"](16), fill="black")
            with Image.open(output / row["cover"]) as image:
                sheet.paste(image.convert("RGB").resize((360, 360), Image.Resampling.LANCZOS), (x, y + 26))
        sheet.save(output / "review" / f"covers-{offset // 12 + 1:02d}.jpg", quality=92)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("manifest", type=Path)
    parser.add_argument("logo", type=Path)
    parser.add_argument("output", type=Path)
    args = parser.parse_args()
    manifest_bytes = args.manifest.read_bytes()
    manifest = json.loads(manifest_bytes)
    rows = manifest["rows"]
    ids = [row["native_tourvisor_id"] for row in rows]
    if (manifest["schema_version"] != 1 or manifest["stage"] != "catalogue_and_provider_profile_screened"
            or not 1 <= len(rows) <= 100 or len(ids) != len(set(ids))
            or any(type(hotel_id) is not int or hotel_id <= 0 for hotel_id in ids)):
        parser.error("expected a screened intake with up to 100 distinct native hotel IDs")
    if any(row["profile_name_and_country_match"] is not True or row["publication_allowed"] is not False
           or len(row["source_photo_samples"]) > 8 for row in rows):
        parser.error("unexpected hotel profile or gallery input")
    composer = runpy.run_path(str(Path(__file__).with_name("prepare-wave2-native-tourvisor-covers-20261117.py")))
    logo_bytes = args.logo.read_bytes()
    if hashlib.sha256(logo_bytes).hexdigest() != composer["LOGO_SHA256"]:
        parser.error("the accepted AnyTour logo is required")
    with Image.open(io.BytesIO(logo_bytes)) as image:
        logo = image.convert("RGBA")
    args.output.mkdir(parents=True, exist_ok=False)
    covers = args.output / "covers"
    covers.mkdir()
    (args.output / "review").mkdir()
    with ThreadPoolExecutor(max_workers=6) as pool:
        results = list(pool.map(lambda row: render(row, logo, composer, covers), rows))
    make_sheets(results, args.output, composer)
    counts = {status: sum(row["status"] == status for row in results)
              for status in sorted({row["status"] for row in results})}
    summary = {"input_sha256": hashlib.sha256(manifest_bytes).hexdigest(), "counts": counts, "rows": results}
    (args.output / "review" / "receipt.json").write_text(
        json.dumps(summary, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    print(json.dumps(counts, ensure_ascii=False))
    if any(row["stage"] == "editorial_and_visual_review" for row in rows) and not counts.get("preview_ready"):
        raise SystemExit("No eligible gallery rendered. See review/receipt.json")


if __name__ == "__main__":
    main()
