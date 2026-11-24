"""Read advertising destinations listed in the accepted route manifest after installation."""
import argparse
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timezone
import hashlib
import importlib.util
import json
from pathlib import Path
import re
import time

SHA = r"[0-9a-f]{64}"
OBSERVER_SHA = "db675f078929307153fc2f135fcb40717e78354ea894a242545c71e1f36a93ac"
DEFAULT_OBSERVER = Path("/tmp/routeops-real-input/source/tools/verify_installed_telegram_routes.py")
TRANSIENT = {"TimeoutError", "ConnectionResetError", "ConnectionAbortedError", "BrokenPipeError",
             "RemoteDisconnected", "IncompleteRead"}
HEADERS = {"Cache-Control": "cache_control", "Age": "age", "Date": "date"}


def utc():
    return datetime.now(timezone.utc).isoformat(timespec="milliseconds")


def require(ok, msg):
    if not ok:
        raise ValueError(msg)


def load_observer(path):
    source = path.read_bytes()
    require(hashlib.sha256(source).hexdigest() == OBSERVER_SHA, "wrong reviewed observer")
    spec = importlib.util.spec_from_file_location("installed_hotel_ad_observer", path)
    observer = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(observer)
    return observer


def read_manifest(path, expected_digest, installed_resolver):
    raw = path.read_bytes()
    require(len(raw) <= 4_000_000 and hashlib.sha256(raw).hexdigest() == expected_digest, "manifest digest mismatch")
    candidate = json.loads(raw)
    resolver = candidate["resolver"]["candidate_sha256"]
    require(re.fullmatch(SHA, resolver) and (not installed_resolver or resolver == installed_resolver),
            "installed resolver differs from candidate")
    changes = candidate["changes"]
    require(isinstance(changes, list) and len(changes) <= 2000, "invalid change list")
    seen_offer, seen_post = set(), set()
    rows = []
    for item in changes:
        offer, destination = item["offer_id"], item["post_url"]
        require(isinstance(offer, str) and re.fullmatch(r"tg_[1-9][0-9]{0,11}", offer)
                and str(item["hotel_id"]) == offer[3:]
                and isinstance(destination, str) and re.fullmatch(r"https://t\.me/anytour/[1-9][0-9]{0,11}", destination)
                and offer not in seen_offer and destination not in seen_post, "invalid or repeated changed destination")
        seen_offer.add(offer)
        seen_post.add(destination)
        rows.append({"offer_id": offer, "post_url": destination})
    return rows, resolver


def observe(row, get_ad, pause=time.sleep):
    attempts = []
    for attempt in range(2):
        response = get_ad(row)
        record = dict(response, observed_at=utc(), attempt=attempt + 1)
        record["headers"] = {header: record.pop(key) for header, key in HEADERS.items() if key in record}
        attempts.append(record)
        if record["ok"] or record.get("reason") not in TRANSIENT or attempt:
            break
        pause(0.5)
    return {"offer_id": row["offer_id"], "expected_post_url": row["post_url"],
            "ok": attempts[-1]["ok"], "attempts": attempts}


def observe_rows(rows, get_ad):
    with ThreadPoolExecutor(max_workers=5) as pool:
        return list(pool.map(lambda row: observe(row, get_ad), rows))


def main():
    cli = argparse.ArgumentParser(description=__doc__)
    cli.add_argument("manifest", type=Path)
    cli.add_argument("manifest_sha256")
    cli.add_argument("output", type=Path)
    cli.add_argument("--observer", type=Path, default=DEFAULT_OBSERVER)
    cli.add_argument("--installed-resolver-sha256")
    cli.add_argument("--deploy-run", required=True)
    cli.add_argument("--installed-sha", required=True)
    args = cli.parse_args()
    require(re.fullmatch(r"[1-9][0-9]{0,19}", args.deploy_run)
            and re.fullmatch(r"[0-9a-f]{40}", args.installed_sha), "invalid install identifiers")
    require(not args.output.exists(), "output already exists")
    rows, resolver = read_manifest(args.manifest, args.manifest_sha256, args.installed_resolver_sha256)
    observer = load_observer(args.observer)
    start = utc()
    observations = observe_rows(rows, observer.get_ad)
    count = sum(item["ok"] for item in observations)
    report = {"started_at": start, "finished_at": utc(), "deploy_run_id": args.deploy_run,
              "installed_sha": args.installed_sha, "manifest_sha256": args.manifest_sha256,
              "candidate_resolver_sha256": resolver, "observer_sha256": OBSERVER_SHA,
              "selected_changes": len(rows), "verified_count": count, "observed": observations}
    with args.output.open("x", encoding="utf-8") as f:
        json.dump(report, f, ensure_ascii=False, indent=2)
        f.write("\n")
    print(f"{count}/{len(rows)} changed advertising destinations verified")
    return 0 if count == len(rows) else 1


if __name__ == "__main__":
    raise SystemExit(main())
