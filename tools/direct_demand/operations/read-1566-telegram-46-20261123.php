<?php
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('log_errors', '0');

// Existing #1566 reader, restricted to the ranked batch of candidate pairs.
// This program has no apply mode, publication registration or provider mutation.
const APP = '/var/www/anytoour/data/www/app.anytoour.ru';
const OPERATIONS = '/var/www/anytoour/data/channel-post-operations';
const APPLICATION_SHA = '__APPLICATION_SHA__';
const REPOSITORY_SHA = '__REPOSITORY_SHA__';
const NATIVE_RUN_ID = '__NATIVE_RUN_ID__';
function requireRead(bool $ok, string $reason): void
{
    if (!$ok) throw new RuntimeException($reason);
}
function jsonRead(array $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
function reasonRead(Throwable $e): string
{
    return get_class($e) === RuntimeException::class
        && preg_match('/\A[a-z_]{1,100}\z/D', $e->getMessage()) === 1 ? $e->getMessage() : 'read_evidence_unavailable';
}
function publicRetryLimit(?string $raw, int $now): array
{
    if ($raw !== null && (strlen($raw)>160 || preg_match('/\A[\x20-\x7E]*\z/D',$raw)!==1)) $raw=null;
    $seconds = null;
    if ($raw !== null && preg_match('/\A[0-9]{1,8}\z/D',$raw) === 1) $seconds=(int)$raw;
    elseif ($raw !== null) {
        $when=DateTimeImmutable::createFromFormat(DateTimeInterface::RFC7231,$raw,new DateTimeZone('UTC'));
        if ($when!==false && $when->format(DateTimeInterface::RFC7231)===$raw) $seconds=max(0,$when->getTimestamp()-$now);
    }
    return ['observed_at_utc'=>gmdate(DATE_ATOM,$now), 'retry_after_raw'=>$raw,
        'retry_after_seconds'=>$seconds, 'resume_not_before_unix'=>$seconds===null?null:$now+$seconds];
}
/** Bounded host observations; never open Manager2 or return process arguments. */
function resourcesRead(): array
{
    $result = ['observed_at_utc'=>gmdate(DATE_ATOM), 'scope'=>'same_readonly_preparation_not_manager_load_test'];
    $memory = @file_get_contents('/proc/meminfo', false, null, 0, 32768);
    foreach (['MemTotal','MemAvailable','SwapTotal','SwapFree'] as $name) {
        $result['memory_kb'][$name] = is_string($memory) && preg_match('/^'. $name .':\s+([0-9]+) kB$/m', $memory, $m) ? (int)$m[1] : null;
    }
    $stat = @file_get_contents('/proc/stat', false, null, 0, 32768);
    $result['cpu_count']=is_string($stat)?preg_match_all('/^cpu[0-9]+\s/m',$stat):null;
    $result['cpu_ticks'] = is_string($stat) && preg_match('/^cpu\s+([0-9 ]+)$/m',$stat,$m)
        ? array_map('intval', preg_split('/\s+/',trim($m[1]))) : null;
    $load = @file_get_contents('/proc/loadavg', false, null, 0, 256);
    $result['load_average'] = is_string($load) && preg_match('/^([0-9.]+) ([0-9.]+) ([0-9.]+) /',$load,$m)
        ? [(float)$m[1],(float)$m[2],(float)$m[3]] : null;
    $result['processes'] = ['php_fpm'=>['observed'=>0,'rss_kb'=>0], 'mysql'=>['observed'=>0,'rss_kb'=>0]];
    $paths = glob('/proc/[0-9]*/comm');
    if (is_array($paths) && count($paths) <= 8192) foreach ($paths as $path) {
        $name = @file_get_contents($path, false, null, 0, 128);
        $kind = is_string($name) && preg_match('/\Aphp-fpm[0-9.]*\n?\z/D',$name) ? 'php_fpm'
            : (is_string($name) && in_array(trim($name),['mysqld','mariadbd'],true) ? 'mysql' : null);
        if ($kind === null) continue;
        $result['processes'][$kind]['observed']++;
        $status = @file_get_contents(dirname($path).'/status', false, null, 0, 16384);
        if (is_string($status) && preg_match('/^VmRSS:\s+([0-9]+) kB$/m',$status,$m)) $result['processes'][$kind]['rss_kb'] += (int)$m[1];
    }
    $result['process_inventory_readable'] = is_array($paths) && count($paths) <= 8192;
    return $result;
}

function mysqlResourcesRead(PDO $pdo): array
{
    try {
        $rows = $pdo->query("SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_connected','Threads_running','Max_used_connections','Innodb_row_lock_current_waits','Innodb_row_lock_time','Innodb_row_lock_waits')")->fetchAll(PDO::FETCH_ASSOC);
        $values = [];
        foreach ($rows as $row) if (preg_match('/\A[0-9]+\z/D',(string)$row['Value'])) $values[$row['Variable_name']] = (int)$row['Value'];
        return ['state'=>'observed','values'=>$values];
    } catch (Throwable $e) { return ['state'=>'unavailable']; }
}

function serverReadBudgetRead(array $resources): array
{
    $cpus=$resources['cpu_count']??null;$load=$resources['load_average'][0]??null;
    $known=is_int($cpus)&&$cpus>0&&$cpus<=1024&&(is_float($load)||is_int($load))&&is_finite((float)$load)&&$load>=0;
    $limit=$known?1.5*$cpus:null;
    return ['allowed'=>$known&&$load<=$limit,'cpu_count'=>$cpus,'load_one_minute'=>$load,
        'max_load_one_minute'=>$limit,'reason'=>$known&&$load<=$limit?'bounded_read_allowed':'server_resource_hold',
        'write_admission'=>false,'public_preparation_on_runner_independent'=>true];
}

function runtimeRead(): void
{
    requireRead(PHP_SAPI === 'cli' && realpath(APP) === APP && getcwd() === APP, 'application_root_changed');
    foreach ([
        'GIT_OPTIONAL_LOCKS=0 git -c core.fsmonitor=false rev-parse HEAD' => APPLICATION_SHA,
        'GIT_OPTIONAL_LOCKS=0 git -c core.fsmonitor=false status --porcelain --untracked-files=no' => '',
    ] as $command => $expected) {
        $lines = [];
        exec($command . ' 2>/dev/null', $lines, $status);
        requireRead($status === 0 && implode("\n", $lines) === $expected, 'installed_application_changed');
    }
}


function albumStructureRead(DOMDocument $dom, DOMXPath $xp, DOMNode $node, string $url, string $message, string $body): array
{
    $cls=static fn(string $c):string=>'contains(concat(" ",normalize-space(@class)," ")," '.$c.' ")';
    $texts=$xp->query('.//*['.$cls('tgme_widget_message_text').']',$node);
    $photos=$xp->query('.//*['.$cls('tgme_widget_message_photo_wrap').']',$node);
    $other=$xp->query('.//video|.//audio|.//*['.$cls('tgme_widget_message_video_thumb').' or '.$cls('tgme_widget_message_roundvideo').']',$node);
    $videos=$xp->query('.//video|.//*['.$cls('tgme_widget_message_video_thumb').']',$node);
    $roundVideos=$xp->query('.//*['.$cls('tgme_widget_message_roundvideo').']',$node);
    $times=$xp->query('.//time[@datetime]',$node);
    $links=$xp->query('.//a[@href]',$node);
    requireRead($texts&&$photos&&$other&&$times&&$links&&$texts->length<=6&&$photos->length<=6&&$other->length<=6&&$times->length<=6&&$links->length<=100,'album_structure_unbounded');
    $captions=[];foreach($texts as $text){$html='';foreach($text->childNodes as $child)$html.=$dom->saveHTML($child);
        $plain=html_entity_decode(strip_tags(preg_replace('~<br\s*/?>~i',"\n",$html)),ENT_QUOTES|ENT_HTML5,'UTF-8');
        requireRead(mb_check_encoding($plain,'UTF-8')&&mb_strlen($plain,'UTF-8')<=12000,'album_text_unbounded');
        $captions[]=['text'=>$plain,'sha256'=>hash('sha256',$plain),'owner_data_post'=>$node->getAttribute('data-post')];}
    $dates=[];foreach($times as $time)$dates[]=$time->getAttribute('datetime');
    $members=[];$buttons=[];foreach($links as $link){$href=$link->getAttribute('href');
        if(preg_match('~\Ahttps://t\.me/anytour/(931|932|933|934|935|936)(?:\?[^\s<>]*)?\z~D',$href,$m))$members[]=$m[1];
        if(str_contains(' '.$link->getAttribute('class').' ',' tgme_widget_message_inline_button '))$buttons[]=['text'=>mb_substr(trim($link->textContent),0,180),'url_sha256'=>hash('sha256',$href)];}
    $caption=count($captions)===1?$captions[0]['text']:null;
    return ['url'=>$url,'caption'=>$caption,'caption_sha256'=>$caption===null?null:hash('sha256',$caption),'html_sha256'=>hash('sha256',$body),
        'published_at'=>count($dates)===1?$dates[0]:null,'published_unixtime'=>count($dates)===1?(new DateTimeImmutable($dates[0]))->getTimestamp():null,
        'single_photo'=>false,'photo_url'=>'','photo_marker_sha256'=>null,'photo_count'=>$photos->length,'other_media_count'=>$other->length,
        'video_count'=>$videos->length,'roundvideo_count'=>$roundVideos->length,'buttons'=>$buttons,
        'format_observation'=>['requested_mid'=>$message,'root_data_post'=>$node->getAttribute('data-post'),'caption_nodes'=>$captions,'dates'=>$dates,
            'linked_album_mids'=>array_values(array_unique($members)),'caption_mid_confirmed'=>false,'native_album_membership_confirmed'=>false,'keyboard_admission_confirmed'=>false]];
}

function parsePublicRead(string $body, string $channel, string $message): array
{
    $url = 'https://t.me/' . $channel . '/' . $message;
    $doc = new DOMDocument('1.0', 'UTF-8');
    $prior = libxml_use_internal_errors(true);
    try { $loaded = $doc->loadHTML($body, LIBXML_NONET); }
    finally { libxml_clear_errors(); libxml_use_internal_errors($prior); }
    requireRead($loaded, 'public_post_invalid');
    $xp = new DOMXPath($doc);
    $nodes = $xp->query('//*[@data-post="' . $channel . '/' . $message . '"]');
    requireRead($nodes && $nodes->length === 1, 'public_identity_changed');
    $node = $nodes->item(0);
    if($channel==='anytour' && in_array($message,['931','932','933','934','935','936'],true)) return albumStructureRead($doc,$xp,$node,$url,$message,$body);
    $cls = static fn(string $c): string => 'contains(concat(" ",normalize-space(@class)," ")," ' . $c . ' ")';
    $text = $xp->query('.//*[' . $cls('tgme_widget_message_text') . ']', $node);
    $photos = $xp->query('.//*[' . $cls('tgme_widget_message_photo_wrap') . ']', $node);
    $dates = $xp->query('.//time[@datetime]', $node);
    $other = $xp->query('.//video|.//audio|.//*[' . $cls('tgme_widget_message_video_thumb')
        . ' or ' . $cls('tgme_widget_message_roundvideo') . ' or ' . $cls('tgme_widget_message_grouped') . ']', $node);
    $videos = $xp->query('.//video|.//*['.$cls('tgme_widget_message_video_thumb').']', $node);
    $roundVideos = $xp->query('.//*['.$cls('tgme_widget_message_roundvideo').']', $node);
    requireRead($text && $photos && $dates && $other && $text->length === 1 && $dates->length === 1,
        'public_caption_or_date_unavailable');
    $html = '';
    foreach ($text->item(0)->childNodes as $child) $html .= $doc->saveHTML($child);
    $caption = html_entity_decode(strip_tags(preg_replace('~<br\s*/?>~i', "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    requireRead($caption !== '' && mb_check_encoding($caption, 'UTF-8') && mb_strlen($caption, 'UTF-8') <= 12000,
        'public_caption_invalid');
    $single = $photos->length === 1 && $other->length === 0;
    $photo = $single ? $photos->item(0) : null;
    $marker = $photo ? trim($photo->getAttribute('class')) : '';
    $photoUrl = '';
    if ($photo && $photo->getAttribute('href') === $url
        && preg_match('/(?:^|\s)[0-9]{5,}(?:_[0-9]+)?(?:\s|$)/D', $marker) === 1
        && preg_match('~background-image:\s*url\([\'"]?(https://[^\'")]+)[\'"]?\)~', $photo->getAttribute('style'), $match) === 1) {
        $parsed = parse_url($match[1]);
        if (is_array($parsed) && ($parsed['scheme'] ?? '') === 'https'
            && preg_match('/\A(?:[a-z0-9-]+\.)*(?:cdn-telegram\.org|telegram-cdn\.org|telesco\.pe)\z/D', $parsed['host'] ?? '') === 1
            && !isset($parsed['user']) && !isset($parsed['pass']) && !isset($parsed['port'])) $photoUrl = $match[1];
    }
    $buttons = [];
    $links = $xp->query('.//a[' . $cls('tgme_widget_message_inline_button') . ']', $node);
    requireRead($links && $links->length <= 20, 'public_keyboard_unbounded');
    foreach ($links as $link) {
        $href = $link->getAttribute('href');
        $buttons[] = ['text' => trim($link->textContent),
            'hotel_url' => preg_match('~\Ahttps://t\.me/anyru_bot\?start=p_[A-Za-z0-9_-]{8,60}\z~D', $href) === 1 ? $href : null,
            'url_sha256' => hash('sha256', $href)];
    }
    $captionLinks = [];
    $anchors = $xp->query('.//a[@href]', $text->item(0));
    if ($anchors) foreach ($anchors as $anchor) {
        if (count($captionLinks) >= 40) break;
        $href = $anchor->getAttribute('href');
        $captionLinks[] = ['text'=>mb_substr(trim($anchor->textContent),0,180),
            'url_sha256'=>hash('sha256',$href),
            'public_bot_url'=>preg_match('~\\Ahttps://t\\.me/anyru_bot\\?start=(?:tg_anytour_msk|p_[A-Za-z0-9_-]{8,60})\\z~D',$href) === 1 ? $href : null];
    }
    $documentAnchors = [];
    $documentLinks = $xp->query('//a[@href]');
    if ($documentLinks) foreach ($documentLinks as $anchor) {
        if (count($documentAnchors) >= 80) break;
        $href = $anchor->getAttribute('href');
        $documentAnchors[] = ['text'=>mb_substr(trim($anchor->textContent),0,180),
            'class'=>substr($anchor->getAttribute('class'),0,256), 'url_sha256'=>hash('sha256',$href),
            'public_start_url'=>preg_match('~\Ahttps://t\.me/[A-Za-z0-9_]{5,32}\?start=[A-Za-z0-9_-]{1,128}\z~D',$href)===1 ? $href : null];
    }
    return ['url' => $url, 'caption' => $caption, 'caption_sha256' => hash('sha256', $caption),
        'html_sha256' => hash('sha256', $body), 'published_at' => $dates->item(0)->getAttribute('datetime'),
        'published_unixtime' => (new DateTimeImmutable($dates->item(0)->getAttribute('datetime')))->getTimestamp(),
        'single_photo' => $single && $photoUrl !== '', 'photo_url' => $photoUrl,
        'photo_count' => $photos->length, 'other_media_count' => $other->length,
        'video_count'=>$videos->length, 'roundvideo_count'=>$roundVideos->length, 'caption_links'=>$captionLinks,
        'document_anchors'=>$documentAnchors, 'document_anchors_truncated'=>$documentLinks && $documentLinks->length>80,
        'photo_marker_sha256' => $marker !== '' ? hash('sha256', $marker) : null, 'buttons' => $buttons];
}

function corroborateTargetKeyboard(array $embedded, array $archive): array
{
    requireRead($embedded['url'] === $archive['url']
        && $embedded['published_unixtime'] === $archive['published_unixtime']
        && $embedded['caption_sha256'] === $archive['caption_sha256'],
        'target_keyboard_archive_identity_changed');
    requireRead($embedded['buttons'] === [] || $embedded['buttons'] === $archive['buttons'],
        'target_keyboard_archive_changed');
    $embedded['buttons'] = $archive['buttons'];
    $embedded['target_keyboard_archive_verified'] = true;
    return $embedded;
}


const INSTALLED_BLOBS=[
 '.github/workflows/edit-1566-ranked-46-historical-telegram.yml'=>'bf59d830df21fe9bc1cc4ba46d5ea01d14a4c972',
 'tests/run_channel_post_automation_regression.php'=>'2d64bee3b446bb3569356e1d46b22ce4f8733786',
 'tools/anytour_existing_post_batch.php'=>'444ba4561802ae96e9c65bb5c9e85b731e61022d',
];
const SMALL_CAPTION_SCOPE=[
 102203=>['telegram'=>'1561','max'=>'AaHwVf1OCGc'],
 37931=>['telegram'=>'1554','max'=>'AaHwVbsRYzs'],
 102201=>['telegram'=>'1580','max'=>'AaHwWbJMJac'],
];
const PREPARED_PAIRS=[
 8094=>['source'=>'905','source_publication'=>1951,'target'=>'567','target_date'=>1713769144,'target_caption'=>'37fd47408b3e74a0dff1233df09f1f45ce5b94f3aa5a0b05c7a1f88fbffb4870','target_photo'=>'07989c88a8f257d8580905c1b368649dca1b00099b531c173b048f0930141a4a'],
 73082=>['source'=>'1025','source_publication'=>2204,'target'=>'588','target_date'=>1714555735,'target_caption'=>'80a7b342b26fdf2f1310fc1125d5f8549c18f0cc8b350013f3d11823a033a252','target_photo'=>'d5abd6334904eb43a00a531e64b5e812775524df8d7547e28c09087f130ec7c5'],
];
function captionPublicBytes(string $url,array &$rates):array{
 $host=(string)parse_url($url,PHP_URL_HOST);if(isset($rates[$host]))return ['ok'=>false,'reason'=>'public_retry_after_held','retry_after'=>$rates[$host],'http_attempts'=>0];
 $body='';$retry=null;$c=curl_init($url);curl_setopt_array($c,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_USERAGENT=>'Mozilla/5.0',CURLOPT_HEADERFUNCTION=>static function($c,string $line)use(&$retry):int{if(stripos($line,'retry-after:')===0)$retry=trim(substr($line,12));return strlen($line);},CURLOPT_WRITEFUNCTION=>static function($c,string $chunk)use(&$body):int{if(strlen($body)+strlen($chunk)>1000000)return 0;$body.=$chunk;return strlen($chunk);}]);$ok=curl_exec($c)!==false&&curl_errno($c)===0;$status=(int)curl_getinfo($c,CURLINFO_HTTP_CODE);curl_close($c);
 if($status===429)$rates[$host]=publicRetryLimit($retry,time());return ['ok'=>$ok&&$status===200,'http'=>$status,'body'=>$body,'body_sha256'=>hash('sha256',$body),'http_attempts'=>1,'retry_after'=>$status===429?$rates[$host]:null];
}
function telegramCandidatePublicRead(array $pair, bool $priorOperationExists, callable $read): array
{
    if (!$priorOperationExists) return [$read('any_toour', $pair['source']), $read('anytour', $pair['target'])];
    $skipped = ['ok'=>false, 'reason'=>'prior_operation_do_not_replay', 'http_attempts'=>0];
    return [$skipped + ['url'=>'https://t.me/any_toour/'.$pair['source']],
        $skipped + ['url'=>'https://t.me/anytour/'.$pair['target']]];
}

function telegramNativeEditEvidenceRead(array $snapshot, string $chat, string $message): array
{
    $raw = $snapshot['raw_body'] ?? null;
    if (!is_array($raw) || $raw === []) return ['state'=>'unavailable'];
    if ((string)($raw['chat']['id'] ?? '') !== $chat
        || (string)($raw['message_id'] ?? '') !== $message) return ['state'=>'identity_mismatch'];
    $id = static fn($value): ?string => (is_int($value) || is_string($value))
        && preg_match('/\A[1-9][0-9]{0,19}\z/D', (string)$value) === 1 ? (string)$value : null;
    $flag = static fn(string $field): ?bool => is_bool($raw[$field] ?? null) ? $raw[$field] : null;
    $via = is_array($raw['via_bot'] ?? null) ? $raw['via_bot'] : [];
    $from = is_array($raw['from'] ?? null) ? $raw['from'] : [];
    $sender = $raw['sender_chat']['id'] ?? null;
    $kind = isset($raw['photo']) ? 'photo' : (isset($raw['text']) ? 'text' : 'unknown');
    foreach (['video','video_note','animation','audio','voice','document','sticker','paid_media','poll'] as $field)
        if (isset($raw[$field])) $kind = $field;
    $forward = $raw['forward_origin']['type'] ?? null;
    $forwarded = isset($raw['forward_origin']) || isset($raw['forward_date'])
        || isset($raw['forward_from']) || isset($raw['forward_from_chat']);
    $markup = $raw['reply_markup'] ?? null;
    $markupKind = $markup === null ? null
        : (is_array($markup) && isset($markup['inline_keyboard']) ? 'inline'
            : (is_array($markup) && isset($markup['keyboard']) ? 'reply_keyboard' : 'unknown'));
    $signature = $raw['author_signature'] ?? null;
    $suggested = $raw['suggested_post_info']['state'] ?? null;
    return ['state'=>'native_snapshot', 'observation'=>'saved_bot_api_message',
        'chat_id'=>$chat, 'message_id'=>$message,
        'raw_message_sha256'=>hash('sha256', jsonRead($raw)),
        'from_id_sha256'=>$id($from['id'] ?? null) !== null ? hash('sha256', (string)$from['id']) : null,
        'from_bot_id'=>($from['is_bot'] ?? null) === true ? $id($from['id'] ?? null) : null,
        'sender_chat_id'=>(is_int($sender) || is_string($sender))
            && preg_match('/\A-?[1-9][0-9]{0,19}\z/D', (string)$sender) === 1 ? (string)$sender : null,
        'via_bot_id'=>($via['is_bot'] ?? null) === true ? $id($via['id'] ?? null) : null,
        'author_signature'=>is_string($signature) ? mb_substr($signature, 0, 256) : null,
        'forwarded'=>$forwarded ? true : null,
        'forward_origin_type'=>in_array($forward, ['user','hidden_user','chat','channel'], true) ? $forward : null,
        'is_paid_post'=>$flag('is_paid_post'), 'has_protected_content'=>$flag('has_protected_content'),
        'is_from_offline'=>$flag('is_from_offline'),
        'suggested_post_state'=>in_array($suggested, ['pending','approved','declined'], true) ? $suggested : null,
        'media_group_id_sha256'=>is_string($raw['media_group_id'] ?? null)
            ? hash('sha256', $raw['media_group_id']) : null,
        'media_kind'=>$kind, 'reply_markup_kind'=>$markupKind,
        'date'=>is_int($raw['date'] ?? null) ? $raw['date'] : null,
        'edit_date'=>is_int($raw['edit_date'] ?? null) ? $raw['edit_date'] : null,
        'original_send_method'=>null, 'can_be_edited'=>null, 'can_edit_media'=>null];
}

function telegramTargetEditabilityRead(string $message, ?array $native, array $journal,
    array $public, ?string $editorBotId, ?bool $rightsReady, bool $directoryExists = false,
    string $readPurpose = 'candidate_preparation'): array
{
    requireRead(in_array($readPurpose, ['candidate_preparation','accepted_readback'], true), 'read_purpose_invalid');
    $acceptedReadback = $readPurpose === 'accepted_readback';
    // This readback observes accepted destinations; it does not read getMe or current edit rights.
    if ($acceptedReadback) { $editorBotId = null; $rightsReady = null; }
    $result = ['schema'=>1, 'chat_id'=>'-1001845835645', 'message_id'=>$message,
        'method'=>'editMessageMedia', 'editor_bot_id'=>$editorBotId,
        'channel_rights_verified'=>$rightsReady, 'per_message_capability'=>null,
        'editability_guaranteed'=>false, 'success_probability'=>null, 'probability_basis'=>'not_calibrated',
        'state'=>'unknown_editability', 'evidence_tier'=>'unknown', 'read_purpose'=>$readPurpose,
        'readback_required'=>$acceptedReadback,
        'preparation_blocked'=>false, 'candidate_preparation_allowed'=>true, 'no_retry'=>false, 'reasons'=>[]];
    $finish = static fn(string $state, string $tier, bool $blocked, bool $noRetry, array $reasons): array
        => array_replace($result, ['state'=>$state, 'evidence_tier'=>$tier,
            'preparation_blocked'=>$acceptedReadback || $blocked,
            'candidate_preparation_allowed'=>!($acceptedReadback || $blocked),
            'no_retry'=>$acceptedReadback || $noRetry,
            'reasons'=>array_values(array_unique(array_merge(
                $acceptedReadback ? ['accepted_destination_scope'] : [], $reasons)))]);
    if (($journal['state'] ?? null) !== 'absent' || $directoryExists) {
        if (($journal['state'] ?? null) === 'unavailable' && !$directoryExists)
            return $finish('operation_requires_readback', 'unavailable', true, true, ['media_operation_evidence_unavailable']);
        $states = $journal['states'] ?? [];
        $events = $journal['events'] ?? [];
        $prefix = ['intent_saved','identity_reserved','request_prepared','provider_edit_started','provider_response_saved'];
        $bound = count($states) === 6 && array_slice($states, 0, 5) === $prefix
            && count($events) === 6 && array_column($events, 'state') === $states
            && ($events[0]['target_matches'] ?? null) === true
            && ($events[2]['target_matches'] ?? null) === true
            && ($events[5]['target_matches'] ?? null) === true
            && ($events[3]['provider_write_attempts'] ?? null) === 1
            && ($events[5]['provider_write_attempts'] ?? null) === 1;
        if ($bound && $states[5] === 'provider_message_id_rejected'
            && ($events[4]['provider_http'] ?? null) === 400
            && ($events[4]['transport_error'] ?? null) === false
            && ($events[4]['provider_ok'] ?? null) === false
            && ($events[4]['provider_error_code'] ?? null) === 400
            && ($events[4]['message_id_invalid'] ?? null) === true)
            return $finish('rejected_no_retry', 'confirmed_rejection', true, true, ['previous_message_id_invalid']);
        if ($bound && $states[5] === 'native_and_registry_verified'
            && ($events[4]['provider_http'] ?? null) === 200
            && ($events[4]['transport_error'] ?? null) === false
            && ($events[4]['provider_ok'] ?? null) === true
            && ($events[4]['target_matches'] ?? null) === true)
            return $finish('completed_no_retry', 'historical_media_edit', true, true, ['previous_transfer_completed']);
        return $finish('operation_requires_readback', 'prior_operation', true, true, ['existing_operation_do_not_replay']);
    }
    if (($native['state'] ?? '') === 'unaccepted_reserved_identity')
        return $finish('reserved_no_retry', 'reservation', true, true, ['unaccepted_identity_is_not_a_message']);
    if (($native['native_claim_held_or_unknown'] ?? false) === true
        || in_array($native['state'] ?? null, ['claimed','write_started','unknown'], true))
        return $finish('operation_requires_readback', 'native_operation', true, true, ['native_claim_or_outcome_requires_readback']);
    if (isset($native['content_edit_journal']) && ($native['content_edit_journal']['state'] ?? null) !== 'absent')
        return $finish('operation_requires_readback', 'native_operation', true, true, ['existing_content_operation_do_not_replay']);
    $evidence = $native['telegram_message'] ?? ['state'=>'unavailable'];
    if (($evidence['state'] ?? '') === 'identity_mismatch')
        return $finish('evidence_conflict', 'unusable_snapshot', true, false, ['native_message_identity_mismatch']);
    if (($evidence['state'] ?? '') === 'native_snapshot'
        && (($evidence['chat_id'] ?? null) !== $result['chat_id']
            || ($evidence['message_id'] ?? null) !== $message))
        return $finish('evidence_conflict', 'unusable_snapshot', true, false, ['native_message_identity_mismatch']);
    // An expected hotel button is positive readback evidence, not a candidate veto.
    // Missing historical media proof never makes an accepted destination reusable.
    if ($acceptedReadback)
        return $finish('accepted_target_not_for_reuse', 'accepted_scope', true, true, []);
    $caption = is_string($public['caption'] ?? null) ? $public['caption'] : '';
    // Albums may expose several DOM copies with caption=null; inspect the
    // already bounded text nodes without inventing a native caption member.
    $nodes = $public['format_observation']['caption_nodes'] ?? [];
    if (is_array($nodes)) foreach (array_slice($nodes, 0, 6) as $node)
        if (is_string($node['text'] ?? null)) $caption .= "\n".mb_substr($node['text'], 0, 12000);
    if (preg_match('/(?<![\pL\pN])(?:отзыв\pL*|видеокруж\pL*|видео\s+от\s+менеджер\pL*)(?![\pL\pN])/iu', $caption) === 1
        || ($evidence['media_kind'] ?? null) === 'video_note'
        || (is_int($public['roundvideo_count'] ?? null) && $public['roundvideo_count'] > 0))
        return $finish('protected_by_owner', 'owner_policy', true, false, ['reviews_and_manager_video_excluded']);
    if (($evidence['media_kind'] ?? null) === 'video'
        || (is_int($public['video_count'] ?? null) && $public['video_count'] > 0))
        return $finish('format_requires_review', 'content_review_required', true, false, ['video_content_not_approved']);
    if (is_array($public['buttons'] ?? null) && $public['buttons'] !== [])
        return $finish('existing_keyboard_review', 'existing_attribution', true, false, ['target_keyboard_requires_exact_review']);
    if (!$rightsReady)
        return $finish('channel_not_verified', 'channel_precondition', true, false, ['channel_edit_rights_not_confirmed']);
    if (($evidence['state'] ?? '') !== 'native_snapshot') return $result;
    $constraints = [];
    if (($evidence['is_paid_post'] ?? null) === true) $constraints[] = 'native_paid_post';
    if (in_array($evidence['suggested_post_state'] ?? null, ['approved','declined'], true)) $constraints[] = 'native_closed_suggested_post';
    if (($evidence['forwarded'] ?? null) === true) $constraints[] = 'native_forwarded_message';
    if (($evidence['reply_markup_kind'] ?? null) === 'reply_keyboard') $constraints[] = 'native_non_inline_keyboard';
    if ($editorBotId !== null && ($evidence['via_bot_id'] ?? null) !== null
        && $evidence['via_bot_id'] !== $editorBotId) $constraints[] = 'native_other_inline_bot';
    if ($constraints !== []) return $finish('native_constraint', 'observed_native_restriction', true, false, $constraints);
    if ($editorBotId !== null && (($evidence['from_bot_id'] ?? null) === $editorBotId
        || ($evidence['via_bot_id'] ?? null) === $editorBotId))
        return $finish('bot_origin_observed', 'origin_only', false, false, ['current_bot_origin_is_not_edit_capability']);
    return $result;
}

function receiptRead(string $path, ?string $message = null): array
{
    if (!file_exists($path) && !is_link($path)) return ['state' => 'absent'];
    requireRead(!is_link($path) && is_file($path) && filesize($path) <= 1_000_000, 'operation_receipt_unreadable');
    $raw = file_get_contents($path);
    requireRead(is_string($raw), 'operation_receipt_unreadable');
    $lines = array_values(array_filter(explode("\n", $raw), static fn($s) => trim($s) !== ''));
    requireRead(count($lines) <= 500, 'operation_receipt_unbounded');
    $states = []; $events = [];
    foreach ($lines as $line) {
        $record = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
        $state = $record['state'] ?? '';
        $states[] = is_string($state) && preg_match('/\A[a-z0-9_:-]{1,100}\z/D', $state) === 1 ? $state : 'unclassified';
        $safe = ['state'=>end($states)];
        foreach (['at_utc','reason','publication_id','version_id','provider_write_attempts','plan_sha256'] as $key) {
            $v=$record[$key]??null;
            if(is_int($v) || (is_string($v)&&strlen($v)<=100&&preg_match('/\A[a-zA-Z0-9_:T.+-]{1,100}\z/D',$v)===1))$safe[$key]=$v;
        }
        if($state==='provider_response_saved') {
            $response=$record['response']??[];
            $safe['provider_http']=is_int($response['http']??null)?$response['http']:null;
            $safe['transport_error']=is_bool($response['transport_error']??null)?$response['transport_error']:null;
            $safe['provider_ok']=is_bool($response['data']['ok']??null)?$response['data']['ok']:null;
            $safe['provider_error_code']=is_int($response['data']['error_code']??null)?$response['data']['error_code']:null;
            $safe['message_id_invalid']=($response['data']['description']??null)==='Bad Request: MESSAGE_ID_INVALID';
            $safe['target_matches']=$message !== null
                && (string)($response['data']['result']['message_id']??'') === $message
                && (string)($response['data']['result']['chat']['id']??'') === '-1001845835645';
        }
        if($state==='intent_saved') {
            $x=$record['execution']??[];
            $safe['original_native_run_id']=preg_match('/\A[0-9]{1,20}\z/D',$x['native_run_id']??'')?$x['native_run_id']:null;
            $safe['original_application_sha']=preg_match('/\A[a-f0-9]{40}\z/D',$x['application_sha']??'')?$x['application_sha']:null;
            $safe['target_matches']=$message !== null && ($record['row']['target_message_id']??null) === $message
                && $safe['original_native_run_id'] !== null && $safe['original_application_sha'] !== null;
        }
        if ($state === 'request_prepared') {
            $request = $record['request'] ?? null;
            $safe['target_matches']=$message !== null && is_array($request)
                && (string)($request['chat_id']??'') === '-1001845835645'
                && (string)($request['message_id']??'') === $message
                && array_keys($request) === ['chat_id','message_id','media','reply_markup']
                && is_array($request['media']??null) && ($request['media']['type']??null) === 'photo'
                && (!isset($record['method']) || $record['method'] === 'editMessageMedia')
                && (!isset($record['path']) || $record['path'] === 'editMessageMedia')
                && ($record['request_sha256']??null) === hash('sha256', jsonRead($request));
        }
        if (in_array($state, ['provider_message_id_rejected','native_and_registry_verified'], true))
            $safe['target_matches']=$message !== null && ($record['message_id']??null) === $message
                && ($record['url']??null) === 'https://t.me/anytour/'.$message;
        $events[]=$safe;
    }
    return ['state' => 'present_do_not_replay', 'sha256' => hash('sha256', $raw), 'line_count' => count($lines), 'states' => $states, 'events'=>$events];
}

function publicationRead(PDO $pdo, ChannelPostRegistryService $registry, int $channel, string $message): ?array
{
    $q = $pdo->prepare('SELECT id FROM post_publications WHERE post_channel_id=? AND external_message_id=? LIMIT 2');
    $q->execute([$channel, $message]);
    $ids = $q->fetchAll(PDO::FETCH_COLUMN);
    requireRead(count($ids) <= 1, 'registry_identity_ambiguous');
    if ($ids === []) return null;
    $p = $registry->publication((int) $ids[0]);
    if ($p === null && $channel === 6) {
        // An existing reservation without an accepted version is not a native
        // message. It remains held even if its operation journal is unavailable.
        $reserved = $pdo->prepare('SELECT id,external_message_id,current_version_id FROM post_publications '
            . 'WHERE id=? AND post_channel_id=? LIMIT 1');
        $reserved->execute([(int)$ids[0], $channel]);
        $identity = $reserved->fetch(PDO::FETCH_ASSOC);
        requireRead(is_array($identity) && (string)$identity['external_message_id'] === $message
            && $identity['current_version_id'] === null, 'reserved_native_observation_unavailable');
        return ['publication_id'=>(int)$ids[0], 'state'=>'unaccepted_reserved_identity',
            'accepted_message_receipt_verified'=>false, 'telegram_message'=>['state'=>'unavailable'],
            'content_edit_journal'=>receiptRead(OPERATIONS.'/issue-1566-edit-existing-'.(int)$ids[0].'.jsonl')];
    }
    requireRead(is_array($p) && $p['project_key'] === 'anytour' && $p['platform'] === 'telegram'
        && $p['bot_key'] === 'anyru_bot' && $p['message_id'] === $message
        && (int) $p['channel']['id'] === $channel, 'registry_identity_changed');
    $q = $pdo->prepare('SELECT id,version_number,state,attempt_count,write_attempts,error_reason,'
        . 'claim_token IS NOT NULL AS has_claim,claimed_at IS NOT NULL AS has_claimed_at '
        . 'FROM post_versions WHERE publication_id=? ORDER BY version_number LIMIT 101');
    $q->execute([(int) $p['publication_id']]);
    $history = $q->fetchAll(PDO::FETCH_ASSOC);
    requireRead(count($history) <= 100, 'version_history_incomplete');
    $held = false;
    foreach ($history as $v) {
        if ((int) $v['has_claim'] !== 0 || (int) $v['has_claimed_at'] !== 0
            || in_array($v['state'], ['claimed', 'write_started', 'unknown'], true)) $held = true;
    }
    $s = $p['snapshot'];
    $media = [];
    foreach (($s['attachments'] ?? []) as $a) $media[] = [
        'type' => $a['type'] ?? '', 'provider' => $a['provider'] ?? '',
        'file_id_sha256' => !empty($a['telegram_file_id']) ? hash('sha256', $a['telegram_file_id']) : null,
        'file_unique_id_sha256' => !empty($a['file_unique_id']) ? hash('sha256', $a['file_unique_id']) : null,
        'reusable_telegram_photo' => ($a['type'] ?? '') === 'image' && ($a['provider'] ?? '') === 'telegram'
            && preg_match('/\A[A-Za-z0-9_-]{8,1024}\z/D', $a['telegram_file_id'] ?? '') === 1
            && preg_match('/\A[A-Za-z0-9_-]{5,160}\z/D', $a['file_unique_id'] ?? '') === 1,
    ];
    $url = ChannelPostButtonTransport::buttonUrl($p);
    $resolved = $registry->resolveToken('anytour', 'telegram', 'anyru_bot', (string) $p['start_payload']);
    $same = is_array($resolved) && (int) $resolved['publication_id'] === (int) $p['publication_id']
        && (int) $resolved['version_id'] === (int) $p['version_id'];
    $keyboardObservation = [];
    $keyboardRows = is_array($s['keyboard'] ?? null) ? $s['keyboard'] : [];
    $keyboardUnbounded = count($keyboardRows) > 20;
    $buttonsSeen = 0;
    foreach (array_slice($keyboardRows, 0, 20) as $keyRow) {
        if (!is_array($keyRow)) { $keyboardUnbounded = true; continue; }
        foreach ($keyRow as $keyButton) {
            if (++$buttonsSeen > 20) { $keyboardUnbounded = true; break 2; }
            if (!is_array($keyButton)) { $keyboardUnbounded = true; continue; }
            $keyUrl = is_string($keyButton['url'] ?? null) ? $keyButton['url'] : '';
            $keyboardObservation[] = ['type'=>substr((string)($keyButton['type'] ?? 'link'),0,30),
                'text'=>mb_substr((string)($keyButton['text'] ?? ''),0,180),
                'url_sha256'=>hash('sha256',$keyUrl),
                'public_hotel_url'=>preg_match('~\\Ahttps://t\\.me/anyru_bot\\?start=p_[A-Za-z0-9_-]{8,60}\\z~D',$keyUrl) === 1 ? $keyUrl : null];
        }
    }
    $r = $p['receipt'];
    return ['publication_id' => $p['publication_id'], 'version_id' => $p['version_id'],
        'version_number' => $p['version_number'], 'content_hash' => $p['content_hash'], 'state' => $p['state'],
        'published_at' => $s['published_at'] ?? '', 'post_url' => $s['post_url'] ?? '', 'caption_sha256' => hash('sha256', $s['text'] ?? ''),
        'hotel_id' => $s['metadata']['hotel_catalog_id'] ?? null, 'logical_id' => $s['metadata']['logical_id'] ?? null,
        'monitor_price_record_id' => $s['metadata']['monitor_price_record_id'] ?? null,
        'attachments' => $media, 'button_url' => $url,
        'telegram_message'=>telegramNativeEditEvidenceRead($s, (string)$p['channel']['channel_id'], $message),
        'one_expected_button' => $url !== '' && ChannelPostIncomingAdapter::containsOnlyButton($s, $url, $p['channel']['button_text']),
        'keyboard_observation'=>$keyboardObservation, 'keyboard_rows_count'=>count($keyboardRows), 'keyboard_unbounded'=>$keyboardUnbounded,
        'current_token_resolves' => $same, 'native_claim_held_or_unknown' => $held, 'version_history' => $history,
        'receipt' => ['sha256' => hash('sha256', jsonRead($r)), 'write_attempts' => $r['write_attempts'] ?? null,
            'request_count' => $r['request_count'] ?? null, 'content_preserved' => $r['content_preserved'] ?? null,
            'button_verified' => $r['button_verified'] ?? null],
        'content_edit_journal' => receiptRead(OPERATIONS . '/issue-1566-edit-existing-' . $p['publication_id'] . '.jsonl')];
}

/** Observe the existing MID-bound media journal without creating an operation. */
function telegramMediaOperationRead(string $message): array
{
    $exists=null; $symlink=null;
    $journal=['state'=>'unavailable','reason'=>'media_operation_not_observed'];
    set_error_handler(static function(int $severity,string $ignored):bool {
        throw new RuntimeException('optional_media_observation_failed');
    });
    try {
        if(preg_match('/\A[1-9][0-9]{0,19}\z/D',$message)!==1) {
            $journal=['state'=>'unavailable','reason'=>'invalid_target_message_id'];
        } else {
            $directory=OPERATIONS.'/anytour-issue1566-historical-tg-media-'.$message.'-v1';
            $symlink=is_link($directory);$exists=$symlink||file_exists($directory);
            if($symlink)$journal=['state'=>'unavailable','reason'=>'operation_directory_symlink'];
            elseif($exists&&(!is_dir($directory)||!is_readable($directory)||!is_executable($directory)))
                $journal=['state'=>'unavailable','reason'=>'operation_directory_unreadable'];
            elseif(is_link($directory.'/receipt.jsonl'))
                $journal=['state'=>'unavailable','reason'=>'operation_receipt_symlink'];
            else $journal=receiptRead($directory.'/receipt.jsonl',$message);
        }
    } catch(Throwable $ignored) {
        $journal=['state'=>'unavailable','reason'=>'media_operation_read_unavailable'];
    } finally {restore_error_handler();}
    return ['directory_exists'=>$exists,'directory_symlink'=>$symlink,'journal'=>$journal];
}
function telegramMediaOperationHeldRead(array $observation): bool
{
    return ($observation['directory_exists']??null)!==false
        ||($observation['journal']['state']??null)!=='absent';
}
/** The callback retains the owner's embed/archive reads for each channel. */
function telegramPreparedPairPublicRead(array $pair,array $operation,callable $read): array
{
    $held=telegramMediaOperationHeldRead($operation);
    $observed=telegramCandidatePublicRead($pair,$held,$read);
    if($held&&($operation['journal']['state']??null)==='unavailable'
        &&($operation['directory_exists']??null)!==true) {
        foreach($observed as &$row)$row['reason']='media_operation_evidence_unavailable';
        unset($row);
    }
    return $observed;
}
/** Existing target and duplicate queries now precede source preparation. */
function telegramPreparedPairTargetRead(PDO $pdo,ChannelPostRegistryService $registry,int $hotel,array $pair): array
{
    $q=$pdo->prepare('SELECT id FROM post_publications WHERE post_channel_id=6 AND external_message_id=? LIMIT 2');
    $q->execute([$pair['target']]);$targets=$q->fetchAll(PDO::FETCH_COLUMN);
    $q=$pdo->prepare("SELECT p.id FROM post_publications p JOIN post_versions v ON v.id=p.current_version_id WHERE p.post_channel_id=6 AND (JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.metadata.hotel_catalog_id'))=? OR JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.metadata.logical_id')) IN (?,?)) LIMIT 2");
    $q->execute([(string)$hotel,'commercial-hotel-'.$hotel.'-v1','commercial-main-hotel-'.$hotel.'-v1']);
    $duplicates=$q->fetchAll(PDO::FETCH_COLUMN);$native=null;$projectionReason=null;
    if(count($targets)===1) {
        set_error_handler(static function(int $severity,string $ignored):bool{throw new RuntimeException('optional_native_observation_failed');});
        try{$native=publicationRead($pdo,$registry,6,$pair['target']);}
        catch(Throwable $ignored){$projectionReason='target_native_projection_unavailable';}
        finally{restore_error_handler();}
    }
    return ['target_native_ids'=>$targets,'hotel_duplicate_publication_ids'=>$duplicates,
        'target_native'=>$native,'target_native_projection_reason'=>$projectionReason];
}
function telegramPreparedPairSourceRead(PDO $pdo,ChannelPostRegistryService $registry,int $hotel,array $pair): array
{
    $source=$registry->publication($pair['source_publication']);
    requireRead(is_array($source)&&$source['project_key']==='anytour'&&$source['platform']==='telegram'&&$source['bot_key']==='anyru_bot'&&(int)$source['channel']['id']===10&&$source['message_id']===$pair['source'],'pair_source_identity_changed');
    $q=$pdo->prepare("SELECT id FROM post_versions WHERE publication_id=? AND (state IN ('claimed','write_started','unknown') OR claim_token IS NOT NULL OR claimed_at IS NOT NULL) LIMIT 2");
    $q->execute([$pair['source_publication']]);$held=$q->fetchAll(PDO::FETCH_COLUMN);
    return ['source'=>$source,'native_writer_ids'=>$held,'button'=>ChannelPostButtonTransport::buttonUrl($source),
        'context'=>$registry->resolveToken('anytour','telegram','anyru_bot',$source['start_payload'])];
}
/** The actual prepared-pair path; callbacks expose the existing read boundaries to tests. */
function telegramPreparedPairNativeRead(int $hotel,array $pair,array $public,array $prior,
    ?bool $rightsReady,array $feed,array $routes,callable $readTarget,callable $readSource,callable $readOperation): array
{
    $names=['source_native_identity','source_public_body','source_one_live_button','source_token_context',
        'source_photo','source_no_writer','target_no_registry_or_duplicate','target_no_prior_media_operation',
        'target_preimage','target_no_keyboard','canonical_feed_and_route','native_channel_rights'];
    $row=['hotel_id'=>$hotel,'source_message_id'=>$pair['source'],'target_message_id'=>$pair['target'],
        'write_admission'=>false,'prepared_checks_pass'=>false,'candidate_preparation_allowed'=>false,
        'candidate_preparation_skipped'=>true,'checks'=>array_fill_keys($names,null),
        'target_native_ids'=>null,'hotel_duplicate_publication_ids'=>null,'native_writer_ids'=>null];
    $assessment=null;
    try {
        $fresh=$readOperation($pair['target']);
        requireRead(is_array($fresh),'media_operation_observation_unavailable');
        // Disappearance after the public phase cannot release an already observed hold.
        $operation=telegramMediaOperationHeldRead($prior)&&!telegramMediaOperationHeldRead($fresh)?$prior:$fresh;
        $directoryObserved=($prior['directory_exists']??null)===true||($fresh['directory_exists']??null)===true;
        $journal=$operation['journal']??['state'=>'unavailable'];
        $row['prior_media_operation_before_public']=$prior;$row['prior_media_operation_current']=$fresh;
        $sp=$public['source']??[];$tp=$public['target']??[];
        $assessment=telegramTargetEditabilityRead($pair['target'],null,$journal,$tp,null,$rightsReady,$directoryObserved);
        $row['candidate_assessment']=$assessment;
        if($assessment['preparation_blocked']) {
            if(telegramMediaOperationHeldRead($operation))$row['checks']['target_no_prior_media_operation']=false;
            $row['hold_reason']='telegram_destination_'.$assessment['state'];return $row;
        }
        $observed=$readTarget($hotel,$pair);
        requireRead(is_array($observed)&&is_array($observed['target_native_ids']??null)
            &&is_array($observed['hotel_duplicate_publication_ids']??null),'target_registry_observation_unavailable');
        $targets=$observed['target_native_ids'];$duplicates=$observed['hotel_duplicate_publication_ids'];
        $row['target_native_ids']=$targets;$row['hotel_duplicate_publication_ids']=$duplicates;
        $row['target_native']=$observed['target_native']??null;
        $row['target_native_projection_reason']=$observed['target_native_projection_reason']??null;
        $assessment=telegramTargetEditabilityRead($pair['target'],$row['target_native'],$journal,$tp,null,$rightsReady,$directoryObserved);
        // Any existing target identity or hotel assignment remains an independent owner hold.
        if($targets!==[]||$duplicates!==[]) {
            if(!$assessment['preparation_blocked'])$assessment=array_replace($assessment,
                ['state'=>'existing_registry_assignment','evidence_tier'=>'registry_observation',
                    'preparation_blocked'=>true,'candidate_preparation_allowed'=>false,'no_retry'=>$targets!==[]]);
            $assessment['reasons'][]='target_registry_or_hotel_duplicate_present';
            $row['checks']['target_no_registry_or_duplicate']=false;
        }
        $row['candidate_assessment']=$assessment;
        if($assessment['preparation_blocked']) {
            $row['hold_reason']='telegram_destination_'.$assessment['state'];return $row;
        }
        $sourceData=$readSource($hotel,$pair);
        requireRead(is_array($sourceData)&&is_array($sourceData['source']??null)
            &&is_array($sourceData['native_writer_ids']??null),'source_preparation_unavailable');
        $source=$sourceData['source'];$snapshot=$source['snapshot'];$held=$sourceData['native_writer_ids'];
        $button=$sourceData['button'];$context=$sourceData['context'];
        $directory=OPERATIONS.'/anytour-issue1566-historical-tg-media-'.$pair['target'].'-v1';
        $media=$snapshot['attachments']??[];
        $out=['native_channel_rights'=>['ok'=>$rightsReady===true]];
 $checks=['source_native_identity'=>$source['state']==='sent'&&(int)($snapshot['metadata']['hotel_catalog_id']??0)===$hotel,'source_public_body'=>(($sp['ok']??false)&&($sp['single_photo']??false)&&($sp['caption_sha256']??null)===hash('sha256',$snapshot['text'])),'source_one_live_button'=>(($sp['ok']??false)&&count($sp['buttons']??[])===1&&($sp['buttons'][0]['hotel_url']??null)===$button),'source_token_context'=>is_array($context)&&$context['publication_id']===$source['publication_id']&&$context['version_id']===$source['version_id'],'source_photo'=>count($media)===1&&($media[0]['type']??null)==='image'&&!empty($media[0]['telegram_file_id'])&&!empty($media[0]['file_unique_id']),'source_no_writer'=>$held===[],'target_no_registry_or_duplicate'=>$targets===[]&&$duplicates===[],'target_no_prior_media_operation'=>!file_exists($directory)&&!is_link($directory),'target_preimage'=>(($tp['ok']??false)&&($tp['single_photo']??false)&&($tp['caption_sha256']??null)===$pair['target_caption']&&($tp['published_unixtime']??null)===$pair['target_date']&&($tp['photo_marker_sha256']??null)===$pair['target_photo']),'target_no_keyboard'=>(($tp['ok']??false)&&($tp['buttons']??null)===[]),'canonical_feed_and_route'=>($feed['tg_'.$hotel]??null)==='https://t.me/any_toour/'.$pair['source']&&($routes['tg_'.$hotel]??null)==='https://t.me/any_toour/'.$pair['source']&&!in_array('https://t.me/anytour/'.$pair['target'],$routes,true),'native_channel_rights'=>($out['native_channel_rights']['ok']??false)];
 $row=array_replace($row,['source_publication_id'=>$source['publication_id'],'source_version_id'=>$source['version_id'],'source_content_hash'=>$source['content_hash'],'source_caption'=>$snapshot['text'],'source_caption_sha256'=>hash('sha256',$snapshot['text']),'source_photo_file_id_sha256'=>count($media)===1?hash('sha256',$media[0]['telegram_file_id']??''):null,'source_photo_file_unique_id_sha256'=>count($media)===1?hash('sha256',$media[0]['file_unique_id']??''):null,'checks'=>$checks,'prepared_checks_pass'=>!in_array(false,$checks,true),'target_native_ids'=>$targets,'hotel_duplicate_publication_ids'=>$duplicates,'native_writer_ids'=>$held,'advertising_scope'=>'canonical existing feed and resolver; no advertiser campaign changes']);
        $row['candidate_preparation_skipped']=false;
        $row['candidate_preparation_allowed']=$row['prepared_checks_pass']
            &&$assessment['candidate_preparation_allowed']&&!$assessment['preparation_blocked']&&!$assessment['no_retry'];
        if(!$row['candidate_preparation_allowed'])$row['hold_reason']='prepared_pair_owner_checks_not_confirmed';
        return $row;
    } catch(Throwable $error) {
        if(!is_array($assessment))$assessment=telegramTargetEditabilityRead($pair['target'],null,
            ['state'=>'unavailable'],[],null,$rightsReady,false);
        $assessment=array_replace($assessment,['state'=>'preparation_evidence_unavailable','evidence_tier'=>'unavailable',
            'preparation_blocked'=>true,'candidate_preparation_allowed'=>false]);
        $assessment['reasons'][]='prepared_pair_evidence_unavailable';
        $row['prepared_checks_pass']=false;$row['candidate_preparation_allowed']=false;
        $row['candidate_preparation_skipped']=true;
        $row['candidate_assessment']=$assessment;$row['hold_reason']=reasonRead($error);return $row;
    }
}

$out=['schema'=>1,'operation'=>'hotel_1720_installed_read_and_existing_small_caption_context','ok'=>false,'repository_sha'=>REPOSITORY_SHA,'application_sha'=>APPLICATION_SHA,'native_run_id'=>NATIVE_RUN_ID,'write_admission'=>false,'production_writes'=>0,'provider_writes'=>0,'bot_starts'=>0,'installed_blobs'=>[],'public_rows'=>[],'native_rows'=>[],'pair_public'=>[],'pair_native'=>[]];
$pdo=null;$lock=null;$acquired=null;$stage='installed_runtime';$start=hrtime(true);$rates=[];
try{
 runtimeRead();requireRead(realpath(OPERATIONS)===OPERATIONS,'operations_root_changed');
 foreach(INSTALLED_BLOBS as $path=>$expected){$lines=[];exec('GIT_OPTIONAL_LOCKS=0 git rev-parse '.escapeshellarg('HEAD:'.$path).' 2>/dev/null',$lines,$status);$actual=implode("\n",$lines);requireRead($status===0&&$actual===$expected,'installed_keyboard_guard_source_changed');$worktree=null;if($path==='tools/anytour_existing_post_batch.php'){$lines=[];exec('GIT_OPTIONAL_LOCKS=0 git hash-object -- '.escapeshellarg($path).' 2>/dev/null',$lines,$status);$worktree=implode("\n",$lines);requireRead($status===0&&$worktree===$expected,'installed_keyboard_guard_changed');}$out['installed_blobs'][$path]=['installed_git_blob'=>$actual,'actual_editor_worktree_blob'=>$worktree];}
 $out['resources_before']=resourcesRead();$out['resource_budget']=serverReadBudgetRead($out['resources_before']);requireRead($out['resource_budget']['allowed'],'server_resource_hold');$stage='public_caption_read';
 // Independent public reads precede both SQL transaction and shared PRICE lease.
 foreach(SMALL_CAPTION_SCOPE as $hotel=>$ids)foreach(['telegram','max'] as $platform){$url=$platform==='telegram'?'https://t.me/any_toour/'.$ids[$platform].'?embed=1&mode=tme':'https://max.ru/channel_anytour/'.$ids[$platform];$r=captionPublicBytes($url,$rates);$row=['hotel_id'=>$hotel,'platform'=>$platform,'url'=>$url,'http'=>$r['http']??null,'http_attempts'=>$r['http_attempts'],'raw_sha256'=>$r['body_sha256']??null,'retry_after'=>$r['retry_after']??null,'ok'=>false];
  if($r['ok'])try{if($platform==='telegram'){$seen=parsePublicRead($r['body'],'any_toour',$ids[$platform]);$row['public']=$seen;$row['ok']=true;}else{require_once APP.'/services/PromotedHotelPriceWriter.php';$caption=PromotedHotelPriceWriter::maxPublicCaption($r['body']);$row['caption']=$caption;$row['caption_sha256']=hash('sha256',$caption);$row['native_photo_button_verified']=false;$row['ok']=true;}}catch(Throwable $e){$row['hold_reason']=reasonRead($e);}else$row['hold_reason']=$r['reason']??'public_caption_unavailable';$out['public_rows'][]=$row;
 }
 ob_start();try{require APP.'/config.php';require_once APP.'/services/ProjectConfig.php';require_once APP.'/services/ChannelPostRegistryService.php';require_once APP.'/services/ChannelPostButtonTransport.php';}finally{ob_end_clean();}requireRead(ProjectConfig::projectId()==='anytour','project_changed');

 $pairPrior=[];
 foreach(PREPARED_PAIRS as $hotel=>$pair){
  $pairPrior[$hotel]=telegramMediaOperationRead($pair['target']);
  [$sp,$tp]=telegramPreparedPairPublicRead($pair,$pairPrior[$hotel],static function(string $channel,string $message)use(&$rates):array{
   $snapshots=[];foreach(['embed','archive'] as $kind){$url=$kind==='embed'?'https://t.me/'.$channel.'/'.$message.'?embed=1&mode=tme':'https://t.me/s/'.$channel.'?before='.((int)$message+1);$r=captionPublicBytes($url,$rates);try{requireRead($r['ok'],'pair_public_unavailable');$snapshots[$kind]=parsePublicRead($r['body'],$channel,$message);}catch(Throwable $e){$snapshots[$kind]=['ok'=>false,'reason'=>reasonRead($e),'http'=>$r['http']??null,'retry_after'=>$r['retry_after']??null];}}
   try{requireRead(isset($snapshots['embed']['url'],$snapshots['archive']['url']),'pair_public_representation_unavailable');$combined=corroborateTargetKeyboard($snapshots['embed'],$snapshots['archive']);$combined['ok']=true;return $combined;}catch(Throwable $e){return ['ok'=>false,'reason'=>reasonRead($e)];}
  });
  $out['pair_public'][$hotel]=['source'=>$sp,'target'=>$tp];
 }
 require_once APP.'/hotel-posts-telegram-main19.php';$feed=anytourTelegramFeedOfferUrls(anytourTelegramFeedRead());$routes=anytourTelegramFeedDestinationPosts($feed);$out['feed_count']=count($feed);
 $pdo=ConversationDb::connection();$pdo->exec('SET SESSION TRANSACTION READ ONLY');$registry=new ChannelPostRegistryService($pdo);$channel=$registry->channel('anytour','telegram','anyru_bot','-1002262666616');requireRead(is_array($channel)&&(int)$channel['id']===10,'small_channel_identity_changed');try{require_once APP.'/services/ChannelPostRuntime.php';require_once APP.'/tools/anytour_existing_post_batch.php';$rights=\AnyTour\ExistingPostBatch\transferChannelRights($registry);$out['native_channel_rights']=['ok'=>true,'state'=>'ready','channel_registry_id'=>(int)$rights['id'],'per_message_editability_guaranteed'=>false];}catch(Throwable $e){$out['native_channel_rights']=['ok'=>false,'reason'=>reasonRead($e),'per_message_editability_guaranteed'=>false];}
 $stage='price_shared_lease';$path=OPERATIONS.'/monitor-price-after-collection-v1.jsonl';requireRead(!is_link($path)&&is_file($path),'price_editor_lease_unknown');$lock=fopen($path,'rb');requireRead(is_resource($lock),'price_read_lease_unavailable');$wait=hrtime(true);requireRead(flock($lock,LOCK_SH|LOCK_NB),'price_editor_busy_or_unknown');$acquired=hrtime(true);$out['price_lease_wait_ms']=(int)(($acquired-$wait)/1000000);runtimeRead();
$out['mysql_before']=mysqlResourcesRead($pdo);$pdo->beginTransaction();$stage='small_native_context';
 foreach(SMALL_CAPTION_SCOPE as $hotel=>$ids){$row=['hotel_id'=>$hotel,'message_id'=>$ids['telegram'],'ok'=>false];try{$q=$pdo->prepare('SELECT id FROM post_publications WHERE post_channel_id=10 AND external_message_id=? LIMIT 2');$q->execute([$ids['telegram']]);$found=$q->fetchAll(PDO::FETCH_COLUMN);requireRead(count($found)===1,'small_publication_unconfirmed');$p=$registry->publication((int)$found[0]);requireRead(is_array($p)&&$p['platform']==='telegram'&&$p['bot_key']==='anyru_bot'&&$p['message_id']===$ids['telegram'],'small_publication_identity_changed');$s=$p['snapshot'];$row+=['publication_id'=>$p['publication_id'],'version_id'=>$p['version_id'],'state'=>$p['state'],'native_hotel_id'=>$s['metadata']['hotel_catalog_id']??null,'logical_id'=>$s['metadata']['logical_id']??null,'caption'=>$s['text'],'caption_sha256'=>hash('sha256',$s['text']),'entities'=>$s['entities']??null,'published_at'=>$s['published_at']??null,'button_url'=>ChannelPostButtonTransport::buttonUrl($p),'attachment_types'=>array_column($s['attachments']??[],'type'),'photo_unique_hashes'=>array_map(static fn($a)=>empty($a['file_unique_id'])?null:hash('sha256',$a['file_unique_id']),$s['attachments']??[])];$row['ok']=true;}catch(Throwable $e){$row['hold_reason']=reasonRead($e);}$out['native_rows'][]=$row;}

 foreach(PREPARED_PAIRS as $hotel=>$pair){
  $out['pair_native'][]=telegramPreparedPairNativeRead($hotel,$pair,$out['pair_public'][$hotel],$pairPrior[$hotel],
   ($out['native_channel_rights']['ok']??false)===true,$feed,$routes,
   static fn(int $hotel,array $pair):array=>telegramPreparedPairTargetRead($pdo,$registry,$hotel,$pair),
   static fn(int $hotel,array $pair):array=>telegramPreparedPairSourceRead($pdo,$registry,$hotel,$pair),
   'telegramMediaOperationRead');
 }
 $pdo->rollBack();$out['mysql_after']=mysqlResourcesRead($pdo);runtimeRead();$out['ok']=true;
}catch(Throwable $e){$out['failure_stage']=$stage;$out['failure_reason']=reasonRead($e);}
finally{if($pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();if(is_resource($lock)){if($acquired!==null)$out['price_lease_held_ms']=(int)((hrtime(true)-$acquired)/1000000);flock($lock,LOCK_UN);fclose($lock);}}
$out['resources_after']=resourcesRead();$out['read_at_utc']=gmdate(DATE_ATOM);$out['read_elapsed_ms']=(int)((hrtime(true)-$start)/1000000);echo jsonRead($out),PHP_EOL;exit($out['ok']?0:2);
