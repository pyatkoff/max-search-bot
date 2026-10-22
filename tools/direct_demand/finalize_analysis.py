"""Build reviewable editorial priorities from a completed owner query export."""
import hashlib
import html
import json
from pathlib import Path
import re
import zipfile
from decimal import Decimal

ROOT = Path(__file__).resolve().parent
OUT = ROOT / 'deliverables'
OUT.mkdir(exist_ok=True)
source = json.loads((ROOT / 'raw_report_20261022.json').read_text())
analysis = json.loads((ROOT / 'analysis_20261022/topic_candidates.json').read_text())
assert source['status'] == 'COLLECTED' and source['coverage']['pagination_complete']
rows = source['raw_rows']
active = '714260445'
feed = json.loads((ROOT / 'current_feed_summary.json').read_text())

definitions = [
 ('eg_couple_ai', 'Египет на двоих: всё включено', 'EG', r'(?=.*егип)(?=.*(?:вс[её] включ|all inclusive))(?=.*(?:на двоих|вдво[её]м|2 взрослых|два взрослых))', 'Подобрать тур на двоих'),
 ('eg_newyear', 'Египет на Новый год', 'EG', r'(?=.*егип)(?=.*(?:новый год|новогод|нового года))', 'Подобрать на Новый год'),
 ('eg_sharm', 'Шарм-эль-Шейх: подобрать отель и тур', 'EG', r'шарм|sharm', 'Подобрать тур в Шарм'),
 ('eg_hurghada', 'Хургада: подобрать отель и тур', 'EG', r'хургад|hurghada', 'Подобрать тур в Хургаду'),
 ('vn_phuquoc', 'Фукуок: подобрать отдых на острове', 'VN', r'фукуок|phu quoc', 'Подобрать тур на Фукуок'),
 ('vn_nhatrang', 'Нячанг: подобрать отель и тур', 'VN', r'нячанг|ня чанг|nha trang', 'Подобрать тур в Нячанг'),
 ('vn_camranh', 'Камрань: подобрать отель и тур', 'VN', r'камран|cam ranh', 'Подобрать тур в Камрань'),
 ('th_phuket', 'Пхукет: подобрать отель и тур', 'TH', r'пхукет|phuket', 'Подобрать тур на Пхукет'),
 ('th_pattaya', 'Паттайя: подобрать отель и тур', 'TH', r'патта[йя]|pattaya', 'Подобрать тур в Паттайю'),
 ('tr_ai', 'Турция: отдых «всё включено»', 'TR', r'(?=.*(?:турци|турецк))(?=.*(?:вс[её] включ|all inclusive))', 'Подобрать тур в Турцию'),
 ('mv_couple', 'Мальдивы на двоих', 'MV', r'(?=.*мальдив)(?=.*(?:на двоих|вдво[её]м|2 взрослых|два взрослых))', 'Подобрать отдых на двоих'),
 ('lk_tours', 'Шри-Ланка: подобрать пляжный отдых', 'LK', r'шри.?ланк|sri lanka', 'Подобрать тур на Шри-Ланку'),
 ('cn_hainan', 'Хайнань: подобрать отель и тур', 'CN', r'хайнан|санья|сания', 'Подобрать тур на Хайнань'),
]
prefix = {'EG':'tg_egypt','VN':'tg_vietnam','TH':'tg_tailand','TR':'tg_turkey','MV':'tg_maldives','LK':'tg_srilanka'}

def stats(rr):
 return {'rows':len(rr), 'impressions':sum(int(r['Impressions']) for r in rr),
  'clicks':sum(int(r['Clicks']) for r in rr),
  'cost_rub_ex_vat':float(sum((Decimal(r['Cost']) for r in rr),Decimal(0))),
  'distinct_queries':len({r['Query'].casefold().replace('ё','е') for r in rr})}

def examples(rr):
 agg={}
 for r in rr:
  q=r['Query'];d=agg.setdefault(q,[0,0]);d[0]+=int(r['Clicks']);d[1]+=int(r['Impressions'])
 return [{'query':q,'clicks':v[0],'impressions':v[1]} for q,v in sorted(agg.items(),key=lambda x:(-x[1][0],-x[1][1],x[0]))[:8]]

priorities=[]
for ref,title,country,pattern,cta in definitions:
 matched=[r for r in rows if re.search(pattern,r['Query'],re.I)]
 current=[r for r in matched if r['CampaignId']==active]
 priorities.append({'topic_ref':ref,'kind':'intent','proposed_post_title':title,'country':country,
  'current_feed_collections':[c['id'] for c in feed['collections'] if country in prefix and c['id'].startswith(prefix[country])],
  'match_pattern':pattern,'all_campaigns_30d':stats(matched),'active_campaign_30d':stats(current),
  'active_campaign_last7d':stats([r for r in current if r['Date']>='2026-10-16']),
  'query_examples':examples(current or matched),'cta':cta,
  'entry_id':None,'post_id':None,'hotel_id':None,'publishable':False,
  'next_content_action':'Подготовить подборку по этой теме из проверенных гостиничных материалов и актуальных предложений.'})

hotel_path=ROOT/'hotel_shortlist.json'
hotels=json.loads(hotel_path.read_text()) if hotel_path.exists() else None
priority_data={'source_period':['2026-09-23','2026-10-22'],'primary_campaign_id':active,
 'recent_window':['2026-10-16','2026-10-22'],
 'method':'Темы пересекаются: их метрики нельзя складывать. Порядок подготовки определяется объёмом наблюдавшихся кликов действующей кампании; это не рейтинг продаж или всего рынка.',
 'id_contract':'Наш ID темы/поста/отеля + yclid сохраняются при входе; повторный вход связывается по chat ID. keyword не используется. Реальные ID назначаются существующим механизмом публикации.',
 'selected_intents':sorted(priorities,key=lambda t:-t['active_campaign_30d']['clicks']),
 'hotel_candidates':hotels,'publication_status':'NOT_PUBLISHED','direct_changes':False}
(OUT/'anytour-demand-priorities-20261022.json').write_text(json.dumps(priority_data,ensure_ascii=False,indent=2))

e=lambda s:html.escape(str(s),quote=True)
table=''
for n,t in enumerate(priority_data['selected_intents'],1):
 table+='<tr>'+''.join('<td>'+e(v)+'</td>' for v in (n,t['proposed_post_title'],t['active_campaign_30d']['clicks'],t['active_campaign_last7d']['clicks'],'; '.join(x['query'] for x in t['query_examples'][:2]),t['cta']))+'</tr>'
section='<h2>Первая очередь тематических постов</h2><p>Действующая РК 714260445. Клики за 30 дней и за 16–22 октября. Темы пересекаются, значения не суммируются. Архивная РК 714320748 в этих двух столбцах не участвует.</p><div class="scroll"><table><thead><tr><th>№</th><th>Пост</th><th>Клики / 30 дней</th><th>Клики / 7 дней</th><th>Исходные запросы</th><th>Кнопка</th></tr></thead><tbody>'+table+'</tbody></table></div>'
section+='<p><b>Как использовать:</b> подготовить материал и предложение для каждой выбранной темы, затем назначить существующий ID поста и связать его с входом через /newmax/. Реальные даты, состав и бюджет туриста уточняются в диалоге. Цены и свойства гостиниц в этом отчёте не заявлены.</p>'
section+='<p><b>Что измеряем:</b> в текущей конфигурации обе кампании используют цель 548086692 и оплату за конверсию. Это прежняя подписочная цель из исходного разбора. Выгрузка содержит клики и расходы; число обращений и продаж здесь не измерено. Для новой воронки отдельно потребуется связь с содержательным обращением.</p>'
if hotels:
 hotel_body=''
 for n,h in enumerate(hotels['candidates'],1):
  hs=h['stats_active_714260445']; both=h['stats_30d_both']
  aliases=sorted(h['query_aliases'],key=lambda q:-q['stats_active_714260445']['clicks_known_sum'])
  alias='; '.join(q['raw_aliases'][0] for q in aliases[:2])
  hotel_body+='<tr>'+''.join('<td>'+e(v)+'</td>' for v in (n,h['label'],hs['clicks'],hs['impressions'],both['clicks'],alias,'Подобрать тур в этот отель'))+'</tr>'
 section+='<h2>30 конкретных гостиничных кандидатов</h2><p>Названия взяты из реальных запросов и требуют сопоставления с нашим каталогом. Показывают интерес, не подтверждают свойства отеля или наличие тура. На отдельное название пока приходится мало кликов. Сети и разные корпуса не объединены в один объект.</p><div class="scroll"><table><thead><tr><th>№</th><th>Название из запросов</th><th>Клики: активная РК</th><th>Показы: активная РК</th><th>Клики: обе РК</th><th>Исходные написания</th><th>Кнопка поста</th></tr></thead><tbody>'+hotel_body+'</tbody></table></div>'
report=(ROOT/'analysis_20261022/report.html').read_text()
report=report.replace('COLLECTED','Выгрузка завершена')
report=report.replace('<label>Найти в показанных темах и запросах',section+'<label>Найти в показанных темах и запросах',1)
report=report.replace('<h2>Кандидаты связок: запрос → тема → фид → пост</h2>','<h2>Первичная группировка за 30 дней по обеим кампаниям</h2>')
(OUT/'anytour-demand-20261022.html').write_text(report)

provenance={'github_run':'https://github.com/pyatkoff/max-search-bot/actions/runs/39856326754',
 'collector_sha':'a6f3dbf0ec2b7a17bb5bd2050270abf96bcb30cb',
 'artifact_id':12459547197,'artifact_sha256':'ccdf4a9de98e9937c566519bb210d0cface05cfa9525e44c44a5c1c422c5c7e6',
 'raw_report_sha256':hashlib.sha256((ROOT/'raw_report_20261022.json').read_bytes()).hexdigest(),
 'source_feed':feed['source'],'config_unchanged':source['config_unchanged'],
 'source_row_count':source['row_count'],'production_changes':False}
(OUT/'source-provenance.json').write_text(json.dumps(provenance,ensure_ascii=False,indent=2))
archive=OUT/'anytour-demand-20261022.zip'
with zipfile.ZipFile(archive,'w',zipfile.ZIP_DEFLATED,compresslevel=7) as z:
 for p,name in [(OUT/'anytour-demand-20261022.html','report.html'),(OUT/'anytour-demand-priorities-20261022.json','priorities.json'),(ROOT/'raw_report_20261022.json','source-report.json'),(ROOT/'analysis_20261022/topic_candidates.json','full-query-analysis.json'),(OUT/'source-provenance.json','source-provenance.json')]:
  z.write(p,name)
 if hotel_path.exists():z.write(hotel_path,'hotel-shortlist.json')
print(json.dumps({'report':str(OUT/'anytour-demand-20261022.html'),'archive':str(archive),'archive_bytes':archive.stat().st_size,'intent_topics':len(priorities)},ensure_ascii=False))
