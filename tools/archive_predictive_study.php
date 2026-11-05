<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');ini_set('log_errors','0');error_reporting(0);
$archiveStudyOutputLevel=ob_get_level();ob_start(static fn(string $bytes):string=>'');
require_once dirname(__DIR__).'/services/ArchivePredictiveStudyStore.php';

/** Fixed-command subprocess; captured bytes never enter public logs. */
function archivePredictivePrivateProcess(array $command,int $timeoutSeconds):array
{
    $environment=null;
    if(($command[0]??null)==='/usr/bin/git'){
        $environment=getenv();if(!is_array($environment))throw new RuntimeException('study_process_unavailable');
        $environment['GIT_OPTIONAL_LOCKS']='0';
    }
    $pipes=[];$process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__),$environment);
    if(!is_resource($process))throw new RuntimeException('study_process_unavailable');
    fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
    $out='';$err='';$exit=null;$started=hrtime(true);
    try{
        while(true){
            $out.=stream_get_contents($pipes[1]);$err.=stream_get_contents($pipes[2]);
            if(strlen($out)>8192||strlen($err)>8192)throw new RuntimeException('study_process_output_limit');
            $status=proc_get_status($process);
            if(!is_array($status))throw new RuntimeException('study_process_unavailable');
            if(!$status['running']){$exit=(int)$status['exitcode'];break;}
            if(hrtime(true)-$started>$timeoutSeconds*1000000000)throw new RuntimeException('study_process_timeout');
            usleep(100000);
        }
        $out.=stream_get_contents($pipes[1]);$err.=stream_get_contents($pipes[2]);
        if(strlen($out)>8192||strlen($err)>8192)throw new RuntimeException('study_process_output_limit');
    }catch(Throwable $e){proc_terminate($process);throw $e;}
    finally{fclose($pipes[1]);fclose($pipes[2]);$closed=proc_close($process);}
    return ['exit'=>$exit!==null&&$exit>=0?$exit:$closed,'stdout'=>$out];
}

/** Bind the tracked application tree and index to the reviewed installed revision without Git index refresh writes. */
function archivePredictiveAssertInstalledSource(string $root,string $expectedSource):void
{
    $commit=archivePredictivePrivateProcess(['/usr/bin/git','-C',$root,'rev-parse','HEAD'],10);
    if($commit['exit']!==0||trim($commit['stdout'])!==$expectedSource)
        throw new RuntimeException('study_installed_source_changed');
    foreach([['diff','--quiet','HEAD'],['diff','--cached','--quiet']]as$arguments){
        $probe=archivePredictivePrivateProcess(array_merge(['/usr/bin/git','-C',$root],$arguments),10);
        if($probe['exit']!==0)throw new RuntimeException('study_installed_source_changed');
    }
}

$store=null;$stage='arguments';$exitCode=0;$publicResult=['ok'=>false];
try{
    $operation=$argv[1]??'';
    if(!in_array($operation,['build','status','activate','derive'],true)
        ||count($argv)!==(in_array($operation,['activate','derive'],true)?5:4)
        ||preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/D',$argv[2]??'')!==1
        ||preg_match('/\A[a-f0-9]{40}\z/D',$argv[3]??'')!==1
        ||($operation==='activate'&&preg_match('/\A[a-f0-9]{64}\z/D',$argv[4]??'')!==1)
        ||($operation==='derive'&&preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/D',$argv[4]??'')!==1))
        throw new RuntimeException('study_arguments');
    $runId=$argv[2];$expectedSource=$argv[3];$root=dirname(__DIR__);
    $stage='operator';
    if(realpath($root)!=='/var/www/anytoour/data/www/app.anytoour.ru'
        ||!function_exists('posix_geteuid')||posix_geteuid()!==1012)
        throw new RuntimeException('study_operator_denied');
    archivePredictiveAssertInstalledSource($root,$expectedSource);
    $level=ob_get_level();ob_start();
    try{
        require $root.'/config.php';
        require_once $root.'/services/ProjectAccessService.php';
        require_once $root.'/services/ArchiveResearchReadService.php';
        require_once $root.'/services/ArchivePredictiveStudyAtoms.php';
        require_once $root.'/services/ArchivePredictiveRelativeWeights.php';
    }finally{while(ob_get_level()>$level)ob_end_clean();}
    ini_set('display_errors','0');ini_set('log_errors','0');error_reporting(0);
    if(ProjectConfig::projectId()!=='anytour')throw new RuntimeException('study_operator_denied');
    ProjectAccessService::initializeReadOnly();
    $files=['services/ArchiveResearchReadService.php','services/ArchiveResearchSemanticExtractor.php',
        'services/ManagerAiTextRedactor.php','services/InternalOwnerDialogueScope.php','services/ModeledConversionSemanticPrediction.php',
        'services/ArchivePredictiveStudyAtoms.php','services/ModeledConversionTextSignals.php',
        'services/ArchivePredictiveStudyStore.php','services/ArchivePredictiveRelativeWeights.php',
        'tools/archive_predictive_study.php','tools/archive_predictive_study.py'];
    $hashes=[];
    foreach($files as$file){
        $path=$root.'/'.$file;
        if(is_link($path)||!is_file($path)||realpath($path)!==$path)throw new RuntimeException('study_source_unavailable');
        $hash=hash_file('sha256',$path);
        if(!is_string($hash))throw new RuntimeException('study_source_unavailable');
        $hashes[$file]=$hash;
    }
    $authorize=static function()use($root,$hashes,$expectedSource):void{
        if(PHP_SAPI!=='cli'||posix_geteuid()!==1012||realpath(dirname(__DIR__))!==$root
            ||ProjectConfig::projectId()!=='anytour')throw new RuntimeException('study_operator_denied');
        archivePredictiveAssertInstalledSource($root,$expectedSource);
        foreach($hashes as$file=>$expected){
            $path=$root.'/'.$file;
            if(is_link($path)||realpath($path)!==$path||!hash_equals($expected,(string)hash_file('sha256',$path)))
                throw new RuntimeException('study_source_changed');
        }
    };
    $authorize();$store=new ArchivePredictiveStudyStore();
    if($operation==='status'){
        $result=$store->status($runId);
    }else{
        $provenance=['source_commit'=>$expectedSource,
            'extractor_sha256'=>$hashes['services/ArchiveResearchSemanticExtractor.php'],
            'text_features_sha256'=>$hashes['services/ModeledConversionTextSignals.php'],
            'analyzer_sha256'=>$hashes['tools/archive_predictive_study.py'],
            'rule_revision'=>ArchiveResearchSemanticExtractor::RULE_REVISION,'captured_at_unix'=>time(),'code_hashes'=>$hashes];
        $reader=new ArchiveResearchReadService(ConversationDb::connection());
        if($operation==='derive'){
            $stage='derivation_scope';$fresh=$reader->operatorStudyPage($authorize,[],1);
            $stage='derivation';$checkpoint=$store->deriveCompleted($argv[4],$runId,$provenance,$fresh['scope_digest']);
        }else{$stage='store';$checkpoint=$store->begin($runId,$provenance);}
        if(isset($checkpoint['lineage'])){
            $stage='derivation_scope';$fresh=$reader->operatorStudyPage($authorize,[],1);
            if(($fresh['scope_digest']??null)!==$checkpoint['scope_digest'])throw new RuntimeException('study_source_scope_changed');
        }
        if($operation==='activate'){
            $stage='activation_scope';$fresh=$reader->operatorStudyPage($authorize,[],1);
            if(($fresh['scope_digest']??null)!==$checkpoint['scope_digest'])throw new RuntimeException('study_source_scope_changed');
            $stage='activation';
            $result=$store->activate($argv[4],static fn(array $candidate):bool=>ArchivePredictiveRelativeWeights::validate($candidate));
        }else{
            $salt=hex2bin($checkpoint['salt_hex']);
            if(!is_string($salt)||strlen($salt)!==32)throw new RuntimeException('study_checkpoint');
            $atoms=new ArchivePredictiveStudyAtoms($salt,$checkpoint['atom_state']);
            $began=hrtime(true);$pages=0;
            while(!$checkpoint['complete']){
                if(++$pages>20000||hrtime(true)-$began>1800000000000)throw new RuntimeException('study_capture_limit');
                $stage='source_page';
                $page=$reader->operatorStudyPage($authorize,$checkpoint['position']??[],25);
                $stage='projection';$derived=[];
                foreach($page['windows']as$window)foreach($atoms->consume($window)as$atom)$derived[]=$atom;
                $stage='checkpoint';$checkpoint=$store->commitPage($page,$derived,$atoms->state());
                if($checkpoint['source_counts']['records']>500000)throw new RuntimeException('study_capture_limit');
                if(!$checkpoint['complete'])usleep(20000);
            }
            $authorize();$stage='analysis_intent';
            if($store->claimAnalysis()){
                $stage='analysis';
                $analysis=archivePredictivePrivateProcess(['/usr/bin/python3','-I','-B',$root.'/tools/archive_predictive_study.py',
                    '--run-dir',$store->path()],300);
                if($analysis['exit']!==0)throw new RuntimeException('study_analysis_failed');
                $proof=json_decode(trim($analysis['stdout']),true,16,JSON_THROW_ON_ERROR);
                if(!is_array($proof)||count($proof)!==5||array_diff(array_keys($proof),['ok','completed','private_outputs_written',
                    'purchase_probability_calibrated','live_files_changed'])
                    ||($proof['ok']??null)!==true||($proof['completed']??null)!==true
                    ||($proof['private_outputs_written']??null)!==true||($proof['purchase_probability_calibrated']??null)!==false
                    ||($proof['live_files_changed']??null)!==false)throw new RuntimeException('study_analysis_contract');
                $authorize();
                if(isset($checkpoint['lineage'])){
                    $stage='derivation_scope';$fresh=$reader->operatorStudyPage($authorize,[],1);
                    if(($fresh['scope_digest']??null)!==$checkpoint['scope_digest'])throw new RuntimeException('study_source_scope_changed');
                }
                $stage='publication';$result=$store->publishAnalysis();
            }else $result=['ok'=>true,'completed'=>true,'already_completed'=>true];
        }
    }
    // The workflow sees only technical facts. Corpus sizes, outcomes and coefficients stay private.
    $publicResult=$result;
}catch(Throwable $error){
    $allowed=['study_arguments','study_operator_denied','study_installed_source_changed','study_source_unavailable',
        'study_source_changed','study_busy','study_provenance_changed','study_existing_intent','study_capture_complete',
        'study_source_scope_changed','study_page_contract','study_expected_contract','study_window_contract',
        'study_message_contract','study_page_reconciliation','study_position_contract','study_source_order',
        'study_chunk_limit','study_orphan_conflict','study_corpus_reconciliation','study_chunk_changed',
        'study_capture_incomplete','study_analysis_intent_changed','study_analysis_unknown','study_analysis_contract',
        'study_analysis_binding','study_analysis_receipt_changed','study_analysis_failed','study_capture_limit',
        'study_review_hash','study_candidate_changed','study_weights_not_eligible','study_activation_readback',
        'study_process_unavailable','study_process_output_limit','study_process_timeout','study_summary_limit',
        'study_summary_identity','study_raw_source_refused','study_checkpoint','study_checkpoint_limit',
        'study_file','study_file_changed','study_file_limit','study_directory','study_directory_sync','study_scope',
        'study_derive_arguments','study_derive_parent_incomplete','study_derive_target_exists','study_derive_source_changed',
        'study_derive_parent_changed','study_derive_state_changed'];
    $reason=in_array($error->getMessage(),$allowed,true)?$error->getMessage():'study_operation_failed';
    $publicResult=['ok'=>false,'reason'=>$reason,'stage'=>$stage];
    $exitCode=1;
}finally{if($store!==null)$store->close();while(ob_get_level()>$archiveStudyOutputLevel)ob_end_clean();}
foreach($publicResult as$key=>$value){
    if(in_array($key,['reason','stage'],true)){
        if(is_string($value)&&preg_match('/\A[a-z_]{1,64}\z/D',$value)===1)continue;
    }elseif(is_bool($value))continue;
    $publicResult=['ok'=>false,'reason'=>'study_public_output_contract'];$exitCode=1;break;
}
echo json_encode($publicResult,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
exit($exitCode);
