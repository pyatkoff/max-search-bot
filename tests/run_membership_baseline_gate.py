#!/usr/bin/env python3
import copy
import sys
import unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "tools"))
import membership_baseline_gate as gate

class Tests(unittest.TestCase):
    def setUp(self):
        self.scope = {"project":"anytour","platform":"max","person":"synthetic","conversation":"synthetic"}
        self.data = {"scope":self.scope,"admission":dict.fromkeys(("canonical_guard_authorized","same_case_bound","non_test","non_owner","not_revoked"),True),"rows":[],"protocol_bytes":123,"elapsed_ms":3}
    def receipt(self,kind,state,occurred,available):
        return {"scope":copy.deepcopy(self.scope),"kind":kind,"state":state,"period":"period-a","source_version":1,"occurred_at":occurred,"recorded_at":occurred,"first_available_at":available,"immutable":True,"origin":"immutable_journal_receipt"}
    def test_valid_pair_and_missing(self):
        sub=self.receipt("channel_subscription_observed","subscribed","2026-10-27T13:00:00Z","2026-10-27T13:00:01Z")
        ret=self.receipt("channel_retention_30m_observed","retained","2026-10-27T13:30:00Z","2026-10-27T13:30:01Z")
        self.data["rows"]=[sub,ret]
        self.assertEqual(gate.evaluate(self.data)["baseline"],{"subscription":"subscribed","retention_30m":"retained"})
        self.data["rows"]=[]
        self.assertEqual(gate.evaluate(self.data)["baseline"],{"subscription":"unknown","retention_30m":"unknown"})
    def test_late_availability_is_unknown(self):
        self.data["rows"]=[self.receipt("channel_subscription_observed","subscribed","2026-10-27T13:00:00Z","2026-10-27T14:36:00Z")]
        self.assertEqual(gate.evaluate(self.data)["baseline"]["subscription"],"unknown")
    def test_current_state_and_missing_first_available_rejected(self):
        r=self.receipt("channel_subscription_observed","subscribed","2026-10-27T13:00:00Z","2026-10-27T13:01:00Z")
        r["origin"]="current_state"; self.data["rows"]=[r]
        with self.assertRaises(gate.Invalid): gate.evaluate(self.data)
        r["origin"]="immutable_journal_receipt"; r["first_available_at"]=None
        with self.assertRaises(gate.Invalid): gate.evaluate(self.data)
    def test_excluded_and_mismatch_and_ambiguous(self):
        self.data["admission"]["not_revoked"]=False
        with self.assertRaises(gate.Invalid): gate.evaluate(self.data)
        self.data["admission"]["not_revoked"]=True
        r=self.receipt("channel_subscription_observed","subscribed","2026-10-27T13:00:00Z","2026-10-27T13:01:00Z")
        r["scope"]["person"]="other";self.data["rows"]=[r]
        with self.assertRaises(gate.Invalid): gate.evaluate(self.data)
        r["scope"]["person"]="synthetic";self.data["rows"]=[r,copy.deepcopy(r)]
        with self.assertRaises(gate.Invalid): gate.evaluate(self.data)
    def test_bounds_and_retention_period(self):
        self.data["rows"]=[{}]*501
        with self.assertRaises(gate.Invalid): gate.evaluate(self.data)
        self.data["rows"]=[];self.data["protocol_bytes"]=65537
        with self.assertRaises(gate.Invalid): gate.evaluate(self.data)
        self.data["protocol_bytes"]=10;self.data["elapsed_ms"]=5001
        with self.assertRaises(gate.Invalid): gate.evaluate(self.data)
        self.data["elapsed_ms"]=1
        self.data["rows"]=[self.receipt("channel_subscription_observed","subscribed","2026-10-27T13:00:00Z","2026-10-27T13:01:00Z"),self.receipt("channel_retention_30m_observed","retained","2026-10-27T13:30:00Z","2026-10-27T13:31:00Z")]
        self.data["rows"][1]["period"]="different"
        with self.assertRaises(gate.Invalid): gate.evaluate(self.data)

if __name__ == "__main__": unittest.main(verbosity=2)
