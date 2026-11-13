"""Local preparation/verification for the existing one-shot feed writer carrier."""
import base64, hashlib, json, os, re, subprocess, sys, zipfile
from pathlib import Path

OPS = Path('tools/direct_demand/operations')
PREFIX = 'commercial-caption-repair-20261113'
PRIVATE = Path(os.environ.get('RUNNER_TEMP', '/nonexistent')) / 'caption-repair-private'
SAFE = Path(os.environ.get('RUNNER_TEMP', '/nonexistent')) / 'caption-repair-safe'
NAMES = ['canary', 'batch001', 'batch002', 'batch003', 'batch004']
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
    p = read(OPS / (PREFIX + '-feed-plan.json'))
    need(p['schema_version'] == 1 and p['ready'] is True, 'feed_plan_not_ready')
    need(p['operation'] == 'commercial-channel-hotel-feeds-20261113-update9', 'feed_operation_changed')
    need(p['current_installed_sha'] == os.environ['EXPECTED_PRODUCTION_SHA'] == '498c5a752cd6f99c2d0e8c343dc5d44b9e80843f', 'current_source_changed')
    need(p['cohort_installed_sha'] == '8d3dfec0e6bce70e16b8717312d5dd6faf954fc1', 'cohort_source_changed')
    need(p['completion_installed_sha'] == '85b500236de471f62fde547e5f799d12509e47c4', 'completion_source_changed')
    need([b['name'] for b in p['operations']] == NAMES and [b['row_count'] for b in p['operations']] == [8, 50, 50, 50, 18], 'operation_scope_changed')
    need([b['installed_sha'] for b in p['operations']] == [p['cohort_installed_sha']]*4+[p['completion_installed_sha']], 'original_source_list_changed')
    for b in p['operations']:
        need(b['operation'] == PREFIX + ('-canary-v1' if b['name']=='canary' else '-'+b['name']+'-v1')
             and re.fullmatch(r'[a-f0-9]{64}', b['manifest_sha256'] or ''), 'original_operation_pin_missing')
    return p

def prepare():
    p = plan()
    need(os.environ['GITHUB_RUN_ATTEMPT'] == '1', 'run_retry_not_authorized')
    need(subprocess.check_output(['git','rev-parse','HEAD'], text=True).strip() == os.environ['GITHUB_SHA'], 'checkout_head_changed')
    for name, expected in p['source_script_pins'].items():
        need(digest((OPS/name).read_bytes()) == expected, 'approved_source_blob_changed:'+name)
    # Reuse the committed completion helper's PREPARE ONLY. No editor invocation.
    historical_env={**os.environ, 'EXPECTED_PRODUCTION_SHA':p['completion_installed_sha']}
    subprocess.run([sys.executable, str(OPS/(PREFIX+'-complete-unstarted-batch.py')), 'prepare'],
        env=historical_env, check=True, stdout=subprocess.PIPE)
    cohort = read(PRIVATE/'approved-cohort.json')
    need(len(cohort['rows']) == 176 and digest((PRIVATE/'approved-cohort.json').read_bytes()) == p['cohort_sha256']
         and cohort['expected_installed_sha'] == p['cohort_installed_sha'], 'immutable_cohort_changed')
    save(SAFE/'feed-plan.json', p)
    print(json.dumps({'approved_hotels':88,'approved_existing_captions':176,'feed_operation':p['operation'],'caption_edit_calls':0}))

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
         and run['actor']['login']=='pyatkoff', 'original_run_binding_changed')
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
             and a['button_text']=='✈️ Узнать стоимость тура', 'receipt_versions_or_content_changed')
        for k in ['body_photo_button_verified','formatting_verified','existing_message_reused','existing_token_resolves_to_current_version']:
            need(a[k] is True, 'receipt_not_verified:'+k)

def terminal():
    p=plan(); original=artifact('original-caption',p['original_caption']); complete=artifact('completion-caption',p['completion_caption'])
    expected={name+s for name in NAMES[:4] for s in ['.started','.result.json']}
    need(set(original)==expected, 'original_batch004_may_have_started')
    jobs=read(PRIVATE/'original-caption-jobs.json')['jobs']
    need(len(jobs)==1 and jobs[0]['id']==p['original_caption']['job_id'], 'original_job_changed')
    steps={s['name']:s for s in jobs[0]['steps']}
    need(all(steps[n+': edit only the approved existing captions once']['conclusion']=='success' for n in NAMES[:4])
         and steps['batch004: edit only the approved existing captions once']['conclusion']=='failure', 'original_terminal_boundary_changed')
    required=expected|{'batch004.started','batch004.result.json','batch004.manifest.json','batch004.prepared-not-executed.manifest.json',
        'completion-provenance.json','summary.json','final-snapshot.json','before-completion-snapshot.json','source-gate-receipts.json'}
    need(required<=set(complete), 'completion_artifact_incomplete')
    for n in expected: need(complete[n]==original[n], 'original_158_receipts_not_preserved')
    old_raw=(PRIVATE/'batch004.manifest.json').read_bytes()
    need(complete['batch004.prepared-not-executed.manifest.json']==old_raw, 'unexecuted_manifest_history_changed')
    final_raw=complete['batch004.manifest.json']; m=json.loads(final_raw); old=json.loads(old_raw); final_binding=p['operations'][4]
    need(digest(final_raw)==final_binding['manifest_sha256'] and m['expected_installed_sha']==p['completion_installed_sha'], 'completion_manifest_changed')
    need({k:v for k,v in m.items() if k not in ['expected_installed_sha','snapshot']}=={k:v for k,v in old.items() if k not in ['expected_installed_sha','snapshot']}, 'completion_scope_or_caption_changed')
    need(m['snapshot']['installed_sha']==p['completion_installed_sha'] and m['snapshot']['post_count']==176
         and m['snapshot']['sha256']==digest(complete['before-completion-snapshot.json']), 'completion_snapshot_binding_changed')
    proof=json.loads(complete['completion-provenance.json'])
    need(proof['original_run_id']==p['original_caption']['run_id'] and proof['original_head']==p['original_caption']['head_sha']
         and proof['original_artifact_id']==p['original_caption']['artifact_id'] and proof['original_artifact_sha256']==p['original_caption']['artifact_sha256']
         and proof['completed_posts_reverified']==158 and proof['unstarted_posts_reverified']==18
         and proof['source_before']==p['cohort_installed_sha'] and proof['source_current']==p['completion_installed_sha']
         and proof['executed_manifest_sha256']==final_binding['manifest_sha256'] and proof['cohort_sha256']==p['cohort_sha256']
         and decimal(proof['completion_run_id'])==p['completion_caption']['run_id']
         and proof['same_operation_id'] is True and proof['earlier_batches_replayed'] is False, 'completion_provenance_changed')
    (PRIVATE/'batch004.manifest.json').write_bytes(final_raw)
    all_receipts={}
    for b in p['operations']:
        raw=(PRIVATE/(b['name']+'.manifest.json')).read_bytes(); manifest=json.loads(raw)
        need(digest(raw)==b['manifest_sha256'] and manifest['expected_installed_sha']==b['installed_sha'], 'per_operation_source_binding_changed')
        r=json.loads(complete[b['name']+'.result.json']);receipt_result(r,manifest,raw,p)
        for a in r['posts']:
            key=a['platform']+':'+str(a['publication_id']);need(key not in all_receipts,'duplicate_terminal_receipt');all_receipts[key]=a
        (SAFE/(b['name']+'.result.json')).write_bytes(complete[b['name']+'.result.json'])
    summary=json.loads(complete['summary.json']); snapshot=json.loads(complete['final-snapshot.json'])
    need(summary['ok'] is True and summary['cohort_sha256']==p['cohort_sha256'] and summary['installed_sha']==p['completion_installed_sha']
         and decimal(summary['run_id'])==p['completion_caption']['run_id'] and summary['run_attempt']==1
         and summary['confirmed_caption_edits']==176 and summary['corrected_hotels']==88 and summary['existing_buttons_and_photos_verified']==176
         and summary['original_completed_posts']==158 and summary['first_execution_remaining_posts']==18
         and summary['earlier_batches_replayed'] is False and summary['final_snapshot_sha256']==digest(complete['final-snapshot.json']), 'terminal_caption_summary_changed')
    cohort=read(PRIVATE/'approved-cohort.json');rows={r['publication_id']:r for r in cohort['rows']}
    need(snapshot['ok'] is True and snapshot['installed_sha']==p['completion_installed_sha'] and snapshot['post_write_attempts']==snapshot['provider_calls']==0
         and len(snapshot['posts'])==len(all_receipts)==176,'terminal_snapshot_scope_changed')
    seen=set()
    for x in snapshot['posts']:
        pid=x['publication_id'];need(pid in rows and pid not in seen,'terminal_snapshot_identity_changed');seen.add(pid)
        r=rows[pid];a=all_receipts[x['platform']+':'+str(pid)]
        for k in ['hotel_id','platform','publication_id','message_id','post_url','source_key','config_version','photo_signature','button_url_sha256']:need(x[k]==r[k],'terminal_snapshot_binding_changed')
        need(x['caption']==r['new_text'] and x['caption_sha256']==r['new_text_sha256'] and x['version_id']==a['version_id']
             and x['attachments_sha256']==a['attachments_sha256'] and x['status']=='sent' and x['button_observed_in_snapshot'] is True
             and x['button_resolves_to_current_post'] is True and x['media_count']==1,'terminal_snapshot_content_changed')
    save(PRIVATE/'confirmed-caption-receipts.json',all_receipts)
    (SAFE/'caption-terminal-summary.json').write_bytes(complete['summary.json'])
    (SAFE/'caption-completion-provenance.json').write_bytes(complete['completion-provenance.json'])
    compile_capture(p)
    print(json.dumps({'original_confirmed':158,'remaining_original_confirmed':18,'all_original_operations_complete':True,'caption_replays':0}))

def php_bundle(template, constants, include_preparation=False):
    sources=[(OPS/'caption-feed-contract.php').read_text()]
    if include_preparation:
        s=(OPS/'caption-feed-preparation.php').read_text()
        required="if (!function_exists('caption_feed_render')) require_once __DIR__.'/caption-feed-contract.php';"
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
    need(len(seen)==176,'captured_receipts_incomplete')
    print(json.dumps({'fresh_files':3,'confirmed_original_receipts':176,'capture_lock_released':True}))

def prepared():
    p=plan(); prep=read(SAFE/'preparation.json'); saved=(SAFE/'actual-feed-input.json').read_bytes();need(saved.endswith(b'\n'),'input_file_incomplete');raw=saved[:-1];i=json.loads(raw)
    need(digest(raw)==prep['input_sha256'] and digest(saved)==prep['saved_input_file_sha256'] and digest((PRIVATE/'writer.php').read_bytes())==prep['compiled_writer_sha256']
         and i['operation']==p['operation'] and i['schema_version']==5 and len(i['caption_updates'])==176,'prepared_writer_binding_changed')
    files=[{k:f[k] for k in ['name','sha256','bytes','rows','expected_previous_sha256']} for f in i['files']]
    need([f['name'] for f in files]==FEEDS and files==prep['files'],'prepared_file_scope_changed')
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
        'run_id':decimal(os.environ['GITHUB_RUN_ID']),'run_attempt':1,'corrected_hotels':88,'confirmed_caption_sources':176,'target_xml_occurrences':352,
        'feed_files':3,'caption_edits_this_run':0,'new_posts':0,'price_changes':0,'route_changes':0,'photo_changes':0,
        'input_sha256':prep['input_sha256'],'local_and_public_exact_hashes_verified':True,'files':prep['files'],
        'original_caption_run':p['original_caption']['run_id'],'completion_caption_run':p['completion_caption']['run_id'],
        'observed_at_utc':r['observed_at_utc'],'retry_authorized':False}
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
