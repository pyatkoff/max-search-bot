#!/usr/bin/env python3
"""Build the offline region catalog from an authorised Direct or actual HL7 export."""
import argparse
import datetime
import hashlib
import json
import os
import re
import tempfile
from pathlib import Path

MAX_REGIONS = 250000
MAX_MANIFEST_BYTES = 65536
MAX_BUCKET_BYTES = 1048576


def normalized_id(value):
    if isinstance(value, bool) or not isinstance(value, (str, int)):
        return ''
    value = str(value).strip()
    return value.lstrip('0') if re.fullmatch(r'[0-9]{1,20}', value) else ''


def label(value):
    if not isinstance(value, str) or not value.strip():
        raise ValueError('Expected a non-empty region label')
    value = value.strip()
    if len(value.encode('utf-8')) > 400 or re.search(r'[\x00-\x1f\x7f]', value):
        raise ValueError('Invalid region label')
    return value


def validated_regions(regions, maximum=MAX_REGIONS):
    if not isinstance(regions, dict) or not regions or len(regions) > maximum:
        raise ValueError('Expected a non-empty bounded region dictionary')
    clean = {}
    for region_id, record in regions.items():
        if not isinstance(region_id, str) or normalized_id(region_id) != region_id or not isinstance(record, dict):
            raise ValueError('Invalid region ID/record')
        name = label(record.get('name'))
        aliases = record.get('departure_names')
        if not isinstance(aliases, list) or len(aliases) > 10:
            raise ValueError('Invalid explicit departure policy')
        hierarchy = record.get('hierarchy', [])
        if not isinstance(hierarchy, list) or len(hierarchy) > 5:
            raise ValueError('Invalid region hierarchy')
        aliases, hierarchy = [label(x) for x in aliases], [label(x) for x in hierarchy]
        if hierarchy and hierarchy[-1] != name:
            raise ValueError('Region name must be the deepest hierarchy label')
        clean[region_id] = {'name': name, 'departure_names': aliases, 'hierarchy': hierarchy}
    return dict(sorted(clean.items(), key=lambda pair: int(pair[0])))


def hl7_hierarchy(row):
    path, ended = [], False
    for index in range(1, 6):
        value = row.get('UF_LINE' + str(index))
        if value is None or value == '':
            ended = True
        else:
            value = label(value)
            if ended:
                raise ValueError('HL7 hierarchy contains a gap')
            path.append(value)
    return path


def exported_rows(document):
    """Reject missing/empty payloads instead of mistaking the base for an import."""
    if isinstance(document, dict):
        if 'error' in document:
            raise ValueError('An error response is not a region export')
        if 'result' in document:
            if 'GeoRegions' in document or 'rows' in document:
                raise ValueError('Ambiguous region export envelope')
            document = document['result']
        if isinstance(document, dict):
            if 'error' in document:
                raise ValueError('An error response is not a region export')
            keys = [key for key in ('GeoRegions', 'rows') if key in document]
            if len(keys) != 1:
                raise ValueError('Expected exactly one GeoRegions or HL7 rows array')
            document = document[keys[0]]
    if not isinstance(document, list) or not document or len(document) > 200000:
        raise ValueError('Expected a non-empty bounded GeoRegions array or HL7 rows array')
    return document


def import_regions(document, base):
    document = exported_rows(document)
    prior = base.get('regions', {})
    regions = validated_regions(prior) if prior else {}
    observed = set()
    for row in document:
        if not isinstance(row, dict):
            raise ValueError('Region rows must be objects')
        # HL7.ID is Bitrix's internal row ID; the advertising ID is UF_ID.
        is_hl7 = 'UF_ID' in row
        region_id = normalized_id(row.get('UF_ID') if is_hl7 else row.get('GeoRegionId'))
        if not region_id:
            raise ValueError('Each row needs a positive UF_ID/GeoRegionId')
        if region_id in observed:
            raise ValueError('Duplicate Yandex region ID ' + region_id)
        observed.add(region_id)
        hierarchy = hl7_hierarchy(row) if is_hl7 else []
        name = hierarchy[-1] if hierarchy else label(row.get('UF_NAME') if is_hl7 else row.get('GeoRegionName'))
        previous = regions.get(region_id)
        kind = row.get('GeoRegionType', '')
        # Names are matched against active departure records, never used as IDs.
        # HL7 has homonymous cities/villages and local labels. Identity is not
        # airport eligibility: only the prior explicit policies survive.
        candidates = [] if is_hl7 else ([name] if not kind or kind == 'City' else [])
        regions[region_id] = {
            'name': name,
            'departure_names': previous['departure_names'] if previous else candidates,
            'hierarchy': hierarchy,
        }
    return validated_regions(regions)


def read_json(path, maximum):
    path = Path(path)
    if path.is_symlink() or path.stat().st_size > maximum:
        raise ValueError('Catalog file is a link or exceeds its size limit')
    return json.loads(path.read_text(encoding='utf-8'))


def load_catalog(path):
    """Read legacy v1 or every count/hash-checked bucket in a suffix-2 bundle."""
    path = Path(path)
    document = read_json(path, MAX_MANIFEST_BYTES)
    if not isinstance(document, dict) or type(document.get('version')) is not int:
        raise ValueError('Catalog manifest must be an object')
    if document.get('version') == 1:
        return {**document, 'regions': validated_regions(document.get('regions'))}
    if document.get('version') != 2 or document.get('layout') != 'suffix-2' or 'regions' in document:
        raise ValueError('Unsupported region catalog layout')
    expected, buckets = document.get('region_count'), document.get('buckets')
    if type(expected) is not int or not 0 < expected <= MAX_REGIONS or not isinstance(buckets, dict) or not 0 < len(buckets) <= 100:
        raise ValueError('Invalid catalog count/buckets')
    directory = path.parent / 'yandex_regions'
    if directory.is_symlink():
        raise ValueError('Bucket directory must not be a link')
    regions, total = {}, 0
    for suffix, descriptor in sorted(buckets.items()):
        if not isinstance(suffix, str) or not re.fullmatch(r'[0-9]{2}', suffix) or not isinstance(descriptor, dict):
            raise ValueError('Invalid bucket descriptor')
        count, digest = descriptor.get('count'), descriptor.get('sha256')
        if type(count) is not int or not 0 < count <= MAX_REGIONS or not isinstance(digest, str) or not re.fullmatch(r'[0-9a-f]{64}', digest):
            raise ValueError('Invalid bucket count/hash')
        bucket_path = directory / (suffix + '.json')
        if bucket_path.is_symlink() or bucket_path.stat().st_size > MAX_BUCKET_BYTES:
            raise ValueError('Bucket file is a link or exceeds 1 MiB')
        raw = bucket_path.read_bytes()
        if hashlib.sha256(raw).hexdigest() != digest:
            raise ValueError('Bucket checksum mismatch')
        bucket = json.loads(raw)
        if not isinstance(bucket, dict) or set(bucket) != {'regions'}:
            raise ValueError('Invalid bucket payload')
        records = validated_regions(bucket['regions'])
        if len(records) != count or any(region_id[-2:].zfill(2) != suffix for region_id in records):
            raise ValueError('Bucket count/suffix mismatch')
        if set(regions).intersection(records):
            raise ValueError('Repeated ID across buckets')
        regions.update(records)
        total += count
        if total > expected:
            raise ValueError('Catalog count mismatch')
    if total != expected or len(regions) != expected:
        raise ValueError('Catalog count mismatch')
    return {**document, 'regions': dict(sorted(regions.items(), key=lambda pair: int(pair[0])))}


def json_bytes(document):
    return (json.dumps(document, ensure_ascii=False, separators=(',', ':')) + '\n').encode('utf-8')


def write_catalog(output, regions, metadata, layout='flat'):
    """Validate and stage the whole bundle before replacing accepted output."""
    output = Path(output)
    regions, payloads = validated_regions(regions), {}
    if layout == 'flat':
        manifest = {**metadata, 'version': 1, 'regions': regions}
    elif layout == 'suffix-2':
        buckets = {}
        for region_id, record in regions.items():
            buckets.setdefault(region_id[-2:].zfill(2), {})[region_id] = record
        descriptors = {}
        for suffix, records in sorted(buckets.items()):
            raw = json_bytes({'regions': records})
            if len(raw) > MAX_BUCKET_BYTES:
                raise ValueError('Bucket exceeds 1 MiB')
            payloads[suffix] = raw
            descriptors[suffix] = {'count': len(records), 'sha256': hashlib.sha256(raw).hexdigest()}
        manifest = {**metadata, 'version': 2, 'layout': layout, 'region_count': len(regions), 'buckets': descriptors}
    else:
        raise ValueError('Unsupported catalog layout')
    manifest_bytes = json_bytes(manifest)
    if len(manifest_bytes) > MAX_MANIFEST_BYTES:
        raise ValueError('Manifest exceeds 64 KiB; use suffix-2 for a full dictionary')
    output.parent.mkdir(parents=True, exist_ok=True)
    if output.is_symlink() or (output.parent / 'yandex_regions').is_symlink():
        raise ValueError('Output catalog must not be a link')
    with tempfile.TemporaryDirectory(prefix='.region-import-', dir=output.parent) as temporary:
        stage = Path(temporary)
        staged_manifest = stage / output.name
        staged_manifest.write_bytes(manifest_bytes)
        if payloads:
            (stage / 'yandex_regions').mkdir()
            for suffix, raw in payloads.items():
                (stage / 'yandex_regions' / (suffix + '.json')).write_bytes(raw)
        if load_catalog(staged_manifest)['regions'] != regions:
            raise ValueError('Staged catalog did not round-trip')
        target = output.parent / 'yandex_regions'
        moved_previous, installed_buckets = False, False
        try:
            if payloads:
                if target.exists():
                    os.replace(target, stage / 'previous_buckets')
                    moved_previous = True
                os.replace(stage / 'yandex_regions', target)
                installed_buckets = True
            os.replace(staged_manifest, output)
        except OSError:
            if installed_buckets:
                os.replace(target, stage / 'failed_buckets')
            if moved_previous:
                os.replace(stage / 'previous_buckets', target)
            raise
    return manifest


def source_provenance(path, input_path, rows, seed_path, seed):
    """Bind the import to the independently recovered complete HL7 transport."""
    manifest = read_json(path, MAX_MANIFEST_BYTES)
    if not isinstance(manifest, dict) or manifest.get('repository') != 'pyatkoff/tour-max':
        raise ValueError('Expected actual Tour-Max source provenance')
    summary, validated = manifest.get('summary', {}), manifest.get('validated', {})
    if not isinstance(summary, dict) or not isinstance(validated, dict):
        raise ValueError('Invalid source validation metadata')
    if manifest.get('local_validation') != 'PASS' or summary.get('complete') is not True or validated.get('complete') is not True:
        raise ValueError('Expected a successfully validated complete HL7 source')
    if summary.get('hlblock_id') != 7 or summary.get('row_count') != len(rows) or validated.get('row_count') != len(rows):
        raise ValueError('Source count/HL7 mismatch')
    digest = hashlib.sha256(Path(input_path).read_bytes()).hexdigest()
    if manifest.get('rawrows_json_sha256') != digest:
        raise ValueError('Input rows differ from the validated source')
    source_sha = manifest.get('source_sha', '')
    if not re.fullmatch(r'[0-9a-f]{40}', source_sha) or summary.get('workflow_source_sha') != source_sha:
        raise ValueError('Invalid source commit provenance')
    raw_path = Path(path).parent / 'raw.ndjson'
    if raw_path.is_symlink() or raw_path.stat().st_size > 64 * 1024 * 1024:
        raise ValueError('Invalid recovered source stream')
    raw = raw_path.read_bytes()
    raw_hash = hashlib.sha256(raw).hexdigest()
    if raw_hash != summary.get('raw_sha256') or raw_hash != validated.get('raw_sha256'):
        raise ValueError('Recovered source checksum mismatch')
    header = json.loads(raw.split(b'\n', 1)[0])
    if header.get('hlblock_id') != 7 or header.get('declared_count') != len(rows):
        raise ValueError('Recovered source header/count mismatch')
    captured_at = header.get('exported_at', '')
    datetime.datetime.strptime(captured_at, '%Y-%m-%dT%H:%M:%SZ')
    source_ids = {normalized_id(row.get('UF_ID')) for row in rows}
    if '' in source_ids or len(source_ids) != len(rows):
        raise ValueError('Invalid/duplicate source IDs')
    for key in ('run_id', 'run_attempt', 'job_id'):
        if type(manifest.get(key)) is not int or manifest[key] <= 0:
            raise ValueError('Invalid source run provenance')
    return {'kind': 'actual_tour_max_hl7', 'repository': manifest['repository'], 'source_sha': source_sha,
            'run_id': manifest['run_id'], 'run_attempt': manifest['run_attempt'], 'job_id': manifest['job_id'],
            'captured_at': captured_at, 'raw_ndjson_sha256': raw_hash, 'rawrows_json_sha256': digest,
            'source_rows': len(rows), 'seed_rows': len(seed.get('regions', {})),
            'seed_only_rows': len(set(seed.get('regions', {})) - source_ids),
            'seed_sha256': hashlib.sha256(Path(seed_path).read_bytes()).hexdigest() if seed_path else None,
            'name_rule': 'deepest_nonempty_UF_LINE1_to_UF_LINE5',
            'scope': 'actual_HL7_labels_including_local_entries; not_an_officialness_or_airport_mapping',
            'new_hl7_departure_policy': 'empty; existing_seed_policies_preserved'}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('input', type=Path)
    parser.add_argument('--output', required=True, type=Path)
    parser.add_argument('--base', type=Path, help='Preserve existing aliases and explicit regional policy')
    parser.add_argument('--layout', choices=('flat', 'suffix-2'), default='flat')
    parser.add_argument('--provenance', type=Path, help='Validated HL7 transport manifest next to raw.ndjson')
    parser.add_argument('--source', required=True, help='Origin of the authorised export; no credentials')
    parser.add_argument('--verified-on', required=True, help='YYYY-MM-DD date of the exported dictionary')
    args = parser.parse_args()
    try:
        datetime.date.fromisoformat(args.verified_on)
        if not re.fullmatch(r'\d{4}-\d{2}-\d{2}', args.verified_on):
            raise ValueError('--verified-on must be YYYY-MM-DD')
        rows = exported_rows(read_json(args.input, 32 * 1024 * 1024))
        base = load_catalog(args.base) if args.base else {}
        regions = import_regions(rows, base)
        metadata = {'source': label(args.source), 'verified_on': args.verified_on}
        if args.provenance:
            metadata['provenance'] = source_provenance(args.provenance, args.input, rows, args.base, base)
            if metadata['provenance']['captured_at'][:10] != args.verified_on:
                raise ValueError('Capture date differs from --verified-on')
        write_catalog(args.output, regions, metadata, args.layout)
    except (ValueError, OSError, KeyError, TypeError, UnicodeError) as error:
        parser.error(str(error))
    print('Imported %d region records using %s' % (len(regions), args.layout))


if __name__ == '__main__':
    main()
