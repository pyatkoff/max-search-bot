"""Materialize exact MAX editor inputs from an approved scope and original receipts."""

import argparse
import hashlib
from io import BytesIO
import json
from pathlib import Path
import re
import zipfile


def require(condition, reason):
    if not condition:
        raise ValueError(reason)


def digest(data):
    return hashlib.sha256(data).hexdigest()


def read_verified(file, expected, limit):
    with file.open("rb") as stream:
        data = stream.read(limit + 1)
    require(0 < len(data) <= limit and digest(data) == expected, f"{file.name}: invalid receipt bytes")
    return data


def registry_zip(file, expected_zip, expected_json):
    encoded = read_verified(file, expected_zip, 4_000_000)
    with zipfile.ZipFile(BytesIO(encoded)) as archive:
        require(archive.namelist() == ["registry.json"], f"{file.name}: unexpected ZIP members")
        member = archive.getinfo("registry.json")
        require(0 < member.file_size <= 2_000_000, f"{file.name}: invalid registry size")
        data = archive.read(member)
    require(digest(data) == expected_json, f"{file.name}: invalid registry digest")
    return data


def stage_scope(scope_file, approved_digest, archive_file, native_file, stage):
    encoded = read_verified(scope_file, approved_digest, 256_000)
    scope = json.loads(encoded)
    native = scope["native_source"]
    archive_bytes = registry_zip(archive_file, scope["archive_sha256"], scope["archive_registry_sha256"])
    native_bytes = registry_zip(native_file, native["zip_sha256"], native["json_sha256"])

    entry = stage / "batch_entry.php"
    code, count = re.subn(r"(const MAX_BATCH_SCOPE_SHA256 = ')[a-f0-9]{64}(')",
                          lambda match: match[1] + approved_digest + match[2], entry.read_text())
    require(count == 1, "editor scope constant missing or ambiguous")
    (stage / "scope.json").write_bytes(encoded)
    (stage / "native-targets.json").write_bytes(archive_bytes)
    (stage / "native-source.json").write_bytes(native_bytes)
    entry.write_text(code)


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("scope", type=Path)
    parser.add_argument("digest")
    parser.add_argument("archive", type=Path)
    parser.add_argument("native", type=Path)
    parser.add_argument("stage", type=Path)
    args = parser.parse_args()
    stage_scope(args.scope, args.digest, args.archive, args.native, args.stage)
