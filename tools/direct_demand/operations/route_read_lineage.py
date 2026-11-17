"""Preserve historical route evidence; exact optional audited runtime transition.

SOURCE-ONLY: the cross-runtime path remains closed until actual acceptance evidence
is independently authenticated and its exact digest is reviewed and pinned here.
No API calls, writes, runtime installation, permit or execution authorization.
"""
import hashlib,json,re
sha=lambda b:hashlib.sha256(b).hexdigest()
FROM_RUNTIME='8281b387f258932601439eb789560f6b2cbabd4d'
TO_RUNTIME='71d5ae937c59b953d762bcca520af97291081a2d'
EXPECTED_CLOSURE_PROOF_SHA256='9675e440e0d81e73975304dc99c89c17addf2d618151fa6ead89fe32596c7474'
REVIEWED_TRANSITION_SHA256='5eb201e5ad482424bf60faba794956f85ca19dc758245adb3e3b0f6ffdb8c206'
EXPECTED_OLD_PINS_SHA256='ee0b66909813c6b3374511ed3786b686a7cb29a00dde8d0c7c7c3d249ec83224'
EXPECTED_CHANGED_PINS={
 'services/ChannelPostRegistryService.php':('7304af19cc19666f00ca4ead1b04c2bdce719646','94fb1988ac8caf90dc5103aa23c58e7aad6ea691'),
 'services/ChannelPostAutomationService.php':('f5975ab95de5f45e087eb7a0f768f566c5bca648','c6386e155d42bc83970edc95f35b3ebeb5140533'),
}
def need(v,m):
 if not v:raise ValueError(m)
def canonical(value):return json.dumps(value,ensure_ascii=False,sort_keys=True,separators=(',',':')).encode()
def transition_check(raw,context,old_runtime,new_runtime):
 need(isinstance(raw,bytes) and REVIEWED_TRANSITION_SHA256 is not None and sha(raw)==REVIEWED_TRANSITION_SHA256,'closed_runtime_transition_gate')
 t=json.loads(raw)
 need(set(t)=={'kind','from_runtime','to_runtime','component_pins','read_call_closure_sha256','accepted_context'},'transition_fields_changed')
 need(t['kind']=='ROOT_AUTHENTICATED_EXACT_8281_TO_71D5_READ_COMPATIBILITY' and t['from_runtime']==old_runtime==FROM_RUNTIME and t['to_runtime']==new_runtime==TO_RUNTIME,'unreviewed_runtime_pair')
 need(isinstance(context,dict) and canonical(context)==canonical(t['accepted_context']),'authenticated_runtime_context_changed')
 need(set(context)=={'runtime_acceptance','runtime_native_artifact','original_read_artifact','root_projection_sha256'},'runtime_context_scope_changed')
 a=context['runtime_acceptance'];n=context['runtime_native_artifact'];o=context['original_read_artifact']
 need(a['state']=='DEPLOYED_AND_NATIVE_ACCEPTED' and a['head_sha']==TO_RUNTIME and a['deploy_run_id']==44164717290,'new_deployment_acceptance_changed')
 need(n['repository']==o['repository']=='pyatkoff/max-search-bot' and n['run_id']!=o['run_id'] and n['artifact_id']!=o['artifact_id'],'native_evidence_must_be_distinct')
 need(o['run_id']==44143486101 and o['head_sha']=='d8c40388f5112a6bdfe70decb997b894685b1c52' and o['artifact_id']==14355586924 and o['archive_sha256']=='8d672983dc73b04ca2342e1cec68f937589c853c4a62334b3b3c91ac8f63b176','historical_native_evidence_changed')
 need(context['root_projection_sha256']=='1f9677f64fc7b89ac9a0f7d22cdeb93a67b388421b815da7d4701afe1588f87b','historical_route_projection_changed')
 pins=t['component_pins'];need(isinstance(pins,dict) and len(pins)==22,'component_scope_changed')
 need(sha(canonical({p:x['old'] for p,x in pins.items()}))==EXPECTED_OLD_PINS_SHA256,'old_component_identity_changed')
 changed={p:(x['old'],x['new']) for p,x in pins.items() if x['old']!=x['new']}
 need(changed==EXPECTED_CHANGED_PINS,'unreviewed_component_change')
 need(t['read_call_closure_sha256']==EXPECTED_CLOSURE_PROOF_SHA256,'read_compatibility_proof_missing')
 return t
def rebind(original_proof_raw,original_read_raw,fresh_read_raw,runtime_transition_raw=None,authenticated_context=None):
 p=json.loads(original_proof_raw);old=json.loads(original_read_raw);new=json.loads(fresh_read_raw)
 need(p.get('kind')=='root_authenticated_private_route_extension' and p.get('bound_read_sha256')==sha(original_read_raw),'original_root_read_binding_changed')
 need('read_at_utc' in old and 'read_at_utc' in new,'native_timestamp_missing')
 old.pop('read_at_utc');new.pop('read_at_utc');cross=old.get('installed_sha')!=new.get('installed_sha')
 if cross:
  transition_check(runtime_transition_raw,authenticated_context,old.get('installed_sha'),new.get('installed_sha'))
  # Only the independently accepted runtime identity is normalized in copies.
  # Neither historical input bytes nor original route proof/receipts are rewritten.
  old.pop('installed_sha');new.pop('installed_sha')
 need(canonical(old)==canonical(new),'fresh_read_business_fields_changed')
 derived=dict(p);derived['bound_read_sha256']=sha(fresh_read_raw)
 inverse=dict(derived);inverse['bound_read_sha256']=p['bound_read_sha256'];need(inverse==p,'proof_rebind_scope_changed')
 raw=json.dumps(derived,ensure_ascii=False,separators=(',',':')).encode()
 record={'kind':'ONLY_BOUND_READ_SHA256_AFTER_TIMESTAMP_ONLY_NATIVE_READ_CHANGE','original_root_proof_sha256':sha(original_proof_raw),'original_raw_read_sha256':sha(original_read_raw),'fresh_raw_read_sha256':sha(fresh_read_raw),'derived_root_proof_sha256':sha(raw),'only_ignored_read_field':'read_at_utc','all_other_fields_identical':True,'inverse_object_identity':True}
 if cross:
  record.update(kind='BOUND_READ_SHA256_AFTER_EXACT_AUTHENTICATED_RUNTIME_TRANSITION',all_other_fields_identical=False,all_business_fields_identical=True,explicitly_changed_read_fields=['read_at_utc','installed_sha'],runtime_transition_sha256=sha(runtime_transition_raw),from_runtime=FROM_RUNTIME,to_runtime=TO_RUNTIME)
  record.pop('only_ignored_read_field')
 return raw,record
