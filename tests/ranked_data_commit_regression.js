'use strict';
const assert=require('node:assert/strict');
const crypto=require('node:crypto');

// Called by the existing required lifecycle regression, with no production I/O.
module.exports=async function verifyRankedDataCommit(check){
  const R={...require('../tools/deployment_release'),...require('../tools/ranked_telegram_data_input')};
  const base='1'.repeat(40),head='2'.repeat(40),root='3'.repeat(40),config='4'.repeat(40);
  const approvalsPath='config/anytour_ranked_approved_indexes.json';
  const indexPath='config/anytour_ranked_index_fixture.json',planPath='config/anytour_ranked_pairs_fixture.json';
  const hash=b=>crypto.createHash('sha256').update(b).digest('hex');
  const bytes=x=>Buffer.from(JSON.stringify(x));
  const previous=[{path:'config/anytour_previous_fixture.json',sha256:'5'.repeat(64)}];
  const proof={run_id:'12345',run_attempt:1,artifact_id:23456,artifact_zip_sha256:'6'.repeat(64),
    registry_sha256:'7'.repeat(64),checkpoint_sha256:'8'.repeat(64),repository_sha:base,application_sha:base};
  const plan=bytes({schema:1,native_preflight:proof,rows:[{hotel_id:9,source_message_id:'10',target_message_id:'11'}]});
  const item={path:planPath,bytes:plan.length,git_blob:R.gitBlob(plan),sha256:hash(plan)};
  const index=bytes({schema:1,native_preflight:proof,native_source:{head_sha:'9'.repeat(40),job_id:34567,
    step:'Read the exact prepared pairs',artifact_name:'issue-1566-telegram-ranked-preflight'},native_zip_bytes:1234,plans:[item]});
  const digest=hash(index),addition={path:indexPath,sha256:digest},branch='ops/anytour-hotel-ranked-plan-'+digest;
  const encode=(at,path,raw)=>({type:'file',path,sha:R.gitBlob(raw),size:raw.length,encoding:'base64',content:raw.toString('base64')});
  const key=(at,path)=>R.API_ROOT+'/contents/'+path+'?ref='+at;
  const fixture=()=>{
    const approvals=bytes([...previous,addition]);
    return {
      [R.API_ROOT+'/git/commits/'+head]:{sha:head,parents:[{sha:base}],tree:{sha:root}},
      [R.API_ROOT+'/compare/'+base+'...'+head+'?per_page=100']:{status:'ahead',ahead_by:1,behind_by:0,total_commits:1,
        base_commit:{sha:base},merge_base_commit:{sha:base},commits:[{sha:head}],
        files:[{filename:approvalsPath,status:'modified'},{filename:indexPath,status:'added'},{filename:planPath,status:'added'}]},
      [key(base,approvalsPath)]:encode(base,approvalsPath,bytes(previous)),
      [key(head,approvalsPath)]:encode(head,approvalsPath,approvals),
      [key(head,indexPath)]:encode(head,indexPath,index),
      [key(head,planPath)]:encode(head,planPath,plan),
      [R.API_ROOT+'/git/trees/'+root]:{sha:root,truncated:false,tree:[{path:'config',type:'tree',mode:'040000',sha:config}]},
      [R.API_ROOT+'/git/trees/'+config]:{sha:config,truncated:false,tree:[[approvalsPath,approvals],[indexPath,index],[planPath,plan]]
        .map(([path,raw])=>({path:path.slice(7),type:'blob',mode:'100644',sha:R.gitBlob(raw)}))}
    };
  };
  const invoke=async values=>{
    let mutations=0;
    const api=async(method,path)=>{
      if(method!=='GET'){mutations++;throw Error('inspection_mutation_forbidden');}
      assert.ok(Object.hasOwn(values,path),'unexpected API path '+path);
      return structuredClone(values[path]);
    };
    const result=await R.inspectRankedDataCommit({api,head,base,digest,branch});
    check(mutations,0,'data inspection: no mutation');
    return result;
  };
  const result=await invoke(fixture());
  check(result.write_admission,false,'data inspection: unchanged code never grants WRITE');
  check(result.evidence_only,true,'data inspection: proof is explicitly preparatory');
  check(result.data_sha,head,'data inspection: data commit is distinct');
  check(result.repository_sha,base,'data inspection: installation source stays separate');
  check(result.plan_sha256,item.sha256,'data inspection: exact immutable plan');
  assert.deepEqual(result.changed_paths,[approvalsPath,indexPath,planPath].sort());
  check(true,true,'data inspection: complete bounded delta');
  const relation=R.API_ROOT+'/compare/'+base+'...'+head+'?per_page=100';
  const commit=R.API_ROOT+'/git/commits/'+head,tree=R.API_ROOT+'/git/trees/'+config;
  const refusals=[
    ['ranked_data_parent_changed',v=>v[commit].parents.push({sha:'a'.repeat(40)})],
    ['ranked_data_parent_changed',v=>v[commit].parents[0].sha='a'.repeat(40)],
    ['ranked_data_delta_incomplete',v=>v[relation].total_commits=2],
    ['ranked_data_delta_incomplete',v=>v[relation].commits=[]],
    ['ranked_data_delta_incomplete',v=>v[relation].files.push({filename:'services/ChannelPostRegistryService.php',status:'modified'})],
    ['ranked_data_mixed_change',v=>v[relation].files[2]={filename:'services/ChannelPostRegistryService.php',status:'modified'}],
    ['ranked_data_mixed_change',v=>v[relation].files[2].status='modified'],
    ['ranked_data_mixed_change',v=>v[relation].files[2].previous_filename='config/other.json'],
    ['ranked_data_approvals_rewritten',v=>v[key(head,approvalsPath)]=encode(head,approvalsPath,bytes([{...previous[0],sha256:'a'.repeat(64)},addition]))],
    ['ranked_data_approvals_rewritten',v=>v[key(head,approvalsPath)]=encode(head,approvalsPath,bytes([addition]))],
    ['ranked_data_approvals_rewritten',v=>v[key(head,approvalsPath)]=encode(head,approvalsPath,bytes([...previous,addition,addition]))],
    ['ranked_data_approval_not_new',v=>v[key(head,approvalsPath)]=encode(head,approvalsPath,bytes([...previous,{...addition,sha256:'a'.repeat(64)}]))],
    ['ranked_data_plan_changed',v=>v[key(head,planPath)]=encode(head,planPath,bytes({schema:1,native_preflight:proof,rows:[]}))],
    ['ranked_data_tree_incomplete',v=>v[tree].truncated=true],
    ['ranked_data_file_mode_changed',v=>v[tree].tree[2].mode='100755'],
    ['ranked_data_file_mode_changed',v=>v[tree].tree[2].type='commit'],
    ['ranked_data_file_mode_changed',v=>v[tree].tree[2].sha='a'.repeat(40)],
    ['ranked_data_file_changed',v=>v[key(head,planPath)].sha='a'.repeat(40)],
  ];
  for(const [reason,change]of refusals){const values=fixture();change(values);
    await assert.rejects(()=>invoke(values),e=>e.code===reason,'data inspection must reject '+reason);
    check(true,true,'data inspection refuses '+reason);
  }
  await assert.rejects(()=>R.inspectRankedDataCommit({api:async()=>{throw Error('read_unavailable');},head,base,digest,branch}),/read_unavailable/);
  check(true,true,'data inspection: missing proof never grants a fallback');
};
