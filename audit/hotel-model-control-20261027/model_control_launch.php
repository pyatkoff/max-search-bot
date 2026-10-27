<?php
declare(strict_types=1);
// Default is credential-free preparation. --execute needs a NEW explicit paid admission.
umask(0077);
$args=getopt('', ['prepare','execute','prepared:','approval:']);
$execute=isset($args['execute']);
if ($execute && isset($args['prepare'])) throw new RuntimeException('choose_one_mode');
$prepared=$args['prepared']??null;
if (!is_string($prepared)||$prepared===''||$prepared[0]!=='/') throw new RuntimeException('absolute_prepared_directory_required');
if (defined('OPENAI_API_KEY')) throw new RuntimeException('credentials_must_not_be_preloaded');
require __DIR__.'/model_control_fixture.php';
const CONTROL_SCOPE='JAZ99_SUNRISE1554_ISOLATED_MAX2_PAID_GENERATIONS';
function demand(bool $ok,string $error): void {if(!$ok)throw new RuntimeException($error);}
function bytes(array $x): string {return json_encode($x,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
function readJson(string $p): array {$x=json_decode(file_get_contents($p),true,64,JSON_THROW_ON_ERROR);demand(is_array($x),'invalid_json');return $x;}
function writePrivate(string $p,array $x): void {demand(file_put_contents($p,bytes($x)."\n",LOCK_EX)!==false,'write_failed');chmod($p,0600);}
function profile(array $body): void {
    demand(($body['model']??null)==='gpt-6.1-sol'&&($body['reasoning']['effort']??null)==='low'
        &&($body['service_tier']??null)==='default'&&($body['store']??null)===false
        &&($body['max_output_tokens']??null)===2200,'unapproved_canonical_profile');
}
function pins(string $repo): array {
    $files=['ai/ManagerDraftClient.php','services/ManagerAiDraftService.php','services/ManagerPreparedReplyService.php',
        'services/ManagerAiDraftContextService.php','services/ManagerAiDraftReferenceContext.php',
        'services/ManagerCompanyKnowledge.php','services/TourConsultationPolicy.php','tests/run_manager_prepared_reply_regression.php',
        'services/ManagerHotelKnowledge.php','services/ManagerHotelReferenceContext.php','knowledge/hotels/manifest.json',
        'knowledge/hotels/anytour-99-apartments.json','knowledge/hotels/anytour-1554-family-suite.json'];
    $out=[];foreach($files as $f)$out[$f]=hash_file('sha256',$repo.'/'.$f);
    // The unchanged canonical client itself fixes connect3s/timeout20s/no redirects/TLS.
    $client=file_get_contents($repo.'/ai/ManagerDraftClient.php');
    demand(str_contains($client,'CURLOPT_TIMEOUT=>20')&&str_contains($client,'CURLOPT_CONNECTTIMEOUT=>3'),'transport_timeout_changed');
    foreach(['model_control_launch.php','model_control_fixture.php','model_control_provider_once.php','model_control_provider_rpc.php','model_control_provider_remote.php','model-case-plan.json','RUN_SPEC.json'] as $f)$out['control/'.$f]=hash_file('sha256',__DIR__.'/'.$f);
    return $out;
}
function canonicalProviderOnce(string $instructions,array $payload,array $images,string $expectedHash): array {
    // The child loads existing credentials in place; the parent never receives a key or DB config.
    $child=getenv('HOTEL_CONTROL_PROVIDER_RPC')==='1'?'model_control_provider_rpc.php':'model_control_provider_once.php';
    $pipes=[];$process=proc_open([PHP_BINARY,__DIR__.'/'.$child],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,__DIR__);
    demand(is_resource($process),'provider_child_start_failed');
    $input=bytes(['instructions'=>$instructions,'payload'=>$payload,'images'=>$images,'expected_request_sha256'=>$expectedHash]);
    demand(strlen($input)<=393216,'provider_child_input_bound');
    $offset=0;while($offset<strlen($input)){$n=fwrite($pipes[0],substr($input,$offset));demand(is_int($n)&&$n>0,'provider_child_input_failed');$offset+=$n;}
    fclose($pipes[0]);$raw=stream_get_contents($pipes[1],262145);fclose($pipes[1]);
    // Child stderr is discarded, never copied into a public log or receipt.
    stream_get_contents($pipes[2],4096);fclose($pipes[2]);$code=proc_close($process);
    demand($code===0&&is_string($raw)&&strlen($raw)<=262144,'provider_child_outcome_unknown');
    $out=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
    demand(is_array($out)&&is_array($out['result']??null)&&is_array($out['metadata']??null)
        &&is_bool($out['canonical_request_attempted']??null),'provider_child_invalid_result');
    return $out;
}
function caseRuntime(array $entry,array $case,string $at): array {
    [$pdo,$snapshot,$job]=controlFixture($entry,$case['latest_native_inquiry_body_suffix'],null,$case['selected_room'],$at);
    demand($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'&&!PreparedReplyPdo::$mysqlFixture,'not_isolated_sqlite');
    $transport=static function(array $urls): array {throw new RuntimeException('external_reader_forbidden');};
    $selection=static fn(): ManagerSelectionSnapshot=>ManagerSelectionSnapshot::read($snapshot,$transport);
    $photos=isset($snapshot['photo_context'])?static fn(array $context): ?array=>$snapshot['photo_context']->read($context):null;
    $input=ManagerAiDraftService::prepareInput($snapshot['context'],$snapshot['company_knowledge'],$selection,$snapshot['reference_context'],$photos);
    demand(!isset($input['error'])&&($input['preflight']??null)===null,'non_model_preflight_or_invalid_context');
    $worker=ManagerPreparedReplyService::context($job,$transport);
    demand(!empty($worker['ok'])&&$worker['context']===$input['context']&&($worker['image_inputs']??[])===($input['images']??[]),'worker_capture_mismatch');
    $hotel=$input['context']['hotel_reference']??[];
    $bundle=readJson(__DIR__.'/repo/knowledge/hotels/'.$case['bundle_file']);
    demand(($hotel['status']??null)==='available'&&$hotel['hotel_ref']===$case['hotel_ref']
        &&$hotel['facts']===$bundle['facts']&&count($hotel['facts'])===6&&count($hotel['sources'])===3
        &&$hotel['sources']===$bundle['sources']&&$hotel['material_limits']===$bundle['material_limits']
        &&$hotel['record_hashes']===$bundle['record_hashes'],'whole_packet_not_retained');
    foreach($case['primary_fact_ids'] as $id)demand(in_array($id,array_column($hotel['facts'],'id'),true),'new_fact_missing');
    demand(!str_contains(bytes($worker),'PRIVATE'),'private_fixture_identity_leak');
    $body=ManagerDraftClient::requestBody(ManagerAiDraftService::instructions($input['context']),$input['context'],$input['images']??[]);profile($body);
    $plain=$input['context'];unset($plain['hotel_reference']);
    $plainBody=ManagerDraftClient::requestBody(ManagerAiDraftService::instructions($plain),$plain,$input['images']??[]);
    $increment=strlen(bytes($body))-strlen(bytes($plainBody));
    demand($increment<=16384&&strlen(bytes($hotel))<=16384&&strlen(bytes($body))<=196608,'request_budget_exceeded');
    return compact('pdo','snapshot','job','selection','photos','input','body','increment');
}
function requestMetadata(array $rt,array $case): array {
    return ['case_id'=>$case['case_id'],'hotel_ref'=>$case['hotel_ref'],'bundle_sha256'=>$case['bundle_sha256'],
        'snapshot_revision'=>$rt['snapshot']['revision'],'request_sha256'=>hash('sha256',bytes($rt['body'])),
        'request_bytes'=>strlen(bytes($rt['body'])),'hotel_encoded_increment_bytes'=>$rt['increment'],
        'normalized_context'=>$rt['input']['context'],'request_body'=>$rt['body'],'image_inputs'=>$rt['input']['images']??[]];
}
$repo=realpath(__DIR__.'/repo');demand(is_string($repo),'repo_missing');
$plan=readJson(__DIR__.'/model-case-plan.json');demand(count($plan['cases'])===2,'two_cases_required');
$manifest=readJson($repo.'/knowledge/hotels/manifest.json');demand(count($manifest['bindings'])===15,'cohort_changed');
$entries=[];foreach($manifest['bindings'] as $entry)$entries[$entry['accepted_binding']['hotel_ref']['id']]=$entry;
foreach($plan['cases'] as $case)demand(hash_file('sha256',$repo.'/knowledge/hotels/'.$case['bundle_file'])===$case['bundle_sha256'],'bundle_seal_changed');
if(!$execute){
    demand(!file_exists($prepared)&&mkdir($prepared,0700,true),'fresh_prepared_directory_required');
    $prepared=realpath($prepared);$at=gmdate('Y-m-d H:i:s',time()-60);$metadata=[];
    foreach($plan['cases'] as $case){
        $rt=caseRuntime($entries[$case['hotel_id']],$case,$at);$record=requestMetadata($rt,$case);
        writePrivate($prepared.'/'.$case['case_id'].'.json',$record);$metadata[]=['case_id'=>$case['case_id'],'request_sha256'=>$record['request_sha256']];
        $rt['pdo']->mutate("INSERT INTO messages VALUES(3,101,'inbound','customer','Новый обычный ввод','2026-10-27 10:02:00','telegram',NULL,'synthetic','synthetic')");
        demand(!(ManagerPreparedReplyService::context($rt['job'])['ok']??false),'later_inbound_not_suspended');
    }
    $frozen=['schema'=>'two_hotel_model_control_prepared_v1','status'=>'NOT_ISSUED','run_id'=>bin2hex(random_bytes(16)),
        'prepared_directory'=>$prepared,'frozen_latest_message_at_utc'=>$at,'source_pins'=>pins($repo),'cases'=>$metadata,
        'scope'=>CONTROL_SCOPE,'profile'=>['model'=>'gpt-6.1-sol','reasoning'=>'low','service_tier'=>'default','store'=>false,'max_output_tokens'=>2200,'timeout_seconds'=>20],
        'max_paid_calls'=>2,'model_calls'=>0,'natural_use_verified'=>false];
    writePrivate($prepared.'/prepared-manifest.json',$frozen);
    writePrivate($prepared.'/approval-required.json',['schema'=>'hotel_model_control_operator_admission_v1','operator_authorized'=>false,
        'operator_receipt'=>'REQUIRED_NEW_EXPLICIT_PAID_OPERATION_RECEIPT','scope'=>CONTROL_SCOPE,
        'prepared_directory'=>$prepared,'prepared_manifest_sha256'=>hash_file('sha256',$prepared.'/prepared-manifest.json'),
        'not_before_utc'=>'REQUIRED_UTC_START','deadline_utc'=>'REQUIRED_FUTURE_UTC_DEADLINE','max_paid_calls'=>2,'model'=>'gpt-6.1-sol',
        'reasoning'=>'low','service_tier'=>'default','max_output_tokens_per_call'=>2200,'timeout_seconds'=>20]);
    echo bytes(['status'=>'PREPARED_ZERO_CALLS','prepared_directory'=>$prepared,'cases'=>2])."\n";exit(0);
}
// No credential-bearing file is loaded until every explicit operator gate passes.
demand(is_dir($prepared),'prepared_directory_missing');$prepared=realpath($prepared);
$frozen=readJson($prepared.'/prepared-manifest.json');
demand($frozen['prepared_directory']===$prepared&&$frozen['source_pins']===pins($repo),'frozen_source_or_control_changed');
$approvalPath=$args['approval']??null;demand(is_string($approvalPath)&&is_file($approvalPath),'new_operator_admission_required');
$approval=readJson($approvalPath);
demand(($approval['schema']??null)==='hotel_model_control_operator_admission_v1'&&($approval['operator_authorized']??null)===true
    &&is_string($approval['operator_receipt']??null)&&strlen($approval['operator_receipt'])>=12
    &&!str_contains($approval['operator_receipt'],'REQUIRED')&&($approval['scope']??null)===CONTROL_SCOPE,'explicit_paid_operator_receipt_required');
demand(($approval['prepared_directory']??null)===$prepared&&($approval['prepared_manifest_sha256']??null)===hash_file('sha256',$prepared.'/prepared-manifest.json'),'approval_not_bound_to_frozen_run');
foreach(['max_paid_calls'=>2,'model'=>'gpt-6.1-sol','reasoning'=>'low','service_tier'=>'default','max_output_tokens_per_call'=>2200,'timeout_seconds'=>20] as $k=>$v)demand(($approval[$k]??null)===$v,'approval_profile_or_budget_mismatch');
$parseUtc=static function($s): int {demand(is_string($s)&&preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D',$s)===1,'explicit_utc_window_required');$x=strtotime($s);demand($x!==false,'invalid_utc_window');return $x;};
$start=$parseUtc($approval['not_before_utc']??null);$deadline=$parseUtc($approval['deadline_utc']??null);
demand($start<=time()&&$deadline>time()&&$deadline>$start,'outside_paid_operator_window');
$out=$prepared.'/execution';demand(!file_exists($out)&&mkdir($out,0700),'execution_already_exists_do_not_retry');
$handle=fopen($out.'/attempt-receipt.json','x');demand($handle!==false,'exclusive_attempt_receipt_failed');flock($handle,LOCK_EX);
$receipt=['schema'=>'two_hotel_model_attempt_receipt_v1','run_id'=>$frozen['run_id'],'operator_receipt'=>$approval['operator_receipt'],
    'status'=>'APPROVED_NOT_ISSUED','calls_issued'=>0,'cases'=>[],'actual_model_use_verified'=>false,'semantic_accuracy_accepted'=>false,'natural_use_verified'=>false];
$persist=static function()use(&$receipt,$handle):void{rewind($handle);ftruncate($handle,0);demand(fwrite($handle,bytes($receipt)."\n")!==false,'receipt_write_failed');fflush($handle);if(function_exists('fsync'))fsync($handle);};$persist();
try{
    foreach($plan['cases'] as $index=>$case){
        demand(time()+25<$deadline,'insufficient_paid_operator_window');
        demand($frozen['source_pins']===pins($repo),'source_drift_before_case');
        $rt=caseRuntime($entries[$case['hotel_id']],$case,$frozen['frozen_latest_message_at_utc']);
        $record=requestMetadata($rt,$case);$expected=readJson($prepared.'/'.$case['case_id'].'.json');
        demand($record['request_sha256']===$expected['request_sha256'],'frozen_body_hash_changed');
        $before=preparedReplySourceState($rt['pdo']);$used=false;$usage=[];
        $receipt['cases'][$index]=['case_id'=>$case['case_id'],'status'=>'READY_NOT_ISSUED','request_sha256'=>$record['request_sha256']];$persist();
        $provider=static function(string $instructions,array $payload,array $images)use(&$used,&$usage,&$receipt,$persist,$index,$expected,$deadline):array{
            demand(!$used&&$receipt['calls_issued']<2&&time()+25<$deadline,'duplicate_or_expired_attempt');
            $body=ManagerDraftClient::requestBody($instructions,$payload,$images);profile($body);
            demand(hash('sha256',bytes($body))===$expected['request_sha256'],'provider_frozen_body_mismatch');
            $used=true;++$receipt['calls_issued'];$receipt['status']='ISSUED_OUTCOME_UNKNOWN';
            $receipt['cases'][$index]['status']='ISSUED_OUTCOME_UNKNOWN';$receipt['cases'][$index]['issued_at_utc']=gmdate('Y-m-d\TH:i:s\Z');$persist();
            $providerOutput=canonicalProviderOnce($instructions,$payload,$images,$expected['request_sha256']);
            $result=$providerOutput['result'];$usage=$providerOutput['metadata'];
            $receipt['cases'][$index]['canonical_request_attempted']=$providerOutput['canonical_request_attempted'];
            $receipt['cases'][$index]['provider_result']=$result;$receipt['cases'][$index]['usage']=$usage;$receipt['cases'][$index]['status']='PROVIDER_RETURNED_UNVALIDATED';$persist();return $result;
        };
        $result=ManagerAiDraftService::generate($rt['snapshot']['context'],$provider,$rt['snapshot']['company_knowledge'],$rt['selection'],$rt['snapshot']['reference_context'],$rt['photos']);
        demand($used&&!empty($result['ok'])&&!empty($result['should_draft'])&&is_string($result['draft']??null),'model_result_not_valid_draft_stop');
        demand($frozen['source_pins']===pins($repo),'source_drift_after_model_call');
        $saved=ManagerPreparedReplyService::complete($rt['job'],$rt['snapshot']['revision'],$result);
        $ready=ManagerPreparedReplyService::prepared(101,1,$rt['snapshot']);
        demand(!empty($saved['ok'])&&!empty($ready['prepared'])&&$ready['proposal']===$result&&$ready['proposal']['draft']===$result['draft'],'exact_generated_text_readback_failed');
        $cache=$rt['pdo']->query('SELECT status,proposal_json,proposal_sha256 FROM manager_prepared_replies')->fetchAll();
        demand(count($cache)===1&&$cache[0]['status']==='ready'&&hash_equals($cache[0]['proposal_sha256'],hash('sha256',$cache[0]['proposal_json'])),'cache_hash_mismatch');
        demand(preparedReplySourceState($rt['pdo'])===$before&&$rt['pdo']->sourceWriteAttempts===0,'source_write_detected');
        demand($frozen['source_pins']===pins($repo),'source_drift_after_readback');
        $receipt['cases'][$index]+=['accepted_generated_proposal'=>$result,'cache_proposal_sha256'=>$cache[0]['proposal_sha256'],'exact_readback'=>true,'context_revision'=>$rt['snapshot']['revision']];
        $receipt['cases'][$index]['status']='MODEL_TEXT_SAVED_READBACK_PENDING_SEMANTIC_REVIEW';$receipt['actual_model_use_verified']=true;$persist();
    }
    $receipt['status']='TWO_MODEL_TEXTS_SAVED_READBACK_PENDING_SEMANTIC_REVIEW';$persist();
}catch(Throwable $e){$receipt['status']='STOPPED_NO_RETRY_MANUAL_RECONCILIATION';$receipt['error_code']='control_failed_or_unknown';$persist();fclose($handle);fwrite(STDERR,"Control stopped; inspect private attempt receipt. No automatic retry.\n");exit(1);}
fclose($handle);echo bytes(['status'=>$receipt['status'],'calls_issued'=>$receipt['calls_issued'],'private_receipt'=>$out.'/attempt-receipt.json'])."\n";
