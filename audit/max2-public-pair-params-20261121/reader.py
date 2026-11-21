"""Prepared, unexecuted bounded read of natural MAX2 atomic public pair keys.

Source transport/guards: max-search-bot ca1b2e5277a35168d892a9de89c10ad93d3a2e20.
Only the exact plans below are admissible. No DB, mutations, URLs, or visitor IDs.
"""
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
DATE1 = DATE2 = '2026-11-21'
TIMEZONE = '+03:00'
MAX2_GOAL, MODELED_GOAL = 563541588, 678187097
MODELED_TARGET = 'anytour_modeled_value_v1'
NAMESPACE = 'max2_public_pair_v1'
MAX_ATTEMPTS, MAX_RESPONSE_BYTES, MAX_RETURN_BYTES = 6, 2_000_000, 4_000_000
TOTAL_SECONDS, REQUEST_TIMEOUT, MAX_GROUP_ROWS = 180, 15, 100
MAX2_FILTER = "ym:s:goal563541588IsReached=='Yes'"
METRICS = ('ym:s:visits', 'ym:s:users',
           'ym:s:goal563541588reaches', 'ym:s:goal563541588visits',
           'ym:s:goal563541588users', 'ym:s:goal678187097reaches',
           'ym:s:goal678187097visits', 'ym:s:goal678187097users',
           'ym:s:goal678187097convertedRUBRevenue')
QUERY_KEYS = ()
COUNTER_PATH = '/management/v1/counter/' + str(COUNTER)
GOALS_PATH, REPORT_PATH = COUNTER_PATH + '/goals', '/stat/v1/data'
DOCS = {
    'table': 'https://yandex.ru/dev/metrika/ru/stat/openapi/data_1',
    'segmentation': 'https://yandex.ru/dev/metrika/ru/stat/segmentation',
    'hierarchy': 'https://yandex.ru/support/metrica/ru/data/visit-params-data',
    'goal_metrics': 'https://yandex.ru/dev/metrika/en/stat/metrics/visits/conversions',
}
TAG = "EXISTS(ym:s:paramsLevel1=='max2_public_pair_v1' AND ym:s:paramsLevel2=='key')"
BASE = {'ids': str(COUNTER), 'date1': DATE1, 'date2': DATE2,
        'timezone': TIMEZONE, 'accuracy': 'full', 'lang': 'en',
        'include_undefined': 'true', 'offset': '1', 'metrics': ','.join(METRICS)}
PLAN = {
    'all_visits': {**BASE, 'limit': '1'},
    'tagged_visits': {**BASE, 'filters': TAG, 'limit': '1'},
    'tagged_max2_visits': {**BASE, 'filters': TAG + ' AND ' + MAX2_FILTER, 'limit': '1'},
    'pair_values': {**BASE, 'filters': TAG, 'limit': str(MAX_GROUP_ROWS),
                    'dimensions': 'ym:s:paramsLevel1,ym:s:paramsLevel2,ym:s:paramsLevel3'},
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
    global RESULT, DEADLINE
    DEADLINE = time.monotonic() + TOTAL_SECONDS
    RESULT = {
        'schema_version': 1, 'namespace': NAMESPACE, 'counter_id': COUNTER,
        'date1': DATE1, 'date2': DATE2, 'timezone': TIMEZONE,
        'started_at_utc': datetime.now(timezone.utc).isoformat(),
        'server_writes': False, 'advertising_writes': False, 'database_opened': False,
        'token_exported': False, 'campaign_optimization_read': False,
        'requests': [], 'errors': [], 'warnings': [], 'goals': [],
        'scopes': {}, 'groups': {}, 'coverage': {}, 'documentation': DOCS.copy(),
        'interpretation': {
            'basis': 'natural_provider_visits_with_atomic_saved_public_pair',
            'date_basis': 'visit_start_date_in_explicit_UTC_plus_03_not_parameter_arrival_time',
            'post_install_only': 'not_proved_by_daily_report',
            'pair_join': 'single_reported_key_parsed_without_cross_product',
            'coverage': 'tagged_is_subset_of_all_counter_visits_not_of_all_eligible_saves',
            'per_key_metrics': 'nonadditive_across_keys_visits_and_goals_may_repeat',
            'modeled_revenue': 'modeled_value_in_reported_visits_not_booking_revenue_or_hotel_allocation',
            'overlap': 'same_reported_visit_not_purchase_probability_or_causal_attribution',
            'cross_query_snapshot': 'sequential_provider_reports_not_atomic_snapshot',
            'unknown': 'missing_or_rejected_observation_is_not_zero',
            'visit_to_entry_linkage': False, 'hotel_price_join': False,
            'saved_version': 'not_present_in_pair_registry_history_required',
            'direct_attribution': 'not_selected_by_this_scope',
        },
        'limits': {'get_attempts': MAX_ATTEMPTS, 'retries': 0,
                   'request_timeout_seconds': REQUEST_TIMEOUT, 'total_seconds': TOTAL_SECONDS,
                   'response_bytes': MAX_RESPONSE_BYTES, 'return_bytes': MAX_RETURN_BYTES,
                   'group_rows': MAX_GROUP_ROWS},
    }
    return RESULT

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
        check_time()
        if len(RESULT['requests']) >= MAX_ATTEMPTS:
            raise Stop('REQUEST_BUDGET')
        url = 'https://api-metrika.yandex.net' + path
        if params:
            url += '?' + urllib.parse.urlencode(params)
        request = urllib.request.Request(
            url, headers={'Authorization': 'OAuth ' + token, 'Accept-Language': 'en'},
            method='GET')
        entry = {'endpoint': path, 'query': name, 'attempt': 1, 'http': None}
        RESULT['requests'].append(entry)
        try:
            with opener.open(request, timeout=REQUEST_TIMEOUT) as response:
                status = response.status
                if response.geturl() != url:
                    raise Stop('RESPONSE_ENDPOINT_MISMATCH')
                raw = response.read(MAX_RESPONSE_BYTES + 1)
        except Stop:
            raise
        except urllib.error.HTTPError as error:
            status = error.code
            raw = error.read(MAX_RESPONSE_BYTES + 1)
            error.close()
        except Exception:
            raise Stop('HTTPS_READ_FAILED') from None
        entry['http'] = status
        if len(raw) > MAX_RESPONSE_BYTES:
            raise Stop('RESPONSE_TOO_LARGE', status)
        if status != 200:
            raise Stop('API_READ_REJECTED', status, error_codes(raw))
        try:
            body = json.loads(raw)
        except Exception:
            raise Stop('NON_JSON_RESPONSE', status) from None
        if not isinstance(body, dict) or body.get('errors') or body.get('error'):
            raise Stop('API_RESPONSE_ERROR', status, error_codes(raw))
        return body
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


def quality(body, row_count, has_dimensions):
    out = {key: body.get(key) for key in (
        'sampled', 'sample_share', 'sample_size', 'sample_space', 'data_lag',
        'total_rows', 'total_rows_rounded', 'contains_sensitive_data')}
    for key in ('sampled', 'total_rows_rounded', 'contains_sensitive_data'):
        if out[key] is not None and type(out[key]) is not bool:
            raise Stop('INVALID_QUALITY_METADATA')
    if out['sample_share'] is not None and not 0 <= finite_count(out['sample_share']) <= 1:
        raise Stop('INVALID_SAMPLING_METADATA')
    for key in ('sample_size', 'sample_space', 'data_lag'):
        if out[key] is not None:
            finite_count(out[key])
    total = out['total_rows']
    if type(total) is not int or total < 0:
        raise Stop('INVALID_TOTAL_ROWS')
    exact_rows = out['total_rows_rounded'] is False
    if exact_rows and total < row_count:
        raise Stop('INCONSISTENT_TOTAL_ROWS')
    rows_complete = (total == row_count if has_dimensions else total <= 1) if exact_rows else None
    unsampled = out['sampled'] is False and out['sample_share'] in (None, 1)
    out.update({'rows_returned': row_count, 'rows_complete': rows_complete,
                'unsampled': unsampled, 'data_lag_available': out['data_lag'] is not None})
    out['complete'] = rows_complete is True and unsampled and out['contains_sensitive_data'] is False
    return out

def report(get, name):
    params = PLAN[name].copy()
    validate_request(REPORT_PATH, params)
    body = get(REPORT_PATH, params)
    query = body.get('query')
    dimensions = params.get('dimensions', '').split(',') if params.get('dimensions') else []
    if (not isinstance(query, dict) or query.get('ids') != [COUNTER]
            or query.get('date1') != DATE1 or query.get('date2') != DATE2
            or query.get('timezone') != TIMEZONE
            or query.get('metrics') != list(METRICS) or query.get('dimensions', []) != dimensions
            or (query.get('filters') or None) != params.get('filters')
            or type(query.get('limit')) is not int or query['limit'] != int(params['limit'])
            or type(query.get('offset')) is not int or query['offset'] != 1):
        raise Stop('PROVIDER_QUERY_SCOPE_MISMATCH')
    totals, rows = body.get('totals'), body.get('data')
    if not isinstance(totals, list) or len(totals) != len(METRICS):
        raise Stop('METRICS_LENGTH_MISMATCH')
    totals = [finite_count(value) for value in totals]
    if not isinstance(rows, list) or len(rows) > int(params['limit']):
        raise Stop('REPORT_ROW_BOUND')
    validated = []
    for row in rows:
        if (not isinstance(row, dict) or not isinstance(row.get('dimensions'), list)
                or len(row['dimensions']) != len(dimensions)
                or not all(isinstance(d, dict) for d in row['dimensions'])
                or not isinstance(row.get('metrics'), list) or len(row['metrics']) != len(METRICS)):
            raise Stop('REPORT_ROW_SHAPE')
        metrics = [finite_count(value) for value in row['metrics']]
        counts = named_counts(dict(zip(METRICS, metrics)))
        validated.append({'dimensions': row['dimensions'], 'metrics': metrics, 'counts': counts})
    q = quality(body, len(rows), bool(dimensions))
    counts = named_counts(dict(zip(METRICS, totals)))
    if not dimensions and ((rows and validated[0]['metrics'] != totals) or (not rows and any(totals))):
        raise Stop('AGGREGATE_TOTALS_MISMATCH')
    return {
        'scope': name, 'request': params, 'quality': q, 'complete': q['complete'],
        'provider_totals': dict(zip(METRICS, totals)), 'counts': counts,
    }, validated

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

def public_pair(value):
    if not isinstance(value, str):
        return None
    match = re.fullmatch(r'p([1-9][0-9]{0,18})__max_([1-9][0-9]{0,18})', value)
    return {'key': value, 'post_id': match[1], 'offer_id': 'max_' + match[2]} if match else None

def project_pairs(rows):
    accepted, seen = [], set()
    rejected = {name: {'rows': 0, 'metrics_sum_nonadditive': {metric: 0 for metric in METRICS}}
                for name in ('other_parameter_branch', 'undefined_or_invalid_public_key')}
    for row in rows:
        dims = row['dimensions']
        reason = None
        if dims[0].get('name') != NAMESPACE or dims[1].get('name') != 'key':
            reason = 'other_parameter_branch'
            pair = None
        else:
            pair = public_pair(dims[2].get('name'))
            if pair is None:
                reason = 'undefined_or_invalid_public_key'
        if reason:
            rejected[reason]['rows'] += 1
            for metric, value in zip(METRICS, row['metrics']):
                rejected[reason]['metrics_sum_nonadditive'][metric] += value
        else:
            if pair['key'] in seen:
                raise Stop('DUPLICATE_PUBLIC_PAIR_ROW')
            seen.add(pair['key'])
            accepted.append({**pair, 'counts': row['counts']})
    sums = {metric: sum(row['metrics'][i] for row in rows
                       if row['dimensions'][0].get('name') == NAMESPACE
                       and row['dimensions'][1].get('name') == 'key'
                       and public_pair(row['dimensions'][2].get('name')) is not None)
            for i, metric in enumerate(METRICS)}
    return {'rows': accepted, 'accepted_public_keys': len(accepted), 'rejected': rejected,
            'accepted_row_metric_sums_nonadditive': sums,
            'rows_not_unique_visits': True, 'money_not_allocated_to_hotel': True,
            'pair_evidence': 'atomic_string_only_no_cross_product'}

def remember_error(stage, error):
    RESULT['errors'].append({'stage': stage, **error.info})
    if error.info['code'] in ('TIME_BUDGET', 'REQUEST_BUDGET') or error.info.get('http') in (401, 403):
        raise error

def update_coverage():
    def counts(name):
        value = RESULT['scopes'].get(name)
        return value['counts'] if value else None
    all_counts, tagged, max2 = map(counts, ('all_visits', 'tagged_visits', 'tagged_max2_visits'))
    total = all_counts['visits'] if all_counts else None
    observed = tagged['visits'] if tagged else None
    comparable = total is not None and observed is not None and 0 <= observed <= total
    if total is not None and observed is not None and not comparable:
        RESULT['warnings'].append({'stage': 'coverage', 'code': 'CROSS_REPORT_COUNTS_NOT_ALIGNED'})
    RESULT['coverage'] = {
        'all_counter_visits': total, 'pair_tagged_visits': observed,
        'tagged_share_of_counter_visits': observed / total if comparable and total else None,
        'tagged_max2_visits': max2['visits'] if max2 else None,
        'same_visit_max2_and_modeled_visits': max2['goals'][str(MODELED_GOAL)]['visits'] if max2 else None,
        'public_keys_returned': RESULT['groups'].get('pair_values', {}).get('accepted_public_keys'),
        'missing_queries': [name for name in PLAN if name not in RESULT['scopes']],
        'complete_queries': [name for name in PLAN if RESULT['scopes'].get(name, {}).get('complete') is True],
        'per_key_sum_is_unique_visit_denominator': False,
        'all_counter_visits_are_eligible_saves': False,
    }

def collect(get):
    read_goals(get)
    for name in PLAN:
        try:
            summary, rows = report(get, name)
            if name == 'tagged_max2_visits':
                summary['same_visit_overlap'] = overlap(summary['counts'])
            if name == 'pair_values':
                RESULT['groups'][name] = project_pairs(rows)
                if RESULT['groups'][name]['rejected']['undefined_or_invalid_public_key']['rows']:
                    RESULT['warnings'].append({'stage': name, 'code': 'PUBLIC_KEYS_UNRECOGNIZED'})
            RESULT['scopes'][name] = summary
            if not summary['complete']:
                RESULT['warnings'].append({'stage': name, 'code': 'SAMPLING_OR_COMPLETENESS_UNKNOWN'})
        except Stop as error:
            remember_error(name, error)
    update_coverage()
    RESULT['status'] = 'PARTIAL' if RESULT['errors'] or RESULT['warnings'] else 'COLLECTED'
    return RESULT

def serialized_result():
    raw = json.dumps(RESULT, ensure_ascii=False, allow_nan=False, separators=(',', ':'))
    if len(raw.encode('utf-8')) <= MAX_RETURN_BYTES:
        return raw
    return json.dumps({
        'schema_version': 1, 'namespace': NAMESPACE, 'counter_id': COUNTER,
        'date1': DATE1, 'date2': DATE2, 'timezone': TIMEZONE,
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
        update_coverage()
        RESULT['finished_at_utc'] = datetime.now(timezone.utc).isoformat()
        print(serialized_result())

reset()
if __name__ == '__main__':
    main()

