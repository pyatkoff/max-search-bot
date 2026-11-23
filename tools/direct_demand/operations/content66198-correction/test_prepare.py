"""Offline behavior checks. Temporary fixtures are never native execution evidence."""
import argparse
import base64
import copy
import datetime
import importlib.util
import json
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('waldorf_prepare', ROOT / 'prepare.py')
p = importlib.util.module_from_spec(spec)
spec.loader.exec_module(p)
OPS = ROOT.parent
PHP = 'php'


def fixture(correct=False):
    now = datetime.datetime.now(datetime.timezone.utc).replace(microsecond=0).isoformat()
    pin = dict(sha256='b' * 64, bytes=681574400, lines=9000, collector_run_id='45000000001', cycle_state='completed')
    meta = dict(operation=p.META, ok=True, installed_sha=p.APP, post_write_attempts=0,
        provider_calls=0, db_writes=0, monitor_pending=False, observed_at_utc=now, journal_pin=pin)
    phrase = p.NEW if correct else p.OLD
    heading = '✈️ Туры в Waldorf Astoria Maldives Ithaafushi'
    price = '💰 От 2 100 000 ₽ за двоих\n✈️ Вылет из Москвы 01.12.2026\n👥 2 взрослых\n🛏 Вилла с бассейном\n🍽 Завтрак\nЦена найдена мониторингом 23.11.2026, 08:12 МСК. Стоимость и наличие могут измениться.'
    text = heading + '\n📍 Итаафуши, Мальдивы.\n\n🐠 У островного рифа.\n🍽 ' + phrase + ' и красивые площадки для ужинов у океана.\n\n' + price + '\n\nУзнать стоимость тура'
    record = 'a' * 64
    posts = []
    for platform, pid, version in [('telegram', 901, 12001), ('max', 902, 12002)]:
        pos = 'offset' if platform == 'telegram' else 'from'
        bold, italic = ('bold', 'italic') if platform == 'telegram' else ('strong', 'emphasized')
        markup = [dict(type=bold, **{pos: 0}, length=p.units(heading)),
            dict(type=italic, **{pos: p.units(text[:text.index(phrase)])}, length=p.units(phrase)),
            dict(type=bold, **{pos: p.units(text[:text.index(price)])}, length=p.units(price.split('\n')[0]))]
        posts.append(dict(hotel_id=66198, platform=platform, post_url=p.URLS[platform], status='sent',
            publication_id=pid, version_id=version, message_id='678' if platform == 'telegram' else 'mid.fixture.66198',
            caption=text, caption_sha256=p.sha(text.encode()), provider_live_verified=platform == 'max',
            snapshot_provenance='registry_and_live_max_get' if platform == 'max' else 'canonical_registry_latest_version',
            monitor_price_record_id=record, prior_monitor_receipt=None, caption_correction_operation=None,
            media_count=1, media_types=['image'], photo_signature=platform + ':fixture_photo_66198',
            attachments_sha256='c' * 64, config_version=7,
            source_key='tg_anytour_msk' if platform == 'telegram' else 'max_anytour_msk1',
            button_url_sha256='d' * 64, button_observed_in_snapshot=True,
            button_resolves_to_current_post=True, markup=markup))
    prior = dict(complete=True, operation='commercial-monitor-price-h66198-' + record, posts={})
    for x in posts:
        receipt = {k: x[k] for k in ['platform', 'publication_id', 'message_id', 'post_url']}
        receipt.update(text=x['caption'], text_sha256=x['caption_sha256'], monitor_price={'record_id': record},
            body_photo_button_verified=True, existing_message_reused=True)
        prior['posts'][x['platform']] = {'state': 'confirmed', 'receipt': receipt}
    for x in posts:
        x['prior_monitor_receipt'] = copy.deepcopy(prior)
    read = dict(operation=p.READ, ok=True, installed_sha=p.APP, observed_at_utc=now,
        post_write_attempts=0, provider_calls=1, monitor_pending=False,
        monitor_journal_sha256=pin['sha256'], journal_pin=pin, posts=posts)
    return meta, read


class BindingTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='offline-waldorf-fixture-')
        self.dir = Path(self.temp.name)
        self.meta_path = self.dir / 'journal-metadata.json'
        self.read_path = self.dir / 'read-result.json'
        self.write_fixture()

    def tearDown(self):
        self.temp.cleanup()

    def write_fixture(self, correct=False, edit=None):
        meta, read = fixture(correct)
        if edit:
            edit(meta, read)
        self.meta_path.write_bytes(p.raw(meta))
        self.read_path.write_bytes(p.raw(read))
        return meta, read

    def compile(self):
        return p.compile_edit(OPS, self.read_path, self.meta_path, self.dir)

    def php_functions(self, body):
        source = (self.dir / 'edit.php').read_text()
        code = source[:source.index('$out = [')] + '\n' + body
        process = subprocess.run([PHP], input=code, text=True, capture_output=True)
        self.assertEqual(process.returncode, 0, process.stderr + process.stdout)
        return process.stdout

    def native_result_fixture(self):
        manifest = p.load(self.dir / 'edit-manifest.json')
        result = dict(operation=p.EDIT, installed_sha=p.APP, ok=True,
            mode='approved_existing_caption_quality_revision', manifest_sha256=p.sha((self.dir / 'edit-manifest.json').read_bytes()),
            post_write_attempts=sum(not r['same_caption_skip'] for r in manifest['rows']),
            caption_updates=sum(not r['same_caption_skip'] for r in manifest['rows']), verified_posts=2,
            new_posts_created=0, configuration_write_attempts=0, button_write_attempts=0,
            tour_search_calls=0, price_queries=0, posts=[])
        for row in manifest['rows']:
            receipt = {k: row[k] for k in ['hotel_id', 'platform', 'publication_id', 'message_id', 'post_url',
                'photo_signature', 'attachments_sha256', 'button_url_sha256', 'source_key', 'monitor_price_record_id']}
            receipt.update(previous_version_id=row['version_id'], version_id=row['version_id'] + (0 if row['same_caption_skip'] else 10),
                text_sha256=row['new_text_sha256'], previous_text_sha256=row['old_text_sha256'],
                previous_attachments_sha256=row['attachments_sha256'], state='sent',
                button_text=manifest['button_text'],
                caption_correction_operation=row['caption_correction_operation'] if row['same_caption_skip'] else p.EDIT,
                same_caption_skipped=row['same_caption_skip'], body_photo_button_verified=True,
                formatting_verified=True, existing_message_reused=True, existing_token_resolves_to_current_version=True)
            result['posts'].append(receipt)
        return result

    def test_reader_uses_exact_urls_and_live_identity_resolution(self):
        p.compile_metadata(OPS, self.dir)
        p.compile_read(OPS, self.meta_path, self.dir)
        scope = p.load(self.dir / 'read-scope.json')['rows'][0]
        self.assertEqual(scope['hotel_id'], 66198)
        self.assertEqual(scope['telegram_message_id'], 678)
        self.assertEqual(scope['max_publication_id'], 0)
        self.assertEqual(scope['telegram_publication_id'], 0)
        self.assertEqual(scope['max_url'], p.URLS['max'])
        self.compile()
        manifest = p.load(self.dir / 'edit-manifest.json')
        self.assertEqual([r['publication_id'] for r in manifest['rows']], [901, 902])
        self.assertEqual([r['version_id'] for r in manifest['rows']], [12001, 12002])
        for file in ['journal-metadata.php', 'read.php', 'edit.php']:
            result = subprocess.run([PHP, '-l', str(self.dir / file)], text=True, capture_output=True)
            self.assertEqual(result.returncode, 0, result.stderr + result.stdout)

    def test_exact_phrase_preserves_price_cta_photo_and_utf16_spans(self):
        self.compile()
        manifest = p.load(self.dir / 'edit-manifest.json')
        formatted = json.loads(self.php_functions("$m=json_decode(base64_decode(MANIFEST_BASE64),true);validateManifest($m);$out=[];foreach($m['rows'] as $r)$out[]=captionQualityProviderText($r['platform'],$r['old_text'],$r['new_text'],$r['old_markup']);echo jsonValue($out);"))
        for row, fmt in zip(manifest['rows'], formatted):
            self.assertEqual(row['new_text'], row['old_text'].replace(p.OLD, p.NEW))
            self.assertEqual(row['old_text'].split('💰', 1)[1], row['new_text'].split('💰', 1)[1])
            self.assertEqual(len(fmt['markup']), len(row['old_markup']))
            self.assertEqual(fmt['markup'][0], row['old_markup'][0])
            delta = p.units(p.NEW) - p.units(p.OLD)
            self.assertEqual(fmt['markup'][1]['length'], row['old_markup'][1]['length'] + delta)
            pos = 'from' if row['platform'] == 'max' else 'offset'
            self.assertEqual(fmt['markup'][2][pos], row['old_markup'][2][pos] + delta)
            self.assertEqual(fmt['markup'][2]['length'], row['old_markup'][2]['length'])
            if row['platform'] == 'max':
                self.assertIn('<i>' + p.NEW + '</i>', fmt['max_html'])
                self.assertIn('2 100 000 ₽', fmt['max_html'])

    def test_native_single_image_representation_and_media_scope(self):
        # Native READ45091375484 reports ['image'] for both platforms. Preserve its
        # canonical shape while using independent fixture IDs, versions and clocks.
        self.compile()
        self.php_functions(r"""
foreach(['telegram','max'] as $platform){
    $media=['type'=>'image','provider'=>$platform,$platform==='max'?'photo_id':'file_unique_id'=>'fixture_66198'];
    need(photoSignature(['attachments'=>[$media]])===$platform.':fixture_66198','canonical_image_rejected');
    foreach([[$media,$media],[array_replace($media,['type'=>'photo'])],[array_replace($media,['type'=>'video'])]] as $bad){
        $failed=false;try{photoSignature(['attachments'=>$bad]);}catch(Throwable $e){$failed=true;}
        need($failed,'invalid_media_accepted');
    }
}
""")
        for platform in ['telegram', 'max']:
            for count, types in [(1, ['photo']), (1, ['video']), (1, []), (1, 'image'),
                                 (1, ['image', 'video']), (2, ['image']), (2, ['image', 'image'])]:
                with self.subTest(platform=platform, count=count, media_types=types):
                    def change(meta, read):
                        post = next(x for x in read['posts'] if x['platform'] == platform)
                        post.update(media_count=count, media_types=types)
                    self.write_fixture(edit=change)
                    with self.assertRaisesRegex(ValueError, 'native_target_or_preservation_unverified'):
                        self.compile()

    def test_already_correct_is_verified_noop(self):
        self.write_fixture(correct=True)
        receipt = self.compile()
        self.assertEqual(receipt['expected_edits'], 0)
        self.php_functions("$m=json_decode(base64_decode(MANIFEST_BASE64),true);validateManifest($m);foreach($m['rows'] as $r){$f=captionQualityProviderText($r['platform'],$r['old_text'],$r['new_text'],$r['old_markup']);need(shortTitleMarkupEqual($f['markup'],$r['old_markup']),'noop_changed_markup');}")
        result_path = self.dir / 'edit-result.json'
        result_path.write_bytes(p.raw(self.native_result_fixture()))
        verified = p.verify(self.dir / 'edit-manifest.json', result_path, self.dir)
        self.assertEqual(verified['posts_edited'], 0)
        changed_metadata = self.native_result_fixture()
        changed_metadata['posts'][0]['caption_correction_operation'] = p.EDIT
        result_path.write_bytes(p.raw(changed_metadata))
        with self.assertRaises(ValueError):
            p.verify(self.dir / 'edit-manifest.json', result_path, self.dir)

    def test_one_already_correct_post_does_not_consume_another_attempt(self):
        meta, read = fixture(False)
        _, correct = fixture(True)
        read['posts'][0] = correct['posts'][0]
        self.meta_path.write_bytes(p.raw(meta))
        self.read_path.write_bytes(p.raw(read))
        receipt = self.compile()
        self.assertEqual(receipt['expected_edits'], 1)
        result_path = self.dir / 'edit-result.json'
        result_path.write_bytes(p.raw(self.native_result_fixture()))
        verified = p.verify(self.dir / 'edit-manifest.json', result_path, self.dir)
        self.assertEqual(verified['posts_edited'], 1)
        self.assertEqual(verified['native_result']['post_write_attempts'], 1)

    def test_wrong_target_hash_state_or_native_proof_refused(self):
        mutations = [
            lambda m, r: r['posts'][0].update(hotel_id=5160),
            lambda m, r: r['posts'][0].update(message_id='679'),
            lambda m, r: r['posts'][1].update(post_url='https://max.ru/channel_anytour/other'),
            lambda m, r: r['posts'][1].update(provider_live_verified=False),
            lambda m, r: r['posts'][0].update(caption_sha256='0' * 64),
            lambda m, r: r['posts'][0].update(status='claimed'),
            lambda m, r: r['posts'][0].update(media_count=2),
            lambda m, r: r['posts'][0].update(button_resolves_to_current_post=False),
            lambda m, r: r.update(monitor_pending=True),
            lambda m, r: r.update(monitor_journal_sha256='0' * 64),
            lambda m, r: r.update(observed_at_utc='2020-01-01T00:00:00Z'),
            lambda m, r: r.update(provider_calls=2),
        ]
        for mutation in mutations:
            with self.subTest(mutation=mutation):
                self.write_fixture(edit=mutation)
                with self.assertRaises((ValueError, AssertionError)):
                    self.compile()

    def test_price_lineage_or_phrase_drift_refused(self):
        def change(m, r):
            r['posts'][0]['prior_monitor_receipt']['posts']['telegram']['receipt']['publication_id'] = 777
        self.write_fixture(edit=change)
        with self.assertRaises(AssertionError):
            self.compile()
        for value in ['Ресторанов десять.', p.OLD + ' ' + p.OLD, p.OLD + ' ' + p.NEW]:
            with self.subTest(value=value):
                with self.assertRaises(ValueError):
                    p.changed_caption(value)

    def test_exact_php_scope_and_native_markup_drift_refused(self):
        self.compile()
        body = r"""
$m=json_decode(base64_decode(MANIFEST_BASE64),true);validateManifest($m);
$mutations=[static function(&$x){$x['rows'][0]['hotel_id']=5160;},static function(&$x){$x['rows'][0]['publication_id']++;},static function(&$x){$x['rows'][1]['version_id']++;},static function(&$x){$x['rows'][0]['new_text'].='!';},static function(&$x){array_pop($x['rows']);},static function(&$x){$x['quality_authorization']='old';},static function(&$x){$x['predecessor']=['operation'=>'old'];}];
foreach($mutations as $f){$bad=$m;$f($bad);$rejected=false;try{validateManifest($bad);}catch(Throwable $e){$rejected=true;}need($rejected,'mutated_scope_accepted');}
$r=$m['rows'][0];$bad=$r['old_markup'];$bad[0]['length']--;$rejected=false;try{captionQualityProviderText($r['platform'],$r['old_text'],$r['new_text'],$bad);}catch(Throwable $e){$rejected=true;}need($rejected,'changed_native_markup_accepted');
"""
        self.php_functions(body)

    def test_ambiguous_markup_boundary_holds_before_provider(self):
        def crossing(m, r):
            r['posts'][0]['markup'][1]['length'] -= 1
        self.write_fixture(edit=crossing)
        self.compile()
        self.php_functions("$m=json_decode(base64_decode(MANIFEST_BASE64),true);$failed=false;try{validateManifest($m);}catch(Throwable $e){$failed=$e->getMessage()==='waldorf_markup_crosses_replacement_boundary';}need($failed,'ambiguous_markup_accepted');")

    def test_native_unknown_wrong_version_or_extra_write_refused(self):
        self.compile()
        result = self.native_result_fixture()
        path = self.dir / 'edit-result.json'
        path.write_bytes(p.raw(result))
        self.assertEqual(p.verify(self.dir / 'edit-manifest.json', path, self.dir)['posts_edited'], 2)
        for field, value in [('ok', False), ('button_write_attempts', 1), ('post_write_attempts', 3), ('manifest_sha256', '0' * 64)]:
            bad = copy.deepcopy(result)
            bad[field] = value
            path.write_bytes(p.raw(bad))
            with self.subTest(field=field), self.assertRaises(ValueError):
                p.verify(self.dir / 'edit-manifest.json', path, self.dir)
        bad = copy.deepcopy(result)
        bad['posts'][0]['version_id'] = bad['posts'][0]['previous_version_id']
        path.write_bytes(p.raw(bad))
        with self.assertRaises(ValueError):
            p.verify(self.dir / 'edit-manifest.json', path, self.dir)


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--ops', type=Path, default=ROOT.parent)
    parser.add_argument('--php', default='php')
    args = parser.parse_args()
    OPS = args.ops.resolve()
    PHP = args.php
    if shutil.which(PHP) is None:
        raise SystemExit('PHP is required; skipped checks are not a pass')
    unittest.main(argv=['test_prepare.py'], verbosity=2)
