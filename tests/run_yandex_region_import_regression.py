#!/usr/bin/env python3
import importlib.util
from pathlib import Path

spec = importlib.util.spec_from_file_location('region_import', Path(__file__).parents[1] / 'tools/import_yandex_regions.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

hl = module.import_regions([{'ID': 123, 'UF_ID': 969, 'UF_NAME': 'Выборг'}], {})
assert '969' in hl and '123' not in hl, 'HL row ID must never replace the Yandex ID'
direct = module.import_regions({'result': {'GeoRegions': [
    {'GeoRegionId': 225, 'GeoRegionName': 'Россия', 'GeoRegionType': 'Country'},
    {'GeoRegionId': 2, 'GeoRegionName': 'Санкт-Петербург', 'GeoRegionType': 'City'},
]}}, {'regions': {'2': {'departure_names': ['С.Петербург']}}})
assert direct['225']['departure_names'] == [], 'A country must not choose an airport'
assert direct['2']['departure_names'] == ['С.Петербург'], 'Preserve explicit alias/policy'
for value in [True, 1.5, '0', 'abc', '{region_id}', '1' * 21]:
    assert module.normalized_id(value) == ''
for invalid in [
    [{'ID': 213, 'UF_NAME': 'Москва'}],
    [{'UF_ID': 2, 'UF_NAME': 'A'}, {'UF_ID': 2, 'UF_NAME': 'B'}],
    [{'UF_ID': 2, 'UF_NAME': 'A\x00B'}],
]:
    try:
        module.import_regions(invalid, {})
    except ValueError:
        pass
    else:
        raise AssertionError('Ambiguous or invalid exports must fail before output')
print('Yandex region import regression passed')
