(function(){
'use strict';const $=s=>document.getElementById(s),text=(tag,value)=>{const e=document.createElement(tag);e.textContent=value;return e};
const labels={destination:'Проверьте клиента перед добавлением',queued:'Сохраняем…',waiting:'Очередь сохранена. Нет подтверждения сервера.',review:'Нужна проверка похожего предложения',blocked:'Подключение или условия изменились',saved:'Сохранено в подборке'};
const errors={source_price_changed:'В источнике другая цена. Существующая цена и ручные правки не изменены.',ambiguous_offer:'Тождество тарифа и рейсов не доказано. Можно явно добавить отдельный вариант.',connection_changed:'Подключите эту же поездку заново в Manager2.',bridge_unavailable:'Нужна открытая вкладка сборщика Manager2.',invalid_import:'Шаблон или условия источника не прошли проверку.'};
let rendering=false;
async function command(type,operation_id){$('notice').textContent='';const r=await chrome.runtime.sendMessage({type,operation_id});if(!r.ok)$('notice').textContent='Не удалось подтвердить действие. Клиент или состояние очереди изменились.';await render()}
async function render(){if(rendering)return;rendering=true;try{const s=await chrome.runtime.sendMessage({type:'status'});$('target').replaceChildren();if(s.target){$('target').append(text('h2',s.target.client),text('p',s.target.channel+' · '+s.target.trip),text('p','В подборке: '+s.target.count));}else $('target').append(text('p','Подборка пока не подключена.'));
 const root=$('items');root.replaceChildren();for(const item of s.items.slice().reverse()){const box=document.createElement('article');box.className='item';const o=item.observation.offer;box.append(text('h2',o.hotel),text('p',o.date_from+' · '+o.nights+' ночей · '+o.room),text('p',o.amount+' '+o.currency+' за весь состав'),text('p','Клиент: '+item.target.client+' · '+item.target.trip),text('p',labels[item.state]||'Требуется проверка'));if(item.error){const e=text('p',errors[item.error]||item.error);e.className='error';box.append(e)}
 const button=(label,type)=>{const b=text('button',label);b.onclick=()=>command(type,item.operation_id);box.append(b)};
 if(item.state==='destination')button('Добавить для этого клиента','confirm');
 if(item.state==='waiting')button('Проверить и продолжить','retry');
 if(item.state==='review')button('Добавить отдельным вариантом','variant');
 if(item.state==='blocked')button('Продолжить с новым подключением этой поездки','reconnect');
 if(item.state!=='queued'){const b=text('button',item.state==='saved'?'Убрать из списка':'Убрать из очереди');b.className='secondary';b.onclick=()=>command('remove',item.operation_id);box.append(b)}
 root.append(box)}
 }catch{$('notice').textContent='Расширение временно недоступно. Очередь сохранена.'}finally{rendering=false}}
$('manager').onclick=()=>chrome.runtime.sendMessage({type:'open_manager'});void render();chrome.storage.onChanged.addListener(()=>void render());
})();
