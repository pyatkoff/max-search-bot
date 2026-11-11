<?php
declare(strict_types=1);
define('REGIONAL_MANAGER_PROVISION_LIBRARY_ONLY',true);
require dirname(__DIR__).'/tools/provision_regional_managers.php';
$key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>3072,'config'=>__DIR__.'/fixtures/provision-openssl.cnf.txt']);
if(!$key||!openssl_pkey_export($key,$recipientPrivate,null,['config'=>__DIR__.'/fixtures/provision-openssl.cnf.txt']))throw new RuntimeException('Synthetic crypto unavailable');
$recipientPublic=openssl_pkey_get_details($key)['key'];$checks=0;$directories=[];
function provisionCheck(string$label,bool$ok):void{global$checks;if(!$ok)throw new RuntimeException('FAIL '.$label);++$checks;echo 'PASS '.$label."\n";}
function provisionRefused(callable$action,string$expected):void
{
    try{$action();}catch(RegionalManagerProvisionFailure$error){provisionCheck('Refused '.$expected,$error->getMessage()===$expected);return;}
    throw new RuntimeException('Missing refusal '.$expected);
}
final class SyntheticProvisionDirectory
{
    public int $revision=0;
    public string $failure='';
    public bool $commonScoped=false;
    public array $writes=[];
    public array $managers=[];
    public array $groups=[];
    public array $common=[5,6];
    public function __invoke(string$action,array$input):array
    {
        if($action==='preview')return['actor_id'=>2,'project'=>['id'=>1,'project_key'=>'anytour'],'revision'=>$this->revision];
        if($action==='validate_plan')return['scope_receipts'=>[],'common_group'=>['member_ids'=>[5,6]],'common_group_scope_empty'=>!$this->commonScoped,'sources_sha256'=>str_repeat('a',64)];
        if($action==='readback')return['complete'=>count($this->managers)===2&&count($this->groups)===2&&count($this->common)===4,
            'manager_count'=>count($this->managers),'group_count'=>count($this->groups)];
        $this->writes[]=$action;
        if($action==='create_manager'){
            $id=100+count($this->managers);$this->managers[$id]=['login'=>$input['target']['login'],'hash'=>password_hash($input['password'],PASSWORD_DEFAULT)];
            return['ok'=>true,'manager_id'=>$id];
        }
        if($action==='create_group'){
            if($this->failure==='second_group'&&count($this->groups)===1)throw new RuntimeException('Synthetic uncertain boundary');
            $id=200+count($this->groups);$this->groups[$id]=$input;return['ok'=>true,'group_id'=>$id];
        }
        if($action==='append_common'){$this->common=array_values(array_unique([...$this->common,...$input['manager_ids']]));return['ok'=>true,'group_id'=>1];}
        throw new RuntimeException('Unexpected synthetic operation');
    }
}
function provisionFixture(string$public,int&$now,bool&$sourceAllowed,bool&$actorAllowed):array
{
    global$directories;$dir=sys_get_temp_dir().'/regional-provision-'.bin2hex(random_bytes(8));mkdir($dir,0700);
    $directories[]=$dir;$config=$dir.'/config.php';file_put_contents($config,"synthetic configuration\n");chmod($config,0600);
    $directory=new SyntheticProvisionDirectory();
    $operator=new RegionalManagerProvision($config,$public,
        static function(string$sha)use(&$sourceAllowed):bool{return$sourceAllowed&&$sha===str_repeat('a',40);},
        static function(bool$write)use(&$actorAllowed):void{RegionalManagerProvision::check(!$write||$actorAllowed,'ACTOR_UNAVAILABLE');},
        $directory,static function()use(&$now):int{return$now;},
        static fn()=>openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>3072,'config'=>__DIR__.'/fixtures/provision-openssl.cnf.txt']),
        ['config'=>__DIR__.'/fixtures/provision-openssl.cnf.txt'],static fn()=>fileowner($config));
    return[$operator,$directory,$config];
}
function provisionPlan(array$prepare):array
{
    return['contract'=>RegionalManagerProvision::CONTRACT,'request_id'=>$prepare['request_id'],'application_sha'=>$prepare['application_sha'],
        'expires_at'=>$prepare['expires_at'],'preview_digest'=>$prepare['preview_digest'],'actor_id'=>2,'project_id'=>1,'project_key'=>'anytour',
        'targets'=>[
            ['territory_region_id'=>'62','login'=>'synthetic.region.a','display_name'=>'Synthetic staff A','group_key'=>'synthetic-region-a','group_name'=>'Synthetic territory A'],
            ['territory_region_id'=>'11131','login'=>'synthetic.region.b','display_name'=>'Synthetic staff B','group_key'=>'synthetic-region-b','group_name'=>'Synthetic territory B'],
        ]];
}
function provisionEnvelope(array$plan,array$prepare):string
{
    return json_encode(RegionalManagerProvision::seal($plan,$prepare['server_public_key_pem'],RegionalManagerProvision::CONTRACT.':apply:'.$prepare['request_id']),JSON_THROW_ON_ERROR);
}
try{
    $now=1800000000;$sourceAllowed=true;$actorAllowed=true;$sha=str_repeat('a',40);
    [$operator,$directory,$config]=provisionFixture($recipientPublic,$now,$sourceAllowed,$actorAllowed);
    $absent=RegionalManagerProvision::open($operator->status($sha),$recipientPrivate,RegionalManagerProvision::CONTRACT);
    provisionCheck('Absent status is read-only and creates no private operation files',$absent['phase']==='absent'&&glob($config.'.regional-managers-v1-*')===[]);
    $packet=$operator->prepare($sha);$prepare=RegionalManagerProvision::open($packet,$recipientPrivate,RegionalManagerProvision::CONTRACT);
    provisionCheck('Prepared request binds source actor project and a thirty-minute maximum',$prepare['actor_id']===2&&$prepare['project_id']===1&&$prepare['expires_at']-$prepare['created_at']===1800);
    $privatePath=$config.'.regional-managers-v1-private.pem';$statePath=$config.'.regional-managers-v1-state.json';
    provisionCheck('Ephemeral server key and durable state have owner-only permissions',(fileperms($privatePath)&0777)===0600&&(fileperms($statePath)&0777)===0600);
    $keyHash=hash_file('sha256',$privatePath);
    provisionCheck('Repeated prepare reuses the original request and makes no database call',$operator->prepare($sha)===$packet&&hash_file('sha256',$privatePath)===$keyHash&&$directory->writes===[]);
    $plan=provisionPlan($prepare);$envelope=provisionEnvelope($plan,$prepare);
    $directory->revision=1;provisionRefused(static fn()=>$operator->apply($sha,$envelope),'PREVIEW_CHANGED');$directory->revision=0;
    $sourceAllowed=false;provisionRefused(static fn()=>$operator->apply($sha,$envelope),'APPLICATION_SHA_MISMATCH');$sourceAllowed=true;
    $actorAllowed=false;provisionRefused(static fn()=>$operator->apply($sha,$envelope),'ACTOR_UNAVAILABLE');$actorAllowed=true;
    $directory->commonScoped=true;provisionRefused(static fn()=>$operator->apply($sha,$envelope),'COMMON_GROUP_SCOPED');$directory->commonScoped=false;
    $now+=1801;provisionRefused(static fn()=>$operator->apply($sha,$envelope),'REQUEST_EXPIRED');$now-=1801;
    $badPlan=$plan;$badPlan['targets'][0]['territory_region_id']='225';$bad=provisionEnvelope($badPlan,$prepare);
    provisionRefused(static fn()=>$operator->apply($sha,$bad),'PLAN_INVALID');
    $badPlan=$plan;$badPlan['actor_id']=90;$bad=provisionEnvelope($badPlan,$prepare);
    provisionRefused(static fn()=>$operator->apply($sha,$bad),'PLAN_INVALID');
    $tampered=json_decode($envelope,true,8,JSON_THROW_ON_ERROR);$raw=base64_decode($tampered['ciphertext']);$raw[0]=chr(ord($raw[0])^1);$tampered['ciphertext']=base64_encode($raw);
    $bad=json_encode($tampered,JSON_THROW_ON_ERROR);provisionRefused(static fn()=>$operator->apply($sha,$bad),'DECRYPTION_FAILED');
    provisionCheck('Every rejected plan leaves the request prepared and the database untouched',$directory->writes===[]&&json_decode(file_get_contents($statePath),true)['phase']==='prepared');
    $resultPacket=$operator->apply($sha,$envelope);$result=RegionalManagerProvision::open($resultPacket,$recipientPrivate,RegionalManagerProvision::CONTRACT);
    provisionCheck('The fixed plan creates exactly two accounts two groups and one append',$directory->writes===['create_manager','create_group','create_manager','create_group','append_common']&&$result['phase']==='configured'&&$result['readback']['complete']===true);
    $passwords=array_column($result['credentials'],'password');
    provisionCheck('Both initial passwords are independent server-generated 256-bit URL-safe values',count(array_unique($passwords))===2&&array_reduce($passwords,static fn(bool$ok,string$p):bool=>$ok&&preg_match('/\A[A-Za-z0-9_-]{43}\z/D',$p)===1,true));
    foreach($result['credentials']as$index=>$credential)provisionCheck('Only the recipient can recover the exact password used for its account '.($index+1),password_verify($credential['password'],$directory->managers[100+$index]['hash']));
    $serialized=json_encode($resultPacket,JSON_THROW_ON_ERROR);$stateRaw=file_get_contents($statePath);
    provisionCheck('No plaintext password login or staff name appears in output or private operation state',!str_contains($serialized,'synthetic.region.')&&!str_contains($stateRaw,'Synthetic staff')&&!str_contains($stateRaw,'synthetic.region.')&&array_reduce($passwords,static fn(bool$ok,string$p):bool=>$ok&&!str_contains($serialized,$p)&&!str_contains($stateRaw,$p),true));
    provisionCheck('Configured result removes its ephemeral server private key',!file_exists($privatePath));
    $writes=$directory->writes;
    provisionCheck('Exact configured replay returns the original encrypted result without resetting accounts',$operator->apply($sha,$envelope)===$resultPacket&&$directory->writes===$writes);
    $different=provisionEnvelope($plan,$prepare);provisionRefused(static fn()=>$operator->apply($sha,$different),'REQUEST_BINDING_MISMATCH');
    $before=hash_file('sha256',$statePath);$status=RegionalManagerProvision::open($operator->status($sha),$recipientPrivate,RegionalManagerProvision::CONTRACT);
    provisionCheck('Status rechecks the saved result without any database or state mutation',$status['phase']==='configured'&&$status['readback']['complete']===true&&hash_file('sha256',$statePath)===$before&&$directory->writes===$writes);

    [$unknown,$uncertain,$unknownConfig]=provisionFixture($recipientPublic,$now,$sourceAllowed,$actorAllowed);
    $unknownPrepare=RegionalManagerProvision::open($unknown->prepare($sha),$recipientPrivate,RegionalManagerProvision::CONTRACT);
    $unknownEnvelope=provisionEnvelope(provisionPlan($unknownPrepare),$unknownPrepare);$uncertain->failure='second_group';
    try{$unknown->apply($sha,$unknownEnvelope);throw new RuntimeException('Expected synthetic failure');}catch(RuntimeException$error){if($error->getMessage()!=='Synthetic uncertain boundary')throw$error;}
    $unknownPath=$unknownConfig.'.regional-managers-v1-state.json';$unknownRaw=file_get_contents($unknownPath);$saved=json_decode($unknownRaw,true,32,JSON_THROW_ON_ERROR);
    provisionCheck('A partial or uncertain write retains the original applying fence and encrypted recovery',$saved['phase']==='applying'&&isset($saved['credentials_packet'])&&file_exists($unknownConfig.'.regional-managers-v1-private.pem'));
    $writes=$uncertain->writes;provisionRefused(static fn()=>$unknown->apply($sha,$unknownEnvelope),'UNKNOWN_WRITE_HELD');
    provisionRefused(static fn()=>$unknown->prepare($sha),'UNKNOWN_WRITE_HELD');
    $status=RegionalManagerProvision::open($unknown->status($sha),$recipientPrivate,RegionalManagerProvision::CONTRACT);
    $recovery=RegionalManagerProvision::open($status['credentials_packet'],$recipientPrivate,RegionalManagerProvision::CONTRACT);
    provisionCheck('Unknown status returns the original recoverable credentials but cannot retry a write',$status['write_outcome']==='unknown'&&count($recovery['credentials'])===2&&$uncertain->writes===$writes&&file_get_contents($unknownPath)===$unknownRaw);
    chmod($unknownPath,0644);provisionRefused(static fn()=>$unknown->status($sha),'PRIVATE_STATE_INVALID');chmod($unknownPath,0600);
    unlink($unknownPath);provisionRefused(static fn()=>$unknown->status($sha),'PRIVATE_STATE_INVALID');
    file_put_contents($unknownPath,$unknownRaw);chmod($unknownPath,0600);

    [$unsafe,$unused,$unsafeConfig]=provisionFixture($recipientPublic,$now,$sourceAllowed,$actorAllowed);
    $decoy=dirname($unsafeConfig).'/decoy';file_put_contents($decoy,'synthetic');chmod($decoy,0600);
    symlink($decoy,$unsafeConfig.'.regional-managers-v1-lock');
    provisionRefused(static fn()=>$unsafe->prepare($sha),'PRIVATE_STATE_INVALID');
    provisionCheck('A rejected private-path symlink cannot reach a directory write',$unused->writes===[]);
    unlink($unsafeConfig.'.regional-managers-v1-lock');
    symlink(dirname($unsafeConfig).'/missing-state',$unsafeConfig.'.regional-managers-v1-state.json');
    provisionRefused(static fn()=>$unsafe->status($sha),'PRIVATE_STATE_INVALID');
    unlink($unsafeConfig.'.regional-managers-v1-state.json');
    symlink(dirname($unsafeConfig).'/missing-key',$unsafeConfig.'.regional-managers-v1-private.pem');
    provisionRefused(static fn()=>$unsafe->status($sha),'PRIVATE_STATE_INVALID');
}finally{
    foreach($directories as$dir){foreach(glob($dir.'/*')?:[]as$file){if(is_file($file)||is_link($file))unlink($file);}rmdir($dir);}
}
echo 'Regional manager provisioning operator: '.$checks." synthetic checks passed; no server/DB access\n";
