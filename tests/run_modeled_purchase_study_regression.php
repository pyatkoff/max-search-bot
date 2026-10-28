<?php
declare(strict_types=1);

/** Real producer/bridge lifecycle on synthetic SQLite/MySQL records and private files. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/services/ModeledConversionPurchaseStudy.php';
require_once dirname(__DIR__).'/services/ModeledConversionOutcomeReceipt.php';
require_once __DIR__.'/run_modeled_conversion_stream_regression.php';
$passed=0;$failed=0;
function purchaseRuntimeCheck(string $name,bool $ok):void
{
    global $passed,$failed;echo($ok?'PASS  ':'FAIL  ').$name."\n";if($ok)$passed++;else$failed++;
}
function purchaseRuntimeState(string $dir):array
{
    $envelope=json_decode((string)file_get_contents($dir.'/state.json'),true,8,JSON_THROW_ON_ERROR);
    return json_decode($envelope['state_json'],true,256,JSON_THROW_ON_ERROR);
}
function purchaseRuntimeClean(string $path):void
{
    if(is_link($path)||is_file($path)){unlink($path);return;}
    if(!is_dir($path))return;
    foreach(scandir($path)as$name)if($name!=='.'&&$name!=='..')purchaseRuntimeClean($path.'/'.$name);
    rmdir($path);
}
function purchaseRuntimeProcess(array $command,string $input=''):array
{
    $proc=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($proc))throw new RuntimeException('fixture_process');
    fwrite($pipes[0],$input);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);return[proc_close($proc),$out,$error];
}
function purchaseRuntimePerson(PDO $p,int $id,int $created,string $project='anytour',int $customer=0):void
{
    $customer=$customer?:$id;
    $p->prepare('INSERT INTO customer_channels(id,customer_id,project_key,channel,external_user_id) VALUES (?,?,?,?,?)')->execute([$id,$customer,$project,'max','fixture-user-'.$id]);
    $p->prepare('INSERT INTO conversations(id,customer_id,customer_channel_id,project_key,channel,is_test,metadata_json) VALUES (?,?,?,?,?,?,?)')->execute([$id,$customer,$id,$project,'max',0,json_encode(ConversationRecordClock::stamp([],$created),JSON_THROW_ON_ERROR)]);
}
function purchaseRuntimeMessage(PDO $p,int $message,int $conversation):void
{
    $now=time();
    $p->prepare('INSERT INTO messages(id,conversation_id,channel,direction,sender_type,text,metadata_json,created_at) VALUES (?,?,?,?,?,?,?,?)')->execute([$message,$conversation,'max','inbound','customer',"Подскажите цену на тур и отель отдельно 3взр+2реб на 6/8/11 ночей\n1. Zephyr Lantern номер не старый",json_encode(ConversationRecordClock::stamp(['type'=>'message'],$now),JSON_THROW_ON_ERROR),gmdate('Y-m-d H:i:s',$now)]);
}
function purchaseRuntimeReportFixture(string $directory,array $state,array $receipt,string $root):void
{
    if(!is_dir($directory))mkdir($directory,0700);
    $code="import sys,json;sys.path.insert(0,sys.argv[1]);import purchase_study_runtime as r;import purchase_conversion_study as s;x=json.loads(sys.stdin.read());x['model']=s.seal({k:v for k,v in x['model'].items() if k!='sha256'});x['evaluation']['model_sha256']=x['model']['sha256'];x['evaluation']=s.seal({k:v for k,v in x['evaluation'].items() if k!='sha256'});print(r.encoded(r.seal(x)))";
    [$exit,$stateJson,$error]=purchaseRuntimeProcess(['python3','-c',$code,$root.'/tools'],json_encode($state,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION));
    if($exit!==0||$error!=='')throw new RuntimeException('fixture_report_seal');$stateJson=trim($stateJson);
    $receiptJson=json_encode($receipt,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION);
    file_put_contents($directory.'/state.json',json_encode(['schema_version'=>1,'state_json'=>$stateJson,'state_sha256'=>hash('sha256',$stateJson),'receipt_json'=>$receiptJson,'receipt_sha256'=>hash('sha256',$receiptJson)],JSON_THROW_ON_ERROR));chmod($directory.'/state.json',0600);
    file_put_contents($directory.'/lock','');chmod($directory.'/lock',0600);
}
/** Reproduce only a synthetic fixture failure; never broaden production logging. */
function purchaseRuntimeRequireAdvance(ModeledConversionPurchaseStudy $supplier,string $directory,string $root):array
{
    $envelope=json_decode((string)file_get_contents($directory.'/state.json'),true,8,JSON_THROW_ON_ERROR);
    $previous=json_decode($envelope['state_json'],true,256,JSON_THROW_ON_ERROR);$result=$supplier->tick(time());
    if(($result['status']??null)!=='failed')return$result;
    try{
        $collect=new ReflectionMethod(ModeledConversionPurchaseStudy::class,'collect');
        $request=$collect->invoke($supplier,$previous,$envelope['state_json']);
    }catch(PDOException $error){
        $state=preg_match('/\A[A-Z0-9]{5}\z/D',(string)$error->getCode())?(string)$error->getCode():'unknown';
        $number=(int)($error->errorInfo[1]??0);throw new RuntimeException('fixture_collect_sqlstate_'.$state.'_driver_'.$number);
    }catch(Throwable $error){
        $category=preg_match('/\Apurchase_study_[a-z_]{1,100}\z/D',$error->getMessage())?$error->getMessage():'unknown';
        throw new RuntimeException('fixture_collect_'.$category);
    }
    $code="import sys,json,re;sys.path.insert(0,sys.argv[1]);import purchase_study_runtime as r;import purchase_conversion_study as s\ntry:\n r.advance(json.loads(sys.stdin.read()));print('ok')\nexcept s.NotReady as e:\n print(str(e) if re.fullmatch('[a-z_]{1,100}',str(e)) else 'unknown');sys.exit(2)";
    [$exit,$category,$error]=purchaseRuntimeProcess(['python3','-c',$code,$root.'/tools'],json_encode($request,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION));
    $category=trim($category);if(preg_match('/\A[a-z_]{1,100}\z/D',$category)!==1)$category='unknown';
    throw new RuntimeException('fixture_advance_bridge_'.$category.'_exit_'.$exit);
}

$root=dirname(__DIR__);$temp=realpath(sys_get_temp_dir()).'/purchase-runtime-fixture-'.bin2hex(random_bytes(10));mkdir($temp,0700);
try {
    DiagnosticLogger::setFile($temp.'/events.log');ProjectConfig::resetForTests(['id'=>'anytour']);
    $testDsn=getenv('PURCHASE_STUDY_TEST_DSN')?:'sqlite::memory:';
    if($testDsn!=='sqlite::memory:'&&preg_match('/\Amysql:host=127\.0\.0\.1;port=[0-9]+;dbname=maxbot_modeled_purchase_study_test;charset=utf8mb4\z/D',$testDsn)!==1)throw new RuntimeException('unsafe_fixture_database');
    $testOptions=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
    if($testDsn!=='sqlite::memory:')$testOptions[PDO::ATTR_EMULATE_PREPARES]=false;
    $p=new PDO($testDsn,getenv('PURCHASE_STUDY_TEST_USER')?:null,getenv('PURCHASE_STUDY_TEST_PASS')?:null,$testOptions);
    $testMysql=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    if($testMysql&&$p->query('SELECT DATABASE()')->fetchColumn()!=='maxbot_modeled_purchase_study_test')throw new RuntimeException('unsafe_fixture_database');
    modeledStreamSchema($p);modeledProfileSchema($p);
    $p->exec('ALTER TABLE conversations ADD COLUMN metadata_json TEXT');
    $p->exec('ALTER TABLE conversations ADD COLUMN lead_outcome TEXT');
    $p->exec('DROP TABLE conversation_events');
    $p->exec('CREATE TABLE conversation_events(id '.($testMysql?'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT').',conversation_id BIGINT,event_type VARCHAR(64),actor_type VARCHAR(32),actor_id VARCHAR(191),payload_json TEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $p->prepare('INSERT INTO modeled_conversion_profile_versions(id,project_key,platform,customer_key,conversation_id,journal_cursor,observed_at_unix,input_sha256,profile_json) VALUES (?,?,?,?,?,?,?,?,?)')->execute([50,'anytour','max','9000','9000',0,time()-86400,str_repeat('a',64),'fixture-secret-old-profile-must-not-be-read']);
    $stream=new ModeledConversionStream($p);$stream->start(time());$profiles=new ModeledConversionProfiles($p);$profiles->start();$stream->tick(time());$profiles->tick(time());
    $directory=$temp.'/study';$supplier=new ModeledConversionPurchaseStudy($p,$directory);
    purchaseRuntimeCheck('status is unregistered without creating private artifacts',$supplier->status()['status']==='unregistered'&&!file_exists($directory));
    $noReport=$supplier->report();purchaseRuntimeCheck('private report cannot register a study or invent maturity',$noReport['status']==='unregistered'&&$noReport['registered_at_utc']===null&&$noReport['first_window_matures_at_utc']===null&&$noReport['horizon_days']===30&&!file_exists($directory));
    $registered=$supplier->tick(time());$first=purchaseRuntimeState($directory);$registeredAt=strtotime($first['manifest']['registered_at']);
    purchaseRuntimeCheck('first actual tick registers at current clock and freezes the existing profile floor',$registered['status']==='registered'&&$registered['cases']===0&&$first['profile_cursor']==='50'&&abs(time()-$registeredAt)<=10);
    purchaseRuntimeCheck('protocol state is private and atomically stored',((fileperms($directory)&0777)===0700)&&((fileperms($directory.'/state.json')&0777)===0600)&&((fileperms($directory.'/lock')&0777)===0600)&&glob($directory.'/state-*.tmp')===[]);
    $pure=new ModeledConversionPurchaseStudy(new PDO('sqlite::memory:'),$directory);$saved=file_get_contents($directory.'/state.json');
    purchaseRuntimeCheck('registered status needs no source tables and never changes the registry',$pure->status()['status']==='registered'&&file_get_contents($directory.'/state.json')===$saved);
    $initialReport=$pure->report();purchaseRuntimeCheck('private report exposes actual registration with no SQL or model work',$initialReport['registered_at_utc']===$first['manifest']['registered_at']&&$initialReport['comparison']===null&&$initialReport['first_window_matures_at_utc']===null&&file_get_contents($directory.'/state.json')===$saved);
    [$exit,$choice,$err]=purchaseRuntimeProcess(['python3','-c',"import sys;sys.path.insert(0,sys.argv[1]);import purchase_conversion_study as s;print(next(i for i in range(1000,2000) if s.assignment_for_scope({'project_key':'anytour','platform':'max','customer_key':str(i),'conversation_id':str(i)})=='train'))",$root.'/tools']);
    if($exit!==0||$err!==''||!ctype_digit(trim($choice)))throw new RuntimeException('fixture_assignment');$person=(int)trim($choice);
    while(time()<=$registeredAt)usleep(10000);
    purchaseRuntimePerson($p,$person,time());purchaseRuntimeMessage($p,1,$person);$stream->tick(time());$profiles->tick(time());
    $nativeProfile=json_decode($p->query("SELECT profile_json FROM modeled_conversion_profile_versions WHERE customer_key='".$person."' ORDER BY id LIMIT 1")->fetchColumn(),true,128,JSON_THROW_ON_ERROR);
    $r=purchaseRuntimeRequireAdvance($supplier,$directory,$root);$state=purchaseRuntimeState($directory);$packet=$nativeProfile['purchase_study'];
    purchaseRuntimeCheck('actual stream and profile producer feeds its first frozen customer packet to the runtime',$r['status']==='advanced'&&$r['cases']===1&&count($state['cases'])===1&&$state['cases'][0]['packet']['sha256']===$packet['sha256']);
    purchaseRuntimeCheck('new-lead admission preserves canonical UTC and source references',$state['cases'][0]['admission']['scope']==$packet['scope']&&$state['cases'][0]['admission']['prior_conversations']===0&&$state['cases'][0]['admission']['earlier_profiles']===0&&strtotime($state['cases'][0]['admission']['conversation_created_at'])>$registeredAt);
    purchaseRuntimeCheck('descriptive unresolved trips do not activate probabilities or export',$packet['legacy_trip_status']==='unknown'&&$packet['purchase_probability']===null&&$packet['export_allowed']===false&&$r['model_fitted']===0&&$r['verified_payment_probability']===0&&$r['all_lead_calibration_ready']===0&&$r['conversion_uploads']===0);
    $frozen=$state['cases'][0]['packet'];$p->exec("UPDATE conversations SET lead_outcome='won' WHERE id=".$person);$r=$supplier->tick(time());
    purchaseRuntimeCheck('mutable CRM won without a receipt never creates an outcome label',count(purchaseRuntimeState($directory)['cases'][0]['outcomes'])===0&&$r['train_resolved']===0);
    $ok=ModeledConversionOutcomeReceipt::observe($p,$person,'won',null,7,'140000.00','2026-11-04');$stream->tick(time());$profiles->tick(time());$r=$supplier->tick(time());$state=purchaseRuntimeState($directory);
    purchaseRuntimeCheck('actual outcome observer through the real stream reaches the enrolled inquiry',$ok&&$r['status']==='advanced'&&count($state['cases'][0]['outcomes'])===1&&$state['cases'][0]['outcomes'][0]['payload']['sale_amount']==='140000.00');
    purchaseRuntimeCheck('actual producer key order and numeric/string identities survive normalization',$state['cases'][0]['outcomes'][0]['scope']==$packet['scope']&&$state['cases'][0]['outcomes'][0]['event_type']==='sales_pipeline_outcome_observed'&&$state['cases'][0]['outcomes'][0]['payload']['actor_role']==='manager');
    ModeledConversionOutcomeReceipt::observe($p,$person,'lost','not_travelling',7,null,null);ModeledConversionOutcomeReceipt::observe($p,$person,'open',null,0,null,null);$stream->tick(time());$profiles->tick(time());$r=$supplier->tick(time());$state=purchaseRuntimeState($directory);
    purchaseRuntimeCheck('new-source correction and system reopening remain append-only outcome history',count($state['cases'][0]['outcomes'])===3&&array_column(array_column($state['cases'][0]['outcomes'],'payload'),'outcome')===['won','lost','open']);
    purchaseRuntimeCheck('later profiles and outcomes cannot replace the earliest selected feature packet',$state['cases'][0]['packet']===$frozen&&$r['already_selected_profiles']>=1&&$r['train_resolved']===0);
    purchaseRuntimeCheck('only admitted-journal coverage is represented, not complete business capture',$state['cases'][0]['coverage']['status']==='complete'&&$r['coverage_is_admitted_journal_only']===1&&$r['all_lead_calibration_ready']===0);
    $reportBytes=file_get_contents($directory.'/state.json');$report=$pure->report();
    purchaseRuntimeCheck('private report supplies first admitted window maturity without a source read',$report['status']==='registered'&&$report['horizon_days']===30&&$report['first_window_matures_at_utc']===gmdate('Y-m-d\TH:i:s\Z',strtotime($packet['frozen_at'])+30*86400)&&$report['comparison_status']==='not_available'&&$report['comparison']===null&&file_get_contents($directory.'/state.json')===$reportBytes);
    // This sealed cache fixture tests projection/privacy only, not model quality.
    $reportState=$state;$reportState['model']=['kind'=>'paired_model','version'=>'inquiry_outcome_study_v1','target'=>'manager_recorded_inquiry_outcome_30d_v1','sha256'=>str_repeat('b',64),'model_weights'=>['fixture-private-model-weight']];$reportState['model_invalidated']=false;
    $bins=[];for($bin=0;$bin<5;$bin++)$bins[]=['bin'=>$bin,'n'=>$bin===2?40:0,'predicted_mean'=>$bin===2?0.5:null,'observed_rate'=>$bin===2?0.5:null,'person_group'=>'fixture-private-bin-person'];
    $score=['n'=>40,'positives'=>20,'brier'=>0.25,'log_loss'=>0.7,'top_k'=>10,'precision_at_k'=>0.5,'calibration'=>$bins,'source_reference'=>'fixture-private-score-source'];$better=$score;$better['brier']=0.2;$better['log_loss']=0.6;$better['precision_at_k']=0.8;
    $reportState['evaluation']=['kind'=>'paired_evaluation','version'=>'inquiry_outcome_study_v1','target'=>'manager_recorded_inquiry_outcome_30d_v1','protocol_sha256'=>$state['protocol_sha256'],'model_sha256'=>str_repeat('b',64),'evaluated_at'=>gmdate('Y-m-d\TH:i:s\Z'),'control_people'=>45,'paired_resolved_people'=>40,'unknown_or_censored_people'=>5,'baseline'=>$score,'conditions'=>$better,
        'paired_delta_conditions_minus_baseline'=>['brier'=>-0.05,'log_loss'=>-0.1,'precision_at_k'=>0.3],
        'paired_person_bootstrap'=>['brier'=>['lower'=>-0.08,'upper'=>-0.02],'log_loss'=>['lower'=>-0.15,'upper'=>-0.05],'precision_at_k'=>['lower'=>0.1,'upper'=>0.4]],'evaluation_population'=>'same_resolved_conditional_subset','full_buyer_quality_ready'=>false,'purchase_probability'=>null,'export_allowed'=>false,'training_groups'=>['fixture-private-training-person'],'source_reference'=>'fixture-private-evaluation-source'];
    $reportEnvelope=json_decode($reportBytes,true,8,JSON_THROW_ON_ERROR);$reportReceipt=json_decode($reportEnvelope['receipt_json'],true,8,JSON_THROW_ON_ERROR);$reportReceipt['model_fitted']=1;$reportReceipt['comparison_available']=1;$reportReceipt['paired_resolved_people']=40;$reportReceipt['paired_brier_delta']=-0.05;$reportReceipt['training_revalidation_pending']=0;
    $reportDirectory=$temp.'/report';purchaseRuntimeReportFixture($reportDirectory,$reportState,$reportReceipt,$root);$reportSupplier=new ModeledConversionPurchaseStudy(new PDO('sqlite::memory:'),$reportDirectory);$report=$reportSupplier->report();$comparison=$report['comparison'];$projected=json_encode($report,JSON_THROW_ON_ERROR);
    purchaseRuntimeCheck('private comparison exposes paired scores calibration ranking and uncertainty only',$report['comparison_status']==='available'&&$comparison['baseline']['n']===40&&$comparison['conditions']['brier']===0.2&&count($comparison['baseline']['calibration'])===5&&$comparison['paired_person_bootstrap']['brier']['upper']===-0.02&&$comparison['comparison_conclusion']==='conditional_score_advantage'&&$comparison['full_buyer_quality_ready']===false&&!str_contains($projected,'fixture-private-')&&!str_contains($projected,'model_sha256')&&!str_contains($projected,'person_group')&&!str_contains($projected,'source_reference')&&!str_contains($projected,'model_weights'));
    $reportState['model_invalidated']=true;$reportReceipt['model_invalidated']=1;$reportReceipt['comparison_available']=0;purchaseRuntimeReportFixture($reportDirectory,$reportState,$reportReceipt,$root);$report=$reportSupplier->report();
    purchaseRuntimeCheck('invalidated model suppresses its old scores from the private report',$report['comparison_status']==='model_invalidated'&&$report['comparison']===null);
    $reportState['model_invalidated']=false;$reportReceipt['model_invalidated']=0;$reportReceipt['training_revalidation_pending']=1;purchaseRuntimeReportFixture($reportDirectory,$reportState,$reportReceipt,$root);$report=$reportSupplier->report();
    purchaseRuntimeCheck('temporary training revalidation suppresses current comparison without inventing model invalidation',$report['comparison_status']==='training_revalidation_pending'&&$report['comparison']===null);
    $reportReceipt['comparison_available']=1;$reportReceipt['training_revalidation_pending']=0;$reportState['evaluation']['baseline']['calibration'][2]['predicted_mean']='fixture-private-not-a-number';purchaseRuntimeReportFixture($reportDirectory,$reportState,$reportReceipt,$root);$report=$reportSupplier->report();
    purchaseRuntimeCheck('malformed aggregate values fail closed rather than returning arbitrary private strings',$report['status']==='failed'&&$report['comparison']===null&&!str_contains(json_encode($report),'fixture-private-'));
    $public=json_encode($r,JSON_THROW_ON_ERROR);$forbidden=['scope','customer_key','conversation_id','person_group','state_json','packet','profile_cursor','outcomes'];
    purchaseRuntimeCheck('returned diagnostics contain only fixed aggregate keys and no source text',array_intersect(array_keys($r),$forbidden)===[]&&!str_contains($public,'Zephyr Lantern')&&!str_contains(file_get_contents($directory.'/state.json'),'fixture-secret-old-profile'));
    $saved=file_get_contents($directory.'/state.json');$lock=fopen($directory.'/lock','r+b');flock($lock,LOCK_EX);
    purchaseRuntimeCheck('competing runtime and status inspection respect the nonblocking artifact lock',$supplier->tick(time())['status']==='busy'&&$supplier->status()['status']==='busy'&&file_get_contents($directory.'/state.json')===$saved);flock($lock,LOCK_UN);fclose($lock);
    $p->beginTransaction();$r=$supplier->tick(time());$owned=$p->inTransaction();$p->rollBack();
    purchaseRuntimeCheck('runtime never commits or rolls back an existing caller transaction',$r['status']==='failed'&&$owned&&file_get_contents($directory.'/state.json')===$saved);
    $p->exec("UPDATE modeled_conversion_stream_state SET enabled=0 WHERE project_key='anytour'");$r=$supplier->tick(time());
    purchaseRuntimeCheck('disabled existing intake cannot advance the study',$r['status']==='off'&&file_get_contents($directory.'/state.json')===$saved);$p->exec("UPDATE modeled_conversion_stream_state SET enabled=1 WHERE project_key='anytour'");
    file_put_contents($directory.'/state.json',$saved.'broken');$bad=file_get_contents($directory.'/state.json');
    purchaseRuntimeCheck('corrupt state fails closed without resetting protocol or overwriting evidence',$supplier->status()['status']==='failed'&&$supplier->tick(time())['status']==='failed'&&file_get_contents($directory.'/state.json')===$bad);file_put_contents($directory.'/state.json',$saved);
    $old=json_decode($p->query('SELECT payload_json FROM conversation_events WHERE id=1')->fetchColumn(),true,32,JSON_THROW_ON_ERROR);$old['forbidden_note']='fixture-private-edited-secret';
    $p->prepare('UPDATE conversation_events SET payload_json=? WHERE id=1')->execute([json_encode($old,JSON_THROW_ON_ERROR)]);$stream->tick(time());$profiles->tick(time());$r=$supplier->tick(time());$edited=purchaseRuntimeState($directory);
    purchaseRuntimeCheck('actual edited source revision becomes sticky unknown without copying the changed payload',$r['status']==='advanced'&&$r['invalid_outcome_rows']>=1&&isset($edited['exclusions'][$packet['person_group']])&&$edited['exclusions'][$packet['person_group']]['reason']==='current_access_unknown'&&!str_contains(file_get_contents($directory.'/state.json'),'fixture-private-edited-secret'));
    $revoked=$temp.'/revoked';mkdir($revoked,0700);file_put_contents($revoked.'/state.json',$saved);chmod($revoked.'/state.json',0600);$other=new ModeledConversionPurchaseStudy($p,$revoked);$p->exec('UPDATE conversations SET is_test=1 WHERE id='.$person);$r=$other->tick(time());$revokedState=purchaseRuntimeState($revoked);
    purchaseRuntimeCheck('current canonical test exclusion invalidates previously enrolled inquiry access',$r['status']==='advanced'&&$revokedState['exclusions'][$packet['person_group']]['reason']==='current_access_denied');$p->exec('UPDATE conversations SET is_test=0 WHERE id='.$person);
    $unsafe=$temp.'/unsafe';mkdir($unsafe,0700);$target=$temp.'/target';file_put_contents($target,'fixture-private-target');chmod($target,0600);symlink($target,$unsafe.'/state.json');$unsafeSupplier=new ModeledConversionPurchaseStudy($p,$unsafe);
    purchaseRuntimeCheck('symlink state files fail closed and external targets are untouched',$unsafeSupplier->status()['status']==='failed'&&$unsafeSupplier->tick(time())['status']==='failed'&&file_get_contents($target)==='fixture-private-target');
    $wrong=$temp.'/not-private';mkdir($wrong,0755);chmod($wrong,0755);$wrongSupplier=new ModeledConversionPurchaseStudy($p,$wrong);$r=$wrongSupplier->tick(time());
    purchaseRuntimeCheck('existing nonprivate directories are rejected instead of chmodding shared paths',$r['status']==='failed'&&((fileperms($wrong)&0777)===0755));
    $inside=$root.'/purchase-study-test-must-not-exist';$r=(new ModeledConversionPurchaseStudy($p,$inside))->tick(time());
    purchaseRuntimeCheck('study artifacts cannot be created under the served repository',$r['status']==='failed'&&!file_exists($inside));
    $log=is_file($temp.'/events.log')?file_get_contents($temp.'/events.log'):'';$safe=true;
    foreach(array_filter(explode("\n",$log))as$line){$entry=json_decode($line,true);if(($entry['component']??null)==='modeled_purchase_study')$safe=$safe&&($entry['event']??null)==='runtime_failed'&&($entry['data']??null)===['coverage'=>'unknown']&&!isset($entry['chat_id']);}
    purchaseRuntimeCheck('failure telemetry has no cohort counts identifiers or private payload',$safe&&!str_contains($log,'Zephyr Lantern')&&!str_contains($log,'fixture-private-edited-secret'));

    // Exact copied producer code: a Python helper edit must invalidate old state.
    $code=$temp.'/code';mkdir($code,0700);mkdir($code.'/services',0700);mkdir($code.'/tools',0700);
    foreach(['services/ModeledConversionPurchaseStudy.php','services/ModeledConversionOutcomeReceipt.php','services/ConversationRecordClock.php','services/InternalOwnerDialogueScope.php','services/DiagnosticLogger.php','tools/purchase_conversion_study.py','tools/purchase_study_runtime.py','tools/profile_conversion_journal.py','tools/extract_conversion_profiles.py']as$file)copy($root.'/'.$file,$code.'/'.$file);
    $private=$temp.'/copied-private';$bootstrap=$temp.'/fingerprint.php';
    $program='<?php require '.var_export($code.'/services/ModeledConversionPurchaseStudy.php',true).'; DiagnosticLogger::setFile('.var_export($temp.'/copied-events.log',true).'); $p=new PDO("sqlite::memory:",null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);'
        .'$p->exec("CREATE TABLE modeled_conversion_profiles_state(project_key TEXT,enabled INT,journal_cursor INT)");$p->exec("INSERT INTO modeled_conversion_profiles_state VALUES (\'anytour\',1,0)");'
        .'$p->exec("CREATE TABLE modeled_conversion_stream_state(project_key TEXT,enabled INT,last_receipt_json TEXT)");$p->exec("INSERT INTO modeled_conversion_stream_state VALUES (\'anytour\',1,NULL)");'
        .'$p->exec("CREATE TABLE modeled_conversion_profile_versions(id INT,project_key TEXT)");$p->exec("CREATE TABLE modeled_conversion_stream_versions(id INT,project_key TEXT)");'
        .'$s=new ModeledConversionPurchaseStudy($p,'.var_export($private,true).');$first=$s->tick(time());$before=file_get_contents('.var_export($private.'/state.json',true).');'
        .'file_put_contents('.var_export($code.'/tools/purchase_conversion_study.py',true).',"\n# fixture changed semantic helper\n",FILE_APPEND);'
        .'$status=$s->status();$next=$s->tick(time());echo json_encode([$first["status"],$status["status"],$next["status"],file_get_contents('.var_export($private.'/state.json',true).')===$before]);';
    file_put_contents($bootstrap,$program);[$exit,$answer,$error]=purchaseRuntimeProcess([PHP_BINARY,$bootstrap]);
    purchaseRuntimeCheck('changed semantic Python helper invalidates existing runtime registration without auto-reset',$exit===0&&$error===''&&json_decode($answer,true)===['registered','failed','failed',true]);
}catch(Throwable $e){purchaseRuntimeCheck('runtime fixture executes required producer integration',false);fwrite(STDERR,'purchase_runtime_fixture_failed: '.get_class($e).': '.$e->getMessage()."\n");}
finally{purchaseRuntimeClean($temp);}
echo "\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";exit($failed?1:0);
