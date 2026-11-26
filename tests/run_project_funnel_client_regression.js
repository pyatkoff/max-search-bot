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
function fixture({reply=accepted,http=200,networkError=false,counter='',clientId='12345678900012345678',timeout=false,bridgeData=init,nativeBridge=false,pageHref=href,telegram=false,assignThrows=false,project='appleservice39'}={}){
  const els=new Map(),scripts=[],requests=[],assigned=[],opened=[],metrics=[],metricUrls=[],addresses=[],timeline=[],timers=new Map();let n=0,decoded=false;
  const el=id=>{if(!els.has(id))els.set(id,{hidden:true,textContent:'',listeners:{},addEventListener(k,fn){this.listeners[k]=fn;},setAttribute(){}});return els.get(id);};
  el('funnel-config').textContent=JSON.stringify({...cfg,project,counter_id:counter,...(telegram?{platform:'telegram',channel_url:'https://t.me/appleservice39'}:{})});
  const win={document:{getElementById:el,querySelector:()=>el('main'),head:{appendChild:s=>scripts.push(s)},createElement:()=>({remove(){}})},
    WebApp:{initData:bridgeData,ready(){}},location:{href:pageHref,hash:new URL(pageHref).hash,assign:url=>{timeline.push('assign');if(assignThrows)throw Error('navigation_unavailable');assigned.push(url);}},
    history:{replaceState(state,title,url){const next=new URL(url,win.location.href);win.location.href=next.href;win.location.hash=next.hash;addresses.push(next.href);timeline.push('address');}},
    setTimeout(fn,ms){const id=++n;timers.set(id,fn);if((timeout&&ms===10000)||(!clientId&&ms===3000))queueMicrotask(fn);return id;},clearTimeout:id=>timers.delete(id),
    ym(...args){metrics.push(args);metricUrls.push(win.location.href);timeline.push(args[1]);if(args[1]==='getClientID'&&clientId)queueMicrotask(()=>args[2](clientId));},
    async fetch(url,options){requests.push({url,body:JSON.parse(options.body)});if(networkError)throw Error('network');if(timeout)return new Promise((_,reject)=>options.signal.addEventListener('abort',()=>reject(Error('timeout'))));return{ok:http===200,json:async()=>{decoded=true;return reply;}};}};
  if(nativeBridge)win.WebApp.openMaxLink=url=>{opened.push({url,decoded});if(nativeBridge==='throw')throw Error('bridge_unavailable');};
  if(telegram){
    win.Telegram={WebApp:win.WebApp};
    if(nativeBridge)win.Telegram.WebApp.openTelegramLink=win.WebApp.openMaxLink;
    delete win.WebApp;
  }
  return{win,els,requests,assigned,opened,metrics,metricUrls,addresses,timeline,scripts};
}
(async()=>{
  let f=fixture();await P.start(f.win);check('success redirects only after one acknowledged save',()=>{assert.equal(f.requests.length,1);assert.deepEqual(f.assigned,[accepted.redirect_url]);assert.equal(f.requests[0].body.init_data,init);});
  f=fixture({nativeBridge:true});await P.start(f.win);check('confirmed Apple/MAX paid receipt uses ordinary navigation first like MAX2',()=>{assert.deepEqual(f.opened,[]);assert.deepEqual(f.assigned,[accepted.redirect_url]);assert.equal(f.requests.length,1);});
  f=fixture({nativeBridge:true,reply:{...accepted,saved:false}});await P.start(f.win);check('failed save cannot open a native post or navigate',()=>{assert.deepEqual(f.opened,[]);assert.deepEqual(f.assigned,[]);assert.equal(f.els.get('retry').hidden,false);});
  f=fixture({nativeBridge:true,assignThrows:true});await P.start(f.win);check('ordinary navigation failure falls back to the exact native MAX link after receipt',()=>{assert.deepEqual(f.opened,[{url:accepted.redirect_url,decoded:true}]);assert.deepEqual(f.assigned,[]);assert.equal(f.els.get('continue').href,accepted.redirect_url);});
  f=fixture({nativeBridge:'throw',assignThrows:true});await P.start(f.win);check('both navigation failures retain the confirmed manual link',()=>{assert.deepEqual(f.opened,[{url:accepted.redirect_url,decoded:true}]);assert.deepEqual(f.assigned,[]);assert.equal(f.els.get('continue').href,accepted.redirect_url);assert.equal(f.els.get('continue').hidden,false);});
  f=fixture({nativeBridge:true,project:'other-company',counter:'99900011'});await P.start(f.win);check('other MAX companies retain their original init and native-first navigation',()=>{assert.equal(f.metrics[0][2].url,undefined);assert.equal(f.metrics.filter(call=>call[1]==='notBounce').length,1);assert.deepEqual(f.opened,[{url:accepted.redirect_url,decoded:true}]);assert.deepEqual(f.assigned,[]);});
  f=fixture({reply:{...accepted,saved:false}});await P.start(f.win);check('save failure shows retry and never navigates',()=>{assert.deepEqual(f.assigned,[]);assert.equal(f.els.get('retry').hidden,false);});
  f=fixture({networkError:true});await P.start(f.win);check('network failure retains a manual retry',()=>{assert.equal(f.els.get('retry').hidden,false);assert.deepEqual(f.assigned,[]);});
  f=fixture({timeout:true});await P.start(f.win);check('unknown POST timeout never assumes saved or navigates',()=>assert.deepEqual(f.assigned,[]));
  f=fixture({counter:'99900011'});await P.start(f.win);check('ClientID comes from the configured counter callback',()=>{assert.equal(f.requests[0].body.client_id,'12345678900012345678');assert.equal(f.metrics[0][0],'99900011');assert.ok(!JSON.stringify(f.metrics[0]).includes('WebAppData'));});
  check('ordinary pageview starts after the real paid URL is preserved without MAX identity',()=>{assert.equal(f.metricUrls[0],ctx.metric);assert.ok(f.timeline.indexOf('address')<f.timeline.indexOf('init'));assert.equal(new URL(f.metricUrls[0]).searchParams.get('yclid'),'18446744073709551614');assert.equal(f.metrics[0][1],'init');assert.equal(f.metrics[0][2].defer,undefined);assert.equal(f.metrics[0][2].url,ctx.metric);assert.equal(f.metrics[0][2].referrer,'');assert.deepEqual(f.metrics.map(call=>call[1]),['init','notBounce','getClientID','notBounce']);assert.equal(f.metrics.filter(call=>call[1]==='init').length,1);assert.equal(f.metrics.filter(call=>call[1]==='hit').length,0);for(const part of['WebAppData','hash','7001','startapp','ctx1'])assert.ok(!f.metricUrls[0].includes(part));assert.ok(!JSON.stringify(f.metrics).includes('WebAppData'));assert.ok(!JSON.stringify(f.metrics).includes('7001'));});
  for(const pageHref of ['https://tour-max.ru/new/appleservice39/#'+new URL(href).hash.slice(1),'https://tour-max.ru/new/appleservice39/?yclid=999&region=1&campaign=0#'+new URL(href).hash.slice(1)]){
    f=fixture({counter:'42611469',nativeBridge:true,pageHref});await P.start(f.win);
    check('branded miniapp preserves signed click and exact destination '+pageHref.split('#')[0],()=>{const actual=new URL(f.metricUrls[0]),expected=P.context(pageHref,{initData:init});assert.equal(actual.origin+actual.pathname,'https://tour-max.ru/new/appleservice39/');assert.equal(actual.searchParams.get('yclid'),'18446744073709551614');assert.equal(actual.searchParams.get('region'),'213');assert.equal(actual.searchParams.get('campaign'),'123');assert.equal(actual.hash,'');assert.equal(f.metricUrls[0],expected.metric);assert.equal(f.requests[0].url,expected.clean);assert.equal(new URL(f.requests[0].url).searchParams.has('yclid'),false);assert.equal(f.requests[0].body.init_data,init);assert.equal(f.requests[0].body.start_param,start);assert.deepEqual(f.opened,[]);assert.deepEqual(f.assigned,[accepted.redirect_url]);assert.equal(f.metrics[0][2].url,expected.metric);assert.equal(f.metrics[0][2].referrer,'');assert.deepEqual(f.timeline,['address','init','notBounce','getClientID','notBounce','assign']);assert.ok(f.metrics.every(call=>call[0]==='42611469'));assert.equal(f.scripts.filter(script=>script.src==='https://mc.yandex.ru/metrika/tag.js').length,1);});
  }
  f=fixture({counter:'42611469',pageHref:href.replace('?project=appleservice39','?project=appleservice39&startapp=post1_menu')});await P.start(f.win);
  check('conflicting launch cannot rewrite address, initialize analytics or save a visit',()=>{assert.equal(f.addresses.length,0);assert.equal(f.metrics.length,0);assert.equal(f.requests.length,0);assert.equal(f.opened.length,0);assert.equal(f.assigned.length,0);});
  f=fixture({counter:'42611469',reply:{...accepted,saved:false}});await P.start(f.win);await f.els.get('retry').listeners.click();check('retry does not start a second pageview or mark a failed save as a handoff',()=>{assert.equal(f.metrics.filter(call=>call[1]==='init').length,1);assert.equal(f.metrics.filter(call=>call[1]==='notBounce').length,1);assert.equal(f.requests.length,2);assert.deepEqual(f.assigned,[]);});
  f=fixture({counter:'99900011',clientId:''});await P.start(f.win);check('blocked analytics preserves an empty CID and the saved redirect',()=>{assert.equal(f.requests[0].body.client_id,'');assert.equal(f.assigned.length,1);});
  const organic=new URLSearchParams({auth_date:'1794300000',user:'{"id":7001}',start_param:'post1_menu',hash:'f'.repeat(64)}).toString();
  f=fixture({bridgeData:organic,reply:{ok:true,mode:'publication',saved:false,post_id:'menu',offer_id:'as39-search-v1-menu',redirect_url:cfg.channel_url+'/AaG9bSM5Y9o'},counter:'99900011'});f.win.location.href='https://app.anytoour.ru/project_funnel.php?project=appleservice39';f.win.location.hash='';await P.start(f.win);
  check('organic path redirects without Metrika or fake ad capture',()=>{assert.equal(f.assigned.length,1);assert.equal(f.metrics.length,0);assert.equal(f.requests[0].body.client_id,'');});
  const telegramOffer='as39-tg-service-display';
  const telegramToken=Buffer.from(JSON.stringify({post_id:'service-display',offer_id:telegramOffer})).toString('base64url');
  const telegramStart='18446744073709551614_region_213_campaign_123_ctx1_'+telegramToken;
  const telegramInit=new URLSearchParams({auth_date:'1794300000',user:'{"id":7001}',start_param:telegramStart,hash:'f'.repeat(64)}).toString();
  const telegramPage='https://app.anytoour.ru/project_funnel.php?project=appleservice39&platform=telegram';
  const telegramOptions={telegram:true,nativeBridge:true,bridgeData:telegramInit,counter:'42611469',pageHref:telegramPage+'#'+new URLSearchParams({tgWebAppData:telegramInit})};
  const telegramReply={...accepted,offer_id:telegramOffer,redirect_url:'https://t.me/appleservice39/32',receipt:{visit_id:'tg1_'+'a'.repeat(64),cursor:'1',reused:false}};
  f=fixture({...telegramOptions,reply:telegramReply});
  await P.start(f.win);
  check('Telegram captures the paid URL before Metrika and opens the exact post after saving',()=>{
    const tracked=new URL(f.metricUrls[0]);
    assert.equal(tracked.searchParams.get('yclid'),'18446744073709551614');
    assert.equal(tracked.searchParams.get('platform'),'telegram');
    assert.equal(tracked.hash,'');
    assert.equal(f.requests[0].url,telegramPage);
    assert.equal(f.requests[0].body.init_data,telegramInit);
    assert.deepEqual(f.opened,[{url:telegramReply.redirect_url,decoded:true}]);
    assert.deepEqual(f.timeline.slice(0,3),['address','init','getClientID']);
    assert.equal(f.metrics[0][2].url,undefined);
    assert.equal(f.metrics.filter(call=>call[1]==='notBounce').length,1);
  });
  const hostedApplePage='https://tg.rhat.ru/tgminiapple/';
  const hostedAppleLaunch=hostedApplePage+'?'+new URLSearchParams({tgWebAppStartParam:telegramStart});
  f=fixture({...telegramOptions,pageHref:hostedAppleLaunch,reply:telegramReply});
  await P.start(f.win);
  check('AppleService39 captures on the Telegram Mini App host before opening a post',()=>{
    const tracked=new URL(f.metricUrls[0]);
    assert.equal(tracked.origin+tracked.pathname,hostedApplePage);
    assert.equal(tracked.searchParams.get('yclid'),'18446744073709551614');
    assert.equal(tracked.searchParams.has('tgWebAppStartParam'),false);
    assert.equal(tracked.searchParams.has('project'),false);
    assert.equal(f.requests[0].url,hostedApplePage);
    assert.equal(f.requests[0].body.init_data,telegramInit);
    assert.deepEqual(f.opened,[{url:telegramReply.redirect_url,decoded:true}]);
    assert.deepEqual(f.timeline.slice(0,3),['address','init','getClientID']);
  });
  f=fixture({...telegramOptions,pageHref:hostedApplePage+'?tgWebAppStartParam=post1_menu',reply:telegramReply});
  await P.start(f.win);
  check('conflicting Telegram URL hint cannot start Metrika or save a visit',()=>{
    assert.equal(f.metrics.length,0);
    assert.equal(f.requests.length,0);
    assert.equal(f.opened.length,0);
  });
  f=fixture({...telegramOptions,reply:{...telegramReply,receipt:accepted.receipt}});
  await P.start(f.win);
  check('Telegram does not open the post for a MAX receipt',()=>{assert.deepEqual(f.opened,[]);assert.deepEqual(f.assigned,[]);});
  f=fixture({...telegramOptions,pageHref:hostedApplePage,http:400,reply:{ok:false,error:'invalid_launch'},bridgeData:new URLSearchParams({auth_date:'1794300000',user:'{"id":7001}',hash:'f'.repeat(64)}).toString()});
  f.els.get('funnel-config').textContent=JSON.stringify({...JSON.parse(f.els.get('funnel-config').textContent),catalogue_url:'https://t.me/appleservice39'});
  await P.start(f.win);
  check('Opening the AppleService39 bot menu displays the channel without writing a visit',()=>{
    assert.equal(f.requests.length,0);
    assert.equal(f.metrics.length,0);
    assert.equal(f.els.get('continue').href,'https://t.me/appleservice39');
    assert.equal(f.els.get('continue').hidden,false);
    assert.equal(f.els.get('retry').hidden,true);
  });
  console.log('PROJECT FUNNEL CLIENT PASSED '+passed);
})().catch(e=>{console.error(e);process.exitCode=1;});
