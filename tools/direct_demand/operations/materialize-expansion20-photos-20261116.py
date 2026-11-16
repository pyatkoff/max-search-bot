"""Fetch only 120 saved public photo URLs on the runner; never touch production."""
from __future__ import annotations
import concurrent.futures, hashlib, io, json, pathlib, re, sys, urllib.request, urllib.error, zipfile

ARCHIVE_SHA='e3f02fb97b0e9080b26f9a91b0ad58f1fbad410764140e7355d7f6319c2086a0'
RESULT_SHA='147c68f3234f7f65b059de3d217d730b9871d7a63309dea5f8daebc7db1ba774'
IDS=[88169,547,15774,12125,57335,2480,2417,2418,2468,12127,27481,23289,50262,72939,2489,85417,2466,2460,99713,43676]

def digest(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()

def plan(archive: bytes) -> list[dict]:
    if digest(archive)!=ARCHIVE_SHA: raise ValueError('retained_archive_changed')
    with zipfile.ZipFile(io.BytesIO(archive)) as z:
        raw=z.read('result.json')
    if digest(raw)!=RESULT_SHA: raise ValueError('retained_result_changed')
    source=json.loads(raw)
    if source.get('ok') is not True: raise ValueError('source_not_successful')
    rows={r['tourvisor_id']:r for r in source['rows']}
    result=[]
    for tv in IDS:
        r=rows[tv];p=r['local_profile']
        if not isinstance(p,dict) or p.get('id')!=tv or not p.get('detailsAvailable'):
            raise ValueError('detailed_local_identity_missing')
        urls=list(dict.fromkeys(p.get('images') or []))
        if len(urls)<6: raise ValueError('photo_candidates_insufficient')
        indices=list(dict.fromkeys([0,1,2,len(urls)//4,len(urls)//2,(3*len(urls))//4]))
        if len(indices)!=6: raise ValueError('sample_not_six')
        for i in indices:
            url=urls[i]
            if not re.fullmatch(r'https://static\.tourvisor\.ru/hotel_pics/(?:verybig|big)/'+str(r['country_id'])+r'/[A-Za-z0-9_-]+\.jpg',url):
                raise ValueError('saved_url_not_in_exact_media_scope')
            result.append({'work_id':r['work_id'],'tourvisor_id':tv,'name':r['name'],'source_fetched_at':p.get('detailsFetchedAt'),
                           'source_image_index':i,'url':url,'relative_path':f"photos/{r['work_id']}_TV{tv}_{i:03d}.jpg"})
    if len(result)!=120 or len({r['url'] for r in result})!=120: raise ValueError('photo_scope_changed')
    return result

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,req,fp,code,msg,headers,newurl):
        return None

def fetch(row: dict,out: pathlib.Path) -> dict:
    result=dict(row,downloaded=False,visually_accepted=False,reuse_rights_newly_confirmed=False)
    try:
        opener=urllib.request.build_opener(NoRedirect())
        req=urllib.request.Request(row['url'],headers={'User-Agent':'AnyTour-Media-Preparation/1.0'})
        with opener.open(req,timeout=15) as response:
            result['http']=response.status
            if response.status!=200 or response.headers.get_content_type()!='image/jpeg': raise ValueError('not_jpeg_response')
            raw=response.read(8*1024*1024+1)
        if not (1024<=len(raw)<=8*1024*1024) or not raw.startswith(b'\xff\xd8'): raise ValueError('invalid_jpeg_bytes')
        path=out/row['relative_path'];path.parent.mkdir(parents=True,exist_ok=True)
        with path.open('xb') as f: f.write(raw)
        result.update(downloaded=True,bytes=len(raw),sha256=digest(raw))
    except Exception as error:
        result['error_type']=type(error).__name__
        if isinstance(error,urllib.error.HTTPError): result['http']=error.code
    return result

def main() -> int:
    if len(sys.argv)!=4 or sys.argv[1] not in ['--plan-only','--fetch']:
        raise ValueError('usage: --plan-only|--fetch retained.zip output_directory')
    rows=plan(pathlib.Path(sys.argv[2]).read_bytes());out=pathlib.Path(sys.argv[3]);out.mkdir(parents=True,exist_ok=True)
    (out/'photo-plan.json').write_text(json.dumps(rows,ensure_ascii=False,indent=2)+'\n')
    if sys.argv[1]=='--plan-only':
        print('MEDIA_PLAN_OK hotels=20 saved_photo_urls=120 network_calls=0');return 0
    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
        results=list(pool.map(lambda row:fetch(row,out),rows))
    summary={'schema':'anytour_saved_photo_materialization_v1','source_run':43845928034,'source_artifact':14214743619,
        'source_archive_sha256':ARCHIVE_SHA,'source_result_sha256':RESULT_SHA,'hotels':20,'media_get_attempts':120,
        'downloaded':sum(r['downloaded'] for r in results),'failed':sum(not r['downloaded'] for r in results),
        'offer_searches':0,'production_connections':0,'site_writes':0,'post_writes':0,'feed_writes':0,
        'visual_acceptance_performed':False,'rights_clearance_performed':False,'rows':results}
    (out/'manifest.json').write_text(json.dumps(summary,ensure_ascii=False,indent=2)+'\n')
    print('MEDIA_RESULT='+json.dumps({k:v for k,v in summary.items() if k!='rows'}))
    return 0 if summary['failed']==0 else 2

if __name__=='__main__':
    raise SystemExit(main())
