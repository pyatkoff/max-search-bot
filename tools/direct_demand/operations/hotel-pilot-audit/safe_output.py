"""Strict aggregate-only egress allowlist. No free-form errors, actor IDs, text or URLs."""
import re
def need(v):
 if not v:raise ValueError('unsafe_aggregate')
def keys(d,ks):need(type(d)is dict and set(d)==set(ks.split()))
def integer(x):need(type(x)is int and 0<=x<=10000)
def timestamp(x):need(type(x)is str and re.fullmatch(r'2026-11-\d{2} \d{2}:\d{2}:\d{2}',x) is not None and '2026-11-11 21:00:00'<=x<'2026-11-18 21:00:00')
def post(p):
 keys(p,'publication_id post_id platform channel_id entries distinct_dialogues first_entry_utc last_entry_utc');need(type(p['publication_id'])is int and p['publication_id']>0);need(p['platform'] in ['telegram','max']);need(type(p['post_id'])is str and re.fullmatch(r'(?:[0-9]{1,64}|mid\.[A-Za-z0-9_-]{1,128})',p['post_id']) is not None);need(type(p['channel_id'])is str and re.fullmatch(r'-?[0-9]{1,64}',p['channel_id']) is not None)
 integer(p['entries']);integer(p['distinct_dialogues']);need(p['distinct_dialogues']<=p['entries']);timestamp(p['first_entry_utc']);timestamp(p['last_entry_utc']);need(p['first_entry_utc']<=p['last_entry_utc'])
def validate(x,c):
 keys(x,'ok installed_sha db_writes provider_calls message_sends aggregate');need(x['ok'] is True and x['installed_sha']==c['runtime_sha']);need(all(type(x[k])is int and x[k]==0 for k in ['db_writes','provider_calls','message_sends']))
 a=x['aggregate'];keys(a,'schema window coverage posts priority semantics publications');need(a['schema']=='post_entry_aggregate_v1');need(a['window']==dict(c['window'],business_timezone='Europe/Moscow',days=7));v=a['coverage'];keys(v,'entry_rows_read complete_within_bound unavailable_publication_entries invalid_binding_entries global_entry_rows_scanned global_window_entries');need(v['complete_within_bound'] is True)
 for k in ['entry_rows_read','unavailable_publication_entries','invalid_binding_entries','global_entry_rows_scanned','global_window_entries']:integer(v[k])
 need(type(a['posts'])is list and len(a['posts'])<=10000)
 for p in a['posts']:post(p)
 need(sum(p['entries'] for p in a['posts'])+v['unavailable_publication_entries']+v['invalid_binding_entries']==v['entry_rows_read'])
 need(a['semantics']=={'basis':'registry_accepted_entries_event_join_not_rechecked','entries_are_clicks':False,'dialogues_are_unique_people':False,'paid_organic':'unknown','cross_post_dialogues_additive':False,'leads_measured':False,'current_post_liveness_verified':False})
 need(type(a['priority'])is list and len(a['priority'])==4)
 for item,target in zip(a['priority'],c['targets']):
  keys(item,'target observed_posts zero_means');need(item['target']==target and item['zero_means']=='no_observed_registry_entry_publication_existence_reported_separately');need(item['observed_posts']==[p for p in a['posts'] if p['platform']==target['platform'] and p['channel_id']==target['channel_id'] and p['post_id']==target['message_id']])
 need(v['entry_rows_read']<=v['global_window_entries']<=v['global_entry_rows_scanned'])
 need(type(a['publications'])is list and len(a['publications'])<=4)
 for p in a['publications']:
  keys(p,'publication_id post_id platform channel_id version_id state');need(type(p['publication_id'])is str and re.fullmatch(r'[1-9][0-9]{0,19}',p['publication_id']) is not None);need(p['version_id'] is None or (type(p['version_id'])is str and re.fullmatch(r'[1-9][0-9]{0,19}',p['version_id']) is not None));need(p['state'] is None or p['state'] in ['draft','approved','sent','failed','rejected','pending','published','superseded','claimed','write_started','unknown','requires_attention','superseded_before_write']);need(any(p['platform']==t['platform'] and p['channel_id']==t['channel_id'] and p['post_id']==t['message_id'] for t in c['targets']))
 return x

STOP_CODES={'unsupported_server_version','wall_budget','targets_closed','runtime_changed','runtime_dirty','db_source_changed','entry_limit_incomplete','selected_byte_limit','output_byte_limit','target_ambiguous','capped_primary_plan','join_multiplication','explain_shape','explain_rows_unknown','explain_dependent','unbounded_base_access','explain_full_scan','explain_cost_or_missing_base','bounded_read_stopped'}
def safe_failure(raw):
 keys(raw,'ok error');need(raw['ok'] is False and raw['error'] in STOP_CODES);return raw
