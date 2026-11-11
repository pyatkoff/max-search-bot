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
eval(<<<'FIXTURE'
namespace PushRecipientFixture;
use \ConversationDb;use \RoutingAccessService;use \ManagerPriorityService;
use \ManagerPushConnectionService;use \ManagerPushPresentation;use \ManagerPushContext;
use \RuntimeException;use \Throwable;
function openssl_pkey_new(array$options){return \openssl_pkey_new($options+['config'=>$GLOBALS['push_access_config']]);}
function openssl_pkey_export($key,&$output,?string$passphrase=null,array$options=[]):bool{return \openssl_pkey_export($key,$output,$passphrase,$options+['config'=>$GLOBALS['push_access_config']]);}
function openssl_sign(string$data,&$signature,$key,$algorithm):bool{
    if($GLOBALS['beforeProvider']!==null){$callback=$GLOBALS['beforeProvider'];$GLOBALS['beforeProvider']=null;$callback();}
    return \openssl_sign($data,$signature,$key,$algorithm);
}
function curl_init(string$endpoint){return$endpoint;}
function curl_setopt_array($handle,array$options):bool{return true;}
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
    $client=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1','config'=>$config]);
    $details=openssl_pkey_get_details($client);$public="\x04".$details['ec']['x'].$details['ec']['y'];
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
    pushAccessCheck('A waiting unassigned sales task remains eligible off shift while ordinary queue push stays off',!RoutingAccessService::canSeeConversation(7,$waiting)&&$target(102)[0]===['https://synthetic-push.invalid/7']&&!in_array('https://synthetic-push.invalid/7',$notify(102),true));
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
