#!/usr/bin/env python3
"""Single admitted private read, no business writes/models/customer or goal calls."""
import base64, hashlib, json, os, re, socket, subprocess, sys
from pathlib import Path
import pyrage

ROOT=Path(__file__).resolve().parents[1]
PHASE='validation'
REMOTE_ATTEMPTED=False

class FixedFailure(Exception): pass

def run():
    global PHASE,REMOTE_ATTEMPTED
    expected=os.environ['EXPECTED_RUNTIME_SHA'];host=os.environ['DEPLOY_HOST'];user=os.environ['DEPLOY_USER']
    if not re.fullmatch(r'[a-f0-9]{40}',expected): raise FixedFailure('invalid_pin')
    if not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9.-]{0,252}',host) or not re.fullmatch(r'[A-Za-z0-9_][A-Za-z0-9_.-]{0,63}',user): raise FixedFailure('invalid_target')
    temp=Path(os.environ['RUNNER_TEMP'])
    PHASE='host_pin'
    if {v[4][0] for v in socket.getaddrinfo(host,22,type=socket.SOCK_STREAM)}!={'5.45.122.54'}: raise FixedFailure('canonical_target_changed')
    scan=subprocess.run(['ssh-keyscan','-T','10',host],capture_output=True,text=True,timeout=30)
    pinned=[]
    for line in scan.stdout.splitlines():
        p=line.split()
        if len(p)!=3 or p[0].startswith('#'): continue
        try: fp='SHA256:'+base64.b64encode(hashlib.sha256(base64.b64decode(p[2],validate=True)).digest()).decode().rstrip('=')
        except ValueError: continue
        if fp=='SHA256:/5mPWmGBQ6UPnNQDEHLOCy7dCpxdz+FxQ4zVOjqE0F0': pinned.append(host+' '+p[1]+' '+p[2])
    if not pinned: raise FixedFailure('canonical_host_pin_not_seen')
    known=temp/'owner_model_hosts';known.write_text('\n'.join(pinned)+'\n');known.chmod(0o600)
    PHASE='source'
    php=(ROOT/'tools/audit_owner_model_episodes.php').read_bytes()
    if b'__ISSUED_EPOCH__' in php: raise FixedFailure('unfrozen_admission')
    encoded=base64.b64encode(php).decode()
    guard=f'test "$(git rev-parse HEAD)" = "{expected}" || {{ echo runtime_sha_moved >&2; exit 21; }}'
    clean='git diff --quiet HEAD -- services project.php tools/ModeledConversionCandidateSelection.php tools/profile_conversion_snapshot.py tools/extract_conversion_profiles.py tools/build_conversion_observations.py || { echo pinned_source_dirty >&2; exit 22; }'
    commands=['set -euo pipefail','cd /var/www/anytoour/data/www/app.anytoour.ru',guard,clean,"printf '%s' '"+encoded+"' | base64 --decode | php",guard,clean]
    PHASE='remote';REMOTE_ATTEMPTED=True
    result=subprocess.run(['ssh','-i',str(temp/'owner_model_key'),'-o','BatchMode=yes','-o','StrictHostKeyChecking=yes','-o','UserKnownHostsFile='+str(known),'-o','ConnectTimeout=10',user+'@'+host,'bash -se'],input='\n'.join(commands)+'\n',capture_output=True,text=True,timeout=120)
    if result.returncode or result.stderr:
        safe=result.stderr.strip()
        if re.fullmatch(r'(?:runtime_sha_moved|pinned_source_dirty|owner_episode_read_failed:[a-z_]{1,32}:(?:[A-Z0-9]{5}|none):[0-9]{1,6})',safe): raise FixedFailure(safe)
        raise FixedFailure('remote_read_failed_exit_'+str(result.returncode))
    PHASE='payload'
    if len(result.stdout.encode())>2*1024*1024: raise FixedFailure('payload_limit')
    data=json.loads(result.stdout);receipt=data['receipt'];snapshot=data['snapshot']
    if not (receipt['prior_exclusion_verified'] is True and receipt['prior_people_excluded']==5
            and receipt['metadata_candidates_total']<=100 and receipt['candidate_prefixes_read']<=5
            and receipt['all_message_rows_read']<=250 and receipt['old_transcript_rows_read']==0
            and snapshot['database_writes'] is False and snapshot['conversion_uploads']==0
            and len(snapshot['cases'])<=5): raise FixedFailure('receipt_limit')
    if sum(len(c['messages']) for c in snapshot['cases'])>receipt['all_message_rows_read']: raise FixedFailure('row_accounting')
    if any(len(c['messages'])>50 for c in snapshot['cases']): raise FixedFailure('case_row_limit')
    # Only aggregate counts/digests in plaintext manifest, never native scope tuples.
    manifest={'schema_version':1,'operation':'owner-admitted1140-6409686245-v1','installed_sha':expected,
        'run_id':os.environ['GITHUB_RUN_ID'],'run_attempt':os.environ['GITHUB_RUN_ATTEMPT'],
        'captured_at':snapshot['captured_at'],'scope_cutoff_epoch':snapshot['scope_cutoff_epoch'],
        'input_sha256':hashlib.sha256(json.dumps(data,sort_keys=True,separators=(',',':'),ensure_ascii=False).encode()).hexdigest(),
        'script_sha256':hashlib.sha256(php).hexdigest(),'receipt':receipt,'skipped':snapshot['skipped'],
        'model_calls':0,'customer_sends':0,'conversion_uploads':0,'database_writes':False}
    PHASE='encryption';wire=json.dumps({'manifest':manifest,**data},sort_keys=True,separators=(',',':'),ensure_ascii=False).encode()
    recipient=pyrage.x25519.Recipient.from_str((ROOT/'docs/diagnostics-recipient.txt').read_text().strip())
    sealed=pyrage.encrypt(wire,[recipient])
    out=temp/'owner-model-artifact';out.mkdir(mode=0o700)
    (out/'owner-model-episodes.age').write_bytes(sealed)
    manifest['ciphertext_sha256']=hashlib.sha256(sealed).hexdigest();manifest['ciphertext_bytes']=len(sealed)
    wheels=list((temp/'owner-model-wheel').glob('pyrage-1.3.0-*.whl'))
    if len(wheels)!=1 or hashlib.sha256(wheels[0].read_bytes()).hexdigest()!='ab066b22925c5a0ec5fead2e21e4586b21d5da730055c7e46caa978bd99de936': raise FixedFailure('wheel_pin')
    (out/wheels[0].name).write_bytes(wheels[0].read_bytes())
    code=out/'code';code.mkdir(mode=0o700)
    for name in ['profile_conversion_snapshot.py','extract_conversion_profiles.py','build_conversion_observations.py','ModeledConversionCandidateSelection.php']:
        (code/name).write_bytes((ROOT/'tools'/name).read_bytes())
    for f in out.rglob('*'):
        if f.is_file(): f.chmod(0o600)
    (out/'manifest.json').write_text(json.dumps(manifest,sort_keys=True,indent=2)+'\n');(out/'manifest.json').chmod(0o600)
    print(json.dumps(manifest,sort_keys=True))

if __name__=='__main__':
    try: run()
    except Exception as exc:
        reason=str(exc) if isinstance(exc,FixedFailure) else 'fixed_failure'
        print(json.dumps({'ok':False,'phase':PHASE,'reason':reason,'remote_attempted':REMOTE_ATTEMPTED,'source_counts':'unknown_if_remote_attempted'}),file=sys.stderr)
        sys.exit(1)
