(function(){
function bindSalesEditor({canEdit=false,pipeline={}}={}){if(!canEdit)return;window.WorkspaceV2StageTags?.bind({canEdit,pipeline});window.WorkspaceV2Outcome?.bind({canEdit,pipeline})}
window.WorkspaceV2Pipeline={bindSalesEditor};
})();
