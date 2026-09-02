const S={csrf:'',catalog:{stages:[],tags:[]},saving:{stage:false,tag:false}};
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

function setFormSaving(kind,saving){
    S.saving[kind]=Boolean(saving);
    const form=$(kind==='stage'?'stageForm':'tagForm');
    const submit=form.querySelector('button[type="submit"]');
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
    const r=await safeApi('admin_catalog',{},'Не удалось загрузить этапы и теги. Проверьте соединение и повторите попытку.');
    if(!r)return;
    if(!r.ok){status('Не удалось загрузить этапы и теги.');return}
    S.catalog=r.catalog||{stages:[],tags:[]};
    render();
}

function leadCountLabel(v){
    const n=Number(v)||0;
    return n?`${n} лид${n%10===1&&n%100!==11?'':n%10>=2&&n%10<=4&&(n%100<10||n%100>=20)?'а':'ов'}`:'нет лидов';
}

function render(){
    $('stages').innerHTML=(S.catalog.stages||[]).map(s=>`<div class="item ${Number(s.is_active)?'':'inactive'}"><div class="chip"><span class="dot" style="background:${esc(s.color)}"></span><b>${esc(s.display_name)}</b></div><span class="muted">${esc(s.stage_key)}</span><span class="meta muted">${Number(s.sort_order)||0} · ${leadCountLabel(s.usage_count)}${Number(s.is_terminal)?' · финальный':''}${Number(s.is_won)?' · продажа':''}</span><button class="secondary edit" onclick="editStage('${esc(s.stage_key)}')">Изменить</button></div>`).join('')||'<p>Этапов нет</p>';
    $('tags').innerHTML=(S.catalog.tags||[]).map(t=>`<div class="item ${Number(t.is_active)?'':'inactive'}"><div class="chip"><span class="dot" style="background:${esc(t.color)}"></span><b>${esc(t.display_name)}</b></div><span class="muted">${esc(t.tag_key)}</span><span class="meta muted">${Number(t.sort_order)||0} · ${leadCountLabel(t.usage_count)}</span><button class="secondary edit" onclick="editTag(${Number(t.id)})">Изменить</button></div>`).join('')||'<p>Тегов пока нет</p>';
}

function clearStage(){
    $('stageKey').value='';$('stageKey').readOnly=false;$('stageName').value='';$('stageColor').value='#64748b';$('stageSort').value='0';$('stageActive').checked=true;$('stageTerminal').checked=false;$('stageWon').checked=false;$('stageForm').classList.add('hidden');
}

function clearTag(){
    $('tagId').value='';$('tagKey').value='';$('tagName').value='';$('tagColor').value='#64748b';$('tagSort').value='0';$('tagActive').checked=true;$('tagForm').classList.add('hidden');
}

window.editStage=key=>{
    const s=S.catalog.stages.find(x=>x.stage_key===key);if(!s)return;
    $('stageKey').value=s.stage_key;$('stageKey').readOnly=true;$('stageName').value=s.display_name;$('stageColor').value=s.color||'#64748b';$('stageSort').value=s.sort_order||0;$('stageActive').checked=Number(s.is_active)===1;$('stageTerminal').checked=Number(s.is_terminal)===1;$('stageWon').checked=Number(s.is_won)===1;$('stageForm').dataset.usageCount=String(Number(s.usage_count)||0);$('stageForm').classList.remove('hidden');$('stageForm').scrollIntoView({behavior:'smooth',block:'nearest'});
};

window.editTag=id=>{
    const t=S.catalog.tags.find(x=>Number(x.id)===Number(id));if(!t)return;
    $('tagId').value=t.id;$('tagKey').value=t.tag_key;$('tagName').value=t.display_name;$('tagColor').value=t.color||'#64748b';$('tagSort').value=t.sort_order||0;$('tagActive').checked=Number(t.is_active)===1;$('tagForm').dataset.usageCount=String(Number(t.usage_count)||0);$('tagForm').classList.remove('hidden');$('tagForm').scrollIntoView({behavior:'smooth',block:'nearest'});
};

function bind(){
    $('newStage').onclick=()=>{clearStage();$('stageForm').dataset.usageCount='0';$('stageForm').classList.remove('hidden')};
    $('cancelStage').onclick=clearStage;
    $('newTag').onclick=()=>{clearTag();$('tagForm').dataset.usageCount='0';$('tagForm').classList.remove('hidden')};
    $('cancelTag').onclick=clearTag;
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
        if(!r.ok){
            if(r.error==='stage_in_use'){status(stageErrorText(r.error,r.usage_count));return}
            status(stageErrorText(r.error,r.usage_count));return;
        }
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
        if(!r.ok){
            if(r.error==='tag_in_use'){status(tagErrorText(r.error,r.usage_count));return}
            status(tagErrorText(r.error,r.usage_count));return;
        }
        status('Тег сохранён.',true);clearTag();await load();
    };
}

boot();