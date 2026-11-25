import copy
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'tools'))
import verify_installed_telegram_routes as verify


def change(n,m=None):
    m=m or n
    return dict(offer_id=f'tg_{n}',hotel_id=n,source_url=f'https://t.me/any_toour/{n}',
                post_url=f'https://t.me/anytour/{m}',publication_id=n,version_id=n+10000,
                plan_sha256='d'*64,html_sha256='e'*64)


def manifest(rows, before='1'*64, after='2'*64):
    return {'native':{'run_id':'50','repository_sha':'c'*40,'application_sha':'b'*40,
            'artifact_id':'20','artifact_sha256':'d'*64},
            'public':{'run_id':'51','repository_sha':'b'*40,'report_sha256':'a'*64},
            'resolver':{'base_sha256':before,'candidate_sha256':after},'changes':rows}


class Selection(unittest.TestCase):
    def test_rebase_partial_replay_and_exact_duplicate(self):
        a,b,c,d=[change(i) for i in [11,12,13,14]]
        before={r['offer_id']:r['source_url'] for r in [a,b,c,d]}
        before[c['offer_id']]=c['post_url']
        after=before|{r['offer_id']:r['post_url'] for r in [a,d]}
        inp=manifest([a,b,c],before='3'*64,after='4'*64)
        selected,unbound,stamps,pending=verify.select(before,after,[('old',inp),('again',inp)],'1'*64,'2'*64)
        self.assertEqual([('tg_11',['again','old'])],[(r['offer_id'],r['manifests']) for r in selected])
        self.assertEqual(unbound,[{'offer_id':'tg_14','reason':'no_matching_receipt'}])
        self.assertEqual(pending,['tg_12'])
        self.assertFalse(any(s['exact_resolver_pair'] for s in stamps))

    def test_conflict_and_later_exact(self):
        a=change(11); old=a['source_url']; new=a['post_url']
        original=manifest([a],before='3'*64,after='4'*64)
        wrong=copy.deepcopy(original);wrong['changes'][0]['version_id']+=2
        def select(d):
            return verify.select({'tg_11':old},{'tg_11':new},d,'1'*64,'2'*64)
        self.assertEqual(select([('a',original),('b',wrong)])[1][0]['reason'],'conflicting_receipts')
        good=select([('a',manifest([a])),('b',wrong)])
        self.assertEqual(good[0][0]['manifests'],['a'])
        self.assertEqual(good[0][0]['version_id'],a['version_id'])
        self.assertTrue(good[2][0]['exact_resolver_pair'])

    def test_source_validation_and_target_collision(self):
        a=change(11);b=change(12,11)
        before={r['offer_id']:r['source_url'] for r in [a,b]}
        after={r['offer_id']:r['post_url'] for r in [a,b]}
        self.assertEqual(verify.select(before,after,[('x',manifest([a,b]))],'1'*64,'2'*64)[0],[])
        for row in [a|{'post_url':'https://othersite.invalid/123'},a|{'hotel_id':12},a|{'source_url':a['post_url']}]:
            with self.assertRaises(ValueError):
                verify.confirmed_changes([('x',manifest([row]))])
        with self.assertRaises(ValueError):
            verify.confirmed_changes([('x',manifest([a,a]))])
        self.assertFalse(verify.select(before,before,[('x',manifest([a]))],'1'*64,'2'*64)[0])

    def test_historical_public_input_recovered_against_newly_installed_resolver(self):
        row = change(11)
        archived = manifest([])
        archived["already_configured"] = [row]
        before = {row["offer_id"]:row["source_url"]}
        after = {row["offer_id"]:row["post_url"]}
        self.assertEqual(verify.select(before, after, [("recovered", archived)], "1"*64, "2"*64)[0][0]["offer_id"], "tg_11")
        self.assertEqual(verify.select(after, after, [("recovered", archived)], "1"*64, "2"*64)[0], [])

    def test_original_eleven_receipt_and_real_release_destinations(self):
        root = Path(__file__).parent / "fixtures" / "ranked-route-45372056681"
        readback = root / "public-readback.json"
        advertising = root / "advertising-readback.json"
        self.assertEqual(verify.sha(readback), "89e531dc0acf0395d03c7adbb9200e401cf0721b5f7e224d78fc287eb2b5deec")
        self.assertEqual(verify.sha(advertising), "e926c9545d7374a218d5d376928478768366c29ea61deca77e32ba1b3a0d3da9")
        report = json.loads(readback.read_bytes())
        self.assertEqual(str(report['run_id']), '45372056681')
        block = next(iter(report['plans'].values()))
        approvals = {}
        for row in block['verified_destinations']:
            offer = 'tg_' + str(row['hotel_id'])
            key = (offer, row['source_url'], row['post_url'])
            approvals[key] = [('historical-public-readback', {'offer_id':offer, **row})]
        # The independent public observer on that date had no GitHub workflow-run identity.
        # Exercise the production delta comparison with the original observed rows.
        observed = json.loads(advertising.read_bytes())['results']
        before = {r['offer_id']:r['before']['post_url'] for r in observed}
        after = {r['offer_id']:r['observed']['post_url'] for r in observed}
        selected, unbound, pending = verify.select_public_delta(before, after, approvals, set())
        self.assertEqual({r['offer_id'] for r in selected}, {k[0] for k in approvals})
        self.assertEqual(len(selected), 11)
        self.assertEqual(unbound, [{'offer_id':'tg_466','reason':'no_matching_receipt'}])
        self.assertEqual(pending, [])
        self.assertEqual(verify.select_public_delta(after, after, approvals, set()), ([], [], []))

    def test_coverage_marks_missing_proof_and_zero_separately(self):
        self.assertEqual(verify.coverage([],[],[]), ('no_small_to_large_changes',0))
        self.assertEqual(verify.coverage([],[{}],[]), ('partial',1))
        self.assertEqual(verify.coverage([{}],[{}],[]), ('partial',1))
        self.assertEqual(verify.coverage([{}],[],['tg_1']), ('partial',1))
        self.assertEqual(verify.coverage([{}],[],[]), ('verified',0))

    def test_public_get_header_body_and_redirect(self):
        row=change(11)
        with patch.object(verify,'HTTPSConnection') as ctor:
            conn=ctor.return_value; reply=conn.getresponse.return_value
            reply.status=302; reply.getheader.return_value='no-cache'
            self.assertFalse(verify.get_ad(row)['ok'])
            self.assertEqual(conn.request.call_count,1)
            conn.request.assert_called_with('GET',verify.PATH+'tg_11',headers={'Cache-Control':'no-cache, max-age=0',
                'Pragma':'no-cache','Accept':'application/json'})
            ctor.assert_called_once_with('app.anytoour.ru',timeout=8)
            reply.status=200;reply.read.return_value=json.dumps({'post_url':row['post_url'],'offer_id':'tg_12'}).encode()
            self.assertFalse(verify.get_ad(row)['ok'])
            reply.read.return_value=json.dumps({'post_url':row['post_url'],'offer_id':'tg_11'}).encode()
            result=verify.get_ad(row)
            self.assertTrue(result['ok']);self.assertEqual(result['cache_control'],'no-cache')
            reply.read.return_value=b'x'*4097; self.assertFalse(verify.get_ad(row)['ok'])


if __name__=='__main__':
    unittest.main()
