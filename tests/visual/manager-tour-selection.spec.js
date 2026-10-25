const {test,expect}=require('@playwright/test');
const fs=require('node:fs'),path=require('node:path');
const {execFileSync}=require('node:child_process');
const {setup,root}=require('./fixtures/internal-notes');
const copy=x=>JSON.parse(JSON.stringify(x));
const {sourceFixture,bridgeFixture,anexSourceFixture,tourvisorSourceFixture}=require('./fixtures/selection-collector');
async function collectorSourceRuntime(page,mode='saved'){
 await page.evaluate(mode=>{
  window.collectorStaged=[];window.collectorRequests=[];window.collectorResult=mode==='lost_response'?'saved':mode;
  window.chrome={runtime:{sendMessage:async m=>{
   collectorRequests.push(m);
   if(m.type==='begin_add')return{ok:true,ticket:'a'.repeat(32)};
   if(m.type==='enqueue'||m.type==='enqueue_edit'){collectorStaged.push(m);if(mode==='lost_response')throw new Error('Synthetic lost enqueue reply');return{ok:true,operation_id:m.ticket,state:m.type==='enqueue_edit'?'editing':'queued'}}
   if(m.type==='add_status')return{ok:true,state:collectorResult,error:collectorResult==='review'?'source_price_changed':null};
   if(m.type==='show_edit')return{ok:true};
   throw new Error('Unexpected collector command');
  },onMessage:{addListener(){}}}};
 },mode);
 await page.addScriptTag({path:path.join(root,'extensions/selection-collector/collector-client.js')});
}
async function pricePopupFixture(page,options={}){
 const target={actor:7,conversation_id:11,binding:'b'.repeat(64),connection:{id:'c'.repeat(32)},client:'Синтетическая Анна',trip:'Лето у моря',channel:'telegram',count:0},operation='e'.repeat(32),tv=options.tv===true;
 const item={operation_id:operation,state:options.state||'editing',needs_edit_confirmation:true,target:copy(target),observation:{adapter:tv?'tourvisor-snapshot-v1':'funsun-b2b-v1',offer:{hotel:'Синтетический отель',date_from:'2027-06-05',nights:7,room:'Standard / 2AD',amount:'237017',currency:'RUB'},raw:tv?{party_price_confirmation:'pending'}:{price:'237017 RUB'}},...(options.error?{error:options.error}:{})};
 const state={ok:true,target,items:[item]},commands=[];let lost=options.lost===true;
 await page.route('https://collector.fixture/**',async route=>{
  const file=new URL(route.request().url()).pathname.slice(1);
  if(file==='fixture-command'){
   const m=route.request().postDataJSON();commands.push(copy(m));let reply={ok:true};
   if(m.type==='status')reply=copy(state);
   else if(m.type==='edit_draft'&&item.state==='editing')item.edit_draft=copy(m.draft);
   else if(m.type==='remove'){item.state='cancelled';delete item.edit_draft;}
   else if(m.type==='save_edit'&&item.state==='editing'){
    item.manager_amount=m.manager_amount;item.needs_edit_confirmation=false;item.state=options.result||'saved';if(item.state==='saved')target.count=1;
    if(lost){lost=false;return route.abort('failed')}
   }else if(m.type==='open_manager')reply={ok:options.openFailure!==true};
   else reply={ok:false};
   return route.fulfill({contentType:'application/json',body:JSON.stringify(reply)});
  }
  if(!['popup.html','popup.css','popup.js','price-adjustment.js'].includes(file))return route.abort();
  return route.fulfill({contentType:file.endsWith('.html')?'text/html':file.endsWith('.css')?'text/css':'application/javascript',body:fs.readFileSync(path.join(root,'extensions/selection-collector',file))});
 });
 await page.addInitScript(()=>{
  const listeners=[];window.chrome={runtime:{sendMessage:async m=>{const response=await fetch('/fixture-command',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(m)});const r=await response.json();if(['edit_draft','save_edit','remove'].includes(m.type)&&r.ok)for(const fn of listeners)queueMicrotask(()=>fn());return r}},storage:{onChanged:{addListener(fn){listeners.push(fn)}}}};
 });
 await page.goto('https://collector.fixture/popup.html');return{state,commands,operation};
}
function searchInquiryProjection(mode){
  return JSON.parse(execFileSync('php',['-r',`
    require getcwd().'/tests/support/search3-tour-handoff.php';
    $pdo=deliveryDb();search3FixtureSchema($pdo);ProjectConfig::resetForTests(search3FixtureConfig());[$secret]=search3FixtureKeys();$intent=search3FixtureIntent();
    $intent['observation']['hotel']['name']='Синтетический выбранный отель у моря';
    if(${JSON.stringify(mode)}==='deferred'){$intent['observation']['flights']=['state'=>'manager-deferred','outward'=>[],'inward'=>[]];$intent['observation']['price']=array_replace($intent['observation']['price'],['amount'=>null,'state'=>'unknown','basis'=>'shown-offer']);}
    $created=search3FixtureCommand($intent,$secret);$entry=TourSelectionBotEntryService::accept(search3FixtureNative('telegram',search3FixturePayload($created['links']['telegram']),101));
    if(empty($entry['ok']))throw new RuntimeException('Synthetic native inquiry not saved');echo json_encode(TourChoiceService::forConversation($pdo,1),JSON_THROW_ON_ERROR);
  `],{cwd:root,encoding:'utf8'}));
}
async function fixture(page,options={}){
  const base=await setup(page),calls=[],drafts=new Map(),receipts=new Map();let fail=options.fail||'',held=null;
  const snapshot=id=>({conversation_id:id,version:drafts.get(id)?.version||0,draft:copy(drafts.get(id)?.draft||{title:'Подборка туров',intro:'',offers:[]}),can_edit:!options.readOnly,updated_at:null,updated_by_manager_id:7});
  if(options.draft)drafts.set(11,{version:1,draft:copy(options.draft)});
  let publication=null,creations=0,share={version:0,active:false,publication_id:null,path:null};const heldPhotos=[];
  await page.route(/\/manager\/tour-selection-photo\.php\?/,async route=>{
    if(options.holdPhotos)await new Promise(resolve=>heldPhotos.push(resolve));
    const headers={'Cache-Control':'private, no-store'};
    return options.photoError?route.fulfill({status:404,headers,body:''}):route.fulfill({contentType:'image/jpeg',headers,body:fs.readFileSync(path.join(root,'assets/owner-tour-cards/compact-v1',(['cover','beach','room'][Number(new URL(route.request().url()).searchParams.get('photo'))]||'cover')+'.jpg'))});
  });
  await page.route('https://apigate-tui.fstravel.com/api/geocontent/static/Hotel/**',route=>{
    const url=route.request().url(),kind=url.includes('MainPhoto')?'cover':url.includes('InsideOfTheRoom')?'room':'beach';
    return route.fulfill({contentType:'image/jpeg',body:fs.readFileSync(path.join(root,'assets/owner-tour-cards/compact-v1',kind+'.jpg'))});
  });
  const photoUploads=[],uploadedFiles=new Map();let heldUpload=null;
  await page.route(/\/manager\/tour-selection-photo\.php(?:\?|$)/,async route=>{
    if(route.request().method()==='GET'){
      const key=new URL(route.request().url()).searchParams.get('draft_photo');if(!key)return route.fallback();const photo=uploadedFiles.get(key);if(!photo?.complete)return route.fulfill({status:404,body:''});
      return route.fulfill({contentType:'image/jpeg',headers:{'Cache-Control':'private, no-store'},body:fs.readFileSync(path.join(root,'assets/owner-tour-cards/compact-v1',photo.kind+'.jpg'))});
    }
    const d=route.request().postDataJSON();photoUploads.push(copy(d));expect(d.action).toBe('upload');expect(d.csrf).toBe('fixture-csrf');expect(d.conversation_id).toBe(11);
    if(options.uploadMode==='invalid')return route.fulfill({status:422,contentType:'application/json',body:JSON.stringify({ok:false,error:'invalid_photo'})});
    expect(d.image_base64.length).toBeLessThanOrEqual(96000);expect(d.part_index).toBeGreaterThanOrEqual(0);expect(d.part_count).toBe(Math.ceil(d.image_bytes/72000));
    let photo=uploadedFiles.get(d.operation_id);if(!photo){photo={parts:new Map(),complete:false,url:'https://app.anytoour.ru/manager/tour-selection-photo.php?conversation_id=11&draft_photo='+d.operation_id,kind:d.kind,illustrative:true};uploadedFiles.set(d.operation_id,photo)}
    photo.parts.set(d.part_index,Buffer.from(d.image_base64,'base64'));photo.complete=photo.parts.size===d.part_count;
    if(options.uploadMode==='after'||options.uploadMode==='after_final'&&photo.complete){options.uploadMode='';return route.abort('failed')}
    if(options.uploadMode==='hold')await new Promise(resolve=>heldUpload=resolve);
    return route.fulfill({contentType:'application/json',body:JSON.stringify(photo.complete?{ok:true,operation_id:d.operation_id,photo:{url:photo.url,kind:photo.kind,illustrative:true},sha256:d.image_sha256}:{ok:true,operation_id:d.operation_id,state:'receiving',part_index:d.part_index,received:photo.parts.size})});
  });
  await page.route('**/manager/tour-selections-api.php',async route=>{
    const d=route.request().postDataJSON();calls.push(copy(d));expect(d.csrf).toBe('fixture-csrf');expect([11,12]).toContain(d.conversation_id);
    const reply=(body,status=200)=>route.fulfill({status,contentType:'application/json',body:JSON.stringify(body)});
    if(d.action==='trip_context')return reply({ok:true,trip:options.trip||{state:'unbound',revision:'',fields:{},details:[],warnings:[]}});
    if(d.action==='trip_apply'){if(options.tripMode==='changed')return reply({ok:false,error:'trip_changed'},409);if(options.tripMode==='denied')return reply({ok:false,error:'forbidden'},403);if(d.confirm_current_trip!==true||d.revision!==options.trip?.revision)throw new Error('Unconfirmed trip');if(options.holdTrip)await new Promise(resolve=>held=resolve);return reply({ok:true,trip:options.trip});}
    if(d.action==='get')return reply({ok:true,selection:snapshot(d.conversation_id)});
    if(d.action==='share')return reply({ok:true,share:copy(share)});
    if(['publish','revoke'].includes(d.action)){
      if(options.readOnly)return reply({ok:false,error:'forbidden'},403);
      if(d.share_version!==share.version)return reply({ok:false,error:'share_changed'},409);
      share={version:share.version+1,active:d.action==='publish',publication_id:d.publication_id,path:d.action==='publish'?'/selection/?t='+'d'.repeat(64):null};
      if(options.shareMode==='after'){options.shareMode='';return route.abort('failed')}
      return reply({ok:true,share:copy(share)});
    }
    if(d.action==='operation')return reply({ok:true,found:receipts.has(d.operation_id),saved_version:receipts.get(d.operation_id)?.version??null,selection:snapshot(d.conversation_id)});
    if(d.action==='prepare'){
      const built=JSON.parse(execFileSync('php',['-r',`require getcwd().'/services/ManagerTourSelectionPublicationDraft.php'; $draft=json_decode(stream_get_contents(STDIN),true); $result=ManagerTourSelectionPublicationDraft::build($draft); if ($result['ok']) foreach ($result['offers'] as &$offer) $offer['card']=TourOfferCardRenderer::render($offer['snapshot'],$offer['presentation'],'tour_like_1_'.str_repeat('a',24)); echo json_encode($result,JSON_THROW_ON_ERROR);`],{cwd:root,input:JSON.stringify(snapshot(d.conversation_id).draft),encoding:'utf8'}));
      if(!built.ok)return reply(built,422);
      publication=publication||{id:'a'.repeat(32),draft_version:d.version,title:built.title,channel:options.channel||'telegram',can_send:!options.readOnly,cards:built.offers.map((offer,i)=>({index:i,offer_id:i+1,state:'pending',snapshot:offer.snapshot,text:offer.card.plain_text,photos:offer.presentation.photos.map((_,p)=>'tour-selection-photo.php?publication_id='+'a'.repeat(32)+'&card='+i+'&photo='+p+'&conversation_id='+d.conversation_id),...(offer.presentation.layout==='gallery-v1'?{photo_layout:'gallery-v1'}:{})}))};
      return reply({ok:true,publication:copy(publication)});
    }
    if(d.action==='interest')return reply(options.interest||{ok:false,error:'observations_unavailable'},options.interest?.ok?200:503);
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
  if(options.deniedEntry)await page.route('**/manager/api.php',route=>{const d=route.request().postDataJSON();return d.action==='detail'&&Number(d.conversation_id)===11?route.fulfill({status:403,contentType:'application/json',body:'{"ok":false,"error":"forbidden"}'}):route.fallback()});
  if(options.entry)await page.goto('http://127.0.0.1:4173/manager2/'+options.entry);
  await expect.poll(()=>page.evaluate(()=>!!WorkspaceV2.S.workspaceBound&&!WorkspaceV2.S.booting)).toBe(true);
  if(!options.entry){await page.locator('#inboxList [data-conversation-id="11"]').click();await expect(page.locator('#tourSelectionOpen')).toBeAttached()}
  return {...base,calls,drafts,receipts,snapshot,photoUploads,uploadedFiles,releaseUpload:()=>heldUpload?.(),creations:()=>creations,publication:()=>publication,heldPhotos:()=>heldPhotos.length,holdPhotos:()=>{options.holdPhotos=true},releasePhotos:()=>{options.holdPhotos=false;heldPhotos.splice(0).forEach(resolve=>resolve())},release:()=>held?.(),setFail:x=>fail=x,setTripMode:x=>options.tripMode=x};
}
async function open(page){if(!await page.locator('#tourSelectionOpen').isVisible())await page.locator('#m2ConversationMenuToggle').click();await page.locator('#tourSelectionOpen').click();await expect(page.locator('#tourSelectionDialog')).toBeVisible();await expect(page.locator('#tsStatus')).toContainText(/загружен|Просмотр|Восстановлены/)}

for(const viewport of [{width:390,height:900},{width:430,height:900},{width:768,height:900},{width:1440,height:900},{width:390,height:420}])test('collector opens the exact selection after authorized conversation navigation '+viewport.width+'x'+viewport.height,async({page,browserName})=>{
 await page.setViewportSize(viewport);const s=await fixture(page,{draft:completeDraft(),entry:'?selection=11&collector_actor=7#conversation=11'});
 await expect(page.locator('#tourSelectionDialog')).toBeVisible();await expect(page.locator('#ts-hotel')).toHaveValue('Синтетический отель у моря');await expect(page.locator('#ts-amount')).toHaveValue('240000');expect(s.calls.map(x=>x.action)).toEqual(['get']);
 expect(await page.evaluate(()=>WorkspaceV2.S.current)).toBe(11);expect(new URL(page.url()).searchParams.has('selection')).toBe(false);expect(new URL(page.url()).searchParams.has('collector_actor')).toBe(false);await expect(page.locator('#tsClose')).toBeInViewport();
 fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({animations:'disabled',path:path.join(root,'manager2-artifacts',`collector-navigation-${browserName}-${viewport.width}x${viewport.height}.png`)});
 await page.locator('#tsClose').click();await page.evaluate(()=>WorkspaceV2TourSelection.sync(WorkspaceV2.S.detail.conversation));await expect(page.locator('#tourSelectionDialog')).not.toBeVisible();expect(s.calls.map(x=>x.action)).toEqual(['get']);clean(s);
});

for(const entry of ['?selection=11&collector_actor=8#conversation=11','?selection=11&collector_actor=7#conversation=12','?selection=11&selection=12&collector_actor=7#conversation=11','?selection=11&collector_actor=7','?selection=011&collector_actor=7#conversation=11'])test('collector navigation refuses an unmatched selection hint '+entry,async({page})=>{
 const s=await fixture(page,{draft:completeDraft(),entry});await expect(page.locator('#tourSelectionDialog')).not.toBeVisible();expect(s.calls).toEqual([]);clean(s);
});

test('collector direct selection never opens after a denied conversation read',async({page})=>{
 const s=await fixture(page,{draft:completeDraft(),entry:'?selection=11&collector_actor=7#conversation=11',deniedEntry:true});await expect(page.locator('#tourSelectionDialog')).not.toBeVisible();expect(s.calls).toEqual([]);expect(await page.evaluate(()=>WorkspaceV2.S.current)).toBe(0);clean(s);
});

test('collector selection link leaves the original tab reply and selected file intact',async({page})=>{
 const s=await fixture(page,{draft:completeDraft()});await page.locator('#replyText').fill('Мой ответ остаётся в исходной вкладке');await page.locator('#replyFile').setInputFiles({name:'keep.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic, never sent')});const other=await page.context().newPage();
 const next=await fixture(other,{draft:completeDraft(),entry:'?selection=11&collector_actor=7#conversation=11'});await expect(other.locator('#tourSelectionDialog')).toBeVisible();await expect(page.locator('#replyText')).toHaveValue('Мой ответ остаётся в исходной вкладке');await expect(page.locator('#mediaSelection')).toContainText('keep.txt');expect(await page.evaluate(()=>WorkspaceV2Media.hasFile())).toBe(true);expect(s.calls).toEqual([]);expect(next.calls.map(x=>x.action)).toEqual(['get']);clean(s);clean(next);await other.close();
});

test('collector popup opens current and saved-row selections with captured targets',async({page})=>{
 const s=await pricePopupFixture(page,{state:'saved'});await page.getByRole('button',{name:'Открыть подборку',exact:true}).click();expect(s.commands.find(x=>x.type==='open_manager')).toEqual({type:'open_manager',target:s.state.target});
 s.state.target={...s.state.target,conversation_id:12,client:'Другой синтетический клиент'};await page.reload();await page.getByRole('button',{name:'Открыть эту подборку',exact:true}).click();expect(s.commands.filter(x=>x.type==='open_manager').at(-1)).toEqual({type:'open_manager',target:s.state.items[0].target,operation_id:s.operation});expect(s.commands.some(x=>['confirm','save_edit','retry','variant'].includes(x.type))).toBe(false);
});

test('collector stale popup navigation explains the changed target without an import',async({page})=>{
 const s=await pricePopupFixture(page,{state:'saved',openFailure:true});await page.getByRole('button',{name:'Открыть подборку',exact:true}).click();await expect(page.locator('#notice')).toContainText('Клиент или очередь изменились');expect(s.commands.filter(x=>x.type==='open_manager')).toHaveLength(1);expect(s.commands.some(x=>['confirm','save_edit','retry','variant'].includes(x.type))).toBe(false);
});

for(const viewport of [{width:390,height:900},{width:430,height:900},{width:768,height:900},{width:1440,height:900},{width:390,height:420}])for(const mode of ['selected','deferred'])test(`Manager2 Search3 native inquiry ${mode} preserves exact conditions and draft ${viewport.width}x${viewport.height}`,async({page,browserName})=>{
  await page.setViewportSize(viewport);const s=await fixture(page);
  await page.locator('#replyText').fill('Мой новый ответ, который нельзя потерять');await page.locator('#replyFile').setInputFiles({name:'search3-inquiry.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic, never sent')});
  const detail=await page.evaluate(()=>JSON.parse(JSON.stringify(WorkspaceV2.S.detail)));detail.tour_choices=searchInquiryProjection(mode);
  await page.route('**/manager/api.php',route=>{const d=route.request().postDataJSON();return d.action==='detail'&&Number(d.conversation_id)===11?route.fulfill({contentType:'application/json',body:JSON.stringify(detail)}):route.fallback()});
  expect(await page.evaluate(()=>WorkspaceV2Conversation.refreshVisible())).toBe(true);await page.locator('#mobileLeadBtn').click();const panel=page.locator('#leadCard [data-tour-choices]');await expect(panel).toBeVisible();await panel.locator('summary').click();
  for(const value of ['Выбранные туры из поиска','Синтетический выбранный отель у моря','Family sea view','Возраст детей: 0, 0, 12','12.06.2027','Полную стоимость и наличие необходимо подтвердить','ANEX'])await expect(panel).toContainText(value);
  if(mode==='selected'){for(const value of ['231 456,50 ₽ за всех','Outward selected 10:00 synthetic','Inward selected 19:00 synthetic'])await expect(panel).toContainText(value)}else{await expect(panel).toContainText('Цена не указана');await expect(panel).toContainText('Подбор рейсов с менеджером');await expect(panel).not.toContainText('Outward selected')}
  for(const value of ['Понравившиеся туры','record_id','anex_online:','s3_','19.06.2027'])await expect(panel).not.toContainText(value);await expect(panel.locator('button,form')).toHaveCount(0);
  await panel.evaluate(el=>Promise.all(el.getAnimations({subtree:true}).map(a=>a.finished.catch(()=>{}))));expect(await panel.evaluate(el=>el.scrollWidth<=el.clientWidth+1)).toBe(true);
  await expect(page.locator('#replyText')).toHaveValue('Мой новый ответ, который нельзя потерять');await expect(page.locator('#mediaSelection')).toContainText('search3-inquiry.txt');
  fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({animations:'disabled',path:path.join(root,'manager2-artifacts',`tour-selection-search3-${browserName}-${mode}-${viewport.width}x${viewport.height}.png`)});clean(s);
});
async function save(page){await page.locator('#tsSave').click();await expect(page.locator('#tsSaved')).toHaveText('Сохранено на сервере')}
function clean(s,extra=[]){expect(s.unexpected).toEqual([]);expect(s.errors).toEqual([]);expect(s.calls.filter(x=>!['get','save','operation',...extra].includes(x.action))).toEqual([]);expect(s.pending.size).toBeLessThanOrEqual(2)}

for(const width of [390,430,768,1440])test('selection editor preserves order conditions and reply at '+width,async({page,browserName})=>{
  await page.setViewportSize({width,height:900});const s=await fixture(page);expect(s.calls).toEqual([]);
  await expect(page.locator('#conversationTitle')).toBeVisible();
  await page.locator('#replyText').fill('Ответ клиенту остаётся в переписке');
  await open(page);await page.locator('#tsEmptyAdd').click();await page.locator('#ts-hotel').fill('Тихий отель у моря');await page.locator('#ts-amount').fill('240000');await page.locator('#ts-adults').fill('2');await page.locator('#ts-child_ages').fill('0, 4, 4');await save(page);
  await page.locator('#tsAdd').click();await page.locator('#ts-hotel').fill('Семейный вариант');await page.locator('[data-ts-action="up"]').click();await save(page);
  expect(s.drafts.get(11).draft.offers.map(x=>x.hotel)).toEqual(['Семейный вариант','Тихий отель у моря']);
  const groupTarget=s.drafts.get(11).draft.offers[1].id;
  await page.locator('#ts-hotel-group').selectOption('o:'+groupTarget);await save(page);
  const group=s.drafts.get(11).draft.offers[0].hotel_group;expect(group).toMatch(/^[a-f0-9]{32}$/);expect(s.drafts.get(11).draft.offers[1].hotel_group).toBe(group);
  await expect(page.locator('.tsGroupLabel')).toContainText('вариантов 2');
  await page.locator('[data-ts-field="selected"]').uncheck();await save(page);expect(s.drafts.get(11).draft.offers[0].selected).toBe(false);
  await page.locator('[data-ts-select]').nth(1).click();await expect(page.locator('#ts-child_ages')).toHaveValue('0, 4, 4');
  expect(await page.evaluate(()=>document.querySelector('#tourSelectionDialog').scrollWidth-document.querySelector('#tourSelectionDialog').clientWidth)).toBeLessThanOrEqual(1);
  await page.setViewportSize({width,height:420});await expect(page.locator('#tsSave')).toBeInViewport();await expect(page.locator('#tsClose')).toBeInViewport();await page.setViewportSize({width,height:900});
  await page.locator('#tsGrouping').scrollIntoViewIfNeeded();await expect(page.locator('#ts-hotel-group')).toBeInViewport();
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

for(const viewport of [{width:390,height:900},{width:430,height:900},{width:768,height:900},{width:1440,height:900},{width:390,height:420}])test(`selection colleague comparison retains local recovery ${viewport.width}x${viewport.height}`,async({page,browserName})=>{
  await page.setViewportSize(viewport);const s=await fixture(page);await open(page);await page.locator('#tsEmptyAdd').click();await page.locator('#ts-hotel').fill('Первый вариант');await save(page);
  const other=copy(s.drafts.get(11));other.version++;other.draft.offers[0].hotel='Вариант коллеги';other.draft.offers[0].child_ages=[8];s.drafts.set(11,other);
  await page.locator('#ts-hotel').fill('Мои новые условия');await page.locator('#ts-child_ages').fill('4, 10');await page.locator('#tsSave').click();await expect(page.locator('#tsRecovery')).toContainText('другая версия');
  const writes=s.calls.filter(x=>x.action==='save').length;
  await page.getByRole('button',{name:'Показать версию коллеги',exact:true}).click();await expect(page.locator('#ts-hotel')).toHaveValue('Вариант коллеги');
  await expect(page.getByRole('button',{name:'Продолжить с моими правками',exact:true})).toBeVisible();await expect(page.locator('#tsSave')).toBeDisabled();
  fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({animations:'disabled',path:path.join(root,'manager2-artifacts',`tour-selection-conflict-${browserName}-${viewport.width}x${viewport.height}.png`)});
  await page.locator('#tsClose').click();await open(page);await expect(page.locator('#ts-hotel')).toHaveValue('Вариант коллеги');
  await page.reload();await page.locator('#replyText').waitFor({state:'visible'});await open(page);await expect(page.locator('#ts-hotel')).toHaveValue('Вариант коллеги');
  await page.getByRole('button',{name:'Продолжить с моими правками',exact:true}).click();await expect(page.locator('#ts-hotel')).toHaveValue('Мои новые условия');await expect(page.locator('#ts-child_ages')).toHaveValue('4, 10');
  expect(s.calls.filter(x=>x.action==='save')).toHaveLength(writes);await save(page);expect(s.drafts.get(11).draft.offers[0].hotel).toBe('Мои новые условия');expect(s.drafts.get(11).draft.offers[0].child_ages).toEqual([4,10]);clean(s);
});

test('selection comparison preserves a full draft and invalid age input after reload',async({page})=>{
  const draft=completeDraft();draft.offers=Array.from({length:20},(_,i)=>({...copy(draft.offers[0]),id:i.toString(16).padStart(32,'0'),flights:'f'.repeat(1200),constraints:'c'.repeat(1200),rationale:'r'.repeat(600),compromise:'m'.repeat(600),inclusions:'i'.repeat(600),photos:['cover','beach','room'].map(kind=>({kind,url:'https://photos.example.org/'+kind+'?ref='+'a'.repeat(1000),illustrative:true}))}));
  const s=await fixture(page,{draft});await open(page);await page.locator('#ts-hotel').fill('Мои условия с ошибкой ввода');await page.locator('#ts-child_ages').fill('4, возраст');await page.locator('#tsClose').click();
  const other=copy(s.drafts.get(11));other.version++;other.draft.offers[0].hotel='Серверный вариант';s.drafts.set(11,other);
  await open(page);await page.getByRole('button',{name:'Показать версию коллеги',exact:true}).click();await page.reload();await page.locator('#replyText').waitFor({state:'visible'});await open(page);
  await page.getByRole('button',{name:'Продолжить с моими правками',exact:true}).click();await expect(page.locator('#ts-hotel')).toHaveValue('Мои условия с ошибкой ввода');await expect(page.locator('#ts-child_ages')).toHaveValue('4, возраст');await expect(page.locator('#tsSave')).toBeDisabled();
  expect(s.calls.filter(x=>x.action==='save')).toHaveLength(0);await page.locator('#ts-child_ages').fill('4, 10');await save(page);expect(s.drafts.get(11).draft.offers).toHaveLength(20);expect(s.drafts.get(11).draft.offers[1]).toEqual(draft.offers[1]);clean(s);
});

test('selection comparison accepts a newer server version only by explicit choice',async({page})=>{
  const s=await fixture(page,{draft:completeDraft()});await open(page);await page.locator('#ts-hotel').fill('Мой вариант');
  const other=copy(s.drafts.get(11));other.version++;other.draft.offers[0].hotel='Версия два';s.drafts.set(11,other);await page.locator('#tsSave').click();await page.getByRole('button',{name:'Показать версию коллеги',exact:true}).click();
  other.version++;other.draft.offers[0].hotel='Версия три';s.drafts.set(11,copy(other));await page.locator('#tsClose').click();await open(page);await expect(page.locator('#ts-hotel')).toHaveValue('Версия три');
  const writes=s.calls.filter(x=>x.action==='save').length;await page.getByRole('button',{name:'Продолжить с версией коллеги',exact:true}).click();await expect(page.locator('#tsSaved')).toHaveText('Сохранено на сервере');
  await page.reload();await page.locator('#replyText').waitFor({state:'visible'});await open(page);await expect(page.locator('#ts-hotel')).toHaveValue('Версия три');expect(s.calls.filter(x=>x.action==='save')).toHaveLength(writes);clean(s);
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
  expect(await page.locator('.tsCardText').textContent()).toBe(s.publication().cards[0].text);
  await expect(page.locator('.tsCardTitle')).toHaveText('Синтетический отель у моря');await expect(page.locator('.tsCardPrice')).toHaveText('240 000 ₽ за 4 человека');
  await expect(page.locator('.tsPhotoSlideshow')).toHaveCount(1);await expect(page.locator('.tsPhotoMosaic')).toHaveCount(0);
  expect(await page.locator('.tsPhotos').evaluate(el=>el.scrollWidth>el.clientWidth*2.8)).toBe(true);
  await expect(page.getByRole('button',{name:'Предыдущее фото'})).toBeDisabled();await expect(page.locator('.tsPhotoPosition')).toHaveText('Фото 1 из 3');
  await page.getByRole('button',{name:'Следующее фото'}).click();await expect.poll(()=>page.locator('.tsPhotos').evaluate(el=>Math.round(el.scrollLeft/el.clientWidth))).toBe(1);await expect(page.locator('.tsPhotoPosition')).toHaveText('Фото 2 из 3');
  await page.locator('.tsPhotos').evaluate(el=>{el.scrollLeft=el.clientWidth*2});await expect(page.locator('.tsPhotoPosition')).toHaveText('Фото 3 из 3');await expect(page.getByRole('button',{name:'Следующее фото'})).toBeDisabled();
  await page.getByRole('button',{name:'Предыдущее фото'}).click();await expect(page.locator('.tsPhotoPosition')).toHaveText('Фото 2 из 3');await page.getByRole('button',{name:'Предыдущее фото'}).click();await expect(page.locator('.tsPhotoPosition')).toHaveText('Фото 1 из 3');
  await expect(page.locator('.tsCardText')).toContainText('240 000 ₽ за 4 человека');await expect(page.locator('.tsCardText')).toContainText('Топливный сбор необходимо проверить');expect(s.creations()).toBe(0);
  await expect(page.locator('#tsSend')).toBeEnabled();expect(await page.evaluate(()=>document.querySelector('#tourSelectionDialog').scrollWidth-document.querySelector('#tourSelectionDialog').clientWidth)).toBeLessThanOrEqual(1);
  await page.setViewportSize({width,height:420});await expect(page.locator('#tsSend')).toBeInViewport();await expect(page.locator('#tsClose')).toBeInViewport();await page.setViewportSize({width,height:900});
  await expect.poll(()=>s.pending.size).toBeLessThanOrEqual(2);fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({animations:'disabled',path:path.join(root,'manager2-artifacts',`tour-selection-preview-${browserName}-${width}.png`)});
  await page.locator('#tsSend').click();await expect(page.locator('#tsStatus')).toContainText('Подборка отправлена');expect(s.creations()).toBe(1);await expect(page.locator('.tsDeliveryState')).toHaveText('✓ Отправлен');await expect(page.locator('#tsSend')).toBeDisabled();
  await page.locator('#tsClose').click();await expect(page.locator('#replyText')).toHaveValue('Мой ответ остаётся здесь');await open(page);await page.locator('#tsHistory').click();await page.getByRole('button',{name:/Версия 1/}).click();await expect(page.locator('.tsDeliveryState')).toHaveText('✓ Отправлен');expect(s.creations()).toBe(1);await expect.poll(()=>s.pending.size).toBeLessThanOrEqual(2);clean(s,deliveryActions);
});

for(const width of [390,430,768,1440])test('selection MAX preview retains mosaic and escaped canonical caption at '+width,async({page,browserName})=>{
  await page.setViewportSize({width,height:900});const draft=completeDraft();draft.offers[0].hotel='Отель <img src=x onerror=alert(1)> & море';draft.offers[0].constraints='<b>Доплату проверить</b>';
  const s=await fixture(page,{draft,channel:'max'});await open(page);await page.locator('#tsPreview').click();
  await expect(page.locator('.tsPhotoMosaic')).toHaveCount(1);await expect(page.locator('.tsPhotoSlideshow')).toHaveCount(0);
  await expect(page.locator('.tsPhotos img')).toHaveCount(3);await expect(page.locator('.tsCardTitle')).toHaveText(draft.offers[0].hotel);await expect(page.locator('.tsCardPrice')).toHaveText('240 000 ₽ за 4 человека');
  expect(await page.locator('.tsCardText').textContent()).toBe(s.publication().cards[0].text);await expect(page.locator('.tsCardText img,.tsCardText b,.tsCardText script')).toHaveCount(0);
  expect(await page.locator('.tsPhotos').evaluate(el=>el.scrollWidth-el.clientWidth)).toBeLessThanOrEqual(1);await expect(page.locator('#tsSend')).toBeEnabled();expect(s.creations()).toBe(0);
  fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({animations:'disabled',path:path.join(root,'manager2-artifacts',`tour-selection-max-preview-${browserName}-${width}.png`)});clean(s,deliveryActions);
});

test('selection lost creation response checks saved result without another send',async({page})=>{
  const s=await fixture(page,{draft:completeDraft(),sendMode:'after'});await open(page);await page.locator('#tsPreview').click();await page.locator('#tsSend').click();await expect(page.locator('.tsDeliveryState')).toHaveText('✓ Отправлен');expect(s.creations()).toBe(1);expect(s.calls.filter(x=>x.action==='send')).toHaveLength(1);await expect(page.locator('#tsSend')).toBeDisabled();clean(s,deliveryActions);
});

test('selection unknown creation prevents another send after reopening',async({page})=>{
  const s=await fixture(page,{draft:completeDraft(),sendMode:'unknown'});await open(page);await page.locator('#tsPreview').click();await page.locator('#tsSend').click();await expect(page.locator('.tsDeliveryState')).toContainText('Результат неизвестен');await expect(page.locator('#tsSend')).toBeDisabled();
  await page.locator('#tsClose').click();s.holdPhotos();await open(page);await page.locator('#tsPreview').click();
  const photosReady=()=>page.locator('.tsPhotos img').evaluateAll(images=>images.length===3&&images.every(img=>img.complete&&img.naturalWidth>0));
  // A browser may reuse decoded images in the same document. Every other
  // image must have its authenticated GET held before allowing completion.
  await expect.poll(async()=>s.heldPhotos()+await page.locator('.tsPhotos img').evaluateAll(images=>images.filter(img=>img.complete&&img.naturalWidth>0).length)).toBe(3);
  const photoRequests=[...s.pending].filter(r=>new URL(r.url()).pathname==='/manager/tour-selection-photo.php');
  const waitingPhotos=await page.locator('.tsPhotos img').evaluateAll(images=>images.flatMap((img,i)=>img.complete&&img.naturalWidth>0?[]:[i]));
  expect(photoRequests.map(r=>({method:r.method(),conversation:new URL(r.url()).searchParams.get('conversation_id'),photo:new URL(r.url()).searchParams.get('photo')})).sort((a,b)=>a.photo.localeCompare(b.photo))).toEqual(waitingPhotos.map(photo=>({method:'GET',conversation:'11',photo:String(photo)})));
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

for(const width of [390,430,768,1440])test('selection shared link explicit publish and revoke preserve composer at '+width,async({page,browserName})=>{
  await page.setViewportSize({width,height:900});const s=await fixture(page,{draft:completeDraft()});
  await page.locator('#replyText').fill('Несохранённый ответ менеджера');await open(page);await page.locator('#tsPreview').click();
  await expect(page.locator('#tsSend')).toBeEnabled();await page.locator('#tsShare').click();
  expect(s.calls.filter(x=>x.action==='publish')).toHaveLength(0);await page.getByRole('button',{name:'Опубликовать эту версию',exact:true}).click();
  const input=page.getByRole('textbox',{name:'Общая ссылка на подборку'});await expect(input).toHaveValue(/\/selection\/\?t=d{64}$/);
  await expect(page.locator('#tsStatus')).toContainText('Сообщения туристу не отправлялись');expect(s.creations()).toBe(0);
  await expect(page.locator('#tourSelectionDialog')).toBeVisible();expect(await page.locator('#tourSelectionDialog').evaluate(e=>e.scrollWidth-e.clientWidth)).toBeLessThanOrEqual(1);
  fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({animations:'disabled',path:path.join(root,'manager2-artifacts',`tour-selection-share-${browserName}-${width}.png`)});
  await page.getByRole('button',{name:'Отозвать ссылку',exact:true}).click();await expect(input).toHaveCount(0);await expect(page.locator('#tsRecovery')).toContainText('Ссылка отозвана');
  await page.locator('#tsClose').click();await expect(page.locator('#replyText')).toHaveValue('Несохранённый ответ менеджера');
  clean(s,[...deliveryActions,'share','publish','revoke']);
});

test('selection lost publish response reads status without another publication',async({page})=>{
  const s=await fixture(page,{draft:completeDraft(),shareMode:'after'});await open(page);await page.locator('#tsPreview').click();await expect(page.locator('#tsSend')).toBeEnabled();
  await page.locator('#tsShare').click();await page.getByRole('button',{name:'Опубликовать эту версию',exact:true}).click();
  await expect(page.getByRole('textbox',{name:'Общая ссылка на подборку'})).toBeVisible();expect(s.calls.filter(x=>x.action==='publish')).toHaveLength(1);expect(s.creations()).toBe(0);
  clean(s,[...deliveryActions,'share','publish']);
});

test('selection publish becomes available when held preview photos finish',async({page})=>{
  const s=await fixture(page,{draft:completeDraft(),holdPhotos:true});await open(page);await page.locator('#tsPreview').click();await expect.poll(()=>s.heldPhotos()).toBe(3);
  await page.locator('#tsShare').click();await expect(page.locator('#tsPublish')).toBeDisabled();expect(s.calls.filter(x=>x.action==='publish')).toHaveLength(0);
  s.releasePhotos();await expect(page.locator('#tsPublish')).toBeEnabled();await page.locator('#tsPublish').click();await expect(page.getByRole('textbox',{name:'Общая ссылка на подборку'})).toBeVisible();
  expect(s.calls.filter(x=>x.action==='publish')).toHaveLength(1);expect(s.creations()).toBe(0);clean(s,[...deliveryActions,'share','publish']);
});

for(const width of [390,430,768,1440])test('Manager2 web inquiry retains exact public terms and the composer at '+width,async({page,browserName})=>{
  await page.setViewportSize({width,height:900});const s=await fixture(page);
  await page.locator('#replyText').fill('Мой несохранённый ответ');await page.locator('#replyFile').setInputFiles({name:'selection-inquiry.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic file, never sent')});
  const detail=await page.evaluate(()=>JSON.parse(JSON.stringify(WorkspaceV2.S.detail)));
  detail.tour_choices={offers:[],web_inquiries:[{variant:2,link_version:3,public_offer:{hotel:'Синтетический выбранный отель',departure:'Москва',date_from:'2027-06-12',nights:7,adults:2,children:2,room:'Именно семейный номер',meal:'Всё включено',amount:'260000.50',currency:'RUB',flights:'Рейсы уточняются',inclusions:'Трансфер',constraints:'Топливо требует проверки',rationale:'Песчаный пляж',compromise:'До пляжа 400 м',observed_at:'2026-10-23T08:00:00Z'}}]};
  await page.route('**/manager/api.php',route=>{const d=route.request().postDataJSON();return d.action==='detail'&&Number(d.conversation_id)===11?route.fulfill({contentType:'application/json',body:JSON.stringify(detail)}):route.fallback()});
  expect(await page.evaluate(()=>WorkspaceV2Conversation.refreshVisible())).toBe(true);
  await page.locator('#mobileLeadBtn').click();const panel=page.locator('#leadCard [data-tour-choices]');await expect(panel).toBeVisible();await panel.locator('summary').click();
  for(const value of ['Обращения из веб-подборки','Синтетический выбранный отель','260 000,50 ₽ за всех','Вариант 2 · версия 3','12.06.2027','Именно семейный номер','Взрослых: 2 · детей: 2','Рейсы уточняются','Топливо требует проверки','До пляжа 400 м'])await expect(panel).toContainText(value);
  await expect(panel).not.toContainText('Понравившиеся туры');await expect(panel).not.toContainText('Возраст детей');await expect(panel.locator('button,form')).toHaveCount(0);
  // The canonical summary arrow rotates for140ms. Its transient transformed
  // glyph contributes to Chromium scrollWidth; measure the settled open state.
  await panel.evaluate(el=>Promise.all(el.getAnimations({subtree:true}).map(a=>a.finished.catch(()=>{}))));
  await panel.scrollIntoViewIfNeeded();expect(await panel.evaluate(el=>el.scrollWidth-el.clientWidth)).toBeLessThanOrEqual(1);
  fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({animations:'disabled',path:path.join(root,'manager2-artifacts',`tour-selection-inquiry-${browserName}-${width}.png`)});
  await page.locator('#mobileLeadClose').click();await expect(page.locator('#replyText')).toHaveValue('Мой несохранённый ответ');await expect(page.locator('#mediaSelection')).toContainText('selection-inquiry.txt');
  expect(s.unexpected).toEqual([]);expect(s.errors).toEqual([]);expect(s.calls.filter(call=>call.action==='send')).toEqual([]);
});

for(const width of [390,430,768,1440])test('public selection has exact terms and no private metadata at '+width,async({page,browserName})=>{
  const source=completeDraft(),built=JSON.parse(execFileSync('php',['-r',`require getcwd().'/services/ManagerTourSelectionPublicationDraft.php'; $r=ManagerTourSelectionPublicationDraft::build(json_decode(stream_get_contents(STDIN),true)); echo json_encode($r,JSON_THROW_ON_ERROR);`],{cwd:root,input:JSON.stringify(source),encoding:'utf8'}));
  expect(built.ok).toBe(true);const s=built.offers[0].snapshot;
  const selection={title:'Отдых у моря',intro:'Подобрали варианты под ваше путешествие.',version:2,updated_at:'2026-10-22 12:00:00',offers:[{...s,index:0,children:2,destination:'Турция, Лара',flights:'Рейсы уточняются',inclusions:'Трансфер',constraints:'Доплаты необходимо проверить',observed_at:'2026-10-22T10:00:00Z',photos:['cover','beach','room'].map(x=>'/'+x+'.jpg')}]};
  delete selection.offers[0].child_ages;
  selection.offers[0].hotel_group=0;
  selection.offers.push({...copy(selection.offers[0]),index:1,hotel_group:0,room:'Номер с видом на море',date_from:'2027-06-12',amount:'260000',flights:'Другой перелёт'});
  selection.offers.push({...copy(selection.offers[0]),index:2,hotel_group:1,amount:'270000'});
  for(const offer of selection.offers)offer.bot_links={
    telegram:'https://t.me/synthetic_bot?start=ts_'+'c'.repeat(12)+'_2_'+offer.index.toString(36)+'_'+(offer.index+1).toString(16).repeat(32),
    max:'https://max.ru/synthetic_max?start=ts_'+'c'.repeat(12)+'_2_'+offer.index.toString(36)+'_'+(offer.index+5).toString(16).repeat(32),
  };

  const html=execFileSync('php',['-r',`$selection=json_decode(stream_get_contents(STDIN),true); function selectionEscape(string $v):string{return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');} $h='selectionEscape';require getcwd().'/selection/view.php';`],{cwd:root,input:JSON.stringify(selection),encoding:'utf8'});
  const unexpected=[];
  await page.route('**/*',route=>{const request=route.request(),url=new URL(request.url()),p=url.pathname;if(url.origin!=='https://selection-fixture.invalid'||request.method()!=='GET'){unexpected.push({url:request.url(),method:request.method()});return route.abort()}if(p==='/selection/')return route.fulfill({contentType:'text/html',body:html});if(p==='/selection/selection.css')return route.fulfill({contentType:'text/css',body:fs.readFileSync(path.join(root,'selection/selection.css'))});if(/^\/(cover|beach|room)\.jpg$/.test(p))return route.fulfill({contentType:'image/jpeg',body:fs.readFileSync(path.join(root,'assets/owner-tour-cards/compact-v1',p.slice(1)))});unexpected.push({url:request.url(),method:request.method()});return route.abort()});
  await page.setViewportSize({width,height:1000});await page.goto('https://selection-fixture.invalid/selection/');await expect(page.locator('.offer')).toHaveCount(2);await expect(page.locator('.offerVariant')).toHaveCount(3);await expect(page.locator('.party').first()).toContainText('детей 2');await expect(page.locator('.fareNotice').first()).toContainText('требуют подтверждения');await expect(page.locator('#variant-2')).toContainText('260 000,00');await expect(page.locator('#variant-2')).toContainText('2027-06-12');await expect(page.locator('.variantRoom')).toHaveCount(2);
  await expect(page.getByRole('link',{name:'Уточнить в Telegram',exact:true})).toHaveCount(3);await expect(page.getByRole('link',{name:'Уточнить в MAX',exact:true})).toHaveCount(3);
  for(const offer of selection.offers){const variant=page.locator('.offerVariant').nth(offer.index);for(const [platform,label]of[['telegram','Telegram'],['max','MAX']])await expect(variant.getByRole('link',{name:'Уточнить в '+label,exact:true})).toHaveAttribute('href',offer.bot_links[platform]);}
  expect(new Set(await page.locator('.botActions a').evaluateAll(links=>links.map(link=>link.href))).size).toBe(6);
  await expect(page.locator('form,input,textarea,script')).toHaveCount(0);await expect(page.locator('.nextStep').first()).toContainText('после перехода и запуска бота');
  expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);expect(await page.locator('body').innerText()).not.toContain('child_ages');
  for(const field of ['child_ages','source_url','source_label','callback_data'])expect(await page.content()).not.toContain(field);
  // Native lazy loading need not fetch below-fold photos until they are viewed.
  // Visit every image and require a successful load before the full-page capture.
  for(const photo of await page.locator('img').all()){
    await photo.scrollIntoViewIfNeeded();
    await expect.poll(()=>photo.evaluate(img=>img.complete&&img.naturalWidth>0)).toBe(true);
  }
  await page.evaluate(()=>scrollTo(0,0));await expect.poll(()=>page.evaluate(()=>scrollY)).toBe(0);
  await expect.poll(()=>page.locator('img').evaluateAll(imgs=>imgs.every(i=>i.complete&&i.naturalWidth>0))).toBe(true);
  await page.locator('img').evaluateAll(imgs=>Promise.all(imgs.map(img=>img.decode())));
  await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
  expect(page.url()).toBe('https://selection-fixture.invalid/selection/');expect(unexpected).toEqual([]);
  fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({animations:'disabled',fullPage:true,path:path.join(root,'manager2-artifacts',`tour-selection-public-${browserName}-${width}.png`)});
});


test('explicit hotel grouping survives reload and renaming separates only that offer',async({page})=>{
  const source=completeDraft(),other=copy(source.offers[0]);other.id='b'.repeat(32);other.room='Другой номер';source.offers.push(other);
  const s=await fixture(page,{draft:source});await open(page);
  // Equal names do not silently group. Choose the other exact offer explicitly.
  expect(s.drafts.get(11).draft.offers.every(x=>!x.hotel_group)).toBe(true);
  await page.locator('#ts-hotel-group').selectOption('o:'+other.id);await save(page);let group=s.drafts.get(11).draft.offers[0].hotel_group;
  expect(group).toMatch(/^[a-f0-9]{32}$/);expect(group).not.toBe(other.id);
  // Any valid stored key is allowed, including an existing offer's ID.
  // An ungrouped offer must stay in its own namespace after rename.
  group=other.id;const stored=s.drafts.get(11);stored.version++;stored.draft.offers.forEach(x=>x.hotel_group=group);
  await page.locator('#tsClose').click();await page.reload();await expect.poll(()=>page.evaluate(()=>!!WorkspaceV2.S.workspaceBound&&!WorkspaceV2.S.booting)).toBe(true);
  if(!await page.locator('#tourSelectionOpen').count())await page.locator('#inboxList [data-conversation-id="11"]').click();await open(page);
  await expect(page.locator('#ts-hotel-group')).toHaveValue('g:'+group);
  await page.locator('[data-ts-select="'+other.id+'"]').click();
  await page.locator('#ts-hotel').fill('Другая гостиница');await save(page);
  expect(s.drafts.get(11).draft.offers[1].hotel_group).toBeUndefined();expect(s.drafts.get(11).draft.offers[0].hotel_group).toBe(group);
  await expect(page.locator('#ts-hotel-group')).toHaveValue('');await expect(page.locator('.tsGroupLabel')).toHaveCount(0);expect(s.creations()).toBe(0);clean(s);
});

test('collector exact source row retains current price and fails on changing conditions',async({page})=>{
 const s=await sourceFixture(page);let r=await s.read();expect(r.error).toBeUndefined();expect(r.value.offer.amount).toBe('237017');expect(r.value.raw.price).toBe('237 017 RUB');expect(r.value.offer.child_ages).toEqual([]);expect(r.value.raw.flight_alternatives).toContain('01:00');expect(r.value.offer).not.toHaveProperty('flights');
 await page.locator('tr.price_info').evaluate(e=>['hotel','room','meal','htplace','tour','ptype','townfrom','state'].forEach(key=>e.removeAttribute('data-'+key)));r=await s.read();expect(r.error).toBeUndefined();expect(r.value.keys).toEqual({hotel:'10',room:'20',meal:'30',placement:'40',tour:'78',program:'114',departure:'274286',country:'18803',spo:'60'});
 await page.locator('tr.price_info').evaluate(e=>e.dataset.room='21');expect((await s.read()).error).toContain('FS-room-mismatch');await page.locator('tr.price_info').evaluate(e=>e.dataset.room='20');
 expect(await page.locator('.price').evaluate(e=>getComputedStyle(e,'::before').content)).toContain('259304');
 await page.locator('[name=CHECKIN_BEG]').fill('06.06.2027');expect((await s.read()).error).toContain('Дождитесь установившихся дат');await page.locator('[name=CHECKIN_BEG]').fill('04.06.2027');expect((await s.read()).error).toContain('Нажмите «Искать»');await page.locator('[name=CHECKIN_BEG]').fill('05.06.2027');
 await page.evaluate(()=>document.querySelector('[name=STATEINC]').options[0].value='99');expect((await s.read()).error).toContain('Нажмите «Искать»');await page.evaluate(()=>document.querySelector('[name=STATEINC]').options[0].value='18803');
 await page.locator('.price').evaluate(e=>e.textContent='259304 RUB 237017 RUB');expect((await s.read()).error).toContain('Цена или валюта');await page.locator('.price').evaluate(e=>e.textContent='237 017 RUB');
 await page.locator('.price').evaluate(e=>e.dataset.currency_title='USD');expect((await s.read()).error).toContain('Цена или валюта');await page.locator('.price').evaluate(e=>e.dataset.currency_title='RUB');
 await page.locator('tr.price_info').evaluate(e=>e.dataset.checkin='20270606');expect((await s.read()).error).toContain('Строка не соответствует');await page.locator('tr.price_info').evaluate(e=>e.dataset.checkin='20270605');
 await page.evaluate(()=>document.querySelector('[name=CHILD]').options[0].value='1');expect((await s.read()).error).toContain('Нажмите «Искать»');
 expect(s.calls).toEqual([]);
});

test('FUN&SUN identity errors distinguish field cause and unsupported external rows without exposing raw values',async({page})=>{
 const s=await sourceFixture(page),original=await page.locator('tr.price_info').getAttribute('class');
 for(const [attr,prefix,value]of [['hotel','hotelKey-','10'],['room','roomKey-','20'],['meal','mealKey-','30'],['htplace','htPlaceKey-','40'],['tour','tourKey-','78'],['ptype','programTypeKey-','114'],['townfrom','townFromKey-','274286'],['state','stateKey-','18803']]){
  await page.locator('tr.price_info').evaluate((e,{attr,prefix})=>{e.setAttribute('data-'+attr,'999');},{attr,prefix});
  expect((await s.read()).error).toContain('FS-'+attr+'-mismatch');
  await page.locator('tr.price_info').evaluate((e,{attr,value})=>e.setAttribute('data-'+attr,value),{attr,value});
  expect((await s.read()).error).toBeUndefined();
 }
 await page.locator('tr.price_info').evaluate(e=>e.classList.add('roomKey-21'));expect((await s.read()).error).toContain('FS-room-multiple');
 await page.locator('tr.price_info').evaluate(e=>{e.classList.remove('roomKey-21');e.dataset.room='0'});expect((await s.read()).error).toContain('FS-room-format');
 await page.locator('tr.price_info').evaluate(e=>e.dataset.room='<img src=x onerror=secret>');let r=await s.read();expect(r.error).toContain('FS-room-format');expect(r.error).not.toContain('secret');expect(r.error).not.toContain('<img');
 await page.locator('tr.price_info').evaluate(e=>{e.removeAttribute('data-room');e.classList.remove('roomKey-20')});expect((await s.read()).error).toContain('FS-room-missing');
 await page.locator('tr.price_info').evaluate((e,original)=>{e.className=original;e.dataset.room='20';e.dataset.hotel='-2883989';e.classList.replace('hotelKey-10','hotelKey--2883989');e.dataset.htplace=e.dataset.room='-2147483647';e.classList.replace('roomKey-20','roomKey--2147483647');e.classList.replace('htPlaceKey-40','htPlaceKey--2147483647');e.classList.replace('spoKey-60','spoKey-')},original);
 r=await s.read();expect(r.error).toContain('FS-hotel-format');expect(r.error).toContain('вручную');expect(r.error).not.toContain('расходятся');expect(s.calls).toEqual([]);
});

for(const width of [390,430,768,1440])test('FUN&SUN unsupported inventory and real failures stay distinct from pencil guidance at '+width,async({page,browserName})=>{
 await page.setViewportSize({width,height:900});await sourceFixture(page);await collectorSourceRuntime(page,'editing');await page.addScriptTag({path:path.join(root,'extensions/selection-collector/content.js')});
 await page.locator('tr.price_info').evaluate(e=>{e.dataset.hotel='-2883989';e.classList.replace('hotelKey-10','hotelKey--2883989')});
 await page.getByRole('button',{name:'В подборку AnyTour',exact:true}).click();const note=page.locator('[data-anytour-note]');await expect(note).toContainText('FS-hotel-format');await expect(note).toContainText('вручную');await expect(note).toHaveCSS('color','rgb(140, 48, 39)');expect(await page.evaluate(()=>collectorStaged.length)).toBe(0);
 fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({animations:'disabled',path:path.join(root,'manager2-artifacts',`tour-selection-source-error-${browserName}-${width}.png`)});
 await page.locator('tr.price_info').evaluate(e=>{e.dataset.hotel='10';e.classList.replace('hotelKey--2883989','hotelKey-10')});await page.getByRole('button',{name:'✎ Изменить цену',exact:true}).click();await expect(note).toContainText('Откройте значок расширения');await expect(note).toHaveCSS('color','rgb(65, 94, 79)');expect(await page.evaluate(()=>collectorStaged.length)).toBe(1);
 await page.screenshot({animations:'disabled',path:path.join(root,'manager2-artifacts',`tour-selection-source-guidance-${browserName}-${width}.png`)});
 await page.locator('tr.price_info').evaluate(e=>e.dataset.room='21');await page.getByRole('button',{name:'Не добавлено',exact:true}).click();await expect(note).toContainText('FS-room-mismatch');await expect(note).toHaveCSS('color','rgb(140, 48, 39)');expect(await page.evaluate(()=>collectorStaged.length)).toBe(1);expect(await page.evaluate(()=>collectorRequests.filter(x=>x.type==='enqueue').length)).toBe(0);
 expect(await page.locator('body').innerText()).not.toContain('Синтетическая Анна');
});

for(const width of [390,430,768,1440])test('collector source button saves a settled row in one click without third party client identity at '+width,async({page,browserName})=>{
 await page.setViewportSize({width,height:900});await sourceFixture(page);await collectorSourceRuntime(page);
 await page.addScriptTag({path:path.join(root,'extensions/selection-collector/content.js')});await page.getByRole('button',{name:'В подборку AnyTour'}).click();await expect(page.getByRole('button',{name:'✓ В подборке'})).toBeVisible();
 expect(await page.evaluate(()=>collectorStaged.length)).toBe(1);expect(await page.evaluate(()=>collectorStaged[0].observation.offer.amount)).toBe('237017');expect(await page.locator('body').innerText()).not.toContain('Синтетическая Анна');
 expect(await page.evaluate(()=>collectorRequests.map(x=>x.type))).toEqual(['begin_add','enqueue','add_status']);expect(await page.evaluate(()=>collectorStaged[0].ticket)).toBe('a'.repeat(32));
 fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({animations:'disabled',path:path.join(root,'manager2-artifacts',`tour-one-click-funsun-${browserName}-${width}.png`)});
 await page.getByRole('button',{name:'✓ В подборке'}).click({noWaitAfter:true});await page.locator('.price').evaluate(e=>{e.textContent='240000 RUB';e.setAttribute('data-converted-price-number','240000')});await expect(page.getByRole('button',{name:'Не добавлено'})).toBeVisible();expect(await page.evaluate(()=>collectorStaged.length)).toBe(1);
});

for(const mode of ['waiting','review'])test('collector source '+mode+' never claims saved or blindly repeats append',async({page})=>{
 await page.setViewportSize({width:390,height:900});await sourceFixture(page);await collectorSourceRuntime(page,mode);await page.addScriptTag({path:path.join(root,'extensions/selection-collector/content.js')});
 await page.getByRole('button',{name:'В подборку AnyTour'}).click();const label=mode==='waiting'?'В очереди AnyTour':'Проверьте в AnyTour';await expect(page.getByRole('button',{name:label})).toBeEnabled();await expect(page.getByRole('button',{name:'✓ В подборке'})).toHaveCount(0);
 await page.getByRole('button',{name:label}).click();expect(await page.evaluate(()=>collectorStaged.length)).toBe(1);expect(await page.evaluate(()=>collectorRequests.filter(x=>x.type==='begin_add').length)).toBe(1);
 if(mode==='waiting'){await page.evaluate(()=>collectorResult='saved');await page.getByRole('button',{name:label}).click();await expect(page.getByRole('button',{name:'✓ В подборке'})).toBeVisible();expect(await page.evaluate(()=>collectorStaged.length)).toBe(1)}
});

test('collector source lost enqueue reply checks the exact preallocated operation',async({page})=>{
 await sourceFixture(page);await collectorSourceRuntime(page,'lost_response');await page.addScriptTag({path:path.join(root,'extensions/selection-collector/content.js')});
 await page.getByRole('button',{name:'В подборку AnyTour'}).click();await expect(page.getByRole('button',{name:'✓ В подборке'})).toBeVisible();
 expect(await page.evaluate(()=>collectorStaged.length)).toBe(1);expect(await page.evaluate(()=>collectorRequests.at(-1))).toEqual({type:'add_status',operation_id:'a'.repeat(32)});
});

for(const source of [{name:'funsun',fixture:sourceFixture,script:'content.js',amount:'237017'},{name:'anex',fixture:anexSourceFixture,script:'anex-content.js',amount:'177323'},{name:'tourvisor',fixture:tourvisorSourceFixture,script:'tourvisor-content.js',amount:'160000'}])test('source pencil '+source.name+' captures for private editing without importing or exposing a client',async({page,browserName})=>{
 await page.setViewportSize({width:390,height:900});await source.fixture(page);await page.addStyleTag({path:path.join(root,'extensions/selection-collector/content.css')});await collectorSourceRuntime(page,'editing');await page.addScriptTag({path:path.join(root,'extensions/selection-collector',source.script)});
 await page.getByRole('button',{name:'✎ Изменить цену',exact:true}).click();await expect(page.getByRole('button',{name:'Правка в AnyTour',exact:true})).toBeVisible();await expect(page.locator('[data-anytour-note]')).toHaveCSS('color','rgb(65, 94, 79)');
 expect(await page.evaluate(()=>collectorStaged.length)).toBe(1);expect(await page.evaluate(()=>collectorStaged[0].type)).toBe('enqueue_edit');expect(await page.evaluate(()=>collectorStaged[0].observation.offer.amount)).toBe(source.amount);expect(await page.locator('body').innerText()).not.toContain('Синтетическая Анна');
 await page.getByRole('button',{name:'Правка в AnyTour',exact:true}).click();expect(await page.evaluate(()=>collectorStaged.length)).toBe(1);expect(await page.evaluate(()=>collectorRequests.filter(x=>x.type==='show_edit').length)).toBe(1);
 fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({animations:'disabled',path:path.join(root,'manager2-artifacts',`tour-pencil-source-${source.name}-${browserName}.png`)});
 await page.setViewportSize({width:1440,height:900});await expect(page.getByRole('button',{name:'В подборку AnyTour',exact:true})).toBeVisible();await expect(page.getByRole('button',{name:'Правка в AnyTour',exact:true})).toBeVisible();await page.screenshot({animations:'disabled',path:path.join(root,'manager2-artifacts',`tour-pencil-source-${source.name}-${browserName}-1440.png`)});
});

for(const width of [390,430,768,1440])test('collector connection preserves exact destination at '+width,async({page,browserName})=>{
 await page.setViewportSize({width,height:900});const s=await bridgeFixture(page);await expect(page.locator('#collectorTarget')).toContainText('Синтетическая Анна');await s.hello();await expect(page.locator('#collectorStatus')).toContainText('Проверьте клиента');await expect(page.locator('#collectorConnect')).toBeDisabled();
 await expect(page.locator('#collectorOpenSelection')).toHaveAttribute('href','/manager2/?selection=11&collector_actor=7#conversation=11');
 await page.locator('#collectorConfirm').check();await page.locator('#collectorConnect').click();await expect(page.locator('#collectorStatus')).toContainText('Подключено');expect(s.calls.filter(x=>x.action==='collector_connect')).toHaveLength(1);
 const before=s.calls.length;await s.command({action:'import',actor:8});await s.command({action:'import',conversation_id:12});expect(s.calls).toHaveLength(before);
 await s.command({action:'operation'});await expect.poll(()=>s.events.some(e=>e.type==='result'&&e.result.found===false)).toBe(true);expect(s.calls.at(-1).action).toBe('collector_operation');
 await s.command({action:'import'});await expect(page.locator('#collectorTarget')).toContainText('На сервере: 1');await expect.poll(()=>s.events.some(e=>e.type==='connected'&&e.target.count===1&&e.selected===false)).toBe(true);
 expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);
 await page.locator('#collectorRevoke').click();await expect(page.locator('#collectorStatus')).toContainText('отозван');await expect(page.locator('#collectorRevoke')).toBeDisabled();expect(s.errors).toEqual([]);
 fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({animations:'disabled',path:path.join(root,'manager2-artifacts',`tour-collector-${browserName}-${width}.png`)});
 await page.setViewportSize({width,height:420});await page.locator('#collectorConfirm').scrollIntoViewIfNeeded();await expect(page.locator('#collectorConfirm')).toBeInViewport();
});

test('collector return link clears stale identity after access is lost',async({page})=>{
 const s=await bridgeFixture(page);await s.hello();await expect(page.locator('#collectorOpenSelection')).toHaveText('← Открыть эту подборку');
 await page.route('**/manager/tour-selections-api.php',route=>route.fulfill({status:403,contentType:'application/json',body:'{"ok":false,"error":"forbidden"}'}));await page.locator('#collectorRefresh').click();await expect(page.locator('#collectorStatus')).toContainText('нет права');await expect(page.locator('#collectorOpenSelection')).toHaveAttribute('href','/manager2/');await expect(page.locator('#collectorTarget')).toBeEmpty();expect(s.errors).toEqual([]);
});

for(const width of [390,430,768,1440])test('collector imported source survives manual edits in responsive editor at '+width,async({page,browserName})=>{
 await page.setViewportSize({width,height:900});const imported=JSON.parse(execFileSync('php',['-r',`require 'tests/support/manager-tour-collector.php';$i=ManagerTourSelectionImportDraft::normalize(collectorObservation());$o=$i['offer'];$o['id']=str_repeat('a',32);$o['import_source']=ManagerTourSelectionImportDraft::source($i);echo json_encode(['title'=>'Лето у моря','intro'=>'','offers'=>[$o]],JSON_THROW_ON_ERROR);`],{cwd:root,encoding:'utf8'}));
 const s=await fixture(page,{draft:imported});await page.locator('#replyText').fill('Сохранённый ответ');await page.locator('#replyFile').setInputFiles({name:'collector.txt',mimeType:'text/plain',buffer:Buffer.from('synthetic')});await open(page);
 await page.locator('.tsImportedSource').scrollIntoViewIfNeeded();await expect(page.locator('.tsImportedSource')).toContainText('200000 RUB');await expect(page.locator('.tsImportedSource')).toContainText('не подтверждены');await expect(page.locator('#tsCollector')).toHaveText('Сборщик туров');
 await page.locator('#ts-amount').fill('210000');await save(page);expect(s.drafts.get(11).draft.offers[0].import_source.fields.amount).toBe('200000');expect(s.drafts.get(11).draft.offers[0].amount).toBe('210000');
 await page.locator('.tsImportedSource').scrollIntoViewIfNeeded();fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({animations:'disabled',path:path.join(root,'manager2-artifacts',`tour-imported-${browserName}-${width}.png`)});
 await page.setViewportSize({width,height:420});await expect(page.locator('#tsSave')).toBeInViewport();await expect(page.locator('#tsClose')).toBeInViewport();expect(await page.locator('#tourSelectionDialog').evaluate(e=>e.scrollWidth-e.clientWidth)).toBeLessThanOrEqual(1);
 await page.locator('#tsClose').click();await expect(page.locator('#replyText')).toHaveValue('Сохранённый ответ');await expect(page.locator('#mediaSelection')).toContainText('collector.txt');clean(s);
});

test('collector popup clearly separates destination review waiting and saved rows',async({page,browserName})=>{
 const ext=path.join(root,'extensions/selection-collector'),states=['destination','waiting','review','blocked','saved'];let data={ok:true,target:{client:'Синтетическая Анна',channel:'telegram',trip:'Лето у моря',count:1},items:states.map((state,i)=>({operation_id:String(i),state,error:state==='review'?'source_price_changed':null,target:{client:'Синтетическая Анна',trip:'Лето у моря'},observation:{offer:{hotel:'Синтетический вариант '+i,date_from:'2027-06-05',nights:7,room:'Standard / 2AD',amount:'200000',currency:'RUB'}}}))};
 await page.setViewportSize({width:390,height:800});await page.route('https://collector.fixture/**',route=>{const file=new URL(route.request().url()).pathname.slice(1);if(!['popup.html','popup.css','popup.js','price-adjustment.js'].includes(file))return route.abort();return route.fulfill({contentType:file.endsWith('.html')?'text/html':file.endsWith('.css')?'text/css':'application/javascript',body:fs.readFileSync(path.join(ext,file))})});
 await page.addInitScript(data=>{window.popupCommands=[];window.chrome={runtime:{sendMessage:async m=>{popupCommands.push(m);return m.type==='status'?data:{ok:true}}},storage:{onChanged:{addListener(){}}}}},data);await page.goto('https://collector.fixture/popup.html');
 await expect(page.locator('.item')).toHaveCount(5);await expect(page.getByRole('button',{name:'Добавить для этого клиента'})).toHaveCount(1);await expect(page.getByRole('button',{name:'Проверить и продолжить'})).toHaveCount(1);await expect(page.getByRole('button',{name:'Добавить отдельным вариантом'})).toHaveCount(1);await expect(page.getByRole('button',{name:'Продолжить с новым подключением этой поездки'})).toHaveCount(1);expect(await page.evaluate(()=>popupCommands.some(x=>x.type!=='status'))).toBe(false);expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);
 fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({animations:'disabled',fullPage:true,path:path.join(root,'manager2-artifacts',`tour-collector-popup-${browserName}.png`)});
});

for(const width of [390,430,768,1440])test('collector pre-add price editor preserves source and typed price until explicit save at '+width,async({page,browserName})=>{
 await page.setViewportSize({width,height:900});const s=await pricePopupFixture(page);await expect(page.locator('#target')).toContainText('Синтетическая Анна');await expect(page.locator('.item')).toContainText('Цена ТО: 237017 RUB');
 await page.getByText('Скидка или наценка',{exact:true}).click();await page.getByLabel('Размер изменения',{exact:true}).fill('2');await page.getByRole('button',{name:'Рассчитать от цены ТО',exact:true}).click();await expect(page.getByLabel('Итоговая цена, RUB',{exact:true})).toHaveValue('232276.66');
 await page.getByRole('button',{name:'Рассчитать от цены ТО',exact:true}).click();await expect(page.getByLabel('Итоговая цена, RUB',{exact:true})).toHaveValue('232276.66');expect(s.commands.filter(x=>x.type==='save_edit')).toHaveLength(0);
 await expect.poll(()=>s.state.items[0].edit_draft?.amount).toBe('232276.66');await expect(page.getByLabel('Размер изменения',{exact:true})).toBeVisible();fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({animations:'disabled',fullPage:true,path:path.join(root,'manager2-artifacts',`tour-preadd-calculator-${browserName}-${width}.png`)});await page.reload();await expect(page.getByLabel('Итоговая цена, RUB',{exact:true})).toHaveValue('232276.66');expect(s.state.items[0].observation.offer.amount).toBe('237017');
 expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({animations:'disabled',fullPage:true,path:path.join(root,'manager2-artifacts',`tour-preadd-price-${browserName}-${width}.png`)});
 await page.setViewportSize({width,height:420});await page.getByRole('button',{name:'Добавить с этой ценой',exact:true}).scrollIntoViewIfNeeded();await expect(page.getByRole('button',{name:'Добавить с этой ценой',exact:true})).toBeInViewport();await page.screenshot({animations:'disabled',path:path.join(root,'manager2-artifacts',`tour-preadd-short-${browserName}-${width}.png`)});await page.getByRole('button',{name:'Добавить с этой ценой',exact:true}).click();await expect(page.locator('.item')).toContainText('Сохранено в подборке');await expect(page.locator('.item')).toContainText('Цена в подборке: 232276.66 RUB');expect(s.commands.filter(x=>x.type==='save_edit')).toEqual([{type:'save_edit',operation_id:s.operation,manager_amount:'232276.66'}]);
});

test('pre-add cancel removes only the unconfirmed edit and reload does not import it',async({page})=>{
 const s=await pricePopupFixture(page);await page.getByLabel('Итоговая цена, RUB',{exact:true}).fill('-1');await expect(page.getByRole('button',{name:'Добавить с этой ценой',exact:true})).toBeDisabled();await page.getByLabel('Итоговая цена, RUB',{exact:true}).fill('220000');await expect(page.getByRole('button',{name:'Добавить с этой ценой',exact:true})).toBeEnabled();await page.getByRole('button',{name:'Отмена',exact:true}).click();await expect(page.locator('.item')).toHaveCount(0);await page.reload();await expect(page.locator('.item')).toHaveCount(0);expect(s.state.items[0].state).toBe('cancelled');expect(s.commands.filter(x=>x.type==='save_edit')).toHaveLength(0);
});
test('pre-add lost Save response reads the existing result without another Save',async({page})=>{
 const s=await pricePopupFixture(page,{lost:true});await page.getByLabel('Итоговая цена, RUB',{exact:true}).fill('220000');await page.getByRole('button',{name:'Добавить с этой ценой',exact:true}).click();await expect(page.locator('.item')).toContainText('Сохранено в подборке');await expect(page.getByRole('button',{name:'Добавить с этой ценой',exact:true})).toHaveCount(0);expect(s.commands.filter(x=>x.type==='save_edit')).toHaveLength(1);expect(s.commands.at(-1).type).toBe('status');
});
test('TV pre-add editor cannot replace the required room price basis with a discount',async({page})=>{
 const s=await pricePopupFixture(page,{tv:true});await page.getByLabel('Итоговая цена, RUB',{exact:true}).fill('220000');await expect(page.getByRole('button',{name:'Добавить с этой ценой',exact:true})).toBeDisabled();await page.getByRole('checkbox').check();await page.getByRole('button',{name:'Добавить с этой ценой',exact:true}).click();await expect(page.locator('.item')).toContainText('Сохранено в подборке');expect(s.commands.filter(x=>x.type==='save_edit')).toEqual([{type:'save_edit',operation_id:s.operation,manager_amount:'220000',confirm_price_basis:true}]);
});
test('unconfirmed adjusted price remains waiting and existing price conflict offers no duplicate bypass',async({page})=>{
 const s=await pricePopupFixture(page,{result:'waiting'});await page.getByLabel('Итоговая цена, RUB',{exact:true}).fill('220000');await page.getByRole('button',{name:'Добавить с этой ценой',exact:true}).click();await expect(page.locator('.item')).toContainText('Нет подтверждения сервера');await expect(page.locator('.item')).not.toContainText('Цена в подборке:');await expect(page.getByLabel('Итоговая цена, RUB',{exact:true})).toHaveCount(0);expect(s.commands.filter(x=>x.type==='save_edit')).toHaveLength(1);
 s.state.items[0].state='review';s.state.items[0].error='manual_price_conflict';await page.reload();await expect(page.locator('.item')).toContainText('Этот тур уже есть с другой ручной ценой');await expect(page.getByRole('button',{name:'Добавить отдельным вариантом',exact:true})).toHaveCount(0);
});

for(const viewport of [{width:390,height:900},{width:430,height:900},{width:768,height:900},{width:1440,height:900},{width:390,height:420}])test('selection interest separates anonymous native and automated signals '+viewport.width+'x'+viewport.height,async({page,browserName})=>{
 await page.setViewportSize(viewport);
 const interest={ok:true,interest:{window_days:30,limited:true,totals:{browser:{page_view:{unique:3,repeat:2},hotel_view:{unique:4,repeat:1},bot_open:{unique:1,repeat:0}},preview:{page_fetch:{unique:2,repeat:0}},robot:{page_fetch:{unique:1,repeat:0}}},variants:[{index:0,hotel_views:4,bot_opens:1}],last_browser_at:'2026-10-23 14:00:00',native:{liked:0,inquiries:0,likes_limited:false,inquiries_limited:false}}};
 const state=await fixture(page,{draft:completeDraft(),interest});
 await page.locator('#replyText').fill('Мой незаконченный ответ');await page.locator('#replyFile').setInputFiles({name:'interest-retained.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic retained file')});
 await open(page);await page.locator('#tsPreview').click();await expect(page.locator('#tsInterest')).toBeVisible();await page.locator('#tsInterest').click();
 await expect(page.locator('#tsInterestPanel')).toContainText('анонимные сигналы браузера');await expect(page.locator('.tsInterestCounts')).toContainText('3 · повторов 2');
 await expect(page.locator('#tsInterestPanel')).toContainText('«Нравится» — 0, обращений — 0');await expect(page.locator('#tsInterestPanel')).toContainText('Достигнут предел выборки');
 await expect(page.locator('#tsInterestPanel')).toContainText('События предпросмотра: 2');await expect(page.locator('#tsInterestPanel')).toContainText('События распознанной автоматизации: 1');
 await expect(page.locator('#tsInterestPanel')).toContainText('23.10.2026 17:00 МСК');
 await page.locator('#tsInterestPanel h3').scrollIntoViewIfNeeded();expect(await page.locator('#tourSelectionDialog').evaluate(el=>el.scrollWidth-el.clientWidth)).toBeLessThanOrEqual(1);
 await expect(page.locator('#tsClose')).toBeInViewport();await expect(page.locator('#tsDone')).toBeInViewport();
 fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({animations:'disabled',path:path.join(root,'manager2-artifacts',`tour-interest-${browserName}-${viewport.width}x${viewport.height}.png`)});
 await page.locator('#tsClose').click();await expect(page.locator('#replyText')).toHaveValue('Мой незаконченный ответ');await expect(page.locator('#mediaSelection')).toContainText('interest-retained.txt');
 expect(state.creations()).toBe(0);expect(state.calls.filter(x=>x.action==='interest')).toHaveLength(1);clean(state,['prepare','interest']);
});

test('selection interest read failure is unknown instead of a false zero',async({page})=>{
 const state=await fixture(page,{draft:completeDraft()});await open(page);await page.locator('#tsPreview').click();await page.locator('#tsInterest').click();
 await expect(page.locator('#tsInterestPanel')).toContainText('не означает, что просмотров не было');await expect(page.locator('.tsInterestCounts')).toHaveCount(0);expect(state.creations()).toBe(0);clean(state,['prepare','interest']);
});

test('public interest observes visible hotels without changing exact bot links or creating native choices',async({page})=>{
 const cap={t:'a'.repeat(64),version:2,expires:Math.floor(Date.now()/1000)+900,nonce:'b'.repeat(32),bucket:'browser',mac:'c'.repeat(64)};
 const offer={index:0,hotel_group:0,hotel:'Синтетический отель',departure:'Москва',date_from:'2027-06-05',nights:7,adults:2,children:0,room:'Standard',meal:'Всё включено',amount:'200000',currency:'RUB',rationale:'',compromise:'',destination:'Турция',flights:'',inclusions:'',constraints:'',observed_at:'',photos:['cover','beach','room'].map(x=>'/'+x+'.jpg'),bot_links:{telegram:'https://t.me/synthetic_bot?start=exact-version-2'}};
 const selection={title:'Синтетическая подборка',intro:'',version:2,updated_at:'2026-10-23 14:00:00',offers:[offer,{...copy(offer),index:1,room:'Suite',amount:'250000'},{...copy(offer),index:2,hotel_group:1,hotel:'Другой отель'}]};
 const html=execFileSync('php',['-r',`$in=json_decode(stream_get_contents(STDIN),true);$selection=$in['selection'];$interest=$in['cap'];$h=static fn(string $v):string=>htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');require getcwd().'/selection/view.php';`],{cwd:root,input:JSON.stringify({selection,cap}),encoding:'utf8'});
 const calls=[],unexpected=[];
 await page.route('**/*',route=>{
  const request=route.request(),url=new URL(request.url()),p=url.pathname;
  if(url.origin==='https://t.me'&&request.isNavigationRequest())return route.abort();
  if(url.origin!=='https://selection-fixture.invalid'){unexpected.push(url.href);return route.abort()}
  if(p==='/selection/')return route.fulfill({contentType:'text/html',body:html});
  if(p==='/selection/activity.php'&&request.method()==='POST'){calls.push(request.postDataJSON());return route.fulfill({contentType:'application/json',body:'{"ok":true,"saved":true}'})}
  if(p==='/selection/activity.js')return route.fulfill({contentType:'application/javascript',body:fs.readFileSync(path.join(root,'selection/activity.js'))});
  if(p==='/selection/selection.css')return route.fulfill({contentType:'text/css',body:fs.readFileSync(path.join(root,'selection/selection.css'))});
  if(/^\/(cover|beach|room)\.jpg$/.test(p))return route.fulfill({contentType:'image/jpeg',body:fs.readFileSync(path.join(root,'assets/owner-tour-cards/compact-v1',p.slice(1)))});
  unexpected.push(p);return route.abort();
 });
 await page.setViewportSize({width:390,height:900});await page.goto('https://selection-fixture.invalid/selection/');
 await expect.poll(()=>calls.filter(x=>x.kind==='page_view').length).toBe(1);
 await page.locator('[data-interest-hotel]').first().scrollIntoViewIfNeeded();await expect.poll(()=>calls.some(x=>x.kind==='hotel_view'&&x.card_index===0)).toBe(true);
 await page.locator('[data-interest-hotel]').last().scrollIntoViewIfNeeded();await expect.poll(()=>calls.some(x=>x.kind==='hotel_view'&&x.card_index===2)).toBe(true);
 expect(calls.some(x=>x.kind==='hotel_view'&&x.card_index===1)).toBe(false);
 const first=calls.find(x=>x.kind==='page_view');await page.reload();await expect.poll(()=>calls.filter(x=>x.kind==='page_view').length).toBe(2);
 const second=calls.filter(x=>x.kind==='page_view')[1];expect(second.visitor_id).toBe(first.visitor_id);expect(second.event_id).not.toBe(first.event_id);
 const link=page.locator('#variant-2 .botActions a');await expect(link).toHaveAttribute('href',offer.bot_links.telegram);await link.click();await expect.poll(()=>calls.some(x=>x.kind==='bot_open'&&x.card_index===1&&x.platform==='telegram')).toBe(true);
 expect(calls.every(x=>['page_view','hotel_view','bot_open'].includes(x.kind))).toBe(true);expect(unexpected).toEqual([]);
});

function knownTrip(){return {state:'saved',revision:'a'.repeat(64),fields:{departure:'Москва',destination:'Турция',date_from:'2027-06-05',nights:7,adults:2,child_ages:[0,6]},details:['Вылет: Москва','Направление: Турция','Состав: 2 взрослых, детей: 2 (0, 6)','Сохранённая дата: 05.06.2027','Ночей: 7','Бюджет поездки: 240000 RUB за всех (не цена предложения)'],warnings:[]};}
for(const viewport of [{width:390,height:900},{width:430,height:900},{width:768,height:900},{width:1440,height:900},{width:390,height:420}])test(`selection explicit trip prefill preserves prior offers and composer ${viewport.width}x${viewport.height}`,async({page,browserName})=>{
 await page.setViewportSize(viewport);const original=completeDraft(),s=await fixture(page,{draft:original,trip:knownTrip()});
 await page.locator('#replyText').fill('Синтетический ответ не отправляется');await page.locator('#replyFile').setInputFiles({name:'trip-unsent.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic only')});
 await open(page);expect(s.calls.filter(x=>x.action.startsWith('trip_'))).toHaveLength(0);
 await page.locator('#tsTripAdd').click();await expect(page.locator('#tsTripApply')).toBeDisabled();await expect(page.locator('#tsEditor')).toContainText('Москва');await expect(page.locator('#tsEditor')).toContainText('не цена предложения');
 await page.locator('#tsTripCancel').click();expect(s.calls.filter(x=>x.action==='save')).toHaveLength(0);expect(s.drafts.get(11).draft).toEqual(original);
 await page.locator('#tsTripAdd').click();await page.locator('#tsTripConfirm').check();await expect(page.locator('#tsTripApply')).toBeEnabled();
 fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({animations:'disabled',path:path.join(root,'manager2-artifacts',`tour-selection-trip-${browserName}-${viewport.width}x${viewport.height}.png`)});
 await page.locator('#tsTripApply').click();await expect(page.locator('#ts-departure')).toHaveValue('Москва');await expect(page.locator('#ts-destination')).toHaveValue('Турция');await expect(page.locator('#ts-date_from')).toHaveValue('2027-06-05');await expect(page.locator('#ts-nights')).toHaveValue('7');await expect(page.locator('#ts-adults')).toHaveValue('2');await expect(page.locator('#ts-child_ages')).toHaveValue('0, 6');
 for(const key of ['hotel','room','meal','amount'])await expect(page.locator('#ts-'+key)).toHaveValue('');
 await save(page);const stored=s.drafts.get(11).draft;expect(stored.offers).toHaveLength(2);expect(stored.offers[0]).toEqual(original.offers[0]);expect(stored.offers[1].photos).toEqual([]);expect(stored.offers[1].import_source).toBeUndefined();
 await page.locator('#tsClose').click();await expect(page.locator('#replyText')).toHaveValue('Синтетический ответ не отправляется');await expect(page.locator('#mediaSelection')).toContainText('trip-unsent.txt');
 await page.reload();await page.locator('#replyText').waitFor({state:'visible'});await open(page);await page.locator('[data-ts-select]').nth(1).click();await expect(page.locator('#ts-child_ages')).toHaveValue('0, 6');clean(s,['trip_context','trip_apply']);
});
test('selection changed trip is not applied and manual path remains available',async({page})=>{
 const s=await fixture(page,{trip:knownTrip(),tripMode:'changed'});await open(page);await page.locator('#tsTripAdd').click();await page.locator('#tsTripConfirm').check();await page.locator('#tsTripApply').click();await expect(page.locator('#tsStatus')).toContainText('изменились');expect(s.calls.filter(x=>x.action==='save')).toHaveLength(0);await expect(page.locator('#tsEmptyAdd')).toBeVisible();await page.locator('#tsEmptyAdd').click();await expect(page.locator('#ts-departure')).toHaveValue('');await page.locator('#tsClose').click();clean(s,['trip_context','trip_apply']);
});
test('selection late trip response cannot append to another client',async({page})=>{
 const s=await fixture(page,{trip:knownTrip(),holdTrip:true});await open(page);await page.locator('#tsTripAdd').click();await page.locator('#tsTripConfirm').check();await page.locator('#tsTripApply').click();await page.locator('#tsClose').click();if(await page.locator('#mobileBack').isVisible())await page.locator('#mobileBack').click();
 await page.locator('#inboxList [data-conversation-id="12"]').click();await open(page);s.release();await expect(page.locator('#tsEmptyAdd')).toBeVisible();expect(s.calls.filter(x=>x.action==='save')).toHaveLength(0);clean(s,['trip_context','trip_apply']);
});

test('selection unbound trip keeps manual editing available without false conditions',async({page})=>{
 const s=await fixture(page);await open(page);await page.locator('#tsTripAdd').click();await expect(page.locator('#tsEditor')).toContainText('Нет подходящих сохранённых условий');await expect(page.locator('#tsTripApply')).toHaveCount(0);await page.locator('#tsTripCancel').click();await page.locator('#tsEmptyAdd').click();await expect(page.locator('#ts-adults')).toHaveValue('');await page.locator('#tsClose').click();clean(s,['trip_context']);
});


test('ANEX actual extractor payload passes canonical PHP source validation and refuses changed forms',async({page})=>{
 const s=await anexSourceFixture(page),first=await s.read();expect(first.error).toBeUndefined();expect(first.value.quote_id).toBe(s.quote);expect(first.value.offer.room).toBe('Standard Room / DBL');expect(first.value.offer.amount).toBe('177323');expect(first.value.url).not.toContain('CLAIM');
 const normalized=JSON.parse(execFileSync('php',['-r',`require 'services/ManagerTourSelectionImportDraft.php';echo json_encode(ManagerTourSelectionImportDraft::normalize(json_decode(stream_get_contents(STDIN),true)),JSON_THROW_ON_ERROR);`],{cwd:root,input:JSON.stringify(first.value),encoding:'utf8'}));expect(normalized.offer.source_label).toBe('ANEX · цена со страницы');expect(normalized.offer.photos).toEqual([]);
 await page.locator('[data-testid=ADULT]').fill('3 взрослых');expect((await s.read()).error).toContain('Условия формы изменились');await page.locator('[data-testid=ADULT]').fill('2 взрослых');
 await page.locator('[data-testid=CHECKIN_BEG]').fill('26.10');expect((await s.read()).error).toContain('точной даты');await page.locator('[data-testid=CHECKIN_BEG]').fill('25.10');
 await page.locator('#nativePrice').evaluate(e=>e.textContent='177 323 ₽ 12 413 ₽');expect((await s.read()).error).toContain('одной суммой');await page.locator('#nativePrice').evaluate(e=>e.textContent='177 323 ₽');
 await page.locator('#info>div').nth(7).locator('button').nth(1).evaluate(e=>e.textContent='2 PAX');expect((await s.read()).error).toContain('размещение');
 expect(s.calls).toEqual([s.url]);
});
for(const width of [390,430,768,1440])test(`ANEX source action saves exact settled row in one click without booking at ${width}`,async({page,browserName})=>{
 await page.setViewportSize({width,height:900});const s=await anexSourceFixture(page);
 await collectorSourceRuntime(page);await page.evaluate(()=>{window.nativeCreates=0;document.querySelector('#nativePrice').onclick=()=>nativeCreates++});
 await page.addScriptTag({path:path.join(root,'extensions/selection-collector/anex-content.js')});await page.getByRole('button',{name:'В подборку AnyTour',exact:true}).click();await expect(page.getByRole('button',{name:'✓ В подборке'})).toBeVisible();
 expect(await page.evaluate(()=>collectorStaged.length)).toBe(1);expect(await page.evaluate(()=>collectorStaged[0].observation.offer.amount)).toBe('177323');expect(await page.evaluate(()=>nativeCreates)).toBe(0);expect(await page.locator('body').innerText()).not.toContain('Синтетическая Анна');
 fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({animations:'disabled',path:path.join(root,'manager2-artifacts',`tour-anex-${browserName}-${width}.png`)});
 expect(await page.evaluate(()=>collectorRequests.map(x=>x.type))).toEqual(['begin_add','enqueue','add_status']);
 await page.getByRole('button',{name:'✓ В подборке'}).click({noWaitAfter:true});await page.locator('#nativePrice').evaluate(e=>e.textContent='180 000 ₽');await expect(page.getByRole('button',{name:'Не добавлено'})).toBeVisible();expect(await page.evaluate(()=>collectorStaged.length)).toBe(1);expect(await page.evaluate(()=>nativeCreates)).toBe(0);
 await page.setViewportSize({width,height:420});await expect(page.getByRole('button',{name:'Не добавлено'})).toBeVisible();expect(s.calls).toEqual([s.url]);
});

for(const width of [390,430,768,1440])test(`ANEX imported terms show their actual source in the existing editor ${width}`,async({page,browserName})=>{
 await page.setViewportSize({width,height:900});const imported=JSON.parse(execFileSync('php',['-r',`require 'tests/support/manager-tour-collector.php';$i=ManagerTourSelectionImportDraft::normalize(anexCollectorObservation());$o=$i['offer'];$o['id']=str_repeat('a',32);$o['import_source']=ManagerTourSelectionImportDraft::source($i);echo json_encode(['title'=>'Смешанная подборка','intro'=>'','offers'=>[$o]],JSON_THROW_ON_ERROR);`],{cwd:root,encoding:'utf8'}));
 const s=await fixture(page,{draft:imported});await page.locator('#replyText').fill('Неотправленный ответ');await open(page);await expect(page.locator('.tsImportedSource')).toContainText('Исходные условия ANEX');await expect(page.locator('.tsImportedSource')).toContainText('177323 RUB');await expect(page.locator('#tsCollector')).toHaveText('Сборщик туров');
 await page.locator('#ts-amount').fill('180000');await save(page);expect(s.drafts.get(11).draft.offers[0].import_source.fields.amount).toBe('177323');
 await page.locator('.tsImportedSource').scrollIntoViewIfNeeded();fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({animations:'disabled',path:path.join(root,'manager2-artifacts',`tour-anex-editor-${browserName}-${width}.png`)});
 await page.locator('#tsClose').click();await expect(page.locator('#replyText')).toHaveValue('Неотправленный ответ');clean(s);
});

test('ANEX form interaction invalidates old result even when displayed day month is unchanged',async({page})=>{
 await anexSourceFixture(page);await collectorSourceRuntime(page);await page.addScriptTag({path:path.join(root,'extensions/selection-collector/anex-content.js')});
 await page.locator('[data-testid=CHECKIN_BEG]').click();await page.getByRole('button',{name:'В подборку AnyTour',exact:true}).click();await expect(page.getByRole('button',{name:'Не добавлено'})).toBeVisible();await expect(page.locator('[data-anytour-note]')).toContainText('обновите страницу');expect(await page.evaluate(()=>collectorStaged.length)).toBe(0);expect(await page.evaluate(()=>collectorRequests.map(x=>x.type))).toEqual(['begin_add']);
});

test('Tourvisor actual snapshot extractor and canonical PHP retain the same source terms',async({page})=>{
 const s=await tourvisorSourceFixture(page),first=await s.read();expect(first.error).toBeUndefined();expect(first.value.quote_id).toBe('tv-snapshot-1234567890');expect(first.value.offer.amount).toBe('160000');expect(first.value.raw.party_price_confirmation).toBe('pending');
 const normalize=o=>JSON.parse(execFileSync('php',['-r',`require 'services/ManagerTourSelectionImportDraft.php';echo json_encode(ManagerTourSelectionImportDraft::normalize(json_decode(stream_get_contents(STDIN),true)),JSON_THROW_ON_ERROR);`],{cwd:root,input:JSON.stringify(o),encoding:'utf8'}));
 expect(normalize(first.value)).toBeNull();const confirmed=copy(first.value);confirmed.raw.party_price_confirmation='one_room_two_adults';const value=normalize(confirmed);expect(value.offer.amount).toBe('160000');expect(value.offer.source_label).toBe('Tourvisor · снимок ANEX');expect(value.offer.constraints).toContain('топливо уже включено');expect(value.offer.photos).toEqual([]);
 await page.reload();await page.locator('.TVTourCardOperatorControlLogoImage').evaluate(e=>e.setAttribute('src','https://tourvisor.ru/pics/operators/mobilelogo/13.svg'));await page.addScriptTag({path:path.join(root,'extensions/selection-collector/tourvisor.js')});expect((await s.read()).value).toEqual(first.value);
 await page.locator('.TVSearchLinkItem').evaluate(e=>e.setAttribute('href',e.getAttribute('href').replace('29.10.2026','28.10.2026')));expect((await s.read()).error).toContain('Точная дата');
});

test('Tourvisor refuses stale menu mismatched handle and changing price basis',async({page})=>{
 const s=await tourvisorSourceFixture(page);const link=await page.locator('.TVOperatorLinkItem').getAttribute('href');await page.locator('.TVOperatorLinkItem').evaluate(e=>e.setAttribute('href',e.getAttribute('href').replace('HOTELLIST=798','HOTELLIST=799')));expect((await s.read()).error).toContain('другой карточке');await page.locator('.TVOperatorLinkItem').evaluate((e,v)=>e.setAttribute('href',v),link);
 await page.locator('.TVTourCardWindow').evaluate(e=>e.setAttribute('data-tvtourlink','https://selection.example/#tvtourid=1234567891'));expect((await s.read()).error).toContain('Снимок');await page.locator('.TVTourCardWindow').evaluate(e=>e.setAttribute('data-tvtourlink','https://selection.example/#tvtourid=1234567890'));
 await page.locator('.TVTourCardWindow').evaluate(e=>e.setAttribute('data-tvtourlink','http://selection.example/#tvtourid=1234567890'));expect((await s.read()).error).toContain('Снимок');await page.locator('.TVTourCardWindow').evaluate(e=>e.setAttribute('data-tvtourlink','https://selection.example/#tvtourid=1234567890'));
 await page.locator('.TVTourCardPriceWaiter').evaluate(e=>e.classList.remove('TVHide'));expect((await s.read()).error).toContain('загружается');await page.locator('.TVTourCardPriceWaiter').evaluate(e=>e.classList.add('TVHide'));
 await page.locator('.TVTourCardIncludePrices').evaluate(e=>e.textContent='Стоимость тура 144 000 РУБ Топливный сбор 17 000 РУБ');expect((await s.read()).error).toContain('топливо');
});

for(const width of [390,430,768,1440])test(`Tourvisor settled snapshot stages without native action at ${width}`,async({page,browserName})=>{
 await page.setViewportSize({width,height:900});await tourvisorSourceFixture(page);await page.evaluate(()=>{window.collectorStaged=[];window.nativeCreates=0;document.querySelector('#nativeContinue').onclick=()=>nativeCreates++;window.chrome={runtime:{sendMessage:async m=>{collectorStaged.push(m);return{ok:true}}}}});
 await page.addScriptTag({path:path.join(root,'extensions/selection-collector/collector-client.js')});await page.addScriptTag({path:path.join(root,'extensions/selection-collector/tourvisor-content.js')});await page.getByRole('button',{name:'В подборку AnyTour',exact:true}).click();await expect(page.getByRole('button',{name:'Проверьте цену и клиента в AnyTour'})).toBeVisible();expect(await page.evaluate(()=>collectorStaged.length)).toBe(1);expect(await page.evaluate(()=>nativeCreates)).toBe(0);expect(await page.locator('body').innerText()).not.toContain('Синтетическая Анна');
 fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({animations:'disabled',path:path.join(root,'manager2-artifacts',`tour-tv-${browserName}-${width}.png`)});
 await page.getByRole('button',{name:'Проверьте цену и клиента в AnyTour'}).click({noWaitAfter:true});await page.locator('.TVTourCardPriceValue').evaluate(e=>e.textContent='161 000');await expect(page.getByRole('button',{name:'Не добавлено'})).toBeVisible();expect(await page.evaluate(()=>collectorStaged.length)).toBe(1);expect(await page.evaluate(()=>nativeCreates)).toBe(0);await page.setViewportSize({width,height:420});await page.getByRole('button',{name:'Не добавлено'}).scrollIntoViewIfNeeded();await expect(page.getByRole('button',{name:'Не добавлено'})).toBeInViewport();
});

test('Tourvisor popup requires explicit room price basis and keeps destination visible',async({page,browserName})=>{
 const ext=path.join(root,'extensions/selection-collector'),data={ok:true,target:{client:'Синтетическая Анна',channel:'telegram',trip:'Синтетическая поездка',count:0},items:[{operation_id:'tv1',state:'destination',target:{client:'Синтетическая Анна',trip:'Синтетическая поездка'},observation:{adapter:'tourvisor-snapshot-v1',offer:{hotel:'Синтетический Tourvisor',date_from:'2026-10-29',nights:11,room:'standard room',amount:'160000',currency:'RUB'},raw:{party_price_confirmation:'pending'}}}]};
 await page.setViewportSize({width:390,height:800});await page.route('https://collector.fixture/**',route=>{const file=new URL(route.request().url()).pathname.slice(1);if(!['popup.html','popup.css','popup.js','price-adjustment.js'].includes(file))return route.abort();return route.fulfill({contentType:file.endsWith('.html')?'text/html':file.endsWith('.css')?'text/css':'application/javascript',body:fs.readFileSync(path.join(ext,file))})});await page.addInitScript(data=>{window.popupCommands=[];window.chrome={runtime:{sendMessage:async m=>{popupCommands.push(m);return m.type==='status'?data:{ok:true}}},storage:{onChanged:{addListener(){}}}}},data);await page.goto('https://collector.fixture/popup.html');
 const confirm=page.getByRole('button',{name:'Подтвердить цену и добавить для этого клиента'});await expect(confirm).toBeDisabled();await expect(page.locator('#items')).toContainText('Цена ТО: 160000 RUB · за номер');await expect(page.locator('#items')).toContainText('Синтетическая Анна');await page.getByRole('checkbox').check();await expect(confirm).toBeEnabled();fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({animations:'disabled',fullPage:true,path:path.join(root,'manager2-artifacts',`tour-tv-popup-${browserName}.png`)});await confirm.click();expect(await page.evaluate(()=>popupCommands.filter(m=>m.type==='confirm'))).toEqual([{type:'confirm',operation_id:'tv1',confirm_price_basis:true}]);
});

for(const width of [390,430,768,1440])test(`Tourvisor source amount stays separate from manager edits at ${width}`,async({page,browserName})=>{
 await page.setViewportSize({width,height:900});const imported=JSON.parse(execFileSync('php',['-r',`require 'tests/support/manager-tour-collector.php';$i=ManagerTourSelectionImportDraft::normalize(tourvisorCollectorObservation());$o=$i['offer'];$o['id']=str_repeat('a',32);$o['import_source']=ManagerTourSelectionImportDraft::source($i);echo json_encode(['title'=>'Три источника','intro'=>'','offers'=>[$o]],JSON_THROW_ON_ERROR);`],{cwd:root,encoding:'utf8'}));
 const s=await fixture(page,{draft:imported});await page.locator('#replyText').fill('Неотправленный ответ');await open(page);await expect(page.locator('.tsImportedSource')).toContainText('Исходные условия Tourvisor');await expect(page.locator('.tsImportedSource')).toContainText('за номер');await page.locator('#ts-amount').fill('170000');await save(page);expect(s.drafts.get(11).draft.offers[0].import_source.fields.amount).toBe('160000');await page.locator('.tsImportedSource').scrollIntoViewIfNeeded();fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({animations:'disabled',path:path.join(root,'manager2-artifacts',`tour-tv-editor-${browserName}-${width}.png`)});await page.locator('#tsClose').click();await expect(page.locator('#replyText')).toHaveValue('Неотправленный ответ');clean(s);
});


for(const width of [390,430,768,1440])test('FUN&SUN ordinary AJAX result adds with compact checkmark without copying a link at '+width,async({page,browserName})=>{
 await page.setViewportSize({width,height:900});const s=await sourceFixture(page,{ajax:true});await s.search();
 const extracted=await s.read();expect(extracted.error).toBeUndefined();expect(extracted.value.url).toContain('PROGRAMINC=0');expect(page.url()).toBe('https://b2b.fstravel.com/search_tour');
 const normalized=JSON.parse(execFileSync('php',['-r',`require 'services/ManagerTourSelectionImportDraft.php';echo json_encode(ManagerTourSelectionImportDraft::normalize(json_decode(stream_get_contents(STDIN),true)),JSON_THROW_ON_ERROR);`],{cwd:root,input:JSON.stringify(extracted.value),encoding:'utf8'}));expect(normalized.offer.amount).toBe('237017');expect(normalized.keys.program).toBe('114');
 await collectorSourceRuntime(page);await page.addScriptTag({path:path.join(root,'extensions/selection-collector/content.js')});await s.search();
 const add=page.getByRole('button',{name:'В подборку AnyTour',exact:true}),edit=page.getByRole('button',{name:'✎ Изменить цену',exact:true});
 const rect=await add.boundingBox(),pencil=await edit.boundingBox();expect(rect.width).toBe(32);expect(rect.height).toBe(32);expect(pencil.y).toBe(rect.y);expect(pencil.width).toBe(32);expect(pencil.x-rect.x).toBe(36);
 expect(await add.evaluate(e=>getComputedStyle(e,'::before').content)).toContain('✓');expect(await edit.evaluate(e=>getComputedStyle(e,'::before').content)).toContain('✎');expect(await add.evaluate(e=>getComputedStyle(e,'::after').content)).toBe('none');
 await add.click();const saved=page.getByRole('button',{name:'✓ В подборке',exact:true});await expect(saved).toHaveAttribute('data-anytour-state','saved');expect(await page.evaluate(()=>collectorStaged.length)).toBe(1);expect(page.url()).toBe('https://b2b.fstravel.com/search_tour');expect(s.calls).toEqual([]);
 fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({animations:'disabled',path:path.join(root,'manager2-artifacts',`tour-compact-ajax-${browserName}-${width}.png`)});
});

test('FUN&SUN search generation refuses loading old rows changed forms and child ages',async({page})=>{
 const s=await sourceFixture(page,{ajax:true});await s.search();expect((await s.read()).value).toBeDefined();
 await page.locator('button.load').evaluate(e=>e.disabled=true);expect((await s.read()).error).toContain('окончания поиска');await page.locator('button.load').evaluate(e=>e.disabled=false);
 await page.locator('[name=CHECKIN_BEG]').fill('04.06.2027');expect((await s.read()).error).toContain('Нажмите «Искать»');await page.locator('[name=CHECKIN_BEG]').fill('05.06.2027');
 // The mutable page link is neither evidence nor a navigation target.
 await page.locator('#samo-link-to-page a').evaluate(e=>e.href='https://evil.example/search_tour?ADULT=2');expect((await s.read()).value).toBeDefined();
 await page.evaluate(()=>window.fixtureHoldSearch=true);await page.getByRole('button',{name:'Искать',exact:true}).click();expect((await s.read()).error).toContain('новых результатов');
 await page.evaluate(()=>{const row=document.querySelector('tr.price_info'),parent=row.parentElement;row.remove();parent.append(row)});expect((await s.read()).error).toContain('новых результатов');
 await page.evaluate(()=>{window.fixtureHoldSearch=false;document.querySelector('[name=CHILD] option').value='1';document.querySelector('tr.price_info').classList.replace('child-0','child-1')});await s.search();expect((await s.read()).value.offer.child_ages).toEqual([4]);
 await page.evaluate(()=>document.querySelector('[name=AGE1] option').value='5');expect((await s.read()).error).toContain('Возраст ребёнка');
 await s.search();expect((await s.read()).value.offer.child_ages).toEqual([5]);
});

test('FUN&SUN changed price during settled capture never enqueues',async({page})=>{
 const s=await sourceFixture(page,{ajax:true});await collectorSourceRuntime(page);await page.addScriptTag({path:path.join(root,'extensions/selection-collector/content.js')});await s.search();
 await page.getByRole('button',{name:'В подборку AnyTour',exact:true}).click({noWaitAfter:true});await page.locator('.price').evaluate(e=>{e.textContent='240000 RUB';e.dataset.convertedPriceNumber='240000'});await expect(page.getByRole('button',{name:'Не добавлено',exact:true})).toBeVisible();expect(await page.evaluate(()=>collectorStaged.length)).toBe(0);
});

test('collector popup requests a readable width before native viewport autosizing',async({page,browserName})=>{
 await page.setViewportSize({width:40,height:600});const s=await pricePopupFixture(page);await expect(page.locator('#target')).toContainText('Синтетическая Анна');
 const geometry=await page.evaluate(()=>({root:document.documentElement.getBoundingClientRect().width,body:document.body.getBoundingClientRect().width,min:getComputedStyle(document.documentElement).minWidth}));expect(geometry.root).toBe(370);expect(geometry.body).toBe(370);expect(geometry.min).toBe('370px');
 await page.setViewportSize({width:390,height:600});await expect(page.getByRole('button',{name:'Открыть подборку',exact:true})).toBeInViewport();await page.getByLabel('Итоговая цена, RUB',{exact:true}).scrollIntoViewIfNeeded();await expect(page.getByLabel('Итоговая цена, RUB',{exact:true})).toBeInViewport();await page.evaluate(()=>scrollTo(0,0));expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);expect(s.commands.every(x=>x.type==='status')).toBe(true);
 fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({animations:'disabled',path:path.join(root,'manager2-artifacts',`tour-popup-width-${browserName}.png`)});
});


function mixedPhotoDraft(){
 const draft=JSON.parse(execFileSync('php',['-r',`require 'tests/support/manager-tour-collector.php';$offers=[];foreach([collectorObservation(),anexCollectorObservation(),tourvisorCollectorObservation()] as $n=>$raw){$i=ManagerTourSelectionImportDraft::normalize($raw);$o=$i['offer'];$o['id']=str_repeat(dechex($n+10),32);$o['import_source']=ManagerTourSelectionImportDraft::source($i);$offers[]=$o;}echo json_encode(['title'=>'Три источника','intro'=>'','offers'=>$offers],JSON_THROW_ON_ERROR);`],{cwd:root,encoding:'utf8'}));
 draft.offers[0].photos=copy(completeDraft().offers[0].photos);
 draft.offers[1].photos=[{kind:'room',url:'https://photos.example.org/anex-room.jpg',illustrative:true}];
 draft.offers[2].photos=[{kind:'cover',url:'https://photos.example.org/own-cover.jpg',illustrative:false},{kind:'room',url:'https://photos.example.org/tourvisor-room.jpg',illustrative:true}];
 return draft;
}
for(const width of [390,430,768,1440])test('selection same-hotel photo reuse keeps mixed source terms and own room at '+width,async({page,browserName})=>{
 await page.setViewportSize({width,height:900});const original=mixedPhotoDraft(),s=await fixture(page,{draft:original});await open(page);
 await page.locator('[data-ts-select="'+original.offers[1].id+'"]').click();await expect(page.locator('[data-ts-copy-photos]')).toHaveCount(0);
 await page.locator('#ts-hotel-group').selectOption('o:'+original.offers[0].id);await expect(page.locator('[data-ts-copy-photos]')).toBeVisible();await expect(page.locator('#ts-photo-cover')).toHaveValue('');
 await page.locator('#tsPhotoReuse').scrollIntoViewIfNeeded();fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({animations:'disabled',path:path.join(root,'manager2-artifacts',`tour-selection-photo-reuse-${browserName}-${width}.png`)});
 await page.locator('[data-ts-copy-photos]').click();await expect(page.locator('#ts-photo-cover')).toHaveValue('https://photos.example.org/cover.jpg');await expect(page.locator('#ts-photo-beach')).toHaveValue('https://photos.example.org/beach.jpg');await expect(page.locator('#ts-photo-room')).toHaveValue('https://photos.example.org/anex-room.jpg');
 await save(page);await page.locator('[data-ts-select="'+original.offers[2].id+'"]').click();const group=s.drafts.get(11).draft.offers[0].hotel_group;await page.locator('#ts-hotel-group').selectOption('g:'+group);
 await page.locator('#tsPhotoSource').selectOption(original.offers[0].id);await page.locator('[data-ts-copy-photos]').click();await expect(page.locator('#ts-photo-cover')).toHaveValue('https://photos.example.org/own-cover.jpg');await expect(page.locator('#ts-photo-room')).toHaveValue('https://photos.example.org/tourvisor-room.jpg');await save(page);
 const stored=s.drafts.get(11).draft;for(let i=0;i<3;i++){const before=copy(original.offers[i]),after=copy(stored.offers[i]);delete before.photos;delete after.photos;delete after.hotel_group;expect(after).toEqual(before);}
 await page.locator('#tsPreview').click();await expect(page.locator('.tsPreviewCard')).toHaveCount(3);await expect(page.locator('.tsPreviewHeading')).toBeInViewport();await expect(page.locator('.tsPreviewCard .tsPhotos').first()).toBeInViewport();await expect(page.locator('#tsSend')).toBeEnabled();expect(s.creations()).toBe(0);expect(s.calls.filter(x=>x.action==='prepare')).toHaveLength(1);clean(s,deliveryActions);
 fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({animations:'disabled',path:path.join(root,'manager2-artifacts',`tour-selection-mixed-photo-preview-${browserName}-${width}.png`)});
});
for(const width of [390,430,768,1440])test('selection preview correction opens exact missing variant and photo field at '+width,async({page,browserName})=>{
 await page.setViewportSize({width,height:900});const draft=completeDraft();draft.offers.push({...copy(draft.offers[0]),id:'b'.repeat(32),hotel:'Другой вариант <b>с моря</b>',room:'',photos:[]});const s=await fixture(page,{draft});await open(page);await page.locator('#tsPreview').click();
 await expect(page.locator('#tsPreviewIssues')).toBeVisible();await expect(page.locator('#tsPreviewIssues [data-ts-fix-offer]')).toHaveCount(2);await expect(page.locator('#tsPreviewIssues b')).toHaveCount(0);
 fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({animations:'disabled',path:path.join(root,'manager2-artifacts',`tour-selection-preview-issues-${browserName}-${width}.png`)});
 await page.getByRole('button',{name:'Вариант 2: три фото',exact:true}).click();await expect(page.locator('#ts-photo-cover')).toBeFocused();await expect(page.locator('#ts-photo-cover')).toBeInViewport();await expect(page.locator('#ts-hotel')).toHaveValue(draft.offers[1].hotel);await expect(page.locator('#ts-photo-room')).toHaveValue('');
 fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({animations:'disabled',path:path.join(root,'manager2-artifacts',`tour-selection-photo-correction-${browserName}-${width}.png`)});
 await page.setViewportSize({width,height:420});await page.getByRole('button',{name:'Вариант 2: номер',exact:true}).click();await expect(page.locator('#ts-room')).toBeFocused();await expect(page.locator('#ts-room')).toBeInViewport();await expect(page.locator('#tsClose')).toBeInViewport();expect(await page.locator('#tourSelectionDialog').evaluate(e=>e.scrollWidth-e.clientWidth)).toBeLessThanOrEqual(1);
 await page.locator('#ts-room').fill('Мой номер');await expect(page.locator('#tsPreviewIssues')).toBeHidden();await page.locator('#tsClose').click();expect(s.creations()).toBe(0);clean(s,deliveryActions);
});
test('selection photo reuse needs explicit current grouping and never copies a room',async({page})=>{
 const draft=completeDraft();draft.offers.push({...copy(draft.offers[0]),id:'b'.repeat(32),photos:[]});const s=await fixture(page,{draft});await open(page);await page.locator('[data-ts-select="'+'b'.repeat(32)+'"]').click();await expect(page.locator('[data-ts-copy-photos]')).toHaveCount(0);
 await page.locator('#ts-hotel-group').selectOption('o:'+draft.offers[0].id);await page.locator('[data-ts-copy-photos]').click();await expect(page.locator('#ts-photo-room')).toHaveValue('');await page.locator('#ts-photo-cover').fill('');await expect(page.locator('[data-ts-copy-photos]')).toBeVisible();await page.locator('#ts-hotel').fill('Иной отель');await expect(page.locator('[data-ts-copy-photos]')).toHaveCount(0);await save(page);
 expect(s.drafts.get(11).draft.offers[1].hotel_group).toBeUndefined();expect(s.drafts.get(11).draft.offers[1].photos.map(x=>x.kind)).toEqual(['beach']);clean(s);
});
test('selection photo reuse stays read only',async({page})=>{
 const draft=completeDraft();draft.offers[0].hotel_group='c'.repeat(32);draft.offers.push({...copy(draft.offers[0]),id:'b'.repeat(32),photos:[]});const s=await fixture(page,{draft,readOnly:true});await open(page);await page.locator('[data-ts-select="'+'b'.repeat(32)+'"]').click();await expect(page.locator('[data-ts-copy-photos]')).toBeDisabled();await expect(page.locator('#tsPhotoSource')).toBeDisabled();await page.locator('#tsClose').click();expect(s.calls.map(x=>x.action)).toEqual(['get']);clean(s);
});

for(const changedContext of ['conversation','actor','auth'])test('selection photo reuse refuses stale '+changedContext,async({page})=>{
 const draft=completeDraft();draft.offers[0].hotel_group='c'.repeat(32);draft.offers.push({...copy(draft.offers[0]),id:'b'.repeat(32),photos:[]});const s=await fixture(page,{draft});await open(page);await page.locator('[data-ts-select="'+'b'.repeat(32)+'"]').click();
 await page.evaluate(mode=>{const s=WorkspaceV2.S;if(mode==='conversation')s.current=12;else if(mode==='actor')s.manager.id=8;else s.authGeneration++;document.querySelector('[data-ts-copy-photos]').click()},changedContext);
 await expect(page.locator('#ts-photo-cover')).toHaveValue('');expect(s.calls.map(x=>x.action)).toEqual(['get']);expect(s.drafts.get(11).draft).toEqual(draft);await page.locator('#tsClose').click();clean(s);
});
test('selection copied hotel photos use ordinary optimistic conflict recovery',async({page})=>{
 const draft=completeDraft();draft.offers[0].hotel_group='c'.repeat(32);draft.offers.push({...copy(draft.offers[0]),id:'b'.repeat(32),photos:[copy(draft.offers[0].photos[2])]});const s=await fixture(page,{draft});await open(page);await page.locator('[data-ts-select="'+'b'.repeat(32)+'"]').click();
 const server=copy(s.drafts.get(11));server.version++;server.draft.offers[1].amount='260000';s.drafts.set(11,server);await page.locator('[data-ts-copy-photos]').click();await page.locator('#tsSave').click();await expect(page.locator('#tsRecovery')).toContainText('другая версия');expect(s.drafts.get(11)).toEqual(server);await expect(page.locator('#ts-photo-cover')).toHaveValue('https://photos.example.org/cover.jpg');
 await expect(page.locator('#tsPreview')).toBeDisabled();await page.locator('#tsClose').click();expect(s.creations()).toBe(0);clean(s);
});


for(const width of [390,430,768,1440])test('selection local photo files save into exact draft and preview without touching composer at '+width,async({page,browserName})=>{
 await page.setViewportSize({width,height:900});const original=completeDraft();original.offers[0].photos=[];const s=await fixture(page,{draft:original});await page.locator('#replyText').fill('Мой ответ клиенту');await page.locator('#replyFile').setInputFiles({name:'chat-file.txt',mimeType:'text/plain',buffer:Buffer.from('synthetic chat')});await open(page);
 for(const kind of ['cover','beach','room']){await page.locator('#ts-upload-'+kind).setInputFiles(path.join(root,'assets/owner-tour-cards/compact-v1',kind+'.jpg'));await expect(page.locator('#tsStatus')).toContainText('Фото добавлено');await expect(page.locator('#tsSaved')).toHaveText('Сохранено на сервере');}
 expect(s.photoUploads).toHaveLength(['cover','beach','room'].reduce((n,k)=>n+Math.ceil(fs.statSync(path.join(root,'assets/owner-tour-cards/compact-v1',k+'.jpg')).size/72000),0));expect(s.uploadedFiles.size).toBe(3);const stored=s.drafts.get(11).draft;expect(stored.offers[0].photos.map(x=>x.kind)).toEqual(['cover','beach','room']);const terms=copy(stored);terms.offers[0].photos=[];expect(terms).toEqual(original);await expect(page.locator('.tsUploadedThumb')).toHaveCount(3);await expect(page.locator('.tsPhotoUrl')).toHaveCount(3);expect(await page.locator('.tsPhotoUrl input').evaluateAll(xs=>xs.every(x=>x.value===''))).toBe(true);
 for(const img of await page.locator('.tsUploadedThumb').all()){await img.scrollIntoViewIfNeeded();await expect.poll(()=>img.evaluate(x=>x.complete&&x.naturalWidth>0)).toBe(true);await img.evaluate(x=>x.decode());}
 await page.locator('#ts-upload-room').scrollIntoViewIfNeeded();fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({animations:'disabled',path:path.join(root,'manager2-artifacts',`tour-selection-photo-files-${browserName}-${width}.png`)});
 await page.setViewportSize({width,height:420});await expect(page.locator('#tsClose')).toBeInViewport();await expect(page.locator('#tsPreview')).toBeInViewport();expect(await page.locator('#tourSelectionDialog').evaluate(x=>x.scrollWidth-x.clientWidth)).toBeLessThanOrEqual(1);await page.setViewportSize({width,height:900});
 await page.locator('#tsPreview').click();await expect(page.locator('.tsPreviewCard')).toHaveCount(1);await expect(page.locator('#tsSend')).toBeEnabled();expect(s.creations()).toBe(0);await page.locator('#tsClose').click();await expect(page.locator('#replyText')).toHaveValue('Мой ответ клиенту');await expect(page.locator('#mediaSelection')).toContainText('chat-file.txt');await open(page);await expect(page.locator('.tsUploadedThumb')).toHaveCount(3);await expect.poll(()=>s.pending.size).toBeLessThanOrEqual(2);clean(s,deliveryActions);
});
test('selection lost photo upload reply retries identical file and operation only explicitly',async({page})=>{
 const draft=completeDraft(),s=await fixture(page,{draft,uploadMode:'after'});await open(page);await page.locator('#ts-upload-cover').setInputFiles(path.join(root,'assets/owner-tour-cards/compact-v1','cover.jpg'));await expect(page.locator('#tsUploadRetry')).toBeEnabled();await expect(page.locator('#tsPreview')).toBeDisabled();await expect(page.locator('#ts-photo-cover')).toHaveValue(draft.offers[0].photos[0].url);expect(s.photoUploads).toHaveLength(1);expect(s.uploadedFiles.size).toBe(1);
 await page.locator('#tsUploadRetry').click();await expect(page.locator('#tsStatus')).toContainText('Фото добавлено');expect(s.photoUploads).toHaveLength(s.photoUploads[0].part_count+1);expect(s.photoUploads[1]).toEqual(s.photoUploads[0]);expect(s.uploadedFiles.size).toBe(1);expect(s.creations()).toBe(0);clean(s);
});
test('selection cancelled uncertain photo keeps prior source and permits manual input',async({page})=>{
 const draft=completeDraft(),s=await fixture(page,{draft,uploadMode:'after'});await open(page);await page.locator('#ts-upload-cover').setInputFiles(path.join(root,'assets/owner-tour-cards/compact-v1','cover.jpg'));await expect(page.locator('#tsUploadRetry')).toBeEnabled();await page.locator('#tsUploadCancel').click();await expect(page.locator('#ts-photo-cover')).toHaveValue(draft.offers[0].photos[0].url);await expect(page.locator('#ts-photo-cover')).toBeEnabled();expect(s.drafts.get(11).draft).toEqual(draft);expect(s.photoUploads).toHaveLength(1);clean(s);
});
test('selection invalid local photo and server rejection preserve previous photo',async({page})=>{
 const draft=completeDraft(),s=await fixture(page,{draft,uploadMode:'invalid'});await open(page);await page.locator('#ts-upload-cover').setInputFiles({name:'bad.svg',mimeType:'image/svg+xml',buffer:Buffer.from('<svg/>')});await expect(page.locator('#tsStatus')).toContainText('JPEG или PNG');expect(s.photoUploads).toHaveLength(0);
 await page.locator('#ts-upload-cover').setInputFiles(path.join(root,'assets/owner-tour-cards/compact-v1','cover.jpg'));await expect(page.locator('#tsStatus')).toContainText('Файл не подходит');await expect(page.locator('#ts-photo-cover')).toHaveValue(draft.offers[0].photos[0].url);expect(s.drafts.get(11).draft).toEqual(draft);clean(s);
});
test('selection late photo response cannot populate a newly opened client',async({page})=>{
 const s=await fixture(page,{draft:completeDraft(),uploadMode:'hold'});await open(page);await page.locator('#ts-upload-cover').setInputFiles(path.join(root,'assets/owner-tour-cards/compact-v1','cover.jpg'));await expect.poll(()=>s.photoUploads.length).toBe(1);await page.locator('#tsClose').click();await page.locator('#inboxList [data-conversation-id="12"]').click();await open(page);s.releaseUpload();await expect(page.locator('#tsEmptyAdd')).toBeVisible();expect(s.drafts.has(12)).toBe(false);expect(s.calls.filter(x=>x.action==='save')).toHaveLength(0);clean(s);
});
test('selection read only cannot choose a file',async({page})=>{
 const s=await fixture(page,{draft:completeDraft(),readOnly:true});await open(page);await expect(page.locator('#ts-upload-cover')).toBeDisabled();await expect(page.locator('#ts-upload-room')).toBeDisabled();expect(s.photoUploads).toHaveLength(0);clean(s);
});

test('selection file chosen during draft save cannot follow the same offer id to another client',async({page})=>{
 const s=await fixture(page,{draft:completeDraft(),fail:'hold'});s.drafts.set(12,{version:1,draft:completeDraft()});await open(page);await page.locator('#ts-hotel').fill('Правка первого клиента');await page.locator('#ts-upload-cover').setInputFiles(path.join(root,'assets/owner-tour-cards/compact-v1','cover.jpg'));await expect.poll(()=>s.calls.filter(x=>x.action==='save').length).toBe(1);await page.locator('#tsClose').click();await page.locator('#inboxList [data-conversation-id="12"]').click();await open(page);s.release();await expect(page.locator('#ts-hotel')).toHaveValue(completeDraft().offers[0].hotel);expect(s.photoUploads).toHaveLength(0);expect(s.drafts.get(12).version).toBe(1);clean(s);
});

test('selection uploaded photo can be removed or replaced by an ordinary source URL',async({page})=>{
 const draft=completeDraft(),s=await fixture(page,{draft});await open(page);await page.locator('#ts-upload-cover').setInputFiles(path.join(root,'assets/owner-tour-cards/compact-v1','cover.jpg'));await expect(page.locator('#tsStatus')).toContainText('Фото добавлено');await page.locator('.tsPhotoSlot').first().locator('summary').click();await page.locator('#ts-photo-cover').fill('https://photos.example.org/replacement.jpg');await save(page);expect(s.drafts.get(11).draft.offers[0].photos[0].url).toBe('https://photos.example.org/replacement.jpg');
 await page.locator('#ts-upload-cover').setInputFiles(path.join(root,'assets/owner-tour-cards/compact-v1','cover.jpg'));await expect(page.locator('#tsStatus')).toContainText('Фото добавлено');await page.locator('[data-ts-remove-photo="cover"]').click();await save(page);expect(s.drafts.get(11).draft.offers[0].photos).toEqual(draft.offers[0].photos.slice(1));await page.locator('#tsPreview').click();await expect(page.locator('#tsStatus')).toContainText('три фото');expect(s.creations()).toBe(0);clean(s,deliveryActions);
});

test('selection lost final photo chunk confirms the same image without another upload operation',async({page})=>{
 const draft=completeDraft(),s=await fixture(page,{draft,uploadMode:'after_final'});await open(page);await page.locator('#ts-upload-cover').setInputFiles(path.join(root,'assets/owner-tour-cards/compact-v1','cover.jpg'));await expect(page.locator('#tsUploadRetry')).toBeEnabled();const count=s.photoUploads.length,last=copy(s.photoUploads[count-1]);expect(count).toBe(last.part_count);expect(s.drafts.get(11).version).toBe(1);await expect(page.locator('#ts-photo-cover')).toHaveValue(draft.offers[0].photos[0].url);
 await page.locator('#tsUploadRetry').click();await expect(page.locator('#tsStatus')).toContainText('Фото добавлено');expect(s.photoUploads).toHaveLength(count+1);expect(s.photoUploads[count]).toEqual(last);expect(s.uploadedFiles.size).toBe(1);expect(s.drafts.get(11).version).toBe(2);clean(s);
});

function automaticPhotoDraft(count=3){
 const code=`require 'tests/support/manager-tour-collector.php';$o=collectorObservation();$base='https://apigate-tui.fstravel.com/api/geocontent/static/Hotel/00180000-ac11-0242-6c05-08d9934f9e4b/';$o['photos']=array_slice(array_map(static fn($k)=>$base.$k.'-Source-automatic.jpg',['MainPhoto','Gallery','InsideOfTheRoom']),0,(int)$argv[1]);$i=ManagerTourSelectionImportDraft::normalize($o);if(!$i)throw new RuntimeException('invalid automatic fixture');$offer=$i['offer'];$offer['id']=str_repeat('a',32);$offer['import_source']=ManagerTourSelectionImportDraft::source($i);echo json_encode(['title'=>'Автоматические фото','intro'=>'','offers'=>[$offer]],JSON_THROW_ON_ERROR);`;
 return JSON.parse(execFileSync('php',['-r',code,String(count)],{cwd:root,encoding:'utf8'}));
}
for(const width of [390,1440])for(const count of [1,2])test('automatic gallery public '+count+' photos keeps hotel and price visible at '+width,async({page,browserName})=>{
 const draft=automaticPhotoDraft(count),built=JSON.parse(execFileSync('php',['-r',`require 'services/ManagerTourSelectionPublicationDraft.php';echo json_encode(ManagerTourSelectionPublicationDraft::build(json_decode(stream_get_contents(STDIN),true)),JSON_THROW_ON_ERROR);`],{cwd:root,input:JSON.stringify(draft),encoding:'utf8'}));expect(built.ok).toBe(true);
 const selection={title:'Отдых у моря',intro:'',version:1,updated_at:'2026-10-25 12:00:00',offers:[{...built.offers[0].snapshot,index:0,children:0,photo_layout:'gallery-v1',destination:'Синтетический курорт',flights:'Рейсы уточняются',inclusions:'Трансфер',constraints:'Доплаты требуют проверки',observed_at:'2026-10-25T10:00:00Z',photos:['cover','beach'].slice(0,count).map(x=>'/'+x+'.jpg')}]};
 const html=execFileSync('php',['-r',`$selection=json_decode(stream_get_contents(STDIN),true);$h=static fn($s):string=>htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');require 'selection/view.php';`],{cwd:root,input:JSON.stringify(selection),encoding:'utf8'}),unexpected=[],errors=[];page.on('pageerror',e=>errors.push(String(e)));expect(html).not.toContain('Warning:');
 await page.route('**/*',route=>{const request=route.request(),url=new URL(request.url()),p=url.pathname;if(url.origin==='https://selection-fixture.invalid'&&request.method()==='GET'){if(p==='/selection/')return route.fulfill({contentType:'text/html',body:html});if(p==='/selection/selection.css')return route.fulfill({contentType:'text/css',body:fs.readFileSync(path.join(root,'selection/selection.css'))});if(/^\/(cover|beach)\.jpg$/.test(p))return route.fulfill({contentType:'image/jpeg',body:fs.readFileSync(path.join(root,'assets/owner-tour-cards/compact-v1',p.slice(1)))});}unexpected.push({url:request.url(),method:request.method()});return route.abort()});
 await page.setViewportSize({width,height:900});await page.goto('https://selection-fixture.invalid/selection/');await expect(page.locator('.photos img')).toHaveCount(count);await expect.poll(()=>page.locator('.photos img').evaluateAll(xs=>xs.every(x=>x.complete&&x.naturalWidth>0))).toBe(true);await page.locator('.photos img').evaluateAll(xs=>Promise.all(xs.map(x=>x.decode())));
 const price=await page.locator('.price').boundingBox();expect(price.y+price.height).toBeLessThan(900);expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);await expect(page.locator('figcaption')).toHaveCount(0);expect(unexpected).toEqual([]);expect(errors).toEqual([]);
 fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({path:path.join(root,'manager2-artifacts',`tour-selection-auto-public-${count}-${browserName}-${width}.png`)});
});
for(const source of [{name:'FUNSUN',fixture:sourceFixture,script:'content.js'},{name:'ANEX',fixture:anexSourceFixture,script:'anex-content.js'}])test('automatic gallery '+source.name+' one click captures source photos without file input',async({page})=>{
 await source.fixture(page);await collectorSourceRuntime(page);await page.addScriptTag({path:path.join(root,'extensions/selection-collector',source.script)});
 await page.getByRole('button',{name:'В подборку AnyTour',exact:true}).click();await expect(page.getByRole('button',{name:'✓ В подборке',exact:true})).toBeVisible();
 const observations=await page.evaluate(()=>collectorStaged.map(x=>x.observation));expect(observations).toHaveLength(1);expect(observations[0].photos).toHaveLength(3);expect(new Set(observations[0].photos).size).toBe(3);expect(await page.locator('iframe').count()).toBe(0);expect(await page.locator('input[type=file]').count()).toBe(0);
 expect(await page.locator('body').innerText()).not.toContain('Синтетическая Анна');
});
test('automatic gallery source changes while photos load cannot enqueue a stale offer',async({page})=>{
 await sourceFixture(page);let release;await page.route('https://b2b.fstravel.com/hotel/10',async route=>{await new Promise(r=>release=r);await route.fulfill({contentType:'text/html',body:'<img class="slider-img" src="https://apigate-tui.fstravel.com/api/geocontent/static/Hotel/00180000-ac11-0242-6c05-08d9934f9e4b/MainPhoto-Source-test.jpg">'})});
 await collectorSourceRuntime(page);await page.addScriptTag({path:path.join(root,'extensions/selection-collector/content.js')});await page.getByRole('button',{name:'В подборку AnyTour',exact:true}).click();await expect.poll(()=>!!release).toBe(true);
 await page.locator('.price').evaluate(e=>{e.setAttribute('data-converted-price-number','250000');e.textContent='250 000 RUB'});release();await expect(page.locator('[data-anytour-note]')).toContainText('Условия изменились');expect(await page.evaluate(()=>collectorStaged.length)).toBe(0);
});
test('automatic gallery Tourvisor copies only exact card photo prefixes',async({page})=>{
 await tourvisorSourceFixture(page,{photos:true});const value=await page.evaluate(()=>AnyTourTourvisor.extract(document,location.href,'2026-10-24T11:00:00Z'));expect(value.photos).toHaveLength(3);value.raw.party_price_confirmation='one_room_two_adults';
 const normalized=JSON.parse(execFileSync('php',['-r',`require 'services/ManagerTourSelectionImportDraft.php';echo json_encode(ManagerTourSelectionImportDraft::normalize(json_decode(stream_get_contents(STDIN),true)),JSON_THROW_ON_ERROR);`],{cwd:root,input:JSON.stringify(value),encoding:'utf8'}));expect(normalized.offer.photos).toHaveLength(3);expect(normalized.photos).toEqual(value.photos);
 await page.locator('.TVPreviewGalleryItem').first().evaluate(e=>e.style.backgroundImage='url("https://static.tourvisor.ru/hotel_pics/verybig/1/503-1.jpg")');expect((await page.evaluate(()=>AnyTourTourvisor.extract(document,location.href))).photos).toHaveLength(2);
});
for(const width of [390,430,768,1440])test('automatic gallery editor is ready without manual uploads at '+width,async({page,browserName})=>{
 await page.setViewportSize({width,height:900});const draft=automaticPhotoDraft(),s=await fixture(page,{draft});await open(page);await expect(page.locator('.tsAutomaticPhotoStatus')).toContainText('Фото добавлены автоматически');await expect(page.locator('.tsAutomaticPhotos .tsUploadedThumb')).toHaveCount(3);
 await page.locator('.tsAutomaticPhotos').evaluate(el=>el.scrollIntoView({block:'start'}));await expect.poll(()=>page.locator('.tsAutomaticPhotos img').evaluateAll(xs=>xs.every(x=>x.complete&&x.naturalWidth>0))).toBe(true);
 expect(await page.locator('.tsPhotoReplace').evaluateAll(xs=>xs.every(x=>!x.open))).toBe(true);expect(await page.locator('body').evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
 fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({path:path.join(root,'manager2-artifacts',`tour-selection-auto-photos-${browserName}-${width}.png`)});
 await page.locator('#tsPreview').click();await expect(page.locator('.tsPhotos img')).toHaveCount(3);await expect(page.locator('.tsCardText')).toContainText('Фотографии отеля.');expect(s.photoUploads).toHaveLength(0);expect(s.creations()).toBe(0);clean(s,deliveryActions);
});
for(const channel of ['telegram','max'])for(const count of [1,2,3])test('automatic gallery '+channel+' preview uses '+count+' actual photos',async({page,browserName})=>{
 await page.setViewportSize({width:390,height:900});const draft=automaticPhotoDraft(count),s=await fixture(page,{draft,channel});await page.evaluate(c=>{WorkspaceV2.S.detail.conversation.channel=c},channel);await open(page);await page.locator('#tsPreview').click();await expect(page.locator('.tsPhotos img')).toHaveCount(count);await expect(page.locator('#tsSend')).toBeEnabled();await expect(page.locator('.tsCardText')).toContainText('Интерьер выбранного номера может отличаться');await expect(page.locator('.tsPhotos')).toHaveClass(new RegExp('tsPhotoCount'+count));
 if(count===1){await expect(page.locator('.tsPhotoNav')).toHaveCount(0);fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({path:path.join(root,'manager2-artifacts',`tour-selection-auto-one-${channel}-${browserName}-390.png`)})}
 expect(s.photoUploads).toHaveLength(0);expect(s.creations()).toBe(0);clean(s,deliveryActions);
});
test('automatic gallery replacement is optional and keeps original source material',async({page})=>{
 const draft=automaticPhotoDraft(2),s=await fixture(page,{draft});await page.route('https://photos.example.org/replacement.jpg',route=>route.fulfill({contentType:'image/jpeg',body:fs.readFileSync(path.join(root,'assets/owner-tour-cards/compact-v1/cover.jpg'))}));await open(page);await page.locator('.tsPhotoReplace').first().locator('summary').click();await page.locator('#ts-photo-cover').fill('https://photos.example.org/replacement.jpg');await save(page);expect(s.drafts.get(11).draft.offers[0].photos[0].url).toBe('https://photos.example.org/replacement.jpg');expect(s.drafts.get(11).draft.offers[0].import_source).toEqual(draft.offers[0].import_source);expect(s.photoUploads).toHaveLength(0);clean(s);
});

test('automatic gallery unavailable source never asks for three manual uploads',async({page})=>{
 const draft=automaticPhotoDraft();draft.offers[0].photos=[];draft.offers[0].import_source.photos=[];const s=await fixture(page,{draft});await open(page);await expect(page.locator('.tsAutomaticPhotoStatus')).toContainText('повторно');expect(await page.locator('.tsPhotoFields input[type=file]').count()).toBe(0);
 await page.locator('#tsPreview').click();await expect(page.locator('#tsStatus')).toContainText('фото отеля');await expect(page.locator('#tsStatus')).not.toContainText('три фото');await expect(page.locator('#tsSend')).toBeHidden();expect(s.creations()).toBe(0);clean(s,deliveryActions);
});
