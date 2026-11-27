"""Project one held pipeline notification to metadata suitable for a job log."""
from datetime import datetime, timezone
import hashlib
import json
import os
from pathlib import Path
import re
import sys
import time


ROOT = Path('/var/www/anytoour/data/www/app.anytoour.ru')
KINDS = {'bootstrap', 'failure', 'recovery'}
STATES = {'prepared', 'sending', 'unknown'}


def clock(value, now):
    if type(value) is not int or not 946684800 <= value <= now:
        raise ValueError('invalid_delivery_clock')
    return datetime.fromtimestamp(value, timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')


def project(state, source, now):
    pending = state.get('pending')
    previous = state.get('last_notification')
    result = {'application_sha': source, 'pending': None, 'last_confirmed_at': None}
    if previous is not None:
        result['last_confirmed_at'] = clock(previous['confirmed_at'], now)
    if pending is not None:
        intent = pending['intent_id']
        result['pending'] = {
            'kind': pending['kind'], 'state': pending['state'],
            'prepared_at': clock(pending['prepared_at'], now),
            'intent_ref': hashlib.sha256(intent.encode('ascii')).hexdigest()[:16],
            'matches_last_confirmation': previous is not None and previous.get('intent_id') == intent,
        }
    return result


def read(source):
    if Path.cwd().resolve() != ROOT:
        raise ValueError('unexpected_checkout')
    sys.path.insert(0, str(ROOT / 'tools'))
    import modeled_conversion_monitor as monitor
    import modeled_conversion_monitor_local as local
    import modeled_conversion_monitor_runtime as runtime

    if local._source_sha() != source:
        raise ValueError('application_changed')
    reader = runtime.DurableMonitorRuntime(local.PRIVATE_DIR, lambda: None, lambda *args: None, role='pipeline')
    fd = reader._directory()
    try:
        state = reader._read(fd, runtime.STATE_FILE, runtime.MAX_STATE)
        monitor.validate_state(state)
        monitor.role_guard(state, 'pipeline')
        if state['initialized'] is not True or state['verified'] is not True:
            raise ValueError('pipeline_uninitialized')
        result = project(state, source, int(time.time()))
    finally:
        os.close(fd)
    if local._source_sha() != source:
        raise ValueError('application_changed')
    return result


def valid_time(value):
    return isinstance(value, str) and re.fullmatch(r'20\d\d-\d\d-\d\dT\d\d:\d\d:\d\dZ', value) is not None


def validate(value, source):
    if not isinstance(value, dict) or set(value) != {'application_sha', 'pending', 'last_confirmed_at'} \
            or value['application_sha'] != source:
        raise ValueError('unexpected_receipt')
    if value['last_confirmed_at'] is not None and not valid_time(value['last_confirmed_at']):
        raise ValueError('invalid_confirmation')
    pending = value['pending']
    if pending is not None and (not isinstance(pending, dict)
            or set(pending) != {'kind', 'state', 'prepared_at', 'intent_ref', 'matches_last_confirmation'}
            or pending['kind'] not in KINDS or pending['state'] not in STATES
            or not valid_time(pending['prepared_at']) or not isinstance(pending['intent_ref'], str)
            or re.fullmatch(r'[0-9a-f]{16}', pending['intent_ref']) is None
            or type(pending['matches_last_confirmation']) is not bool):
        raise ValueError('invalid_pending_metadata')
    return value


if __name__ == '__main__':
    try:
        operation, source = sys.argv[1:]
        if re.fullmatch(r'[0-9a-f]{40}', source) is None:
            raise ValueError('invalid_application')
        if operation == 'read':
            output = read(source)
        elif operation == 'verify':
            raw = sys.stdin.buffer.read(1025)
            if len(raw) > 1024:
                raise ValueError('receipt_bound')
            output = json.loads(raw)
        else:
            raise ValueError('invalid_operation')
        clean = validate(output, source)
        print(json.dumps(clean, sort_keys=True, separators=(',', ':')))
    except Exception:
        sys.exit('notification_metadata_unavailable')
