"""Portable historical gzip restoration regressions. No network/runtime access."""
import gzip,hashlib,json,pathlib,tempfile,unittest
from unittest.mock import patch
from monitor_price_expansion1000_prefix import accepted877
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
if __name__=='__main__':unittest.main(verbosity=2)
