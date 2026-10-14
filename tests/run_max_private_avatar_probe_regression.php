<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/MaxPrivateAvatarProbe.php';

$passed=0;
function avatarProbeCheck(string $name, bool $ok): void
{
    global $passed;
    if (!$ok) throw new RuntimeException('FAIL '.$name);
    $passed++;echo 'PASS '.$name."\n";
}

final class AvatarProbePdo extends PDO
{
    public array $operations=[];
    public ?array $row=['id'=>'2','project_key'=>'anytour','channel'=>'max','original_display_name'=>'Pavel',
        'conversation_chat_id'=>'991001','channel_chat_id'=>'991001','external_user_id'=>'991002'];
    public bool $transaction=false;
    public bool $failRead=false;
    public function __construct() {}
    public function inTransaction(): bool {return $this->transaction;}
    public function exec(string $statement): int|false
    {
        if ($statement!=='SET TRANSACTION READ ONLY') throw new RuntimeException('write_forbidden');
        $this->operations[]=['readonly'];return 0;
    }
    public function beginTransaction(): bool {$this->operations[]=['begin'];$this->transaction=true;return true;}
    public function rollBack(): bool {$this->operations[]=['rollback'];$this->transaction=false;return true;}
    public function prepare(string $query, array $options=[]): PDOStatement|false
    {
        if (!str_starts_with($query,'SELECT ') || !$this->transaction) throw new RuntimeException('write_forbidden');
        $this->operations[]=['prepare',$query];return new AvatarProbeStatement($this);
    }
}
final class AvatarProbeStatement extends PDOStatement
{
    public function __construct(private AvatarProbePdo $database) {}
    public function execute(?array $params=null): bool
    {
        $this->database->operations[]=['execute',$params];
        if ($params!==['2','anytour','max','Pavel'] || $this->database->failRead) throw new RuntimeException('PRIVATE_DATABASE_ERROR');
        return true;
    }
    public function fetch(int $mode=PDO::FETCH_DEFAULT, int $orientation=PDO::FETCH_ORI_NEXT, int $offset=0): mixed
    {
        return $this->database->row ?? false;
    }
}
final class AvatarProbeExchange
{
    public array $calls=[];
    public array $receipt=['http_status'=>200,'transport_error'=>false,'ssl_verify_result'=>0];
    public string $body='';
    public bool $throw=false;
    public bool $rollbackBeforeRequest=false;
    public function __construct(private AvatarProbePdo $database)
    {
        $this->body=json_encode(['chat_id'=>991001,'type'=>'dialog','dialog_with_user'=>[
            'user_id'=>991002,'first_name'=>'PRIVATE_PROFILE','avatar_url'=>'https://photos.invalid/PRIVATE_PROFILE_URL',
            'full_avatar_url'=>'https://photos.invalid/PRIVATE_FULL_URL'],
            'icon'=>['url'=>'https://photos.invalid/PRIVATE_CHANNEL_ICON'],'pinned_message'=>['text'=>'PRIVATE_MESSAGE']],JSON_THROW_ON_ERROR);
    }
    public function request(string $url, array $options): array
    {
        $this->calls[]=[$url,$options];
        $this->rollbackBeforeRequest=!$this->database->inTransaction()
            && ($this->database->operations[count($this->database->operations)-1][0]??'')==='rollback';
        if ($this->throw) throw new RuntimeException('PRIVATE_TOKEN_ERROR');
        $sink=$options[CURLOPT_WRITEFUNCTION];
        // Drive the real bounded body sink; no curl or provider request runs.
        foreach (str_split($this->body,4096) as $chunk) if ($sink(null,$chunk)!==strlen($chunk)) break;
        return $this->receipt;
    }
}
function probeFixture(): array
{
    $db=new AvatarProbePdo();$exchange=new AvatarProbeExchange($db);
    return [$db,$exchange,new MaxPrivateAvatarProbe($db,[$exchange,'request'])];
}
$sha=str_repeat('a',40);
$input=['expected_sha'=>$sha,'confirm_owner_test'=>true];
function probeRun(MaxPrivateAvatarProbe $probe, ?array $input=null, ?string $installed=null, string $project='anytour', string $token='PRIVATE_TOKEN'): array
{
    global $sha;
    return $probe->run($input ?? ['expected_sha'=>$sha,'confirm_owner_test'=>true],$installed ?? $sha,$project,$token);
}
function probeRedacted(array $result): bool
{
    $json=json_encode($result,JSON_THROW_ON_ERROR);
    return array_keys($result)===['ok','reason','request_attempted','http_status','type_is_dialog','chat_id_match','user_id_match','has_avatar_url','has_full_avatar_url']
        && !preg_match('/PRIVATE_|991001|991002|https?:|Pavel|anytour/',$json);
}

avatarProbeCheck('CLI accepts only expected SHA and explicit owner-test confirmation',MaxPrivateAvatarProbe::arguments(['--expected-sha='.$sha,'--confirm-owner-test'])===$input);
foreach ([[],['--expected-sha='.$sha],['--confirm-owner-test'],['--expected-sha='.$sha,'--confirm-owner-test','--confirm-owner-test'],
    ['--expected-sha='.$sha,'--expected-sha='.$sha,'--confirm-owner-test'],['--expected-sha='.strtoupper($sha),'--confirm-owner-test'],
    ['--expected-sha='.$sha,'--confirm-owner-test','--conversation-id=991001'],
    ['--expected-sha='.$sha,'--confirm-owner-test','--url=https://PRIVATE_URL.invalid'],
    ['--expected-sha='.$sha,'--confirm-owner-test=true']] as $case) {
    avatarProbeCheck('missing, repeated or arbitrary-target CLI arguments fail closed',MaxPrivateAvatarProbe::arguments($case)===null);
}
avatarProbeCheck('signed int64 identities retain exact decimal strings',MaxPrivateAvatarProbe::decimalId('9223372036854775807')==='9223372036854775807');
foreach (['0','-991001','0991001','9223372036854775808','18446744073709551615','1e3','+1',1.0,true,null,'991001/../me'] as $value) {
    avatarProbeCheck('invalid, floating and overflowing IDs never become a URL',MaxPrivateAvatarProbe::decimalId($value)===null);
}
foreach ([['expected_sha'=>$sha],array_replace($input,['confirm_owner_test'=>false]),$input+['conversation_id'=>'3']] as $case) {
    [$db,$exchange,$probe]=probeFixture();$result=probeRun($probe,$case);
    avatarProbeCheck('invalid service input reads no target and calls no provider',$result['reason']==='invalid_arguments'&&$db->operations===[]&&$exchange->calls===[]&&probeRedacted($result));
}
foreach ([['installed_sha_mismatch',str_repeat('b',40),'anytour','PRIVATE_TOKEN'],['configured_project_mismatch',$sha,'other','PRIVATE_TOKEN'],
    ['configured_token_unavailable',$sha,'anytour',''],['configured_token_unavailable',$sha,'anytour',"PRIVATE_TOKEN\r\nInjected: bad"]] as [$reason,$installed,$project,$token]) {
    [$db,$exchange,$probe]=probeFixture();$result=probeRun($probe,$input,$installed,$project,$token);
    avatarProbeCheck('provenance and config mismatch stop before database/provider',$result['reason']===$reason&&$db->operations===[]&&$exchange->calls===[]&&probeRedacted($result));
}

[$db,$exchange,$probe]=probeFixture();$result=probeRun($probe);
avatarProbeCheck('one matched private GET reports photo presence without profile data',$result['ok']&&$result['reason']==='photo_url_present'&&$result['has_avatar_url']&&$result['has_full_avatar_url']&&count($exchange->calls)===1&&probeRedacted($result));
avatarProbeCheck('target resolution uses one read-only transaction rolled back before HTTP',array_column($db->operations,0)===['readonly','begin','prepare','execute','rollback']&&$exchange->rollbackBeforeRequest);
$sql=$db->operations[2][1];
avatarProbeCheck('SQL binds exact owner row and original customer/channel/project identity',str_contains($sql,'cu.id=c.customer_id')&&str_contains($sql,'cc.id=c.customer_channel_id')&&str_contains($sql,'cc.customer_id=c.customer_id')&&str_contains($sql,'cc.project_key=c.project_key')&&str_contains($sql,'cc.channel=c.channel')&&str_contains($sql,'p.is_active=1')&&str_contains($sql,'c.id=? AND c.project_key=? AND c.channel=? AND cu.display_name=? LIMIT 1'));
[$url,$options]=$exchange->calls[0];
avatarProbeCheck('transport uses fixed API origin, stored chat ID and GET only',$url==='https://platform-api2.max.ru/chats/991001'&&$options[CURLOPT_CUSTOMREQUEST]==='GET'&&!isset($options[CURLOPT_POSTFIELDS]));
avatarProbeCheck('transport verifies TLS and never follows redirects',$options[CURLOPT_SSL_VERIFYPEER]===true&&$options[CURLOPT_SSL_VERIFYHOST]===2&&$options[CURLOPT_FOLLOWLOCATION]===false&&$options[CURLOPT_MAXREDIRS]===0&&$options[CURLOPT_PROTOCOLS]===CURLPROTO_HTTPS);
avatarProbeCheck('transport bounds connect and total timeout without printing Authorization',$options[CURLOPT_CONNECTTIMEOUT]===5&&$options[CURLOPT_TIMEOUT]===10&&$options[CURLOPT_HTTPHEADER][0]==='Authorization: PRIVATE_TOKEN'&&probeRedacted($result));

foreach ([['id'=>'3'],['project_key'=>'other'],['channel'=>'telegram'],['original_display_name'=>'pavel'],
    ['conversation_chat_id'=>'-991001'],['channel_chat_id'=>'991003'],['channel_chat_id'=>null],['external_user_id'=>'9223372036854775808']] as $changes) {
    [$db,$exchange,$probe]=probeFixture();$db->row=array_replace($db->row,$changes);$result=probeRun($probe);
    avatarProbeCheck('changed, nonprivate or unbound stored target issues no request',!$result['request_attempted']&&!$result['ok']&&$exchange->calls===[]&&!$db->inTransaction()&&probeRedacted($result));
}
[$db,$exchange,$probe]=probeFixture();$db->row=null;$result=probeRun($probe);
avatarProbeCheck('missing target cannot trigger a profile lookup',$result['reason']==='target_unavailable'&&$exchange->calls===[]&&!$db->inTransaction());
[$db,$exchange,$probe]=probeFixture();$db->failRead=true;$result=probeRun($probe);
avatarProbeCheck('database failure rolls back without leaking exception or calling provider',$result['reason']==='target_unavailable'&&$exchange->calls===[]&&!$db->inTransaction()&&probeRedacted($result));
[$db,$exchange,$probe]=probeFixture();$db->transaction=true;$result=probeRun($probe);
avatarProbeCheck('existing caller transaction is neither reused nor rolled back',$result['reason']==='target_unavailable'&&$db->operations===[]&&$db->inTransaction()&&$exchange->calls===[]);

foreach ([['type'=>'channel'],['chat_id'=>991003],['dialog_with_user'=>['user_id'=>991003,'avatar_url'=>'https://PRIVATE_PHOTO.invalid']],
    ['dialog_with_user'=>null],['chat_id'=>991001.0]] as $changes) {
    [$db,$exchange,$probe]=probeFixture();$data=json_decode($exchange->body,true);$exchange->body=json_encode(array_replace($data,$changes),JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION);$result=probeRun($probe);
    avatarProbeCheck('provider identity mismatch never advertises someone else photo',$result['reason']==='provider_identity_mismatch'&&!$result['ok']&&!$result['has_avatar_url']&&!$result['has_full_avatar_url']&&count($exchange->calls)===1&&probeRedacted($result));
}
[$db,$exchange,$probe]=probeFixture();$exchange->body=json_encode(['chat_id'=>991001,'type'=>'dialog','dialog_with_user'=>['user_id'=>991002,'avatar_url'=>null],'icon'=>['url'=>'https://PRIVATE_CHANNEL_ICON.invalid']],JSON_THROW_ON_ERROR);$result=probeRun($probe);
avatarProbeCheck('photo absent is explicit and channel icon never substitutes for customer',$result['ok']&&$result['reason']==='photo_url_absent'&&!$result['has_avatar_url']&&!$result['has_full_avatar_url']&&probeRedacted($result));
foreach (['http://PRIVATE_PROFILE.invalid','javascript:PRIVATE_PROFILE','https://PRIVATE_TOKEN@photos.invalid/path',[],false] as $photo) {
    [$db,$exchange,$probe]=probeFixture();$exchange->body=json_encode(['chat_id'=>991001,'type'=>'dialog','dialog_with_user'=>['user_id'=>991002,'avatar_url'=>$photo]],JSON_THROW_ON_ERROR);$result=probeRun($probe);
    avatarProbeCheck('invalid photo field is not reported as available',$result['reason']==='photo_url_absent'&&!$result['has_avatar_url']&&count($exchange->calls)===1&&probeRedacted($result));
}
[$db,$exchange,$probe]=probeFixture();$db->row['conversation_chat_id']=$db->row['channel_chat_id']='9223372036854775807';$db->row['external_user_id']='9223372036854775806';
$exchange->body='{"chat_id":9223372036854775807,"type":"dialog","dialog_with_user":{"user_id":9223372036854775806}}';$result=probeRun($probe);
avatarProbeCheck('largest canonical identities are compared without overflow',$result['ok']&&$result['chat_id_match']&&$result['user_id_match']&&count($exchange->calls)===1);

foreach ([403,404,429,500,302] as $http) {
    [$db,$exchange,$probe]=probeFixture();$exchange->receipt['http_status']=$http;$exchange->body='PRIVATE_TOKEN_ERROR';$result=probeRun($probe);
    avatarProbeCheck('provider refusal or redirect is recorded once without retry or raw body',$result['reason']==='provider_http_error'&&$result['http_status']===$http&&$result['request_attempted']&&count($exchange->calls)===1&&probeRedacted($result));
}
[$db,$exchange,$probe]=probeFixture();$exchange->receipt['transport_error']=true;$result=probeRun($probe);
avatarProbeCheck('transport failure stays attempted and never retries',$result['reason']==='transport_error'&&$result['request_attempted']&&count($exchange->calls)===1&&probeRedacted($result));
[$db,$exchange,$probe]=probeFixture();$exchange->throw=true;$result=probeRun($probe);
avatarProbeCheck('unknown transport exception is redacted and never retries',$result['reason']==='transport_error'&&$result['request_attempted']&&count($exchange->calls)===1&&probeRedacted($result));
[$db,$exchange,$probe]=probeFixture();$exchange->receipt['ssl_verify_result']=20;$result=probeRun($probe);
avatarProbeCheck('unverified TLS response cannot become avatar evidence',$result['reason']==='strict_tls_failed'&&!$result['ok']&&count($exchange->calls)===1);
foreach (['PRIVATE_PROFILE_NOT_JSON','null','[1,2,3]'] as $body) {
    [$db,$exchange,$probe]=probeFixture();$exchange->body=$body;$result=probeRun($probe);
    avatarProbeCheck('malformed or non-object body produces no profile evidence',!$result['ok']&&count($exchange->calls)===1&&probeRedacted($result));
}
[$db,$exchange,$probe]=probeFixture();$exchange->body=str_pad($exchange->body,MaxPrivateAvatarProbe::MAX_RESPONSE_BYTES,' ');$result=probeRun($probe);
avatarProbeCheck('bounded response accepts exactly sixty-four KiB',$result['ok']&&count($exchange->calls)===1);
[$db,$exchange,$probe]=probeFixture();$exchange->body=str_repeat('X',MaxPrivateAvatarProbe::MAX_RESPONSE_BYTES+1);$result=probeRun($probe);
avatarProbeCheck('body sink stops above sixty-four KiB without parsing or retry',$result['reason']==='response_too_large'&&!$result['ok']&&count($exchange->calls)===1&&probeRedacted($result));

// Real CLI invocation proves invalid/provenance inputs never need config.php.
foreach ([[],['--expected-sha='.str_repeat('0',40),'--confirm-owner-test']] as $arguments) {
    $command=[PHP_BINARY,dirname(__DIR__).'/tools/max_private_avatar_probe.php',...$arguments];
    $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($process)) throw new RuntimeException('CLI process unavailable');
    fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);fclose($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[2]);$code=proc_close($process);
    $result=json_decode($stdout,true,24,JSON_THROW_ON_ERROR);
    avatarProbeCheck('actual CLI rejects unsafe input before configuration with redacted JSON',$code!==0&&$stderr===''&&!$result['request_attempted']&&probeRedacted($result));
}
echo 'TOTAL '.$passed." passed\n";
