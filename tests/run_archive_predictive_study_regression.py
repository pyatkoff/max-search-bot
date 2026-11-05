#!/usr/bin/env python3
"""Synthetic regression; never opens the real archive or an existing private run."""
import copy
import hashlib
import importlib.util
import json
import math
import os
import pathlib
import stat
import subprocess
import sys
import tempfile

ROOT = pathlib.Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location("archive_predictive_study", ROOT / "tools/archive_predictive_study.py")
study = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(study)
T = 1760000000
CHECKS = 0


def check(ok, name):
    global CHECKS
    if not ok:
        raise AssertionError(name)
    CHECKS += 1


def h(value):
    return hashlib.sha256(str(value).encode()).hexdigest()


def atom(case, number, at, goals=(), role="customer", episode=1, group=None, account="A2", **changes):
    result = {"type": "message", "case_key": h("case:" + str(case)),
              "group_key": h("group:" + str(case if group is None else group)), "account_alias": account,
              "ref": "archive_message:" + str(case * 100 + number), "at": at, "version_at": at,
              "record_sha256": h("source:" + str(case) + ":" + str(number)), "role": role, "status": "scanned",
              "episode": episode, "boundary": "first_trip_request_candidate" if number == 1 else None,
              "product": "tour", "goals": list(goals), "flags": {},
              "text_features": {"criteria_families": ["destination", "dates"] if number == 1 else [],
                  **{key: False for key in study.TEXT_BOOL}}}
    result["text_features"]["customer_text_nonempty"] = role == "customer"
    for key, value in changes.items():
        if key == "features":
            result["text_features"].update(value)
        else:
            result[key] = value
    return result


def sample(case=1, payment=True, group=None, account="A2", signal=False):
    anchor = T + case * 1000
    first_goals = ["substantive_interest"] + (["office_question"] if signal else [])
    result = [atom(case, 1, anchor, first_goals, group=group, account=account,
                   features={"near_term_urgency": signal})]
    for number in (2, 3):
        result.append(atom(case, number, anchor + number * 60,
                           ["substantive_interest"] if signal else [], group=group, account=account,
                           features={"explicit_comparison": signal}))
    if payment:
        result += [atom(case, 4, anchor + 3600, ["payment_self_report"], group=group, account=account),
                   atom(case, 5, anchor + 3660, ["manager_payment_ack"], role="manager", group=group, account=account)]
    return result


def manifest(atoms, captured=T + 120 * 86400):
    return {"schema": 1, "kind": study.VERSION, "project_key": "anytour", "run_id": "synthetic_fixture",
            "source_fence": "99999999", "scope_digest": h("scope"), "extractor_sha256": h("extractor"),
            "text_features_sha256": h("text-features"), "rule_revision": 3, "captured_at_unix": captured,
            "source_commit": "a" * 40, "complete": True,
            "chunks": [{"file": "chunk_000001.ndjson", "sha256": h("pure-fixture-chunk"), "records": len(atoms)}],
            "expected": {"desktop_records": len(atoms), "account_chat_pairs": len({a["case_key"] for a in atoms})},
            "coverage": {"source_history_complete": False, "historical_availability": "unknown",
                         "immutable_snapshot": False, "source_counts": {"records": len(atoms), "desktop_import": len(atoms), "business_message": 0}}}


def run(atoms, captured=T + 120 * 86400):
    return study.analyze(manifest(atoms, captured), iter(atoms))


def first(result):
    return next(r for r in result["prefixes"] if r["prefix_kind"] == "first_meaningful")


def rejected(operation, name):
    try:
        operation()
    except study.InvalidStudy:
        check(True, name)
    else:
        check(False, name)


def model_row(group, label, **changes):
    row = {"group_key": h(group), "eligible": True, "label": label, "stage": "concrete_request",
           "prefix_kind": "first_meaningful", "explicit_decline": False,
           "features": {name: 0 for name in study.FEATURES}}
    row.update(changes)
    return row


def protocol_checks():
    base = sample(signal=True)
    result = run(base)
    row = first(result)
    check(row["eligible"] and row["label"] == 1 and row["true_purchase_label"] is None, "future evidence is not verified purchase")
    check(result["summary"]["counts"]["payment_candidate_cases"] == 1, "candidate audit exists")
    check(result["case_audits"][0]["first_payment_created_at_unix"] == base[3]["at"], "first payment uses occurrence")
    check(result["case_audits"][0]["prepayment_signal_lags_seconds"]["near_term_urgency"] == 3600, "prepay lag exact")
    check(any(r["prefix_kind"] == "third_customer_turn_24h" for r in result["prefixes"]), "third text prefix produced")
    check(next(r for r in result["prefixes"] if r["prefix_kind"] == "after_24h")["exclusion_reason"] == "outcome_already_known",
          "24h snapshot cannot predict already known outcome")
    changed = copy.deepcopy(base)
    changed.append(atom(1, 6, base[3]["at"] + 100, ["agency_purchase_intent"],
                        features={"explicit_decline": True, "long_range_planning": True}))
    later = first(run(changed))
    check(later["feature_fingerprint"] == row["feature_fingerprint"] and later["features"] == row["features"],
          "future changed semantics cannot alter earlier features")
    check(all(r["true_purchase_label"] is None for r in result["prefixes"]), "no business negative labels")
    absent = run(sample(payment=False))
    check(first(absent)["label"] == 0 and "not_no_purchase" in first(absent)["label_reason"], "zero only retained evidence target")
    immature = run(sample(payment=False), T + 2 * 86400)
    check(first(immature)["label"] is None and first(immature)["label_reason"] == "horizon_not_mature", "immature remains unknown")
    edited = copy.deepcopy(base)
    edited[3]["version_at"] += 600
    edited_result = run(edited)
    check(first(edited_result)["label"] is None and first(edited_result)["label_reason"] == "edited_payment_source", "edited outcome excluded")
    check(edited_result["case_audits"][0]["first_payment_created_at_unix"] == base[3]["at"]
          and edited_result["case_audits"][0]["first_payment_edited"], "edit never moves first payment clock")
    check(not edited_result["case_audits"][0]["prepayment_lag_claims_eligible"]
          and all(v is None for v in edited_result["case_audits"][0]["prepayment_signal_lags_seconds"].values()),
          "edited first payment does not claim clean historical signal lags")
    reverse_payment = sample(signal=True)
    reverse_payment[4]["at"] = reverse_payment[4]["version_at"] = reverse_payment[3]["at"] - 60
    audit = run(reverse_payment)["case_audits"][0]
    check(audit["first_payment_source_uncertain"] and not audit["prepayment_lag_claims_eligible"]
          and "first_payment_clock_or_source_uncertain" in audit["audit_exclusions"],
          "later native payment backdate retained but audit clock uncertain")
    future_payment = sample(signal=True)
    future_payment[3]["at"] = future_payment[3]["version_at"] = T + 121 * 86400
    future_payment[4]["at"] = future_payment[4]["version_at"] = T + 122 * 86400
    audit = run(future_payment)["case_audits"][0]
    check(audit["first_payment_source_uncertain"] and not audit["prepayment_lag_claims_eligible"],
          "future first-payment clock cannot create clean audit lags")
    edited_prefix = copy.deepcopy(base)
    edited_prefix[0]["version_at"] += 300
    check(first(run(edited_prefix))["exclusion_reason"] == "version_after_cutoff", "edited future version excluded from prefix")
    unknown = copy.deepcopy(base)
    unknown[3]["at"] = unknown[3]["version_at"] = None
    unknown_result = run(unknown)
    check(first(unknown_result)["label"] is None and unknown_result["case_audits"][0]["payment_clock_unknown"], "unknown outcome time never negative")
    reverse = copy.deepcopy(base)
    reverse[1]["at"] = reverse[1]["version_at"] = reverse[0]["at"] - 1
    check(first(run(reverse))["exclusion_reason"] == "reversed_or_equal_source_order", "native reversal independently rejected")
    barrier = sample(payment=False)
    barrier.append(atom(1, 4, barrier[0]["at"] + 3600, role="unknown", status="unknown_role"))
    check(first(run(barrier))["label"] is None, "future source barrier never becomes negative")
    other = copy.deepcopy(base)
    other[3]["product"] = other[4]["product"] = "flight"
    check(first(run(other))["label_reason"] == "other_or_unknown_payment_product", "flight outcome not tour")
    uncertain_trip = copy.deepcopy(base)
    uncertain_trip[0]["flags"]["party_change_unresolved"] = True
    check(first(run(uncertain_trip))["exclusion_reason"] == "trip_binding_unknown", "unresolved trip excluded")
    episode_zero = [atom(7, 1, T, ["manager_payment_ack"], role="manager", episode=0)]
    zero_result = run(episode_zero)
    check(len(zero_result["case_audits"]) == 1 and not zero_result["prefixes"], "episode zero payment audited without fake prefix")
    unresolved = [atom(9, 1, T, ["manager_payment_ack"], role="manager", episode=0),
                  atom(9, 2, T + 60, ["substantive_interest"], features={"criteria_families": ["destination", "dates"]})]
    unresolved[1]["boundary"] = "first_trip_conditions_candidate"
    check(first(run(unresolved))["exclusion_reason"] == "prior_unresolved_outcome",
          "technical episode start cannot turn earlier unresolved payment into control zero")
    explicit_trip = copy.deepcopy(unresolved)
    explicit_trip[1]["episode"] = 2
    explicit_trip[1]["boundary"] = "explicit_new_trip_candidate"
    check(first(run(explicit_trip))["eligible"] and first(run(explicit_trip))["label"] == 0,
          "documented new trip can have its own retained evidence window")
    early = [atom(8, 1, T, ["payment_self_report", "substantive_interest"])]
    check(first(run(early))["exclusion_reason"] == "outcome_already_known", "payment at first meaning not predicted")
    departed = copy.deepcopy(base)
    departed.append(atom(1, 6, base[3]["at"] + 100, ["refund_report"], role="manager"))
    check(first(run(departed))["label"] is None and first(run(departed))["label_reason"] == "contrary_outcome_observed", "refund remains separate uncertainty")
    boundary = sample(payment=False)
    boundary.append(atom(1, 4, boundary[0]["at"] + 30 * 86400, ["payment_self_report"]))
    check(first(run(boundary))["label"] == 0, "horizon end excluded exactly as existing payment followup")
    late = sample(payment=False)
    late.append(atom(1, 4, late[0]["at"] + 31 * 86400, ["payment_self_report"]))
    check(first(run(late))["label"] == 0 and len(run(late)["case_audits"]) == 1, "late report not inside30d target")
    two_trips = sample(payment=False)
    two_trips += [atom(1, 4, T + 100000, ["substantive_interest"], episode=2),
                  atom(1, 5, T + 101000, ["payment_self_report"], episode=2)]
    two_result = run(two_trips)
    check(first(two_result)["label"] == 0 and any(r["episode"] == 2 and r["label"] == 1 for r in two_result["prefixes"]),
          "new episode payment cannot label old episode")
    old_flags = copy.deepcopy(two_trips)
    old_flags[0]["text_features"]["near_term_urgency"] = True
    check("near_term_urgency" not in run(old_flags)["case_audits"][0]["prepayment_signal_lags_seconds"],
          "case audit cannot inherit earlier trip flags")
    duplicate = sample(payment=False)
    duplicate.insert(1, copy.deepcopy(duplicate[0]))
    rejected(lambda: run(duplicate), "any duplicate source rejected before completion")
    masked = sample(payment=False)
    masked[2] = copy.deepcopy(masked[0])
    rejected(lambda: run(masked), "duplicate cannot replace omitted canonical row")
    cross_duplicate = sample(20, payment=False) + sample(21, payment=False)
    cross_duplicate[-1]["ref"] = cross_duplicate[0]["ref"]
    rejected(lambda: run(cross_duplicate), "global archive row duplicate across cases rejected")
    conflict = copy.deepcopy(duplicate)
    conflict[1]["text_features"]["near_term_urgency"] = True
    rejected(lambda: run(conflict), "conflicting duplicate closed")
    a, b = sample(10), sample(11)
    rejected(lambda: run(a + b + [a[0]]), "case reopened after another case closed")
    before = sample(payment=False)
    invalid = copy.deepcopy(before)
    invalid[0]["text"] = "RAW_TEXT_FORBIDDEN"
    rejected(lambda: run(invalid), "raw text atom forbidden")
    invalid = copy.deepcopy(before)
    invalid[0]["at"] = True
    rejected(lambda: run(invalid), "boolean not a clock")
    invalid = copy.deepcopy(base)
    invalid[3]["role"] = "manager"
    rejected(lambda: run(invalid), "payment source actor independently checked")
    m = manifest(before)
    m["complete"] = False
    rejected(lambda: study.analyze(m, before), "incomplete corpus cannot complete")
    m = manifest(before)
    m["chain_sha256"] = h("chain")
    m["coverage"]["source_counts"]["business_message"] = 1
    m["coverage"]["source_counts"]["desktop_import"] -= 1
    m["expected"]["desktop_records"] -= 1
    check(study.analyze(m, before)["summary"]["counts"]["source_records"] == len(before), "all source kinds counted independently of desktop total")
    m = manifest(before)
    m["source_fence"] = "1"
    rejected(lambda: study.analyze(m, before), "future row fence rejected")
    m = manifest(before)
    m["expected"]["account_chat_pairs"] = 2
    rejected(lambda: study.analyze(m, before), "corpus denominator verified")
    rejected(lambda: study.loads('{"x":1,"x":2}'), "duplicate JSON keys rejected")
    rejected(lambda: study.loads('{"x":NaN}'), "nonfinite JSON rejected")


def math_checks():
    rows = [model_row("one", 1) for _ in range(3)] + [model_row("two", 0)]
    weighted = study.normalized_rows(rows)
    sums = {}
    for r, w in weighted:
        sums[r["group_key"]] = sums.get(r["group_key"], 0) + w
    check(all(abs(w - 1) < 1e-12 for w in sums.values()), "each peer equal total weight")
    check(len(study.normalized_rows([model_row("x", None)])) == 0, "unknown never enters fitting")
    for qualifier in ("question", "conditional", "quoted_or_relayed", "past_trip"):
        state = study.new_feature_state()
        study.advance_features(state, atom(99, 1, T, ["substantive_interest"]))
        study.advance_features(state, atom(99, 2, T + 60, flags={qualifier: True},
                                          features={"explicit_decline": True}))
        check(not study.feature_result(state)[2], "decline qualifier does not become refusal: " + qualifier)
    for weak in ("office_question", "payment_terms_question"):
        state = study.new_feature_state()
        study.advance_features(state, atom(98, 2, T, [weak]))
        check(not state["current"][weak], "weak logistics before anchor ignored: " + weak)
        for qualifier in ("conditional", "quoted_or_relayed", "past_trip"):
            state = study.new_feature_state()
            study.advance_features(state, atom(98, 1, T, ["substantive_interest"]))
            study.advance_features(state, atom(98, 2, T + 60, [weak], flags={qualifier: True}))
            check(not state["current"][weak], "weak logistics qualifier ignored: " + weak + qualifier)
        state = study.new_feature_state()
        study.advance_features(state, atom(98, 1, T, ["substantive_interest"]))
        study.advance_features(state, atom(98, 2, T + 60, [weak], flags={"question": True, "negation": True}))
        check(state["current"][weak], "anchored logistics question remains useful: " + weak)
    state = study.new_feature_state()
    study.advance_features(state, atom(99, 1, T, ["substantive_interest"]))
    study.advance_features(state, atom(99, 2, T + 60, flags={"negation": True},
                                      features={"explicit_decline": True}))
    check(study.feature_result(state)[2], "direct negative refusal retains fixed decline guard")
    common = [0.0] * len(study.baseline_vector(rows[0]))
    declined = model_row("guard", 1, explicit_decline=True)
    declined["features"]["deferral"] = 1
    score = study.scores(declined, common, {f: 0.5 for f in study.FEATURES})
    check(abs(score["stage"] - 0.2) < 1e-12 and abs(score["old"] - 0.2) < 1e-12
          and abs(score["candidate"] - 0.2) < 1e-12, "decline once and exact old global floor")
    all_active = model_row("caps", 1)
    all_active["features"] = {f: 1 for f in study.FEATURES}
    score = study.scores(all_active, common, {f: 1.5 for f in study.FEATURES})
    check(abs(score["candidate"] - (1.3 / 2.3)) < 1e-12, "aggregate odds cap applied before evaluation")
    rare = []
    for i in range(30):
        r = model_row("rare" + str(i), int(i % 2 == 0))
        r["features"]["office_question"] = int(i < 10)
        rare.append((r, 1.0))
    weights, support = study.learn_relative(rare, common)
    check(weights["office_question"] == 1 and support["office_question"]["neutral"], "rare feature exact neutral")
    check(all(0.5 <= v <= 1.5 for v in weights.values()), "individual odds caps")
    simple = [(model_row("g1", 1), 1.0), (model_row("g2", 0), 1.0)]
    neutral = {f: 1.0 for f in study.FEATURES}
    report = study.evaluate(simple, common, neutral)
    cloned = study.normalized_rows([simple[0][0], copy.deepcopy(simple[0][0]), simple[1][0]])
    clone_report = study.evaluate(cloned, common, neutral)
    check(report["metrics"] == clone_report["metrics"] and report["paired_group_bootstrap"] == clone_report["paired_group_bootstrap"],
          "group bootstrap and metrics invariant to identical prefix multiplication")
    check(report["within_stage_prefix_ranking"][0]["auc"]["stage"] == 0.5, "ranking ties receive half credit")
    check(study.evaluate([(model_row("positive_only", 1), 1.0)], common, neutral)
          ["within_stage_prefix_ranking"][0]["auc"]["stage"] is None, "single class ranking unknown")
    check(study.evaluate([], common, neutral) is None, "empty evaluation unavailable")
    many = []
    need = {"development": 80, "control": 40}
    used = {"development": 0, "control": 0}
    case = 100
    while any(used[a] < need[a] for a in need):
        case += 1
        group = h("group:" + str(case))
        arm = study.assignment(group)
        if used[arm] >= need[arm]:
            continue
        positive = used[arm] % 2 == 0
        many += sample(case, payment=positive, signal=positive)
        used[arm] += 1
    result = run(many, T + 365 * 86400)
    check(result["summary"]["fit"]["fitted"] and result["summary"]["fit"]["support_gates_passed"],
          "all eligible controls and group support fit")
    check(result["summary"]["evaluation"] is not None and result["candidate_weights"]["complete"],
          "three fixed arms evaluated")
    check(result["summary"]["fit"]["control_tuned"] is False and result["summary"]["fit"]["control_is_pristine_research_holdout"] is False,
          "no pristine or control tuning claim")
    check(set(result["candidate_weights"]["multipliers"]) == set(study.FEATURES)
          and all(0.5 <= v <= 1.5 for v in result["candidate_weights"]["multipliers"].values()),
          "candidate exact seven bounded weights")
    control_flip = copy.deepcopy(many)
    for a in control_flip:
        if study.assignment(a["group_key"]) == "control":
            a["text_features"]["near_term_urgency"] = not a["text_features"]["near_term_urgency"]
    flipped = run(control_flip, T + 365 * 86400)
    check(result["candidate_weights"]["multipliers"] == flipped["candidate_weights"]["multipliers"],
          "control semantics cannot tune fitted coefficients")
    check(result["candidate_weights"]["stage_priors_unchanged"] and result["summary"]["commission_unchanged"],
          "purchase priors and commission remain unchanged")
    check(result["summary"]["relative_weights"]["multipliers"] == result["candidate_weights"]["multipliers"]
          and result["summary"]["relative_weights"]["weights_eligible"] == result["candidate_weights"]["weights_eligible"],
          "private summary returns exact proposed seven weights")
    check(result["summary"]["relative_weights"]["old_multipliers"] == dict(zip(study.FEATURES, (1.1, 1.2, 0.65, 0.5, 1.0, 1.0, 1.0))),
          "private summary preserves all seven old multiplier meanings")
    check(result["summary"]["payment_candidate_audit"]["cases"] == result["summary"]["counts"]["payment_candidate_cases"],
          "private summary covers every payment candidate audit")
    audit_data = sample(31, signal=True)
    edited_audit = sample(32)
    edited_audit[3]["version_at"] += 60
    unknown_audit = sample(34)
    unknown_audit[3]["at"] = unknown_audit[3]["version_at"] = None
    other_audit = sample(35)
    other_audit[3]["product"] = other_audit[4]["product"] = "flight"
    audit_data += edited_audit + [atom(33, 1, T, ["manager_payment_ack"], role="manager", episode=0)] + unknown_audit + other_audit
    audit_result = run(audit_data)
    aggregate = audit_result["summary"]["payment_candidate_audit"]
    check(aggregate["cases"] == 5 and aggregate["lag_claims_eligible_cases"] == 1
          and aggregate["lag_claims_excluded_cases"] == 4 and aggregate["unknown_payment_clock_cases"] == 1
          and aggregate["edited_first_payment_cases"] == 1, "candidate timing coverage counts every clean and excluded case")
    check(aggregate["prepayment_signals"]["near_term_urgency"]["timing_eligible_cases"] == 1
          and aggregate["prepayment_signals"]["near_term_urgency"]["median_lag_seconds"] == 3600
          and aggregate["audit_exclusions_by_reason"]["trip_binding_unknown"] == 1,
          "prepayment aggregate uses only admitted same-episode timing")
    serialized_summary = study.encoded(audit_result["summary"])
    check(b"archive_message:" not in serialized_summary and b"case_key" not in serialized_summary
          and all(a["group_key"].encode() not in serialized_summary for a in audit_result["case_audits"]),
          "summary contains no customer identifiers or source refs")
    check(len(study.encoded(result["summary"])) <= 11800, "private summary bound")
    empty = run([])
    check(not empty["candidate_weights"]["weights_eligible"] and empty["summary"]["complete"], "empty complete corpus honest ineligible")


def cli_checks():
    with tempfile.TemporaryDirectory(prefix="archive-predictive-synthetic-") as parent:
        run_dir = pathlib.Path(parent) / "run"
        run_dir.mkdir(mode=0o700)
        atoms = sample(payment=False)
        raw = b"".join(study.encoded(a) + b"\n" for a in atoms)
        (run_dir / "chunk_000001.ndjson").write_bytes(raw)
        m = manifest(atoms)
        m["chunks"][0]["sha256"] = hashlib.sha256(raw).hexdigest()
        m["analyzer_sha256"] = hashlib.sha256((ROOT / "tools/archive_predictive_study.py").read_bytes()).hexdigest()
        manifest_raw = study.encoded(m) + b"\n"
        (run_dir / "manifest.json").write_bytes(manifest_raw)
        before = {p.name: hashlib.sha256(p.read_bytes()).hexdigest() for p in run_dir.iterdir()}
        command = [sys.executable, str(ROOT / "tools/archive_predictive_study.py"), "--run-dir", str(run_dir)]
        completed = subprocess.run(command, capture_output=True, timeout=30)
        check(completed.returncode == 0 and not completed.stderr, "finite CLI completes synthetic run")
        stdout = json.loads(completed.stdout)
        check(set(stdout) == {"ok", "completed", "private_outputs_written", "purchase_probability_calibrated", "live_files_changed"}
              and all(type(v) is bool for v in stdout.values()) and stdout["ok"], "stdout only technical booleans")
        analysis = run_dir / "analysis"
        check(set(p.name for p in analysis.iterdir()) == {"summary.json", "candidate_weights.json", "case_audits.jsonl", "prefixes.jsonl"},
              "exact private outputs")
        check((analysis.stat().st_mode & 0o777) == 0o700
              and all((p.stat().st_mode & 0o777) == 0o600 for p in analysis.iterdir()), "private output permissions")
        actual = json.loads((analysis / "candidate_weights.json").read_bytes())
        check(actual["manifest_sha256"] == hashlib.sha256(manifest_raw).hexdigest(), "exact manifest bytes bind candidate")
        after = {p.name: hashlib.sha256(p.read_bytes()).hexdigest() for p in run_dir.iterdir() if p.is_file()}
        check(before == after, "source input hashes unchanged")
        second = subprocess.run(command, capture_output=True, timeout=30)
        check(second.returncode == 2 and not json.loads(second.stdout)["ok"]
              and second.stderr == b"archive_predictive_input_invalid\n", "no overwrite or silent rerun")
        bad = pathlib.Path(parent) / "bad"
        bad.mkdir()
        (bad / "chunk_000001.ndjson").write_bytes(raw + b"{}\n")
        (bad / "manifest.json").write_bytes(manifest_raw)
        rejected_run = subprocess.run([*command[:-1], str(bad)], capture_output=True, timeout=30)
        check(rejected_run.returncode == 2 and not (bad / "analysis").exists()
              and b"archive_message" not in rejected_run.stdout + rejected_run.stderr, "hash failure before any private output")
        link = pathlib.Path(parent) / "linked"
        link.mkdir()
        (link / "manifest.json").symlink_to(run_dir / "manifest.json")
        linked = subprocess.run([*command[:-1], str(link)], capture_output=True, timeout=30)
        check(linked.returncode == 2 and not (link / "analysis").exists(), "symlink input rejected")
        durable = pathlib.Path(parent) / "durable"
        durable.mkdir(mode=0o700)
        sync_events = []
        original_fsync = study.os.fsync
        def observe_sync(fd):
            sync_events.append("directory" if stat.S_ISDIR(os.fstat(fd).st_mode) else "file")
            return original_fsync(fd)
        try:
            study.os.fsync = observe_sync
            study.write_outputs(str(durable), run([]))
        finally:
            study.os.fsync = original_fsync
        check(sync_events == ["directory", "file", "file", "file", "file", "directory", "directory"],
              "mkdir parent then all files then analysis directory then parent durable before completion")
        unsafe = copy.deepcopy(m)
        unsafe["chunks"][0]["file"] = "../escape.ndjson"
        rejected(lambda: study.validate_manifest(unsafe), "parent escape path rejected")


if __name__ == "__main__":
    protocol_checks()
    math_checks()
    cli_checks()
    print(json.dumps({"ok": True, "synthetic_regressions_passed": True}, separators=(",", ":")))
