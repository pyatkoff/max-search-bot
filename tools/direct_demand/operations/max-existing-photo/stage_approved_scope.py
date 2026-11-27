"""Materialize exact MAX editor inputs from an approved scope and original receipts."""

import argparse
from datetime import datetime, timedelta
import hashlib
from io import BytesIO
import json
from pathlib import Path
import re
import zipfile

from run_batch import read_preparation


def require(condition, reason):
    if not condition:
        raise ValueError(reason)


def digest(data):
    return hashlib.sha256(data).hexdigest()


def verify_activation(commit, before, head, path):
    require(commit.get("sha") == head and [row["sha"] for row in commit.get("parents", [])] == [before],
            "activation requires a direct push of one commit")
    files = commit.get("files", [])
    require(len(files) == 1 and files[0]["filename"] == path and files[0]["status"] in {"added", "modified"},
            "activation must change only the MAX2 route evidence file")


def verify_adopted_publisher(comparison, reviewed, activation_path):
    require(comparison.get("status") == "ahead" and comparison.get("behind_by") == 0
            and comparison.get("merge_base_commit", {}).get("sha") == reviewed,
            "activation is outside the accepted publisher history")
    files = {row["filename"] for row in comparison.get("files", [])}
    allowed = {activation_path, ".github/workflows/read-1566-old-ordinary-max-posts.yml"}
    require(activation_path in files and files.issubset(allowed),
            "publisher code changed since the accepted PR")


def replace_route_read(scope, route):
    require(isinstance(route, dict) and set(route) == {"targets", "run_id", "job_id", "observed_utc"},
            "unexpected MAX2 route evidence fields")
    targets = [{"offer_id": "max_" + str(row["hotel_id"]), "target_mid": row["target_mid"]}
               for row in scope["rows"]]
    require(route["targets"] == targets, "MAX2 route evidence targets differ from the approved scope")
    require(all(type(route[key]) is int and route[key] > 0 for key in ("run_id", "job_id")),
            "invalid MAX2 route run or job")
    timestamp = route["observed_utc"]
    require(isinstance(timestamp, str) and "T" in timestamp
            and datetime.fromisoformat(timestamp).utcoffset() == timedelta(0), "invalid MAX2 route time")
    readback = scope["route_readback"]
    require(readback["repository"] == "pyatkoff/tour-max" and readback["write_calls"] == 0,
            "invalid approved MAX2 read policy")
    for key in ("run_id", "job_id", "observed_utc"):
        readback[key] = route[key]


def apply_activation(scope, activation):
    """Refresh evidence for the same editorial scope. Return whether native inputs changed."""
    require(isinstance(activation, dict), "invalid MAX activation")
    route = {key: value for key, value in activation.items() if key != "native_source"}
    previous = scope["native_source"]
    selected = previous
    if "native_source" in activation:
        fresh = activation["native_source"]
        ids = ("run_id", "job_id", "artifact_id")
        heads = ("workflow_head", "repository_sha", "application_sha")
        sums = ("zip_sha256", "json_sha256")
        require(isinstance(fresh, dict) and set(fresh) == set(ids + heads + sums),
                "unexpected native proof fields")
        require(all(type(fresh[key]) is int and fresh[key] > 0 for key in ids), "invalid native proof IDs")
        require(all(isinstance(fresh[key], str) and re.fullmatch("[a-f0-9]{40}", fresh[key]) for key in heads),
                "invalid native proof commit")
        require(all(isinstance(fresh[key], str) and re.fullmatch("[a-f0-9]{64}", fresh[key]) for key in sums),
                "invalid native proof digest")
        selected = {**previous, **fresh}

    replace_route_read(scope, route)
    scope["native_source"] = selected
    return selected != previous


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


def stage_scope(scope_file, approved_digest, archive_file, native_file, stage, route_file=None):
    encoded = read_verified(scope_file, approved_digest, 256_000)
    scope = json.loads(encoded)
    native = scope["native_source"]
    archive_bytes = registry_zip(archive_file, scope["archive_sha256"], scope["archive_registry_sha256"])
    native_bytes = registry_zip(native_file, native["zip_sha256"], native["json_sha256"])

    if route_file is not None:
        with route_file.open("rb") as stream:
            route_bytes = stream.read(16_001)
        require(len(route_bytes) <= 16_000, "MAX2 route evidence file is too large")
        replace_route_read(scope, json.loads(route_bytes))
        encoded = (json.dumps(scope, ensure_ascii=False, indent=2) + "\n").encode()
        approved_digest = digest(encoded)

    entry = stage / "batch_entry.php"
    code, count = re.subn(r"(const MAX_BATCH_SCOPE_SHA256 = ')[a-f0-9]{64}(')",
                          lambda match: match[1] + approved_digest + match[2], entry.read_text())
    require(count == 1, "editor scope constant missing or ambiguous")
    (stage / "scope.json").write_bytes(encoded)
    (stage / "native-targets.json").write_bytes(archive_bytes)
    (stage / "native-source.json").write_bytes(native_bytes)
    entry.write_text(code)


def stage_preparation(file, expected, stage):
    encoded = read_verified(file, expected, 1_000_000)
    with zipfile.ZipFile(BytesIO(encoded)) as archive:
        members = archive.namelist()
        require(len(members) == 3 and set(members) == {"manifest.json", "batch-plan.json", "preparation.json"},
                "unexpected preparation ZIP members")
        for name, target, limit in (("manifest.json", "preparation-scope.json", 256_000),
                                    ("preparation.json", "preparation.json", 128_000)):
            member = archive.getinfo(name)
            require(0 < member.file_size <= limit, f"{name}: invalid preparation member size")
            (stage / target).write_bytes(archive.read(member))
    receipt = stage / "preparation.json"
    rows = read_preparation(receipt, digest(receipt.read_bytes()), stage)
    print("MAX_REUSED_NATIVE_CHECKPOINTS=" + str(sum(row["state"] == "ready_read_only" for row in rows)))


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("scope", type=Path)
    parser.add_argument("digest")
    parser.add_argument("archive", type=Path)
    parser.add_argument("native", type=Path)
    parser.add_argument("stage", type=Path)
    parser.add_argument("--preparation", nargs=2, metavar=("ZIP", "SHA256"))
    parser.add_argument("--route-readback", type=Path)
    args = parser.parse_args()
    stage_scope(args.scope, args.digest, args.archive, args.native, args.stage, args.route_readback)
    if args.preparation is not None:
        file, expected = args.preparation
        stage_preparation(Path(file), expected, args.stage)
