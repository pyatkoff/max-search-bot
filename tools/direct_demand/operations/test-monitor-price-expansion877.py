"""Portable offline PRICE877 source contract. stdlib only; no network or runtime writes."""
import base64,gzip,hashlib,json,pathlib,re
from monitor_price_expansion1000_prefix import accepted877
ROOT=pathlib.Path(__file__).resolve().parent
PROOF_SHA='a650e2821a6394038c9585e902c331d1ca117f31619cbac34a3a6926e4034b47'
BASE660_SHA='406293597fa7ddeb7d3c58dd08d2c44ad43345923a0385f9eaea33798ad2d0db'
CONTRACT_SHA='d75989788c046fe3c0aab18b01a5d1edf07a7872050a37a17f49a6df6c29de14'
sha=lambda b:hashlib.sha256(b).hexdigest()
canonical=lambda x:json.dumps(x,ensure_ascii=False,sort_keys=True,separators=(',',':')).encode()
checks=0
def need(value,reason):
 global checks
 checks+=1
 if not value:raise AssertionError(reason)
def verify(root=ROOT):
 raw=(root/'monitor-price-expansion877-proof-20261118.json').read_bytes();need(sha(raw)==PROOF_SHA,'immutable_proof_changed');proof=json.loads(raw)
 need(proof['base_source_commit']=='4fde5c5a8fedcd17882589628a1f466c9e629d4e','base_source')
 packed=accepted877((root/'monitor-price-expanded-roster-20261111.json.gz').read_bytes(),root);need(sha(packed)==proof['roster_gzip_sha256'],'packed_roster_changed')
 roster=json.loads(gzip.decompress(packed));rows=roster['rows'];need(len(rows)==877,'total877');need(len({r['tourvisor_hotel_id'] for r in rows})==877,'unique_source_ids')
 need(sha(canonical(rows[:660]))==BASE660_SHA==proof['previous660_canonical_sha256'],'accepted660_changed')
 need(sha(canonical(rows[660:]))==proof['append217_canonical_sha256'],'append217_changed')
 need(sha((root/'monitor-price-hook-contract.php').read_bytes())==CONTRACT_SHA==proof['contract_sha256'],'4fde_contract_changed')
 need(len(proof['append217'])==217,'proof_scope');need([r['tourvisor_hotel_id'] for r in rows[660:]]==[r['tourvisor_hotel_id'] for r in proof['append217']],'exact217_order')
 artifacts={x['operation']:x for x in proof['original_artifacts']};need(len(artifacts)==24,'original24operations')
 for artifact in artifacts.values():
  need(type(artifact['artifact_id']) is int and artifact['artifact_id']>0,'artifact_identity')
  need(all(re.fullmatch('[0-9a-f]{64}',artifact[k]) for k in ['archive_sha256','manifest_sha256']),'artifact_hashes')
 slot='💰 [Минимальная актуальная цена тура — подтвердить перед выпуском]';terms='[Город вылета · даты · ночи · состав туристов · номер · питание · время проверки]'
 suffix='\n\n'+slot+'\n'+terms+'\n\nУзнать стоимость тура'
 for row,expected in zip(rows[660:],proof['append217']):
  need(sha(canonical(row))==expected['row_canonical_sha256'],'exact_row_binding_template')
  hid=row['tourvisor_hotel_id'];need(row['hotel_id']==hid and row['logical_id']==row['key']==expected['logical_id'],'identity')
  need(row['original_publication_operation']==expected['original_publication_operation'] and row['original_publication_operation'] in artifacts,'original_source_operation')
  need(row['templates']['max']==row['templates']['telegram'],'paired_template')
  text=row['templates']['max'];need(text.endswith(suffix) and text.count(slot)==text.count(terms)==1,'one_price_slot')
  original=text[:-len(suffix)]+'\n\nПодберём тур на ваши даты. Нажмите кнопку под постом.'
  need(sha(original.encode())==row['original_text_sha256'],'template_preserves_original_body')
  binding=row['feed_binding'];need(binding['tourvisor_hotel_id']==hid and binding['posts']==row['posts'],'feed_identity')
  for ch,prefix in [('max','max_'),('telegram','tg_')]:
   need(binding['offer_ids'][ch]==prefix+str(hid),'offer_identity')
   need(row['expected_text_sha256'][ch]==row['original_text_sha256'],'original_caption_baseline')
  need(binding['destinations']['telegram']==row['posts']['telegram'],'tg_direct_post')
  dest=binding['destinations']['max'];need('_ctx1_' in dest,'typed_max_destination')
  ctx=json.loads(base64.urlsafe_b64decode(dest.split('_ctx1_')[1]+'==='));need(ctx['offer_id']=='max_'+str(hid) and re.fullmatch('h1_[0-9a-f]{32}',ctx['post_id']),'typed_max_identity')
 need(proof['state']=='SOURCE_EXPANSION_PROOF_NOT_RUNTIME_PRICE_ADMISSION','no_runtime_authorization')
 return checks
if __name__=='__main__':print('PRICE877_SOURCE_OK checks='+str(verify())+' network=0 production_writes=0')
