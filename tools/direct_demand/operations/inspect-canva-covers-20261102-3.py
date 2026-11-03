import base64,hashlib,json,os,struct,urllib.parse,urllib.request
from pathlib import Path
rows=json.loads("[{\"hotel_id\":282,\"url\":\"https://export-download.canva.com/c487fa80-9862-44a8-9ec0-9b08b87fb7e0/0/0001-3812818112387463865.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261102%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261102T181955Z&X-Amz-Expires=81856&X-Amz-Signature=9e878d582b31075af71b90faeadc0ae862c16b6134e54bd62cff1e7bb98482fd&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Tue%2C%2003%20Nov%202026%2017%3A04%3A11%20GMT\"},{\"hotel_id\":235,\"url\":\"https://export-download.canva.com/7b93a86b-74a8-4ef8-99c9-98d564ceef6d/0/0001-3812818115165260536.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261102%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261102T175325Z&X-Amz-Expires=84542&X-Amz-Signature=86b4573f3a0942d549200fc8536aacb166120134c63939dd30ccdc2ac8b8616e&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Tue%2C%2003%20Nov%202026%2017%3A22%3A27%20GMT\"},{\"hotel_id\":382,\"url\":\"https://export-download.canva.com/c8a2cf73-acac-4e6c-9ffd-b352c0e3dded/0/0001-8722867608011947672.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261103%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261103T143933Z&X-Amz-Expires=8440&X-Amz-Signature=4f9ba476beba9e03ba28f7d714a77192f22171511c7a4227a099b238a4e3a98b&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Tue%2C%2003%20Nov%202026%2017%3A00%3A13%20GMT\"},{\"hotel_id\":250,\"url\":\"https://export-download.canva.com/bb9c1c19-82e6-41ff-b350-a688904a7637/0/0001-5903614241430803680.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261103%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261103T021922Z&X-Amz-Expires=55616&X-Amz-Signature=aeaa79b561359e51525c9372d859c814f818270de6387717c702be223c8c7797&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Tue%2C%2003%20Nov%202026%2017%3A46%3A18%20GMT\"}]")
group=int(os.environ['COVER_GROUP'])
assert group in (0,1) and len(rows)==4
rows=rows[group*3:group*3+3]
assert 1<=len(rows)<=3
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):raise ValueError('unexpected_asset_redirect')
opener=urllib.request.build_opener(NoRedirect())
out={'operation':'commercial-batch24-covers-20261103-read1','mode':'inspect_native_promotional_images_only','message_sends':0,'db_writes':0,'server_connections':0,'group':group,'covers':[]}
Path('covers').mkdir(exist_ok=True)
for row in rows:
 p=urllib.parse.urlsplit(row['url'])
 assert p.scheme=='https' and p.hostname=='export-download.canva.com' and p.port in (None,443) and not p.username and not p.password
 assert row['hotel_id'] in (282,235,382,250)
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
