"""One admitted SELECT-only PHP stdin read; retain only an authenticated ciphertext."""
import base64
import datetime as dt
import hashlib
import json
import math
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
BRANCH='audit/hotel-entry-forecast-20261120'
BASE='b4b65daaa472c15426a244b87ede3e6c0394626b'
ROOT=Path('audit/hotel-entry-forecast-20261120')
PIN='SHA256:/5mPWmGBQ6UPnNQDEHLOCy7dCpxdz+FxQ4zVOjqE0F0'
WINDOW={'from_utc':'2026-11-11 21:00:00','until_utc_exclusive':'2026-11-18 21:00:00','followup_until_utc_exclusive':'2026-11-19 21:00:00'}
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

def admission_path(): return Path(os.environ['RUNNER_TEMP'])/'hotel-entry-forecast-admission.json'

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
    forbidden={'conversation_id','customer_key','customer_id','external_user_id','external_chat_id','entry_id','snapshot_json','profile_json','post_text','text','dialogues','raw','token','yclid','client_id','profile_id','journal_id','request_anchor','current_request_anchor'}
    if isinstance(value,dict):
        need(not (set(value)&forbidden),'PRIVATE_FIELD_IN_OUTPUT')
        for v in value.values(): no_private_fields(v)
    elif isinstance(value,list):
        for v in value: no_private_fields(v)

STATES=('pending','ready','uploading','accepted','processed','unknown','failed','expired','unmatched')
PRICE_ACTIONS=('missing','unchanged','rebased','reduced','voided','unverified','review_required')
AGE_BANDS=('first_hour','first_three_hours','first_day','later_update','unknown')
MONETARY_VERSIONS=('early_deal_commission_7pct_v1','early_deal_commission_7pct_v2','early_deal_commission_7pct_v3')
LIMITS={'max_entry_rows':10000,'max_profile_rows':10000,'max_profile_packet_bytes':32768,
    'max_current_access_scopes':10000,'max_current_access_profile_bytes':131072,'max_current_access_scalar_bytes':32,
    'max_journal_rows':1000,'max_journal_packet_bytes':4096,'max_ledger_rows':10000,
    'max_selected_bytes':4194304,'max_output_bytes':4194304,'max_execution_seconds':20,'sql_timeout_ms':3000}
COVERAGE_COUNTS=('entry_rows_read','global_entry_rows_scanned','global_window_entries',
    'unavailable_publication_entries','invalid_binding_entries','owner_scope_excluded_entries','admitted_entries',
    'current_access_scopes_checked','current_access_allowed_scopes','current_access_blocked_scopes',
    'current_access_unavailable_scopes','current_access_blocked_entries','current_access_unavailable_entries',
    'admitted_scopes','scopes_without_profile_in_followup','profile_rows_read','profile_packets_read',
    'invalid_profile_packets','unavailable_profile_packets','profiles_unknown_clock','profiles_outside_entry_24h',
    'profiles_not_exact_entry','profiles_missing_price_context','exact_linked_profiles','exact_linked_entries',
    'selected_request_anchors','ambiguous_request_anchors','duplicate_profiles_same_anchor','ledger_rows_read','ledger_invalid_rows')
ORIGINS=('unknown','customer_budget','delivered_offer','native_hotel_inquiry','channel_post_range','unavailable')
SEMANTICS={
    'entry_basis':'registry_accepted_entries_event_join_not_rechecked',
    'current_access':'latest_exact_subject_profile_access_status_allowed_at_read_time',
    'source_clock_basis':'saved_database_recording_evidence_not_native_action_time',
    'profile_candidate_basis':'same_subject_and_scope_time_bounds_not_entry_attribution',
    'profile_origin_counts_basis':'declared_bounded_metadata_not_validated_money',
    'selection':'earliest_valid_exact_price_linked_frozen_profile_per_request_anchor_within_entry_24h',
    'request_identity':'observed_anchor_not_verified_person_or_trip',
    'exact_link':'frozen_channel_post_range_reference_and_matching_entry_publication_version_context',
    'other_price_origins_attributed_to_post':False,
    'ledger_scope':'all_valid_exact_linked_profiles_within_entry_24h_excluding_ambiguous_anchors',
    'ledger_lifecycle_basis':'recorded_observation_timestamps_and_current_states_as_of_read',
    'ledger_audit_sums_are_per_record_diagnostics':True,
    'forecast_value_and_ledger_delta_additive':False,
    'price_verified':False,'calibration_status':'expert_estimate_not_calibrated',
    'entries_are_clicks':False,'dialogues_are_unique_people':False,
    'max2_visit_linkage_established':False,'entry_capture_completeness':'not_scanned_without_exact_frozen_reference',
    'customer_texts_read':False,'full_profile_json_read':False,'recomputation_performed':False}

def count(value, bound=100000):
    need(type(value) is int and 0<=value<=bound,'COUNT_INVALID')
    return value

def money(value):
    need(type(value) in (int,float) and math.isfinite(value) and 0<=value<=10**12
         and abs(value*100-round(value*100))<0.001,'MONEY_INVALID')
    return value

def fields(value, names, code):
    need(isinstance(value,dict) and set(value)==set(names),code)

def same_money(a,b):
    need(abs(a-b)<0.001,'MONEY_RECONCILIATION_FAILED')

def validate_ledger(ledger):
    fields(ledger,('rows','value_rub_sum','by_state','api_accepted_rows','api_accepted_value_rub_sum',
        'processed_rows','processed_value_rub_sum','price_audit'),'LEDGER_FIELDS_INVALID')
    rows=count(ledger['rows']);total=money(ledger['value_rub_sum'])
    fields(ledger['by_state'],STATES,'LEDGER_STATES_INVALID')
    for state,v in ledger['by_state'].items():
        fields(v,('rows','value_rub_sum'),'LEDGER_STATE_FIELDS_INVALID')
        count(v['rows'],rows);money(v['value_rub_sum'])
    need(sum(v['rows'] for v in ledger['by_state'].values())==rows,'LEDGER_COUNT_RECONCILIATION_FAILED')
    same_money(sum(v['value_rub_sum'] for v in ledger['by_state'].values()),total)
    for prefix in ('api_accepted','processed'):
        count(ledger[prefix+'_rows'],rows);money(ledger[prefix+'_value_rub_sum'])
        need(ledger[prefix+'_value_rub_sum']<=total+0.001,'LEDGER_SUBSET_INVALID')
    fields(ledger['price_audit'],('counts','original_rub_sum','applied_rub_sum','prior_reserved_rub_sum'),
        'PRICE_AUDIT_FIELDS_INVALID')
    audit=ledger['price_audit'];fields(audit['counts'],PRICE_ACTIONS,'PRICE_AUDIT_ACTIONS_INVALID')
    for v in audit['counts'].values():count(v,rows)
    need(sum(audit['counts'].values())==rows,'PRICE_AUDIT_COUNT_RECONCILIATION_FAILED')
    for key in ('original_rub_sum','applied_rub_sum','prior_reserved_rub_sum'):money(audit[key])

def validate_forecast(row):
    fields(row,('monetary_version','semantic_version','model_sha256','anchors','estimated_count','probability_sum',
        'probability_mean','frozen_value_rub_sum','base_rub_sum','deal_point_min_rub','deal_point_max_rub',
        'age_seconds_min','age_seconds_max','age_bands','first_frozen_at','last_frozen_at'),'FORECAST_FIELDS_INVALID')
    need(row['monetary_version'] in MONETARY_VERSIONS and row['semantic_version']=='semantic_purchase_heuristic_v1',
        'MODEL_VERSION_INVALID')
    need(isinstance(row['model_sha256'],str) and re.fullmatch('[a-f0-9]{64}',row['model_sha256']) is not None,
        'MODEL_DIGEST_INVALID')
    anchors=count(row['anchors']);need(anchors>0,'EMPTY_FORECAST_GROUP')
    estimated=count(row['estimated_count'],anchors)
    count(row['base_rub_sum'],anchors*100000)
    if estimated:
        for k,bound in (('probability_sum',estimated),('probability_mean',1)):
            v=row[k];need(type(v) in (int,float) and math.isfinite(v) and 0<v<=bound,'PROBABILITY_INVALID')
        need(abs(row['probability_sum']/estimated-row['probability_mean'])<=0.000001,'PROBABILITY_RECONCILIATION_FAILED')
        count(row['frozen_value_rub_sum'],estimated*100000)
    else:
        need(all(row[k] is None for k in ('probability_sum','probability_mean','frozen_value_rub_sum')),
             'UNKNOWN_PROBABILITY_NOT_NULL')
    for k in ('deal_point_min_rub','deal_point_max_rub'):
        need(row[k] is None or type(row[k]) is int and 10000<=row[k]<=10000000,'DEAL_POINT_INVALID')
    need((row['deal_point_min_rub'] is None)==(row['deal_point_max_rub'] is None),'DEAL_POINT_PAIR_INVALID')
    if row['deal_point_min_rub'] is not None:
        need(row['deal_point_min_rub']<=row['deal_point_max_rub'],'DEAL_POINT_ORDER_INVALID')
    for k in ('age_seconds_min','age_seconds_max'):count(row[k],86400)
    need(row['age_seconds_min']<=row['age_seconds_max'],'AGE_ORDER_INVALID')
    fields(row['age_bands'],AGE_BANDS,'AGE_BANDS_INVALID')
    for v in row['age_bands'].values():count(v,anchors)
    need(sum(row['age_bands'].values())==anchors,'AGE_COUNT_RECONCILIATION_FAILED')
    for k in ('first_frozen_at','last_frozen_at'):
        need(isinstance(row[k],str) and re.fullmatch(r'\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z',row[k]) is not None,
             'FROZEN_CLOCK_INVALID')
        moment=dt.datetime.fromisoformat(row[k].replace('Z','+00:00'))
        lower=dt.datetime.strptime(WINDOW['from_utc'],'%Y-%m-%d %H:%M:%S').replace(tzinfo=dt.timezone.utc)
        upper=dt.datetime.strptime(WINDOW['followup_until_utc_exclusive'],'%Y-%m-%d %H:%M:%S').replace(tzinfo=dt.timezone.utc)
        need(lower<=moment<upper,'FROZEN_CLOCK_SCOPE_INVALID')
    need(row['first_frozen_at']<=row['last_frozen_at'],'FROZEN_CLOCK_ORDER_INVALID')

def validate_report(report):
    need(isinstance(report,dict),'REPORT_SHAPE_INVALID')
    if report.get('ok') is False:
        need(set(report)=={'ok','error'} and isinstance(report['error'],str)
             and re.fullmatch('[a-z_]{1,80}',report['error']) is not None,'UNSAFE_ERROR_OUTPUT')
        return report
    fields(report,('ok','installed_sha','db_writes','provider_calls','message_sends','aggregate'),'REPORT_FIELDS_INVALID')
    need(report['ok'] is True and report['installed_sha']==BASE,'RUNTIME_PROVENANCE_MISMATCH')
    for k in ('db_writes','provider_calls','message_sends'):
        need(type(report[k]) is int and report[k]==0,'SIDE_EFFECT_FLAGS_INVALID')
    a=report['aggregate']
    fields(a,('schema','window','checked_at_utc','coverage','posts','ledger','semantics','limits'),'AGGREGATE_FIELDS_INVALID')
    need(a['schema']=='post_entry_forecast_aggregate_v1','AGGREGATE_SCHEMA_INVALID')
    need(a['window']=={**WINDOW,'business_timezone':'Europe/Moscow','days':7,'followup_hours':24},'WINDOW_MISMATCH')
    need(a['limits']==LIMITS,'LIMITS_MISMATCH')
    need(a['semantics']==SEMANTICS,'SEMANTICS_MISMATCH')
    need(isinstance(a['checked_at_utc'],str) and re.fullmatch(r'\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z',a['checked_at_utc']) is not None,
        'READ_CLOCK_INVALID')
    c=a['coverage'];fields(c,(*COVERAGE_COUNTS,'complete_within_bound','profile_origin_counts'),'COVERAGE_FIELDS_INVALID')
    need(c['complete_within_bound'] is True,'INCOMPLETE_COHORT')
    for k in COVERAGE_COUNTS:count(c[k],10000)
    fields(c['profile_origin_counts'],ORIGINS,'PROFILE_ORIGIN_FIELDS_INVALID')
    for v in c['profile_origin_counts'].values():count(v,10000)
    need(sum(c['profile_origin_counts'].values())==c['profile_rows_read'],'PROFILE_ORIGIN_RECONCILIATION_FAILED')
    need(c['current_access_scopes_checked']==sum(c['current_access_'+k+'_scopes'] for k in ('allowed','blocked','unavailable'))
         and c['current_access_allowed_scopes']==c['admitted_scopes']
         and c['current_access_blocked_scopes']<=c['current_access_blocked_entries']
         and c['current_access_unavailable_scopes']<=c['current_access_unavailable_entries'],
         'CURRENT_ACCESS_RECONCILIATION_FAILED')
    need(c['entry_rows_read']<=c['global_window_entries']<=c['global_entry_rows_scanned']
         and c['admitted_entries']<=c['entry_rows_read']
         and c['exact_linked_entries']<=c['admitted_entries']
         and c['exact_linked_profiles']<=c['profile_packets_read']<=c['profile_rows_read']
         and c['selected_request_anchors']<=c['exact_linked_profiles']
         and c['scopes_without_profile_in_followup']<=c['admitted_scopes'],'COVERAGE_RELATIONS_INVALID')
    need(isinstance(a['posts'],list) and len(a['posts'])<=1000,'POST_GROUP_BOUND')
    seen=set()
    for p in a['posts']:
        fields(p,('publication_id','version_id','platform','channel_id','entries','admitted_entries','exact_linked_entries',
            'exact_linked_profiles','selected_anchors','forecasts','ledger'),'POST_FIELDS_INVALID')
        need(all(type(p[k]) is int and 0<p[k]<10**19 for k in ('publication_id','version_id')),'PUBLICATION_ID_INVALID')
        need(p['platform'] in ('max','telegram') and isinstance(p['channel_id'],str)
             and re.fullmatch('-?[0-9]{1,64}',p['channel_id']) is not None,'PUBLIC_CHANNEL_INVALID')
        key=(p['platform'],p['channel_id'],p['publication_id'],p['version_id'])
        need(key not in seen,'DUPLICATE_POST_GROUP');seen.add(key)
        for k in ('entries','admitted_entries','exact_linked_entries','exact_linked_profiles','selected_anchors'):count(p[k],10000)
        need(0<p['entries'] and p['exact_linked_entries']<=p['admitted_entries']<=p['entries']
             and p['selected_anchors']<=p['exact_linked_profiles'],'POST_COVERAGE_INVALID')
        need(isinstance(p['forecasts'],list) and len(p['forecasts'])<=1000,'FORECAST_GROUP_BOUND')
        forecast_keys=set()
        for f in p['forecasts']:
            validate_forecast(f)
            fk=(f['monetary_version'],f['semantic_version'],f['model_sha256'])
            need(fk not in forecast_keys,'DUPLICATE_FORECAST_GROUP');forecast_keys.add(fk)
        need(sum(f['anchors'] for f in p['forecasts'])==p['selected_anchors'],'ANCHOR_RECONCILIATION_FAILED')
        validate_ledger(p['ledger'])
    validate_ledger(a['ledger'])
    for key,coverage_key in (('admitted_entries','admitted_entries'),('exact_linked_entries','exact_linked_entries'),
        ('exact_linked_profiles','exact_linked_profiles'),('selected_anchors','selected_request_anchors')):
        need(sum(p[key] for p in a['posts'])==c[coverage_key],'POST_TOTAL_RECONCILIATION_FAILED')
    need(sum(p['ledger']['rows'] for p in a['posts'])==a['ledger']['rows'],'POST_LEDGER_RECONCILIATION_FAILED')
    same_money(sum(p['ledger']['value_rub_sum'] for p in a['posts']),a['ledger']['value_rub_sum'])
    need(sum(p['entries'] for p in a['posts'])+c['unavailable_publication_entries']+c['invalid_binding_entries']==c['entry_rows_read'],
        'ENTRY_TOTAL_RECONCILIATION_FAILED')
    need(c['admitted_entries']+c['owner_scope_excluded_entries']+c['unavailable_publication_entries']+c['invalid_binding_entries']
         +c['current_access_blocked_entries']+c['current_access_unavailable_entries']==c['entry_rows_read'],
        'ADMISSION_TOTAL_RECONCILIATION_FAILED')
    need(a['ledger']['rows']+c['ledger_invalid_rows']<=c['ledger_rows_read'],'LEDGER_READ_RECONCILIATION_FAILED')
    for state in STATES:
        need(sum(p['ledger']['by_state'][state]['rows'] for p in a['posts'])==a['ledger']['by_state'][state]['rows'],
            'LEDGER_STATE_RECONCILIATION_FAILED')
        same_money(sum(p['ledger']['by_state'][state]['value_rub_sum'] for p in a['posts']),
            a['ledger']['by_state'][state]['value_rub_sum'])
    for prefix in ('api_accepted','processed'):
        need(sum(p['ledger'][prefix+'_rows'] for p in a['posts'])==a['ledger'][prefix+'_rows'],
            'LEDGER_SUBSET_RECONCILIATION_FAILED')
        same_money(sum(p['ledger'][prefix+'_value_rub_sum'] for p in a['posts']),
            a['ledger'][prefix+'_value_rub_sum'])
    for action in PRICE_ACTIONS:
        need(sum(p['ledger']['price_audit']['counts'][action] for p in a['posts'])==a['ledger']['price_audit']['counts'][action],
            'PRICE_AUDIT_COUNT_RECONCILIATION_FAILED')
    for key in ('original_rub_sum','applied_rub_sum','prior_reserved_rub_sum'):
        same_money(sum(p['ledger']['price_audit'][key] for p in a['posts']),a['ledger']['price_audit'][key])
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
    with tempfile.TemporaryDirectory(prefix='hotel-entry-forecast-',dir=os.environ['RUNNER_TEMP']) as temp:
        try: report=execute(Path(temp))
        except Stop as e: report={'ok':False,'transport_error':str(e)}
        except Exception: report={'ok':False,'transport_error':'TRANSPORT_FAILED'}
        data=json.dumps(report,ensure_ascii=False,allow_nan=False,separators=(',',':')).encode()
        public=serialization.load_pem_public_key(base64.b64decode(os.environ['REPORT_PUBLIC_KEY_B64'],validate=True))
        key,nonce=AESGCM.generate_key(bit_length=256),os.urandom(12)
        aad=('anytour-entry-forecast-20261112-20261118-'+os.environ['GITHUB_SHA']).encode()
        wrapped=public.encrypt(key,padding.OAEP(mgf=padding.MGF1(hashes.SHA256()),algorithm=hashes.SHA256(),label=None))
        b64=lambda v:base64.b64encode(v).decode()
        envelope={'schema':1,'workflow_sha':os.environ['GITHUB_SHA'],'aad':b64(aad),'nonce':b64(nonce),
            'wrapped_key':b64(wrapped),'ciphertext':b64(AESGCM(key).encrypt(nonce,data,aad))}
        output=Path('encrypted-entry-forecast');output.mkdir(exist_ok=True)
        (output/'entry-forecast.enc.json').write_text(json.dumps(envelope))
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
