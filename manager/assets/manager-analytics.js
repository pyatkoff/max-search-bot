(function(){
  'use strict';
  const $=id=>document.getElementById(id),form=$('analyticsFilters'),client=window.ManagerHttpClient;
  let actor=0,csrf='',epoch=0,scopes=[],current=null,cursor=null,applied=null,metric='all',directory=null,grantVersion=0,grantLocked=false;
  const node=(tag,text)=>{const n=document.createElement(tag);n.textContent=String(text??'');return n};
  function status(text,error=false){$('analyticsStatus').textContent=text;$('analyticsStatus').classList.toggle('error',error)}
  function invalidate(){epoch++;actor=0;current=null;cursor=null;applied=null;$('analyticsApp').hidden=true;$('supervisorAdmin').hidden=true;$('analyticsRows').replaceChildren();$('analyticsTotals').replaceChildren();$('analyticsLogin').hidden=false}
  async function request(action,data={}){const r=await client.request(action,data,csrf,'supervision-api.php');if(r.ok&&r.viewer_id!==actor){invalidate();status('Аккаунт изменился. Обновите страницу.',true);return {ok:false,error:'unauthorized'}}if(r.error==='unauthorized'||r.error==='csrf'){invalidate();status('Сессия изменилась. Войдите заново, затем обновите страницу.',true)}return r}
  function options(select,rows,all){const old=select.value;select.replaceChildren();if(all)select.appendChild(new Option(all.label,all.value));for(const row of rows)select.appendChild(new Option(row.label,String(row.value)));if([...select.options].some(o=>o.value===old))select.value=old}
  function populateSources(){const project=form.elements.project_key.value;const rows=scopes.filter(s=>!project||s.project_key===project).flatMap(s=>s.sources||[]);options(form.elements.source_id,rows.map(s=>({value:s.id,label:s.name})),{label:'Все разрешённые',value:'0'})}
  const names={all:'Все',requested:'Запрос менеджера',replied:'Первый ответ',continued:'Продолжение туриста',waiting:'Ждут ответа'};
  const seconds=value=>value===null?'нет данных':Number(value)<60?Math.round(Number(value))+' сек':Math.round(Number(value)/60)+' мин';
  function filters(){return {...Object.fromEntries(new FormData(form)),source_id:Number(form.elements.source_id.value),manager_id:Number(form.elements.manager_id.value),metric}}
  function signature(query){return JSON.stringify(['from','to','mode','project_key','source_id','channel','manager_id','metric'].map(key=>query[key]??''))}
  function clearReport(){$('analyticsReport').hidden=true;$('analyticsMore').hidden=true;$('analyticsRows').replaceChildren();$('analyticsTotals').replaceChildren()}
  function filtersChanged(){epoch++;applied=null;current=null;cursor=null;clearReport();form.setAttribute('aria-busy','false');status('Фильтры изменены. Нажмите «Показать».')}

  function renderRows(rows,append){if(!append)$('analyticsRows').replaceChildren();for(const row of rows){const article=node('article','');article.className='dialogue';const text=node('div','');text.appendChild(node('strong',row.display_name||'Турист'));text.appendChild(node('small',[row.channel?.toUpperCase(),row.manager_name,'№'+row.id].filter(Boolean).join(' · ')));text.appendChild(node('small',row.first_reply_at?'Первый ответ: '+seconds(row.first_reply_seconds)+(row.first_reply_manager_id?' · сотрудник №'+row.first_reply_manager_id:''):'Первый ответ не записан'));if(row.recorded_stage||row.outcome)text.appendChild(node('small','Записано: '+[row.recorded_stage,row.outcome].filter(Boolean).join(' · ')));article.appendChild(text);const link=node('a','Переписка ↗');link.href='/manager2/#conversation='+Number(row.id);link.target='_blank';link.rel='noopener';link.setAttribute('aria-label','Открыть переписку: '+(row.display_name||'Турист'));article.appendChild(link);$('analyticsRows').appendChild(article)}if(!append&&!rows.length)$('analyticsRows').appendChild(node('p','В этой выборке диалогов нет.'))}
  function render(result,append){
    current=result;cursor=result.next_cursor;$('analyticsReport').hidden=false;
    $('analyticsMore').hidden=!result.has_more;$('analyticsMore').disabled=false;$('analyticsDrilldownLabel').textContent='· '+names[metric];
    {const t=result.totals;$('analyticsTotals').replaceChildren();for(const [label,value,hint] of [['Диалогов',t.conversations,'Вся разрешённая выборка'],['Назначены сейчас',t.assigned,'Текущий ответственный'],['Ждут ответа',t.waiting,'Текущее состояние'],['Готовые ответы',t.ready_candidates,'Предварительно: сообщения, срок и инструкция']]){const card=node('div','');card.className='metric';card.append(node('span',label),node('strong',value),node('span',hint));$('analyticsTotals').appendChild(card)}
      $('analyticsFunnel').replaceChildren();for(const [key,label,value] of [['all','Обращения',t.conversations],['requested','Запрос менеджера',t.requested],['replied','Первый ответ',t.replied],['continued','Продолжение туриста',t.continued]]){const b=node('button',label);b.type='button';b.appendChild(node('strong',value));b.setAttribute('aria-pressed',String(metric===key));b.onclick=()=>{metric=key;void load(false)};$('analyticsFunnel').appendChild(b)}
      const wait=node('button','Показать ожидающих: '+t.waiting);wait.type='button';wait.onclick=()=>{metric='waiting';void load(false)};$('analyticsFunnel').appendChild(wait);
      $('analyticsLatency').textContent='N = '+result.first_reply.n+' · медиана '+seconds(result.first_reply.p50_seconds)+' · p95 '+seconds(result.first_reply.p95_seconds);
      const e=result.period_events;$('analyticsEvents').textContent=e.requests+' запросов менеджера · '+e.assignments+' назначений · '+e.reply_messages+' сообщений менеджеров в '+e.reply_conversations+' диалогах · '+e.delivery_failures+' записанных ошибок доставки';
      $('analyticsManagers').replaceChildren();for(const row of result.by_manager){const tr=node('tr','');const cell=node('td','');const b=node('button',row.manager_name);b.type='button';b.onclick=()=>{if(row.manager_id){form.elements.manager_id.value=String(row.manager_id);metric='all';void load(false)}};cell.appendChild(b);tr.appendChild(cell);for(const k of ['conversations','waiting','replied','reply_n'])tr.appendChild(node('td',row[k]));$('analyticsManagers').appendChild(tr)}
      $('analyticsSales').textContent='Выиграно: '+t.won+' · Потеряно: '+t.lost+' · Без подтверждённого результата: '+(t.conversations-t.won-t.lost)+'. Этап записан в истории у '+t.recorded_stage+' из '+t.conversations+'.';
      $('analyticsDefinition').textContent=result.mode==='active'?'Сейчас: все разрешённые активные диалоги. Даты относятся только к блоку событий; состояние и когорта не смешиваются.':result.mode==='requested'?'Когорта: первый запрос менеджера в выбранном периоде. Ответ и продолжение учитываются за последующую историю; состояние — на момент расчёта.':'Когорта: диалоги, начатые в выбранном периоде. Ответ и продолжение учитываются за последующую историю; состояние — на момент расчёта.';
      $('analyticsCoverage').textContent='Агрегаты охватывают всю разрешённую когорту. Строки — по 50. Наличие ответа не равно свежему AI-разбору: полная ревизия справочников и условий проверяется при открытии AI; предварительные готовые ответы не считаются проверенным охватом. При изменении диалогов обновите весь отчёт кнопкой «Показать».';
    }
    renderRows(result.rows||[],append);$('analyticsAsOf').textContent='Рассчитано: '+(window.ManagerTime?.format(result.as_of,{year:true})||result.as_of)+' · МСК. Данные только для чтения.';
  }
  async function load(append){
    const draft=filters(),key=signature(draft);
    // A continuation belongs to the applied cohort, never to an edited form.
    // Check again here even when a programmatic edit emitted no input event.
    if(append&&(!applied||applied.signature!==key)){filtersChanged();return}
    if(append&&!cursor)return;
    const ticket=++epoch,owner=actor,query=append?{...applied.query,cursor}:{...draft};
    if(!append){applied=null;clearReport()}
    form.setAttribute('aria-busy','true');$('analyticsMore').disabled=true;status('Считаем полную разрешённую выборку…');
    const result=await request('analytics',query);if(ticket!==epoch||owner!==actor)return;
    if(signature(filters())!==key){filtersChanged();return}
    form.setAttribute('aria-busy','false');$('analyticsMore').disabled=false;
    if(!result.ok){if(result.error==='forbidden'){applied=null;current=null;cursor=null;clearReport();$('analyticsApp').hidden=true;}status(result.error==='forbidden'?'Доступ руководителя отсутствует или отозван.':result.error==='invalid_period'?'Выберите корректный период не длиннее 93 дней.':'Не удалось получить аналитику. Можно повторить «Показать».',true);return}
    if(typeof result.access_revision!=='string'||!/^[a-f0-9]{64}$/.test(result.access_revision)){filtersChanged();status('Не удалось подтвердить область отчёта. Нажмите «Показать».',true);return}
    if(append&&applied.access_revision!==result.access_revision){filtersChanged();status('Область доступа изменилась. Нажмите «Показать».',true);return}
    if(!append)applied={signature:key,access_revision:result.access_revision,query:Object.freeze({...query})};
    render(result,append);status(result.totals.conversations?'Готово.':'За выбранный период данных нет.');
  }
  function grantSelection(){
    if(!directory)return;const project=Number($('grantProject').value),target=Number($('grantManager').value),grant=directory.grants.find(g=>Number(g.project_id)===project&&Number(g.manager_id)===target);
    grantVersion=Number(grant?.version||0);$('grantEnabled').checked=!!Number(grant?.enabled);$('grantUnsourced').checked=!!Number(grant?.include_unsourced);
    const root=$('grantSources');root.replaceChildren(node('legend','Разрешённые источники'));for(const source of directory.sources.filter(s=>Number(s.project_id)===project)){const label=node('label','');label.className='check';const input=document.createElement('input');input.type='checkbox';input.value=String(source.id);input.checked=!!grant?.source_ids?.includes(Number(source.id));label.append(input,node('span',source.display_name));root.appendChild(label)}
    const account=directory.managers.find(m=>Number(m.id)===target&&Number(m.project_id)===project);$('grantIdentity').textContent=account?'Точный аккаунт: '+account.login+' · №'+account.id+' · версия прав '+grantVersion:'Выберите активный аккаунт проекта';$('grantSave').disabled=!account||grantLocked;
  }
  function grantProject(){const project=Number($('grantProject').value);options($('grantManager'),directory.managers.filter(m=>Number(m.project_id)===project).map(m=>({value:m.id,label:m.login+' · '+(m.display_name||'')+' · №'+m.id})));grantSelection()}
  async function readGrants(){const owner=actor;$('grantStatus').textContent='Читаем текущее состояние…';const r=await request('directory');if(owner!==actor)return;if(!r.ok){$('grantStatus').textContent='Нет доступа к управлению правами.';return}directory=r;grantLocked=false;options($('grantProject'),r.projects.map(p=>({value:p.id,label:p.display_name})));$('grantForm').hidden=false;grantProject();$('grantStatus').textContent='Состояние прочитано. Изменения ещё не сохранены.'}
  async function start(){
    const me=await client.request('identity',{},'','supervision-api.php');if(!me.ok){invalidate();status('Войдите в кабинет менеджера.',true);return}actor=Number(me.manager.id);csrf=me.csrf;
    $('supervisorAdmin').hidden=me.manager.role!=='admin';const r=await request('scope');if(!actor)return;scopes=r.scopes||[];
    if(!r.ok||!scopes.length){status('Право руководителя в проекте пока не выдано.',true);return}
    $('analyticsApp').hidden=false;options(form.elements.project_key,scopes.map(s=>({value:s.project_key,label:s.display_name})),{label:'Все разрешённые',value:''});populateSources();options(form.elements.manager_id,(r.managers||[]).map(m=>({value:m.id,label:m.name})),{label:'Вся команда',value:'0'});
    const today=new Date(new Date().toLocaleString('en-US',{timeZone:'Europe/Moscow'})),date=d=>d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');form.elements.to.value=date(today);today.setDate(today.getDate()-6);form.elements.from.value=date(today);await load(false);
  }
  form.onsubmit=e=>{e.preventDefault();metric='all';void load(false)};form.oninput=filtersChanged;form.onchange=e=>{if(e.target===form.elements.project_key)populateSources();filtersChanged()};$('analyticsMore').onclick=()=>void load(true);
  $('grantRead').onclick=()=>void readGrants();$('grantProject').onchange=grantProject;$('grantManager').onchange=grantSelection;
  $('grantForm').onsubmit=async e=>{e.preventDefault();if(grantLocked)return;grantLocked=true;$('grantSave').disabled=true;const owner=actor;const r=await request('set',{manager_id:Number($('grantManager').value),project_id:Number($('grantProject').value),expected_version:grantVersion,enabled:$('grantEnabled').checked,include_unsourced:$('grantUnsourced').checked,source_ids:[...$('grantSources').querySelectorAll('input:checked')].map(n=>Number(n.value))});if(owner!==actor)return;$('grantStatus').textContent=r.ok?'Команда сохранена. Нажмите «Прочитать текущие права» для проверки.':'Сохранение не подтверждено или состояние изменилось. Сначала прочитайте текущие права; повторной отправки нет.';};
  window.addEventListener('pagehide',()=>{epoch++;actor=0;applied=null});void start();
})();
