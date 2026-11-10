#!/usr/bin/env python3
"""Build the shared offline geography from a Direct GeoRegions or HL7 JSON export."""
import argparse
import json
import re
from pathlib import Path


def normalized_id(value):
    if isinstance(value, bool) or not isinstance(value, (str, int)):
        return ''
    value = str(value).strip()
    return value.lstrip('0') if re.fullmatch(r'[0-9]{1,20}', value) else ''


def import_regions(document, base):
    if isinstance(document, dict):
        document = document.get('result', document)
        document = document.get('GeoRegions', document.get('rows', [])) if isinstance(document, dict) else document
    if not isinstance(document, list) or len(document) > 200000:
        raise ValueError('Expected a bounded GeoRegions array or HL7 rows array')
    regions = dict(base.get('regions', {}))
    observed = {}
    for row in document:
        if not isinstance(row, dict):
            raise ValueError('Region rows must be objects')
        # HL7.ID is Bitrix's internal row ID; the advertising ID is UF_ID.
        region_id = normalized_id(row.get('UF_ID', row.get('GeoRegionId')))
        name = row.get('UF_NAME', row.get('GeoRegionName', ''))
        if not region_id or not isinstance(name, str) or not name.strip():
            raise ValueError('Each row needs UF_ID/GeoRegionId and UF_NAME/GeoRegionName')
        name = name.strip()
        if len(name.encode('utf-8')) > 400 or re.search(r'[\x00-\x1f\x7f]', name):
            raise ValueError('Invalid region name')
        if region_id in observed and observed[region_id] != name:
            raise ValueError('Conflicting names for Yandex region ' + region_id)
        observed[region_id] = name
        previous = regions.get(region_id, {})
        kind = row.get('GeoRegionType', '')
        # Names are matched against active departure records, never used as IDs.
        candidates = [name] if not kind or kind == 'City' else []
        regions[region_id] = {
            'name': name,
            'departure_names': previous.get('departure_names', candidates),
        }
    return dict(sorted(regions.items(), key=lambda pair: int(pair[0])))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('input', type=Path)
    parser.add_argument('--output', required=True, type=Path)
    parser.add_argument('--base', type=Path, help='Preserve existing aliases and explicit regional policy')
    parser.add_argument('--source', required=True, help='Origin of the authorised export; no credentials')
    parser.add_argument('--verified-on', required=True, help='YYYY-MM-DD date of the exported dictionary')
    args = parser.parse_args()
    if not re.fullmatch(r'\d{4}-\d{2}-\d{2}', args.verified_on):
        parser.error('--verified-on must be YYYY-MM-DD')
    if args.input.stat().st_size > 32 * 1024 * 1024:
        parser.error('Region export exceeds 32 MiB')
    base = json.loads(args.base.read_text()) if args.base else {}
    result = {'version': 1, 'source': args.source, 'verified_on': args.verified_on,
              'regions': import_regions(json.loads(args.input.read_text()), base)}
    args.output.write_text(json.dumps(result, ensure_ascii=False, indent=2) + '\n')
    print('Imported %d Yandex regions' % len(result['regions']))


if __name__ == '__main__':
    main()
