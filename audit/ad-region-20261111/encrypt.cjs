'use strict';
const fs=require('node:fs'),crypto=require('node:crypto');
try {
 const input=fs.readFileSync(0);
 if(input.length>8000000)throw Error('bound');
 const sha=process.env.GITHUB_SHA,request=process.env.AUDIT_REQUEST_ID;
 if(!/^[a-f0-9]{40}$/.test(sha)||!/^[a-f0-9]{32}$/.test(request))throw Error('scope');
 const publicKey=Buffer.from(process.env.REPORT_PUBLIC_KEY_B64||'','base64');
 const info=crypto.createPublicKey(publicKey);
 if(info.asymmetricKeyType!=='rsa'||info.asymmetricKeyDetails.modulusLength<3072)throw Error('key');
 const key=crypto.randomBytes(32),nonce=crypto.randomBytes(12);
 const aad=Buffer.from('advertising-region-audit-v1:'+request+':'+sha);
 const cipher=crypto.createCipheriv('aes-256-gcm',key,nonce);
 cipher.setAAD(aad);
 const ciphertext=Buffer.concat([cipher.update(input),cipher.final(),cipher.getAuthTag()]);
 const wrapped=crypto.publicEncrypt({key:publicKey,padding:crypto.constants.RSA_PKCS1_OAEP_PADDING,oaepHash:'sha256'},key);
 const b64=x=>x.toString('base64');
 process.stdout.write(JSON.stringify({schema:1,algorithm:'RSA-OAEP-SHA256+A256GCM',workflow_sha:sha,request_id:request,aad:b64(aad),nonce:b64(nonce),wrapped_key:b64(wrapped),ciphertext:b64(ciphertext)}));
 key.fill(0);
}catch(_){process.stderr.write('ENCRYPTION_REFUSED\n');process.exitCode=1;}
