#!/usr/bin/env python3
"""Private, API-key-free queue and authenticated result transport for bulk evals.

SQLite stores per-input status; a submitted/unknown job is never automatically
replayed. This program does not submit HTTP requests or contact customers.
"""
import base64
import ctypes
import ctypes.util
import gzip
import hashlib
import json
import os
import re
from pathlib import Path
import sqlite3
from datetime import datetime, timezone

MAX_PRIMARY_CASES = 14

def encoded(value):
    return json.dumps(value,ensure_ascii=False,sort_keys=True,separators=(',',':')).encode()

def digest(data):
    return hashlib.sha256(data).hexdigest()

def atomic_json(path,value):
    path=Path(path);path.parent.mkdir(mode=0o700,parents=True,exist_ok=True)
    temp=path.with_name(path.name+'.tmp');fd=os.open(temp,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
    try:
        with os.fdopen(fd,'wb') as stream:
            stream.write(encoded(value));stream.flush();os.fsync(stream.fileno())
        os.replace(temp,path)
    finally:
        if temp.exists():temp.unlink()

class Queue:
    def __init__(self,path):
        p=Path(path);p.parent.mkdir(mode=0o700,parents=True,exist_ok=True)
        self.db=sqlite3.connect(p);os.chmod(p,0o600)
        self.db.execute('CREATE TABLE IF NOT EXISTS jobs (fingerprint TEXT PRIMARY KEY, case_id TEXT, stage TEXT, state TEXT NOT NULL, task_token TEXT, input_json TEXT NOT NULL, result_json TEXT)')
        columns={row[1] for row in self.db.execute('PRAGMA table_info(jobs)')}
        for column in ['input_hash','contract_hash']:
            if column not in columns:self.db.execute('ALTER TABLE jobs ADD COLUMN '+column+' TEXT')
        for key,raw in self.db.execute('SELECT fingerprint,input_json FROM jobs WHERE input_hash IS NULL').fetchall():
            value=json.loads(raw);value.pop('case_id',None)
            self.db.execute('UPDATE jobs SET input_hash=? WHERE fingerprint=?',(digest(encoded(value)),key))
        self.db.execute('CREATE INDEX IF NOT EXISTS jobs_input_index ON jobs(stage,input_hash)')
        self.db.execute('CREATE TABLE IF NOT EXISTS receipts (task_token TEXT PRIMARY KEY, receipt_json TEXT NOT NULL)');self.db.commit()

    def enqueue(self,case,stage,contract):
        if stage!='generate':raise ValueError('grading_disabled')
        value={k:v for k,v in case.items() if k!='case_id'}
        input_hash=digest(encoded(value));contract_hash=digest(encoded(contract))
        key=digest(encoded({'input':value,'stage':stage,'contract':contract}))
        with self.db:
            for old_key,old_input,old_contract in self.db.execute('SELECT fingerprint,input_json,contract_hash FROM jobs WHERE stage=? AND input_hash=?',(stage,input_hash)).fetchall():
                old_case=json.loads(old_input)
                legacy=digest(encoded({'case':old_case,'stage':stage,'contract':contract}))
                if old_contract==contract_hash or old_key==legacy:
                    self.db.execute('UPDATE jobs SET contract_hash=? WHERE fingerprint=?',(contract_hash,old_key))
                    return old_key
            if self.db.execute('SELECT COUNT(*) FROM jobs WHERE task_token IS NOT NULL').fetchone()[0]:
                raise ValueError('study_membership_frozen')
            self.db.execute('INSERT OR IGNORE INTO jobs(fingerprint,case_id,stage,state,task_token,input_json,result_json,input_hash,contract_hash) VALUES(?,?,?,\'pending\',NULL,?,NULL,?,?)',(key,case['case_id'],stage,encoded(case).decode(),input_hash,contract_hash))
        return key

    def reserve(self,keys,token):
        if len(keys)!=MAX_PRIMARY_CASES or len(keys)!=len(set(keys)):raise ValueError('invalid_queue_keys')
        if not isinstance(token,str) or re.fullmatch(r'[0-9a-f]{48}',token) is None:raise ValueError('invalid_task_token')
        # Lock before reading spent attempts; two controllers cannot race the cap.
        self.db.execute('BEGIN IMMEDIATE')
        try:
            rows=[self.db.execute('SELECT state,input_json FROM jobs WHERE fingerprint=?',(key,)).fetchone() for key in keys]
            if any(not row or row[0]!='pending' for row in rows):raise ValueError('queue_replay_forbidden')
            stages={self.db.execute('SELECT stage FROM jobs WHERE fingerprint=?',(key,)).fetchone()[0] for key in keys}
            if stages!={'generate'}:raise ValueError('grading_disabled')
            stage=next(iter(stages))
            spent=self.db.execute('SELECT COUNT(*) FROM jobs WHERE stage=? AND task_token IS NOT NULL',(stage,)).fetchone()[0]
            if spent+len(keys)>MAX_PRIMARY_CASES:raise ValueError('study_attempt_budget_exceeded')
            for key in keys:self.db.execute('UPDATE jobs SET state=\'submitted\',task_token=? WHERE fingerprint=?',(token,key))
            self.db.commit()
        except BaseException:
            self.db.rollback()
            raise
        return [json.loads(row[1]) for row in rows]

    def accept(self,token,records,receipt):
        indexed={row['case_id']:row for row in records}
        if len(indexed)!=len(records):raise ValueError('duplicate_result')
        jobs=self.db.execute('SELECT fingerprint,case_id,state FROM jobs WHERE task_token=?',(token,)).fetchall()
        if not jobs or set(indexed)-{row[1] for row in jobs}:raise ValueError('foreign_result')
        if any(row[2]!='submitted' for row in jobs):raise ValueError('receipt_already_applied')
        with self.db:
            for key,cid,_ in jobs:
                record=indexed.get(cid)
                if record is None:state='unknown'
                elif record.get('provider_calls') is None or record.get('result_origin')=='unknown':state='unknown'
                elif record.get('result',{}).get('ok') is True:state='complete'
                else:state='error'
                self.db.execute('UPDATE jobs SET state=?,result_json=? WHERE fingerprint=?',(state,encoded(record).decode() if record else None,key))
            self.db.execute('INSERT INTO receipts VALUES(?,?)',(token,encoded(receipt).decode()))

    def counts(self):
        return dict(self.db.execute('SELECT state,COUNT(*) FROM jobs GROUP BY state'))

def sealed_box(plain,public_key):
    """libsodium sealed boxes; no private key leaves the prepared remote lease."""
    lib=ctypes.CDLL(ctypes.util.find_library('sodium'))
    lib.sodium_init.restype=ctypes.c_int
    if lib.sodium_init()<0 or len(public_key)!=32:raise ValueError('sodium_unavailable')
    lib.crypto_box_seal.argtypes=[ctypes.c_void_p,ctypes.c_void_p,ctypes.c_ulonglong,ctypes.c_void_p]
    lib.crypto_box_seal.restype=ctypes.c_int
    out=ctypes.create_string_buffer(len(plain)+48)
    if lib.crypto_box_seal(out,plain,len(plain),public_key)!=0:raise ValueError('seal_failed')
    return out.raw

def source_manifest():
    value=json.loads(Path(__file__).with_name('l01_source.json').read_text())
    if value.get('schema')!='manager_ai_l01_source_v1' or value.get('case_count')!=14 or value.get('parallelism')!=1 or value.get('profiles')!=[{'model':'gpt-6-luna','reasoning_effort':'low','service_tier':'default'},{'model':'gpt-6.1-sol','reasoning_effort':'low','service_tier':'default'}] or value.get('output_token_reservation')!=30800:
        raise ValueError('source_manifest_invalid')
    if value.get('production_sha')!='7af94dddba1808d90f78e0bc0abb84c15b0200a5' or value.get('candidate_sha')!='f687eebceb105628dc06005d01efbb54955eef7b':raise ValueError('source_manifest_invalid')
    if value.get('budget_cap_microusd')!=1000000 or not 0<value.get('reservation_microusd',0)<=1000000 or sum(x['reservation_microusd'] for x in value.get('attempt_bindings',[]))!=value['reservation_microusd'] or len(value['attempt_bindings'])!=14:raise ValueError('money_bound_invalid')
    return value

def proposal_hash(cases):
    return digest(encoded({'cases':cases,'grader_calls':0,'provider_calls':0,
        'schema':'l01_frozen_development_cases_v1','status':'unsubmitted'}))

def validate_case(case,index):
    if not isinstance(case,dict) or set(case)!={'case_id','context','provenance','requested_model'} or case['case_id']!='L01-'+str(index).zfill(3) or not 1<=index<=14:raise ValueError('invalid_case')
    if case.get('requested_model') not in ['gpt-6-luna','gpt-6.1-sol']:raise ValueError('invalid_model')
    p=case['provenance'];c=case['context']
    allowed={'conversation_id','as_of_message_id','messages','history_incomplete','media_unread','selections_link_unread','verified_company_facts'}
    if not isinstance(c,dict) or set(c)-allowed or c.get('verified_company_facts')!=[] or not isinstance(p,dict):raise ValueError('invalid_context')
    messages=c.get('messages')
    if not isinstance(messages,list) or not 1<=len(messages)<=120 or type(c.get('history_incomplete')) is not bool:raise ValueError('invalid_context')
    ids=[]
    for m in messages:
        if not isinstance(m,dict) or set(m)-{'id','sender_type','text','event_type','created_at','media_unread','selections_link_unread','text_truncated'} or type(m.get('id')) is not int or m['id']<=0 or not isinstance(m.get('text'),str) or m.get('sender_type') not in ['customer','manager','ai','system']:raise ValueError('invalid_message')
        ids.append(m['id'])
        stamp=datetime.strptime(m['created_at'],'%Y-%m-%dT%H:%M:%SZ').replace(tzinfo=timezone.utc)
        if stamp.strftime('%Y-%m-%dT%H:%M:%SZ')!=m['created_at']:raise ValueError('invalid_timestamp')
    if ids!=sorted(set(ids)) or type(c.get('as_of_message_id')) is not int or c['as_of_message_id']!=ids[-1]:raise ValueError('invalid_ids')
    if p.get('kind')=='prepared_anex_b42_development':
        if set(p)!={'kind','exact_context_reused','future_messages_added','source_input_id','source_input_sha256','context_sha256'} or p['exact_context_reused'] is not True or type(p['future_messages_added']) is not int or p['future_messages_added']!=0 or re.fullmatch(r'PX42-0[1-7]',p.get('source_input_id','')) is None or re.fullmatch(r'[0-9a-f]{64}',p.get('source_input_sha256','')) is None or digest(encoded(c))!=p['context_sha256']:raise ValueError('invalid_provenance')
    elif p.get('kind')=='authored_synthetic_transport_fixture':
        if set(p)!={'kind','control'} or not isinstance(p['control'],str) or re.fullmatch(r'[a-z][a-z_]{0,79}',p['control']) is None or type(c.get('conversation_id')) is not int or c['conversation_id']!=2000000+index:raise ValueError('invalid_provenance')
    else:raise ValueError('invalid_provenance')

def input_envelope(task,prepared,cases):
    manifest=source_manifest()
    if task.get('schema')!='manager_ai_l01_task_v1' or task.get('stage')!='generate' or len(cases)!=MAX_PRIMARY_CASES or prepared.get('maximum_case_count')!=MAX_PRIMARY_CASES or task.get('case_count')!=14 or task.get('parallelism')!=1:raise ValueError('study_contract_mismatch')
    for index,case in enumerate(cases,1):validate_case(case,index)
    if proposal_hash(cases)!=manifest['proposal_inputs_sha256']:raise ValueError('proposal_membership_mismatch')
    if task.get('production_sha')!=manifest['production_sha'] or task.get('candidate_sha')!=manifest['candidate_sha']:raise ValueError('source_binding_mismatch')
    for key in ['task_token','production_sha','candidate_sha','source_bundle_sha256','case_count','parallelism','stage']:
        if task[key]!=prepared[key]:raise ValueError('prepared_binding_mismatch')
    recipient_hash=digest(task['output_recipient'].encode())
    if prepared['output_recipient_sha256']!=recipient_hash:raise ValueError('prepared_binding_mismatch')
    now=datetime.now(timezone.utc)
    expires=datetime.fromisoformat(prepared['expires_at'].replace('Z','+00:00'))
    if not 0<(expires-now).total_seconds()<=3600:raise ValueError('lease_expired')
    if any(datetime.strptime(m['created_at'],'%Y-%m-%dT%H:%M:%SZ').replace(tzinfo=timezone.utc)>now for c in cases for m in c['context']['messages']):raise ValueError('future_message')
    value={'schema':'manager_ai_l01_input_v1','repository':'pyatkoff/max-search-bot','task_token':task['task_token'],
        'production_sha':task['production_sha'],'candidate_sha':task['candidate_sha'],'source_bundle_sha256':task['source_bundle_sha256'],
        'output_recipient_sha256':recipient_hash,'lease_id':prepared['lease_id'],'stage':task['stage'],
        'generated_at':now.strftime('%Y-%m-%dT%H:%M:%SZ'),'expires_at':prepared['expires_at'],'cases':cases,
        'proposal_inputs_sha256':manifest['proposal_inputs_sha256'],'criteria_sha256':manifest['criteria_sha256'],
        'selection_freeze_sha256':manifest['selection_freeze_sha256']}
    plain=encoded(value)
    if len(plain)>16777216:raise ValueError('input_too_large')
    cipher=sealed_box(gzip.compress(plain,mtime=0),base64.b64decode(prepared['public_key'],validate=True))
    if len(cipher)>4194304:raise ValueError('ciphertext_too_large')
    return value,cipher

def decrypt_output(envelope,identity,expected):
    from cryptography.hazmat.primitives import serialization, hashes
    from cryptography.hazmat.primitives.asymmetric import padding
    from cryptography.hazmat.primitives.ciphers.aead import AESGCM
    if envelope.get('schema')!='manager_ai_l01_encrypted_v1':raise ValueError('envelope_schema')
    aad=base64.b64decode(envelope['aad'],validate=True);binding=json.loads(aad)
    for key,value in expected.items():
        if binding.get(key)!=value:raise ValueError('output_binding_mismatch')
    key=serialization.load_pem_private_key(Path(identity).read_bytes(),password=None)
    secret=key.decrypt(base64.b64decode(envelope['wrapped_key'],validate=True),padding.OAEP(mgf=padding.MGF1(hashes.SHA256()),algorithm=hashes.SHA256(),label=None))
    ciphertext=base64.b64decode(envelope['ciphertext'],validate=True)+base64.b64decode(envelope['tag'],validate=True)
    plain=AESGCM(secret).decrypt(base64.b64decode(envelope['iv'],validate=True),ciphertext,aad)
    return json.loads(plain)

def self_test():
    import copy
    import tempfile
    with tempfile.TemporaryDirectory(prefix='manager-l01-queue-') as folder:
        queue=Queue(Path(folder)/'queue.sqlite')
        contract={'model':'gpt-6.1-sol','effort':'low','tier':'default','series':'manager-l01-synthetic-contract'}
        cases=[{'case_id':'S'+str(i),'context':{'synthetic':i},'provenance':{'kind':'authored_synthetic'}} for i in range(14)]
        keys=[queue.enqueue(case,'generate',contract) for case in cases]
        assert queue.enqueue(dict(cases[0],case_id='renamed'),'generate',contract)==keys[0]
        for count in [1,13,15]:
            bad=keys[:count] if count<=14 else keys+['extra']
            try:queue.reserve(bad,'a'*48)
            except ValueError:pass
            else:raise AssertionError('exact14_required')
        try:queue.enqueue(cases[0],'grade',contract)
        except ValueError:pass
        else:raise AssertionError('grading_enabled')
        assert queue.reserve(keys,'a'*48)==cases
        try:queue.reserve(keys,'b'*48)
        except ValueError:pass
        else:raise AssertionError('submitted_replayed')
        try:queue.enqueue({'case_id':'EXTRA','context':{'synthetic':'extra'},'provenance':{'kind':'authored_synthetic'}},'generate',contract)
        except ValueError as error:assert str(error)=='study_membership_frozen'
        else:raise AssertionError('membership_expanded')
        queue.accept('a'*48,[{'case_id':'S0','provider_calls':0,'result_origin':'policy','result':{'ok':True}},
            {'case_id':'S1','provider_calls':1,'result_origin':'model','result':{'ok':False}}],{'synthetic':True})
        assert queue.counts()=={'complete':1,'error':1,'unknown':12}
        try:queue.reserve(keys,'b'*48)
        except ValueError:pass
        else:raise AssertionError('unknown_or_error_replayed')
        assert queue.db.execute('SELECT COUNT(*) FROM jobs WHERE task_token IS NOT NULL').fetchone()[0]==14
        queue.db.close()
    c={'requested_model':'gpt-6-luna','case_id':'L01-003','context':{'conversation_id':2000003,'as_of_message_id':7,
        'messages':[{'id':7,'sender_type':'customer','text':'Synthetic \u2028 \U0001f30d','event_type':'message','created_at':'2020-01-01T00:00:00Z','media_unread':False,'selections_link_unread':False}],
        'history_incomplete':False,'verified_company_facts':[]},'provenance':{'kind':'authored_synthetic_transport_fixture','control':'neutral_test'}}
    validate_case(c,3);before=encoded(c);proposal_hash([c]);assert before==encoded(c)
    for mutate in [lambda v:v.update(case_id='L01-004'),lambda v:v['provenance'].update(kind='k02_development_prefix'),
        lambda v:v['context'].update(conversation_id=2000004),lambda v:v.update(selection_fixture={}),
        lambda v:v['context'].update(verified_company_facts=[{'text':'invented'}]),lambda v:v['provenance'].update(control='Invalid control')]:
        bad=copy.deepcopy(c);mutate(bad)
        try:validate_case(bad,3)
        except ValueError:pass
        else:raise AssertionError('invalid_case_accepted')
    assert proposal_hash([c])!=source_manifest()['proposal_inputs_sha256']
    historical=copy.deepcopy(c);historical['case_id']='L01-001';historical['context']['conversation_id']=330001
    historical['provenance']={'kind':'prepared_anex_b42_development','exact_context_reused':True,'future_messages_added':0,'source_input_id':'PX42-01','source_input_sha256':'a'*64,'context_sha256':digest(encoded(historical['context']))}
    validate_case(historical,1)
    historical['context']['messages'][0]['text']='changed'
    try:validate_case(historical,1)
    except ValueError:pass
    else:raise AssertionError('historical_hash_not_bound')
    print('L01 Standard queue synthetic checks PASS; exact14 incl policy/error/unknown, no expansion/replay, frozen proposal binding')

if __name__=='__main__':
    import sys
    if sys.argv[1:]!=['--self-test']:raise SystemExit('self_test_only')
    self_test()
