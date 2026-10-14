const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');

class Node{
  constructor(tag='#text',text=''){this.tag=tag;this.children=[];this.value=text;this.dataset={};}
  set textContent(value){this.value=String(value);this.children=[];}
  get textContent(){return this.value+this.children.map(n=>n.textContent).join('');}
  set innerHTML(_){throw new Error('Transcript content must never enter an HTML parser');}
  appendChild(node){this.children.push(...(node.tag==='#fragment'?node.children:[node]));return node;}
  replaceChildren(...nodes){this.value='';this.children=[];nodes.forEach(n=>this.appendChild(n));}
  get childNodes(){return this.children;}
}
const box=new Node('div');Object.assign(box,{scrollHeight:400,scrollTop:0,clientHeight:300});
const context={window:{WorkspaceV2:{S:{},$:id=>id==='messages'?box:null}},document:{createElement:tag=>new Node(tag),createTextNode:text=>new Node('#text',text),createDocumentFragment:()=>new Node('#fragment')}};
vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../manager/assets/manager-time.js'),'utf8'),context);
vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../manager/assets/workspace-v2-conversation.js'),'utf8'),context);
const action=(raw,label='Клиент нажал кнопку')=>({type:'callback',label,raw_payload:raw});
const message=(id,text,extra={})=>({id,direction:'inbound',sender_type:'customer',text,created_at:'2026-08-24 14:20:00',...extra});
function render(rows){const before=JSON.stringify(rows);context.window.WorkspaceV2Conversation.renderMessages(rows);assert.equal(JSON.stringify(rows),before,'Rendering preserves exact source messages');return box.children;}
const find=(node,name)=>node.children.find(c=>c.className===name);

const raw='manager_after_tours',label='Клиент запросил менеджера после подбора туров';
const projected=message(1,raw,{service_action:action(raw,label)});
const event=render([projected])[0];
assert.equal(event.className,'msg serviceAction','Callback is not rendered as a tourist utterance');
assert.equal(event.dataset.scrollMessageKey,'1','Existing scroll identity survives');
assert.equal(find(event,'messageSender').textContent,'Действие клиента');
assert.equal(find(event,'msgBody').textContent,label);
const details=find(event,'serviceActionDetails');
assert.equal(details.tag,'details','Raw payload is available through native disclosure');
assert.equal(details.children[0].textContent,'Служебные подробности');
assert.equal(details.children[1].textContent,raw);
assert.ok(find(event,'msgMeta').textContent,'Original timestamp stays present');

for(const text of ['manager_after_tours','phone_manual','g1_1234abcd_phone_manual','<img src=x onerror=alert(1)>']){
  const ordinary=render([message(2,text)])[0];
  assert.equal(ordinary.className,'msg customer');assert.equal(find(ordinary,'msgBody').textContent,text);assert.equal(find(ordinary,'serviceActionDetails'),undefined);
}
for(const extra of [{sender_type:'manager'},{direction:'outbound'},{sender_type:'ai'},{service_action:{type:'message',label:'wrong',raw_payload:raw}}]){
  const ignored=render([message(3,raw,{service_action:action(raw,label),...extra})])[0];
  assert.notEqual(ignored.className,'msg serviceAction','Only confirmed inbound customer callback projection is rendered as an event');
}
const unknownRaw='unknown_<svg onload=alert(1)>',unknown=render([message(4,unknownRaw,{service_action:action(unknownRaw)})])[0];
assert.equal(find(unknown,'msgBody').textContent,'Клиент нажал кнопку');
assert.equal(find(unknown,'serviceActionDetails').children[1].textContent,unknownRaw,'Unknown raw code remains literal text');
const linked=message(5,'phone_manual',{service_action:action('phone_manual','Клиент выбрал ручной ввод телефона'),linked_message:{type:'reply',mid:'99',sender_name:'Турист',text:'Цитата <b>без HTML</b>'},attachments:[{type:'file',url:'media-file.php?id=fixture',name:'offer.pdf'}]});
const rows=render([projected,linked,message(6,'Обычная реплика'),message(7,'<b>Здравствуйте</b>',{sender_type:'ai',direction:'outbound'})]);
assert.deepEqual(rows.map(r=>r.dataset.scrollMessageKey),['1','5','6','7']);
assert.ok(find(rows[1],'linkedMessage').textContent.includes('Цитата <b>без HTML</b>'));
assert.equal(find(rows[1],'attachments').children[0].href,'media-file.php?id=fixture');
assert.equal(find(rows[1],'msgBody').textContent,'Клиент выбрал ручной ввод телефона');
assert.equal(find(rows[3],'messageSender').textContent,'Бот');
assert.equal(find(rows[3],'msgBody').textContent,'Здравствуйте','Existing safe bot bold presentation is preserved');
console.log('PASS typed callback events, literal tourist text, raw details, unknown actions, immutable order/IDs, quotes/media and bot vocabulary');
