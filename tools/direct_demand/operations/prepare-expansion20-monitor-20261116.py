"""Append exact already-published twenty to the existing roster. Offline only."""
from __future__ import annotations
import argparse, base64, gzip, hashlib, json, pathlib, re, zipfile
import xml.etree.ElementTree as ET
IDS=[88169,547,15774,12125,57335,2480,2417,2418,2468,12127,27481,23289,50262,72939,2489,85417,2466,2460,99713,43676]
BOUND_SHA='a809657d638cfafb122e0faaebf50e40088325ade6491890574f3c0770ae6c93'
FEED_SHA='7d1d7d468c604840193980689354fd339effc3a6d8d1a0e4622ac52f5fb9bbe5'
SLOT='💰 [Минимальная актуальная цена тура — подтвердить перед выпуском]\n[Город вылета · даты · ночи · состав туристов · номер · питание · время проверки]'
def sha(b):return hashlib.sha256(b).hexdigest()
def require(ok, why):
 if not ok:raise ValueError(why)
def compact(x):return json.dumps(x,ensure_ascii=False,separators=(',',':')).encode()
def checked_zip(path, digest):
 raw=pathlib.Path(path).read_bytes();require(sha(raw)==digest,'original_archive_changed');return zipfile.ZipFile(path)
def read_json(z,name):return json.loads(z.read(name))
def append_bytes(raw, extra):
 text=raw.decode();m=re.search(r'"rows"\s*:\s*\[',text);require(m is not None,'roster_rows_missing');pos=m.end();dec=json.JSONDecoder();ends=[]
 while True:
  while text[pos].isspace():pos+=1
  if text[pos]==']':break
  _,pos=dec.raw_decode(text,pos);ends.append(pos)
  while text[pos].isspace():pos+=1
  if text[pos]==',':pos+=1;continue
  require(text[pos]==']','roster_array_invalid');break
 require(len(ends)==608,'old_roster_not608')
 last=ends[-1];prefix=text[:last].encode();insert=',\n'+',\n'.join(json.dumps(r,ensure_ascii=False,indent=2) for r in extra)
 result=(text[:last]+insert+text[last:]).encode();require(result.startswith(prefix),'old_prefix_changed')
 return result,len(prefix),sha(prefix)
def main():
 p=argparse.ArgumentParser();p.add_argument('--root',required=True);p.add_argument('--bound',required=True);p.add_argument('--feed',required=True);p.add_argument('--out',required=True);a=p.parse_args()
 root=pathlib.Path(a.root);out=pathlib.Path(a.out);out.mkdir(parents=True,exist_ok=True)
 packed=(root/'monitor-price-expanded-roster-20261111.json.gz').read_bytes();raw=gzip.decompress(packed);old=json.loads(raw);require(len(old['rows'])==608,'old_roster_count_changed')
 seen={r['tourvisor_hotel_id'] for r in old['rows']};require(not seen.intersection(IDS),'new_hotels_already_present')
 rows=[];evidence=[]
 with checked_zip(a.bound,BOUND_SHA) as b,checked_zip(a.feed,FEED_SHA) as f:
  feed_names=['hotel-posts-telegram.xml','hotel-posts-max.xml','hotel-posts-all.xml']
  xml={}
  hashes={'hotel-posts-telegram.xml':'16b25de3b0c85c64b195443b2c22ad6306b3cbd57959fd42681b6e257626ebd4','hotel-posts-max.xml':'4c0b2367eb18b4da05f36834d17897977b1bbec32dbdf17ad5f923ed0fe08920','hotel-posts-all.xml':'66e65ca20729ffe322be841d5f6b9f22b4108b036c7a6bf5e3cfcedfb573d0f1'}
  for name,digest in hashes.items():
   matches=[f.read(n) for n in f.namelist() if n.endswith('/'+name) and sha(f.read(n))==digest]
   require(bool(matches),'accepted_final_feed_bytes_missing');xml[name]=matches[0]
  offers={name:{x.attrib['id']:x for x in ET.fromstring(value).findall('./shop/offers/offer')} for name,value in xml.items()}
  require([len(offers[n]) for n in feed_names]==[628,628,1256],'final_feed_count_changed')
  for group in (20,21):
   manifest_raw=b.read(f'{group}/manifest.json');m=json.loads(manifest_raw);approval=read_json(b,f'{group}/approval.json');s=read_json(b,f'{group}/publication.json');read=read_json(b,f'{group}/bound-read.json')
   require(approval['manifest_sha256']==sha(manifest_raw),'original_manifest_changed')
   require(m['operation']==s['operation']==approval['operation']==f'commercial-hotel-batch-20261116-{group}-v1','original_operation_changed')
   require(s['ok'] and s['new_posts']==len(s['posts'])==20 and s['reused']==0,'original_publication_unconfirmed')
   require(s['installed_sha']==approval['expected_installed_sha']=='d5091653179ac0460ed0129ae32cc52495abfbdd','original_publication_source_changed')
   require(read['ok'] and read['scope_hotels']==read['feed_rows']==read['private_binding_count']==10 and read['deferred_count']==0,'current_binding_read_invalid')
   require(read['installed_sha']=='729bd1cf40567b2072f2471af283954cd9989cd1','current_binding_read_source_changed')
   for original,r,binding in zip(m['rows'],read['rows'],read['private_max_bindings']):
    tv=original['hotel_id'];require(tv==r['hotel_id']==binding['hotel_id'],'native_identity_changed')
    posts={x['platform']:x for x in s['posts'] if x['hotel_id']==tv};require(set(posts)=={'max','telegram'},'pair_missing')
    require(original['text_sha256']==sha(original['text'].encode()),'original_text_changed')
    require('💰' not in original['text'] and original['text'].endswith('Подберём тур на ваши даты.'),'new_caption_scope_changed')
    destinations={};ids={};urls={}
    for channel in ('max','telegram'):
     receipt=posts[channel];url=r[channel+'_post_url'];oid=('max_' if channel=='max' else 'tg_')+str(tv);name='hotel-posts-'+channel+'.xml'
     require(receipt['state']=='sent' and receipt['body_photo_button_verified'] and receipt['reused'] is False,'original_receipt_unconfirmed')
     require(receipt['post_url']==url and receipt['logical_id']==original['logical_id'] and receipt['text_sha256']==original['text_sha256'] and receipt['photo_sha256']==original['photo_sha256'],'original_receipt_changed')
     element=offers[name][oid];combined=offers['hotel-posts-all.xml'][oid]
     require(element.attrib==combined.attrib and [(x.tag,x.attrib,x.text) for x in element]==[(x.tag,x.attrib,x.text) for x in combined],'feed_pair_disagrees')
     require(element.find('price') is None and element.findtext('picture')==original['photo_url'],'new_feed_unpriced_photo_changed')
     destination=element.findtext('url');require(destination==combined.findtext('url'),'feed_destination_changed')
     if channel=='telegram':require(destination==url,'telegram_feed_destination_changed')
     else:
      token=re.search(r'_ctx1_([A-Za-z0-9_-]+)$',destination);require(token is not None,'max_route_context_missing')
      encoded=token.group(1);context=json.loads(base64.urlsafe_b64decode(encoded+'='*((4-len(encoded)%4)%4)))
      require(destination.startswith('https://max.ru/id9704048781_2_bot?startapp=') and binding['post_url']==url and context=={'post_id':binding['public_route_alias'],'offer_id':oid} and binding['public_offer_id']==oid,'max_route_destination_changed')
     destinations[channel]=destination;ids[channel]=oid;urls[channel]=url
    template=original['text']+'\n\n'+SLOT+'\n\nУзнать стоимость тура'
    row={'key':original['logical_id'],'hotel_id':tv,'tourvisor_hotel_id':tv,'logical_id':original['logical_id'],'original_publication_operation':m['operation'],'original_text_sha256':original['text_sha256'],'cover_sha256':original['photo_sha256'],'posts':urls,'expected_text_sha256':{c:original['text_sha256'] for c in ('max','telegram')},'templates':{c:template for c in ('max','telegram')},'feed_binding':{'tourvisor_hotel_id':tv,'offer_ids':ids,'posts':urls,'destinations':destinations}}
    rows.append(row);evidence.append({'tourvisor_hotel_id':tv,'country_id':r['country_id'],'region_id':r['region_id'],'name':r['name'],'operation':m['operation'],'original_text':original['text'],'text_sha256':original['text_sha256'],'photo_sha256':original['photo_sha256'],'publication_summary_sha256':sha(b.read(f'{group}/publication.json')),'bound_read_sha256':sha(b.read(f'{group}/bound-read.json'))})
 require([r['tourvisor_hotel_id'] for r in rows]==IDS,'new_scope_order_changed')
 updated,prefix_length,prefix_sha=append_bytes(raw,rows);data=json.loads(updated)
 require(data['rows'][:608]==old['rows'] and data['rows'][608:]==rows and len(data['rows'])==628,'old_rows_changed')
 require({k:v for k,v in data.items() if k!='rows'}=={k:v for k,v in old.items() if k!='rows'},'old_metadata_changed')
 require(len({r['tourvisor_hotel_id'] for r in data['rows']})==628,'duplicate_native_id')
 require(len({r['posts'][c] for r in data['rows'] for c in ('max','telegram')})==1256,'duplicate_post')
 result=gzip.compress(updated,compresslevel=9,mtime=0);(out/'monitor-price-expanded-roster-20261111.json.gz').write_bytes(result)
 proof={'schema_version':1,'kind':'published20_monitor_roster_admission','previous_count':608,'next_count':628,'new_tourvisor_ids':IDS,'old_gzip_sha256':sha(packed),'old_decompressed_sha256':sha(raw),'old_rows_prefix_bytes':prefix_length,'old_rows_prefix_sha256':prefix_sha,'new_gzip_sha256':sha(result),'new_decompressed_sha256':sha(updated),'sources':{'bound_run':43871777544,'bound_artifact':14228391968,'bound_zip_sha256':BOUND_SHA,'feed_run':43874344520,'feed_artifact':14229960161,'feed_zip_sha256':FEED_SHA},'rows':evidence,'new_prices_added':0,'post_edits':0,'supplier_calls':0}
 (out/'monitor-price-expansion20-proof-20261116.json').write_bytes(compact(proof)+b'\n')
 print(json.dumps({k:v for k,v in proof.items() if k not in ('rows','sources')},ensure_ascii=False))
if __name__=='__main__':main()
