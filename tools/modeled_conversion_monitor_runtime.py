"""Inactive durable adapter for the canonical monitor; no CLI, collector or timer.

A coordinated, reviewed cutover must provision an existing initialized state in a
dedicated owner-only directory before this library can run. It never seeds or
imports state and never reads an SSH key. Its receipts are local observations,
not evidence of an external observer or a scheduled GitHub execution.
"""
import fcntl
import json
import os
import re
from datetime import datetime, timezone
from pathlib import Path
import stat
import time
import uuid

import modeled_conversion_monitor as monitor

KIND = "modeled_monitor_private_runtime_v1"
RECEIPT_KIND = "modeled_monitor_local_observations_v1"
STATE_FILE = "runtime-state.json"
LOCK_FILE = "runtime.lock"
RECEIPT_FILE = "runtime-observations.json"
MAX_STATE = 131072
MAX_HEALTH = 32768
MAX_RECEIPT = 32768
PIPELINE_RECEIPT_FILE = "pipeline-checks.json"
MAX_PIPELINE_RECEIPT = 4096

class RuntimeFailure(Exception):
    def __init__(self, reason):
        self.reason = reason
        super().__init__("private_monitor_runtime_failure")

def _object(pairs):
    value = {}
    for key, item in pairs:
        if key in value:
            raise ValueError("duplicate_private_field")
        value[key] = item
    return value

def _decode(data):
    def invalid_constant(_):
        raise ValueError("invalid_private_number")
    return json.loads(data, object_pairs_hook=_object, parse_constant=invalid_constant)

def _private_file(info):
    return stat.S_ISREG(info.st_mode) and stat.S_IMODE(info.st_mode) == 0o600 and info.st_uid == os.geteuid()

class DurableMonitorRuntime:
    def __init__(self, directory, health_supplier, wire, *, role, clock=time.time, receipt_context=None):
        if not callable(health_supplier) or not callable(wire) or not callable(clock):
            raise ValueError("runtime_dependencies_required")
        if role not in {"pipeline", "external"} or (receipt_context is not None and not callable(receipt_context)):
            raise ValueError("runtime_role_required")
        self.role = role
        self.receipt_context = receipt_context
        self.directory = Path(directory)
        self.health_supplier = health_supplier
        self.wire = wire
        self.clock = clock

    def _directory(self):
        fd = os.open(self.directory, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC)
        try:
            info = os.fstat(fd)
            if not stat.S_ISDIR(info.st_mode) or stat.S_IMODE(info.st_mode) != 0o700 or info.st_uid != os.geteuid():
                raise RuntimeFailure("private_storage_unavailable")
            try:
                os.stat("last-check.json", dir_fd=fd, follow_symlinks=False)
            except FileNotFoundError:
                return fd
            raise RuntimeFailure("dedicated_runtime_directory_required")
        except BaseException:
            os.close(fd)
            raise

    def _lock(self, directory_fd):
        created = False
        try:
            fd = os.open(LOCK_FILE, os.O_RDWR | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600, dir_fd=directory_fd)
            created = True
        except FileExistsError:
            fd = os.open(LOCK_FILE, os.O_RDWR | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=directory_fd)
        try:
            if created:
                os.fchmod(fd, 0o600)
            if not _private_file(os.fstat(fd)):
                raise RuntimeFailure("private_storage_unavailable")
            try:
                fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:
                raise RuntimeFailure("busy")
            return fd
        except BaseException:
            os.close(fd)
            raise

    def _read(self, directory_fd, name, limit, *, optional=False):
        try:
            fd = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=directory_fd)
        except FileNotFoundError:
            if optional:
                return None
            raise RuntimeFailure("initialized_state_required")
        with os.fdopen(fd, "rb") as handle:
            info = os.fstat(handle.fileno())
            if not _private_file(info) or info.st_size > limit:
                raise RuntimeFailure("private_storage_unavailable")
            data = handle.read(limit + 1)
        if len(data) > limit:
            raise RuntimeFailure("private_storage_unavailable")
        return _decode(data)

    def _target(self, directory_fd, name, required):
        try:
            info = os.stat(name, dir_fd=directory_fd, follow_symlinks=False)
        except FileNotFoundError:
            if required:
                raise RuntimeFailure("initialized_state_required")
            return
        if not _private_file(info):
            raise RuntimeFailure("private_storage_unavailable")

    def _atomic(self, directory_fd, name, value, limit, *, required):
        data = monitor.canonical_bytes(value)
        if len(data) > limit:
            raise RuntimeFailure("private_storage_unavailable")
        self._target(directory_fd, name, required)
        temporary = ".runtime-" + uuid.uuid4().hex
        fd = None
        try:
            fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600, dir_fd=directory_fd)
            os.fchmod(fd, 0o600)
            with os.fdopen(fd, "wb") as handle:
                fd = None
                handle.write(data)
                handle.flush()
                os.fsync(handle.fileno())
            self._target(directory_fd, name, required)
            os.replace(temporary, name, src_dir_fd=directory_fd, dst_dir_fd=directory_fd)
            os.fsync(directory_fd)
        finally:
            if fd is not None:
                os.close(fd)
            try:
                os.unlink(temporary, dir_fd=directory_fd)
            except FileNotFoundError:
                pass

    def _store(self, directory_fd, state):
        monitor.validate_state(state)
        monitor.role_guard(state, self.role)
        if state["initialized"] is not True or state["verified"] is not True:
            raise RuntimeFailure("initialized_state_required")
        self._atomic(directory_fd, STATE_FILE, state, MAX_STATE, required=True)

    @staticmethod
    def _sha(value):
        return isinstance(value, str) and re.fullmatch(r"[a-f0-9]{40}", value) is not None

    @staticmethod
    def _iso(value):
        return time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime(value))

    @staticmethod
    def _timestamp(value):
        if not isinstance(value, str) or len(value) != 20:
            raise RuntimeFailure("local_receipt_unavailable")
        try:
            parsed = datetime.strptime(value, "%Y-%m-%dT%H:%M:%SZ").replace(tzinfo=timezone.utc)
            result = int(parsed.timestamp())
        except (ValueError, OverflowError):
            raise RuntimeFailure("local_receipt_unavailable")
        if result < 946684800 or parsed.strftime("%Y-%m-%dT%H:%M:%SZ") != value:
            raise RuntimeFailure("local_receipt_unavailable")
        return result

    def _context(self):
        if self.receipt_context is None:
            return None
        context = self.receipt_context()
        if self.role != "pipeline" or not isinstance(context, dict) or set(context) != {"origin", "source_sha"} \
                or context["origin"] != "server_cron" or not self._sha(context["source_sha"]):
            raise RuntimeFailure("invalid_receipt_context")
        return context

    def _prior_receipt(self, directory_fd, context):
        if context is not None:
            return self._prior_pipeline_receipt(directory_fd)
        value = self._read(directory_fd, RECEIPT_FILE, MAX_RECEIPT, optional=True)
        if value is None:
            return []
        if not isinstance(value, dict) or value.get("schema_version") != 1 or value.get("kind") != RECEIPT_KIND \
                or value.get("source") != "local_runtime" or value.get("project") != monitor.PROJECT \
                or value.get("counter_id") != monitor.COUNTER or value.get("role") != self.role \
                or value.get("external_proof") is not False or value.get("scheduled_proof") is not False:
            raise RuntimeFailure("local_receipt_unavailable")
        recent = value.get("recent")
        if not isinstance(recent, list) or len(recent) > 3:
            raise RuntimeFailure("local_receipt_unavailable")
        seen, previous = set(), 0
        for item in recent:
            if not isinstance(item, dict) or set(item) != {"cycle_id", "checked_at_unix", "status", "flags", "notification", "ok"}:
                raise RuntimeFailure("local_receipt_unavailable")
            cycle = item["cycle_id"]
            if not isinstance(cycle, str) or re.fullmatch(r"[a-f0-9]{32}", cycle) is None or cycle in seen:
                raise RuntimeFailure("local_receipt_unavailable")
            seen.add(cycle)
            if type(item["checked_at_unix"]) is not int or item["checked_at_unix"] < 946684800 \
                    or item["checked_at_unix"] < previous or item["status"] not in monitor.STATUSES \
                    or item["notification"] not in {"quiet", "sent", "unknown"} or type(item["ok"]) is not bool:
                raise RuntimeFailure("local_receipt_unavailable")
            previous = item["checked_at_unix"]
            flags = monitor.clean_flags(item["flags"])
            if flags != item["flags"] or any(flag not in monitor.ROLE_FLAGS[self.role] for flag in flags):
                raise RuntimeFailure("local_receipt_unavailable")
        return recent

    def _prior_pipeline_receipt(self, directory_fd):
        value = self._read(directory_fd, PIPELINE_RECEIPT_FILE, MAX_PIPELINE_RECEIPT, optional=True)
        if value is None:
            return []
        keys = {"schema_version", "project", "counter_id", "role", "origin", "checked_at", "status", "flags",
                "notification", "last_notification_at", "source_sha", "cycle_id", "ok", "checks"}
        if not isinstance(value, dict) or set(value) != keys or value["schema_version"] != 1 \
                or value["project"] != monitor.PROJECT or value["counter_id"] != monitor.COUNTER \
                or value["role"] != "pipeline" or value["origin"] != "server_cron" \
                or not self._sha(value["source_sha"]) or not isinstance(value["cycle_id"], str) or re.fullmatch(r"[a-f0-9]{32}", value["cycle_id"]) is None \
                or value["status"] not in monitor.STATUSES or value["notification"] not in {"quiet", "sent", "unknown"} \
                or type(value["ok"]) is not bool or (value["notification"] == "unknown" and value["ok"]):
            raise RuntimeFailure("local_receipt_unavailable")
        checked = self._timestamp(value["checked_at"])
        if value["last_notification_at"] is not None and self._timestamp(value["last_notification_at"]) > checked:
            raise RuntimeFailure("local_receipt_unavailable")
        flags = monitor.clean_flags(value["flags"])
        if flags != value["flags"] or any(flag not in monitor.ROLE_FLAGS["pipeline"] for flag in flags):
            raise RuntimeFailure("local_receipt_unavailable")
        checks = value["checks"]
        if not isinstance(checks, list) or len(checks) > 3:
            raise RuntimeFailure("local_receipt_unavailable")
        seen, previous = set(), 0
        for item in checks:
            if not isinstance(item, dict) or set(item) != {"cycle_id", "checked_at", "status", "ok", "source_sha"} \
                    or not isinstance(item["cycle_id"], str) or re.fullmatch(r"[a-f0-9]{32}", item["cycle_id"]) is None or item["cycle_id"] in seen \
                    or item["status"] not in monitor.STATUSES or type(item["ok"]) is not bool \
                    or (item["ok"] and item["status"] not in {"ok", "off"}) or not self._sha(item["source_sha"]):
                raise RuntimeFailure("local_receipt_unavailable")
            moment = self._timestamp(item["checked_at"])
            if moment <= previous or moment > checked:
                raise RuntimeFailure("local_receipt_unavailable")
            previous = moment
            seen.add(item["cycle_id"])
        if checks:
            last = checks[-1]
            if any(last[key] != value[key] for key in ("cycle_id", "checked_at", "status", "source_sha")):
                raise RuntimeFailure("local_receipt_unavailable")
        return checks

    def _write_receipt(self, directory_fd, recent, output, state, now, cycle_id, context):
        if any(item["cycle_id"] == cycle_id for item in recent):
            raise RuntimeFailure("invalid_cycle_identity")
        if context is None:
            if recent and now < recent[-1]["checked_at_unix"]:
                raise RuntimeFailure("invalid_clock")
            observation = {"cycle_id": cycle_id, "checked_at_unix": now, "status": output["status"],
                           "flags": monitor.clean_flags(output["flags"]), "notification": output["notification"], "ok": output["ok"]}
            value = {"schema_version": 1, "kind": RECEIPT_KIND, "source": "local_runtime", "role": self.role,
                     "project": monitor.PROJECT, "counter_id": monitor.COUNTER,
                     "external_proof": False, "scheduled_proof": False, "recent": [*recent[-2:], observation]}
            self._atomic(directory_fd, RECEIPT_FILE, value, MAX_RECEIPT, required=False)
            return
        if recent and now <= self._timestamp(recent[-1]["checked_at"]):
            raise RuntimeFailure("invalid_clock")
        last_notification = state.get("last_notification")
        confirmed = last_notification.get("confirmed_at") if isinstance(last_notification, dict) else None
        if type(confirmed) is int and confirmed > now:
            raise RuntimeFailure("invalid_clock")
        notification_at = self._iso(confirmed) if type(confirmed) is int and confirmed >= 946684800 else None
        checked = self._iso(now)
        observation = {"cycle_id": cycle_id, "checked_at": checked, "status": output["status"],
                       "ok": output["ok"] is True and output["status"] in {"ok", "off"} and output["notification"] in {"quiet", "sent"},
                       "source_sha": context["source_sha"]}
        value = {"schema_version": 1, "project": monitor.PROJECT, "counter_id": monitor.COUNTER,
                 "role": "pipeline", "origin": "server_cron", "checked_at": checked, "status": output["status"],
                 "flags": monitor.clean_flags(output["flags"]), "notification": output["notification"],
                 "last_notification_at": notification_at, "source_sha": context["source_sha"],
                 "cycle_id": cycle_id, "ok": output["ok"] is True, "checks": [*recent[-2:], observation]}
        self._atomic(directory_fd, PIPELINE_RECEIPT_FILE, value, MAX_PIPELINE_RECEIPT, required=False)

    def _result(self, output=None, *, reason=None, recorded=False, attempted=False, cycle_id=None, context=None):
        if output is None:
            output = monitor.result("unavailable", ["configuration_unavailable"], "unknown" if attempted else "quiet")
        if attempted and output.get("intent_prepared") is True:
            output = monitor.result(output["status"], output["flags"], "unknown")
        result = dict(output)
        result.update(kind=KIND, source="local_runtime", role=self.role, external_proof=False, scheduled_proof=False,
                      origin="server_cron" if context is not None else "local_runtime",
                      runtime_status=reason or "recorded", receipt_recorded=recorded,
                      receipt_file=PIPELINE_RECEIPT_FILE if context is not None else RECEIPT_FILE)
        if cycle_id is not None:
            result["cycle_id"] = cycle_id
        if context is not None:
            result["source_sha"] = context["source_sha"]
        result["ok"] = output["ok"] is True and recorded and reason is None
        return result

    def _now(self):
        value = self.clock()
        if type(value) not in (int, float) or not 946684800 <= value <= 4102444800:
            raise RuntimeFailure("invalid_clock")
        return int(value)

    def cycle(self):
        directory_fd = lock_fd = None
        attempted = False
        output = context = cycle_id = None
        try:
            directory_fd = self._directory()
            lock_fd = self._lock(directory_fd)
            state = self._read(directory_fd, STATE_FILE, MAX_STATE)
            monitor.validate_state(state)
            monitor.role_guard(state, self.role)
            if state["initialized"] is not True or state["verified"] is not True:
                raise RuntimeFailure("initialized_state_required")
            context = self._context()
            recent = self._prior_receipt(directory_fd, context)
            now = self._now()
            if recent:
                previous = self._timestamp(recent[-1]["checked_at"]) if context is not None else recent[-1]["checked_at_unix"]
                if now < previous:
                    raise RuntimeFailure("invalid_clock")
            cycle = uuid.uuid4()
            cycle_id, owner_run = cycle.hex, str(cycle.int)
            try:
                health = self.health_supplier()
                if len(monitor.canonical_bytes(health)) > MAX_HEALTH:
                    raise ValueError("bounded_health_required")
            except Exception:
                health = {"schema_version": 1, "project": monitor.PROJECT, "counter_id": monitor.COUNTER,
                          "status": "unavailable", "flags": ["health_metadata_unavailable"]}

            def save(value):
                self._store(directory_fd, value)

            def wire(token, method, payload):
                nonlocal attempted
                attempted = True
                return self.wire(token, method, payload)

            state, output = monitor.prepare(health, None, state, state["recipient"], wire, owner_run, now=now, role=self.role)
            save(state)
            if output["intent_prepared"]:
                state, output = monitor.send(state, state["recipient"], wire, save, owner_run,
                                             prepared_this_run=True, now=self._now(), role=self.role)
            checked = self._now()
            if checked < now:
                raise RuntimeFailure("invalid_clock")
            try:
                self._write_receipt(directory_fd, recent, output, state, checked, cycle_id, context)
            except Exception:
                return self._result(output, reason="local_receipt_unavailable", attempted=attempted, cycle_id=cycle_id, context=context)
            return self._result(output, recorded=True, attempted=attempted, cycle_id=cycle_id, context=context)
        except RuntimeFailure as error:
            return self._result(output, reason=error.reason, attempted=attempted, cycle_id=cycle_id, context=context)
        except Exception:
            return self._result(reason="private_runtime_unavailable", attempted=attempted, cycle_id=cycle_id, context=context)
        finally:
            if lock_fd is not None:
                try:
                    fcntl.flock(lock_fd, fcntl.LOCK_UN)
                finally:
                    os.close(lock_fd)
            if directory_fd is not None:
                os.close(directory_fd)
