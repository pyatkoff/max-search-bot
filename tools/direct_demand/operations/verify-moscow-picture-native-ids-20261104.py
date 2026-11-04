import collections,html,json,os,pathlib,re,unicodedata,urllib.parse,xml.etree.ElementTree as ET
temp=pathlib.Path(os.environ['RUNNER_TEMP']);base=pathlib.Path('tools/direct_demand/operations')
def one(folder,name):
 p=list((temp/folder).rglob(name));assert len(p)==1;return p[0]
raw=one('moscow-source','moscow_any.source.xml').read_bytes();assert __import__('hashlib').sha256(raw).hexdigest()=='21ca10ff39422e7509a8fc1835b2046f68243e0c1103b1660aac6e6cf36a5996'
d=json.loads(one('retained-read9','price-readback.json').read_text().splitlines()[-1]);assert len(d['hotels'])==157
native={int(h['local_tv_id']):h for h in d['hotels']}
native.update({17480:{'local_tv_id':17480,'expected_name':'TUI BLUE PALM GARDEN','expected_country_id':4,'identity_provenance':'accepted_native_catalog_read','current_name_matches_expected':True,'catalog':{'id':17480,'name':'TUI BLUE PALM GARDEN','country_id':4,'is_active':1}},1100:{'local_tv_id':1100,'expected_name':'CLUB HOTEL SERA','expected_country_id':4,'identity_provenance':'accepted_native_catalog_read','current_name_matches_expected':True,'catalog':{'id':1100,'name':'CLUB HOTEL SERA','country_id':4,'is_active':1}}})
review={482,476,81173,32577,478,80964}
def fold(s):
 s=re.sub(r'\s*\(.*?\)','',html.unescape(s))
 return ' '.join(''.join(c if c.isalnum() else ' ' for c in unicodedata.normalize('NFKC',s).casefold()).split())
counts=collections.Counter();verified=[];deferred=[]
for offer in ET.fromstring(raw).findall('.//offer'):
 params=urllib.parse.parse_qs(urllib.parse.urlsplit(offer.findtext('url','')).query,keep_blank_values=True)
 if not params.get('hotel',[''])[0]:counts['general_resort_not_hotel_binding']+=1;continue
 pic=urllib.parse.urlsplit(offer.findtext('picture',''));m=re.fullmatch(r'/country([0-9]+)/([0-9]+)\.PNG',pic.path)
 if pic.hostname!='ptours-kazan.ru' or not m:counts['unexpected_picture_schema']+=1;continue
 country,hid=map(int,m.groups());counts['exact_picture_schema_hotel_offers']+=1
 try:sid=int(params['hotel'][0])
 except ValueError:sid=None
 counts['search_hotel_parameter_same_as_picture_id' if sid==hid else 'search_hotel_parameter_differs_from_picture_id']+=1
 if hid not in native:counts['picture_hotel_id_not_in_published159_scope']+=1;continue
 h=native[hid];cat=h.get('catalog',{});name=offer.findtext('name','')
 if name.startswith('Туры в '):name=name[len('Туры в '):]
 if name.endswith(' из Москвы'):name=name[:-len(' из Москвы')]
 ok=hid not in review and int(cat.get('id',0))==hid and int(cat.get('is_active',0))==1 and cat.get('name') and int(cat.get('country_id',0))==int(h['expected_country_id'])==country and h.get('current_name_matches_expected') is True and fold(name)==fold(cat['name'])
 if ok:verified.append({'native_hotel_id':hid,'native_country_id':country,'catalog_name':cat['name'],'state':'PICTURE_COUNTRY_ID_AND_RETAINED_CURRENT_NATIVE_NAME_CORRESPONDENCE_VERIFIED_NO_PRICE_ACCEPTANCE'})
 else:deferred.append({'picture_hotel_id':hid,'picture_country_id':country,'source_public_hotel_name':name,'expected_native_name':h.get('expected_name'),'reason':'COUNTRY_OR_CURRENT_NAME_OR_REVIEW_BINDING_NOT_ADMITTED'})
report={'operation':'commercial-price-source-20261104-moscow-picture-native-binding1','state':'ORIGINAL_SOURCE_COUNTRY_ID_NAME_CORRESPONDENCE_CHECKED_NO_NEW_SOURCE_QUERY','source_gets':0,'photo_gets':0,'db_calls':0,'gateway_calls':0,'price_edits':0,'counts':dict(counts),'verified_published_native_correspondences':verified,'deferred_existing_scope_correspondences':deferred,'verified_count':len(verified),'deferred_existing_scope_count':len(deferred),'arbitrary_numeric_id_not_used_without_country_and_name':True,'checked_price_unit_pending_user_reply':True,'catalog_source_provenance':'originalREAD9plusacceptedoriginalstock39_READ15new2','not_a_current_tour_or_final_basket_binding':True}
assert counts['general_resort_not_hotel_binding']+counts['exact_picture_schema_hotel_offers']+counts['unexpected_picture_schema']==140
assert not ({r['native_hotel_id'] for r in verified}&review)
assert len({r['native_hotel_id'] for r in verified})==len(verified)
(temp/'moscow-picture-native-binding-summary.json').write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n');print(json.dumps(report,ensure_ascii=False))
