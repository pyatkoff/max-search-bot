import copy,hashlib,json,os,pathlib,urllib.parse
base=pathlib.Path('tools/direct_demand/operations');folder=pathlib.Path(os.environ['RUNNER_TEMP'])/'source58'
filename=os.environ['SOURCE_METADATA_PROJECTION_PLAN_FILENAME'];assert pathlib.Path(filename).name==filename
raw=(base/filename).read_bytes();assert hashlib.sha256(raw).hexdigest()==os.environ['EXPECTED_SOURCE_METADATA_PROJECTION_PLAN_SHA256'];plan=json.loads(raw)
assert plan['source_run_id']==42110612108 and plan['source_sha']=='616f2e2f3f488f3d0858a9088d3e64b777af4e09' and plan['source_artifact_id']==13454390908 and plan['source_artifact_name']=='commercial-batch58-primary-0'
def one(name):
 rows=list(folder.rglob(name));assert len(rows)==1
 return json.loads(rows[0].read_text())
source=one(plan['source_file']);summary=one(plan['source_summary_file'])
assert source['operation']=='commercial-batch-20261105-58-primary-preparation1' and source['ok'] is True and len(source['pages'])==1
page=source['pages'][0]
def validate(p):
 assert p['hotel_id']==343 and p['source_url']==p['final_url']==plan['source_url']=='https://www.oldpalaceresort.com/en/gallery/'
 assert p['property_identity_tokens_matched'] is True and not p.get('error')
 text=p['public_text'];assert len(text)==plan['public_text_characters']==1123 and hashlib.sha256(text.encode()).hexdigest()==plan['public_text_sha256']
 assert len(p['declared_public_image_references'])==len(p['image_urls'])==48
 for uri in p['declared_public_image_references']:
  q=urllib.parse.urlsplit(uri)
  assert q.scheme=='https' and q.hostname=='www.oldpalaceresort.com' and not q.username and not q.password and not q.fragment
  assert not any(s in uri.lower() for s in ('firebase','token=','access_token','api_key'))
validate(page)
cases=0
for label in ('native','source_url','final_url','identity','text_sha','foreign_image'):
 p=copy.deepcopy(page)
 if label=='native':p['hotel_id']=83563
 elif label=='source_url':p['source_url']='https://www.oldpalaceresort.com/en/'
 elif label=='final_url':p['final_url']='https://www.oldpalaceresort.com/en/'
 elif label=='identity':p['property_identity_tokens_matched']=False
 elif label=='text_sha':p['public_text']+='foreign'
 else:p['declared_public_image_references'][0]='https://foreign.invalid/photo.jpg'
 try:validate(p)
 except AssertionError:cases+=1
 else:raise RuntimeError('NEW_SOURCE58_METADATA_NEGATIVE_ACCEPTED:'+label)
assert cases==6 and summary['readable_identity_bound_pages']==1 and summary['new_owner_http_gets']==1 and summary['pages'][0]['public_text_sha256']==plan['public_text_sha256']
keys=['source_url','final_url','page_title','image_hosts','image_urls','declared_public_image_references','image_details','gallery_memberships','document_urls','website_links','hotel_id']
metadata={k:page.get(k) for k in keys};metadata.update({'public_text_sha256':plan['public_text_sha256'],'public_text_characters':1123})
out={'operation':plan['operation'],'state':'CURRENT_OWNER_GALLERY_METADATA_PROJECTED_NO_NEW_OWNER_HTTP','source_run_id':plan['source_run_id'],'source_sha':plan['source_sha'],'source_artifact_id':plan['source_artifact_id'],'new_actual_input_cases':cases,'old_fixtures_rerun':0,'owner_page_gets':0,'raw_photo_gets':0,'server_calls':0,'provider_calls':0,'new_posts':0,'ready_for_send':0,'metadata':metadata}
(pathlib.Path(os.environ['RUNNER_TEMP'])/'source58-owner-metadata.json').write_text(json.dumps(out,ensure_ascii=False)+'\n')
print(json.dumps(out,ensure_ascii=False),flush=True)
