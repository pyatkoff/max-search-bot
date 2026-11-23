"""Independent offline checks; synthetic fixtures are never native read evidence."""
import argparse
import base64
import copy
import datetime
import hashlib
import importlib.util
import json
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parent
REPO = ROOT.parents[3]
LINEAGE = ROOT / 'lineage'
PHP = shutil.which('php') or 'php'
HOTELS = [2335, 2603, 2602, 11751, 11775, 64918, 11791, 1703, 2721, 33531, 11847, 17608, 236]
APP = 'b16bf4e7002d31fdc1238eef9373b617fdaa2fe3'


def encode(value):
    return json.dumps(value, ensure_ascii=False, separators=(',', ':'))


def php_value(value):
    payload = base64.b64encode(encode(value).encode()).decode()
    return "json_decode(base64_decode('" + payload + "'),true,128,JSON_THROW_ON_ERROR)"


def load_prepare():
    spec = importlib.util.spec_from_file_location('max13_prepare', ROOT / 'prepare.py')
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class PHPChecks(unittest.TestCase):
    def run_php(self, body, extra=''):
        # Functions only: the production main/installed guards are never invoked.
        source = (LINEAGE / 'commercial-caption-review73-20261113-read-scope.php').read_text()
        header = source[:source.index('$out=')]
        process = subprocess.run([PHP], input=header + '\n' + extra + '\n' + body,
                                 text=True, capture_output=True, timeout=20)
        self.assertEqual(process.returncode, 0, process.stderr + process.stdout)
        return json.loads(process.stdout)


class SourceBindingTests(unittest.TestCase):
    def test_exact_13_max_manifest_has_no_current_version_from_history(self):
        manifest = json.loads((ROOT / 'manifest.json').read_bytes())
        self.assertEqual(manifest['platform'], 'max')
        self.assertEqual(manifest['authorization_scope'], 'one_time_native_read_13_only')
        self.assertEqual(manifest['approval_comment_id'], 7072422617)
        rows = manifest['rows']
        self.assertEqual([x['hotel_id'] for x in rows], HOTELS)
        for key in ['publication_id', 'message_id', 'post_url']:
            self.assertEqual(len({x[key] for x in rows}), 13)
        for row in rows:
            self.assertNotIn('version_id', row)
            self.assertNotIn('telegram_url', row)
            self.assertTrue(row['post_url'].startswith('https://max.ru/channel_anytour/'))
            self.assertIsInstance(row['historical']['version_id'], int)

    def test_publishing_pins_match_actual_current_source_bytes(self):
        pins = json.loads((ROOT / 'surface-pins.json').read_bytes())
        self.assertEqual(len(pins), 22)
        for path, expected in pins.items():
            with self.subTest(path=path):
                data = (REPO / path).read_bytes()
                digest = hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()
                self.assertEqual(digest, expected)


class ProviderAndClaimTests(PHPChecks):
    def test_native_get_exact_mid_and_channel_and_no_write_method(self):
        response = dict(http=200, transport_error=False, data=dict(
            body=dict(mid='mid.offline_fixture'),
            recipient=dict(chat_id='-80492244209683', chat_type='channel')))
        stub = '''
final class ChannelPostApiTransport {
 public static array $calls=[];
 public static array $response=[];
 public static function buttonRequest(string $platform,string $method,string $path,array $query,?array $body):array {
  self::$calls[]=[$platform,$method,$path,$query,$body];
  if ($platform!=='max'||$method!=='GET'||$path!=='/messages/mid.offline_fixture'||$query!==[]||$body!==null) throw new RuntimeException('fixture_refused_non_read');
  return self::$response;
 }
}
'''
        body = 'ChannelPostApiTransport::$response=' + php_value(response) + ''';
$ok=readMax('mid.offline_fixture','-80492244209683');$errors=[];
foreach (['mid','channel','chat_type','http','transport'] as $change) {
 $original=ChannelPostApiTransport::$response;
 if($change==='mid')ChannelPostApiTransport::$response['data']['body']['mid']='mid.other';
 if($change==='channel')ChannelPostApiTransport::$response['data']['recipient']['chat_id']='-123';
 if($change==='chat_type')ChannelPostApiTransport::$response['data']['recipient']['chat_type']='dialog';
 if($change==='http')ChannelPostApiTransport::$response['http']=500;
 if($change==='transport')ChannelPostApiTransport::$response['transport_error']=true;
 try{readMax('mid.offline_fixture','-80492244209683');$errors[$change]='ACCEPTED';}
 catch(Throwable $e){$errors[$change]=$e->getMessage();}
 ChannelPostApiTransport::$response=$original;
}
$count=count(ChannelPostApiTransport::$calls);
try{readMax('../other?token=fixture','-80492244209683');$invalid='ACCEPTED';}
catch(Throwable $e){$invalid=$e->getMessage();}
echo jsonValue(['errors'=>$errors,'calls'=>ChannelPostApiTransport::$calls,'invalid'=>$invalid,'calls_before_invalid'=>$count]);
'''
        result = self.run_php(body, stub)
        self.assertEqual(set(result['errors'].values()), {'max_read_identity_changed', 'max_read_failed'})
        self.assertEqual(result['invalid'], 'invalid_max_message_id')
        self.assertEqual(len(result['calls']), result['calls_before_invalid'])
        self.assertTrue(all(x == ['max', 'GET', '/messages/mid.offline_fixture', [], None] for x in result['calls']))

    def test_claim_fence_checks_archived_versions_without_writing(self):
        result = self.run_php('''
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE post_versions(id INTEGER PRIMARY KEY,publication_id INTEGER,state TEXT,claim_token TEXT,claimed_at TEXT)');
$pdo->exec("INSERT INTO post_versions VALUES (1,901,'sent',NULL,NULL),(2,901,'sent',NULL,NULL),(3,902,'unknown',NULL,NULL)");
noActiveVersions($pdo,[901]);$errors=[];
foreach(['claimed','write_started','unknown','claim_token','claimed_at'] as $case){
 $pdo->exec("UPDATE post_versions SET state='sent',claim_token=NULL,claimed_at=NULL WHERE id=1");
 if(in_array($case,['claimed','write_started','unknown'],true))$pdo->exec("UPDATE post_versions SET state='".$case."' WHERE id=1");
 else $pdo->exec("UPDATE post_versions SET ".$case."='offline_claim' WHERE id=1");
 $before=$pdo->query('SELECT total_changes()')->fetchColumn();$pdo->exec('PRAGMA query_only=ON');
 try{noActiveVersions($pdo,[901]);$errors[$case]='ACCEPTED';}catch(Throwable $e){$errors[$case]=$e->getMessage();}
 if($pdo->query('SELECT total_changes()')->fetchColumn()!==$before)throw new RuntimeException('fixture_detected_write');
 $pdo->exec('PRAGMA query_only=OFF');
}
echo jsonValue($errors);
''')
        self.assertEqual(set(result.values()), {'existing_publication_active_or_unknown_stop'})


class SnapshotTests(PHPChecks):
    def inspect(self, mutate='', text='✈️ Offline Resort & Spa\n💰 От 200 000 ₽ за двоих', row=None):
        row = row or json.loads((ROOT / 'manifest.json').read_bytes())['rows'][0]
        label = '✈️ Узнать стоимость тура'
        button = 'https://max.ru/id9704048781_1_bot?start=p_OFFLINE_FIXTURE_1234567890'
        native = dict(url=row['post_url'], timestamp=1795392000000,
            recipient=dict(chat_id='-80492244209683', chat_type='channel'),
            body=dict(mid=row['message_id'], text=text,
                markup=[dict(type='strong', **{'from': 0}, length=10)],
                attachments=[dict(type='image', payload=dict(photo_id='113355',
                    token='OFFLINE_ATTACHMENT_SECRET_SENTINEL', url='https://fixture.invalid/photo')),
                    dict(type='inline_keyboard', payload=dict(buttons=[[dict(type='link', text=label, url=button)]]))]))
        functions = (ROOT / 'read-body.php').read_text().split('// MAX13_MAIN', 1)[0]
        functions = functions.removeprefix('<?php')
        imports = 'require_once ' + php_value(str(REPO / 'services/ChannelPostButtonTransport.php')) + ';\n'
        setup = '$row=' + php_value(row) + ';$message=' + php_value(native) + ';$button=' + php_value(button) + ''';
$live=ChannelPostIncomingAdapter::maxSnapshot($message);
$publication=['project_key'=>'anytour','platform'=>'max','bot_key'=>'id9704048781_1_bot',
 'channel_id'=>'-80492244209683','source_key'=>'max_anytour_msk1','publication_id'=>$row['publication_id'],
 'version_id'=>$row['historical']['version_id']+10000,'external_message_id'=>$row['message_id'],
 'state'=>'sent','start_payload'=>'p_OFFLINE_FIXTURE_1234567890','snapshot'=>$live,
 'channel'=>['id'=>9,'config_version'=>13,'button_text'=>'✈️ Узнать стоимость тура','bot_url'=>'https://max.ru/id9704048781_1_bot']];
$publication['snapshot']['metadata']['hotel_catalog_id']=(string)$row['hotel_id'];
$publication['snapshot']['metadata']['logical_id']='commercial-hotel-'.$row['hotel_id'].'-v1';
$resolved=['publication_id'=>$row['publication_id'],'version_id'=>$publication['version_id'],'source_key'=>'max_anytour_msk1'];
'''
        body = setup + mutate + '''
try{$result=inspectMax13Snapshots($row,$publication,$live,$button,$resolved);}
catch(Throwable $e){$result=['error'=>$e->getMessage()];}
echo jsonValue($result);
'''
        return self.run_php(body, imports + functions)

    def test_fresh_price_version_is_not_bound_to_historical_caption(self):
        result = self.inspect()
        self.assertEqual(result['verdict'], 'native-correct')
        self.assertEqual(result['literal_amp_entity_count'], 0)
        self.assertEqual(result['ordinary_ampersand_count'], 1)
        self.assertEqual(result['version_id'], result['historical_version_id'] + 10000)
        self.assertFalse(result['matches_historical_caption'])
        self.assertEqual(result['hold_reasons'], [])

    def test_literal_entity_is_observed_without_decoding_or_editing(self):
        text = '✈️ Offline Resort &amp; Spa\nЦена обновлена: 200 000 ₽ & другие условия'
        result = self.inspect(text=text)
        self.assertEqual(result['caption'], text)
        self.assertEqual(result['caption_sha256'], hashlib.sha256(text.encode()).hexdigest())
        self.assertEqual(result['literal_amp_entity_count'], 1)
        self.assertEqual(result['ordinary_ampersand_count'], 1)
        self.assertEqual(result['verdict'], 'conditional_edit_candidate')
        self.assertEqual(result['hold_reasons'], [])

    def test_same_markup_key_order_is_accepted_but_changed_span_is_held(self):
        result = self.inspect("$live['markup']=[['length'=>10,'from'=>0,'type'=>'strong']];")
        self.assertEqual(result['verdict'], 'native-correct')
        self.assertTrue(result['native_registry_markup_match'])
        changed = self.inspect("$live['markup'][0]['from']=1;")
        self.assertEqual(changed['verdict'], 'hold')
        self.assertIn('native_registry_markup_mismatch', changed['hold_reasons'])

    def test_provider_text_drift_remains_visible_and_holds(self):
        result = self.inspect("$live['text']='Provider current &amp; text';")
        self.assertEqual(result['caption'], 'Provider current &amp; text')
        self.assertEqual(result['literal_amp_entity_count'], 1)
        self.assertEqual(result['verdict'], 'hold')
        self.assertIn('registry_caption_mismatch', result['hold_reasons'])

    def test_photo_change_extra_media_and_bad_buttons_are_held(self):
        cases = [
            ("$live['attachments'][0]['photo_id']='999';", 'native_registry_media_mismatch'),
            ("$live['attachments'][]=$live['attachments'][0];", 'native_media_shape_changed'),
            ("$live['attachments'][0]['type']='video';", 'native_media_shape_changed'),
            ("$live['keyboard'][0][0]['url']='https://fixture.invalid/wrong';", 'current_button_binding_unverified'),
            ("$live['keyboard'][0][]=$live['keyboard'][0][0];", 'current_button_binding_unverified'),
            ("++$resolved['version_id'];", 'current_button_binding_unverified'),
            ("$live['metadata']['capability']='requires_attention';", 'snapshot_capability_held'),
        ]
        for mutation, expected in cases:
            with self.subTest(expected=expected, mutation=mutation):
                result = self.inspect(mutation)
                self.assertEqual(result['verdict'], 'hold')
                self.assertIn(expected, result['hold_reasons'])

    def test_attachment_token_rotation_is_read_only_and_not_disclosed(self):
        result = self.inspect("$live['attachments'][0]['token']='ROTATED_OFFLINE_ATTACHMENT_SECRET';")
        self.assertEqual(result['verdict'], 'native-correct')
        self.assertTrue(result['native_registry_media_match'])
        self.assertFalse(result['raw_attachment_hash_match'])
        serialized = encode(result)
        for private in ['OFFLINE_ATTACHMENT_SECRET', 'ROTATED_OFFLINE', 'OFFLINE_FIXTURE_1234567890',
                        'fixture.invalid/photo', 'raw_body', 'start_payload', '"attachments":', '"keyboard":']:
            self.assertNotIn(private, serialized)

    def test_wrong_current_or_native_identity_cannot_be_classified(self):
        cases = [
            ("$publication['platform']='telegram';", 'current_publication_identity_changed'),
            ("$publication['version_id']=0;", 'current_publication_identity_changed'),
            ("$publication['external_message_id']='mid.wrong';", 'current_publication_identity_changed'),
            ("$publication['source_key']='other_source';", 'current_publication_identity_changed'),
            ("$publication['state']='unknown';", 'current_publication_identity_changed'),
            ("$publication['snapshot']['metadata']['hotel_catalog_id']='66198';", 'current_hotel_binding_changed'),
            ("$publication['snapshot']['metadata']['logical_id']='commercial-hotel-66198-v1';", 'current_logical_identity_changed'),
            ("$publication['channel']['config_version']=0;", 'current_channel_configuration_changed'),
            ("$live['raw_body']['body']['mid']='mid.wrong';", 'native_post_identity_changed'),
            ("$live['raw_body']['recipient']['chat_id']='-123';", 'native_post_identity_changed'),
            ("$live['post_url']='https://max.ru/channel_anytour/wrong';", 'native_post_identity_changed'),
        ]
        for mutation, expected in cases:
            with self.subTest(mutation=mutation):
                self.assertEqual(self.inspect(mutation), {'error': expected})


class CompilerAndVerifierTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.p = load_prepare()
        cls.posts = [SnapshotTests().inspect(row=row) for row in cls.p.manifest()['rows']]

    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='max13-offline-compiler-')
        self.directory = Path(self.temp.name)
        self.meta_path = self.directory / 'metadata-fixture.json'
        self.now = datetime.datetime.now(datetime.timezone.utc).replace(microsecond=0)
        self.meta = dict(operation=self.p.META, ok=True, installed_sha=APP,
            manifest_sha256=self.p.MANIFEST_SHA256, monitor_pending=False,
            observed_at_utc=self.now.isoformat(),
            journal_pin=dict(sha256='a' * 64, bytes=1874681123, lines=18765,
                collector_run_id='123456', cycle_state='completed'),
            **dict.fromkeys(self.p.ZERO_FIELDS, 0))
        self.meta_path.write_bytes(self.p.raw(self.meta))

    def tearDown(self):
        self.temp.cleanup()

    def result(self):
        return dict(schema=1, kind='MAX13_NATIVE_READ_ONLY', operation=self.p.READ, ok=True,
            installed_sha=APP, manifest_sha256=self.p.MANIFEST_SHA256, approval_comment_id=7072422617,
            **dict(dict.fromkeys(self.p.ZERO_FIELDS, 0), provider_calls=13),
            journal_pin=copy.deepcopy(self.meta['journal_pin']),
            monitor_journal_sha256=self.meta['journal_pin']['sha256'], monitor_pending=False,
            monitor_journal_unchanged=True, locks_released_before_return=True,
            observed_at_utc=self.now.isoformat(), post_count=13, posts=copy.deepcopy(self.posts),
            counts={'native-correct': 13, 'conditional_edit_candidate': 0, 'hold': 0})

    def verify(self, result):
        return self.p.validate_result(result, self.meta, now=self.now.timestamp())

    def test_compiles_only_metadata_and_bounded_read_without_legacy_runner(self):
        self.p.compile_metadata(self.directory)
        proof = self.p.compile_read(self.meta_path, self.directory)
        self.assertEqual(proof['provider_methods'], ['GET'])
        self.assertEqual(proof['post_count'], 13)
        self.assertEqual((self.directory / 'read-scope.json').read_bytes(), (ROOT / 'manifest.json').read_bytes())
        for filename in ['journal-metadata.php', 'read.php']:
            script = self.directory / filename
            checked = subprocess.run([PHP, '-l', str(script)], text=True, capture_output=True, timeout=20)
            self.assertEqual(checked.returncode, 0, checked.stderr + checked.stdout)
            text = script.read_text()
            self.assertIn(APP, text)
            self.assertNotIn('priorMonitorReceipt(', text)
            self.assertNotIn('stream_get_contents($monitorLock)', text)
            self.assertNotIn('LOCK_EX', text)
        read = (self.directory / 'read.php').read_text()
        self.assertIn('START TRANSACTION READ ONLY', read)
        self.assertIn('SET TRANSACTION ISOLATION LEVEL READ COMMITTED', read)
        self.assertNotIn("identity('telegram')", read)
        self.assertNotIn('registerPublication(', read)
        self.assertNotIn('registerObservation(', read)
        self.assertNotIn('captionRequest(', read)
        self.assertNotIn('file_put_contents(', read)
        self.assertNotIn('fwrite(', read)

    def test_metadata_refuses_wrong_app_stale_unknown_oversize_and_write_evidence(self):
        self.assertEqual(self.p.metadata(self.meta_path, now=self.now.timestamp())[1], self.meta['journal_pin'])
        cases = [
            lambda d: d.update(installed_sha='b' * 40),
            lambda d: d.update(observed_at_utc=(self.now - datetime.timedelta(seconds=901)).isoformat()),
            lambda d: d.update(observed_at_utc=(self.now + datetime.timedelta(seconds=61)).isoformat()),
            lambda d: d.update(monitor_pending=True),
            lambda d: d.update(db_writes=1),
            lambda d: d.update(provider_calls=1),
            lambda d: d.update(post_write_attempts=False),
            lambda d: d['journal_pin'].update(cycle_state='UNKNOWN'),
            lambda d: d['journal_pin'].update(bytes=2147483649),
            lambda d: d['journal_pin'].update(bytes=True),
        ]
        for index, mutation in enumerate(cases):
            with self.subTest(case=index):
                damaged = copy.deepcopy(self.meta)
                mutation(damaged)
                self.meta_path.write_bytes(self.p.raw(damaged))
                with self.assertRaises(ValueError):
                    self.p.metadata(self.meta_path, now=self.now.timestamp())
        boundary = copy.deepcopy(self.meta)
        boundary['journal_pin']['bytes'] = 2147483648
        self.meta_path.write_bytes(self.p.raw(boundary))
        self.assertEqual(self.p.metadata(self.meta_path, now=self.now.timestamp())[1]['bytes'], 2147483648)

    def test_full_current_batch_verifies_but_never_authorizes_a_write(self):
        result = self.result()
        proof = self.verify(result)
        self.assertEqual(proof['state'], 'MAX13_NATIVE_READ_ONLY_VERIFIED')
        self.assertEqual(proof['counts']['native-correct'], 13)
        self.assertFalse(proof['retry_authorized'])
        self.assertEqual([proof[k] for k in ['post_write_attempts', 'db_writes', 'xml_writes']], [0, 0, 0])
        result['posts'][0] = SnapshotTests().inspect(text='Offline Resort &amp; Spa')
        result['posts'][1] = SnapshotTests().inspect("$live['text']='Unregistered &amp; caption';",
            row=self.p.manifest()['rows'][1])
        result['posts'][2] = SnapshotTests().inspect("$live['markup']=null;$publication['snapshot']['markup']=null;",
            row=self.p.manifest()['rows'][2])
        result['counts'] = {'native-correct': 10, 'conditional_edit_candidate': 1, 'hold': 2}
        proof = self.verify(result)
        self.assertEqual(proof['counts'], result['counts'])
        self.assertEqual(proof['post_write_attempts'], 0)
        self.assertFalse(proof['retry_authorized'])

    def test_partial_changed_scope_and_forged_verdict_do_not_verify(self):
        cases = [
            lambda d: d.update(ok=False),
            lambda d: d.update(posts=d['posts'][:-1], post_count=12),
            lambda d: d.update(provider_calls=12),
            lambda d: d.update(db_writes=1),
            lambda d: d.update(xml_writes=1),
            lambda d: d.update(locks_released_before_return=False),
            lambda d: d['journal_pin'].update(sha256='b' * 64),
            lambda d: d['posts'][0].update(platform='telegram'),
            lambda d: d['posts'][0].update(version_id=0),
            lambda d: d['posts'][0].update(caption='tampered caption'),
            lambda d: d['posts'][0].update(literal_amp_entity_count=1),
            lambda d: d['posts'][0].update(verdict='conditional_edit_candidate'),
            lambda d: d['posts'][0].update(button_resolves_to_current_post=False),
            lambda d: d['posts'][0].update(raw_body={'token': 'OFFLINE_SECRET'}),
            lambda d: d['counts'].update(hold=1),
        ]
        for index, mutation in enumerate(cases):
            with self.subTest(case=index):
                damaged = self.result()
                mutation(damaged)
                with self.assertRaises(ValueError):
                    self.verify(damaged)


class JournalTests(PHPChecks):
    def test_streamed_pin_rejects_corruption_pending_and_incomplete_records(self):
        helper = (LINEAGE / 'commercial-w9-78929-price-journal-20261118.php').read_text()
        helper = helper.split('declare(strict_types=1);', 1)[1]
        with tempfile.TemporaryDirectory(prefix='max13-offline-journal-') as directory:
            path = Path(directory) / 'journal.jsonl'
            state = dict(pending=None, nightly_cycle=dict(collector_run_id='123456', state='completed'))
            first = b'{"offline_fixture":true}\n'
            data = first + (encode(state) + '\n').encode()
            path.write_bytes(data)
            pin = dict(sha256=hashlib.sha256(data).hexdigest(), bytes=len(data), lines=2,
                       collector_run_id='123456', cycle_state='completed')

            def inspect(check_pin):
                return self.run_php('$path=' + php_value(str(path)) + ';$pin=' + php_value(check_pin) + ''';
$f=fopen($path,'r');flock($f,LOCK_SH|LOCK_NB);
try{$proof=verifiedCaptionPriceJournal($path,$f,$pin);$result=['ok'=>true,'proof'=>$proof];}
catch(Throwable $e){$result=['ok'=>false,'error'=>$e->getMessage()];}
flock($f,LOCK_UN);fclose($f);echo jsonValue($result);
''', helper)

            self.assertEqual(inspect(pin)['proof'], {k: pin[k] for k in ['sha256', 'bytes', 'lines']})
            changed = dict(pin, sha256='0' * 64)
            self.assertEqual(inspect(changed)['error'], 'monitor_journal_handoff_pin_changed')
            self.assertEqual(inspect(dict(pin, collector_run_id='999'))['error'], 'monitor_journal_handoff_cycle_changed')
            incomplete = data[:-1]
            path.write_bytes(incomplete)
            incomplete_pin = dict(pin, bytes=len(incomplete), sha256=hashlib.sha256(incomplete).hexdigest())
            self.assertEqual(inspect(incomplete_pin)['error'], 'monitor_journal_record_incomplete')
            state['pending'] = {'operation': 'offline_unknown', 'state': 'unknown'}
            pending = first + (encode(state) + '\n').encode()
            path.write_bytes(pending)
            pending_pin = dict(pin, bytes=len(pending), sha256=hashlib.sha256(pending).hexdigest())
            self.assertEqual(inspect(pending_pin)['error'], 'monitor_pending_operation_stop')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default=PHP)
    args, rest = parser.parse_known_args()
    PHP = args.php
    unittest.main(argv=[__file__] + rest)
