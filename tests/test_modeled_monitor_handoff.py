#!/usr/bin/env python3
"""Filesystem and protocol controls for the one-time handoff; no server or wire."""
import contextlib
import copy
import fcntl
import io
import json
import os
from pathlib import Path
import stat
import sys
import tempfile
import time
from types import SimpleNamespace
import unittest
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "tools"))
sys.dont_write_bytecode = True
import modeled_conversion_monitor_handoff as h

OP = "12345678901"
SHA = "a" * 40
ARTIFACT = "23456789012"
REAL_BASELINE = h._baseline

def donor():
    return {"schema_version": 1, "project": "anytour", "counter_id": "98615635", "role": "pipeline",
            "recipient": "654321", "token": "123456:" + "a" * 35, "initialized": True, "verified": True,
            "pending": None, "incident": None,
            "last_notification": {"kind": "bootstrap", "intent_id": "a" * 32,
                                  "message_id": 123, "confirmed_at": int(time.time()) - 300}}

def pending():
    return {"kind": "failure", "state": "unknown", "intent_id": "b" * 32, "owner_run": "1",
            "health_status": "degraded", "flags": ["profile_tick_stale"]}

class HandoffTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="modeled-handoff-fixture-")
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name) / "app"
        self.root.mkdir()
        self.value = donor()
        self.directory = self.root.parent / "max-search-bot-modeled-pipeline"
        self.lock = self.root.parent / ".pipeline-handoff.lock"
        self.stack = contextlib.ExitStack()
        self.addCleanup(self.stack.close)
        for name, value in (("ROOT", self.root), ("PRIVATE_DIR", self.directory), ("LOCK_FILE", self.lock)):
            self.stack.enter_context(mock.patch.object(h, name, value))
        self.baseline = self.stack.enter_context(mock.patch.object(h, "_baseline"))
        self.source = self.stack.enter_context(mock.patch.object(h, "_source"))
        self.cron = self.stack.enter_context(mock.patch.object(h, "_cron", return_value=(0, False)))
        self.wire = self.stack.enter_context(mock.patch.object(h.monitor, "telegram_wire", side_effect=AssertionError("wire_forbidden")))
        self.addCleanup(lambda: self.wire.assert_not_called())

    def state_bytes(self):
        return (self.directory / "runtime-state.json").read_bytes()

    def stage(self):
        return h.stage(OP, SHA, copy.deepcopy(self.value))

    def activate(self):
        self.cron.side_effect = [(0, False), (1, True)]
        with mock.patch.object(h.subprocess, "run", return_value=SimpleNamespace(returncode=0)) as run:
            out = h.activate(OP, SHA, ARTIFACT)
        self.cron.side_effect = None
        self.cron.return_value = (1, True)
        return out, run

    def test_stage_is_inactive_private_exact_and_has_no_cron_install(self):
        value = copy.deepcopy(self.value)
        with mock.patch.object(h.subprocess, "run", side_effect=AssertionError("process_forbidden")):
            out = h.stage(OP, SHA, value)
        self.assertTrue(out["ok"] and out["staged"] and out["state_imported"])
        self.assertFalse(out["configured"] or out["activated"])
        self.assertEqual(set(p.name for p in self.directory.iterdir()), {"runtime-state.json", "cron.lock", "handoff.json"})
        self.assertEqual(json.loads(self.state_bytes()), value)
        self.assertEqual(stat.S_IMODE(self.directory.stat().st_mode), 0o700)
        for path in self.directory.iterdir():
            self.assertEqual(stat.S_IMODE(path.stat().st_mode), 0o600)
        self.baseline.assert_called_once()
        self.assertNotIn(value["recipient"], json.dumps(out))
        self.assertNotIn(value["token"], json.dumps(out))

    def test_state_admission_never_creates_target_for_unconfirmed_donor(self):
        for mutate in (
            lambda s: s.update(role="external"),
            lambda s: s.update(pending=pending()),
            lambda s: s.update(initialized=False),
            lambda s: s.update(verified=False),
            lambda s: s.pop("pending"),
            lambda s: s.pop("last_notification"),
            lambda s: s["last_notification"].update(confirmed_at=True),
        ):
            with self.subTest(mutate=mutate):
                value = copy.deepcopy(self.value)
                mutate(value)
                with self.assertRaises(Exception):
                    h.stage(OP, SHA, value)
                self.assertFalse(self.directory.exists())
        self.baseline.assert_not_called()

    def test_same_operation_reconciliation_does_not_reimport_or_recheck_baseline(self):
        self.stage()
        before = {p.name: p.read_bytes() for p in self.directory.iterdir()}
        self.baseline.reset_mock()
        with mock.patch.object(h, "_new_file", side_effect=AssertionError("write_forbidden")):
            out = h.stage(OP, SHA, copy.deepcopy(self.value))
        self.assertTrue(out["ok"])
        self.assertEqual(before, {p.name: p.read_bytes() for p in self.directory.iterdir()})
        self.baseline.assert_not_called()

    def test_changed_operation_or_donor_never_overwrites_existing_state(self):
        self.stage()
        before = self.state_bytes()
        with self.assertRaises(Exception):
            h.stage("12345678902", SHA, copy.deepcopy(self.value))
        changed = copy.deepcopy(self.value)
        changed["recipient"] = "67890"
        with self.assertRaises(Exception):
            h.stage(OP, SHA, changed)
        self.assertEqual(before, self.state_bytes())

    def test_existing_empty_target_and_symlink_are_not_replaced(self):
        self.directory.mkdir(mode=0o700)
        with self.assertRaises(Exception):
            self.stage()
        self.assertEqual(list(self.directory.iterdir()), [])
        self.directory.rmdir()
        foreign = self.root.parent / "foreign"
        foreign.mkdir(mode=0o700)
        self.directory.symlink_to(foreign, target_is_directory=True)
        with self.assertRaises(Exception):
            self.stage()
        self.assertTrue(self.directory.is_symlink())
        self.assertEqual(list(foreign.iterdir()), [])

    def test_atomic_noreplace_preserves_existing_target(self):
        source, target = self.root.parent / "source", self.root.parent / "target"
        source.mkdir(mode=0o700)
        target.mkdir(mode=0o700)
        (source / "new").write_text("synthetic")
        with self.assertRaises(Exception):
            h._rename_absent(source, target)
        self.assertTrue((source / "new").exists())
        self.assertEqual(list(target.iterdir()), [])

    def test_activation_has_strict_marker_and_separate_ack_before_fixed_reconcile(self):
        self.stage()
        before = self.state_bytes()
        observed = []
        def reconcile(args, **kwargs):
            self.assertEqual(args, ["/bin/bash", "tools/reconcile_modeled_conversion_monitor_cron.sh"])
            self.assertEqual(kwargs["timeout"], 20)
            self.assertEqual(kwargs["cwd"], str(self.root))
            marker = json.loads((self.directory / "activation.json").read_bytes())
            proof = json.loads((self.directory / "activation-proof.json").read_bytes())
            self.assertEqual(set(marker), set(h.BASE) | {"operation", "activated_at", "source_sha", "state_imported"})
            self.assertEqual(proof["external_intent_artifact"], ARTIFACT)
            self.assertNotIn("external_intent_artifact", marker)
            observed.append(True)
            return SimpleNamespace(returncode=0)
        self.cron.side_effect = [(0, False), (1, True)]
        with mock.patch.object(h.subprocess, "run", side_effect=reconcile):
            out = h.activate(OP, SHA, ARTIFACT)
        self.assertTrue(out["ok"] and out["activated"] and out["configured"])
        self.assertEqual(out["cron_count"], 1)
        self.assertEqual(observed, [True])
        self.assertEqual(before, self.state_bytes())

    def test_failed_reconcile_preserves_import_marker_and_ack_for_forward_repair(self):
        self.stage()
        before = self.state_bytes()
        with mock.patch.object(h.subprocess, "run", return_value=SimpleNamespace(returncode=1)):
            with self.assertRaises(Exception):
                h.activate(OP, SHA, ARTIFACT)
        self.assertTrue((self.directory / "activation.json").exists())
        self.assertTrue((self.directory / "activation-proof.json").exists())
        self.assertEqual(before, self.state_bytes())
        out = h._status(OP, SHA)
        self.assertTrue(out["activated"])
        self.assertFalse(out["ok"])
        self.assertEqual(out["cron_count"], 0)

    def test_active_reconciliation_is_readonly_and_keeps_unknown_pending(self):
        self.stage()
        self.activate()
        value = json.loads(self.state_bytes())
        value["pending"] = pending()
        (self.directory / "runtime-state.json").write_bytes(h.monitor.canonical_bytes(value))
        before = self.state_bytes()
        with mock.patch.object(h.subprocess, "run", side_effect=AssertionError("process_forbidden")):
            out = h.activate(OP, SHA, ARTIFACT)
        self.assertTrue(out["ok"] and out["activated"])
        self.assertEqual(before, self.state_bytes())
        with self.assertRaises(Exception):
            h.activate(OP, SHA, "23456789013")

    def test_existing_owned_cron_blocks_initial_stage_or_activation(self):
        self.cron.return_value = (1, True)
        with self.assertRaises(Exception):
            self.stage()
        self.assertFalse(self.directory.exists())
        self.cron.return_value = (0, False)
        self.stage()
        self.cron.return_value = (1, False)
        with self.assertRaises(Exception):
            h.activate(OP, SHA, ARTIFACT)
        self.assertFalse((self.directory / "activation.json").exists())

    def test_absent_status_never_creates_directory_or_lock(self):
        self.assertIsNone(h._lock(read_only=True))
        out = h._status(OP, SHA)
        self.assertTrue(out["ok"])
        self.assertFalse(out["staged"] or out["activated"])
        self.assertFalse(self.directory.exists() or self.lock.exists())

    def test_private_modes_and_duplicate_json_fail_closed(self):
        self.stage()
        (self.directory / "runtime-state.json").chmod(0o644)
        with self.assertRaises(Exception):
            h._status(OP, SHA)
        for raw in (b'{"x":1,"x":2}', b'{"x":NaN}', b'{"x":Infinity}'):
            with self.assertRaises(Exception):
                h._decode(raw)

    def test_quiet_health_admission_checks_fixed_tenant_flags_and_freshness(self):
        def run_for(health):
            def run(args, **kwargs):
                self.assertEqual(args, ["/usr/bin/php", "tools/modeled_conversion_monitor.php", "health"])
                self.assertEqual(kwargs["timeout"], 20)
                kwargs["stdout"].write(json.dumps(health).encode())
                return SimpleNamespace(returncode=0)
            return run
        health = {"schema_version": 1, "project": "anytour", "counter_id": "98615635",
                  "status": "ok", "flags": [], "checked_at": h._iso()}
        with mock.patch.object(h.subprocess, "run", side_effect=run_for(health)):
            REAL_BASELINE()
        for patch in ({"project": "other"}, {"flags": ["profile_tick_stale"]}, {"status": "unavailable"},
                      {"checked_at": "2020-01-01T00:00:00Z"}):
            with self.subTest(patch=patch), mock.patch.object(h.subprocess, "run", side_effect=run_for({**health, **patch})):
                with self.assertRaises(Exception):
                    REAL_BASELINE()

    def test_failed_private_persistence_cannot_activate_or_install_cron(self):
        with mock.patch.object(h, "_new_file", side_effect=OSError("synthetic_write_failed")), \
                mock.patch.object(h.subprocess, "run", side_effect=AssertionError("process_forbidden")):
            with self.assertRaises(Exception):
                self.stage()
        self.assertFalse(self.directory.exists())

    def test_invalid_cli_arguments_return_only_static_public_failure(self):
        output = io.StringIO()
        with contextlib.redirect_stdout(output):
            result = h.main(["activate", "--operation", "synthetic-private-value",
                             "--source-sha", SHA, "--external-intent-artifact", ARTIFACT])
        self.assertEqual(result, 1)
        receipt = json.loads(output.getvalue())
        self.assertFalse(receipt["ok"])
        self.assertNotIn("synthetic-private-value", output.getvalue())
        self.source.assert_not_called()
        self.assertFalse(self.directory.exists())

    def test_http_cgi_environment_refuses_before_source_lock_cron_or_state(self):
        for variable in ("REQUEST_METHOD", "GATEWAY_INTERFACE"):
            with self.subTest(variable=variable):
                output = io.StringIO()
                # Make the other root guards valid: this test must fail if the CLI boundary is removed.
                def realpath(path):
                    return str(self.root / "tools/modeled_conversion_monitor_handoff.py") if path == h.__file__ else str(self.root)
                with mock.patch.dict(os.environ, {variable: "synthetic-http"}, clear=False), \
                        mock.patch.object(h.os.path, "realpath", side_effect=realpath), \
                        mock.patch.object(h, "_lock") as lock, contextlib.redirect_stdout(output):
                    result = h.main(["status", "--operation", OP, "--source-sha", SHA])
                self.assertEqual(result, 1)
                self.assertFalse(json.loads(output.getvalue())["ok"])
                self.source.assert_not_called()
                self.cron.assert_not_called()
                lock.assert_not_called()
                self.assertFalse(self.directory.exists() or self.lock.exists())

    def test_oversized_valid_activation_marker_holds_without_changes(self):
        self.stage()
        self.activate()
        marker = self.directory / "activation.json"
        raw = marker.read_bytes()
        expanded = b" " * (2049 - len(raw)) + raw
        self.assertEqual(len(expanded), 2049)
        self.assertEqual(json.loads(expanded), json.loads(raw))
        marker.write_bytes(expanded)
        before = {p.name: p.read_bytes() for p in self.directory.iterdir()}
        with mock.patch.object(h, "_new_file", side_effect=AssertionError("write_forbidden")), \
                mock.patch.object(h.subprocess, "run", side_effect=AssertionError("process_forbidden")):
            with self.assertRaises(Exception):
                h._status(OP, SHA)
        self.assertEqual(before, {p.name: p.read_bytes() for p in self.directory.iterdir()})

if __name__ == "__main__":
    unittest.main()
