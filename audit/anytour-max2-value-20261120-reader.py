"""Fixed-week GET-only Metrika observation. No attribution or financial writes."""
import json
import math
import os
import re
import signal
import ssl
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timezone
from pathlib import Path

sys.dont_write_bytecode = True
ROOT = Path('/var/www/anytoour/data/www/d.anytoour.ru')
COUNTER = 98615635
DATE1, DATE2 = '2026-11-12', '2026-11-18'
MAX2_GOAL, MODELED_GOAL = 563541588, 678187097
MODELED_TARGET = 'anytour_modeled_value_v1'
MAX_ATTEMPTS, MAX_RESPONSE_BYTES, MAX_RETURN_BYTES = 24, 2_000_000, 4_000_000
TOTAL_SECONDS, REQUEST_TIMEOUT = 180, 15
DIRECT_FILTER = "ym:s:lastsignAdvEngine=='ya_direct'"
MAX2_FILTER = "ym:s:goal563541588IsReached=='Yes'"
DIRECT_MAX2_FILTER = DIRECT_FILTER + ' AND ' + MAX2_FILTER
METRICS = ('ym:s:visits', 'ym:s:users',
           'ym:s:goal563541588reaches', 'ym:s:goal563541588visits',
           'ym:s:goal563541588users', 'ym:s:goal678187097reaches',
           'ym:s:goal678187097visits', 'ym:s:goal678187097users',
           'ym:s:goal678187097convertedRUBRevenue')
QUERY_KEYS = ('post_id', 'offer_id', 'hotel_id', 'startapp', '_ctx1')
COUNTER_PATH = '/management/v1/counter/' + str(COUNTER)
GOALS_PATH, REPORT_PATH = COUNTER_PATH + '/goals', '/stat/v1/data'
DOCS = {
    'segmentation': 'https://yandex.ru/dev/metrika/en/stat/segmentation',
    'goal_metrics': 'https://yandex.ru/dev/metrika/en/stat/metrics/visits/conversions',
    'attribution': 'https://yandex.ru/dev/metrika/ru/stat/param',
}


class Stop(Exception):
    def __init__(self, code, http=None, provider_codes=None):
        self.info = {'code': code, 'http': http}
        if provider_codes:
            self.info['provider_error_codes'] = provider_codes
        super().__init__(code)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def reset():
    """Tests and the single CLI invocation start with independent state."""
    global RESULT, DEADLINE
    DEADLINE = time.monotonic() + TOTAL_SECONDS
    RESULT = {
        'schema_version': 1, 'counter_id': COUNTER, 'date1': DATE1, 'date2': DATE2,
        'started_at_utc': datetime.now(timezone.utc).isoformat(),
        'server_writes': False, 'advertising_writes': False, 'database_opened': False,
        'token_exported': False, 'campaign_optimization_read': False,
        'requests': [], 'errors': [], 'warnings': [], 'goals': [],
        'scopes': {}, 'grouped': {}, 'documentation': DOCS.copy(),
        'interpretation': {
            'basis': 'provider_reported_visits_in_fixed_week',
            'attribution_requested': 'lastsign',
            'attribution_semantics': 'provider_reporting_mapping_not_campaign_optimization',
            'overlap': 'goals_reported_in_same_visit_not_purchase_probability',
            'modeled_revenue': 'provider_reported_modeled_value_not_booking_revenue',
            'visit_to_request_linkage': 'not_observed_by_this_aggregate_read',
            'cross_visit_cohort': False, 'hotel_price_join': False,
        },
        'limits': {'get_attempts': MAX_ATTEMPTS, 'request_timeout_seconds': REQUEST_TIMEOUT,
                   'total_seconds': TOTAL_SECONDS, 'response_bytes': MAX_RESPONSE_BYTES,
                   'return_bytes': MAX_RETURN_BYTES, 'start_url_rows': 100},
    }
    return RESULT


reset()


def finite_count(value):
    if type(value) not in (int, float) or not 0 <= value <= 1e18 or not math.isfinite(value):
        raise Stop('INVALID_COUNT')
    return value


def bounded_text(value, limit=256):
    if not isinstance(value, str) or len(value) > limit or re.search(r'[\x00-\x1f\x7f]', value):
        raise Stop('INVALID_METADATA')
    return value


def check_time():
    if time.monotonic() >= DEADLINE:
        raise Stop('TIME_BUDGET')


def query_plan():
    base = {'ids': str(COUNTER), 'date1': DATE1, 'date2': DATE2,
            'accuracy': 'full', 'lang': 'en', 'include_undefined': 'true'}
    reports = {
        'engine_discovery': {**base, 'metrics': 'ym:s:visits',
                             'dimensions': 'ym:s:lastsignAdvEngine', 'limit': '100',
                             'sort': '-ym:s:visits'},
    }
    for name, filters in (
        ('all_traffic', None),
        ('all_traffic_max2_visits', MAX2_FILTER),
        ('yandex_direct_last_significant', DIRECT_FILTER),
        ('yandex_direct_last_significant_max2_visits', DIRECT_MAX2_FILTER),
    ):
        p = {**base, 'metrics': ','.join(METRICS), 'limit': '1'}
        if filters:
            p['filters'] = filters
        reports[name] = p
    reports['max2_direct_by_date'] = {
        **base, 'metrics': ','.join(METRICS), 'filters': DIRECT_MAX2_FILTER,
        'dimensions': 'ym:s:date', 'limit': '7', 'sort': 'ym:s:date'}
    reports['max2_direct_start_url_profile'] = {
        **base, 'metrics': 'ym:s:visits', 'filters': DIRECT_MAX2_FILTER,
        'dimensions': 'ym:s:startURL', 'limit': '100', 'sort': '-ym:s:visits'}
    reports['max2_direct_by_observed_campaign'] = {
        **base, 'metrics': ','.join(METRICS), 'filters': DIRECT_MAX2_FILTER,
        'dimensions': 'ym:s:lastsignDirectClickOrder', 'limit': '100',
        'sort': '-ym:s:visits'}
    return reports


PLAN = query_plan()


def validate_request(path, params):
    if path in (COUNTER_PATH, GOALS_PATH):
        if params not in (None, {}):
            raise Stop('MANAGEMENT_PARAMETERS_NOT_ALLOWED')
        return 'counter' if path == COUNTER_PATH else 'goal_catalog'
    if path != REPORT_PATH:
        raise Stop('ENDPOINT_NOT_ALLOWED')
    if not isinstance(params, dict):
        raise Stop('REPORT_SCOPE_MISMATCH')
    for name, allowed in PLAN.items():
        if params == allowed:
            return name
    raise Stop('REPORT_SCOPE_MISMATCH')


def load_local_config():
    from dotenv import dotenv_values
    if Path.cwd() != ROOT or ROOT.resolve() != ROOT:
        raise Stop('EXACT_DIRECTORY_REQUIRED')
    envfile = ROOT / '.env'
    if envfile.is_symlink() or not envfile.is_file() or envfile.stat().st_size > 65536:
        raise Stop('LOCAL_ENV_NOT_CONFIRMED')
    safe_env = {
        'PATH': '/usr/local/bin:/usr/bin:/bin', 'HOME': os.environ.get('HOME', '/'),
        'GIT_OPTIONAL_LOCKS': '0', 'GIT_TERMINAL_PROMPT': '0',
        'GIT_CONFIG_NOSYSTEM': '1', 'GIT_CONFIG_GLOBAL': '/dev/null',
    }

    def git(*args):
        check_time()
        p = subprocess.run(
            ['git', '--no-optional-locks', '-c', 'core.fsmonitor=false',
             '-c', 'core.hooksPath=/dev/null', '-C', str(ROOT), *args],
            capture_output=True, text=True, timeout=10, env=safe_env)
        if p.returncode:
            raise Stop('PROJECT_IDENTITY_CHECK_FAILED')
        return p.stdout.strip()

    if git('rev-parse', '--show-toplevel') != str(ROOT):
        raise Stop('PROJECT_IDENTITY_MISMATCH')
    if git('config', '--get', 'remote.origin.url') not in (
        'git@github.com:pyatkoff/yandex-direct-autopilot.git',
        'https://github.com/pyatkoff/yandex-direct-autopilot.git',
        'https://github.com/pyatkoff/yandex-direct-autopilot',
    ):
        raise Stop('PROJECT_IDENTITY_MISMATCH')
    sha = git('rev-parse', 'HEAD')
    if not re.fullmatch('[0-9a-f]{40}', sha):
        raise Stop('INVALID_INSTALLED_REVISION')
    RESULT['installed_sha'] = sha
    cfg = dotenv_values(envfile, interpolate=False)

    def conf(key, default=''):
        return os.environ.get(key, cfg.get(key, default))

    if str(conf('READ_ONLY')).lower() != 'true':
        raise Stop('READ_ONLY_NOT_TRUE')
    if str(conf('YANDEX_DIRECT_SANDBOX', 'false')).lower() not in ('false', '0'):
        raise Stop('SANDBOX_ENABLED')
    token = conf('YANDEX_OAUTH_TOKEN')
    if not isinstance(token, str) or not token or re.search(r'\s', token):
        raise Stop('LOCAL_TOKEN_MISSING_OR_INVALID')
    RESULT['local_config_verified'] = True
    return token


def error_codes(raw):
    """Only short machine codes survive. Never return provider error messages."""
    try:
        body = json.loads(raw)
        candidates = []
        if isinstance(body, dict):
            candidates += [body.get('code'), body.get('error_type')]
            for item in body.get('errors', [])[:10]:
                if isinstance(item, dict):
                    candidates += [item.get('code'), item.get('error_type')]
        return list(dict.fromkeys(
            str(v) for v in candidates if type(v) is int
            or isinstance(v, str) and re.fullmatch(r'[A-Za-z0-9_.:-]{1,80}', v)))
    except Exception:
        return []


def make_reader(token):
    context = ssl.create_default_context()
    context.check_hostname = True
    context.verify_mode = ssl.CERT_REQUIRED
    opener = urllib.request.build_opener(
        urllib.request.ProxyHandler({}), NoRedirect(),
        urllib.request.HTTPSHandler(context=context))

    def get(path, params=None):
        name = validate_request(path, params)
        url = 'https://api-metrika.yandex.net' + path
        if params:
            url += '?' + urllib.parse.urlencode(params)
        request = urllib.request.Request(
            url, headers={'Authorization': 'OAuth ' + token, 'Accept-Language': 'en'},
            method='GET')
        for attempt in range(3):
            check_time()
            if len(RESULT['requests']) >= MAX_ATTEMPTS:
                raise Stop('REQUEST_BUDGET')
            entry = {'endpoint': path, 'query': name, 'attempt': attempt + 1, 'http': None}
            RESULT['requests'].append(entry)
            try:
                with opener.open(request, timeout=REQUEST_TIMEOUT) as response:
                    status, headers = response.status, response.headers
                    if response.geturl() != url:
                        raise Stop('RESPONSE_ENDPOINT_MISMATCH')
                    raw = response.read(MAX_RESPONSE_BYTES + 1)
            except Stop:
                raise
            except urllib.error.HTTPError as error:
                status, headers = error.code, error.headers
                raw = error.read(MAX_RESPONSE_BYTES + 1)
                error.close()
            except Exception:
                raise Stop('HTTPS_READ_FAILED') from None
            entry['http'] = status
            if len(raw) > MAX_RESPONSE_BYTES:
                raise Stop('RESPONSE_TOO_LARGE', status)
            if status in (429, 500, 502, 503, 504) and attempt < 2:
                delay = headers.get('Retry-After', '2') if headers else '2'
                delay = int(delay) if re.fullmatch(r'[0-9]{1,2}', str(delay)) else 2
                if delay <= 8:
                    check_time()
                    time.sleep(max(1, delay))
                    continue
            if status != 200:
                raise Stop('API_READ_REJECTED', status, error_codes(raw))
            try:
                body = json.loads(raw)
            except Exception:
                raise Stop('NON_JSON_RESPONSE', status) from None
            if not isinstance(body, dict) or body.get('errors') or body.get('error'):
                raise Stop('API_RESPONSE_ERROR', status, error_codes(raw))
            return body
        raise Stop('RETRY_LIMIT')
    return get


def max2_condition(condition):
    if not isinstance(condition, dict) or set(condition) != {'type', 'url'}:
        return False
    kind, value = condition['type'], condition['url']
    if kind not in ('contain', 'exact', 'start') or not isinstance(value, str):
        return False
    # This identifies the retained goal; it does not prescribe a new URL rule.
    return (len(value) <= 2048
            and re.search(r'(?<![A-Za-z0-9])max2(?![A-Za-z0-9])', value, re.I) is not None)


def condition_metadata(condition):
    """Retain operator and safe route structure, never URL parameter values."""
    if not isinstance(condition, dict):
        return {'verification': 'unsupported_shape'}
    kind = condition.get('type')
    if not isinstance(kind, str) or not re.fullmatch(r'[a-z_]{1,32}', kind):
        return {'verification': 'unsupported_operator'}
    value = condition.get('url')
    result = {'type': kind, 'contains_max2': max2_condition(condition)}
    if not isinstance(value, str) or len(value) > 2048:
        return {**result, 'verification': 'unavailable_value'}
    if value == MODELED_TARGET:
        return {**result, 'target': MODELED_TARGET, 'verification': 'exact_modeled_target'}
    if value in ('max2', '/max2/', 'new/max2/', '/new/max2/'):
        return {**result, 'path_or_fragment': value, 'verification': 'recognized_max2'}
    try:
        parsed = urllib.parse.urlsplit(value)
        if parsed.scheme in ('http', 'https') and parsed.hostname:
            host, path, _, _ = route_and_presence(value)
            return {**result, 'host': host, 'path': path,
                    'has_query': bool(parsed.query), 'has_fragment': bool(parsed.fragment),
                    'verification': 'recognized_max2' if result['contains_max2'] else 'other_rule'}
    except ValueError:
        pass
    return {**result, 'value_redacted': True, 'verification': 'unrecognized_rule'}


def read_goals(get):
    counter = get(COUNTER_PATH).get('counter', {})
    if not isinstance(counter, dict) or type(counter.get('id')) is not int or counter['id'] != COUNTER:
        raise Stop('EXACT_COUNTER_NOT_RETURNED')
    RESULT['counter'] = {'id': COUNTER}
    for key in ('name', 'time_zone_name', 'status'):
        if key in counter:
            RESULT['counter'][key] = bounded_text(counter[key])
    if 'time_zone_offset' in counter:
        value = counter['time_zone_offset']
        if type(value) not in (int, float) or not math.isfinite(value) or abs(value) > 86400:
            raise Stop('INVALID_COUNTER_TIMEZONE')
        RESULT['counter']['time_zone_offset'] = value
    goals = get(GOALS_PATH).get('goals')
    if not isinstance(goals, list) or len(goals) > 200:
        raise Stop('GOAL_CATALOG_BOUND')
    seen, targets = set(), {}
    for goal in goals:
        gid = goal.get('id') if isinstance(goal, dict) else None
        if type(gid) is not int or gid <= 0 or gid in seen:
            raise Stop('INVALID_GOAL_CATALOG')
        seen.add(gid)
        if gid in (MAX2_GOAL, MODELED_GOAL):
            targets[gid] = goal
    if set(targets) != {MAX2_GOAL, MODELED_GOAL}:
        raise Stop('EXACT_GOALS_NOT_RETURNED')
    for gid in (MAX2_GOAL, MODELED_GOAL):
        goal = targets[gid]
        conditions = goal.get('conditions')
        if gid == MAX2_GOAL:
            if goal.get('type') != 'url':
                raise Stop('MAX2_GOAL_CONDITIONS_MISMATCH')
            if conditions is None or conditions == []:
                verification = 'partial_conditions_unavailable'
                RESULT['warnings'].append({'stage': 'goal_catalog', 'code': 'MAX2_CONDITIONS_UNAVAILABLE'})
            elif (not isinstance(conditions, list) or len(conditions) > 10
                  or not all(isinstance(c, dict) and set(c) == {'type', 'url'}
                             and c['type'] in ('contain', 'exact', 'start')
                             and isinstance(c['url'], str) and len(c['url']) <= 2048
                             for c in conditions)):
                verification = 'partial_conditions_unsupported'
                RESULT['warnings'].append({'stage': 'goal_catalog', 'code': 'MAX2_CONDITIONS_UNSUPPORTED'})
            elif not any(map(max2_condition, conditions)):
                raise Stop('MAX2_GOAL_CONDITIONS_MISMATCH')
            elif not all(map(max2_condition, conditions)):
                verification = 'partial_additional_conditions'
                RESULT['warnings'].append({'stage': 'goal_catalog', 'code': 'MAX2_ADDITIONAL_CONDITIONS'})
            else:
                verification = 'verified'
        elif (goal.get('type') != 'action'
              or conditions != [{'type': 'exact', 'url': MODELED_TARGET}]):
            raise Stop('MODELED_GOAL_CONDITIONS_MISMATCH')
        else:
            verification = 'verified'
        metadata = {'id': gid, 'name': bounded_text(goal.get('name', ''), 512),
                    'type': goal['type'], 'condition_verification': verification,
                    'conditions': [condition_metadata(c) for c in conditions]
                    if isinstance(conditions, list) and 0 < len(conditions) <= 10 else None}
        for key in ('is_retargeting', 'flag'):
            if key in goal and goal[key] is not None:
                value = goal[key]
                if type(value) not in (bool, int, str):
                    raise Stop('INVALID_GOAL_METADATA')
                metadata[key] = bounded_text(value) if isinstance(value, str) else value
        RESULT['goals'].append(metadata)
    RESULT['goal_catalog_verified'] = all(g['condition_verification'] == 'verified' for g in RESULT['goals'])


def quality(body, row_count, has_dimensions):
    out = {key: body.get(key) for key in (
        'sampled', 'sample_share', 'sample_size', 'sample_space', 'data_lag', 'total_rows')}
    if out['sampled'] is not None and type(out['sampled']) is not bool:
        raise Stop('INVALID_SAMPLING_METADATA')
    if out['sample_share'] is not None and not 0 <= finite_count(out['sample_share']) <= 1:
        raise Stop('INVALID_SAMPLING_METADATA')
    for key in ('sample_size', 'sample_space', 'data_lag'):
        if out[key] is not None:
            finite_count(out[key])
    total = out['total_rows']
    if type(total) is not int or total < 0:
        raise Stop('INVALID_TOTAL_ROWS')
    if total < row_count:
        raise Stop('INCONSISTENT_TOTAL_ROWS')
    rows_complete = total == row_count if has_dimensions else total <= 1
    out.update({'rows_returned': row_count, 'rows_complete': rows_complete,
                'unsampled': out['sampled'] is False and out['sample_share'] in (None, 1),
                'data_lag_available': out['data_lag'] is not None})
    out['complete'] = out['rows_complete'] and out['unsampled']
    return out


def report(get, name):
    params = PLAN[name].copy()
    validate_request(REPORT_PATH, params)
    body = get(REPORT_PATH, params)
    query = body.get('query')
    metrics = params['metrics'].split(',')
    dimensions = params.get('dimensions', '').split(',') if params.get('dimensions') else []
    if (not isinstance(query, dict) or query.get('ids') != [COUNTER]
            or query.get('date1') != DATE1 or query.get('date2') != DATE2
            or query.get('metrics') != metrics or query.get('dimensions', []) != dimensions
            or (query.get('filters') or None) != params.get('filters')):
        raise Stop('PROVIDER_QUERY_SCOPE_MISMATCH')
    totals, rows = body.get('totals'), body.get('data')
    if not isinstance(totals, list) or len(totals) != len(metrics):
        raise Stop('METRICS_LENGTH_MISMATCH')
    totals = [finite_count(value) for value in totals]
    if not isinstance(rows, list) or len(rows) > int(params['limit']):
        raise Stop('REPORT_ROW_BOUND')
    validated = []
    for row in rows:
        if (not isinstance(row, dict) or not isinstance(row.get('dimensions'), list)
                or len(row['dimensions']) != len(dimensions)
                or not all(isinstance(d, dict) for d in row['dimensions'])
                or not isinstance(row.get('metrics'), list) or len(row['metrics']) != len(metrics)):
            raise Stop('REPORT_ROW_SHAPE')
        validated.append({'dimensions': row['dimensions'],
                          'metrics': [finite_count(value) for value in row['metrics']]})
    q = quality(body, len(rows), bool(dimensions))
    if not q['complete']:
        RESULT['warnings'].append({'stage': name, 'code': 'SAMPLED_OR_INCOMPLETE_REPORT'})
    output = {
        'scope': name, 'request': params, 'filter': params.get('filters'),
        'quality': q, 'complete': q['complete'],
        'raw_provider_totals': dict(zip(metrics, totals)),
    }
    if not dimensions and rows and validated[0]['metrics'] != totals:
        raise Stop('AGGREGATE_TOTALS_MISMATCH')
    return output, validated


def named_counts(totals):
    values = {'visits': totals['ym:s:visits'], 'users': totals['ym:s:users'], 'goals': {}}
    for gid in (MAX2_GOAL, MODELED_GOAL):
        goal = {suffix: totals['ym:s:goal' + str(gid) + suffix]
                for suffix in ('reaches', 'visits', 'users')}
        if goal['users'] > goal['visits'] or goal['visits'] > goal['reaches']:
            raise Stop('INCONSISTENT_GOAL_COUNTS')
        if goal['visits'] > values['visits'] or goal['users'] > values['users']:
            raise Stop('GOAL_COUNTS_EXCEED_SCOPE')
        values['goals'][str(gid)] = goal
    if values['users'] > values['visits']:
        raise Stop('USERS_EXCEED_VISITS')
    values['goals'][str(MODELED_GOAL)]['convertedRUBRevenue'] = totals[METRICS[-1]]
    return values


def overlap(counts):
    denominator = counts['visits']
    numerator = counts['goals'][str(MODELED_GOAL)]['visits']
    if counts['goals'][str(MAX2_GOAL)]['visits'] != denominator:
        raise Stop('MAX2_FILTER_NOT_CONFIRMED')
    return {
        'meaning': 'modeled_goal_and_max2_in_same_reported_visit_not_purchase_probability',
        'numerator_modeled_goal_visits': numerator, 'denominator_max2_visits': denominator,
        'share': numerator / denominator if denominator else None,
        'modeled_value_rub_in_these_visits': counts['goals'][str(MODELED_GOAL)]['convertedRUBRevenue'],
    }


def route_and_presence(raw):
    """Raw URL and parameter values never leave this function/server process."""
    if raw is None:
        return '[unparsed-host]', '[unparsed-path]', {key: False for key in QUERY_KEYS}, False
    if not isinstance(raw, str) or len(raw) > 8192:
        raise Stop('START_URL_SHAPE')
    try:
        url = urllib.parse.urlsplit(raw)
        if url.scheme not in ('http', 'https') or not url.hostname:
            raise ValueError()
        host = url.hostname.lower()
        if not re.fullmatch(r'[a-z0-9.-]{1,253}', host) or '..' in host:
            raise ValueError()
        public_hosts = {'tour-max.ru', 'www.tour-max.ru', 'anytoour.ru',
                        'www.anytoour.ru', 'anytour.ru', 'www.anytour.ru', 'tg.rhat.ru'}
        host = host if host in public_hosts else '[other-host-redacted]'
        # Only public fixed paths are useful here; arbitrary path components may
        # themselves contain visitor identifiers and are not exported.
        path = url.path or '/'
        public_paths = {'/', '/new/', '/new/max2', '/new/max2/', '/new/max2/index.php',
                        '/new/max2/index.html', '/max2/', '/max2'}
        path = path if path in public_paths else '[other-path-redacted]'
        pairs = urllib.parse.parse_qsl(url.query, keep_blank_values=True, max_num_fields=100)
        keys = {key for key, _ in pairs}
        presence = {key: key in keys for key in QUERY_KEYS}
        marker = any(key == 'startapp' and '_ctx1_' in value for key, value in pairs)
        return host, path, presence, marker
    except (ValueError, UnicodeError):
        return '[unparsed-host]', '[unparsed-path]', {key: False for key in QUERY_KEYS}, False


def url_profile(output, rows):
    routes = {}
    presence = {key: {'url_rows': 0, 'visits': 0} for key in QUERY_KEYS}
    marker = {'url_rows': 0, 'visits': 0}
    returned_visits = 0
    unparsed = {'url_rows': 0, 'visits': 0}
    for row in rows:
        dim = row['dimensions'][0]
        # startURL is a plain string dimension; the provider's name is the URL.
        raw = dim.get('name')
        host, path, flags, has_marker = route_and_presence(raw)
        visits = row['metrics'][0]
        returned_visits += visits
        if host == '[unparsed-host]':
            unparsed['url_rows'] += 1
            unparsed['visits'] += visits
        key = (host, path)
        group = routes.setdefault(key, {'host': host, 'path': path, 'url_rows': 0, 'visits': 0})
        group['url_rows'] += 1
        group['visits'] += visits
        for name, present in flags.items():
            if present:
                presence[name]['url_rows'] += 1
                presence[name]['visits'] += visits
        if has_marker:
            marker['url_rows'] += 1
            marker['visits'] += visits
    output.update({
        'routes': sorted(routes.values(), key=lambda value: (-value['visits'], value['host'], value['path'])),
        'query_key_presence': presence, 'startapp_ctx1_marker_presence': marker,
        'returned_url_rows_visits': returned_visits,
        'provider_all_url_rows_visits': output['raw_provider_totals']['ym:s:visits'],
        'presence_basis': 'returned_url_rows_only_not_all_visits_when_truncated',
        'raw_urls_exported': False, 'query_values_exported': False,
        'unparsed_url_presence_unknown': unparsed,
        'user_counts_summed': False,
    })
    return output


def remember_error(stage, error, optional=False):
    RESULT['errors'].append({'stage': stage, 'optional': optional, **error.info})
    if error.info['code'] in ('TIME_BUDGET', 'REQUEST_BUDGET'):
        raise error


def collect(get):
    read_goals(get)
    direct_visits = None
    try:
        output, rows = report(get, 'engine_discovery')
        entries = []
        for row in rows:
            value = row['dimensions'][0].get('id')
            if value is not None and (not isinstance(value, str)
                                      or not re.fullmatch(r'[A-Za-z0-9_.-]{1,100}', value)):
                raise Stop('ENGINE_ID_INVALID')
            entries.append({'id': value, 'visits': row['metrics'][0]})
        output['engines'] = entries
        RESULT['engine_discovery'] = output
        if not output['quality']['rows_complete']:
            raise Stop('ENGINE_DISCOVERY_TRUNCATED')
        matches = [entry for entry in entries if entry['id'] == 'ya_direct']
        if len(matches) != 1:
            raise Stop('DIRECT_ENGINE_NOT_UNIQUELY_DISCOVERED')
        direct_visits = matches[0]['visits']
        output['selected_id'] = 'ya_direct'
        output['ya_undefined_is_direct'] = False
    except Stop as error:
        remember_error('engine_discovery', error)

    scopes = ['all_traffic', 'all_traffic_max2_visits']
    if direct_visits is not None:
        scopes += ['yandex_direct_last_significant',
                   'yandex_direct_last_significant_max2_visits']
    else:
        RESULT['direct_scope_available'] = False
    for name in scopes:
        try:
            output, _ = report(get, name)
            output.update(named_counts(output['raw_provider_totals']))
            if name.endswith('_max2_visits'):
                output['same_visit_overlap'] = overlap(output)
            if name == 'yandex_direct_last_significant':
                matches = output['visits'] == direct_visits
                output['expected_visits_from_discovery'] = direct_visits
                output['source_visit_count_matches_discovery'] = matches
                if not matches:
                    RESULT['warnings'].append({'stage': name, 'code': 'DISCOVERY_TOTAL_CHANGED_OR_SAMPLED'})
            RESULT['scopes'][name] = output
        except Stop as error:
            remember_error(name, error)
    if direct_visits is not None:
        RESULT['direct_scope_available'] = True
        for name in ('max2_direct_by_date', 'max2_direct_start_url_profile',
                     'max2_direct_by_observed_campaign'):
            try:
                output, rows = report(get, name)
                if name == 'max2_direct_start_url_profile':
                    output = url_profile(output, rows)
                else:
                    result_rows = []
                    for row in rows:
                        dimension = row['dimensions'][0]
                        totals = dict(zip(METRICS, row['metrics']))
                        counts = named_counts(totals)
                        if name == 'max2_direct_by_date':
                            date = dimension.get('name')
                            if (not isinstance(date, str) or not re.fullmatch(r'\d{4}-\d{2}-\d{2}', date)
                                    or not DATE1 <= date <= DATE2):
                                raise Stop('DATE_OUTSIDE_FIXED_WINDOW')
                            label = {'date': date}
                        else:
                            cid = dimension.get('id')
                            if cid is not None and (not isinstance(cid, str)
                                                    or not re.fullmatch(r'(?:[0-9]{1,20}|undefined)', cid)):
                                raise Stop('CAMPAIGN_DIMENSION_INVALID')
                            label = {'observed_campaign_id': cid}
                        result_rows.append({**label, 'raw_provider_metrics': totals,
                                            **counts, 'same_visit_overlap': overlap(counts)})
                    output['rows'] = result_rows
                    output['users_additive_across_rows'] = False
                    if name.endswith('_campaign'):
                        output['meaning'] = 'observed_reporting_dimension_not_campaign_optimization'
                RESULT['grouped'][name] = output
            except Stop as error:
                remember_error(name, error, optional=name.endswith('_campaign'))
    RESULT['status'] = 'PARTIAL' if RESULT['errors'] or RESULT['warnings'] else 'COLLECTED'
    return RESULT


def serialized_result():
    raw = json.dumps(RESULT, ensure_ascii=False, allow_nan=False, separators=(',', ':'))
    if len(raw.encode('utf-8')) <= MAX_RETURN_BYTES:
        return raw
    return json.dumps({
        'schema_version': 1, 'counter_id': COUNTER, 'date1': DATE1, 'date2': DATE2,
        'status': 'READ_FAILED', 'server_writes': False, 'advertising_writes': False,
        'database_opened': False, 'token_exported': False,
        'errors': [{'code': 'RETURN_SIZE_BUDGET', 'http': None}],
    }, separators=(',', ':'))


def main():
    reset()

    def timeout(*_):
        raise Stop('TIME_BUDGET')

    signal.signal(signal.SIGALRM, timeout)
    signal.alarm(TOTAL_SECONDS)
    try:
        collect(make_reader(load_local_config()))
    except Stop as error:
        if not any(item.get('code') == error.info['code'] for item in RESULT['errors']):
            RESULT['errors'].append(error.info)
        RESULT['status'] = 'PARTIAL' if RESULT['scopes'] else 'READ_FAILED'
    except Exception:
        RESULT['errors'].append({'code': 'LOCAL_READER_FAILED', 'http': None})
        RESULT['status'] = 'PARTIAL' if RESULT['scopes'] else 'READ_FAILED'
    finally:
        signal.alarm(0)
        RESULT['finished_at_utc'] = datetime.now(timezone.utc).isoformat()
        print(serialized_result())


if __name__ == '__main__':
    main()
