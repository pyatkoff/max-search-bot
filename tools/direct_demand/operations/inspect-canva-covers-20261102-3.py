import base64,hashlib,json,os,struct,urllib.parse,urllib.request
from pathlib import Path
rows=json.loads("[{\"hotel_id\":174,\"url\":\"https://export-download.canva.com/8bc272fd-dfa4-4fe8-8898-cc998080b970/0/0001-3462663154066734684.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261101%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261101T231618Z&X-Amz-Expires=75706&X-Amz-Signature=17ebe92de2b116141e2b2acc50012f8dde504b8819dcefc196fc71fde8c6cedb&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Mon%2C%2002%20Nov%202026%2020%3A18%3A04%20GMT\"},{\"hotel_id\":55574,\"url\":\"https://export-download.canva.com/de1d7c84-a43d-4bae-8aad-43e140dcc94f/0/0001-6048855239901022938.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261102%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261102T081859Z&X-Amz-Expires=43931&X-Amz-Signature=25f253cb15b7962246dce08065fec8e5b09b0db23e7b884c9c7f523d1c1f9460&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Mon%2C%2002%20Nov%202026%2020%3A31%3A10%20GMT\"}]")
group=int(os.environ['COVER_GROUP'])
assert group==0 and len(rows)==2
rows=rows[group*3:group*3+3]
assert 1<=len(rows)<=3
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):raise ValueError('unexpected_asset_redirect')
opener=urllib.request.build_opener(NoRedirect())
out={'operation':'commercial-batch11-two-title-fixes-20261102-read1','mode':'inspect_native_promotional_images_only','message_sends':0,'db_writes':0,'server_connections':0,'group':group,'covers':[]}
Path('covers').mkdir(exist_ok=True)
for row in rows:
 p=urllib.parse.urlsplit(row['url'])
 assert p.scheme=='https' and p.hostname=='export-download.canva.com' and p.port in (None,443) and not p.username and not p.password
 assert row['hotel_id'] in (174,55574)
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
