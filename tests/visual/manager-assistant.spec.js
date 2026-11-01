const {test,expect}=require('@playwright/test');
const fs=require('node:fs'),path=require('node:path');
const {setup,root}=require('./fixtures/internal-notes');
const {setupAiUsage}=require('./fixtures/manager-ai-usage');

// AI_USAGE_CASES_BEGIN — same production page/API on an isolated local database.
function usageClean(s){expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);expect(s.calls.every(c=>['identity','list','review','input','sent_text','assessment_read','assessment_save'].includes(c.action))).toBe(true)}
function usageInputResponse(page){return page.waitForResponse(r=>r.url().endsWith('/manager/ai-usage-api.php')&&r.request().postDataJSON().action==='input')}
async function usageOpenInput(page,id=10047){const response=usageInputResponse(page);await page.locator('[data-usage-case="'+id+'"]').getByRole('button',{name:'Что видел AI',exact:true}).click();return(await response).json()}
function usageReadOnly(s){expect(s.calls.filter(c=>c.action==='review')).toHaveLength(0);usageClean(s)}
async function usageCapture(page,browser,width,label){const directory=path.join(root,'manager2-artifacts');fs.mkdirSync(directory,{recursive:true});await page.screenshot({animations:'disabled',path:path.join(directory,`assistant-usage-${browser}-${width}-${label}.png`)})}
for(const width of [390,430,768,1440])test('AI usage real owner comparison and explicit review '+width,async({page,browserName})=>{
  await page.setViewportSize({width,height:900});const s=await setupAiUsage(page);
  try{
    await expect(page.locator('[data-usage-case]')).toHaveCount(20);expect(s.calls.filter(c=>c.action==='review')).toHaveLength(0);
    await page.getByLabel('Только с правками').check();await expect(page.locator('[data-usage-case]').first()).toHaveAttribute('data-usage-case','10046');
    await expect(page.locator('[data-usage-case]').first()).toContainText('Светлана');await expect(page.locator('[data-usage-case]').first()).toContainText('до 220 тысяч рублей');
    expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);await usageCapture(page,browserName,width,'overview');
    const card=page.locator('[data-usage-case]').first();await card.locator('.usageComparison').scrollIntoViewIfNeeded();await usageCapture(page,browserName,width,'comparison');
    await card.getByRole('button',{name:'Отметить просмотренным',exact:true}).click();await expect(card.getByRole('button',{name:'Просмотрено',exact:true})).toBeDisabled();expect(s.calls.filter(c=>c.action==='review')).toHaveLength(1);
    await page.getByRole('button',{name:'Обновить',exact:true}).click();await expect(page.locator('[data-usage-case]').first()).toHaveAttribute('data-usage-case','10044');
    await page.getByRole('button',{name:'Все применения',exact:true}).click();await expect(page.locator('[data-usage-case]').first()).toHaveAttribute('data-usage-case','10046');await expect(page.locator('[data-usage-case]').first().getByRole('button',{name:'Просмотрено',exact:true})).toBeDisabled();
    await page.setViewportSize({width,height:420});expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);usageClean(s);
  }finally{await s.cleanup()}
});
test('AI usage keyset paging visits all real cases without repeating a page',async({page})=>{
  const s=await setupAiUsage(page);try{await expect(page.locator('[data-usage-case]')).toHaveCount(20);await page.getByRole('button',{name:'Показать ещё',exact:true}).click();await expect(page.locator('[data-usage-case]')).toHaveCount(40);await page.getByRole('button',{name:'Показать ещё',exact:true}).click();await expect(page.locator('[data-usage-case]')).toHaveCount(47);await expect(page.locator('#usageMore')).toBeHidden();const ids=await page.locator('[data-usage-case]').evaluateAll(nodes=>nodes.map(n=>n.dataset.usageCase));expect(new Set(ids).size).toBe(47);expect(s.calls.filter(c=>c.action==='review')).toHaveLength(0);usageClean(s)}finally{await s.cleanup()}
});
for(const width of [390,430,768,1440])test('AI usage opens the exact saved input and original selection only on demand '+width,async({page,browserName})=>{
  await page.setViewportSize({width,height:900});const firstPage=page.waitForResponse(r=>r.url().endsWith('/manager/ai-usage-api.php')&&r.request().postDataJSON().action==='list');const s=await setupAiUsage(page);
  try{
    await expect(page.locator('[data-usage-case]')).toHaveCount(20);const original=(await(await firstPage).json()).items[0];expect(original.message_id).toBe(10047);expect(s.calls.filter(c=>c.action==='input')).toHaveLength(0);
    const card=page.locator('[data-usage-case="10047"]'),panel=card.locator('.usageInput');await expect(card.locator('.usageQuestion')).toContainText('Хотим в Турцию в начале июня');await expect(panel).toBeHidden();
    const reply=await usageOpenInput(page);expect(reply.available).toBe(true);expect(s.calls.filter(c=>c.action==='input')).toEqual([{action:'input',message_id:10047,case_revision:original.case_revision,csrf:'fixture-usage-csrf'}]);
    await expect(card.getByRole('button',{name:'Скрыть контекст',exact:true})).toHaveAttribute('aria-expanded','true');await expect(panel).toBeVisible();
    await expect(panel.locator('.usageInputMessages h4')).toHaveText(['Система · сообщение №941','Менеджер · сообщение №942','AI · сообщение №943','Турист · сообщение №1047']);
    await expect(panel.locator('.usageInputMessages .usageText').last()).toHaveText('Сравните именно эти два отеля из подборки.\n<img src=x onerror="window.usageInjected=1">');await expect(panel).not.toContainText('Хотим в Турцию в начале июня');
    for(const caveat of ['История передана не полностью','Часть вложений не прочитана','Есть непрочитанные ссылки на подборки','сами изображения в этой записи не сохранены','Текст был сокращён до передачи AI'])await expect(panel).toContainText(caveat);
    expect(reply.input_evidence.coverage).toBe('images_omitted');expect(reply.input_evidence.images).toHaveLength(1);expect(JSON.stringify(reply)).not.toContain('data:image');await expect(panel.locator('img,script,iframe')).toHaveCount(0);
    const refs=panel.locator('.usageInputReferences');await expect(refs.locator('dd').filter({hasText:'Лазурный берег'})).toHaveText('Лазурный берег');await expect(refs.locator('dd').filter({hasText:/^Тихая бухта$/})).toHaveText('Тихая бухта');await expect(refs.locator('dd').filter({hasText:'198 000 ₽'})).toHaveText('198 000 ₽');await expect(refs.locator('dd').filter({hasText:'219 000 ₽'})).toHaveText('219 000 ₽');await expect(refs).toContainText('#942 · менеджер');await expect(refs).toContainText('актуальные цена и наличие ими не подтверждены');
    const working=refs.getByRole('heading',{name:'Рабочий черновик подборки',exact:true}).locator('..');await expect(working).toContainText('Эта запись не подтверждает отправку туристу');await expect(working).toContainText('Цена относится к составу, записанному в каждом варианте');await expect(working).toContainText('Заметка менеджера: проверить размещение троих взрослых.');await expect(working.locator('dt').filter({hasText:'Взрослых в варианте'}).locator('+ dd')).toHaveText('3');await expect(working.locator('dt').filter({hasText:'Возраста детей в варианте'}).locator('+ dd')).toHaveText('Детей нет');await expect(working).toContainText('Менеджер изменил относительно источника: Взрослых в варианте, Сумма в черновике');await expect(working).toContainText('Фотографии этого варианта не были прочитаны');
    await panel.evaluate(el=>el.scrollIntoView({block:'start'}));expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);await usageCapture(page,browserName,width,'input');
    await refs.evaluate(el=>el.scrollIntoView({block:'start'}));await usageCapture(page,browserName,width,'input-selection');
    await working.evaluate(el=>el.scrollIntoView({block:'start'}));await usageCapture(page,browserName,width,'input-working');
    await panel.getByText('Инструкции, действовавшие для этого ответа',{exact:true}).click();await expect(panel.locator('details[open] pre')).toHaveText('Сравнивай только сохранённые варианты.\nНе обещай цену без проверки.\n<script>window.usageInjected=1</script>');
    await panel.getByText('Все сохранённые данные подборки',{exact:true}).click();const exact=await panel.locator('details[open] pre').allTextContents();expect(exact.some(text=>JSON.parse(text.startsWith('{')?text:'{}').offers?.[1]?.hotel==='Тихая бухта')).toBe(true);
    await working.getByText('Исходное наблюдение до правок менеджера',{exact:true}).click();expect(JSON.parse(await working.locator('details[open] pre').textContent())).toMatchObject({adults:2,amount:'219000'});
    expect(await page.evaluate(()=>window.usageInjected)).toBeUndefined();await expect(panel.locator('img,script,iframe,a[href]')).toHaveCount(0);await page.setViewportSize({width,height:420});expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);
    await card.getByRole('button',{name:'Скрыть контекст',exact:true}).click();await expect(panel).toBeHidden();await expect(panel).toBeEmpty();await expect(card.getByRole('button',{name:'Что видел AI',exact:true})).toHaveAttribute('aria-expanded','false');expect(s.calls.filter(c=>c.action==='input')).toHaveLength(1);usageReadOnly(s);
  }finally{await s.cleanup()}
});
test('AI usage distinguishes absent prepared corrupt and unavailable saved input without reconstruction',async({page})=>{
  const s=await setupAiUsage(page);try{
    await expect(page.locator('[data-usage-case]')).toHaveCount(20);
    for(const [id,reason,text] of [[10046,'not_recorded','Вход для этого черновика не сохранён'],[10045,'prepared_input_not_captured','Вход внешнего помощника'],[10044,'invalid_evidence','не прошёл проверку целостности']]){
      const result=await usageOpenInput(page,id);expect(result).toMatchObject({available:false,reason});const panel=page.locator('[data-usage-case="'+id+'"] .usageInput');await expect(panel).toContainText(text);await expect(panel.locator('.usageInputMessages')).toHaveCount(0);await expect(panel).not.toContainText('Хотим в Турцию');
    }
    s.queueInput({body:d=>({ok:true,viewer_id:1,message_id:d.message_id,case_revision:d.case_revision,available:false,reason:'storage_unavailable'})});await usageOpenInput(page);await expect(page.locator('[data-usage-case="10047"] .usageInput')).toContainText('Хранилище контекста сейчас недоступно');expect(s.calls.filter(c=>c.action==='input')).toHaveLength(4);usageReadOnly(s);
  }finally{await s.cleanup()}
});
test('AI usage refuses stale cases and responses belonging to another message revision or context',async({page})=>{
  const s=await setupAiUsage(page);try{
    await expect(page.locator('[data-usage-case]')).toHaveCount(20);const original=await usageOpenInput(page),card=page.locator('[data-usage-case="10047"]'),panel=card.locator('.usageInput');await expect(panel.locator('.usageInputMessages')).toBeVisible();
    const wrongMessage={...original,message_id:10046},wrongRevision={...original,case_revision:'f'.repeat(64)},wrongContext=JSON.parse(JSON.stringify(original));wrongContext.input_evidence.context.conversation_id=146;
    const wrongTail=JSON.parse(JSON.stringify(original));wrongTail.input_evidence.context.as_of_message_id=1048;
    for(const [body,text] of [[{ok:false,error:'case_changed'},'Сравнение изменилось'],[wrongMessage,'Не удалось проверить контекст'],[wrongRevision,'Не удалось проверить контекст'],[{...original,available:'true'},'Не удалось проверить контекст'],[wrongContext,'Не удалось проверить данные'],[wrongTail,'Не удалось проверить данные']]){
      await card.getByRole('button',{name:'Скрыть контекст',exact:true}).click();s.queueInput({body});await usageOpenInput(page);await expect(panel).toContainText(text);await expect(panel.locator('.usageInputMessages')).toHaveCount(0);await expect(panel).not.toContainText('Сравните именно эти два отеля');
    }
    expect(s.calls.filter(c=>c.action==='input')).toHaveLength(7);usageReadOnly(s);
  }finally{await s.cleanup()}
});
test('AI usage collapse and reopen require a fresh read and discard the older in-flight input',async({page})=>{
  const s=await setupAiUsage(page);try{
    await expect(page.locator('[data-usage-case]')).toHaveCount(20);const old=await usageOpenInput(page),card=page.locator('[data-usage-case="10047"]'),panel=card.locator('.usageInput');await expect(panel.locator('.usageInputMessages')).toBeVisible();await card.getByRole('button',{name:'Скрыть контекст',exact:true}).click();
    old.input_evidence.context.messages[3].text='OBSOLETE CLOSED INPUT';s.queueInput({hold:true,body:old});await card.getByRole('button',{name:'Что видел AI',exact:true}).click();await expect.poll(()=>s.heldInputCount()).toBe(1);await card.getByRole('button',{name:'Скрыть контекст',exact:true}).click();await expect(panel).toBeHidden();await expect(panel).toBeEmpty();
    await usageOpenInput(page);await expect(panel).toContainText('Сравните именно эти два отеля');const delayed=usageInputResponse(page);s.releaseInput();await(await delayed).finished();await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));await expect(panel).not.toContainText('OBSOLETE CLOSED INPUT');expect(s.calls.filter(c=>c.action==='input')).toHaveLength(3);usageReadOnly(s);
  }finally{await s.cleanup()}
});
test('AI usage pending input still completes when another results page is appended',async({page})=>{
  const s=await setupAiUsage(page);try{
    await expect(page.locator('[data-usage-case]')).toHaveCount(20);const original=await usageOpenInput(page),card=page.locator('[data-usage-case="10047"]');await expect(card.locator('.usageInputMessages')).toBeVisible();await card.getByRole('button',{name:'Скрыть контекст',exact:true}).click();s.queueInput({hold:true,body:original});await card.getByRole('button',{name:'Что видел AI',exact:true}).click();await expect.poll(()=>s.heldInputCount()).toBe(1);
    await page.getByRole('button',{name:'Показать ещё',exact:true}).click();await expect(page.locator('[data-usage-case]')).toHaveCount(40);const delayed=usageInputResponse(page);s.releaseInput();await(await delayed).finished();await expect(card.locator('.usageInputMessages')).toBeVisible();await expect(card.locator('.usageInputStatus')).toHaveCount(0);expect(s.calls.filter(c=>c.action==='input')).toHaveLength(2);usageReadOnly(s);
  }finally{await s.cleanup()}
});
for(const action of ['filter','refresh'])test('AI usage drops a late input after '+action,async({page})=>{
  const s=await setupAiUsage(page);try{
    await expect(page.locator('[data-usage-case]')).toHaveCount(20);const old=await usageOpenInput(page),card=page.locator('[data-usage-case="10047"]');await expect(card.locator('.usageInputMessages')).toBeVisible();await card.getByRole('button',{name:'Скрыть контекст',exact:true}).click();s.queueInput({hold:true,body:old});await card.getByRole('button',{name:'Что видел AI',exact:true}).click();await expect.poll(()=>s.heldInputCount()).toBe(1);
    const list=page.waitForResponse(r=>r.url().endsWith('/manager/ai-usage-api.php')&&r.request().postDataJSON().action==='list');if(action==='filter')await page.getByLabel('Только с правками').check();else await page.getByRole('button',{name:'Обновить',exact:true}).click();await(await list).finished();await expect(page.locator('[data-usage-case]')).toHaveCount(20);
    const delayed=usageInputResponse(page);s.releaseInput();await(await delayed).finished();await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));await expect(page.locator('.usageInputMessages')).toHaveCount(0);await expect(page.locator('.usageInput:not([hidden])')).toHaveCount(0);expect(s.calls.filter(c=>c.action==='input')).toHaveLength(2);usageReadOnly(s);
  }finally{await s.cleanup()}
});
for(const outcome of ['account','forbidden','csrf'])test('AI usage clears saved input after '+outcome+' change and ignores an older reply',async({page})=>{
  const s=await setupAiUsage(page);try{
    await expect(page.locator('[data-usage-case]')).toHaveCount(20);const old=await usageOpenInput(page),card=page.locator('[data-usage-case="10047"]');await expect(card.locator('.usageInputMessages')).toBeVisible();await card.getByRole('button',{name:'Скрыть контекст',exact:true}).click();s.queueInput({hold:true,body:old});await card.getByRole('button',{name:'Что видел AI',exact:true}).click();await expect.poll(()=>s.heldInputCount()).toBe(1);
    s.queueInput({status:outcome==='account'?200:403,body:outcome==='account'?{...old,viewer_id:999}:{ok:false,error:outcome}});await usageOpenInput(page,10046);await expect(page.locator('#usageApp')).toBeHidden();await expect(page.locator('[data-usage-case]')).toHaveCount(0);
    const delayed=usageInputResponse(page);s.releaseInput();await(await delayed).finished();await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));await expect(page.locator('#usageApp')).toBeHidden();await expect(page.locator('.usageInput')).toHaveCount(0);usageReadOnly(s);
  }finally{await s.cleanup()}
});
function sentResponse(page){return page.waitForResponse(r=>r.url().endsWith('/manager/ai-usage-api.php')&&r.request().postDataJSON().action==='sent_text')}
async function openSent(page,id=10044){const response=sentResponse(page);await page.locator('[data-usage-case="'+id+'"]').getByRole('button',{name:'Текст при отправке',exact:true}).click();return(await response).json()}
for(const width of [390,430,768,1440])test('AI usage keeps the initial manager answer after a later edit '+width,async({page,browserName})=>{
  await page.setViewportSize({width,height:900});const s=await setupAiUsage(page);try{
    await expect(page.locator('[data-usage-case]')).toHaveCount(20);const card=page.locator('[data-usage-case="10044"]');await expect(card).toContainText('Позднее изменение ответа менеджера.');expect(s.calls.filter(c=>c.action==='sent_text')).toHaveLength(0);
    const result=await openSent(page),panel=card.locator('.usageSentText');expect(result).toMatchObject({ok:true,viewer_id:1,message_id:10044,available:true});expect(s.calls.filter(c=>c.action==='sent_text')[0].case_revision).toBe(result.case_revision);
    await expect(panel.locator('.usageOriginalText')).toHaveText(result.text);await expect(panel).toContainText('Здравствуйте, Мария! Для отдыха на двоих');await expect(panel).not.toContainText('Позднее изменение');await expect(panel).toContainText('Различие с предложением AI само по себе не означает исправление ошибки');
    await panel.scrollIntoViewIfNeeded();expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);await usageCapture(page,browserName,width,'initial-text');
    await card.getByRole('button',{name:'Скрыть первоначальный текст',exact:true}).click();await expect(panel).toBeHidden();await expect(panel).toBeEmpty();await openSent(page);expect(s.calls.filter(c=>c.action==='sent_text')).toHaveLength(2);await page.setViewportSize({width,height:420});expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);usageReadOnly(s);
  }finally{await s.cleanup()}
});
test('AI usage original text has honest missing invalid and failed-storage states',async({page})=>{
  const s=await setupAiUsage(page);try{
    await expect(page.locator('[data-usage-case]')).toHaveCount(20);expect(await openSent(page,10046)).toMatchObject({available:false,reason:'not_recorded'});await expect(page.locator('[data-usage-case="10046"] .usageSentText')).toContainText('Текущая версия сообщения не заменяет его');
    const card=page.locator('[data-usage-case="10044"]'),panel=card.locator('.usageSentText');
    for(const [reason,label] of [['invalid_evidence','не прошёл проверку целостности'],['storage_unavailable','Хранилище первоначального текста сейчас недоступно']]){
      s.queueSentText({body:d=>({ok:true,viewer_id:1,message_id:d.message_id,case_revision:d.case_revision,available:false,reason})});await openSent(page);await expect(panel).toContainText(label);await expect(panel.locator('.usageOriginalText')).toHaveCount(0);await card.getByRole('button',{name:'Скрыть первоначальный текст',exact:true}).click();
    }usageReadOnly(s);
  }finally{await s.cleanup()}
});
test('AI usage original text rejects stale mismatched data and renders source markup inertly',async({page})=>{
  const s=await setupAiUsage(page);try{
    await expect(page.locator('[data-usage-case]')).toHaveCount(20);const original=await openSent(page),card=page.locator('[data-usage-case="10044"]'),panel=card.locator('.usageSentText');
    for(const [body,label] of [[{ok:false,error:'case_changed'},'Сравнение изменилось'],[{...original,message_id:10046},'Не удалось проверить первоначальный текст'],[{...original,case_revision:'f'.repeat(64)},'Не удалось проверить первоначальный текст'],[{...original,text:[]},'Не удалось проверить данные']]){
      await card.getByRole('button',{name:'Скрыть первоначальный текст',exact:true}).click();s.queueSentText({body});await openSent(page);await expect(panel).toContainText(label);await expect(panel.locator('.usageOriginalText')).toHaveCount(0);
    }
    await card.getByRole('button',{name:'Скрыть первоначальный текст',exact:true}).click();s.queueSentText({body:{...original,text:'<img src=x onerror="window.usageInjected=1"> &amp; literal'}});await openSent(page);await expect(panel.locator('.usageOriginalText')).toHaveText('<img src=x onerror="window.usageInjected=1"> &amp; literal');await expect(panel.locator('img,script,a')).toHaveCount(0);expect(await page.evaluate(()=>window.usageInjected)).toBeUndefined();usageReadOnly(s);
  }finally{await s.cleanup()}
});
test('AI usage original text discards closed reads and survives appending a page',async({page})=>{
  const s=await setupAiUsage(page);try{
    await expect(page.locator('[data-usage-case]')).toHaveCount(20);const original=await openSent(page),card=page.locator('[data-usage-case="10044"]'),panel=card.locator('.usageSentText');await card.getByRole('button',{name:'Скрыть первоначальный текст',exact:true}).click();
    s.queueSentText({hold:true,body:{...original,text:'OBSOLETE ORIGINAL'}});await card.getByRole('button',{name:'Текст при отправке',exact:true}).click();await expect.poll(()=>s.heldInputCount()).toBe(1);await card.getByRole('button',{name:'Скрыть первоначальный текст',exact:true}).click();await openSent(page);const delayed=sentResponse(page);s.releaseInput();await(await delayed).finished();await expect(panel).not.toContainText('OBSOLETE ORIGINAL');
    await card.getByRole('button',{name:'Скрыть первоначальный текст',exact:true}).click();s.queueSentText({hold:true,body:original});await card.getByRole('button',{name:'Текст при отправке',exact:true}).click();await expect.poll(()=>s.heldInputCount()).toBe(1);await page.getByRole('button',{name:'Показать ещё',exact:true}).click();await expect(page.locator('[data-usage-case]')).toHaveCount(40);const appended=sentResponse(page);s.releaseInput();await(await appended).finished();await expect(panel.locator('.usageOriginalText')).toHaveText(original.text);usageReadOnly(s);
  }finally{await s.cleanup()}
});
for(const action of ['refresh','filter','account'])test('AI usage does not restore an old original text after '+action,async({page})=>{
  const s=await setupAiUsage(page);try{
    await expect(page.locator('[data-usage-case]')).toHaveCount(20);const original=await openSent(page),card=page.locator('[data-usage-case="10044"]');await card.getByRole('button',{name:'Скрыть первоначальный текст',exact:true}).click();s.queueSentText({hold:true,body:{...original,text:'OBSOLETE ORIGINAL'}});await card.getByRole('button',{name:'Текст при отправке',exact:true}).click();await expect.poll(()=>s.heldInputCount()).toBe(1);
    if(action==='account'){s.queueSentText({body:{...original,viewer_id:999}});await openSent(page,10046);await expect(page.locator('#usageApp')).toBeHidden();}else{if(action==='filter')await page.getByLabel('Только с правками').check();else await page.getByRole('button',{name:'Обновить',exact:true}).click();await expect(page.locator('[data-usage-case]')).toHaveCount(20);}
    const delayed=sentResponse(page);s.releaseInput();await(await delayed).finished();await expect(page.locator('#usageCases')).not.toContainText('OBSOLETE ORIGINAL');await expect(page.locator('.usageSentText:not([hidden])')).toHaveCount(0);usageReadOnly(s);
  }finally{await s.cleanup()}
});
test('AI usage renders hostile text inertly and labels a later message edit',async({page})=>{
  const s=await setupAiUsage(page);try{
    await expect(page.locator('[data-usage-case]')).toHaveCount(20);
    await page.route('**/manager/ai-usage-api.php',async route=>{if(route.request().postDataJSON().action!=='list')return route.fallback();const response=await route.fetch(),body=await response.json();Object.assign(body.items[0],{question_text:'<img src=x onerror="window.usageInjected=1">',proposed_text:'<script>window.usageInjected=1</script>',current_sent_text:'Later edited reply',initial_text_available:false,edited:true});return route.fulfill({response,json:body})});
    await page.getByRole('button',{name:'Обновить',exact:true}).click();const card=page.locator('[data-usage-case]').first();await expect(card).toContainText('Текст сообщения сейчас');await expect(card).toContainText('Здесь показана его текущая версия');await expect(card.locator('img,script')).toHaveCount(0);expect(await page.evaluate(()=>window.usageInjected)).toBeUndefined();usageClean(s);
  }finally{await s.cleanup()}
});
test('AI usage clears private comparisons after an account or permission change',async({page})=>{
  for(const outcome of ['account','forbidden']){
    const s=await setupAiUsage(page);try{await expect(page.locator('[data-usage-case]')).toHaveCount(20);await page.route('**/manager/ai-usage-api.php',route=>route.fulfill({status:outcome==='forbidden'?403:200,contentType:'application/json',body:JSON.stringify(outcome==='forbidden'?{ok:false,error:'forbidden'}:{ok:true,viewer_id:999,items:[]})}));await page.getByRole('button',{name:'Обновить',exact:true}).click();await expect(page.locator('#usageApp')).toBeHidden();await expect(page.locator('[data-usage-case]')).toHaveCount(0);usageClean(s)}finally{await s.cleanup();await page.unroute('**/manager/ai-usage-api.php')}
  }
});
test('AI usage rejects a late prior-filter page and never retries an uncertain review automatically',async({page})=>{
  const firstPage=page.waitForResponse(r=>r.url().endsWith('/manager/ai-usage-api.php')&&r.request().postDataJSON().action==='list');
  const s=await setupAiUsage(page);let release;
  try{
    await expect(page.locator('[data-usage-case]')).toHaveCount(20);const original=await(await firstPage).json();let held=false;
    // Reorder copies of the actual initial API response. route.fetch would write
    // Secure cookies into WebKit's HTTP-only fixture jar before delivery.
    await page.route('**/manager/ai-usage-api.php',async route=>{const d=route.request().postDataJSON();if(d.action!=='list')return route.fallback();const body=JSON.parse(JSON.stringify(original));if(d.project_key===''){body.items[0].display_name='OBSOLETE FILTER';held=true;await new Promise(resolve=>release=resolve)}return route.fulfill({contentType:'application/json',json:body})});
    const delayed=page.waitForResponse(r=>r.url().endsWith('/manager/ai-usage-api.php')&&r.request().postDataJSON().action==='list'&&r.request().postDataJSON().project_key==='');
    await page.getByRole('button',{name:'Обновить',exact:true}).click();await expect.poll(()=>held).toBe(true);await page.locator('#usageProject').selectOption('anytour');await expect(page.locator('[data-usage-case]')).toHaveCount(20);release();await(await delayed).finished();await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));await expect(page.locator('#usageCases')).not.toContainText('OBSOLETE FILTER');
    await page.unroute('**/manager/ai-usage-api.php');await page.getByRole('button',{name:'Обновить',exact:true}).click();await expect(page.locator('[data-usage-case]')).toHaveCount(20);let reviews=0;
    await page.route('**/manager/ai-usage-api.php',route=>{if(route.request().postDataJSON().action!=='review')return route.fallback();reviews++;return route.fulfill({status:503,contentType:'application/json',body:JSON.stringify({ok:false,error:'unavailable'})})});
    const card=page.locator('[data-usage-case]').first();await card.getByRole('button',{name:'Отметить просмотренным',exact:true}).click();await expect(card.locator('.usageCaseStatus')).toContainText('Обновите отчёт');expect(reviews).toBe(1);await expect(card.getByRole('button',{name:'Отметить просмотренным',exact:true})).toBeEnabled();usageClean(s);
  }finally{release?.();await s.cleanup()}
});
for(const width of [390,1440])test('AI usage owner entry follows the existing Analytics identity '+width,async({page,browserName})=>{
  await page.setViewportSize({width,height:900});const s=await setupAiUsage(page);
  try{
    await expect(page.locator('[data-usage-case]')).toHaveCount(20);let role='admin';
    await page.route('**/manager/supervision-api.php',route=>{const d=route.request().postDataJSON();return route.fulfill({contentType:'application/json',body:JSON.stringify(d.action==='identity'?{ok:true,manager:{id:1,role},csrf:'fixture-usage-csrf'}:d.action==='scope'?{ok:true,viewer_id:1,scopes:[{project_key:'anytour',display_name:'AnyTour',sources:[]}],managers:[]}:{ok:false,error:'analytics_unavailable',viewer_id:1})})});
    await page.goto(s.origin+'/manager2/analytics.php');await expect(page.locator('#analyticsAiUsage')).toBeVisible();await expect(page.locator('#analyticsAiUsage')).toHaveAttribute('href','/manager2/ai-usage.php');expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);await usageCapture(page,browserName,width,'entry');
    role='manager';await page.reload();await expect(page.locator('#analyticsAiUsage')).toBeHidden();usageClean(s);
  }finally{await s.cleanup()}
});
for(const width of [390,430,768,1440])test('AI usage issued prepared context stays distinct from full model input '+width,async({page,browserName})=>{
  await page.setViewportSize({width,height:1000});const s=await setupAiUsage(page);try{
    await expect(page.locator('[data-usage-case]')).toHaveCount(20);
    const card=page.locator('[data-usage-case="10042"]'),panel=card.locator('.usageInput');
    await card.getByRole('button',{name:'Что видел AI',exact:true}).click();
    await expect(panel.getByRole('heading',{name:'Контекст, выданный внешнему помощнику',exact:true})).toBeVisible();
    await expect(panel).toContainText('Получение и использование пакета не подтверждены');
    await expect(panel).toContainText('полный вход внешней модели');await expect(panel).toContainText('Question 42');
    await expect(panel).not.toContainText('Что AI получил при подготовке');
    await panel.getByText('Сведения о выданном пакете',{exact:true}).click();await expect(panel).toContainText('packet_sha256');
    const geometry=await page.evaluate(()=>({width:innerWidth,document:document.documentElement.scrollWidth}));
    expect(geometry.document-geometry.width).toBeLessThanOrEqual(1);
    await card.scrollIntoViewIfNeeded();await usageCapture(page,browserName,width,'issued-context');
    await card.getByRole('button',{name:'Разобрать правку',exact:true}).click();
    await expect(card.locator('.usageAssessment')).toContainText('полный вход внешней модели неизвестен');
    const form=card.locator('.usageAssessmentForm');await form.getByLabel('Вид правки',{exact:true}).selectOption('meaning');
    await expect(form.getByLabel('Польза правки',{exact:true})).toBeDisabled();
    expect(s.calls.filter(c=>c.action==='input')).toHaveLength(1);usageReadOnly(s);
  }finally{await s.cleanup()}
});
function assessmentCard(page,id=10040){return page.locator('[data-usage-case="'+id+'"]')}
async function openAssessment(page,id=10040){const response=page.waitForResponse(r=>r.url().endsWith('/manager/ai-usage-api.php')&&r.request().postDataJSON().action==='assessment_read');await assessmentCard(page,id).getByRole('button',{name:'Разобрать правку',exact:true}).click();return(await response).json()}
async function fillAssessment(page){const form=assessmentCard(page).locator('.usageAssessmentForm');await form.getByLabel('Вид правки',{exact:true}).selectOption('meaning');await form.getByLabel('Что изменилось',{exact:true}).selectOption('last_question');await form.getByLabel('Польза правки',{exact:true}).selectOption('better');await form.getByLabel('Почему вы так считаете',{exact:true}).fill('Добавлена реакция на вопрос о варианте без перелёта, условия остались неподтверждёнными.');await form.getByLabel('Источник цитаты туриста',{exact:true}).selectOption('1040');await form.getByLabel('Точная цитата из сохранённой реплики',{exact:true}).fill('А без перелёта можно?');return form}
for(const width of [390,430,768,1440])test('AI usage semantic correction and independent owner judgement '+width,async({page,browserName})=>{
  await page.setViewportSize({width,height:1000});const s=await setupAiUsage(page);
  try{
    await expect(page.locator('[data-usage-case]')).toHaveCount(20);expect(s.calls.filter(c=>c.action.startsWith('assessment_'))).toEqual([]);
    const initial=await openAssessment(page);expect(initial).toMatchObject({ok:true,version:0,case_improvement_confirmed:false,snapshot:{can_judge_effect:true,pair_changed:true}});
    const card=assessmentCard(page);await expect(card.locator('.usageAssessment')).toContainText('Возможность оформить только отель ещё нужно проверить.');const form=await fillAssessment(page);await form.getByRole('button',{name:'Сохранить оценку',exact:true}).click();
    await expect(card.locator('.usageAssessmentRecord')).toHaveCount(1);await expect(card.locator('.usageAssessmentRecord')).toContainText('Изменён смысл');await expect(card.locator('.usageAssessment')).not.toContainText('Другой владелец подтвердил');
    await expect(card.locator('.usageAssessmentForm').getByLabel('Действие',{exact:true}).locator('option[value="adjudication"]')).toHaveCount(0);
    expect(s.calls.filter(c=>c.action==='assessment_save')).toHaveLength(1);expect(s.calls.filter(c=>c.action==='review')).toHaveLength(0);
    await s.switchActor(5);await expect(page.locator('[data-usage-case]')).toHaveCount(20);const peer=await openAssessment(page);expect(peer.can_adjudicate).toBe(true);
    const peerForm=assessmentCard(page).locator('.usageAssessmentForm');await peerForm.getByLabel('Действие',{exact:true}).selectOption('adjudication');await peerForm.getByLabel('Результат независимой проверки',{exact:true}).selectOption('confirm');await peerForm.getByLabel('Почему вы так считаете',{exact:true}).fill('Оба последних вопроса видны в сохранённом запросе. Правка отвечает на них, не подтверждая условия продажи.');await peerForm.getByLabel('Источник цитаты туриста',{exact:true}).selectOption('1040');await peerForm.getByLabel('Точная цитата из сохранённой реплики',{exact:true}).fill('после 25 октября.');await peerForm.getByRole('button',{name:'Сохранить оценку',exact:true}).click();
    await expect(assessmentCard(page).locator('.usageAssessmentRecord')).toHaveCount(2);await expect(assessmentCard(page).locator('.usageAssessment')).toContainText('Другой владелец подтвердил пользу этой правки в данном случае. Общий прирост качества ещё не измерен.');
    const geometry=await page.evaluate(()=>({width:innerWidth,document:document.documentElement.scrollWidth}));console.log('ASSESSMENT_GEOMETRY '+JSON.stringify({browser:browserName,...geometry}));await assessmentCard(page).locator('.usageAssessment').scrollIntoViewIfNeeded();await usageCapture(page,browserName,width,'meaning');expect(geometry.document-geometry.width).toBeLessThanOrEqual(1);usageClean(s);
  }finally{await s.cleanup()}
});
test('AI usage assessment preserves unavailable initial or prepared input as unknown',async({page})=>{
  const s=await setupAiUsage(page);try{
    await expect(page.locator('[data-usage-case]')).toHaveCount(20);
    for(const id of [10044,10042,10046,10047]){const r=await openAssessment(page,id);expect(r.snapshot.can_judge_effect).toBe(false);const form=assessmentCard(page,id).locator('.usageAssessmentForm');await expect(form.getByLabel('Польза правки',{exact:true})).toBeDisabled();await expect(form.getByLabel('Польза правки',{exact:true})).toHaveValue('unknown');await expect(form.getByLabel('Польза правки',{exact:true}).locator('option[value="better"]')).toBeDisabled();if(id===10046){expect(r.snapshot.pair_available).toBe(false);await expect(form.getByLabel('Вид правки',{exact:true})).toHaveValue('unassessable')}await assessmentCard(page,id).getByRole('button',{name:'Закрыть разбор',exact:true}).click()}
    expect(s.calls.filter(c=>c.action==='assessment_save')).toHaveLength(0);usageClean(s);
  }finally{await s.cleanup()}
});
test('AI usage lost successful assessment reconciles actual receipt without resending',async({page})=>{
  const s=await setupAiUsage(page);try{
    await expect(page.locator('[data-usage-case]')).toHaveCount(20);await openAssessment(page);const form=await fillAssessment(page),cookies=await page.context().cookies();s.queueAssessment({action:'assessment_save',actual:true,abort:true});await form.getByRole('button',{name:'Сохранить оценку',exact:true}).click();
    await expect(form).toContainText('Результат сохранения неизвестен');await expect(form.getByRole('button',{name:'Сохранить оценку',exact:true})).toBeDisabled();await expect(form.getByLabel('Почему вы так считаете',{exact:true})).toBeDisabled();expect(s.actualResponses).toHaveLength(1);expect(s.actualResponses[0]).toMatchObject({status:200,body:{ok:true,saved_version:1,replayed:false}});expect(await page.context().cookies()).toEqual(cookies);
    await form.getByRole('button',{name:'Проверить сохранение',exact:true}).click();await expect(assessmentCard(page).locator('.usageAssessmentRecord')).toHaveCount(1);expect(s.calls.filter(c=>c.action==='assessment_save')).toHaveLength(1);usageClean(s);
  }finally{await s.cleanup()}
});
test('AI usage unknown uncommitted assessment permits a checked retry only',async({page})=>{
  const s=await setupAiUsage(page);try{
    await expect(page.locator('[data-usage-case]')).toHaveCount(20);await openAssessment(page);const form=await fillAssessment(page);s.queueAssessment({action:'assessment_save',abort:true});await form.getByRole('button',{name:'Сохранить оценку',exact:true}).click();await expect(form).toContainText('Результат сохранения неизвестен');
    await form.getByRole('button',{name:'Проверить сохранение',exact:true}).click();await expect(form).toContainText('Операция не найдена');await expect(form.getByRole('button',{name:'Сохранить оценку',exact:true})).toBeEnabled();await expect(form.getByLabel('Почему вы так считаете',{exact:true})).toHaveValue(/Добавлена реакция/);await form.getByRole('button',{name:'Сохранить оценку',exact:true}).click();await expect(assessmentCard(page).locator('.usageAssessmentRecord')).toHaveCount(1);expect(s.calls.filter(c=>c.action==='assessment_save')).toHaveLength(2);usageClean(s);
  }finally{await s.cleanup()}
});
test('AI usage concurrent owner assessment rejects stale form and preserves its text',async({page})=>{
  const s=await setupAiUsage(page);try{
    await expect(page.locator('[data-usage-case]')).toHaveCount(20);const r=await openAssessment(page),form=await fillAssessment(page);
    const result=await s.postAs(5,{action:'assessment_save',message_id:10040,case_revision:r.case_revision,version:0,comparison_sha256:r.snapshot.comparison_sha256,operation_id:'1234567890abcdef1234567890abcdef',phase:'proposal',target_version:0,data:{kind:'meaning',dimension:'last_question',effect:'unknown',reason:'Ещё не оценена польза',citation:null}});expect(result).toMatchObject({status:200,body:{ok:true,saved_version:1}});
    await form.getByRole('button',{name:'Сохранить оценку',exact:true}).click();await expect(form).toContainText('Другой владелец уже сохранил оценку');await expect(form.getByLabel('Почему вы так считаете',{exact:true})).toHaveValue(/Добавлена реакция/);expect(s.calls.filter(c=>c.action==='assessment_save')).toHaveLength(1);usageClean(s);
  }finally{await s.cleanup()}
});
for(const action of ['close','filter','account'])test('AI usage late assessment cannot restore private data after '+action,async({page})=>{
  const s=await setupAiUsage(page);try{
    await expect(page.locator('[data-usage-case]')).toHaveCount(20);s.queueAssessment({action:'assessment_read',actual:true,hold:true});const response=page.waitForResponse(r=>r.url().endsWith('/manager/ai-usage-api.php')&&r.request().postDataJSON().action==='assessment_read');await assessmentCard(page).getByRole('button',{name:'Разобрать правку',exact:true}).click();await expect.poll(()=>s.heldInputCount()).toBe(1);
    if(action==='close')await assessmentCard(page).getByRole('button',{name:'Закрыть разбор',exact:true}).click();else if(action==='filter')await page.getByLabel('Только с правками').check();else{await s.switchActor(2,false);await page.getByRole('button',{name:'Обновить',exact:true}).click();await expect(page.locator('#usageApp')).toBeHidden()}
    s.releaseInput();await(await response).finished();await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
    if(action==='account'){await expect(assessmentCard(page)).toHaveCount(0)}else{await expect(page.locator('.usageAssessmentForm')).toHaveCount(0);if(action==='close')await expect(assessmentCard(page).locator('.usageAssessment')).toBeEmpty()}usageClean(s);
  }finally{await s.cleanup()}
});
test('AI usage assessment style judgement is inert text and never confirmed useful',async({page})=>{
  const s=await setupAiUsage(page);try{
    await expect(page.locator('[data-usage-case]')).toHaveCount(20);await openAssessment(page);const form=assessmentCard(page).locator('.usageAssessmentForm');await form.getByLabel('Вид правки',{exact:true}).selectOption('style');await expect(form.getByLabel('Польза правки',{exact:true})).toHaveValue('unknown');await expect(form.getByLabel('Польза правки',{exact:true})).toBeDisabled();await form.getByLabel('Почему вы так считаете',{exact:true}).fill('<img src=x onerror="window.assessmentInjected=1">');await form.getByRole('button',{name:'Сохранить оценку',exact:true}).click();await expect(assessmentCard(page).locator('.usageAssessmentRecord')).toContainText('<img src=x');expect(await page.evaluate(()=>window.assessmentInjected)).toBeUndefined();
    await s.switchActor(5);await expect(page.locator('[data-usage-case]')).toHaveCount(20);await openAssessment(page);const peer=assessmentCard(page).locator('.usageAssessmentForm');await peer.getByLabel('Действие',{exact:true}).selectOption('adjudication');await expect(peer.getByLabel('Результат независимой проверки',{exact:true}).locator('option[value="confirm"]')).toBeDisabled();await expect(peer.getByLabel('Результат независимой проверки',{exact:true})).toHaveValue('insufficient_context');usageClean(s);
  }finally{await s.cleanup()}
});
test('AI usage assessment stale message cannot be graded and missing migration keeps journal usable',async({page})=>{
  const s=await setupAiUsage(page);try{
    await expect(page.locator('[data-usage-case]')).toHaveCount(20);await openAssessment(page);const form=await fillAssessment(page);s.mutate("UPDATE messages SET text='A later changed message' WHERE id=10040");await form.getByRole('button',{name:'Сохранить оценку',exact:true}).click();await expect(form).toContainText('Ответ изменился. Обновите журнал');
    await page.getByRole('button',{name:'Обновить',exact:true}).click();await expect(page.locator('[data-usage-case]')).toHaveCount(20);s.mutate('DROP TABLE manager_ai_usage_assessments');await openAssessment(page);await expect(assessmentCard(page).locator('.usageAssessment')).toContainText('Разбор недоступен');await expect(assessmentCard(page).locator('.usageAssessmentForm')).toHaveCount(0);await expect(assessmentCard(page).getByRole('button',{name:'Отметить просмотренным',exact:true})).toBeEnabled();usageClean(s);
  }finally{await s.cleanup()}
});


for(const phase of ['read','save','receipt'])test('AI usage assessment survives pagination during '+phase,async({page})=>{
  const s=await setupAiUsage(page);try{
    await expect(page.locator('[data-usage-case]')).toHaveCount(20);
    if(phase!=='read'){await openAssessment(page);const form=await fillAssessment(page);s.queueAssessment({action:'assessment_save',actual:true,...(phase==='save'?{hold:true}:{abort:true})});await form.getByRole('button',{name:'Сохранить оценку',exact:true}).click();
      if(phase==='receipt'){await expect(form).toContainText('Результат сохранения неизвестен');s.queueAssessment({action:'assessment_read',actual:true,hold:true});await form.getByRole('button',{name:'Проверить сохранение',exact:true}).click()}
    }else{s.queueAssessment({action:'assessment_read',actual:true,hold:true});await assessmentCard(page).getByRole('button',{name:'Разобрать правку',exact:true}).click()}
    await expect.poll(()=>s.heldInputCount()).toBe(1);await page.getByRole('button',{name:'Показать ещё',exact:true}).click();await expect(page.locator('[data-usage-case]')).toHaveCount(40);s.releaseInput();
    if(phase==='read')await expect(assessmentCard(page).locator('.usageAssessmentForm')).toBeVisible();else{await expect(assessmentCard(page).locator('.usageAssessmentRecord')).toHaveCount(1);expect(s.actualResponses.find(r=>r.action==='assessment_save')).toMatchObject({status:200,body:{ok:true,saved_version:1}});expect(s.calls.filter(c=>c.action==='assessment_save')).toHaveLength(1)}
    await expect(assessmentCard(page).locator('.usageAssessmentForm').getByRole('button',{name:'Сохранить оценку',exact:true})).toBeEnabled();await assessmentCard(page).getByRole('button',{name:'Закрыть разбор',exact:true}).click();await expect(assessmentCard(page).locator('.usageAssessment')).toBeHidden();usageClean(s);
  }finally{await s.cleanup()}
});
// AI_USAGE_CASES_END

// The fixture renders the real Manager2 PHP shell and production assets. These
// extra routes are local synthetic assistant reads/decisions, never live data.
async function setupAssistant(page,{owner=false}={}){
  const base=await setup(page,{role:owner?'admin':'manager'});
  const calls=[],detailResponses=[],heldDetails=[];
  const recommendation=(changes={})=>({revision:'a'.repeat(64),next_action:'check_offer',reason:'Клиент выбрал два отеля. Нужно сравнить питание и вылет из СПб.',checks:['Сравнить выбранные отели на двоих.','Проверить тарифы с завтраками и полную стоимость.'],prepared_at:new Date().toISOString(),expires_at:new Date(Date.now()+3600000).toISOString(),latest_message_id:35,evidence_message_ids:[35],history_incomplete:false,decision:null,...changes});
  const items=new Map([
    [11,{conversation_id:11,display_name:'Свадебное путешествие',channel:'telegram',last_message_at:new Date().toISOString(),tasks:[],recommendation:recommendation()}],
    [12,{conversation_id:12,display_name:'Клиент выбирает отель',channel:'telegram',last_message_at:new Date().toISOString(),tasks:[],recommendation:recommendation({next_action:'no_contact',reason:'Клиент обсуждает варианты. Сейчас отвечать не нужно.',checks:['Дождаться обратной связи по подборке.']})}],
    [901,{conversation_id:901,display_name:'Ранний клиент с задачей',channel:'max',last_message_at:'2026-09-01T10:00:00Z',tasks:[{id:31,title:'Проверить ответ поставщика',status:'open',due_state:'overdue',due_at_utc:'2026-10-19T09:00:00Z'}],recommendation:null}]
  ]);
  if(owner)for(const [id,item] of items){item.project_name=id===12?'Pegas':'AnyTour';item.manager_id=id===12?8:9;item.manager_name=id===12?'Анна':'Светлана';item.can_decide=false;}
  await page.route('**/manager/ai-draft.php',route=>route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,prepared:false})}));
  await page.route('**/manager/assistant-api.php',async route=>{
    const d=route.request().postDataJSON();calls.push(d);expect(d.csrf).toBe('fixture-csrf');
    let result,status=200,raw;
    if(d.action==='list')result=d.cursor?{ok:true,items:[items.get(901)],has_more:false,next_cursor:null}:{ok:true,items:[items.get(11),items.get(12)],has_more:true,next_cursor:'v1.'+'a'.repeat(64)+'.12'};
    else if(d.action==='detail'){
      const next=detailResponses.shift();
      result=next&&Object.prototype.hasOwnProperty.call(next,'result')?next.result:{ok:true,item:items.get(Number(d.conversation_id))};
      status=next?.status||200;raw=next?.raw;
      // Capture the original response before holding it: a later client/account
      // switch must not accidentally turn a stale response into fresh fixture data.
      result=JSON.parse(JSON.stringify(result));
      if(next?.hold)await new Promise(resolve=>heldDetails.push(resolve));
    }
    else if(d.action==='decision'){const item=items.get(Number(d.conversation_id));expect(d.revision).toBe(item.recommendation.revision);expect(d.expected_decision).toBe(item.recommendation.decision);item.recommendation={...item.recommendation,decision:d.decision};result={ok:true};}
    else throw Error('Unexpected assistant fixture action '+d.action);
    return route.fulfill({status,contentType:'application/json',body:raw===undefined?JSON.stringify(result):raw});
  });
  await expect.poll(()=>page.evaluate(()=>WorkspaceV2.S.workspaceBound&&!WorkspaceV2.S.booting)).toBe(true);
  return{base,calls,items,queueDetail:(...responses)=>detailResponses.push(...responses),heldDetailCount:()=>heldDetails.length,releaseDetail:()=>heldDetails.shift()?.()};
}
function clean(s){expect(s.base.errors).toEqual([]);expect(s.base.unexpected).toEqual([]);expect(s.base.calls.filter(c=>['send','take','release','close','reopen','create_task','update_task','set_task_completed'].includes(c.action))).toEqual([]);expect(s.calls.filter(c=>!['list','detail','decision'].includes(c.action))).toEqual([])}
async function bounded(page){
  expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);
  const box=await page.locator('#assistantDialog').boundingBox(),v=page.viewportSize();expect(box.x).toBeGreaterThanOrEqual(-1);expect(box.x+box.width).toBeLessThanOrEqual(v.width+1);expect(box.y).toBeGreaterThanOrEqual(-1);expect(box.y+box.height).toBeLessThanOrEqual(v.height+1);await expect(page.locator('#assistantClose')).toBeInViewport();
}
async function capture(page,browser,width,label){const directory=path.join(root,'manager2-artifacts');fs.mkdirSync(directory,{recursive:true});await page.screenshot({animations:'disabled',path:path.join(directory,`assistant-${browser}-${width}-${label}.png`)})}
async function openGlobalAssistant(page){
  const entry=page.locator('#assistantOpen');
  if(page.viewportSize().width<=740&&!await page.locator('#m2MoreMenu').isVisible())await page.locator('#m2MoreToggle').click();
  await expect(entry).toBeVisible();await entry.click();
}

for(const width of [390,430,768,1440])test('Manager assistant unifies list and current-client advice without touching reply '+width,async({page,browserName})=>{
  await page.setViewportSize({width,height:900});const s=await setupAssistant(page);expect(s.calls).toEqual([]);
  await openGlobalAssistant(page);await expect(page.locator('[data-assistant-client]')).toHaveCount(2);expect(s.calls.map(c=>c.action)).toEqual(['list']);await bounded(page);await capture(page,browserName,width,'list');
  await page.locator('#assistantMore').click();await expect(page.locator('[data-assistant-client]')).toHaveCount(3);await expect(page.locator('#assistantMore')).toBeHidden();expect(s.calls.at(-1).cursor).toBe('v1.'+'a'.repeat(64)+'.12');
await page.locator('[data-assistant-client="11"]').click();await expect(page.locator('#assistantDetail')).toContainText('Сравнить выбранные отели на двоих.');await expect(page.locator('#assistantPlan')).toBeVisible();if(width<768)await expect(page.locator('.assistantToolbar')).toBeHidden();await page.locator('.assistantSource summary').click();await expect(page.locator('.assistantSource')).toContainText('Сообщений в основании: 1');await bounded(page);await capture(page,browserName,width,'client');
  await page.locator('[data-assistant-decision="done"]').click();await expect(page.locator('#assistantDetail')).toContainText('Отмечено выполненным');expect(s.calls.filter(c=>c.action==='decision')).toHaveLength(1);
  if(width<768)await page.locator('#assistantBack').click();await page.locator('[data-assistant-client="12"]').click();await expect(page.locator('#assistantDetail')).toContainText('Дождаться клиента');await expect(page.locator('#assistantPlan')).toHaveCount(0);await expect(page.locator('[data-assistant-decision]')).toHaveCount(0);
await page.setViewportSize({width,height:420});await bounded(page);await capture(page,browserName,width,'short');await page.locator('#assistantClose').click();if(width>=768)expect(await page.evaluate(()=>document.documentElement.scrollHeight)).toBeLessThanOrEqual(421);
  await page.setViewportSize({width,height:900});await page.evaluate(()=>WorkspaceV2Conversation.open(11));await expect(page.locator('#replyText')).toBeEnabled();
  await page.locator('#replyText').fill('Мой незаконченный ответ');await page.locator('#replyFile').setInputFiles({name:'assistant-keep.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic retained attachment')});
  const draft=await page.locator('#replyText').inputValue(),selection=await page.locator('#mediaSelection').textContent();
await expect(page.locator('.conversationIdentityText #assistantClientOpen')).toHaveCount(0);await page.locator('#m2ConversationMenuToggle').click();await page.locator('#assistantClientOpen').click();await expect(page.locator('#assistantDetail')).toContainText('Отмечено выполненным');expect(s.calls.filter(c=>c.action==='decision')).toHaveLength(1);await page.locator('#assistantClose').click();
  await expect(page.locator('#replyText')).toHaveValue(draft);await expect(page.locator('#mediaSelection')).toHaveText(selection);
  clean(s);
});

test('assistant does not present expired reasoning or a missing analysis as completed work',async({page})=>{
  const s=await setupAssistant(page);s.items.get(11).recommendation.expires_at=new Date(Date.now()-1000).toISOString();await openGlobalAssistant(page);await page.locator('[data-assistant-client="11"]').click();await expect(page.locator('#assistantDetail')).toContainText('Нет свежего разбора');await expect(page.locator('#assistantDetail')).toContainText('Это не означает');await expect(page.locator('#assistantDetail')).not.toContainText('Клиент выбрал два отеля');await expect(page.locator('[data-assistant-decision]')).toHaveCount(0);expect(s.calls.every(c=>['list','detail'].includes(c.action))).toBe(true);clean(s);
});

async function selectAssistantClient(page,id=11){
  await openGlobalAssistant(page);
  await page.locator(`[data-assistant-client="${id}"]`).click();
  await expect(page.locator('#assistantDetail')).toContainText(id===11?'Сравнить выбранные отели на двоих.':'Дождаться клиента');
}
function detailCalls(s){return s.calls.filter(call=>call.action==='detail')}
async function periodicDetail(page,s){
  const before=detailCalls(s).length;
  const response=page.waitForResponse(response=>response.url().endsWith('/manager/assistant-api.php')&&response.request().postDataJSON().action==='detail');
  await page.clock.runFor(20001);
  await expect.poll(()=>detailCalls(s).length).toBe(before+1);
  await (await response).finished();
}
async function rememberReadingState(page){
  await page.locator('.assistantSource summary').click();
  await expect(page.locator('.assistantSource')).toHaveAttribute('open','');
  return page.evaluate(()=>{
    const source=document.querySelector('.assistantSource'),control=source.querySelector('summary'),body=document.querySelector('.assistantBody'),text=source.querySelector('p').firstChild;
    // Native details, keyboard focus, a real Range and the actual scroll host.
    // Keep node references so restoring a lookalike DOM cannot satisfy this check.
    control.focus({preventScroll:true});
    const range=document.createRange();range.setStart(text,0);range.setEnd(text,Math.min(18,text.length));
    const selection=getSelection();selection.removeAllRanges();selection.addRange(range);
    body.scrollTop=Math.max(1,Math.min(body.scrollHeight-body.clientHeight,130));
    window.assistantReadingProof={source,control,article:source.closest('article'),client:document.querySelector('[data-assistant-client="11"]'),body,top:body.scrollTop,text:selection.toString(),anchor:selection.anchorNode,offset:selection.anchorOffset};
    return {top:body.scrollTop,text:selection.toString()};
  });
}
async function expectReadingState(page){
  await expect.poll(()=>page.evaluate(()=>{
    const p=window.assistantReadingProof,s=getSelection();
    return {source:p.source===document.querySelector('.assistantSource')&&p.source.isConnected,article:p.article===document.querySelector('.assistantRecommendation'),client:p.client===document.querySelector('[data-assistant-client="11"]'),expanded:p.source.open,focus:document.activeElement===p.control&&p.control.isConnected,scroll:Math.abs(p.body.scrollTop-p.top)<=1,selection:s.toString()===p.text&&s.anchorNode===p.anchor&&s.anchorOffset===p.offset};
  })).toEqual({source:true,article:true,client:true,expanded:true,focus:true,scroll:true,selection:true});
}

for(const viewport of [{width:390,height:900},{width:430,height:900},{width:768,height:900},{width:1440,height:900},{width:390,height:420}])test(`unchanged periodic assistant refresh preserves native reading state ${viewport.width}x${viewport.height}`,async({page})=>{
  await page.setViewportSize(viewport);const s=await setupAssistant(page);await page.clock.install();
  s.items.get(11).recommendation.checks.push(...Array.from({length:16},(_,i)=>`Пункт проверки ${i+1}: сопоставить условия выбранного варианта по полному запросу клиента.`));
  await selectAssistantClient(page);const reading=await rememberReadingState(page);expect(reading.top).toBeGreaterThan(0);expect(reading.text).not.toBe('');
  await periodicDetail(page,s);await expectReadingState(page);
  // A slow identical response must preserve the same state while pending too.
  s.queueDetail({hold:true});const before=detailCalls(s).length;await page.clock.runFor(20001);await expect.poll(()=>s.heldDetailCount()).toBe(1);expect(detailCalls(s)).toHaveLength(before+1);await expectReadingState(page);
  const response=page.waitForResponse(response=>response.url().endsWith('/manager/assistant-api.php')&&response.request().postDataJSON().action==='detail');s.releaseDetail();await (await response).finished();await expectReadingState(page);await bounded(page);clean(s);
});

test('periodic assistant refresh invalidates changed expired denied and failed reasoning',async({page})=>{
  await page.setViewportSize({width:1440,height:900});const s=await setupAssistant(page);await page.clock.install();
  const original=JSON.parse(JSON.stringify(s.items.get(11).recommendation));
  for(const outcome of ['changed','expired','revoked','server-error','empty-response','unauthorized'])await test.step(outcome,async()=>{
  if(await page.locator('#assistantDialog').isVisible())await page.locator('#assistantClose').click();
  s.items.get(11).recommendation=JSON.parse(JSON.stringify(original));
  await selectAssistantClient(page);await page.locator('.assistantSource summary').click();
  const oldReason=s.items.get(11).recommendation.reason;
  if(outcome==='changed')s.items.get(11).recommendation={...s.items.get(11).recommendation,revision:'b'.repeat(64),reason:'Новый запрос: проверить только выбранный тариф с завтраками.',latest_message_id:36,evidence_message_ids:[36]};
  else if(outcome==='expired')s.items.get(11).recommendation.expires_at=new Date(Date.now()-1000).toISOString();
  else if(outcome==='revoked')s.queueDetail({status:403,result:{ok:false,error:'forbidden'}});
  else if(outcome==='server-error')s.queueDetail({status:500,result:{ok:false,error:'assistant_unavailable'}});
  else if(outcome==='empty-response')s.queueDetail({raw:''});
  else s.queueDetail({status:401,result:{ok:false,error:'unauthorized'}});
  await periodicDetail(page,s);await expect(page.locator('#assistantDetail')).not.toContainText(oldReason);
  if(outcome==='changed')await expect(page.locator('#assistantDetail')).toContainText('Новый запрос: проверить только выбранный тариф с завтраками.');
  else if(outcome==='expired'){await expect(page.locator('#assistantDetail')).toContainText('Нет свежего разбора');await expect(page.locator('[data-assistant-decision]')).toHaveCount(0)}
  else if(outcome==='unauthorized'){await expect(page.locator('#assistantDialog')).not.toBeVisible();await expect(page.locator('#managerAuthRecovery')).toBeVisible()}
  else{await expect(page.locator('#assistantStatus')).toHaveClass(/isError/);await expect(page.locator('[data-assistant-decision]')).toHaveCount(0)}
  });clean(s);
});

test('late periodic assistant reads cannot steal client navigation focus or account context',async({page})=>{
  const s=await setupAssistant(page);await page.clock.install();
  for(const destination of ['other-client','back','close','other-account'])await test.step(destination,async()=>{
  if(await page.locator('#assistantDialog').isVisible())await page.locator('#assistantClose').click();
  const mobile=destination==='back';await page.setViewportSize({width:mobile?390:1440,height:mobile?420:900});await selectAssistantClient(page);
  s.queueDetail({hold:true,result:{ok:true,item:{...s.items.get(11),recommendation:{...s.items.get(11).recommendation,reason:'Устаревший ответ от предыдущего запроса.'}}}});
  await page.clock.runFor(20001);await expect.poll(()=>s.heldDetailCount()).toBe(1);
  if(destination==='other-client'){await page.locator('[data-assistant-client="12"]').click();await expect(page.locator('#assistantDetail')).toContainText('Дождаться клиента')}
  else if(destination==='back')await page.locator('#assistantBack').click();
  else if(destination==='close')await page.locator('#assistantClose').click();
  else{
    await page.evaluate(()=>WorkspaceV2.showAuthRecovery());s.base.switchManager({id:8,role:'manager',display_name:'Другой менеджер',is_working:true});
    await page.locator('#managerAuthLogin').fill('isolated-account');await page.locator('#managerAuthPassword').fill('isolated-password');await page.locator('#managerAuthSubmit').click();await expect(page.locator('#managerAuthRecovery')).toBeHidden();
    await openGlobalAssistant(page);await page.locator('[data-assistant-client="12"]').click();await expect(page.locator('#assistantDetail')).toContainText('Дождаться клиента');
  }
  // Cached client text renders before its protected detail read completes. Wait
  // for that navigation's intentional focus handoff before releasing older work.
  if(destination==='other-client'||destination==='other-account')await expect(page.locator('#assistantConversation')).toBeFocused();
  const focused=await page.evaluate(()=>{window.assistantNavigationFocus=document.activeElement;return{activeId:document.activeElement.id,selectedClient:document.querySelector('.assistantClient.isSelected')?.dataset.assistantClient||null}});
  const response=page.waitForResponse(response=>response.url().endsWith('/manager/assistant-api.php')&&response.request().postDataJSON().action==='detail'&&Number(response.request().postDataJSON().conversation_id)===11);s.releaseDetail();await (await response).finished();
  await expect(page.locator('#assistantDetail')).not.toContainText('Устаревший ответ от предыдущего запроса.');
  await expect.poll(()=>page.evaluate(()=>({sameControl:document.activeElement===window.assistantNavigationFocus,connected:window.assistantNavigationFocus.isConnected,activeId:document.activeElement.id,selectedClient:document.querySelector('.assistantClient.isSelected')?.dataset.assistantClient||null}))).toEqual({sameControl:true,connected:true,...focused});
  if(destination==='back'){await expect(page.locator('#assistantDialog')).not.toHaveClass(/hasSelection/);await expect(page.locator('[data-assistant-client="11"]')).toBeFocused()}
  else if(destination==='close'){await expect(page.locator('#assistantDialog')).not.toBeVisible();await expect(page.locator(page.viewportSize().width<=740?'#m2MoreToggle':'#assistantOpen')).toBeFocused()}
  else{await expect(page.locator('#assistantDetail')).toContainText('Дождаться клиента');await expect(page.locator('#assistantDetail h3')).toHaveText('Клиент выбирает отель')}
  });clean(s);
});

for(const viewport of [{width:390,height:844},{width:430,height:844},{width:768,height:844},{width:1440,height:844},{width:390,height:420}])test(`assistant operational priority without AI and changed-order recovery ${viewport.width}x${viewport.height}`,async({page,browserName})=>{
  await page.setViewportSize(viewport);const s=await setupAssistant(page);
  const own={...s.items.get(11),recommendation:null,operational:{awaiting_manager_reply:true,due_task:false}};
  const due={...s.items.get(12),recommendation:null,operational:{awaiting_manager_reply:false,due_task:true},tasks:[{id:31,title:'Проверить ответ поставщика',status:'open',due_state:'overdue',due_at_utc:'2026-01-01 10:00:00'}]};
  s.items.clear();s.items.set(11,own);s.items.set(12,due);for(let id=100;id<120;id++)s.items.set(id,{...own,conversation_id:id,display_name:'Клиент '+id,operational:{awaiting_manager_reply:false,due_task:false}});
  let changed=false,restarted=false;
  await page.route('**/manager/assistant-api.php',async route=>{
    const d=route.request().postDataJSON();s.calls.push(d);expect(d.csrf).toBe('fixture-csrf');let result,status=200;
    if(d.action==='detail')result={ok:true,item:s.items.get(Number(d.conversation_id))};
    else if(d.action==='list'){
      if(d.cursor&&!changed){changed=true;s.items.get(11).operational.awaiting_manager_reply=false;result={ok:false,error:'list_changed'};status=409}
      else{
        if(changed&&!d.cursor)restarted=true;
        const all=[...s.items.values()].sort((a,b)=>Number(b.operational.awaiting_manager_reply)-Number(a.operational.awaiting_manager_reply)||Number(b.operational.due_task)-Number(a.operational.due_task)||b.conversation_id-a.conversation_id);
        const offset=d.cursor?all.findIndex(i=>i.conversation_id===Number(d.cursor.split('.').at(-1)))+1:0,items=all.slice(offset,offset+20),more=offset+items.length<all.length;
        result={ok:true,items,has_more:more,next_cursor:more?'v1.'+(changed?'b':'a').repeat(64)+'.'+items.at(-1).conversation_id:null};
      }
    }else throw Error('Unexpected write '+d.action);
    return route.fulfill({status,contentType:'application/json',body:JSON.stringify(result)});
  });
  await page.evaluate(()=>WorkspaceV2Conversation.open(11));await page.locator('#replyText').fill('Мой ответ остаётся в черновике');await page.locator('#replyFile').setInputFiles({name:'retained.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic retained file')});
  const selection=await page.locator('#mediaSelection').textContent();
  await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
  await page.locator('#messages').hover();const beforeWheel=await page.locator('#messages').evaluate(box=>box.scrollTop);
  await page.mouse.wheel(0,-250);
  // WebKit marks reading intent before its native wheel animation finishes.
  // Capture the baseline only after the requested real scroll has completed.
  await expect.poll(()=>page.locator('#messages').evaluate(box=>box.scrollTop)).toBe(Math.max(0,beforeWheel-250));
  await expect.poll(()=>page.evaluate(()=>WorkspaceV2Jump.isFollowingLatest())).toBe(false);
  await page.evaluate(()=>{const host=document.querySelector('#messages');window.priorityReading={node:host.querySelector('[data-scroll-message-key]'),host,top:host.scrollTop}});
  if(viewport.width<=740)await page.locator('#mobileBack').click();
  await openGlobalAssistant(page);await expect(page.locator('[data-assistant-client]')).toHaveCount(20);await expect(page.locator('[data-assistant-client="11"]')).toBeVisible();await expect(page.locator('[data-assistant-client="12"]')).toBeVisible();await page.locator('[data-assistant-filter="actions"]').click();await expect(page.locator('[data-assistant-client]')).toHaveCount(2);
  await bounded(page);await capture(page,browserName,viewport.width,`operational-list-${viewport.height}`);
  await page.locator('[data-assistant-client="11"]').click();await expect(page.locator('.assistantOperational')).toContainText('Обращение без записанного ответа');await expect(page.locator('#assistantDetail')).toContainText('Нет свежего разбора');await expect(page.locator('#assistantPlan')).toHaveCount(0);await bounded(page);await capture(page,browserName,viewport.width,`operational-detail-${viewport.height}`);
  await page.locator('#assistantConversation').click();await expect(page.locator('#replyText')).toHaveValue('Мой ответ остаётся в черновике');await expect(page.locator('#mediaSelection')).toHaveText(selection);expect(await page.evaluate(()=>({host:priorityReading.host===document.querySelector('#messages'),scroll:Math.round(priorityReading.host.scrollTop-priorityReading.top),node:!!priorityReading.node?.isConnected}))).toEqual({host:true,scroll:0,node:true});
  if(viewport.width<=740)await page.locator('#mobileBack').click();
  await openGlobalAssistant(page);await page.locator('[data-assistant-filter="all"]').click();await page.locator('#assistantMore').click();await expect.poll(()=>restarted).toBe(true);await expect(page.locator('[data-assistant-client]')).toHaveCount(20);await expect(page.locator('[data-assistant-client="11"]')).toHaveCount(0);await expect(page.locator('[data-assistant-client="12"]')).toBeVisible();
  expect(s.calls.filter(c=>c.action==='list'&&c.cursor)).toHaveLength(1);await page.locator('#assistantMore').click();await expect(page.locator('[data-assistant-client]')).toHaveCount(22);await expect(page.locator('#assistantMore')).toBeHidden();await page.locator('[data-assistant-filter="actions"]').click();await expect(page.locator('[data-assistant-client]')).toHaveCount(1);await expect(page.locator('[data-assistant-client="12"]')).toBeVisible();
  await page.locator('#assistantClose').click();await expect(page.locator('#replyText')).toHaveValue('Мой ответ остаётся в черновике');await expect(page.locator('#mediaSelection')).toHaveText(selection);clean(s);
});

for(const viewport of [{width:390,height:900},{width:430,height:900},{width:768,height:900},{width:1440,height:900},{width:390,height:420}])test('Owner Assistant shows all managers and preserves own draft '+viewport.width+'x'+viewport.height,async({page,browserName})=>{
  await page.setViewportSize(viewport);const s=await setupAssistant(page,{owner:true});await page.evaluate(()=>WorkspaceV2Conversation.open(11));await page.locator('#replyText').fill('Сохранить мой незаконченный ответ');await page.locator('#replyFile').setInputFiles({name:'owner-kept.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic retained attachment')});
  if(viewport.width<768){await page.getByRole('button',{name:'Назад к диалогам',exact:true}).click();await expect(page.locator('#inboxList')).toBeVisible();}
  await openGlobalAssistant(page);await expect(page.locator('#assistantTitle')).toHaveText('Помощник владельца');await expect(page.locator('#assistantSubtitle')).toContainText('всей команды');await expect(page.locator('[data-assistant-client="11"]')).toContainText('Светлана');await expect(page.locator('[data-assistant-client="12"]')).toContainText('Анна');await expect(page.locator('[data-assistant-client="12"]')).toContainText('Pegas');await bounded(page);await capture(page,browserName,viewport.width,'owner-list-'+viewport.height);
  await page.locator('[data-assistant-client="11"]').click();await expect(page.locator('#assistantDetail')).toContainText('Светлана');await expect(page.locator('.assistantRecommendation')).toBeVisible();await expect(page.locator('[data-assistant-decision]')).toHaveCount(0);await bounded(page);await capture(page,browserName,viewport.width,'owner-detail-'+viewport.height);await page.locator('#assistantClose').click();await expect(page.locator('#replyText')).toHaveValue('Сохранить мой незаконченный ответ');await expect(page.locator('#mediaSelection')).toContainText('owner-kept.txt');clean(s);
});
