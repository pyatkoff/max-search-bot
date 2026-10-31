(function(){
  'use strict';
  const $=id=>document.getElementById(id),http=window.ManagerHttpClient;
  const labels={departure:'Вылет и пересадки',destination:'Направления и исключения',dates:'Даты поездки',nights:'Длительность',party:'Фактический состав',budget:'Бюджет туриста и единица суммы',hotel:'Критерии отеля',room_meal:'Номер и питание',must_have:'Обязательные условия',tradeoffs:'Допустимые компромиссы',rejections:'Отказы и их причины',questions:'Открытые вопросы'};
  const cid=Number(new URL(location.href).searchParams.get('conversation'));
  const node=(tag,text='')=>{const e=document.createElement(tag);e.textContent=text;return e},clone=v=>JSON.parse(JSON.stringify(v));
  let actor=0,csrf='',epoch=0,current=null,draft=null,pending=null,dirty=false,busy=false,revision=0,beforeNewTrip=null,needsSync=false,retryReady=false;
  const key=()=>`anytour.requestProfile.${actor}.${cid}`;
  function status(text,error=false){$('profileStatus').textContent=text;$('profileStatus').classList.toggle('error',error)}
  function persist(){if(!actor)return;try{if(dirty||pending)sessionStorage.setItem(key(),JSON.stringify({at:Date.now(),draft,pending,dirty}));else sessionStorage.removeItem(key())}catch(e){}}
  function clear(){epoch++;actor=0;current=draft=pending=null;$('profileFields').replaceChildren();$('profileHistory').replaceChildren();$('profileSuggestion').replaceChildren();$('profileLabel').value='';$('profileBoundary').replaceChildren();$('profileApp').hidden=true;$('profileRetryLoad').hidden=true;status('Доступ или аккаунт изменился. Откройте профиль из доступной переписки.',true)}
  async function request(action,data={}){
    const r=await http.request(action,{conversation_id:cid,...data},csrf,'request-profile-api.php');
    if(['unauthorized','csrf','forbidden'].includes(r.error)||(actor&&r.ok&&r.viewer_id!==actor)){clear();return {ok:false,error:'forbidden'}}return r;
  }
  const source=id=>current?.sources?.find(s=>s.id===Number(id));
  function fromProfile(p){const fields={};for(const [k,f] of Object.entries(p.fields||{}))fields[k]={value:clone(f.value),kind:f.kind,importance:f.importance,note:f.note,source_id:f.citation?.message_id||0,quote:f.citation?.quote||''};return {version:p.version,context_sha256:current.context_sha256,label:p.label,boundary_message_id:p.boundary_message_id,new_trip:false,fields}}
  function select(options,value){const e=node('select');for(const [v,t] of options)e.appendChild(new Option(t,v));e.value=String(value);return e}
  function field(label,e){const box=node('label',label);box.appendChild(e);return box}
  function input(value,max=400,type='text'){const e=node('input');e.type=type;e.value=value??'';if(type==='text')e.maxLength=max;return e}
  function area(value,max){const e=node('textarea');e.maxLength=max;e.value=value??'';return e}
  function sourceOptions(){return [['0','Без ссылки — уточнение менеджера'],...(current?.sources||[]).filter(s=>['customer','manager'].includes(s.sender_type)&&!s.unavailable).map(s=>[String(s.id),`№${s.id} · ${s.sender_type==='customer'?'Турист':'Менеджер'} · ${s.text.slice(0,75)}`])]}
  function collect(){
    if(!draft)return null;const fields={};
    for(const section of $('profileFields').children){const k=section.dataset.field;let value;
      if(k==='party'){const a=section.querySelector('[data-value=adults]').value;if(!a)continue;const children=section.querySelector('[data-value=children]').value;if(children===''){status('Укажите число детей; отсутствие сведений не означает, что детей нет.',true);return null}
        const raw=section.querySelector('[data-value=ages]').value.trim();if(Number(children)===0&&raw!==''){status('Указаны возрасты детей, но число детей равно нулю. Сверьте состав.',true);return null}value={adults:Number(a),children:Number(children),child_ages:Number(children)===0?[]:raw===''?null:raw.split(/[,;\s]+/).map(Number)};
      }else{value=section.querySelector('[data-value=text]').value.trim();if(!value)continue;}
      fields[k]={value,kind:section.querySelector('[data-kind]').value,importance:section.querySelector('[data-importance]').value,note:section.querySelector('[data-note]').value,source_id:Number(section.querySelector('[data-source]').value),quote:section.querySelector('[data-quote]').value};
    }
    return {...draft,label:$('profileLabel').value.trim(),boundary_message_id:Number($('profileBoundary').value),new_trip:$('profileNewTrip').checked,fields};
  }
  function summaryText(f){if(!f)return 'Пока не указано';const v=f.value;return (typeof v==='string'?v:`${v.adults} взр. · ${v.children} дет.`+(v.children?' · возрасты: '+(v.child_ages?.join(', ')||'неизвестны'):''))+' · '+({hard:'обязательное',preferred:'желательно',unknown:'важность не определена'}[f.importance]||'')}
  function updateSummaries(){for(const section of $('profileFields').children){const target=section.querySelector('[data-summary]');if(target)target.textContent=summaryText(draft.fields[section.dataset.field])}}
  function changed(){const next=collect();if(next)draft=next;dirty=true;revision++;updateSummaries();persist();buttons()}
  function buttons(){const conflict=current&&draft&&current.profile.version!==draft.version;$('profileSave').disabled=busy||!current?.can_edit||!!pending||needsSync||conflict;$('profileRefresh').disabled=busy;$('profileReplace').hidden=!conflict;$('profileReconcile').hidden=!pending;$('profileReconcile').disabled=busy;const reconcileLabel=retryReady?'Повторить ту же запись':'Проверить сохранение';if($('profileReconcile').textContent!==reconcileLabel)$('profileReconcile').textContent=reconcileLabel;}
  function render(){
    if(!current||!draft)return;$('profileApp').hidden=false;$('profileLabel').value=draft.label;
    const boundary=$('profileBoundary');boundary.replaceChildren(new Option('Выберите начало обсуждения','0'));for(const [id,title] of sourceOptions().slice(1))boundary.appendChild(new Option(title,id));boundary.value=String(draft.boundary_message_id);
    $('profileNewTrip').checked=draft.new_trip;const opened=new Set([...$('profileFields').children].filter(s=>s.open).map(s=>s.dataset.field));$('profileFields').replaceChildren();
    for(const [k,title] of Object.entries(labels)){
      const f=draft.fields[k]||{value:k==='party'?null:'',kind:'manager_interpretation',importance:'unknown',note:'',source_id:0,quote:''};
      const section=node('details');section.className='profileField';section.dataset.field=k;section.dataset.changed=String(current.profile.changed_sources?.includes(k));section.open=opened.has(k)||!!current.profile.changed_sources?.includes(k);const heading=node('summary');heading.appendChild(node('strong',title));const valueSummary=node('span',summaryText(draft.fields[k]));valueSummary.dataset.summary='';heading.appendChild(valueSummary);section.appendChild(heading);const grid=node('div');grid.className='profileFieldGrid';
      if(k==='party'){const party=node('div');party.className='profileParty wide';for(const [id,title,value] of [['adults','Взрослых',f.value?.adults],['children','Детей',f.value?.children],['ages','Возрасты детей через запятую',f.value?.child_ages?.join(', ')??'']]){const e=input(value,50,id==='ages'?'text':'number');if(id!=='ages'){e.min=id==='adults'?'1':'0';e.max=id==='adults'?'20':'12'}e.dataset.value=id;party.appendChild(field(title,e))}grid.appendChild(party)}
      else{const e=area(f.value,400);e.dataset.value='text';const box=field('Условие для этой поездки',e);box.className='wide';grid.appendChild(box)}
      const kind=select([['manager_interpretation','Трактовка / уточнение менеджера'],['customer_statement','Буквальные слова туриста']],f.kind);kind.dataset.kind='';grid.appendChild(field('Происхождение значения',kind));
      const importance=select([['unknown','Не определена'],['hard','Обязательное'],['preferred','Желательно']],f.importance);importance.dataset.importance='';grid.appendChild(field('Важность, отмеченная менеджером',importance));
      const choices=sourceOptions();if(f.source_id&&!choices.some(([id])=>Number(id)===f.source_id))choices.push([String(f.source_id),'Недоступное основание №'+f.source_id]);const sources=select(choices,f.source_id);sources.dataset.source='';const sb=field('Сообщение-основание',sources);sb.className='wide';grid.appendChild(sb);
      const preview=node('p',source(f.source_id)?.text||'Без цитаты значение остаётся уточнением менеджера.');preview.className='profileCitation wide';grid.appendChild(preview);
      const quote=area(f.quote,600);quote.dataset.quote='';const qb=field('Точный фрагмент сообщения',quote);qb.className='wide';grid.appendChild(qb);
      const note=area(f.note,200);note.dataset.note='';const nb=field('Комментарий менеджера',note);nb.className='wide';grid.appendChild(nb);
      sources.onchange=()=>{const s=source(sources.value);preview.textContent=s?.text||'Без цитаты значение остаётся уточнением менеджера.';quote.value=s?.text.slice(0,600)||'';changed()};
      if(current.profile.changed_sources?.includes(k)){const warn=node('p','Сообщение-основание изменилось или недоступно. Сверьте значение и цитату перед сохранением.');warn.className='sourceWarning';grid.appendChild(warn)}
      section.appendChild(grid);section.oninput=changed;section.onchange=changed;$('profileFields').appendChild(section);
    }
    const states={empty:'Профиль пока не создан. Выберите начало запроса и заполните известные условия.',fresh:'Профиль сверён с доступным текстовым контекстом. Цена, наличие и полнота условий этим не подтверждены.',needs_review:'После сохранения появились новые сообщения или изменился контекст. Сверьте условия перед подтверждением.',source_changed:'Изменились сообщения-основания. Старые цитаты показаны как история, их нужно проверить.'};
    $('profileState').textContent=(states[current.profile.status]||'Профиль требует проверки.')+(current.coverage_incomplete?' Показано ограниченное окно переписки; отсутствие сведений здесь не доказывает, что их не обсуждали.':'');$('profileState').classList.toggle('warn',current.profile.status!=='fresh');
    const suggestion=$('profileSuggestion');suggestion.replaceChildren();suggestion.hidden=!current.party_suggestion||!current.can_edit;
    if(!suggestion.hidden){suggestion.appendChild(node('p','В последнем сообщении турист явно указал состав. Можно изменить только состав в черновике; даты и остальные условия останутся на месте.'));const quote=node('p',current.party_suggestion.quote);quote.className='profileCitation';suggestion.appendChild(quote);const b=node('button','Применить состав к черновику');b.type='button';b.className='usageReview profileSuggestionButton';b.onclick=()=>{const s=current.party_suggestion;draft=collect()||draft;draft.fields.party={value:clone(s.value),kind:'customer_statement',importance:draft.fields.party?.importance||'unknown',note:'',source_id:s.source_id,quote:s.quote};dirty=true;revision++;persist();render();$('profileFields').querySelector('[data-field=party]').open=true;status('Состав обновлён в черновике. Сверьте профиль и сохраните его явно.')};suggestion.appendChild(b)}
    $('profileHistory').replaceChildren();for(const h of current.history||[]){$('profileHistory').appendChild(node('p',`Версия ${h.version} · менеджер №${h.manager_id} · ${h.created_at} UTC · ${h.changed_fields.map(k=>labels[k]||k).join(', ')||'Повторная сверка условий'}`))}
    for(const e of $('profileForm').querySelectorAll('input,textarea,select'))e.disabled=!current.can_edit;buttons();
  }
  async function load(mode='keep'){
    $('profileRetryLoad').hidden=true;
    const ticket=++epoch,viewer=actor,editTicket=revision;rStatus('Загружаем профиль и основания…');const r=await request('get');if(ticket!==epoch||viewer!==actor)return false;
    if(!r.ok||r.conversation_id!==cid||!r.profile||!Array.isArray(r.sources)){status(current?'Не удалось проверить профиль. Ваш черновик остаётся на странице.':'Не удалось загрузить профиль. Повторите загрузку.',true);$('profileRetryLoad').hidden=!!current;buttons();return false}
    const old=draft&&current?collect():draft;current=r;
    if(!draft||(mode==='replace'&&editTicket===revision)){draft=fromProfile(r.profile);dirty=false;needsSync=false;beforeNewTrip=null;persist()}
    else{draft=old||draft;if(mode==='sync'&&draft.version===r.profile.version){draft.context_sha256=r.context_sha256;needsSync=false;persist()}else needsSync=draft.context_sha256!==r.context_sha256;}
    render();status(draft.version!==r.profile.version?'Сохранённый профиль изменился. Ваш черновик сохранён; сравните его перед заменой.':needsSync?'Есть новые сообщения. Нажмите «Сверить с новыми сообщениями».':'Профиль загружен.');return true;
  }
  function rStatus(text){status(text)}
  async function submit(){
    if(busy||pending||needsSync||!current?.can_edit)return;const next=collect();if(!next)return;draft=next;retryReady=false;pending={...clone(draft),operation_id:crypto.randomUUID().replaceAll('-','')};persist();await sendPending();
  }
  function acknowledgeTrip(payload){if(payload?.new_trip&&(draft.trip_intent??null)===(payload.trip_intent??null)){draft.new_trip=false;delete draft.trip_intent;beforeNewTrip=null;$('profileNewTrip').checked=false}}
  async function sendPending(){
    if(!pending||busy)return;const ticket=epoch,viewer=actor,rev=revision,payload=clone(pending);busy=true;buttons();status('Сохраняем проверенный профиль…');const r=await request('save',payload);if(ticket!==epoch||viewer!==actor)return;busy=false;
    if(r.ok&&r.conversation_id===cid&&Number.isSafeInteger(r.saved_version)){
      const now=collect(),ack={...payload};delete ack.operation_id;const unchanged=rev===revision&&now&&JSON.stringify(now)===JSON.stringify(ack);acknowledgeTrip(payload);pending=null;if(unchanged){draft.version=r.saved_version;dirty=false;persist();const loaded=await load('replace');if(viewer!==actor)return;if(!loaded){status('Профиль сохранён, но прочитать актуальную версию не удалось. Нажмите «Сверить с новыми сообщениями».',true);return}status(rev===revision?'Профиль сохранён. Поздние изменения переписки потребуют новой сверки.':'Профиль сохранён; новые изменения остались в вашем черновике.')}else{draft.version=r.saved_version;dirty=true;persist();const loaded=await load('keep');if(viewer!==actor)return;status(loaded?'Предыдущие условия сохранены. Новые правки остались в вашем черновике.':'Запись сохранена; новые правки в черновике. Прочитать актуальную версию не удалось — повторите сверку.',!loaded)}return;
    }
    if(['network_error','invalid_response','profile_unavailable'].includes(r.error)){status('Сохранение не подтверждено. Черновик сохранён; проверьте результат перед повтором.',true);buttons();return}
    pending=null;persist();const messages={conflict:'Профиль уже изменён. Ваш черновик сохранён; загрузите актуальную версию для сравнения.',context_changed:'Появились новые сообщения. Ваши условия сохранены в черновике; сверяйте их с новым контекстом.',source_changed:'Цитата не соответствует текущему сообщению. Проверьте основание.',not_customer_statement:'Значение не является буквальными словами туриста. Уточните цитату или отметьте трактовку менеджера.',boundary_required:'Выберите сообщение, с которого началось обсуждение поездки.',new_trip_required:'Для другой границы запроса явно начните отдельный профиль.',profile_too_large:'Профиль слишком длинный. Сократите комментарии и цитаты.'};
    if(r.error==='context_changed')needsSync=true;if(r.error==='conflict')await load('keep');status(messages[r.error]||'Проверьте значения, состав, цитаты и происхождение сведений.',true);buttons();
  }
  $('profileForm').onsubmit=e=>{e.preventDefault();void submit()};$('profileLabel').oninput=changed;$('profileBoundary').onchange=changed;
  $('profileNewTrip').onchange=()=>{if($('profileNewTrip').checked){beforeNewTrip=collect()||draft;draft={...draft,label:'',boundary_message_id:0,new_trip:true,trip_intent:crypto.randomUUID(),fields:{}}}else if(beforeNewTrip){draft=beforeNewTrip;draft.new_trip=false;beforeNewTrip=null}dirty=true;revision++;persist();render()};
  $('profileRetryLoad').onclick=()=>void load('keep');$('profileRefresh').onclick=()=>void load('sync');$('profileReplace').onclick=()=>void load('replace');
  async function reconcile(){if(!pending||busy)return;const ticket=epoch,viewer=actor;busy=true;buttons();const r=await request('operation',{operation_id:pending.operation_id});if(ticket!==epoch||viewer!==actor)return;busy=false;if(r.ok&&r.found){if(Number.isSafeInteger(r.saved_version))draft.version=r.saved_version;acknowledgeTrip(pending);pending=null;persist();const loaded=await load('keep');if(viewer!==actor)return;status(loaded?'Запись подтверждена. Ваш черновик сохранён; актуальная версия показана в истории.':'Запись подтверждена, но актуальная версия не прочитана. Повторите сверку; черновик сохранён.',!loaded)}else if(r.ok){status('Запись пока не найдена. Повтор той же операции безопасен; новые значения сначала сохраните в черновике.');retryReady=true}else status('Не удалось проверить сохранение. Черновик сохранён.',true);buttons()}
  $('profileReconcile').onclick=()=>void (retryReady?sendPending():reconcile());
  function validDraft(d){return d&&Number.isSafeInteger(d.version)&&d.version>=0&&/^[a-f0-9]{64}$/.test(d.context_sha256)&&typeof d.label==='string'&&Number.isSafeInteger(d.boundary_message_id)&&typeof d.new_trip==='boolean'&&d.fields&&typeof d.fields==='object'&&!Array.isArray(d.fields)&&Object.entries(d.fields).every(([k,f])=>labels[k]&&f&&typeof f==='object'&&typeof f.note==='string'&&typeof f.quote==='string'&&Number.isSafeInteger(f.source_id)&&['customer_statement','manager_interpretation'].includes(f.kind)&&['unknown','hard','preferred'].includes(f.importance)&&(k==='party'?f.value&&Number.isSafeInteger(f.value.adults)&&Number.isSafeInteger(f.value.children)&&(f.value.child_ages===null||Array.isArray(f.value.child_ages)):typeof f.value==='string'))}
  (async()=>{if(!Number.isSafeInteger(cid)||cid<=0){status('Откройте профиль из карточки доступного диалога.',true);return}$('profileConversation').href='/manager2/#conversation='+cid;const r=await request('identity');if(!r.ok||!Number.isSafeInteger(r.viewer_id)||r.viewer_id<=0||typeof r.csrf!=='string'){status('Войдите в кабинет менеджера и откройте профиль заново.',true);return}actor=r.viewer_id;csrf=r.csrf;
    try{const saved=JSON.parse(sessionStorage.getItem(key())||'null');if(saved&&Date.now()-saved.at<86400000&&validDraft(saved.draft)&&(!saved.pending||(validDraft(saved.pending)&&/^[a-f0-9]{32}$/.test(saved.pending.operation_id)))){draft=saved.draft;pending=saved.pending||null;dirty=!!saved.dirty}}catch(e){}await load();})();
})();
