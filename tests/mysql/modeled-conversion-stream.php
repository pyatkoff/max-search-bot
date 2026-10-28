<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/run_modeled_conversion_stream_regression.php';
require_once dirname(__DIR__).'/run_modeled_conversion_status_regression.php';
$admin=null;$p=null;$other=null;$safe=false;$failed=false;
try{
    $dsn=(string)getenv('PREPARED_REPLY_MYSQL_DSN');
    if(!preg_match('/\Amysql:host=127\.0\.0\.1;port=[0-9]+;dbname=maxbot_prepared_test;charset=utf8mb4\z/D',$dsn))throw new RuntimeException('unsafe_fixture_database');
    $user=getenv('PREPARED_REPLY_MYSQL_USER')?:null;$pass=getenv('PREPARED_REPLY_MYSQL_PASS')?:null;
    $opts=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false];
    $admin=new PDO($dsn,$user,$pass,$opts);
    if($admin->query('SELECT DATABASE()')->fetchColumn()!=='maxbot_prepared_test')throw new RuntimeException('unsafe_fixture_database');
    $admin->exec('CREATE DATABASE IF NOT EXISTS maxbot_modeled_stream_test CHARACTER SET utf8mb4');$safe=true;
    $testDsn=str_replace('dbname=maxbot_prepared_test;','dbname=maxbot_modeled_stream_test;',$dsn);
    $p=new PDO($testDsn,$user,$pass,$opts);$other=new PDO($testDsn,$user,$pass,$opts);
    if($p->query('SELECT DATABASE()')->fetchColumn()!=='maxbot_modeled_stream_test'||$other->query('SELECT DATABASE()')->fetchColumn()!=='maxbot_modeled_stream_test')throw new RuntimeException('unsafe_fixture_database');
    $passed=0;$check=static function(string $name,bool $ok)use(&$passed){if(!$ok)throw new RuntimeException('assertion_failed');$passed++;echo 'PASS native MySQL '.$name.PHP_EOL;};
    modeledStreamSchema($p);modeledStreamAssertions($p,$check);
    // Isolate the two-transaction race from the earlier 700-row throughput case.
    // A complete bounded sweep here takes one page, so recovery has a fixed bound.
    modeledStreamSchema($p);
    $s=new ModeledConversionStream($p);$now=1793002200;modeledStreamMessage($p,10,11);$s->start($now);
    $other->beginTransaction();modeledStreamMessage($other,800,11,'late committed fixture');
    modeledStreamMessage($p,801,11,'later ID committed first');$s->tick($now);
    $check('uncommitted source is not copied',(int)$p->query("SELECT COUNT(*) FROM modeled_conversion_stream_sources WHERE source_kind='message' AND source_id=800")->fetchColumn()===0);
    $other->commit();
    $s->tick($now+1);
    $check('actual out of order transaction commit is eventually recovered',(int)$p->query("SELECT COUNT(*) FROM modeled_conversion_stream_sources WHERE source_kind='message' AND source_id=800")->fetchColumn()===1);
    $check('retry after actual late commit adds no duplicate revision',$s->tick($now+2)['new_revisions']===0);
    $competing=new ModeledConversionStream($other);$blocked=false;
    $before=(int)$p->query('SELECT COUNT(*) FROM modeled_conversion_stream_versions')->fetchColumn();
    $s->tick($now+41,static function()use($competing,$now,&$blocked){$blocked=($competing->tick($now+41)['status']??'')==='busy';});
    $check('concurrent observer cannot own the same project checkpoint',$blocked&&(int)$p->query('SELECT COUNT(*) FROM modeled_conversion_stream_versions')->fetchColumn()===$before);
    modeledProfileAssertions($p,$check);
    $profiles=new ModeledConversionProfiles($p);$competingProfiles=new ModeledConversionProfiles($other);$profileBlocked=false;
    $profiles->tick(1793003000,static function()use($competingProfiles,&$profileBlocked){$profileBlocked=($competingProfiles->tick(1793003000)['status']??'')==='busy';});
    $check('concurrent profile transformer cannot own the same derived checkpoint',$profileBlocked);
    forecastStatusSchema($p);forecastStatusAssertions($p,$check);
    echo 'MODELED CONVERSION STREAM NATIVE MYSQL PASSED '.$passed.PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,'FAIL modeled stream native MySQL '.get_class($e).PHP_EOL);$failed=true;}
finally{
    if($other&&$other->inTransaction())$other->rollBack();$other=null;$p=null;
    if($admin&&$safe)$admin->exec('DROP DATABASE IF EXISTS maxbot_modeled_stream_test');
}
if($failed)exit(1);
require __DIR__.'/modeled-purchase-study.php';

