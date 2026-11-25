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
const PAIRS = [
    1067 => ['source'=>'273', 'target'=>'1066', 'publication_id'=>3809, 'version_id'=>14385, 'published_unixtime'=>1743668442, 'expected_caption_sha256'=>'a3288991e0a486cfc95e6c919799ec56a4a8d561271e41be4a29765644d5af76'],
    1100 => ['source'=>'173', 'target'=>'1072', 'publication_id'=>3810, 'version_id'=>14386, 'published_unixtime'=>1744183638, 'expected_caption_sha256'=>'c1218aed2f0f27a129861a9a124e6ede79c9cc26d07ceaa03a78c18a941c9c3b'],
    1142 => ['source'=>'343', 'target'=>'1081', 'publication_id'=>3811, 'version_id'=>14387, 'published_unixtime'=>1745137076, 'expected_caption_sha256'=>'55c309a1c7ec7e03d69c7002e66ac8fea136ee0afd7e44927679f0074bf41f02'],
    1151 => ['source'=>'152', 'target'=>'1102', 'publication_id'=>3812, 'version_id'=>14388, 'published_unixtime'=>1747906898, 'expected_caption_sha256'=>'581edb2313482564c33a22fb69f2cb63f83a3e07519adfbbd608d1ca4968ea38'],
    1221 => ['source'=>'238', 'target'=>'1078', 'publication_id'=>3813, 'version_id'=>14389, 'published_unixtime'=>1744879625, 'expected_caption_sha256'=>'bb6422aa8d298908f599f664968abe026422310b62d628698cfc09edce28f53a'],
    1334 => ['source'=>'169', 'target'=>'664', 'publication_id'=>3814, 'version_id'=>14390, 'published_unixtime'=>1717660536, 'expected_caption_sha256'=>'2aa882407364767a2a96199f17746fefaf85c7819554c93d27c53733ed923ab0'],
    1336 => ['source'=>'453', 'target'=>'1032', 'publication_id'=>3815, 'version_id'=>14391, 'published_unixtime'=>1740738279, 'expected_caption_sha256'=>'603c4374f0f1d6214f5053b247085e0edebd36d0c4ad024aa19d272c3e17ac72'],
    1421 => ['source'=>'552', 'target'=>'1076', 'publication_id'=>3816, 'version_id'=>14392, 'published_unixtime'=>1744616938, 'expected_caption_sha256'=>'0ca9e9eb3720ee7ad56eff44caf49e4deff53d8e82103698b13b4025a6ce0d36'],
    1429 => ['source'=>'285', 'target'=>'1125', 'publication_id'=>3817, 'version_id'=>14393, 'published_unixtime'=>1749973243, 'expected_caption_sha256'=>'684e6e02abba525347602ab36b40e6cb45a5844e1801665bf7fb2f0b97aaa3e8'],
    2522 => ['source'=>'1049', 'target'=>'1120', 'publication_id'=>3818, 'version_id'=>14394, 'published_unixtime'=>1749547449, 'expected_caption_sha256'=>'51d77e5e203fbaeab5b0e17c431b460b4b9c9ec761baf03f5c267e690dd8f8f8'],
    2794 => ['source'=>'1056', 'target'=>'768', 'publication_id'=>3819, 'version_id'=>14395, 'published_unixtime'=>1721806548, 'expected_caption_sha256'=>'aa38cc43bdb9aa3c55f1aabad93ae9bbcedf3c8919c0ca9e1f597a6cd80caf51'],
    4060 => ['source'=>'289', 'target'=>'916', 'publication_id'=>3820, 'version_id'=>14396, 'published_unixtime'=>1730274008, 'expected_caption_sha256'=>'97b8f674fdee0fddea0de997765a81e2c65ef11cfa7e4c9fc6a82b9f2f006744'],
    8020 => ['source'=>'899', 'target'=>'839', 'publication_id'=>3821, 'version_id'=>14397, 'published_unixtime'=>1725540621, 'expected_caption_sha256'=>'ee9b6cc5ec50518d38efbf59fb4b4be184cbb2807341c6de4ab542a4e535dcec'],
    32322 => ['source'=>'682', 'target'=>'1603', 'publication_id'=>3822, 'version_id'=>14398, 'published_unixtime'=>1783062911, 'expected_caption_sha256'=>'7334e84756845f710adbf941e4783c92bbca2d963425673efc26fa163b6086e9'],
    1014 => ['source'=>'345', 'target'=>'1604', 'publication_id'=>3823, 'version_id'=>14399, 'published_unixtime'=>1783149311, 'expected_caption_sha256'=>'7b72e3c77f575d2b3b754b17980097fca480bea18cbf94f175d9383938fdba2d'],
    14140 => ['source'=>'593', 'target'=>'909', 'publication_id'=>3829, 'version_id'=>14405, 'published_unixtime'=>1729674071, 'expected_caption_sha256'=>'e90f9d7229796a03f84eb0a6e6d4da19818b6ef8ad416d1f3fedcd588be16976'],
    1402 => ['source'=>'312', 'target'=>'1085', 'publication_id'=>3830, 'version_id'=>14406, 'published_unixtime'=>1745566590, 'expected_caption_sha256'=>'97dcba158a72652ee5c4c7ae3463028d5ab02575a824e53cf37ba2ab1956d7d8'],
    1959 => ['source'=>'470', 'target'=>'526', 'publication_id'=>3831, 'version_id'=>14407, 'published_unixtime'=>1712046057, 'expected_caption_sha256'=>'541714c591f8d4be03b2e9c9e600760ccd805bd7fd87e8f2b0376ee719734c83'],
];
const PAIR_DIGEST = '91ad991e49fcc3848b71c307573d91616d8e1de6644f324f10834979b9c0c2fa';


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

/** Fixed public GETs only; TLS verified, no redirects or retries. */
function publicEvidence(string $channel, string $message): array
{
    global $publicAttempts, $publicRateLimit;
    $before = $publicAttempts;
    if ($publicRateLimit !== null) return ['ok'=>false, 'url'=>'https://t.me/'.$channel.'/'.$message,
        'reason'=>'public_retry_after_held', 'http_attempts'=>0, 'rate_limit'=>$publicRateLimit];
    try {
        $value = publicRead($channel,$message);
        return ['ok'=>true, 'http_attempts'=>$publicAttempts-$before] + $value;
    }
    catch (Throwable $e) {
        return ['ok'=>false, 'url'=>'https://t.me/'.$channel.'/'.$message,
            'reason'=>reasonRead($e), 'http_attempts'=>$publicAttempts-$before,
            'rate_limit'=>$publicRateLimit];
    }
}

/** Public structural observation only: no album membership or keyboard admission is inferred. */
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

function publicRead(string $channel, string $message): array
{
    $allowed = $channel === 'anytour' ? array_merge(array_column(PAIRS, 'target'),['932','933','934','935','936'])
        : ($channel === 'any_toour' ? array_column(PAIRS, 'source') : []);
    requireRead(in_array($message, $allowed, true), 'public_scope_invalid');
    $url = 'https://t.me/' . $channel . '/' . $message;
    $embedded = parsePublicRead(readTelegramPublicHtml($url . '?embed=1&mode=tme'), $channel, $message);
    if ($channel !== 'anytour' || isset($embedded['format_observation'])) return $embedded;

    // Telegram's embedded widget can omit the inline keyboard of a historical channel post.
    $archiveUrl = 'https://t.me/s/anytour?before=' . ((int) $message + 1);
    $archive = parsePublicRead(readTelegramPublicHtml($archiveUrl), $channel, $message);
    return corroborateTargetKeyboard($embedded, $archive);
}

function readTelegramPublicHtml(string $url): string
{
    global $publicAttempts, $publicRateLimit;
    $retryAfter = null;
    $body = '';
    $curl = curl_init($url);
    requireRead($curl !== false, 'public_transport_unavailable');
    curl_setopt_array($curl, [
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 18, CURLOPT_USERAGENT => 'Mozilla/5.0',
        CURLOPT_HEADERFUNCTION => static function ($unused, string $header) use (&$retryAfter): int {
            if (stripos($header,'retry-after:') === 0) $retryAfter = substr(trim(substr($header,12)),0,160);
            return strlen($header);
        },
        CURLOPT_WRITEFUNCTION => static function ($unused, string $part) use (&$body): int {
            if (strlen($body) + strlen($part) > 1_000_000) return 0;
            $body .= $part;
            return strlen($part);
        },
    ]);
    $publicAttempts++;
    $ok = curl_exec($curl) !== false && curl_errno($curl) === 0;
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if ($status === 429) {
        $publicRateLimit = publicRetryLimit($retryAfter,time());
        throw new RuntimeException('public_rate_limited');
    }
    requireRead($ok && $status === 200, 'public_post_unavailable');
    return $body;
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

/** Read-only evidence helpers. Accepted route readback never takes a candidate early exit. */
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

/** No raw receipt, provider file id, media token or claim token leaves the host. */
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


/** Optional bounded journal evidence; failures affect only the accepted-row annotation. */
function telegramAcceptedCandidateAssessmentRead(string $message, ?array $native, array $public): array
{
    $directoryExists = null;
    $directorySymlink = null;
    $journal = ['state'=>'unavailable', 'reason'=>'media_operation_not_observed'];
    // Optional filesystem warnings must not corrupt the reader JSON or disclose a host path.
    set_error_handler(static function (int $severity, string $ignored): bool {
        throw new RuntimeException('optional_media_observation_failed');
    });
    try {
        if (preg_match('/\A[1-9][0-9]{0,19}\z/D', $message) !== 1) {
            $journal = ['state'=>'unavailable', 'reason'=>'invalid_target_message_id'];
        } else {
            $directory = OPERATIONS.'/anytour-issue1566-historical-tg-media-'.$message.'-v1';
            $directorySymlink = is_link($directory);
            $directoryExists = $directorySymlink || file_exists($directory);
            if ($directorySymlink) {
                $journal = ['state'=>'unavailable', 'reason'=>'operation_directory_symlink'];
            } elseif ($directoryExists && (!is_dir($directory) || !is_readable($directory) || !is_executable($directory))) {
                $journal = ['state'=>'unavailable', 'reason'=>'operation_directory_unreadable'];
            } elseif (is_link($directory.'/receipt.jsonl')) {
                $journal = ['state'=>'unavailable', 'reason'=>'operation_receipt_symlink'];
            } else {
                $journal = receiptRead($directory.'/receipt.jsonl', $message);
            }
        }
    } catch (Throwable $ignored) {
        $journal = ['state'=>'unavailable', 'reason'=>'media_operation_read_unavailable'];
    } finally {
        restore_error_handler();
    }
    try {
        $assessment = telegramTargetEditabilityRead($message, $native, $journal, $public,
            null, null, $directoryExists === true, 'accepted_readback');
    } catch (Throwable $ignored) {
        // Optional evidence must not suppress the existing route/date/photo readback.
        $journal = ['state'=>'unavailable', 'reason'=>'candidate_assessment_unavailable'];
        $assessment = telegramTargetEditabilityRead($message, null, $journal, [],
            null, null, $directoryExists === true, 'accepted_readback');
    }
    $assessment['prior_media_operation_directory_exists'] = $directoryExists;
    $assessment['prior_media_operation_directory_symlink'] = $directorySymlink;
    $assessment['prior_media_operation'] = $journal;
    return $assessment;
}

/** The current accepted ROUTE readback checks remain independent of candidate reuse policy. */
function telegramAcceptedReadbackRowRead(int $hotel, array $pair, ?array $src, ?array $dst,
    array $pub, array $feed, array $landings, ?string $rowError = null): array
{
    $assessment = telegramAcceptedCandidateAssessmentRead($pair['target'], $dst, $pub);
    $context = ['read_purpose'=>'accepted_readback', 'readback_required'=>true, 'candidate_preparation_allowed'=>false,
        'no_retry'=>true, 'candidate_assessment'=>$assessment];
    if ($rowError !== null) {
        return ['hotel_id'=>$hotel,'source_message_id'=>$pair['source'],'target_message_id'=>$pair['target'],
            'verified'=>false,'checks'=>['row_available'=>false],'reason'=>$rowError,
            'real_ad_contact_attribution'=>'not_exercised_no_synthetic_entry'] + $context;
    }
        $expected='https://t.me/anytour/'.$pair['target']; $old='https://t.me/any_toour/'.$pair['source'];
        $native=is_array($dst)&&(int)$dst['publication_id']===$pair['publication_id']
            &&$dst['state']==='sent'&&(string)$dst['hotel_id']===(string)$hotel
            &&$dst['post_url']===$expected&&!$dst['native_claim_held_or_unknown'];
        $sameVersion=$native&&(int)$dst['version_id']===$pair['version_id'];
        $photo=$native&&is_array($src)&&count($dst['attachments'])===1&&count($src['attachments'])===1
            &&$dst['attachments'][0]['reusable_telegram_photo']&&$src['attachments'][0]['reusable_telegram_photo']
            &&$dst['attachments'][0]['file_unique_id_sha256']!==null
            &&$dst['attachments'][0]['file_unique_id_sha256']===$src['attachments'][0]['file_unique_id_sha256'];
        $caption=$native&&($pub['ok']??false)&&$dst['caption_sha256']===$pub['caption_sha256'];
        $buttons=$native&&($pub['ok']??false)&&$dst['one_expected_button']&&$dst['current_token_resolves']
            &&($pub['target_keyboard_archive_verified']??false)&&count($pub['buttons'])===1
            &&$pub['buttons'][0]['hotel_url']===$dst['button_url'];
        $route=($feed['tg_'.$hotel]??null)===$old&&($landings['tg_'.$hotel]??null)===$expected;
        $date=($pub['ok']??false)&&$pair['published_unixtime']===$pub['published_unixtime'];
        $originalCaption=($pub['ok']??false)&&$pair['expected_caption_sha256']===$pub['caption_sha256'];
        $checks=['registered_identity'=>$native,'original_version_current'=>$sameVersion,'native_source_photo'=>$photo,
            'public_caption'=>$caption,'public_single_photo'=>($pub['ok']??false)&&$pub['single_photo'],
            'one_hotel_button_and_exact_token_context'=>$buttons,'accepted_original_date'=>$date,'accepted_original_caption'=>$originalCaption,'installed_route'=>$route];
        return ['hotel_id'=>$hotel,'source_message_id'=>$pair['source'],'target_message_id'=>$pair['target'],
            'source_native'=>$src,'target_native'=>$dst,'target_public'=>$pub,'checks'=>$checks,
            'verified'=>!in_array(false,$checks,true),'real_ad_contact_attribution'=>'not_exercised_no_synthetic_entry'] + $context;
}


$out = ['schema'=>1, 'issue'=>1566, 'operation'=>'hotel_accepted_telegram_routes_readback',
    'ok'=>false, 'repository_sha'=>REPOSITORY_SHA, 'application_sha'=>APPLICATION_SHA,
    'native_run_id'=>NATIVE_RUN_ID, 'native_run_attempt'=>1, 'write_admission'=>false,
    'production_writes'=>0, 'provider_writes'=>0, 'bot_starts'=>0, 'native_read_request_count'=>0];
$pdo=null; $priceLock=null; $priceLeaseAcquired=null; $publicAttempts=0; $publicRateLimit=null;
$readStarted=hrtime(true); $stage='installed_release';
try {
    $data=[];
    foreach(PAIRS as $h=>$r) $data[]=[$h,(int)$r['source'],(int)$r['target'],$r['publication_id'],$r['version_id'],$r['published_unixtime'],$r['expected_caption_sha256']];
    requireRead(count($data)===18 && hash_equals(PAIR_DIGEST,hash('sha256',jsonRead($data))),'input_packet_changed');
    runtimeRead();
    requireRead(realpath(OPERATIONS)===OPERATIONS,'operations_root_changed');
    $out['resources_before']=resourcesRead();
    // All external reads precede the SQL transaction and shared PRICE lease.
    $stage='accepted_public_posts'; $public=[];
    foreach(PAIRS as $hotel=>$pair) $public[$hotel]=publicEvidence('anytour',$pair['target']);
    $out['public_observations']=$public;
    $out['public_get_attempts']=$publicAttempts; $out['public_rate_limit']=$publicRateLimit;
    runtimeRead();
    require_once APP.'/hotel-posts-telegram-main19.php';
    $catalogue=anytourTelegramFeedRead(); $feed=anytourTelegramFeedOfferUrls($catalogue);
    requireRead(count($feed)===1055,'feed_inventory_changed');
    $landings=anytourTelegramFeedDestinationPosts($feed);
    $out['current_feed_sha256']=hash('sha256',$catalogue);
    $out['route_feed_source']=['kind'=>'canonical_local_raw_xml','raw_utf8'=>$catalogue,
        'bytes'=>strlen($catalogue),'sha256'=>hash('sha256',$catalogue),'offer_count'=>count($feed)];
    $out['resolver_sha256']=hash_file('sha256',APP.'/hotel-posts-telegram-main19.php');
    $lockPath=OPERATIONS.'/monitor-price-after-collection-v1.jsonl';
    requireRead(!is_link($lockPath)&&is_file($lockPath),'price_editor_lease_unknown');
    $priceLock=fopen($lockPath,'rb'); requireRead(is_resource($priceLock),'price_read_lease_unavailable');
    $lockStarted=hrtime(true); $deadline=$lockStarted+240_000_000_000; $shared=false;
    do { $shared=flock($priceLock,LOCK_SH|LOCK_NB); if($shared||hrtime(true)>=$deadline)break; usleep(2_000_000); } while(true);
    $out['price_lease_wait_ms']=(int)((hrtime(true)-$lockStarted)/1_000_000);
    requireRead($shared,'price_editor_busy_or_unknown'); $priceLeaseAcquired=hrtime(true);
    $out['price_editor_shared_read_lease']=true; runtimeRead();
    $stage='initialize_registry';
    ob_start();
    try { require APP.'/config.php'; require_once APP.'/services/ProjectConfig.php'; require_once APP.'/services/ChannelPostRegistryService.php';
        require_once APP.'/services/ChannelPostButtonTransport.php'; }
    finally {ob_end_clean();}
    $stage='configuration_identity';
    requireRead(ProjectConfig::projectId()==='anytour','project_changed');
    $stage='readonly_database';
    $pdo=ConversationDb::connection(); $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $registry=new ChannelPostRegistryService($pdo);
    $stage='canonical_channels';
    $target=$registry->channel('anytour','telegram','anyru_bot','-1001845835645');
    $source=$registry->channel('anytour','telegram','anyru_bot','-1002262666616');
    requireRead(is_array($target)&&(int)$target['id']===6&&$target['source_key']==='tg_anytour_msk'
        &&is_array($source)&&(int)$source['id']===10,'channel_identity_changed');
    $out['target_channel']=['registry_id'=>6,'source_key'=>$target['source_key'],
        'config_version'=>(int)$target['config_version'],'button_text'=>$target['button_text']];
    $out['mysql_resources_before']=mysqlResourcesRead($pdo);
    $pdo->beginTransaction(); $out['rows']=[];
    foreach(PAIRS as $hotel=>$pair) {
      try {
        $stage='hotel_'.$hotel; $src=publicationRead($pdo,$registry,10,$pair['source']);
        $dst=publicationRead($pdo,$registry,6,$pair['target']); $pub=$public[$hotel];
        $out['rows'][]=telegramAcceptedReadbackRowRead($hotel,$pair,$src,$dst,$pub,$feed,$landings);
      } catch(Throwable $rowError) {
        $out['rows'][]=telegramAcceptedReadbackRowRead($hotel,$pair,null,null,[],$feed,$landings,reasonRead($rowError));
      }
    }
    $out['mysql_resources_after']=mysqlResourcesRead($pdo); $pdo->rollBack(); runtimeRead();
    $out['verified_count']=count(array_filter($out['rows'],static fn($r)=>$r['verified']));
    $out['read_at_utc']=gmdate(DATE_ATOM); $out['ok']=true;
} catch(Throwable $e) {$out['failure_stage']=$stage; $out['failure_reason']=reasonRead($e); $out['failure_type']=get_class($e);}
finally {
    if($pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();
    if(is_resource($priceLock)) {$out['price_lease_held_ms']=$priceLeaseAcquired===null?0:(int)((hrtime(true)-$priceLeaseAcquired)/1_000_000); flock($priceLock,LOCK_UN); fclose($priceLock);}
}
$out['resources_after']=resourcesRead(); $out['read_elapsed_ms']=(int)((hrtime(true)-$readStarted)/1_000_000);
if($out['ok'])$out['checkpoint_sha256']=hash('sha256',jsonRead($out));
echo json_encode($out,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),PHP_EOL;
exit($out['ok']?0:2);
