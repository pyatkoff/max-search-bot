#!/usr/bin/env python3
"""Build the completed account analysis and update its concise summary once."""
import hashlib
import json
from pathlib import Path
import resource
import time
from analyze_account_demand import build, render

ROOT=Path(__file__).resolve().parent
SOURCE=ROOT/'account_queries_20261022.json'
INVENTORY=ROOT/'account_inventory_20261022.json'
OUT=ROOT/'account_analysis'


def merge_metrics(items):
    result={'rows':sum(x['rows'] for x in items)}
    for key in ('impressions','clicks','cost'):
        known=sum(x[key+'_known_sum'] for x in items)
        missing=sum(x[key+'_missing_rows'] for x in items)
        result[key+'_known_sum']=round(known,2) if key=='cost' else known
        result[key+'_missing_rows']=missing
        result[key]=None if missing or not items else result[key+'_known_sum']
    return result


def main():
    started=time.perf_counter()
    source=json.loads(SOURCE.read_text(encoding='utf-8'))
    if source.get('status')!='COLLECTED' or source.get('coverage',{}).get('pagination_complete') is not True:
        raise SystemExit('SOURCE_NOT_COMPLETE: final outputs were not replaced')
    inventory=json.loads(INVENTORY.read_text(encoding='utf-8'))
    feed=json.loads((ROOT/'current_feed_summary.json').read_text(encoding='utf-8'))['collections']
    result=build(source,inventory,feed)
    if result['ranking_status'].startswith('PROVISIONAL'):
        raise SystemExit('ANALYSIS_COVERAGE_NOT_COMPLETE: final outputs were not replaced')
    result['source_sha256']=hashlib.sha256(SOURCE.read_bytes()).hexdigest()
    result['inventory_sha256']=hashlib.sha256(INVENTORY.read_bytes()).hexdigest()
    OUT.mkdir(exist_ok=True)
    with (OUT/'account-topic-candidates.json').open('w',encoding='utf-8') as target:
        json.dump(result,target,ensure_ascii=False,separators=(',',':'),allow_nan=False)
        target.write('\n')
    (OUT/'account-demand.html').write_text(render(result),encoding='utf-8')
    summary=json.loads((ROOT/'account_inventory_summary.json').read_text(encoding='utf-8'))
    cov=result['coverage']; broad=result['broad_search_rankings']; metrics=result['broad_search_metrics']
    summary['query_export']={'status':source['status'],'ranking_status':result['ranking_status'],
        'report_path':str(SOURCE),'analysis_json_path':str(OUT/'account-topic-candidates.json'),
        'html_path':str(OUT/'account-demand.html'),'source_sha256':result['source_sha256'],
        'rows':cov['valid_query_rows'],'unique_normalized_queries':broad['query_count_before_display_limit'],
        'requested_campaigns':len(cov['requested_query_campaign_ids']),
        'complete_campaigns':len(cov['complete_query_campaign_ids']),
        'required_search_activity_campaigns':len(cov['required_search_activity_campaign_ids']),
        'missing_search_campaign_ids':cov['search_activity_ids_not_complete'],
        'actual_day_count':len(cov['actual_query_days']),
        'actual_date_min':cov['actual_query_days'][0] if cov['actual_query_days'] else None,
        'actual_date_max':cov['actual_query_days'][-1] if cov['actual_query_days'] else None,
        'broad_search_metrics':metrics,'unknown_search_campaigns':len(result['segments']['UNKNOWN']['SEARCH']['query_campaign_ids']),
        'scope_note':'All exposed query rows from every campaign with detected search activity; current, archived, inactive and UNKNOWN metadata remain separate.'}
    old_ids=summary.get('previous_two_campaigns',{}).get('campaign_ids',[])
    old_metrics=merge_metrics([result['by_campaign'][cid]['query_metrics_by_network']['SEARCH'] for cid in old_ids if cid in result['by_campaign']])
    old_share=None if metrics['clicks'] in (None,0) or old_metrics['clicks'] is None else round(old_metrics['clicks']/metrics['clicks']*100,4)
    summary['previous_two_campaigns']['exposed_query_metrics']=old_metrics
    summary['previous_two_campaigns']['exposed_query_click_share_percent']=old_share
    summary['previous_two_campaigns']['exposed_query_share_basis']='SEARCH_QUERY_PERFORMANCE_REPORT exposed rows; separate from earlier account performance share. Sources may differ in collection time and query disclosure.'
    def concise(item, label):
        return {label:item.get(label), 'key':item['key'],'metrics':item['metrics'],
            'clicks_by_lifecycle':{state:values['clicks'] for state,values in item.get('metrics_by_lifecycle',{}).items()},
            'campaign_count':len(item.get('campaign_ids',[])),
            **({key:item[key] for key in ('kind','query_examples','intent_review_flags') if key in item})}
    summary['top_search_countries']=[concise(x,'country') for x in broad['countries'][:20]]
    summary['top_search_topics']=[concise(x,'topic') for x in broad['topics'][:25]]
    summary['top_current_on_topics']=[concise(x,'topic') for x in result['current_search_rankings']['topics'][:15]]
    summary['top_unknown_state_topics']=[concise(x,'topic') for x in result['unknown_search_rankings']['topics'][:15]]
    footprint={'input':SOURCE.name,'status':'COLLECTED','wall_seconds':round(time.perf_counter()-started,2),
        'peak_rss_mib':round(resource.getrusage(resource.RUSAGE_SELF).ru_maxrss/1024,2),
        'output_json_bytes':(OUT/'account-topic-candidates.json').stat().st_size,
        'output_html_bytes':(OUT/'account-demand.html').stat().st_size}
    summary.setdefault('builder_footprint',{})['measured_actual_full']=footprint
    temp=ROOT/'account_inventory_summary.json.tmp'
    temp.write_text(json.dumps(summary,ensure_ascii=False,indent=2,allow_nan=False)+'\n',encoding='utf-8')
    temp.replace(ROOT/'account_inventory_summary.json')
    (ROOT/'builder_actual_full_footprint.json').write_text(json.dumps(footprint,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
    print(json.dumps({'ranking_status':result['ranking_status'],'rows':cov['valid_query_rows'],
        'unique_queries':broad['query_count_before_display_limit'],'complete_campaigns':len(cov['complete_query_campaign_ids']),
        'broad_search_clicks':metrics['clicks'],'old_two_query_click_share_percent':old_share,
        'top_countries':[(x['country'],x['metrics']['clicks']) for x in broad['countries'][:8]],
        'top_topics':[(x['topic'],x['metrics']['clicks']) for x in broad['topics'][:8]],
        'footprint':footprint},ensure_ascii=False))


if __name__=='__main__':
    main()
