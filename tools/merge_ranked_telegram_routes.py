"""Prepare one ROUTE candidate for completed public readbacks, even before candidate uploads are installed.

Usage: python3 tools/merge_ranked_telegram_routes.py FRESH_RAW_XML NEW_OUTPUT_DIRECTORY PUBLIC_RUN_ID [PUBLIC_RUN_ID ...]
The existing ROUTE owner supplies the checked-out resolver and admits the result against the current base.
"""

import argparse
from collections import Counter
import hashlib
import io
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import zipfile

from collect_ranked_route_manifests import api, OBSERVER, REPO
from prepare_ranked_telegram_routes import ROOT, RESOLVER, materialize_ranked_data, project, require

WRITER = ".github/workflows/edit-1566-ranked-46-historical-telegram.yml"
LATE_READER = ".github/workflows/read-1566-telegram-46-20261123.yml"
LATE_BRANCH = "ops/1566-telegram-46-native-read-20261123"
OWNER_ID = 226193297

# Original late receipts reviewed in ROUTE #1699, comment 7191354146.
# Pin these two native/readback chains. A registry push is not a general observer.
LATE_PUBLIC_RECEIPTS = {
    "45799877337": {
        "head": "3d21cbcf0f04709a2b83898337b7718a7680a48c",
        "job": 139840014674,
        "native": "45799514607",
        "artifact": 15107016255,
        "name": "ranked-public-readback-late-45799514607",
        "digest": "sha256:8b0d8d0e8520bad522b5d70c4ab2f7f890b44effbf075455566ed763e976b46d",
    },
    "45802597406": {
        "head": "e0fb2920ef2105dcffc0a56b8f45367626d1bd25",
        "job": 139849399157,
        "native": "45801952244",
        "artifact": 15108431030,
        "name": "ranked-public-readback-late-tested-45801952244",
        "digest": "sha256:761505488982489ab39bbf2641823f4fdbae98034cc5f2be30738b32306cffe6",
    },
}


def original_run(run_id, workflow, event):
    run = api(f"actions/runs/{run_id}")
    require(str(run["id"]) == run_id and run["run_attempt"] == 1 and run["status"] == "completed"
            and run["conclusion"] in ("success", "failure") and run["event"] == event
            and run["path"] == workflow and run["repository"]["full_name"] == REPO
            and run["head_repository"]["full_name"] == REPO and re.fullmatch("[a-f0-9]{40}", run["head_sha"]),
            "invalid original workflow run")
    return run


def artifact(run, pattern):
    packs = api(f'actions/runs/{run["id"]}/artifacts?per_page=100')
    require(packs["total_count"] == len(packs["artifacts"]), "incomplete artifact inventory")
    matched = [a for a in packs["artifacts"] if re.fullmatch(pattern, a["name"])]
    require(len(matched) == 1, "expected one original workflow artifact")
    a = matched[0]
    require(not a["expired"] and 0 < a["size_in_bytes"] <= 2_000_000
            and a["workflow_run"]["id"] == run["id"] and a["workflow_run"]["head_sha"] == run["head_sha"]
            and a["workflow_run"]["head_branch"] == run["head_branch"], "artifact attribution invalid")
    body = api(f'actions/artifacts/{a["id"]}/zip', binary=True)
    require(len(body) == a["size_in_bytes"] and a["digest"] == "sha256:" + hashlib.sha256(body).hexdigest(),
            "artifact archive changed")
    return a, body


def unpack(body, names, target):
    target.mkdir()
    with zipfile.ZipFile(io.BytesIO(body)) as arc:
        require(sorted(arc.namelist()) == sorted(names), "original archive inventory invalid")
        for member in arc.infolist():
            require(0 < member.file_size <= 1_000_000 and not member.flag_bits & 1, "invalid receipt entry")
            (target / member.filename).write_bytes(arc.read(member))


def check_observer_job(observer, job_name, steps):
    run_id = str(observer["id"])
    jobs = api(f"actions/runs/{run_id}/attempts/1/jobs?per_page=100")
    require(jobs["total_count"] == len(jobs["jobs"]) == 1, "public job inventory invalid")
    job = jobs["jobs"][0]
    require(str(job["run_id"]) == run_id and job["run_attempt"] == 1 and job["head_sha"] == observer["head_sha"]
            and job["name"] == job_name and job["status"] == "completed", "public job attribution invalid")
    for name, allowed in steps:
        found = [step["conclusion"] for step in job["steps"] if step["name"] == name]
        require(len(found) == 1 and found[0] in allowed, "original public verification or upload missing")
    return job


def observe_original(run_id):
    late = LATE_PUBLIC_RECEIPTS.get(run_id)
    if late is not None:
        observer = original_run(run_id, LATE_READER, "push")
        require(observer["head_branch"] == LATE_BRANCH and observer["head_sha"] == late["head"]
                and observer["conclusion"] == "success" and observer["actor"]["id"] == OWNER_ID
                and observer["triggering_actor"]["id"] == OWNER_ID, "unrecognized late public observer")
        job = check_observer_job(observer, "registry", [
            ("Authenticate the original transfer, job and immutable artifact", ["success"]),
            ("Read and verify published Telegram cards", ["success"]),
            ("Preserve independent late public evidence separately", ["success"]),
        ])
        require(job["id"] == late["job"] and job["conclusion"] == "success", "unrecognized late public job")
        public, body = artifact(observer, re.escape(late["name"]))
        require(public["id"] == late["artifact"] and public["digest"] == late["digest"],
                "unrecognized late public archive")
        return observer, public, body, late["native"]

    observer = original_run(run_id, OBSERVER, "workflow_run")
    require(observer["head_branch"] == "main", "public observer must run from main")
    check_observer_job(observer, "public-readback", [
        ("Authenticate the original transfer, job and immutable artifact", ["success"]),
        ("Read and verify published Telegram cards", ["success", "failure"]),
        ("Save verified destinations for the existing route publisher", ["success"]),
    ])
    public, body = artifact(observer, r"ranked-public-readback-[1-9][0-9]{0,19}")
    return observer, public, body, None


def stage_original(run_id, feed, folder):
    observer, pub, body, expected_native_id = observe_original(run_id)
    public_dir = folder / "public"
    unpack(body, ["ranked-public-readback.json"], public_dir)
    report_file = public_dir / "ranked-public-readback.json"
    report = json.loads(report_file.read_bytes())
    if expected_native_id is None:
        require(pub["name"] == "ranked-public-readback-" + report["run_id"], "native/public run mismatch")
    else:
        require(report["run_id"] == expected_native_id and report["read_only"] is True
                and report["provider_write_attempts"] == 0, "late native/public run mismatch")
    native = original_run(report["run_id"], WRITER, "push")
    require(native["head_sha"] == report["repository_sha"] and native["actor"]["id"] == OWNER_ID
            and native["triggering_actor"]["id"] == OWNER_ID, "original native producer mismatch")
    receipt, archive = artifact(native, "issue-1566-hotel-ranked-46-transfer")
    require(str(receipt["id"]) == report["artifact_id"] and receipt["digest"] == "sha256:" + report["artifact_sha256"],
            "native archive differs from independently observed archive")
    branch = native["head_branch"]
    data_prefix = "ops/anytour-hotel-ranked-data-"
    root = ROOT
    if branch.startswith(data_prefix):
        require(report["write_operation"] == "hotel_ranked_approved_input", "invalid detached writer operation")
        root = folder / "ranked-data"
        approved = [materialize_ranked_data(api, native["head_sha"], branch[len(data_prefix):], root)]
    else:
        registry = json.loads((ROOT / "config/anytour_ranked_approved_indexes.json").read_bytes())
        if report["write_operation"] == "hotel_ranked_approved_input":
            prefix = "ops/anytour-hotel-ranked-plan-"
            require(branch.startswith(prefix), "approved plan branch invalid")
            approved = [item for item in registry if item["sha256"] == branch[len(prefix):]]
        else:
            approved = [item for item in registry if item["path"] == "config/anytour_ranked_46_20261123.json"]
    require(len(approved) == 1, "index no longer approved")
    index_file = root / approved[0]["path"]
    index = index_file.read_bytes()
    require(hashlib.sha256(index).hexdigest() == approved[0]["sha256"], "approved index changed")
    names = {"summary.json"}
    for item in json.loads(index)["plans"]:
        names.update(f"{kind}-{item['sha256']}.json" for kind in ("check", "result"))
    unpack(archive, names, folder / "native")
    (folder / "native.zip").write_bytes(archive)
    env = dict(os.environ, RUNNER_TEMP=str(folder), RANKED_APPROVED_INDEX=approved[0]["path"],
               WRITE_OPERATION=report["write_operation"], NATIVE_BRANCH=native["head_branch"],
               NATIVE_REPOSITORY_SHA=native["head_sha"], NATIVE_RUN_ID=report["run_id"],
               NATIVE_PARENT_RUN_ID=report.get("parent_run_id", ""), RECEIPT_ID=str(receipt["id"]),
               RECEIPT_SHA256=report["artifact_sha256"], GITHUB_RUN_ID=run_id, GITHUB_SHA=observer["head_sha"],
               PUBLIC_ARTIFACT_ID=str(pub["id"]), PUBLIC_ARTIFACT_DIGEST=pub["digest"])
    if root != ROOT:
        env.update(RANKED_DATA_ROOT=str(root), RANKED_INDEX_SHA=approved[0]["sha256"])
    output = folder / "candidate"
    subprocess.run([sys.executable, ROOT / "tools/prepare_ranked_telegram_routes.py", feed, report_file, output],
                   env=env, check=True)
    return json.loads((output / "route-candidate.json").read_bytes())


def combine(feed, packages, resolver):
    receipts = []
    rows = []
    native_ids = set()
    for package in packages:
        origin = {key: package[key] for key in ("native", "public", "approved_index")}
        native_id = origin["native"]["run_id"]
        require(native_id not in native_ids, "multiple observer receipts for the same native run")
        native_ids.add(native_id)
        receipts.append(origin)
        for row in package["changes"] + package["already_configured"] + package["held"]:
            entry = {key: row[key] for key in ("hotel_id", "source_url", "post_url", "publication_id", "version_id",
                                                "plan_sha256", "html_sha256")}
            entry["native_run_id"] = native_id
            rows.append(entry)
    # Count the entire observed set before excluding any conflicts.
    identities = {key: Counter(row[key] for row in rows) for key in ("hotel_id", "source_url", "post_url")}
    independent, contested = [], []
    for row in rows:
        if all(count[row[key]] == 1 for key, count in identities.items()):
            independent.append(row)
        else:
            contested.append(row)
    candidate, manifest = project(feed, resolver, independent)
    manifest["held"].extend(dict(row, offer_id="tg_" + str(row["hotel_id"]), reason="conflicting_receipts") for row in contested)
    manifest["receipts"] = receipts
    return candidate, manifest


def main(feed, output, run_ids):
    packages = []
    for run in dict.fromkeys(run_ids):
        require(re.fullmatch("[1-9][0-9]{0,19}", run), "invalid public workflow run ID")
        with tempfile.TemporaryDirectory() as folder:
            packages.append(stage_original(run, feed, Path(folder)))
    candidate, manifest = combine(feed, packages, ROOT / RESOLVER)
    output.mkdir()
    (output / RESOLVER).write_bytes(candidate)
    (output / "route-candidate.json").write_text(json.dumps(manifest, indent=2) + "\n", encoding="utf-8")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("feed", type=Path)
    parser.add_argument("output", type=Path)
    parser.add_argument("public_run_ids", nargs="+")
    args = parser.parse_args()
    main(args.feed, args.output, args.public_run_ids)
