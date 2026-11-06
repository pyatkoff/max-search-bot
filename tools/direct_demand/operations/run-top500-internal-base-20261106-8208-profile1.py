import hashlib,json,os,pathlib,re,subprocess,urllib.parse
t=pathlib.Path(os.environ['RUNNER_TEMP']);assert os.environ['GITHUB_RUN_ATTEMPT']=='1'
out=t/'top500-profile-readback.private.json';assert not out.exists(),'ORIGINAL_PROFILE_OUTCOME_FIRST_NO_REPEAT'
sha=os.environ['EXPECTED_PRODUCTION_SHA'];assert re.fullmatch('[a-f0-9]{40}',sha)
intent=json.loads((t/'top500-profile-input.private.json').read_text());assert intent['expected_installed_sha']==sha
ssh=['ssh','-i',str(t/'channel_key'),'-o','BatchMode=yes','-o','ConnectTimeout=10','-o','IdentitiesOnly=yes','-o','StrictHostKeyChecking=yes','-o','GlobalKnownHostsFile=/dev/null','-o','UserKnownHostsFile='+str(t/'channel_known_hosts'),os.environ['DEPLOY_USER']+'@'+os.environ['DEPLOY_HOST']]
remote="set -e; cd /var/www/anytoour/data/www/app.anytoour.ru; test \"$(git rev-parse HEAD)\" = '"+sha+"'; test -z \"$(git status --porcelain --untracked-files=no)\"; php"
with (t/'top500-profile.php').open('rb') as src,out.open('wb') as dst:r=subprocess.run(ssh+[remote],stdin=src,stdout=dst,stderr=subprocess.PIPE,timeout=240)
d=json.loads(out.read_text().splitlines()[-1]);p=d.get('profile') or {}
s={k:d.get(k) for k in ['operation','ok','error','error_class','sqlstate','last_completed_stage','installed_sha','source_binding','db_writes','gateway_calls','provider_post_reads','provider_channel_rights_checks','message_sends','photo_http','copyright_reuse_proof','hero_quality_proof','ready_for_publication']}
s['fields']={k:({'type':'text','characters':len(v),'sha256':hashlib.sha256(v.encode()).hexdigest()} if isinstance(v,str) else {'type':type(v).__name__,'items':len(v) if isinstance(v,(list,dict)) else None}) for k,v in p.items()}
s['photo_details_metadata']=[{'index':i,'url_sha256':hashlib.sha256(z['url'].encode()).hexdigest(),'caption':z.get('caption') if isinstance(z.get('caption'),str) and len(z['caption'])<=100 else None,'caption_characters':len(z.get('caption') or '')} for i,z in enumerate(p.get('photoDetails') or [])]
s['hotel_information_field_names']={k:list(v) if isinstance(v,dict) else None for k,v in (p.get('hotelInformation') or {}).items()} if isinstance(p.get('hotelInformation'),dict) else None
s['description_section_metadata']=[{'label':z.get('label'),'field':z.get('field'),'text_characters':len(z.get('text') or ''),'text_sha256':hashlib.sha256((z.get('text') or '').encode()).hexdigest()} for z in p.get('descriptionSections') or [] if isinstance(z,dict)]
site=p.get('site');s['stored_site_pointer']=None
if isinstance(site,str) and len(site)<=2048:
 q=urllib.parse.urlsplit(site)
 if q.scheme=='https' and q.hostname and not q.username and not q.password and 'firebase' not in q.hostname.lower() and 'token=' not in q.query.lower():s['stored_site_pointer']=site
(t/'top500-profile-summary.json').write_text(json.dumps(s,ensure_ascii=False,separators=(',',':'))+'\n');print(json.dumps(s,ensure_ascii=False,separators=(',',':')))
assert r.returncode==0 and d['ok'] and d['last_completed_stage']=='complete_selected_profile' and d['source_binding']['native_hotel_id']==8208 and d['source_binding']['country_id']==2
assert d['db_writes']==d['gateway_calls']==d['provider_post_reads']==d['message_sends']==d['photo_http']==0 and d['provider_channel_rights_checks']==2
