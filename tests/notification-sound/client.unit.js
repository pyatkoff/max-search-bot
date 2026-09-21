'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict'),path=require('node:path');
const root=path.resolve(__dirname,'../..');
let checks=0;
function check(name,actual,expected){assert.deepEqual(actual,expected,name);checks++;console.log('PASS '+name);}
function harness(shared){
  const elements=new Map(),listeners={};
  class Element{
    constructor(){this.attributes={};this.textContent='';this.disabled=false;}
    set id(value){this._id=value;elements.set(value,this);}get id(){return this._id;}
    setAttribute(k,v){this.attributes[k]=v;}append(...children){this.children=children;}
    insertAdjacentElement(_where,el){elements.set(el.id,el);}
  }
  const anchor=new Element();anchor.id='notificationStatus';
  const document={visibilityState:'visible',getElementById:id=>elements.get(id)||null,createElement:()=>new Element(),addEventListener:(type,fn)=>listeners[type]=fn};
  let count=0,blocked=false;
  class Audio{
    constructor(){this.state='suspended';this.currentTime=0;this.destination={};}
    async resume(){if(blocked)throw new Error('NotAllowedError');this.state='running';}
    async close(){this.state='closed';}
    createOscillator(){return {type:'sine',frequency:{setValueAtTime(){}},connect(){},disconnect(){},start(){count++;},stop(){}};}
    createGain(){return {gain:{setValueAtTime(){},linearRampToValueAtTime(){},exponentialRampToValueAtTime(){}},connect(){},disconnect(){}};}
  }
  const feed={upper:100,rows:[],owner:7,requests:[],fail:false,deferred:null};
  const context={document,navigator:{},setTimeout,clearTimeout,setInterval:()=>1,clearInterval(){},Date,console,AudioContext:Audio,addEventListener:(type,fn)=>listeners[type]=fn};
  context.window=context;context.self=context;
  context.WorkspaceV2={S:{manager:{id:7},authGeneration:1,authExpired:false},notificationEvents:async cursor=>{
    feed.requests.push(cursor);if(feed.deferred)await feed.deferred;if(feed.fail)throw new Error('network');
    return {ok:true,manager_id:feed.owner,cursor:feed.upper,events:feed.rows};
  }};
  vm.createContext(context);
  vm.runInContext(fs.readFileSync(path.join(root,'manager/assets/workspace-v2-sound-ledger.js'),'utf8'),context);
  const ledger=context.WorkspaceSoundLedger;
  ledger.supported=()=>true;
  // Persistence and audio are test doubles; browser integration has its own suite.
  const storage=shared||{states:new Map(),tail:Promise.resolve()};
  ledger.run=(id,action)=>{
    const result=storage.tail.then(async()=>{
      if(!storage.states.has(id))storage.states.set(id,{cursor:null,pollAt:0,lastTone:0,seen:{}});
      return action(storage.states.get(id));
    });storage.tail=result.catch(()=>{});return result;
  };
  vm.runInContext(fs.readFileSync(path.join(root,'manager/assets/workspace-v2-sound.js'),'utf8'),context);
  context.WorkspaceV2Sound.activate(7);
  return {context,feed,elements,storage,get count(){return count;},block:()=>blocked=true,controller:context.WorkspaceV2Sound};
}
(async()=>{
 const h=harness();
 check('sound starts disabled',h.elements.get('soundToggle').attributes['aria-pressed'],'false');
 check('loading the old conversation never schedules audio',h.count,0);
 await h.controller.enable();check('explicit enable schedules a test cue',h.count,1);
 check('first poll uses a silent baseline',h.feed.requests[0],null);
 h.feed.upper=101;h.feed.rows=[{message_id:101,conversation_id:11}];
 await h.controller.poll(true);check('fresh incoming schedules one cue',h.count,2);
 await h.controller.poll(true);check('same event is not replayed',h.count,2);
 h.feed.upper=102;h.feed.rows.push({message_id:102,conversation_id:11});
 await h.controller.poll(true);check('a short burst is grouped',h.count,2);
 check('grouping still advances the seen arrival',Object.keys(h.storage.states.get(7).seen).includes('11:102'),true);
 h.context.document.visibilityState='hidden';const requests=h.feed.requests.length;
 await h.controller.poll(true);check('hidden tab does not poll',h.feed.requests.length,requests);
 h.context.document.visibilityState='visible';
 h.controller.disable();h.feed.upper=103;h.feed.rows.push({message_id:103,conversation_id:11});await h.controller.poll(true);
 check('disabled sound does not poll or play',h.count,2);
 await h.controller.testSound();check('test remains available while off',h.count,3);
 check('test alone does not enable arrivals',h.elements.get('soundToggle').attributes['aria-pressed'],'false');
 const history=harness();await history.controller.enable();history.feed.upper=150;history.feed.rows=[{message_id:150,conversation_id:11}];history.storage.states.get(7).pollAt=Date.now()-60000;
 await history.controller.poll(true);check('long suspension rebaselines old arrivals',history.count,1);
 const failure=harness();failure.block();await failure.controller.enable();check('browser rejection does not schedule a cue',failure.count,0);
 check('browser rejection remains visibly off',failure.elements.get('soundToggle').attributes['aria-pressed'],'false');
 check('browser rejection is explained',failure.elements.get('soundMessage').textContent.includes('не разрешил звук'),true);
 const wrong=harness();await wrong.controller.enable();wrong.feed.owner=8;wrong.feed.upper=101;wrong.feed.rows=[{message_id:101,conversation_id:11}];await wrong.controller.poll(true);
 check('another-account response is rejected',wrong.count,1);
 const expired=harness();await expired.controller.enable();expired.context.WorkspaceV2.S.authExpired=true;expired.controller.suspend();const prior=expired.feed.requests.length;await expired.controller.poll(true);
 check('expired session stops requests',expired.feed.requests.length,prior);
 check('expired session leaves sound off',expired.elements.get('soundToggle').attributes['aria-pressed'],'false');
 const stale=harness();await stale.controller.enable();let finish;stale.feed.deferred=new Promise(resolve=>finish=resolve);stale.feed.upper=101;stale.feed.rows=[{message_id:101,conversation_id:11}];
 const pending=stale.controller.poll(true);await new Promise(resolve=>setImmediate(resolve));stale.context.WorkspaceV2.S.authGeneration++;stale.controller.suspend();finish();await pending;
 check('late response after auth change cannot schedule audio',stale.count,1);
 const unsupported=harness();unsupported.context.WorkspaceSoundLedger.supported=()=>false;await unsupported.controller.enable();check('missing coordination remains silent',unsupported.count,0);
 const shared={states:new Map(),tail:Promise.resolve()},a=harness(shared),b=harness(shared);await a.controller.enable();await b.controller.enable();
 for(const item of [a,b]){item.feed.upper=101;item.feed.rows=[{message_id:101,conversation_id:11}];}
 await Promise.all([a.controller.poll(true),b.controller.poll(true)]);check('two controllers share one new-arrival decision',a.count+b.count,3);
 check('callback data is not needed by controller payload',Object.keys(a.feed.rows[0]).sort(),['conversation_id','message_id']);
 const reorder=harness();reorder.feed.rows=[{message_id:110,conversation_id:11}];await reorder.controller.enable();
 check('initial recent history is silently recorded',reorder.count,1);
 reorder.feed.rows.push({message_id:109,conversation_id:11});reorder.feed.upper=110;await reorder.controller.poll(true);
 check('late lower ID in same conversation produces a new cue',reorder.count,2);
 await reorder.controller.poll(true);check('late lower ID is deduplicated exactly',reorder.count,2);
 console.log('TOTAL '+checks+' PASS '+checks+' FAIL 0');
})().catch(error=>{console.error(error);process.exitCode=1;});
