<?php
declare(strict_types=1);

/** Isolated synthetic files. Called by the existing registered archive regression. */
function archivePredictiveStudyStoreRegression():int
{
    require_once dirname(__DIR__,2).'/services/ArchivePredictiveStudyStore.php';
    $passed=0;
    $check=static function(bool $ok,string $name)use(&$passed):void{
        if(!$ok)throw new RuntimeException('archive_predictive_store_'.$name);$passed++;
    };
    $reject=static function(callable $call,string $reason)use($check):void{
        try{$call();$check(false,'expected_'.$reason);}
        catch(RuntimeException $e){$check($e->getMessage()===$reason,'rejected_'.$reason);}
    };
    $remove=static function(string $path)use(&$remove):void{
        if(is_link($path)||is_file($path)){unlink($path);return;}
        if(!is_dir($path))return;
        foreach(scandir($path)as$name)if($name!=='.'&&$name!=='..')$remove($path.'/'.$name);
        rmdir($path);
    };
    $base=sys_get_temp_dir().'/archive-predictive-store-'.bin2hex(random_bytes(12));
    if(!mkdir($base,0700))throw new RuntimeException('archive_predictive_store_fixture_directory');
    $uid=function_exists('posix_geteuid')?posix_geteuid():fileowner($base);
    $root=$base.'/private';$store=null;$other=null;
    $write=static function(string $path,string $raw):void{
        if(file_put_contents($path,$raw)!==strlen($raw)||!chmod($path,0600))
            throw new RuntimeException('archive_predictive_store_fixture_file');
    };
    $hash=str_repeat('a',64);$scope=str_repeat('b',64);$features=str_repeat('c',64);$protocol=str_repeat('d',64);
    $provenance=['source_commit'=>str_repeat('1',40),'extractor_sha256'=>$hash,'text_features_sha256'=>$features,
        'analyzer_sha256'=>str_repeat('e',64),'rule_revision'=>3,'captured_at_unix'=>1700000000,
        'code_hashes'=>['services/Synthetic.php'=>str_repeat('f',64),
            'services/ArchiveResearchSemanticExtractor.php'=>$hash,'services/ModeledConversionTextSignals.php'=>$features,
            'services/ArchivePredictiveStudyAtoms.php'=>str_repeat('1',64),'services/ArchiveResearchReadService.php'=>str_repeat('2',64),
            'services/ManagerAiTextRedactor.php'=>str_repeat('3',64),'services/InternalOwnerDialogueScope.php'=>str_repeat('4',64)]];
    $atom=static fn(int $id):array=>['type'=>'message','case_key'=>str_repeat('1',64),'group_key'=>str_repeat('2',64),
        'account_alias'=>'A1','ref'=>'archive_message:'.$id,'at'=>1700000000+$id,'version_at'=>1700000000+$id,
        'record_sha256'=>str_repeat('3',64),'role'=>'customer','status'=>'scanned','episode'=>1,'boundary'=>null,
        'product'=>'tour','goals'=>['substantive_interest'],'flags'=>[],'text_features'=>['customer_text_nonempty'=>true]];
    $page1=['project_key'=>'anytour','source_fence'=>'9','scope_digest'=>$scope,
        'expected'=>['desktop_records'=>2,'account_chat_pairs'=>2],
        'windows'=>[['account_id'=>'10','chat_id'=>'20','account_alias'=>'A1','chat_window_complete'=>true,
            'messages'=>[['source_kind'=>'desktop_import','read_status'=>'available'],['source_kind'=>'desktop_import','read_status'=>'available']]]],
        'next_position'=>['f'=>'9','a'=>'10','c'=>'20','n'=>'2','scope_digest'=>$scope],'has_more'=>true,
        'metadata_rows'=>2,'source_json_bytes'=>100,'returned_records'=>2];
    $page2=['project_key'=>'anytour','source_fence'=>'9','scope_digest'=>$scope,'expected'=>null,
        'windows'=>[['account_id'=>'11','chat_id'=>'21','account_alias'=>'A2','chat_window_complete'=>true,
            'messages'=>[['source_kind'=>'business_message','read_status'=>'available']]]],
        'next_position'=>null,'has_more'=>false,'metadata_rows'=>1,'source_json_bytes'=>50,'returned_records'=>1];
    try{
        $reader=new ArchivePredictiveStudyStore($root,$uid);
        $check($reader->readSummaryForScope($scope)===['available'=>false,'status'=>'not_built']
            &&!file_exists($root),'missing_summary_is_readonly_without_directory_creation');
        $check($reader->status('synthetic')===['ok'=>true,'exists'=>false]&&!file_exists($root),'missing_status_is_readonly');
        $reject(static fn()=>new ArchivePredictiveStudyStore($base.'/../outside',$uid),'study_directory');
        $store=new ArchivePredictiveStudyStore($root,$uid);
        $reject(static fn()=>$store->begin('../outside',$provenance),'study_run');
        $initial=$store->begin('synthetic',$provenance);
        $check(strlen($initial['salt_hex'])===64&&$initial['source_fence']===null&&!$initial['complete'],'initial_private_state');
        $check((fileperms($root)&0077)===0&&(fileperms($root.'/synthetic')&0077)===0
            &&(fileperms($root.'/synthetic/checkpoint.json')&0077)===0,'private_modes');
        $other=new ArchivePredictiveStudyStore($root,$uid);
        $reject(static fn()=>$other->begin('competing',$provenance),'study_busy');
        $check($other->readSummaryForScope($scope)===['available'=>false,'status'=>'busy'],'reader_does_not_bypass_writer_lock');
        $bad=$atom(1);$bad['text']='synthetic unminimized message';
        $reject(static fn()=>$store->commitPage($page1,[$bad,$atom(2)],['v'=>1]),'study_raw_source_refused');
        $check($store->currentCheckpoint()['source_counts']['records']===0
            &&!file_exists($root.'/synthetic/chunk_000001.ndjson'),'raw_source_failure_does_not_advance_checkpoint');
        $c=$store->commitPage($page1,[$atom(1),$atom(2)],['v'=>1,'sequence'=>2]);
        $check($c['source_counts']['records']===2&&$c['chat_pairs']===1&&!$c['complete']
            &&$c['position']===$page1['next_position'],'first_page_commits_only_returned_coverage');
        $chunk1=file_get_contents($root.'/synthetic/chunk_000001.ndjson');
        $check(hash('sha256',$chunk1)===$c['chunks'][0]['sha256']
            &&substr_count($chunk1,"\n")===2&&(fileperms($root.'/synthetic/chunk_000001.ndjson')&0077)===0,
            'immutable_chunk_precedes_durable_checkpoint');
        $reject(static fn()=>$store->claimAnalysis(),'study_capture_incomplete');
        $store->close();$later=$provenance;$later['captured_at_unix']++;
        $c=$store->begin('synthetic',$later);
        $check($c['salt_hex']===$initial['salt_hex']&&$c['atom_state']===['v'=>1,'sequence'=>2]
            &&$c['provenance']['captured_at_unix']===$provenance['captured_at_unix'],'resume_preserves_identity_salt_and_state');
        $badPage=$page2;$badPage['scope_digest']=str_repeat('9',64);
        $reject(static fn()=>$store->commitPage($badPage,[$atom(3)],['v'=>1]),'study_source_scope_changed');
        $check($store->currentCheckpoint()['source_counts']['records']===2,'scope_drift_does_not_advance');
        $write($root.'/synthetic/chunk_000002.ndjson',"{}\n");
        $reject(static fn()=>$store->commitPage($page2,[$atom(3)],['v'=>1]),'study_orphan_conflict');
        $check($store->currentCheckpoint()['source_counts']['records']===2,'conflicting_orphan_never_replaced');
        unlink($root.'/synthetic/chunk_000002.ndjson');
        $expectedChunk=json_encode($atom(3),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
        $write($root.'/synthetic/chunk_000002.ndjson',$expectedChunk);
        $c=$store->commitPage($page2,[$atom(3)],['v'=>1,'sequence'=>3]);
        $check($c['complete']&&$c['source_counts']['records']===3&&$c['source_counts']['desktop_import']===2
            &&$c['source_counts']['business_message']===1&&$c['chat_pairs']===2&&count($c['chunks'])===2,
            'identical_orphan_reused_and_eof_reconciles_all_source_kinds');
        $check(file_get_contents($root.'/synthetic/chunk_000002.ndjson')===$expectedChunk,'no_derived_chunk_rewrite');
        $manifestRaw=file_get_contents($root.'/synthetic/manifest.json');
        $manifest=json_decode($manifestRaw,true,64,JSON_THROW_ON_ERROR);
        $check($manifest['complete']===true&&$manifest['coverage']['source_counts']['records']===3
            &&$manifest['coverage']['historical_availability']==='unknown'
            &&$manifest['expected']===['desktop_records'=>2,'account_chat_pairs'=>2],'final_manifest_truthful_coverage');
        $check($store->claimAnalysis()===true,'analysis_intent_recorded_before_execution');
        $reject(static fn()=>$store->claimAnalysis(),'study_analysis_unknown');
        $analysis=$root.'/synthetic/analysis';mkdir($analysis,0700);
        $binding=['schema'=>1,'project_key'=>'anytour','run_id'=>'synthetic','scope_digest'=>$scope,'source_fence'=>'9',
            'manifest_sha256'=>hash('sha256',$manifestRaw),'protocol_sha256'=>$protocol,'complete'=>true,'weights_eligible'=>true];
        $summary=['kind'=>'archive_predictive_study_summary_v1']+$binding+['counts'=>['payment_candidate_cases'=>1],
            'coverage'=>['historical_availability'=>'unknown'],'fit'=>['fitted'=>true],'evaluation'=>null];
        $candidate=['kind'=>'archive_predictive_relative_weights_v1']+$binding+[
            'extractor_sha256'=>$hash,'text_features_sha256'=>$features,'target'=>'observed_retained_payment_evidence_30d_v1',
            'multipliers'=>array_fill_keys(['continued_meaningful_engagement','near_term_urgency','long_range_planning',
                'deferral','continued_offer_search','office_question','payment_terms_question'],1.0),
            'global_odds_bounds'=>[0.25,1.3],'explicit_decline_guard'=>0.25,'stage_priors_unchanged'=>true,
            'calibration_status'=>'not_calibrated_purchase_probability','historical_availability'=>'unknown',
            'issued_at_unix'=>1700000100];
        $summaryRaw=json_encode($summary,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
        $candidateRaw=json_encode($candidate,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
        $write($analysis.'/summary.json',$summaryRaw);$write($analysis.'/candidate_weights.json',$candidateRaw);
        $write($analysis.'/case_audits.jsonl',"{}\n");$write($analysis.'/prefixes.jsonl',"{}\n");
        $published=$store->publishAnalysis();
        $check($published['ok']&&$published['completed']&&$published['private_outputs_published']
            &&!file_exists($root.'/active-weights.json'),'publishing_analysis_never_activates_weights');
        $check($store->claimAnalysis()===false,'completed_analysis_is_not_executed_again');
        $reject(static fn()=>$store->activate(str_repeat('0',64),static fn(array $v):bool=>true),'study_review_hash');
        $reject(static fn()=>$store->activate(hash('sha256',$candidateRaw),static fn(array $v):bool=>false),'study_weights_not_eligible');
        $activated=$store->activate(hash('sha256',$candidateRaw),static fn(array $v):bool=>$v['weights_eligible']===true);
        $check($activated['activated']&&!$activated['already_active']&&file_get_contents($root.'/active-weights.json')===$candidateRaw,
            'explicit_reviewed_candidate_activation_copies_exact_private_bytes');
        $check($store->activate(hash('sha256',$candidateRaw),static fn(array $v):bool=>true)['already_active'],
            'activation_same_candidate_is_idempotent');
        $store->close();
        $read=$reader->readSummaryForScope($scope);
        $check($read['available']&&$read['complete']&&$read['counts']['payment_candidate_cases']===1
            &&$read['candidate_sha256']===hash('sha256',$candidateRaw),'completed_private_summary_readback');
        $check($reader->readSummaryForScope(str_repeat('8',64))===['available'=>false,'status'=>'scope_changed'],
            'withdrawn_scope_cannot_read_old_aggregates');
        $check($reader->status('synthetic')['analysis_complete']===true,'status_reads_completion_without_source_operations');
        $check(strlen(json_encode($read,JSON_THROW_ON_ERROR))<=ArchivePredictiveStudyStore::SUMMARY_BYTES,'summary_inside_wire_budget');
        $write($analysis.'/summary.json',$summaryRaw.' ');
        $check($reader->readSummaryForScope($scope)===['available'=>false,'status'=>'unavailable'],'tampered_summary_is_not_exposed');
        $write($analysis.'/summary.json',$summaryRaw);
        chmod($analysis.'/summary.json',0644);
        $check($reader->readSummaryForScope($scope)===['available'=>false,'status'=>'unavailable'],'public_file_mode_is_not_repaired_or_read');
        chmod($analysis.'/summary.json',0600);
        $badOwner=new ArchivePredictiveStudyStore($root,$uid+1);
        $check($badOwner->readSummaryForScope($scope)===['available'=>false,'status'=>'unavailable'],'wrong_owner_refused');
        $latest=file_get_contents($root.'/latest.json');
        $write($root.'/latest.json',json_encode(['run_id'=>'../outside'],JSON_THROW_ON_ERROR));
        $check($reader->readSummaryForScope($scope)===['available'=>false,'status'=>'unavailable'],'latest_pointer_cannot_choose_arbitrary_path');
        $write($root.'/latest.json',$latest);
        $write($root.'/synthetic/chunk_000001.ndjson',$chunk1.' ');
        $reject(static fn()=>$store->begin('synthetic',$provenance),'study_chunk_changed');
        $store->close();$write($root.'/synthetic/chunk_000001.ndjson',$chunk1);
        $link=$base.'/linked';symlink($root,$link);
        $check((new ArchivePredictiveStudyStore($link,$uid))->readSummaryForScope($scope)===['available'=>false,'status'=>'unavailable'],
            'symlink_root_refused');
        unlink($link);
        $public=$base.'/public';mkdir($public,0755);chmod($public,0755);
        $check((new ArchivePredictiveStudyStore($public,$uid))->readSummaryForScope($scope)===['available'=>false,'status'=>'unavailable'],
            'world_readable_root_refused');
        $store=new ArchivePredictiveStudyStore($root,$uid);
        $changed=$provenance;$changed['extractor_sha256']=str_repeat('0',64);
        $reject(static fn()=>$store->begin('synthetic',$changed),'study_provenance_changed');
        $store->close();
        $empty=new ArchivePredictiveStudyStore($base.'/empty',$uid);$empty->begin('empty',$provenance);
        $emptyPage=['project_key'=>'anytour','source_fence'=>'0','scope_digest'=>$scope,
            'expected'=>['desktop_records'=>0,'account_chat_pairs'=>0],'windows'=>[],'next_position'=>null,
            'has_more'=>false,'metadata_rows'=>0,'source_json_bytes'=>0,'returned_records'=>0];
        $emptyState=$empty->commitPage($emptyPage,[],['v'=>1]);
        $check($emptyState['complete']&&$emptyState['source_counts']['records']===0,'empty_corpus_can_complete_without_invented_records');
        $empty->close();
        // Derivation changes analysis provenance, never the original evidence or its privacy boundary.
        $parentFiles=['checkpoint.json','manifest.json','analysis-intent.json','analysis-complete.json',
            'chunk_000001.ndjson','chunk_000002.ndjson','analysis/summary.json','analysis/candidate_weights.json',
            'analysis/case_audits.jsonl','analysis/prefixes.jsonl'];
        $parentSnapshot=[];foreach($parentFiles as$name)$parentSnapshot[$name]=file_get_contents($root.'/synthetic/'.$name);
        $latestBefore=file_get_contents($root.'/latest.json');$activeBefore=file_get_contents($root.'/active-weights.json');
        $next=$provenance;$next['source_commit']=str_repeat('2',40);$next['analyzer_sha256']=str_repeat('0',64);
        $next['captured_at_unix']=1700099999;$next['code_hashes']['tools/archive_predictive_study.py']=$next['analyzer_sha256'];
        $next['code_hashes']['tools/archive_predictive_study.php']=str_repeat('5',64);
        $next['code_hashes']['services/ArchivePredictiveStudyStore.php']=str_repeat('6',64);
        $store=new ArchivePredictiveStudyStore($root,$uid);
        $reject(static fn()=>$store->deriveCompleted('synthetic','synthetic',$next,$scope),'study_derive_arguments');
        $reject(static fn()=>$store->deriveCompleted('synthetic','wrong-scope',$next,str_repeat('0',64)),'study_source_scope_changed');
        $check(!file_exists($root.'/wrong-scope'),'derivation_scope_failure_creates_no_target');
        foreach(['services/ArchiveResearchReadService.php','services/ArchiveResearchSemanticExtractor.php',
            'services/ManagerAiTextRedactor.php','services/InternalOwnerDialogueScope.php',
            'services/ArchivePredictiveStudyAtoms.php','services/ModeledConversionTextSignals.php']as$i=>$producer){
            $changed=$next;$changed['code_hashes'][$producer]=str_repeat('7',64);
            $reject(static fn()=>$store->deriveCompleted('synthetic','changed-'.$i,$changed,$scope),'study_derive_source_changed');
            $check(!file_exists($root.'/changed-'.$i),'changed_producer_never_creates_target_'.$i);
        }
        $write($root.'/synthetic/manifest.json',$parentSnapshot['manifest.json'].' ');
        $reject(static fn()=>$store->deriveCompleted('synthetic','changed-manifest',$next,$scope),'study_derive_parent_changed');
        $write($root.'/synthetic/manifest.json',$parentSnapshot['manifest.json']);
        $write($root.'/synthetic/analysis/prefixes.jsonl',"tampered\n");
        $reject(static fn()=>$store->deriveCompleted('synthetic','changed-prefixes',$next,$scope),'study_derive_parent_changed');
        $write($root.'/synthetic/analysis/prefixes.jsonl',$parentSnapshot['analysis/prefixes.jsonl']);
        $derived=$store->deriveCompleted('synthetic','derived',$next,$scope);
        $derivedManifestRaw=file_get_contents($root.'/derived/manifest.json');
        $derivedManifest=json_decode($derivedManifestRaw,true,64,JSON_THROW_ON_ERROR);
        $check($derived['run_id']==='derived'&&$derived['complete']&&$derived['position']===null
            &&$derived['provenance']['source_commit']===$next['source_commit']
            &&$derived['provenance']['analyzer_sha256']===$next['analyzer_sha256']
            &&$derived['provenance']['captured_at_unix']===$provenance['captured_at_unix'],
            'new_analysis_provenance_preserves_original_capture_clock');
        $original=json_decode($parentSnapshot['checkpoint.json'],true,64,JSON_THROW_ON_ERROR);
        foreach(['salt_hex','source_fence','scope_digest','expected','position','atom_state','chunks','chain_sha256',
            'source_counts','chat_pairs','last_pair','complete','total_chunk_bytes']as$key)
            $check($derived[$key]===$original[$key],'derivation_preserves_'.$key);
        $check($derivedManifest['lineage']===['kind'=>'completed_atoms_copy_v1','parent_run_id'=>'synthetic',
            'parent_manifest_sha256'=>hash('sha256',$parentSnapshot['manifest.json'])]
            &&$derivedManifest['source_commit']===$next['source_commit']
            &&$derivedManifest['analyzer_sha256']===$next['analyzer_sha256']
            &&hash('sha256',$derivedManifestRaw)!==hash('sha256',$parentSnapshot['manifest.json']),
            'new_manifest_binds_new_algorithm_and_original_parent_digest');
        foreach(['chunk_000001.ndjson','chunk_000002.ndjson']as$name){
            $from=stat($root.'/synthetic/'.$name);$to=stat($root.'/derived/'.$name);
            $check(file_get_contents($root.'/derived/'.$name)===$parentSnapshot[$name]
                &&$from['ino']!==$to['ino']&&$from['nlink']===1&&$to['nlink']===1
                &&($to['mode']&0077)===0,'copied_private_chunk_has_distinct_inode_'.$name);
        }
        foreach($parentFiles as$name)$check(file_get_contents($root.'/synthetic/'.$name)===$parentSnapshot[$name],
            'derivation_never_rewrites_parent_'.$name);
        $check(file_get_contents($root.'/latest.json')===$latestBefore
            &&file_get_contents($root.'/active-weights.json')===$activeBefore
            &&!file_exists($root.'/derived/analysis'),'derivation_neither_publishes_nor_activates');
        $check($store->claimAnalysis()===true,'derived_analysis_has_its_own_single_intent');
        $reject(static fn()=>$store->claimAnalysis(),'study_analysis_unknown');$store->close();
        $derivedIntent=file_get_contents($root.'/derived/analysis-intent.json');
        $reject(static fn()=>$store->deriveCompleted('synthetic','derived',$next,$scope),'study_derive_target_exists');
        $check(file_get_contents($root.'/derived/analysis-intent.json')===$derivedIntent,
            'existing_unknown_derived_analysis_is_never_reset');
        $resumed=$store->begin('derived',$next);
        $check($resumed['lineage']===$derived['lineage']&&$resumed['provenance']===$derived['provenance'],
            'matching_completed_copy_resumes_without_new_capture_time');
        $reject(static fn()=>$store->claimAnalysis(),'study_analysis_unknown');
        $newAnalysis=$root.'/derived/analysis';mkdir($newAnalysis,0700);
        $newSummary=$summary;$newCandidate=$candidate;
        foreach(['run_id'=>'derived','manifest_sha256'=>hash('sha256',$derivedManifestRaw),
            'protocol_sha256'=>str_repeat('9',64)]as$key=>$value){$newSummary[$key]=$value;$newCandidate[$key]=$value;}
        $write($newAnalysis.'/summary.json',json_encode($newSummary,JSON_THROW_ON_ERROR));
        $write($newAnalysis.'/candidate_weights.json',json_encode($newCandidate,JSON_THROW_ON_ERROR));
        $write($newAnalysis.'/case_audits.jsonl',"{}\n");$write($newAnalysis.'/prefixes.jsonl',"{}\n");
        $check($store->publishAnalysis()['completed']&&$store->claimAnalysis()===false,
            'derived_analysis_can_complete_once_under_new_manifest');
        $store->close();
        $check($reader->readSummaryForScope($scope)['run_id']==='derived'
            &&file_get_contents($root.'/active-weights.json')===$activeBefore,
            'derived_summary_readable_without_weights_activation');
        foreach($parentFiles as$name)$check(file_get_contents($root.'/synthetic/'.$name)===$parentSnapshot[$name],
            'new_analysis_still_never_rewrites_parent_'.$name);
        mkdir($root.'/interrupted-copy',0700);$write($root.'/interrupted-copy/derive-intent.json','{}');
        $reject(static fn()=>$store->deriveCompleted('synthetic','interrupted-copy',$next,$scope),'study_derive_target_exists');
        $reject(static fn()=>$store->begin('interrupted-copy',$next),'study_existing_intent');$store->close();
        $check(file_get_contents($root.'/interrupted-copy/derive-intent.json')==='{}',
            'partial_copy_intent_is_preserved_and_cannot_be_reset');
        $store->begin('unfinished',$provenance);$store->commitPage($emptyPage,[],['v'=>1]);$store->claimAnalysis();$store->close();
        $unfinishedIntent=file_get_contents($root.'/unfinished/analysis-intent.json');
        $reject(static fn()=>$store->deriveCompleted('unfinished','from-unknown',$next,$scope),'study_derive_parent_incomplete');
        $check(!file_exists($root.'/from-unknown')&&file_get_contents($root.'/unfinished/analysis-intent.json')===$unfinishedIntent,
            'unknown_parent_analysis_cannot_be_derived_or_replayed');
        $store->begin('unfinished',$provenance);
        $unfinishedManifestRaw=file_get_contents($root.'/unfinished/manifest.json');
        $ineligibleSummary=$summary;$ineligibleCandidate=$candidate;
        foreach(['run_id'=>'unfinished','source_fence'=>'0','manifest_sha256'=>hash('sha256',$unfinishedManifestRaw),
            'weights_eligible'=>false]as$key=>$value){$ineligibleSummary[$key]=$value;$ineligibleCandidate[$key]=$value;}
        $ineligibleSummary['counts']=['payment_candidate_cases'=>0];$ineligibleSummary['fit']=['fitted'=>false];
        $ineligibleAnalysis=$root.'/unfinished/analysis';mkdir($ineligibleAnalysis,0700);
        $write($ineligibleAnalysis.'/summary.json',json_encode($ineligibleSummary,JSON_THROW_ON_ERROR));
        $write($ineligibleAnalysis.'/candidate_weights.json',json_encode($ineligibleCandidate,JSON_THROW_ON_ERROR));
        $write($ineligibleAnalysis.'/case_audits.jsonl',"");$write($ineligibleAnalysis.'/prefixes.jsonl',"");
        $check($store->publishAnalysis()['weights_eligible']===false,'completed_ineligible_analysis_is_valid_parent_evidence');
        $store->close();$ineligibleCopy=$store->deriveCompleted('unfinished','from-ineligible',$next,$scope);
        $check($ineligibleCopy['complete']&&$ineligibleCopy['chunks']===[]&&!file_exists($root.'/from-ineligible/analysis')
            &&file_get_contents($root.'/active-weights.json')===$activeBefore,
            'ineligible_parent_can_be_reanalysed_without_implicit_activation');
        $store->close();
        return $passed;
    }finally{
        if($store!==null)$store->close();if($other!==null)$other->close();$remove($base);
    }
}
