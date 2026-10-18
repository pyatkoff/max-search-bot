'use strict';
// Ciphertext-only transport. Standard Node crypto, no network or private-key input.
const fs=require('node:fs');
const crypto=require('node:crypto');
const path=require('node:path');
const fail=()=>{throw Error('bulk_transport_invalid')};
function taskCheck(t){
  const common=['schema','phase','task_token','production_sha','source_bundle_sha256','output_recipient','case_count','parallelism','stage'];
  const extra=t.phase==='evaluate'?['ciphertext_base64','ssh_host_key']:['collect','cleanup'].includes(t.phase)?['ssh_host_key']:[];
  if(t.schema!=='manager_ai_k03_task_v1'||!['verify','prepare','evaluate','collect','cleanup'].includes(t.phase)||Object.keys(t).sort().join()!=common.concat(extra).sort().join())fail();
  for(const [k,n] of [['task_token',48],['production_sha',40],['source_bundle_sha256',64]])if(typeof t[k]!=='string'||!new RegExp('^[0-9a-f]{'+n+'}$').test(t[k]))fail();
  if(!Number.isInteger(t.case_count)||t.case_count!==100||!Number.isInteger(t.parallelism)||t.parallelism!==1||t.stage!=='generate')fail();
  if(typeof t.output_recipient!=='string'||t.output_recipient.length>1200)fail();
  const key=crypto.createPublicKey(t.output_recipient);
  if(key.asymmetricKeyType!=='rsa'||key.asymmetricKeyDetails.modulusLength!==3072||key.asymmetricKeyDetails.publicExponent!==65537n)fail();
  if(extra.includes('ssh_host_key')&&(typeof t.ssh_host_key!=='string'||t.ssh_host_key.length>2048||!/^(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp256) [A-Za-z0-9+/]+={0,2}$/.test(t.ssh_host_key)))fail();
  if(t.phase==='evaluate'&&(typeof t.ciphertext_base64!=='string'||t.ciphertext_base64.length>5592408||!/^[A-Za-z0-9+/]+={0,2}$/.test(t.ciphertext_base64)||Buffer.from(t.ciphertext_base64,'base64').length>4194304))fail();
  return t;
}
const dependencyFiles=[
  'ai/ManagerDraftClient.php','services/ManagerAiTextRedactor.php','services/ManagerCompanyKnowledge.php',
  'services/ManagerSelectionLinks.php','services/ManagerSelectionPageParser.php',
  'services/ManagerSelectionSnapshot.php','services/ManagerAiDraftService.php'
];
function dependencyHashes(){
  return Object.fromEntries(dependencyFiles.map(filename=>[filename,crypto.createHash('sha256').update(fs.readFileSync(filename)).digest('hex')]));
}
function makeBundle(){
  const dependencies=dependencyHashes();
  let result="<?php\ndeclare(strict_types=1);\ndefine('MANAGER_AI_BULK_LIBRARY_ONLY',true);\n";
  result+="define('MANAGER_AI_K03_REPLAY_DEPENDENCIES',json_decode('"+JSON.stringify(dependencies)+"',true,16,JSON_THROW_ON_ERROR));\n";
  const imports={
    'services/ManagerSelectionPageParser.php':["require_once __DIR__.'/ManagerAiTextRedactor.php';"],
    'services/ManagerSelectionSnapshot.php':["require_once __DIR__.'/ManagerSelectionLinks.php';","require_once __DIR__.'/ManagerSelectionPageParser.php';"],
    'services/ManagerAiDraftService.php':["require_once dirname(__DIR__).'/ai/ManagerDraftClient.php';","require_once __DIR__.'/ManagerCompanyKnowledge.php';","require_once __DIR__.'/ManagerSelectionSnapshot.php';"]
  };
  for(const filename of [...dependencyFiles,'tools/manager_ai_bulk.php']){
    let s=fs.readFileSync(filename,'utf8');if(!s.startsWith('<?php\n')||s.includes('\r'))fail();s=s.slice(6);
    if(!/^\s*declare\(strict_types=1\);\s*/.test(s))fail();s=s.replace(/^\s*declare\(strict_types=1\);\s*/,'');
    for(const req of imports[filename]||[]){if(!s.startsWith(req))fail();s=s.slice(req.length).trimStart();}
    if(filename!=='tools/manager_ai_bulk.php'&&/^\s*require(?:_once)?\b/m.test(s))fail();
    result+=s+'\n';
  }
  return result;
}
function seal(data,recipient,aad){
  const key=crypto.randomBytes(32),iv=crypto.randomBytes(12),cipher=crypto.createCipheriv('aes-256-gcm',key,iv);
  const aadBytes=Buffer.from(JSON.stringify(aad));cipher.setAAD(aadBytes);
  const encrypted=Buffer.concat([cipher.update(data),cipher.final()]);
  const wrapped=crypto.publicEncrypt({key:recipient,oaepHash:'sha256',padding:crypto.constants.RSA_PKCS1_OAEP_PADDING},key);key.fill(0);
  return {schema:'manager_ai_k03_encrypted_v1',aad:aadBytes.toString('base64'),wrapped_key:wrapped.toString('base64'),iv:iv.toString('base64'),tag:cipher.getAuthTag().toString('base64'),ciphertext:encrypted.toString('base64')};
}
function selfTest(){
  const {privateKey,publicKey}=crypto.generateKeyPairSync('rsa',{modulusLength:3072});
  const e=seal(Buffer.from('SYNTHETIC_SENTINEL'),publicKey,{scope:'test'});
  const key=crypto.privateDecrypt({key:privateKey,oaepHash:'sha256'},Buffer.from(e.wrapped_key,'base64'));
  function open(tag){const d=crypto.createDecipheriv('aes-256-gcm',key,Buffer.from(e.iv,'base64'));d.setAAD(Buffer.from(e.aad,'base64'));d.setAuthTag(tag);return Buffer.concat([d.update(Buffer.from(e.ciphertext,'base64')),d.final()]);}
  if(open(Buffer.from(e.tag,'base64')).toString()!=='SYNTHETIC_SENTINEL')fail();
  const tag=Buffer.from(e.tag,'base64');tag[0]^=1;let rejected=false;try{open(tag)}catch{rejected=true}if(!rejected)fail();
  const wrong=crypto.generateKeyPairSync('rsa',{modulusLength:3072});
  rejected=false;try{crypto.privateDecrypt({key:wrong.privateKey,oaepHash:'sha256'},Buffer.from(e.wrapped_key,'base64'))}catch{rejected=true}if(!rejected)fail();
  const t={schema:'manager_ai_k03_task_v1',phase:'verify',task_token:'a'.repeat(48),production_sha:'b'.repeat(40),source_bundle_sha256:'c'.repeat(64),output_recipient:publicKey.export({type:'spki',format:'pem'}),case_count:100,parallelism:1,stage:'generate'};
  taskCheck(t);for(const bad of [{...t,parallelism:2},{...t,case_count:101},{...t,stage:'grade'},{...t,case_count:0},{...t,raw_transcript:'SYNTHETIC'}]){let no=false;try{taskCheck(bad)}catch{no=true}if(!no)fail();}
  const bundle=makeBundle();
  for(const name of ['ManagerDraftClient','ManagerAiTextRedactor','ManagerCompanyKnowledge','ManagerSelectionLinks','ManagerSelectionPageParser','ManagerSelectionSnapshot','ManagerAiDraftService']) if(bundle.split('final class '+name+'\n').length!==2)fail();
  if(bundle.includes('require_once ')||Object.keys(dependencyHashes()).length!==7)fail();
  process.stdout.write('bulk envelope: own-key, tamper and metadata checks PASS\n');
}
function main(){
  const cmd=process.argv[2];if(cmd==='test'){selfTest();return}
  if(cmd==='bundle-hash'){process.stdout.write(crypto.createHash('sha256').update(makeBundle()).digest('hex')+'\n');return;}
  const dir=process.env.PRIVATE_DIR;if(!dir||!path.isAbsolute(dir))fail();
  if(cmd==='validate'){
    const bytes=fs.readFileSync('.github/manager-ai-bulk-task.json');if(bytes.length>8388608)fail();
    const t=taskCheck(JSON.parse(bytes));const bundle=makeBundle();if(crypto.createHash('sha256').update(bundle).digest('hex')!==t.source_bundle_sha256)fail();
    fs.mkdirSync(dir,{mode:0o700,recursive:true});fs.writeFileSync(path.join(dir,'task.json'),JSON.stringify(t),{mode:0o600});
    const sealed=t.phase==='evaluate'?t.ciphertext_base64:'';
    fs.writeFileSync(path.join(dir,'remote.php'),bundle+"\nexit(ManagerAiBulkEval::main($_SERVER['argv']??[], '"+sealed+"'));\n",{mode:0o600});
    const vars={phase:t.phase,task_token:t.task_token,production_sha:t.production_sha,source_bundle_sha256:t.source_bundle_sha256,recipient_sha256:crypto.createHash('sha256').update(t.output_recipient).digest('hex'),case_count:t.case_count,parallelism:t.parallelism,stage:t.stage,valid:'true'};
    fs.appendFileSync(process.env.GITHUB_OUTPUT,Object.entries(vars).map(([k,v])=>`${k}=${v}`).join('\n')+'\n');
  }else if(cmd==='pin'){
    const t=taskCheck(JSON.parse(fs.readFileSync(path.join(dir,'task.json'))));const host=process.env.DEPLOY_HOST;
    if(!host||/[\s,\[\]*?!\\]/.test(host))fail();fs.writeFileSync(path.join(dir,'known_hosts'),host+' '+t.ssh_host_key+'\n',{mode:0o600});
  }else if(cmd==='finish'){
    const t=taskCheck(JSON.parse(fs.readFileSync(path.join(dir,'task.json'))));
    const raw=fs.readFileSync(path.join(dir,'remote-output.jsonl'));if(raw.length>16777216||!raw.length)fail();
    if(t.phase==='prepare'){
      const p=JSON.parse(raw);if(p.type!=='prepared'||p.ok!==true||p.schema!=='manager_ai_k03_lease_v1'||p.task_token!==t.task_token||p.production_sha!==t.production_sha||p.source_bundle_sha256!==t.source_bundle_sha256||p.output_recipient_sha256!==crypto.createHash('sha256').update(t.output_recipient).digest('hex')||p.case_count!==t.case_count||p.parallelism!==t.parallelism||p.stage!==t.stage||p.fork_available!==true||p.maximum_case_count!==100||JSON.stringify(p.dependency_sha256)!==JSON.stringify(dependencyHashes()))fail();
      if(!fs.readFileSync(path.join(dir,'https-proof.json')).equals(raw))fail();
      const hosts=fs.readFileSync(path.join(dir,'known_hosts'),'utf8').trim().split('\n');if(hosts.length!==1)fail();
      const parts=hosts[0].split(/\s+/);if(parts.length!==3)fail();p.ssh_host_key=parts.slice(1).join(' ');p.source_commit=process.env.GITHUB_SHA;
      fs.writeFileSync(path.join(dir,'prepared.json'),JSON.stringify(p)+'\n',{mode:0o600});
    }else if(t.phase==='cleanup'){
      const p=JSON.parse(raw);if(p.type!=='cleanup'||p.ok!==true)fail();fs.writeFileSync(path.join(dir,'cleanup.json'),JSON.stringify(p)+'\n',{mode:0o600});
    }else{
      const execution=JSON.parse(fs.readFileSync(path.join(dir,'execution.json')));
      const aad={schema:'manager_ai_k03_binding_v1',task_token:t.task_token,production_sha:t.production_sha,source_bundle_sha256:t.source_bundle_sha256,recipient_sha256:crypto.createHash('sha256').update(t.output_recipient).digest('hex'),stage:t.stage};
      const envelope=seal(Buffer.from(JSON.stringify({raw_jsonl:raw.toString('utf8'),execution})),t.output_recipient,aad);
      fs.writeFileSync(path.join(dir,'results.enc.json'),JSON.stringify(envelope),{mode:0o600});
      fs.appendFileSync(process.env.GITHUB_OUTPUT,'encrypted=true\n');
      // Any incomplete output is preserved encrypted; the final workflow gate remains red.
      let complete=false;try{const rows=raw.toString().trim().split('\n').map(JSON.parse);complete=rows[0].expected_cases===t.case_count&&rows.at(-1).complete===true&&rows.filter(r=>r.type==='case').length===t.case_count&&execution.remote_ok===true&&execution.deployed_ok===true&&execution.main_ok===true;}catch{}
      fs.writeFileSync(path.join(dir,'complete'),complete?'true':'false',{mode:0o600});
    }
  }else fail();
}
if(require.main===module){try{main()}catch{process.stderr.write('bulk_transport_invalid\n');process.exitCode=1}}
module.exports={seal,taskCheck,makeBundle,dependencyHashes};
