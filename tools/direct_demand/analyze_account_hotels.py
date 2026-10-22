#!/usr/bin/env python3
"""Build a conservative hotel-demand queue from a read-only account query export.

Only observed literal aliases are matched. Campaign lifecycle is the current
configuration snapshot, not its historical state on every impression date.
No external requests, publications or writes to the source export occur.
"""
from __future__ import annotations
import argparse,ast,hashlib,json,re,unicodedata
from collections import Counter,defaultdict
from datetime import date,datetime,timezone
from decimal import Decimal,InvalidOperation
from pathlib import Path

LIFECYCLES=('ACTIVE','ARCHIVED','INACTIVE','UNKNOWN')
HOTEL_RE=re.compile(r'\b(?:hotel|hotels|resort|residence|palace|grand|beach|spa|inn|sunrise|rixos|jaz|pickalbatros|albatros|barut|delphin|titanic|amiana|alma|lugano|sorgun|swissotel|movenpick|sheraton|steigenberger|ramada|wyndham|hilton|centara|novotel)\b|отел|санрайз|риксос|альбатрос|свандор|барут|джаз фанара|конкорд|ремал|макади',re.I)


def norm(value):
    value=unicodedata.normalize('NFKC',str(value)).casefold().replace('ё','е')
    return ' '.join(re.sub(r'[^\w\s]',' ',value,flags=re.UNICODE).split())


def number(value):
    if value is None or str(value).strip() in {'','--','—','null','None'}:return None
    try:
        x=Decimal(str(value).strip().replace(',','.'))
        return x if x.is_finite() else None
    except InvalidOperation:return None


def stats(rows):
    result={'source_rows':len(rows)}
    for field in ('impressions','clicks','cost'):
        values=[r[field] for r in rows];known=[x for x in values if x is not None]
        cast=(lambda x:round(float(x),2)) if field=='cost' else int
        result[field]=cast(sum(known,Decimal(0))) if rows and len(values)==len(known) else None
        result[field+'_known_sum']=cast(sum(known,Decimal(0)))
        result[field+'_missing_rows']=len(values)-len(known)
    return result


def state(record):
    s=str(record.get('State',record.get('state','UNKNOWN'))).upper()
    if s=='ON':return 'ACTIVE'
    if s=='ARCHIVED':return 'ARCHIVED'
    if s in {'OFF','SUSPENDED','ENDED','CONVERTED'}:return 'INACTIVE'
    return 'UNKNOWN'


def find_list(obj,paths):
    for path in paths:
        value=obj
        for key in path.split('.'):
            value=value.get(key) if isinstance(value,dict) else None
        if isinstance(value,list):return value
    return []


def config_records(source):
    return find_list(source,('config_before.campaigns','campaign_inventory','inventory.campaigns','config_after.campaigns','campaigns'))


def metadata(source,inventory):
    out={}
    # Explicit source campaign lookup is freshest; unknown placeholders never erase known states.
    for root in (inventory or {},source):
        records=config_records(root)
        if isinstance(root.get('campaign_config_by_id'),dict):
            records=records+list(root['campaign_config_by_id'].values())
        for c in records:
            if not isinstance(c,dict):continue
            cid=c.get('Id',c.get('CampaignId',c.get('id')))
            if cid is None:continue
            cid=str(cid)
            if cid in out and state(c)=='UNKNOWN' and state(out[cid])!='UNKNOWN':continue
            out[cid]=c
    return out


def load_recipes(path,seed):
    if path and path.exists():return json.loads(path.read_text())['recipes']
    tree=ast.parse(seed.read_text())
    defs=next(ast.literal_eval(n.value) for n in tree.body if isinstance(n,ast.Assign) and any(isinstance(t,ast.Name) and t.id=='DEFS' for t in n.targets))
    return [{'key':key,'label':label,'observed_anchor_literals':anchors,'excluded_observed_literals':exclude,'note':note,
             'scope_regex':r'турци|алани|аланья|тюрклер' if key=='long_beach_turkey' else None} for key,label,anchors,exclude,note in defs]


def build(source,inventory,recipes):
    raw=find_list(source,('raw_rows','query_rows','search_query_rows'));meta=metadata(source,inventory)
    requested={str(x) for x in source.get('campaign_ids',[])}
    groups=defaultdict(list);rejected=[];network_excluded=Counter();cache={}
    collector_network=source.get('query_network_type',source.get('coverage',{}).get('query_network_type'))
    for index,row in enumerate(raw):
        if not isinstance(row,dict):rejected.append({'index':index,'reason':'NOT_OBJECT'});continue
        cid=row.get('CampaignId',row.get('campaign_id'));query=row.get('Query',row.get('query'));day=row.get('Date',row.get('date'))
        if cid is None or not isinstance(query,str) or not query.strip():rejected.append({'index':index,'reason':'MISSING_CAMPAIGN_QUERY'});continue
        try:date.fromisoformat(str(day))
        except (ValueError,TypeError):rejected.append({'index':index,'reason':'INVALID_DATE'});continue
        net=str(row.get('AdNetworkType',row.get('NetworkType',row.get('network_type',collector_network))) or 'UNKNOWN').upper()
        if net!='SEARCH':network_excluded[net]+=1;continue
        cid=str(cid);requested.add(cid)
        if query not in cache:cache[query]=norm(query)
        normalized=cache[query]
        if not normalized:rejected.append({'index':index,'reason':'EMPTY_NORMALIZED_QUERY'});continue
        record=meta.get(cid,{})
        groups[normalized].append({'source_row_index_0based':index,'campaign_id':cid,'lifecycle':state(record),'query':query,'normalized_query':normalized,'date':str(day),
                                   **{f:number(row.get(f.capitalize(),row.get(f))) for f in ('impressions','clicks','cost')}})
    flat=[r for rows in groups.values() for r in rows]
    compiled=[]
    for recipe in recipes:
        anchors=recipe['observed_anchor_literals']
        unobserved=[x for x in anchors if not any(x in key for key in groups)]
        pattern='(?:'+'|'.join(re.escape(x) for x in anchors)+')'
        compiled.append((recipe,re.compile(pattern),unobserved))
    memberships=defaultdict(list);candidate_rows={};candidate_groups={};exclusions={}
    for recipe,pattern,_ in compiled:
        key=recipe['key'];selected=[];qgroups={};excluded=[]
        for q,rows in groups.items():
            if not pattern.search(q):continue
            blocked=any(e in q for e in recipe.get('excluded_observed_literals',[]))
            if recipe.get('scope_regex') and not re.search(recipe['scope_regex'],q):blocked=True
            if blocked:excluded.extend(rows);continue
            selected.extend(rows);qgroups[q]=rows
            for r in rows:memberships[r['source_row_index_0based']].append(key)
        candidate_rows[key]=selected;candidate_groups[key]=qgroups;exclusions[key]=excluded
    # An observed comparison/pasted list is not attributable to a single hotel.
    # Keep it separately rather than counting its clicks for every named hotel.
    conflicted={index:keys for index,keys in memberships.items() if len(keys)>1}
    conflicted_indices=set(conflicted)
    if conflicted:
        for recipe,_,_ in compiled:
            key=recipe['key']
            candidate_rows[key]=[r for r in candidate_rows[key] if r['source_row_index_0based'] not in conflicted_indices]
            candidate_groups[key]={q:[r for r in rr if r['source_row_index_0based'] not in conflicted_indices]
                                   for q,rr in candidate_groups[key].items()
                                   if any(r['source_row_index_0based'] not in conflicted_indices for r in rr)}
        for index in conflicted_indices:del memberships[index]
    def summarize(rows):
        return {**stats(rows),'unique_normalized_queries':len({r['normalized_query'] for r in rows}),'campaign_ids':sorted({r['campaign_id'] for r in rows}),
                'metrics_by_lifecycle':{s:stats([r for r in rows if r['lifecycle']==s]) for s in LIFECYCLES}}
    def evidence(q,rows):
        return {'normalized_query':q,'raw_aliases':sorted({r['query'] for r in rows}),'source_row_indices_0based':[r['source_row_index_0based'] for r in rows],**summarize(rows)}
    def rank(x):
        active=x['metrics_by_lifecycle']['ACTIVE']
        return (-active['clicks_known_sum'],-x['clicks_known_sum'],-active['impressions_known_sum'],-x['impressions_known_sum'],x.get('label',x.get('normalized_query','')))
    candidates=[]
    for recipe,pattern,unobserved in compiled:
        key=recipe['key'];rows=candidate_rows[key]
        aliases=[evidence(q,rr) for q,rr in candidate_groups[key].items()];aliases.sort(key=rank)
        candidates.append({'demand_candidate_key':key,'label':recipe['label'],'kind':'hotel_name_demand_candidate','canonical_hotel_id':None,'local_identity_matching_required':True,'publishable':False,
                           'observed_anchor_literals':recipe['observed_anchor_literals'],'candidate_regex_normalized':pattern.pattern,'excluded_observed_literals':recipe.get('excluded_observed_literals',[]),
                           'scope_regex':recipe.get('scope_regex'),'identity_note':recipe.get('note'),'anchors_not_present_in_this_export':unobserved,
                           **summarize(rows),'metrics_by_campaign':{cid:{'lifecycle':state(meta.get(cid,{})),**stats([r for r in rows if r['campaign_id']==cid])} for cid in sorted({r['campaign_id'] for r in rows})},
                           'query_aliases':aliases,'excluded_source_rows':len(exclusions[key]),'excluded_query_examples':sorted({r['query'] for r in exclusions[key]})[:8]})
    candidates.sort(key=rank)
    unmatched=[]
    for q,rows in groups.items():
        if any(r['source_row_index_0based'] in conflicted_indices for r in rows):continue
        if any(r['source_row_index_0based'] in memberships for r in rows):continue
        if not any(r['clicks'] is not None and r['clicks']>0 for r in rows) or not HOTEL_RE.search(q):continue
        unmatched.append(evidence(q,rows))
    unmatched.sort(key=rank)
    brand_patterns={'rixos':r'\brixos\b|риксос','pickalbatros':r'pickalbatros|пикальбатрос|альбатрос','sunrise':r'\bsunrise\b|санрайз','jaz':r'\bjaz\b|\bjas\b|джаз фанара','barut':r'\bbarut\b|барут','delphin':r'\bdelphin\b|дельфин'}
    brands=[]
    for key,pattern in brand_patterns.items():
        rr=[r for q,group in groups.items() if re.search(pattern,q) for r in group
            if r['source_row_index_0based'] not in memberships and r['source_row_index_0based'] not in conflicted_indices]
        if rr:
            brand_groups=defaultdict(list)
            for r in rr:brand_groups[r['normalized_query']].append(r)
            brand_examples=[evidence(q,group) for q,group in brand_groups.items()]
            brand_examples.sort(key=rank)
            brands.append({'brand_demand_key':key,'kind':'UNCLASSIFIED_BRAND_OR_HOTEL_DEMAND','brand_assignment_unverified':True,'regex':pattern,**summarize(rr),
                           'examples':[q['raw_aliases'][0] for q in brand_examples[:15]],'query_evidence':brand_examples[:50]})
    brands.sort(key=rank)
    duplicates={str(i):keys for i,keys in memberships.items() if len(keys)>1}
    comparisons=[{'matched_demand_candidate_keys':sorted({key for r in rr for key in conflicted.get(r['source_row_index_0based'],[])}),
                  **evidence(q,rr)} for q,rr in groups.items()
                 if any(r['source_row_index_0based'] in conflicted_indices for r in rr)]
    comparisons.sort(key=rank)
    unique_selected=[r for r in flat if r['source_row_index_0based'] in memberships]
    return {'schema':'anytour.direct.account_hotel_shortlist.v1','generated_at_utc':datetime.now(timezone.utc).isoformat(),'period':{'from':source.get('date1'),'to':source.get('date2'),
            'includes_current_calendar_day':source.get('coverage',{}).get('includes_current_calendar_day'),
            'calendar_day_note':'Current-day completeness is not inferred from execution-host date; use campaign timezone and source collection timestamp.'},
            'status':'COLLECTED' if source.get('stream_validated') is True and source.get('coverage',{}).get('pagination_complete') is True and not rejected and not duplicates else 'PARTIAL',
            'scope':'SEARCH query rows only; explicit per-row network or collector query_network_type assertion required. Direct-exposed account queries, not all market demand.',
            'lifecycle_note':'ACTIVE = current configuration State ON; ARCHIVED = ARCHIVED; INACTIVE = OFF/SUSPENDED/ENDED/CONVERTED; missing/other = UNKNOWN. Not a historical campaign state per impression date.',
            'metrics_note':'Cost in account currency excluding VAT as reported; no conversion or property claims. Zero reported cost does not establish free acquisition. Empty lifecycle groups use null totals with known_sum=0.',
            'cost_basis':source.get('cost_basis'),'coverage':{'source_status':source.get('status'),'stream_validated':source.get('stream_validated'),'source_row_count':len(raw),'included_search_row_count':len(flat),'network_excluded_rows':dict(network_excluded),'rejected_rows':rejected,
                      'requested_campaign_count':len(requested),'campaign_ids':sorted(requested),'by_lifecycle':{s:{'campaign_ids':sorted(cid for cid in requested if state(meta.get(cid,{}))==s),**stats([r for r in flat if r['lifecycle']==s])} for s in LIFECYCLES},
                      'source_configuration_coverage':source.get('configuration_coverage'),'campaign_metadata_missing_ids':sorted(cid for cid in requested if not meta.get(cid) or state(meta[cid])=='UNKNOWN')},
            'hotel_candidate_count':len(candidates),'unique_selected_source_rows':len(unique_selected),'overlapping_source_memberships':duplicates,'selected_metrics':summarize(unique_selected),'candidates':candidates,
            'multi_candidate_comparison_queries_excluded_from_individual_hotel_metrics':comparisons,
            'unclassified_brand_demand':brands,'promising_unclassified_hotel_queries':unmatched[:500],'unclassified_hotel_like_clicked_query_count':len(unmatched),'unclassified_display_limit':500,
            'campaign_lifecycle':{cid:{'state':meta.get(cid,{}).get('State'),'lifecycle':state(meta.get(cid,{})),'name':meta.get(cid,{}).get('Name'),'currency':meta.get(cid,{}).get('Currency')} for cid in sorted(requested)}}


def main():
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('--source',type=Path,required=True);p.add_argument('--inventory',type=Path);p.add_argument('--recipes',type=Path);p.add_argument('--seed-builder',type=Path,default=Path(__file__).with_name('build_hotel_shortlist.py'));p.add_argument('--output',type=Path,required=True)
    args=p.parse_args();source=json.loads(args.source.read_text());inventory=json.loads(args.inventory.read_text()) if args.inventory else None
    recipes=load_recipes(args.recipes,args.seed_builder);result=build(source,inventory,recipes)
    result['source_file']=str(args.source.resolve());result['source_file_sha256']=hashlib.sha256(args.source.read_bytes()).hexdigest();result['recipe_count']=len(recipes)
    result['raw_row_preservation']={'deduplicated':False,'policy':'Every input row contributes separately; native visible-key duplicates are preserved. Sets only compact textual alias lists.',
                                    'source_duplicate_annotations':{key:value for key,value in source.items() if 'duplicat' in key.lower()}}
    args.output.write_text(json.dumps(result,ensure_ascii=False,separators=(',',':'))+'\n')
    print(json.dumps({'output':str(args.output),'status':result['status'],'campaign_count':result['coverage']['requested_campaign_count'],'search_rows':result['coverage']['included_search_row_count'],'hotel_candidates':result['hotel_candidate_count'],'selected_rows':result['unique_selected_source_rows'],'unclassified_hotel_like_clicked_query_count':result['unclassified_hotel_like_clicked_query_count'],'lifecycle_campaigns':{s:len(result['coverage']['by_lifecycle'][s]['campaign_ids']) for s in LIFECYCLES}},ensure_ascii=False))

if __name__=='__main__':main()
