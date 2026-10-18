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
from pathlib import Path
import sqlite3
from datetime import datetime, timezone

MAX_PRIMARY_CASES = 36

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
            self.db.execute('INSERT OR IGNORE INTO jobs(fingerprint,case_id,stage,state,task_token,input_json,result_json,input_hash,contract_hash) VALUES(?,?,?,\'pending\',NULL,?,NULL,?,?)',(key,case['case_id'],stage,encoded(case).decode(),input_hash,contract_hash))
        return key

    def reserve(self,keys,token):
        if not keys or len(keys)>MAX_PRIMARY_CASES or len(keys)!=len(set(keys)):raise ValueError('invalid_queue_keys')
        with self.db:
            rows=[self.db.execute('SELECT state,input_json FROM jobs WHERE fingerprint=?',(key,)).fetchone() for key in keys]
            if any(not row or row[0]!='pending' for row in rows):raise ValueError('queue_replay_forbidden')
            stages={self.db.execute('SELECT stage FROM jobs WHERE fingerprint=?',(key,)).fetchone()[0] for key in keys}
            if stages!={'generate'}:raise ValueError('grading_disabled')
            stage=next(iter(stages))
            spent=self.db.execute('SELECT COUNT(*) FROM jobs WHERE stage=? AND task_token IS NOT NULL',(stage,)).fetchone()[0]
            if spent+len(keys)>MAX_PRIMARY_CASES:raise ValueError('study_attempt_budget_exceeded')
            for key in keys:self.db.execute('UPDATE jobs SET state=\'submitted\',task_token=? WHERE fingerprint=?',(token,key))
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

def input_envelope(task,prepared,cases):
    if task.get('stage')!='generate' or not 1<=len(cases)<=MAX_PRIMARY_CASES or prepared.get('maximum_case_count')!=MAX_PRIMARY_CASES:raise ValueError('study_contract_mismatch')
    ids=[c.get('case_id') for c in cases]
    if any(not isinstance(cid,str) for cid in ids) or len(ids)!=len(set(ids)):raise ValueError('invalid_case_membership')
    for key in ['task_token','production_sha','source_bundle_sha256','case_count','parallelism','stage']:
        if task[key]!=prepared[key]:raise ValueError('prepared_binding_mismatch')
    recipient_hash=digest(task['output_recipient'].encode())
    if prepared['output_recipient_sha256']!=recipient_hash or len(cases)!=task['case_count']:raise ValueError('prepared_binding_mismatch')
    now=datetime.now(timezone.utc)
    expires=datetime.fromisoformat(prepared['expires_at'].replace('Z','+00:00'))
    if not 0<(expires-now).total_seconds()<=3600:raise ValueError('lease_expired')
    value={'schema':'manager_ai_bulk_input_v1','repository':'pyatkoff/max-search-bot','task_token':task['task_token'],
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
    if envelope.get('schema')!='manager_ai_bulk_encrypted_v1':raise ValueError('envelope_schema')
    aad=base64.b64decode(envelope['aad'],validate=True);binding=json.loads(aad)
    for key,value in expected.items():
        if binding.get(key)!=value:raise ValueError('output_binding_mismatch')
    key=serialization.load_pem_private_key(Path(identity).read_bytes(),password=None)
    secret=key.decrypt(base64.b64decode(envelope['wrapped_key'],validate=True),padding.OAEP(mgf=padding.MGF1(hashes.SHA256()),algorithm=hashes.SHA256(),label=None))
    ciphertext=base64.b64decode(envelope['ciphertext'],validate=True)+base64.b64decode(envelope['tag'],validate=True)
    plain=AESGCM(secret).decrypt(base64.b64decode(envelope['iv'],validate=True),ciphertext,aad)
    return json.loads(plain)

def self_test():
    import tempfile
    with tempfile.TemporaryDirectory(prefix='selection-eval-queue-') as folder:
        queue=Queue(Path(folder)/'queue.sqlite')
        contract={'model':'gpt-6.1-sol','effort':'low','tier':'fast','series':'synthetic-contract'}
        case={'case_id':'S01','context':{'synthetic':True},'provenance':{'kind':'authored_synthetic'}}
        key=queue.enqueue(case,'generate',contract)
        renamed=dict(case,case_id='renamed')
        assert queue.enqueue(renamed,'generate',contract)==key
        try:queue.enqueue(case,'grade',contract)
        except ValueError:pass
        else:raise AssertionError('grading_enabled')
        assert queue.reserve([key],'a'*48)==[case]
        try:queue.reserve([key],'b'*48)
        except ValueError:pass
        else:raise AssertionError('submitted_replayed')
        queue.accept('a'*48,[],{'synthetic':True})
        assert queue.counts()=={'unknown':1}
        try:queue.reserve([key],'b'*48)
        except ValueError:pass
        else:raise AssertionError('unknown_replayed')
        keys=[]
        for i in range(35):
            keys.append(queue.enqueue({'case_id':'T'+str(i),'context':{'synthetic':i},'provenance':{'kind':'authored_synthetic'}},'generate',contract))
        queue.reserve(keys,'c'*48)
        extra=queue.enqueue({'case_id':'EXTRA','context':{'synthetic':'extra'},'provenance':{'kind':'authored_synthetic'}},'generate',contract)
        try:queue.reserve([extra],'d'*48)
        except ValueError as error:assert str(error)=='study_attempt_budget_exceeded'
        else:raise AssertionError('study_budget_exceeded')
        queue.db.close()
    print('selection queue synthetic checks PASS; generation-only, deduplication, unknown/no replay and36-case budget')

if __name__=='__main__':
    import sys
    if sys.argv[1:]!=['--self-test']:raise SystemExit('self_test_only')
    self_test()
