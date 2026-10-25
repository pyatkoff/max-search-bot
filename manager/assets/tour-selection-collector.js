(function(){
'use strict';
const config=JSON.parse(document.getElementById('collectorConfig').textContent),$=id=>document.getElementById(id);
let device='',nonce='',target=null,connection=null,busy=false,epoch=0;
const id=()=>Array.from(crypto.getRandomValues(new Uint8Array(16)),x=>x.toString(16).padStart(2,'0')).join('');
const errors={unauthorized:'Вход Manager2 завершён. Войдите снова.',csrf:'Сессия изменилась. Обновите страницу после входа.',forbidden:'Сейчас у вас нет права изменять эту подборку.',not_found:'Подборка недоступна.',trip_changed:'Клиент или поездка изменились. Обновите данные и подтвердите подключение.',connection_changed:'Подключение отозвано, истекло или относится к другой сессии. Подключите подборку заново.',source_price_changed:'У источника другая цена. Существующая ручная цена сохранена.',ambiguous_offer:'Похожее предложение уже есть. Тождество рейсов и тарифа не установлено.',selection_full:'В подборке уже 20 предложений.',invalid_import:'Страница или данные предложения изменились. Импорт остановлен.',network_error:'Нет подтверждения сервера. Очередь сохранена; результат можно проверить.',storage_unavailable:'Хранилище временно недоступно. Очередь сохранена.'};
function status(s,error=false){$('collectorStatus').textContent=s;$('collectorStatus').classList.toggle('error',error)}
errors.manual_price_conflict='Тур уже есть с другой ручной ценой. Откройте подборку и проверьте её: прежняя цена не изменена.';
function controls(){$('collectorConnect').disabled=busy||!device||!target||!$('collectorConfirm').checked;$('collectorRevoke').disabled=busy||!connection;$('collectorRefresh').disabled=busy}
function emit(type,data={}){document.dispatchEvent(new CustomEvent('anytour-collector-page',{detail:{type,nonce,...(type==='connected'?{features:{manager_price:1}}:{}),...data}}))}
async function request(action,data={}){return ManagerHttpClient.request(action,{conversation_id:config.conversation_id,...data},config.csrf,'tour-selections-api.php')}
function render(){
 const root=$('collectorTarget'),open=$('collectorOpenSelection');root.replaceChildren();open.href='/manager2/';open.textContent='← В Manager2';if(!target)return;
 const h=document.createElement('h2');h.textContent=target.client;root.append(h);
 open.href='/manager2/?selection='+target.conversation_id+'&collector_actor='+config.actor+'#conversation='+target.conversation_id;open.textContent='← Открыть эту подборку';
 for(const text of [target.channel+' · '+target.trip,'Диалог с '+target.started_at,(target.dates.length?'Даты в подборке: '+target.dates.join(', '):'В подборке пока нет дат — проверьте текущую поездку.'),'На сервере: '+target.count+' предложений']){const p=document.createElement('p');p.textContent=text;root.append(p)}
 controls();
}
async function refresh(){const token=++epoch;busy=true;controls();const r=await request('collector_get',{device_id:device});if(token!==epoch)return;busy=false;
 if(!r.ok||r.target?.actor!==config.actor||r.target?.conversation_id!==config.conversation_id){target=null;connection=null;render();controls();status(errors[r.error]||'Не удалось прочитать подборку.',true);emit('disconnected');return}
 target=r.target;connection=r.connection;render();
 if(connection){status('Подключено. Новые предложения сохранятся для показанного клиента.');emit('connected',{target,connection,selected:false})}
 else status(device?'Проверьте клиента и текущую поездку, затем подключите подборку.':'Откройте расширение AnyTour. Подборка ещё не подключена.');
}
$('collectorConfirm').addEventListener('change',controls);$('collectorRefresh').onclick=refresh;
$('collectorConnect').onclick=async()=>{if(busy||!target||!device||!$('collectorConfirm').checked)return;busy=true;controls();const token=++epoch;const r=await request('collector_connect',{device_id:device,connection_id:id(),binding:target.binding,confirm_current_trip:true});if(token!==epoch)return;busy=false;if(!r.ok){status(errors[r.error]||'Не удалось подключить подборку.',true);controls();return}target=r.target;connection=r.connection;render();status('Подключено. Можно добавлять поддерживаемые предложения FUN&SUN, ANEX и Tourvisor.');emit('connected',{target,connection,selected:true})};
$('collectorRevoke').onclick=async()=>{if(busy)return;busy=true;controls();++epoch;const r=await request('collector_revoke');busy=false;if(!r.ok){status(errors[r.error]||'Не удалось подтвердить отключение.',true);controls();return}connection=null;$('collectorConfirm').checked=false;controls();status('Доступ сборщика отозван. Уже сохранённые предложения остались в подборке.');emit('disconnected')};
document.addEventListener('anytour-collector-extension',async event=>{
 const m=event.detail;if(!m||typeof m!=='object')return;
 if(m.type==='hello'&&/^[a-f0-9]{32}$/.test(m.device||'')&&/^[a-f0-9]{32}$/.test(m.nonce||'')){device=m.device;nonce=m.nonce;await refresh();return}
 if(m.type!=='command'||m.nonce!==nonce||busy||!connection||!target||m.actor!==config.actor||m.conversation_id!==config.conversation_id||m.connection_id!==connection.id||!['import','operation'].includes(m.action)||!/^[a-f0-9]{32}$/.test(m.request_id||''))return;
 const token=epoch;let r=await request(m.action==='import'?'collector_import':'collector_operation',{operation_id:m.operation_id,connection_id:connection.id,observation:m.observation,as_variant:m.as_variant===true,...(Object.hasOwn(m,'manager_amount')?{manager_amount:m.manager_amount}:{})});
 if(token!==epoch)return;
 const result={ok:r.ok===true,error:r.error,found:r.found,state:r.state,saved_version:r.saved_version,count:r.count??r.selection?.draft?.offers?.length,existing_offer_id:r.existing_offer_id,...(Object.hasOwn(r,'manager_amount')?{manager_amount:r.manager_amount}:{})};
 if(r.ok){if(Number.isInteger(result.count)){target.count=result.count;render();emit('connected',{target,connection,selected:false})}status(m.action==='operation'?'Результат операции проверен.':r.state==='already_added'?'Это предложение уже добавлено.':'Предложение сохранено в черновике. Отправки туристу не было.')}
 else{status(errors[r.error]||'Не удалось подтвердить добавление.',true);if(['unauthorized','csrf','forbidden','not_found','connection_changed'].includes(r.error)){connection=null;controls();emit('disconnected')}}
 emit('result',{request_id:m.request_id,result});
});
void refresh();document.dispatchEvent(new CustomEvent('anytour-collector-page-ready'));
})();
