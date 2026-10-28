(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  const api = '/manager/virtual-reply-reviews-api.php';
  const labels = {pass:'Без замечаний',revise:'Есть замечания',insufficient_context:'Не хватает контекста'};
  const roles = {tourist:'Турист',manager:'Менеджер',bot:'Бот'};
  const actions = {clarify:'Уточнить условия',prepare_selection:'Подготовить подборку',check_offer:'Проверить предложение',answer_question:'Ответить',no_contact:'Не писать'};
  let generation=0, selection=0, controllers=[], cursors=[null], nextCursor=null;
  const node = (tag,text,cls) => { const el=document.createElement(tag); if(text!==undefined)el.textContent=String(text);if(cls)el.className=cls;return el; };
  const when = value => {
    const raw=String(value||''); const d=new Date(raw.includes('T')?raw:raw.replace(' ','T')+'Z');
    return Number.isNaN(d.valueOf())?'Дата не указана':new Intl.DateTimeFormat('ru-RU',{dateStyle:'short',timeStyle:'short',timeZone:'Europe/Kaliningrad'}).format(d);
  };
  const clear = el => el.replaceChildren();
  const stop = () => { controllers.forEach(c=>c.abort());controllers=[]; };
  async function read(params) {
    const controller=new AbortController();controllers.push(controller);
    const timer=setTimeout(()=>controller.abort(),20000);
    try {
      const query=new URLSearchParams(params);
      const response=await fetch(api+'?'+query,{credentials:'same-origin',cache:'no-store',signal:controller.signal,headers:{Accept:'application/json'}});
      let data;try{data=await response.json();}catch{throw Object.assign(new Error('response'),{status:response.status});}
      if(!response.ok||data.ok!==true)throw Object.assign(new Error('request'),{status:response.status});
      return data;
    } finally {clearTimeout(timer);controllers=controllers.filter(c=>c!==controller);}
  }
  function accessError(error) {
    if(error.status!==401&&error.status!==403)return false;
    generation++;selection++;stop();clear($('reviews'));clear($('detail'));
    $('status').textContent=error.status===401?'Войдите в кабинет, чтобы открыть разборы.':'Разборы доступны только администратору с доступом к AnyTour.';
    $('login').hidden=false;$('previous').disabled=true;$('next').disabled=true;return true;
  }
  function paragraph(parent,title,text,cls='text') {parent.append(node('h3',title),node('div',text,cls));}
  function renderDetail(data) {
    const root=$('detail'),p=data.payload||{},a=data.author_result||{},r=data.review_result||{};clear(root);
    root.append(node('span',labels[r.verdict]||r.verdict,'badge '+r.verdict),node('h2','Вопрос → ответ → оценка Astra'));
    if(p.history_incomplete)root.append(node('p','Предшествующая история неполная. Оценка ограничена доступными сообщениями.','notice'));
    if(p.historical_context_reconstructed)root.append(node('p','Исторический контекст восстановлен источником.','notice'));
    const messages=Array.isArray(p.messages)?p.messages:[];
    const last=[...messages].reverse().find(m=>m.sender_type==='tourist');
    if(last)paragraph(root,'Последняя реплика туриста',last.text||'[Текст недоступен]','text draft');
    const history=node('details'),summary=node('summary','Вся доступная история · '+messages.length+' сообщений');history.append(summary);
    messages.forEach((m,i)=>{
      const box=node('div',undefined,'message '+(roles[m.sender_type]?m.sender_type:'other'));box.id='evidence-'+String(m.id);
      const meta=node('div',undefined,'meta');meta.append(node('strong',roles[m.sender_type]||'Сообщение'),document.createTextNode(' · '+when(m.created_at)+' · '+(i+1)));
      box.append(meta,node('div',m.text||'[Текст недоступен]','text'));
      if(m.media_unread)box.append(node('p','Вложение не просмотрено.','meta'));
      if(m.source_text_unavailable)box.append(node('p','Исходный текст недоступен.','meta'));
      if(m.selections_link_unread)box.append(node('p','Подборка по ссылке не прочитана.','meta'));
      history.append(box);
    });root.append(history);
    paragraph(root,'Точный сохранённый ответ',a.draft===null?'Текста для отправки нет. Выбрано действие: '+(actions[a.next_action]||a.next_action)+'.':a.draft,'text draft');
    const explanation=node('details');explanation.open=a.draft===null;explanation.append(node('summary','Причина и следующие проверки'),node('div',a.reason||'','text'));
    const checks=node('ul');(a.checks||[]).forEach(t=>checks.append(node('li',t)));explanation.append(checks);root.append(explanation);
    const review=node('section',undefined,'review');review.append(node('h3','Оценка Astra: '+(labels[r.verdict]||r.verdict)));
    if(!Array.isArray(r.issues)||!r.issues.length)review.append(node('p','Замечаний нет. Развёрнутого объяснения в сохранённой оценке нет.'));
    (r.issues||[]).forEach(issue=>{
      review.append(node('p',issue.explanation||'','text'));
      const cited=(issue.evidence_message_ids||[]).map(id=>messages.findIndex(m=>m.id===id)+1).filter(n=>n>0);
      if(cited.length)review.append(node('p','Цитируемые сообщения в истории: '+cited.join(', '),'meta'));
    });
    if(r.corrected_draft!==null&&r.corrected_draft!==undefined)paragraph(review,'Вариант исправления Astra',r.corrected_draft,'text draft');
    if(r.lesson_candidate!==null&&r.lesson_candidate!==undefined)paragraph(review,'Предложение для общего правила — кандидат',r.lesson_candidate);
    if(data.disposition?.status&&data.disposition.status!=='unresolved')review.append(node('p','Результат последующего разбора: '+data.disposition.status,'meta'));
    root.append(review);root.focus({preventScroll:true});if(window.matchMedia('(max-width: 767px)').matches)root.scrollIntoView({block:'start'});
  }
  async function openCase(id) {
    const g=generation,s=++selection;$('detail').replaceChildren(node('p','Загружаем переписку…','muted'));
    try {
      const d=await read({case_id:id});if(g!==generation||s!==selection)return;
      document.querySelectorAll('.row').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.case===id)));
      renderDetail(d);const url=new URL(location.href);url.hash='case='+id;history.replaceState(null,'',url);
    } catch(error) {
      if(g!==generation||s!==selection)return;if(accessError(error))return;
      $('detail').replaceChildren(node('p',error.status===404?'Этот разбор больше недоступен: источник мог измениться или доступ был отозван.':'Не удалось загрузить разбор. Выберите его ещё раз для повторной проверки.','notice'));
    }
  }
  async function loadPage() {
    const g=++generation;selection++;stop();nextCursor=null;$('next').disabled=true;$('previous').disabled=true;
    clear($('reviews'));$('detail').replaceChildren(node('p','Выберите разбор.','muted'));$('status').textContent='Загружаем разборы…';$('login').hidden=true;
    const params={verdict:$('verdict').value};if(cursors.at(-1)!==null)params.cursor=cursors.at(-1);
    try {
      const d=await read(params);if(g!==generation)return false;
      if(!Array.isArray(d.reviews))throw new Error('malformed');
      d.reviews.forEach(item=>{
        const button=node('button',undefined,'row');button.type='button';button.dataset.case=item.case_id;button.setAttribute('aria-pressed','false');
        button.append(node('strong','Разбор №'+item.number+' · '+(labels[item.verdict]||item.verdict)),node('small','Вопрос: '+when(item.reference_time)),node('small',item.source_kind==='conversation'?'Текущая переписка':'Архив Telegram'));
        button.addEventListener('click',()=>openCase(item.case_id));$('reviews').append(button);
      });
      nextCursor=d.has_more&&typeof d.next_cursor==='string'?d.next_cursor:null;
      $('next').disabled=nextCursor===null;$('previous').disabled=cursors.length<=1;
      $('status').textContent=d.reviews.length?'Показано '+d.reviews.length+' разборов. '+(nextCursor?'Для продолжения нажмите «Старше».':'Это последняя страница.'):(nextCursor?'На этой странице доступных разборов нет. Продолжите кнопкой «Старше».':'Доступных разборов с этой оценкой нет.');return true;
    } catch(error) {
      if(g!==generation)return false;if(accessError(error))return false;
      $('status').textContent='Не удалось загрузить список. Нажмите «Обновить», чтобы проверить ещё раз.';return false;
    }
  }
  $('next').addEventListener('click',()=>{if(nextCursor!==null){cursors.push(nextCursor);loadPage();}});
  $('previous').addEventListener('click',()=>{if(cursors.length>1){cursors.pop();loadPage();}});
  const reset=()=>{cursors=[null];loadPage();};$('verdict').addEventListener('change',reset);$('refresh').addEventListener('click',reset);
  const linked=location.hash.match(/^#case=([a-f0-9]{64})$/)?.[1];loadPage().then(ok=>{if(ok&&linked)openCase(linked);});
})();
