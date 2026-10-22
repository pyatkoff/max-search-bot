#!/usr/bin/env python3
"""Build the expanded AnyTour funnel report and validated private archive.

Run only when both reviewed hotel and thematic JSON inputs are final. This is a
packaging pass: it never recomputes the full query analysis or publishes data.
"""
from __future__ import annotations

import argparse
from datetime import datetime, timezone
import hashlib
import html
import json
import os
from pathlib import Path
import tempfile
import zipfile

ROOT = Path(__file__).resolve().parent
PERIOD = ['2026-09-23', '2026-10-22']
REPORT = 'anytour-demand-20261022.html'
ARCHIVE = 'anytour-demand-20261022.zip'
PRIORITIES = 'anytour-demand-priorities-20261022.json'
PROVENANCE = 'source-provenance.json'
INPUTS = {
    'queries': 'account_queries_20261022.json',
    'inventory': 'account_inventory_20261022.json',
    'inventory_summary': 'account_inventory_summary.json',
    'analysis': 'account_analysis/account-topic-candidates.json',
    'sales_seed': 'top500_funnel_seed.json',
    'hotel_recipes': 'expanded_hotel_recipes.json',
    'hotel_shortlist': 'expanded_hotel_shortlist.json',
    'themes': 'thematic_selection_candidates.json',
    'query_artifacts': 'account_query_artifact.json',
}
ARCHIVE_NAMES = {
    'queries': 'source-query-report.json', 'inventory': 'source-account-inventory.json',
    'inventory_summary': 'account-inventory-summary.json', 'analysis': 'full-query-analysis.json',
    'sales_seed': 'top500-funnel-seed.json', 'hotel_recipes': 'expanded-hotel-recipes.json',
    'hotel_shortlist': 'expanded-hotel-shortlist.json', 'themes': 'thematic-selection-candidates.json',
    'query_artifacts': 'query-source-artifacts.json',
}


def require(condition, message):
    if not condition:
        raise ValueError(message)


def load(path):
    return json.loads(path.read_text(encoding='utf-8'))


def sha256(path):
    h = hashlib.sha256()
    with path.open('rb') as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b''):
            h.update(block)
    return h.hexdigest()


def json_bytes(value):
    return (json.dumps(value, ensure_ascii=False, indent=2, allow_nan=False) + '\n').encode('utf-8')


def e(value):
    return html.escape(str(value), quote=True)


def number(value):
    return '—' if value is None else f'{value:,}'.replace(',', ' ')


def metric(record, name):
    # A null total remains unknown; never turn missing evidence into a zero.
    if record.get(name + '_missing_rows', 0):
        return str(number(record.get(name + '_known_sum', 0))) + ' + ?'
    return number(record.get(name, record.get(name + '_known_sum')))


def table(headings, rows, table_id=None):
    identity = '' if table_id is None else ' id="' + e(table_id) + '"'
    return '<div class="scroll"><table' + identity + '><thead><tr>' + ''.join(
        '<th>' + e(x) + '</th>' for x in headings) + '</tr></thead><tbody>' + ''.join(
        '<tr>' + ''.join('<td>' + cell + '</td>' for cell in row) + '</tr>' for row in rows
    ) + '</tbody></table></div>'


def hotel_rows(candidates):
    for h in candidates:
        aliases = [q['raw_aliases'][0] for q in h.get('query_aliases', []) if q.get('raw_aliases')][:2]
        yield [e(h['label']), 'Добавлен' if h['expansion_status'] == 'NEW_OBSERVED_NAME' else 'Был в 87',
               e(metric(h, 'impressions')), e(metric(h, 'clicks')),
               e(metric(h['metrics_by_lifecycle']['ACTIVE'], 'clicks')),
               e('; '.join(aliases))]


def render(summary, hotels, themes, seed, query_sha):
    candidates = hotels['candidates']
    expansion = hotels['expansion_summary']
    total = summary['query_export']['broad_search_metrics']
    topic_rows = []
    ordered = sorted(themes['candidates'], key=lambda t: t['candidate_key'] != 'EG_heated_pool')
    for t in ordered:
        priority = 'Редакционный приоритет' if t['candidate_key'] == 'EG_heated_pool' else 'Наблюдаемый спрос'
        facts = t['eligibility_checks']
        checks = '<ul class="checks">' + ''.join('<li>' + e(x) + '</li>' for x in facts[:3]) + '</ul>'
        if len(facts) > 3:
            checks += '<details><summary>Ещё условия</summary><ul class="checks">' + ''.join(
                '<li>' + e(x) + '</li>' for x in facts[3:]) + '</ul></details>'
        examples = '; '.join(x['query'] for x in t.get('source_examples', []) if x.get('qualified'))[:280]
        qualified = t['qualified_need_metrics']
        topic_rows.append([e(t['proposed_selection_title']) + '<small>' + e(t['country']) + ' · ' + e(t['need']) + '</small>',
                           e(priority), e(metric(t['metrics'], 'impressions') + ' / ' + metric(qualified, 'impressions')),
                           e(metric(t['metrics'], 'clicks') + ' / ' + metric(qualified, 'clicks')),
                           checks + ('<small>Запросы: ' + e(examples) + '</small>' if examples else '')])
    hotel_headings = ['Название из запросов', 'Очередь', 'Показы наших объявлений', 'Клики',
                      'Клики РК ON сейчас', 'Наблюдаемые формулировки']
    seed_rows = [[e(x['rank']), e(x['country']), e(x['hotel'])] for x in seed['ranked_name_rows']]
    scope_rows = [[e(label), e(metric(summary['query_export']['metrics_by_current_lifecycle'][key], 'impressions')),
                   e(metric(summary['query_export']['metrics_by_current_lifecycle'][key], 'clicks'))]
                  for key, label in [('ACTIVE', 'ON сейчас'), ('ARCHIVED', 'Архив'),
                                     ('INACTIVE', 'Остановлены'), ('UNKNOWN', 'Метаданные недоступны')]]
    return '''<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>AnyTour · гостиничные посты и тематические подборки</title>
<style>
:root{font-family:system-ui,-apple-system,Segoe UI,sans-serif;color:#20313d;background:#f3f6f7;font-size:15px;line-height:1.5}*{box-sizing:border-box}body{margin:0}main{max-width:1240px;margin:auto;padding:32px 24px 60px}header{padding:22px 28px;background:#173c43;color:white;border-radius:14px}h1{font-size:30px;line-height:1.2;margin:4px 0 14px}h2{font-size:23px;margin:0 0 12px}p{margin:10px 0}section{background:white;border:1px solid #dce5e8;border-radius:14px;padding:24px;margin-top:20px}.eyebrow{font-size:12px;letter-spacing:.09em;text-transform:uppercase;color:#bde4db}.lead{max-width:1050px}.cards{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-top:20px}.card{background:#eaf2f1;padding:15px 18px;border-radius:9px}.card strong{display:block;font-size:25px}.card span{font-size:13px}.note{color:#596c77;font-size:13px}.callout{border-left:4px solid #2b8278;padding:10px 15px;background:#eef6f3}.scroll{overflow:auto;margin:16px 0}table{width:100%;border-collapse:collapse;font-size:13px}th{text-align:left;background:#edf3f5;color:#3b505d}th,td{padding:10px 12px;border-bottom:1px solid #e0e7ea;vertical-align:top}td:nth-child(1){min-width:210px}td:last-child{min-width:250px}small{display:block;color:#61717b;margin-top:6px;line-height:1.4}summary{cursor:pointer;font-weight:600;padding:9px 0}details{margin-top:10px}input{width:100%;padding:11px 13px;font:inherit;border:1px solid #bccbd1;border-radius:7px}.checks{padding-left:17px;margin:0}.checks li{margin-bottom:4px}code{overflow-wrap:anywhere;font-size:12px}.route{font-size:18px;font-weight:650}footer{padding:18px 4px;color:#647783;font-size:12px}@media(max-width:700px){main{padding:18px 12px 35px}section,header{padding:18px}.cards{grid-template-columns:1fr}h1{font-size:25px}}
</style><main><header><div class="eyebrow">AnyTour · очередь контента · 22 октября 2026</div><h1>Расширенная очередь подготовки постов и подборок</h1>
<p class="lead">Первая очередь — гостиничные посты и темы «страна × потребность»: подогреваемый бассейн, пляж, семейный состав, питание и сезон. Для Египта с подогревом задан редакционный приоритет; его наблюдаемые метрики показаны отдельно.</p></header>''' + (
        '<div class="cards"><div class="card"><strong>' + e(len(candidates)) + '</strong><span>наблюдаемых кандидатов по названиям: '
        + e(hotels['baseline_hotel_count']) + ' прежних + ' + e(hotels['new_hotel_candidate_count']) + ' добавлено</span></div>'
        + '<div class="card"><strong>' + e(len(ordered)) + '</strong><span>тем для конкретных подборок</span></div>'
        + '<div class="card"><strong>500 строк</strong><span>дополнительный пул из существующего ТОП500 продаж</span></div></div>'
        + '<section><h2>Что измерено</h2><p><b>Показы наших объявлений за 23.09–22.10.2026</b> в раскрытых поисковых запросах Директа: '
        + e(metric(total, 'impressions')) + ' показов, ' + e(metric(total, 'clicks')) + ' кликов, '
        + e(summary['query_export']['requested_campaigns']) + ' РК с поисковой активностью.</p>'
        + '<p class="note">Полной частотности Wordstat ещё нет. Эти показатели зависят от нашего охвата и настроек рекламы; это не число запросов по всему Яндексу. 22 октября — включённый текущий день на момент сбора. Архивные и остановленные РК сохранены; текущий статус не описывает статус в день показа.</p>'
        + '<details><summary>Охват и статусы кампаний</summary>' + table(['Текущий статус РК', 'Показы наших объявлений', 'Клики'], scope_rows)
        + '<p class="note">У ' + e(summary['query_export']['unknown_search_campaigns']) + ' поисковых РК метаданные недоступны. Их запросы учтены. Полный запросный источник содержит '
        + e(number(summary['query_export']['rows'])) + ' API-строк; исходные строки с одинаковыми видимыми измерениями сохранены.</p></details></section>'
        + '<section><h2>Конкретные отели: первые 30</h2><p>Всего ' + e(len(candidates)) + ' проверенных по тексту запросов кандидатов, в том числе '
        + e(expansion['with_clicks']) + ' с кликами и ' + e(expansion['without_clicks']) + ' без кликов. По совпавшим именным формулировкам — '
        + e(metric(hotels['selected_metrics'], 'impressions')) + ' показов наших объявлений и ' + e(metric(hotels['selected_metrics'], 'clicks'))
        + ' кликов. Порядок: клики РК ON сейчас, затем клики всех РК.</p>'
        + '<p class="callout">Это названия из спроса. Сопоставление с локальным каталогом, переименования и различия корпусов ещё требуют проверки. Свойства отеля из запроса не выводятся.</p>'
        + table(hotel_headings, hotel_rows(candidates[:30]))
        + '<details><summary>Все ' + e(len(candidates)) + ' кандидатов по названиям</summary><input type="search" placeholder="Найти название или формулировку" aria-label="Поиск по кандидатам отелей" data-filter="all-hotels">'
        + table(hotel_headings, hotel_rows(candidates), 'all-hotels') + '</details>'
        + '<p class="note">Метрики относятся к совпавшим формулировкам. Сравнительные запросы про несколько объектов вынесены из индивидуальных гостиничных метрик. Набор пока не исчерпывает все гостиничные названия в аккаунте.</p></section>'
        + '<section><h2>Подборки «страна × потребность»</h2><p>Узкий пост должен отвечать на конкретный выбор туриста. Египет с подогревом стоит первым по редакционному решению, даже при небольшом наблюдаемом трафике. Темы пересекаются; их метрики не складываются. В метриках показаны все совпадения / строки с явной потребностью после исключения неоднозначных формулировок и вставок офера.</p>'
        + table(['Подборка', 'Основание приоритета', 'Показы наших объявлений: все / явная потребность', 'Клики: все / явная потребность', 'Что подтвердить перед подборкой'], topic_rows)
        + '<p class="note">Формулировка запроса подтверждает интерес к условию. Включать конкретный отель можно после проверки фактов: сезона, дат работы услуги, типа бассейна/пляжа/питания, ограничений и источника с датой проверки.</p></section>'
        + '<section><h2>ТОП500 продаж: дополнительный пул</h2><p>В существующем источнике ' + e(len(seed['ranked_name_rows'])) + ' строк названий и '
        + e(seed['legacy_id_count']) + ' уникальных legacy Tourvisor ID. Это отдельные представления: ID не сопоставлялись с названиями по позиции. Пересечения с найденными названиями и переименования ещё не проверены.</p>'
        + '<p class="note">Это не 500 новых уникальных отелей. Ранг отражает исходный список продаж и не является рейтингом качества. Пул полезен для расширения контента и следующей проверки частотности; метрики Директа ему автоматически не приписываются.</p>'
        + '<details><summary>Все 500 строк исходного списка продаж</summary><input type="search" placeholder="Найти страну или название" aria-label="Поиск по ТОП500" data-filter="top500">'
        + table(['Ранг в источнике', 'Страна в источнике', 'Название в источнике'], seed_rows, 'top500') + '</details></section>'
        + '<section><h2>Следующий рабочий шаг</h2><p>Сопоставить гостиничные названия с каталогом, проверить факты для приоритетных подборок и заполнить связи поста с отелем или темой. Затем подготовить фид и сообщения под эти конкретные входы.</p>'
        + '<p>Это очередь подготовки: после проверки названий и условий можно собрать конкретные посты и подборки для согласованной воронки.</p></section>'
        + '<footer>В ZIP: новая рабочая очередь, все кандидаты и рецепты, темы с исходными запросами, ТОП500, полная выгрузка запросов, inventory, прежний полный анализ и provenance. Данные рекламы не изменялись. SHA-256 полного запросного источника: <code>'
        + e(query_sha) + '</code></footer></main>'
        + '<script>document.querySelectorAll("[data-filter]").forEach(function(input){input.addEventListener("input",function(){var q=input.value.toLocaleLowerCase("ru");document.querySelectorAll("#"+input.dataset.filter+" tbody tr").forEach(function(row){row.hidden=!row.textContent.toLocaleLowerCase("ru").includes(q);});});});</script></html>'
    )


def build(root=ROOT):
    paths = {key: root / relative for key, relative in INPUTS.items()}
    require(all(path.is_file() for path in paths.values()), 'Reviewed inputs are not all present')
    hashes = {key: sha256(path) for key, path in paths.items()}
    summary, hotels, recipes, themes, seed = [load(paths[key]) for key in
        ('inventory_summary', 'hotel_shortlist', 'hotel_recipes', 'themes', 'sales_seed')]
    export = summary['query_export']
    require(summary['period'] == PERIOD and export['status'] == 'COLLECTED', 'Full account query export required')
    require(export['source_sha256'] == hashes['queries'] and not export['missing_search_campaign_ids']
            and export['requested_campaigns'] == export['complete_campaigns'] == 64, 'Query scope/hash mismatch')
    require(hotels['schema'] == 'anytour.direct.expanded_hotel_shortlist.v1'
            and hotels['status'] == 'COLLECTED' and hotels['source_file_sha256'] == hashes['queries'], 'Hotel input mismatch')
    require([hotels['period']['from'], hotels['period']['to']] == PERIOD, 'Hotel period mismatch')
    require(recipes['source_file_sha256'] == hashes['queries'], 'Hotel recipes source mismatch')
    require(hotels['hotel_candidate_count'] == len(hotels['candidates']) == 317
            and len(recipes['recipes']) == len(hotels['candidates']), 'Expanded hotel count mismatch')
    require({x['key'] for x in recipes['recipes']} == {x['demand_candidate_key'] for x in hotels['candidates']}, 'Hotel recipe key mismatch')
    require(all(x.get('canonical_hotel_id') is None and x.get('publishable') is False for x in hotels['candidates']), 'Hotel identity remains unconfirmed')
    require(themes['source_period'] == PERIOD and themes['source_file_sha256'] == hashes['queries']
            and themes['selected_count'] == len(themes['candidates']) == 36, 'Thematic input mismatch')
    require(any(t['candidate_key'] == 'EG_heated_pool' for t in themes['candidates']), 'Egypt heated pool selection missing')
    require(all(t['publishable'] is False and not t['hotel_properties_asserted'] and t['eligibility_checks'] for t in themes['candidates']), 'Theme fact checks required')
    require(len(seed['ranked_name_rows']) == 500 and seed['legacy_id_count'] == len(set(seed['legacy_tourvisor_ids'])) == 492, 'TOP500 seed scope mismatch')
    report = render(summary, hotels, themes, seed, hashes['queries']).encode('utf-8')
    queue = {
        'schema': 'anytour.funnel.expanded_work_queue.v1', 'period': PERIOD,
        'source_query_sha256': hashes['queries'], 'publication_status': 'NOT_PUBLISHED', 'publishable': False,
        'metrics_basis': 'Показы наших объявлений 23.09–22.10.2026 в раскрытых поисковых запросах Директа; не частотность Wordstat.',
        'editorial_priority': ['EG_heated_pool'], 'theme_overlap': 'Candidate themes overlap; do not sum metrics.',
        'id_contract': 'Own entry ID -> post/theme/hotel; yclid at entry, then chat ID. keyword is not used.',
        'hotel_candidates_file': ARCHIVE_NAMES['hotel_shortlist'], 'hotel_recipes_file': ARCHIVE_NAMES['hotel_recipes'],
        'hotel_candidates': [{key: h.get(key) for key in ('demand_candidate_key', 'label', 'expansion_status', 'canonical_hotel_id',
            'local_identity_matching_required', 'publishable', 'impressions', 'clicks', 'metrics_by_lifecycle')} for h in hotels['candidates']],
        'thematic_candidates_file': ARCHIVE_NAMES['themes'], 'thematic_candidates': themes['candidates'],
        'top500_sales_seed_file': ARCHIVE_NAMES['sales_seed'], 'top500_name_rows': 500, 'top500_unmatched_legacy_ids': 492,
        'top500_identity_policy': 'Separate name rows and legacy IDs; no positional joining or claim of 500 new unique properties.',
        'readiness': 'CATALOG_IDENTITY_AND_SELECTION_FACTS_PENDING', 'direct_changes': False,
    }
    out = root / 'deliverables'
    old_provenance = load(out / PROVENANCE) if (out / PROVENANCE).exists() else {}
    collection = old_provenance.get('collection', old_provenance)
    provenance = {'schema': 'anytour.funnel.expanded_package.v1', 'generated_at_utc': datetime.now(timezone.utc).isoformat(),
        'collection': collection, 'period': PERIOD, 'full_analysis_recomputed': False, 'production_changes': False,
        'input_files': {key: {'archive_name': ARCHIVE_NAMES[key], 'sha256': hashes[key], 'bytes': path.stat().st_size}
                        for key, path in paths.items()},
        'counts': {'hotel_candidates': len(hotels['candidates']), 'themes': len(themes['candidates']),
                   'top500_name_rows': 500, 'legacy_tourvisor_ids_unmatched': 492},
        'report_sha256': hashlib.sha256(report).hexdigest(), 'working_queue_sha256': hashlib.sha256(json_bytes(queue)).hexdigest()}
    generated = {REPORT: report, PRIORITIES: json_bytes(queue), PROVENANCE: json_bytes(provenance)}
    out.mkdir(exist_ok=True)
    with tempfile.TemporaryDirectory(prefix='expanded-funnel-', dir=out) as temporary:
        staging = Path(temporary)
        for name, body in generated.items():
            (staging / name).write_bytes(body)
        pending_zip = staging / ARCHIVE
        with zipfile.ZipFile(pending_zip, 'w', zipfile.ZIP_DEFLATED, compresslevel=7) as archive:
            archive.writestr('report.html', report)
            archive.writestr('priorities.json', generated[PRIORITIES])
            archive.writestr(PROVENANCE, generated[PROVENANCE])
            for key, path in paths.items():
                archive.write(path, ARCHIVE_NAMES[key])
            archive.write(Path(__file__), 'package-expanded-funnel.py')
            archive.writestr('README.txt', 'Новая рабочая очередь: priorities.json. Метрики: показы/клики наших объявлений за 23.09–22.10.2026, не Wordstat. Все локальные гостиничные ID и факты для тематических подборок требуют подтверждения. ТОП500: 500 строк названий и 492 отдельных legacy ID; позиционное сопоставление не выполнялось. Исходные API-строки сохранены без дедупликации.\n')
        with zipfile.ZipFile(pending_zip) as archive:
            require(archive.testzip() is None, 'ZIP CRC validation failed')
            require(len(archive.namelist()) == len(set(archive.namelist())), 'Duplicate ZIP entry')
            require(archive.read('report.html') == report, 'Archived report differs')
            require(json.loads(archive.read('priorities.json'))['schema'] == queue['schema'], 'Working queue missing')
        # All staged files and the archive are complete before replacing existing deliverables.
        for name in generated:
            os.replace(staging / name, out / name)
        os.replace(pending_zip, out / ARCHIVE)
    return {'hotel_candidates': len(hotels['candidates']), 'new_observed_names': hotels['new_hotel_candidate_count'],
            'themes': len(themes['candidates']), 'top500_name_rows': 500, 'archive_bytes': (out / ARCHIVE).stat().st_size,
            'report': str(out / REPORT), 'archive': str(out / ARCHIVE), 'full_analysis_recomputed': False}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, default=ROOT)
    args = parser.parse_args()
    print(json.dumps(build(args.root.resolve()), ensure_ascii=False))


if __name__ == '__main__':
    main()
