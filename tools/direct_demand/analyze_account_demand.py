#!/usr/bin/env python3
"""Aggregate an account inventory, campaign performance and exposed search queries.

No external calls, campaign mutations or publication. Source field names and
unknown coverage remain visible; campaign names are never used to infer a brand.
"""
import argparse
from collections import Counter, defaultdict
from datetime import datetime, timezone
import hashlib
import html
import json
from pathlib import Path
from analyze_demand import classify, normalize, number, parse_feed, feed_match, stats, trend_for


def pick_list(source, paths):
    for path in paths:
        value = source
        for part in path.split('.'):
            value = value.get(part) if isinstance(value, dict) else None
        if isinstance(value, list):
            return value, path
    return [], None


def campaign_state(campaign):
    state = str(campaign.get('State', campaign.get('state', 'UNKNOWN'))).upper()
    if state == 'ARCHIVED':
        return 'ARCHIVED'
    if state == 'ON':
        return 'ACTIVE'
    if state in {'OFF', 'SUSPENDED', 'ENDED', 'CONVERTED'}:
        return 'INACTIVE'
    return 'UNKNOWN'


def network_type(row, source, query):
    value = row.get('AdNetworkType', row.get('NetworkType', row.get('network_type')))
    if value is None and query:
        # This is trusted only as an explicit collector assertion/filter.
        value = source.get('query_network_type') or source.get('coverage', {}).get('query_network_type')
    value = str(value or 'UNKNOWN').upper()
    return 'SEARCH' if value == 'SEARCH' else 'NETWORK' if value in {'AD_NETWORK', 'NETWORK', 'YANDEX_AD_NETWORK'} else 'UNKNOWN'


def metric_rows(raw, source, query=False):
    result, rejected = [], []
    normalized_cache, classification_cache = {}, {}
    for i, row in enumerate(raw):
        cid = row.get('CampaignId', row.get('campaign_id')) if isinstance(row, dict) else None
        day = str(row.get('Date', row.get('date', '')))[:10] if isinstance(row, dict) else ''
        if cid is None or (query and (not day or not isinstance(row.get('Query', row.get('query')), str))):
            rejected.append({'row_index': i, 'reason': 'missing_campaign_date_or_query'})
            continue
        item = {'source_row_index': i, 'campaign_id': str(cid), 'date': day or None,
                'period_total': not query and not day,
                'network': network_type(row, source, query),
                **{name: number(row.get(name.capitalize(), row.get(name))) for name in ('impressions', 'clicks', 'cost')}}
        if query:
            item['query'] = row.get('Query', row.get('query'))
            if item['query'] not in normalized_cache:
                normalized_cache[item['query']] = normalize(item['query'])
            item['normalized_query'] = normalized_cache[item['query']]
            key=item['normalized_query']
            if key not in classification_cache:
                classification_cache[key]=classify(key)
            item['classification'] = classification_cache[key]
        result.append(item)
    return result, rejected


def summary(rows):
    result = stats(rows)
    result.update({'distinct_queries': len({r['normalized_query'] for r in rows if 'normalized_query' in r}),
                   'actual_days': sorted({r['date'] for r in rows if r['date']}),
                   'period_total_rows': 0 if rows and 'normalized_query' in rows[0] else sum(bool(r.get('period_total')) for r in rows)})
    return result


def rankings(rows, feed, query_limit=None):
    query_groups, topic_groups, country_groups = defaultdict(list), defaultdict(list), defaultdict(list)
    for row in rows:
        query_groups[row['normalized_query']].append(row)
        topic_groups[row['classification']['topic_id']].append(row)
        country_groups[row['classification']['country_code']].append(row)
    rank = lambda x: (-x['metrics']['clicks_known_sum'], -x['metrics']['cost_known_sum'], -x['metrics']['impressions_known_sum'], x.get('key', ''))
    queries = []
    for key, group in query_groups.items():
        forms = sorted({r['query'] for r in group})
        metrics=summary(group)
        states={r['lifecycle'] for r in group}
        split={next(iter(states)):metrics} if len(states)==1 else {state:summary([r for r in group if r['lifecycle']==state]) for state in sorted(states)}
        queries.append({'key': key, 'query': group[0]['query'], 'forms': forms,
                        'classification': group[0]['classification'], 'metrics': metrics,
                        'campaign_ids': sorted({r['campaign_id'] for r in group}),
                        'metrics_by_lifecycle': split})
    queries.sort(key=rank)
    topics = []
    for key, group in topic_groups.items():
        desc = group[0]['classification']
        flags = sorted({f for r in group for f in r['classification']['intent_review_flags']})
        kind, label = desc['kind'], desc['topic']
        title = 'Туры в ' + label if kind == 'hotel_candidate' else 'Отели ' + label + ': подбор тура' if kind == 'hotel_brand_candidate' else label.replace(' · ', ': ')
        topics.append({'key': key, 'topic': label, 'kind': kind, 'country_code': desc['country_code'],
                       'metrics': summary(group), 'campaign_ids': sorted({r['campaign_id'] for r in group}),
                       'metrics_by_lifecycle': {state: summary([r for r in group if r.get('lifecycle') == state]) for state in sorted({r['lifecycle'] for r in group})},
                       'query_examples': [q['query'] for q in queries if q['classification']['topic_id'] == key][:8],
                       'intent_review_flags': flags, 'hotel_id': None, 'post_id': None, 'entry_id': None,
                       'proposed_post_title': title,
                       'cta': 'Подобрать тур в этот отель' if kind == 'hotel_candidate' else 'Подобрать отель этой сети' if kind == 'hotel_brand_candidate' else 'Подобрать на мои даты',
                       'sample_feed_direction_match': feed_match(desc, feed),
                       'campaign_brand': 'UNASSIGNED', 'feed_applicability': 'REQUIRES_CAMPAIGN_OWNER_REVIEW',
                       'publishable': False})
    topics.sort(key=rank)
    countries = [{'key': key, 'country': group[0]['classification']['country'], 'metrics': summary(group),
                  'metrics_by_lifecycle': {state: summary([r for r in group if r.get('lifecycle') == state]) for state in sorted({r['lifecycle'] for r in group})},
                  'campaign_ids': sorted({r['campaign_id'] for r in group})} for key, group in country_groups.items()]
    countries.sort(key=rank)
    return {'queries': queries if query_limit is None else queries[:query_limit],
            'query_count_before_display_limit': len(queries), 'query_display_limit': query_limit,
            'topics': topics, 'countries': countries}


def build(source, inventory_source=None, feed=None):
    feed = feed or []
    inv_source = inventory_source or source
    inventory, inv_path = pick_list(inv_source, ('campaign_inventory', 'inventory.campaigns', 'inventory.Campaigns', 'config_before.campaigns', 'campaigns', 'Campaigns', 'result.Campaigns'))
    query_raw, query_path = pick_list(source, ('raw_rows', 'query_rows', 'search_query_rows'))
    performance_raw, perf_path = pick_list(inv_source, ('raw_stats', 'campaign_performance_rows', 'performance_rows', 'campaign_statistics.rows'))
    if not perf_path:
        performance_raw, perf_path = pick_list(source, ('raw_stats', 'campaign_performance_rows', 'performance_rows', 'campaign_statistics.rows'))
    queries, query_rejected = metric_rows(query_raw, source, True)
    performance, performance_rejected = metric_rows(performance_raw, inv_source)
    campaigns, duplicate_ids = {}, []
    for row in inventory:
        cid = row.get('Id', row.get('CampaignId', row.get('id')))
        if cid is None:
            continue
        cid = str(cid)
        if cid in campaigns:
            duplicate_ids.append(cid)
        campaigns[cid] = {'id': cid, 'name': row.get('Name', row.get('name', '')), 'type': row.get('Type', row.get('type')),
                          'state': row.get('State', row.get('state')), 'status': row.get('Status', row.get('status')),
                          'timezone': row.get('TimeZone', row.get('timezone')), 'currency': row.get('Currency', row.get('currency')),
                          'lifecycle': campaign_state(row), 'brand': 'UNASSIGNED', 'source_record': row}
    enumerated_ids = set(campaigns)
    supplementary, supplementary_path = pick_list(source, ('config_before.campaigns', 'campaign_inventory', 'inventory.campaigns'))
    for row in supplementary:
        cid = str(row.get('Id', row.get('CampaignId', '')))
        if not cid:
            continue
        # A newer explicit ID lookup can enrich archived/converted records that
        # the account enumeration did not return. Unknown placeholders cannot
        # overwrite known metadata from the inventory.
        if cid not in campaigns or (campaign_state(row) != 'UNKNOWN' and row.get('Name')):
            campaigns[cid] = {'id': cid, 'name': row.get('Name', ''), 'type': row.get('Type'),
                              'state': row.get('State'), 'status': row.get('Status'),
                              'timezone': row.get('TimeZone'), 'currency': row.get('Currency'),
                              'lifecycle': campaign_state(row), 'brand': 'UNASSIGNED', 'source_record': row}
    reported_ids = {r['campaign_id'] for r in queries + performance}
    for cid in reported_ids - set(campaigns):
        campaigns[cid] = {'id': cid, 'name': '', 'type': None, 'state': None, 'status': None, 'timezone': None,
                          'currency': None, 'lifecycle': 'UNKNOWN', 'brand': 'UNASSIGNED', 'source_record': None}
    for row in queries + performance:
        row['lifecycle'] = campaigns[row['campaign_id']]['lifecycle']
    requested = source.get('campaign_ids', source.get('coverage', {}).get('requested_campaign_ids', []))
    requested = [str(x) for x in requested]
    coverage = source.get('coverage', {})
    scopes = coverage.get('campaigns', [])
    complete_ids = {str(x.get('campaign_id', x.get('CampaignId'))) for x in scopes if x.get('complete') is True}
    for batch in coverage.get('batches', []):
        if batch.get('complete') is True:
            complete_ids.update(str(cid) for cid in batch.get('campaign_ids',[]))
    # Global assertion is accepted only for an explicit complete inventory export.
    inventory_complete = inv_source.get('inventory_complete', inv_source.get('coverage', {}).get('inventory_complete')) is True or inv_source.get('config_before',{}).get('complete') is True
    if inventory_source and inventory_source.get('complete') is True:
        inventory_complete = True
    if coverage.get('pagination_complete') is True and requested:
        complete_ids.update(requested)
    discovered = sorted(enumerated_ids)
    search_activity_ids = {str(x) for x in inv_source.get('campaign_ids_with_search_impressions', [])}
    if not search_activity_ids:
        search_activity_ids = {r['campaign_id'] for r in performance if r['network']=='SEARCH' and r['impressions'] is not None and r['impressions'] > 0}
    performance_complete = inv_source.get('stats_coverage',{}).get('complete') is True or inv_source.get('coverage',{}).get('campaign_performance_complete') is True
    inventory_unreported = sorted(set(discovered) - set(requested))
    partial = (not inventory_complete or not performance_complete or not coverage.get('pagination_complete') or search_activity_ids - complete_ids
               or duplicate_ids or query_rejected or performance_rejected or source.get('stream_validated') is False
               or source.get('config_unchanged') is False or inv_source.get('config_unchanged') is False
               or any(r['network']=='UNKNOWN' for r in queries))
    unknown_ids = sorted(cid for cid,c in campaigns.items() if c['lifecycle']=='UNKNOWN')
    by_campaign = {}
    base_meta = dict(source)
    base_meta['campaign_timezones'] = {cid: c['timezone'] for cid, c in campaigns.items() if c['timezone']}
    for cid, campaign in sorted(campaigns.items()):
        qr = [r for r in queries if r['campaign_id'] == cid]
        pr = [r for r in performance if r['campaign_id'] == cid]
        own_meta = {**base_meta, 'status': 'COLLECTED' if cid in complete_ids else 'PARTIAL',
                    'coverage': {**coverage, 'pagination_complete': cid in complete_ids}}
        by_campaign[cid] = {**campaign, 'query_export_complete': cid in complete_ids,
                            'query_metrics': summary(qr), 'campaign_performance_metrics': summary(pr) if pr else None,
                            'performance_by_network': {n: summary([r for r in pr if r['network'] == n]) for n in ('SEARCH','NETWORK','UNKNOWN')},
                            'query_metrics_by_network': {n: summary([r for r in qr if r['network'] == n]) for n in ('SEARCH','NETWORK','UNKNOWN')},
                            'search_rankings': rankings([r for r in qr if r['network'] == 'SEARCH'],feed,query_limit=200),
                            'search_trend': trend_for([r for r in qr if r['network'] == 'SEARCH'],own_meta,cid)}
    all_search = [r for r in queries if r['network'] == 'SEARCH']
    current_search = [r for r in all_search if r['lifecycle'] == 'ACTIVE']
    sections = {}
    for lifecycle in ('ACTIVE','INACTIVE','ARCHIVED','UNKNOWN'):
        sections[lifecycle] = {}
        for network in ('SEARCH','NETWORK','UNKNOWN'):
            qr = [r for r in queries if r['lifecycle'] == lifecycle and r['network'] == network]
            pr = [r for r in performance if r['lifecycle'] == lifecycle and r['network'] == network]
            sections[lifecycle][network] = {'query_metrics': summary(qr), 'campaign_performance_metrics': summary(pr) if pr else None,
                                            'query_campaign_ids': sorted({r['campaign_id'] for r in qr}),
                                            'performance_campaign_ids': sorted({r['campaign_id'] for r in pr})}
    source_meta = {k: v for k, v in source.items() if k not in ('raw_rows','raw_stats','query_rows','search_query_rows','campaign_performance_rows','performance_rows','campaign_statistics','campaign_inventory','inventory','campaigns','Campaigns')}
    return {'analysis_schema': 'account-demand-v1', 'generated_at_utc': datetime.now(timezone.utc).isoformat(),
            'client_login': source.get('client_login', inv_source.get('client_login')),
            'ranking_status': 'PROVISIONAL_INCOMPLETE_ACCOUNT_COVERAGE' if partial else 'ACCOUNT_SEARCH_COLLECTED_WITH_UNKNOWN_CAMPAIGN_METADATA' if unknown_ids else 'ACCOUNT_EXPOSED_SEARCH_ROWS',
            'source': source_meta, 'coverage': {'inventory_path': inv_path, 'inventory_complete': inventory_complete,
                'inventory_campaign_count': len(discovered), 'inventory_campaign_ids': discovered,
                'campaign_registry_count': len(campaigns), 'campaign_registry_ids': sorted(campaigns),
                'supplementary_lookup_path': supplementary_path,
                'supplementary_ids_outside_enumeration': sorted(set(campaigns)-enumerated_ids),
                'unknown_lifecycle_campaign_ids': unknown_ids,
                'performance_complete': performance_complete,
                'inventory_status': inv_source.get('status'), 'inventory_problems': inv_source.get('problems',[]),
                'query_status': source.get('status'), 'query_problems': source.get('problems',[]),
                'required_search_activity_campaign_ids': sorted(search_activity_ids),
                'requested_query_campaign_ids': sorted(set(requested)), 'complete_query_campaign_ids': sorted(complete_ids),
                'inventory_ids_not_requested': inventory_unreported,
                'search_activity_ids_not_requested': sorted(search_activity_ids-set(requested)),
                'search_activity_ids_not_complete': sorted(search_activity_ids-complete_ids),
                'inventory_ids_without_reported_search_activity': sorted(enumerated_ids-search_activity_ids),
                'query_row_campaign_ids': sorted({r['campaign_id'] for r in queries}),
                'query_row_ids_outside_inventory': sorted({r['campaign_id'] for r in queries} - set(discovered)),
                'performance_row_campaign_ids': sorted({r['campaign_id'] for r in performance}),
                'query_source_path': query_path, 'performance_source_path': perf_path,
                'valid_query_rows': len(queries), 'valid_performance_rows': len(performance),
                'query_network_unknown_rows': sum(r['network']=='UNKNOWN' for r in queries),
                'rejected_query_rows': query_rejected, 'rejected_performance_rows': performance_rejected,
                'duplicate_inventory_ids': sorted(set(duplicate_ids)),
                'actual_query_days': sorted({r['date'] for r in queries})},
            'inventory': list(campaigns.values()), 'by_campaign': by_campaign,
            'segments': sections, 'current_search_metrics': summary(current_search),
            'current_search_rankings': rankings(current_search,feed,query_limit=200),
            'broad_search_metrics': summary(all_search), 'broad_search_rankings': rankings(all_search,feed),
            'unknown_search_rankings': rankings([r for r in all_search if r['lifecycle']=='UNKNOWN'],feed,query_limit=200),
            'archived_search_rankings': rankings([r for r in all_search if r['lifecycle']=='ARCHIVED'],feed,query_limit=200),
            'inactive_search_rankings': rankings([r for r in all_search if r['lifecycle']=='INACTIVE'],feed,query_limit=200),
            'all_exposed_query_metrics': summary(queries),
            'account_performance_metrics': summary(performance) if performance else None,
            'feed_collections_sample': feed,
            'notes': ['Широкий месячный поисковый рейтинг включает все выгруженные SEARCH-строки: активные, архивные, выключенные и UNKNOWN. Их вклады показаны отдельно. Рейтинг текущих активных кампаний с State=ON доступен отдельным срезом.',
                      'Текущий статус не восстанавливает историческое состояние кампании. Неизвестный статус не исключает строки из широкого месячного спроса и не превращается в активный.',
                      'Архив, выключенные кампании и строки с неизвестным статусом/типом сети показаны отдельно. Наличие имени кампании не устанавливает принадлежность бренду.',
                      'Итоги campaign-performance и раскрытых поисковых запросов различаются по покрытию; их нельзя подменять друг другом. Неполный аккаунт не считается полным охватом.',
                      'Переданный фид — образец для сопоставления направления. Его применимость к каждой кампании и бренду требует проверки.',
                      'Контракт воронки: наш ID темы/поста/отеля + yclid при входе; сопоставление по chat ID. keyword не используется. Реальные ID не назначены; публикации не выполнялись.',
                      'Сведения об отелях, доступности туров, ценах, лидах и продажах в этом анализе не утверждаются.']
                     + (['Нативные строки одного непагинированного ответа API сохранены без дедупликации метрик. Совпадение пяти видимых измерений не считается уникальным ID строки. Причина повторения из этих данных не установлена.'] if source.get('native_visible_key_duplicates') else [])}


def render(data):
    e=lambda x:html.escape(str(x),quote=True)
    def amount(s,k):
        if s is None or s[k] is None:
            return 'Нет данных'
        return f"{s[k]:,.2f}".replace(',',' ').replace('.',',') if k=='cost' else str(s[k])
    def table(head,body):
        return '<div class="scroll"><table><thead><tr>'+''.join('<th>'+e(x)+'</th>' for x in head)+'</tr></thead><tbody>'+body+'</tbody></table></div>'
    def cells(values):
        return '<tr>'+''.join('<td>'+e(x)+'</td>' for x in values)+'</tr>'
    cov=data['coverage']; partial=data['ranking_status'].startswith('PROVISIONAL')
    notice='НЕПОЛНЫЙ ОХВАТ АККАУНТА — порядок отражает только собранную часть.' if partial else 'Выгрузка поискового спроса завершена для всех кампаний с обнаруженной поисковой активностью. Поисковый отчёт содержит только запросы, раскрываемые Директом.'
    unknown_search_ids=data['segments']['UNKNOWN']['SEARCH']['query_campaign_ids']
    if unknown_search_ids:
        notice+=' У '+str(len(unknown_search_ids))+' поисковых кампаний неизвестен текущий статус. Их поисковые запросы включены в широкий месячный рейтинг и выделены отдельно.'
    overview='<h2>Покрытие аккаунта</h2>'+table(['Показатель','Значение'],''.join(cells(x) for x in [
        ('Логин',data['client_login'] or 'Не указан'),('Кампаний в inventory',cov['inventory_campaign_count']),
        ('Inventory завершён',cov['inventory_complete']),('Всего ID в сводном реестре',cov['campaign_registry_count']),
        ('Performance-пагинация завершена',cov['performance_complete']),
        ('С поисковыми показами за период',len(cov['required_search_activity_campaign_ids'])),('Кампаний с завершённой выгрузкой запросов',len(cov['complete_query_campaign_ids'])),
        ('Поисковая активность есть, запросы не запрошены',', '.join(cov['search_activity_ids_not_requested']) or '—'),
        ('Поисковая выгрузка не завершена',', '.join(cov['search_activity_ids_not_complete']) or '—'),
        ('Без показанной поисковой активности в period-performance',len(cov['inventory_ids_without_reported_search_activity'])),
        ('Строк поискового отчёта',cov['valid_query_rows']),('Строк campaign-performance',cov['valid_performance_rows']),
        ('Фактические дни строк',f"{cov['actual_query_days'][0]}—{cov['actual_query_days'][-1]}" if cov['actual_query_days'] else 'Нет строк')]))
    inv_body=''
    for cid,c in data['by_campaign'].items():
        ps,qs=c['campaign_performance_metrics'],c['query_metrics']
        inv_body+=cells([cid,c['name'],c['type'] or '—',c['state'] or 'UNKNOWN',c['status'] or 'UNKNOWN',c['lifecycle'],
                         c['query_export_complete'],amount(ps,'clicks'),amount(ps,'cost'),amount(qs,'clicks'),
                         amount(c['query_metrics_by_network']['SEARCH'],'clicks'),amount(c['query_metrics_by_network']['NETWORK'],'clicks'),amount(c['query_metrics_by_network']['UNKNOWN'],'clicks')])
    overview+='<h2>Все кампании аккаунта</h2><p>Нулевые строки и отсутствие данных различаются. Архивные и выключенные кампании не становятся активными по имени или наличию старого трафика.</p>'+table(['ID','Название','Тип','State','Status','Группа','Запросы выгружены','Клики: performance','Расход: performance','Клики: queries','SEARCH','NETWORK','Неизвестная сеть'],inv_body)
    seg_body=''
    for lifecycle,networks in data['segments'].items():
        for network,s in networks.items():
            seg_body+=cells([lifecycle,network,amount(s['campaign_performance_metrics'],'clicks'),amount(s['campaign_performance_metrics'],'cost'),amount(s['query_metrics'],'clicks'),amount(s['query_metrics'],'cost'),', '.join(s['query_campaign_ids']) or '—'])
    overview+='<h2>Активные, выключенные и архив · поиск и сети</h2>'+table(['Статус сейчас','Размещение','Клики: performance','Расход: performance','Клики: queries','Расход: queries','Кампании queries'],seg_body)
    if data['source'].get('native_visible_key_duplicates'):
        overview+='<h2>Нативные строки с одинаковыми видимыми измерениями</h2><p>Строки одного непагинированного ответа API сохранены без дедупликации метрик. Совпадение пяти видимых измерений не считается уникальным ID строки. Причина повторения из этих данных не установлена. Диагностика исходного ответа сохранена в JSON.</p>'
    technical=overview
    cards=[('Всего кампаний в сводном реестре',cov['campaign_registry_count']),
           ('Кампаний с поисковыми показами',len(cov['required_search_activity_campaign_ids'])),
           ('Строк реальных запросов',cov['valid_query_rows']),
           ('Разных поисковых запросов',data['broad_search_rankings']['query_count_before_display_limit']),
           ('Кликов в раскрытых запросах',amount(data['broad_search_metrics'],'clicks')),
           ('Поисковых кампаний с неизвестным статусом',len(data['segments']['UNKNOWN']['SEARCH']['query_campaign_ids']))]
    overview='<p>Период: <b>'+e(data['source'].get('date1','не указан'))+' — '+e(data['source'].get('date2','не указан'))+'</b>. Активность кампаний указана на момент выгрузки.</p><div class="cards">'+''.join('<div class="card"><span>'+e(label)+'</span><strong>'+e(value)+'</strong></div>' for label,value in cards)+'</div>'
    rankings=data['broad_search_rankings']
    country_body=''.join(cells([x['country'],amount(x['metrics'],'impressions'),amount(x['metrics'],'clicks'),amount(x['metrics'],'cost'),
                             amount(x['metrics_by_lifecycle'].get('ACTIVE'),'clicks'),amount(x['metrics_by_lifecycle'].get('ARCHIVED'),'clicks'),
                             amount(x['metrics_by_lifecycle'].get('INACTIVE'),'clicks'),amount(x['metrics_by_lifecycle'].get('UNKNOWN'),'clicks'),', '.join(x['campaign_ids'])]) for x in rankings['countries'])
    overview+='<h2>Какие направления ищут</h2><p>Весь собранный поисковый спрос за период. Активные, архивные, остановленные и кампании с неизвестным статусом показаны отдельно.</p>'+table(['Направление','Показы','Клики','Расход','Активные сейчас','Архив','Остановлены','Статус неизвестен','Кампании'],country_body)
    topic_body=''
    for t in rankings['topics'][:20]:
        matches=t['sample_feed_direction_match']['collections']
        sample=', '.join(str(x['id']) for x in matches) or 'Не сопоставлено с образцом'
        topic_body+=cells([t['topic'],t['kind'],amount(t['metrics'],'clicks'),amount(t['metrics'],'cost'),
                          amount(t['metrics_by_lifecycle'].get('ACTIVE'),'clicks'),amount(t['metrics_by_lifecycle'].get('ARCHIVED'),'clicks'),
                          amount(t['metrics_by_lifecycle'].get('UNKNOWN'),'clicks'),', '.join(t['campaign_ids']),'; '.join(t['query_examples'][:3]),'; '.join(t['intent_review_flags']) or '—',sample,t['proposed_post_title'],t['cta']])
    overview+='<h2>20 тем для подготовки постов</h2><p>Весь собранный поиск. Вклад архива и кампаний с неизвестным статусом сохранён. Применимость образца фида к каждой кампании требует проверки. Названия постов — предложения для просмотра.</p>'+table(['Тема','Тип','Клики','Расход','Активные сейчас','Архив','Статус неизвестен','Кампании','Запросы','Проверить намерение','Образец фида','Название поста','Кнопка'],topic_body)
    qbody=''.join(cells([q['query'],q['classification']['topic'],amount(q['metrics'],'clicks'),amount(q['metrics'],'cost'),', '.join(q['campaign_ids'])]) for q in rankings['queries'][:30])
    overview+='<h2>30 самых кликаемых формулировок</h2>'+table(['Запрос','Тема','Клики','Расход','Кампании'],qbody)
    for key,label in [('current_search_rankings','Текущие активные кампании ON: отдельный срез'),('unknown_search_rankings','Неизвестный статус UNKNOWN: отдельный срез')]:
        body=''.join(cells([t['topic'],amount(t['metrics'],'clicks'),amount(t['metrics'],'cost'),', '.join(t['campaign_ids'])]) for t in data[key]['topics'][:30])
        overview+='<h2>'+e(label)+'</h2>'+table(['Тема','Клики','Расход','Кампании'],body)
    per_campaign=''
    for cid,c in data['by_campaign'].items():
        topics=c['search_rankings']['topics'][:10]
        body=''.join(cells([t['topic'],amount(t['metrics'],'clicks'),amount(t['metrics'],'cost'),'; '.join(t['query_examples'][:2])]) for t in topics)
        countries='; '.join(x['country']+': '+amount(x['metrics'],'clicks')+' кликов' for x in c['search_rankings']['countries'][:8]) or 'Нет SEARCH-строк'
        per_campaign+='<details><summary>'+e(cid+' · '+c['name']+' · '+c['lifecycle'])+'</summary><p>'+e(countries)+'</p>'+table(['Тема','Клики','Расход','Примеры'],body)+'</details>'
    overview+='<details><summary>Направления и темы по каждой кампании</summary>'+per_campaign+'</details>'
    overview+='<details><summary>Покрытие, все кампании и технические границы выгрузки</summary>'+technical+'</details>'
    notes='<ul>'+''.join('<li>'+e(x)+'</li>' for x in data['notes'])+'</ul>'
    return '<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Директ · весь аккаунт и спрос</title><style>body{margin:0;background:#f5f7fa;color:#17243b;font:15px/1.5 system-ui}main{max-width:1550px;margin:auto;padding:32px 24px}h1{font-size:30px;line-height:1.2}h2{font-size:22px;margin-top:36px}.notice{padding:16px;border-radius:10px;background:#fff1d5;border:1px solid #e4ca97}.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:24px 0}.card{background:white;border:1px solid #dfe6ef;border-radius:10px;padding:16px}.card span{display:block;font-size:13px;color:#52617a}.card strong{display:block;font-size:27px;margin-top:7px}.scroll{overflow:auto;background:#fff;border:1px solid #dfe6ef;border-radius:10px}table{width:100%;border-collapse:collapse;font-size:13px}th,td{padding:11px 13px;border-bottom:1px solid #e4e9f1;text-align:left;vertical-align:top}th{background:#eaf0f7;white-space:nowrap}p,li{max-width:1200px;color:#52617a}details{background:white;border:1px solid #dfe6ef;border-radius:10px;padding:12px;margin:10px 0}summary{cursor:pointer;font-weight:600}@media(max-width:600px){main{padding:22px 14px}h1{font-size:25px}}</style><main><h1>Директ: весь аккаунт → поисковый спрос → тематическая воронка</h1><div class="notice">'+e(notice)+'</div>'+overview+'<h2>Границы анализа</h2>'+notes+'</main></html>'


def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('report',type=Path)
    p.add_argument('--inventory',type=Path)
    p.add_argument('--feed',type=Path)
    p.add_argument('--feed-summary',type=Path)
    p.add_argument('--query-network-type',choices=['SEARCH','NETWORK','UNKNOWN'])
    p.add_argument('--out-dir',type=Path)
    args=p.parse_args()
    source=json.loads(args.report.read_text(encoding='utf-8'))
    if args.query_network_type:
        source['query_network_type']=args.query_network_type
        source['query_network_type_basis']='EXPLICIT_CALLER_ASSERTION_OF_VERIFIED_REPORT_TYPE'
    inventory=json.loads(args.inventory.read_text(encoding='utf-8')) if args.inventory else None
    feed=parse_feed(args.feed) if args.feed else json.loads(args.feed_summary.read_text(encoding='utf-8'))['collections'] if args.feed_summary else []
    result=build(source,inventory,feed)
    result['source_sha256']=hashlib.sha256(args.report.read_bytes()).hexdigest()
    if args.inventory:
        result['inventory_sha256']=hashlib.sha256(args.inventory.read_bytes()).hexdigest()
    out=args.out_dir or args.report.parent/'account_analysis'
    out.mkdir(parents=True,exist_ok=True)
    # Stream compact JSON instead of allocating a second full rendered copy.
    with (out/'account-topic-candidates.json').open('w',encoding='utf-8') as target:
        json.dump(result,target,ensure_ascii=False,separators=(',',':'),allow_nan=False)
        target.write('\n')
    (out/'account-demand.html').write_text(render(result),encoding='utf-8')
    print(json.dumps({'ranking_status':result['ranking_status'],'inventory_campaigns':result['coverage']['inventory_campaign_count'],'query_rows':result['coverage']['valid_query_rows'],'out_dir':str(out)},ensure_ascii=False))


if __name__=='__main__':
    main()
