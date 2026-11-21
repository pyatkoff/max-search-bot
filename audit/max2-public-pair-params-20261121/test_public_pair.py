"""Synthetic fixtures only: no provider, SSH, database, production key, or Git."""
import base64
import copy
import hashlib
import io
import json
import os
import runpy
import ssl
import tempfile
import unittest
import urllib.error
from pathlib import Path
from unittest import mock

HERE = Path(__file__).resolve().parent

def load_source(name):
    return runpy.run_path(str(HERE / name), run_name='source_fixture')

class PairReaderTests(unittest.TestCase):
    def setUp(self):
        self.m = load_source('reader.py')
        self.g = self.m['collect'].__globals__
        self.m['reset']()
        self.calls = []
        self.bodies = {
            'all_visits': self.body('all_visits', [10, 8, 7, 6, 5, 3, 3, 3, 1230]),
            'tagged_visits': self.body('tagged_visits', [4, 3, 4, 3, 2, 2, 2, 2, 1100]),
            'tagged_max2_visits': self.body('tagged_max2_visits', [3, 2, 4, 3, 2, 1, 1, 1, 300]),
            'pair_values': self.body('pair_values', [4, 3, 4, 3, 2, 2, 2, 2, 1100], [
                self.row('p12__max_34', [2, 2, 2, 2, 2, 1, 1, 1, 100]),
                self.row('p56__max_78', [3, 2, 2, 2, 1, 2, 2, 1, 1000]),
            ]),
        }
        self.goals = [
            {'id': 563541588, 'name': 'max2', 'type': 'url', 'conditions': [{'type': 'contain', 'url': '/max2/'}]},
            {'id': 678187097, 'name': 'modeled', 'type': 'action',
             'conditions': [{'type': 'exact', 'url': 'anytour_modeled_value_v1'}]},
        ]

    def row(self, key, metrics, namespace='max2_public_pair_v1', field='key'):
        return {'dimensions': [{'name': namespace}, {'name': field}, {'name': key}], 'metrics': metrics}

    def body(self, name, metrics, rows=None):
        plan = self.m['PLAN'][name]
        query = {**plan, 'ids': [98615635], 'metrics': list(self.m['METRICS']),
                 'dimensions': plan.get('dimensions', '').split(',') if plan.get('dimensions') else [],
                 'limit': int(plan['limit']), 'offset': 1}
        data = rows if rows is not None else [{'dimensions': [], 'metrics': metrics}]
        return {'query': query, 'totals': metrics, 'data': data, 'total_rows': len(data),
                'total_rows_rounded': False, 'sampled': False, 'sample_share': 1,
                'sample_size': 10, 'sample_space': 10, 'data_lag': 75, 'contains_sensitive_data': False}

    def get(self, path, params=None):
        name = self.m['validate_request'](path, params)
        self.calls.append(name)
        if name == 'counter':
            return {'counter': {'id': 98615635, 'time_zone_name': 'Europe/Moscow', 'time_zone_offset': 10800}}
        if name == 'goal_catalog':
            return {'goals': copy.deepcopy(self.goals)}
        value = self.bodies[name]
        if isinstance(value, Exception):
            raise value
        return copy.deepcopy(value)

    def collect(self):
        return self.m['collect'](self.get)

    def test_actual_collect_six_queries_atomic_pairs_and_nonadditivity(self):
        result = self.collect()
        self.assertEqual(self.calls, ['counter', 'goal_catalog', 'all_visits',
                                      'tagged_visits', 'tagged_max2_visits', 'pair_values'])
        self.assertEqual(result['status'], 'COLLECTED')
        self.assertEqual(result['coverage']['pair_tagged_visits'], 4)
        self.assertEqual(result['coverage']['tagged_share_of_counter_visits'], .4)
        rows = result['groups']['pair_values']['rows']
        self.assertEqual([(r['post_id'], r['offer_id']) for r in rows], [('12', 'max_34'), ('56', 'max_78')])
        self.assertEqual(result['groups']['pair_values']['accepted_row_metric_sums_nonadditive']['ym:s:visits'], 5)
        self.assertEqual(result['scopes']['tagged_max2_visits']['same_visit_overlap']['share'], 1/3)
        self.assertFalse(result['coverage']['per_key_sum_is_unique_visit_denominator'])
        self.assertFalse(result['interpretation']['hotel_price_join'])

    def test_strict_public_pair_boundaries(self):
        parse = self.m['public_pair']
        self.assertEqual(parse('p' + '9'*19 + '__max_' + '8'*19)['post_id'], '9'*19)
        for value in ('p0__max_1', 'p01__max_1', 'p1__max_0', 'p1__max_02',
                      'p'+'9'*20+'__max_1', 'p1__max_'+'9'*20,
                      'p12__max_34\n', 'p１２__max_34', 'p12__max_34?yclid=123',
                      'p12__max_34@example.org', '', None, 12, True):
            with self.subTest(value=value):
                self.assertIsNone(parse(value))

    def test_other_branches_and_invalid_values_are_never_exported(self):
        bad = 'private-person@example.org'
        rows = [self.row('p12__max_34', [1, 1, 1, 1, 1, 0, 0, 0, 0]),
                self.row(bad, [1, 1, 0, 0, 0, 0, 0, 0, 0]),
                self.row('p56__max_78', [1, 1, 0, 0, 0, 0, 0, 0, 0], namespace='unrelated')]
        self.bodies['pair_values'] = self.body('pair_values', [3, 3, 1, 1, 1, 0, 0, 0, 0], rows)
        result = self.collect()
        group = result['groups']['pair_values']
        self.assertEqual(len(group['rows']), 1)
        self.assertEqual(group['rejected']['other_parameter_branch']['rows'], 1)
        self.assertEqual(group['rejected']['undefined_or_invalid_public_key']['rows'], 1)
        encoded = json.dumps(result)
        self.assertNotIn(bad, encoded)
        self.assertNotIn('unrelated', encoded)
        self.assertNotIn('p56__max_78', encoded)

    def test_duplicate_atomic_row_fails_without_partial_money_sum(self):
        first = self.bodies['pair_values']['data'][0]
        self.bodies['pair_values']['data'].append(copy.deepcopy(first))
        self.bodies['pair_values']['total_rows'] = 3
        result = self.collect()
        self.assertEqual(result['status'], 'PARTIAL')
        self.assertNotIn('pair_values', result['groups'])
        self.assertIsNone(result['coverage']['public_keys_returned'])
        self.assertEqual(result['errors'][0]['code'], 'DUPLICATE_PUBLIC_PAIR_ROW')

    def test_quality_sampling_truncation_sensitivity_and_unknown_retained(self):
        body = self.bodies['pair_values']
        body.update(sampled=True, sample_share=.5, total_rows=200,
                    total_rows_rounded=True, contains_sensitive_data=True, data_lag=999)
        result = self.collect()
        q = result['scopes']['pair_values']['quality']
        self.assertFalse(q['complete'])
        self.assertIsNone(q['rows_complete'])
        self.assertEqual(q['total_rows'], 200)
        self.assertTrue(q['total_rows_rounded'])
        self.assertTrue(q['contains_sensitive_data'])
        self.assertEqual(q['data_lag'], 999)
        self.assertEqual(result['status'], 'PARTIAL')
        self.m['reset']()
        body.update(sampled=False, sample_share=1, total_rows_rounded=False, contains_sensitive_data=False)
        result = self.collect()
        self.assertFalse(result['scopes']['pair_values']['quality']['rows_complete'])
        self.m['reset']()
        body.pop('contains_sensitive_data')
        result = self.collect()
        self.assertIsNone(result['scopes']['pair_values']['quality']['contains_sensitive_data'])

    def test_missing_response_is_unknown_and_existing_totals_survive(self):
        self.bodies['pair_values'] = self.m['Stop']('API_READ_REJECTED', 503, ['server_error'])
        result = self.collect()
        self.assertEqual(result['status'], 'PARTIAL')
        self.assertEqual(result['coverage']['pair_tagged_visits'], 4)
        self.assertIsNone(result['coverage']['public_keys_returned'])
        self.assertEqual(result['coverage']['missing_queries'], ['pair_values'])

    def test_wrong_goal_stops_before_reports_unknown_max2_condition_is_partial(self):
        self.goals[0]['conditions'][0]['url'] = '/max20/'
        with self.assertRaises(self.m['Stop']) as error:
            self.collect()
        self.assertEqual(error.exception.info['code'], 'MAX2_GOAL_CONDITIONS_MISMATCH')
        self.assertEqual(self.calls, ['counter', 'goal_catalog'])
        self.m['reset']()
        self.goals[0]['conditions'] = [{'type': 'regexp', 'url': 'SECRET.*/max2/.*'}]
        result = self.collect()
        self.assertEqual(result['status'], 'PARTIAL')
        self.assertNotIn('SECRET', json.dumps(result))
        self.m['reset']()
        self.goals[1]['conditions'][0]['url'] = 'other_action'
        with self.assertRaises(self.m['Stop']) as error:
            self.collect()
        self.assertEqual(error.exception.info['code'], 'MODELED_GOAL_CONDITIONS_MISMATCH')

    def test_malformed_counts_and_provider_scope_are_rejected(self):
        for invalid in (-1, float('nan'), float('inf'), True, '1'):
            with self.subTest(value=invalid):
                with self.assertRaises(self.m['Stop']):
                    self.m['finite_count'](invalid)
        self.bodies['tagged_visits']['query']['timezone'] = '+00:00'
        self.bodies['pair_values']['data'][0]['metrics'][0] = True
        result = self.collect()
        self.assertEqual({e['code'] for e in result['errors']},
                         {'PROVIDER_QUERY_SCOPE_MISMATCH', 'INVALID_COUNT'})
        self.assertIsNone(result['coverage']['pair_tagged_visits'])

    def test_only_exact_management_and_report_plan_are_allowed(self):
        validate = self.m['validate_request']
        self.assertEqual(len(self.m['PLAN']) + 2, self.m['MAX_ATTEMPTS'])
        for name, params in self.m['PLAN'].items():
            self.assertEqual(validate('/stat/v1/data', params), name)
            for changed in ({**params, 'ids': '1'}, {**params, 'dimensions': 'ym:s:clientID'},
                            {**params, 'filters': ''}, {**params, 'date2': '2026-11-22'},
                            {**params, 'metrics': 'ym:s:pageviews'}, {**params, 'limit': '1000'}):
                with self.subTest(name=name, changed=changed):
                    if changed != params:
                        with self.assertRaises(self.m['Stop']):
                            validate('/stat/v1/data', changed)
        for path in ('/stat/v1/data.csv', '/management/v1/counter/98615635/edit', 'https://evil.example/'):
            with self.assertRaises(self.m['Stop']):
                validate(path, {})

    def test_transport_get_only_tls_no_proxy_no_redirect_and_six_attempt_cap(self):
        opener = mock.Mock()
        response = mock.MagicMock()
        response.__enter__.return_value = response
        response.status = 200
        response.geturl.return_value = 'https://api-metrika.yandex.net/management/v1/counter/98615635'
        response.read.return_value = b'{}'
        opener.open.return_value = response
        with mock.patch.object(self.m['urllib'].request, 'build_opener', return_value=opener) as build:
            get = self.m['make_reader']('synthetic_fixture_token')
            handlers = build.call_args.args
            self.assertEqual(handlers[0].proxies, {})
            self.assertIsInstance(handlers[1], self.m['NoRedirect'])
            self.assertIsNone(handlers[1].redirect_request())
            self.assertEqual(handlers[2]._context.verify_mode, ssl.CERT_REQUIRED)
            self.assertTrue(handlers[2]._context.check_hostname)
            for _ in range(6):
                get(self.m['COUNTER_PATH'])
            with self.assertRaises(self.m['Stop']) as error:
                get(self.m['COUNTER_PATH'])
        self.assertEqual(error.exception.info['code'], 'REQUEST_BUDGET')
        self.assertEqual(opener.open.call_count, 6)
        for call in opener.open.call_args_list:
            self.assertEqual(call.args[0].get_method(), 'GET')
            self.assertEqual(call.kwargs['timeout'], 15)

    def test_forbidden_response_no_retry_no_body_and_stops_collection(self):
        error = urllib.error.HTTPError('https://api-metrika.yandex.net', 403, 'Forbidden', {},
                                      io.BytesIO(b'{"errors":[{"code":"access_denied","message":"PRIVATE_BODY"}]}'))
        opener = mock.Mock()
        opener.open.side_effect = error
        with mock.patch.object(self.m['urllib'].request, 'build_opener', return_value=opener):
            get = self.m['make_reader']('synthetic_fixture_token')
            with self.assertRaises(self.m['Stop']) as caught:
                get(self.m['COUNTER_PATH'])
        self.assertEqual(opener.open.call_count, 1)
        self.assertEqual(caught.exception.info['provider_error_codes'], ['access_denied'])
        self.assertNotIn('PRIVATE_BODY', json.dumps(self.g['RESULT']))
        self.m['reset']()
        self.bodies['all_visits'] = self.m['Stop']('API_READ_REJECTED', 403)
        with self.assertRaises(self.m['Stop']):
            self.collect()
        self.assertEqual(self.calls, ['counter', 'goal_catalog', 'all_visits'])

    def test_size_bound_preserves_safety_flags_and_scope(self):
        self.g['RESULT']['oversize_fixture'] = 'x' * (self.m['MAX_RETURN_BYTES'] + 1)
        result = json.loads(self.m['serialized_result']())
        self.assertEqual(result['errors'][0]['code'], 'RETURN_SIZE_BUDGET')
        self.assertEqual(result['namespace'], 'max2_public_pair_v1')
        for key in ('server_writes', 'advertising_writes', 'database_opened', 'token_exported'):
            self.assertIs(result[key], False)

class PairRunnerTests(unittest.TestCase):
    def setUp(self):
        self.m = load_source('runner.py')

    def test_key_must_be_new_valid_pinned_public_key_before_execute(self):
        g = self.m['main'].__globals__
        execute = mock.Mock(side_effect=AssertionError('must_not_execute'))
        with mock.patch.dict(g, {'execute': execute}), mock.patch.dict(os.environ, {}, clear=True):
            with self.assertRaises(self.m['SafeStop']):
                self.m['main']()
        execute.assert_not_called()

    def test_public_key_pin_and_type(self):
        from cryptography.hazmat.primitives.asymmetric import rsa
        from cryptography.hazmat.primitives import serialization
        key = rsa.generate_private_key(public_exponent=65537, key_size=3072)
        public = key.public_key().public_bytes(serialization.Encoding.PEM,
                                              serialization.PublicFormat.SubjectPublicKeyInfo)
        env = {'REPORT_PUBLIC_KEY_B64': base64.b64encode(public).decode(),
               'REPORT_PUBLIC_KEY_SHA256': hashlib.sha256(public).hexdigest()}
        with mock.patch.dict(os.environ, env, clear=True):
            self.assertEqual(self.m['report_public_key']().key_size, 3072)
            os.environ['REPORT_PUBLIC_KEY_SHA256'] = '0'*64
            with self.assertRaises(self.m['SafeStop']):
                self.m['report_public_key']()

    def test_unadmitted_execute_stops_without_any_subprocess(self):
        with tempfile.TemporaryDirectory() as tmp, mock.patch.dict(os.environ, {}, clear=True), \
                mock.patch.object(self.m['subprocess'], 'run') as run:
            with self.assertRaises(self.m['SafeStop']):
                self.m['execute'](Path(tmp))
        run.assert_not_called()

if __name__ == '__main__':
    unittest.main()

