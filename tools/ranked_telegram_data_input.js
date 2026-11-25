'use strict';
const crypto=require('node:crypto');
const {API_ROOT,sha,requireValue,object,keys,same,gitBlob,parseJson,approvedRankedInput}=require('./deployment_release');
const RANKED_INPUT_BRANCH=/^ops\/anytour-hotel-ranked-plan-([0-9a-f]{64})$/;

/** Inspect a prospective data commit without granting native or WRITE admission.
 * The installed resolver/editor stay authoritative until their explicit data
 * transport is wired. A valid JSON inspection is never a release receipt.
 */
async function inspectRankedDataCommit({api,head,base,digest:inputDigest,branch}) {
  requireValue(sha(head)&&sha(base)&&head!==base&&RANKED_INPUT_BRANCH.exec(branch)?.[1]===inputDigest,
    'ranked_data_identity_invalid');
  const commit=await api('GET',API_ROOT+'/git/commits/'+head);
  requireValue(commit?.sha===head&&Array.isArray(commit.parents)&&commit.parents.length===1
    &&commit.parents[0].sha===base&&sha(commit.tree?.sha),'ranked_data_parent_changed');
  const relation=await api('GET',API_ROOT+'/compare/'+base+'...'+head+'?per_page=100');
  requireValue(relation?.status==='ahead'&&relation.ahead_by===1&&relation.behind_by===0
    &&relation.total_commits===1&&relation.base_commit?.sha===base&&relation.merge_base_commit?.sha===base
    &&Array.isArray(relation.commits)&&relation.commits.length===1&&relation.commits[0].sha===head
    &&Array.isArray(relation.files)&&relation.files.length===3,'ranked_data_delta_incomplete');
  const read=async (at,path)=>{
    const item=await api('GET',API_ROOT+'/contents/'+path+'?ref='+at);
    requireValue(item?.type==='file'&&item.path===path&&item.encoding==='base64'
      &&Number.isSafeInteger(item.size)&&item.size>0&&item.size<=64000&&typeof item.content==='string',
      'ranked_data_file_changed');
    const encoded=item.content.replace(/\n/g,''),bytes=Buffer.from(encoded,'base64');
    requireValue(bytes.toString('base64')===encoded&&bytes.length===item.size&&gitBlob(bytes)===item.sha,
      'ranked_data_file_changed');
    return {document:parseJson(bytes),blob:item.sha,bytes:item.size,digest:crypto.createHash('sha256').update(bytes).digest('hex')};
  };
  const approvalsPath='config/anytour_ranked_approved_indexes.json';
  const previous=await read(base,approvalsPath),current=await read(head,approvalsPath);
  requireValue(Array.isArray(previous.document)&&Array.isArray(current.document)
    &&current.document.length===previous.document.length+1
    &&same(current.document.slice(0,-1),previous.document),'ranked_data_approvals_rewritten');
  const addition=current.document.at(-1);
  requireValue(object(addition)&&keys(addition,['path','sha256'])&&addition.sha256===inputDigest
    &&/^config\/anytour_[a-z0-9_]+\.json$/.test(addition.path)
    &&previous.document.every(row=>row?.sha256!==inputDigest&&row?.path!==addition.path),
    'ranked_data_approval_not_new');
  const profile=await approvedRankedInput(api,head,inputDigest,branch);
  const index=await read(head,addition.path),item=index.document.plans[0];
  requireValue(/^config\/anytour_[a-z0-9_]+\.json$/.test(item.path)
    &&item.path!==addition.path&&item.path!==approvalsPath,'ranked_data_plan_path_invalid');
  const plan=await read(head,item.path);
  requireValue(plan.blob===item.git_blob&&plan.bytes===item.bytes&&plan.digest===item.sha256
    &&same(plan.document.native_preflight,index.document.native_preflight), 'ranked_data_plan_changed');
  const expected=[approvalsPath,addition.path,item.path].sort();
  requireValue(same(relation.files.map(row=>row.filename).sort(),expected)
    &&relation.files.every(row=>row.status===(row.filename===approvalsPath?'modified':'added')
      &&row.previous_filename===undefined),'ranked_data_mixed_change');
  const root=await api('GET',API_ROOT+'/git/trees/'+commit.tree.sha);
  requireValue(root?.sha===commit.tree.sha&&root.truncated===false&&Array.isArray(root.tree),
    'ranked_data_tree_incomplete');
  const config=root.tree.filter(row=>row.path==='config');
  requireValue(config.length===1&&config[0].type==='tree'&&config[0].mode==='040000'&&sha(config[0].sha),
    'ranked_data_tree_incomplete');
  const tree=await api('GET',API_ROOT+'/git/trees/'+config[0].sha);
  requireValue(tree?.sha===config[0].sha&&tree.truncated===false&&Array.isArray(tree.tree),
    'ranked_data_tree_incomplete');
  for(const [path,file] of [[approvalsPath,current],[addition.path,index],[item.path,plan]]){
    const entries=tree.tree.filter(row=>row.path===path.slice('config/'.length));
    requireValue(entries.length===1&&entries[0].type==='blob'&&entries[0].mode==='100644'
      &&entries[0].sha===file.blob,'ranked_data_file_mode_changed');
  }
  return {protocol:'anytour_ranked_data_candidate_v1',evidence_only:true,write_admission:false,
    data_sha:head,repository_sha:base,index_sha256:inputDigest,index_path:addition.path,
    index_git_blob:index.blob,plan_path:item.path,plan_sha256:plan.digest,plan_git_blob:plan.blob,
    plan_bytes:plan.bytes,native_run_id:profile.native_run,changed_paths:expected,
    preserves_existing_approvals:true};
}

module.exports={inspectRankedDataCommit};
