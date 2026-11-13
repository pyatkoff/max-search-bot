import base64, copy, gzip, hashlib, importlib.util, json, re, sys, xml.etree.ElementTree as ET
from pathlib import Path
from datetime import datetime
ROOT=Path(__file__).resolve().parent
IDS=[968,1531]
CHANNELS=('max','telegram')
OPERATION='commercial-hotel-batch-20261113-14-v1'
FEED_OPERATION='commercial-channel-hotel-feeds-20261113-update15'
BASE=ROOT.parent
def need(ok,message):
    if not ok: raise ValueError(message)
def sha(raw): return hashlib.sha256(raw).hexdigest()
def read(p): return json.loads(Path(p).read_bytes())
def digest(v): return sha(json.dumps(v,ensure_ascii=False,sort_keys=True,separators=(',',':')).encode())
def save_new(p,v):
    with Path(p).open('x') as f: json.dump(v,f,ensure_ascii=False,indent=2);f.write('\n')
def dated(v):
    d=datetime.fromisoformat(v.replace('Z','+00:00'));need(d.utcoffset() is not None,'timezone');return d
def decimal(v):
    s=str(v);need(re.fullmatch('[1-9][0-9]*',s),'decimal');return s
def source_client():
    p=Path('/workspace/scratch/9110f7abc258/hotel-copy-api-check/source-sync/sync_source.py')
    need(sha(p.read_bytes())=='7c7812e2f00161a6316af1a8ae97df6ada429edb5aac48bead837d0e6e85cd6f','client_changed')
    spec=importlib.util.spec_from_file_location('retained_client',p);m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);return m
SITE_IDS={int(h['tourvisorId']):h['id'] for h in read(BASE/'selected-source.json') if int(h['tourvisorId']) in IDS}
def proof():
    manifest=read(BASE/'manifest.json');need(manifest['operation']==OPERATION,'manifest_operation')
    need(sha((BASE/'manifest.json').read_bytes())=='0271a61dea9f40ca24ac7d3e19436a1078f09a3b0d4a72dd98d2c7e5ee642d95','manifest_pin')
    raw=(BASE/'actual/channel-preflight.json').read_bytes();terminal=raw.splitlines()[-1];pub=json.loads(terminal)
    need(sha(terminal)=='c2fcad9924ad9aea277e018d5e1dc9dabf7d49479bfed6de9e2f912a3555cdb8','publication_pin')
    need(pub['ok'] is True and pub['new_posts']==pub['post_write_attempts']==len(pub['posts'])==4 and pub['reused']==0,'actual_eight_required')
    bound=read(BASE/'actual/channel-feed-read.json');need(bound['ok'] is True and bound['scope_hotels']==bound['feed_rows']==2,'bound_four')
    need(sha((BASE/'actual/channel-feed-read.json').read_bytes())=='18e74ef39f3969bb510a72d202a9aa2caf5acbba18d160f5856ac0908fcf7bf6','read_pin')
    posts={}
    for p in pub['posts']:
        hid,ch=p['hotel_id'],p['platform'];need(hid in IDS and ch in CHANNELS and (hid,ch) not in posts,'duplicate_post')
        source=next(r for r in manifest['rows'] if r['hotel_id']==hid)
        need(all(p[k]==source[k] for k in ['logical_id','text_sha256','photo_sha256','photo_url']),'actual_content_changed')
        need(p['body_photo_button_verified'] is True and p['reused'] is False and p['state']=='sent','unverified_post')
        need(sha(source['text'].encode())==source['text_sha256'],'text_hash');posts[hid,ch]=p
    need(set(posts)=={(i,ch) for i in IDS for ch in CHANNELS},'incomplete_pairs')
    summary=read(BASE/'feed-result/summary.json');need(summary['ok'] is True and summary['operation']==FEED_OPERATION and summary['offer_counts']==[592,592,1184] and summary['old590_offer_blocks_byte_exact'] and summary['old_price_blocks_byte_exact'],'feed_summary')
    data=read(BASE/'feed-result/actual-feed-input.json');served=read(BASE/'feed-result/public-readback.json')
    for name,key in [('actual-feed-input.json','input_sha256'),('public-readback.json','readback_sha256'),('writer-receipt.json','writer_receipt_sha256'),('source.json','source_sha256')]:
        need(sha((BASE/'feed-result'/name).read_bytes())==summary[key],'feed_pin_'+name)
    bindings={};dates=[]
    for prepared,live in zip(data['files'],served['files']):
        old=base64.b64decode(prepared['previous_content_base64'],validate=True);new=base64.b64decode(prepared['content_base64'],validate=True)
        need(new==base64.b64decode(live['public_content_base64'],validate=True)==(BASE/'feed-result'/('public-'+prepared['name'])).read_bytes(),'served_bytes')
        need(sha(new)==prepared['sha256']==live['sha256'] and live['http_status']==200,'served_hash')
        blocks=lambda raw: re.findall(rb'<offer id="[^"<>]+">.*?</offer>',raw,re.S)
        need(blocks(new)[:len(blocks(old))]==blocks(old),'old_offer_bytes')
        xml=ET.fromstring(new);dates.append(xml.attrib['date']);nodes={n.attrib['id']:n for n in xml.iter('offer')}
        channels=('telegram','max') if prepared['name']=='hotel-posts-all.xml' else (('telegram',) if 'telegram' in prepared['name'] else ('max',))
        need(len(nodes)==prepared['rows']==live['rows']==592*len(channels),'offer_count')
        for update in data['publication_updates']:
            hid=update['hotel_id'];need(hid in IDS,'feed_scope')
            ctx=base64.urlsafe_b64encode(json.dumps({'post_id':update['public_route_alias'],'offer_id':'max_'+str(hid)},separators=(',',':')).encode()).decode().rstrip('=')
            b={'tourvisor_hotel_id':hid,'posts':{ch:posts[hid,ch]['post_url'] for ch in CHANNELS},'offer_ids':{'max':'max_'+str(hid),'telegram':'tg_'+str(hid)},'destinations':{'max':'https://max.ru/id9704048781_2_bot?startapp={yclid}_region_{region_id}_campaign_{campaign_id}_ctx1_'+ctx,'telegram':posts[hid,'telegram']['post_url']}}
            bindings[hid]=b
            for ch in channels:
                node=nodes[b['offer_ids'][ch]];need(node.findtext('url')==b['destinations'][ch] and node.findtext('picture')==posts[hid,ch]['photo_url'] and node.find('price') is None,'new_feed_binding')
    need(len(set(dates))==1,'feed_date')
    need(summary['publication_summary_sha256']==sha(terminal) and summary['bound_read_sha256']==sha((BASE/'actual/channel-feed-read.json').read_bytes()),'feed_publication_binding')
    return {'manifest':manifest,'posts':posts,'publicationSummarySha256':sha(terminal),'publicationRun':'https://github.com/pyatkoff/max-search-bot/actions/runs/43382026346/attempts/1','capturedAt':bound['read_at_utc'],'routeProofSha256':summary['route_proof_sha256'],'feedSummarySha256':sha((BASE/'feed-result/summary.json').read_bytes()),'feedBindings':bindings,'feedCapturedAt':served['verified_at_utc'],'feedCatalogDate':dates[0],'feedRun':'https://github.com/pyatkoff/max-search-bot/actions/runs/43382484575/attempts/1'}

def new_photos(hid):
    row=next(h for h in read(BASE/'selected-source.json') if h['tourvisorId']==str(hid));photos=row['photos'];checks=read(BASE/'photo-downloads.json');need(len(set(photos))==3,'three_unique_photos')
    for url in photos:
        p=next(x for x in checks if x['hotel_id']==hid and x['url']==url);need(p['ok'] and p['http']==200 and p['width']>=500 and p['height']>=375 and sha(Path(p['path']).read_bytes())==p['sha256'],'verified_photo_changed')
    return list(photos)
def photo_url(hid):return 'https://www.alibey.com/en/our-hotels/ali-bey-park-manavgat/' if hid==968 else 'https://www.kumkoybeachresort.com/index.php/odalar-suitler/'
def photo_set(hid):
    capture=read(BASE/'actual/channel-feed-read.json')['read_at_utc'];alts={968:['Бассейн Ali Bey Park Manavgat','Водная игровая зона Ali Bey Park Manavgat','Зона отдыха спа Ali Bey Park Manavgat'],1531:['Территория и бассейны Sunis Kumköy Beach Resort','Номер Sunis Kumköy Beach Resort','Зелёная территория Sunis Kumköy Beach Resort']}
    return {'capturedAt':capture,'sourceUrl':photo_url(hid),'proofUrl':'https://github.com/pyatkoff/max-search-bot/actions/runs/43382026346/attempts/1','label':'Три разные официальные фотографии своего отеля просмотрены. Исходные фото и принятая история сохранены; дата съёмки неизвестна.','photos':[{'url':u,'alt':a} for u,a in zip(new_photos(hid),alts[hid])]}
def photo_refs(hid,h):
    url=photo_url(hid);pairs={(r['kind'],r['url']) for r in h.get('sourceRefs',[])}
    return [] if ('official',url) in pairs else [{'kind':'official','label':'Официальная страница · просмотренные фото своего отеля','url':url,'at':read(BASE/'actual/channel-feed-read.json')['read_at_utc'],'note':'Официальные фото открыты и просмотрены 13.11.2026. ID/принятая связь не менялись; фото Club/Sorgun и даты съёмки не переносились.'}]
