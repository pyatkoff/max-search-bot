#!/usr/bin/env python3
"""Finite public hotel destination inspection in the existing cloud workflow."""
import csv,hashlib,html,json,os,pathlib,re,subprocess,urllib.parse,urllib.request
BASE='https://anytoour.ru/poisk-turov/'
MAX_BYTES=2_500_000
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args,**kwargs):return None
opener=urllib.request.build_opener(NoRedirect)
def get(url):
    u=urllib.parse.urlsplit(url)
    assert u.scheme=='https' and u.hostname=='anytoour.ru' and not u.username and not u.password and not u.fragment
    req=urllib.request.Request(url,headers={'User-Agent':'AnyTour-public-hotel-feed-contract/1','Accept':'text/html,application/javascript'})
    with opener.open(req,timeout=25) as r:
        assert r.status==200
        data=r.read(MAX_BYTES+1)
        assert 0<len(data)<=MAX_BYTES
        return data.decode('utf-8-sig'),r.headers.get('Content-Type','')
root=pathlib.Path(os.environ['RUNNER_TEMP'])
rows=json.loads(pathlib.Path('tools/direct_demand/operations/commercial-hotel-feed-20261103-contract1-inputs.json').read_text())
page,content_type=get(BASE)
assert 'text/html' in content_type and 'id="tourSearch"' in page
scripts=[html.unescape(x) for x in re.findall(r'<script[^>]+src=["\']([^"\']+)["\']',page)]
bundle=[urllib.parse.urljoin(BASE,x).split('#')[0] for x in scripts if 'bundle-v1.php?' in x and 'type=js' in x]
assert len(bundle)==1
script,_=get(bundle[0])
start=script.index("const rt=window.V2Runtime,renderer=window.V2Results,catalogs=window.V2Catalogs;")
section=script[start:]
assert "await catalogs.init();" in section and "const hydrated=hydrateUrlState();" in section
assert section.index("await catalogs.init();")<section.index("const hydrated=hydrateUrlState();")
def function(name):
    match=re.search(r'function '+re.escape(name)+r'\([^]*', '') if False else re.search(r'function '+re.escape(name)+r'\(',section)
    assert match,name
    end=section.find('\nfunction ',match.start()+1)
    assert end!=-1
    return section[match.start():end]
functions='\n'.join(function(name) for name in ['params','queryValue','setField','hydrateUrlState'])
node=pathlib.Path('tools/direct_demand/operations/check-hotel-url-hydration-20261103.js').read_text()
proc=subprocess.run(['node','-e',node],input=json.dumps({'rows':rows,'functions':functions}),text=True,capture_output=True,timeout=15)
assert proc.returncode==0,proc.stderr
checked=json.loads(proc.stdout)
assert checked['checked']==len(rows) and checked['automatic_search'] is False and checked['selected_hotel_preserved'] is True
headers=['Property ID','Property name','Final URL','Destination name','Image URL','Category']
with (root/'commercial-hotel-feed-20261103-contract1.csv').open('w',newline='',encoding='utf-8') as f:
    writer=csv.DictWriter(f,fieldnames=headers)
    writer.writeheader()
    for row in rows:
        assert row['hotel_id']>0 and len(row['destination'])<=25 and re.fullmatch(r'[a-f0-9]{64}',row['image_sha256'])
        assert urllib.parse.urlsplit(row['image_url']).hostname=='raw.githubusercontent.com'
        query=urllib.parse.urlencode({'country':1,'region':row['region_id'],'hotel':row['hotel_id']})
        writer.writerow({'Property ID':str(row['hotel_id']),'Property name':row['name'],'Final URL':BASE+'?'+query,'Destination name':row['destination'],'Image URL':row['image_url'],'Category':'отель'})
csv_bytes=(root/'commercial-hotel-feed-20261103-contract1.csv').read_bytes()
parsed=list(csv.DictReader(csv_bytes.decode('utf-8').splitlines()))
assert len(parsed)==len(rows)==6 and len({x['Property ID'] for x in parsed})==6
assert all('Price' not in x and 'available' not in x for x in parsed)
summary={'operation':'commercial-hotel-feed-20261103-contract1','state':'SIX_HOTEL_FEED_ROWS_PREPARED_PUBLIC_ROUTE_AND_OFFLINE_HYDRATION_VERIFIED_NOT_IMPORTED','catalog_ids':[r['hotel_id'] for r in rows],'row_count':len(rows),'http_gets':2,'script_executed_in_browser':False,'provider_search_calls':0,'server_admin_connections':0,'advertising_changes':0,'price_fields':0,'csv_sha256':hashlib.sha256(csv_bytes).hexdigest(),'landing_http':200,'offline_contract_cases':checked['checked']}
(root/'commercial-hotel-feed-20261103-contract1.json').write_text(json.dumps(summary,ensure_ascii=False,sort_keys=True))
print(json.dumps(summary,ensure_ascii=False,sort_keys=True))
