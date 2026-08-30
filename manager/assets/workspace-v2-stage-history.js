(function(){
const W=window.WorkspaceV2,{esc}=W;
function markup(history){
    history=Array.isArray(history)?history:[];
    if(!history.length)return '<div class="leadStageHistoryEmpty">История появится после следующей смены этапа.</div>';
    const rows=history.slice(0,5).map(x=>{
        const from=String(x.from_stage_name||x.from_stage_key||'Новый лид');
        const to=String(x.to_stage_name||x.to_stage_key||'Этап');
        const manager=String(x.changed_by_manager_name||'').trim();
        const at=String(x.created_at||'').trim().slice(0,16);
        return `<div class="leadStageHistoryRow"><span class="leadStageHistoryFlow">${esc(from)} <b>→</b> ${esc(to)}</span><span class="leadStageHistoryMeta">${manager?esc(manager)+(at?' · ':''):''}${esc(at)}</span></div>`;
    }).join('');
    return `<details class="leadStageHistoryDetails"><summary>История этапов <span>${history.length}</span></summary><div class="leadStageHistoryList">${rows}</div></details>`;
}
window.WorkspaceV2StageHistory={markup};
})();
