#!/usr/bin/env python3
"""Validate a real bounded-read receipt and reconcile it locally with the 604 baseline."""
import argparse
import hashlib
import json
from collections import Counter, defaultdict
from pathlib import Path

ROOT = Path(__file__).resolve().parent
parser = argparse.ArgumentParser()
parser.add_argument('receipt', type=Path)
parser.add_argument('--output', type=Path, required=True)
args = parser.parse_args()
scope_raw = (ROOT / 'scope.json').read_bytes()
scope = json.loads(scope_raw)
raw = args.receipt.read_bytes()
receipt = json.loads(raw)
assert receipt['operation'] == scope['operation']
assert receipt['scope_sha256'] == hashlib.sha256(scope_raw).hexdigest()
assert 'fatal' not in receipt, 'Source guard failure invalidates the receipt'
for key in ['db_writes','provider_calls','price_reads','model_calls','customer_rows_read',
            'message_sends','new_publications','identity_bindings_written','automation_changes']:
    assert receipt[key] == 0, key
resolution = receipt['catalog_resolution']
assert resolution['complete'] and resolution['status'] == 'complete', 'No complete catalog read; do not report zero mappings'
assert resolution['resolver_blob_sha'] == 'cc135a95d2a6e0f73ce50be2141c9b8a26fddc58'
assert [r['anex_id'] for r in resolution['rows']] == scope['anex_ids']
assert len(resolution['rows']) == 48
assert resolution['accepted_source_count'] + resolution['unresolved_source_count'] == 48
baseline = json.loads((ROOT / 'published_baseline_minimal.json').read_text())
assert baseline['registry_sha256'] == scope['registry_baseline']['sha256']
assert len(baseline['rows']) == 604
published = defaultdict(list)
for item in baseline['rows']:
    if item['catalog_hotel_id'] is not None:
        published[item['catalog_hotel_id']].append(item)
native = receipt['current_native_posts']
native_complete = native.get('complete') is True
native_rows = native.get('rows', []) if native_complete else []
by_anex = {r['anex_id']: r for r in scope['anex_rows']}
records = []
for item in resolution['rows']:
    row = {**by_anex[item['anex_id']], 'mapping_status': item['status'],
           'catalog_hotel_id': item['catalog_hotel_id'], 'resolver_reason': item['reason'],
           'ready_for_publication': False, 'identity_bindings_written': 0}
    target = item['catalog_hotel_id']
    if target is None:
        row['classification'] = 'accepted_mapping_still_unresolved_requires_identity_work'
        row['confirmed_duplicate_of_published_604'] = False
    else:
        assert isinstance(target, int) and target > 0 and item['status'] == 'accepted_mapping'
        matches = published.get(target, [])
        posts = [p for p in native_rows if p['hotel_catalog_id'] == target and p['exact_native_metadata_match']]
        stable = [p for p in posts if p['state'] == 'sent' and not p['claim_present'] and p['source_active_and_manager_routed']]
        exact_baseline_pair = False
        if len(matches) == 1 and native_complete:
            wanted = {'max': matches[0]['max_url'], 'telegram': matches[0]['telegram_url']}
            exact_baseline_pair = len(stable) == 2 and len({p['platform'] for p in stable}) == 2 \
                and all(wanted.get(p['platform']) == p['post_url'] for p in stable)
        row['published_baseline_matches'] = matches
        row['current_native_posts'] = posts
        row['confirmed_duplicate_of_published_604'] = exact_baseline_pair
        if exact_baseline_pair:
            row['classification'] = 'confirmed_existing_published_pair_reconcile_duplicate_registry_row'
        elif matches:
            row['classification'] = 'accepted_mapping_to_published_baseline_current_pair_needs_clearance'
        elif posts:
            row['classification'] = 'accepted_mapping_with_existing_posts_needs_registry_reconciliation'
        else:
            row['classification'] = 'accepted_mapping_needs_materials_and_publication_clearance'
        row['same_number_or_name_inference_used'] = False
    records.append(row)

old = receipt['old_max_context']
old_rows = []
if old.get('complete') is True:
    assert {r['publication_id'] for r in old['rows']} == {58, 59, 61}
    for row in old['rows']:
        relation = row['relation']
        old_rows.append({'publication_id': row['publication_id'], 'post_url': row['post_url'],
                         'candidate_catalog_hotel_id': relation['candidate_catalog_hotel_id'],
                         'native_relation_proven': relation['native_relation_proven'],
                         'status': relation['status'],
                         'exact_button_resolves_publication': row['exact_button_resolves_publication'],
                         'button_observed_in_current_snapshot': row['button_observed_in_current_snapshot'],
                         'ready_for_new_post': False})

counts = Counter(r['classification'] for r in records)
output = {
    'schema': 1, 'operation': scope['operation'], 'read_observed_at_utc': receipt['observed_at_utc'],
    'read_finished_at_utc': receipt['finished_at_utc'], 'installed_sha': receipt['installed_sha'],
    'receipt_sha256': hashlib.sha256(raw).hexdigest(), 'scope_sha256': receipt['scope_sha256'],
    'catalog_resolution_complete': True, 'current_native_posts_complete': native_complete,
    'old_max_context_complete': old.get('complete') is True,
    'counts': {'anex_rows': 48, 'accepted_source_rows': resolution['accepted_source_count'],
               'unique_target_ids': resolution['unique_target_count'],
               'unresolved_source_rows': resolution['unresolved_source_count'],
               'confirmed_duplicates_of_published_604': sum(r['confirmed_duplicate_of_published_604'] for r in records),
               'classifications': dict(counts),
               'old_max_native_relations_proven': sum(r['native_relation_proven'] for r in old_rows) if old.get('complete') else None,
               'automatic_publication_readiness_claims': 0},
    'anex_rows': records, 'old_max_rows': old_rows,
    'limitations': ['No provider, price, model or customer data calls.',
                    'Accepted mapping resolves identity only; hotel materials and release holds still apply.',
                    'An absent native metadata match is not full name/alias duplicate clearance.',
                    'The other 11 remaining native-ID review rows are outside this resolver read.'],
    'external_mutations': 0,
}
args.output.parent.mkdir(parents=True, exist_ok=True)
args.output.write_text(json.dumps(output, ensure_ascii=False, indent=2) + '\n')
print(json.dumps(output['counts'], ensure_ascii=False))
