// Explicit empty account preferences for pre-existing feature fixtures. Unknown
// actions still follow each fixture's strict rejection path.
function emptyPersonalWorkspaceSnapshot(){
  return {ok:true,version:0,state:{tabs:[],labels:[],members:{},pins:[],settings:{start_tab:'waiting',density:'comfortable',font:'normal'}}};
}
module.exports={emptyPersonalWorkspaceSnapshot};
