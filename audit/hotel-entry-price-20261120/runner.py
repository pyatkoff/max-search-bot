"""One admitted SELECT-only PHP stdin read; retain only an authenticated ciphertext."""
import base64
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile

from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import padding
from cryptography.hazmat.primitives.ciphers.aead import AESGCM

REPO='pyatkoff/max-search-bot'
BRANCH='audit/hotel-entry-price-20261120'
BASE='b4b65daaa472c15426a244b87ede3e6c0394626b'
ROOT=Path('audit/hotel-entry-price-20261120')
PIN='SHA256:/5mPWmGBQ6UPnNQDEHLOCy7dCpxdz+FxQ4zVOjqE0F0'
WINDOW={'from_utc':'2026-11-11 21:00:00','until_utc_exclusive':'2026-11-18 21:00:00'}
REMOTE='unset GIT_DIR GIT_WORK_TREE GIT_COMMON_DIR GIT_INDEX_FILE GIT_CONFIG_PARAMETERS GIT_CONFIG_COUNT GIT_OBJECT_DIRECTORY GIT_ALTERNATE_OBJECT_DIRECTORIES; cd /var/www/anytoour/data/www/app.anytoour.ru && GIT_OPTIONAL_LOCKS=0 php'

class Stop(Exception): pass

def need(condition, code):
    if not condition: raise Stop(code)

def closed_sources():
    pins={}
    for name, env in (('program.php','PROGRAM_SHA256'),('tests.php','TESTS_SHA256'),('runner.py','RUNNER_SHA256')):
        expected=os.environ.get(env,'')
        need(re.fullmatch('[a-f0-9]{64}',expected) is not None,'SOURCE_PIN_MISSING')
        need(hashlib.sha256((ROOT/name).read_bytes()).hexdigest()==expected,'SOURCE_CHANGED')
        pins[name]=expected
    return pins

def original_event():
    env=os.environ
    need(env.get('GITHUB_REPOSITORY')==REPO and env.get('GITHUB_ACTOR')=='pyatkoff'
         and env.get('GITHUB_EVENT_NAME')=='push' and env.get('GITHUB_RUN_ATTEMPT')=='1'
         and env.get('GITHUB_REF')=='refs/heads/'+BRANCH,'ORIGINAL_EVENT_REQUIRED')
    need(re.fullmatch('[a-f0-9]{40}',env.get('GITHUB_SHA','')) is not None,'HEAD_INVALID')
    need(re.fullmatch('[1-9][0-9]{0,19}',env.get('GITHUB_RUN_ID','')) is not None,'RUN_INVALID')
    event=json.loads(Path(env['GITHUB_EVENT_PATH']).read_bytes())
    need(event.get('created') is True and event.get('deleted') is False and event.get('forced') is False
         and event.get('before')=='0'*40 and event.get('after')==env['GITHUB_SHA']
         and event.get('ref')==env['GITHUB_REF'],'ORIGINAL_BRANCH_CREATION_REQUIRED')
    return env['GITHUB_SHA'],int(env['GITHUB_RUN_ID'])

def api(path):
    raw=subprocess.check_output(['gh','api','--method','GET','repos/'+REPO+'/'+path],timeout=20,stderr=subprocess.DEVNULL)
    need(len(raw)<=1_000_000,'PROVENANCE_SIZE_BOUND')
    return json.loads(raw)

def admission_path(): return Path(os.environ['RUNNER_TEMP'])/'hotel-entry-price-admission.json'

def admit():
    head,run_id=original_event();pins=closed_sources()
    actual=subprocess.check_output(['git','rev-parse','HEAD'],text=True,timeout=10).strip()
    parent=subprocess.check_output(['git','rev-parse','HEAD^'],text=True,timeout=10).strip()
    need(actual==head and parent==BASE,'CHECKOUT_PROVENANCE_MISMATCH')
    need(api('git/ref/heads/main')['object']['sha']==BASE,'MAIN_MOVED')
    need(api('git/ref/heads/'+BRANCH)['object']['sha']==head,'CARRIER_MOVED')
    run=api('actions/runs/'+str(run_id))
    need(run.get('id')==run_id and run.get('head_sha')==head and run.get('run_attempt')==1
         and run.get('event')=='push' and run.get('head_branch')==BRANCH,'RUN_PROVENANCE_MISMATCH')
    for file in ('program.php','tests.php'):
        subprocess.run(['php','-n','-l',str(ROOT/file)],check=True,timeout=10,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    subprocess.run(['php','-n',str(ROOT/'tests.php')],check=True,timeout=20)
    admission_path().write_text(json.dumps({'state':'SELECT_ONLY_ANALYTICS_ADMITTED','head_sha':head,
        'run_id':run_id,'parent_sha':BASE,'source_sha256':pins,'window':WINDOW,
        'observed_at':dt.datetime.now(dt.timezone.utc).isoformat()}))
    print('SELECT_ONLY_ANALYTICS_ADMITTED')

def verify_admission():
    head,run_id=original_event();pins=closed_sources()
    a=json.loads(admission_path().read_bytes())
    need(a.get('state')=='SELECT_ONLY_ANALYTICS_ADMITTED' and a.get('head_sha')==head
         and a.get('run_id')==run_id and a.get('parent_sha')==BASE
         and a.get('source_sha256')==pins and a.get('window')==WINDOW,'ADMISSION_BINDING_INVALID')
    age=(dt.datetime.now(dt.timezone.utc)-dt.datetime.fromisoformat(a['observed_at'])).total_seconds()
    need(0<=age<=120,'ADMISSION_EXPIRED')
    return a

def no_private_fields(value):
    forbidden={'conversation_id','entry_id','snapshot_json','post_text','text','dialogues','raw','token','yclid','client_id','profile_id'}
    if isinstance(value,dict):
        need(not (set(value)&forbidden),'PRIVATE_FIELD_IN_OUTPUT')
        for v in value.values(): no_private_fields(v)
    elif isinstance(value,list):
        for v in value: no_private_fields(v)

def validate_report(report):
    need(isinstance(report,dict),'REPORT_SHAPE_INVALID')
    if report.get('ok') is False:
        need(set(report)=={'ok','error'} and isinstance(report['error'],str)
             and re.fullmatch('[a-z_]{1,80}',report['error']) is not None,'UNSAFE_ERROR_OUTPUT')
        return report
    need(report.get('ok') is True and report.get('installed_sha')==BASE,'RUNTIME_PROVENANCE_MISMATCH')
    need(set(report)=={'ok','installed_sha','db_writes','provider_calls','message_sends','aggregate'},'REPORT_FIELDS_INVALID')
    for k in ('db_writes','provider_calls','message_sends'):
        need(type(report.get(k)) is int and report[k]==0,'SIDE_EFFECT_FLAGS_INVALID')
    aggregate=report.get('aggregate');need(isinstance(aggregate,dict),'AGGREGATE_MISSING')
    need(set(aggregate)=={'schema','window','coverage','posts','semantics'}
         and aggregate['schema']=='post_entry_price_aggregate_v1','AGGREGATE_FIELDS_INVALID')
    window=aggregate.get('window',{})
    need(window=={**WINDOW,'business_timezone':'Europe/Moscow','days':7},'WINDOW_MISMATCH')
    coverage=aggregate['coverage'];posts=aggregate['posts'];semantics=aggregate['semantics']
    counts={'entry_rows_read','unavailable_publication_entries','invalid_binding_entries','distinct_pinned_versions',
        'version_rows_read','source_key_unavailable_entries','global_entry_rows_scanned','global_window_entries'}
    need(isinstance(coverage,dict) and set(coverage)==counts|{'complete_within_bound','price_state_entries'},'COVERAGE_FIELDS_INVALID')
    need(all(type(coverage[k]) is int and 0<=coverage[k]<=10000 for k in counts)
         and coverage['complete_within_bound'] is True and coverage['distinct_pinned_versions']<=1000
         and coverage['version_rows_read']<=coverage['distinct_pinned_versions'],'COVERAGE_COUNTS_INVALID')
    states={'missing','oversize','unparseable','parsed'}
    need(isinstance(coverage['price_state_entries'],dict) and set(coverage['price_state_entries'])==states
         and all(type(v) is int and 0<=v<=10000 for v in coverage['price_state_entries'].values()),'PRICE_COUNTS_INVALID')
    expected_semantics={'basis':'registry_accepted_entries_event_join_not_rechecked','entries_are_clicks':False,
        'dialogues_are_unique_people':False,'paid_organic':'unknown','cross_post_or_version_dialogues_additive':False,
        'leads_measured':False,'current_post_liveness_verified':False,
        'price_basis':'pure_parse_of_exact_entry_pinned_publication_snapshot','price_is_frozen_modeled_context':False,
        'price_verified':False,'point_is':'advertised_range_midpoint_or_floor_not_sales_mean',
        'source_key_basis':'current_publication_routing_not_hotel_identity','missing_price_is_zero':False,
        'customer_texts_read':False,'profiles_read':False}
    need(semantics==expected_semantics,'SEMANTICS_INVALID')
    need(isinstance(posts,list) and len(posts)<=1000,'POSTS_BOUND')
    post_fields={'publication_id','version_id','post_id','platform','channel_id','source_key','content_sha256','price',
        'entries','distinct_dialogues','first_entry_utc','last_entry_utc'}
    seen=set();totals={k:0 for k in states}
    for post in posts:
        need(isinstance(post,dict) and set(post)==post_fields,'POST_FIELDS_INVALID')
        need(all(type(post[k]) is int and 0<post[k]<10**19 for k in ('publication_id','version_id')),'PUBLICATION_ID_INVALID')
        need(post['platform'] in ('max','telegram') and isinstance(post['channel_id'],str)
             and re.fullmatch('-?[0-9]{1,64}',post['channel_id']) is not None,'CHANNEL_INVALID')
        need(isinstance(post['post_id'],str) and re.fullmatch('[A-Za-z0-9_.:-]{0,256}',post['post_id']) is not None,'POST_ID_INVALID')
        source=post['source_key'];digest=post['content_sha256']
        need(source is None or isinstance(source,str) and re.fullmatch('[A-Za-z][A-Za-z0-9_:-]{0,95}',source) is not None,'SOURCE_KEY_INVALID')
        need(digest is None or isinstance(digest,str) and re.fullmatch('[a-f0-9]{64}',digest) is not None,'CONTENT_HASH_INVALID')
        need(type(post['entries']) is int and 0<post['entries']<=10000 and type(post['distinct_dialogues']) is int
             and 0<post['distinct_dialogues']<=post['entries'],'GROUP_COUNT_INVALID')
        need(all(isinstance(post[k],str) and re.fullmatch(r'\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?',post[k]) for k in ('first_entry_utc','last_entry_utc'))
             and WINDOW['from_utc']<=post['first_entry_utc']<=post['last_entry_utc']<WINDOW['until_utc_exclusive'],'ENTRY_CLOCK_INVALID')
        key=(post['platform'],post['channel_id'],post['publication_id'],post['version_id'])
        need(key not in seen,'GROUP_DUPLICATED');seen.add(key)
        price=post['price']
        need(isinstance(price,dict) and set(price)=={'state','low','high','point','unit','party_size','basis','price_verified'}
             and price['state'] in states and price['price_verified'] is False,'PRICE_FIELDS_INVALID')
        if price['state']=='parsed':
            need(type(price['low']) is int and type(price['point']) is int and 10000<=price['low']<=price['point']<=10000000
                 and (price['high'] is None or type(price['high']) is int and price['point']<=price['high']<=10000000)
                 and price['unit'] in ('unknown','party_total','per_person')
                 and (price['party_size'] is None or type(price['party_size']) is int and 0<=price['party_size']<=99)
                 and price['basis'] in ('advertised_floor_proxy','advertised_range_proxy'),'PRICE_VALUE_INVALID')
        else:
            need(all(price[k] is None for k in ('low','high','point','unit','party_size','basis')),'MISSING_PRICE_NOT_NULL')
        totals[price['state']]+=post['entries']
    need(totals==coverage['price_state_entries'] and sum(totals.values())+coverage['unavailable_publication_entries']
         +coverage['invalid_binding_entries']==coverage['entry_rows_read'],'ENTRY_RECONCILIATION_FAILED')
    no_private_fields(report)
    return report

def execute(work):
    admission=verify_admission()
    need(all(os.environ.get(k) for k in ('SSH_KEY','SSH_HOST','SSH_USER')),'ESTABLISHED_SSH_SETTINGS_UNAVAILABLE')
    try:
        raw=os.environ['SSH_KEY'].replace('\r','')
        raw=raw.replace('\\n','\n') if 'PRIVATE KEY' in raw else base64.b64decode(raw,validate=True).decode()
        key=work/'key';key.write_text(raw.rstrip()+'\n');key.chmod(0o600)
    except Exception: raise Stop('SSH_KEY_FORMAT_INVALID') from None
    host,user=os.environ['SSH_HOST'],os.environ['SSH_USER']
    need(re.fullmatch('[A-Za-z0-9_.:-]+',host) is not None and not host.startswith('-')
         and re.fullmatch('[A-Za-z0-9_-]+',user) is not None,'SSH_DESTINATION_INVALID')
    scan=subprocess.run(['ssh-keyscan','-T','10','-t','ed25519,ecdsa,rsa',host],capture_output=True,text=True,timeout=35)
    matches=[]
    for line in scan.stdout.splitlines():
        fields=line.split()
        if len(fields)<3 or fields[0].startswith('#'):continue
        try:fp='SHA256:'+base64.b64encode(hashlib.sha256(base64.b64decode(fields[2],validate=True)).digest()).decode().rstrip('=')
        except Exception:continue
        if fp==PIN:matches.append(line)
    need(bool(matches),'HOST_CONTINUITY_PIN_MISMATCH_STOP')
    known=work/'known_hosts';known.write_text('\n'.join(matches)+'\n')
    options=['-i',str(key),'-o','IdentitiesOnly=yes','-o','BatchMode=yes','-o','StrictHostKeyChecking=yes',
        '-o','UserKnownHostsFile='+str(known),'-o','GlobalKnownHostsFile=/dev/null','-o','ConnectTimeout=10',
        '-o','ServerAliveInterval=15','-o','ServerAliveCountMax=2']
    need((dt.datetime.now(dt.timezone.utc)-dt.datetime.fromisoformat(admission['observed_at'])).total_seconds()<=120,'ADMISSION_EXPIRED')
    with (work/'stdout').open('wb') as output,(work/'stderr').open('wb') as error:
        process=subprocess.run(['ssh',*options,user+'@'+host,REMOTE],input=(ROOT/'program.php').read_bytes(),stdout=output,stderr=error,timeout=45)
    path=work/'stdout';need(path.stat().st_size<=4_194_304,'OUTPUT_BOUND')
    try:report=json.loads(path.read_bytes())
    except Exception:raise Stop('NATIVE_OUTPUT_UNAVAILABLE') from None
    validated=validate_report(report)
    need(process.returncode==0 or validated.get('ok') is False,'NATIVE_EXIT_MISMATCH')
    return validated

def encrypted_read():
    with tempfile.TemporaryDirectory(prefix='hotel-entry-price-',dir=os.environ['RUNNER_TEMP']) as temp:
        try: report=execute(Path(temp))
        except Stop as e: report={'ok':False,'transport_error':str(e)}
        except Exception: report={'ok':False,'transport_error':'TRANSPORT_FAILED'}
        data=json.dumps(report,ensure_ascii=False,allow_nan=False,separators=(',',':')).encode()
        public=serialization.load_pem_public_key(base64.b64decode(os.environ['REPORT_PUBLIC_KEY_B64'],validate=True))
        key,nonce=AESGCM.generate_key(bit_length=256),os.urandom(12)
        aad=('anytour-entry-price-20261112-20261118-'+os.environ['GITHUB_SHA']).encode()
        wrapped=public.encrypt(key,padding.OAEP(mgf=padding.MGF1(hashes.SHA256()),algorithm=hashes.SHA256(),label=None))
        b64=lambda v:base64.b64encode(v).decode()
        envelope={'schema':1,'workflow_sha':os.environ['GITHUB_SHA'],'aad':b64(aad),'nonce':b64(nonce),
            'wrapped_key':b64(wrapped),'ciphertext':b64(AESGCM(key).encrypt(nonce,data,aad))}
        output=Path('encrypted-entry-price');output.mkdir(exist_ok=True)
        (output/'entry-price.enc.json').write_text(json.dumps(envelope))
        print('READ_RESULT='+('COLLECTED' if report.get('ok') is True else 'READ_FAILED'))
        print('ENCRYPTED_REPORT_READY')

def main():
    os.umask(0o077)
    need(len(sys.argv)==2 and sys.argv[1] in ('admit','read'),'MODE_INVALID')
    if sys.argv[1]=='admit':admit()
    else:encrypted_read()

if __name__=='__main__':
    try:main()
    except Exception:print('ANALYTICS_SAFE_STOP',file=sys.stderr);sys.exit(1)
