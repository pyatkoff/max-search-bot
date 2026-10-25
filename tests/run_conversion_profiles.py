#!/usr/bin/env python3
"""Offline contract/meaning tests; synthetic examples are not held-out accuracy."""
import copy
import json
from pathlib import Path
import subprocess
import sys
import unittest

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "tools"))
import extract_conversion_profiles as tool

START = "2026-10-01T09:00:00Z"
CUTOFF = "2026-10-01T12:00:00Z"
LATER = "2026-10-02T12:00:00Z"
SCOPE = {"project_key": "anytour", "platform": "telegram", "customer_key": "person:7",
         "conversation_id": "conversation:9", "episode_key": "trip:2"}


def message(text, speaker="customer", i=1, at=None, available=None, kind="text"):
    at = at or f"2026-10-01T10:{i:02d}:00Z"
    return {"id": f"message:{i}", "speaker": speaker, "kind": kind, "at": at,
            "available_at": available or at, "text": text}


def case(*messages):
    return {"subject": dict(SCOPE), "brand_key": "anytour", "context_from": START,
            "prediction_at": CUTOFF, "source_complete_through": CUTOFF,
            "prefix_complete": True, "messages": list(messages)}


def extract(*messages):
    return tool.extract_case(case(*messages), CUTOFF)


def value(profile, field):
    return profile["fields"][field]["value"]


class ProfileTests(unittest.TestCase):
    def test_total_budget_keeps_agency_default_basis(self):
        p = extract(message("Бюджет до 240 тыс. рублей, на 8 ночей"))
        b = value(p, "budget")
        self.assertEqual((b["minimum"], b["maximum"], b["currency"], b["unit"]), (None, 240000, "RUB", "party_total"))
        self.assertEqual(b["unit_basis"], "agency_default_party_total")

    def test_per_person_budget_is_not_collapsed_or_multiplied(self):
        p = extract(message("120–150 тыс. ₽ на человека, нас двое"))
        b = value(p, "budget")
        self.assertEqual((b["minimum"], b["maximum"], b["unit"]), (120000, 150000, "per_person"))
        self.assertIsNone(p["expected_value_rub"])

    def test_foreign_currency_stays_unconverted(self):
        b = value(extract(message("Общий бюджет 2500 EUR на всех")), "budget")
        self.assertEqual((b["maximum"], b["currency"], b["unit_basis"]), (2500, "EUR", "explicit"))

    def test_bare_budget_number_does_not_mean_thousands(self):
        b = value(extract(message("Бюджет 150 рублей")), "budget")
        self.assertEqual(b["maximum"], 150)

    def test_budget_does_not_consume_party_nights_dates(self):
        p = extract(message("Бюджет 200 тыс, 2 взрослых, на 7 ночей с 10.11"))
        self.assertEqual(value(p, "budget")["maximum"], 200000)
        self.assertEqual(p["party"]["adults"]["value"], 2)

    def test_economy_is_not_a_measured_low_budget(self):
        p = extract(message("Хотим бюджетные варианты, желательно не дорого"))
        self.assertIsNone(value(p, "budget"))
        self.assertEqual(value(p, "budget_preference"), "economy_preference")

    def test_manager_quote_never_becomes_customer_budget(self):
        p = extract(message("Тур на двоих"), message("Итоговая цена 283\u00a0705 RUB", "manager", 2))
        self.assertIsNone(value(p, "budget"))
        self.assertEqual(len(p["manager_quotes"]), 1)
        self.assertEqual(p["manager_quotes"][0]["value"]["maximum"], 283705)

    def test_customer_repeating_price_is_not_budget(self):
        for text in ["Вы прислали цену 300 тыс", "Я купил тур за 300 тыс", "Стоимость 300 тыс?"]:
            with self.subTest(text=text):
                self.assertIsNone(value(extract(message(text)), "budget"))

    def test_manager_quote_survives_adjacent_url_and_itinerary(self):
        p = extract(message("Вылет 08.11, 5+1н, Beach Hotel 4*, AI, 294\u00a0300 RUB. Подробнее: https://example.org/tour/1", "manager"))
        self.assertEqual(len(p["manager_quotes"]), 1)
        self.assertEqual(p["manager_quotes"][0]["value"]["maximum"], 294300)

    def test_later_budget_correction_has_new_source(self):
        p = extract(message("Бюджет 150 тыс"), message("Теперь бюджет 220 тыс на всех", i=2))
        self.assertEqual(value(p, "budget")["maximum"], 220000)
        self.assertEqual(p["fields"]["budget"]["references"][0]["id"], "message:2")

    def test_simultaneous_incompatible_budgets_are_ambiguous(self):
        p = extract(message("Бюджет 150 тыс либо 250 тыс"))
        self.assertEqual(p["fields"]["budget"]["status"], "ambiguous")
        self.assertIsNone(value(p, "budget"))

    def test_explicit_party_and_child_ages(self):
        p = extract(message("4 взрослых, 3 детей (6, 13, 15)"))
        self.assertEqual({k: p["party"][k]["value"] for k in p["party"]},
                         {"adults": 4, "children": 3, "total": 7, "child_ages": [6, 13, 15]})

    def test_age_reply_keeps_source_of_single_child_context(self):
        p = extract(message("2 взрослых и 1 ребенок"), message("Сколько лет ребенку?", "manager", 2), message("3 года, на 5 ночей", i=3))
        self.assertEqual(p["party"]["child_ages"]["value"], [3])
        self.assertEqual(len(p["party"]["child_ages"]["references"]), 2)

    def test_two_people_and_working_adult_assumption_are_separate(self):
        p = extract(message("В Турцию на двоих"))
        self.assertEqual(p["party"]["total"]["value"], 2)
        self.assertIsNone(p["party"]["adults"]["value"])
        self.assertEqual(p["working_assumptions"][0]["value"], {"adults": 2, "children": 0})

    def test_party_conflict_and_age_mismatch_remain_explicit(self):
        p = extract(message("2 взрослых 2 детей (5), всего 7 человек"))
        self.assertEqual(p["party"]["total"]["status"], "ambiguous")
        self.assertEqual(p["party"]["child_ages"]["status"], "ambiguous")

    def test_negated_party_number_is_not_kept(self):
        p = extract(message("Не 3 взрослых, а 4 взрослых, без детей"))
        self.assertEqual(p["party"]["total"]["value"], 4)

    def test_days_and_nights_are_separate_observations(self):
        for text, expected in [("На 7 дней", "days"), ("Дней на 5", "days"), ("6-7 ночей", "nights")]:
            with self.subTest(text=text):
                self.assertEqual(value(extract(message(text)), "duration")["unit"], expected)

    def test_explicit_breakfast_overrides_working_destination_defaults(self):
        p = extract(message("Египет, с завтраком"))
        self.assertEqual(value(p, "meal"), "breakfast")
        self.assertEqual(p["fields"]["meal"]["basis"], "explicit_customer")

    def test_explicit_half_board_is_not_reduced_to_breakfast(self):
        self.assertEqual(value(extract(message("Завтрак и ужин")), "meal"), "half_board")

    def test_unrestricted_meal_and_room_do_not_create_constraints(self):
        p = extract(message("Питание без разницы. Разместиться без разницы, мы семья"))
        self.assertEqual(value(p, "meal"), "unrestricted")
        self.assertEqual(value(p, "room_arrangement"), "unrestricted")
        self.assertTrue(value(p, "family_group"))

    def test_date_window_flexibility_and_missing_year(self):
        p = extract(message("С 10 по 20 февраля, диапазон +-5 дней можно, 6-7 ночей"))
        self.assertEqual((value(p, "dates")["start_day"], value(p, "dates")["end_day"], value(p, "dates")["year"]), (10, 20, None))
        self.assertEqual(value(p, "date_flexibility_days"), 5)
        self.assertEqual(value(p, "dates")["window_kind"], "unspecified")

    def test_numeric_date_range_does_not_become_duration(self):
        p = extract(message("С 3.11. по 7.11 в Турцию"))
        self.assertEqual(value(p, "dates")["end_day"], 7)
        self.assertIsNone(value(p, "duration"))

    def test_invalid_date_is_not_asserted(self):
        self.assertIsNone(value(extract(message("Вылет 31.02.2027")), "dates"))

    def test_city_role_is_not_guessed(self):
        p = extract(message("Тур с 5 марта по 10 марта Санкт-Петербург"))
        self.assertIsNone(value(p, "departure"))
        self.assertEqual(value(p, "unresolved_city"), "Санкт-Петербург")
        self.assertEqual(value(p, "dates")["end_day"], 10)

    def test_explicit_departure_and_destination_variants(self):
        p = extract(message("Из Самары в Шри -Ланку или Тайланд"))
        self.assertEqual(value(p, "departure"), "Самара")
        self.assertEqual(set(value(p, "destination")["values"]), {"Шри-Ланка", "Таиланд"})

    def test_yes_preceding_new_quote_only_confirms_hotel_interest(self):
        p = extract(message("2 взрослых и ребенок"), message("Рассматриваете Coral Bay из публикации?", "manager", 2),
                    message("Да", i=3), message("Тогда предлагаю другую дату 10.11, цена 400 тыс RUB", "manager", 4))
        self.assertEqual(value(p, "hotel_interest"), "Coral Bay")
        self.assertEqual(p["current_stage"]["value"], "clarifying")
        self.assertIsNone(value(p, "budget"))
        self.assertFalse(p["observation"]["eligible"] is False)

    def test_bare_yes_interest_and_link_never_mean_selection(self):
        p = extract(message("Интересен тур на 7 ночей"), message("Варианты https://example.org", "manager", 2), message("Да", i=3))
        self.assertEqual(p["current_stage"]["value"], "request")
        self.assertTrue(p["observation"]["eligible"])

    def test_explicit_stage_phrases(self):
        for text, stage in [("Сравниваем эти отели", "comparing"), ("Берём второй вариант", "offer_selected"),
                            ("Давайте бронировать", "booking_intent"), ("Забронировали тур у вас", "booking_confirmed"),
                            ("Мы оплатили тур у вас", "paid"), ("Купили в другом агентстве", "purchased_elsewhere"),
                            ("Отпуск отменили", "declined")]:
            with self.subTest(text=text):
                self.assertEqual(extract(message(text))["current_stage"]["value"], stage)

    def test_questions_conditionals_negations_and_option_refusal_do_not_raise_stage(self):
        for text in ["Если подойдет, готовы бронировать", "Мы не готовы бронировать", "Не давайте бронировать",
                     "Мы не оплатили тур у вас", "Как бронировать?", "Этот отель не подходит"]:
            with self.subTest(text=text):
                self.assertNotIn(extract(message(text))["current_stage"]["value"], tool.base.PROGRESS | tool.base.NEGATIVE)

    def test_purchase_without_seller_stays_unresolved(self):
        p = extract(message("Мы уже купили"))
        self.assertEqual(value(p, "purchase_report"), "seller_unresolved")
        self.assertIsNone(p["current_stage"]["value"])

    def test_manager_purchase_claim_does_not_become_customer_action(self):
        p = extract(message("Хочу тур"), message("Оплатили тур у вас. Давайте бронировать", "manager", 2))
        self.assertEqual(p["current_stage"]["value"], "request")

    def test_later_customer_answers_are_clarification(self):
        p = extract(message("В Турцию"), message("Какой бюджет и вылет?", "manager", 2), message("Из Казани, бюджет 180 тыс", i=3))
        self.assertEqual(p["current_stage"]["value"], "clarifying")

    def test_old_trip_future_and_late_available_messages_do_not_leak(self):
        p = extract(message("Бюджет 100 тыс"), message("Бюджет 400 тыс", i=2, at="2026-09-30T10:00:00Z"),
                    message("Бюджет 500 тыс", i=3, at=LATER), message("Бюджет 600 тыс", i=4, available=LATER))
        self.assertEqual(value(p, "budget")["maximum"], 100000)
        self.assertEqual(len(p["customer_requirements"]), 1)

    def test_actions_system_and_unknown_speakers_are_not_customer_text(self):
        p = extract(message("Оплатили тур у вас", kind="action"), message("Бюджет 100 тыс", speaker="other", i=2))
        self.assertEqual(p["customer_requirements"], [])
        self.assertIsNone(value(p, "budget"))

    def test_semantic_availability_is_actual_extraction_time(self):
        p = tool.extract_case(case(message("Готовы бронировать")), LATER)
        self.assertEqual(p["current_stage"]["available_at"], LATER)
        self.assertFalse(p["current_stage"]["usable_at_prediction"])
        self.assertEqual(p["observation"]["features"]["current_stage"], "unknown")

    def test_incomplete_frozen_source_is_not_current_complete_prediction(self):
        c = case(message("Тур в Египет")); c["source_complete_through"] = START
        with self.assertRaisesRegex(tool.base.InvalidObservation, "incomplete_prediction_coverage"):
            tool.extract_case(c, CUTOFF)
        c["prefix_complete"] = False
        self.assertEqual(tool.extract_case(c, CUTOFF)["observation"]["label"]["reason"], "incomplete_prefix")

    def test_same_time_incompatible_stages_stay_ambiguous(self):
        p = extract(message("Давайте бронировать"), message("Отпуск отменили", i=2, at="2026-10-01T10:01:00Z"))
        self.assertEqual(p["current_stage"]["status"], "ambiguous")
        self.assertEqual(p["observation"]["features"]["current_stage"], "unknown")

    def test_source_spans_hashes_and_source_roles_are_verifiable(self):
        m = message("Бюджет 140 тыс на человека")
        p = extract(m); ref = p["fields"]["budget"]["references"][0]
        self.assertEqual(ref["speaker"], "customer")
        self.assertIn("140", m["text"][slice(*ref["span"])])
        self.assertEqual(ref["text_sha256"], tool.hashlib.sha256(m["text"].encode()).hexdigest())

    def test_id_renaming_and_input_order_do_not_supply_answers(self):
        rows = [message("4 взрослых 2 детей (5, 9), с завтраком"), message("Из Сочи, до 300 тыс на всех", i=2)]
        a = extract(*rows); renamed = copy.deepcopy(rows)
        for i, m in enumerate(renamed):
            m["id"] = f"random:{99-i}"
        b = extract(*reversed(renamed))
        for field in a["fields"]:
            self.assertEqual(a["fields"][field]["value"], b["fields"][field]["value"])
        self.assertEqual(a["party"]["total"]["value"], b["party"]["total"]["value"])

    def test_duplicate_message_is_idempotent_and_conflict_rejected(self):
        m = message("Бюджет 100 тыс")
        self.assertEqual(extract(m), extract(m, copy.deepcopy(m)))
        bad = {**m, "text": "PRIVATE SENTINEL"}
        with self.assertRaisesRegex(tool.base.InvalidObservation, "conflicting_message_id"):
            extract(m, bad)

    def test_no_probabilities_or_export_and_brand_context_preserved(self):
        c = case(message("Бюджет 500 тыс, давайте бронировать")); c["brand_key"] = "pegas"
        p = tool.extract_case(c, CUTOFF)
        self.assertEqual(p["brand_key"], "pegas")
        for field in ["purchase_anywhere_probability", "purchase_with_us_probability", "expected_commission_rub", "expected_value_rub"]:
            self.assertIsNone(p[field])
        self.assertFalse(p["export_allowed"])
        self.assertIsNone(p["observation"]["label"]["value"])

    def test_cli_atomicity_unknown_fields_and_private_error_redaction(self):
        good = case(message("Бюджет 100 тыс")); bad = copy.deepcopy(good); bad["private"] = "PRIVATE SENTINEL"
        document = {"schema_version": 1, "extracted_at": CUTOFF, "cases": [good, bad]}
        result = subprocess.run([sys.executable, str(ROOT/"tools/extract_conversion_profiles.py")], input=json.dumps(document), text=True, capture_output=True)
        self.assertEqual(result.returncode, 2)
        self.assertEqual(result.stdout, "")
        self.assertEqual(json.loads(result.stderr), {"ok": False, "error": "invalid_fields"})

    def test_cli_duplicate_keys_invalid_utf8_and_size_limit(self):
        for raw in [b'{"schema_version":1,"schema_version":1}', b'\xff', b' '*(tool.MAX_BYTES+1)]:
            result = subprocess.run([sys.executable, str(ROOT/"tools/extract_conversion_profiles.py")], input=raw, capture_output=True)
            self.assertEqual(result.returncode, 2)
            self.assertEqual(result.stdout, b"")

    def test_cli_matches_direct_call(self):
        data = {"schema_version": 1, "extracted_at": CUTOFF, "cases": [case(message("Тур из Москвы на 7 дней, 2 чел"))]}
        result = subprocess.run([sys.executable, str(ROOT/"tools/extract_conversion_profiles.py")], input=json.dumps(data), text=True, capture_output=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(json.loads(result.stdout), tool.extract_document(data))


if __name__ == "__main__":
    unittest.main(verbosity=2)
