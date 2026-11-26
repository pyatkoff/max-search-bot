"""Inspect a MAX photo batch Git commit and its original read receipts without writing."""
import argparse
import json
import re
import stat
import subprocess
import tempfile
import zipfile
from pathlib import Path

from validate_ranked_telegram_input import git_blob, git_output, require, sha, verify_git_scope


def read_receipt(path, limit):
    with path.open("rb") as stream:
        raw = stream.read(limit + 1)
    require(0 < len(raw) <= limit, "MAX receipt size outside the allowed range")
    return raw


def read_registry_zip(path):
    with zipfile.ZipFile(path) as archive:
        require(archive.namelist() == ["registry.json"], "unexpected MAX artifact members")
        member = archive.getinfo("registry.json")
        require(0 < member.file_size <= 8_000_000
                and not member.flag_bits & 1 and not stat.S_ISLNK(member.external_attr >> 16),
                "invalid MAX registry member")
        with archive.open(member) as stream:
            raw = stream.read(8_000_001)
        require(len(raw) == member.file_size, "MAX registry member size changed")
        return raw


def inspect_packet(repo, application_sha, data_sha, scope_sha, archive_bytes, source_bytes, *, repository_sha=None):
    repository_sha = application_sha if repository_sha is None else repository_sha
    for commit in (application_sha, repository_sha, data_sha):
        require(re.fullmatch(r"[a-f0-9]{40}", commit) is not None, "invalid Git commit identity")
    require(re.fullmatch(r"[a-f0-9]{64}", scope_sha) is not None, "invalid MAX scope digest")
    scope_path = f"config/anytour_max_photo_scope_{scope_sha}.json"
    raw = git_output(repo, "show", f"{data_sha}:{scope_path}")
    require(0 < len(raw) <= 256_000 and sha(raw) == scope_sha, "MAX scope bytes changed")
    verify_git_scope(repo, repository_sha, data_sha, scope_path, git_blob(raw), scope_sha, [],
                     allow_ranked_approval_append=False)

    scope = json.loads(raw)
    require(type(scope["version"]) is int and scope["version"] == 1, "unsupported MAX photo scope")
    rows = scope["rows"]
    require(isinstance(rows, list) and 0 < len(rows) <= 100, "MAX photo scope must contain 1–100 hotels")
    for field in ("hotel_id", "source_id", "source_mid", "source_url", "target_mid", "target_url"):
        values = [row[field] for row in rows]
        if field.endswith("_id"):
            require(all(type(value) is int and value > 0 for value in values), "invalid MAX scope " + field)
        else:
            require(all(isinstance(value, str) and value for value in values), "invalid MAX scope " + field)
        require(len(set(values)) == len(values), "duplicate MAX scope " + field)
    require({row["source_mid"] for row in rows}.isdisjoint(row["target_mid"] for row in rows),
            "MAX source cannot also be a destination")

    native = scope["native_source"]
    require(sha(source_bytes) == native["json_sha256"], "MAX native source receipt changed")
    require(native["application_sha"] == application_sha and native["repository_sha"] == repository_sha
            and type(native["attempt"]) is int and native["attempt"] == 1,
            "MAX native read belongs to a different application or attempt")
    archive = json.loads(archive_bytes)
    source = json.loads(source_bytes)
    archive_sha = sha(archive_bytes)
    archive_run_id = str(scope["archive_run_id"])
    require(re.fullmatch(r"[1-9][0-9]{0,19}", archive_run_id) is not None, "invalid MAX archive run")
    require(archive_sha == scope["archive_registry_sha256"]
            or (archive_sha == scope.get("archive_selected_sha256")
                and archive.get("registry_json_sha256") == scope["archive_registry_sha256"]),
            "MAX archive receipt changed")
    require(archive["ok"] is True and archive["history_complete"] is True
            and archive["price_content_shared_lease"] is True and str(archive["native_run_id"]) == archive_run_id
            and archive["production_writes"] == archive["provider_writes"] == 0, "invalid MAX archive receipt")
    require(source["ok"] is True and source["operation"] == "max_seven_source_rights"
            and source["application_sha"] == native["application_sha"]
            and source["repository_sha"] == native["repository_sha"] and str(source["archive_run"]) == archive_run_id
            and source["provider_writes"] == source["db_writes"] == source["bot_starts"] == 0
            and source["target_editor_rights"] is True, "invalid MAX native source receipt")


    native_rows = {row["hotel_id"]: row for row in source["rows"]}
    targets = {row["mid"]: row for row in archive["rows"]}
    require(len(native_rows) == len(source["rows"]) and len(targets) == len(archive["rows"]),
            "conflicting MAX receipt rows")
    for row in rows:
        observed = native_rows.get(row["hotel_id"], {})
        target = targets.get(row["target_mid"], {})
        require(all(observed.get(field) == row[field] for field in ("source_id", "source_mid", "target_mid", "target_url"))
                and target.get("url") == row["target_url"], "MAX scope differs from the original read receipts")

    return {
        "evidence_only": True,
        "write_admission": False,
        "application_sha": application_sha,
        "repository_sha": repository_sha,
        "data_source_sha": data_sha,
        "scope_sha256": scope_sha,
        "archive_run_id": archive_run_id,
        "native_source_run_id": native["run_id"],
        "hotel_count": len(rows),
    }


def editor_preview(scope_bytes, scope_sha, archive_bytes, source_bytes, repository_sha, application_sha):
    compiler = Path(__file__).with_name("max_photo_batch_preview.php")
    with tempfile.TemporaryDirectory(prefix="max-photo-preview-") as directory:
        files = [Path(directory) / name for name in ("scope.json", "archive.json", "source.json")]
        for path, raw in zip(files, (scope_bytes, archive_bytes, source_bytes)):
            path.write_bytes(raw)
        process = subprocess.run(
            ["php", str(compiler), str(files[0]), scope_sha, str(files[1]), str(files[2]),
             repository_sha, application_sha],
            capture_output=True, check=False, timeout=15,
        )
        require(process.returncode == 0, "existing MAX plan compiler refused the packet")
        result = json.loads(process.stdout)
        require(result["provider_writes"] == 0, "unexpected MAX compiler result")
        return result


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--git-root", type=Path, required=True)
    parser.add_argument("--application-sha", required=True)
    parser.add_argument("--repository-sha")
    parser.add_argument("--data-source-sha", required=True)
    parser.add_argument("--scope-sha256", required=True)
    parser.add_argument("--archive", type=Path, required=True)
    parser.add_argument("--native-source", type=Path, required=True)
    parser.add_argument("--registry-zips", action="store_true")
    parser.add_argument("--editor-preview", action="store_true")
    args = parser.parse_args()
    try:
        def receipt(path):
            return read_registry_zip(path) if args.registry_zips else read_receipt(path, 8_000_000)
        archive_bytes, source_bytes = receipt(args.archive), receipt(args.native_source)
        result = inspect_packet(args.git_root, args.application_sha, args.data_source_sha,
                                args.scope_sha256, archive_bytes, source_bytes,
                                repository_sha=args.repository_sha)
        if args.editor_preview:
            scope_path = f"config/anytour_max_photo_scope_{args.scope_sha256}.json"
            scope_bytes = git_output(args.git_root, "show", f"{args.data_source_sha}:{scope_path}")
            result["editor_preview"] = editor_preview(scope_bytes, args.scope_sha256, archive_bytes, source_bytes,
                                                       result["repository_sha"], args.application_sha)
    except (KeyError, OSError, TypeError, ValueError, zipfile.BadZipFile, subprocess.TimeoutExpired) as error:
        parser.exit(1, f"MAX packet refused: {error}\n")
    print(json.dumps(result))


if __name__ == "__main__":
    main()
