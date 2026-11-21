"""Synthetic catalog and transport regressions; no service credentials or network."""
import copy
import importlib.util
import io
import json
import ssl
import unittest
import urllib.error
from pathlib import Path
from unittest import mock

HERE = Path(__file__).resolve().parent


def module():
    spec = importlib.util.spec_from_file_location('new_goals_reader', HERE / 'reader.py')
    result = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(result)
    return result


class CatalogTests(unittest.TestCase):
    def setUp(self):
        self.m = module()
        self.counter = {'counter': {'id': 98615635, 'status': 'Active',
                        'time_zone_name': 'Europe/Moscow', 'time_zone_offset': 180,
                        'owner_login': 'SHOULD_NOT_BE_EXPORTED'}}
        self.goals = {'goals': [
            {'id': 563541588, 'name': 'max2', 'type': 'url', 'is_retargeting': 0,
             'conditions': [{'type': 'contain', 'url': '/max2/'}]},
            {'id': 678187097, 'name': 'AnyTour: оценочная ценность обращения v1',
             'type': 'action', 'is_retargeting': 0,
             'conditions': [{'type': 'exact', 'url': 'anytour_modeled_value_v1'}]},
            {'id': 687931224, 'name': 'max2 оценочная', 'type': 'action', 'is_retargeting': 0,
             'conditions': [{'type': 'exact', 'url': 'max2ok'}]},
            {'id': 687941321, 'name': 'anytour_modeled_value_v2_max2',
             'type': 'action', 'is_retargeting': 0,
             'conditions': [{'type': 'exact', 'url': 'anytour_modeled_value_v2_max2'}]},
            {'id': 999, 'name': 'UNRELATED_PRIVATE_NAME', 'type': 'url',
             'conditions': [{'type': 'exact', 'url': 'UNRELATED_PRIVATE_URL'}]},
        ]}

    def collect(self):
        self.m.reset()
        def get(path, params=None):
            name = self.m.validate_request(path, params)
            self.m.RESULT['requests'].append({'endpoint': path, 'query': name,
                                             'attempt': 1, 'http': 200})
            return copy.deepcopy(self.counter if path == self.m.COUNTER_PATH else self.goals)
        result = self.m.collect(get)
        self.m.validate_projected_report(result)
        return result

    def test_exact_four_goal_catalog_and_no_unrelated_projection(self):
        result = self.collect()
        self.assertEqual(result['status'], 'COLLECTED')
        self.assertEqual([r['id'] for r in result['goals']], list(self.m.GOAL_ORDER))
        self.assertTrue(result['protected_goal_baseline_verified'])
        self.assertEqual(len(result['requests']), 2)
        self.assertFalse(result['conversion_events_sent'])
        text = json.dumps(result)
        for private in ('SHOULD_NOT_BE_EXPORTED', 'UNRELATED_PRIVATE_NAME', 'UNRELATED_PRIVATE_URL'):
            self.assertNotIn(private, text)

    def test_counter_mismatch_stops_before_goal_request(self):
        for value in (1, '98615635', True, None):
            with self.subTest(value=value):
                self.counter['counter']['id'] = value
                with self.assertRaisesRegex(self.m.Stop, 'EXACT_COUNTER_NOT_RETURNED'):
                    self.collect()
                self.assertEqual(len(self.m.RESULT['requests']), 1)

    def test_missing_each_required_goal_is_not_success(self):
        original = copy.deepcopy(self.goals)
        for gid in self.m.GOAL_ORDER:
            with self.subTest(gid=gid):
                self.goals = copy.deepcopy(original)
                self.goals['goals'] = [g for g in self.goals['goals'] if g['id'] != gid]
                with self.assertRaisesRegex(self.m.Stop, 'EXACT_FOUR_GOALS_NOT_RETURNED'):
                    self.collect()
                self.assertFalse(self.m.RESULT['goal_catalog_verified'])

    def test_new_target_type_operator_and_case_must_match_exactly(self):
        original = copy.deepcopy(self.goals)
        for position in (2, 3):
            for change in ('old_target', 'url_type', 'contain', 'case', 'extra_condition'):
                with self.subTest(position=position, change=change):
                    self.goals = copy.deepcopy(original)
                    goal = self.goals['goals'][position]
                    if change == 'old_target':
                        goal['conditions'][0]['url'] = 'anytour_modeled_value_v1'
                    elif change == 'url_type':
                        goal['type'] = 'url'
                    elif change == 'contain':
                        goal['conditions'][0]['type'] = 'contain'
                    elif change == 'case':
                        goal['conditions'][0]['url'] = goal['conditions'][0]['url'].upper()
                    else:
                        goal['conditions'].append({'type': 'exact', 'url': 'other'})
                    with self.assertRaisesRegex(self.m.Stop, 'TYPE_OR_TARGET_MISMATCH'):
                        self.collect()
                    self.assertEqual(self.m.RESULT['goals'], [])

    def test_old_v1_name_or_retargeting_drift_fails_closed(self):
        original = copy.deepcopy(self.goals)
        for position in (0, 1):
            for field, value in (('name', 'changed'), ('is_retargeting', 1)):
                with self.subTest(position=position, field=field):
                    self.goals = copy.deepcopy(original)
                    self.goals['goals'][position][field] = value
                    with self.assertRaisesRegex(self.m.Stop, 'PROTECTED_GOAL_.*_BASELINE_MISMATCH'):
                        self.collect()

    def test_duplicate_boolean_string_and_missing_ids_are_rejected(self):
        original = copy.deepcopy(self.goals)
        for value in (563541588, True, '999', None, -1):
            with self.subTest(value=value):
                self.goals = copy.deepcopy(original)
                self.goals['goals'][-1]['id'] = value
                with self.assertRaisesRegex(self.m.Stop, 'INVALID_GOAL_CATALOG'):
                    self.collect()

    def test_catalog_and_metadata_bounds(self):
        original = copy.deepcopy(self.goals)
        self.goals['goals'] = [{'id': 1000 + i} for i in range(201)]
        with self.assertRaisesRegex(self.m.Stop, 'GOAL_CATALOG_BOUND'):
            self.collect()
        for field, value in (('name', 'x' * 513), ('name', 'unsafe\nname'),
                             ('is_retargeting', 'false')):
            self.goals = copy.deepcopy(original)
            self.goals['goals'][2][field] = value
            with self.assertRaises(self.m.Stop):
                self.collect()

    def test_no_report_mutation_external_or_parameterized_endpoint(self):
        for path, params in (
            ('/stat/v1/data', None),
            ('/management/v1/counter/98615635/offline_conversions/upload', None),
            ('https://other.example/management/v1/counter/98615635', None),
            (self.m.COUNTER_PATH, {'anything': '1'}),
            (self.m.COUNTER_PATH + '?x=1', None),
            (self.m.GOALS_PATH, None),
        ):
            with self.subTest(path=path, params=params):
                self.m.reset()
                with self.assertRaises(self.m.Stop):
                    self.m.validate_request(path, params)
                self.assertEqual(self.m.RESULT['requests'], [])

    def test_exact_request_order_and_two_attempt_ceiling(self):
        self.collect()
        for path in self.m.EXPECTED_PATHS:
            with self.assertRaisesRegex(self.m.Stop, 'REQUEST_BUDGET'):
                self.m.validate_request(path, None)
        self.m.reset()
        self.m.RESULT['requests'].append({'endpoint': self.m.COUNTER_PATH})
        with self.assertRaisesRegex(self.m.Stop, 'EXACT_REQUEST_ORDER_REQUIRED'):
            self.m.validate_request(self.m.COUNTER_PATH, None)

    def test_strict_json_rejects_duplicate_keys_nonfinite_values(self):
        for raw in ('{"counter":{},"counter":{}}', '{"x":NaN}', '{"x":Infinity}'):
            with self.assertRaises(self.m.Stop):
                self.m.strict_json(raw)

    def test_result_validation_rejects_forged_scope_write_and_receipt(self):
        original = copy.deepcopy(self.collect())
        mutations = [
            lambda r: r.update(counter_id=1),
            lambda r: r.update(conversion_events_sent=True),
            lambda r: r.update(goal_catalog_verified=False),
            lambda r: r['requests'][1].update(attempt=2),
            lambda r: r['requests'][1].update(http=429),
            lambda r: r['goals'][3]['conditions'][0].update(url='anytour_modeled_value_v1'),
        ]
        for mutate in mutations:
            value = copy.deepcopy(original)
            mutate(value)
            with self.assertRaises(self.m.Stop):
                self.m.validate_projected_report(value)

    def test_https_transport_get_only_no_retry_no_secret_error(self):
        class Response:
            status = 200
            def __init__(self, url, value):
                self.url, self.value = url, value
            def geturl(self):
                return self.url
            def read(self, maximum):
                return json.dumps(self.value).encode()[:maximum]
            def __enter__(self):
                return self
            def __exit__(self, *_):
                pass
        opener = mock.Mock()
        def opened(request, timeout):
            self.assertEqual(request.get_method(), 'GET')
            self.assertIsNone(request.data)
            self.assertNotIn('?', request.full_url)
            self.assertEqual(timeout, 15)
            value = self.counter if request.full_url.endswith('/98615635') else self.goals
            return Response(request.full_url, value)
        opener.open.side_effect = opened
        self.m.reset()
        with mock.patch.object(self.m.urllib.request, 'build_opener', return_value=opener) as build:
            result = self.m.collect(self.m.make_reader('SYNTHETIC_OAUTH_SECRET'))
        self.assertEqual(result['status'], 'COLLECTED')
        self.assertEqual(opener.open.call_count, 2)
        self.assertNotIn('SYNTHETIC_OAUTH_SECRET', json.dumps(result))
        handlers = build.call_args.args
        self.assertTrue(any(isinstance(x, self.m.NoRedirect) for x in handlers))
        context = next(x for x in handlers if isinstance(x, self.m.urllib.request.HTTPSHandler))._context
        self.assertTrue(context.check_hostname)
        self.assertEqual(context.verify_mode, ssl.CERT_REQUIRED)

        self.m.reset()
        opener.reset_mock()
        body = b'{"errors":[{"code":429,"message":"PRIVATE_TOKEN"}]}'
        opener.open.side_effect = urllib.error.HTTPError('https://example', 429, 'ignored', {}, io.BytesIO(body))
        with mock.patch.object(self.m.urllib.request, 'build_opener', return_value=opener):
            with self.assertRaises(self.m.Stop) as error:
                self.m.make_reader('SYNTHETIC_OAUTH_SECRET')(self.m.COUNTER_PATH)
        self.assertEqual(opener.open.call_count, 1)
        self.assertEqual(error.exception.info['provider_error_codes'], ['429'])
        self.assertNotIn('PRIVATE_TOKEN', json.dumps(error.exception.info))


if __name__ == '__main__':
    unittest.main()
