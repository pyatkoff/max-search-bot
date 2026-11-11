const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const {createHarness,assertNoMutations}=require('./run_manager_reauth_composer_behavior_regression.js');
const window={WorkspaceV2:{S:{},$:()=>null,esc:String}};
vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../manager/assets/workspace-v2-quick-insert.js'),'utf8'),{window,setTimeout,clearTimeout});
const token=window.WorkspaceV2QuickInsert.token;
for(const [text,caret,query,start]of [['/',1,'',0],['/док',4,'док',0],['Текст /док после',10,'док',6],['\n/прайс',7,'прайс',1],['/привет-2026',12,'привет-2026',0]]){
 assert.deepEqual(JSON.parse(JSON.stringify(token(text,caret,caret))),{query,start,end:caret});
}
for(const text of ['https://example.invalid/док','слово/док','//док','/док/файл','/док?','/🙂','/'+ 'a'.repeat(121)])assert.equal(token(text,text.length,text.length),null,text);
assert.equal(token('/док',2,2),null,'a caret inside the token cannot replace its unselected suffix');
assert.equal(token('/док',0,4),null,'a human selection is not a slash command');
console.log('PASS standalone Unicode slash tokens reject URLs, paths, selections and incomplete caret ranges');
async function ready(text='До /док после'){
 const h=createHarness();await h.start();const input=h.ids.get('replyText');
 input.setSelectionRange=(start,end,direction='none')=>{input.selectionStart=start;input.selectionEnd=end;input.selectionDirection=direction};
 await h.draft(text);input.setSelectionRange(7,7);return h;
}
(async()=>{
 const h=await ready(),range=h.C.captureQuickReplyReplacement(3,7),file={name:'preserved.txt'};h.setFile(file);
 assert.ok(range);assert.equal(h.C.canReplaceQuickReply(range),true);
 assert.equal(h.C.insertQuickReply('Готовый ответ',range.context,undefined,range),true);
 assert.equal(h.ids.get('replyText').value,'До Готовый ответ после');assert.equal(h.ids.get('replyText').selectionStart,16);assert.equal(h.getFile(),file);assertNoMutations(h);
 console.log('PASS canonical replacement changes only the slash token and retains prefix, suffix, caret and exact file without sending');
 for(const kind of ['human-edit','edit-undo','caret','reopen','account','auth']){
  const h=await ready(),r=h.C.captureQuickReplyReplacement(3,7),input=h.ids.get('replyText');
  if(kind==='human-edit')await h.draft('Новый текст');
  if(kind==='edit-undo'){await h.draft('Временное изменение');await h.draft(r.text);input.setSelectionRange(7,7)}
  if(kind==='caret')input.setSelectionRange(3,3);
  if(kind==='reopen')await h.C.open(101);
  if(kind==='account')h.W.S.manager.id++;
  if(kind==='auth')h.W.showAuthRecovery();
  const value=input.value;assert.equal(h.C.canReplaceQuickReply(r),false,kind);assert.equal(h.C.insertQuickReply('Late result',r.context,undefined,r),false,kind);assert.equal(input.value,value,kind);assertNoMutations(h);
 }
 console.log('PASS late replacement cannot survive human edit/undo, caret movement, reopening, account or auth change');
 const legacy=await ready('Existing');assert.equal(legacy.C.insertQuickReply('Ordinary template'),true);assert.equal(legacy.ids.get('replyText').value,'Existing\nOrdinary template');assertNoMutations(legacy);
 assert.equal(legacy.C.insertAiDraft('Explicit AI insertion',legacy.C.captureReplyContext(),null),true);assert.equal(legacy.ids.get('replyText').value,'Existing\nOrdinary template\nExplicit AI insertion');assertNoMutations(legacy);
 console.log('PASS ordinary library and AI caller signature retain the existing append boundary');
})().catch(error=>{console.error(error);process.exitCode=1});
