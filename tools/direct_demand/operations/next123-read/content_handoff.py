"""Exact existing-message CONTENT terminal handoff; no new posts or sender replay."""
import json,hashlib
RUN=44255962195
HEAD='c4fb9b86f233c09f706b4133f5a690300467d7b3'
ARTIFACT=14406291497
ZIP_SHA='ba36a6a6f584467d58abd3ae14445807ced7776037b04c24206a55dfaf9b2939'
SHA='818044ecaa3b10c54b5c5b8aa66078243fac0603958cb2781b782fe66afae5d5'
NAME='content78929-terminal.json'
def need(v,m):
 if not v:raise ValueError(m)
def validate(config,root,api):
 h=config['content_terminal_handoff'];need(h=={'repository':'max-search-bot','run_id':RUN,'head_sha':HEAD,'artifact_id':ARTIFACT,'archive_sha256':ZIP_SHA,'receipt_path':NAME,'receipt_sha256':SHA},'content_handoff_scope')
 need(config['source_sha256'].get(NAME)==SHA,'content_receipt_sourcepin');p=root/NAME;need(not any(x.is_symlink() for x in [p,*p.parents]),'content_receipt_symlink');raw=p.read_bytes();need(hashlib.sha256(raw).hexdigest()==SHA,'content_receipt_changed');s=json.loads(raw);n=s['native_result']
 need(s['state']=='EXISTING_78929_ADULTS_CONDITION_VERIFIED_BOTH' and s['new_posts']==0 and s['posts_edited']==2 and n['ok'] is True and n['installed_sha']==config['runtime_sha'],'content_native_scope')
 need(all(type(n[k]) is int and n[k]==0 for k in ['new_posts_created','configuration_write_attempts','button_write_attempts','tour_search_calls','price_queries']),'content_boundary')
 need([(p['platform'],p['hotel_id'],p['publication_id'],p['version_id']) for p in n['posts']]==[('max',78929,2520,8805),('telegram',78929,2510,8806)],'content_pairs')
 need(all(p['body_photo_button_verified'] is True and p['existing_message_reused'] is True and p['existing_token_resolves_to_current_version'] is True and p['previous_attachments_sha256']==p['attachments_sha256'] for p in n['posts']),'content_verification')
 r=api('max-search-bot','actions/runs/'+str(RUN));need(r['id']==RUN and r['repository']['full_name']=='pyatkoff/max-search-bot' and r['head_sha']==HEAD and r['run_attempt']==1 and r['status']=='completed' and r['conclusion']=='success','content_run_changed')
 a=api('max-search-bot','actions/artifacts/'+str(ARTIFACT));need(a['id']==ARTIFACT and a['expired'] is False and a['digest']=='sha256:'+ZIP_SHA and a['workflow_run']['id']==RUN and a['workflow_run']['head_sha']==HEAD,'content_artifact_changed')
 need(api('max-search-bot','git/ref/heads/audit/content100-bot-publication-20261101')['object']['sha']==HEAD,'content_carrier_changed_after_handoff')
 ack=config['content_window_ack'];need(ack['comment_id']==6961029169,'content_ack_scope');comment=api('max-search-bot','issues/comments/'+str(ack['comment_id']));need(comment['user']['id']==226193297 and hashlib.sha256(comment['body'].encode()).hexdigest()==ack['body_sha256'],'content_window_ack_changed')
 return {'state':s['state'],'receipt_sha256':SHA,'new_posts':0}
