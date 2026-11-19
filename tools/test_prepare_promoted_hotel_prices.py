import unittest
from datetime import datetime
import xml.etree.ElementTree as ET

from prepare_promoted_hotel_prices import MOSCOW, prepare, revise_caption


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
        self.assertEqual(result["target_hotels"], 19)
        self.assertEqual(result["eligible_hotels"], 1)
        self.assertEqual(result["plans"][0], {
            "hotel_id": 415, "max_url": "https://max.ru/anytour/AaFUVxlGPTg",
            "telegram_url": "https://t.me/anytour/1754", "price_block": BLOCK,
            "observed_at": "2026-11-19T04:01:00+03:00", "departure_date": "2027-01-16",
        })

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
