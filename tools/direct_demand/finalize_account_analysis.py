#!/usr/bin/env python3
"""Package a complete account demand analysis and editorial candidates."""
import ast
from collections import defaultdict
from decimal import Decimal
import hashlib
import html
import json
import os
from pathlib import Path
import re
import zipfile
from analyze_demand import normalize

ROOT = Path(__file__).resolve().parent
OUT = ROOT / 'deliverables'
OLD_IDS = {'714260445', '714320748'}


def load(path):
    return json.loads(path.read_text(encoding='utf-8'))


def metrics(rows):
    return {'rows': len(rows), 'impressions': sum(int(r['Impressions']) for r in rows),
            'clicks': sum(int(r['Clicks']) for r in rows),
            'cost_rub_ex_vat': float(sum((Decimal(r['Cost']) for r in rows), Decimal(0))),
            'distinct_queries': len({normalize(r['Query']) for r in rows})}


def examples(rows):
    grouped = defaultdict(lambda: [0, 0])
    for row in rows:
        grouped[row['Query']][0] += int(row['Clicks'])
        grouped[row['Query']][1] += int(row['Impressions'])
    return [{'query': q, 'clicks': v[0], 'impressions': v[1]} for q, v in
            sorted(grouped.items(), key=lambda x: (-x[1][0], -x[1][1], x[0]))[:8]]


def main():
    source = load(ROOT / 'account_queries_20261022.json')
    inventory = load(ROOT / 'account_inventory_20261022.json')
    analysis = load(ROOT / 'account_analysis/account-topic-candidates.json')
    assert source['status'] == 'COLLECTED' and source['coverage']['pagination_complete']
    assert not analysis['coverage']['search_activity_ids_not_complete']
    assert not analysis['ranking_status'].startswith('PROVISIONAL')
    rows = source['raw_rows']
    registry = {c['id']: c for c in analysis['inventory']}
    state = lambda r: registry[str(r['CampaignId'])]['lifecycle']
    old = [r for r in rows if str(r['CampaignId']) in OLD_IDS]
    all_metrics, old_metrics = metrics(rows), metrics(old)
    # Reuse editorial definitions as data, without executing the former script.
    module = ast.parse((ROOT / 'finalize_analysis.py').read_text())
    definitions = next(ast.literal_eval(n.value) for n in module.body if
                       isinstance(n, ast.Assign) and any(isinstance(t, ast.Name) and t.id == 'definitions' for t in n.targets))
    definitions += [
        ('general_hot', 'Горящие туры: выбрать направление и даты', 'GENERAL', r'горящ|горячие тур|горячие пут', 'Подобрать горящий тур'),
        ('eg_hot', 'Горящие туры в Египет', 'EG', r'(?=.*егип)(?=.*(?:горящ|горячие тур|горячие пут))', 'Подобрать тур в Египет'),
        ('tr_hot', 'Горящие туры в Турцию', 'TR', r'(?=.*(?:турци|турецк))(?=.*(?:горящ|горячие тур|горячие пут))', 'Подобрать тур в Турцию'),
        ('vn_hot', 'Горящие туры во Вьетнам', 'VN', r'(?=.*вьетнам)(?=.*(?:горящ|горячие тур|горячие пут))', 'Подобрать тур во Вьетнам'),
        ('th_hot', 'Горящие туры в Таиланд', 'TH', r'(?=.*та[ий]ланд)(?=.*(?:горящ|горячие тур|горячие пут))', 'Подобрать тур в Таиланд'),
        ('general_newyear', 'Куда поехать на Новый год', 'GENERAL', r'новый год|новогод|нового года', 'Подобрать на Новый год'),
    ]
    definitions = [(ref,title,country,r'хайнан|\b(?:санья|сания)\b' if ref == 'cn_hainan' else pattern,cta)
                   for ref,title,country,pattern,cta in definitions]
    general_keys = {q['key'] for q in analysis['broad_search_rankings']['queries'] if q['classification']['country_code'] == 'GENERAL'}
    themes = []
    for ref, title, country, pattern, cta in definitions:
        matched = [r for r in rows if re.search(pattern, r['Query'], re.I)]
        if country == 'GENERAL':
            matched = [r for r in matched if normalize(r['Query']) in general_keys]
        if ref in {'eg_couple_ai', 'mv_couple'}:
            child_party = r'\b\d+\s*(?:реб[её]н|дет[ьяеи])|\bс\s+(?:реб[её]н|дет[ьяеи]|детьми)|\b(?:двое|трое)\s+дет'
            matched = [r for r in matched if not re.search(child_party,r['Query'],re.I)]
        themes.append({'topic_ref': ref, 'proposed_post_title': title, 'country': country,
                       'all_search_30d': metrics(matched),
                       'by_lifecycle': {s: metrics([r for r in matched if state(r) == s]) for s in ('ACTIVE', 'ARCHIVED', 'INACTIVE', 'UNKNOWN')},
                       'last7d_all_search': metrics([r for r in matched if r['Date'] >= '2026-10-16']),
                       'previous_two_campaigns_in_same_export': metrics([r for r in matched if str(r['CampaignId']) in OLD_IDS]),
                       'campaign_ids': sorted({str(r['CampaignId']) for r in matched}),
                       'query_examples': examples(matched), 'match_pattern': pattern, 'cta': cta,
                       'entry_id': None, 'post_id': None, 'hotel_id': None, 'publishable': False})
    themes.sort(key=lambda x: (-x['all_search_30d']['clicks'], -x['last7d_all_search']['clicks']))
    priority_data = {'period': [source['date1'], source['date2']],
                     'source_campaign_count': len(source['campaign_ids']), 'coverage': analysis['coverage'],
                     'all_search_metrics': all_metrics,
                     'previous_two_campaigns_in_same_export': old_metrics,
                     'old_two_share_of_exposed_clicks': old_metrics['clicks'] / all_metrics['clicks'],
                     'selected_intents': themes,
                     'method': 'Наблюдавшийся поисковый спрос всех кампаний за месяц. Темы пересекаются; значения не складываются. Статус кампании текущий. Это рейтинг кликов в раскрытых запросах, не продаж и не всего рынка.',
                     'id_contract': 'Наш ID темы/поста/отеля + yclid при входе; сопоставление по chat ID. keyword не используется.',
                     'publication_status': 'NOT_PUBLISHED', 'direct_changes': False}
    OUT.mkdir(exist_ok=True)
    (OUT / 'anytour-demand-priorities-20261022.json').write_text(json.dumps(priority_data, ensure_ascii=False, indent=2) + '\n')
    e = lambda x: html.escape(str(x), quote=True)
    table = '<div class="scroll"><table><thead><tr>' + ''.join('<th>' + x + '</th>' for x in
        ['Пост', 'Клики / 30 дней', 'Клики / 7 дней', 'ON сейчас', 'Архив', 'Остановлены', 'Статус неизвестен', 'Примеры запросов']) + '</tr></thead><tbody>'
    for t in themes:
        values = [t['proposed_post_title'], t['all_search_30d']['clicks'], t['last7d_all_search']['clicks']] + [t['by_lifecycle'][s]['clicks'] for s in ('ACTIVE','ARCHIVED','INACTIVE','UNKNOWN')] + ['; '.join(x['query'] for x in t['query_examples'][:2])]
        table += '<tr>' + ''.join('<td>' + e(x) + '</td>' for x in values) + '</tr>'
    table += '</tbody></table></div>'
    intro = '<h2>Что изменилось после расширения охвата</h2><p>В предыдущей версии были только две кампании. Теперь проверена статистика всего аккаунта и выгружены запросы всех '+e(len(source['campaign_ids']))+' кампаний с поисковыми показами за период. На прежние две кампании приходится '+e(round(100*old_metrics['clicks']/all_metrics['clicks'], 1))+'% кликов в этой расширенной выгрузке запросов. Оценка приоритетов пересчитана.</p>'
    intro += '<h2>Пересчитанная очередь конкретных тематических постов</h2><p>Темы пересекаются, их метрики нельзя складывать. Последние 7 дней: 16–22 октября. Отдельные срезы показывают текущий статус кампаний.</p>' + table
    intro += '<p>Связка: /newmax/ → пост по теме или отелю → кнопка в бот. Наш ID связан с постом/отелем; yclid сохраняется при входе, дальнейшее сопоставление — по chat ID. {keyword} не участвует.</p>'
    hotel_path = ROOT / 'account_hotel_shortlist.json'
    if hotel_path.exists():
        hotels = load(hotel_path)
        assert hotels['status'] == 'COLLECTED'
        priority_data['hotel_candidates_file'] = 'hotel-shortlist.json'
        intro += '<h2>Первая очередь гостиничных постов</h2><p>Конкретные названия из запросов, сопоставление с нашим каталогом ещё не выполнено. Порядок: клики кампаний ON сейчас, затем все клики месяца. Сети, разные корпуса и неоднозначные названия остаются отдельными. Свойства отелей и доступность туров здесь не заявлены.</p><div class="scroll"><table><thead><tr><th>Название из запросов</th><th>Клики / все РК</th><th>Клики / ON сейчас</th><th>Клики / архив</th><th>Клики / остановлены</th><th>Клики / статус неизвестен</th><th>Исходные запросы</th></tr></thead><tbody>'
        for h in hotels['candidates'][:30]:
            values = [h['label'], h['clicks_known_sum']] + [h['metrics_by_lifecycle'][s]['clicks_known_sum'] for s in ('ACTIVE','ARCHIVED','INACTIVE','UNKNOWN')] + ['; '.join(q['raw_aliases'][0] for q in h['query_aliases'][:2])]
            intro += '<tr>' + ''.join('<td>' + e(x) + '</td>' for x in values) + '</tr>'
        intro += '</tbody></table></div><p>Полный список из '+e(hotels['hotel_candidate_count'])+' кандидатов, наблюдаемые варианты написания и неподтверждённые гостиничные запросы находятся в архиве.</p>'
        (OUT / 'anytour-demand-priorities-20261022.json').write_text(json.dumps(priority_data, ensure_ascii=False, indent=2) + '\n')
    report = (ROOT / 'account_analysis/account-demand.html').read_text()
    report = report.replace('<h2>Какие направления ищут</h2>', intro + '<h2>Какие направления ищут</h2>', 1)
    (OUT / 'anytour-demand-20261022.html').write_text(report)
    provenance = {'query_run': 'https://github.com/pyatkoff/max-search-bot/actions/runs/39858358002',
                  'repair_run': 'https://github.com/pyatkoff/max-search-bot/actions/runs/39859577281',
                  'single_response_run': 'https://github.com/pyatkoff/max-search-bot/actions/runs/39860688397',
                  'collector_sha': '458599b29eaa80d17bbd86fd94456ed4ad3689e0',
                  'repair_collector_sha': '6354450cfe746aeeee4577d9ac5a4b70cb14275a',
                  'single_collector_sha': '1ab259ed07d7e8af19a8a7eb03438016e31f9bf6',
                  'inventory_run': 'https://github.com/pyatkoff/max-search-bot/actions/runs/39857868934',
                  'query_sha256': hashlib.sha256((ROOT / 'account_queries_20261022.json').read_bytes()).hexdigest(),
                  'inventory_sha256': hashlib.sha256((ROOT / 'account_inventory_20261022.json').read_bytes()).hexdigest(),
                  'query_artifact': load(ROOT / 'account_query_artifact.json'),
                  'inventory_artifact_sha256': '70ec35391c6137db8d5d4b837c7c5f8db40c6b78dec421f4bcb00df852878dc6',
                  'configuration_unchanged': source['config_unchanged'],
                  'unknown_metadata_campaigns': analysis['coverage']['unknown_lifecycle_campaign_ids'],
                  'production_changes': False}
    (OUT / 'source-provenance.json').write_text(json.dumps(provenance, ensure_ascii=False, indent=2) + '\n')
    paths = [(OUT / 'anytour-demand-20261022.html', 'report.html'),
             (OUT / 'anytour-demand-priorities-20261022.json', 'priorities.json'),
             (OUT / 'source-provenance.json', 'source-provenance.json'),
             (ROOT / 'account_queries_20261022.json', 'source-query-report.json'),
             (ROOT / 'account_inventory_20261022.json', 'source-account-inventory.json'),
             (ROOT / 'account_analysis/account-topic-candidates.json', 'full-query-analysis.json'),
             (ROOT / 'current_feed_summary.json', 'feed-sample-summary.json')]
    if hotel_path.exists():
        paths.append((hotel_path, 'hotel-shortlist.json'))
        paths.append((ROOT / 'account_hotel_recipes.json', 'hotel-matching-recipes.json'))
    archive = OUT / 'anytour-demand-20261022.zip'
    temporary_archive = OUT / 'anytour-demand-20261022.pending.zip'
    with zipfile.ZipFile(temporary_archive, 'w', zipfile.ZIP_DEFLATED, compresslevel=7) as z:
        for path, name in paths:
            z.write(path, name)
    with zipfile.ZipFile(temporary_archive) as z:
        assert z.testzip() is None
    os.replace(temporary_archive, archive)
    print(json.dumps({'campaigns':len(source['campaign_ids']), **all_metrics,
                      'previous_two_share': priority_data['old_two_share_of_exposed_clicks'],
                      'topics': [{'title':t['proposed_post_title'],'clicks':t['all_search_30d']['clicks']} for t in themes],
                      'archive_bytes':archive.stat().st_size}, ensure_ascii=False))


if __name__ == '__main__':
    main()
