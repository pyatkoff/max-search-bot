#!/usr/bin/env python3
"""Generate a reviewable policy draft or validate its exact source anchors offline."""
import argparse
import hashlib
import json
from pathlib import Path
import re

from import_yandex_regions import load_catalog

ROOT = Path(__file__).resolve().parents[1]
MAX_RULE_BYTES = 262144
MACROS = {label: pair[0] for pair in [
    ('Центр', 'Центральный федеральный округ'),
    ('Северо-Запад', 'Северо-Западный федеральный округ'),
    ('Северный Кавказ', 'Северо-Кавказский федеральный округ'),
    ('Юг', 'Южный федеральный округ'),
    ('Поволжье', 'Приволжский федеральный округ'),
    ('Урал', 'Уральский федеральный округ'),
    ('Сибирь', 'Сибирский федеральный округ'),
    ('Дальний Восток', 'Дальневосточный федеральный округ'),
] for label in pair}
CITY_ANCHORS = {
    # Actual major-city nodes under the source's legacy Russian macro/province paths.
    '6': 'Калуга', '18': 'Петрозаводск', '19': 'Сыктывкар', '44': 'Ижевск',
    '46': 'Киров', '55': 'Тюмень', '57': 'Ханты-Мансийск', '68': 'Чита',
    '74': 'Якутск', '78': 'П.Камчатский', '80': 'Ю.Сахалинск', '194': 'Саратов',
    '235': 'Магнитогорск', '237': 'Новокузнецк', '968': 'Череповец',
    '976': 'Братск', '1091': 'Нижневартовск', '11091': 'Орск', '11230': 'Новый Уренгой',
}
PROVINCE_OPTIONS = {
    ('Центр', 'Москва и Московская область'): ['Москва'],
    ('Поволжье', 'Республика Башкортостан'): ['Уфа'],
    ('Сибирь', 'Алтайский край'): ['Барнаул'],
    ('Дальний Восток', 'Республика Бурятия'): ['Улан-Удэ'],
    ('Сибирь', 'Республика Хакасия'): ['Абакан'],
    ('Северный Кавказ', 'Чеченская Республика'): ['Грозный'],
    ('Сибирь', 'Кемеровская область (Кузбасс)'): ['Кемерово', 'Новокузнецк'],
    ('Урал', 'Челябинская область'): ['Челябинск', 'Магнитогорск'],
    ('Сибирь', 'Иркутская область'): ['Иркутск', 'Братск'],
    ('Поволжье', 'Оренбургская область'): ['Оренбург', 'Орск'],
    ('Северо-Запад', 'Вологодская область'): ['Вологда', 'Череповец'],
    ('Поволжье', 'Республика Татарстан'): ['Казань', 'Нижнекамск', 'Наб.Челны'],
    ('Урал', 'Ханты-Мансийский автономный округ - Югра'): ['Сургут', 'Нижневартовск', 'Ханты-Мансийск'],
    ('Юг', 'Краснодарский край'): ['Краснодар', 'Сочи'],
    ('Северный Кавказ', 'Ставропольский край'): ['Ставрополь', 'Мин.Воды'],
}


def city_key(name):
    name = name.lower().replace('ё', 'е')
    name = {'санкт-петербург': 'с.петербург', 'санкт петербург': 'с.петербург',
            'нижний новгород': 'н.новгород', 'минеральные воды': 'мин.воды',
            'набережные челны': 'наб.челны', 'петропавловск-камчатский': 'п.камчатский',
            'южно-сахалинск': 'ю.сахалинск'}.get(name, name)
    return re.sub(r'[\s.]+', '', name)


def fallback_table():
    return json.loads((ROOT/'departure_fallbacks.json').read_text())


def valid_id(value):
    return isinstance(value, str) and re.fullmatch(r'[1-9][0-9]{0,19}', value) is not None


def valid_labels(value, maximum):
    return isinstance(value, list) and len(value) <= maximum and all(
        isinstance(label, str) and label and label == label.strip()
        and len(label.encode('utf-8')) <= 400 and not re.search(r'[\x00-\x1f\x7f]', label)
        for label in value)


def baseline(regions):
    # Empty hints on new HL7 records are not global prohibitions. The retained
    # country policy is explicit; all other original policies are nonempty.
    return {key: row for key, row in regions.items() if row['departure_names'] or key == '225'}


def validate_rules(rules, catalog, manifest_sha256):
    if not isinstance(rules, dict) or type(rules.get('version')) is not int or rules['version'] != 1:
        raise ValueError('Unsupported policy version')
    if rules.get('catalog_manifest_sha256') != manifest_sha256:
        raise ValueError('Policy is not bound to the current catalog manifest')
    exact, scopes = rules.get('exact'), rules.get('scopes')
    if not isinstance(exact, dict) or len(exact) > 1000 or not isinstance(scopes, list) or len(scopes) > 1000:
        raise ValueError('Invalid bounded policy tables')
    regions = catalog['regions']
    for region_id, entry in exact.items():
        if not valid_id(region_id) or not isinstance(entry, dict) or not valid_labels(entry.get('departure_names'), 8):
            raise ValueError('Invalid exact departure policy')
        if region_id not in regions or entry.get('anchor_hierarchy') != regions[region_id].get('hierarchy', []):
            raise ValueError('Exact policy must retain its full actual-source anchor')
        if not valid_labels(entry.get('anchor_hierarchy'), 5):
            raise ValueError('Invalid exact anchor hierarchy')
    active_names = rules.get('active_catalog', {}).get('departure_names', [])
    if not valid_labels(active_names, 256):
        raise ValueError('Invalid active-catalog names')
    if 'active_catalog' in rules:
        capture = rules['active_catalog']
        if type(capture.get('row_count')) is not int or capture['row_count'] != len(active_names) or not active_names \
                or not isinstance(capture.get('rows_sha256'), str) or not re.fullmatch(r'[a-f0-9]{64}', capture['rows_sha256']):
            raise ValueError('Invalid active-catalog snapshot receipt')
    active_keys = {city_key(name) for name in active_names}
    if len(active_keys) != len(active_names):
        raise ValueError('Ambiguous canonical active departure names')
    fallbacks = fallback_table()
    for region_id, row in baseline(regions).items():
        old = row['departure_names']
        new = exact.get(region_id, {}).get('departure_names')
        if new is None or (not old and new != []) or new[:len(old)] != old:
            raise ValueError('Existing departure policy changed: ' + region_id)
        extras = new[len(old):]
        approved = [name for primary in old for name in fallbacks.get(primary, [])]
        path = row.get('hierarchy', [])
        if len(path) >= 2 and path[0] in MACROS:
            approved += PROVINCE_OPTIONS.get((MACROS[path[0]], path[1]), [])
        if extras and (not active_names or any(city_key(name) in active_keys for name in old)
                       or len(new) > 3 or any(name not in approved for name in extras)):
            raise ValueError('Only existing fallbacks or reviewed same-province options may extend an inactive exact policy: ' + region_id)
    retained = baseline(regions)
    for region_id, entry in exact.items():
        if region_id not in retained and any(city_key(name) not in active_keys for name in entry['departure_names']):
            raise ValueError('New exact cities need active-catalog evidence')
    known_keys = active_keys | {city_key(name) for row in retained.values() for name in row['departure_names']}
    prefixes = {}
    for scope in scopes:
        if not isinstance(scope, dict) or not valid_id(scope.get('anchor_id')) or not valid_labels(scope.get('departure_names'), 8):
            raise ValueError('Invalid scoped departure policy')
        prefix, anchor = scope.get('prefix'), scope.get('anchor_hierarchy')
        minimum = {'province': 2, 'city': 3}.get(scope.get('kind'), 6)
        if not valid_labels(prefix, 5) or len(prefix) < minimum or not valid_labels(anchor, 5) or anchor[:len(prefix)] != prefix:
            raise ValueError('A geographic scope must be an exact anchored prefix, not a broad root')
        source = regions.get(scope['anchor_id'])
        if source is None or source.get('hierarchy', []) != anchor:
            raise ValueError('Scope anchor differs from the actual source')
        key = tuple(prefix)
        names = scope['departure_names']
        if any(city_key(name) not in known_keys for name in names):
            raise ValueError('Scoped targets need retained-policy or active-catalog evidence')
        if key in prefixes and prefixes[key] != names:
            raise ValueError('Conflicting equally specific policies')
        prefixes[key] = names
    return rules


def generate_draft(catalog, manifest_sha256, active_catalog=None):
    regions = catalog['regions']
    exact = {key: {'departure_names': row['departure_names'], 'anchor_hierarchy': row.get('hierarchy', [])}
             for key, row in baseline(regions).items()}
    active_names = []
    if active_catalog is not None:
        if active_catalog.get('complete') is not True or active_catalog.get('consistent_snapshot') is not True:
            raise ValueError('Active catalog must be a complete consistent read-only snapshot')
        active_names = [row['name'] for row in active_catalog['rows'] if row['is_active'] == 1]
        if len(active_names) != active_catalog['row_count'] or not valid_labels(active_names, 256):
            raise ValueError('Invalid active departure snapshot')
        active_keys = {city_key(name) for name in active_names}
        fallbacks = fallback_table()
        for entry in exact.values():
            names = list(entry['departure_names'])
            if names and not any(city_key(name) in active_keys for name in names):
                for primary in list(names):
                    for candidate in fallbacks.get(primary, []):
                        if len(names) < 3 and city_key(candidate) not in {city_key(name) for name in names}:
                            names.append(candidate)
            entry['departure_names'] = names
        for region_id, target in CITY_ANCHORS.items():
            row = regions[region_id]
            path = row.get('hierarchy', [])
            if len(path) < 3 or path[0] not in set(MACROS.values()) or city_key(row['name']) != city_key(target) or city_key(target) not in active_keys:
                raise ValueError('Curated exact city is not supported by its actual path and active catalog: ' + region_id)
            exact[region_id] = {'departure_names': [target], 'anchor_hierarchy': path}
        for entry in exact.values():
            path, names = entry['anchor_hierarchy'], entry['departure_names']
            if not names or any(city_key(name) in active_keys for name in names) or len(path) < 2 or path[0] not in MACROS:
                continue
            for candidate in PROVINCE_OPTIONS.get((MACROS[path[0]], path[1]), []):
                if len(names) < 3 and city_key(candidate) in active_keys and city_key(candidate) not in {city_key(name) for name in names}:
                    names.append(candidate)
    provinces, cities = {}, {}
    for region_id, entry in exact.items():
        path, names = entry['anchor_hierarchy'], entry['departure_names']
        if not names or len(path) < 2 or path[0] not in MACROS:
            continue
        province = (MACROS[path[0]], path[1])
        targets = provinces.setdefault(province, [])
        targets.extend(name for name in names if name not in targets)
        if len(path) >= 3:
            city = (MACROS[path[0]], *path[1:])
            if city in cities and cities[city] != names:
                raise ValueError('Conflicting source city policies')
            cities[city] = names
    if active_catalog is not None:
        retained_keys = {city_key(name) for row in baseline(regions).values() for name in row['departure_names']}
        for province, targets in PROVINCE_OPTIONS.items():
            if any(city_key(name) not in active_keys | retained_keys for name in targets):
                raise ValueError('Curated province target is not active: ' + str(province))
            provinces[province] = targets
    scopes = {}

    def add(kind, prefix, names, region_id, path):
        key = tuple(prefix)
        candidate = {'kind': kind, 'prefix': list(prefix), 'anchor_id': region_id,
                     'anchor_hierarchy': path, 'departure_names': names}
        previous = scopes.get(key)
        if previous and previous['departure_names'] != names:
            raise ValueError('Conflicting draft scopes')
        if previous is None or (len(path), int(region_id)) < (len(previous['anchor_hierarchy']), int(previous['anchor_id'])):
            scopes[key] = candidate

    for region_id, row in regions.items():
        path = row.get('hierarchy', [])
        if len(path) < 2 or path[0] not in MACROS:
            continue
        normalized = (MACROS[path[0]], *path[1:])
        if normalized[:2] in provinces:
            add('province', path[:2], provinces[normalized[:2]], region_id, path)
        for length in range(3, len(path)+1):
            if normalized[:length] in cities:
                add('city', path[:length], cities[normalized[:length]], region_id, path)
    result = {'version': 1, 'catalog_manifest_sha256': manifest_sha256,
              'source': 'Reviewed rules over the retained Tour-Max HL7 snapshot; not live airport availability',
              'exact': exact, 'scopes': sorted(scopes.values(), key=lambda scope: (len(scope['prefix']), scope['prefix']))}
    if active_catalog is not None:
        result['active_catalog'] = {key: active_catalog[key] for key in ('captured_at', 'row_count', 'rows_sha256')}
        result['active_catalog']['departure_names'] = active_names
    return validate_rules(result, catalog, manifest_sha256)


def coverage(rules, catalog):
    """Offline coverage after the same exact-name active filtering used by runtime."""
    active = {city_key(name): name for name in rules.get('active_catalog', {}).get('departure_names', [])}
    prefixes = {tuple(scope['prefix']): scope for scope in rules['scopes']}
    reasons = {'mapped': 0, 'no_policy': 0, 'empty_policy': 0, 'no_active_departure': 0}
    candidate_counts, used_names, examples = {}, set(), {}
    old_mapped = 0
    for region_id, row in catalog['regions'].items():
        old_mapped += any(city_key(name) in active for name in row['departure_names'])
        entry = rules['exact'].get(region_id)
        if entry is None:
            path = row.get('hierarchy', [])
            entry = next((prefixes[tuple(path[:length])] for length in range(len(path), 1, -1)
                          if tuple(path[:length]) in prefixes), None)
        names = None if entry is None else entry['departure_names']
        candidates = list(dict.fromkeys(active[city_key(name)] for name in names or [] if city_key(name) in active))[:3]
        reason = 'mapped' if candidates else 'no_policy' if names is None else 'empty_policy' if not names else 'no_active_departure'
        reasons[reason] += 1
        if candidates:
            candidate_counts[len(candidates)] = candidate_counts.get(len(candidates), 0) + 1
            used_names.update(candidates)
        if region_id in {'969', '10758', '10884', '155820', '237', '235', '968', '1091', '1', '225'}:
            examples[region_id] = {'name': row['name'], 'hierarchy': row.get('hierarchy', []),
                                   'policy_names': names, 'active_candidates': candidates}
    return {'catalog_manifest_sha256': rules['catalog_manifest_sha256'], 'catalog_region_count': len(catalog['regions']),
            'active_catalog': rules.get('active_catalog'), 'exact_policies': len(rules['exact']),
            'scopes': len(rules['scopes']), 'after_active_filter': reasons,
            'baseline_active_mapped_regions': old_mapped, 'new_active_mapped_regions': reasons['mapped']-old_mapped,
            'mapped_candidate_count_distribution': candidate_counts, 'referenced_active_departures': sorted(used_names),
            'unreferenced_active_departures': sorted(set(active.values())-used_names), 'examples': examples}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--catalog', type=Path, default=ROOT/'resources/yandex_regions.json')
    parser.add_argument('--rules', type=Path, default=ROOT/'resources/regional_departure_rules.json')
    parser.add_argument('--generate-draft', action='store_true', help='Explicitly replace the draft; ordinary invocation only validates reviewed rules')
    parser.add_argument('--active-catalog', type=Path, help='Verified complete current departure snapshot; targets remain names, never provider IDs')
    parser.add_argument('--coverage-output', type=Path, help='Save offline coverage against the recorded active snapshot')
    args = parser.parse_args()
    catalog = load_catalog(args.catalog)
    digest = hashlib.sha256(args.catalog.read_bytes()).hexdigest()
    if args.generate_draft:
        active = json.loads(args.active_catalog.read_text()) if args.active_catalog else None
        rules = generate_draft(catalog, digest, active)
        raw = (json.dumps(rules, ensure_ascii=False, indent=2)+'\n').encode('utf-8')
        if len(raw) > MAX_RULE_BYTES:
            raise ValueError('Departure policy exceeds 256 KiB')
        args.rules.write_bytes(raw)
    if args.rules.stat().st_size > MAX_RULE_BYTES:
        raise ValueError('Departure policy exceeds 256 KiB')
    rules = validate_rules(json.loads(args.rules.read_text()), catalog, digest)
    if args.coverage_output:
        args.coverage_output.write_text(json.dumps(coverage(rules, catalog), ensure_ascii=False, indent=2)+'\n')
    print(json.dumps({'exact': len(rules['exact']), 'scopes': len(rules['scopes']),
                      'province_scopes': sum(scope['kind']=='province' for scope in rules['scopes']),
                      'city_scopes': sum(scope['kind']=='city' for scope in rules['scopes'])}))


if __name__ == '__main__':
    main()
