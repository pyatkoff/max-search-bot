const {test,expect}=require('@playwright/test');
const fs=require('node:fs'),path=require('node:path');
const {execFileSync}=require('node:child_process');
const {setup,root}=require('./fixtures/internal-notes');
const copy=x=>JSON.parse(JSON.stringify(x));
async function fixture(page,options={}){
  const base=await setup(page),calls=[],drafts=new Map(),receipts=new Map();let fail=options.fail||'',held=null;
  const snapshot=id=>({conversation_id:id,version:drafts.get(id)?.version||0,draft:copy(drafts.get(id)?.draft||{title:'Подборка туров',intro:'',offers:[]}),can_edit:!options.readOnly,updated_at:null,updated_by_manager_id:7});
  if(options.draft)drafts.set(11,{version:1,draft:copy(options.draft)});
  let publication=null,creations=0;const heldPhotos=[];
  await page.route(/\/manager\/tour-selection-photo\.php\?/,async route=>{
    if(options.holdPhotos)await new Promise(resolve=>heldPhotos.push(resolve));
    const headers={'Cache-Control':'private, no-store'};
    return options.photoError?route.fulfill({status:404,headers,body:''}):route.fulfill({contentType:'image/jpeg',headers,body:fs.readFileSync(path.join(root,'assets/owner-tour-cards/compact-v1',(['cover','beach','room'][Number(new URL(route.request().url()).searchParams.get('photo'))]||'cover')+'.jpg'))});
  });
  await page.route('**/manager/tour-selections-api.php',async route=>{
    const d=route.request().postDataJSON();calls.push(copy(d));expect(d.csrf).toBe('fixture-csrf');expect([11,12]).toContain(d.conversation_id);
    const reply=(body,status=200)=>route.fulfill({status,contentType:'application/json',body:JSON.stringify(body)});
    if(d.action==='get')return reply({ok:true,selection:snapshot(d.conversation_id)});
    if(d.action==='operation')return reply({ok:true,found:receipts.has(d.operation_id),saved_version:receipts.get(d.operation_id)?.version??null,selection:snapshot(d.conversation_id)});
    if(d.action==='prepare'){
      const built=JSON.parse(execFileSync('php',['-r',`require getcwd().'/services/ManagerTourSelectionPublicationDraft.php'; $draft=json_decode(stream_get_contents(STDIN),true); $result=ManagerTourSelectionPublicationDraft::build($draft); if ($result['ok']) foreach ($result['offers'] as &$offer) $offer['card']=TourOfferCardRenderer::render($offer['snapshot'],$offer['presentation'],'tour_like_1_'.str_repeat('a',24)); echo json_encode($result,JSON_THROW_ON_ERROR);`],{cwd:root,input:JSON.stringify(snapshot(d.conversation_id).draft),encoding:'utf8'}));
      if(!built.ok)return reply(built,422);
      publication=publication||{id:'a'.repeat(32),draft_version:d.version,title:built.title,channel:'telegram',can_send:!options.readOnly,cards:built.offers.map((offer,i)=>({index:i,offer_id:i+1,state:'pending',snapshot:offer.snapshot,text:offer.card.plain_text,photos:[0,1,2].map(p=>'tour-selection-photo.php?publication_id='+'a'.repeat(32)+'&card='+i+'&photo='+p+'&conversation_id='+d.conversation_id)}))};
      return reply({ok:true,publication:copy(publication)});
    }
    if(d.action==='history')return reply({ok:true,publications:publication?[{publication_id:publication.id,draft_version:1,created_at:'2026-10-22 12:00:00'}]:[]});
    if(d.action==='publication')return reply({ok:true,publication:copy(publication)});
    if(d.action==='send'){
      expect(d.publication_id).toBe(publication.id);const card=publication.cards[d.card_index];
      if(card.state==='pending'){creations++;card.state=options.sendMode==='unknown'?'unknown':'delivered'}
      if(options.sendMode==='unknown')return reply({ok:false,error:'delivery_unknown',publication:copy(publication)},422);
      if(options.sendMode==='after'){options.sendMode='';return route.abort('failed')}
      return reply({ok:true,publication:copy(publication)});
    }
    expect(d.action).toBe('save');
    if(options.readOnly)return reply({ok:false,error:'forbidden'},403);
    if(fail==='before'){fail='';return route.abort('failed')}
    if(!receipts.has(d.operation_id)){
      if(d.version!==snapshot(d.conversation_id).version)return reply({ok:false,error:'conflict',selection:snapshot(d.conversation_id)},409);
      const value={draft:copy(d.draft),version:d.version+1};drafts.set(d.conversation_id,value);receipts.set(d.operation_id,copy(value));
    }
    if(fail==='after'){fail='';return route.abort('failed')}
    if(fail==='hold'){fail='';await new Promise(resolve=>held=resolve)}
    return reply({ok:true,saved_version:receipts.get(d.operation_id).version,selection:snapshot(d.conversation_id)});
  });
  await expect.poll(()=>page.evaluate(()=>!!WorkspaceV2.S.workspaceBound&&!WorkspaceV2.S.booting)).toBe(true);
  await page.locator('#inboxList [data-conversation-id="11"]').click();await expect(page.locator('#tourSelectionOpen')).toBeAttached();
  return {...base,calls,drafts,receipts,snapshot,creations:()=>creations,publication:()=>publication,heldPhotos:()=>heldPhotos.length,holdPhotos:()=>{options.holdPhotos=true},releasePhotos:()=>{options.holdPhotos=false;heldPhotos.splice(0).forEach(resolve=>resolve())},release:()=>held?.(),setFail:x=>fail=x};
}
async function open(page){if(!await page.locator('#tourSelectionOpen').isVisible())await page.locator('#m2ConversationMenuToggle').click();await page.locator('#tourSelectionOpen').click();await expect(page.locator('#tourSelectionDialog')).toBeVisible();await expect(page.locator('#tsStatus')).toContainText(/загружен|Просмотр|Восстановлены/)}
async function save(page){await page.locator('#tsSave').click();await expect(page.locator('#tsSaved')).toHaveText('Сохранено на сервере')}
function clean(s,extra=[]){expect(s.unexpected).toEqual([]);expect(s.errors).toEqual([]);expect(s.calls.filter(x=>!['get','save','operation',...extra].includes(x.action))).toEqual([]);expect(s.pending.size).toBeLessThanOrEqual(2)}

for(const width of [390,430,768,1440])test('selection editor preserves order conditions and reply at '+width,async({page,browserName})=>{
  await page.setViewportSize({width,height:900});const s=await fixture(page);expect(s.calls).toEqual([]);
  await expect(page.locator('#conversationTitle')).toBeVisible();
  await page.locator('#replyText').fill('Ответ клиенту остаётся в переписке');
  await open(page);await page.locator('#tsEmptyAdd').click();await page.locator('#ts-hotel').fill('Тихий отель у моря');await page.locator('#ts-amount').fill('240000');await page.locator('#ts-adults').fill('2');await page.locator('#ts-child_ages').fill('0, 4, 4');await save(page);
  await page.locator('#tsAdd').click();await page.locator('#ts-hotel').fill('Семейный вариант');await page.locator('[data-ts-action="up"]').click();await save(page);
  expect(s.drafts.get(11).draft.offers.map(x=>x.hotel)).toEqual(['Семейный вариант','Тихий отель у моря']);
  await page.locator('[data-ts-field="selected"]').uncheck();await save(page);expect(s.drafts.get(11).draft.offers[0].selected).toBe(false);
  await page.locator('[data-ts-select]').nth(1).click();await expect(page.locator('#ts-child_ages')).toHaveValue('0, 4, 4');
  expect(await page.evaluate(()=>document.querySelector('#tourSelectionDialog').scrollWidth-document.querySelector('#tourSelectionDialog').clientWidth)).toBeLessThanOrEqual(1);
  await page.setViewportSize({width,height:420});await expect(page.locator('#tsSave')).toBeInViewport();await expect(page.locator('#tsClose')).toBeInViewport();await page.setViewportSize({width,height:900});
  fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({animations:'disabled',path:path.join(root,'manager2-artifacts',`tour-selection-${browserName}-${width}.png`)});
  await page.locator('#tsClose').click();await expect(page.locator('#replyText')).toHaveValue('Ответ клиенту остаётся в переписке');
  await page.reload();await expect.poll(()=>page.evaluate(()=>!!WorkspaceV2.S.workspaceBound&&!WorkspaceV2.S.booting)).toBe(true);if(!await page.locator('#tourSelectionOpen').count())await page.locator('#inboxList [data-conversation-id="11"]').click();await open(page);await expect(page.locator('[data-ts-select]')).toHaveCount(2);clean(s);
});

test('selection conflict keeps local conditions until explicit choice',async({page})=>{
  const s=await fixture(page);await open(page);await page.locator('#tsEmptyAdd').click();await page.locator('#ts-hotel').fill('Мой вариант');await save(page);
  const other=copy(s.drafts.get(11));other.version++;other.draft.offers[0].hotel='Вариант коллеги';s.drafts.set(11,other);
  await page.locator('#ts-hotel').fill('Моя новая версия');await page.locator('#tsSave').click();await expect(page.locator('#tsRecovery')).toContainText('другая версия');await expect(page.locator('#ts-hotel')).toHaveValue('Моя новая версия');
  await page.getByRole('button',{name:'Продолжить с моими правками'}).click();await save(page);expect(s.drafts.get(11).draft.offers[0].hotel).toBe('Моя новая версия');clean(s);
});

test('lost save response reconciles without another mutation',async({page})=>{
  const s=await fixture(page,{fail:'after'});await open(page);await page.locator('#tsEmptyAdd').click();await page.locator('#ts-hotel').fill('Сохранённый вариант');await page.locator('#tsSave').click();await expect(page.getByRole('button',{name:'Проверить сохранение'})).toBeVisible();
  await page.getByRole('button',{name:'Проверить сохранение'}).click();await expect(page.locator('#tsSaved')).toHaveText('Сохранено на сервере');expect(s.calls.filter(x=>x.action==='save')).toHaveLength(1);clean(s);
});

test('known missing receipt retries only the identical draft command',async({page})=>{
  const s=await fixture(page,{fail:'before'});await open(page);await page.locator('#tsEmptyAdd').click();await page.locator('#ts-hotel').fill('Непринятый запрос');await page.locator('#tsSave').click();await page.getByRole('button',{name:'Проверить сохранение'}).click();await page.getByRole('button',{name:'Повторить это сохранение'}).click();await expect(page.locator('#tsSaved')).toHaveText('Сохранено на сервере');
  const writes=s.calls.filter(x=>x.action==='save');expect(writes).toHaveLength(2);expect(writes[1]).toEqual(writes[0]);clean(s);
});

test('invalid children input survives close and blocks stale autosave',async({page})=>{
  const s=await fixture(page);await open(page);await page.locator('#tsEmptyAdd').click();await page.locator('#ts-child_ages').fill('4, возраст');await expect(page.locator('#tsSave')).toBeDisabled();await page.waitForTimeout(1100);expect(s.calls.filter(x=>x.action==='save')).toHaveLength(0);
  await page.locator('#tsClose').click();await open(page);await expect(page.locator('#ts-child_ages')).toHaveValue('4, возраст');await page.locator('#ts-child_ages').fill('4, 10');await save(page);expect(s.drafts.get(11).draft.offers[0].child_ages).toEqual([4,10]);clean(s);
});

test('late original commit and colleague edit cannot silently replace retry conditions',async({page})=>{
  const s=await fixture(page,{fail:'before'});await open(page);await page.locator('#tsEmptyAdd').click();await page.locator('#ts-hotel').fill('Мои условия');await page.locator('#ts-child_ages').fill('4');await page.locator('#tsSave').click();
  await page.getByRole('button',{name:'Проверить сохранение'}).click();await expect(page.getByRole('button',{name:'Повторить это сохранение'})).toBeVisible();
  // The original request finishes AFTER the missing-receipt read, and a
  // colleague subsequently changes the same offer before our explicit retry.
  const command=s.calls.find(x=>x.action==='save');s.receipts.set(command.operation_id,{draft:copy(command.draft),version:1});
  const newer=copy(command.draft);newer.offers[0].hotel='Условия коллеги';newer.offers[0].child_ages=[8];s.drafts.set(11,{draft:newer,version:2});
  await page.getByRole('button',{name:'Повторить это сохранение'}).click();await expect(page.locator('#tsRecovery')).toContainText('другая версия');
  await expect(page.locator('#ts-hotel')).toHaveValue('Мои условия');await expect(page.locator('#ts-child_ages')).toHaveValue('4');expect(s.drafts.get(11).draft).toEqual(newer);
  const writes=s.calls.filter(x=>x.action==='save');expect(writes).toHaveLength(2);expect(writes[1]).toEqual(writes[0]);
  await page.getByRole('button',{name:'Показать версию коллеги'}).click();await expect(page.locator('#ts-hotel')).toHaveValue('Условия коллеги');await expect(page.locator('#ts-child_ages')).toHaveValue('8');clean(s);
});

test('readonly selection cannot add or save offers',async({page})=>{
  const s=await fixture(page,{readOnly:true});await open(page);await expect(page.locator('#tsAdd')).toBeDisabled();await expect(page.locator('#tsEmptyAdd')).toBeDisabled();await expect(page.locator('#tsSave')).toBeDisabled();expect(s.calls.map(x=>x.action)).toEqual(['get']);clean(s);
});

function completeDraft(){return {title:'Отдых у моря',intro:'',offers:[{id:'a'.repeat(32),selected:true,hotel:'Синтетический отель у моря',destination:'Турция, Лара',departure:'Москва',date_from:'2027-06-05',nights:7,adults:2,child_ages:[4,4],room:'Семейный номер',meal:'Всё включено',amount:'240000',currency:'RUB',rationale:'Песчаный пляж и семейный номер',compromise:'Компактная территория',flights:'Рейсы уточняются',inclusions:'Трансфер',constraints:'Топливный сбор необходимо проверить',photos:['cover','beach','room'].map(kind=>({kind,url:'https://photos.example.org/'+kind+'.jpg',illustrative:true}))}]}}
const deliveryActions=['prepare','history','publication','send'];
for(const width of [390,430,768,1440])test('selection customer preview and explicit send preserve composer at '+width,async({page,browserName})=>{
  await page.setViewportSize({width,height:900});const s=await fixture(page,{draft:completeDraft()});await page.locator('#replyText').fill('Мой ответ остаётся здесь');await open(page);
  await expect(page.locator('#ts-photo-cover')).toHaveValue('https://photos.example.org/cover.jpg');await page.locator('#tsPreview').click();await expect(page.locator('.tsPreviewCard')).toHaveCount(1);await expect(page.locator('.tsPhotos img')).toHaveCount(3);
  await expect(page.locator('.tsCardText')).toContainText('240 000 ₽ за 4 человека');await expect(page.locator('.tsCardText')).toContainText('Топливный сбор необходимо проверить');expect(s.creations()).toBe(0);
  await expect(page.locator('#tsSend')).toBeEnabled();expect(await page.evaluate(()=>document.querySelector('#tourSelectionDialog').scrollWidth-document.querySelector('#tourSelectionDialog').clientWidth)).toBeLessThanOrEqual(1);
  await page.setViewportSize({width,height:420});await expect(page.locator('#tsSend')).toBeInViewport();await expect(page.locator('#tsClose')).toBeInViewport();await page.setViewportSize({width,height:900});
  await expect.poll(()=>s.pending.size).toBeLessThanOrEqual(2);fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({animations:'disabled',path:path.join(root,'manager2-artifacts',`tour-selection-preview-${browserName}-${width}.png`)});
  await page.locator('#tsSend').click();await expect(page.locator('#tsStatus')).toContainText('Подборка отправлена');expect(s.creations()).toBe(1);await expect(page.locator('.tsDeliveryState')).toHaveText('✓ Отправлен');await expect(page.locator('#tsSend')).toBeDisabled();
  await page.locator('#tsClose').click();await expect(page.locator('#replyText')).toHaveValue('Мой ответ остаётся здесь');await open(page);await page.locator('#tsHistory').click();await page.getByRole('button',{name:/Версия 1/}).click();await expect(page.locator('.tsDeliveryState')).toHaveText('✓ Отправлен');expect(s.creations()).toBe(1);await expect.poll(()=>s.pending.size).toBeLessThanOrEqual(2);clean(s,deliveryActions);
});

test('selection lost creation response checks saved result without another send',async({page})=>{
  const s=await fixture(page,{draft:completeDraft(),sendMode:'after'});await open(page);await page.locator('#tsPreview').click();await page.locator('#tsSend').click();await expect(page.locator('.tsDeliveryState')).toHaveText('✓ Отправлен');expect(s.creations()).toBe(1);expect(s.calls.filter(x=>x.action==='send')).toHaveLength(1);await expect(page.locator('#tsSend')).toBeDisabled();clean(s,deliveryActions);
});

test('selection unknown creation prevents another send after reopening',async({page})=>{
  const s=await fixture(page,{draft:completeDraft(),sendMode:'unknown'});await open(page);await page.locator('#tsPreview').click();await page.locator('#tsSend').click();await expect(page.locator('.tsDeliveryState')).toContainText('Результат неизвестен');await expect(page.locator('#tsSend')).toBeDisabled();
  await page.locator('#tsClose').click();s.holdPhotos();await open(page);await page.locator('#tsPreview').click();
  const photosReady=()=>page.locator('.tsPhotos img').evaluateAll(images=>images.length===3&&images.every(img=>img.complete&&img.naturalWidth>0));
  // A browser may reuse decoded images in the same document. Otherwise hold
  // and identify all three new authenticated GETs before allowing completion.
  await expect.poll(async()=>s.heldPhotos()===3||(s.heldPhotos()===0&&await photosReady())).toBe(true);
  const photoRequests=[...s.pending].filter(r=>new URL(r.url()).pathname==='/manager/tour-selection-photo.php');
  if(s.heldPhotos()===3)expect(photoRequests.map(r=>({method:r.method(),conversation:new URL(r.url()).searchParams.get('conversation_id'),photo:new URL(r.url()).searchParams.get('photo')})).sort((a,b)=>a.photo.localeCompare(b.photo))).toEqual([0,1,2].map(photo=>({method:'GET',conversation:'11',photo:String(photo)})));
  else {expect(photoRequests).toEqual([]);expect(await photosReady()).toBe(true)}
  await expect(page.locator('#tsSend')).toBeDisabled();expect(s.creations()).toBe(1);expect(s.calls.filter(x=>x.action==='send')).toHaveLength(1);
  s.releasePhotos();
  await expect.poll(photosReady).toBe(true);
  await expect.poll(()=>[...s.pending].filter(r=>new URL(r.url()).pathname==='/manager/tour-selection-photo.php').map(r=>r.url())).toEqual([]);
  await expect.poll(()=>s.pending.size).toBeLessThanOrEqual(2);
  await expect(page.locator('.tsDeliveryState')).toContainText('Результат неизвестен');await expect(page.locator('#tsSend')).toBeDisabled();expect(s.creations()).toBe(1);expect(s.calls.filter(x=>x.action==='send')).toHaveLength(1);clean(s,deliveryActions);
});

test('selection missing photos cannot become a sendable quote',async({page})=>{
  const draft=completeDraft();draft.offers[0].photos=[];const s=await fixture(page,{draft});await open(page);await page.locator('#tsPreview').click();await expect(page.locator('#tsStatus')).toContainText('три фото');await expect(page.locator('#tsSend')).toBeHidden();expect(s.creations()).toBe(0);clean(s,deliveryActions);
});

test('selection photo failure keeps the preview unsent',async({page})=>{
  const s=await fixture(page,{draft:completeDraft(),photoError:true});await open(page);await page.locator('#tsPreview').click();await expect(page.locator('#tsStatus')).toContainText('Не удалось загрузить фото');await expect(page.locator('#tsSend')).toBeDisabled();expect(s.creations()).toBe(0);clean(s,deliveryActions);
});

test('pending selection photos cannot send or overwrite another clients editor',async({page})=>{
  const s=await fixture(page,{draft:completeDraft(),holdPhotos:true,photoError:true});await open(page);await page.locator('#tsPreview').click();await expect.poll(()=>s.heldPhotos()).toBe(3);await expect(page.locator('#tsSend')).toBeDisabled();await expect(page.locator('#tsSend')).toHaveText('Загружаем фото…');
  await page.locator('#tsClose').click();await page.locator('#inboxList [data-conversation-id="12"]').click();await open(page);await expect(page.locator('#tsTitle')).toHaveText('Алексей Иванов');
  s.releasePhotos();await expect.poll(()=>s.pending.size).toBeLessThanOrEqual(2);await expect(page.locator('#tsStatus')).not.toContainText('Не удалось загрузить фото');expect(s.creations()).toBe(0);clean(s,deliveryActions);
});
