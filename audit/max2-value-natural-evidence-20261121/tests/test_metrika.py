import copy
import importlib.util
import io
import json
from pathlib import Path
import unittest
from unittest import mock
import urllib.error

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('natural_metrika', ROOT / 'metrika_reader.py')
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


def body(values=None):
    values = [3, 2, 79.08, 3, 2, 79.08] if values is None else values
    return {'query': {'ids': [m.COUNTER], 'date1': m.DATE, 'date2': m.DATE, 'timezone': m.TIMEZONE,
            'metrics': list(m.METRICS), 'dimensions': [], 'filters': '', 'limit': 1, 'offset': 1},
        'totals': list(values), 'data': [{'dimensions': [], 'metrics': list(values)}],
        'sampled': False, 'sample_share': 1.0, 'total_rows': 1, 'total_rows_rounded': False,
        'contains_sensitive_data': False, 'data_lag': 12}


class ReadTests(unittest.TestCase):
    def setUp(self):
        m.reset()

    def test_natural_totals_exact_two_new_goals(self):
        out = m.collect(lambda path, plan: body())
        self.assertEqual(out['status'], 'OBSERVED')
        self.assertEqual([(g['goal_id'], g['target']) for g in out['goals']], list(zip(m.GOALS, m.TARGETS)))
        self.assertEqual(out['goals'][0]['reported_value_rub'], '79.08')
        self.assertFalse(out['interpretation']['per_reservation_delivery_verified'])
        self.assertFalse(out['interpretation']['post_install_only_cohort'])
        self.assertFalse(out['conversion_events_sent'])

    def test_observed_zero_distinct_from_unknown(self):
        zero = body([0] * 6)
        out = m.project(zero)
        self.assertEqual(out['status'], 'OBSERVED')
        self.assertFalse(out['goals'][0]['positive_natural_report_observed'])
        zero['data'] = []; zero['total_rows'] = 0
        self.assertEqual(m.project(zero)['goals'][1]['reported_reaches'], 0)
        zero.pop('sampled')
        self.assertEqual(m.project(zero)['status'], 'PARTIAL')
        self.assertIsNone(m.project(zero)['goals'])

    def test_bad_provider_scope(self):
        changes = {'ids': [1], 'date1': '2026-11-20', 'date2': '2026-11-22', 'timezone': '+00:00',
            'metrics': list(m.METRICS[:-1]), 'dimensions': ['ym:s:clientID'], 'filters': 'old-goal',
            'limit': 2, 'offset': 0}
        for key, value in changes.items():
            with self.subTest(key=key):
                value_body = body(); value_body['query'][key] = value
                with self.assertRaises(m.Stop): m.project(value_body)

    def test_old_goal_and_unrequested_operations_are_rejected(self):
        for path in ('/management/v1/counter/98615635/goals', '/stat/v1/data.csv',
                     '/management/v1/counter/98615635/offline_conversions/upload'):
            with self.assertRaises(m.Stop): m.validate_request(path, m.PLAN)
        for change in ({'ids': '1'}, {'dimensions': 'ym:s:clientID'},
                       {'metrics': 'ym:s:goal678187097reaches'}, {'filters': 'x'}, {'date1': '2026-11-20'}):
            plan = dict(m.PLAN); plan.update(change)
            with self.assertRaises(m.Stop): m.validate_request(m.REPORT_PATH, plan)

    def test_invalid_metrics_fail_closed(self):
        for value in (None, True, '3', -1, float('nan'), float('inf'), 1e16):
            for index in (0, 2):
                with self.subTest(value=repr(value), index=index):
                    values = [3, 2, 79.08, 3, 2, 79.08]; values[index] = value
                    with self.assertRaises(m.Stop): m.project(body(values))
        for values in ([2.5, 2, 79, 3, 2, 79], [1, 2, 79, 3, 2, 79]):
            with self.assertRaises(m.Stop): m.project(body(values))

    def test_row_and_total_mismatch(self):
        for change in ('totals', 'dimensions', 'extra_row', 'missing_row', 'count'):
            item = copy.deepcopy(body())
            if change == 'totals': item['data'][0]['metrics'][0] += 1
            elif change == 'dimensions': item['data'][0]['dimensions'] = [{'id': 'private'}]
            elif change == 'extra_row': item['data'].append(copy.deepcopy(item['data'][0]))
            elif change == 'missing_row': item['data'] = []
            else: item['total_rows'] = 0
            with self.subTest(change=change), self.assertRaises(m.Stop): m.project(item)

    def test_sampling_and_unknown_metadata_never_become_zero(self):
        for key, value in (('sampled', True), ('sample_share', .8), ('total_rows_rounded', True),
                           ('contains_sensitive_data', True), ('sampled', None)):
            item = body(); item[key] = value
            out = m.project(item)
            self.assertEqual(out['status'], 'PARTIAL'); self.assertIsNone(out['goals'])
        for key, value in (('sampled', 'false'), ('sample_share', True), ('data_lag', -1),
                           ('total_rows', True), ('contains_sensitive_data', 0)):
            item = body(); item[key] = value
            with self.assertRaises(m.Stop): m.project(item)

    def test_strict_json_and_no_raw_error_text(self):
        for raw in (b'{"a":1,"a":2}', b'{"x":NaN}', b'{"x":Infinity}'):
            with self.assertRaises(m.Stop): m.strict_json(raw)
        self.assertEqual(m.error_codes(b'{"message":"secret-person","code":"ACCESS_DENIED"}'), ['ACCESS_DENIED'])
        self.assertEqual(m.error_codes(b'{"code":"private secret with spaces"}'), [])

    def test_exact_single_get_transport_and_no_retry(self):
        expected = 'https://api-metrika.yandex.net' + m.REPORT_PATH + '?' + m.urllib.parse.urlencode(m.PLAN)
        response = mock.MagicMock(); response.__enter__.return_value = response
        response.status = 200; response.geturl.return_value = expected
        response.read.return_value = json.dumps(body()).encode()
        opener = mock.Mock(); opener.open.return_value = response
        with mock.patch.object(m.urllib.request, 'build_opener', return_value=opener):
            get = m.make_reader('fixture-token-only')
            out = m.collect(get)
            with self.assertRaises(m.Stop): m.collect(get)
        self.assertEqual(opener.open.call_count, 1)
        request = opener.open.call_args.args[0]
        self.assertEqual(request.get_method(), 'GET'); self.assertEqual(request.full_url, expected)
        self.assertNotIn('Cookie', request.headers)
        self.assertNotIn('fixture-token-only', json.dumps(out))
        self.assertEqual(out['requests'][0]['http'], 200)

    def test_network_and_http_failure_are_one_attempt_without_secret(self):
        for error in (RuntimeError('private-token-or-person'),
                      urllib.error.HTTPError('x', 403, 'private', {}, io.BytesIO(b'{"message":"private","code":"DENIED"}'))):
            m.reset(); opener = mock.Mock(); opener.open.side_effect = error
            with mock.patch.object(m.urllib.request, 'build_opener', return_value=opener):
                get = m.make_reader('fixture-token-only')
                with self.assertRaises(m.Stop) as raised: m.collect(get)
                with self.assertRaises(m.Stop): m.collect(get)
            self.assertEqual(opener.open.call_count, 1)
            self.assertNotIn('private', json.dumps(raised.exception.info))
            self.assertIsNone(m.RESULT['goals'])

    def test_redirect_and_oversize_rejected(self):
        self.assertIsNone(m.NoRedirect().redirect_request(None, None, None, None, None, None))
        response = mock.MagicMock(); response.__enter__.return_value = response; response.status = 200
        url = 'https://api-metrika.yandex.net' + m.REPORT_PATH + '?' + m.urllib.parse.urlencode(m.PLAN)
        for returned_url, raw in (('https://other.example', b'{}'), (url, b'x' * (m.MAX_RESPONSE_BYTES + 1))):
            m.reset(); response.geturl.return_value = returned_url; response.read.return_value = raw
            opener = mock.Mock(); opener.open.return_value = response
            with mock.patch.object(m.urllib.request, 'build_opener', return_value=opener):
                with self.assertRaises(m.Stop): m.collect(m.make_reader('fixture-token-only'))
            self.assertEqual(opener.open.call_count, 1)

    def test_bad_install_pin_rejected_before_credentials(self):
        with mock.patch.object(m.sys, 'argv', ['reader', '0' * 64]), \
             mock.patch.object(m, 'load_local_config') as local, mock.patch('sys.stdout', new_callable=io.StringIO) as stdout:
            m.main()
        local.assert_not_called()
        result = json.loads(stdout.getvalue())
        self.assertEqual(result['errors'][0]['code'], 'ORIGINAL_INSTALL_RECEIPT_PIN_REQUIRED')
        self.assertIsNone(result['goals'])

    def test_closed_retained_projection(self):
        out = m.collect(lambda path, plan: body())
        out.update(local_config_verified=True, installed_sha='a' * 40,
                   install_receipt_sha256=m.INSTALL_RECEIPT_SHA256,
                   finished_at_utc=out['started_at_utc'],
                   requests=[{'endpoint': m.REPORT_PATH, 'query': 'two_new_goal_totals', 'attempt': 1, 'http': 200}])
        self.assertIs(m.validate_projected_report(out), out)
        for mutate in (
            lambda r: r.update(client_id='private'),
            lambda r: r.update(counter_id=1),
            lambda r: r.update(install_receipt_sha256='0' * 64),
            lambda r: r.update(conversion_events_sent=True),
            lambda r: r['goals'][0].update(goal_id=678187097),
            lambda r: r['goals'][1].update(reported_value_rub='NaN'),
            lambda r: r['goals'][0].update(client_id='private'),
            lambda r: r['interpretation'].update(per_reservation_delivery_verified=True),
            lambda r: r['requests'].append(dict(r['requests'][0])),
            lambda r: r['quality'].update(complete=False),
        ):
            changed = copy.deepcopy(out); mutate(changed)
            with self.assertRaises(m.Stop): m.validate_projected_report(changed)


if __name__ == '__main__': unittest.main()
