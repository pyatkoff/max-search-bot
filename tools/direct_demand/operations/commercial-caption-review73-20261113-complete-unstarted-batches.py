"""Adapt the accepted completion88 helper to the two original, never-started batches.

The PHP executor, result verifier and reader are unchanged. This helper only
authenticates prior evidence, rebinds unstarted manifests to a fresh full read,
and combines the actual receipts without replaying earlier batches.
"""
import base64, copy, gzip, hashlib, json, os, re, sys, zipfile
from pathlib import Path

PREFIX = 'commercial-caption-review73-20261113'
OLD = '5fbb61990706ae4289e539df2c26c60e409032f4'
CURRENT = '8d6ac35dd17614b2cadb3aa3af7637c74280c01c'
ORIGINAL_RUN = 43455664622
ORIGINAL_HEAD = 'ea88349442e926ea39b409347eb14635eb891760'
ORIGINAL_JOB = 131989003936
ORIGINAL_ARTIFACT = 14054371257
ORIGINAL_ZIP_SHA = 'ca308824dcf688d179d09705ffbc90752be6bec28124330a8bb842adb343e596'
COHORT_SHA = '4d8f9340e3fd5b62d7c5828bd889a9828d87c91a343a98b2d5db8c5de46b9fab'
OPS = Path('tools/direct_demand/operations')
PRIVATE = Path(os.environ['RUNNER_TEMP']) / 'caption-repair-private'
SAFE = Path(os.environ['RUNNER_TEMP']) / 'caption-repair-safe'
NAMES = ['canary','batch001','batch002','batch003']
COMPLETED = NAMES[:2]
UNSTARTED = NAMES[2:]
COUNTS = [8,46,46,46]
MANIFEST_SHAS = {
    'canary':'65595a177031b4240ff0dcf757870ba2dc5a01132789e84bcd64080ce64ab14a',
    'batch001':'870960c2dd9b17c43b07a83cd6fe484e3a41effe771a06537aa686646053b94d',
    'batch002':'ab928395858e39eecf579eb9cf1be5678c9353ffa12047a5254ce8239c31cde0',
    'batch003':'24d3a28ffeae94ef7839c5a9331065d143fa74b393683f1cfd1f0e118408be5e',
}
SOURCE_PINS = {
    '-edit-inputs.json.gz.b64':'3e1fe8c0e002a15adafd758c70e2eabe251fbad10b6f37ed9dd3c204a343da36',
    '-executor.php':'cb30dbd22432224bed30d0e8c0121b58a393ffcbd3263a09614b7d083fa88c2e',
    '-verify.py':'8273e0884d1711db99e0e5f0ee3a99f57324fc16bd8c135c1dba6e1918000d5e',
    '-read-scope.php':'5158f1e4157984cce98acc085329a0227f6b78c93829bd5984d247e8b629c95e',
    '-scope.json':'9ee88848bb9c5cd0c238267acdaddbbf76f1365173a0e6bf2d58ce0e9c184ea6',
}

def sha(raw): return hashlib.sha256(raw).hexdigest()
def require(ok, why):
    if not ok: raise RuntimeError(why)
def save(path, value): path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + '\n')
def read(path): return json.loads(path.read_bytes())
def compile_php(source, values):
    require(all("'" not in str(v) and '\\' not in str(v) for v in values.values()), 'invalid_constant')
    constants = '\n'.join("define('%s', '%s');" % (k, v) for k, v in values.items())
    require(source.count('declare(strict_types=1);') == 1, 'php_header_changed')
    return source.replace('declare(strict_types=1);', 'declare(strict_types=1);\n' + constants, 1)

def prepare():
    require(os.environ['EXPECTED_PRODUCTION_SHA'] == CURRENT and os.environ['GITHUB_RUN_ATTEMPT'] == '1', 'source_or_attempt_not_approved')
    PRIVATE.mkdir(mode=0o700); SAFE.mkdir(mode=0o700)
    for suffix, digest in SOURCE_PINS.items(): require(sha((OPS/(PREFIX+suffix)).read_bytes()) == digest, 'unchanged_source_pin_failed:'+suffix)
    raw = (OPS/(PREFIX+'-edit-inputs.json.gz.b64')).read_bytes()
    files = json.loads(gzip.decompress(base64.b64decode(raw)))
    require(set(files) == {'approved-cohort.json','source-snapshot.json','read-proof.json',*(name+'.manifest.json' for name in NAMES)}, 'original_files_changed')
    for name, text in files.items(): (PRIVATE/name).write_text(text)
    cohort = read(PRIVATE/'approved-cohort.json'); snapshot = read(PRIVATE/'source-snapshot.json')
    require(sha((PRIVATE/'approved-cohort.json').read_bytes()) == COHORT_SHA, 'cohort_changed')
    require(sha((PRIVATE/'source-snapshot.json').read_bytes()) == '7f3f2d28ffbb21762878f72caff5c2cd6a0433950168dc0bdbd3aa9f1a58831e', 'original_read_changed')
    require(cohort['expected_installed_sha'] == snapshot['installed_sha'] == OLD and cohort['source_read_run_id'] == 43452943496, 'original_source_changed')
    allrows = []; predecessor = None
    for index, name in enumerate(NAMES):
        raw = (PRIVATE/(name+'.manifest.json')).read_bytes(); m = json.loads(raw)
        require(sha(raw) == MANIFEST_SHAS[name] and m['expected_installed_sha'] == OLD
            and m['row_count'] == len(m['rows']) == COUNTS[index] and m['batch_index'] == index
            and m['predecessor'] == predecessor and m['quality_cohort_sha256'] == COHORT_SHA, 'original_manifest_changed')
        predecessor = {'operation':m['operation'],'manifest_sha256':sha(raw)}
        allrows.extend(m['rows'])
    require(allrows == cohort['rows'] and len(allrows) == len({r['publication_id'] for r in allrows}) == 146, 'original_exact_scope_changed')
    reader = (OPS/(PREFIX+'-read-scope.php')).read_text(); scope = (OPS/(PREFIX+'-scope.json')).read_bytes()
    (PRIVATE/'reader.php').write_text(compile_php(reader, {'EXPECTED_INSTALLED_SHA':CURRENT,
        'APPROVED_MANIFEST_SHA256':sha(scope),'MANIFEST_BASE64':base64.b64encode(scope).decode()}))
    print(json.dumps({'prepared':True,'only_write_operations':[PREFIX+'-'+n+'-v1' for n in UNSTARTED],
        'unstarted_posts':92,'completed_posts_to_reverify':54,'earlier_executors_compiled':0}))

def checked_result(name):
    result = read(SAFE/(name+'.result.json')); m = read(PRIVATE/(name+'.manifest.json'))
    require(result['validated'] is True and result['ssh_status'] == 0 and result['retry_authorized'] is False
        and result.get('runtime_error') is None and result['operation'] == m['operation']
        and result['manifest_sha256'] == sha((PRIVATE/(name+'.manifest.json')).read_bytes())
        and re.fullmatch('[a-f0-9]{64}', result['runtime_sha256']), 'actual_result_binding_changed:'+name)
    require(result['confirmed_posts'] == len(result['posts']) == m['row_count'], 'actual_result_count_changed:'+name)
    byid = {r['publication_id']:r for r in m['rows']}; seen = set()
    for p in result['posts']:
        pid = p['publication_id']; require(pid in byid and pid not in seen, 'actual_receipt_identity_changed'); seen.add(pid); x = byid[pid]
        for key in ['hotel_id','platform','publication_id','message_id','post_url','photo_signature','button_url_sha256','source_key']:
            require(p[key] == x[key], 'actual_receipt_binding_changed:'+key)
        require(p['previous_version_id'] == x['version_id'] and type(p['version_id']) is int and p['version_id'] != x['version_id']
            and p['previous_text_sha256'] == x['old_text_sha256'] and p['text_sha256'] == x['new_text_sha256']
            and p['previous_attachments_sha256'] == x['attachments_sha256'] and re.fullmatch('[a-f0-9]{64}',p['attachments_sha256']), 'actual_receipt_preimage_changed')
        require(p['state'] == 'sent' and p['button_text'] == m['button_text']
            and p.get('monitor_price_record_id') == x['monitor_price_record_id']
            and p.get('caption_correction_operation') == m['operation'], 'actual_receipt_lineage_changed')
        for key in ['body_photo_button_verified','formatting_verified','existing_message_reused','existing_token_resolves_to_current_version']:
            require(p[key] is True, 'actual_receipt_unverified:'+key)
    expected_run = ORIGINAL_RUN if name in COMPLETED else int(os.environ['GITHUB_RUN_ID'])
    require((SAFE/(name+'.started')).read_bytes() == f'{expected_run}/1\n'.encode(), 'actual_started_run_changed')
    return result

def original():
    r = read(PRIVATE/'original-run.json'); j = read(PRIVATE/'original-jobs.json')['jobs']; a = read(PRIVATE/'original-artifact.json')
    require(r['id'] == ORIGINAL_RUN and r['head_sha'] == ORIGINAL_HEAD and r['run_attempt'] == 1
        and r['event'] == 'push' and r['head_branch'] == 'audit/content100-bot-publication-20261101'
        and r['path'] == '.github/workflows/content100-bot-publication-20261101.yml'
        and r['status'] == 'completed' and r['conclusion'] == 'failure', 'original_run_changed')
    require(len(j) == 1 and j[0]['id'] == ORIGINAL_JOB and j[0]['run_id'] == ORIGINAL_RUN
        and j[0]['head_sha'] == ORIGINAL_HEAD and j[0]['run_attempt'] == 1
        and j[0]['status'] == 'completed' and j[0]['conclusion'] == 'failure', 'original_job_changed')
    steps = {s['name']:s for s in j[0]['steps']}
    for name in ['Compile and validate four exact finite caption batches without sends','Authenticate original read artifact and exact preimage',
                 'Verify nine original source gates and the unchanged 22 publishing pins','Prepare existing server connection','Pin existing server host-key continuity']:
        require(steps[name]['conclusion'] == 'success', 'original_gate_unconfirmed')
    require(steps['Apply canary and three sequential batches once with exact predecessors']['conclusion'] == 'failure'
        and steps['Read back146 revised captions and retained photo/button identities']['conclusion'] == 'skipped'
        and steps['Retain actual edit receipts and final snapshots']['conclusion'] == 'success', 'original_stop_changed')
    require(a['id'] == ORIGINAL_ARTIFACT and a['name'] == PREFIX+'-result' and a['expired'] is False
        and a['digest'] == 'sha256:'+ORIGINAL_ZIP_SHA and a['workflow_run']['id'] == ORIGINAL_RUN
        and a['workflow_run']['head_sha'] == ORIGINAL_HEAD, 'original_artifact_identity_changed')
    raw = (PRIVATE/'original-result.zip').read_bytes(); require(sha(raw) == ORIGINAL_ZIP_SHA, 'original_result_changed')
    expected = {name+suffix for name in COMPLETED for suffix in ['.started','.result.json']}
    with zipfile.ZipFile(PRIVATE/'original-result.zip') as z:
        require(len(z.infolist()) == 4 and set(z.namelist()) == expected
            and all(i.file_size < 5000000 for i in z.infolist()), 'unstarted_batch_may_have_started_stop')
        for name in sorted(expected): (SAFE/name).write_bytes(z.read(name))
    outputs = []; lograw = (PRIVATE/'original-job-log.txt').read_bytes(); lines = lograw.decode('utf-8-sig').replace('\r\n','\n').splitlines()
    for index, line in enumerate(lines):
        matched = re.match(r'^\d{4}-\d\d-\d\dT\S+Z (\{.*\})$', line)
        if not matched: continue
        try: value = json.loads(matched.group(1))
        except json.JSONDecodeError: continue
        if isinstance(value,dict) and value.get('operation','').startswith(PREFIX) and 'validated' in value: outputs.append((index,value))
    require(len(outputs) == 2 and [v['operation'] for _,v in outputs] == [PREFIX+'-'+n+'-v1' for n in COMPLETED]
        and [v.get('confirmed_posts') for _,v in outputs] == [8,46] and all(v.get('validated') is True for _,v in outputs), 'original_actual_output_sequence_changed')
    after = '\n'.join(lines[outputs[-1][0]+1:])
    require('Traceback (most recent call last)' in after and 'File "<stdin>", line 3' in after and 'AssertionError' in after, 'original_local_guard_failure_unconfirmed')
    validated = [checked_result(name) for name in COMPLETED]
    require(sum(x['confirmed_posts'] for x in validated) == 54, 'completed_count_changed')
    (SAFE/'original-partial.zip').write_bytes(raw)
    for name in ['original-run.json','original-jobs.json','original-artifact.json']: (SAFE/name).write_bytes((PRIVATE/name).read_bytes())
    proof = {'operation':PREFIX+'-original-failure-proof-v1','original_run_id':ORIGINAL_RUN,'original_head_sha':ORIGINAL_HEAD,
        'original_job_id':ORIGINAL_JOB,'original_run_attempt':1,'original_artifact_id':ORIGINAL_ARTIFACT,
        'original_artifact_sha256':ORIGINAL_ZIP_SHA,'validated_batches':[{'name':n,'operation':v['operation'],'confirmed_posts':v['confirmed_posts']} for n,v in zip(COMPLETED,validated)],
        'completed_posts':54,'unstarted_batch_names':UNSTARTED,'no_unstarted_artifact_members':True,
        'traceback_stdin_line':3,'original_job_log_sha256':sha(lograw)}
    save(SAFE/'original-failure-proof.json',proof)
    print(json.dumps({'original_confirmed_posts':54,'unstarted_operations':UNSTARTED,'earlier_batches_replayed':False}))

def before(status):
    cohort = read(PRIVATE/'approved-cohort.json'); expected = {r['publication_id']:r for r in cohort['rows']}
    currentraw = (SAFE/'before-completion-snapshot.json').read_bytes(); current = json.loads(currentraw)
    require(status == 0 and current['ok'] is True and current['installed_sha'] == CURRENT
        and current['operation'] == PREFIX+'-read-v1' and current['provider_calls'] == 73
        and current['post_write_attempts'] == 0 and current['monitor_pending'] is False, 'fresh_read_failed')
    completed = {p['publication_id']:p for name in COMPLETED for p in checked_result(name)['posts']}
    source = {p['publication_id']:p for p in read(PRIVATE/'source-snapshot.json')['posts']}
    require(len(current['posts']) == len(expected) == 146 and len(completed) == 54, 'fresh_scope_changed')
    seen = set()
    for p in current['posts']:
        pid = p['publication_id']; require(pid in expected and pid not in seen, 'fresh_identity_changed'); seen.add(pid); x = expected[pid]
        for key in ['hotel_id','platform','publication_id','message_id','post_url','source_key','config_version','photo_signature','button_url_sha256']:
            require(p[key] == x[key], 'fresh_binding_changed:'+key)
        require(p['status'] == 'sent' and p['button_resolves_to_current_post'] is True and p['media_count'] == 1
            and p['provider_live_verified'] is (p['platform'] == 'max') and p.get('monitor_price_record_id') == x['monitor_price_record_id'], 'fresh_post_not_ready')
        if pid in completed:
            receipt = completed[pid]
            require(p['caption'] == x['new_text'] and p['caption_sha256'] == x['new_text_sha256']
                and p['version_id'] == receipt['version_id'] and p['attachments_sha256'] == receipt['attachments_sha256']
                and p['button_observed_in_snapshot'] is True and p.get('caption_correction_operation') == receipt['caption_correction_operation'], 'completed_post_changed')
        else:
            require(p['caption'] == x['old_text'] and p['caption_sha256'] == x['old_text_sha256']
                and p['version_id'] == x['version_id'] and p['attachments_sha256'] == x['attachments_sha256']
                and p['button_observed_in_snapshot'] == x['button_observed_in_snapshot']
                and p.get('caption_correction_operation') == source[pid].get('caption_correction_operation'), 'unstarted_post_changed')
    remaining = set(expected)-set(completed); allremaining = set(); previous = {'operation':PREFIX+'-batch001-v1','manifest_sha256':MANIFEST_SHAS['batch001']}
    executor = (OPS/(PREFIX+'-executor.php')).read_text()
    for name in UNSTARTED:
        originalraw = (PRIVATE/(name+'.manifest.json')).read_bytes(); m = json.loads(originalraw); original = copy.deepcopy(m)
        require(sha(originalraw) == MANIFEST_SHAS[name] and not (SAFE/(name+'.started')).exists(), 'unstarted_preparation_changed')
        ids = {r['publication_id'] for r in m['rows']}
        require(len(ids) == 46 and ids <= remaining and not ids & allremaining, 'unstarted_exact_scope_changed'); allremaining |= ids
        (SAFE/(name+'.prepared-not-executed.manifest.json')).write_bytes(originalraw)
        m['expected_installed_sha'] = CURRENT
        m['snapshot'] = {'operation':current['operation'],'installed_sha':CURRENT,'post_count':146,'sha256':sha(currentraw),'observed_at_utc':current['observed_at_utc']}
        m['predecessor'] = previous
        allowed = {'expected_installed_sha','snapshot'} | ({'predecessor'} if name == 'batch003' else set())
        require({k:v for k,v in m.items() if k not in allowed} == {k:v for k,v in original.items() if k not in allowed}, 'rebound_changed_unapproved_fields')
        require(m['predecessor']['operation'] == original['predecessor']['operation'], 'rebound_predecessor_operation_changed')
        save(PRIVATE/(name+'.manifest.json'),m); newraw = (PRIVATE/(name+'.manifest.json')).read_bytes(); (SAFE/(name+'.manifest.json')).write_bytes(newraw)
        defs = {'EXPECTED_INSTALLED_SHA':CURRENT,'APPROVED_UPDATE_OPERATION':m['operation'],'APPROVED_MANIFEST_SHA256':sha(newraw),
            'MANIFEST_BASE64':base64.b64encode(newraw).decode(),'REPAIR_COHORT_SHA256':m['quality_cohort_sha256'],
            'REPAIR_BATCH_INDEX':str(m['batch_index']),'REPAIR_PUBLICATION_IDS_BASE64':base64.b64encode(json.dumps([r['publication_id'] for r in m['rows']]).encode()).decode()}
        compiled = compile_php(executor,defs); (PRIVATE/(name+'.php')).write_text(compiled)
        pure = compiled.split("$out = ['operation'",1)[0]
        require(pure != compiled, 'executor_validation_boundary_changed')
        (PRIVATE/(name+'-validate.php')).write_text(pure+"$m=json_decode(base64_decode(MANIFEST_BASE64,true),true,128,JSON_THROW_ON_ERROR); validateManifest($m); echo 'MANIFEST_VALIDATED',PHP_EOL;")
        previous = {'operation':m['operation'],'manifest_sha256':sha(newraw)}
    require(allremaining == remaining and len(allremaining) == 92, 'remaining_scope_incomplete')
    proof = provenance('prepared')
    save(SAFE/'completion-plan-provenance.json',proof); print(json.dumps(proof))

def provenance(phase):
    batches = []
    for name in NAMES:
        mraw = (PRIVATE/(name+'.manifest.json')).read_bytes(); m = json.loads(mraw); original = name in COMPLETED
        has_result = phase == 'confirmed' or original
        batches.append({'name':name,'operation':m['operation'],'post_count':m['row_count'],
            'execution_run_id':ORIGINAL_RUN if original else int(os.environ['GITHUB_RUN_ID']),
            'execution_head_sha':ORIGINAL_HEAD if original else os.environ['GITHUB_SHA'],'run_attempt':1,
            'installed_sha':OLD if original else CURRENT,'original_manifest_sha256':MANIFEST_SHAS[name],
            'executed_manifest_sha256':sha(mraw),'predecessor':m['predecessor'],
            'result_sha256':sha((SAFE/(name+'.result.json')).read_bytes()) if has_result else None,
            'started_sha256':sha((SAFE/(name+'.started')).read_bytes()) if has_result else None})
    return {'schema_version':1,'operation':PREFIX+'-completion-v1','phase':phase,'cohort_sha256':COHORT_SHA,
        'source_read_run_id':43452943496,'source_before':OLD,'source_current':CURRENT,
        'original_run_id':ORIGINAL_RUN,'original_head_sha':ORIGINAL_HEAD,'original_job_id':ORIGINAL_JOB,'original_run_attempt':1,
        'original_artifact_id':ORIGINAL_ARTIFACT,'original_artifact_sha256':ORIGINAL_ZIP_SHA,
        'completed_posts_reverified':54,'unstarted_posts_reverified':92,
        'before_completion_snapshot_sha256':sha((SAFE/'before-completion-snapshot.json').read_bytes()),
        'completion_run_id':int(os.environ['GITHUB_RUN_ID']),'completion_head_sha':os.environ['GITHUB_SHA'],'completion_run_attempt':1,
        'same_operation_ids':True,'earlier_batches_replayed':False,'publisher_blob_count':22,
        'source_admission':'existing_authorized_content_push','release_closure_status':'not_accepted',
        'source_gate_receipts_sha256':sha((SAFE/'source-gate-receipts.json').read_bytes()),
        'content_source_acceptance_sha256':sha((SAFE/'content-source-acceptance.json').read_bytes()),
        'release_closure_status_sha256':sha((SAFE/'release-closure-status.json').read_bytes()),'batches':batches}

def final(status):
    cohort = read(PRIVATE/'approved-cohort.json'); expected = {p['publication_id']:p for p in cohort['rows']}; confirmed = {}
    for name in NAMES:
        result = checked_result(name)
        for p in result['posts']: require(p['publication_id'] not in confirmed,'duplicate_receipt'); confirmed[p['publication_id']] = p
    raw = (SAFE/'final-snapshot.json').read_bytes(); r = json.loads(raw)
    require(status == 0 and r['ok'] is True and r['installed_sha'] == CURRENT and r['operation'] == PREFIX+'-read-v1'
        and r['monitor_pending'] is False, 'final_read_failed')
    require(r['post_write_attempts'] == 0 and r['provider_calls'] == 73 and len(r['posts']) == len(expected) == len(confirmed) == 146, 'final_scope_changed')
    seen = set()
    for p in r['posts']:
        pid = p['publication_id']; require(pid in expected and pid not in seen,'final_identity_changed'); seen.add(pid); x = expected[pid]; receipt = confirmed[pid]
        for key in ['hotel_id','platform','publication_id','message_id','post_url','source_key','config_version','photo_signature','button_url_sha256']:
            require(p[key] == x[key], 'final_binding_changed:'+key)
        require(p['status'] == 'sent' and p['button_observed_in_snapshot'] is True and p['button_resolves_to_current_post'] is True
            and p['media_count'] == 1 and p['provider_live_verified'] is (p['platform'] == 'max'), 'final_publication_unconfirmed')
        require(p['caption'] == x['new_text'] and p['caption_sha256'] == x['new_text_sha256'] and sha(p['caption'].encode()) == p['caption_sha256']
            and p['version_id'] == receipt['version_id'] and p['attachments_sha256'] == receipt['attachments_sha256']
            and p.get('monitor_price_record_id') == x['monitor_price_record_id']
            and p.get('caption_correction_operation') == receipt['caption_correction_operation'], 'final_current_version_changed')
    proof = provenance('confirmed'); save(SAFE/'completion-provenance.json',proof)
    out = {'ok':True,'operation':PREFIX+'-edit-v1','source_read_run_id':cohort['source_read_run_id'],'cohort_sha256':COHORT_SHA,
        'installed_sha':CURRENT,'observed_at_utc':r['observed_at_utc'],'confirmed_caption_edits':146,'corrected_hotels':73,
        'price_blocks_retained':sum(x.get('monitor_price_record_id') is not None for x in cohort['rows']),
        'timestamp_display_conversions':0,'new_posts_created':0,'existing_buttons_and_photos_verified':146,
        'final_snapshot_sha256':sha(raw),'run_id':os.environ['GITHUB_RUN_ID'],'run_attempt':int(os.environ['GITHUB_RUN_ATTEMPT']),
        'original_completed_run_id':ORIGINAL_RUN,'original_completed_posts':54,'first_execution_remaining_posts':92,
        'earlier_batches_replayed':False,'completion_provenance_sha256':sha((SAFE/'completion-provenance.json').read_bytes())}
    require(out['price_blocks_retained'] == 54, 'price_block_count_changed')
    save(SAFE/'summary.json',out); print(json.dumps(out))

if __name__ == '__main__':
    command = sys.argv[1]
    if command == 'prepare': prepare()
    elif command == 'original': original()
    elif command == 'before': before(int(sys.argv[2]))
    elif command == 'final': final(int(sys.argv[2]))
    else: raise RuntimeError('unsupported_command')
