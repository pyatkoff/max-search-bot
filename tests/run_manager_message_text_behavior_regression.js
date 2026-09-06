const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

class Node {
  constructor(tag = '#text', text = '') { this.tag = tag; this.children = []; this.value = text; this.dataset = {}; }
  set textContent(value) { this.value = String(value); this.children = []; }
  get textContent() { return this.value + this.children.map(n => n.textContent).join(''); }
  set innerHTML(_) { throw new Error('Message content must never enter an HTML parser'); }
  appendChild(node) { this.children.push(...(node.tag === '#fragment' ? node.children : [node])); return node; }
  replaceChildren(...nodes) { this.value = ''; this.children = []; nodes.forEach(n => this.appendChild(n)); }
  get childNodes() { return this.children; }
}
const box = new Node('div');
Object.assign(box, { scrollHeight: 400, scrollTop: 0, clientHeight: 300 });
const context = {
  window: { WorkspaceV2: { S: {}, $: id => id === 'messages' ? box : null } },
  document: { createElement: tag => new Node(tag), createTextNode: text => new Node('#text', text), createDocumentFragment: () => new Node('#fragment') },
};
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../manager/assets/workspace-v2-conversation.js'), 'utf8'), context);
function render(sender_type, text) {
  const message = { sender_type, text, created_at: '2026-09-06 14:19:01' };
  context.window.WorkspaceV2Conversation.renderMessages([message]);
  assert.equal(message.text, text, 'Stored message must stay intact');
  return box.children[0].children.find(n => n.className === 'msgBody');
}
const phrase = '📱 <b>Менеджер пока не успел ответить</b>\nМожно продолжить ждать.';
const body = render('ai', phrase);
assert.equal(body.textContent, '📱 Менеджер пока не успел ответить\nМожно продолжить ждать.');
assert.equal(body.children.find(n => n.tag === 'strong')?.textContent, 'Менеджер пока не успел ответить');
assert.equal(render('ai', '<b>Первый</b> и <b>второй</b>').textContent, 'Первый и второй');
for (const sender of ['customer', 'manager', 'system', undefined]) {
  assert.equal(render(sender, phrase).textContent, phrase, `${sender}: literal transcript must be preserved`);
}
for (const text of ['<b>незакрытый', '<b onclick="alert(1)">текст</b>', '<img src=x onerror=alert(1)>', '<script>alert(1)</script>', '&lt;b&gt;текст&lt;/b&gt;', '<b><img src=x onerror=alert(1)></b>']) {
  assert.equal(render('ai', text).textContent, text, 'Unknown/unsafe markup stays literal');
}
const mixed = render('ai', '<b>Заголовок</b> <a href="javascript:alert(1)">ссылка</a>');
assert.equal(mixed.textContent, 'Заголовок <a href="javascript:alert(1)">ссылка</a>');
assert.ok(mixed.children.every(n => ['#text', 'strong'].includes(n.tag)));
assert.equal(render('ai', null).textContent, '');

const inboxContext={window:{WorkspaceV2:{S:{},$:()=>null,esc:value=>String(value??''),pipe(){},api(){},statusText(){},outcomeText(){},formatWait(){}}},document:{}};
vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../manager/assets/workspace-v2-inbox.js'),'utf8'),inboxContext);
const preview=inboxContext.window.WorkspaceV2Inbox.messagePreview;
assert.equal(preview(phrase,'ai'),'📱 Менеджер пока не успел ответить\nМожно продолжить ждать.');
assert.equal(preview('<b>Первый</b> и <b>второй</b>','ai'),'Первый и второй');
for(const sender of ['customer','manager','system',undefined])assert.equal(preview(phrase,sender),phrase,`${sender}: literal inbox preview must be preserved`);
for(const text of ['<b>незакрытый','<b onclick="alert(1)">текст</b>','<img src=x onerror=alert(1)>','<b><img src=x onerror=alert(1)></b>'])assert.equal(preview(text,'ai'),text,'Unknown/unsafe preview markup stays literal');

console.log('PASS bot bold headings and previews, literal customer/manager text, immutable source and no HTML parsing');
