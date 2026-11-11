<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/run_advertising_visits_regression.php';
require_once dirname(__DIR__).'/fixtures/project_funnel_advertising_assertions.php';
$pdo=null;$safe=false;
try {
    $dsn=(string)getenv('ADVERTISING_VISITS_MYSQL_DSN');
    if (!preg_match('/\Amysql:.*(?:\Amysql:|;)dbname=(maxbot_advertising_test(?:_[A-Za-z0-9_]+)?)(?:;|\z)/D',$dsn,$m)) throw new RuntimeException('unsafe_fixture_database');
    $pdo=new PDO($dsn,getenv('ADVERTISING_VISITS_MYSQL_USER')?:null,getenv('ADVERTISING_VISITS_MYSQL_PASS')?:null,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    if ($pdo->query('SELECT DATABASE()')->fetchColumn()!==$m[1]) throw new RuntimeException('unsafe_fixture_database');
    $safe=true;
    foreach (['advertising_visit_observations','advertising_visit_cursors'] as $table) $pdo->exec('DROP TABLE IF EXISTS '.$table);
    advertisingVisitFixtureSchema($pdo);
    $passed=0;
    advertisingVisitAssertions($pdo,static function(string $name,bool $ok) use (&$passed):void {
        if (!$ok) throw new RuntimeException('assertion_failed');
        $passed++; echo 'PASS native MySQL '.$name.PHP_EOL;
    });
    $before=$pdo->query('SELECT * FROM advertising_visit_observations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    foreach(['037_max_channel_conversion_outbox.sql','038_max_channel_shared_goals.sql','067_telegram_dialogue_conversions.sql','104_project_funnel_context.sql']as$migration)$pdo->exec(file_get_contents(dirname(__DIR__,2).'/migrations/'.$migration));
    foreach($pdo->query('SELECT * FROM advertising_visit_observations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)as$i=>$row)if(array_intersect_key($row,$before[$i])!==$before[$i])throw new RuntimeException('historical_visit_changed');
    projectFunnelNativeAssertions($pdo,static function(string $name,bool $ok)use(&$passed):void{if(!$ok)throw new RuntimeException('project_funnel_assertion_failed');$passed++;echo 'PASS native MySQL '.$name.PHP_EOL;});
    echo 'ADVERTISING VISITS NATIVE MYSQL PASSED '.$passed.PHP_EOL;
} catch(Throwable $e) {
    fwrite(STDERR,'FAIL advertising visits native MySQL '.get_class($e).PHP_EOL);exit(1);
} finally {
    if ($pdo && $safe) foreach (['max_channel_conversion_outbox','max_channel_attribution_settings','advertising_visit_observations','advertising_visit_cursors'] as $table) {
        try {$pdo->exec('DROP TABLE IF EXISTS '.$table);} catch(Throwable $ignored) {}
    }
}
