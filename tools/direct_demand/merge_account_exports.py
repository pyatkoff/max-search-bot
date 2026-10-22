#!/usr/bin/env python3
"""Replace an incomplete batch with a complete, disjoint campaign export."""
from collections import Counter
import hashlib
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parent


def read(name):
    path = ROOT / name
    return json.loads(path.read_text()), hashlib.sha256(path.read_bytes()).hexdigest()


def main():
    first, first_sha = read('account_queries_partial_20261022.json')
    repair, repair_sha = read('account_queries_repair_20261022.json')
    single, single_sha = read('account_queries_single_20261022.json')
    inventory, inventory_sha = read('account_inventory_20261022.json')
    assert first['stream_validated'] and repair['stream_validated'] and single['stream_validated']
    assert repair['repair_of']['parent_report_sha256'] == first_sha
    assert first['config_unchanged'] and repair['config_unchanged'] and single['config_unchanged']
    assert single['status'] == 'COLLECTED' and single['coverage']['pagination_complete']
    assert single['repair_of']['parent_report_sha256'] == repair_sha
    for key in ('client_login', 'date1', 'date2', 'query_network_type', 'report_type', 'cost_basis'):
        assert first[key] == repair[key], key
        assert first[key] == single[key], key
    keep_batches = [b for b in first['batches'] if b['complete']]
    keep_ids = {int(cid) for b in keep_batches for cid in b['campaign_ids']}
    replacement_ids = set(repair['campaign_ids'])
    failed_ids = {int(cid) for b in first['batches'] if not b['complete'] for cid in b['campaign_ids']}
    assert replacement_ids == failed_ids
    assert not keep_ids.intersection(replacement_ids)
    repair_batches = [b for b in repair['batches'] if b['complete']]
    repair_ids = {int(cid) for b in repair_batches for cid in b['campaign_ids']}
    single_ids = set(single['campaign_ids'])
    assert single_ids == {715091398} == {int(cid) for b in repair['batches'] if not b['complete'] for cid in b['campaign_ids']}
    assert repair_ids | single_ids == replacement_ids and not repair_ids.intersection(single_ids)
    assert len(single['batches']) == 1 and single['batches'][0]['complete']
    single_pages = single['batches'][0]['pages']
    assert len(single_pages) == 1 and single_pages[0]['offset'] == 0
    assert single_pages[0]['rows'] == single['row_count'] < single['page_size']
    expected = set(inventory['campaign_ids_with_search_impressions'])
    assert keep_ids | replacement_ids == expected == set(first['campaign_ids'])
    clean_rows = [r for r in first['raw_rows'] if int(r['CampaignId']) in keep_ids] + [r for r in repair['raw_rows'] if int(r['CampaignId']) in repair_ids]
    rows = clean_rows + single['raw_rows']
    assert len(rows) == sum(b['rows'] for b in keep_batches + repair_batches) + single['row_count']
    fields = ('Date', 'CampaignId', 'AdGroupId', 'AdId', 'Query')
    keys = [tuple(r[k] for k in fields) for r in rows]
    clean_keys = [tuple(r[k] for k in fields) for r in clean_rows]
    assert len(set(clean_keys)) == len(clean_keys), 'duplicate composite key in paginated sources'
    duplicate_keys = {key:count for key,count in Counter(keys).items() if count > 1}
    assert all(int(key[1]) in single_ids for key in duplicate_keys)
    assert all(int(r['CampaignId']) in expected and first['date1'] <= r['Date'] <= first['date2'] for r in rows)
    counts = Counter(int(r['CampaignId']) for r in rows)
    for phase in ('config_before', 'config_after'):
        old_config = {c['Id']: c for c in first[phase]['campaigns']}
        new_config = {c['Id']: c for c in repair[phase]['campaigns']}
        for cid in replacement_ids:
            assert old_config.get(cid) == new_config.get(cid), f'configuration changed: {cid}'
        for c in single[phase]['campaigns']:
            assert old_config.get(c['Id']) == c, f'configuration changed: {c["Id"]}'
    source_artifacts = json.loads((ROOT / 'account_query_artifact.json').read_text())
    result = {k:v for k,v in first.items() if k not in ('raw_rows', 'coverage', 'batches', 'problems', 'row_count')}
    result.update({'status':'COLLECTED', 'row_count':len(rows), 'raw_rows':rows, 'problems':[],
                   'finished_at_utc':single['finished_at_utc'], 'source_segments':source_artifacts,
                   'merge_method':'COMPLETE_CAMPAIGN_BATCHES_PLUS_FULL_REPLACEMENT_OF_INCOMPLETE_CAMPAIGN_BATCH',
                   'source_sha256':{'first':first_sha, 'repair':repair_sha, 'single':single_sha, 'inventory':inventory_sha},
                   'replaced_incomplete_batches':[b for s in (first,repair) for b in s['batches'] if not b['complete']],
                   'native_visible_key_duplicates':{'campaign_ids':sorted(single_ids),'groups':len(duplicate_keys),'excess_rows':len(rows)-len(set(keys)),
                        'method':'ALL_NATIVE_API_ROWS_PRESERVED_FROM_ONE_UNPAGINATED_RESPONSE; NO_DEDUPLICATION',
                        'details':[{'dimensions':dict(zip(fields,key)),'native_row_count':count} for key,count in duplicate_keys.items()]},
                   'batches':[{**b,'source':label,'source_batch_index':b['batch_index'],'batch_index':i} for i,(label,b) in enumerate(
                       [('first',b) for b in keep_batches]+[('repair',b) for b in repair_batches]+[('single',b) for b in single['batches']])]})
    result['coverage'] = {'scope':'ALL_INVENTORY_DISCOVERED_SEARCH_ACTIVITY_CAMPAIGNS',
                           'pagination_complete':True, 'data_complete':True, 'all_market_queries':False,
                           'pagination_not_atomic_snapshot':True, 'requested_campaign_ids':sorted(expected),
                           'campaigns':[{'campaign_id':cid,'rows':counts[cid],'complete':True,
                                         'source':'first_complete_batches' if cid in keep_ids else 'repair_complete_export' if cid in repair_ids else 'single_native_response'} for cid in sorted(expected)],
                           'batches':result['batches'], 'raw_row_count':len(rows), 'unique_row_keys':len(set(keys))}
    (ROOT / 'account_queries_20261022.json').write_text(json.dumps(result, ensure_ascii=False, separators=(',',':'))+'\n')
    print(json.dumps({'campaigns':len(expected), 'rows':len(rows), 'kept_campaigns':len(keep_ids), 'repaired_campaigns':len(replacement_ids)}))


if __name__ == '__main__':
    main()
