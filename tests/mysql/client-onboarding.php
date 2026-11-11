<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
$dsn=getenv('CLIENT_ONBOARDING_TEST_MYSQL_DSN');
if(!$dsn){echo "SKIP native client onboarding fixture is not configured\n";exit;}
if(!preg_match('/\Amysql:host=(127\.0\.0\.1|localhost|mysql);(?:port=[0-9]{1,5};)?dbname=client_onboarding_test;charset=utf8mb4\z/D',$dsn))throw new RuntimeException('Refusing non-ephemeral fixture database');
$user=getenv('CLIENT_ONBOARDING_TEST_MYSQL_USER');$password=getenv('CLIENT_ONBOARDING_TEST_MYSQL_PASSWORD');
if(!$user||!is_string($password))throw new RuntimeException('Explicit fixture credentials required');
require_once dirname(__DIR__).'/fixtures/client_onboarding_fixture.php';
function nativeClientProvider(PDO $pdo): callable
{
    return static function(ProjectMaxConnection $owner,string $method,string $path,array $query,?array $body)use($pdo):array{
        if($pdo->inTransaction())throw new RuntimeException('Lock across synthetic provider');
        if($path==='/me')return ['http'=>200,'data'=>['is_bot'=>true,'username'=>$owner->botKey(),'user_id'=>12345]];
        if($method==='POST'){
            $q=$pdo->prepare('INSERT INTO client_fixture_provider_calls(project_id,url,write_count) SELECT id,?,1 FROM projects WHERE project_key=? ON DUPLICATE KEY UPDATE write_count=write_count+1,url=VALUES(url)');$q->execute([$body['url'],$owner->projectKey()]);usleep(150000);return ['http'=>200,'data'=>['success'=>true]];
        }
        $q=$pdo->prepare('SELECT f.url FROM client_fixture_provider_calls f JOIN projects p ON p.id=f.project_id WHERE p.project_key=?');$q->execute([$owner->projectKey()]);$url=$q->fetchColumn();return ['http'=>200,'data'=>['subscriptions'=>$url?[['url'=>$url,'update_types'=>ProjectMaxWebhookSetup::UPDATE_TYPES]]:[]]];
    };
}
$pdo=new PDO($dsn,$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='client_onboarding_test')throw new RuntimeException('Unexpected schema');
$worker=($argv[1]??'')==='--worker';
if($worker){
    $private=getenv('CLIENT_ONBOARDING_FIXTURE_PRIVATE');
    if(!is_string($private)||!is_dir($private)||$pdo->query("SELECT login FROM managers WHERE id=1")->fetchColumn()!=='platform'||(int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('project_client_connections','project_client_operations') AND engine='InnoDB'")->fetchColumn()!==2)throw new RuntimeException('Not a native synthetic worker');
    $registry=clientFixtureBind($pdo,$private);$service=new ManagerClientOnboardingService($registry,nativeClientProvider($pdo),'clientFixtureProbe');$command=$argv[2]??'';$letter=$argv[3]??'';$actor=(int)($argv[4]??0);$project=(int)($argv[5]??0);
    if(!in_array($command,['create','cancel','activate'],true)||!preg_match('/\A[a-f]\z/D',$letter)||!in_array($actor,[1,3],true)||($command==='activate'&&$project<=0))throw new RuntimeException('Invalid synthetic command');
    echo "READY\n";fflush(STDOUT);if(fgets(STDIN)!=="start\n")throw new RuntimeException('Missing worker barrier');
    $result=match($command){'create'=>$service->create($actor,clientFixtureInput($letter)),'cancel'=>$service->cancel($actor,str_repeat($letter,32)),'activate'=>$service->activate($actor,$project,str_repeat($letter,32))};echo json_encode($result,JSON_THROW_ON_ERROR);exit;
}
foreach(['tables'=>'table_schema','routines'=>'routine_schema','events'=>'event_schema'] as $table=>$column)if((int)$pdo->query('SELECT COUNT(*) FROM information_schema.'.$table.' WHERE '.$column.'=DATABASE()')->fetchColumn()!==0)throw new RuntimeException('Refusing non-empty database');
clientFixtureSchema($pdo);$pdo->exec('CREATE TABLE client_fixture_provider_calls(project_id BIGINT UNSIGNED PRIMARY KEY,url VARCHAR(255),write_count INTEGER NOT NULL) ENGINE=InnoDB');$private=(realpath(sys_get_temp_dir())?:sys_get_temp_dir()).'/client-onboarding-native-'.bin2hex(random_bytes(6));$registry=clientFixtureBind($pdo,$private);$registry->credentials->initialize(true);putenv('CLIENT_ONBOARDING_FIXTURE_PRIVATE='.$private);
$passed=0;
function nativeClientCheck(string $label,bool $ok): void {global$passed;if(!$ok)throw new RuntimeException('FAIL '.$label);$passed++;echo 'PASS '.$label."\n";}
function nativeClientRace(string $letter,array $commands,int $project=0,int $actor=1): array
{
    $workers=[];$pipes=[];$results=[];
    try{
        foreach($commands as $i=>$command){[$action,$ownLetter,$ownActor]=is_array($command)?$command:[$command,$letter,$actor];$workers[$i]=proc_open([PHP_BINARY,__FILE__,'--worker',$action,$ownLetter,(string)$ownActor,(string)$project],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes[$i]);if(!is_resource($workers[$i]))throw new RuntimeException('Cannot start worker');stream_set_timeout($pipes[$i][1],20);}
        foreach($pipes as $stream)if(fgets($stream[1])!=="READY\n")throw new RuntimeException('Worker not ready');
        foreach($pipes as $stream){fwrite($stream[0],"start\n");fclose($stream[0]);}
        foreach($pipes as $i=>$stream){$body=stream_get_contents($stream[1]);$error=stream_get_contents($stream[2]);fclose($stream[1]);fclose($stream[2]);$exit=proc_close($workers[$i]);$workers[$i]=null;if($exit!==0||$error!=='')throw new RuntimeException('Native worker failed '.$error);$results[]=json_decode($body,true,32,JSON_THROW_ON_ERROR);}
    }finally{foreach($workers as $i=>$worker)if(is_resource($worker)){proc_terminate($worker);foreach($pipes[$i]??[] as $stream)if(is_resource($stream))fclose($stream);proc_close($worker);}}
    return $results;
}
try{
    $race=nativeClientRace('a',['create','create']);$service=new ManagerClientOnboardingService($registry);$receipt=$service->receipt(1,str_repeat('a',32));$id=$receipt['project_id'];
    nativeClientCheck('concurrent identical creates commit one company and employee',$race[0]===$race[1]&&$receipt['state']==='created'&&(int)$pdo->query('SELECT COUNT(*) FROM project_client_connections')->fetchColumn()===1&&(int)$pdo->query("SELECT COUNT(*) FROM managers WHERE login='staff_a'")->fetchColumn()===1);
    $race=nativeClientRace('b',['create','cancel']);$receipt=$service->receipt(1,str_repeat('b',32));$count=(int)$pdo->query("SELECT COUNT(*) FROM projects WHERE project_key='client_bbbbbbbbbbbbbbbbbbbbbbbb'")->fetchColumn();
    nativeClientCheck('lost-create cancellation versus creation has one durable winner',($receipt['state']==='created'&&$count===1)||($receipt['state']==='cancelled'&&$count===0));
    $before=$pdo->query('SELECT * FROM project_client_operations ORDER BY actor_id,operation_id')->fetchAll();$service->receipt(1,str_repeat('a',32));nativeClientCheck('receipt is read only',$before===$pdo->query('SELECT * FROM project_client_operations ORDER BY actor_id,operation_id')->fetchAll());
    $duplicate=clientFixtureInput('c');$duplicate['staff'][0]['login']='staff_a';$before=(int)$pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn();
    nativeClientCheck('native unique-login failure rolls back all directory writes',$service->create(1,$duplicate)['error']==='duplicate_client'&&(int)$pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn()===$before);
    $race=nativeClientRace('c',[['create','c',1],['create','d',3]]);
    nativeClientCheck('independent platform admins create distinct companies without partial grants',($race[0]['state']??'')==='created'&&($race[1]['state']??'')==='created'&&$race[0]['project_id']!==$race[1]['project_id']);
    $parallelId=$race[0]['project_id'];$parallel=new ManagerClientOnboardingService($registry,nativeClientProvider($pdo),'clientFixtureProbe');$correction=['operation_id'=>str_repeat('5',32),'bot_key'=>'native_corrected_c_bot','token'=>'native-corrected-token','materials'=>'Native corrected materials'];$configured=$parallel->configure(3,$parallelId,$correction);
    nativeClientCheck('native correction commits safe replayable receipt and resets check',$configured['state']==='configured'&&$parallel->receipt(3,$correction['operation_id'])===$configured&&$parallel->configure(3,$parallelId,$correction)===$configured&&$parallel->activate(3,$parallelId,str_repeat('6',32))['error']==='verification_required');
    $parallel->verify(3,$parallelId);$race=nativeClientRace('f',['activate','activate'],$parallelId,3);$receipt=$parallel->receipt(3,str_repeat('f',32));
    nativeClientCheck('independent activation workers claim exactly one provider mutation',in_array('active',array_column($race,'state'),true)&&$receipt['state']==='active'&&(int)$pdo->query('SELECT write_count FROM client_fixture_provider_calls WHERE project_id='.$parallelId)->fetchColumn()===1&&$parallel->activate(3,$parallelId,str_repeat('f',32))===$receipt);
    nativeClientCheck('native registered connection refuses correction',$parallel->configure(3,$parallelId,array_replace($correction,['operation_id'=>str_repeat('7',32)]))['error']==='configuration_locked');
    $subscriptions=[];$writes=0;$provider=clientFixtureProvider($subscriptions,$writes,static function()use($pdo){if($pdo->inTransaction())throw new RuntimeException('SQL lock held across provider');});$service=new ManagerClientOnboardingService($registry,$provider,'clientFixtureProbe');
    nativeClientCheck('native source and encrypted registry pass read-only commissioning',$service->verify(1,$id)['state']==='planned'&&$writes===0);
    $result=$service->activate(1,$id,str_repeat('d',32));nativeClientCheck('native activation confirms exactly one external write',$result['state']==='active'&&$writes===1&&$service->activate(1,$id,str_repeat('d',32))===$result);
    $new=$service->create(1,clientFixtureInput('e'));$subscriptions=[];$second=new PDO($dsn,$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$second->exec('SET SESSION innodb_lock_wait_timeout=1');
    $service=new ManagerClientOnboardingService($registry,clientFixtureProvider($subscriptions,$writes,static function($owner,$method,$path)use($second){if($path==='/me')$second->exec('UPDATE managers SET is_active=0 WHERE id=1');}),'clientFixtureProbe');
    // Save an honest preflight before revoking the actor during the next one.
    (new ManagerClientOnboardingService($registry,clientFixtureProvider($subscriptions,$writes),'clientFixtureProbe'))->verify(1,$new['project_id']);$before=$writes;$result=$service->activate(1,$new['project_id'],str_repeat('f',32));
    nativeClientCheck('independent actor revocation before mutation prevents dispatch',$writes===$before&&$result['error']==='forbidden');$second->exec('UPDATE managers SET is_active=1 WHERE id=1');
    $rows=$pdo->query('SELECT * FROM project_client_connections ORDER BY project_id')->fetchAll();$pdo->exec((string)file_get_contents(dirname(__DIR__,2).'/migrations/101_client_onboarding.sql'));
    nativeClientCheck('actual forward migration is idempotent and preserves ciphertext and receipts',$rows===$pdo->query('SELECT * FROM project_client_connections ORDER BY project_id')->fetchAll());
    nativeClientCheck('native new employee cannot access legacy company',!ProjectAccessService::canAccess((int)$pdo->query("SELECT id FROM managers WHERE login='staff_a'")->fetchColumn(),'anytour'));
    echo 'TOTAL '.$passed.' | PASS '.$passed." | FAIL 0\n";
}finally{if(is_file($private.'/master.key'))unlink($private.'/master.key');if(is_dir($private))rmdir($private);}
