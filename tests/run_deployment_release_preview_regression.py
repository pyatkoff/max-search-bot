#!/usr/bin/env python3
"""Offline real-Git integration of content, commit intent and receipt proposals."""
from __future__ import annotations
import copy
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'tools'))
import deployment_content_plan as content
import deployment_release_preview as release
spec = importlib.util.spec_from_file_location('base_content_test_module', ROOT / 'tests/run_deployment_content_plan_regression.py')
base_tests = importlib.util.module_from_spec(spec)
spec.loader.exec_module(base_tests)


class ReleasePreviewTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.fx = base_tests.ContentPlanTests
        cls.fx.setUpClass()
        cls.repo = cls.fx.repo
        cls.markers = {}
        for marker in content.DEPLOYMENT_MARKERS:
            for message in (marker, marker.upper()):
                cls.markers[message] = cls.message_commit('Private synthetic text ' + message)
        cls.prior_intent = cls.markers[content.DEPLOYMENT_MARKERS[0]]
        cls.test_after_intent = cls.fx.commit({base_tests.LEAF: '# test after activation\n'}, parent=cls.prior_intent)
        cls.unknown = cls.message_commit('[modeled-unregistered-v99] PRIVATE_NOT_FOR_REPORT')
        cls.incomplete_marker = cls.message_commit('[anytour-' + 'x' * 250)
        cls.oversize = cls.message_commit('x' * (content.MAX_COMMIT_BYTES + 1))
        cls.benign = cls.message_commit('modeled conversion test: no bracketed operation marker')
        # A merge whose final tree is unchanged but whose second parent carries
        # an uninstalled activation intent. The complete DAG must be inspected.
        tree = cls.fx.git('rev-parse', cls.fx.base + '^{tree}').strip()
        cls.second_parent = cls.fx.git('commit-tree', tree, '-p', cls.fx.leaf,
                                      '-p', cls.prior_intent, '-m', 'merge pending branches').strip()
        cls.fx.git('checkout', '-q', '--detach', cls.fx.base)

    @classmethod
    def tearDownClass(cls):
        cls.fx.tearDownClass()

    @classmethod
    def message_commit(cls, message):
        cls.fx.git('checkout', '-q', '--detach', cls.fx.base)
        cls.fx.git('commit', '--allow-empty', '-qm', message)
        return cls.fx.git('rev-parse', 'HEAD').strip()

    def receipt(self, sha=None):
        return self.fx().receipt(sha)

    def event(self, sha=None):
        return {'repository': release.REPOSITORY, 'event_name': 'push', 'ref': 'refs/heads/main',
                'sha': sha or self.fx.leaf, 'run_id': '67890', 'run_attempt': 1,
                'forced': False, 'inputs': {}}

    def preview(self, sha=None, receipt=None, event=None):
        sha = sha or self.fx.leaf
        result = release.preview(self.repo, receipt if receipt is not None else self.receipt(),
                                 sha, event if event is not None else self.event(sha))
        for key in ('skip_installation_authorized', 'production_observed',
                    'release_slot_mutation_authorized', 'publication_performed'):
            self.assertIs(result[key], False)
        return result

    def test_all_four_activation_markers_require_ordinary_release_in_both_cases(self):
        for marker, sha in self.markers.items():
            with self.subTest(marker=marker):
                result = self.preview(sha)
                self.assertEqual(result['decision'], 'ordinary_release_required')
                self.assertEqual(result['comparison']['classification'], 'identical_contents')
                self.assertNotIn('proposed_record', result)
                self.assertNotIn('Private synthetic text', json.dumps(result))

    def test_prior_activation_cannot_hide_behind_latest_development_commit(self):
        result = self.preview(self.test_after_intent)
        self.assertEqual(result['comparison']['classification'], 'development_only')
        self.assertEqual(result['decision'], 'ordinary_release_required')
        self.assertEqual(result['comparison']['intent_scan']['checked_commits'], 2)

    def test_uninstalled_second_parent_intent_is_seen(self):
        result = self.preview(self.second_parent)
        self.assertEqual(result['decision'], 'ordinary_release_required')
        self.assertIn(self.prior_intent, [x['commit_sha'] for x in result['comparison']['intent_scan']['matched_commits']])

    def test_original_installed_intent_is_not_replayed(self):
        result = self.preview(self.test_after_intent, receipt=self.receipt(self.prior_intent))
        self.assertEqual(result['decision'], 'no_install_candidate')
        self.assertEqual(result['proposed_record']['application_sha'], self.prior_intent)

    def test_unknown_reserved_marker_is_not_silently_ignored_or_disclosed(self):
        result = self.preview(self.unknown)
        self.assertEqual(result['decision'], 'ordinary_release_required')
        self.assertTrue(result['comparison']['intent_scan']['matched_commits'][0]['unknown_marker_present'])
        self.assertNotIn('PRIVATE_NOT_FOR_REPORT', json.dumps(result))
        self.assertNotIn('unregistered-v99', json.dumps(result))

    def test_overlong_incomplete_reserved_marker_requires_ordinary_release(self):
        self.assertEqual(self.preview(self.incomplete_marker)['decision'], 'ordinary_release_required')

    def test_benign_message_is_not_interpreted_as_activation(self):
        self.assertEqual(self.preview(self.benign)['decision'], 'no_install_candidate')

    def test_commit_message_budget_cannot_fall_back_to_content_success(self):
        result = self.preview(self.oversize)
        self.assertEqual(result['decision'], 'blocked')
        self.assertEqual(result['comparison']['classification'], 'unconfirmed')
        self.assertEqual(result['reason'], 'intent_message_limit')
        self.assertFalse(result['comparison']['runtime_contents_unchanged'])

    def test_incomplete_history_scan_never_returns_content_candidate(self):
        with patch.object(content, 'MAX_INTENT_COMMITS', 1):
            result = self.preview(self.fx.consecutive_test)
        self.assertEqual(result['decision'], 'blocked')
        self.assertEqual(result['reason'], 'intent_history_limit')

    def test_grafted_history_is_rejected(self):
        grafts = self.repo / '.git/info/grafts'
        grafts.write_text(self.fx.leaf + ' ' + self.fx.base + '\n')
        try:
            result = self.preview()
            self.assertEqual(result['decision'], 'blocked')
            self.assertEqual(result['reason'], 'grafted_history')
        finally:
            grafts.unlink()

    def test_missing_partial_clone_objects_cannot_trigger_lazy_fetch(self):
        git = content.GitObjects(self.repo)
        self.assertEqual(git.environment['GIT_NO_LAZY_FETCH'], '1')
        self.assertEqual(git.environment['GIT_TERMINAL_PROMPT'], '0')

    def test_reported_time_must_be_a_valid_explicit_instant(self):
        for stamp in (None, '2026-02-30T01:00:00Z', '2026-11-05 16:32:46',
                      '2026-11-05T16:32:46+03:00', 'unknown'):
            with self.subTest(stamp=stamp):
                receipt = self.receipt(); receipt['reported_at'] = stamp
                result = self.preview(receipt=receipt)
                self.assertEqual(result['decision'], 'blocked')
                self.assertEqual(result['reason'], 'invalid_installed_timestamp')

    def test_distinct_repository_and_application_revisions_do_not_invent_installation(self):
        receipt = self.receipt(); before = copy.deepcopy(receipt)
        result = self.preview(receipt=receipt)
        record = result['proposed_record']
        self.assertEqual(record['repository_sha'], self.fx.leaf)
        self.assertEqual(record['application_sha'], self.fx.base)
        self.assertEqual(record['installation_run_id'], '12345')
        self.assertEqual(record['candidate_run_id'], '67890')
        self.assertFalse(record['completed'])
        self.assertFalse(record['installation_performed'])
        for field in ('sha', 'run_id', *content.STAGES):
            self.assertNotIn(field, record)
        self.assertEqual(receipt, before)
        self.assertEqual(record['original_installation']['receipt_digest'], release.canonical_digest(receipt))

    def test_preview_cannot_be_used_as_original_installation_receipt(self):
        record = self.preview()['proposed_record']
        with self.assertRaises(content.Unconfirmed):
            content.validate_receipt(record)
        result = self.preview(self.fx.consecutive_test, receipt=record)
        self.assertEqual(result['decision'], 'blocked')

    def test_consecutive_no_install_candidates_keep_the_original_application_sha(self):
        for sha in (self.fx.leaf, self.fx.consecutive_test):
            result = self.preview(sha)
            self.assertEqual(result['decision'], 'no_install_candidate')
            self.assertEqual(result['proposed_record']['application_sha'], self.fx.base)

    def test_earlier_uninstalled_application_change_is_not_accepted(self):
        result = self.preview(self.fx.runtime_then_test)
        self.assertEqual(result['decision'], 'ordinary_release_required')
        self.assertNotIn('proposed_record', result)

    def test_manual_release_is_never_suppressed_by_unchanged_files(self):
        event = self.event(); event['event_name'] = 'workflow_dispatch'
        result = self.preview(event=event)
        self.assertEqual(result['decision'], 'ordinary_release_required')
        self.assertEqual(result['reason'], 'explicit_manual_release')

    def test_forced_or_input_bearing_event_keeps_the_ordinary_path(self):
        for delta in ({'forced': True}, {'inputs': {'operation': 'enable'}}):
            event = self.event(); event.update(delta)
            self.assertEqual(self.preview(event=event)['decision'], 'ordinary_release_required')

    def test_foreign_wrong_branch_stale_head_unknown_event_or_attempt_cannot_propose(self):
        for delta in ({'repository': 'foreign/repo'}, {'ref': 'refs/heads/other'},
                      {'sha': self.fx.base}, {'run_attempt': 2}, {'run_attempt': True},
                      {'event_name': 'pull_request'}, {'run_id': '0'}, {'run_id': str(2**53)},
                      {'forced': 'false'}, {'inputs': None}, {'unexpected': True}):
            with self.subTest(delta=delta):
                event = self.event(); event.update(delta)
                result = self.preview(event=event)
                self.assertEqual(result['decision'], 'blocked')
                self.assertNotIn('proposed_record', result)

    def test_missing_context_does_not_mean_no_intent(self):
        for field in self.event():
            event = self.event(); del event[field]
            self.assertEqual(self.preview(event=event)['decision'], 'blocked')

    def test_same_revision_is_not_a_new_no_install_release(self):
        self.assertEqual(self.preview(self.fx.base)['decision'], 'ordinary_release_required')

    def test_failed_baseline_has_no_proposed_record(self):
        receipt = self.receipt(); receipt['diagnostics'] = 'failure'
        result = self.preview(receipt=receipt)
        self.assertEqual(result['decision'], 'blocked')
        self.assertNotIn('proposed_record', result)

    def test_recovery_provenance_is_preserved_not_relabelled(self):
        receipt = self.receipt(); receipt.update(smoke_attempt=2, diagnostics_attempt=2)
        record = self.preview(receipt=receipt)['proposed_record']['original_installation']
        self.assertEqual(record['install_attempt'], 1)
        self.assertEqual(record['smoke_attempt'], 2)
        self.assertEqual(record['diagnostics_attempt'], 2)

    def test_repeated_preview_is_deterministic_and_has_no_repository_write(self):
        def state():
            return (self.fx.git('rev-parse', 'HEAD'), self.fx.git('status', '--porcelain'),
                    self.fx.git('for-each-ref', '--format=%(refname) %(objectname)'))
        before = state(); first = self.preview(); second = self.preview()
        self.assertEqual(first, second)
        self.assertEqual(state(), before)

    def cli(self, receipt, event):
        with tempfile.TemporaryDirectory(prefix='anytour-preview-cli-') as folder:
            folder = Path(folder)
            a, b = folder/'receipt.json', folder/'event.json'
            a.write_text(receipt if isinstance(receipt, str) else json.dumps(receipt))
            b.write_text(event if isinstance(event, str) else json.dumps(event))
            return subprocess.run([sys.executable, str(ROOT/'tools/deployment_release_preview.py'),
                                   '--repository', str(self.repo), '--installed-receipt', str(a),
                                   '--event-context', str(b), '--candidate-sha', self.fx.leaf],
                                  text=True, capture_output=True, timeout=10)

    def test_real_cli_produces_preview_not_installation_success(self):
        run = self.cli(self.receipt(), self.event())
        self.assertEqual(run.returncode, 0, run.stderr)
        result = json.loads(run.stdout)
        self.assertEqual(result['decision'], 'no_install_candidate')
        self.assertFalse(result['skip_installation_authorized'])
        self.assertFalse(result['proposed_record']['completed'])

    def test_cli_rejects_duplicate_malformed_and_oversized_json_without_disclosure(self):
        for text in ('{"sha":"PRIVATE_A","sha":"PRIVATE_B"}', 'PRIVATE_INVALID',
                     '{"x": NaN}', 'x'*(content.MAX_RECEIPT+1)):
            with self.subTest(length=len(text)):
                run = self.cli(text, self.event())
                self.assertEqual(run.returncode, 2)
                self.assertEqual(json.loads(run.stdout)['decision'], 'blocked')
                self.assertNotIn('PRIVATE_', run.stdout + run.stderr)


if __name__ == '__main__':
    unittest.main(verbosity=2)
