"""Compile the one approved Waldorf66198 correction; never run a provider or SSH.

The native carrier authenticates current CI/source, release, ownership and artifacts.
These offline stages retain the existing read/editor engine and its native guards.
"""
import argparse
import base64
import datetime
import hashlib
import importlib.util
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parent
APP = 'b1da1418ff75cba9657d8b5a2971270c8eafb1c9'
LINEAGE = 'd44ff7ff0e1137801137467f5f03e3ca32af87f6'
READ = 'commercial-waldorf66198-caption-read-20261123-v1'
META = 'commercial-waldorf66198-journal-metadata-20261123-v1'
EDIT = 'commercial-waldorf66198-caption-correction-20261123-v1'
AUTH = 'approval7072600922_user20261123_one_time_waldorf66198'
OLD = 'Одиннадцать ресторанов'
NEW = 'Десять ресторанов, баров и лаунжей'
URLS = {'telegram': 'https://t.me/any_toour/678', 'max': 'https://max.ru/channel_anytour/AaHo8ukNYB8'}
PIN_FILE_SHA256 = 'bd35b0c4e07d8339cd8161eef3800b6ff0e4be4b769712636e353c2a65016123'
SOURCE_HASHES = {
    'commercial-caption-review73-20261113-read-scope.php': '5158f1e4157984cce98acc085329a0227f6b78c93829bd5984d247e8b629c95e',
    'commercial-caption-review73-20261113-executor.php': 'cb30dbd22432224bed30d0e8c0121b58a393ffcbd3263a09614b7d083fa88c2e',
    'commercial-w9-78929-price-journal-20261118.php': '859ba9d5fde7fa88e3c019a82d0550da22efcbbd66096e2f98e11ed4a3b62263',
    'w11-approved-corrections/prepare-read-stage.py': 'fb6a50c321c0d808c1051da2bbac762732114bca8ad1e2b29b5d0c642719a27c',
    'w11-approved-corrections/prepare-edit-stage.py': 'e3e8473d078bf23ba999875054d0445041259b5f67f67b84a5669d7ecd39f7e7',
}


def need(ok, reason):
    if not ok:
        raise ValueError(reason)


def raw(value):
    return (json.dumps(value, ensure_ascii=False, separators=(',', ':')) + '\n').encode()


def sha(value):
    return hashlib.sha256(value).hexdigest()


def units(value):
    return len(value.encode('utf-16-le')) // 2


def load(path):
    return json.loads(path.read_bytes())


def source(ops, name):
    data = (ops / name).read_bytes()
    need(sha(data) == SOURCE_HASHES[name], 'lineage_source_changed:' + name)
    return data.decode()


def lineage(ops):
    need(__debug__, 'optimized_python_would_disable_lineage_guards')
    for name in SOURCE_HASHES:
        source(ops, name)
    spec = importlib.util.spec_from_file_location('waldorf_w11_lineage', ops / 'w11-approved-corrections/prepare-edit-stage.py')
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def once(text, old, new):
    need(text.count(old) == 1, 'lineage_replacement_anchor_changed')
    return text.replace(old, new)


def pin_surface(text):
    data = (ROOT / 'surface-pins.json').read_bytes()
    need(sha(data) == PIN_FILE_SHA256, 'reviewed_surface_pins_changed')
    pins = json.loads(data)
    old = dict(re.findall(r'"([^"\n]+)"\s*=>\s*"([a-f0-9]{40})"', text))
    need(len(pins) == 22 and set(old) == set(pins), 'publishing_surface_scope_changed')
    for path, previous in old.items():
        text = once(text, '"' + path + '" => "' + previous + '"', '"' + path + '" => "' + pins[path] + '"')
    return text


def bind(text, definitions):
    declarations = '\n'.join('define(' + json.dumps(k) + ',' + json.dumps(v, ensure_ascii=False) + ');' for k, v in definitions.items())
    return once(text, 'declare(strict_types=1);', 'declare(strict_types=1);\n' + declarations)


def scope():
    # Zero publication IDs use the existing exact-URL resolver; no historical IDs.
    return {'operation': READ, 'rows': [{'hotel_id': 66198,
        'max_publication_id': 0, 'max_url': URLS['max'],
        'telegram_publication_id': 0, 'telegram_message_id': 678,
        'telegram_url': URLS['telegram']}]}


def timestamp(value):
    need(isinstance(value, str), 'native_observation_time_missing')
    parsed = datetime.datetime.fromisoformat(value.replace('Z', '+00:00'))
    need(parsed.tzinfo is not None, 'native_observation_timezone_missing')
    return parsed.timestamp()


def metadata(path):
    result = load(path)
    need(result.get('ok') is True and result.get('operation') == META and result.get('installed_sha') == APP
         and result.get('monitor_pending') is False, 'native_metadata_binding_changed')
    need(all(type(result.get(k)) is int and result[k] == 0 for k in ['provider_calls', 'post_write_attempts', 'db_writes']), 'metadata_write_or_provider_attempt')
    pin = result['journal_pin']
    need(set(pin) == {'sha256', 'bytes', 'lines', 'collector_run_id', 'cycle_state'}, 'journal_pin_schema_changed')
    need(isinstance(pin['sha256'], str) and re.fullmatch('[a-f0-9]{64}', pin['sha256'])
         and type(pin['bytes']) is int and 0 < pin['bytes'] <= 2147483648
         and type(pin['lines']) is int and pin['lines'] > 0
         and isinstance(pin['collector_run_id'], str) and re.fullmatch('[0-9]+', pin['collector_run_id'])
         and isinstance(pin['cycle_state'], str) and pin['cycle_state'] != ''
         and 'unknown' not in pin['cycle_state'].lower(), 'journal_pin_invalid')
    timestamp(result['observed_at_utc'])
    return result, pin


def bind_journal(text, ops, pin, read_only):
    begin = '    $monitorRaw=stream_get_contents($monitorLock);'
    end = "$out['monitor_journal_sha256']=hash('sha256',$monitorRaw);" if read_only else "need(array_key_exists('pending',$monitorState) && $monitorState['pending']===null,'monitor_pending_operation_stop');"
    a = text.index(begin)
    b = text.index(end, a) + len(end)
    path = '$monitorPath' if read_only else '$monitorJournal'
    replacement = '    $monitorProof=verifiedCaptionPriceJournal(' + path + ', $monitorLock, json_decode(CAPTION_PRICE_PIN,true,16,JSON_THROW_ON_ERROR));'
    if read_only:
        replacement += "\n    $out['monitor_journal_sha256']=$monitorProof['sha256'];\n    $out['journal_pin']=json_decode(CAPTION_PRICE_PIN,true,16,JSON_THROW_ON_ERROR);"
    text = text[:a] + replacement + text[b:]
    helper = source(ops, 'commercial-w9-78929-price-journal-20261118.php')
    helper = once(helper, "if (!is_array($latest) || !array_key_exists('pending', $latest)", "if (!is_array($latest) || ($latest['schema'] ?? null) !== 1 || !array_key_exists('pending', $latest)")
    prefix = '<?php\ndeclare(strict_types=1);\n'
    need(helper.startswith(prefix), 'journal_helper_header_changed')
    text = once(text, 'declare(strict_types=1);', 'declare(strict_types=1);\n' + helper[len(prefix):])
    return bind(text, {'CAPTION_PRICE_PIN': json.dumps(pin, separators=(',', ':'))})


def compile_metadata(ops, out):
    w11 = lineage(ops)
    text = source(ops, 'commercial-caption-review73-20261113-read-scope.php')
    text = pin_surface(text[:text.index('function photoSignature(')])
    body = once(w11.r.METADATA_BODY,
        "requireAudit(is_array($latest)&&array_key_exists('pending',$latest)",
        "requireAudit(is_array($latest)&&($latest['schema']??null)===1&&array_key_exists('pending',$latest)")
    text = bind(text + body, {'EXPECTED_INSTALLED_SHA': APP, 'JOURNAL_METADATA_OPERATION': META})
    (out / 'journal-metadata.php').write_text(text)


def compile_read(ops, journal_meta, out):
    lineage(ops)
    _, pin = metadata(journal_meta)
    text = pin_surface(source(ops, 'commercial-caption-review73-20261113-read-scope.php'))
    text = text.replace('commercial-caption-review73-20261113-read-v1', READ)
    for old, new in [("count($scope['rows']??[])===73", "count($scope['rows']??[])===1"),
                     ("count($out['posts'])===146", "count($out['posts'])===2"),
                     ("$out['provider_calls']===73", "$out['provider_calls']===1")]:
        text = once(text, old, new)
    text, n = re.subn(r"array_column\(\$scope\['rows'\],'hotel_id'\)===\[[0-9,]+\]", "array_column($scope['rows'],'hotel_id')===[66198]", text)
    need(n == 1, 'reader_hotel_scope_anchor_changed')
    # Native current markup is part of the authenticated read artifact, never rebuilt.
    text = once(text, "'caption'=>$caption,", "'caption'=>$caption,\n                'markup'=>$platform==='max'?($live['markup']??null):($s['markup']??null),")
    text = bind_journal(text, ops, pin, True)
    data = raw(scope())
    text = bind(text, {'EXPECTED_INSTALLED_SHA': APP, 'APPROVED_MANIFEST_SHA256': sha(data), 'MANIFEST_BASE64': base64.b64encode(data).decode()})
    (out / 'read-scope.json').write_bytes(data)
    (out / 'read.php').write_text(text)


def changed_caption(old):
    need(isinstance(old, str) and '\r' not in old and '\0' not in old, 'caption_bytes_invalid')
    if old.count(OLD) == 1 and old.count(NEW) == 0:
        return old.replace(OLD, NEW)
    need(old.count(OLD) == 0 and old.count(NEW) == 1, 'approved_phrase_not_unique')
    return old  # Durable verified no-op, never a second provider edit.


def make_manifest(ops, snapshot, journal_meta):
    w11 = lineage(ops)
    meta, pin = metadata(journal_meta)
    data = snapshot.read_bytes()
    result = json.loads(data)
    need(result.get('ok') is True and result.get('operation') == READ and result.get('installed_sha') == APP
         and result.get('post_write_attempts') == 0 and result.get('provider_calls') == 1
         and result.get('monitor_pending') is False and result.get('journal_pin') == pin
         and result.get('monitor_journal_sha256') == pin['sha256'], 'native_read_binding_changed')
    now = datetime.datetime.now(datetime.timezone.utc).timestamp()
    observed = timestamp(result['observed_at_utc'])
    need(timestamp(meta['observed_at_utc']) <= observed + 60 and now - 900 <= observed <= now + 60, 'native_read_expired')
    posts = result.get('posts')
    need(isinstance(posts, list) and len(posts) == 2 and {p.get('platform') for p in posts} == set(URLS), 'exact_pair_scope_changed')
    rows = []
    for platform in ['telegram', 'max']:
        x = next(p for p in posts if p['platform'] == platform)
        need(x['hotel_id'] == 66198 and x['post_url'] == URLS[platform]
             and x['status'] == 'sent' and x['button_resolves_to_current_post'] is True
             and x['media_count'] == 1 and x['media_types'] == ['image']
             and isinstance(x['markup'], list), 'native_target_or_preservation_unverified')
        need(platform != 'max' or x['provider_live_verified'] is True, 'max_native_read_unverified')
        need(platform != 'telegram' or x['message_id'] == '678', 'telegram_native_message_changed')
        need(x['source_key'] == ('max_anytour_msk1' if platform == 'max' else 'tg_anytour_msk'), 'native_source_changed')
        for key in ['publication_id', 'version_id', 'config_version']:
            need(type(x[key]) is int and x[key] > 0, 'native_identity_invalid')
        for key in ['caption_sha256', 'attachments_sha256', 'button_url_sha256']:
            need(isinstance(x[key], str) and re.fullmatch('[a-f0-9]{64}', x[key]), 'native_hash_invalid')
        need(sha(x['caption'].encode()) == x['caption_sha256'], 'native_caption_hash_changed')
        new = changed_caption(x['caption'])
        need(units(new) <= 1024, 'caption_length_hold')
        price, offset = w11.price_block(x)  # The original approved editor's price-lineage guard.
        row = {k: x[k] for k in ['hotel_id', 'platform', 'publication_id', 'version_id', 'message_id', 'post_url',
            'photo_signature', 'attachments_sha256', 'button_url_sha256', 'config_version', 'source_key',
            'button_observed_in_snapshot', 'monitor_price_record_id', 'caption_correction_operation']}
        row.update(old_text=x['caption'], new_text=new, old_text_sha256=x['caption_sha256'],
            new_text_sha256=sha(new.encode()), old_markup=x['markup'], price_block_sha256=sha(price.encode()),
            old_price_offset_utf16=offset, new_price_offset_utf16=units(new[:new.index(price)]) if price else 0,
            price_length_utf16=units(price), same_caption_skip=x['caption'] == new)
        rows.append(row)
    need(rows[0]['publication_id'] != rows[1]['publication_id'], 'duplicate_publication')
    manifest = dict(schema_version=1, operation=EDIT, expected_installed_sha=APP,
        update_kind='approved_existing_caption_quality_revision', button_text='✈️ Узнать стоимость тура',
        throttle_microseconds=3200000, snapshot=dict(operation=READ, installed_sha=APP, post_count=2,
        sha256=sha(data), observed_at_utc=result['observed_at_utc']), quality_authorization=AUTH,
        quality_cohort_sha256=sha(raw(rows)), batch_index=0, batch_kind='canary', predecessor=None,
        row_count=2, rows=rows)
    return manifest, pin


def compile_edit(ops, snapshot, journal_meta, out):
    manifest, pin = make_manifest(ops, snapshot, journal_meta)
    text = pin_surface(source(ops, 'commercial-caption-review73-20261113-executor.php'))
    a, b = text.index('function captionQualityScope('), text.index('function validateManifest(')
    text = text[:a] + (ROOT / 'scope.php').read_text().removeprefix('<?php\n') + '\n' + text[b:]
    text = text.replace('commercial-caption-review73-20261113-read-v1', READ)
    text = once(text, "($source['post_count'] ?? null) === 146", "($source['post_count'] ?? null) === 2")
    text = bind_journal(text, ops, pin, False)
    text = once(text, "captionQualityProviderText($platform, $row['old_text'], $row['new_text'])",
                "captionQualityProviderText($platform, $row['old_text'], $row['new_text'], $plan['old_markup'])")
    # Same-content handling from the accepted W11 compiler; the provider path stays identical.
    a = text.index('        $requestStart = (int)floor(')
    b = text.index("        $p = $registry->publication($plan['publication_id']);", a)
    text = text[:a] + "        if ($plan['old_text'] !== $plan['new_text']) {\n" + text[a:b] + '        }\n' + text[b:]
    text = once(text, "        need(is_array($p) && $p['state'] === 'sent' && $p['platform'] === $platform",
                "        need($plan['old_text']!==$plan['new_text'] || (is_array($p) && (int)$p['version_id']===$plan['version_id']),'same_caption_version_changed');\n        need(is_array($p) && $p['state'] === 'sent' && $p['platform'] === $platform")
    text = once(text, "$out[$quality ? 'caption_updates' : 'title_updates'] = count($out['posts']);",
                "$out[$quality ? 'caption_updates' : 'title_updates'] = count(array_filter($out['posts'],static fn(array $p):bool=>!$p['same_caption_skipped'])); $out['verified_posts']=count($out['posts']);")
    text = once(text, "'existing_message_reused' => true, 'existing_token_resolves_to_current_version' => true]",
                "'existing_message_reused' => true, 'existing_token_resolves_to_current_version' => true, 'same_caption_skipped'=>$plan['old_text']===$plan['new_text']]")
    text = once(text, "        $state['posts'][$key]['state'] = 'confirmed';",
                "        if(!isset($state['posts'][$key]))$state['posts'][$key]=['plan'=>$plan];\n        $state['posts'][$key]['state'] = 'confirmed';")
    data = raw(manifest)
    text = bind(text, {'EXPECTED_INSTALLED_SHA': APP, 'APPROVED_UPDATE_OPERATION': EDIT,
        'APPROVED_MANIFEST_SHA256': sha(data), 'MANIFEST_BASE64': base64.b64encode(data).decode(),
        'REPAIR_AUTHORIZATION': AUTH, 'REPAIR_COHORT_SHA256': manifest['quality_cohort_sha256'],
        'REPAIR_ROWS_BASE64': base64.b64encode(raw(manifest['rows'])).decode()})
    (out / 'edit.php').write_text(text)
    (out / 'edit-manifest.json').write_bytes(data)
    receipt = dict(state='OFFLINE_COMPILED_NOT_EXECUTED', installed_sha=APP, source_lineage=LINEAGE,
        operation=EDIT, approval_comment_id=7072600922, manifest_sha256=sha(data),
        snapshot_sha256=manifest['snapshot']['sha256'], journal_pin=pin, row_count=2,
        expected_edits=sum(not row['same_caption_skip'] for row in manifest['rows']))
    (out / 'compile-receipt.json').write_bytes(raw(receipt))
    return receipt


def verify(manifest_path, result_path, out, write=True):
    data = manifest_path.read_bytes()
    manifest, result = json.loads(data), load(result_path)
    need(manifest.get('operation') == EDIT and manifest.get('expected_installed_sha') == APP
         and manifest.get('row_count') == 2, 'verification_manifest_changed')
    need(result.get('ok') is True and result.get('operation') == EDIT and result.get('installed_sha') == APP
         and result.get('mode') == 'approved_existing_caption_quality_revision'
         and result.get('manifest_sha256') == sha(data), 'native_edit_not_confirmed')
    changes = sum(not row['same_caption_skip'] for row in manifest['rows'])
    need(result.get('post_write_attempts') == changes and result.get('caption_updates') == changes
         and result.get('verified_posts') == 2 and len(result.get('posts', [])) == 2, 'native_edit_count_changed')
    need(all(result.get(k) == 0 for k in ['new_posts_created', 'configuration_write_attempts', 'button_write_attempts', 'tour_search_calls', 'price_queries']), 'outside_scope_write')
    seen = set()
    for p in result['posts']:
        key = (p['platform'], p['publication_id'])
        rows = [r for r in manifest['rows'] if (r['platform'], r['publication_id']) == key]
        need(len(rows) == 1 and key not in seen, 'native_result_identity_changed')
        seen.add(key)
        row = rows[0]
        for field in ['hotel_id', 'platform', 'publication_id', 'message_id', 'post_url', 'photo_signature', 'button_url_sha256', 'source_key', 'monitor_price_record_id']:
            need(p.get(field) == row[field], 'native_result_preservation_changed:' + field)
        need(p.get('text_sha256') == row['new_text_sha256'] and p.get('previous_text_sha256') == row['old_text_sha256']
             and p.get('previous_version_id') == row['version_id']
             and p.get('previous_attachments_sha256') == row['attachments_sha256']
             and p.get('same_caption_skipped') is row['same_caption_skip']
             and p.get('state') == 'sent' and p.get('button_text') == manifest['button_text'], 'native_result_binding_changed')
        # editMessageCaption can return a new Telegram file_id for the same
        # file_unique_id. The existing engine proves exactly one image and its
        # stable photo signature before/after; the full attachment digest also
        # includes that replaceable file_id. Never relax the pre-edit digest,
        # MAX preservation, or a no-op's unchanged current snapshot.
        attachment = p.get('attachments_sha256')
        need(isinstance(attachment, str) and re.fullmatch('[a-f0-9]{64}', attachment)
             and ((row['platform'] == 'telegram' and not row['same_caption_skip'])
                  or attachment == row['attachments_sha256']), 'native_result_attachment_invalid')
        need(type(p.get('version_id')) is int and p['version_id'] > 0
             and ((p['version_id'] == row['version_id']) if row['same_caption_skip'] else (p['version_id'] != row['version_id'])), 'native_result_version_changed')
        expected_operation = row['caption_correction_operation'] if row['same_caption_skip'] else EDIT
        need(p.get('caption_correction_operation') == expected_operation, 'native_correction_operation_changed')
        need(all(p.get(k) is True for k in ['body_photo_button_verified', 'formatting_verified', 'existing_message_reused', 'existing_token_resolves_to_current_version']), 'native_preservation_not_verified')
    summary = dict(state='VERIFIED_EXISTING_CAPTIONS', operation=EDIT, installed_sha=APP,
        manifest_sha256=sha(data), posts_verified=2, posts_edited=changes, native_result=result)
    if write:
        (out / 'verified-result.json').write_bytes(raw(summary))
    return summary


RECONCILE_PHP = r'''
function reconciliationAttachments(array $before,array $after,array $row,array $receipt): array
{
    need(photoSignature($before)===$row['photo_signature'] && photoSignature($after)===$row['photo_signature'],
        'reconciliation_photo_identity_changed');
    need(attachmentHash($before)===$row['attachments_sha256']
        && $receipt['previous_attachments_sha256']===$row['attachments_sha256']
        && attachmentHash($after)===$receipt['attachments_sha256'], 'reconciliation_attachment_binding_changed');
    $old=$before['attachments'][0]; $new=$after['attachments'][0]; $changed=[];
    if ($row['platform']==='telegram' && !$receipt['same_caption_skipped']) {
        $keys=['file_unique_id','provider','telegram_file_id','type'];
        foreach ([$old,$new] as $item) {
            $actual=array_keys($item); sort($actual);
            need($actual===$keys && $item['provider']==='telegram'
                && is_string($item['telegram_file_id']) && $item['telegram_file_id']!==''
                && strlen($item['telegram_file_id'])<=4096, 'reconciliation_telegram_attachment_shape_changed');
        }
        foreach ($keys as $key) if ($old[$key]!==$new[$key]) $changed[]=$key;
        need($changed===[] || $changed===['telegram_file_id'], 'reconciliation_telegram_attachment_delta_changed');
    } else {
        need($before['attachments']===$after['attachments'], 'reconciliation_immutable_attachment_changed');
    }
    return ['photo_signature'=>$row['photo_signature'],'previous_attachments_sha256'=>attachmentHash($before),
        'attachments_sha256'=>attachmentHash($after),'before_attachments'=>$before['attachments'],
        'after_attachments'=>$after['attachments'],'changed_attachment_fields'=>$changed,
        'photo_count'=>1,'photo_identity_unchanged'=>true];
}

$out=['ok'=>false,'operation'=>RECONCILE_OPERATION,'caption_operation'=>CAPTION_OPERATION,
    'installed_sha'=>EXPECTED_INSTALLED_SHA,'provider_calls'=>0,'post_write_attempts'=>0,
    'database_writes'=>0,'filesystem_writes'=>0,'posts'=>[]];
$pdo=null; $monitorLock=null; $captionLock=null;
try {
    need(PHP_SAPI==='cli','cli_only'); installedGuard(); acceptedSurface();
    $input=base64_decode(RECONCILE_INPUT_BASE64,true);
    need(is_string($input) && hash('sha256',$input)===RECONCILE_INPUT_SHA256,'reconciliation_input_changed');
    $ctx=json_decode($input,true,128,JSON_THROW_ON_ERROR); $manifest=$ctx['manifest']; $result=$ctx['native_result'];
    need($ctx['operation']===RECONCILE_OPERATION && $ctx['installed_sha']===EXPECTED_INSTALLED_SHA
        && $manifest['operation']===CAPTION_OPERATION && $manifest['expected_installed_sha']===EXPECTED_INSTALLED_SHA
        && $result['operation']===CAPTION_OPERATION && $result['installed_sha']===EXPECTED_INSTALLED_SHA
        && $result['manifest_sha256']===$ctx['manifest_sha256'] && count($manifest['rows'])===2
        && count($result['posts'])===2,'reconciliation_scope_changed');
    $base='/var/www/anytoour/data/channel-post-operations';
    need(realpath($base)===$base && !is_link($base),'reconciliation_operation_root_changed');
    $monitorPath=$base.'/monitor-price-after-collection-v1.jsonl';
    need(is_file($monitorPath) && !is_link($monitorPath),'monitor_journal_unavailable');
    $monitorLock=fopen($monitorPath,'rb');
    need(is_resource($monitorLock) && flock($monitorLock,LOCK_SH|LOCK_NB),'monitor_already_running');
    $monitorProof=verifiedCaptionPriceJournal($monitorPath,$monitorLock,$ctx['journal_pin']);
    $dir=$base.'/'.CAPTION_OPERATION; $path=$dir.'/receipt.jsonl';
    need(realpath($dir)===$dir && !is_link($dir) && realpath($path)===$path && is_file($path) && !is_link($path),
        'reconciliation_caption_journal_missing');
    $captionLock=fopen($path,'rb');
    need(is_resource($captionLock) && flock($captionLock,LOCK_SH|LOCK_NB),'reconciliation_caption_journal_busy');
    $stat=fstat($captionLock);
    need(is_array($stat) && $stat['nlink']===1 && $stat['size']>0 && $stat['size']<=134217728,
        'reconciliation_caption_journal_size');
    $bytes=stream_get_contents($captionLock,134217729);
    need(is_string($bytes) && strlen($bytes)===$stat['size'] && str_ends_with($bytes,"\n"),
        'reconciliation_caption_journal_incomplete');
    $state=null; $terminal=null;
    foreach (explode("\n",substr($bytes,0,-1)) as $line) {
        $state=json_decode($line,true,128,JSON_THROW_ON_ERROR);
        need(is_array($state) && ($state['operation']??null)===CAPTION_OPERATION
            && ($state['manifest_sha256']??null)===$ctx['manifest_sha256']
            && ($state['installed_sha']??null)===EXPECTED_INSTALLED_SHA,'reconciliation_caption_journal_binding_changed');
        need($terminal===null || $terminal===$state,'reconciliation_caption_terminal_changed');
        if (($state['complete']??false)===true) $terminal=$state;
    }
    need(is_array($state) && ($state['complete']??null)===true && ($state['row_count']??null)===2
        && ($state['snapshot_sha256']??null)===$manifest['snapshot']['sha256']
        && count($state['posts']??[])===2,'reconciliation_caption_not_complete');
    ob_start();
    try {
        require getcwd().'/config.php';
        require_once getcwd().'/services/ChannelPostRuntime.php';
        require_once getcwd().'/services/ChannelPostEntryService.php';
        require_once getcwd().'/services/ChannelPostButtonTransport.php';
    } finally { ob_end_clean(); }
    $pdo=ConversationDb::connection(); $pdo->exec('START TRANSACTION READ ONLY');
    $registry=new ChannelPostRegistryService($pdo);
    noActiveVersions($pdo,array_column($manifest['rows'],'publication_id'));
    $targets=['telegram'=>['bot'=>'anyru_bot','channel'=>'-1002262666616','source'=>'tg_anytour_msk','id'=>10],
        'max'=>['bot'=>'id9704048781_1_bot','channel'=>'-80492244209683','source'=>'max_anytour_msk1','id'=>9]];
    $seen=[];
    foreach ($manifest['rows'] as $row) {
        $platform=$row['platform']; $target=$targets[$platform]??null; $key=$platform.':'.$row['publication_id'];
        need($row['hotel_id']===66198 && is_array($target) && !isset($seen[$platform]),'reconciliation_target_changed');
        $seen[$platform]=true; $entry=$state['posts'][$key]??null;
        $matches=array_values(array_filter($result['posts'],static fn($r)=>$r['platform']===$platform && $r['publication_id']===$row['publication_id']));
        need(count($matches)===1 && is_array($entry) && ($entry['state']??null)==='confirmed'
            && ($entry['receipt']??null)===$matches[0],'reconciliation_original_receipt_changed');
        $receipt=$matches[0]; $plan=$entry['plan'];
        foreach ($row as $field=>$value) need(array_key_exists($field,$plan)
            && ($field==='old_markup' ? (is_array($plan[$field]) && shortTitleMarkupEqual($value,$plan[$field])) : $plan[$field]===$value),
            'reconciliation_original_plan_changed');
        $identity=ChannelPostRuntime::identity($platform);
        need(is_array($identity) && $identity['project_key']==='anytour' && $identity['bot_key']===$target['bot'],
            'reconciliation_runtime_identity_changed');
        $current=$registry->publication($row['publication_id']);
        $before=$registry->versionForPublication($row['publication_id'],$row['version_id']);
        $after=$registry->versionForPublication($row['publication_id'],$receipt['version_id']);
        foreach ([$before,$after,$current] as $post) {
            need(is_array($post) && $post['publication_id']===$row['publication_id'] && $post['project_key']==='anytour'
                && $post['platform']===$platform && $post['bot_key']===$target['bot'] && $post['channel_id']===$target['channel']
                && $post['external_message_id']===$row['message_id'],'reconciliation_registry_identity_changed');
        }
        need($before['version_id']===$row['version_id'] && $after['version_id']===$receipt['version_id']
            && $current['version_id']===$receipt['version_id'] && $current['state']==='sent'
            && $current['source_key']===$row['source_key'] && $current['source_key']===$target['source']
            && $current['snapshot']===$after['snapshot'],'reconciliation_current_version_changed');
        $channel=$current['channel'];
        need((int)$channel['id']===$target['id'] && (int)$channel['config_version']===$row['config_version']
            && !empty($channel['is_enabled']) && $channel['button_text']===$manifest['button_text']
            && ($channel['post_mode']??'append_button')==='append_button','reconciliation_channel_changed');
        $old=$before['snapshot']; $new=$after['snapshot'];
        need(($old['text']??null)===$row['old_text'] && ($new['text']??null)===$row['new_text']
            && hash('sha256',$old['text'])===$receipt['previous_text_sha256']
            && hash('sha256',$new['text'])===$receipt['text_sha256']
            && ($old['post_url']??null)===$row['post_url'] && ($new['post_url']??null)===$row['post_url']
            && is_array($old['markup']??null) && shortTitleMarkupEqual($row['old_markup'],$old['markup'])
            && is_array($new['markup']??null) && shortTitleMarkupEqual($plan['new_markup'],$new['markup']),
            'reconciliation_caption_or_markup_changed');
        $proof=reconciliationAttachments($old,$new,$row,$receipt);
        need(($plan['attachments']??null)===$old['attachments'],'reconciliation_original_attachment_changed');
        if ($platform==='telegram' && !$receipt['same_caption_skipped']) {
            $response=$entry['provider_response_private']??null;
            need(is_array($response) && ($response['transport_error']??null)===0 && ($response['http']??null)===200
                && ($response['data']['ok']??null)===true,'reconciliation_native_response_missing');
            $message=$response['data']['result']??null;
            need(is_array($message) && (string)($message['chat']['id']??'')===$target['channel']
                && (string)($message['message_id']??'')===$row['message_id'],'reconciliation_native_message_changed');
            $native=ChannelPostIncomingAdapter::telegramSnapshot($message);
            need(is_array($native) && ($native['attachments']??null)===$new['attachments']
                && ($native['text']??null)===$row['new_text'] && is_array($native['markup']??null)
                && shortTitleMarkupEqual($plan['new_markup'],$native['markup']),
                'reconciliation_native_snapshot_changed');
        }
        need(($old['metadata']['monitor_price_record_id']??null)===$row['monitor_price_record_id']
            && ($new['metadata']['monitor_price_record_id']??null)===$row['monitor_price_record_id']
            && ($new['metadata']['caption_correction_operation']??null)===$receipt['caption_correction_operation'],
            'reconciliation_price_or_caption_lineage_changed');
        $url=ChannelPostButtonTransport::buttonUrl($current);
        need(hash('sha256',$url)===$row['button_url_sha256']
            && ChannelPostIncomingAdapter::containsOnlyButton($new,$url,$manifest['button_text'])
            && $current['start_payload']===$plan['token'],'reconciliation_button_changed');
        $resolved=ChannelPostEntryService::resolve($platform,$current['start_payload'],$registry);
        need(is_array($resolved) && $resolved['publication_id']===$row['publication_id']
            && $resolved['version_id']===$receipt['version_id'] && $resolved['source_key']===$row['source_key'],
            'reconciliation_token_resolution_changed');
        $out['posts'][]=$proof+['hotel_id'=>66198,'platform'=>$platform,'publication_id'=>$row['publication_id'],
            'previous_version_id'=>$row['version_id'],'version_id'=>$receipt['version_id'],'message_id'=>$row['message_id'],
            'post_url'=>$row['post_url'],'text_sha256'=>$receipt['text_sha256'],'button_url_sha256'=>$row['button_url_sha256'],
            'original_receipt_confirmed'=>true,'price_markup_button_preserved'=>true,'current_token_verified'=>true];
    }
    need(count($seen)===2,'reconciliation_pair_incomplete');
    installedGuard(); acceptedSurface();
    clearstatcache(true,$path); $named=lstat($path); $opened=fstat($captionLock);
    foreach (['ino','dev','mode','size','mtime','ctime','nlink'] as $field)
        need(is_array($named) && is_array($opened) && $named[$field]===$stat[$field] && $opened[$field]===$stat[$field],
            'reconciliation_caption_journal_changed');
    need(realpath($path)===$path && !is_link($path),'reconciliation_caption_journal_changed');
    $out+=['input_sha256'=>RECONCILE_INPUT_SHA256,'manifest_sha256'=>$ctx['manifest_sha256'],
        'native_result_sha256'=>$ctx['native_result_sha256'],'caption_journal_sha256'=>hash('sha256',$bytes),
        'caption_journal_bytes'=>strlen($bytes),'journal_pin'=>$ctx['journal_pin'],'monitor_pending'=>false,
        'observed_at_utc'=>gmdate('c')];
    $out['ok']=true;
} catch (Throwable $e) {
    $out['error']=preg_match('/\A[a-z][a-z0-9_]{1,100}\z/D',$e->getMessage())?$e->getMessage():'reconciliation_read_failed';
} finally {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    foreach ([$captionLock,$monitorLock] as $lock) if (is_resource($lock)) { flock($lock,LOCK_UN); fclose($lock); }
}
echo jsonValue($out),PHP_EOL;
exit($out['ok']?0:2);
'''


def compile_reconcile(ops, journal_meta, manifest_path, result_path, out):
    lineage(ops)
    meta, pin = metadata(journal_meta)
    now = datetime.datetime.now(datetime.timezone.utc).timestamp()
    need(now - 900 <= timestamp(meta['observed_at_utc']) <= now + 60, 'reconciliation_metadata_expired')
    verified = verify(manifest_path, result_path, out, write=False)
    manifest = load(manifest_path)
    # This observes an already confirmed operation. It never recompiles EDIT,
    # reuses its expired pre-edit snapshot as current authority, or creates a journal.
    need({r['platform'] for r in manifest['rows']} == set(URLS)
         and all(r['hotel_id'] == 66198 and r['post_url'] == URLS[r['platform']]
                 and changed_caption(r['old_text']) == r['new_text'] for r in manifest['rows']),
         'reconciliation_exact_scope_changed')
    ctx = dict(operation=EDIT + '-reconcile', installed_sha=APP, manifest=manifest,
        manifest_sha256=sha(manifest_path.read_bytes()), native_result=verified['native_result'],
        native_result_sha256=sha(result_path.read_bytes()), journal_pin=pin,
        metadata_sha256=sha(journal_meta.read_bytes()), metadata_observed_at_utc=meta['observed_at_utc'])
    data = raw(ctx)
    text = source(ops, 'commercial-caption-review73-20261113-read-scope.php')
    text = pin_surface(text[:text.index('function readMax(')])
    engine = source(ops, 'commercial-caption-review73-20261113-executor.php')
    text += engine[engine.index('function shortTitleMarkupCanonicalValue('):engine.index('function photoSignature(')]
    helper = source(ops, 'commercial-w9-78929-price-journal-20261118.php')
    helper = once(helper, "if (!is_array($latest) || !array_key_exists('pending', $latest)",
                  "if (!is_array($latest) || ($latest['schema'] ?? null) !== 1 || !array_key_exists('pending', $latest)")
    helper = helper.removeprefix('<?php\ndeclare(strict_types=1);\n')
    text = bind(text + helper + RECONCILE_PHP, {'EXPECTED_INSTALLED_SHA': APP, 'CAPTION_OPERATION': EDIT,
        'RECONCILE_OPERATION': ctx['operation'], 'RECONCILE_INPUT_SHA256': sha(data),
        'RECONCILE_INPUT_BASE64': base64.b64encode(data).decode()})
    (out / 'reconcile-input.json').write_bytes(data)
    (out / 'reconcile.php').write_text(text)
    return ctx


def verify_reconcile(input_path, result_path, out):
    data = input_path.read_bytes()
    ctx, result = json.loads(data), load(result_path)
    need(ctx.get('operation') == EDIT + '-reconcile' and ctx.get('installed_sha') == APP
         and result.get('ok') is True and result.get('operation') == ctx['operation']
         and result.get('caption_operation') == EDIT and result.get('installed_sha') == APP
         and result.get('input_sha256') == sha(data)
         and result.get('manifest_sha256') == ctx['manifest_sha256']
         and result.get('native_result_sha256') == ctx['native_result_sha256']
         and result.get('journal_pin') == ctx['journal_pin'] and result.get('monitor_pending') is False,
         'reconciliation_result_binding_changed')
    need(all(type(result.get(k)) is int and result[k] == 0
             for k in ['provider_calls', 'post_write_attempts', 'database_writes', 'filesystem_writes']),
         'reconciliation_write_or_provider_attempt')
    now = datetime.datetime.now(datetime.timezone.utc).timestamp()
    need(now - 900 <= timestamp(result['observed_at_utc']) <= now + 60, 'reconciliation_result_expired')
    need(isinstance(result.get('caption_journal_sha256'), str)
         and re.fullmatch('[a-f0-9]{64}', result['caption_journal_sha256'])
         and type(result.get('caption_journal_bytes')) is int and 0 < result['caption_journal_bytes'] <= 134217728,
         'reconciliation_journal_proof_missing')
    posts = result.get('posts')
    need(isinstance(posts, list) and len(posts) == 2
         and {x.get('platform') for x in posts} == set(URLS), 'reconciliation_pair_changed')
    for item in posts:
        row = next(r for r in ctx['manifest']['rows'] if r['platform'] == item['platform'])
        receipt = next(r for r in ctx['native_result']['posts'] if r['platform'] == item['platform'])
        for field in ['hotel_id', 'platform', 'publication_id', 'message_id', 'post_url', 'photo_signature', 'button_url_sha256']:
            need(item.get(field) == row[field], 'reconciliation_identity_changed:' + field)
        need(item.get('previous_version_id') == row['version_id'] and item.get('version_id') == receipt['version_id']
             and item.get('text_sha256') == receipt['text_sha256']
             and item.get('previous_attachments_sha256') == row['attachments_sha256']
             and item.get('attachments_sha256') == receipt['attachments_sha256']
             and item.get('photo_count') == 1
             and all(item.get(k) is True for k in ['photo_identity_unchanged', 'original_receipt_confirmed',
                                                  'price_markup_button_preserved', 'current_token_verified']),
             'reconciliation_preservation_unverified')
        arrays = [item.get('before_attachments'), item.get('after_attachments')]
        for attachments, digest in zip(arrays, [row['attachments_sha256'], receipt['attachments_sha256']]):
            need(isinstance(attachments, list) and len(attachments) == 1 and isinstance(attachments[0], dict)
                 and attachments[0].get('type') == 'image' and attachments[0].get('provider') == row['platform']
                 and sha(raw(attachments)[:-1]) == digest, 'reconciliation_raw_attachment_changed')
            stable = attachments[0].get('photo_id', attachments[0].get('file_unique_id'))
            need(isinstance(stable, str) and row['platform'] + ':' + stable == row['photo_signature'],
                 'reconciliation_raw_photo_changed')
        before, after = (x[0] for x in arrays)
        changed = sorted(k for k in before.keys() | after.keys() if before.get(k) != after.get(k))
        if row['platform'] == 'telegram' and not receipt['same_caption_skipped']:
            keys = {'type', 'provider', 'file_unique_id', 'telegram_file_id'}
            need(set(before) == set(after) == keys
                 and all(isinstance(x['telegram_file_id'], str) and 0 < len(x['telegram_file_id']) <= 4096 for x in [before, after])
                 and changed in [[], ['telegram_file_id']], 'reconciliation_telegram_delta_invalid')
        else:
            need(before == after, 'reconciliation_immutable_attachment_changed')
        need(item.get('changed_attachment_fields') == changed, 'reconciliation_delta_proof_changed')
    summary = dict(state='VERIFIED_CONFIRMED_CAPTION_RECONCILIATION', installed_sha=APP,
        operation=ctx['operation'], original_caption_operation=EDIT, posts_verified=2,
        provider_calls=0, post_write_attempts=0, native_proof=result)
    (out / 'verified-reconcile.json').write_bytes(raw(summary))
    return summary


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('stage', choices=['metadata', 'read', 'edit', 'verify', 'reconcile', 'verify-reconcile'])
    parser.add_argument('--ops', type=Path, default=ROOT.parent)
    parser.add_argument('--out', type=Path, required=True)
    parser.add_argument('--journal-meta', type=Path)
    parser.add_argument('--snapshot', type=Path)
    parser.add_argument('--manifest', type=Path)
    parser.add_argument('--result', type=Path)
    parser.add_argument('--input', type=Path)
    args = parser.parse_args()
    args.out.mkdir(parents=True, exist_ok=True)
    if args.stage == 'metadata':
        compile_metadata(args.ops, args.out)
    elif args.stage == 'read':
        need(args.journal_meta is not None, 'journal_meta_required')
        compile_read(args.ops, args.journal_meta, args.out)
    elif args.stage == 'edit':
        need(args.journal_meta is not None and args.snapshot is not None, 'fresh_native_read_required')
        compile_edit(args.ops, args.snapshot, args.journal_meta, args.out)
    elif args.stage == 'reconcile':
        need(args.journal_meta is not None and args.manifest is not None and args.result is not None,
             'original_native_edit_evidence_required')
        compile_reconcile(args.ops, args.journal_meta, args.manifest, args.result, args.out)
    elif args.stage == 'verify-reconcile':
        need(args.input is not None and args.result is not None, 'native_reconciliation_result_required')
        verify_reconcile(args.input, args.result, args.out)
    else:
        need(args.manifest is not None and args.result is not None, 'native_edit_result_required')
        verify(args.manifest, args.result, args.out)
    print(json.dumps({'stage': args.stage, 'operation': EDIT, 'state': 'VERIFIED_NATIVE_RESULT' if args.stage in ['verify', 'verify-reconcile'] else 'OFFLINE_COMPILED_NOT_EXECUTED'}))


if __name__ == '__main__':
    main()
