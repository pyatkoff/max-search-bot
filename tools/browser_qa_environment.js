'use strict';
const fs=require('node:fs'),path=require('node:path');
const check=(ok,message)=>{if(!ok)throw new Error(message);};
function pin(){
  const value=JSON.parse(fs.readFileSync(path.join(__dirname,'browser_qa_environment.json'),'utf8'));
  check(value.schema===1&&/^ghcr\.io\/pyatkoff\/max-search-bot-browser-qa@sha256:[a-f0-9]{64}$/.test(value.image||'')&&/^[a-f0-9]{64}$/.test(value.recipeSha||''),'Invalid immutable browser environment pin');
  check(value.runtime&&/^v22\./.test(value.runtime.node)&&/^8\.3\./.test(value.runtime.php)&&value.runtime.playwright==='1.55.0'&&value.runtime.platform==='linux'&&value.runtime.arch==='x64','Invalid prepared browser toolchain');
  return value;
}
function assertExecution(execution,expected=pin()){
  check(execution&&execution.containerImage===expected.image&&execution.recipeSha===expected.recipeSha,'Native browser execution must use the pinned prepared image/recipe');
  for(const [key,value] of Object.entries(expected.runtime))check(execution[key]===value,'Prepared browser execution differs: '+key);
  return execution;
}
function fromEnvironment(env,execution){
  if(env.QA_BROWSER_IMAGE===undefined)return execution; // Host planner/assembler is not native execution.
  const expected=pin();
  check(env.QA_BROWSER_IMAGE===expected.image,'Unapproved browser container override');
  const built=JSON.parse(fs.readFileSync('/opt/manager2-qa/runtime.json','utf8'));
  const result={...execution,containerImage:env.QA_BROWSER_IMAGE,recipeSha:built.recipeSha};
  for(const key of Object.keys(expected.runtime)){
    if(!Object.hasOwn(result,key))result[key]=built[key];
    else check(result[key]===built[key],'Actual browser runtime differs from built image: '+key);
  }
  return assertExecution(result,expected);
}
function assertSourceExecution(execution,inputs){
  if(inputs?.entries?.some(entry=>entry.path==='tools/browser_qa_environment.json'))assertExecution(execution);
}
module.exports={pin,assertExecution,fromEnvironment,assertSourceExecution};
