#!/usr/bin/env python3
"""One-use encrypted relay to the existing private Telegram history importer.

Only the ephemeral public recipient and allowlisted aggregate result are logged.
The private recipient, SSH identity, payload and source never leave temporary
private runner storage. This auxiliary tool is not installed on production.
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


EXPECTED_MAIN = "d5cbb401002f851fc327584bf67b7f5ffd0b20df"
EXPECTED_SOURCE_SHA256 = "655025adabf918ee41a1fee9ff1d67cd91fa89ad6c60101459194c4834b0bea7"
EXPECTED_SOURCE_BYTES = 53_715_353
EXPECTED_PERSONAL_CHATS = 5_956
MAX_SOURCE_BYTES = 64 * 1024 * 1024
MAX_ENVELOPE_BYTES = 12 * 1024 * 1024
PAYLOAD_BRANCH = "analysis/telegram-history-payload-20261017"
PAYLOAD_REF = "refs/remotes/origin/telegram-history-payload"
PAYLOAD_PATH = "tools/telegram-history-envelope.json"
RECIPIENT_BRANCH = "analysis/telegram-history-recipient-20261017"
PRODUCTION_ROOT = "/var/www/anytoour/data/www/app.anytoour.ru"
SSH_HOST_KEY = "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIEXxjWqpuN07MTGcXrRnshpTX3KKK/wvQeaYl03RY8DA"


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


def publish_recipient(public: bytes) -> None:
    # This orphan branch contains one public PEM only. Never force or reuse it.
    blob = command(["git", "hash-object", "-w", "--stdin"], input_bytes=public).decode().strip()
    if not re.fullmatch(r"[a-f0-9]{40}", blob):
        raise ValueError("public recipient blob unavailable")
    tree = command(["git", "mktree"], input_bytes=("100644 blob " + blob + "\trecipient.pem\n").encode()).decode().strip()
    if not re.fullmatch(r"[a-f0-9]{40}", tree):
        raise ValueError("public recipient tree unavailable")
    git_env = os.environ.copy()
    git_env.update(GIT_AUTHOR_NAME="AnyTour archive import", GIT_COMMITTER_NAME="AnyTour archive import",
                   GIT_AUTHOR_EMAIL="actions@users.noreply.github.com", GIT_COMMITTER_EMAIL="actions@users.noreply.github.com")
    result = subprocess.run(["git", "commit-tree", tree], input=b"Ephemeral public history-import recipient\n",
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=30, check=False, env=git_env)
    commit = result.stdout.decode().strip()
    if result.returncode or not re.fullmatch(r"[a-f0-9]{40}", commit):
        raise ValueError("public recipient commit unavailable")
    command(["git", "push", "origin", commit + ":refs/heads/" + RECIPIENT_BRANCH])


def receive_payload(private_key: rsa.RSAPrivateKey) -> bytes:
    envelope_bytes = None
    deadline = time.monotonic() + 600
    for attempt in range(40):
        remaining = deadline - time.monotonic()
        if remaining <= 0:
            break
        result = subprocess.run(["git", "fetch", "--no-tags", "origin",
                                 "refs/heads/" + PAYLOAD_BRANCH + ":" + PAYLOAD_REF],
                                stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                timeout=min(10, remaining), check=False)
        if result.returncode == 0:
            envelope_bytes = command(["git", "show", PAYLOAD_REF + ":" + PAYLOAD_PATH])
            break
        if attempt < 39:
            time.sleep(min(15, max(0, deadline - time.monotonic())))
    if envelope_bytes is None or len(envelope_bytes) > MAX_ENVELOPE_BYTES:
        raise ValueError("bounded upload unavailable")
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
    if len(fields["wrapped_key_b64"]) != 384 or len(fields["nonce_b64"]) != 12:
        raise ValueError("wrong envelope dimensions")
    key = private_key.decrypt(fields["wrapped_key_b64"], padding.OAEP(
        mgf=padding.MGF1(algorithm=hashes.SHA256()), algorithm=hashes.SHA256(), label=None))
    if len(key) != 32:
        raise ValueError("wrong content key")
    compressed = AESGCM(key).decrypt(fields["nonce_b64"], fields["ciphertext_b64"], None)
    with gzip.GzipFile(fileobj=io.BytesIO(compressed), mode="rb") as stream:
        source = stream.read(MAX_SOURCE_BYTES + 1)
        if stream.read(1):
            raise ValueError("source overflow")
    if len(source) != EXPECTED_SOURCE_BYTES or hashlib.sha256(source).hexdigest() != EXPECTED_SOURCE_SHA256:
        raise ValueError("source identity mismatch")
    export = json.loads(source)
    chats = export.get("chats", {}).get("list") if isinstance(export, dict) else None
    if not isinstance(chats, list) or sum(
        isinstance(chat, dict) and chat.get("type") == "personal_chat" for chat in chats
    ) != EXPECTED_PERSONAL_CHATS:
        raise ValueError("wrong chat scope")
    return source


def public_result(output: bytes) -> dict:
    if len(output) > 32_768:
        raise ValueError("unexpected result size")
    result = json.loads(output)
    if not isinstance(result, dict) or result.get("ok") is not True or result.get("matched_shared_account") is not True:
        raise ValueError("private import unavailable")
    if set(result) != {"ok", "matched_shared_account", "counts", "storage"}:
        raise ValueError("unexpected result fields")
    fields = {"counts": {"selected_chats", "messages", "service_messages", "excluded_chats"},
              "storage": {"inserted", "updated", "unchanged"}}
    for name in ("counts", "storage"):
        if not isinstance(result[name], dict) or not result[name]:
            raise ValueError("invalid aggregate")
        if set(result[name]) != fields[name] or any(type(value) is not int or value < 0
                                                  for value in result[name].values()):
            raise ValueError("invalid aggregate fields")
    return result


def relay(source: bytes, directory: Path) -> dict:
    main_matches()
    host = os.environ.get("DEPLOY_HOST", "")
    user = os.environ.get("DEPLOY_USER", "")
    if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9.-]*", host) or not re.fullmatch(r"[A-Za-z_][A-Za-z0-9_.-]*", user):
        raise ValueError("invalid transport target")
    raw_key = os.environ.get("DEPLOY_SSH_KEY_VALUE", "")
    if "PRIVATE KEY" in raw_key:
        key_bytes = raw_key.replace("\\n", "\n").replace("\r", "").encode()
    else:
        key_bytes = base64.b64decode(raw_key, validate=True)
    private_write(directory / "ssh-key", key_bytes)
    private_write(directory / "known_hosts", (host + " " + SSH_HOST_KEY + "\n").encode())
    command(["ssh-keygen", "-y", "-f", str(directory / "ssh-key")])
    helper = Path("tools/telegram_history_import_remote.php").read_bytes()
    if not helper.startswith(b"<?php\n") or len(helper) > 65_536:
        raise ValueError("wrong remote helper")
    encoded_helper = base64.b64encode(helper[6:]).decode("ascii")
    php = "eval(base64_decode('" + encoded_helper + "'));"
    remote = "\n".join([
        "set -eu",
        "cd " + shlex.quote(PRODUCTION_ROOT),
        "test \"$(git rev-parse HEAD)\" = " + shlex.quote(EXPECTED_MAIN),
        "umask 077",
        "tg_history_private=$(mktemp -d /tmp/anytour-telegram-history.XXXXXX)",
        "chmod 700 \"$tg_history_private\"",
        "trap 'rm -f \"$tg_history_private/input.json\"; rmdir \"$tg_history_private\"' EXIT",
        "trap 'exit 1' HUP INT TERM",
        "cat > \"$tg_history_private/input.json\"",
        "chmod 600 \"$tg_history_private/input.json\"",
        "test \"$(stat -c %s \"$tg_history_private/input.json\")\" = " + str(EXPECTED_SOURCE_BYTES),
        "php -d display_errors=0 -d log_errors=0 -d memory_limit=1024M -r " + shlex.quote(php) + ' "$tg_history_private/input.json"',
        "test \"$(git rev-parse HEAD)\" = " + shlex.quote(EXPECTED_MAIN),
    ])
    ssh = ["ssh", "-i", str(directory / "ssh-key"),
           "-o", "StrictHostKeyChecking=yes", "-o", "UserKnownHostsFile=" + str(directory / "known_hosts"),
           "-o", "BatchMode=yes", "-o", "IdentitiesOnly=yes", "-o", "ForwardAgent=no",
           "-o", "PasswordAuthentication=no", "-o", "KbdInteractiveAuthentication=no",
           "-o", "ConnectTimeout=10", "-o", "ServerAliveInterval=10", "-o", "ServerAliveCountMax=2",
           "-o", "LogLevel=ERROR", user + "@" + host, remote]
    # Before launching the sole apply attempt, write a local receipt. No retry path.
    private_write(directory / "apply-started", b"one attempt; inspect unknown outcomes\n")
    output = command(ssh, input_bytes=source, timeout=300)
    main_matches()
    return public_result(output)


def main() -> int:
    try:
        if os.environ.get("GITHUB_REPOSITORY") != "pyatkoff/max-search-bot" or os.environ.get("GITHUB_REF") != "refs/heads/analysis/telegram-history-import-20261017":
            raise ValueError("wrong workflow scope")
        main_matches()
        os.umask(0o077)
        with tempfile.TemporaryDirectory(prefix="tg-history-private-", dir=os.environ.get("RUNNER_TEMP")) as temporary:
            directory = Path(temporary)
            private_key = rsa.generate_private_key(public_exponent=65537, key_size=3072)
            private_write(directory / "recipient.pem", private_key.private_bytes(
                serialization.Encoding.PEM, serialization.PrivateFormat.PKCS8, serialization.NoEncryption()))
            public = private_key.public_key().public_bytes(serialization.Encoding.PEM,
                                                          serialization.PublicFormat.SubjectPublicKeyInfo)
            print(public.decode("ascii"), end="", flush=True)
            publish_recipient(public)
            source = receive_payload(private_key)
            result = relay(source, directory)
            print(json.dumps(result, separators=(",", ":")), flush=True)
        return 0
    except Exception:
        print("Private history import did not complete; inspect existing receipts before another attempt.", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
