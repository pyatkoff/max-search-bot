#!/usr/bin/env python3
"""Synthetic corruption/import contracts plus the frozen actual-source bundle proof."""
import hashlib
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('region_import', ROOT / 'tools/import_yandex_regions.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
checks = 0


def check(ok, message):
    global checks
    checks += 1
    if not ok:
        raise AssertionError(message)


def rejected(call):
    try:
        call()
    except (ValueError, OSError, TypeError, KeyError, UnicodeError):
        check(True, 'expected rejection')
        return
    raise AssertionError('Invalid/partial input was accepted')


seed = {'regions': {'4': {'name': 'Белгород', 'departure_names': ['Белгород']},
                    '225': {'name': 'Россия', 'departure_names': []},
                    '2': {'name': 'Санкт-Петербург', 'departure_names': ['С.Петербург']}}}
row = {'ID': 123, 'UF_ID': 969, 'UF_LINE1': 'Северо-Запад', 'UF_LINE2': 'Ленинградская область',
       'UF_LINE3': 'Выборгский район', 'UF_LINE4': 'Выборг', 'UF_LINE5': ''}
hl = module.import_regions([row], {})
check('969' in hl and '123' not in hl, 'Advertising UF_ID must never become internal row ID')
check(hl['969']['name'] == 'Выборг' and hl['969']['hierarchy'] == [row['UF_LINE'+str(i)] for i in range(1, 5)], 'Deepest nonempty name and full ordered hierarchy')
check(hl['969']['departure_names'] == [], 'New HL7 labels do not invent a departure policy')
for envelope in [[row], {'rows': [row]}, {'GeoRegions': [row]}, {'result': [row]},
                 {'result': {'rows': [row]}}, {'result': {'GeoRegions': [row]}}]:
    check(module.import_regions(envelope, {}) == hl, 'Every supported envelope imports actual rows')
homonyms = module.import_regions([{'UF_ID': 4, 'UF_NAME': 'Белгород'}, {'UF_ID': 155820, 'UF_NAME': 'Белгород'}], seed)
check(homonyms['4']['departure_names'] == ['Белгород'] and homonyms['155820']['departure_names'] == [], 'A homonymous village must not inherit a city airport policy')
direct = module.import_regions({'result': {'GeoRegions': [
    {'GeoRegionId': 225, 'GeoRegionName': 'Россия', 'GeoRegionType': 'Country'},
    {'GeoRegionId': 2, 'GeoRegionName': 'Санкт-Петербург', 'GeoRegionType': 'City'}]}}, seed)
check(direct['225']['departure_names'] == [] and direct['2']['departure_names'] == ['С.Петербург'], 'Preserve Direct type and explicit alias policies')
for value in [True, 1.5, '0', 'abc', '{region_id}', '1' * 21]:
    check(module.normalized_id(value) == '', 'Reject invalid advertising ID')
for invalid in [None, False, 123, 'rows', [], {}, {'unexpected': [row]}, {'result': None},
                {'result': {}}, {'result': []}, {'rows': []}, {'GeoRegions': []},
                {'rows': {}}, {'rows': [row], 'GeoRegions': [row]},
                {'result': {'rows': [row], 'GeoRegions': [row]}},
                {'result': {'rows': [row]}, 'rows': [row]},
                {'error': {'error_code': 1000}, 'result': {'GeoRegions': [row]}},
                {'result': {'error': {}, 'rows': [row]}}, [{'ID': 213, 'UF_NAME': 'Москва'}],
                [{'UF_ID': 2, 'UF_NAME': 'A'}, {'UF_ID': 2, 'UF_NAME': 'A'}],
                [{'UF_ID': 2, 'UF_NAME': 'A'}, {'UF_ID': 2, 'UF_NAME': 'B'}],
                [{'UF_ID': 2, 'UF_NAME': 'A\x00B'}],
                [{'UF_ID': 2, 'UF_LINE1': 'Страна', 'UF_LINE3': 'Город'}]]:
    rejected(lambda invalid=invalid: module.import_regions(invalid, seed))

with tempfile.TemporaryDirectory(prefix='yandex-import-regression-') as temporary:
    directory = Path(temporary)
    output = directory / 'catalog.json'
    records = module.import_regions([row, {'UF_ID': 100, 'UF_NAME': 'Синтетический регион'},
                                     {'UF_ID': '99999999999999999999', 'UF_NAME': 'Длинный ID'}], seed)
    metadata = {'source': 'synthetic regression', 'verified_on': '2026-11-10'}
    manifest = module.write_catalog(output, records, metadata, 'suffix-2')
    check(module.load_catalog(output)['regions'] == records, 'Complete v2 bundle round-trip')
    check(set(manifest['buckets']) == {'00', '02', '04', '25', '69', '99'}, 'Suffix keys are fixed two decimal characters')
    flat = directory / 'flat' / 'catalog.json'
    module.write_catalog(flat, records, metadata)
    check(module.load_catalog(flat)['regions'] == records, 'Legacy flat v1 round-trip')
    manifest_bytes = output.read_bytes()
    second_output = directory / 'deterministic' / 'catalog.json'
    module.write_catalog(second_output, records, metadata, 'suffix-2')
    check(second_output.read_bytes() == manifest_bytes and all(
        (second_output.parent/'yandex_regions'/(suffix+'.json')).read_bytes() ==
        (output.parent/'yandex_regions'/(suffix+'.json')).read_bytes()
        for suffix in manifest['buckets']), 'Complete bundle generation is byte-deterministic')
    bucket = directory / 'yandex_regions' / '69.json'
    bucket_bytes = bucket.read_bytes()
    bucket.write_bytes(bucket_bytes.replace('Выборг'.encode(), 'Синтетика'.encode()))
    rejected(lambda: module.load_catalog(output))
    bucket.write_bytes(bucket_bytes)
    for change in [lambda doc: doc.update(region_count=doc['region_count']+1),
                   lambda doc: doc['buckets']['69'].update(count=2),
                   lambda doc: doc['buckets']['69'].update(count=True),
                   lambda doc: doc['buckets']['69'].update(sha256='0'*64),
                   lambda doc: doc['buckets'].update({'../69': doc['buckets'].pop('69')}),
                   lambda doc: doc.update(layout='arbitrary-path'),
                   lambda doc: doc.update(regions={})]:
        corrupted = json.loads(manifest_bytes)
        change(corrupted)
        output.write_bytes(module.json_bytes(corrupted))
        rejected(lambda: module.load_catalog(output))
    output.write_bytes(manifest_bytes)
    wrong_suffix = json.loads(bucket_bytes)
    wrong_suffix['regions']['970'] = wrong_suffix['regions'].pop('969')
    raw = module.json_bytes(wrong_suffix)
    bucket.write_bytes(raw)
    corrupted = json.loads(manifest_bytes)
    corrupted['buckets']['69']['sha256'] = hashlib.sha256(raw).hexdigest()
    output.write_bytes(module.json_bytes(corrupted))
    rejected(lambda: module.load_catalog(output))
    bucket.write_bytes(bucket_bytes)
    output.write_bytes(manifest_bytes)
    check(module.load_catalog(output)['regions'] == records, 'Accepted bundle restored after tamper fixtures')
    input_path, base_path = directory / 'export.json', directory / 'base.json'
    base_path.write_bytes(module.json_bytes({'version': 1, **seed}))
    command = [sys.executable, str(ROOT/'tools/import_yandex_regions.py'), str(input_path),
               '--base', str(base_path), '--output', str(output), '--layout', 'suffix-2',
               '--source', 'synthetic fixture', '--verified-on', '2026-11-10']
    for malformed in [{}, {'result': {}}, {'rows': []}, {'GeoRegions': []}]:
        input_path.write_text(json.dumps(malformed))
        failed = subprocess.run(command, capture_output=True, text=True)
        check(failed.returncode != 0 and 'Imported ' not in failed.stdout, 'No apparent successful base-only import')
        check(output.read_bytes() == manifest_bytes and bucket.read_bytes() == bucket_bytes, 'Malformed input never changes accepted manifest/buckets')
    input_path.write_text(json.dumps({'rows': [row]}))
    succeeded = subprocess.run(command, capture_output=True, text=True)
    check(succeeded.returncode == 0, 'CLI v2 import succeeded: ' + succeeded.stderr)
    imported = module.load_catalog(output)['regions']
    check(imported['969'] == hl['969'] and len(imported) == 4, 'CLI includes exactly supplied source and explicit seeds')
    input_path.write_text(json.dumps({'rows': [{'UF_ID': 970, 'UF_NAME': 'Новый регион'}]}))
    v2_command = list(command)
    v2_command[v2_command.index('--base') + 1] = str(output)
    succeeded = subprocess.run(v2_command, capture_output=True, text=True)
    imported_v2 = module.load_catalog(output)['regions']
    check(succeeded.returncode == 0 and len(imported_v2) == 5 and imported_v2['969'] == hl['969'], 'CLI reads every v2 base bucket before merging another region')
    unrelated_bucket = directory/'yandex_regions/25.json'
    unrelated_bytes = unrelated_bucket.read_bytes()
    before_corrupt = output.read_bytes()
    unrelated_bucket.write_bytes(unrelated_bytes + b' ')
    failed = subprocess.run(v2_command, capture_output=True, text=True)
    check(failed.returncode != 0 and output.read_bytes() == before_corrupt and unrelated_bucket.read_bytes() == unrelated_bytes + b' ', 'Corruption in an unrelated base bucket cannot become a partial successful import')
    unrelated_bucket.write_bytes(unrelated_bytes)
    # Reject an over-limit complete build before touching the accepted output.
    before = output.read_bytes()
    too_large = {str(100*i+1): {'name': 'x'*400, 'departure_names': [], 'hierarchy': []} for i in range(1, 3001)}
    rejected(lambda: module.write_catalog(output, too_large, metadata, 'suffix-2'))
    check(output.read_bytes() == before, 'Over-limit bucket does not overwrite accepted manifest')
    bad_provenance = directory / 'provenance.json'
    bad_provenance.write_text(json.dumps({'local_validation': 'PASS', 'summary': {'complete': True, 'hlblock_id': 7, 'row_count': 2}, 'validated': {'complete': True, 'row_count': 2}}))
    failed = subprocess.run(command + ['--provenance', str(bad_provenance)], capture_output=True, text=True)
    check(failed.returncode != 0 and output.read_bytes() == before, 'Source-count mismatch never publishes a partial bundle')

# Frozen source completeness and all 62 pre-existing policy arrays are independent
# fingerprints; source labels may differ from the old short seed display names.
actual = module.load_catalog(ROOT/'resources/yandex_regions.json')
check(actual['version'] == 2 and actual['region_count'] == 99708 and len(actual['buckets']) == 100, 'Actual full bundle counts')
provenance = actual['provenance']
check(provenance['source_rows'] == 99701 and provenance['seed_only_rows'] == 7 and provenance['seed_rows'] == 62, 'Actual source and seed completeness')
check(provenance['source_sha'] == '8275f0a19a0edc4c5600a871bb5b824837807fb5' and provenance['run_id'] == 42832333286 and provenance['captured_at'] == '2026-11-10T14:16:15Z', 'Exact original source provenance')
check(provenance['raw_ndjson_sha256'] == '8c718dc9bda5f46c470a09cf69b602c8eb2806f24c1e3700f6c19f8d7be3b6b9', 'Complete transport digest')
seed_ids = '1 2 4 5 7 8 9 10 11 12 13 14 15 16 20 21 22 23 24 25 28 30 33 35 36 37 38 39 41 42 43 45 47 48 49 50 51 53 54 56 62 63 64 65 66 67 75 76 77 172 191 192 193 195 197 213 225 239 973 1092 1104 1106'.split()
regions = actual['regions']
policies = {key: regions[key]['departure_names'] for key in seed_ids}
policy_digest = hashlib.sha256(json.dumps(policies, ensure_ascii=False, separators=(',', ':')).encode()).hexdigest()
check(policy_digest == 'f5e9b8ace2289ea4bc5ad94259675f6a91f4a43bf8adad967ed31cfccf1f99fc', 'All 62 original departure policies retained exactly')
check(sum(bool(record['hierarchy']) for record in regions.values()) == 99701, 'Exactly every HL7 source row preserves its hierarchy')
check(all(record['departure_names'] == [] for key, record in regions.items() if key not in policies), 'Every new HL7 ID has empty departure hints')
check({key for key, record in regions.items() if not record['hierarchy']} == {'172','197','225','239','1092','1104','1106'}, 'Exactly seven retained seed-only rows')
check(regions['969']['name'] == 'Выборг' and regions['10884']['name'] == 'Пушкин' and len(regions['10884']['hierarchy']) == 5, 'Actual depth-four and depth-five names')
check(regions['4']['name'] == regions['155820']['name'] == 'Белгород' and regions['4']['departure_names'] == ['Белгород'] and regions['155820']['departure_names'] == [], 'Actual homonymous village cannot inherit the city policy')
check(regions['400003']['name'] == 'Корал Москва' and regions['400003']['departure_names'] == [], 'Actual local label is preserved without claiming an airport')
print('Yandex region import regression passed (%d checks; 99708 actual records)' % checks)
