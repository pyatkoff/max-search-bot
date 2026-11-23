"""Offline compiler/verifier for the one MAX13 native read; no SSH or provider calls.

metadata --out DIR                       -> journal-metadata.php
read --journal-meta META.json --out DIR  -> read.php, read-scope.json
verify --journal-meta META.json --result READ.json --out DIR -> verification.json
"""
import argparse
import ast
import base64
import datetime
import hashlib
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parent
APP = '898d796f5220bd8694f52206d1525d97e9739d18'
LINEAGE = '26171ec67ada13822f140aa0ed6b870617263ee4'
READ = 'commercial-max13-amp-read-20261123-v1'
META = 'commercial-max13-amp-metadata-20261123-v1'
IDS = [2335, 2603, 2602, 11751, 11775, 64918, 11791, 1703, 2721, 33531, 11847, 17608, 236]
MANIFEST_SHA256 = 'c564d0c0dee0ccdaaa25bc5bb5ba46d703ffc9b48237730f4fb79e214734b99b'
PIN_FILE_SHA256 = 'bd35b0c4e07d8339cd8161eef3800b6ff0e4be4b769712636e353c2a65016123'
SOURCE_HASHES = {
    'commercial-caption-review73-20261113-read-scope.php': '5158f1e4157984cce98acc085329a0227f6b78c93829bd5984d247e8b629c95e',
    'commercial-w9-78929-price-journal-20261118.php': '859ba9d5fde7fa88e3c019a82d0550da22efcbbd66096e2f98e11ed4a3b62263',
    'w11-approved-corrections/prepare-read-stage.py': 'fb6a50c321c0d808c1051da2bbac762732114bca8ad1e2b29b5d0c642719a27c',
}
ZERO_FIELDS = ['provider_calls', 'post_write_attempts', 'db_writes', 'xml_writes', 'filesystem_writes', 'message_sends']
POST_FIELDS = {
    'hotel_id', 'title', 'platform', 'publication_id', 'version_id', 'message_id', 'post_url', 'source_key',
    'config_version', 'status', 'native_http_status', 'provider_live_verified', 'snapshot_provenance',
    'caption', 'caption_sha256', 'registry_caption_sha256', 'native_registry_caption_match',
    'literal_amp_entity_count', 'ordinary_ampersand_count', 'media_count', 'media_types', 'photo_signature',
    'registry_photo_signature', 'native_registry_media_match', 'attachments_sha256', 'registry_attachments_sha256',
    'raw_attachment_hash_match', 'markup_sha256', 'registry_markup_sha256', 'native_registry_markup_match',
    'button_url_sha256', 'button_text', 'native_button_verified', 'registry_button_verified',
    'button_resolves_to_current_post', 'historical_version_id', 'historical_caption_sha256',
    'matches_historical_caption', 'amp_status', 'verdict', 'hold_reasons',
}
HOLDS = {'registry_caption_mismatch', 'native_media_shape_changed', 'registry_media_shape_changed',
         'native_registry_media_mismatch', 'native_registry_markup_mismatch',
         'current_button_binding_unverified', 'snapshot_capability_held'}


def need(ok, reason):
    if not ok:
        raise ValueError(reason)


def sha(value):
    return hashlib.sha256(value).hexdigest()


def raw(value):
    return (json.dumps(value, ensure_ascii=False, separators=(',', ':')) + '\n').encode()


def load(path):
    return json.loads(Path(path).read_bytes())


def source(name):
    data = (ROOT / 'lineage' / name).read_bytes()
    need(sha(data) == SOURCE_HASHES[name], 'immutable_read_lineage_changed:' + name)
    return data.decode('utf-8')


def once(text, old, new):
    need(text.count(old) == 1, 'lineage_anchor_changed')
    return text.replace(old, new, 1)


def bind(text, definitions):
    # PHP JSON string literals require dollars to remain literal. Base64 carries
    # arbitrary JSON; every direct constant below is fixed identifier/hash/time.
    need(all(isinstance(v, str) and '$' not in v and '\\' not in v for v in definitions.values()), 'unsafe_php_constant')
    declarations = '\n'.join('define(' + json.dumps(k) + ',' + json.dumps(v, ensure_ascii=False) + ');'
                             for k, v in definitions.items())
    return once(text, 'declare(strict_types=1);', 'declare(strict_types=1);\n' + declarations)


def pin_surface(text):
    data = (ROOT / 'surface-pins.json').read_bytes()
    need(sha(data) == PIN_FILE_SHA256, 'reviewed_surface_pins_changed')
    pins = json.loads(data)
    old = dict(re.findall(r'"([^"\n]+)"\s*=>\s*"([a-f0-9]{40})"', text))
    need(len(pins) == 22 and set(old) == set(pins), 'publishing_surface_scope_changed')
    for path, previous in old.items():
        text = once(text, '"' + path + '" => "' + previous + '"', '"' + path + '" => "' + pins[path] + '"')
    return text


def manifest():
    data = (ROOT / 'manifest.json').read_bytes()
    need(sha(data) == MANIFEST_SHA256, 'exact_manifest_changed')
    d = json.loads(data)
    need(d.get('schema') == 1 and d.get('kind') == 'MAX13_AMP_READ_ONLY_SCOPE'
         and d.get('operation') == READ and d.get('approval_comment_id') == 7072422617
         and d.get('authorization_scope') == 'one_time_native_read_13_only'
         and [r.get('hotel_id') for r in d.get('rows', [])] == IDS, 'manifest_scope_changed')
    for key in ['publication_id', 'message_id', 'post_url']:
        need(len({row[key] for row in d['rows']}) == 13, 'duplicate_manifest_identity')
    for row in d['rows']:
        need(set(row) == {'hotel_id', 'title', 'publication_id', 'message_id', 'post_url', 'historical'}
             and type(row['publication_id']) is int and row['publication_id'] > 0
             and re.fullmatch(r'mid\.[0-9a-f]{32}', row['message_id'])
             and re.fullmatch(r'https://max\.ru/channel_anytour/[A-Za-z0-9_-]+', row['post_url']), 'manifest_identity_invalid')
        historical = row['historical']
        need(historical['receipt_source_commit'] == LINEAGE and historical['native_run_attempt'] == 1
             and type(historical['version_id']) is int and historical['version_id'] > 0
             and re.fullmatch('[a-f0-9]{64}', historical['text_sha256']), 'historical_evidence_invalid')
    return d


def timestamp(value):
    need(isinstance(value, str) and re.fullmatch(r'\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|\+00:00)', value),
         'observation_time_not_explicit_utc')
    return datetime.datetime.fromisoformat(value.replace('Z', '+00:00')).timestamp()


def fresh(value, now=None):
    stamp = timestamp(value)
    now = datetime.datetime.now(datetime.timezone.utc).timestamp() if now is None else now
    need(now - 900 <= stamp <= now + 60, 'native_observation_expired')
    return stamp


def validate_pin(pin):
    need(isinstance(pin, dict) and set(pin) == {'sha256', 'bytes', 'lines', 'collector_run_id', 'cycle_state'}, 'journal_pin_schema_changed')
    need(isinstance(pin['sha256'], str) and re.fullmatch('[a-f0-9]{64}', pin['sha256'])
         and type(pin['bytes']) is int and 0 < pin['bytes'] <= 2147483648
         and type(pin['lines']) is int and pin['lines'] > 0
         and isinstance(pin['collector_run_id'], str) and re.fullmatch('[0-9]+', pin['collector_run_id'])
         and isinstance(pin['cycle_state'], str) and pin['cycle_state'] != ''
         and 'unknown' not in pin['cycle_state'].lower(), 'journal_pin_invalid')
    return pin


def metadata(path, now=None):
    d = load(path)
    need(d.get('ok') is True and d.get('operation') == META and d.get('installed_sha') == APP
         and d.get('manifest_sha256') == MANIFEST_SHA256 and d.get('monitor_pending') is False,
         'native_metadata_binding_changed')
    need(all(type(d.get(k)) is int and d[k] == 0 for k in ZERO_FIELDS), 'metadata_mutation_or_provider_attempt')
    fresh(d.get('observed_at_utc'), now)
    return d, validate_pin(d.get('journal_pin'))


def metadata_body():
    tree = ast.parse(source('w11-approved-corrections/prepare-read-stage.py'))
    found = [ast.literal_eval(node.value) for node in tree.body if isinstance(node, ast.Assign)
             and any(isinstance(target, ast.Name) and target.id == 'METADATA_BODY' for target in node.targets)]
    need(len(found) == 1 and isinstance(found[0], str), 'metadata_lineage_body_missing')
    body = once(found[0], "'db_writes'=>0]", "'db_writes'=>0,'xml_writes'=>0,'filesystem_writes'=>0,'message_sends'=>0,'manifest_sha256'=>APPROVED_MANIFEST_SHA256]")
    body = once(body, "requireAudit(is_array($latest)&&array_key_exists('pending',$latest)",
                "requireAudit(is_array($latest)&&($latest['schema']??null)===1&&array_key_exists('pending',$latest)")
    return once(body, "$out['observed_at_utc']=gmdate('c');", "installedGuard();acceptedSurface();$out['observed_at_utc']=gmdate('c');")


def compile_metadata(out):
    manifest()
    text = source('commercial-caption-review73-20261113-read-scope.php')
    text = pin_surface(text[:text.index('function photoSignature(')]) + metadata_body()
    text = bind(text, {'EXPECTED_INSTALLED_SHA': APP, 'JOURNAL_METADATA_OPERATION': META,
                       'APPROVED_MANIFEST_SHA256': MANIFEST_SHA256})
    (out / 'journal-metadata.php').write_text(text, encoding='utf-8')
    return {'state': 'OFFLINE_COMPILED_NOT_EXECUTED', 'stage': 'metadata', 'installed_sha': APP,
            'manifest_sha256': MANIFEST_SHA256, 'script_sha256': sha(text.encode())}


def compile_read(journal_meta, out):
    manifest()
    meta, pin = metadata(journal_meta)
    template = source('commercial-caption-review73-20261113-read-scope.php')
    # Keep only the established guard/read helpers. The historical 73-row runner,
    # its old journal size cap and any mutation engine are never compiled.
    text = pin_surface(template[:template.index('/** Read only the exact original monitor journal')])
    helper = source('commercial-w9-78929-price-journal-20261118.php')
    helper = once(helper, "if (!is_array($latest) || !array_key_exists('pending', $latest)",
                  "if (!is_array($latest) || ($latest['schema'] ?? null) !== 1 || !array_key_exists('pending', $latest)")
    header = '<?php\ndeclare(strict_types=1);\n'
    need(helper.startswith(header), 'journal_helper_header_changed')
    body = (ROOT / 'read-body.php').read_text(encoding='utf-8')
    need(body.startswith('<?php\n'), 'read_body_header_changed')
    text += '\n' + helper[len(header):] + '\n' + body.removeprefix('<?php\n')
    manifest_bytes = (ROOT / 'manifest.json').read_bytes()
    text = bind(text, {'EXPECTED_INSTALLED_SHA': APP, 'MAX13_READ_OPERATION': READ,
        'APPROVED_MANIFEST_SHA256': MANIFEST_SHA256, 'MANIFEST_BASE64': base64.b64encode(manifest_bytes).decode(),
        'CAPTION_PRICE_PIN': json.dumps(pin, separators=(',', ':')),
        'MAX13_METADATA_OBSERVED_AT': meta['observed_at_utc']})
    (out / 'read.php').write_text(text, encoding='utf-8')
    (out / 'read-scope.json').write_bytes(manifest_bytes)
    return {'state': 'OFFLINE_COMPILED_NOT_EXECUTED', 'stage': 'read', 'installed_sha': APP,
            'manifest_sha256': MANIFEST_SHA256, 'metadata_sha256': sha(Path(journal_meta).read_bytes()),
            'script_sha256': sha(text.encode()), 'provider_methods': ['GET'], 'post_count': 13}


def validate_result(result, meta, now=None):
    approved = manifest()
    need(result.get('ok') is True and result.get('schema') == 1 and result.get('kind') == 'MAX13_NATIVE_READ_ONLY'
         and result.get('operation') == READ and result.get('installed_sha') == APP
         and result.get('manifest_sha256') == MANIFEST_SHA256 and result.get('approval_comment_id') == 7072422617,
         'native_result_binding_changed')
    need(result.get('provider_calls') == 13 and type(result['provider_calls']) is int
         and all(type(result.get(k)) is int and result[k] == 0 for k in ZERO_FIELDS if k != 'provider_calls'),
         'native_call_count_or_write_changed')
    need(result.get('journal_pin') == meta['journal_pin']
         and result.get('monitor_journal_sha256') == meta['journal_pin']['sha256']
         and result.get('monitor_pending') is False and result.get('monitor_journal_unchanged') is True
         and result.get('locks_released_before_return') is True, 'native_journal_or_lock_proof_changed')
    observed = fresh(result.get('observed_at_utc'), now)
    need(0 <= observed - timestamp(meta['observed_at_utc']) <= 900, 'native_metadata_read_interval_invalid')
    rows = result.get('posts')
    need(isinstance(rows, list) and len(rows) == result.get('post_count') == 13
         and [row.get('hotel_id') for row in rows] == IDS, 'native_scope_incomplete')
    counts = {'native-correct': 0, 'conditional_edit_candidate': 0, 'hold': 0}
    for row, expected in zip(rows, approved['rows']):
        need(set(row) == POST_FIELDS, 'native_output_fields_not_allowlisted')
        need(all(row.get(k) == expected[k] for k in ['hotel_id', 'title', 'publication_id', 'message_id', 'post_url'])
             and row['platform'] == 'max' and row['source_key'] == 'max_anytour_msk1'
             and row['status'] == 'sent' and row['native_http_status'] == 200 and row['provider_live_verified'] is True
             and row['snapshot_provenance'] == 'current_registry_and_live_max_get'
             and type(row['version_id']) is int and row['version_id'] > 0
             and type(row['config_version']) is int and row['config_version'] > 0, 'native_target_identity_changed')
        caption = row['caption']
        need(isinstance(caption, str) and caption and '\0' not in caption
             and sha(caption.encode()) == row['caption_sha256'], 'native_caption_hash_changed')
        literal = caption.count('&amp;')
        need(type(row['literal_amp_entity_count']) is int and row['literal_amp_entity_count'] == literal
             and type(row['ordinary_ampersand_count']) is int and row['ordinary_ampersand_count'] == caption.count('&') - literal
             and row['amp_status'] == ('native_literal_amp_entity' if literal else 'native_correct'), 'native_entity_classification_changed')
        for key in ['caption_sha256', 'registry_caption_sha256', 'attachments_sha256', 'registry_attachments_sha256',
                    'markup_sha256', 'registry_markup_sha256', 'button_url_sha256', 'historical_caption_sha256']:
            need(isinstance(row[key], str) and re.fullmatch('[a-f0-9]{64}', row[key]), 'native_hash_invalid')
        for key in ['native_registry_caption_match', 'native_registry_media_match', 'raw_attachment_hash_match',
                    'native_registry_markup_match', 'native_button_verified', 'registry_button_verified',
                    'button_resolves_to_current_post', 'matches_historical_caption']:
            need(type(row[key]) is bool, 'native_comparison_flag_invalid')
        need(row['historical_version_id'] == expected['historical']['version_id']
             and row['historical_caption_sha256'] == expected['historical']['text_sha256']
             and row['matches_historical_caption'] == (row['caption_sha256'] == row['historical_caption_sha256'])
             and row['native_registry_caption_match'] == (row['caption_sha256'] == row['registry_caption_sha256'])
             and row['raw_attachment_hash_match'] == (row['attachments_sha256'] == row['registry_attachments_sha256'])
             and (not row['native_registry_markup_match'] or row['markup_sha256'] == row['registry_markup_sha256']),
             'native_comparison_evidence_changed')
        need(row['button_text'] == '✈️ Узнать стоимость тура'
             and isinstance(row['hold_reasons'], list) and len(set(row['hold_reasons'])) == len(row['hold_reasons'])
             and set(row['hold_reasons']) <= HOLDS, 'native_hold_reason_invalid')
        held = row['hold_reasons'] != []
        expected_verdict = 'hold' if held else ('conditional_edit_candidate' if literal else 'native-correct')
        need(row['verdict'] == expected_verdict, 'native_verdict_changed')
        if not held:
            need(row['media_count'] == 1 and row['media_types'] == ['image']
                 and isinstance(row['photo_signature'], str) and re.fullmatch(r'max:[0-9]+', row['photo_signature'])
                 and row['photo_signature'] == row['registry_photo_signature']
                 and all(row[k] is True for k in ['native_registry_caption_match', 'native_registry_media_match',
                    'native_registry_markup_match', 'native_button_verified', 'registry_button_verified',
                    'button_resolves_to_current_post']), 'native_preservation_unverified')
        counts[expected_verdict] += 1
    need(result.get('counts') == counts, 'native_counts_changed')
    return {'state': 'MAX13_NATIVE_READ_ONLY_VERIFIED', 'ok': True, 'operation': READ, 'installed_sha': APP,
            'manifest_sha256': MANIFEST_SHA256, 'post_count': 13, 'counts': counts,
            'observed_at_utc': result['observed_at_utc'], 'provider_calls': 13,
            'post_write_attempts': 0, 'db_writes': 0, 'xml_writes': 0, 'retry_authorized': False}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('stage', choices=['metadata', 'read', 'verify'])
    parser.add_argument('--out', required=True, type=Path)
    parser.add_argument('--journal-meta', type=Path)
    parser.add_argument('--result', type=Path)
    args = parser.parse_args()
    args.out.mkdir(parents=True, exist_ok=True)
    if args.stage == 'metadata':
        proof = compile_metadata(args.out)
    else:
        need(args.journal_meta is not None, 'journal_metadata_required')
        if args.stage == 'read':
            proof = compile_read(args.journal_meta, args.out)
        else:
            need(args.result is not None, 'native_result_required')
            meta, _ = metadata(args.journal_meta)
            proof = validate_result(load(args.result), meta)
            proof.update(metadata_sha256=sha(args.journal_meta.read_bytes()), read_result_sha256=sha(args.result.read_bytes()))
            (args.out / 'verification.json').write_bytes(raw(proof))
    print(json.dumps(proof, ensure_ascii=False, separators=(',', ':')))


if __name__ == '__main__':
    main()
