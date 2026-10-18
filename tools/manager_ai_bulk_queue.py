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

MAX_PRIMARY_CASES = 100

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

def projected_prefix_hash(case):
    p=case['provenance']
    rows=[[m['id'],m['sender_type'],m['text'],m['event_type'],m['created_at'],
           m['media_unread'],m['selections_link_unread'],m.get('text_truncated',False)]
          for m in case['context']['messages']]
    value=[p['source_ordinals'],p['source_message_ids'],p['source_epoch_seconds'],rows]
    return digest(json.dumps(value,ensure_ascii=True,separators=(',',':')).encode('ascii'))

def validate_historical_case(case,index):
    if set(case)!={'case_id','context','provenance'}:raise ValueError('invalid_case')
    p=case['provenance'];c=case['context']
    keys={'kind','cutoff_at','source_sha256','source_alias','cutoff_ordinal','source_ordinals',
          'source_message_ids','source_epoch_seconds','source_prefix_sha256','projected_prefix_sha256',
          'delivery_revision','selection_freeze_sha256'}
    if set(p)!=keys or p['kind']!='k02_development_prefix':raise ValueError('invalid_provenance')
    for key in ['source_sha256','source_prefix_sha256','projected_prefix_sha256','selection_freeze_sha256']:
        if not isinstance(p[key],str) or re.fullmatch(r'[0-9a-f]{64}',p[key]) is None:raise ValueError('invalid_provenance')
    if not isinstance(p['source_alias'],str) or re.fullmatch(r'TG[0-9]{4,}',p['source_alias']) is None:raise ValueError('invalid_source_alias')
    if not isinstance(p['delivery_revision'],str) or re.fullmatch(r'[A-Za-z0-9_-]{1,128}',p['delivery_revision']) is None:raise ValueError('invalid_delivery_revision')
    n=p['cutoff_ordinal']
    if type(n) is not int or not 1<=n<=120 or not isinstance(p['source_ordinals'],list) or any(type(v) is not int for v in p['source_ordinals']) or p['source_ordinals']!=list(range(1,n+1)):raise ValueError('invalid_source_ordinals')
    if not isinstance(c.get('messages'),list) or len(c['messages'])!=n or type(c.get('conversation_id')) is not int or c['conversation_id']!=1000000+index:raise ValueError('invalid_context_binding')
    if not isinstance(p['source_epoch_seconds'],list) or len(p['source_epoch_seconds'])!=n:raise ValueError('invalid_source_epochs')
    ids=[m['id'] for m in c['messages']]
    if not isinstance(p['source_message_ids'],list) or any(type(mid) is not int for mid in p['source_message_ids']) or p['source_message_ids']!=ids or any(type(mid) is not int or mid<=0 for mid in ids) or ids!=sorted(set(ids)) or c.get('as_of_message_id')!=ids[-1]:raise ValueError('invalid_source_ids')
    try:
        cutoff=datetime.strptime(p['cutoff_at'],'%Y-%m-%dT%H:%M:%SZ').replace(tzinfo=timezone.utc)
        if cutoff.strftime('%Y-%m-%dT%H:%M:%SZ')!=p['cutoff_at']:raise ValueError('invalid_cutoff')
        for epoch,m in zip(p['source_epoch_seconds'],c['messages']):
            if type(epoch) is not int or not 0<=epoch<=253402300799:raise ValueError('invalid_epoch')
            stamp=datetime.fromtimestamp(epoch,timezone.utc)
            if stamp.strftime('%Y-%m-%dT%H:%M:%SZ')!=m['created_at'] or stamp>cutoff:raise ValueError('epoch_mismatch')
        if int(cutoff.timestamp())!=p['source_epoch_seconds'][-1]:raise ValueError('cutoff_epoch_mismatch')
    except (KeyError,OverflowError,TypeError):raise ValueError('invalid_source_epochs') from None
    if projected_prefix_hash(case)!=p['projected_prefix_sha256']:raise ValueError('projected_prefix_mismatch')

def input_envelope(task,prepared,cases):
    if task.get('schema')!='manager_ai_k03_task_v1' or task.get('stage')!='generate' or len(cases)!=MAX_PRIMARY_CASES or prepared.get('maximum_case_count')!=MAX_PRIMARY_CASES or task.get('parallelism')!=1:raise ValueError('study_contract_mismatch')
    ids=[c.get('case_id') for c in cases]
    if any(not isinstance(cid,str) for cid in ids) or len(ids)!=len(set(ids)):raise ValueError('invalid_case_membership')
    histories=set();freezes=set();revisions=set()
    for index,case in enumerate(cases,1):
        validate_historical_case(case,index)
        p=case['provenance'];history=(p['source_sha256'],p['source_alias'])
        if history in histories:raise ValueError('duplicate_history')
        histories.add(history);freezes.add(p['selection_freeze_sha256']);revisions.add(p['delivery_revision'])
    if len(freezes)!=1 or len(revisions)!=1:raise ValueError('freeze_binding_mismatch')
    for key in ['task_token','production_sha','source_bundle_sha256','case_count','parallelism','stage']:
        if task[key]!=prepared[key]:raise ValueError('prepared_binding_mismatch')
    recipient_hash=digest(task['output_recipient'].encode())
    if prepared['output_recipient_sha256']!=recipient_hash or len(cases)!=task['case_count']:raise ValueError('prepared_binding_mismatch')
    now=datetime.now(timezone.utc)
    expires=datetime.fromisoformat(prepared['expires_at'].replace('Z','+00:00'))
    if not 0<(expires-now).total_seconds()<=3600:raise ValueError('lease_expired')
    value={'schema':'manager_ai_k03_input_v1','repository':'pyatkoff/max-search-bot','task_token':task['task_token'],
        'production_sha':task['production_sha'],'source_bundle_sha256':task['source_bundle_sha256'],
        'output_recipient_sha256':recipient_hash,'lease_id':prepared['lease_id'],'stage':task['stage'],
        'generated_at':now.strftime('%Y-%m-%dT%H:%M:%SZ'),'expires_at':prepared['expires_at'],'cases':cases}
    plain=encoded(value)
    if len(plain)>16777216:raise ValueError('input_too_large')
    cipher=sealed_box(gzip.compress(plain,mtime=0),base64.b64decode(prepared['public_key'],validate=True))
    if len(cipher)>4194304:raise ValueError('ciphertext_too_large')
    return value,cipher

def decrypt_output(envelope,identity,expected):
    from cryptography.hazmat.primitives import serialization, hashes
    from cryptography.hazmat.primitives.asymmetric import padding
    from cryptography.hazmat.primitives.ciphers.aead import AESGCM
    if envelope.get('schema')!='manager_ai_k03_encrypted_v1':raise ValueError('envelope_schema')
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
    with tempfile.TemporaryDirectory(prefix='k03-replay-queue-') as folder:
        queue=Queue(Path(folder)/'queue.sqlite')
        contract={'model':'gpt-6.1-sol','effort':'low','tier':'fast','series':'k03-synthetic-contract'}
        cases=[{'case_id':'S'+str(i),'context':{'synthetic':i},'provenance':{'kind':'authored_synthetic'}} for i in range(100)]
        keys=[queue.enqueue(case,'generate',contract) for case in cases]
        assert queue.enqueue(dict(cases[0],case_id='renamed'),'generate',contract)==keys[0]
        for count in [1,99,101]:
            bad=keys[:count] if count<=100 else keys+['extra']
            try:queue.reserve(bad,'a'*48)
            except ValueError:pass
            else:raise AssertionError('exact100_required')
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
        queue.accept('a'*48,[
            {'case_id':'S0','provider_calls':0,'result_origin':'policy','result':{'ok':True}},
            {'case_id':'S1','provider_calls':1,'result_origin':'model','result':{'ok':False}},
        ],{'synthetic':True})
        assert queue.counts()=={'complete':1,'error':1,'unknown':98}
        try:queue.reserve(keys,'b'*48)
        except ValueError:pass
        else:raise AssertionError('unknown_or_error_replayed')
        assert queue.db.execute('SELECT COUNT(*) FROM jobs WHERE task_token IS NOT NULL').fetchone()[0]==100
        queue.db.close()
    messages=[{'id':7,'sender_type':'customer','text':'Synthetic \u2028 \U0001f30d','event_type':'message',
               'created_at':'2020-01-01T00:00:00Z','media_unread':False,'selections_link_unread':False},
              {'id':8,'sender_type':'manager','text':'Synthetic reply','event_type':'message',
               'created_at':'2020-01-01T00:00:01Z','media_unread':False,'selections_link_unread':False}]
    case={'case_id':'P01','context':{'conversation_id':1000001,'as_of_message_id':8,'messages':messages,
          'history_incomplete':False},'provenance':{'kind':'k02_development_prefix','cutoff_at':'2020-01-01T00:00:01Z',
          'source_sha256':'a'*64,'source_alias':'TG9999001','cutoff_ordinal':2,'source_ordinals':[1,2],
          'source_message_ids':[7,8],'source_epoch_seconds':[1577836800,1577836801],
          'source_prefix_sha256':'b'*64,'projected_prefix_sha256':'0'*64,
          'delivery_revision':'synthetic-r1','selection_freeze_sha256':'c'*64}}
    case['provenance']['projected_prefix_sha256']=projected_prefix_hash(case)
    validate_historical_case(case,1)
    original=encoded(case);projected_prefix_hash(case);assert encoded(case)==original
    for mutate in [
        lambda c:c['provenance'].update(kind='historical_prefix'),
        lambda c:c['provenance'].update(source_ordinals=[1]),
        lambda c:c['provenance'].update(source_ordinals=[1,1]),
        lambda c:c['provenance'].update(source_ordinals=[1,3]),
        lambda c:c['provenance'].update(source_ordinals=[True,2]),
        lambda c:c['provenance'].update(source_message_ids=[7,7]),
        lambda c:c['provenance'].update(source_epoch_seconds=[1577836800,1577836802]),
        lambda c:c['provenance'].update(source_epoch_seconds=[True,1577836801]),
        lambda c:c['context'].update(conversation_id=9999001),
    ]:
        bad=copy.deepcopy(case);mutate(bad);bad['provenance']['projected_prefix_sha256']=projected_prefix_hash(bad)
        try:validate_historical_case(bad,1)
        except ValueError:pass
        else:raise AssertionError('invalid_prefix_accepted')
    print('K03 queue synthetic checks PASS; exact100 attempts incl policy/error/unknown, no expansion/replay, strict detached prefix tuple')

if __name__=='__main__':
    import sys
    if sys.argv[1:]!=['--self-test']:raise SystemExit('self_test_only')
    self_test()
