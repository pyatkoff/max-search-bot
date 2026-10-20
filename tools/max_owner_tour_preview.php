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

/** Fixed HTTPS origin; bounded/redacted exchange; no redirects, automatic retry or raw logging. */
function ownerPreviewRequest(string $method,string $path,array $query,?array $body,string $token): ?array
{
    $url='https://platform-api2.max.ru'.$path.($query?'?'.http_build_query($query):'');
    $bytes='';
    $ch=curl_init($url);
    $options=MaxTlsConfig::strictCurlOptions()+[
        CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,
        CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER=>['Authorization: '.$token,'Content-Type: application/json'],
        CURLOPT_WRITEFUNCTION=>static function($ch,string $part) use (&$bytes): int {
            if (strlen($bytes)+strlen($part)>65536) return 0;
            $bytes.=$part;return strlen($part);
        },
    ];
    if ($body!==null) $options[CURLOPT_POSTFIELDS]=json_encode($body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    curl_setopt_array($ch,$options);curl_exec($ch);
    $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    $ok=curl_errno($ch)===0 && (int)curl_getinfo($ch,CURLINFO_SSL_VERIFYRESULT)===0 && $status>=200 && $status<300;
    curl_close($ch);
    if (!$ok) return null;
    $data=json_decode($bytes,true,512,JSON_BIGINT_AS_STRING);
    return is_array($data)?$data:null;
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
    $identity=$request('GET','/messages/'.rawurlencode($mid),[],null,$token);
    $chat=MaxPrivateAvatarProbe::chatId($identity['recipient']['chat_id']??null);
    $bot=MaxPrivateAvatarProbe::decimalId($identity['recipient']['user_id']??null);
    if (($identity['body']['mid']??null)!==$mid || MaxPrivateAvatarProbe::decimalId($identity['sender']['user_id']??null)!==$user || ($identity['recipient']['chat_type']??null)!=='dialog' || $chat===null || $bot===null) return array_replace($out,['state'=>'identity_unverified']);
    // A durable exclusive receipt is created before the sole POST. Its existence blocks all later attempts, even after a crash/timeout.
    if (is_link($directory) || (!is_dir($directory) && !@mkdir($directory,0700)) || (fileperms($directory)&0777)!==0700 || !ownerPreviewSyncDirectory(dirname($directory))) return array_replace($out,['state'=>'receipt_unavailable']);
    $old=umask(0077);$file=@fopen($path,'x+');umask($old);
    if ($file===false) return array_replace($out,['state'=>'already_attempted']);
    try {
        if (((fstat($file)['mode']??0)&0777)!==0600 || !ownerPreviewWrite($file,['demo'=>'delphin-imperial-20261021','state'=>'attempt_reserved','created_at'=>gmdate('c')]) || !ownerPreviewSyncDirectory($directory)) return array_replace($out,['state'=>'receipt_unavailable']);
        $out['send_attempted']=true;$out['provider_requests']++;
        $sent=$request('POST','/messages',['user_id'=>$user],ownerPreviewBody(),$token);
        $sent=$sent['message']??$sent;
        $sentMid=$sent['body']['mid']??null;
        if (!is_string($sentMid) || !preg_match('/\A(?:mid\.)?[A-Za-z0-9_-]{1,200}\z/D',$sentMid)) {
            ownerPreviewWrite($file,['demo'=>'delphin-imperial-20261021','state'=>'unknown']);
            return array_replace($out,['state'=>'unknown']);
        }
        // Keep the private provider receipt even if subsequent verification fails. No replay.
        ownerPreviewWrite($file,['demo'=>'delphin-imperial-20261021','state'=>'sent_unverified','message_id'=>$sentMid]);
        $out['provider_requests']++;
        $check=$request('GET','/messages/'.rawurlencode($sentMid),[],null,$token);
        $attachments=$check['body']['attachments']??[];
        $images=0;$keyboards=[];
        foreach ((array)$attachments as $attachment) {
            if (($attachment['type']??null)==='image') $images++;
            elseif (($attachment['type']??null)==='inline_keyboard') $keyboards[]=$attachment['payload']['buttons']??null;
        }
        $verified=($check['body']['mid']??null)===$sentMid && ($check['body']['text']??null)===ownerPreviewBody()['text']
            && MaxPrivateAvatarProbe::decimalId($check['sender']['user_id']??null)===$bot
            && MaxPrivateAvatarProbe::decimalId($check['recipient']['user_id']??null)===$user
            && MaxPrivateAvatarProbe::chatId($check['recipient']['chat_id']??null)===$chat && ($check['recipient']['chat_type']??null)==='dialog'
            && $images===3 && count((array)$attachments)===4 && $keyboards===[[[['type'=>'message','text'=>'Нравится']]]];
        $out['ok']=$verified;$out['state']=$verified?'verified':'sent_unverified';
        ownerPreviewWrite($file,['demo'=>'delphin-imperial-20261021','state'=>$out['state'],'message_id'=>$sentMid]);
        return $out;
    } catch (Throwable $e) { return array_replace($out,['state'=>$out['send_attempted']?'unknown':'receipt_unavailable']); }
    finally { fclose($file); }
}

if (!defined('MAX_OWNER_PREVIEW_OFFLINE_TEST')) {
    ini_set('display_errors','0');
    $result=['ok'=>false,'state'=>'invalid_arguments','send_attempted'=>false,'provider_requests'=>0];
    $expected=ownerPreviewArguments(array_slice($argv,1));
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
                $result=ownerPreviewRun($expected,$installed,ProjectConfig::projectId(),defined('MAX_SEARCH_TOKEN')?(string)MAX_SEARCH_TOKEN:'',static fn()=>ownerPreviewTarget($db),'ownerPreviewRequest','/var/www/anytoour/data/max-owner-tour-preview');
            }
        } catch (Throwable $e) { $result['state']='configuration_unavailable'; }
        finally { while(ob_get_level()>$level)ob_end_clean(); }
    }
    echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
    exit($result['ok']?0:1);
}
