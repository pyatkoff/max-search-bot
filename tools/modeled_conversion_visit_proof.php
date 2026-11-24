<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');ini_set('log_errors','0');
$level=ob_get_level();ob_start(static fn(string $s):string=>'');$ok=false;$saved=false;$scheduled=false;$prior=null;$lock=null;$started=false;
$attemptStarted=hrtime(true);$budgetMs=0;$phase='settings';$ledgerRows=null;
$work=static function()use($attemptStarted,&$budgetMs,&$phase,&$ledgerRows):array{
    return ['phase'=>$phase,'ledger_rows'=>$ledgerRows,'elapsed_ms'=>intdiv(hrtime(true)-$attemptStarted,1000000),'budget_ms'=>$budgetMs];
};
try{
    if(count($argv)!==3)throw new InvalidArgumentException();
    $scheduled=$argv[1]==='scheduled';
    if($scheduled){
        if(preg_match('/\\A(?:[5-9]|1[0-9]|20)\\z/D',$argv[2])!==1||getenv('MODELED_MONITOR_TRIGGER')!=='cron'
            ||realpath(getcwd())!=='/var/www/anytoour/data/www/app.anytoour.ru'
            ||realpath(dirname(__DIR__))!=='/var/www/anytoour/data/www/app.anytoour.ru')throw new InvalidArgumentException();
        $budgetMs=1000*(int)$argv[2];$deadline=microtime(true)+(int)$argv[2];
    }else{
        if($argv[1]!=='check'||preg_match('/\\A20[0-9]{2}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\\z/D',$argv[2])!==1)throw new InvalidArgumentException();
        $checkpoint=DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s\\Z',$argv[2],new DateTimeZone('UTC'));
        if(!$checkpoint||$checkpoint->format('Y-m-d\\TH:i:s\\Z')!==$argv[2])throw new InvalidArgumentException();
        $deadline=microtime(true)+100;
    }
    require dirname(__DIR__).'/config.php';
    require_once dirname(__DIR__).'/services/ProjectAccessService.php';
    require_once dirname(__DIR__).'/services/MetrikaOfflineClient.php';
    require_once dirname(__DIR__).'/services/ModeledConversionVisitProof.php';
    require_once dirname(__DIR__).'/services/ModeledConversionVisitLedgerSnapshot.php';
    require_once dirname(__DIR__).'/services/ModeledConversionVisitArchiveIndex.php';
    if(ProjectConfig::projectId()!=='anytour'||!defined('METRIKA_COUNTER_ID')||(string)METRIKA_COUNTER_ID!=='98615635'||!defined('METRIKA_OAUTH_TOKEN'))throw new RuntimeException();
    $dir=dirname(ModeledConversionVisitProof::path());$path=$dir.'/refresh.lock';
    if(is_link($dir)||(!is_dir($dir)&&!mkdir($dir,0700))||(fileperms($dir)&0777)!==0700||fileowner($dir)!==posix_geteuid()
        ||is_link($path)||(is_file($path)&&((fileperms($path)&0777)!==0600||fileowner($path)!==posix_geteuid())))throw new RuntimeException();
    $lock=fopen($path,'c');if($lock===false||!chmod($path,0600))throw new RuntimeException();
    if(!flock($lock,LOCK_EX|LOCK_NB)){$ok=$scheduled;}
    else{
        $now=time();$prior=ModeledConversionVisitProof::automatic($now);
        if($scheduled&&($prior['available']??false)&&$now-$prior['attempt_unix']<240)$ok=true;
        else{
            if($scheduled){ModeledConversionVisitProof::recordAutomatic('running',$now,$prior,$work());$started=true;}
            ProjectAccessService::initializeReadOnly();$pdo=ConversationDb::connection();$pdo->exec('SET SESSION TRANSACTION READ ONLY');
            $q=$pdo->query("SELECT goal_id,goal_intent,contract_version,activated_at_unix FROM modeled_conversion_value_export_settings WHERE project_key='anytour' AND counter_id='98615635' LIMIT 1");
            $settings=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
            if(($settings['goal_id']??null)!=='678187097'||($settings['goal_intent']??null)!=='verified'||($settings['contract_version']??null)!==ModeledConversionValueContract::VERSION)throw new RuntimeException();
            $phase='snapshot';
            $ledger=ModeledConversionVisitLedgerSnapshot::capture($pdo,$deadline);$activation=(int)$settings['activated_at_unix'];
            $phase='selection';$ledgerRows=count($ledger);
            $cache=ModeledConversionVisitProof::readCache($now);$retained=[];$ids=null;
            if($scheduled){
                $cutoff=($cache['available']??false)?strtotime($cache['receipt']['checkpoint_at']):$activation;
                if(!is_int($cutoff))throw new RuntimeException();
                $cachedEvents=array_column($cache['receipt']['events']??[],null,'fingerprint');
                $cohortSize=0;
                foreach($ledger as$r){
                    if(!ModeledConversionVisitProof::inCheckpointCohort($r,$cutoff))continue;
                    if(++$cohortSize>ModeledConversionVisitProof::MAX_ROWS)throw new RuntimeException('verification_cohort_bound');
                    $old=ModeledConversionVisitProof::prior($r,$now);
                    if($old===[]){
                        $cached=$cachedEvents[ModeledConversionVisitProof::fingerprint($r)]??null;
                        if($cached!==null)$old=['at'=>strtotime($cached['checked_at']??$cache['receipt']['checked_at']),'event'=>$cached];
                    }
                    $e=$old['event']??[];
                    $terminal=($e['linkage']??null)==='unique_visit_reconciled'
                        ||(($e['upload_status']??null)==='LINKAGE_FAILURE'&&!in_array($e['reason']??null,['provider_linkage_failure','client_visit_report_unavailable'],true));
                    if($terminal&&$now-($old['at']??0)<86400){
                        $e['checked_at']=gmdate('Y-m-d\\TH:i:s\\Z',$old['at']);$retained[(string)$r['id']]=$e;
                    }
                }
                $selectionIndex=ModeledConversionVisitArchiveIndex::open();
                try {
                    $readPrior=$selectionIndex===null
                        ? static fn(array $r):array=>ModeledConversionVisitProof::prior($r,$now)
                        : static fn(array $r):array=>$selectionIndex->prior($r,$now);
                    $ids=ModeledConversionVisitProof::dueIds($ledger,$cutoff,$now,$readPrior,2);
                } finally {
                    $selectionIndex?->close();
                    unset($selectionIndex, $readPrior);
                }
            }else $cutoff=$checkpoint->getTimestamp();
            $followup=($cache['available']??false)&&is_string($cache['receipt']['followup_checkpoint_at']??null)
                ?strtotime($cache['receipt']['followup_checkpoint_at']):false;
            if(!is_int($followup)||$followup<946684800||$followup>$now)$followup=null;
            $phase='verification';
            $receipt=ModeledConversionVisitProof::verify($ledger,new MetrikaOfflineClient('98615635',(string)METRIKA_OAUTH_TOKEN,null,$deadline),
                $activation,$cutoff,$now,$ids,$deadline,$retained,$followup);
            $phase='save';
            ModeledConversionVisitProof::save($receipt);$saved=true;
            if($scheduled)ModeledConversionVisitProof::recordAutomatic('ok',time(),$prior,$work());
            $ok=true;
        }
    }
}catch(Throwable $ignored){
    if($scheduled&&$started)try{ModeledConversionVisitProof::recordAutomatic('failed',time(),$prior,$work());}catch(Throwable $alsoIgnored){}
}finally{if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}}
while(ob_get_level()>$level)ob_end_clean();
echo json_encode(['ok'=>$ok,'private_verification_saved'=>$saved,'provider_write_requests'=>false,'source_writes'=>false],JSON_THROW_ON_ERROR)."\n";
exit($ok?0:1);
