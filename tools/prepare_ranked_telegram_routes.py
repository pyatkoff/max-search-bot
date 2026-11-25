"""Prepare an isolated route candidate from this observer's authenticated inputs."""

import argparse
from collections import Counter
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import zipfile


ROOT = Path(__file__).resolve().parents[1]
RESOLVER = "hotel-posts-telegram-main19.php"
CATALOG = """
require $argv[1];
$xml = file_get_contents($argv[2]);
$source = anytourTelegramFeedOfferUrls($xml);
echo json_encode([$source, anytourTelegramFeedDestinationPosts($source),
    hash('sha256', anytourTelegramFeedProject($xml)), hash('sha256', $xml)], JSON_THROW_ON_ERROR);
"""
INSERT = b"    ];\n    $landing = $source;\n"
PAIR = rb"        '(tg_[1-9][0-9]*)' => \['(https://t\.me/any_toour/[1-9][0-9]*)', '(https://t\.me/anytour/[1-9][0-9]*)'\],"


def require(test, reason):
    if not test:
        raise ValueError(reason)


def digest(data):
    return hashlib.sha256(data).hexdigest()


def php(*args):
    return json.loads(subprocess.check_output(["php", *args]))


def verify_inputs():
    index_path = os.environ["RANKED_APPROVED_INDEX"]
    registry = json.loads((ROOT / "config/anytour_ranked_approved_indexes.json").read_bytes())
    approved = [entry for entry in registry if entry["path"] == index_path]
    require(len(approved) == 1, "index outside the route owner's observer registry")
    index = (ROOT / index_path).read_bytes()
    require(digest(index) == approved[0]["sha256"], "approved index changed")
    branch = os.environ["NATIVE_BRANCH"]
    if os.environ["WRITE_OPERATION"] == "hotel_ranked_approved_input":
        require(branch == "ops/anytour-hotel-ranked-plan-" + digest(index), "native branch/index mismatch")
    else:
        historical = {
            "hotel_ranked_46_transfer": "ops/anytour-hotel-ranked-46-apply-20261123",
            "hotel_ranked_37_continuation": "ops/anytour-hotel-ranked-37-continue-20261123",
            "hotel_ranked_resume": "ops/anytour-hotel-ranked-resume-20261124",
            "hotel_ranked_unreached": "ops/anytour-hotel-ranked-after-" + os.environ.get("NATIVE_PARENT_RUN_ID", ""),
        }
        require(index_path == "config/anytour_ranked_46_20261123.json" and
                branch == historical.get(os.environ["WRITE_OPERATION"]), "historical scope mismatch")
    expected = {"summary.json"}
    for item in json.loads(index)["plans"]:
        expected.update(f"{kind}-{item['sha256']}.json" for kind in ("check", "result"))
    work = Path(os.environ["RUNNER_TEMP"])
    archive = work / "native.zip"
    require(0 < archive.stat().st_size <= 2_000_000 and digest(archive.read_bytes()) == os.environ["RECEIPT_SHA256"],
            "native archive digest mismatch")
    with zipfile.ZipFile(archive) as records:
        require(sorted(records.namelist()) == sorted(expected), "native archive inventory mismatch")
        for member in records.infolist():
            require(0 < member.file_size <= 1_000_000 and
                    records.read(member) == (work / "native" / member.filename).read_bytes(), "native entry changed")
    return approved[0]


def table_owned(source):
    blocks = re.findall(rb"    \$tableRoutes = \[\n(.*?)    \];", source, re.DOTALL)
    require(len(blocks) == 1, "unknown shared route table")
    rows = [re.fullmatch(PAIR, line) for line in blocks[0].splitlines()]
    require(rows and all(rows), "unrecognized shared route declaration")
    return {row[1].decode() for row in rows}, {row[3].decode() for row in rows}


def project(feed, resolver, rows):
    original = resolver.read_bytes()
    with tempfile.TemporaryDirectory() as folder:
        base = Path(folder, RESOLVER)
        base.write_bytes(original)
        raw, current, projection, snapshot = php("-r", CATALOG, "--", base, feed)
    owned_ids, reserved_urls = table_owned(original)
    offers = set()
    targets = Counter(row["post_url"] for row in rows)
    sources = Counter(row["source_url"] for row in rows)
    changes, already, held = [], [], []
    expected = current.copy()
    for row in rows:
        offer = "tg_" + str(row["hotel_id"])
        source, target = row["source_url"], row["post_url"]
        require(re.fullmatch("tg_[1-9][0-9]{0,11}", offer)
                and re.fullmatch(r"https://t\.me/any_toour/[1-9][0-9]*", source)
                and re.fullmatch(r"https://t\.me/anytour/[1-9][0-9]*", target), "invalid approved route")
        require(offer not in offers, "duplicate accepted offer")
        offers.add(offer)
        entry = {"offer_id": offer, **row}
        if offer in owned_ids or target in reserved_urls:
            reason = "shared_route"
        elif sources[source] != 1:
            reason = "source_reused_in_accepted_set"
        elif targets[target] != 1 or any(url == target and other != offer for other, url in current.items()):
            reason = "destination_already_assigned"
        elif raw.get(offer) not in (source, target) or current.get(offer) not in (source, target):
            reason = "source_or_current_destination_changed"
        elif current[offer] == target:
            already.append(entry)
            continue
        else:
            changes.append(entry)
            expected[offer] = target
            continue
        held.append({**entry, "reason": reason})

    require(original.count(INSERT) == 1, "unknown route resolver shape")
    additions = "".join(f"        '{r['offer_id']}' => ['{r['source_url']}', '{r['post_url']}'],\n" for r in changes)
    candidate = original.replace(INSERT, additions.encode("ascii") + INSERT)
    with tempfile.TemporaryDirectory() as folder:
        path = Path(folder, RESOLVER)
        path.write_bytes(candidate)
        after_raw, after, after_projection, after_snapshot = php("-r", CATALOG, "--", path, feed)
    require(after_snapshot == snapshot and list(after_raw.items()) == list(raw.items()) and after_projection == projection
            and list(after.items()) == list(expected.items()), "unrelated feed or route changed")
    return candidate, {
        "changes": changes, "already_configured": already, "held": held,
        "feed": {"offers": len(raw), "unchanged": len(raw) - len(changes),
                 "source_sha256": snapshot, "projection_sha256": projection},
        "resolver": {"base_sha256": digest(original), "candidate_sha256": digest(candidate)},
    }


def main(feed, report, output):
    approved = verify_inputs()
    bound = php(ROOT / "tools/verify_ranked_telegram_public.php", "--route-input", report)
    public = {"run_id": os.environ["GITHUB_RUN_ID"], "repository_sha": os.environ["GITHUB_SHA"],
              "report_sha256": digest(report.read_bytes()), "artifact_id": os.environ["PUBLIC_ARTIFACT_ID"],
              "artifact_sha256": os.environ["PUBLIC_ARTIFACT_DIGEST"].removeprefix("sha256:")}
    require(re.fullmatch("[1-9][0-9]{0,19}", public["run_id"]) and
            re.fullmatch("[a-f0-9]{40}", public["repository_sha"]) and re.fullmatch("[1-9][0-9]{0,19}", public["artifact_id"])
            and re.fullmatch("[a-f0-9]{64}", public["artifact_sha256"]), "public workflow identity invalid")
    candidate, manifest = project(feed, ROOT / RESOLVER, bound["accepted"])
    manifest.update(native=bound["native"], public=public, approved_index=approved)
    output.mkdir()
    (output / RESOLVER).write_bytes(candidate)
    (output / "route-candidate.json").write_text(json.dumps(manifest, indent=2) + "\n", encoding="utf-8")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    for name in ("feed", "report", "output"):
        parser.add_argument(name, type=Path)
    args = parser.parse_args()
    main(args.feed, args.report, args.output)
