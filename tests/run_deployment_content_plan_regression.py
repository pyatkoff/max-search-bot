#!/usr/bin/env python3
"""Isolated real-Git checks; no network, production, credentials or model calls."""
from __future__ import annotations
import copy
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import textwrap
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location("deployment_content_plan", ROOT / "tools/deployment_content_plan.py")
assert SPEC and SPEC.loader
module = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(module)
LEAF = "tests/run_purchase_study_runtime.py"


class ContentPlanTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory(prefix="anytour-content-plan-")
        cls.repo = Path(cls.temp.name) / "repo"
        cls.repo.mkdir()
        cls.git("init", "-q")
        cls.git("config", "user.name", "Synthetic regression")
        cls.git("config", "user.email", "regression@example.invalid")
        cls.base_files = {"app.php": "<?php echo 'runtime-one';\n", LEAF: "# fixture v1\n",
                          "export_debug_logs.php": "<?php // runtime diagnostics\n",
                          "tests/run_conversation_catalog.php": "<?php // live suite\n",
                          "tests/run_conversation_regression.php": "<?php // live suite\n",
                          "tests/fixtures/tourvisor_routes.json": "{}\n",
                          "docs/runtime-instructions.md": "runtime instructions\n"}
        for name, data in cls.base_files.items():
            file = cls.repo / name
            file.parent.mkdir(parents=True, exist_ok=True)
            file.write_text(data)
        cls.git("add", ".")
        cls.git("commit", "-qm", "synthetic installed source")
        cls.base = cls.git("rev-parse", "HEAD").strip()
        cls.leaf = cls.commit({LEAF: "# fixture v2\n"})
        cls.runtime = cls.commit({"app.php": "<?php echo 'runtime-two';\n"})
        cls.runtime_then_test = cls.commit({LEAF: "# fixture v2\n"}, parent=cls.runtime)
        cls.consecutive_test = cls.commit({LEAF: "# fixture v3\n"}, parent=cls.leaf)
        cls.live_test = cls.commit({"tests/run_conversation_catalog.php": "<?php // changed\n"})
        cls.live_regression = cls.commit({"tests/run_conversation_regression.php": "<?php // changed\n"})
        cls.fixture = cls.commit({"tests/fixtures/tourvisor_routes.json": '{"changed":true}\n'})
        cls.docs = cls.commit({"docs/runtime-instructions.md": "changed instructions\n"})
        cls.workflow = cls.commit({".github/workflows/deploy.yml": "name: changed\n"})
        cls.unknown = cls.commit({"new-runtime.json": "{}\n"})
        cls.deleted = cls.commit({LEAF: None})
        cls.renamed = cls.commit({LEAF: None, "tests/renamed_runtime_test.py": "# fixture v1\n"})
        cls.mixed = cls.commit({LEAF: "# fixture v2\n", "app.php": "<?php echo 'mixed';\n"})
        cls.policy = cls.commit({"tools/deployment_content_plan.py": "# another policy\n"})
        cls.git("checkout", "-q", "--detach", cls.base)
        os.chmod(cls.repo / LEAF, 0o755)
        cls.git("add", LEAF)
        cls.git("commit", "-qm", "mode change")
        cls.mode = cls.git("rev-parse", "HEAD").strip()
        cls.git("checkout", "-q", "--detach", cls.base)
        os.symlink("app.php", cls.repo / "runtime-link")
        cls.git("add", "runtime-link")
        cls.git("commit", "-qm", "symlink source")
        cls.symlink = cls.git("rev-parse", "HEAD").strip()
        cls.git("checkout", "-q", "--detach", cls.base)
        cls.git("commit", "--allow-empty", "-qm", "[modeled-pipeline-monitor-activation-v1]")
        cls.same_tree_intent = cls.git("rev-parse", "HEAD").strip()
        cls.git("checkout", "-q", "--detach", cls.base)

    @classmethod
    def tearDownClass(cls):
        cls.temp.cleanup()

    @classmethod
    def git(cls, *args):
        environment = {k: v for k, v in os.environ.items() if not k.startswith("GIT_")}
        environment.update(GIT_CONFIG_NOSYSTEM="1", GIT_CONFIG_GLOBAL=os.devnull)
        return subprocess.check_output(["git", *args], cwd=cls.repo, env=environment,
                                       stderr=subprocess.PIPE, text=True)

    @classmethod
    def commit(cls, changes, parent=None):
        cls.git("checkout", "-q", "--detach", parent or cls.base)
        for name, text in changes.items():
            target = cls.repo / name
            if text is None:
                target.unlink()
            else:
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_text(text)
        cls.git("add", "-A")
        cls.git("commit", "-qm", "synthetic change")
        return cls.git("rev-parse", "HEAD").strip()

    def receipt(self, sha=None):
        return {"schema": 2, "sha": sha or self.base, "run_id": "12345", "install_attempt": 1,
                "smoke_attempt": 1, "diagnostics_attempt": 1,
                "reported_at": "2026-11-05T16:32:46Z",
                **dict.fromkeys(module.STAGES, "success")}

    def classify(self, target, receipt=None):
        result = module.plan(self.repo, self.receipt() if receipt is None else receipt, target)
        self.assertIs(result["skip_installation_authorized"], False)
        self.assertIs(result["production_observed"], False)
        return result

    def test_native_leaf_change_is_content_only_not_install_permission(self):
        result = self.classify(self.leaf)
        self.assertEqual(result["classification"], "development_only")
        self.assertEqual(result["changed_paths"], [LEAF])
        self.assertEqual(result["installed_sha"], self.base)
        self.assertEqual(result["candidate_sha"], self.leaf)
        self.assertEqual(result["installed_runtime_fingerprint"], result["candidate_runtime_fingerprint"])

    def test_native_consecutive_uninstalled_test_commits_compare_to_installed_source(self):
        self.assertEqual(self.classify(self.consecutive_test)["classification"], "development_only")

    def test_native_earlier_uninstalled_runtime_change_cannot_hide_behind_latest_test_commit(self):
        result = self.classify(self.runtime_then_test)
        self.assertEqual(result["classification"], "runtime_or_unknown")
        self.assertEqual(result["changed_path_count"], 2)
        self.assertFalse(result["runtime_contents_unchanged"])

    def test_native_runtime_change_requires_normal_path(self):
        result = self.classify(self.runtime)
        self.assertNotEqual(result["installed_runtime_fingerprint"], result["candidate_runtime_fingerprint"])

    def test_native_mixed_change_cannot_use_development_label(self):
        self.assertEqual(self.classify(self.mixed)["classification"], "runtime_or_unknown")

    def test_native_production_catalog_remains_runtime(self):
        self.assertEqual(self.classify(self.live_test)["classification"], "runtime_or_unknown")

    def test_native_production_regression_remains_runtime(self):
        self.assertEqual(self.classify(self.live_regression)["classification"], "runtime_or_unknown")

    def test_native_live_fixture_remains_runtime(self):
        self.assertEqual(self.classify(self.fixture)["classification"], "runtime_or_unknown")

    def test_native_docs_not_blanket_exempted(self):
        self.assertEqual(self.classify(self.docs)["classification"], "runtime_or_unknown")

    def test_native_workflow_not_exempted(self):
        self.assertEqual(self.classify(self.workflow)["classification"], "runtime_or_unknown")

    def test_native_unknown_file_not_exempted(self):
        self.assertEqual(self.classify(self.unknown)["classification"], "runtime_or_unknown")

    def test_native_policy_cannot_exempt_its_own_change(self):
        self.assertEqual(self.classify(self.policy)["classification"], "runtime_or_unknown")

    def test_native_leaf_removal_requires_normal_path(self):
        self.assertEqual(self.classify(self.deleted)["classification"], "runtime_or_unknown")

    def test_native_leaf_rename_requires_normal_path(self):
        self.assertEqual(self.classify(self.renamed)["classification"], "runtime_or_unknown")

    def test_native_leaf_mode_change_requires_normal_path(self):
        self.assertEqual(self.classify(self.mode)["classification"], "runtime_or_unknown")

    def test_native_symlink_never_confirms_content_equivalence(self):
        result = self.classify(self.symlink)
        self.assertEqual(result["reason"], "unsupported_tree_entry")
        self.assertFalse(result["runtime_contents_unchanged"])

    def test_native_noop_tree_does_not_authorize_activation_or_skip(self):
        # Commit-message activation is outside a tree comparison. Never convert
        # content equality into permission to suppress that release intent.
        result = self.classify(self.same_tree_intent)
        self.assertEqual(result["classification"], "identical_contents")
        self.assertFalse(result["skip_installation_authorized"])

    def test_native_unrelated_installed_branch_is_rejected(self):
        result = self.classify(self.leaf, self.receipt(self.runtime))
        self.assertEqual(result["reason"], "installed_source_not_ancestor")

    def test_native_missing_commit_is_not_equivalence(self):
        self.assertEqual(self.classify("f" * 40)["reason"], "git_read_failed")

    def test_invalid_sha_is_not_a_git_argument(self):
        for bad in ("main", "HEAD", "--help", self.base + "^{tree}", "A" * 40, None):
            with self.subTest(bad=bad):
                self.assertEqual(self.classify(bad)["reason"], "invalid_commit_sha")

    def test_incomplete_receipt_does_not_approve_test_only_change(self):
        for stage in module.STAGES:
            for outcome in (None, "failure", "skipped", "in_progress"):
                with self.subTest(stage=stage, outcome=outcome):
                    receipt = self.receipt()
                    receipt[stage] = outcome
                    self.assertEqual(self.classify(self.leaf, receipt)["reason"], "incomplete_installed_receipt")

    def test_receipt_schema_ids_and_attempts_are_typed(self):
        for key, bad in (("schema", True), ("schema", 1), ("sha", "HEAD"), ("run_id", "0"),
                         ("run_id", "01"), ("run_id", 123), ("run_id", str(2**53)),
                         ("install_attempt", 2), ("install_attempt", True),
                         ("smoke_attempt", 3), ("diagnostics_attempt", "1")):
            with self.subTest(key=key, bad=bad):
                receipt = self.receipt()
                receipt[key] = bad
                self.assertEqual(self.classify(self.leaf, receipt)["classification"], "unconfirmed")

    def test_native_recovered_verification_receipt_is_content_input_only(self):
        receipt = self.receipt()
        receipt.update(smoke_attempt=2, diagnostics_attempt=2)
        self.assertEqual(self.classify(self.leaf, receipt)["classification"], "development_only")

    def test_native_replace_ref_cannot_hide_a_runtime_change(self):
        self.git("replace", self.runtime, self.leaf)
        try:
            self.assertEqual(self.classify(self.runtime)["classification"], "runtime_or_unknown")
        finally:
            self.git("replace", "-d", self.runtime)

    def test_native_ambient_git_dir_does_not_select_another_repository(self):
        with patch.dict(os.environ, {"GIT_DIR": "/missing/.git", "GIT_CONFIG_COUNT": "99"}):
            self.assertEqual(self.classify(self.leaf)["classification"], "development_only")

    def test_native_shallow_history_is_unknown(self):
        shallow = Path(self.temp.name) / "shallow"
        subprocess.check_call(["git", "clone", "-q", "--depth=1", self.repo.as_uri(), str(shallow)],
                              stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        result = module.plan(shallow, self.receipt(), self.base)
        self.assertEqual(result["reason"], "shallow_history")

    def workflow_block(self, lines, marker):
        # Read only this workflow's explicit indentation; no runner dependency
        # or second copy of the Bash implementation is needed by the fixture.
        matches = [i for i, line in enumerate(lines) if line == marker]
        self.assertEqual(len(matches), 1, "expected one scoped marker: " + marker)
        start = matches[0] + 1
        indent = len(marker) - len(marker.lstrip())
        end = next((i for i in range(start, len(lines)) if lines[i].strip()
                    and len(lines[i]) - len(lines[i].lstrip()) <= indent), len(lines))
        return lines[start:end]

    def workflow_steps(self, job):
        lines = (ROOT / ".github/workflows/deploy.yml").read_text().splitlines()
        jobs = self.workflow_block(lines, "jobs:")
        scoped = self.workflow_block(jobs, "  " + job + ":")
        steps = self.workflow_block(scoped, "    steps:")
        starts = [i for i, line in enumerate(steps) if line.startswith("      - ")]
        self.assertTrue(starts, "expected steps in " + job)
        return [steps[start:end] for start, end in zip(starts, starts[1:] + [len(steps)])]

    def workflow_step(self, steps, marker):
        matches = [step for step in steps if marker in step]
        self.assertEqual(len(matches), 1, "expected one scoped step: " + marker)
        return matches[0]

    def verification_step(self):
        step = self.workflow_step(self.workflow_steps("verify"), "        id: verification_history")
        self.assertEqual(step[0], "      - name: Fetch complete verification ancestry for the exact SHA")
        return step

    @staticmethod
    def history_environment():
        environment = {k: v for k, v in os.environ.items() if not k.startswith("GIT_")}
        environment.update(GIT_CONFIG_NOSYSTEM="1", GIT_CONFIG_GLOBAL=os.devnull,
                           GIT_TERMINAL_PROMPT="0")
        return environment

    def history_git(self, repository, *args):
        return subprocess.check_output(["git", *args], cwd=repository,
                                       env=self.history_environment(), stderr=subprocess.PIPE,
                                       text=True, timeout=20).strip()

    def verification_clone(self, target):
        self.git("update-ref", "refs/heads/verification-source", target)
        self.git("update-ref", "refs/heads/verification-unrelated", self.runtime)
        self.git("tag", "-f", "verification-unrelated-tag", self.runtime)
        temporary = tempfile.TemporaryDirectory(prefix="anytour-verification-history-")
        self.addCleanup(temporary.cleanup)
        checkout = Path(temporary.name) / "checkout"
        subprocess.run(["git", "clone", "-q", "--depth=1", "--single-branch", "--no-tags",
                        "--branch", "verification-source", self.repo.as_uri(), str(checkout)],
                       env=self.history_environment(), check=True, capture_output=True, timeout=20)
        self.history_git(checkout, "checkout", "-q", "--detach", target)
        self.assertEqual(self.history_git(checkout, "rev-parse", "--is-shallow-repository"), "true")
        return checkout

    def run_verification_history(self, checkout, expected):
        environment = self.history_environment()
        environment["EXPECTED_SHA"] = expected
        lines = self.workflow_block(self.verification_step(), "        run: |")
        self.assertTrue(lines and any(line.strip() for line in lines))
        self.assertTrue(all(not line.strip() or line.startswith("          ") for line in lines))
        script = textwrap.dedent("\n".join(lines)) + "\n"
        return subprocess.run(["bash", "-e", "-o", "pipefail", "-c", script],
                              cwd=checkout, env=environment, text=True, capture_output=True, timeout=20)

    def test_verification_history_is_common_preparation_without_changing_bundle_checkout(self):
        installer = self.workflow_step(self.workflow_steps("deploy"), "      - name: Checkout exact deployment SHA")
        self.assertEqual([line.strip() for line in self.workflow_block(installer, "        with:") if line.strip()],
                         ["ref: ${{ github.sha }}", "fetch-depth: 0"])
        steps = self.workflow_steps("verify")
        checkout = self.workflow_step(steps, "        uses: actions/checkout@v4")
        self.assertEqual(checkout[0], "      - name: Checkout exact installed verification SHA")
        self.assertEqual([line.strip() for line in self.workflow_block(checkout, "        with:") if line.strip()],
                         ["ref: ${{ github.sha }}", "fetch-depth: 1"])
        history = self.verification_step()
        self.assertEqual([line.strip() for line in self.workflow_block(history, "        env:") if line.strip()],
                         ["EXPECTED_SHA: ${{ github.sha }}"])
        self.assertEqual(history.count("        shell: bash"), 1)
        self.assertFalse(any(line.startswith(("        if:", "        continue-on-error:")) for line in history))
        admission = self.workflow_step(steps, "        id: admission")
        unchanged = self.workflow_step(steps, "        id: unchanged")
        self.assertLess(steps.index(checkout), steps.index(history))
        self.assertLess(steps.index(history), min(steps.index(admission), steps.index(unchanged)))

    def test_exact_verification_fetch_keeps_full_dag_and_excludes_unrelated_refs_and_objects(self):
        tree = self.git("rev-parse", self.leaf + "^{tree}").strip()
        merged = self.git("commit-tree", tree, "-p", self.leaf, "-p", self.same_tree_intent,
                          "-m", "merge a second-parent deployment intent").strip()
        for target in (self.consecutive_test, merged):
            with self.subTest(target=target):
                checkout = self.verification_clone(target)
                before_refs = self.history_git(checkout, "for-each-ref", "--format=%(refname)")
                result = self.run_verification_history(checkout, target)
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(self.history_git(checkout, "rev-parse", "HEAD"), target)
                self.assertEqual(self.history_git(checkout, "rev-parse", "--is-shallow-repository"), "false")
                self.assertEqual(self.history_git(checkout, "for-each-ref", "--format=%(refname)"), before_refs)
                self.assertEqual(self.history_git(checkout, "tag", "--list"), "")
                actual = set(self.history_git(checkout, "rev-list", "--parents", target).splitlines())
                expected = set(self.git("rev-list", "--parents", target).splitlines())
                self.assertEqual(actual, expected)
                objects = set(self.history_git(checkout, "cat-file", "--batch-all-objects",
                                               "--batch-check=%(objectname)").splitlines())
                self.assertNotIn(self.runtime, objects)
                self.assertEqual(module.plan(checkout, self.receipt(), target), self.classify(target))
                if target == merged:
                    observed = module.scan_release_intents(module.GitObjects(checkout), self.base, target)
                    self.assertEqual(observed["status"], "deployment_intent_present")
                    self.assertIn(self.same_tree_intent, [m["commit_sha"] for m in observed["matched_commits"]])

    def test_verification_history_rejects_wrong_source_before_fetch(self):
        checkout = self.verification_clone(self.consecutive_test)
        self.history_git(checkout, "remote", "remove", "origin")
        for expected in ("main", "--help", self.base):
            with self.subTest(expected=expected):
                result = self.run_verification_history(checkout, expected)
                self.assertNotEqual(result.returncode, 0)
                self.assertNotIn("origin", result.stderr)
                self.assertEqual(self.history_git(checkout, "rev-parse", "HEAD"), self.consecutive_test)
                self.assertEqual(self.history_git(checkout, "rev-parse", "--is-shallow-repository"), "true")

    def test_failed_verification_fetch_cannot_be_reported_as_complete_history(self):
        checkout = self.verification_clone(self.consecutive_test)
        self.history_git(checkout, "remote", "set-url", "origin", str(checkout.parent / "missing.git"))
        result = self.run_verification_history(checkout, self.consecutive_test)
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(self.history_git(checkout, "rev-parse", "HEAD"), self.consecutive_test)
        self.assertEqual(self.history_git(checkout, "rev-parse", "--is-shallow-repository"), "true")

    def test_complete_verification_history_needs_no_second_fetch(self):
        checkout = self.verification_clone(self.consecutive_test)
        result = self.run_verification_history(checkout, self.consecutive_test)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.history_git(checkout, "remote", "remove", "origin")
        result = self.run_verification_history(checkout, self.consecutive_test)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.history_git(checkout, "rev-parse", "HEAD"), self.consecutive_test)

    def test_tree_parser_preserves_regular_executable_runtime_entries(self):
        result = module.parse_tree(b"100755 blob " + b"a" * 40 + b"\ttools/run.sh\0")
        self.assertEqual(result["tools/run.sh"], ("100755", "a" * 40))

    def test_tree_parser_rejects_truncation_duplicates_paths_and_submodules(self):
        entry = b"100644 blob " + b"a" * 40 + b"\tapp.php\0"
        cases = [b"", entry[:-1], entry + entry,
                 b"160000 commit " + b"a" * 40 + b"\tmodule\0",
                 b"120000 blob " + b"a" * 40 + b"\tlink\0"]
        for name in (b"../app", b"/app", b"a//b", b"a\\b", b"a\nb", b"\xff"):
            cases.append(b"100644 blob " + b"a" * 40 + b"\t" + name + b"\0")
        for raw in cases:
            with self.subTest(raw=raw):
                with self.assertRaises(module.Unconfirmed):
                    module.parse_tree(raw)

    def test_changed_path_output_is_bounded_but_all_changes_are_classified(self):
        before = {"a": ("100644", "a" * 40)}
        after = {**before, **{f"z{i:04d}": ("100644", "b" * 40) for i in range(105)}}
        result = module.compare_trees(before, after)
        self.assertEqual(result["classification"], "runtime_or_unknown")
        self.assertEqual(result["changed_path_count"], 105)
        self.assertEqual(len(result["changed_paths"]), 100)
        self.assertTrue(result["changed_paths_truncated"])

    def test_native_cli_reads_receipt_and_emits_no_permission(self):
        receipt_file = Path(self.temp.name) / "receipt.json"
        receipt_file.write_text(json.dumps(self.receipt()))
        command = [sys.executable, str(ROOT / "tools/deployment_content_plan.py"),
                   "--repository", str(self.repo), "--installed-receipt", str(receipt_file),
                   "--candidate-sha", self.leaf]
        result = subprocess.run(command, capture_output=True, text=True, check=False)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertFalse(json.loads(result.stdout)["skip_installation_authorized"])
        receipt_file.write_text("{not-json PRIVATE_PAYLOAD}")
        result = subprocess.run(command, capture_output=True, text=True, check=False)
        self.assertEqual(result.returncode, 2)
        self.assertNotIn("PRIVATE_PAYLOAD", result.stdout + result.stderr)
        self.assertEqual(json.loads(result.stdout)["reason"], "unreadable_installed_receipt")


if __name__ == "__main__":
    unittest.main(verbosity=2)
