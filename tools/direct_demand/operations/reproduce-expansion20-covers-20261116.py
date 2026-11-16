"""Reproduce the already-reviewed 20 covers from an authenticated retained artifact."""
from __future__ import annotations
import hashlib,io,json,pathlib,sys,zipfile
from PIL import Image,ImageDraw,ImageFont,ImageOps
PIXELS={88169:'b626885033d406050eb37e2f1b1ccfc9b4eb0b8e44dc3318fe8057160fb54e59',547:'0468d4af67ccceeadf549c393d7b8efaea5f0fb2c3f7ed37315d25882786a213',15774:'14aa193b86cd667fa38bb90308565fb5065e4220f61a66a4a8557a84974c8015',12125:'a2bdf1ea8f96634cf1c8bb2f078f8518945305740c71833d4e933ffb8ec745ef',57335:'0d9b79460d362b8931321ccfb82ecf13e5f4da8991f0cc0cc87d7e47617c5fdc',2480:'fb666c61e02096ecbcf77e605f14edd6cd1606e6910d682829d8bbf230110ed8',2417:'afd61d2e572dc2ae9fee3ee3a0badc118d33664f647773c08268948d8a00e611',2418:'f7bbb108c810420f8ea23b63eb8f4e455e39b67122a9948f49d872ef5630aeee',2468:'5a97ed0b82c9c784ae6dd90ca05ac69bd0b09e79995e154f5c655014eddc9ba3',12127:'89c0c88682c6495ab55a413ec275fc153ab19e8e79c8282c61596053326800f1',27481:'3e730946eb9febe79147e81a021aa1856ae0c92f2e821b43ee666ae30346e827',23289:'232d550d194d172a8db5a2deac3c4d4ffceb53777faa0145c62ccaf037c20d6c',50262:'cbab7a735249af4c7e744c3e21484a5fec56ef28637409a3bd76a69f3c6d7944',72939:'8dfa1cd0e3676de87667ef1c87fbf833d1b85d142675289b97ee9f9bdfc510d6',2489:'490fb99862cac9a936bb36d4fc13a45ee33d9cbbe3468ddcaa12c4da9ed9dc35',85417:'a715c1d25045725faef46dd1ac4dc8623e5a760f2e97ff363023b9907e402d1a',2466:'a5f753bff5da2274de86b9f5c3e1ca75e70fbaa084e2e0dfacf9d9e97a06b652',2460:'3b9b74c423b66f92d304a828f5316eb7d5bdc8cc166b373b7a2e759476b3edf9',99713:'e3498959976e024be23de6156a31b328d40fbc3ecb83770c5d03fb348cfa0dfd',43676:'100c2245b8d1f8ee3c90f46c51c061dba08984228ea21330aab0ab6b330b1829'}
FONT_ROOT=pathlib.Path('/usr/share/fonts/truetype/dejavu')
def sha(b):return hashlib.sha256(b).hexdigest()
def font(size,bold=False):return ImageFont.truetype(str(FONT_ROOT/('DejaVuSans-Bold.ttf' if bold else 'DejaVuSans.ttf')),size)
def wrap(draw,text,f,width):
 lines=[];line=''
 for word in text.split():
  candidate=(line+' '+word).strip()
  if line and draw.textlength(candidate,font=f)>width:lines.append(line);line=word
  else:line=candidate
 if line:lines.append(line)
 return lines

def main():
 archive,source,dest=map(pathlib.Path,sys.argv[1:]);raw=archive.read_bytes()
 assert sha(raw)=='3be24375dbb26fd5e43651e5221997cbabe563852af9a562e37614748b8c064a','media_archive_changed'
 packet=json.loads(source.read_bytes());assert [r[1] for r in packet['rows']]==list(PIXELS),'scope_changed'
 for name,digest in [('DejaVuSans.ttf','57f73e11f51999432bf7ab22ce55b6f945d5eca1bf824404cfa9ec2e3718c84e'),('DejaVuSans-Bold.ttf','a4c5bc453ca281d90ea079e596da7ae0dfeb5777497c29ec254e76d97ff6f890')]:assert sha((FONT_ROOT/name).read_bytes())==digest,'font_changed'
 dest.mkdir(parents=True,exist_ok=True);result=[]
 with zipfile.ZipFile(io.BytesIO(raw)) as z:
  m=json.loads(z.read('manifest.json'));assert m['downloaded']==120 and m['failed']==0
  by={(r['tourvisor_id'],r['source_image_index']):r for r in m['rows']};assert len(by)==120
  for work,tv,text,indices,sources in packet['rows']:
   title=text.split('\n')[0].removeprefix('✈️ Туры в ')
   if tv==27481:title='Anantara Sir Bani Yas Al Sahel';text='✈️ Туры в '+title+'\n'+text.split('\n',1)[1]
   location=text.split('\n')[1].removeprefix('📍 ').removesuffix('.')
   pics=[];photo_evidence=[]
   for index in indices:
    r=by[tv,index];b=z.read(r['relative_path']);assert r['downloaded'] and sha(b)==r['sha256'];pics.append(Image.open(io.BytesIO(b)).convert('RGB'));photo_evidence.append({'sha256':r['sha256'],'url':r['url'],'source_image_index':index})
   assert len(pics)==3
   image=Image.new('RGB',(1000,1000),'white');draw=ImageDraw.Draw(image);f=font(34,True);lines=wrap(draw,title,f,940)
   if len(lines)>2:f=font(30,True);lines=wrap(draw,title,f,940)
   assert len(lines)<=2;top=30 if len(lines)==2 else 49
   for i,line in enumerate(lines):draw.text((500,top+i*42),line,font=f,fill='#132D3C',anchor='mt')
   draw.text((500,124),location,font=font(22),fill='#52626B',anchor='mt')
   for pic,(x,y,w,h) in zip(pics,[(18,170,964,460),(18,646,474,276),(508,646,474,276)]):image.paste(ImageOps.fit(pic,(w,h),method=Image.Resampling.LANCZOS),(x,y))
   draw.text((22,949),'AnyTour',font=font(30,True),fill='#132D3C');draw.text((978,957),'Тур на ваши даты',font=font(21),fill='#52626B',anchor='rt')
   assert sha(image.tobytes())==PIXELS[tv],f'approved_pixels_changed:{tv}'
   target=dest/f'{tv}.png';assert not target.exists(),'asset_path_already_exists';image.save(target,format='PNG',optimize=True);b=target.read_bytes()
   result.append({'work_id':work,'hotel_id':tv,'text':text,'text_sha256':sha(text.encode()),'name':title,'location':location,'photo_sha256':sha(b),'photo_pixels_sha256':PIXELS[tv],'photo_bytes':len(b),'photo_file':f'{tv}.png','source_photos':photo_evidence,'sources':sources})
 (dest/'asset-manifest.json').write_text(json.dumps({'schema':'expansion20_pixel_exact_assets_v1','source_media_artifact':14218025386,'source_media_sha256':sha(raw),'hotels':20,'source_photo_downloads':0,'post_writes':0,'rows':result},ensure_ascii=False,indent=2)+'\n')
 print('APPROVED_COVERS_REPRODUCED hotels=20 pixel_equal=20 supplier_calls=0 post_writes=0')
if __name__=='__main__':main()
