<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/integrations/MaxPrivateAvatarProbe.php';

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
        'conversation_chat_id'=>'-991002','channel_chat_id'=>'-991002','external_user_id'=>'991002'];
    public ?array $owned=['external_message_id'=>'mid.synthetic_owner','message_sender_id'=>'991002','incoming_type'=>'message'];
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
        $this->operations[]=['prepare',$query];return new AvatarProbeStatement($this,str_contains($query,'FROM messages m'));
    }
}
final class AvatarProbeStatement extends PDOStatement
{
    public function __construct(private AvatarProbePdo $database, private bool $message=false) {}
    public function execute(?array $params=null): bool
    {
        $this->database->operations[]=['execute',$params];
        $expected=$this->message?['2','max','inbound','customer',$this->database->row['external_user_id']]:['2','anytour','max','Pavel'];
        if ($params!==$expected || $this->database->failRead) throw new RuntimeException('PRIVATE_DATABASE_ERROR');
        return true;
    }
    public function fetch(int $mode=PDO::FETCH_DEFAULT, int $orientation=PDO::FETCH_ORI_NEXT, int $offset=0): mixed
    {
        return ($this->message?$this->database->owned:$this->database->row) ?? false;
    }
}
final class AvatarProbeExchange
{
    public array $calls=[];
    public array $receipt=['http_status'=>200,'transport_error'=>false,'ssl_verify_result'=>0];
    public string $body='';
    public string $messageBody='';
    public array $messageReceipt=['http_status'=>200,'transport_error'=>false,'ssl_verify_result'=>0];
    public bool $identityThrow=false;
    public bool $throw=false;
    public bool $rollbackBeforeRequest=false;
    public function __construct(private AvatarProbePdo $database)
    {
        $this->body=json_encode(['chat_id'=>991001,'type'=>'dialog','dialog_with_user'=>[
            'user_id'=>991002,'first_name'=>'PRIVATE_PROFILE','avatar_url'=>'https://photos.invalid/PRIVATE_PROFILE_URL',
            'full_avatar_url'=>'https://photos.invalid/PRIVATE_FULL_URL'],
            'icon'=>['url'=>'https://photos.invalid/PRIVATE_CHANNEL_ICON'],'pinned_message'=>['text'=>'PRIVATE_MESSAGE']],JSON_THROW_ON_ERROR);
        $this->messageBody=json_encode(['body'=>['mid'=>'mid.synthetic_owner','text'=>'PRIVATE_ORIGINAL_MESSAGE'],
            'sender'=>['user_id'=>991002],'recipient'=>['chat_id'=>991001,'chat_type'=>'dialog','user_id'=>991099]],JSON_THROW_ON_ERROR);
    }
    public function request(string $url, array $options): array
    {
        $this->calls[]=[$url,$options];
        $this->rollbackBeforeRequest=!$this->database->inTransaction()
            && ($this->database->operations[count($this->database->operations)-1][0]??'')==='rollback';
        $identity=str_contains($url,'/messages/');
        if (($identity&&$this->identityThrow)||(!$identity&&$this->throw)) throw new RuntimeException('PRIVATE_TOKEN_ERROR');
        $sink=$options[CURLOPT_WRITEFUNCTION];
        // Drive the real bounded body sink; no curl or provider request runs.
        foreach (str_split($identity?$this->messageBody:$this->body,4096) as $chunk) if ($sink(null,$chunk)!==strlen($chunk)) break;
        return $identity?$this->messageReceipt:$this->receipt;
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
    return array_keys($result)===['ok','reason','request_attempted','http_status','identity_lookup_attempted','identity_http_status','provider_request_count','message_identity_verified','type_is_dialog','chat_id_match','user_id_match','has_avatar_url','has_full_avatar_url']
        && !preg_match('/PRIVATE_|991001|991002|synthetic_owner|https?:|Pavel|anytour/',$json);
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
avatarProbeCheck('one identity read and one avatar GET report only redacted photo presence',$result['ok']&&$result['reason']==='photo_url_present'&&$result['has_avatar_url']&&$result['has_full_avatar_url']&&count($exchange->calls)===2&&$result['provider_request_count']===2&&$result['message_identity_verified']&&probeRedacted($result));
avatarProbeCheck('two bounded identity SELECTs roll back before either HTTP read',array_column($db->operations,0)===['readonly','begin','prepare','execute','prepare','execute','rollback']&&$exchange->rollbackBeforeRequest);
$sql=$db->operations[2][1];
avatarProbeCheck('SQL binds exact owner row and original customer/channel/project identity',str_contains($sql,'cu.id=c.customer_id')&&str_contains($sql,'cc.id=c.customer_channel_id')&&str_contains($sql,'cc.customer_id=c.customer_id')&&str_contains($sql,'cc.project_key=c.project_key')&&str_contains($sql,'cc.channel=c.channel')&&str_contains($sql,'p.is_active=1')&&str_contains($sql,'c.id=? AND c.project_key=? AND c.channel=? AND cu.display_name=? LIMIT 1'));
[$url,$options]=$exchange->calls[1];
avatarProbeCheck('avatar URL uses verified provider recipient ID rather than legacy alias',$exchange->calls[0][0]==='https://platform-api2.max.ru/messages/mid.synthetic_owner'&&$url==='https://platform-api2.max.ru/chats/991001'&&!str_contains($url,'-991002')&&$options[CURLOPT_CUSTOMREQUEST]==='GET'&&!isset($options[CURLOPT_POSTFIELDS]));
$messageSql=$db->operations[4][1];
avatarProbeCheck('owned text-message lookup filters channel sender and callback type before LIMIT',str_contains($messageSql,'m.conversation_id=? AND m.channel=? AND m.direction=? AND m.sender_type=? AND m.sender_id=?')&&str_contains($messageSql,'JSON_VALID(m.metadata_json)')&&str_contains($messageSql,"END='message' ORDER BY m.id DESC LIMIT 1")&&!preg_match('/SELECT[^;]*m\.text\b/',$messageSql));
avatarProbeCheck('transport verifies TLS and never follows redirects',$options[CURLOPT_SSL_VERIFYPEER]===true&&$options[CURLOPT_SSL_VERIFYHOST]===2&&$options[CURLOPT_FOLLOWLOCATION]===false&&$options[CURLOPT_MAXREDIRS]===0&&$options[CURLOPT_PROTOCOLS]===CURLPROTO_HTTPS);
avatarProbeCheck('transport bounds connect and total timeout without printing Authorization',$options[CURLOPT_CONNECTTIMEOUT]===5&&$options[CURLOPT_TIMEOUT]===10&&$options[CURLOPT_HTTPHEADER][0]==='Authorization: PRIVATE_TOKEN'&&probeRedacted($result));

foreach ([['id'=>'3'],['project_key'=>'other'],['channel'=>'telegram'],['original_display_name'=>'pavel'],
    ['conversation_chat_id'=>'-0'],['channel_chat_id'=>'991003'],['channel_chat_id'=>null],['external_user_id'=>'9223372036854775808']] as $changes) {
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
avatarProbeCheck('provider identity mismatch never advertises someone else photo',$result['reason']==='provider_identity_mismatch'&&!$result['ok']&&!$result['has_avatar_url']&&!$result['has_full_avatar_url']&&count($exchange->calls)===2&&probeRedacted($result));
}
[$db,$exchange,$probe]=probeFixture();$exchange->body=json_encode(['chat_id'=>991001,'type'=>'dialog','dialog_with_user'=>['user_id'=>991002,'avatar_url'=>null],'icon'=>['url'=>'https://PRIVATE_CHANNEL_ICON.invalid']],JSON_THROW_ON_ERROR);$result=probeRun($probe);
avatarProbeCheck('photo absent is explicit and channel icon never substitutes for customer',$result['ok']&&$result['reason']==='photo_url_absent'&&!$result['has_avatar_url']&&!$result['has_full_avatar_url']&&probeRedacted($result));
foreach (['http://PRIVATE_PROFILE.invalid','javascript:PRIVATE_PROFILE','https://PRIVATE_TOKEN@photos.invalid/path',[],false] as $photo) {
    [$db,$exchange,$probe]=probeFixture();$exchange->body=json_encode(['chat_id'=>991001,'type'=>'dialog','dialog_with_user'=>['user_id'=>991002,'avatar_url'=>$photo]],JSON_THROW_ON_ERROR);$result=probeRun($probe);
    avatarProbeCheck('invalid photo field is not reported as available',$result['reason']==='photo_url_absent'&&!$result['has_avatar_url']&&count($exchange->calls)===2&&probeRedacted($result));
}
[$db,$exchange,$probe]=probeFixture();$db->row['conversation_chat_id']=$db->row['channel_chat_id']='9223372036854775807';$db->row['external_user_id']='9223372036854775806';
$db->owned['message_sender_id']='9223372036854775806';
$exchange->messageBody='{"body":{"mid":"mid.synthetic_owner"},"sender":{"user_id":9223372036854775806},"recipient":{"chat_id":9223372036854775807,"chat_type":"dialog"}}';
$exchange->body='{"chat_id":9223372036854775807,"type":"dialog","dialog_with_user":{"user_id":9223372036854775806}}';$result=probeRun($probe);
avatarProbeCheck('largest canonical identities are compared without overflow',$result['ok']&&$result['chat_id_match']&&$result['user_id_match']&&count($exchange->calls)===2);

foreach ([403,404,429,500,302] as $http) {
    [$db,$exchange,$probe]=probeFixture();$exchange->receipt['http_status']=$http;$exchange->body='PRIVATE_TOKEN_ERROR';$result=probeRun($probe);
    avatarProbeCheck('avatar refusal or redirect is recorded once without retry or raw body',$result['reason']==='provider_http_error'&&$result['http_status']===$http&&$result['request_attempted']&&count($exchange->calls)===2&&probeRedacted($result));
}
[$db,$exchange,$probe]=probeFixture();$exchange->receipt['transport_error']=true;$result=probeRun($probe);
avatarProbeCheck('avatar transport failure stays attempted and never retries',$result['reason']==='transport_error'&&$result['request_attempted']&&count($exchange->calls)===2&&probeRedacted($result));
[$db,$exchange,$probe]=probeFixture();$exchange->throw=true;$result=probeRun($probe);
avatarProbeCheck('unknown avatar exception is redacted and never retries',$result['reason']==='transport_error'&&$result['request_attempted']&&count($exchange->calls)===2&&probeRedacted($result));
[$db,$exchange,$probe]=probeFixture();$exchange->receipt['ssl_verify_result']=20;$result=probeRun($probe);
avatarProbeCheck('unverified TLS response cannot become avatar evidence',$result['reason']==='strict_tls_failed'&&!$result['ok']&&count($exchange->calls)===2);
foreach (['PRIVATE_PROFILE_NOT_JSON','null','[1,2,3]'] as $body) {
    [$db,$exchange,$probe]=probeFixture();$exchange->body=$body;$result=probeRun($probe);
    avatarProbeCheck('malformed or non-object body produces no profile evidence',!$result['ok']&&count($exchange->calls)===2&&probeRedacted($result));
}
[$db,$exchange,$probe]=probeFixture();$exchange->body=str_pad($exchange->body,MaxPrivateAvatarProbe::MAX_RESPONSE_BYTES,' ');$result=probeRun($probe);
avatarProbeCheck('bounded avatar response accepts exactly sixty-four KiB',$result['ok']&&count($exchange->calls)===2);
[$db,$exchange,$probe]=probeFixture();$exchange->body=str_repeat('X',MaxPrivateAvatarProbe::MAX_RESPONSE_BYTES+1);$result=probeRun($probe);
avatarProbeCheck('avatar sink stops above sixty-four KiB without parsing or retry',$result['reason']==='response_too_large'&&!$result['ok']&&count($exchange->calls)===2&&probeRedacted($result));

foreach (['-1','-9223372036854775808','9223372036854775807'] as $value) avatarProbeCheck('chat IDs preserve signed int64 exactly',MaxPrivateAvatarProbe::chatId($value)===$value);
foreach (['0','-0','-01','+1','-9223372036854775809','9223372036854775808',1.0] as $value) avatarProbeCheck('noncanonical or overflowing real chat IDs fail closed',MaxPrivateAvatarProbe::chatId($value)===null);
foreach ([null,['external_message_id'=>'callback.synthetic','message_sender_id'=>'991002','incoming_type'=>'callback'],
    ['external_message_id'=>'../PRIVATE_URL','message_sender_id'=>'991002','incoming_type'=>'message'],
    ['external_message_id'=>'mid.synthetic_owner','message_sender_id'=>'991003','incoming_type'=>'message']] as $owned) {
    [$db,$exchange,$probe]=probeFixture();$db->owned=$owned;$result=probeRun($probe);
    avatarProbeCheck('no owned text message means no identity or avatar request',$result['reason']==='owned_message_unavailable'&&!$result['identity_lookup_attempted']&&!$result['request_attempted']&&$exchange->calls===[]&&probeRedacted($result));
}
foreach ([['body'=>['mid'=>'mid.different']],['sender'=>['user_id'=>991003]],['recipient'=>['chat_id'=>991001,'chat_type'=>'channel']],
    ['recipient'=>['chat_id'=>null,'chat_type'=>'dialog']],['recipient'=>['chat_id'=>991001.0,'chat_type'=>'dialog']]] as $changes) {
    [$db,$exchange,$probe]=probeFixture();$exchange->messageBody=json_encode(array_replace(json_decode($exchange->messageBody,true),$changes),JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION);$result=probeRun($probe);
    avatarProbeCheck('message mid sender or recipient mismatch cannot trigger avatar GET',$result['reason']==='message_identity_mismatch'&&$result['identity_lookup_attempted']&&!$result['request_attempted']&&count($exchange->calls)===1&&$result['provider_request_count']===1&&probeRedacted($result));
}
foreach ([403,404,429,500,302] as $http) {
    [$db,$exchange,$probe]=probeFixture();$exchange->messageReceipt['http_status']=$http;$exchange->messageBody='PRIVATE_MESSAGE_ERROR';$result=probeRun($probe);
    avatarProbeCheck('identity HTTP failure is distinguished and never retried',$result['reason']==='identity_provider_http_error'&&$result['identity_http_status']===$http&&!$result['request_attempted']&&count($exchange->calls)===1&&probeRedacted($result));
}
[$db,$exchange,$probe]=probeFixture();$exchange->identityThrow=true;$result=probeRun($probe);
avatarProbeCheck('unknown identity result stops without avatar call or retry',$result['reason']==='identity_transport_error'&&$result['identity_lookup_attempted']&&!$result['request_attempted']&&count($exchange->calls)===1&&probeRedacted($result));
[$db,$exchange,$probe]=probeFixture();$exchange->messageBody=str_repeat('X',MaxPrivateAvatarProbe::MAX_RESPONSE_BYTES+1);$result=probeRun($probe);
avatarProbeCheck('identity body has the same sixty-four KiB limit',$result['reason']==='identity_response_too_large'&&!$result['request_attempted']&&count($exchange->calls)===1&&probeRedacted($result));
[$db,$exchange,$probe]=probeFixture();$exchange->messageBody='{"body":{"mid":"mid.synthetic_owner"},"sender":{"user_id":991002},"recipient":{"chat_id":-9223372036854775808,"chat_type":"dialog","user_id":991099}}';$exchange->body='{"chat_id":-9223372036854775808,"type":"dialog","dialog_with_user":{"user_id":991002}}';$result=probeRun($probe);
avatarProbeCheck('real negative chat differs from legacy alias and preserves min int64',$result['ok']&&$exchange->calls[1][0]==='https://platform-api2.max.ru/chats/-9223372036854775808'&&$result['chat_id_match']&&$result['user_id_match']);

// Execute the production SELECT/JSON/filter/order/limit expressions on a real
// isolated SQLite database. Only MySQL's READ ONLY spelling and JSON_UNQUOTE
// differ; SQLite JSON_EXTRACT already returns unquoted scalar strings.
final class AvatarProbeSqlite extends PDO
{
    public function __construct() {parent::__construct('sqlite::memory:');$this->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$this->sqliteCreateFunction('JSON_UNQUOTE',static fn($value)=>$value,1);}
    public function exec(string $statement): int|false {return parent::exec($statement==='SET TRANSACTION READ ONLY'?'PRAGMA query_only=ON':$statement);}
}
$sqlDb=new AvatarProbeSqlite();
$sqlDb->exec('CREATE TABLE customers(id INTEGER PRIMARY KEY,display_name TEXT); CREATE TABLE projects(project_key TEXT,is_active INTEGER); CREATE TABLE customer_channels(id INTEGER PRIMARY KEY,customer_id INTEGER,project_key TEXT,channel TEXT,external_chat_id TEXT,external_user_id TEXT); CREATE TABLE conversations(id INTEGER PRIMARY KEY,customer_id INTEGER,customer_channel_id INTEGER,project_key TEXT,channel TEXT,external_chat_id TEXT); CREATE TABLE messages(id INTEGER PRIMARY KEY,conversation_id INTEGER,channel TEXT,direction TEXT,sender_type TEXT,sender_id TEXT,external_message_id TEXT,metadata_json TEXT)');
$sqlDb->prepare('INSERT INTO customers VALUES(?,?)')->execute([1,'Pavel']);
$sqlDb->prepare('INSERT INTO projects VALUES(?,?)')->execute(['anytour',1]);
$sqlDb->prepare('INSERT INTO customer_channels VALUES(?,?,?,?,?,?)')->execute([1,1,'anytour','max','-991002','991002']);
$sqlDb->prepare('INSERT INTO conversations VALUES(?,?,?,?,?,?)')->execute([2,1,1,'anytour','max','-991002']);
$insert=$sqlDb->prepare('INSERT INTO messages VALUES(?,?,?,?,?,?,?,?)');
foreach ([
    [1,2,'max','inbound','customer','991002','mid.eligible_owner','{"type":"message"}'],
    [2,2,'max','inbound','customer','991002','mid.callback','{"type":"callback"}'],
    [3,2,'max','inbound','customer','991003','mid.foreign_sender','{"type":"message"}'],
    [4,2,'max','inbound','customer','991002','mid.invalid_json','{'],
    [5,3,'max','inbound','customer','991002','mid.other_conversation','{"type":"message"}'],
    [6,2,'telegram','inbound','customer','991002','mid.other_channel','{"type":"message"}'],
    [7,2,'max','outbound','manager','991002','mid.outbound','{"type":"message"}'],
    [8,2,'max','inbound','customer','991002','','{"type":"message"}'],
] as $row) $insert->execute($row);
$sqlCalls=[];
$sqlExchange=static function(string $url,array $options) use (&$sqlCalls): array {
    $sqlCalls[]=$url;
    $body=count($sqlCalls)===1
        ? ['body'=>['mid'=>'mid.eligible_owner'],'sender'=>['user_id'=>991002],'recipient'=>['chat_id'=>-991005,'chat_type'=>'dialog','user_id'=>991099]]
        : ['chat_id'=>-991005,'type'=>'dialog','dialog_with_user'=>['user_id'=>991002]];
    ($options[CURLOPT_WRITEFUNCTION])(null,json_encode($body,JSON_THROW_ON_ERROR));
    return ['http_status'=>200,'transport_error'=>false,'ssl_verify_result'=>0];
};
$sqlResult=probeRun(new MaxPrivateAvatarProbe($sqlDb,$sqlExchange));
avatarProbeCheck('actual SQL selects older owner text behind callbacks foreign rows and invalid JSON',$sqlResult['ok']&&$sqlCalls===['https://platform-api2.max.ru/messages/mid.eligible_owner','https://platform-api2.max.ru/chats/-991005']&&probeRedacted($sqlResult));
avatarProbeCheck('actual SQL transaction is released before provider lookup',!$sqlDb->inTransaction());
$writeBlocked=false;try {$sqlDb->exec('UPDATE customers SET display_name="changed"');} catch (PDOException $error) {$writeBlocked=true;}
avatarProbeCheck('actual SQL fixture enforces read-only database operation',$writeBlocked);

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
