#!/usr/bin/env python3
"""Validate real source anchors and corruption boundaries without any live lookup."""
import copy
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
print('Regional departure rules: real anchors, all 62 retained policies and malformed-policy checks passed')
