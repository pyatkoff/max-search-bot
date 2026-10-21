<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/integrations/MaxPrivateAvatarProbe.php';
require_once __DIR__.'/max_owner_preview_images.php';

/** Fixed one-off demo, deliberately disconnected from Manager2 and callback dispatch. */
function ownerPreviewArguments(array $args): ?string
{
    if (count($args)!==2 || count(array_unique($args))!==2 || !in_array('--confirm-owner-send',$args,true)) return null;
    foreach ($args as $arg) if (preg_match('/\A--expected-sha=([0-9a-f]{40})\z/D',$arg,$m)) return $m[1];
    return null;
}

function ownerPreviewBody(): array
{
    return ['text'=>"Тест оформления — даты, цена и условия демонстрационные.\n\nDelphin Imperial 5★ · Турция, Лара\n5–12 июня 2027 · 7 ночей\nИз Москвы · 2 взрослых\nУлучшенный номер · Ультра всё включено\n238 400 ₽ за двоих\n\nВ примере: перелёт, трансфер и страховка включены.\nФото номера — пример интерьера отеля.",
        'attachments'=>[
            ['type'=>'image','payload'=>['url'=>'https://www.delphinhotel.com/main_pics/pages/medium/2996.png']],
            ['type'=>'image','payload'=>['url'=>'https://www.delphinhotel.com/main_pics/pages/medium/1823.jpg']],
            ['type'=>'image','payload'=>['url'=>'https://www.delphinhotel.com/main_pics/pages/medium/709.png']],
            ['type'=>'inline_keyboard','payload'=>['buttons'=>[[['type'=>'message','text'=>'Нравится']]]]],
        ]];
}

function ownerPreviewComparisonArguments(array $args): ?string
{
    if (!in_array('--confirm-owner-comparison',$args,true)) return null;
    return ownerPreviewArguments(array_map(static fn($arg)=>$arg==='--confirm-owner-comparison'?'--confirm-owner-send':$arg,$args));
}

function ownerPreviewVariantsArguments(array $args): ?string
{
    if (!in_array('--confirm-owner-variants',$args,true)) return null;
    return ownerPreviewArguments(array_map(static fn($arg)=>$arg==='--confirm-owner-variants'?'--confirm-owner-send':$arg,$args));
}

function ownerPreviewComparisonText(string $variant='comparison'): string
{
    if ($variant==='compact') return "Delphin Imperial 5★ · Турция, Лара\n5–12 июня 2027 · 7 ночей\nМосква · 2 взрослых\nУлучшенный номер\nУльтра всё включено\n\n238 400 ₽ за двоих\nПерелёт, трансфер и страховка включены.\n\nДемо: даты, цена и условия — пример.\nФото номера — пример интерьера отеля.";
    if ($variant==='price-first') return "Delphin Imperial 5★ · Турция, Лара\n238 400 ₽ за двоих\n\n5–12 июня 2027 · 7 ночей\nМосква · 2 взрослых\nУлучшенный номер\nУльтра всё включено\nПерелёт, трансфер и страховка включены.\n\nДемо: даты, цена и условия — пример.\nФото номера — пример интерьера отеля.";
    if ($variant!=='comparison') throw new InvalidArgumentException('Unknown fixed variant');
    return "Delphin Imperial 5★ · Турция, Лара\n5–12 июня 2027 · 7 ночей\nМосква · 2 взрослых\nУлучшенный номер · Ультра всё включено\n\n238 400 ₽ за двоих\nПерелёт, трансфер и страховка включены.\n\nДемо: даты, цена и условия — пример.\nФото номера — пример интерьера отеля.";
}

/** Fixed text contains BMP characters only, so codepoint and UTF-16 offsets coincide. */
function ownerPreviewComparisonMarkup(string $variant='comparison'): array
{
    $text=ownerPreviewComparisonText($variant);$spans=[];
    foreach (['Delphin Imperial 5★','238 400 ₽ за двоих'] as $bold) {
        $spans[]=['type'=>'strong','from'=>preg_match_all('/./us',substr($text,0,strpos($text,$bold))),'length'=>preg_match_all('/./us',$bold)];
    }
    return $spans;
}

function ownerPreviewComparisonBody(string $variant='comparison'): array
{
    $body=ownerPreviewBody();
    $body['text']=str_replace(['Delphin Imperial 5★','238 400 ₽ за двоих'],['<b>Delphin Imperial 5★</b>','<b>238 400 ₽ за двоих</b>'],ownerPreviewComparisonText($variant));
    $body['format']='html';
    $body['attachments'][1]['payload']['url']='https://www.delphinhotel.com/main_pics/pages/big/4325.png';
    return $body;
}

/** MAX readback contains plain text and strong spans, rather than the submitted HTML. */
function ownerPreviewComparisonMatches(?array $message,string $mid,string $user,string $bot,string $chat,string $variant='comparison'): bool
{
    if (($message['body']['text']??null)!==ownerPreviewComparisonText($variant)) return false;
    $markup=$message['body']['markup']??null;$expected=ownerPreviewComparisonMarkup($variant);
    if (!is_array($markup)||count($markup)!==2) return false;
    foreach ($markup as $span) {
        if (!is_array($span)||count($span)!==3) return false;
        $match=array_search(['type'=>$span['type']??null,'from'=>$span['from']??null,'length'=>$span['length']??null],$expected,true);
        if ($match===false) return false;
        unset($expected[$match]);
    }
    $message['body']['text']=ownerPreviewBody()['text'];
    return $expected===[]&&ownerPreviewMatches($message,$mid,$user,$bot,$chat);
}

/** No transcript text, arbitrary recipient lookup, business write or open transaction escapes. */
function ownerPreviewTarget(PDO $db): ?array
{
    if ($db->inTransaction()) return null;
    try {
        $db->exec('SET TRANSACTION READ ONLY');
        $db->beginTransaction();
        $q=$db->prepare('SELECT c.id,c.project_key,c.channel,c.status,cu.display_name AS original_display_name,c.external_chat_id AS conversation_chat_id,cc.external_chat_id AS channel_chat_id,cc.external_user_id FROM conversations c JOIN customers cu ON cu.id=c.customer_id JOIN customer_channels cc ON cc.id=c.customer_channel_id AND cc.customer_id=c.customer_id AND cc.project_key=c.project_key AND cc.channel=c.channel JOIN projects p ON p.project_key=c.project_key AND p.is_active=1 WHERE c.id=? AND c.project_key=? AND c.channel=? AND cu.display_name=? LIMIT 1');
        $q->execute(['2','anytour','max','Pavel']);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;
        $q=$db->prepare("SELECT m.external_message_id,m.sender_id AS message_sender_id FROM messages m WHERE m.conversation_id=? AND m.channel=? AND m.direction=? AND m.sender_type=? AND m.sender_id=? AND m.external_message_id IS NOT NULL AND m.external_message_id<>'' AND CASE WHEN JSON_VALID(m.metadata_json) THEN JSON_UNQUOTE(JSON_EXTRACT(m.metadata_json,'$.type')) ELSE NULL END='message' ORDER BY m.id DESC LIMIT 1");
        $q->execute(['2','max','inbound','customer',(string)$row['external_user_id']]);
        $message=$q->fetch(PDO::FETCH_ASSOC);
        return is_array($message)?$row+$message:null;
    } catch (Throwable $e) { return null; }
    finally { if ($db->inTransaction()) $db->rollBack(); }
}

/** Private error message; fixed-demo edits apply further redaction before bounded public output. */
function ownerPreviewPrivateMessage($value,string $token,array $redactions=[]): ?string
{
    if (!is_string($value)) return null;
    if ($token!=='') $value=str_replace($token,'[redacted-token]',$value);
    foreach ($redactions as $id) if (is_string($id)&&$id!=='') $value=str_replace($id,'[redacted-id]',$value);
    $value=preg_replace('/\bmid\.[A-Za-z0-9_-]+/','[redacted-message]',$value);
    $value=preg_replace('~(?:[a-z][a-z0-9+.-]{1,15}://|www\.)[^\s<>"\x27]+~iu','[redacted-url]',$value);
    $value=preg_replace('/[0-9]{5,}/','[redacted-number]',$value);
    $value=preg_replace('/[\p{C}]+/u',' ',$value);
    if (!is_string($value)) return null;
    $value=substr($value,0,2048);
    while ($value!==''&&preg_match('//u',$value)!==1) $value=substr($value,0,-1);
    return $value;
}

/** Only a pre-redacted provider message about the fixed public demo may reach stdout. */
function ownerPreviewValidationMessage($value,string $token,array $redactions): ?string
{
    $value=ownerPreviewPrivateMessage($value,$token,$redactions);
    if ($value===null) return null;
    $value=preg_replace('/[0-9]+/','[redacted-number]',$value);
    $value=preg_replace('/\[Source:.*$/is','[redacted-source]',$value);
    return ownerPreviewPrivateMessage($value,'');
}

/** Allowlisted public codes and separately redacted private error message. */
function ownerPreviewDecode(string $bytes,int $status,int $curl,int $tls,bool $overflow=false,string $token='',array $redactions=[]): array
{
    $data=json_decode($bytes,true,64,JSON_BIGINT_AS_STRING);
    $code=is_array($data)?($data['code']??null):null;
    $diagnostic=['http_status'=>max(0,min(599,$status)),'curl_code'=>max(0,min(999,$curl)),
        'error_category'=>$overflow?'response_too_large':($tls!==0?'tls':($curl!==0?'transport':($status<200||$status>=300?'http_rejected':(!is_array($data)?'invalid_response':(($data['success']??null)===false?'provider_rejected':'none'))))),
        'api_code'=>in_array($code,['proto.payload','proto.query','proto.auth','access.denied','chat.not.found','user.not.found',
            'dialog.suspended','error.dialog.suspended','attachment.not.ready','attachment.invalid','message.empty','message.too.long'],true)?$code:null];
    $privateCode=is_string($code)&&preg_match('/\A[a-z][a-z._]{1,63}\z/D',$code)?$code:null;
    $privateMessage=$diagnostic['error_category']!=='none'?ownerPreviewPrivateMessage(is_array($data)?($data['message']??null):null,$token,$redactions):null;
    return ['data'=>$diagnostic['error_category']==='none'?$data:null,'diagnostic'=>$diagnostic,'private_api_code'=>$privateCode,'private_api_message'=>$privateMessage];
}

/** Fixed HTTPS origin; bounded/redacted exchange; no redirects, retries or raw logging. */
function ownerPreviewRequest(string $method,string $path,array $query,?array $body,string $token): array
{
    $url='https://platform-api2.max.ru'.$path.($query?'?'.http_build_query($query):'');
    $bytes='';$overflow=false;$ch=curl_init($url);
    $options=MaxTlsConfig::strictCurlOptions()+[
        CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>30,
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER=>['Authorization: '.$token,'Content-Type: application/json'],
        CURLOPT_WRITEFUNCTION=>static function($ch,string $part) use (&$bytes,&$overflow): int {
            if (strlen($bytes)+strlen($part)>262144) { $overflow=true;return 0; }
            $bytes.=$part;return strlen($part);
        },
    ];
    if ($body!==null) $options[CURLOPT_POSTFIELDS]=json_encode($body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    curl_setopt_array($ch,$options);curl_exec($ch);
    $redactions=array_values($query);
    foreach (($body['attachments']??[]) as $attachment) if (is_string($attachment['payload']['token']??null)) $redactions[]=$attachment['payload']['token'];
    $result=ownerPreviewDecode($bytes,(int)curl_getinfo($ch,CURLINFO_HTTP_CODE),curl_errno($ch),(int)curl_getinfo($ch,CURLINFO_SSL_VERIFYRESULT),$overflow,$token,$redactions);
    curl_close($ch);return $result;
}

/** Legacy array/null responses remain supported only for existing offline fixtures. */
function ownerPreviewData(?array $response): ?array
{
    return isset($response['diagnostic'])?($response['data']??null):$response;
}
function ownerPreviewDiagnostic(?array $response): array
{
    return $response['diagnostic']??['http_status'=>0,'curl_code'=>0,'error_category'=>'unavailable','api_code'=>null];
}
function ownerPreviewMid(?array $message): ?string
{
    $message=$message['message']??$message;
    $mid=$message['body']['mid']??$message['mid']??null;
    return is_string($mid)&&preg_match('/\A(?:mid\.)?[A-Za-z0-9_-]{1,200}\z/D',$mid)?$mid:null;
}
function ownerPreviewMatches(?array $check,string $mid,string $user,string $bot,string $chat): bool
{
    $attachments=$check['body']['attachments']??[];$images=0;$keyboards=[];
    foreach ((array)$attachments as $attachment) {
        if (($attachment['type']??null)==='image') $images++;
        elseif (($attachment['type']??null)==='inline_keyboard') $keyboards[]=$attachment['payload']['buttons']??null;
    }
    $buttons=$keyboards[0]??null;$row=is_array($buttons)?($buttons[0]??null):null;$button=is_array($row)?($row[0]??null):null;
    $like=count($keyboards)===1&&is_array($buttons)&&count($buttons)===1&&is_array($row)&&count($row)===1
        &&is_array($button)&&count($button)===2&&($button['type']??null)==='message'&&($button['text']??null)==='Нравится';
    return ownerPreviewMid($check)===$mid && ($check['body']['text']??null)===ownerPreviewBody()['text']
        && MaxPrivateAvatarProbe::decimalId($check['sender']['user_id']??null)===$bot
        && MaxPrivateAvatarProbe::decimalId($check['recipient']['user_id']??null)===$user
        && MaxPrivateAvatarProbe::chatId($check['recipient']['chat_id']??null)===$chat && ($check['recipient']['chat_type']??null)==='dialog'
        && $images===3 && count((array)$attachments)===4 && $like;
}

function ownerPreviewWrite($file,array $receipt): bool
{
    $bytes=json_encode($receipt,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
    if (strlen($bytes)>65536) return false;
    return ftruncate($file,0) && rewind($file) && fwrite($file,$bytes)===strlen($bytes) && fflush($file)
        && function_exists('fsync') && fsync($file);
}

function ownerPreviewSyncDirectory(string $path): bool
{
    $directory=@fopen($path,'r');
    if ($directory===false) return false;
    try { return function_exists('fsync') && fsync($directory); }
    finally { fclose($directory); }
}

function ownerPreviewRecoveryArguments(array $args): ?string
{
    if (!in_array('--confirm-owner-recovery',$args,true)) return null;
    return ownerPreviewArguments(array_map(static fn($arg)=>$arg==='--confirm-owner-recovery'?'--confirm-owner-send':$arg,$args));
}

/** Fixed original receipt is read, never rewritten by recovery. */
function ownerPreviewOriginal(string $directory): ?array
{
    $path=$directory.'/delphin-imperial-20261021.json';
    if (is_link($directory)||!is_dir($directory)||(fileperms($directory)&0777)!==0700
        ||is_link($path)||!is_file($path)||(fileperms($path)&0777)!==0600) return null;
    $bytes=@file_get_contents($path,false,null,0,4097);
    $receipt=is_string($bytes)&&strlen($bytes)<=4096?json_decode($bytes,true):null;
    return is_array($receipt)&&($receipt['demo']??null)==='delphin-imperial-20261021'&&($receipt['state']??null)==='unknown'
        ? ['hash'=>hash('sha256',$bytes)]:null;
}

function ownerPreviewTimestamp($value): ?int
{
    if (is_string($value)&&preg_match('/\A[0-9]{1,13}\z/D',$value)) $value=(int)$value;
    return is_int($value)&&$value>0&&$value<=4102444800000?$value:null;
}

/** Empty/private-incomplete history is never proof: the just-verified owner MID must occur exactly once. */
function ownerPreviewRecoveryHistory(?array $history,array $identity,string $user,string $bot,string $chat,int $upper): array
{
    $out=['complete'=>false,'messages'=>0,'anchor_found'=>false,'bot_messages'=>0,'media_messages'=>0,'suspect_messages'=>0];
    $messages=$history['messages']??null;
    if (!is_array($messages)||!array_is_list($messages)||count($messages)>=100) return $out;
    $out['messages']=count($messages);$anchors=0;
    foreach ($messages as $message) {
        $timestamp=ownerPreviewTimestamp($message['timestamp']??null);
        $sender=MaxPrivateAvatarProbe::decimalId($message['sender']['user_id']??null);
        if (!is_array($message)||$timestamp===null||$timestamp<1792539612000||$timestamp>$upper
            ||MaxPrivateAvatarProbe::chatId($message['recipient']['chat_id']??null)!==$chat
            ||($message['recipient']['chat_type']??null)!=='dialog') { $out['suspect_messages']++;continue; }
        // Optional/foreign recipient.user_id must never hide evidence of a same-chat bot send.
        if ($sender===$bot||($message['sender']['is_bot']??false)===true) $out['bot_messages']++;
        if (($message['body']['attachments']??[])!==[]) $out['media_messages']++;
        if ($sender!==$user||isset($message['recipient']['user_id'])&&MaxPrivateAvatarProbe::decimalId($message['recipient']['user_id'])!==$bot) $out['suspect_messages']++;
        if ($sender===$user&&ownerPreviewMid($message)===ownerPreviewMid($identity)&&$timestamp===ownerPreviewTimestamp($identity['timestamp']??null)) $anchors++;
    }
    $out['anchor_found']=$anchors===1;
    $out['complete']=$out['anchor_found']&&$out['bot_messages']===0&&$out['media_messages']===0&&$out['suspect_messages']===0;
    return $out;
}

/** Only the historical, definite payload rejection may enter the fixed repair once. */
function ownerPreviewKnownRejection(string $path,string $originalHash): ?array
{
    clearstatcache(true,$path);
    if (is_link($path)||!is_file($path)||(fileperms($path)&0777)!==0600) return null;
    $file=@fopen($path,'r+b');
    if ($file===false) return null;
    $keep=false;
    try {
        if (!flock($file,LOCK_EX|LOCK_NB)) return null;
        $opened=fstat($file);$current=stat($path);
        if ($opened===false||$current===false||$opened['ino']!==$current['ino']||$opened['dev']!==$current['dev']) return null;
        $bytes=stream_get_contents($file,65537);$record=is_string($bytes)&&strlen($bytes)<=65536?json_decode($bytes,true):null;
        if (!is_array($record)||($record['demo']??null)!=='delphin-imperial-20261021'
            ||($record['original_receipt_sha256']??null)!==$originalHash||!is_string($record['created_at']??null)) return null;
        $edit=($record['repair_revision']??null)==='payload-repair-v1'
            && ownerPreviewMid(['mid'=>$record['message_id']??null])!==null
            && MaxPrivateAvatarProbe::decimalId($record['verified_owner_id']??null)!==null
            && MaxPrivateAvatarProbe::decimalId($record['verified_bot_id']??null)!==null
            && MaxPrivateAvatarProbe::chatId($record['verified_chat_id']??null)!==null;
        if (!$edit&&strlen($bytes)>8192) return null;
        if (!$edit && (($record['state']??null)!=='unknown'
            ||array_key_exists('message_id',$record)||array_key_exists('repair_revision',$record)||array_key_exists('previous_recovery',$record)
            ||($record['send_diagnostic']['http_status']??null)!==400||($record['send_diagnostic']['curl_code']??null)!==0
            ||($record['send_diagnostic']['error_category']??null)!=='http_rejected'||($record['send_diagnostic']['api_code']??null)!=='proto.payload'
            ||($record['private_api_code']??null)!=='proto.payload')) return null;
        $keep=true;return ['file'=>$file,'record'=>$record,'hash'=>hash('sha256',$bytes),'edit'=>$edit];
    } finally { if (!$keep) fclose($file); }
}

/** Locked replacement is fsynced and atomically installed before a repair POST. */
function ownerPreviewRepairReservation(string $journal,array $prior,array $receipt)
{
    if (!rewind($prior['file'])) return false;
    $bytes=stream_get_contents($prior['file'],65537);
    clearstatcache(true,$journal);
    $opened=fstat($prior['file']);$current=stat($journal);
    if (!is_string($bytes)||strlen($bytes)>65536||!hash_equals($prior['hash'],hash('sha256',$bytes))||is_link($journal)
        ||$opened===false||$current===false||$opened['ino']!==$current['ino']||$opened['dev']!==$current['dev']) return false;
    $temporary=$journal.'.payload-repair-v1.tmp';
    $old=umask(0077);$file=@fopen($temporary,'x+');umask($old);
    if ($file===false) return false;
    $keep=false;
    try {
        if (((fstat($file)['mode']??0)&0777)!==0600||!flock($file,LOCK_EX|LOCK_NB)
            ||!ownerPreviewWrite($file,$receipt+['state'=>'attempt_reserved'])||!ownerPreviewSyncDirectory(dirname($journal))
            ||!rename($temporary,$journal)||!ownerPreviewSyncDirectory(dirname($journal))) return false;
        clearstatcache(true,$journal);$keep=true;return $file;
    } finally { if (!$keep) fclose($file); }
}

/** Repair outcomes also replace atomically: a torn terminal write cannot erase prior evidence. */
function ownerPreviewRecoveryWrite(&$file,string $journal,array $receipt,bool $repair): bool
{
    if (strlen(json_encode($receipt,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES))+1>65536) return false;
    if (!$repair) return ownerPreviewWrite($file,$receipt);
    if (!rewind($file)) return false;
    $bytes=stream_get_contents($file,65537);
    if (!is_string($bytes)||strlen($bytes)>65536) return false;
    $replacement=ownerPreviewRepairReservation($journal,['file'=>$file,'hash'=>hash('sha256',$bytes)],$receipt);
    if ($replacement===false) return false;
    fclose($file);$file=$replacement;return true;
}

/** Edit one durably stored own demo MID. This path can never create a message. */
function ownerPreviewEdit(array $out,array $receipt,&$file,string $journal,callable $request,string $token,bool $atomic,?array $transfers=null): array
{
    $mid=$receipt['message_id'];$user=$receipt['verified_owner_id'];$bot=$receipt['verified_bot_id'];$chat=$receipt['verified_chat_id'];
    $out['edit_attempted']=false;
    try {
    $out['provider_requests']++;
    $response=$request('GET','/messages/'.rawurlencode($mid),[],null,$token);
    $out['readback_diagnostic']=ownerPreviewDiagnostic($response);$message=ownerPreviewData($response);
    $same=ownerPreviewMid($message)===$mid&&($message['body']['text']??null)===ownerPreviewBody()['text']
        &&MaxPrivateAvatarProbe::decimalId($message['sender']['user_id']??null)===$bot
        &&MaxPrivateAvatarProbe::decimalId($message['recipient']['user_id']??null)===$user
        &&MaxPrivateAvatarProbe::chatId($message['recipient']['chat_id']??null)===$chat&&($message['recipient']['chat_type']??null)==='dialog';
    if (!$same) return array_replace($out,['state'=>'edit_identity_unverified']);
    if (ownerPreviewMatches($message,$mid,$user,$bot,$chat)) {
        $out['ok']=true;$out['state']='verified';
        ownerPreviewRecoveryWrite($file,$journal,array_replace($receipt,['state'=>'verified']),$atomic);return $out;
    }
    if (!ownerPreviewRecoveryWrite($file,$journal,array_replace($receipt,['state'=>'images_preparing']),$atomic)) return array_replace($out,['state'=>'recovery_receipt_unavailable']);
    $body=ownerPreviewPrepareImages($receipt,$file,$journal,$request,$token,$atomic,$out,$transfers);
    if ($body===null) return array_replace($out,['state'=>'image_preparation_unverified']);
    if (!ownerPreviewRecoveryWrite($file,$journal,array_replace($receipt,['state'=>'edit_attempt_reserved']),$atomic)) return array_replace($out,['state'=>'recovery_receipt_unavailable']);
    $out['edit_attempted']=true;$out['provider_requests']++;
    $response=$request('PUT','/messages',['message_id'=>$mid],$body,$token);
    $out['edit_diagnostic']=ownerPreviewDiagnostic($response);$receipt['edit_diagnostic']=$out['edit_diagnostic'];
    $receipt['private_api_code']=$response['private_api_code']??null;$receipt['private_api_message']=$response['private_api_message']??null;
    if ($out['edit_diagnostic']['error_category']!=='none') {
        $value=ownerPreviewValidationMessage($response['private_api_message']??null,$token,[$mid,$user,$bot,$chat]);
        if ($value!==null) $out['validation_message']=$value;
    }
    $out['state']='edit_unverified';
    ownerPreviewRecoveryWrite($file,$journal,array_replace($receipt,['state'=>$out['state']]),$atomic);
    $out['provider_requests']++;
    $response=$request('GET','/messages/'.rawurlencode($mid),[],null,$token);
    $out['readback_diagnostic']=ownerPreviewDiagnostic($response);
    $out['ok']=ownerPreviewMatches(ownerPreviewData($response),$mid,$user,$bot,$chat);
    $out['state']=$out['ok']?'verified':'edit_unverified';
    ownerPreviewRecoveryWrite($file,$journal,array_replace($receipt,['state'=>$out['state']]),$atomic);
    return $out;
    } catch (Throwable $e) {
        $out['state']=$out['edit_attempted']?'edit_unverified':'edit_identity_unverified';
        if ($out['edit_attempted']) ownerPreviewRecoveryWrite($file,$journal,array_replace($receipt,['state'=>$out['state']]),$atomic);
        return $out;
    }
}

/** One recovery, or one fixed repair of its definite historical payload rejection. */
function ownerPreviewRecoverRun(string $expected,string $installed,string $project,string $token,callable $target,callable $request,string $directory,?int $now=null,?array $transfers=null): array
{
    $out=['ok'=>false,'state'=>'blocked','send_attempted'=>false,'provider_requests'=>0];
    if (!preg_match('/\A[0-9a-f]{40}\z/D',$expected)||$expected!==$installed||$project!=='anytour'||$token===''||strlen($token)>4096||preg_match('/[\x00-\x20\x7f]/',$token)) return $out;
    $journal=$directory.'/delphin-imperial-20261021-recovery.json';
    $original=ownerPreviewOriginal($directory);
    if ($original===null) return array_replace($out,['state'=>'original_receipt_unavailable']);
    $prior=null;
    if (file_exists($journal)||is_link($journal)) {
        $prior=ownerPreviewKnownRejection($journal,$original['hash']);
        if ($prior===null) return array_replace($out,['state'=>'recovery_already_attempted']);
    }
    try {
    $row=$target();
    if (!is_array($row)||(string)($row['id']??'')!=='2'||($row['project_key']??'')!=='anytour'||($row['channel']??'')!=='max'||($row['original_display_name']??'')!=='Pavel'
        ||!in_array($row['status']??'', ['waiting_manager','manager'],true)) return array_replace($out,['state'=>'owner_unavailable']);
    $user=MaxPrivateAvatarProbe::decimalId($row['external_user_id']??null);$alias=MaxPrivateAvatarProbe::chatId($row['conversation_chat_id']??null);$mid=$row['external_message_id']??'';
    if ($user===null||$alias===null||MaxPrivateAvatarProbe::chatId($row['channel_chat_id']??null)!==$alias||MaxPrivateAvatarProbe::decimalId($row['message_sender_id']??null)!==$user
        ||!is_string($mid)||!preg_match('/\A(?:mid\.)?[A-Za-z0-9_-]{1,200}\z/D',$mid)) return array_replace($out,['state'=>'owner_unavailable']);
    if (($prior['edit']??false)===true) {
        $receipt=$prior['record'];$file=$prior['file'];
        if ($receipt['verified_owner_id']!==$user) return array_replace($out,['state'=>'owner_unavailable']);
        try { return ownerPreviewEdit($out,$receipt,$file,$journal,$request,$token,true,$transfers); }
        finally { if (is_resource($file)) fclose($file); }
    }
    try {
        $out['provider_requests']++;
        $response=$request('GET','/messages/'.rawurlencode($mid),[],null,$token);
        $out['request_diagnostic']=ownerPreviewDiagnostic($response);$identity=ownerPreviewData($response);
        $chat=MaxPrivateAvatarProbe::chatId($identity['recipient']['chat_id']??null);$bot=MaxPrivateAvatarProbe::decimalId($identity['recipient']['user_id']??null);
        $timestamp=ownerPreviewTimestamp($identity['timestamp']??null);$text=$identity['body']['text']??null;
        if (ownerPreviewMid($identity)!==$mid||MaxPrivateAvatarProbe::decimalId($identity['sender']['user_id']??null)!==$user||($identity['recipient']['chat_type']??null)!=='dialog'||$chat===null||$bot===null
            ||$timestamp===null||$timestamp<1792539644000||!is_string($text)||preg_match('/пуст/ui',$text)!==1) return array_replace($out,['state'=>'recovery_owner_unverified']);
        $upper=($now??time())*1000;
        if ($timestamp>$upper) return array_replace($out,['state'=>'recovery_owner_unverified']);
        $out['provider_requests']++;
        // MAX: from is the upper boundary, to is the lower boundary, in Unix milliseconds.
        $response=$request('GET','/messages',['chat_id'=>$chat,'from'=>$upper,'to'=>1792539612000,'count'=>100],null,$token);
        $out['request_diagnostic']=ownerPreviewDiagnostic($response);
        $out['preflight']=ownerPreviewRecoveryHistory(ownerPreviewData($response),$identity,$user,$bot,$chat,$upper);
        if (!$out['preflight']['complete']) return array_replace($out,['state'=>'recovery_history_unproven']);
        if (ownerPreviewOriginal($directory)!==$original) return array_replace($out,['state'=>'original_receipt_changed']);
    } catch (Throwable $e) { return array_replace($out,['state'=>'recovery_preflight_unavailable']); }
    $receipt=['demo'=>'delphin-imperial-20261021','created_at'=>gmdate('c'),'original_receipt_sha256'=>$original['hash'],'repair_revision'=>'payload-repair-v1','verified_owner_id'=>$user,'verified_bot_id'=>$bot,'verified_chat_id'=>$chat];
    if ($prior!==null) {
        $receipt['previous_recovery']=$prior['record'];$receipt['previous_recovery_sha256']=$prior['hash'];
        $file=ownerPreviewRepairReservation($journal,$prior,$receipt);
    } else {
        $old=umask(0077);$file=@fopen($journal,'x+');umask($old);
    }
    if ($file===false) return array_replace($out,['state'=>'recovery_already_attempted']);
    try {
        if (((fstat($file)['mode']??0)&0777)!==0600||!flock($file,LOCK_EX|LOCK_NB)||($prior===null&&!ownerPreviewWrite($file,$receipt+['state'=>'attempt_reserved']))||!ownerPreviewSyncDirectory($directory)||!ownerPreviewSyncDirectory(dirname($directory))) return array_replace($out,['state'=>'recovery_receipt_unavailable']);
        if (ownerPreviewOriginal($directory)!==$original) return array_replace($out,['state'=>'original_receipt_changed']);
        $out['send_attempted']=true;$out['provider_requests']++;
        $response=$request('POST','/messages',['user_id'=>$user],['text'=>ownerPreviewBody()['text']],$token);
        $out['send_diagnostic']=ownerPreviewDiagnostic($response);$receipt['send_diagnostic']=$out['send_diagnostic'];
        $receipt['private_api_code']=$response['private_api_code']??null;
        $receipt['private_api_message']=$response['private_api_message']??null;
        $sentMid=ownerPreviewMid(ownerPreviewData($response));
        $out['state']=$sentMid===null?'unknown':'text_created';
        if ($sentMid!==null) $receipt['message_id']=$sentMid;
        if (!ownerPreviewRecoveryWrite($file,$journal,$receipt+['state'=>$out['state']],$prior!==null)) return array_replace($out,['state'=>'recovery_receipt_unavailable']);
        if ($sentMid===null) return $out;
        return ownerPreviewEdit($out,$receipt,$file,$journal,$request,$token,$prior!==null,$transfers);
    } catch (Throwable $e) {
        $out['state']=isset($receipt['message_id'])?'sent_unverified':($out['send_attempted']?'unknown':'recovery_receipt_unavailable');
        ownerPreviewRecoveryWrite($file,$journal,$receipt+['state'=>$out['state']],$prior!==null);return $out;
    } finally { fclose($file); }
    } finally { if ($prior!==null&&is_resource($prior['file'])) fclose($prior['file']); }
}

/** Callables are offline test seams only. The CLI below supplies only fixed production dependencies. */
/** Read the already verified comparison cache; never rewrite or repair its receipt. */
function ownerPreviewComparisonCache(string $directory,string $sourceHash,string $sourceMid,string $user,string $bot,string $chat): ?array
{
    $path=$directory.'/delphin-imperial-compact-compare-20261021.json';
    if (is_link($path)||!is_file($path)||(fileperms($path)&0777)!==0600) return null;
    $file=@fopen($path,'rb');
    if ($file===false) return null;
    try {
        if (!flock($file,LOCK_SH|LOCK_NB)) return null;
        clearstatcache(true,$path);$opened=fstat($file);$current=stat($path);
        if ($opened===false||$current===false||$opened['ino']!==$current['ino']||$opened['dev']!==$current['dev']||($opened['mode']&0777)!==0600) return null;
        $bytes=stream_get_contents($file,65537);$record=is_string($bytes)&&strlen($bytes)<=65536?json_decode($bytes,true):null;
        $mid=is_array($record)?ownerPreviewMid(['mid'=>$record['message_id']??null]):null;
        if (!is_array($record)||($record['demo']??null)!=='delphin-imperial-compact-compare-20261021'||($record['state']??null)!=='verified'
            ||($record['source_recovery_sha256']??null)!==$sourceHash||($record['source_message_id']??null)!==$sourceMid
            ||($record['verified_owner_id']??null)!==$user||($record['verified_bot_id']??null)!==$bot||($record['verified_chat_id']??null)!==$chat
            ||($record['post_reserved']??null)!==true||$mid===null||$mid===$sourceMid
            ||!is_array($record['image_tokens']??null)||count($record['image_tokens'])!==3
            ||!is_array($record['image_sources']??null)||count($record['image_sources'])!==3) return null;
        $body=ownerPreviewComparisonBody();
        foreach ([0,1,2] as $index) {
            if (!ownerPreviewImageToken($record['image_tokens'][$index]??null)||($record['image_sources'][$index]??null)!==hash('sha256',$body['attachments'][$index]['payload']['url'])) return null;
            $body['attachments'][$index]['payload']=['token'=>$record['image_tokens'][$index]];
        }
        if (($record['post_body_sha256']??null)!==hash('sha256',json_encode($body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))) return null;
        return ['hash'=>hash('sha256',$bytes),'record'=>$record,'path'=>$path];
    } finally { fclose($file); }
}

/** Separately requested fixed cards; every old message and receipt remains unchanged. */
function ownerPreviewComparisonRun(string $expected,string $installed,string $project,string $token,callable $target,callable $request,string $directory,?int $now=null,?array $transfers=null,string $variant='comparison'): array
{
    $out=['ok'=>false,'state'=>'blocked','send_attempted'=>false,'provider_requests'=>0];
    if (!in_array($variant,['comparison','compact','price-first'],true)) return $out;
    if (!preg_match('/\A[0-9a-f]{40}\z/D',$expected)||$expected!==$installed||$project!=='anytour'||$token===''||strlen($token)>4096||preg_match('/[\x00-\x20\x7f]/',$token)) return $out;
    $original=ownerPreviewOriginal($directory);
    $sourcePath=$directory.'/delphin-imperial-20261021-recovery.json';
    $source=$original!==null?ownerPreviewKnownRejection($sourcePath,$original['hash']):null;
    if ($source===null) return array_replace($out,['state'=>'source_receipt_unavailable']);
    $file=null;
    try {
        $prior=$source['record'];$row=$target();$user=is_array($row)?MaxPrivateAvatarProbe::decimalId($row['external_user_id']??null):null;
        $alias=is_array($row)?MaxPrivateAvatarProbe::chatId($row['conversation_chat_id']??null):null;
        if (($source['edit']??false)!==true||($prior['state']??null)!=='verified'||$user===null||$alias===null
            ||(string)($row['id']??'')!=='2'||($row['project_key']??null)!=='anytour'||($row['channel']??null)!=='max'
            ||($row['original_display_name']??null)!=='Pavel'||!in_array($row['status']??null,['waiting_manager','manager'],true)
            ||MaxPrivateAvatarProbe::chatId($row['channel_chat_id']??null)!==$alias||MaxPrivateAvatarProbe::decimalId($row['message_sender_id']??null)!==$user
            ||$prior['verified_owner_id']!==$user) return array_replace($out,['state'=>'owner_unavailable']);
        $oldMid=$prior['message_id'];$bot=$prior['verified_bot_id'];$chat=$prior['verified_chat_id'];
        $oldTokens=$prior['image_tokens']??null;
        if (!is_array($oldTokens)||count($oldTokens)!==3) return array_replace($out,['state'=>'source_receipt_unavailable']);
        foreach ([0,1,2] as $index) if (!ownerPreviewImageToken($oldTokens[$index]??null)) return array_replace($out,['state'=>'source_receipt_unavailable']);
        $out['provider_requests']++;
        $response=$request('GET','/messages/'.rawurlencode($oldMid),[],null,$token);$out['readback_diagnostic']=ownerPreviewDiagnostic($response);
        if (!ownerPreviewMatches(ownerPreviewData($response),$oldMid,$user,$bot,$chat)) return array_replace($out,['state'=>'source_card_unverified']);
        $cache=null;
        if ($variant!=='comparison') {
            $cache=ownerPreviewComparisonCache($directory,$source['hash'],$oldMid,$user,$bot,$chat);
            if ($cache===null) return array_replace($out,['state'=>'source_comparison_unavailable']);
            $out['provider_requests']++;
            $response=$request('GET','/messages/'.rawurlencode($cache['record']['message_id']),[],null,$token);
            $out['readback_diagnostic']=ownerPreviewDiagnostic($response);
            if (!ownerPreviewComparisonMatches(ownerPreviewData($response),$cache['record']['message_id'],$user,$bot,$chat)) return array_replace($out,['state'=>'source_comparison_unverified']);
        }
        $demo=$variant==='comparison'?'delphin-imperial-compact-compare-20261021':'delphin-imperial-native-'.$variant.'-20261021';
        $journal=$directory.'/'.$demo.'.json';
        if (is_link($journal)) return array_replace($out,['state'=>'comparison_receipt_unavailable']);
        $exists=file_exists($journal);$old=umask(0077);$file=@fopen($journal,$exists?'r+b':'x+');umask($old);
        if ($file===false||((fstat($file)['mode']??0)&0777)!==0600||!flock($file,LOCK_EX|LOCK_NB)) return array_replace($out,['state'=>'comparison_receipt_unavailable']);
        clearstatcache(true,$journal);$opened=fstat($file);$current=stat($journal);
        if ($opened===false||$current===false||$opened['ino']!==$current['ino']||$opened['dev']!==$current['dev']) return array_replace($out,['state'=>'comparison_receipt_unavailable']);
        $body=ownerPreviewComparisonBody($variant);
        if ($exists) {
            $bytes=stream_get_contents($file,65537);$receipt=is_string($bytes)&&strlen($bytes)<=65536?json_decode($bytes,true):null;
            if (!is_array($receipt)||($receipt['demo']??null)!==$demo
                ||($receipt['source_recovery_sha256']??null)!==$source['hash']||($receipt['source_message_id']??null)!==$oldMid
                ||($variant!=='comparison'&&($receipt['source_comparison_sha256']??null)!==$cache['hash'])
                ||($receipt['verified_owner_id']??null)!==$user||($receipt['verified_bot_id']??null)!==$bot||($receipt['verified_chat_id']??null)!==$chat
                ||!is_string($receipt['created_at']??null)) return array_replace($out,['state'=>'comparison_already_attempted']);
            if (array_key_exists('message_id',$receipt)) {
                $mid=ownerPreviewMid(['mid'=>$receipt['message_id']]);
                if ($mid===null||($receipt['post_reserved']??null)!==true) return array_replace($out,['state'=>'comparison_already_attempted']);
                foreach ([0,1,2] as $index) {
                    if (!ownerPreviewImageToken($receipt['image_tokens'][$index]??null)||($receipt['image_sources'][$index]??null)!==hash('sha256',$body['attachments'][$index]['payload']['url'])) return array_replace($out,['state'=>'comparison_already_attempted']);
                    $body['attachments'][$index]['payload']=['token'=>$receipt['image_tokens'][$index]];
                }
                if (($receipt['post_body_sha256']??null)!==hash('sha256',json_encode($body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))) return array_replace($out,['state'=>'comparison_already_attempted']);
                $out['provider_requests']++;
                $response=$request('GET','/messages/'.rawurlencode($mid),[],null,$token);$out['readback_diagnostic']=ownerPreviewDiagnostic($response);
                $out['ok']=ownerPreviewComparisonMatches(ownerPreviewData($response),$mid,$user,$bot,$chat,$variant);$out['state']=$out['ok']?'verified':'sent_unverified';
                ownerPreviewRecoveryWrite($file,$journal,array_replace($receipt,['state'=>$out['state']]),true);return $out;
            }
            if (array_key_exists('post_reserved',$receipt)) return array_replace($out,['state'=>'comparison_already_attempted']);
            if (!in_array($receipt['state']??null,['preparing','images_prepared'],true)) return array_replace($out,['state'=>'comparison_already_attempted']);
        } else {
            $receipt=['demo'=>$demo,'state'=>'preparing','created_at'=>gmdate('c',$now??time()),
                'source_recovery_sha256'=>$source['hash'],'source_message_id'=>$oldMid,'verified_owner_id'=>$user,'verified_bot_id'=>$bot,'verified_chat_id'=>$chat,
                'source_photo_sources'=>array_map(static fn($attachment)=>hash('sha256',$attachment['payload']['url']),array_slice(ownerPreviewBody()['attachments'],0,3)),
                'image_tokens'=>[0=>$oldTokens[0],2=>$oldTokens[2]],'image_sources'=>[0=>hash('sha256',$body['attachments'][0]['payload']['url']),2=>hash('sha256',$body['attachments'][2]['payload']['url'])]];
            if ($cache!==null) {
                $receipt['source_comparison_sha256']=$cache['hash'];
                $receipt['image_tokens']=$cache['record']['image_tokens'];$receipt['image_sources']=$cache['record']['image_sources'];
            }
            if (!ownerPreviewWrite($file,$receipt)||!ownerPreviewSyncDirectory($directory)||!ownerPreviewSyncDirectory(dirname($directory))) return array_replace($out,['state'=>'comparison_receipt_unavailable']);
        }
        $body=ownerPreviewPrepareImages($receipt,$file,$journal,$request,$token,true,$out,$transfers,true);
        if ($body===null) return array_replace($out,['state'=>'image_preparation_unverified']);
        $body['text']=ownerPreviewComparisonBody($variant)['text'];
        if (ownerPreviewOriginal($directory)!==$original||hash_file('sha256',$sourcePath)!==$source['hash']) return array_replace($out,['state'=>'source_receipt_changed']);
        if ($cache!==null&&hash_file('sha256',$cache['path'])!==$cache['hash']) return array_replace($out,['state'=>'source_receipt_changed']);
        $receipt['post_reserved']=true;$receipt['post_body_sha256']=hash('sha256',json_encode($body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        if (!ownerPreviewRecoveryWrite($file,$journal,array_replace($receipt,['state'=>'post_reserved']),true)) return array_replace($out,['state'=>'comparison_receipt_unavailable']);
        $out['send_attempted']=true;$out['provider_requests']++;
        $response=$request('POST','/messages',['user_id'=>$user],$body,$token);
        $out['send_diagnostic']=ownerPreviewDiagnostic($response);$receipt['send_diagnostic']=$out['send_diagnostic'];
        $receipt['private_api_code']=$response['private_api_code']??null;$receipt['private_api_message']=$response['private_api_message']??null;
        if ($out['send_diagnostic']['error_category']!=='none') {
            $value=ownerPreviewValidationMessage($response['private_api_message']??null,$token,array_merge([$oldMid,$user,$bot,$chat],array_values($receipt['image_tokens'])));
            if ($value!==null) $out['validation_message']=$value;
        }
        $mid=ownerPreviewMid(ownerPreviewData($response));$out['state']=$mid===null?'unknown':'sent_unverified';
        if ($mid!==null) $receipt['message_id']=$mid;
        if (!ownerPreviewRecoveryWrite($file,$journal,array_replace($receipt,['state'=>$out['state']]),true)) return array_replace($out,['state'=>'comparison_receipt_unavailable']);
        if ($mid===null) return $out;
        $out['provider_requests']++;
        $response=$request('GET','/messages/'.rawurlencode($mid),[],null,$token);$out['readback_diagnostic']=ownerPreviewDiagnostic($response);
        $out['ok']=ownerPreviewComparisonMatches(ownerPreviewData($response),$mid,$user,$bot,$chat,$variant);$out['state']=$out['ok']?'verified':'sent_unverified';
        ownerPreviewRecoveryWrite($file,$journal,array_replace($receipt,['state'=>$out['state']]),true);return $out;
    } catch (Throwable $e) { return array_replace($out,['state'=>$out['send_attempted']?'unknown':'comparison_receipt_unavailable']); }
    finally { if (is_resource($file)) fclose($file);fclose($source['file']); }
}

/** Two independent, explicitly requested demonstrations; no arbitrary variant input. */
function ownerPreviewVariantsRun(string $expected,string $installed,string $project,string $token,callable $target,callable $request,string $directory): array
{
    $out=['ok'=>true,'state'=>'verified','send_attempted'=>false,'provider_requests'=>0,'variants'=>[]];
    foreach (['compact','price-first'] as $variant) {
        $result=ownerPreviewComparisonRun($expected,$installed,$project,$token,$target,$request,$directory,null,null,$variant);
        $out['variants'][$variant]=$result;$out['ok']=$out['ok']&&$result['ok'];
        $out['send_attempted']=$out['send_attempted']||$result['send_attempted'];$out['provider_requests']+=$result['provider_requests'];
        if (!$result['ok']) { $out['state']='incomplete';break; }
    }
    return $out;
}

function ownerPreviewRun(string $expected,string $installed,string $project,string $token,callable $target,callable $request,string $directory): array
{
    $out=['ok'=>false,'state'=>'blocked','send_attempted'=>false,'provider_requests'=>0];
    if (!preg_match('/\A[0-9a-f]{40}\z/D',$expected) || $expected!==$installed || $project!=='anytour' || $token==='' || strlen($token)>4096 || preg_match('/[\x00-\x20\x7f]/',$token)) return $out;
    $path=$directory.'/delphin-imperial-20261021.json';
    if (file_exists($path)||is_link($path)) return array_replace($out,['state'=>'already_attempted']);
    $row=$target();
    if (!is_array($row) || (string)($row['id']??'')!=='2' || ($row['project_key']??'')!=='anytour' || ($row['channel']??'')!=='max' || ($row['original_display_name']??'')!=='Pavel'
        || !in_array($row['status']??'', ['waiting_manager','manager'],true)) return array_replace($out,['state'=>'owner_unavailable']);
    $user=MaxPrivateAvatarProbe::decimalId($row['external_user_id']??null);
    $alias=MaxPrivateAvatarProbe::chatId($row['conversation_chat_id']??null);
    $mid=$row['external_message_id']??'';
    if ($user===null || $alias===null || MaxPrivateAvatarProbe::chatId($row['channel_chat_id']??null)!==$alias || MaxPrivateAvatarProbe::decimalId($row['message_sender_id']??null)!==$user || !is_string($mid) || !preg_match('/\A(?:mid\.)?[A-Za-z0-9_-]{1,200}\z/D',$mid)) return array_replace($out,['state'=>'owner_unavailable']);
    $out['provider_requests']++;
    $identity=ownerPreviewData($request('GET','/messages/'.rawurlencode($mid),[],null,$token));
    $chat=MaxPrivateAvatarProbe::chatId($identity['recipient']['chat_id']??null);
    $bot=MaxPrivateAvatarProbe::decimalId($identity['recipient']['user_id']??null);
    if (ownerPreviewMid($identity)!==$mid || MaxPrivateAvatarProbe::decimalId($identity['sender']['user_id']??null)!==$user || ($identity['recipient']['chat_type']??null)!=='dialog' || $chat===null || $bot===null) return array_replace($out,['state'=>'identity_unverified']);
    // A durable exclusive receipt is created before the sole POST. Its existence blocks all later attempts, even after a crash/timeout.
    if (is_link($directory) || (!is_dir($directory) && !@mkdir($directory,0700)) || (fileperms($directory)&0777)!==0700 || !ownerPreviewSyncDirectory(dirname($directory))) return array_replace($out,['state'=>'receipt_unavailable']);
    $old=umask(0077);$file=@fopen($path,'x+');umask($old);
    if ($file===false) return array_replace($out,['state'=>'already_attempted']);
    $receipt=['demo'=>'delphin-imperial-20261021','created_at'=>gmdate('c')];
    try {
        if (((fstat($file)['mode']??0)&0777)!==0600 || !ownerPreviewWrite($file,$receipt+['state'=>'attempt_reserved']) || !ownerPreviewSyncDirectory($directory)) return array_replace($out,['state'=>'receipt_unavailable']);
        $out['send_attempted']=true;$out['provider_requests']++;
        $response=$request('POST','/messages',['user_id'=>$user],ownerPreviewBody(),$token);
        $out['send_diagnostic']=ownerPreviewDiagnostic($response);
        $receipt['private_api_code']=$response['private_api_code']??null;
        $receipt['private_api_message']=$response['private_api_message']??null;
        $sentMid=ownerPreviewMid(ownerPreviewData($response));
        if (!is_string($sentMid) || !preg_match('/\A(?:mid\.)?[A-Za-z0-9_-]{1,200}\z/D',$sentMid)) {
            ownerPreviewWrite($file,$receipt+['state'=>'unknown','send_diagnostic'=>$out['send_diagnostic']]);
            return array_replace($out,['state'=>'unknown']);
        }
        // Keep the private provider receipt even if subsequent verification fails. No replay.
        ownerPreviewWrite($file,$receipt+['state'=>'sent_unverified','message_id'=>$sentMid,'send_diagnostic'=>$out['send_diagnostic']]);
        $out['provider_requests']++;
        $check=ownerPreviewData($request('GET','/messages/'.rawurlencode($sentMid),[],null,$token));
        $verified=ownerPreviewMatches($check,$sentMid,$user,$bot,$chat);
        $out['ok']=$verified;$out['state']=$verified?'verified':'sent_unverified';
        ownerPreviewWrite($file,$receipt+['state'=>$out['state'],'message_id'=>$sentMid,'send_diagnostic'=>$out['send_diagnostic']]);
        return $out;
    } catch (Throwable $e) { return array_replace($out,['state'=>$out['send_attempted']?'unknown':'receipt_unavailable']); }
    finally { fclose($file); }
}

if (!defined('MAX_OWNER_PREVIEW_OFFLINE_TEST')) {
    ini_set('display_errors','0');
    $result=['ok'=>false,'state'=>'invalid_arguments','send_attempted'=>false,'provider_requests'=>0];
    $args=array_slice($argv,1);$expected=ownerPreviewArguments($args);$recovery=false;$comparison=false;$variants=false;
    if ($expected===null) { $expected=ownerPreviewRecoveryArguments($args);$recovery=$expected!==null; }
    if ($expected===null) { $expected=ownerPreviewComparisonArguments($args);$comparison=$expected!==null; }
    if ($expected===null) { $expected=ownerPreviewVariantsArguments($args);$variants=$expected!==null; }
    if ($expected!==null) {
        $level=ob_get_level();ob_start();
        try {
            $root=dirname(__DIR__);
            $installed=trim((string)shell_exec('git -C '.escapeshellarg($root).' rev-parse HEAD 2>/dev/null'));
            if ($installed!==$expected) $result['state']='installed_sha_mismatch';
            else {
                require_once $root.'/config.php';
                require_once $root.'/services/ConversationDb.php';
                require_once $root.'/services/ProjectConfig.php';
                $db=ConversationDb::connection();
                $run=$variants?'ownerPreviewVariantsRun':($comparison?'ownerPreviewComparisonRun':($recovery?'ownerPreviewRecoverRun':'ownerPreviewRun'));
                $result=$run($expected,$installed,ProjectConfig::projectId(),defined('MAX_SEARCH_TOKEN')?(string)MAX_SEARCH_TOKEN:'',static fn()=>ownerPreviewTarget($db),'ownerPreviewRequest','/var/www/anytoour/data/max-owner-tour-preview');
            }
        } catch (Throwable $e) { $result['state']='configuration_unavailable'; }
        finally { while(ob_get_level()>$level)ob_end_clean(); }
    }
    echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
    exit($result['ok']?0:1);
}
