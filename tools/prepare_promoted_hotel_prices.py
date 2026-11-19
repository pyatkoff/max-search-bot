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
TARGETS = {
    174: ("https://max.ru/anytour/AaHzN34rD6Q", "https://t.me/anytour/1792"),
    12107: ("https://max.ru/anytour/AZwjaZt4Kz8", "https://t.me/anytour/809"),
    132: ("https://max.ru/anytour/AZ5qgiujRlo", "https://t.me/anytour/17"),
    469: ("https://max.ru/anytour/AZ6sX9HGLTk", "https://t.me/anytour/19"),
    12111: ("https://max.ru/anytour/AZ6wa60cTlw", "https://t.me/anytour/23"),
    70048: ("https://max.ru/anytour/AZ61r6_QcAI", "https://t.me/anytour/37"),
    82606: ("https://max.ru/anytour/AZ7KT8ynMm8", "https://t.me/anytour/48"),
    1344: ("https://max.ru/anytour/AZ7PcvbibFA", "https://t.me/anytour/82"),
    260: ("https://max.ru/anytour/AZ7UVbXteP8", "https://t.me/anytour/192"),
    415: ("https://max.ru/anytour/AaFUVxlGPTg", "https://t.me/anytour/1754"),
    2493: ("https://max.ru/anytour/AZ7Z4K9JJNQ", "https://t.me/anytour/196"),
    3452: ("https://max.ru/anytour/AZ7pU2e2Znw", "https://t.me/anytour/1148"),
    4181: ("https://max.ru/anytour/AZ8DSRZpHNw", "https://t.me/anytour/204"),
    37668: ("https://max.ru/anytour/AZ8r4R9vT_Q", "https://t.me/anytour/402"),
    43676: ("https://max.ru/anytour/AZ9k6MvNF94", "https://t.me/anytour/414"),
    64895: ("https://max.ru/anytour/AZ-OUsaPJjg", "https://t.me/anytour/993"),
    73974: ("https://max.ru/anytour/AZ-tLRx7Jzw", "https://t.me/anytour/455"),
    74503: ("https://max.ru/anytour/AZ_MDV0gO_I", "https://t.me/anytour/474"),
    99713: ("https://max.ru/anytour/AZ_RB3FVXFc", "https://t.me/anytour/483"),
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


def prepare(xml: bytes, now: datetime) -> dict:
    if now.tzinfo is None:
        raise ValueError("timezone_required")
    now = now.astimezone(MOSCOW)
    if len(xml) > MAX_XML_BYTES or re.search(rb"<!\s*(?:DOCTYPE|ENTITY)\b", xml, re.IGNORECASE):
        raise ValueError("unsafe_xml")
    root = ET.fromstring(xml.decode("utf-8-sig"))
    containers = root.findall("shop/offers")
    if root.tag != "yml_catalog" or len(containers) != 1:
        raise ValueError("unexpected_feed_structure")
    want = {f"{platform}_{hotel}" for hotel in TARGETS for platform in ("max", "tg")}
    offers: dict[str, list[ET.Element]] = {}
    for item in containers[0].findall("offer"):
        key = item.get("id")
        if key in want:
            offers.setdefault(key, []).append(item)

    plans = []
    held = []
    for hotel, (max_url, telegram_url) in TARGETS.items():
        try:
            max_items = offers.get(f"max_{hotel}", [])
            tg_items = offers.get(f"tg_{hotel}", [])
            if len(max_items) != 1 or len(tg_items) != 1:
                raise ValueError("missing_or_duplicate_feed_offer")
            maximum = parse_offer(max_items[0], now)
            telegram = parse_offer(tg_items[0], now)
            if maximum != telegram:
                raise ValueError("platform_offer_mismatch")
            plans.append({
                "hotel_id": hotel, "max_url": max_url, "telegram_url": telegram_url,
                "price_block": maximum.block,
                "observed_at": maximum.observed.isoformat(),
                "departure_date": maximum.departure.date().isoformat(),
            })
        except ValueError as failure:
            held.append({"hotel_id": hotel, "reason": str(failure)})
    return {
        "checked_at": now.isoformat(), "feed_sha256": hashlib.sha256(xml).hexdigest(),
        "target_hotels": len(TARGETS), "eligible_hotels": len(plans), "plans": plans, "held": held,
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
