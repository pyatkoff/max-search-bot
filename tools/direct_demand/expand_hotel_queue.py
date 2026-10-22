#!/usr/bin/env python3
"""Merge reviewed observed hotel-name recipes and rebuild a separate demand queue.

Sources and the previous 87-hotel queue are never modified. No external calls.
All candidate identities remain unresolved until local catalog matching.
"""
from __future__ import annotations
import argparse,copy,hashlib,importlib.util,json,re
from collections import Counter
from datetime import datetime,timezone
from pathlib import Path


def sha(path):return hashlib.sha256(path.read_bytes()).hexdigest()


def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('--source',type=Path,required=True);p.add_argument('--inventory',type=Path,required=True)
    p.add_argument('--base-recipes',type=Path,required=True);p.add_argument('--additions',type=Path,nargs='+',required=True)
    p.add_argument('--output-recipes',type=Path,required=True);p.add_argument('--output-shortlist',type=Path,required=True)
    p.add_argument('--include-extras',action='store_true');p.add_argument('--top500-seed',type=Path)
    args=p.parse_args();base_sha=sha(args.base_recipes);source_sha=sha(args.source)
    spec=importlib.util.spec_from_file_location('hotel_analysis',Path(__file__).with_name('analyze_account_hotels.py'))
    m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
    source=json.loads(args.source.read_text());inventory=json.loads(args.inventory.read_text());base=json.loads(args.base_recipes.read_text())
    recipes=copy.deepcopy(base['recipes']);bykey={r['key']:r for r in recipes};basekeys=set(bykey)
    recipe_sources={r['key']:{'file':str(args.base_recipes),'sha256':base_sha,'baseline':True} for r in recipes}
    skipped=[]
    for path in args.additions:
        additions=json.loads(path.read_text());batch=additions['recipes']+(additions.get('extra_candidates',[]) if args.include_extras else [])
        for r in batch:
            key=r['key']
            if key in bykey:
                # A same-name addition is not silently merged with another identity.
                if bykey[key]['label']!=r['label'] or bykey[key]['observed_anchor_literals']!=r['observed_anchor_literals']:
                    raise ValueError('DUPLICATE_RECIPE_KEY_REQUIRES_REVIEW '+key)
                skipped.append({'key':key,'reason':'IDENTICAL_RECIPE_KEY'});continue
            r=copy.deepcopy(r);recipes.append(r);bykey[key]=r;recipe_sources[key]={'file':str(path),'sha256':sha(path),'baseline':False}
    groups={}
    for row in source['raw_rows']:
        q=m.norm(row['Query'])
        if q not in groups:groups[q]={'raw':row['Query'],'impressions':0,'clicks':0}
        for field in ('impressions','clicks'):
            n=m.number(row[field.capitalize()]);groups[q][field]+=int(n) if n is not None else 0
    validated=[]
    for r in recipes:
        anchors=r.get('observed_anchor_literals',[])
        if not anchors or any(not isinstance(a,str) or not a.strip() or m.norm(a)!=a for a in anchors):raise ValueError('INVALID_NORMALIZED_LITERAL '+r['key'])
        unobserved=[a for a in anchors if not any(a in q for q in groups)]
        if unobserved:raise ValueError('UNOBSERVED_LITERAL '+r['key']+' '+repr(unobserved))
        matches=[q for q in groups if any(a in q for a in anchors) and not any(x in q for x in r.get('excluded_observed_literals',[]))
                 and (not r.get('scope_regex') or re.search(r['scope_regex'],q))]
        if not matches:raise ValueError('NO_SCOPED_OBSERVATIONS '+r['key'])
        sample=max(matches,key=lambda q:(groups[q]['impressions'],groups[q]['clicks'],-len(q)))
        r['canonical_hotel_id']=None;r['identity_matching_required']=True;r['publishable']=False
        r['review_provenance']={**recipe_sources[r['key']],'source_query_file':str(args.source),'source_query_sha256':source_sha,
                                'example_normalized_query':sample,'raw_example':groups[sample]['raw'],'matched_normalized_queries_before_multi_hotel_exclusion':len(matches)}
        validated.append(r['key'])
    recipe_out={'schema':'anytour.direct.expanded_hotel_recipes.v1','generated_at_utc':datetime.now(timezone.utc).isoformat(),'base_recipe_file':str(args.base_recipes),'base_recipe_sha256':base_sha,
                'source_file':str(args.source),'source_file_sha256':source_sha,'baseline_count':len(basekeys),'expanded_count':len(recipes),'new_count':len(recipes)-len(basekeys),
                'policy':'Observed specific name candidates including zero-click queries. No local identity IDs, hotel property claims, chain-to-object inference or positional TOP500 mapping.',
                'skipped':skipped,'recipes':recipes}
    # Run the shared row-preserving analysis only after every new literal has source evidence.
    result=m.build(source,inventory,recipes)
    result['schema']='anytour.direct.expanded_hotel_shortlist.v1';result['source_file']=str(args.source);result['source_file_sha256']=source_sha
    result['baseline_hotel_count']=len(basekeys);result['new_hotel_candidate_count']=len(recipes)-len(basekeys)
    result['recipes_file']=str(args.output_recipes);result['candidate_identity_note']='All labels are observed name candidates. Every local identity, including the previous 87, still requires catalog matching.'
    result['raw_row_preservation']={'deduplicated':False,'policy':'Every native report row retained; source-row indices preserve duplicate API observations.',
                                   'source_duplicate_annotations':{k:v for k,v in source.items() if 'duplicat' in k.lower()}}
    for c in result['candidates']:
        r=bykey[c['demand_candidate_key']];c['expansion_status']='BASELINE_87' if c['demand_candidate_key'] in basekeys else 'NEW_OBSERVED_NAME'
        c['review_provenance']=r['review_provenance'];c['provided_manual_provenance']=r.get('provenance')
        c['has_clicked_observations']=c['clicks_known_sum']>0
        c['priority_basis']='AD_IMPRESSIONS_AND_CLICKS_IN_THIS_ACCOUNT_NOT_YANDEX_MONTHLY_QUERY_FREQUENCY'
    top500=[]
    if args.top500_seed:
        seed=json.loads(args.top500_seed.read_text());names=seed.get('ranked_name_rows',[])
        for c in result['candidates']:
            # Lexical evidence only; no positional join with the independent legacy ID list.
            aliases=[q['normalized_query'] for q in c['query_aliases']]
            matching=[{'hotel':n['hotel'],'country':n.get('country'),'rank':n.get('rank'),'match_method':'EXACT_NORMALIZED_NAME_SUBSTRING_IN_OBSERVED_QUERY_NOT_IDENTITY_CONFIRMATION'}
                      for n in names if len(m.norm(n.get('hotel','')))>5 and any(m.norm(n['hotel']) in q for q in aliases)]
            if matching:
                c['top500_name_evidence']=matching;top500.append(c['demand_candidate_key'])
        result['top500_name_evidence_scope']={'seed_file':str(args.top500_seed),'sha256':sha(args.top500_seed),'candidate_count_with_lexical_evidence':len(top500),
                                            'hotel_id_join_performed':False,'note':'Names only. Ranked-name rows are never paired to the independent 492-ID list by position.'}
    # All remaining hotel-like queries are visible, including zero-click demand; no generic city/chain becomes an object.
    used={index for c in result['candidates'] for q in c['query_aliases'] for index in q['source_row_indices_0based']}
    unclassified=[]
    for q,metrics in groups.items():
        if not m.HOTEL_RE.search(q):continue
        # Compiled candidate matching, rather than source membership, keeps known multi-hotel comparisons out of the residual object queue.
        if any(any(a in q for a in r['observed_anchor_literals']) and not any(e in q for e in r.get('excluded_observed_literals',[])) and (not r.get('scope_regex') or re.search(r['scope_regex'],q)) for r in recipes):continue
        unclassified.append({'normalized_query':q,'raw_example':metrics['raw'],'impressions':metrics['impressions'],'clicks':metrics['clicks'],'kind':'UNRESOLVED_HOTEL_LIKE_TEXT_NOT_A_CATALOG_OBJECT'})
    unclassified.sort(key=lambda q:(-q['impressions'],-q['clicks'],q['normalized_query']))
    result['remaining_unclassified_hotel_like_query_count_including_zero_clicks']=len(unclassified)
    result['remaining_unclassified_hotel_like_examples_by_impressions']=unclassified[:300]
    result['expansion_summary']={'candidate_count':len(recipes),'baseline_count':len(basekeys),'new_count':len(recipes)-len(basekeys),
                                 'with_clicks':sum(c['clicks_known_sum']>0 for c in result['candidates']),'without_clicks':sum(c['clicks_known_sum']==0 for c in result['candidates']),
                                 'new_without_clicks':sum(c['clicks_known_sum']==0 and c['expansion_status']=='NEW_OBSERVED_NAME' for c in result['candidates']),
                                 'impressions_all_candidates':result['selected_metrics']['impressions_known_sum'],'clicks_all_candidates':result['selected_metrics']['clicks_known_sum']}
    assert not result['overlapping_source_memberships']
    assert sum(c['source_rows'] for c in result['candidates'])==result['unique_selected_source_rows']
    assert all(c['canonical_hotel_id'] is None for c in result['candidates'])
    assert sha(args.base_recipes)==base_sha
    args.output_recipes.write_text(json.dumps(recipe_out,ensure_ascii=False,indent=2)+'\n')
    args.output_shortlist.write_text(json.dumps(result,ensure_ascii=False,separators=(',',':'))+'\n')
    print(json.dumps({'output_shortlist':str(args.output_shortlist),'status':result['status'],**result['expansion_summary'],
                      'multi_hotel_comparison_query_count':len(result['multi_candidate_comparison_queries_excluded_from_individual_hotel_metrics']),
                      'top500_name_evidence_candidates':len(top500)},ensure_ascii=False))

if __name__=='__main__':main()
