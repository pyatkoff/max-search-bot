<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

/** An isolated child process avoids changing the surrounding native fixture. */
(static function():void {
    $purchaseStudyAdmin=null;$purchaseStudySafe=false;$purchaseStudyFailed=false;$purchaseStudyProcess=null;
    try{
        $purchaseStudyDsn=(string)getenv('PREPARED_REPLY_MYSQL_DSN');
        if(preg_match('/\Amysql:host=127\.0\.0\.1;port=[0-9]+;dbname=maxbot_prepared_test;charset=utf8mb4\z/D',$purchaseStudyDsn)!==1)throw new RuntimeException('unsafe_fixture_database');
        $purchaseStudyUser=getenv('PREPARED_REPLY_MYSQL_USER')?:null;$purchaseStudyPass=getenv('PREPARED_REPLY_MYSQL_PASS')?:null;
        $purchaseStudyAdmin=new PDO($purchaseStudyDsn,$purchaseStudyUser,$purchaseStudyPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
        if($purchaseStudyAdmin->query('SELECT DATABASE()')->fetchColumn()!=='maxbot_prepared_test')throw new RuntimeException('unsafe_fixture_database');
        $purchaseStudyAdmin->exec('CREATE DATABASE IF NOT EXISTS maxbot_modeled_purchase_study_test CHARACTER SET utf8mb4');$purchaseStudySafe=true;
        $purchaseStudyEnvironment=getenv();if(!is_array($purchaseStudyEnvironment))throw new RuntimeException('fixture_environment');
        $purchaseStudyEnvironment['PURCHASE_STUDY_TEST_DSN']=str_replace('dbname=maxbot_prepared_test;','dbname=maxbot_modeled_purchase_study_test;',$purchaseStudyDsn);
        $purchaseStudyEnvironment['PURCHASE_STUDY_TEST_USER']=$purchaseStudyUser??'';$purchaseStudyEnvironment['PURCHASE_STUDY_TEST_PASS']=$purchaseStudyPass??'';
        $purchaseStudyProcess=proc_open([PHP_BINARY,dirname(__DIR__).'/run_modeled_purchase_study_regression.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$purchaseStudyPipes,null,$purchaseStudyEnvironment);
        if(!is_resource($purchaseStudyProcess))throw new RuntimeException('fixture_process');
        fclose($purchaseStudyPipes[0]);$purchaseStudyOut=stream_get_contents($purchaseStudyPipes[1]);$purchaseStudyError=stream_get_contents($purchaseStudyPipes[2]);
        fclose($purchaseStudyPipes[1]);fclose($purchaseStudyPipes[2]);$purchaseStudyCode=proc_close($purchaseStudyProcess);$purchaseStudyProcess=null;
        if($purchaseStudyOut!=='')echo$purchaseStudyOut;if($purchaseStudyError!=='')fwrite(STDERR,$purchaseStudyError);
        if($purchaseStudyCode!==0)throw new RuntimeException('native_purchase_study_failed');
        echo 'MODELED PURCHASE STUDY NATIVE MYSQL PASSED'.PHP_EOL;
    }catch(Throwable $purchaseStudyError){
        fwrite(STDERR,'FAIL modeled purchase study native MySQL '.get_class($purchaseStudyError).PHP_EOL);$purchaseStudyFailed=true;
    }finally{
        if(is_resource($purchaseStudyProcess)){proc_terminate($purchaseStudyProcess,9);proc_close($purchaseStudyProcess);}
        if($purchaseStudyAdmin&&$purchaseStudySafe)$purchaseStudyAdmin->exec('DROP DATABASE IF EXISTS maxbot_modeled_purchase_study_test');
        $purchaseStudyAdmin=null;
    }
    if($purchaseStudyFailed)exit(1);
})();
