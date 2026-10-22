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
    inventory, inventory_sha = read('account_inventory_20261022.json')
    assert first['stream_validated'] and repair['stream_validated']
    assert first['config_unchanged'] and repair['config_unchanged']
    assert repair['status'] == 'COLLECTED' and repair['coverage']['pagination_complete']
    for key in ('client_login', 'date1', 'date2', 'query_network_type', 'report_type', 'cost_basis'):
        assert first[key] == repair[key], key
    keep_batches = [b for b in first['batches'] if b['complete']]
    keep_ids = {int(cid) for b in keep_batches for cid in b['campaign_ids']}
    replacement_ids = set(repair['campaign_ids'])
    assert not keep_ids.intersection(replacement_ids)
    expected = set(inventory['campaign_ids_with_search_impressions'])
    assert keep_ids | replacement_ids == expected == set(first['campaign_ids'])
    rows = [r for r in first['raw_rows'] if int(r['CampaignId']) in keep_ids] + repair['raw_rows']
    assert len(rows) == sum(b['rows'] for b in keep_batches) + repair['row_count']
    fields = ('Date', 'CampaignId', 'AdGroupId', 'AdId', 'Query')
    keys = [tuple(r[k] for k in fields) for r in rows]
    assert len(set(keys)) == len(keys), 'duplicate composite key'
    assert all(int(r['CampaignId']) in expected and first['date1'] <= r['Date'] <= first['date2'] for r in rows)
    counts = Counter(int(r['CampaignId']) for r in rows)
    for phase in ('config_before', 'config_after'):
        old_config = {c['Id']: c for c in first[phase]['campaigns']}
        new_config = {c['Id']: c for c in repair[phase]['campaigns']}
        for cid in replacement_ids:
            assert old_config.get(cid) == new_config.get(cid), f'configuration changed: {cid}'
    source_artifacts = json.loads((ROOT / 'account_query_artifact.json').read_text())
    result = {k:v for k,v in first.items() if k not in ('raw_rows', 'coverage', 'batches', 'problems', 'row_count')}
    result.update({'status':'COLLECTED', 'row_count':len(rows), 'raw_rows':rows, 'problems':[],
                   'finished_at_utc':repair['finished_at_utc'], 'source_segments':source_artifacts,
                   'merge_method':'COMPLETE_CAMPAIGN_BATCHES_PLUS_FULL_REPLACEMENT_OF_INCOMPLETE_CAMPAIGN_BATCH',
                   'source_sha256':{'first':first_sha, 'repair':repair_sha, 'inventory':inventory_sha},
                   'replaced_incomplete_batches':[b for b in first['batches'] if not b['complete']],
                   'batches':keep_batches + [{**b, 'source':'repair', 'batch_index':i+len(keep_batches)} for i,b in enumerate(repair['batches'])]})
    result['coverage'] = {'scope':'ALL_INVENTORY_DISCOVERED_SEARCH_ACTIVITY_CAMPAIGNS',
                           'pagination_complete':True, 'data_complete':True, 'all_market_queries':False,
                           'pagination_not_atomic_snapshot':True, 'requested_campaign_ids':sorted(expected),
                           'campaigns':[{'campaign_id':cid,'rows':counts[cid],'complete':True,
                                         'source':'first_complete_batches' if cid in keep_ids else 'repair_complete_export'} for cid in sorted(expected)],
                           'batches':result['batches'], 'raw_row_count':len(rows), 'unique_row_keys':len(set(keys))}
    (ROOT / 'account_queries_20261022.json').write_text(json.dumps(result, ensure_ascii=False, separators=(',',':'))+'\n')
    print(json.dumps({'campaigns':len(expected), 'rows':len(rows), 'kept_campaigns':len(keep_ids), 'repaired_campaigns':len(replacement_ids)}))


if __name__ == '__main__':
    main()
