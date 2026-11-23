#!/usr/bin/env python3
"""Offline executable tests: the actual PHP contract and exact publisher bytes."""
import argparse
import base64
import copy
import datetime as dt
import html
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('feed_prepare', ROOT / 'prepare.py')
mod = importlib.util.module_from_spec(spec); spec.loader.exec_module(mod)
PARSER = argparse.ArgumentParser(); PARSER.add_argument('--ops', type=Path, required=True); PARSER.add_argument('--php', default=os.environ.get('PHP_BIN', 'php'))
ARGS, REMAINING = PARSER.parse_known_args()

def fixture(correct_posts=False, correct_feed=False):
    stamp = dt.datetime.now(dt.timezone.utc).isoformat(timespec='seconds')
    binding = mod.binding(json.loads((ROOT / 'binding.json').read_bytes()))
    caption = 'Waldorf Astoria Maldives — островной отдых\n' + mod.OLD + ' & отдых для двоих.\nЦена от 123 456 ₽. Вылет 30.11.2026.'
    rows = []
    for platform, pid, vid, mid in [('telegram', 1500, 37000, '678'), ('max', 1510, 37001, 'native-max-message')]:
        text = mod.correct(caption) if correct_posts else caption
        rows.append(dict(hotel_id=66198, platform=platform, publication_id=pid, version_id=vid, message_id=mid,
                         post_url=mod.URLS[platform], caption=text, caption_sha256=mod.sha(text.encode()), photo_signature=platform + ':photo-hash',
                         attachments_sha256='a' * 64, button_url_sha256='b' * 64, source_key='max_anytour_msk1' if platform == 'max' else 'tg_anytour_msk',
                         monitor_price_record_id=None, caption_correction_operation='previous-real-caption' if correct_posts else None,
                         status='sent', provider_live_verified=platform == 'max', button_resolves_to_current_post=True, media_count=1, media_types=['image']))
    feeds = {}
    for platform in ['telegram', 'max']:
        prefix = 'tg_' if platform == 'telegram' else 'max_'
        descriptions = [mod.correct(caption) if correct_feed else caption, 'Другой отель. ' + mod.OLD + '. ' + ('Не менять. ' * 45), 'Без цены. ' + ('Сохранять полностью. ' * 35)]
        blocks = []
        for suffix, description in zip(['66198', '9999', '9998'], descriptions):
            oid = prefix + suffix
            url = mod.feed_url(platform, oid, mod.URLS[platform]) if suffix == '66198' else 'https://example.test/' + oid
            blocks.append('      <offer id="' + oid + '">\n        <url>' + html.escape(url) + '</url>\n'
                          '        <price>123456.00</price><currencyId>RUB</currencyId>\n'
                          '        <picture>https://example.test/photo.jpg</picture>\n'
                          '        <name>Не менять заголовок</name>\n        <description>' + html.escape(description) + '</description>\n'
                          '        <custom_label_0>' + platform + '</custom_label_0>\n'
                          '        <custom_label_1>Мальдивы</custom_label_1>\n      </offer>\n')
        feeds[platform] = ''.join(blocks)
    files = []
    for name in mod.NAMES:
        body = feeds['telegram'] + feeds['max'] if name == mod.NAMES[2] else feeds['telegram' if name == mod.NAMES[0] else 'max']
        raw = ('<?xml version="1.0" encoding="UTF-8"?>\n<yml_catalog date="2026-11-23 03:00">\n  <shop>\n    <offers>\n' + body + '    </offers>\n  </shop>\n</yml_catalog>\n').encode()
        files.append(dict(name=name, bytes=len(raw), rows=6 if name == mod.NAMES[2] else 3, sha256=mod.sha(raw), content_base64=base64.b64encode(raw).decode()))
    pin = dict(sha256='e' * 64, bytes=649112533, lines=5717, collector_run_id='collector-current', cycle_state='completed')
    ps = mod.posts(rows); bindings = mod.resolve(ps, files)
    capture = dict(ok=True, kind='CONTENT66198_FEED_READ', phase='pre_edit_capture', operation=mod.CAPTION_OP, feed_operation=mod.FEED_OP,
                   installed_sha=mod.APP, approval_comment_id=7072600922, filesystem_writes=0, provider_calls=0, database_writes=0,
                   locks_released_before_return=True, monitor_journal_unchanged=True, observed_at_utc=stamp,
                   journal_pin=pin, posts=ps, files=files, caption_updates=bindings, target_description_occurrences=4,
                   description_changes=0 if correct_feed else 4)
    snapshot = dict(ok=True, operation='commercial-waldorf66198-caption-read-20261123-v1', installed_sha=mod.APP, observed_at_utc=stamp,
                    post_write_attempts=0, provider_calls=1, monitor_pending=False, monitor_journal_sha256=pin['sha256'], journal_pin=pin, posts=rows)
    snapshot_raw = mod.canonical(snapshot); capture['native_read_sha256'] = mod.sha(snapshot_raw)
    metadata = dict(ok=True, operation='commercial-waldorf66198-journal-metadata-20261123-v1', installed_sha=mod.APP, observed_at_utc=stamp,
                    post_write_attempts=0, provider_calls=0, db_writes=0, monitor_pending=False, journal_pin=pin)
    manifest_rows = []
    for p in ps:
        row = {k: p[k] for k in ['hotel_id', 'platform', 'publication_id', 'version_id', 'message_id', 'post_url', 'photo_signature', 'attachments_sha256', 'button_url_sha256', 'source_key', 'monitor_price_record_id']}
        row.update(old_text=p['caption'], new_text=mod.correct(p['caption']), old_text_sha256=p['caption_sha256'], new_text_sha256=mod.sha(mod.correct(p['caption']).encode()))
        manifest_rows.append(row)
    manifest = dict(operation=mod.CAPTION_OP, expected_installed_sha=mod.APP, row_count=2, rows=manifest_rows)
    manifest_raw = mod.canonical(manifest); state = dict(operation=mod.CAPTION_OP, installed_sha=mod.APP, manifest_sha256=mod.sha(manifest_raw), row_count=2, complete=True, posts={})
    for row in manifest_rows:
        skip = row['old_text'] == row['new_text']; receipt = {k: row[k] for k in ['hotel_id', 'platform', 'publication_id', 'message_id', 'post_url', 'photo_signature', 'button_url_sha256', 'source_key', 'monitor_price_record_id']}
        receipt.update(previous_version_id=row['version_id'], version_id=row['version_id'] if skip else row['version_id'] + 2,
                       previous_text_sha256=row['old_text_sha256'], text_sha256=row['new_text_sha256'],
                       previous_attachments_sha256=row['attachments_sha256'], attachments_sha256=row['attachments_sha256'],
                       state='sent', button_text='✈️ Узнать стоимость тура', same_caption_skipped=skip,
                       caption_correction_operation='previous-real-caption' if skip else mod.CAPTION_OP,
                       body_photo_button_verified=True, formatting_verified=True, existing_message_reused=True, existing_token_resolves_to_current_version=True)
        state['posts'][row['platform'] + ':' + str(row['publication_id'])] = dict(state='confirmed', plan=copy.deepcopy(row), receipt=receipt)
    op = dict(operation=mod.CAPTION_OP, manifest_sha256=mod.sha(manifest_raw), installed_sha=mod.APP, journal_sha256='f' * 64, row_count=2)
    capture_raw = mod.canonical(capture)
    receipts = dict(ok=True, phase='post_edit_receipts', operation=mod.CAPTION_OP, feed_operation=mod.FEED_OP, installed_sha=mod.APP,
                    capture_sha256=mod.sha(capture_raw), pre_edit_feeds_unchanged=True, filesystem_writes=0, provider_calls=0, database_writes=0,
                    locks_released_before_return=True, monitor_journal_unchanged=True, observed_at_utc=stamp, journal_pin=pin,
                    caption_operations=[op], caption_states={mod.CAPTION_OP: state})
    return dict(binding=binding, capture=capture, capture_raw=capture_raw, snapshot=snapshot, snapshot_raw=snapshot_raw, metadata=metadata,
                manifest=manifest, manifest_raw=manifest_raw, receipts=receipts, state=state, op=op)

class FeedTests(unittest.TestCase):
    def php(self, code, value):
        with tempfile.TemporaryDirectory() as d:
            script = Path(d) / 'test.php'
            script.write_text('<?php\n$d=json_decode(stream_get_contents(STDIN),true,128,JSON_THROW_ON_ERROR);\n'
                              "define('FEED66198_BINDING_B64',base64_encode(json_encode($d['binding'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)));\n"
                              + 'require ' + json.dumps(str(ROOT / 'contract.php')) + ';\n'
                              + 'try {' + code + '} catch(Throwable $e){echo json_encode(["ok"=>false,"error"=>$e->getMessage()]);exit(2);}\n')
            r = subprocess.run([ARGS.php, str(script)], input=mod.canonical(value), capture_output=True)
            return r, json.loads(r.stdout) if r.stdout else None

    def updates(self, f):
        return mod.verified_receipts(f['receipts'], b'', f['capture_raw'], f['capture'], f['manifest'], f['manifest_raw'], f['binding'])

    def test_native_timestamps_and_actual_tg_provenance(self):
        f = fixture(); got = mod.native_read(f['snapshot_raw'], f['metadata'], f['binding'])
        self.assertEqual(got['journal_pin']['bytes'], 649112533)
        for stamp in ['2026-11-23T00:00:00Z', '2026-11-23T00:00:00+00:00']:
            mod.fresh(stamp, dt.datetime(2026,11,23,tzinfo=dt.timezone.utc).timestamp())
        with self.assertRaises(ValueError): mod.fresh('2026-11-23T03:00:00+03:00')
        with self.assertRaises(ValueError): mod.fresh('2020-01-01T00:00:00Z')

    def test_native_canonical_image_media_and_started_journal(self):
        # The original native READ45091375484 returned canonical media type
        # image on both platforms, not the provider-specific photo label.
        f = fixture()
        f['snapshot']['journal_pin'].update(bytes=1874681123, lines=11806,
                                          collector_run_id='44891762210', cycle_state='started')
        got = mod.native_read(mod.canonical(f['snapshot']), f['metadata'], f['binding'])
        self.assertEqual(got['journal_pin']['cycle_state'], 'started')
        self.assertEqual([p['platform'] for p in got['posts']], ['telegram', 'max'])
        for count, kinds in [(1, ['photo']), (1, ['video']), (0, []), (2, ['image', 'image'])]:
            for platform in ['telegram', 'max']:
                bad = copy.deepcopy(f['snapshot'])
                post = next(p for p in bad['posts'] if p['platform'] == platform)
                post.update(media_count=count, media_types=kinds)
                with self.subTest(platform=platform, count=count, kinds=kinds), self.assertRaisesRegex(ValueError, 'native_post_not_verified'):
                    mod.native_read(mod.canonical(bad), f['metadata'], f['binding'])

    def test_exact_four_changes_and_php_matches_python(self):
        f = fixture(); updates = self.updates(f); changes = 0
        for file in f['capture']['files']:
            before = base64.b64decode(file['content_base64']); after, count = mod.project(before, updates, file['name']); changes += count
            r, got = self.php("echo json_encode(['ok'=>true,'bytes'=>base64_encode(caption_feed_render(base64_decode($d['xml']),$d['updates'],$d['name']))]);",
                              dict(binding=f['binding'], xml=file['content_base64'], updates=updates, name=file['name']))
            self.assertEqual(r.returncode, 0, r.stderr); self.assertEqual(base64.b64decode(got['bytes']), after)
            self.assertEqual(before.count(b'<price>123456.00</price>'), after.count(b'<price>123456.00</price>'))
            self.assertIn(('Другой отель. ' + mod.OLD).encode(), after)
            self.assertEqual(after.replace(mod.NEW.encode(), mod.OLD.encode()), before)
        self.assertEqual(changes, 4)

    def test_noop_captions_with_old_xml_and_preserved_lineage(self):
        f = fixture(correct_posts=True, correct_feed=False); updates = self.updates(f)
        self.assertEqual(sum(mod.project(base64.b64decode(x['content_base64']), updates, x['name'])[1] for x in f['capture']['files']), 4)
        r, got = self.php("$u=wf_confirmed($d['state'],$d['op'],$d['bindings']);echo json_encode(['ok'=>true,'count'=>count($u)]);",
                          dict(binding=f['binding'], state=f['state'], op=f['op'], bindings=f['capture']['caption_updates']))
        self.assertEqual(r.returncode, 0, got); self.assertEqual(got['count'], 2)
        f['state']['posts']['telegram:1500']['receipt']['caption_correction_operation'] = mod.CAPTION_OP
        with self.assertRaises(ValueError): self.updates(f)

    def test_already_correct_feeds_are_byte_noop(self):
        f = fixture(correct_posts=True, correct_feed=True); updates = self.updates(f)
        for file in f['capture']['files']:
            before = base64.b64decode(file['content_base64']); after, count = mod.project(before, updates, file['name'])
            self.assertEqual((after, count), (before, 0))

    def test_price_url_or_body_difference_rejected_before_edit(self):
        for change in ['price', 'url', 'body', 'missing', 'duplicate']:
            f = fixture(); files = f['capture']['files']; raw = base64.b64decode(files[0]['content_base64'])
            if change == 'price': raw = raw.replace('123 456'.encode(), '123 457'.encode(), 1)
            elif change == 'url': raw = raw.replace(b'https://t.me/any_toour/678', b'https://t.me/any_toour/679', 1)
            elif change == 'body': raw = raw.replace('отдых для двоих'.encode(), 'новый текст'.encode(), 1)
            elif change == 'missing': raw = raw.replace(b'id="tg_66198"', b'id="tg_removed"', 1)
            else: raw = raw.replace(b'id="tg_9999"', b'id="tg_66198"', 1)
            files[0].update(content_base64=base64.b64encode(raw).decode(), bytes=len(raw), sha256=mod.sha(raw))
            with self.subTest(change=change), self.assertRaises(ValueError): mod.resolve(f['capture']['posts'], files)

    def test_unknown_partial_wrong_version_and_wrong_operation_rejected(self):
        for change in ['unknown', 'partial', 'version', 'operation', 'identity', 'text', 'button']:
            f = fixture(); p = f['state']['posts']['max:1510']
            if change == 'unknown': p['state'] = 'started'
            elif change == 'partial': f['state']['complete'] = False
            elif change == 'version': p['receipt']['version_id'] = p['plan']['version_id']
            elif change == 'operation': p['receipt']['caption_correction_operation'] = 'some-other-operation'
            elif change == 'identity': p['receipt']['hotel_id'] = 666
            elif change == 'text': p['plan']['new_text'] += ' Непозволительное добавление'
            else: p['receipt']['button_url_sha256'] = 'c' * 64
            with self.subTest(change=change), self.assertRaises(ValueError): self.updates(f)
            r, got = self.php("wf_confirmed($d['state'],$d['op'],$d['bindings']);echo json_encode(['ok'=>true]);",
                              dict(binding=f['binding'], state=f['state'], op=f['op'], bindings=f['capture']['caption_updates']))
            self.assertNotEqual(r.returncode, 0, (change, got))

    def test_dynamic_inventory_and_no_dtd_or_duplicate_json(self):
        f = fixture(); self.assertEqual([x['rows'] for x in f['capture']['files']], [3,3,6])
        mod.resolve(f['capture']['posts'], f['capture']['files'])
        with self.assertRaises(ValueError): mod.parse(b'{"x":1,"x":2}')
        raw = base64.b64decode(f['capture']['files'][0]['content_base64'])
        with self.assertRaises(ValueError): mod.xml(raw.replace(b'<yml_catalog', b'<!DOCTYPE x><yml_catalog', 1))

    def test_manifest_cannot_change_price_or_expand_targets(self):
        f = fixture(); mod.verify_manifest(f['manifest'], f['manifest_raw'], f['capture'], f['binding'])
        f['manifest']['rows'][0]['new_text'] = f['manifest']['rows'][0]['new_text'].replace('123 456','999 999')
        with self.assertRaises(ValueError): mod.verify_manifest(f['manifest'], mod.canonical(f['manifest']), f['capture'], f['binding'])

    def test_real_source_pins_and_all_compiled_php_syntax(self):
        f = fixture(); ctx = mod.native_read(f['snapshot_raw'], f['metadata'], f['binding'])
        sources = [mod.compile_php(f['binding'], ctx, phase) for phase in ['capture','receipts','readback']]
        sources.append(mod.write_compile(f['binding'], {'journal_pin':f['capture']['journal_pin']}, mod.canonical({'operation':mod.FEED_OP}), ARGS.ops))
        with tempfile.TemporaryDirectory() as d:
            for index, raw in enumerate(sources):
                path = Path(d)/('compiled'+str(index)+'.php'); path.write_bytes(raw)
                result = subprocess.run([ARGS.php, '-l', str(path)], capture_output=True)
                self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            wrong = Path(d)/'source'; wrong.mkdir()
            for name in mod.SOURCE_HASHES: (wrong/name).write_bytes((ARGS.ops/name).read_bytes() + b'\n')
            with self.assertRaises(ValueError): mod.write_compile(f['binding'], ctx, b'{}', wrong)

    def test_cli_prepare_and_no_output_overwrite(self):
        f = fixture()
        with tempfile.TemporaryDirectory() as d:
            root=Path(d)
            for name,key in [('binding','binding'),('capture','capture'),('manifest','manifest'),('receipts','receipts')]:
                (root/(name+'.json')).write_bytes(mod.canonical(f[key]))
            command=[os.environ.get('PYTHON','python3'),str(ROOT/'prepare.py'),'prepare','--binding',str(root/'binding.json'),'--capture',str(root/'capture.json'),
                     '--manifest',str(root/'manifest.json'),'--receipts',str(root/'receipts.json'),'--source-root',str(ARGS.ops),'--out',str(root/'out')]
            result=subprocess.run(command,capture_output=True);self.assertEqual(result.returncode,0,result.stderr)
            prep=json.loads((root/'out/preparation.json').read_bytes());self.assertEqual(prep['description_changes'],4)
            self.assertEqual(prep['existing_writer_sha256'],mod.SOURCE_HASHES['publish-commercial-channel-hotel-feeds-20261103-1.php'])
            result=subprocess.run(command,capture_output=True);self.assertNotEqual(result.returncode,0)

    def test_real_nested_monitor_state_and_unknown_guard(self):
        f=fixture(); state={'schema':1,'pending':None,'nightly_cycle':{'collector_run_id':'collector-current','state':'completed'}}
        code="wf_monitor_state($d['state'],$d['pin']);echo json_encode(['ok'=>true]);"
        r,got=self.php(code,dict(binding=f['binding'],state=state,pin=f['capture']['journal_pin']));self.assertEqual(r.returncode,0,got)
        for field in ['pending','schema','nightly_cycle']:
            bad=copy.deepcopy(state);bad[field]=('unknown' if field=='pending' else None)
            r,got=self.php(code,dict(binding=f['binding'],state=bad,pin=f['capture']['journal_pin']));self.assertNotEqual(r.returncode,0,got)

    def test_workflow_cli_all_six_phases_and_native_terminal_chain(self):
        f=fixture()
        with tempfile.TemporaryDirectory() as d:
            root=Path(d)
            for name,key in [('binding','binding'),('capture','capture'),('manifest','manifest'),('receipts','receipts'),('snapshot','snapshot'),('journal','metadata')]:
                (root/(name+'.json')).write_bytes(mod.canonical(f[key]))
            edit_result=dict(ok=True,operation=mod.CAPTION_OP,manifest_sha256=mod.sha(f['manifest_raw']),posts=[p['receipt'] for p in f['state']['posts'].values()])
            (root/'edit-result.json').write_bytes(mod.canonical(edit_result))
            def cli(phase,out,*args):
                cmd=['python3',str(ROOT/'prepare.py'),phase,'--binding',str(root/'binding.json'),'--out',str(root/out)]
                cmd.extend(str(x) for x in args);r=subprocess.run(cmd,capture_output=True)
                self.assertEqual(r.returncode,0,r.stderr.decode());return r
            cli('capture','read','--snapshot',root/'snapshot.json','--journal',root/'journal.json')
            cli('verify-capture','read','--snapshot',root/'snapshot.json','--capture',root/'capture.json')
            proof=json.loads((root/'read/capture-proof.json').read_bytes());self.assertEqual(len(proof['approved_diffs']),4)
            cli('receipts','receipt','--manifest',root/'manifest.json','--result',root/'edit-result.json','--capture',root/'capture.json','--journal',root/'journal.json')
            cli('prepare','write','--manifest',root/'manifest.json','--capture',root/'capture.json','--receipts',root/'receipts.json','--source-root',ARGS.ops)
            prep=json.loads((root/'write/preparation.json').read_bytes())
            result=dict(ok=True,operation=mod.FEED_OP,mode='publish_three_static_public_direct_yml_feeds',db_writes=0,provider_calls=0,message_sends=0,
                        files=[dict({k:x[k] for k in ['name','sha256','bytes','rows']},write_action='replace',public_url='https://app.anytoour.ru/feed/'+x['name']) for x in prep['files']])
            (root/'writer-result.json').write_bytes(mod.canonical(result))
            cli('verify-writer','write','--preparation',root/'write/preparation.json','--result',root/'writer-result.json','--exit-code','0')
            readback=dict(ok=True,phase='public_readback',operation=mod.CAPTION_OP,feed_operation=mod.FEED_OP,installed_sha=mod.APP,
                          input_sha256=prep['input_sha256'],public_feed_gets=3,locks_released_before_return=True,monitor_journal_unchanged=True,
                          filesystem_writes=0,provider_calls=0,database_writes=0,observed_at_utc=f['capture']['observed_at_utc'],
                          files=[dict(name=x['name'],local_sha256=x['sha256'],public_sha256=x['sha256'],bytes=x['bytes'],rows=x['rows'],
                                      url='https://app.anytoour.ru/feed/'+x['name'],http_status=200) for x in prep['files']])
            (root/'readback.json').write_bytes(mod.canonical(readback))
            cli('verify-readback','write','--preparation',root/'write/preparation.json','--result',root/'readback.json')
            terminal=json.loads((root/'write/terminal.json').read_bytes());self.assertEqual(terminal['state'],'CONTENT66198_FEED_NATIVE_PUBLIC_VERIFIED')
            self.assertEqual(terminal['writer_result_sha256'],mod.sha(mod.canonical(result)))

    def test_claims_on_any_version_block_even_when_current_sent(self):
        f=fixture()
        code="""$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
            $pdo->exec('CREATE TABLE post_versions(publication_id INTEGER,state TEXT,claim_token TEXT,claimed_at TEXT)');
            $q=$pdo->prepare('INSERT INTO post_versions VALUES(?,?,?,?)');$q->execute([1500,'sent',null,null]);
            $q->execute([1500,$d['state'],$d['token'],$d['at']]);wf_no_publication_claim($pdo,1500);echo json_encode(['ok'=>true]);"""
        for state,token,at in [('claimed',None,None),('write_started',None,None),('unknown',None,None),('sent','token',None),('sent',None,'stamp')]:
            r,got=self.php(code,dict(binding=f['binding'],state=state,token=token,at=at));self.assertNotEqual(r.returncode,0,(state,got))
        r,got=self.php(code,dict(binding=f['binding'],state='sent',token=None,at=None));self.assertEqual(r.returncode,0,got)

if __name__ == '__main__':
    unittest.main(argv=['test_feed.py', *REMAINING], verbosity=2)
