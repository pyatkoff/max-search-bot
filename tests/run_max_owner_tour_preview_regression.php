<?php
declare(strict_types=1);
define('MAX_OWNER_PREVIEW_OFFLINE_TEST',true);
require_once dirname(__DIR__).'/tools/max_owner_tour_preview.php';
$checks=0;
function previewAssert(bool $ok,string $message): void { global $checks;$checks++;if(!$ok)throw new RuntimeException($message); }
function previewFixture(): array {
    return ['id'=>'2','project_key'=>'anytour','channel'=>'max','status'=>'manager','original_display_name'=>'Pavel',
        'external_user_id'=>'991002','conversation_chat_id'=>'-991002','channel_chat_id'=>'-991002',
        'external_message_id'=>'mid.fixture','message_sender_id'=>'991002'];
}
final class PreviewPdo extends PDO {
    public bool $transaction=false;
    public bool $fail=false;
    public array $operations=[];
    public function __construct(){}
    public function inTransaction():bool{return $this->transaction;}
    public function exec(string $query):int|false{previewAssert($query==='SET TRANSACTION READ ONLY','Only READ ONLY setup');$this->operations[]='readonly';return 0;}
    public function beginTransaction():bool{$this->transaction=true;$this->operations[]='begin';return true;}
    public function rollBack():bool{$this->transaction=false;$this->operations[]='rollback';return true;}
    public function prepare(string $query,array $options=[]):PDOStatement|false{
        previewAssert($this->transaction&&str_starts_with($query,'SELECT '),'Only transactional SELECTs');
        previewAssert(!preg_match('/SELECT[^;]*m\.text\b/',$query),'No transcript text selected');
        $message=str_contains($query,'FROM messages m');
        $this->operations[]=$message?'owned_message':'fixed_owner';
        return new PreviewStatement($this,$message);
    }
}
final class PreviewStatement extends PDOStatement {
    public function __construct(private PreviewPdo $db,private bool $message){}
    public function execute(?array $params=null):bool{
        previewAssert($params===($this->message?['2','max','inbound','customer','991002']:['2','anytour','max','Pavel']),'Exact owner query bindings');
        if($this->db->fail)throw new RuntimeException('PRIVATE_DB_ERROR');return true;
    }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0):mixed{
        return $this->message?['external_message_id'=>'mid.fixture','message_sender_id'=>'991002']:previewFixture();
    }
}
function previewTestRun(array $changes=[],string $failure='',?string $dir=null): array {
    $dir=$dir??sys_get_temp_dir().'/max-owner-preview-'.bin2hex(random_bytes(8));
    $calls=[];$row=array_replace(previewFixture(),$changes);$sha=str_repeat('a',40);
    $request=static function($method,$path,$query,$body,$token)use(&$calls,$failure):?array {
        $calls[]=[$method,$path,$query,$body];
        if($method==='POST'){
            previewAssert(count(array_filter($calls,static fn($c)=>($c[0]==='POST'&&$c[1]==='/messages')))===1,'No automatic second POST');
            if($failure==='throw')throw new RuntimeException('PRIVATE_TOKEN');
            if($failure==='timeout')return null;
            if($failure==='unknown_code')return ownerPreviewDecode('{"code":"photo.fetch.failed","message":"field attachments: PRIVATE_TOKEN https://private.test user991002"}',400,0,0,false,'PRIVATE_TOKEN');
            return ['message'=>['body'=>['mid'=>'mid.sent']]];
        }
        if($path==='/messages/mid.fixture')return ['body'=>['mid'=>'mid.fixture'],
            'sender'=>['user_id'=>$failure==='wrong_sender'?'991003':'991002'],
            'recipient'=>['chat_id'=>'991001','chat_type'=>'dialog','user_id'=>'991099']];
        if($failure==='readback')return null;
        return ['body'=>['mid'=>'mid.sent','text'=>ownerPreviewBody()['text'],'attachments'=>ownerPreviewBody()['attachments']],
            'sender'=>['user_id'=>'991099'],'recipient'=>['user_id'=>'991002','chat_id'=>'991001','chat_type'=>'dialog']];
    };
    $result=ownerPreviewRun($sha,$sha,'anytour','PRIVATE_TOKEN',static fn()=>$row,$request,$dir);
    return [$result,$calls,$dir];
}
$sha=str_repeat('a',40);
$db=new PreviewPdo();$target=ownerPreviewTarget($db);
previewAssert($target===previewFixture()&&!$db->inTransaction()&&$db->operations===['readonly','begin','fixed_owner','owned_message','rollback'],'Read-only lookup rolls back before provider');
$db=new PreviewPdo();$db->fail=true;
previewAssert(ownerPreviewTarget($db)===null&&!$db->inTransaction(),'Database exception rolls back and fails closed');
$db=new PreviewPdo();$db->transaction=true;
previewAssert(ownerPreviewTarget($db)===null&&$db->operations===[]&&$db->inTransaction(),'Existing transaction untouched');
previewAssert(ownerPreviewArguments(['--expected-sha='.$sha,'--confirm-owner-send'])===$sha,'Explicit fixed command');
foreach([[],['--confirm-owner-send'],['--expected-sha='.$sha],['--expected-sha='.$sha,'--confirm-owner-send','--user-id=3'],['--expected-sha='.$sha,'--confirm-owner-send','--confirm-owner-send'],['--expected-sha='.strtoupper($sha),'--confirm-owner-send']]as$args)previewAssert(ownerPreviewArguments($args)===null,'Reject missing/repeated/arbitrary arguments');
$body=ownerPreviewBody();
previewAssert(count($body['attachments'])===4,'Three fixed photos plus one keyboard');
previewAssert($body['attachments'][3]['payload']['buttons']===[[['type'=>'message','text'=>'Нравится']]],'Native text button, no callback payload');
$readback=['body'=>['mid'=>'mid.semantic','text'=>$body['text'],'attachments'=>$body['attachments']],
    'sender'=>['user_id'=>'991099'],'recipient'=>['user_id'=>'991002','chat_id'=>'991001','chat_type'=>'dialog']];
$readback['body']['attachments'][3]['payload']['buttons']=[[['text'=>'Нравится','type'=>'message']]];
previewAssert(ownerPreviewMatches($readback,'mid.semantic','991002','991099','991001'),'Native reversed associative key order is the same one-button card');
foreach ([[['text'=>'Другое','type'=>'message']],[['text'=>'Нравится','type'=>'callback']],[['text'=>'Нравится','type'=>'message'],['text'=>'Нравится','type'=>'message']]] as $row) {
    $bad=$readback;$bad['body']['attachments'][3]['payload']['buttons']=[$row];
    previewAssert(!ownerPreviewMatches($bad,'mid.semantic','991002','991099','991001'),'Wrong label/type or extra keyboard cell is rejected');
}
previewAssert(str_contains($body['text'],'демонстрационные')&&str_contains($body['text'],'238 400 ₽ за двоих'),'Clearly labelled demo price');
$called=false;$out=ownerPreviewRun($sha,str_repeat('b',40),'anytour','PRIVATE_TOKEN',static function()use(&$called){$called=true;},static fn()=>null,'/never-created');
previewAssert(!$called&&!$out['send_attempted'],'Provenance failure has no lookup/send');
foreach([['id'=>'3'],['project_key'=>'anex'],['channel'=>'telegram'],['original_display_name'=>'pavel'],['status'=>'bot'],['external_user_id'=>'0'],['channel_chat_id'=>'991003'],['message_sender_id'=>'991003'],['external_message_id'=>'../unsafe']]as$changes){
    [$out,$calls]=previewTestRun($changes);
    previewAssert(!$out['send_attempted']&&$calls===[],'Other identity or unsafe state cannot send');
}
[$out,$calls]=previewTestRun([],'wrong_sender');
previewAssert($out['state']==='identity_unverified'&&count($calls)===1,'Provider identity mismatch stops before POST');
[$out,$calls,$dir]=previewTestRun();
previewAssert($out['ok']&&$out['state']==='verified'&&count($calls)===3,'One identity GET, one POST, one readback GET');
previewAssert($calls[1][2]===['user_id'=>'991002'],'Uses verified external user, not internal chat alias');
previewAssert((fileperms($dir)&0777)===0700&&(fileperms($dir.'/delphin-imperial-20261021.json')&0777)===0600,'Receipt is private');
[$again,$calls]=previewTestRun([],'',$dir);
previewAssert($again['state']==='already_attempted'&&$calls===[],'Verified receipt prevents all replay');
foreach(['timeout','throw','readback']as$failure){
    [$out,$calls,$dir]=previewTestRun([],$failure);
    previewAssert(!$out['ok']&&$out['send_attempted'],'Unknown/failed readback never claims success');
    [$again,$replayed]=previewTestRun([],'',$dir);
    previewAssert($again['state']==='already_attempted'&&$replayed===[],'Unknown send is never replayed');
    previewAssert(!str_contains(json_encode($out),'PRIVATE_')&&!str_contains(json_encode($out),'991002'),'Public result contains no private identity/token');
}
// Existing receipt corruption and symlinks fail closed, not as permission to send again.
$dir=sys_get_temp_dir().'/max-owner-preview-'.bin2hex(random_bytes(8));mkdir($dir,0700);
file_put_contents($dir.'/delphin-imperial-20261021.json','corrupt');
[$out,$calls]=previewTestRun([],'',$dir);
previewAssert($out['state']==='already_attempted'&&$calls===[],'Corrupt receipt still prevents replay');
$dir=sys_get_temp_dir().'/max-owner-preview-'.bin2hex(random_bytes(8));mkdir($dir,0755);
[$out,$calls]=previewTestRun([],'',$dir);
previewAssert(!$out['send_attempted']&&count($calls)===1,'Nonprivate receipt directory cannot send');
$bad=$dir.'-link';symlink($dir,$bad);
[$out,$calls]=previewTestRun([],'',$bad);
previewAssert(!$out['send_attempted'],'Symlink directory is never used for receipt');
previewAssert(ownerPreviewRecoveryArguments(['--expected-sha='.$sha,'--confirm-owner-recovery'])===$sha,'Recovery requires its own explicit confirmation');
previewAssert(ownerPreviewRecoveryArguments(['--expected-sha='.$sha,'--confirm-owner-send'])===null,'Ordinary confirmation cannot authorize recovery');
foreach ([['code'=>'attachment.not.ready','message'=>'PRIVATE_TOKEN'],['code'=>'PRIVATE_TOKEN','message'=>'991002']] as $error) {
    $decoded=ownerPreviewDecode(json_encode($error),400,0,0);
    previewAssert($decoded['data']===null&&$decoded['diagnostic']['http_status']===400,'HTTP errors cannot masquerade as success');
    previewAssert(!str_contains(json_encode($decoded['diagnostic']),'PRIVATE_')&&!str_contains(json_encode($decoded['diagnostic']),'991002'),'Never print provider message/unknown code');
}
previewAssert(ownerPreviewDecode('',0,28,0)['diagnostic']['error_category']==='transport','Timeout has bounded curl category');
previewAssert(ownerPreviewDecode('{}',200,0,20)['diagnostic']['error_category']==='tls','TLS verification failure fails closed');
previewAssert(ownerPreviewDecode('{}',200,0,0,true)['diagnostic']['error_category']==='response_too_large','Overflow fails closed');
previewAssert(ownerPreviewMid(['message'=>['mid'=>'mid.canonical']])==='mid.canonical','Canonical message.mid fallback');
previewAssert(ownerPreviewDecode('{"code":"proto.payload","message":"PRIVATE_DETAILS"}',400,0,0)['diagnostic']['api_code']==='proto.payload','Fixed safe protocol code remains actionable');
$privateError=ownerPreviewDecode('{"code":"photo.fetch.failed","message":"PRIVATE_DETAILS"}',400,0,0);
previewAssert($privateError['private_api_code']==='photo.fetch.failed'&&$privateError['diagnostic']['api_code']===null&&!str_contains(json_encode($privateError['diagnostic']),'photo.fetch.failed'),'Unknown structured code stays private');
$privateMessage=ownerPreviewDecode(json_encode(['code'=>'proto.payload','message'=>"field attachments: PRIVATE_TOKEN https://private.test/path?key=PRIVATE_TOKEN user 991002\n\x01"],JSON_THROW_ON_ERROR),400,0,0,false,'PRIVATE_TOKEN');
previewAssert($privateMessage['private_api_message']==='field attachments: [redacted-token] [redacted-url] user [redacted-number] ','Only sanitized provider message field retained');
previewAssert(!str_contains(json_encode(ownerPreviewDiagnostic($privateMessage)),'field attachments'),'Provider message never enters public diagnostics');
previewAssert(ownerPreviewDecode('{"message":{"unexpected":"nested"}}',400,0,0)['private_api_message']===null,'Nested fields/full body are never captured as message');
previewAssert(strlen(ownerPreviewPrivateMessage(str_repeat('я',2048),''))===2048&&preg_match('//u',ownerPreviewPrivateMessage(str_repeat('я',2048),''))===1,'Private message cap is2KiB of valid UTF8');
foreach (['http://example.test','token123','bad code','Upper.case',str_repeat('a',65)] as $badCode)
    previewAssert(ownerPreviewDecode(json_encode(['code'=>$badCode]),400,0,0)['private_api_code']===null,'Private code rejects URL/digits/whitespace/case/oversize');
function recoveryTest(string $failure='',?string $dir=null): array {
    $dir=$dir??sys_get_temp_dir().'/max-owner-recovery-'.bin2hex(random_bytes(8));
    if (!is_dir($dir)) {
        mkdir($dir,0700);file_put_contents($dir.'/delphin-imperial-20261021.json',json_encode(['demo'=>'delphin-imperial-20261021','state'=>'unknown']));
        chmod($dir.'/delphin-imperial-20261021.json',0600);
    }
    $before=file_get_contents($dir.'/delphin-imperial-20261021.json');$calls=[];$sha=str_repeat('a',40);
    $anchor=['timestamp'=>1792539801694,'body'=>['mid'=>'mid.fixture','text'=>'Пусто у меня'],
        'sender'=>['user_id'=>'991002'],'recipient'=>['user_id'=>'991099','chat_id'=>'991001','chat_type'=>'dialog']];
    $saved=is_file($dir.'/delphin-imperial-20261021-recovery.json')?json_decode(file_get_contents($dir.'/delphin-imperial-20261021-recovery.json'),true):null;
    $edited=($saved['state']??null)==='verified';
    $request=static function($method,$path,$query,$body,$token)use(&$calls,&$edited,$failure,$dir,$before,$anchor):?array {
        $calls[]=[$method,$path,$query,$body];
        if ($method==='POST'&&$path==='/messages') {
            previewAssert(count(array_filter($calls,static fn($c)=>($c[0]==='POST'&&$c[1]==='/messages')))===1,'Recovery has one POST maximum');
            previewAssert($body===['text'=>ownerPreviewBody()['text']],'Creation is canonical text-only, no speculative attachment POST');
            $journal=json_decode(file_get_contents($dir.'/delphin-imperial-20261021-recovery.json'),true);
            previewAssert($journal['state']==='attempt_reserved'&&$journal['original_receipt_sha256']===hash('sha256',$before)&&isset($journal['created_at']),'Durable journal bound to old receipt exists before POST');
            previewAssert((fileperms($dir.'/delphin-imperial-20261021-recovery.json')&0777)===0600,'Recovery journal private');
            if ($failure==='concurrent') {
                $sha=str_repeat('a',40);
                $duplicate=ownerPreviewRecoverRun($sha,$sha,'anytour','PRIVATE_TOKEN','previewFixture',static function(){throw new RuntimeException('Concurrent provider call');},$dir,1792541000);
                previewAssert(!$duplicate['send_attempted']&&$duplicate['provider_requests']===0,'Concurrent activation cannot enter a reserved repair');
            }
            if ($failure==='throw') throw new RuntimeException('PRIVATE_TOKEN');
            if ($failure==='timeout') return ownerPreviewDecode('',0,28,0);
            if ($failure==='http400') return ownerPreviewDecode('{"code":"attachment.not.ready","message":"PRIVATE_TOKEN"}',400,0,0);
            if ($failure==='proto_payload') return ownerPreviewDecode('{"code":"proto.payload","message":"PRIVATE_TOKEN"}',400,0,0,false,'PRIVATE_TOKEN');
            if ($failure==='unknown_code') return ownerPreviewDecode('{"code":"photo.fetch.failed","message":"field attachments: PRIVATE_TOKEN https://private.test user991002"}',400,0,0,false,'PRIVATE_TOKEN');
            return ownerPreviewDecode('{"message":{"mid":"mid.recovered"}}',200,0,0);
        }
        if ($path==='/uploads') {
            previewAssert($method==='POST'&&$query===['type'=>'image']&&$body===null,'Only fixed image upload reservation');
            if ($failure==='upload_host') return ownerPreviewDecode('{"url":"https://iu.oneme.ru.evil.test/uploadImage?token=secret"}',200,0,0);
            return ownerPreviewDecode('{"url":"https://iu.oneme.ru/uploadImage?token=signed"}',200,0,0);
        }
        if ($method==='PUT') {
            $journal=json_decode(file_get_contents($dir.'/delphin-imperial-20261021-recovery.json'),true);
            previewAssert(($journal['message_id']??null)==='mid.recovered'&&$journal['state']==='edit_attempt_reserved','MID durable before edit');
            previewAssert(($journal['image_tokens']??null)===['PRIVATE_IMAGE_TOKEN_0','PRIVATE_IMAGE_TOKEN_1','PRIVATE_IMAGE_TOKEN_2'],'All three tokens durable before PUT');
            previewAssert($path==='/messages'&&$query===['message_id'=>'mid.recovered']&&$body['text']===ownerPreviewBody()['text']&&$body['attachments'][3]===ownerPreviewBody()['attachments'][3]&&array_column(array_slice($body['attachments'],0,3),'payload')===[['token'=>'PRIVATE_IMAGE_TOKEN_0'],['token'=>'PRIVATE_IMAGE_TOKEN_1'],['token'=>'PRIVATE_IMAGE_TOKEN_2']],'PUT only same exact known demo MID and fixed full body');
            if ($failure==='edit_rejected') return ownerPreviewDecode(json_encode(['code'=>'proto.payload','message'=>'Invalid attachments[0].payload for mid.recovered owner991002 https://private.test PRIVATE_TOKEN']),400,0,0,false,'PRIVATE_TOKEN',['mid.recovered']);
            if ($failure==='edit_false') return ownerPreviewDecode('{"success":false,"message":"Invalid attachments payload for mid.recovered PRIVATE_TOKEN"}',200,0,0,false,'PRIVATE_TOKEN',['mid.recovered']);
            if ($failure==='edit_timeout') return ownerPreviewDecode('',0,28,0);
            $edited=true;return ownerPreviewDecode('{"success":true}',200,0,0);
        }
        if ($path==='/messages/mid.fixture') {
            if ($failure==='stale') return array_replace($anchor,['timestamp'=>1792539643999]);
            if ($failure==='wrong_phrase') return array_replace_recursive($anchor,['body'=>['text'=>'другое']]);
            if ($failure==='wrong_identity') return array_replace_recursive($anchor,['sender'=>['user_id'=>'1']]);
            return $anchor;
        }
        if ($path==='/messages') {
            previewAssert($query===['chat_id'=>'991001','from'=>1792541000000,'to'=>1792539612000,'count'=>100],'Fixed full send-to-now history request');
            if ($failure==='history403') return ownerPreviewDecode('{}',403,0,0);
            if ($failure==='missing_anchor') return ['messages'=>[]];
            if ($failure==='truncated') return ['messages'=>array_fill(0,100,$anchor)];
            if ($failure==='out_of_window') return ['messages'=>[$anchor,array_replace($anchor,['timestamp'=>1792541000001])]];
            if ($failure==='bad_anchor_time') return ['messages'=>[array_replace($anchor,['timestamp'=>1792539801695])]];
            if ($failure==='media') return ['messages'=>[array_replace_recursive($anchor,['body'=>['attachments'=>[['type'=>'image']]]])]];
            if ($failure==='suspect') return ['messages'=>[$anchor,array_replace_recursive($anchor,['sender'=>['user_id'=>'333']])]];
            if ($failure==='bot') {
                $bot=array_replace_recursive($anchor,['sender'=>['user_id'=>'991099'],'body'=>['mid'=>'mid.other']]);unset($bot['recipient']['user_id']);
                return ['messages'=>[$anchor,$bot]];
            }
            if ($failure==='changed_receipt') file_put_contents($dir.'/delphin-imperial-20261021.json',$before.' ');
            return ['messages'=>[$anchor]];
        }
        if ($failure==='readback'||$failure==='edit404') return null;
        if ($failure==='readback_throw') throw new RuntimeException('PRIVATE_READBACK');
        return ['body'=>['mid'=>'mid.recovered','text'=>ownerPreviewBody()['text'],'attachments'=>$edited?ownerPreviewBody()['attachments']:[]],
            'sender'=>['user_id'=>'991099'],'recipient'=>['user_id'=>$failure==='edit_wrong_owner'?'991003':'991002','chat_id'=>'991001','chat_type'=>'dialog']];
    };
    $transfers=['download'=>static function($index,$directory)use($failure) {
        if ($failure==='download') return null;
        $path=$directory.'/.owner-preview-image-'.$index.'-'.bin2hex(random_bytes(8)).'.tmp';
        file_put_contents($path,'offline-image');chmod($path,0600);return ['path'=>$path,'mime'=>$failure==='download_mime'?'text/html':'image/jpeg'];
    },'upload'=>static function($url,$image)use($failure) {
        previewAssert(ownerPreviewUploadUrl($url)&&is_file($image['path']),'Only validated signed upload URL and private file');
        preg_match('/image-([012])-/',basename($image['path']),$m);$index=(int)$m[1];
        if ($failure==='upload_malformed') return ownerPreviewDecode('{"wrong":{"token":"PRIVATE_IMAGE_TOKEN"}}',200,0,0);
        if ($failure==='upload_partial'&&$index===1) return ownerPreviewDecode('{}',500,0,0);
        return ownerPreviewDecode(json_encode(['photos'=>['opaque-photo-key'=>['token'=>'PRIVATE_IMAGE_TOKEN_'.$index]]]),200,0,0);
    }];
    $result=ownerPreviewRecoverRun($sha,$sha,'anytour','PRIVATE_TOKEN','previewFixture',$request,$dir,1792541000,$transfers);
    if ($failure!=='changed_receipt') previewAssert(file_get_contents($dir.'/delphin-imperial-20261021.json')===$before,'Recovery never changes original receipt');
    previewAssert(!str_contains(json_encode($result),'PRIVATE_')&&!str_contains(json_encode($result),'991002'),'Recovery output redacted');
    return [$result,$calls,$dir];
}
foreach (['stale','wrong_phrase','wrong_identity','history403','missing_anchor','truncated','out_of_window','bad_anchor_time','media','suspect','bot','changed_receipt'] as $failure) {
    [$out,$calls,$dir]=recoveryTest($failure);
    previewAssert(!$out['send_attempted']&&count(array_filter($calls,static fn($c)=>($c[0]==='POST'&&$c[1]==='/messages')))===0,'Failed corroboration never recovers: '.$failure);
    previewAssert(!file_exists($dir.'/delphin-imperial-20261021-recovery.json'),'Preflight failures reserve no send');
}
foreach (['','timeout','http400','proto_payload','unknown_code','throw','readback','readback_throw'] as $failure) {
    [$out,$calls,$dir]=recoveryTest($failure);
    previewAssert($out['send_attempted']&&$out['ok']===($failure===''),'Only fully verified recovery reports success');
    $journal=json_decode(file_get_contents($dir.'/delphin-imperial-20261021-recovery.json'),true);
    previewAssert(isset($journal['created_at'])&&in_array($journal['state'],['unknown','verified','sent_unverified','text_created','edit_unverified'],true),'Recovery keeps created_at and terminal receipt');
    if ($failure==='unknown_code') {
        previewAssert(($journal['private_api_code']??null)==='photo.fetch.failed'&&!str_contains(json_encode($out),'photo.fetch.failed'),'Private journal retains exact safe unknown code; stdout does not');
        previewAssert(str_contains($journal['private_api_message']??'','field attachments')&&!str_contains(json_encode($out),'field attachments')&&!str_contains($journal['private_api_message'],'PRIVATE_TOKEN'),'Private message captured in journal only after redaction');
    }
    [$again,$replayed]=recoveryTest('',$dir);
    previewAssert(count(array_filter($replayed,static fn($c)=>($c[0]==='POST'&&$c[1]==='/messages')))===0,'Any revision permanently blocks every future POST');
    if (!isset($journal['message_id'])) previewAssert($again['state']==='recovery_already_attempted'&&$replayed===[],'Unknown text creation blocks all later requests');
    [$old,$replayed]=previewTestRun([],'',$dir);
    previewAssert($old['state']==='already_attempted'&&$replayed===[],'Original sender once guard unchanged after recovery');
}
[$out,$calls,$dir]=recoveryTest('missing_anchor');
file_put_contents($dir.'/delphin-imperial-20261021-recovery.json','corrupt');
[$out,$calls]=recoveryTest('',$dir);
previewAssert($out['state']==='recovery_already_attempted'&&$calls===[],'Corrupt recovery journal still blocks all provider requests');
[$out,$calls,$dir]=previewTestRun([],'timeout');
$receipt=json_decode(file_get_contents($dir.'/delphin-imperial-20261021.json'),true);
previewAssert(isset($receipt['created_at'])&&$receipt['state']==='unknown','Original unknown receipt now preserves created_at');
[$out,$calls,$dir]=previewTestRun([],'unknown_code');
$receipt=json_decode(file_get_contents($dir.'/delphin-imperial-20261021.json'),true);
previewAssert(($receipt['private_api_code']??null)==='photo.fetch.failed'&&!str_contains(json_encode($out),'photo.fetch.failed'),'Original sender also retains safe unknown code privately only');
previewAssert(str_contains($receipt['private_api_message']??'','field attachments')&&!str_contains(json_encode($out),'field attachments')&&!str_contains($receipt['private_api_message'],'PRIVATE_TOKEN'),'Original receipt also stores only sanitized private error message');
function legacyRejectionFixture(array $changes=[]): array {
    [,,$dir]=recoveryTest('missing_anchor');
    $record=['demo'=>'delphin-imperial-20261021','state'=>'unknown','created_at'=>'2026-10-21T00:20:00+00:00',
        'original_receipt_sha256'=>hash_file('sha256',$dir.'/delphin-imperial-20261021.json'),
        'send_diagnostic'=>['http_status'=>400,'curl_code'=>0,'error_category'=>'http_rejected','api_code'=>'proto.payload'],
        'private_api_code'=>'proto.payload'];
    $record=array_replace_recursive($record,$changes);$bytes=json_encode($record)."\n";
    $path=$dir.'/delphin-imperial-20261021-recovery.json';file_put_contents($path,$bytes);chmod($path,0600);
    return [$dir,$record,$bytes];
}
foreach ([['state'=>'sent_unverified','message_id'=>'mid.old'],['state'=>'verified'],['send_diagnostic'=>['http_status'=>0,'curl_code'=>28]],
          ['send_diagnostic'=>['curl_code'=>28]],['send_diagnostic'=>['http_status'=>500]],['send_diagnostic'=>['api_code'=>'attachment.not.ready']],
          ['repair_revision'=>'payload-repair-v1'],['message_id'=>null],['original_receipt_sha256'=>str_repeat('0',64)]] as $changes) {
    [$dir,$record,$bytes]=legacyRejectionFixture($changes);
    [$out,$calls]=recoveryTest('',$dir);
    previewAssert($out['state']==='recovery_already_attempted'&&$calls===[],'Only exact legacy400/curl0/proto.payload/noMID may continue');
    previewAssert(file_get_contents($dir.'/delphin-imperial-20261021-recovery.json')===$bytes,'Ineligible prior journal never changes');
}
[$dir,$record,$bytes]=legacyRejectionFixture();
$lock=fopen($dir.'/delphin-imperial-20261021-recovery.json','r+');flock($lock,LOCK_EX);
[$out,$calls]=recoveryTest('',$dir);
previewAssert($out['state']==='recovery_already_attempted'&&$calls===[],'Concurrent locked legacy journal blocks before provider');
fclose($lock);
[$out,$calls]=recoveryTest('concurrent',$dir);
$journal=json_decode(file_get_contents($dir.'/delphin-imperial-20261021-recovery.json'),true);
previewAssert($out['ok']&&$journal['repair_revision']==='payload-repair-v1'&&$journal['state']==='verified'&&$journal['message_id']==='mid.recovered','Exact legacy rejection creates text then edits once and persists verified MID');
previewAssert($journal['previous_recovery']===$record&&$journal['previous_recovery_sha256']===hash('sha256',$bytes),'Full prior journal retained immutably nested with exact byte hash');
[$again,$calls]=recoveryTest('',$dir);
previewAssert($again['ok']&&count($calls)===1&&$calls[0][0]==='GET','Completed same-MID resume reads back without PUT or POST');
[$dir,$record,$bytes]=legacyRejectionFixture();
[$out,$calls]=recoveryTest('missing_anchor',$dir);
previewAssert(!$out['send_attempted']&&file_get_contents($dir.'/delphin-imperial-20261021-recovery.json')===$bytes,'Repair still needs complete fresh anchored history');
foreach (['timeout','proto_payload','readback'] as $failure) {
    [$dir,$record,$bytes]=legacyRejectionFixture();
    [$out,$calls]=recoveryTest($failure,$dir);
    $journal=json_decode(file_get_contents($dir.'/delphin-imperial-20261021-recovery.json'),true);
    previewAssert(!$out['ok']&&$journal['previous_recovery']===$record,'Uncertain/failed repair retains original rejection evidence');
    [$again,$calls]=recoveryTest('',$dir);
    previewAssert(count(array_filter($calls,static fn($c)=>($c[0]==='POST'&&$c[1]==='/messages')))===0,'Any attempted repair revision blocks future creation, including same400');
    if (!isset($journal['message_id'])) previewAssert($again['state']==='recovery_already_attempted'&&$calls===[],'Unknown creation without MID never resumes');
}
[$dir,$record,$bytes]=legacyRejectionFixture();
file_put_contents($dir.'/delphin-imperial-20261021-recovery.json.payload-repair-v1.tmp','crash residue');
[$out,$calls]=recoveryTest('',$dir);
previewAssert(!$out['send_attempted']&&file_get_contents($dir.'/delphin-imperial-20261021-recovery.json')===$bytes,'Interrupted reservation fails closed and keeps prior record intact');
foreach (['edit_rejected','edit_false','edit_timeout'] as $failure) {
    [$dir]=legacyRejectionFixture();
    [$out,$calls]=recoveryTest($failure,$dir);
    previewAssert(!$out['ok']&&$out['edit_attempted']&&count(array_filter($calls,static fn($c)=>($c[0]==='POST'&&$c[1]==='/messages')))===1&&count(array_filter($calls,static fn($c)=>$c[0]==='PUT'))===1,'One text creation, one same-MID edit on rejection/timeout');
    if ($failure!=='edit_timeout') previewAssert(str_contains($out['validation_message']??'','Invalid attachments')&&!str_contains($out['validation_message'],'mid.recovered')&&!str_contains($out['validation_message'],'991002')&&!str_contains($out['validation_message'],'PRIVATE_TOKEN')&&!str_contains($out['validation_message'],'https:'),'Bounded validator message retains field and strips private identifiers');
    [$out,$calls]=recoveryTest('wrong_phrase',$dir);
    previewAssert($out['ok']&&$calls[0][0]==='GET'&&$calls[0][1]==='/messages/mid.recovered'&&count(array_filter($calls,static fn($c)=>($c[0]==='POST'&&$c[1]==='/messages')||$c[1]==='/messages'&&$c[0]==='GET'))===0,'Edit resume ignores newer owner phrase/history and never creates');
}
[$dir]=legacyRejectionFixture();
[,,$dir]=recoveryTest('edit_rejected',$dir);
foreach (['edit404','edit_wrong_owner'] as $failure) {
    [$out,$calls]=recoveryTest($failure,$dir);
    previewAssert(!$out['ok']&&!$out['edit_attempted']&&count($calls)===1&&$calls[0][0]==='GET','Missing/foreign known MID cannot fall back to PUT or POST');
}
previewAssert(ownerPreviewUploadUrl('https://iu.oneme.ru/uploadImage?token=signed%2Bvalue'),'Canonical signed image upload URL allowed');
foreach (['http://iu.oneme.ru/uploadImage?token=x','https://iu.oneme.ru.evil.test/uploadImage?token=x','https://evil.oneme.ru/uploadImage?token=x','https://user@iu.oneme.ru/uploadImage?token=x','https://iu.oneme.ru:444/uploadImage?token=x','https://iu.oneme.ru/uploadImage?token=x#fragment','https://iu.oneme.ru/other?token=x'] as $url)
    previewAssert(!ownerPreviewUploadUrl($url),'Untrusted upload origin/path/credentials/port rejected');
previewAssert(ownerPreviewUploadedToken(['photos'=>['opaque'=>['token'=>'opaque+/=.:_-token']]])==='opaque+/=.:_-token','Typed photo token preserves opaque punctuation');
foreach ([['token'=>'x'],['photos'=>['a'=>['token'=>'x'],'b'=>['token'=>'y']]],['photos'=>['a'=>['token'=>42]]],['photos'=>['a'=>['token'=>str_repeat('x',4097)]]]] as $data)
    previewAssert(ownerPreviewUploadedToken($data)===null,'Wrong/ambiguous/oversized upload token blocked');
foreach (['download','download_mime','upload_host','upload_malformed','upload_partial'] as $failure) {
    [$dir]=legacyRejectionFixture();recoveryTest('readback',$dir);
    [$out,$calls]=recoveryTest($failure,$dir);
    previewAssert(!$out['ok']&&!$out['edit_attempted']&&count(array_filter($calls,static fn($c)=>$c[0]==='PUT'||($c[0]==='POST'&&$c[1]==='/messages')))===0,'Image failure cannot create or edit a message');
    previewAssert(glob($dir.'/.owner-preview-image-*.tmp')===[],'Owned download temporary files cleaned after image failure');
    if ($failure==='upload_partial') {
        $journal=json_decode(file_get_contents($dir.'/delphin-imperial-20261021-recovery.json'),true);
        previewAssert(($journal['image_tokens']??null)===['PRIVATE_IMAGE_TOKEN_0'],'Partial successful tokens persist privately');
        [$out,$calls]=recoveryTest('',$dir);
        previewAssert($out['ok']&&count(array_filter($calls,static fn($c)=>$c[1]==='/uploads'))===2,'Resume reuses stored token and uploads only missing images');
    }
}
$opaque='PRIVATE_IMAGE_TOKEN_sensitive+/=';
$decoded=ownerPreviewDecode(json_encode(['code'=>'proto.payload','message'=>'Invalid token '.$opaque.' '.str_repeat('tail',800)]),400,0,0,false,'PRIVATE_TOKEN',[$opaque]);
previewAssert(!str_contains($decoded['private_api_message'],$opaque)&&strlen($decoded['private_api_message'])<=2048,'Image tokens redacted before bounded error truncation');
echo "OWNER TOUR PREVIEW: $checks PASS\n";
