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
  const record=value=>value!==null&&typeof value==='object'&&!Array.isArray(value);
  const digest=value=>typeof value==='string'&&/^[a-f0-9]{64}$/.test(value);
  function validInput(r,c){
    const e=r.input_evidence,x=e?.context;
    if(!digest(r.input_sha256)||!record(e)||e.schema_version!==1||!record(x)||x.conversation_id!==c.conversation_id
      ||!Number.isSafeInteger(x.as_of_message_id)||x.as_of_message_id<=0||x.as_of_message_id>=c.message_id
      ||!['history_incomplete','media_unread','selections_link_unread'].every(k=>typeof x[k]==='boolean')
      ||!Array.isArray(x.verified_company_facts)||!Array.isArray(x.messages)||!x.messages.length||x.messages.length>120
      ||typeof e.instructions!=='string'||!e.instructions||!digest(e.instructions_sha256)
      ||!Array.isArray(e.images)||e.images.length>4||e.coverage!==(e.images.length?'images_omitted':'text_only'))return false;
    let previous=0,characters=0;
    for(const m of x.messages){
      if(!record(m)||!Number.isSafeInteger(m.id)||m.id<=previous||m.id>x.as_of_message_id
        ||!['customer','manager','ai','system'].includes(m.sender_type)||typeof m.text!=='string'
        ||!['media_unread','selections_link_unread','text_truncated'].every(k=>!(k in m)||typeof m[k]==='boolean')
        ||(m.text_truncated&&!x.history_incomplete))return false;
      previous=m.id;characters+=Array.from(m.text).length;
    }
    if(previous!==x.as_of_message_id||characters>40000)return false;
    let bytes=0;
    return e.images.every((im,i)=>record(im)&&im.image_index===i+1&&im.type==='input_image'&&im.detail==='high'
      &&['image/jpeg','image/png','image/webp'].includes(im.mime_type)&&digest(im.payload_sha256)
      &&Number.isSafeInteger(im.payload_bytes)&&im.payload_bytes>0&&(bytes+=im.payload_bytes)<=6291456);
  }
  function inputNote(parent,text){const p=node('p',text);p.className='usageCaveat';parent.appendChild(p)}
  function inputDetails(title,value){const details=node('details');details.className='usageInputDetails';details.appendChild(node('summary',title));const pre=node('pre',typeof value==='string'?value:JSON.stringify(value,null,2));pre.className='usageInputRaw';details.appendChild(pre);return details}
  function renderInputReferences(panel, context, doc = document, issued = false) {
    const el = (tag, text = '') => {
      const element = doc.createElement(tag);
      element.textContent = text;
      return element;
    };
    const object = value => value !== null && typeof value === 'object' && !Array.isArray(value);
    const text = value => typeof value === 'string' ? value : Number.isSafeInteger(value) ? String(value) : '';
    const section = (title, note) => {
      const node = el('section'); node.className = 'usageInputReference';
      node.append(el('h4', title));
      if (note) { const p = el('p', note); p.className = 'usageCaveat'; node.append(p); }
      return node;
    };
    const exact = (title, value) => {
      const details = el('details'); details.className = 'usageInputDetails';
      const pre = el('pre', JSON.stringify(value, null, 2)); pre.className = 'usageInputRaw';
      details.append(el('summary', title), pre);
      return details;
    };
    const fields = rows => {
      const list = el('dl'); list.className = 'usageInputFields';
      for (const [label, value] of rows) list.append(el('dt', label), el('dd', text(value) || 'Не указано'));
      return list;
    };
    const selectionLabels = {
      hotel: 'Отель', location: 'Место отдыха', departure_city: 'Город вылета',
      dates_text: 'Даты на странице', nights_text: 'Длительность на странице',
      meal: 'Питание', room_and_travellers: 'Номер и состав на странице',
      price_text: 'Цена на странице', operator_text: 'Туроператор'
    };
    const workingLabels = {
      hotel: 'Отель', destination: 'Направление', departure: 'Город вылета',
      date_from: 'Дата вылета', nights: 'Ночей', adults: 'Взрослых в варианте',
      room: 'Номер', meal: 'Питание', amount: 'Сумма в черновике', currency: 'Валюта',
      stars: 'Категория', source_label: 'Название источника', observed_at: 'Наблюдение источника',
      flights: 'Перелёт', inclusions: 'Включённые услуги', constraints: 'Ограничения',
      rationale: 'Обоснование менеджера', compromise: 'Компромисс в заметке менеджера'
    };
    const root = el('div'); root.className = 'usageInputReferences';
    if (Array.isArray(context.selection_snapshots)) {
      const group = section('Подборки, прочитанные при подготовке',
        'Это наблюдения страниц при подготовке ответа. Состав страницы при отправке, актуальные цена и наличие ими не подтверждены.');
      context.selection_snapshots.forEach((snapshot, index) => {
        if (!object(snapshot)) return;
        const state = {observed: 'Страница прочитана', unread: 'Страница не прочитана', unavailable: 'Страница недоступна'}[snapshot.state] || 'Состояние не определено';
        const page = section('Подборка ' + (index + 1), '');
        page.append(fields([['Состояние', state], ['Прочитана', snapshot.observed_at]]));
        if (Array.isArray(snapshot.source_messages)) {
          const roles = {customer: 'турист', manager: 'менеджер', ai: 'AI', system: 'служебное сообщение'};
          const origins = snapshot.source_messages.map(source => {
            if (!object(source)) return '';
            return ['#' + text(source.message_id), roles[source.sender_type] || 'автор не определён', text(source.sent_at)].filter(Boolean).join(' · ');
          }).filter(Boolean);
          page.append(el('p', 'Ссылка была в сообщениях: ' + (origins.join('; ') || 'Не указано')));
        }
        if (snapshot.offers_truncated === true) inputNote(page, 'В сохранённое наблюдение вошла только часть вариантов.');
        if (Array.isArray(snapshot.offers)) snapshot.offers.forEach((offer, offerIndex) => {
          if (!object(offer)) return;
          const card = section('Вариант ' + (offerIndex + 1), '');
          card.append(fields(Object.entries(selectionLabels).map(([key, label]) => [label, offer[key]])));
          card.append(el('p', offer.price_basis === null || offer.price_basis === undefined
            ? 'За кого или за какую услугу указана цена, в наблюдении не определено.'
            : 'Основа цены в источнике: ' + text(offer.price_basis)));
          if (Array.isArray(offer.flights) && offer.flights.length) card.append(fields([['Перелёт на странице', offer.flights.filter(value => typeof value === 'string').join('\n')]]));
          card.append(exact('Все сохранённые поля варианта', offer)); page.append(card);
        });
        page.append(exact('Все сохранённые данные подборки', snapshot)); group.append(page);
      });
      if (context.selection_coverage !== undefined) group.append(exact('Охват чтения подборок', context.selection_coverage));
      root.append(group);
    }
    if (object(context.working_selection)) {
      const working = context.working_selection;
      const group = section('Рабочий черновик подборки',
        'Сохранённые менеджером варианты. Эта запись не подтверждает отправку туристу, его выбор, цену или наличие. Заметки менеджера не являются подтверждёнными свойствами отеля.');
      group.append(fields([['Версия', working.version], ['Сохранён', working.saved_at]]));
      if (working.price_basis === 'whole_party') group.append(el('p', 'Цена относится к составу, записанному в каждом варианте; он может отличаться от текущего запроса.'));
      if (Array.isArray(working.offers)) working.offers.forEach(offer => {
        if (!object(offer)) return;
        const card = section('Позиция в рабочем черновике: ' + (text(offer.variant) || 'Не указана'), '');
        card.append(fields(Object.entries(workingLabels).map(([key, label]) => [label, offer[key]])));
        card.append(fields([['Возраста детей в варианте', Array.isArray(offer.child_ages) && offer.child_ages.length
          ? offer.child_ages.map(text).join(', ') : Array.isArray(offer.child_ages) ? 'Детей нет' : 'Не записаны']]));
        if (offer.photos_unread === true) card.append(el('p', 'Фотографии этого варианта не были прочитаны.'));
        if (Array.isArray(offer.source_condition_edits) && offer.source_condition_edits.length) {
          const changes = offer.source_condition_edits.map(key => workingLabels[key] || (key === 'child_ages' ? 'Возраста детей' : text(key))).filter(Boolean);
          card.append(el('p', 'Менеджер изменил относительно источника: ' + changes.join(', ') + '. Поставщик эти изменения здесь не подтверждал.'));
        }
        if (offer.source_observation !== undefined && offer.source_observation !== null) card.append(exact('Исходное наблюдение до правок менеджера', offer.source_observation));
        card.append(exact('Все сохранённые поля рабочего варианта', offer)); group.append(card);
      });
      group.append(exact('Все сохранённые данные рабочего черновика', working)); root.append(group);
    }
    if (Array.isArray(context.verified_company_facts)) {
      const group = section(issued?'Сведения о компании в пакете сервера':'Сведения о компании, переданные AI',
        'Это сведения, допущенные при подготовке данного черновика; они не описывают пожелания туриста.');
      if (!context.verified_company_facts.length) group.append(el('p', issued?'Сведения в пакет не включены.':'Сведения не передавались.'));
      for (const fact of context.verified_company_facts) if (object(fact) && typeof fact.text === 'string') {
        group.append(el('p', fact.text), exact('Источник и ограничения сведения', fact));
      }
      root.append(group);
    }
    const referenceLabels = {
      origin_posts: ['Публикации, переданные AI', 'Публикация и вход по ней не подтверждают текущие условия поездки или согласие туриста.'],
      travel_reference: ['Справка о направлениях и вылетах', 'Сохранённая справка не подтверждает рейсы на нужную дату, места или цену.'],
      hotel_reference: ['Гостиничная справка, переданная AI', 'Опубликованные сведения применимы только в сохранённых пределах. Это не подтверждение услуг на даты поездки или соответствия операторского номера официальной категории.'],
      photo_evidence: ['Какие изображения были доступны AI', 'attached означает, что изображение было передано при подготовке. Само изображение в этой записи не сохранено. unavailable или omitted означает, что оно не было передано.'],
      reference_coverage: ['Охват дополнительных сведений', '']
    };
    if(issued){referenceLabels.origin_posts[0]='Публикации в пакете сервера';referenceLabels.hotel_reference[0]='Гостиничная справка в пакете сервера';referenceLabels.photo_evidence=['Изображения в пакете сервера','attached означает, что сервер включил изображение в ответ. Получение и использование внешней моделью неизвестны; сами изображения в этой записи не сохранены. unavailable или omitted означает отсутствие изображения в пакете.'];}
    for (const [key, [title, note]] of Object.entries(referenceLabels)) if (Object.hasOwn(context, key)) {
      const group = section(title, note); group.append(exact('Сохранённые данные', context[key])); root.append(group);
    }
    if (!Array.isArray(context.selection_snapshots) && Object.hasOwn(context, 'selection_coverage')) root.append(exact('Охват чтения подборок', context.selection_coverage));
    root.append(exact('Все сохранённые поля контекста', context));
    panel.appendChild(root);
  }
  function renderInput(panel,e,receipt){
    const issued=receipt?.provenance==='server_issued_context';
    const x=e.context;panel.replaceChildren(node('h3',issued?'Контекст, выданный внешнему помощнику':'Что AI получил при подготовке'));
    const intro=node('p',issued?'Сохранён точный пакет сервера, квитанцию которого помощник указал при сохранении ответа. Получение и использование пакета не подтверждены; полный вход внешней модели, её настройки и дополнительные инструкции неизвестны.':'Сохранённый вход именно этого черновика. Сообщения показаны в том виде, в котором их получил AI; последующие изменения переписки сюда не попадают.');intro.className='usageInputIntro';panel.appendChild(intro);
    if(x.history_incomplete)inputNote(panel,issued?'В пакете сохранена неполная история. Другие условия могли остаться за его пределами.':'История передана не полностью. Условия могли обсуждаться за пределами этого контекста.');
    if(x.media_unread)inputNote(panel,'Часть вложений не прочитана. Их содержание не подтверждено этим контекстом.');
    if(x.selections_link_unread)inputNote(panel,'Есть непрочитанные ссылки на подборки. Их состав здесь не подтверждён.');
    if(x.selection_coverage?.payload_omitted)inputNote(panel,issued?'Данные прочитанных подборок не вошли в пакет из-за ограничения объёма.':'Данные прочитанных подборок не вошли во вход AI из-за ограничения объёма.');
    if(x.selection_coverage?.links_omitted>0||x.selection_coverage?.unsupported_links>0||x.selection_coverage?.source_limit_reached)inputNote(panel,'Прочитаны не все ссылки на подборки. Границы чтения сохранены в дополнительных данных.');
    if(x.reference_coverage?.payload_omitted)inputNote(panel,issued?'Дополнительные сведения не вошли в пакет из-за ограничения объёма.':'Дополнительные сведения не вошли во вход AI из-за ограничения объёма.');
    if(e.coverage==='images_omitted')inputNote(panel,(issued?'В выданном пакете были изображения (':'AI получил изображения (')+e.images.length+'), но сами изображения в этой записи не сохранены. Полный вход по ней восстановить нельзя.');
    const messages=node('ol');messages.className='usageInputMessages';const roles={customer:'Турист',manager:'Менеджер',ai:'AI',system:'Система'};
    for(const m of x.messages){const item=node('li');item.appendChild(node('h4',roles[m.sender_type]+' · сообщение №'+m.id));const text=node('p',m.text);text.className='usageText';item.appendChild(text);
      if(m.text_truncated)inputNote(item,issued?'В пакете сохранён сокращённый текст.':'Текст был сокращён до передачи AI.');
      if(m.media_unread)inputNote(item,'Вложения не прочитаны.');
      if(m.selections_link_unread)inputNote(item,'Ссылка на подборку не прочитана.');
      messages.appendChild(item);
    }panel.appendChild(messages);
    if(issued){inputNote(panel,'Эта запись подтверждает только пакет сервера. По ней нельзя подтвердить пользу правки или допустить автоматическую отправку.');panel.appendChild(inputDetails('Сведения о выданном пакете',{job_id:receipt.prepared_packet.job_id,revision:receipt.prepared_packet.revision,preparation_scope:receipt.prepared_packet.preparation_scope,latest_message_id:receipt.prepared_packet.latest_message_id,company_knowledge:receipt.prepared_packet.company_knowledge,instruction_revision:receipt.prepared_packet.instruction_revision,packet_sha256:receipt.input_sha256,issued_at:receipt.issued_at}));}
    renderInputReferences(panel,x,document,issued);
    panel.appendChild(inputDetails(issued?'Инструкции выданного пакета':'Инструкции, действовавшие для этого ответа',e.instructions));
  }
  function inputViewer(c,article){
    const area=node('div');area.className='usageInputArea';const toggle=node('button','Что видел AI');toggle.type='button';toggle.className='usageInputToggle';toggle.setAttribute('aria-expanded','false');
    const panel=node('section');panel.className='usageInput';panel.id='usage-input-'+c.message_id;panel.hidden=true;panel.setAttribute('aria-label','Сохранённый контекст AI');toggle.setAttribute('aria-controls',panel.id);area.append(toggle,panel);
    let serial=0;
    toggle.onclick=async()=>{
      const ticket=++serial;panel.hidden=!panel.hidden;toggle.setAttribute('aria-expanded',String(!panel.hidden));toggle.textContent=panel.hidden?'Что видел AI':'Скрыть контекст';panel.replaceChildren();if(panel.hidden)return;
      const viewer=actor,note=node('p','Загружаем сохранённый контекст…');note.className='usageInputStatus';note.setAttribute('role','status');panel.appendChild(note);
      const r=await request('input',{message_id:c.message_id,case_revision:c.case_revision});
      // Pagination keeps this article; refresh/filter/access changes remove it.
      // A separate per-open token prevents a closed request filling a reopened panel.
      if(ticket!==serial||viewer!==actor||!article.isConnected||panel.hidden)return;
      if(!r.ok||r.message_id!==c.message_id||r.case_revision!==c.case_revision||typeof r.available!=='boolean'){
        note.classList.add('error');note.textContent=r.error==='case_changed'?'Сравнение изменилось. Обновите отчёт перед просмотром контекста.':'Не удалось проверить контекст. Закройте и откройте его снова или обновите отчёт.';return;
      }
      if(!r.available){const reasons={not_recorded:'Вход для этого черновика не сохранён. Текущая переписка не заменяет исходный контекст.',prepared_input_not_captured:'Вход внешнего помощника при подготовке этого ответа не сохранялся. Контекст первого просмотра не заменяет его.',storage_unavailable:'Хранилище контекста сейчас недоступно. Закройте и откройте контекст позже.',invalid_evidence:'Сохранённый вход не прошёл проверку целостности и не показан.'};note.textContent=reasons[r.reason]||'Сохранённый вход недоступен. Исходный контекст не восстановлен.';return;}
      if(!validInput(r,c)||(r.provenance!==undefined&&r.provenance!=='server_issued_context')){note.classList.add('error');note.textContent='Не удалось проверить данные сохранённого входа. Обновите отчёт.';return;}
      if(r.provenance==='server_issued_context'&&(r.external_model_input!=='unknown'||!record(r.prepared_packet)||!digest(r.input_receipt)||!record(r.prepared_packet.context)||r.prepared_packet.context.conversation_id!==c.conversation_id||JSON.stringify(r.prepared_packet.context)!==JSON.stringify(r.input_evidence.context)||r.prepared_packet.instructions!==r.input_evidence.instructions)){note.classList.add('error');note.textContent='Не удалось проверить сведения о выданном пакете.';return;}
      renderInput(panel,r.input_evidence,r);
    };return area;
  }
  function sentTextViewer(c,article){
    const area=node('div');area.className='usageSentTextArea';const button=node('button','Текст при отправке');button.type='button';button.className='usageInputToggle';button.setAttribute('aria-expanded','false');
    const panel=node('section');panel.className='usageSentText';panel.id='usage-sent-'+c.message_id;panel.hidden=true;panel.setAttribute('aria-label','Первоначальный ответ менеджера');button.setAttribute('aria-controls',panel.id);area.append(button,panel);let serial=0;
    button.onclick=async()=>{
      const ticket=++serial;panel.hidden=!panel.hidden;button.setAttribute('aria-expanded',String(!panel.hidden));button.textContent=panel.hidden?'Текст при отправке':'Скрыть первоначальный текст';panel.replaceChildren();if(panel.hidden)return;
      const viewer=actor,note=node('p','Загружаем первоначальный текст…');note.className='usageInputStatus';note.setAttribute('role','status');panel.appendChild(note);
      const r=await request('sent_text',{message_id:c.message_id,case_revision:c.case_revision});
      if(ticket!==serial||viewer!==actor||!article.isConnected||panel.hidden)return;
      if(!r.ok||r.message_id!==c.message_id||r.case_revision!==c.case_revision||typeof r.available!=='boolean'){
        note.classList.add('error');note.textContent=r.error==='case_changed'?'Сравнение изменилось. Обновите отчёт перед просмотром первоначального текста.':'Не удалось проверить первоначальный текст. Закройте и откройте его снова или обновите отчёт.';return;
      }
      if(!r.available){const reasons={not_recorded:'Первоначальный текст отдельно не сохранён. Текущая версия сообщения не заменяет его.',invalid_evidence:'Сохранённый первоначальный текст не прошёл проверку целостности и не показан.',storage_unavailable:'Хранилище первоначального текста сейчас недоступно. Попробуйте открыть его позже.'};note.textContent=reasons[r.reason]||'Первоначальный текст недоступен.';return;}
      if(typeof r.text!=='string'||!r.text||Array.from(r.text).length>20000||new TextEncoder().encode(r.text).length>80000||!digest(r.text_sha256)){
        note.classList.add('error');note.textContent='Не удалось проверить данные первоначального текста. Обновите отчёт.';return;
      }
      panel.replaceChildren(node('h4','Первоначальный ответ менеджера'));const text=node('p',r.text);text.className='usageText usageOriginalText';panel.appendChild(text);inputNote(panel,'Это текст до последующих изменений сообщения. Различие с предложением AI само по себе не означает исправление ошибки.');
    };return area;
  }
  const assessmentKinds={unchanged:'Без правки',style:'Только стиль',meaning:'Изменён смысл',unassessable:'Нельзя оценить'};
  const assessmentDimensions={none:'Не относится к смыслу',conditions:'Условия поездки',last_question:'Полнота ответа на последний вопрос',selection:'Точная подборка',unnecessary_question:'Лишний повторный вопрос',unsupported_claim:'Неподтверждённое утверждение',other:'Другое'};
  const assessmentEffects={unknown:'Не установлена',better:'Стало полезнее',worse:'Стало хуже',mixed:'Есть польза и потери'};
  const assessmentVerdicts={confirm:'Подтверждаю оценку',reject:'Не согласен с оценкой',insufficient_context:'Недостаточно контекста'};
  function assessmentViewer(c,article){
    const area=node('section');area.className='usageInputArea usageAssessmentArea';
    const toggle=node('button','Разобрать правку');toggle.type='button';toggle.className='usageInputToggle';toggle.setAttribute('aria-expanded','false');
    const panel=node('div');panel.className='usageAssessment';panel.hidden=true;area.append(toggle,panel);
    let serial=0,open=false,pending=null,working=false,fieldCounter=0;
    const alive=(viewer,turn)=>open&&serial===turn&&actor===viewer&&article.isConnected;
    const bound=r=>r.ok&&r.message_id===c.message_id&&r.case_revision===c.case_revision&&r.viewer_id===actor;
    const valid=r=>bound(r)&&Number.isSafeInteger(r.version)&&r.version>=0&&record(r.snapshot)&&digest(r.snapshot.comparison_sha256)
      &&typeof r.snapshot.proposed_text==='string'&&typeof r.snapshot.pair_available==='boolean'&&typeof r.snapshot.pair_changed==='boolean'
      &&typeof r.snapshot.can_judge_effect==='boolean'&&typeof r.snapshot.input_available==='boolean'&&Array.isArray(r.snapshot.request_messages)
      &&(r.snapshot.initial_sent_text===null||typeof r.snapshot.initial_sent_text==='string')&&Array.isArray(r.history)&&typeof r.can_adjudicate==='boolean'&&typeof r.case_improvement_confirmed==='boolean'
      &&[r.candidate,r.decision].every(x=>x===null||(record(x)&&Number.isSafeInteger(x.version)&&Number.isSafeInteger(x.manager_id)&&record(x.data)&&typeof x.data.reason==='string'));
    function field(parent,label,element){const wrap=node('div'),l=node('label',label);wrap.className='usageAssessmentField';element.id='usage-assessment-'+c.message_id+'-'+(++fieldCounter);l.htmlFor=element.id;wrap.append(l,element);parent.appendChild(wrap);return element}
    function select(options){const e=node('select');for(const [value,label] of Object.entries(options))e.appendChild(new Option(label,value));return e}
    function summary(parent,title,row){if(!row)return;const box=node('div');box.className='usageAssessmentRecord';box.appendChild(node('h4',title+' · версия '+row.version+' · владелец '+row.manager_id));
      const d=row.data;box.appendChild(node('p',row.phase==='proposal'?[assessmentKinds[d.kind],assessmentDimensions[d.dimension],assessmentEffects[d.effect]].join(' · '):assessmentVerdicts[d.verdict]));box.appendChild(node('p',d.reason));
      if(d.citation)box.appendChild(node('blockquote','Источник #'+d.citation.message_id+': '+d.citation.quote));parent.appendChild(box)}
    function render(r){
      pending=null;working=false;panel.replaceChildren(node('h3','Разбор первоначальной правки'));
      const x=r.snapshot,info=node('p',r.case_improvement_confirmed?'Другой владелец подтвердил пользу этой правки в данном случае. Общий прирост качества ещё не измерен.':'Разбор — оценка конкретной правки. Само изменение текста не доказывает пользу или ошибку AI.');info.className='usageInputIntro';panel.appendChild(info);
      const pair=node('div');pair.className='usageComparison';pair.append(version('Исходное предложение AI',x.proposed_text),version('Первый отправленный текст',x.pair_available?x.initial_sent_text:'Первоначальный текст не подтверждён.'));panel.appendChild(pair);
      const warning=node('p',x.can_judge_effect?'Доступен сохранённый текстовый ввод. Цены, условия продажи и выводы из старых реплик требуют собственных подтверждений.':(x.input_reason==='external_model_input_unknown'?'Сохранён пакет сервера, но полный вход внешней модели неизвестен. Пользу правки подтвердить нельзя; результат остаётся «Не установлена».':'Пользу оценить нельзя: исходный ввод отсутствует или неполон либо нет первоначального текста. Можно описать правку, сохранив результат «Не установлена».'));warning.className='usageCaveat';panel.appendChild(warning);
      const context=node('details');context.className='usageInputDetails';context.appendChild(node('summary','Сохранённые реплики для проверки'));
      if(!x.request_messages.length)context.appendChild(node('p','Сохранённые реплики недоступны. Текущая переписка не заменяет исходный ввод.'));
      for(const m of x.request_messages){const item=node('div');item.className='usageAssessmentSource';item.append(node('h4','#'+m.id+' · '+(m.sender_type==='customer'?'Турист':m.sender_type)),node('p',m.text));context.appendChild(item)}panel.appendChild(context);
      summary(panel,'Оценка правки',r.candidate);summary(panel,'Независимая проверка',r.decision);
      if(r.history.some(h=>h.stale)){const p=node('p','Есть прежние оценки другого исходного сравнения. Они не подтверждают текущий случай.');p.className='usageCaveat';panel.appendChild(p)}
      const history=node('details');history.className='usageInputDetails';history.appendChild(node('summary','Последние записи: '+r.history.length));for(const h of r.history)history.appendChild(node('p','Версия '+h.version+' · владелец '+h.manager_id+' · '+(h.phase==='proposal'?'оценка':'проверка')+(h.stale?' · прежнее сравнение':'')));panel.appendChild(history);
      const form=node('form');form.className='usageAssessmentForm';const controls=node('fieldset');controls.className='usageAssessmentFields';controls.appendChild(node('legend','Сохранить оценку'));
      const phase=field(controls,'Действие',select({proposal:'Новая оценка правки',...(r.can_adjudicate?{adjudication:'Независимая проверка оценки'}:{})}));
      const classification=node('div');classification.className='usageAssessmentGrid';const kind=field(classification,'Вид правки',select(assessmentKinds)),dimension=field(classification,'Что изменилось',select(assessmentDimensions)),effect=field(classification,'Польза правки',select(assessmentEffects));controls.appendChild(classification);
      kind.value=x.pair_available&&!x.pair_changed?'unchanged':'unassessable';dimension.value=kind.value==='meaning'?'last_question':'none';effect.value='unknown';
      const verdict=field(controls,'Результат независимой проверки',select(assessmentVerdicts));verdict.value='insufficient_context';verdict.parentElement.hidden=true;
      const reason=field(controls,'Почему вы так считаете',node('textarea'));reason.required=true;reason.maxLength=2000;reason.rows=3;
      const citationSource=field(controls,'Источник цитаты туриста',select({'0':'Без цитаты',...Object.fromEntries(x.request_messages.filter(m=>m.sender_type==='customer'&&!m.text_truncated&&!m.media_unread&&!m.selections_link_unread).map(m=>[m.id,'Реплика #'+m.id]))}));
      const sourceText=node('p');sourceText.className='usageText usageAssessmentSource';controls.appendChild(sourceText);
      const quote=field(controls,'Точная цитата из сохранённой реплики',node('textarea'));quote.maxLength=1000;quote.rows=2;
      const save=node('button','Сохранить оценку');save.type='submit';save.className='usageReview';controls.appendChild(save);form.appendChild(controls);
      const note=node('p');note.className='usageCaseStatus';note.setAttribute('role','status');note.setAttribute('aria-live','polite');form.appendChild(note);
      const reconcile=node('button','Проверить сохранение');reconcile.type='button';reconcile.className='usageReview';reconcile.hidden=true;form.appendChild(reconcile);panel.appendChild(form);
      function sync(){const peer=phase.value==='adjudication';classification.hidden=peer;verdict.parentElement.hidden=!peer;
        if(kind.value!=='meaning'){dimension.value='none';effect.value='unknown'}else if(dimension.value==='none')dimension.value='last_question';
        dimension.disabled=kind.value!=='meaning';effect.disabled=kind.value!=='meaning'||!x.can_judge_effect;
        for(const option of effect.options)option.disabled=option.value!=='unknown'&&!x.can_judge_effect;
        for(const option of kind.options)option.disabled=option.value!=='unassessable'&&(!x.pair_available||((option.value==='unchanged')===x.pair_changed));
        const confirm=verdict.querySelector('option[value="confirm"]');confirm.disabled=!x.can_judge_effect||r.candidate?.data.kind!=='meaning'||r.candidate?.data.effect==='unknown';if(confirm.disabled&&verdict.value==='confirm')verdict.value='insufficient_context';
        const required=peer?verdict.value==='confirm':effect.value!=='unknown';quote.required=required;
        sourceText.textContent=x.request_messages.find(m=>String(m.id)===citationSource.value)?.text||'';
      }
      for(const e of [phase,kind,dimension,effect,verdict,citationSource])e.onchange=sync;sync();
      const errorText={conflict:'Другой владелец уже сохранил оценку. Закройте и снова откройте разбор.',case_changed:'Ответ изменился. Обновите журнал перед разбором.',comparison_changed:'Исходное сравнение изменилось. Обновите журнал.',candidate_changed:'Оценка для проверки изменилась. Откройте разбор заново.',independent_owner_required:'Нужен другой владелец, который не создавал оценку и не отправлял ответ.',invalid_citation:'Выберите сохранённую реплику туриста и вставьте точную цитату.',effect_unassessable:'Недостаточно исходных данных для оценки пользы.',invalid_assessment:'Проверьте вид правки, причину и оценку пользы.'};
      async function refresh(receipt){const viewer=actor,turn=serial;const next=await request('assessment_read',{message_id:c.message_id,case_revision:c.case_revision,...(receipt?{operation_id:receipt}:{})});
        if(!alive(viewer,turn))return null;if(!valid(next))return null;return next;}
      reconcile.onclick=async()=>{if(!pending||working)return;working=true;note.textContent='Проверяем сохранение…';const next=await refresh(pending.operation_id);working=false;if(!open||!article.isConnected)return;
        if(!next){note.textContent='Состояние не подтверждено. Повторите проверку сохранения.';return}
        if(next.operation?.found){render(next);return}
        if(next.version!==pending.version||next.snapshot.comparison_sha256!==pending.comparison_sha256){controls.disabled=true;reconcile.hidden=true;note.textContent='Сравнение или оценка изменились. Откройте разбор заново.';return}
        controls.disabled=false;reconcile.hidden=true;pending=null;note.textContent='Операция не найдена. Можно проверить форму и сохранить снова.';
      };
      form.onsubmit=async e=>{e.preventDefault();if(working||pending)return;
        const data={reason:reason.value,citation:citationSource.value==='0'&&!quote.value.trim()?null:{message_id:Number(citationSource.value),quote:quote.value}};
        if(phase.value==='proposal')Object.assign(data,{kind:kind.value,dimension:dimension.value,effect:effect.value});else data.verdict=verdict.value;
        pending={message_id:c.message_id,case_revision:c.case_revision,version:r.version,comparison_sha256:x.comparison_sha256,operation_id:crypto.randomUUID().replaceAll('-',''),phase:phase.value,target_version:phase.value==='adjudication'?r.candidate.version:0,data};
        const viewer=actor,turn=serial;working=true;controls.disabled=true;note.textContent='Сохраняем оценку…';
        const saved=await request('assessment_save',pending);if(!alive(viewer,turn))return;working=false;
        if(bound(saved)&&Number.isSafeInteger(saved.saved_version)&&saved.saved_version===r.version+1){const next=await refresh(pending.operation_id);if(!next){reconcile.hidden=false;note.textContent='Сохранение принято. Проверьте итоговое состояние.';return}render(next);return}
        if(errorText[saved.error]){pending=null;controls.disabled=false;note.textContent=errorText[saved.error];note.classList.add('error');return}
        reconcile.hidden=false;note.textContent='Результат сохранения неизвестен. Проверьте его перед новой попыткой.';
      };
    }
    toggle.onclick=async()=>{
      if(open){if(pending||working)return;open=false;serial++;panel.hidden=true;panel.replaceChildren();toggle.textContent='Разобрать правку';toggle.setAttribute('aria-expanded','false');return}
      open=true;const turn=++serial,viewer=actor;panel.hidden=false;panel.replaceChildren(node('p','Загружаем исходное сравнение…'));toggle.textContent='Закрыть разбор';toggle.setAttribute('aria-expanded','true');
      const r=await request('assessment_read',{message_id:c.message_id,case_revision:c.case_revision});if(!alive(viewer,turn))return;
      if(!valid(r)){panel.replaceChildren(node('p',r.error==='case_changed'?'Ответ изменился. Обновите журнал.':'Разбор недоступен. Закройте и откройте его заново.'));return}render(r);
    };
    return area;
  }

  function renderCase(c){
    const article=node('article');article.className='usageCase';article.dataset.usageCase=String(c.message_id);
    const header=node('div');header.className='usageCaseHeader';const person=node('div'),name=node('strong',c.display_name||'Турист');name.className='usagePerson';person.appendChild(name);
    const meta=node('div',[c.channel==='telegram'?'TG':String(c.channel||'').toUpperCase(),c.manager_name,projectLabels.get(c.project_key)||c.project_key,'Отправлено '+clock(c.sent_at)+' МСК'].filter(Boolean).join(' · '));meta.className='usageMeta';person.appendChild(meta);header.appendChild(person);
    const rules=c.base_instruction_current===true?'текущая редакция':c.base_instruction_current===false?'прежняя редакция':'редакция не определена';
    const saved=typeof c.generated_at==='string'?(c.source==='prepared'?'Первый просмотр готового ответа: ':'Черновик сохранён: ')+clock(c.generated_at)+' МСК':'';
    const provenance=node('div',[saved,'Общие правила: '+rules].filter(Boolean).join(' · '));provenance.className='usageMeta usageProvenance';provenance.title='Сравниваются только общие инструкции. Отметка не подтверждает модель, дополнительные знания или качество ответа. Для готового ответа показан первый сохранённый просмотр, а не время его подготовки.';person.appendChild(provenance);
    const badge=node('span',!c.initial_text_available?(c.edited?'Правка после отправки':'Текущая версия'):c.recorded_text_matches_proposal?'Без правок при отправке':'С правками');badge.className='usageBadge'+(c.recorded_text_matches_proposal&&c.initial_text_available?'':' changed');header.appendChild(badge);article.appendChild(header);
    const question=node('div');question.className='usageQuestion';const label=node('span',c.question_edited?'Сообщение туриста · позднее изменено':'Сообщение туриста перед подготовкой');label.className='usageLabel';question.append(label,node('p',c.question_text??'Исходное сообщение недоступно. Доступный контекст можно посмотреть в переписке.'));if(c.question_truncated){const p=node('p','Показана часть сообщения.');p.className='usageCaveat';question.appendChild(p)}article.appendChild(question);
    const comparison=node('div');comparison.className='usageComparison';comparison.appendChild(version('AI предложил',c.proposed_text,c.source==='prepared'?'заранее':'по кнопке AI'));
    const sent=version(c.initial_text_available?'Менеджер отправил':'Текст сообщения сейчас',c.current_sent_text);
    if(!c.initial_text_available){const p=node('p',c.edited?'Сообщение редактировали после отправки. Здесь показана его текущая версия.':'Первоначальная отправленная версия не подтверждена текущим текстом.');p.className='usageCaveat';sent.appendChild(p)}
    if(c.edited||!c.initial_text_available)sent.appendChild(sentTextViewer(c,article));
    if(c.current_sent_text_truncated){const p=node('p','Показана часть ответа. Полный текст — в переписке.');p.className='usageCaveat';sent.appendChild(p)}comparison.appendChild(sent);article.appendChild(comparison);
    if(c.reason){const reason=node('p','Почему AI предложил этот ответ: '+c.reason);reason.className='usageReason';article.appendChild(reason)}
    article.appendChild(inputViewer(c,article));
    article.appendChild(assessmentViewer(c,article));
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
