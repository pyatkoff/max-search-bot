#!/usr/bin/env python3
"""Transform admitted private journal snapshots; no source/DB/network/model access."""
import argparse
from datetime import datetime, timezone
import hashlib
import json
import re
from pathlib import Path
import sys

import build_conversion_observations as base
import extract_conversion_profiles as extractor
import purchase_conversion_study as purchase_study

VERSION = "admitted_journal_profile_v1_5"
MAX_BYTES = 2 * 1024 * 1024
ADAPTER_SHA = hashlib.sha256(Path(__file__).read_bytes()).hexdigest()


def epoch(value):
    base.require(type(value) is int and 946684800 <= value <= 4102444800, "invalid_journal_clock")
    return datetime.fromtimestamp(value, timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def recorded_payload(raw, recorded, basis):
    value = json.loads(raw or "{}", object_pairs_hook=base.unique_object)
    if value == []:
        value = {}
    if not isinstance(value, dict):
        raise ValueError()
    if basis == "canonical_recording_unix_v1":
        clock = value.get("_recorded_clock")
        if (not isinstance(clock, dict) or set(clock) != {"version", "basis", "unix"}
                or type(clock["version"]) is not int or clock["version"] != 1
                or clock["basis"] != "server_recording_unix"
                or type(clock["unix"]) is not int or clock["unix"] != recorded):
            raise ValueError()
        value = dict(value)
        del value["_recorded_clock"]
    elif "_recorded_clock" in value:
        # A new header cannot be silently consumed through the old clock contract.
        raise ValueError()
    return value


def offer_journey(events, scope, omitted):
    """Join only immutable producer receipts already present in this admitted prefix.

    Native IDs and callback credentials are used for validation, never projected.
    Offer terms remain manager observations, never customer facts or accepted prices.
    """
    prepared = {}; deliveries = {}; likes = {}
    for event in events:
        kind, row, data, recorded = event
        ref = {"journal_id": row["journal_id"], "source_id": row["source_id"],
               "source_version_no": row["source_version_no"],
               "recorded_at": epoch(recorded), "available_at": epoch(row["observed_at_unix"])}
        if kind == "tour_offer_prepared":
            binding = data.get("binding", {})
            snapshot = data.get("snapshot")
            if (data.get("schema") != 1 or type(data.get("schema")) is not int
                    or set(data) != {"schema", "binding", "snapshot", "token_hash"}
                    or not isinstance(binding, dict)
                    or binding.get("project_key") != scope["project_key"]
                    or binding.get("channel") != scope["platform"]
                    or not all(isinstance(binding.get(k), str) and binding[k]
                               for k in ("external_chat_id", "external_user_id"))
                    or not isinstance(data.get("token_hash"), str)
                    or re.fullmatch(r"[a-f0-9]{64}", data["token_hash"]) is None
                    or not isinstance(snapshot, dict)
                    or set(snapshot) - {"hotel", "departure", "room", "meal", "date_from", "date_to", "date_to_basis",
                                        "nights", "adults", "child_ages", "amount", "currency", "price_basis", "is_demo",
                                        "price_verified", "flights", "rationale", "compromise", "quoted_details"}
                    or snapshot.get("is_demo") is not False
                    or snapshot.get("price_basis") != "party"
                    or snapshot.get("price_verified") is not False
                    or not all(isinstance(snapshot.get(k), str) and snapshot[k]
                               for k in ("hotel", "departure", "room", "meal", "date_from", "date_to", "amount", "currency"))):
                omitted("invalid_offer_receipt"); continue
            prepared.setdefault(row["source_id"], []).append((ref, data))
        else:
            identifier = data.get("offer_id")
            if type(identifier) is not int or identifier <= 0:
                omitted("invalid_offer_receipt"); continue
            target = deliveries if kind == "tour_offer_delivered" else likes
            target.setdefault(str(identifier), []).append((ref, data))
    out = []
    for identifier, candidates in prepared.items():
        if len(candidates) != 1:
            omitted("ambiguous_offer_receipt"); continue
        ref, data = candidates[0]
        item = {"snapshot": data["snapshot"], "snapshot_sha256": base.digest(data["snapshot"]),
                "prepared": ref, "delivery": None, "reaction": None,
                "state": "prepared", "customer_response": "unknown"}
        sent = deliveries.get(identifier, [])
        if len(sent) == 1:
            delivery, receipt = sent[0]
            native_chat = receipt.get("native_chat_id")
            valid = (set(receipt) == {"offer_id", "native_mid", "native_chat_id"}
                     and all(isinstance(receipt.get(k), str) and receipt[k]
                             for k in ("native_mid", "native_chat_id"))
                     and native_chat == data["binding"]["external_chat_id"]
                     and (scope["platform"] != "telegram" or native_chat == data["binding"]["external_user_id"])
                     and (delivery["recorded_at"], int(delivery["source_id"])) > (ref["recorded_at"], int(ref["source_id"])))
            if valid:
                item.update(delivery=delivery, state="delivered")
            else:
                omitted("invalid_offer_delivery")
        elif len(sent) > 1:
            omitted("ambiguous_offer_delivery")
        choices = likes.get(identifier, [])
        if len(choices) == 1 and item["delivery"] is not None:
            choice, receipt = choices[0]
            if (set(receipt) == {"offer_id"}
                    and (choice["recorded_at"], int(choice["source_id"])) > (item["delivery"]["recorded_at"], int(item["delivery"]["source_id"]))):
                item.update(reaction=choice, state="liked", customer_response="explicit_offer_preference")
            else:
                omitted("invalid_offer_reaction")
        elif choices:
            omitted("offer_reaction_without_unique_delivery")
        item["available_at"] = max(r["available_at"] for r in (item["prepared"], item["delivery"], item["reaction"]) if r is not None)
        out.append(item)
    for identifier in (deliveries.keys() | likes.keys()) - prepared.keys():
        omitted("offer_snapshot_not_in_prefix")
    out.sort(key=lambda item: int(item["prepared"]["journal_id"]))
    return {"version": "admitted_offer_journey_v1", "offers": out,
            "trip_status": "unknown", "episode_identity": "unverified",
            "complete": False, "ad_target_eligible": False,
            "meaning": "saved_offer_receipts_not_customer_conditions_or_purchase"}


def profile(document, extracted_at):
    base.fields(document, ("schema_version", "requested_at", "journal_cursor", "cases"), ("source_fence_journal_id",))
    base.require(document["schema_version"] == 1 and type(document["schema_version"]) is int, "unsupported_schema")
    requested = base.moment(document["requested_at"])
    base.require(requested <= base.moment(extracted_at), "extraction_before_request")
    base.key(document["journal_cursor"])
    information_fence = document.get("source_fence_journal_id", document["journal_cursor"])
    base.require(isinstance(information_fence, str) and re.fullmatch(r"[0-9]{1,20}", information_fence)
                 and re.fullmatch(r"[0-9]{1,20}", document["journal_cursor"])
                 and int(information_fence) >= int(document["journal_cursor"]), "invalid_information_fence")
    base.require(isinstance(document["cases"], list) and len(document["cases"]) <= 4, "profile_case_limit")
    output = []; seen = set()
    for case in document["cases"]:
        base.fields(case, ("subject", "rows", "window_limited", "access_denied"))
        base.fields(case["subject"], base.SCOPE_FIELDS[:-1])
        validated = base.subject({**case["subject"], "episode_key": "validation"})
        scope = {**validated, "episode_key": "unresolved-prefix:"+validated["conversation_id"]}
        base.require(scope["project_key"] == "anytour", "foreign_project")
        identity = base.digest(case["subject"])
        base.require(identity not in seen, "duplicate_profile_scope"); seen.add(identity)
        base.require(type(case["window_limited"]) is bool and type(case["access_denied"]) is bool, "explicit_coverage_required")
        base.require(isinstance(case["rows"], list) and len(case["rows"]) <= 50, "profile_window_limit")
        messages = []; refs = []; errors = {}; unique = set()
        input_rows = {}; receipts = []; membership = []; offer_events = []

        def omitted(reason):
            errors[reason] = errors.get(reason, 0)+1

        for row in case["rows"]:
            base.fields(row, ("journal_id", "source_kind", "source_id", "source_version_no", "observed_at_unix", "snapshot"), ("progress_journal_id",))
            for key in ("journal_id", "source_id"):
                base.require(isinstance(row[key], str) and re.fullmatch(r"[1-9][0-9]{0,19}", row[key]), "invalid_journal_identity")
            base.require(int(row["journal_id"]) <= int(information_fence), "revision_after_information_fence")
            progress = row.get("progress_journal_id", row["journal_id"])
            base.require(isinstance(progress, str) and re.fullmatch(r"[1-9][0-9]{0,19}", progress)
                         and int(progress) <= int(row["journal_id"]), "invalid_progress_reference")
            base.require(row["source_kind"] in {"message", "event"}, "invalid_journal_kind")
            base.require(row["journal_id"] not in unique, "duplicate_journal_revision"); unique.add(row["journal_id"])
            base.require(type(row["source_version_no"]) is int and row["source_version_no"] >= 1, "invalid_source_revision")
            if row["source_version_no"] > 1:
                omitted("source_revision_chronology_unresolved")
            received = epoch(row["observed_at_unix"])
            base.require(base.moment(received) <= requested, "future_journal_receipt")
            snap = row["snapshot"]
            base.require(isinstance(snap, dict) and snap.get("source_kind") == row["source_kind"]
                         and str(snap.get("source_id")) == str(row["source_id"]), "journal_source_mismatch")
            status = snap.get("source_status")
            base.require(status in {"present", "deleted", "scope_excluded"}, "invalid_source_status")
            refs.append({"journal_id": row["journal_id"], "source_kind": row["source_kind"],
                         "source_id": str(row["source_id"]), "source_version_no": row["source_version_no"], "observed_at": received,
                         "progress_journal_id": progress, "snapshot_sha256": base.digest(snap), "source_status": status})
            if status != "present":
                omitted(status); continue
            matches = (snap.get("project_key") == scope["project_key"] and snap.get("platform") == scope["platform"]
                       and str(snap.get("conversation_id")) == scope["conversation_id"]
                       and str(snap.get("customer_key")) == scope["customer_key"])
            if not matches:
                base.require("source_fence_journal_id" in document and "progress_journal_id" in row
                             and int(row["journal_id"]) > int(progress), "journal_scope_mismatch")
                omitted("source_scope_changed_at_information_fence"); continue
            if case["access_denied"]:
                omitted("scope_denied"); continue
            is_application = (row["source_kind"] == "event" and isinstance(snap.get("payload"), dict)
                              and snap["payload"].get("event_type") == "wizard_value_applied")
            is_membership = (row["source_kind"] == "event" and isinstance(snap.get("payload"), dict)
                             and snap["payload"].get("event_type") in {
                                 "channel_subscription_observed", "channel_retention_30m_observed"})
            is_offer = (row["source_kind"] == "event" and isinstance(snap.get("payload"), dict)
                        and snap["payload"].get("event_type") in {
                            "tour_offer_prepared", "tour_offer_delivered", "tour_offer_liked"})
            if row["source_kind"] != "message" and not is_application and not is_membership and not is_offer:
                omitted("uninterpreted_event"); continue
            if snap.get("payload_status") != "copied":
                omitted("payload_unavailable"); continue
            recorded = snap.get("source_created_at_unix")
            if (snap.get("source_timestamp_basis") not in {"database_session_timezone_to_unix", "canonical_recording_unix_v1"}
                    or type(recorded) is not int or not 946684800 <= recorded <= row["observed_at_unix"]):
                omitted("source_clock_unresolved"); continue
            payload = snap.get("payload")
            base.require(isinstance(payload, dict), "invalid_message_payload")
            if is_offer:
                try:
                    data = recorded_payload(payload.get("payload_json"), recorded, snap["source_timestamp_basis"])
                    kind = payload["event_type"]
                    if (row["source_version_no"] != 1 or not isinstance(data, dict)
                            or payload.get("actor_type") != ("customer" if kind == "tour_offer_liked" else "system")):
                        raise ValueError()
                    offer_events.append((kind, row, data, recorded))
                except (ValueError, TypeError, KeyError, base.InvalidObservation):
                    omitted("invalid_offer_receipt")
                continue
            if is_membership:
                try:
                    observed = recorded_payload(payload.get("payload_json"), recorded, snap["source_timestamp_basis"])
                    event_type = payload.get("event_type")
                    common = {"version", "origin", "source", "identity_scope", "state", "occurred_at_unix"}
                    expected = common if event_type == "channel_subscription_observed" else common | {
                        "subscription_event_id", "minimum_age_seconds"}
                    valid = (isinstance(observed, dict) and set(observed) == expected
                             and observed.get("version") == 1
                             and type(observed.get("version")) is int
                             and observed.get("origin") == "existing_membership_producer"
                             and payload.get("actor_type") == "system"
                             and observed.get("source") in {"max_membership_history", "telegram_membership_history"}
                             and observed.get("identity_scope") in {"same_chat_and_source", "source_only", "unknown"}
                             and type(observed.get("occurred_at_unix")) is int
                             and 946684800 <= observed["occurred_at_unix"] <= recorded)
                    if event_type == "channel_subscription_observed":
                        valid = valid and observed.get("state") == "subscribed"
                    else:
                        valid = (valid and observed.get("state") in {"retained", "not_retained"}
                                 and observed.get("minimum_age_seconds") == 1800
                                 and isinstance(observed.get("subscription_event_id"), str)
                                 and re.fullmatch(r"[1-9][0-9]{0,19}", observed["subscription_event_id"]))
                    if not valid:
                        raise ValueError()
                except (ValueError, TypeError, KeyError, base.InvalidObservation):
                    omitted("invalid_membership_receipt"); continue
                membership.append({"event_type": event_type, "source_id": str(row["source_id"]),
                    "id": "journal:"+row["journal_id"], "source": observed["source"],
                    "identity_scope": observed["identity_scope"], "state": observed["state"],
                    "at": epoch(observed["occurred_at_unix"]), "recorded_at": epoch(recorded),
                    "available_at": received,
                    "subscription_event_id": observed.get("subscription_event_id"),
                    "minimum_age_seconds": observed.get("minimum_age_seconds")})
                continue
            if is_application:
                try:
                    applied = recorded_payload(payload.get("payload_json"), recorded, snap["source_timestamp_basis"])
                    valid = (isinstance(applied, dict) and set(applied) == {
                        "version", "origin", "input_message_id", "input_kind", "input_text_sha256", "field", "storage_value"}
                        and type(applied["version"]) is int and applied["version"] == 1
                        and applied["origin"] == "existing_wizard_step_application"
                        and payload.get("actor_type") == "system" and row["source_version_no"] == 1
                        and isinstance(applied["input_message_id"], str)
                        and re.fullmatch(r"[1-9][0-9]{0,19}", applied["input_message_id"])
                        and applied["input_kind"] in {"message", "callback"}
                        and isinstance(applied["input_text_sha256"], str)
                        and re.fullmatch(r"[a-f0-9]{64}", applied["input_text_sha256"])
                        and isinstance(applied["field"], str)
                        and extractor.wizard_value(applied["field"], applied["storage_value"]) is not None)
                    if not valid:
                        raise ValueError()
                except (ValueError, TypeError, KeyError, base.InvalidObservation):
                    omitted("invalid_wizard_application"); continue
                receipts.append({"applied": applied, "receipt": {
                    "id": "journal:"+row["journal_id"], "source": "event", "speaker": "other",
                    "text": "", "at": epoch(recorded), "available_at": received}})
                continue
            text = payload.get("text")
            if not isinstance(text, str) or len(text) > extractor.MAX_TEXT:
                omitted("text_unavailable_or_oversize"); continue
            try:
                metadata = recorded_payload(payload.get("metadata_json"), recorded, snap["source_timestamp_basis"])
                if metadata == []:
                    metadata = {}  # Canonical outbound PHP writer uses an empty array.
                if not isinstance(metadata, dict):
                    raise ValueError()
            except (ValueError, TypeError):
                omitted("invalid_message_metadata"); continue
            speaker = "other"
            if payload.get("direction") == "inbound" and payload.get("sender_type") == "customer":
                speaker = "customer"
            elif payload.get("direction") == "outbound" and payload.get("sender_type") == "manager":
                speaker = "manager"
            linked = metadata.get("linked_message")
            if isinstance(linked, dict) and linked.get("type") == "forward":
                speaker = "other"
                omitted("forwarded_statement_owner_unresolved")
            kind = metadata.get("type")
            # Canonical outboundForConversation writes ordinary manager text without type.
            is_text = kind == "message" or (speaker == "manager" and kind is None)
            if not is_text:
                omitted("action_or_unknown_message_type")
            messages.append({"id": "journal:"+str(row["journal_id"]), "speaker": speaker,
                             "kind": "text" if is_text else "action", "text": text,
                             "at": epoch(recorded), "available_at": received})
            input_rows.setdefault(str(row["source_id"]), []).append({
                "message": messages[-1], "kind": kind, "version": row["source_version_no"]})
        applications = []; grouped = {}; claimed_values = {}
        for item in receipts:
            applied = item["applied"]; candidates = input_rows.get(applied["input_message_id"], [])
            if len(candidates) != 1:
                omitted("wizard_input_missing_or_ambiguous"); continue
            candidate = candidates[0]; message = candidate["message"]
            callback_prefixes = {"city": "pick_city_", "country": "pick_country_", "adults": "adults_",
                                 "children": "child_", "stars": "star_", "meal": "meal_", "nights": "nights_", "date": "pick_date_"}
            expected_callback = callback_prefixes.get(applied["field"], "invalid:") + (
                applied["storage_value"].replace('-', '_') if applied["field"] == "nights" else applied["storage_value"])
            input_valid = True
            if applied["input_kind"] == "message" and applied["field"] == "child_ages":
                # Legacy application can coerce empty separators to age zero.
                # Keep the applied receipt, but do not call that a stated age.
                parts = message["text"].split(',') if ',' in message["text"] else message["text"].split()
                input_valid = (bool(parts) and all(re.fullmatch(r"[0-9]{1,2}", p.strip()) for p in parts)
                               and [int(p.strip()) for p in parts] == [int(p) for p in applied["storage_value"].split(', ')])
            if applied["input_kind"] == "message" and applied["field"] in {"adults", "children"} and message["text"].strip().isdigit():
                input_valid = int(message["text"].strip()) == int(applied["storage_value"])
            if (candidate["version"] != 1 or message["speaker"] != "customer"
                    or candidate["kind"] != applied["input_kind"]
                    or (applied["input_kind"] == "callback" and message["text"] != expected_callback)
                    or hashlib.sha256(message["text"].encode()).hexdigest() != applied["input_text_sha256"]
                    or message["at"] > item["receipt"]["at"]):
                omitted("wizard_input_mismatch_or_unresolved"); continue
            key = (message["id"], applied["field"])
            claimed_values.setdefault(key, set()).add(applied["storage_value"])
            if not input_valid:
                omitted("wizard_input_mismatch_or_unresolved"); continue
            grouped.setdefault(key, []).append({"message_id": message["id"], "field": applied["field"],
                "storage_value": applied["storage_value"], "receipt": item["receipt"]})
        for key, group in grouped.items():
            if len(claimed_values[key]) != 1:
                omitted("conflicting_wizard_application"); continue
            # Earliest exact successful receipt; repeating it cannot add value or a stage.
            applications.append(min(group, key=lambda a: (a["receipt"]["at"], a["receipt"]["available_at"], a["receipt"]["id"])))
        subscription_ids = {}
        for item in membership:
            if item["event_type"] == "channel_subscription_observed":
                subscription_ids.setdefault(item["source_id"], []).append(item["id"])
        membership_receipts = []
        for item in membership:
            if item["event_type"] == "channel_subscription_observed":
                membership_receipts.append({k: item[k] for k in (
                    "id", "source", "identity_scope", "state", "at", "recorded_at", "available_at")} | {
                    "kind": "subscription"})
                continue
            matches = subscription_ids.get(item["subscription_event_id"], [])
            if len(matches) != 1:
                omitted("membership_period_missing_or_ambiguous"); continue
            membership_receipts.append({k: item[k] for k in (
                "id", "source", "identity_scope", "state", "at", "recorded_at", "available_at",
                "minimum_age_seconds")} | {"kind": "retention_30m", "subscription_id": matches[0]})
        fence = max((r["observed_at"] for r in refs), default=document["requested_at"])
        start = min([m["at"] for m in messages]+[r["at"] for r in membership_receipts], default=fence)
        profile_case = {"subject": scope, "brand_key": "anytour", "context_from": start,
                        "prediction_at": extracted_at, "source_complete_through": None,
                        "source_fence_at": fence, "prefix_complete": False, "trip_status": "unknown",
                        "messages": messages, "membership_receipts": membership_receipts}
        result = extractor.extract_case(profile_case, extracted_at, applications)
        # A denied current scope has no usable facts, even when older snapshots still exist.
        base.require(not case["access_denied"] or not result["prediction_features"]["facts"], "denied_scope_facts")
        journey = offer_journey(offer_events, scope, omitted)
        wrapper = {"subject": case["subject"], "profile": result, "offer_journey": journey, "source_references": refs,
                       "adapter": {"version": VERSION, "sha256": ADAPTER_SHA},
                       "journal_cursor": document["journal_cursor"], "source_fence_journal_id": information_fence,
                       "access_status": "denied" if case["access_denied"] else "allowed",
                       "coverage": {"source_history_complete": False, "trip_status": "unknown",
                                    "window_limited": case["window_limited"], "exposure": "unknown",
                                    "identity_assurance": "canonical_source_assertion", "errors": errors,
                                    "applied_wizard_fields": len(applications)}}
        # Descriptive future packet only: no inquiry/episode admission, label,
        # prediction or legacy eligibility is inferred by this wrapper.
        wrapper['purchase_study'] = purchase_study.freeze_profile(wrapper, case['rows'])
        output.append(wrapper)
    return {"schema_version": 1, "adapter_version": VERSION, "adapter_sha256": ADAPTER_SHA,
            "extractor_sha256": extractor.RULE_SHA, "journal_cursor": document["journal_cursor"], "source_fence_journal_id": information_fence,
            "requested_at": document["requested_at"], "extracted_at": extracted_at,
            "profiles": output, "export_allowed": False, "model_calls": 0, "conversion_uploads": 0}


def main():
    parser = argparse.ArgumentParser(description=__doc__); parser.add_argument("input")
    args = parser.parse_args()
    try:
        with open(args.input, "rb") as handle:
            raw = handle.read(MAX_BYTES+1)
        base.require(len(raw) <= MAX_BYTES, "input_byte_limit")
        document = json.loads(raw.decode("utf-8"), object_pairs_hook=base.unique_object)
        # Bind semantic availability to the actual completion second, never the old source fence.
        result = None
        for _ in range(3):
            stamp = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
            candidate = profile(document, stamp)
            if datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ") == stamp:
                result = candidate; break
        base.require(result is not None, "unstable_extraction_clock")
        text = json.dumps(result, ensure_ascii=False, separators=(",", ":"))
        base.require(len(text.encode()) <= MAX_BYTES, "output_byte_limit")
    except (base.InvalidObservation, OSError, UnicodeDecodeError, json.JSONDecodeError, RecursionError, OverflowError):
        print(json.dumps({"ok": False, "error": "journal_profile_invalid"}), file=sys.stderr)
        return 2
    print(text); return 0


if __name__ == "__main__":
    sys.exit(main())

