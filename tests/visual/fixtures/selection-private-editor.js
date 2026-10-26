const {expect}=require('@playwright/test');
const fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process');
const root=path.resolve(__dirname,'../../..'),copy=x=>JSON.parse(JSON.stringify(x));
// Real shipped PHP views and canonical editor assets. Only private HTTP data is synthetic.
async function privateEditor(page,options={}){
 const manifest=JSON.parse(fs.readFileSync(path.join(root,'modules/tour-selections/editor.json'))),calls=[],errors=[],unexpected=[],receipts=new Map();
 let actor=options.actor??7,allowed=options.allowed!==false,readOnly=options.readOnly===true,fail=options.fail||'',held=null,version=options.draft?1:0,draft=copy(options.draft||{title:'Подборка туров',intro:'',offers:[]});
 const snapshot=()=>({conversation_id:11,version,draft:copy(draft),can_edit:!readOnly,updated_at:null,updated_by_manager_id:7});
 const app=execFileSync('php',['-r',`$editorAsset=static fn($name)=>'tour-selection-editor.php?asset='.rawurlencode($name).'&amp;package=synthetic';require 'modules/tour-selections/editor-app.php';`],{cwd:root,encoding:'utf8'});
 page.on('pageerror',e=>errors.push(e.message));
 await page.route('**/*',async route=>{
  const req=route.request(),url=new URL(req.url()),reply=(data,status=200)=>route.fulfill({status,contentType:'application/json',body:JSON.stringify(data)});
  if(url.origin==='https://tours.anytoour.ru'&&url.pathname==='/editor.php'){
   expect(req.method()).toBe('GET');expect(req.headers().cookie).toBeUndefined();
   const html=execFileSync('php',['-r',`$_SERVER['QUERY_STRING']=$argv[1];parse_str($argv[1],$_GET);require 'modules/tour-selections/editor-tours.php';`,url.search.slice(1)],{cwd:root,encoding:'utf8'});
   return route.fulfill({contentType:'text/html',headers:{'Cache-Control':'private, no-store','Content-Security-Policy':"default-src 'none'; style-src 'unsafe-inline'; frame-src https://app.anytoour.ru; frame-ancestors 'none'"},body:html});
  }
  if(url.origin==='https://app.anytoour.ru'&&url.pathname==='/manager/tour-selection-editor.php'){
   if(url.searchParams.has('asset')){
    const name=url.searchParams.get('asset');expect(['editor.js','editor.css','private-host.js','private-host.css','http.js','time.js']).toContain(name);
    return route.fulfill({contentType:name.endsWith('.css')?'text/css':'application/javascript',body:fs.readFileSync(path.join(root,manifest.files[name]))});
   }
   return route.fulfill({contentType:'text/html',headers:{'Cache-Control':'private, no-store','Content-Security-Policy':"default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' https: blob:; connect-src 'self'; frame-ancestors https://tours.anytoour.ru"},body:app});
  }
  if(url.origin==='https://app.anytoour.ru'&&req.method()==='POST'&&['/manager/api.php','/manager/tour-selection-editor-api.php'].includes(url.pathname)){
   const d=req.postDataJSON();calls.push(copy({...d,path:url.pathname}));
   if(url.pathname==='/manager/api.php'){expect(d.action).toBe('me');return actor?reply({ok:true,manager:{id:actor,role:'manager'},csrf:'private-csrf'}):reply({ok:false,error:'unauthorized'},401)}
   if(!actor)return reply({ok:false,error:'unauthorized'},401);
   if(d.csrf!=='private-csrf'||fail==='csrf'){fail='';return reply({ok:false,error:'csrf'},403)}
   if(d.editor_actor!==actor)return reply({ok:false,error:'stale_actor'},409);
   expect(d.conversation_id).toBe(11);
   if(!allowed)return reply({ok:false,error:'not_found'},404);
   if(d.action==='editor_context')return reply({ok:true,context:{id:11,actor,display_name:'Синтетическая Анна',channel:'telegram',status:'manager',can_edit:!readOnly}});
   expect(d.editor_resource).toBe('tour-selections-api.php');
   if(d.action==='get'){
    const response={ok:true,selection:snapshot()};
    if(fail==='hold_get'){fail='';await new Promise(resolve=>held=resolve)}
    return reply(response);
   }
   if(d.action==='operation')return reply({ok:true,found:receipts.has(d.operation_id),saved_version:receipts.get(d.operation_id)??null,selection:snapshot()});
   expect(d.action).toBe('save');
   if(readOnly)return reply({ok:false,error:'forbidden'},403);
   if(fail==='before'){fail='';return route.abort('failed')}
   if(!receipts.has(d.operation_id)){
    if(d.version!==version)return reply({ok:false,error:'conflict',selection:snapshot()},409);
    draft=copy(d.draft);version++;receipts.set(d.operation_id,version);
   }
   if(fail==='after'){fail='';return route.abort('failed')}
   return reply({ok:true,saved_version:receipts.get(d.operation_id),selection:snapshot()});
  }
  unexpected.push(req.method()+' '+url.origin+url.pathname);return route.abort();
 });
 const entry=options.entry||'?conversation_id=11&editor_actor=7';
 await page.goto('https://tours.anytoour.ru/editor.php'+entry);
 const editor=page.frameLocator('iframe');
 return{editor,calls,errors,unexpected,receipts,snapshot,setActor:x=>actor=x,setAllowed:x=>allowed=x,setFail:x=>fail=x,release:()=>held?.(),hasHeld:()=>!!held};
}
module.exports={privateEditor};
