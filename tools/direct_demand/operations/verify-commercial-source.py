"""Offline publication source acceptance; no HTTP, DB or provider calls."""
import argparse, copy, json, re
from pathlib import Path

# Reviewed against main 160e019701165cd53dd5a9be0fa3db533d82120e.
# PR #1339 adds an opt-in public URL check, opt-in transaction joining and a
# separate webhook method; existing AnyTour publication calls retain behavior.
# ProjectConfig.get delegates its identical traversal to the newly pinned class.
PINS = {
  "integrations/ChannelPostApiTransport.php": "300634d77cb9fddf51adf9869974f956cca6e3f9",
  "migrations/033_channel_post_context.sql": "0530458030a4bfe6b6cc55d1e5e441745c29b947",
  "migrations/036_channel_post_member_delivery.sql": "1bda463e52b33130ad12752ddef4ee5c92699c05",
  "migrations/040_channel_post_reconciliation.sql": "0f24b838eacd45333c5ade9a2991d060488c6035",
  "services/ChannelPostAdminService.php": "b80eefc801054bfe6fdb58ec345ecdf687b2e89a",
  "services/ChannelPostAutomationService.php": "f5975ab95de5f45e087eb7a0f768f566c5bca648",
  "services/ChannelPostButtonTransport.php": "7b6711f1de63000346d8e5789f2dbb7f725535b8",
  "services/ChannelPostEntryService.php": "f0e04e9b4e0ed6859ced96279cbf0619931596e6",
  "services/ChannelPostMaxAlbumRecovery.php": "de266cd068587cc3b2ff83f308a966ea66887a04",
  "services/ChannelPostMediaService.php": "f794efd0f6f24df916e18909ff6281b0bc928433",
  "services/ChannelPostMemberDeliveryService.php": "20c1d7984898b2a5996a5f9e20e4d2d8c20b4778",
  "services/ChannelPostReconciliationService.php": "2b559b067a91abb9402a9dc2f3ce75d1ddedc7c3",
  "services/ChannelPostReconciliationState.php": "d9583ceca80d1424a44ade3561c21282a23a52f0",
  "services/ChannelPostRegistryService.php": "8335bf2fb0c1cd78f268d4e90fad1a4465fa6390",
  "services/ChannelPostRuntime.php": "2f1dd9b77474fdbaa92f0402f3b0aa170c595474",
  "services/ChannelPostTelegramAlbum.php": "faa1a14bc279a4af33936282126bf0d46e343040",
  "services/ChannelPostWebhookConfigService.php": "d5fdcb056088f1e3a541b4046325d97eef6417d9",
  "services/ConversationDb.php": "bb6281faf55aca7beeae7f8a57389d19751936f7",
  "services/MaxTlsConfig.php": "032c0211ee3f39c6f0d27c669abd5b467e321dea",
  "services/MaxTransport.php": "2cb4f8786b4704a8e62a3a9c40dc2cc73dad6159",
  "services/ProjectConfig.php": "63c17c8103412f8cff8cdcc4bbb062e744106af9",
  "services/ProjectConfiguration.php": "07019d473c588e3386ace42977db93092e8112e3"
}
DEPLOY_PATH = ".github/workflows/deploy.yml"
TLS_PATH = ".github/workflows/max-tls-preflight.yml"

def require(ok, reason):
    if not ok:
        raise ValueError(reason)

def verify(e, expected):
    require(bool(re.fullmatch(r"[a-f0-9]{40}", expected)), "invalid_approved_source")
    require(e["main"]["object"]["sha"] == expected, "approved_source_not_current")
    run = e["verify_run"]
    require(run["head_sha"] == expected and run["path"] == DEPLOY_PATH, "verify_source_or_owner_mismatch")
    require(run["status"] == "completed", "deployment_still_active")
    jobs = [j for j in e["verify_jobs"]["jobs"] if j["name"] == "verify"]
    require(len(jobs) == 1, "verify_job_not_unique")
    j = jobs[0]
    require(j["run_id"] == run["id"] and j["head_sha"] == expected, "verify_job_source_mismatch")
    require(j["status"] == "completed" and j["conclusion"] == "success", "source_verify_not_successful")
    tls = e["tls_run"]
    require(tls["head_sha"] == expected and tls["path"] == TLS_PATH, "tls_source_or_owner_mismatch")
    require(tls["status"] == "completed" and tls["conclusion"] == "success", "strict_tls_not_successful")
    commit, tree = e["source_commit"], e["source_tree"]
    require(commit["sha"] == expected and commit["tree"]["sha"] == tree["sha"], "source_tree_mismatch")
    require(tree.get("truncated") is False, "source_tree_incomplete")
    entries = tree["tree"]
    require(len({x["path"] for x in entries}) == len(entries), "duplicate_tree_path")
    blobs = {x["path"]: x for x in entries}
    require(len(PINS) == 22, "accepted_surface_count")
    for path, sha in PINS.items():
        row = blobs.get(path, {})
        require(row.get("type") == "blob" and row.get("sha") == sha, "publishing_surface_changed:" + path)
    # Aggregate deploy health remains visible. Its unrelated terminal job result
    # does not replace source verification, strict TLS or the exact publisher.
    # Live installed-source/host/schema/rights/once checks stay in the PHP route.
    return {"ok": True, "publishing_blob_count": len(PINS),
            "source_verify": "success", "strict_tls": "success",
            "aggregate_deploy_conclusion": run.get("conclusion")}

def self_test():
    sha, tree_sha = "a"*40, "b"*40
    good = {
        "main": {"object": {"sha": sha}},
        "verify_run": {"id": 10, "head_sha": sha, "path": DEPLOY_PATH, "status": "completed", "conclusion": "success"},
        "verify_jobs": {"jobs": [{"name": "verify", "run_id": 10, "head_sha": sha, "status": "completed", "conclusion": "success"}]},
        "tls_run": {"head_sha": sha, "path": TLS_PATH, "status": "completed", "conclusion": "success"},
        "source_commit": {"sha": sha, "tree": {"sha": tree_sha}},
        "source_tree": {"sha": tree_sha, "truncated": False,
                        "tree": [{"path": p, "type": "blob", "sha": s} for p,s in PINS.items()]},
    }
    assert verify(good, sha)["ok"]
    unrelated = copy.deepcopy(good)
    unrelated["verify_run"]["conclusion"] = "failure"
    assert verify(unrelated, sha)["ok"]
    cases = [
        ("stale_main", lambda e: e["main"]["object"].update(sha="c"*40)),
        ("wrong_verify_source", lambda e: e["verify_run"].update(head_sha="c"*40)),
        ("active_deployment", lambda e: e["verify_run"].update(status="in_progress")),
        ("failed_verify", lambda e: e["verify_jobs"]["jobs"][0].update(conclusion="failure")),
        ("wrong_verify_job_source", lambda e: e["verify_jobs"]["jobs"][0].update(head_sha="c"*40)),
        ("failed_tls", lambda e: e["tls_run"].update(conclusion="failure")),
        ("wrong_tls_source", lambda e: e["tls_run"].update(head_sha="c"*40)),
        ("wrong_tls_owner", lambda e: e["tls_run"].update(path=DEPLOY_PATH)),
        ("wrong_commit_tree", lambda e: e["source_commit"]["tree"].update(sha="c"*40)),
        ("truncated_tree", lambda e: e["source_tree"].update(truncated=True)),
        ("missing_publisher", lambda e: e["source_tree"]["tree"].pop()),
        ("changed_publisher", lambda e: e["source_tree"]["tree"][0].update(sha="c"*40)),
    ]
    for name, mutate in cases:
        changed = copy.deepcopy(good)
        mutate(changed)
        try:
            verify(changed, sha)
        except (ValueError, KeyError):
            continue
        raise AssertionError("unsafe admission:" + name)
    print(json.dumps({"ok": True, "controlled_cases": 2+len(cases),
                      "http_calls": 0, "db_writes": 0, "message_sends": 0}))

if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--self-test", action="store_true")
    parser.add_argument("--expected-source")
    parser.add_argument("--evidence-dir")
    args = parser.parse_args()
    if args.self_test:
        self_test()
    else:
        require(args.expected_source is not None and args.evidence_dir is not None, "missing_input")
        root = Path(args.evidence_dir)
        evidence = {name: json.loads((root/(name+".json")).read_text()) for name in
                    ("main", "verify_run", "verify_jobs", "tls_run", "source_commit", "source_tree")}
        print(json.dumps(verify(evidence, args.expected_source), sort_keys=True))

