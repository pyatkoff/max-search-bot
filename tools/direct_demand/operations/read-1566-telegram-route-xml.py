"""Bounded public XML observation. It never opens an advertising entry or starts a bot."""
import hashlib, json
from pathlib import Path
import urllib.request, urllib.error
import xml.etree.ElementTree as ET

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args,**kwargs): return None

native=json.loads(Path('pub/registry.json').read_bytes())
candidate=json.loads(Path('pub/original-candidate/route-candidate.json').read_bytes())
expected={r['offer_id']:r for r in candidate['changes']}
assert len(expected)==18
report={'provider_writes':0,'bot_starts':0,'synthetic_ad_entries':0,'feeds':[]}
for label,url in [('telegram','https://app.anytoour.ru/feed/hotel-posts-telegram.xml'),
                  ('common','https://app.anytoour.ru/feed/hotel-posts-all.xml'),
                  ('advertising_telegram','https://app.anytoour.ru/hotel-posts-telegram-main19.php')]:
    result={'kind':label,'url':url,'ok':False}
    try:
        req=urllib.request.Request(url,headers={'Cache-Control':'no-cache, max-age=0','Pragma':'no-cache'})
        with urllib.request.build_opener(NoRedirect).open(req,timeout=12) as response:
            data=response.read(4_000_001)
            result.update(status=response.status,date=response.headers.get('Date'),cache_control=response.headers.get('Cache-Control'),age=response.headers.get('Age'))
            assert response.status==200 and response.url==url and 0<len(data)<=4_000_000
        root=ET.fromstring(data); offers=root.findall('.//offer')
        indexed={r.attrib['id']:r for r in offers}
        assert len(indexed)==len(offers)
        result.update(bytes=len(data),sha256=hashlib.sha256(data).hexdigest(),offers=len(offers))
        result['rows']=[]
        for ident,r in expected.items():
            offer=indexed.get(ident); source=r['source_url']; ad=f'https://t.me/Any_tour_bot?startapp={{yclid}}_{ident}'
            actual=offer.findtext('url') if offer is not None else None
            ok=actual==ad if label=='advertising_telegram' else actual in (source,ad)
            result['rows'].append({'offer_id':ident,'url':actual,'raw_source_preserved':actual==source,
                                   'original_yclid_template':actual==ad,'ok':ok})
        result['ok']=len(offers)==(2110 if label=='common' else 1055) and all(r['ok'] for r in result['rows'])
    except urllib.error.HTTPError as e:
        result.update(status=e.code,reason='http_error',retry_after=e.headers.get('Retry-After'))
    except Exception as e: result['reason']=type(e).__name__
    report['feeds'].append(result)
report['ok']=all(r['ok'] for r in report['feeds'])
Path('pub/xml-readback.json').write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n')
print(json.dumps({'xml_ok':report['ok'],'feeds':[{k:r.get(k) for k in ('kind','status','offers','ok','reason')} for r in report['feeds']]}))
# Partial observations remain visible and do not create or replay provider operations.
raise SystemExit(0 if report['ok'] else 1)
