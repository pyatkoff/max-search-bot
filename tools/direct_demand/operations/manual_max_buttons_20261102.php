<?php
declare(strict_types=1);
// Owner-authorized, fixed two-post repair. Stream over SSH; never install in webroot.
if (PHP_SAPI !== 'cli') exit(2);
ini_set('display_errors', '0'); ini_set('log_errors', '0'); error_reporting(E_ALL);
set_error_handler(static function (): void { throw new RuntimeException('runtime_error'); });
umask(0077); ob_start();
const REPAIR_ROOT = '/var/www/anytoour/data/www/app.anytoour.ru';
const REPAIR_SHA = '9c5be6ba829e5083fd27f377887121d58492bf54';
const REPAIR_JOURNAL = '/var/www/anytoour/data/www/max-search-bot-private-logs/manual-max-buttons-20261102';
const REPAIR_TARGETS = [
    'FIRST' => ['channel'=>'-72182945320979','mid'=>'mid.ffffbe59992f6fed01a19c17752265c8','published_ms'=>'1793620145442'],
    'SECOND' => ['channel'=>'-68765999669267','mid'=>'mid.ffffc1752b045fed01a19c183f535af2','published_ms'=>'1793620197203'],
];
final class ManualMaxRepairFailure extends RuntimeException {}
$target = getenv('MAX_REPAIR_TARGET') ?: '';
$result = ['schema'=>'anytour.manual_max_buttons.v1','target'=>$target,'ok'=>false,'state'=>'requires_attention','database_writes'=>0,'write_attempts'=>0,'provider_gets'=>0,'installed_sha'=>REPAIR_SHA];
$pdo = null; $journal = null;
try {
    repairCheck(isset(REPAIR_TARGETS[$target]), 'invalid_fixed_target'); $t = REPAIR_TARGETS[$target];
    require_once REPAIR_ROOT.'/config.php';
    require_once REPAIR_ROOT.'/services/ConversationDb.php';
    $pdo = ConversationDb::connection();
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $pdo->exec('START TRANSACTION READ ONLY');
    require_once REPAIR_ROOT.'/services/ChannelPostRuntime.php';
    require_once REPAIR_ROOT.'/services/ChannelPostButtonTransport.php';
    $identity = ChannelPostRuntime::identity('max'); repairCheck(is_array($identity), 'canonical_identity_unavailable');
    [$channel,$row] = repairRegistry($pdo,$identity,$t);
    $url = ChannelPostButtonTransport::buttonUrl(['start_payload'=>$row['start_payload'],'channel'=>$channel]);
    $label = (string)$channel['button_text'];
    repairCheck($url !== '' && $label !== '' && mb_strlen($label,'UTF-8') <= 128, 'invalid_configured_button');
    repairCheck(rtrim((string)$channel['bot_url'],'/') === 'https://max.ru/'.$identity['bot_key'], 'canonical_bot_url_mismatch');
    $before = repairRead($t); $snapshot = ChannelPostIncomingAdapter::maxSnapshot($before);
    repairCheck(is_array($snapshot) && ($snapshot['metadata']['capability'] ?? '') === 'supported', 'fresh_snapshot_invalid');
    [$video,$keyboardCount] = repairMedia($before);
    $old = json_decode((string)$row['snapshot_json'],true);
    $raw = is_array($old['raw_body'] ?? null) ? $old['raw_body'] : [];
    $oldVideo = null;
    foreach (($raw['body']['attachments'] ?? []) as $a) if (($a['type'] ?? '') === 'video') $oldVideo = $a['payload']['token'] ?? null;
    $result += ['publication_id'=>(int)$row['publication_id'],'version_id'=>(int)$row['current_version_id'],'config_version'=>(int)$channel['config_version'],'published_ms'=>(int)$t['published_ms'],'media_count'=>1,'video_count'=>1,
        'old_vs_fresh'=>['text_equal'=>($raw['body']['text'] ?? null) === ($before['body']['text'] ?? null),'markup_equal'=>($raw['body']['markup'] ?? null) === ($before['body']['markup'] ?? null),'link_equal'=>($raw['link'] ?? null) === ($before['link'] ?? null),'video_token_equal'=>$oldVideo === $video],
        'before_content_hash'=>ChannelPostIncomingAdapter::contentFingerprint($snapshot)];
    if (ChannelPostIncomingAdapter::containsOnlyButton($snapshot,$url,$label)) {
        $result['ok']=true; $result['state']='sent'; $result['reason']='already_present'; $result['button_verified']=true; $result['content_preserved']=true;
    } else {
        repairCheck($keyboardCount === 0, 'unexpected_existing_keyboard');
        // READ COMMITTED rechecks the same original version/config immediately before the one write.
        [$channelNow,$rowNow] = repairRegistry($pdo,$identity,$t);
        repairCheck($channelNow === $channel && $rowNow === $row, 'registry_or_config_changed');
        $base = dirname(REPAIR_JOURNAL);
        repairCheck(!is_link($base) && !is_link(REPAIR_JOURNAL), 'unsafe_journal_path');
        if (!is_dir(REPAIR_JOURNAL)) repairCheck(mkdir(REPAIR_JOURNAL,0700,true), 'journal_directory_unavailable');
        $dir = realpath(REPAIR_JOURNAL); $app = realpath(REPAIR_ROOT);
        repairCheck(is_string($dir) && is_string($app) && $dir !== $app && !str_starts_with($dir,$app.'/') && (fileperms($dir)&0777) === 0700, 'unsafe_journal_directory');
        $path = REPAIR_JOURNAL.'/'.$target.'.jsonl';
        repairCheck(!file_exists($path) && !is_link($path), 'one_shot_intent_already_exists');
        $journal = fopen($path,'x'); repairCheck(is_resource($journal) && (fstat($journal)['mode']&0777) === 0600, 'one_shot_intent_unavailable');
        repairJournal($journal,['kind'=>'intent','target'=>$target,'created_at_utc'=>gmdate('c'),'installed_sha'=>REPAIR_SHA,'message_id_hash'=>hash('sha256',$t['mid']),'publication_id'=>$result['publication_id'],'version_id'=>$result['version_id'],'config_version'=>$result['config_version'],'before_content_hash'=>$result['before_content_hash']]);
        $result['state']='unknown'; $result['write_attempts']=1;
        $body = ['attachments'=>[['type'=>'video','payload'=>['token'=>$video]],['type'=>'inline_keyboard','payload'=>['buttons'=>[[['type'=>'link','text'=>$label,'url'=>$url]]]]]]];
        $put = [];
        try { $put = ChannelPostApiTransport::buttonRequest('max','PUT','/messages',['message_id'=>$t['mid']],$body); }
        catch (Throwable $e) { $result['put_transport_exception']=true; }
        $result['put_http']=(int)($put['http'] ?? 0);
        // Always one readback after the sole PUT, including a lost transport response. Never retry.
        $after = repairRead($t); $afterSnapshot = ChannelPostIncomingAdapter::maxSnapshot($after);
        [$afterVideo,$afterKeyboardCount] = repairMedia($after);
        $result['content_preserved'] = $video === $afterVideo
            && ($before['body']['text'] ?? null) === ($after['body']['text'] ?? null)
            && ($before['body']['markup'] ?? null) === ($after['body']['markup'] ?? null)
            && ($before['body']['format'] ?? null) === ($after['body']['format'] ?? null)
            && ($before['link'] ?? null) === ($after['link'] ?? null);
        $result['button_verified'] = is_array($afterSnapshot) && $afterKeyboardCount === 1 && ChannelPostIncomingAdapter::containsOnlyButton($afterSnapshot,$url,$label);
        $result['ok'] = $result['content_preserved'] && $result['button_verified'];
        $result['state'] = $result['ok'] ? 'sent' : 'unknown';
        $result['reason'] = $result['ok'] ? 'manual_button_verified' : 'manual_edit_outcome_unknown';
    }
} catch (Throwable $e) {
    $result['reason'] = $e instanceof ManualMaxRepairFailure ? $e->getMessage() : 'manual_repair_unavailable';
}
if ($pdo instanceof PDO && $pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable $e) {} }
$result['finished_at_utc']=gmdate('c');
if (is_resource($journal)) {
    try { repairJournal($journal,['kind'=>'receipt','result'=>$result]); $result['journal_receipt_persisted']=true; }
    catch (Throwable $e) { $result['journal_receipt_persisted']=false; }
    fclose($journal);
}
if (ob_get_level()) ob_end_clean();
echo json_encode($result,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL;
exit($result['ok'] ? 0 : 1);

function repairCheck(bool $ok,string $reason): void { if (!$ok) throw new ManualMaxRepairFailure($reason); }
function repairRegistry(PDO $pdo,array $identity,array $target): array
{
    $q=$pdo->prepare("SELECT pc.id,pc.channel_id,pc.bot_url,pc.button_text,pc.is_enabled,pc.config_version FROM post_channels pc JOIN projects p ON p.id=pc.project_id WHERE p.project_key=? AND p.is_active=1 AND pc.platform='max' AND pc.bot_key=? AND pc.channel_id=? LIMIT 2");
    $q->execute([$identity['project_key'],$identity['bot_key'],$target['channel']]); $channels=$q->fetchAll(PDO::FETCH_ASSOC);
    repairCheck(count($channels)===1 && (int)$channels[0]['is_enabled']===1,'configured_channel_unavailable'); $channel=$channels[0];
    $q=$pdo->prepare("SELECT pp.id AS publication_id,pp.current_version_id,pp.start_payload,pv.state,pv.error_reason,pv.write_attempts,pv.snapshot_json,(pv.claim_token IS NOT NULL OR pv.claimed_at IS NOT NULL) AS claim_exists,EXISTS(SELECT 1 FROM post_versions v WHERE v.publication_id=pp.id AND (v.state IN ('unknown','claimed','write_started') OR v.claim_token IS NOT NULL OR v.claimed_at IS NOT NULL)) AS unsafe_version_exists FROM post_publications pp JOIN post_versions pv ON pv.id=pp.current_version_id AND pv.publication_id=pp.id WHERE pp.post_channel_id=? AND pp.external_message_id=? LIMIT 2");
    $q->execute([(int)$channel['id'],$target['mid']]); $rows=$q->fetchAll(PDO::FETCH_ASSOC); repairCheck(count($rows)===1,'registry_target_unavailable'); $row=$rows[0];
    repairCheck($row['state']==='requires_attention' && $row['error_reason']==='unsupported_media' && (int)$row['write_attempts']===0 && !(bool)$row['claim_exists'] && !(bool)$row['unsafe_version_exists'],'registry_not_zero_write_unsupported_media');
    return [$channel,$row];
}
function repairRead(array $target): array
{
    global $result; $result['provider_gets']++;
    $r=ChannelPostApiTransport::buttonRequest('max','GET','/messages/'.rawurlencode($target['mid']),[],null);
    repairCheck(empty($r['transport_error']) && (int)($r['http'] ?? 0)===200 && is_array($r['data'] ?? null),'provider_read_unavailable'); $m=$r['data'];
    repairCheck(($m['body']['mid'] ?? '')===$target['mid'] && ($m['recipient']['chat_type'] ?? '')==='channel' && (string)($m['recipient']['chat_id'] ?? '')===$target['channel'] && (string)($m['timestamp'] ?? '')===$target['published_ms'],'provider_target_identity_mismatch');
    return $m;
}
function repairMedia(array $message): array
{
    $attachments=$message['body']['attachments'] ?? null; repairCheck(is_array($attachments),'invalid_attachments'); $videos=[]; $keyboards=0;
    foreach ($attachments as $a) {
        repairCheck(is_array($a) && is_array($a['payload'] ?? null),'invalid_attachment');
        if (($a['type'] ?? '')==='inline_keyboard') { $keyboards++; continue; }
        repairCheck(($a['type'] ?? '')==='video' && ChannelPostIncomingAdapter::maxVideoTokenValid($a['payload']['token'] ?? null),'unexpected_media'); $videos[]=$a['payload']['token'];
    }
    repairCheck(count($videos)===1 && $keyboards<=1,'unexpected_media_count'); return [$videos[0],$keyboards];
}
function repairJournal($file,array $entry): void
{
    $json=json_encode($entry,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
    repairCheck(fwrite($file,$json)===strlen($json) && fflush($file),'journal_write_failed');
    if (function_exists('fsync')) repairCheck(fsync($file),'journal_sync_failed');
}
