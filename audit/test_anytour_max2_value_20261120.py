"""Offline checks execute the real collector and guarded transport with mocks."""
import copy
import importlib.util
import io
import json
from pathlib import Path
import ssl
import sys
import unittest
from unittest.mock import patch
import urllib.error
import urllib.request

sys.dont_write_bytecode = True
SOURCE = Path(__file__).with_name('anytour-max2-value-20261120-reader.py')
SPEC = importlib.util.spec_from_file_location('max2_reader_under_test', SOURCE)
r = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(r)


def body(params, totals, rows=None, **quality):
    dims = params.get('dimensions', '').split(',') if params.get('dimensions') else []
    if rows is None:
        rows = [{'dimensions': [], 'metrics': totals}]
    return {
        'query': {'ids': [r.COUNTER], 'date1': r.DATE1, 'date2': r.DATE2,
                  'metrics': params['metrics'].split(','), 'dimensions': dims,
                  'filters': params.get('filters', '')},
        'totals': totals, 'data': rows, 'total_rows': len(rows), 'sampled': False,
        'sample_share': 1, 'sample_size': 100, 'sample_space': 100, 'data_lag': 60,
        **quality,
    }


class Provider:
    def __init__(self):
        self.calls = []
        self.goals = [
            {'id': r.MAX2_GOAL, 'name': 'max2', 'type': 'url',
             'conditions': [{'type': 'contain', 'url': 'max2'}]},
            {'id': r.MODELED_GOAL, 'name': 'Modeled value', 'type': 'action',
             'conditions': [{'type': 'exact', 'url': r.MODELED_TARGET}]},
        ]
        self.transforms = {}
        self.failures = {}
        self.engines = [
            {'dimensions': [{'id': 'ya_direct', 'name': 'Yandex Direct'}], 'metrics': [60]},
            {'dimensions': [{'id': 'ya_undefined', 'name': 'Yandex undefined'}], 'metrics': [40]},
        ]
        self.values = {
            'all_traffic': [100, 80, 50, 40, 35, 12, 8, 7, 2000],
            'all_traffic_max2_visits': [40, 35, 50, 40, 35, 10, 6, 5, 1400],
            'yandex_direct_last_significant': [60, 50, 36, 30, 26, 9, 7, 6, 1200],
            'yandex_direct_last_significant_max2_visits': [30, 26, 36, 30, 26, 7, 5, 4, 900],
        }

    def __call__(self, path, params=None):
        name = r.validate_request(path, params)
        self.calls.append((path, copy.deepcopy(params), name))
        if name in self.failures:
            raise self.failures[name]
        if name == 'counter':
            return {'counter': {'id': r.COUNTER, 'name': 'AnyTour', 'time_zone_name': 'Europe/Moscow',
                                'time_zone_offset': 10800, 'status': 'Active'}}
        if name == 'goal_catalog':
            return {'goals': copy.deepcopy(self.goals)}
        if name == 'engine_discovery':
            result = body(params, [100], copy.deepcopy(self.engines))
        elif name in self.values:
            result = body(params, self.values[name].copy())
        elif name == 'max2_direct_start_url_profile':
            urls = [
                'https://tour-max.ru/new/max2/?post_id=SECRET_POST&offer_id=SECRET_OFFER'
                '&hotel_id=SECRET_HOTEL&startapp=SECRET_CLICK_ctx1_SECRET_CONTEXT'
                '&yclid=SECRET_YCLID&client_id=SECRET_CLIENT&user=SECRET_USER'
                '&signature=SECRET_SIGNATURE#SECRET_FRAGMENT',
                'https://SECRET_USER:SECRET_PASSWORD@tour-max.ru/new/max2/?utm_campaign=SECRET_CAMPAIGN',
            ]
            rows = [{'dimensions': [{'name': url}], 'metrics': [count]}
                    for url, count in zip(urls, (10, 20))]
            result = body(params, [30], rows)
        else:
            metrics = [[10, 9, 12, 10, 9, 2, 2, 2, 300],
                       [20, 18, 24, 20, 18, 5, 3, 3, 600]]
            dimensions = ([{'name': '2026-11-12'}, {'name': '2026-11-18'}]
                          if name == 'max2_direct_by_date'
                          else [{'id': '11', 'name': 'SECRET_CAMPAIGN_NAME'},
                                {'id': '12', 'name': 'OTHER_CAMPAIGN'}])
            rows = [{'dimensions': [dim], 'metrics': values}
                    for dim, values in zip(dimensions, metrics)]
            result = body(params, self.values['yandex_direct_last_significant_max2_visits'].copy(), rows)
        if name in self.transforms:
            self.transforms[name](result)
        return result


class Response:
    status = 200
    headers = {}

    def __init__(self, request, payload):
        self.request = request
        self.payload = payload

    def __enter__(self):
        return self

    def __exit__(self, *_):
        return False

    def geturl(self):
        return self.request.full_url

    def read(self, bound):
        return self.payload[:bound]


class ReaderTests(unittest.TestCase):
    def setUp(self):
        r.reset()
        self.provider = Provider()

    def test_real_collect_mapping_segments_and_no_raw_identifiers(self):
        with patch('builtins.open', side_effect=AssertionError('file I/O')), \
             patch.object(r.subprocess, 'run', side_effect=AssertionError('subprocess')), \
             patch.object(urllib.request.OpenerDirector, 'open', side_effect=AssertionError('network')):
            result = r.collect(self.provider)
        self.assertEqual(result['status'], 'COLLECTED')
        self.assertEqual([c[2] for c in self.provider.calls][:3],
                         ['counter', 'goal_catalog', 'engine_discovery'])
        self.assertEqual(len(self.provider.calls), 10)
        self.assertFalse(result['server_writes'])
        self.assertFalse(result['advertising_writes'])
        self.assertFalse(result['campaign_optimization_read'])
        selected = result['scopes']['yandex_direct_last_significant_max2_visits']
        self.assertEqual(selected['visits'], 30)
        self.assertEqual(selected['goals'][str(r.MAX2_GOAL)], {'reaches': 36, 'visits': 30, 'users': 26})
        self.assertEqual(selected['goals'][str(r.MODELED_GOAL)]['convertedRUBRevenue'], 900)
        self.assertEqual(selected['same_visit_overlap']['share'], 5 / 30)
        self.assertIn('not_purchase_probability', selected['same_visit_overlap']['meaning'])
        self.assertEqual(selected['filter'], r.DIRECT_MAX2_FILTER)
        self.assertTrue(result['scopes']['yandex_direct_last_significant']['source_visit_count_matches_discovery'])
        self.assertEqual(result['goals'][0]['conditions'][0]['path_or_fragment'], 'max2')
        self.assertEqual(result['goals'][1]['conditions'][0]['target'], r.MODELED_TARGET)
        urls = result['grouped']['max2_direct_start_url_profile']
        self.assertEqual(urls['routes'], [{'host': 'tour-max.ru', 'path': '/new/max2/', 'url_rows': 2, 'visits': 30}])
        self.assertEqual(urls['query_key_presence']['post_id'], {'url_rows': 1, 'visits': 10})
        self.assertEqual(urls['query_key_presence']['_ctx1'], {'url_rows': 0, 'visits': 0})
        self.assertEqual(urls['startapp_ctx1_marker_presence'], {'url_rows': 1, 'visits': 10})
        serialized = r.serialized_result()
        self.assertNotIn('SECRET', serialized)
        self.assertNotIn('OTHER_CAMPAIGN', serialized)
        self.assertNotIn('https://tour-max.ru/new/max2/', serialized)
        self.assertLess(len(serialized.encode()), r.MAX_RETURN_BYTES)

    def test_wrong_goals_stop_before_statistics_and_missing_conditions_are_partial(self):
        for mutation, code in (
            (lambda g: g[0].update(type='action'), 'MAX2_GOAL_CONDITIONS_MISMATCH'),
            (lambda g: g[0].update(conditions=[{'type': 'contain', 'url': '/max20/'}]),
             'MAX2_GOAL_CONDITIONS_MISMATCH'),
            (lambda g: g[1].update(conditions=[{'type': 'exact', 'url': 'wrong_target'}]),
             'MODELED_GOAL_CONDITIONS_MISMATCH'),
            (lambda g: g[0].update(id=111), 'EXACT_GOALS_NOT_RETURNED'),
        ):
            with self.subTest(code=code):
                r.reset()
                provider = Provider()
                mutation(provider.goals)
                with self.assertRaises(r.Stop) as caught:
                    r.collect(provider)
                self.assertEqual(caught.exception.info['code'], code)
                self.assertEqual(len(provider.calls), 2)
        r.reset()
        del self.provider.goals[0]['conditions']
        result = r.collect(self.provider)
        self.assertEqual(result['status'], 'PARTIAL')
        self.assertFalse(result['goal_catalog_verified'])
        self.assertEqual(result['goals'][0]['condition_verification'], 'partial_conditions_unavailable')
        for unknown in ([{'type': 'regexp', 'url': '.*/new/max2/.*'}],
                        {'type': 'contain', 'url': 'max2'},
                        [{'type': 'contain', 'unknown_field': 'SECRET_VALUE'}]):
            with self.subTest(unknown_conditions=type(unknown).__name__):
                r.reset()
                provider = Provider()
                provider.goals[0]['conditions'] = unknown
                result = r.collect(provider)
                self.assertEqual(result['status'], 'PARTIAL')
                self.assertFalse(result['goal_catalog_verified'])
                self.assertEqual(result['goals'][0]['condition_verification'],
                                 'partial_conditions_unsupported')
                self.assertIn('yandex_direct_last_significant_max2_visits', result['scopes'])
                self.assertNotIn('SECRET_VALUE', r.serialized_result())

    def test_no_direct_does_not_treat_undefined_as_direct_or_invent_zero(self):
        self.provider.engines = [
            {'dimensions': [{'id': 'ya_undefined', 'name': 'Yandex Direct'}], 'metrics': [100]}]
        result = r.collect(self.provider)
        self.assertFalse(result['direct_scope_available'])
        self.assertEqual(set(result['scopes']), {'all_traffic', 'all_traffic_max2_visits'})
        self.assertEqual(result['grouped'], {})
        self.assertTrue(any(e['code'] == 'DIRECT_ENGINE_NOT_UNIQUELY_DISCOVERED'
                            for e in result['errors']))

    def test_sampling_and_url_truncation_preserve_totals_and_partial_coverage(self):
        self.provider.transforms['all_traffic'] = lambda b: b.update(
            sampled=True, sample_share=0.5, sample_size=50, sample_space=100)
        self.provider.transforms['max2_direct_start_url_profile'] = lambda b: b.update(total_rows=120)
        result = r.collect(self.provider)
        self.assertEqual(result['status'], 'PARTIAL')
        self.assertEqual(result['scopes']['all_traffic']['visits'], 100)
        self.assertFalse(result['scopes']['all_traffic']['complete'])
        profile = result['grouped']['max2_direct_start_url_profile']
        self.assertEqual(profile['quality']['total_rows'], 120)
        self.assertFalse(profile['quality']['rows_complete'])
        self.assertEqual(profile['quality']['data_lag'], 60)
        self.assertEqual(profile['query_key_presence']['post_id']['visits'], 10)
        self.assertIn('returned_url_rows_only', profile['presence_basis'])

    def test_truncated_discovery_never_admits_direct_scope(self):
        self.provider.transforms['engine_discovery'] = lambda b: b.update(total_rows=101)
        result = r.collect(self.provider)
        self.assertFalse(result['direct_scope_available'])
        self.assertTrue(any(e['code'] == 'ENGINE_DISCOVERY_TRUNCATED' for e in result['errors']))

    def test_bad_counts_and_provider_scope_fail_locally_with_other_reports_retained(self):
        for bad in (-1, True, '30', float('nan'), float('inf'), 10 ** 400):
            with self.subTest(value=type(bad).__name__):
                r.reset()
                provider = Provider()
                provider.values['all_traffic'][0] = bad
                result = r.collect(provider)
                self.assertNotIn('all_traffic', result['scopes'])
                self.assertIn('yandex_direct_last_significant', result['scopes'])
                self.assertTrue(any(e['code'] == 'INVALID_COUNT' for e in result['errors']))
        r.reset()
        self.provider.transforms['all_traffic'] = lambda b: b['query'].update(ids=[1])
        result = r.collect(self.provider)
        self.assertTrue(any(e['code'] == 'PROVIDER_QUERY_SCOPE_MISMATCH' for e in result['errors']))

    def test_same_visit_filter_is_checked_and_zero_denominator_stays_unknown(self):
        self.provider.values['all_traffic_max2_visits'][3] = 39
        result = r.collect(self.provider)
        self.assertNotIn('all_traffic_max2_visits', result['scopes'])
        self.assertTrue(any(e['code'] == 'MAX2_FILTER_NOT_CONFIRMED' for e in result['errors']))
        r.reset()
        provider = Provider()
        provider.values['all_traffic_max2_visits'] = [0] * 9
        result = r.collect(provider)
        self.assertIsNone(result['scopes']['all_traffic_max2_visits']['same_visit_overlap']['share'])

    def test_http_partial_keeps_machine_code_not_error_body(self):
        self.provider.failures['all_traffic'] = r.Stop('API_READ_REJECTED', 403, ['access_denied'])
        result = r.collect(self.provider)
        self.assertEqual(result['status'], 'PARTIAL')
        self.assertNotIn('all_traffic', result['scopes'])
        self.assertIn('yandex_direct_last_significant_max2_visits', result['scopes'])
        self.assertEqual(result['errors'][0]['http'], 403)
        self.assertEqual(result['errors'][0]['provider_error_codes'], ['access_denied'])

    def test_guarded_transport_get_tls_no_proxy_no_redirect_and_exact_plan(self):
        calls, handlers = [], []

        class Opener:
            def open(self, request, timeout):
                calls.append((request, timeout))
                return Response(request, b'{"counter":{"id":98615635}}')

        def build(*args):
            handlers.extend(args)
            return Opener()

        with patch.object(r.urllib.request, 'build_opener', side_effect=build):
            get = r.make_reader('MOCK_PRIVATE_TOKEN')
            self.assertEqual(get(r.COUNTER_PATH)['counter']['id'], r.COUNTER)
            for path, params in (
                ('/management/v1/counter/1', None),
                (r.COUNTER_PATH, {'oauth_token': 'bad'}),
                (r.REPORT_PATH, {**r.PLAN['all_traffic'], 'ids': '1'}),
                (r.REPORT_PATH, {**r.PLAN['all_traffic'], 'date1': '2026-01-01'}),
                (r.REPORT_PATH, {**r.PLAN['all_traffic'], 'dimensions': 'ym:s:clientID'}),
                (r.REPORT_PATH, {**r.PLAN['all_traffic'], 'filters': "ym:s:clientID=='123'"}),
                (r.REPORT_PATH, {**r.PLAN['all_traffic'], 'metrics': 'ym:s:pageviews'}),
                (r.REPORT_PATH, {**r.PLAN['all_traffic'], 'limit': '100000'}),
            ):
                with self.subTest(path=path, keys=list(params or {})):
                    with self.assertRaises(r.Stop):
                        get(path, params)
        self.assertEqual(len(calls), 1)
        self.assertEqual(calls[0][0].get_method(), 'GET')
        self.assertEqual(calls[0][1], 15)
        self.assertTrue(calls[0][0].full_url.startswith('https://api-metrika.yandex.net/'))
        proxy = next(h for h in handlers if isinstance(h, urllib.request.ProxyHandler))
        self.assertEqual(proxy.proxies, {})
        tls = next(h for h in handlers if isinstance(h, urllib.request.HTTPSHandler))
        self.assertTrue(tls._context.check_hostname)
        self.assertEqual(tls._context.verify_mode, ssl.CERT_REQUIRED)
        redirect = next(h for h in handlers if isinstance(h, r.NoRedirect))
        self.assertIsNone(redirect.redirect_request(None))
        self.assertNotIn('MOCK_PRIVATE_TOKEN', r.serialized_result())

    def test_403_no_retry_budget_and_response_bound(self):
        calls = []

        class Denied:
            def open(self, request, timeout):
                calls.append(request)
                raise urllib.error.HTTPError(
                    request.full_url, 403, 'SECRET_ERROR_MESSAGE', {},
                    io.BytesIO(b'{"errors":[{"error_type":"access_denied","message":"SECRET_BODY"}]}'))

        with patch.object(r.urllib.request, 'build_opener', return_value=Denied()), \
             patch.object(r.time, 'sleep', side_effect=AssertionError('403 retry')):
            get = r.make_reader('SECRET_TOKEN')
            with self.assertRaises(r.Stop) as caught:
                get(r.COUNTER_PATH)
            self.assertEqual(caught.exception.info,
                             {'code': 'API_READ_REJECTED', 'http': 403,
                              'provider_error_codes': ['access_denied']})
            self.assertEqual(len(calls), 1)
            r.RESULT['requests'] = [{} for _ in range(r.MAX_ATTEMPTS)]
            with self.assertRaises(r.Stop) as caught:
                get(r.COUNTER_PATH)
            self.assertEqual(caught.exception.info['code'], 'REQUEST_BUDGET')
            self.assertEqual(len(calls), 1)
        r.reset()

        class Oversize:
            def open(self, request, timeout):
                return Response(request, b'x' * (r.MAX_RESPONSE_BYTES + 1))

        with patch.object(r.urllib.request, 'build_opener', return_value=Oversize()):
            with self.assertRaises(r.Stop) as caught:
                r.make_reader('MOCK_TOKEN')(r.COUNTER_PATH)
            self.assertEqual(caught.exception.info['code'], 'RESPONSE_TOO_LARGE')

    def test_unknown_url_and_path_secrets_are_not_exported(self):
        host, path, flags, marker = r.route_and_presence(
            'https://secret-host.example/SECRET_PATH?post_id=SECRET_VALUE#SECRET_FRAGMENT')
        self.assertEqual(host, '[other-host-redacted]')
        self.assertEqual(path, '[other-path-redacted]')
        self.assertTrue(flags['post_id'])
        self.assertFalse(marker)
        self.assertEqual(r.route_and_presence(None)[0], '[unparsed-host]')
        metadata = r.condition_metadata({'type': 'contain',
                                         'url': 'https://tour-max.ru/new/max2/?yclid=SECRET_VALUE'})
        self.assertEqual(metadata['path'], '/new/max2/')
        self.assertTrue(metadata['has_query'])
        self.assertNotIn('SECRET', json.dumps(metadata))


if __name__ == '__main__':
    unittest.main()
