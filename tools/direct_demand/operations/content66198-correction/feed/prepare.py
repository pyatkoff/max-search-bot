#!/usr/bin/env python3
"""Compile only the approved 66198 binding into the unchanged schema-5 writer.

This tool performs local preparation/verification only. Native scripts are
executed once by the existing CONTENT carrier after its normal admission.
"""
from __future__ import annotations
import argparse
import base64
import datetime as dt
import hashlib
import json
import re
from pathlib import Path
import time
import xml.etree.ElementTree as ET

HERE = Path(__file__).resolve().parent
OLD = 'Одиннадцать ресторанов'
NEW = 'Десять ресторанов, баров и лаунжей'
CAPTION_OP = 'commercial-waldorf66198-caption-correction-20261123-v1'
APP = 'b1da1418ff75cba9657d8b5a2971270c8eafb1c9'
FEED_OP = 'commercial-channel-hotel-feeds-20261123-update66198'
NAMES = ['hotel-posts-telegram.xml', 'hotel-posts-max.xml', 'hotel-posts-all.xml']
URLS = {'telegram': 'https://t.me/any_toour/678', 'max': 'https://max.ru/channel_anytour/AaHo8ukNYB8'}
PREFIX = b'<?php\ndeclare(strict_types=1);\n'
SOURCE_HASHES = {
    'monitor-price-contract.php': '3c61a260d9c40f7741d644a9531aebea58790595ae2adbad6ff8163975621ede',
    'publish-commercial-channel-hotel-feeds-20261103-1.php': '8609a2cf06d4a46738f934047d46744bdc54d3863e7c2921fdfec1a9be4b7a5e',
}
BLOCK = re.compile(rb'<offer id="([A-Za-z0-9_-]+)">.*?</offer>', re.S)
DESC = re.compile(rb'(<description>)(.*?)(</description>)', re.S)

def need(ok, message):
    if not ok:
        raise ValueError(message)

def sha(raw):
    return hashlib.sha256(raw).hexdigest()

def canonical(value):
    return json.dumps(value, ensure_ascii=False, separators=(',', ':')).encode()

def object_pairs(pairs):
    obj = {}
    for k, v in pairs:
        need(k not in obj, 'duplicate_json_key')
        obj[k] = v
    return obj

def parse(raw):
    return json.loads(raw, object_pairs_hook=object_pairs)

def read(path):
    return parse(Path(path).read_bytes())

def save(out, name, value):
    out.mkdir(parents=True, exist_ok=True)
    raw = value if isinstance(value, bytes) else canonical(value) + b'\n'
    with (out / name).open('xb') as f:
        f.write(raw)

def hash_value(value):
    return isinstance(value, str) and re.fullmatch('[a-f0-9]{64}', value) is not None

def binding(value):
    need(set(value) == {'schema', 'kind', 'installed_sha', 'caption_operation', 'feed_operation', 'approval_comment_id'}, 'binding_schema')
    need(value['schema'] == 1 and value['kind'] == 'CONTENT66198_FEED_BINDING'
         and value['installed_sha'] == APP
         and value['caption_operation'] == CAPTION_OP and value['feed_operation'] == FEED_OP
         and value['approval_comment_id'] == 7072600922, 'binding_scope')
    return {k: value[k] for k in ['schema', 'kind', 'installed_sha', 'caption_operation', 'feed_operation', 'approval_comment_id']}

def fresh(stamp, now=None):
    need(isinstance(stamp, str) and re.fullmatch(r'\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|\+00:00)', stamp), 'capture_time_invalid')
    observed = dt.datetime.fromisoformat(stamp.replace('Z', '+00:00')).timestamp()
    now = time.time() if now is None else now
    need(now - 900 <= observed <= now + 60, 'capture_expired')

def correct(text):
    need(isinstance(text, str) and 0 < len(text.encode()) < 30000, 'caption_invalid')
    if text.count(OLD) == 1 and text.count(NEW) == 0:
        return text.replace(OLD, NEW)
    need(text.count(OLD) == 0 and text.count(NEW) == 1, 'approved_phrase_not_unique')
    return text

def exact_variants(text):
    fixed = correct(text)
    return {fixed, fixed.replace(NEW, OLD)}

def journal(pin):
    need(set(pin) == {'sha256', 'bytes', 'lines', 'collector_run_id', 'cycle_state'}
         and hash_value(pin['sha256']) and type(pin['bytes']) is int and 0 < pin['bytes'] <= 2147483648
         and type(pin['lines']) is int and pin['lines'] > 0, 'journal_pin_invalid')
    return {k: pin[k] for k in ['sha256', 'bytes', 'lines', 'collector_run_id', 'cycle_state']}

POST_FIELDS = ['hotel_id', 'platform', 'publication_id', 'version_id', 'message_id', 'post_url', 'caption', 'caption_sha256',
               'photo_signature', 'attachments_sha256', 'button_url_sha256', 'source_key', 'monitor_price_record_id', 'caption_correction_operation']

def posts(rows):
    need(isinstance(rows, list) and len(rows) == 2, 'post_scope')
    result = {}
    for r in rows:
        p = r.get('platform')
        need(p in URLS and p not in result and r.get('hotel_id') == 66198 and r.get('post_url') == URLS[p], 'post_identity')
        need(type(r.get('publication_id')) is int and r['publication_id'] > 0
             and type(r.get('version_id')) is int and r['version_id'] > 0
             and isinstance(r.get('message_id'), str) and r['message_id'], 'post_version')
        need(p != 'telegram' or r['message_id'] == '678', 'telegram_message')
        need(sha(r['caption'].encode()) == r['caption_sha256'], 'caption_hash')
        correct(r['caption'])
        for k in ['caption_sha256', 'attachments_sha256', 'button_url_sha256']:
            need(hash_value(r.get(k)), 'post_hash')
        need(r['source_key'] == ('max_anytour_msk1' if p == 'max' else 'tg_anytour_msk')
             and r['photo_signature'].startswith(p + ':'), 'post_source')
        result[p] = {k: r.get(k) for k in POST_FIELDS}
    return [result[p] for p in ['telegram', 'max']]

def native_read(raw, metadata, b):
    s = parse(raw)
    need(s.get('ok') is True and s.get('operation') == 'commercial-waldorf66198-caption-read-20261123-v1'
         and s.get('installed_sha') == b['installed_sha'] and s.get('post_write_attempts') == 0
         and s.get('monitor_pending') is False, 'native_read_unconfirmed')
    need(metadata.get('ok') is True and metadata.get('operation') == 'commercial-waldorf66198-journal-metadata-20261123-v1'
         and metadata.get('installed_sha') == b['installed_sha'] and metadata.get('monitor_pending') is False
         and metadata.get('post_write_attempts') == metadata.get('provider_calls') == metadata.get('db_writes') == 0, 'metadata_unconfirmed')
    fresh(s['observed_at_utc']); fresh(metadata['observed_at_utc'])
    pin = journal(s['journal_pin'])
    need(pin == journal(metadata['journal_pin']) and s['monitor_journal_sha256'] == pin['sha256'], 'read_journal_changed')
    for r in s['posts']:
        need((r.get('platform') != 'max' or r.get('provider_live_verified') is True) and r.get('status') == 'sent'
             and r.get('button_resolves_to_current_post') is True and r.get('media_count') == 1 and r.get('media_types') == ['image'],
             'native_post_not_verified')
    return {'journal_pin': pin, 'posts': posts(s['posts']), 'native_read_sha256': sha(raw)}

def feed_url(platform, offer_id, post_url):
    if platform == 'telegram':
        return post_url
    alias = 'h1_' + sha(('anytour|' + offer_id + '|' + post_url).encode())[:32]
    token = base64.urlsafe_b64encode(canonical({'post_id': alias, 'offer_id': offer_id})).decode().rstrip('=')
    return 'https://max.ru/id9704048781_2_bot?startapp={yclid}_region_{region_id}_campaign_{campaign_id}_ctx1_' + token

def xml(raw):
    need(isinstance(raw, bytes) and 1000 < len(raw) < 4000000 and b'<!doctype' not in raw.lower()
         and b'<!entity' not in raw.lower() and b'available=' not in raw.lower(), 'xml_envelope')
    raw.decode('utf-8')
    root = ET.fromstring(raw)
    need(root.tag == 'yml_catalog', 'xml_root')
    nodes = {}
    for n in root.iter('offer'):
        i = n.get('id')
        need(i and re.fullmatch('[A-Za-z0-9_-]+', i) and i not in nodes, 'duplicate_offer')
        nodes[i] = n
    blocks = {}
    for m in BLOCK.finditer(raw):
        i = m[1].decode()
        need(i not in blocks, 'duplicate_offer')
        blocks[i] = m[0]
    need(list(nodes) == list(blocks), 'offer_envelope_or_order')
    return nodes, blocks

def field(node, tag):
    matches = list(node.iter(tag))
    need(len(matches) == 1 and matches[0] in list(node), 'field_not_unique')
    return ''.join(matches[0].itertext())

def resolve(ps, files):
    need([f['name'] for f in files] == NAMES, 'file_scope')
    parsed = {}
    for f in files:
        raw = base64.b64decode(f['content_base64'], validate=True)
        need(len(raw) == f['bytes'] and sha(raw) == f['sha256'], 'preimage_hash')
        parsed[f['name']] = xml(raw)
        need(len(parsed[f['name']][0]) == f['rows'], 'preimage_rows')
    ts, ms, combined = [parsed[n][0] for n in NAMES]
    need(len(ts) == len(ms) > 0 and set(ts).isdisjoint(ms) and set(combined) == set(ts) | set(ms), 'inventory_changed')
    result = []
    for p in posts(ps):
        platform = p['platform']; name = NAMES[0] if platform == 'telegram' else NAMES[1]
        found = [(i, n) for i, n in parsed[name][0].items()
                 if i.startswith('tg_' if platform == 'telegram' else 'max_')
                 and field(n, 'url') == feed_url(platform, i, p['post_url'])]
        need(len(found) == 1, 'actual_offer_not_unique')
        i, node = found[0]; other = combined.get(i)
        need(i == ('tg_' if platform == 'telegram' else 'max_') + '66198', 'exact_offer_changed')
        need(other is not None, 'combined_target_missing')
        for n in [node, other]:
            need(field(n, 'url') == feed_url(platform, i, p['post_url'])
                 and field(n, 'custom_label_0') == platform and field(n, 'description') in exact_variants(p['caption']), 'feed_caption_not_current')
        result.append({'operation': CAPTION_OP, 'hotel_id': 66198, 'platform': platform, 'publication_id': p['publication_id'],
                       'message_id': p['message_id'], 'post_url': p['post_url'], 'previous_version_id': p['version_id'],
                       'previous_text_sha256': p['caption_sha256'], 'text_sha256': sha(correct(p['caption']).encode()),
                       'photo_signature': p['photo_signature'], 'attachments_sha256': p['attachments_sha256'],
                       'button_url_sha256': p['button_url_sha256'], 'source_key': p['source_key'],
                       'offer_id': i, 'feed_url': feed_url(platform, i, p['post_url']),
                       'previous_caption_correction_operation': p['caption_correction_operation']})
    return result

def project(raw, updates, name):
    nodes, old_blocks = xml(raw)
    targets = {u['offer_id']: u for u in updates if name == NAMES[2] or u['platform'] == ('telegram' if name == NAMES[0] else 'max')}
    need(len(targets) == (2 if name == NAMES[2] else 1), 'target_count')
    for i, u in targets.items():
        need(i in nodes and field(nodes[i], 'url') == u['feed_url'] and field(nodes[i], 'custom_label_0') == u['platform']
             and field(nodes[i], 'description') in exact_variants(u['old_text']) and correct(u['old_text']) == u['text'], 'description_preimage')
    changed = 0
    def replace_offer(m):
        nonlocal changed
        i = m[1].decode()
        if i not in targets:
            return m[0]
        u = targets[i]
        matches = list(DESC.finditer(m[0]))
        need(len(matches) == 1, 'description_not_unique')
        d = matches[0]; content = d[2]
        if field(nodes[i], 'description') == u['text']:
            need(content.count(OLD.encode()) == 0 and content.count(NEW.encode()) == 1, 'raw_phrase_changed')
            return m[0]
        need(content.count(OLD.encode()) == 1 and content.count(NEW.encode()) == 0, 'raw_phrase_changed')
        changed += 1
        return m[0][:d.start(2)] + content.replace(OLD.encode(), NEW.encode()) + m[0][d.end(2):]
    after = BLOCK.sub(replace_offer, raw)
    new_nodes, new_blocks = xml(after)
    need(list(old_blocks) == list(new_blocks), 'offer_order_changed')
    for i, u in targets.items():
        need(field(new_nodes[i], 'description') == u['text'], 'description_projection')
    return after, changed

def captured(raw, b, snapshot_raw=None):
    c = parse(raw)
    need(c.get('ok') is True and c.get('phase') == 'pre_edit_capture' and c.get('kind') == 'CONTENT66198_FEED_READ'
         and c.get('operation') == CAPTION_OP and c.get('feed_operation') == FEED_OP and c.get('installed_sha') == b['installed_sha']
         and c.get('approval_comment_id') == 7072600922 and c.get('filesystem_writes') == c.get('provider_calls') == c.get('database_writes') == 0
         and c.get('locks_released_before_return') is True and c.get('monitor_journal_unchanged') is True, 'capture_not_confirmed')
    fresh(c['observed_at_utc']); journal(c['journal_pin'])
    if snapshot_raw is not None:
        need(c['native_read_sha256'] == sha(snapshot_raw) and posts(parse(snapshot_raw)['posts']) == c['posts'], 'capture_native_binding_changed')
    need(resolve(c['posts'], c['files']) == c['caption_updates'], 'capture_resolved_binding_changed')
    return c

def compile_php(b, context, phase):
    contract = (HERE / 'contract.php').read_bytes(); template = (HERE / 'capture.php').read_bytes()
    need(contract.startswith(PREFIX) and template.startswith(PREFIX), 'php_header_changed')
    definitions = {'FEED66198_BINDING_B64': base64.b64encode(canonical(b)).decode(),
                   'FEED66198_CONTEXT_B64': base64.b64encode(canonical(context)).decode(), 'FEED66198_PHASE': phase}
    header = ''.join("const %s='%s';\n" % item for item in definitions.items()).encode()
    return PREFIX + header + contract[len(PREFIX):] + b'\n' + template[len(PREFIX):]

def verify_manifest(m, raw, c, b):
    need(m.get('operation') == CAPTION_OP and m.get('expected_installed_sha') == b['installed_sha']
         and m.get('row_count') == 2 and isinstance(m.get('rows'), list) and len(m['rows']) == 2, 'manifest_scope')
    before = {p['platform']: p for p in c['posts']}
    seen = set()
    for r in m['rows']:
        p = r['platform']; need(p in before and p not in seen, 'manifest_pair'); seen.add(p); old = before[p]
        for k in ['hotel_id', 'platform', 'publication_id', 'version_id', 'message_id', 'post_url', 'photo_signature', 'attachments_sha256', 'button_url_sha256', 'source_key']:
            need(r.get(k) == old[k], 'manifest_current_identity_changed:' + k)
        need(r.get('old_text') == old['caption'] and r.get('new_text') == correct(old['caption'])
             and r.get('old_text_sha256') == old['caption_sha256'] and r.get('new_text_sha256') == sha(correct(old['caption']).encode()), 'manifest_nonphrase_delta')
    return sha(raw)

def verified_receipts(r, raw, c_raw, c, m, m_raw, b):
    need(r.get('ok') is True and r.get('phase') == 'post_edit_receipts' and r.get('operation') == CAPTION_OP
         and r.get('feed_operation') == FEED_OP and r.get('installed_sha') == b['installed_sha']
         and r.get('capture_sha256') == sha(c_raw) and r.get('pre_edit_feeds_unchanged') is True
         and r.get('filesystem_writes') == r.get('provider_calls') == r.get('database_writes') == 0
         and r.get('locks_released_before_return') is True and r.get('monitor_journal_unchanged') is True, 'receipts_capture_unconfirmed')
    fresh(r['observed_at_utc']); need(journal(r['journal_pin']) == journal(c['journal_pin']), 'monitor_changed_after_capture')
    need(len(r['caption_operations']) == 1 and set(r['caption_states']) == {CAPTION_OP}, 'receipt_operation_scope')
    op = r['caption_operations'][0]; state = r['caption_states'][CAPTION_OP]
    need(op['operation'] == CAPTION_OP and op['row_count'] == 2 and op['installed_sha'] == b['installed_sha']
         and op['manifest_sha256'] == sha(m_raw) and hash_value(op['journal_sha256']), 'receipt_operation_pin')
    need(state['operation'] == CAPTION_OP and state['row_count'] == 2 and state['complete'] is True
         and state['manifest_sha256'] == sha(m_raw) and state['installed_sha'] == b['installed_sha'] and len(state['posts']) == 2, 'receipt_terminal_scope')
    plans = {x['platform'] + ':' + str(x['publication_id']): x for x in m['rows']}
    need(set(state['posts']) == set(plans), 'receipt_post_scope')
    updates = []
    by_key = {x['platform'] + ':' + str(x['publication_id']): x for x in c['caption_updates']}
    for key, post in state['posts'].items():
        need(post['state'] == 'confirmed', 'receipt_not_confirmed'); p = post['plan']; a = post['receipt']; approved = plans[key]
        for k, v in p.items():
            need(k in approved and approved[k] == v, 'receipt_plan_changed:' + k)
        for k in ['hotel_id', 'platform', 'publication_id', 'message_id', 'post_url', 'photo_signature', 'button_url_sha256', 'source_key']:
            need(a.get(k) == approved[k], 'receipt_identity_changed:' + k)
        skip = approved['old_text'] == approved['new_text']
        need(a.get('previous_version_id') == approved['version_id'] and type(a.get('version_id')) is int and a['version_id'] > 0
             and (a['version_id'] == approved['version_id']) == skip and a.get('same_caption_skipped') is skip
             and a.get('text_sha256') == approved['new_text_sha256'] and a.get('previous_text_sha256') == approved['old_text_sha256']
             and a.get('previous_attachments_sha256') == approved['attachments_sha256'] and hash_value(a.get('attachments_sha256'))
             and a.get('state') == 'sent' and a.get('button_text') == '✈️ Узнать стоимость тура'
             and a.get('caption_correction_operation') == (by_key[key]['previous_caption_correction_operation'] if skip else CAPTION_OP)
             and a.get('monitor_price_record_id') == approved.get('monitor_price_record_id'),
             'receipt_version_or_preservation_changed')
        for k in ['body_photo_button_verified', 'formatting_verified', 'existing_message_reused', 'existing_token_resolves_to_current_version']:
            need(a.get(k) is True, 'receipt_verification_missing:' + k)
        updates.append(dict(by_key[key], old_text=approved['old_text'], text=approved['new_text']))
    return updates

def write_compile(b, context, input_raw, source_root):
    root = Path(source_root)
    sources = {n: (root / n).read_bytes() for n in SOURCE_HASHES}
    need(all(sha(sources[n]) == h for n, h in SOURCE_HASHES.items()), 'existing_writer_source_changed')
    contract = (HERE / 'contract.php').read_bytes()
    need(all(s.startswith(PREFIX) for s in [contract, *sources.values()]), 'php_header_changed')
    writer = sources['publish-commercial-channel-hotel-feeds-20261103-1.php'][len(PREFIX):]
    require = b"require_once __DIR__.'/monitor-price-contract.php';"
    need(writer.count(require) == 1, 'existing_writer_dependency_changed')
    writer = writer.replace(require, b'')
    definitions = {'FEED66198_BINDING_B64': base64.b64encode(canonical(b)).decode(),
                   'FEED66198_CONTEXT_B64': base64.b64encode(canonical(context)).decode(),
                   'EXPECTED_INSTALLED_SHA': b['installed_sha'], 'PUBLIC_STATIC_FEED_OPERATION': FEED_OP,
                   'PUBLIC_STATIC_FEED_INPUT_SHA256': sha(input_raw), 'PUBLIC_STATIC_FEED_INPUT_B64': base64.b64encode(input_raw).decode()}
    header = ''.join("const %s='%s';\n" % item for item in definitions.items()).encode()
    return PREFIX + header + sources['monitor-price-contract.php'][len(PREFIX):] + b'\n' + contract[len(PREFIX):] + b'\n' + writer

def main():
    p = argparse.ArgumentParser(description=__doc__); p.add_argument('phase', choices=['capture', 'verify-capture', 'receipts', 'prepare', 'verify-writer', 'verify-readback'])
    for name in ['binding', 'snapshot', 'journal', 'capture', 'manifest', 'result', 'receipts', 'preparation', 'source-root', 'readback', 'edit-result']:
        p.add_argument('--' + name)
    p.add_argument('--exit-code', type=int, default=0); p.add_argument('--out', required=True)
    a = p.parse_args(); b = binding(read(a.binding)); out = Path(a.out)
    if a.phase == 'capture':
        ctx = native_read(Path(a.snapshot).read_bytes(), read(a.journal), b)
        script = compile_php(b, ctx, 'capture'); save(out, 'capture.php', script)
        save(out, 'capture-compile.json', {'ok': True, 'operation': CAPTION_OP, 'native_read_sha256': ctx['native_read_sha256'], 'script_sha256': sha(script), 'writes': 0})
    elif a.phase == 'verify-capture':
        c_raw = Path(a.capture).read_bytes(); c = captured(c_raw, b, Path(a.snapshot).read_bytes())
        old = {x['platform']: x for x in c['posts']}; changes = 0; diffs = []
        updates = [dict(x, old_text=old[x['platform']]['caption'], text=correct(old[x['platform']]['caption'])) for x in c['caption_updates']]
        for f in c['files']:
            before = base64.b64decode(f['content_base64'], validate=True)
            after, changed = project(before, updates, f['name']); changes += changed
            before_nodes, _ = xml(before); after_nodes, _ = xml(after)
            for u in updates:
                if f['name'] != NAMES[2] and u['platform'] != ('telegram' if f['name'] == NAMES[0] else 'max'):
                    continue
                diffs.append({'file': f['name'], 'offer_id': u['offer_id'], 'post_url': u['post_url'],
                              'before': field(before_nodes[u['offer_id']], 'description'),
                              'after': field(after_nodes[u['offer_id']], 'description'),
                              'changed': field(before_nodes[u['offer_id']], 'description') != field(after_nodes[u['offer_id']], 'description')})
        need(c['target_description_occurrences'] == 4 and c['description_changes'] == changes, 'capture_change_count')
        save(out, 'capture-proof.json', {'ok': True, 'operation': CAPTION_OP, 'capture_sha256': sha(c_raw), 'description_changes': changes,
                                        'target_description_occurrences': 4, 'all_other_bytes_preserved': True, 'writes': 0, 'approved_diffs': diffs})
        print(json.dumps({'approved_phrase_only_diffs': diffs}, ensure_ascii=False))
    elif a.phase == 'receipts':
        c_raw = Path(a.capture).read_bytes(); c = captured(c_raw, b); m_raw = Path(a.manifest).read_bytes(); m = parse(m_raw)
        manifest_sha = verify_manifest(m, m_raw, c, b); result_raw = Path(a.edit_result or a.result).read_bytes(); result = parse(result_raw)
        need(a.exit_code == 0 and result.get('ok') is True and result.get('operation') == CAPTION_OP
             and result.get('manifest_sha256') == manifest_sha and isinstance(result.get('posts'), list) and len(result['posts']) == 2,
             'edit_result_not_confirmed')
        meta = read(a.journal); need(meta.get('ok') is True and meta.get('monitor_pending') is False
             and meta.get('installed_sha') == b['installed_sha'] and journal(meta['journal_pin']) == journal(c['journal_pin']), 'post_edit_journal_changed')
        fresh(meta['observed_at_utc'])
        ctx = {'journal_pin': journal(meta['journal_pin']), 'manifest_sha256': manifest_sha, 'capture_sha256': sha(c_raw),
               'edit_result_sha256': sha(result_raw), 'caption_updates': c['caption_updates'],
               'files': [{k: f[k] for k in ['name', 'sha256', 'bytes']} for f in c['files']]}
        save(out, 'receipts.php', compile_php(b, ctx, 'receipts'))
    elif a.phase == 'prepare':
        c_raw = Path(a.capture).read_bytes(); c = captured(c_raw, b); m_raw = Path(a.manifest).read_bytes(); m = parse(m_raw)
        verify_manifest(m, m_raw, c, b); r_raw = Path(a.receipts).read_bytes(); r = parse(r_raw)
        updates = verified_receipts(r, r_raw, c_raw, c, m, m_raw, b); files = []; changes = 0
        for f in c['files']:
            before = base64.b64decode(f['content_base64'], validate=True); after, changed = project(before, updates, f['name']); changes += changed
            files.append({'name': f['name'], 'sha256': sha(after), 'bytes': len(after), 'rows': f['rows'], 'expected_previous_sha256': f['sha256'],
                          'content_base64': base64.b64encode(after).decode(), 'previous_content_base64': f['content_base64']})
        i = {'schema_version': 5, 'operation': FEED_OP, 'files': files, 'generated_at': dt.datetime.now(dt.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ'),
             'caption_operations': r['caption_operations'], 'caption_updates': c['caption_updates']}
        raw = canonical(i); ctx = {'journal_pin': journal(r['journal_pin'])}; writer = write_compile(b, ctx, raw, a.source_root)
        summaries = [{k: f[k] for k in ['name', 'sha256', 'bytes', 'rows', 'expected_previous_sha256']} for f in files]
        prep = {'ok': True, 'operation': CAPTION_OP, 'feed_operation': FEED_OP, 'installed_sha': b['installed_sha'], 'input_sha256': sha(raw),
                'capture_sha256': sha(c_raw), 'caption_receipts_sha256': sha(r_raw), 'manifest_sha256': sha(m_raw),
                'writer_sha256': sha(writer), 'existing_writer_sha256': SOURCE_HASHES['publish-commercial-channel-hotel-feeds-20261103-1.php'],
                'files': summaries, 'target_description_occurrences': 4, 'description_changes': changes,
                'all_other_bytes_preserved': True, 'price_changes': 0, 'url_changes': 0, 'offer_changes': 0, 'new_posts': 0, 'retry_authorized': False}
        save(out, 'input.json', raw); save(out, 'writer.php', writer); save(out, 'preparation.json', prep)
        save(out, 'readback.php', compile_php(b, dict(ctx, files=summaries, input_sha256=sha(raw)), 'readback'))
    elif a.phase == 'verify-writer':
        prep = read(a.preparation); r = read(a.result)
        need(prep['operation'] == CAPTION_OP and prep['installed_sha'] == b['installed_sha'] and a.exit_code == 0
             and r.get('ok') is True and r.get('operation') == FEED_OP and r.get('mode') == 'publish_three_static_public_direct_yml_feeds'
             and r.get('db_writes') == r.get('provider_calls') == r.get('message_sends') == 0 and len(r.get('files', [])) == 3, 'writer_unconfirmed_no_retry')
        for expected, actual in zip(prep['files'], r['files']):
            need(all(actual.get(k) == expected[k] for k in ['name', 'sha256', 'bytes', 'rows'])
                 and actual.get('write_action') in ['replace', 'reuse'] and actual.get('public_url') == 'https://app.anytoour.ru/feed/' + expected['name'],
                 'writer_file_changed_no_retry')
        save(out, 'writer-verification.json', {'ok': True, 'operation': CAPTION_OP, 'feed_operation': FEED_OP,
                                             'input_sha256': prep['input_sha256'], 'preparation_sha256': sha(Path(a.preparation).read_bytes()),
                                             'result_sha256': sha(Path(a.result).read_bytes()), 'retry_authorized': False})
    elif a.phase == 'verify-readback':
        result_path = a.readback or a.result
        prep = read(a.preparation); r = read(result_path)
        writer_proof = read(out / 'writer-verification.json')
        need(writer_proof.get('ok') is True and writer_proof.get('operation') == CAPTION_OP and writer_proof.get('feed_operation') == FEED_OP
             and writer_proof.get('input_sha256') == prep['input_sha256'] and writer_proof.get('preparation_sha256') == sha(Path(a.preparation).read_bytes())
             and hash_value(writer_proof.get('result_sha256')) and writer_proof.get('retry_authorized') is False, 'original_writer_not_verified')
        need(a.exit_code == 0 and r.get('ok') is True and r.get('phase') == 'public_readback' and r.get('operation') == CAPTION_OP
             and r.get('feed_operation') == FEED_OP and r.get('installed_sha') == b['installed_sha'] and r.get('input_sha256') == prep['input_sha256']
             and r.get('public_feed_gets') == 3 and r.get('locks_released_before_return') is True and r.get('monitor_journal_unchanged') is True
             and r.get('filesystem_writes') == r.get('provider_calls') == r.get('database_writes') == 0 and len(r.get('files', [])) == 3, 'public_readback_unconfirmed')
        for e, f in zip(prep['files'], r['files']):
            need(f['name'] == e['name'] and f['local_sha256'] == f['public_sha256'] == e['sha256'] and f['bytes'] == e['bytes'] and f['rows'] == e['rows']
                 and f['url'] == 'https://app.anytoour.ru/feed/' + e['name'] and f['http_status'] == 200, 'public_readback_changed')
        save(out, 'terminal.json', dict(prep, state='CONTENT66198_FEED_NATIVE_PUBLIC_VERIFIED', observed_at_utc=r['observed_at_utc'],
                                      writer_result_sha256=writer_proof['result_sha256'], readback_sha256=sha(Path(result_path).read_bytes())))
    print(json.dumps({'ok': True, 'phase': a.phase, 'operation': CAPTION_OP, 'local_outputs': str(out), 'external_writes': 0}))

if __name__ == '__main__':
    main()
