#!/usr/bin/env python3
"""Prepare one owner release for the admitted Telegram rows. This command works offline.

python3 prepare_ranked_46_release.py --run run.json --artifact artifact.json --zip receipt.zip \
    --repo /path/to/owner-checkout --out new-output-directory

The repository must contain the accepted three-hotel editor from #1664. Changes are emitted
as one checked Git patch. The caller's Git checkout, channel posts and live bindings are untouched.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile

from build_telegram_46 import compile_inputs, digest, need, raw_json, unpack
from writer_gate_46 import bind_gate
from writer_profile_46 import bind_sources

WORKFLOW = ".github/workflows/edit-1566-next-historical-telegram.yml"
EDITOR = "tools/anytour_existing_post_batch.php"
RELEASE = "tools/deployment_release.js"
REGISTRY = "services/ChannelPostRegistryService.php"
TRANSPORT = "integrations/ChannelPostApiTransport.php"
PRICE_PLAN = "config/anytour_existing_post_transfer_four_20261123.json"
NEXT_PLAN = "config/anytour_existing_post_transfer_next_20261123.json"
INDEX = "config/anytour_ranked_46_20261123.json"
BRANCH = "ops/anytour-hotel-ranked-46-apply-20261123"
READ_SHA = "db1157a5091ff0f708d7825acf6ca4e1dc9a5339"


def git(folder, *args):
    command = ["git", "-c", "core.hooksPath=/dev/null", "-c", "core.autocrlf=false",
               "-c", "core.fsmonitor=false", "-C", str(folder), *args]
    return subprocess.run(command, check=True, stdout=subprocess.PIPE).stdout


def blob(data):
    return hashlib.sha1(b"blob " + str(len(data)).encode() + b"\0" + data).hexdigest()


def write_files(folder, files):
    for name, data in files.items():
        path = Path(name)
        need(not path.is_absolute() and ".." not in path.parts, "invalid_generated_path")
        target = folder / path
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(data)


def repo_sources(repo):
    root = Path(git(repo, "rev-parse", "--show-toplevel").decode().strip()).resolve()
    need(root == repo.resolve(), "owner_checkout_root_expected")
    head = git(repo, "rev-parse", "HEAD").decode().strip()
    need(re.fullmatch(r"[0-9a-f]{40}", head), "invalid_base_commit")
    paths = [WORKFLOW, EDITOR, RELEASE, REGISTRY, TRANSPORT, PRICE_PLAN, NEXT_PLAN]
    return head, {name: git(repo, "show", head + ":" + name).decode("utf-8") for name in paths}


def check_plans(stage, php, plans):
    for path in [EDITOR, REGISTRY, TRANSPORT]:
        subprocess.run([php, "-l", str(stage / path)], check=True, stdout=subprocess.DEVNULL)
    for path, raw in plans:
        expected = json.loads(raw)["rows"]
        result = json.loads(subprocess.run(
            [php, str(stage / EDITOR), "--check-ranked-transfer-plan", digest(raw)],
            check=True, stdout=subprocess.PIPE, cwd=stage).stdout)
        need(result.get("ok") is True and result.get("mode") == "offline_plan_check"
             and result.get("production_writes") == result.get("provider_writes") == 0
             and result.get("plan_sha256") == digest(raw)
             and [(r["hotel_id"], r["target_url"], r["caption_sha256"]) for r in result["rows"]] ==
                 [(r["hotel_id"], "https://t.me/anytour/" + r["target_message_id"], r["next_caption_sha256"])
                  for r in expected], "installed_editor_offline_check_failed")
    for path, flag in [(PRICE_PLAN, "--check-transfer-plan"), (NEXT_PLAN, "--check-next-transfer-plan")]:
        old = (stage / path).read_bytes()
        result = json.loads(subprocess.run([php, str(stage / EDITOR), flag, digest(old)],
                                           check=True, stdout=subprocess.PIPE, cwd=stage).stdout)
        need(result.get("ok") is True and result.get("plan_sha256") == digest(old), "prior_owner_plan_changed")


def package(repo, records, proof, channel_ready, zip_size, php, node):
    inputs = compile_inputs(records, proof, channel_ready, "transfer")
    dispositions = json.loads(inputs["index.json"])
    summary = dict(native_preflight=proof, ready=dispositions["ready"], held=dispositions["held"])
    if not summary["ready"]:
        summary["outcome"] = "no_eligible_rows"
        return {"index.json": raw_json(summary)}
    base_sha, base = repo_sources(repo)
    plans = [("config/anytour_ranked_46_20261123_%02d.json" % i, inputs[name])
             for i, name in enumerate(dispositions["plans"], 1)]
    owner_index = dict(schema=1, native_preflight=proof, native_zip_bytes=zip_size, plans=[
        dict(path=path, bytes=len(data), git_blob=blob(data), sha256=digest(data)) for path, data in plans])
    index_raw = raw_json(owner_index)
    editor_changes = bind_sources(base, plans)
    gate_changes = bind_gate(base, plans, INDEX, index_raw)
    need(not (editor_changes.keys() & gate_changes.keys()), "overlapping_generator_outputs")
    sources = {**editor_changes, **gate_changes}
    original = {path: text.encode() for path, text in base.items()}
    changes = {path: text.encode() for path, text in sources.items()}
    need(not ({k for k, _ in plans} | {INDEX}) & changes.keys(), "generated_path_collision")
    changes.update(dict(plans))
    changes[INDEX] = index_raw
    with tempfile.TemporaryDirectory() as scratch:
        stage = Path(scratch)
        git(stage, "init", "-q")
        write_files(stage, original)
        git(stage, "add", "--all")
        tree = git(stage, "write-tree").decode().strip()
        write_files(stage, changes)
        check_plans(stage, php, plans)
        subprocess.run([node, "--check", str(stage / RELEASE)], check=True)
        git(stage, "add", "--all")
        git(stage, "diff", "--cached", "--check", tree)
        patch = git(stage, "diff", "--cached", "--binary", "--full-index", "--no-renames", tree)
    # Verify applicability against the caller's actual working copy without modifying files or its index.
    subprocess.run(["git", "-C", str(repo), "apply", "--check", "-"], input=patch, check=True)
    summary.update(outcome="prepared_for_one_owner_release", base_sha=base_sha, owner_trigger=BRANCH,
                   release_patch_sha256=digest(patch), owner_index_sha256=digest(index_raw),
                   receipt_reader_commit=READ_SHA, plans=owner_index["plans"],
                   files=sorted(changes))
    return {"index.json": raw_json(summary), "owner.patch": patch}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    for key in ["run", "artifact", "zip", "repo", "out"]:
        parser.add_argument("--" + key, required=True, type=Path)
    parser.add_argument("--php", default="php", help="PHP CLI with the extensions used by the existing editor")
    parser.add_argument("--node", default="node", help="Node executable used to check the existing release verifier")
    args = parser.parse_args()
    need(not args.out.exists() and args.out.parent.is_dir(), "fresh_output_directory_required")
    need(args.zip.stat().st_size <= 8_000_000, "archive_too_large")
    zipped = args.zip.read_bytes()
    records, proof, ready = unpack(json.loads(args.run.read_bytes()), json.loads(args.artifact.read_bytes()), zipped)
    files = package(args.repo, records, proof, ready, len(zipped), args.php, args.node)
    with tempfile.TemporaryDirectory(dir=args.out.parent) as staging:
        staged = Path(staging) / "result"
        staged.mkdir()
        write_files(staged, files)
        os.rename(staged, args.out)
    summary = json.loads(files["index.json"])
    print(summary["outcome"], len(summary["ready"]), "ready;", len(summary["held"]), "held")


if __name__ == "__main__":
    main()
