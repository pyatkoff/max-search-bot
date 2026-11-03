import base64,hashlib,json,os,struct,urllib.parse,urllib.request
from pathlib import Path
rows=json.loads("[{\"hotel_id\":1449,\"url\":\"https://export-download.canva.com/fcebe5a6-8541-481f-9bdf-c99b42efdfbc/0/0001-4111181616994826616.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261103%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261103T081925Z&X-Amz-Expires=57579&X-Amz-Signature=6277e1440bc267ec5d7728970f1c6c54ee7ec966fdeea97cd7315f731ea2d5ea&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Wed%2C%2004%20Nov%202026%2000%3A19%3A04%20GMT\"},{\"hotel_id\":1453,\"url\":\"https://export-download.canva.com/9cfb6f90-67c4-439e-b191-eb6627a8382f/0/0001-4283444306369441364.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261103%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261103T153138Z&X-Amz-Expires=29847&X-Amz-Signature=cba7b38f032b5994450a1a4b3043dbf2f7ad1abf80e5997bc0ff30f592531acf&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Tue%2C%2003%20Nov%202026%2023%3A49%3A05%20GMT\"},{\"hotel_id\":9432,\"url\":\"https://export-download.canva.com/2541a453-a00c-4581-8792-cc7078aa7112/0/0001-4611081175569010185.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261103%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261103T204944Z&X-Amz-Expires=10482&X-Amz-Signature=a583939bf579552569340a619d3a533ed50493664377dfafdfee583fb90d3d71&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Tue%2C%2003%20Nov%202026%2023%3A44%3A26%20GMT\"},{\"hotel_id\":1451,\"url\":\"https://export-download.canva.com/8908cb91-2115-483b-a915-d0da2ea91987/0/0001-5132372834967196087.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261103%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261103T045233Z&X-Amz-Expires=68424&X-Amz-Signature=0eb391c030c80a1cf324ee67268fa70531034bbd7b0b169e05cbb6c3b7eccc78&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Tue%2C%2003%20Nov%202026%2023%3A52%3A57%20GMT\"},{\"hotel_id\":1151,\"url\":\"https://export-download.canva.com/5dd0b4e1-3f96-43c0-b164-5995f8061b5f/0/0001-3157544397492302276.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261103%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261103T043224Z&X-Amz-Expires=70441&X-Amz-Signature=a49f648117da3ae6283aec15d4a9f0a71d71d0b84cf110ede2682033299dd04d&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Wed%2C%2004%20Nov%202026%2000%3A06%3A25%20GMT\"},{\"hotel_id\":1583,\"url\":\"https://export-download.canva.com/87102055-2274-40bb-aebd-6e20b25f61e4/0/0001-6548754917309859914.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261103%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261103T075840Z&X-Amz-Expires=57403&X-Amz-Signature=c996b9612fe3ee56da8702181e3f3eabf530dcd8ec26a6e5a3c7240d3b61676e&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Tue%2C%2003%20Nov%202026%2023%3A55%3A23%20GMT\"}]")
group=int(os.environ['COVER_GROUP'])
assert group in (0,1,2) and len(rows)==6
rows=rows[group*2:group*2+2]
assert 1<=len(rows)<=2
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):raise ValueError('unexpected_asset_redirect')
opener=urllib.request.build_opener(NoRedirect())
out={'operation':'commercial-batch32-covers-20261103-read1','mode':'inspect_native_promotional_images_only','message_sends':0,'db_writes':0,'server_connections':0,'group':group,'covers':[]}
Path('covers').mkdir(exist_ok=True)
for row in rows:
 p=urllib.parse.urlsplit(row['url'])
 assert p.scheme=='https' and p.hostname=='export-download.canva.com' and p.port in (None,443) and not p.username and not p.password
 assert row['hotel_id'] in (1449,1453,9432,1451,1151,1583)
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
