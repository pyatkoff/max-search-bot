(function(){
'use strict';const $=s=>document.getElementById(s),text=(tag,value)=>{const e=document.createElement(tag);e.textContent=value;return e};
const labels={editing:'Правка перед добавлением · тур ещё не добавлен',destination:'Проверьте клиента перед добавлением',queued:'Сохраняем…',waiting:'Очередь сохранена. Нет подтверждения сервера.',review:'Нужна проверка похожего предложения',blocked:'Подключение или условия изменились',saved:'Сохранено в подборке'};
const errors={source_price_changed:'В источнике другая цена. Существующая цена и ручные правки не изменены.',ambiguous_offer:'Тождество тарифа и рейсов не доказано. Можно явно добавить отдельный вариант.',manual_price_conflict:'Этот тур уже есть с другой ручной ценой. Откройте подборку в Manager2 и проверьте её. Повтор не изменил прежний тур.',connection_changed:'Подключите эту же поездку заново в Manager2.',bridge_unavailable:'Нужна открытая вкладка сборщика Manager2.',invalid_import:'Шаблон или условия источника не прошли проверку.'};
const forms=new Map(),writes=new Map(),busy=new Set();let rendering=false,again=false;
errors.bridge_update_required='Обновите открытую вкладку сборщика в Manager2. Эта версия ещё не подтверждает ручную цену; повторного добавления нет.';
function persist(operation,draft){forms.set(operation,{...draft});const {calculator_open,...saved}=draft;const p=chrome.runtime.sendMessage({type:'edit_draft',operation_id:operation,draft:saved}).catch(()=>({ok:false}));writes.set(operation,p);void p.then(r=>{if(!r?.ok&&!busy.has(operation))$('notice').textContent='Правка пока не подтверждена. Проверьте подключение перед добавлением.'});}
async function command(type,operation_id,extra={}){
 if(busy.has(operation_id))return;busy.add(operation_id);$('notice').textContent='';
 for(const el of document.querySelectorAll('[data-operation="'+operation_id+'"] input,[data-operation="'+operation_id+'"] select,[data-operation="'+operation_id+'"] button'))el.disabled=true;
 try{await writes.get(operation_id);const r=await chrome.runtime.sendMessage({type,operation_id,...extra});if(!r?.ok)$('notice').textContent='Не удалось подтвердить действие. Клиент или состояние очереди изменились.'}
 catch{$('notice').textContent='Ответ о действии потерялся. Смотрите состояние ниже или откройте окно заново.'}
 finally{busy.delete(operation_id);await render()}
}
function priceEditor(item,box){
 const operation=item.operation_id,o=item.observation.offer,initial={amount:o.amount,action:'discount',value:'',basis:'percent',round:'none',price_basis:false,calculator_open:false},values={...initial,...item.edit_draft,...forms.get(operation)};forms.set(operation,values);
 const form=document.createElement('form');form.className='priceEditor';form.setAttribute('aria-label','Цена перед добавлением');
 const title=text('h3','Цена для клиента');form.append(title);
 const hint=text('p','Цена со страницы не подтверждает полный тариф. Ручная правка не проверяет рейсы и доплаты.');hint.className='hint';form.append(hint);
 const field=(name,label,options,parent=form)=>{const wrap=text('label',label),control=document.createElement(options?'select':'input');control.dataset.priceField=name;control.name=name;
  if(options)for(const [value,title]of options){const option=text('option',title);option.value=value;control.append(option)}else{control.type='text';control.inputMode='decimal';control.maxLength=32}
  control.value=values[name];wrap.append(control);parent.append(wrap);return control;
 };
 const amount=field('amount','Итоговая цена, '+o.currency),calculator=document.createElement('details'),summary=text('summary','Скидка или наценка'),grid=document.createElement('div');grid.className='priceCalc';calculator.open=values.calculator_open;calculator.append(summary,grid);calculator.ontoggle=()=>{values.calculator_open=calculator.open;forms.set(operation,{...values})};
 const action=field('action','Изменение',[['discount','Скидка'],['markup','Наценка']],grid),value=field('value','Размер изменения',null,grid),basis=field('basis','Способ расчёта',[['percent','Проценты'],['amount','Сумма, '+o.currency]],grid),round=field('round','Округление',[['none','Без округления'],['unit','До целого'],['ten','До десятков'],['hundred','До сотен'],['thousand','До тысяч']],grid);
 const apply=text('button','Рассчитать от цены ТО');apply.type='button';calculator.append(apply);form.append(calculator);
 const error=text('p','');error.className='error';error.setAttribute('role','status');form.append(error);
 let priceBasis=null;if(item.observation.adapter==='tourvisor-snapshot-v1'){
  const label=document.createElement('label');label.className='priceBasis';priceBasis=document.createElement('input');priceBasis.type='checkbox';priceBasis.dataset.priceField='price_basis';priceBasis.checked=values.price_basis;
  label.append(priceBasis,document.createTextNode(' Подтверждаю: цена источника за один номер на двух взрослых. Полный тариф ещё не проверен.'));form.append(label);
 }
 const save=text('button','Добавить с этой ценой');save.type='submit';form.append(save);
 const changed=()=>{for(const name of ['action','value','basis','round','amount'])values[name]=form.elements.namedItem(name).value;values.price_basis=priceBasis?.checked===true;persist(operation,values);validate()};
 const validate=()=>{try{AnyTourPriceAdjustment.total(amount.value);save.disabled=busy.has(operation)||(priceBasis&&!priceBasis.checked)}catch{save.disabled=true}};
 for(const el of [action,value,basis,round,amount])el.addEventListener(el.tagName==='SELECT'?'change':'input',()=>{if(el===amount)error.textContent='';changed()});if(priceBasis)priceBasis.onchange=changed;
 apply.onclick=()=>{try{amount.value=AnyTourPriceAdjustment.adjust(o.amount,action.value,value.value,basis.value,round.value);error.textContent='';changed()}catch(e){error.textContent=e.message}};
 form.onsubmit=e=>{e.preventDefault();try{const final=AnyTourPriceAdjustment.total(amount.value);if(priceBasis&&!priceBasis.checked)return;error.textContent='';void command('save_edit',operation,{manager_amount:final,...(priceBasis?{confirm_price_basis:true}:{})})}catch(e){error.textContent=e.message}};
 if(busy.has(operation))for(const el of form.querySelectorAll('input,select,button'))el.disabled=true;else validate();box.append(form);
}
async function render(){if(rendering){again=true;return}rendering=true;again=false;try{
 const s=await chrome.runtime.sendMessage({type:'status'});
 const focused=document.activeElement,focus=focused?.dataset.priceField?{operation:focused.closest('[data-operation]')?.dataset.operation,field:focused.dataset.priceField,start:focused.selectionStart,end:focused.selectionEnd}:null;
 $('target').replaceChildren();if(s.target){$('target').append(text('h2',s.target.client),text('p',s.target.channel+' · '+s.target.trip),text('p','В подборке: '+s.target.count));}else $('target').append(text('p','Подборка пока не подключена.'));
 document.querySelector('main>.hint').hidden=s.items.some(x=>x.state==='editing');
 const root=$('items');root.replaceChildren();for(const item of s.items.slice().reverse().filter(x=>x.state!=='cancelled')){const box=document.createElement('article');box.className='item';box.dataset.operation=item.operation_id;const o=item.observation.offer;box.append(text('h2',o.hotel),text('p',o.date_from+' · '+o.nights+' ночей · '+o.room),text('p','Цена ТО: '+o.amount+' '+o.currency+(item.observation.adapter==='tourvisor-snapshot-v1'?' · за номер':' за весь состав')),text('p','Клиент: '+item.target.client+' · '+item.target.trip),text('p',labels[item.state]||'Требуется проверка'));if(item.manager_amount)box.append(text('p',(item.state==='saved'?'Цена в подборке: ':'Выбранная цена: ')+item.manager_amount+' '+o.currency));if(item.error){const e=text('p',errors[item.error]||'Нужна проверка подключения или предложения.');e.className='error';box.append(e)}
 const button=(label,type)=>{const b=text('button',label);b.onclick=()=>command(type,item.operation_id);box.append(b)};
 if(item.state==='destination'){if(item.observation.adapter==='tourvisor-snapshot-v1'){const label=document.createElement('label'),input=document.createElement('input');input.type='checkbox';label.append(input,document.createTextNode(' Подтверждаю: показанная цена относится к одному номеру на двух взрослых. Полная стоимость ещё не проверена.'));box.append(label);const b=text('button','Подтвердить цену и добавить для этого клиента');b.disabled=true;input.onchange=()=>{b.disabled=!input.checked};b.onclick=()=>{if(input.checked)void command('confirm',item.operation_id,{confirm_price_basis:true})};box.append(b)}else button('Добавить для этого клиента','confirm')}
 if(item.state==='waiting')button('Проверить и продолжить','retry');
 if(item.state==='editing')priceEditor(item,box);
 if(item.state==='review'&&item.error!=='manual_price_conflict')button('Добавить отдельным вариантом','variant');
 if(item.state==='blocked')button('Продолжить с новым подключением этой поездки','reconnect');
 if(item.state!=='queued'){const b=text('button',item.state==='editing'?'Отмена':item.state==='saved'?'Убрать из списка':'Убрать из очереди');b.className='secondary';b.onclick=()=>command('remove',item.operation_id);box.append(b)}
 if(busy.has(item.operation_id))for(const el of box.querySelectorAll('input,select,button'))el.disabled=true;
 root.append(box)}
 if(focus){const el=root.querySelector('[data-operation="'+focus.operation+'"] [data-price-field="'+focus.field+'"]');if(el&&!el.disabled){el.focus({preventScroll:true});if(typeof el.setSelectionRange==='function'&&Number.isInteger(focus.start))el.setSelectionRange(focus.start,focus.end)}}
 const active=new Set(s.items.filter(x=>x.needs_edit_confirmation).map(x=>x.operation_id));for(const key of forms.keys())if(!active.has(key)){forms.delete(key);writes.delete(key)}
 }catch{$('notice').textContent='Расширение временно недоступно. Очередь сохранена.'}finally{rendering=false;if(again)void render()}}
$('manager').onclick=()=>chrome.runtime.sendMessage({type:'open_manager'});void render();chrome.storage.onChanged.addListener(()=>void render());
})();
