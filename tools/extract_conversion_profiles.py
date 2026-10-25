#!/usr/bin/env python3
"""Conservative offline Russian trip profiles, with source spans; no model/API calls."""

import argparse
import hashlib
import json
import re
import sys
from datetime import date

import build_conversion_observations as base

VERSION = "ru_trip_prefix_rules_v1_1"
MAX_BYTES = 2 * 1024 * 1024
MAX_CASES = 100
MAX_MESSAGES = 500
MAX_TEXT = 12000
with open(__file__, "rb") as _source:
    RULE_SHA = hashlib.sha256(_source.read()).hexdigest()

NUM = r"(?:\d{1,3}(?:[ \u00a0]\d{3})+|\d{1,7})"
MONEY = re.compile(rf"(?<![\w.])(?P<lo>{NUM})(?:\s*[-–]\s*(?P<hi>{NUM}))?\s*"
                   r"(?P<scale>тыс(?:яч\w*)?\.?|т\.\s*р\.|[кk])?\s*"
                   r"(?P<currency>руб\w*|₽|rub|eur|евро|usd|доллар\w*|\$)?(?!\w)", re.I)
COUNTRIES = {
    "Турция": r"турци\w*", "Египет": r"егип\w*", "Таиланд": r"та[ий]ланд\w*",
    "Нячанг": r"нячанг\w*", "Дубай": r"дуба[йяе]\w*", "ОАЭ": r"оаэ",
    "Шри-Ланка": r"шри\s*[-–]?\s*ланк\w*", "Вьетнам": r"вьетнам\w*",
    "Мальдивы": r"мальдив\w*", "Стамбул": r"стамбул\w*",
}
CITIES = {"Москва": r"москв\w*", "Сочи": r"сочи", "Самара": r"самар\w*",
          "Уфа": r"уф[аыеу]", "Санкт-Петербург": r"санкт[- ]петербург\w*|спб",
          "Казань": r"казан\w*", "Екатеринбург": r"екатеринбург\w*",
          "Новосибирск": r"новосибирск\w*", "Калининград": r"калининград\w*"}
MONTHS = {"январ": 1, "феврал": 2, "март": 3, "апрел": 4, "мая": 5, "июн": 6,
          "июл": 7, "август": 8, "сентябр": 9, "октябр": 10, "ноябр": 11, "декабр": 12}


def text_match(pattern, text):
    return re.search(pattern, text, re.I)


def negated(text, start):
    return bool(text_match(r"(?:\bне|\bне надо|\bне хотим|\bне рассматриваем)\s*$", text[max(0, start-30):start]))


def affirmed(pattern, text):
    """A negated occurrence cannot establish a stage; another occurrence may."""
    return next((m for m in re.finditer(pattern, text, re.I)
                 if not negated(text, m.start())), None)


def reference(message, span=None):
    return {"source": "message", "id": message["id"], "speaker": message["speaker"],
            "at": message["at"], "available_at": message["available_at"],
            "text_sha256": hashlib.sha256(message["text"].encode()).hexdigest(),
            "span": list(span or (0, len(message["text"])))}


def unknown():
    return {"status": "unknown", "value": None, "basis": "not_established", "references": [],
            "available_at": None, "usable_at_prediction": False}


def fact(value, message, extracted, cutoff, span=None, basis="explicit_customer", extra=()):
    refs = [reference(m) for m in extra] + [reference(message, span)]
    available = max([extracted] + [r["available_at"] for r in refs])
    return {"status": "known", "value": value, "basis": basis, "references": refs,
            "available_at": available, "usable_at_prediction": available <= cutoff}


def choose(candidates):
    if not candidates:
        return unknown()
    latest = max(c["references"][-1]["at"] for c in candidates)
    rows = [c for c in candidates if c["references"][-1]["at"] == latest]
    values = {base.digest(c["value"]) for c in rows}
    if len(values) > 1:
        return {"status": "ambiguous", "value": None, "basis": "conflicting_customer_statements",
                "references": [r for c in rows for r in c["references"]],
                "available_at": max(c["available_at"] for c in rows),
                "usable_at_prediction": all(c["usable_at_prediction"] for c in rows)}
    return rows[-1]


def monetary(text, customer):
    """Only explicit amounts; no thousands inferred from a bare number or trip price."""
    results = []
    for match in MONEY.finditer(text):
        nearby = text[max(0, match.start()-24):min(len(text), match.end()+65)]
        context = bool(text_match(r"бюджет|лимит|потрат|улож|рассчитываем|не более|\bдо\s*$", text[max(0, match.start()-30):match.start()]))
        if not (match["currency"] or match["scale"] or customer and context and int(re.sub(r"\s", "", match["lo"])) >= 1000):
            continue
        if customer and not context and text_match(r"стоимост|цен[ауы]|стоит|предложили|прислали|купил|оплатил|забронировал", text):
            continue
        if negated(text, match.start()) or customer and "?" in text:
            continue
        scale = 1000 if match["scale"] else 1
        low = int(re.sub(r"\s", "", match["lo"])) * scale
        high = int(re.sub(r"\s", "", match["hi"])) * scale if match["hi"] else low
        if not 0 < low <= high <= 1000000000:
            continue
        currency = (match["currency"] or "RUB").lower()
        currency = "EUR" if currency in {"eur", "евро"} else "USD" if currency in {"usd", "$"} or currency.startswith("доллар") else "RUB"
        before = text[max(0, match.start()-20):match.start()]
        lower_only = bool(text_match(r"\bот\s*$", before))
        upper_only = bool(text_match(r"\b(?:до|максимум|не более)\s*$", before))
        per_person = bool(text_match(r"на человека|за человека|с человека|за одного", nearby))
        explicit_total = bool(text_match(r"на всех|за всех|всего|общий бюджет|на (?:двоих|троих|четверых)", nearby))
        results.append(({"minimum": low if not upper_only else None,
                         "maximum": high if not lower_only else None, "currency": currency,
                         "currency_basis": "explicit" if match["currency"] else "agency_default_rub",
                         "unit": "per_person" if per_person else "party_total",
                         "unit_basis": "explicit" if per_person or explicit_total else "agency_default_party_total"}, match.span()))
    return results


def date_constraints(text):
    """Keep window meaning/year unresolved unless explicitly supplied."""
    results = []
    numeric = r"(?<!\d)(?P<d>\d{1,2})\.(?P<m>\d{1,2})(?:\.(?P<y>20\d{2}))?\.?"
    matches = list(re.finditer(numeric, text))
    if matches:
        first, last = matches[0], matches[-1]
        year = int(first["y"]) if first["y"] else None
        start, end = int(first["d"]), int(last["d"])
        month, end_month = int(first["m"]), int(last["m"])
        try:
            date(year or 2000, month, start); date(int(last["y"] or year or 2000), end_month, end)
        except ValueError:
            return []
        results.append(({"start_day": start, "start_month": month, "end_day": end,
                         "end_month": end_month, "year": year,
                         "window_kind": "departure" if text_match(r"дат\w* вылета|вылет", text) else "unspecified"},
                        (first.start(), last.end())))
        return results
    for word, month in MONTHS.items():
        match = re.search(rf"(?:(?:с|на)\s*)?(?P<d>\d{{1,2}})(?:\s*[-–]\s*(?P<r>\d{{1,2}})|\s+по\s+(?P<r2>\d{{1,2}}))?\s*{word}\w*(?:\s*(?P<y>20\d{{2}}))?", text, re.I)
        if not match:
            continue
        start = int(match["d"]); end = int(match["r"] or match["r2"] or start)
        tail = re.search(rf"по\s*(\d{{1,2}})\s*{word}\w*", text[match.end():], re.I)
        if tail:
            end = int(tail[1])
        year = int(match["y"]) if match["y"] else None
        try:
            date(year or 2000, month, start); date(year or 2000, month, end)
        except ValueError:
            continue
        results.append(({"start_day": start, "start_month": month, "end_day": end,
                         "end_month": month, "year": year, "window_kind": "departure" if text_match(r"вылет", text) else "unspecified"},
                        (match.start(), match.end()+ (tail.end() if tail else 0))))
        break
    return results


def validate_case(case):
    base.fields(case, ("subject", "brand_key", "context_from", "prediction_at", "source_complete_through",
                       "prefix_complete", "messages"))
    scope = base.subject(case["subject"]); base.key(case["brand_key"])
    start, cutoff, complete = map(base.moment, [case["context_from"], case["prediction_at"], case["source_complete_through"]])
    base.require(start <= complete <= cutoff, "invalid_prefix_interval")
    base.require(type(case["prefix_complete"]) is bool, "explicit_completeness_required")
    base.require(not case["prefix_complete"] or complete == cutoff, "incomplete_prediction_coverage")
    base.require(isinstance(case["messages"], list) and len(case["messages"]) <= MAX_MESSAGES, "message_limit")
    unique = {}
    for m in case["messages"]:
        base.fields(m, ("id", "speaker", "kind", "at", "available_at", "text")); base.key(m["id"])
        base.require(isinstance(m["speaker"], str) and isinstance(m["kind"], str)
                     and m["speaker"] in {"customer", "manager", "other"} and m["kind"] in {"text", "action"}, "invalid_message_role")
        base.require(isinstance(m["text"], str) and len(m["text"]) <= MAX_TEXT, "text_limit")
        base.require(base.moment(m["available_at"]) >= base.moment(m["at"]), "source_available_before_occurrence")
        base.require(m["id"] not in unique or unique[m["id"]] == m, "conflicting_message_id")
        unique[m["id"]] = m
    rows = sorted(unique.values(), key=lambda m: (m["at"], m["id"]))
    rows = [m for m in rows if case["context_from"] <= m["at"] <= case["prediction_at"]
            and m["available_at"] <= case["prediction_at"] and m["kind"] == "text"]
    return scope, rows


def extract_case(case, extracted):
    scope, messages = validate_case(case)
    cutoff = case["prediction_at"]
    fields = {name: [] for name in ["budget", "budget_preference", "duration", "dates", "departure",
              "destination", "meal", "date_flexibility_days", "room_arrangement", "family_group",
              "hotel_interest", "unresolved_city", "purchase_report"]}
    party = {k: [] for k in ("adults", "children", "total", "child_ages")}
    offered = []; stages = []; canonical = []; requirements = []; previous_manager = None
    assumption_refs = []

    def add(name, value, m, span=None, basis="explicit_customer", extra=()):
        target = party[name] if name in party else fields[name]
        target.append(fact(value, m, extracted, cutoff, span, basis, extra))

    for m in messages:
        text = m["text"]; folded = text.lower().replace("ё", "е")
        if not text.strip() or m["speaker"] == "other":
            continue
        kind = "customer_message" if m["speaker"] == "customer" else "manager_message"
        raw_ref = {k: reference(m)[k] for k in ("source", "id", "at", "available_at")}
        canonical.append({"id": "message:"+m["id"], "subject": scope, "kind": kind,
                          "at": m["at"], "available_at": m["available_at"], "confidence": "high", "references": [raw_ref]})
        if m["speaker"] == "manager":
            for value, span in monetary(text, False):
                offered.append(fact(value, m, extracted, cutoff, span, "manager_quote_not_customer_budget"))
            previous_manager = m
            continue
        initial_count = sum(len(v) for v in fields.values()) + sum(len(v) for v in party.values())
        for value, span in monetary(text, True):
            add("budget", value, m, span)
        if text_match(r"бюджетн|недорого|не дорого|экономн|подешевле", text):
            add("budget_preference", "economy_preference", m)
        for pattern, component in [(r"(?<![\d-])(\d{1,2})\s*взросл\w*", "adults"),
                                   (r"(?<![\d-])(\d{1,2})\s*(?:детей|ребен\w*|ребён\w*|дет\w*)", "children"),
                                   (r"(?<![\d-])(\d{1,2})\s*(?:чел\w*|человек)", "total")]:
            for match in re.finditer(pattern, text, re.I):
                if not negated(text, match.start()) and 0 <= int(match[1]) <= 30:
                    add(component, int(match[1]), m, match.span())
        pair = text_match(r"\bна двоих\b|\bнас двое\b", text)
        if pair:
            add("total", 2, m, pair.span()); assumption_refs.append(m)
        if text_match(r"\bбез детей\b", text):
            add("children", 0, m)
        ages = text_match(r"(?:детей|дети|ребен\w*|ребён\w*)\s*\(([\d ,;]+)\)", text)
        if ages:
            values = [int(v) for v in re.findall(r"\d+", ages[1])]
            if values and all(0 <= v <= 17 for v in values):
                add("child_ages", values, m, ages.span())
        age = text_match(r"^\s*(\d{1,2})\s*(?:год|года|лет)\b", text)
        if age and int(age[1]) <= 17 and choose(party["children"])["value"] == 1:
            add("child_ages", [int(age[1])], m, age.span(), "single_child_context", [r for r in messages if r["id"] == choose(party["children"])["references"][-1]["id"]])
        duration = text_match(r"(?<![\d+-])(\d{1,2})(?:\s*[-–]\s*(\d{1,2}))?\s*(ноч\w*|дней|дня|день)\b", text)
        reverse = text_match(r"\b(дней|дня|ночей)\s+на\s+(\d{1,2})\b", text)
        if duration and not negated(text, duration.start()):
            lo, hi = int(duration[1]), int(duration[2] or duration[1])
            if 1 <= lo <= hi <= 90:
                add("duration", {"minimum": lo, "maximum": hi, "unit": "nights" if duration[3].lower().startswith("ноч") else "days"}, m, duration.span())
        elif reverse:
            add("duration", {"minimum": int(reverse[2]), "maximum": int(reverse[2]), "unit": "nights" if reverse[1].startswith("ноч") else "days"}, m, reverse.span())
        for value, span in date_constraints(text):
            add("dates", value, m, span)
        flex = text_match(r"(?:\+\s*[-–]|плюс[- ]минус)\s*(\d{1,2})\s*дн\w*", text)
        if flex:
            add("date_flexibility_days", int(flex[1]), m, flex.span())
        for city, pattern in CITIES.items():
            departure = text_match(rf"(?:\bиз\s+|город\s+вылета\s+)({pattern})\b", text)
            if departure:
                add("departure", city, m, departure.span())
            elif text_match(rf"\b(?:{pattern})\b", text):
                add("unresolved_city", city, m, basis="place_role_not_established")
        countries = [name for name, pattern in COUNTRIES.items() if text_match(rf"\b(?:{pattern})\b", text)
                     and not negated(text, text_match(pattern, text).start())]
        if countries:
            add("destination", {"values": countries, "open_alternatives": bool(text_match(r"и т\.?\s*п\.?|другие направления", text))}, m)
        meals = [(r"ультра\s*(?:все включено|олл)|\buai\b", "ultra_all_inclusive"),
                 (r"вс[её]\s*включено|\bai\b", "all_inclusive"),
                 (r"завтрак\w*\s*(?:и|\+)\s*ужин\w*|полупансион|\bhb\b", "half_board"),
                 (r"завтрак\w*|\bbb\b", "breakfast"), (r"без питания|\bro\b", "room_only")]
        if text_match(r"питани\w*\s*(?:без разницы|любое|не важно)", text):
            add("meal", "unrestricted", m)
        else:
            for pattern, value in meals:
                meal = text_match(pattern, text)
                if meal and not negated(text, meal.start()):
                    add("meal", value, m, meal.span()); break
        if text_match(r"(?:разместиться|размещение|номера|номер)\s*(?:нам\s*)?(?:без разницы|не важно|люб\w*)", text):
            add("room_arrangement", "unrestricted", m)
        if text_match(r"семья|семьей|семьёй", text):
            add("family_group", True, m)
        confirmation = None
        if folded.strip(" .!\n") in {"да", "рассматриваем", "да рассматриваем"} and previous_manager:
            question = text_match(r"рассматриваете\s+(.+?)(?:\s+из публикации)?\?", previous_manager["text"])
            if question and len(question[1]) <= 160:
                confirmation = previous_manager
                add("hotel_interest", question[1], m, basis="customer_confirmation_of_hotel_interest", extra=[previous_manager])
        coverage = sum(len(v) for v in fields.values()) + sum(len(v) for v in party.values()) - initial_count
        requirements.append({"reference": reference(m), "recognized_fact_count": coverage,
                             "interpretation_coverage": "partial_rules_only"})
        stage = None
        guarded = bool(text_match(r"\?|\bесли\b|\bпока не\b|\bне\s+(?:будем|готов\w*|хотим|давайте|оплатил\w*|забронировал\w*|выбираем|бер[её]м)\b", text))
        if not guarded:
            if affirmed(r"(?:купили|забронировали)\s+(?:тур\s+)?(?:в другом агентстве|у других|у другого)", text):
                stage = "purchased_elsewhere"
            elif affirmed(r"(?:оплатили|оплатила|оплатил)\s+(?:тур\s+)?у вас", text):
                stage = "paid"
            elif affirmed(r"(?:забронировали|забронировал|забронировала)\s+(?:тур\s+)?у вас", text):
                stage = "booking_confirmed"
            elif affirmed(r"давайте\s+(?:бронировать|забронируем)|готов[ыа]?\s+бронировать|хотим\s+забронировать|^забронируйте\b", text):
                stage = "booking_intent"
            elif affirmed(r"(?:выбираем|берем|берём)\s+(?:этот|первый|второй|третий)\s+(?:тур|вариант|отель)", text) and not affirmed(r"\bне\s+(?:выбираем|берем|берём)", text):
                stage = "offer_selected"
            elif affirmed(r"отпуск отменили|поездку отменили|тур больше не нужен|от поездки отказываемся", text):
                stage = "declined"
        if stage is None and affirmed(r"уже\s+(?:купили|оплатили|забронировали)", text):
            add("purchase_report", "seller_unresolved", m)
        if stage is None and affirmed(r"сравниваем|чем отличаются|какой из (?:этих|двух)", text):
            stage = "comparing"
        if stage is None and (coverage or text_match(r"\bтур\w*\b|путев\w*|путёв\w*|предложения|хотим отдых", text)):
            stage = "clarifying" if previous_manager and ("?" in previous_manager["text"]) else "request"
        if confirmation:
            stage = "clarifying"
        if stage:
            stages.append(fact(stage, m, extracted, cutoff, extra=[confirmation] if confirmation else []))

    result_fields = {name: choose(values) for name, values in fields.items()}
    components = {name: choose(values) for name, values in party.items()}
    adults, children, total = [components[k]["value"] for k in ("adults", "children", "total")]
    if adults is not None and children is not None:
        computed = adults+children
        refs = components["adults"]["references"]+components["children"]["references"]
        if total is not None and total != computed:
            components["total"] = {**components["total"], "status": "ambiguous", "value": None,
                                   "basis": "party_arithmetic_conflict", "references": refs+components["total"]["references"]}
        else:
            components["total"] = {**components["adults"], "value": computed, "basis": "sum_explicit_components", "references": refs,
                                   "available_at": max(components[k]["available_at"] for k in ("adults", "children")),
                                   "usable_at_prediction": all(components[k]["usable_at_prediction"] for k in ("adults", "children"))}
    ages = components["child_ages"]
    if ages["value"] is not None and children is not None and len(ages["value"]) != children:
        components["child_ages"] = {**ages, "status": "ambiguous", "value": None, "basis": "age_count_conflict"}
    current = choose(stages)
    semantic = []
    for i, row in enumerate(stages):
        refs = [{k: r[k] for k in ("source", "id", "at", "available_at")} for r in row["references"]]
        semantic.append({"id": "rules:"+row["references"][-1]["id"]+":"+str(i), "subject": scope,
                         "kind": row["value"], "at": max(r["at"] for r in refs),
                         "available_at": row["available_at"], "confidence": "high", "references": refs})
    observation = {"subject": scope, "context_from": case["context_from"], "prediction_at": cutoff,
                   "observed_through": max(cutoff, extracted), "prefix_complete": case["prefix_complete"],
                   "followup_complete": False, "evidence": canonical+semantic, "advertising_entry": None}
    built = base.build_observation(observation)
    assumptions = []
    if assumption_refs and adults is None and children is None and components["total"]["value"] == 2:
        assumptions.append(fact({"adults": 2, "children": 0}, assumption_refs[-1], extracted, cutoff,
                                basis="agency_working_assumption_not_customer_composition"))
    return {"subject": scope, "brand_key": case["brand_key"], "context_from": case["context_from"],
            "prediction_at": cutoff, "source_complete_through": case["source_complete_through"],
            "prefix_complete": case["prefix_complete"], "extraction_version": VERSION,
            "extracted_at": extracted, "extractor_sha256": RULE_SHA,
            "fields": result_fields, "party": components, "working_assumptions": assumptions,
            "manager_quotes": offered, "current_stage": current,
            "customer_requirements": requirements, "observation": built,
            "observation_input": observation, "purchase_anywhere_probability": None,
            "purchase_with_us_probability": None, "expected_commission_rub": None,
            "expected_value_rub": None, "export_allowed": False}


def extract_document(value):
    base.fields(value, ("schema_version", "extracted_at", "cases"))
    base.require(type(value["schema_version"]) is int and value["schema_version"] == 1, "unsupported_schema")
    base.moment(value["extracted_at"])
    base.require(isinstance(value["cases"], list) and len(value["cases"]) <= MAX_CASES, "case_limit")
    profiles = [extract_case(c, value["extracted_at"]) for c in value["cases"]]
    base.require(len({p["observation"]["observation_id"] for p in profiles}) == len(profiles), "duplicate_observation")
    return {"schema_version": 1, "mode": "shadow_only", "extraction_version": VERSION,
            "extractor_sha256": RULE_SHA, "probability": None, "export_allowed": False, "profiles": profiles}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("input", nargs="?", default="-")
    args = parser.parse_args()
    try:
        if args.input == "-":
            raw = sys.stdin.buffer.read(MAX_BYTES+1)
        else:
            with open(args.input, "rb") as handle:
                raw = handle.read(MAX_BYTES+1)
        base.require(len(raw) <= MAX_BYTES, "input_byte_limit")
        result = extract_document(json.loads(raw.decode("utf-8"), object_pairs_hook=base.unique_object))
    except (base.InvalidObservation, OSError, UnicodeDecodeError, json.JSONDecodeError, RecursionError) as exc:
        reason = str(exc) if isinstance(exc, base.InvalidObservation) else "input_unavailable_or_invalid_json"
        print(json.dumps({"ok": False, "error": reason}), file=sys.stderr)
        return 2
    print(json.dumps(result, sort_keys=True, ensure_ascii=True, separators=(",", ":")))
    return 0


if __name__ == "__main__":
    sys.exit(main())
