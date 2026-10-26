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
    def test_direct_flight_requirement_is_source_bound_customer_fact(self):
        for text in ["Вылет из Казани, прямой рейс", "Нужны прямые рейсы", "Хотим лететь прямым рейсом", "Только без пересадок"]:
            with self.subTest(text=text):
                p = extract(message(text)); row = p["fields"]["flight_requirement"]
                self.assertEqual(row["value"], "direct_only")
                self.assertEqual(row["references"][0]["speaker"], "customer")
                self.assertTrue(row["references"][0]["span"][1] > row["references"][0]["span"][0])
                self.assertEqual(p["prediction_features"]["facts"]["fields.flight_requirement"]["value"], "direct_only")

    def test_flight_question_negation_and_report_do_not_create_requirement(self):
        for text in ["Есть прямой рейс?", "Если прямой рейс, какая цена", "Нам предложили прямой рейс", "Менеджер написал: прямой рейс", "Не хочу прямой рейс", "Прямой рейс не подходит", "В прошлом году летали прямым рейсом"]:
            with self.subTest(text=text):
                self.assertIsNone(value(extract(message(text)), "flight_requirement"))

    def test_customer_can_remove_earlier_direct_flight_requirement(self):
        for text in ["Не нужен прямой рейс", "Прямой рейс не обязателен", "Не принципиально, прямой рейс или нет"]:
            with self.subTest(text=text):
                p=extract(message("Нужен прямой рейс"),message(text,i=2))
                row=p["fields"]["flight_requirement"]
                self.assertEqual(row["value"],"direct_not_required")
                self.assertEqual(row["references"][0]["id"],"message:2")
        p=extract(message("Нужен прямой рейс, питание не обязательно"))
        self.assertEqual(value(p,"flight_requirement"),"direct_only")

    def test_manager_bot_and_action_cannot_supply_flight_requirement(self):
        for options in [{"speaker":"manager"}, {"speaker":"other"}, {"kind":"action"}]:
            with self.subTest(options=options):
                self.assertIsNone(value(extract(message("Прямой рейс", **options)), "flight_requirement"))
        p=extract(message("Только без пересадок"),message("Рейс с пересадкой",speaker="manager",i=2))
        self.assertEqual(value(p,"flight_requirement"),"direct_only")

    def test_late_flight_requirement_is_not_a_historical_feature(self):
        p=tool.extract_case(case(message("Нужен прямой рейс",at=LATER)),CUTOFF)
        self.assertIsNone(value(p,"flight_requirement"))
        p=tool.extract_case(case(message("Нужен прямой рейс")),LATER)
        self.assertEqual(value(p,"flight_requirement"),"direct_only")
        self.assertNotIn("fields.flight_requirement",p["prediction_features"]["facts"])

    def test_explicit_adult_count_with_people_word_keeps_composition(self):
        for text in ["4 человека взрослых", "4 человек взрослых", "4 чел. взрослых"]:
            with self.subTest(text=text):
                p = extract(message(text))
                self.assertEqual(p["party"]["adults"]["value"], 4)
                self.assertEqual(p["party"]["total"]["value"], 4)
                self.assertIsNone(p["party"]["children"]["value"])

    def test_people_word_does_not_override_explicit_components_or_negation(self):
        for text in ["4 человека: 3 взрослых, 1 ребенок", "Не 2 человека взрослых, а 3 взрослых, 1 ребенок"]:
            with self.subTest(text=text):
                p = extract(message(text))
                self.assertEqual(p["party"]["adults"]["value"], 3)
        self.assertIsNone(extract(message("4 человека взрослых", "manager"))["party"]["adults"]["value"])

    def test_duration_options_keep_disjoint_choices_and_whole_source_span(self):
        for text in ["На 6 ,9 ночей", "На 6, 9 ночей", "На 9 или 6 ночей", "На 6, 9 или 12 ночей"]:
            with self.subTest(text=text):
                p = extract(message(text)); d = value(p, "duration")
                self.assertEqual(d["options"], [6, 9, 12] if "12" in text else [6, 9])
                self.assertEqual((d["minimum"], d["maximum"], d["unit"]), (6, 12 if "12" in text else 9, "nights"))
                span = p["fields"]["duration"]["references"][0]["span"]
                self.assertIn("6", text[slice(*span)])
                self.assertIn("9", text[slice(*span)])

    def test_duration_options_preserve_day_unit_and_do_not_change_ranges(self):
        self.assertEqual(value(extract(message("6 или 9 дней")), "duration")["unit"], "days")
        self.assertEqual(value(extract(message("6–9 ночей")), "duration"),
                         {"minimum": 6, "maximum": 9, "unit": "nights"})

    def test_invalid_duration_cannot_become_a_valid_numeric_suffix(self):
        for text in ["6,5 ночей", "106 ночей", "6-9, 12 ночей", "Не 6 или 9 ночей", "0 или 9 ночей"]:
            with self.subTest(text=text):
                self.assertIsNone(value(extract(message(text)), "duration"))
        self.assertIsNone(value(extract(message("На 6 или 9 ночей", "manager")), "duration"))

    def test_ordinal_date_window_keeps_both_bounds_and_whole_span(self):
        for text in ["Вылет с 6 го по 9 сентября включительно", "Вылет с 6-го по 9-го сентября", "Вылет 6–9 сентября"]:
            with self.subTest(text=text):
                p = extract(message(text)); d = value(p, "dates")
                self.assertEqual((d["start_day"], d["end_day"], d["start_month"], d["year"]), (6, 9, 9, None))
                self.assertEqual(d["window_kind"], "departure")
                span = p["fields"]["dates"]["references"][0]["span"]
                self.assertIn("6", text[slice(*span)])

    def test_open_date_bound_is_not_an_exact_date_or_invented_next_day(self):
        for text in ["После 6 сентября", "После 06.09"]:
            with self.subTest(text=text):
                d = value(extract(message(text)), "dates")
                self.assertEqual((d["start_day"], d["start_month"], d["start_inclusive"]), (6, 9, False))
                self.assertIsNone(d["end_day"])
                self.assertIsNone(d["end_month"])
                self.assertIsNone(d["year"])

    def test_upper_date_bound_keeps_qualified_inclusivity(self):
        for text, inclusive in [("До 6 сентября", None), ("До 6 сентября включительно", True),
                                ("Раньше 06.09", False), ("Не позднее 6 сентября", True)]:
            with self.subTest(text=text):
                d = value(extract(message(text)), "dates")
                self.assertIsNone(d["start_day"])
                self.assertEqual((d["end_day"], d["end_month"], d["end_inclusive"]), (6, 9, inclusive))

    def test_lower_date_bound_keeps_inclusive_from_and_not_earlier(self):
        for text in ["С 6 сентября", "Не ранее 06.09"]:
            with self.subTest(text=text):
                d = value(extract(message(text)), "dates")
                self.assertEqual((d["start_day"], d["start_inclusive"]), (6, True))
                self.assertIsNone(d["end_day"])

    def test_ordinal_invalid_date_does_not_drop_first_digit_or_first_bound(self):
        for text in ["Вылет с 36-го по 9 сентября", "Вылет с 6-го по 39 сентября", "Вылет 39 сентября"]:
            with self.subTest(text=text):
                self.assertIsNone(value(extract(message(text)), "dates"))
        self.assertIsNone(value(extract(message("После 6 сентября", "manager")), "dates"))

    def test_lagged_prefix_admits_known_facts_without_admitting_target(self):
        c = case(message("Бюджет до 200 тыс на всех, 2 взрослых"))
        c.update(prefix_complete=False, source_complete_through="2026-10-01T11:00:00Z",
                 source_fence_at="2026-10-01T11:00:00Z")
        p = tool.extract_case(c, CUTOFF)
        view = p["prediction_features"]
        self.assertEqual(view["facts"]["fields.budget"]["value"]["maximum"], 200000)
        self.assertEqual(view["facts"]["party.adults"]["value"], 2)
        self.assertEqual(view["source_gap_seconds"], 3600)
        self.assertEqual(view["facts"]["fields.budget"]["seconds_since_source_record"], 7140)
        self.assertFalse(p["target_admission"]["eligible"])
        self.assertEqual(p["observation"]["label"]["reason"], "incomplete_prefix")

    def test_partial_history_has_fence_without_fabricated_complete_through(self):
        c = case(message("Тур в Египет"))
        c.update(prefix_complete=False, source_complete_through=None, source_fence_at=CUTOFF)
        p = tool.extract_case(c, CUTOFF)
        self.assertIsNone(p["prediction_features"]["source_complete_through"])
        self.assertFalse(p["prediction_features"]["prefix_complete"])
        self.assertEqual(p["prediction_features"]["facts"]["fields.destination"]["value"]["values"], ["Египет"])
        self.assertNotIn("fields.budget", p["prediction_features"]["facts"])
        self.assertEqual(p["prediction_features"]["unavailable_facts"]["fields.budget"], "unknown")

    def test_later_semantic_processing_cannot_enter_historical_features(self):
        p = tool.extract_case(case(message("Бюджет 200 тыс")), LATER)
        self.assertEqual(value(p, "budget")["maximum"], 200000)
        self.assertNotIn("fields.budget", p["prediction_features"]["facts"])
        self.assertEqual(p["prediction_features"]["unavailable_facts"]["fields.budget"], "not_available_at_prediction")
        self.assertFalse(p["observation"]["eligible"])
        self.assertTrue(p["prefix_complete"])
        self.assertEqual(p["target_admission"]["reason"], "target_history_unavailable_at_prediction")

    def test_target_already_reached_keeps_observed_stage_as_fact(self):
        p = extract(message("Давайте бронировать"))
        self.assertEqual(p["prediction_features"]["facts"]["observed_stage"]["value"], "booking_intent")
        self.assertEqual(p["target_admission"], {"target_version": tool.base.TARGET,
                         "eligible": False, "reason": "target_already_reached"})

    def test_feature_projection_excludes_quotes_assumptions_and_conflicts(self):
        p = extract(message("Цена 500 тыс", "manager", 1), message("На двоих", i=2),
                    message("Бюджет 200 тыс", i=3), message("Бюджет 300 тыс", i=4, at="2026-10-01T10:03:00Z"))
        view = p["prediction_features"]
        self.assertNotIn("fields.budget", view["facts"])
        self.assertEqual(view["unavailable_facts"]["fields.budget"], "ambiguous")
        self.assertNotIn("party.adults", view["facts"])
        self.assertEqual(view["facts"]["party.total"]["value"], 2)
        self.assertNotIn("manager_quotes", view)
        self.assertNotIn("working_assumptions", view)

    def test_unknown_coverage_requires_explicit_valid_source_fence(self):
        for update in [{"source_complete_through": None, "prefix_complete": False},
                       {"source_complete_through": None, "source_fence_at": CUTOFF},
                       {"source_fence_at": START}, {"source_fence_at": LATER}]:
            with self.subTest(update=update):
                c = case(message("Тур")); c.update(update)
                with self.assertRaises(tool.base.InvalidObservation): tool.extract_case(c, CUTOFF)

    def test_explicit_source_fence_rejects_later_rows_and_legacy_fence_stays_unknown(self):
        c = case(message("Бюджет 200 тыс"))
        c.update(prefix_complete=False, source_complete_through=START, source_fence_at=START)
        with self.assertRaisesRegex(tool.base.InvalidObservation, "source_row_after_fence"): tool.extract_case(c, CUTOFF)
        c.pop("source_fence_at")
        p = tool.extract_case(c, CUTOFF)
        self.assertIsNone(p["prediction_features"]["source_fence_at"])
        self.assertIsNone(p["prediction_features"]["source_gap_seconds"])

    def test_fact_projection_fingerprint_binds_value_scope_and_actual_availability(self):
        c = case(message("Бюджет 200 тыс")); a = tool.extract_case(c, CUTOFF)["prediction_features"]
        self.assertEqual(a, tool.extract_case(copy.deepcopy(c), CUTOFF)["prediction_features"])
        for change in ["budget", "brand", "person", "availability"]:
            d = copy.deepcopy(c)
            if change == "budget": d["messages"][0]["text"] = "Бюджет 300 тыс"
            elif change == "brand": d["brand_key"] = "pegas"
            elif change == "person": d["subject"]["customer_key"] = "other"
            b = tool.extract_case(d, LATER if change == "availability" else CUTOFF)["prediction_features"]
            self.assertNotEqual(a["feature_fingerprint"], b["feature_fingerprint"])

    def test_later_messages_do_not_refresh_old_facts_or_change_frozen_features(self):
        c = case(message("Бюджет 200 тыс"), message("Спасибо", i=2, at="2026-10-01T11:59:00Z"))
        before = tool.extract_case(c, CUTOFF)["prediction_features"]
        self.assertEqual(before["facts"]["fields.budget"]["seconds_since_source_record"], 7140)
        c["messages"].append(message("Бюджет 500 тыс, давайте бронировать", i=3, at=LATER))
        after = tool.extract_case(c, CUTOFF)["prediction_features"]
        self.assertEqual(before, after)

    def test_decimal_thousands_keep_full_amount_and_source_span(self):
        for text in ["Бюджет 150,5 тыс. ₽", "Бюджет 150.5 тыс. ₽"]:
            with self.subTest(text=text):
                p = extract(message(text))
                self.assertEqual(value(p, "budget")["maximum"], 150500)
                ref = p["fields"]["budget"]["references"][0]
                self.assertIn("150", text[slice(*ref["span"])])

    def test_decimal_range_preserves_per_person_basis(self):
        b = value(extract(message("Бюджет 100,5–150,75 тыс. ₽ на человека")), "budget")
        self.assertEqual((b["minimum"], b["maximum"], b["unit"]),
                         (100500, 150750, "per_person"))

    def test_narrow_no_break_group_separator_is_not_a_suffix_amount(self):
        b = value(extract(message("Бюджет 150\u202f500 рублей")), "budget")
        self.assertEqual(b["maximum"], 150500)

    def test_unsupported_fraction_or_currency_cannot_create_false_ruble_budget(self):
        for text in ["Бюджет 150,50 рублей", "Бюджет 150,5555 тыс. ₽",
                     "Бюджет 100,5,3 тыс. ₽", "Бюджет 2500 GBP",
                     "Бюджет 1,5 млн рублей", "Бюджет 150," + "0"*30 + "1 тыс. ₽"]:
            with self.subTest(text=text):
                self.assertIsNone(value(extract(message(text)), "budget"))

    def test_decimal_manager_quote_keeps_role_and_explicit_currency(self):
        p = extract(message("Цена 1,5 тыс. EUR", "manager"))
        self.assertIsNone(value(p, "budget"))
        self.assertEqual(p["manager_quotes"][0]["value"]["maximum"], 1500)
        self.assertEqual(p["manager_quotes"][0]["value"]["currency"], "EUR")

    def test_decimal_amount_respects_bounds_and_does_not_assume_scale(self):
        for text, bounds in [("Бюджет до 150,5 тыс", (None, 150500)),
                             ("Бюджет от 150,5 тыс", (150500, None)),
                             ("Бюджет 150,0 рублей", (150, 150))]:
            with self.subTest(text=text):
                b = value(extract(message(text)), "budget")
                self.assertEqual((b["minimum"], b["maximum"]), bounds)

    def test_negated_other_seller_purchase_is_not_a_terminal_outcome(self):
        p = extract(message("Мы не купили в другом агентстве"))
        self.assertNotIn(p["current_stage"]["value"], tool.base.NEGATIVE)

    def test_negated_trip_refusal_is_not_abandonment(self):
        p = extract(message("Мы не от поездки отказываемся, а от этого отеля"))
        self.assertNotIn(p["current_stage"]["value"], tool.base.NEGATIVE)

    def test_negated_comparison_is_not_customer_comparison(self):
        p = extract(message("Мы не сравниваем, просто уточняем"))
        self.assertNotEqual(p["current_stage"]["value"], "comparing")

    def test_affirmed_intent_after_negated_purchase_is_retained(self):
        p = extract(message("Мы не купили в другом агентстве. Давайте бронировать"))
        self.assertEqual(p["current_stage"]["value"], "booking_intent")

    def test_later_affirmed_comparison_after_negated_occurrence(self):
        p = extract(message("Мы не сравниваем цены, но сравниваем условия"))
        self.assertEqual(p["current_stage"]["value"], "comparing")

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
