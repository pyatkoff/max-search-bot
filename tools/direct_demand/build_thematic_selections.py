#!/usr/bin/env python3
"""Extract source-backed country × travel-need candidates from an existing export.

No external requests, publication, hotel-property inference or source mutations.
Clusters overlap by design: their impressions/clicks must not be added together.
"""
import argparse
from collections import defaultdict
from datetime import date,timedelta
import hashlib
import json
from pathlib import Path
import re
from analyze_demand import COUNTRIES,normalize,number
from analyze_account_demand import campaign_state

ROOT=Path(__file__).resolve().parent
COUNTRY_RULES=[(c,l,re.compile(p,re.I)) for c,l,p in COUNTRIES if c!='RU_AB']+[
    ('RU','Россия',re.compile(r'\bросси|\bсочи\b|\bадлер|соль илецк|домбай|архыз|дагестан|кабардинка',re.I)),
    ('AB','Абхазия',re.compile(r'абхази|\bгагр|сухум|пицунд|гудаут',re.I))]

# Only literal demand expressions are used. Property words found solely inside
# known hotel-name phrases have weaker evidence and do not earn first priority.
NEEDS={
 'heated_pool':{'label':'с подогреваемым бассейном','kind':'property',
    'positive':r'подогре|(?:тепл\w*\s+(?:\w+\s+){0,3}бассейн)|(?:бассейн\w*\s+(?:\w+\s+){0,5}тепл)',
    'negative':r'без подогрев|неподогре|не подогре|подогрев не нужен',
    'eligibility':['Подтверждены подогреваемый бассейн и его тип, сезон работы, доступность на даты поездки и условия пользования.']},
 'sandy_beach':{'label':'с песчаным пляжем или заходом','kind':'property',
    'positive':r'песчан\w*|песочн\w*|\bпесок\b',
    'negative':r'без песчан|не песчан|не нужен песчан',
    'eligibility':['Покрытие пляжа и покрытие захода подтверждаются раздельно; потребность в песчаном заходе не доказывает песчаный пляж.']},
 'family':{'label':'для отдыха с детьми','kind':'party',
    'positive':r'ребен|дет[ьяе]|семейн',
    'negative':r'без детей|adults? only|только для взрослых|не для детей',
    'eligibility':['Подтверждены существенные для возраста детей условия, размещение, пляж и работа нужной инфраструктуры на даты поездки.']},
 'waterpark':{'label':'с аквапарком или водными горками','kind':'property',
    'positive':r'аквапарк|aqua\s*park|водн\w*\s+(?:\w+\s+){0,2}горк|аквагорк',
    'negative':r'без аквапарк|без водн\w* гор|не нужен аквапарк',
    'eligibility':['Подтверждены наличие аквапарка/горок, сезон и часы работы, возрастные ограничения, включение в стоимость или доплата.']},
 'first_line':{'label':'на первой линии','kind':'property',
    'positive':r'перв\w*\s+(?:берегов\w*\s+)?лини|\b1\s*(?:я\s*)?(?:берегов\w*\s+)?лини',
    'negative':r'не на первой|не первая линия|не нужна первая линия',
    'eligibility':['Подтверждены фактический путь к пляжу и переходы/дороги; маркетинговая «первая линия» не заменяет описание доступа.']},
 'coral_reef':{'label':'с коралловым рифом для снорклинга','kind':'property',
    'positive':r'\bриф\w*|\breef\b|коралл\w*|сноркл|подводн\w* мир',
    'negative':r'без коралл|без риф|не нужен риф|coral travel|корал тревел',
    'eligibility':['Подтверждены риф и доступ к нему у конкретного отеля, способ входа в море и существенные ограничения; не обещается неизменное состояние моря.']},
 'windless_bay':{'label':'в бухте с меньшей ветровой нагрузкой','kind':'property',
    'positive':r'безветр|без ветра|защищенн\w*\s+(?:\w+\s+){0,3}ветр',
    'negative':r'не безветр',
    'eligibility':['Подтверждены расположение и сезонные особенности бухты; отсутствие ветра на конкретные даты не гарантируется.']},
 'adults_only':{'label':'для взрослых, без детей','kind':'property',
    'positive':r'adults? only|без детей|только для взрослых|\b18\s*\+',
    'negative':r'не только для взрослых|не adults? only|не без детей',
    'eligibility':['Подтверждены действующие возрастные правила конкретного отеля; запрос «без детей» не превращается в факт об отеле.']},
 'quiet':{'label':'для спокойного отдыха','kind':'property',
    'positive':r'(?:тих\w*|спокойн\w*)\s+(?:\w+\s+){0,3}(?:отел|отдых|курорт|пляж)|(?:отел|отдых|курорт|пляж)\w*\s+(?:\w+\s+){0,3}(?:тих|спокойн)|без анимации|без дискотек|без тусовок|без шумн',
    'negative':r'не тихий|не спокойный|не нужен тихий',
    'eligibility':['Есть проверенные сведения о формате развлечений и расположении; субъективная тишина не обещается как гарантированное свойство.']},
 'newyear':{'label':'на Новый год','kind':'season',
    'positive':r'новый год|нового года|новогод|\b31 декабря\b|\b30 декабря\b',
    'negative':r'не на новый год|кроме нового года',
    'eligibility':['Подтверждены работа отеля и нужной инфраструктуры, доступность тура на выбранные даты и обязательные праздничные доплаты.']},
 'winter':{'label':'для зимнего отдыха','kind':'season',
    'positive':r'\bзим\w*|декабр|январ|феврал',
    'negative':r'не зимой|кроме зимы',
    'eligibility':['Подтверждены зимняя работа отеля и существенной инфраструктуры на даты поездки; сезонные условия объясняются без погодных гарантий.']},
}
for spec in NEEDS.values():
    spec['positive_re']=re.compile(spec['positive'],re.I)
    spec['negative_re']=re.compile(spec['negative'],re.I)

INFO_RULES=[
 ('employment',re.compile(r'\bваканси|\bработ[аыу]\b|трудоустрой|\bзарплат')),
 ('weather_webcam',re.compile(r'\bпогод|веб.?камер|webcam')),
 ('navigation_contact',re.compile(r'\bтелефон|как добраться|точный адрес')),
 ('entry_rules',re.compile(r'правила въезда|документы для въезда|срок действия паспорта|с декабря \d{4} года въезд')),
 ('medical_acronym',re.compile(r'оаэ у ребенка|отоакуст|скрининг')),
]
NAME_TOKENS={
 'waterpark':re.compile(r'джангл аква|альбатрос аква|albatros aqua|jungle aqua|aqua park (?:sharm|resort|hotel)|aqua park aquaventure|waterworld makadi',re.I),
 'coral_reef':re.compile(r'reef oasis|риф оазис|ree[fv] resort|коралл бич|coral beach',re.I),
}


def acc():
    return {'rows':0,'impressions_known_sum':0,'clicks_known_sum':0,'impressions_missing_rows':0,'clicks_missing_rows':0}


def add(target,row):
    target['rows']+=1
    for key in ('impressions','clicks'):
        value=number(row.get(key.capitalize()))
        if value is None:target[key+'_missing_rows']+=1
        else:target[key+'_known_sum']+=int(value)


def metrics(target):
    out={'rows':target['rows']}
    for key in ('impressions','clicks'):
        out[key]=None if target[key+'_missing_rows'] else target[key+'_known_sum']
        if target[key+'_missing_rows']:
            out[key+'_known_sum']=target[key+'_known_sum']
            out[key+'_unavailable_rows']=target[key+'_missing_rows']
    return out


def detect(raw):
    q=normalize(raw)
    countries=[(code,label) for code,label,pattern in COUNTRY_RULES if pattern.search(q)]
    # An explicitly named departure city/country is not an additional destination.
    if len(countries)>1 and re.search(r'\bиз (?:россии|сочи|адлера)\b',q):
        countries=[c for c in countries if c[0]!='RU']
    flags=[name for name,pattern in INFO_RULES if pattern.search(q)]
    if re.search(r'отзыв|\breviews?\b',q):flags.append('reviews_information')
    if len(raw)>=140:
        catalogue_marker=re.search(r'\d[\d\s]*\s*(?:м2|м²|кв\s*м)|\d\s*этаж|двухэтаж|трехэтаж|\bчартер\b|\bcharter\b|\b(?:promo|standard|superior|deluxe|economy|family)\s+room\d*',raw,re.I)
        emoji_count=len(re.findall(r'[\U0001F000-\U0001FAFF\u2600-\u27BF]',raw))
        decorated_offer=emoji_count>=3 and re.search(r'стоимость|цена',raw,re.I)
        if catalogue_marker or decorated_offer:flags.append('pasted_offer_or_description')
    matches=[];negated=[]
    for need,spec in NEEDS.items():
        test=raw.casefold().replace('ё','е') if need=='adults_only' else q
        if not spec['positive_re'].search(test):continue
        if spec['negative_re'].search(test):
            negated.append(need);continue
        specificity='explicit_requirement'
        if need=='heated_pool' and not re.search(r'бассейн|pool',q):specificity='heating_pool_unspecified'
        if need=='sandy_beach' and not re.search(r'пляж|заход|вход|берег|море',q):specificity='sand_context_unspecified'
        if need in NAME_TOKENS and NAME_TOKENS[need].search(q) and not re.search(r'\bс (?:аквапарком|водными горками|рифом|кораллами)|хорош\w* риф|лучш\w* аквапарк|отели\s+(?:\w+\s+){0,4}с ',q):specificity='possible_hotel_name_token'
        matches.append((need,specificity))
    return q,countries,matches,flags,negated


def build(source,inventory,limit):
    if source.get('status')!='COLLECTED' or not source.get('coverage',{}).get('pagination_complete'):
        raise ValueError('COMPLETE_SOURCE_REQUIRED')
    index={str(c['Id']):c for c in inventory['config_before']['campaigns']}
    for c in source.get('config_before',{}).get('campaigns',[]):
        cid=str(c['Id'])
        if cid not in index or (campaign_state(c)!='UNKNOWN' and c.get('Name')):index[cid]=c
    last=date.fromisoformat(source['date2']);first7=(last-timedelta(days=6)).isoformat()
    groups={};cache={};ambiguous_rows=0;negated_counts=defaultdict(int)
    for row_index,row in enumerate(source['raw_rows']):
        raw=row['Query']
        if raw not in cache:cache[raw]=detect(raw)
        q,countries,matches,flags,negated=cache[raw]
        for need in negated:negated_counts[need]+=1
        if len(countries)!=1:
            if matches and len(countries)>1:ambiguous_rows+=1
            continue
        code,label=countries[0];on=index.get(row['CampaignId'],{}).get('State')=='ON';recent=first7<=row['Date']<=source['date2']
        for need,specificity in matches:
            key=code+'_'+need
            if key not in groups:
                groups[key]={'country_code':code,'country':label,'need':need,'all':acc(),'on':acc(),'last7':acc(),'qualified':acc(),'qualified_on':acc(),'forms':set(),'raw_forms':set(),'campaigns':set(),'flags':defaultdict(int),'specificities':defaultdict(int),'examples':{}}
            g=groups[key];add(g['all'],row)
            if on:add(g['on'],row)
            if recent:add(g['last7'],row)
            qualified=specificity!='possible_hotel_name_token' and not any(f in flags for f in ('employment','weather_webcam','navigation_contact','entry_rules','medical_acronym','pasted_offer_or_description'))
            if qualified:
                add(g['qualified'],row)
                if on:add(g['qualified_on'],row)
            g['forms'].add(q);g['raw_forms'].add(raw);g['campaigns'].add(row['CampaignId']);g['specificities'][specificity]+=1
            for flag in flags:g['flags'][flag]+=1
            if q not in g['examples']:g['examples'][q]={'query':raw,'normalized_query':q,'metrics':acc(),'on':acc(),'last7':acc(),'specificity':specificity,'review_flags':flags,'qualified':qualified,'source_row_index_0based':row_index}
            example=g['examples'][q];add(example['metrics'],row)
            if on:add(example['on'],row)
            if recent:add(example['last7'],row)
    candidates=[]
    for key,g in groups.items():
        spec=NEEDS[g['need']];qualified_clicks=g['qualified']['clicks_known_sum'];all_clicks=g['all']['clicks_known_sum']
        band=1 if spec['kind']=='property' and qualified_clicks>0 else 2 if spec['kind']=='party' and qualified_clicks>0 else 3 if spec['kind']=='season' and qualified_clicks>0 else 4 if spec['kind']=='property' and len(g['forms'])>=3 else 5
        examples=sorted(g['examples'].values(),key=lambda e:(not e['qualified'],-e['metrics']['clicks_known_sum'],-e['last7']['clicks_known_sum'],-e['metrics']['impressions_known_sum'],e['normalized_query']))[:8]
        candidates.append({'candidate_key':key,'country_code':g['country_code'],'country':g['country'],'need':g['need'],
            'proposed_selection_title':g['country']+' '+spec['label'],'need_kind':spec['kind'],'priority_band':band,
            'metrics':metrics(g['all']),'currently_on_metrics':metrics(g['on']),'last7_metrics':metrics(g['last7']),
            'qualified_need_metrics':metrics(g['qualified']),'qualified_need_currently_on_metrics':metrics(g['qualified_on']),
            'normalized_query_forms':len(g['forms']),'raw_query_forms':len(g['raw_forms']),'campaign_count':len(g['campaigns']),
            'specificity_row_counts':dict(g['specificities']),'review_flag_row_counts':dict(g['flags']),
            'source_examples':[{**{k:v for k,v in e.items() if k not in ('metrics','on','last7')},'metrics':metrics(e['metrics']),'currently_on_metrics':metrics(e['on']),'last7_metrics':metrics(e['last7'])} for e in examples],
            'eligibility_requires_confirmed_facts':True,'hotel_properties_asserted':False,'eligibility_checks':spec['eligibility'],
            'hotel_ids':[],'post_id':None,'entry_id':None,'publishable':False})
    candidates.sort(key=lambda x:(x['priority_band'],-(x['qualified_need_metrics']['clicks'] or 0),-(x['last7_metrics']['clicks'] or 0),-(x['currently_on_metrics']['clicks'] or 0),-x['normalized_query_forms'],x['candidate_key']))
    # Keep the requested compact shortlist; do not fabricate candidates to fill it.
    selected=candidates[:limit]
    return {'schema':'thematic-selection-candidates-v1','final_status':'EDITORIAL_CANDIDATES_READY','wordstat_status':'NOT_QUERIED','client_login':source['client_login'],
        'source_period':[source['date1'],source['date2']],'source_rows':len(source['raw_rows']),
        'source_campaign_count':len(source['campaign_ids']),'source_status':source['status'],
        'last7_window':[first7,source['date2']],'last7_basis':'Last seven report dates, inclusive; recent Direct data may still update. No growth comparison is inferred.',
        'on_basis':'Current State=ON in explicit campaign metadata; not the historical state on each query date.',
        'detected_country_need_clusters':len(candidates),'selected_count':len(selected),
        'priority_method':'Specific property conditions with at least one qualified click first; then party requirements; then seasonal requirements. Within each band: qualified clicks, last7 clicks, current ON clicks, number of normalized forms. Broad generic tour/hot-deal clusters are not created.',
        'qualification_method':'Counts retain every matched native source row. Qualified subset omits property words found only in possible hotel names; explicit employment/weather/webcam/contact/entry-rule/medical-acronym contexts; long pasted offers/descriptions with catalogue/building/charter/room-code markers or decorated-price markers. A long tourist wish without these markers is not flagged. Reviews remain as a visible informational flag, not an automatic exclusion.',
        'overlap_note':'Clusters overlap: one query may express children, sand and a pool simultaneously. Their impressions and clicks must not be added across clusters.',
        'evidence_note':'A requested feature is a tourist requirement, never a fact about any hotel. No prices, hotel IDs, posts, advertising or source data were changed.',
        'entry_contract':{'topic_or_post_id_plus_yclid':True,'join_by_chat_id':True,'keyword_used':False},
        'diagnostics':{'multi_country_need_rows_not_assigned':ambiguous_rows,'negated_need_rows_not_counted':dict(negated_counts)},
        'candidates':selected,
        'other_observed_clusters_summary':[{'candidate_key':x['candidate_key'],'title':x['proposed_selection_title'],'metrics':x['metrics'],'qualified_need_metrics':x['qualified_need_metrics'],'forms':x['normalized_query_forms']} for x in candidates[limit:]]}


def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('--source',type=Path,default=ROOT/'account_queries_20261022.json')
    p.add_argument('--inventory',type=Path,default=ROOT/'account_inventory_20261022.json')
    p.add_argument('--output',type=Path,default=ROOT/'thematic_selection_candidates.json')
    p.add_argument('--limit',type=int,default=36)
    args=p.parse_args()
    if not 1<=args.limit<=40:raise SystemExit('limit must be1..40')
    source=json.loads(args.source.read_text(encoding='utf-8'));inventory=json.loads(args.inventory.read_text(encoding='utf-8'))
    result=build(source,inventory,args.limit)
    result['source_file_sha256']=hashlib.sha256(args.source.read_bytes()).hexdigest()
    args.output.write_text(json.dumps(result,ensure_ascii=False,indent=2,allow_nan=False)+'\n',encoding='utf-8')
    print(json.dumps({'output':str(args.output),'selected':result['selected_count'],'detected':result['detected_country_need_clusters'],
        'candidates':[(x['proposed_selection_title'],x['metrics']['clicks'],x['qualified_need_metrics']['clicks'],x['currently_on_metrics']['clicks'],x['last7_metrics']['clicks']) for x in result['candidates']]},ensure_ascii=False))


if __name__=='__main__':main()
