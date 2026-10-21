<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
if (!defined('TELEGRAM_OWNER_PREVIEW_LIBRARY')) define('TELEGRAM_OWNER_PREVIEW_LIBRARY',true);
require_once __DIR__.'/telegram_owner_tour_preview.php';

/** The three requested visual examples only. Terms are invented and explicitly labelled demo. */
function ownerSelectionHotels(): array
{
    $base='https://www.delphinhotel.com/main_pics/pages/big/';
    return [
        'palace'=>['title'=>'Delphin Palace 5★','price'=>'219 600 ₽ за двоих','room'=>'Стандартный номер',
            'photos'=>[$base.'4003.png',$base.'4042.png','https://www.delphinhotel.com/main_pics/pages/medium/798.png']],
        'imperial'=>['title'=>'Delphin Imperial 5★','price'=>'238 400 ₽ за двоих','room'=>'Улучшенный номер',
            'photos'=>['https://www.delphinhotel.com/main_pics/pages/medium/2996.png',$base.'4270.png','https://www.delphinhotel.com/main_pics/pages/medium/709.png']],
        'be-grand'=>['title'=>'Delphin BE Grand Resort 5★','price'=>'251 900 ₽ за двоих','room'=>'Улучшенный номер',
            'photos'=>[$base.'4407.png',$base.'4391.png',$base.'4376.png']],
    ];
}

function ownerSelectionArguments(array $args): ?string
{
    if (!in_array('--confirm-owner-trio',$args,true)) return null;
    return ownerPreviewArguments(array_map(static fn($arg)=>$arg==='--confirm-owner-trio'?'--confirm-owner-send':$arg,$args));
}

function ownerSelectionConditions(string $key): string
{
    $hotel=ownerSelectionHotels()[$key]??null;if ($hotel===null) throw new InvalidArgumentException('Unknown fixed hotel');
    return "5–12 июня 2027 · 7 ночей\nИз Москвы · 2 взрослых\n".$hotel['room']."\nУльтра всё включено\nПерелёт, трансфер и страховка включены.";
}

function ownerSelectionFooter(): string
{
    return "Демо: даты, цена и условия — пример.\nФото номера — пример интерьера отеля.";
}

function ownerSelectionTelegramBody(string $key): array
{
    $hotel=ownerSelectionHotels()[$key]??null;if ($hotel===null) throw new InvalidArgumentException('Unknown fixed hotel');
    $body=telegramOwnerPreviewBody('slideshow');
    $body['rich_message']['blocks'][1]['text']=[['type'=>'bold','text'=>$hotel['title']],' · Турция, Лара'];
    $body['rich_message']['blocks'][2]['text']=['type'=>'bold','text'=>$hotel['price']];
    $body['rich_message']['blocks'][3]['text']=ownerSelectionConditions($key);
    return $body;
}

function ownerSelectionMaxText(string $key,bool $html=false): string
{
    $hotel=ownerSelectionHotels()[$key]??null;if ($hotel===null) throw new InvalidArgumentException('Unknown fixed hotel');
    $title=$html?'<b>'.$hotel['title'].'</b>':$hotel['title'];$price=$html?'<b>'.$hotel['price'].'</b>':$hotel['price'];
    return $title.' · Турция, Лара'."\n".$price."\n".ownerSelectionConditions($key)."\n\n".ownerSelectionFooter();
}

function ownerSelectionMaxBody(string $key,array $tokens): array
{
    $attachments=[];foreach ([0,1,2] as $index) {
        if (!ownerPreviewImageToken($tokens[$index]??null)) throw new InvalidArgumentException('Missing photo token');
        $attachments[]=['type'=>'image','payload'=>['token'=>$tokens[$index]]];
    }
    $attachments[]=['type'=>'inline_keyboard','payload'=>['buttons'=>[[['type'=>'message','text'=>'Нравится']]]]];
    return ['text'=>ownerSelectionMaxText($key,true),'format'=>'html','attachments'=>$attachments];
}

function ownerSelectionMaxMatches(?array $message,string $mid,array $identity,string $key): bool
{
    $text=ownerSelectionMaxText($key);$hotel=ownerSelectionHotels()[$key];$expected=[];
    foreach ([$hotel['title'],$hotel['price']] as $bold) $expected[]=['type'=>'strong','from'=>preg_match_all('/./us',substr($text,0,strpos($text,$bold))),'length'=>preg_match_all('/./us',$bold)];
    if (($message['body']['text']??null)!==$text||!is_array($message['body']['markup']??null)||count($message['body']['markup'])!==2) return false;
    foreach ($message['body']['markup'] as $span) {
        if (!is_array($span)||count($span)!==3) return false;
        $index=array_search(['type'=>$span['type']??null,'from'=>$span['from']??null,'length'=>$span['length']??null],$expected,true);
        if ($index===false) return false;unset($expected[$index]);
    }
    $message['body']['text']=ownerPreviewBody()['text'];
    return $expected===[]&&ownerPreviewMatches($message,$mid,$identity['user'],$identity['bot'],$identity['chat']);
}

/** Independently prove both existing private owner conversations before any upload or send. */
function ownerSelectionIdentity(string $channel,$row,callable $request,string $token,array &$out): ?array
{
    $user=is_array($row)?MaxPrivateAvatarProbe::decimalId($row['external_user_id']??null):null;
    if ($user===null||($row['project_key']??null)!=='anytour'||($row['channel']??null)!==$channel||!in_array($row['status']??null,['waiting_manager','manager'],true)
        ||MaxPrivateAvatarProbe::decimalId($row['message_sender_id']??null)!==$user) return null;
    if ($channel==='telegram') {
        if ((string)($row['id']??'')!=='1'||MaxPrivateAvatarProbe::decimalId($row['conversation_chat_id']??null)!==$user
            ||MaxPrivateAvatarProbe::decimalId($row['channel_chat_id']??null)!==$user||MaxPrivateAvatarProbe::decimalId($row['external_message_id']??null)===null) return null;
        $out['provider_requests']++;$me=$request('getMe',[],$token,[]);$bot=MaxPrivateAvatarProbe::decimalId($me['data']['id']??null);
        if ($bot===null||($me['data']['is_bot']??null)!==true||($me['data']['username']??null)!=='anyru_bot') return null;
        $out['provider_requests']++;$chat=$request('getChat',['chat_id'=>$user],$token,[]);
        return ($chat['data']['type']??null)==='private'&&MaxPrivateAvatarProbe::decimalId($chat['data']['id']??null)===$user?['user'=>$user,'bot'=>$bot,'chat'=>$user]:null;
    }
    $alias=MaxPrivateAvatarProbe::chatId($row['conversation_chat_id']??null);$mid=$row['external_message_id']??null;
    if ((string)($row['id']??'')!=='2'||($row['original_display_name']??null)!=='Pavel'||$alias===null
        ||MaxPrivateAvatarProbe::chatId($row['channel_chat_id']??null)!==$alias||ownerPreviewMid(['mid'=>$mid])===null) return null;
    $out['provider_requests']++;$message=ownerPreviewData($request('GET','/messages/'.rawurlencode($mid),[],null,$token));
    $bot=MaxPrivateAvatarProbe::decimalId($message['recipient']['user_id']??null);$chat=MaxPrivateAvatarProbe::chatId($message['recipient']['chat_id']??null);
    return ownerPreviewMid($message)===$mid&&MaxPrivateAvatarProbe::decimalId($message['sender']['user_id']??null)===$user
        &&($message['recipient']['chat_type']??null)==='dialog'&&$bot!==null&&$chat!==null?['user'=>$user,'bot'=>$bot,'chat'=>$chat]:null;
}

function ownerSelectionHash(array $value): string
{
    return hash('sha256',json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
}

function ownerSelectionDownload(string $key,int $index,string $directory): ?array
{
    return ownerPreviewDownloadOfficialImage(ownerSelectionHotels()[$key]['photos'][$index],$index,$directory);
}

/** Callables are offline test seams; the CLI provides fixed production dependencies only. */
function ownerSelectionRun(string $expected,string $installed,string $project,array $tokens,array $targets,array $requests,string $directory,array $transfers=[]): array
{
    $out=['ok'=>false,'state'=>'blocked','send_attempted'=>false,'provider_requests'=>0,'cards'=>[]];$files=[];$records=[];$images=[];$lock=null;
    if (!preg_match('/\A[0-9a-f]{40}\z/D',$expected)||$expected!==$installed||$project!=='anytour') return $out;
    foreach (['telegram','max'] as $channel) if (!is_string($tokens[$channel]??null)||$tokens[$channel]===''||strlen($tokens[$channel])>4096||preg_match('/[\x00-\x20\x7f]/',$tokens[$channel])) return $out;
    try {
        if (is_link($directory)||(!is_dir($directory)&&!@mkdir($directory,0700))||(fileperms($directory)&0777)!==0700||!ownerPreviewSyncDirectory(dirname($directory))) return array_replace($out,['state'=>'receipt_unavailable']);
        $lockPath=$directory.'/batch.lock';if (is_link($lockPath)) return array_replace($out,['state'=>'receipt_unavailable']);
        $old=umask(0077);$lock=@fopen($lockPath,'c+');umask($old);
        if ($lock===false||((fstat($lock)['mode']??0)&0777)!==0600||!flock($lock,LOCK_EX|LOCK_NB)) return array_replace($out,['state'=>'busy']);
        $identities=[];
        foreach (['telegram','max'] as $channel) {
            $identities[$channel]=ownerSelectionIdentity($channel,$targets[$channel](),$requests[$channel],$tokens[$channel],$out);
            if ($identities[$channel]===null) return array_replace($out,['state'=>'owner_unverified']);
        }
        // Inspect every receipt before any new creation, so an unknown earlier attempt stops the entire batch.
        foreach (['telegram','max'] as $channel) foreach (ownerSelectionHotels() as $key=>$hotel) {
            $id=$channel.'-'.$key;$demo='owner-trio-20261021-'.$id;$path=$directory.'/'.$demo.'.json';
            $spec=ownerSelectionHash(['hotel'=>$hotel,'caption'=>ownerSelectionMaxText($key),'telegram'=>ownerSelectionTelegramBody($key)]);
            if (is_link($path)) return array_replace($out,['state'=>'receipt_unavailable']);
            $exists=file_exists($path);$old=umask(0077);$file=@fopen($path,$exists?'r+b':'x+');umask($old);
            if ($file===false) return array_replace($out,['state'=>'receipt_unavailable']);$files[$id]=$file;
            if (((fstat($file)['mode']??0)&0777)!==0600||!flock($file,LOCK_EX|LOCK_NB)) return array_replace($out,['state'=>'receipt_unavailable']);
            clearstatcache(true,$path);$opened=fstat($file);$current=stat($path);
            if ($opened===false||$current===false||$opened['ino']!==$current['ino']||$opened['dev']!==$current['dev']) return array_replace($out,['state'=>'receipt_unavailable']);
            $identity=$identities[$channel];
            if ($exists) {
                $raw=stream_get_contents($file,65537);$record=is_string($raw)&&strlen($raw)<=65536?json_decode($raw,true):null;
                if (!is_array($record)||($record['demo']??null)!==$demo||($record['spec_sha256']??null)!==$spec||($record['identity']??null)!==$identity
                    ||!is_string($record['created_at']??null)) return array_replace($out,['state'=>'already_attempted']);
                if (array_key_exists('post_reserved',$record)) {
                    if ($record['post_reserved']!==true||!in_array($record['state']??null,['verified','sent_unverified'],true)
                        ||!is_string($record['message_id']??null)||!is_array($record['photo_sha256']??null)||count($record['photo_sha256'])!==3) return array_replace($out,['state'=>'already_attempted']);
                    $body=$channel==='telegram'?['chat_id'=>$identity['user']]+ownerSelectionTelegramBody($key):ownerSelectionMaxBody($key,$record['image_tokens']??[]);
                    if (($record['post_sha256']??null)!==ownerSelectionHash(['body'=>$body,'photos'=>$record['photo_sha256']])) return array_replace($out,['state'=>'already_attempted']);
                    if ($channel==='telegram') {
                        $verified=MaxPrivateAvatarProbe::decimalId($record['message_id'])===MaxPrivateAvatarProbe::decimalId($record['native_message']['message_id']??null)
                            &&telegramOwnerPreviewMatches($record['native_message']??null,$identity['user'],$identity['bot'],'slideshow',ownerSelectionTelegramBody($key));
                    } else {
                        if (ownerPreviewMid(['mid'=>$record['message_id']])===null) return array_replace($out,['state'=>'already_attempted']);
                        $out['provider_requests']++;$check=$requests[$channel]('GET','/messages/'.rawurlencode($record['message_id']),[],null,$tokens[$channel]);
                        $verified=ownerSelectionMaxMatches(ownerPreviewData($check),$record['message_id'],$identity,$key);
                    }
                    if (!$verified) return array_replace($out,['state'=>'sent_unverified']);
                    $out['cards'][$id]=['ok'=>true,'state'=>'previously_verified','send_attempted'=>false];
                } elseif (!in_array($record['state']??null,['preparing','images_prepared'],true)||array_key_exists('message_id',$record)) return array_replace($out,['state'=>'already_attempted']);
            } else {
                $record=['demo'=>$demo,'spec_sha256'=>$spec,'identity'=>$identity,'created_at'=>gmdate('c'),'state'=>'preparing','image_tokens'=>[]];
                if (!ownerPreviewWrite($file,$record)||!ownerPreviewSyncDirectory($directory)) return array_replace($out,['state'=>'receipt_unavailable']);
            }
            $records[$id]=$record;
        }
        $download=$transfers['download']??'ownerSelectionDownload';$upload=$transfers['upload']??'ownerPreviewUploadImage';
        // Download once per hotel; both messengers use the exact same actual photo bytes.
        foreach (ownerSelectionHotels() as $key=>$hotel) {
            if (isset($out['cards']['telegram-'.$key],$out['cards']['max-'.$key])) continue;
            foreach ([0,1,2] as $index) {
                $image=$download($key,$index,$directory);
                if (!is_array($image)||!is_string($image['path']??null)||dirname($image['path'])!==$directory
                    ||!preg_match('/\A\.owner-preview-image-[012]-[a-f0-9]{16}\.tmp\z/D',basename($image['path']))||is_link($image['path'])
                    ||!is_file($image['path'])||(fileperms($image['path'])&0777)!==0600||!in_array($image['mime']??null,['image/jpeg','image/png'],true)) return array_replace($out,['state'=>'image_unavailable']);
                $images[$key][$index]=$image;
            }
            $hashes=array_map(static fn($image)=>hash_file('sha256',$image['path']),$images[$key]);
            foreach (['telegram','max'] as $channel) {
                $id=$channel.'-'.$key;$record=&$records[$id];
                if (isset($record['photo_sha256'])&&$record['photo_sha256']!==$hashes) return array_replace($out,['state'=>'source_changed']);
                if (!isset($out['cards'][$id])) { $record['photo_sha256']=$hashes;if (!ownerPreviewWrite($files[$id],$record)) return array_replace($out,['state'=>'receipt_unavailable']); }
                unset($record);
            }
        }
        foreach (['telegram','max'] as $channel) foreach (ownerSelectionHotels() as $key=>$hotel) {
            $id=$channel.'-'.$key;if (isset($out['cards'][$id])) continue;
            $record=&$records[$id];$identity=$identities[$channel];$request=$requests[$channel];$token=$tokens[$channel];$file=$files[$id];
            if ($channel==='max') foreach ([0,1,2] as $index) {
                if (array_key_exists($index,$record['image_tokens'])) {
                    if (!ownerPreviewImageToken($record['image_tokens'][$index])) return array_replace($out,['state'=>'image_unavailable']);continue;
                }
                $out['provider_requests']++;$reservation=$request('POST','/uploads',['type'=>'image'],null,$token);
                $data=ownerPreviewData($reservation);$url=$data['url']??$data['upload_url']??null;
                if (!ownerPreviewUploadUrl($url)) return array_replace($out,['state'=>'image_upload_unavailable']);
                $out['provider_requests']++;$response=$upload($url,$images[$key][$index]);$imageToken=ownerPreviewUploadedToken(ownerPreviewData($response));
                if ($imageToken===null) return array_replace($out,['state'=>'image_upload_unavailable']);
                $record['image_tokens'][$index]=$imageToken;$record['state']='images_prepared';
                if (!ownerPreviewWrite($file,$record)) return array_replace($out,['state'=>'receipt_unavailable']);
            }
            $body=$channel==='telegram'?['chat_id'=>$identity['user']]+ownerSelectionTelegramBody($key):ownerSelectionMaxBody($key,$record['image_tokens']);
            $record['post_reserved']=true;$record['post_sha256']=ownerSelectionHash(['body'=>$body,'photos'=>$record['photo_sha256']]);$record['state']='post_reserved';
            if (!ownerPreviewWrite($file,$record)||!ownerPreviewSyncDirectory($directory)) return array_replace($out,['state'=>'receipt_unavailable']);
            $out['send_attempted']=true;$out['provider_requests']++;
            $response=$channel==='telegram'?$request('sendRichMessage',$body,$token,$images[$key]):$request('POST','/messages',['user_id'=>$identity['user']],$body,$token);
            $message=$response['data']??null;$mid=$channel==='telegram'?MaxPrivateAvatarProbe::decimalId($message['message_id']??null):ownerPreviewMid(ownerPreviewData($response));
            $record['state']=$mid===null?'unknown':'sent_unverified';if ($mid!==null) $record['message_id']=$mid;
            if ($channel==='telegram'&&is_array($message)) $record['native_message']=['message_id'=>$message['message_id']??null,
                'from'=>['id'=>$message['from']['id']??null,'is_bot'=>$message['from']['is_bot']??null],
                'chat'=>['id'=>$message['chat']['id']??null,'type'=>$message['chat']['type']??null],
                'rich_message'=>$message['rich_message']??null,'reply_markup'=>$message['reply_markup']??null];
            $record['diagnostic']=$channel==='telegram'?($response['diagnostic']??[]):ownerPreviewDiagnostic($response);
            if (!ownerPreviewWrite($file,$record)) return array_replace($out,['state'=>'receipt_unavailable']);
            if ($mid===null) return array_replace($out,['state'=>'unknown']);
            if ($channel==='max') { $out['provider_requests']++;$response=$request('GET','/messages/'.rawurlencode($mid),[],null,$token);$message=ownerPreviewData($response); }
            $verified=$channel==='telegram'?telegramOwnerPreviewMatches($message,$identity['user'],$identity['bot'],'slideshow',ownerSelectionTelegramBody($key)):ownerSelectionMaxMatches($message,$mid,$identity,$key);
            $record['state']=$verified?'verified':'sent_unverified';
            if (!ownerPreviewWrite($file,$record)) return array_replace($out,['state'=>'receipt_unavailable']);
            $out['cards'][$id]=['ok'=>$verified,'state'=>$record['state'],'send_attempted'=>true,'diagnostic'=>$record['diagnostic']];
            unset($record);if (!$verified) return array_replace($out,['state'=>'sent_unverified']);
        }
        return array_replace($out,['ok'=>true,'state'=>'verified']);
    } catch (Throwable $e) { return array_replace($out,['state'=>$out['send_attempted']?'unknown':'unavailable']); }
    finally {
        foreach ($images as $set) foreach ($set as $image) @unlink($image['path']);
        foreach ($files as $file) if (is_resource($file)) fclose($file);if (is_resource($lock)) fclose($lock);
    }
}

if (!defined('OWNER_SELECTION_PREVIEW_OFFLINE_TEST')) {
    ini_set('display_errors','0');$result=['ok'=>false,'state'=>'invalid_arguments','send_attempted'=>false,'provider_requests'=>0];
    $expected=ownerSelectionArguments(array_slice($argv,1));
    if ($expected!==null) {
        $level=ob_get_level();ob_start();
        try {
            $root=dirname(__DIR__);$installed=trim((string)shell_exec('git -C '.escapeshellarg($root).' rev-parse HEAD 2>/dev/null'));
            if ($installed!==$expected) $result['state']='installed_sha_mismatch';
            else {
                require_once $root.'/config.php';require_once $root.'/services/ConversationDb.php';require_once $root.'/services/ProjectConfig.php';
                $db=ConversationDb::connection();
                $result=ownerSelectionRun($expected,$installed,ProjectConfig::projectId(),
                    ['telegram'=>defined('TELEGRAM_BOT_TOKEN')?(string)TELEGRAM_BOT_TOKEN:'','max'=>defined('MAX_SEARCH_TOKEN')?(string)MAX_SEARCH_TOKEN:''],
                    ['telegram'=>static fn()=>telegramOwnerPreviewTarget($db),'max'=>static fn()=>ownerPreviewTarget($db)],
                    ['telegram'=>'telegramOwnerPreviewRequest','max'=>'ownerPreviewRequest'],'/var/www/anytoour/data/owner-tour-selection-preview');
            }
        } catch (Throwable $e) { $result['state']='configuration_unavailable'; }
        finally { while(ob_get_level()>$level)ob_end_clean(); }
    }
    echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";exit($result['ok']?0:1);
}
