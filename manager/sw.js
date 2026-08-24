self.addEventListener('install',event=>{self.skipWaiting();});
self.addEventListener('activate',event=>{event.waitUntil(self.clients.claim());});

self.addEventListener('message',event=>{
  const data=event.data||{};
  if(data.type!=='SHOW_NOTIFICATION')return;
  const title=data.title||'AnyTour — новое сообщение';
  const options={
    body:data.body||'Клиент написал в диалог',
    tag:data.tag||'anytour-manager',
    renotify:true,
    data:{conversationId:Number(data.conversationId||0),url:data.url||'./'},
    icon:data.icon||undefined,
    badge:data.badge||undefined
  };
  event.waitUntil(self.registration.showNotification(title,options));
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
