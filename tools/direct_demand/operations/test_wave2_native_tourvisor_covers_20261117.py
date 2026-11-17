import importlib.util
from pathlib import Path
import io
import json
import tempfile
import unittest

from PIL import Image

SRC = Path(__file__).parent / 'prepare-wave2-native-tourvisor-covers-20261117.py'
spec = importlib.util.spec_from_file_location('coverprep', SRC)
coverprep = importlib.util.module_from_spec(spec)
spec.loader.exec_module(coverprep)


class TestCoverPreparer(unittest.TestCase):
    def test_tourvisor_slug_photos_and_security(self):
        self.assertEqual(coverprep.check_photo_url('https://static.tourvisor.ru/hotel_pics/verybig/8/soneva-jani-resort-102407365.jpg'), 8)
        self.assertEqual(coverprep.check_photo_url('https://static.tourvisor.ru/hotel_pics/verybig/8/12120-30165207.jpg'), 8)
        for bad in ['http://static.tourvisor.ru/hotel_pics/verybig/8/x.jpg', 'https://static.tourvisor.ru.evil.com/hotel_pics/verybig/8/x.jpg', 'https://static.tourvisor.ru/hotel_pics/verybig/8/../x.jpg', 'https://static.tourvisor.ru/hotel_pics/verybig/8/x.jpg?token=1']:
            with self.assertRaises(ValueError):
                coverprep.check_photo_url(bad)

    def test_cover_and_held_rows_without_provider(self):
        base = 'https://static.tourvisor.ru/hotel_pics/verybig/8/12120-30165{}.jpg'
        urls = [base.format('001'), base.format('002'), base.format('003'), base.format('004')]
        row = {'hotel_id': 12120, 'anytour_canonical_id': None, 'provider_verified_native_tourvisor_id': 12120, 'provider_verified_country_id': 8, 'canonical_cross_namespace_unverified': True, 'hotel': 'Anantara Kihavah Maldives Villas', 'work_id': 'EXP002', 'commercial_send_allowed': False, 'photo_urls': urls}
        manifest = {'state': 'EDITORIAL_AND_TOURVISOR_PHOTO_SOURCES_REVIEWABLE_NOT_SENT', 'rows': [row]}
        logo = Image.new('RGBA', (200, 60), (255, 255, 255, 180))
        def source(url):
            i = urls.index(url)
            return Image.effect_noise((1200, 800), 20 + i).convert('RGB'), {'source_url':url, 'bytes':12345, 'sha256':str(i)*64}
        with tempfile.TemporaryDirectory() as d:
            rec = coverprep.build(manifest, logo, d, fetch=source)
            self.assertEqual(rec['prepared'], 1)
            self.assertEqual(rec['actual_posts'], 0)
            self.assertFalse(rec['rows'][0]['publication_allowed'])
            self.assertTrue(rec['rows'][0]['cross_namespace_held'])
            self.assertIsNone(rec['rows'][0]['anytour_canonical_id'])
            p = Path(d)/'12120.png'
            with Image.open(p) as cover:
                self.assertEqual(cover.size, (1080, 1080))
                self.assertEqual(cover.convert('RGB').getpixel((3, 1000)), (39, 67, 203))
            rec2 = coverprep.build(manifest, logo, d, fetch=source)
            self.assertEqual(rec2['prepared'], 0)
            self.assertIn('no_overwrite', rec2['rows'][0]['reason'])

    def test_no_false_approval_and_country_mismatch(self):
        row = {'hotel_id': 12120, 'anytour_canonical_id': None, 'provider_verified_native_tourvisor_id': 12120, 'provider_verified_country_id': 8, 'canonical_cross_namespace_unverified': True, 'hotel': 'Anantara Kihavah Maldives Villas', 'work_id': 'EXP002', 'commercial_send_allowed': True,
               'photo_urls': ['https://static.tourvisor.ru/hotel_pics/verybig/8/x%d.jpg'%i for i in range(3)]}
        with self.assertRaises(ValueError):
            coverprep.validate_rows({'state':'EDITORIAL_AND_TOURVISOR_PHOTO_SOURCES_REVIEWABLE_NOT_SENT','rows':[row]})
        row['commercial_send_allowed'] = False
        row['photo_urls'][-1] = 'https://static.tourvisor.ru/hotel_pics/verybig/9/x2.jpg'
        with self.assertRaises(ValueError):
            coverprep.validate_rows({'state':'EDITORIAL_AND_TOURVISOR_PHOTO_SOURCES_REVIEWABLE_NOT_SENT','rows':[row]})


if __name__ == '__main__':
    unittest.main()
