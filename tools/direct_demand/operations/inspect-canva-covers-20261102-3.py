import base64,hashlib,json,os,struct,urllib.parse,urllib.request
from pathlib import Path
rows=json.loads("[{\"hotel_id\":272,\"url\":\"https://export-download.canva.com/5ad972d4-3185-40cd-8e16-61161e4fd5df/0/0001-3507699213305530322.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261103%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261103T090019Z&X-Amz-Expires=7046&X-Amz-Signature=adec7d521e4970998c0b299f041718446e7ff7210ac5b13e4710be6c02f0c390&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Tue%2C%2003%20Nov%202026%2010%3A57%3A45%20GMT\"},{\"hotel_id\":273,\"url\":\"https://export-download.canva.com/f90a8a4f-7f21-4d86-9b22-c8461afefcb7/0/0001-2476374897611595376.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261103%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261103T040301Z&X-Amz-Expires=27060&X-Amz-Signature=94b38959d15d9ad688df739e1e46f50bba4416ee680e604118d2e574d50111e0&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Tue%2C%2003%20Nov%202026%2011%3A34%3A01%20GMT\"}]")
group=int(os.environ['COVER_GROUP'])
assert group==0 and len(rows)==2
rows=rows[group*3:group*3+3]
assert 1<=len(rows)<=3
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):raise ValueError('unexpected_asset_redirect')
opener=urllib.request.build_opener(NoRedirect())
out={'operation':'commercial-batch19-covers-20261103-read2','mode':'inspect_native_promotional_images_only','message_sends':0,'db_writes':0,'server_connections':0,'group':group,'covers':[]}
Path('covers').mkdir(exist_ok=True)
for row in rows:
 p=urllib.parse.urlsplit(row['url'])
 assert p.scheme=='https' and p.hostname=='export-download.canva.com' and p.port in (None,443) and not p.username and not p.password
 assert row['hotel_id'] in (272,273)
 with opener.open(row['url'],timeout=35) as r:
  raw=r.read(1600001)
  assert r.status==200
 assert 10000<len(raw)<=1600000 and raw[:8]==b'\x89PNG\r\n\x1a\n'
 width,height=struct.unpack('>II',raw[16:24]);assert 500<=width<=2500 and 500<=height<=2500
 digest=hashlib.sha256(raw).hexdigest();filename=str(row['hotel_id'])+'.png';Path('covers',filename).write_bytes(raw)
 b64=base64.b64encode(raw).decode();parts=[b64[i:i+48000] for i in range(0,len(b64),48000)]
 meta={'hotel_id':row['hotel_id'],'mime':'image/png','width':width,'height':height,'bytes':len(raw),'sha256':digest,'file':filename,'chunks':len(parts)}
 out['covers'].append(meta);print(json.dumps({'kind':'cover',**meta}),flush=True)
 for i,value in enumerate(parts):print(json.dumps({'kind':'cover_chunk','hotel_id':row['hotel_id'],'index':i,'total':len(parts),'data':value}),flush=True)
out['ok']=True
Path('native-cover-receipt.json').write_text(json.dumps(out,indent=2)+'\n');print(json.dumps(out),flush=True)
