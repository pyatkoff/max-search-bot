const {test,expect}=require('@playwright/test');
const fs=require('node:fs'),path=require('node:path');
const {execFileSync}=require('node:child_process');
const {setup,root}=require('./fixtures/internal-notes');
const copy=x=>JSON.parse(JSON.stringify(x));
const {sourceFixture,bridgeFixture,anexSourceFixture,tourvisorSourceFixture}=require('./fixtures/selection-collector');
const {worker:collectorWorker,provider:collectorProvider,makeTarget:collectorTarget}=require('../run_manager_tour_collector_behavior_regression');
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
async function canonicalCollectorSourceRuntime(page){
 // Reuse the actual worker harness with its persistent storage and synthetic
 // receipt bridge; the browser still runs the shipped source caller/extractor.
 const db={},server=collectorProvider(),requests=[],sender={url:page.url(),tab:{id:22},documentId:'tourvisor-browser-document',frameId:0};
 let worker=collectorWorker(db,server,{popupSupported:false}),captured=0;await worker.connect(collectorTarget());
 await page.exposeFunction('collectorSourceCommand',async message=>{requests.push(copy(message));const result=await worker.send(message,sender);if(message.type==='begin_add'&&result.ok)captured++;return result});
 await page.evaluate(()=>{window.chrome={runtime:{sendMessage:message=>window.collectorSourceCommand(message),onMessage:{addListener(){}}}}});
 await page.addScriptTag({path:path.join(root,'extensions/selection-collector/collector-client.js')});
 return {db,server,requests,captureCount:()=>captured,command:message=>worker.send(message),
  restart:async()=>{worker=collectorWorker(db,server,{popupSupported:false});await worker.connect(db.target,false)},
  changeTarget:()=>worker.connect(collectorTarget(12,'b'.repeat(32)))};
}
async function pricePopupFixture(page,options={}){
 const target={actor:7,conversation_id:11,binding:'b'.repeat(64),connection:{id:'c'.repeat(32)},client:'Синтетическая Анна',trip:'Лето у моря',channel:'telegram',count:0},operation='e'.repeat(32),tv=options.tv===true;
 const item={operation_id:operation,state:options.state||'editing',needs_edit_confirmation:options.state!=='destination',target:copy(target),observation:{adapter:tv?'tourvisor-snapshot-v1':'funsun-b2b-v1',offer:{hotel:'Синтетический отель',date_from:'2027-06-05',nights:7,room:'Standard / 2AD',amount:'237017',currency:'RUB'},raw:tv?{party_price_confirmation:'pending'}:{price:'237017 RUB'}},...(options.error?{error:options.error}:{})};
 const state={ok:true,target,items:[item]},commands=[];let lost=options.lost===true,holdStatus=false,releaseStatus=null,statusFailure='';
 await page.route('https://collector.fixture/**',async route=>{
  const file=new URL(route.request().url()).pathname.slice(1);
  if(file==='fixture-command'){
   const m=route.request().postDataJSON();commands.push(copy(m));let reply={ok:true};
   if(m.type==='status'){reply=copy(state);if(statusFailure){const failure=statusFailure;statusFailure='';if(failure==='lost')return route.abort('failed');reply.ok=false}if(holdStatus){holdStatus=false;await new Promise(resolve=>{releaseStatus=resolve})}}
   else if(m.type==='edit_draft'&&item.state==='editing')item.edit_draft=copy(m.draft);
   else if(m.type==='remove'){item.state='cancelled';delete item.edit_draft;}
   else if(m.type==='save_edit'&&item.state==='editing'){
    item.manager_amount=m.manager_amount;item.needs_edit_confirmation=false;item.state=options.result||'saved';if(item.state==='saved')target.count=1;
    if(lost){lost=false;return route.abort('failed')}
   }else if(m.type==='confirm'&&item.state==='destination'){
    if(tv&&m.confirm_price_basis!==true)reply={ok:false};
    else if(options.confirmFailure==='reject')reply={ok:false};
    else if(options.confirmFailure==='lost')return route.abort('failed');
    else{item.state='saved';target.count++;}
   }else if(m.type==='open_manager')reply={ok:options.openFailure!==true};
   else reply={ok:false};
   return route.fulfill({contentType:'application/json',body:JSON.stringify(reply)});
  }
  if(!['popup.html','popup.css','popup.js','price-adjustment.js'].includes(file))return route.abort();
  return route.fulfill({contentType:file.endsWith('.html')?'text/html':file.endsWith('.css')?'text/css':'application/javascript',body:fs.readFileSync(path.join(root,'extensions/selection-collector',file))});
 });
 await page.addInitScript(()=>{
  const listeners=[];window.pricePopupRefresh=()=>{for(const fn of listeners)fn()};window.chrome={runtime:{sendMessage:async m=>{const response=await fetch('/fixture-command',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(m)});const r=await response.json();if(['edit_draft','save_edit','remove','confirm'].includes(m.type)&&r.ok)for(const fn of listeners)queueMicrotask(()=>fn());return r}},storage:{onChanged:{addListener(fn){listeners.push(fn)}}}};
 });
 await page.goto('https://collector.fixture/popup.html');return{state,commands,operation,item,failNextStatus:mode=>{statusFailure=mode},holdNextStatus:()=>{holdStatus=true},statusHeld:()=>releaseStatus!==null,releaseStatus:()=>{releaseStatus();releaseStatus=null},refresh:async()=>{const prior=await page.locator('.item').first().elementHandle();await page.evaluate(()=>pricePopupRefresh());await expect.poll(()=>prior.evaluate(node=>node.isConnected)).toBe(false)}};
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
function biblioFixture(){
 return JSON.parse(execFileSync('php',['-r',`require getcwd().'/tests/support/manager-tour-collector.php';require getcwd().'/services/ManagerTourSelectionBiblioSource.php';$f=biblioPublicFixture(true);$o=ManagerTourSelectionBiblioSource::parse($f['html'],$f['url'])[0];$o['photos']=$f['photos'];$n=ManagerTourSelectionImportDraft::normalize($o);$offer=$n['offer'];$offer['id']=str_repeat('b',32);$offer['import_source']=ManagerTourSelectionImportDraft::source($n);echo json_encode(['url'=>$f['url'],'offer'=>$o['offer'],'raw'=>$o['raw'],'photos'=>$f['photos'],'saved'=>$offer],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);`],{cwd:root,encoding:'utf8'}));
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
  const heldBiblioImages=[];let biblioImagesSettled=0;
  if(options.imageExportCrossOrigin)await page.route('https://image-export.fixture/**',route=>route.fulfill({contentType:'image/jpeg',body:fs.readFileSync(path.join(root,'assets/owner-tour-cards/compact-v1/cover.jpg'))}));
  if(options.biblio)await page.route('https://www.bgoperator.ru/pr_img/**',async route=>{const broken=options.biblioImageError===true,tiny=options.biblioTinyImages===true;if(options.holdBiblioImages)await new Promise(resolve=>heldBiblioImages.push(resolve));try{return await(broken?route.fulfill({status:404,body:''}):route.fulfill({contentType:tiny?'image/png':'image/jpeg',body:tiny?Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=','base64'):fs.readFileSync(path.join(root,'assets/owner-tour-cards/compact-v1/cover.jpg'))}))}finally{biblioImagesSettled++}});
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
    if(options.biblio&&d.action.startsWith('biblio_')){
      const b=options.biblio,row={offer:copy(b.offer),raw:copy(b.raw),ticket:'quote-fixture-ticket'};
      if(d.action==='biblio_search'){expect(d.url).toBe(b.url);if(options.holdBiblio)await new Promise(resolve=>held=resolve);return reply({ok:true,offers:[row],total:1,next_offset:null,version:snapshot(d.conversation_id).version});}
      if(d.action==='biblio_review')return reply(options.biblioPhotosDenied?{ok:false,error:'biblio_photos_unavailable'}:{ok:true,...row,ticket:'ready-fixture-ticket',photos:copy(b.photos),version:snapshot(d.conversation_id).version},options.biblioPhotosDenied?422:200);
      expect(d.action).toBe('biblio_add');expect(d.ticket).toBe('ready-fixture-ticket');expect(d.confirm_party_price).toBe(true);
      if(options.biblioLoss==='before'){options.biblioLoss='';return route.abort('failed')}
      if(!receipts.has(d.operation_id)){const before=snapshot(d.conversation_id),offer=copy(b.saved);offer.amount=d.manager_amount;const value={version:before.version+1,draft:{...before.draft,offers:[...before.draft.offers,offer]}};drafts.set(d.conversation_id,value);receipts.set(d.operation_id,copy(value));}
      if(options.biblioLoss==='after'){options.biblioLoss='';return route.abort('failed')}
      return reply({ok:true,state:'added',saved_version:receipts.get(d.operation_id).version,count:snapshot(d.conversation_id).draft.offers.length});
    }
    if(d.action==='trip_context')return reply({ok:true,trip:options.trip||{state:'unbound',revision:'',fields:{},details:[],warnings:[]}});
    if(d.action==='trip_apply'){if(options.tripMode==='changed')return reply({ok:false,error:'trip_changed'},409);if(options.tripMode==='denied')return reply({ok:false,error:'forbidden'},403);if(d.confirm_current_trip!==true||d.revision!==options.trip?.revision)throw new Error('Unconfirmed trip');if(options.holdTrip)await new Promise(resolve=>held=resolve);return reply({ok:true,trip:options.trip});}
    if(d.action==='get')return reply({ok:true,selection:snapshot(d.conversation_id)});
    if(d.action==='share')return reply({ok:true,share:copy(share)});
    if(['publish','revoke'].includes(d.action)){
      if(options.readOnly)return reply({ok:false,error:'forbidden'},403);
      if(d.share_version!==share.version)return reply({ok:false,error:'share_changed'},409);
      share={version:share.version+1,active:d.action==='publish',publication_id:d.publication_id,path:d.action==='publish'?'/selection/?t='+'d'.repeat(64):null};
      if(share.active&&options.hostedShare){share.public_url=options.hostedShare==='spoof'?'https://other.invalid/?t='+'d'.repeat(64):'https://tours.anytoour.ru/?t='+'d'.repeat(64);share.preview_path=share.path+'&preview=1800000000.'+'e'.repeat(64);share.preview_url=share.public_url+'&preview=1800000000.'+'e'.repeat(64);}
      if(options.shareMode==='after'){options.shareMode='';return route.abort('failed')}
      return reply({ok:true,share:copy(share)});
    }
    if(d.action==='operation')return reply({ok:true,found:receipts.has(d.operation_id),saved_version:receipts.get(d.operation_id)?.version??null,selection:snapshot(d.conversation_id)});
    if(d.action==='prepare'){
      const built=JSON.parse(execFileSync('php',['-r',`require getcwd().'/services/ManagerTourSelectionPublicationDraft.php'; $draft=json_decode(stream_get_contents(STDIN),true); $result=ManagerTourSelectionPublicationDraft::build($draft); if ($result['ok']) foreach ($result['offers'] as &$offer) $offer['card']=TourOfferCardRenderer::render($offer['snapshot'],$offer['presentation'],'tour_like_1_'.str_repeat('a',24)); echo json_encode($result,JSON_THROW_ON_ERROR);`],{cwd:root,input:JSON.stringify(snapshot(d.conversation_id).draft),encoding:'utf8'}));
      if(!built.ok)return reply(built,422);
      if(options.imageExportLongText)built.offers[0].card.plain_text+='\n'+'Длинное условие\n'.repeat(300);
      publication=publication||{id:'a'.repeat(32),draft_version:d.version,title:built.title,channel:options.channel||'telegram',can_send:!options.readOnly,cards:built.offers.map((offer,i)=>({index:i,offer_id:i+1,state:'pending',snapshot:offer.snapshot,text:offer.card.plain_text,photos:offer.presentation.photos.map((_,p)=>'tour-selection-photo.php?publication_id='+'a'.repeat(32)+'&card='+i+'&photo='+p+'&conversation_id='+d.conversation_id),...(offer.presentation.layout==='gallery-v1'?{photo_layout:'gallery-v1'}:{})}))};
      if(options.imageExportCrossOrigin)publication.cards[0].photos=publication.cards[0].photos.map((_,index)=>'https://image-export.fixture/photo-'+index+'.jpg');
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
  return {...base,calls,drafts,receipts,snapshot,photoUploads,uploadedFiles,releaseUpload:()=>heldUpload?.(),creations:()=>creations,publication:()=>publication,heldPhotos:()=>heldPhotos.length,holdPhotos:()=>{options.holdPhotos=true},releasePhotos:()=>{options.holdPhotos=false;heldPhotos.splice(0).forEach(resolve=>resolve())},biblioImagesHeld:()=>heldBiblioImages.length,biblioImagesSettled:()=>biblioImagesSettled,releaseBiblioImages:()=>{options.holdBiblioImages=false;heldBiblioImages.splice(0).forEach(resolve=>resolve())},release:()=>held?.(),setFail:x=>fail=x,setTripMode:x=>options.tripMode=x};
}
async function open(page,{edit=true}={}){if(!await page.locator('#tourSelectionOpen').isVisible())await page.locator('#m2ConversationMenuToggle').click();await page.locator('#tourSelectionOpen').click();await expect(page.locator('#tourSelectionDialog')).toBeVisible();await expect(page.locator('#tsStatus')).toContainText(/загружен|Просмотр|Восстановлены/);if(edit&&await page.locator('#tsOfferDetails').count()&&!await page.locator('#tsOfferDetails').evaluate(el=>el.open))await page.locator('#tsOfferDetails>summary').click()}

async function biblioReview(page,b,{waitPhotos=true}={}){
 await open(page,{edit:false});await page.locator('#tsBiblio').click();await page.locator('#tsBiblioUrl').fill(b.url);await page.locator('#tsBiblioRead').click();await page.locator('[data-biblio-choice]').click();await expect(page.locator('#tsBiblioAdd')).toBeDisabled();await expect(page.locator('.tsBiblioPhotos img')).toHaveCount(b.photos.length);if(waitPhotos)await expect.poll(()=>page.locator('.tsBiblioPhotos img').evaluateAll(images=>images.every(img=>img.complete&&img.naturalWidth>0))).toBe(true);
}
async function savedGalleryPhotos(page,s,count){
 await expect(page.locator('#tsOfferDetails')).toBeVisible();if(!await page.locator('#tsOfferDetails').evaluate(el=>el.open))await page.locator('#tsOfferDetails > summary').click();
 const thumbnails=page.locator('.tsAutomaticPhotos .tsUploadedThumb');await expect(thumbnails).toHaveCount(count);for(const thumb of await thumbnails.all()){await thumb.scrollIntoViewIfNeeded();await expect.poll(()=>thumb.evaluate(img=>img.complete&&img.naturalWidth>0)).toBe(true)}
 const sources=new Set(await thumbnails.evaluateAll(images=>images.map(img=>img.currentSrc||img.src)));await expect.poll(()=>Array.from(s.pending).filter(request=>sources.has(request.url())).length).toBe(0);
}
for(const [width,height]of [[390,900],[430,900],[768,900],[1440,900],[390,420]])test(`Biblio direct quote photos terms and edited price ${width}x${height}`,async({page,browserName})=>{
 await page.setViewportSize({width,height});const b=biblioFixture(),original=completeDraft(),s=await fixture(page,{draft:original,biblio:b});await biblioReview(page,b);
 await expect(page.locator('.tsBiblioTerms')).toContainText('дети: 7');await expect(page.locator('.tsBiblioTerms')).toContainText('40000 EUR');await expect(page.locator('.tsBiblioTerms')).toContainText('трансфер');await expect(page.locator('#tsBiblioResults')).toContainText('123');
 expect(s.calls.some(x=>['biblio_add','save','prepare','send','publish','collector_import'].includes(x.action))).toBe(false);
 await page.locator('#tsBiblioResults article').evaluate(el=>el.scrollIntoView({block:'start'}));fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({animations:'disabled',path:path.join(root,'manager2-artifacts',`biblio-overview-${browserName}-${width}x${height}.png`)});
 await page.locator('#tsBiblioRead').focus();await page.keyboard.press('Tab');await expect(page.locator('#tsBiblioAmount')).toBeFocused();await page.locator('#tsBiblioAmount').fill('125 000,50');const bounds=await page.locator('#tsBiblioAmount').evaluate(el=>{const input=el.getBoundingClientRect(),viewport=el.closest('.tsEditor').getBoundingClientRect();return{top:input.top,bottom:input.bottom,viewportTop:viewport.top,viewportBottom:viewport.bottom,viewportHeight:viewport.height}});expect(bounds.viewportHeight).toBeGreaterThan(120);expect(bounds.top).toBeGreaterThanOrEqual(bounds.viewportTop-1);expect(bounds.bottom).toBeLessThanOrEqual(bounds.viewportBottom+1);await page.locator('#tsBiblioConfirm').check();await expect(page.locator('#tsBiblioAdd')).toBeEnabled();
 expect(await page.locator('#tourSelectionDialog').evaluate(el=>el.scrollWidth-el.clientWidth)).toBeLessThanOrEqual(1);
 for(const id of ['tsBiblioAdd','tsBiblioBack','tsBiblioCancel'])expect(await page.locator('#'+id).evaluate(el=>el.getBoundingClientRect().height)).toBeGreaterThanOrEqual(44);
 await page.locator('#tsBiblioAdd').scrollIntoViewIfNeeded();fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({animations:'disabled',path:path.join(root,'manager2-artifacts',`biblio-review-${browserName}-${width}x${height}.png`)});
 await page.locator('#tsBiblioAdd').click();await expect.poll(()=>s.snapshot(11).draft.offers.length).toBe(2);await expect(page.locator('[data-ts-select]')).toHaveCount(2);expect(s.snapshot(11).draft.offers[0]).toEqual(original.offers[0]);const offer=s.snapshot(11).draft.offers[1];expect(offer.amount).toBe('125000.50');expect(offer.import_source.fields.amount).toBe('123456');expect(offer.photos).toHaveLength(3);expect(s.calls.filter(x=>x.action==='biblio_add')).toHaveLength(1);expect(s.calls.some(x=>['send','publish','collector_import'].includes(x.action))).toBe(false);await page.locator('[data-ts-select]').nth(1).click();await expect(page.locator('.tsImportedSource')).toContainText('Библио-Глобус');
 await savedGalleryPhotos(page,s,b.photos.length);
 expect(s.errors).toEqual([]);clean(s,['biblio_search','biblio_review','biblio_add']);
});
for(const loss of ['before','after'])test('Biblio uncertain add reconciles before exact retry '+loss,async({page})=>{
 const b=biblioFixture(),s=await fixture(page,{biblio:b,biblioLoss:loss});await biblioReview(page,b);await page.locator('#tsBiblioConfirm').check();await page.locator('#tsBiblioAdd').click();await expect(page.locator('#tsRecovery')).toContainText('Сервер мог сохранить');const first=copy(s.calls.find(x=>x.action==='biblio_add'));expect(s.calls.filter(x=>x.action==='biblio_add')).toHaveLength(1);
 await page.locator('#tsClose').click();await page.reload();await expect.poll(()=>page.evaluate(()=>!!WorkspaceV2.S.workspaceBound&&!WorkspaceV2.S.booting)).toBe(true);if(!await page.locator('#tourSelectionOpen').count())await page.locator('#inboxList [data-conversation-id="11"]').click();await open(page,{edit:false});expect(s.calls.filter(x=>x.action==='biblio_add')).toHaveLength(1);await page.getByRole('button',{name:'Проверить сохранение',exact:true}).click();
 if(loss==='before'){await page.getByRole('button',{name:'Повторить это сохранение',exact:true}).click();await expect.poll(()=>s.calls.filter(x=>x.action==='biblio_add').length).toBe(2);expect(s.calls.filter(x=>x.action==='biblio_add')[1]).toEqual(first)}
 await expect(page.locator('#tsRecovery')).toBeHidden();expect(s.snapshot(11).draft.offers).toHaveLength(1);expect(s.calls.filter(x=>x.action==='biblio_add')).toHaveLength(loss==='after'?1:2);expect(s.calls.some(x=>['send','publish','collector_import'].includes(x.action))).toBe(false);await savedGalleryPhotos(page,s,b.photos.length);clean(s,['biblio_search','biblio_review','biblio_add']);
});
test('Biblio source response cannot cross a client switch',async({page})=>{
 const b=biblioFixture(),s=await fixture(page,{biblio:b,holdBiblio:true});await open(page,{edit:false});await page.locator('#tsBiblio').click();await page.locator('#tsBiblioUrl').fill(b.url);await page.locator('#tsBiblioRead').click();await expect.poll(()=>s.calls.some(x=>x.action==='biblio_search')).toBe(true);await page.locator('#tsClose').click();await page.locator('#inboxList [data-conversation-id="12"]').click();await open(page,{edit:false});s.release();await expect(page.locator('#tsBiblioResults')).toHaveCount(0);expect(s.drafts.has(11)).toBe(false);expect(s.drafts.has(12)).toBe(false);expect(s.calls.some(x=>['biblio_review','biblio_add','save'].includes(x.action))).toBe(false);clean(s,['biblio_search']);
});
test('Biblio confirmed add adopts current colleague revision without stale pre-add conflict',async({page})=>{
 const b=biblioFixture(),s=await fixture(page,{biblio:b,biblioLoss:'after'});await biblioReview(page,b);await page.locator('#tsBiblioConfirm').check();await page.locator('#tsBiblioAdd').click();await expect(page.locator('#tsRecovery')).toContainText('Сервер мог сохранить');const current=s.snapshot(11);current.draft.offers[0].rationale='Пояснение коллеги после добавления';s.drafts.set(11,{version:current.version+1,draft:copy(current.draft)});await page.getByRole('button',{name:'Проверить сохранение',exact:true}).click();await expect(page.locator('#tsRecovery')).toBeHidden();await expect(page.locator('#tsSave')).toBeDisabled();await expect(page.locator('#ts-rationale')).toHaveValue('Пояснение коллеги после добавления');expect(s.snapshot(11).draft.offers).toHaveLength(1);expect(s.calls.filter(x=>x.action==='biblio_add')).toHaveLength(1);expect(s.calls.some(x=>x.action==='save')).toBe(false);await savedGalleryPhotos(page,s,b.photos.length);clean(s,['biblio_search','biblio_review','biblio_add']);
});
test('Biblio source panel invalidates old history navigation',async({page})=>{
 const b=biblioFixture(),s=await fixture(page,{draft:completeDraft(),biblio:b});await open(page,{edit:false});await page.locator('#tsPreview').click();await expect(page.locator('#tsSend')).toBeVisible();await page.locator('#tsEdit').click();await page.locator('#tsHistory').click();await expect(page.locator('#tsRecovery')).toContainText('Версия');await page.evaluate(()=>{window.oldBiblioHistory=document.querySelector('#tsRecovery button')});await page.locator('#tsBiblio').click();await expect(page.locator('#tsRecovery')).toBeHidden();await page.evaluate(()=>oldBiblioHistory.click());await expect(page.locator('#tsSend')).toBeHidden();await expect(page.locator('#tsShare')).toBeHidden();await expect(page.locator('#tsBiblioRead')).toBeVisible();expect(s.calls.some(x=>['publication','send','publish','biblio_add'].includes(x.action))).toBe(false);clean(s,['prepare','history']);
});
test('Biblio missing hotel photos keeps the selection unchanged',async({page})=>{
 const b=biblioFixture(),original=completeDraft(),s=await fixture(page,{draft:original,biblio:b,biblioPhotosDenied:true});await open(page,{edit:false});await page.locator('#tsBiblio').click();await page.locator('#tsBiblioUrl').fill(b.url);await page.locator('#tsBiblioRead').click();await page.locator('[data-biblio-choice]').click();await expect(page.locator('#tsBiblioStatus')).toContainText('не подтверждены');await expect(page.locator('#tsBiblioAdd')).toHaveCount(0);expect(s.snapshot(11).draft).toEqual(original);expect(s.calls.some(x=>['biblio_add','save','send','publish'].includes(x.action))).toBe(false);clean(s,['biblio_search','biblio_review']);
});
test('Biblio waits for image decode without resetting the edited amount or confirmation',async({page})=>{
 const b=biblioFixture(),s=await fixture(page,{biblio:b,holdBiblioImages:true});await biblioReview(page,b,{waitPhotos:false});await expect.poll(()=>s.biblioImagesHeld()).toBe(3);await page.locator('#tsBiblioAmount').fill('130000');await page.locator('#tsBiblioConfirm').check();await expect(page.locator('#tsBiblioAdd')).toBeDisabled();await expect(page.locator('#tsBiblioPhotoStatus')).toContainText('Загружаем');s.releaseBiblioImages();await expect(page.locator('#tsBiblioAdd')).toBeEnabled();await expect(page.locator('#tsBiblioAmount')).toHaveValue('130000');await expect(page.locator('#tsBiblioConfirm')).toBeChecked();expect(s.calls.some(x=>x.action==='biblio_add')).toBe(false);await page.locator('#tsBiblioAdd').click();await expect.poll(()=>s.snapshot(11).draft.offers[0]?.amount).toBe('130000');await savedGalleryPhotos(page,s,b.photos.length);clean(s,['biblio_search','biblio_review','biblio_add']);
});
for(const mode of ['broken','tiny'])test('Biblio unsuitable image blocks normal and stale handler add '+mode,async({page})=>{
 const b=biblioFixture(),s=await fixture(page,{biblio:b,biblioImageError:mode==='broken',biblioTinyImages:mode==='tiny'});await biblioReview(page,b,{waitPhotos:false});if(mode==='tiny')await expect.poll(()=>page.locator('.tsBiblioPhotos img').evaluateAll(images=>images.every(img=>img.complete&&img.naturalWidth===1))).toBe(true);await expect(page.locator('#tsBiblioPhotoStatus')).toContainText('Не все фотографии');await page.locator('#tsBiblioConfirm').check();await expect(page.locator('#tsBiblioAdd')).toBeDisabled();await page.evaluate(()=>document.querySelector('#tsBiblioAdd').onclick());expect(s.calls.some(x=>['biblio_add','save','prepare','send','publish'].includes(x.action))).toBe(false);await expect(page.locator('#tsBiblioBack')).toBeEnabled();await page.locator('#tsBiblioBack').click();await expect(page.locator('[data-biblio-choice]')).toBeVisible();clean(s,['biblio_search','biblio_review']);
});
test('Biblio previous gallery failure cannot change a newly reviewed gallery',async({page})=>{
 const b=biblioFixture(),options={biblio:b,holdBiblioImages:true,biblioImageError:true},s=await fixture(page,options);await biblioReview(page,b,{waitPhotos:false});await expect.poll(()=>s.biblioImagesHeld()).toBe(3);await page.evaluate(()=>{window.oldBiblioImages=Array.from(document.querySelectorAll('.tsBiblioPhotos img'))});await page.locator('#tsBiblioBack').click();options.holdBiblioImages=false;options.biblioImageError=false;b.photos=b.photos.map(url=>url.replace('synthetic-','next-'));await page.locator('[data-biblio-choice]').click();await expect.poll(()=>page.locator('.tsBiblioPhotos img').evaluateAll(images=>images.every(img=>img.complete&&img.naturalWidth>0))).toBe(true);await page.locator('#tsBiblioAmount').fill('140000');await page.locator('#tsBiblioConfirm').check();s.releaseBiblioImages();await expect.poll(()=>s.biblioImagesSettled()).toBe(6);await page.evaluate(()=>oldBiblioImages.forEach(img=>img.onerror?.()));await expect(page.locator('#tsBiblioAdd')).toBeEnabled();await expect(page.locator('#tsBiblioPhotoStatus')).toHaveText('Фотографии загружены.');await expect(page.locator('#tsBiblioAmount')).toHaveValue('140000');expect(s.calls.some(x=>x.action==='biblio_add')).toBe(false);clean(s,['biblio_search','biblio_review']);
});
for(const count of [1,2])test('Biblio accepts exactly the reviewed loaded gallery size '+count,async({page})=>{
 const b=biblioFixture();b.photos=b.photos.slice(0,count);const s=await fixture(page,{biblio:b});await biblioReview(page,b);await page.locator('#tsBiblioConfirm').check();await expect(page.locator('#tsBiblioAdd')).toBeEnabled();expect(s.calls.some(x=>x.action==='biblio_add')).toBe(false);clean(s,['biblio_search','biblio_review']);
});

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
  expect(await page.evaluate(()=>WorkspaceV2Conversation.refreshVisible())).toBe(true);await page.locator('#conversationTitle.m2ClientCardButton, #mobileLeadBtn:visible').first().click();const panel=page.locator('#leadCard [data-tour-choices]');await expect(panel).toBeVisible();await panel.locator('summary').click();
  for(const value of ['Выбранные туры из поиска','Синтетический выбранный отель у моря','Family sea view','Возраст детей: 0, 0, 12','12.06.2027','Полную стоимость и наличие необходимо подтвердить','ANEX'])await expect(panel).toContainText(value);
  if(mode==='selected'){for(const value of ['231 456,50 ₽ за всех','Outward selected 10:00 synthetic','Inward selected 19:00 synthetic'])await expect(panel).toContainText(value)}else{await expect(panel).toContainText('Цена не указана');await expect(panel).toContainText('Подбор рейсов с менеджером');await expect(panel).not.toContainText('Outward selected')}
  for(const value of ['Понравившиеся туры','record_id','anex_online:','s3_','19.06.2027'])await expect(panel).not.toContainText(value);await expect(panel.locator('form')).toHaveCount(0);await expect(panel.getByRole('button',{name:'Оформление бронирования',exact:true})).toHaveCount(1);await expect(panel.locator('[data-booking-kind="search"]')).toHaveAttribute('data-booking-source',String(detail.tour_choices.search_inquiries[0].inquiry_id));
  await panel.evaluate(el=>Promise.all(el.getAnimations({subtree:true}).map(a=>a.finished.catch(()=>{}))));expect(await panel.evaluate(el=>el.scrollWidth<=el.clientWidth+1)).toBe(true);
  await expect(page.locator('#replyText')).toHaveValue('Мой новый ответ, который нельзя потерять');await expect(page.locator('#mediaSelection')).toContainText('search3-inquiry.txt');
  fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({animations:'disabled',path:path.join(root,'manager2-artifacts',`tour-selection-search3-${browserName}-${mode}-${viewport.width}x${viewport.height}.png`)});clean(s);
});
async function save(page){await page.locator('#tsSave').click();await expect(page.locator('#tsSaved')).toHaveText('Сохранено на сервере')}
function clean(s,extra=[]){expect(s.unexpected).toEqual([]);expect(s.errors).toEqual([]);expect(s.calls.filter(x=>!['get','save','operation',...extra].includes(x.action))).toEqual([]);expect(s.pending.size).toBeLessThanOrEqual(2)}

let editorPreviewBuild=null;
function standaloneEditorPreview(){
  if(!editorPreviewBuild){
    editorPreviewBuild=fs.mkdtempSync(path.join(require('node:os').tmpdir(),'selection-editor-visual-'));
    execFileSync('python3',['modules/tour-selections/build-editor.py','build','--working-tree','--output',editorPreviewBuild],{cwd:root});
    execFileSync('python3',['modules/tour-selections/build-editor.py','verify','--output',editorPreviewBuild],{cwd:root});
  }
  return editorPreviewBuild;
}
test.afterAll(()=>{if(editorPreviewBuild)fs.rmSync(editorPreviewBuild,{recursive:true,force:true})});
for(const width of [390,430,768,1440])test('standalone selection editor uses exact packaged core without WorkspaceV2 at '+width,async({page,browserName})=>{
  const build=standaloneEditorPreview(),requests=[],errors=[],allowed=['preview/index.html','preview/preview.css','preview/preview-host.js','payload/editor.js','payload/editor.css'];
  page.on('pageerror',e=>errors.push(e.message));
  await page.route('**/*',async route=>{
    const request=route.request(),url=new URL(request.url()),name=url.pathname.slice(1);requests.push({method:request.method(),origin:url.origin,name});
    if(url.origin!=='https://editor-preview.fixture'||request.method()!=='GET'||!allowed.includes(name))return route.abort();
    return route.fulfill({contentType:name.endsWith('.html')?'text/html':name.endsWith('.css')?'text/css':'application/javascript',body:fs.readFileSync(path.join(build,name))});
  });
  await page.setViewportSize({width,height:900});await page.goto('https://editor-preview.fixture/preview/index.html');
  expect(await page.evaluate(()=>typeof window.WorkspaceV2)).toBe('undefined');
  expect(await page.evaluate(()=>typeof window.WorkspaceV2TourSelection)).toBe('undefined');
  expect(fs.readFileSync(path.join(build,'payload/editor.js'))).toEqual(fs.readFileSync(path.join(root,'manager/assets/workspace-v2-tour-selection.js')));
  expect(fs.readFileSync(path.join(build,'payload/editor.css'))).toEqual(fs.readFileSync(path.join(root,'manager/assets/workspace-v2-tour-selection.css')));
  await page.locator('#tourSelectionOpen').click();await expect(page.locator('#tsTitle')).toHaveText('Демонстрационный турист');
  await page.locator('#tsOfferDetails>summary').click();
  await expect(page.locator('#tsRecipient')).toContainText('без отправки');await expect(page.locator('#ts-hotel')).toHaveValue('Пример отеля у моря');
  await page.locator('#ts-hotel').fill('Мой демонстрационный вариант');await page.locator('#ts-amount').fill('210000');await page.locator('#tsSave').click();
  await expect(page.locator('#tsSaved')).toHaveText('Сохранено в этой вкладке');
  await expect(page.locator('#tsStatus')).toContainText('в этой вкладке');
  expect(await page.evaluate(()=>document.querySelector('#tourSelectionDialog').scrollWidth-document.querySelector('#tourSelectionDialog').clientWidth)).toBeLessThanOrEqual(1);
  await page.setViewportSize({width,height:420});await expect(page.locator('#tsSave')).toBeInViewport();await expect(page.locator('#tsClose')).toBeInViewport();await expect(page.locator('#tsCollector')).toBeInViewport();await page.setViewportSize({width,height:900});
  await page.locator('#tsClose').click();await page.reload();await page.locator('#tourSelectionOpen').click();
  await expect(page.locator('#ts-hotel')).toHaveValue('Мой демонстрационный вариант');await expect(page.locator('#ts-amount')).toHaveValue('210000');
  await page.locator('#tsCollector').click();await expect(page.locator('#demoNotice')).toContainText('только демонстрационный черновик');
  await page.locator('#tsPreview').click();await expect(page.locator('#tsStatus')).toContainText('Демонстрация');await expect(page.locator('#tsSend')).toBeHidden();
  fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({animations:'disabled',path:path.join(root,'manager2-artifacts',`tour-selection-editor-standalone-${browserName}-${width}.png`)});
  await page.locator('#tsOfferDetails>summary').click();await page.locator('#ts-hotel').fill('Несохранённый демонстрационный вариант');await page.locator('#tsClose').click();await page.locator('#demoReset').click();await page.locator('#tourSelectionOpen').click();await expect(page.locator('#ts-hotel')).toHaveValue('Пример отеля у моря');
  expect(errors).toEqual([]);expect(requests.every(r=>r.method==='GET'&&r.origin==='https://editor-preview.fixture'&&allowed.includes(r.name))).toBe(true);
});

for(const width of [390,430,768,1440])test('selection overview reveals editing and preserves exact variants at '+width,async({page,browserName})=>{
  await page.setViewportSize({width,height:900});const draft=completeDraft();draft.offers.push({...copy(draft.offers[0]),id:'b'.repeat(32),hotel:'Второй синтетический вариант',room:'Standard',meal:'Завтрак',amount:'215000'});
  const s=await fixture(page,{draft});await open(page,{edit:false});
  await expect(page.locator('#tsOfferDetails')).not.toHaveAttribute('open','');
  await expect(page.locator('#ts-hotel')).toBeHidden();
  const overview=page.getByRole('region',{name:'Обзор выбранного варианта'});
  // The semantic section has an accessible name; reading it never prepares or sends a publication.
  await expect(overview).toContainText('Синтетический отель у моря');
  for(const value of ['2027-06-05','7 ноч.','2 взр.','4, 4','Семейный номер','Всё включено','240 000 ₽'])await expect(overview).toContainText(value);
  expect(s.calls.map(x=>x.action)).toEqual(['get']);expect(s.creations()).toBe(0);
  await expect(page.locator('.tsHeadActions #tsHistory')).toBeVisible();await expect(page.locator('.tsFooter #tsHistory')).toHaveCount(0);
  await expect(page.locator('#tsPreview')).toHaveClass('tsPrimary');await expect(page.locator('#tsSave')).not.toHaveClass('tsPrimary');await expect(page.locator('#tsDone')).toBeHidden();
  await page.locator('[data-ts-select]').nth(1).click();await expect(overview).toContainText('Второй синтетический вариант');await expect(overview).toContainText('215 000 ₽');await expect(page.locator('#tsOfferDetails')).not.toHaveAttribute('open','');
  await page.locator('[data-ts-action="up"]').click();await save(page);expect(s.drafts.get(11).draft.offers.map(x=>x.id)).toEqual(['b'.repeat(32),'a'.repeat(32)]);
  if(width<768){expect(await page.locator('#tsList').evaluate(list=>{const box=list.getBoundingClientRect(),active=list.querySelector('.isSelected').getBoundingClientRect();return active.left>=box.left-1&&active.right<=box.right+1})).toBe(true);await expect(page.locator('#tsOfferDetails>summary')).toBeInViewport();await page.setViewportSize({width,height:420});await expect(page.locator('#tsPreview')).toBeInViewport();await expect(page.locator('#tsSave')).toBeInViewport();await expect(page.locator('#tsClose')).toBeInViewport();expect(await page.locator('#tsEditor').evaluate(el=>el.clientHeight)).toBeGreaterThan(60);await page.setViewportSize({width,height:900})}
  expect(await page.locator('#tourSelectionDialog').evaluate(el=>el.scrollWidth-el.clientWidth)).toBeLessThanOrEqual(1);
  fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({animations:'disabled',path:path.join(root,'manager2-artifacts',`tour-selection-overview-${browserName}-${width}.png`)});
  await page.locator('#tsOfferDetails>summary').click();await expect(page.locator('#ts-amount')).toBeVisible();await page.locator('#ts-amount').fill('');await expect(overview).toContainText('Цена не указана');await page.locator('#ts-amount').fill('210000');await save(page);
  await expect(overview).toContainText('210 000 ₽');expect(s.drafts.get(11).draft.offers[0].room).toBe('Standard');expect(s.drafts.get(11).draft.offers[1]).toEqual(draft.offers[0]);
  await page.locator('#tsOfferDetails>summary').click();await page.locator('#tsPreview').click();await expect(page.locator('.tsPreviewCard')).toHaveCount(2);await expect(page.locator('#tsSend')).toHaveClass('tsPrimary');expect(s.creations()).toBe(0);
  await page.locator('#tsEdit').click();await expect(overview).toContainText('210 000 ₽');clean(s,['prepare']);
});

for(const width of [390,430,768,1440])test('selection editor preserves order conditions and reply at '+width,async({page,browserName})=>{
  await page.setViewportSize({width,height:900});const s=await fixture(page);expect(s.calls).toEqual([]);
  await expect(page.locator('#conversationTitle')).toBeVisible();
  await page.locator('#replyText').fill('Ответ клиенту остаётся в переписке');
  await open(page);await expect(page.locator('#tsCollector')).toBeInViewport();await page.locator('#tsEmptyAdd').click();await page.locator('#ts-hotel').fill('Тихий отель у моря');await page.locator('#ts-amount').fill('240000');await page.locator('#ts-adults').fill('2');await page.locator('#ts-child_ages').fill('0, 4, 4');await save(page);
  await page.locator('#tsAdd').click();await page.locator('#ts-hotel').fill('Семейный вариант');await page.locator('[data-ts-action="up"]').click();await save(page);
  expect(s.drafts.get(11).draft.offers.map(x=>x.hotel)).toEqual(['Семейный вариант','Тихий отель у моря']);
  const groupTarget=s.drafts.get(11).draft.offers[1].id;
  await page.locator('#ts-hotel-group').selectOption('o:'+groupTarget);await save(page);
  const group=s.drafts.get(11).draft.offers[0].hotel_group;expect(group).toMatch(/^[a-f0-9]{32}$/);expect(s.drafts.get(11).draft.offers[1].hotel_group).toBe(group);
  await expect(page.locator('.tsGroupLabel')).toContainText('вариантов 2');
  await page.locator('[data-ts-field="selected"]').uncheck();await save(page);expect(s.drafts.get(11).draft.offers[0].selected).toBe(false);
  await page.locator('[data-ts-select]').nth(1).click();await expect(page.locator('#ts-child_ages')).toHaveValue('0, 4, 4');
  expect(await page.evaluate(()=>document.querySelector('#tourSelectionDialog').scrollWidth-document.querySelector('#tourSelectionDialog').clientWidth)).toBeLessThanOrEqual(1);
  await page.setViewportSize({width,height:420});await expect(page.locator('#tsSave')).toBeInViewport();await expect(page.locator('#tsClose')).toBeInViewport();await expect(page.locator('#tsCollector')).toBeInViewport();await page.setViewportSize({width,height:900});
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

test('selection returning from history identifies the current draft and clears historical controls',async({page,browserName})=>{
 const s=await fixture(page,{draft:completeDraft()});await open(page);await page.locator('#tsPreview').click();await expect(page.locator('.tsPreviewCard')).toHaveCount(1);await page.locator('#tsEdit').click();
 await page.locator('#tsHistory').click();await page.getByRole('button',{name:/Версия 1/}).click();await expect(page.locator('#tsStatus')).toContainText('Показана сохранённая версия');
 await page.locator('#tsShare').click();await expect(page.locator('#tsRecovery')).toContainText('Общая страница подборки');await page.locator('#tsEdit').click();
 await expect(page.locator('#ts-hotel')).toHaveValue(completeDraft().offers[0].hotel);await expect(page.locator('#tsStatus')).toHaveText('Текущий черновик. Изменения не отправляют сообщения.');await expect(page.locator('#tsRecovery')).toBeHidden();
 for(const viewport of [{width:390,height:900},{width:430,height:900},{width:768,height:900},{width:1440,height:900},{width:390,height:420}]){await page.setViewportSize(viewport);await expect(page.locator('#tsStatus')).toBeInViewport();await expect(page.locator('#tsPreview')).toBeInViewport();await expect(page.locator('#tsClose')).toBeInViewport();expect(await page.locator('#tourSelectionDialog').evaluate(el=>el.scrollWidth<=el.clientWidth+1)).toBe(true);fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({animations:'disabled',path:path.join(root,'manager2-artifacts',`tour-selection-current-draft-${browserName}-${viewport.width}x${viewport.height}.png`)});}
 expect(s.creations()).toBe(0);expect(s.calls.some(x=>['send','publish','revoke','save'].includes(x.action))).toBe(false);clean(s,['prepare','history','publication','share']);
});

test('selection late historical version cannot replace a new preview or current draft',async({page})=>{
 const s=await fixture(page,{draft:completeDraft()});await open(page);await page.locator('#tsPreview').click();await expect(page.locator('.tsPreviewCard')).toHaveCount(1);await page.locator('#tsEdit').click();await page.locator('#tsHistory').click();
 let release,done;const finished=new Promise(resolve=>done=resolve);await page.route('**/manager/tour-selections-api.php',async route=>{if(route.request().postDataJSON().action!=='publication')return route.fallback();const old=copy(s.publication());old.cards[0].text='Старая историческая версия';await new Promise(resolve=>release=resolve);await route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,publication:old})});done();});
 await page.getByRole('button',{name:/Версия 1/}).click();await expect.poll(()=>!!release).toBe(true);await page.locator('#tsPreview').click();await expect(page.locator('.tsCardText')).not.toContainText('Старая историческая версия');await page.locator('#tsEdit').click();release();await finished;
 await expect.poll(()=>s.pending.size).toBe(0);await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));await expect(page.locator('#ts-hotel')).toBeVisible();await expect(page.locator('#tsStatus')).toHaveText('Текущий черновик. Изменения не отправляют сообщения.');expect(s.creations()).toBe(0);clean(s,['prepare','history']);
});

test('selection newest historical version wins when replies arrive in reverse order',async({page})=>{
 const s=await fixture(page,{draft:completeDraft()});await open(page);await page.locator('#tsPreview').click();await expect(page.locator('.tsPreviewCard')).toHaveCount(1);await page.locator('#tsEdit').click();
 let release,done;const finished=new Promise(resolve=>done=resolve);const first='a'.repeat(32),second='b'.repeat(32);
 await page.route('**/manager/tour-selections-api.php',async route=>{const d=route.request().postDataJSON();if(d.action==='history')return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,publications:[{publication_id:first,draft_version:1,created_at:'2026-10-22 12:00:00'},{publication_id:second,draft_version:2,created_at:'2026-10-22 13:00:00'}]})});if(d.action!=='publication')return route.fallback();const p=copy(s.publication());p.id=d.publication_id;p.cards[0].text=d.publication_id===first?'Первая историческая версия':'Вторая историческая версия';if(d.publication_id===first)await new Promise(resolve=>release=resolve);await route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,publication:p})});if(d.publication_id===first)done();});
 await page.locator('#tsHistory').click();await page.getByRole('button',{name:/Версия 1/}).click();await expect.poll(()=>!!release).toBe(true);await page.getByRole('button',{name:/Версия 2/}).click();await expect(page.locator('.tsCardText')).toHaveText('Вторая историческая версия');release();await finished;await expect.poll(()=>s.pending.size).toBe(0);await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));await expect(page.locator('.tsCardText')).toHaveText('Вторая историческая версия');expect(s.creations()).toBe(0);clean(s,['prepare']);
});

function completeDraft(){return {title:'Отдых у моря',intro:'',offers:[{id:'a'.repeat(32),selected:true,hotel:'Синтетический отель у моря',destination:'Турция, Лара',departure:'Москва',date_from:'2027-06-05',nights:7,adults:2,child_ages:[4,4],room:'Семейный номер',meal:'Всё включено',amount:'240000',currency:'RUB',rationale:'Песчаный пляж и семейный номер',compromise:'Компактная территория',flights:'Рейсы уточняются',inclusions:'Трансфер',constraints:'Топливный сбор необходимо проверить',photos:['cover','beach','room'].map(kind=>({kind,url:'https://photos.example.org/'+kind+'.jpg',illustrative:true}))}]}}

for(const width of [390,1440])test('bulk selection prices preview one atomic change without compounding at '+width,async({page,browserName})=>{
 await page.setViewportSize({width,height:900});const original=mixedPhotoDraft();original.offers[1].selected=false;
 const s=await fixture(page,{draft:original});await page.locator('#replyText').fill('Ответ менеджера сохраняется отдельно');await open(page,{edit:false});await page.locator('#tsBulkPrices').click();
 const panel=page.locator('#tsBulkPrice');await expect(panel).toBeVisible();await expect(page.locator('#tsBulkItems input:checked')).toHaveCount(2);await expect(page.locator('#tsHistory')).toBeDisabled();
 await page.locator('#tsBulkValue').fill('10');await expect(page.locator('#tsBulkPreview')).toContainText('180 000');await page.locator('#tsBulkValue').fill('5');await expect(page.locator('#tsBulkPreview')).toContainText('190 000');
 expect(s.calls.map(x=>x.action)).toEqual(['get']);expect(s.drafts.get(11).draft).toEqual(original);expect(s.creations()).toBe(0);
 expect(await panel.evaluate(el=>el.scrollWidth-el.clientWidth)).toBeLessThanOrEqual(1);expect(await page.locator('#tsBulkApply').evaluate(el=>el.getBoundingClientRect().height)).toBeGreaterThanOrEqual(44);
 fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({animations:'disabled',path:path.join(root,'manager2-artifacts',`tour-selection-bulk-prices-${browserName}-${width}.png`)});
 await page.setViewportSize({width,height:420});await expect(page.locator('#tsBulkApply')).toBeInViewport();await expect(page.locator('#tsBulkCancel')).toBeInViewport();await expect(page.locator('#tsClose')).toBeInViewport();
 // A fast repeated activation is the same UI operation, not another discount.
 await page.evaluate(()=>{window.usedBulkApply=document.querySelector('#tsBulkApply')});await page.locator('#tsBulkApply').click();await page.evaluate(()=>window.usedBulkApply.click());
 const expected=copy(original);expected.offers[0].amount='190000';expected.offers[2].amount='152000';await expect.poll(()=>s.drafts.get(11).draft).toEqual(expected);
 expect(s.calls.filter(x=>x.action==='save')).toHaveLength(1);await expect(panel).toBeHidden();await page.locator('#tsClose').click();await expect(page.locator('#replyText')).toHaveValue('Ответ менеджера сохраняется отдельно');clean(s);
});

test('bulk selection prices require one currency for an amount and preserve unchosen offers',async({page})=>{
 const original=completeDraft();original.offers.push({...copy(original.offers[0]),id:'b'.repeat(32),hotel:'Другой отель в евро',currency:'EUR',amount:'1000'});const s=await fixture(page,{draft:original});await open(page,{edit:false});await page.locator('#tsBulkPrices').click();
 await page.locator('#tsBulkBasis').selectOption('amount');await page.locator('#tsBulkValue').fill('100');await expect(page.locator('#tsBulkError')).toContainText('в одной валюте');await expect(page.locator('#tsBulkApply')).toBeDisabled();
 await page.locator('[data-ts-bulk-offer="'+ 'b'.repeat(32)+'"]').uncheck();await expect(page.locator('#tsBulkApply')).toBeEnabled();await page.locator('#tsBulkApply').click();await expect.poll(()=>s.drafts.get(11).draft.offers[0].amount).toBe('239900');expect(s.drafts.get(11).draft.offers[1]).toEqual(original.offers[1]);clean(s);
});

test('bulk selection prices use exact cents and preview rounding from the captured base',async({page})=>{
 const original=completeDraft();original.offers[0].amount='1234.56';const s=await fixture(page,{draft:original});await open(page,{edit:false});await page.locator('#tsBulkPrices').click();
 await page.locator('#tsBulkValue').fill('12,5');await expect(page.locator('#tsBulkPreview')).toContainText('1 080,24');await page.locator('#tsBulkAction').selectOption('markup');await expect(page.locator('#tsBulkPreview')).toContainText('1 388,88');
 for(const [round,shown]of [['unit','1 389'],['ten','1 390'],['hundred','1 400'],['thousand','1 000']]){await page.locator('#tsBulkRound').selectOption(round);await expect(page.locator('#tsBulkPreview')).toContainText(shown)}
 await page.locator('#tsBulkRound').selectOption('none');await expect(page.locator('#tsBulkPreview')).toContainText('1 388,88');expect(s.calls.map(x=>x.action)).toEqual(['get']);await page.locator('#tsBulkApply').click();await expect.poll(()=>s.drafts.get(11).draft.offers[0].amount).toBe('1388.88');clean(s);
});

test('bulk selection prices reject invalid totals atomically and cancellation does not save',async({page})=>{
 const original=completeDraft();original.offers[0].amount='0.01';original.offers.push({...copy(original.offers[0]),id:'b'.repeat(32),hotel:'Верхняя граница цены',amount:'999999999.99'});const s=await fixture(page,{draft:original});await open(page,{edit:false});await page.locator('#tsBulkPrices').click();
 await page.locator('#tsBulkBasis').selectOption('amount');await page.locator('#tsBulkValue').fill('1');await expect(page.locator('#tsBulkApply')).toBeDisabled();await expect(page.locator('#tsBulkError')).toContainText('больше нуля');await page.locator('#tsBulkAction').selectOption('markup');await expect(page.locator('#tsBulkApply')).toBeDisabled();await expect(page.locator('#tsBulkError')).toContainText('меньше миллиарда');
 await page.locator('#tsBulkValue').fill('0.001');await expect(page.locator('#tsBulkError')).toContainText('до двух знаков');await page.locator('#tsBulkCancel').click();expect(s.drafts.get(11).draft).toEqual(original);expect(s.calls.map(x=>x.action)).toEqual(['get']);await expect(page.locator('#tsSave')).toBeDisabled();clean(s);
});

for(const [base,action,value,round,expected]of [['1.49','markup','0.67','unit','1'],['0.03','discount','50','none','0.02'],['99504.95','markup','1','thousand','100000']])test('bulk selection prices round the exact final total once '+base+' '+action+' '+round,async({page})=>{
 const original=completeDraft();original.offers[0].amount=base;const s=await fixture(page,{draft:original});await open(page,{edit:false});await page.locator('#tsBulkPrices').click();await page.locator('#tsBulkAction').selectOption(action);await page.locator('#tsBulkValue').fill(value);await page.locator('#tsBulkRound').selectOption(round);await page.locator('#tsBulkApply').click();await expect.poll(()=>s.drafts.get(11).draft.offers[0].amount).toBe(expected);clean(s);
});

test('bulk selection prices respect canonical version conflicts without overwriting a colleague',async({page})=>{
 const original=completeDraft(),s=await fixture(page,{draft:original});await open(page,{edit:false});await page.locator('#tsBulkPrices').click();const colleague=copy(original);colleague.offers[0].room='Другая подтверждённая категория';s.drafts.set(11,{version:2,draft:colleague});
 await page.locator('#tsBulkApply').click();await expect(page.locator('#tsRecovery')).toContainText('другая версия');expect(s.drafts.get(11)).toEqual({version:2,draft:colleague});expect(s.calls.filter(x=>x.action==='save')).toHaveLength(1);await expect(page.locator('#tsBulkPrices')).toBeDisabled();clean(s);
});

test('bulk selection prices have no pending action after close and client switch',async({page})=>{
 const original=completeDraft(),s=await fixture(page,{draft:original});await open(page,{edit:false});await page.locator('#tsBulkPrices').click();await page.locator('#tsBulkValue').fill('20');await page.evaluate(()=>{window.staleBulkApply=document.querySelector('#tsBulkApply')});await page.locator('#tsClose').click();await page.locator('#inboxList [data-conversation-id="12"]').click();await open(page);await page.evaluate(()=>window.staleBulkApply.click());
 await expect(page.locator('#tsBulkPrice')).toBeHidden();expect(s.drafts.has(12)).toBe(false);expect(s.drafts.get(11).draft).toEqual(original);expect(s.calls.filter(x=>x.action==='save')).toHaveLength(0);clean(s);
});

test('bulk selection prices are unavailable to read-only viewers',async({page})=>{
 const original=completeDraft(),s=await fixture(page,{draft:original,readOnly:true});await open(page,{edit:false});await expect(page.locator('#tsBulkPrices')).toBeDisabled();await expect(page.locator('#tsBulkPrice')).toBeHidden();expect(s.calls.filter(x=>x.action==='save')).toHaveLength(0);clean(s);
});

test('bulk selection prices dismiss history and fence its delayed or stale navigation',async({page})=>{
 const original=completeDraft(),s=await fixture(page,{draft:original});await open(page,{edit:false});await page.locator('#tsPreview').click();await expect(page.locator('.tsPreviewCard')).toHaveCount(1);const publication=copy(s.publication());await page.locator('#tsEdit').click();await page.locator('#tsHistory').click();const versionButton=page.getByRole('button',{name:/Версия 1/});await expect(versionButton).toBeVisible();await versionButton.evaluate(button=>{window.staleHistoryVersion=button});
 let release,started=false;const held=new Promise(resolve=>{release=resolve});await page.route('**/manager/tour-selections-api.php',async route=>{if(route.request().postDataJSON().action==='publication'){started=true;await held}return route.fallback()});await versionButton.click();await expect.poll(()=>started).toBe(true);await page.locator('#tsBulkPrices').click();await expect(page.locator('#tsRecovery')).toBeHidden();release();await expect.poll(()=>s.calls.filter(x=>x.action==='publication').length).toBe(1);await page.evaluate(()=>window.staleHistoryVersion.click());
 await expect(page.locator('#tsBulkApply')).toBeEnabled();await page.locator('#tsBulkApply').click();await expect.poll(()=>s.drafts.get(11).draft.offers[0].amount).toBe('228000');expect(s.calls.filter(x=>x.action==='publication')).toHaveLength(1);expect(s.publication()).toEqual(publication);clean(s,['prepare','history','publication']);
});
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

for(const hostedShare of ['ready','spoof'])test('selection dedicated host links preserve tokens and reject other origins '+hostedShare,async({page})=>{
  const s=await fixture(page,{draft:completeDraft(),hostedShare});await open(page);await page.locator('#tsPreview').click();await expect(page.locator('#tsSend')).toBeEnabled();await page.locator('#tsShare').click();await page.getByRole('button',{name:'Опубликовать эту версию',exact:true}).click();
  const expected=(hostedShare==='ready'?'https://tours.anytoour.ru/?t=':new URL('/selection/?t=',page.url()).href)+'d'.repeat(64);
  await expect(page.getByRole('textbox',{name:'Общая ссылка на подборку'})).toHaveValue(expected);
  await expect(page.getByRole('link',{name:'Открыть страницу',exact:true})).toHaveAttribute('href',expected+'&preview=1800000000.'+'e'.repeat(64));
  expect(s.creations()).toBe(0);clean(s,[...deliveryActions,'share','publish']);
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
  await page.locator('#conversationTitle.m2ClientCardButton, #mobileLeadBtn:visible').first().click();const panel=page.locator('#leadCard [data-tour-choices]');await expect(panel).toBeVisible();await panel.locator('summary').click();
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

test('collector acknowledges a held operation after Refresh without restoring its older view',async({page})=>{
 const s=await bridgeFixture(page);await s.hello();await expect(page.locator('#collectorStatus')).toContainText('Проверьте клиента');
 await page.locator('#collectorConfirm').check();await page.locator('#collectorConnect').click();await expect(page.locator('#collectorStatus')).toContainText('Подключено');
 const calls=[],requestId='1'.repeat(32),operationId='2'.repeat(32);let release;
 const held=new Promise(resolve=>{release=resolve});
 await page.route('**/manager/tour-selections-api.php',async route=>{
  const d=route.request().postDataJSON();calls.push(copy(d));
  if(d.action==='collector_operation'){await held;return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,found:true,saved_version:9,count:3})})}
  if(d.action==='collector_get')return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,target:{actor:7,conversation_id:11,client:'Синтетическая Анна',channel:'telegram',trip:'Свежие условия',started_at:'2026-10-23 10:00:00',dates:['2027-06-05'],count:4,version:10,binding:'b'.repeat(64)},connection:{id:'c'.repeat(32),expires_at:'2099-01-01 00:00:00'}})});
  return route.fallback();
 });
 await s.command({action:'operation',request_id:requestId,operation_id:operationId});await expect.poll(()=>calls.filter(c=>c.action==='collector_operation').length).toBe(1);
 await page.locator('#collectorRefresh').click();await expect(page.locator('#collectorTarget')).toContainText('На сервере: 4');await expect(page.locator('#collectorTarget')).toContainText('Свежие условия');const freshStatus=await page.locator('#collectorStatus').textContent();
 release();await expect.poll(()=>s.events.filter(e=>e.type==='result'&&e.request_id===requestId).length).toBe(1);
 expect(s.events.find(e=>e.type==='result'&&e.request_id===requestId)).toMatchObject({nonce:'e'.repeat(32),request_id:requestId,result:{ok:true,found:true,saved_version:9,count:3}});
 await expect(page.locator('#collectorTarget')).toContainText('На сервере: 4');await expect(page.locator('#collectorTarget')).toContainText('Свежие условия');await expect(page.locator('#collectorStatus')).toHaveText(freshStatus);
 expect(calls.map(c=>c.action)).toEqual(['collector_operation','collector_get']);expect(calls[0].operation_id).toBe(operationId);expect(s.errors).toEqual([]);
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
 const interest={ok:true,interest:{window_days:30,limited:true,totals:{browser:{page_view:{unique:3,repeat:2},hotel_view:{unique:4,repeat:1},bot_open:{unique:2,repeat:0}},preview:{page_fetch:{unique:2,repeat:0}},robot:{page_fetch:{unique:1,repeat:0}}},variants:[{index:0,hotel_views:4,bot_opens:0},{index:1,hotel_views:0,bot_opens:1},{index:2,hotel_views:0,bot_opens:1},{index:99,hotel_views:900,bot_opens:900}],last_browser_at:'2026-10-23 14:00:00',native:{liked:0,inquiries:0,likes_limited:false,inquiries_limited:false}}};
 const draft=completeDraft();draft.offers.push({...copy(draft.offers[0]),id:'b'.repeat(32),date_from:'2027-06-12',room:'Сьют с отдельной спальней',amount:'275000'}, {...copy(draft.offers[0]),id:'c'.repeat(32),hotel:'Другой синтетический отель с длинным названием у моря',room:'Номер <b>Deluxe</b>',amount:'310000'});
 const state=await fixture(page,{draft,interest});
 await page.locator('#replyText').fill('Мой незаконченный ответ');await page.locator('#replyFile').setInputFiles({name:'interest-retained.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic retained file')});
 await open(page);await page.locator('#tsPreview').click();await expect(page.locator('#tsInterest')).toBeVisible();await page.locator('#tsInterest').click();
 await expect(page.locator('#tsInterestPanel')).toContainText('анонимные сигналы браузера');await expect(page.locator('.tsInterestCounts')).toContainText('3 · повторов 2');
 await expect(page.locator('#tsInterestPanel')).toContainText('«Нравится» — 0, обращений — 0');await expect(page.locator('#tsInterestPanel')).toContainText('Достигнут предел выборки');
 await expect(page.locator('#tsInterestPanel')).toContainText('События предпросмотра: 2');await expect(page.locator('#tsInterestPanel')).toContainText('События распознанной автоматизации: 1');
 await expect(page.locator('#tsInterestPanel')).toContainText('23.10.2026 17:00 МСК');
 await expect(page.locator('.tsInterestHotel')).toHaveCount(1);await expect(page.locator('.tsInterestHotel')).toContainText('Синтетический отель у моря');await expect(page.locator('.tsInterestHotel')).not.toContainText('Семейный номер');
 const variants=page.locator('.tsInterestOffer');await expect(variants).toHaveCount(2);
 await expect(variants.first()).toContainText('Сьют с отдельной спальней');await expect(variants.first()).toContainText('275 000 ₽ за 4 человека');await expect(variants.first()).toContainText('12 июня 2027');
 await expect(variants.last()).toContainText('Номер <b>Deluxe</b>');await expect(variants.last().locator('b')).toHaveCount(0);await expect(page.locator('#tsInterestPanel')).not.toContainText('900');
 await page.locator('#tsInterestPanel h3').scrollIntoViewIfNeeded();expect(await page.locator('#tourSelectionDialog').evaluate(el=>el.scrollWidth-el.clientWidth)).toBeLessThanOrEqual(1);
 await expect(page.locator('#tsClose')).toBeInViewport();await expect(page.locator('#tsDone')).toBeInViewport();
 await variants.first().locator('h4').evaluate(el=>el.scrollIntoView({block:'start'}));
 fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({animations:'disabled',path:path.join(root,'manager2-artifacts',`tour-selection-interest-${browserName}-${viewport.width}x${viewport.height}.png`)});
 for(const [i,index]of [1,2].entries()){
  await variants.nth(i).getByRole('button',{name:'Показать карточку варианта '+(index+1),exact:true}).click();
  await expect(page.locator('.tsPreviewCard[data-ts-card="'+index+'"] .tsCardTitle')).toBeInViewport();
  expect(await page.evaluate(()=>document.activeElement.dataset.tsCard)).toBe(String(index));
 }
 await page.locator('#tsClose').click();await expect(page.locator('#replyText')).toHaveValue('Мой незаконченный ответ');await expect(page.locator('#mediaSelection')).toContainText('interest-retained.txt');
 expect(state.creations()).toBe(0);expect(state.calls.filter(x=>x.action==='interest')).toHaveLength(1);clean(state,['prepare','interest']);
});

test('selection interest keeps historical terms and rejects a stale card jump after navigation',async({page})=>{
 const interest={ok:true,interest:{window_days:30,totals:{},variants:[{index:0,hotel_views:0,bot_opens:1}],native:{liked:0,inquiries:0}}};
 const s=await fixture(page,{draft:completeDraft(),interest});await open(page);await page.locator('#tsPreview').click();
 const original=s.publication().cards[0].text;await page.locator('#tsEdit').click();await page.locator('#ts-room').fill('Новый номер в черновике');await page.locator('#ts-amount').fill('990000');await save(page);
 await page.locator('#tsHistory').click();await page.getByRole('button',{name:/Версия 1 ·/}).click();await page.locator('#tsInterest').click();
 const row=page.locator('.tsInterestOffer');await expect(row).toContainText('Семейный номер');await expect(row).toContainText('240 000 ₽ за 4 человека');await expect(row).not.toContainText('Новый номер');await expect(row).not.toContainText('990 000');
 expect(await row.locator('.tsInterestTerms').textContent()).toBe(original.split('\n').slice(1,6).join('\n'));
 const jump=await row.getByRole('button',{name:'Показать карточку варианта 1',exact:true}).elementHandle();
 await page.locator('#tsClose').click();await page.locator('#inboxList [data-conversation-id="12"]').click();await expect.poll(()=>page.evaluate(()=>WorkspaceV2.S.current)).toBe(12);
 await jump.evaluate(button=>button.click());await expect(page.locator('#tourSelectionDialog')).not.toBeVisible();
 expect(s.creations()).toBe(0);expect(s.calls.filter(x=>x.action==='save')).toHaveLength(1);expect(s.calls.filter(x=>x.action==='interest')).toHaveLength(1);clean(s,['prepare','history','publication','interest']);
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
 await page.setViewportSize({width,height:420});await expect(page.getByRole('button',{name:'Не добавлено'})).toBeVisible();expect(await page.evaluate(()=>collectorStaged[0].observation.photos)).toEqual(s.photos);expect(s.calls.slice().sort()).toEqual([s.url,s.galleryUrl,...s.photos].sort());
});

for(const width of [390,430,768,1440])test(`ANEX imported terms show their actual source in the existing editor ${width}`,async({page,browserName})=>{
 await page.setViewportSize({width,height:900});const imported=JSON.parse(execFileSync('php',['-r',`require 'tests/support/manager-tour-collector.php';$i=ManagerTourSelectionImportDraft::normalize(anexCollectorObservation());$o=$i['offer'];$o['id']=str_repeat('a',32);$o['import_source']=ManagerTourSelectionImportDraft::source($i);echo json_encode(['title'=>'Смешанная подборка','intro'=>'','offers'=>[$o]],JSON_THROW_ON_ERROR);`],{cwd:root,encoding:'utf8'}));
 const s=await fixture(page,{draft:imported});await page.locator('#replyText').fill('Неотправленный ответ');await open(page);await expect(page.locator('.tsImportedSource')).toContainText('Исходные условия ANEX');await expect(page.locator('.tsImportedSource')).toContainText('177323 RUB');await expect(page.locator('#tsCollector')).toHaveText('Сборщик туров');
 await page.locator('#ts-amount').fill('180000');await save(page);expect(s.drafts.get(11).draft.offers[0].import_source.fields.amount).toBe('177323');
 await page.locator('.tsImportedSource').scrollIntoViewIfNeeded();fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({animations:'disabled',path:path.join(root,'manager2-artifacts',`tour-anex-editor-${browserName}-${width}.png`)});
 await page.locator('#tsClose').click();await expect(page.locator('#replyText')).toHaveValue('Неотправленный ответ');clean(s);
});

for(const width of [390,1440])test('ANEX result price sorting preserves a current row without reloading the document at '+width,async({page,browserName})=>{
 await page.setViewportSize({width,height:900});const s=await anexSourceFixture(page,{searchControls:true});await collectorSourceRuntime(page);await page.addScriptTag({path:path.join(root,'extensions/selection-collector/anex-content.js')});
 await page.getByRole('button',{name:'По цене',exact:true}).click();await page.getByRole('button',{name:'В подборку AnyTour',exact:true}).click();await expect(page.getByRole('button',{name:'✓ В подборке',exact:true})).toBeVisible();expect(await page.evaluate(()=>collectorStaged.length)).toBe(1);expect(await page.evaluate(()=>collectorStaged[0].observation.offer.amount)).toBe('177323');expect(await page.evaluate(()=>collectorStaged[0].observation.photos)).toEqual(s.photos);
 fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({animations:'disabled',path:path.join(root,'manager2-artifacts',`tour-anex-sort-${browserName}-${width}.png`)});
});

for(const choice of ['По отелю','По умолчанию'])test('ANEX result grouping '+choice+' preserves exact row conditions',async({page})=>{
 await anexSourceFixture(page,{searchControls:true});await collectorSourceRuntime(page);await page.addScriptTag({path:path.join(root,'extensions/selection-collector/anex-content.js')});
 if(choice==='По умолчанию')await page.getByRole('radio',{name:'По отелю',exact:true}).check();await page.getByRole('radio',{name:choice,exact:true}).check();await page.getByRole('button',{name:'В подборку AnyTour',exact:true}).click();await expect(page.getByRole('button',{name:'✓ В подборке',exact:true})).toBeVisible();expect(await page.evaluate(()=>collectorStaged.length)).toBe(1);expect(await page.evaluate(()=>collectorStaged[0].observation.offer.nights)).toBe(7);
});

test('ANEX sorting cannot clear an ambiguous year or admit an unknown result control',async({page})=>{
 await anexSourceFixture(page,{searchControls:true});await collectorSourceRuntime(page);await page.addScriptTag({path:path.join(root,'extensions/selection-collector/anex-content.js')});
 await page.locator('[data-testid=CHECKIN_BEG]').click();await page.getByRole('button',{name:'По цене',exact:true}).click();await page.getByRole('radio',{name:'По отелю',exact:true}).check();await page.getByRole('button',{name:'В подборку AnyTour',exact:true}).click();await expect(page.getByRole('button',{name:'Не добавлено',exact:true})).toBeVisible();expect(await page.evaluate(()=>collectorStaged.length)).toBe(0);
 await page.reload();await page.addScriptTag({path:path.join(root,'extensions/selection-collector/anex.js')});await collectorSourceRuntime(page);await page.addScriptTag({path:path.join(root,'extensions/selection-collector/anex-content.js')});await page.evaluate(()=>{const b=document.createElement('button');b.type='button';b.textContent='Неизвестный фильтр';document.querySelector('#search-filter-anchor').append(b)});await page.getByRole('button',{name:'Неизвестный фильтр',exact:true}).click();await page.getByRole('button',{name:'В подборку AnyTour',exact:true}).click();await expect(page.getByRole('button',{name:'Не добавлено',exact:true})).toBeVisible();expect(await page.evaluate(()=>collectorStaged.length)).toBe(0);
});

for(const egypt of [false,true])test('ANEX native search accepts only its new '+(egypt?'Egypt':'Turkey')+' result without document reload',async({page})=>{
 const s=await anexSourceFixture(page,{searchControls:true,egypt}),nights=egypt?12:8;await collectorSourceRuntime(page);await page.addScriptTag({path:path.join(root,'extensions/selection-collector/anex-content.js')});
 await page.locator('[data-testid=NIGHTMIN]').fill(String(nights));await page.locator('[data-testid=NIGHTMAX]').fill(String(nights));await page.getByRole('button',{name:'Найти тур',exact:true}).click();await page.getByRole('button',{name:'В подборку AnyTour',exact:true}).click();await expect(page.getByRole('button',{name:'Не добавлено',exact:true})).toBeVisible();expect(await page.evaluate(()=>collectorStaged.length)).toBe(0);
 await s.replaceResult({query:{NIGHTMIN:String(nights),NIGHTMAX:String(nights)},nights,price:'185 000'});await page.getByRole('button',{name:'В подборку AnyTour',exact:true}).click();await expect(page.getByRole('button',{name:'✓ В подборке',exact:true})).toBeVisible();const observation=await page.evaluate(()=>collectorStaged[0].observation);expect(observation.offer.nights).toBe(nights);expect(observation.offer.amount).toBe('185000');expect(observation.photos).toEqual(s.photos);const normalized=JSON.parse(execFileSync('php',['-r',`require 'services/ManagerTourSelectionImportDraft.php';echo json_encode(ManagerTourSelectionImportDraft::normalize(json_decode(stream_get_contents(STDIN),true)),JSON_THROW_ON_ERROR);`],{cwd:root,input:JSON.stringify(observation),encoding:'utf8'}));expect(normalized.offer.nights).toBe(nights);expect(normalized.offer.amount).toBe('185000');
});

test('ANEX native search retains its full-year fence and rejects reused old anchors',async({page})=>{
 const s=await anexSourceFixture(page,{searchControls:true});await collectorSourceRuntime(page);await page.addScriptTag({path:path.join(root,'extensions/selection-collector/anex-content.js')});await page.locator('[data-testid=CHECKIN_BEG]').click();
 await s.replaceResult({});await page.getByRole('button',{name:'В подборку AnyTour',exact:true}).click();await expect(page.getByRole('button',{name:'Не добавлено',exact:true})).toBeVisible();expect(await page.evaluate(()=>collectorStaged.length)).toBe(0);
 await page.getByRole('button',{name:'Найти тур',exact:true}).click();await page.evaluate(()=>{const a=document.querySelector('#anexQuote');a.href=a.href+'&SORT_TYPE=1'});await page.getByRole('button',{name:'Не добавлено',exact:true}).click();await expect(page.locator('[data-anytour-note]')).toContainText('новых результатов');expect(await page.evaluate(()=>collectorStaged.length)).toBe(0);
 await s.replaceResult({query:{CHECKIN_BEG:'20271025',CHECKIN_END:'20271025'},documentQuery:{},date:'25 окт. 2027',weekday:'пн'});await page.getByRole('button',{name:'В подборку AnyTour',exact:true}).click();await expect(page.getByRole('button',{name:'Не добавлено',exact:true})).toBeVisible();await expect(page.locator('[data-anytour-note]')).toContainText('Строка не соответствует');expect(await page.evaluate(()=>collectorStaged.length)).toBe(0);
 await page.getByRole('button',{name:'Найти тур',exact:true}).click();await s.replaceResult({query:{CHECKIN_BEG:'20271025',CHECKIN_END:'20271025'},date:'25 окт. 2027',weekday:'пн'});await page.getByRole('button',{name:'В подборку AnyTour',exact:true}).click();await expect(page.getByRole('button',{name:'✓ В подборке',exact:true})).toBeVisible();expect(await page.evaluate(()=>collectorStaged[0].observation.offer.date_from)).toBe('2027-10-25');
});

test('ANEX later search refuses an older response before accepting matching fresh results',async({page})=>{
 const s=await anexSourceFixture(page,{searchControls:true});await collectorSourceRuntime(page);await page.addScriptTag({path:path.join(root,'extensions/selection-collector/anex-content.js')});for(const n of ['8','9']){await page.locator('[data-testid=NIGHTMIN]').fill(n);await page.locator('[data-testid=NIGHTMAX]').fill(n);await page.getByRole('button',{name:'Найти тур',exact:true}).click()}
 await s.replaceResult({query:{NIGHTMIN:'8',NIGHTMAX:'8'},documentQuery:{NIGHTMIN:'9',NIGHTMAX:'9'},nights:8});await page.getByRole('button',{name:'В подборку AnyTour',exact:true}).click();await expect(page.getByRole('button',{name:'Не добавлено',exact:true})).toBeVisible();expect(await page.evaluate(()=>collectorStaged.length)).toBe(0);
 await s.replaceResult({query:{NIGHTMIN:'9',NIGHTMAX:'9'},nights:9});await page.getByRole('button',{name:'В подборку AnyTour',exact:true}).click();await expect(page.getByRole('button',{name:'✓ В подборке',exact:true})).toBeVisible();expect(await page.evaluate(()=>collectorStaged[0].observation.offer.nights)).toBe(9);
});

test('ANEX native search during gallery collection cannot append the former result generation',async({page})=>{
 const s=await anexSourceFixture(page,{searchControls:true});await collectorSourceRuntime(page);let release;const held=new Promise(resolve=>release=resolve);await page.route(s.galleryUrl,async route=>{await held;return route.fulfill({contentType:'text/html',body:'<div role="tabpanel"><div id="sliderPhoto">'+s.photos.map(url=>'<img src="'+url.replace('&','&amp;')+'">').join('')+'</div></div>'})});await page.addScriptTag({path:path.join(root,'extensions/selection-collector/anex-content.js')});
 await page.getByRole('button',{name:'В подборку AnyTour',exact:true}).click();await expect(page.getByRole('button',{name:'Добавляем фото…',exact:true})).toBeVisible();await page.getByRole('button',{name:'Найти тур',exact:true}).click();release();await expect(page.getByRole('button',{name:'Не добавлено',exact:true})).toBeVisible();expect(await page.evaluate(()=>collectorStaged.length)).toBe(0);expect(await page.evaluate(()=>collectorRequests.map(x=>x.type))).toEqual(['begin_add']);
});

test('ANEX form interaction invalidates old result even when displayed day month is unchanged',async({page})=>{
 await anexSourceFixture(page);await collectorSourceRuntime(page);await page.addScriptTag({path:path.join(root,'extensions/selection-collector/anex-content.js')});
 await page.locator('[data-testid=CHECKIN_BEG]').click();await page.getByRole('button',{name:'В подборку AnyTour',exact:true}).click();await expect(page.getByRole('button',{name:'Не добавлено'})).toBeVisible();await expect(page.locator('[data-anytour-note]')).toContainText('Найти тур');expect(await page.evaluate(()=>collectorStaged.length)).toBe(0);expect(await page.evaluate(()=>collectorRequests.map(x=>x.type))).toEqual(['begin_add']);
});

for(const standard of [false,true])test('ANEX Egypt exact ten-cell '+(standard?'unlabelled':'Light')+' row retains HRG and SSH identities',async({page})=>{
 const s=await anexSourceFixture(page,{egypt:true,standard});for(const airport of ['HRG','SSH']){await page.locator('#anexQuote').evaluate((a,airport)=>{const u=new URL(a.href);u.searchParams.set('fullNumber',u.searchParams.get('fullNumber').replace(/^(HRG|SSH)/,airport));a.href=u.href},airport);const read=await s.read();expect(read.error).toBeUndefined();expect(read.value.offer.destination).toBe('Египет, Хургада');expect(read.value.offer.nights).toBe(11);expect(read.value.offer.amount).toBe('148039');expect(read.value.keys.spo).toBe(airport+'-12345-MOW'+(standard?'':' Anex Light'));expect(read.value.raw.tariff).toBe(standard?'':'ANEX Light');const normalized=JSON.parse(execFileSync('php',['-r',`require 'services/ManagerTourSelectionImportDraft.php';echo json_encode(ManagerTourSelectionImportDraft::normalize(json_decode(stream_get_contents(STDIN),true)),JSON_THROW_ON_ERROR);`],{cwd:root,input:JSON.stringify(read.value),encoding:'utf8'}));expect(normalized.keys).toEqual(read.value.keys)}
 await page.locator('[data-testid=STATEINC]').fill('Турция');expect((await s.read()).error).toContain('Условия формы');expect(s.calls).toEqual([s.url]);
});
test('ANEX Egypt split hotel-flight nights cannot become an invented ordinary duration',async({page})=>{
 const s=await anexSourceFixture(page,{egypt:true});await page.locator('#info').evaluate(info=>{info.children[1].textContent='10 +1';info.children[5].textContent='10ночей+1'});expect((await s.read()).error).toContain('Ночи разделены');await collectorSourceRuntime(page);await page.addScriptTag({path:path.join(root,'extensions/selection-collector/anex-content.js')});await page.getByRole('button',{name:'В подборку AnyTour',exact:true}).click();await expect(page.locator('[data-anytour-note]')).toContainText('Ночи разделены');expect(await page.evaluate(()=>collectorStaged.length)).toBe(0);expect(s.calls).toEqual([s.url]);
});
for(const width of [390,1440])test('ANEX Egypt source joins one same-direction three-source draft with automatic photos at '+width,async({page,browserName})=>{
 const source=await anexSourceFixture(page,{egypt:true});await collectorSourceRuntime(page);await page.evaluate(()=>{window.nativeCreates=0;document.querySelector('#nativePrice').onclick=()=>nativeCreates++});await page.addScriptTag({path:path.join(root,'extensions/selection-collector/anex-content.js')});await page.getByRole('button',{name:'В подборку AnyTour',exact:true}).click();await expect(page.getByRole('button',{name:'✓ В подборке',exact:true})).toBeVisible();const observation=await page.evaluate(()=>collectorStaged[0].observation);expect(observation.photos).toEqual(source.photos);expect(observation.offer.destination).toBe('Египет, Хургада');expect(await page.evaluate(()=>nativeCreates)).toBe(0);expect(source.calls.slice().sort()).toEqual([source.url,source.galleryUrl,...source.photos].sort());
 const selection=JSON.parse(execFileSync('php',['-r',`require 'tests/support/manager-tour-collector.php';$pdo=deliveryDb();echo json_encode(collectorEgyptMixedDraft($pdo,json_decode(stream_get_contents(STDIN),true)),JSON_THROW_ON_ERROR);`],{cwd:root,input:JSON.stringify(observation),encoding:'utf8'}));const imported=selection.draft,anexId=imported.offers[1].id,tvId=imported.offers[2].id;expect(imported.offers.map(x=>[x.destination,x.date_from,x.nights,x.adults])).toEqual([['Египет, Хургада','2026-10-29',11,2],['Египет, Хургада','2026-10-29',11,2],['Египет, Хургада','2026-10-29',11,2]]);
 const editor=await page.context().newPage();await editor.setViewportSize({width,height:900});const s=await fixture(editor,{draft:imported});await editor.route(/^https:\/\/(?:files\.anextour\.ru\/hotel\/hotels\/egypt\/synthetic\/o[1-3]\?&hotelCode=100|static\.tourvisor\.ru\/hotel_pics\/verybig\/1\/502-[1-3]\.jpg)$/,route=>route.fulfill({contentType:'image/jpeg',body:fs.readFileSync(path.join(root,'assets/owner-tour-cards/compact-v1/cover.jpg'))}));await open(editor);await expect(editor.locator('[data-ts-select]')).toHaveCount(3);await editor.locator('[data-ts-select="'+anexId+'"]').click();await expect(editor.locator('.tsImportedSource')).toContainText('Исходные условия ANEX');await editor.locator('.tsAutomaticPhotos').evaluate(el=>el.scrollIntoView({block:'start'}));await expect.poll(()=>editor.locator('.tsAutomaticPhotos img').evaluateAll(xs=>xs.length===3&&xs.every(x=>x.complete&&x.naturalWidth>0))).toBe(true);await editor.locator('#ts-amount').fill('150000');await editor.locator('[data-ts-action=up]').click();await save(editor);expect(s.drafts.get(11).draft.offers[0].id).toBe(anexId);expect(s.drafts.get(11).draft.offers[0].import_source).toEqual(imported.offers[1].import_source);
 await editor.locator('[data-ts-select="'+tvId+'"]').click();await editor.locator('[data-ts-field=selected]').uncheck();await save(editor);await editor.locator('[data-ts-field=selected]').check();await save(editor);await editor.locator('#tsClose').click();await editor.reload();await expect.poll(()=>editor.evaluate(()=>!!WorkspaceV2.S.workspaceBound&&!WorkspaceV2.S.booting)).toBe(true);if(!await editor.locator('#tourSelectionOpen').count())await editor.locator('#inboxList [data-conversation-id="11"]').click();await open(editor);await expect(editor.locator('[data-ts-select]')).toHaveCount(3);await expect(editor.locator('#ts-amount')).toHaveValue('150000');await editor.locator('#tsPreview').click();await expect(editor.locator('.tsPreviewCard')).toHaveCount(3);await expect(editor.locator('.tsPhotos img')).toHaveCount(9);await expect.poll(()=>editor.locator('.tsPhotos img').evaluateAll(xs=>xs.length===9&&xs.every(x=>x.complete&&x.naturalWidth>0))).toBe(true);await expect(editor.locator('#tsSend')).toBeEnabled();await expect(editor.locator('.tsCardPrice').first()).toContainText('150 000');expect(s.photoUploads).toHaveLength(0);expect(s.creations()).toBe(0);expect(await editor.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await editor.screenshot({animations:'disabled',path:path.join(root,'manager2-artifacts',`tour-selection-mixed-egypt-${browserName}-${width}.png`)});clean(s,deliveryActions);await editor.close();
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
 await page.setViewportSize({width,height:900});await tourvisorSourceFixture(page);await collectorSourceRuntime(page,'destination');await page.evaluate(()=>{window.nativeCreates=0;document.querySelector('#nativeContinue').onclick=()=>nativeCreates++});
 const visibility=width===390||width===768?'class':'style',setMenuVisibility=(el,{kind,open})=>{if(kind==='class')el.classList.toggle('TVHide',!open);else el.style.display=open?'':'none'};
 const menu=page.locator('.TVMenuControl');await menu.evaluate(setMenuVisibility,{kind:visibility,open:false});
 await page.addScriptTag({path:path.join(root,'extensions/selection-collector/tourvisor-content.js')});
 await expect(menu.locator('[data-anytour-add]')).toHaveCount(0);
 await menu.evaluate(setMenuVisibility,{kind:visibility,open:true});await expect(menu.getByRole('button',{name:'В подборку AnyTour',exact:true})).toBeVisible();
 await menu.evaluate(setMenuVisibility,{kind:visibility,open:false});await menu.evaluate(setMenuVisibility,{kind:visibility,open:true});await expect(menu.locator('[data-anytour-add]')).toHaveCount(2);
 await page.getByRole('button',{name:'В подборку AnyTour',exact:true}).click();await expect(page.getByRole('button',{name:'Проверьте в AnyTour',exact:true})).toBeVisible();expect(await page.evaluate(()=>collectorStaged.length)).toBe(1);expect(await page.evaluate(()=>collectorStaged[0].ticket)).toBe('a'.repeat(32));expect(await page.evaluate(()=>nativeCreates)).toBe(0);expect(await page.locator('body').innerText()).not.toContain('Синтетическая Анна');
 fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({animations:'disabled',path:path.join(root,'manager2-artifacts',`tour-tv-${browserName}-${width}.png`)});
 await page.getByRole('button',{name:'Проверьте в AnyTour',exact:true}).click();expect(await page.evaluate(()=>collectorStaged.length)).toBe(1);
 await page.evaluate(()=>{window.collectorResult='cancelled';window.collectorExtracts=0;const extract=AnyTourTourvisor.extract;AnyTourTourvisor.extract=(...args)=>{collectorExtracts++;return extract(...args)}});
 await page.getByRole('button',{name:'Проверьте в AnyTour',exact:true}).click({noWaitAfter:true});await expect.poll(()=>page.evaluate(()=>collectorExtracts)).toBe(1);await page.locator('.TVTourCardPriceValue').evaluate(e=>e.textContent='161 000');await expect(page.getByRole('button',{name:'Не добавлено'})).toBeVisible();expect(await page.evaluate(()=>collectorStaged.length)).toBe(1);expect(await page.evaluate(()=>nativeCreates)).toBe(0);await page.setViewportSize({width,height:420});await page.getByRole('button',{name:'Не добавлено'}).scrollIntoViewIfNeeded();await expect(page.getByRole('button',{name:'Не добавлено'})).toBeInViewport();
});

for(const width of [390,1440])for(const outcome of ['saved','cancelled'])test(`Tourvisor ordinary source tracks one destination through ${outcome} at ${width}`,async({page})=>{
 await page.setViewportSize({width,height:900});await tourvisorSourceFixture(page);const runtime=await canonicalCollectorSourceRuntime(page);
 await page.evaluate(()=>{window.nativeCreates=0;document.querySelector('#nativeContinue').onclick=()=>nativeCreates++});
 await page.addScriptTag({path:path.join(root,'extensions/selection-collector/tourvisor-content.js')});
 const button=page.locator('[data-anytour-add]:not([data-anytour-edit])');
 await button.click();await expect.poll(()=>runtime.db.items?.length).toBe(1);await expect(button).toBeEnabled();
 const operation=runtime.db.items[0].operation_id;
 expect(runtime.db.items[0].state).toBe('destination');expect(runtime.db.items[0].observation.raw.party_price_confirmation).toBe('pending');expect(runtime.server.calls).toHaveLength(0);
 await button.click();await expect(button).toBeEnabled();expect(runtime.db.items).toHaveLength(1);expect(runtime.requests.filter(m=>m.type==='enqueue')).toHaveLength(1);expect(runtime.requests.filter(m=>m.type==='begin_add')).toHaveLength(1);
 expect(await runtime.command({type:'confirm',operation_id:operation})).toEqual({ok:false});expect(runtime.server.calls).toHaveLength(0);
 if(outcome==='saved'){
  expect((await runtime.command({type:'confirm',operation_id:operation,confirm_price_basis:true})).ok).toBe(true);await expect.poll(()=>runtime.db.items[0].state).toBe('saved');
  await runtime.restart();await button.click();await expect(button).toHaveText('✓ В подборке');
  expect(runtime.server.creates).toBe(1);expect(runtime.server.calls.map(c=>c.action)).toEqual(['operation','import']);expect(runtime.requests.filter(m=>m.type==='enqueue')).toHaveLength(1);expect(runtime.requests.filter(m=>m.type==='begin_add')).toHaveLength(1);
 }else{
  expect((await runtime.command({type:'remove',operation_id:operation})).ok).toBe(true);await runtime.restart();
  expect(runtime.db.items[0].state).toBe('cancelled');await button.click();await expect.poll(()=>runtime.db.items.length).toBe(2);await expect(button).toBeEnabled();
  const fresh=runtime.db.items[1];expect(fresh.operation_id).not.toBe(operation);expect(fresh.state).toBe('destination');expect(fresh.observation.raw.party_price_confirmation).toBe('pending');expect(runtime.server.calls).toHaveLength(0);expect(runtime.requests.filter(m=>m.type==='enqueue')).toHaveLength(2);expect(runtime.requests.filter(m=>m.type==='begin_add')).toHaveLength(2);
 }
 expect(await page.evaluate(()=>nativeCreates)).toBe(0);expect(await page.locator('body').innerText()).not.toContain(runtime.db.target.client);expect(await page.locator('body').innerText()).not.toContain(runtime.db.target.trip);
});

for(const width of [390,1440])for(const editing of [false,true])test(`Tourvisor reused menu keeps separate pending ${editing?'edited':'ordinary'} cards at ${width}`,async({page})=>{
 await page.setViewportSize({width,height:900});await tourvisorSourceFixture(page);const runtime=await canonicalCollectorSourceRuntime(page);
 await page.addScriptTag({path:path.join(root,'extensions/selection-collector/tourvisor-content.js')});
 const button=page.locator(editing?'[data-anytour-edit]':'[data-anytour-add]:not([data-anytour-edit])');
 await button.click();await expect.poll(()=>runtime.db.items?.length).toBe(1);await expect(button).toBeEnabled();const original=runtime.db.items[0].operation_id;
 await page.evaluate(()=>{window.fixtureTourvisorButton=document.querySelector('[data-anytour-add]:not([data-anytour-edit])');location.hash='tvtourid=1234567891';document.querySelector('.TVTourCardWindow').setAttribute('data-tvtourlink','https://selection.example/#tvtourid=1234567891');document.querySelector('.TVBedIcon .TVTourCardOptionHeader').textContent='Deluxe room'});
 await button.click();await expect.poll(()=>runtime.db.items.length).toBe(2);await expect(button).toBeEnabled();
 expect(runtime.db.items[1].observation.quote_id).toBe('tv-snapshot-1234567891');expect(runtime.db.items[1].observation.offer.room).toBe('Deluxe room');expect(runtime.db.items[1].state).toBe(editing?'editing':'destination');
 await page.evaluate(()=>{location.hash='tvtourid=1234567890';document.querySelector('.TVTourCardWindow').setAttribute('data-tvtourlink','https://selection.example/#tvtourid=1234567890');document.querySelector('.TVBedIcon .TVTourCardOptionHeader').textContent='standard room'});
 await button.click();await expect(button).toBeEnabled();expect(runtime.db.items).toHaveLength(2);expect(runtime.db.items[0].operation_id).toBe(original);expect(runtime.db.items[0].observation.quote_id).toBe('tv-snapshot-1234567890');
 expect(await page.evaluate(()=>fixtureTourvisorButton===document.querySelector('[data-anytour-add]:not([data-anytour-edit])'))).toBe(true);
 expect(runtime.requests.filter(m=>m.type==='begin_add')).toHaveLength(2);expect(runtime.requests.filter(m=>m.type===(editing?'enqueue_edit':'enqueue'))).toHaveLength(2);expect(runtime.server.calls).toHaveLength(0);
 expect(await page.locator('body').innerText()).not.toContain(runtime.db.target.client);expect(await page.locator('body').innerText()).not.toContain(runtime.db.target.trip);
});

for(const width of [390,1440])test(`Tourvisor late saved result cannot mark a different reused card at ${width}`,async({page})=>{
 await page.setViewportSize({width,height:900});await tourvisorSourceFixture(page);const runtime=await canonicalCollectorSourceRuntime(page);
 await page.addScriptTag({path:path.join(root,'extensions/selection-collector/tourvisor-content.js')});
 const button=page.locator('[data-anytour-add]:not([data-anytour-edit])');await button.click();await expect.poll(()=>runtime.db.items?.length).toBe(1);await expect(button).toBeEnabled();
 let release,held=false;const gate=new Promise(resolve=>{release=resolve}),receive=runtime.server.receive.bind(runtime.server);
 runtime.server.receive=async command=>{if(command.action==='operation'){held=true;await gate}return receive(command)};
 expect((await runtime.command({type:'confirm',operation_id:runtime.db.items[0].operation_id,confirm_price_basis:true})).ok).toBe(true);await expect.poll(()=>held).toBe(true);
 const checks=runtime.requests.filter(m=>m.type==='add_status').length;await button.click();await expect.poll(()=>runtime.requests.filter(m=>m.type==='add_status').length).toBeGreaterThan(checks);await expect(button).toBeDisabled();
 await page.evaluate(()=>{location.hash='tvtourid=1234567891';document.querySelector('.TVTourCardWindow').setAttribute('data-tvtourlink','https://selection.example/#tvtourid=1234567891');document.querySelector('.TVBedIcon .TVTourCardOptionHeader').textContent='Deluxe room'});release();
 await expect.poll(()=>runtime.db.items[0].state).toBe('saved');await expect(button).toBeEnabled();await expect(button).not.toHaveText('✓ В подборке');await expect(page.locator('[data-anytour-note]')).toContainText('Карточка изменилась');
 expect(runtime.db.items).toHaveLength(1);expect(runtime.server.creates).toBe(1);expect(runtime.db.items[0].observation.quote_id).toBe('tv-snapshot-1234567890');
 await button.click();await expect.poll(()=>runtime.db.items.length).toBe(2);await expect(button).toBeEnabled();expect(runtime.db.items[1].observation.quote_id).toBe('tv-snapshot-1234567891');expect(runtime.db.items[1].state).toBe('destination');expect(runtime.server.creates).toBe(1);
});

for(const width of [390,1440])test(`Tourvisor ordinary source can restart after removing a blocked unconfirmed destination at ${width}`,async({page})=>{
 await page.setViewportSize({width,height:900});await tourvisorSourceFixture(page);const runtime=await canonicalCollectorSourceRuntime(page);
 await page.addScriptTag({path:path.join(root,'extensions/selection-collector/tourvisor-content.js')});
 const button=page.locator('[data-anytour-add]:not([data-anytour-edit])');await button.click();await expect.poll(()=>runtime.db.items?.length).toBe(1);await expect(button).toBeEnabled();
 const original=runtime.db.items[0].operation_id;expect(runtime.db.items[0].state).toBe('destination');
 await runtime.changeTarget();expect(runtime.db.items[0].state).toBe('blocked');expect(runtime.db.items[0].observation.raw.party_price_confirmation).toBe('pending');
 expect((await runtime.command({type:'remove',operation_id:original})).ok).toBe(true);await runtime.restart();expect(runtime.db.items[0].state).toBe('cancelled');
 await button.click();await expect.poll(()=>runtime.db.items.length).toBe(2);await expect(button).toBeEnabled();
 const fresh=runtime.db.items[1];expect(fresh.operation_id).not.toBe(original);expect(fresh.target.conversation_id).toBe(12);expect(fresh.state).toBe('destination');expect(fresh.observation.raw.party_price_confirmation).toBe('pending');
 expect(runtime.requests.filter(m=>m.type==='begin_add')).toHaveLength(2);expect(runtime.requests.filter(m=>m.type==='enqueue')).toHaveLength(2);expect(runtime.server.calls).toHaveLength(0);
 expect(await runtime.command({type:'confirm',operation_id:fresh.operation_id})).toEqual({ok:false});expect(runtime.server.calls).toHaveLength(0);
 expect(await page.locator('body').innerText()).not.toContain(runtime.db.target.client);expect(await page.locator('body').innerText()).not.toContain(runtime.db.target.trip);
});

for(const change of ['price','target'])test(`Tourvisor ordinary source refuses ${change} changes during capture`,async({page})=>{
 await tourvisorSourceFixture(page);const runtime=await canonicalCollectorSourceRuntime(page);
 await page.evaluate(()=>{window.collectorExtracts=0;const extract=AnyTourTourvisor.extract;AnyTourTourvisor.extract=(...args)=>{collectorExtracts++;return extract(...args)}});
 await page.addScriptTag({path:path.join(root,'extensions/selection-collector/tourvisor-content.js')});
 const button=page.locator('[data-anytour-add]:not([data-anytour-edit])');await button.click();await expect.poll(()=>page.evaluate(()=>collectorExtracts)).toBe(1);await expect.poll(()=>runtime.captureCount()).toBe(1);
 if(change==='price')await page.evaluate(()=>{document.querySelector('.TVTourCardPriceValue').textContent='161 000';document.querySelector('.TVTourCardIncludePrices').textContent='Стоимость тура 145 000 РУБ Топливный сбор 16 000 РУБ'});else await runtime.changeTarget();
 await expect(button).toHaveText('Не добавлено');expect(runtime.db.items||[]).toHaveLength(0);expect(runtime.server.calls).toHaveLength(0);
 expect(runtime.requests.filter(m=>m.type==='begin_add')).toHaveLength(1);expect(runtime.requests.filter(m=>m.type==='enqueue')).toHaveLength(change==='price'?0:1);
 await expect(page.locator('[data-anytour-note]')).toContainText(change==='price'?'Карточка ещё меняется':'Клиент или подключение изменились');
});

for(const width of [390,1440])test(`Tourvisor popup retains current price confirmation when another queued offer saves at ${width}`,async({page,browserName})=>{
 await page.setViewportSize({width,height:900});const s=await pricePopupFixture(page,{tv:true,state:'destination'});s.item.needs_edit_confirmation=false;
 const checkbox=page.getByRole('checkbox'),confirm=page.getByRole('button',{name:'Подтвердить цену и добавить для этого клиента'});
 await expect(confirm).toBeDisabled();await checkbox.check();await expect(confirm).toBeEnabled();
 s.state.items.push({...copy(s.item),operation_id:'f'.repeat(32),state:'queued'});await s.refresh();await expect(checkbox).toBeChecked();
 s.state.items[1].state='saved';s.state.target.count++;await s.refresh();await expect(checkbox).toBeChecked();await expect(confirm).toBeEnabled();
 fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({animations:'disabled',fullPage:true,path:path.join(root,'manager2-artifacts',`tour-selection-consent-${browserName}-${width}.png`)});
 expect(s.commands.filter(m=>m.type==='confirm')).toHaveLength(0);
 await checkbox.uncheck();await s.refresh();await expect(checkbox).not.toBeChecked();await expect(confirm).toBeDisabled();
 await checkbox.check();await confirm.click();await expect(page.locator('[data-operation="'+s.operation+'"]')).toContainText('Сохранено в подборке');
 expect(s.commands.filter(m=>m.type==='confirm')).toEqual([{type:'confirm',operation_id:s.operation,confirm_price_basis:true}]);
});

for(const change of ['selected-target','connection','price','operation','blocked','reopen'])test(`Tourvisor popup forgets price confirmation after ${change}`,async({page})=>{
 const s=await pricePopupFixture(page,{tv:true,state:'destination'}),checkbox=page.getByRole('checkbox'),confirm=page.getByRole('button',{name:'Подтвердить цену и добавить для этого клиента'});
 await checkbox.check();await expect(confirm).toBeEnabled();
 if(change==='reopen')await page.reload();
 else{
  const target=copy(s.state.target),item=copy(s.item);
  if(change==='selected-target')s.state.target.conversation_id=12;
  if(change==='connection'){s.item.target.connection.id='d'.repeat(32);s.state.target.connection.id='d'.repeat(32)}
  if(change==='price')s.item.observation.offer.amount='240000';
  if(change==='operation')s.item.operation_id='f'.repeat(32);
  if(change==='blocked')s.item.state='blocked';
  await s.refresh();
  s.state.target=target;Object.assign(s.item,item);await s.refresh();
 }
 await expect(checkbox).not.toBeChecked();await expect(confirm).toBeDisabled();expect(s.commands.filter(m=>m.type==='confirm')).toHaveLength(0);
});

for(const failure of ['reject','lost'])test(`Tourvisor popup consumes price confirmation when confirm response is ${failure}`,async({page})=>{
 const s=await pricePopupFixture(page,{tv:true,state:'destination',confirmFailure:failure}),checkbox=page.getByRole('checkbox'),confirm=page.getByRole('button',{name:'Подтвердить цену и добавить для этого клиента'});
 await checkbox.check();await confirm.click();await expect(checkbox).not.toBeChecked();await expect(confirm).toBeDisabled();
 await expect(page.locator('#notice')).toContainText(failure==='lost'?'Ответ о действии потерялся':'Не удалось подтвердить действие');await s.refresh();await expect(checkbox).not.toBeChecked();
 expect(s.commands.filter(m=>m.type==='confirm')).toEqual([{type:'confirm',operation_id:s.operation,confirm_price_basis:true}]);
});

for(const failure of ['lost','invalid'])test(`Tourvisor popup forgets price confirmation when status is ${failure}`,async({page})=>{
 const s=await pricePopupFixture(page,{tv:true,state:'destination'}),checkbox=page.getByRole('checkbox'),confirm=page.getByRole('button',{name:'Подтвердить цену и добавить для этого клиента'});
 await checkbox.check();s.failNextStatus(failure);await page.evaluate(()=>pricePopupRefresh());await expect(checkbox).not.toBeChecked();await expect(confirm).toBeDisabled();await expect(page.locator('#notice')).toContainText('Расширение временно недоступно');
 await s.refresh();await expect(checkbox).not.toBeChecked();await expect(confirm).toBeDisabled();expect(s.commands.filter(m=>m.type==='confirm')).toHaveLength(0);
});

test('Tourvisor popup retains a price check made during a pending status refresh',async({page})=>{
 const s=await pricePopupFixture(page,{tv:true,state:'destination'}),checkbox=page.getByRole('checkbox'),confirm=page.getByRole('button',{name:'Подтвердить цену и добавить для этого клиента'});
 await expect(confirm).toBeDisabled();const prior=await checkbox.elementHandle();s.holdNextStatus();await page.evaluate(()=>pricePopupRefresh());await expect.poll(()=>s.statusHeld()).toBe(true);
 await checkbox.check();s.releaseStatus();await expect.poll(()=>prior.evaluate(node=>node.isConnected)).toBe(false);await expect(checkbox).toBeChecked();await expect(confirm).toBeEnabled();expect(s.commands.filter(m=>m.type==='confirm')).toHaveLength(0);
});

test('Tourvisor popup requires explicit room price basis and keeps destination visible',async({page,browserName})=>{
 const ext=path.join(root,'extensions/selection-collector'),target={actor:7,conversation_id:11,binding:'b'.repeat(64),connection:{id:'c'.repeat(32)},client:'Синтетическая Анна',channel:'telegram',trip:'Синтетическая поездка',count:0},data={ok:true,target,items:[{operation_id:'tv1',state:'destination',target:copy(target),observation:{adapter:'tourvisor-snapshot-v1',offer:{hotel:'Синтетический Tourvisor',date_from:'2026-10-29',nights:11,room:'standard room',amount:'160000',currency:'RUB'},raw:{party_price_confirmation:'pending'}}}]};
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
 await sourceFixture(page);let release;await page.route('https://b2b.fstravel.com/hotel/10',async route=>{await new Promise(r=>release=r);await route.fulfill({contentType:'text/html',body:'<h2>Синтетический отель</h2><img class="slider-img" src="https://apigate-tui.fstravel.com/api/geocontent/static/Hotel/00180000-ac11-0242-6c05-08d9934f9e4b/MainPhoto-Source-test.jpg">'})});
 await collectorSourceRuntime(page);await page.addScriptTag({path:path.join(root,'extensions/selection-collector/content.js')});await page.getByRole('button',{name:'В подборку AnyTour',exact:true}).click();await expect.poll(()=>!!release).toBe(true);
 await page.locator('.price').evaluate(e=>{e.setAttribute('data-converted-price-number','250000');e.textContent='250 000 RUB'});release();await expect(page.locator('[data-anytour-note]')).toContainText('Условия изменились');expect(await page.evaluate(()=>collectorStaged.length)).toBe(0);
});
for(const width of [390,1440])test('FUN&SUN HBK signed inventory imports exact row and automatic gallery through canonical draft at '+width,async({page,browserName})=>{
 await page.setViewportSize({width,height:900});const source=await sourceFixture(page,{partner:true,ajax:true});await collectorSourceRuntime(page);await page.addScriptTag({path:path.join(root,'extensions/selection-collector/content.js')});await source.search();
 await page.locator('tr.price_info').evaluate(row=>{window.nativeCreates=0;row.querySelector('.price').onclick=()=>nativeCreates++});
 await page.getByRole('button',{name:'В подборку AnyTour',exact:true}).click();await expect(page.getByRole('button',{name:'✓ В подборке',exact:true})).toBeVisible();
 const observations=await page.evaluate(()=>collectorStaged.map(x=>x.observation));expect(observations).toHaveLength(1);const observation=observations[0];expect(observation.keys).toMatchObject({hotel:'-10',room:'-2147483647',placement:'-2147483647',spo:'',partner:'HBK'});expect(observation.raw.hotel_url).toBe('https://b2b.fstravel.com/hotel/-10?partner=HBK');expect(observation.offer.room).toBe('DOUBLE STANDARD / 2ADL');expect(observation.offer.amount).toBe('237017');expect(observation.photos).toEqual(source.partnerPhotos);expect(observation.offer).not.toHaveProperty('flights');expect(await page.evaluate(()=>nativeCreates)).toBe(0);expect(await page.locator('body').innerText()).not.toContain('Синтетическая Анна');expect(source.calls).toEqual([]);expect(source.galleryRequests.slice().sort()).toEqual([observation.raw.hotel_url,...source.partnerPhotos].sort());expect(await page.locator('iframe').count()).toBe(0);
 const imported=JSON.parse(execFileSync('php',['-r',`require 'tests/support/manager-tour-collector.php';$pdo=deliveryDb();$selection=collectorImportedDraft($pdo,json_decode(stream_get_contents(STDIN),true));echo json_encode($selection['draft'],JSON_THROW_ON_ERROR);`],{cwd:root,input:JSON.stringify(observation),encoding:'utf8'}));expect(imported.offers).toHaveLength(1);expect(imported.offers[0].import_source.keys).toEqual(observation.keys);
 const editor=await page.context().newPage();await editor.setViewportSize({width,height:900});const s=await fixture(editor,{draft:imported});await editor.route('https://hbpro.expert/resource/hotel/photo_1200/11/22/*.jpg',route=>route.fulfill({contentType:'image/jpeg',body:fs.readFileSync(path.join(root,'assets/owner-tour-cards/compact-v1/cover.jpg'))}));await open(editor);await expect(editor.locator('.tsAutomaticPhotos .tsUploadedThumb')).toHaveCount(3);await editor.locator('.tsAutomaticPhotos').evaluate(el=>el.scrollIntoView({block:'start'}));await expect.poll(()=>editor.locator('.tsAutomaticPhotos img').evaluateAll(xs=>xs.length===3&&xs.every(x=>x.complete&&x.naturalWidth>0))).toBe(true);
 await editor.locator('#ts-amount').fill('240000');await save(editor);expect(s.drafts.get(11).draft.offers[0].import_source).toEqual(imported.offers[0].import_source);await editor.locator('#tsPreview').click();await expect(editor.locator('.tsPhotos img')).toHaveCount(3);await expect(editor.locator('.tsCardPrice')).toContainText('240 000');await expect(editor.locator('.tsCardText')).toContainText('Фотографии отеля.');await expect(editor.locator('.tsCardText')).toContainText('Интерьер выбранного номера может отличаться');expect(s.photoUploads).toHaveLength(0);expect(s.creations()).toBe(0);expect(await editor.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);
 fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await editor.screenshot({animations:'disabled',path:path.join(root,'manager2-artifacts',`tour-selection-funsun-hbk-${browserName}-${width}.png`)});clean(s,deliveryActions);await editor.close();
});
test('FUN&SUN native numeric gallery survives its canonical navigation with exact hotel heading',async({page})=>{
 const source=await sourceFixture(page,{canonical:true});await collectorSourceRuntime(page);await page.addScriptTag({path:path.join(root,'extensions/selection-collector/content.js')});await page.getByRole('button',{name:'В подборку AnyTour',exact:true}).click();await expect(page.getByRole('button',{name:'✓ В подборке',exact:true})).toBeVisible();expect(await page.evaluate(()=>collectorStaged[0].observation.photos)).toEqual(source.nativePhotos);expect(source.galleryRequests).toContain('https://b2b.fstravel.com/hotel/10');expect(source.galleryRequests).toContain('https://b2b.fstravel.com/hotel/europe/turkey/synthetic-hotel');expect(source.calls).toEqual([]);expect(await page.locator('iframe').count()).toBe(0);
});
test('FUN&SUN retries one incomplete gallery before its single import',async({page})=>{
 test.setTimeout(45000);
 const source=await sourceFixture(page,{canonical:true,incompleteGalleryOnce:true});await collectorSourceRuntime(page);await page.addScriptTag({path:path.join(root,'extensions/selection-collector/content.js')});
 await page.getByRole('button',{name:'В подборку AnyTour',exact:true}).click();await expect(page.getByRole('button',{name:'✓ В подборке',exact:true})).toBeVisible({timeout:25000});
 const staged=await page.evaluate(()=>collectorStaged),commands=await page.evaluate(()=>collectorRequests);
 expect(staged).toHaveLength(1);expect(staged[0].observation.photos).toEqual(source.nativePhotos);expect(staged[0].observation.offer.amount).toBe('237017');
 expect(commands.filter(x=>x.type==='begin_add')).toHaveLength(1);expect(commands.filter(x=>x.type==='enqueue')).toHaveLength(1);
 expect(source.galleryRequests.filter(x=>x==='https://b2b.fstravel.com/hotel/10')).toHaveLength(2);expect(source.galleryRequests).toContain('https://b2b.fstravel.com/hotel/europe/turkey/synthetic-hotel');
 expect(await page.locator('iframe').count()).toBe(0);expect(await page.locator('input[type=file]').count()).toBe(0);expect(source.calls).toEqual([]);
});
for(const partner of [false,true])test('FUN&SUN '+(partner?'HBK':'numeric')+' gallery waits for the loaded hotel heading',async({page})=>{
 const source=await sourceFixture(page,{partner}),photos=partner?source.partnerPhotos:source.nativePhotos;
 const href='https://b2b.fstravel.com/hotel/'+(partner?'-10?partner=HBK':'10');
 await page.route(href,route=>route.fulfill({contentType:'text/html',body:'<meta charset="utf-8"><h2 class="hotel_name">Другой отель</h2>'+photos.map(src=>'<img class="slider-img" src="'+src+'">').join('')}));
 await collectorSourceRuntime(page);await page.evaluate(()=>{window.galleryReads=0;const read=AnyTourFunsun.photos;AnyTourFunsun.photos=(...args)=>{galleryReads++;return read(...args)}});
 await page.addScriptTag({path:path.join(root,'extensions/selection-collector/content.js')});await page.getByRole('button',{name:'В подборку AnyTour',exact:true}).click();
 await expect.poll(()=>page.evaluate(()=>galleryReads)).toBeGreaterThan(0);expect(await page.evaluate(()=>collectorStaged.length)).toBe(0);
 await page.frameLocator('iframe').locator('.hotel_name').evaluate(e=>e.textContent='Синтетический отель');
 await expect(page.getByRole('button',{name:'✓ В подборке',exact:true})).toBeVisible();
 const saved=await page.evaluate(()=>collectorStaged[0].observation);expect(saved.photos).toEqual(photos);expect(saved.offer.amount).toBe('237017');expect(saved.offer.hotel).toBe('Синтетический отель');expect(source.calls).toEqual([]);expect(await page.locator('iframe').count()).toBe(0);
});
test('FUN&SUN waits for three changing gallery images instead of keeping the first set',async({page})=>{
 const source=await sourceFixture(page,{partner:true}),photos=source.partnerPhotos;
 await page.route('https://b2b.fstravel.com/hotel/-10?partner=HBK',route=>route.fulfill({contentType:'text/html',body:'<meta charset="utf-8"><h2 class="hotel_name">Синтетический отель</h2>'+photos.slice().reverse().map(src=>'<img class="slider-img" src="'+src+'">').join('')}));
 await collectorSourceRuntime(page);await page.evaluate(photos=>{window.galleryReads=0;window.galleryHold=true;const read=AnyTourFunsun.photos;AnyTourFunsun.photos=(...args)=>{galleryReads++;if(galleryHold)args[0].querySelectorAll('img.slider-img').forEach((x,i)=>x.src=photos[(i+galleryReads)%photos.length]);return read(...args)}},photos);
 await page.addScriptTag({path:path.join(root,'extensions/selection-collector/content.js')});await page.getByRole('button',{name:'В подборку AnyTour',exact:true}).click();
 await expect.poll(()=>page.evaluate(()=>galleryReads)).toBeGreaterThan(0);expect(await page.evaluate(()=>collectorStaged.length)).toBe(0);
 await page.evaluate(photos=>{window.galleryHold=false;document.querySelector('iframe').contentDocument.querySelectorAll('img.slider-img').forEach((x,i)=>x.src=photos[i])},photos);
 await expect(page.getByRole('button',{name:'✓ В подборке',exact:true})).toBeVisible();expect(await page.evaluate(()=>collectorStaged[0].observation.photos)).toEqual(photos);expect(source.calls).toEqual([]);expect(await page.locator('iframe').count()).toBe(0);
});
test('FUN&SUN HBK requires exact partner hotel link and sentinel profile before enqueue',async({page})=>{
 const source=await sourceFixture(page,{partner:true});const original=await page.locator('tr.price_info').getAttribute('class');
 for(const href of ['https://b2b.fstravel.com/hotel/-10?partner=OTHER','https://b2b.fstravel.com/hotel/-11?partner=HBK','https://b2b.fstravel.com/hotel/-10?partner=HBK&token=secret','https://other.invalid/hotel/-10?partner=HBK']){await page.locator('.link-hotel a').evaluate((e,href)=>e.href=href,href);expect((await source.read()).error).toBeTruthy()}
 await page.locator('.link-hotel a').evaluate(e=>e.href='https://b2b.fstravel.com/hotel/-10?partner=HBK');expect((await source.read()).error).toBeUndefined();await page.locator('tr.price_info').evaluate(e=>{e.dataset.room='20';e.classList.replace('roomKey--2147483647','roomKey-20')});expect((await source.read()).error).toContain('FS-room-format');await page.locator('tr.price_info').evaluate((e,original)=>{e.className=original;e.dataset.room='-2147483647';e.classList.add('spoKey-60')},original);expect((await source.read()).error).toBeTruthy();expect(source.galleryRequests).toEqual([]);expect(source.calls).toEqual([]);
});
test('FUN&SUN HBK source changes while partner gallery loads never enqueue an old offer',async({page})=>{
 const source=await sourceFixture(page,{partner:true});let release;await page.route('https://b2b.fstravel.com/hotel/-10?partner=HBK',async route=>{await new Promise(r=>release=r);await route.fulfill({contentType:'text/html',body:'<h2>Синтетический отель</h2>'+source.partnerPhotos.map(src=>'<img class="slider-img" src="'+src+'">').join('')})});await collectorSourceRuntime(page);await page.addScriptTag({path:path.join(root,'extensions/selection-collector/content.js')});await page.getByRole('button',{name:'В подборку AnyTour',exact:true}).click();await expect.poll(()=>!!release).toBe(true);await page.locator('.hotel-room').evaluate(e=>e.textContent='DOUBLE SUPERIOR / 2ADL');release();await expect(page.locator('[data-anytour-note]')).toContainText('Условия изменились');expect(await page.evaluate(()=>collectorStaged.length)).toBe(0);expect(await page.locator('iframe').count()).toBe(0);expect(source.calls).toEqual([]);
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
 await page.locator('#tsPreview').click();await expect(page.locator('.tsPhotos img')).toHaveCount(3);await expect(page.locator('.tsCardText')).toContainText('Фотографии отеля.');await expect(page.locator('#tsSend')).toBeEnabled();expect(s.photoUploads).toHaveLength(0);expect(s.creations()).toBe(0);clean(s,deliveryActions);
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
 const draft=automaticPhotoDraft();draft.offers[0].photos=[];draft.offers[0].import_source.photos=[];const s=await fixture(page,{draft});await open(page,{edit:false});await expect(page.locator('#tsOfferDetails')).not.toHaveAttribute('open','');expect(await page.locator('.tsPhotoFields input[type=file]').count()).toBe(0);
 await page.locator('#tsPreview').click();await expect(page.locator('#tsStatus')).toContainText('фото отеля');await expect(page.locator('#tsStatus')).not.toContainText('три фото');await expect(page.locator('#tsSend')).toBeHidden();
 await page.locator('[data-ts-fix-field="photos"]').click();await expect(page.locator('#tsOfferDetails')).toHaveAttribute('open','');await expect(page.locator('.tsAutomaticPhotoStatus')).toBeVisible();await expect(page.locator('.tsAutomaticPhotoStatus')).toContainText('Если результат неизвестен, не добавляйте тур повторно');expect(s.creations()).toBe(0);clean(s,deliveryActions);
});

for(const width of [390,1440])test('imported empty gallery can be corrected in the same saved offer at '+width,async({page,browserName})=>{
 await page.setViewportSize({width,height:900});const draft=automaticPhotoDraft(0);
 draft.offers[0].amount='250000';
 for(const id of ['b','c']){const other=copy(completeDraft().offers[0]);other.id=id.repeat(32);draft.offers.push(other)}
 const original=copy(draft),s=await fixture(page,{draft});await open(page);
 await expect(page.locator('.tsPhotoFields input[type=file]')).toHaveCount(0);
 expect((await page.locator('[data-ts-photo-mode="manual"]').boundingBox()).height).toBeGreaterThanOrEqual(44);
 await page.locator('[data-ts-photo-mode="manual"]').click();await expect(page.locator('.tsPhotoFields input[type=file]')).toHaveCount(3);
 expect((await page.locator('[data-ts-photo-mode="auto"]').boundingBox()).height).toBeGreaterThanOrEqual(44);
 await expect(page.locator('.tsPhotoFields')).toContainText('достаточно одного фото');
 expect(s.calls.map(x=>x.action)).toEqual(['get']);expect(s.photoUploads).toHaveLength(0);expect(s.drafts.get(11).draft).toEqual(original);
 await page.locator('#ts-upload-cover').setInputFiles(path.join(root,'assets/owner-tour-cards/compact-v1/cover.jpg'));
 await expect(page.locator('#tsStatus')).toContainText('Фото добавлено');await expect(page.locator('#tsSaved')).toHaveText('Сохранено на сервере');
 const stored=copy(s.drafts.get(11).draft);expect(stored.offers[0].photos).toHaveLength(1);expect(stored.offers[0].photos[0].url).toContain('&draft_photo=');
 stored.offers[0].photos=[];expect(stored).toEqual(original);expect(s.drafts.get(11).draft.offers.map(x=>x.id)).toEqual(original.offers.map(x=>x.id));
 await page.locator('[data-ts-photo-mode="auto"]').click();await expect(page.locator('.tsAutomaticPhotoStatus')).toHaveText('Фото добавлены менеджером. Проверьте их в предпросмотре.');
 await expect.poll(()=>page.locator('.tsAutomaticPhotos img').evaluateAll(xs=>xs.length===1&&xs.every(x=>x.complete&&x.naturalWidth>0))).toBe(true);
 await page.locator('.tsAutomaticPhotos').scrollIntoViewIfNeeded();expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);
 fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({animations:'disabled',path:path.join(root,'manager2-artifacts',`tour-selection-photo-correction-${browserName}-${width}.png`)});
 await page.locator('#tsPreview').click();await expect(page.locator('.tsPreviewCard')).toHaveCount(3);await expect(page.locator('.tsPreviewCard').first().locator('.tsPhotos img')).toHaveCount(1);await expect(page.locator('#tsSend')).toBeEnabled();
 expect(s.calls.filter(x=>x.action==='save')).toHaveLength(1);expect(s.creations()).toBe(0);clean(s,deliveryActions);
});

test('imported gallery correction preserves sibling photos and original quote',async({page})=>{
 const draft=automaticPhotoDraft(3),original=copy(draft),s=await fixture(page,{draft});await open(page);
 await page.locator('[data-ts-photo-mode="manual"]').click();await page.locator('#ts-upload-cover').setInputFiles(path.join(root,'assets/owner-tour-cards/compact-v1/cover.jpg'));
 await expect(page.locator('#tsStatus')).toContainText('Фото добавлено');await expect(page.locator('#tsSaved')).toHaveText('Сохранено на сервере');
 const stored=copy(s.drafts.get(11).draft);expect(stored.offers[0].photos.slice(1)).toEqual(original.offers[0].photos.slice(1));stored.offers[0].photos=original.offers[0].photos;expect(stored).toEqual(original);
 await page.locator('[data-ts-photo-mode="auto"]').click();await expect(page.locator('.tsAutomaticPhotoStatus')).toContainText('дополнены или заменены менеджером');await expect(page.locator('.tsUploadedLabel').filter({hasText:'Из галереи источника'})).toHaveCount(2);
 await savedGalleryPhotos(page,s,3);expect(s.creations()).toBe(0);clean(s);
});

test('imported empty gallery correction obeys readonly access',async({page})=>{
 const draft=automaticPhotoDraft(0),s=await fixture(page,{draft,readOnly:true});await open(page);await expect(page.locator('[data-ts-photo-mode="manual"]')).toBeDisabled();
 expect(s.photoUploads).toHaveLength(0);expect(s.calls.map(x=>x.action)).toEqual(['get']);expect(s.drafts.get(11).draft).toEqual(draft);clean(s);
});

test('older imported offer keeps its existing three-role preview requirement',async({page})=>{
 const draft=automaticPhotoDraft(0);delete draft.offers[0].import_source.photos;const original=copy(draft),s=await fixture(page,{draft});await open(page);
 await page.locator('[data-ts-photo-mode="manual"]').click();await expect(page.locator('.tsPhotoFields')).toContainText('нужны фото отеля, пляжа или бассейна и пример номера');
 await page.locator('#ts-upload-cover').setInputFiles(path.join(root,'assets/owner-tour-cards/compact-v1/cover.jpg'));await expect(page.locator('#tsStatus')).toContainText('Фото добавлено');
 const stored=copy(s.drafts.get(11).draft);expect(stored.offers[0].photos).toHaveLength(1);stored.offers[0].photos=[];expect(stored).toEqual(original);
 const prepared=page.waitForResponse(r=>r.url().includes('/tour-selections-api.php')&&r.request().postDataJSON()?.action==='prepare');await page.locator('#tsPreview').click();
 expect(await (await prepared).json()).toMatchObject({ok:false,error:'incomplete_offers',fields:[{offer_id:draft.offers[0].id,fields:['photos']}]});
 await expect(page.locator('#tsStatus')).toContainText('фото отеля');await expect(page.locator('#tsSend')).toBeHidden();expect(s.creations()).toBe(0);clean(s,deliveryActions);
});

test('imported photo correction pending upload cannot write to the next client',async({page})=>{
 const draft=automaticPhotoDraft(0),s=await fixture(page,{draft,uploadMode:'hold'});await open(page);await page.locator('[data-ts-photo-mode="manual"]').click();
 await page.locator('#ts-upload-cover').setInputFiles(path.join(root,'assets/owner-tour-cards/compact-v1/cover.jpg'));await expect.poll(()=>s.photoUploads.length).toBe(1);await expect(page.locator('[data-ts-photo-mode="auto"]')).toBeDisabled();
 await page.locator('#tsClose').click();await page.locator('#inboxList [data-conversation-id="12"]').click();await open(page);s.releaseUpload();
 await expect(page.locator('#tsEmptyAdd')).toBeVisible();expect(s.drafts.has(12)).toBe(false);expect(s.calls.filter(x=>x.action==='save')).toHaveLength(0);expect(s.drafts.get(11).draft).toEqual(draft);clean(s);
});

for(const width of [390,1440])test('selection print uses exact frozen cards and loaded photos without a tab or write at '+width,async({page,browserName})=>{
 await page.setViewportSize({width,height:900});const draft=completeDraft();draft.offers.push({...copy(draft.offers[0]),id:'b'.repeat(32),hotel:'Второй синтетический отель',amount:'123456.78',currency:'EUR'});
 const s=await fixture(page,{draft});await page.locator('#replyText').fill('PRIVATE COMPOSER MUST NOT PRINT');await open(page);await expect(page.locator('#tsPrint')).toBeHidden();await page.locator('#tsPreview').click();await expect(page.locator('#tsPrint')).toBeEnabled();
 const calls=copy(s.calls),original=copy(s.publication()),before=copy(s.drafts.get(11)),pages=page.context().pages().length;
 const requests=[];page.on('request',req=>requests.push(req.url()));
 await page.evaluate(()=>{window.printCalls=0;window.print=()=>{printCalls++};window.previewImages=Array.from(document.querySelectorAll('.tsPhotos img'))});
 await page.locator('#tsPrint').click();expect(await page.evaluate(()=>printCalls)).toBe(1);expect(s.calls).toEqual(calls);expect(s.creations()).toBe(0);expect(s.drafts.get(11)).toEqual(before);expect(page.context().pages()).toHaveLength(pages);expect(requests).toEqual([]);
 expect(await page.locator('.tsPrintOffer .tsCardText').allTextContents()).toEqual(original.cards.map(card=>card.text));await expect(page.locator('.tsPrintVersion')).toHaveText('Версия подборки 1');
 expect(await page.evaluate(()=>previewImages.every(img=>img.closest('.tsPrintProposal')&&img.complete&&img.naturalWidth>0))).toBe(true);
 await page.emulateMedia({media:'print'});await expect(page.locator('.tsPrintProposal')).toBeVisible();await expect(page.locator('.tsShell')).toBeHidden();await expect(page.locator('#replyText')).toBeHidden();await expect(page.locator('.tsPrintProposal')).not.toContainText('PRIVATE COMPOSER');
 await expect(page.locator('.tsPrintPhotos img')).toHaveCount(6);expect(await page.locator('.tsPrintPhotos img').evaluateAll(imgs=>imgs.every(img=>getComputedStyle(img).display!=='none'&&img.getBoundingClientRect().width>0))).toBe(true);
 expect(await page.locator('.tsPrintProposal').evaluate(el=>el.scrollWidth<=el.clientWidth+1)).toBe(true);
 expect(await page.locator('.tsPrintProposal').evaluate(el=>{const r=el.getBoundingClientRect();return r.left>=-1&&r.right<=innerWidth+1})).toBe(true);
 fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({animations:'disabled',fullPage:true,path:path.join(root,'manager2-artifacts',`tour-selection-print-${browserName}-${width}.png`)});
 await page.evaluate(()=>window.dispatchEvent(new Event('afterprint')));await page.emulateMedia({media:'screen'});await expect(page.locator('.tsPrintProposal')).toHaveCount(0);await expect(page.locator('#tsPrint')).toBeEnabled();
 expect(await page.evaluate(()=>previewImages.every(img=>img.closest('.tsPreviewCard')))).toBe(true);await page.locator('#tsEdit').click();await page.locator('#tsClose').click();await expect(page.locator('#replyText')).toHaveValue('PRIVATE COMPOSER MUST NOT PRINT');expect(s.calls).toEqual(calls);clean(s,deliveryActions);
});
for(const failed of [false,true])test('selection print blocks '+(failed?'broken':'pending')+' preview photos',async({page})=>{
 const s=await fixture(page,{draft:completeDraft(),holdPhotos:!failed,photoError:failed});await open(page);await page.locator('#tsPreview').click();await expect(page.locator('.tsPreviewCard')).toHaveCount(1);await expect(page.locator('#tsPrint')).toBeDisabled();
 await page.evaluate(()=>{window.printCalls=0;window.print=()=>{printCalls++};document.getElementById('tsPrint').click()});expect(await page.evaluate(()=>printCalls)).toBe(0);
 if(!failed){s.releasePhotos();await expect(page.locator('#tsPrint')).toBeEnabled()}else await expect(page.locator('#tsStatus')).toContainText('Не удалось загрузить фото');clean(s,deliveryActions);
});
test('selection readonly history prints original terms after the editable draft changed',async({page})=>{
 const options={draft:completeDraft()},s=await fixture(page,options);await open(page);await page.locator('#tsPreview').click();await expect(page.locator('#tsPrint')).toBeEnabled();const original=copy(s.publication());await page.locator('#tsEdit').click();await page.locator('#ts-hotel').fill('NEW PRIVATE DRAFT');await save(page);await page.locator('#tsClose').click();options.readOnly=true;await open(page);
 await page.locator('#tsHistory').click();await page.getByRole('button',{name:/Версия 1 ·/}).click();await expect(page.locator('#tsPrint')).toBeEnabled();const before=copy(s.calls);await page.evaluate(()=>{window.print=()=>{}});await page.locator('#tsPrint').click();expect(await page.locator('.tsPrintOffer .tsCardText').allTextContents()).toEqual(original.cards.map(card=>card.text));await expect(page.locator('.tsPrintProposal')).not.toContainText('NEW PRIVATE DRAFT');expect(s.calls).toEqual(before);expect(s.drafts.get(11).draft.offers[0].hotel).toBe('NEW PRIVATE DRAFT');expect(s.creations()).toBe(0);clean(s,deliveryActions);
});
test('selection print is removed on client switch and old button cannot print the next client',async({page})=>{
 const s=await fixture(page,{draft:completeDraft()});await open(page);await page.locator('#tsPreview').click();await expect(page.locator('#tsPrint')).toBeEnabled();await page.evaluate(()=>{window.printCalls=0;window.print=()=>{printCalls++};window.oldPrintButton=document.getElementById('tsPrint')});await page.locator('#tsPrint').click();await expect(page.locator('.tsPrintProposal')).toHaveCount(1);await page.locator('#tsClose').click();await expect(page.locator('.tsPrintProposal')).toHaveCount(0);await page.locator('#inboxList [data-conversation-id="12"]').click();await open(page);await page.evaluate(()=>oldPrintButton.click());expect(await page.evaluate(()=>printCalls)).toBe(1);await expect(page.locator('.tsPrintProposal')).toHaveCount(0);expect(s.drafts.has(12)).toBe(false);clean(s,deliveryActions);
});

async function observeImageExport(frame){
 await frame.evaluate(()=>{
  window.exportInk=[];window.exportPhotoSources=[];window.exportBlobUrls=new Set();
  const text=CanvasRenderingContext2D.prototype.fillText,photo=CanvasRenderingContext2D.prototype.drawImage,create=URL.createObjectURL.bind(URL),revoke=URL.revokeObjectURL.bind(URL);
  CanvasRenderingContext2D.prototype.fillText=function(value,...rest){exportInk.push(value);return text.call(this,value,...rest)};
  CanvasRenderingContext2D.prototype.drawImage=function(image,...rest){exportPhotoSources.push(image.getAttribute('src'));return photo.call(this,image,...rest)};
  URL.createObjectURL=value=>{const url=create(value);exportBlobUrls.add(url);return url};URL.revokeObjectURL=url=>{exportBlobUrls.delete(url);return revoke(url)};
 });
}
async function saveExportPng(download,browserName,label){
 const file=path.join(root,'manager2-artifacts',`tour-selection-image-${browserName}-${label}.png`);fs.mkdirSync(path.dirname(file),{recursive:true});await download.saveAs(file);
 const bytes=fs.readFileSync(file);expect(bytes.subarray(0,8).toString('hex')).toBe('89504e470d0a1a0a');expect(bytes.readUInt32BE(16)).toBe(1080);expect(bytes.readUInt32BE(20)).toBeGreaterThan(600);expect(bytes.readUInt32BE(20)).toBeLessThanOrEqual(8192);return file;
}
async function downloadPreparedImage(page,scope=page){
 const link=scope.locator('[data-ts-image-download]');await expect(link).toBeVisible();const received=page.waitForEvent('download');await link.click();return received;
}
for(const width of [390,430,768,1440])test('selection image export keeps immutable text photos and version '+width,async({page,browserName})=>{
 await page.setViewportSize({width,height:900});const s=await fixture(page,{draft:completeDraft()});await page.locator('#replyText').fill('PRIVATE COMPOSER MUST NOT EXPORT');await open(page);await expect(page.locator('.tsExportImage')).toHaveCount(0);await page.locator('#tsPreview').click();
 const button=page.locator('[data-ts-image-export="0"]');await expect(button).toBeEnabled();await button.scrollIntoViewIfNeeded();expect(await button.evaluate(el=>el.getBoundingClientRect().height)).toBeGreaterThanOrEqual(44);expect(await page.locator('#tourSelectionDialog').evaluate(el=>el.scrollWidth-el.clientWidth)).toBeLessThanOrEqual(1);
 const original=copy(s.publication()),before=copy(s.snapshot(11)),calls=copy(s.calls),pages=page.context().pages().length;await observeImageExport(page);
 if(width===390){await button.focus();await page.keyboard.press('Enter')}else await button.click();const link=page.locator('[data-ts-image-download]');await expect(link).toBeVisible();if(width===390){await expect(link).toBeFocused();await expect(link).toHaveCSS('outline-style','solid');await expect.poll(()=>link.evaluate(el=>parseFloat(getComputedStyle(el).outlineWidth))).toBeGreaterThanOrEqual(3);}await link.scrollIntoViewIfNeeded();fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({animations:'disabled',path:path.join(root,'manager2-artifacts',`tour-selection-image-ready-${browserName}-${width}.png`)});const download=await downloadPreparedImage(page);expect(download.suggestedFilename()).toBe('AnyTour-v1-variant-1.png');await saveExportPng(download,browserName,String(width));
 const drawn=await page.evaluate(()=>({text:exportInk.join(' '),photos:exportPhotoSources}));expect(drawn.text.replace(/\s/g,'')).toContain(original.cards[0].text.replace(/\s/g,''));expect(drawn.text).toContain('Версия подборки 1');expect(drawn.text).not.toContain('PRIVATE COMPOSER');expect(drawn.photos).toEqual(original.cards[0].photos);
 expect(s.snapshot(11)).toEqual(before);expect(s.publication()).toEqual(original);expect(s.calls).toEqual(calls);expect(page.context().pages()).toHaveLength(pages);expect(s.creations()).toBe(0);await expect(button).toBeEnabled();await expect.poll(()=>page.evaluate(()=>exportBlobUrls.size)).toBe(0);await expect(button).toBeVisible();clean(s,deliveryActions);
});
test('selection image export keeps gallery photos neutral and source price distinct',async({page,browserName})=>{
 const b=biblioFixture(),draft={title:'Синтетическая подборка',intro:'',offers:[b.saved]};draft.offers[0].amount='130000';const s=await fixture(page,{draft,biblio:b});await open(page);await page.locator('#tsPreview').click();await expect(page.locator('.tsExportImage')).toBeEnabled();expect(s.publication().cards[0].photo_layout).toBe('gallery-v1');await observeImageExport(page);
 await page.locator('.tsExportImage').click();await saveExportPng(await downloadPreparedImage(page),browserName,'gallery');const ink=await page.evaluate(()=>exportInk.join(' '));expect(ink.replace(/\s/g,'')).toContain(s.publication().cards[0].text.replace(/\s/g,''));expect(ink).not.toContain('Третье фото — пример');expect(s.snapshot(11).draft.offers[0].import_source.fields.amount).toBe('123456');expect(s.snapshot(11).draft.offers[0].amount).toBe('130000');clean(s,deliveryActions);
});
test('selection image export reads a historical version after the editable price changed',async({page,browserName})=>{
 const options={draft:completeDraft()},s=await fixture(page,options);await open(page);await page.locator('#tsPreview').click();await expect(page.locator('.tsExportImage')).toBeEnabled();const original=copy(s.publication());await page.locator('#tsEdit').click();await page.locator('#ts-hotel').fill('NEW PRIVATE DRAFT');await page.locator('#ts-amount').fill('999999');await save(page);await page.locator('#tsClose').click();options.readOnly=true;await open(page);await page.locator('#tsHistory').click();await page.getByRole('button',{name:/Версия 1 ·/}).click();await expect(page.locator('.tsExportImage')).toBeEnabled();await observeImageExport(page);const calls=copy(s.calls);
 await page.locator('.tsExportImage').click();await saveExportPng(await downloadPreparedImage(page),browserName,'history');const ink=await page.evaluate(()=>exportInk.join(' '));expect(ink.replace(/\s/g,'')).toContain(original.cards[0].text.replace(/\s/g,''));expect(ink).not.toContain('NEW PRIVATE DRAFT');expect(ink).not.toContain('999999');expect(s.snapshot(11).draft.offers[0].amount).toBe('999999');expect(s.calls).toEqual(calls);expect(s.creations()).toBe(0);clean(s,deliveryActions);
});
for(const failed of [false,true])test('selection image export waits for '+(failed?'broken':'pending')+' photos',async({page})=>{
 const s=await fixture(page,{draft:completeDraft(),holdPhotos:!failed,photoError:failed});await open(page);await page.locator('#tsPreview').click();const button=page.locator('.tsExportImage');await expect(button).toBeDisabled();await page.evaluate(()=>{window.exportEncodeCalls=0;HTMLCanvasElement.prototype.toBlob=function(){exportEncodeCalls++}});await button.evaluate(el=>el.onclick());expect(await page.evaluate(()=>exportEncodeCalls)).toBe(0);
 if(!failed){s.releasePhotos();await expect(button).toBeEnabled()}else await expect(page.locator('#tsStatus')).toContainText('Не удалось загрузить фото');clean(s,deliveryActions);
});
for(const mode of ['edit','client'])test('selection image export cancels its encoder on '+mode+' and ignores the detached card',async({page})=>{
 const s=await fixture(page,{draft:completeDraft()}),downloads=[];page.on('download',download=>downloads.push(download));await open(page);await page.locator('#tsPreview').click();await expect(page.locator('.tsExportImage')).toBeEnabled();
 await page.evaluate(()=>{window.oldExportButton=document.querySelector('.tsExportImage');window.heldExport=null;window.exportEncoderFinished=false;const encode=HTMLCanvasElement.prototype.toBlob;HTMLCanvasElement.prototype.toBlob=function(callback,...args){window.heldExport=()=>encode.call(this,blob=>{callback(blob);window.exportEncoderFinished=true},...args)}});
 await page.locator('.tsExportImage').click();await expect.poll(()=>page.evaluate(()=>typeof heldExport)).toBe('function');
 if(mode==='edit')await page.locator('#tsEdit').click();else{await page.locator('#tsClose').click();await page.locator('#inboxList [data-conversation-id="12"]').click();await open(page)}
 await page.evaluate(()=>{oldExportButton.onclick();heldExport();});await expect.poll(()=>page.evaluate(()=>exportEncoderFinished)).toBe(true);expect(await page.evaluate(()=>document.querySelectorAll('a[download]').length)).toBe(0);expect(downloads).toHaveLength(0);expect(s.creations()).toBe(0);if(mode==='client')expect(s.drafts.has(12)).toBe(false);clean(s,deliveryActions);
});
test('selection ready image link is revoked on client switch and its detached click cannot download',async({page})=>{
 const s=await fixture(page,{draft:completeDraft()}),downloads=[];page.on('download',download=>downloads.push(download));await open(page);await page.locator('#tsPreview').click();await expect(page.locator('.tsExportImage')).toBeEnabled();await observeImageExport(page);await page.locator('.tsExportImage').click();await expect(page.locator('[data-ts-image-download]')).toBeVisible();expect(await page.evaluate(()=>exportBlobUrls.size)).toBe(1);await page.evaluate(()=>{window.oldReadyImage=document.querySelector('[data-ts-image-download]')});await page.locator('#tsClose').click();await page.locator('#inboxList [data-conversation-id="12"]').click();await open(page);await page.evaluate(()=>oldReadyImage.click());expect(await page.evaluate(()=>exportBlobUrls.size)).toBe(0);expect(await page.evaluate(()=>oldReadyImage.hasAttribute('href'))).toBe(false);expect(downloads).toHaveLength(0);expect(s.drafts.has(12)).toBe(false);expect(s.creations()).toBe(0);clean(s,deliveryActions);
});
test('selection image export refuses browser-protected photo bytes without a partial file',async({page})=>{
 const s=await fixture(page,{draft:completeDraft(),imageExportCrossOrigin:true}),downloads=[];page.on('download',download=>downloads.push(download));await open(page);await page.locator('#tsPreview').click();await expect(page.locator('.tsExportImage')).toBeEnabled();const calls=copy(s.calls);await page.locator('.tsExportImage').click();await expect(page.locator('#tsStatus')).toContainText('Не удалось подготовить изображение');expect(downloads).toHaveLength(0);expect(s.calls).toEqual(calls);await expect(page.locator('.tsExportImage')).toBeEnabled();clean(s,deliveryActions);
});
test('selection image export measures every condition and refuses an overlong canvas',async({page})=>{
 const s=await fixture(page,{draft:completeDraft(),imageExportLongText:true}),downloads=[];page.on('download',download=>downloads.push(download));await open(page);await page.locator('#tsPreview').click();await expect(page.locator('.tsExportImage')).toBeEnabled();await page.evaluate(()=>{window.exportEncodeCalls=0;HTMLCanvasElement.prototype.toBlob=function(){exportEncodeCalls++}});const original=copy(s.publication()),calls=copy(s.calls);await page.locator('.tsExportImage').click();await expect(page.locator('#tsStatus')).toContainText('текст не будет обрезан');expect(await page.evaluate(()=>exportEncodeCalls)).toBe(0);expect(downloads).toHaveLength(0);expect(s.publication()).toEqual(original);expect(s.calls).toEqual(calls);await expect(page.locator('#tsPrint')).toBeEnabled();clean(s,deliveryActions);
});

const {privateEditor}=require('./fixtures/selection-private-editor');

function imageDownloadPolicy(page){
 const events=[],safe=text=>text.replace(/blob:[^\s"'<>]+/g,'blob:[local]').replace(/https?:\/\/[^\s"'<>]+/g,value=>{try{const url=new URL(value);return url.origin+url.pathname}catch{return '[url]'}}).slice(0,400);
 page.on('console',message=>{if(['error','warning'].includes(message.type())&&/download|sandbox|content security policy|blob:|not allowed/i.test(message.text())&&events.length<8)events.push({type:message.type(),text:safe(message.text())})});
 return()=>console.log('SYNTHETIC image-download policy '+JSON.stringify({events,frames:page.frames().slice(0,3).map(frame=>safe(frame.url()))}));
}
test('protected editor CSP blocks foreign frames and executable Blob scripts',async({page})=>{
 const s=await privateEditor(page,{draft:completeDraft()});await expect(s.editor.locator('#tsStatus')).toContainText('загружен');const before=copy(s.snapshot()),calls=copy(s.calls),pages=page.context().pages().length;
 const payload='window.imagePolicyProbe.ran=true';const text=await page.evaluate(async payload=>{
  const blob=new Blob([payload],{type:'application/javascript'});window.imagePolicyProbe={foreignBlocked:false,scriptBlocked:false,violation:false,ran:false,url:URL.createObjectURL(blob)};return blob.text();
 },payload);expect(text).toBe(payload);
 try{
  await page.evaluate(()=>{
   document.addEventListener('securitypolicyviolation',event=>{
    if(event.blockedURI.startsWith('https://foreign-editor.invalid')&&event.disposition==='enforce')imagePolicyProbe.foreignBlocked=true;
    // CSP reports may strip non-HTTP URLs down to their scheme (CSP3 5.2/5.4).
    if((event.blockedURI===imagePolicyProbe.url||event.blockedURI==='blob')&&event.effectiveDirective.startsWith('script-src')&&event.disposition==='enforce')imagePolicyProbe.violation=true;
   });
   const foreign=document.createElement('iframe');foreign.id='foreignFramePolicyProbe';foreign.src='https://foreign-editor.invalid/not-allowed';document.body.append(foreign);
   const script=document.createElement('script');script.id='blobScriptPolicyProbe';script.src=imagePolicyProbe.url;script.onerror=()=>{imagePolicyProbe.scriptBlocked=true};document.body.append(script);
  });
  expect(await page.locator('#blobScriptPolicyProbe').getAttribute('src')).toBe(await page.evaluate(()=>imagePolicyProbe.url));
  await expect.poll(()=>page.evaluate(()=>imagePolicyProbe.foreignBlocked)).toBe(true);await expect.poll(()=>page.evaluate(()=>imagePolicyProbe.scriptBlocked)).toBe(true);await expect.poll(()=>page.evaluate(()=>imagePolicyProbe.violation)).toBe(true);expect(await page.evaluate(()=>imagePolicyProbe.ran)).toBe(false);
 }finally{await page.evaluate(()=>{document.getElementById('blobScriptPolicyProbe')?.remove();document.getElementById('foreignFramePolicyProbe')?.remove();URL.revokeObjectURL(imagePolicyProbe.url)})}
 expect(s.snapshot()).toEqual(before);expect(s.calls).toEqual(calls);expect(page.context().pages()).toHaveLength(pages);expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);
});

test('protected tours iframe permits an ordinary native blob download control',async({page})=>{
 const report=imageDownloadPolicy(page),s=await privateEditor(page,{draft:completeDraft()}),f=s.editor;
 const frame=page.frames().find(frame=>frame.url().startsWith('https://app.anytoour.ru/manager/tour-selection-editor.php'));
 await expect(f.locator('#tsStatus')).toContainText('загружен');const before=copy(s.snapshot()),calls=copy(s.calls),pages=page.context().pages().length;
 await frame.evaluate(()=>{const link=document.createElement('a');link.id='nativeImageDownloadControl';link.href=URL.createObjectURL(new Blob(['SYNTHETIC native download control'],{type:'text/plain'}));link.download='synthetic-download-control.txt';link.textContent='Synthetic native download control';link.style.cssText='display:block;min-height:44px;padding:12px';document.getElementById('tsEditor').append(link)});
 try{const controlReceived=page.waitForEvent('download',{timeout:5000});await f.locator('#nativeImageDownloadControl').click();const control=await controlReceived;expect(control.suggestedFilename()).toBe('synthetic-download-control.txt');expect(await control.failure()).toBe(null);await frame.evaluate(()=>{const link=document.getElementById('nativeImageDownloadControl');URL.revokeObjectURL(link.href);link.remove()});expect(s.snapshot()).toEqual(before);expect(s.calls).toEqual(calls);expect(page.context().pages()).toHaveLength(pages);expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);}finally{report()}
});

test('protected tours iframe exports its immutable card using existing loaded private photos',async({page,browserName})=>{
 const report=imageDownloadPolicy(page),s=await privateEditor(page,{draft:completeDraft()}),f=s.editor,prepareCalls=[];let photoReads=0,original=null;
 const frame=page.frames().find(frame=>frame.url().startsWith('https://app.anytoour.ru/manager/tour-selection-editor.php'));
 await page.route('**/manager/tour-selection-editor-api.php',async route=>{
  const d=route.request().postDataJSON();if(d.action!=='prepare')return route.fallback();expect(d.csrf).toBe('private-csrf');expect(d.editor_actor).toBe(7);expect(d.conversation_id).toBe(11);prepareCalls.push(copy(d));
  const built=JSON.parse(execFileSync('php',['-r',`require 'services/ManagerTourSelectionPublicationDraft.php';$b=ManagerTourSelectionPublicationDraft::build(json_decode(stream_get_contents(STDIN),true));foreach($b['offers'] as &$o)$o['card']=TourOfferCardRenderer::render($o['snapshot'],$o['presentation'],'tour_like_1_'.str_repeat('a',24));echo json_encode($b,JSON_THROW_ON_ERROR);`],{cwd:root,input:JSON.stringify(s.snapshot().draft),encoding:'utf8'}));
  original={id:'a'.repeat(32),draft_version:1,title:built.title,channel:'telegram',can_send:true,cards:built.offers.map((offer,index)=>({index,state:'pending',text:offer.card.plain_text,snapshot:offer.snapshot,photos:offer.presentation.photos.map((_,photo)=>'https://app.anytoour.ru/manager/tour-selection-photo.php?publication_id='+'a'.repeat(32)+'&card='+index+'&photo='+photo)}))};return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,publication:original})});
 });
 await page.route('https://app.anytoour.ru/manager/tour-selection-photo.php?**',route=>{photoReads++;return route.fulfill({contentType:'image/jpeg',body:fs.readFileSync(path.join(root,'assets/owner-tour-cards/compact-v1/cover.jpg'))})});
 await expect(f.locator('#tsStatus')).toContainText('загружен');
 await f.locator('#tsPreview').click();await expect(f.locator('.tsExportImage')).toBeEnabled();expect(await page.locator('iframe').evaluate(el=>el.sandbox.contains('allow-downloads'))).toBe(true);const calls=copy(s.calls),before=copy(s.snapshot()),pages=page.context().pages().length;expect(photoReads).toBe(3);await observeImageExport(frame);
 await f.locator('.tsExportImage').click();try{await saveExportPng(await downloadPreparedImage(page,f),browserName,'protected')}finally{report()};const drawn=await frame.evaluate(()=>({text:exportInk.join(' '),photos:exportPhotoSources}));expect(drawn.text.replace(/\s/g,'')).toContain(original.cards[0].text.replace(/\s/g,''));expect(drawn.photos).toEqual(original.cards[0].photos);expect(photoReads).toBe(3);expect(s.snapshot()).toEqual(before);expect(s.calls).toEqual(calls);expect(prepareCalls).toHaveLength(1);expect(page.context().pages()).toHaveLength(pages);expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);
});
test('protected tours iframe compares native print lifecycle with a same-frame control and recovers without events',async({page,browserName},testInfo)=>{
 const s=await privateEditor(page,{draft:completeDraft()}),f=s.editor;const prepareCalls=[];
 const frame=page.frames().find(x=>x.url().startsWith('https://app.anytoour.ru/manager/tour-selection-editor.php'));
 await expect(f.locator('#tsStatus')).toContainText('загружен');
 // Both phases call through the real native function on an ordinary user click.
 // Observe each for the same bounded window: WebKit's CI host may emit no
 // printing events even for this control. That is never a successful print claim.
 await frame.evaluate(()=>{
  window.printDiagnostic={phase:'control',control:{calls:0,before:0,after:0},proposal:{calls:0,before:0,after:0}};
  const nativePrint=window.print.bind(window);window.print=()=>{printDiagnostic[printDiagnostic.phase].calls++;return nativePrint()};
  addEventListener('beforeprint',()=>printDiagnostic[printDiagnostic.phase].before++);addEventListener('afterprint',()=>printDiagnostic[printDiagnostic.phase].after++);
  const control=document.createElement('button');control.id='nativePrintControl';control.textContent='Synthetic native print control';control.onclick=()=>window.print();document.getElementById('tourSelectionDialog').append(control);
 });
 await f.locator('#nativePrintControl').click();await frame.evaluate(()=>new Promise(resolve=>setTimeout(resolve,5000)));
 const control=await frame.evaluate(()=>({...printDiagnostic.control}));expect(control.calls).toBe(1);expect([0,1]).toContain(control.before);if(browserName==='chromium')expect(control.before).toBe(1);
 await frame.evaluate(()=>{document.getElementById('nativePrintControl').remove();printDiagnostic.phase='proposal'});
 await page.route('**/manager/tour-selection-editor-api.php',async route=>{
  const d=route.request().postDataJSON();if(d.action!=='prepare')return route.fallback();expect(d.csrf).toBe('private-csrf');expect(d.editor_actor).toBe(7);expect(d.conversation_id).toBe(11);prepareCalls.push(copy(d));
  const built=JSON.parse(execFileSync('php',['-r',`require 'services/ManagerTourSelectionPublicationDraft.php'; $b=ManagerTourSelectionPublicationDraft::build(json_decode(stream_get_contents(STDIN),true)); foreach($b['offers'] as &$o)$o['card']=TourOfferCardRenderer::render($o['snapshot'],$o['presentation'],'tour_like_1_'.str_repeat('a',24)); echo json_encode($b,JSON_THROW_ON_ERROR);`],{cwd:root,input:JSON.stringify(s.snapshot().draft),encoding:'utf8'}));
  return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,publication:{id:'a'.repeat(32),draft_version:1,title:built.title,channel:'telegram',can_send:true,cards:built.offers.map((o,index)=>({index,state:'pending',text:o.card.plain_text,snapshot:o.snapshot,photos:o.presentation.photos.map(p=>p.url)}))}})});
 });
 await page.route('https://photos.example.org/*.jpg',route=>route.fulfill({contentType:'image/jpeg',body:fs.readFileSync(path.join(root,'assets/owner-tour-cards/compact-v1/cover.jpg'))}));
 await expect(f.locator('#tsStatus')).toContainText('загружен');await f.locator('#tsPreview').click();await expect(f.locator('#tsPrint')).toBeEnabled();expect(prepareCalls).toHaveLength(1);expect(await page.locator('iframe').evaluate(el=>el.sandbox.contains('allow-modals'))).toBe(true);
 const pages=page.context().pages().length;await frame.evaluate(()=>{window.printImages=Array.from(document.querySelectorAll('.tsPhotos img'))});await f.locator('#tsPrint').click();await frame.evaluate(()=>new Promise(resolve=>setTimeout(resolve,5000)));
 const observed=await frame.evaluate(()=>({native:printDiagnostic,temporary:!!document.querySelector('.tsPrintProposal')}));expect(observed.native.proposal.calls).toBe(1);expect(observed.native.proposal.before).toBe(control.before);if(browserName==='chromium')expect(observed.native.proposal.before).toBe(1);
 await testInfo.attach('native-print-control-'+browserName,{contentType:'application/json',body:Buffer.from(JSON.stringify({browser:browserName,observationMs:5000,...observed,actualPrintOrPdfConfirmed:false}))});
 // Independently exercise no-event recovery in both engines. This separate
 // controlled non-returning lifecycle is not used to claim native acceptance.
 if(observed.temporary)await f.locator('#tsPrint').click();await expect(f.locator('.tsPrintProposal')).toHaveCount(0);await expect(f.locator('#tsPrint')).toHaveText('Печать / PDF');
 await frame.evaluate(()=>{window.print=()=>{}});await f.locator('#tsPrint').click();await expect(f.locator('#tsPrint')).toHaveText('Вернуть предпросмотр');await f.locator('#tsPrint').click();await expect(f.locator('.tsPrintProposal')).toHaveCount(0);expect(await frame.evaluate(()=>printImages.every(img=>img.closest('.tsPreviewCard')))).toBe(true);await expect(f.locator('#tsPrint')).toBeEnabled();
 expect(page.context().pages()).toHaveLength(pages);expect(s.calls.every(x=>['me','editor_context','get'].includes(x.action))).toBe(true);expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);
});
for(const viewport of [{width:390,height:900},{width:430,height:900},{width:768,height:900},{width:1440,height:900},{width:390,height:420},{width:430,height:420}])test('private editor uses the same protected core in tours iframe '+viewport.width+'x'+viewport.height,async({page,browserName})=>{
 await page.setViewportSize(viewport);const s=await privateEditor(page,{draft:completeDraft()}),f=s.editor;await f.locator('#tsOfferDetails>summary').click();
 await expect(f.locator('#ts-hotel')).toHaveValue('Синтетический отель у моря');await expect(f.locator('#ts-amount')).toHaveValue('240000');
 expect(await page.evaluate(()=>window.WorkspaceV2)).toBeUndefined();expect(await page.frames()[1].evaluate(()=>window.WorkspaceV2)).toBeUndefined();
 if(viewport.height===420){
  // A visible footer alone is insufficient: a manager must be able to see the
  // entire focused field through every scroll/clip ancestor, including WebKit.
  for(const selector of ['#ts-hotel','#ts-amount']){
   const input=f.locator(selector);await input.scrollIntoViewIfNeeded();
   await expect.poll(()=>input.evaluate(el=>{
    const r=el.getBoundingClientRect();let top=Math.max(0,r.top),bottom=Math.min(innerHeight,r.bottom),left=Math.max(0,r.left),right=Math.min(innerWidth,r.right);
    for(let p=el.parentElement;p;p=p.parentElement){
     const s=getComputedStyle(p),b=p.getBoundingClientRect();
     if(/auto|scroll|hidden|clip/.test(s.overflowY)){top=Math.max(top,b.top+p.clientTop);bottom=Math.min(bottom,b.top+p.clientTop+p.clientHeight);}
     if(/auto|scroll|hidden|clip/.test(s.overflowX)){left=Math.max(left,b.left+p.clientLeft);right=Math.min(right,b.left+p.clientLeft+p.clientWidth);}
    }
    return bottom-top>=r.height-1&&right-left>=r.width-1;
   })).toBe(true);
  }
  await f.locator('#ts-amount').fill('240001');
 }
 await f.locator('#ts-hotel').fill('Сохранённый отдельный редактор');await f.locator('#tsSave').click();await expect(f.locator('#tsSaved')).toHaveText('Сохранено на сервере');
 await expect(f.locator('#tsClose')).toBeInViewport();await expect(f.locator('#tsCollector')).toBeInViewport();await expect(f.locator('#tsSave')).toBeInViewport();
 expect(await f.locator('#tourSelectionDialog').evaluate(el=>el.scrollWidth-el.clientWidth)).toBeLessThanOrEqual(1);
 fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({animations:'disabled',path:path.join(root,'manager2-artifacts',`private-editor-${browserName}-${viewport.width}x${viewport.height}.png`)});
 await page.reload();await expect(f.locator('#ts-hotel')).toHaveValue('Сохранённый отдельный редактор');await expect(f.locator('#ts-amount')).toHaveValue(viewport.height===420?'240001':'240000');expect(s.snapshot().draft.offers[0].amount).toBe(viewport.height===420?'240001':'240000');expect(s.calls.filter(x=>x.action==='save')).toHaveLength(1);expect(s.calls.every(x=>['me','editor_context','get','save'].includes(x.action))).toBe(true);expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);
});
for(const [name,options,message] of [['anonymous',{actor:0},'войдите'],['different account',{actor:8},'другой аккаунт'],['denied conversation',{allowed:false},'недоступна'],['invalid CSRF',{fail:'csrf'},'подтвердить доступ']])test('private editor blocks '+name+' before loading a draft',async({page})=>{
 const s=await privateEditor(page,options);await expect(s.editor.locator('#editorMessage')).toContainText(message);await expect(s.editor.locator('#tourSelectionDialog')).toHaveCount(0);expect(s.calls.filter(x=>x.action==='get'||x.action==='save')).toHaveLength(0);expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);
});
for(const query of ['?conversation_id=11&conversation_id=12&editor_actor=7','?conversation_id=11&editor_actor=7&editor_actor=8','?conversation_id=011&editor_actor=7'])test('private editor refuses ambiguous navigation '+query,async({page})=>{
 const s=await privateEditor(page,{entry:query});await expect(page.locator('iframe')).toHaveCount(0);expect(s.calls).toEqual([]);expect(s.unexpected).toEqual([]);
});
test('private editor read-only access cannot edit or send',async({page})=>{
 const s=await privateEditor(page,{readOnly:true,draft:completeDraft()}),f=s.editor;await expect(f.locator('#tsStatus')).toContainText('Просмотр');await expect(f.locator('#ts-hotel')).toBeDisabled();await expect(f.locator('#tsSave')).toBeDisabled();await expect(f.locator('#tsAdd')).toBeDisabled();await expect(f.locator('#tsCollector')).toBeDisabled();expect(s.calls.filter(x=>x.action==='save'||x.action==='send')).toHaveLength(0);expect(s.errors).toEqual([]);
});
test('private editor lost save survives reload and resolves one canonical receipt',async({page})=>{
 const s=await privateEditor(page,{draft:completeDraft(),fail:'after'}),f=s.editor;await f.locator('#tsOfferDetails>summary').click();await expect(f.locator('#ts-hotel')).toBeVisible();await f.locator('#ts-hotel').fill('Принято сервером до обрыва');await f.locator('#tsSave').click();await expect(f.getByRole('button',{name:'Проверить сохранение'})).toBeVisible();await page.reload();await expect(f.getByRole('button',{name:'Проверить сохранение'})).toBeVisible();await f.getByRole('button',{name:'Проверить сохранение'}).click();await expect(f.locator('#tsSaved')).toHaveText('Сохранено на сервере');await expect(f.locator('#ts-hotel')).toHaveValue('Принято сервером до обрыва');expect(s.calls.filter(x=>x.action==='save')).toHaveLength(1);expect(s.receipts.size).toBe(1);expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);
});
test('private editor keeps old-account unsaved work isolated and recovers only same account',async({page})=>{
 const s=await privateEditor(page,{draft:completeDraft()}),f=s.editor;await f.locator('#tsOfferDetails>summary').click();await expect(f.locator('#ts-hotel')).toBeVisible();await f.locator('#ts-hotel').fill('Личные несохранённые условия');
 s.setActor(8);await f.locator('#tsSave').click();await expect(f.locator('#editorMessage')).toContainText('Сессия изменилась');await expect(f.locator('#tourSelectionDialog')).not.toBeVisible();await expect(f.locator('#tsEditor')).toBeEmpty();
 await f.locator('#editorRetry').click();await expect(f.locator('#editorMessage')).toContainText('другой аккаунт');expect(s.snapshot().draft.offers[0].hotel).toBe('Синтетический отель у моря');
 s.setActor(7);await f.locator('#editorRetry').click();await expect(f.locator('#ts-hotel')).toHaveValue('Личные несохранённые условия');await f.getByRole('button',{name:'Проверить сохранение'}).click();await f.getByRole('button',{name:'Повторить это сохранение'}).click();await expect(f.locator('#tsSaved')).toHaveText('Сохранено на сервере');const writes=s.calls.filter(x=>x.action==='save');expect(writes).toHaveLength(2);expect(writes[1]).toEqual(writes[0]);expect(s.receipts.size).toBe(1);expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);
});
test('private editor discards a stale draft response after hiding the document',async({page})=>{
 const s=await privateEditor(page,{draft:completeDraft(),fail:'hold_get'}),f=s.editor;await expect.poll(s.hasHeld).toBe(true);
 await page.frames()[1].evaluate(()=>{Object.defineProperty(document,'hidden',{configurable:true,value:true});document.dispatchEvent(new Event('visibilitychange'))});s.release();await expect(f.locator('#tourSelectionDialog')).not.toBeVisible();await expect(f.locator('#tsEditor')).toBeEmpty();
 await page.frames()[1].evaluate(()=>Object.defineProperty(document,'hidden',{configurable:true,value:false}));await f.locator('#editorRetry').click();await expect(f.locator('#ts-hotel')).toHaveValue('Синтетический отель у моря');expect(s.calls.filter(x=>x.action==='save')).toHaveLength(0);expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);
});


function hotelKnowledgeFixture(){const r=JSON.parse(fs.readFileSync(path.join(root,'knowledge/hotels/anytour-99-apartments.json'),'utf8'));r.status='available';return r;}
function hotelKnowledgeDraft(){const d=completeDraft();d.offers[0].hotel='JAZ Makadi Saraya Resort';d.offers.push({...copy(d.offers[0]),id:'9'.repeat(32),hotel:'Другой вариант без подтверждённой справки'});return d;}
for(const width of[390,430,768,1440])test('hotel knowledge editor selected saved offer at '+width,async({page,browserName})=>{
 await page.setViewportSize({width,height:1000});const reference=hotelKnowledgeFixture(),draft=hotelKnowledgeDraft(),s=await privateEditor(page,{draft,hotelReference:reference}),f=s.editor;
 await f.locator('#tsHotelKnowledgeRead').click();await expect(f.locator('#tsHotelKnowledge')).toContainText(reference.facts[0].text);
 await expect(f.locator('#tsHotelKnowledge')).toContainText(reference.material_limits.source_conflicts[0].text);
 await expect(f.locator('#tsHotelKnowledge')).toContainText('Справка частичная');
 await f.locator('#tsHotelKnowledge summary').click();await expect(f.locator('#tsHotelKnowledge')).toContainText(reference.binding_revision);
 await expect(f.locator('#tsHotelKnowledge')).not.toContainText('turn100view1');
 expect(s.calls.filter(x=>x.editor_resource==='hotel-knowledge-api.php')).toMatchObject([{action:'read',conversation_id:11,draft_version:1,offer_id:draft.offers[0].id}]);
 expect(await f.locator('#tsHotelKnowledge').evaluate(el=>el.scrollWidth>el.clientWidth)).toBe(false);
 await f.locator('#tsHotelKnowledge').scrollIntoViewIfNeeded();fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({path:path.join(root,'manager2-artifacts',`hotel-knowledge-editor-${browserName}-${width}.png`)});
 await f.locator('[data-ts-select="'+draft.offers[1].id+'"]').click();await expect(f.locator('#tsHotelKnowledge')).toBeEmpty();
 await f.locator('#tsHotelKnowledgeRead').click();await expect(f.locator('#tsHotelKnowledge')).toContainText('недоступна');await expect(f.locator('#tsHotelKnowledge')).not.toContainText(reference.facts[0].text);
 expect(s.calls.some(x=>['send','publish'].includes(x.action))).toBe(false);expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);
});
test('hotel knowledge editor dirty save invalidates delayed facts',async({page})=>{
 const reference=hotelKnowledgeFixture(),s=await privateEditor(page,{draft:hotelKnowledgeDraft(),hotelReference:reference,fail:'hold_hotel'}),f=s.editor;
 await f.locator('#tsHotelKnowledgeRead').click();await expect.poll(()=>s.hasHeld()).toBe(true);
 await f.locator('#tsOfferDetails>summary').click();await f.locator('#ts-hotel').fill('Изменённый отель');await expect(f.locator('#tsHotelKnowledge')).toBeEmpty();await expect(f.locator('#tsHotelKnowledgeRead')).toBeDisabled();
 s.release();await expect.poll(()=>s.snapshot().version).toBe(2);await expect(f.locator('#tsHotelKnowledge')).toBeEmpty();expect(s.calls.filter(x=>x.editor_resource==='hotel-knowledge-api.php')).toHaveLength(1);expect(s.errors).toEqual([]);
});
test('hotel knowledge editor account restart clears delayed private facts',async({page})=>{
 const reference=hotelKnowledgeFixture(),s=await privateEditor(page,{draft:hotelKnowledgeDraft(),hotelReference:reference,fail:'hold_hotel'}),f=s.editor;
 await f.locator('#tsHotelKnowledgeRead').click();await expect.poll(()=>s.hasHeld()).toBe(true);s.setActor(9);s.release();
 await expect(f.locator('#editorSignIn')).toBeVisible();await expect(f.locator('#tsHotelKnowledge')).toHaveCount(0);expect(s.calls.filter(x=>x.action==='save')).toHaveLength(0);expect(s.errors).toEqual([]);
});
test('hotel knowledge editor read-only access and changed-version refusal',async({page})=>{
 const reference=hotelKnowledgeFixture(),s=await privateEditor(page,{draft:hotelKnowledgeDraft(),hotelReference:reference,readOnly:true}),f=s.editor;
 await expect(f.locator('#tsSave')).toBeDisabled();await f.locator('#tsHotelKnowledgeRead').click();await expect(f.locator('#tsHotelKnowledge')).toContainText(reference.facts[0].text);
 s.setFail('hotel_version');await f.locator('#tsHotelKnowledgeRead').click();await expect(f.locator('#tsHotelKnowledge')).toContainText('не подтверждены');await expect(f.locator('#tsHotelKnowledge')).not.toContainText(reference.facts[0].text);expect(s.calls.some(x=>['save','send','publish'].includes(x.action))).toBe(false);expect(s.errors).toEqual([]);
});

const oneTouchBrowserSha=x=>require('node:crypto').createHash('sha256').update(x).digest('hex');
// The new appearance uses the shipped protected core and real PHP publication
// builder. Only HTTP/session data and image files are synthetic.
async function appearancePrivatePreview(page,s){
 let frozen=null;
 await page.route('**/manager/tour-selection-editor-api.php',async route=>{
  const d=route.request().postDataJSON();
  if(!['prepare','publication','history'].includes(d.action))return route.fallback();
  expect(d.csrf).toBe('private-csrf');expect(d.editor_actor).toBe(7);expect(d.conversation_id).toBe(11);
  if(d.action==='history')return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,publications:[{publication_id:frozen.id,draft_version:frozen.draft_version,created_at:'2026-11-04 00:00:00'}]})});
  if(d.action==='prepare'){
   const built=JSON.parse(execFileSync('php',['-r',`require 'services/ManagerTourSelectionPublicationDraft.php';$b=ManagerTourSelectionPublicationDraft::build(json_decode(stream_get_contents(STDIN),true));foreach($b['offers'] as &$o)$o['card']=TourOfferCardRenderer::render($o['snapshot'],$o['presentation'],'tour_like_1_'.str_repeat('a',24));echo json_encode($b,JSON_THROW_ON_ERROR);`],{cwd:root,input:JSON.stringify(s.snapshot().draft),encoding:'utf8'}));expect(built.ok).toBe(true);
   frozen={id:'f'.repeat(32),draft_version:d.version,title:built.title,intro:built.intro,...(built.appearance?{appearance:copy(built.appearance)}:{}),channel:'telegram',can_send:true,cards:built.offers.map((o,index)=>({index,state:'pending',text:o.card.plain_text,snapshot:o.snapshot,photos:o.presentation.photos.map((_,i)=>'https://app.anytoour.ru/manager/tour-selection-photo.php?publication_id='+'f'.repeat(32)+'&card='+index+'&photo='+i)}))};
  }
  return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,publication:copy(frozen)})});
 });
 await page.route('https://app.anytoour.ru/manager/tour-selection-photo.php?**',route=>route.fulfill({contentType:'image/jpeg',body:fs.readFileSync(path.join(root,'assets/owner-tour-cards/compact-v1/cover.jpg'))}));
 return()=>copy(frozen);
}
for(const template of ['photo','compact'])for(const width of [390,1440])test('selection appearance frozen protected preview '+template+' '+width,async({page,browserName})=>{
 await page.setViewportSize({width,height:900});const original=completeDraft();const second=copy(original.offers[0]);second.id='b'.repeat(32);second.hotel='Second synthetic hotel';original.offers.push(second);
 const s=await privateEditor(page,{draft:original}),f=s.editor,frozen=await appearancePrivatePreview(page,s),agency='Synthetic "Agency" <img src=x>',intro='Synthetic introduction <svg> without markup';
 await expect(f.locator('#tsStatus')).toContainText('загружен');expect(await f.locator('#tsAppearance').evaluate(el=>el.getBoundingClientRect().height)).toBeGreaterThanOrEqual(44);expect(await f.locator('#tourSelectionDialog').evaluate(el=>el.scrollWidth-el.clientWidth)).toBeLessThanOrEqual(1);await f.getByRole('button',{name:'Оформление',exact:true}).click();
 await f.locator('#tsAppearanceTemplate').selectOption(template);await f.locator('#tsAppearancePalette').selectOption('ocean');await f.locator('#tsAppearanceAgency').fill(agency);await f.locator('#tsSelectionTitle').fill('Synthetic selection title');await f.locator('#tsSelectionIntro').fill(intro);
 await expect.poll(()=>s.snapshot().draft.appearance?.agency_name).toBe(agency);await expect.poll(()=>s.snapshot().draft.intro).toBe(intro);await expect(f.locator('#tsSaved')).toHaveText('Сохранено на сервере');expect(s.snapshot().draft.offers).toEqual(original.offers);
 await expect(f.locator('.tsAppearance img,.tsAppearance svg,.tsAppearance script')).toHaveCount(0);expect(await f.locator('#tsAppearanceAgency').getAttribute('onfocus')).toBe(null);
 for(const id of ['tsAppearanceTemplate','tsAppearancePalette','tsAppearanceAgency','tsSelectionTitle','tsAppearanceBack'])expect(await f.locator('#'+id).evaluate(el=>el.getBoundingClientRect().height)).toBeGreaterThanOrEqual(44);
 expect(await f.locator('#tourSelectionDialog').evaluate(el=>el.scrollWidth-el.clientWidth)).toBeLessThanOrEqual(1);
 fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});
 if(template==='photo')await page.screenshot({path:path.join(root,'manager2-artifacts',`selection-appearance-settings-${browserName}-${width}.png`),animations:'disabled'});
 await f.locator('#tsAppearanceBack').click();await f.locator('#tsPreview').click();await expect(f.locator('.tsSelectionBrand')).toHaveText(agency);await expect(f.locator('.tsSelectionBrand')).toHaveCSS('color','rgb(23, 102, 161)');await expect(f.locator('.tsSelectionIntro')).toHaveText(intro);await expect(f.locator('#tsEditor')).toHaveClass(new RegExp('tsTemplate-'+template));
 await expect(f.locator('.tsExportImage').first()).toBeEnabled();await page.screenshot({path:path.join(root,'manager2-artifacts',`selection-appearance-preview-${template}-${browserName}-${width}.png`),animations:'disabled'});
 const publication=frozen();expect(publication.appearance).toEqual({template,palette:'ocean',agency_name:agency});expect(publication.cards).toHaveLength(2);
 if(width===1440){
  await f.locator('#tsEdit').click();await f.locator('#tsAppearance').click();await f.locator('#tsAppearanceAgency').fill('LATER WORKING DRAFT');await f.locator('#tsAppearancePalette').selectOption('sunset');await expect.poll(()=>s.snapshot().draft.appearance?.agency_name).toBe('LATER WORKING DRAFT');await expect(f.locator('#tsSaved')).toHaveText('Сохранено на сервере');await f.locator('#tsAppearanceBack').click();
  await f.locator('#tsHistory').click();await f.getByRole('button',{name:new RegExp('Версия '+publication.draft_version+' ·')}).click();await expect(f.locator('.tsSelectionBrand')).toHaveText(agency);await expect(f.locator('.tsSelectionBrand')).toHaveCSS('color','rgb(23, 102, 161)');
  const frame=page.frames().find(x=>x.url().startsWith('https://app.anytoour.ru/manager/tour-selection-editor.php'));await observeImageExport(frame);
  await frame.evaluate(()=>{window.appearanceInk=[];const original=CanvasRenderingContext2D.prototype.fillText;CanvasRenderingContext2D.prototype.fillText=function(text,...args){appearanceInk.push({text,color:this.fillStyle});return original.call(this,text,...args)}});
  await f.locator('.tsExportImage').first().click();await expect(f.locator('[data-ts-image-download]')).toBeVisible();const ink=await frame.evaluate(()=>appearanceInk);expect(ink.find(x=>x.text===agency)?.color).toBe('#1766a1');expect(ink.map(x=>x.text).join(' ')).toContain(intro);expect(ink.map(x=>x.text).join(' ')).not.toContain('LATER WORKING DRAFT');
  // Inspect only the new encoder's frozen design. No native download is needed.
  await f.getByRole('button',{name:'Отменить',exact:true}).click();await frame.evaluate(()=>{window.print=()=>{}});await f.locator('#tsPrint').click();await expect(f.locator('.tsPrintProposal .tsPrintBrand')).toHaveText(agency);await expect(f.locator('.tsPrintProposal .tsPrintIntro')).toHaveText(intro);await expect(f.locator('.tsPrintProposal')).toHaveClass(/tsPalette-ocean/);await f.locator('#tsPrint').click();expect(frozen()).toEqual(publication);
 }
 expect(s.calls.some(c=>['send','publish','biblio_add','onetouch_add','collector_import'].includes(c.action))).toBe(false);expect(s.unexpected).toEqual([]);expect(s.errors).toEqual([]);
});
test('selection appearance respects read-only actor and legacy shape',async({page})=>{
 const original=completeDraft(),s=await privateEditor(page,{draft:original,readOnly:true}),f=s.editor;await expect(f.locator('#tsStatus')).toContainText('Просмотр');await expect(f.locator('#tsAppearance')).toBeDisabled();expect(s.snapshot().draft).toEqual(original);expect(s.calls.some(c=>c.action==='save')).toBe(false);
});
test('appearance preview issue opens and focuses the actual incomplete offer field',async({page})=>{
 const original=completeDraft();original.offers[0].room='';original.appearance={template:'compact',palette:'ocean',agency_name:'Synthetic agency'};
 const s=await privateEditor(page,{draft:original}),f=s.editor;
 await page.route('**/manager/tour-selection-editor-api.php',async route=>{
  const d=route.request().postDataJSON();if(d.action!=='prepare')return route.fallback();expect(d.csrf).toBe('private-csrf');expect(d.editor_actor).toBe(7);expect(d.conversation_id).toBe(11);
  const result=JSON.parse(execFileSync('php',['-r',`require 'services/ManagerTourSelectionPublicationDraft.php';echo json_encode(ManagerTourSelectionPublicationDraft::build(json_decode(stream_get_contents(STDIN),true)),JSON_THROW_ON_ERROR);`],{cwd:root,input:JSON.stringify(s.snapshot().draft),encoding:'utf8'}));expect(result.error).toBe('incomplete_offers');
  return route.fulfill({status:422,contentType:'application/json',body:JSON.stringify(result)});
 });
 await expect(f.locator('#tsStatus')).toContainText('загружен');await f.locator('#tsAppearance').click();await f.locator('#tsPreview').click();await f.getByRole('button',{name:'Вариант 1: номер',exact:true}).click();
 await expect(f.locator('#ts-room')).toBeVisible();await expect(f.locator('#ts-room')).toBeFocused();await expect(f.locator('#ts-room')).toHaveValue('');await expect(f.locator('.tsAppearance')).toHaveCount(0);expect(s.snapshot().draft).toEqual(original);expect(s.calls.some(c=>['save','send','publish','collector_import'].includes(c.action))).toBe(false);expect(s.unexpected).toEqual([]);expect(s.errors).toEqual([]);
});
test('appearance editing rejects a late historical response and detached history button',async({page})=>{
 const original=completeDraft();original.appearance={template:'photo',palette:'ocean',agency_name:'Synthetic agency'};const s=await privateEditor(page,{draft:original}),f=s.editor,frozen=await appearancePrivatePreview(page,s);
 await expect(f.locator('#tsStatus')).toContainText('загружен');await f.locator('#tsPreview').click();await expect(f.locator('.tsPreviewCard')).toHaveCount(1);await f.locator('#tsEdit').click();await f.locator('#tsHistory').click();
 let release,done,publicationReads=0;const finished=new Promise(resolve=>done=resolve);
 await page.route('**/manager/tour-selection-editor-api.php',async route=>{if(route.request().postDataJSON().action!=='publication')return route.fallback();publicationReads++;const old=frozen();await new Promise(resolve=>release=resolve);await route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,publication:old})});done()});
 const button=f.getByRole('button',{name:new RegExp('Версия '+frozen().draft_version+' ·')});await button.evaluate(el=>{window.detachedAppearanceHistoryButton=el});await button.click();await expect.poll(()=>!!release).toBe(true);
 await f.locator('#tsAppearance').click();await f.locator('#tsAppearanceAgency').fill('EDIT AFTER HISTORY');const frame=page.frames().find(x=>x.url().startsWith('https://app.anytoour.ru/manager/tour-selection-editor.php'));await frame.evaluate(()=>detachedAppearanceHistoryButton.click());release();await finished;
 await expect.poll(()=>s.snapshot().draft.appearance?.agency_name).toBe('EDIT AFTER HISTORY');await frame.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));await expect(f.locator('#tsAppearanceAgency')).toBeVisible();await expect(f.locator('#tsAppearanceAgency')).toHaveValue('EDIT AFTER HISTORY');await expect(f.locator('.tsPreviewCard')).toHaveCount(0);await expect(f.locator('#tsSend')).toBeHidden();await expect(f.locator('#tsShare')).toBeHidden();expect(publicationReads).toBe(1);expect(s.snapshot().draft.offers).toEqual(original.offers);expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);
});
for(const template of ['photo','compact'])for(const width of [390,1440])test('public selection appearance geometry '+template+' '+width,async({page,browserName})=>{
 await page.setViewportSize({width,height:900});const requests=[],agency='A'.repeat(80),palette=template==='photo'?'forest':'sunset';
 const offer={index:0,hotel:'Synthetic public hotel',destination:'Synthetic destination',departure:'Москва',date_from:'2077-01-07',nights:7,adults:2,children:0,room:'Synthetic room',meal:'BB',amount:'123456',currency:'RUB',price_qualifier:'from',rationale:'Synthetic explanation',compromise:'Synthetic compromise',flights:'Flights require verification',inclusions:'Package requires verification',constraints:'Price and availability require verification',observed_at:'2077-01-01T00:00:00Z',photos:[0,1,2].map(i=>'photo.php?card=0&photo='+i),photo_layout:'gallery-v1',bot_links:[]};
 const second={...copy(offer),index:1,hotel:'Second synthetic public hotel',photos:[0,1,2].map(i=>'photo.php?card=1&photo='+i)};
 const selection={title:'Synthetic appearance selection',intro:'Synthetic introduction',version:1,updated_at:'2077-01-01 00:00:00',appearance:{template,palette,agency_name:agency},offers:[offer,second]};
 const html=execFileSync('php',['-r',`$selection=json_decode(stream_get_contents(STDIN),true);$h=static fn(string $s):string=>htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');require 'selection/view.php';`],{cwd:root,input:JSON.stringify(selection),encoding:'utf8'});
 const csp=fs.readFileSync(path.join(root,'selection/index.php'),'utf8').match(/header\("Content-Security-Policy: ([^"]+)"\)/)[1];
 await page.route('**/*',async route=>{const url=new URL(route.request().url());requests.push(url.pathname);if(url.origin!=='http://127.0.0.1:4173')throw Error('Unexpected external public appearance request');if(url.pathname==='/appearance-public/')return route.fulfill({contentType:'text/html',headers:{'Content-Security-Policy':csp},body:html});if(url.pathname==='/appearance-public/selection.css')return route.fulfill({contentType:'text/css',body:fs.readFileSync(path.join(root,'selection/selection.css'))});if(url.pathname==='/appearance-public/photo.php')return route.fulfill({contentType:'image/jpeg',body:fs.readFileSync(path.join(root,'assets/owner-tour-cards/compact-v1/cover.jpg'))});throw Error('Unexpected public appearance resource')});
 await page.goto('http://127.0.0.1:4173/appearance-public/');await expect(page.locator('.brand')).toHaveText(agency);await expect(page.locator('.brand')).toHaveCSS('color',palette==='forest'?'rgb(8, 118, 84)':'rgb(161, 68, 39)');await expect(page.locator('.offer')).toHaveCount(2);await expect(page.locator('.photos img')).toHaveCount(6);for(const img of await page.locator('.photos img').all()){await img.scrollIntoViewIfNeeded();await expect.poll(()=>img.evaluate(i=>i.complete&&i.naturalWidth>0)).toBe(true)}
 expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);expect(await page.locator('main.selection').evaluate(el=>el.scrollWidth-el.clientWidth)).toBeLessThanOrEqual(1);await expect(page.locator('.price').first()).toContainText('от');await expect(page.locator('.offer').first()).toContainText('Price and availability require verification');
 for(const img of await page.locator('.photos img').all())await expect(img).toBeVisible();await page.locator('.brand').scrollIntoViewIfNeeded();fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({path:path.join(root,'manager2-artifacts',`selection-appearance-public-${template}-${browserName}-${width}.png`),fullPage:true,animations:'disabled'});expect(requests.filter(p=>p.endsWith('/photo.php'))).toHaveLength(6);
});
function oneTouchBrowserBaseOffer(){return{id:'b'.repeat(32),selected:true,hotel:'Синтетический сохранённый Библио-Глобус',stars:3,destination:'Турция',departure:'Москва',date_from:'2077-01-07',nights:7,adults:2,child_ages:[],room:'Standard',meal:'BB',amount:'180000',currency:'RUB',rationale:'',compromise:'',flights:'',inclusions:'',constraints:'',source_label:'Библио-Глобус · synthetic',source_url:'',observed_at:null,photos:[]}}
async function oneTouchBrowserFileFixture(page,options={}){
 const photoEvents=[],safePhotoUrl=value=>{try{const u=new URL(value);return u.protocol==='blob:'?'blob:[local]':u.origin+u.pathname}catch{return '[invalid]'}},safePhotoText=value=>String(value??'').replace(/blob:[^\s"'<>]+/g,'blob:[local]').replace(/https?:\/\/[^\s"'<>]+/g,safePhotoUrl).slice(0,500);
 page.on('requestfailed',req=>{if(photoEvents.length<12)photoEvents.push({type:'requestfailed',url:safePhotoText(req.url()),error:safePhotoText(req.failure()?.errorText)})});
 page.on('console',msg=>{if(photoEvents.length<12&&['error','warning'].includes(msg.type()))photoEvents.push({type:msg.type(),text:safePhotoText(msg.text())})});
 const image=await page.evaluate(()=>{const c=document.createElement('canvas');c.width=160;c.height=120;c.getContext('2d').fillRect(0,0,160,120);return c.toDataURL('image/jpeg').split(',')[1]});
 const bytes=Buffer.from(image,'base64'),photo='https://image.onetouch.travel/synthetic/cover.jpg',gallery='https://onetouch.travel/hotels/synthetic-hotel-1001/',reference='SYNTHETIC_BROWSER_ONLY';
 const fields={...oneTouchBrowserBaseOffer(),hotel:'Синтетический OneTouch',amount:'123456.78',price_qualifier:'from'},raw={capture_mode:'manager-file-v1',capture_reference:reference,hotel_url:gallery,capture_gallery_sha256:oneTouchBrowserSha('synthetic-gallery'),capture_photo_sha256:JSON.stringify({[photo]:oneTouchBrowserSha(bytes)}),capture_price_declared_at:'2077-01-01T00:00:00Z',capture_gallery_observed_at:'2020-01-01T00:00:00Z',transport:'Перелёт требует проверки',tour:'Synthetic tour',tariff:'Synthetic tariff',availability:'Номер по запросу',room:fields.room,meal:fields.meal};
 const row={ticket:'synthetic-ticket',offer:copy(fields),raw};
 const source={schema:'onetouch-manager-capture-v1',reference,captured_at:raw.capture_price_declared_at,url:"https://b2b.onetouch.travel/search_tour?samo_action=PRICES&TOWNFROMINC=2&STATEINC=9&TOURTYPE=0&TOURINC=0&PROGRAMGROUPINC=0&PROGRAMINC=0&CHECKIN_BEG=20770107&NIGHTS_FROM=7&CHECKIN_END=20770107&NIGHTS_TILL=7&ADULT=2&CURRENCY=1&CHILD=0&TOWNS_ANY=1&townssearch=0&TOWNS=&STARS_ANY=1&STARS=&HOTELS_ANY=1&hotelsearch=0&HOTELS=&MEALS_ANY=1&MEALS=&ROOMS_ANY=1&ROOMS=&CHILD_IN_BED=0&FREIGHT=1&COMFORTABLE_SEATS=0&FILTER=1&MOMENT_CONFIRM=0&WITHOUT_PROMO=0&HOTELTYPES=&PARTITION_PRICE=&PRICEPAGE=1&DYN_SEPARATE=1&rev=965964269",public_controls:{},prices_gzip_base64:'SYNTHETIC_NO_HTTP',prices_sha256:oneTouchBrowserSha('synthetic-prices')};
 const bundle={schema:'onetouch-bundle-v1',source,gallery_cache:[{hotel:'1001',url:gallery,observed_at:raw.capture_gallery_observed_at,html_gzip_base64:'SYNTHETIC_NO_HTTP',html_sha256:raw.capture_gallery_sha256,images:[{url:photo,sha256:oneTouchBrowserSha(bytes),mime:'image/jpeg',base64:options.badImage?Buffer.from('<svg/>').toString('base64'):image}]}]};
 if(options.badImage){bundle.gallery_cache[0].images[0].sha256=oneTouchBrowserSha(Buffer.from('<svg/>'));raw.capture_photo_sha256=JSON.stringify({[photo]:bundle.gallery_cache[0].images[0].sha256})}
 let imported=false;
 const s=await privateEditor(page,{draft:{title:'Synthetic retained selection',intro:'',offers:[oneTouchBrowserBaseOffer()]},sourceAction:async(d,store)=>{
  if(d.action==='onetouch_search')return{ok:true,version:store.snapshot().version,offers:[copy(row)]};
  if(d.action==='onetouch_review')return{ok:true,version:store.snapshot().version,...copy(row),photos:[photo]};
  if(d.action==='onetouch_add'){
   expect(imported).toBe(false);imported=true;const draft=store.snapshot().draft;
   draft.offers.push({...copy(fields),id:'d'.repeat(32),amount:d.manager_amount,photos:[],import_source:{adapter:'onetouch-public-v1',observed_at:source.captured_at,url:source.url,fields:copy(fields),raw:copy(raw),photos:[photo]}});store.replaceDraft(draft);
   return options.lostAdd?null:{ok:true,state:'added',saved_version:store.snapshot().version,count:2,offer_id:'d'.repeat(32)};
  }
  if(d.action==='onetouch_operation')return{ok:true,found:imported,saved_version:store.snapshot().version};
  if(d.editor_resource==='tour-selection-photo.php'){
   expect(d.action).toBe('upload');expect(d.source_photo_url).toBe(photo);expect(d.image_sha256).toBe(oneTouchBrowserSha(bytes));
   return{ok:true,operation_id:d.operation_id,photo:{kind:d.kind,url:'https://app.anytoour.ru/manager/tour-selection-photo.php?conversation_id=11&draft_photo='+'f'.repeat(32)}};
  }
  throw Error('Unexpected synthetic source action '+d.action);
 }});
 // WebKit routes native Blob image loads through interception; keep these local bytes in the browser.
 await page.route('blob:https://app.anytoour.ru/*',route=>route.continue());
 await page.route('https://app.anytoour.ru/manager/tour-selection-photo.php?**',route=>route.fulfill({contentType:'image/jpeg',body:bytes}));
 const f=s.editor;await expect(f.locator('#tsOneTouch')).toBeVisible();await f.locator('#tsOneTouch').click();
 await f.locator('#tsOneTouchFile').setInputFiles({name:'synthetic-onetouch.json',mimeType:'application/json',buffer:Buffer.from(JSON.stringify(bundle))});
 await f.locator('#tsOneTouchRead').click();await f.getByRole('button',{name:'Проверить фото и условия',exact:true}).click();
 return{...s,f,fields,base:oneTouchBrowserBaseOffer(),photoEvents,safePhotoText};
}
async function oneTouchBrowserWaitForPhotos(s){
 try{await expect(s.f.locator('#tsOneTouchPhotoStatus')).toHaveText('Фотографии загружены.')}catch(error){
  try{
   const images=await s.f.locator('#tsOneTouchResults img').evaluateAll(nodes=>nodes.slice(0,3).map(img=>({complete:img.complete,width:img.naturalWidth,height:img.naturalHeight,connected:img.isConnected,sourceScheme:(img.getAttribute('src')||'').split(':')[0].slice(0,40),documentOrigin:img.ownerDocument.location.origin.slice(0,500)})));
   await test.info().attach('onetouch-photo-readiness-failure',{contentType:'application/json',body:Buffer.from(JSON.stringify({images,events:s.photoEvents.slice(-12),errors:s.errors.slice(-12).map(s.safePhotoText),unexpected:s.unexpected.slice(-12).map(s.safePhotoText),calls:s.calls.slice(-12).map(c=>({action:String(c.action??'').slice(0,80),resource:String(c.editor_resource??'').slice(0,80)}))}))});
  }catch(diagnosticError){console.warn('SYNTHETIC OneTouch diagnostics unavailable: '+String(diagnosticError?.name??'Error').slice(0,80))}throw error;
 }
}
for(const width of[390,1440])test('OneTouch local file to existing private uploader retains source price and old offer '+width,async({page,browserName})=>{
 await page.setViewportSize({width,height:844});const s=await oneTouchBrowserFileFixture(page),f=s.f;
 await oneTouchBrowserWaitForPhotos(s);
 const img=f.locator('#tsOneTouchResults img');expect(await img.getAttribute('src')).toMatch(/^blob:/);
 fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await f.locator('#tsOneTouchResults').screenshot({path:path.join(root,'manager2-artifacts',`onetouch-review-${browserName}-${width}.png`)});
 await expect(f.locator('#tsOneTouchResults')).toContainText('Номер по запросу');await f.locator('#tsOneTouchAmount').fill('130000');await f.locator('#tsOneTouchConfirm').check();
 await expect(f.locator('#tsOneTouchAdd')).toBeEnabled();await f.locator('#tsOneTouchAdd').click();
 await expect.poll(()=>s.snapshot().draft.offers[1]?.photos.length).toBe(1);
 expect(s.snapshot().draft.offers[0]).toEqual(s.base);expect(s.snapshot().draft.offers[1]).toMatchObject({amount:'130000',price_qualifier:'from',import_source:{fields:{amount:'123456.78',price_qualifier:'from'}}});
 expect(s.calls.filter(c=>c.action==='onetouch_add')).toHaveLength(1);expect(s.calls.filter(c=>c.editor_resource==='tour-selection-photo.php')).toHaveLength(1);
 await expect(f.locator('.tsAutomaticPhotoStatus')).toContainText('Фотографии сохранены');await f.locator('[data-ts-photo-mode="manual"]').click();await expect(f.locator('.tsPhotoSlot h4')).toHaveText(['Фото 1','Фото 2','Фото 3']);
 expect(s.calls.some(c=>['send','publish','biblio_add','collector_import'].includes(c.action))).toBe(false);expect(s.unexpected).toEqual([]);expect(s.errors).toEqual([]);
});
test('OneTouch rejects non-JPEG file bytes before quote dispatch',async({page})=>{
 const s=await oneTouchBrowserFileFixture(page,{badImage:true});await expect(s.f.locator('#tsOneTouchStatus')).toContainText('Недопустимый размер фотографии');
 expect(s.calls.some(c=>c.action==='onetouch_add')).toBe(false);expect(s.snapshot().draft.offers).toEqual([s.base]);expect(s.unexpected).toEqual([]);
});
test('OneTouch lost add response permits only receipt read then missing private photo completion',async({page})=>{
 const s=await oneTouchBrowserFileFixture(page,{lostAdd:true}),f=s.f;await oneTouchBrowserWaitForPhotos(s);
 await f.locator('#tsOneTouchConfirm').check();await f.locator('#tsOneTouchAdd').click();await expect(f.locator('#tsRecovery')).toContainText('Проверить');
 await f.locator('#tsRecovery button').filter({hasText:'Проверить'}).click();await expect.poll(()=>s.snapshot().draft.offers[1]?.photos.length).toBe(1);
 expect(s.calls.filter(c=>c.action==='onetouch_add')).toHaveLength(1);expect(s.calls.filter(c=>c.action==='onetouch_operation')).toHaveLength(1);expect(s.snapshot().draft.offers).toHaveLength(2);expect(s.snapshot().draft.offers[0]).toEqual(s.base);expect(s.unexpected).toEqual([]);expect(s.errors).toEqual([]);
});

for(const width of [390,430,768,1440])test('offer conditions comparison is readonly with exact different trips '+width,async({page,browserName})=>{
 await page.setViewportSize({width,height:900});const original=completeDraft();
 original.offers.push({...copy(original.offers[0]),id:'b'.repeat(32),hotel:'Синтетический <svg>',date_from:'2027-06-12',nights:10,adults:1,child_ages:[11],room:'Номер <img>',meal:'Завтрак',amount:'1000',currency:'EUR',price_qualifier:'from',constraints:'Доплата за трансфер\nБагаж уточняется',inclusions:''});
 const s=await privateEditor(page,{draft:original}),f=s.editor;await expect(f.locator('#tsStatus')).toContainText('загружен');const before=copy(s.snapshot()),calls=copy(s.calls);
 await f.locator('#tsCompareOffers').click();const panel=f.locator('#tsOfferCompare'),scroll=f.locator('#tsCompareScroll');await expect(panel).toBeVisible();await expect(panel.locator('thead th')).toHaveCount(3);
 await expect(panel.getByRole('row',{name:/Цена за весь состав/})).toContainText(/240\s000/);await expect(panel.getByRole('row',{name:/Цена за весь состав/})).toContainText(/от\s1\s000/);
 await expect(panel.getByRole('row',{name:/Дата вылета/})).toContainText('2027-06-05');await expect(panel.getByRole('row',{name:/Дата вылета/})).toContainText('2027-06-12');
 await expect(panel.getByRole('row',{name:/Состав/})).toContainText('дети: 4, 4');await expect(panel.getByRole('row',{name:/Состав/})).toContainText('дети: 11');
 await expect(panel.getByRole('row',{name:/Номер/})).toContainText('Номер <img>');await expect(panel.getByRole('row',{name:/Питание/})).toContainText('Завтрак');await expect(panel.getByRole('row',{name:/Важные условия/})).toContainText('Багаж уточняется');
 await expect(panel.locator('caption')).toContainText('Различаются валюта, даты, длительность или состав');await expect(panel.locator('img,svg,script')).toHaveCount(0);
 expect(await f.locator('html').evaluate(el=>el.scrollWidth-el.clientWidth)).toBeLessThanOrEqual(1);expect(await panel.evaluate(el=>el.scrollWidth-el.clientWidth)).toBeLessThanOrEqual(1);expect(await f.locator('#tsCompareBack').evaluate(el=>el.getBoundingClientRect().height)).toBeGreaterThanOrEqual(44);
 if(width<600){await scroll.focus();await scroll.press('ArrowRight');await expect.poll(()=>scroll.evaluate(el=>el.scrollLeft)).toBeGreaterThan(0);await scroll.evaluate(el=>{el.scrollLeft=0;el.scrollTop=0})}
 fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({path:path.join(root,'manager2-artifacts',`tour-selection-offer-comparison-${browserName}-${width}.png`),animations:'disabled'});
 if([390,1440].includes(width)){await panel.getByRole('row',{name:/Цена за весь состав/}).scrollIntoViewIfNeeded();await page.screenshot({path:path.join(root,'manager2-artifacts',`tour-selection-offer-comparison-price-${browserName}-${width}.png`),animations:'disabled'});}
 await page.setViewportSize({width,height:420});await expect(f.locator('#tsCompareBack')).toBeInViewport();await expect(f.locator('#tsClose')).toBeInViewport();expect(await scroll.evaluate(el=>el.clientHeight)).toBeGreaterThan(50);
 await scroll.focus();await scroll.press('Escape');await expect(panel).toBeHidden();await expect(f.locator('#tourSelectionDialog')).toBeVisible();await expect(f.locator('#tsCompareOffers')).toBeFocused();expect(s.snapshot()).toEqual(before);expect(s.calls).toEqual(calls);expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);
});

test('offer comparison caps four choices and does not include an excluded offer',async({page})=>{
 const original=completeDraft();for(let i=1;i<5;i++)original.offers.push({...copy(original.offers[0]),id:String.fromCharCode(97+i).repeat(32),hotel:'Синтетический вариант '+(i+1),selected:i!==4});
 const s=await privateEditor(page,{draft:original}),f=s.editor;await expect(f.locator('#tsStatus')).toContainText('загружен');const before=copy(s.snapshot()),calls=copy(s.calls);await f.locator('#tsCompareOffers').click();await f.locator('#tsCompareChooser > summary').click();
 const choices=f.locator('#tsCompareChoices input');await choices.nth(2).check();await choices.nth(3).check();await expect(choices.nth(4)).toBeDisabled();await expect(f.locator('#tsCompareChoices input:checked')).toHaveCount(4);await expect(f.locator('#tsCompareScroll thead th')).toHaveCount(5);
 await choices.nth(1).uncheck();await choices.nth(4).check();await expect(f.locator('#tsCompareScroll thead')).toContainText('Не включён в подборку');await expect(choices.nth(1)).toBeDisabled();
 await f.locator('#tsCompareBack').click();expect(s.snapshot()).toEqual(before);expect(s.calls).toEqual(calls);expect(s.snapshot().draft.offers[4].selected).toBe(false);expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);
});

test('offer comparison keeps original source prices separate from manual prices',async({page})=>{
 const original=mixedPhotoDraft();for(const offer of original.offers)offer.photos=[];original.offers[0].amount='190000';
 const s=await privateEditor(page,{draft:original}),f=s.editor;await expect(f.locator('#tsStatus')).toContainText('загружен');const before=copy(s.snapshot()),calls=copy(s.calls);await f.locator('#tsCompareOffers').click();
 await expect(f.locator('#tsCompareScroll').getByRole('row',{name:/Цена за весь состав/})).toContainText(/190\s000/);await expect(f.locator('#tsCompareScroll').getByRole('row',{name:/Исходная цена/})).toContainText(/200\s000/);await expect(f.locator('#tsCompareScroll').getByRole('row',{name:/Цена получена/})).toContainText(original.offers[0].import_source.observed_at);
 await f.locator('#tsCompareChooser > summary').click();await f.locator('#tsCompareChoices input').nth(1).uncheck();await f.locator('#tsCompareChoices input').nth(2).check();await expect(f.locator('#tsCompareScroll').getByRole('row',{name:/Исходная цена/})).toContainText('за номер');
 await f.locator('#tsCompareBack').click();expect(s.snapshot()).toEqual(before);expect(s.calls).toEqual(calls);expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);
});

test('offer comparison preserves invalid unsaved ages and price without making a quote',async({page})=>{
 const original=completeDraft();original.offers.push({...copy(original.offers[0]),id:'b'.repeat(32),hotel:'Другой синтетический вариант'});const s=await privateEditor(page,{draft:original}),f=s.editor;await expect(f.locator('#tsStatus')).toContainText('загружен');
 await f.locator('#tsOfferDetails > summary').click();await f.locator('#ts-child_ages').fill('7,x');await f.locator('#ts-amount').fill('1e5');const before=copy(s.snapshot()),calls=copy(s.calls);await f.locator('#tsCompareOffers').click();
 await expect(f.locator('#tsCompareScroll').getByRole('row',{name:/Состав/})).toContainText('Возраст детей требует исправления: 7,x');await expect(f.locator('#tsCompareScroll').getByRole('row',{name:/Цена за весь состав/})).toContainText('Проверьте цену: 1e5');await expect(f.locator('#tsCompareScroll caption')).toContainText('несохранёнными правками');
 await f.locator('#tsCompareBack').click();await expect(f.locator('#ts-child_ages')).toHaveValue('7,x');await expect(f.locator('#ts-amount')).toHaveValue('1e5');await expect(f.locator('#tsSave')).toBeDisabled();expect(s.snapshot()).toEqual(before);expect(s.calls).toEqual(calls);expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);
});

test('readonly actor can compare and navigate without acquiring edit rights',async({page})=>{
 const original=completeDraft();original.offers.push({...copy(original.offers[0]),id:'b'.repeat(32),hotel:'Другой синтетический вариант'});const s=await privateEditor(page,{draft:original,readOnly:true}),f=s.editor;await expect(f.locator('#tsStatus')).toContainText('Просмотр');const before=copy(s.snapshot()),calls=copy(s.calls);
 await expect(f.locator('#tsCompareOffers')).toBeEnabled();await f.locator('#tsCompareOffers').click();await f.locator('[data-ts-compare-open]').nth(1).click();await expect(f.locator('#tsOfferCompare')).toBeHidden();await expect(f.locator('#ts-hotel')).toHaveValue('Другой синтетический вариант');await expect(f.locator('#ts-hotel')).toBeDisabled();expect(s.snapshot()).toEqual(before);expect(s.calls).toEqual(calls);expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);
});

test('offer comparison treats equivalent child ages as the same party without rewriting raw input',async({page})=>{
 const original=completeDraft();original.offers[0].child_ages=[4,4,11];original.offers.push({...copy(original.offers[0]),id:'b'.repeat(32),hotel:'Другой синтетический вариант',child_ages:[11,4,4]});
 const s=await privateEditor(page,{draft:original}),f=s.editor;await expect(f.locator('#tsStatus')).toContainText('загружен');const before=copy(s.snapshot()),calls=copy(s.calls);await f.locator('#tsOfferDetails > summary').click();
 // Dispatch the edit and open the view in one task so this checks raw input,
 // independently of the existing 900ms autosave delay on a loaded CI worker.
 await f.locator('#ts-child_ages').evaluate(input=>{input.value='04, 4, 11';input.dispatchEvent(new Event('input',{bubbles:true}));input.closest('dialog').querySelector('#tsCompareOffers').click()});
 await expect(f.locator('#tsOfferCompare')).toBeVisible();await expect(f.locator('#tsCompareScroll caption')).not.toContainText('Различаются');await expect(f.locator('#tsCompareScroll caption')).toContainText('несохранёнными правками');await expect(f.locator('#tsCompareScroll').getByRole('row',{name:/Состав/})).toContainText('дети: 04, 4, 11');await expect(f.locator('#tsCompareScroll').getByRole('row',{name:/Состав/})).toContainText('дети: 11, 4, 4');
 await f.locator('#tsClose').click();expect(s.snapshot()).toEqual(before);expect(s.calls).toEqual(calls);expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);
});

test('offer comparison rejects actual stored publish and revoke handlers',async({page})=>{
 const original=completeDraft();original.offers.push({...copy(original.offers[0]),id:'b'.repeat(32),hotel:'Другой синтетический вариант'});const s=await privateEditor(page,{draft:original}),f=s.editor;await appearancePrivatePreview(page,s);const writes=[];
 const share={version:1,active:true,path:'/selection/?t='+'a'.repeat(64),publication_id:'e'.repeat(32),can_revoke:true};
 await page.route('https://app.anytoour.ru/manager/tour-selection-editor-api.php',async route=>{const d=route.request().postDataJSON();if(d.action==='share')return route.fulfill({json:{ok:true,share}});if(['publish','revoke'].includes(d.action)){writes.push(copy(d));return route.fulfill({json:{ok:true,share}})}return route.fallback()});
 await f.locator('#tsPreview').click();await expect(f.locator('.tsPreviewCard')).toHaveCount(2);await f.locator('#tsShare').click();await expect(f.locator('#tsPublish')).toBeEnabled();await expect(f.locator('#tsRevoke')).toBeEnabled();await f.locator('#tsPublish').evaluate(button=>window.comparisonOldPublish=button.onclick);await f.locator('#tsRevoke').evaluate(button=>window.comparisonOldRevoke=button.onclick);await f.locator('#tsEdit').click();const before=copy(s.snapshot());await f.locator('#tsCompareOffers').click();
 await f.locator('body').evaluate(async()=>{await window.comparisonOldPublish();await window.comparisonOldRevoke()});await expect(f.locator('#tsOfferCompare')).toBeVisible();expect(writes).toEqual([]);expect(s.calls.filter(call=>['save','send','publish','revoke'].includes(call.action))).toEqual([]);expect(s.snapshot()).toEqual(before);expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);
});

test('offer comparison rejects late history and a stored send handler',async({page})=>{
 const original=completeDraft();original.offers.push({...copy(original.offers[0]),id:'b'.repeat(32),hotel:'Другой синтетический вариант'});const s=await privateEditor(page,{draft:original}),f=s.editor,frozen=await appearancePrivatePreview(page,s);await f.locator('#tsPreview').click();await expect(f.locator('.tsPreviewCard')).toHaveCount(2);await expect(f.locator('#tsSend')).toBeEnabled();await f.locator('#tsSend').evaluate(el=>window.comparisonOldSend=el.onclick);await f.locator('#tsEdit').click();
 let release=null,publicationReads=0;await page.route('https://app.anytoour.ru/manager/tour-selection-editor-api.php',async route=>{const d=route.request().postDataJSON();if(d.action==='history')return route.fulfill({json:{ok:true,publications:[{publication_id:'f'.repeat(32),draft_version:1,created_at:'2077-01-01 00:00:00'}]}});if(d.action==='publication'){publicationReads++;await new Promise(resolve=>release=resolve);return route.fulfill({json:{ok:true,publication:frozen()}})}return route.fallback()});
 await f.locator('#tsHistory').click();const old=f.locator('#tsRecovery button').first();await expect(old).toBeVisible();await old.evaluate(el=>window.detachedCompareHistory=el);await old.click();await expect.poll(()=>!!release).toBe(true);const before=copy(s.snapshot());await f.locator('#tsCompareOffers').click();await expect(f.locator('#tsSend')).toBeDisabled();await f.locator('body').evaluate(()=>{window.detachedCompareHistory.click();return window.comparisonOldSend()});
 const response=page.waitForResponse(result=>result.url().includes('/manager/tour-selection-editor-api.php')&&result.request().postDataJSON().action==='publication');release();await response;await f.locator('body').evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
 await expect(f.locator('#tsOfferCompare')).toBeVisible();await expect(f.locator('.tsPreviewCard')).toHaveCount(0);await expect(f.locator('#tsSend')).toBeHidden();expect(publicationReads).toBe(1);expect(s.snapshot()).toEqual(before);expect(s.calls.filter(x=>['save','send','publish','revoke'].includes(x.action))).toEqual([]);expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);
});

// Append to the admitted canonical selection spec after preserving PR1350 cases.
// Uses its existing protected editor harness, real PHP renderer and synthetic photos.
for(const count of [1,2])test('personal mobile gallery preserves actual photo count '+count,async({page,browserName})=>{
 await page.setViewportSize({width:390,height:900});
 const offer={index:0,hotel:'Synthetic gallery hotel',destination:'Synthetic destination',departure:'Москва',date_from:'2077-06-05',nights:7,adults:2,children:0,room:'Standard',meal:'BB',amount:'345678',currency:'RUB',rationale:'Synthetic recommendation',compromise:'',flights:'',inclusions:'',constraints:'',observed_at:'',photos:Array.from({length:count},(_,i)=>'photo.php?photo='+i),photo_layout:'gallery-v1',bot_links:[]};
 const selection={title:'Synthetic gallery',intro:'',version:1,updated_at:'2077-01-01 00:00:00',appearance:{template:'personal',palette:'forest',agency_name:'Synthetic agency'},offers:[offer]};
 const html=execFileSync('php',['-r',`$selection=json_decode(stream_get_contents(STDIN),true);$h=static fn(string $s):string=>htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');require 'selection/view.php';`],{cwd:root,input:JSON.stringify(selection),encoding:'utf8'});
 await page.route('http://127.0.0.1:4173/personal-gallery/**',route=>{const u=new URL(route.request().url());if(u.pathname.endsWith('/selection.css'))return route.fulfill({contentType:'text/css',body:fs.readFileSync(path.join(root,'selection/selection.css'))});if(u.pathname.endsWith('/photo.php'))return route.fulfill({contentType:'image/jpeg',body:fs.readFileSync(path.join(root,'assets/owner-tour-cards/compact-v1/cover.jpg'))});return route.fulfill({contentType:'text/html',body:html})});
 await page.goto('http://127.0.0.1:4173/personal-gallery/');await expect(page.locator('.photos img')).toHaveCount(count);for(const img of await page.locator('.photos img').all())await expect.poll(()=>img.evaluate(x=>x.complete&&x.naturalWidth>0)).toBe(true);
 const geometry=await page.locator('.photos').evaluate(el=>({width:el.clientWidth,columns:getComputedStyle(el).gridTemplateColumns.split(' ').length,figures:[...el.querySelectorAll('figure')].map(x=>x.getBoundingClientRect().width)}));expect(geometry.columns).toBe(count);if(count===1)expect(Math.abs(geometry.width-geometry.figures[0])).toBeLessThanOrEqual(1);else expect(Math.abs(geometry.figures[0]-geometry.figures[1])).toBeLessThanOrEqual(1);
 await expect(page.locator('.price')).toHaveCSS('color','rgb(8, 118, 84)');await expect(page.locator('.personalManager,.personalComparison')).toHaveCount(0);expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({path:path.join(root,'manager2-artifacts',`tour-selection-personal-gallery-${count}-${browserName}-390.png`),fullPage:true,animations:'disabled'});
});

for(const width of [390,430,768,1440])test('personal client page exact conditions and expandable comparison '+width,async({page,browserName})=>{
 await page.setViewportSize({width,height:900});const requests=[];
 const manager={name:'Синтетический менеджер <script>текст</script>',note:'Помогу сравнить варианты и уточню условия. <svg> — обычный текст.',phone:'+7 (000) 000-00-00',email:'manager@example.invalid'};
 const offer={index:0,hotel:'Синтетический отель у моря',destination:'Синтетическое направление',departure:'Москва',date_from:'2077-11-20',nights:8,adults:2,children:2,child_ages:[4,4],room:'Standard Garden View',meal:'AI',amount:'345678',currency:'RUB',price_qualifier:'from',rationale:'Для спокойного отдыха на большой территории',compromise:'Вторая линия, автобус до пляжа',flights:'Туда: синтетический рейс 20.11, 22:00 → 02:45 (+1 день)\nОбратно: синтетический рейс 29.11',inclusions:'Перелёт, проживание, питание AI. Остальное уточняется.',constraints:'Багаж и обязательные доплаты требуют проверки.',observed_at:'2077-11-01T12:00:00Z',photos:[0,1,2].map(i=>'photo.php?card=0&photo='+i),photo_layout:'gallery-v1',bot_links:{telegram:'https://t.me/synthetic_bot?start=exact_publication_v3_card0'}};
 const second={...copy(offer),index:1,hotel:'Другой синтетический отель',room:'Family Pool View',meal:'BB',amount:'1234.50',currency:'EUR',price_qualifier:'fixed',child_ages:[0,6],photos:[0,1,2].map(i=>'photo.php?card=1&photo='+i),bot_links:{telegram:'https://t.me/synthetic_bot?start=exact_publication_v3_card1'}};
 const palette=width===390?'forest':width===430?'sunset':'ocean'; const selection={title:'Ваш отдых — персональная подборка',intro:'Несколько вариантов с разными условиями. Выберите, что ближе именно вам.',version:3,updated_at:'2077-11-01 12:00:00',appearance:{template:'personal',palette,agency_name:'Синтетическое агентство',manager},offers:[offer,second]};
 const html=execFileSync('php',['-r',`$selection=json_decode(stream_get_contents(STDIN),true);$h=static fn(string $s):string=>htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');require 'selection/view.php';`],{cwd:root,input:JSON.stringify(selection),encoding:'utf8'});
 const csp=fs.readFileSync(path.join(root,'selection/index.php'),'utf8').match(/header\("Content-Security-Policy: ([^"]+)"\)/)[1];
 await page.route('**/*',async route=>{
  const url=new URL(route.request().url());requests.push(url.pathname);if(url.origin!=='http://127.0.0.1:4173')throw Error('Unexpected external personal-template request');
  if(url.pathname==='/personal-page/')return route.fulfill({contentType:'text/html',headers:{'Content-Security-Policy':csp},body:html});
  if(url.pathname==='/personal-page/selection.css')return route.fulfill({contentType:'text/css',body:fs.readFileSync(path.join(root,'selection/selection.css'))});
  if(url.pathname==='/personal-page/photo.php')return route.fulfill({contentType:'image/jpeg',body:fs.readFileSync(path.join(root,'assets/owner-tour-cards/compact-v1/cover.jpg'))});
  throw Error('Unexpected personal-template resource');
 });
 await page.goto('http://127.0.0.1:4173/personal-page/');
 await expect(page.locator('.personalManager h2')).toHaveText(manager.name);await expect(page.locator('.price').first()).toHaveCSS('color',{forest:'rgb(8, 118, 84)',sunset:'rgb(161, 68, 39)',ocean:'rgb(23, 102, 161)'}[palette]);await expect(page.locator('.personalManager')).toContainText(manager.note);
 await expect(page.locator('.personalManager script,.personalManager svg')).toHaveCount(0);
 await expect(page.locator('.managerContacts a[href^="tel:"]')).toHaveAttribute('href','tel:+70000000000');
 await expect(page.locator('.managerContacts a[href^="mailto:"]')).toHaveAttribute('href','mailto:manager%40example.invalid');
 await expect(page.locator('.offer')).toHaveCount(2);await expect(page.locator('.photos img')).toHaveCount(6);
 for(const img of await page.locator('.photos img').all()){await img.scrollIntoViewIfNeeded();await expect.poll(()=>img.evaluate(i=>i.complete&&i.naturalWidth>0)).toBe(true)}
 const fillGaps=await page.locator('.photos').first().evaluate(el=>[...el.querySelectorAll('figure')].map(figure=>{const f=figure.getBoundingClientRect(),i=figure.querySelector('img').getBoundingClientRect();return Math.max(Math.abs(f.top-i.top),Math.abs(f.bottom-i.bottom))}));expect(Math.max(...fillGaps)).toBeLessThanOrEqual(1);console.log('personal-gallery-fill',JSON.stringify({width,fillGaps}));
 const details=page.locator('.personalDetails').first();await expect(details).not.toHaveAttribute('open','');await expect(page.locator('.personalRecommendation').first()).toBeVisible();await expect(page.locator('.personalCompromise').first()).toContainText(offer.compromise);
 await details.locator('summary').click();await expect(details).toHaveAttribute('open','');await expect(details).toContainText(offer.flights);await expect(details).toContainText(offer.constraints);
 await details.locator('summary').focus();await page.keyboard.press('Enter');await expect(details).not.toHaveAttribute('open','');await page.keyboard.press('Enter');await expect(details).toHaveAttribute('open','');
 await expect(page.locator('.personalSummary').first()).toContainText('4, 4');await expect(page.locator('.fareNotice').first()).toContainText(offer.observed_at);
 await expect(page.locator('.comparisonScroll tbody tr')).toHaveCount(2);await expect(page.locator('.comparisonScroll tbody tr').first()).toContainText('от 345 678,00 RUB');await expect(page.locator('.comparisonScroll tbody tr').nth(1)).toContainText('1 234,50 EUR');
 await expect(page.locator('.comparisonScroll tbody tr').nth(1)).toContainText('0, 6');await expect(page.locator('.comparisonScroll tbody tr').nth(1)).toContainText(second.room);
 await expect(page.locator('#variant-1 .botActions a')).toHaveAttribute('href',offer.bot_links.telegram);await expect(page.locator('#variant-2 .botActions a')).toHaveAttribute('href',second.bot_links.telegram);
 expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);expect(await page.locator('main.selection').evaluate(el=>el.scrollWidth-el.clientWidth)).toBeLessThanOrEqual(1);
 for(const item of await page.locator('.personalDetails summary,.managerContacts a,.personalNavigation a').all())expect(await item.evaluate(el=>el.getBoundingClientRect().height)).toBeGreaterThanOrEqual(44);
 await page.locator('.personalManager').scrollIntoViewIfNeeded();fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({path:path.join(root,'manager2-artifacts',`tour-selection-personal-public-${browserName}-${width}.png`),fullPage:true,animations:'disabled'});
 await page.locator('.comparisonScroll a').nth(1).click();await expect(page).toHaveURL(/#variant-2$/);expect(requests.filter(p=>p.endsWith('/photo.php'))).toHaveLength(6);
});

for(const width of [390,1440])test('personal template manager can assemble and retain exact frozen presentation '+width,async({page,browserName})=>{
 await page.setViewportSize({width,height:900});const original=completeDraft(),s=await privateEditor(page,{draft:original}),f=s.editor,frozen=await appearancePrivatePreview(page,s);
 await expect(f.locator('#tsStatus')).toContainText('загружен');await f.locator('#tsAppearance').click();await f.locator('#tsAppearanceTemplate').selectOption('personal');
 const manager={name:'Синтетический менеджер <img src=x>',note:'Моя рекомендация: сравните условия и обсудим варианты.',phone:'+7 (000) 000-00-00',email:'manager@example.invalid'};
 for(const key of ['Name','Note','Phone','Email'])await f.locator('#tsManager'+key).fill(manager[key.toLowerCase()]);
 await expect.poll(()=>s.snapshot().draft.appearance?.manager).toEqual(manager);await expect(f.locator('#tsSaved')).toHaveText('Сохранено на сервере');expect(s.snapshot().draft.offers).toEqual(original.offers);
 for(const id of ['tsManagerName','tsManagerPhone','tsManagerEmail'])expect(await f.locator('#'+id).evaluate(el=>el.getBoundingClientRect().height)).toBeGreaterThanOrEqual(44);
 expect(await f.locator('#tourSelectionDialog').evaluate(el=>el.scrollWidth-el.clientWidth)).toBeLessThanOrEqual(1);
 fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({path:path.join(root,'manager2-artifacts',`tour-selection-personal-settings-${browserName}-${width}.png`),animations:'disabled'});
 await f.locator('#tsAppearanceBack').click();await f.locator('#tsPreview').click();await expect(f.locator('.tsManagerPresentation h4')).toHaveText(manager.name);await expect(f.locator('.tsManagerPresentation img')).toHaveCount(0);await expect(f.locator('.tsManagerPresentation')).toContainText(manager.email);
 const publication=frozen();expect(publication.appearance.manager).toEqual(manager);expect(publication.appearance.template).toBe('personal');
 const previewImages=f.locator('.tsPhotos img');await expect(previewImages).toHaveCount(publication.cards.reduce((sum,card)=>sum+card.photos.length,0));
 for(const img of await previewImages.all()){
  await expect.poll(()=>img.evaluate(image=>image.complete&&image.naturalWidth>0),{timeout:5000}).toBe(true);
  await img.evaluate(async image=>{let timer;try{await Promise.race([image.decode(),new Promise((_,reject)=>{timer=setTimeout(()=>reject(new Error('Personal preview image decode timed out')),5000)})])}finally{clearTimeout(timer)}});
 }
 await f.locator('#tsEditor').evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));console.log('personal-preview-ready',JSON.stringify({width,images:await previewImages.count(),decoded:true}));
 await page.screenshot({path:path.join(root,'manager2-artifacts',`tour-selection-personal-preview-${browserName}-${width}.png`),animations:'disabled'});
 await f.locator('#tsEdit').click();await f.locator('#tsAppearance').click();await f.locator('#tsManagerName').fill('Later private edit');await expect.poll(()=>s.snapshot().draft.appearance.manager.name).toBe('Later private edit');await expect(f.locator('#tsSaved')).toHaveText('Сохранено на сервере');await f.locator('#tsAppearanceBack').click();
 await f.locator('#tsHistory').click();await f.getByRole('button',{name:new RegExp('Версия '+publication.draft_version+' ·')}).click();await expect(f.locator('.tsManagerPresentation h4')).toHaveText(manager.name);expect(frozen()).toEqual(publication);
 expect(s.calls.some(c=>['send','publish','biblio_add','onetouch_add','collector_import'].includes(c.action))).toBe(false);expect(s.unexpected).toEqual([]);expect(s.errors).toEqual([]);
});

test('detached personal contact editor cannot modify returned offer editing',async({page})=>{
 const s=await privateEditor(page,{draft:completeDraft()}),f=s.editor;await expect(f.locator('#tsStatus')).toContainText('загружен');await f.locator('#tsAppearance').click();await f.locator('#tsAppearanceTemplate').selectOption('personal');await f.locator('#tsManagerName').fill('Synthetic manager');await expect(f.locator('#tsSaved')).toHaveText('Сохранено на сервере');
 await f.locator('#tsManagerName').evaluate(el=>window.detachedPersonalInput=el.oninput);await f.locator('#tsAppearanceBack').click();const prior=copy(s.snapshot().draft),saved=s.calls.filter(x=>x.action==='save').length;
 const frame=page.frames().find(x=>x.url().startsWith('https://app.anytoour.ru/manager/tour-selection-editor.php'));await frame.evaluate(()=>window.detachedPersonalInput({target:{value:'Detached malicious edit'}}));await frame.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
 expect(s.snapshot().draft).toEqual(prior);expect(s.calls.filter(x=>x.action==='save')).toHaveLength(saved);expect(s.unexpected).toEqual([]);expect(s.errors).toEqual([]);
});

// Explicit AnyTour branding: new cases only; earlier template acceptance stays intact.
for(const width of [390,430,768,1440])test('AnyTour official brand public page preserves offer and self-only resources '+width,async({page,browserName})=>{
 await page.setViewportSize({width,height:900});const brandOrigin='http://127.0.0.1:4173',errors=[],requests=[];page.on('pageerror',error=>errors.push(error.message));
 const offer={index:0,hotel:'Синтетический отель у моря',destination:'Синтетическое направление',departure:'Москва',date_from:'2077-11-20',nights:9,adults:3,children:0,child_ages:[],room:'Family Garden View',meal:'AI',amount:'389000',currency:'RUB',rationale:'Пляж и большая территория для спокойного отдыха.',compromise:'Стоимость и наличие нужно подтвердить.',flights:'Прямой перелёт. Багаж 23 кг.',inclusions:'Проживание, питание AI, перелёт.',constraints:'Сохранённые условия; стоимость требует подтверждения.',observed_at:'2077-11-01T12:00:00Z',photos:[0,1,2].map(i=>'photo.php?photo='+i),bot_links:{telegram:'https://t.me/synthetic_bot?start=exact_brand_v2_card0'}};
 const selection={title:'Ваш отдых у моря',intro:'Подобрали вариант для вашей поездки. Фотографии, цена и условия собраны на одной странице.',version:2,updated_at:'2077-11-02 12:00:00',appearance:{template:'personal',palette:'anytour',agency_name:'AnyTour'},offers:[offer]};
 const html=execFileSync('php',['-r',`$selection=json_decode(stream_get_contents(STDIN),true);$h=static fn(string $s):string=>htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');require 'selection/view.php';`],{cwd:root,input:JSON.stringify(selection),encoding:'utf8'});
 const csp=fs.readFileSync(path.join(root,'selection/index.php'),'utf8').match(/header\("Content-Security-Policy: ([^"]+)"\)/)[1];
 await page.route('**/*',route=>{const u=new URL(route.request().url());requests.push(u.pathname+u.search);if(u.origin!==brandOrigin)throw Error('Unexpected brand resource origin');
  if(u.pathname==='/brand-selection/')return route.fulfill({contentType:'text/html',headers:{'Content-Security-Policy':csp},body:html});
  if(u.pathname==='/brand-selection/selection.css')return route.fulfill({contentType:'text/css',body:fs.readFileSync(path.join(root,'selection/selection.css'))});
  if(u.pathname==='/brand-selection/photo.php')return route.fulfill({contentType:'image/jpeg',body:fs.readFileSync(path.join(root,'assets/owner-tour-cards/compact-v1',['cover','beach','room'][Number(u.searchParams.get('photo'))]+'.jpg'))});
  throw Error('Unexpected brand resource');});
 await page.goto(brandOrigin+'/brand-selection/');await expect(page.getByRole('link',{name:'AnyTour',exact:true})).toBeVisible();
 const logo=page.locator('.brand .anytourLogo');await expect(logo).toHaveAttribute('viewBox','0 0 224 61');expect(await logo.locator('path').count()).toBeGreaterThan(10);await expect(logo.locator('script,image,use,foreignObject,style')).toHaveCount(0);
 expect(await logo.locator('path').evaluateAll(paths=>[...new Set(paths.map(p=>p.getAttribute('fill')))].sort())).toEqual(['#2743CB','#FF510C']);
 await expect(page.locator('h1')).toHaveCSS('color','rgb(39, 67, 203)');await expect(page.locator('.price')).toHaveCSS('color','rgb(39, 67, 203)');await expect(page.locator('.selectionHead')).toHaveCSS('border-top-color','rgb(255, 81, 12)');
 await expect(page.locator('.offer')).toHaveCount(1);await expect(page.locator('.photos img')).toHaveCount(3);for(const img of await page.locator('.photos img').all()){await img.scrollIntoViewIfNeeded();await expect.poll(()=>img.evaluate(i=>i.complete&&i.naturalWidth>0)).toBe(true)}
 await expect(page.locator('.price')).toContainText('389 000,00 RUB');await expect(page.locator('.personalSummary')).toContainText('Взрослых: 3');await expect(page.locator('.personalSummary')).toContainText(offer.room);await expect(page.locator('.personalSummary')).toContainText('9 ночей');
 await page.locator('.personalDetails summary').click();await expect(page.locator('.personalDetails')).toContainText(offer.flights);await expect(page.locator('.personalDetails')).toContainText(offer.constraints);await expect(page.locator('.fareNotice')).toContainText(offer.observed_at);
 await expect(page.locator('.botActions a')).toHaveAttribute('href',offer.bot_links.telegram);expect(await page.locator('.brand').evaluate(el=>el.getBoundingClientRect().height)).toBeGreaterThanOrEqual(44);expect(await page.locator('.botActions a').evaluate(el=>el.getBoundingClientRect().height)).toBeGreaterThanOrEqual(44);
 expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);expect(requests.some(url=>url==='/brand-selection/selection.css?v=0.1.4')).toBe(true);expect(errors).toEqual([]);
 await page.locator('.selectionHead').scrollIntoViewIfNeeded();fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({path:path.join(root,'manager2-artifacts',`tour-selection-anytour-public-${browserName}-${width}.png`),fullPage:true,animations:'disabled'});
});

for(const width of [390,1440])test('AnyTour official brand manager choice freezes preview and print without changing offers '+width,async({page,browserName})=>{
 await page.setViewportSize({width,height:900});const original=completeDraft(),s=await privateEditor(page,{draft:original}),f=s.editor,frozen=await appearancePrivatePreview(page,s);
 await expect(f.locator('#tsStatus')).toContainText('загружен');await f.locator('#tsAppearance').click();await expect(f.locator('#tsAppearancePalette')).toHaveValue('anytour');
 await f.locator('#tsAppearanceTemplate').selectOption('personal');await expect.poll(()=>s.snapshot().draft.appearance).toEqual({template:'personal',palette:'anytour',agency_name:'AnyTour',manager:{name:'',note:'',phone:'',email:''}});await expect(f.locator('#tsSaved')).toHaveText('Сохранено на сервере');
 expect(s.snapshot().draft.offers).toEqual(original.offers);await f.locator('#tsAppearanceBack').click();await f.locator('#tsPreview').click();await expect(f.locator('.tsSelectionBrand .tsAnyTourLogo')).toBeVisible();await expect(f.locator('.tsSelectionHeading h4')).toHaveCSS('color','rgb(39, 67, 203)');await expect(f.locator('#tsPrint')).toBeEnabled();
 const publication=frozen();expect(publication.appearance.palette).toBe('anytour');expect(publication.cards.map(c=>c.snapshot.amount)).toEqual(original.offers.filter(o=>o.selected).map(o=>o.amount));expect(await f.locator('html').evaluate(el=>el.scrollWidth-el.clientWidth)).toBeLessThanOrEqual(1);
 fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({path:path.join(root,'manager2-artifacts',`tour-selection-anytour-preview-${browserName}-${width}.png`),animations:'disabled'});
 const frame=page.frames().find(x=>x.url().startsWith('https://app.anytoour.ru/manager/tour-selection-editor.php'));await frame.evaluate(()=>{window.print=()=>{}});await f.locator('#tsPrint').click();await expect(f.locator('.tsPrintBrand .tsAnyTourLogo')).toHaveCount(1);await expect(f.locator('.tsPrintProposal')).toHaveClass(/tsPalette-anytour/);expect(await f.locator('.tsPrintOffer .tsCardText').allTextContents()).toEqual(publication.cards.map(c=>c.text));
 await page.emulateMedia({media:'print'});await page.screenshot({path:path.join(root,'manager2-artifacts',`tour-selection-anytour-print-${browserName}-${width}.png`),fullPage:true,animations:'disabled'});await page.emulateMedia({media:'screen'});await f.locator('#tsPrint').click();
 await f.locator('#tsEdit').click();await f.locator('#tsAppearance').click();await f.locator('#tsAppearancePalette').selectOption('sunset');await expect(f.locator('#tsSaved')).toHaveText('Сохранено на сервере');await f.locator('#tsAppearanceBack').click();await f.locator('#tsHistory').click();await f.getByRole('button',{name:new RegExp('Версия '+publication.draft_version+' ·')}).click();await expect(f.locator('.tsSelectionBrand .tsAnyTourLogo')).toBeVisible();expect(frozen()).toEqual(publication);expect(s.snapshot().draft.offers).toEqual(original.offers);
 expect(s.calls.some(c=>['send','publish','biblio_add','onetouch_add','collector_import'].includes(c.action))).toBe(false);expect(s.unexpected).toEqual([]);expect(s.errors).toEqual([]);
});

// Bulk terms: only new cases; the entire preceding canonical spec is retained.
async function setBulkTerm(scope,key,value,mode){
 await scope.locator('#tsTermsUse-'+key).check();
 if(mode)await scope.locator('#tsTermsMode-'+key).selectOption(mode);
 if(mode!=='clear')await scope.locator('#tsTermsValue-'+key).fill(value);
}
async function confirmBulkTerms(scope){await scope.locator('#tsTermsConfirm').check();await scope.locator('#tsTermsApply').click();}
for(const width of [390,430,768,1440])test('bulk terms preview applies only selected fields and preserves imported evidence '+width,async({page,browserName})=>{
 await page.setViewportSize({width,height:900});const original=mixedPhotoDraft();original.offers[2].selected=false;
 const s=await privateEditor(page,{draft:original}),f=s.editor;await expect(f.locator('#tsStatus')).toContainText('загружен');await f.locator('#tsBulkTermsOpen').click();
 await expect(f.locator('#tsTermsOffers input:checked')).toHaveCount(2);await expect(f.locator('#tsTermsApply')).toBeDisabled();
 await expect(f.locator('#tsTermsValue-nights')).toBeHidden();await f.locator('#tsTermsUse-nights').check();await expect(f.locator('#tsTermsValue-nights')).toBeVisible();await f.locator('#tsTermsUse-nights').uncheck();await expect(f.locator('#tsTermsValue-nights')).toBeHidden();
 const inputs={date_from:'2077-02-28',adults:'3',child_ages:'0, 6, 6',room:'Family <img src=x>',meal:'AI',constraints:'Проверить багаж и обязательные доплаты.'};
 for(const [key,value]of Object.entries(inputs))await setBulkTerm(f,key,value);
 await expect(f.locator('.tsTermsRow')).toHaveCount(2);await expect(f.locator('.tsTermsRow').first()).toContainText('Станет: 0, 6, 6');await expect(f.locator('.tsTermsRow').first()).toContainText('Family <img src=x>');await expect(f.locator('#tsTermsPreview img,#tsTermsPreview svg,#tsTermsPreview script')).toHaveCount(0);
 expect(s.snapshot().draft).toEqual(original);expect(s.calls.filter(x=>x.action==='save')).toHaveLength(0);await expect(f.locator('#tsTermsApply')).toBeDisabled();
 for(const id of ['tsTermsCancel','tsTermsApply','tsTermsValue-date_from','tsTermsValue-adults'])expect(await f.locator('#'+id).evaluate(el=>el.getBoundingClientRect().height)).toBeGreaterThanOrEqual(44);
 expect(await f.locator('#tourSelectionDialog').evaluate(el=>el.scrollWidth-el.clientWidth)).toBeLessThanOrEqual(1);fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});
 await f.locator('#tsTermsFields').scrollIntoViewIfNeeded();await page.screenshot({path:path.join(root,'manager2-artifacts',`tour-selection-bulk-terms-fields-${browserName}-${width}.png`),animations:'disabled'});
 await f.locator('#tsTermsPreview').scrollIntoViewIfNeeded();await page.screenshot({path:path.join(root,'manager2-artifacts',`tour-selection-bulk-terms-preview-${browserName}-${width}.png`),animations:'disabled'});
 await confirmBulkTerms(f);await expect(f.locator('#tsSaved')).toHaveText('Сохранено на сервере');expect(s.calls.filter(x=>x.action==='save')).toHaveLength(1);
 const result=s.snapshot().draft,keys=Object.keys(inputs),unchanged=offer=>Object.fromEntries(Object.entries(offer).filter(([key])=>!keys.includes(key)));
 for(const index of [0,1]){expect(unchanged(result.offers[index])).toEqual(unchanged(original.offers[index]));expect(result.offers[index]).toMatchObject({date_from:'2077-02-28',adults:3,child_ages:[0,6,6],room:inputs.room,meal:'AI'});expect(result.offers[index].constraints).toBe([original.offers[index].constraints,inputs.constraints].filter(Boolean).join('\n'));}
 expect(result.offers[2]).toEqual(original.offers[2]);const normalized=JSON.parse(execFileSync('php',['-r',`require 'services/ManagerTourSelectionDraft.php';echo json_encode(ManagerTourSelectionDraft::normalize(json_decode(stream_get_contents(STDIN),true)),JSON_THROW_ON_ERROR);`],{cwd:root,input:JSON.stringify(result),encoding:'utf8'}));expect(normalized).not.toBeNull();expect(normalized.offers[0].child_ages).toEqual([0,6,6]);
 expect(s.calls.some(x=>['send','publish','biblio_add','onetouch_add','collector_import'].includes(x.action))).toBe(false);expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);
});

test('bulk terms validation prevents a late invalid offer from partly changing the batch',async({page})=>{
 const original=completeDraft();original.offers.push({...copy(original.offers[0]),id:'b'.repeat(32),constraints:'x'.repeat(1199)});
 const s=await privateEditor(page,{draft:original}),f=s.editor;await expect(f.locator('#tsStatus')).toContainText('загружен');await f.locator('#tsBulkTermsOpen').click();await setBulkTerm(f,'constraints','Append');
 await expect(f.locator('#tsTermsError')).toContainText('превышен размер');await expect(f.locator('#tsTermsApply')).toBeDisabled();expect(s.snapshot().draft).toEqual(original);expect(s.calls.filter(x=>x.action==='save')).toHaveLength(0);
 await f.locator('#tsTermsUse-constraints').uncheck();await setBulkTerm(f,'adults','21');await expect(f.locator('#tsTermsError')).toContainText('от 1 до 20');await f.locator('#tsTermsUse-adults').uncheck();await setBulkTerm(f,'child_ages','0, 18');await expect(f.locator('#tsTermsError')).toContainText('до 17');
 await f.locator('#tsTermsCancel').click();expect(s.snapshot().draft).toEqual(original);expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);
});

test('bulk terms clear and replace are explicit and preserve unselected fields with explicitly no children',async({page})=>{
 const original=completeDraft(),s=await privateEditor(page,{draft:original}),f=s.editor;await expect(f.locator('#tsStatus')).toContainText('загружен');await f.locator('#tsBulkTermsOpen').click();
 for(const [key,value]of Object.entries({departure:'Санкт-Петербург',nights:'09',child_ages:''}))await setBulkTerm(f,key,value);
 await setBulkTerm(f,'flights','Рейсы уточняются отдельно.','replace');await setBulkTerm(f,'inclusions','','clear');await expect(f.locator('#tsTermsPreview')).toContainText('Станет: Без детей');await expect(f.locator('#tsTermsPreview')).toContainText('Станет: Не указано');
 await confirmBulkTerms(f);await expect(f.locator('#tsSaved')).toHaveText('Сохранено на сервере');expect(s.snapshot().draft.offers[0]).toEqual({...original.offers[0],departure:'Санкт-Петербург',nights:9,child_ages:[],flights:'Рейсы уточняются отдельно.',inclusions:''});expect(s.receipts.size).toBe(1);expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);
});

test('bulk terms changing or silently tampering with preview invalidates explicit confirmation',async({page})=>{
 const original=completeDraft(),s=await privateEditor(page,{draft:original}),f=s.editor;await expect(f.locator('#tsStatus')).toContainText('загружен');await f.locator('#tsBulkTermsOpen').click();await setBulkTerm(f,'room','New room');await f.locator('#tsTermsConfirm').check();
 await f.locator('#tsTermsValue-room').fill('Changed room');await expect(f.locator('#tsTermsConfirm')).not.toBeChecked();await expect(f.locator('#tsTermsApply')).toBeDisabled();await f.locator('#tsTermsConfirm').check();
 await f.locator('#tsTermsValue-room').evaluate(el=>{el.value='Silent unreviewed room'});await f.locator('#tsTermsApply').click();await expect(f.locator('#tsTermsConfirm')).not.toBeChecked();await expect(f.locator('#tsTermsError')).toContainText('текущий предпросмотр');expect(s.snapshot().draft).toEqual(original);expect(s.calls.filter(x=>x.action==='save')).toHaveLength(0);expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);
});

test('bulk terms cancel and Escape retain focus without saving at a short mobile height',async({page,browserName})=>{
 await page.setViewportSize({width:390,height:420});const original=completeDraft(),s=await privateEditor(page,{draft:original}),f=s.editor;await expect(f.locator('#tsStatus')).toContainText('загружен');await f.locator('#tsBulkTermsOpen').click();await setBulkTerm(f,'meal','BB');
 await expect(f.locator('#tsTermsCancel')).toBeInViewport();await expect(f.locator('#tsTermsApply')).toBeInViewport();await f.locator('#tsTermsPreview').scrollIntoViewIfNeeded();await page.screenshot({path:path.join(root,'manager2-artifacts',`tour-selection-bulk-terms-short-${browserName}.png`),animations:'disabled'});
 await f.locator('#tsTermsValue-meal').press('Escape');await expect(f.locator('#tsBulkTerms')).toBeHidden();await expect(f.locator('#tsBulkTermsOpen')).toBeFocused();await expect(f.locator('#tourSelectionDialog')).toBeVisible();expect(s.snapshot().draft).toEqual(original);expect(s.calls.filter(x=>x.action==='save')).toHaveLength(0);expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);
});

test('bulk terms lost save outcome reconciles one canonical operation before any retry',async({page})=>{
 const s=await privateEditor(page,{draft:completeDraft(),fail:'after'}),f=s.editor;await expect(f.locator('#tsStatus')).toContainText('загружен');await f.locator('#tsBulkTermsOpen').click();await setBulkTerm(f,'meal','BB');await confirmBulkTerms(f);
 await expect(f.getByRole('button',{name:'Проверить сохранение',exact:true})).toBeVisible();expect(s.calls.filter(x=>x.action==='save')).toHaveLength(1);await f.getByRole('button',{name:'Проверить сохранение',exact:true}).click();await expect(f.locator('#tsSaved')).toHaveText('Сохранено на сервере');expect(s.snapshot().draft.offers[0].meal).toBe('BB');expect(s.calls.filter(x=>x.action==='save')).toHaveLength(1);expect(s.receipts.size).toBe(1);expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);
});

test('bulk terms canonical conflict preserves the other manager draft and the local intended changes',async({page})=>{
 const original=completeDraft(),s=await fixture(page,{draft:original});await open(page,{edit:false});await page.locator('#tsBulkTermsOpen').click();await setBulkTerm(page,'room','Local batch room');const colleague=copy(original);colleague.offers[0].amount='260000';s.drafts.set(11,{version:2,draft:colleague});
 await confirmBulkTerms(page);await expect(page.locator('#tsRecovery')).toContainText('другая версия');expect(s.drafts.get(11)).toEqual({version:2,draft:colleague});await expect(page.locator('#ts-room')).toHaveValue('Local batch room');await expect(page.locator('#tsBulkTermsOpen')).toBeDisabled();expect(s.calls.filter(x=>x.action==='save')).toHaveLength(1);clean(s);
});

test('bulk terms detached apply cannot modify another client or a later batch',async({page})=>{
 const original=completeDraft(),s=await fixture(page,{draft:original});await open(page,{edit:false});await page.locator('#tsBulkTermsOpen').click();await setBulkTerm(page,'room','Stale batch');await page.locator('#tsTermsConfirm').check();await page.evaluate(()=>{window.detachedTermsApply=document.getElementById('tsTermsApply').onclick});
 await page.locator('#tsClose').click();await page.locator('#inboxList [data-conversation-id="12"]').click();await open(page);await page.evaluate(()=>detachedTermsApply());expect(s.drafts.has(12)).toBe(false);await page.locator('#tsClose').click();await page.locator('#inboxList [data-conversation-id="11"]').click();await open(page,{edit:false});await page.locator('#tsBulkTermsOpen').click();await page.evaluate(()=>detachedTermsApply());await expect(page.locator('#tsTermsUse-room')).not.toBeChecked();expect(s.drafts.get(11).draft).toEqual(original);expect(s.calls.filter(x=>x.action==='save')).toHaveLength(0);clean(s);
});

test('bulk terms remain unavailable to a read-only viewer',async({page})=>{
 const original=completeDraft(),s=await fixture(page,{draft:original,readOnly:true});await open(page,{edit:false});await expect(page.locator('#tsBulkTermsOpen')).toBeDisabled();await page.evaluate(()=>document.getElementById('tsBulkTermsOpen').onclick());await expect(page.locator('#tsBulkTerms')).toBeHidden();expect(s.drafts.get(11).draft).toEqual(original);expect(s.calls.filter(x=>x.action==='save')).toHaveLength(0);clean(s);
});

test('bulk terms preserve a previous frozen publication after new conditions are saved',async({page})=>{
 const original=completeDraft(),s=await privateEditor(page,{draft:original}),f=s.editor,frozen=await appearancePrivatePreview(page,s);await expect(f.locator('#tsStatus')).toContainText('загружен');await f.locator('#tsPreview').click();await expect(f.locator('#tsPrint')).toBeEnabled();const publication=frozen();
 await f.locator('#tsEdit').click();await f.locator('#tsBulkTermsOpen').click();await setBulkTerm(f,'room','New draft room');await setBulkTerm(f,'flights','New draft flight','replace');await confirmBulkTerms(f);await expect(f.locator('#tsSaved')).toHaveText('Сохранено на сервере');
 await f.locator('#tsHistory').click();await f.getByRole('button',{name:new RegExp('Версия '+publication.draft_version+' ·')}).click();expect(frozen()).toEqual(publication);await expect(f.locator('.tsCardText')).toContainText(original.offers[0].room);await expect(f.locator('.tsCardText')).not.toContainText('New draft room');expect(s.snapshot().draft.offers[0].room).toBe('New draft room');expect(s.calls.some(x=>['send','publish','biblio_add','onetouch_add','collector_import'].includes(x.action))).toBe(false);expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);
});

// Parametric actual-party intake and photo identity. All HTTP data/JPEGs are synthetic.
async function oneTouchParametricFixture(page,options={}){
 const adults=options.adults??3,photo='https://image.onetouch.travel/synthetic/party-cover.jpg',gallery='https://onetouch.travel/hotels/synthetic-hotel-1001/',reference='SYNTHETIC_SHARED_PARTY_REFERENCE',newId='d'.repeat(32),otherId='e'.repeat(32);
 const image=await page.evaluate(()=>{const canvas=document.createElement('canvas');canvas.width=160;canvas.height=120;canvas.getContext('2d').fillRect(0,0,160,120);return canvas.toDataURL('image/jpeg').split(',')[1]}),bytes=Buffer.from(image,'base64');
 const base={...oneTouchBrowserBaseOffer(),adults:options.baseAdults??2},url="https://b2b.onetouch.travel/search_tour?samo_action=PRICES&TOWNFROMINC=2&STATEINC=9&TOURTYPE=0&TOURINC=0&PROGRAMGROUPINC=0&PROGRAMINC=0&CHECKIN_BEG=20770107&NIGHTS_FROM=7&CHECKIN_END=20770107&NIGHTS_TILL=7&ADULT="+adults+"&CURRENCY=1&CHILD=0&TOWNS_ANY=1&townssearch=0&TOWNS=&STARS_ANY=1&STARS=&HOTELS_ANY=1&hotelsearch=0&HOTELS=&MEALS_ANY=1&MEALS=&ROOMS_ANY=1&ROOMS=&CHILD_IN_BED=0&FREIGHT=1&COMFORTABLE_SEATS=0&FILTER=1&MOMENT_CONFIRM=0&WITHOUT_PROMO=0&HOTELTYPES=&PARTITION_PRICE=&PRICEPAGE=1&DYN_SEPARATE=1&rev=965964269";
 const fields={...copy(base),hotel:'Синтетический OneTouch · состав из источника',adults,amount:'123456.78',price_qualifier:'from',source_label:'OneTouch · synthetic',source_url:url};
 const raw={capture_mode:'manager-file-v1',capture_reference:reference,hotel_url:gallery,capture_gallery_sha256:oneTouchBrowserSha('synthetic-party-gallery'),capture_photo_sha256:JSON.stringify({[photo]:oneTouchBrowserSha(bytes)}),capture_price_declared_at:'2077-01-01T00:00:00Z',capture_gallery_observed_at:'2020-01-01T00:00:00Z',party:adults+'AD',transport:'Перелёт требует проверки',tour:'Synthetic party tour',tariff:'Synthetic party tariff',availability:'Номер по запросу',room:fields.room,meal:fields.meal};
 const source={schema:'onetouch-manager-capture-v1',reference,captured_at:raw.capture_price_declared_at,url,public_controls:{},prices_gzip_base64:'SYNTHETIC_NO_HTTP',prices_sha256:oneTouchBrowserSha('synthetic-party-prices-'+adults)};
 const bundle={schema:'onetouch-bundle-v1',source,gallery_cache:[{hotel:'1001',url:gallery,observed_at:raw.capture_gallery_observed_at,html_gzip_base64:'SYNTHETIC_NO_HTTP',html_sha256:raw.capture_gallery_sha256,images:[{url:photo,sha256:oneTouchBrowserSha(bytes),mime:'image/jpeg',base64:image}]}]};
 function savedOffer(n,id,legacy=false,amount='130000'){
  const original={...copy(fields),adults:n},sourceFields=copy(original);if(legacy){delete sourceFields.adults;delete sourceFields.child_ages}
  return{...copy(original),id,amount,photos:[],import_source:{adapter:'onetouch-public-v1',observed_at:source.captured_at,url:source.url.replace('ADULT='+adults,'ADULT='+n),fields:sourceFields,raw:{...copy(raw),party:n+'AD'},photos:[photo]}};
 }
 const initialOther=options.otherAdults?savedOffer(options.otherAdults,otherId,options.otherLegacy):null,initial=[copy(base),...(initialOther?[copy(initialOther)]:[])],uploadTargets=[];
 let imported=false,savedVersion=null;
 const s=await privateEditor(page,{draft:{title:'Синтетическая подборка с разными составами',intro:'',offers:initial},sourceAction:async(d,store)=>{
  if(d.action==='onetouch_search')return{ok:true,version:store.snapshot().version,offers:[{ticket:'synthetic-party-ticket',offer:copy(fields),raw:copy(raw)}]};
  if(d.action==='onetouch_review')return{ok:true,version:store.snapshot().version,ticket:'synthetic-party-ready',offer:copy(fields),raw:copy(raw),photos:[photo]};
  if(d.action==='onetouch_add'){
   expect(imported).toBe(false);imported=true;const next=store.snapshot().draft;next.offers.push(savedOffer(adults,newId,options.newLegacy,d.manager_amount));store.replaceDraft(next);savedVersion=store.snapshot().version;
   if(options.removeNewAfterAdd){const later=store.snapshot().draft;later.offers=later.offers.filter(offer=>offer.id!==newId);store.replaceDraft(later)}
   return options.lostAdd?null:{ok:true,state:'added',saved_version:savedVersion,count:store.snapshot().draft.offers.length,offer_id:newId};
  }
  if(d.action==='onetouch_operation')return{ok:true,found:imported,saved_version:savedVersion};
  if(d.editor_resource==='tour-selection-photo.php'){
   expect(d.action).toBe('upload');expect(d.source_photo_url).toBe(photo);expect(d.image_sha256).toBe(oneTouchBrowserSha(bytes));uploadTargets.push(d.offer_id);
   return{ok:true,operation_id:d.operation_id,photo:{kind:d.kind,url:'https://app.anytoour.ru/manager/tour-selection-photo.php?conversation_id=11&draft_photo='+'f'.repeat(32)}};
  }
  throw Error('Unexpected synthetic party action '+d.action);
 }});
 await page.route('blob:https://app.anytoour.ru/*',route=>route.continue());
 await page.route('https://app.anytoour.ru/manager/tour-selection-photo.php?**',route=>route.fulfill({contentType:'image/jpeg',body:bytes}));
 const f=s.editor;await expect(f.locator('#tsOneTouch')).toBeVisible();await f.locator('#tsOneTouch').click();
 await f.locator('#tsOneTouchFile').setInputFiles({name:'synthetic-onetouch-party.json',mimeType:'application/json',buffer:Buffer.from(JSON.stringify(bundle))});
 if(Number.isInteger(adults)&&adults>=1&&adults<=14)await f.locator('#tsOneTouchRead').click();else await expect(f.locator('#tsOneTouchRead')).toBeDisabled();if(!options.noReview)await f.getByRole('button',{name:'Проверить фото и условия',exact:true}).click();
 return{...s,f,fields,base,initialOther,newId,otherId,uploadTargets,photoEvents:[],safePhotoText:value=>String(value??'').replace(/blob:[^\s"'<>]+/g,'blob:[local]').slice(0,300)};
}
for(const adults of[1,3,14])test('OneTouch actual adult count '+adults+' preserves literal source amount and composition',async({page,browserName})=>{
 await page.setViewportSize({width:430,height:900});const s=await oneTouchParametricFixture(page,{adults}),f=s.f;await oneTouchBrowserWaitForPhotos(s);
 await expect(f.locator('#tsOneTouchResults')).toContainText((adults===1?'1 взрослый':adults+' взрослых')+' · без детей');
 await expect(f.locator('#tsOneTouchResults')).toContainText('от');await expect(f.locator('#tsOneTouchAmount')).toHaveValue('123456.78');
 if(adults===3){fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await f.locator('#tsOneTouchResults').screenshot({path:path.join(root,'manager2-artifacts',`onetouch-party-three-${browserName}-430.png`)})}
 await f.locator('#tsOneTouchAmount').fill('130000');await f.locator('#tsOneTouchConfirm').check();await f.locator('#tsOneTouchAdd').click();
 await expect.poll(()=>s.snapshot().draft.offers.find(offer=>offer.id===s.newId)?.photos.length).toBe(1);
 expect(s.snapshot().draft.offers[0]).toEqual(s.base);expect(s.snapshot().draft.offers.find(offer=>offer.id===s.newId)).toMatchObject({adults,child_ages:[],amount:'130000',price_qualifier:'from',import_source:{fields:{adults,child_ages:[],amount:'123456.78',price_qualifier:'from'},raw:{party:adults+'AD'}}});
 expect(s.uploadTargets).toEqual([s.newId]);expect(s.calls.filter(c=>c.action==='onetouch_add')).toHaveLength(1);expect(s.calls.some(c=>['send','publish','biblio_add','collector_import'].includes(c.action))).toBe(false);expect(s.unexpected).toEqual([]);expect(s.errors).toEqual([]);
});
test('OneTouch unknown receipt distinguishes equal-price two and three adult source offers',async({page})=>{
 const s=await oneTouchParametricFixture(page,{adults:3,otherAdults:2,lostAdd:true}),f=s.f;await oneTouchBrowserWaitForPhotos(s);
 await f.locator('#tsOneTouchConfirm').check();await f.locator('#tsOneTouchAdd').click();await expect(f.locator('#tsRecovery')).toContainText('Проверить');
 await f.locator('#tsRecovery button').filter({hasText:'Проверить'}).click();await expect.poll(()=>s.snapshot().draft.offers.find(offer=>offer.id===s.newId)?.photos.length).toBe(1);
 expect(s.snapshot().draft.offers.find(offer=>offer.id===s.otherId)).toEqual(s.initialOther);expect(s.uploadTargets).toEqual([s.newId]);expect(s.calls.filter(c=>c.action==='onetouch_add')).toHaveLength(1);expect(s.calls.filter(c=>c.action==='onetouch_operation')).toHaveLength(1);expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);
});
test('OneTouch confirmed receipt with removed target never transfers photos to another party',async({page})=>{
 const s=await oneTouchParametricFixture(page,{adults:3,otherAdults:2,lostAdd:true,removeNewAfterAdd:true}),f=s.f;await oneTouchBrowserWaitForPhotos(s);
 await f.locator('#tsOneTouchConfirm').check();await f.locator('#tsOneTouchAdd').click();await expect(f.locator('#tsRecovery')).toContainText('Проверить');
 await f.locator('#tsRecovery button').filter({hasText:'Проверить'}).click();await expect(f.locator('#tsStatus')).toContainText('привязку фотографий нужно проверить');
 expect(s.snapshot().draft.offers).toEqual([s.base,s.initialOther]);expect(s.uploadTargets).toEqual([]);expect(s.calls.filter(c=>c.action==='onetouch_add')).toHaveLength(1);expect(s.calls.filter(c=>c.action==='onetouch_operation')).toHaveLength(1);expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);
});
test('OneTouch legacy missing source party uses pinned saved party rather than selected offer',async({page})=>{
 const s=await oneTouchParametricFixture(page,{adults:2,baseAdults:3,otherAdults:3,lostAdd:true,newLegacy:true}),f=s.f;await oneTouchBrowserWaitForPhotos(s);
 await f.locator('#tsOneTouchConfirm').check();await f.locator('#tsOneTouchAdd').click();await expect(f.locator('#tsRecovery')).toContainText('Проверить');
 await f.locator('#tsRecovery button').filter({hasText:'Проверить'}).click();await expect.poll(()=>s.snapshot().draft.offers.find(offer=>offer.id===s.newId)?.photos.length).toBe(1);
 expect(s.snapshot().draft.offers[0]).toEqual(s.base);expect(s.snapshot().draft.offers.find(offer=>offer.id===s.otherId)).toEqual(s.initialOther);expect(s.uploadTargets).toEqual([s.newId]);expect(s.calls.filter(c=>c.action==='onetouch_add')).toHaveLength(1);expect(s.calls.filter(c=>c.action==='onetouch_operation')).toHaveLength(1);expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);
});
test('OneTouch rejects response party outside the observed enabled adult set before review',async({page})=>{
 const s=await oneTouchParametricFixture(page,{adults:15,noReview:true});await expect(s.f.getByRole('button',{name:'Проверить фото и условия',exact:true})).toHaveCount(0);
 expect(s.snapshot().draft.offers).toEqual([s.base]);expect(s.calls.some(c=>['onetouch_review','onetouch_add'].includes(c.action))).toBe(false);expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);
});

// New fileless OneTouch path only. All source prices/photos below are synthetic.
async function oneTouchDirectFixture(page,options={}){
 const jpeg=await page.evaluate(()=>{const c=document.createElement('canvas');c.width=160;c.height=120;c.getContext('2d').fillRect(0,0,160,120);return c.toDataURL('image/jpeg').split(',')[1]});
 const photo='https://image.onetouch.travel/images/hotels_hash/100/synthetic/'+'a'.repeat(32)+'.jpg',gallery='https://onetouch.travel/hotels/synthetic-hotel-1001/';
 const base=oneTouchBrowserBaseOffer(),newId='e'.repeat(32),adults=options.adults??3;
 const fields={...copy(base),hotel:'Синтетический OneTouch — прямой поиск',adults,amount:'123456.78',price_qualifier:'from'};
 const raw={party:adults+'AD',hotel_url:gallery,transport:'Перелёт требует проверки',tour:'Синтетический пакет',tariff:'Синтетический тариф',availability:'Номер по запросу',room:fields.room,meal:fields.meal};
 let imported=false,actualUrl='',operation='';
 const s=await privateEditor(page,{draft:{title:'Синтетический прямой поиск',intro:'',offers:[base]},sourceAction:async(d,store)=>{
  expect(d).not.toHaveProperty('photo_mode');expect(d).not.toHaveProperty('photo_expected');
  if(d.action==='onetouch_search'){
   expect(d).not.toHaveProperty('capture');const u=new URL(d.url);expect(u.origin).toBe('https://b2b.onetouch.travel');expect(u.pathname).toBe('/search_tour');
   expect(u.searchParams.get('ADULT')).toBe(String(adults));expect(u.searchParams.get('CHILD')).toBe('0');expect(u.searchParams.has('AGES')).toBe(false);expect(u.searchParams.get('PRICEPAGE')).toBe('1');expect(u.searchParams.get('DYN_SEPARATE')).toBe('1');
   expect(u.searchParams.get('CHECKIN_BEG')).toBe('20770107');expect(u.searchParams.get('NIGHTS_FROM')).toBe('7');
   u.searchParams.set('rev','3187520523');actualUrl=u.href;
   if(options.unavailable)return{ok:false,error:'onetouch_source_unavailable'};
   const offer=copy(fields);if(options.wrongParty)offer.adults=2;
   return{ok:true,version:store.snapshot().version,offers:[{ticket:'synthetic-direct-quote',offer,raw:copy(raw)}]};
  }
  if(d.action==='onetouch_review'){
   expect(d.ticket).toBe('synthetic-direct-quote');expect(d).not.toHaveProperty('gallery_gzip_base64');
   const offer=copy(fields);if(options.changedPrice)offer.amount='200000';
   return{ok:true,version:store.snapshot().version,ticket:'synthetic-direct-ready',offer,raw:copy(raw),photos:[photo],source_reference:{quote_id:'0x'+'a'.repeat(64),url:actualUrl}};
  }
  if(d.action==='onetouch_add'){
   expect(imported).toBe(false);expect(d.ticket).toBe('synthetic-direct-ready');expect(d.confirm_price_from).toBe(true);imported=true;operation=d.operation_id;
   const savedUrl=new URL(actualUrl);if(options.oldOrigin)savedUrl.searchParams.set('rev','965964269');
   const draft=store.snapshot().draft;draft.offers.push({...copy(fields),id:newId,amount:d.manager_amount,photos:[{url:photo,kind:'cover',illustrative:true}],import_source:{adapter:'onetouch-public-v1',quote_id:'0x'+'a'.repeat(64),url:savedUrl.href,observed_at:'2077-01-01T00:00:00Z',fields:copy(fields),raw:copy(raw),photos:[photo]}});if(options.ambiguousRecovery)draft.offers.push({...copy(draft.offers[1]),id:'f'.repeat(32)});store.replaceDraft(draft);
   return options.lostAdd?null:{ok:true,state:options.oldOrigin?'already_added':'added',offer_id:newId,saved_version:store.snapshot().version,count:2};
  }
  if(d.action==='onetouch_operation'){expect(d.operation_id).toBe(operation);return{ok:true,found:imported,saved_version:store.snapshot().version};}
  throw Error('Unexpected direct OneTouch action '+d.action);
 }});
 await page.route('https://image.onetouch.travel/**',route=>options.brokenPhoto?route.abort('failed'):route.fulfill({contentType:'image/jpeg',body:Buffer.from(jpeg,'base64')}));
 const f=s.editor;await expect(f.locator('#tsStatus')).toContainText('загружен');await f.locator('#tsOneTouch').click();
 await expect(f.locator('#tsOneTouchDate')).toBeEnabled();await expect(f.locator('#tsOneTouchNights')).toBeEnabled();await expect(f.locator('#tsOneTouchAdults')).toBeEnabled();
 await f.locator('#tsOneTouchDate').fill('2077-01-07');await f.locator('#tsOneTouchNights').fill('7');await f.locator('#tsOneTouchAdults').fill(String(adults));
 return{...s,f,base,fields,raw,photo,newId,actualUrl:()=>actualUrl};
}
async function oneTouchDirectReadReview(s){await s.f.locator('#tsOneTouchRead').click();await s.f.getByRole('button',{name:'Проверить фото и условия',exact:true}).click();}
test('OneTouch fileless search review and direct photo import retain old selection',async({page,browserName})=>{
 await page.setViewportSize({width:430,height:932});const s=await oneTouchDirectFixture(page),f=s.f;await oneTouchDirectReadReview(s);
 await expect(f.locator('#tsOneTouchPhotoStatus')).toHaveText('Фотографии загружены.');await expect(f.locator('#tsOneTouchResults')).toContainText('3 взрослых · без детей');await expect(f.locator('#tsOneTouchResults')).toContainText('от 123');
 await f.locator('#tsOneTouchConfirm').check();await expect(f.locator('#tsOneTouchAdd')).toBeEnabled();
 const frame=page.frames().find(frame=>frame.url().includes('/manager/tour-selection-editor.php'));expect(await frame.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);
 for(const id of['tsOneTouchDate','tsOneTouchNights','tsOneTouchAdults','tsOneTouchRead','tsOneTouchAdd'])expect((await f.locator('#'+id).boundingBox()).height).toBeGreaterThanOrEqual(44);
 fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await f.locator('#tsOneTouchResults').scrollIntoViewIfNeeded();await page.screenshot({path:path.join(root,'manager2-artifacts',`onetouch-direct-review-${browserName}-430.png`),fullPage:true,animations:'disabled'});
 await f.locator('#tsOneTouchAmount').fill('135000');await f.locator('#tsOneTouchAdd').click();await expect.poll(()=>s.snapshot().draft.offers.length).toBe(2);
 const saved=s.snapshot().draft.offers[1];expect(s.snapshot().draft.offers[0]).toEqual(s.base);expect(saved.photos).toEqual([{url:s.photo,kind:'cover',illustrative:true}]);expect(saved.amount).toBe('135000');expect(saved.import_source.fields.amount).toBe('123456.78');expect(saved.import_source.raw.party).toBe('3AD');expect(new URL(saved.import_source.url).searchParams.get('rev')).toBe('3187520523');
 await expect(f.locator('#tsStatus')).not.toContainText('Завершите перенос фотографий');expect(s.calls.filter(c=>c.action==='onetouch_add')).toHaveLength(1);expect(s.calls.some(c=>c.editor_resource==='tour-selection-photo.php'||['save','send','publish','collector_import'].includes(c.action))).toBe(false);expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);
});
for(const adults of[1,14])test('OneTouch direct form retains enabled adult boundary '+adults,async({page})=>{
 const s=await oneTouchDirectFixture(page,{adults});await oneTouchDirectReadReview(s);await expect(s.f.locator('#tsOneTouchPhotoStatus')).toHaveText('Фотографии загружены.');await expect(s.f.locator('#tsOneTouchResults')).toContainText(adults===1?'1 взрослый · без детей':'14 взрослых · без детей');expect(s.snapshot().draft.offers).toEqual([s.base]);expect(s.calls.some(c=>c.action==='onetouch_add')).toBe(false);expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);
});
test('OneTouch direct invalid party stops before any source request',async({page})=>{
 const s=await oneTouchDirectFixture(page);await s.f.locator('#tsOneTouchAdults').fill('15');await expect(s.f.locator('#tsOneTouchRead')).toBeDisabled();expect(s.calls.some(c=>c.action.startsWith('onetouch_'))).toBe(false);expect(s.snapshot().draft.offers).toEqual([s.base]);expect(s.errors).toEqual([]);
});
test('OneTouch rejected file requires explicit return to direct search and never silently falls back',async({page})=>{
 const s=await oneTouchDirectFixture(page),f=s.f;await f.locator('#tsOneTouchFile').setInputFiles({name:'invalid-synthetic.json',mimeType:'application/json',buffer:Buffer.from('{')});await expect(f.locator('#tsOneTouchStatus')).not.toBeEmpty();await expect(f.locator('#tsOneTouchRead')).toBeDisabled();expect(s.calls.some(c=>c.action.startsWith('onetouch_'))).toBe(false);
 await f.locator('#tsOneTouchDirect').click();await expect(f.locator('#tsOneTouchRead')).toBeEnabled();await f.locator('#tsOneTouchRead').click();await expect(f.getByRole('button',{name:'Проверить фото и условия',exact:true})).toHaveCount(1);expect(s.calls.filter(c=>c.action==='onetouch_search')).toHaveLength(1);expect(s.snapshot().draft.offers).toEqual([s.base]);expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);
});
test('OneTouch direct response cannot silently change requested party',async({page})=>{
 const s=await oneTouchDirectFixture(page,{wrongParty:true});await s.f.locator('#tsOneTouchRead').click();await expect(s.f.locator('#tsOneTouchStatus')).toContainText('изменились');await expect(s.f.getByRole('button',{name:'Проверить фото и условия',exact:true})).toHaveCount(0);expect(s.calls.filter(c=>c.action==='onetouch_search')).toHaveLength(1);expect(s.calls.some(c=>['onetouch_review','onetouch_add'].includes(c.action))).toBe(false);expect(s.snapshot().draft.offers).toEqual([s.base]);expect(s.errors).toEqual([]);
});
test('OneTouch direct source failure leaves saved offers and makes no alternate read',async({page})=>{
 const s=await oneTouchDirectFixture(page,{unavailable:true});await s.f.locator('#tsOneTouchRead').click();await expect(s.f.locator('#tsOneTouchStatus')).toContainText('не ответил');expect(s.calls.filter(c=>c.action==='onetouch_search')).toHaveLength(1);expect(s.calls.some(c=>['onetouch_review','onetouch_add','collector_import'].includes(c.action))).toBe(false);expect(s.snapshot().draft.offers).toEqual([s.base]);expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);
});
test('OneTouch direct review cannot replace original minimum price',async({page})=>{
 const s=await oneTouchDirectFixture(page,{changedPrice:true});await oneTouchDirectReadReview(s);await expect(s.f.locator('#tsOneTouchStatus')).toContainText('Не удалось подтвердить фотографии');await expect(s.f.locator('#tsOneTouchAdd')).toHaveCount(0);expect(s.calls.some(c=>c.action==='onetouch_add')).toBe(false);expect(s.snapshot().draft.offers).toEqual([s.base]);expect(s.errors).toEqual([]);
});
test('OneTouch direct failed image blocks adding without a file upload fallback',async({page})=>{
 const s=await oneTouchDirectFixture(page,{brokenPhoto:true});await oneTouchDirectReadReview(s);await s.f.locator('#tsOneTouchConfirm').check();await expect(s.f.locator('#tsOneTouchAdd')).toBeDisabled();expect(s.calls.some(c=>c.action==='onetouch_add'||c.editor_resource==='tour-selection-photo.php')).toBe(false);expect(s.snapshot().draft.offers).toEqual([s.base]);expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);
});
test('OneTouch direct lost receipt survives old local cache and reconciles without add replay',async({page})=>{
 const s=await oneTouchDirectFixture(page,{lostAdd:true}),f=s.f;await oneTouchDirectReadReview(s);await expect(f.locator('#tsOneTouchPhotoStatus')).toHaveText('Фотографии загружены.');await f.locator('#tsOneTouchConfirm').check();await f.locator('#tsOneTouchAdd').click();await expect(f.locator('#tsRecovery')).toContainText('Проверить');
 const frame=page.frames().find(frame=>frame.url().includes('/manager/tour-selection-editor.php'));const changed=await frame.evaluate(()=>{let found=0;for(let i=0;i<sessionStorage.length;i++){const key=sessionStorage.key(i);let v;try{v=JSON.parse(sessionStorage.getItem(key))}catch{continue}if(v?.pending?.kind==='onetouch'){v.at=Date.now()-25*3600000;sessionStorage.setItem(key,JSON.stringify(v));found++}}return found});expect(changed).toBe(1);
 await page.reload();await expect(f.locator('#tsRecovery')).toContainText('Проверить');await f.locator('#tsRecovery button').filter({hasText:'Проверить'}).click();await expect(f.locator('#tsStatus')).toContainText('Сохранённая операция OneTouch подтверждена');await expect(f.locator('#tsStatus')).not.toContainText('фотографий нужно проверить');expect(s.snapshot().draft.offers[0]).toEqual(s.base);expect(s.snapshot().draft.offers[1].photos).toEqual([{url:s.photo,kind:'cover',illustrative:true}]);expect(s.calls.filter(c=>c.action==='onetouch_add')).toHaveLength(1);expect(s.calls.filter(c=>c.action==='onetouch_operation')).toHaveLength(1);expect(s.calls.some(c=>c.editor_resource==='tour-selection-photo.php')).toBe(false);expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);
});
test('OneTouch direct idless receipt never guesses between ambiguous canonical photo offers',async({page})=>{
 const s=await oneTouchDirectFixture(page,{lostAdd:true,ambiguousRecovery:true}),f=s.f;await oneTouchDirectReadReview(s);await expect(f.locator('#tsOneTouchPhotoStatus')).toHaveText('Фотографии загружены.');await f.locator('#tsOneTouchConfirm').check();await f.locator('#tsOneTouchAdd').click();await expect(f.locator('#tsRecovery')).toContainText('Проверить');await f.locator('#tsRecovery button').filter({hasText:'Проверить'}).click();await expect(f.locator('#tsStatus')).toContainText('привязку фотографий нужно проверить');expect(s.snapshot().draft.offers).toHaveLength(3);expect(s.snapshot().draft.offers[0]).toEqual(s.base);expect(s.calls.filter(c=>c.action==='onetouch_add')).toHaveLength(1);expect(s.calls.filter(c=>c.action==='onetouch_operation')).toHaveLength(1);expect(s.calls.some(c=>c.editor_resource==='tour-selection-photo.php')).toBe(false);expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);
});
for(const lostAdd of[true,false])test('OneTouch direct receipt keeps a different valid source URL unresolved '+(lostAdd?'idless':'known id'),async({page})=>{
 const s=await oneTouchDirectFixture(page,{lostAdd,oldOrigin:true}),f=s.f;await oneTouchDirectReadReview(s);await expect(f.locator('#tsOneTouchPhotoStatus')).toHaveText('Фотографии загружены.');await f.locator('#tsOneTouchConfirm').check();await f.locator('#tsOneTouchAdd').click();
 if(lostAdd){await expect(f.locator('#tsRecovery')).toContainText('Проверить');await f.locator('#tsRecovery button').filter({hasText:'Проверить'}).click();await expect(f.locator('#tsStatus')).toContainText('привязку фотографий нужно проверить');}else await expect(f.locator('#tsStatus')).toContainText('Привязку исходного предложения');
 await expect(f.locator('#tsStatus')).not.toContainText('Завершите перенос');expect(new URL(s.snapshot().draft.offers[1].import_source.url).searchParams.get('rev')).toBe('965964269');expect(new URL(s.actualUrl()).searchParams.get('rev')).toBe('3187520523');expect(s.snapshot().draft.offers[0]).toEqual(s.base);expect(s.calls.filter(c=>c.action==='onetouch_add')).toHaveLength(1);expect(s.calls.filter(c=>c.action==='onetouch_operation')).toHaveLength(lostAdd?1:0);expect(s.calls.some(c=>c.editor_resource==='tour-selection-photo.php')).toBe(false);expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);
});

async function oneTouchFamilyFixture(page,options={}){
 const jpeg=await page.evaluate(()=>{const c=document.createElement('canvas');c.width=160;c.height=120;c.getContext('2d').fillRect(0,0,160,120);return c.toDataURL('image/jpeg').split(',')[1]});
 const photo='https://image.onetouch.travel/images/hotels_hash/100/synthetic/'+'a'.repeat(32)+'.jpg',gallery='https://onetouch.travel/hotels/synthetic-hotel-1001/';
 const base=oneTouchBrowserBaseOffer(),newId='e'.repeat(32),adults=2,ages=options.ages??[8],party='2AD+'+ages.length+'CH('+ages.join(',')+')';
 const fields={...copy(base),hotel:'Синтетический OneTouch — семейный поиск',adults,child_ages:[...ages],room:'STANDARD ROOM / 2 ADL + '+ages.length+' CHD(0-17)',amount:'123456.78',price_qualifier:'from'};
 const raw={party,hotel_url:gallery,transport:'Перелёт требует проверки',tour:'Синтетический пакет',tariff:'Синтетический тариф',availability:'Номер по запросу',room:fields.room,meal:fields.meal};
 let imported=false,actualUrl='',operation='';
 const s=await privateEditor(page,{draft:{title:'Синтетический прямой поиск',intro:'',offers:[base]},sourceAction:async(d,store)=>{
  expect(d).not.toHaveProperty('photo_mode');expect(d).not.toHaveProperty('photo_expected');
  if(d.action==='onetouch_search'){
   expect(d).not.toHaveProperty('capture');const u=new URL(d.url);expect(u.origin).toBe('https://b2b.onetouch.travel');expect(u.pathname).toBe('/search_tour');
   expect(u.searchParams.get('ADULT')).toBe(String(adults));expect(u.searchParams.get('CHILD')).toBe(String(ages.length));expect(u.searchParams.get('AGES')).toBe(ages.join(','));expect(u.searchParams.get('PRICEPAGE')).toBe('1');expect(u.searchParams.get('DYN_SEPARATE')).toBe('1');
   expect(u.searchParams.get('CHECKIN_BEG')).toBe('20770107');expect(u.searchParams.get('NIGHTS_FROM')).toBe('7');
   u.searchParams.set('rev','3187520523');actualUrl=u.href;
   if(options.unavailable)return{ok:false,error:'onetouch_source_unavailable'};
   const offer=copy(fields);if(options.wrongAge)offer.child_ages=[9];
   return{ok:true,version:store.snapshot().version,offers:[{ticket:'synthetic-direct-quote',offer,raw:copy(raw)}]};
  }
  if(d.action==='onetouch_review'){
   expect(d.ticket).toBe('synthetic-direct-quote');expect(d).not.toHaveProperty('gallery_gzip_base64');
   const offer=copy(fields);if(options.wrongReviewAge)offer.child_ages=[9];
   return{ok:true,version:store.snapshot().version,ticket:'synthetic-direct-ready',offer,raw:copy(raw),photos:[photo],source_reference:{quote_id:'0x'+'a'.repeat(64),url:actualUrl}};
  }
  if(d.action==='onetouch_add'){
   expect(imported).toBe(false);expect(d.ticket).toBe('synthetic-direct-ready');expect(d.confirm_price_from).toBe(true);imported=true;operation=d.operation_id;
   const savedUrl=new URL(actualUrl);if(options.oldOrigin)savedUrl.searchParams.set('rev','965964269');
   const draft=store.snapshot().draft;draft.offers.push({...copy(fields),id:newId,amount:d.manager_amount,photos:[{url:photo,kind:'cover',illustrative:true}],import_source:{adapter:'onetouch-public-v1',quote_id:'0x'+'a'.repeat(64),url:savedUrl.href,observed_at:'2077-01-01T00:00:00Z',fields:copy(fields),raw:copy(raw),photos:[photo]}});if(options.ambiguousRecovery)draft.offers.push({...copy(draft.offers[1]),id:'f'.repeat(32)});if(options.wrongSavedAge){const changed=draft.offers[1];changed.child_ages=[9];changed.import_source.fields.child_ages=[9];changed.import_source.raw.party='2AD+1CH(9)';const changedUrl=new URL(changed.import_source.url);changedUrl.searchParams.set('AGES','9');changed.import_source.url=changedUrl.href;}store.replaceDraft(draft);
   return options.lostAdd?null:{ok:true,state:options.oldOrigin?'already_added':'added',offer_id:newId,saved_version:store.snapshot().version,count:2};
  }
  if(d.action==='onetouch_operation'){expect(d.operation_id).toBe(operation);return{ok:true,found:imported,saved_version:store.snapshot().version};}
  throw Error('Unexpected direct OneTouch action '+d.action);
 }});
 await page.route('https://image.onetouch.travel/**',route=>options.brokenPhoto?route.abort('failed'):route.fulfill({contentType:'image/jpeg',body:Buffer.from(jpeg,'base64')}));
 const f=s.editor;await expect(f.locator('#tsStatus')).toContainText('загружен');await f.locator('#tsOneTouch').click();
 await expect(f.locator('#tsOneTouchDate')).toBeEnabled();await expect(f.locator('#tsOneTouchNights')).toBeEnabled();await expect(f.locator('#tsOneTouchAdults')).toBeEnabled();
 await f.locator('#tsOneTouchDate').fill('2077-01-07');await f.locator('#tsOneTouchNights').fill('7');await f.locator('#tsOneTouchAdults').fill(String(adults));
 await f.locator('#tsOneTouchChildren').fill(String(ages.length));for(let i=0;i<ages.length;i++)await f.locator('#tsOneTouchAge'+i).fill(String(ages[i]));
 return{...s,f,base,fields,raw,photo,newId,actualUrl:()=>actualUrl};
}

async function oneTouchFamilyFileFixture(page,options={}){
 const adults=2,ages=options.ages??[8,8],photo='https://image.onetouch.travel/synthetic/party-cover.jpg',gallery='https://onetouch.travel/hotels/synthetic-hotel-1001/',reference='SYNTHETIC_SHARED_PARTY_REFERENCE',newId='d'.repeat(32),otherId='e'.repeat(32);
 const image=await page.evaluate(()=>{const canvas=document.createElement('canvas');canvas.width=160;canvas.height=120;canvas.getContext('2d').fillRect(0,0,160,120);return canvas.toDataURL('image/jpeg').split(',')[1]}),bytes=Buffer.from(image,'base64');
 const base={...oneTouchBrowserBaseOffer(),adults:options.baseAdults??2},adultUrl="https://b2b.onetouch.travel/search_tour?samo_action=PRICES&TOWNFROMINC=2&STATEINC=9&TOURTYPE=0&TOURINC=0&PROGRAMGROUPINC=0&PROGRAMINC=0&CHECKIN_BEG=20770107&NIGHTS_FROM=7&CHECKIN_END=20770107&NIGHTS_TILL=7&ADULT="+adults+"&CURRENCY=1&CHILD=0&TOWNS_ANY=1&townssearch=0&TOWNS=&STARS_ANY=1&STARS=&HOTELS_ANY=1&hotelsearch=0&HOTELS=&MEALS_ANY=1&MEALS=&ROOMS_ANY=1&ROOMS=&CHILD_IN_BED=0&FREIGHT=1&COMFORTABLE_SEATS=0&FILTER=1&MOMENT_CONFIRM=0&WITHOUT_PROMO=0&HOTELTYPES=&PARTITION_PRICE=&PRICEPAGE=1&DYN_SEPARATE=1&rev=965964269";
 const nativeUrl=new URL(adultUrl);nativeUrl.searchParams.set('CHILD',String(ages.length));nativeUrl.searchParams.set('AGES',ages.join(','));const url=nativeUrl.href;
 const fields={...copy(base),hotel:'Синтетический OneTouch · состав из источника',adults,child_ages:[...ages],room:'STANDARD ROOM / 2 ADL + '+ages.length+' CHD(0-17)',amount:'123456.78',price_qualifier:'from',source_label:'OneTouch · synthetic',source_url:url};
 const raw={capture_mode:'manager-file-v1',capture_reference:reference,hotel_url:gallery,capture_gallery_sha256:oneTouchBrowserSha('synthetic-party-gallery'),capture_photo_sha256:JSON.stringify({[photo]:oneTouchBrowserSha(bytes)}),capture_price_declared_at:'2077-01-01T00:00:00Z',capture_gallery_observed_at:'2020-01-01T00:00:00Z',party:adults+'AD+'+ages.length+'CH('+ages.join(',')+')',transport:'Перелёт требует проверки',tour:'Synthetic party tour',tariff:'Synthetic party tariff',availability:'Номер по запросу',room:fields.room,meal:fields.meal};
 const source={schema:'onetouch-manager-capture-v1',reference,captured_at:raw.capture_price_declared_at,url,public_controls:{},prices_gzip_base64:'SYNTHETIC_NO_HTTP',prices_sha256:oneTouchBrowserSha('synthetic-party-prices-'+adults)};
 const bundle={schema:'onetouch-bundle-v1',source,gallery_cache:[{hotel:'1001',url:gallery,observed_at:raw.capture_gallery_observed_at,html_gzip_base64:'SYNTHETIC_NO_HTTP',html_sha256:raw.capture_gallery_sha256,images:[{url:photo,sha256:oneTouchBrowserSha(bytes),mime:'image/jpeg',base64:image}]}]};
 function savedOffer(n,id,legacy=false,amount='130000'){
  const original={...copy(fields),adults:n},sourceFields=copy(original);if(legacy){delete sourceFields.adults;delete sourceFields.child_ages}
  return{...copy(original),id,amount,photos:[],import_source:{adapter:'onetouch-public-v1',observed_at:source.captured_at,url:source.url.replace('ADULT='+adults,'ADULT='+n),fields:sourceFields,raw:{...copy(raw),party:n+'AD+'+ages.length+'CH('+ages.join(',')+')'},photos:[photo]}};
 }
 const initialOther=options.otherAdults?savedOffer(options.otherAdults,otherId,options.otherLegacy):null,initial=[copy(base),...(initialOther?[copy(initialOther)]:[])],uploadTargets=[];
 let imported=false,savedVersion=null;
 const s=await privateEditor(page,{draft:{title:'Синтетическая подборка с разными составами',intro:'',offers:initial},sourceAction:async(d,store)=>{
  if(d.action==='onetouch_search')return{ok:true,version:store.snapshot().version,offers:[{ticket:'synthetic-party-ticket',offer:copy(fields),raw:copy(raw)}]};
  if(d.action==='onetouch_review')return{ok:true,version:store.snapshot().version,ticket:'synthetic-party-ready',offer:copy(fields),raw:copy(raw),photos:[photo]};
  if(d.action==='onetouch_add'){
   expect(imported).toBe(false);imported=true;const next=store.snapshot().draft;next.offers.push(savedOffer(adults,newId,options.newLegacy,d.manager_amount));store.replaceDraft(next);savedVersion=store.snapshot().version;
   if(options.removeNewAfterAdd){const later=store.snapshot().draft;later.offers=later.offers.filter(offer=>offer.id!==newId);store.replaceDraft(later)}
   return options.lostAdd?null:{ok:true,state:'added',saved_version:savedVersion,count:store.snapshot().draft.offers.length,offer_id:newId};
  }
  if(d.action==='onetouch_operation')return{ok:true,found:imported,saved_version:savedVersion};
  if(d.editor_resource==='tour-selection-photo.php'){
   expect(d.action).toBe('upload');expect(d.source_photo_url).toBe(photo);expect(d.image_sha256).toBe(oneTouchBrowserSha(bytes));uploadTargets.push(d.offer_id);
   return{ok:true,operation_id:d.operation_id,photo:{kind:d.kind,url:'https://app.anytoour.ru/manager/tour-selection-photo.php?conversation_id=11&draft_photo='+'f'.repeat(32)}};
  }
  throw Error('Unexpected synthetic party action '+d.action);
 }});
 await page.route('blob:https://app.anytoour.ru/*',route=>route.continue());
 await page.route('https://app.anytoour.ru/manager/tour-selection-photo.php?**',route=>route.fulfill({contentType:'image/jpeg',body:bytes}));
 const f=s.editor;await expect(f.locator('#tsOneTouch')).toBeVisible();await f.locator('#tsOneTouch').click();
 await f.locator('#tsOneTouchFile').setInputFiles({name:'synthetic-onetouch-party.json',mimeType:'application/json',buffer:Buffer.from(JSON.stringify(bundle))});
 if(Number.isInteger(adults)&&adults>=1&&adults<=14)await f.locator('#tsOneTouchRead').click();else await expect(f.locator('#tsOneTouchRead')).toBeDisabled();if(!options.noReview)await f.getByRole('button',{name:'Проверить фото и условия',exact:true}).click();
 return{...s,f,fields,base,initialOther,newId,otherId,uploadTargets,photoEvents:[],safePhotoText:value=>String(value??'').replace(/blob:[^\s"'<>]+/g,'blob:[local]').slice(0,300)};
}

// Family-only new scenarios. Synthetic API/photos; no real provider or selection.
test('OneTouch family selected file keeps declared ages and file provenance without direct fallback',async({page})=>{
 const s=await oneTouchFamilyFileFixture(page,{ages:[8,8]}),f=s.f;await oneTouchBrowserWaitForPhotos(s);await expect(f.locator('#tsOneTouchChildren')).toHaveValue('2');await expect(f.locator('#tsOneTouchChildren')).toBeDisabled();await expect(f.locator('#tsOneTouchAge0')).toHaveValue('8');await expect(f.locator('#tsOneTouchAge1')).toHaveValue('8');await expect(f.locator('#tsOneTouchResults')).toContainText('заявленных параметров поиска в файле');
 await f.locator('#tsOneTouchConfirm').check();await f.locator('#tsOneTouchAdd').click();await expect.poll(()=>s.snapshot().draft.offers.find(offer=>offer.id===s.newId)?.photos.length).toBe(1);expect(s.snapshot().draft.offers[0]).toEqual(s.base);expect(s.snapshot().draft.offers.find(offer=>offer.id===s.newId)).toMatchObject({child_ages:[8,8],import_source:{fields:{child_ages:[8,8]},raw:{capture_mode:'manager-file-v1',party:'2AD+2CH(8,8)'}}});expect(s.calls.filter(c=>c.action==='onetouch_search').every(c=>!!c.capture&&!c.url)).toBe(true);expect(s.uploadTargets).toEqual([s.newId]);expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);
});
test('OneTouch family file cannot silently replace requested ages with missing values',async({page})=>{
 const s=await oneTouchFamilyFixture(page,{ages:[8]}),f=s.f;const badUrl=new URL('https://b2b.onetouch.travel/search_tour?ADULT=2&CHILD=1');await f.locator('#tsOneTouchFile').setInputFiles({name:'invalid-family.json',mimeType:'application/json',buffer:Buffer.from(JSON.stringify({schema:'onetouch-bundle-v1',source:{schema:'onetouch-manager-capture-v1',url:badUrl.href},gallery_cache:[]}))});await expect(f.locator('#tsOneTouchRead')).toBeDisabled();expect(s.calls.some(c=>c.action.startsWith('onetouch_'))).toBe(false);expect(s.snapshot().draft.offers).toEqual([s.base]);expect(s.errors).toEqual([]);
});
for(const ages of[[0],[8],[17],[8,8],[0,8,17]])test('OneTouch family exact ages '+ages.join(',')+' retain whole-party minimum and saved source',async({page,browserName})=>{
 await page.setViewportSize({width:430,height:932});const s=await oneTouchFamilyFixture(page,{ages}),f=s.f;await oneTouchDirectReadReview(s);
 await expect(f.locator('#tsOneTouchPhotoStatus')).toHaveText('Фотографии загружены.');await expect(f.locator('#tsOneTouchResults')).toContainText('Возраст детей — из параметров поиска.');await expect(f.locator('#tsOneTouchResults')).toContainText('CHD(0-17)');await expect(f.locator('#tsOneTouchAmount')).toHaveValue('123456.78');
 for(let i=0;i<ages.length;i++)await expect(f.locator('#tsOneTouchAge'+i)).toHaveValue(String(ages[i]));await f.locator('#tsOneTouchConfirm').check();await expect(f.locator('#tsOneTouchAdd')).toBeEnabled();
 if(ages.length===2){const frame=page.frames().find(frame=>frame.url().includes('/manager/tour-selection-editor.php'));expect(await frame.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);for(const id of['tsOneTouchChildren','tsOneTouchAge0','tsOneTouchAge1','tsOneTouchAdd'])expect((await f.locator('#'+id).boundingBox()).height).toBeGreaterThanOrEqual(44);fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({path:path.join(root,'manager2-artifacts',`onetouch-family-twins-${browserName}-430.png`),fullPage:true,animations:'disabled'})}
 await f.locator('#tsOneTouchAmount').fill('145000');await f.locator('#tsOneTouchAdd').click();await expect.poll(()=>s.snapshot().draft.offers.length).toBe(2);
 expect(s.snapshot().draft.offers[0]).toEqual(s.base);expect(s.snapshot().draft.offers[1]).toMatchObject({child_ages:ages,amount:'145000',price_qualifier:'from',import_source:{fields:{child_ages:ages,amount:'123456.78'},raw:{party:'2AD+'+ages.length+'CH('+ages.join(',')+')'}}});expect(s.snapshot().draft.offers[1].photos).toEqual([{url:s.photo,kind:'cover',illustrative:true}]);expect(s.calls.filter(c=>c.action==='onetouch_add')).toHaveLength(1);expect(s.calls.some(c=>['save','send','publish','collector_import','biblio_add'].includes(c.action))).toBe(false);expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);
});
test('OneTouch family blank age never becomes infant zero and count changes discard review',async({page})=>{
 const s=await oneTouchFamilyFixture(page,{ages:[8]}),f=s.f;await f.locator('#tsOneTouchAge0').fill('');await expect(f.locator('#tsOneTouchRead')).toBeDisabled();expect(s.calls.some(c=>c.action==='onetouch_search')).toBe(false);
 await f.locator('#tsOneTouchAge0').fill('8');await oneTouchDirectReadReview(s);await expect(f.locator('#tsOneTouchPhotoStatus')).toHaveText('Фотографии загружены.');await f.locator('#tsOneTouchConfirm').check();
 await f.locator('#tsOneTouchAge0').fill('9');await expect(f.locator('#tsOneTouchResults')).toBeEmpty();await expect(f.locator('#tsOneTouchConfirm')).toHaveCount(0);await expect(f.locator('#tsOneTouchStatus')).toContainText('заново');
 await f.locator('#tsOneTouchChildren').fill('2');await expect(f.locator('#tsOneTouchAge0')).toHaveValue('9');await expect(f.locator('#tsOneTouchAge1')).toHaveValue('');await expect(f.locator('#tsOneTouchRead')).toBeDisabled();
 await f.locator('#tsOneTouchChildren').fill('0');await expect(f.locator('#tsOneTouchAge0')).toHaveCount(0);await expect(f.locator('#tsOneTouchRead')).toBeEnabled();expect(s.snapshot().draft.offers).toEqual([s.base]);expect(s.calls.some(c=>c.action==='onetouch_add')).toBe(false);expect(s.errors).toEqual([]);
});
test('OneTouch family response with another child age stops before review',async({page})=>{
 const s=await oneTouchFamilyFixture(page,{ages:[8],wrongAge:true});await s.f.locator('#tsOneTouchRead').click();await expect(s.f.locator('#tsOneTouchStatus')).toContainText('изменились');await expect(s.f.getByRole('button',{name:'Проверить фото и условия',exact:true})).toHaveCount(0);expect(s.calls.some(c=>['onetouch_review','onetouch_add'].includes(c.action))).toBe(false);expect(s.snapshot().draft.offers).toEqual([s.base]);expect(s.errors).toEqual([]);
});
test('OneTouch family review cannot replace age with equal-price same-claim offer',async({page})=>{
 const s=await oneTouchFamilyFixture(page,{ages:[8],wrongReviewAge:true});await oneTouchDirectReadReview(s);await expect(s.f.locator('#tsOneTouchStatus')).toContainText('Не удалось подтвердить фотографии');await expect(s.f.locator('#tsOneTouchAdd')).toHaveCount(0);expect(s.calls.some(c=>c.action==='onetouch_add')).toBe(false);expect(s.snapshot().draft.offers).toEqual([s.base]);expect(s.errors).toEqual([]);
});
for(const wrongSavedAge of[false,true])test('OneTouch family lost receipt keeps original requested ages without import replay '+wrongSavedAge,async({page})=>{
 const s=await oneTouchFamilyFixture(page,{ages:[8],lostAdd:true,wrongSavedAge}),f=s.f;await oneTouchDirectReadReview(s);await expect(f.locator('#tsOneTouchPhotoStatus')).toHaveText('Фотографии загружены.');await f.locator('#tsOneTouchConfirm').check();await f.locator('#tsOneTouchAdd').click();await expect(f.locator('#tsRecovery')).toContainText('Проверить');
 await page.reload();await expect(f.locator('#tsRecovery')).toContainText('Проверить');await f.locator('#tsRecovery button').filter({hasText:'Проверить'}).click();await expect(f.locator('#tsStatus')).toContainText(wrongSavedAge?'привязку фотографий нужно проверить':'Сохранённая операция OneTouch подтверждена');
 expect(s.snapshot().draft.offers[0]).toEqual(s.base);expect(s.snapshot().draft.offers[1].child_ages).toEqual(wrongSavedAge?[9]:[8]);expect(s.calls.filter(c=>c.action==='onetouch_add')).toHaveLength(1);expect(s.calls.filter(c=>c.action==='onetouch_operation')).toHaveLength(1);expect(s.calls.some(c=>c.editor_resource==='tour-selection-photo.php')).toBe(false);expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);
});
