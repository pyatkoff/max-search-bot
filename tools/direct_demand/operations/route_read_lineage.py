"""Root-attested private-route proof: only native read timestamp rebinding permitted."""
import hashlib,json
sha=lambda b:hashlib.sha256(b).hexdigest()
def need(v,m):
 if not v:raise ValueError(m)
def rebind(original_proof_raw,original_read_raw,fresh_read_raw):
 p=json.loads(original_proof_raw);old=json.loads(original_read_raw);new=json.loads(fresh_read_raw)
 need(p.get('kind')=='root_authenticated_private_route_extension' and p.get('bound_read_sha256')==sha(original_read_raw),'original_root_read_binding_changed')
 need('read_at_utc' in old and 'read_at_utc' in new,'native_timestamp_missing')
 old.pop('read_at_utc');new.pop('read_at_utc');need(old==new,'fresh_read_business_fields_changed')
 derived=dict(p);derived['bound_read_sha256']=sha(fresh_read_raw)
 inverse=dict(derived);inverse['bound_read_sha256']=p['bound_read_sha256'];need(inverse==p,'proof_rebind_scope_changed')
 raw=json.dumps(derived,ensure_ascii=False,separators=(',',':')).encode()
 return raw,{'kind':'ONLY_BOUND_READ_SHA256_AFTER_TIMESTAMP_ONLY_NATIVE_READ_CHANGE','original_root_proof_sha256':sha(original_proof_raw),'original_raw_read_sha256':sha(original_read_raw),'fresh_raw_read_sha256':sha(fresh_read_raw),'derived_root_proof_sha256':sha(raw),'only_ignored_read_field':'read_at_utc','all_other_fields_identical':True,'inverse_object_identity':True}
