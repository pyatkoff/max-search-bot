"""Offline candidate classifier; no network, storage, or production export."""
from dataclasses import dataclass

@dataclass(frozen=True)
class Evidence:
    meaningful_interest: bool = False
    criteria_families: int = 0
    concrete_offer_reaction: bool = False
    explicit_booking_intent: bool = False
    agency_choice: bool = False
    customer_continued: bool = False
    quoted_or_conditional: bool = False
    declined: bool = False
    context_ambiguous: bool = False

WEIGHTS = (0, 1, 3, 5, 8)

def classify(e: Evidence):
    if e.context_ambiguous or e.quoted_or_conditional or e.declined:
        return {'stage': 'unknown' if e.context_ambiguous else 'D0', 'weight': None if e.context_ambiguous else 0}
    level = 0
    if e.meaningful_interest: level = 1
    if level and e.criteria_families >= 2: level = 2
    if level and e.concrete_offer_reaction and e.customer_continued: level = 3
    if level and (e.explicit_booking_intent or e.agency_choice): level = 4
    return {'stage': f'D{level}', 'weight': WEIGHTS[level]}
