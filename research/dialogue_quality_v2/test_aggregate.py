import unittest
from aggregate import summarize

NOW=1800000000
def row(anchor,days=1,platform='max',features=None,**kw):
    return dict(anonymous_request_key=anchor,platform=platform,
        early_observed_unix=NOW-days*86400, snapshot_complete=True,
        snapshot_as_of_unix=NOW,early_features=features or {},**kw)

class AggregateTests(unittest.TestCase):
    def test_windows(self):
        r=summarize([row('a',1,features={'meaningful_interest':True}),row('b',15)],NOW)
        self.assertEqual(r['7d_max']['records'],1)
        self.assertEqual(r['30d_max']['records'],2)
        self.assertEqual(r['7d_max']['stage_counts']['D1'],1)
    def test_dedupe(self):
        self.assertEqual(summarize([row('a'),row('a')],NOW)['7d_max']['records'],1)
    def test_platform(self):
        self.assertEqual(summarize([row('a',platform='telegram')],NOW)['7d_max']['records'],0)
    def test_incomplete_refuses_totals(self):
        r=summarize([row('a'),dict(snapshot_complete=False)],NOW)
        self.assertIsNone(r['7d_max']['records'])
    def test_snapshot_mismatch(self):
        r=summarize([row('a',snapshot_as_of_unix=NOW-1)],NOW)
        self.assertFalse(r['30d_max']['complete'])
    def test_unknown_features(self):
        r=summarize([row('a',features={'not_an_evidence_field':True})],NOW)
        self.assertEqual(r['7d_max']['stage_counts']['unknown'],1)

if __name__=='__main__': unittest.main()
