#!/usr/bin/env python3
"""Bounded, encrypted evidence from existing read-only MAX snapshots.

Only the public age recipient belongs in git. Never emit plaintext to stdout.
Message text remains private even after best-effort contact redaction.
"""
import argparse
import datetime as dt
import hashlib
import hmac
import json
import os
from pathlib import Path
import re
import sys

import pyrage

ROOT = Path(__file__).resolve().parents[1]
REPOSITORY = "pyatkoff/max-search-bot"
MAX_INPUT = 32 * 1024 * 1024
MAX_OUTPUT = 2 * 1024 * 1024
MAX_SESSIONS = 20
MAX_MESSAGES = 24
MAX_TEXT = 280
FLAGS = {
    "repeated_callback_input", "repeated_same_input", "excessive_turns",
    "rapid_date_reselection", "manager_requested_no_reply",
    "manager_taken_no_reply", "left_waiting_queue_without_manager_reply",
}
STATES = {"ai", "waiting_manager", "manager", "closed", "bot", "new"}
TIMES = ("started_at", "last_message_at", "manager_request_at", "manager_first_reply_at")
BOOLS = ("needs_collected", "tours_opened", "site_opened", "manager_requested",
         "manager_request_active", "manager_replied", "phone_received")


def timestamp(value):
    if not isinstance(value, str) or len(value) > 40:
        raise ValueError("invalid timestamp")
    parsed = dt.datetime.fromisoformat(value.replace("Z", "+00:00"))
    return parsed.replace(tzinfo=dt.timezone.utc) if parsed.tzinfo is None else parsed


def fresh(value, now, minutes=15):
    age = (now - timestamp(value)).total_seconds()
    if not -60 <= age <= minutes * 60:
        raise ValueError("stale evidence")


def redacted_text(value):
    text = value if isinstance(value, str) else ""
    text = re.sub(r"[\x00-\x1f\x7f]", " ", text)
    text = re.sub(r"(?:https?://|www\.)\S+", "[URL]", text, flags=re.I)
    text = re.sub(r"[\w.+-]+@[\w.-]+\.[\w-]+", "[EMAIL]", text)
    text = re.sub(r"(?<!\w)@[\w.]{3,}", "[HANDLE]", text)
    text = re.sub(r"(?<!\d)\+?\d[\d\s().-]{7,}\d(?!\d)",
                  lambda m: "[NUMBER]" if len(re.sub(r"\D", "", m[0])) >= 9 else m[0], text)
    text = re.sub(r"(?i)\b(?:token|authorization|password|api[_-]?key)\s*[:=]\s*\S+",
                  "[CREDENTIAL]", text)
    return " ".join(text.split())[:MAX_TEXT]


def read_bounded(path, limit):
    with Path(path).open("rb") as stream:
        data = stream.read(limit + 1)
    if len(data) > limit:
        raise ValueError("input limit")
    return data


def external_path(path):
    path = Path(path).absolute()
    if path.is_symlink() or path.resolve().is_relative_to(ROOT):
        raise ValueError("private output must be outside checkout")
    return path


def private_write(path, data):
    path = external_path(path)
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    try:
        with os.fdopen(fd, "wb") as stream:
            stream.write(data)
    except BaseException:
        path.unlink(missing_ok=True)
        raise


def build_evidence(reports, sha, recipient, now=None):
    now = now or dt.datetime.now(dt.timezone.utc)
    if not re.fullmatch(r"[0-9a-f]{40}", sha):
        raise ValueError("invalid production sha")
    salt = os.urandom(32)
    evidence = []
    omitted = 0
    windows = []
    for name, report in reports:
        if name not in {"live", "today", "yesterday"}:
            raise ValueError("invalid window")
        if report.get("ok") is not True or report.get("channel") != "max":
            raise ValueError("invalid snapshot")
        fresh(report.get("generated_at"), now)
        if name == "live":
            if report.get("window_type") != "rolling_hours" or report.get("window_hours") != 1:
                raise ValueError("unexpected rolling window")
        elif report.get("window_type") != "calendar_day" or report.get("timezone") != "Europe/Kaliningrad":
            raise ValueError("unexpected calendar window")
        windows.append({"name": name, "generated_at": timestamp(report["generated_at"]).isoformat(),
                        "since_utc": timestamp(report["since_utc"]).isoformat()})
        sessions = report.get("sessions")
        if not isinstance(sessions, list):
            raise ValueError("missing sessions")
        for session in reversed(sessions):
            if session.get("is_test") or session.get("channel") != "max":
                continue
            flags = [flag for flag in session.get("flags", []) if flag in FLAGS]
            if not flags or not session.get("message_tail"):
                continue
            if len(evidence) >= MAX_SESSIONS:
                omitted += 1
                continue
            identifier = session.get("conversation_id")
            if not isinstance(identifier, int) or isinstance(identifier, bool) or identifier <= 0:
                raise ValueError("missing correlation")
            alias = hmac.new(salt, str(identifier).encode(), hashlib.sha256).hexdigest()[:16]
            item = {"session_ref": alias, "window": name, "flags": flags,
                    "status": session.get("status") if session.get("status") in STATES else "unknown",
                    "messages": []}
            for field in TIMES:
                item[field] = timestamp(session[field]).isoformat() if session.get(field) else None
            for field in BOOLS:
                item[field] = session.get(field) is True
            for message in session["message_tail"][-MAX_MESSAGES:]:
                direction = message.get("direction")
                sender = message.get("sender_type")
                if direction not in {"inbound", "outbound"} or sender not in {"customer", "bot", "ai", "manager", "system"}:
                    continue
                item["messages"].append({"direction": direction, "sender_type": sender,
                                         "created_at": timestamp(message["created_at"]).isoformat(),
                                         "text": redacted_text(message.get("text"))})
            evidence.append(item)
    result = {"schema_version": 1, "repository": REPOSITORY, "production_sha": sha,
              "generated_at": now.isoformat(), "recipient": recipient, "windows": windows,
              "limits": {"sessions": MAX_SESSIONS, "messages_per_session": MAX_MESSAGES,
                         "characters_per_message": MAX_TEXT}, "omitted_sessions": omitted,
              "privacy": "private; contact redaction is best effort, not anonymization",
              "coverage": "flagged MAX sessions only; tails may be truncated; windows may overlap",
              "sessions": evidence}
    data = json.dumps(result, ensure_ascii=False, separators=(",", ":")).encode()
    if len(data) > MAX_OUTPUT:
        raise ValueError("output limit")
    return data


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="command", required=True)
    keygen = sub.add_parser("keygen")
    keygen.add_argument("--identity", required=True)
    keygen.add_argument("--recipient", required=True)
    encrypt = sub.add_parser("encrypt")
    encrypt.add_argument("--recipient", required=True)
    encrypt.add_argument("--sha", required=True)
    encrypt.add_argument("--live", required=True)
    encrypt.add_argument("--today", required=True)
    encrypt.add_argument("--yesterday", required=True)
    encrypt.add_argument("--output", required=True)
    decrypt = sub.add_parser("decrypt")
    decrypt.add_argument("--identity", required=True)
    decrypt.add_argument("--input", required=True)
    decrypt.add_argument("--sha", required=True)
    decrypt.add_argument("--output", required=True)
    args = parser.parse_args()
    try:
        if args.command == "keygen":
            if Path(args.recipient).exists():
                raise ValueError("recipient already exists")
            identity = pyrage.x25519.Identity.generate()
            private_write(args.identity, (str(identity) + "\n").encode())
            with Path(args.recipient).open("x") as out:
                out.write(str(identity.to_public()) + "\n")
        elif args.command == "encrypt":
            recipient = pyrage.x25519.Recipient.from_str(read_bounded(args.recipient, 256).decode().strip())
            reports = [(name, json.loads(read_bounded(getattr(args, name), MAX_INPUT)))
                       for name in ("live", "today", "yesterday")]
            data = build_evidence(reports, args.sha, str(recipient))
            private_write(args.output, pyrage.encrypt(data, [recipient]))
        else:
            identity = pyrage.x25519.Identity.from_str(read_bounded(args.identity, 256).decode().strip())
            ciphertext = read_bounded(args.input, MAX_OUTPUT + 65536)
            data = pyrage.decrypt(ciphertext, [identity])
            report = json.loads(data)
            if report.get("repository") != REPOSITORY or report.get("schema_version") != 1:
                raise ValueError("wrong evidence schema")
            if report.get("production_sha") != args.sha or report.get("recipient") != str(identity.to_public()):
                raise ValueError("wrong evidence provenance")
            fresh(report.get("generated_at"), dt.datetime.now(dt.timezone.utc), 60)
            private_write(args.output, data)
        print("Encrypted evidence operation succeeded")
        return 0
    except Exception:
        # Neither payload content, key material nor exception repr belongs in Actions logs.
        print("Encrypted evidence operation failed; no plaintext was published", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
