import unittest
from datetime import datetime
import hashlib
import json
from pathlib import Path
import tempfile
import xml.etree.ElementTree as ET

from prepare_promoted_hotel_prices import MOSCOW, TARGET_FILE, prepare, read_targets, revise_caption


NOW = datetime(2026, 11, 19, 21, 30, tzinfo=MOSCOW)
BLOCK = (
    "💰 От 102 233 ₽ за двоих\n"
    "✈️ Вылет из Москвы 16.01.2027 · 5 ночей\n"
    "👥 2 взрослых\n"
    "🛏 standard room\n"
    "🍽 Всё включено\n"
    "Цена найдена мониторингом 19.11.2026, 04:01 МСК. Стоимость и наличие могут измениться."
)


def snapshot(*rows):
    root = ET.Element("yml_catalog")
    offers = ET.SubElement(ET.SubElement(root, "shop"), "offers")
    for platform, text, amount in rows:
        offer = ET.SubElement(offers, "offer", id=f"{platform}_415")
        ET.SubElement(offer, "price").text = amount
        ET.SubElement(offer, "currencyId").text = "RUB"
        ET.SubElement(offer, "description").text = text
        ET.SubElement(offer, "url").text = "https://example.test/ignored"
    return ET.tostring(root, encoding="utf-8")


class PromotedPricesTest(unittest.TestCase):
    def pair(self, max_block=BLOCK, tg_block=BLOCK, amount="102233"):
        return prepare(snapshot(("max", max_block, amount), ("tg", tg_block, amount)), NOW)

    def seagull_hold(self, result):
        return next(row["reason"] for row in result["held"] if row["hotel_id"] == 415)

    def test_exact_destination_and_price_fields(self):
        result = self.pair(f"MAX description\n\n{BLOCK}", f"Telegram description\n\n{BLOCK}")
        self.assertEqual(result["target_hotels"], len(read_targets(TARGET_FILE.read_bytes())))
        self.assertEqual(result["targets_sha256"], hashlib.sha256(TARGET_FILE.read_bytes()).hexdigest())
        self.assertEqual(result["eligible_hotels"], 1)
        self.assertEqual(result["plans"][0], {
            "hotel_id": 415, "max_url": "https://max.ru/anytour/AaFUVxlGPTg", "max_publication_id": 23,
            "telegram_url": "https://t.me/anytour/1754", "telegram_publication_id": 20, "price_block": BLOCK,
            "observed_at": "2026-11-19T04:01:00+03:00", "departure_date": "2027-01-16",
        })

    def test_original_19_pairs_match_the_approved_destinations(self):
        legacy = {174, 12107, 132, 469, 12111, 70048, 82606, 1344, 260, 415, 2493, 3452,
                  4181, 37668, 43676, 64895, 73974, 74503, 99713}
        targets = read_targets(TARGET_FILE.read_bytes())
        lines = [
            f"{hotel} {platform} {targets[hotel][platform]['publication_id']} {targets[hotel][platform]['url']}"
            for hotel in sorted(legacy) for platform in ("max", "telegram")
        ]
        self.assertEqual(hashlib.sha256("\n".join(lines).encode()).hexdigest(),
                         "9e1324a0f67357fe1d937667b926f5629285b8f33233efb32b30fd5dbe140975")

    def test_single_platform_target_still_requires_both_price_sources(self):
        accepted = read_targets(TARGET_FILE.read_bytes())[415]
        with tempfile.TemporaryDirectory() as tmp:
            file = Path(tmp) / "scope.json"
            for platform, only in accepted.items():
                with self.subTest(platform=platform):
                    file.write_text(json.dumps([only]), encoding="utf-8")
                    pair = snapshot(("max", BLOCK, "102233"), ("tg", BLOCK, "102233"))
                    row = prepare(pair, NOW, target_file=file)["plans"][0]
                    self.assertEqual({k for k in row if k.endswith("_url")}, {platform + "_url"})
                    self.assertEqual(row[platform + "_publication_id"], only["publication_id"])
                    incomplete = prepare(snapshot(("max", BLOCK, "102233")), NOW, target_file=file)
                    self.assertEqual(self.seagull_hold(incomplete), "missing_or_duplicate_feed_offer")

    def test_target_duplicates_and_wrong_channel_are_rejected(self):
        accepted = read_targets(TARGET_FILE.read_bytes())
        source = accepted[415]["telegram"]
        other = accepted[174]["telegram"]
        for changed in (dict(other, publication_id=source["publication_id"]), dict(other, url=source["url"]),
                        dict(other, hotel_id=415), dict(other, platform="max"), dict(other, publication_id=True)):
            with self.subTest(changed=changed), self.assertRaises(ValueError):
                read_targets(json.dumps([source, changed]).encode())

    def test_age_cutoff_is_strict_and_future_observations_are_held(self):
        for stamp in ("18.11.2026, 09:30", "19.11.2026, 21:31"):
            block = BLOCK.replace("19.11.2026, 04:01", stamp)
            self.assertEqual(self.seagull_hold(self.pair(block, block)), "observation_outside_36h")
        fresh = BLOCK.replace("19.11.2026, 04:01", "18.11.2026, 09:31")
        self.assertEqual(self.pair(fresh, fresh)["eligible_hotels"], 1)

    def test_departure_and_cross_platform_disagreement(self):
        past = BLOCK.replace("16.01.2027", "18.11.2026")
        self.assertEqual(self.seagull_hold(self.pair(past, past)), "past_departure")
        different = BLOCK.replace("standard room", "family room")
        self.assertEqual(self.seagull_hold(self.pair(tg_block=different)), "platform_offer_mismatch")
        self.assertEqual(self.seagull_hold(self.pair(amount="102234")), "amount_mismatch")

    def test_duplicate_and_entity_inputs_fail_closed(self):
        row = ("max", BLOCK, "102233")
        repeated = prepare(snapshot(row, row, ("tg", BLOCK, "102233")), NOW)
        self.assertEqual(self.seagull_hold(repeated), "missing_or_duplicate_feed_offer")
        with self.assertRaisesRegex(ValueError, "unsafe_xml"):
            prepare(b'<!DOCTYPE x [<!ENTITY n "x">]><x/>', NOW)

    def test_caption_update_preserves_copy_and_replaces_its_previous_block(self):
        original = "✈️ Seagull\n\n📍 Хургада\nОписание и ссылка https://t.me/anytour"
        first = revise_caption(original, BLOCK, telegram=True)
        self.assertEqual(first, original + "\n\n" + BLOCK)
        second_block = BLOCK.replace("102 233", "102 000")
        self.assertEqual(revise_caption(first, second_block, telegram=True), original + "\n\n" + second_block)

    def test_caption_rejects_unsupported_existing_price_and_telegram_overflow(self):
        for caption in (BLOCK + "\nother", "✈️ Seagull\n\nОт 120 000 ₽"):
            with self.assertRaisesRegex(ValueError, "ambiguous_existing_price"):
                revise_caption(caption, BLOCK, telegram=True)
        with self.assertRaisesRegex(ValueError, "telegram_caption_too_long"):
            revise_caption("😀" * 510, BLOCK, telegram=True)


if __name__ == "__main__":
    unittest.main()
