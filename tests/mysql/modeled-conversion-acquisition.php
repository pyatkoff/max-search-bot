<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/run_modeled_conversion_acquisition_stats_regression.php';

/** Execute the same assertions against a new disposable MySQL database only. */
function modeledAcquisitionNativeRegression():void
{
    $admin=null;$pdo=null;$created=false;$database='';$phase='fixture_guard';
    try{
        $dsn=(string)getenv('PREPARED_REPLY_MYSQL_DSN');
        if(preg_match('/\\Amysql:host=127\\.0\\.0\\.1;port=[0-9]+;dbname=maxbot_prepared_test;charset=utf8mb4\\z/D',$dsn)!==1)
            throw new RuntimeException('acquisition_unsafe_fixture');
        $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
        $user=getenv('PREPARED_REPLY_MYSQL_USER')?:null;
        $pass=getenv('PREPARED_REPLY_MYSQL_PASS')?:null;
        $phase='fixture_connection';
        $admin=new PDO($dsn,$user,$pass,$options);
        $database='maxbot_acquisition_'.bin2hex(random_bytes(6));
        if(preg_match('/\\Amaxbot_acquisition_[a-f0-9]{12}\\z/D',$database)!==1)
            throw new RuntimeException('acquisition_unsafe_fixture');
        $phase='fixture_database';
        $admin->exec('CREATE DATABASE '.$database.' CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');
        $created=true;
        $fixtureDsn=str_replace('dbname=maxbot_prepared_test;','dbname='.$database.';',$dsn);
        $pdo=new PDO($fixtureDsn,$user,$pass,$options);
        $pdo->exec("SET time_zone='+00:00'");
        $phase='reader_assertions';
        $passed=modeledAcquisitionAssertions($pdo);
        if(!is_int($passed)||$passed<1)throw new RuntimeException('acquisition_empty_assertions');
        echo 'NATIVE MYSQL ACQUISITION: '.$passed.' PASS'.PHP_EOL;
    }catch(Throwable $error){
        $state=$error instanceof PDOException?(string)($error->errorInfo[0]??$error->getCode()):'unknown';
        $driver=$error instanceof PDOException?(string)($error->errorInfo[1]??''):'unknown';
        $state=preg_match('/\\A[A-Z0-9]{5}\\z/D',$state)===1?$state:'unknown';
        $driver=preg_match('/\\A[0-9]{1,10}\\z/D',$driver)===1?$driver:'unknown';
        fwrite(STDERR,'FAIL native acquisition phase='.$phase.' sqlstate='.$state.' driver='.$driver.PHP_EOL);
        throw new RuntimeException('native_acquisition_failed');
    }finally{
        $pdo=null;
        if($created&&$admin instanceof PDO
            &&preg_match('/\\Amaxbot_acquisition_[a-f0-9]{12}\\z/D',$database)===1)
            $admin->exec('DROP DATABASE IF EXISTS '.$database);
    }
}
modeledAcquisitionNativeRegression();
