import fs from 'node:fs/promises';
import path from 'node:path';
import crypto from 'node:crypto';
import { fileURLToPath } from 'node:url';
import satori from 'satori';
import sharp from 'sharp';

export const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
export const sha256 = b => crypto.createHash('sha256').update(b).digest('hex');
export const JPEG = { quality: 90, chromaSubsampling: '4:4:4', mozjpeg: true };
export const PNG = { compressionLevel: 6, adaptiveFiltering: true };
sharp.concurrency(1);
const fonts = await Promise.all([[400,'Regular'],[600,'Semibold'],[700,'Bold']].map(async ([weight,suffix]) => ({name:'Open Sans',weight,style:'normal',data:await fs.readFile(path.join(ROOT,'assets/fonts',`OpenSans-${suffix}.ttf`))})));
export const REPOSITORY_ROOT = path.resolve(ROOT, '../../../..');
const logo = await fs.readFile(path.join(REPOSITORY_ROOT,'.github/traffic-publish/brand/anytour-logo-white.png'));
check(sha256(logo)==='b27cda894047b1ecb4125eea2ecf0db9d1e1674472cad62f70edf1ef1a6c968f','canonical_logo_changed');

export function check(condition,message) { if (!condition) throw new Error(message); }
export function validateCard(card) {
  check(card && card.brand==='anytour','unknown_brand');
  check(/^[a-z0-9-]+$/.test(card.id),'invalid_id');
  check(typeof card.title==='string' && card.title.length>=2 && card.title.length<=160,'invalid_title');
  check(typeof card.description==='string' && card.description.length<=160,'invalid_description');
  check(typeof card.location==='string' && card.location.length<=80,'invalid_location');
  check(!('price' in card) && !('currency' in card) && !('expires_at' in card),'dynamic_price_not_supported_in_this_pilot');
  check(card.verified_identity===true,'unverified_identity');
  check(!card.demo || (card.demo_label && card.demo_label.startsWith('ДЕМО')),'demo_requires_visible_label');
  check(Array.isArray(card.photos) && card.photos.length === 3,'invalid_photo_count');
  for(const p of card.photos){
    check(typeof p.path==='string' && p.path.startsWith('assets/') && !p.path.includes('..') && !p.path.includes('://'),'local_asset_path_required');
    check(/^[a-f0-9]{64}$/.test(p.sha256),'asset_hash_required');
    check(Array.isArray(p.focal) && p.focal.length===2 && p.focal.every(x=>Number.isFinite(x)&&x>=0&&x<=1),'invalid_focal_point');
  }
}

function el(type,style={},children,role,props={}) { return {type,props:{...props,style,...(role?{'data-role':role}:{}),...(children!==undefined?{children}:{})}}; }
function box(style,children,role) { return el('div',{display:'flex',flexShrink:0,...style},children,role); }
function text(value,style,role) { return box({fontFamily:'Open Sans',...style},value,role); }
function abs(x,y,w,h,rest={}) { return {position:'absolute',left:x,top:y,width:w,...(h!==null?{height:h}:{}),...rest}; }
const dataURI = (buffer,type='image/png') => `data:${type};base64,${buffer.toString('base64')}`;

async function photo(asset,width,height,{fit='cover',background='#eaf0fb',assetRoot=ROOT}={}) {
  const source=await fs.readFile(path.join(assetRoot,asset.path));
  check(sha256(source)===asset.sha256,`asset_hash_mismatch:${asset.path}`);
  let normalized=await sharp(source,{limitInputPixels:40_000_000}).autoOrient().toColourspace('srgb').png().toBuffer();
  if(asset.extract) normalized=await sharp(normalized).extract(asset.extract).png().toBuffer();
  const m=await sharp(normalized).metadata();
  let rect=null, retained=1;
  if(fit==='cover'){
    const ratio=width/height;
    let cw=m.width,ch=m.height;
    if(cw/ch>ratio)cw=Math.round(ch*ratio);else ch=Math.round(cw/ratio);
    cw=Math.min(cw,m.width);ch=Math.min(ch,m.height);
    const left=Math.max(0,Math.min(m.width-cw,Math.round(asset.focal[0]*m.width-cw/2)));
    const top=Math.max(0,Math.min(m.height-ch,Math.round(asset.focal[1]*m.height-ch/2)));
    rect={left,top,width:cw,height:ch};retained=(cw*ch)/(m.width*m.height);
    normalized=await sharp(normalized).extract(rect).resize(width,height,{fit:'fill'}).png().toBuffer();
  }else{
    normalized=await sharp(normalized).resize(width,height,{fit:'contain',background}).png().toBuffer();
  }
  return {uri:dataURI(normalized),audit:{path:asset.path,source_sha256:asset.sha256,source_dimensions:[m.width,m.height],target:[width,height],fit,extract:asset.extract??null,crop:rect,retained_area:retained,focal:asset.focal}};
}

const HOTEL_TITLE_SIZE = 58;
const HOTEL_DESCRIPTION_SIZE = 38;
const hotelPlanCache = new Map();

async function balancedHotelCopy(card) {
  const fields=[{key:'title',value:card.title,size:HOTEL_TITLE_SIZE,weight:700},
    {key:'description',value:card.description,size:HOTEL_DESCRIPTION_SIZE,weight:400}];
  const probes=[],widths=new Map();
  for(const field of fields){
    field.words=field.value.trim().split(/\s+/);
    field.candidates=[{key:field.key+':whole',value:field.value}];
    const explicit=card.layout?.[field.key+'_lines'];
    if(explicit!==undefined){
      check(Array.isArray(explicit)&&explicit.length>=1&&explicit.length<=2
        &&explicit.every(s=>typeof s==='string'&&s.trim()===s&&s.length>0&&!/[\n\r]/.test(s))
        &&explicit.join(' ')===field.value.trim().replace(/\s+/g,' '),'layout_override_changes_copy');
      field.explicit=explicit;
      explicit.forEach((value,i)=>field.candidates.push({key:`${field.key}:explicit:${i}`,value}));
    }
    for(let split=1;split<field.words.length;split++){
      field.candidates.push({key:`${field.key}:${split}:left`,value:field.words.slice(0,split).join(' ')},
        {key:`${field.key}:${split}:right`,value:field.words.slice(split).join(' ')});
    }
    for(const item of field.candidates)probes.push(text(item.value,
      {alignSelf:'flex-start',fontSize:field.size,fontWeight:field.weight,lineHeight:1.2},item.key));
  }
  await satori(box({width:10000,height:14000,flexDirection:'column',alignItems:'flex-start'},probes),{
    width:10000,height:14000,fonts,embedFont:true,onStyleError:e=>{throw e;},
    onNodeDetected:n=>{if(n.props?.['data-role'])widths.set(n.props['data-role'],n.width);}
  });
  const result={};
  for(const field of fields){
    if(widths.get(field.key+':whole')<=920){result[field.key]=field.value;continue;}
    if(field.explicit){
      check(field.explicit.every((_,i)=>widths.get(`${field.key}:explicit:${i}`)<=920),'layout_override_overflows');
      result[field.key]=field.explicit.join('\n');continue;
    }
    const candidates=[];
    for(let split=1;split<field.words.length;split++){
      const left=widths.get(`${field.key}:${split}:left`),right=widths.get(`${field.key}:${split}:right`);
      if(left>920||right>920)continue;
      const dangling=/^(и|с|у|в|на|для|&|and|of|the|a|an)$/iu.test(field.words[split-1])||field.words[split]==='&';
      const orphan=split===1||split===field.words.length-1;
      candidates.push({split,score:Math.abs(left-right)+(dangling?10000:0)+(orphan?5000:0)});
    }
    candidates.sort((a,b)=>a.score-b.score||a.split-b.split);
    check(candidates.length,`hotel_${field.key}_requires_more_than_two_lines`);
    const {split}=candidates[0];
    result[field.key]=field.words.slice(0,split).join(' ')+'\n'+field.words.slice(split).join(' ');
  }
  return result;
}

// Measure the real glyph layout, then give the remaining space to photographs.
// The bridge uses the same rectangles for Pillow-centering -> focal conversion.
// Short and long titles keep the same type size; unsupported copy is rejected.
export async function planHotelLayout(card) {
  const portrait=card.photos[0].width/card.photos[0].height<0.95;
  const key=JSON.stringify([card.title,card.description,card.location,portrait,card.layout??null]);
  if(hotelPlanCache.has(key))return hotelPlanCache.get(key);
  const copy=await balancedHotelCopy(card);
  const measured=[];
  const measure=box({width:1000,height:1000,position:'relative'},[
    text(copy.title,abs(40,0,920,null,{fontSize:HOTEL_TITLE_SIZE,fontWeight:700,lineHeight:1.08,whiteSpace:'pre-wrap'}),'title'),
    text(copy.description,abs(40,300,920,null,{fontSize:HOTEL_DESCRIPTION_SIZE,fontWeight:400,lineHeight:1.2,whiteSpace:'pre-wrap'}),'description'),
    text(card.location,abs(42,600,680,null,{fontSize:36,fontWeight:600,lineHeight:1.2}),'location')
  ]);
  await satori(measure,{width:1000,height:1000,fonts,embedFont:true,
    onStyleError:e=>{throw e;},
    onNodeDetected:n=>{if(n.props?.['data-role'])measured.push({role:n.props['data-role'],height:n.height});}
  });
  const height=role=>Math.ceil(measured.find(n=>n.role===role).height);
  const titleHeight=height('title'),descriptionHeight=height('description'),locationHeight=height('location');
  check(titleHeight<=Math.ceil(HOTEL_TITLE_SIZE*1.08*2),'hotel_title_requires_more_than_two_lines');
  check(descriptionHeight<=Math.ceil(HOTEL_DESCRIPTION_SIZE*1.2*2),'hotel_description_requires_more_than_two_lines');
  check(locationHeight<=44,'hotel_location_requires_more_than_one_line');
  const copyHeight=titleHeight+18+descriptionHeight;
  const copyTop=960-copyHeight,photoTop=96,photoBottom=copyTop-24;
  const photoHeight=photoBottom-photoTop;
  check(photoHeight>=560,'hotel_photo_area_too_small');
  let photoRects;
  if(portrait){
    const sideHeight=Math.floor((photoHeight-12)/2);
    photoRects=[[24,photoTop,520,photoHeight],[556,photoTop,420,sideHeight],
      [556,photoTop+sideHeight+12,420,photoHeight-sideHeight-12]];
  }else{
    const detailHeight=210,heroHeight=photoHeight-detailHeight-12;
    photoRects=[[24,photoTop,952,heroHeight],[24,photoTop+heroHeight+12,470,detailHeight],
      [506,photoTop+heroHeight+12,470,detailHeight]];
  }
  const plan={portrait,variant:portrait?'portrait':titleHeight>64?'landscape-long-title':'landscape-short-title',
    titleSize:HOTEL_TITLE_SIZE,descriptionSize:HOTEL_DESCRIPTION_SIZE,titleHeight,descriptionHeight,
    titleText:copy.title,descriptionText:copy.description,
    explicitTitleLines:card.layout?.title_lines??null,
    copyTop,copyHeight,photoRects,boxes:photoRects.map(r=>r.slice(2))};
  hotelPlanCache.set(key,plan);
  return plan;
}

async function hotelTree(card,titleSize,assetRoot=ROOT) {
  const plan=await planHotelLayout(card);
  const prepared=await Promise.all(card.photos.map((p,i)=>photo(p,...plan.boxes[i],{assetRoot})));
  const photoEl=(p,x,y,w,h,role)=>el('img',abs(x,y,w,h,{borderRadius:16}),undefined,role,{src:p.uri,width:w,height:h});
  const children=[
    box(abs(0,0,1000,84,{backgroundColor:'#2743CB'})),
    box(abs(24,30,5,24,{backgroundColor:'#FF510C',borderRadius:2})),
    text(card.location,abs(42,20,680,null,{fontSize:36,fontWeight:600,lineHeight:1.2,color:'#fff'}),'location'),
    el('img',abs(750,12,219,59),undefined,'logo',{src:dataURI(logo),width:219,height:59}),
    ...prepared.map((p,i)=>photoEl(p,...plan.photoRects[i],['photo-hero','photo-detail-1','photo-detail-2'][i])),
    box(abs(40,plan.copyTop,920,null,{flexDirection:'column'}),[
      text(plan.titleText,{width:920,marginBottom:18,fontSize:titleSize,fontWeight:700,lineHeight:1.08,whiteSpace:'pre-wrap',color:'#10264D'},'title'),
      text(plan.descriptionText,{width:920,fontSize:HOTEL_DESCRIPTION_SIZE,fontWeight:400,lineHeight:1.2,whiteSpace:'pre-wrap',color:'#43546D'},'description')
    ],'copy')
  ];
  if(card.demo)children.push(text(card.demo_label,abs(40,112,700,null,{padding:12,fontSize:28,fontWeight:700,color:'#10264D',backgroundColor:'#FFF0CC'}),'demo-label'));
  return {tree:box({width:1000,height:1000,backgroundColor:'#F8FAFF',position:'relative'},children,'canvas'),
    width:1000,height:1000,photos:prepared.map(x=>x.audit),
    limits:{title:{left:40,top:plan.copyTop,right:960,bottom:plan.copyTop+plan.titleHeight},
      description:{left:40,top:plan.copyTop+plan.titleHeight+17,right:960,bottom:960},
      location:{left:42,top:20,right:722,bottom:64}},portrait:plan.portrait,plan};
}

export function layoutFailures(nodes,limits) {
  const errors=[];
  for(const [role,lim] of Object.entries(limits)){
    const n=nodes.find(n=>n.role===role);if(!n){errors.push(`missing_node:${role}`);continue;}
    if(n.left<lim.left-.5 || n.top<lim.top-.5 || n.left+n.width>lim.right+.5 || n.top+n.height>lim.bottom+.5)errors.push(`overflow:${role}`);
  }
  const title=nodes.find(n=>n.role==='title'),description=nodes.find(n=>n.role==='description');
  if(title&&description&&title.top+title.height+12>description.top)errors.push('overlap:title-description');
  return errors;
}

export async function renderCard(card,{previews=true,pngOnly=false,assetRoot=ROOT}={}) {
  validateCard(card);
  const start=performance.now();let out,nodes,svg,titleSize;
  const candidates=[HOTEL_TITLE_SIZE];
  for(titleSize of candidates){
    out=await hotelTree(card,titleSize,assetRoot);nodes=[];
    svg=await satori(out.tree,{width:out.width,height:out.height,fonts,embedFont:true,
      onStyleError:e=>{throw e;},
      onNodeDetected:n=>{if(n.props?.['data-role'])nodes.push({role:n.props['data-role'],left:n.left,top:n.top,width:n.width,height:n.height,text:n.textContent??null});}
    });
    const errors=layoutFailures(nodes,out.limits);
    if(!errors.length)break;
    if(errors.some(e=>e!=='overflow:title')||titleSize===candidates.at(-1))throw new Error(`${card.id}: ${errors.join(', ')}`);
  }
  const png=await sharp(Buffer.from(svg)).flatten({background:'#fff'}).toColourspace('srgb').png(PNG).toBuffer();
  const jpeg=pngOnly?null:await sharp(png).jpeg(JPEG).toBuffer();
  const channel=pngOnly?null:await sharp(png).resize(768,768).jpeg(JPEG).toBuffer();
  const mobile={};if(previews)for(const width of [320,360])mobile[width]=await sharp(png).resize(width,width).png(PNG).toBuffer();
  return {svg,png,jpeg,channel,mobile,audit:{id:card.id,brand:card.brand,title:card.title,dimensions:[out.width,out.height],title_font_px:titleSize,title_mobile_320_px:titleSize*320/out.width,description_mobile_320_px:HOTEL_DESCRIPTION_SIZE*320/out.width,portrait_layout:out.portrait??false,layout_plan:out.plan??null,limits:out.limits,nodes,photos:out.photos,layout_errors:layoutFailures(nodes,out.limits),image_sha256:sha256(png),jpeg_sha256:jpeg?sha256(jpeg):null,bytes:{png:png.length,jpeg:jpeg?.length??null,channel768:channel?.length??null},render_ms:performance.now()-start}};
}
