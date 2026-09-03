self.addEventListener('install',event=>{self.skipWaiting();});
self.addEventListener('activate',event=>{event.waitUntil((async()=>{await self.clients.claim();try{await syncPushSubscription();}catch(e){}})());});

function b64ToUint8(base64String){
  const padding='='.repeat((4-base64String.length%4)%4);
  const base64=(base64String+padding).replace(/-/g,'+').replace(/_/g,'/');
  const raw=atob(base64);
  return Uint8Array.from([...raw].map(c=>c.charCodeAt(0)));
}

function sameBytes(left,right){
  if(!left||!right)return false;
  const a=new Uint8Array(left),b=new Uint8Array(right);
  if(a.length!==b.length)return false;
  for(let i=0;i<a.length;i++){if(a[i]!==b[i])return false;}
  return true;
}

async function syncPushSubscription(){
  const keyResp=await fetch('push.php?action=key',{credentials:'same-origin',cache:'no-store'});
  if(!keyResp.ok)throw new Error('push_key_http_'+keyResp.status);
  const keyJson=await keyResp.json();
  if(!keyJson.ok||!keyJson.public_key||!keyJson.csrf)throw new Error('push_key_missing');
  const key=b64ToUint8(keyJson.public_key);
  let sub=await self.registration.pushManager.getSubscription();
  if(sub&&sub.options&&sub.options.applicationServerKey&&!sameBytes(sub.options.applicationServerKey,key)){
    await sub.unsubscribe();
    sub=null;
  }
  if(!sub)sub=await self.registration.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:key});
  const save=await fetch('push.php',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({action:'subscribe',csrf:keyJson.csrf,subscription:sub.toJSON()})});
  if(!save.ok)throw new Error('push_save_http_'+save.status);
  const saved=await save.json();
  if(!saved.ok)throw new Error('push_save_failed');
  return true;
}

function show(data){
  const title=data.title||'AnyTour — новое сообщение';
  const options={
    body:data.body||'Клиент написал в диалог',
    tag:data.tag||('anytour-manager-'+Number(data.conversationId||0)),
    renotify:true,
    data:{conversationId:Number(data.conversationId||0),url:data.url||'./'},
    icon:data.icon||undefined,
    badge:data.badge||undefined
  };
  return self.registration.showNotification(title,options);
}

self.addEventListener('message',event=>{
  const data=event.data||{};
  if(data.type==='SYNC_PUSH'){
    event.waitUntil(syncPushSubscription().catch(()=>false));
    return;
  }
  if(data.type!=='SHOW_NOTIFICATION')return;
  event.waitUntil((async()=>{try{await syncPushSubscription();}catch(e){}await show(data);})());
});

self.addEventListener('push',event=>{
  let data={};
  try{data=event.data?event.data.json():{};}catch(e){data={body:event.data?event.data.text():'Новое сообщение'};}
  event.waitUntil(show(data));
});

self.addEventListener('notificationclick',event=>{
  event.notification.close();
  const data=event.notification.data||{};
  const conversationId=Number(data.conversationId||0);
  const target=(data.url||'./')+(conversationId?('#conversation='+conversationId):'');
  event.waitUntil((async()=>{
    const list=await self.clients.matchAll({type:'window',includeUncontrolled:true});
    for(const client of list){
      if('focus' in client){
        await client.focus();
        client.postMessage({type:'OPEN_CONVERSATION',conversationId});
        return;
      }
    }
    if(self.clients.openWindow)return self.clients.openWindow(target);
  })());
});
