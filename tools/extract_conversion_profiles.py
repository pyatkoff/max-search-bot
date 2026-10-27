#!/usr/bin/env python3
"""Conservative offline Russian trip profiles, with source spans; no model/API calls."""

import argparse
import hashlib
import json
import re
import sys
from datetime import date

import build_conversion_observations as base

VERSION = "ru_trip_prefix_rules_v1_13"
MAX_BYTES = 2 * 1024 * 1024
MAX_CASES = 100
MAX_MESSAGES = 500
MAX_TEXT = 12000
with open(__file__, "rb") as _source:
    RULE_SHA = hashlib.sha256(_source.read()).hexdigest()

NUM = r"(?:\d{1,3}(?:[ \u00a0\u202f]\d{3})+|\d{1,10})(?:[.,]\d+)?"
MONEY = re.compile(rf"(?<![\w.,])(?P<lo>{NUM})(?:\s*[-–]\s*(?P<hi>{NUM}))?\s*"
                   r"(?P<scale>тыс(?:яч\w*)?\.?|т\.\s*р\.|[кk])?\s*"
                   r"(?P<currency>руб\w*|₽|rub|eur|евро|usd|доллар\w*|\$)?(?!\w|[.,]\d)", re.I)
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


def current_destinations(text):
    """Keep country mentions in current clauses; preserve raw history in evidence.

    A past-trip clause ends at punctuation or an explicit current-intent marker.
    Inspect occurrences, not just the first match: the same country may be both
    a past holiday and an explicitly requested current alternative.
    """
    dash_boundary = (r"\s+[—–-]\s+(?="
                     r"(?:(?:(?:а|но)\s+)?(?:(?:мы|я)\s+)?(?:(?:сейчас|теперь|в этом году)\s+)?"
                     r"(?:хотим|хочу|рассматриваем|рассматриваю|выбираем|выбираю|планируем|планирую|интересует)\b|"
                     r"(?:(?:мы|я)\s+)?(?:в прошлом году|прошл\w*\s+(?:летом|зимой|весной|осенью)|"
                     r"раньше|ранее|отдыхал[аи]?|ездил[аи]?|были(?!\s+бы\b)|побывали|посещали)\b))")
    clauses = re.split(r"[.!?;,\n]+|" + dash_boundary + r"|\b(?=сейчас\b|теперь\b|"
                       r"(?:а|и)\s+(?:хотим|хочу|рассматриваем|выбираем|планируем)\b)", text, flags=re.I)
    accepted = set()
    for clause in clauses:
        historical = affirmed(r"\b(?:в прошлом году|прошл\w*\s+(?:летом|зимой|весной|осенью)|раньше|ранее|"
                              r"отдыхал[аи]?|ездил[аи]?|были(?!\s+бы\b)|побывали|посещали)\b", clause)
        current = text_match(r"\b(?:хотим|хочу|рассматриваем|выбираем|планируем)\b", clause)
        past_time = text_match(r"\b(?:в прошлом году|прошл\w*\s+(?:летом|зимой|весной|осенью)|раньше|ранее)\b", clause)
        if historical and (not current or current.start() > historical.start()
                           or (past_time and past_time.start() < current.start())):
            continue
        for name, pattern in COUNTRIES.items():
            if any(not negated(clause, m.start()) for m in re.finditer(rf"\b(?:{pattern})\b", clause, re.I)):
                accepted.add(name)
    return [name for name in COUNTRIES if name in accepted]


def reference(message, span=None):
    return {"source": message.get("source", "message"), "id": message["id"], "speaker": message["speaker"],
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


def whole_currency_units(raw, scale):
    """Scale decimal text exactly, without floats or decimal-context rounding."""
    whole, _, fraction = re.sub(r"\s", "", raw).replace(",", ".").partition(".")
    whole = whole.lstrip("0") or "0"
    fraction = fraction.rstrip("0")
    places = 3 if scale == 1000 else 0
    if len(whole) > 10 or len(fraction) > places:
        return None
    return int(whole) * scale + (int(fraction.ljust(places, "0")) if fraction else 0)


def monetary(text, customer):
    """Only explicit amounts; no thousands inferred from a bare number or trip price."""
    results = []
    for match in MONEY.finditer(text):
        # The integer-valued budget schema can retain fractional thousands only
        # when scaling yields whole currency units. Never parse their suffix as
        # a different amount, or silently label an explicit foreign code RUB.
        if not match["currency"] and re.match(r"\s*[A-Za-z]{3}\b", text[match.end():]):
            continue
        scale = 1000 if match["scale"] else 1
        amounts = [whole_currency_units(v, scale)
                   for v in (match["lo"], match["hi"] or match["lo"])]
        if not all(v is not None and 0 < v <= 1000000000 for v in amounts):
            continue
        low, high = amounts
        if low > high:
            continue
        nearby = text[max(0, match.start()-24):min(len(text), match.end()+65)]
        context = bool(text_match(r"бюджет|лимит|потрат|улож|рассчитываем|не более|\bдо\s*$", text[max(0, match.start()-30):match.start()]))
        if not (match["currency"] or match["scale"] or customer and context and low >= 1000):
            continue
        if customer and not context and text_match(r"стоимост|цен[ауы]|стоит|предложили|прислали|купил|оплатил|забронировал", text):
            continue
        if negated(text, match.start()) or customer and "?" in text:
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


def duration_constraint(text):
    """Keep explicit alternatives discrete; never accept a numeric suffix."""
    match = text_match(r"(?<![\w.,+–-])(\d{1,2}(?:\s*(?:,|или|[-–])\s*\d{1,2})*)\s*"
                       r"(ноч\w*|дней|дня|день)\b", text)
    if match:
        raw = match[1]
        values = [int(v) for v in re.findall(r"\d+", raw)]
        if negated(text, match.start()) or not all(1 <= v <= 90 for v in values):
            return None
        unit = "nights" if match[2].lower().startswith("ноч") else "days"
        if "-" in raw or "–" in raw:
            if len(values) != 2 or "," in raw or "или" in raw.lower() or values[0] > values[1]:
                return None
            return {"minimum": values[0], "maximum": values[1], "unit": unit}, match.span()
        # An unspaced decimal-looking comma cannot establish integer choices.
        if re.search(r"\d,\d", raw):
            return None
        value = {"minimum": min(values), "maximum": max(values), "unit": unit}
        if len(values) > 1:
            value["options"] = sorted(set(values))
        return value, match.span()
    reverse = text_match(r"\b(дней|дня|ночей)\s+на\s+(\d{1,2})(?!\d)\b", text)
    if reverse and not negated(text, reverse.start()) and 1 <= int(reverse[2]) <= 90:
        return {"minimum": int(reverse[2]), "maximum": int(reverse[2]),
                "unit": "nights" if reverse[1].lower().startswith("ноч") else "days"}, reverse.span()
    return None


def direct_flight_requirement(text):
    """An explicit customer requirement, never a question or offered flight."""
    for clause in re.split(r"[.;!\n]", text):
        if text_match(r"\?|\bесли\b|"
                      r"предложил\w*|предлага\w*|написал\w*|сообщил\w*|летали|прошл\w*\s+(?:год|месяц)|"
                      r"не\s+(?:хочу|хотим|подход\w*|устраив\w*)", clause):
            continue
        match = affirmed(r"\bпрям(?:ой|ые|ым|ыми)\s+рейс\w*\b|\bбез\s+пересад(?:ок|ки)\b", clause)
        if match:
            start = text.index(clause)
            qualifier = r"не\s+(?:обязател\w*|обязательно|нуж\w*|важ\w*|принципиальн\w*)"
            optional = (text_match(qualifier+r"[\s,:]*$", clause[:match.start()])
                        or text_match(r"^\s*"+qualifier, clause[match.end():]))
            return ("direct_not_required" if optional else "direct_only"), (start, start+len(clause))
    return None


def requirement_clause(text):
    """A bounded explicit requirement, not a question, past trip or quoted offer."""
    return not text_match(r"\?|\bесли\b|\bне\s+(?:хочу|хотим|надо|поед\w*)|"
                          r"не\s+(?:подход\w*|устраив\w*|обязател\w*|рассматрива\w*)|"
                          r"предложил\w*|предлага\w*|написал\w*|сообщил\w*|"
                          r"прошл\w*\s+(?:год|месяц)|отдыхали|ездили|летали|жили|\bбыли\b", text)


def month_only_constraint(text):
    """A named month never supplies a day, implicit year or exact alternative."""
    month_words = {r"январ[ьяею]": 1, r"феврал[ьяею]": 2, r"март[аеу]?": 3,
                   r"апрел[ьяею]": 4, r"ма[йяею]": 5, r"июн[ьяею]": 6,
                   r"июл[ьяею]": 7, r"август[аеу]?": 8, r"сентябр[ьяею]": 9,
                   r"октябр[ьяею]": 10, r"ноябр[ьяею]": 11, r"декабр[ьяею]": 12}
    candidates = []
    for word, month in month_words.items():
        for match in re.finditer(rf"\b{word}\b(?:\s+(20\d{{2}})(?!\d)(?:\s*года?)?)?", text, re.I):
            before = text[:match.start()]
            if not match[1] and re.match(r"\s+\d{3,}", text[match.end():]):
                continue
            # Invalid/unsupported day syntax must not silently degrade to a month.
            if re.search(r"\d\s*(?:-?(?:го|ого))?\s*$", before, re.I):
                continue
            start = max(before.rfind(sep) for sep in ".;!\n")+1
            end = min((text.find(sep, match.end()) for sep in ".;!\n" if text.find(sep, match.end()) >= 0),
                      default=len(text))
            clause = text[start:end]
            if (negated(text, match.start())
                    or text_match(r"\b(?:не\s+(?:в|на)|с|от|до|после|раньше|позже|не ранее|не позднее)\s*$", before)
                    or not requirement_clause(clause)):
                continue
            candidates.append(({"start_day": None, "start_month": month, "end_day": None,
                                "end_month": month, "year": int(match[1]) if match[1] else None,
                                "window_kind": "departure" if text_match(r"вылет", clause) else "unspecified",
                                "precision": "month"}, match.span()))
    return candidates[0] if len(candidates) == 1 else None


def hotel_stars_constraint(text):
    """Preserve class ranges versus explicit alternatives; never parse a suffix."""
    pattern = (r"(?<![\w.,+*/–-])(?P<values>\d{1,2}\s*(?:\*\s*)?"
               r"(?:\s*(?:[-–/,]|или)\s*\d{1,2}\s*(?:\*\s*)?)*)"
               r"\s*(?P<unit>\*|зв[её]зд\w*)(?!\d|[.,]\d)")
    for match in re.finditer(pattern, text, re.I):
        before = text[:match.start()]
        start = max(before.rfind(sep) for sep in ".;!\n")+1
        end = min((text.find(sep, match.end()) for sep in ".;!\n" if text.find(sep, match.end()) >= 0),
                  default=len(text))
        if not requirement_clause(text[start:end]) or negated(text, match.start()):
            continue
        raw = match["values"]; values = [int(v) for v in re.findall(r"\d+", raw)]
        if (not all(1 <= v <= 5 for v in values) or re.search(r"\d,\d", raw)
                or re.match(r"\s*\d", text[match.end():])):
            continue
        is_range = bool(re.search(r"[-–]", raw))
        if is_range and (len(values) != 2 or values[0] > values[1] or re.search(r"[,/]|или", raw, re.I)):
            continue
        value = {"minimum": min(values), "maximum": max(values)}
        if len(values) > 1 and not is_range:
            value["options"] = sorted(set(values))
        if len(values) == 1:
            lower = re.search(r"\b(?:не ниже|от)\s*$", before, re.I)
            upper = re.search(r"\b(?:не выше|до)\s*$", before, re.I)
            if lower:
                value["maximum"] = None
            if upper:
                value["minimum"] = None
            return value, (lower.start() if lower else upper.start() if upper else match.start(), match.end())
        return value, match.span()
    return None


def leisure_preferences(text):
    """Explicit travel preferences, never local weather facts or implied nightlife."""
    result = []
    for clause in re.finditer(r"[^.;!\n]+", text):
        part = clause[0]
        if text_match(r"\?|\bесли\b|предлож\w*|написал\w*|рассказал\w*|сообщил\w*|"
                      r"прошл\w*\s+(?:год|месяц)|купались|плавали|гуляли|отдыхали|ездили|\bбыли\b", part):
            continue

        def add(name, value, match):
            # The qualifier is part of the evidence, not just the matched noun.
            result.append((name, value, clause.span()))

        def qualifier(match):
            # A qualifier of another comma-separated preference cannot remove this one.
            before = re.split(r"[,;]", part[:match.start()])[-1][-45:]
            after = re.split(r"[,;]", part[match.end():])[0][:45]
            optional = r"(?:не\s*(?:важ\w*|принципиальн\w*|обязател\w*)|без\s+разницы)"
            if text_match(optional+r"\s*$", before) or text_match(r"^\s*"+optional, after):
                return "unrestricted"
            if text_match(r"не\s+(?:хоч\w*|планиру\w*|нуж\w*)[^,;]*$", before) or text_match(
                    r"^\s*(?:больше\s+)?не\s+(?:хоч\w*|планиру\w*|нуж\w*)", after):
                return "not_desired"
            return None

        monsoon = text_match(r"\bмуссон\w*", part)
        if monsoon:
            optional = qualifier(monsoon)
            aversion = text_match(r"(?:без|избежать|избегать|вне\s+сезона|где\s+нет)\s+(?:сезона\s+)?$", part[:monsoon.start()])
            if optional == "unrestricted":
                add("weather_preference", "unrestricted", monsoon)
            elif aversion or text_match(r"не\s+(?:хоч\w*|нуж\w*)[^,;]*$", part[:monsoon.start()]):
                add("weather_preference", "avoid_monsoon", monsoon)
        swimming = text_match(r"\b(?:купа(?:ться|ни\w*)|плава(?:ть|ни\w*))\b", part)
        if swimming:
            add("swimming_preference", qualifier(swimming) or "desired", swimming)
        evening = text_match(r"\bвечер\w*", part)
        if evening:
            walking = text_match(r"\b(?:гулять|погулять|прогул\w*|пройтись)\b", part)
            if walking:
                add("evening_walks_preference", qualifier(walking) or "desired", walking)
            environment = text_match(r"\b(?:оживлен\w*|оживлён\w*|нескуч\w*|не\s+скуч\w*|весел\w*|весёл\w*|тих\w*|спокойн\w*)", part)
            if environment:
                desired = "quiet" if text_match(r"тих|спокойн", environment[0]) else "lively"
                restriction = qualifier(environment)
                add("evening_environment_preference", restriction or desired, environment)
    return result


def date_constraints(text):
    """Keep window meaning/year unresolved unless explicitly supplied."""
    results = []
    def qualified(value, start, end, is_range):
        bound = re.search(r"(?<!\w)(не\s+ранее|не\s+позднее|после|раньше|до|от|с|по|на)\s*$",
                          text[:start], re.I)
        if not bound:
            return value, (start, end)
        qualifier = re.sub(r"\s+", " ", bound[1].lower())
        if not is_range and qualifier != "на":
            upper = qualifier in {"до", "по", "раньше", "не позднее"}
            suffix = re.match(r"\s*включ(?:ительно|ено)\b", text[end:], re.I)
            inclusive = False if qualifier in {"после", "раньше"} else None if qualifier == "до" else True
            if suffix:
                inclusive = True
                end += suffix.end()
            value.update(start_day=None if upper else value["start_day"],
                         start_month=None if upper else value["start_month"],
                         end_day=value["end_day"] if upper else None,
                         end_month=value["end_month"] if upper else None,
                         start_inclusive=None if upper else inclusive,
                         end_inclusive=inclusive if upper else None)
        return value, (bound.start(), end)

    numeric = r"(?<![\w.])(?P<d>\d{1,2})\.(?P<m>\d{1,2})(?:\.(?P<y>20\d{2}))?\.?"
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
        results.append(qualified({"start_day": start, "start_month": month, "end_day": end,
                         "end_month": end_month, "year": year,
                         "window_kind": "departure" if text_match(r"дат\w* вылета|вылет", text) else "unspecified"},
                        first.start(), last.end(), len(matches) > 1))
        return results
    for word, month in MONTHS.items():
        ordinal = r"(?:\s*-?\s*(?:го|ого))?"
        match = re.search(rf"(?<![\w.-])(?P<d>\d{{1,2}}){ordinal}"
                          rf"(?:\s*[-–]\s*(?P<r>\d{{1,2}}){ordinal}|\s+по\s+(?P<r2>\d{{1,2}}){ordinal})?"
                          rf"\s*{word}\w*(?:\s*(?P<y>20\d{{2}}))?", text, re.I)
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
        results.append(qualified({"start_day": start, "start_month": month, "end_day": end,
                         "end_month": month, "year": year, "window_kind": "departure" if text_match(r"вылет", text) else "unspecified"},
                        match.start(), match.end()+ (tail.end() if tail else 0),
                        bool(match["r"] or match["r2"] or tail)))
        break
    if not results:
        month = month_only_constraint(text)
        if month:
            results.append(month)
    return results


def wizard_value(field, raw):
    """Project exact successful wizard storage; native IDs remain references, not guessed names."""
    if not isinstance(raw, str) or len(raw) > 160:
        return None
    if field in {"adults", "children"} and re.fullmatch(r"(?:0|[1-9][0-9]?)", raw):
        count = int(raw)
        if (1 if field == "adults" else 0) <= count <= 30:
            return field, count
    if field == "child_ages" and re.fullmatch(r"(?:0|[1-9][0-9]?)(?:, (?:0|[1-9][0-9]?))*", raw):
        ages = [int(v) for v in raw.split(', ')]
        if len(ages) <= 30 and all(v <= 17 for v in ages):
            return "child_ages", ages
    if field == "nights" and re.fullmatch(r"[1-9][0-9]?(?:-[1-9][0-9]?)?", raw):
        parts = [int(v) for v in raw.split('-')]
        if 1 <= parts[0] <= parts[-1] <= 90:
            return "duration", {"minimum": parts[0], "maximum": parts[-1], "unit": "nights"}
    if field == "date" and re.fullmatch(r"[0-9]{2}\.[0-9]{2}\.[0-9]{4}", raw):
        day, month, year = map(int, raw.split('.'))
        try:
            date(year, month, day)
        except ValueError:
            return None
        return "dates", {"start_day": day, "start_month": month, "end_day": day,
                         "end_month": month, "year": year, "window_kind": "departure"}
    directories = {"city": "departure_reference", "country": "destination_reference",
                   "meal": "meal_reference", "stars": "hotel_class_reference"}
    if field in directories and re.fullmatch(r"[1-9][0-9]{0,9}", raw):
        if field != "stars" or int(raw) <= 5:
            return directories[field], {"directory": "canonical_wizard_"+field, "id": raw,
                                       "label_status": "unresolved"}
    return None


def validate_case(case, applied_message_ids=()):
    base.fields(case, ("subject", "brand_key", "context_from", "prediction_at", "source_complete_through",
                       "prefix_complete", "messages"), ("source_fence_at", "trip_status"))
    base.require(isinstance(case.get("trip_status", "accepted"), str) and case.get("trip_status", "accepted") in {"accepted", "unknown"}, "invalid_trip_status")
    base.require(case.get("trip_status", "accepted") != "unknown" or case["prefix_complete"] is False,
                 "unresolved_trip_cannot_be_complete")
    scope = base.subject(case["subject"]); base.key(case["brand_key"])
    start, cutoff = map(base.moment, [case["context_from"], case["prediction_at"]])
    complete = base.moment(case["source_complete_through"]) if case["source_complete_through"] is not None else None
    base.require(complete is not None or "source_fence_at" in case, "source_fence_required")
    fence = base.moment(case.get("source_fence_at", case["prediction_at"]))
    base.require(start <= fence <= cutoff and (complete is None or start <= complete <= fence), "invalid_prefix_interval")
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
        if "source_fence_at" in case:
            base.require(base.moment(m["at"]) <= fence, "source_row_after_fence")
        base.require(m["id"] not in unique or unique[m["id"]] == m, "conflicting_message_id")
        unique[m["id"]] = m
    rows = sorted(unique.values(), key=lambda m: (m["at"], m["id"]))
    rows = [m for m in rows if case["context_from"] <= m["at"] <= case["prediction_at"]
            and m["available_at"] <= case["prediction_at"]
            and (m["kind"] == "text" or m["id"] in applied_message_ids)]
    return scope, rows


def prediction_features(case, extracted, fields, party, stage):
    """Admit known available facts independently of target-risk-set eligibility."""
    cutoff = base.moment(case["prediction_at"])
    fence = case.get("source_fence_at")
    candidates = {**{"fields."+k: v for k, v in fields.items()},
                  **{"party."+k: v for k, v in party.items()}, "observed_stage": stage}
    admitted = {}; unavailable = {}
    for path, row in sorted(candidates.items()):
        if row["status"] != "known":
            unavailable[path] = row["status"]
        elif not row["usable_at_prediction"]:
            unavailable[path] = "not_available_at_prediction"
        else:
            latest = max(base.moment(r["at"]) for r in row["references"])
            admitted[path] = {**row, "last_source_recorded_at": base.stamp(latest),
                              "seconds_since_source_record": int((cutoff-latest).total_seconds())}
    view = {"version": "available_trip_facts_v1", "prediction_at": case["prediction_at"],
            "semantic_available_at": extracted, "source_fence_at": fence,
            "source_complete_through": case["source_complete_through"],
            "source_gap_seconds": int((cutoff-base.moment(fence)).total_seconds()) if fence else None,
            "prefix_complete": case["prefix_complete"], "semantic_coverage": "partial_rules_only",
            "facts": admitted, "unavailable_facts": unavailable}
    view["trip_status"] = case.get("trip_status", "accepted")
    view["feature_scope"] = "current_trip" if view["trip_status"] == "accepted" else "unresolved_conversation_prefix"
    view["feature_fingerprint"] = base.digest({"subject": case["subject"], "brand_key": case["brand_key"],
                                             "context_from": case["context_from"], "extractor_sha256": RULE_SHA, "features": view})
    return view


def extract_case(case, extracted, wizard_applications=()):
    base.moment(extracted)
    scope, messages = validate_case(case, {a["message_id"] for a in wizard_applications})
    cutoff = case["prediction_at"]
    fields = {name: [] for name in ["budget", "budget_preference", "duration", "dates", "departure",
              "destination", "meal", "date_flexibility_days", "room_arrangement", "family_group",
              "hotel_interest", "unresolved_city", "purchase_report", "flight_requirement", "hotel_stars",
              "weather_preference", "swimming_preference", "evening_walks_preference", "evening_environment_preference",
              "departure_reference", "destination_reference", "meal_reference", "hotel_class_reference"]}
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
        if m["speaker"] == "customer":
            for application in wizard_applications:
                if application["message_id"] == m["id"]:
                    value = wizard_value(application["field"], application["storage_value"])
                    if value:
                        add(value[0], value[1], m, basis="canonical_wizard_application",
                            extra=[application["receipt"]])
        # A successfully applied button is still an action, not dialogue or booking intent.
        if m["kind"] == "action":
            if m["speaker"] == "customer":
                previous_manager = None
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
        for pattern, component in [(r"(?<![\d-])(\d{1,2})\s*(?:(?:человека?|чел\.?)\s+)?взросл\w*", "adults"),
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
        duration = duration_constraint(text)
        if duration:
            add("duration", duration[0], m, duration[1])
        flight = direct_flight_requirement(text)
        if flight:
            add("flight_requirement", flight[0], m, flight[1])
        stars = hotel_stars_constraint(text)
        if stars:
            add("hotel_stars", stars[0], m, stars[1])
        for name, preference, span in leisure_preferences(text):
            add(name, preference, m, span, "explicit_customer_preference_not_destination_fact")
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
        countries = current_destinations(text)
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
        # The immediately preceding proposal can ground only its first answer.
        # A refusal, unrelated answer or confirmation consumes that question.
        previous_manager = None

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
                         "available_at": row["available_at"], "confidence": "high" if case.get("trip_status", "accepted") == "accepted" else "medium", "references": refs})
    observation = {"subject": scope, "context_from": case["context_from"], "prediction_at": cutoff,
                   "observed_through": max(cutoff, extracted), "prefix_complete": case["prefix_complete"],
                   "followup_complete": False, "evidence": canonical+semantic, "advertising_entry": None,
                   "target_history_available_at": extracted}
    built = base.build_observation(observation)
    if case.get("trip_status", "accepted") == "unknown":
        built["eligible"] = False
        built["label"] = {**built["label"], "value": None, "reason": "trip_boundary_unresolved"}
    assumptions = []
    if assumption_refs and adults is None and children is None and components["total"]["value"] == 2:
        assumptions.append(fact({"adults": 2, "children": 0}, assumption_refs[-1], extracted, cutoff,
                                basis="agency_working_assumption_not_customer_composition"))
    return {"subject": scope, "brand_key": case["brand_key"], "context_from": case["context_from"],
            "trip_status": case.get("trip_status", "accepted"),
            "prediction_at": cutoff, "source_complete_through": case["source_complete_through"],
            "prefix_complete": case["prefix_complete"], "extraction_version": VERSION,
            "extracted_at": extracted, "extractor_sha256": RULE_SHA,
            "fields": result_fields, "party": components, "working_assumptions": assumptions,
            "manager_quotes": offered, "current_stage": current,
            "customer_requirements": requirements, "observation": built,
            "prediction_features": prediction_features(case, extracted, result_fields, components, current),
            "target_admission": {"target_version": base.TARGET, "eligible": built["eligible"],
                                 "reason": "complete_prefix_without_observed_target" if built["eligible"] else built["label"]["reason"]},
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
