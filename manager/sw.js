importScripts('assets/workspace-v2-sound-ledger.js');

self.addEventListener('install',event=>{self.skipWaiting();});
// Activation must finish without waiting on the subscription lock or push service.
// The authenticated page owns all versioned subscription persistence.
self.addEventListener('activate',event=>{event.waitUntil(self.clients.claim());});

// Old worker messages remain harmless. Only the authenticated page can capture
// a browser installation's current intent; a worker must never revive an endpoint.
function syncPushSubscription(){return Promise.resolve(false);}

async function show(data){
  const title=data.title||'AnyTour — новое сообщение';
  const options={
    body:data.body||'Клиент написал в диалог',
    tag:data.tag||('anytour-manager-'+Number(data.conversationId||0)),
    renotify:true,
    data:{conversationId:Number(data.conversationId||0),url:data.url||'./'},
    icon:data.icon||undefined,
    badge:data.badge||undefined
  };
  const arrival={conversation_id:Number(data.conversationId||0),message_id:Number(data.incomingMessageId||0)};
  const managerId=Number(data.notificationManagerId||0);
  if(data.notificationKind==='customer_message'&&Number.isSafeInteger(managerId)&&managerId>0&&WorkspaceSoundLedger.validEvent(arrival)&&WorkspaceSoundLedger.supported()){
    let displayed=false;
    try{
      // A visible, explicitly enabled page may handle audio first. Its own feed
      // rechecks identity/access; the push payload never opens or reads a chat.
      const clients=await self.clients.matchAll({type:'window',includeUncontrolled:true});
      await Promise.all(clients.filter(client=>client.visibilityState==='visible').slice(0,8).map(client=>new Promise(resolve=>{
        const channel=new MessageChannel();
        const timer=setTimeout(()=>{channel.port1.close();resolve();},1000);
        channel.port1.onmessage=()=>{clearTimeout(timer);channel.port1.close();resolve();};
        client.postMessage({type:'CHECK_NOTIFICATION_SOUND',managerId},[channel.port2]);
      })));
      return await WorkspaceSoundLedger.run(managerId,async ledger=>{
        if(WorkspaceSoundLedger.seen(ledger,arrival))options.silent=true;
        const result=await self.registration.showNotification(title,options);displayed=true;
        WorkspaceSoundLedger.mark(ledger,[arrival]);return result;
      });
    }catch(error){if(displayed)return;/* Preserve OS fallback, but never display twice after a ledger write failure. */}
  }
  return self.registration.showNotification(title,options);
}

self.addEventListener('message',event=>{
  const data=event.data||{};
  if(data.type==='SOUND_CAPABILITIES'){event.ports?.[0]?.postMessage({soundLedger:WorkspaceSoundLedger.supported()?1:0});return;}
  if(data.type==='SYNC_PUSH'){
    event.waitUntil(syncPushSubscription().catch(()=>false));
    return;
  }
  if(data.type!=='SHOW_NOTIFICATION')return;
  event.waitUntil(Promise.allSettled([syncPushSubscription(),show(data)]));
});

self.addEventListener('push',event=>{
  let data={};
  try{data=event.data?event.data.json():{};}catch(e){data={body:event.data?event.data.text():'Новое сообщение'};}
  event.waitUntil(show(data));
});

self.addEventListener('notificationclick',event=>{
  event.notification.close();
  const data=event.notification.data||{};
  const parsedId=Number(data.conversationId||0);
  const conversationId=Number.isSafeInteger(parsedId)&&parsedId>0?parsedId:0;
  const base=new URL('./',self.location.href),alternate=new URL('../manager2/',base);
  const paths=[base.pathname,base.pathname+'index.php',alternate.pathname,alternate.pathname+'index.php'];
  const workspaceUrl=value=>{try{const url=new URL(value,base);return url.origin===base.origin&&paths.includes(url.pathname)?url:null;}catch(error){return null;}};
  const targetUrl=workspaceUrl(data.url||'./')||new URL(base.href);
  if(conversationId)targetUrl.hash='conversation='+conversationId;
  const target=targetUrl.href;
  event.waitUntil((async()=>{
    const list=await self.clients.matchAll({type:'window',includeUncontrolled:true}).catch(()=>[]);
    for(const client of list){
      if(!workspaceUrl(client.url)||typeof client.focus!=='function')continue;
      try{
        const focused=await client.focus();
        if(focused&&!workspaceUrl(focused.url))continue;
        if(conversationId)client.postMessage({type:'OPEN_CONVERSATION',conversationId});
        return;
      }catch(error){/* A closed or unfocusable window cannot consume this click. */}
    }
    if(self.clients.openWindow)return self.clients.openWindow(target);
  })());
});
