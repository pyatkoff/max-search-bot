#!/usr/bin/env python3
"""Attach explicitly reviewed LOCAL identities and media to private funnel drafts.

This reads captured content-only API responses, not a provider search. Never
commit the review input, snapshots, photos or generated account work package.
"""
import argparse
import base64
from datetime import datetime, timezone
import hashlib
import html
import json
from pathlib import Path
import re
import zipfile
import xml.etree.ElementTree as ET


def load(path):
    return json.loads(path.read_text(encoding='utf-8'))


def encode(value):
    return (json.dumps(value, ensure_ascii=False, indent=2) + '\n').encode()


def sha(value):
    return hashlib.sha256(value).hexdigest()


def build(root, review_path, knowledge_path):
    out = root / 'deliverables'
    data = load(out / 'funnel-content-preparation.json')
    review = load(review_path)
    now = datetime.now(timezone.utc).isoformat()
    items, captures, requested, missing = {}, {}, set(), set()
    for path in sorted((root / 'catalog_reads').glob('top500_ids_*.json')):
        capture = load(path)
        assert capture.get('ok') is True and capture.get('source') == 'anytour-local-tv-catalog-v1'
        requested.update(capture['requestedIds'])
        missing.update(capture['missingIds'])
        for item in capture['items']:
            assert item['id'] in capture['requestedIds'] and item['catalog'] == 'local-tv'
            items[item['id']] = item
            captures[item['id']] = {'member': 'catalog-reads/' + path.name,
                                   'capture_sha256': sha(path.read_bytes()),
                                   'url': capture['_evidence']['url']}
    cohort = set(load(root / 'top500_funnel_seed.json')['legacy_tourvisor_ids'])
    assert requested == cohort and set(items) | missing == cohort and not set(items) & missing
    bound_ids = set()
    for binding in review['bindings']:
        item = items[binding['hotel_id']]
        assert item['name'] == binding['expected_name']
        assert item['country']['name'] == binding['expected_country']
        binding.update(catalog_namespace='local-tv', identity_verified=True,
                       revision=item['revision'], details_fetched_at=item['detailsFetchedAt'],
                       source=captures[item['id']] | {'input_corpus': binding['source']})
        bound_ids.add(item['id'])
    photos = load(root / 'post_photo_candidates/manifest.json')
    rejected = {r['file'] for r in review['rejected_photos']}
    approved = {}
    for photo in photos:
        if photo.get('error') or Path(photo['local_path']).name in rejected:
            continue
        path = root / 'post_photo_candidates' / Path(photo['local_path']).name
        assert sha(path.read_bytes()) == photo['sha256']
        assert photo['url'] in items[photo['hotel_id']]['images']
        photo.update(reviewed=True, status='SELECTED_FOR_DRAFT_COVER',
                     review_scope='Visual cover review and native-catalog attribution; not facility/season proof.',
                     archive_member='post-photos/' + path.name)
        approved[photo['hotel_id']] = photo
    with zipfile.ZipFile(knowledge_path) as knowledge:
        index = json.loads(knowledge.read('plan155/working-knowledge-index.json'))
    added_keys = {d['draft_key'] for d in review['drafts']}
    drafts = [d for d in data['post_drafts'] if d['draft_key'] not in added_keys]
    for draft in review['drafts']:
        native_id = draft['hotel_id']
        assert native_id in bound_ids and items[native_id]['detailsAvailable']
        proofs = []
        for cid in draft['claim_ids']:
            if cid in review['native_claims']:
                claim = review['native_claims'][cid]
                assert claim['hotel_id'] == native_id
                if claim.get('field'):
                    value = items[native_id]
                    for field in claim['field'].split('.'):
                        value = value[field]
                    proof = {'claim_id': cid, 'record_variants': [claim],
                             'source_field_value': value,
                             'source': captures[native_id],
                             'source_revision': items[native_id]['revision'],
                             'source_fetched_at': items[native_id]['detailsFetchedAt']}
                else:
                    proof = {'claim_id': cid, 'record_variants': [claim], 'sources': [claim]}
            else:
                variants = index['claims'][cid]
                assert {v['record'].get('hotel_key') for v in variants} - {None} <= set(draft['knowledge_profile_keys'])
                source_ids = {s for v in variants for s in v['record'].get('source_ids', [])}
                proof = {'claim_id': cid, 'record_variants': [v['record'] for v in variants],
                         'sources': [v['record'] for sid in sorted(source_ids)
                                     for v in index['sources_including_unclaimed'][sid]]}
            proofs.append(proof)
        draft.update(status='DRAFT_REQUIRES_DESTINATION_AND_FINAL_FACT_REVIEW',
                     publishable=False, channel_id=None, post_id=None, entry_id=None,
                     button_url=None, catalog_ids=[native_id], catalog_namespace='local-tv',
                     price=None, availability=None, proof=proofs,
                     images={'status': 'SELECTED_FOR_DRAFT_COVER' if native_id in approved else 'NO_APPROVED_COVER',
                             'urls': [approved[native_id]['url']] if native_id in approved else []})
        drafts.append(draft)
    data['post_drafts'] = drafts
    by_key = {d['source_candidate_key']: d for d in drafts}
    feed = [r for r in data['draft_feed_records'] if r['source_candidate_key'] not in
            {'top500_rank_206', 'top500_rank_280', 'top500_rank_411'}]
    for draft in review['drafts']:
        if not draft['source_candidate_key'].startswith('top500_'):
            continue
        key = draft['source_candidate_key']
        feed.append({'draft_collection_key': 'draft_hotel_' + key, 'kind': 'hotel',
                     'source_candidate_key': key, 'name': items[draft['hotel_id']]['name'],
                     'description': 'Подберите тур в этот отель на ваши даты в боте.',
                     'url': None, 'picture': None, 'channel_id': None, 'post_id': None,
                     'entry_id': None, 'catalog_id': None, 'enabled': False,
                     'post_draft_key': draft['draft_key'],
                     'missing': ['channel', 'post', 'issued_entry_id', 'destination_url', 'approved_image']})
    for row in feed:
        draft = by_key.get(row['source_candidate_key'])
        if not draft or draft.get('catalog_namespace') != 'local-tv':
            continue
        native_id = draft['catalog_ids'][0]
        row.update(catalog_id=native_id, catalog_namespace='local-tv',
                   post_draft_key=draft['draft_key'], picture=approved.get(native_id, {}).get('url'))
        row['missing'] = [x for x in row['missing'] if x != 'catalog_or_selection_binding'
                          and not (x == 'approved_image' and native_id in approved)]
    assert len({r['draft_collection_key'] for r in feed}) == len(feed)
    assert all(r['url'] is None and not r['enabled'] for r in feed)
    data['draft_feed_records'] = feed
    # Apply identity to matching work rows without broadening any name match.
    for row in data['name_references']:
        for binding in review['bindings']:
            source = binding['source']['input_corpus']
            if row['source'] == source and ((source == 'direct' and row['source_key'] == binding.get('source_key'))
                 or (source == 'top500' and row['sales_rank'] == binding.get('sales_rank'))):
                row.update(catalog_id=binding['hotel_id'], catalog_namespace='local-tv',
                           catalog_identity_verified=True, catalog_identity_evidence=binding)
                if binding.get('knowledge_profile_key'):
                    row['reviewed_knowledge_profile_key'] = binding['knowledge_profile_key']
    requests = []
    for row in feed:
        phrase = row['name'].replace(' / ', ' ')
        requests.append({'source_candidate_key': row['source_candidate_key'],
                         'endpoint': review['wordstat']['endpoint'], 'method': 'POST',
                         'body_template': {'phrase': phrase, 'numPhrases': 50, 'regions': [],
                                           'devices': ['DEVICE_ALL'], 'folderId': None},
                         'region_scope': 'All Wordstat regions; explicitly reconfigure for target geography.',
                         'period': 'Last30days at actual collection time, not a calendar month.',
                         'actual_frequency': None, 'executed': False})
    data['wordstat_preparation'] = {'access': review['wordstat'], 'actual_requests': 0,
                                   'prepared_seed_requests': requests}
    data['catalog_preparation'] = {'captured_at_utc': now, 'requested_cohort_ids': len(cohort),
                                 'returned_profiles': len(items), 'missing_cohort_ids': len(missing),
                                 'scope': 'Only supplied492IDs, not the whole LOCAL catalogue.',
                                 'bindings': review['bindings'], 'approved_covers': list(approved.values()),
                                 'rejected_photos': review['rejected_photos'],
                                 'discovery_blocker': 'Legacy name search and provider gateway returned403; no bypass or broad ID enumeration.',
                                 'knowledge_source_modified': False}
    data['summary'].update(generated_at_utc=now, verified_catalog_ids=len(bound_ids),
                           verified_catalog_source_references=len(review['bindings']),
                           post_drafts=len(drafts), source_claim_references=sum(len(d['proof']) for d in drafts),
                           draft_feed_records=len(feed), selected_draft_covers=len(approved),
                           wordstat_access=review['wordstat']['status'])
    data['limitations'] = ['Only five distinct hotel identities verified in this bounded pass.',
                          'No prices, availability, issued entry/post IDs or channel publication.',
                          'Four draft covers selected; Palma cover unresolved.',
                          'Incomplete XML is not upload-ready; no Wordstat frequency collected.']
    xml = ET.Element('yml_catalog', date=now[:16].replace('T', ' '))
    shop = ET.SubElement(xml, 'shop')
    ET.SubElement(shop, 'name').text = 'Any Tour MAX — INCOMPLETE DRAFT'
    ET.SubElement(shop, 'company').text = 'Any Tour'
    ET.SubElement(shop, 'url').text = 'https://AnyTour.online/'
    ET.SubElement(shop, 'offers')
    collections = ET.SubElement(shop, 'collections')
    collections.append(ET.Comment('DO NOT UPLOAD: destination URLs absent; images and bindings incomplete'))
    for row in feed:
        node = ET.SubElement(collections, 'collection', id=row['draft_collection_key'])
        if row.get('picture'):
            ET.SubElement(node, 'picture').text = row['picture']
        ET.SubElement(node, 'name').text = row['name']
        ET.SubElement(node, 'description').text = row['description']
    ET.indent(xml)
    xml_bytes = ET.tostring(xml, encoding='utf-8', xml_declaration=True)
    esc = lambda s: html.escape(str(s), quote=True)
    cards = []
    for draft in review['drafts']:
        photo = approved.get(draft['hotel_id'])
        picture = ''
        if photo:
            raw = (root / 'post_photo_candidates' / Path(photo['local_path']).name).read_bytes()
            picture = '<img style="width:100%;max-width:640px;border-radius:10px" alt="' + esc(draft['title']) + '" src="data:image/jpeg;base64,' + base64.b64encode(raw).decode() + '">'
        cards.append('<details><summary>' + esc(draft['title']) + '</summary>' + picture +
                     '<p>' + esc(draft['body']).replace('\n\n', '</p><p>') + '</p><p><b>Кнопка:</b> ' + esc(draft['button_label']) + '</p>' +
                     '<small>LOCAL ' + str(draft['hotel_id']) + '; кнопка пока без адреса. ' + ('Обложка выбрана для черновика.' if photo else 'Обложка ожидает замены: в галерее обнаружены фото El Palacio.') + '</small></details>')
    section = ('<!-- FUNNEL_CATALOG_BEGIN --><section><h2>Следующий пакет: каталог и фотографии</h2><p><b>Всего 15 черновиков постов</b>, 5 подтверждённых LOCAL ID и 4 выбранные обложки. В очереди фида 356 заготовок: 353 прежних и 3 дополнительных отеля из ТОП500.</p>' +
              '<p>Проверены все 492 ID предоставленного списка: получено 72 карточки, 420 отсутствуют в этом чтении. Это ограниченный срез; он не измеряет полноту всей базы. ID связаны с названиями и странами по 6 исходным строкам, без позиционного соединения.</p>' +
              '<p class="note">Для Palma de Mirette отклонены обложка и три альтернативы с изображением соседнего El Palacio. Подогрев и доступность услуг по фотографии не определялись.</p>' + ''.join(cards) +
              '<p><b>Частотность:</b> ещё не получена. Действующий Wordstat API относится к Yandex Search API; нужен подключённый каталог и доступ. Заготовлены 356 запросов, фактических вызовов — 0. Показы рекламы не заменяют эту статистику.</p></section><!-- FUNNEL_CATALOG_END -->')
    report_path = out / 'anytour-demand-20261022.html'
    report = report_path.read_text()
    report = re.sub(r'<!-- FUNNEL_CATALOG_BEGIN -->.*?<!-- FUNNEL_CATALOG_END -->', '', report, flags=re.S)
    report = report.replace('Автопилот: подготовка контента</h2>', 'Первый пакет: подготовка контента</h2>')
    report = report.replace('</header>', '</header>' + section, 1)
    report_bytes = report.encode()
    content_bytes = encode(data)
    provenance_path = out / 'source-provenance.json'
    provenance = load(provenance_path)
    provenance.setdefault('initial_content_preparation', provenance.get('content_preparation'))
    provenance['content_preparation'] = dict(provenance.get('content_preparation') or {},
                                             summary=data['summary'], sha256=sha(content_bytes))
    provenance['catalog_preparation'] = {'summary': data['summary'], 'source': data['catalog_preparation'],
                                       'content_preparation_sha256': sha(content_bytes)}
    provenance['report_sha256'] = sha(report_bytes)
    priorities_path = out / 'anytour-demand-priorities-20261022.json'
    priorities = load(priorities_path)
    priorities['additional_catalog_verified_hotels'] = [b for b in review['bindings'] if b.get('sales_rank') in [206, 280, 411]]
    priorities['content_preparation_file'] = 'funnel-content-preparation.json'
    priorities['readiness'] = 'PARTIAL_CATALOG_BINDING_AND_CONTENT_PREPARATION'
    provenance['working_queue_sha256'] = sha(encode(priorities))
    generated = {'report.html': report_bytes, 'source-provenance.json': encode(provenance),
                 'priorities.json': encode(priorities), 'funnel-content-preparation.json': content_bytes,
                 'funnel-collections.INCOMPLETE-DRAFT.xml': xml_bytes,
                 'funnel-catalog-review.json': review_path.read_bytes(),
                 'attach-funnel-catalog.py': Path(__file__).read_bytes(),
                 'README.txt': ('Рабочая очередь: priorities.json; актуальный контент и связи: funnel-content-preparation.json. '
                                '317 названий из Директа,36 тем,3 дополнительных подтверждённых отеля ТОП500. '
                                '15 черновиков постов,5 LOCAL ID,4 обложки. Канал и адреса входа не заполнены; '
                                'XML неполный, загружать нельзя. Фото Palma отклонены из-за El Palacio. '
                                'Проверен только пул492ID:72 карточки/420 отсутствующих, не вся база. '
                                'Частотность Wordstat ещё не собрана; нужен доступ Search API. '
                                'Исходные API-строки сохранены. Действующая реклама и гостиничная база не изменялись.\n').encode()}
    for path in sorted((root / 'catalog_reads').glob('top500_ids_*.json')):
        generated['catalog-reads/' + path.name] = path.read_bytes()
    for photo in approved.values():
        generated[photo['archive_member']] = (root / 'post_photo_candidates' / Path(photo['local_path']).name).read_bytes()
    archive_path = out / 'anytour-demand-20261022.zip'
    pending = archive_path.with_suffix('.catalog-pending.zip')
    with zipfile.ZipFile(archive_path) as old, zipfile.ZipFile(pending, 'w', zipfile.ZIP_DEFLATED, compresslevel=7) as new:
        for entry in old.infolist():
            if entry.filename not in generated:
                new.writestr(entry, old.read(entry.filename))
        for name, value in generated.items():
            new.writestr(name, value)
    with zipfile.ZipFile(pending) as checked:
        assert checked.testzip() is None and len(checked.namelist()) == len(set(checked.namelist()))
    report_path.write_bytes(report_bytes)
    provenance_path.write_bytes(generated['source-provenance.json'])
    priorities_path.write_bytes(generated['priorities.json'])
    (out / 'funnel-content-preparation.json').write_bytes(content_bytes)
    (out / 'funnel-collections.INCOMPLETE-DRAFT.xml').write_bytes(xml_bytes)
    (out / 'funnel-preparation-summary.json').write_bytes(encode(data['summary']))
    pending.replace(archive_path)
    return data['summary']


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, default=Path(__file__).resolve().parent)
    parser.add_argument('--review', type=Path, required=True)
    parser.add_argument('--knowledge', type=Path, required=True)
    args = parser.parse_args()
    print(json.dumps(build(args.root.resolve(), args.review, args.knowledge), ensure_ascii=False))
