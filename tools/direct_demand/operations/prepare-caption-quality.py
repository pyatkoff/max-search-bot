#!/usr/bin/env python3
"""Bounded content revision through the existing editor; no retries or new posts."""
import argparse
import base64
import gzip
import hashlib
import json
import os
import re
from pathlib import Path

COHORT_SHA = 'ef5e14d2aba7f4b412acfcfd71967ae986d2876d7e3ba14361119cff62b815fd'
SOURCE = '160e019701165cd53dd5a9be0fa3db533d82120e'
PREFIX = 'commercial-content-quality-20261108-cohort159'
SCOPE_SHA = 'd1c11d29609483213cd8988aeb398fca48c9d78b8a3588297066cf4926398724'
READER_SHA = '16b2f660709bda1995fa4edbf90e67651338d54d25244e1b54cdabf36ec2aaea'

def sha(raw):
    return hashlib.sha256(raw).hexdigest()

def encoded(value):
    return (json.dumps(value, ensure_ascii=False, indent=2) + '\n').encode()

def save(path, value):
    path.write_bytes(encoded(value))

def require(ok, reason):
    if not ok:
        raise RuntimeError(reason)

def load_cohort(ops):
    raw = gzip.decompress((ops/'commercial-content-quality-20261108-inputs.json.gz').read_bytes())
    require(sha(raw) == COHORT_SHA, 'reviewed_cohort_changed')
    c = json.loads(raw)
    require(c['operation_prefix'] == PREFIX and c['expected_installed_sha'] == SOURCE, 'cohort_identity_changed')
    require(len(c['rows']) == 318 and len(c['unchanged_baseline']) == 916, 'cohort_count_changed')
    require(len({r['publication_id'] for r in c['rows']}) == 318, 'duplicate_target')
    require(len({r['hotel_id'] for r in c['rows']}) == 159, 'hotel_count_changed')
    baseline = {r['publication_id']:r for r in c['unchanged_baseline']}
    require(len(baseline) == 916, 'duplicate_baseline')
    for row in c['rows']:
        old = baseline[row['publication_id']]
        for key in ['hotel_id','platform','publication_id','version_id','message_id','post_url','photo_signature',
                    'attachments_sha256','button_url_sha256','config_version','source_key','button_observed_in_snapshot']:
            require(row[key] == old[key], 'target_baseline_binding_changed')
        require(row['old_text'] == old['caption'] and row['old_text_sha256'] == old['caption_sha256'], 'old_text_changed')
        require(sha(row['new_text'].encode()) == row['new_text_sha256'], 'new_text_changed')
    return c

def batch_name(n):
    return 'canary' if n == 0 else f'batch{n:03d}'

def manifests(c):
    chunks = [c['rows'][:8]] + [c['rows'][i:i+50] for i in range(8,318,50)]
    require(len(chunks) == 8 and set(r['hotel_id'] for r in chunks[0]) == {1352,1074,462,1659}, 'canary_changed')
    previous = None
    out = []
    for n, rows in enumerate(chunks):
        name = batch_name(n)
        m = {k:c[k] for k in ['schema_version','expected_installed_sha','update_kind','snapshot','button_text','throttle_microseconds']}
        m.update(operation=f'{PREFIX}-{name}-v1', row_count=len(rows), rows=rows,
                 quality_cohort_sha256=COHORT_SHA,
                 quality_authorization='user_20261108_accepted_content_quality_followup',
                 blocked_operation_not_resumed='commercial-hotel-caption-review6706115450-20261107-1-v1',
                 batch_index=n, batch_kind='canary' if n == 0 else 'batch', predecessor=previous)
        raw = encoded(m)
        out.append((m,raw,sha(raw)))
        previous = {'operation':m['operation'],'manifest_sha256':sha(raw)}
    return out

def php(template, definitions):
    require(template.count('declare(strict_types=1);') == 1, 'executor_header_changed')
    defs = '\n'.join("define('%s', '%s');" % (k,v) for k,v in definitions.items())
    require(all(re.fullmatch(r'[A-Za-z0-9+/=_.-]+',v) for v in definitions.values()), 'unsafe_compiled_constant')
    return template.replace('declare(strict_types=1);', 'declare(strict_types=1);\n'+defs,1)

def prepare(ops, private, safe):
    c = load_cohort(ops)
    packet = private/'packet'
    packet.mkdir(mode=0o700, parents=True, exist_ok=False)
    safe.mkdir(mode=0o700, parents=True, exist_ok=True)
    template = (ops/'title-only-executor-template.php').read_text()
    index = []
    for n,(m,raw,digest) in enumerate(manifests(c)):
        name = batch_name(n)
        (packet/f'{name}.manifest.json').write_bytes(raw)
        compiled = php(template, {'EXPECTED_INSTALLED_SHA':SOURCE,'APPROVED_UPDATE_OPERATION':m['operation'],
                                 'APPROVED_MANIFEST_SHA256':digest,'MANIFEST_BASE64':base64.b64encode(raw).decode()})
        (private/f'{name}.php').write_text(compiled)
        index.append({'index':n,'name':name,'operation':m['operation'],'manifest_sha256':digest,
                      'post_count':len(m['rows']),'hotel_ids':sorted({r['hotel_id'] for r in m['rows']})})
    reader = (ops/'read-short-heading-scope-20261108.php').read_bytes()
    scope = (ops/'short-heading-scope-20261107.json').read_bytes()
    require(sha(reader) == READER_SHA and sha(scope) == SCOPE_SHA, 'existing_reader_or_scope_changed')
    (private/'final-reader.php').write_text(php(reader.decode(), {'EXPECTED_INSTALLED_SHA':SOURCE,
        'APPROVED_MANIFEST_SHA256':SCOPE_SHA,'MANIFEST_BASE64':base64.b64encode(scope).decode()}))
    summary = {'schema_version':1,'cohort_sha256':COHORT_SHA,'post_count':318,'hotel_count':159,
               'batch_count':8,'expected_installed_sha':SOURCE,'reason_counts_by_hotel':c['reason_counts_by_hotel'],
               'new_posts_authorized':0,'batches':index}
    save(packet/'index.json',summary)
    save(safe/'prepared.safe.json',summary)
    print(json.dumps({'prepared_posts':318,'hotels':159,'batches':8,'provider_calls':0}))

def start(ops, private, safe, n):
    c = load_cohort(ops)
    m,raw,digest = manifests(c)[n]
    require((private/'packet'/f'{batch_name(n)}.manifest.json').read_bytes() == raw, 'packet_manifest_changed')
    marker = safe/f'{batch_name(n)}.started.safe.json'
    with marker.open('x') as f:
        f.write(encoded({'operation':m['operation'],'manifest_sha256':digest,'posts':len(m['rows']),
                        'run_id':os.environ.get('GITHUB_RUN_ID'),'run_attempt':os.environ.get('GITHUB_RUN_ATTEMPT')}).decode())

RECEIPT_KEYS = ['hotel_id','platform','publication_id','previous_version_id','version_id','message_id','post_url',
                'text_sha256','previous_text_sha256','photo_signature','previous_attachments_sha256','attachments_sha256',
                'button_url_sha256','button_text','source_key','state','body_photo_button_verified','formatting_verified',
                'existing_message_reused','existing_token_resolves_to_current_version']

def result(ops, private, safe, n, ssh_status):
    c = load_cohort(ops)
    m,_,digest = manifests(c)[n]
    raw = (private/f'{batch_name(n)}.runtime.jsonl').read_bytes()
    out = {'operation':m['operation'],'manifest_sha256':digest,'expected_posts':len(m['rows']),
           'ssh_status':ssh_status,'raw_output_sha256':sha(raw),'validated':False,'posts':[],
           'state':'DISPATCHED_OUTCOME_REQUIRES_RECONCILIATION_NO_REPLAY'}
    try:
        require(0 < len(raw) <= 4_000_000 and raw.endswith(b'\n'), 'runtime_output_missing_or_incomplete')
        lines = [json.loads(line) for line in raw.splitlines() if line.strip()]
        r = lines[-1]
        require(isinstance(r,dict) and r.get('operation') == m['operation'], 'runtime_identity_changed')
        out['runtime_reported_ok'] = r.get('ok') is True
        out['reported_post_write_attempts'] = r.get('post_write_attempts')
        out['reported_caption_updates'] = r.get('caption_updates')
        out['error'] = r.get('error') if re.fullmatch(r'[a-z][a-z0-9_]{1,100}',str(r.get('error',''))) else None
        targets = {row['publication_id']:row for row in m['rows']}
        seen = set()
        for p in r.get('posts',[]):
            require(p.get('publication_id') in targets and p['publication_id'] not in seen, 'unexpected_or_duplicate_receipt')
            seen.add(p['publication_id'])
            row = targets[p['publication_id']]
            for k in ['hotel_id','platform','publication_id','message_id','post_url','photo_signature','button_url_sha256','source_key']:
                require(p.get(k) == row[k], 'receipt_identity_changed')
            require(p.get('previous_version_id') == row['version_id'] and isinstance(p.get('version_id'),int)
                    and p['version_id'] != row['version_id'], 'receipt_version_changed')
            require(p.get('text_sha256') == row['new_text_sha256'] and p.get('previous_text_sha256') == row['old_text_sha256']
                    and p.get('previous_attachments_sha256') == row['attachments_sha256'], 'receipt_content_changed')
            require(p.get('state') == 'sent' and p.get('button_text') == c['button_text'], 'receipt_not_sent')
            require(all(p.get(k) is True for k in ['body_photo_button_verified','formatting_verified',
                         'existing_message_reused','existing_token_resolves_to_current_version']), 'receipt_unverified')
            require(re.fullmatch(r'[a-f0-9]{64}',p.get('attachments_sha256','')) is not None, 'receipt_photo_hash_missing')
            out['posts'].append({k:p[k] for k in RECEIPT_KEYS})
        require(ssh_status == 0 and r.get('ok') is True, 'runtime_not_successful')
        require(r.get('manifest_sha256') == digest and r.get('installed_sha') == SOURCE
                and r.get('mode') == 'approved_existing_caption_quality_revision', 'runtime_manifest_changed')
        require(len(out['posts']) == len(m['rows']) == r.get('post_write_attempts') == r.get('caption_updates')
                == r.get('preflight_existing_messages'), 'runtime_count_changed')
        require(all(r.get(k) == 0 for k in ['new_posts_created','configuration_write_attempts','button_write_attempts',
                                          'tour_search_calls','price_queries']), 'outside_scope_write')
        out.update(validated=True,state='ALL_EXISTING_CAPTION_EDITS_CONFIRMED',confirmed_posts=len(out['posts']))
    except Exception as e:
        out['verification_error'] = str(e) if re.fullmatch(r'[a-z][a-z0-9_]{1,100}',str(e)) else 'invalid_runtime_result'
    save(safe/f'{batch_name(n)}.result.safe.json',out)
    print(json.dumps({k:out.get(k) for k in ['operation','validated','confirmed_posts','state','error','verification_error']}))
    require(out['validated'], 'batch_failed_no_retry')

def receipts(safe):
    posts = {}
    for n in range(8):
        r = json.loads((safe/f'{batch_name(n)}.result.safe.json').read_text())
        require(r['validated'] is True, 'previous_batch_not_confirmed')
        for p in r['posts']:
            require(p['publication_id'] not in posts, 'duplicate_confirmed_post')
            posts[p['publication_id']] = p
    require(len(posts) == 318, 'final_receipts_incomplete')
    return posts

def verify_final(ops, private, safe, ssh_status):
    c = load_cohort(ops)
    confirmed = receipts(safe)
    raw = (private/'final-snapshot.runtime.json').read_bytes()
    require(ssh_status == 0 and 0 < len(raw) < 6_000_000, 'final_read_failed')
    r = json.loads(raw)
    require(r['ok'] is True and r['installed_sha'] == SOURCE and len(r['posts']) == 916
            and r['post_write_attempts'] == 0 and r['provider_calls'] == 0, 'final_read_scope_changed')
    old = {p['publication_id']:p for p in c['unchanged_baseline']}
    changes = {p['publication_id']:p for p in c['rows']}
    seen = set()
    for p in r['posts']:
        pid = p['publication_id']
        require(pid in old and pid not in seen, 'final_identity_duplicate_or_unknown')
        seen.add(pid)
        baseline = old[pid]
        for k in ['hotel_id','platform','publication_id','message_id','post_url','source_key','config_version','photo_signature','button_url_sha256']:
            require(p[k] == baseline[k], 'final_identity_photo_button_changed')
        require(p['status'] == 'sent' and p['button_observed_in_snapshot'] is True
                and p['button_resolves_to_current_post'] is True and p['media_count'] == 1, 'final_post_unconfirmed')
        expected = changes[pid]['new_text'] if pid in changes else baseline['caption']
        version = confirmed[pid]['version_id'] if pid in changes else baseline['version_id']
        attachments = confirmed[pid]['attachments_sha256'] if pid in changes else baseline['attachments_sha256']
        require(p['caption'] == expected and p['caption_sha256'] == sha(expected.encode())
                and p['version_id'] == version and p['attachments_sha256'] == attachments, 'final_current_version_changed')
        require('с перел' not in p['caption'].splitlines()[0], 'heading_regressed')
    require(seen == set(old), 'final_scope_incomplete')
    # Public captions, immutable IDs and hashes only; no start tokens or customer data.
    (safe/'final-snapshot.safe.json').write_bytes(raw)
    summary = {'ok':True,'observed_at_utc':r['observed_at_utc'],'installed_sha':SOURCE,'cohort_sha256':COHORT_SHA,
               'verified_existing_posts':916,'revised_posts':318,'revised_hotels':159,'unchanged_posts':598,
               'confirmed_current_versions':916,'short_headings':916,'existing_button_resolution_verified':916,
               'new_posts_created':0,'final_snapshot_sha256':sha(raw),'reason_counts_by_hotel':c['reason_counts_by_hotel']}
    save(safe/'final-verification.safe.json',summary)
    print(json.dumps(summary,ensure_ascii=False))

def summarize(safe):
    confirmed = 0
    unresolved = []
    unstarted = []
    for n in range(8):
        name = batch_name(n)
        file = safe/f'{name}.result.safe.json'
        if file.is_file():
            r = json.loads(file.read_text())
            confirmed += len(r.get('posts',[]))
            if r.get('validated') is not True:
                unresolved.append(name)
        elif (safe/f'{name}.started.safe.json').exists():
            unresolved.append(name)
        else:
            unstarted.append(name)
    final = safe/'final-verification.safe.json'
    s = {'confirmed_caption_edits':confirmed,'planned_caption_edits':318,'unresolved_batches':unresolved,
         'unstarted_batches':unstarted,'final_read_verified':final.exists(),
         'complete':confirmed == 318 and not unresolved and not unstarted and final.exists(),
         'retry_authorized':False,'new_publications_authorized':0}
    safe.mkdir(parents=True,exist_ok=True)
    save(safe/'summary.safe.json',s)
    print(json.dumps(s))

def main():
    p = argparse.ArgumentParser()
    p.add_argument('command',choices=['prepare','start','result','verify-final','summarize'])
    p.add_argument('--ops-dir',type=Path,default=Path(__file__).resolve().parent)
    p.add_argument('--private-dir',type=Path,required=True)
    p.add_argument('--safe-dir',type=Path,required=True)
    p.add_argument('--batch',type=int)
    p.add_argument('--ssh-status',type=int,default=0)
    a = p.parse_args()
    if a.command == 'prepare':prepare(a.ops_dir,a.private_dir,a.safe_dir)
    elif a.command == 'start':start(a.ops_dir,a.private_dir,a.safe_dir,a.batch)
    elif a.command == 'result':result(a.ops_dir,a.private_dir,a.safe_dir,a.batch,a.ssh_status)
    elif a.command == 'verify-final':verify_final(a.ops_dir,a.private_dir,a.safe_dir,a.ssh_status)
    else:summarize(a.safe_dir)

if __name__ == '__main__':main()
