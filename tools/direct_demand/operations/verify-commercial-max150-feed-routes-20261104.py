import base64,copy,hashlib,json,os,pathlib,re,xml.etree.ElementTree as ET
BASE=pathlib.Path('.github/traffic-publish')
OPS=pathlib.Path('tools/direct_demand/operations')
BASELINE=json.loads((OPS/'commercial-channel-hotel-feeds-20261104-update11-route-baseline.json').read_text())
CANONICAL=json.loads((pathlib.Path(os.environ['RUNNER_TEMP'])/'canonical150'/'channel-preflight.json').read_text().splitlines()[-1])
assert CANONICAL['ok'] and CANONICAL['operation']=='commercial-miniapp-canonical-150-bindings-read1' and CANONICAL['binding_count']==300
URLS={}
for r in CANONICAL['bindings']:
    if r['platform']!='max':continue
    offer='max_'+str(r['hotel_id']);alias='h1_'+hashlib.sha256(('anytour|'+offer+'|'+r['post_url']).encode()).hexdigest()[:32]
    assert r['public_offer_id']==offer and r['public_route_alias']==alias
    encoded=base64.urlsafe_b64encode(json.dumps({'post_id':alias,'offer_id':offer},separators=(',',':')).encode()).decode().rstrip('=')
    URLS[offer]='https://max.ru/id9704048781_2_bot?startapp={yclid}_region_{region_id}_campaign_{campaign_id}_ctx1_'+encoded
assert len(URLS)==150
def insist(ok,why):
    if not ok:raise ValueError(why)
def blocks(raw):
    s=raw.decode()
    return {m[1]:m[0] for m in re.finditer(r'<offer\b[^>]*\bid="([^"]+)"[^>]*>[\s\S]*?</offer>',s)}
def check(name,raw):
    b=BASELINE[name]
    insist(b'<!DOCTYPE' not in raw.upper() and b'<!ENTITY' not in raw.upper(),'unsafe_xml_declaration')
    doc=ET.fromstring(raw);offers=doc.findall('shop/offers/offer')
    outside=re.sub(r'<offer\b[^>]*\bid="([^"]+)"[^>]*>[\s\S]*?</offer>',lambda m:'<offer id="'+m[1]+'">__OFFER__</offer>',raw.decode())
    outside=re.sub(r'<yml_catalog date="[^"]+">','<yml_catalog date="__DATE__">',outside)
    insist(hashlib.sha256(outside.encode()).hexdigest()==b['non_offer_bytes_sha256'],'non_offer_metadata_bytes_changed')
    insist(len(offers)==b['rows'],'offer_count')
    rawblocks=blocks(raw);insist(set(rawblocks)==set(b['non_url_blocks']),'offer_identity_scope')
    if name=='hotel-posts-telegram.xml':insist(hashlib.sha256(raw).hexdigest()==b['sha256'],'telegram_bytes_changed')
    result={}
    for o in offers:
        oid=o.attrib['id'];block=rawblocks[oid]
        stable=re.sub(r'<url>[\s\S]*?</url>','<url>__URL__</url>',block,count=1)
        insist(hashlib.sha256(stable.encode()).hexdigest()==b['non_url_blocks'][oid],'non_url_offer_bytes_changed')
        insist(o.findtext('name') and o.findtext('description'),'required_name_description')
        name_text=o.findtext('name');desc=o.findtext('description')
        insist(len(name_text)<=56 and len(desc)<=81 and len(name_text.split())<=22 and len(desc.split())<=23,'native_text_limits')
        insist(not re.search(r'[-‐‑‒–—−]',name_text+desc) and len(re.findall(r'[^\w\s]',desc))<=15,'native_copy_punctuation')
        insist(o.find('price') is None and 'available' not in o.attrib,'invented_price_availability')
        url=o.findtext('url')
        if oid.startswith('max_'):
            insist(url==URLS.get(oid),'route_does_not_match_native_candidate')
            prefix='https://max.ru/id9704048781_2_bot?startapp={yclid}_region_{region_id}_campaign_{campaign_id}_ctx1_'
            insist(url.startswith(prefix),'wrong_miniapp_bot_or_ad_macros')
            encoded=url[len(prefix):]
            insist(re.fullmatch(r'[A-Za-z0-9_-]{1,256}',encoded) is not None,'invalid_context_token')
            value=json.loads(base64.urlsafe_b64decode(encoded+'='*((4-len(encoded)%4)%4)))
            insist(list(value)==['post_id','offer_id'] and value['offer_id']==oid and re.fullmatch(r'h1_[a-f0-9]{32}',value['post_id']) is not None,'public_alias_binding')
        else:insist(url.startswith('https://t.me/any_toour/'),'telegram_destination_changed')
        result[oid]=block
    return result
actual={n:(BASE/n).read_bytes() for n in BASELINE}
checked={n:check(n,b) for n,b in actual.items()}
insist(checked['hotel-posts-all.xml']=={**checked['hotel-posts-telegram.xml'],**checked['hotel-posts-max.xml']},'combined_exact_union')
sample=actual['hotel-posts-max.xml'];one=next(iter(URLS.values()))
bad=sample.replace(one.encode(),one.replace('id9704048781_2_bot','id9704048781_1_bot').encode(),1)
try:check('hotel-posts-max.xml',bad);raise AssertionError('foreign_bot_accepted')
except ValueError:pass
other=next(u for u in URLS.values() if u!=one)
bad=sample.replace(one.encode(),other.encode(),1)
try:check('hotel-posts-max.xml',bad);raise AssertionError('other_hotel_accepted')
except ValueError:pass
bad=sample.replace('<description>'.encode(),'<description>Изменение '.encode(),1)
try:check('hotel-posts-max.xml',bad);raise AssertionError('description_rewrite_accepted')
except ValueError:pass
print('MAX150_ROUTE_YML_OK new_cases=3 max_exact_routes=150 tg150_bytes_unchanged=1 combined300_exact_union=1 other_offer_bytes_unchanged=1 private_ids_in_urls=0 old_yml_fixtures_rerun=0')
