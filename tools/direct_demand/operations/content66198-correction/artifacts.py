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

def manifest(directory):
    files = sorted(directory.glob('*.json'))
    need(4 <= len(files) <= 12, 'read_member_count')
    print(json.dumps({'state':'CONTENT66198_READ_ARTIFACT_MEMBERS',
                      'members':{p.name:sha(p.read_bytes()) for p in files}},sort_keys=True))

if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('stage',choices=['consume','manifest'])
    parser.add_argument('--binding',type=Path)
    parser.add_argument('--out',type=Path,required=True)
    args = parser.parse_args()
    if args.stage == 'manifest':
        manifest(args.out)
    else:
        consume(json.loads(args.binding.read_bytes()),args.out)
