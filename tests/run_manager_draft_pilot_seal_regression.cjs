'use strict';
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const {seal} = require('../tools/pilot-manager-draft-20261019/seal.cjs');
const pair = crypto.generateKeyPairSync('rsa', {modulusLength:2048});
const pem = pair.publicKey.export({type:'spki',format:'pem'});
const task = {source_sha:'4c0e35b3f8a2362efcc7ba12348b041b8f1eba40',task_token:'synthetic-only',recipient_pem:pem};
const batch = {ok:true,schema:'manager_draft_pilot_v1',source:{production_sha:task.source_sha},
  conversations:[{synthetic:'private sentinel'}],exported_count:1,captured_at_utc:'2026-10-19 12:00:00',
  effects:{provider_calls:0,customer_sends:0,read_markers:0,database_writes:0,draft_imports:0}};
const raw = Buffer.from(JSON.stringify(batch));
const e = seal(raw, task);
assert(!JSON.stringify(e).includes('private sentinel'));
const key = crypto.privateDecrypt({key:pair.privateKey,padding:crypto.constants.RSA_PKCS1_OAEP_PADDING,oaepHash:'sha256'},Buffer.from(e.wrapped_key_b64,'base64'));
function open(aad=e.aad_b64, tag=e.tag_b64) {
  const decipher=crypto.createDecipheriv('aes-256-gcm',key,Buffer.from(e.iv_b64,'base64'));
  decipher.setAAD(Buffer.from(aad,'base64')); decipher.setAuthTag(Buffer.from(tag,'base64'));
  return Buffer.concat([decipher.update(Buffer.from(e.ciphertext_b64,'base64')),decipher.final()]);
}
assert.deepEqual(open(),raw);
assert.throws(()=>open(Buffer.from('tampered').toString('base64')));
const badTag=Buffer.from(e.tag_b64,'base64');badTag[0]^=1;
assert.throws(()=>open(e.aad_b64,badTag.toString('base64')));
assert.throws(()=>seal(Buffer.from(JSON.stringify({...batch,exported_count:2})),task));
assert.throws(()=>seal(Buffer.from(JSON.stringify({...batch,effects:{provider_calls:1}})),task));
assert.throws(()=>seal(raw,{...task,source_sha:'0'.repeat(40)}));
console.log('PASS manager draft pilot encryption: round-trip, no plaintext, tamper and provenance rejection');
