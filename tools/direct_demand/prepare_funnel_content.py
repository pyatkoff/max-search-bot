#!/usr/bin/env python3
"""Prepare private funnel work files using a read-only hotel knowledge archive.

Name matches are content references, never verified native catalogue bindings.
Inputs and generated customer/account data must not be committed to GitHub.
"""
from __future__ import annotations

import argparse
from collections import defaultdict, Counter
from datetime import datetime, timezone
import hashlib
import html
import json
from pathlib import Path
import re
import unicodedata
import zipfile
import xml.etree.ElementTree as ET


def read_json(path):
    return json.loads(path.read_text(encoding='utf-8'))


def encoded(value):
    return (json.dumps(value, ensure_ascii=False, indent=2) + '\n').encode()


def normalized_name(value):
    # Retain every word, location and ex-name. No fuzzy or token-subset approval.
    text = ''.join(c for c in unicodedata.normalize('NFKD', value.casefold())
                   if not unicodedata.combining(c))
    return re.sub(r'[^\w]+', ' ', text).strip()


COUNTRY = {'Турция': 'TR', 'Египет': 'EG', 'Вьетнам': 'VN', 'Таиланд': 'TH',
           'ОАЭ': 'UAE', 'Оман': 'OM', 'Шри-Ланка': 'LK', 'Россия': 'RU'}


def build(root, knowledge_path, draft_path):
    hotels = read_json(root / 'expanded_hotel_shortlist.json')['candidates']
    sales = read_json(root / 'top500_funnel_seed.json')['ranked_name_rows']
    themes = read_json(root / 'thematic_selection_candidates.json')['candidates']
    drafts = read_json(draft_path)['drafts']
    with zipfile.ZipFile(knowledge_path) as source:
        index_bytes = source.read('plan155/working-knowledge-index.json')
        index = json.loads(index_bytes)
        baseline = json.loads(source.read(index['baseline']['full_snapshot_ref']))
    names = defaultdict(list)
    for key, label in index['hotel_names'].items():
        names[normalized_name(label)].append(key)
    countries = {x['hotel_key']: x['country'] for arr in
                 ('hotel_properties', 'regional_research_properties', 'new_research_hotels')
                 for x in baseline[arr]}
    counts_by_hotel = Counter()
    for variants in index['claims'].values():
        for key in {v['record'].get('hotel_key') for v in variants} - {None}:
            counts_by_hotel[key] += 1
    matches = []
    for source_type, rows in [('direct', hotels), ('top500', sales)]:
        for number, row in enumerate(rows):
            label = row['label'] if source_type == 'direct' else row['hotel']
            country = COUNTRY.get(row.get('country'))
            keys = names.get(normalized_name(label), [])
            if country:
                keys = [k for k in keys if countries.get(k) == country]
            key = keys[0] if len(keys) == 1 else None
            matches.append({
                'source': source_type, 'source_row_0based': number,
                'source_key': row.get('demand_candidate_key'), 'sales_rank': row.get('rank'),
                'label': label, 'country': country,
                'match_status': 'UNIQUE_NORMALIZED_NAME_CONTENT_REFERENCE' if key else 'UNRESOLVED',
                'knowledge_profile_key': key, 'knowledge_profile_name': index['hotel_names'].get(key),
                'knowledge_country': countries.get(key), 'claim_id_count': counts_by_hotel[key] if key else 0,
                'catalog_id': None, 'catalog_identity_verified': False,
                'note': 'Name-only reuse for content preparation; native catalogue ID still required.',
            })
    proof_count = 0
    for draft in drafts:
        proofs = []
        for cid in draft['claim_ids']:
            variants = index['claims'].get(cid)
            if not variants:
                raise ValueError('Missing claim: ' + cid)
            records = [v['record'] for v in variants]
            hotels_in_claim = {r.get('hotel_key') for r in records} - {None}
            if not hotels_in_claim.issubset(set(draft['knowledge_profile_keys'])):
                raise ValueError('Cross-hotel claim: ' + cid)
            source_ids = sorted({s for r in records for s in r.get('source_ids', [])})
            sources = []
            for sid in source_ids:
                if sid not in index['sources_including_unclaimed']:
                    raise ValueError('Missing source: ' + sid)
                for v in index['sources_including_unclaimed'][sid]:
                    record = v['record']
                    summary = {k: record.get(k) for k in
                               ('id', 'hotel_key', 'url', 'kind', 'retrieved_on',
                                'document_season', 'read_scope', 'limitations')}
                    if summary not in sources:
                        sources.append(summary)
            proofs.append({'claim_id': cid, 'record_variants': records, 'sources': sources})
        draft.update(status='DRAFT_REQUIRES_FACT_AND_DESTINATION_REVIEW', publishable=False,
                     channel_id=None, post_id=None, entry_id=None, button_url=None,
                     catalog_ids=[], price=None, availability=None, proof=proofs,
                     images={'status': 'NOT_SELECTED', 'urls': []},
                     facts_as_saved_in='hotel knowledge version 92; no new live verification')
        proof_count += len(proofs)
    # These logical collection keys cannot be mistaken for issued entry/post IDs.
    feed = []
    drafts_by_key = {d['source_candidate_key']: d for d in drafts}
    for kind, rows in [('hotel', hotels), ('selection', themes)]:
        for row in rows:
            key = row['demand_candidate_key'] if kind == 'hotel' else row['candidate_key']
            title = row['label'] if kind == 'hotel' else row['proposed_selection_title']
            draft = drafts_by_key.get(key)
            feed.append({'draft_collection_key': 'draft_' + kind + '_' + key,
                         'kind': kind, 'source_candidate_key': key, 'name': title,
                         'description': ('Туры в ' + title + '. Подберите даты поездки в боте.')
                         if kind == 'hotel' else title + '. Подберите подходящие туры в боте.',
                         'picture': None, 'url': None, 'channel_id': None, 'post_id': None,
                         'entry_id': None, 'catalog_id': None, 'enabled': False,
                         'post_draft_key': draft['draft_key'] if draft else None,
                         'missing': ['catalog_or_selection_binding', 'channel', 'post',
                                     'issued_entry_id', 'destination_url', 'approved_image']})
    now = datetime.now(timezone.utc).isoformat()
    summary = {'generated_at_utc': now, 'direct_candidates': len(hotels),
               'direct_exact_name_references': sum(x['source'] == 'direct' and
                   x['knowledge_profile_key'] is not None for x in matches),
               'top500_rows': len(sales), 'top500_exact_name_references': sum(
                   x['source'] == 'top500' and x['knowledge_profile_key'] is not None for x in matches),
               'verified_catalog_ids': 0, 'post_drafts': len(drafts),
               'source_claim_references': proof_count,
               'draft_feed_records': len(feed), 'upload_ready_feed_records': 0,
               'channel': None, 'published_posts': 0,
               'wordstat_access': 'NOT_YET_VERIFIED'}
    provenance = {'library_file_id': 'libfile_e554d6c3171c8191926e5a7ae8bcd1e4',
                  'version': 92, 'knowledge_index_member': 'plan155/working-knowledge-index.json',
                  'knowledge_index_sha256': hashlib.sha256(index_bytes).hexdigest(),
                  'read_only': True, 'knowledge_source_modified': False,
                  'feed_sample_library_file_id': 'libfile_c21f6fe2dee881919162f8ed3d35bfd4',
                  'feed_format': 'yml_catalog/shop/collections/collection; empty offers'}
    output = {'schema': 'anytour.funnel.content_preparation.v1', 'summary': summary,
              'source': provenance, 'name_references': matches, 'post_drafts': drafts,
              'draft_feed_records': feed,
              'limitations': ['Exact name references are not verified native catalogue IDs.',
                              'No positional joining of TOP500 name rows and legacy IDs.',
                              'No present prices, availability, future service guarantees or photo approval.',
                              'Inactive XML templates omit URL/picture and are not upload-ready.',
                              'Channel will be provided by owner later.']}
    xml = ET.Element('yml_catalog', date=now[:16].replace('T', ' '))
    shop = ET.SubElement(xml, 'shop')
    ET.SubElement(shop, 'name').text = 'Any Tour MAX — DRAFT'
    ET.SubElement(shop, 'company').text = 'Any Tour'
    ET.SubElement(shop, 'url').text = 'https://AnyTour.online/'
    ET.SubElement(shop, 'offers')
    collections = ET.SubElement(shop, 'collections')
    collections.append(ET.Comment('INCOMPLETE DRAFT: no destinations or pictures; DO NOT UPLOAD'))
    for row in feed:
        node = ET.SubElement(collections, 'collection', id=row['draft_collection_key'])
        node.append(ET.Comment('URL and picture pending; no issued entry ID'))
        ET.SubElement(node, 'name').text = row['name']
        ET.SubElement(node, 'description').text = row['description']
    ET.indent(xml)
    xml_bytes = ET.tostring(xml, encoding='utf-8', xml_declaration=True)
    out = root / 'deliverables'
    content_bytes = encoded(output)
    (out / 'funnel-content-preparation.json').write_bytes(content_bytes)
    (out / 'funnel-collections.INCOMPLETE-DRAFT.xml').write_bytes(xml_bytes)
    # Insert a bounded review section into the existing report; preserve all demand analysis.
    esc = lambda x: html.escape(str(x), quote=True)
    cards = []
    for draft in drafts:
        urls = sorted({s['url'] for p in draft['proof'] for s in p['sources'] if s.get('url')})
        cards.append('<details><summary>' + esc(draft['title']) + '</summary><p>' +
                     esc(draft['body']).replace('\n\n', '</p><p>').replace('\n', '<br>') +
                     '</p><p><b>Кнопка:</b> ' + esc(draft['button_label']) + ' · ссылка ожидает привязки</p>' +
                     '<small>Основание: ' + ', '.join(esc(x) for x in draft['claim_ids']) + '</small>' +
                     '<small>' + ' · '.join('<a href="' + esc(u) + '">Источник</a>' for u in urls) +
                     '</small></details>')
    section = '<!-- FUNNEL_PREPARATION_BEGIN --><section><h2>Автопилот: подготовка контента</h2>' + \
              '<p>Ежечасная задача включена. Канал ожидается от владельца; публикация и рекламный запуск не выполнены.</p>' + \
              '<p><b>' + str(summary['direct_exact_name_references']) + '</b> точных совпадений названий из 317 позиций Директа; <b>' + \
              str(summary['top500_exact_name_references']) + '</b> из 500 строк ТОП500. Это ссылки на материалы гостиничной базы, не подтверждённые ID каталога.</p>' + \
              '<p><b>' + str(len(drafts)) + '</b> черновиков постов с ' + str(proof_count) + ' ссылками на сохранённые утверждения; <b>' + str(len(feed)) + \
              '</b> заготовки коллекций в формате существующего фида. Фотографии и адреса входов ещё не заполнены. XML неполный и к загрузке не готов.</p>' + \
              '<p class="note">Источник фактов — существующая гостиничная база, версия 92. Новая проверка сайтов в этом проходе не выполнялась. Сезон и конкретный тариф требуют проверки перед публикацией.</p>' + \
              ''.join(cards) + '</section><!-- FUNNEL_PREPARATION_END -->'
    report_path = out / 'anytour-demand-20261022.html'
    report = report_path.read_text()
    report = re.sub(r'<!-- FUNNEL_PREPARATION_BEGIN -->.*?<!-- FUNNEL_PREPARATION_END -->', '', report, flags=re.S)
    report = report.replace('</header>', '</header>' + section, 1)
    report = report.replace('Пересечения с найденными названиями и переименования ещё не проверены.',
                            'Первый проход точных совпадений названий с гостиничной базой выполнен; привязка к ID каталога и переименования ещё требуют проверки.')
    report_bytes = report.encode()
    report_path.write_bytes(report_bytes)
    provenance_path = out / 'source-provenance.json'
    package_provenance = read_json(provenance_path)
    package_provenance['content_preparation'] = {'summary': summary, 'source': provenance,
                                               'sha256': hashlib.sha256(content_bytes).hexdigest()}
    package_provenance['report_sha256'] = hashlib.sha256(report_bytes).hexdigest()
    provenance_bytes = encoded(package_provenance)
    provenance_path.write_bytes(provenance_bytes)
    archive_path = out / 'anytour-demand-20261022.zip'
    replacements = {'report.html': report_bytes, 'source-provenance.json': provenance_bytes,
                    'funnel-content-preparation.json': content_bytes,
                    'funnel-collections.INCOMPLETE-DRAFT.xml': xml_bytes,
                    'funnel-post-drafts-input.json': draft_path.read_bytes(),
                    'prepare-funnel-content.py': Path(__file__).read_bytes()}
    pending = archive_path.with_suffix('.content-pending.zip')
    with zipfile.ZipFile(archive_path) as old, zipfile.ZipFile(pending, 'w', zipfile.ZIP_DEFLATED, compresslevel=7) as new:
        for item in old.infolist():
            if item.filename not in replacements:
                new.writestr(item, old.read(item.filename))
        for name, body in replacements.items():
            new.writestr(name, body)
    with zipfile.ZipFile(pending) as check:
        if check.testzip() or len(check.namelist()) != len(set(check.namelist())):
            raise ValueError('Invalid archive')
    pending.replace(archive_path)
    (out / 'funnel-preparation-summary.json').write_bytes(encoded(summary))
    return summary


def append_reviewed_batch(root, knowledge_path, batch_path):
    """Append reviewed drafts without rebuilding or losing catalog/media work."""
    out = root / 'deliverables'
    data = read_json(out / 'funnel-content-preparation.json')
    batch = read_json(batch_path)
    batch_hash = hashlib.sha256(batch_path.read_bytes()).hexdigest()
    for previous in data.get('reviewed_content_batches', []):
        if previous['batch_id'] == batch['batch_id']:
            if previous['input_sha256'] != batch_hash:
                raise ValueError('A completed batch ID cannot be reused with different contents')
            return {'changed': False, 'reason': 'BATCH_ALREADY_COMPLETED', 'summary': data['summary']}
    existing_keys = {d['draft_key'] for d in data['post_drafts']}
    feed_by_source = {r['source_candidate_key']: r for r in data['draft_feed_records']}
    assert len(existing_keys) == len(data['post_drafts'])
    batch_keys = [d['draft_key'] for d in batch['drafts']]
    assert len(batch_keys) == len(set(batch_keys)) and not existing_keys.intersection(batch_keys)
    with zipfile.ZipFile(knowledge_path) as archive:
        index_bytes = archive.read(batch['knowledge_index_member'])
        index = json.loads(index_bytes)
    added = []
    for draft in batch['drafts']:
        key = draft['source_candidate_key']
        if key not in feed_by_source:
            raise ValueError('Draft has no existing funnel/feed source: ' + key)
        proof = []
        for cid in draft['claim_ids']:
            variants = index['claims'][cid]
            hotels = {v['record'].get('hotel_key') for v in variants} - {None}
            if not hotels or not hotels <= set(draft['knowledge_profile_keys']):
                raise ValueError('Hotel scope mismatch: ' + cid)
            source_ids = sorted({s for v in variants for s in v['record'].get('source_ids', [])})
            if not source_ids:
                raise ValueError('Claim has no sources: ' + cid)
            sources = []
            for sid in source_ids:
                for variant in index['sources_including_unclaimed'][sid]:
                    record = variant['record']
                    source = {k: record.get(k) for k in ('id', 'hotel_key', 'url', 'kind',
                              'retrieved_on', 'document_season', 'read_scope', 'limitations')}
                    if source not in sources:
                        sources.append(source)
            proof.append({'claim_id': cid, 'record_variants': [v['record'] for v in variants],
                          'sources': sources, 'future_validity': 'UNVERIFIED'})
        row = feed_by_source[key]
        is_selection = row['kind'] == 'selection'
        if is_selection and not draft.get('selection_eligibility', {}).get('required_checks'):
            raise ValueError('A thematic draft requires explicit eligibility checks')
        draft.update(kind='selection' if is_selection else 'hotel',
                     status='REVIEWABLE_CONTENT_DRAFT_IDENTITY_AND_DATE_CHECKS_PENDING',
                     publishable=False, channel_id=None, post_id=None, entry_id=None,
                     button_url=None, catalog_ids=[row['catalog_id']] if row.get('catalog_id') else [],
                     catalog_namespace=row.get('catalog_namespace'), price=None, availability=None,
                     images={'status': 'NOT_SELECTED', 'urls': []}, proof=proof,
                     prepared_from={'library_file_id': 'libfile_e554d6c3171c8191926e5a7ae8bcd1e4',
                                    'version': batch['knowledge_library_version'],
                                    'index_member': batch['knowledge_index_member'],
                                    'index_sha256': hashlib.sha256(index_bytes).hexdigest(),
                                    'new_live_property_verification': False})
        row['post_draft_key'] = draft['draft_key']
        if draft.get('feed_description'):
            row['description'] = draft['feed_description']
        added.append(draft)
    data['post_drafts'].extend(added)
    theme_keys = {r['source_candidate_key'] for r in data['draft_feed_records'] if r['kind'] == 'selection'}
    for draft in data['post_drafts']:
        draft.setdefault('kind', 'selection' if draft['source_candidate_key'] in theme_keys else 'hotel')
    now = datetime.now(timezone.utc).isoformat()
    summary = data['summary']
    summary.update(generated_at_utc=now, post_drafts=len(data['post_drafts']),
                   hotel_post_drafts=sum(d['kind'] == 'hotel' for d in data['post_drafts']),
                   thematic_post_drafts=sum(d['kind'] == 'selection' for d in data['post_drafts']),
                   source_claim_references=sum(len(d['proof']) for d in data['post_drafts']))
    completed = {'batch_id': batch['batch_id'], 'input_sha256': batch_hash,
                 'completed_at_utc': now, 'new_drafts': len(added),
                 'new_hotels': sum(d['kind'] == 'hotel' for d in added),
                 'new_selections': sum(d['kind'] == 'selection' for d in added),
                 'skipped': batch.get('skipped', []), 'source_version': batch['knowledge_library_version']}
    data.setdefault('reviewed_content_batches', []).append(completed)
    data['limitations'] = [
        'These are reviewable drafts. Identity, dates, service operation and photos remain partial.',
        'Only the five previously verified native LOCAL identities are confirmed; new content does not issue IDs.',
        'No prices, availability, entry/post IDs or channel publication.',
        'Thematic source facts retain room/facility/season/child-age boundaries.',
        'Incomplete XML is not upload-ready; no Wordstat frequency has been collected.']
    # Change only descriptions in the existing incomplete XML, preserving catalog and photo work.
    xml_path = out / 'funnel-collections.INCOMPLETE-DRAFT.xml'
    xml = ET.parse(xml_path)
    by_collection = {r['draft_collection_key']: r for r in data['draft_feed_records']}
    for collection in xml.findall('./shop/collections/collection'):
        row = by_collection[collection.attrib['id']]
        collection.find('description').text = row['description']
        assert collection.find('url') is None
    assert len(xml.findall('./shop/collections/collection')) == len(data['draft_feed_records'])
    ET.indent(xml)
    xml_bytes = ET.tostring(xml.getroot(), encoding='utf-8', xml_declaration=True)
    esc = lambda s: html.escape(str(s), quote=True)
    cards = []
    for draft in added:
        urls = sorted({s['url'] for p in draft['proof'] for s in p['sources'] if s.get('url')})
        checks = draft.get('selection_eligibility', {}).get('required_checks', [])
        cards.append('<details><summary>' + esc(draft['title']) + '</summary><p>' +
                     esc(draft['body']).replace('\n\n', '</p><p>').replace('\n', '<br>') +
                     '</p><p><b>Кнопка:</b> ' + esc(draft['button_label']) + ' · адрес ещё не выдан</p>' +
                     ('<small>Перед включением в подборку: ' + esc('; '.join(checks)) + '</small>' if checks else '') +
                     '<small>Основание: ' + ', '.join(esc(x) for x in draft['claim_ids']) + '</small><small>' +
                     ' · '.join('<a href="' + esc(u) + '">Источник</a>' for u in urls) + '</small></details>')
    section = ('<!-- FUNNEL_BATCH_' + esc(batch['batch_id']) + '_BEGIN --><section><h2>Новые посты и подборки</h2><p><b>Всего ' +
               str(summary['post_drafts']) + ' черновика</b>: ' + str(summary['hotel_post_drafts']) + ' гостиничных постов и ' +
               str(summary['thematic_post_drafts']) + ' тематические подборки. Добавлено за проход: ' + str(len(added)) +
               '. Коллекций фида — ' + str(summary['draft_feed_records']) + '; активных адресов перехода — 0.</p>' +
               '<p class="note">Факты повторно использованы из гостиничной базы версии ' + str(batch['knowledge_library_version']) +
               '. Это подготовка контента: новая проверка сайтов, цен и услуг на даты поездки в этом проходе не выполнялась. ID и фотографии новых отелей ещё требуют проверки.</p>' +
               ''.join(cards) + '<p class="note">Crystal Prestige временно отложен: в источнике другое полное название, и связь с коротким названием очереди пока не подтверждена.</p></section><!-- FUNNEL_BATCH_' + esc(batch['batch_id']) + '_END -->')
    report_path = out / 'anytour-demand-20261022.html'
    report = report_path.read_text()
    report = report.replace('<b>Всего 15 черновиков постов</b>', '<b>Пакет каталога: 15 черновиков на момент подготовки</b>')
    report = report.replace('</header>', '</header>' + section, 1)
    report_bytes = report.encode()
    content_bytes = encoded(data)
    queue_path = out / 'anytour-demand-priorities-20261022.json'
    queue = read_json(queue_path)
    queue['content_post_counts'] = {k: summary[k] for k in ('post_drafts', 'hotel_post_drafts', 'thematic_post_drafts')}
    queue['latest_content_batch'] = completed
    queue_bytes = encoded(queue)
    provenance_path = out / 'source-provenance.json'
    provenance = read_json(provenance_path)
    provenance['content_preparation']['summary'] = summary
    provenance['content_preparation']['sha256'] = hashlib.sha256(content_bytes).hexdigest()
    provenance['report_sha256'] = hashlib.sha256(report_bytes).hexdigest()
    provenance['working_queue_sha256'] = hashlib.sha256(queue_bytes).hexdigest()
    provenance.setdefault('content_batches', []).append(completed)
    replacements = {'report.html': report_bytes, 'priorities.json': queue_bytes,
                    'source-provenance.json': encoded(provenance),
                    'funnel-content-preparation.json': content_bytes,
                    'funnel-collections.INCOMPLETE-DRAFT.xml': xml_bytes,
                    'funnel-content-batches/' + batch['batch_id'] + '.json': batch_path.read_bytes(),
                    'prepare-funnel-content.py': Path(__file__).read_bytes(),
                    'README.txt': ('Актуальная очередь: priorities.json; тексты, источники, связи и ограничения: funnel-content-preparation.json. '
                                   + str(summary['post_drafts']) + ' черновика: ' + str(summary['hotel_post_drafts']) + ' отелей и '
                                   + str(summary['thematic_post_drafts']) + ' тематические подборки.5 подтверждённых LOCAL ID,4 обложки. '
                                   'Фид содержит356 неполных заготовок без адресов входа; не загружать. '
                                   'Канал ещё не передан; публикации, трат и изменений гостиничной базы нет. '
                                   'Частотность Wordstat не собрана. Исходный запросный отчёт и предыдущие материалы сохранены.\n').encode()}
    archive_path = out / 'anytour-demand-20261022.zip'
    pending = archive_path.with_suffix('.batch-pending.zip')
    with zipfile.ZipFile(archive_path) as old, zipfile.ZipFile(pending, 'w', zipfile.ZIP_DEFLATED, compresslevel=7) as new:
        for entry in old.infolist():
            if entry.filename not in replacements:
                new.writestr(entry, old.read(entry.filename))
        for name, value in replacements.items():
            new.writestr(name, value)
    with zipfile.ZipFile(pending) as checked:
        assert checked.testzip() is None and len(checked.namelist()) == len(set(checked.namelist()))
    report_path.write_bytes(report_bytes)
    queue_path.write_bytes(queue_bytes)
    provenance_path.write_bytes(replacements['source-provenance.json'])
    (out / 'funnel-content-preparation.json').write_bytes(content_bytes)
    xml_path.write_bytes(xml_bytes)
    (out / 'funnel-preparation-summary.json').write_bytes(encoded(summary))
    pending.replace(archive_path)
    return {'changed': True, 'batch': completed, 'summary': summary}


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, default=Path(__file__).resolve().parent)
    parser.add_argument('--knowledge', type=Path, required=True)
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--drafts', type=Path)
    mode.add_argument('--append-drafts', type=Path)
    args = parser.parse_args()
    result = (append_reviewed_batch(args.root.resolve(), args.knowledge, args.append_drafts)
              if args.append_drafts else build(args.root.resolve(), args.knowledge, args.drafts))
    print(json.dumps(result, ensure_ascii=False))
