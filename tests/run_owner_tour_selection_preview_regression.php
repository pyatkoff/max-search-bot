<?php
declare(strict_types=1);
define('OWNER_SELECTION_PREVIEW_OFFLINE_TEST',true);
require_once dirname(__DIR__).'/tools/owner_tour_selection_preview.php';
$checks=0;$directories=[];
function trioAssert(bool $condition,string $message): void { global $checks;$checks++;if (!$condition) throw new RuntimeException($message); }
function trioRow(string $channel): array {
    return $channel==='telegram'?['id'=>'1','project_key'=>'anytour','channel'=>'telegram','status'=>'manager','external_user_id'=>'881001','conversation_chat_id'=>'881001','channel_chat_id'=>'881001','message_sender_id'=>'881001','external_message_id'=>'177']:
        ['id'=>'2','project_key'=>'anytour','channel'=>'max','status'=>'manager','external_user_id'=>'772001','conversation_chat_id'=>'772001','channel_chat_id'=>'772001','message_sender_id'=>'772001','external_message_id'=>'mid.owner_inbound','original_display_name'=>'Pavel'];
}
function trioNative(string $channel,string $key): array {
    $index=array_search($key,array_keys(ownerSelectionHotels()),true)+1;
    if ($channel==='telegram') {
        $body=ownerSelectionTelegramBody($key);$blocks=$body['rich_message']['blocks'];
        foreach ([0,1,2] as $i) $blocks[0]['blocks'][$i]=['type'=>'photo','photo'=>[['file_id'=>'PRIVATE_PHOTO_'.$key.$i,'width'=>1920,'height'=>1279]]];
        return ['message_id'=>200+$index,'chat'=>['id'=>881001,'type'=>'private'],'from'=>['id'=>881099,'is_bot'=>true],'rich_message'=>['blocks'=>$blocks],'reply_markup'=>$body['reply_markup']];
    }
    $text=ownerSelectionMaxText($key);$hotel=ownerSelectionHotels()[$key];$markup=[];
    foreach ([$hotel['title'],$hotel['price']] as $bold) $markup[]=['length'=>mb_strlen($bold),'type'=>'strong','from'=>mb_strlen(substr($text,0,strpos($text,$bold)))];
    return ['body'=>['mid'=>'mid.trio_'.$key,'text'=>$text,'markup'=>array_reverse($markup),
        'attachments'=>[['type'=>'image'],['type'=>'image'],['type'=>'image'],['type'=>'inline_keyboard','payload'=>['buttons'=>[[['type'=>'message','text'=>'Нравится']]]]]]],
        'sender'=>['user_id'=>772099],'recipient'=>['user_id'=>772001,'chat_id'=>-770001,'chat_type'=>'dialog']];
}
function trioRun(string $failure='',?string $directory=null,array $changes=[]): array {
    global $directories;
    if ($directory===null) { $directory=sys_get_temp_dir().'/owner-trio-'.bin2hex(random_bytes(8));$directories[]=$directory; }
    $calls=[];$downloads=[];$uploadCount=0;
    $tg=static function($method,$body,$token,$images)use(&$calls,$failure,$directory):array {
        $calls[]=['telegram',$method];
        if ($method==='getMe') return ['data'=>['id'=>881099,'username'=>$failure==='bot'?'wrong_bot':'anyru_bot','is_bot'=>true]];
        if ($method==='getChat') return ['data'=>['id'=>881001,'type'=>$failure==='chat'?'group':'private']];
        trioAssert($method==='sendRichMessage'&&$body['chat_id']==='881001'&&count($images)===3,'Only a three-photo native card to independently proved Telegram owner');
        $key=null;foreach (ownerSelectionHotels() as $candidate=>$hotel) if ($hotel['title']===$body['rich_message']['blocks'][1]['text'][0]['text']) $key=$candidate;
        trioAssert($key!==null,'Only fixed catalog hotel can be sent');
        $record=json_decode(file_get_contents($directory.'/'.ownerSelectionDemoPrefix().'-telegram-'.$key.'.json'),true);
        trioAssert($record['post_reserved']===true&&$record['state']==='post_reserved'&&strlen($record['post_sha256'])===64,'Durable per-card reservation precedes Telegram message POST');
        if ($key==='imperial'&&in_array($failure,['tg_timeout','tg_reject','tg_throw'],true)) {
            if ($failure==='tg_throw') throw new RuntimeException('PRIVATE_TOKEN');
            return ['data'=>null,'diagnostic'=>['http_status'=>$failure==='tg_reject'?400:0,'error_category'=>'transport']];
        }
        $native=trioNative('telegram',$key);if ($failure==='tg_text'&&$key==='imperial') $native['rich_message']['blocks'][2]['text']='Wrong price';
        return ['data'=>$native,'diagnostic'=>['http_status'=>200,'curl_code'=>0,'error_category'=>'none']];
    };
    $max=static function($method,$path,$query,$body,$token)use(&$calls,$failure,$directory):array {
        $calls[]=['max',$method,$path];
        $diagnostic=['http_status'=>200,'curl_code'=>0,'error_category'=>'none','api_code'=>null];
        if ($path==='/messages/mid.owner_inbound') return ['data'=>['body'=>['mid'=>'mid.owner_inbound'],'sender'=>['user_id'=>772001],
            'recipient'=>['user_id'=>772099,'chat_id'=>-770001,'chat_type'=>$failure==='max_identity'?'chat':'dialog']],'diagnostic'=>$diagnostic];
        if ($path==='/uploads') return ['data'=>['url'=>'https://iu.oneme.ru/uploadImage?opaque=private'],'diagnostic'=>$diagnostic];
        if ($method==='GET') {
            $key=substr($path,strlen('/messages/mid.trio_'));$native=trioNative('max',$key);
            if ($failure==='max_text'&&$key==='imperial') $native['body']['text']='Wrong price';
            return ['data'=>$native,'diagnostic'=>$diagnostic];
        }
        trioAssert($method==='POST'&&$path==='/messages'&&$query===['user_id'=>'772001'],'MAX creates only in the separately proved owner chat');
        $key=null;foreach (ownerSelectionHotels() as $candidate=>$hotel) if ($body['text']===ownerSelectionMaxText($candidate,true)) $key=$candidate;
        trioAssert($key!==null,'Only fixed native MAX caption is sent');
        $record=json_decode(file_get_contents($directory.'/'.ownerSelectionDemoPrefix().'-max-'.$key.'.json'),true);
        trioAssert($record['post_reserved']===true&&$record['state']==='post_reserved'&&count($record['image_tokens'])===3,'All source-bound image tokens and permanent reservation are durable before MAX POST');
        if ($key==='imperial'&&$failure==='max_timeout') return ['data'=>null,'diagnostic'=>['http_status'=>0,'error_category'=>'transport']];
        return ['data'=>['body'=>['mid'=>'mid.trio_'.$key]],'diagnostic'=>$diagnostic];
    };
    $download=static function($key,$index,$directory)use(&$downloads,$failure):?array {
        $downloads[]=[$key,$index];if ($failure==='image') return null;
        $path=$directory.'/.owner-preview-image-'.$index.'-'.bin2hex(random_bytes(8)).'.tmp';
        file_put_contents($path,($failure==='source_changed'?'changed-':'fixed-').$key.'-'.$index);chmod($path,0600);return ['path'=>$path,'mime'=>'image/jpeg'];
    };
    $upload=static function($url,$image)use(&$uploadCount,$failure):array {
        $uploadCount++;trioAssert(ownerPreviewUploadUrl($url),'Only signed MAX upload endpoint is used');
        if ($failure==='upload'&&$uploadCount===2) return ['data'=>null];
        return ['data'=>['photos'=>['opaque'=>['token'=>'PRIVATE_IMAGE_TOKEN_'.hash_file('sha256',$image['path'])]]],'diagnostic'=>['http_status'=>200,'error_category'=>'none']];
    };
    $targets=[];foreach (['telegram','max'] as $channel) { $row=array_replace(trioRow($channel),$changes[$channel]??[]);$targets[$channel]=static fn()=>$row; }
    $sha=str_repeat('a',40);
    $out=ownerSelectionRun($sha,$sha,'anytour',['telegram'=>'PRIVATE_TG_TOKEN','max'=>'PRIVATE_MAX_TOKEN'],$targets,['telegram'=>$tg,'max'=>$max],$directory,['download'=>$download,'upload'=>$upload]);
    trioAssert(!str_contains(json_encode($out),'PRIVATE_')&&!str_contains(json_encode($out),'881001')&&!str_contains(json_encode($out),'772001'),'Public output contains no credentials, native IDs or media tokens');
    trioAssert(!is_dir($directory)||glob($directory.'/.owner-preview-image-*.tmp')===[],'Owned temporary image files are removed');
    return [$out,$calls,$directory,$downloads,$uploadCount];
}
function trioSends(array $calls): int { return count(array_filter($calls,static fn($call)=>$call[0]==='telegram'&&$call[1]==='sendRichMessage'||$call[0]==='max'&&$call[1]==='POST'&&$call[2]==='/messages')); }
if (defined('OWNER_SELECTION_FIXTURE_LIBRARY')) return;
$sha=str_repeat('a',40);trioAssert(ownerSelectionArguments(['--expected-sha='.$sha,'--confirm-owner-trio'])===$sha,'Only exact fixed batch activation is accepted');
foreach ([[],['--expected-sha='.$sha,'--confirm-owner-trio','--chat-id=1'],['--expected-sha='.$sha,'--confirm-owner-trio','--confirm-owner-telegram']] as $args) trioAssert(ownerSelectionArguments($args)===null,'No arbitrary recipient or mixed mode input');
$source=file_get_contents(dirname(__DIR__).'/tools/owner_tour_selection_preview.php');
trioAssert(str_contains($source,"defined('MAX_SEARCH_TOKEN')")&&!str_contains($source,"defined('MAX_BOT_TOKEN')"),'Entrypoint uses the canonical installed MAX credential');
foreach (ownerSelectionHotels() as $key=>$hotel) {
    trioAssert(count($hotel['photos'])===3&&count(array_unique($hotel['photos']))===3,'Each actual hotel has three distinct fixed original photos');
    $body=ownerSelectionTelegramBody($key);trioAssert($body['rich_message']['blocks'][0]['type']==='slideshow'&&$body['rich_message']['blocks'][4]['type']==='footer','Telegram uses the demonstrated native carousel and gray footer');
    trioAssert(str_contains(ownerSelectionMaxText($key),$hotel['price']."\n5–12")&&str_contains(ownerSelectionMaxText($key),'Демо:'),'Price is immediately followed by dates, all terms remain demonstrative');
    trioAssert(telegramOwnerPreviewMatches(trioNative('telegram',$key),'881001','881099','slideshow',$body),'Each Telegram response matches exact selected hotel/body');
    $identity=['user'=>'772001','bot'=>'772099','chat'=>'-770001'];$native=trioNative('max',$key);
    trioAssert(ownerSelectionMaxMatches($native,'mid.trio_'.$key,$identity,$key),'MAX accepts semantically identical reordered strong span keys/order');
    $native['body']['markup'][0]['extra']=1;trioAssert(!ownerSelectionMaxMatches($native,'mid.trio_'.$key,$identity,$key),'Additional formatting fields cannot verify');
    $native=trioNative('max',$key);$native['body']['markup'][1]=$native['body']['markup'][0];trioAssert(!ownerSelectionMaxMatches($native,'mid.trio_'.$key,$identity,$key),'Duplicate strong spans cannot verify');
}
[$out,$calls,$directory,$downloads,$uploads]=trioRun();
trioAssert($out['ok']&&count($out['cards'])===6&&trioSends($calls)===6&&count($downloads)===9&&$uploads===9,'Exactly three native messages per channel with nine real photos downloaded once and uploaded to MAX once');
[$again,$calls,$_,$downloads,$uploads]=trioRun('',$directory);trioAssert($again['ok']&&trioSends($calls)===0&&$downloads===[]&&$uploads===0,'Verified full batch is skipped without repeated send/download/upload');
foreach (['tg_timeout','tg_reject','tg_throw','tg_text','max_timeout','max_text'] as $failure) {
    [$out,$calls,$directory]=trioRun($failure);trioAssert(!$out['ok']&&trioSends($calls)===(str_starts_with($failure,'tg_')?2:5),'Batch stops on first uncertain creation/readback');
    [$again,$calls]=trioRun('',$directory);
    trioAssert(trioSends($calls)===(in_array($failure,['max_text'],true)?1:0),'Unknown sends never replay; known MAX MID may be reverified GET-only before continuing the never-attempted final card');
}
[$out,$calls,$directory]=trioRun('upload');trioAssert(!$out['ok']&&trioSends($calls)===3,'Upload failure occurs before a MAX message reservation');
[$again,$calls,$_,$downloads,$uploads]=trioRun('',$directory);trioAssert($again['ok']&&trioSends($calls)===3&&$uploads===8,'Pre-send resume reuses the successful source-bound upload token and never resends Telegram');
[$out,$calls,$directory]=trioRun('upload');[$again,$calls]=trioRun('source_changed',$directory);trioAssert(!$again['ok']&&$again['state']==='source_changed'&&trioSends($calls)===0,'Changed remote photo bytes stop cross-channel continuation');
foreach (['bot','chat','max_identity','image'] as $failure) { [$out,$calls]=trioRun($failure);trioAssert(!$out['ok']&&trioSends($calls)===0,'Identity/media failures cannot create any card'); }
foreach (['telegram','max'] as $channel) { [$out,$calls]=trioRun('',null,[$channel=>['channel'=>'website']]);trioAssert(!$out['ok']&&trioSends($calls)===0,'Wrong source channel cannot select a recipient'); }
[$out,$calls,$directory]=trioRun('upload');file_put_contents($directory.'/owner-trio-20261021-max-be-grand.json','{');
[$again,$calls]=trioRun('',$directory);trioAssert(!$again['ok']&&trioSends($calls)===0,'Corrupt later receipt stops the entire batch before new creations');
[$out,$calls,$directory]=trioRun('upload');$path=$directory.'/owner-trio-20261021-max-be-grand.json';unlink($path);symlink('/does/not/exist',$path);
[$again,$calls]=trioRun('',$directory);trioAssert(!$again['ok']&&trioSends($calls)===0,'Symlink receipt never redirects private writes or permits send');unlink($path);
foreach ($directories as $directory) if (is_dir($directory)) { foreach (glob($directory.'/*')?:[] as $path) unlink($path);rmdir($directory); }
echo "OWNER TOUR SELECTION PREVIEW: $checks checks PASSED\n";
