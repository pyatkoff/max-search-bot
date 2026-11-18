"""Exact successful readback of a PARTIAL, blocked PRICE cycle. Never cycle success."""
import hashlib,json,pathlib
RUN=44254140999
HEAD='f767095344e5268c5ddfd26c71358a43b71bbaf4'
ARTIFACT=14405665921
ARCHIVE_SHA='724256a3698fff5c6e830a8c448e832a41005cc0bb30622591a7a9181ae3ab43'
RECEIPT_SHA='986d63b78480cdc6af9ad3515a3fdee42167fa67fb1990f5ca2628790690296f'
RECEIPT_PATH='price-readback-summary.json'
H=lambda b:hashlib.sha256(b).hexdigest()
def need(v,m):
 if not v:raise ValueError(m)
def validate_summary(raw):
 need(H(raw)==RECEIPT_SHA,'exact_price_readback_changed');s=json.loads(raw)
 need(s['ok'] is True and s['mode']=='read_only_post_schedule_handoff' and s['run_id']==str(RUN) and s['source']==HEAD,'readback_identity')
 need(s['code_hashes']=={'hook':'483f9f1cd853fdf1808523e72d466e2acd0866ec6ae35de049ada38b5cc128f9','cohort':'e90579f6e12470c6b1ae415f4f888b78ad990125eeb0f59be845ce84ffe3d9df','collector':'c7ec457c2ff11b7824d481813c5d8d6a62fc22f46d49e6a5addaae91011dddae'},'installed_surface_changed')
 need(all(type(s[k]) is int and s[k]==0 for k in ['post_writes','feed_writes','supplier_calls','code_writes']),'readback_mutated')
 j=s['journal'];need(j['ok'] is True and j['mode']=='read_only_current_journal' and j['counter_scope']=='this_verifier_only' and j['prefix_verified'] is True and j['pending'] is None,'journal_not_idle')
 need(j['pin']=={'sha256':'d02f2206df26e6dd75b55347ef95d0cf5717be93227be7e3142c38daadb62041','bytes':606390308,'lines':5443},'journal_pin')
 need(j['cycle_state']=='preflight_blocked' and j['cycle_collector_run_id']=='44236272559','partial_cycle_identity')
 b=j['latest_cycle']['preflight_block'];need(b['tourvisor_hotel_id']==36451 and b['terminal']['state']=='abandoned_before_write' and b['terminal']['channel_preflight']['write_attempts']==0,'blocked_child_not_closed')
 need(all(type(j[k]) is int and j[k]==0 for k in ['preflight_write_attempts','supplier_calls','post_writes','feed_writes','site_writes','writes']),'verifier_mutated')
 need(set(s['feeds'])=={'hotel-posts-telegram.xml','hotel-posts-max.xml','hotel-posts-all.xml'},'feed_scope')
 for f in s['feeds'].values():need(f['public_http']==200 and f['public_sha256']==f['sha256'],'public_feed_diverged')
 return {'kind':'EXACT_READBACK_AFTER_PARTIAL_PRICE_CYCLE','price_cycle_success':False,'prior_cycle_updated_pairs':j['latest_cycle']['updated_pairs'],'readback_writes':0,'pending':None,'receipt_sha256':RECEIPT_SHA}
def validate_handoff(config,root,api):
 expected={'kind':'EXACT_READBACK_AFTER_PARTIAL_PRICE_CYCLE','repository':'poisk-turov-test','run_id':RUN,'head_sha':HEAD,'artifact_id':ARTIFACT,'archive_sha256':ARCHIVE_SHA,'receipt_path':RECEIPT_PATH,'receipt_sha256':RECEIPT_SHA}
 need(config['price_terminal_handoff']==expected,'exact_handoff_scope')
 need(config['source_sha256'].get(RECEIPT_PATH)==RECEIPT_SHA,'receipt_source_pin')
 p=root/RECEIPT_PATH;need(not any(x.is_symlink() for x in [p,*p.parents]),'receipt_symlink');result=validate_summary(p.read_bytes())
 r=api('poisk-turov-test','actions/runs/'+str(RUN))
 need(r['id']==RUN and r['repository']['full_name']=='pyatkoff/poisk-turov-test' and r['head_sha']==HEAD and r['run_attempt']==1 and r['status']=='completed' and r['conclusion']=='success','readback_run_not_success')
 a=api('poisk-turov-test','actions/artifacts/'+str(ARTIFACT))
 need(a['id']==ARTIFACT and a['expired'] is False and a['digest']=='sha256:'+ARCHIVE_SHA and a['workflow_run']['id']==RUN and a['workflow_run']['head_sha']==HEAD,'readback_artifact_changed')
 return result
