(function(){
'use strict';
let managerId=0,authGeneration=0,operation=0,enabled=false,wanted=false,audio=null,timer=null,busy=false,note='';
let paused=true,baseline=true,storageFailed=false;
const preferencePrefix='anytour.workspaceSound.enabled.v1:',unsaved=new Map();
const state=()=>window.WorkspaceV2?.S;
const current=()=>!paused&&Number.isSafeInteger(managerId)&&managerId>0 && Number(state()?.manager?.id)===managerId && state()?.authGeneration===authGeneration && !state()?.authExpired;
const visible=()=>document.visibilityState!=='hidden';
function timeout(promise,ms){let timer;return Promise.race([promise,new Promise((_,reject)=>{timer=setTimeout(()=>reject(new Error('sound_timeout')),ms);})]).finally(()=>clearTimeout(timer));}
function controls(){
  let root=document.getElementById('soundStatus');
  if(root)return root;
  const anchor=document.getElementById('notificationStatus');if(!anchor)return null;
  root=document.createElement('div');root.id='soundStatus';root.className='workspaceSound';
  const toggle=document.createElement('button');toggle.id='soundToggle';toggle.type='button';toggle.onclick=()=>wanted?disable():enable();
  const test=document.createElement('button');test.id='soundTest';test.type='button';test.textContent='Проверить звук';test.onclick=()=>testSound();
  const status=document.createElement('span');status.id='soundMessage';status.setAttribute('role','status');
  root.append(toggle,test,status);anchor.insertAdjacentElement('afterend',root);return root;
}
// Only an account-scoped boolean is persisted. No session, transcript or credentials.
function preferenceKey(){return preferencePrefix+managerId;}
function readPreference(){
  storageFailed=false;
  if(unsaved.has(managerId)){storageFailed=true;return unsaved.get(managerId);}
  try{return localStorage.getItem(preferenceKey())==='1';}
  catch(e){storageFailed=true;return false;}
}
function savePreference(value){
  try{localStorage.setItem(preferenceKey(),value?'1':'0');unsaved.delete(managerId);storageFailed=false;}
  catch(e){unsaved.set(managerId,value);storageFailed=true;while(unsaved.size>32)unsaved.delete(unsaved.keys().next().value);}
}
function waitingNote(){return 'Звук включён в настройках. Браузер ожидает нажатие в кабинете.';}
function render(){
  const root=controls();if(!root)return;
  const active=current(),toggle=document.getElementById('soundToggle');
  toggle.textContent=wanted?'Выключить звук':'Включить звук';
  // The toggle describes the saved choice; data-state/status describe readiness.
  toggle.setAttribute('aria-pressed',String(active&&wanted));toggle.disabled=!active;
  document.getElementById('soundTest').disabled=busy||!active;
  root.setAttribute('data-state',!active?'signed-out':!wanted?'off':enabled?'ready':busy?'starting':'waiting');
  const message=!active?'Звук приостановлен до входа. Настройка сохранена.':note||(wanted?(enabled?'Звук включён. В фоне — системные уведомления.':waitingNote()):'Звук выключен. Включите и проверьте громкость устройства.');
  document.getElementById('soundMessage').textContent=message+(storageFailed?' Не удалось сохранить настройку в браузере; выбор действует только на этой странице.':'');
}
function stopAudio(){const old=audio;audio=null;if(old){old.onstatechange=null;try{void old.close().catch(()=>{});}catch(e){}}}
function resetRuntime(){operation++;enabled=false;busy=false;baseline=true;note='';stopAudio();}
function suspend(){paused=true;resetRuntime();render();}
function activate(id){
  id=Number(id||0);
  if(paused||id!==managerId||state()?.authGeneration!==authGeneration)resetRuntime();
  managerId=id;authGeneration=state()?.authGeneration;paused=false;
  wanted=current()?readPreference():false;render();
  if(!timer)timer=setInterval(()=>{void poll();},10000);
  return wanted?start(false):Promise.resolve(false);
}
function disable(){if(!current())return;wanted=false;savePreference(false);resetRuntime();render();}
function cue(){
  if(!current()||!visible()||audio?.state!=='running')return false;
  try{
    const oscillator=audio.createOscillator(),gain=audio.createGain(),now=audio.currentTime;
    oscillator.type='sine';oscillator.frequency.setValueAtTime(880,now);oscillator.frequency.setValueAtTime(660,now+0.08);
    gain.gain.setValueAtTime(0,now);gain.gain.linearRampToValueAtTime(0.07,now+0.015);gain.gain.exponentialRampToValueAtTime(0.001,now+0.22);
    oscillator.connect(gain);gain.connect(audio.destination);
    oscillator.onended=()=>{oscillator.disconnect();gain.disconnect();};oscillator.start(now);oscillator.stop(now+0.23);return true;
  }catch(e){return false;}
}
async function unlock(){
  const Ctor=window.AudioContext||window.webkitAudioContext;
  if(!Ctor)throw new Error('sound_unsupported');
  if(!audio||audio.state==='closed'){
    audio=new Ctor();const ctx=audio;
    ctx.onstatechange=()=>{
      if(audio!==ctx||!current()||!wanted)return;
      if(ctx.state!=='running'){enabled=false;baseline=true;note=waitingNote();render();}
      else if(!enabled&&!busy)void start(false);
    };
  }
  const ctx=audio;
  // Browsers may allow restoration immediately. Otherwise a trusted normal
  // interaction retries resume on this context; no synthetic activation is used.
  await timeout(ctx.resume(),1500);
  if(ctx!==audio||ctx.state!=='running')throw new Error('sound_blocked');
}
async function workerReady(){
  // When OS push is enabled, an old worker must not duplicate page audio.
  if(!('Notification'in window)||Notification.permission!=='granted')return;
  const worker=navigator.serviceWorker?.controller;
  if(!worker)throw new Error('sound_worker_reload');
  const channel=new MessageChannel();
  try{
    const reply=new Promise(resolve=>{channel.port1.onmessage=event=>resolve(event.data?.soundLedger===1);});
    worker.postMessage({type:'SOUND_CAPABILITIES'},[channel.port2]);
    if(!await timeout(reply,1500))throw new Error('sound_worker_reload');
  }catch(e){throw new Error('sound_worker_reload');}
  finally{channel.port1.close();}
}
function failure(error){
  if(error?.message==='sound_worker_reload')return 'Обновите кабинет: подготовка звука уведомлений ещё не завершена.';
  if(error?.message==='sound_coordination_unavailable')return 'В этом браузере звук кабинета недоступен. Системные уведомления остаются отдельными.';
  if(String(error?.message||'').startsWith('sound_storage'))return 'Не удалось подготовить защиту от повторных сигналов. Обновите страницу.';
  return wanted?'Настройка звука сохранена, но браузер не разрешил звук. Нажмите в кабинете для возобновления.':'Браузер не разрешил звук. Проверьте настройки звука сайта.';
}
async function start(testCue=false){
  if(busy||!wanted||!current()||!visible())return false;
  if(enabled){
    if(!testCue)return true;
    const played=cue();if(!played){enabled=false;baseline=true;}
    note=played?'Тестовый сигнал запущен. Проверьте громкость устройства.':waitingNote();render();return played;
  }
  const seq=++operation;busy=true;note=waitingNote();render();
  try{
    if(!window.WorkspaceSoundLedger?.supported())throw new Error('sound_coordination_unavailable');
    await unlock();
    if(seq!==operation||!wanted||!current()||!visible())return false;
    await workerReady();
    if(seq!==operation||!wanted||!current()||!visible())return false;
    enabled=true;
    // Only an explicit enable/test click plays the test cue, never restoration.
    if(testCue&&!cue())throw new Error('sound_blocked');
    await poll(true);return enabled;
  }catch(e){if(seq===operation){enabled=false;note=failure(e);}return false;}
  finally{if(seq===operation){busy=false;render();}}
}
async function enable(){
  if(!current())return false;
  if(!wanted){wanted=true;savePreference(true);baseline=true;}
  return start(true);
}
async function testSound(){
  if(busy||!current())return false;
  if(wanted)return start(true);
  const seq=++operation;busy=true;render();
  try{await unlock();if(seq!==operation||!current())return false;if(!cue())throw new Error('sound_blocked');note='Тестовый сигнал запущен. Если не слышно — проверьте громкость и беззвучный режим.';return true;}
  catch(e){if(seq===operation)note=failure(e);return false;}
  finally{if(seq===operation){busy=false;render();}}
}
function resumeFromInteraction(event){
  if(!event.isTrusted||!wanted||!current()||!visible()||enabled)return;
  if(event.target?.closest?.('#soundStatus'))return;
  if(event.type==='keydown'&&(event.repeat||event.ctrlKey||event.metaKey||event.altKey||event.key==='Escape'))return;
  // An automatic resume may still be pending when the first real tap arrives.
  // Retry synchronously in that gesture so it is not lost to the pending task.
  if(busy){try{void audio?.resume().catch(()=>{});}catch(e){}return;}
  void start(false);
}
async function poll(force=false){
  if(!wanted||!enabled||!current()||!visible())return false;
  const owner=managerId,generation=authGeneration,seq=operation;
  const active=()=>wanted&&enabled&&current()&&visible()&&owner===managerId&&generation===authGeneration&&seq===operation;
  try{
    return await WorkspaceSoundLedger.run(owner,async ledger=>{
      if(!active())return false;
      if(audio?.state!=='running'){enabled=false;baseline=true;note=waitingNote();render();return false;}
      const now=Date.now();if(!force&&now-ledger.pollAt<9000)return false;
      const cursor=baseline||now-ledger.pollAt>30000?null:ledger.cursor;
      const result=await timeout(window.WorkspaceV2.notificationEvents(cursor),8000);
      if(!active())return false;
      if(!result?.ok||result.manager_id!==owner||!Number.isSafeInteger(result.cursor)||result.cursor<0||!Array.isArray(result.events)||result.events.length>500||result.has_more===true)throw new Error('sound_feed_unavailable');
      if(result.events.some(event=>!WorkspaceSoundLedger.validEvent(event)))throw new Error('sound_feed_unavailable');
      const events=cursor===null?[]:result.events.filter(event=>!WorkspaceSoundLedger.seen(ledger,event));
      if(events.length){
        if(now-ledger.lastTone>=3000){if(!cue()){enabled=false;baseline=true;note=waitingNote();render();return false;}ledger.lastTone=Date.now();}
        WorkspaceSoundLedger.mark(ledger,events);
      }
      if(cursor===null)WorkspaceSoundLedger.mark(ledger,result.events);
      ledger.cursor=result.cursor;ledger.pollAt=Date.now();baseline=false;note='';render();return events.length>0;
    },{ifAvailable:!force});
  }catch(e){if(active()){if(String(e?.message||'').startsWith('sound_storage')){enabled=false;note=failure(e);}else note='Нет связи с уведомлениями: звук новых сообщений временно недоступен.';render();}return false;}
}
navigator.serviceWorker?.addEventListener('message',event=>{
  if(event.data?.type!=='CHECK_NOTIFICATION_SOUND')return;
  if(event.data.managerId!==managerId||!enabled||!current()||!visible()){event.ports?.[0]?.postMessage({ok:false});return;}
  void poll(true).then(ok=>event.ports?.[0]?.postMessage({ok})).catch(()=>event.ports?.[0]?.postMessage({ok:false}));
});
document.addEventListener('click',resumeFromInteraction,true);
document.addEventListener('keydown',resumeFromInteraction,true);
document.addEventListener('visibilitychange',()=>{if(visible()&&current()&&wanted){if(enabled)void poll();else void start(false);}});
navigator.serviceWorker?.addEventListener('controllerchange',()=>{if(wanted&&current()&&!enabled)void start(false);});
window.addEventListener('storage',event=>{
  if(!current()||(event.key!==null&&event.key!==preferenceKey()))return;
  try{if(event.storageArea!==localStorage)return;}catch(e){return;}
  unsaved.delete(managerId);
  const next=readPreference();if(next===wanted){render();return;}
  resetRuntime();wanted=next;render();if(wanted)void start(false);
});
window.addEventListener('pageshow',event=>{
  // A restored page must revalidate identity through the existing auth owner.
  if(event.persisted&&paused)void window.WorkspaceV2?.boot?.();
});
window.addEventListener('pagehide',suspend);
window.WorkspaceV2Sound={activate,suspend,enable,disable,testSound,poll};
})();
