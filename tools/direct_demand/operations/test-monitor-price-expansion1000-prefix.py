"""Portable historical gzip restoration regressions. No network/runtime access."""
import copy,gzip,hashlib,json,pathlib,tempfile,unittest
from unittest.mock import patch
from monitor_price_expansion1000_prefix import accepted877,accepted1000,_restore_waldorf66198
ROOT=pathlib.Path(__file__).resolve().parent
class PrefixTests(unittest.TestCase):
 def setUp(self):
  self.current=(ROOT/'monitor-price-expanded-roster-20261111.json.gz').read_bytes()
  self.historical=(ROOT/'monitor-price-expansion1000-baseline877.json.gz').read_bytes()
 def test_exact_historical_bytes_without_any_compression(self):
  with patch.object(gzip,'compress',side_effect=AssertionError('must_not_recompress')):
   self.assertEqual(accepted877(self.current,ROOT),self.historical)
 def test_portable_os_header_change_was_real_hash_difference(self):
  alt=self.historical[:9]+bytes([255])+self.historical[10:]
  self.assertNotEqual(hashlib.sha256(alt).digest(),hashlib.sha256(self.historical).digest())
  self.assertEqual(gzip.decompress(alt),gzip.decompress(self.historical))
  with tempfile.TemporaryDirectory() as d:
   root=pathlib.Path(d)
   for name in ['monitor-price-expansion1000-proof-20261118.json']:
    (root/name).write_bytes((ROOT/name).read_bytes())
   (root/'monitor-price-expansion1000-baseline877.json.gz').write_bytes(alt)
   with self.assertRaisesRegex(AssertionError,'baseline877_packed_changed'):accepted877(self.current,root)
 def test_current_packed_hash_not_weakened(self):
  with self.assertRaisesRegex(AssertionError,'expansion1000_packed_changed'):accepted877(self.current+b'corrupt',ROOT)
 def test_proof_hash_not_weakened(self):
  with tempfile.TemporaryDirectory() as d:
   root=pathlib.Path(d);(root/'monitor-price-expansion1000-proof-20261118.json').write_bytes(b'{}')
   with self.assertRaisesRegex(AssertionError,'expansion1000_proof_changed'):accepted877(self.current,root)
 def test_original_raw_and_packed_pins_remain_exact(self):
  proof=json.loads((ROOT/'monitor-price-expansion1000-proof-20261118.json').read_bytes())
  actual=accepted877(self.current,ROOT)
  self.assertEqual(hashlib.sha256(actual).hexdigest(),proof['baseline_roster_gzip_sha256'])
  self.assertEqual(hashlib.sha256(gzip.decompress(actual)).hexdigest(),proof['baseline_roster_raw_sha256'])
 def test_exact_completed_correction_and_999_unchanged_rows(self):
  self.assertEqual(hashlib.sha256(self.current).hexdigest(),'b671558af880a7ab186364c35e3750ee1ffdd500931d6f868d1f0f142a550abf')
  before=json.loads(accepted1000(self.current,ROOT));after=json.loads(gzip.decompress(self.current))
  changed=[i for i,(old,new) in enumerate(zip(before['rows'],after['rows'])) if old!=new]
  self.assertEqual(changed,[663]);self.assertEqual(len(after['rows']),1000)
  old=before['rows'][663];new=after['rows'][663]
  self.assertEqual(set(new)-set(old),{'caption_correction_baseline'})
  self.assertEqual({key for key in old if old[key]!=new[key]},{'templates','expected_text_sha256'})
  caption='bf5978467d0dab5e19bf072a7478381ff88a0803b8b6718d8769ae9126237676'
  operation='commercial-waldorf66198-caption-correction-20261123-v1'
  for channel,publication,version in [('max',1510,14281),('telegram',1500,14280)]:
   self.assertEqual(new['templates'][channel],old['templates'][channel].replace('Одиннадцать ресторанов','Десять ресторанов, баров и лаунжей'))
   self.assertEqual(new['expected_text_sha256'][channel],caption)
   self.assertEqual(new['caption_correction_baseline'][channel],{'publication_id':publication,'version_id':version,'text_sha256':caption,'operation':operation})
  self.assertEqual({k:v for k,v in before.items() if k!='rows'},{k:v for k,v in after.items() if k!='rows'})
 def test_exact_correction_rejects_unapproved_deltas(self):
  proof=json.loads((ROOT/'monitor-price-expansion1000-proof-20261118.json').read_bytes())
  full=gzip.decompress(self.current);historical=gzip.decompress(self.historical)
  self.assertEqual(hashlib.sha256(_restore_waldorf66198(full,historical,proof)).hexdigest(),'ee0b1019a09c07cea2413723e972d045244707be39a38f573fd86f1a4c879b6f')
  for mutation in ['template','expected_template_hash','publication','version','caption','operation','baseline_extra','platform_missing','cover','post','feed','original_caption','other876','append123','metadata','remove','duplicate']:
   with self.subTest(mutation=mutation):
    data=json.loads(full);row=data['rows'][663];reason='waldorf66198_row_changed'
    if mutation=='template':row['templates']['max']+='changed'
    elif mutation=='expected_template_hash':row['expected_text_sha256']['max']=hashlib.sha256(row['templates']['max'].encode()).hexdigest()
    elif mutation=='publication':row['caption_correction_baseline']['max']['publication_id']=1500
    elif mutation=='version':row['caption_correction_baseline']['telegram']['version_id']=13436
    elif mutation=='caption':row['caption_correction_baseline']['max']['text_sha256']='0'*64
    elif mutation=='operation':row['caption_correction_baseline']['max']['operation']='another-operation'
    elif mutation=='baseline_extra':row['caption_correction_baseline']['max']['extra']=True
    elif mutation=='platform_missing':del row['caption_correction_baseline']['telegram']
    elif mutation=='cover':row['cover_sha256']='0'*64
    elif mutation=='post':row['posts']['telegram']='https://t.me/other/1'
    elif mutation=='feed':row['feed_binding']['destinations']['max']='https://example.com/'
    elif mutation=='original_caption':row['original_text_sha256']='0'*64
    elif mutation=='other876':data['rows'][0]['hotel_id']=-1;reason='other876_changed'
    elif mutation=='append123':data['rows'][-1]['hotel_id']=-1;reason='append123_changed'
    elif mutation=='metadata':data['registry_version']=-1;reason='original1000_raw_changed'
    elif mutation=='remove':data['rows'].pop();reason='waldorf66198_scope_changed'
    elif mutation=='duplicate':data['rows'][-1]=copy.deepcopy(data['rows'][-2]);reason='waldorf66198_scope_changed'
    changed=(json.dumps(data,ensure_ascii=False,indent=2)+'\n').encode()
    if mutation=='metadata':
     self.assertEqual(full.count(b'"registry_version": 220'),1)
     changed=full.replace(b'"registry_version": 220',b'"registry_version": -1',1)
    with self.assertRaisesRegex(AssertionError,reason):
     _restore_waldorf66198(changed,historical,proof)
if __name__=='__main__':unittest.main(verbosity=2)
