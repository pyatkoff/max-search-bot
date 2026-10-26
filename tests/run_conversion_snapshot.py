#!/usr/bin/env python3
"""Synthetic source-shaped integration controls, not real-data quality claims."""
import copy
import json
from pathlib import Path
import subprocess
import sys
import unittest

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT/"tools"))
import profile_conversion_snapshot as adapter

START = "2026-10-01T09:00:00Z"
CAPTURE = "2026-10-01T10:00:00Z"
RECEIVED = "2026-10-01T10:00:02Z"
PROCESSED = "2026-10-01T10:00:03Z"
SUBJECT = {"project_key": "anytour", "platform": "telegram", "customer_key": "customer:7", "conversation_id": "conversation:9"}


def message(identifier="m:1", text="Бюджет до 200 тыс на всех, 2 взрослых 1 ребенок", **extra):
    return {"id": identifier, "direction": "inbound", "sender_type": "customer", "text": text,
            "recorded_at": START, "type": "message", "metadata_revision": "a"*64, **extra}


def document():
    case = {"subject": dict(SUBJECT), "started_at": START, "messages_complete": True,
            "events_complete": True, "messages": [message()], "tour_choices": {"available": True, "limited": False, "offers": []},
            "source_clock": adapter.CLOCK}
    return {"schema_version": 1, "received_at": RECEIVED, "extracted_at": PROCESSED,
            "snapshot": {"schema_version": 1, "captured_at": CAPTURE, "database_writes": False, "conversion_uploads": 0, "cases": [case]},
            "trips": [{"subject": dict(SUBJECT), "brand_key": "anytour", "trip_start_message_id": "m:1"}]}


class SnapshotTests(unittest.TestCase):
    def test_source_to_typed_profile_and_canonical_observation(self):
        p = adapter.profile(document())["profiles"][0]
        self.assertEqual(p["fields"]["budget"]["value"]["maximum"], 200000)
        self.assertEqual(p["party"]["total"]["value"], 3)
        self.assertEqual(p["current_stage"]["value"], "request")
        self.assertEqual(p["observation"]["features"]["counts"]["customer_message"], 1)
        self.assertFalse(p["export_allowed"])

    def test_receipt_and_semantic_availability_are_distinct(self):
        result = adapter.profile(document());p = result["profiles"][0]
        self.assertEqual(p["fields"]["budget"]["references"][0]["available_at"], RECEIVED)
        self.assertEqual(p["fields"]["budget"]["available_at"], PROCESSED)
        self.assertEqual(result["source_receipt"]["receipt_lag_seconds"], 2)
        self.assertEqual(result["source_receipt"]["source_lag_at_prediction_seconds"], 3)

    def test_stale_capture_never_becomes_current_complete_prediction(self):
        p = adapter.profile(document())["profiles"][0]
        self.assertFalse(p["prefix_complete"])
        self.assertEqual(p["observation"]["label"]["reason"], "incomplete_prefix")

    def test_same_second_complete_source_can_be_complete(self):
        d = document();d.update(received_at=CAPTURE, extracted_at=CAPTURE)
        p = adapter.profile(d)["profiles"][0]
        self.assertTrue(p["prefix_complete"])
        self.assertTrue(p["observation"]["eligible"])

    def test_each_history_limit_prevents_complete_prefix(self):
        for field in ["messages_complete", "events_complete", "choices_unavailable", "choices_limited"]:
            with self.subTest(field=field):
                d = document();d.update(received_at=CAPTURE, extracted_at=CAPTURE);c=d["snapshot"]["cases"][0]
                if field.startswith("choices_"):
                    c["tour_choices"] = {"available": False} if field=="choices_unavailable" else {"available": True,"limited": True,"offers": []}
                else:c[field]=False
                self.assertFalse(adapter.profile(d)["profiles"][0]["prefix_complete"])

    def test_manager_quote_and_direction_contradiction_cannot_fill_budget(self):
        d=document();c=d["snapshot"]["cases"][0];c["messages"][0]["text"]="Тур в Египет"
        c["messages"] += [message("m:2","Цена 500 тыс RUB",direction="outbound",sender_type="manager"),
                          message("m:3","Бюджет 900 тыс",direction="outbound",sender_type="customer")]
        p=adapter.profile(d)["profiles"][0]
        self.assertIsNone(p["fields"]["budget"]["value"])
        self.assertEqual(len(p["manager_quotes"]),1)
        self.assertEqual(p["observation"]["features"]["counts"]["customer_message"],1)

    def test_action_and_system_rows_do_not_become_customer_purchase(self):
        d=document();c=d["snapshot"]["cases"][0]
        c["messages"] += [message("m:2","Оплатили тур у вас",type="callback"),message("m:3","Оплатили тур у вас",sender_type="bot")]
        self.assertEqual(adapter.profile(d)["profiles"][0]["current_stage"]["value"],"request")

    def test_episode_anchor_excludes_old_trip(self):
        d=document();c=d["snapshot"]["cases"][0];c["started_at"]="2026-09-01T09:00:00Z"
        c["messages"].append(message("old:1","Бюджет 900 тыс, оплатили тур у вас",recorded_at="2026-09-01T09:00:00Z"))
        p=adapter.profile(d)["profiles"][0]
        self.assertEqual(p["fields"]["budget"]["value"]["maximum"],200000)
        self.assertEqual(len(p["customer_requirements"]),1)

    def test_anchor_must_be_a_nonempty_current_customer_text(self):
        for change in [{"sender_type":"manager","direction":"outbound"},{"type":"start"},{"text":" "}]:
            with self.subTest(change=change):
                d=document();d["snapshot"]["cases"][0]["messages"][0].update(change)
                with self.assertRaisesRegex(adapter.base.InvalidObservation,"current_trip_customer_anchor_required"):adapter.profile(d)

    def test_all_subject_dimensions_fence_trip_mapping(self):
        for field in adapter.SUBJECT:
            with self.subTest(field=field):
                d=document();d["trips"][0]["subject"][field]="max" if field=="platform" else "foreign"
                with self.assertRaisesRegex(adapter.base.InvalidObservation,"trip_scope_mismatch"):adapter.profile(d)

    def test_brand_is_preserved_not_guessed_from_message(self):
        d=document();d["trips"][0]["brand_key"]="pegas"
        self.assertEqual(adapter.profile(d)["profiles"][0]["brand_key"],"pegas")

    def test_unknown_clock_and_future_rows_fail_without_timezone_guess(self):
        for change in ["clock","future","local_time"]:
            d=document();c=d["snapshot"]["cases"][0]
            if change=="clock":c["source_clock"]="mixed_event_clocks"
            else:c["messages"].append(message("m:2",recorded_at=PROCESSED if change=="future" else "2026-10-01 10:00:00"))
            with self.assertRaises(adapter.base.InvalidObservation):adapter.profile(d)

    def test_receipt_cannot_precede_capture_or_processing(self):
        for key,time in [("received_at",START),("extracted_at",CAPTURE)]:
            d=document();d[key]=time
            with self.assertRaisesRegex(adapter.base.InvalidObservation,"invalid_receipt_chronology"):adapter.profile(d)

    def test_complete_flags_require_explicit_booleans(self):
        for key in ["messages_complete","events_complete"]:
            d=document();d["snapshot"]["cases"][0][key]=1
            with self.assertRaisesRegex(adapter.base.InvalidObservation,"explicit_source_completeness_required"):adapter.profile(d)

    def test_source_revision_and_safe_role_fields_required(self):
        for key,value in [("metadata_revision","bad"),("direction",[]),("sender_type",None),("type","PRIVATE SENTINEL")]:
            d=document();d["snapshot"]["cases"][0]["messages"][0][key]=value
            with self.assertRaises(adapter.base.InvalidObservation):adapter.profile(d)

    def test_unsupported_source_writes_or_conversions_are_rejected(self):
        for key,value in [("database_writes",True),("conversion_uploads",1)]:
            d=document();d["snapshot"][key]=value
            with self.assertRaisesRegex(adapter.base.InvalidObservation,"read_only_source_required"):adapter.profile(d)

    def test_duplicate_messages_are_idempotent_conflicts_fail(self):
        d=document();expected=adapter.profile(d)["profiles"];c=d["snapshot"]["cases"][0]
        c["messages"].append(copy.deepcopy(c["messages"][0]))
        self.assertEqual(adapter.profile(d)["profiles"],expected)
        c["messages"][-1]["text"]="PRIVATE SENTINEL"
        with self.assertRaisesRegex(adapter.base.InvalidObservation,"conflicting_message_id"):adapter.profile(d)

    def test_duplicate_cases_or_trip_mappings_fail_atomically(self):
        for change in ["case","trip"]:
            d=document();d["snapshot"]["cases"]*=2;d["trips"]*=2
            if change=="case":d["trips"][1]=copy.deepcopy(d["trips"][1]);d["trips"][1]["subject"]["conversation_id"]="other"
            with self.assertRaises(adapter.base.InvalidObservation):adapter.profile(d)

    def test_mixed_event_timestamps_are_not_used_for_stages(self):
        d=document();d["snapshot"]["cases"][0]["events"]=[{"kind":"paid","stored_created_at":"unverified time"}]
        p=adapter.profile(d)["profiles"][0]
        self.assertEqual(p["current_stage"]["value"],"request")

    def test_uninterpreted_native_choices_keep_coverage_incomplete(self):
        d=document();d.update(received_at=CAPTURE,extracted_at=CAPTURE)
        d["snapshot"]["cases"][0]["tour_choices"]["offers"]=[{"selected": True}]
        p=adapter.profile(d)["profiles"][0]
        self.assertFalse(p["prefix_complete"])
        self.assertEqual(p["observation"]["label"]["reason"],"incomplete_prefix")

    def test_malformed_native_choice_projection_cannot_assert_coverage(self):
        d=document();d["snapshot"]["cases"][0]["tour_choices"].pop("offers")
        with self.assertRaisesRegex(adapter.base.InvalidObservation,"choice_rows_required"):adapter.profile(d)

    def test_row_order_and_renamed_ids_do_not_supply_facts(self):
        d=document();e=copy.deepcopy(d);e["snapshot"]["cases"][0]["messages"][0]["id"]="random:42";e["trips"][0]["trip_start_message_id"]="random:42"
        a,b=adapter.profile(d)["profiles"][0],adapter.profile(e)["profiles"][0]
        self.assertEqual(a["fields"]["budget"]["value"],b["fields"]["budget"]["value"])

    def test_no_raw_text_network_model_or_export_in_output(self):
        d=document();d["snapshot"]["cases"][0]["messages"][0]["text"]="PRIVATE SENTINEL"
        out=adapter.profile(d)
        self.assertNotIn("PRIVATE SENTINEL",json.dumps(out))
        self.assertEqual(out["source_receipt"]["live_source_reads"],0)
        self.assertEqual(out["source_receipt"]["model_calls"],0)
        self.assertFalse(out["export_allowed"])

    def test_cli_equals_direct_and_has_no_partial_error_output(self):
        d=document();cmd=[sys.executable,str(ROOT/"tools/profile_conversion_snapshot.py")]
        good=subprocess.run(cmd,input=json.dumps(d),text=True,capture_output=True)
        self.assertEqual(good.returncode,0,good.stderr);self.assertEqual(json.loads(good.stdout),adapter.profile(d))
        d["trips"][0]["private"]="PRIVATE SENTINEL"
        bad=subprocess.run(cmd,input=json.dumps(d),text=True,capture_output=True)
        self.assertEqual(bad.returncode,2);self.assertEqual(bad.stdout,"");self.assertNotIn("PRIVATE SENTINEL",bad.stderr)

    def test_cli_duplicate_json_utf8_and_byte_limit(self):
        for raw in [b'{"schema_version":1,"schema_version":1}',b'\xff',b' '*(adapter.extractor.MAX_BYTES+1)]:
            p=subprocess.run([sys.executable,str(ROOT/"tools/profile_conversion_snapshot.py")],input=raw,capture_output=True)
            self.assertEqual(p.returncode,2);self.assertEqual(p.stdout,b"")


if __name__=="__main__":unittest.main(verbosity=2)
