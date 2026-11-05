#!/usr/bin/env python3
"""Finite private retrospective study over derived archive atoms; no raw text or network."""
import collections
import hashlib
import json
import math
import os
import random
import re
import stat
import sys
import time

VERSION = "archive_predictive_study_v1"
TARGET = "observed_retained_payment_evidence_30d_v1"
FEATURES = ("continued_meaningful_engagement", "near_term_urgency", "long_range_planning",
            "deferral", "continued_offer_search", "office_question", "payment_terms_question")
STAGES = ("substantive_dialogue", "concrete_request", "exact_offer_discussion",
          "general_purchase_intent", "agency_purchase_intent")
PREFIXES = ("first_meaningful", "third_customer_turn_24h", "after_24h")
GOALS = ("substantive_interest", "general_purchase_intent", "agency_purchase_intent",
         "payment_self_report", "manager_payment_ack", "booking_confirmation",
         "purchase_supported_candidate", "payment_cancellation", "booking_cancellation",
         "refund_report", "purchased_elsewhere", "deferral", "office_question",
         "payment_terms_question", "documents_for_booking", "document_issuance")
PAYMENTS = frozenset(("payment_self_report", "manager_payment_ack"))
OUTCOMES = frozenset(("payment_self_report", "manager_payment_ack", "booking_confirmation",
                      "purchase_supported_candidate", "payment_cancellation", "booking_cancellation",
                      "refund_report", "purchased_elsewhere", "document_issuance"))
CONTRARY = frozenset(("payment_cancellation", "booking_cancellation", "refund_report", "purchased_elsewhere"))
BINDING_FLAGS = frozenset(("unresolved_trip_boundary", "party_change_unresolved",
                          "product_changed_or_alternative", "source_namespace_barrier"))
TEXT_BOOL = ("explicit_comparison", "explicit_modification", "price_alternative_continued_search",
             "near_term_urgency", "long_range_planning", "explicit_decline", "customer_text_nonempty")
PRODUCTS = ("unknown", "tour", "hotel", "flight", "addon", "mixed")
STATUSES = ("scanned", "source_unavailable", "unknown_role", "text_not_minimized", "text_unavailable",
            "text_too_large", "invalid_utf8", "invalid_source_ref", "forwarded", "unknown_forwarding")
PROTOCOL = {
    "version": VERSION, "target": TARGET, "horizon_seconds": 30 * 86400,
    "outcome_interval": "strictly_after_cutoff_and_before_horizon_end",
    "prefixes": list(PREFIXES), "features": list(FEATURES), "stage_names": list(STAGES),
    "assignment": "sha256_protocol_and_peer_group_80_20_v1",
    "evaluation_exposure": "retrospective_group_control_not_pristine_research_holdout",
    "learner": "fixed_common_stage_prefix_logistic_and_relative_l2_logistic",
    "l2": 0.05, "iterations": 200, "learning_rate": 0.25,
    "coefficient_shrinkage": 0.5, "minimum_feature_dev_groups": 15,
    "minimum_feature_positive_dev_groups": 5,
    "individual_odds_bounds": [0.5, 1.5], "global_odds_bounds": [0.25, 1.3],
    "explicit_decline_guard": 0.25, "minimum_dev_groups": 40, "minimum_control_groups": 40,
    "minimum_positive_dev_groups": 8, "minimum_positive_control_groups": 8,
    "bootstrap_replicates": 200, "bootstrap_seed": 314159,
    "candidate_admission": "held_control_point_logloss_better_old_and_not_worse_stage",
    "true_purchase_probability": None, "historical_availability": "unknown",
}
MAX_ATOMS = 500000
MAX_CASES = 30000
MAX_CASE_ATOMS = 100000
MAX_INPUT_BYTES = 1073741824
MAX_CHUNKS = 10000
MAX_PREFIXES = 100000
MAX_LINE_BYTES = 16384
MAX_SECONDS = 300
SHA = re.compile(r"\A[a-f0-9]{64}\Z")
REF = re.compile(r"\Aarchive_message:[1-9][0-9]{0,19}\Z")
DECIMAL = re.compile(r"\A(?:0|[1-9][0-9]{0,19})\Z")
ATOM_KEYS = frozenset(("type", "case_key", "group_key", "account_alias", "ref", "at", "version_at",
                       "record_sha256", "role", "status", "episode", "boundary", "product",
                       "goals", "flags", "text_features"))


class InvalidStudy(ValueError):
    def __init__(self, code):
        super().__init__(code)
        self.code = code


def require(ok, code):
    if not ok:
        raise InvalidStudy(code)


def encoded(value):
    return json.dumps(value, ensure_ascii=True, separators=(",", ":"), sort_keys=True, allow_nan=False).encode()


def digest(value):
    return hashlib.sha256(encoded(value)).hexdigest()


PROTOCOL_SHA = digest(PROTOCOL)


def unique_object(pairs):
    result = {}
    for key, value in pairs:
        require(key not in result, "duplicate_json_key")
        result[key] = value
    return result


def loads(raw):
    return json.loads(raw, object_pairs_hook=unique_object,
                      parse_constant=lambda unused: (_ for _ in ()).throw(InvalidStudy("nonfinite_json")))


def is_sha(value):
    return isinstance(value, str) and SHA.fullmatch(value) is not None


def valid_time(value):
    return type(value) is int and 946684800 <= value <= 253402300799


def validate_manifest(value):
    required = {"schema", "kind", "project_key", "run_id", "source_fence", "scope_digest",
                "extractor_sha256", "rule_revision", "text_features_sha256", "captured_at_unix",
                "complete", "chunks", "expected", "coverage"}
    require(isinstance(value, dict) and required <= set(value)
            and set(value) <= required | {"analyzer_sha256", "source_commit", "code_hashes", "chain_sha256"}, "manifest_shape")
    require(value["schema"] == 1 and type(value["schema"]) is int
            and value["kind"] == VERSION and value["project_key"] == "anytour"
            and value["rule_revision"] == 3 and type(value["rule_revision"]) is int
            and value["complete"] is True, "manifest_version_or_incomplete")
    require(isinstance(value["run_id"], str) and re.fullmatch(r"[A-Za-z0-9_-]{1,128}", value["run_id"])
            and isinstance(value["source_fence"], str) and DECIMAL.fullmatch(value["source_fence"])
            and is_sha(value["scope_digest"]) and is_sha(value["extractor_sha256"])
            and is_sha(value["text_features_sha256"]) and valid_time(value["captured_at_unix"]), "manifest_binding")
    if "analyzer_sha256" in value:
        require(is_sha(value["analyzer_sha256"]), "manifest_analyzer_hash")
    if "chain_sha256" in value:
        require(is_sha(value["chain_sha256"]), "manifest_chain_hash")
    if "source_commit" in value:
        require(isinstance(value["source_commit"], str) and re.fullmatch(r"[a-f0-9]{40}", value["source_commit"]), "manifest_source_commit")
    if "code_hashes" in value:
        require(isinstance(value["code_hashes"], dict) and len(value["code_hashes"]) <= 32
                and all(isinstance(k, str) and len(k) <= 180 and is_sha(v) for k, v in value["code_hashes"].items()), "manifest_code_hashes")
    require(isinstance(value["coverage"], dict) and len(encoded(value["coverage"])) <= 32768, "manifest_coverage")
    expected = value["expected"]
    require(isinstance(expected, dict) and set(expected) == {"desktop_records", "account_chat_pairs"}
            and type(expected["desktop_records"]) is int and 0 <= expected["desktop_records"] <= MAX_ATOMS
            and type(expected["account_chat_pairs"]) is int and 0 <= expected["account_chat_pairs"] <= MAX_CASES,
            "manifest_expected")
    chunks = value["chunks"]
    require(isinstance(chunks, list) and len(chunks) <= MAX_CHUNKS, "manifest_chunks")
    files = set()
    for chunk in chunks:
        require(isinstance(chunk, dict) and set(chunk) == {"file", "sha256", "records"}, "chunk_shape")
        name = chunk["file"]
        require(isinstance(name, str) and len(name) <= 180 and "\\" not in name
                and not name.startswith("/") and all(re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]{0,127}", p)
                                                     and p not in (".", "..") for p in name.split("/"))
                and 1 <= len(name.split("/")) <= 4 and name not in files, "chunk_path")
        require(is_sha(chunk["sha256"]) and type(chunk["records"]) is int
                and 0 <= chunk["records"] <= MAX_ATOMS, "chunk_binding")
        files.add(name)
    require(sum(c["records"] for c in chunks) == source_record_total(value), "chunk_record_total")
    return value


def source_record_total(manifest):
    counts = manifest["coverage"].get("source_counts")
    if counts is None:
        return manifest["expected"]["desktop_records"]
    require(isinstance(counts, dict) and {"records", "desktop_import", "business_message"} <= set(counts)
            and all(type(counts[k]) is int and 0 <= counts[k] <= MAX_ATOMS
                    for k in ("records", "desktop_import", "business_message"))
            and counts["desktop_import"] == manifest["expected"]["desktop_records"]
            and counts["records"] == counts["desktop_import"] + counts["business_message"],
            "manifest_source_counts")
    return counts["records"]


def validate_atom(value):
    require(isinstance(value, dict) and set(value) == ATOM_KEYS, "atom_shape")
    require(value["type"] == "message" and is_sha(value["case_key"]) and is_sha(value["group_key"])
            and isinstance(value["account_alias"], str) and re.fullmatch(r"A[1-9][0-9]{0,3}", value["account_alias"])
            and isinstance(value["ref"], str) and REF.fullmatch(value["ref"]), "atom_identity")
    for name in ("at", "version_at"):
        require(value[name] is None or valid_time(value[name]), "atom_clock_shape")
    require(value["record_sha256"] is None or is_sha(value["record_sha256"]), "atom_source_hash")
    require(value["role"] in ("customer", "manager", "unknown") and value["status"] in STATUSES
            and type(value["episode"]) is int and 0 <= value["episode"] <= MAX_ATOMS
            and value["product"] in PRODUCTS, "atom_metadata")
    require(value["boundary"] is None or (isinstance(value["boundary"], str)
            and re.fullmatch(r"[a-z_]{1,64}", value["boundary"])), "atom_boundary")
    goals = value["goals"]
    require(isinstance(goals, list) and len(goals) <= len(GOALS) and all(isinstance(g, str) and g in GOALS for g in goals)
            and len(goals) == len(set(goals)), "atom_goals")
    require(value["status"] == "scanned" or not goals, "unscanned_atom_has_goals")
    require("payment_self_report" not in goals or value["role"] == "customer", "payment_actor")
    require("manager_payment_ack" not in goals or value["role"] == "manager", "payment_actor")
    flags = value["flags"]
    require(isinstance(flags, dict) and len(flags) <= 48
            and all(isinstance(k, str) and re.fullmatch(r"[a-z_]{1,64}", k) and type(v) is bool
                    for k, v in flags.items()), "atom_flags")
    features = value["text_features"]
    require(isinstance(features, dict) and set(features) == {"criteria_families", *TEXT_BOOL}
            and all(type(features[k]) is bool for k in TEXT_BOOL), "atom_text_features")
    families = features["criteria_families"]
    require(isinstance(families, list) and len(families) <= 5
            and all(isinstance(v, str) and v in ("destination", "dates", "party", "budget", "requirements") for v in families)
            and len(families) == len(set(families)),
            "atom_criteria")
    return value


def assignment(group):
    value = hashlib.sha256((PROTOCOL_SHA + "\n" + group).encode()).digest()
    return "development" if int.from_bytes(value[:8], "big") % 5 else "control"


def atom_problem(atom, cutoff=None):
    if atom["status"] != "scanned" or atom["role"] == "unknown":
        return "source_or_role_unknown"
    if atom["at"] is None or atom["version_at"] is None or atom["record_sha256"] is None:
        return "clock_or_version_unknown"
    if atom["flags"].get("reversed_clock") or atom["flags"].get("unknown_time"):
        return "clock_unknown_or_reversed"
    if atom["version_at"] < atom["at"]:
        return "version_before_occurrence"
    if cutoff is not None and atom["at"] <= cutoff < atom["version_at"]:
        return "version_after_cutoff"
    return None


def meaningful(atom, anchored):
    if atom["role"] != "customer" or atom["status"] != "scanned":
        return False
    goals = set(atom["goals"])
    f = atom["text_features"]
    safe = not any(atom["flags"].get(k) for k in ("quoted_or_relayed", "past_trip", "conditional", "negation"))
    direct_intent = bool(goals & {"general_purchase_intent", "agency_purchase_intent"})
    return direct_intent or (safe and (bool(goals & {"substantive_interest"})
        or len(f["criteria_families"]) >= 2
        or (anchored and (bool(f["criteria_families"]) or f["explicit_comparison"]
                          or f["explicit_modification"] or f["price_alternative_continued_search"]))))


def new_feature_state():
    return {"turns": 0, "families": set(), "positive": set(), "current": {f: False for f in FEATURES},
            "declined": False, "product": "unknown"}


def advance_features(state, atom):
    if atom["product"] != "unknown":
        state["product"] = atom["product"]
    goals = set(atom["goals"])
    f = atom["text_features"]
    current = state["current"]
    direct_customer = atom["role"] == "customer" and not any(atom["flags"].get(k)
        for k in ("quoted_or_relayed", "past_trip", "conditional", "negation"))
    if meaningful(atom, state["turns"] > 0):
        state["turns"] += 1
        if direct_customer:
            state["families"].update(f["criteria_families"])
        state["positive"].update(goals & {"substantive_interest", "general_purchase_intent", "agency_purchase_intent"})
        if direct_customer:
            current["continued_offer_search"] |= f["explicit_comparison"] or f["explicit_modification"] or f["price_alternative_continued_search"]
        if direct_customer and (f["near_term_urgency"] or f["long_range_planning"]):
            current["near_term_urgency"] = f["near_term_urgency"] and not f["long_range_planning"]
            current["long_range_planning"] = f["long_range_planning"]
    if "deferral" in goals:
        current["deferral"] = True
    if goals & {"general_purchase_intent", "agency_purchase_intent"}:
        current["deferral"] = False
        state["declined"] = False
    if atom["role"] == "customer" and f["explicit_decline"] and not any(atom["flags"].get(k)
            for k in ("question", "conditional", "quoted_or_relayed", "past_trip")):
        state["declined"] = True
    if atom["role"] == "customer" and state["turns"] > 0 and not any(atom["flags"].get(k)
            for k in ("conditional", "quoted_or_relayed", "past_trip")):
        current["office_question"] |= "office_question" in goals
        current["payment_terms_question"] |= "payment_terms_question" in goals
    current["continued_meaningful_engagement"] = state["turns"] >= 2


def feature_result(state):
    stage = "concrete_request" if len(state["families"]) >= 2 else "substantive_dialogue"
    if "general_purchase_intent" in state["positive"]:
        stage = "general_purchase_intent"
    if "agency_purchase_intent" in state["positive"]:
        stage = "agency_purchase_intent"
    return stage, [int(state["current"][name]) for name in FEATURES], state["declined"], state["product"], state["turns"]


def prefix_features(atoms):
    state = new_feature_state()
    for atom in atoms:
        advance_features(state, atom)
    return feature_result(state)


def make_prefix(case_atoms, episode_atoms, kind, cutoff, end_index, captured):
    selected = [(i, a) for i, a in episode_atoms if i <= end_index and a["at"] is not None and a["at"] <= cutoff]
    source = [a for _, a in selected]
    stage, x, declined, product, turns = prefix_features(source)
    row = {
        "schema": 1, "target": TARGET, "case_key": case_atoms[0]["case_key"],
        "group_key": case_atoms[0]["group_key"], "account_alias": case_atoms[0]["account_alias"],
        "episode": episode_atoms[0][1]["episode"], "prefix_kind": kind, "cutoff_at_unix": cutoff,
        "stage": stage, "features": dict(zip(FEATURES, x)), "explicit_decline": declined,
        "product": product, "historical_availability": "unknown", "true_purchase_label": None,
        "assignment": assignment(case_atoms[0]["group_key"]), "eligible": False,
        "exclusion_reason": None, "label": None, "label_reason": "unknown",
        "outcome_refs": [], "feature_source_refs": [a["ref"] for a in source[-32:]],
        "feature_source_count": len(source), "feature_refs_truncated": len(source) > 32,
    }
    row["observation_id"] = digest({k: row[k] for k in ("case_key", "episode", "prefix_kind", "cutoff_at_unix")})
    row["feature_fingerprint"] = digest({"cutoff": cutoff, "source": [
        [a["ref"], a["record_sha256"], a["at"], a["version_at"]] for a in source],
        "stage": stage, "features": row["features"], "decline": declined})
    def excluded(reason):
        row["exclusion_reason"] = reason
        row["label_reason"] = reason
        return row
    if not source or not turns:
        return excluded("meaningful_prefix_unavailable")
    if any(a["at"] is None for i, a in episode_atoms if i <= end_index):
        return excluded("prefix_clock_unknown")
    for _, atom in selected:
        reason = atom_problem(atom, cutoff)
        if reason:
            return excluded(reason)
        if atom["at"] > captured:
            return excluded("future_source_clock")
    if any(a["flags"].get(k) for _, a in selected for k in BINDING_FLAGS):
        return excluded("trip_binding_unknown")
    if product != "tour":
        return excluded("other_or_unknown_prefix_product")
    if any(set(a["goals"]) & OUTCOMES for _, a in selected):
        return excluded("outcome_already_known")
    episode_start = episode_atoms[0][0]
    unresolved = [i for i, a in enumerate(case_atoms) if i < episode_start
                  and a["episode"] == 0 and set(a["goals"]) & OUTCOMES]
    if unresolved:
        last_unresolved = max(unresolved)
        explicit_new_trip = any(a["boundary"] == "explicit_new_trip_candidate"
            and atom_problem(a, cutoff) is None and a["at"] is not None and a["at"] <= cutoff
            for i, a in enumerate(case_atoms) if last_unresolved < i <= episode_start)
        if not explicit_new_trip:
            return excluded("prior_unresolved_outcome")
    # A current retained message with an earlier timestamp cannot sneak behind a fixed native-order prefix.
    if any(a["at"] is not None and a["at"] <= cutoff for i, a in episode_atoms if i > end_index):
        return excluded("reversed_or_equal_source_order")
    row["eligible"] = True
    end = cutoff + PROTOCOL["horizon_seconds"]
    future = [(i, a) for i, a in episode_atoms if i > end_index
              and (a["at"] is None or cutoff < a["at"] < end)]
    payments = [a for _, a in future if set(a["goals"]) & PAYMENTS]
    if any(atom_problem(a) or (a["at"] is not None and a["at"] > captured)
           or (a["version_at"] is not None and a["version_at"] > captured) for _, a in future):
        row["label_reason"] = "followup_clock_or_source_unknown"
    elif any(a["flags"].get(k) for _, a in future for k in BINDING_FLAGS):
        row["label_reason"] = "followup_trip_binding_unknown"
    elif any(a["version_at"] > a["at"] for a in payments):
        row["label_reason"] = "edited_payment_source"
    elif any(a["product"] != "tour" or a["flags"].get("other_payment_product") for a in payments):
        row["label_reason"] = "other_or_unknown_payment_product"
    elif any(set(a["goals"]) & CONTRARY for _, a in future):
        row["label_reason"] = "contrary_outcome_observed"
    elif payments:
        first = min(a["at"] for a in payments)
        row["label"] = 1
        row["label_reason"] = "future_retained_payment_evidence"
        row["outcome_refs"] = [a["ref"] for a in payments if a["at"] == first]
    elif captured < end:
        row["label_reason"] = "horizon_not_mature"
    else:
        row["label"] = 0
        row["label_reason"] = "no_payment_evidence_in_retained_archive_window_not_no_purchase"
    return row


def process_case(atoms, captured):
    by_episode = collections.OrderedDict()
    for i, atom in enumerate(atoms):
        if atom["episode"] > 0:
            by_episode.setdefault(atom["episode"], []).append((i, atom))
    rows = []
    for pairs in by_episode.values():
        first = next(((i, a) for i, a in pairs if meaningful(a, False) and a["at"] is not None), None)
        if first is None:
            continue
        start_index, anchor = first
        cutoff = anchor["at"]
        specs = [("first_meaningful", cutoff, start_index)]
        turns = [(i, a) for i, a in pairs if i >= start_index and a["role"] == "customer"
                 and a["status"] == "scanned" and a["text_features"]["customer_text_nonempty"]
                 and a["at"] is not None and cutoff <= a["at"] <= cutoff + 86400]
        if len(turns) >= 3:
            specs.append(("third_customer_turn_24h", turns[2][1]["at"], turns[2][0]))
        if cutoff + 86400 <= captured:
            eligible_indices = [i for i, a in pairs if a["at"] is not None and a["at"] <= cutoff + 86400]
            specs.append(("after_24h", cutoff + 86400, max(eligible_indices, default=start_index)))
        for kind, at, end_index in specs:
            rows.append(make_prefix(atoms, pairs, kind, at, end_index, captured))
    payments = [(i, a) for i, a in enumerate(atoms) if set(a["goals"]) & PAYMENTS]
    audit = None
    if payments:
        timed = [(i, a) for i, a in payments if a["at"] is not None]
        unknown_clock = any(a["at"] is None for _, a in payments)
        first_time = min((a["at"] for _, a in timed), default=None)
        first = [(i, a) for i, a in timed if a["at"] == first_time]
        first_index = min((i for i, _ in first), default=len(atoms))
        first_episodes = {a["episode"] for _, a in first}
        payment_episode = next(iter(first_episodes)) if len(first_episodes) == 1 else None
        before = [a for i, a in enumerate(atoms) if i < first_index and a["at"] is not None
                  and first_time is not None and a["at"] < first_time and a["episode"] == payment_episode]
        signal_first = {}
        audit_state = new_feature_state()
        for a in before:
            advance_features(audit_state, a)
            audit_stage, x, audit_decline, _, _ = feature_result(audit_state)
            for feature, present in zip(FEATURES, x):
                if present:
                    signal_first.setdefault(feature, a["at"])
            if audit_decline:
                signal_first.setdefault("explicit_decline", a["at"])
            if len(audit_state["families"]) >= 2:
                signal_first.setdefault("concrete_request", a["at"])
            for goal in ("substantive_interest", "general_purchase_intent", "agency_purchase_intent"):
                if goal in a["goals"]:
                    signal_first.setdefault(goal, a["at"])
        audit_exclusions = set()
        if unknown_clock:
            audit_exclusions.add("payment_clock_unknown")
        if payment_episode is None or payment_episode == 0:
            audit_exclusions.add("trip_binding_unknown")
        if any(a["product"] != "tour" or a["flags"].get("other_payment_product") for _, a in first):
            audit_exclusions.add("other_or_unknown_payment_product")
        if any(a["at"] is None or a["version_at"] is None or a["version_at"] > a["at"] for _, a in first):
            audit_exclusions.add("edited_or_unknown_payment_version")
        if any(atom_problem(a) or any(a["flags"].get(k) for k in BINDING_FLAGS) for a in before):
            audit_exclusions.add("prepayment_context_uncertain")
        first_source_uncertain = any(atom_problem(a) or a["at"] > captured or
            a["version_at"] > captured for _, a in first)
        if first_source_uncertain:
            audit_exclusions.add("first_payment_clock_or_source_uncertain")
        late_input_version = any(a["version_at"] is None or
            (first_time is not None and a["version_at"] > first_time) for a in before)
        if late_input_version:
            audit_exclusions.add("prepayment_version_after_payment")
        lag_eligible = not unknown_clock and not first_source_uncertain and not audit_exclusions
        audit = {
            "schema": 1, "case_key": atoms[0]["case_key"], "group_key": atoms[0]["group_key"],
            "account_alias": atoms[0]["account_alias"], "payment_candidate": True,
            "label_basis": "canonical_rule_candidate_not_bank_settlement",
            "first_payment_created_at_unix": None if unknown_clock else first_time,
            "known_payment_created_at_unix": first_time,
            "first_payment_refs": [a["ref"] for _, a in first],
            "first_payment_kinds": sorted({g for _, a in first for g in a["goals"] if g in PAYMENTS}),
            "first_payment_episode": payment_episode, "audit_exclusions": sorted(audit_exclusions),
            "payment_clock_unknown": unknown_clock, "first_payment_source_uncertain": bool(first_source_uncertain),
            "prepayment_lag_claims_eligible": lag_eligible,
            "first_payment_edited": any(a["at"] is None or a["version_at"] is None
                                        or a["version_at"] > a["at"] for _, a in first),
            "first_payment_product": first[0][1]["product"] if first else "unknown",
            "prepayment_signal_lags_seconds": {k: first_time - at if lag_eligible else None
                                                for k, at in signal_first.items()},
            "prepayment_input_edited_after_payment": late_input_version,
            "prefixes": len(rows), "eligible_prefixes": sum(r["eligible"] for r in rows),
            "resolved_evidence_prefixes": sum(r["label"] is not None for r in rows),
            "prefix_exclusions": dict(collections.Counter(r["exclusion_reason"] for r in rows if r["exclusion_reason"])),
            "true_purchase_label": None, "historical_availability": "unknown",
        }
    return rows, audit


def sigmoid(value):
    return 1.0 / (1.0 + math.exp(-max(-35.0, min(35.0, value))))


def logit(value):
    value = max(1e-12, min(1 - 1e-12, value))
    return math.log(value / (1 - value))


def clip(value, low, high):
    return max(low, min(high, value))


def baseline_vector(row):
    return [1.0] + [float(row["stage"] == s) for s in STAGES[1:]] + [
        float(row["prefix_kind"] == p) for p in PREFIXES[1:]]


def decline(row):
    return PROTOCOL["explicit_decline_guard"] if row["explicit_decline"] else 1.0


def normalized_rows(rows):
    selected = [r for r in rows if r["eligible"] and type(r["label"]) is int]
    counts = collections.Counter(r["group_key"] for r in selected)
    return [(r, 1.0 / counts[r["group_key"]]) for r in selected]


def learn_common(weighted):
    dimension = len(baseline_vector(weighted[0][0]))
    total = sum(w for _, w in weighted)
    rate = sum(r["label"] * w for r, w in weighted) / total
    coefficients = [logit(clip(rate, 1e-4, 1 - 1e-4))] + [0.0] * (dimension - 1)
    compressed = collections.defaultdict(lambda: [0.0, 0.0])
    for row, weight in weighted:
        key = (tuple(baseline_vector(row)), row["explicit_decline"])
        compressed[key][0] += weight
        compressed[key][1] += row["label"] * weight
    for _ in range(PROTOCOL["iterations"]):
        grad = [0.0] * dimension
        for (x, is_declined), (weight, target) in compressed.items():
            offset = math.log(0.25) if is_declined else 0.0
            error = sigmoid(sum(a * b for a, b in zip(x, coefficients)) + offset) * weight - target
            for i, value in enumerate(x):
                grad[i] += error * value / total
        coefficients = [v - PROTOCOL["learning_rate"] * (g + (PROTOCOL["l2"] * v if i else 0.0))
                        for i, (v, g) in enumerate(zip(coefficients, grad))]
    return coefficients


def base_logit(row, coefficients):
    return sum(a * b for a, b in zip(baseline_vector(row), coefficients))


def learn_relative(weighted, common):
    present = {f: set() for f in FEATURES}
    positive = {f: set() for f in FEATURES}
    for row, _ in weighted:
        for feature in FEATURES:
            if row["features"][feature]:
                present[feature].add(row["group_key"])
                if row["label"] == 1:
                    positive[feature].add(row["group_key"])
    support = {f: {"development_groups": len(present[f]), "positive_development_groups": len(positive[f]),
                   "neutral": len(present[f]) < 15 or len(positive[f]) < 5} for f in FEATURES}
    coefficients = [0.0] * len(FEATURES)
    total = sum(w for _, w in weighted)
    compressed = collections.defaultdict(lambda: [0.0, 0.0])
    for row, weight in weighted:
        x = tuple(row["features"][f] if not support[f]["neutral"] else 0 for f in FEATURES)
        key = (tuple(baseline_vector(row)), row["explicit_decline"], x)
        compressed[key][0] += weight
        compressed[key][1] += row["label"] * weight
    for _ in range(PROTOCOL["iterations"]):
        grad = [0.0] * len(FEATURES)
        for (b, is_declined, x), (weight, target) in compressed.items():
            offset = sum(a * c for a, c in zip(b, common)) + (math.log(0.25) if is_declined else 0.0)
            error = sigmoid(offset + sum(a * c for a, c in zip(x, coefficients))) * weight - target
            for i, value in enumerate(x):
                grad[i] += error * value / total
        coefficients = [v - PROTOCOL["learning_rate"] * (g + PROTOCOL["l2"] * v)
                        for v, g in zip(coefficients, grad)]
    multipliers = {}
    for i, feature in enumerate(FEATURES):
        multipliers[feature] = 1.0 if support[feature]["neutral"] else clip(
            math.exp(PROTOCOL["coefficient_shrinkage"] * coefficients[i]), 0.5, 1.5)
    return multipliers, support


def scores(row, common, multipliers):
    base = base_logit(row, common)
    guard = decline(row)
    f = row["features"]
    old = guard * (1.1 if f["continued_meaningful_engagement"] else 1.0)
    old *= 1.2 if f["near_term_urgency"] else 1.0
    old *= 0.65 if f["long_range_planning"] else 1.0
    old *= 0.5 if f["deferral"] else 1.0
    new = guard
    for feature in FEATURES:
        if f[feature]:
            new *= multipliers[feature]
    return {"stage": sigmoid(base + math.log(guard)),
            "old": sigmoid(base + math.log(clip(old, 0.25, 1.3))),
            "candidate": sigmoid(base + math.log(clip(new, 0.25, 1.3)))}


def row_losses(target, probability):
    p = clip(probability, 1e-12, 1 - 1e-12)
    return (probability - target) ** 2, -(target * math.log(p) + (1 - target) * math.log1p(-p))


def evaluate(weighted, common, multipliers):
    if not weighted:
        return None
    group_losses = {}
    predictions = []
    for row, weight in weighted:
        probability = scores(row, common, multipliers)
        losses = group_losses.setdefault(row["group_key"], {arm: [0.0, 0.0] for arm in probability})
        for arm, p in probability.items():
            for i, loss in enumerate(row_losses(row["label"], p)):
                losses[arm][i] += weight * loss
        predictions.append((row, weight, probability))
    groups = sorted(group_losses)
    metric = {arm: {"brier": sum(group_losses[g][arm][0] for g in groups) / len(groups),
                    "log_loss": sum(group_losses[g][arm][1] for g in groups) / len(groups)}
              for arm in ("stage", "old", "candidate")}
    paired = {}
    for other in ("stage", "old"):
        changes = {name: [] for name in ("brier", "log_loss")}
        rng = random.Random(PROTOCOL["bootstrap_seed"])
        for _ in range(PROTOCOL["bootstrap_replicates"]):
            chosen = [groups[rng.randrange(len(groups))] for unused in groups]
            for i, name in enumerate(("brier", "log_loss")):
                changes[name].append(sum(group_losses[g]["candidate"][i] - group_losses[g][other][i]
                                         for g in chosen) / len(groups))
        paired[other] = {}
        for name, values in changes.items():
            values.sort()
            paired[other][name] = {"delta": metric["candidate"][name] - metric[other][name],
                                   "lower": values[int(0.025 * len(values))],
                                   "upper": values[min(len(values) - 1, int(0.975 * len(values)))]}
    strata = collections.defaultdict(list)
    for row, weight, probability in predictions:
        strata[(row["stage"], row["prefix_kind"])].append((row["label"], weight, probability))
    ranking = []
    for (stage, prefix), data in sorted(strata.items()):
        values = {}
        for arm in ("stage", "old", "candidate"):
            ordered = sorted((p[arm], target, weight) for target, weight, p in data)
            positive = sum(w for _, y, w in ordered if y == 1)
            negative = sum(w for _, y, w in ordered if y == 0)
            below = 0.0
            concordant = 0.0
            i = 0
            while i < len(ordered):
                j = i
                tie_positive = tie_negative = 0.0
                while j < len(ordered) and ordered[j][0] == ordered[i][0]:
                    if ordered[j][1] == 1:
                        tie_positive += ordered[j][2]
                    else:
                        tie_negative += ordered[j][2]
                    j += 1
                concordant += tie_positive * (below + tie_negative * 0.5)
                below += tie_negative
                i = j
            values[arm] = concordant / (positive * negative) if positive and negative else None
        ranking.append({"stage": stage, "prefix_kind": prefix, "rows": len(data), "auc": values})
    return {"unit": "equal_total_weight_per_peer_group_across_resolved_prefixes", "groups": len(groups),
            "metrics": metric, "paired_group_bootstrap": paired,
            "within_stage_prefix_ranking": ranking,
            "interpretation": "retained_evidence_target_not_true_purchase_calibration"}



def aggregate_payment_audits(audits):
    signals = [*FEATURES, "substantive_interest", "concrete_request",
               "general_purchase_intent", "agency_purchase_intent", "explicit_decline"]
    exclusions = collections.Counter()
    prefix_case_exclusions = collections.Counter()
    for audit in audits:
        exclusions.update(audit["audit_exclusions"])
        prefix_case_exclusions.update(audit["prefix_exclusions"].keys())
    observed = {}
    for signal in signals:
        retained = [a for a in audits if signal in a["prepayment_signal_lags_seconds"]]
        lags = sorted(a["prepayment_signal_lags_seconds"][signal] for a in retained
                      if a["prepayment_lag_claims_eligible"]
                      and type(a["prepayment_signal_lags_seconds"][signal]) is int)
        n = len(lags)
        observed[signal] = {"retained_prefix_cases": len(retained), "timing_eligible_cases": n,
            "median_lag_seconds": (lags[(n - 1) // 2] + lags[n // 2]) / 2 if n else None,
            "p90_lag_seconds": lags[max(0, math.ceil(n * 0.9) - 1)] if n else None}
    return {"unit": "account_chat_cases_not_unique_buyers", "cases": len(audits),
        "peer_groups": len({a["group_key"] for a in audits}),
        "stored_first_clock_present_cases": sum(a["known_payment_created_at_unix"] is not None for a in audits),
        "unknown_payment_clock_cases": sum(a["payment_clock_unknown"] for a in audits),
        "uncertain_first_payment_source_cases": sum(a["first_payment_source_uncertain"] for a in audits),
        "edited_first_payment_cases": sum(a["first_payment_edited"] for a in audits),
        "lag_claims_eligible_cases": sum(a["prepayment_lag_claims_eligible"] for a in audits),
        "lag_claims_excluded_cases": sum(not a["prepayment_lag_claims_eligible"] for a in audits),
        "cases_with_eligible_prefix": sum(a["eligible_prefixes"] > 0 for a in audits),
        "cases_with_resolved_evidence_prefix": sum(a["resolved_evidence_prefixes"] > 0 for a in audits),
        "audit_exclusions_by_reason": dict(exclusions),
        "cases_with_prefix_exclusion_by_reason": dict(prefix_case_exclusions),
        "prepayment_signals": observed,
        "timing_basis": "same_candidate_episode_before_first_retained_created_clock; uncertain_lags_excluded",
        "historical_availability": "unknown", "verified_purchases": None}


def analyze(manifest, atoms, manifest_sha256=None):
    began = time.monotonic()
    validate_manifest(manifest)
    require(manifest_sha256 is None or is_sha(manifest_sha256), "manifest_hash")
    manifest_sha256 = manifest_sha256 or digest(manifest)
    rows, audits = [], []
    cases = set()
    case_atoms = []
    current = None
    identity = None
    all_source_refs = set()
    physical = unique = duplicates = 0
    last_at = None
    coverage = collections.Counter()
    def flush():
        if case_atoms:
            new_rows, audit = process_case(case_atoms, manifest["captured_at_unix"])
            rows.extend(new_rows)
            if audit is not None:
                audits.append(audit)
            require(len(rows) <= MAX_PREFIXES, "prefix_limit")
    for value in atoms:
        physical += 1
        require(physical <= MAX_ATOMS and time.monotonic() - began < MAX_SECONDS, "source_or_runtime_limit")
        atom = dict(validate_atom(value))
        atom["flags"] = dict(atom["flags"])
        require(int(atom["ref"].split(":")[1]) <= int(manifest["source_fence"]), "source_after_fence")
        require(atom["ref"] not in all_source_refs, "duplicate_global_source_ref")
        all_source_refs.add(atom["ref"])
        case = atom["case_key"]
        if case != current:
            flush()
            require(case not in cases, "noncontiguous_case")
            cases.add(case)
            require(len(cases) <= MAX_CASES, "case_limit")
            current = case
            identity = (atom["group_key"], atom["account_alias"])
            case_atoms = []
            last_at = None
        require(identity == (atom["group_key"], atom["account_alias"]), "case_identity_changed")
        if atom["at"] is None:
            atom["flags"]["unknown_time"] = True
        elif last_at is not None and atom["at"] < last_at:
            atom["flags"]["reversed_clock"] = True
        if atom["at"] is not None:
            last_at = atom["at"]
        case_atoms.append(atom)
        require(len(case_atoms) <= MAX_CASE_ATOMS, "case_atom_limit")
        unique += 1
        coverage["unavailable_or_unknown_role"] += int(atom["status"] != "scanned" or atom["role"] == "unknown")
        coverage["unknown_clock"] += int(atom["at"] is None or atom["version_at"] is None)
        coverage["edited_retained_version"] += int(atom["at"] is not None and atom["version_at"] is not None
                                                   and atom["version_at"] > atom["at"])
        coverage["unread_media"] += int(atom["flags"].get("unread_media", False))
    flush()
    require(physical == source_record_total(manifest)
            and len(cases) == manifest["expected"]["account_chat_pairs"], "corpus_count_mismatch")
    observation_ids = [r["observation_id"] for r in rows]
    require(len(set(observation_ids)) == len(observation_ids), "duplicate_observation")
    development = normalized_rows([r for r in rows if r["assignment"] == "development"])
    control = normalized_rows([r for r in rows if r["assignment"] == "control"])
    dev_groups = {r["group_key"] for r, _ in development}
    ctrl_groups = {r["group_key"] for r, _ in control}
    require(not dev_groups & ctrl_groups, "group_split_overlap")
    dev_positive = {r["group_key"] for r, _ in development if r["label"] == 1}
    ctrl_positive = {r["group_key"] for r, _ in control if r["label"] == 1}
    support_ok = len(dev_groups) >= 40 and len(ctrl_groups) >= 40 and len(dev_positive) >= 8 and len(ctrl_positive) >= 8
    train_ok = len(dev_groups) >= 40 and len(dev_positive) >= 8 and any(r["label"] == 0 for r, _ in development)
    multipliers = {f: 1.0 for f in FEATURES}
    support = {f: {"development_groups": 0, "positive_development_groups": 0, "neutral": True} for f in FEATURES}
    evaluation = None
    if train_ok:
        common = learn_common(development)
        multipliers, support = learn_relative(development, common)
        evaluation = evaluate(control, common, multipliers)
    point_better = evaluation is not None and evaluation["metrics"]["candidate"]["log_loss"] < evaluation["metrics"]["old"]["log_loss"]
    point_not_worse = evaluation is not None and evaluation["metrics"]["candidate"]["log_loss"] <= evaluation["metrics"]["stage"]["log_loss"]
    eligible = bool(support_ok and train_ok and point_better and point_not_worse)
    binding = {k: manifest[k] for k in ("project_key", "run_id", "scope_digest", "source_fence", "extractor_sha256", "text_features_sha256")}
    binding.update({"manifest_sha256": manifest_sha256, "protocol_sha256": PROTOCOL_SHA})
    candidate = {
        "schema": 1, "kind": "archive_predictive_relative_weights_v1", **binding,
        "target": TARGET, "complete": True, "weights_eligible": eligible, "multipliers": multipliers,
        "global_odds_bounds": [0.25, 1.3], "explicit_decline_guard": 0.25,
        "stage_priors_unchanged": True, "calibration_status": "not_calibrated_purchase_probability",
        "historical_availability": "unknown", "issued_at_unix": manifest["captured_at_unix"],
    }
    summary = {
        "schema": 1, "kind": "archive_predictive_study_summary_v1", **binding,
        "target": TARGET, "complete": True, "weights_eligible": eligible,
        "counts": {"source_records": physical, "unique_source_records": unique, "duplicate_records": duplicates,
                   "account_chat_pairs": len(cases), "payment_candidate_cases": len(audits), "prefixes": len(rows),
                   "eligible_prefixes": sum(r["eligible"] for r in rows),
                   "evidence_positive_prefixes": sum(r["label"] == 1 for r in rows),
                   "retained_evidence_absent_prefixes": sum(r["label"] == 0 for r in rows),
                   "unknown_prefixes": sum(r["label"] is None for r in rows)},
        "coverage": {**dict(coverage), "prefix_exclusions": dict(collections.Counter(
                        r["exclusion_reason"] for r in rows if r["exclusion_reason"])),
                     "label_reasons": dict(collections.Counter(r["label_reason"] for r in rows)),
                     "source_history_external_completeness": "unknown", "historical_availability": "unknown",
                     "accepted_16000_membership": "unverified", "true_purchase_labels_available": False},
        "fit": {"development_groups": len(dev_groups), "positive_development_groups": len(dev_positive),
                "control_groups": len(ctrl_groups), "positive_control_groups": len(ctrl_positive),
                "fitted": train_ok, "support_gates_passed": support_ok, "feature_support": support,
                "point_better_than_old": point_better, "point_not_worse_than_stage": point_not_worse,
                "control_tuned": False, "control_is_pristine_research_holdout": False},
        "evaluation": evaluation, "candidate_admission": PROTOCOL["candidate_admission"],
        "relative_weights": {"multipliers": dict(multipliers),
            "old_multipliers": dict(zip(FEATURES, (1.1, 1.2, 0.65, 0.5, 1.0, 1.0, 1.0))),
            "caps": {"individual": [0.5, 1.5], "global": [0.25, 1.3], "explicit_decline_guard": 0.25},
            "basis": "development_fit; identical_held_group_control; retained_payment_evidence_not_purchase",
            "weights_eligible": eligible, "activation": "candidate_not_activated",
            "stage_priors_unchanged": True, "calibrated_purchase_probability": None},
        "payment_candidate_audit": aggregate_payment_audits(audits),
        "probability_calibrated_purchase": False, "stage_priors_unchanged": True, "commission_unchanged": True,
        "model_calls": 0, "provider_calls": 0, "conversion_uploads": 0, "business_writes": 0,
    }
    if len(encoded(summary)) > 11800 and summary["evaluation"] is not None:
        ranking = summary["evaluation"].pop("within_stage_prefix_ranking")
        compact = {}
        for arm in ("stage", "old", "candidate"):
            values = [r["auc"][arm] for r in ranking if r["auc"][arm] is not None]
            compact[arm] = sum(values) / len(values) if values else None
        summary["evaluation"]["within_stage_prefix_macro_auc"] = compact
        summary["evaluation"]["ranking_detail_omitted_for_size"] = True
    require(len(encoded(summary)) <= 11800, "summary_byte_limit")
    require(time.monotonic() - began < MAX_SECONDS, "runtime_limit")
    return {"summary": summary, "candidate_weights": candidate, "case_audits": audits, "prefixes": rows}


def checked_file(path, maximum):
    require(os.path.isabs(path) and os.path.realpath(path) == path, "private_path")
    info = os.lstat(path)
    require(stat.S_ISREG(info.st_mode) and not stat.S_ISLNK(info.st_mode)
            and 0 <= info.st_size <= maximum, "private_file")
    return info


def read_dataset(run):
    require(os.path.isabs(run) and os.path.realpath(run) == run, "run_path")
    info = os.lstat(run)
    require(stat.S_ISDIR(info.st_mode) and not stat.S_ISLNK(info.st_mode), "run_directory")
    path = os.path.join(run, "manifest.json")
    checked_file(path, 1048576)
    with open(path, "rb") as handle:
        raw = handle.read(1048577)
    require(len(raw) <= 1048576, "manifest_bytes")
    manifest = validate_manifest(loads(raw))
    require("source_counts" in manifest["coverage"], "manifest_runtime_source_counts_required")
    checked_file(os.path.abspath(__file__), 262144)
    with open(__file__, "rb") as handle:
        analyzer_hash = hashlib.sha256(handle.read()).hexdigest()
    require(manifest.get("analyzer_sha256") == analyzer_hash, "analyzer_source_binding")
    def atoms():
        total_bytes = 0
        for chunk in manifest["chunks"]:
            path = os.path.join(run, *chunk["file"].split("/"))
            checked_file(path, MAX_INPUT_BYTES)
            sha = hashlib.sha256()
            count = 0
            with open(path, "rb") as handle:
                while True:
                    line = handle.readline(MAX_LINE_BYTES + 1)
                    if not line:
                        break
                    require(len(line) <= MAX_LINE_BYTES and line.endswith(b"\n"), "atom_line_limit")
                    count += 1
                    total_bytes += len(line)
                    require(total_bytes <= MAX_INPUT_BYTES, "input_bytes")
                    sha.update(line)
                    yield loads(line)
            require(count == chunk["records"] and sha.hexdigest() == chunk["sha256"], "chunk_hash_or_count")
    return manifest, hashlib.sha256(raw).hexdigest(), atoms()


def fsync_directory(path):
    require(hasattr(os, "O_DIRECTORY") and hasattr(os, "O_NOFOLLOW"), "directory_fsync_unavailable")
    fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        require(stat.S_ISDIR(os.fstat(fd).st_mode), "directory_fsync_not_directory")
        os.fsync(fd)
    finally:
        os.close(fd)


def write_outputs(run, result):
    directory = os.path.join(run, "analysis")
    os.mkdir(directory, 0o700)
    fsync_directory(run)
    files = (("summary.json", (result["summary"],)),
             ("candidate_weights.json", (result["candidate_weights"],)),
             ("case_audits.jsonl", result["case_audits"]),
             ("prefixes.jsonl", result["prefixes"]))
    for name, values in files:
        path = os.path.join(directory, name)
        fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        with os.fdopen(fd, "wb") as handle:
            for value in values:
                handle.write(encoded(value) + b"\n")
            handle.flush()
            os.fsync(handle.fileno())
    fsync_directory(directory)
    fsync_directory(run)


def main():
    ok = False
    code = 0
    try:
        require(len(sys.argv) == 3 and sys.argv[1] == "--run-dir", "arguments")
        run = sys.argv[2]
        require(not os.path.lexists(os.path.join(run, "analysis")), "analysis_already_exists")
        manifest, manifest_sha, atoms = read_dataset(run)
        result = analyze(manifest, atoms, manifest_sha)
        write_outputs(run, result)
        ok = True
    except InvalidStudy:
        sys.stderr.write("archive_predictive_input_invalid\n")
        code = 2
    except Exception:
        sys.stderr.write("archive_predictive_processing_unavailable\n")
        code = 3
    sys.stdout.write(json.dumps({"ok": ok, "completed": ok, "private_outputs_written": ok,
                                "purchase_probability_calibrated": False, "live_files_changed": False},
                               separators=(",", ":")) + "\n")
    return code


if __name__ == "__main__":
    raise SystemExit(main())
