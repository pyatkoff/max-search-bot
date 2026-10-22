#!/usr/bin/env python3
"""Read-only aggregation of exposed Direct query rows; no ad/account writes."""
import argparse
import hashlib
import html
import json
import re
import unicodedata
import xml.etree.ElementTree as ET
from collections import Counter, defaultdict
from datetime import date, datetime, timedelta, timezone
from decimal import Decimal, InvalidOperation
from pathlib import Path
from zoneinfo import ZoneInfo

COUNTRIES = [
    ('TR', 'Турция', r'турци|антал|анталь|алан[ьи]|белек|кемер|сиде|бодрум|бельдиби|авсаллар|turkey|belek|kemer'),
    ('EG', 'Египет', r'егип|шарм|хургад|марса алам|аламейн|sharm|hurghada'),
    ('TH', 'Таиланд', r'та[ий]ланд|пхукет|патта[йя]|самуи|кхао лак|краби|phuket|pattaya'),
    ('VN', 'Вьетнам', r'вьетнам|фукуок|нячанг|ня чанг|фантхьет|муйне|vietnam|nha trang'),
    ('AE', 'ОАЭ', r'\bоаэ\b|дуба[йи]|шардж|рас.?аль.?хайм|dubai'),
    ('MV', 'Мальдивы', r'мальдив|maldives'),
    ('LK', 'Шри-Ланка', r'шри.?ланк|унаватун|хиккадув|бентот|sri lanka'),
    ('CN', 'Китай', r'кита[йея]|хайнан|\b(?:санья|сания)\b'),
    ('CU', 'Куба', r'\bкуб[аыуе]\b|варадеро'),
    ('ID', 'Индонезия / Бали', r'индонез|\bбали\b|\bbali\b'),
    ('SC', 'Сейшелы', r'сейшел'),
    ('TN', 'Тунис', r'тунис|джерб|хаммамет'),
    ('RU_AB', 'Россия / Абхазия', r'\bросси|сочи|адлер|абхаз|сухум|гагра|соль илецк|домбай|архыз|дагестан|кабардинка'),
]
NEEDS = [
    ('heated_pool', 'Подогреваемый бассейн', r'подогре|т[её]пл.{0,6}басс'),
    ('sandy_beach', 'Песчаный пляж', r'песчан|\bпесок\b'),
    ('new_year', 'Новый год', r'новый год|новогод|нового года|31 декабря|30 декабря'),
    ('family', 'Отдых с детьми', r'реб[её]н|дет[ьяе]|семейн'),
    ('waterpark', 'Аквапарк', r'аквапарк|водны.{0,4}горк'),
    ('first_line', 'Первая линия', r'перв[ао]й? линии|первая линия|1.?я линия'),
    ('all_inclusive', 'Всё включено', r'вс[её] включен|all inclusive|ultra'),
    ('newlyweds', 'Свадебное путешествие', r'свадеб|медов.{0,4}месяц'),
    ('hot', 'Горящие туры', r'горящ|горячие тур|горячие пут'),
    ('solo', 'Поездка одному', r'на одного|одному|одна поед|один взрос'),
    ('couple', 'Поездка вдвоём', r'на двоих|вдво[её]м|2 взрослых|два взрослых'),
]
HOTELS = [
    ('bellis_deluxe', 'Bellis Deluxe', r'bellis|беллис|белис дел'),
    ('crystal_flora', 'Crystal Flora Beach', r'crystal flora|кристал+ флора'),
    ('nirvana_dolce_vita', 'Nirvana Dolce Vita', r'nirvana dolce|нирвана дольче'),
    ('sultan_gardens', 'Sultan Gardens', r'sultan garden|султан гарден'),
    ('albatros_royal_grand', 'Albatros Royal Grand', r'albatros royal grand|альбатрос роял гранд'),
    ('concorde_front', 'Concorde El Salam Front Area', r'concorde el salam front|конкорд.{0,8}салам.{0,8}фронт'),
    ('concorde_sport', 'Concorde El Salam Sport Area', r'concorde el salam sport|конкорд.{0,8}салам.{0,8}спорт'),
    ('xperience_sea_breeze', 'Xperience Sea Breeze', r'xperience sea breeze|экспириенс си бриз'),
    ('wasa_hotel', 'Wasa Hotel', r'wasa hotel|васа отел'),
    ('viva_blue', 'Viva Blue Resort', r'viva blue|вива блю'),
    ('pickalbatros_aqua_park_sharm', 'Pickalbatros Aqua Park Sharm', r'pickalbatros aqua park sharm|пикальбатрос аква парк шарм'),
    ('rixos_unresolved', 'Rixos', r'\brixos\b|риксос'),
    ('pickalbatros_unresolved', 'Pickalbatros', r'pickalbatros|пикальбатрос'),
    ('sunrise_unresolved', 'Sunrise', r'\bsunrise\b|санрайз'),
    ('jaz_unresolved', 'Jaz', r'\bjaz\b'),
    ('delphin_unresolved', 'Delphin', r'\bdelphin\b|дельфин.{0,10}(отел|делюкс|империал)'),
]
METRICS = ('impressions', 'clicks', 'cost')


def normalize(value):
    value = unicodedata.normalize('NFKC', str(value)).casefold().replace('ё', 'е')
    return ' '.join(re.sub(r'[^\w\s]', ' ', value, flags=re.UNICODE).split())


def number(value):
    if value is None or str(value).strip() in ('', '--', '—', 'null', 'None'):
        return None
    try:
        n = Decimal(str(value).strip().replace(',', '.'))
        return n if n.is_finite() else None
    except InvalidOperation:
        return None


def stats(rows):
    result = {'rows': len(rows)}
    for field in METRICS:
        values = [r[field] for r in rows]
        known = [v for v in values if v is not None]
        total = sum(known, Decimal(0))
        cast = (lambda x: round(float(x), 2)) if field == 'cost' else int
        result[field] = cast(total) if len(known) == len(values) and values else None
        result[field + '_known_sum'] = cast(total)
        result[field + '_missing_rows'] = len(values) - len(known)
    return result


def converted_rows(source):
    old = isinstance(source, list)
    raw = source if old else source.get('raw_rows', source.get('rows', []))
    result, rejected = [], []
    for index, r in enumerate(raw):
        if not isinstance(r, dict):
            rejected.append({'row': index, 'reason': 'not_object'})
            continue
        query = r.get('Query', r.get('query'))
        day = str(r.get('Date', r.get('date', '')))[:10]
        try:
            date.fromisoformat(day)
        except ValueError:
            rejected.append({'row': index, 'reason': 'invalid_date'})
            continue
        if not isinstance(query, str) or not normalize(query):
            rejected.append({'row': index, 'reason': 'missing_query'})
            continue
        result.append({'query': query, 'normalized': normalize(query), 'date': day,
                       'campaign_id': str(r.get('CampaignId', r.get('campaign_id', '714260445' if old else 'UNKNOWN'))),
                       **{f: number(r.get(f.capitalize(), r.get(f))) for f in METRICS}})
    return result, rejected, old


def classify(query):
    countries = [(code, label) for code, label, pattern in COUNTRIES if re.search(pattern, query, re.I)]
    country_code, country_label = countries[0] if len(countries) == 1 else ('MULTI', 'Несколько направлений') if countries else ('GENERAL', 'Общие запросы')
    needs = [(code, label) for code, label, pattern in NEEDS if re.search(pattern, query, re.I)]
    hotel = next(((code, label) for code, label, pattern in HOTELS if re.search(pattern, query, re.I)), None)
    if hotel:
        topic_id, label = 'hotel_' + hotel[0], hotel[1]
        kind = 'hotel_brand_candidate' if hotel[0].endswith('_unresolved') else 'hotel_candidate'
    else:
        need_id, need_label = needs[0] if needs else ('general', 'Подбор тура')
        topic_id, label, kind = country_code + '_' + need_id, country_label + ' · ' + need_label, 'intent'
    flags = []
    for flag, pattern in (
        ('employment', r'\bваканси|\bработ[аыу]\b|\bтрудоустрой|\bрезюме\b|\bзарплат'),
        ('navigation_contact', r'\bадрес|как добраться|\bтелефон'),
        ('weather_webcam', r'\bпогод|веб.?камер|webcam'),
    ):
        if re.search(pattern, query, re.I):
            flags.append(flag)
    if re.search(r'\bотзыв|\breviews?\b', query, re.I):
        flags.append('hotel_reviews_information' if hotel else 'reviews_information')
    return {'topic_id': topic_id, 'topic': label, 'kind': kind, 'country_code': country_code,
            'country': country_label, 'matched_countries': [x[0] for x in countries],
            'needs': [x[0] for x in needs], 'hotel_id': None,
            'hotel_matching_required': bool(hotel), 'intent_review_flags': flags}


def parse_feed(path):
    if not path:
        return []
    root = ET.parse(path).getroot()
    return [{'id': x.get('id'), 'name': x.findtext('name') or '', 'url': x.findtext('url') or ''}
            for x in root.findall('.//collections/collection')]


def feed_match(topic, feed):
    code = topic['country_code']
    pattern = next((p for c, _, p in COUNTRIES if c == code), None)
    matches = [x for x in feed if pattern and re.search(pattern, x['name'], re.I)]
    if not feed:
        return {'status': 'FEED_NOT_PROVIDED', 'collections': []}
    return {'status': 'DIRECTION_MATCH_CANDIDATE' if matches else 'NO_DIRECTION_MATCH', 'collections': matches}


def trend_for(rows, meta, campaign_id):
    if meta.get('historical_baseline'):
        return {'status': 'UNAVAILABLE_HISTORICAL_BASELINE', 'reason': 'Исходный архив содержит только один фактический день.'}
    if meta.get('status') != 'COLLECTED' or not meta.get('coverage', {}).get('pagination_complete'):
        return {'status': 'UNAVAILABLE_INCOMPLETE_EXPORT'}
    try:
        zone = ZoneInfo(meta['campaign_timezones'][campaign_id])
        collected = datetime.fromisoformat(meta['finished_at_utc'].replace('Z', '+00:00'))
        collection_day = collected.astimezone(zone).date()
        first, last = date.fromisoformat(meta['date1']), date.fromisoformat(meta['date2'])
        end = min(last, collection_day - timedelta(days=1))
        recent_start, previous_end = end - timedelta(days=6), end - timedelta(days=7)
        previous_start = end - timedelta(days=13)
    except (KeyError, ValueError, TypeError):
        return {'status': 'UNAVAILABLE_DATE_OR_TIMEZONE'}
    if first > previous_start or last < end:
        return {'status': 'UNAVAILABLE_LESS_THAN_14_COMPLETE_DAYS'}
    recent = stats([r for r in rows if recent_start.isoformat() <= r['date'] <= end.isoformat()])
    previous = stats([r for r in rows if previous_start.isoformat() <= r['date'] <= previous_end.isoformat()])
    # A complete export with no exposed rows in a window has zero returned metrics.
    for s in (recent, previous):
        if s['rows'] == 0:
            for f in METRICS:
                s[f] = 0
    delta = {}
    for f in METRICS:
        a, b = recent[f], previous[f]
        delta[f] = None if a is None or b is None or b == 0 else round((a - b) / b * 100, 2)
    return {'status': 'COMPARABLE_EXPOSED_ROWS', 'recent': {'from': recent_start.isoformat(), 'to': end.isoformat(), 'days': 7, **recent},
            'previous': {'from': previous_start.isoformat(), 'to': previous_end.isoformat(), 'days': 7, **previous},
            'delta_percent': delta, 'excluded_incomplete_day': collection_day.isoformat(),
            'note': 'Равные окна по 7 полных календарных дней в часовом поясе кампании; только раскрытые Директом строки.'}


def build(source, feed):
    rows, rejected, old = converted_rows(source)
    meta = {'status': 'HISTORICAL_BASELINE', 'historical_baseline': True} if old else {k: v for k, v in source.items() if k not in ('raw_rows', 'rows')}
    meta['historical_baseline'] = old or bool(meta.get('historical_baseline'))
    buckets = defaultdict(list)
    for row in rows:
        buckets[row['normalized']].append(row)
    queries = []
    for key, group in buckets.items():
        forms = Counter(r['query'] for r in group)
        topic = classify(key)
        queries.append({'normalized_query': key, 'example_query': forms.most_common(1)[0][0],
                        'forms': sorted(forms), 'actual_days': sorted({r['date'] for r in group}),
                        'totals': stats(group),
                        'by_campaign': {c: stats([r for r in group if r['campaign_id'] == c]) for c in sorted({r['campaign_id'] for r in group})},
                        'trend_by_campaign': {c: trend_for([r for r in group if r['campaign_id'] == c],meta,c) for c in sorted({r['campaign_id'] for r in group})},
                        **topic})
    rank = lambda q: (-q['totals']['clicks_known_sum'], -q['totals']['cost_known_sum'], -q['totals']['impressions_known_sum'], q.get('normalized_query', q.get('topic_id', '')))
    queries.sort(key=rank)
    topic_rows = defaultdict(list)
    for row in rows:
        topic_rows[classify(row['normalized'])['topic_id']].append(row)
    topics = []
    for tid, group in topic_rows.items():
        qs = [q for q in queries if q['topic_id'] == tid]
        desc = {k: qs[0][k] for k in ('topic_id', 'topic', 'kind', 'country_code', 'country', 'hotel_id', 'hotel_matching_required')}
        flags = sorted({flag for q in qs for flag in q['intent_review_flags']})
        title = ('Туры в ' + desc['topic']) if desc['kind'] == 'hotel_candidate' else ('Отели ' + desc['topic'] + ': подбор тура') if desc['kind'] == 'hotel_brand_candidate' else desc['topic'].replace(' · ', ': ')
        topics.append({**desc, 'totals': stats(group), 'unique_queries': len(qs),
                       'intent_review_flags': flags,
                       'query_examples': [q['example_query'] for q in qs[:6]],
                       'by_campaign': {c: stats([r for r in group if r['campaign_id'] == c]) for c in sorted({r['campaign_id'] for r in group})},
                       'trend_by_campaign': {c: trend_for([r for r in group if r['campaign_id'] == c],meta,c) for c in sorted({r['campaign_id'] for r in group})},
                       'current_feed': feed_match(desc, feed), 'proposed_post_title': title,
                       'cta': 'Подобрать тур в этот отель' if desc['kind'] == 'hotel_candidate' else 'Подобрать отель этой сети' if desc['kind'] == 'hotel_brand_candidate' else 'Подобрать на мои даты',
                       'readiness': 'REVIEW_QUERY_INTENT' if flags else 'RESOLVE_SPECIFIC_HOTEL' if desc['kind'] == 'hotel_brand_candidate' else 'MATCH_LOCAL_HOTEL_ID' if desc['kind'] == 'hotel_candidate' else 'EDITORIAL_REVIEW'})
    topics.sort(key=rank)
    campaigns = {}
    for c in sorted({r['campaign_id'] for r in rows}):
        cr = [r for r in rows if r['campaign_id'] == c]
        campaigns[c] = {'totals': stats(cr), 'unique_queries': len({r['normalized'] for r in cr}),
                        'actual_days': sorted({r['date'] for r in cr}), 'trend': trend_for(cr, meta, c)}
    days = sorted({r['date'] for r in rows})
    ranking_status = 'HISTORICAL_BASELINE_ONLY' if old else 'OBSERVED_EXPOSED_ROWS' if meta.get('status') == 'COLLECTED' and meta.get('coverage',{}).get('pagination_complete') and not rejected else 'PROVISIONAL_INCOMPLETE_COVERAGE'
    return {'analysis_schema': 1, 'generated_at_utc': datetime.now(timezone.utc).isoformat(),
            'ranking_status': ranking_status,
            'source': meta, 'coverage': {'input_rows': len(source) if old else len(source.get('raw_rows', source.get('rows', []))),
            'valid_rows': len(rows), 'rejected_rows': rejected, 'unique_normalized_queries': len(queries),
            'actual_date_min': days[0] if days else None, 'actual_date_max': days[-1] if days else None,
            'actual_day_count': len(days), 'actual_days': days},
            'metrics_note': 'Расход в валюте счёта без НДС. Нет данных о содержательных обращениях, продажах, CPA или конверсиях. Числа — только раскрытые Директом строки; отсутствие строки не описывает весь спрос.',
            'classification_note': 'Группировка по явным регулярным шаблонам — кандидаты для редакционной проверки, не подтверждённые hotel_id. У каждого запроса одна основная тема; дополнительные потребности сохранены. Маркеры работы, навигации, погоды и отзывов требуют смысловой проверки; отзывы об отеле сохранены как информационный интерес. География автоматически не исключается. Темы не публиковались.',
            'totals': stats(rows), 'campaigns': campaigns, 'feed_collections': feed,
            'queries': queries, 'topic_candidates': topics}


def render(data):
    esc = lambda x: html.escape(str(x), quote=True)
    def val(x, money=False):
        return 'Нет данных' if x is None else f'{x:,.2f}'.replace(',', ' ').replace('.', ',') if money else f'{x:,}'.replace(',', ' ')
    def metric(s, name):
        result = val(s[name], name == 'cost')
        if s[name] is None and s[name + '_missing_rows']:
            result += ' · известно ' + val(s[name + '_known_sum'], name == 'cost')
        return result
    cov, src, total = data['coverage'], data['source'], data['totals']
    historical = src.get('historical_baseline')
    status = 'ИСТОРИЧЕСКАЯ ОСНОВА · 09.09.2026' if historical else src.get('status', 'Статус неизвестен')
    warn = 'Это исторический архив. Он не показывает текущую популярность запросов в октябре.' if historical else 'Это спрос, охваченный двумя выбранными кампаниями. Часть запросов Директ не раскрывает. Результаты не равны всему рынку.'
    if data['ranking_status'] == 'PROVISIONAL_INCOMPLETE_COVERAGE':
        warn = 'НЕПОЛНАЯ ВЫГРУЗКА. Таблицы описывают только полученную часть и не устанавливают приоритеты по полному спросу. Сравнение недель недоступно. ' + warn
    if src.get('problems'):
        warn += ' Причины: ' + ', '.join(map(str,src['problems'])) + '.'
    sections = []
    def table(head, body):
        return '<div class="scroll"><table><thead><tr>' + ''.join('<th>'+esc(h)+'</th>' for h in head) + '</tr></thead><tbody>' + body + '</tbody></table></div>'
    camp_body = ''
    for c, info in data['campaigns'].items():
        s, t = info['totals'], info['trend']
        trend = t['status']
        if t['status'] == 'COMPARABLE_EXPOSED_ROWS':
            trend = f"{t['recent']['from']}—{t['recent']['to']}: {val(t['recent']['clicks'])} кликов; {t['previous']['from']}—{t['previous']['to']}: {val(t['previous']['clicks'])} кликов"
        camp_body += '<tr>' + ''.join('<td>'+esc(v)+'</td>' for v in (c, info['unique_queries'], metric(s,'impressions'),metric(s,'clicks'),metric(s,'cost'),trend)) + '</tr>'
    sections.append('<h2>Кампании и равные окна по 7 полных дней</h2>'+table(['РК','Запросов','Показы','Клики','Расход','Сравнение'],camp_body))
    topic_body = ''
    for i, topic in enumerate(data['topic_candidates'][:100],1):
        s = topic['totals']
        feed = ' / '.join(f"{x['id']}: {x['name']}" for x in topic['current_feed']['collections']) or ('Фид не предоставлен' if not data['feed_collections'] else 'Сопоставление направления не найдено')
        note = 'Нужно определить конкретный отель сети' if topic['kind'] == 'hotel_brand_candidate' else 'Нужен локальный ID отеля' if topic['hotel_matching_required'] else 'Нужна редакционная проверка'
        if topic['intent_review_flags']:
            note += ' · Проверить намерение: ' + ', '.join(topic['intent_review_flags'])
        topic_body += '<tr data-search="'+esc(' '.join(topic['query_examples'])+' '+topic['topic'])+'"><td>'+str(i)+'</td><td><strong>'+esc(topic['topic'])+'</strong><small>'+esc(note)+'</small></td><td>'+esc(' · '.join(topic['query_examples'][:3]))+'</td><td>'+esc(metric(s,'clicks'))+'</td><td>'+esc(metric(s,'cost'))+'</td><td>'+esc(feed)+'</td><td>'+esc(topic['proposed_post_title'])+'<small>'+esc(topic['cta'])+'</small></td></tr>'
    sections.append('<h2>Кандидаты связок: запрос → тема → фид → пост</h2><p>Сортировка по кликам, затем расходу исключительно в полученных строках. При неполной выгрузке этот порядок не является общим приоритетом. Показаны первые 100 тем. Это предложения для просмотра; публикации и изменения рекламы не выполнялись.</p>'+table(['№','Тема','Реальные запросы','Клики','Расход','Текущее направление фида','Черновое название / действие'],topic_body))
    qbody = ''
    for q in data['queries'][:200]:
        s = q['totals']
        qbody += '<tr data-search="'+esc(' '.join(q['forms']))+'">'+''.join('<td>'+esc(v)+'</td>' for v in (q['example_query'], q['topic'],metric(s,'impressions'),metric(s,'clicks'),metric(s,'cost'),', '.join(q['by_campaign'])))+'</tr>'
    sections.append('<h2>Рейтинг точных запросов</h2><p>Первые 200 нормализованных запросов. Регистр, ё/е, пунктуация и повторные пробелы объединены; исходные формулировки сохранены в JSON.</p>'+table(['Запрос','Тема','Показы','Клики','Расход','РК'],qbody))
    config = 'не проверено' if src.get('config_unchanged') is None else 'не изменились в проверенном наборе полей' if src['config_unchanged'] else 'обнаружено изменение во время сбора'
    page_state = 'полная' if src.get('coverage',{}).get('pagination_complete') else 'не подтверждена'
    return '<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>AnyTour · спрос и тематические связки</title><style>body{margin:0;background:#f4f6fa;color:#17243c;font:15px/1.5 system-ui,sans-serif}main{max-width:1500px;margin:auto;padding:36px 24px}h1{font-size:32px;line-height:1.15;margin:14px 0}h2{font-size:22px;margin:36px 0 12px}.eyebrow{color:#546b8a;letter-spacing:.08em;font-size:12px;font-weight:700}.notice{background:#fff2d8;border:1px solid #ead09c;border-radius:12px;padding:16px}.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin:24px 0}.card{background:white;border:1px solid #dfe5ee;border-radius:12px;padding:18px}.card b{display:block;font-size:28px}.card span,small{color:#62718a}small{display:block;font-size:12px;margin-top:5px}.scroll{overflow:auto;background:white;border:1px solid #dfe5ee;border-radius:12px}table{border-collapse:collapse;width:100%;font-size:13px}th,td{padding:13px 14px;border-bottom:1px solid #e5eaf1;text-align:left;vertical-align:top}th{background:#edf2f8;white-space:nowrap}td:nth-child(3){min-width:220px}input{padding:12px 15px;width:min(600px,90%);border:1px solid #c7d1df;border-radius:9px;font:inherit}p{max-width:1100px}.muted{color:#63718a}footer{margin:36px 0;color:#63718a;font-size:13px}@media(max-width:600px){main{padding:22px 14px}h1{font-size:26px}}</style><main><div class="eyebrow">ANYTOUR · ПОДГОТОВКА НОВОЙ ВОРОНКИ</div><h1>Поисковый спрос → тематический пост → бот</h1><p class="muted">'+esc(status)+' · фактические даты строк: '+esc(cov['actual_date_min'])+'—'+esc(cov['actual_date_max'])+'</p><div class="notice">'+esc(warn)+'</div><div class="cards">'+''.join('<div class="card"><span>'+esc(label)+'</span><b>'+esc(value)+'</b></div>' for label,value in [('Строк API',val(cov['valid_rows'])),('Запросов',val(cov['unique_normalized_queries'])),('Фактических дней',val(cov['actual_day_count'])),('Кликов',metric(total,'clicks')),('Расход без НДС',metric(total,'cost'))])+'</div><p><b>Покрытие:</b> пагинация — '+esc(page_state)+'; конфигурация кампаний — '+esc(config)+'. Запрошенный период: '+esc(src.get('date1','не указан'))+'—'+esc(src.get('date2','не указан'))+'. Отклонённых строк: '+str(len(cov['rejected_rows']))+'.</p><p class="muted">'+esc(data['metrics_note'])+'</p><p class="muted">'+esc(data['classification_note'])+'</p><label>Найти в показанных темах и запросах<br><input id="filter" placeholder="Отель, страна, потребность"></label>'+''.join(sections)+'<footer>Источник: Яндекс Директ, раскрытые поисковые запросы выбранных кампаний. Все исходные строки и полные рейтинги находятся в соседнем JSON. Числовые нули отделены от недоступных значений. Сведения об отелях, цены туров и готовые предложения в этом отчёте не утверждаются.</footer></main><script>document.getElementById("filter").addEventListener("input",function(){const q=this.value.toLocaleLowerCase("ru").replaceAll("ё","е");document.querySelectorAll("tr[data-search]").forEach(r=>r.hidden=!r.dataset.search.toLocaleLowerCase("ru").replaceAll("ё","е").includes(q))})</script></html>'


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('report', type=Path)
    parser.add_argument('--out-dir', type=Path)
    parser.add_argument('--feed', type=Path)
    args = parser.parse_args()
    source = json.loads(args.report.read_text(encoding='utf-8'))
    result = build(source, parse_feed(args.feed))
    result['source_file_sha256'] = hashlib.sha256(args.report.read_bytes()).hexdigest()
    out = args.out_dir or args.report.parent
    out.mkdir(parents=True,exist_ok=True)
    (out/'topic_candidates.json').write_text(json.dumps(result,ensure_ascii=False,indent=2,allow_nan=False)+'\n',encoding='utf-8')
    (out/'report.html').write_text(render(result),encoding='utf-8')
    print(json.dumps({'rows':result['coverage']['valid_rows'],'queries':result['coverage']['unique_normalized_queries'],'topics':len(result['topic_candidates']),'out_dir':str(out)},ensure_ascii=False))


if __name__ == '__main__':
    main()
