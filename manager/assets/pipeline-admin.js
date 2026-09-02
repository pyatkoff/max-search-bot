const S={csrf:'',catalog:{stages:[],tags:[],close_reasons:[]},saving:{stage:false,tag:false,closeReason:false}};
const $=id=>document.getElementById(id);
const api=(action,data={})=>ManagerHttpClient.request(action,data,S.csrf,'pipeline-api.php');
const esc=v=>{const d=document.createElement('div');d.textContent=v??'';return d.innerHTML};

function status(msg,ok=false){
    const el=$('status');
    el.textContent=msg||'';
    el.className=msg?(ok?'status-ok':'status-error'):'';
}

function gateMessage(msg){
    const el=$('denied');
    el.textContent=msg;
    el.classList.remove('hidden');
}

function kindLabel(kind){return kind==='stage'?'этапа':kind==='tag'?'тега':'причины отказа'}
function formFor(kind){return $(kind==='stage'?'stageForm':kind==='tag'?'tagForm':'closeReasonForm')}
function titleFor(kind){return $(kind==='stage'?'stageTitle':kind==='tag'?'tagTitle':'closeReasonTitle')||formFor(kind)?.querySelector('h3')}

function setEditorTitle(kind,label=''){
    const title=titleFor(kind);if(!title)return;
    const editing=String(label||'').trim();
    const noun=kind==='stage'?'этап':kind==='tag'?'тег':'причина отказа';
    title.textContent=editing?`Редактируется ${noun}: ${editing}`:`Новый ${noun}`;
}

function editorBusy(kind){
    if(!S.saving[kind])return false;
    status(`Дождитесь завершения сохранения ${kindLabel(kind)}.`);
    return true;
}

function setFormSaving(kind,saving){
    S.saving[kind]=Boolean(saving);
    const form=formFor(kind);const submit=form?.querySelector('button[type="submit"]');
    if(submit)submit.disabled=Boolean(saving);
}

function stageErrorText(error,usageCount=0){
    const messages={
        duplicate_stage_key:'Этап с таким кодом уже существует.',
        invalid_stage_key:'Код этапа должен содержать только латинские буквы, цифры, дефис или подчёркивание.',
        invalid_display_name:'Укажите название этапа до 96 символов.',
        save_failed:'Этап не сохранён из-за ошибки сервера. Повторите попытку.'
    };
    if(error==='stage_in_use')return `Нельзя отключить этап: в нём ${leadCountLabel(usageCount)}. Сначала перенесите лиды в другой этап.`;
    return messages[error]||'Этап не сохранён. Повторите попытку.';
}

function tagErrorText(error,usageCount=0){
    const messages={
        duplicate_tag_key:'Тег с таким кодом уже существует.',
        invalid_tag_key:'Код тега должен содержать только латинские буквы, цифры, дефис или подчёркивание.',
        invalid_display_name:'Укажите название тега до 96 символов.',
        not_found:'Тег больше не существует. Обновите список и повторите действие.',
        save_failed:'Тег не сохранён из-за ошибки сервера. Повторите попытку.'
    };
    if(error==='tag_in_use')return `Нельзя отключить тег: он назначен ${leadCountLabel(usageCount)}. Сначала снимите тег с этих лидов.`;
    return messages[error]||'Тег не сохранён. Повторите попытку.';
}

function closeReasonErrorText(error){
    const messages={
        duplicate_close_reason_key:'Причина с таким кодом уже существует.',
        invalid_close_reason_key:'Код причины должен содержать только латинские буквы, цифры, дефис или подчёркивание.',
        invalid_display_name:'Укажите название причины до 96 символов.',
        save_failed:'Причина отказа не сохранена из-за ошибки сервера. Повторите попытку.'
    };
    return messages[error]||'Причина отказа не сохранена. Повторите попытку.';
}

async function safeApi(action,data={},failureMessage='Не удалось выполнить запрос. Проверьте соединение и повторите попытку.'){
    try{return await api(action,data)}catch(e){status(failureMessage);return null}
}

async function boot(){
    let me;
    try{me=await ManagerHttpClient.request('me')}catch(e){gateMessage('Не удалось проверить доступ. Проверьте соединение и обновите страницу.');return}
    if(!me.ok||!me.manager||me.manager.role!=='admin'){gateMessage('Доступно только администратору.');return}
    S.csrf=me.csrf;
    $('app').classList.remove('hidden');
    bind();
    await load();
}

async function load(){
    const r=await safeApi('admin_catalog',{},'Не удалось загрузить справочники воронки. Проверьте соединение и повторите попытку.');
    if(!r)return;
    if(!r.ok){status('Не удалось загрузить справочники воронки.');return}
    S.catalog=r.catalog||{stages:[],tags:[],close_reasons:[]};
    render();
}

function leadCountLabel(v){
    const n=Number(v)||0;
    return n?`${n} лид${n%10===1&&n%100!==11?'':n%10>=2&&n%10<=4&&(n%100<10||n%100>=20)?'а':'ов'}`:'нет лидов';
}

function render(){
    $('stages').innerHTML=(S.catalog.stages||[]).map(s=>`<div class="item ${Number(s.is_active)?'':'inactive'}"><div class="chip"><span class="dot" style="background:${esc(s.color)}"></span><b>${esc(s.display_name)}</b></div><span class="muted">${esc(s.stage_key)}</span><span class="meta muted">${Number(s.sort_order)||0} · ${leadCountLabel(s.usage_count)}${Number(s.is_terminal)?' · финальный':''}${Number(s.is_won)?' · продажа':''}</span><button class="secondary edit" onclick="editStage('${esc(s.stage_key)}')">Изменить</button></div>`).join('')||'<p>Этапов нет</p>';
    $('tags').innerHTML=(S.catalog.tags||[]).map(t=>`<div class="item ${Number(t.is_active)?'':'inactive'}"><div class="chip"><span class="dot" style="background:${esc(t.color)}"></span><b>${esc(t.display_name)}</b></div><span class="muted">${esc(t.tag_key)}</span><span class="meta muted">${Number(t.sort_order)||0} · ${leadCountLabel(t.usage_count)}</span><button class="secondary edit" onclick="editTag(${Number(t.id)})">Изменить</button></div>`).join('')||'<p>Тегов пока нет</p>';
    $('closeReasons').innerHTML=(S.catalog.close_reasons||[]).map(r=>`<div class="item ${Number(r.is_active)?'':'inactive'}"><div class="chip"><b>${esc(r.display_name)}</b></div><span class="muted">${esc(r.reason_key)}</span><span class="meta muted">${Number(r.sort_order)||0} · ${leadCountLabel(r.usage_count)}${Number(r.is_active)?'':' · неактивна'}</span><button class="secondary edit" onclick="editCloseReason('${esc(r.reason_key)}')">Изменить</button></div>`).join('')||'<p>Причин отказа пока нет</p>';
}

function clearStage(){
    $('stageKey').value='';$('stageKey').readOnly=false;$('stageName').value='';$('stageColor').value='#64748b';$('stageSort').value='0';$('stageActive').checked=true;$('stageTerminal').checked=false;$('stageWon').checked=false;setEditorTitle('stage');$('stageForm').classList.add('hidden');
}

function clearTag(){
    $('tagId').value='';$('tagKey').value='';$('tagName').value='';$('tagColor').value='#64748b';$('tagSort').value='0';$('tagActive').checked=true;setEditorTitle('tag');$('tagForm').classList.add('hidden');
}

function clearCloseReason(){
    $('closeReasonKey').value='';$('closeReasonKey').readOnly=false;$('closeReasonName').value='';$('closeReasonSort').value='0';$('closeReasonActive').checked=true;setEditorTitle('closeReason');$('closeReasonForm').classList.add('hidden');
}

window.editStage=key=>{
    if(editorBusy('stage'))return;
    const s=S.catalog.stages.find(x=>x.stage_key===key);if(!s)return;
    $('stageKey').value=s.stage_key;$('stageKey').readOnly=true;$('stageName').value=s.display_name;$('stageColor').value=s.color||'#64748b';$('stageSort').value=s.sort_order||0;$('stageActive').checked=Number(s.is_active)===1;$('stageTerminal').checked=Number(s.is_terminal)===1;$('stageWon').checked=Number(s.is_won)===1;$('stageForm').dataset.usageCount=String(Number(s.usage_count)||0);setEditorTitle('stage',s.display_name||s.stage_key);$('stageForm').classList.remove('hidden');$('stageForm').scrollIntoView({behavior:'smooth',block:'nearest'});
};

window.editTag=id=>{
    if(editorBusy('tag'))return;
    const t=S.catalog.tags.find(x=>Number(x.id)===Number(id));if(!t)return;
    $('tagId').value=t.id;$('tagKey').value=t.tag_key;$('tagName').value=t.display_name;$('tagColor').value=t.color||'#64748b';$('tagSort').value=t.sort_order||0;$('tagActive').checked=Number(t.is_active)===1;$('tagForm').dataset.usageCount=String(Number(t.usage_count)||0);setEditorTitle('tag',t.display_name||t.tag_key);$('tagForm').classList.remove('hidden');$('tagForm').scrollIntoView({behavior:'smooth',block:'nearest'});
};

window.editCloseReason=key=>{
    if(editorBusy('closeReason'))return;
    const r=(S.catalog.close_reasons||[]).find(x=>x.reason_key===key);if(!r)return;
    $('closeReasonKey').value=r.reason_key;$('closeReasonKey').readOnly=true;$('closeReasonName').value=r.display_name;$('closeReasonSort').value=r.sort_order||0;$('closeReasonActive').checked=Number(r.is_active)===1;setEditorTitle('closeReason',r.display_name||r.reason_key);$('closeReasonForm').classList.remove('hidden');$('closeReasonForm').scrollIntoView({behavior:'smooth',block:'nearest'});
};

function bind(){
    $('newStage').onclick=()=>{if(editorBusy('stage'))return;clearStage();$('stageForm').dataset.usageCount='0';$('stageForm').classList.remove('hidden')};
    $('cancelStage').onclick=()=>{if(editorBusy('stage'))return;clearStage()};
    $('newTag').onclick=()=>{if(editorBusy('tag'))return;clearTag();$('tagForm').dataset.usageCount='0';$('tagForm').classList.remove('hidden')};
    $('cancelTag').onclick=()=>{if(editorBusy('tag'))return;clearTag()};
    $('newCloseReason').onclick=()=>{if(editorBusy('closeReason'))return;clearCloseReason();$('closeReasonForm').classList.remove('hidden')};
    $('cancelCloseReason').onclick=()=>{if(editorBusy('closeReason'))return;clearCloseReason()};
    $('stageWon').onchange=()=>{if($('stageWon').checked)$('stageTerminal').checked=true};

    $('stageForm').onsubmit=async e=>{
        e.preventDefault();
        if(S.saving.stage)return;
        status('');
        const usage=Number($('stageForm').dataset.usageCount||0);
        if(!$('stageActive').checked&&usage>0){status(stageErrorText('stage_in_use',usage));return}
        setFormSaving('stage',true);
        const r=await safeApi('save_stage',{
            stage_key:$('stageKey').value.trim(),display_name:$('stageName').value.trim(),color:$('stageColor').value,sort_order:Number($('stageSort').value||0),is_active:$('stageActive').checked,is_terminal:$('stageTerminal').checked,is_won:$('stageWon').checked
        },'Этап не сохранён. Проверьте соединение и повторите попытку.');
        setFormSaving('stage',false);
        if(!r)return;
        if(!r.ok){status(stageErrorText(r.error,r.usage_count));return}
        status('Этап сохранён.',true);clearStage();await load();
    };

    $('tagForm').onsubmit=async e=>{
        e.preventDefault();
        if(S.saving.tag)return;
        status('');
        const usage=Number($('tagForm').dataset.usageCount||0);
        if(!$('tagActive').checked&&usage>0){status(tagErrorText('tag_in_use',usage));return}
        setFormSaving('tag',true);
        const r=await safeApi('save_tag',{
            id:Number($('tagId').value||0),tag_key:$('tagKey').value.trim(),display_name:$('tagName').value.trim(),color:$('tagColor').value,sort_order:Number($('tagSort').value||0),is_active:$('tagActive').checked
        },'Тег не сохранён. Проверьте соединение и повторите попытку.');
        setFormSaving('tag',false);
        if(!r)return;
        if(!r.ok){status(tagErrorText(r.error,r.usage_count));return}
        status('Тег сохранён.',true);clearTag();await load();
    };

    $('closeReasonForm').onsubmit=async e=>{
        e.preventDefault();
        if(S.saving.closeReason)return;
        status('');setFormSaving('closeReason',true);
        const r=await safeApi('save_close_reason',{
            reason_key:$('closeReasonKey').value.trim(),display_name:$('closeReasonName').value.trim(),sort_order:Number($('closeReasonSort').value||0),is_active:$('closeReasonActive').checked
        },'Причина отказа не сохранена. Проверьте соединение и повторите попытку.');
        setFormSaving('closeReason',false);
        if(!r)return;
        if(!r.ok){status(closeReasonErrorText(r.error));return}
        status('Причина отказа сохранена.',true);clearCloseReason();await load();
    };
}

boot();