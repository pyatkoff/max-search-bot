(function(){
'use strict';
let managerId=0,authGeneration=0,operation=0,enabled=false,audio=null,timer=null,busy=false,note='';
const state=()=>window.WorkspaceV2?.S;
const current=()=>managerId>0 && Number(state()?.manager?.id)===managerId && state()?.authGeneration===authGeneration && !state()?.authExpired;
const visible=()=>document.visibilityState!=='hidden';
function timeout(promise,ms){let timer;return Promise.race([promise,new Promise((_,reject)=>{timer=setTimeout(()=>reject(new Error('sound_timeout')),ms);})]).finally(()=>clearTimeout(timer));}
function controls(){
  let root=document.getElementById('soundStatus');
  if(root)return root;
  const anchor=document.getElementById('notificationStatus');if(!anchor)return null;
  root=document.createElement('div');root.id='soundStatus';root.className='workspaceSound';
  const toggle=document.createElement('button');toggle.id='soundToggle';toggle.type='button';toggle.onclick=()=>enabled?disable():enable();
  const test=document.createElement('button');test.id='soundTest';test.type='button';test.textContent='Проверить звук';test.onclick=()=>testSound();
  const status=document.createElement('span');status.id='soundMessage';status.setAttribute('role','status');
  root.append(toggle,test,status);anchor.insertAdjacentElement('afterend',root);return root;
}
function render(){
  if(!controls())return;
  const toggle=document.getElementById('soundToggle');
  toggle.textContent=busy?'Включаем…':enabled?'Выключить звук':'Включить звук';
  toggle.setAttribute('aria-pressed',String(enabled));toggle.disabled=busy||!current();
  document.getElementById('soundTest').disabled=busy||!current();
  document.getElementById('soundMessage').textContent=note||(enabled?'Звук включён в этой вкладке. В фоне — системные уведомления.':'Звук выключен. Включите и проверьте громкость устройства.');
}
function stopAudio(){const old=audio;audio=null;if(old){old.onstatechange=null;try{void old.close().catch(()=>{});}catch(e){}}}
function suspend(){operation++;enabled=false;busy=false;note='Звук выключен. После входа включите его снова.';stopAudio();render();}
function activate(id){
  id=Number(id||0);
  if(id!==managerId||state()?.authGeneration!==authGeneration){operation++;enabled=false;busy=false;note='';stopAudio();}
  managerId=id;authGeneration=state()?.authGeneration;render();
  if(!timer)timer=setInterval(()=>{void poll();},10000);
}
function disable(){operation++;enabled=false;busy=false;note='';stopAudio();render();}
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
    ctx.onstatechange=()=>{if(audio===ctx&&enabled&&ctx.state!=='running'){enabled=false;note='Браузер приостановил звук. Нажмите «Включить звук».';render();}};
  }
  // resume is invoked directly from the manager's click, not from a timer.
  await timeout(audio.resume(),1500);
  if(audio.state!=='running')throw new Error('sound_blocked');
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
  return 'Браузер не разрешил звук. Повторите включение и проверьте настройки звука сайта.';
}
async function enable(){
  if(busy||!current())return false;
  const seq=++operation;busy=true;note='';render();
  try{
    const unlocking=unlock();
    if(!window.WorkspaceSoundLedger?.supported()){await unlocking;throw new Error('sound_coordination_unavailable');}
    await unlocking;await workerReady();
    if(seq!==operation||!current())return false;
    enabled=true;if(!cue())throw new Error('sound_blocked');
    note='Тестовый сигнал запущен. Новые входящие будут звучать в открытой вкладке.';
    await poll(true);return enabled;
  }catch(e){if(seq===operation){enabled=false;note=failure(e);}return false;}
  finally{if(seq===operation){busy=false;render();}}
}
async function testSound(){
  if(busy||!current())return false;
  const seq=++operation;busy=true;render();
  try{await unlock();if(seq!==operation||!current())return false;if(!cue())throw new Error('sound_blocked');note='Тестовый сигнал запущен. Если не слышно — проверьте громкость и беззвучный режим.';return true;}
  catch(e){if(seq===operation)note=failure(e);return false;}
  finally{if(seq===operation){busy=false;render();}}
}
async function poll(force=false){
  if(!enabled||!current()||!visible())return false;
  const owner=managerId,generation=authGeneration,seq=operation;
  const active=()=>enabled&&current()&&visible()&&owner===managerId&&generation===authGeneration&&seq===operation;
  try{
    return await WorkspaceSoundLedger.run(owner,async ledger=>{
      if(!active())return false;
      if(audio?.state!=='running'){enabled=false;note='Браузер приостановил звук. Нажмите «Включить звук».';render();return false;}
      const now=Date.now();if(!force&&now-ledger.pollAt<9000)return false;
      const cursor=now-ledger.pollAt>30000?null:ledger.cursor;
      const result=await timeout(window.WorkspaceV2.notificationEvents(cursor),8000);
      if(!active())return false;
      if(!result?.ok||result.manager_id!==owner||!Number.isSafeInteger(result.cursor)||result.cursor<0||!Array.isArray(result.events)||result.events.length>500||result.has_more===true)throw new Error('sound_feed_unavailable');
      if(result.events.some(event=>!WorkspaceSoundLedger.validEvent(event)))throw new Error('sound_feed_unavailable');
      const events=cursor===null?[]:result.events.filter(event=>!WorkspaceSoundLedger.seen(ledger,event));
      if(events.length){
        if(now-ledger.lastTone>=3000){if(!cue()){enabled=false;note='Не удалось воспроизвести сигнал. Включите звук снова.';render();return false;}ledger.lastTone=Date.now();}
        WorkspaceSoundLedger.mark(ledger,events);
      }
      if(cursor===null)WorkspaceSoundLedger.mark(ledger,result.events);
      ledger.cursor=result.cursor;ledger.pollAt=Date.now();note='';render();return events.length>0;
    },{ifAvailable:!force});
  }catch(e){if(active()){if(String(e?.message||'').startsWith('sound_storage')){enabled=false;note=failure(e);}else note='Нет связи с уведомлениями: звук новых сообщений временно недоступен.';render();}return false;}
}
navigator.serviceWorker?.addEventListener('message',event=>{
  if(event.data?.type!=='CHECK_NOTIFICATION_SOUND')return;
  if(event.data.managerId!==managerId||!enabled||!current()||!visible()){event.ports?.[0]?.postMessage({ok:false});return;}
  void poll(true).then(ok=>event.ports?.[0]?.postMessage({ok})).catch(()=>event.ports?.[0]?.postMessage({ok:false}));
});
document.addEventListener('visibilitychange',()=>{if(visible())void poll();});
window.addEventListener('pagehide',suspend);
window.WorkspaceV2Sound={activate,suspend,enable,disable,testSound,poll};
})();
