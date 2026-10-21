<?php

declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/fixtures/virtual_reply_sampling.php';
$pdo=null;$safe=false;$tables=['virtual_reply_case_sampling','virtual_reply_sampling_claims','virtual_reply_sampling_aliases','virtual_reply_sampling_groups',
    'virtual_reply_jobs','virtual_reply_cases','virtual_reply_cursors','virtual_reply_quotas'];
try{
    $dsn=(string)getenv('PREPARED_REPLY_MYSQL_DSN');
    if(preg_match('/\Amysql:.*(?:\Amysql:|;)dbname=(maxbot_prepared_test(?:_[a-zA-Z0-9_]+)?)(?:;|\z)/D',$dsn,$m)!==1)throw new RuntimeException('unsafe_fixture_database');
    $pdo=new PDO($dsn,getenv('PREPARED_REPLY_MYSQL_USER')?:null,getenv('PREPARED_REPLY_MYSQL_PASS')?:null,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    if($pdo->query('SELECT DATABASE()')->fetchColumn()!==$m[1])throw new RuntimeException('unsafe_fixture_database');
    $safe=true;foreach($tables as $table)$pdo->exec('DROP TABLE IF EXISTS '.$table);
    samplingSchema($pdo);
    samplingAssertions($pdo,static function(string $name,bool $ok):void{
        if(!$ok)throw new RuntimeException('sampling_assertion_failed');
        echo 'PASS native MySQL '.$name."\n";
    });
    // Independent worker connections share the same consumed group/day ledger.
    foreach($tables as $table)$pdo->exec('DELETE FROM '.$table);
    $peer=new PDO($dsn,getenv('PREPARED_REPLY_MYSQL_USER')?:null,getenv('PREPARED_REPLY_MYSQL_PASS')?:null,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $reader=new SamplingFixtureReader();$reader->add(701,'native-shared');$reader->add(702,'native-shared');
    $clock=static fn():int=>strtotime('2026-10-21T19:00:00Z');
    $first=new VirtualReplyStore($pdo,$reader,$clock);$second=new VirtualReplyStore($peer,$reader,$clock);
    $first->capture('conversation','fresh',20);
    $claim=$first->claim('author',7,20);$other=$second->claim('author',8,20);
    if(count($claim['jobs'])!==1||$other['jobs']!==[]||$other['daily_used']!==1
        ||(int)$peer->query('SELECT COUNT(*) FROM virtual_reply_sampling_claims')->fetchColumn()!==1)
        throw new RuntimeException('sampling_assertion_failed');
    echo "PASS native MySQL separate worker connections cannot allocate another prefix of the consumed group\n";
}catch(Throwable $error){
    $state=$error instanceof PDOException&&(preg_match('/\A[A-Z0-9]{5}\z/D',(string)$error->getCode())===1)?(string)$error->getCode():'unknown';
    fwrite(STDERR,'FAIL native MySQL virtual sampling SQLSTATE='.$state."\n");exit(1);
}finally{if($pdo&&$safe)foreach($tables as $table){try{$pdo->exec('DROP TABLE IF EXISTS '.$table);}catch(Throwable $ignored){}}}
