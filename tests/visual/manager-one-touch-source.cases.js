const {test,expect}=require('@playwright/test');
const {privateEditor}=require('./fixtures/selection-private-editor');
const {createHash}=require('node:crypto');
const fs=require('node:fs'),path=require('node:path'),root=path.resolve(__dirname,'../..');
const copy=x=>JSON.parse(JSON.stringify(x));
const sha=x=>createHash('sha256').update(x).digest('hex');
function baseOffer(){return{id:'b'.repeat(32),selected:true,hotel:'Синтетический сохранённый Библио-Глобус',stars:3,destination:'Турция',departure:'Москва',date_from:'2077-01-07',nights:7,adults:2,child_ages:[],room:'Standard',meal:'BB',amount:'180000',currency:'RUB',rationale:'',compromise:'',flights:'',inclusions:'',constraints:'',source_label:'Библио-Глобус · synthetic',source_url:'',observed_at:null,photos:[]}}
async function sourceFixture(page,options={}){
 const image=await page.evaluate(()=>{const c=document.createElement('canvas');c.width=160;c.height=120;c.getContext('2d').fillRect(0,0,160,120);return c.toDataURL('image/jpeg').split(',')[1]});
 const bytes=Buffer.from(image,'base64'),photo='https://image.onetouch.travel/synthetic/cover.jpg',gallery='https://onetouch.travel/hotels/synthetic-hotel-1001/',reference='SYNTHETIC_BROWSER_ONLY';
 const fields={...baseOffer(),hotel:'Синтетический OneTouch',amount:'123456.78',price_qualifier:'from'},raw={capture_mode:'manager-file-v1',capture_reference:reference,hotel_url:gallery,capture_gallery_sha256:sha('synthetic-gallery'),capture_photo_sha256:JSON.stringify({[photo]:sha(bytes)}),capture_price_declared_at:'2077-01-01T00:00:00Z',capture_gallery_observed_at:'2020-01-01T00:00:00Z',transport:'Перелёт требует проверки',tour:'Synthetic tour',tariff:'Synthetic tariff',availability:'Номер по запросу',room:fields.room,meal:fields.meal};
 const row={ticket:'synthetic-ticket',offer:copy(fields),raw};
 const source={schema:'onetouch-manager-capture-v1',reference,captured_at:raw.capture_price_declared_at,url:'https://b2b.onetouch.travel/search_tour?samo_action=PRICES&CHECKIN_BEG=20770107&NIGHTS_FROM=7',public_controls:{},prices_gzip_base64:'SYNTHETIC_NO_HTTP',prices_sha256:sha('synthetic-prices')};
 const bundle={schema:'onetouch-bundle-v1',source,gallery_cache:[{hotel:'1001',url:gallery,observed_at:raw.capture_gallery_observed_at,html_gzip_base64:'SYNTHETIC_NO_HTTP',html_sha256:raw.capture_gallery_sha256,images:[{url:photo,sha256:sha(bytes),mime:'image/jpeg',base64:options.badImage?Buffer.from('<svg/>').toString('base64'):image}]}]};
 if(options.badImage){bundle.gallery_cache[0].images[0].sha256=sha(Buffer.from('<svg/>'));raw.capture_photo_sha256=JSON.stringify({[photo]:bundle.gallery_cache[0].images[0].sha256})}
 let imported=false;
 const s=await privateEditor(page,{draft:{title:'Synthetic retained selection',intro:'',offers:[baseOffer()]},sourceAction:async(d,store)=>{
  if(d.action==='onetouch_search')return{ok:true,version:store.snapshot().version,offers:[copy(row)]};
  if(d.action==='onetouch_review')return{ok:true,version:store.snapshot().version,...copy(row),photos:[photo]};
  if(d.action==='onetouch_add'){
   expect(imported).toBe(false);imported=true;const draft=store.snapshot().draft;
   draft.offers.push({...copy(fields),id:'d'.repeat(32),amount:d.manager_amount,photos:[],import_source:{adapter:'onetouch-public-v1',observed_at:source.captured_at,url:source.url,fields:copy(fields),raw:copy(raw),photos:[photo]}});store.replaceDraft(draft);
   return options.lostAdd?null:{ok:true,state:'added',saved_version:store.snapshot().version,count:2,offer_id:'d'.repeat(32)};
  }
  if(d.action==='onetouch_operation')return{ok:true,found:imported,saved_version:store.snapshot().version};
  if(d.editor_resource==='tour-selection-photo.php'){
   expect(d.action).toBe('upload');expect(d.source_photo_url).toBe(photo);expect(d.image_sha256).toBe(sha(bytes));
   return{ok:true,operation_id:d.operation_id,photo:{kind:d.kind,url:'https://app.anytoour.ru/manager/tour-selection-photo.php?conversation_id=11&draft_photo='+'f'.repeat(32)}};
  }
  throw Error('Unexpected synthetic source action '+d.action);
 }});
 await page.route('https://app.anytoour.ru/manager/tour-selection-photo.php?**',route=>route.fulfill({contentType:'image/jpeg',body:bytes}));
 const f=s.editor;await expect(f.locator('#tsOneTouch')).toBeVisible();await f.locator('#tsOneTouch').click();
 await f.locator('#tsOneTouchFile').setInputFiles({name:'synthetic-onetouch.json',mimeType:'application/json',buffer:Buffer.from(JSON.stringify(bundle))});
 await f.locator('#tsOneTouchRead').click();await f.getByRole('button',{name:'Проверить фото и условия',exact:true}).click();
 return{...s,f,fields,base:baseOffer()};
}
for(const width of[390,1440])test('OneTouch local file to existing private uploader retains source price and old offer '+width,async({page,browserName})=>{
 await page.setViewportSize({width,height:844});const s=await sourceFixture(page),f=s.f;
 await expect(f.locator('#tsOneTouchPhotoStatus')).toHaveText('Фотографии загружены.');
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
 const s=await sourceFixture(page,{badImage:true});await expect(s.f.locator('#tsOneTouchStatus')).toContainText('Недопустимый размер фотографии');
 expect(s.calls.some(c=>c.action==='onetouch_add')).toBe(false);expect(s.snapshot().draft.offers).toEqual([s.base]);expect(s.unexpected).toEqual([]);
});
test('OneTouch lost add response permits only receipt read then missing private photo completion',async({page})=>{
 const s=await sourceFixture(page,{lostAdd:true}),f=s.f;await expect(f.locator('#tsOneTouchPhotoStatus')).toHaveText('Фотографии загружены.');
 await f.locator('#tsOneTouchConfirm').check();await f.locator('#tsOneTouchAdd').click();await expect(f.locator('#tsRecovery')).toContainText('Проверить');
 await f.locator('#tsRecovery button').filter({hasText:'Проверить'}).click();await expect.poll(()=>s.snapshot().draft.offers[1]?.photos.length).toBe(1);
 expect(s.calls.filter(c=>c.action==='onetouch_add')).toHaveLength(1);expect(s.calls.filter(c=>c.action==='onetouch_operation')).toHaveLength(1);expect(s.snapshot().draft.offers).toHaveLength(2);expect(s.snapshot().draft.offers[0]).toEqual(s.base);expect(s.unexpected).toEqual([]);expect(s.errors).toEqual([]);
});
