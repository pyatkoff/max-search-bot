"""Portable PRICE1000 source-only proof. Stdlib, no network/native writes."""
import pathlib,json,gzip,hashlib,base64,copy,re
from monitor_price_expansion1000_prefix import accepted877,accepted1000,PROOF_SHA
ROOT=pathlib.Path(__file__).resolve().parent
sha=lambda b:hashlib.sha256(b).hexdigest()
canonical=lambda x:json.dumps(x,ensure_ascii=False,sort_keys=True,separators=(',',':')).encode()
checks=0
def need(v,why):
 global checks
 checks+=1
 if not v:raise AssertionError(why)
def validate_rows(rows,p):
 need(len(rows)==1000 and len({r['tourvisor_hotel_id'] for r in rows})==1000,'exact1000')
 need(sha(canonical(rows[:877]))==p['previous877_canonical_sha256'],'old877_changed')
 need([r['tourvisor_hotel_id'] for r in rows[877:]]==[r['tourvisor_hotel_id'] for r in p['append123']],'append_order_or_scope')
 need(sha(canonical(rows[877:]))==p['append123_canonical_sha256'],'append_exact_changed')
 for row,expected in zip(rows[877:],p['append123']):
  need(sha(canonical(row))==expected['row_canonical_sha256'],'row_changed')
  tv=row['tourvisor_hotel_id'];need(row['hotel_id']==tv and row['key']==row['logical_id']==expected['logical_id'],'identity')
  op=row['original_publication_operation'];need(op==expected['original_publication_operation'],'original_operation')
  need(row['templates']['max']==row['templates']['telegram'],'paired_template')
  text=row['templates']['max'];suffix='\n\n💰 [Минимальная актуальная цена тура — подтвердить перед выпуском]\n[Город вылета · даты · ночи · состав туристов · номер · питание · время проверки]\n\nУзнать стоимость тура'
  need(text.endswith(suffix),'price_slot');original=text[:-len(suffix)]+'\n\nПодберём тур на ваши даты. Нажмите кнопку под постом.'
  need(sha(original.encode())==row['original_text_sha256'],'preserved_original_caption')
  bind=row['feed_binding'];need(bind['tourvisor_hotel_id']==tv and bind['posts']==row['posts'],'binding_identity')
  for c,prefix in [('max','max_'),('telegram','tg_')]:
   need(row['expected_text_sha256'][c]==row['original_text_sha256']==expected['publications'][c]['text_sha256'],'caption_baseline')
   need(row['posts'][c]==expected['publications'][c]['post_url'],'original_post')
   need(expected['original_approved_paths'][c]==op+'/'+c+'-'+row['logical_id']+'-approved.json','approved_path')
   need(bind['offer_ids'][c]==prefix+str(tv),'offer_identity')
  need(bind['destinations']['telegram']==row['posts']['telegram'],'telegram_destination')
  ctx=json.loads(base64.urlsafe_b64decode(bind['destinations']['max'].split('_ctx1_')[1]+'==='));need(ctx['offer_id']==bind['offer_ids']['max'] and re.fullmatch('h1_[a-f0-9]{32}',ctx['post_id']),'max_context')
 return True
def verify(root=ROOT):
 raw=(root/'monitor-price-expansion1000-proof-20261118.json').read_bytes();need(sha(raw)==PROOF_SHA,'immutable_proof');p=json.loads(raw)
 packed=(root/'monitor-price-expanded-roster-20261111.json.gz').read_bytes();old=accepted877(packed,root)
 # The current roster passes its exact correction/999-row checks before the
 # unchanged expansion assertions consume the byte-exact historical source.
 rows=json.loads(accepted1000(packed,root))['rows'];validate_rows(rows,p)
 need(json.loads(gzip.decompress(old))['rows']==rows[:877],'exact_prefix')
 need(sha((root/'monitor-price-hook-contract.php').read_bytes())=='d75989788c046fe3c0aab18b01a5d1edf07a7872050a37a17f49a6df6c29de14','unchanged_history_preflight_contract')
 need(p['state']=='SOURCE_EXPANSION_PROOF_NOT_RUNTIME_PRICE_ADMISSION','no_runtime_admission')
 for mutation in ['remove','extra','duplicate','swap','old','text','post','operation','context']:
  bad=copy.deepcopy(rows)
  if mutation=='remove':bad.pop()
  elif mutation=='extra':bad.append(copy.deepcopy(bad[-1]))
  elif mutation=='duplicate':bad[-1]['tourvisor_hotel_id']=bad[-2]['tourvisor_hotel_id']
  elif mutation=='swap':bad[-1],bad[-2]=bad[-2],bad[-1]
  elif mutation=='old':bad[0]['hotel_id']=-1
  elif mutation=='text':bad[-1]['templates']['max']+='changed'
  elif mutation=='post':bad[-1]['posts']['telegram']='https://t.me/other/1'
  elif mutation=='operation':bad[-1]['original_publication_operation']='commercial-hotel-batch-20261117-27-v1'
  else:bad[-1]['feed_binding']['destinations']['max']='https://example.com/'
  try:validate_rows(bad,p)
  except AssertionError:continue
  raise AssertionError('negative accepted '+mutation)
 return checks
if __name__=='__main__':print('PRICE1000_SOURCE_OK checks='+str(verify())+' network=0 production_writes=0')
