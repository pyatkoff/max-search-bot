"""Offline-testable one-write gate. Fetching/creating comments is NOT performed here.
Authenticated comment JSON must come from same-repository GitHub API over existing token.
Root connector checks the private repository; this gate never pretends it has that access.
"""
import datetime,hashlib,json,re
STATUSES=('in_progress','queued','waiting','pending','requested')
OWNER=226193297
FIELDS={'schema_version','kind','repository','execution_run_id','execution_head_sha','run_attempt','batch','operation','claim_id','plan_sha256','script_sha256','preimage_sha256','previous_native_receipt_sha256','private_route_proof_sha256','nonce','observed_at','expires_at','private_repository','private_branch_sha','private_status_checks','decision'}
def require(v,m):
 if not v:raise ValueError(m)
def time(s):
 d=datetime.datetime.fromisoformat(s.replace('Z','+00:00'));require(d.tzinfo is not None,'timezone_required');return d

def admit(comment,expected,now):
 require(comment.get('id')==expected['permit_comment_id'] and comment.get('user',{}).get('id')==OWNER and comment['user'].get('type')=='User','authenticated_owner_comment_required')
 p=json.loads(comment['body']);require(set(p)==FIELDS,'permit_schema_changed')
 require(p['schema_version']==1 and p['kind']=='ROOT_CONNECTOR_ONE_NATIVE_FEED_WRITE' and p['decision']=='ALLOW_EXACT_ONE_WRITE','one_write_permit_required')
 for key in ('repository','execution_run_id','execution_head_sha','run_attempt','batch','operation','claim_id','plan_sha256','script_sha256','preimage_sha256','previous_native_receipt_sha256','private_route_proof_sha256','nonce'):
  require(p[key]==expected[key],'permit_binding_changed_'+key)
 require(p['repository']=='pyatkoff/max-search-bot' and p['run_attempt']==1 and type(p['execution_run_id']) is int and p['execution_run_id']>0,'one_shot_scope_required')
 observed,expires,updated=time(p['observed_at']),time(p['expires_at']),time(comment['updated_at'])
 # 60s measured from earliest private status query, not the last convenient timestamp.
 require(0<=(now-observed).total_seconds()<=60 and 0<(expires-observed).total_seconds()<=60 and now<=expires and observed<=updated<=now,'permit_expired_or_future')
 require(p['private_repository']=='pyatkoff/tour-max' and p['private_branch_sha']==expected['private_branch_sha'] and re.fullmatch('[a-f0-9]{40}',p['private_branch_sha']) is not None,'private_scope_changed')
 checks=p['private_status_checks'];require([c['status'] for c in checks]==list(STATUSES),'all_private_statuses_required')
 for c in checks:
  require(set(c)=={'status','observed_at','total_count','returned_count','response_sha256'} and c['total_count']==c['returned_count']==0 and observed<=time(c['observed_at'])<=updated and re.fullmatch('[a-f0-9]{64}',c['response_sha256']) is not None,'private_check_not_clear')
 return {'permit_body_sha256':hashlib.sha256(comment['body'].encode()).hexdigest(),'comment_id':comment['id'],'comment_updated_at':comment['updated_at'],'consumption':'ONE_LOCAL_EXCLUSIVE_STARTED_MARKER_BEFORE_UNCHANGED_WRITER','root_connector_private_checks':True}
