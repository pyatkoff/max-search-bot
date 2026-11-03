import csv,hashlib,json,pathlib,urllib.parse,urllib.request
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):raise ValueError('unexpected_public_feed_redirect')
opener=urllib.request.build_opener(NoRedirect())
report={'operation':'commercial-channel-hotel-feeds-20261103-update1','mode':'public_https_exact_readback','provider_calls':0,'message_sends':0,'files':[]}
for name in ['hotel-posts-telegram.csv','hotel-posts-max.csv','hotel-posts-all.csv']:
 url='https://app.anytoour.ru/feed/'+name
 req=urllib.request.Request(url,headers={'Cache-Control':'no-cache','User-Agent':'AnyTour-Public-Hotel-Feed-Verify/1.0'})
 with opener.open(req,timeout=30) as r:
  raw=r.read(1000001);status=r.status;ctype=r.headers.get('Content-Type','')
 expected=pathlib.Path('.github/traffic-publish',name).read_bytes()
 assert status==200 and raw==expected and len(raw)<=1000000
 rows=list(csv.DictReader(raw.decode('utf-8').splitlines()))
 report['files'].append({'name':name,'public_url':url,'http':status,'content_type':ctype,'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest(),'rows':len(rows)})
report['ok']=True
pathlib.Path(__import__('os').environ['RUNNER_TEMP'],'public-feed-readback.json').write_text(json.dumps(report,indent=2))
print(json.dumps(report,sort_keys=True))
