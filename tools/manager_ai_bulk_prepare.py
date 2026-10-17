#!/usr/bin/env python3
"""Freeze 500 chat-disjoint past-only cases; never use later outcomes as labels."""
from pathlib import Path
import collections,hashlib,html,json,random,re,sys
import argparse
ROOT=Path(__file__).resolve().parent
ARGS=None
from manager_ai_bulk_queue import atomic_json,encoded,digest

def plain(v):
    if isinstance(v,str):return v
    if isinstance(v,list):return ''.join(plain(x) for x in v)
    if isinstance(v,dict):return str(v.get('text',''))
    return ''

def redacted(text,display):
    if display and len(display)>2:text=text.replace(display,'[турист]')
    text=re.sub(r'https?://[^\s<>"\u0027]+','[ссылка не прочитана]',text,flags=re.I)
    text=re.sub(r'[\w.!#$%&\u0027*+/=?^`{|}~-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}','[email скрыт]',text)
    text=re.sub(r'(?<!\w)(?:\+?[78][ -]?(?:\([0-9]{3}\)|[0-9]{3})[ -]?[0-9]{3}[ -]?[0-9]{2}[ -]?[0-9]{2}|\+(?:[0-9][ ()-]*){9,14}[0-9])(?!\w)','[телефон скрыт]',text)
    text=re.sub(r'(?<!\w)@[A-Za-z0-9_]{4,}','[контакт скрыт]',text)
    text=re.sub(r'\b[0-9]{10,}\b','[длинный идентификатор скрыт]',text)
    text=re.sub(r'[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]','',text)
    return text

def main():
    raw=Path(ARGS.archive).read_bytes();source_hash=digest(raw)
    register=json.loads(Path(ARGS.register).read_text())
    assert source_hash==register['source_sha256']
    data=json.loads(raw);owner='user'+str(data['personal_information']['user_id'])
    prior_text=Path(ARGS.prior_study).read_text()
    study=json.loads(re.search(r'<script[^>]*id="study-data"[^>]*>(.*?)</script>',prior_text,re.S)[1])
    assert study['source']['archive_sha256']==source_hash
    reviewed={x['case_id'] for x in register['reviews']}
    prior={x['source_alias'] for x in study['sample_freeze']['case_membership']}
    excluded=reviewed|prior;assert reviewed and prior
    pools=collections.defaultdict(list)
    for position,chat in enumerate(data['chats']['list'],1):
        alias=f'TG{position:04d}'
        if alias in excluded or chat.get('type')!='personal_chat' or chat.get('id')==777000:continue
        messages=[m for m in chat.get('messages',[]) if m.get('type')=='message']
        messages.sort(key=lambda m:(int(m.get('date_unixtime',0)),int(m['id'])))
        incoming=[(i,m) for i,m in enumerate(messages) if m.get('from_id') and m['from_id']!=owner and len(plain(m.get('text','')).strip())>=4]
        if not incoming:continue
        # Membership and cutoff are selected without using a subsequent answer.
        i,target=incoming[-1];cutoff=int(target.get('date_unixtime',0));prefix=messages[:i+1]
        edited=lambda m:bool(m.get('edited_unixtime') and int(m['edited_unixtime'])>cutoff)
        if edited(target):continue
        raw_text=plain(target.get('text','')).lower()
        if re.search(r'паспорт|виз[ауые]|документ|регистрац|мест[ао].*(?:самолет|самолёт)',raw_text):group='documents'
        elif re.search(r'оплат|брон|договор|возврат|отмен|продли|перенос|пересад|не\s+отвеч|неправиль|почему',raw_text):group='service_or_change'
        elif len(prefix)>60:group='long_history'
        elif any(re.search(r'qui-quo\.|qq\.travel',plain(m.get('text','')),re.I) for m in prefix):group='selection'
        elif re.search(r'спасибо|понял|благодар|не\s+актуаль|не\s+интерес|купил|приобрел|приобрёл',raw_text):group='closure'
        else:group='request'
        pools[group].append({'alias':alias,'chat':chat,'prefix':prefix,'cutoff':cutoff,'target_id':int(target['id']),'late_edits':sum(edited(m) for m in prefix)})
    rng=random.Random(ARGS.seed)
    for pool in pools.values():rng.shuffle(pool)
    quota={'documents':65,'service_or_change':85,'long_history':85,'selection':110,'closure':55,'request':100}
    selected=[]
    for group,n in quota.items():
        take=min(n,len(pools[group]));selected.extend((group,x) for x in pools[group][:take]);pools[group]=pools[group][take:]
    spare=[(g,x) for g,values in pools.items() for x in values];rng.shuffle(spare);selected+=spare[:500-len(selected)]
    assert len(selected)==500 and len({x['alias'] for _,x in selected})==500
    # A stratified 20% per group is reserved. No content-derived outcome labels.
    by_group=collections.defaultdict(list)
    for item in selected:by_group[item[0]].append(item)
    holdout=[];development=[]
    for group,items in by_group.items():
        rng.shuffle(items);n=len(items)//5;holdout+=items[:n];development+=items[n:]
    while len(holdout)<100:holdout.append(development.pop())
    while len(holdout)>100:development.append(holdout.pop())
    assert len(development)==400 and len(holdout)==100
    work=Path(ARGS.output).resolve();work.mkdir(mode=0o700,parents=True,exist_ok=True);work.chmod(0o700);outputs={};membership=[]
    for split,items in [('development',development),('holdout',holdout)]:
        cases=[]
        for index,(group,item) in enumerate(items,1):
            rows=[];selection_flags={};media_flags={};dropped_edits=[]
            display=item['chat'].get('name','')
            for m in item['prefix']:
                mid=int(m['id'])
                if m.get('edited_unixtime') and int(m['edited_unixtime'])>item['cutoff']:dropped_edits.append(mid);continue
                text=plain(m.get('text',''));entities=m.get('text_entities') or []
                urls=re.findall(r'https?://[^\s<>"\u0027]+',text,re.I)+[e.get('href','') for e in entities if isinstance(e,dict) and e.get('href')]
                selection=any(re.search(r'^(?:https?://)?(?:www\.)?(?:qui-quo\.(?:ru|com|online)|qq\.travel)(?:[/:?#]|$)',u,re.I) for u in urls)
                text=redacted(text,display)
                if selection:text+='\n[ссылка на подборку не прочитана]'
                if any(e.get('href') for e in entities if isinstance(e,dict)):text+='\n[скрытая гиперссылка не прочитана]'
                forwarded=bool(m.get('forwarded_from') or m.get('forwarded_from_id'))
                if forwarded:text='[пересланный текст; авторство не установлено]\n'+text
                media=bool(m.get('photo') or m.get('file') or m.get('media_type') or forwarded or m.get('reply_to_message_id'))
                sender='manager' if m.get('from_id')==owner else 'customer' if m.get('from_id') else 'system'
                rows.append({'id':mid,'sender_type':sender,'text':text,'event_type':'message','created_at':str(m.get('date','')),'media_unread':media})
                selection_flags[mid]=selection;media_flags[mid]=media
            incomplete=bool(dropped_edits) or len(rows)>120;rows=rows[-120:];kept=[];remaining=40000
            for row in reversed(rows):
                if len(row['text'])>remaining:
                    incomplete=True
                    if not kept:row=dict(row,text=row['text'][-remaining:],text_truncated=True);kept.append(row)
                    break
                kept.append(row);remaining-=len(row['text'])
            kept.reverse()
            if not kept or kept[-1]['id']!=item['target_id']:raise ValueError('target_lost')
            ids=[m['id'] for m in kept];assert ids==sorted(set(ids))
            cid=('BD' if split=='development' else 'BH')+f'{index:03d}'
            context={'conversation_id':int(item['alias'][2:]),'as_of_message_id':item['target_id'],'messages':kept,
                'history_incomplete':incomplete,'media_unread':any(m['media_unread'] for m in kept),
                'selections_link_unread':any(selection_flags[mid] for mid in ids),'verified_company_facts':[]}
            cases.append({'case_id':cid,'context':context})
            membership.append({'case_id':cid,'source_alias':item['alias'],'group':group,'split':split,
                'cutoff_message_id':item['target_id'],'source_prefix_count':len(item['prefix']),
                'bounded_messages':len(kept),'bounded_characters':sum(len(m['text']) for m in kept),
                'history_incomplete':incomplete,'late_edited_message_ids_excluded':dropped_edits})
        outputs[split]=cases;atomic_json(work/(split+'_cases.json'),cases)
    assert {x['source_alias'] for x in membership if x['split']=='development'}.isdisjoint(x['source_alias'] for x in membership if x['split']=='holdout')
    assert not {x['source_alias'] for x in membership}&excluded
    manifest={'schema':'manager_ai_bulk_sample_v1','seed':ARGS.seed,'source_sha256':source_hash,
        'source_library_file_id':register.get('source_library_file_id'),'cases':500,'development':400,'holdout':100,
        'excluded_reviewed_histories':len(reviewed),'excluded_prior_fixed_model_histories':len(prior),'excluded_union':len(excluded),
        'development_sha256':digest(encoded(outputs['development'])),'holdout_sha256':digest(encoded(outputs['holdout'])),
        'groups':dict(collections.Counter(x['group'] for x in membership)),
        'automatic_reference_labels':False,'future_messages_in_context':False,'model_calls':0,
        'earlier_cross_source_identity_overlap':'unknown; this set is disjoint from the175reviewed and120fixed-model cases identified in the same exact export',
        'limits':['Purposive stratified sample, not population accuracy.','OUT denotes shared-account direction, not individual authorship.','Archive projection, not real Manager snapshot or attachment reading.','No current company facts approved.','Holdout texts/results must not be used to tune the evaluated candidate.'],
        'membership':membership}
    atomic_json(work/'sample_manifest.json',manifest)
    print(json.dumps({k:manifest[k] for k in ['cases','development','holdout','excluded_union','groups','model_calls']},ensure_ascii=False))

if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__)
    for name in ['archive','register','prior-study','output']:parser.add_argument('--'+name,required=True)
    parser.add_argument('--seed',type=int,default=18102026);ARGS=parser.parse_args();main()
