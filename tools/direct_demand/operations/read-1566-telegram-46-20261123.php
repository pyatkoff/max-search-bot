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


function albumStructureRead(DOMDocument $dom, DOMXPath $xp, DOMNode $node, string $url, string $message, string $body): array
{
    $cls=static fn(string $c):string=>'contains(concat(" ",normalize-space(@class)," ")," '.$c.' ")';
    $texts=$xp->query('.//*['.$cls('tgme_widget_message_text').']',$node);
    $photos=$xp->query('.//*['.$cls('tgme_widget_message_photo_wrap').']',$node);
    $other=$xp->query('.//video|.//audio|.//*['.$cls('tgme_widget_message_video_thumb').' or '.$cls('tgme_widget_message_roundvideo').']',$node);
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
        'single_photo'=>false,'photo_url'=>'','photo_marker_sha256'=>null,'photo_count'=>$photos->length,'other_media_count'=>$other->length,'buttons'=>$buttons,
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
        'photo_count' => $photos->length, 'other_media_count' => $other->length, 'caption_links'=>$captionLinks,
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
$out=['schema'=>1,'operation'=>'hotel_1720_installed_read_and_existing_small_caption_context','ok'=>false,'repository_sha'=>REPOSITORY_SHA,'application_sha'=>APPLICATION_SHA,'native_run_id'=>NATIVE_RUN_ID,'write_admission'=>false,'production_writes'=>0,'provider_writes'=>0,'bot_starts'=>0,'installed_blobs'=>[],'public_rows'=>[],'native_rows'=>[],'pair_public'=>[],'pair_native'=>[]];
$pdo=null;$lock=null;$acquired=null;$stage='installed_runtime';$start=hrtime(true);$rates=[];
try{
 runtimeRead();requireRead(realpath(OPERATIONS)===OPERATIONS,'operations_root_changed');
 foreach(INSTALLED_BLOBS as $path=>$expected){$lines=[];exec('GIT_OPTIONAL_LOCKS=0 git rev-parse '.escapeshellarg('HEAD:'.$path).' 2>/dev/null',$lines,$status);$actual=implode("\n",$lines);requireRead($status===0&&$actual===$expected,'installed_keyboard_guard_source_changed');$worktree=null;if($path==='tools/anytour_existing_post_batch.php'){$lines=[];exec('GIT_OPTIONAL_LOCKS=0 git hash-object -- '.escapeshellarg($path).' 2>/dev/null',$lines,$status);$worktree=implode("\n",$lines);requireRead($status===0&&$worktree===$expected,'installed_keyboard_guard_changed');}$out['installed_blobs'][$path]=['installed_git_blob'=>$actual,'actual_editor_worktree_blob'=>$worktree];}
 $out['resources_before']=resourcesRead();$stage='public_caption_read';
 // Independent public reads precede both SQL transaction and shared PRICE lease.
 foreach(SMALL_CAPTION_SCOPE as $hotel=>$ids)foreach(['telegram','max'] as $platform){$url=$platform==='telegram'?'https://t.me/any_toour/'.$ids[$platform].'?embed=1&mode=tme':'https://max.ru/channel_anytour/'.$ids[$platform];$r=captionPublicBytes($url,$rates);$row=['hotel_id'=>$hotel,'platform'=>$platform,'url'=>$url,'http'=>$r['http']??null,'http_attempts'=>$r['http_attempts'],'raw_sha256'=>$r['body_sha256']??null,'retry_after'=>$r['retry_after']??null,'ok'=>false];
  if($r['ok'])try{if($platform==='telegram'){$seen=parsePublicRead($r['body'],'any_toour',$ids[$platform]);$row['public']=$seen;$row['ok']=true;}else{require_once APP.'/services/PromotedHotelPriceWriter.php';$caption=PromotedHotelPriceWriter::maxPublicCaption($r['body']);$row['caption']=$caption;$row['caption_sha256']=hash('sha256',$caption);$row['native_photo_button_verified']=false;$row['ok']=true;}}catch(Throwable $e){$row['hold_reason']=reasonRead($e);}else$row['hold_reason']=$r['reason']??'public_caption_unavailable';$out['public_rows'][]=$row;
 }
 ob_start();try{require APP.'/config.php';require_once APP.'/services/ProjectConfig.php';require_once APP.'/services/ChannelPostRegistryService.php';require_once APP.'/services/ChannelPostButtonTransport.php';}finally{ob_end_clean();}requireRead(ProjectConfig::projectId()==='anytour','project_changed');

 foreach(PREPARED_PAIRS as $hotel=>$pair){$out['pair_public'][$hotel]=[];foreach(['source'=>'any_toour','target'=>'anytour'] as $which=>$channel){$snapshots=[];foreach(['embed','archive'] as $kind){$url=$kind==='embed'?'https://t.me/'.$channel.'/'.$pair[$which].'?embed=1&mode=tme':'https://t.me/s/'.$channel.'?before='.((int)$pair[$which]+1);$r=captionPublicBytes($url,$rates);try{requireRead($r['ok'],'pair_public_unavailable');$snapshots[$kind]=parsePublicRead($r['body'],$channel,$pair[$which]);}catch(Throwable $e){$snapshots[$kind]=['ok'=>false,'reason'=>reasonRead($e),'http'=>$r['http']??null,'retry_after'=>$r['retry_after']??null];}}
 try{requireRead(isset($snapshots['embed']['url'],$snapshots['archive']['url']),'pair_public_representation_unavailable');$combined=corroborateTargetKeyboard($snapshots['embed'],$snapshots['archive']);$combined['ok']=true;$out['pair_public'][$hotel][$which]=$combined;}catch(Throwable $e){$out['pair_public'][$hotel][$which]=['ok'=>false,'reason'=>reasonRead($e)];}}
 }
 require_once APP.'/hotel-posts-telegram-main19.php';$feed=anytourTelegramFeedOfferUrls(anytourTelegramFeedRead());$routes=anytourTelegramFeedDestinationPosts($feed);$out['feed_count']=count($feed);
 $pdo=ConversationDb::connection();$pdo->exec('SET SESSION TRANSACTION READ ONLY');$registry=new ChannelPostRegistryService($pdo);$channel=$registry->channel('anytour','telegram','anyru_bot','-1002262666616');requireRead(is_array($channel)&&(int)$channel['id']===10,'small_channel_identity_changed');try{require_once APP.'/services/ChannelPostRuntime.php';require_once APP.'/tools/anytour_existing_post_batch.php';$rights=\AnyTour\ExistingPostBatch\transferChannelRights($registry);$out['native_channel_rights']=['ok'=>true,'state'=>'ready','channel_registry_id'=>(int)$rights['id'],'per_message_editability_guaranteed'=>false];}catch(Throwable $e){$out['native_channel_rights']=['ok'=>false,'reason'=>reasonRead($e),'per_message_editability_guaranteed'=>false];}
 $stage='price_shared_lease';$path=OPERATIONS.'/monitor-price-after-collection-v1.jsonl';requireRead(!is_link($path)&&is_file($path),'price_editor_lease_unknown');$lock=fopen($path,'rb');requireRead(is_resource($lock),'price_read_lease_unavailable');$wait=hrtime(true);requireRead(flock($lock,LOCK_SH|LOCK_NB),'price_editor_busy_or_unknown');$acquired=hrtime(true);$out['price_lease_wait_ms']=(int)(($acquired-$wait)/1000000);runtimeRead();
$out['mysql_before']=mysqlResourcesRead($pdo);$pdo->beginTransaction();$stage='small_native_context';
 foreach(SMALL_CAPTION_SCOPE as $hotel=>$ids){$row=['hotel_id'=>$hotel,'message_id'=>$ids['telegram'],'ok'=>false];try{$q=$pdo->prepare('SELECT id FROM post_publications WHERE post_channel_id=10 AND external_message_id=? LIMIT 2');$q->execute([$ids['telegram']]);$found=$q->fetchAll(PDO::FETCH_COLUMN);requireRead(count($found)===1,'small_publication_unconfirmed');$p=$registry->publication((int)$found[0]);requireRead(is_array($p)&&$p['platform']==='telegram'&&$p['bot_key']==='anyru_bot'&&$p['message_id']===$ids['telegram'],'small_publication_identity_changed');$s=$p['snapshot'];$row+=['publication_id'=>$p['publication_id'],'version_id'=>$p['version_id'],'state'=>$p['state'],'native_hotel_id'=>$s['metadata']['hotel_catalog_id']??null,'logical_id'=>$s['metadata']['logical_id']??null,'caption'=>$s['text'],'caption_sha256'=>hash('sha256',$s['text']),'entities'=>$s['entities']??null,'published_at'=>$s['published_at']??null,'button_url'=>ChannelPostButtonTransport::buttonUrl($p),'attachment_types'=>array_column($s['attachments']??[],'type'),'photo_unique_hashes'=>array_map(static fn($a)=>empty($a['file_unique_id'])?null:hash('sha256',$a['file_unique_id']),$s['attachments']??[])];$row['ok']=true;}catch(Throwable $e){$row['hold_reason']=reasonRead($e);}$out['native_rows'][]=$row;}

 foreach(PREPARED_PAIRS as $hotel=>$pair){$row=['hotel_id'=>$hotel,'source_message_id'=>$pair['source'],'target_message_id'=>$pair['target'],'write_admission'=>false];try{$source=$registry->publication($pair['source_publication']);requireRead(is_array($source)&&$source['project_key']==='anytour'&&$source['platform']==='telegram'&&$source['bot_key']==='anyru_bot'&&(int)$source['channel']['id']===10&&$source['message_id']===$pair['source'],'pair_source_identity_changed');$snapshot=$source['snapshot'];
 $q=$pdo->prepare('SELECT id FROM post_publications WHERE post_channel_id=6 AND external_message_id=? LIMIT 2');$q->execute([$pair['target']]);$targets=$q->fetchAll(PDO::FETCH_COLUMN);
 $q=$pdo->prepare("SELECT p.id FROM post_publications p JOIN post_versions v ON v.id=p.current_version_id WHERE p.post_channel_id=6 AND (JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.metadata.hotel_catalog_id'))=? OR JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.metadata.logical_id')) IN (?,?)) LIMIT 2");$q->execute([(string)$hotel,'commercial-hotel-'.$hotel.'-v1','commercial-main-hotel-'.$hotel.'-v1']);$duplicates=$q->fetchAll(PDO::FETCH_COLUMN);
 $q=$pdo->prepare("SELECT id FROM post_versions WHERE publication_id=? AND (state IN ('claimed','write_started','unknown') OR claim_token IS NOT NULL OR claimed_at IS NOT NULL) LIMIT 2");$q->execute([$pair['source_publication']]);$held=$q->fetchAll(PDO::FETCH_COLUMN);
 $sp=$out['pair_public'][$hotel]['source'];$tp=$out['pair_public'][$hotel]['target'];$button=ChannelPostButtonTransport::buttonUrl($source);$context=$registry->resolveToken('anytour','telegram','anyru_bot',$source['start_payload']);$directory=OPERATIONS.'/anytour-issue1566-historical-tg-media-'.$pair['target'].'-v1';$media=$snapshot['attachments']??[];
 $checks=['source_native_identity'=>$source['state']==='sent'&&(int)($snapshot['metadata']['hotel_catalog_id']??0)===$hotel,'source_public_body'=>(($sp['ok']??false)&&($sp['single_photo']??false)&&($sp['caption_sha256']??null)===hash('sha256',$snapshot['text'])),'source_one_live_button'=>(($sp['ok']??false)&&count($sp['buttons']??[])===1&&($sp['buttons'][0]['hotel_url']??null)===$button),'source_token_context'=>is_array($context)&&$context['publication_id']===$source['publication_id']&&$context['version_id']===$source['version_id'],'source_photo'=>count($media)===1&&($media[0]['type']??null)==='image'&&!empty($media[0]['telegram_file_id'])&&!empty($media[0]['file_unique_id']),'source_no_writer'=>$held===[],'target_no_registry_or_duplicate'=>$targets===[]&&$duplicates===[],'target_no_prior_media_operation'=>!file_exists($directory)&&!is_link($directory),'target_preimage'=>(($tp['ok']??false)&&($tp['single_photo']??false)&&($tp['caption_sha256']??null)===$pair['target_caption']&&($tp['published_unixtime']??null)===$pair['target_date']&&($tp['photo_marker_sha256']??null)===$pair['target_photo']),'target_no_keyboard'=>(($tp['ok']??false)&&($tp['buttons']??null)===[]),'canonical_feed_and_route'=>($feed['tg_'.$hotel]??null)==='https://t.me/any_toour/'.$pair['source']&&($routes['tg_'.$hotel]??null)==='https://t.me/any_toour/'.$pair['source']&&!in_array('https://t.me/anytour/'.$pair['target'],$routes,true),'native_channel_rights'=>($out['native_channel_rights']['ok']??false)];
 $row+=['source_publication_id'=>$source['publication_id'],'source_version_id'=>$source['version_id'],'source_content_hash'=>$source['content_hash'],'source_caption'=>$snapshot['text'],'source_caption_sha256'=>hash('sha256',$snapshot['text']),'source_photo_file_id_sha256'=>count($media)===1?hash('sha256',$media[0]['telegram_file_id']??''):null,'source_photo_file_unique_id_sha256'=>count($media)===1?hash('sha256',$media[0]['file_unique_id']??''):null,'checks'=>$checks,'prepared_checks_pass'=>!in_array(false,$checks,true),'target_native_ids'=>$targets,'hotel_duplicate_publication_ids'=>$duplicates,'native_writer_ids'=>$held,'advertising_scope'=>'canonical existing feed and resolver; no advertiser campaign changes'];
 }catch(Throwable $e){$row['hold_reason']=reasonRead($e);}$out['pair_native'][]=$row;}
 $pdo->rollBack();$out['mysql_after']=mysqlResourcesRead($pdo);runtimeRead();$out['ok']=true;
}catch(Throwable $e){$out['failure_stage']=$stage;$out['failure_reason']=reasonRead($e);}
finally{if($pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();if(is_resource($lock)){if($acquired!==null)$out['price_lease_held_ms']=(int)((hrtime(true)-$acquired)/1000000);flock($lock,LOCK_UN);fclose($lock);}}
$out['resources_after']=resourcesRead();$out['read_at_utc']=gmdate(DATE_ATOM);$out['read_elapsed_ms']=(int)((hrtime(true)-$start)/1000000);echo jsonRead($out),PHP_EOL;exit($out['ok']?0:2);
