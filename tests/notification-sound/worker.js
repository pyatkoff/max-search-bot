if(!['127.0.0.1','localhost'].includes(self.location.hostname))throw new Error('synthetic_fixture_only');
// Synthetic service-worker harness: real sound coordination, no push sent,
// no OS permission prompt or external subscription created.
const nativeImport=self.importScripts.bind(self),nativeListen=self.addEventListener.bind(self);
const captured={},shown=[];
self.importScripts=(...urls)=>nativeImport(...urls.map(url=>url.startsWith('assets/')?'../../manager/'+url:url));
self.addEventListener=(type,handler)=>{captured[type]=handler;};
self.registration.showNotification=async(title,options)=>{shown.push({title,options});};
nativeImport('../../manager/sw.js');
self.addEventListener=nativeListen;
nativeListen('install',event=>event.waitUntil(self.skipWaiting()));
nativeListen('activate',event=>event.waitUntil(self.clients.claim()));
nativeListen('message',event=>{
  if(event.data?.type==='TEST_PUSH'){
    let work=Promise.resolve();
    captured.push({data:{json:()=>event.data.payload},waitUntil:promise=>{work=promise;}});
    event.waitUntil(work.then(()=>event.ports[0].postMessage({shown})).catch(error=>event.ports[0].postMessage({error:String(error)})));
  }else captured.message?.(event);
});
