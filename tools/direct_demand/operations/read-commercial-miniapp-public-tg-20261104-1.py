import hashlib,json,re,urllib.request,urllib.error
URL='https://tg.rhat.ru/tgmini/'
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args,**kwargs):return None
def clean(s):
    s=re.sub(r'/\*[\s\S]*?\*/','',s)
    s=re.sub(r"""(['"\x60])(?:\\.|(?!\1)[\s\S])*?\1""","'[REDACTED_LITERAL]'",s)
    return re.sub(r'\b[0-9]{6,}\b','0',s)
result={'operation':'commercial-miniapp-current-public-tg-read1','source_url':URL,'server_connections':0,'provider_calls':0,'db_reads':0,'db_writes':0,'message_sends':0,'runtime_writes':0}
try:
    req=urllib.request.Request(URL,headers={'User-Agent':'AnyTour public miniapp contract inspection'})
    with urllib.request.build_opener(NoRedirect).open(req,timeout=35) as r:
        result['http']=r.status;result['content_type']=r.headers.get('Content-Type','');raw=r.read(240001)
    if len(raw)>240000:raise ValueError('PUBLIC_CLIENT_BOUND')
    html=raw.decode('utf-8');result['bytes']=len(raw);result['sha256']=hashlib.sha256(raw).hexdigest()
    result['indicators']={x:x in html for x in ['Telegram.WebApp','initData','start_param','tgWebAppStartParam','post1_','ctx1','post_id','offer_id','Any_tour_bot','save.php','yclid','campaign_id']}
    result['declared_local_contract_files']=sorted(set(re.findall(r"""['"](?:\./)?([A-Za-z0-9_-]+\.(?:php|js))['"]""",html)))
    scripts=re.findall(r'<script\b[^>]*>([\s\S]*?)</script>',html,re.I)
    result['redacted_client_scripts']=[clean(s) for s in scripts if any(t in s for t in ['Telegram','start_param','yclid','save.php'])]
    result['ok']=True
except Exception as e:
    result.update(ok=False,error_type=type(e).__name__)
    if isinstance(e,urllib.error.HTTPError):result['http']=e.code
print(json.dumps(result,ensure_ascii=False,separators=(',',':')))
open('public-tg-miniapp-contract.json','w').write(json.dumps(result,ensure_ascii=False,separators=(',',':')))
