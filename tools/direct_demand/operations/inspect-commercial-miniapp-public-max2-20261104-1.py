import hashlib,json,re,urllib.error,urllib.parse,urllib.request
from html.parser import HTMLParser
from pathlib import Path
URL='https://tour-max.ru/new/max2/'
def ref(value):
 p=urllib.parse.urlsplit(urllib.parse.urljoin(URL,value))
 if p.scheme!='https' or p.hostname!='tour-max.ru' or p.netloc!='tour-max.ru' or p.query or p.fragment or p.username or p.password:return None
 if not re.fullmatch(r'/new/max2/[A-Za-z0-9_./-]+\.js',p.path):return None
 return urllib.parse.urlunsplit(p)
assert ref('app.js')=='https://tour-max.ru/new/max2/app.js'
assert ref('app.js?access_token=private') is None
assert ref('https://foreign.example/app.js') is None
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):raise ValueError('redirect_not_followed')
class Parser(HTMLParser):
 def __init__(self):super().__init__();self.scripts=[];self.title='';self.in_title=False
 def handle_starttag(self,tag,attrs):
  a=dict(attrs)
  if tag=='title':self.in_title=True
  if tag=='script' and a.get('src'):
   s=ref(a['src'])
   if s and s not in self.scripts:self.scripts.append(s)
 def handle_endtag(self,tag):
  if tag=='title':self.in_title=False
 def handle_data(self,s):
  if self.in_title:self.title+=s
out={'operation':'commercial-miniapp-public-max2-contract-read1','source_url':URL,'mode':'one_public_https_html_get','max_requests':1,'requests_attempted':0,'server_connections':0,'provider_calls':0,'db_writes':0,'message_sends':0,'new_ref_guard_cases':3}
try:
 opener=urllib.request.build_opener(NoRedirect())
 out['requests_attempted']=1
 with opener.open(urllib.request.Request(URL,headers={'User-Agent':'AnyTour-Public-Route-Inspection/1.0','Accept':'text/html'}),timeout=35) as r:
  raw=r.read(1000001);out['http']=r.status;out['content_type']=r.headers.get('Content-Type','')
  assert r.status==200 and len(raw)<=1000000 and out['content_type'].split(';',1)[0].strip() in ['text/html','application/xhtml+xml']
 text=raw.decode('utf-8',errors='replace');p=Parser();p.feed(text)
 out.update({'ok':True,'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest(),'page_title':re.sub(r'[^A-Za-zА-Яа-яЁё0-9 .,:!()]+',' ',p.title)[:160],'declared_public_sameowner_js':p.scripts[:12],'indicators':{k:(k in text) for k in ['Telegram.WebApp','WebApp.initData','start_param','startapp','yclid','post_id','offer_id','ctx1','tgWebAppStartParam','MAX.WebApp','openLink']},'accepted_parameter_names':sorted(set(re.findall(r'(?:searchParams|urlParams|params)\.get\([\'"]([A-Za-z_]{1,40})[\'"]\)',text)))[:30],'inline_code_exported':False})
except Exception as e:
 out.update({'ok':False,'error_type':type(e).__name__})
 if isinstance(e,urllib.error.HTTPError):out['http']=e.code
Path('public-miniapp-contract.json').write_text(json.dumps(out,ensure_ascii=False,indent=2)+'\n')
print(json.dumps(out,ensure_ascii=False,sort_keys=True))
