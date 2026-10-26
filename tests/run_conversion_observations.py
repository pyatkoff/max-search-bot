#!/usr/bin/env python3
"""Synthetic temporal/isolation tests, never a claim of model accuracy."""

import copy
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import unittest

TOOL = Path(__file__).resolve().parents[1] / "tools" / "build_conversion_observations.py"
spec = importlib.util.spec_from_file_location("conversion_observations", TOOL)
builder = importlib.util.module_from_spec(spec)
spec.loader.exec_module(builder)

SCOPE = {"project_key": "anytour", "platform": "max", "customer_key": "customer:17",
         "conversation_id": "31", "episode_key": "trip:8"}
START = "2026-10-01T09:00:00Z"
BEFORE = "2026-10-01T10:00:00Z"
PREDICT = "2026-10-01T12:00:00Z"
AFTER = "2026-10-02T10:00:00Z"
END = "2026-10-08T12:00:00Z"
LATE = "2026-10-09T12:00:00Z"


def item(identity, kind, at=BEFORE, available=None, confidence="high"):
    return {"id": identity, "subject": dict(SCOPE), "kind": kind, "at": at,
            "available_at": available or at, "confidence": confidence,
            "references": [{"source": "message", "id": "source:" + identity,
                            "at": at, "available_at": at}]}


def observation(*evidence):
    return {"subject": dict(SCOPE), "context_from": START, "prediction_at": PREDICT,
            "observed_through": END, "prefix_complete": True, "followup_complete": True,
            "evidence": list(evidence)}


def ad_entry(at=BEFORE, available=None):
    return {"id": "visit:42", "project_key": "anytour", "platform": "max",
            "customer_key": "customer:17", "source_id": "1576", "counter_id": "98615635",
            "at": at, "available_at": available or at, "verification": "verified"}


class ObservationTests(unittest.TestCase):
    def test_late_target_history_blocks_future_positive_without_rewriting_source_coverage(self):
        row = observation(item("question", "customer_message"), item("choose", "booking_intent", AFTER))
        row["target_history_available_at"] = AFTER
        result = builder.build_observation(row)
        self.assertTrue(result["features"]["prefix_complete"])
        self.assertFalse(result["eligible"])
        self.assertIsNone(result["label"]["value"])
        self.assertEqual(result["label"]["reason"], "target_history_unavailable_at_prediction")

    def test_target_history_available_by_prediction_preserves_existing_risk_set(self):
        row = observation(item("question", "customer_message"), item("choose", "booking_intent", AFTER))
        before = builder.build_observation(row)
        row["target_history_available_at"] = PREDICT
        after = builder.build_observation(row)
        self.assertEqual(before, after)

    def test_target_history_availability_has_valid_actual_clock(self):
        for at in ["2026-09-30T09:00:00Z", LATE, "2026-10-01 12:00:00", None]:
            with self.subTest(at=at):
                row = observation(); row["target_history_available_at"] = at
                with self.assertRaises(builder.InvalidObservation): builder.build_observation(row)

    def test_future_progress_becomes_label_not_features(self):
        base = observation(item("question", "customer_message"), item("need", "clarifying"))
        before = builder.build_observation(base)
        base["evidence"].append(item("choose", "offer_selected", AFTER))
        after = builder.build_observation(base)
        self.assertEqual(before["features"], after["features"])
        self.assertEqual(before["feature_fingerprint"], after["feature_fingerprint"])
        self.assertEqual(after["label"], {"value": 1, "reason": "observed_progress", "evidence_ids": ["choose"]})
        self.assertNotIn("choose", after["evidence_ids_at_prediction"])

    def test_late_annotation_cannot_retroactively_enter_features(self):
        base = observation(item("question", "customer_message"))
        first = builder.build_observation(base)
        base["evidence"].append(item("late", "comparing", BEFORE, AFTER))
        last = builder.build_observation(base)
        self.assertEqual(first["features"], last["features"])
        self.assertEqual(first["feature_fingerprint"], last["feature_fingerprint"])

    def test_silence_is_unknown_even_after_complete_window(self):
        row = builder.build_observation(observation(item("question", "customer_message")))
        self.assertTrue(row["eligible"])
        self.assertIsNone(row["label"]["value"])
        self.assertEqual(row["label"]["reason"], "no_confirmed_outcome")

    def test_terminal_is_negative_only_after_complete_mature_window(self):
        value = observation(item("refusal", "declined", AFTER))
        value["observed_through"] = AFTER
        self.assertEqual(builder.build_observation(value)["label"]["reason"], "window_not_mature")
        value["observed_through"] = END
        value["followup_complete"] = False
        self.assertEqual(builder.build_observation(value)["label"]["reason"], "incomplete_followup")
        value["followup_complete"] = True
        self.assertEqual(builder.build_observation(value)["label"]["value"], 0)

    def test_booking_intent_is_progress_without_being_purchase_probability(self):
        data = {"schema_version": 1, "observations": [observation(item("book", "booking_intent", AFTER))]}
        result = builder.build_document(data)
        self.assertEqual(result["observations"][0]["label"]["value"], 1)
        self.assertIsNone(result["probability"])
        self.assertFalse(result["export_allowed"])
        self.assertEqual(result["mode"], "shadow_only")

    def test_existing_progress_and_incomplete_prefix_are_not_new_targets(self):
        value = observation(item("choose", "offer_selected"))
        row = builder.build_observation(value)
        self.assertFalse(row["eligible"])
        self.assertEqual(row["label"]["reason"], "target_already_reached")
        value["prefix_complete"] = False
        self.assertEqual(builder.build_observation(value)["label"]["reason"], "incomplete_prefix")

    def test_current_trip_boundary_excludes_old_messages(self):
        row = builder.build_observation(observation(item("old", "paid", "2026-09-30T10:00:00Z")))
        self.assertTrue(row["eligible"])
        self.assertEqual(row["evidence_ids_at_prediction"], [])
        stale_reference = item("borrowed", "offer_selected")
        stale_reference["references"].append({"source": "message", "id": "old:1",
                                               "at": "2026-09-30T10:00:00Z",
                                               "available_at": "2026-09-30T10:00:00Z"})
        with self.assertRaisesRegex(builder.InvalidObservation, "source_precedes_current_trip"):
            builder.build_observation(observation(stale_reference))

    def test_every_subject_dimension_is_isolated_even_in_future_rows(self):
        for field in builder.SCOPE_FIELDS:
            with self.subTest(field=field):
                other = item("future", "paid", AFTER)
                other["subject"][field] = "telegram" if field == "platform" else "different"
                with self.assertRaisesRegex(builder.InvalidObservation, "cross_subject_evidence"):
                    builder.build_observation(observation(other))

    def test_duplicate_evidence_is_idempotent_and_conflict_fails(self):
        event = item("same", "customer_message")
        single = builder.build_observation(observation(event))
        repeated = builder.build_observation(observation(event, copy.deepcopy(event)))
        self.assertEqual(single, repeated)
        conflict = copy.deepcopy(event)
        conflict["kind"] = "manager_message"
        with self.assertRaisesRegex(builder.InvalidObservation, "conflicting_evidence_id"):
            builder.build_observation(observation(event, conflict))

    def test_future_advertising_link_cannot_change_feature_snapshot(self):
        value = observation(item("question", "customer_message"))
        baseline = builder.build_observation(value)
        value["advertising_entry"] = ad_entry(available=AFTER)
        later = builder.build_observation(value)
        self.assertEqual(baseline["features"], later["features"])
        self.assertEqual(baseline["feature_fingerprint"], later["feature_fingerprint"])
        value["advertising_entry"] = ad_entry()
        self.assertEqual(builder.build_observation(value)["features"]["advertising"]["status"], "verified_by_producer")
        value["advertising_entry"]["verification"] = "unverified"
        self.assertIsNone(builder.build_observation(value)["features"]["advertising"]["entry_key"])

    def test_foreign_advertising_person_or_source_scope_cannot_join(self):
        for field in ("project_key", "platform", "customer_key"):
            with self.subTest(field=field):
                value = observation()
                value["advertising_entry"] = ad_entry()
                value["advertising_entry"][field] = "other"
                with self.assertRaisesRegex(builder.InvalidObservation, "cross_subject_advertising_link"):
                    builder.build_observation(value)

    def test_person_group_is_stable_across_trips_conversations_and_linked_platforms(self):
        first = observation()
        second = observation()
        second["subject"].update(conversation_id="32", episode_key="trip:9", platform="telegram")
        a, b = builder.build_observation(first), builder.build_observation(second)
        self.assertEqual(a["group_key"], b["group_key"])
        self.assertNotEqual(a["observation_id"], b["observation_id"])
        second["subject"]["project_key"] = "another"
        self.assertNotEqual(a["group_key"], builder.build_observation(second)["group_key"])

    def test_prediction_boundary_is_prefix_and_deadline_is_inclusive(self):
        at_cutoff = builder.build_observation(observation(item("chosen", "offer_selected", PREDICT)))
        self.assertFalse(at_cutoff["eligible"])
        at_end = builder.build_observation(observation(item("chosen", "offer_selected", END)))
        self.assertEqual(at_end["label"]["value"], 1)
        after_end = builder.build_observation(observation(item("chosen", "offer_selected", LATE)))
        self.assertIsNone(after_end["label"]["value"])

    def test_positive_can_be_observed_early_but_uncertain_stage_is_not_truth(self):
        value = observation(item("choose", "offer_selected", AFTER))
        value["observed_through"] = AFTER
        value["followup_complete"] = False
        self.assertEqual(builder.build_observation(value)["label"]["value"], 1)
        value["evidence"][0]["confidence"] = "medium"
        self.assertIsNone(builder.build_observation(value)["label"]["value"])

    def test_source_availability_and_future_references_are_checked(self):
        value = item("annotated", "comparing")
        value["references"][0]["at"] = AFTER
        value["references"][0]["available_at"] = AFTER
        with self.assertRaisesRegex(builder.InvalidObservation, "future_source_reference"):
            builder.build_observation(observation(value))
        value = item("annotated", "comparing", AFTER, BEFORE)
        with self.assertRaisesRegex(builder.InvalidObservation, "available_before_occurrence"):
            builder.build_observation(observation(value))

    def test_ambiguous_equal_time_stages_stay_unknown(self):
        row = builder.build_observation(observation(item("a", "clarifying"), item("b", "comparing")))
        self.assertEqual(row["features"]["current_stage"], "unknown")

    def test_later_processing_time_cannot_create_a_future_progress_event(self):
        value = item("late-processing", "booking_intent", AFTER)
        value["references"][0].update(at=BEFORE, available_at=BEFORE)
        with self.assertRaisesRegex(builder.InvalidObservation, "occurrence_must_match_latest_source"):
            builder.build_observation(observation(value))

    def test_ids_do_not_turn_into_new_observations_when_followup_arrives(self):
        value = observation(item("q", "customer_message"))
        early = builder.build_observation(value)
        value["observed_through"] = LATE
        value["evidence"].append(item("progress", "booking_intent", AFTER))
        mature = builder.build_observation(value)
        self.assertEqual(early["observation_id"], mature["observation_id"])
        with self.assertRaisesRegex(builder.InvalidObservation, "duplicate_observation"):
            builder.build_document({"schema_version": 1, "observations": [value, value]})

    def test_cli_is_atomic_and_never_echoes_unknown_private_fields(self):
        good = observation(item("q", "customer_message"))
        bad = copy.deepcopy(good)
        bad["private_text"] = "PRIVATE SENTINEL DO NOT EMIT"
        result = subprocess.run([sys.executable, str(TOOL)],
                                input=json.dumps({"schema_version": 1, "observations": [good, bad]}),
                                text=True, capture_output=True, check=False)
        self.assertEqual(result.returncode, 2)
        self.assertEqual(result.stdout, "")
        self.assertEqual(json.loads(result.stderr), {"ok": False, "error": "invalid_fields"})

    def test_cli_rejects_duplicate_json_keys_invalid_utf8_and_oversize(self):
        for raw, reason in [(b'{"schema_version":1,"schema_version":1,"observations":[]}', "duplicate_json_key"),
                            (b'\xff', "input_unavailable_or_invalid_json"),
                            (b' ' * (builder.MAX_BYTES + 1), "input_byte_limit")]:
            with self.subTest(reason=reason):
                result = subprocess.run([sys.executable, str(TOOL)], input=raw, capture_output=True)
                self.assertEqual(result.returncode, 2)
                self.assertEqual(result.stdout, b"")
                self.assertEqual(json.loads(result.stderr)["error"], reason)

    def test_cli_success_preserves_shadow_only_contract(self):
        result = subprocess.run([sys.executable, str(TOOL)],
                                input=json.dumps({"schema_version": 1, "observations": [observation()]}),
                                text=True, capture_output=True, check=False)
        self.assertEqual(result.returncode, 0, result.stderr)
        data = json.loads(result.stdout)
        self.assertEqual(data["target_version"], builder.TARGET)
        self.assertIsNone(data["observations"][0]["label"]["value"])


if __name__ == "__main__":
    unittest.main(verbosity=2)
