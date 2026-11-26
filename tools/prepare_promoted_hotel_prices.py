#!/usr/bin/env python3
"""Prepare exact, paired PRICE blocks for the promoted #1566 publications.

This module reads a single public combined XML snapshot. The native editor owns
provider identities, locks, writes and readback. This module has no write path.
"""
from __future__ import annotations

import argparse
from dataclasses import dataclass
from datetime import datetime, timedelta
from decimal import Decimal
import hashlib
import json
from pathlib import Path
import re
import sys
import xml.etree.ElementTree as ET
from zoneinfo import ZoneInfo


MOSCOW = ZoneInfo("Europe/Moscow")
MAX_XML_BYTES = 4_000_000
TARGET_FILE = Path(__file__).resolve().parents[1] / "config" / "promoted_hotel_price_targets.json"
POST_URLS = {
    "max": re.compile(r"https://max[.]ru/anytour/[A-Za-z0-9_-]{1,100}"),
    "telegram": re.compile(r"https://t[.]me/anytour/[1-9][0-9]*"),
}

MONEY = r"[1-9][0-9 \u00a0\u202f]*(?:,[0-9]{1,2})?"
STAMP = r"[0-9]{2}\.[0-9]{2}\.[0-9]{4}, [0-9]{2}:[0-9]{2}"
BLOCK = re.compile(
    r"^💰 От (?P<amount>" + MONEY + r") ₽ за двоих\n"
    r"✈️ Вылет из (?P<city>[^\n]+?) (?P<departure>[0-9]{2}\.[0-9]{2}\.[0-9]{4})"
    r" · (?P<nights>[1-9][0-9]* ноч(?:ь|и|ей))\n"
    r"👥 (?P<party>2 взрослых)\n"
    r"🛏 (?P<room>[^\n]+)\n"
    r"🍽 (?P<meal>[^\n]+)\n"
    r"Цена найдена мониторингом (?P<observed>" + STAMP + r") МСК\."
    r" Стоимость и наличие могут измениться\.$",
    re.MULTILINE,
)
PRICE_HINT = re.compile(r"Цена найдена мониторингом|^\s*💰|[0-9][ \u00a0\u202f]*₽", re.MULTILINE)


@dataclass(frozen=True)
class Offer:
    block: str
    observed: datetime
    departure: datetime


def parse_offer(offer: ET.Element, now: datetime) -> Offer:
    def field(name: str) -> str:
        elements = offer.findall(name)
        if len(elements) != 1 or elements[0].text is None or list(elements[0]):
            raise ValueError("invalid_" + name)
        return elements[0].text

    description = field("description")
    matches = list(BLOCK.finditer(description))
    if len(matches) != 1:
        raise ValueError("missing_or_ambiguous_price_block")
    match = matches[0]
    xml_price = field("price")
    if not re.fullmatch(r"[1-9][0-9]*(?:\.[0-9]{1,2})?", xml_price) or field("currencyId") != "RUB":
        raise ValueError("invalid_feed_amount")
    caption_price = re.sub(r"[ \u00a0\u202f]", "", match["amount"]).replace(",", ".")
    if Decimal(xml_price) != Decimal(caption_price):
        raise ValueError("amount_mismatch")
    observed = datetime.strptime(match["observed"], "%d.%m.%Y, %H:%M").replace(tzinfo=MOSCOW)
    departure = datetime.strptime(match["departure"], "%d.%m.%Y").replace(tzinfo=MOSCOW)
    if not timedelta(0) <= now - observed < timedelta(hours=36):
        raise ValueError("observation_outside_36h")
    if departure.date() < now.date():
        raise ValueError("past_departure")
    return Offer(match[0], observed, departure)


def read_targets(raw: bytes) -> dict:
    rows = json.loads(raw)
    if not isinstance(rows, list) or not rows:
        raise ValueError("invalid_promoted_targets")
    hotels: dict[int, dict] = {}
    publications: set[int] = set()
    urls: set[str] = set()
    for row in rows:
        if not isinstance(row, dict) or set(row) != {"hotel_id", "platform", "publication_id", "url"}:
            raise ValueError("invalid_promoted_target")
        hotel, platform = row["hotel_id"], row["platform"]
        publication, url = row["publication_id"], row["url"]
        if (type(hotel) is not int or hotel <= 0 or type(publication) is not int or publication <= 0
                or not isinstance(platform, str) or platform not in POST_URLS
                or not isinstance(url, str) or not POST_URLS[platform].fullmatch(url)):
            raise ValueError("invalid_promoted_target")
        targets = hotels.setdefault(hotel, {})
        if platform in targets or publication in publications or url in urls:
            raise ValueError("duplicate_promoted_target")
        targets[platform] = row
        publications.add(publication)
        urls.add(url)
    return hotels


def prepare(xml: bytes, now: datetime, *, target_file: Path = TARGET_FILE) -> dict:
    if now.tzinfo is None:
        raise ValueError("timezone_required")
    now = now.astimezone(MOSCOW)
    if len(xml) > MAX_XML_BYTES or re.search(rb"<!\s*(?:DOCTYPE|ENTITY)\b", xml, re.IGNORECASE):
        raise ValueError("unsafe_xml")
    root = ET.fromstring(xml.decode("utf-8-sig"))
    containers = root.findall("shop/offers")
    if root.tag != "yml_catalog" or len(containers) != 1:
        raise ValueError("unexpected_feed_structure")
    target_bytes = target_file.read_bytes()
    targets = read_targets(target_bytes)
    want = {f"{platform}_{hotel}" for hotel in targets for platform in ("max", "tg")}
    offers: dict[str, list[ET.Element]] = {}
    for item in containers[0].findall("offer"):
        key = item.get("id")
        if key in want:
            offers.setdefault(key, []).append(item)

    plans = []
    held = []
    for hotel, platforms in targets.items():
        try:
            max_items = offers.get(f"max_{hotel}", [])
            tg_items = offers.get(f"tg_{hotel}", [])
            if len(max_items) != 1 or len(tg_items) != 1:
                raise ValueError("missing_or_duplicate_feed_offer")
            maximum = parse_offer(max_items[0], now)
            telegram = parse_offer(tg_items[0], now)
            if maximum != telegram:
                raise ValueError("platform_offer_mismatch")
            plan = {
                "hotel_id": hotel,
                "price_block": maximum.block,
                "observed_at": maximum.observed.isoformat(),
                "departure_date": maximum.departure.date().isoformat(),
            }
            for platform, binding in platforms.items():
                plan[platform + "_url"] = binding["url"]
                plan[platform + "_publication_id"] = binding["publication_id"]
            plans.append(plan)
        except ValueError as failure:
            held.append({"hotel_id": hotel, "reason": str(failure)})
    return {
        "checked_at": now.isoformat(), "feed_sha256": hashlib.sha256(xml).hexdigest(),
        "targets_sha256": hashlib.sha256(target_bytes).hexdigest(),
        "target_hotels": len(targets), "eligible_hotels": len(plans), "plans": plans, "held": held,
    }


def revise_caption(caption: str, block: str, *, telegram: bool) -> str:
    """Append on first write and replace only our terminal block on later writes."""
    if not caption or not BLOCK.fullmatch(block):
        raise ValueError("invalid_caption_or_price_block")
    existing = list(BLOCK.finditer(caption))
    if existing:
        matches_terminal = len(existing) == 1 and existing[0].end() == len(caption)
        if not matches_terminal or not caption[:existing[0].start()].endswith("\n\n"):
            raise ValueError("ambiguous_existing_price")
        prefix = caption[:existing[0].start()]
    else:
        prefix = caption + "\n\n"
    if PRICE_HINT.search(prefix):
        raise ValueError("ambiguous_existing_price")
    result = prefix + block
    if telegram and len(result.encode("utf-16-le")) // 2 > 1024:
        raise ValueError("telegram_caption_too_long")
    return result


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("xml", help="Combined feed snapshot, or - for stdin")
    args = parser.parse_args()
    if args.xml == "-":
        xml = sys.stdin.buffer.read(MAX_XML_BYTES + 1)
    else:
        with Path(args.xml).open("rb") as stream:
            xml = stream.read(MAX_XML_BYTES + 1)
    print(json.dumps(prepare(xml, datetime.now(MOSCOW)), ensure_ascii=False))


if __name__ == "__main__":
    main()
