<?php
declare(strict_types=1);
// One fixed read-only source receipt; exact reviewed consumer is evaluated in memory.
ini_set('display_errors','0');ini_set('log_errors','0');set_time_limit(35);
$buffer=ob_get_level();ob_start();$pdo=null;$phase='bootstrap';
try {
    $issued=__ISSUED_EPOCH__;$expires=__EXPIRES_EPOCH__;
    if (time()<$issued-60||time()>$expires) throw new RuntimeException();
    $root=dirname(getcwd());
    if ($root!=='/var/www/anytoour/data/www/app.anytoour.ru') throw new RuntimeException();
    require_once $root.'/config.php';
    require_once $root.'/services/ProjectConfig.php';
    require_once $root.'/services/TravelDirectoryRepository.php';
    if (ProjectConfig::projectId()!=='anytour') throw new RuntimeException();
    $phase='readonly_directory';
    $pdo=HotelDatabase::connection();
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('SET TRANSACTION READ ONLY');
    if (!$pdo->beginTransaction()) throw new RuntimeException();
    $phase='consumer_code';
    $source=base64_decode('__READER_BASE64__',true);
    if (!is_string($source)||hash('sha1','blob '.strlen($source)."\0".$source)!=='ce7cb461e03cedbb3b81a3548d7f2ccff9ccde48'
        ||!str_starts_with($source,'<?php')) throw new RuntimeException();
    eval(substr($source,5));
    $fetch=new ReflectionMethod(ManagerSearch3CandidateReader::class,'fetch');
    $http=[];$directories=[];$selectedId=null;$latestPlan=null;$aiGuardBlocked=false;
    $transport=static function(string $url,array $envelope,array $limits) use($fetch,&$http,&$selectedId,&$latestPlan,&$aiGuardBlocked): array {
        if (isset($envelope['params'])) {
            if ($selectedId===null||!is_array($latestPlan)||($latestPlan['id']??null)!==$selectedId
                ||($latestPlan['code']??null)!=='AI'||!is_array($latestPlan['nativeIds']??null)
                ||count($latestPlan['nativeIds'])!==1||!is_string($latestPlan['nativeIds'][0]??null)
                ||trim($latestPlan['nativeIds'][0])!==$latestPlan['nativeIds'][0]
                ||($envelope['params']['meal']??null)!==$latestPlan['nativeIds'][0]) {
                $aiGuardBlocked=true;throw new RuntimeException();
            }
        }
        if (count($http)>=3) throw new RuntimeException();
        $index=count($http);
        $http[]=['kind'=>isset($envelope['action'])?'meal_catalogue':'listing',
            'timeout_ms'=>$limits['timeout_ms'],'max_bytes'=>$limits['max_bytes']];
        $response=$fetch->invoke(null,$url,$envelope,$limits);
        $http[$index]['status']=is_int($response['status']??null)?$response['status']:null;
        $http[$index]['body_bytes']=is_string($response['body']??null)?strlen($response['body']):0;
        if (isset($envelope['action'])&&$selectedId!==null) {
            $latestPlan=null;
            try {
                $wire=json_decode($response['body']??'',true,48,JSON_THROW_ON_ERROR);
                foreach ($wire['data']['plans']??[] as $plan) {
                    if (is_array($plan)&&($plan['id']??null)===$selectedId) {$latestPlan=$plan;break;}
                }
            } catch (Throwable $ignoredCatalogue) {}
        }
        return $response;
    };
    $directory=static function(string $kind,string $name) use(&$directories) {
        if (count($directories)>=2||!in_array($kind,['departure','country'],true)) throw new RuntimeException();
        $row=$kind==='departure'?TravelDirectoryRepository::cityByName(null,$name):TravelDirectoryRepository::countryByName(null,$name);
        $directories[]=['kind'=>$kind,'requested_name'=>$name,'resolved_id'=>is_array($row)?($row['ID']??null):null,
            'resolved_name'=>is_array($row)?($row['NAME']??null):null];
        return $row;
    };
    $reader=new ManagerSearch3CandidateReader($transport,$directory);
    $args=['departure'=>'Москва','country'=>'Египет','date_from'=>'2026-11-08','date_to'=>'2026-11-08',
        'nights_min'=>7,'nights_max'=>7,'adults'=>2,'child_ages'=>[],'limit'=>3];
    $phase='catalogue';
    $catalogue=$reader->readMealCatalog([]);
    $matches=[];
    if (($catalogue['ok']??false)===true) {
        foreach ($catalogue['plans'] as $plan) if ($plan['code']==='AI') $matches[]=$plan;
    }
    $result=null;$status='hold';$reason='no_unique_canonical_ai_plan';
    if (count($matches)===1) {
        $phase='candidate';
        // This is an actual canonical catalogue record, never a legacy meal-number lookup.
        $selectedId=$matches[0]['meal_plan_id'];$latestPlan=null;
        $args['meal_plan_id']=$selectedId;
        $result=$reader->read($args);
        if (($result['ok']??false)===true) {
            $status=$result['candidates']===[]?'empty_retained_scope':'candidates_observed';$reason=null;
        } else $reason=$aiGuardBlocked?'ai_plan_changed_before_listing':($result['reason']??'candidate_unavailable');
    } elseif (($catalogue['ok']??false)!==true) $reason=$catalogue['reason']??'catalogue_unavailable';
    $phase='rollback';
    $pdo->rollBack();
    $receipt=['source'=>'ai1150_actual_source_receipt','schema_version'=>1,
        'operation'=>'ai1150-source-receipt-20261026-v1','status'=>$status,'reason'=>$reason,
        'captured_at'=>gmdate('Y-m-d\TH:i:s\Z'),'candidate_reader_blob'=>'ce7cb461e03cedbb3b81a3548d7f2ccff9ccde48',
        'execution'=>'reviewed_candidate_in_memory_not_installed','requested_scope'=>$args,
        'requested_meal_code'=>'AI','catalogue'=>$catalogue,'matching_ai_plan_count'=>count($matches),
        'candidate_result'=>$result,'directory_bindings'=>$directories,'http_requests'=>$http,
        'http_request_count'=>count($http),'candidate_count'=>is_array($result)?count($result['candidates']??[]):0,
        'read_only_directory_transaction'=>true,'directory_transaction_rolled_back'=>true,
        'database_writes_requested'=>0,'supplier_search_requested'=>false,'customer_sends_requested'=>0,
        'unfiltered_fallback'=>false,'prices_currently_verified'=>false];
    $json=json_encode($receipt,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    if (strlen($json)>131072) throw new RuntimeException();
    while(ob_get_level()>$buffer) ob_end_clean();
    echo $json,"\n";
} catch (Throwable $ignored) {
    if ($pdo instanceof PDO&&$pdo->inTransaction()) {try {$pdo->rollBack();}catch(Throwable $ignoredRollback){}}
    while(ob_get_level()>$buffer) ob_end_clean();
    fwrite(STDERR,'fixed_ai1150_receipt_failed:'.$phase."\n");exit(1);
}
