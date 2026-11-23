"""Consume one authenticated original READ artifact; no privileged execution."""
import argparse
import hashlib
import io
import json
import re
import subprocess
import zipfile
from pathlib import Path

REPO = 'pyatkoff/max-search-bot'
BRANCH = 'audit/content100-bot-publication-20261101'
WORKFLOW = '.github/workflows/content100-bot-publication-20261101.yml'
OWNER = 226193297

# This is the single already executed EDIT. A failed workflow is not a general
# artifact authority: only its authenticated successful native Apply phase is.
APP = 'b1da1418ff75cba9657d8b5a2971270c8eafb1c9'
EDIT_OPERATION = 'commercial-waldorf66198-caption-correction-20261123-v1'
EDIT_PIN = {
    'kind': 'CONTENT66198_CONFIRMED_EDIT', 'application_sha': APP,
    'run_id': 45096362078, 'run_attempt': 1,
    'head_sha': 'a6e10ac31ad23a8a152d12bc4d13a1b7cb874437', 'job_id': 137436923923,
    'artifact_id': 14783477052, 'name': 'content66198-original-correction',
    'sha256': 'b9fe77162da0953ba9bb3608c54e213b31be592bee274151247fbec0d745619e',
}
READ_PIN = {
    'kind': 'CONTENT66198_ORIGINAL_READ', 'run_id': 45096018311, 'run_attempt': 1,
    'head_sha': '9d5116bb0fa3d44cc166426806b8d2f7daf921ad',
    'artifact_id': 14783441649, 'name': 'content66198-original-read',
    'sha256': '738e934fd166d9165ecf83ad19e51075f2f107b2982f2226da6fd42b590ad67d',
}
READ_MEMBERS = {
    'capture-proof.json': '51b55664d0960f543cd5ea896d504bf5ed405291f5e564c6b12d217180e7da4e',
    'edit-manifest-preflight.json': 'a10fa0a8823b2c674d39727d7088889534adff3183f88f9de67add181415ee85',
    'feed-capture.json': '64ece3dc9c6a157c87283c96b06e588519cb4f6728b95162bb875c24972d3c75',
    'journal-metadata.json': 'be19069f2ef68deb858d3dd48568b9c644fb77a7291d4c00764396508fd67af0',
    'public-before.json': '04872ab09561d7e6db983c7a75f399f8000308b08fce6644ca19ea6ffeb95b90',
    'read-admission.json': '017f5e664fd0a85b018010a960522a823691808b081dbefc21d09796e3253ddb',
    'read-result.json': '7639b872b5b4382bd2ba766cc31a03d961115f1c5bc097ff5dd441d2627aa14a',
    'read-scope.json': 'c983bd5f2d1413e0b1a618373e681f64f53799f728e322fcf11b9702c5687328',
}
EDIT_MEMBERS = frozenset({
    'content66198-edit/compile-receipt.json', 'content66198-edit/edit-manifest.json',
    'content66198-edit/edit-output.jsonl', 'content66198-edit/edit-output.jsonl.exit',
    'content66198-edit/edit-result.json', 'content66198-edit/public-before-edit.json',
    'content66198-edit-before_apply-admission.json', 'content66198-edit-credentials-admission.json',
})
EDIT_STEPS = {
    1: ('Set up job', 'success'),
    2: ('Run actions/checkout@d23441a48e516b6c34aea4fa41551a30e30af803', 'success'),
    3: ('Authenticate original source, current ownership and installed application', 'success'),
    4: ('Authenticate the original READ artifact and unchanged exact diff', 'success'),
    5: ('Pin established SSH host continuity and authentication identity', 'success'),
    6: ('Recheck native admission immediately before the caption edits', 'success'),
    7: ('Apply the single approved correction to the two existing posts once', 'success'),
    8: ('Verify both original native edit receipts and independent public text', 'failure'),
    9: ('Read only the original caption receipt for the existing XML writer', 'skipped'),
    10: ('Compile the existing schema5 XML writer against exact preimages', 'skipped'),
    11: ('Recheck current ownership and application immediately before XML write', 'skipped'),
    12: ('Write only the four approved XML description changes once', 'skipped'),
    13: ('Verify the original XML writer result', 'skipped'),
    14: ('Read back the three resulting files and public XML URLs', 'skipped'),
    15: ('Verify both post receipts and all XML destinations', 'skipped'),
    16: ('Preserve original evidence on success, failure or UNKNOWN', 'success'),
    17: ('Remove runner-only credentials and compiled PHP', 'success'),
    34: ('Post Run actions/checkout@d23441a48e516b6c34aea4fa41551a30e30af803', 'success'),
    35: ('Complete job', 'success'),
}
ORIGINAL_ADMISSION = {
    'ok': True, 'stage': 'edit', 'repository_sha': APP, 'application_sha': APP,
    'operation_run_id': EDIT_PIN['run_id'], 'operation_head': EDIT_PIN['head_sha'], 'operation_attempt': 1,
    'source_ci': {'run_id': 45094992137, 'head_sha': 'dfb2075d9512c58c93a0bff7366c583dbf8615db'},
    'source_comment': {'id': 6961029169, 'updated_at': '2026-11-23T01:27:49Z',
                       'body_sha256': '8792ac704b201d4cb060e2d315df17ebadce2177bcf98bc189adb050d2f7be32'},
    'approval_comment': {'id': 7072600922, 'updated_at': '2026-11-22T01:59:49Z',
                         'body_sha256': '9c94d588f167bdd7dc3c8c309dd3727298aa00620528f8455ab05dbd2a9fa943'},
    'diagnostics_commit': '636b625f7fd652782bcfa5b4480fa2b872218eef',
    'diagnostics_blob': 'b675fdaac9815da4889317d1fef36be4d535ba6c',
    'record_digest': '150712719ea7ed6fc83090052f67ee1f2ae443d245e6f7690a46bece516e2c81',
    'provider_calls': 0, 'post_write_attempts': 0, 'xml_write_attempts': 0,
}
EDIT_TARGETS = {
    'telegram': {'publication_id': 1500, 'previous_version_id': 13436, 'version_id': 14280,
                 'message_id': '678', 'post_url': 'https://t.me/any_toour/678',
                 'photo_signature': 'telegram:AQAD2iFrG5M24Et-', 'source_key': 'tg_anytour_msk'},
    'max': {'publication_id': 1510, 'previous_version_id': 13435, 'version_id': 14281,
            'message_id': 'mid.ffffb6caf0606fed01a1e8f2e90d601f',
            'post_url': 'https://max.ru/channel_anytour/AaHo8ukNYB8',
            'photo_signature': 'max:51629974024', 'source_key': 'max_anytour_msk1'},
}

def need(ok, code):
    if not ok:
        raise RuntimeError(code)

def sha(data):
    return hashlib.sha256(data).hexdigest()

def api(path, raw=False):
    result = subprocess.run(['gh','api',f'repos/{REPO}/{path}'], capture_output=True, timeout=70)
    need(result.returncode == 0, 'artifact_api_refused')
    need(len(result.stdout) <= (64*1024*1024 if raw else 2*1024*1024), 'artifact_api_oversized')
    return result.stdout if raw else json.loads(result.stdout)

def validate_archive(raw, binding):
    need(sha(raw) == binding['sha256'], 'artifact_hash_changed')
    expected = binding['members']
    need(isinstance(expected,dict) and 4 <= len(expected) <= 12, 'artifact_member_scope')
    for name, digest in expected.items():
        need(re.fullmatch(r'[a-z][a-z0-9-]*\.json',name) is not None
             and re.fullmatch(r'[a-f0-9]{64}',digest) is not None, 'artifact_member_binding')
    with zipfile.ZipFile(io.BytesIO(raw)) as z:
        infos = z.infolist()
        need(len(infos) == len(expected) and {x.filename for x in infos} == set(expected), 'artifact_members_changed')
        need(all(not x.is_dir() and not x.flag_bits & 1 and 0 < x.file_size <= 32*1024*1024 for x in infos)
             and sum(x.file_size for x in infos) <= 64*1024*1024, 'artifact_member_size')
        data = {name:z.read(name) for name in expected}
        need(all(sha(value) == expected[name] for name,value in data.items()), 'artifact_member_hash_changed')
        for value in data.values():
            need(isinstance(json.loads(value),dict), 'artifact_member_json')
        return data

def consume(binding, out):
    need(binding['kind'] == 'CONTENT66198_ORIGINAL_READ' and binding['run_attempt'] == 1, 'read_binding_scope')
    run = api('actions/runs/'+str(binding['run_id']))
    need(run['id'] == binding['run_id'] and run['head_sha'] == binding['head_sha']
         and run['run_attempt'] == 1 and run['status'] == 'completed' and run['conclusion'] == 'success'
         and run['event'] == 'push' and run['head_branch'] == BRANCH and run['path'] == WORKFLOW
         and run['actor']['id'] == run['triggering_actor']['id'] == OWNER, 'original_read_not_verified')
    jobs = api('actions/runs/'+str(binding['run_id'])+'/attempts/1/jobs?per_page=100')
    need(jobs['total_count'] == len(jobs['jobs']) == 1 and jobs['jobs'][0]['name'] == 'native-read'
         and jobs['jobs'][0]['conclusion'] == 'success' and jobs['jobs'][0]['run_attempt'] == 1
         and jobs['jobs'][0]['head_sha'] == binding['head_sha'], 'original_read_job_changed')
    meta = api('actions/artifacts/'+str(binding['artifact_id']))
    need(meta['id'] == binding['artifact_id'] and meta['name'] == binding['name'] and meta['expired'] is False
         and meta['digest'] == 'sha256:'+binding['sha256'] and meta['workflow_run']['id'] == binding['run_id']
         and meta['workflow_run']['head_sha'] == binding['head_sha'], 'read_artifact_changed')
    files = validate_archive(api('actions/artifacts/'+str(binding['artifact_id'])+'/zip',raw=True), binding)
    need(not out.exists(), 'read_artifact_destination_exists')
    out.mkdir(mode=0o700)
    for name, data in files.items():
        (out/name).write_bytes(data)
    print(json.dumps({'state':'CONTENT66198_ORIGINAL_READ_AUTHENTICATED','run_id':binding['run_id'],
                      'head_sha':binding['head_sha'],'artifact_id':binding['artifact_id'],'members':binding['members']},sort_keys=True))

def same(left, right):
    # JSON comparison retains boolean/integer distinctions in native evidence.
    return json.dumps(left,sort_keys=True,separators=(',',':')) == json.dumps(right,sort_keys=True,separators=(',',':'))

def validate_edit_binding(binding):
    need(set(binding) == set(EDIT_PIN) | {'members','read_binding'}
         and all(same(binding[k],v) for k,v in EDIT_PIN.items()), 'edit_binding_scope')
    read = binding['read_binding']
    need(isinstance(read,dict) and set(read) == set(READ_PIN) | {'members'}
         and all(same(read[k],v) for k,v in READ_PIN.items())
         and read['members'] == READ_MEMBERS, 'edit_original_read_binding_changed')
    need(isinstance(binding['members'],dict) and set(binding['members']) == EDIT_MEMBERS
         and all(isinstance(v,str) and re.fullmatch('[a-f0-9]{64}',v) for v in binding['members'].values()),
         'edit_member_binding_changed')

def validate_run(run, binding, conclusion):
    need(same(run.get('id'),binding['run_id']) and run.get('head_sha') == binding['head_sha']
         and same(run.get('run_attempt'),1) and run.get('status') == 'completed'
         and run.get('conclusion') == conclusion and run.get('event') == 'push'
         and run.get('head_branch') == BRANCH and run.get('path') == WORKFLOW
         and same(run.get('actor',{}).get('id'),OWNER)
         and same(run.get('triggering_actor',{}).get('id'),OWNER), 'original_operation_run_changed')

def validate_artifact_meta(meta, binding):
    need(same(meta.get('id'),binding['artifact_id']) and meta.get('name') == binding['name']
         and meta.get('expired') is False and meta.get('digest') == 'sha256:'+binding['sha256']
         and same(meta.get('workflow_run',{}).get('id'),binding['run_id'])
         and meta.get('workflow_run',{}).get('head_sha') == binding['head_sha']
         and meta.get('workflow_run',{}).get('head_branch') == BRANCH, 'original_operation_artifact_changed')

def validate_edit_jobs(jobs, binding):
    rows = jobs.get('jobs',[])
    need(same(jobs.get('total_count'),1) and len(rows) == 1, 'original_edit_job_inventory_changed')
    job = rows[0]
    need(same(job.get('id'),binding['job_id']) and same(job.get('run_id'),binding['run_id'])
         and job.get('name') == 'native-edit' and job.get('status') == 'completed'
         and job.get('conclusion') == 'failure' and same(job.get('run_attempt'),1)
         and job.get('head_sha') == binding['head_sha'], 'original_edit_job_changed')
    steps = job.get('steps',[])
    need(len(steps) == len(EDIT_STEPS)
         and {s.get('number') for s in steps} == set(EDIT_STEPS), 'original_edit_step_inventory_changed')
    for step in steps:
        name, conclusion = EDIT_STEPS[step['number']]
        need(step.get('name') == name and step.get('status') == 'completed'
             and step.get('conclusion') == conclusion, 'original_edit_phase_changed')

def validate_edit_archive(raw, binding):
    need(sha(raw) == binding['sha256'], 'edit_artifact_hash_changed')
    expected = binding['members']
    need(set(expected) == EDIT_MEMBERS, 'edit_artifact_member_scope')
    with zipfile.ZipFile(io.BytesIO(raw)) as z:
        infos = z.infolist()
        need(len(infos) == len(EDIT_MEMBERS) and {x.filename for x in infos} == EDIT_MEMBERS,
             'edit_artifact_members_changed')
        need(all(not x.is_dir() and not x.flag_bits & 1 and 0 < x.file_size <= 2*1024*1024 for x in infos)
             and sum(x.file_size for x in infos) <= 8*1024*1024, 'edit_artifact_member_size')
        files = {name:z.read(name) for name in expected}
    need(all(sha(data) == expected[name] for name,data in files.items()), 'edit_artifact_member_hash_changed')
    for name,data in files.items():
        if name.endswith('.json'):
            need(isinstance(json.loads(data),dict), 'edit_artifact_member_json')
    return files

def verified_read_directory(binding, directory):
    read = binding['read_binding']
    validate_run(api('actions/runs/'+str(read['run_id'])),read,'success')
    jobs = api('actions/runs/'+str(read['run_id'])+'/attempts/1/jobs?per_page=100')
    rows = jobs.get('jobs',[])
    need(jobs.get('total_count') == len(rows) == 1 and rows[0].get('name') == 'native-read'
         and rows[0].get('status') == 'completed' and rows[0].get('conclusion') == 'success'
         and same(rows[0].get('run_attempt'),1) and rows[0].get('head_sha') == read['head_sha'],
         'edit_original_read_job_changed')
    validate_artifact_meta(api('actions/artifacts/'+str(read['artifact_id'])),read)
    need(directory.is_dir() and not directory.is_symlink()
         and {p.name for p in directory.iterdir()} == set(READ_MEMBERS), 'edit_read_directory_changed')
    files = {}
    for name,digest in READ_MEMBERS.items():
        path = directory/name
        need(path.is_file() and not path.is_symlink() and 0 < path.stat().st_size <= 32*1024*1024,
             'edit_read_file_changed')
        files[name] = path.read_bytes()
        need(sha(files[name]) == digest, 'edit_read_member_hash_changed')
    return files

def validate_edit_evidence(files, read_files):
    prefix = 'content66198-edit/'
    need(files[prefix+'edit-output.jsonl.exit'] == b'0\n', 'original_edit_exit_not_zero')
    output = files[prefix+'edit-output.jsonl']
    need(output.endswith(b'\n') and len(output.splitlines()) == 3, 'original_edit_output_shape_changed')
    records = [json.loads(line) for line in output.splitlines()]
    result = json.loads(files[prefix+'edit-result.json'])
    need(same(records[-1],result), 'original_edit_terminal_changed')
    progress = [{'progress':'existing_caption_quality_revised','platform':platform,
                 'publication_id':target['publication_id'],'post_url':target['post_url']}
                for platform,target in EDIT_TARGETS.items()]
    need(same(records[:-1],progress), 'original_edit_progress_changed')
    manifest_raw = files[prefix+'edit-manifest.json']
    need(manifest_raw == read_files['edit-manifest-preflight.json'], 'original_read_manifest_changed')
    manifest = json.loads(manifest_raw)
    need(manifest.get('operation') == EDIT_OPERATION and manifest.get('expected_installed_sha') == APP
         and manifest.get('snapshot',{}).get('sha256') == sha(read_files['read-result.json'])
         and same(manifest.get('row_count'),2) and len(manifest.get('rows',[])) == 2,
         'original_edit_manifest_scope_changed')
    need(result.get('ok') is True and result.get('operation') == EDIT_OPERATION
         and result.get('mode') == 'approved_existing_caption_quality_revision'
         and result.get('installed_sha') == APP and result.get('manifest_sha256') == sha(manifest_raw),
         'original_edit_result_binding_changed')
    for key in ['post_write_attempts','caption_updates','verified_posts','preflight_existing_messages']:
        need(type(result.get(key)) is int and result[key] == 2, 'original_edit_count_changed')
    for key in ['new_posts_created','configuration_write_attempts','button_write_attempts','tour_search_calls','price_queries']:
        need(type(result.get(key)) is int and result[key] == 0, 'original_edit_unapproved_write')
    rows = {row['platform']:row for row in manifest['rows']}
    posts = result.get('posts',[])
    need(len(rows) == len(posts) == 2 and set(rows) == {p.get('platform') for p in posts} == set(EDIT_TARGETS),
         'original_edit_post_scope_changed')
    for post in posts:
        row = rows[post['platform']]; target = EDIT_TARGETS[post['platform']]
        need(all(same(post.get(k),v) for k,v in target.items()) and post.get('hotel_id') == 66198
             and post.get('state') == 'sent' and post.get('same_caption_skipped') is False
             and post.get('caption_correction_operation') == EDIT_OPERATION,
             'original_edit_identity_changed')
        need(all(post.get(k) is True for k in ['body_photo_button_verified','formatting_verified',
                  'existing_message_reused','existing_token_resolves_to_current_version']),
             'original_edit_preservation_missing')
        need(post.get('previous_text_sha256') == row['old_text_sha256'] == sha(row['old_text'].encode())
             and post.get('text_sha256') == row['new_text_sha256'] == sha(row['new_text'].encode())
             and post.get('previous_attachments_sha256') == row['attachments_sha256']
             and post.get('button_url_sha256') == row['button_url_sha256']
             and post.get('button_text') == manifest['button_text']
             and post.get('monitor_price_record_id') == row['monitor_price_record_id'],
             'original_edit_content_binding_changed')
        attachment = post.get('attachments_sha256')
        need(isinstance(attachment,str) and re.fullmatch('[a-f0-9]{64}',attachment)
             and (post['platform'] == 'telegram' or attachment == row['attachments_sha256']),
             'original_edit_attachment_changed')
    compile_receipt = json.loads(files[prefix+'compile-receipt.json'])
    expected_compile = {'state':'OFFLINE_COMPILED_NOT_EXECUTED','installed_sha':APP,
        'source_lineage':'d44ff7ff0e1137801137467f5f03e3ca32af87f6','operation':EDIT_OPERATION,
        'approval_comment_id':7072600922,'manifest_sha256':sha(manifest_raw),
        'snapshot_sha256':sha(read_files['read-result.json']),
        'journal_pin':json.loads(read_files['journal-metadata.json'])['journal_pin'],
        'row_count':2,'expected_edits':2}
    need(same(compile_receipt,expected_compile), 'original_edit_compile_changed')
    for checkpoint,observed in [('credentials','2026-11-23T01:45:55.205Z'),('before_apply','2026-11-23T01:46:13.227Z')]:
        admission = json.loads(files['content66198-edit-'+checkpoint+'-admission.json'])
        need(same(admission,ORIGINAL_ADMISSION | {'checkpoint':checkpoint,'observed_at_utc':observed}),
             'original_edit_admission_changed')
    return result

def consume_edit(binding, read_dir, out):
    validate_edit_binding(binding)
    read_files = verified_read_directory(binding,read_dir)
    validate_run(api('actions/runs/'+str(binding['run_id'])),binding,'failure')
    validate_edit_jobs(api('actions/runs/'+str(binding['run_id'])+'/attempts/1/jobs?per_page=100'),binding)
    validate_artifact_meta(api('actions/artifacts/'+str(binding['artifact_id'])),binding)
    files = validate_edit_archive(api('actions/artifacts/'+str(binding['artifact_id'])+'/zip',raw=True),binding)
    validate_edit_evidence(files,read_files)
    need(not out.exists() and not out.is_symlink(), 'edit_artifact_destination_exists')
    out.mkdir(mode=0o700)
    for name,data in files.items():
        path = out/name
        path.parent.mkdir(mode=0o700,exist_ok=True)
        path.write_bytes(data)
    print(json.dumps({'state':'CONTENT66198_CONFIRMED_EDIT_AUTHENTICATED',
        'run_id':binding['run_id'],'head_sha':binding['head_sha'],'artifact_id':binding['artifact_id'],
        'members':binding['members'],'caption_replay_permitted':False,'xml_write_permitted':False},sort_keys=True))

def manifest(directory):
    files = sorted(directory.glob('*.json'))
    need(4 <= len(files) <= 12, 'read_member_count')
    print(json.dumps({'state':'CONTENT66198_READ_ARTIFACT_MEMBERS',
                      'members':{p.name:sha(p.read_bytes()) for p in files}},sort_keys=True))

if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('stage',choices=['consume','consume-edit','manifest'])
    parser.add_argument('--binding',type=Path)
    parser.add_argument('--read-dir',type=Path)
    parser.add_argument('--out',type=Path,required=True)
    args = parser.parse_args()
    if args.stage == 'manifest':
        manifest(args.out)
    elif args.stage == 'consume-edit':
        need(args.binding is not None and args.read_dir is not None, 'edit_artifact_arguments_missing')
        consume_edit(json.loads(args.binding.read_bytes()),args.read_dir,args.out)
    else:
        consume(json.loads(args.binding.read_bytes()),args.out)
