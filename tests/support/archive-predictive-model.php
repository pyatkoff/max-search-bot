<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/services/ArchivePredictiveStudyAtoms.php';
require_once dirname(__DIR__,2).'/services/ArchivePredictiveRelativeWeights.php';
require_once dirname(__DIR__,2).'/services/ModeledConversionValueRegistry.php';

function archivePredictiveSyntheticCandidate(array $changes=[]):array
{
    return array_replace([
        'schema'=>1,'kind'=>'archive_predictive_relative_weights_v1','project_key'=>'anytour','run_id'=>'synthetic-study',
        'scope_digest'=>str_repeat('a',64),'source_fence'=>'100','manifest_sha256'=>str_repeat('b',64),
        'protocol_sha256'=>str_repeat('c',64),
        'extractor_sha256'=>hash_file('sha256',dirname(__DIR__,2).'/services/ArchiveResearchSemanticExtractor.php'),
        'text_features_sha256'=>hash_file('sha256',dirname(__DIR__,2).'/services/ModeledConversionTextSignals.php'),
        'target'=>'observed_retained_payment_evidence_30d_v1','complete'=>true,'weights_eligible'=>true,
        'multipliers'=>array_fill_keys(ArchivePredictiveRelativeWeights::FEATURES,1.0),
        'global_odds_bounds'=>[0.25,1.3],'explicit_decline_guard'=>0.25,'stage_priors_unchanged'=>true,
        'calibration_status'=>'not_calibrated_purchase_probability','historical_availability'=>'unknown',
        'issued_at_unix'=>1600000000,
    ],$changes);
}
function archivePredictiveModelRegression():int
{
    $checks=0;$check=static function(bool $ok,string $reason)use(&$checks):void{
        $checks++;if(!$ok)throw new RuntimeException('archive_predictive_model_'.$reason);
    };
    $candidate=archivePredictiveSyntheticCandidate();$check(ArchivePredictiveRelativeWeights::validate($candidate),'valid_candidate');
    foreach(['complete'=>false,'weights_eligible'=>false,'project_key'=>'foreign','target'=>'bank_payment',
        'stage_priors_unchanged'=>false,'historical_availability'=>'invented','issued_at_unix'=>time()+3600,
        'text_features_sha256'=>str_repeat('0',64),'extractor_sha256'=>str_repeat('0',64),'extra'=>'PRIVATE_RAW']as$k=>$v){
        $p=$candidate;$p[$k]=$v;$check(!ArchivePredictiveRelativeWeights::validate($p),'reject_'.$k);
    }
    foreach([0.0,0.49,1.51,INF,NAN,'1.1']as$v){
        $p=$candidate;$p['multipliers']['deferral']=$v;
        $check(!ArchivePredictiveRelativeWeights::validate($p),'reject_multiplier');
    }
    $raw=json_encode($candidate,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $cap=ArchivePredictiveRelativeWeights::capsule($candidate,hash('sha256',$raw));
    $check(is_array($cap)&&ArchivePredictiveRelativeWeights::validCapsule($cap),'capsule');
    $oldCap=$cap;$oldCap['extractor_sha256']=str_repeat('0',64);
    $check(ArchivePredictiveRelativeWeights::validCapsule($oldCap),'historical_source_hash_retained');
    $dir=sys_get_temp_dir().'/archive-predictive-model-'.bin2hex(random_bytes(8));$file=$dir.'/active.json';
    if(!mkdir($dir,0700))throw new RuntimeException('fixture_directory');
    try{
        file_put_contents($file,$raw);chmod($file,0600);$uid=(int)fileowner($file);
        $loaded=ArchivePredictiveRelativeWeights::loadFrom($file,$uid);
        $check($loaded===$cap,'fixed_file_bytes_provenance');
        $check(ArchivePredictiveRelativeWeights::loadFrom($file,$uid+1)===null,'owner_check');
        chmod($file,0644);$check(ArchivePredictiveRelativeWeights::loadFrom($file,$uid)===null,'file_permissions');
        chmod($file,0600);chmod($dir,0755);
        $check(ArchivePredictiveRelativeWeights::loadFrom($file,$uid)===null,'directory_permissions');chmod($dir,0700);
        if(function_exists('symlink')){
            symlink($file,$dir.'/link.json');
            $check(ArchivePredictiveRelativeWeights::loadFrom($dir.'/link.json',$uid)===null,'symlink_refused');
            unlink($dir.'/link.json');
        }
        file_put_contents($file,'{"invalid":true}');
        $check(ArchivePredictiveRelativeWeights::loadFrom($file,$uid)===null,'malformed_file');
    }finally{
        if(is_link($dir.'/link.json'))unlink($dir.'/link.json');
        if(is_file($file))unlink($file);
        rmdir($dir);
    }
    $message=static function(string $role,string $text,int $id,int $time,array $override=[]):array{
        return array_replace(['source_ref'=>'archive_message:'.$id,'source_kind'=>'desktop_import','read_status'=>'available',
            'role'=>$role,'direction'=>$role==='customer'?'inbound':'outbound','role_verified'=>true,
            'created_at'=>$time,'version_at'=>$time,'record_sha256'=>str_repeat('d',64),'forwarded'=>false,
            'text'=>$text,'text_minimized'=>true,'media'=>[],'media_contents_read'=>false,
            'selection_links_unread'=>false,'links_redacted'=>false],$override);
    };
    $msgs=[$message('customer','Подберите тур в Турцию на 7 ночей. PRIVATE_TEXT_MUST_NOT_PERSIST',1,1700000000),
        $message('manager','Бронируем?',2,1700000010),$message('customer','Да',3,1700000020)];
    $window=['account_id'=>'100','chat_id'=>'200','account_alias'=>'A1','chat_window_complete'=>true,'messages'=>$msgs];
    $a=new ArchivePredictiveStudyAtoms(str_repeat('k',32));$whole=$a->consume($window);
    $b=new ArchivePredictiveStudyAtoms(str_repeat('k',32));
    $first=$b->consume(array_replace($window,['messages'=>array_slice($msgs,0,2),'chat_window_complete'=>false]));
    $resume=new ArchivePredictiveStudyAtoms(str_repeat('k',32),$b->state());
    $last=$resume->consume(array_replace($window,['messages'=>array_slice($msgs,2)]));
    $check($whole===[...$first,...$last]&&$a->state()===$resume->state(),'source_window_resume_parity');
    $check(in_array('agency_purchase_intent',$whole[2]['goals'],true),'canonical_yes_premise_retained');
    $json=json_encode($whole,JSON_THROW_ON_ERROR);
    $check(!str_contains($json,'PRIVATE_TEXT_MUST_NOT_PERSIST')&&!str_contains($json,'"text":')
        &&!str_contains($json,'"account_id":')&&!str_contains($json,'"chat_id":'),'no_raw_text_or_native_identity_in_atoms');
    $other=(new ArchivePredictiveStudyAtoms(str_repeat('k',32)))->consume(array_replace($window,['account_id'=>'101','account_alias'=>'A2']));
    $check($other[0]['group_key']===$whole[0]['group_key']&&$other[0]['case_key']!==$whole[0]['case_key'],
        'same_peer_cross_accounts_one_split_group');
    $f=(new ArchivePredictiveStudyAtoms(str_repeat('k',32)))->consume(array_replace($window,
        ['messages'=>[$message('customer','Оплатил',4,1700000030,['forwarded'=>true])]]));
    $check($f[0]['goals']===[]&&$f[0]['status']==='forwarded'
        &&$f[0]['text_features']['customer_text_nonempty']===false,'forwarded_not_own_payment');
    $unknown=(new ArchivePredictiveStudyAtoms(str_repeat('k',32)))->consume(array_replace($window,
        ['messages'=>[$message('customer','',5,1700000040,['read_status'=>'deleted','text'=>null,'record_sha256'=>null])]]));
    $check($unknown[0]['status']==='source_unavailable'&&$unknown[0]['at']===1700000040,'unknown_retains_source_coverage');
    $w=$candidate;$w['multipliers']['payment_terms_question']=1.3;$wraw=json_encode($w,JSON_THROW_ON_ERROR);
    $weighted=ArchivePredictiveRelativeWeights::capsule($w,hash('sha256',$wraw));
    $model=new ModeledConversionSemanticPrediction(semanticPredictionSubject(),'2026-11-02T01:00:00Z',
        '2026-11-02T00:00:00Z','2026-11-03T12:00:00Z','100','100',$weighted);
    $state=ArchiveResearchSemanticExtractor::initialState();$base=strtotime('2026-11-01T00:00:00Z');
    foreach([['customer','Подберите тур в Турцию на 7 ночей.'],['customer','Можно оплатить картой?']]as$i=>$parts){
        [$role,$text]=$parts;$ref='journal:'.($i+1);$at=$base+$i*60;
        $r=semanticPredictionResult($text,$role,$ref,$at,$state);$model->observeMessage($role,$text,$ref,$at,$at+1,1,$r);
    }
    $p=$model->finish(semanticPredictionLegacy());
    $check($p['stage']==='concrete_request'&&$p['base_probability']===0.083333
        &&$p['hypothesis_odds_multiplier']===1.3&&$p['estimated_probability']>$p['base_probability']
        &&$p['features']['payment_terms_question']&&$p['training_status']==='archive_relative_weights'
        &&$p['calibration_status']==='not_calibrated'&&!$p['export_allowed'],'relative_weights_preserve_prior_and_export_contract');
    $wrapper=['subject'=>semanticPredictionSubject(),'access_status'=>'allowed',
        'profile'=>['prediction_at'=>$p['profile_prediction_at']],'semantic_prediction'=>$p];
    $read=ModeledConversionValueRegistry::predictionFields($wrapper);
    $check($read['semantic_prediction']['availability']==='available'
        &&$read['semantic_prediction']['value']['relative_weights']===$weighted,'frozen_weighted_packet_reads_back');
    $changed=$wrapper;$changed['semantic_prediction']['estimated_probability']=0.9;
    $changed['semantic_prediction']['prediction_digest']=ModeledConversionSemanticPrediction::digest($changed['semantic_prediction']);
    $check(ModeledConversionValueRegistry::predictionFields($changed)['semantic_prediction']['availability']==='unavailable',
        'resigned_inconsistent_weighted_probability_refused');
    $prior=semanticPredictionRun([['text'=>'Подберите тур в Турцию на 7 ночей.']]);$frozen=serialize($prior);
    $legacy=['subject'=>semanticPredictionSubject(),'access_status'=>'allowed',
        'profile'=>['prediction_at'=>$prior['profile_prediction_at']],'semantic_prediction'=>$prior];
    $check(ModeledConversionValueRegistry::predictionFields($legacy)['semantic_prediction']['availability']==='available'
        &&serialize($prior)===$frozen&&!isset($prior['relative_weights']),'legacy_snapshot_is_not_rewritten');
    $late=$weighted;$late['issued_at_unix']=strtotime('2026-11-04T00:00:00Z');
    $thrown=false;
    try{new ModeledConversionSemanticPrediction(semanticPredictionSubject(),'2026-11-02T01:00:00Z',
        '2026-11-02T00:00:00Z','2026-11-03T12:00:00Z','100','100',$late);}
    catch(InvalidArgumentException$ignored){$thrown=true;}
    $check($thrown,'weights_after_forecast_clock_refused');
    return $checks;
}
