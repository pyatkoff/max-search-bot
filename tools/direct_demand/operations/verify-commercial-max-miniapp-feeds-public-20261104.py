import hashlib,json,os,pathlib,urllib.request,xml.etree.ElementTree as ET
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):raise ValueError('unexpected_public_feed_redirect')
opener=urllib.request.build_opener(NoRedirect())
temp=pathlib.Path(os.environ['RUNNER_TEMP'])
writer=json.loads((temp/'channel-preflight.json').read_text().splitlines()[-1])
tg=next(r for r in writer['files'] if r['name']=='hotel-posts-telegram.xml')
assert tg['sha256']=='807da5b17cbda96860c952417bd880091246991e39504ba1e65520f392d70d70'
assert 'reuse' in tg['write_action'].lower()
report={'operation':'commercial-channel-hotel-feeds-20261104-update11','mode':'public_https_exact_max_routing_yml_readback','provider_calls':0,'message_sends':0,'files':[{'name':tg['name'],'bytes':tg['bytes'],'sha256':tg['sha256'],'offers':150,'verification':'UNCHANGED_FROM_UPDATE10_NATIVE_SAMEHASH_REUSE','new_http_read':False,'previous_accepted_http':200}]}
for platform,rows in [('max',150),('all',300)]:
 name='hotel-posts-'+platform+'.xml';url='https://app.anytoour.ru/feed/'+name
 req=urllib.request.Request(url,headers={'Cache-Control':'no-cache','User-Agent':'AnyTour-Public-Hotel-Feed-Verify/1.0'})
 with opener.open(req,timeout=30) as response:
  raw=response.read(1000001);status=response.status;ctype=response.headers.get('Content-Type','')
 expected=pathlib.Path('.github/traffic-publish',name).read_bytes()
 assert status==200 and raw==expected and len(raw)<=1000000 and ctype.split(';',1)[0].strip() in ['application/xml','text/xml']
 offers=ET.fromstring(raw).findall('shop/offers/offer')
 assert len(offers)==rows and all(o.findtext('name') and o.findtext('description') for o in offers)
 maxrows=[o for o in offers if o.attrib['id'].startswith('max_')]
 assert len(maxrows)==150 and all(o.findtext('url').startswith('https://max.ru/id9704048781_2_bot?startapp=') for o in maxrows)
 report['files'].append({'name':name,'public_url':url,'http':status,'content_type':ctype,'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest(),'offers':len(offers),'name_count':len(offers),'description_count':len(offers),'max_miniapp_routes':150,'new_http_read':True})
report['ok']=True
(temp/'public-feed-readback.json').write_text(json.dumps(report,indent=2))
print(json.dumps(report,sort_keys=True))
