'use strict';
const assert=require('node:assert/strict'),P=require('../assets/project-funnel.js');
let passed=0;function check(name,fn){fn();passed++;console.log('PASS '+name);}
const token=Buffer.from(JSON.stringify({post_id:'service-display',offer_id:'as39-search-v1-service-display'})).toString('base64url');
const start='18446744073709551614_region_213_campaign_123_ctx1_'+token;
const init=new URLSearchParams({auth_date:'1794300000',user:'{"id":7001}',start_param:start,hash:'f'.repeat(64)}).toString();
const href='https://app.anytoour.ru/project_funnel.php?project=appleservice39#'+new URLSearchParams({WebAppData:init,WebAppStartParam:start});
const cfg={project:'appleservice39',brand:'AppleService39',counter_id:'',channel_url:'https://max.ru/channel_appleservice39ru'};
const accepted={ok:true,mode:'advertising',saved:true,post_id:'service-display',offer_id:'as39-search-v1-service-display',redirect_url:cfg.channel_url+'/AaG9bRUxHcg',receipt:{visit_id:'max1_'+'a'.repeat(64),cursor:'1',reused:false}};
const ctx=P.context(href);
check('opaque post and large click ID remain strings',()=>{assert.equal(ctx.payload.post_id,'service-display');assert.equal(ctx.payload.yclid,'18446744073709551614');});
check('analytics URL contains ad fields and no MAX identity or signature',()=>{assert.ok(ctx.metric.includes('yclid=18446744073709551614'));for(const part of['WebAppData','hash','7001','startapp','ctx1'])assert.ok(!ctx.metric.includes(part));});
check('bridge may load after unsigned launch hints',()=>assert.equal(P.context('https://app.test/?startapp='+start).init_data,''));
check('bridge and fragment cannot disagree',()=>assert.throws(()=>P.context(href,{initData:init+'x'})));
check('unsigned hint cannot replace signed post',()=>assert.throws(()=>P.context(href.replace('?project=appleservice39','?project=appleservice39&startapp=post1_menu'))));
check('duplicate launch fields are rejected',()=>assert.throws(()=>P.context(href+'&WebAppStartParam='+start)));
for(const bad of['https://evil.test/post',cfg.channel_url+'/post?x=1',cfg.channel_url+'/post#x',cfg.channel_url+'/../evil',cfg.channel_url+'-other/post','https://max.ru@evil.test/post'])check('unsafe destination rejected '+bad,()=>assert.equal(P.safeLink(bad,cfg.channel_url),false));
check('matching durable receipt allows redirect',()=>assert.equal(P.receipt(accepted,ctx,cfg),true));
for(const change of[{saved:false},{mode:'demo'},{post_id:'menu'},{offer_id:'another'},{receipt:null},{receipt:{visit_id:'',cursor:'1'}},{redirect_url:'https://evil.test'}])check('incomplete or foreign receipt prevents redirect '+JSON.stringify(change),()=>assert.equal(P.receipt({...accepted,...change},ctx,cfg),false));
function fixture({reply=accepted,http=200,networkError=false,counter='',clientId='12345678900012345678',timeout=false,bridgeData=init}={}){
  const els=new Map(),scripts=[],requests=[],assigned=[],metrics=[],timers=new Map();let n=0;
  const el=id=>{if(!els.has(id))els.set(id,{hidden:true,textContent:'',listeners:{},addEventListener(k,fn){this.listeners[k]=fn;},setAttribute(){}});return els.get(id);};
  el('funnel-config').textContent=JSON.stringify({...cfg,counter_id:counter});
  const win={document:{getElementById:el,querySelector:()=>el('main'),head:{appendChild:s=>scripts.push(s)},createElement:()=>({remove(){}})},
    WebApp:{initData:bridgeData,ready(){}},location:{href,hash:new URL(href).hash,assign:url=>assigned.push(url)},history:{replaceState(){}},
    setTimeout(fn,ms){const id=++n;timers.set(id,fn);if((timeout&&ms===10000)||(!clientId&&ms===3000))queueMicrotask(fn);return id;},clearTimeout:id=>timers.delete(id),
    ym(...args){metrics.push(args);if(args[1]==='getClientID'&&clientId)queueMicrotask(()=>args[2](clientId));},
    async fetch(url,options){requests.push({url,body:JSON.parse(options.body)});if(networkError)throw Error('network');if(timeout)return new Promise((_,reject)=>options.signal.addEventListener('abort',()=>reject(Error('timeout'))));return{ok:http===200,json:async()=>reply};}};
  return{win,els,requests,assigned,metrics,scripts};
}
(async()=>{
  let f=fixture();await P.start(f.win);check('success redirects only after one acknowledged save',()=>{assert.equal(f.requests.length,1);assert.deepEqual(f.assigned,[accepted.redirect_url]);assert.equal(f.requests[0].body.init_data,init);});
  f=fixture({reply:{...accepted,saved:false}});await P.start(f.win);check('save failure shows retry and never navigates',()=>{assert.deepEqual(f.assigned,[]);assert.equal(f.els.get('retry').hidden,false);});
  f=fixture({networkError:true});await P.start(f.win);check('network failure retains a manual retry',()=>{assert.equal(f.els.get('retry').hidden,false);assert.deepEqual(f.assigned,[]);});
  f=fixture({timeout:true});await P.start(f.win);check('unknown POST timeout never assumes saved or navigates',()=>assert.deepEqual(f.assigned,[]));
  f=fixture({counter:'99900011'});await P.start(f.win);check('ClientID comes from the configured counter callback',()=>{assert.equal(f.requests[0].body.client_id,'12345678900012345678');assert.equal(f.metrics[0][0],'99900011');assert.ok(!JSON.stringify(f.metrics[0]).includes('WebAppData'));});
  check('analytics defers the automatic hit and records one explicitly sanitized visit',()=>{assert.equal(f.metrics[0][1],'init');assert.equal(f.metrics[0][2].defer,true);const hits=f.metrics.filter(call=>call[1]==='hit');assert.equal(hits.length,1);assert.equal(hits[0][2],ctx.metric);assert.deepEqual(hits[0][3],{referrer:''});assert.ok(!JSON.stringify(f.metrics).includes('WebAppData'));assert.ok(!JSON.stringify(f.metrics).includes('7001'));});
  f=fixture({counter:'99900011',clientId:''});await P.start(f.win);check('blocked analytics preserves an empty CID and the saved redirect',()=>{assert.equal(f.requests[0].body.client_id,'');assert.equal(f.assigned.length,1);});
  const organic=new URLSearchParams({auth_date:'1794300000',user:'{"id":7001}',start_param:'post1_menu',hash:'f'.repeat(64)}).toString();
  f=fixture({bridgeData:organic,reply:{ok:true,mode:'publication',saved:false,post_id:'menu',offer_id:'as39-search-v1-menu',redirect_url:cfg.channel_url+'/AaG9bSM5Y9o'},counter:'99900011'});f.win.location.href='https://app.anytoour.ru/project_funnel.php?project=appleservice39';f.win.location.hash='';await P.start(f.win);
  check('organic path redirects without Metrika or fake ad capture',()=>{assert.equal(f.assigned.length,1);assert.equal(f.metrics.length,0);assert.equal(f.requests[0].body.client_id,'');});
  console.log('PROJECT FUNNEL CLIENT PASSED '+passed);
})().catch(e=>{console.error(e);process.exitCode=1;});
