const fs=require('node:fs'),os=require('node:os'),path=require('node:path'),net=require('node:net');
const {execFileSync,spawn}=require('node:child_process');
const root=path.resolve(__dirname,'../../..');
async function setupRequestProfile(page,options={}){
  const directory=fs.mkdtempSync(path.join(os.tmpdir(),'manager-request-profile-'));
  let server;
  try{
    const fixture=JSON.parse(execFileSync('php',[path.join(root,'tests/support/manager-request-profile-http.php'),directory,'visual'],{cwd:root,encoding:'utf8'}));
    const port=await new Promise((resolve,reject)=>{const s=net.createServer();s.once('error',reject);s.listen(0,'127.0.0.1',()=>{const port=s.address().port;s.close(()=>resolve(port))})});
    const origin='http://127.0.0.1:'+port;
    let log='';server=spawn('php',['-d','session.save_path='+fixture.sessions,'-S','127.0.0.1:'+port,'-t',fixture.webroot],{cwd:root,stdio:['ignore','pipe','pipe']});
    server.stdout.on('data',b=>{log+=b});server.stderr.on('data',b=>{log+=b});
    await new Promise((resolve,reject)=>{let attempts=0;const probe=()=>{if(server.exitCode!==null)return reject(Error(log));const socket=net.connect(port,'127.0.0.1');socket.once('connect',()=>{socket.destroy();resolve()});socket.once('error',()=>{socket.destroy();if(++attempts>100)reject(Error(log||'local PHP did not start'));else setTimeout(probe,20)})};probe()});
    await page.context().addCookies([{name:'anytour_manager_panel',value:fixture.session_ids['1'],domain:'127.0.0.1',path:'/manager/',httpOnly:true,sameSite:'Lax',secure:false}]);
    const errors=[],calls=[],unexpected=[],queued=options.initialGetAbort?[{action:'get',abort:true}]:[],held=[];page.on('pageerror',e=>errors.push(e.message));
    await page.route('**/*',async route=>{const request=route.request(),url=new URL(request.url());if(url.origin!==origin){unexpected.push(request.url());return route.abort()}
      if(url.pathname==='/manager/request-profile-api.php'){
        const input=request.postDataJSON();calls.push(input);if(!['identity','get','save','operation'].includes(input.action)){unexpected.push(input.action);return route.abort()}
        const at=queued.findIndex(r=>r.action===input.action);if(at>=0){const next=queued.splice(at,1)[0];const original=next.actual?await route.fetch():null;
          if(next.hold)await new Promise(resolve=>held.push(resolve));if(next.abort)return route.abort();
          return route.fulfill(original?{response:original}:{status:next.status||200,contentType:'application/json',json:typeof next.body==='function'?next.body(input):next.body});}
      }
      return route.continue();});
    await page.goto(origin+'/manager2/request-profile.php?conversation=1');
    return{root,origin,fixture,calls,errors,unexpected,queue:(...responses)=>queued.push(...responses),heldCount:()=>held.length,release:()=>held.shift()?.(),log:()=>log,
      mutate:sql=>execFileSync('php',['-r',"$p=new PDO('sqlite:'.$argv[1]);$p->exec($argv[2]);",fixture.database,sql],{encoding:'utf8'}),
      cleanup:async()=>{held.splice(0).forEach(resolve=>resolve());server.kill('SIGTERM');await new Promise(resolve=>{if(server.exitCode!==null)return resolve();server.once('exit',resolve)});fs.rmSync(directory,{recursive:true,force:true})}};

  }catch(error){server?.kill('SIGTERM');fs.rmSync(directory,{recursive:true,force:true});throw error}
}
module.exports={setupRequestProfile,root};
