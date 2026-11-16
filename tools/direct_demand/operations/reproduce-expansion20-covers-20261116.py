"""Render the same 20 photo selections; actual font hashes and output require fresh visual review."""
from __future__ import annotations
import hashlib,io,json,pathlib,sys,zipfile
from PIL import Image,ImageDraw,ImageFont,ImageOps
IDS=[88169,547,15774,12125,57335,2480,2417,2418,2468,12127,27481,23289,50262,72939,2489,85417,2466,2460,99713,43676]
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
 packet=json.loads(source.read_bytes());assert [r[1] for r in packet['rows']]==IDS,'scope_changed'
 font_hashes={name:sha((FONT_ROOT/name).read_bytes()) for name in ['DejaVuSans.ttf','DejaVuSans-Bold.ttf']}
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
   assert image.size==(1000,1000)
   pixel_hash=sha(image.tobytes())
   target=dest/f'{tv}.png';assert not target.exists(),'asset_path_already_exists';image.save(target,format='PNG',optimize=True);b=target.read_bytes()
   result.append({'work_id':work,'hotel_id':tv,'text':text,'text_sha256':sha(text.encode()),'name':title,'location':location,'photo_sha256':sha(b),'photo_pixels_sha256':pixel_hash,'photo_bytes':len(b),'photo_file':f'{tv}.png','source_photos':photo_evidence,'sources':sources})
 (dest/'asset-manifest.json').write_text(json.dumps({'schema':'expansion20_same_source_assets_v2','font_hashes':font_hashes,'visual_acceptance_pending':True,'source_media_artifact':14218025386,'source_media_sha256':sha(raw),'hotels':20,'source_photo_downloads':0,'post_writes':0,'rows':result},ensure_ascii=False,indent=2)+'\n')
 print('SAME_SOURCE_COVERS_RENDERED hotels=20 fresh_visual_review_required=1 supplier_calls=0 post_writes=0')
if __name__=='__main__':main()
