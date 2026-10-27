#!/usr/bin/env python3
"""Offline contract/meaning tests; synthetic examples are not held-out accuracy."""
import copy
import hashlib
import json
from pathlib import Path
import subprocess
import sys
import tempfile
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


def membership_receipt(identifier="membership:1", kind="subscription", state="subscribed",
                       at="2026-10-01T10:00:00Z", recorded="2026-10-01T10:01:00Z",
                       available="2026-10-01T10:02:00Z", **extra):
    row = {"id": identifier, "kind": kind, "source": "telegram_membership_history",
           "identity_scope": "same_chat_and_source", "state": state, "at": at,
           "recorded_at": recorded, "available_at": available}
    row.update(extra)
    return row


def extract(*messages):
    return tool.extract_case(case(*messages), CUTOFF)


def value(profile, field):
    return profile["fields"][field]["value"]


class ProfileTests(unittest.TestCase):
    def test_membership_receipts_preserve_cutoff_and_do_not_invent_retention(self):
        subscribed = membership_receipt()
        c = case(message("Турция")); c["membership_receipts"] = [subscribed]
        p = tool.extract_case(c, CUTOFF)
        self.assertEqual(p["early_signals"]["subscription"]["state"], "subscribed")
        self.assertEqual(p["early_signals"]["subscription"]["recorded_at"], "2026-10-01T10:01:00Z")
        self.assertEqual(p["early_signals"]["retention_30m"],
                         {"status": "unknown", "reason": "retention_receipt_missing"})
        self.assertEqual(p["prediction_features"]["early_signals"], p["early_signals"])
        self.assertIsNone(p["purchase_with_us_probability"])
        self.assertIsNone(p["expected_value_rub"])

        early = copy.deepcopy(c)
        early["prediction_at"] = early["source_complete_through"] = "2026-10-01T10:20:00Z"
        pending = tool.extract_case(early, "2026-10-01T10:20:00Z")["early_signals"]
        self.assertEqual(pending["retention_30m"], {"status": "pending", "reason": "window_not_mature"})

    def test_retention_requires_same_period_scope_and_actual_availability(self):
        subscribed = membership_receipt()
        retained = membership_receipt("retention:1", "retention_30m", "retained",
            "2026-10-01T10:30:00Z", "2026-10-01T10:31:00Z", "2026-10-01T10:32:00Z",
            subscription_id="membership:1", minimum_age_seconds=1800)
        c = case(message("Турция")); c["membership_receipts"] = [subscribed, retained]
        signal = tool.extract_case(c, CUTOFF)["early_signals"]["retention_30m"]
        self.assertEqual((signal["status"], signal["state"], signal["seconds_after_subscription"]),
                         ("known", "retained", 1800))

        late = copy.deepcopy(c)
        late["membership_receipts"][1]["available_at"] = "2026-10-01T12:30:00Z"
        self.assertEqual(tool.extract_case(late, CUTOFF)["early_signals"]["retention_30m"],
                         {"status": "unknown", "reason": "not_available_at_prediction"})

        unscoped = copy.deepcopy(c)
        unscoped["membership_receipts"][1]["identity_scope"] = "source_only"
        self.assertEqual(tool.extract_case(unscoped, CUTOFF)["early_signals"]["retention_30m"],
                         {"status": "unknown", "reason": "identity_or_period_unverified"})

        wrong_period = copy.deepcopy(c)
        wrong_period["membership_receipts"][1]["subscription_id"] = "membership:other"
        self.assertEqual(tool.extract_case(wrong_period, CUTOFF)["early_signals"]["retention_30m"],
                         {"status": "unknown", "reason": "retention_receipt_missing"})

    def test_membership_current_state_cannot_be_backdated_or_cross_platform_joined(self):
        late = membership_receipt(available="2026-10-01T12:30:00Z")
        c = case(message("Турция")); c["membership_receipts"] = [late]
        self.assertEqual(tool.extract_case(c, CUTOFF)["early_signals"]["subscription"],
                         {"status": "unknown", "reason": "not_available_at_prediction"})
        wrong = membership_receipt(); wrong["source"] = "max_membership_history"
        c["membership_receipts"] = [wrong]
        self.assertEqual(tool.extract_case(c, CUTOFF)["early_signals"]["subscription"],
                         {"status": "unknown", "reason": "identity_scope_unverified"})
    def test_hotel_question_is_consumed_by_first_customer_answer(self):
        question = message("Рассматриваете Rixos?", "manager")
        for answer in ["Нет, этот отель не подходит. Хочу другой.", "Подумаем", "Да"]:
            with self.subTest(answer=answer):
                p = extract(question, message(answer, i=2), message("Да", i=3))
                if answer == "Да":
                    self.assertEqual(value(p, "hotel_interest"), "Rixos")
                    self.assertEqual(p["fields"]["hotel_interest"]["references"][-1]["id"], "message:2")
                else:
                    self.assertIsNone(value(p, "hotel_interest"))
                    self.assertNotIn("fields.hotel_interest", p["prediction_features"]["facts"])
        p = extract(question, message("Нет", i=2), message("Рассматриваете другой отель?", "manager", i=3), message("Да", i=4))
        self.assertEqual(value(p, "hotel_interest"), "другой отель")

    def test_historical_destination_is_not_current_intent(self):
        for text, expected in [
            ("В прошлом году отдыхали в Египте, сейчас хотим Турцию.", ["Турция"]),
            ("Сейчас хотим Турцию, в прошлом году отдыхали в Египте.", ["Турция"]),
            ("Раньше были в Египте. Теперь рассматриваем Турцию или Таиланд.", ["Турция", "Таиланд"]),
            ("Отдыхали в Египте, а хотим Турцию.", ["Турция"]),
            ("В прошлом году хотели Египет, сейчас рассматриваем Турцию и Египет.", ["Турция", "Египет"]),
            ("Рассматриваем Турцию или Египет.", ["Турция", "Египет"]),
            ("Хотим вернуться в Египет где были раньше.", ["Египет"]),
            ("В Турции ещё не были и хотим туда.", ["Турция"]),
            ("Мы были бы рады отдохнуть в Турции.", ["Турция"]),
            ("Отдыхали в Египте — хотим Турцию.", ["Турция"]),
            ("В прошлом году отдыхали — в Египте, сейчас хотим Турцию.", ["Турция"]),
            ("Сейчас хотим Турцию — в прошлом году отдыхали в Египте.", ["Турция"]),
            ("Отдыхали в Египте — мы хотим Турцию.", ["Турция"]),
            ("Отдыхали в Египте — в этом году хотим Турцию.", ["Турция"]),
            ("Отдыхали в Египте — интересует Турция.", ["Турция"]),
            ("Хотим Турцию — в Египте уже были.", ["Турция"]),
            ("Сейчас хотим Турцию — в Египте отдыхали в прошлом году.", ["Турция"]),
            ("Отдыхали в Египте — Турцию сейчас рассматриваем.", ["Турция"]),
            ("Отдыхали в Египте и хотим Турцию или Таиланд.", ["Турция", "Таиланд"]),
        ]:
            with self.subTest(text=text):
                p = extract(message(text))
                self.assertEqual(value(p, "destination")["values"], expected)
                self.assertEqual(p["prediction_features"]["facts"]["fields.destination"]["value"]["values"], expected)
        p = extract(message("В прошлом году отдыхали в Египте."))
        self.assertIsNone(value(p, "destination"))
        self.assertNotIn("fields.destination", p["prediction_features"]["facts"])

    def test_nonprice_preferences_keep_distinct_customer_meanings_and_evidence(self):
        text="Без муссонов. Купаться в море. Вечером погулять, чтобы было оживленно."
        p=extract(message(text))
        self.assertEqual(value(p,"weather_preference"),"avoid_monsoon")
        self.assertEqual(value(p,"swimming_preference"),"desired")
        self.assertEqual(value(p,"evening_walks_preference"),"desired")
        self.assertEqual(value(p,"evening_environment_preference"),"lively")
        for name in ["weather_preference","swimming_preference","evening_walks_preference","evening_environment_preference"]:
            row=p["fields"][name]
            self.assertEqual(row["references"][0]["speaker"],"customer")
            self.assertTrue(text[slice(*row["references"][0]["span"])])
            self.assertIn("fields."+name,p["prediction_features"]["facts"])
        self.assertIsNone(p["expected_value_rub"]);self.assertFalse(p["export_allowed"])
        self.assertEqual(value(extract(message("Направление, где нет муссонов")),"weather_preference"),"avoid_monsoon")
        self.assertIsNone(value(extract(message("В этой стране нет муссонов")),"weather_preference"))

    def test_preferences_do_not_invent_destination_weather_or_nightlife(self):
        p=extract(message("Можно купаться, вечером гулять"))
        self.assertEqual(value(p,"swimming_preference"),"desired")
        self.assertEqual(value(p,"evening_walks_preference"),"desired")
        self.assertIsNone(value(p,"evening_environment_preference"))
        self.assertIsNone(value(p,"weather_preference"))
        self.assertIsNone(value(p,"destination"))
        self.assertNotIn("nightlife",json.dumps(value(p,"evening_walks_preference")))

    def test_later_preference_removal_changes_only_its_own_field(self):
        p=extract(message("Без муссонов. Купаться. Вечером погулять, чтобы было оживленно"),
                  message("Купание не важно",i=2))
        self.assertEqual(value(p,"swimming_preference"),"unrestricted")
        span=p["fields"]["swimming_preference"]["references"][0]["span"]
        self.assertIn("не важно","Купание не важно"[slice(*span)])
        self.assertEqual(value(p,"weather_preference"),"avoid_monsoon")
        self.assertEqual(value(p,"evening_walks_preference"),"desired")
        p=extract(message("Хотим купаться"),message("Купаться больше не планируем",i=2))
        self.assertEqual(value(p,"swimming_preference"),"not_desired")
        p=extract(message("Без муссонов"),message("Муссоны не принципиальны",i=2))
        self.assertEqual(value(p,"weather_preference"),"unrestricted")
        p=extract(message("Вечером прогулки, чтобы было оживленно"),message("Вечерние прогулки не важны",i=2))
        self.assertEqual(value(p,"evening_walks_preference"),"unrestricted")
        self.assertEqual(value(p,"evening_environment_preference"),"lively")
        self.assertEqual(p["fields"]["evening_environment_preference"]["references"][0]["id"],"message:1")

    def test_manager_actions_past_reports_and_questions_are_not_preferences(self):
        texts=["Без муссонов. Купаться в море. Вечером гулять",
               "Менеджер предложил отдых без муссонов, можно купаться",
               "В прошлом году купались и гуляли вечером", "Без муссонов можно купаться?",
               "Если можно купаться, какая цена?"]
        for text in texts:
            options=[{}] if text!=texts[0] else [{"speaker":"manager"},{"speaker":"other"},{"kind":"action"}]
            for option in options:
                with self.subTest(text=text,option=option):
                    p=extract(message(text,**option))
                    for name in ["weather_preference","swimming_preference","evening_walks_preference","evening_environment_preference"]:
                        self.assertIsNone(value(p,name))

    def test_preferences_keep_actual_semantic_availability_and_trip_coverage(self):
        p=tool.extract_case(case(message("Без муссонов. Купаться")),LATER)
        self.assertEqual(value(p,"weather_preference"),"avoid_monsoon")
        self.assertNotIn("fields.weather_preference",p["prediction_features"]["facts"])
        p=extract(message("Купаться",at=LATER))
        self.assertIsNone(value(p,"swimming_preference"))

    def test_month_only_preserves_unspecified_day_year_and_evidence(self):
        for text, month, year in [("Июль, хотим отдых на море", 7, None),
                                  ("Планируем на февраль 2028 года", 2, 2028),
                                  ("В мае хотим в отпуск", 5, None)]:
            with self.subTest(text=text):
                p = extract(message(text)); row = p["fields"]["dates"]; d = row["value"]
                self.assertIsNotNone(d)
                self.assertEqual((d["start_month"], d["end_month"], d["year"]), (month, month, year))
                self.assertIsNone(d["start_day"]); self.assertIsNone(d["end_day"])
                self.assertEqual(d["precision"], "month")
                span = row["references"][0]["span"]
                self.assertTrue(text[slice(*span)])

    def test_month_only_does_not_hide_invalid_day_or_make_alternative_exact(self):
        for text in ["31 февраля", "45 марта", "Февраль или март", "После августа", "До октября", "С октября",
                     "Не в июле", "В июле не поедем",
                     "В прошлом году отдыхали в июле", "Менеджер предложил август", "Майя хочет тур",
                     "Июль не рассматриваем", "Раньше были в августе", "На январь 20288 года"]:
            with self.subTest(text=text):
                self.assertIsNone(value(extract(message(text)), "dates"))

    def test_hotel_star_range_and_discrete_alternatives_keep_their_meaning(self):
        for text, expected in [("Отель 3-5*", {"minimum":3,"maximum":5}),
                               ("Рассматриваем отели 3 или 5 звезд", {"minimum":3,"maximum":5,"options":[3,5]}),
                               ("Нужен отель 4* или 5*", {"minimum":4,"maximum":5,"options":[4,5]}),
                               ("Отели 4/5*", {"minimum":4,"maximum":5,"options":[4,5]}),
                               ("Хотим отель 4*", {"minimum":4,"maximum":4}),
                               ("Отель не ниже 4 звезд", {"minimum":4,"maximum":None})]:
            with self.subTest(text=text):
                p = extract(message(text)); row=p["fields"]["hotel_stars"]
                self.assertEqual(row["value"],expected)
                self.assertEqual(row["references"][0]["speaker"],"customer")
                self.assertTrue(text[slice(*row["references"][0]["span"])])

    def test_invalid_or_reported_stars_do_not_become_customer_requirement(self):
        for text in ["Отель 6-4*", "Отель 10*", "Отель 4,5*", "Отель 5-3*", "Отель 0-4*",
                     "Менеджер предложил отель 5*", "Есть отель 4*?", "В прошлом году жили в отеле 4*",
                     "Не хотим отель 5*", "Отель 5* не подходит", "Рейтинг отеля 4,5", "Бюджет до 400 тыс.",
                     "Отель 4* не рассматриваем", "4*2", "4 * 2"]:
            with self.subTest(text=text):
                self.assertIsNone(value(extract(message(text)),"hotel_stars"))

    def test_month_and_hotel_class_updates_keep_roles_cutoff_and_null_export(self):
        p=extract(message("Июль, отель 4*"),message("Август, отель 5*",speaker="manager",i=2))
        self.assertEqual(value(p,"dates")["start_month"],7)
        self.assertEqual(value(p,"hotel_stars")["minimum"],4)
        p=extract(message("Июль, отель 4*"),message("На август, отель 5*",i=2))
        self.assertEqual(value(p,"dates")["start_month"],8)
        self.assertEqual(value(p,"hotel_stars")["minimum"],5)
        for options in [{"speaker":"manager"},{"speaker":"other"},{"kind":"action"},{"at":LATER}]:
            with self.subTest(options=options):
                p=extract(message("Август, отель 5*",**options))
                self.assertIsNone(value(p,"dates"));self.assertIsNone(value(p,"hotel_stars"))
        p=tool.extract_case(case(message("Август, отель 5*")),LATER)
        self.assertNotIn("fields.hotel_stars",p["prediction_features"]["facts"])
        self.assertNotIn("fields.dates",p["prediction_features"]["facts"])
        self.assertIsNone(p["expected_value_rub"]);self.assertFalse(p["export_allowed"])

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



# Current admitted-journal controls reuse this canonical test entrypoint.
import profile_conversion_journal as journal
import build_conversion_observations as base

J_REQUEST = "2026-10-26T09:10:00Z"
J_EXTRACT = "2026-10-26T09:10:01Z"


def journal_row(identifier="1", text="Бюджет до 200 тыс на всех, 2 взрослых", **extra):
    snap = {"source_kind": "message", "source_id": identifier, "source_status": "present",
            "project_key": "anytour", "platform": "telegram", "conversation_id": "10", "customer_key": "7",
            "payload_status": "copied", "source_created_at_unix": 1793005200,
            "source_timestamp_basis": "database_session_timezone_to_unix",
            "payload": {"direction": "inbound", "sender_type": "customer", "text": text,
                        "metadata_json": '{"type":"message"}'}}
    snap.update(extra)
    return {"journal_id": identifier, "source_kind": "message", "source_id": identifier,
            "source_version_no": 1, "observed_at_unix": 1793005800, "snapshot": snap}


def journal_document(*rows):
    return {"schema_version": 1, "requested_at": J_REQUEST, "journal_cursor": "20",
            "cases": [{"subject": {"project_key": "anytour", "platform": "telegram", "customer_key": "7", "conversation_id": "10"},
                       "rows": list(rows), "window_limited": False, "access_denied": False}]}


def wizard_receipt(input_row, field, value, identifier="19"):
    row = journal_row(identifier)
    row["source_kind"] = row["snapshot"]["source_kind"] = "event"
    row["snapshot"]["source_created_at_unix"] += 1
    payload = input_row["snapshot"]["payload"]
    row["snapshot"]["payload"] = {"event_type": "wizard_value_applied", "actor_type": "system",
        "payload_json": json.dumps({"version": 1, "origin": "existing_wizard_step_application",
            "input_message_id": input_row["source_id"], "input_kind": json.loads(payload["metadata_json"])["type"],
            "input_text_sha256": hashlib.sha256(payload["text"].encode()).hexdigest(),
            "field": field, "storage_value": value})}
    return row


def membership_journal_receipt(identifier, event_type, occurred, recorded, observed, **extra):
    row = journal_row(identifier)
    row["source_kind"] = row["snapshot"]["source_kind"] = "event"
    row["snapshot"]["source_created_at_unix"] = recorded
    row["observed_at_unix"] = observed
    payload = {"version": 1, "origin": "existing_membership_producer",
               "source": "telegram_membership_history", "identity_scope": "same_chat_and_source",
               "state": "subscribed" if event_type == "channel_subscription_observed" else "retained",
               "occurred_at_unix": occurred}
    payload.update(extra)
    row["snapshot"]["payload"] = {"event_type": event_type, "actor_type": "system",
                                      "payload_json": json.dumps(payload)}
    return row


class JournalProfileTests(unittest.TestCase):
    def test_membership_receipts_flow_through_actual_journal_cli(self):
        subscription = membership_journal_receipt("5", "channel_subscription_observed",
                                                   1793005200, 1793005260, 1793005320)
        retention = membership_journal_receipt("6", "channel_retention_30m_observed",
                                                1793007000, 1793007060, 1793007120,
                                                subscription_event_id="5", minimum_age_seconds=1800)
        document = journal_document(subscription, retention)
        document["requested_at"] = "2026-10-26T10:00:00Z"
        with tempfile.NamedTemporaryFile(mode="w", suffix=".json") as handle:
            json.dump(document, handle); handle.flush()
            run = subprocess.run([sys.executable, str(ROOT/"tools/profile_conversion_journal.py"), handle.name],
                                 text=True, capture_output=True)
        self.assertEqual(run.returncode, 0, run.stderr)
        cli = json.loads(run.stdout)
        direct = journal.profile(document, cli["extracted_at"])
        self.assertEqual(cli, direct)
        result = direct["profiles"][0]
        signal = result["profile"]["early_signals"]
        self.assertEqual(signal["subscription"]["state"], "subscribed")
        self.assertEqual(signal["retention_30m"]["state"], "retained")
        self.assertEqual(signal["retention_30m"]["seconds_after_subscription"], 1800)
        self.assertEqual(result["coverage"]["errors"], {})
        self.assertFalse(result["profile"]["export_allowed"])

    def test_recording_clock_survives_actual_journal_cli_without_wall_inference(self):
        message_row = journal_row("1", text="Бюджет до 200 тыс на всех")
        answer = journal_row("2", text="2")
        applied = wizard_receipt(answer, "adults", "2", "3")
        subscription = membership_journal_receipt("5", "channel_subscription_observed", 1793005200, 1793005260, 1793005320)
        retention = membership_journal_receipt("6", "channel_retention_30m_observed", 1793007000, 1793007060, 1793007120,
                                               subscription_event_id="5", minimum_age_seconds=1800)
        rows = [message_row, answer, applied, subscription, retention]
        for row in rows:
            snap = row["snapshot"]
            snap["source_timestamp_basis"] = "canonical_recording_unix_v1"
            # A bare wall value can differ by three hours; it is not a fallback clock.
            snap["source_created_at"] = "2026-10-26 12:00:00"
            key = "metadata_json" if row["source_kind"] == "message" else "payload_json"
            meta = json.loads(snap["payload"][key])
            meta["_recorded_clock"] = {"version": 1, "basis": "server_recording_unix", "unix": snap["source_created_at_unix"]}
            snap["payload"][key] = json.dumps(meta)
        document = journal_document(*rows); document["requested_at"] = "2026-10-26T10:00:00Z"
        with tempfile.NamedTemporaryFile(mode="w", suffix=".json") as handle:
            json.dump(document, handle); handle.flush()
            run = subprocess.run([sys.executable, str(ROOT/"tools/profile_conversion_journal.py"), handle.name], text=True, capture_output=True)
        self.assertEqual(run.returncode, 0, run.stderr)
        cli = json.loads(run.stdout); self.assertEqual(cli, journal.profile(document, cli["extracted_at"]))
        result = cli["profiles"][0]; p = result["profile"]
        self.assertEqual(result["coverage"]["errors"], {})
        self.assertEqual(p["fields"]["budget"]["value"]["maximum"], 200000)
        self.assertEqual(p["party"]["adults"]["value"], 2)
        self.assertEqual(p["early_signals"]["retention_30m"]["state"], "retained")
        self.assertEqual(p["prediction_features"]["facts"]["fields.budget"]["last_source_recorded_at"], "2026-10-26T09:00:00Z")
        self.assertFalse(p["export_allowed"]); self.assertIsNone(p["expected_value_rub"])

    def test_recording_clock_header_must_agree_with_native_snapshot(self):
        for clock in [None, {"version": 1, "basis": "server_recording_unix", "unix": 1793005201},
                      {"version": True, "basis": "server_recording_unix", "unix": 1793005200},
                      {"version": 1, "basis": "server_recording_unix", "unix": "1793005200"},
                      {"version": 1, "basis": "server_recording_unix", "unix": 1793005200, "extra": True}]:
            row = journal_row(source_timestamp_basis="canonical_recording_unix_v1")
            meta = {"type": "message"}
            if clock is not None: meta["_recorded_clock"] = clock
            row["snapshot"]["payload"]["metadata_json"] = json.dumps(meta)
            result = self.build(row)
            self.assertEqual(result["coverage"]["errors"], {"invalid_message_metadata": 1})
            self.assertEqual(result["profile"]["fields"]["budget"]["status"], "unknown")
            self.assertNotIn("fields.budget", result["profile"]["prediction_features"]["facts"])

    def test_current_intent_runtime_and_cli(self):
        scenarios = [
            ([('manager', 'Рассматриваете Rixos?'), ('customer', 'Нет, этот отель не подходит. Хочу другой.'), ('customer', 'Да')], None, None),
            ([('manager', 'Рассматриваете Rixos?'), ('customer', 'Да')], 'Rixos', None),
            ([('customer', 'В прошлом году отдыхали в Египте, сейчас хотим Турцию.')], None, ['Турция']),
            ([('customer', 'Рассматриваем Турцию или Египет.')], None, ['Турция', 'Египет']),
            ([('customer', 'В Турции ещё не были и хотим туда.')], None, ['Турция']),
            ([('customer', 'Мы были бы рады отдохнуть в Турции.')], None, ['Турция']),
            ([('customer', 'Отдыхали в Египте — хотим Турцию.')], None, ['Турция']),
            ([('customer', 'В прошлом году отдыхали — в Египте, сейчас хотим Турцию.')], None, ['Турция']),
            ([('customer', 'Сейчас хотим Турцию — в прошлом году отдыхали в Египте.')], None, ['Турция']),
            ([('customer', 'Отдыхали в Египте — мы хотим Турцию.')], None, ['Турция']),
            ([('customer', 'Отдыхали в Египте — в этом году хотим Турцию.')], None, ['Турция']),
            ([('customer', 'Отдыхали в Египте — интересует Турция.')], None, ['Турция']),
            ([('customer', 'Хотим Турцию — в Египте уже были.')], None, ['Турция']),
            ([('customer', 'Сейчас хотим Турцию — в Египте отдыхали в прошлом году.')], None, ['Турция']),
            ([('customer', 'Отдыхали в Египте — Турцию сейчас рассматриваем.')], None, ['Турция']),
        ]
        for turns, hotel, countries in scenarios:
            with self.subTest(turns=turns):
                rows = []
                for i, (speaker, text) in enumerate(turns, 1):
                    row = journal_row(str(i), text=text)
                    row['snapshot']['source_created_at_unix'] += i
                    row['snapshot']['payload'].update(sender_type=speaker, direction='outbound' if speaker == 'manager' else 'inbound')
                    rows.append(row)
                document = journal_document(*rows)
                with tempfile.NamedTemporaryFile(mode='w', suffix='.json') as handle:
                    json.dump(document, handle); handle.flush()
                    run = subprocess.run([sys.executable, str(ROOT/'tools/profile_conversion_journal.py'), handle.name], text=True, capture_output=True)
                self.assertEqual(run.returncode, 0, run.stderr)
                cli = json.loads(run.stdout)
                direct = journal.profile(document, cli['extracted_at'])
                self.assertEqual(cli, direct)
                result = direct['profiles'][0]
                self.assertEqual(result['coverage']['errors'], {})
                p = result['profile']
                self.assertEqual(value(p, 'hotel_interest'), hotel)
                self.assertEqual(value(p, 'destination')['values'] if countries else value(p, 'destination'), countries)
                self.assertEqual(p['extractor_sha256'], hashlib.sha256((ROOT/'tools/extract_conversion_profiles.py').read_bytes()).hexdigest())
                if hotel is None:
                    self.assertNotIn('fields.hotel_interest', p['prediction_features']['facts'])
                if countries:
                    self.assertEqual(p['prediction_features']['facts']['fields.destination']['value']['values'], countries)

    def build(self, *rows):
        return journal.profile(journal_document(*rows), J_EXTRACT)["profiles"][0]

    def test_successful_short_answer_and_buttons_build_one_grounded_profile(self):
        rows = []
        values = [("2", "adults", "2", "message"), ("child_1", "children", "1", "callback"),
                  ("7", "child_ages", "7", "message"), ("nights_6_8", "nights", "6-8", "callback"),
                  ("pick_date_15.12.2026", "date", "15.12.2026", "callback"),
                  ("pick_country_4", "country", "4", "callback")]
        for i, (text, field, value, kind) in enumerate(values, 1):
            row = journal_row(str(i), text=text)
            row["snapshot"]["payload"]["metadata_json"] = json.dumps({"type": kind})
            rows += [row, wizard_receipt(row, field, value, str(i+10))]
        result = self.build(*rows); p = result["profile"]
        self.assertEqual([p["party"][k]["value"] for k in ("adults", "children", "child_ages")], [2, 1, [7]])
        self.assertEqual(p["fields"]["duration"]["value"], {"minimum":6,"maximum":8,"unit":"nights"})
        self.assertEqual(p["fields"]["dates"]["value"]["start_day"], 15)
        self.assertEqual(p["fields"]["destination_reference"]["value"]["id"], "4")
        self.assertEqual(p["fields"]["destination"]["status"], "unknown")
        fact = p["party"]["adults"]
        self.assertEqual(fact["basis"], "canonical_wizard_application")
        self.assertEqual([r["source"] for r in fact["references"]], ["event", "message"])
        self.assertEqual(fact["available_at"], J_EXTRACT)
        self.assertEqual(result["coverage"]["applied_wizard_fields"], 6)
        self.assertFalse(p["target_admission"]["eligible"])
        self.assertIsNone(p["expected_value_rub"])
        self.assertEqual(self.build(*rows[::2])["profile"]["party"]["adults"]["status"], "unknown")

    def test_applied_callback_is_not_customer_dialogue_or_positive_stage(self):
        row = journal_row(text="adults_2")
        row["snapshot"]["payload"]["metadata_json"] = '{"type":"callback"}'
        p = self.build(row, wizard_receipt(row,"adults","2"))["profile"]
        self.assertEqual(p["party"]["adults"]["value"], 2)
        self.assertEqual(p["current_stage"]["status"], "unknown")
        self.assertFalse(p["observation_input"]["evidence"])

    def test_application_needs_matching_unedited_customer_input_not_current_state(self):
        for mutation in [lambda r:r["snapshot"]["payload"].update(text="3"),
                         lambda r:r["snapshot"]["payload"].update(sender_type="ai"),
                         lambda r:r["snapshot"]["payload"].update(direction="outbound"),
                         lambda r:r["snapshot"]["payload"].update(metadata_json='{"type":"message","linked_message":{"type":"forward"}}'),
                         lambda r:r.update(source_version_no=2),
                         lambda r:r["snapshot"].update(source_created_at_unix=1793005202)]:
            row = journal_row(text="2"); receipt = wizard_receipt(row,"adults","2"); mutation(row)
            result = self.build(row,receipt)
            self.assertEqual(result["profile"]["party"]["adults"]["status"], "unknown")
            self.assertEqual(result["coverage"]["applied_wizard_fields"], 0)

    def test_missing_input_wrong_question_and_unsupported_receipt_stay_unknown(self):
        row = journal_row(text="adults_2"); row["snapshot"]["payload"]["metadata_json"] = '{"type":"callback"}'
        self.assertEqual(self.build(wizard_receipt(row,"adults","2"))["coverage"]["errors"], {"wizard_input_missing_or_ambiguous":1})
        for field, value in [("children","2"),("adults","3"),("adults","999"),("budget","200000")]:
            result = self.build(row,wizard_receipt(row,field,value))
            self.assertEqual(result["coverage"]["applied_wizard_fields"], 0)
        receipt = wizard_receipt(row,"adults","2"); receipt["snapshot"]["payload"]["actor_type"] = "ai"
        self.assertEqual(self.build(row,receipt)["coverage"]["applied_wizard_fields"], 0)

    def test_receipt_repeat_is_idempotent_and_conflict_is_not_an_accepted_value(self):
        row = journal_row(text="2")
        first = wizard_receipt(row,"adults","2","18"); second = wizard_receipt(row,"adults","2","19")
        one = self.build(row,first); twice = self.build(row,first,second)
        self.assertEqual(one["profile"], twice["profile"])
        second = wizard_receipt(row,"adults","3","19")
        p = self.build(row,first,second)
        self.assertEqual(p["profile"]["party"]["adults"]["status"], "unknown")
        self.assertEqual(p["coverage"]["errors"]["conflicting_wizard_application"], 1)

    def test_receipt_has_its_own_availability_and_respects_frozen_fence(self):
        row = journal_row(text="2"); receipt = wizard_receipt(row,"adults","2")
        receipt["observed_at_unix"] -= 1
        p = self.build(row,receipt)["profile"]
        self.assertEqual(p["party"]["adults"]["references"][0]["available_at"], "2026-10-26T09:09:59Z")
        self.assertEqual(p["party"]["adults"]["references"][1]["available_at"], J_REQUEST)
        receipt["observed_at_unix"] = 1793005801
        with self.assertRaises(base.InvalidObservation): self.build(row,receipt)
        receipt = wizard_receipt(row,"adults","2","21")
        with self.assertRaises(base.InvalidObservation): self.build(row,receipt)

    def test_application_is_not_retrofitted_to_edited_or_deleted_source(self):
        row = journal_row(text="2"); receipt = wizard_receipt(row,"adults","2")
        receipt["source_version_no"] = 2
        self.assertEqual(self.build(row,receipt)["coverage"]["applied_wizard_fields"], 0)
        row["snapshot"] = {"source_kind":"message","source_id":"1","source_status":"deleted"}
        self.assertEqual(self.build(row,wizard_receipt(journal_row(text="2"),"adults","2"))["coverage"]["applied_wizard_fields"], 0)

    def test_wizard_storage_is_exact_not_a_new_interpretation_of_ids_or_ages(self):
        for field, raw in [("child_ages","7,, 8"),("child_ages","18"),("nights","8-6"),
                           ("date","31.02.2027"),("country","04"),("stars","6")]:
            self.assertIsNone(tool.wizard_value(field,raw))
        for field in ("city","country","meal","stars"):
            target, value = tool.wizard_value(field,"4")
            self.assertTrue(target.endswith("reference"))
            self.assertEqual(value["label_status"], "unresolved")

    def test_applied_legacy_empty_age_separator_is_not_a_customer_zero_age(self):
        for text, raw in [("7,", "7, 0"),("7,,8","7, 0, 8"),(" ","0")]:
            row = journal_row(text=text)
            p = self.build(row,wizard_receipt(row,"child_ages",raw))
            self.assertEqual(p["profile"]["party"]["child_ages"]["status"], "unknown")
            self.assertEqual(p["coverage"]["applied_wizard_fields"], 0)

    def test_profile_keeps_budget_unit_party_without_certifying_trip_or_target(self):
        p = self.build(journal_row())
        self.assertEqual(p["profile"]["fields"]["budget"]["value"]["maximum"], 200000)
        self.assertEqual(p["profile"]["party"]["adults"]["value"], 2)
        self.assertEqual(p["profile"]["trip_status"], "unknown")
        self.assertEqual(p["coverage"]["exposure"], "unknown")
        self.assertEqual(p["coverage"]["identity_assurance"], "canonical_source_assertion")
        self.assertEqual(p["profile"]["prediction_features"]["feature_scope"], "unresolved_conversation_prefix")
        self.assertFalse(p["profile"]["target_admission"]["eligible"])
        self.assertEqual(p["profile"]["target_admission"]["reason"], "trip_boundary_unresolved")
        self.assertIsNone(p["profile"]["expected_value_rub"])

    def test_manager_quote_is_not_customer_budget_even_without_metadata_type(self):
        row = journal_row(text="Стоимость 400000 рублей")
        row["snapshot"]["payload"].update(direction="outbound", sender_type="manager", metadata_json="{}")
        p = self.build(row)["profile"]
        self.assertEqual(p["fields"]["budget"]["status"], "unknown")
        self.assertTrue(p["manager_quotes"])

    def test_native_empty_array_manager_metadata_and_forwarded_text_keep_speaker_boundaries(self):
        row = journal_row(text="Стоимость 400000 рублей")
        row["snapshot"]["payload"].update(direction="outbound", sender_type="manager", metadata_json="[]")
        self.assertTrue(self.build(row)["profile"]["manager_quotes"])
        row = journal_row()
        row["snapshot"]["payload"]["metadata_json"] = '{"type":"message","linked_message":{"type":"forward"}}'
        p = self.build(row)
        self.assertEqual(p["profile"]["fields"]["budget"]["status"], "unknown")
        self.assertEqual(p["coverage"]["errors"]["forwarded_statement_owner_unresolved"], 1)

    def test_actions_other_speaker_and_wrong_direction_cannot_raise_stage(self):
        for metadata, direction, speaker in [('{"type":"callback"}', "inbound", "customer"),
                                               ('{}', "inbound", "customer"),
                                               ('{"type":"message"}', "outbound", "customer"),
                                               ('{"type":"message"}', "inbound", "ai")]:
            row = journal_row(text="Давайте бронировать")
            row["snapshot"]["payload"].update(metadata_json=metadata, direction=direction, sender_type=speaker)
            self.assertEqual(self.build(row)["profile"]["current_stage"]["status"], "unknown")

    def test_booking_words_in_unresolved_trip_do_not_create_training_label(self):
        p = self.build(journal_row(text="Давайте бронировать"))["profile"]
        self.assertEqual(p["current_stage"]["value"], "booking_intent")
        self.assertFalse(p["observation"]["eligible"])
        self.assertIsNone(p["observation"]["label"]["value"])
        self.assertTrue(all(r["confidence"] == "medium" for r in p["observation_input"]["evidence"] if r["id"].startswith("rules:")))

    def test_legacy_unknown_and_future_source_clock_stay_unresolved(self):
        for value in [None, 0, 1793005801]:
            p = self.build(journal_row(source_created_at_unix=value))
            self.assertEqual(p["coverage"]["errors"], {"source_clock_unresolved": 1})
            self.assertEqual(p["profile"]["fields"]["budget"]["status"], "unknown")

    def test_copied_source_can_be_stale_but_semantics_becomes_available_only_now(self):
        p = self.build(journal_row())["profile"]
        fact = p["prediction_features"]["facts"]["fields.budget"]
        self.assertEqual(fact["available_at"], J_EXTRACT)
        self.assertEqual(fact["last_source_recorded_at"], "2026-10-26T09:00:00Z")
        self.assertEqual(fact["seconds_since_source_record"], 601)

    def test_source_scope_revision_and_late_receipt_are_not_silently_accepted(self):
        for mutator in [lambda r:r["snapshot"].update(customer_key="8"),
                        lambda r:r["snapshot"].update(project_key="anex"),
                        lambda r:r.update(observed_at_unix=1793005801),
                        lambda r:r.update(source_id="99")]:
            row = journal_row(); mutator(row)
            with self.assertRaises(base.InvalidObservation):
                self.build(row)

    def test_scope_revocation_cannot_keep_older_customer_facts(self):
        doc = journal_document(journal_row())
        doc["cases"][0]["access_denied"] = True
        p = journal.profile(doc, J_EXTRACT)["profiles"][0]
        self.assertEqual(p["access_status"], "denied")
        self.assertFalse(p["profile"]["prediction_features"]["facts"])

    def test_deletion_and_events_retain_provenance_without_interpreted_intent(self):
        row = journal_row(); row["snapshot"] = {"source_kind":"message", "source_id":"1", "source_status":"deleted"}
        p = self.build(row)
        self.assertEqual(p["coverage"]["errors"], {"deleted": 1})
        self.assertEqual(p["source_references"][0]["source_status"], "deleted")
        row = journal_row(); row["source_kind"] = row["snapshot"]["source_kind"] = "event"
        row["snapshot"]["payload"] = {"event_type":"booking_intent"}
        self.assertEqual(self.build(row)["coverage"]["errors"], {"uninterpreted_event": 1})

    def test_raw_text_is_not_in_normalized_result_and_duplicate_revisions_fail(self):
        row = journal_row(text="private-unrecognized-fixture")
        self.assertNotIn("private-unrecognized-fixture", json.dumps(self.build(row)))
        with self.assertRaises(base.InvalidObservation):
            journal.profile(journal_document(row, row), J_EXTRACT)

    def test_bounded_window_keeps_explicit_incompleteness_without_zero_value(self):
        doc = journal_document(journal_row())
        doc["cases"][0]["window_limited"] = True
        p = journal.profile(doc, J_EXTRACT)["profiles"][0]
        self.assertTrue(p["coverage"]["window_limited"])
        self.assertFalse(p["profile"]["prefix_complete"])
        self.assertIsNone(p["profile"]["purchase_with_us_probability"])

    def test_source_revision_never_invents_an_edit_occurrence_time(self):
        row = journal_row(); row["source_version_no"] = 2
        p = self.build(row)
        self.assertEqual(p["coverage"]["errors"]["source_revision_chronology_unresolved"], 1)
        self.assertEqual(p["profile"]["fields"]["budget"]["value"]["maximum"], 200000)

    def test_trip_status_malformed_types_fail_redacted_contract(self):
        for value in [None, [], {}, True, 1, "invented"]:
            c = case(message("budget fixture")); c["trip_status"] = value
            with self.assertRaises(base.InvalidObservation):
                tool.extract_case(c, CUTOFF)


    def test_information_fence_is_separate_from_processing_position(self):
        row = journal_row()
        row["journal_id"] = "25"; row["progress_journal_id"] = "1"
        doc = journal_document(row); doc["source_fence_journal_id"] = "30"
        p = journal.profile(doc, J_EXTRACT)
        self.assertEqual(p["journal_cursor"], "20")
        self.assertEqual(p["source_fence_journal_id"], "30")
        self.assertEqual(p["profiles"][0]["profile"]["fields"]["budget"]["value"]["maximum"], 200000)
        self.assertEqual(p["profiles"][0]["source_references"][0]["progress_journal_id"], "1")
        doc["source_fence_journal_id"] = "24"
        with self.assertRaises(base.InvalidObservation):
            journal.profile(doc, J_EXTRACT)

    def test_reassigned_source_at_current_fence_never_becomes_old_person_fact(self):
        row = journal_row(customer_key="8"); row["journal_id"] = "25"; row["progress_journal_id"] = "1"
        doc = journal_document(row); doc["source_fence_journal_id"] = "30"
        p = journal.profile(doc, J_EXTRACT)["profiles"][0]
        self.assertEqual(p["coverage"]["errors"]["source_scope_changed_at_information_fence"], 1)
        self.assertFalse(p["profile"]["prediction_features"]["facts"])


    def test_unresolved_trip_cannot_claim_complete_history(self):
        c = case(message("Давайте бронировать")); c["trip_status"] = "unknown"
        with self.assertRaises(base.InvalidObservation):
            tool.extract_case(c, CUTOFF)


if __name__ == "__main__":
    unittest.main(verbosity=2)
