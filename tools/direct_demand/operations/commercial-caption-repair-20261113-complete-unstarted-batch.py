"""Finish only the original, never-started batch004 after the release changed."""
import base64, gzip, hashlib, json, os, sys, zipfile
from pathlib import Path

PREFIX = 'commercial-caption-repair-20261113'
OLD = '8d3dfec0e6bce70e16b8717312d5dd6faf954fc1'
CURRENT = '85b500236de471f62fde547e5f799d12509e47c4'
OPS = Path('tools/direct_demand/operations')
PRIVATE = Path(os.environ['RUNNER_TEMP']) / 'caption-repair-private'
SAFE = Path(os.environ['RUNNER_TEMP']) / 'caption-repair-safe'
NAMES = ['canary', 'batch001', 'batch002', 'batch003', 'batch004']
def sha(b): return hashlib.sha256(b).hexdigest()
def require(ok, why):
    if not ok: raise RuntimeError(why)
def save(path, value): path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + '\n')
def read(path): return json.loads(path.read_bytes())
def compile_php(source, values):
    require(all("'" not in str(v) for v in values.values()), 'invalid_constant')
    constants = '\n'.join("define('%s', '%s');" % (k, v) for k, v in values.items())
    return source.replace('declare(strict_types=1);', 'declare(strict_types=1);\n' + constants, 1)

def prepare():
    require(os.environ['EXPECTED_PRODUCTION_SHA'] == CURRENT, 'source_not_approved')
    PRIVATE.mkdir(mode=0o700); SAFE.mkdir(mode=0o700)
    raw = (OPS / (PREFIX + '-inputs.json.gz.b64')).read_bytes()
    require(sha(raw) == '66f3238c74e256967e6c50b8e4abd2d0a7c865bc8b881ea99bc40cd22e8305f0', 'original_inputs_changed')
    files = json.loads(gzip.decompress(base64.b64decode(raw)))
    require(set(files) == {'approved-cohort.json', 'index.json', 'current-snapshot.json'}, 'original_files_changed')
    for name, text in files.items(): (PRIVATE / name).write_text(text)
    cohort, index, snapshot = (read(PRIVATE / f) for f in ['approved-cohort.json','index.json','current-snapshot.json'])
    require(sha((PRIVATE/'approved-cohort.json').read_bytes()) == index['cohort_sha256'] == '070c9c2d084ae2b6199d8ece0e037b15ffca22c6ef7f9f5d444a1aba5b8dd456', 'cohort_changed')
    byid = {r['publication_id']: r for r in cohort['rows']}; previous = None
    for b in index['batches']:
        m = {'schema_version':1,'operation':b['operation'],'expected_installed_sha':OLD,'update_kind':'approved_existing_caption_quality_revision','snapshot':{'operation':snapshot['operation'],'installed_sha':OLD,'post_count':176,'sha256':cohort['source_read_sha256'],'observed_at_utc':snapshot['observed_at_utc']},'button_text':'✈️ Узнать стоимость тура','throttle_microseconds':3200000,'row_count':b['post_count'],'rows':[byid[i] for i in b['publication_ids']],'quality_cohort_sha256':index['cohort_sha256'],'quality_authorization':cohort['authorization'],'batch_index':b['index'],'batch_kind':'canary' if b['index']==0 else 'batch','predecessor':previous}
        save(PRIVATE/(b['name']+'.manifest.json'), m)
        require(sha((PRIVATE/(b['name']+'.manifest.json')).read_bytes()) == b['manifest_sha256'], 'original_manifest_changed')
        previous = {'operation': m['operation'], 'manifest_sha256': b['manifest_sha256']}
    executor = (OPS/(PREFIX+'-executor.php')).read_bytes()
    require(sha(executor) == '20e945d5c421dfdc69c0182f9529b6c1d7cbfa743a4bb6a9f60cfc3882e9a9fc', 'original_executor_changed')
    reader = (OPS/(PREFIX+'-read-scope.php')).read_text(); scope=(OPS/(PREFIX+'-scope.json')).read_bytes()
    (PRIVATE/'reader.php').write_text(compile_php(reader, {'EXPECTED_INSTALLED_SHA':CURRENT,'APPROVED_MANIFEST_SHA256':sha(scope),'MANIFEST_BASE64':base64.b64encode(scope).decode()}))
    print(json.dumps({'prepared':True,'only_write_operation':PREFIX+'-batch004-v1','unstarted_posts':18}))

def original():
    r=read(PRIVATE/'original-run.json'); j=read(PRIVATE/'original-jobs.json')['jobs']
    require(r['id']==43327630710 and r['head_sha']=='ee73041f93353b002c5561ead612603b2d1ff134' and r['run_attempt']==1 and r['status']=='completed' and r['conclusion']=='failure','original_run_changed')
    require(len(j)==1 and j[0]['id']==131564009766,'original_job_changed')
    steps={s.get('name'):s for s in j[0]['steps']}
    for name in NAMES[:-1]: require(steps[name+': edit only the approved existing captions once']['conclusion']=='success','original_completed_batch_unconfirmed')
    require(steps['batch004: edit only the approved existing captions once']['conclusion']=='failure','original_stop_changed')
    raw=(PRIVATE/'original-result.zip').read_bytes()
    require(sha(raw)=='868fec5bcb1da175358ac60ce73eb3d40ae19c41aa41b951cc29e07550f47e6f','original_result_changed')
    with zipfile.ZipFile(PRIVATE/'original-result.zip') as z:
        expected={name+suffix for name in NAMES[:-1] for suffix in ['.started','.result.json']}
        require(set(z.namelist())==expected and len(z.namelist())==8,'batch004_may_have_started_stop')
        for name in sorted(expected): (SAFE/name).write_bytes(z.read(name))
    total=0
    for name in NAMES[:-1]:
        result=read(SAFE/(name+'.result.json')); manifest=read(PRIVATE/(name+'.manifest.json'))
        require(result['validated'] is True and result['operation']==manifest['operation'] and result['manifest_sha256']==sha((PRIVATE/(name+'.manifest.json')).read_bytes()),'original_result_binding_changed')
        require(len(result['posts'])==manifest['row_count']==result['confirmed_posts'],'original_count_changed')
        total+=len(result['posts'])
    require(total==158,'completed_count_changed')
    print(json.dumps({'original_confirmed_posts':158,'last_operation_started':False,'replay_authorized':False}))

def before(status):
    cohort=read(PRIVATE/'approved-cohort.json'); expected={r['publication_id']:r for r in cohort['rows']}
    current=read(SAFE/'before-completion-snapshot.json')
    require(status==0 and current['ok'] is True and current['installed_sha']==CURRENT and current['provider_calls']==current['post_write_attempts']==0,'fresh_read_failed')
    completed={p['publication_id']:p for name in NAMES[:-1] for p in read(SAFE/(name+'.result.json'))['posts']}
    require(len(current['posts'])==176 and len(completed)==158,'fresh_scope_changed')
    seen=set()
    for p in current['posts']:
        pid=p['publication_id']; require(pid in expected and pid not in seen,'fresh_identity_changed');seen.add(pid);x=expected[pid]
        for key in ['hotel_id','platform','publication_id','message_id','post_url','source_key','config_version','photo_signature','button_url_sha256']: require(p[key]==x[key],'fresh_binding_changed_'+key)
        require(p['status']=='sent' and p['button_resolves_to_current_post'] is True and p['media_count']==1,'fresh_post_not_ready')
        if pid in completed:
            receipt=completed[pid]
            require(p['caption']==x['new_text'] and p['caption_sha256']==x['new_text_sha256'] and p['version_id']==receipt['version_id'] and p['attachments_sha256']==receipt['attachments_sha256'] and p['button_observed_in_snapshot'] is True,'completed_post_changed')
        else:
            require(p['caption']==x['old_text'] and p['caption_sha256']==x['old_text_sha256'] and p['version_id']==x['version_id'] and p['attachments_sha256']==x['attachments_sha256'] and p['button_observed_in_snapshot']==x['button_observed_in_snapshot'],'unstarted_post_changed')
    original_bytes=(PRIVATE/'batch004.manifest.json').read_bytes();m=json.loads(original_bytes)
    require({r['publication_id'] for r in m['rows']}==set(expected)-set(completed) and m['operation']==PREFIX+'-batch004-v1','last_scope_changed')
    (SAFE/'batch004.prepared-not-executed.manifest.json').write_bytes(original_bytes)
    m['expected_installed_sha']=CURRENT
    m['snapshot']={'operation':current['operation'],'installed_sha':CURRENT,'post_count':176,'sha256':sha((SAFE/'before-completion-snapshot.json').read_bytes()),'observed_at_utc':current['observed_at_utc']}
    save(PRIVATE/'batch004.manifest.json',m);newraw=(PRIVATE/'batch004.manifest.json').read_bytes();(SAFE/'batch004.manifest.json').write_bytes(newraw)
    executor=(OPS/(PREFIX+'-executor.php')).read_text()
    defs={'EXPECTED_INSTALLED_SHA':CURRENT,'APPROVED_UPDATE_OPERATION':m['operation'],'APPROVED_MANIFEST_SHA256':sha(newraw),'MANIFEST_BASE64':base64.b64encode(newraw).decode(),'REPAIR_COHORT_SHA256':m['quality_cohort_sha256'],'REPAIR_BATCH_INDEX':'4','REPAIR_PUBLICATION_IDS_BASE64':base64.b64encode(json.dumps([r['publication_id'] for r in m['rows']]).encode()).decode()}
    (PRIVATE/'batch004.php').write_text(compile_php(executor,defs))
    proof={'original_run_id':43327630710,'original_head':'ee73041f93353b002c5561ead612603b2d1ff134','original_artifact_id':13994670839,'original_artifact_sha256':'868fec5bcb1da175358ac60ce73eb3d40ae19c41aa41b951cc29e07550f47e6f','completed_posts_reverified':158,'unstarted_posts_reverified':18,'operation':m['operation'],'source_before':OLD,'source_current':CURRENT,'prepared_manifest_not_executed_sha256':sha(original_bytes),'executed_manifest_sha256':sha(newraw),'completion_run_id':int(os.environ['GITHUB_RUN_ID']),'cohort_sha256':m['quality_cohort_sha256'],'publisher_blob_count':22,'same_operation_id':True,'earlier_batches_replayed':False}
    save(SAFE/'completion-provenance.json',proof);print(json.dumps(proof))

def final(status):
    cohort=read(PRIVATE/'approved-cohort.json');expected={p['publication_id']:p for p in cohort['rows']};confirmed={}
    for name in NAMES:
        r=read(SAFE/(name+'.result.json'));require(r['validated'] is True,'prior_result_not_validated')
        for p in r['posts']: require(p['publication_id'] not in confirmed,'duplicate_receipt');confirmed[p['publication_id']]=p
    raw=(SAFE/'final-snapshot.json').read_bytes();r=json.loads(raw)
    require(status==0 and r['ok'] is True and r['installed_sha']==CURRENT,'final_read_failed')
    require(r['post_write_attempts']==r['provider_calls']==0 and len(r['posts'])==len(expected)==len(confirmed)==176,'final_scope_changed')
    seen=set()
    for p in r['posts']:
        pid=p['publication_id'];require(pid in expected and pid not in seen,'final_identity_changed');seen.add(pid);x=expected[pid];receipt=confirmed[pid]
        for k in ['hotel_id','platform','publication_id','message_id','post_url','source_key','config_version','photo_signature','button_url_sha256']: require(p[k]==x[k],'final_binding_changed_'+k)
        require(p['status']=='sent' and p['button_observed_in_snapshot'] is True and p['button_resolves_to_current_post'] is True and p['media_count']==1,'final_publication_unconfirmed')
        require(p['caption']==x['new_text'] and p['caption_sha256']==x['new_text_sha256'] and p['version_id']==receipt['version_id'] and p['attachments_sha256']==receipt['attachments_sha256'],'final_current_version_changed')
    out={'ok':True,'operation':PREFIX,'source_read_run_id':cohort['source_read_run_id'],'cohort_sha256':sha((PRIVATE/'approved-cohort.json').read_bytes()),'installed_sha':CURRENT,'observed_at_utc':r['observed_at_utc'],'confirmed_caption_edits':176,'corrected_hotels':88,'price_blocks_retained':22,'timestamp_display_conversions':2,'new_posts_created':0,'existing_buttons_and_photos_verified':176,'final_snapshot_sha256':sha(raw),'run_id':os.environ['GITHUB_RUN_ID'],'run_attempt':int(os.environ['GITHUB_RUN_ATTEMPT']),'original_completed_run_id':43327630710,'original_completed_posts':158,'first_execution_remaining_posts':18,'earlier_batches_replayed':False}
    save(SAFE/'summary.json',out);print(json.dumps(out))

if __name__=='__main__':
    command=sys.argv[1]
    if command=='prepare':prepare()
    elif command=='original':original()
    elif command=='before':before(int(sys.argv[2]))
    elif command=='final':final(int(sys.argv[2]))
    else:raise RuntimeError('unsupported_command')
