(function(){
'use strict';
// Synthetic UI fixture only. It never supplies a production credential, opens
// a provider/collector, fetches a URL, publishes or creates a native message.
const storageKey='anytour.editorDemo.v1',copy=value=>JSON.parse(JSON.stringify(value));
const conversation={id:1,manager_id:1,status:'manager',channel:'telegram',display_name:'Демонстрационный турист'};
const initial={title:'Демонстрация подборки',intro:'',offers:[{id:'a'.repeat(32),selected:true,hotel:'Пример отеля у моря',destination:'Египет',departure:'Москва',room:'Standard',meal:'Всё включено',source_label:'Демонстрационный пример',rationale:'',compromise:'',flights:'Требуют проверки',inclusions:'Требуют проверки',constraints:'Синтетические условия, не предложение к продаже',stars:4,nights:7,adults:2,date_from:'2027-06-05',child_ages:[],amount:'200000',currency:'RUB',source_url:'',observed_at:'',photos:[]}]};
let state={version:1,draft:copy(initial),receipts:{}};
try{const saved=JSON.parse(sessionStorage.getItem(storageKey)||'null');if(saved&&Number.isInteger(saved.version)&&saved.version>0&&Array.isArray(saved.draft?.offers)&&saved.draft.offers.length<=20&&JSON.stringify(saved).length<900000&&saved.receipts&&typeof saved.receipts==='object')state=saved}catch(e){}
const capture=()=>({actor:1,conversation:1,auth:1,open:1}),isCurrent=t=>!!t&&t.actor===1&&t.conversation===1&&t.auth===1&&t.open===1;
const snapshot=()=>({conversation_id:1,version:state.version,draft:copy(state.draft),can_edit:true});
const persist=()=>{try{sessionStorage.setItem(storageKey,JSON.stringify(state))}catch(e){}};
const host={
  contract:'tour-selection-editor-host-v1',origin:location.origin,capture,isCurrent,
  notice:'Демонстрация · правки только в этой вкладке · без отправки',savedLabel:'Сохранено в этой вкладке',savedMessage:'Демонстрационный черновик сохранён в этой вкладке.',
  canOpen:c=>c?.id===1,canEdit:()=>true,
  launcher:()=>document.getElementById('demoLauncher'),
  takeCollectorIntent:()=>false,activate:()=>{},
  formatTime:()=>'',
  openCollector:()=>{document.getElementById('demoNotice').textContent='Сборщик подключается в рабочем редакторе. Здесь используется только демонстрационный черновик.'},
  request:async(resource,action,data,options)=>{
    if(!options.isCurrent()||options.signal.aborted||data.conversation_id!==1)return {ok:false,error:'stale_context'};
    if(resource==='tour-selections-api.php'){
      if(action==='get')return {ok:true,selection:snapshot()};
      if(action==='operation')return {ok:true,found:Object.hasOwn(state.receipts,data.operation_id),saved_version:state.receipts[data.operation_id]??null,selection:snapshot()};
      if(action==='save'){
        if(!Object.hasOwn(state.receipts,data.operation_id)){
          if(data.version!==state.version)return {ok:false,error:'conflict',selection:snapshot()};
          state.draft=copy(data.draft);state.version++;state.receipts[data.operation_id]=state.version;
          // Bounded tab-local simulation, never the canonical server store.
          const keys=Object.keys(state.receipts);for(const k of keys.slice(0,Math.max(0,keys.length-20)))delete state.receipts[k];persist();
        }
        return {ok:true,saved_version:state.receipts[data.operation_id],selection:snapshot()};
      }
      if(action==='history')return {ok:true,publications:[]};
      if(action==='trip_context')return {ok:true,trip:{state:'unbound',revision:'',fields:{},details:[],warnings:[]}};
    }
    // There is deliberately no duplicate tariff validator/card renderer/sender.
    document.getElementById('demoNotice').textContent='Предпросмотр карточек, публикация, фото и отправка доступны в рабочем редакторе после проверки сервером. Демонстрация ничего не отправляет.';
    return {ok:false,error:'demo_only',http_status:422};
  }
};
const editor=window.AnyTourSelectionEditor.mount(Object.freeze(host));editor.sync(conversation);
document.getElementById('demoReset').onclick=()=>{editor.suspend();state={version:1,draft:copy(initial),receipts:{}};try{sessionStorage.removeItem(storageKey);sessionStorage.removeItem('workspaceV2.tourSelection.1.1')}catch(e){};editor.sync(conversation)};
})();
