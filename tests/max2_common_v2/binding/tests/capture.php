<?php
declare(strict_types=1);
require __DIR__.'/support.php';

$assertions = 0;
function captureCheck(bool $condition, string $label): void
{
    global $assertions; $assertions++;
    if (!$condition) throw new RuntimeException('ASSERTION_FAILED_'.$label);
}
function captureCount(PDO $db): int
{
    return (int)$db->query('SELECT COUNT(*) FROM max2_common_entry_frames_v2')->fetchColumn();
}
function captureDecoded(?array $row): array
{
    if ($row === null) throw new RuntimeException('SYNTHETIC_FRAME_MISSING');
    $decoded = Max2CommonFrameV2::decoded($row['frame_json'], $row['frame_sha256']);
    if ($decoded === null) throw new RuntimeException('SYNTHETIC_FRAME_INVALID');
    return $decoded;
}

$db = v2BindingTestDb(); $f = v2BindingFixture($db);
$e = (new Max2CommonEntryEvidenceV2($db, $f['map_file']))->read('41','29','91',$f['event_key']);
captureCheck(is_array($e) && $e['subject'] === $f['subject'] && $e['publication_id'] === $f['pair']['publication_id']
    && $e['offer_id'] === $f['pair']['offer_id'] && $e['entry_id'] === '41' && $e['version_id'] === '59', 'exact_actual_map_pair_and_native_subject');
captureCheck((new Max2CommonEntryEvidenceV2($db,$f['map_file']))->read('41','29','92',$f['event_key']) === null, 'wrong_actor_cannot_read_entry_evidence');
captureCheck((new Max2CommonEntryEvidenceV2($db,$f['map_file']))->read('41','30','91',$f['event_key']) === null, 'wrong_conversation_cannot_read_entry_evidence');
captureCheck((new Max2CommonEntryEvidenceV2($db,$f['map_file']))->read('41','29','91',hash('sha256','other update')) === null, 'different_start_event_not_same_entry');

// The actual public entry points must reject absent request authentication.
// Success cases below use Reflection only for a private, synthetic test seam.
// They do not establish a successful real HTTP authentication or live capture.
$savedServer = $_SERVER;
unset($_SERVER['HTTP_X_MAX_BOT_API_SECRET']); $_SERVER['REQUEST_METHOD'] = 'GET';
captureCheck(Max2CommonFrameV2::currentRequest('bot_started') === null, 'actual_http_auth_absent_has_no_request');
$public = (new Max2CommonEntryCaptureV2($db,$f['activation'],$f['map_file']))->captureCurrentHttp($f['record'],$f['conversation'],$f['resolved'],$f['event_key'],$f['entry_observed_at']);
captureCheck($public['status'] === 'HOLD' && captureCount($db) === 0, 'public_entry_capture_without_auth_holds');
$public = (new Max2CommonMessageCaptureV2($db,$f['activation']))->captureCurrentHttp($f['message_observed_at']);
captureCheck($public['status'] === 'HOLD' && captureCount($db) === 0, 'public_message_capture_without_auth_holds');
$_SERVER = $savedServer;

$r = v2BindingCaptureEntry($db,$f);
captureCheck($r['status'] === 'CAPTURED' && $r['frames_added'] === 1 && $r['provider_calls'] === 0 && captureCount($db) === 1, 'new_exact_entry_captured_once');
$entryRow = v2BindingFrameRow($db,'41'); $entryFrame = captureDecoded($entryRow);
captureCheck($entryFrame['origin'] === 'authenticated_max_post_entry_v2' && $entryFrame['entry'] == $e
    && $entryRow['entry_key'] === $f['entry_key'] && $entryRow['external_user_id'] === '91'
    && $entryRow['activation_sha256'] === $f['activation']['activation_sha256'], 'entry_readback_preserves_exact_private_binding');
$r = v2BindingCaptureEntry($db,$f,$f['entry_observed_at']+30);
captureCheck($r['status'] === 'ALREADY_CAPTURED' && v2BindingFrameRow($db,'41') === $entryRow, 'duplicate_entry_never_overwrites_time_or_evidence');
$repeat = $f; $repeat['record']['duplicate'] = true;
captureCheck(v2BindingCaptureEntry($db,$repeat)['status'] === 'HOLD' && v2BindingFrameRow($db,'41') === $entryRow, 'canonical_reused_receipt_not_new_capture');

foreach (['actor','conversation','resolved_version','record_version','resolved_publication','inactive','outer_transaction'] as $case) {
    $db = v2BindingTestDb(); $f = v2BindingFixture($db); $bad = $f;
    if ($case === 'actor') $bad['actor'] = '92';
    if ($case === 'conversation') $bad['conversation']['id'] = '30';
    if ($case === 'resolved_version') $bad['resolved']['version_id'] = '60';
    if ($case === 'record_version') $bad['record']['version_id'] = '60';
    if ($case === 'resolved_publication') $bad['resolved']['publication_id'] = '999999';
    if ($case === 'inactive') $bad['activation']['mode'] = 'off';
    if ($case === 'outer_transaction') $db->beginTransaction();
    captureCheck(v2BindingCaptureEntry($db,$bad)['status'] === 'HOLD' && captureCount($db) === 0, 'entry_rejects_'.$case);
    if ($db->inTransaction()) $db->rollBack();
}

foreach ([
    'foreign_customer'=>"UPDATE customer_channels SET customer_id=18 WHERE id=25",
    'foreign_project'=>"UPDATE projects SET project_key='other' WHERE id=1538",
    'foreign_platform'=>"UPDATE post_channels SET platform='telegram' WHERE id=31",
    'foreign_bot'=>"UPDATE post_channels SET bot_key='other_bot' WHERE id=31",
    'foreign_channel'=>"UPDATE post_channels SET channel_id='-999' WHERE id=31",
    'positive_chat'=>"UPDATE conversations SET external_chat_id='91' WHERE id=29",
    'test_subject'=>"UPDATE conversations SET is_test=1 WHERE id=29",
    'unavailable_entry'=>"UPDATE post_entries SET availability_reason='unavailable' WHERE id=41",
    'wrong_version_parent'=>"UPDATE post_versions SET publication_id=999 WHERE id=59"
] as $case=>$sql) {
    $db = v2BindingTestDb(); $f = v2BindingFixture($db); $db->exec($sql);
    captureCheck(v2BindingCaptureEntry($db,$f)['status'] === 'HOLD' && captureCount($db) === 0, 'entry_native_scope_'.$case);
}
$db = v2BindingTestDb(); $f = v2BindingFixture($db);
$q = $db->prepare('UPDATE post_versions SET snapshot_json=? WHERE id=59');
$q->execute([json_encode(['post_url'=>'https://example.invalid/other'],JSON_THROW_ON_ERROR)]);
captureCheck(v2BindingCaptureEntry($db,$f)['status'] === 'HOLD' && captureCount($db) === 0, 'map_pair_requires_exact_stored_post_url');

$db = v2BindingTestDb(); $f = v2BindingFixture($db); v2BindingCaptureEntry($db,$f);
$before = v2BindingFrameRow($db,'41'); $changedMap = tempnam(sys_get_temp_dir(),'m2-map-test-');
if (!is_string($changedMap)) throw new RuntimeException('SYNTHETIC_TEMP_FAILED');
try {
    if (!chmod($changedMap,0600) || file_put_contents($changedMap,file_get_contents($f['map_file'])."\n") === false) throw new RuntimeException('SYNTHETIC_TEMP_FAILED');
    $bad = $f; $bad['map_file'] = $changedMap;
    captureCheck(v2BindingCaptureEntry($db,$bad)['status'] === 'HOLD' && v2BindingFrameRow($db,'41') === $before, 'changed_map_cannot_mutate_existing_frame');
} finally { unlink($changedMap); }

// MySQL JSON may reorder object keys and add whitespace. The stored digest is
// over the canonical object, not the storage engine's current JSON text.
$decoded = captureDecoded($before);
$reorder = static function (mixed $v) use (&$reorder): mixed {
    if (!is_array($v)) return $v;
    if (!array_is_list($v)) $v = array_reverse($v,true);
    foreach ($v as &$item) $item = $reorder($item);
    unset($item); return $v;
};
$normalized = json_encode($reorder($decoded),JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
captureCheck($normalized !== $before['frame_json'] && hash('sha256',$normalized) !== $before['frame_sha256'], 'storage_normalization_changes_raw_json_bytes');
captureCheck(Max2CommonFrameV2::decoded($normalized,$before['frame_sha256']) == $decoded, 'canonical_digest_survives_mysql_style_json_normalization');
$q = $db->prepare('UPDATE max2_common_entry_frames_v2 SET frame_json=? WHERE entry_id=\'41\''); $q->execute([$normalized]);
captureCheck(captureDecoded(v2BindingFrameRow($db,'41')) == $decoded, 'canonical_frame_reconstructed_from_storage_readback');
$changed = $decoded; $changed['entry']['publication_id'] = '999';
captureCheck(Max2CommonFrameV2::decoded(json_encode($changed,JSON_THROW_ON_ERROR),$before['frame_sha256']) === null, 'changed_pair_content_rejected_by_digest');
$changed = $decoded; $changed['entry']['entry_id'] = 41;
captureCheck(Max2CommonFrameV2::decoded(json_encode($changed,JSON_THROW_ON_ERROR),$before['frame_sha256']) === null, 'string_id_type_is_immutable_across_json_reconstruction');
captureCheck(Max2CommonFrameV2::decoded('{broken',$before['frame_sha256']) === null, 'malformed_stored_frame_has_no_evidence');

$db = v2BindingTestDb(); $f = v2BindingFixture($db); v2BindingCaptureEntry($db,$f);
$r = v2BindingCaptureMessage($db,$f); $messageRow = v2BindingFrameRow($db,null); $message = captureDecoded($messageRow);
$entry = v2BindingFrameRow($db,'41');
captureCheck($r['status'] === 'CAPTURED' && captureCount($db) === 2 && $message['native_message_id'] === '97', 'native_message_has_its_own_frame');
captureCheck($message['entry_frame_key'] === $entry['frame_key'] && $message['prospective_entry_count_bound'] === 1, 'one_native_entry_links_exact_frozen_frame');
captureCheck($message['actor']['external_user_id'] === '91' && $message['actor']['external_chat_id'] === '-91'
    && $message['actor']['subject'] == $f['subject'] && $messageRow['entry_id'] === null && $messageRow['publication_id'] === null
    && $messageRow['version_id'] === null && $messageRow['entry_key'] === null, 'message_frame_stays_distinct_from_publication_record');
captureCheck($message['native_external_message_sha256'] === hash('sha256',$f['external_message_id'])
    && !str_contains($messageRow['frame_json'],$f['external_message_id']), 'frame_keeps_mid_digest_not_raw_message_identity');
$f2 = v2BindingAddEntry($db,$f); v2BindingCaptureEntry($db,$f2,$f['message_observed_at']+1);
$r = v2BindingCaptureMessage($db,$f,$f['message_observed_at']+2);
captureCheck($r['status'] === 'ALREADY_CAPTURED' && v2BindingFrameRow($db,null) === $messageRow, 'later_entry_does_not_rewrite_earlier_message_frame');

$db = v2BindingTestDb(); $f = v2BindingFixture($db,false);
captureCheck(v2BindingCaptureMessage($db,$f)['status'] === 'CAPTURED', 'nonpost_message_frame_is_supported');
$message = captureDecoded(v2BindingFrameRow($db,null));
captureCheck($message['entry_frame_key'] === null && $message['prospective_entry_count_bound'] === 0, 'no_entry_is_not_an_invented_publication_binding');

$db = v2BindingTestDb(); $f = v2BindingFixture($db);
v2BindingCaptureMessage($db,$f); $message = captureDecoded(v2BindingFrameRow($db,null));
captureCheck($message['entry_frame_key'] === null && $message['prospective_entry_count_bound'] === 1, 'uncaptured_entry_is_not_positive_binding');

$db = v2BindingTestDb(); $f = v2BindingFixture($db); v2BindingCaptureEntry($db,$f);
$f2 = v2BindingAddEntry($db,$f); v2BindingCaptureEntry($db,$f2,$f['entry_observed_at']+10);
v2BindingCaptureMessage($db,$f); $message = captureDecoded(v2BindingFrameRow($db,null));
captureCheck($message['entry_frame_key'] === null && $message['prospective_entry_count_bound'] === 2, 'multiple_entries_never_pick_latest_or_first');

$db = v2BindingTestDb(); $f = v2BindingFixture($db); v2BindingCaptureEntry($db,$f);
$f2 = v2BindingAddEntry($db,$f); $db->exec("UPDATE post_entries SET publication_id=NULL,version_id=NULL,availability_reason='unavailable' WHERE id=43");
v2BindingCaptureMessage($db,$f); $message = captureDecoded(v2BindingFrameRow($db,null));
captureCheck($message['entry_frame_key'] === null && $message['prospective_entry_count_bound'] === 2, 'unavailable_competing_entry_still_prevents_unique_binding');

// The pinned canonical Registry writes explicit UTC created_at in both entry
// paths. These fixtures cover that source contract, not a DB session timezone.
$db = v2BindingTestDb(); $f = v2BindingFixture($db); v2BindingCaptureEntry($db,$f);
v2BindingAddEntry($db,$f,'43',gmdate('Y-m-d H:i:s',$f['activation']['activated_at_unix']-1));
v2BindingCaptureMessage($db,$f); $message = captureDecoded(v2BindingFrameRow($db,null));
captureCheck($message['entry_frame_key'] === v2BindingFrameRow($db,'41')['frame_key'] && $message['prospective_entry_count_bound'] === 1, 'old_entry_outside_prospective_utc_floor_not_rebound');

foreach (['sender_other','sender_negative_chat','sender_missing','mid_case','wrong_actor','test_subject','foreign_channel_customer','outbound'] as $case) {
    $db = v2BindingTestDb(); $f = v2BindingFixture($db); $bad = $f;
    if ($case === 'sender_other') $db->exec("UPDATE messages SET sender_id='92' WHERE id=97");
    if ($case === 'sender_negative_chat') $db->exec("UPDATE messages SET sender_id='-91' WHERE id=97");
    if ($case === 'sender_missing') $db->exec('UPDATE messages SET sender_id=NULL WHERE id=97');
    if ($case === 'mid_case') $bad['external_message_id'] = strtolower($f['external_message_id']);
    if ($case === 'wrong_actor') $bad['actor'] = '92';
    if ($case === 'test_subject') $db->exec('UPDATE conversations SET is_test=1 WHERE id=29');
    if ($case === 'foreign_channel_customer') $db->exec('UPDATE customer_channels SET customer_id=18 WHERE id=25');
    if ($case === 'outbound') $db->exec("UPDATE messages SET direction='outbound',sender_type='bot' WHERE id=97");
    captureCheck(v2BindingCaptureMessage($db,$bad)['status'] === 'HOLD' && captureCount($db) === 0, 'message_rejects_'.$case);
}

$db = v2BindingTestDb(); $f = v2BindingFixture($db);
$db->exec('INSERT INTO messages SELECT 98,conversation_id,channel,direction,sender_type,sender_id,external_message_id,metadata_json,created_at FROM messages WHERE id=97');
captureCheck(v2BindingCaptureMessage($db,$f)['status'] === 'HOLD' && captureCount($db) === 0, 'ambiguous_native_message_id_has_no_latest_fallback');

// The DB committed, but the acknowledgement was lost. A retry is inspection,
// never an overwrite or a second capture. This is not a real concurrency test.
$db = v2BindingTestDb(); $f = v2BindingFixture($db); $db->loseCommit = 1;
$r = v2BindingCaptureEntry($db,$f); $row = v2BindingFrameRow($db,'41');
captureCheck($r['status'] === 'UNKNOWN' && $r['frames_added'] === null && $row !== null, 'entry_lost_commit_is_unknown_with_durable_frame');
captureCheck(v2BindingCaptureEntry($db,$f,$f['entry_observed_at']+20)['status'] === 'ALREADY_CAPTURED'
    && v2BindingFrameRow($db,'41') === $row, 'entry_commit_unknown_cannot_overwrite_on_readback');
$db = v2BindingTestDb(); $f = v2BindingFixture($db,false); $db->loseCommit = 1;
$r = v2BindingCaptureMessage($db,$f); $row = v2BindingFrameRow($db,null);
captureCheck($r['status'] === 'UNKNOWN' && $r['frames_added'] === null && $row !== null, 'message_lost_commit_is_unknown_with_durable_frame');
captureCheck(v2BindingCaptureMessage($db,$f,$f['message_observed_at']+20)['status'] === 'ALREADY_CAPTURED'
    && v2BindingFrameRow($db,null) === $row, 'message_commit_unknown_cannot_overwrite_on_readback');

echo 'PASS ', $assertions, " synthetic SQLite capture assertions; Reflection is fixture-only, successful real HTTP authentication is NOT tested; no provider or live DB calls\n";
