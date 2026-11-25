"""Observe accepted Telegram advertising destinations after a verified production install."""
import argparse
from collections import Counter
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timezone
import hashlib
from http.client import HTTPException, HTTPSConnection
import json
from pathlib import Path
import re
import subprocess
import sys

HOST = 'app.anytoour.ru'
PATH = '/hotel-posts-telegram-main19.php?offer='
SHA256 = r'[0-9a-f]{64}'
SHA1 = r'[0-9a-f]{40}'
ID = r'[1-9][0-9]{0,19}'
LOOKUP = """
require $argv[1];
$xml = file_get_contents($argv[2], false, null, 0, ANYTOUR_TELEGRAM_FEED_LIMIT + 1);
$source = anytourTelegramFeedOfferUrls($xml);
echo json_encode(anytourTelegramFeedDestinationPosts($source), JSON_THROW_ON_ERROR);
"""


def check(ok, message):
    if not ok:
        raise ValueError(message)


def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def catalog(php, xml):
    result = subprocess.run(['php', '-r', LOOKUP, '--', str(php.resolve()), str(xml.resolve())],
                            stdout=subprocess.PIPE, check=True, timeout=30)
    rows = json.loads(result.stdout)
    check(isinstance(rows, dict) and 0 < len(rows) <= 20000, 'invalid resolver inventory')
    return rows


def confirmed_changes(manifests):
    accepted = {}
    stamps = []
    for name, manifest in manifests:
        native = manifest['native']
        public = manifest['public']
        resolver = manifest['resolver']
        for digest in [native['artifact_sha256'], public['report_sha256'],
                       resolver['base_sha256'], resolver['candidate_sha256']]:
            check(isinstance(digest, str) and re.fullmatch(SHA256, digest), 'invalid source digest')
        for commit in [native['repository_sha'], native['application_sha'], public['repository_sha']]:
            check(isinstance(commit, str) and re.fullmatch(SHA1, commit), 'invalid source commit')
        for ident in [native['run_id'], native['artifact_id'], public['run_id']]:
            check(re.fullmatch(ID, str(ident)), 'invalid source run')
        changes = manifest['changes']
        previous = manifest.get('already_configured', [])
        check(isinstance(changes, list) and isinstance(previous, list) and len(changes)+len(previous) <= 2000,
              'invalid candidate size')
        rows = changes + previous
        stamp = {'manifest': name, 'native_run_id': str(native['run_id']),
                 'native_artifact_id': str(native['artifact_id']), 'native_artifact_sha256': native['artifact_sha256'],
                 'public_run_id': str(public['run_id']), 'public_report_sha256': public['report_sha256'],
                 'base_sha256': resolver['base_sha256'], 'candidate_sha256': resolver['candidate_sha256']}
        stamps.append(stamp)
        seen = set()
        for r in rows:
            offer = r['offer_id']
            old, new = r['source_url'], r['post_url']
            check(isinstance(offer, str) and re.fullmatch(r'tg_[1-9][0-9]{0,11}', offer)
                  and str(r['hotel_id']) == offer[3:] and offer not in seen, 'invalid or duplicate candidate offer')
            check(isinstance(old, str) and re.fullmatch(r'https://t\.me/any_toour/[1-9][0-9]{0,11}', old)
                  and isinstance(new, str) and re.fullmatch(r'https://t\.me/anytour/[1-9][0-9]{0,11}', new),
                  'invalid candidate URL')
            check(all(re.fullmatch(ID, str(r[k])) for k in ('publication_id','version_id'))
                  and all(isinstance(r[k], str) and re.fullmatch(SHA256, r[k]) for k in ('plan_sha256','html_sha256')),
                  'invalid accepted receipt')
            seen.add(offer)
            accepted.setdefault((offer,old,new), []).append((name,r))
    return accepted, stamps


def select(before, after, manifests, before_hash, after_hash):
    accepted, stamps = confirmed_changes(manifests)
    for stamp in stamps:
        stamp['exact_resolver_pair'] = (before_hash, after_hash) == (stamp['base_sha256'], stamp['candidate_sha256'])
    exact = {s['manifest'] for s in stamps if s['exact_resolver_pair']}
    selected, unbound, pending = select_public_delta(before, after, accepted, exact)
    return selected, unbound, stamps, pending


def select_public_delta(before, after, accepted, exact):
    target_counts = Counter(after.values())
    selected, unbound = [], []
    for offer, new in after.items():
        old = before.get(offer)
        if old == new or not isinstance(old, str) or not old.startswith('https://t.me/any_toour/') or not new.startswith('https://t.me/anytour/'):
            continue
        matches = accepted.get((offer,old,new), [])
        if target_counts[new] > 1:
            unbound.append({'offer_id':offer,'reason':'target_shared_by_multiple_offers'})
            continue
        authoritative = [pair for pair in matches if pair[0] in exact] or matches
        proof_ids = {(str(row['publication_id']), str(row['version_id'])) for _,row in authoritative}
        if len(proof_ids) != 1:
            unbound.append({'offer_id':offer,'reason':'conflicting_receipts' if matches else 'no_matching_receipt'})
            continue
        _, row = authoritative[0]
        selected.append({k:row[k] for k in ('offer_id','source_url','post_url','publication_id','version_id')}
                        | {'manifests': sorted({n for n,_ in authoritative})})
    pending = sorted({offer for offer,old,new in accepted if after.get(offer) == old})
    return selected, unbound, pending


def coverage(rows, unbound, failures):
    if unbound or failures:
        return 'partial', 1
    return ('verified', 0) if rows else ('no_small_to_large_changes', 0)


def get_ad(row):
    offer = row['offer_id']
    conn = HTTPSConnection(HOST, timeout=8)
    observed = {'offer_id':offer, 'url':'https://'+HOST+PATH+offer, 'expected':row['post_url'], 'ok':False}
    try:
        conn.request('GET', PATH+offer, headers={'Cache-Control':'no-cache, max-age=0','Pragma':'no-cache','Accept':'application/json'})
        reply = conn.getresponse()
        observed.update(status=reply.status, cache_control=reply.getheader('Cache-Control'), age=reply.getheader('Age'),
                        date=reply.getheader('Date'))
        if reply.status != 200:
            return observed | {'reason':'unexpected_status'}
        raw = reply.read(4097)
        if len(raw) > 4096:
            return observed | {'reason':'response_too_large'}
        data = json.loads(raw)
        if not isinstance(data, dict):
            return observed | {'reason':'invalid_body'}
        observed['observed_offer'] = str(data.get('offer_id',''))[:40]
        observed['observed_destination'] = str(data.get('post_url',''))[:180]
        if data.get('offer_id') != offer or data.get('post_url') != row['post_url']:
            return observed | {'reason':'unexpected_destination'}
        return observed | {'ok':True}
    except (HTTPException, OSError, ValueError) as exc:
        return observed | {'reason':type(exc).__name__}
    finally:
        conn.close()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    for name in ('before','after','feed','manifests','output'):
        parser.add_argument('--'+name, type=Path, required=True)
    parser.add_argument('--deploy-run-id', required=True)
    parser.add_argument('--installed-sha', required=True)
    args = parser.parse_args()
    check(re.fullmatch(ID,args.deploy_run_id) and re.fullmatch(SHA1,args.installed_sha), 'invalid deploy identity')
    check(args.manifests.is_dir(), 'manifest directory missing')
    paths = sorted(args.manifests.glob('*.json'))
    check(len(paths) <= 256, 'manifest limit exceeded')
    manifests = []
    for path in paths:
        check(path.is_file() and not path.is_symlink() and path.stat().st_size <= 2000000, 'invalid manifest file')
        manifests.append((path.name, json.loads(path.read_bytes())))
    before_hash, after_hash = sha(args.before), sha(args.after)
    before = catalog(args.before,args.feed)
    after = catalog(args.after,args.feed)
    rows, unbound, inputs, pending = select(before,after,manifests,before_hash,after_hash)
    with ThreadPoolExecutor(max_workers=6) as worker:
        observations = list(worker.map(get_ad, rows))
    failures = [r['offer_id'] for r in observations if not r['ok']]
    state, code = coverage(rows,unbound,failures)
    report = {'coverage':state, 'deploy_run_id':args.deploy_run_id, 'installed_sha':args.installed_sha,
              'before_resolver_sha256':before_hash, 'after_resolver_sha256':after_hash,
              'feed_sha256':sha(args.feed), 'manifests':inputs, 'checked_at_utc':datetime.now(timezone.utc).isoformat(),
              'selected_count':len(rows),'verified_count':len(rows)-len(failures),'accepted_changes':rows,
              'unbound_changes':unbound,'pending_offers':pending,'observed':observations,'failed_offers':failures}
    args.output.write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
    print(f'{state}: {len(rows)-len(failures)} verified, {len(failures)} public reads failed, '
          f'{len(unbound)} deployed changes require evidence')
    return code


if __name__ == '__main__':
    sys.exit(main())
