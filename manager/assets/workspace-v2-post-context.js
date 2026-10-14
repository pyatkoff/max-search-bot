(function(){
  'use strict';
  let W=null,S=null,$=null;
  let host=null,endpoint='post-context-api.php',active=null,sequence=0,bound=false;
  const disclosures=new Map();
  function runtime(){W=window.WorkspaceV2;if(!W)return false;S=W.S;$=W.$;return !!S&&typeof $==='function'}
  function node(tag,className,text){const el=document.createElement(tag);if(className)el.className=className;if(text!==undefined)el.textContent=String(text);return el}
  function safePostUrl(value,platform){
    if(typeof value!=='string'||value.length>2048)return '';
    try{
      const url=new URL(value),hosts=platform==='max'?['max.ru']:platform==='telegram'?['t.me']:[];
      if(url.protocol!=='https:'||!hosts.includes(url.hostname.toLowerCase())||url.username||url.password||url.port||url.search||url.hash)return '';
      const valid=platform==='max'?/^\/c\/-?[0-9]+\/[A-Za-z0-9_-]+\/?$/.test(url.pathname):/^\/(?:c\/[0-9]+|[A-Za-z][A-Za-z0-9_]{3,31})\/[0-9]+\/?$/.test(url.pathname);
      return valid?url.href:'';
    }catch{return ''}
  }
  function safeMediaUrl(value,conversationId,entryId){
    if(!Number.isSafeInteger(conversationId)||conversationId<=0||!Number.isSafeInteger(entryId)||entryId<=0)return '';
    const expected='/manager/post-context-media.php?conversation_id='+conversationId+'&entry_id='+entryId+'&attachment=0';
    return typeof value==='string'&&value===expected?value:'';
  }
  function moscowTime(value){
    const input=String(value||'');
    if(!input)return '';
    if(window.ManagerTime?.parse?.(input))return window.ManagerTime.format(input,{year:true})+' МСК';
    // Registry SQL timestamps are UTC; an explicit zone is preserved.
    if(!/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d{1,3})?(?:Z|[+-]\d{2}:?\d{2})?$/.test(input))return '';
    const normalized=input.replace(' ','T')+(/(?:Z|[+-]\d{2}:?\d{2})$/.test(input)?'':'Z');
    const date=new Date(normalized);if(!Number.isFinite(date.getTime()))return '';
    return new Intl.DateTimeFormat('ru-RU',{timeZone:'Europe/Moscow',year:'numeric',day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'}).format(date)+' МСК';
  }
  function capture(conversation){
    if(!S)return null;
    const id=Number(S.current||0),managerId=Number(S.manager?.id||0);
    if(S.authExpired||!id||!managerId||Number(conversation?.id)!==id||Number(S.detail?.conversation?.id)!==id)return null;
    const authGeneration=S.authGeneration,openGeneration=window.WorkspaceV2Conversation?.getOpenGeneration?.()??0;
    return {id,managerId,authGeneration,openGeneration,key:[managerId,authGeneration,id,openGeneration,String(conversation.last_message_at||'')].join(':')};
  }
  function canonicalReadFailed(){
    return ['conversationLoadStatus','conversationRefreshStatus'].some(id=>{
      const status=$?.(id);return !!status&&status.classList.contains('error')&&!status.classList.contains('hidden')&&!!String(status.textContent||'').trim();
    });
  }
  function current(context){const now=capture(S.detail?.conversation);return !!now&&now.key===context.key&&active?.key===context.key}
  function rememberDisclosures(){
    if(!host||!active)return;
    host.querySelectorAll('details[data-post-disclosure]').forEach(el=>{
      const key=active.managerId+':'+active.id+':'+el.dataset.postDisclosure;
      disclosures.delete(key);disclosures.set(key,{open:el.open,top:el.querySelector('.postContextText')?.scrollTop||0});
    });
    while(disclosures.size>80)disclosures.delete(disclosures.keys().next().value);
  }
  function disclosure(key,label,context){
    const el=node('details','postContextDisclosure');el.dataset.postDisclosure=key;
    el.appendChild(node('summary','',label));
    const saved=disclosures.get(context.managerId+':'+context.id+':'+key);el.open=saved?.open===true;
    return {el,top:saved?.top||0};
  }
  function notice(text,kind){
    if(!host)return;
    host.hidden=false;host.dataset.state=kind;host.setAttribute('aria-busy',kind==='loading'?'true':'false');
    const status=node('div','postContextNotice'+(kind==='error'?' isError':''),text);status.setAttribute('role','status');
    host.replaceChildren(status);
  }
  function clear({forget=false}={}){
    sequence++;rememberDisclosures();active=null;
    if(forget)disclosures.clear();
    if(host){if(host.children.length)host.replaceChildren();host.hidden=true;host.removeAttribute('aria-busy');host.dataset.state='idle'}
  }
  function entryMarkup(entry,context,previous=false){
    const card=node('section',previous?'postContextPrevious':'postContextCard');
    if(entry?.unavailable===true||entry?.available===false){card.appendChild(node('div','postContextNotice','Исходный пост этого обращения недоступен.'));return card}
    const head=node('div','postContextHead'),identity=node('div','postContextIdentity');
    identity.appendChild(node('div','postContextEyebrow',previous?'Предыдущее обращение по посту':'Обращение по посту'));
    identity.appendChild(node('div','postContextTitle',String(entry.title||'Публикация').slice(0,300)));
    const platform=entry.platform==='max'?'MAX':entry.platform==='telegram'?'Telegram':'';
    const channel=String(entry.channel_name||'').trim();
    const entered=moscowTime(entry.entered_at);
    identity.appendChild(node('div','postContextMeta',[channel,platform,entered?'Вход '+entered:''].filter(Boolean).join(' · ')));
    const media=safeMediaUrl(entry.media_url,context.id,Number(entry.entry_id));
    if(media){
      const preview=node('a','postContextThumbLink');preview.href=media;preview.target='_blank';preview.rel='noopener noreferrer';preview.setAttribute('aria-label','Открыть фото из публикации');
      const image=node('img','postContextThumb');image.src=media;image.alt='Фото из публикации';image.loading='lazy';image.decoding='async';image.referrerPolicy='no-referrer';
      image.addEventListener('error',()=>{if(current(context)&&preview.parentElement===head){preview.replaceChildren(node('span','postContextPhotoFallback','Фото недоступно'));preview.removeAttribute('href')}});
      preview.appendChild(image);head.appendChild(preview);
    }
    head.appendChild(identity);
    const url=safePostUrl(entry.post_url,entry.platform);
    if(url){const link=node('a','postContextOriginal','Открыть пост');link.href=url;link.target='_blank';link.rel='noopener noreferrer';head.appendChild(link)}
    card.appendChild(head);
    const version=Number(entry.version_number||0),label='Условия и цены из публикации'+(version>0?' · версия '+version:'');
    const detail=disclosure('entry-'+String(entry.entry_id),label,context);
    const text=node('div','postContextText',entry.text?String(entry.text):'Текст публикации не сохранён.');
    // Rich post markup is intentionally displayed as literal text. Provider media
    // stays in the original post until an authorized protected viewer is available.
    text.dataset.postScrollTop=String(detail.top);detail.el.appendChild(text);card.appendChild(detail.el);
    if(!media&&Number(entry.media_count||0)>0)card.appendChild(node('div','postContextMediaHint','Фото и вложения — в оригинальном посте'));
    return card;
  }
  function render(context,payload){
    if(!host||!current(context))return;
    rememberDisclosures();host.hidden=false;host.dataset.state='ready';host.setAttribute('aria-busy','false');
    const latest=payload?.current,history=Array.isArray(payload.history)?payload.history.slice(0,4):[];
    if(!latest&&!history.length){
      // Ordinary/legacy dialogues have no publication entry. Keep the optional
      // panel absent without discarding the completed read/deduplication key.
      host.replaceChildren();host.hidden=true;host.dataset.state='empty';return;
    }
    const items=[];
    if(latest?.unavailable===true||latest?.available===false){const unavailable=node('div','postContextNotice isError','Исходный пост этого обращения недоступен.');unavailable.setAttribute('role','status');items.push(unavailable)}
    else if(latest)items.push(entryMarkup(latest,context));
    if(history.length){
      const older=disclosure('history','Предыдущие обращения · '+history.length,context);older.el.classList.add('postContextHistory');
      const list=node('div','postContextHistoryList');history.forEach(entry=>list.appendChild(entryMarkup(entry,context,true)));
      older.el.appendChild(list);items.push(older.el);
    }
    host.replaceChildren(...items);
    host.querySelectorAll('.postContextText').forEach(el=>{el.scrollTop=Number(el.dataset.postScrollTop||0)});
  }
  function sync(conversation){
    if(!runtime())return Promise.resolve(false);
    conversation=conversation||S.detail?.conversation;
    // Canonical polling can retain the old detail while revoking its access.
    // Its fresh read error therefore invalidates this optional presentation too.
    if(canonicalReadFailed()){clear();return Promise.resolve(false)}
    const context=capture(conversation);
    if(!context){clear({forget:S.authExpired||!S.manager?.id});return Promise.resolve(false)}
    if(active?.managerId!==undefined&&active.managerId!==context.managerId)clear({forget:true});
    if(active?.key===context.key)return active.pending||Promise.resolve(active.ready===true);
    rememberDisclosures();const attempt=++sequence;
    active={...context,ready:false};notice('Загружаем исходный пост…','loading');
    const pending=(async()=>{
      try{
        // Canonical workspace transport owns cookies, CSRF and login recovery.
        if(typeof W.request!=='function'){notice('Не удалось загрузить исходный пост. Обновите диалог, чтобы повторить.','error');return false}
        const json=await W.request(endpoint,'detail',{conversation_id:context.id});
        if(attempt!==sequence||!current(context))return false;
        if(json?.ok!==true||Number(json.conversation_id)!==context.id||!json.context||typeof json.context!=='object'){
          notice([403,404].includes(json?.http_status)?'Пост недоступен для этого диалога.':'Не удалось загрузить исходный пост. Обновите диалог, чтобы повторить.','error');return false;
        }
        active.ready=true;render(context,json.context);return true;
      }catch(error){if(attempt===sequence){if(S.authExpired)clear({forget:true});else if(current(context))notice('Не удалось загрузить исходный пост. Обновите диалог, чтобы повторить.','error')}return false}
      finally{if(attempt===sequence&&active)active.pending=null}
    })();
    active.pending=pending;return pending;
  }
  function init(options={}){
    if(!runtime())return false;
    endpoint=options.endpoint||endpoint;
    if(options.container)host=options.container;
    if(!host){const head=$('conversationZone')?.querySelector('.conversationHead');if(!head)return false;host=node('div','postContext');host.id='conversationPostContext';host.hidden=true;head.insertAdjacentElement('afterend',host)}
    host.classList.add('postContext');host.setAttribute('aria-label','Исходная публикация клиента');
    if(!bound){
      bound=true;
      // The canonical successful header render and login overlay are the only
      // observed surfaces. This observer never reads the Inbox or mutates drafts.
      if(typeof MutationObserver==='function'){
        const observer=new MutationObserver(records=>{
          if(S.authExpired||!S.manager?.id){clear({forget:true});return}
          const identity=capture(S.detail?.conversation);
          if(active&&(!identity||identity.managerId!==active.managerId||identity.authGeneration!==active.authGeneration))clear({forget:!identity||identity?.managerId!==active?.managerId});
          if(canonicalReadFailed()){clear();return}
          const status=$('conversationLoadStatus');
          const loadChanged=records.some(r=>r.target===status||Array.from(r.addedNodes||[]).includes(status));
          if(loadChanged){if(status?.classList.contains('loading')||status?.classList.contains('error'))clear();return}
          if(records.some(r=>r.target===$('conversationTitle')))void sync();
        });
        if($('conversationZone'))observer.observe($('conversationZone'),{attributes:true,attributeFilter:['class'],childList:true,subtree:true});
        observer.observe(document.body,{attributes:true,attributeFilter:['class'],childList:true});
      }
      window.addEventListener('pagehide',()=>clear({forget:true}));
    }
    void sync();return true;
  }
  window.WorkspaceV2PostContext={init,sync,clear,safePostUrl,safeMediaUrl,moscowTime};
})();
