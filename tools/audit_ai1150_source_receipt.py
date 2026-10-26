#!/usr/bin/env python3
"""One fixed, read-only source receipt using the reviewed consumer in memory."""

import base64
import hashlib
import json
import os
from pathlib import Path
import re
import selectors
import socket
import stat
import subprocess
import sys
import time


ROOT = Path(__file__).resolve().parents[1]
RUNTIME_SHA = "19a839536e02b5fb9006d4cab64719b1fdf8769f"
READER_BLOB = "ce7cb461e03cedbb3b81a3548d7f2ccff9ccde48"
REVIEWED_SOURCE_SHA = "e87bb5112a9c99539fe75716cdd36f443f72a6a0"
OPERATION = "ai1150-source-receipt-20261026-v1"
READ_STAGE = "candidate_after_catalogue_discovery"
DISCOVERY_RECEIPT_SHA256 = "49f4721de2d61e8ee27fe0ff18cf78c176062238eeb71c2961d960cfa91fd8d7"
DISCOVERY_CATALOGUE_SHA256 = "6e8a03db0e3ea6d82e7cfcfe5d124d361a975637f3fcee7523878d852efeccfd"
DISCOVERY_READ_AT = "2026-10-26T06:44:01Z"
AUDIT_REF = "refs/heads/audit/ai1150-source-receipt-20261026"
REMOTE_ROOT = "/var/www/anytoour/data/www/app.anytoour.ru"
PINNED_IP = "5.45.122.54"
HOST_FINGERPRINT = "SHA256:/5mPWmGBQ6UPnNQDEHLOCy7dCpxdz+FxQ4zVOjqE0F0"
MAX_BYTES = 131072
PHASE = "validation"
REMOTE_EXECUTION_ATTEMPTED = False
FAILURE_REASONS = {
    "validation_failed", "dns_lookup_failed", "canonical_target_changed", "host_key_scan_failed",
    "invalid_host_key_scan", "canonical_host_pin_not_seen", "known_hosts_write_failed",
    "host_pin_failed", "remote_command_failed", "remote_execution_failed", "invalid_receipt",
}
PHASE_FAILURES = {
    "validation": "validation_failed", "host_pin": "host_pin_failed",
    "remote": "remote_execution_failed", "receipt": "invalid_receipt",
}


class ReceiptFailure(Exception):
    """Failure text is deliberately never copied into runner output."""

    def __init__(self, reason=None):
        self.reason = reason if reason in FAILURE_REASONS else None
        super().__init__()


def require(condition, reason=None):
    if not condition:
        raise ReceiptFailure(reason)


def bounded_process(argv, payload=b"", timeout=75, max_bytes=MAX_BYTES):
    """Drain both pipes while enforcing a combined live output byte ceiling."""
    process = subprocess.Popen(
        argv, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE
    )
    deadline = time.monotonic() + timeout
    stdout = bytearray()
    stderr = bytearray()
    offset = 0
    selector = selectors.DefaultSelector()
    try:
        for stream, label in ((process.stdout, "out"), (process.stderr, "err")):
            os.set_blocking(stream.fileno(), False)
            selector.register(stream, selectors.EVENT_READ, label)
        if payload:
            os.set_blocking(process.stdin.fileno(), False)
            selector.register(process.stdin, selectors.EVENT_WRITE, "in")
        else:
            process.stdin.close()
        while selector.get_map():
            remaining = deadline - time.monotonic()
            require(remaining > 0)
            for key, _ in selector.select(min(remaining, 1)):
                if key.data == "in":
                    try:
                        offset += os.write(key.fd, payload[offset:offset + 65536])
                    except BrokenPipeError:
                        offset = len(payload)
                    except BlockingIOError:
                        continue
                    if offset == len(payload):
                        selector.unregister(key.fileobj)
                        key.fileobj.close()
                    continue
                try:
                    chunk = os.read(key.fd, 65536)
                except BlockingIOError:
                    continue
                if not chunk:
                    selector.unregister(key.fileobj)
                    key.fileobj.close()
                    continue
                require(len(stdout) + len(stderr) + len(chunk) <= max_bytes)
                (stdout if key.data == "out" else stderr).extend(chunk)
        remaining = deadline - time.monotonic()
        require(remaining > 0)
        code = process.wait(timeout=remaining)
        return code, bytes(stdout), bytes(stderr)
    finally:
        selector.close()
        if process.poll() is None:
            process.kill()
            process.wait()
        for stream in (process.stdin, process.stdout, process.stderr):
            if not stream.closed:
                stream.close()


def unique_object(pairs):
    result = {}
    for key, value in pairs:
        require(key not in result)
        result[key] = value
    return result


def reject_constant(_):
    raise ReceiptFailure()


def private_file(path, content):
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(fd, "wb") as target:
        target.write(content)


def pinned_host_lines(scan_output, host):
    """Normalize the host token only after the exact public-key pin is verified."""
    try:
        lines = scan_output.decode("ascii", errors="strict").splitlines()
    except (AttributeError, UnicodeDecodeError):
        raise ReceiptFailure("invalid_host_key_scan") from None
    pinned = []
    for line in lines:
        parts = line.split()
        if len(parts) != 3 or parts[0].startswith("#"):
            continue
        try:
            public_key = base64.b64decode(parts[2], validate=True)
        except ValueError:
            continue
        fingerprint = "SHA256:" + base64.b64encode(hashlib.sha256(public_key).digest()).decode("ascii").rstrip("=")
        if fingerprint == HOST_FINGERPRINT:
            pinned.append(host + " " + parts[1] + " " + parts[2])
    return sorted(set(pinned))


def canonical_host_pin(host):
    try:
        addresses = {row[4][0] for row in socket.getaddrinfo(host, 22, type=socket.SOCK_STREAM)}
    except Exception:
        raise ReceiptFailure("dns_lookup_failed") from None
    require(addresses == {PINNED_IP}, "canonical_target_changed")
    try:
        # A partial keyscan may exit nonzero while still returning the exact pinned key.
        _, scan_out, _ = bounded_process(["ssh-keyscan", "-T", "10", host], timeout=30, max_bytes=65536)
    except Exception:
        raise ReceiptFailure("host_key_scan_failed") from None
    pinned = pinned_host_lines(scan_out, host)
    require(bool(pinned), "canonical_host_pin_not_seen")
    return pinned


def validate_receipt(data):
    require(type(data) is dict)
    require(data.get("source") == "ai1150_actual_source_receipt")
    require(type(data.get("schema_version")) is int and data["schema_version"] == 1)
    require(data.get("operation") == OPERATION)
    require(data.get("read_stage") == READ_STAGE)
    require(data.get("discovery_receipt_sha256") == DISCOVERY_RECEIPT_SHA256)
    require(type(data.get("previous_http_request_count")) is int and data["previous_http_request_count"] == 1)
    require(data.get("catalogue_discovery_reused") is True)
    require(data.get("selected_canonical_code") == "all-inclusive")
    require(type(data.get("matching_plan_count")) is int and data["matching_plan_count"] == 1)
    require(data.get("candidate_reader_blob") == READER_BLOB)
    require(data.get("execution") == "reviewed_candidate_in_memory_not_installed")
    for field in ("database_writes_requested", "customer_sends_requested"):
        require(type(data.get(field)) is int and data[field] == 0)
    for field in ("supplier_search_requested", "unfiltered_fallback", "prices_currently_verified"):
        require(data.get(field) is False)
    for field in ("read_only_directory_transaction", "directory_transaction_rolled_back"):
        require(data.get(field) is True)
    count = data.get("candidate_count")
    requests = data.get("http_request_count")
    require(type(count) is int and 0 <= count <= 3)
    require(type(requests) is int and 0 <= requests <= 2)
    require(type(data.get("total_http_request_count")) is int
            and data["total_http_request_count"] == 1 + requests <= 3)
    require(type(data.get("http_requests")) is list and len(data["http_requests"]) == requests)
    require(all(type(row) is dict and row.get("kind") in {"meal_catalogue", "listing"}
                for row in data["http_requests"]))
    require([row["kind"] for row in data["http_requests"]] == ["meal_catalogue", "listing"][:requests])
    status = data.get("status")
    require(status in {"hold", "empty_retained_scope", "candidates_observed"})
    require((status == "candidates_observed") == (count > 0))
    require(data.get("requested_meal_code") == "all-inclusive")
    require(type(data.get("requested_scope")) is dict)
    require(type(data["requested_scope"].get("meal_plan_id")) is int and data["requested_scope"]["meal_plan_id"] == 7)
    require(type(data.get("catalogue")) is dict)
    catalogue = data["catalogue"]
    catalogue_wire = json.dumps(catalogue, sort_keys=True, separators=(",", ":"), ensure_ascii=False, allow_nan=False).encode("utf-8")
    require(hashlib.sha256(catalogue_wire).hexdigest() == DISCOVERY_CATALOGUE_SHA256)
    selected = [plan for plan in catalogue["plans"] if plan.get("meal_plan_id") == 7]
    require(len(selected) == 1 and selected[0]["code"] == "all-inclusive"
            and selected[0]["filter_supported"] is True and type(selected[0]["native_count"]) is int
            and selected[0]["native_count"] == 1)
    require(type(data.get("directory_bindings")) is list and len(data["directory_bindings"]) <= 2)
    result = data.get("candidate_result")
    if status != "hold":
        require(requests == 2)
        require(type(result) is dict and result.get("ok") is True)
        require(type(result.get("candidates")) is list and len(result["candidates"]) == count)
        require(type(result.get("meal_filter")) is dict)
        require(data["requested_scope"].get("meal_plan_id") == result["meal_filter"].get("plan_id"))
        require(result["meal_filter"].get("code") == "all-inclusive")
        require(result["meal_filter"].get("catalogue_revision") == catalogue["revision"])
        require(result.get("current_price_verified") is False and result.get("selection_authority") is False)
    return status, count, requests


def run():
    global PHASE, REMOTE_EXECUTION_ATTEMPTED
    expected = os.environ["EXPECTED_RUNTIME_SHA"]
    require(re.fullmatch(r"[a-f0-9]{40}", expected) is not None and expected == RUNTIME_SHA)
    require(os.environ.get("GITHUB_RUN_ATTEMPT") == "1")
    require(os.environ.get("GITHUB_EVENT_NAME") == "push" and os.environ.get("GITHUB_REF") == AUDIT_REF)
    run_id = os.environ["GITHUB_RUN_ID"]
    require(re.fullmatch(r"[1-9][0-9]{0,24}", run_id) is not None)
    host = os.environ["DEPLOY_HOST"]
    user = os.environ["DEPLOY_USER"]
    require(re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9.-]{0,252}", host) is not None)
    require(re.fullmatch(r"[A-Za-z0-9_][A-Za-z0-9_.-]{0,63}", user) is not None)
    temp = Path(os.environ["RUNNER_TEMP"])
    require(temp.is_absolute())
    ssh_dir = temp / "ai1150-ssh"
    key = ssh_dir / "key"
    require(stat.S_ISDIR(ssh_dir.lstat().st_mode) and stat.S_IMODE(ssh_dir.lstat().st_mode) == 0o700)
    require(stat.S_ISREG(key.lstat().st_mode) and stat.S_IMODE(key.lstat().st_mode) == 0o600)

    reader = (ROOT / "services/ManagerSearch3CandidateReader.php").read_bytes()
    require(len(reader) <= MAX_BYTES)
    blob = hashlib.sha1(b"blob " + str(len(reader)).encode("ascii") + b"\0" + reader).hexdigest()
    require(blob == READER_BLOB)
    driver = (ROOT / "tools/audit_ai1150_source_receipt.php").read_bytes()
    require(len(driver) <= MAX_BYTES)
    issued = int(time.time())
    replacements = {
        b"__READER_BASE64__": base64.b64encode(reader),
        b"__ISSUED_EPOCH__": str(issued).encode("ascii"),
        b"__EXPIRES_EPOCH__": str(issued + 900).encode("ascii"),
    }
    effective_driver = driver
    for placeholder, value in replacements.items():
        require(effective_driver.count(placeholder) == 1)
        effective_driver = effective_driver.replace(placeholder, value)

    PHASE = "host_pin"
    pinned = canonical_host_pin(host)
    known = ssh_dir / "known_hosts"
    try:
        private_file(known, ("\n".join(pinned) + "\n").encode("ascii"))
    except Exception:
        raise ReceiptFailure("known_hosts_write_failed") from None

    guard = 'test "$(git rev-parse HEAD)" = "' + expected + '" || exit 21'
    clean = "git diff --quiet HEAD -- services project.php || exit 22"
    encoded = base64.b64encode(effective_driver).decode("ascii")
    commands = [
        "set -euo pipefail", "export GIT_OPTIONAL_LOCKS=0", "cd " + REMOTE_ROOT,
        guard, clean, "set +e", "(", "cd services",
        "printf '%s' '" + encoded + "' | base64 --decode | php", ")",
        "driver_status=$?", "set -e", guard, clean, 'exit "$driver_status"',
    ]
    # DNS is checked once; SSH then uses that exact approved address and host identity.
    ssh = [
        "ssh", "-F", "/dev/null", "-T", "-i", str(key),
        "-o", "BatchMode=yes", "-o", "IdentitiesOnly=yes",
        "-o", "StrictHostKeyChecking=yes", "-o", "UserKnownHostsFile=" + str(known),
        "-o", "GlobalKnownHostsFile=/dev/null", "-o", "UpdateHostKeys=no",
        "-o", "ConnectTimeout=10", "-o", "ConnectionAttempts=1", "-o", "LogLevel=ERROR",
        "-o", "Hostname=" + PINNED_IP, "-o", "HostKeyAlias=" + host,
        user + "@" + host, "bash -se",
    ]
    PHASE = "remote"
    REMOTE_EXECUTION_ATTEMPTED = True
    code, stdout, stderr = bounded_process(ssh, ("\n".join(commands) + "\n").encode("ascii"), timeout=75)
    require(code == 0 and stderr == b"", "remote_command_failed")
    PHASE = "receipt"
    data = json.loads(stdout.decode("utf-8"), object_pairs_hook=unique_object, parse_constant=reject_constant)
    status, count, requests = validate_receipt(data)
    record = {
        "wrapper": {
            "runtime_sha": expected, "candidate_reader_blob": blob, "reviewed_source_sha": REVIEWED_SOURCE_SHA,
            "operation": OPERATION, "run_id": run_id, "run_attempt": 1,
            "read_stage": READ_STAGE, "discovery_receipt_sha256": DISCOVERY_RECEIPT_SHA256,
            "catalogue_discovery_read_at": DISCOVERY_READ_AT,
            "previous_http_request_count": 1, "http_request_count": requests,
            "total_http_request_count": data["total_http_request_count"], "catalogue_discovery_reused": True,
            "issued_epoch": issued, "expires_epoch": issued + 900,
            "driver_sha256": hashlib.sha256(driver).hexdigest(),
            "effective_driver_sha256": hashlib.sha256(effective_driver).hexdigest(),
        },
        "receipt": data,
    }
    wire = (json.dumps(record, sort_keys=True, separators=(",", ":"), ensure_ascii=False, allow_nan=False) + "\n").encode("utf-8")
    require(len(wire) <= MAX_BYTES)
    out = temp / "ai1150-receipt"
    out.mkdir(mode=0o700)
    private_file(out / "receipt.json", wire)
    print(json.dumps({"status": status, "candidate_count": count, "http_request_count": requests,
                      "sha256": hashlib.sha256(wire).hexdigest()}, sort_keys=True))


if __name__ == "__main__":
    try:
        run()
    except Exception as error:
        failure_reason = error.reason if isinstance(error, ReceiptFailure) and error.reason else PHASE_FAILURES[PHASE]
        print(json.dumps({"status": "hold", "candidate_count": None, "http_request_count": None,
                          "previous_http_request_count": 1, "total_http_request_count": None, "read_stage": READ_STAGE,
                          "sha256": None, "phase": PHASE, "failure_reason": failure_reason,
                          "remote_execution_attempted": REMOTE_EXECUTION_ATTEMPTED}, sort_keys=True))
        sys.exit(1)
