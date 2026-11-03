#!/usr/bin/env python3
"""One controlled AnyTour pipeline handoff; never checks a cycle or sends a message."""
import argparse
import ctypes
from datetime import datetime, timezone
import errno
import fcntl
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import tempfile
import time

sys.dont_write_bytecode = True
import modeled_conversion_monitor as monitor

ROOT = Path("/var/www/anytoour/data/www/app.anytoour.ru")
PRIVATE_DIR = ROOT.parent / "max-search-bot-modeled-pipeline"
LOCK_FILE = ROOT.parent / ".max-search-bot-modeled-pipeline-handoff.lock"
MAX_STATE = 16384
ENV = {"PATH": "/usr/bin:/bin", "LC_ALL": "C"}
BASE = {"schema_version": 1, "project": "anytour", "counter_id": "98615635", "role": "pipeline"}

class HandoffFailure(Exception):
    pass

def _positive(value):
    return isinstance(value, str) and re.fullmatch(r"[1-9][0-9]{0,19}", value) is not None

def _sha(value):
    return isinstance(value, str) and re.fullmatch(r"[0-9a-f]{40}", value) is not None

def _decode(raw):
    def pairs(items):
        out = {}
        for key, value in items:
            if key in out:
                raise HandoffFailure("invalid_metadata")
            out[key] = value
        return out
    def invalid(_):
        raise HandoffFailure("invalid_metadata")
    return json.loads(raw, object_pairs_hook=pairs, parse_constant=invalid)

def _timestamp(value):
    if not isinstance(value, str) or len(value) != 20:
        raise HandoffFailure("invalid_metadata")
    parsed = datetime.strptime(value, "%Y-%m-%dT%H:%M:%SZ").replace(tzinfo=timezone.utc)
    if parsed.strftime("%Y-%m-%dT%H:%M:%SZ") != value:
        raise HandoffFailure("invalid_metadata")
    stamp = int(parsed.timestamp())
    if stamp < 946684800 or stamp > int(time.time()) + 60:
        raise HandoffFailure("invalid_metadata")
    return stamp

def _iso():
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")

def _file_info(info):
    return stat.S_ISREG(info.st_mode) and stat.S_IMODE(info.st_mode) == 0o600 and info.st_uid == os.geteuid() and info.st_nlink == 1

def _directory(path):
    fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC)
    try:
        info = os.fstat(fd)
        if not stat.S_ISDIR(info.st_mode) or stat.S_IMODE(info.st_mode) != 0o700 or info.st_uid != os.geteuid():
            raise HandoffFailure("private_storage_unavailable")
        return fd
    except BaseException:
        os.close(fd)
        raise

def _read(directory_fd, name, limit, optional=False):
    try:
        fd = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=directory_fd)
    except FileNotFoundError:
        if optional:
            return None
        raise HandoffFailure("private_storage_unavailable")
    with os.fdopen(fd, "rb") as handle:
        info = os.fstat(handle.fileno())
        if not _file_info(info) or info.st_size > limit:
            raise HandoffFailure("private_storage_unavailable")
        raw = handle.read(limit + 1)
    if len(raw) > limit:
        raise HandoffFailure("private_storage_unavailable")
    return _decode(raw)

def _new_file(directory_fd, name, data):
    fd = os.open(name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600, dir_fd=directory_fd)
    with os.fdopen(fd, "wb") as handle:
        os.fchmod(handle.fileno(), 0o600)
        handle.write(data)
        handle.flush()
        os.fsync(handle.fileno())
    os.fsync(directory_fd)

def _rename_absent(source, target):
    libc = ctypes.CDLL(None, use_errno=True)
    rename = getattr(libc, "renameat2", None)
    if rename is None:
        raise HandoffFailure("atomic_install_unavailable")
    rename.argtypes = [ctypes.c_int, ctypes.c_char_p, ctypes.c_int, ctypes.c_char_p, ctypes.c_uint]
    rename.restype = ctypes.c_int
    if rename(-100, os.fsencode(source), -100, os.fsencode(target), 1) != 0:
        code = ctypes.get_errno()
        if code in (errno.EEXIST, errno.ENOTEMPTY):
            raise HandoffFailure("target_already_exists")
        raise HandoffFailure("atomic_install_unavailable")

def _new_json(directory_fd, name, value):
    raw = monitor.canonical_bytes(value)
    if len(raw) > 4096:
        raise HandoffFailure("invalid_metadata")
    temporary = ".handoff-" + os.urandom(16).hex()
    _new_file(directory_fd, temporary, raw)
    # A same-directory no-replace rename makes a complete file visible atomically.
    directory_path = Path(os.readlink("/proc/self/fd/" + str(directory_fd)))
    _rename_absent(directory_path / temporary, directory_path / name)
    os.fsync(directory_fd)

def _lock(read_only=False):
    flags = os.O_RDONLY if read_only else os.O_RDWR | os.O_CREAT
    try:
        fd = os.open(LOCK_FILE, flags | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
    except FileNotFoundError:
        if read_only:
            return None
        raise
    try:
        if not _file_info(os.fstat(fd)):
            raise HandoffFailure("private_storage_unavailable")
        try:
            fcntl.flock(fd, (fcntl.LOCK_SH if read_only else fcntl.LOCK_EX) | fcntl.LOCK_NB)
        except BlockingIOError:
            raise HandoffFailure("handoff_busy")
        return fd
    except BaseException:
        os.close(fd)
        raise

def _source(expected):
    completed = subprocess.run(["/usr/bin/git", "rev-parse", "HEAD"], cwd=str(ROOT),
        stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=5, check=False, env=ENV)
    if completed.returncode != 0 or len(completed.stdout) > 128:
        raise HandoffFailure("source_unavailable")
    actual = completed.stdout.decode("ascii").strip()
    if not _sha(actual) or actual != expected:
        raise HandoffFailure("source_changed")

def _state(value, quiet):
    monitor.validate_state(value)
    monitor.role_guard(value, "pipeline")
    if value.get("initialized") is not True or value.get("verified") is not True:
        raise HandoffFailure("initialized_state_required")
    if quiet and ("pending" not in value or "incident" not in value or value["pending"] is not None or value["incident"] is not None):
        raise HandoffFailure("quiet_state_required")
    last = value.get("last_notification")
    if not isinstance(last, dict) or last.get("kind") not in {"bootstrap", "failure", "recovery"} \
            or not isinstance(last.get("intent_id"), str) or re.fullmatch(r"[0-9a-f]{32}", last["intent_id"]) is None \
            or type(last.get("message_id")) is not int or last["message_id"] <= 0 \
            or type(last.get("confirmed_at")) is not int or not 946684800 <= last["confirmed_at"] <= int(time.time()) + 60:
        raise HandoffFailure("confirmed_state_required")
    return value

def _baseline():
    with tempfile.TemporaryFile(mode="w+b") as output:
        completed = subprocess.run(["/usr/bin/php", "tools/modeled_conversion_monitor.php", "health"],
            cwd=str(ROOT), stdout=output, stderr=subprocess.DEVNULL, timeout=20, check=False, env=ENV)
        output.seek(0)
        raw = output.read(32769)
    if completed.returncode != 0 or not raw or len(raw) > 32768:
        raise HandoffFailure("healthy_baseline_required")
    health = _decode(raw)
    if not monitor.scoped(health) or health.get("status") not in {"ok", "off"} or health.get("flags") != [] \
            or abs(int(time.time()) - _timestamp(health.get("checked_at"))) > 120:
        raise HandoffFailure("healthy_baseline_required")

def _cron():
    with tempfile.TemporaryFile(mode="w+b") as output:
        completed = subprocess.run(["/usr/bin/crontab", "-l"], stdout=output, stderr=subprocess.DEVNULL,
            timeout=10, check=False, env=ENV)
        output.seek(0)
        raw = output.read(65537)
    if completed.returncode != 0 or len(raw) > 65536:
        raise HandoffFailure("cron_unavailable")
    text = raw.decode("utf-8")
    needle = str(ROOT / "tools/modeled_conversion_monitor_local.py") + " --cron"
    lines = [line for line in text.splitlines() if not line.lstrip().startswith("#") and needle in line]
    expected = "*/5 * * * * MODELED_MONITOR_TRIGGER=cron /usr/bin/flock -n " + str(PRIVATE_DIR) \
        + "/cron.lock /usr/bin/timeout 45s /usr/bin/python3 " + needle + " >/dev/null 2>&1"
    return len(lines), lines == [expected]

def _metadata(directory_fd, operation, source_sha):
    value = _read(directory_fd, "handoff.json", 4096)
    keys = set(BASE) | {"operation", "source_sha", "imported_at", "state_imported"}
    if not isinstance(value, dict) or set(value) != keys or type(value.get("schema_version")) is not int or any(value.get(k) != v for k, v in BASE.items()) \
            or value.get("operation") != operation or value.get("source_sha") != source_sha or value.get("state_imported") is not True:
        raise HandoffFailure("handoff_scope_changed")
    _timestamp(value["imported_at"])
    return value

def _marker(directory_fd, operation, source_sha):
    value = _read(directory_fd, "activation.json", 2048, optional=True)
    if value is None:
        return None
    keys = set(BASE) | {"operation", "activated_at", "source_sha", "state_imported"}
    if not isinstance(value, dict) or set(value) != keys or type(value.get("schema_version")) is not int or any(value.get(k) != v for k, v in BASE.items()) \
            or value.get("operation") != operation or value.get("source_sha") != source_sha or value.get("state_imported") is not True:
        raise HandoffFailure("activation_scope_changed")
    _timestamp(value["activated_at"])
    return value

def _status(operation, source_sha):
    try:
        directory_fd = _directory(PRIVATE_DIR)
    except FileNotFoundError:
        count, _ = _cron()
        return {**BASE, "operation": operation, "source_sha": source_sha, "ok": count == 0,
                "staged": False, "configured": False, "activated": False, "state_imported": False, "cron_count": count}
    try:
        _metadata(directory_fd, operation, source_sha)
        marker = _marker(directory_fd, operation, source_sha)
        _state(_read(directory_fd, "runtime-state.json", MAX_STATE), quiet=False)
        count, exact = _cron()
        return {**BASE, "operation": operation, "source_sha": source_sha, "ok": exact if marker is not None else count == 0,
                "staged": True, "configured": marker is not None, "activated": marker is not None, "state_imported": True, "cron_count": count}
    finally:
        os.close(directory_fd)

def stage(operation, source_sha, donor):
    _state(donor, quiet=True)
    if PRIVATE_DIR.exists() or PRIVATE_DIR.is_symlink():
        directory_fd = _directory(PRIVATE_DIR)
        try:
            _metadata(directory_fd, operation, source_sha)
            marker = _marker(directory_fd, operation, source_sha)
            stored = _state(_read(directory_fd, "runtime-state.json", MAX_STATE), quiet=marker is None)
            if marker is None and stored != donor:
                raise HandoffFailure("imported_state_changed")
        finally:
            os.close(directory_fd)
        return _status(operation, source_sha)
    count, _ = _cron()
    if count != 0:
        raise HandoffFailure("existing_monitor_cron")
    _baseline()
    staging = Path(tempfile.mkdtemp(prefix=".modeled-pipeline-stage-" + operation + "-", dir=str(ROOT.parent)))
    os.chmod(staging, 0o700)
    directory_fd = _directory(staging)
    try:
        _new_file(directory_fd, "runtime-state.json", monitor.canonical_bytes(donor))
        _new_file(directory_fd, "cron.lock", b"")
        _new_json(directory_fd, "handoff.json", {**BASE, "operation": operation, "source_sha": source_sha,
                  "imported_at": _iso(), "state_imported": True})
        if _read(directory_fd, "runtime-state.json", MAX_STATE) != donor:
            raise HandoffFailure("import_verification_failed")
    finally:
        os.close(directory_fd)
    _source(source_sha)
    _rename_absent(staging, PRIVATE_DIR)
    parent_fd = os.open(ROOT.parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC)
    try:
        os.fsync(parent_fd)
    finally:
        os.close(parent_fd)
    directory_fd = _directory(PRIVATE_DIR)
    try:
        if _read(directory_fd, "runtime-state.json", MAX_STATE) != donor:
            raise HandoffFailure("import_verification_failed")
    finally:
        os.close(directory_fd)
    return _status(operation, source_sha)

def activate(operation, source_sha, artifact):
    directory_fd = _directory(PRIVATE_DIR)
    try:
        _metadata(directory_fd, operation, source_sha)
        marker = _marker(directory_fd, operation, source_sha)
        _state(_read(directory_fd, "runtime-state.json", MAX_STATE), quiet=marker is None)
        proof = {**BASE, "operation": operation, "source_sha": source_sha, "external_intent_artifact": artifact}
        old_proof = _read(directory_fd, "activation-proof.json", 4096, optional=True)
        if old_proof is not None and old_proof != proof:
            raise HandoffFailure("external_intent_changed")
        if marker is not None:
            if old_proof is None:
                raise HandoffFailure("external_intent_unconfirmed")
            return _status(operation, source_sha)
        count, _ = _cron()
        if count != 0:
            raise HandoffFailure("existing_monitor_cron")
        _source(source_sha)
        if old_proof is None:
            _new_json(directory_fd, "activation-proof.json", proof)
        _new_json(directory_fd, "activation.json", {**BASE, "operation": operation,
                  "activated_at": _iso(), "source_sha": source_sha, "state_imported": True})
    finally:
        os.close(directory_fd)
    completed = subprocess.run(["/bin/bash", "tools/reconcile_modeled_conversion_monitor_cron.sh"], cwd=str(ROOT),
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=20, check=False, env=ENV)
    if completed.returncode != 0:
        raise HandoffFailure("cron_install_unconfirmed")
    result = _status(operation, source_sha)
    if result["configured"] is not True or result["cron_count"] != 1 or result["ok"] is not True:
        raise HandoffFailure("cron_install_unconfirmed")
    return result

class _Parser(argparse.ArgumentParser):
    def error(self, _):
        raise HandoffFailure("invalid_arguments")

def main(argv=None):
    os.umask(0o077)
    operation = source_sha = None
    lock_fd = None
    try:
        if "REQUEST_METHOD" in os.environ or "GATEWAY_INTERFACE" in os.environ:
            raise HandoffFailure("cli_only_required")
        parser = _Parser(description=__doc__)
        parser.add_argument("action", choices=("stage", "activate", "status"))
        parser.add_argument("--operation", required=True)
        parser.add_argument("--source-sha", required=True)
        parser.add_argument("--external-intent-artifact")
        args = parser.parse_args(argv)
        if not _positive(args.operation) or not _sha(args.source_sha) \
                or (args.action == "activate" and not _positive(args.external_intent_artifact)) \
                or (args.action != "activate" and args.external_intent_artifact is not None):
            raise HandoffFailure("invalid_arguments")
        operation, source_sha = args.operation, args.source_sha
        if Path(os.path.realpath(os.getcwd())) != ROOT or Path(os.path.realpath(__file__)).parent.parent != ROOT:
            raise HandoffFailure("fixed_application_root_required")
        _source(source_sha)
        lock_fd = _lock(read_only=args.action == "status")
        if args.action == "stage":
            raw = sys.stdin.buffer.read(MAX_STATE + 1)
            if not raw or len(raw) > MAX_STATE:
                raise HandoffFailure("bounded_state_required")
            result = stage(operation, source_sha, _decode(raw))
        elif args.action == "activate":
            result = activate(operation, source_sha, args.external_intent_artifact)
        else:
            result = _status(operation, source_sha)
        _source(source_sha)
        print(json.dumps(result, sort_keys=True, separators=(",", ":")))
        return 0 if result["ok"] is True else 1
    except Exception:
        result = {**BASE, "ok": False, "reason": "handoff_unavailable"}
        if operation is not None:
            result.update(operation=operation, source_sha=source_sha)
        print(json.dumps(result, sort_keys=True, separators=(",", ":")))
        return 1
    finally:
        if lock_fd is not None:
            fcntl.flock(lock_fd, fcntl.LOCK_UN)
            os.close(lock_fd)

if __name__ == "__main__":
    raise SystemExit(main())
