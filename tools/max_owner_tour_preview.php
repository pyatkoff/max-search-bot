<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/integrations/MaxPrivateAvatarProbe.php';

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

/** Only these provider codes may leave the process; never provider descriptions. */
function ownerPreviewDecode(string $bytes,int $status,int $curl,int $tls,bool $overflow=false): array
{
    $data=json_decode($bytes,true,64,JSON_BIGINT_AS_STRING);
    $code=is_array($data)?($data['code']??null):null;
    $diagnostic=['http_status'=>max(0,min(599,$status)),'curl_code'=>max(0,min(999,$curl)),
        'error_category'=>$overflow?'response_too_large':($tls!==0?'tls':($curl!==0?'transport':($status<200||$status>=300?'http_rejected':(!is_array($data)?'invalid_response':'none')))),
        'api_code'=>in_array($code,['proto.payload','proto.query','proto.auth','access.denied','chat.not.found','user.not.found',
            'dialog.suspended','error.dialog.suspended','attachment.not.ready','attachment.invalid','message.empty','message.too.long'],true)?$code:null];
    return ['data'=>$diagnostic['error_category']==='none'?$data:null,'diagnostic'=>$diagnostic];
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
    $result=ownerPreviewDecode($bytes,(int)curl_getinfo($ch,CURLINFO_HTTP_CODE),curl_errno($ch),(int)curl_getinfo($ch,CURLINFO_SSL_VERIFYRESULT),$overflow);
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
    return ownerPreviewMid($check)===$mid && ($check['body']['text']??null)===ownerPreviewBody()['text']
        && MaxPrivateAvatarProbe::decimalId($check['sender']['user_id']??null)===$bot
        && MaxPrivateAvatarProbe::decimalId($check['recipient']['user_id']??null)===$user
        && MaxPrivateAvatarProbe::chatId($check['recipient']['chat_id']??null)===$chat && ($check['recipient']['chat_type']??null)==='dialog'
        && $images===3 && count((array)$attachments)===4 && $keyboards===[[[['type'=>'message','text'=>'Нравится']]]];
}

function ownerPreviewWrite($file,array $receipt): bool
{
    $bytes=json_encode($receipt,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
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

/** One separately authorized recovery attempt, permanently blocked by any existing recovery journal. */
function ownerPreviewRecoverRun(string $expected,string $installed,string $project,string $token,callable $target,callable $request,string $directory,?int $now=null): array
{
    $out=['ok'=>false,'state'=>'blocked','send_attempted'=>false,'provider_requests'=>0];
    if (!preg_match('/\A[0-9a-f]{40}\z/D',$expected)||$expected!==$installed||$project!=='anytour'||$token===''||strlen($token)>4096||preg_match('/[\x00-\x20\x7f]/',$token)) return $out;
    $journal=$directory.'/delphin-imperial-20261021-recovery.json';
    if (file_exists($journal)||is_link($journal)) return array_replace($out,['state'=>'recovery_already_attempted']);
    $original=ownerPreviewOriginal($directory);
    if ($original===null) return array_replace($out,['state'=>'original_receipt_unavailable']);
    $row=$target();
    if (!is_array($row)||(string)($row['id']??'')!=='2'||($row['project_key']??'')!=='anytour'||($row['channel']??'')!=='max'||($row['original_display_name']??'')!=='Pavel'
        ||!in_array($row['status']??'', ['waiting_manager','manager'],true)) return array_replace($out,['state'=>'owner_unavailable']);
    $user=MaxPrivateAvatarProbe::decimalId($row['external_user_id']??null);$alias=MaxPrivateAvatarProbe::chatId($row['conversation_chat_id']??null);$mid=$row['external_message_id']??'';
    if ($user===null||$alias===null||MaxPrivateAvatarProbe::chatId($row['channel_chat_id']??null)!==$alias||MaxPrivateAvatarProbe::decimalId($row['message_sender_id']??null)!==$user
        ||!is_string($mid)||!preg_match('/\A(?:mid\.)?[A-Za-z0-9_-]{1,200}\z/D',$mid)) return array_replace($out,['state'=>'owner_unavailable']);
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
    $old=umask(0077);$file=@fopen($journal,'x+');umask($old);
    if ($file===false) return array_replace($out,['state'=>'recovery_already_attempted']);
    $receipt=['demo'=>'delphin-imperial-20261021','created_at'=>gmdate('c'),'original_receipt_sha256'=>$original['hash']];
    try {
        if (((fstat($file)['mode']??0)&0777)!==0600||!ownerPreviewWrite($file,$receipt+['state'=>'attempt_reserved'])||!ownerPreviewSyncDirectory($directory)||!ownerPreviewSyncDirectory(dirname($directory))) return array_replace($out,['state'=>'recovery_receipt_unavailable']);
        if (ownerPreviewOriginal($directory)!==$original) return array_replace($out,['state'=>'original_receipt_changed']);
        $out['send_attempted']=true;$out['provider_requests']++;
        $response=$request('POST','/messages',['user_id'=>$user],ownerPreviewBody(),$token);
        $out['send_diagnostic']=ownerPreviewDiagnostic($response);$receipt['send_diagnostic']=$out['send_diagnostic'];
        $sentMid=ownerPreviewMid(ownerPreviewData($response));
        $out['state']=$sentMid===null?'unknown':'sent_unverified';
        if ($sentMid!==null) $receipt['message_id']=$sentMid;
        ownerPreviewWrite($file,$receipt+['state'=>$out['state']]);
        if ($sentMid===null) return $out;
        $out['provider_requests']++;
        $response=$request('GET','/messages/'.rawurlencode($sentMid),[],null,$token);
        $out['readback_diagnostic']=ownerPreviewDiagnostic($response);
        $out['ok']=ownerPreviewMatches(ownerPreviewData($response),$sentMid,$user,$bot,$chat);
        $out['state']=$out['ok']?'verified':'sent_unverified';
        ownerPreviewWrite($file,$receipt+['state'=>$out['state']]);
        return $out;
    } catch (Throwable $e) {
        $out['state']=isset($receipt['message_id'])?'sent_unverified':($out['send_attempted']?'unknown':'recovery_receipt_unavailable');
        ownerPreviewWrite($file,$receipt+['state'=>$out['state']]);return $out;
    } finally { fclose($file); }
}

/** Callables are offline test seams only. The CLI below supplies only fixed production dependencies. */
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
    $args=array_slice($argv,1);$expected=ownerPreviewArguments($args);$recovery=false;
    if ($expected===null) { $expected=ownerPreviewRecoveryArguments($args);$recovery=$expected!==null; }
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
                $run=$recovery?'ownerPreviewRecoverRun':'ownerPreviewRun';
                $result=$run($expected,$installed,ProjectConfig::projectId(),defined('MAX_SEARCH_TOKEN')?(string)MAX_SEARCH_TOKEN:'',static fn()=>ownerPreviewTarget($db),'ownerPreviewRequest','/var/www/anytoour/data/max-owner-tour-preview');
            }
        } catch (Throwable $e) { $result['state']='configuration_unavailable'; }
        finally { while(ob_get_level()>$level)ob_end_clean(); }
    }
    echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
    exit($result['ok']?0:1);
}
