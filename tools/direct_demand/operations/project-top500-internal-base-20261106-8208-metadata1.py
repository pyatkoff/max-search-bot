import copy,hashlib,json,os,pathlib,urllib.parse
OP='commercial-top500-internal-base-20261106-8208-original-metadata1'
IDS=[22879,8520,8208,12575,8188,39872,35319,27611,8554,8602]
def require(ok,why):
    if not ok:raise ValueError(why)
def project(d):
    require(d.get('operation')=='commercial-top500-internal-base-20261105-ten1-read1' and d.get('ok') is True,'original_operation_or_success_changed')
    require(d.get('installed_sha')=='5a18f64f790fdc3ace989d99e8efc84046159795' and d.get('scope_native_ids')==IDS,'original_source_or_native_scope_changed')
    h=d.get('hotels');require(isinstance(h,list) and [x.get('native_hotel_id') for x in h]==IDS,'original_exact10_partition_changed')
    x=next(v for v in h if v['native_hotel_id']==8208)
    n=x.get('current_native');g=x.get('local_tv_metadata');p=x.get('stored_price_last72h')
    require(isinstance(n,dict) and n.get('id')==8208 and isinstance(g,dict) and g.get('id')==8208 and isinstance(p,dict) and p.get('hotel_id')==8208,'exact_native_row_binding_changed')
    require(x.get('owner_top500_membership') is True and x.get('ready_for_publication') is False and x.get('publisher_role') is None,'original_unpublished_role_changed')
    gallery=g.get('distinct_declared_image_urls');require(isinstance(gallery,list) and len(gallery)==41 and len(set(gallery))==41 and g.get('distinct_declared_image_url_count')==41,'original_gallery_count_changed')
    require(p.get('recent_rows')==175 and p.get('existing_policy_condition_rows')==70 and p.get('stored_search_price_is_final_or_available') is False,'original_price_metadata_changed')
    allowed={'hotel_id','recent_rows','oldest_persisted_epoch','newest_persisted_epoch','existing_policy_condition_rows','stored_search_price_is_final_or_available'}
    require(set(p)<=allowed,'private_price_fields_not_permitted_in_projection')
    for url in gallery:
        require(isinstance(url,str) and len(url)<=2048,'declared_url_invalid')
        q=urllib.parse.urlsplit(url)
        require(q.scheme=='https' and q.hostname and not q.username and not q.password and not q.fragment and 'firebase' not in q.hostname.lower() and 'token=' not in q.query.lower(),'unsafe_declared_url')
    return {'operation':OP,'native_hotel_id':8208,'source_run':42165764181,'source_artifact':13479140677,'source_file':'top500-readback.private.json','source_capture_installed_sha':d['installed_sha'],'current_native':copy.deepcopy(n),'local_tv_metadata':copy.deepcopy(g),'stored_price_last72h':copy.deepcopy(p),'price_window_start_epoch_utc':d.get('price_window_start_epoch_utc'),'capture_server_epoch_utc':d.get('server_epoch_utc'),'registry_exact_native_metadata_matches':copy.deepcopy(x.get('registry_exact_native_metadata_matches')),'effective_gallery_rights_hero_facts_not_accepted':True,'ready_for_publication':False,'server_db_provider_quote_photo_calls':0}
def cases():
    n={'id':8208,'name':'SYNTHETIC_METADATA_FIXTURE','country_id':None,'is_active':None}
    g={'id':8208,'distinct_declared_image_urls':['https://fixture.example/declared%d.jpg'%i for i in range(41)],'distinct_declared_image_url_count':41}
    p={'hotel_id':8208,'recent_rows':175,'existing_policy_condition_rows':70,'stored_search_price_is_final_or_available':False}
    h=[{'native_hotel_id':i} for i in IDS];x=next(v for v in h if v['native_hotel_id']==8208)
    x.update(current_native=n,local_tv_metadata=g,stored_price_last72h=p,owner_top500_membership=True,ready_for_publication=False,publisher_role=None)
    d={'operation':'commercial-top500-internal-base-20261105-ten1-read1','ok':True,'installed_sha':'5a18f64f790fdc3ace989d99e8efc84046159795','scope_native_ids':IDS.copy(),'hotels':h}
    require(project(d)['current_native']['country_id'] is None and not project(d)['ready_for_publication'],'missing_identity_invented_or_ready')
    for case in ['operation','scope','partition','native','gallery_binding','gallery_count','price_binding','private_price_field','unsafe_url']:
        b=copy.deepcopy(d);r=next(v for v in b['hotels'] if v['native_hotel_id']==8208)
        if case=='operation':b['operation']+='-foreign'
        elif case=='scope':b['scope_native_ids'].reverse()
        elif case=='partition':b['hotels'].append(copy.deepcopy(r))
        elif case=='native':r['current_native']['id']=1
        elif case=='gallery_binding':r['local_tv_metadata']['id']=1
        elif case=='gallery_count':r['local_tv_metadata']['distinct_declared_image_urls'].pop()
        elif case=='price_binding':r['stored_price_last72h']['hotel_id']=1
        elif case=='private_price_field':r['stored_price_last72h']['fingerprint']='synthetic_rejected_not_live'
        else:r['local_tv_metadata']['distinct_declared_image_urls'][0]='https://firebasestorage.googleapis.com/photo?token=synthetic'
        try:project(b)
        except ValueError:continue
        raise AssertionError('unsafe_projection_case_admitted:'+case)
    return 10
if __name__=='__main__':
    require(cases()==10,'new_projection_case_count')
    print(json.dumps({'operation':OP,'new_input_case_groups':10,'old_cases_rerun':0,'server_db_provider_photo_calls':0}))
    root=pathlib.Path(os.environ['RUNNER_TEMP']);src=root/'original-ten1'/'top500-readback.private.json'
    raw=src.read_bytes();require(0<len(raw)<=1048576,'original_metadata_bytes_bounds')
    result=project(json.loads(raw));result['original_metadata_bytes_sha256']=hashlib.sha256(raw).hexdigest()
    exc=json.loads(pathlib.Path('tools/direct_demand/operations/commercial-top500-internal-base-20261106-8208-closed-contact-hashes.json').read_text())
    require(exc['closed_url_count']==len(set(exc['closed_exact_url_sha256']))==293 and exc['closed_decoded_basename_count']==len(set(exc['closed_lower_decoded_basename_sha256']))==292,'canonical_exclusions_changed')
    urlset=set(exc['closed_exact_url_sha256']);baseset=set(exc['closed_lower_decoded_basename_sha256'])
    pairs=[]
    for url in result['local_tv_metadata']['distinct_declared_image_urls']:
        q=urllib.parse.urlsplit(url);base=urllib.parse.unquote(q.path.rsplit('/',1)[-1]).lower()
        pairs.append({'url_sha256':hashlib.sha256(url.encode()).hexdigest(),'basename':base,'domain':q.hostname,'closed_exact_url':hashlib.sha256(url.encode()).hexdigest() in urlset,'closed_basename':hashlib.sha256(base.encode()).hexdigest() in baseset})
    result['gallery_exclusion_metadata']=pairs
    (root/'top500-8208-metadata.private.json').write_text(json.dumps(result,ensure_ascii=False,separators=(',',':'))+'\n')
    n=result['current_native'];g=result['local_tv_metadata'];p=result['stored_price_last72h']
    safe={'operation':OP,'native_hotel_id':8208,'current_native_identity':{k:n.get(k) for k in ['id','name','country_id','country_name','is_active','region_id','region_name']},'gallery_count':len(pairs),'gallery_source_revision':g.get('revision'),'gallery_source_fetched_at':g.get('source_fetched_at'),'gallery_content_state':g.get('content_state'),'gallery_manual_field_names':g.get('manual_field_names'),'closed_url_matches':sum(v['closed_exact_url'] for v in pairs),'closed_basename_matches':sum(v['closed_basename'] for v in pairs),'gallery_metadata':pairs,'price_coverage':p,'capture_server_epoch_utc':result['capture_server_epoch_utc'],'current_epoch_utc_for_reuse':'MUST_REEVALUATE_AT_NEXT_ACTION','ready_for_publication':False,'server_db_provider_quote_photo_calls':0}
    (root/'top500-8208-summary.json').write_text(json.dumps(safe,ensure_ascii=False,separators=(',',':'))+'\n')
    print(json.dumps(safe,ensure_ascii=False,separators=(',',':')))
