"""Validate an owner account NDJSON export on the runner, never on the server."""
from __future__ import annotations
import argparse
from datetime import date
from decimal import Decimal, InvalidOperation
import hashlib
import json
from pathlib import Path
import re
import sys

FIELDS = ('Date', 'CampaignId', 'AdGroupId', 'AdId', 'Query', 'Impressions', 'Clicks', 'Cost')
MAX_ROWS = 1000000
MAX_LINE_BYTES = 64 * 1024 * 1024


def digest(value):
    return hashlib.sha256(json.dumps(value, ensure_ascii=False, sort_keys=True,
        separators=(',', ':'), allow_nan=False).encode()).hexdigest()


def require(condition, code):
    if not condition:
        raise ValueError(code)


def validate_id(value, nullable=False):
    if nullable and value in {'', '--'}:
        return
    require(isinstance(value, str) and bool(re.fullmatch(r'[1-9][0-9]{0,18}', value)), 'STREAM_REPORT_ID_MALFORMED')
    require(int(value) <= 9223372036854775807, 'STREAM_REPORT_ID_OUT_OF_BOUNDS')


def validate_number(value, integer=False):
    if value in {'', '--'}:
        return
    require(isinstance(value, str) and len(value) <= 64, 'STREAM_REPORT_NUMBER_MALFORMED')
    try:
        parsed = Decimal(value)
    except InvalidOperation:
        raise ValueError('STREAM_REPORT_NUMBER_MALFORMED') from None
    require(parsed.is_finite() and 0 <= parsed <= Decimal('1e18'), 'STREAM_REPORT_NUMBER_OUT_OF_BOUNDS')
    require(not integer or parsed == parsed.to_integral_value(), 'STREAM_REPORT_COUNT_NOT_INTEGER')


def validate_row(row, ids, first, last):
    require(isinstance(row, dict) and set(row) == set(FIELDS), 'STREAM_REPORT_COLUMNS_MISMATCH')
    require(all(isinstance(v, str) for v in row.values()), 'STREAM_REPORT_CELL_TYPE')
    try:
        day = date.fromisoformat(row['Date'])
    except ValueError:
        raise ValueError('STREAM_REPORT_DATE_MALFORMED') from None
    require(day.isoformat() == row['Date'] and first <= row['Date'] <= last, 'STREAM_REPORT_DATE_OUT_OF_SCOPE')
    validate_id(row['CampaignId'])
    require(int(row['CampaignId']) in ids, 'STREAM_REPORT_CAMPAIGN_OUT_OF_SCOPE')
    validate_id(row['AdGroupId'], nullable=True)
    validate_id(row['AdId'], nullable=True)
    require(len(row['Query']) <= 4096, 'STREAM_REPORT_QUERY_OVERSIZED')
    for field in ('Impressions', 'Clicks', 'Cost'):
        validate_number(row[field], integer=field != 'Cost')
    return tuple(row[field] for field in FIELDS[:5])


def consolidate(stream):
    header, summary, before, after = None, None, None, None
    raw_rows, problems, seen = [], [], set()
    batches, current_pages, current_rows = [], [], 0
    campaign_rows = {}
    stream_lines = 0
    try:
        for line in stream:
            stream_lines += 1
            require(len(line.encode('utf-8')) <= MAX_LINE_BYTES, 'STREAM_LINE_TOO_LARGE')
            require(bool(line.strip()), 'STREAM_EMPTY_LINE')
            require(summary is None, 'STREAM_DATA_AFTER_FINAL_SUMMARY')
            try:
                event = json.loads(line)
            except ValueError:
                raise ValueError('STREAM_JSON_MALFORMED') from None
            require(isinstance(event, dict), 'STREAM_EVENT_NOT_OBJECT')
            kind = event.pop('event', None)
            if header is None:
                require(kind == 'header', 'STREAM_HEADER_REQUIRED')
                header = event
                ids = header.get('campaign_ids')
                require(isinstance(ids, list) and ids and len(ids) == len(set(ids))
                        and all(type(v) is int and v > 0 for v in ids), 'STREAM_SCOPE_MALFORMED')
                require(header.get('client_login') == 'anytour2024tp' and header.get('read_only') is True
                        and header.get('production_changes') is False, 'STREAM_AUTHORITY_MISMATCH')
                require(type(header.get('page_size')) is int and 1 <= header['page_size'] <= 20000,
                        'STREAM_PAGE_LIMIT_MALFORMED')
                require(header.get('batch_size') == 25 and header.get('max_rows') == MAX_ROWS,
                        'STREAM_LIMIT_CONTRACT_MISMATCH')
                first, last = header.get('date1'), header.get('date2')
                require(isinstance(first, str) and isinstance(last, str)
                        and date.fromisoformat(first).isoformat() == first
                        and date.fromisoformat(last).isoformat() == last and first <= last,
                        'STREAM_PERIOD_MALFORMED')
                expected_batches = [ids[i:i + 25] for i in range(0, len(ids), 25)]
                campaign_rows = {str(cid): 0 for cid in ids}
                continue
            if kind == 'configuration':
                snap, phase = event.get('snapshot'), event.get('phase')
                require(isinstance(snap, dict) and isinstance(snap.get('campaigns'), list), 'STREAM_CONFIG_MALFORMED')
                config_ids = [c.get('Id') for c in snap['campaigns']]
                require(all(type(cid) is int for cid in config_ids)
                        and len(config_ids) == len(set(config_ids)) and set(config_ids).issubset(ids),
                        'STREAM_CONFIG_SCOPE_MISMATCH')
                missing_ids = sorted(set(ids) - set(config_ids))
                require(snap.get('requested_ids') == ids and snap.get('missing_ids') == missing_ids
                        and snap.get('configuration_complete') == (not missing_ids),
                        'STREAM_CONFIG_MISSING_ID_CONTRACT')
                require(snap.get('sha256') == digest(snap['campaigns']), 'STREAM_CONFIG_DIGEST_MISMATCH')
                if phase == 'before':
                    require(before is None and not raw_rows and not batches, 'STREAM_CONFIG_BEFORE_ORDER')
                    before = snap
                elif phase == 'after':
                    require(before is not None and after is None and not current_pages, 'STREAM_CONFIG_AFTER_ORDER')
                    after = snap
                else:
                    raise ValueError('STREAM_CONFIG_PHASE_INVALID')
                continue
            if kind == 'page':
                require(before is not None and after is None, 'STREAM_PAGE_CONFIG_ORDER')
                index = len(batches)
                require(index < len(expected_batches) and event.get('batch_index') == index
                        and event.get('campaign_ids') == expected_batches[index], 'STREAM_PAGE_BATCH_SCOPE')
                data = event.get('data')
                require(isinstance(data, list) and len(data) <= header['page_size']
                        and event.get('rows') == len(data), 'STREAM_PAGE_ROW_COUNT_MISMATCH')
                require(event.get('offset') == len(current_pages) * header['page_size'], 'STREAM_PAGE_OFFSET_MISMATCH')
                require(not current_pages or current_pages[-1]['rows'] == header['page_size'], 'STREAM_PAGE_AFTER_TERMINATOR')
                require(event.get('rows_sha256') == digest(data), 'STREAM_PAGE_DIGEST_MISMATCH')
                require(len(raw_rows) + len(data) <= MAX_ROWS, 'STREAM_TOTAL_ROW_LIMIT')
                page_keys = set()
                for row in data:
                    key = validate_row(row, set(expected_batches[index]), first, last)
                    require(key not in seen and key not in page_keys, 'STREAM_DUPLICATE_QUERY_ROW')
                    page_keys.add(key)
                seen.update(page_keys)
                raw_rows.extend(data)
                current_rows += len(data)
                for row in data:
                    campaign_rows[row['CampaignId']] += 1
                current_pages.append({k: event[k] for k in ('offset', 'rows', 'request_id', 'rows_sha256')})
                continue
            if kind == 'batch_coverage':
                index = len(batches)
                require(index < len(expected_batches) and event.get('batch_index') == index
                        and event.get('campaign_ids') == expected_batches[index], 'STREAM_BATCH_SCOPE_MISMATCH')
                require(event.get('rows') == current_rows and event.get('pages') == current_pages,
                        'STREAM_BATCH_COUNT_OR_RECEIPT_MISMATCH')
                if event.get('complete') is True:
                    require(current_pages and current_pages[-1]['rows'] < header['page_size']
                            and event.get('problem') is None, 'STREAM_BATCH_FALSE_COMPLETENESS')
                else:
                    require(event.get('complete') is False and bool(event.get('problem')),
                            'STREAM_BATCH_PARTIAL_REASON_MISSING')
                batches.append(event)
                current_pages, current_rows = [], 0
                continue
            if kind == 'summary':
                summary = event
                require(summary.get('row_count') == len(raw_rows), 'STREAM_FINAL_ROW_COUNT_MISMATCH')
                require(summary.get('batches') == batches and not current_pages, 'STREAM_FINAL_BATCH_RECEIPT_MISMATCH')
                require(summary.get('config_before') == before and summary.get('config_after') == after,
                        'STREAM_FINAL_CONFIGURATION_MISMATCH')
                require(summary.get('status') in {'COLLECTED', 'PARTIAL', 'FAILED'}, 'STREAM_FINAL_STATUS_INVALID')
                if summary['status'] == 'COLLECTED':
                    require(len(batches) == len(expected_batches) and all(b.get('complete') for b in batches)
                            and before is not None and after is not None
                            and before['sha256'] == after['sha256']
                            and summary.get('config_unchanged') is True and not summary.get('problems'),
                            'STREAM_FINAL_FALSE_COMPLETENESS')
                continue
            raise ValueError('STREAM_EVENT_KIND_INVALID')
        require(header is not None, 'STREAM_HEADER_MISSING')
        require(summary is not None, 'STREAM_FINAL_SUMMARY_MISSING')
    except (ValueError, KeyError, TypeError, UnicodeError) as exc:
        code = str(exc)
        problems.append(code if re.fullmatch(r'STREAM_[A-Z_]+', code) else 'STREAM_VALIDATION_FAILED')
    result = {**(header or {}), **(summary or {})}
    result['raw_rows'] = raw_rows
    result['row_count'] = len(raw_rows)
    result['stream_lines'] = stream_lines
    result['stream_validated'] = not problems
    result['problems'] = sorted(set(problems + list(result.get('problems', []))))
    if problems:
        result['status'] = 'PARTIAL' if raw_rows else 'FAILED'
    pagination_complete = bool(not problems and summary and summary.get('status') == 'COLLECTED')
    result['coverage'] = {
        'scope': 'INVENTORY_DERIVED_CAMPAIGNS_WITH_SEARCH_ACTIVITY',
        'pagination_complete': pagination_complete, 'data_complete': pagination_complete,
        'all_market_queries': False,
        'pagination_not_atomic_snapshot': True, 'batches': batches,
        'campaigns': [{'campaign_id': int(cid), 'rows': count,
                       'complete': pagination_complete} for cid, count in campaign_rows.items()]}
    if before:
        result['campaign_timezones'] = {str(c['Id']): c.get('TimeZone') for c in before['campaigns']}
        known = {c['Id']: c for c in before['campaigns']}
        result['campaign_config_by_id'] = {
            str(cid): {**known[cid], 'configuration_available': True} if cid in known
            else {'Id': cid, 'State': 'UNKNOWN', 'Status': 'UNKNOWN', 'configuration_available': False}
            for cid in header['campaign_ids']}
        missing_before = before.get('missing_ids', [])
        missing_after = after.get('missing_ids', []) if after else header['campaign_ids']
        result['config_missing_ids'] = sorted(set(missing_before) | set(missing_after))
        result['config_stability_scope'] = 'RETURNED_CAMPAIGN_OBJECTS_NOT_MISSING_CAMPAIGNS'
        result['configuration_coverage'] = {
            'status': 'COMPLETE' if not result['config_missing_ids'] and after else 'PARTIAL',
            'complete': not result['config_missing_ids'] and after is not None,
            'requested_ids': header['campaign_ids'], 'missing_ids_before': missing_before,
            'missing_ids_after': missing_after, 'comparison_scope': result['config_stability_scope']}
    return result


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('stream', type=Path)
    p.add_argument('output', type=Path)
    args = p.parse_args()
    with args.stream.open(encoding='utf-8') as stream:
        result = consolidate(stream)
    args.output.write_text(json.dumps(result, ensure_ascii=False, separators=(',', ':'), allow_nan=False))
    print(json.dumps({k: result.get(k) for k in ('status', 'campaign_ids', 'row_count', 'problems', 'stream_validated')}, ensure_ascii=False))
    return 0 if result.get('status') == 'COLLECTED' else 2


if __name__ == '__main__':
    raise SystemExit(main())
