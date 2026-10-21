<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
if (!defined('MAX_OWNER_PREVIEW_LIBRARY')) define('MAX_OWNER_PREVIEW_LIBRARY',true);
require_once __DIR__.'/max_owner_tour_preview.php';

function telegramOwnerPreviewArguments(array $args): ?string
{
    if (!in_array('--confirm-owner-telegram',$args,true)) return null;
    return ownerPreviewArguments(array_map(static fn($arg)=>$arg==='--confirm-owner-telegram'?'--confirm-owner-send':$arg,$args));
}

/** The fresh recognition message was independently observed through the read-only MCP. */
function telegramOwnerPreviewTarget(PDO $db): ?array
{
    if ($db->inTransaction()) return null;
    try {
        $db->exec('SET TRANSACTION READ ONLY');$db->beginTransaction();
        $q=$db->prepare("SELECT c.id,c.project_key,c.channel,c.status,c.external_chat_id AS conversation_chat_id,cc.external_chat_id AS channel_chat_id,cc.external_user_id,m.sender_id AS message_sender_id,m.external_message_id FROM conversations c JOIN customer_channels cc ON cc.id=c.customer_channel_id AND cc.customer_id=c.customer_id AND cc.project_key=c.project_key AND cc.channel=c.channel JOIN projects p ON p.project_key=c.project_key AND p.is_active=1 JOIN messages m ON m.conversation_id=c.id AND m.channel=c.channel WHERE c.id=? AND c.project_key=? AND c.channel=? AND m.id=? AND m.direction=? AND m.sender_type=? AND m.text=? AND m.created_at=? AND CASE WHEN JSON_VALID(m.metadata_json) THEN JSON_UNQUOTE(JSON_EXTRACT(m.metadata_json,'$.type')) ELSE NULL END='message' LIMIT 1");
        $q->execute(['1','anytour','telegram','108046','inbound','customer','Карточки','2026-10-21 15:12:01']);
        $row=$q->fetch(PDO::FETCH_ASSOC);return is_array($row)?$row:null;
    } catch (Throwable $e) { return null; }
    finally { if ($db->inTransaction()) $db->rollBack(); }
}

function telegramOwnerPreviewBody(string $variant): array
{
    if (!in_array($variant,['collage','slideshow'],true)) throw new InvalidArgumentException('Unknown fixed variant');
    $photos=[];
    foreach ([0,1,2] as $index) $photos[]=['type'=>'photo','photo'=>['type'=>'photo','media'=>'attach://photo'.$index]];
    return ['rich_message'=>['blocks'=>[
        ['type'=>$variant,'blocks'=>$photos],
        ['type'=>'paragraph','text'=>[['type'=>'bold','text'=>'Delphin Imperial 5★'],' · Турция, Лара']],
        ['type'=>'paragraph','text'=>['type'=>'bold','text'=>'238 400 ₽ за двоих']],
        ['type'=>'paragraph','text'=>"5–12 июня 2027 · 7 ночей\nМосква · 2 взрослых\nУлучшенный номер\nУльтра всё включено\nПерелёт, трансфер и страховка включены."],
        ['type'=>'footer','text'=>"Демо: даты, цена и условия — пример.\nФото номера — пример интерьера отеля."],
    ],'skip_entity_detection'=>true],
        'reply_markup'=>['inline_keyboard'=>[[['text'=>'Нравится','callback_data'=>'owner_preview_like_20261021']]]]];
}

/** No raw provider description, URL, identity or token leaves this bounded exchange. */
function telegramOwnerPreviewRequest(string $method,array $payload,string $token,array $images=[]): array
{
    if (!in_array($method,['getMe','getChat','sendRichMessage'],true)) throw new InvalidArgumentException('Unsupported method');
    $bytes='';$overflow=false;$ch=curl_init('https://api.telegram.org/bot'.$token.'/'.$method);
    $options=[CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_POST=>true,
        CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>30,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,
        CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>static function($handle,string $part)use(&$bytes,&$overflow):int {
            if (strlen($bytes)+strlen($part)>262144) { $overflow=true;return 0; }
            $bytes.=$part;return strlen($part);
        }];
    if ($images!==[]) {
        $form=['chat_id'=>$payload['chat_id'],'rich_message'=>json_encode($payload['rich_message'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'reply_markup'=>json_encode($payload['reply_markup'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)];
        foreach ($images as $index=>$image) $form['photo'.$index]=new CURLFile($image['path'],$image['mime'],'photo'.$index.'.'.($image['mime']==='image/jpeg'?'jpg':'png'));
        $options[CURLOPT_POSTFIELDS]=$form;
    } else {
        $options[CURLOPT_HTTPHEADER]=['Content-Type: application/json'];
        $options[CURLOPT_POSTFIELDS]=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }
    curl_setopt_array($ch,$options);curl_exec($ch);
    $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$curl=curl_errno($ch);$tls=(int)curl_getinfo($ch,CURLINFO_SSL_VERIFYRESULT);curl_close($ch);
    $decoded=json_decode($bytes,true,64,JSON_BIGINT_AS_STRING);
    $category=$overflow?'response_too_large':($tls!==0?'tls':($curl!==0?'transport':($status!==200?'http_rejected':(!is_array($decoded)?'invalid_response':(($decoded['ok']??null)!==true?'provider_rejected':'none')))));
    return ['data'=>$category==='none'&&is_array($decoded['result']??null)?$decoded['result']:null,
        'diagnostic'=>['http_status'=>max(0,min(599,$status)),'curl_code'=>max(0,min(999,$curl)),'error_category'=>$category,
            'api_error_code'=>is_int($decoded['error_code']??null)?max(0,min(599,$decoded['error_code'])):null]];
}

/** Equivalent Telegram string/list encodings retain the same exact text and bold spans. */
function telegramOwnerPreviewText($value,bool $bold=false,int $depth=0): ?array
{
    if ($depth>16) return null;
    if (is_string($value)) return $value===''?[]:[[$bold,$value]];
    if (!is_array($value)) return null;
    if (!array_is_list($value)) return ($value['type']??null)==='bold'&&count($value)===2?telegramOwnerPreviewText($value['text']??null,true,$depth+1):null;
    $out=[];
    foreach ($value as $part) {
        $segments=telegramOwnerPreviewText($part,$bold,$depth+1);if ($segments===null) return null;
        foreach ($segments as $segment) {
            $last=count($out)-1;
            if ($last>=0&&$out[$last][0]===$segment[0]) $out[$last][1].=$segment[1];else $out[]=$segment;
        }
    }
    return $out;
}

/** Compare returned native content, allowing Telegram's photo IDs and dimensions only. */
function telegramOwnerPreviewMatches(?array $message,string $user,string $bot,string $variant): bool
{
    if (MaxPrivateAvatarProbe::decimalId($message['message_id']??null)===null
        ||MaxPrivateAvatarProbe::decimalId($message['chat']['id']??null)!==$user||($message['chat']['type']??null)!=='private'
        ||MaxPrivateAvatarProbe::decimalId($message['from']['id']??null)!==$bot||($message['from']['is_bot']??null)!==true) return false;
    $wanted=telegramOwnerPreviewBody($variant);$actual=$message['rich_message']['blocks']??null;
    if (!is_array($actual)||count($actual)!==5||($actual[0]['type']??null)!==$variant||!is_array($actual[0]['blocks']??null)||count($actual[0]['blocks'])!==3) return false;
    foreach ($actual[0]['blocks'] as $block) {
        if (($block['type']??null)!=='photo'||!is_array($block['photo']??null)||count($block['photo'])<1) return false;
        foreach ($block['photo'] as $photo) if (!is_array($photo)||!is_string($photo['file_id']??null)||$photo['file_id']===''||(int)($photo['width']??0)<1||(int)($photo['height']??0)<1) return false;
    }
    foreach ([1,2,3,4] as $index) {
        $block=$wanted['rich_message']['blocks'][$index];
        if (($actual[$index]['type']??null)!==$block['type']||telegramOwnerPreviewText($actual[$index]['text']??null)!==telegramOwnerPreviewText($block['text'])) return false;
    }
    return ($message['reply_markup']??null)==$wanted['reply_markup'];
}

function telegramOwnerPreviewRun(string $expected,string $installed,string $project,string $token,callable $target,callable $request,string $directory,?callable $download=null): array
{
    $out=['ok'=>false,'state'=>'blocked','send_attempted'=>false,'provider_requests'=>0,'variants'=>[]];$images=[];
    if (!preg_match('/\A[0-9a-f]{40}\z/D',$expected)||$expected!==$installed||$project!=='anytour'||$token===''||strlen($token)>4096||preg_match('/[\x00-\x20\x7f]/',$token)) return $out;
    try {
        $row=$target();$user=is_array($row)?MaxPrivateAvatarProbe::decimalId($row['external_user_id']??null):null;
        if (!is_array($row)||(string)($row['id']??'')!=='1'||($row['project_key']??null)!=='anytour'||($row['channel']??null)!=='telegram'
            ||!in_array($row['status']??null,['waiting_manager','manager'],true)||$user===null
            ||MaxPrivateAvatarProbe::decimalId($row['conversation_chat_id']??null)!==$user||MaxPrivateAvatarProbe::decimalId($row['channel_chat_id']??null)!==$user
            ||MaxPrivateAvatarProbe::decimalId($row['message_sender_id']??null)!==$user||MaxPrivateAvatarProbe::decimalId($row['external_message_id']??null)===null) return array_replace($out,['state'=>'owner_unavailable']);
        $out['provider_requests']++;$me=$request('getMe',[],$token,[]);$bot=MaxPrivateAvatarProbe::decimalId($me['data']['id']??null);
        if ($bot===null||($me['data']['username']??null)!=='anyru_bot'||($me['data']['is_bot']??null)!==true) return array_replace($out,['state'=>'bot_unverified']);
        $out['provider_requests']++;$chat=$request('getChat',['chat_id'=>$user],$token,[]);
        if (($chat['data']['type']??null)!=='private'||MaxPrivateAvatarProbe::decimalId($chat['data']['id']??null)!==$user) return array_replace($out,['state'=>'owner_unverified']);
        if (is_link($directory)||(!is_dir($directory)&&!@mkdir($directory,0700))||(fileperms($directory)&0777)!==0700||!ownerPreviewSyncDirectory(dirname($directory))) return array_replace($out,['state'=>'receipt_unavailable']);
        $download=$download??'ownerPreviewDownloadImage';
        foreach ([0,1,2] as $index) {
            $image=$download($index,$directory,true);
            if (!is_array($image)||!is_string($image['path']??null)||dirname($image['path'])!==$directory
                ||!preg_match('/\A\.owner-preview-image-[012]-[a-f0-9]{16}\.tmp\z/D',basename($image['path']))||is_link($image['path'])
                ||!is_file($image['path'])||(fileperms($image['path'])&0777)!==0600||!in_array($image['mime']??null,['image/jpeg','image/png'],true)) return array_replace($out,['state'=>'image_unavailable']);
            $images[$index]=$image;
        }
        foreach (['collage','slideshow'] as $variant) {
            $body=['chat_id'=>$user]+telegramOwnerPreviewBody($variant);$demo='delphin-imperial-native-'.$variant.'-20261021';$path=$directory.'/'.$demo.'.json';
            $hash=hash('sha256',json_encode(['body'=>$body,'photos'=>array_map(static fn($image)=>hash_file('sha256',$image['path']),$images)],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            if (is_link($path)) return array_replace($out,['state'=>'already_attempted']);
            $exists=file_exists($path);$old=umask(0077);$file=@fopen($path,$exists?'r+b':'x+');umask($old);
            if ($file===false) return array_replace($out,['state'=>'already_attempted']);
            try {
                if (((fstat($file)['mode']??0)&0777)!==0600||!flock($file,LOCK_EX|LOCK_NB)) return array_replace($out,['state'=>'receipt_unavailable']);
                clearstatcache(true,$path);$opened=fstat($file);$current=stat($path);
                if ($opened===false||$current===false||$opened['ino']!==$current['ino']||$opened['dev']!==$current['dev']) return array_replace($out,['state'=>'receipt_unavailable']);
                if ($exists) {
                    $bytes=stream_get_contents($file,65537);$saved=is_string($bytes)&&strlen($bytes)<=65536?json_decode($bytes,true):null;
                    if (!is_array($saved)||($saved['demo']??null)!==$demo||($saved['body_sha256']??null)!==$hash||($saved['owner_id']??null)!==$user
                        ||($saved['bot_id']??null)!==$bot||!in_array($saved['state']??null,['verified','sent_unverified'],true)||MaxPrivateAvatarProbe::decimalId($saved['message_id']??null)===null
                        ||MaxPrivateAvatarProbe::decimalId($saved['native_message']['message_id']??null)!==MaxPrivateAvatarProbe::decimalId($saved['message_id'])
                        ||!telegramOwnerPreviewMatches($saved['native_message']??null,$user,$bot,$variant)) return array_replace($out,['state'=>'already_attempted']);
                    $out['variants'][$variant]=['ok'=>true,'state'=>'previously_verified','send_attempted'=>false];continue;
                }
                $receipt=['demo'=>$demo,'state'=>'post_reserved','body_sha256'=>$hash,'owner_id'=>$user,'bot_id'=>$bot,'created_at'=>gmdate('c')];
                if (!ownerPreviewWrite($file,$receipt)||!ownerPreviewSyncDirectory($directory)) return array_replace($out,['state'=>'receipt_unavailable']);
                $out['send_attempted']=true;$out['provider_requests']++;
                $response=$request('sendRichMessage',$body,$token,$images);$message=$response['data']??null;$mid=MaxPrivateAvatarProbe::decimalId($message['message_id']??null);
                $verified=telegramOwnerPreviewMatches($message,$user,$bot,$variant);$state=$verified?'verified':($mid===null?'unknown':'sent_unverified');
                if ($mid!==null) $receipt['message_id']=$mid;
                if (is_array($message)) $receipt['native_message']=['message_id'=>$message['message_id']??null,
                    'from'=>['id'=>$message['from']['id']??null,'is_bot'=>$message['from']['is_bot']??null],
                    'chat'=>['id'=>$message['chat']['id']??null,'type'=>$message['chat']['type']??null],
                    'rich_message'=>$message['rich_message']??null,'reply_markup'=>$message['reply_markup']??null];
                $receipt['state']=$state;$receipt['diagnostic']=$response['diagnostic']??[];
                if (!ownerPreviewWrite($file,$receipt)) return array_replace($out,['state'=>'receipt_unavailable']);
                $out['variants'][$variant]=['ok'=>$verified,'state'=>$state,'send_attempted'=>true,'diagnostic'=>$receipt['diagnostic']];
                if (!$verified) return array_replace($out,['state'=>'incomplete']);
            } finally { fclose($file); }
        }
        return array_replace($out,['ok'=>true,'state'=>'verified']);
    } catch (Throwable $e) { return array_replace($out,['state'=>$out['send_attempted']?'unknown':'unavailable']); }
    finally { foreach ($images as $image) @unlink($image['path']); }
}

if (!defined('TELEGRAM_OWNER_PREVIEW_OFFLINE_TEST')) {
    ini_set('display_errors','0');$result=['ok'=>false,'state'=>'invalid_arguments','send_attempted'=>false,'provider_requests'=>0];
    $expected=telegramOwnerPreviewArguments(array_slice($argv,1));
    if ($expected!==null) {
        $level=ob_get_level();ob_start();
        try {
            $root=dirname(__DIR__);$installed=trim((string)shell_exec('git -C '.escapeshellarg($root).' rev-parse HEAD 2>/dev/null'));
            if ($installed!==$expected) $result['state']='installed_sha_mismatch';
            else {
                require_once $root.'/config.php';require_once $root.'/services/ConversationDb.php';require_once $root.'/services/ProjectConfig.php';
                $db=ConversationDb::connection();
                $result=telegramOwnerPreviewRun($expected,$installed,ProjectConfig::projectId(),defined('TELEGRAM_BOT_TOKEN')?(string)TELEGRAM_BOT_TOKEN:'',static fn()=>telegramOwnerPreviewTarget($db),'telegramOwnerPreviewRequest','/var/www/anytoour/data/telegram-owner-tour-preview');
            }
        } catch (Throwable $e) { $result['state']='configuration_unavailable'; }
        finally { while(ob_get_level()>$level)ob_end_clean(); }
    }
    echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";exit($result['ok']?0:1);
}
