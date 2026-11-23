"""CLOSED single invocation wiring. No retries or hidden resume after native calls."""
import base64
import gzip
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import shlex
import subprocess

ROOT=Path(__file__).resolve().parent
NATIVE_SHA='91dd4ec54aa101685efb8cee61eaf9b8d3c7cf5a07f225af52baeda523055666'

def need(v,m):
    if not v: raise RuntimeError(m)
def sha(b): return hashlib.sha256(b).hexdigest()
def canonical(v): return json.dumps(v,sort_keys=True,separators=(',',':'),ensure_ascii=False).encode()
def dump(path,v):
    raw=canonical(v);path.write_bytes(raw);path.chmod(0o600);return sha(raw)
def command(args,**kw):
    p=subprocess.run(args,capture_output=True,**kw)
    need(p.returncode==0,'command_failed_no_retry')
    return p.stdout

def sanitized_result(raw, stderr, run_id, head, returncode, timed_out=False):
    result={'run_id':run_id,'head':head,'raw_sha256':sha(raw),'stderr_sha256':sha(stderr),
            'returncode':returncode,'timed_out':timed_out,'ok':False,'phase':'unknown','error':'unknown_requires_original_readback'}
    try: value=json.loads(raw)
    except (ValueError,UnicodeDecodeError): return result
    if not isinstance(value,dict): return result
    for key in ('ok','database_confirmation_attempted','child_append_attempted','feed_finish_attempted'):
        if type(value.get(key)) is bool: result[key]=value[key]
    for key in ('provider_calls','provider_writes','code_replacements','consumer_code_writes','post_writes','feed_writes','journal_writes','collector_runs','broad_passes'):
        if type(value.get(key)) is int and value[key]>=0: result[key]=value[key]
    for key in ('phase','error','state','mode'):
        if isinstance(value.get(key),str) and re.fullmatch('[a-z][a-z0-9_]{0,100}',value[key]):result[key]=value[key]
    if isinstance(value.get('hook_sha256'),str) and re.fullmatch('[a-f0-9]{64}',value['hook_sha256']):result['hook_sha256']=value['hook_sha256']
    if isinstance(value.get('journal'),dict) and isinstance(value['journal'].get('pin'),dict):value['journal_pin']=value['journal']['pin']
    for key in ('global_pin','child_pin','journal_pin'):
        pin=value.get(key)
        if isinstance(pin,dict) and re.fullmatch('[a-f0-9]{64}',str(pin.get('sha256',''))) and all(type(pin.get(k)) is int and pin[k]>=0 for k in ('bytes','lines')):
            result[key]={k:pin[k] for k in ('sha256','bytes','lines')}
    feeds=value.get('feed_shas')
    if isinstance(feeds,dict) and set(feeds)=={'telegram','max','all'} and all(isinstance(v,str) and re.fullmatch('[a-f0-9]{64}',v) for v in feeds.values()):result['feed_shas']=feeds
    if timed_out or returncode!=0:result['ok']=False
    if result['ok']:result.pop('error',None)
    return result

def public_terminal(value):
    target=Path(os.environ['RUNNER_TEMP'])/'hook-install-sanitized';target.mkdir(mode=0o700,exist_ok=True)
    dump(target/'terminal.json',value)

def bind_route(admission_cfg, route_raw, expected_sha):
    need(sha(route_raw)==expected_sha,'private_route_attestation_changed')
    route=json.loads(route_raw)
    need(isinstance(route,dict),'route_attestation_shape')
    admission_cfg['route_attestation']={'file':'route-attestation.json','sha256':expected_sha}
    admission_cfg['expected_route_attestation']=route
    return route

def main():
    os.umask(0o077)
    plan=json.loads((ROOT/'launch-CLOSED.json').read_bytes())
    need(plan.get('enabled') is True and plan.get('binding_reviewed') is True,'launch_closed')
    env=os.environ
    need(env['GITHUB_REPOSITORY']=='pyatkoff/poisk-turov-test' and env['GITHUB_RUN_ATTEMPT']=='1'
         and env['GITHUB_EVENT_NAME']=='push','original_run_required')
    run=env['GITHUB_RUN_ID'];head=env['GITHUB_SHA']
    need(re.fullmatch('[1-9][0-9]*',run) and re.fullmatch('[a-f0-9]{40}',head),'identity_invalid')
    base=Path(env['RUNNER_TEMP'])/('price1619-hook-'+run)
    base.mkdir(mode=0o700) # exclusive: never reuse a previous attempt
    sources=plan['sources']; need(sources and len({s['name'] for s in sources})==len(sources),'manifest_invalid')
    for s in sources:
        need(Path(s['name']).name==s['name'] and re.fullmatch('[a-f0-9]{40}',s['ref']),'source_invalid')
        need(s['repository'] in ('pyatkoff/max-search-bot','pyatkoff/poisk-turov-test'),'source_repository_invalid')
        raw=command(['gh','api','--method','GET','-H','Accept: application/vnd.github.raw+json',
                     'repos/'+s['repository']+'/contents/'+s['path']+'?ref='+s['ref']],timeout=40)
        need(sha(raw)==s['sha256'] and hashlib.sha1(b'blob '+str(len(raw)).encode()+b'\0'+raw).hexdigest()==s['blob_sha'],'immutable_source_changed')
        (base/s['name']).write_bytes(raw)
    required={'probe-core.php','probe-worker.php','subprocess.php','admit.py','install.php','guards.php','primitives.php','verify-release.js','release_priority.js','deployment_release.js','release_slot_close.js','producer.php','candidate.php','config-CLOSED.json','admission-config-CLOSED.json'}
    need(required<={s['name'] for s in sources},'missing_source')
    helper=json.loads((base/'config-CLOSED.json').read_bytes()); admission_cfg=json.loads((base/'admission-config-CLOSED.json').read_bytes())
    need(helper.get('enabled') is True and admission_cfg.get('enabled') is True and admission_cfg.get('binding_reviewed') is True,'helper_or_admission_closed')
    # The runner never edits source guards, enables templates, or invents accepted release.
    route_raw=(ROOT/'route-attestation.json').read_bytes()
    bind_route(admission_cfg,route_raw,plan['route_attestation_sha256'])
    (base/'route-attestation.json').write_bytes(route_raw)
    host=env['DEPLOY_HOST'];user=env['DEPLOY_USER']
    need(re.fullmatch('[A-Za-z0-9_.:-]+',host) and not host.startswith('-') and re.fullmatch('[A-Za-z0-9_-]+',user),'remote_invalid')
    opts=['-i',env['RUNNER_TEMP']+'/channel_key','-o','BatchMode=yes','-o','StrictHostKeyChecking=yes','-o','UserKnownHostsFile='+env['RUNNER_TEMP']+'/channel_known_hosts','-o','ConnectTimeout=15','-o','ServerAliveInterval=30']
    remote=user+'@'+host
    def ssh(cmd,timeout=240):
        return command(['ssh',*opts,remote,'unset GIT_DIR GIT_WORK_TREE GIT_COMMON_DIR GIT_INDEX_FILE GIT_CONFIG_PARAMETERS GIT_CONFIG_COUNT GIT_OBJECT_DIRECTORY GIT_ALTERNATE_OBJECT_DIRECTORIES ANYTOUR_MONITOR_HOOK_INSPECT; export GIT_OPTIONAL_LOCKS=0; '+cmd],timeout=timeout)
    scratch=ssh('umask 077; mktemp -d /tmp/price1619-hook.XXXXXXXX',30).decode().strip()
    need(re.fullmatch(r'/tmp/price1619-hook\.[A-Za-z0-9]+',scratch),'scratch_invalid')
    # Only private scratch staging; no canonical code/journal/publication install here.
    def upload(name): command(['scp',*opts,str(base/name),remote+':'+scratch+'/'+name],timeout=60)
    for s in sources: upload(s['name'])
    need(admission_cfg['event_before']==plan['event_before'],'successor_binding_changed')
    need(re.fullmatch('[a-f0-9]{40}',plan['event_before']),'successor_unbound')
    recovery_path=plan['recovery_receipt_remote_path']
    need(re.fullmatch(r'/tmp/price4294-recovery\.[A-Za-z0-9]+/recovery-result.private.json',recovery_path),'recovery_path_unbound')
    recovery_raw=ssh('cat '+shlex.quote(recovery_path),30)
    need(sha(recovery_raw)==helper['recovery_receipt_sha256']==admission_cfg['original_recovery']['receipt_sha256'],'original_recovery_changed')
    (base/'recovery.private.json').write_bytes(recovery_raw);upload('recovery.private.json')
    producer_cfg=dict(helper);producer_cfg['producer_enabled']=plan['producer_enabled']
    need(producer_cfg['producer_enabled'] is True,'producer_closed')
    producer_sha=dump(base/'producer-config.json',producer_cfg);upload('producer-config.json')
    staged=ssh('sha256sum '+' '.join(shlex.quote(scratch+'/'+s['name']) for s in sources)).decode().splitlines()
    need(len(staged)==len(sources),'remote_manifest_incomplete')
    for line,s in zip(staged,sources):need(line.split()==[s['sha256'],scratch+'/'+s['name']],'remote_source_changed')
    # Candidate is invoked ONLY in --inspect mode; never call its default main loop.
    inspect_out=ssh('php '+shlex.quote(scratch+'/candidate.php')+' --inspect')
    (base/'candidate-inspect.private.log').write_bytes(inspect_out)
    lines=[line.split(b'=',1)[1] for line in inspect_out.splitlines() if line.startswith(b'MONITOR_POST_FEED_RESULT=')]
    need(len(lines)==1,'candidate_inspect_ambiguous')
    import time
    wrapper={'candidate_hook_sha256':helper['new_hook_sha256'],'returncode':0,'native_result_raw':lines[0].decode(),
             'native_result_sha256':sha(lines[0]),'feed_shas':helper['feed_shas'],'observed_at_epoch':int(time.time())}
    helper['inspect_receipt_sha256']=dump(base/'inspect.private.json',wrapper);upload('inspect.private.json')
    producer_raw=ssh('php '+shlex.quote(scratch+'/producer.php')+' '+shlex.quote(scratch+'/producer-config.json')+' '+run+' '+head+' '+producer_sha)
    producer=json.loads(producer_raw);producer_digest=dump(base/'producer.json',producer)
    helper['producer_proof']={'run_id':producer['run_id'],'observed_at_epoch':producer['observed_at_epoch'],'receipt_sha256':producer_digest}
    helper['candidate_hook_sha256']=helper['new_hook_sha256']
    admission_cfg['producer_receipt']={'file':'producer.json','sha256':producer_digest}
    payload=dict(helper);payload.pop('admission_sha256',None)
    admission_cfg['helper_config_payload_sha256']=sha(canonical(payload))
    spec=importlib.util.spec_from_file_location('hook_admission',base/'admit.py');module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
    event=json.loads(Path(env['GITHUB_EVENT_PATH']).read_bytes())
    admitted=module.evaluate(admission_cfg,helper,env,event,base)
    helper['admission_sha256']=dump(base/'admission.json',admitted)
    config_sha=dump(base/'config-CLOSED.json',helper)
    upload('admission.json');upload('config-CLOSED.json')
    def invocation(mode):
        return 'php '+shlex.quote(scratch+'/install.php')+' '+mode+' '+' '.join(shlex.quote(scratch+'/'+name) for name in ('config-CLOSED.json','candidate.php','inspect.private.json','admission.json','recovery.private.json'))+' '+config_sha
    baseline=ssh(invocation('inspect-old'))
    # Preserve original PHP JSON bytes/order; never reserialize the CAS baseline.
    need(json.loads(baseline).get('ok') is True and json.loads(baseline).get('mode')=='inspect-old','baseline_failed')
    (base/'baseline.private.json').write_bytes(baseline);upload('baseline.private.json')
    dump(base/'install-intent.private.json',{'run_id':run,'head':head,'config_sha256':config_sha,'attempts':1})
    install_cmd=invocation('install')+' '+shlex.quote(scratch+'/baseline.private.json')
    try:
        # No local kill timer: native TTL guards each consequential boundary.
        proc=subprocess.run(['ssh',*opts,remote,'unset GIT_DIR GIT_WORK_TREE GIT_COMMON_DIR GIT_INDEX_FILE GIT_CONFIG_PARAMETERS GIT_CONFIG_COUNT GIT_OBJECT_DIRECTORY GIT_ALTERNATE_OBJECT_DIRECTORIES ANYTOUR_MONITOR_HOOK_INSPECT; export GIT_OPTIONAL_LOCKS=0; '+install_cmd],capture_output=True)
        (base/'install-result.private.json').write_bytes(proc.stdout);(base/'install-stderr.private.log').write_bytes(proc.stderr)
        terminal=sanitized_result(proc.stdout,proc.stderr,run,head,proc.returncode);terminal['verification_complete']=False
        public_terminal(terminal)
        need(proc.returncode==0 and json.loads(proc.stdout).get('ok') is True,'installation_UNKNOWN_no_retry')
        after=ssh(invocation('inspect-new'))
        (base/'inspect-new.private.json').write_bytes(after)
        need(json.loads(after).get('ok') is True and json.loads(after).get('mode')=='inspect-new','readback_UNKNOWN_no_retry')
        terminal=sanitized_result(proc.stdout,proc.stderr,run,head,proc.returncode)
        terminal['inspect_new_raw_sha256']=sha(after);terminal['inspect_new_ok']=True;terminal['verification_complete']=True
        public_terminal(terminal)
    except BaseException:
        if 'terminal' in locals():
            terminal['ok']=False;terminal['verification_complete']=False;terminal['error']='unknown_requires_original_readback';public_terminal(terminal)
        dump(base/'UNKNOWN.private.json',{'outcome':'UNKNOWN','retry_authorized':False})
        raise
    print('Compatible hook installation and separate readback returned verified receipts; no price pass executed.')

if __name__=='__main__':main()
