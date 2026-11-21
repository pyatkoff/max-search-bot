import runpy
import unittest

MODULE = runpy.run_path('audit/max2-public-params-20261121/reader.py', run_name='source_fixture')

class ScopedPublicParamsTests(unittest.TestCase):
    def test_bounded_four_requests(self):
        self.assertEqual(set(MODULE['PLAN']), {'all_visits', 'tagged_visits', 'post_values', 'offer_values'})
        for item in MODULE['PLAN'].values():
            self.assertEqual(item['ids'], '98615635')
            self.assertEqual((item['date1'], item['date2']), ('2026-11-21','2026-11-21'))
            self.assertEqual(item['accuracy'],'full')
            self.assertLessEqual(int(item['limit']), 100)
    def test_public_ids_and_rejection(self):
        validate=MODULE['public_value']
        for field,valid in [('post_id','123'),('offer_id','max_123')]:
            self.assertEqual(validate(valid,field),valid)
        for item in ('', '0', '-1', '0002', 'anytour', 'max_0', 'tg_123', 'max_01',
                     '99999999999999999999', ' max_12', 'user@example.org'):
            self.assertIsNone(validate(item, 'offer_id'))
        self.assertIsNone(validate('max_3', 'post_id'))
    def test_only_allowlisted_endpoint_and_queries(self):
        stop=MODULE['Stop']
        validate=MODULE['validate_request']
        for name,params in MODULE['PLAN'].items():
            self.assertEqual(validate('/stat/v1/data',params),name)
        with self.assertRaises(stop):
            validate('/management/v1/counter/98615635/edit',{})
        with self.assertRaises(stop):
            validate('/stat/v1/data',{'ids':'98615635'})
    def test_no_pair_in_report_contract(self):
        self.assertNotIn('post_id,ym:s:paramsLevel3',str(MODULE['PLAN']))
        self.assertTrue(all('ym:s:paramsLevel2' in MODULE['PLAN'][name]['filters'] for name in ('post_values','offer_values')))

if __name__ == '__main__':
    unittest.main()
