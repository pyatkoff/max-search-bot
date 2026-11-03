import base64,hashlib,json,os,struct,urllib.parse,urllib.request
from pathlib import Path
rows=json.loads("[{\"hotel_id\":249,\"url\":\"https://export-download.canva.com/6281edb9-af08-43b4-8295-e8b77a7b1a0d/0/0001-6073625100678178511.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261103%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261103T012457Z&X-Amz-Expires=35062&X-Amz-Signature=0c4b95517b9f2b5ba71bdd2a16b950ed619394c7d8b6d5a1dea48469413cad11&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Tue%2C%2003%20Nov%202026%2011%3A09%3A19%20GMT\"},{\"hotel_id\":315,\"url\":\"https://export-download.canva.com/754a4c91-ba26-487f-870a-993bdd69850a/0/0001-613010552056369101.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261102%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261102T220014Z&X-Amz-Expires=46825&X-Amz-Signature=ebaf1d7091bd12a10d370958220aeb753175f3aca4369ab165adf7415176a014&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Tue%2C%2003%20Nov%202026%2011%3A00%3A39%20GMT\"},{\"hotel_id\":382,\"url\":\"https://export-download.canva.com/b713ab35-67e7-41d1-88b0-3aa1e25218c5/0/0001-1005949621999372854.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261102%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261102T183110Z&X-Amz-Expires=60767&X-Amz-Signature=b672ae5dddc05db98f4b56dca2e45ccd5e8791aa29a63c15a6242b1238074194&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Tue%2C%2003%20Nov%202026%2011%3A23%3A57%20GMT\"},{\"hotel_id\":465,\"url\":\"https://export-download.canva.com/c6cf38dd-045f-4bba-8311-9e90b417049e/0/0001-7197273209069228604.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261102%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261102T222801Z&X-Amz-Expires=45982&X-Amz-Signature=127c82d41d7de9ed2abd5a936a623a0f574d9d4e0af62f1e5f7a87a19b83f3f5&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Tue%2C%2003%20Nov%202026%2011%3A14%3A23%20GMT\"},{\"hotel_id\":272,\"url\":\"https://export-download.canva.com/54cd56c5-3894-4fc5-beb4-f7aaf8d909e1/0/0001-5192045472892025007.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261103%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261103T090850Z&X-Amz-Expires=8544&X-Amz-Signature=195357b172e5023305f63e4918c8eef5c7087a8648d7d77e67fe4f195393a0ef&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Tue%2C%2003%20Nov%202026%2011%3A31%3A14%20GMT\"},{\"hotel_id\":260,\"url\":\"https://export-download.canva.com/c98f5f36-629e-4264-830c-14a71e6352fc/0/0001-5512926947163129110.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261103%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261103T031616Z&X-Amz-Expires=26641&X-Amz-Signature=17afc50ec7777f8b3983fece277cd09152450575eaaa1779d7b3ba33b5c5c735&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Tue%2C%2003%20Nov%202026%2010%3A40%3A17%20GMT\"},{\"hotel_id\":273,\"url\":\"https://export-download.canva.com/329ec53d-3aa9-4550-ae0a-8be2de4c72d9/0/0001-2476374896919239722.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261103%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261103T033556Z&X-Amz-Expires=27069&X-Amz-Signature=7570502f4ea993e4b65586fa9cf18cb3d08b0d2de050ae10813ffdc442443a3b&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Tue%2C%2003%20Nov%202026%2011%3A07%3A05%20GMT\"}]")
group=int(os.environ['COVER_GROUP'])
assert group in (0,1,2) and len(rows)==7
rows=rows[group*3:group*3+3]
assert 1<=len(rows)<=3
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):raise ValueError('unexpected_asset_redirect')
opener=urllib.request.build_opener(NoRedirect())
out={'operation':'commercial-batch19-covers-20261103-read1','mode':'inspect_native_promotional_images_only','message_sends':0,'db_writes':0,'server_connections':0,'group':group,'covers':[]}
Path('covers').mkdir(exist_ok=True)
for row in rows:
 p=urllib.parse.urlsplit(row['url'])
 assert p.scheme=='https' and p.hostname=='export-download.canva.com' and p.port in (None,443) and not p.username and not p.password
 assert row['hotel_id'] in (249,315,382,465,272,260,273)
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
