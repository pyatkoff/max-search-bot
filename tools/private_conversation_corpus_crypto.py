#!/usr/bin/env python3
"""Encrypt a complete private JSONL corpus; decrypt only to a private local file.

Requires the repository's pinned pyrage==1.3.0. This transport is separate from
the intentionally bounded encrypted_dialogue_evidence.py diagnostic workflow.
The collector's original bytes, including full message text, are preserved.
Never send plaintext, identities, or exception details to stdout/stderr.

Actions: collector | python tools/private_conversation_corpus_crypto.py encrypt
  --recipient docs/diagnostics-recipient.txt --sha EXACT_PRODUCTION_SHA --input -
  --output "$RUNNER_TEMP/private-corpus/corpus.age"
  --manifest "$RUNNER_TEMP/private-corpus/manifest.json"

Local: python tools/private_conversation_corpus_crypto.py decrypt
  --identity /private/owner.agekey --sha EXACT_PRODUCTION_SHA --input corpus.age
  --ciphertext-sha256 VERIFIED_DIGEST --output /private/corpus/corpus.jsonl

The plaintext capture and decrypt must each be at most one hour old. Artifact
origin/run/production provenance must additionally be verified by the caller;
an age recipient authenticates confidentiality, not the sender's identity.
"""

import argparse
import datetime as dt
import hashlib
import hmac
import importlib.metadata
import io
import json
import os
from pathlib import Path
import re
import stat
import sys

import pyrage


ROOT = Path(__file__).resolve().parents[1]
REPOSITORY = "pyatkoff/max-search-bot"
CORPUS_SCHEMA = "private_conversation_corpus_v1"
ENVELOPE_SCHEMA = "private_conversation_corpus_envelope_v1"
MANIFEST_SCHEMA = "private_conversation_corpus_manifest_v1"
PYRAGE_VERSION = "1.3.0"
MAX_PLAINTEXT_BYTES = 256 * 1024 * 1024
MAX_CIPHERTEXT_BYTES = MAX_PLAINTEXT_BYTES + 1024 * 1024
MAX_ENVELOPE_HEADER_BYTES = 4096
MAX_CAPTURE_SECONDS = 3600
COUNT_KEYS = ("conversations", "messages", "events", "assignments", "managers")
RECORD_TYPES = {
    "conversation": ("conversations", "c"),
    "message": ("messages", "m"),
    "event": ("events", "e"),
    "assignment": ("assignments", "a"),
    "manager": ("managers", "g"),
}


class CorpusError(ValueError):
    """Only fixed, non-customer error codes belong in this exception."""


def _require_crypto_version():
    if importlib.metadata.version("pyrage") != PYRAGE_VERSION:
        raise CorpusError("unsupported_crypto_version")


def _sha(value):
    if not isinstance(value, str) or not re.fullmatch(r"[0-9a-f]{40}", value):
        raise CorpusError("invalid_production_sha")
    return value


def _digest(value):
    if not isinstance(value, str) or not re.fullmatch(r"[0-9a-f]{64}", value):
        raise CorpusError("invalid_digest")
    return value


def _timestamp(value):
    if not isinstance(value, str) or len(value) > 40:
        raise CorpusError("invalid_timestamp")
    try:
        parsed = dt.datetime.fromisoformat(value.replace("Z", "+00:00"))
    except (ValueError, TypeError):
        raise CorpusError("invalid_timestamp") from None
    if parsed.tzinfo is None or parsed.utcoffset() != dt.timedelta(0):
        raise CorpusError("timestamp_must_be_utc")
    return parsed


def _now(value=None):
    result = value if value is not None else dt.datetime.now(dt.timezone.utc)
    if result.tzinfo is None:
        raise CorpusError("clock_must_be_aware")
    return result.astimezone(dt.timezone.utc)


def _fresh(value, now):
    parsed = _timestamp(value)
    if not -60 <= (now - parsed).total_seconds() <= MAX_CAPTURE_SECONDS:
        raise CorpusError("stale_capture")
    return parsed


def _size_limit(value):
    if type(value) is not int or not 1 <= value <= MAX_PLAINTEXT_BYTES:
        raise CorpusError("invalid_size_limit")
    return value


def _unique_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise CorpusError("duplicate_json_key")
        result[key] = value
    return result


def _invalid_constant(_value):
    raise CorpusError("invalid_json_constant")


def _json_object(data):
    try:
        result = json.loads(data, object_pairs_hook=_unique_object,
                            parse_constant=_invalid_constant)
    except (UnicodeError, ValueError, RecursionError):
        raise CorpusError("invalid_json_object") from None
    if not isinstance(result, dict):
        raise CorpusError("invalid_json_object")
    return result


def _counts(value):
    if not isinstance(value, dict) or set(value) != set(COUNT_KEYS):
        raise CorpusError("invalid_counts")
    if any(type(number) is not int or number < 0 for number in value.values()):
        raise CorpusError("invalid_counts")
    return dict(value)


def validate_corpus(data, expected_sha, *, now=None, max_bytes=MAX_PLAINTEXT_BYTES):
    """Fail closed unless the complete collector stream matches its manifest.

    Full message text is deliberately not transformed, shortened, or returned
    in this safe summary. The caller retains and encrypts the original bytes.
    """
    expected_sha = _sha(expected_sha)
    now = _now(now)
    max_bytes = _size_limit(max_bytes)
    if not isinstance(data, bytes) or not 0 < len(data) <= max_bytes:
        raise CorpusError("plaintext_size_limit")
    if not data.endswith(b"\n"):
        raise CorpusError("incomplete_jsonl")
    observed = dict.fromkeys(COUNT_KEYS, 0)
    ids = {kind: set() for kind in RECORD_TYPES}
    header = None
    footer = None
    for line in io.BytesIO(data):
        if not line.strip() or footer is not None:
            raise CorpusError("unexpected_jsonl_record")
        row = _json_object(line)
        kind = row.get("type")
        if header is None:
            if kind != "header" or row.get("schema") != CORPUS_SCHEMA:
                raise CorpusError("invalid_header")
            if row.get("repository") != REPOSITORY or row.get("production_sha") != expected_sha:
                raise CorpusError("wrong_provenance")
            if row.get("project_scope") != ["anytour"]:
                raise CorpusError("wrong_project_scope")
            generated = _fresh(row.get("generated_at"), now)
            expected = _counts(row.get("expected_counts"))
            _counts(row.get("upper_ids"))
            snapshot = row.get("snapshot")
            if (not isinstance(snapshot, dict)
                    or snapshot.get("isolation") != "REPEATABLE READ"
                    or snapshot.get("read_only") is not True):
                raise CorpusError("unsafe_snapshot")
            if not isinstance(row.get("privacy"), dict):
                raise CorpusError("missing_privacy_manifest")
            header = row
        elif kind == "footer":
            if row.get("schema") != CORPUS_SCHEMA or row.get("complete") is not True:
                raise CorpusError("incomplete_capture")
            if (_counts(row.get("expected_counts")) != expected
                    or _counts(row.get("exported_counts")) != expected
                    or observed != expected):
                raise CorpusError("incomplete_counts")
            finished = _fresh(row.get("finished_at"), now)
            if not 0 <= (finished - generated).total_seconds() <= MAX_CAPTURE_SECONDS:
                raise CorpusError("invalid_capture_duration")
            footer = row
        elif isinstance(kind, str) and kind in RECORD_TYPES:
            count_key, prefix = RECORD_TYPES[kind]
            identifier = row.get("id")
            if (not isinstance(identifier, str)
                    or not re.fullmatch(prefix + r"_[0-9a-f]{24}", identifier)
                    or identifier in ids[kind]):
                raise CorpusError("invalid_record_identity")
            ids[kind].add(identifier)
            observed[count_key] += 1
            if observed[count_key] > expected[count_key]:
                raise CorpusError("excess_record_count")
        else:
            raise CorpusError("unexpected_jsonl_record")
    if header is None or footer is None:
        raise CorpusError("missing_complete_footer")
    return {"schema": CORPUS_SCHEMA, "repository": REPOSITORY,
            "production_sha": expected_sha, "generated_at": header["generated_at"],
            "finished_at": footer["finished_at"], "counts": observed,
            "plaintext_bytes": len(data), "plaintext_sha256": hashlib.sha256(data).hexdigest()}


def read_bounded(path, limit, *, secret=False):
    """Read regular files without following a final symlink; never print data."""
    if type(limit) is not int or limit < 1:
        raise CorpusError("invalid_read_limit")
    flags = os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK
    descriptor = os.open(os.fspath(path), flags)
    with os.fdopen(descriptor, "rb") as stream:
        metadata = os.fstat(stream.fileno())
        if not stat.S_ISREG(metadata.st_mode) or metadata.st_size > limit:
            raise CorpusError("invalid_input_file")
        if secret and (metadata.st_uid != os.geteuid() or metadata.st_mode & 0o077):
            raise CorpusError("identity_not_private")
        data = stream.read(limit + 1)
    if len(data) > limit:
        raise CorpusError("input_size_limit")
    return data


def _recipient_from_file(path):
    try:
        value = read_bounded(path, 256).decode("ascii").strip()
        return pyrage.x25519.Recipient.from_str(value)
    except Exception:
        raise CorpusError("invalid_recipient_file") from None


def _identity_from_file(path):
    try:
        value = read_bounded(path, 256, secret=True).decode("ascii").strip()
        return pyrage.x25519.Identity.from_str(value)
    except Exception:
        raise CorpusError("invalid_private_identity") from None


def _output_parent(path, *, checkout_root=ROOT):
    """Hold a no-symlink directory fd; make missing private directories 0700.

    The final parent must be owned by this user and exactly 0700. Existing
    directory modes are never changed. No part of the path may be a symlink.
    """
    absolute = Path(os.path.abspath(os.fspath(path)))
    checkout = Path(checkout_root).resolve()
    if absolute.is_relative_to(checkout):
        raise CorpusError("private_output_inside_checkout")
    if absolute.name in {"", ".", ".."}:
        raise CorpusError("invalid_output_path")
    descriptor = os.open("/", os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        for component in absolute.parent.parts[1:]:
            try:
                child = os.open(component, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW,
                                dir_fd=descriptor)
            except FileNotFoundError:
                try:
                    os.mkdir(component, 0o700, dir_fd=descriptor)
                except FileExistsError:
                    pass
                child = os.open(component, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW,
                                dir_fd=descriptor)
            os.close(descriptor)
            descriptor = child
        metadata = os.fstat(descriptor)
        if metadata.st_uid != os.geteuid() or stat.S_IMODE(metadata.st_mode) != 0o700:
            raise CorpusError("output_directory_not_private")
        return descriptor, absolute.name
    except BaseException:
        os.close(descriptor)
        raise


def private_write_many(items, *, checkout_root=ROOT):
    """Exclusive writes; remove every newly created output if any write fails."""
    parents = []
    created = []
    try:
        for path, data in items:
            parent, name = _output_parent(path, checkout_root=checkout_root)
            parents.append(parent)
            descriptor = os.open(name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW,
                                 0o600, dir_fd=parent)
            created.append((parent, name))
            with os.fdopen(descriptor, "wb") as stream:
                os.fchmod(stream.fileno(), 0o600)
                stream.write(data)
                stream.flush()
                os.fsync(stream.fileno())
    except BaseException:
        for parent, name in reversed(created):
            os.unlink(name, dir_fd=parent)
        raise
    finally:
        for parent in parents:
            os.close(parent)


def encrypt_corpus(data, recipient, expected_sha, *, now=None,
                   max_bytes=MAX_PLAINTEXT_BYTES):
    _require_crypto_version()
    now = _now(now)
    summary = validate_corpus(data, expected_sha, now=now, max_bytes=max_bytes)
    public = pyrage.x25519.Recipient.from_str(str(recipient))
    envelope = {"schema": ENVELOPE_SCHEMA, "repository": REPOSITORY,
                "production_sha": summary["production_sha"], "recipient": str(public),
                "plaintext_bytes": summary["plaintext_bytes"],
                "plaintext_sha256": summary["plaintext_sha256"], "encrypted_at": now.isoformat()}
    encoded_header = json.dumps(envelope, separators=(",", ":")).encode("ascii")
    ciphertext = pyrage.encrypt(encoded_header + b"\n" + data, [public])
    if len(ciphertext) > MAX_CIPHERTEXT_BYTES:
        raise CorpusError("ciphertext_size_limit")
    manifest = {**summary, "schema": MANIFEST_SCHEMA, "recipient": str(public),
                "encrypted_at": envelope["encrypted_at"], "ciphertext_bytes": len(ciphertext),
                "ciphertext_sha256": hashlib.sha256(ciphertext).hexdigest()}
    return ciphertext, manifest


def decrypt_corpus(ciphertext, identity, expected_sha, expected_ciphertext_sha256,
                   *, now=None, max_bytes=MAX_PLAINTEXT_BYTES):
    _require_crypto_version()
    expected_sha = _sha(expected_sha)
    expected_digest = _digest(expected_ciphertext_sha256)
    max_bytes = _size_limit(max_bytes)
    now = _now(now)
    if (not isinstance(ciphertext, bytes) or not ciphertext
            or len(ciphertext) > MAX_CIPHERTEXT_BYTES):
        raise CorpusError("ciphertext_size_limit")
    if not hmac.compare_digest(hashlib.sha256(ciphertext).hexdigest(), expected_digest):
        raise CorpusError("ciphertext_digest_mismatch")
    try:
        decrypted = pyrage.decrypt(ciphertext, [identity])
    except Exception:
        raise CorpusError("age_decryption_failed") from None
    if len(decrypted) > max_bytes + MAX_ENVELOPE_HEADER_BYTES:
        raise CorpusError("plaintext_size_limit")
    separator = decrypted.find(b"\n", 0, MAX_ENVELOPE_HEADER_BYTES)
    if separator < 1:
        raise CorpusError("invalid_envelope")
    envelope = _json_object(decrypted[:separator])
    if (envelope.get("schema") != ENVELOPE_SCHEMA
            or envelope.get("repository") != REPOSITORY
            or envelope.get("production_sha") != expected_sha
            or envelope.get("recipient") != str(identity.to_public())):
        raise CorpusError("wrong_envelope_provenance")
    _fresh(envelope.get("encrypted_at"), now)
    plaintext = decrypted[separator + 1:]
    if (type(envelope.get("plaintext_bytes")) is not int
            or envelope["plaintext_bytes"] != len(plaintext)
            or not hmac.compare_digest(_digest(envelope.get("plaintext_sha256")),
                                       hashlib.sha256(plaintext).hexdigest())):
        raise CorpusError("plaintext_digest_mismatch")
    validate_corpus(plaintext, expected_sha, now=now, max_bytes=max_bytes)
    return plaintext


def _parser():
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest="command", required=True)
    encrypt = commands.add_parser("encrypt")
    encrypt.add_argument("--recipient", required=True)
    encrypt.add_argument("--sha", required=True)
    encrypt.add_argument("--input", required=True, help="JSONL path or - for stdin")
    encrypt.add_argument("--output", required=True)
    encrypt.add_argument("--manifest", required=True)
    encrypt.add_argument("--max-bytes", type=int, default=MAX_PLAINTEXT_BYTES)
    decrypt = commands.add_parser("decrypt")
    decrypt.add_argument("--identity", required=True)
    decrypt.add_argument("--sha", required=True)
    decrypt.add_argument("--input", required=True)
    decrypt.add_argument("--ciphertext-sha256", required=True)
    decrypt.add_argument("--output", required=True)
    decrypt.add_argument("--max-bytes", type=int, default=MAX_PLAINTEXT_BYTES)
    return parser


def main(argv=None):
    args = _parser().parse_args(argv)
    try:
        max_bytes = _size_limit(args.max_bytes)
        if args.command == "encrypt":
            recipient = _recipient_from_file(args.recipient)
            data = (sys.stdin.buffer.read(max_bytes + 1) if args.input == "-"
                    else read_bounded(args.input, max_bytes))
            ciphertext, manifest = encrypt_corpus(data, recipient, args.sha, max_bytes=max_bytes)
            manifest_bytes = (json.dumps(manifest, sort_keys=True, separators=(",", ":"))
                              + "\n").encode("ascii")
            private_write_many([(args.output, ciphertext), (args.manifest, manifest_bytes)])
        else:
            identity = _identity_from_file(args.identity)
            ciphertext = read_bounded(args.input, MAX_CIPHERTEXT_BYTES)
            plaintext = decrypt_corpus(ciphertext, identity, args.sha, args.ciphertext_sha256,
                                       max_bytes=max_bytes)
            private_write_many([(args.output, plaintext)])
        print("Private corpus operation succeeded")
        return 0
    except Exception:
        # Do not expose customer data, file contents, keys, or exception repr.
        print("Private corpus operation failed; no plaintext was published", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
