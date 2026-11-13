"""Local preparation/verification for the existing one-shot feed writer carrier."""
import base64, hashlib, json, os, re, subprocess, sys, zipfile
from pathlib import Path

OPS = Path('tools/direct_demand/operations')
PREFIX = 'commercial-caption-review73-20261113'
PRIVATE = Path(os.environ.get('RUNNER_TEMP', '/nonexistent')) / 'caption-repair-private'
SAFE = Path(os.environ.get('RUNNER_TEMP', '/nonexistent')) / 'caption-repair-safe'
NAMES = ['canary', 'batch001', 'batch002', 'batch003']
FEEDS = ['hotel-posts-telegram.xml', 'hotel-posts-max.xml', 'hotel-posts-all.xml']
PHP_PREFIX = '<?php\ndeclare(strict_types=1);\n'

def need(ok, why):
    if not ok: raise RuntimeError(why)
def digest(raw): return hashlib.sha256(raw).hexdigest()
def read(path): return json.loads(path.read_bytes())
def save(path, value): path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + '\n')
def decimal(value):
    need(type(value) is int and value > 0 or isinstance(value, str) and re.fullmatch(r'[1-9][0-9]*', value), 'noncanonical_decimal_identity')
    return int(value)
def plan():
    p=read(OPS/(PREFIX+'-feed-plan.json'))
    need(p['schema_version']==1 and p['ready'] is True,'feed_plan_not_ready')
    need(p['operation']=='commercial-channel-hotel-feeds-20261113-update19' and p['current_installed_sha']==os.environ['EXPECTED_PRODUCTION_SHA']=='8d6ac35dd17614b2cadb3aa3af7637c74280c01c','current_source_or_operation_changed')
    need(p['cohort_installed_sha']=='5fbb61990706ae4289e539df2c26c60e409032f4' and p['completion_installed_sha']=='8d6ac35dd17614b2cadb3aa3af7637c74280c01c' and p['cohort_sha256']=='4d8f9340e3fd5b62d7c5828bd889a9828d87c91a343a98b2d5db8c5de46b9fab','cohort_source_changed')
    need(p['operations']==[{'name': 'canary', 'operation': 'commercial-caption-review73-20261113-canary-v1', 'manifest_sha256': '65595a177031b4240ff0dcf757870ba2dc5a01132789e84bcd64080ce64ab14a', 'installed_sha': '5fbb61990706ae4289e539df2c26c60e409032f4', 'row_count': 8}, {'name': 'batch001', 'operation': 'commercial-caption-review73-20261113-batch001-v1', 'manifest_sha256': '870960c2dd9b17c43b07a83cd6fe484e3a41effe771a06537aa686646053b94d', 'installed_sha': '5fbb61990706ae4289e539df2c26c60e409032f4', 'row_count': 46}, {'name': 'batch002', 'operation': 'commercial-caption-review73-20261113-batch002-v1', 'manifest_sha256': '7a5ea2f4c469daf6851f4e6264ff57f6001265f8ea84c9a8dbc939d9d3cf610b', 'installed_sha': '8d6ac35dd17614b2cadb3aa3af7637c74280c01c', 'row_count': 46}, {'name': 'batch003', 'operation': 'commercial-caption-review73-20261113-batch003-v1', 'manifest_sha256': 'ba694b939740a4e28388ca6cccc9e2d6d542703539e5f546d1d9961fe2c0e2e2', 'installed_sha': '8d6ac35dd17614b2cadb3aa3af7637c74280c01c', 'row_count': 46}],'operation_scope_changed')
    need(p['source_admission']=='existing_authorized_content_push' and p['current_source_gates']=={'deploy.yml': 43456259794, 'retired-domain-guard.yml': 43456259765, 'max-tls-preflight.yml': 43458415792, 'telegram-production-health.yml': 43458412715, 'max-production-health.yml': 43458412674, 'publish-conversation-diagnostics.yml': 43458415744, 'live-session-diagnostics.yml': 43458412698, 'tour-selection-host.yml': 43458412489, 'modeled-conversion-monitor.yml': 43458412487},'content_source_admission_changed')
    need(p['expected_feed_price_pairs']==274 and p['expected_caption_price_pairs']==27,'price_scope_changed')
    return p

def prepare():
    p=plan()
    need(os.environ['GITHUB_RUN_ATTEMPT']=='1','run_retry_not_authorized')
    need(subprocess.check_output(['git','rev-parse','HEAD'],text=True).strip()==os.environ['GITHUB_SHA'],'checkout_head_changed')
    for name,expected in p['source_script_pins'].items():need(digest((OPS/name).read_bytes())==expected,'approved_source_blob_changed:'+name)
    PRIVATE.mkdir(mode=0o700);SAFE.mkdir(mode=0o700)
    import gzip
    packed=(OPS/(PREFIX+'-feed-inputs.json.gz.b64')).read_bytes()
    files=json.loads(gzip.decompress(base64.b64decode(packed,validate=False)))
    need(set(files)=={'approved-cohort.json'}|{name+'.manifest.json' for name in NAMES},'immutable_input_scope_changed')
    for name,text in files.items():(PRIVATE/name).write_text(text)
    cohort=read(PRIVATE/'approved-cohort.json')
    need(len(cohort['rows'])==146 and digest((PRIVATE/'approved-cohort.json').read_bytes())==p['cohort_sha256'] and cohort['expected_installed_sha']==p['cohort_installed_sha'],'immutable_cohort_changed')
    rows={x['publication_id']:x for x in cohort['rows']};seen=set()
    for b in p['operations']:
        raw=(PRIVATE/(b['name']+'.manifest.json')).read_bytes();m=json.loads(raw)
        need(digest(raw)==p['original_manifest_sha256'][b['name']] and m['expected_installed_sha']==p['cohort_installed_sha'] and m['operation']==b['operation'] and m['row_count']==len(m['rows'])==b['row_count'],'immutable_manifest_changed')
        for x in m['rows']:
            need(x['publication_id'] in rows and rows[x['publication_id']]==x and x['publication_id'] not in seen,'immutable_manifest_cohort_changed');seen.add(x['publication_id'])
    need(len(seen)==146,'immutable_union_incomplete')
    save(SAFE/'feed-plan.json',p)
    print(json.dumps({'approved_hotels':73,'approved_existing_captions':146,'feed_operation':p['operation'],'caption_edit_calls':0}))

def lease(phase):
    p=plan(); prefix=PRIVATE/('lease-'+phase)
    get=lambda suffix: read(Path(str(prefix)+'.'+suffix+'.json'))
    need(get('main')['object']['sha']==p['current_installed_sha'], 'main_lease_changed')
    need(get('carrier')['object']['sha']==os.environ['GITHUB_SHA'], 'carrier_head_lease_changed')
    comment=get('owner');pin=p['owner_pin']
    need(comment['id']==pin['comment_id'] and comment['user']['login']==pin['owner']
         and comment['updated_at']==pin['updated_at'] and digest(comment['body'].encode())==pin['body_sha256']
         and pin['required_scope_line'] in comment['body'].splitlines(), 'owner_claim_changed')
    run=get('run')
    need(decimal(run['id'])==decimal(os.environ['GITHUB_RUN_ID']) and run['run_attempt']==1 and run['status']=='in_progress'
         and run['event']=='push' and run['head_sha']==os.environ['GITHUB_SHA']
         and run['head_branch']=='audit/content100-bot-publication-20261101'
         and run['path']=='.github/workflows/content100-bot-publication-20261101.yml'
         and run['actor']['login']==p['expected_actor'] and run['triggering_actor']['login']==p['expected_actor'], 'current_run_owner_changed')
    need(os.environ['GITHUB_ACTOR']==p['expected_actor'] and os.environ['GITHUB_RUN_ATTEMPT']=='1', 'owner_or_attempt_changed')
    event=read(Path(os.environ['GITHUB_EVENT_PATH']))
    need(event['before']==p['expected_parent_head'] and event['after']==os.environ['GITHUB_SHA'], 'push_parent_lease_changed')
    commit=get('commit')
    need(commit['sha']==os.environ['GITHUB_SHA'] and [x['sha'] for x in commit['parents']]==[p['expected_parent_head']], 'carrier_parent_changed')
    active=get('active'); queued=get('queued')
    need(active['total_count']<=1 and all(decimal(r['id'])==decimal(os.environ['GITHUB_RUN_ID']) for r in active['workflow_runs'])
         and queued['total_count']==0, 'foreign_carrier_run_present')
    save(SAFE/('lease-'+phase+'.json'), {'ok':True,'owner_comment_id':pin['comment_id'],'owner_updated_at':pin['updated_at'],
        'owner_body_sha256':pin['body_sha256'],'carrier_head':os.environ['GITHUB_SHA'],'parent_head':p['expected_parent_head'],
        'installed_sha':p['current_installed_sha'],'run_id':decimal(os.environ['GITHUB_RUN_ID']),'run_attempt':1,'phase':phase})
    print(json.dumps({'lease':phase,'owner_and_heads_verified':True,'foreign_runs':0}))

def artifact(prefix, binding):
    run=read(PRIVATE/(prefix+'-run.json')); meta=read(PRIVATE/(prefix+'-artifact.json'))
    need(decimal(run['id'])==binding['run_id'] and run['head_sha']==binding['head_sha'] and run['run_attempt']==1
         and run['status']=='completed' and run['conclusion']==binding['conclusion']
         and run['head_branch']=='audit/content100-bot-publication-20261101'
         and run['path']=='.github/workflows/content100-bot-publication-20261101.yml'
         and run['actor']['login']=='pyatkoff' and run['triggering_actor']['login']=='pyatkoff' and run['event']=='push', 'original_run_binding_changed')
    need(meta['id']==binding['artifact_id'] and meta['name']==binding['artifact_name'] and meta['expired'] is False
         and meta['digest']=='sha256:'+binding['artifact_sha256'] and meta['workflow_run']['id']==binding['run_id']
         and meta['workflow_run']['head_sha']==binding['head_sha'], 'original_artifact_binding_changed')
    raw=(PRIVATE/(prefix+'.zip')).read_bytes(); need(digest(raw)==binding['artifact_sha256'], 'original_artifact_bytes_changed')
    with zipfile.ZipFile(PRIVATE/(prefix+'.zip')) as z:
        infos=z.infolist(); names=[i.filename for i in infos]
        need(len(names)==len(set(names)) and 1<=len(names)<=30 and all('/' not in n and '\\' not in n for n in names)
             and sum(i.file_size for i in infos)<30000000 and all(i.file_size<8000000 for i in infos), 'artifact_archive_scope_changed')
        return {n:z.read(n) for n in names}

def receipt_result(r, m, raw, p):
    need(r['validated'] is True and r['operation']==m['operation'] and r['manifest_sha256']==digest(raw)
         and len(r['posts'])==r['confirmed_posts']==m['row_count'], 'original_result_not_confirmed')
    targets={x['publication_id']:x for x in m['rows']}; seen=set()
    for a in r['posts']:
        pid=a['publication_id']; need(pid in targets and pid not in seen, 'receipt_scope_changed');seen.add(pid);x=targets[pid]
        for k in ['hotel_id','platform','publication_id','message_id','post_url','photo_signature','button_url_sha256','source_key']:
            need(a[k]==x[k], 'receipt_identity_changed:'+k)
        need(a['previous_version_id']==x['version_id'] and type(a['version_id']) is int and a['version_id']!=x['version_id']
             and a['previous_text_sha256']==x['old_text_sha256'] and a['text_sha256']==x['new_text_sha256']
             and a['previous_attachments_sha256']==x['attachments_sha256'] and a['state']=='sent'
             and a['button_text']=='✈️ Узнать стоимость тура'
             and a.get('monitor_price_record_id')==x.get('monitor_price_record_id')
             and a.get('caption_correction_operation')==m['operation'], 'receipt_versions_or_content_changed')
        for k in ['body_photo_button_verified','formatting_verified','existing_message_reused','existing_token_resolves_to_current_version']:
            need(a[k] is True, 'receipt_not_verified:'+k)

def terminal():
    p=plan();partial=artifact('original-caption',p['original_caption']);complete=artifact('completion-caption',p['completion_caption'])
    from runpy import run_path
    contract=run_path(str(OPS/(PREFIX+'-completion-contract.py')))
    raw=(PRIVATE/'approved-cohort.json').read_bytes()
    originals={name:(PRIVATE/(name+'.manifest.json')).read_bytes() for name in NAMES}
    proof=contract['verify'](p,raw,originals,partial,complete,read(PRIVATE/'original-caption-jobs.json'))
    for name in NAMES:
        (PRIVATE/(name+'.manifest.json')).write_bytes(proof['manifests'][name])
        (SAFE/(name+'.result.json')).write_bytes(complete[name+'.result.json'])
    save(PRIVATE/'confirmed-caption-receipts.json',proof['receipts'])
    (SAFE/'caption-terminal-summary.json').write_bytes(complete['summary.json'])
    (SAFE/'caption-completion-provenance.json').write_bytes(complete['completion-provenance.json'])
    for name in ['source-gate-receipts.json','content-source-acceptance.json','release-closure-status.json']:
        (SAFE/('caption-'+name)).write_bytes(complete[name])
    compile_capture(p)
    print(json.dumps({'original_confirmed':54,'first_execution_remaining_confirmed':92,'all_original_operations_complete':True,'caption_replays':0,'caption_price_blocks_preserved':54}))

def php_bundle(template, constants, include_preparation=False):
    sources=[(OPS/(PREFIX+'-feed-contract.php')).read_text()]
    if include_preparation:
        s=(OPS/(PREFIX+'-feed-preparation.php')).read_text()
        required="if (!function_exists('caption_feed_render')) require_once __DIR__.'/commercial-caption-review73-20261113-feed-contract.php';"
        need(s.count(required)==1,'preparation_dependency_changed');sources.append(s.replace(required,''))
    sources.append((OPS/template).read_text())
    need(all(s.startswith(PHP_PREFIX) for s in sources),'php_source_header_changed')
    need(all("'" not in str(v) for v in constants.values()),'php_constant_invalid')
    header='\n'.join("define('%s', '%s');"%(k,v) for k,v in constants.items())+'\n'
    return PHP_PREFIX+header+'\n'.join(s[len(PHP_PREFIX):] for s in sources)

def compile_capture(p):
    operations=[{k:b[k] for k in ['operation','manifest_sha256','installed_sha','row_count']} for b in p['operations']]
    raw=json.dumps(operations,ensure_ascii=False,separators=(',',':')).encode()
    compiled=php_bundle(PREFIX+'-feed-capture.php',{'EXPECTED_INSTALLED_SHA':p['current_installed_sha'],'CAPTION_FEED_OPERATION':p['operation'],
        'CAPTION_CAPTURE_OPERATIONS_SHA256':digest(raw),'CAPTION_CAPTURE_OPERATIONS_BASE64':base64.b64encode(raw).decode()},True)
    (PRIVATE/'feed-capture.php').write_text(compiled)

def capture(status):
    p=plan(); r=read(SAFE/'capture.json')
    need(status==0 and r['ok'] is True and r['installed_sha']==p['current_installed_sha'] and r['operation']==p['operation']
         and r['filesystem_writes']==r['database_calls']==r['provider_calls']==0 and r['locks_released_before_return'] is True
         and r['monitor_journal_unchanged'] is True,'fresh_capture_unconfirmed')
    expected=read(PRIVATE/'confirmed-caption-receipts.json');seen=set()
    for b in p['operations']:
        s=r['caption_states'][b['operation']]
        need(s['complete'] is True and s['installed_sha']==b['installed_sha'] and s['manifest_sha256']==b['manifest_sha256'] and s['row_count']==b['row_count'], 'captured_original_source_changed')
        for key,post in s['posts'].items():
            need(key in expected and key not in seen and post['state']=='confirmed' and post['receipt']==expected[key], 'captured_receipt_changed');seen.add(key)
    need(len(seen)==146,'captured_receipts_incomplete')
    print(json.dumps({'fresh_files':3,'confirmed_original_receipts':146,'capture_lock_released':True}))

def verify_all_feed_prices(i, p):
    """Independent byte check: only the 146 exact descriptions may change."""
    import xml.etree.ElementTree as ET
    updates=i['caption_updates']
    need(len(updates)==146, 'preservation_update_scope_changed')
    need(len({u['offer_id'] for u in updates})==146, 'preservation_duplicate_offer')
    need([f['name'] for f in i['files']]==['hotel-posts-telegram.xml','hotel-posts-max.xml','hotel-posts-all.xml'],'preservation_file_scope_changed')
    need(all(u['platform'] in ['max','telegram'] for u in updates),'preservation_platform_changed')
    blocks_re=re.compile(rb'<offer id="([A-Za-z0-9_-]+)">.*?</offer>', re.S)
    prices={};checks=[]
    for f in i['files']:
        name=f['name'];before=base64.b64decode(f['previous_content_base64'],validate=True);after=base64.b64decode(f['content_base64'],validate=True)
        need(digest(before)==f['expected_previous_sha256'] and digest(after)==f['sha256'], 'preservation_preimage_changed')
        targets={u['offer_id']:u for u in updates if name=='hotel-posts-all.xml' or u['platform']==('max' if name=='hotel-posts-max.xml' else 'telegram')}
        need(len(targets)==(146 if name=='hotel-posts-all.xml' else 73), 'preservation_target_count_changed')
        old={m.group(1).decode():m.group(0) for m in blocks_re.finditer(before)}
        new={m.group(1).decode():m.group(0) for m in blocks_re.finditer(after)}
        expected=1208 if name=='hotel-posts-all.xml' else 604
        need(len(old)==len(new)==expected and list(old)==list(new), 'preservation_offer_scope_changed')
        need(set(targets)<=set(old) and set(targets)<=set(new),'preservation_target_missing')
        def masked(raw):
            def change(m):
                if m.group(1).decode() not in targets:return m.group(0)
                value,n=re.subn(rb'<description>.*?</description>',b'<description>__ALLOWED_DESCRIPTION__</description>',m.group(0),flags=re.S)
                need(n==1,'preservation_description_not_unique');return value
            return blocks_re.sub(change,raw)
        need(masked(before)==masked(after),'preservation_unapproved_byte_change')
        a=ET.fromstring(before);b=ET.fromstring(after)
        def price_map(root):
            values={}
            for offer in root.findall('.//offer'):
                ns=offer.findall('price')
                need(len(ns)<=1,'preservation_price_not_unique')
                if ns:
                    v=ns[0].text or ''
                    need(re.fullmatch(r'[0-9]+(?:\.[0-9]{1,2})?',v) and float(v)>0,'preservation_price_invalid')
                    values[offer.attrib['id']]=(v,offer.findtext('currencyId'))
            return values
        pa=price_map(a);pb=price_map(b)
        need(pa==pb and len(pa)==p['expected_feed_price_pairs']*(2 if name=='hotel-posts-all.xml' else 1),'preservation_274_price_pairs_changed')
        for offer in b.findall('.//offer'):
            key=offer.attrib['id']
            if key in targets:
                t=offer.findtext('description') or ''
                need(digest(t.encode())==targets[key]['text_sha256'],'preservation_description_receipt_changed')
        prices[name]=pa
        checks.append({'name':name,'rows':expected,'target_descriptions':len(targets),'priced_offers_retained':len(pa),'all_other_bytes_preserved':True,'before_sha256':digest(before),'after_sha256':digest(after)})
    max_prices={k.removeprefix('max_'):v for k,v in prices['hotel-posts-max.xml'].items()}
    tg_prices={k.removeprefix('tg_'):v for k,v in prices['hotel-posts-telegram.xml'].items()}
    need(max_prices==tg_prices and len(max_prices)==274,'preservation_price_pair_binding_changed')
    need(prices['hotel-posts-all.xml']==dict(prices['hotel-posts-telegram.xml'],**prices['hotel-posts-max.xml']),'preservation_combined_prices_changed')
    return {'ok':True,'priced_hotel_pairs_retained':274,'priced_messages_retained':548,'priced_xml_occurrences_retained':1096,'target_description_occurrences':292,'price_changes':0,'url_changes':0,'photo_changes':0,'all_nondescription_bytes_preserved':True,'files':checks}


def prepared():
    p=plan(); prep=read(SAFE/'preparation.json'); saved=(SAFE/'actual-feed-input.json').read_bytes();need(saved.endswith(b'\n'),'input_file_incomplete');raw=saved[:-1];i=json.loads(raw)
    need(digest(raw)==prep['input_sha256'] and digest(saved)==prep['saved_input_file_sha256'] and digest((PRIVATE/'writer.php').read_bytes())==prep['compiled_writer_sha256']
         and i['operation']==p['operation'] and i['schema_version']==5 and len(i['caption_updates'])==146,'prepared_writer_binding_changed')
    preservation=verify_all_feed_prices(i,p)
    save(SAFE/'preservation-verification.json',preservation)
    files=[{k:f[k] for k in ['name','sha256','bytes','rows','expected_previous_sha256']} for f in i['files']]
    need([f['name'] for f in files]==FEEDS and [f['rows'] for f in files]==[604,604,1208]
         and files==prep['files'],'prepared_file_scope_changed')
    raw_files=json.dumps(files,separators=(',',':')).encode()
    script=php_bundle(PREFIX+'-feed-readback.php',{'EXPECTED_INSTALLED_SHA':p['current_installed_sha'],'CAPTION_FEED_OPERATION':p['operation'],
        'CAPTION_READBACK_FILES_SHA256':digest(raw_files),'CAPTION_READBACK_FILES_BASE64':base64.b64encode(raw_files).decode()})
    (PRIVATE/'feed-readback.php').write_text(script)
    print(json.dumps({'prepared_feed_input_sha256':prep['input_sha256'],'same_existing_writer':True,'external_writes':0}))

def writer(status):
    p=plan();prep=read(SAFE/'preparation.json');raw=(SAFE/'writer-receipt.json').read_bytes()
    result={'operation':p['operation'],'ssh_status':status,'writer_output_sha256':digest(raw),'validated':False,'retry_authorized':False}
    try:
        r=json.loads(raw);need(status==0 and r['ok'] is True and r['operation']==p['operation']
            and r['mode']=='publish_three_static_public_direct_yml_feeds' and r['db_writes']==r['provider_calls']==r['message_sends']==0,'writer_not_confirmed')
        need([f['name'] for f in r['files']]==FEEDS,'writer_file_scope_changed')
        for expected,actual in zip(prep['files'],r['files']):
            for k in ['name','sha256','bytes','rows']:need(actual[k]==expected[k],'writer_file_binding_changed')
            need(actual['write_action'] in ['replace','reuse'] and actual['public_url']=='https://app.anytoour.ru/feed/'+actual['name'],'writer_action_changed')
        result['validated']=True;result['files']=r['files']
    except Exception as e:result['error']=str(e)
    save(SAFE/'writer-verification.json',result);print(json.dumps({k:result[k] for k in ['operation','validated','retry_authorized']}))
    need(result['validated'],'unconfirmed_writer_no_retry')

def readback(status):
    p=plan();r=read(SAFE/'public-readback.json');prep=read(SAFE/'preparation.json')
    need(status==0 and r['ok'] is True and r['operation']==p['operation'] and r['installed_sha']==p['current_installed_sha']
         and r['filesystem_writes']==r['database_calls']==r['provider_calls']==0 and r['public_feed_gets']==3 and r['monitor_journal_unchanged'] is True,'feed_readback_not_confirmed')
    need([f['name'] for f in r['files']]==FEEDS,'public_readback_scope_changed')
    for expected,actual in zip(prep['files'],r['files']):
        body=base64.b64decode(actual['public_content_base64'],validate=True)
        need(actual['expected_sha256']==actual['local_sha256']==actual['public_sha256']==expected['sha256']==digest(body)
             and actual['bytes']==expected['bytes']==len(body) and actual['rows']==expected['rows'] and actual['http_status']==200
             and actual['url']=='https://app.anytoour.ru/feed/'+actual['name'],'local_public_bytes_mismatch')
        (SAFE/('public-'+actual['name'])).write_bytes(body)
    need(read(SAFE/'writer-verification.json')['validated'] is True,'writer_unconfirmed_at_readback')
    out={'ok':True,'operation':p['operation'],'installed_sha':p['current_installed_sha'],'carrier_head':os.environ['GITHUB_SHA'],
        'run_id':decimal(os.environ['GITHUB_RUN_ID']),'run_attempt':1,'corrected_hotels':73,'confirmed_caption_sources':146,'target_xml_occurrences':292,
        'feed_files':3,'caption_edits_this_run':0,'new_posts':0,'price_changes':0,'route_changes':0,'photo_changes':0,
        'input_sha256':prep['input_sha256'],'local_and_public_exact_hashes_verified':True,'files':prep['files'],
        'original_caption_run':p['original_caption']['run_id'],'completion_caption_run':p['completion_caption']['run_id'],
        'observed_at_utc':r['observed_at_utc'],'retry_authorized':False}
    preservation=read(SAFE/'preservation-verification.json')
    need(preservation['ok'] is True and preservation['priced_hotel_pairs_retained']==274 and preservation['target_description_occurrences']==292,'final_preservation_proof_missing')
    out.update({k:preservation[k] for k in ['priced_hotel_pairs_retained','priced_messages_retained','priced_xml_occurrences_retained','all_nondescription_bytes_preserved']})
    from runpy import run_path
    contract=run_path(str(OPS/(PREFIX+'-completion-contract.py')))
    source_plan={**p,'completion_caption':{'run_id':decimal(os.environ['GITHUB_RUN_ID']),'head_sha':os.environ['GITHUB_SHA']}}
    admission=contract['source_admission'](source_plan,{n:(SAFE/n).read_bytes() for n in ['source-gate-receipts.json','content-source-acceptance.json','release-closure-status.json']})
    out.update(admission)
    out['caption_content_source_acceptance_sha256']=digest((SAFE/'caption-content-source-acceptance.json').read_bytes())
    save(SAFE/'summary.json',out);print(json.dumps(out))

if __name__=='__main__':
    command=sys.argv[1]
    if command=='prepare':prepare()
    elif command=='lease':lease(sys.argv[2])
    elif command=='terminal':terminal()
    elif command=='capture':capture(int(sys.argv[2]))
    elif command=='prepared':prepared()
    elif command=='writer':writer(int(sys.argv[2]))
    elif command=='readback':readback(int(sys.argv[2]))
    else:raise RuntimeError('unsupported_feed_phase')
