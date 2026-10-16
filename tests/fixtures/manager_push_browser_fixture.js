// Synthetic browser storage and protocol receipts for the maintained native-job
// regressions. Server ownership/SQL is exercised separately with real PDO/HTTP.
function storage(seed={}){
 const data=new Map(Object.entries(seed));return{get length(){return data.size},key(n){return [...data.keys()][n]??null},getItem(k){return data.get(k)??null},setItem(k,v){data.set(k,String(v))},removeItem(k){data.delete(k)},clear(){data.clear()}};
}
function lifecycle(manager=7){
 let revision=1,enabled=true;const receipts=new Map();
 const localStorage=storage({['anytour-manager-push-installation:'+manager]:JSON.stringify({token:'a'.repeat(64),revision})});
 const current=()=>({id:1,revision,enabled});
 return{localStorage,crypto:require('node:crypto').webcrypto,current,
  reply(body){if(body.action==='operation'){const operation=receipts.get(body.operation_id);return operation?{...operation.result,found:true,operation,current_connection:current()}:{ok:true,found:false};}
   if(body.action==='subscribe'&&(!enabled||body.expected_revision!==revision)){const result={ok:false,error:'subscription_revoked'},operation={id:body.operation_id,action:body.action,result};receipts.set(body.operation_id,operation);return{...result,found:true,operation,current_connection:current()};}
   if(body.action==='enable_intent'){revision++;enabled=true;}if(body.action==='revoke'){revision++;enabled=false;}
   const result={ok:true,connection_id:1,revision,enabled},operation={id:body.operation_id,action:body.action,result};receipts.set(body.operation_id,operation);return{ok:true,found:true,operation,current_connection:current()};
  }};
}
module.exports={storage,lifecycle};
