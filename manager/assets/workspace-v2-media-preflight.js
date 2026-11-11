(()=>{
'use strict';
// Advisory local inspection only. Media remains the sole File/attempt owner;
// neither selected bytes nor names are requested, persisted or uploaded here.
const cache=new WeakMap(),HEADER_BYTES=65536;
const paints=new Map();let paintFrame=0,renderSequence=0;
function paint(node,apply){
 paints.set(node,apply);
 if(!paintFrame)paintFrame=window.requestAnimationFrame(()=>{paintFrame=0;const batch=[...paints.values()];paints.clear();batch.forEach(commit=>commit())});
}
const kind=mime=>/^image\//.test(mime)?'image':/^video\//.test(mime)?'video':/^audio\//.test(mime)?'audio':'file';
const labels={image:'фото',video:'видео',audio:'аудио',file:'файл'};
const mb=n=>`${(n/1000000).toLocaleString('ru-RU',{maximumFractionDigits:2})} МБ`;
function header(bytes){
 const b=new Uint8Array(bytes),v=new DataView(b.buffer,b.byteOffset,b.byteLength);
 const text=(start,length)=>String.fromCharCode(...b.subarray(start,start+length));
 const result=(mime,width=null,height=null)=>({mime,width,height});
 if(b.length>=24&&text(0,8)==='\x89PNG\r\n\x1a\n'&&text(12,4)==='IHDR')return result('image/png',v.getUint32(16),v.getUint32(20));
 if(b.length>=10&&['GIF87a','GIF89a'].includes(text(0,6)))return result('image/gif',v.getUint16(6,true),v.getUint16(8,true));
 if(b.length>=26&&text(0,2)==='BM')return result('image/bmp',Math.abs(v.getInt32(18,true)),Math.abs(v.getInt32(22,true)));
 if(b.length>=12&&text(0,4)==='RIFF'&&text(8,4)==='WEBP'){
  if(b.length>=30&&text(12,4)==='VP8X')return result('image/webp',1+b[24]+b[25]*256+b[26]*65536,1+b[27]+b[28]*256+b[29]*65536);
  if(b.length>=25&&text(12,4)==='VP8L'&&b[20]===47)return result('image/webp',1+b[21]+((b[22]&63)<<8),1+(b[22]>>6)+(b[23]<<2)+((b[24]&15)<<10));
  if(b.length>=30&&text(12,4)==='VP8 '&&text(23,3)==='\x9d\x01\x2a')return result('image/webp',v.getUint16(26,true)&16383,v.getUint16(28,true)&16383);
  return result('image/webp');
 }
 if(b.length>=3&&b[0]===255&&b[1]===216&&b[2]===255){
  let offset=2;
  while(offset+4<=b.length){
   if(b[offset++]!==255)break;while(b[offset]===255)offset++;
   const marker=b[offset++];if(marker===0xda||marker===0xd9)break;
   if(marker===1||(marker>=0xd0&&marker<=0xd8))continue;
   if(offset+2>b.length)break;const size=v.getUint16(offset);if(size<2||offset+size>b.length)break;
   if([0xc0,0xc1,0xc2,0xc3,0xc5,0xc6,0xc7,0xc9,0xca,0xcb,0xcd,0xce,0xcf].includes(marker)&&size>=7)return result('image/jpeg',v.getUint16(offset+5),v.getUint16(offset+3));
   offset+=size;
  }
  return result('image/jpeg');
 }
 if(b.length>=4&&['II\x2a\x00','MM\x00\x2a'].includes(text(0,4)))return result('image/tiff');
 if(b.length>=16&&text(4,4)==='ftyp'){
  const brand=text(8,4);
  if(['heic','heix','hevc','hevx','mif1','msf1'].includes(brand))return result('image/heic');
  if(['avif','avis'].includes(brand))return result('image/avif');
  if(['M4A ','M4B '].includes(brand))return result('audio/mp4');
  if(brand==='qt  ')return result('video/quicktime');
  return result('video/mp4');
 }
 if(b.length>=5&&text(0,5)==='%PDF-')return result('application/pdf');
 if(b.length>=3&&text(0,3)==='ID3')return result('audio/mpeg');
 if(b.length>=12&&text(0,4)==='RIFF'&&text(8,4)==='WAVE')return result('audio/wav');
 if(b.length>=4&&text(0,4)==='fLaC')return result('audio/flac');
 return result('');
}
function inspect(file){
 if(!cache.has(file))cache.set(file,Promise.resolve().then(async()=>{
  try{return {...header(await file.slice(0,HEADER_BYTES).arrayBuffer()),read:true}}
  catch{return {mime:'',width:null,height:null,read:false}}
 }));
 return cache.get(file);
}
function describe(file,meta,channel,policy){
 const mime=meta.mime||String(file.type||'').toLowerCase(),type=kind(mime),warnings=[];
 const channelName=channel==='max'?'MAX':channel==='telegram'?'Telegram':'этот канал';
 const expected=`Ожидается у получателя: ${labels[type]}`;
 if(!policy?.supported)return {text:'Вложения для этого канала пока не поддерживаются. Текст и файлы сохранены.',warning:true};
 const limit=policy.max_file_bytes_by_type?.[type];
 if(Number.isFinite(limit)&&file.size>limit)warnings.push(`Размер больше доступного лимита ${mb(limit)}`);
 if(!meta.read)warnings.push('Не удалось прочитать заголовок файла');
 else if(!meta.mime)warnings.push('Формат по заголовку не определён; сервер проверит его при отправке');
 if(meta.mime&&file.type&&kind(meta.mime)!==kind(String(file.type).toLowerCase()))warnings.push('Содержимое отличается от типа, указанного браузером');
 const w=meta.width,h=meta.height,dimensions=Number.isSafeInteger(w)&&w>0&&Number.isSafeInteger(h)&&h>0;
 if(type==='image'){
  if(!policy.image_formats?.includes(mime))warnings.push(`Совместимость этого формата фото с ${channelName} не подтверждена`);
  if(!dimensions)warnings.push('Размеры изображения не проверены');
  else {
   if(policy.max_image_edge&&(w>policy.max_image_edge||h>policy.max_image_edge))warnings.push(`Сторона фото больше ${policy.max_image_edge} пикселей`);
   if(policy.max_photo_dimension_sum&&w+h>policy.max_photo_dimension_sum)warnings.push(`Сумма ширины и высоты больше ${policy.max_photo_dimension_sum} пикселей`);
   if(policy.max_photo_ratio&&Math.max(w/h,h/w)>policy.max_photo_ratio)warnings.push('Фото слишком вытянуто для этого канала');
  }
 }
 if(type==='video'&&!policy.video_formats?.includes(mime))warnings.push('Совместимость формата видео не подтверждена');
 if(type==='video')warnings.push('Кодек и воспроизведение заранее не проверены');
 if(type==='audio'&&channel==='max')warnings.push('Длительность заранее не проверена: MAX допускает до 60 минут');
 // Put the actionable warning first: short screens show a bounded scroll list.
 return {text:`${warnings.length?warnings.join('. ')+'. ':''}${expected}${dimensions?` · ${w}×${h}`:''}${Number.isFinite(limit)?` · до ${mb(limit)}`:''}. ${warnings.length?'':'Размер и распознанный формат проверены предварительно; приём подтвердит канал.'}`,warning:warnings.length>0};
}
function context(){
 const S=window.WorkspaceV2?.S,c=S?.detail?.conversation;
 if(!S||S.authExpired||!S.manager?.id||Number(c?.id)!==Number(S.current)||Number(c?.manager_id)!==Number(S.manager.id)||c.status!=='manager')return null;
 return JSON.stringify([S.manager.id,S.authGeneration,S.current,c.project_key,c.channel,window.WorkspaceV2Conversation?.getOpenGeneration?.()]);
}
function render(rows,box){
 const ticket=++renderSequence,captured=context();
 if(!captured||!rows.length||!box?.querySelectorAll)return;
 const nodes=Array.from(box.querySelectorAll('.mediaSelectedItem')),channel=String(window.WorkspaceV2.S.detail.conversation.channel||'').toLowerCase();
 let policy=null;try{const p=JSON.parse(document.getElementById('replyFile')?.dataset.mediaPolicy||'null');if(p?.schema===1)policy=p.channels?.[channel]||{supported:false}}catch{}
 rows.forEach((row,index)=>{
  const info=nodes[index]?.querySelector('.mediaFileInfo');if(!info)return;
  const status=document.createElement('span');status.className='mediaFileCheck';status.textContent=policy?'Проверяем файл в этой вкладке…':'Предварительные ограничения недоступны; формат проверится при отправке.';info.appendChild(status);
  if(!policy)return;
  if(!policy.supported){status.textContent=describe(row.file,{},channel,policy).text;status.dataset.warning='true';return}
  inspect(row.file).then(meta=>{
   if(ticket!==renderSequence||captured!==context()||!status.isConnected)return;
   const result=describe(row.file,meta,channel,policy);
   // File promises may settle among layout notifications. Commit one visual
   // batch outside that delivery, without asking Media to rebuild its rows.
   paint(status,()=>{if(ticket!==renderSequence||captured!==context()||!status.isConnected)return;status.textContent=result.text;status.dataset.warning=String(result.warning)});
  });
 });
}
window.WorkspaceV2MediaPreflight={render};
})();
