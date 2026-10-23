<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
require_once dirname(__DIR__).'/support/search3-tour-handoff.php';

function search3NativeConnection(string $dsn): PDO
{
    if (!preg_match('/\Amysql:.*(?:\Amysql:|;)dbname=(maxbot_prepared_test_search3_[a-f0-9]{12})(?:;|\z)/D',$dsn,$m)) throw new RuntimeException('unsafe_search3_fixture');
    $pdo=new PDO($dsn,getenv('PREPARED_REPLY_MYSQL_USER')?:null,getenv('PREPARED_REPLY_MYSQL_PASS')?:null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    if ($pdo->query('SELECT DATABASE()')->fetchColumn()!==$m[1]) throw new RuntimeException('wrong_search3_fixture');$pdo->exec('SET SESSION innodb_lock_wait_timeout=5');return $pdo;
}
if (($argv[1]??'')==='worker') {
    try {
        $input=json_decode(stream_get_contents(STDIN),true,24,JSON_THROW_ON_ERROR);$db=search3NativeConnection($input['dsn']);
        (new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$db);ProjectConfig::resetForTests(search3FixtureConfig());(new ReflectionProperty(Search3TourHandoffTrust::class,'keys'))->setValue(null,$input['keys']);
        echo "ready\n";flush();
        $result=$input['mode']==='native'?TourSelectionBotEntryService::accept($input['incoming']):Search3TourHandoffService::execute($input['raw'],$input['server']);echo json_encode($result,JSON_THROW_ON_ERROR);exit;
    }catch(Throwable $e){fwrite(STDERR,'Search3 worker failed: '.get_class($e));exit(1);}
}

/** Both workers announce startup while the real project lock is held. */
function search3NativeRace(PDO $pdo,string $dsn,array $keys,array $inputs): array
{
    $workers=[];$pdo->beginTransaction();Search3TourHandoffService::lockProject($pdo);
    try {
        foreach ($inputs as $input) {
            $process=proc_open([PHP_BINARY,__FILE__,'worker'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('cannot_start_search3_worker');
            $workers[]=[$process,$pipes];fwrite($pipes[0],json_encode($input+['dsn'=>$dsn,'keys'=>$keys],JSON_THROW_ON_ERROR));fclose($pipes[0]);
        }
        foreach ($workers as [, $pipes]) if (trim((string)fgets($pipes[1]))!=='ready') throw new RuntimeException('search3_worker_not_ready');
        usleep(100000);$pdo->commit();$out=[];
        foreach ($workers as [$process,$pipes]) {$body=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($process)!==0||$error!=='')throw new RuntimeException('search3_worker_failed');$out[]=json_decode($body,true,24,JSON_THROW_ON_ERROR);}
        $workers=[];return $out;
    } finally {
        if($pdo->inTransaction())$pdo->rollBack();foreach($workers as [$process,$pipes]){foreach([1,2]as$i)if(is_resource($pipes[$i]))fclose($pipes[$i]);if(is_resource($process)){proc_terminate($process);proc_close($process);}}
    }
}
$admin=null;$native=null;$database=null;$start=$checks;
try {
    $sourceDsn=(string)getenv('PREPARED_REPLY_MYSQL_DSN');if(!preg_match('/\Amysql:.*(?:\Amysql:|;)dbname=(maxbot_prepared_test(?:_[a-zA-Z0-9_]+)?)(?:;|\z)/D',$sourceDsn,$m))throw new RuntimeException('unsafe_fixture_database');
    $admin=new PDO($sourceDsn,getenv('PREPARED_REPLY_MYSQL_USER')?:null,getenv('PREPARED_REPLY_MYSQL_PASS')?:null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);if($admin->query('SELECT DATABASE()')->fetchColumn()!==$m[1])throw new RuntimeException('wrong_fixture_database');
    $database='maxbot_prepared_test_search3_'.bin2hex(random_bytes(6));$admin->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4');
    $dsn=preg_replace('/(?<=dbname=)'.preg_quote($m[1],'/').'(?=;|\z)/',$database,$sourceDsn);$native=search3NativeConnection($dsn);$seed=deliveryDb();
    foreach($seed->query("SELECT name,sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' AND name NOT LIKE 'manager_tour_selection_%'")->fetchAll()as$table){
        $sql=preg_replace('/\bINTEGER PRIMARY KEY AUTOINCREMENT\b/','BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY',$table['sql']);$sql=preg_replace('/\bINTEGER\b/','BIGINT',$sql);$sql=str_replace('created_at TEXT DEFAULT CURRENT_TIMESTAMP','created_at DATETIME DEFAULT CURRENT_TIMESTAMP',$sql);$native->exec($sql);
        foreach($seed->query('SELECT * FROM '.$table['name'])->fetchAll()as$row){$q=$native->prepare('INSERT INTO '.$table['name'].'('.implode(',',array_keys($row)).') VALUES('.implode(',',array_fill(0,count($row),'?')).')');$q->execute(array_values($row));}
    }
    search3FixtureSchema($native);[$secret,$keys]=search3Scenarios($native);
    $before=$native->query('SELECT * FROM search3_tour_handoffs ORDER BY handoff_id')->fetchAll();search3FixtureSchema($native);deliveryCheck('native060 migration retry preserves all immutable receipts',$native->query('SELECT * FROM search3_tour_handoffs ORDER BY handoff_id')->fetchAll()===$before);
    $engine=$native->query("SHOW TABLE STATUS LIKE 'search3_tour_handoffs'")->fetch();$indexes=$native->query('SHOW INDEX FROM search3_tour_handoffs')->fetchAll();$names=array_column($indexes,'Key_name');deliveryCheck('native060 uses InnoDB and operation token retention indexes',strtolower($engine['Engine'])==='innodb'&&!array_diff(['PRIMARY','search3_operation','search3_telegram_token','search3_max_token','search3_bounds'],$names));
    $intent=search3FixtureIntent();[$raw,$server]=search3FixtureSigned($intent,$secret);$command=['mode'=>'command','raw'=>$raw,'server'=>$server];$race=search3NativeRace($native,$dsn,$keys,[$command,$command]);
    deliveryCheck('native concurrent create commands return one immutable capability pair',$race[0]['ok']&&$race[1]['ok']&&$race[0]['links']===$race[1]['links']&&$race[0]['duplicate']!==$race[1]['duplicate']);
    $q=$native->prepare('SELECT COUNT(*) FROM search3_tour_handoffs WHERE project_key=? AND operation_id=?');$q->execute(['anytour',$intent['operation_id']]);deliveryCheck('native operation unique key retains one receipt',(int)$q->fetchColumn()===1);
    $payload=search3FixturePayload($race[0]['links']['telegram']);$incoming=['mode'=>'native','incoming'=>search3FixtureNative('telegram',$payload,101)];$events=(int)$native->query("SELECT COUNT(*) FROM conversation_events WHERE event_type='tour_search_inquiry'")->fetchColumn();$messages=(int)$native->query('SELECT COUNT(*) FROM messages')->fetchColumn();
    $entries=search3NativeRace($native,$dsn,$keys,[$incoming,$incoming]);deliveryCheck('native concurrent authentic entries commit one inquiry and one message',$entries[0]['ok']&&$entries[1]['ok']&&$entries[0]['changed']!==$entries[1]['changed']&&(int)$native->query("SELECT COUNT(*) FROM conversation_events WHERE event_type='tour_search_inquiry'")->fetchColumn()===$events+1&&(int)$native->query('SELECT COUNT(*) FROM messages')->fetchColumn()===$messages+1);
    $revoke=['action'=>'revoke','operation_id'=>$intent['operation_id'],'request_hash'=>hash('sha256',$raw)];[$revokeRaw,$revokeServer]=search3FixtureSigned($revoke,$secret);$out=search3NativeRace($native,$dsn,$keys,[['mode'=>'command','raw'=>$revokeRaw,'server'=>$revokeServer],$incoming]);
    deliveryCheck('native revoke serializes with entry and forbids subsequent use',$out[0]['state']==='revoked'&&Search3TourHandoffService::botEntry('telegram',$payload)===null&&(int)$native->query("SELECT COUNT(*) FROM conversation_events WHERE event_type='tour_search_inquiry'")->fetchColumn()===$events+1);
    echo 'NATIVE SEARCH3 TOTAL '.($checks-$start).' PASS'.PHP_EOL;
} finally {
    if($native&&$native->inTransaction())$native->rollBack();$native=null;if($admin&&$database&&preg_match('/\Amaxbot_prepared_test_search3_[a-f0-9]{12}\z/D',$database))$admin->exec('DROP DATABASE `'.$database.'`');
}
