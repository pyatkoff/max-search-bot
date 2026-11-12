'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const source=fs.readFileSync(require('node:path').join(__dirname,'../manager/assets/workspace-v2-media-preflight.js'),'utf8');
const policies={schema:1,channels:{max:{supported:true,max_file_bytes_by_type:{image:50000000,video:250000000,audio:256000000,file:4000000000},max_image_edge:7680,image_formats:['image/png','image/jpeg','image/gif','image/bmp','image/tiff','image/heic'],video_formats:['video/mp4','video/quicktime','video/x-matroska','video/webm']},telegram:{supported:true,max_file_bytes_by_type:{image:10000000,video:50000000,audio:50000000,file:50000000},max_photo_dimension_sum:10000,max_photo_ratio:20,image_formats:['image/png','image/jpeg','image/webp','image/gif'],video_formats:['video/mp4']}}};
function harness(channel='telegram',policy=policies,manualFrames=false){
 const S={manager:{id:7},authGeneration:1,current:11,detail:{conversation:{id:11,manager_id:7,status:'manager',project_key:'anytour',channel}}};let open=1;
 const frames=[];const window={WorkspaceV2:{S},WorkspaceV2Conversation:{getOpenGeneration:()=>open},requestAnimationFrame:fn=>{if(manualFrames)frames.push(fn);else setImmediate(fn);return 1}};
 const input={dataset:{mediaPolicy:JSON.stringify(policy)}},document={getElementById:()=>input,createElement:()=>({textContent:'',className:'',dataset:{},isConnected:true})};
 vm.runInNewContext(source,{window,document,WeakMap,Uint8Array,DataView,Promise,JSON,Number,String,Math});
 const spans=[],info={appendChild:s=>spans.push(s),querySelector:()=>spans.at(-1)},box={scrollTop:77,querySelectorAll:()=>[{querySelector:()=>info}]};
 return {S,spans,box,window,paint:()=>frames.splice(0).forEach(fn=>fn()),open:()=>open++,render(file,refresh=false){window.WorkspaceV2MediaPreflight.render([{file,status:'unknown'}],box,refresh);return spans.at(-1)},clear(){window.WorkspaceV2MediaPreflight.render([],box)},input};
}
const flush=()=>new Promise(resolve=>setImmediate(()=>setImmediate(resolve)));
const png=(w,h)=>{const b=Buffer.alloc(24);Buffer.from([137,80,78,71,13,10,26,10]).copy(b);b.write('IHDR',12);b.writeUInt32BE(w,16);b.writeUInt32BE(h,20);return b};
function file(bytes,type='image/png',size=bytes.length){let reads=0,ranges=[];return {name:'literal <img>.png',type,size,get reads(){return reads},ranges,slice(start,end){reads++;ranges.push([start,end]);return{arrayBuffer:async()=>Uint8Array.from(bytes).buffer}}}}
const cases=[];const test=(name,fn)=>cases.push({name,fn});
test('local metadata never writes during the read/observer microtask; late paint is fenced',async()=>{
 const h=harness('telegram',policies,true),s=h.render(file(png(5,5)));await flush();assert.match(s.textContent,/Проверяем файл/);h.open();h.paint();assert.match(s.textContent,/Проверяем файл/);const next=h.render(file(png(6,6)));await flush();h.paint();assert.match(next.textContent,/6×6/);
});
test('bounded real PNG header warns dimensions before any upload without mutating File or rows',async()=>{
 const h=harness(),f=file(png(9000,2000)),before=Object.getOwnPropertyDescriptors(f),s=h.render(f);await flush();
 assert.ok(s.textContent.startsWith('Сумма ширины'),'Known warning is visible before long recipient/limit details at short height');assert.match(s.textContent,/9000×2000/);assert.match(s.textContent,/Сумма ширины/);assert.equal(s.dataset.warning,'true');assert.deepEqual(f.ranges,[[0,65536]]);assert.equal(h.box.scrollTop,77);assert.deepEqual(Object.keys(f),Object.keys(before));
});
test('late visual paint preserves the current list scroll against native anchoring',async()=>{
 const h=harness('telegram',policies,true),s=h.render(file(png(5,5)));await flush();let text=s.textContent;
 Object.defineProperty(s,'textContent',{get:()=>text,set:value=>{text=value;h.box.scrollTop+=41}});
 // The user may scroll again after the read settles and before its visual frame.
 h.box.scrollTop=123;h.paint();assert.match(s.textContent,/5×5/);assert.equal(h.box.scrollTop,123);
});
test('only a fresh accepted render rebinds a retained node and permits one layout update',async()=>{
 const h=harness('telegram',policies,true),f=file(png(5,5));let resizes=0;h.window.WorkspaceV2ReplyEditor={resize:()=>resizes++};const s=h.render(f);await flush();h.open();h.paint();assert.equal(resizes,0);assert.match(s.textContent,/Проверяем файл/);
 assert.equal(h.render(f,true),s);await flush();h.paint();assert.equal(h.spans.length,1);assert.equal(f.reads,1);assert.match(s.textContent,/5×5/);assert.equal(resizes,1);
});
test('known MAX edge and Telegram photo bytes/ratio use current channel independently',async()=>{
 const a=harness('max'),b=harness(),f=file(png(8000,10),'image/png',12000000),sa=a.render(f),sb=b.render(f);await flush();assert.match(sa.textContent,/Сторона/);assert.doesNotMatch(sa.textContent,/Размер больше/);assert.match(sb.textContent,/Размер больше/);assert.match(sb.textContent,/слишком вытянуто/);
});
test('browser MIME disagreement uses detected file format and never trusts filename HTML',async()=>{
 const h=harness(),f=file(Buffer.from('%PDF-1.7'),'image/png'),s=h.render(f);await flush();assert.match(s.textContent,/получателя: файл/);assert.match(s.textContent,/отличается/);assert.doesNotMatch(s.textContent,/<img>/);
});
test('unknown or unreadable metadata is explicit, not a promise or a send blocker',async()=>{
 const h=harness(),s=h.render(file(Buffer.from('unknown'),'image/heic'));await flush();assert.match(s.textContent,/не определён/);assert.match(s.textContent,/Размеры.*не проверены/);
 const f=file([]);f.slice=()=>{throw Error('denied')};const fail=h.render(f);await flush();assert.match(fail.textContent,/Не удалось прочитать/);
});
test('unavailable policy and unsupported website make no file reads or network calls',async()=>{
 for(const h of [harness('telegram',null),harness('website')]){const f=file(png(10,10)),s=h.render(f);await flush();assert.equal(f.reads,0);assert.match(s.textContent,/недоступны|не поддерживаются/)}
});
for(const change of ['conversation','actor','auth','open','project','channel','clear','detached'])test('late header cannot paint changed '+change,async()=>{
 const h=harness();let resolve;const f=file([]);f.slice=()=>({arrayBuffer:()=>new Promise(done=>{resolve=done})});const s=h.render(f);await flush();const before=s.textContent;
 if(change==='conversation')h.S.current=12;if(change==='actor')h.S.manager.id=8;if(change==='auth')h.S.authGeneration++;if(change==='open')h.open();if(change==='project')h.S.detail.conversation.project_key='other';if(change==='channel')h.S.detail.conversation.channel='max';if(change==='clear')h.clear();if(change==='detached')s.isConnected=false;
 resolve(Uint8Array.from(png(5,5)).buffer);await flush();assert.equal(s.textContent,before);
});
test('same exact File is read once across renders and a replacement receives its own result',async()=>{
 const h=harness(),f=file(png(10,20));h.render(f);await flush();const second=h.render(f);await flush();assert.equal(f.reads,1);assert.match(second.textContent,/10×20/);const next=h.render(file(png(30,40)));await flush();assert.match(next.textContent,/30×40/);
});
test('JPEG dimensions, GIF and WebP header use bounded local parsing',async()=>{
 const jpeg=Buffer.from([255,216,255,192,0,8,8,0,40,0,30,0]),gif=Buffer.from([71,73,70,56,57,97,30,0,40,0]);
 const webp=Buffer.alloc(30);webp.write('RIFF');webp.write('WEBP',8);webp.write('VP8X',12);webp[24]=29;webp[27]=39;
 for(const [bytes,type] of [[jpeg,'image/jpeg'],[gif,'image/gif'],[webp,'image/webp']]){const h=harness(),s=h.render(file(bytes,type));await flush();assert.match(s.textContent,/30×40/)}
});
(async()=>{let failed=0;for(const c of cases){try{await c.fn();console.log('PASS '+c.name)}catch(e){failed++;console.error('FAIL '+c.name+'\n'+e.stack)}}console.log(`TOTAL ${cases.length} | FAIL ${failed}`);process.exitCode=failed?1:0})();
