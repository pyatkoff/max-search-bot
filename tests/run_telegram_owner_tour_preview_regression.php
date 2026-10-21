<?php
declare(strict_types=1);
define('TELEGRAM_OWNER_PREVIEW_OFFLINE_TEST',true);
require_once dirname(__DIR__).'/tools/telegram_owner_tour_preview.php';
$checks=0;$directories=[];
function tgAssert(bool $ok,string $message): void { global $checks;$checks++;if (!$ok) throw new RuntimeException($message); }
function tgRow(): array { return ['id'=>'1','project_key'=>'anytour','channel'=>'telegram','status'=>'manager','external_user_id'=>'881001','conversation_chat_id'=>'881001','channel_chat_id'=>'881001','message_sender_id'=>'881001','external_message_id'=>'177']; }
final class TgPreviewPdo extends PDO {
    public bool $active=false;public array $operations=[];public bool $fail=false;
    public function __construct(){}
    public function inTransaction():bool{return $this->active;}
    public function exec(string $sql):int|false{tgAssert($sql==='SET TRANSACTION READ ONLY','Only read-only setup');$this->operations[]='readonly';return 0;}
    public function beginTransaction():bool{$this->active=true;$this->operations[]='begin';return true;}
    public function rollBack():bool{$this->active=false;$this->operations[]='rollback';return true;}
    public function prepare(string $sql,array $options=[]):PDOStatement|false{
        tgAssert($this->active&&str_starts_with($sql,'SELECT ')&&!preg_match('/\b(?:UPDATE|INSERT|DELETE)\b/',$sql),'Owner recognition has one bounded SELECT in a read-only transaction');
        tgAssert(str_contains($sql,'LIMIT 1')&&str_contains($sql,'m.sender_type=?')&&str_contains($sql,'cc.customer_id=c.customer_id'),'Recognition preserves project/channel/customer/message binding');
        $this->operations[]='select';return new TgPreviewStatement($this);
    }
}
final class TgPreviewStatement extends PDOStatement {
    public function __construct(private TgPreviewPdo $db){}
    public function execute(?array $params=null):bool{
        tgAssert($params===['1','anytour','telegram','108046','inbound','customer','Карточки','2026-10-21 15:12:01'],'Only the independently observed fresh recognition message can select the recipient');
        if ($this->db->fail) throw new RuntimeException('PRIVATE_DB_ERROR');return true;
    }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0):mixed{return tgRow();}
}
function tgMessage(string $variant): array {
    $body=telegramOwnerPreviewBody($variant);$blocks=$body['rich_message']['blocks'];
    foreach ([0,1,2] as $i) $blocks[0]['blocks'][$i]=['type'=>'photo','photo'=>[['file_id'=>'PRIVATE_PHOTO_'.$i,'width'=>1100,'height'=>825]]];
    return ['message_id'=>$variant==='collage'?201:202,'chat'=>['id'=>881001,'type'=>'private'],'from'=>['id'=>881099,'is_bot'=>true],
        'rich_message'=>['blocks'=>$blocks],'reply_markup'=>$body['reply_markup']];
}
function tgTest(string $failure='',?string $dir=null,array $changes=[]): array {
    global $directories;
    if ($dir===null) { $dir=sys_get_temp_dir().'/telegram-owner-preview-'.bin2hex(random_bytes(8));$directories[]=$dir; }
    $calls=[];$downloads=[];
    $request=static function($method,$body,$token,$images)use(&$calls,$dir,$failure):array {
        $calls[]=[$method,$body];
        if ($method==='getMe') return ['data'=>['id'=>881099,'username'=>$failure==='bot'?'foreign_bot':'anyru_bot','is_bot'=>true]];
        if ($method==='getChat') { tgAssert($body===['chat_id'=>'881001'],'Only the bound private owner chat is inspected');return ['data'=>['id'=>881001,'type'=>$failure==='chat'?'supergroup':'private']]; }
        tgAssert($method==='sendRichMessage'&&$body['chat_id']==='881001','Only native rich messages to the proved owner are created');
        tgAssert(count($images)===3&&array_keys($images)===[0,1,2],'Both native formats contain the three real photos');
        $variant=$body['rich_message']['blocks'][0]['type'];$record=json_decode(file_get_contents($dir.'/delphin-imperial-native-'.$variant.'-20261021.json'),true);
        tgAssert($record['state']==='post_reserved'&&strlen($record['body_sha256'])===64,'Private durable once reservation exists before every send');
        tgAssert(($body['reply_markup']['inline_keyboard']??null)===[[['text'=>'Нравится','callback_data'=>'owner_preview_like_20261021']]],'One native preview button only');
        if ($failure==='timeout'||($failure==='second_timeout'&&$variant==='slideshow')) return ['data'=>null,'diagnostic'=>['http_status'=>0,'curl_code'=>28,'error_category'=>'transport']];
        if ($failure==='throw') throw new RuntimeException('PRIVATE_TOKEN');
        if ($failure==='reject') return ['data'=>null,'diagnostic'=>['http_status'=>400,'curl_code'=>0,'error_category'=>'http_rejected','api_error_code'=>400]];
        $message=tgMessage($variant);
        if ($failure==='text') $message['rich_message']['blocks'][2]['text']['text']='Wrong price';
        return ['data'=>$message,'diagnostic'=>['http_status'=>200,'curl_code'=>0,'error_category'=>'none','api_error_code'=>null]];
    };
    $download=static function($index,$directory,$comparison)use(&$downloads,$failure):?array{
        $downloads[]=$index;tgAssert($comparison===true,'Use the already verified day/beach/room source set');
        if ($failure==='image') return null;
        $path=$directory.'/.owner-preview-image-'.$index.'-'.bin2hex(random_bytes(8)).'.tmp';file_put_contents($path,'fixed-photo-'.$index);chmod($path,0600);return ['path'=>$path,'mime'=>'image/jpeg'];
    };
    $sha=str_repeat('a',40);$row=array_replace(tgRow(),$changes);
    $out=telegramOwnerPreviewRun($sha,$sha,'anytour','PRIVATE_TOKEN',static fn()=>$row,$request,$dir,$download);
    tgAssert(!str_contains(json_encode($out),'PRIVATE_')&&!str_contains(json_encode($out),'881001')&&!str_contains(json_encode($out),'881099'),'Public result excludes credentials, native IDs and file IDs');
    tgAssert(!is_dir($dir)||glob($dir.'/.owner-preview-image-*.tmp')===[],'All owned temporary photo files are removed');
    return [$out,$calls,$dir,$downloads];
}
$db=new TgPreviewPdo();tgAssert(telegramOwnerPreviewTarget($db)===tgRow()&&!$db->active&&$db->operations===['readonly','begin','select','rollback'],'DB transaction ends before Telegram is called');
$db=new TgPreviewPdo();$db->fail=true;tgAssert(telegramOwnerPreviewTarget($db)===null&&!$db->active,'Lookup failure rolls back without business writes');
$sha=str_repeat('a',40);tgAssert(telegramOwnerPreviewArguments(['--expected-sha='.$sha,'--confirm-owner-telegram'])===$sha,'Exact fixed owner command accepted');
foreach ([[],['--expected-sha='.$sha,'--confirm-owner-send'],['--expected-sha='.$sha,'--confirm-owner-telegram','--chat-id=3'],['--expected-sha='.$sha,'--confirm-owner-telegram','--confirm-owner-variants']] as $args)
    tgAssert(telegramOwnerPreviewArguments($args)===null,'No arbitrary recipient, payload or mixed activation accepted');
foreach (['collage','slideshow'] as $variant) {
    $message=tgMessage($variant);tgAssert(telegramOwnerPreviewMatches($message,'881001','881099',$variant),'Native content response matches the chosen media layout');
    $normalized=$message;$normalized['rich_message']['blocks'][3]['text']=[$normalized['rich_message']['blocks'][3]['text']];
    $normalized['rich_message']['blocks'][2]['text']['text']=['238 400 ₽',' за двоих'];
    tgAssert(telegramOwnerPreviewMatches($normalized,'881001','881099',$variant),'Equivalent provider list encodings preserve exact native text and bold');
    foreach (['owner','bot','button','photo','bold','text','variant'] as $failure) {
        $bad=$message;
        if ($failure==='owner') $bad['chat']['id']=881002;
        elseif ($failure==='bot') $bad['from']['id']=881002;
        elseif ($failure==='button') $bad['reply_markup']['inline_keyboard'][0][0]['text']='Other';
        elseif ($failure==='photo') array_pop($bad['rich_message']['blocks'][0]['blocks']);
        elseif ($failure==='bold') $bad['rich_message']['blocks'][2]['text']='238 400 ₽ за двоих';
        elseif ($failure==='text') $bad['rich_message']['blocks'][3]['text']='Other conditions';
        else $bad['rich_message']['blocks'][0]['type']=$variant==='collage'?'slideshow':'collage';
        tgAssert(!telegramOwnerPreviewMatches($bad,'881001','881099',$variant),'Wrong identity, media, text, formatting or button cannot verify delivery');
    }
}
[$first,$_,$dir]=tgTest();
file_put_contents($dir.'/delphin-imperial-native-slideshow-20261021.json','{');
[$again,$calls]=tgTest('',$dir);tgAssert(!$again['ok']&&count(array_filter($calls,static fn($call)=>$call[0]==='sendRichMessage'))===0,'Malformed permanent receipt cannot cause another creation');
foreach (['','timeout','second_timeout','throw','reject','text','bot','chat','image'] as $failure) {
    [$out,$calls,$dir]=tgTest($failure);$sends=count(array_filter($calls,static fn($call)=>$call[0]==='sendRichMessage'));
    tgAssert($out['ok']===($failure==='')&&$sends===match($failure){'','second_timeout'=>2,'bot','chat','image'=>0,default=>1},'Pair sends at most once per layout and stops at the first uncertainty');
    [$again,$calls]=tgTest('',$dir);$resends=count(array_filter($calls,static fn($call)=>$call[0]==='sendRichMessage'));
    tgAssert($resends===(in_array($failure,['bot','chat','image'],true)?2:0),'Unknown or known creation cannot be replayed; pre-send failures may proceed explicitly');
}
foreach (['id'=>'2','channel'=>'max','conversation_chat_id'=>'-881001','channel_chat_id'=>'881002','message_sender_id'=>'881002','status'=>'closed'] as $key=>$value) {
    [$out,$calls]=tgTest('',null,[$key=>$value]);tgAssert(!$out['send_attempted']&&$calls===[],'Wrong owner/channel/state fails before provider calls');
}
foreach ($directories as $dir) if (is_dir($dir)) { foreach (glob($dir.'/*.json')?:[] as $path) unlink($path);rmdir($dir); }
echo "TELEGRAM OWNER TOUR PREVIEW: $checks PASS\n";
