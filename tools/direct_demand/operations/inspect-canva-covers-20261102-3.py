import base64,hashlib,json,os,struct,urllib.parse,urllib.request
from pathlib import Path
rows=json.loads("[{\"hotel_id\":1291,\"url\":\"https://export-download.canva.com/53a724b8-e6a0-4fd9-9e1e-214de7491234/0/0001-5175157026828000961.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261103%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261103T054233Z&X-Amz-Expires=60475&X-Amz-Signature=2f94f67be20b45a4de1515c0607324fb2da3b27547245daa4aaf60c67c28c44d&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Tue%2C%2003%20Nov%202026%2022%3A30%3A28%20GMT\"},{\"hotel_id\":15869,\"url\":\"https://export-download.canva.com/e54f8e01-8f9f-455e-b234-56a9bb19b5d4/0/0001-1744540008616701654.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261102%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261102T211609Z&X-Amz-Expires=93189&X-Amz-Signature=5dbff3d34698c2ca2645fb299be20150f2fef2d331082c4011c8865b1329e382&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Tue%2C%2003%20Nov%202026%2023%3A09%3A18%20GMT\"},{\"hotel_id\":253,\"url\":\"https://export-download.canva.com/7a953456-d5e5-4e89-8b68-0bd7a599d5f7/0/0001-1347097345416316670.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261103%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261103T184620Z&X-Amz-Expires=15806&X-Amz-Signature=8f18f78474e246d426d86338b90b0f3c6bd4165b5fcffd1456283b71a31e1781&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Tue%2C%2003%20Nov%202026%2023%3A09%3A46%20GMT\"},{\"hotel_id\":75377,\"url\":\"https://export-download.canva.com/796525b0-fa86-40ad-b321-935ea6081705/0/0001-5175157026759806794.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261103%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261103T011114Z&X-Amz-Expires=78798&X-Amz-Signature=87244fff4a242820bfb6288cce9c8e44cf563aba62a0b42395b15c4ab8b83ae5&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Tue%2C%2003%20Nov%202026%2023%3A04%3A32%20GMT\"}]")
group=int(os.environ['COVER_GROUP'])
assert group in (0,) and len(rows)==4
rows=rows[group*4:group*4+4]
assert 1<=len(rows)<=4
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):raise ValueError('unexpected_asset_redirect')
opener=urllib.request.build_opener(NoRedirect())
out={'operation':'commercial-batch30-covers-20261103-read1','mode':'inspect_native_promotional_images_only','message_sends':0,'db_writes':0,'server_connections':0,'group':group,'covers':[]}
Path('covers').mkdir(exist_ok=True)
for row in rows:
 p=urllib.parse.urlsplit(row['url'])
 assert p.scheme=='https' and p.hostname=='export-download.canva.com' and p.port in (None,443) and not p.username and not p.password
 assert row['hotel_id'] in (1291,15869,253,75377)
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
