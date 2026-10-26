#!/usr/bin/env python3
"""Fixed private snapshot / distinct hash-only fence; no model or business writes."""
import base64, hashlib, json, os, re, socket, subprocess, sys
from pathlib import Path
import pyrage

ROOT=Path(__file__).resolve().parents[1]
MAX_BYTES=2*1024*1024
PHASE='validation'

class FixedFailure(Exception):
    pass

def run():
    global PHASE
    host=os.environ['DEPLOY_HOST'];user=os.environ['DEPLOY_USER'];expected=os.environ['EXPECTED_RUNTIME_SHA']
    mode=os.environ['PROFILE_MODE'];expected_digest=os.environ.get('EXPECTED_INPUT_DIGEST','')
    if mode not in {'capture','fence'} or not re.fullmatch(r'[a-f0-9]{40}',expected): raise ValueError()
    if not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9.-]{0,252}',host) or not re.fullmatch(r'[A-Za-z0-9_][A-Za-z0-9_.-]{0,63}',user): raise ValueError()
    PHASE='host_key'
    if {v[4][0] for v in socket.getaddrinfo(host,22,type=socket.SOCK_STREAM)}!={'5.45.122.54'}: raise FixedFailure('canonical_target_changed')
    pinned=[]
    for scan_attempt in range(2):
        scan=subprocess.run(['ssh-keyscan','-T','10',host],capture_output=True,text=True,timeout=30)
        for line in scan.stdout.splitlines():
            p=line.split()
            if len(p)!=3 or p[0].startswith('#'): continue
            try: fp='SHA256:'+base64.b64encode(hashlib.sha256(base64.b64decode(p[2],validate=True)).digest()).decode().rstrip('=')
            except ValueError: continue
            if fp=='SHA256:/5mPWmGBQ6UPnNQDEHLOCy7dCpxdz+FxQ4zVOjqE0F0': pinned.append(line)
        if pinned: break
    if not pinned: raise FixedFailure('canonical_host_pin_not_seen')
    temp=Path(os.environ['RUNNER_TEMP']);known=temp/'model_profile_hosts';known.write_text('\n'.join(pinned)+'\n');known.chmod(0o600)
    PHASE='source';php=(ROOT/'tools/audit_model_profile_current.php').read_bytes()
    if b'__CUTOFF_EPOCH__' in php: raise ValueError()
    encoded=base64.b64encode(php).decode()
    guard=f'test "$(git rev-parse HEAD)" = "{expected}" || {{ echo runtime_sha_moved >&2; exit 21; }}'
    clean='git diff --quiet HEAD -- services project.php tools/build_conversion_observations.py || { echo pinned_source_dirty >&2; exit 22; }'
    commands=['set -euo pipefail','cd /var/www/anytoour/data/www/app.anytoour.ru',guard,clean,"printf '%s' '"+encoded+"' | base64 --decode | php",guard,clean]
    PHASE='remote';result=subprocess.run(['ssh','-i',str(temp/'model_profile_key'),'-o','BatchMode=yes','-o','StrictHostKeyChecking=yes','-o','UserKnownHostsFile='+str(known),'-o','ConnectTimeout=10',user+'@'+host,'bash -se'],input='\n'.join(commands)+'\n',capture_output=True,text=True,timeout=90)
    if result.returncode or result.stderr:
        safe=result.stderr.strip()
        if re.fullmatch(r'(?:runtime_sha_moved|pinned_source_dirty|fixed_profile_read_failed:(?:bootstrap|clock|candidates|detail|serialize):(?:[A-Z0-9]{5}|none):[0-9]{1,6})',safe): raise FixedFailure(safe)
        raise FixedFailure('remote_read_failed_exit_'+str(result.returncode))
    if len(result.stdout.encode())>MAX_BYTES: raise ValueError()
    PHASE='payload';data=json.loads(result.stdout)
    if data.get('database_writes') is not False or data.get('conversion_uploads')!=0 or not 0<=len(data['cases'])<=5 or not 0<=data['candidate_count']<=100: raise ValueError()
    frozen={k:v for k,v in data.items() if k!='captured_at'}
    digest=hashlib.sha256(json.dumps(frozen,sort_keys=True,separators=(',',':'),ensure_ascii=False).encode()).hexdigest()
    out=temp/'model-profile-artifact';out.mkdir(mode=0o700)
    manifest={'schema_version':1,'mode':mode,'installed_sha':expected,'run_id':os.environ['GITHUB_RUN_ID'],'run_attempt':os.environ['GITHUB_RUN_ATTEMPT'],'captured_at':data['captured_at'],'scope_cutoff_epoch':data['scope_cutoff_epoch'],'input_sha256':digest,'candidate_count':data['candidate_count'],'case_count':len(data['cases']),'skipped':data['skipped'],'script_sha256':hashlib.sha256(php).hexdigest(),'database_writes':False,'conversion_uploads':0,'model_calls':0,'customer_sends':0}
    if mode=='capture':
        PHASE='encryption'
        wire=json.dumps({'manifest':manifest,'snapshot':data},sort_keys=True,separators=(',',':'),ensure_ascii=False).encode()
        recipient=pyrage.x25519.Recipient.from_str((ROOT/'docs/diagnostics-recipient.txt').read_text().strip())
        sealed=pyrage.encrypt(wire,[recipient]);(out/'model-profile.age').write_bytes(sealed)
        manifest['ciphertext_sha256']=hashlib.sha256(sealed).hexdigest()
        manifest['ciphertext_bytes']=len(sealed)
        PHASE='wheel';wheels=list((temp/'profile-wheel').glob('pyrage-1.3.0-*.whl'))
        if len(wheels)!=1 or hashlib.sha256(wheels[0].read_bytes()).hexdigest()!='ab066b22925c5a0ec5fead2e21e4586b21d5da730055c7e46caa978bd99de936': raise ValueError()
        (out/wheels[0].name).write_bytes(wheels[0].read_bytes())
    else:
        if not re.fullmatch(r'[a-f0-9]{64}',expected_digest): raise ValueError()
        manifest['input_unchanged']=digest==expected_digest
    PHASE='manifest';(out/'manifest.json').write_text(json.dumps(manifest,sort_keys=True,indent=2)+'\n')
    print(json.dumps(manifest,sort_keys=True))

if __name__=='__main__':
    try: run()
    except FixedFailure as exc: sys.exit(str(exc))
    except Exception: sys.exit('fixed_profile_operation_failed:'+PHASE)
