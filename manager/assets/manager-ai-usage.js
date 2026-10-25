(function(){
  'use strict';
  const $=id=>document.getElementById(id),client=window.ManagerHttpClient;
  let actor=0,csrf='',epoch=0,mode='new',cursor=null,applied=null,loaded=0;
  const shown=new Set(),projectLabels=new Map();
  const node=(tag,text='')=>{const n=document.createElement(tag);n.textContent=String(text??'');return n};
  const clock=value=>window.ManagerTime?.format(value,{year:true})||String(value||'');
  function status(text,error=false){$('usageStatus').textContent=text;$('usageStatus').classList.toggle('error',error)}
  function clear(){epoch++;actor=0;cursor=null;applied=null;shown.clear();projectLabels.clear();$('usageCases').replaceChildren();$('usageApp').hidden=true;$('usageMore').hidden=true;$('usageCount').textContent='';$('usageAsOf').textContent='';$('usageLogin').hidden=false}
  async function request(action,data={}){
    const r=await client.request(action,data,csrf,'ai-usage-api.php');
    if(r.error==='unauthorized'||r.error==='csrf'||(actor&&r.ok&&r.viewer_id!==actor)){clear();status('Сессия изменилась. Войдите в кабинет и обновите страницу.',true);return{ok:false,error:'unauthorized'}}
    if(r.error==='forbidden'){clear();status('Отчёт доступен владельцу в разрешённых проектах.',true)}
    return r;
  }
  const filters=()=>({project_key:$('usageProject').value,mode,days:Number($('usageDays').value),changed_only:$('usageChanged').checked});
  const signature=query=>JSON.stringify([query.project_key,query.mode,query.days,query.changed_only]);
  function setMode(next){mode=next;document.querySelectorAll('[data-mode]').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.mode===mode)));$('usageListTitle').textContent=mode==='new'?'Непросмотренные применения':'Все применения';void load(false)}
  function validCase(c){return c&&Number.isSafeInteger(c.message_id)&&c.message_id>0&&Number.isSafeInteger(c.conversation_id)&&c.conversation_id>0&&typeof c.case_revision==='string'&&/^[a-f0-9]{64}$/.test(c.case_revision)&&typeof c.proposed_text==='string'&&typeof c.current_sent_text==='string'&&typeof c.recorded_text_matches_proposal==='boolean'&&typeof c.initial_text_available==='boolean'&&typeof c.reviewed==='boolean'}
  function version(title,text,sub){const section=node('section');section.className='usageVersion';const h=node('h3',title);if(sub)h.appendChild(node('span',sub));section.appendChild(h);const p=node('p',text);p.className='usageText';section.appendChild(p);return section}
  function renderCase(c){
    const article=node('article');article.className='usageCase';article.dataset.usageCase=String(c.message_id);
    const header=node('div');header.className='usageCaseHeader';const person=node('div'),name=node('strong',c.display_name||'Турист');name.className='usagePerson';person.appendChild(name);
    const meta=node('div',[c.channel==='telegram'?'TG':String(c.channel||'').toUpperCase(),c.manager_name,projectLabels.get(c.project_key)||c.project_key,'Отправлено '+clock(c.sent_at)+' МСК'].filter(Boolean).join(' · '));meta.className='usageMeta';person.appendChild(meta);header.appendChild(person);
    const badge=node('span',!c.initial_text_available?(c.edited?'Правка после отправки':'Текущая версия'):c.recorded_text_matches_proposal?'Без правок при отправке':'С правками');badge.className='usageBadge'+(c.recorded_text_matches_proposal&&c.initial_text_available?'':' changed');header.appendChild(badge);article.appendChild(header);
    const question=node('div');question.className='usageQuestion';const label=node('span',c.question_edited?'Сообщение туриста · позднее изменено':'Сообщение туриста перед подготовкой');label.className='usageLabel';question.append(label,node('p',c.question_text??'Исходное сообщение недоступно. Доступный контекст можно посмотреть в переписке.'));if(c.question_truncated){const p=node('p','Показана часть сообщения.');p.className='usageCaveat';question.appendChild(p)}article.appendChild(question);
    const comparison=node('div');comparison.className='usageComparison';comparison.appendChild(version('AI предложил',c.proposed_text,c.source==='prepared'?'заранее':'по кнопке AI'));
    const sent=version(c.initial_text_available?'Менеджер отправил':'Текст сообщения сейчас',c.current_sent_text);
    if(!c.initial_text_available){const p=node('p',c.edited?'Сообщение редактировали после отправки. Первоначальный текст для сравнения недоступен.':'Первоначальная отправленная версия не подтверждена; показан текущий текст.');p.className='usageCaveat';sent.appendChild(p)}
    if(c.current_sent_text_truncated){const p=node('p','Показана часть ответа. Полный текст — в переписке.');p.className='usageCaveat';sent.appendChild(p)}comparison.appendChild(sent);article.appendChild(comparison);
    if(c.reason){const reason=node('p','Почему AI предложил этот ответ: '+c.reason);reason.className='usageReason';article.appendChild(reason)}
    const footer=node('div');footer.className='usageCaseFooter';const link=node('a','Открыть переписку ↗');link.href='/manager2/#conversation='+c.conversation_id;link.target='_blank';link.rel='noopener';footer.appendChild(link);
    const review=node('button',c.reviewed?'Просмотрено':'Отметить просмотренным');review.type='button';review.className='usageReview'+(c.reviewed?' done':'');review.disabled=c.reviewed;footer.appendChild(review);
    const note=node('p',c.updated_since_review?'Сравнение изменилось после предыдущего просмотра.':'');note.className='usageCaseStatus';note.setAttribute('role','status');note.setAttribute('aria-live','polite');footer.appendChild(note);
    review.onclick=async()=>{
      if(review.disabled)return;const ticket=epoch,viewer=actor;review.disabled=true;note.textContent='Сохраняем отметку…';note.classList.remove('error');
      const r=await request('review',{message_id:c.message_id,case_revision:c.case_revision});if(ticket!==epoch||viewer!==actor||!article.isConnected)return;
      if(!r.ok||r.message_id!==c.message_id||r.case_revision!==c.case_revision||r.reviewed!==true){review.disabled=false;note.classList.add('error');note.textContent=r.error==='case_changed'?'Ответ изменился. Обновите сравнение перед отметкой.':'Не удалось подтвердить отметку. Обновите отчёт, чтобы проверить её состояние.';return}
      review.textContent='Просмотрено';review.classList.add('done');note.textContent='Это сравнение больше не появится среди непросмотренных. Новые изменения останутся видны.';
    };
    article.appendChild(footer);return article;
  }
  function projects(rows){const select=$('usageProject'),selected=select.value;select.replaceChildren(new Option('Все доступные',''));projectLabels.clear();for(const row of rows||[])if(typeof row.key==='string'&&typeof row.name==='string'){select.appendChild(new Option(row.name,row.key));projectLabels.set(row.key,row.name)}if([...select.options].some(o=>o.value===selected))select.value=selected}
  async function load(append){
    if(!actor)return;const query=filters(),key=signature(query);if(append&&(!cursor||!applied||applied.signature!==key))return;
    const ticket=++epoch,viewer=actor;status(append?'Загружаем следующие применения…':'Загружаем реальные применения…');$('usageMore').disabled=true;
    if(!append){cursor=null;applied=null;shown.clear();loaded=0;$('usageCases').replaceChildren();$('usageMore').hidden=true;$('usageCount').textContent='';$('usageAsOf').textContent=''}
    const r=await request('list',append?{...query,cursor}:{...query});if(ticket!==epoch||viewer!==actor)return;$('usageMore').disabled=false;
    if(!r.ok){if(r.error==='stale_cursor'){cursor=null;applied=null;shown.clear();loaded=0;$('usageCases').replaceChildren();$('usageCount').textContent='';$('usageMore').hidden=true}status(r.error==='stale_cursor'?'Выборка изменилась. Нажмите «Обновить».':'Не удалось загрузить отчёт. Нажмите «Обновить», чтобы повторить.',true);return}
    if(signature(filters())!==key)return;
    if(!Array.isArray(r.items)||r.items.length>20||!r.items.every(validCase)||typeof r.access_revision!=='string'||!/^[a-f0-9]{64}$/.test(r.access_revision)){status('Не удалось проверить данные отчёта. Обновите страницу.',true);return}
    if(append&&applied.access!==r.access_revision){$('usageCases').replaceChildren();$('usageMore').hidden=true;cursor=null;applied=null;status('Область доступа изменилась. Обновите отчёт.',true);return}
    if(!append){projects(r.projects);if(signature(filters())!==key){status('Выбранный проект недоступен. Выберите проект и обновите отчёт.',true);return}applied={signature:key,access:r.access_revision}}
    $('usageCases').querySelector('.usageEmpty')?.remove();
    for(const c of r.items){if(shown.has(c.message_id))continue;shown.add(c.message_id);loaded++;$('usageCases').appendChild(renderCase(c))}
    cursor=r.has_more?r.next_cursor:null;$('usageMore').hidden=!cursor;$('usageCount').textContent='Показано '+loaded;
    if(!loaded){const empty=node('p',r.has_more?'В этой части выборки нет подходящих применений. Можно продолжить загрузку.':mode==='new'?'Новых непросмотренных применений за этот период нет.':'За этот период подходящих отправок с сохранённой связью с AI нет.');empty.className='usageEmpty';$('usageCases').appendChild(empty)}
    $('usageAsOf').textContent='Обновлено '+clock(r.as_of)+' МСК.';
    status(r.scan_limited?'Показана часть выборки. Нажмите «Показать ещё», чтобы продолжить.':loaded?'Готово.':'Отчёт обновлён.');
  }
  $('usageFilters').onsubmit=e=>{e.preventDefault();void load(false)};
  document.querySelectorAll('[data-mode]').forEach(b=>b.onclick=()=>setMode(b.dataset.mode));
  for(const id of ['usageProject','usageDays','usageChanged'])$(id).onchange=()=>void load(false);
  $('usageMore').onclick=()=>void load(true);
  (async()=>{const ticket=++epoch,r=await request('identity');if(ticket!==epoch)return;if(!r.ok||!Number.isSafeInteger(r.viewer_id)||r.viewer_id<=0||typeof r.csrf!=='string'){if(!['forbidden','unauthorized'].includes(r.error))status('Не удалось проверить доступ. Обновите страницу.',true);return}actor=r.viewer_id;csrf=r.csrf;$('usageLogin').hidden=true;$('usageApp').hidden=false;await load(false)})();
})();
