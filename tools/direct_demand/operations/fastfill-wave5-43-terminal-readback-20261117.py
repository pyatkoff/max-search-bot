#!/usr/bin/env python3
"""Read authentic native receipts and public feeds; stage a git-backed checkpoint."""
import base64
import collections
import datetime
import hashlib
import io
import json
import os
import pathlib
import subprocess
import urllib.request
import xml.etree.ElementTree as ET
import zipfile

REPO = 'pyatkoff/max-search-bot'
APP = '8281b387f258932601439eb789560f6b2cbabd4d'
SOURCE = os.environ['GITHUB_SHA']
PRIOR_COMMIT = '465a8dd0ae0c890f238df7b943034c13aa4965e3'
PRIOR_PATH = 'tools/direct_demand/operations/fastfill-wave4-79-publication-receipt-20261117.json'
TARGET_PATH = 'tools/direct_demand/operations/fastfill-wave5-43-publication-receipt-20261117.json'
artifacts = []


def api(path, payload=None):
    args = ['gh', 'api', 'repos/' + REPO + '/' + path]
    if payload is not None:
        args += ['--method', 'POST', '--input', '-']
    return json.loads(subprocess.check_output(args, input=None if payload is None else json.dumps(payload).encode(), timeout=90))


def run(run_id, sha, conclusion='success'):
    r = api('actions/runs/' + str(run_id))
    assert r['head_sha'] == sha and r['run_attempt'] == 1 and r['conclusion'] == conclusion
    return r


def archive(artifact_id, run_id, digest=None):
    a = api('actions/artifacts/' + str(artifact_id))
    assert not a['expired'] and a['workflow_run']['id'] == run_id
    if digest:
        assert a['digest'] == 'sha256:' + digest
    data = subprocess.check_output(['gh', 'api', 'repos/' + REPO + '/actions/artifacts/' + str(artifact_id) + '/zip'], timeout=90)
    assert 'sha256:' + hashlib.sha256(data).hexdigest() == a['digest']
    with zipfile.ZipFile(io.BytesIO(data)) as z:
        assert z.testzip() is None
        files = {n: z.read(n) for n in z.namelist()}
    artifacts.append({k: a[k] for k in ['id', 'name', 'digest', 'workflow_run']})
    return files


def source(artifact_id, run_id, digest, state):
    files = archive(artifact_id, run_id, digest)
    receipt = json.loads(files['receipt.json'])
    assert receipt['state'] == state and receipt['runtime_sha'] == APP
    assert receipt['protected_surfaces'] == 22 and receipt['modified_hash_pins'] == 2
    assert receipt['send_attempts'] == receipt['feed_writes'] == 0
    assert set(files) == set(receipt['files']) | {'receipt.json'}
    assert all(hashlib.sha256(files[n]).hexdigest() == h for n, h in receipt['files'].items())
    return files, receipt


run(44145217016, '0b39116264a72d56d377b90ff64f8ef8ccc8e584')
source_files, accepted = source(14354874542, 44145217016, 'bdaf26dc9f22916a837a702db18403c3b5fe6f0f86fa3c810282ac01e647ee4b', 'WAVE5_43_EDITORIAL_ACCEPTED_EXISTING_PUBLISHER_SOURCE_ONLY')
assert accepted['total_rows'] == 43 and accepted['all65_classified']
assert accepted['additionally_excluded'] == [4269] and accepted['held_before_visual'] == 21
assert accepted['corrected_visual_ids'] == [4235,43494,110848,115812]
run(44146005426, 'b10b54e3dcc3c056463e281866b45e5d98fb5d0a')
listing = api('actions/runs/44146005426/artifacts?per_page=100')
assert listing['total_count'] == len(listing['artifacts'])
by_name = {a['name']: a for a in listing['artifacts']}
posts, operations, rows = [], [], {}
for batch in accepted['batches']:
    n = batch['batch']
    a = by_name['fastfill-p' + str(n) + '-native-one-attempt-20261117']
    fs = archive(a['id'], 44146005426)
    fs = {k.rsplit('/',1)[-1]: v for k,v in fs.items()}
    assert fs['original-exit.txt'].strip() == b'0'
    assert fs['manifest.json'] == source_files['p'+str(n)+'-manifest.json']
    assert fs['approval.json'] == source_files['p'+str(n)+'-approval.json']
    manifest = json.loads(fs['manifest.json'])
    expected = {r['hotel_id']: r for r in manifest['rows']}
    rows.update(expected)
    r = [json.loads(line) for line in fs['original-native-output.jsonl'].decode().splitlines() if line.startswith('{')][-1]
    terminal = json.loads(fs['terminal-verified.json'])
    sha = hashlib.sha256(fs['original-native-output.jsonl']).hexdigest()
    assert terminal['original_output_sha256'] == sha and terminal['posts'] == r['posts']
    assert r['ok'] and r['installed_sha'] == APP and r['operation'] == batch['operation']
    assert r['new_posts'] == r['post_write_attempts'] == 2*batch['rows']
    assert r['reused'] == 0 and len(r['posts']) == 2*batch['rows']
    by_platform = collections.defaultdict(set)
    for p in r['posts']:
        assert p['hotel_id'] in expected and p['state'] == 'sent' and p['body_photo_button_verified'] and not p['reused']
        platform = p['platform']; assert platform in ['telegram','max']
        assert all(p[k] == expected[p['hotel_id']][k] for k in ['logical_id','text_sha256','photo_sha256','photo_url'])
        assert p['source_key'] == ('tg_anytour_msk' if platform == 'telegram' else 'max_anytour_msk1')
        assert p['channel_id'] == ('-1002262666616' if platform == 'telegram' else '-80492244209683')
        assert p['button_text'] == '✈️ Узнать стоимость тура'
        assert p['post_url'].startswith('https://t.me/any_toour/' if platform == 'telegram' else 'https://max.ru/channel_anytour/')
        assert p['button_url'].startswith('https://t.me/anyru_bot?start=p_' if platform == 'telegram' else 'https://max.ru/id9704048781_1_bot?start=p_')
        by_platform[platform].add(p['hotel_id'])
    assert by_platform['telegram'] == by_platform['max'] == set(batch['ids'])
    posts += r['posts']
    operations.append({'operation':r['operation'],'unique_new_hotels':batch['rows'],'new_posts':r['new_posts'],'reused':0,'original_output_sha256':sha,'run_id':44146005426,'artifact_id':a['id']})
assert len(posts) == len({p['post_url'] for p in posts}) == len({p['publication_id'] for p in posts}) == 86
assert len(rows) == len({p['hotel_id'] for p in posts}) == 43 and 4269 not in rows and 66375 not in rows
assert collections.Counter(p['platform'] for p in posts) == {'telegram':43,'max':43}
assert sum(o['new_posts'] for o in operations) == 86
prior_raw = base64.b64decode(api('contents/'+PRIOR_PATH+'?ref='+PRIOR_COMMIT)['content'])
assert hashlib.sha256(prior_raw).hexdigest() == '41743ebbf30ea306d3d7ace6ecf79cc60565d79f7fdb7f9cf450ebbd3859596a'
prior = json.loads(prior_raw)
assert prior['paired_total_after'] == 956 and len(prior['advertising_status']['missing_native_hotel_ids']) == 296
held = json.loads(pathlib.Path('probe/held-current.json').read_bytes())
assert held['ok'] and held['db_writes'] == held['provider_calls'] == held['sender_calls'] == 0
p = held['publication']
assert p['publication_id'] == 1947 and p['version_id'] == 7476 and p['state'] == 'requires_attention'
assert p['write_attempted'] is False and p['write_attempts'] == 0 and p['error_reason'] == 'provider_read_failed'
scope = prior['advertising_status']['missing_native_hotel_ids'] + sorted(rows)
assert len(scope) == len(set(scope)) == 339
observation = {'mode':'PUBLIC_FEED_READ_ONLY','observed_at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'writes':0,'feeds':[]}
present_sets = []
for name in ['hotel-posts-telegram.xml','hotel-posts-max.xml','hotel-posts-all.xml']:
    url = 'https://app.anytoour.ru/feed/'+name
    with urllib.request.urlopen(urllib.request.Request(url,headers={'User-Agent':'AnyTour-approved-feed-readback/1.0'}),timeout=60) as response:
        assert response.status == 200
        raw = response.read()
    ids = [o.attrib['id'] for o in ET.fromstring(raw).findall('.//offer')]
    duplicate_ids = [i for i,c in collections.Counter(ids).items() if c>1]
    assert not duplicate_ids
    present = [h for h in scope if ('max_'+str(h) in ids if 'max' in name else 'tg_'+str(h) in ids if 'telegram' in name else 'max_'+str(h) in ids and 'tg_'+str(h) in ids)]
    present_sets.append(set(present))
    observation['feeds'].append({'name':name,'url':url,'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest(),'offers':len(ids),'by_platform':dict(collections.Counter(i.split('_',1)[0] for i in ids)),'duplicate_ids':duplicate_ids,'new_scope_present_ids':present,'new_scope_present':len(present)})
covered = set.intersection(*present_sets)
missing = [h for h in scope if h not in covered]
held_rows = json.loads(source_files['held-editorial.json'])
assert len(held_rows) == 22
receipt = {
 'state':'ALL43_PAIRED_HOTELS_86_POSTS_NATIVE_VERIFIED',
 'unique_new_hotels':43,'telegram':43,'max':43,'paired_total_before':956,'paired_total_after':999,'feed_rows_added':0,
 'context':{
  'task':'HOTEL/CONTENT FASTFILL W5','candidate_count':65,'source':'remaining reserve65 from W4 catalogue','photo_prepared':44,'editorial_accepted':43,
  'by_country':{'TH':25,'VN':18},'cover_freeze':accepted['cover_commit'],'visual_qa_sheets':4,
  'corrected_visual_ids':[4235,43494,110848,115812],'native_screen_run':44142065912,'source_acceptance_run':44145217016,
  'native_one_attempt_run':44146005426,'native_source_commit':'b10b54e3dcc3c056463e281866b45e5d98fb5d0a','installed_application_sha':APP,
  'installed_deploy_run':44139041068,'app_installs':0,'feed_writes':0,'price_writes':0,'attribution_config_writes':0,'new_workers':0},
 'operations':operations,'accepted_source_receipt':accepted,'native_artifacts':artifacts,
 'accepted_rows':[rows[i] for i in sorted(rows)],'held_editorial':held_rows,
 'fact_qualifications':json.loads(source_files['fact-qualifications.json']),'posts':posts,
 'prior_unfinished_cape_fahn':{'hotel_id':66375,'post_url':'https://t.me/any_toour/901','current_readonly':held,'prior_receipt_commit':PRIOR_COMMIT,'prior_receipt_path':PRIOR_PATH,'send_or_button_retries':0,'not_in_new43':True},
 'feeds_readback_after_this_batch':observation,
 'advertising_status':{'published_unique_hotels':999,'telegram_feed_unique_hotels':observation['feeds'][0]['offers'],'max_feed_unique_hotels':observation['feeds'][1]['offers'],
 'combined_feed_offers':observation['feeds'][2]['offers'],'published_pairs_missing_from_all_three_feeds':len(missing),'missing_native_hotel_ids':missing,
 'feed_writes_in_this_content_work':0,'price_writes_in_this_content_work':0,'price_freshness_for_new43':'NOT_CONFIRMED_BY_PRICE_OWNER',
 'route_feed_writer':'same existing route/feed owner; fresh PRICE27316/locks/ownership admission before native writes'}
}
output = pathlib.Path('terminal');output.mkdir()
serialized = json.dumps(receipt,ensure_ascii=False,indent=2)+'\n'
(output/'receipt.json').write_text(serialized)
(output/'feed-readback.json').write_text(json.dumps(observation,indent=2)+'\n')
base = api('git/commits/'+SOURCE)
tree = api('git/trees',{'base_tree':base['tree']['sha'],'tree':[{'path':TARGET_PATH,'mode':'100644','type':'blob','content':serialized}]})
commit = api('git/commits',{'message':'CONTENT W5 retain 43 actual pairs and current Cape Fahn hold without replay','tree':tree['sha'],'parents':[SOURCE]})
stage = {'state':'UNREFERENCED_VERIFIED_W5_43_RECEIPT','source_commit':SOURCE,'staged_commit':commit['sha'],'staged_tree':tree['sha'],'path':TARGET_PATH,
 'receipt_sha256':hashlib.sha256(serialized.encode()).hexdigest(),'hotels':43,'posts':86,'paired_total':999,'prior_cape_fahn_held':1,
 'missing_feed_pairs':len(missing),'feed_counts':[x['offers'] for x in observation['feeds']],'observed_at':observation['observed_at']}
(output/'stage.json').write_text(json.dumps(stage,indent=2)+'\n')
print('W5_TERMINAL_STAGE_JSON '+json.dumps(stage,separators=(',',':')))
print('W5_TERMINAL_FEEDS_JSON '+json.dumps(observation,separators=(',',':')))
