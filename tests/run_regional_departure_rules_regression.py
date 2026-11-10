#!/usr/bin/env python3
"""Validate real source anchors and corruption boundaries without any live lookup."""
import copy
from collections import Counter
import hashlib
import json
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT/'tools'))
import regional_departure_rules as module

catalog_path = ROOT/'resources/yandex_regions.json'
catalog = module.load_catalog(catalog_path)
digest = hashlib.sha256(catalog_path.read_bytes()).hexdigest()
rules_path = ROOT/'resources/regional_departure_rules.json'
rules = json.loads(rules_path.read_text())
module.validate_rules(rules, catalog, digest)
assert rules_path.stat().st_size <= module.MAX_RULE_BYTES
assert len(module.baseline(catalog['regions'])) == 62
draft = module.generate_draft(catalog, digest)
assert len(draft['exact']) == 62
assert sum(scope['kind'] == 'province' for scope in draft['scopes']) == 107
assert sum(scope['kind'] == 'city' for scope in draft['scopes']) == 77
for region_id, previous in module.baseline(catalog['regions']).items():
    old = previous['departure_names']
    new = rules['exact'][region_id]['departure_names']
    assert new[:len(old)] == old
    if not old:
        assert new == []
for scope in rules['scopes']:
    anchor = catalog['regions'][scope['anchor_id']]['hierarchy']
    assert scope['anchor_hierarchy'] == anchor
    assert anchor[:len(scope['prefix'])] == scope['prefix']
    assert scope['prefix'][0] in module.MACROS, 'No country, foreign or agency-root policy is generated'


def rejected(candidate, sha=digest):
    try:
        module.validate_rules(candidate, catalog, sha)
    except ValueError:
        return
    raise AssertionError('Unanchored or conflicting policy accepted')


rejected(rules, 'f'*64)
invalid = copy.deepcopy(rules)
invalid['exact']['1']['departure_names'] = ['Брянск']
rejected(invalid)
invalid = copy.deepcopy(rules)
del invalid['exact']['225']
rejected(invalid)
invalid = copy.deepcopy(rules)
invalid['exact']['225']['departure_names'] = ['Москва']
rejected(invalid)
invalid = copy.deepcopy(rules)
invalid['exact']['2']['departure_names'].reverse()
rejected(invalid)
assert rules['exact']['191']['departure_names'] == ['Брянск', 'Москва']
assert rules['exact']['21']['departure_names'] == ['Вологда', 'Череповец']
assert rules['exact']['39']['departure_names'] == ['Ростов-на-Дону', 'Краснодар', 'Мин.Воды']
assert rules['exact']['22']['departure_names'] == ['Калининград'], 'An active own city does not acquire a blanket fallback'
stats = module.coverage(rules, catalog)
assert stats['examples']['155820']['active_candidates'] == ['Москва']
assert stats['examples']['237']['active_candidates'] == ['Новокузнецк']
assert stats['examples']['235']['active_candidates'] == ['Магнитогорск']
assert stats['examples']['968']['active_candidates'] == ['Череповец']
invalid = copy.deepcopy(rules)
invalid['exact']['21']['departure_names'] = ['Вологда', 'Москва']
rejected(invalid)
assert stats['new_active_mapped_regions'] > 0

# Reconstruct the installed policy before this extension, and authenticate its
# complete ID/path registry independently of the new hub choices. The active
# snapshot is held identical on both sides of every comparison below.
capture = rules['active_catalog']
active_snapshot = {key: capture[key] for key in ('captured_at', 'row_count', 'rows_sha256')}
active_snapshot.update(complete=True, consistent_snapshot=True,
                       rows=[{'name': name, 'is_active': 1} for name in capture['departure_names']])
rebuilt = module.generate_draft(catalog, digest, active_snapshot)
assert (json.dumps(rebuilt, ensure_ascii=False, indent=2)+'\n').encode() == rules_path.read_bytes(), \
    'The reviewed registry must rebuild deterministically from its exact source and active snapshot'
assert len(catalog['regions']) == 99708
previous = module.generate_draft(catalog, digest, active_snapshot, include_regional_hubs=False)
previous_registry = {key: previous[key] for key in ('exact', 'scopes')}
assert hashlib.sha256(json.dumps(previous_registry, sort_keys=True, ensure_ascii=False,
                                 separators=(',', ':')).encode()).hexdigest() == \
    '8bf10656644264ffce0c9d29431f25dddd720fbc5279e1cf0e509564237d6cee'
active = {module.city_key(name): name for name in capture['departure_names']}


def candidates(policy, prefixes, region_id, row):
    entry = policy['exact'].get(region_id)
    path = row.get('hierarchy', [])
    if entry is None:
        entry = next((prefixes[tuple(path[:length])] for length in range(len(path), 1, -1)
                      if tuple(path[:length]) in prefixes), None)
    names = [] if entry is None else entry['departure_names']
    return list(dict.fromkeys(active[module.city_key(name)] for name in names
                              if module.city_key(name) in active))[:3]


old_prefixes = {tuple(scope['prefix']): scope for scope in previous['scopes']}
new_prefixes = {tuple(scope['prefix']): scope for scope in rules['scopes']}
old_mapped = 0
gains = Counter()
for region_id, row in catalog['regions'].items():
    old = candidates(previous, old_prefixes, region_id, row)
    new = candidates(rules, new_prefixes, region_id, row)
    if old:
        old_mapped += 1
        assert new == old, ('Existing ordered candidates changed', region_id, old, new)
    elif new:
        path = row.get('hierarchy', [])
        assert region_id in module.EXACT_HUB_OPTIONS or len(path) >= 2 and \
            (module.MACROS.get(path[0]), path[1]) in module.REGIONAL_HUB_OPTIONS
        gains[tuple(path[:2])] += 1
assert old_mapped == 58656
assert sum(gains.values()) == 11740
assert stats['after_active_filter'] == {'mapped': 70396, 'no_policy': 29311,
                                      'empty_policy': 1, 'no_active_departure': 0}
assert not set(module.PROVINCE_OPTIONS) & set(module.REGIONAL_HUB_OPTIONS)
expected = {
    '7': ['Ярославль', 'Москва'], '10': ['Москва'], '11': ['Москва'],
    '12': ['Москва'], '13': ['Москва'], '14': ['Москва'], '15': ['Москва'],
    '192': ['Москва'], '24': ['С.Петербург'], '41': ['Казань', 'Чебоксары'],
    '53': ['Тюмень', 'Екатеринбург'], '1092': ['Владикавказ', 'Грозный'],
    '1104': ['Мин.Воды', 'Ставрополь'], '20627': ['Казань', 'Чебоксары'],
    '11157': ['Тюмень', 'Екатеринбург'], '11016': ['Мин.Воды', 'Ставрополь'],
    '20179': ['Владикавказ', 'Грозный'], '11005': ['Краснодар'],
    '11394': ['Хабаровск'], '10243': ['Хабаровск'], '79': ['Хабаровск'],
    '11404': ['Хабаровск'], '11458': ['Хабаровск', 'Владивосток'],
    '10251': ['Хабаровск', 'Владивосток'], '10903': ['Архангельск'],
    '10176': ['Архангельск'], '11331': ['Новосибирск'], '11320': ['Новосибирск'],
    '20177': ['Волгоград'],
}
for region_id, names in expected.items():
    assert candidates(rules, new_prefixes, region_id, catalog['regions'][region_id]) == names, region_id
for region_id in ['225', '3', '17', '26', '40', '52', '59', '73',
                  '300012', '400003', '207928', '26990']:
    assert candidates(rules, new_prefixes, region_id, catalog['regions'][region_id]) == [], region_id
assert candidates(rules, new_prefixes, '166243', catalog['regions']['166243']) == ['Калуга'], \
    'The Kaluga village named Sevastopol retains its own actual-source province policy'
for region_id, unreviewed in [('53', ['Курган', 'Москва']), ('1092', ['Назрань', 'Москва']),
                             ('14', ['Тверь', 'Владикавказ'])]:
    invalid = copy.deepcopy(rules)
    invalid['exact'][region_id]['departure_names'] = unreviewed
    rejected(invalid)
tampered = {'regions': dict(catalog['regions'])}
tampered['regions']['1092'] = dict(catalog['regions']['1092'], name='Unrelated homonym')
try:
    module.validate_rules(rules, tampered, digest)
except ValueError:
    pass
else:
    raise AssertionError('A seed ID cannot gain hubs after its retained city identity changes')
invalid = copy.deepcopy(rules)
invalid['scopes'][0]['anchor_hierarchy'] = ['Invented parent']
rejected(invalid)
invalid = copy.deepcopy(rules)
invalid['scopes'][0]['anchor_id'] = '99999999999999999999'
rejected(invalid)
invalid = copy.deepcopy(rules)
invalid['scopes'][0]['prefix'] = invalid['scopes'][0]['prefix'][:1]
rejected(invalid)
invalid = copy.deepcopy(rules)
duplicate = copy.deepcopy(invalid['scopes'][0])
duplicate['departure_names'] = ['Conflicting airport city']
invalid['scopes'].append(duplicate)
rejected(invalid)
invalid = copy.deepcopy(rules)
invalid['scopes'][0]['kind'] = 'city'
rejected(invalid)
print('Regional departure rules: all 99708 regions compared; 58656 ordered candidate lists retained; '
      '11740 reviewed additions; real anchors, 62 retained policies and corruption checks passed')
