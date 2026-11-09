#!/usr/bin/env python3
"""Guarded local checker entry; no state import, activation or collector configuration."""
import argparse
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import time

sys.dont_write_bytecode = True
import modeled_conversion_monitor as monitor
import modeled_conversion_monitor_runtime as runtime

ROOT = Path("/var/www/anytoour/data/www/app.anytoour.ru")
PRIVATE_DIR = ROOT.parent / "max-search-bot-modeled-pipeline"
ACTIVATION_FILE = "activation.json"

def _status(configured, reason=None):
    out = {"schema_version": 1, "project": monitor.PROJECT, "counter_id": monitor.COUNTER,
           "role": "pipeline", "configured": bool(configured)}
    if reason is not None:
        out["reason"] = reason
    return out

def _validate_activation(value):
    fields = {"schema_version", "project", "counter_id", "role", "operation",
              "activated_at", "source_sha", "state_imported"}
    if not isinstance(value, dict) or set(value) != fields or type(value["schema_version"]) is not int \
            or value["schema_version"] != 1 or value["project"] != monitor.PROJECT \
            or value["counter_id"] != monitor.COUNTER or value["role"] != "pipeline" \
            or value["state_imported"] is not True or not isinstance(value["operation"], str) \
            or re.fullmatch(r"[1-9][0-9]{0,19}", value["operation"]) is None \
            or not runtime.DurableMonitorRuntime._sha(value["source_sha"]):
        raise ValueError("invalid_pipeline_activation")
    if runtime.DurableMonitorRuntime._timestamp(value["activated_at"]) > int(time.time()) + 60:
        raise ValueError("invalid_pipeline_activation")

def configuration():
    """Read current private configuration without a lock, route output or mutation."""
    reader = runtime.DurableMonitorRuntime(PRIVATE_DIR, lambda: None, lambda *args: None, role="pipeline")
    directory_fd = None
    try:
        try:
            directory_fd = reader._directory()
        except FileNotFoundError:
            return _status(False, "not_activated")
        reader._target(directory_fd, runtime.LOCK_FILE, False)
        reader._target(directory_fd, "cron.lock", False)
        reader._prior_pipeline_receipt(directory_fd)
        marker = reader._read(directory_fd, ACTIVATION_FILE, 2048, optional=True)
        state = reader._read(directory_fd, runtime.STATE_FILE, runtime.MAX_STATE, optional=True)
        if state is not None:
            monitor.validate_state(state)
            monitor.role_guard(state, "pipeline")
            if state["initialized"] is not True or state["verified"] is not True:
                raise ValueError("pipeline_state_not_initialized")
        if marker is None:
            return _status(False, "not_activated")
        _validate_activation(marker)
        if state is None:
            raise ValueError("pipeline_state_missing")
        return _status(True)
    finally:
        if directory_fd is not None:
            os.close(directory_fd)

def _root_valid():
    return Path(os.path.realpath(os.getcwd())) == ROOT and Path(os.path.realpath(__file__)).parent.parent == ROOT

def _source_sha():
    completed = subprocess.run(
        ["/usr/bin/git", "rev-parse", "HEAD"], cwd=str(ROOT), stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL, timeout=3, check=False, env={"PATH": "/usr/bin:/bin", "LC_ALL": "C"})
    if completed.returncode != 0 or len(completed.stdout) > 128:
        raise ValueError("source_unavailable")
    value = completed.stdout.decode("ascii").strip()
    if not runtime.DurableMonitorRuntime._sha(value):
        raise ValueError("source_unavailable")
    return value

def _unavailable_health():
    return {"schema_version": 1, "project": monitor.PROJECT, "counter_id": monitor.COUNTER,
            "status": "unavailable", "flags": ["health_metadata_unavailable"]}

def pipeline_health():
    """Invoke only the fixed read-only PHP health operation; never collect a route."""
    try:
        with tempfile.TemporaryFile(mode="w+b") as output:
            completed = subprocess.run(
                ["/usr/bin/php", "tools/modeled_conversion_monitor.php", "health"], cwd=str(ROOT),
                stdout=output, stderr=subprocess.DEVNULL, timeout=20, check=False)
            output.seek(0)
            raw = output.read(runtime.MAX_HEALTH + 1)
        if completed.returncode != 0 or not raw or len(raw) > runtime.MAX_HEALTH:
            return _unavailable_health()
        value = runtime._decode(raw)
        if not monitor.scoped(value) or value.get("status") not in monitor.STATUSES:
            return _unavailable_health()
        flags = monitor.clean_flags(value.get("flags"))
        if any(flag not in monitor.ROLE_FLAGS["pipeline"] for flag in flags) \
                or (value["status"] == "ok" and flags):
            return _unavailable_health()
        return {"schema_version": 1, "project": monitor.PROJECT, "counter_id": monitor.COUNTER,
                "status": value["status"], "flags": flags}
    except Exception:
        return _unavailable_health()


def refresh_visit_proof(elapsed):
    """Best-effort derived GET verification after alerts, inside the existing 45s cron budget."""
    budget = min(20, int(39 - elapsed))
    if budget < 5:
        return "no_time_budget"
    try:
        with tempfile.TemporaryFile(mode="w+b") as output:
            completed = subprocess.run(
                ["/usr/bin/php", "tools/modeled_conversion_visit_proof.php", "scheduled", str(budget)],
                cwd=str(ROOT), stdout=output, stderr=subprocess.DEVNULL, timeout=budget + 2, check=False)
            output.seek(0)
            raw = output.read(1025)
        value = runtime._decode(raw) if 0 < len(raw) <= 1024 else None
        if completed.returncode != 0 or not isinstance(value, dict) or set(value) != {
                "ok", "private_verification_saved", "provider_write_requests", "source_writes"} \
                or value["ok"] is not True or type(value["private_verification_saved"]) is not bool \
                or value["provider_write_requests"] is not False or value["source_writes"] is not False:
            return "unavailable"
        return "saved" if value["private_verification_saved"] else "skipped"
    except Exception:
        return "unavailable"

def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    action = parser.add_mutually_exclusive_group(required=True)
    action.add_argument("--cron", action="store_true")
    action.add_argument("--status", action="store_true")
    args = parser.parse_args(argv)
    try:
        status = configuration()
        if args.status:
            print(json.dumps(status, sort_keys=True, separators=(",", ":")))
            return 0
        if os.environ.get("MODELED_MONITOR_TRIGGER") != "cron" or not _root_valid():
            raise ValueError("trusted_cron_origin_required")
        if status["configured"] is not True:
            print(json.dumps(status, sort_keys=True, separators=(",", ":")))
            return 1
        started = time.monotonic()
        source_sha = _source_sha()
        checker = runtime.DurableMonitorRuntime(
            PRIVATE_DIR, pipeline_health, monitor.telegram_wire, role="pipeline", clock=time.time,
            receipt_context=lambda: {"origin": "server_cron", "source_sha": source_sha})
        result = checker.cycle()
        result["visit_proof_refresh"] = refresh_visit_proof(time.monotonic() - started)
        print(json.dumps(result, sort_keys=True, separators=(",", ":")))
        return 0 if result["ok"] is True else 1
    except Exception:
        out = _status(False, "configuration_unavailable")
        out["ok"] = False
        print(json.dumps(out, sort_keys=True, separators=(",", ":")))
        return 1

if __name__ == "__main__":
    raise SystemExit(main())
