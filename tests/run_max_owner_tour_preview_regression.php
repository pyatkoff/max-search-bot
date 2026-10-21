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
            previewAssert(count(array_filter($calls,static fn($c)=>$c[0]==='POST'))===1,'No automatic second POST');
            if($failure==='throw')throw new RuntimeException('PRIVATE_TOKEN');
            if($failure==='timeout')return null;
            if($failure==='unknown_code')return ownerPreviewDecode('{"code":"photo.fetch.failed","message":"PRIVATE_TOKEN"}',400,0,0);
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
    $request=static function($method,$path,$query,$body,$token)use(&$calls,$failure,$dir,$before,$anchor):?array {
        $calls[]=[$method,$path,$query,$body];
        if ($method==='POST') {
            previewAssert(count(array_filter($calls,static fn($c)=>$c[0]==='POST'))===1,'Recovery has one POST maximum');
            $journal=json_decode(file_get_contents($dir.'/delphin-imperial-20261021-recovery.json'),true);
            previewAssert($journal['state']==='attempt_reserved'&&$journal['original_receipt_sha256']===hash('sha256',$before)&&isset($journal['created_at']),'Durable journal bound to old receipt exists before POST');
            previewAssert((fileperms($dir.'/delphin-imperial-20261021-recovery.json')&0777)===0600,'Recovery journal private');
            if ($failure==='throw') throw new RuntimeException('PRIVATE_TOKEN');
            if ($failure==='timeout') return ownerPreviewDecode('',0,28,0);
            if ($failure==='http400') return ownerPreviewDecode('{"code":"attachment.not.ready","message":"PRIVATE_TOKEN"}',400,0,0);
            if ($failure==='unknown_code') return ownerPreviewDecode('{"code":"photo.fetch.failed","message":"PRIVATE_TOKEN"}',400,0,0);
            return ownerPreviewDecode('{"message":{"mid":"mid.recovered"}}',200,0,0);
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
        if ($failure==='readback') return null;
        if ($failure==='readback_throw') throw new RuntimeException('PRIVATE_READBACK');
        return ['body'=>['mid'=>'mid.recovered','text'=>ownerPreviewBody()['text'],'attachments'=>ownerPreviewBody()['attachments']],
            'sender'=>['user_id'=>'991099'],'recipient'=>['user_id'=>'991002','chat_id'=>'991001','chat_type'=>'dialog']];
    };
    $result=ownerPreviewRecoverRun($sha,$sha,'anytour','PRIVATE_TOKEN','previewFixture',$request,$dir,1792541000);
    if ($failure!=='changed_receipt') previewAssert(file_get_contents($dir.'/delphin-imperial-20261021.json')===$before,'Recovery never changes original receipt');
    previewAssert(!str_contains(json_encode($result),'PRIVATE_')&&!str_contains(json_encode($result),'991002'),'Recovery output redacted');
    return [$result,$calls,$dir];
}
foreach (['stale','wrong_phrase','wrong_identity','history403','missing_anchor','truncated','out_of_window','bad_anchor_time','media','suspect','bot','changed_receipt'] as $failure) {
    [$out,$calls,$dir]=recoveryTest($failure);
    previewAssert(!$out['send_attempted']&&count(array_filter($calls,static fn($c)=>$c[0]==='POST'))===0,'Failed corroboration never recovers: '.$failure);
    previewAssert(!file_exists($dir.'/delphin-imperial-20261021-recovery.json'),'Preflight failures reserve no send');
}
foreach (['','timeout','http400','unknown_code','throw','readback','readback_throw'] as $failure) {
    [$out,$calls,$dir]=recoveryTest($failure);
    previewAssert($out['send_attempted']&&$out['ok']===($failure===''),'Only fully verified recovery reports success');
    $journal=json_decode(file_get_contents($dir.'/delphin-imperial-20261021-recovery.json'),true);
    previewAssert(isset($journal['created_at'])&&in_array($journal['state'],['unknown','verified','sent_unverified'],true),'Recovery keeps created_at and terminal receipt');
    if ($failure==='unknown_code') previewAssert(($journal['private_api_code']??null)==='photo.fetch.failed'&&!str_contains(json_encode($out),'photo.fetch.failed'),'Private journal retains exact safe unknown code; stdout does not');
    [$again,$replayed]=recoveryTest('',$dir);
    previewAssert($again['state']==='recovery_already_attempted'&&$replayed===[],'Every recovery journal blocks forever, even explicit HTTP400');
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
echo "OWNER TOUR PREVIEW: $checks PASS\n";
