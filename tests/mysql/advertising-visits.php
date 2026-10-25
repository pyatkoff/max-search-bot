<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/run_advertising_visits_regression.php';
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
    echo 'ADVERTISING VISITS NATIVE MYSQL PASSED '.$passed.PHP_EOL;
} catch(Throwable $e) {
    fwrite(STDERR,'FAIL advertising visits native MySQL '.get_class($e).PHP_EOL);exit(1);
} finally {
    if ($pdo && $safe) foreach (['advertising_visit_observations','advertising_visit_cursors'] as $table) {
        try {$pdo->exec('DROP TABLE IF EXISTS '.$table);} catch(Throwable $ignored) {}
    }
}
