import hashlib,json,re,urllib.request
from html.parser import HTMLParser
from pathlib import Path
URL='https://tour-max.ru/new/max2/'
allowed={'post_id','offer_id','ctx1','_ctx1_','start_param','startapp','yclid','region','region_id','campaign','campaign_id','tgWebAppData','hash','auth_date','query_id','user','initData','initDataUnsafe','WebApp','post_url','message_url','channel_url','publication_id','platform','max','telegram','url','error','ok','success','POST','GET','application/json','Content-Type','credentials','same-origin','include','target','payload','save','channel','offer','version','token'}
literal=re.compile(r'"(?:\\.|[^"\\])*"|\'(?:\\.|[^\'\\])*\'|\x60(?:\\.|[^\x60\\])*\x60',re.S)
def redact(src):
 def replace(m):
  raw=m.group(0);v=raw[1:-1]
  if v in allowed or re.fullmatch(r'[A-Za-z0-9_./-]{1,100}\.(?:php|js)',v):return raw
  return '"[redacted literal]"'
 src=literal.sub(replace,src)
 src=re.sub(r'(?<![A-Za-z_])[-]?\d{6,}(?![A-Za-z_])','0',src)
 src=re.sub(r'/\*.*?\*/','',src,flags=re.S)
 src=re.sub(r'(?m)^\s*//.*$','',src)
 return src
assert 'private-secret-123' not in redact('const key="private-secret-123";')
assert 'post_id' in redact('a["post_id"]=x; fetch("save.php");')
assert '123456789' not in redact('const channel=-123456789;')
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*a,**k):raise ValueError('redirect_not_followed')
class Parser(HTMLParser):
 def __init__(self):super().__init__();self.inside=False;self.current='';self.scripts=[]
 def handle_starttag(self,tag,attrs):
  if tag=='script' and not dict(attrs).get('src'):self.inside=True;self.current=''
 def handle_endtag(self,tag):
  if tag=='script' and self.inside:self.inside=False;self.scripts.append(self.current)
 def handle_data(self,s):
  if self.inside:self.current+=s
out={'operation':'commercial-miniapp-public-max2-code-contract-read2','source_url':URL,'reason':'New ctx1/post_id frontend indicators contradict olderbotdocs;firsttargetedcodecontractinspection','server_connections':0,'provider_calls':0,'db_writes':0,'message_sends':0,'new_redaction_cases':3}
try:
 with urllib.request.build_opener(NoRedirect()).open(urllib.request.Request(URL,headers={'User-Agent':'AnyTour-Public-Route-Inspection/1.0','Accept':'text/html'}),timeout=35) as r:
  raw=r.read(1000001);assert r.status==200 and len(raw)<=1000000
 out['http']=200;out['bytes']=len(raw);out['sha256']=hashlib.sha256(raw).hexdigest();out['same_as_read1']=out['sha256']=='5c06578f61aedb5473ce50efa29d5a9c7c0f5bf0a3109d2de3768a4f97659770'
 p=Parser();p.feed(raw.decode('utf-8',errors='replace'))
 scripts=[redact(s) for s in p.scripts if any(k in s for k in ['start_param','ctx1','post_id','startapp'])]
 out['redacted_contract_scripts']=scripts;out['ok']=True;out['inline_literals_or_private_ids_exported']=False
except Exception as e:out.update({'ok':False,'error_type':type(e).__name__})
Path('public-miniapp-code-contract.json').write_text(json.dumps(out,ensure_ascii=False,indent=2)+'\n')
print(json.dumps(out,ensure_ascii=False))
