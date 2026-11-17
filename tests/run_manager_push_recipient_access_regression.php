<?php
declare(strict_types=1);

require_once __DIR__.'/fixtures/manager_regional_routing_fixture.php';
require_once dirname(__DIR__).'/services/ManagerPushService.php';

$passed=0;$failed=0;$requests=[];$beforeProvider=null;
function pushAccessCheck(string$name,bool$ok):void
{
    global$passed,$failed;if($ok){++$passed;echo 'PASS '.$name."\n";}else{++$failed;echo 'FAIL '.$name."\n";}
}
final class PushAccessPDO extends PDO
{
    public array$reads=[];
    public function prepare(string$query,array$options=[]):PDOStatement|false{$this->reads[]=$query;return parent::prepare($query,$options);}
    public function query(string$query,?int$fetchMode=null,mixed...$fetchModeArgs):PDOStatement|false{$this->reads[]=$query;return$fetchMode===null?parent::query($query):parent::query($query,$fetchMode,...$fetchModeArgs);}
}

// Execute current production method bodies with real SQL/crypto; intercept only
// provider I/O in a namespace so PHP's native curl can never contact an endpoint.
$directory=sys_get_temp_dir().'/push-recipient-'.bin2hex(random_bytes(8));mkdir($directory,0700);mkdir($directory.'/services',0700);
$config=__DIR__.'/fixtures/provision-openssl.cnf.txt';
if(!is_file($config)){
    $config=$directory.'/openssl.cnf';file_put_contents($config,"[req]\ndefault_bits=3072\ndistinguished_name=synthetic_dn\nprompt=no\n[synthetic_dn]\nCN=synthetic.invalid\n");
}
$GLOBALS['push_access_config']=$config;

// Deliberately public synthetic keys: scalar43 from the reviewed reproduction
// and RFC8291 section5 sender. Never production credentials. Signing and ECDH
// use different keys, as required by RFC8292 section3.2.
$fixedShortPem=<<<'SHORT_KEY'
-----BEGIN PRIVATE KEY-----
MIGHAgEAMBMGByqGSM49AgEGCCqGSM49AwEHBG0wawIBAQQgAAAAAAAAAAAAAAAA
AAAAAAAAAAAAAAAAAAAAAAAAACuhRANCAASYauJQbx/xBNBCMIYdj0tJj0vExtAJ
sw91RNwSm4LSjQA8zMCmRg4K4yik2X08e2HYb8YonBifJSURDEQbsH6X
-----END PRIVATE KEY-----
SHORT_KEY;
$fixedNormalPem=<<<'NORMAL_KEY'
-----BEGIN PRIVATE KEY-----
MIGHAgEAMBMGByqGSM49AgEGCCqGSM49AwEHBG0wawIBAQQgyfWPiYE+n46HLnH0
KqZOF1fJJU3MYrct3AELtAQ+oRyhRANCAAT+M/SrDepxkU21WCP3O1SUj0EwbZIH
Mtu5pZpTKGSCIA5Zent7wmC6HCJ5mFgJkuk5cwAvMBKiiujwa7t45ewP
-----END PRIVATE KEY-----
NORMAL_KEY;
$GLOBALS['push_access_key_queue']=[];
$GLOBALS['push_access_default_key']=$fixedShortPem;
$wireRequests=[];

eval(<<<'FIXTURE'
namespace PushRecipientFixture;
use \ConversationDb;use \RoutingAccessService;use \ManagerPriorityService;
use \ManagerPushConnectionService;use \ManagerPushPresentation;use \ManagerPushContext;
use \RuntimeException;use \Throwable;
function openssl_pkey_new(array$options){
    if(($options['private_key_type']??null)!==OPENSSL_KEYTYPE_EC||($options['curve_name']??null)!=='prime256v1')
        throw new RuntimeException('unexpected_fixture_key_request');
    $pem=$GLOBALS['push_access_key_queue']!==[]?array_shift($GLOBALS['push_access_key_queue']):$GLOBALS['push_access_default_key'];
    $key=\openssl_pkey_get_private($pem);
    if($key===false)throw new RuntimeException('invalid_public_fixture_key');
    return$key;
}
function openssl_pkey_export($key,&$output,?string$passphrase=null,array$options=[]):bool{return \openssl_pkey_export($key,$output,$passphrase,$options+['config'=>$GLOBALS['push_access_config']]);}
function openssl_sign(string$data,&$signature,$key,$algorithm):bool{
    if($GLOBALS['beforeProvider']!==null){$callback=$GLOBALS['beforeProvider'];$GLOBALS['beforeProvider']=null;$callback();}
    return \openssl_sign($data,$signature,$key,$algorithm);
}
function curl_init(string$endpoint){return$endpoint;}
function curl_setopt_array($handle,array$options):bool{$GLOBALS['wireRequests'][$handle]=$options;return true;}
function curl_exec($handle){$GLOBALS['requests'][]=$handle;return '';}
function curl_getinfo($handle,int$option){return 201;}
function curl_error($handle):string{return '';}
function curl_close($handle):void{}
FIXTURE);
$source=(string)file_get_contents(dirname(__DIR__).'/services/ManagerPushService.php');
$source=preg_replace('/^require_once[^\n]+\n/m','',substr($source,5));
$source=str_replace('__DIR__',var_export($directory.'/services',true),$source);
eval('namespace PushRecipientFixture;use \\ConversationDb;use \\RoutingAccessService;use \\ManagerPriorityService;use \\ManagerPushConnectionService;use \\ManagerPushPresentation;use \\ManagerPushContext;use \\RuntimeException;use \\Throwable;use \\PDO;'.$source);
$control=(string)file_get_contents(dirname(__DIR__).'/services/ConversationControlService.php');
$control=preg_replace('/^require_once[^\n]+\n/m','',substr($control,5));
eval('namespace PushRecipientFixture;use \\ConversationDb;use \\ConversationRecordClock;use \\ProjectConfig;use \\MetrikaConversionGoalService;use \\Throwable;'.$control);
foreach(['CONVERSATION_DB_HOST','CONVERSATION_DB_NAME','CONVERSATION_DB_USER','CONVERSATION_DB_PASS']as$name)if(!defined($name))define($name,'synthetic-fixture-only');

try{
    $pdo=new PushAccessPDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    regionalRoutingSchema($pdo);regionalRoutingBind($pdo);regionalRoutingInstallScopes($pdo);
    $pdo->exec('CREATE TABLE manager_priority_rules(id INTEGER PRIMARY KEY,manager_id INTEGER,rule_type TEXT,rule_value TEXT,bonus INTEGER,is_active INTEGER,comment TEXT);
        CREATE TABLE manager_push_installations(id INTEGER PRIMARY KEY,manager_id INTEGER,revision INTEGER,enabled INTEGER);
        CREATE TABLE manager_push_subscriptions(id INTEGER PRIMARY KEY,manager_id INTEGER,installation_id INTEGER,revision INTEGER,intent_revision INTEGER,is_active INTEGER,endpoint TEXT,p256dh TEXT,auth_secret TEXT,last_success_at TEXT,last_error_at TEXT,last_error TEXT)');
    // A public-only synthetic P-256 point whose X starts with zero. OpenSSL
    // exposes that coordinate as 31 bytes; Web Push still requires all 32.
    $client=openssl_pkey_get_public(<<<'PUBLIC_KEY'
-----BEGIN PUBLIC KEY-----
MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEAB/HGJkkYMDJVccBDvmq6edwSsVM
3WWvn7iBGAJBx30EhmZEIZPMrWmDYce/LeHB2q32MDCq3zVynFaAAmS1YQ==
-----END PUBLIC KEY-----
PUBLIC_KEY);
    $details=openssl_pkey_get_details($client);
    $public="\x04".str_pad($details['ec']['x'],32,"\0",STR_PAD_LEFT).str_pad($details['ec']['y'],32,"\0",STR_PAD_LEFT);
    pushAccessCheck('Synthetic subscription retains the leading zero of its valid P-256 point',
        strlen($details['ec']['x'])===31&&bin2hex($public)==='04001fc718992460c0c955c7010ef9aae9e7704ac54cdd65af9fb881180241c77d048666442193ccad698361c7bf2de1c1daadf63030aadf35729c56800264b561');
    $encode=static fn(string$v):string=>rtrim(strtr(base64_encode($v),'+/','-_'),'=');
    $insert=$pdo->prepare('INSERT INTO manager_push_subscriptions(id,manager_id,installation_id,revision,intent_revision,is_active,endpoint,p256dh,auth_secret) VALUES(?,?,?,1,1,1,?,?,?)');
    foreach([7,8,9]as$id){$pdo->exec('INSERT INTO manager_push_installations VALUES('.$id.','.$id.',1,1)');$insert->execute([$id,$id,$id,'https://synthetic-push.invalid/'.$id,$encode($public),$encode(str_repeat('a',16))]);}
    $assigned=regionalRoutingConversation($pdo,101,'62',['status'=>'manager','manager_id'=>7]);
    $waiting=regionalRoutingConversation($pdo,102,'62');
    regionalRoutingConversation($pdo,103,'11131',['status'=>'closed','manager_id'=>7]);
    regionalRoutingConversation($pdo,104,'62',['status'=>'ai']);
    $notify=static function(int$id=101)use(&$requests):array{$requests=[];\PushRecipientFixture\ManagerPushService::notifyConversation($id,'Synthetic notice');return$requests;};
    $target=static function(int$id=101,int$manager=7)use(&$requests):array{$requests=[];$result=\PushRecipientFixture\ManagerPushService::notifyManager($manager,$id,'Synthetic task','Synthetic reminder');return[$requests,$result];};
    $expected=static fn(int$id):array=>ManagerPriorityService::notificationSelectionForConversation($id)['selected_manager_ids'];

    // Both valid private keys and all SQL/crypto are real; only key selection
    // and provider I/O use the existing namespace seam.
    $shortKey=openssl_pkey_get_private($fixedShortPem);$normalKey=openssl_pkey_get_private($fixedNormalPem);
    $shortDetails=openssl_pkey_get_details($shortKey);$normalDetails=openssl_pkey_get_details($normalKey);
    $shortPoint=hex2bin('04986ae2506f1ff104d04230861d8f4b498f4bc4c6d009b30f7544dc129b82d28d003cccc0a6460e0ae328a4d97d3c7b61d86fc6289c189f2525110c441bb07e97');$normalPoint=hex2bin('04fe33f4ab0dea71914db55823f73b54948f41306d920732dbb9a59a53286482200e597a7b7bc260ba1c227998580992e93973002f3012a28ae8f06bbb78e5ec0f');
    pushAccessCheck('Public deterministic keys retain a valid short Y and a distinct full-width signing peer',
        strlen($shortDetails['ec']['x'])===32&&strlen($shortDetails['ec']['y'])===31
        &&("\x04".str_pad($shortDetails['ec']['x'],32,"\0",STR_PAD_LEFT).str_pad($shortDetails['ec']['y'],32,"\0",STR_PAD_LEFT))===$shortPoint
        &&strlen($normalDetails['ec']['x'])===32&&strlen($normalDetails['ec']['y'])===32
        &&("\x04".$normalDetails['ec']['x'].$normalDetails['ec']['y'])===$normalPoint&&$shortPoint!==$normalPoint);
    $decode=static function(string$value):string{
        $value=strtr($value,'-_','+/');return(string)base64_decode($value.str_repeat('=',(4-strlen($value)%4)%4),true);
    };
    $wirePoint=static function(array$options,string$expectedEcdh,string$expectedVapid)use($decode):bool{
        $body=$options[CURLOPT_POSTFIELDS]??null;$headers=$options[CURLOPT_HTTPHEADER]??[];
        if(!is_string($body)||strlen($body)<86||!is_array($headers)||ord($body[20])!==65)return false;
        $point=substr($body,21,65);$header=implode("\n",$headers);
        if($point!==$expectedEcdh||$point[0]!=="\x04"
            ||preg_match('/Authorization: vapid t=[^,\s]+, k=([A-Za-z0-9_-]+)/',$header,$m)!==1
            ||$decode($m[1])!==$expectedVapid||$expectedEcdh===$expectedVapid)return false;
        $pem="-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(hex2bin('3059301306072A8648CE3D020106082A8648CE3D030107034200').$point),64,"\n")."-----END PUBLIC KEY-----\n";
        return openssl_pkey_get_public($pem)!==false;
    };
    $requests=[];$wireRequests=[];$GLOBALS['push_access_key_queue']=[$fixedShortPem];
    $fixedConfig=null;$fixedFailure=null;
    try{$fixedConfig=\PushRecipientFixture\ManagerPushService::vapid();}
    catch(Throwable$error){$fixedFailure=$error->getMessage();}
    echo 'Fixed short-coordinate VAPID generation: '.($fixedFailure===null?'success':($fixedFailure==='invalid_vapid_key'?'invalid_vapid_key':'unexpected_failure'))."\n";
    pushAccessCheck('Fresh valid short-coordinate VAPID generation retains its exact 65-byte public point without a provider call',
        $fixedFailure===null&&is_array($fixedConfig)&&$decode($fixedConfig['public_key'])===$shortPoint&&$requests===[]);
    $GLOBALS['push_access_key_queue']=[$fixedNormalPem];
    $freshRequest=$fixedConfig!==null?$notify():[];
    pushAccessCheck('Fresh short-coordinate VAPID signs an intercepted message using a distinct full-width ECDH key',
        $freshRequest===['https://synthetic-push.invalid/7']
        &&$wirePoint($wireRequests['https://synthetic-push.invalid/7']??[],$normalPoint,$shortPoint));
    // Seed an existing, different, public fixture configuration. Its exact
    // bytes must survive the ephemeral short-coordinate path and all ACL tests.
    $fixedPath=$directory.'/.runtime/manager_push_vapid.php';
    if(!is_dir(dirname($fixedPath)))mkdir(dirname($fixedPath),0700,true);
    $savedConfig=['private_pem'=>$fixedNormalPem,'public_key'=>$encode($normalPoint),'subject'=>'mailto:synthetic@invalid.example'];
    $savedConfigBytes="<?php\nreturn ".var_export($savedConfig,true).";\n";
    file_put_contents($fixedPath,$savedConfigBytes);
    if(function_exists('opcache_invalidate'))opcache_invalidate($fixedPath,true);
    $requests=[];$wireRequests=[];$GLOBALS['push_access_key_queue']=[$fixedShortPem];
    $existingRequest=$notify();
    pushAccessCheck('An existing VAPID key is unchanged while a valid short-coordinate ephemeral key keeps its full SEC1 wire point',
        $existingRequest===['https://synthetic-push.invalid/7']
        &&$wirePoint($wireRequests['https://synthetic-push.invalid/7']??[],$shortPoint,$normalPoint)
        &&file_get_contents($fixedPath)===$savedConfigBytes
        &&\PushRecipientFixture\ManagerPushService::vapid()===$savedConfig);
    $GLOBALS['push_access_key_queue']=[];
    pushAccessCheck('Active assigned manager remains canonical in singleton batch diagnostics and push',RoutingAccessService::canSeeConversation(7,$assigned)&&count(RoutingAccessService::visibleConversations(7,[$assigned]))===1&&$expected(101)===[7]&&$notify()===['https://synthetic-push.invalid/7']);
    $pdo->exec('UPDATE managers SET is_active=0 WHERE id=7');
    pushAccessCheck('Disabled assigned account cannot pass singleton receiving or batch ACL',!RoutingAccessService::canSeeConversation(7,$assigned)&&!RoutingAccessService::canReceiveConversation(7,$assigned)&&RoutingAccessService::visibleConversations(7,[$assigned])===[]);
    pushAccessCheck('Disabled owner is absent from diagnostics and actual conversation push',$expected(101)===[]&&$notify()===[]);
    pushAccessCheck('Targeted reminder cannot bypass a disabled account',$target()[0]===[]);
    $pdo->exec('UPDATE managers SET is_active=1 WHERE id=7;DELETE FROM manager_projects WHERE manager_id=7');
    pushAccessCheck('Revoked project excludes an assigned owner from diagnostic selection and push',$expected(101)===[]&&$notify()===[]&&$target()[0]===[]);
    $pdo->exec('INSERT INTO manager_projects VALUES(7,1);UPDATE managers SET is_working=0 WHERE id=7');
    pushAccessCheck('Own assigned notifications remain available off shift',$expected(101)===[7]&&$notify()===['https://synthetic-push.invalid/7']);
    $closed=$target(103);pushAccessCheck('An accessible own closed task still notifies its off-shift manager',$closed[0]===['https://synthetic-push.invalid/7']&&$closed[1]['delivered']===1);
    pushAccessCheck('Off-shift waiting stays visible and eligible for reminders while new-queue push stays off',RoutingAccessService::canSeeConversation(7,$waiting)&&$target(102)[0]===['https://synthetic-push.invalid/7']&&!in_array('https://synthetic-push.invalid/7',$notify(102),true));
    pushAccessCheck('An accessible AI unassigned sales task remains eligible off shift',$target(104)[0]===['https://synthetic-push.invalid/7']);
    $pdo->exec('UPDATE conversations SET source_id=2 WHERE id=101;DELETE FROM manager_group_members WHERE manager_id=7 AND group_id=10');
    pushAccessCheck('Own assignment and reminder purpose cannot bypass revoked source membership',$expected(101)===[]&&$notify()===[]&&$target()[0]===[]);
    $pdo->exec('UPDATE conversations SET source_id=1 WHERE id=101;INSERT INTO manager_group_members VALUES(10,7)');
    $pdo->exec('UPDATE managers SET is_working=1 WHERE id=7');
    $requests=[];$created=false;$transactionAtProvider=null;
    $beforeProvider=static function()use($pdo,&$transactionAtProvider):void{$transactionAtProvider=$pdo->inTransaction();};
    $queued=\PushRecipientFixture\ConversationControlService::markWaitingForProject('anytour','max','synthetic-104',[],$created);
    pushAccessCheck('Canonical own-transaction handoff commits its queue and event before notifying',$queued&&$created&&!$pdo->inTransaction()&&$transactionAtProvider===false&&in_array('https://synthetic-push.invalid/7',$requests,true)&&(int)$pdo->query("SELECT COUNT(*) FROM conversation_events WHERE conversation_id=104 AND event_type='waiting_manager'")->fetchColumn()===1);
    regionalRoutingConversation($pdo,105,'62',['status'=>'ai']);$requests=[];$created=false;$pdo->beginTransaction();
    $queued=\PushRecipientFixture\ConversationControlService::markWaitingForProject('anytour','max','synthetic-105',[],$created,false);
    $noEarlyProvider=$requests===[]&&$pdo->inTransaction();$pdo->commit();$afterCommit=$notify(105);
    pushAccessCheck('Explicit outer-transaction handoff retains postcommit notification without early provider work',$queued&&$created&&$noEarlyProvider&&in_array('https://synthetic-push.invalid/7',$afterCommit,true));
    foreach([
        'project revocation'=>static fn()=>$pdo->exec('DELETE FROM manager_projects WHERE manager_id=7'),
        'account deactivation'=>static fn()=>$pdo->exec('UPDATE managers SET is_active=0 WHERE id=7'),
        'reassignment'=>static fn()=>$pdo->exec('UPDATE conversations SET manager_id=9 WHERE id=101'),
        'conversation closure'=>static fn()=>$pdo->exec("UPDATE conversations SET status='closed' WHERE id=101"),
    ]as$name=>$change){
        $beforeProvider=$change;pushAccessCheck('Fresh pre-provider conversation notification blocks '.$name,$notify()===[]);
        $pdo->exec("UPDATE managers SET is_active=1 WHERE id=7;INSERT OR IGNORE INTO manager_projects VALUES(7,1);UPDATE conversations SET status='manager',manager_id=7 WHERE id=101");
        $beforeProvider=$change;$actual=$target()[0];pushAccessCheck('Fresh pre-provider targeted reminder '.($name==='conversation closure'?'retains a status-only change':'blocks '.$name),$actual===($name==='conversation closure'?['https://synthetic-push.invalid/7']:[]));
        $pdo->exec("UPDATE managers SET is_active=1 WHERE id=7;INSERT OR IGNORE INTO manager_projects VALUES(7,1);UPDATE conversations SET status='manager',manager_id=7 WHERE id=101");
    }
    $pdo->exec('UPDATE managers SET priority=100 WHERE id=7');
    $beforeProvider=static fn()=>$pdo->exec('DELETE FROM manager_group_members WHERE manager_id=7 AND group_id=10');
    pushAccessCheck('Waiting fanout rechecks the regional scope after initial priority selection',$notify(102)===[]);
    $pdo->exec('INSERT INTO manager_group_members VALUES(10,7)');
    $beforeProvider=static fn()=>$pdo->exec('DELETE FROM manager_group_territories WHERE group_id=10');
    pushAccessCheck('Targeted waiting reminder cannot use a scope revoked before provider dispatch',$target(102)[0]===[]);
    $pdo->exec('DELETE FROM managers WHERE id=7');
    pushAccessCheck('Deleted account with orphan grants never becomes an unrestricted fallback',!RoutingAccessService::canSeeConversation(7,$assigned)&&RoutingAccessService::visibleConversations(7,[$assigned])===[]&&$expected(101)===[]&&$notify()===[]);
    $pdo->beginTransaction();$pdo->reads=[];$a=$notify(102);$b=$target(102,9);
    pushAccessCheck('Unsupported ambient transaction performs no payload subscription or provider reads and remains caller-owned',$a===[]&&$b[0]===[]&&$pdo->reads===[]&&$pdo->inTransaction());$pdo->rollBack();
    $pdo->exec('PRAGMA query_only=ON');$pdo->beginTransaction();$pdo->reads=[];$a=$notify(102);$b=$target(102,9);
    pushAccessCheck('Read-only transaction never sends or annotates a subscription',$a===[]&&$b[0]===[]&&$pdo->reads===[]&&$pdo->inTransaction());$pdo->rollBack();$pdo->exec('PRAGMA query_only=OFF');
}finally{
    $remove=static function(string$path)use(&$remove):void{if(is_file($path)||is_link($path)){unlink($path);return;}foreach(scandir($path)as$name)if($name!=='.'&&$name!=='..')$remove($path.'/'.$name);rmdir($path);};$remove($directory);
}
echo 'Push recipient access: '.$passed.' passed, '.$failed." failed; provider requests intercepted locally\n";
exit($failed>0?1:0);
