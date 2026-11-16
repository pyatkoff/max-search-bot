"""One finite runner-only materialization of existing hotel sources; no production writes."""
from __future__ import annotations
import concurrent.futures, hashlib, io, json, pathlib, re, sys, urllib.request, urllib.error, zipfile
SOURCE_SHA='e3f02fb97b0e9080b26f9a91b0ad58f1fbad410764140e7355d7f6319c2086a0'
RESULT_SHA='147c68f3234f7f65b059de3d217d730b9871d7a63309dea5f8daebc7db1ba774'
DONE={88169,547,15774,12125,57335,2480,2417,2418,2468,12127,27481,23289,50262,72939,2489,85417,2466,2460,99713,43676}
REMAIN={48263,82790,24743,66547,60232,78033,43569,78002,2451,43675,63208,2491,2470,64954,2479,2493,76318,53617,12124,548,2947,4032,76011,65108,123308,65938,80008,2436,108165,2444,2445,63191,218}
def sha(raw):return hashlib.sha256(raw).hexdigest()
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args,**kwargs):return None

def prepare(source):
    raw=source.read_bytes()
    if sha(raw)!=SOURCE_SHA:raise ValueError('source_archive_changed')
    with zipfile.ZipFile(io.BytesIO(raw)) as z:raw=z.read('result.json')
    if sha(raw)!=RESULT_SHA:raise ValueError('source_result_changed')
    data=json.loads(raw)
    if data.get('ok') is not True:raise ValueError('source_failed')
    selected=[];plan=[]
    for r in data['rows']:
        tv=r['tourvisor_id'];p=r.get('local_profile') or {};ret=r.get('retained_source') or {}
        if tv in DONE:continue
        if p.get('detailsAvailable'):
            if p.get('id')!=tv:raise ValueError('local_identity_changed')
            profile=p;mode='retained_effective_local_profile';fetched=p.get('detailsFetchedAt')
        elif ret.get('valid_source') is True and ret.get('hotel_id')==tv and ret.get('status')=='success':
            profile=ret['normalized']['content'];mode='retained_normalized_source_not_local_intake';fetched=ret.get('fetched_at')
        else:continue
        if tv not in REMAIN:raise ValueError('unexpected_remaining_hotel')
        if profile.get('country',{}).get('id')!=r['country_id']:raise ValueError('country_mismatch')
        selected.append({'work_id':r['work_id'],'tourvisor_id':tv,'name':r['name'],'country_id':r['country_id'],'source_mode':mode,'source_fetched_at':fetched,'profile':profile,'identity_admitted':False,'content_admitted':False,'publication_allowed':False})
        urls=list(dict.fromkeys(profile.get('images') or []))
        if len(urls)<6:raise ValueError('insufficient_saved_photos')
        # Six spread-out retained frames, never fabricate URLs or ask a hotel supplier.
        indices=[]
        for i in [0,1,2,len(urls)//4,len(urls)//2,3*len(urls)//4]+list(range(len(urls))):
            if i not in indices:indices.append(i)
            if len(indices)==6:break
        for i in indices:
            url=urls[i]
            if not re.fullmatch(r'https://static\.tourvisor\.ru/hotel_pics/(?:verybig|big)/'+str(r['country_id'])+r'/[A-Za-z0-9_-]+\.jpg',url):raise ValueError('media_scope_invalid')
            plan.append({'work_id':r['work_id'],'tourvisor_id':tv,'source_image_index':i,'url':url,'path':f"photos/{r['work_id']}_TV{tv}_{i:03d}.jpg"})
    if len(selected)!=33 or {r['tourvisor_id'] for r in selected}!=REMAIN or len(plan)!=198:raise ValueError('remaining_scope_changed')
    if len({r['url'] for r in plan})!=198:raise ValueError('duplicate_saved_media')
    return selected,plan

def fetch(row,out):
    r={**row,'downloaded':False,'visually_reviewed':False,'rights_newly_granted':False}
    try:
        q=urllib.request.Request(row['url'],headers={'User-Agent':'AnyTour-Saved-Media/1.0'})
        with urllib.request.build_opener(NoRedirect()).open(q,timeout=15) as response:
            r['http']=response.status
            if response.status!=200 or response.headers.get_content_type()!='image/jpeg':raise ValueError('not_jpeg')
            raw=response.read(8388609)
        if not 1024<=len(raw)<=8388608 or not raw.startswith(b'\xff\xd8'):raise ValueError('invalid_jpeg')
        target=out/row['path'];target.parent.mkdir(parents=True,exist_ok=True)
        with target.open('xb') as f:f.write(raw)
        r.update(downloaded=True,bytes=len(raw),sha256=sha(raw))
    except Exception as e:
        r['error_type']=type(e).__name__
        if isinstance(e,urllib.error.HTTPError):r['http']=e.code
    return r

def main():
    if len(sys.argv)!=4 or sys.argv[1] not in ('--plan-only','--fetch'):raise ValueError('invalid_arguments')
    hotels,plan=prepare(pathlib.Path(sys.argv[2]));out=pathlib.Path(sys.argv[3]);out.mkdir(parents=True,exist_ok=True)
    for name,data in [('source-dossiers33.json',hotels),('photo-plan.json',plan)]:
        (out/name).write_text(json.dumps(data,ensure_ascii=False,indent=2)+'\n')
    if sys.argv[1]=='--plan-only':print('PLAN_OK hotels=33 saved_photos=198 old_published_excluded=20');return 0
    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:rows=list(pool.map(lambda r:fetch(r,out),plan))
    summary={'schema':'anytour_remaining33_media_v1','source_run':43845928034,'source_artifact':14214743619,'source_archive_sha256':SOURCE_SHA,'source_result_sha256':RESULT_SHA,'hotels':33,'download_attempts':198,'downloaded':sum(r['downloaded'] for r in rows),'failed':sum(not r['downloaded'] for r in rows),'post_writes':0,'feed_writes':0,'db_writes':0,'supplier_offer_searches':0,'new_knowledge_admissions':0,'rows':rows}
    (out/'manifest.json').write_text(json.dumps(summary,ensure_ascii=False,indent=2)+'\n')
    print('MEDIA_RESULT='+json.dumps({k:v for k,v in summary.items() if k!='rows'}))
    return 0 if summary['failed']==0 else 2
if __name__=='__main__':raise SystemExit(main())
