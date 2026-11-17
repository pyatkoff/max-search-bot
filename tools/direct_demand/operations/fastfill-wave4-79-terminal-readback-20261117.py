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
APP = '3ffab7f2e2d01c8cd35144fba621897e4ad75e5d'
SOURCE = os.environ['GITHUB_SHA']
PRIOR_COMMIT = 'efb40d9023ac9f6172ea22f6ba6511d1172c9f08'
PRIOR_PATH = 'tools/direct_demand/operations/fastfill-wave3-72-publication-receipt-20261117.json'
TARGET_PATH = 'tools/direct_demand/operations/fastfill-wave4-79-publication-receipt-20261117.json'
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


run(44133344446, 'a3f210d03fd23f3db28337250143381385e9eeae')
full_source, accepted80 = source(14349679332, 44133344446, '113735f980b7c216e3d826d1b490ff82be1e82a50aa63344fe82302703287609', 'WAVE4_80_EDITORIAL_ACCEPTED_EXISTING_PUBLISHER_SOURCE_ONLY')
run(44137847920, '297a3fa70b592475426ec413a52c15e0eb43c6e8')
nine_source, accepted9 = source(14352178459, 44137847920, '7961c4fca25bccf4a2f7435b6fdf345f4883b5c3dcc174e720eb793811ed5a14', 'WAVE4_NINE_MAX_EXISTING_TG_REUSE_SOURCE_ONLY')
run(44133796626, '7971139fa1c6b10dfee2fe8afea5b528dd7a30d0', 'failure')
old = archive(14349939695, 44133796626, 'e7b6c0146b84715133a34f53b2aeadd45070c073e60c1c1a6ef6f1c67ee4bffb')
old_output = next(v for n, v in old.items() if n.endswith('original-native-output.jsonl'))
old_final = [json.loads(l) for l in old_output.decode().splitlines() if l.startswith('{')][-1]
assert not old_final['ok'] and old_final['operation'] == 'commercial-hotel-batch-20261117-27-v1'
assert old_final['new_posts'] == len(old_final['posts']) == 9 and old_final['post_write_attempts'] == 10
assert all(x['platform'] == 'telegram' and x['body_photo_button_verified'] for x in old_final['posts'])
run(44135479839, '1ae1426be5140e830aac037b5a192a64f1260f07')
readback = archive(14352400936, 44135479839, '68b6b7b15b785452bff436893c754d2434e574d2686188e2c8cc02364c9c3ce9')
observed = json.loads(readback['result.json'])
assert observed['ok'] and observed['db_writes'] == observed['sender_calls'] == observed['provider_calls'] == 0
held = next(x for x in observed['publications'] if x['platform'] == 'telegram' and x['hotel_id'] == 66375)
cp = held['current_publication']
assert cp['publication_id'] == 1947 and cp['version_id'] == 7476 and cp['external_message_id'] == '901'
assert cp['state'] == 'requires_attention' and cp['error_reason'] == 'provider_read_failed'
assert cp['write_attempted'] is False and cp['write_attempts'] == 0 and cp['claim_token'] is None
fresh_held = json.loads(pathlib.Path('probe/held-current.json').read_bytes())
assert fresh_held['ok'] and fresh_held['db_writes'] == fresh_held['provider_calls'] == fresh_held['sender_calls'] == 0
assert fresh_held['publication']['publication_id'] == 1947 and fresh_held['publication']['version_id'] == 7476
assert fresh_held['publication']['state'] == 'requires_attention' and fresh_held['publication']['write_attempted'] is False and fresh_held['publication']['write_attempts'] == 0
held['latest_readonly'] = fresh_held
assert all(not x['file_exists'] for x in observed['publications'] if x['platform'] == 'max')

run(44137531598, 'c1b84a4ca10558b770c2a1d5ca293c60dae9d55f')
run(44138648243, '2f8085f772040592d245e0db0be345493c072916')
posts, operations, rows_by_id = [], [], {}
original_tg = {x['hotel_id']: x for x in old_final['posts']}
for run_id, batches, source_files in [(44137531598, [x for x in accepted80['batches'] if x['batch'] != 27], full_source), (44138648243, accepted9['batches'], nine_source)]:
    listing = api('actions/runs/' + str(run_id) + '/artifacts?per_page=100')
    assert listing['total_count'] == len(listing['artifacts'])
    by_name = {a['name']: a for a in listing['artifacts']}
    for b in batches:
        batch = b['batch']
        a = by_name['fastfill-p' + str(batch) + '-native-one-attempt-20261117']
        fs = archive(a['id'], run_id)
        fs = {n.rsplit('/', 1)[-1]: v for n, v in fs.items()}
        assert fs['original-exit.txt'].strip() == b'0'
        assert fs['manifest.json'] == source_files['p' + str(batch) + '-manifest.json']
        assert fs['approval.json'] == source_files['p' + str(batch) + '-approval.json']
        manifest = json.loads(fs['manifest.json'])
        current_rows = {x['hotel_id']: x for x in manifest['rows']}
        rows_by_id.update(current_rows)
        results = [json.loads(l) for l in fs['original-native-output.jsonl'].decode().splitlines() if l.startswith('{')]
        r = results[-1]
        terminal = json.loads(fs['terminal-verified.json'])
        output_hash = hashlib.sha256(fs['original-native-output.jsonl']).hexdigest()
        assert r['ok'] and r['operation'] == b['operation'] and r['installed_sha'] == APP
        assert terminal['original_output_sha256'] == output_hash and terminal['posts'] == r['posts']
        expected = set(b['ids'])
        n = b['rows']
        assert len(r['posts']) == 2 * n
        if batch == 35:
            assert n == 9 and r['new_posts'] == r['post_write_attempts'] == r['reused'] == 9
            fresh = json.loads(fs['exact-tg-readonly.json'])
            assert fresh['ok'] and fresh['rows'] == 9 and fresh['db_writes'] == fresh['sender_calls'] == fresh['provider_calls'] == 0
        else:
            assert n == 10 and r['new_posts'] == r['post_write_attempts'] == 20 and r['reused'] == 0
        platforms = collections.defaultdict(set)
        for p in r['posts']:
            assert p['hotel_id'] in expected and p['state'] == 'sent' and p['body_photo_button_verified']
            platform = p['platform']
            assert platform in ['telegram', 'max']
            assert p['reused'] == (batch == 35 and platform == 'telegram')
            row = current_rows[p['hotel_id']]
            assert all(p[k] == row[k] for k in ['logical_id', 'text_sha256', 'photo_sha256', 'photo_url'])
            assert p['source_key'] == ('tg_anytour_msk' if platform == 'telegram' else 'max_anytour_msk1')
            assert p['channel_id'] == ('-1002262666616' if platform == 'telegram' else '-80492244209683')
            assert p['button_text'] == '✈️ Узнать стоимость тура'
            assert p['post_url'].startswith('https://t.me/any_toour/' if platform == 'telegram' else 'https://max.ru/channel_anytour/')
            assert p['button_url'].startswith('https://t.me/anyru_bot?start=p_' if platform == 'telegram' else 'https://max.ru/id9704048781_1_bot?start=p_')
            if batch == 35 and platform == 'telegram':
                assert {k: v for k, v in p.items() if k != 'reused'} == {k: v for k, v in original_tg[p['hotel_id']].items() if k != 'reused'}
            platforms[platform].add(p['hotel_id'])
        assert platforms['max'] == platforms['telegram'] == expected
        operations.append({'operation': r['operation'], 'unique_new_hotels': n, 'new_posts': r['new_posts'], 'reused': r['reused'], 'original_output_sha256': output_hash, 'run_id': run_id, 'artifact_id': a['id']})
        posts += r['posts']

assert len(posts) == len({p['post_url'] for p in posts}) == len({p['publication_id'] for p in posts}) == 158
assert len(rows_by_id) == len({p['hotel_id'] for p in posts}) == 79 and 66375 not in rows_by_id
assert collections.Counter(p['platform'] for p in posts) == {'telegram': 79, 'max': 79}
assert sum(p['reused'] for p in posts) == 9 and sum(o['new_posts'] for o in operations) == 149
prior = json.loads(base64.b64decode(api('contents/' + PRIOR_PATH + '?ref=' + PRIOR_COMMIT)['content']))
assert prior['paired_total_after'] == 877 and len(prior['advertising_status']['missing_native_hotel_ids']) == 217
scope = prior['advertising_status']['missing_native_hotel_ids'] + sorted(rows_by_id)
assert len(scope) == len(set(scope)) == 296
feed_observation = {'mode': 'PUBLIC_FEED_READ_ONLY', 'observed_at': datetime.datetime.now(datetime.timezone.utc).isoformat(), 'writes': 0, 'feeds': []}
present_by_feed = []
for name in ['hotel-posts-telegram.xml', 'hotel-posts-max.xml', 'hotel-posts-all.xml']:
    url = 'https://app.anytoour.ru/feed/' + name
    with urllib.request.urlopen(urllib.request.Request(url, headers={'User-Agent': 'AnyTour-approved-feed-readback/1.0'}), timeout=60) as response:
        assert response.status == 200
        raw = response.read()
    ids = [o.attrib['id'] for o in ET.fromstring(raw).findall('.//offer')]
    duplicates = [i for i, count in collections.Counter(ids).items() if count > 1]
    assert not duplicates
    present = [h for h in scope if ('max_' + str(h) in ids if 'max' in name else 'tg_' + str(h) in ids if 'telegram' in name else 'max_' + str(h) in ids and 'tg_' + str(h) in ids)]
    present_by_feed.append(set(present))
    feed_observation['feeds'].append({'name': name, 'url': url, 'bytes': len(raw), 'sha256': hashlib.sha256(raw).hexdigest(), 'offers': len(ids), 'by_platform': dict(collections.Counter(i.split('_', 1)[0] for i in ids)), 'duplicate_ids': duplicates, 'new_scope_present_ids': present, 'new_scope_present': len(present)})
covered = set.intersection(*present_by_feed)
missing = [h for h in scope if h not in covered]
f = feed_observation['feeds']
receipt = {'state': '79_PAIRED_HOTELS_158_COMPLETE_POSTS_ONE_PARTIAL_TG_HELD', 'unique_new_hotels': 79, 'telegram': 79, 'max': 79, 'paired_total_before': 877, 'paired_total_after': 956, 'feed_rows_added': 0, 'context': {'task': 'HOTEL/CONTENT FASTFILL W4', 'candidate_count': 100, 'paid_profiles_and_covers': 81, 'editorial_accepted': 80, 'paired_published': 79, 'by_country': {'TH': 51, 'VN': 28}, 'cover_freeze': accepted80['cover_commit'], 'visual_qa_sheets': 7, 'corrected_visual_ids': [22718, 47213], 'installed_application_sha': APP, 'source_acceptance_run': 44133344446, 'remaining70_native_run': 44137531598, 'nine_max_native_run': 44138648243, 'app_installs': 0, 'feed_writes': 0, 'price_writes': 0, 'attribution_config_writes': 0}, 'operations': operations, 'accepted_source_receipt': accepted80, 'nine_max_source_receipt': accepted9, 'native_artifacts': artifacts, 'accepted_rows': [rows_by_id[i] for i in sorted(rows_by_id)], 'held_editorial': json.loads(full_source['held-editorial.json']), 'posts': posts, 'partial_publications': [held], 'failed_original': {'run_id': 44133796626, 'head_sha': '7971139fa1c6b10dfee2fe8afea5b528dd7a30d0', 'conclusion': 'failure', 'operation': 'commercial-hotel-batch-20261117-27-v1', 'summary': old_final, 'original_output_sha256': hashlib.sha256(old_output).hexdigest(), 'readonly_run': 44135479839, 'replayed': False, 'complete_tg_reused_by_p35': 9, 'actual_photo_sends': 10}, 'message_counts': {'new_complete_pair_messages': 158, 'distinct_new_channel_messages_including_held': 159, 'remaining70_and_p35_message_writes': 149, 'original_p27_photo_sends': 10, 'duplicate_sends': 0}, 'feeds_readback_after_this_batch': feed_observation, 'advertising_status': {'published_unique_hotels': 956, 'telegram_feed_unique_hotels': f[0]['offers'], 'max_feed_unique_hotels': f[1]['offers'], 'combined_feed_offers': f[2]['offers'], 'published_pairs_missing_from_all_three_feeds': len(missing), 'missing_native_hotel_ids': missing, 'feed_writes_in_this_content_work': 0, 'price_writes_in_this_content_work': 0, 'price_freshness_for_new79': 'NOT_CONFIRMED_BY_PRICE_OWNER', 'route_feed_writer': 'same existing route/feed owner; fresh PRICE27316/locks/ownership admission before native writes'}}
output = pathlib.Path('terminal'); output.mkdir()
serialized = json.dumps(receipt, ensure_ascii=False, indent=2) + '\n'
(output / 'receipt.json').write_text(serialized)
(output / 'feed-readback.json').write_text(json.dumps(feed_observation, indent=2) + '\n')
base = api('git/commits/' + SOURCE)
tree = api('git/trees', {'base_tree': base['tree']['sha'], 'tree': [{'path': TARGET_PATH, 'mode': '100644', 'type': 'blob', 'content': serialized}]})
commit = api('git/commits', {'message': 'CONTENT W4 retain 79 actual pairs and original partial TG failure without replay', 'tree': tree['sha'], 'parents': [SOURCE]})
stage = {'state': 'UNREFERENCED_VERIFIED_W4_79_RECEIPT', 'source_commit': SOURCE, 'staged_commit': commit['sha'], 'staged_tree': tree['sha'], 'path': TARGET_PATH, 'receipt_sha256': hashlib.sha256(serialized.encode()).hexdigest(), 'hotels': 79, 'complete_posts': 158, 'paired_total': 956, 'partial_tg_held': 1, 'missing_feed_pairs': len(missing), 'feed_counts': [x['offers'] for x in f], 'observed_at': feed_observation['observed_at']}
(output / 'stage.json').write_text(json.dumps(stage, indent=2) + '\n')
print('W4_TERMINAL_STAGE_JSON ' + json.dumps(stage, separators=(',', ':')))
print('W4_TERMINAL_FEEDS_JSON ' + json.dumps(feed_observation, separators=(',', ':')))
