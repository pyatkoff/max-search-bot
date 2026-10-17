#!/usr/bin/env python3
"""One-use encrypted, project-bound ANEX Telegram history relay.

The orphan recipient branch contains only the ephemeral public RSA key.
Plaintext, private keys and transport errors remain private.
All three authenticated parts are checked before the first import attempt.
"""
from __future__ import annotations

import base64
import gzip
import hashlib
import io
import json
import os
from pathlib import Path
import re
import shlex
import subprocess
import sys
import tempfile
import time

from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import padding, rsa
from cryptography.hazmat.primitives.ciphers.aead import AESGCM


EXPECTED_MAIN = "21d7dffd46f55c81f0780d179f60f99cd3a4f364"
ORIGINAL_SHA256 = "769dbdf0365a471916d0cf228a322d374c8ed0616359f9e9bbe5046233bc5052"
ORIGINAL_BYTES = 159_135_328
EXPECTED_TOTALS = {"selected_chats": 11_600, "messages": 245_862, "service_messages": 540}
MAX_SOURCE_BYTES = 64 * 1024 * 1024
MAX_ENVELOPE_BYTES = 96 * 1024 * 1024
PAYLOAD_BRANCH = "analysis/telegram-anex-history-payload-20261018"
PAYLOAD_REF = "refs/remotes/origin/telegram-anex-history-payload-20261018"
RECIPIENT_BRANCH = "analysis/telegram-anex-history-recipient-20261018"
WORKFLOW_REF = "refs/heads/analysis/telegram-anex-history-20261018"
PRODUCTION_ROOT = "/var/www/anytoour/data/www/app.anytoour.ru"
SSH_HOST_KEY = "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIEXxjWqpuN07MTGcXrRnshpTX3KKK/wvQeaYl03RY8DA"
RUNNER_PHASES = {"startup", "main_verified", "key_validated",
                 "recipient_published", "payload_received", "parts_verified",
                 "remote_apply_submitted", "returned"}
REMOTE_PHASES = {"scope", "source", "hash", "normalize", "project", "shared_account",
                 "owner_binding", "receipt", "start", "apply", "result_receipt"}
runner_phase = "startup"
apply_started = False
current_part = 0


class RemoteFailure(ValueError):
    def __init__(self, report: dict):
        super().__init__("private remote operation failed")
        self.report = report


def phase(value: str) -> None:
    global runner_phase
    if value not in RUNNER_PHASES:
        raise ValueError("invalid phase")
    runner_phase = value
    print(json.dumps({"phase": value}, separators=(",", ":")), flush=True)


def normalized_ssh_key(raw: str) -> bytes:
    if "PRIVATE KEY" in raw:
        value = raw.replace("\\n", "\n").replace("\r", "").encode()
    else:
        value = base64.b64decode("".join(raw.split()), validate=True)
        value = value.replace(b"\r", b"")
    if not value.endswith(b"\n"):
        value += b"\n"
    return value


def command(args: list[str], *, timeout: int = 30, input_bytes: bytes | None = None) -> bytes:
    result = subprocess.run(args, input=input_bytes, stdout=subprocess.PIPE,
                            stderr=subprocess.PIPE, timeout=timeout, check=False)
    if result.returncode:
        raise ValueError("private operation failed")
    return result.stdout


def main_matches() -> None:
    command(["git", "fetch", "--no-tags", "origin", "refs/heads/main:refs/remotes/origin/main"])
    if command(["git", "rev-parse", "refs/remotes/origin/main"]).decode().strip() != EXPECTED_MAIN:
        raise ValueError("main changed")


def private_write(path: Path, data: bytes) -> None:
    fd = os.open(path, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
    with os.fdopen(fd, "wb") as stream:
        stream.write(data)
        stream.flush()
        os.fsync(stream.fileno())


def load_manifest() -> dict:
    raw = Path("tools/telegram_anex_history_manifest.json").read_bytes()
    if len(raw) > 16_384:
        raise ValueError("invalid manifest size")
    manifest = json.loads(raw)
    if not isinstance(manifest, dict) or set(manifest) != {"version", "source_project", "original", "parts"}:
        raise ValueError("invalid manifest fields")
    if type(manifest["version"]) is not int or manifest["version"] != 1 or manifest["source_project"] != "anex":
        raise ValueError("invalid manifest project")
    if manifest["original"] != {"sha256": ORIGINAL_SHA256, "bytes": ORIGINAL_BYTES}:
        raise ValueError("invalid original identity")
    parts = manifest["parts"]
    if not isinstance(parts, list) or len(parts) != 3:
        raise ValueError("invalid part count")
    fields = {"part", "path", "sha256", "bytes", "selected_chats", "messages", "service_messages", "excluded_chats"}
    for index, part in enumerate(parts, 1):
        if not isinstance(part, dict) or set(part) != fields:
            raise ValueError("invalid part fields")
        if type(part["part"]) is not int or part["part"] != index or part["path"] != f"payload/part-{index:03d}.json":
            raise ValueError("invalid part order")
        if not isinstance(part["sha256"], str) or not re.fullmatch(r"[a-f0-9]{64}", part["sha256"]):
            raise ValueError("invalid part hash")
        for key in ("bytes", "selected_chats", "messages", "service_messages", "excluded_chats"):
            if type(part[key]) is not int or part[key] < 0:
                raise ValueError("invalid part count")
        if not 0 < part["bytes"] <= MAX_SOURCE_BYTES or part["excluded_chats"] != 1:
            raise ValueError("invalid part bounds")
        if not 0 < part["selected_chats"] < 10_000 or not 0 < part["messages"] + part["service_messages"] < 200_000:
            raise ValueError("invalid importer bounds")
    if any(sum(part[name] for part in parts) != total for name, total in EXPECTED_TOTALS.items()):
        raise ValueError("invalid canonical totals")
    return manifest


def nonnegative_fields(value: object, names: set[str]) -> bool:
    return isinstance(value, dict) and set(value) == names and all(
        type(item) is int and item >= 0 for item in value.values())


def public_remote_failure(output: bytes, index: int) -> dict | None:
    if not output or len(output) > 1024:
        return None
    try:
        value = json.loads(output)
        if not isinstance(value, dict) or set(value) != {"ok", "phase", "apply_started", "part"}:
            return None
        if (value["ok"] is not False or value["phase"] not in REMOTE_PHASES
                or type(value["apply_started"]) is not bool or type(value["part"]) is not int
                or value["part"] != index):
            return None
        return value
    except (ValueError, TypeError):
        return None


def prepare_transport(directory: Path) -> list[str]:
    host = os.environ.get("DEPLOY_HOST", "")
    user = os.environ.get("DEPLOY_USER", "")
    if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9.-]*", host) or not re.fullmatch(r"[A-Za-z_][A-Za-z0-9_.-]*", user):
        raise ValueError("invalid transport target")
    private_write(directory / "ssh-key", normalized_ssh_key(os.environ.get("DEPLOY_SSH_KEY_VALUE", "")))
    private_write(directory / "known_hosts", (host + " " + SSH_HOST_KEY + "\n").encode())
    command(["ssh-keygen", "-y", "-f", str(directory / "ssh-key")])
    phase("key_validated")
    return ["ssh", "-i", str(directory / "ssh-key"),
            "-o", "StrictHostKeyChecking=yes", "-o", "UserKnownHostsFile=" + str(directory / "known_hosts"),
            "-o", "BatchMode=yes", "-o", "IdentitiesOnly=yes", "-o", "ForwardAgent=no",
            "-o", "PasswordAuthentication=no", "-o", "KbdInteractiveAuthentication=no",
            "-o", "ConnectTimeout=10", "-o", "ServerAliveInterval=10", "-o", "ServerAliveCountMax=2",
            "-o", "LogLevel=ERROR", user + "@" + host]


def remote_php() -> str:
    helper = Path("tools/telegram_anex_history_remote.php").read_bytes()
    if not helper.startswith(b"<?php\n") or len(helper) > 65_536:
        raise ValueError("wrong remote helper")
    encoded = base64.b64encode(helper[6:]).decode("ascii")
    return "eval(base64_decode('" + encoded + "'));"


def fixed_remote() -> list[str]:
    return ["set -eu", "cd " + shlex.quote(PRODUCTION_ROOT),
            'test "$(git rev-parse HEAD)" = ' + shlex.quote(EXPECTED_MAIN)]


def publish_recipient(public: bytes) -> None:
    # A fresh orphan ref contains exactly one safe public key.
    blob = command(["git", "hash-object", "-w", "--stdin"], input_bytes=public).decode().strip()
    if not re.fullmatch(r"[a-f0-9]{40}", blob):
        raise ValueError("public recipient blob unavailable")
    tree = command(["git", "mktree"], input_bytes=(
        "100644 blob " + blob + "\trecipient.pem\n").encode()).decode().strip()
    if not re.fullmatch(r"[a-f0-9]{40}", tree):
        raise ValueError("public recipient tree unavailable")
    git_env = os.environ.copy()
    git_env.update(GIT_AUTHOR_NAME="AnyTour ANEX archive import", GIT_COMMITTER_NAME="AnyTour ANEX archive import",
                   GIT_AUTHOR_EMAIL="actions@users.noreply.github.com", GIT_COMMITTER_EMAIL="actions@users.noreply.github.com")
    result = subprocess.run(["git", "commit-tree", tree], input=b"One-use public ANEX recipient\n",
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=30, check=False, env=git_env)
    commit = result.stdout.decode().strip()
    if result.returncode or not re.fullmatch(r"[a-f0-9]{40}", commit):
        raise ValueError("public recipient commit unavailable")
    # No force and no retries: an existing recipient ref prevents replay.
    command(["git", "push", "origin", commit + ":refs/heads/" + RECIPIENT_BRANCH])


def receive_payload_ref() -> None:
    deadline = time.monotonic() + 600
    expected = None
    for attempt in range(40):
        remaining = deadline - time.monotonic()
        if remaining <= 0:
            break
        result = subprocess.run(["git", "ls-remote", "--exit-code", "origin", "refs/heads/" + PAYLOAD_BRANCH],
                                stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                timeout=min(10, remaining), check=False)
        if result.returncode == 0:
            match = re.fullmatch(rb"([a-f0-9]{40})\trefs/heads/" + PAYLOAD_BRANCH.encode() + rb"\n?", result.stdout)
            if match is None:
                raise ValueError("invalid payload ref")
            expected = match[1].decode()
            break
        if attempt < 39:
            time.sleep(min(15, max(0, deadline - time.monotonic())))
    if expected is None:
        raise ValueError("bounded upload unavailable")
    # Fetch the branch once, then authenticate every part from this single ref.
    command(["git", "fetch", "--no-tags", "origin", "refs/heads/" + PAYLOAD_BRANCH + ":" + PAYLOAD_REF], timeout=45)
    if command(["git", "rev-parse", PAYLOAD_REF]).decode().strip() != expected:
        raise ValueError("payload ref changed")
    phase("payload_received")


def decrypt_part(private_key: rsa.RSAPrivateKey, part: dict) -> bytes:
    object_name = PAYLOAD_REF + ":" + part["path"]
    size_text = command(["git", "cat-file", "-s", object_name]).decode().strip()
    if not re.fullmatch(r"[0-9]+", size_text) or not 0 < int(size_text) <= MAX_ENVELOPE_BYTES:
        raise ValueError("invalid envelope size")
    envelope_bytes = command(["git", "show", object_name])
    if len(envelope_bytes) != int(size_text):
        raise ValueError("envelope size changed")
    envelope = json.loads(envelope_bytes)
    if not isinstance(envelope, dict) or set(envelope) != {
        "version", "wrapped_key_b64", "nonce_b64", "ciphertext_b64"
    } or type(envelope["version"]) is not int or envelope["version"] != 1:
        raise ValueError("wrong envelope")
    fields = {}
    for name in ("wrapped_key_b64", "nonce_b64", "ciphertext_b64"):
        if not isinstance(envelope[name], str):
            raise ValueError("wrong envelope field")
        fields[name] = base64.b64decode(envelope[name], validate=True)
    if len(fields["wrapped_key_b64"]) != 384 or len(fields["nonce_b64"]) != 12 or len(fields["ciphertext_b64"]) < 16:
        raise ValueError("wrong envelope dimensions")
    key = private_key.decrypt(fields["wrapped_key_b64"], padding.OAEP(
        mgf=padding.MGF1(algorithm=hashes.SHA256()), algorithm=hashes.SHA256(), label=None))
    if len(key) != 32:
        raise ValueError("wrong content key")
    compressed = AESGCM(key).decrypt(fields["nonce_b64"], fields["ciphertext_b64"], None)
    with gzip.GzipFile(fileobj=io.BytesIO(compressed), mode="rb") as stream:
        source = stream.read(part["bytes"] + 1)
    if len(source) != part["bytes"] or hashlib.sha256(source).hexdigest() != part["sha256"]:
        raise ValueError("source identity mismatch")
    return source


def validate_scope(source: bytes, part: dict, owner: int | None, seen: set[int]) -> int:
    export = json.loads(source)
    if not isinstance(export, dict) or "personal_information" in export:
        raise ValueError("invalid part metadata")
    chats = export.get("chats", {}).get("list")
    if not isinstance(chats, list) or len(chats) != part["selected_chats"] + 1:
        raise ValueError("invalid chat scope")
    saved = []
    personal = messages = services = 0
    for chat in chats:
        if not isinstance(chat, dict):
            raise ValueError("invalid chat record")
        ident = chat.get("id")
        if type(ident) is not int or ident <= 0:
            raise ValueError("invalid private chat identity")
        records = chat.get("messages")
        if not isinstance(records, list):
            raise ValueError("invalid private messages")
        if chat.get("type") == "saved_messages":
            if records:
                raise ValueError("nonempty owner stub")
            saved.append(ident)
            continue
        if chat.get("type") != "personal_chat" or ident in seen:
            raise ValueError("invalid private chat selection")
        seen.add(ident)
        personal += 1
        for record in records:
            if not isinstance(record, dict):
                raise ValueError("invalid private message")
            if record.get("type") == "message":
                messages += 1
            elif record.get("type") == "service":
                services += 1
            else:
                raise ValueError("unsupported private message")
    if len(saved) != 1 or (owner is not None and saved[0] != owner):
        raise ValueError("inconsistent owner stub")
    if (personal, messages, services) != (part["selected_chats"], part["messages"], part["service_messages"]):
        raise ValueError("canonical part counts changed")
    return saved[0]


def receive_all_parts(private_key: rsa.RSAPrivateKey, manifest: dict, directory: Path) -> list[Path]:
    receive_payload_ref()
    owner = None
    seen: set[int] = set()
    paths = []
    for part in manifest["parts"]:
        source = decrypt_part(private_key, part)
        owner = validate_scope(source, part, owner, seen)
        path = directory / f"part-{part['part']:03d}.json"
        private_write(path, source)
        paths.append(path)
    # This function has no SSH/apply capability. Every part is authenticated now.
    phase("parts_verified")
    return paths


def public_result(output: bytes, part: dict) -> dict:
    if len(output) > 4096:
        raise ValueError("unexpected result size")
    result = json.loads(output)
    if not isinstance(result, dict) or set(result) != {"ok", "source_project", "part", "reused_receipt", "counts", "storage"}:
        raise ValueError("unexpected result fields")
    if (result["ok"] is not True or result["source_project"] != "anex"
            or type(result["part"]) is not int or result["part"] != part["part"]
            or type(result["reused_receipt"]) is not bool):
        raise ValueError("private import unavailable")
    if not nonnegative_fields(result["counts"], {"selected_chats", "messages", "service_messages", "excluded_chats"}):
        raise ValueError("invalid count fields")
    if result["counts"] != {name: part[name] for name in ("selected_chats", "messages", "service_messages", "excluded_chats")}:
        raise ValueError("unexpected canonical counts")
    if not nonnegative_fields(result["storage"], {"inserted", "updated", "unchanged"}):
        raise ValueError("invalid storage fields")
    if sum(result["storage"].values()) != part["messages"]:
        raise ValueError("inconsistent storage counts")
    return result


def relay_part(ssh: list[str], php: str, path: Path, part: dict, directory: Path) -> dict:
    global apply_started, current_part
    current_part = part["part"]
    main_matches()
    if path.stat().st_size != part["bytes"] or hashlib.sha256(path.read_bytes()).hexdigest() != part["sha256"]:
        raise ValueError("private source changed")
    remote = fixed_remote() + [
        "umask 077",
        "tg_history_private=$(mktemp -d /tmp/anytour-telegram-anex-history.XXXXXX)",
        'chmod 700 "$tg_history_private"',
        'trap \'rm -f "$tg_history_private/input.json"; rmdir "$tg_history_private"\' EXIT',
        "trap 'exit 1' HUP INT TERM",
        'cat > "$tg_history_private/input.json"',
        'chmod 600 "$tg_history_private/input.json"',
        'test "$(stat -c %s "$tg_history_private/input.json")" = ' + str(part["bytes"]),
        "php -d display_errors=0 -d log_errors=0 -d memory_limit=1536M -r " + shlex.quote(php)
        + " -- import " + str(current_part) + ' "$tg_history_private/input.json"',
        'test "$(git rev-parse HEAD)" = ' + shlex.quote(EXPECTED_MAIN)]
    private_write(directory / f"apply-started-{current_part}", b"One submission; inspect durable receipts on unknown outcome.\n")
    apply_started = True
    phase("remote_apply_submitted")
    with path.open("rb") as stream:
        result = subprocess.run(ssh + ["\n".join(remote)], stdin=stream, stdout=subprocess.PIPE,
                                stderr=subprocess.PIPE, timeout=300, check=False)
    if result.returncode:
        failure = public_remote_failure(result.stdout, current_part)
        if failure is not None:
            raise RemoteFailure(failure)
        raise ValueError("private remote operation failed")
    report = public_result(result.stdout, part)
    main_matches()
    phase("returned")
    return report


def main() -> int:
    try:
        if (os.environ.get("GITHUB_REPOSITORY") != "pyatkoff/max-search-bot"
                or os.environ.get("GITHUB_REF") != WORKFLOW_REF
                or os.environ.get("GITHUB_RUN_ATTEMPT") != "1"):
            raise ValueError("wrong one-use workflow scope")
        manifest = load_manifest()
        main_matches()
        phase("main_verified")
        os.umask(0o077)
        with tempfile.TemporaryDirectory(prefix="tg-anex-history-private-", dir=os.environ.get("RUNNER_TEMP")) as temporary:
            directory = Path(temporary)
            ssh = prepare_transport(directory)
            php = remote_php()
            # The completed live acceptance is not repeated by this import.
            private_key = rsa.generate_private_key(public_exponent=65537, key_size=3072)
            private_write(directory / "recipient-private.pem", private_key.private_bytes(
                serialization.Encoding.PEM, serialization.PrivateFormat.PKCS8, serialization.NoEncryption()))
            public = private_key.public_key().public_bytes(
                serialization.Encoding.PEM, serialization.PublicFormat.SubjectPublicKeyInfo)
            publish_recipient(public)
            phase("recipient_published")
            paths = receive_all_parts(private_key, manifest, directory)
            for path, part in zip(paths, manifest["parts"], strict=True):
                result = relay_part(ssh, php, path, part, directory)
                print(json.dumps(result, separators=(",", ":")), flush=True)
        return 0
    except Exception as error:
        failure = error.report if isinstance(error, RemoteFailure) else {
            "ok": False, "phase": runner_phase, "apply_started": apply_started, "part": current_part}
        print(json.dumps(failure, separators=(",", ":")), flush=True)
        print("Private ANEX import did not complete; inspect durable receipts before any further attempt.", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
