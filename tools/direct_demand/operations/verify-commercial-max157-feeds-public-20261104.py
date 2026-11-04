import hashlib,json,os,pathlib,urllib.request,xml.etree.ElementTree as ET
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):raise ValueError('unexpected_public_feed_redirect')
opener=urllib.request.build_opener(NoRedirect())
temp=pathlib.Path(os.environ['RUNNER_TEMP'])
report={'operation':'commercial-channel-hotel-feeds-20261104-update13','mode':'public_https_exact_miniapp_yml_readback','provider_calls':0,'message_sends':0,'files':[]}
for platform,rows in [('telegram',157),('max',157),('all',314)]:
 name='hotel-posts-'+platform+'.xml';url='https://app.anytoour.ru/feed/'+name
 with opener.open(urllib.request.Request(url,headers={'Cache-Control':'no-cache','User-Agent':'AnyTour-Public-Hotel-Feed-Verify/1.0'}),timeout=30) as response:
  raw=response.read(1000001);status=response.status;ctype=response.headers.get('Content-Type','')
 expected=pathlib.Path('.github/traffic-publish',name).read_bytes()
 assert status==200 and raw==expected and len(raw)<=1000000 and ctype.split(';',1)[0].strip() in ['application/xml','text/xml']
 offers=ET.fromstring(raw).findall('shop/offers/offer');assert len(offers)==rows and all(o.findtext('name') and o.findtext('description') for o in offers)
 maxrows=[o for o in offers if o.attrib['id'].startswith('max_')]
 assert len(maxrows)==(0 if platform=='telegram' else 157) and all(o.findtext('url').startswith('https://max.ru/id9704048781_2_bot?startapp=') for o in maxrows)
 report['files'].append({'name':name,'public_url':url,'http':status,'content_type':ctype,'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest(),'offers':len(offers),'name_count':len(offers),'description_count':len(offers),'max_miniapp_routes':len(maxrows)})
report['ok']=True
(temp/'public-feed-readback.json').write_text(json.dumps(report,indent=2))
print(json.dumps(report,sort_keys=True))
