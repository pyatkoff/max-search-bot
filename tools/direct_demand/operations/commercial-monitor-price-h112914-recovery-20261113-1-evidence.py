#!/usr/bin/env python3
"""Exact public evidence for one original112914 recovery; no provider/DB client."""
import argparse
import hashlib
import io
import json
import os
from pathlib import Path, PurePosixPath
import re
import subprocess
import zipfile

OP = 'commercial-monitor-price-h112914-recovery-20261113-1'
ORIGINAL = 'commercial-monitor-price-h112914-d7617e5a491183b8f8e73d26a8b419b6147d6a8323b84799d0b6057819f93e70'
CORE_HEAD = '2a1f13ed4f766f5d1371c6275e3d4f69bac0e558'
VERIFIER_SHA = '5e2a31e62984792225fc57f512b508c5975d1ec463413f18bddb29bfa0aa82fb'
GATES = ['deploy.yml', 'max-tls-preflight.yml', 'modeled-conversion-value-export.yml', 'max-production-health.yml',
         'telegram-production-health.yml', 'live-session-diagnostics.yml', 'modeled-conversion-monitor.yml',
         'tour-selection-host.yml', 'publish-conversation-diagnostics.yml']


def need(ok, reason):
    if not ok:
        raise ValueError(reason)


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def canonical(value):
    return json.dumps(value, ensure_ascii=False, separators=(',', ':')).encode()


def saved(path, value):
    Path(path).write_text(json.dumps(value, ensure_ascii=False, indent=2) + '\n')


def require_admission(plan):
    need(plan.get('ready') is True and plan.get('execution_authorized') is True, 'recovery_actual_execution_admission_required')
    need(plan['operation'] == OP and plan['original_operation'] == ORIGINAL, 'recovery_scope_changed')
    current = plan.get('expected_current_installed_sha')
    need(isinstance(current, str) and re.fullmatch('[a-f0-9]{40}', current)
         and plan.get('expected_installed_sha') == current, 'recovery_actual_current_source_required')
    need(isinstance(plan.get('expected_parent_head'), str) and re.fullmatch('[a-f0-9]{40}', plan['expected_parent_head'])
         and isinstance(plan.get('owner_pin'), dict), 'recovery_actual_owner_and_parent_required')
    a = plan.get('current_source_acceptance')
    need(isinstance(a, dict) and a.get('schema') == 'anytour.current-source-acceptance.v1'
         and a.get('installed_sha') == current and a.get('core_pull_request') == 1522
         and a.get('core_head_sha') == CORE_HEAD and isinstance(a.get('core_merge_sha'), str)
         and re.fullmatch('[a-f0-9]{40}', a['core_merge_sha'])
         and a.get('verifier_sha256') == VERIFIER_SHA
         and a.get('runtime_pins_sha256') == sha(canonical(plan['current_runtime_pins'])), 'recovery_accepted_core_proof_required')
    gates = a.get('source_gates')
    need(isinstance(gates, dict) and set(gates) == set(GATES), 'recovery_actual_source_gates_required')
    for gate in gates.values():
        need(type(gate.get('run_id')) is int and gate['run_id'] > 0 and gate.get('head_sha') == current
             and gate.get('run_attempt') == 1 and gate.get('conclusion') == 'success', 'recovery_actual_source_gate_invalid')
    return a


def verify_readiness(plan, run, meta, raw):
    b = plan['readiness_binding']
    need(run['id'] == b['run_id'] and run['run_attempt'] == 1 and run['head_sha'] == b['head_sha']
         and run['status'] == 'completed' and run['conclusion'] == 'success'
         and run['path'] == '.github/workflows/content100-bot-publication-20261101.yml'
         and run['head_branch'] == 'audit/content100-bot-publication-20261101', 'recovery_original_readiness_run_changed')
    need(meta['id'] == b['artifact_id'] and meta['name'] == b['artifact_name'] and meta['expired'] is False
         and meta['digest'] == 'sha256:' + b['artifact_sha256'] and meta['workflow_run']['id'] == b['run_id']
         and meta['workflow_run']['head_sha'] == b['head_sha'] and sha(raw) == b['artifact_sha256'], 'recovery_original_readiness_archive_changed')
    with zipfile.ZipFile(io.BytesIO(raw)) as z:
        need(len(z.infolist()) <= 100 and sum(i.file_size for i in z.infolist()) < 20000000, 'recovery_original_archive_limit')

        def one(name, cap=200000):
            entries = [i for i in z.infolist() if PurePosixPath(i.filename).name == name]
            need(len(entries) == 1 and entries[0].file_size < cap, 'recovery_original_member_missing_or_ambiguous')
            return z.read(entries[0])

        result_raw = one('result.json'); transport_raw = one('transport.json')
        need(sha(result_raw) == b['result_sha256'] and sha(transport_raw) == b['transport_sha256'], 'recovery_original_result_changed')
        d = json.loads(result_raw.splitlines()[-1]); t = json.loads(transport_raw)
        need(d['operation'] == b['operation'] and d['installed_sha'] == b['installed_sha'] and d['ok'] is True
             and d['journal_cas_verified'] is True and d['global_lock_available'] is True
             and d['global_pending_is_null'] is False and d['feed_continuation_authorized'] is False,
             'recovery_readiness_not_bound')
        need(all(d[k] == 0 for k in ['db_write_attempts', 'journal_write_attempts', 'post_write_attempts',
                                    'configuration_write_attempts', 'provider_write_attempts', 'feed_write_attempts']), 'recovery_readiness_was_not_read_only')
        need(d['pending'] == plan['original_pending'] and d['pending']['operation'] == ORIGINAL, 'recovery_original_pending_changed')
        for key, target in [('global_journal', 'global_prefix'), ('child_journal', 'child_prefix')]:
            need(all(d[key][k] == v for k, v in plan[target].items()), 'recovery_original_prefix_changed')
        child = d['child_journal']; n = d['native_max']
        need(child['history_lines_scanned'] == child['lines'] == 4 and child['historical_post_keys'] == ['max:anjum']
             and child['telegram_intent_seen'] is False and child['complete'] is None and 'complete' not in child['state_keys']
             and child['monitor_snapshot_sha256'] == plan['original_monitor_snapshot_sha256']
             and child['feed_binding_sha256'] == plan['original_feed_binding_sha256'], 'recovery_original_child_scope_changed')
        need(n['attempted'] is True and n['ok'] is True and n['native_confirmed'] is True and n['provider_gets'] == 3
             and n['before_write_callback_calls'] == 0 and n['database_session_read_only'] is True
             and n['publication_id'] == 184 and n['version_id'] == 4323 and n['previous_version_id'] == 3405
             and n['record_id'] == plan['record_id'] and n['manifest_hash'] == plan['original_manifest_sha256']
             and n['registry_state'] == 'requires_attention' and n['registry_write_attempts'] == 0
             and n['registry_claim_present'] is False and n['registry_unchanged_during_read'] is True
             and n['transport_state'] == 'sent' and n['transport_reason'] == 'already_present'
             and all(n[k] is True for k in ['text_matches', 'photo_matches', 'url_matches', 'only_expected_button'])
             and all(n[k] == 0 for k in ['db_write_attempts', 'journal_write_attempts', 'post_write_attempts', 'provider_write_attempts']),
             'recovery_actual_native_proof_changed')
        need(n['original_plan_sha256'] == plan['original_max_plan_sha256']
             and n['original_button_result_sha256'] == plan['original_max_button_result_sha256']
             and n['expected_text_sha256'] == n['actual_text_sha256'] == plan['expected_max_text_sha256']
             and n['expected_photo_signature_sha256'] == plan['expected_max_photo_signature_sha256'], 'recovery_native_content_pins_changed')
        need([r['operation'] for r in n['http_results']] == ['bot_identity', 'channel_rights', 'current_post']
             and all(r['http'] == 200 and not r['transport_error'] for r in n['http_results']), 'recovery_native_read_receipts_changed')
        need(t['ssh_status'] == 0 and t['read_only'] is True and t['retry_authorized'] is False
             and str(t['run_id']) == str(b['run_id']) and t['head_sha'] == b['head_sha'] and t['run_attempt'] == 1, 'recovery_readiness_transport_changed')
        old = plan['original_readiness1']; failure = plan['original_feed_attempt']
        need(d['original_readiness_result_sha256'] == old['result_sha256']
             and sha(one('previous-readiness-original.zip', 1000000)) == old['artifact_sha256']
             and sha(one('previous-readiness-result.json')) == old['result_sha256'], 'recovery_readiness1_history_changed')
        need(json.loads(one('original-failure-binding.json')) == {'verified': True, **failure}
             and sha(one('original-guard-receipt.json')) == failure['writer_receipt_sha256'], 'recovery_original_feed_refusal_changed')
        retained = {name: one(name) for name in ['original-failure-binding.json', 'original-guard-receipt.json',
                    'original-started.json', 'original-wrapper-proof.json', 'previous-readiness-result.json',
                    'previous-readiness-transport.json', 'previous-readiness-run.json', 'previous-readiness-artifact.json']}
        retained['previous-readiness-original.zip'] = one('previous-readiness-original.zip', 1000000)
        retained['readiness2-result.json'] = result_raw; retained['readiness2-transport.json'] = transport_raw
        return retained


def verify_success(plan, result, transport, started):
    a = require_admission(plan)
    need(transport['ssh_status'] == 0 and transport['run_attempt'] == 1 and transport['retry_authorized'] is False
         and transport['read_only'] is False, 'recovery_transport_not_confirmed')
    need(started['operation'] == OP and started['ready_plan_sha256'] == sha(canonical(plan))
         and started['readiness_result_sha256'] == plan['readiness_result_sha256']
         and started['current_installed_sha'] == a['installed_sha'] and started['run_attempt'] == 1
         and str(started['run_id']) == str(transport['run_id']) and started['head_sha'] == transport['head_sha'], 'recovery_started_binding_changed')
    need(result['ok'] is True and result['operation'] == OP and result['original_operation'] == ORIGINAL
         and result['outcome'] == 'original_pending_completed' and result['installed_sha'] == a['installed_sha']
         and result['record_id'] == plan['record_id'] and result['tourvisor_hotel_id'] == 112914
         and result['readiness_result_sha256'] == plan['readiness_result_sha256']
         and result['original_global_prefix'] == plan['global_prefix'] and result['original_child_prefix'] == plan['child_prefix']
         and result['original_manifest_sha256'] == plan['original_manifest_sha256'], 'recovery_success_scope_changed')
    flags = ['original_prefix_cas_verified', 'child_prefix_preserved', 'child_complete', 'child_lock_released_before_feed',
             'global_lock_retained_through_feed', 'original_pending_closed', 'global_pending_is_null', 'global_prefix_preserved',
             'global_unrelated_fields_unchanged', 'holds_unchanged', 'nightly_cycle_unchanged', 'original_feed_completed',
             'feed_local_and_public_hashes_verified_by_frozen_hook']
    need(all(result.get(k) is True for k in flags), 'recovery_original_completion_not_confirmed')
    need(result['max_confirmation_attempts'] == 1 and result['telegram_caption_edit_attempts'] == 1
         and result['feed_finish_invocations'] == 1 and result['retry_authorized'] is False
         and all(result[k] == 0 for k in ['max_provider_content_write_attempts', 'telegram_button_write_attempts',
                                         'new_posts_created', 'supplier_calls', 'collector_calls', 'manual_pending_clear_attempts']),
         'recovery_bounded_effects_changed')
    m = result['max_confirmation']; t = result['telegram']
    need(m['ok'] is True and m['state'] == 'sent' and m['reason'] == 'already_present' and m['publication_id'] == 184
         and m['version_id'] == 4323 and m['config_version'] == 2 and m['database_writes'] == 1 and m['provider_writes'] == 0
         and m['receipt']['request_count'] == 3 and m['receipt']['write_attempts'] == 0
         and [r['operation'] for r in m['receipt']['http_results']] == ['bot_identity', 'channel_rights', 'current_post']
         and all(r['http'] == 200 and not r['transport_error'] for r in m['receipt']['http_results']), 'recovery_max_confirmation_not_exact')
    need(t['state'] == 'sent' and t['version_id'] > t['previous_version_id'] > 0 and t['publication_id'] > 0
         and t['body_photo_button_verified'] is True and t['caption_edit_attempts'] == 1 and t['button_write_attempts'] == 0,
         'recovery_telegram_not_confirmed')
    need(result['sequence'] == ['global_ex', 'child_ex', 'original_prefix_cas', 'max_confirmation_intent',
         'max_same_version_confirmed', 'telegram_first_intent', 'child_complete_durable_readback',
         'child_ex_released_global_ex_retained', 'frozen_hook_finish_feed_completed'], 'recovery_lock_handoff_order_changed')
    for final, initial in [('global_final_pin', 'global_prefix'), ('child_complete_pin', 'child_prefix')]:
        p = result[final]
        need(p['bytes'] > plan[initial]['bytes'] and p['lines'] > plan[initial]['lines']
             and all(isinstance(p[k], str) and re.fullmatch('[a-f0-9]{64}', p[k]) for k in ['sha256', 'last_line_sha256']), 'recovery_final_journal_pin_invalid')
    need([f['name'] for f in result['feed_files']] == ['hotel-posts-telegram.xml', 'hotel-posts-max.xml', 'hotel-posts-all.xml']
         and [f['rows'] for f in result['feed_files']] == [575, 575, 1150], 'recovery_original_feed_scope_changed')
    for f in result['feed_files']:
        need(f['bytes'] > 0 and all(isinstance(f[k], str) and re.fullmatch('[a-f0-9]{64}', f[k])
                                    for k in ['sha256', 'expected_previous_sha256']), 'recovery_final_feed_pin_invalid')
    return {'schema': 'anytour.original-monitor-recovery-terminal.v1', 'ok': True, 'operation': OP,
            'original_operation': ORIGINAL, 'tourvisor_hotel_id': 112914, 'record_id': plan['record_id'],
            'installed_sha': a['installed_sha'], 'current_source_acceptance_sha256': sha(canonical(a)),
            'readiness_result_sha256': plan['readiness_result_sha256'], 'run_id': transport['run_id'],
            'run_attempt': 1, 'head_sha': transport['head_sha'], 'original_pending_closed': True,
            'retry_authorized': False, 'feed_operation': result['feed_operation'], 'feed_files': result['feed_files']}


def main():
    ap = argparse.ArgumentParser(); ap.add_argument('mode', choices=['authenticate', 'verify-result']); ap.add_argument('--plan', type=Path, required=True); ap.add_argument('--evidence', type=Path, required=True)
    args = ap.parse_args(); plan = json.loads(args.plan.read_bytes()); acceptance = require_admission(plan); e = args.evidence
    e.mkdir(parents=True, exist_ok=True, mode=0o700)
    if args.mode == 'authenticate':
        def api(path):
            return subprocess.check_output(['gh', 'api', 'repos/pyatkoff/max-search-bot/' + path])
        b = plan['readiness_binding']; run_raw = api('actions/runs/' + str(b['run_id'])); meta_raw = api('actions/artifacts/' + str(b['artifact_id']))
        raw = api('actions/artifacts/' + str(b['artifact_id']) + '/zip')
        retained = verify_readiness(plan, json.loads(run_raw), json.loads(meta_raw), raw)
        for name, value in retained.items():
            (e / name).write_bytes(value)
        (e / 'readiness2-original.zip').write_bytes(raw); (e / 'readiness2-run.json').write_bytes(run_raw); (e / 'readiness2-artifact.json').write_bytes(meta_raw)
        core_raw = api('pulls/1522'); core = json.loads(core_raw)
        need(core['number'] == 1522 and core['merged'] is True and core['head']['sha'] == acceptance['core_head_sha']
             and core['merge_commit_sha'] == acceptance['core_merge_sha'] and core['base']['ref'] == 'main', 'recovery_actual_merged_core_changed')
        (e / 'accepted-core-pr.json').write_bytes(core_raw); saved(e / 'current-source-acceptance.json', acceptance)
        (e / 'recovery-plan.json').write_bytes(args.plan.read_bytes())
        (e / 'recovery-evidence-contract.py').write_bytes(Path(__file__).read_bytes())
        saved(e / 'readiness-binding.json', {'verified': True, **b})
        print('ORIGINAL_READINESS2_NATIVE_AND_JOURNAL_PROOF_AUTHENTICATED')
    else:
        raw = (e / 'result.json').read_bytes(); transport = json.loads((e / 'transport.json').read_bytes()); started = json.loads((e / 'recovery.started').read_bytes())
        need(sha(raw) == transport['stdout_sha256'], 'recovery_transport_result_bytes_changed')
        summary = verify_success(plan, json.loads(raw.splitlines()[-1]), transport, started)
        summary['result_sha256'] = sha(raw); summary['transport_sha256'] = sha((e / 'transport.json').read_bytes())
        summary['started_sha256'] = sha((e / 'recovery.started').read_bytes())
        summary['plan_sha256'] = sha(args.plan.read_bytes()); summary['compiled_sha256'] = started['compiled_sha256']
        summary['evidence_contract_sha256'] = sha(Path(__file__).read_bytes()); saved(e / 'summary.json', summary)
        print(json.dumps(summary, ensure_ascii=False))


if __name__ == '__main__':
    main()
