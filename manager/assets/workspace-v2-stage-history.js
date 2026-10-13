(function(){
const W=window.WorkspaceV2,{esc}=W;
function formatStageSince(value){return window.ManagerTime.format(value)}
function currentSinceMarkup(history){
    history=Array.isArray(history)?history:[];
    const since=formatStageSince(history[0]?.created_at);
    return since?`<div class="leadStageHistoryEmpty">На текущем этапе с ${esc(since)}</div>`:'';
}
function markup(history){
    history=Array.isArray(history)?history:[];
    if(!history.length)return '<div class="leadStageHistoryEmpty">История появится после следующей смены этапа.</div>';
    const rows=history.slice(0,5).map(x=>{
        const from=String(x.from_stage_name||x.from_stage_key||'Новый лид');
        const to=String(x.to_stage_name||x.to_stage_key||'Этап');
        const manager=String(x.changed_by_manager_name||'').trim();
        const at=window.ManagerTime.format(x.created_at,{year:true});
        return `<div class="leadStageHistoryRow"><span class="leadStageHistoryFlow">${esc(from)} <b>→</b> ${esc(to)}</span><span class="leadStageHistoryMeta">${manager?esc(manager)+(at?' · ':''):''}${esc(at)}</span></div>`;
    }).join('');
    return `${currentSinceMarkup(history)}<details class="leadStageHistoryDetails"><summary>История этапов <span>${history.length}</span></summary><div class="leadStageHistoryList">${rows}</div></details>`;
}
window.WorkspaceV2StageHistory={markup,formatStageSince,currentSinceMarkup};
})();
