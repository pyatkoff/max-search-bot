#!/usr/bin/env python3
"""Finite carrier proposal. No network on import, prepare or controls."""
import argparse,base64,datetime as dt,hashlib,io,json,os,pathlib,re,shlex,socket,stat,subprocess,tarfile,tempfile

REPO='pyatkoff/max-search-bot'
OP='F1142-OWNER2-READONLY-20261027-1'
ROOT='/var/www/anytoour/data/www/app.anytoour.ru'
HOST_PIN='SHA256:/5mPWmGBQ6UPnNQDEHLOCy7dCpxdz+FxQ4zVOjqE0F0'
RUNTIME_FILES=frozenset({'source/carrier.py','source/remote.php','source/projection.php','tools/requirements-encrypted-evidence.txt','.github/workflows/f1142-readonly.yml'})
META_FILES=frozenset({'execution-manifest.json','request.json','ROOT-APPROVAL.json'})
APP_FILES=frozenset({'config.example.php','services/ConversationDb.php','services/ProjectAccessService.php','services/ProjectConfig.php','services/TourSearchHandoffService.php'})
def require(ok,code):
    if not ok: raise RuntimeError(code)
def sha(raw):return hashlib.sha256(raw).hexdigest()
def encoded(x):return json.dumps(x,sort_keys=True,separators=(',',':'),ensure_ascii=False).encode()
def crypto():
    try: import pyrage
    except ImportError: raise RuntimeError('approved_pyrage_dependency_missing') from None
    return pyrage
def recipient(value,expected=None):
    require(isinstance(value,str) and value.isascii(),'recipient_missing_or_invalid')
    # Permit exactly one trailing LF. Reject whitespace, extra lines and defaults.
    p=value[:-1] if value.endswith('\n') else value
    require(re.fullmatch(r'age1[0-9a-z]{58}',p) is not None,'recipient_missing_or_invalid')
    f=sha(p.encode('ascii'))
    require(expected is None or f==expected,'recipient_fingerprint_mismatch')
    r=crypto().x25519.Recipient.from_str(p)
    require(str(r)==p,'recipient_not_canonical')
    return p,f,r
def validate_manifest(m):
    require(type(m) is dict and set(m)=={'schema_version','repository','operation_id','production_sha','execution_enabled','runtime_files','runtime_pins','approved_projection_sha256'},'manifest_shape')
    require(type(m['schema_version']) is int and m['schema_version']==1 and m['repository']==REPO and m['operation_id']==OP,'manifest_scope')
    require(type(m['execution_enabled']) is bool and re.fullmatch('[0-9a-f]{40}',m['production_sha'] or '') is not None,'manifest_source')
    require(type(m['runtime_files']) is dict and set(m['runtime_files'])==RUNTIME_FILES,'runtime_inventory_rejected')
    require(type(m['runtime_pins']) is dict and set(m['runtime_pins'])==APP_FILES,'app_inventory_rejected')
    require(m['approved_projection_sha256']=='95c7605ed79839aa1dc636d8351981d4bb9730d1b7fd0046e1161d3975489ac1','projection_pin_rejected')
    for n,v in m['runtime_files'].items():
        require(type(v) is str and re.fullmatch('[0-9a-f]{64}',v) is not None,'runtime_digest_invalid')
    require(m['runtime_files']['source/projection.php']==m['approved_projection_sha256'],'projection_digest_mismatch')
    for n,v in m['runtime_pins'].items():
        require(type(v) is dict and set(v)=={'git_blob','sha256'} and re.fullmatch('[0-9a-f]{40}',v['git_blob'] or '') is not None and re.fullmatch('[0-9a-f]{64}',v['sha256'] or '') is not None,'app_digest_invalid')
def validate_request(r,manifest,approval,parse_crypto=True):
    validate_manifest(manifest)
    require(type(r) is dict,'request_shape')
    require(set(r)=={'operation_id','production_sha','recipient_age','recipient_fingerprint_sha256','purpose'},'request_shape')
    require(r['operation_id']==OP and r['purpose']=='f1142_owner2_binding_readonly','operation_mismatch')
    require(r['production_sha']==manifest['production_sha'],'source_mismatch')
    require(re.fullmatch('[0-9a-f]{64}',r['recipient_fingerprint_sha256'] or '') is not None,'recipient_fingerprint_missing')
    public=r['recipient_age']
    require(type(public) is str and public.isascii(),'recipient_missing_or_invalid')
    public=public[:-1] if public.endswith('\n') else public
    require(re.fullmatch(r'age1[0-9a-z]{58}',public) is not None,'recipient_missing_or_invalid')
    require(sha(public.encode('ascii'))==r['recipient_fingerprint_sha256'],'recipient_fingerprint_mismatch')
    require(type(approval) is dict and set(approval)=={'approved','independent_reviewer','manifest_sha256','request_sha256','bootstrap_definition_only_reviewed','recipient_roundtrip_verified'},'approval_shape')
    require(approval.get('approved') is True and approval.get('independent_reviewer')=='ROOT','independent_review_missing')
    require(approval.get('manifest_sha256')==sha(encoded(manifest)) and approval.get('request_sha256')==sha(encoded(r)),'review_digest_mismatch')
    require(approval.get('bootstrap_definition_only_reviewed') is True,'bootstrap_source_review_missing')
    require(approval.get('recipient_roundtrip_verified') is True,'recipient_roundtrip_missing')
    if not parse_crypto:return public,None
    p,f,key=recipient(public,r['recipient_fingerprint_sha256'])
    return p,key
def envelope(result,request,audit_sha,run_id):
    require(result.get('read_only_rollback') is True and result.get('database_writes')==0,'rollback_unconfirmed')
    return {'schema_version':1,'repository':REPO,'production_sha':request['production_sha'],
        'recipient':request['recipient_age'].rstrip('\n'),'generated_at':dt.datetime.now(dt.timezone.utc).isoformat(),
        'purpose':'f1142_owner2_binding_readonly','operation_id':OP,'source':{'audit_sha':audit_sha,'run_id':run_id},
        'rollback_proof':True,'projection':result}
def ciphertext(raw,key):
    require(len(raw)<=16384,'private_envelope_bound')
    return crypto().encrypt(raw,[key])
def read_relative(base,name,limit):
    require(name in RUNTIME_FILES|META_FILES,'unknown_member')
    path=pathlib.PurePosixPath(name)
    require(not path.is_absolute() and str(path)==name and all(p not in ('','..','.') for p in path.parts),'unsafe_member')
    base=pathlib.Path(base)
    require(base.is_absolute(),'base_not_absolute')
    # Open every absolute and relative directory component with NOFOLLOW, not a
    # resolve-then-open TOCTOU check. No body read occurs until the whole walk passes.
    fd=os.open('/',os.O_RDONLY|os.O_DIRECTORY)
    try:
        for component in base.parts[1:]+path.parts[:-1]:
            require(component not in ('','..','.'),'unsafe_parent')
            nxt=os.open(component,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW,dir_fd=fd);os.close(fd);fd=nxt
        body=os.open(path.name,os.O_RDONLY|os.O_NOFOLLOW,dir_fd=fd)
        try:
            meta=os.fstat(body)
            require(stat.S_ISREG(meta.st_mode) and meta.st_nlink==1 and meta.st_size<=limit,'member_type_or_bound')
            data=b''
            while len(data)<=limit:
                chunk=os.read(body,min(65536,limit+1-len(data)))
                if not chunk:break
                data+=chunk
            require(len(data)<=limit,'member_bound')
            return data
        finally:os.close(body)
    finally:os.close(fd)
def sealed_files(base,manifest):
    validate_manifest(manifest)
    # Exact fixed inventory only; neither private review notes nor arbitrary paths.
    for name in sorted(RUNTIME_FILES):
        require(sha(read_relative(base,name,65536))==manifest['runtime_files'][name],'packet_source_mismatch')
def preflight(base,request,approval,manifest,parse_crypto=True):
    # Metadata shape, scope and ROOT digests BEFORE runtime body reads.
    public,key=validate_request(request,manifest,approval,parse_crypto)
    require(manifest['execution_enabled'] is True,'source_packet_execution_disabled')
    sealed_files(base,manifest)
    return public,key
def load_metadata(base):
    return tuple(json.loads(read_relative(base,n,16384)) for n in ('execution-manifest.json','request.json','ROOT-APPROVAL.json'))
def trusted_host(host,temp):
    require({v[4][0] for v in socket.getaddrinfo(host,22,type=socket.SOCK_STREAM)}=={'5.45.122.54'},'canonical_dns_mismatch')
    matches=[]
    for _ in range(2):
        r=subprocess.run(['ssh-keyscan','-t','ed25519','-T','10',host],capture_output=True,timeout=25)
        for line in r.stdout.decode('ascii','ignore').splitlines():
            p=line.split()
            if len(p)!=3 or p[1]!='ssh-ed25519':continue
            try:f='SHA256:'+base64.b64encode(hashlib.sha256(base64.b64decode(p[2],validate=True)).digest()).decode().rstrip('=')
            except ValueError:continue
            if f==HOST_PIN:matches.append(line)
        if matches:break
    require(bool(matches),'canonical_host_pin_not_seen')
    path=temp/'known_hosts';path.write_text('\n'.join(matches)+'\n');path.chmod(0o600);return path
def remote_script(base,manifest,request):
    work='/tmp/f1142-owner2-readonly-20261027-1'
    out=io.BytesIO()
    with tarfile.open(fileobj=out,mode='w') as t:
        for local,name in [('source/remote.php','remote.php'),('source/projection.php','projection.php')]:
            raw=read_relative(base,local,65536);info=tarfile.TarInfo(name);info.size=len(raw);info.mode=0o600;t.addfile(info,io.BytesIO(raw))
    archive=out.getvalue();q=shlex.quote
    lines=['set -euo pipefail','umask 077','cd '+q(ROOT),'test "$(git rev-parse HEAD)" = '+q(manifest['production_sha']),'git diff --quiet','git diff --cached --quiet']
    for path,pin in manifest['runtime_pins'].items():lines.append('test "$(sha256sum '+q(path)+' | cut -d\' \' -f1)" = '+q(pin['sha256']))
    # Exclusive operation intent is retained after any attempt, NEVER reset/reused.
    lines += ['mkdir -m 700 '+q(work),"printf '%s' "+q(sha(encoded(request)))+' > '+q(work+'/request.sha256'),
        "trap 'rm -f "+work+"/remote.php "+work+"/projection.php "+work+"/payload.tar' EXIT",
        "printf '%s' "+q(base64.b64encode(archive).decode())+' | base64 --decode > '+q(work+'/payload.tar'),
        'test "$(sha256sum '+q(work+'/payload.tar')+' | cut -d\' \' -f1)" = '+q(sha(archive)),
        'tar -xf '+q(work+'/payload.tar')+' -C '+q(work),
        'php -d display_errors=0 -d log_errors=0 '+q(work+'/remote.php')+' '+q(ROOT)+' '+q(manifest['production_sha']),
        'test "$(git rev-parse HEAD)" = '+q(manifest['production_sha'])]
    for path,pin in manifest['runtime_pins'].items():lines.append('test "$(sha256sum '+q(path)+' | cut -d\' \' -f1)" = '+q(pin['sha256']))
    return '\n'.join(lines)+'\n'
def ssh_command(identity,known,user,host):
    require(re.fullmatch('[a-z_][a-z0-9_-]{0,31}',user or '') is not None and re.fullmatch('[A-Za-z0-9][A-Za-z0-9.-]{0,252}',host or '') is not None,'ssh_target_invalid')
    return ['ssh','-F','/dev/null','-i',str(identity),'-o','IdentitiesOnly=yes','-o','IdentityAgent=none',
        '-o','AddKeysToAgent=no','-o','ControlMaster=no','-o','ControlPath=none','-o','ProxyCommand=none','-o','ProxyJump=none',
        '-o','BatchMode=yes','-o','StrictHostKeyChecking=yes','-o','GlobalKnownHostsFile=/dev/null',
        '-o','UserKnownHostsFile='+str(known),'-o','ConnectTimeout=10','-o','ServerAliveInterval=10','-o','ServerAliveCountMax=2',user+'@'+host,'bash -se']
def ssh_environment():
    child_env=os.environ.copy();child_env.pop('SSH_AUTH_SOCK',None);child_env.pop('SSH_AGENT_PID',None)
    return child_env
def execute(base,request,approval,output,manifest):
    # All recipient/source/review/attempt guards precede DNS, keyscan, SSH or private read.
    public,key=preflight(base,request,approval,manifest)
    require(os.environ.get('GITHUB_REPOSITORY')==REPO and os.environ.get('GITHUB_RUN_ATTEMPT')=='1','original_run_required')
    require(not output.exists(),'ciphertext_output_exists')
    require(re.fullmatch('[0-9a-f]{40}',os.environ.get('GITHUB_SHA','')) is not None,'audit_sha_required')
    identity=pathlib.Path(os.environ['F1142_EXISTING_IDENTITY_FILE'])
    require(identity.is_file() and not identity.is_symlink() and identity.stat().st_mode&0o077==0,'identity_custody_invalid')
    # Never inspect the private key as data. OpenSSH uses the existing credential in place.
    with tempfile.TemporaryDirectory(prefix='f1142-carrier-') as td:
        temp=pathlib.Path(td);temp.chmod(0o700)
        known=trusted_host(os.environ['DEPLOY_HOST'],temp)
        cmd=ssh_command(identity,known,os.environ['DEPLOY_USER'],os.environ['DEPLOY_HOST'])
        child_env=ssh_environment()
        # No raw stdout/stderr is ever logged, committed or uploaded.
        r=subprocess.run(cmd,input=remote_script(base,manifest,request).encode(),capture_output=True,timeout=90,env=child_env)
        require(r.returncode==0 and len(r.stdout)<=8192,'remote_outcome_unconfirmed_no_retry')
        result=json.loads(r.stdout);raw=encoded(envelope(result,request,os.environ['GITHUB_SHA'],os.environ['GITHUB_RUN_ID']))
        blob=ciphertext(raw,key)
        with output.open('xb') as f:os.chmod(output,0o600);f.write(blob)
        print(json.dumps({'status':'encrypted_receipt_saved','database_writes':0,'ciphertext_sha256':sha(blob)}))
def main():
    p=argparse.ArgumentParser();p.add_argument('--preflight-metadata',action='store_true');p.add_argument('--preflight',action='store_true');p.add_argument('--output')
    a=p.parse_args()
    try:
        base=pathlib.Path(__file__).resolve().parent.parent;m,r,v=load_metadata(base)
        if a.preflight_metadata or a.preflight:preflight(base,r,v,m,parse_crypto=not a.preflight_metadata)
        else:
            require(a.output is not None,'ciphertext_path_missing');execute(base,r,v,pathlib.Path(a.output),m)
        return 0
    except Exception:
        print('{"status":"stopped_no_retry","database_writes_verified":false}');return 1
if __name__=='__main__':raise SystemExit(main())
