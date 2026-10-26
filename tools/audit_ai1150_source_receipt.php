<?php
declare(strict_types=1);
// Continue the original discovery -> canonical selection -> candidate flow; never repeat discovery.
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
    $phase='discovery_proof';
    $discoveryRaw=base64_decode('eyJyZWNlaXB0Ijp7ImNhbmRpZGF0ZV9jb3VudCI6MCwiY2FuZGlkYXRlX3JlYWRlcl9ibG9iIjoiY2U3Y2I0NjFlMDNjZWRiYjNiODFhMzU0OGQ3ZjJjY2ZmOWNjZGU0OCIsImNhbmRpZGF0ZV9yZXN1bHQiOm51bGwsImNhcHR1cmVkX2F0IjoiMjAyNi0xMC0yNlQwNjo0NDowMVoiLCJjYXRhbG9ndWUiOnsiYXZhaWxhYmxlIjp0cnVlLCJjb21wbGV0ZSI6dHJ1ZSwibmV4dF9hY3Rpb24iOiJjaG9vc2Vfc3VwcG9ydGVkX21lYWxfcGxhbl9pZCIsIm9rIjp0cnVlLCJwbGFucyI6W3siY29kZSI6Im5vLW1lYWxzIiwiZmlsdGVyX3N1cHBvcnRlZCI6ZmFsc2UsIm1lYWxfcGxhbl9pZCI6MSwibmFtZV9ydSI6ItCR0LXQtyDQv9C40YLQsNC90LjRjyIsIm5hdGl2ZV9jb3VudCI6MH0seyJjb2RlIjoiYnJlYWtmYXN0IiwiZmlsdGVyX3N1cHBvcnRlZCI6dHJ1ZSwibWVhbF9wbGFuX2lkIjoyLCJuYW1lX3J1Ijoi0JfQsNCy0YLRgNCw0LrQuCIsIm5hdGl2ZV9jb3VudCI6MX0seyJjb2RlIjoiaGFsZi1ib2FyZCIsImZpbHRlcl9zdXBwb3J0ZWQiOnRydWUsIm1lYWxfcGxhbl9pZCI6MywibmFtZV9ydSI6ItCf0L7Qu9GD0L/QsNC90YHQuNC+0L0iLCJuYXRpdmVfY291bnQiOjF9LHsiY29kZSI6ImhhbGYtYm9hcmQtcGx1cyIsImZpbHRlcl9zdXBwb3J0ZWQiOmZhbHNlLCJtZWFsX3BsYW5faWQiOjQsIm5hbWVfcnUiOiLQn9C+0LvRg9C/0LDQvdGB0LjQvtC9INC/0LvRjtGBIiwibmF0aXZlX2NvdW50IjowfSx7ImNvZGUiOiJmdWxsLWJvYXJkIiwiZmlsdGVyX3N1cHBvcnRlZCI6ZmFsc2UsIm1lYWxfcGxhbl9pZCI6NSwibmFtZV9ydSI6ItCf0L7Qu9C90YvQuSDQv9Cw0L3RgdC40L7QvSIsIm5hdGl2ZV9jb3VudCI6MH0seyJjb2RlIjoiZnVsbC1ib2FyZC1wbHVzIiwiZmlsdGVyX3N1cHBvcnRlZCI6ZmFsc2UsIm1lYWxfcGxhbl9pZCI6NiwibmFtZV9ydSI6ItCf0L7Qu9C90YvQuSDQv9Cw0L3RgdC40L7QvSDQv9C70Y7RgSIsIm5hdGl2ZV9jb3VudCI6MH0seyJjb2RlIjoiYWxsLWluY2x1c2l2ZSIsImZpbHRlcl9zdXBwb3J0ZWQiOnRydWUsIm1lYWxfcGxhbl9pZCI6NywibmFtZV9ydSI6ItCS0YHRkSDQstC60LvRjtGH0LXQvdC+IiwibmF0aXZlX2NvdW50IjoxfSx7ImNvZGUiOiJ1bHRyYS1hbGwtaW5jbHVzaXZlIiwiZmlsdGVyX3N1cHBvcnRlZCI6dHJ1ZSwibWVhbF9wbGFuX2lkIjo4LCJuYW1lX3J1Ijoi0KPQu9GM0YLRgNCwINCy0YHRkSDQstC60LvRjtGH0LXQvdC+IiwibmF0aXZlX2NvdW50IjoxfSx7ImNvZGUiOiJzb2Z0LWFsbC1pbmNsdXNpdmUiLCJmaWx0ZXJfc3VwcG9ydGVkIjpmYWxzZSwibWVhbF9wbGFuX2lkIjo5LCJuYW1lX3J1Ijoi0JzRj9Cz0LrQvtC1INCy0YHRkSDQstC60LvRjtGH0LXQvdC+IiwibmF0aXZlX2NvdW50IjowfSx7ImNvZGUiOiJhbGNvaG9sLWZyZWUtYWxsLWluY2x1c2l2ZSIsImZpbHRlcl9zdXBwb3J0ZWQiOmZhbHNlLCJtZWFsX3BsYW5faWQiOjEwLCJuYW1lX3J1Ijoi0JLRgdGRINCy0LrQu9GO0YfQtdC90L4g0LHQtdC3INCw0LvQutC+0LPQvtC70Y8iLCJuYXRpdmVfY291bnQiOjB9XSwicHJvdmlkZXIiOiJ0b3Vydmlzb3IiLCJyZWFkX2F0IjoiMjAyNi0xMC0yNlQwNjo0NDowMVoiLCJyZXZpc2lvbiI6Ijg2NDJmNGJjZGI3ODY4NWYzMWUzY2FiYTk2NjhiZTE1N2RkMjY3Y2I5NjRlNDllMTk3ZjE5M2I4Y2NkOGVlMTgiLCJzY29wZV9rZXkiOiJnbG9iYWwiLCJzb3VyY2UiOiJzZWFyY2gzX21lYWxfY2F0YWxvZ3VlIiwic291cmNlX3RleHRfdW50cnVzdGVkIjp0cnVlLCJzdGF0ZSI6ImF2YWlsYWJsZSJ9LCJjdXN0b21lcl9zZW5kc19yZXF1ZXN0ZWQiOjAsImRhdGFiYXNlX3dyaXRlc19yZXF1ZXN0ZWQiOjAsImRpcmVjdG9yeV9iaW5kaW5ncyI6W10sImRpcmVjdG9yeV90cmFuc2FjdGlvbl9yb2xsZWRfYmFjayI6dHJ1ZSwiZXhlY3V0aW9uIjoicmV2aWV3ZWRfY2FuZGlkYXRlX2luX21lbW9yeV9ub3RfaW5zdGFsbGVkIiwiaHR0cF9yZXF1ZXN0X2NvdW50IjoxLCJodHRwX3JlcXVlc3RzIjpbeyJib2R5X2J5dGVzIjoxMTEzLCJraW5kIjoibWVhbF9jYXRhbG9ndWUiLCJtYXhfYnl0ZXMiOjQxOTQzMDQsInN0YXR1cyI6MjAwLCJ0aW1lb3V0X21zIjo0OTk5fV0sIm1hdGNoaW5nX2FpX3BsYW5fY291bnQiOjAsIm9wZXJhdGlvbiI6ImFpMTE1MC1zb3VyY2UtcmVjZWlwdC0yMDI2MTAyNi12MSIsInByaWNlc19jdXJyZW50bHlfdmVyaWZpZWQiOmZhbHNlLCJyZWFkX29ubHlfZGlyZWN0b3J5X3RyYW5zYWN0aW9uIjp0cnVlLCJyZWFzb24iOiJub191bmlxdWVfY2Fub25pY2FsX2FpX3BsYW4iLCJyZXF1ZXN0ZWRfbWVhbF9jb2RlIjoiQUkiLCJyZXF1ZXN0ZWRfc2NvcGUiOnsiYWR1bHRzIjoyLCJjaGlsZF9hZ2VzIjpbXSwiY291bnRyeSI6ItCV0LPQuNC/0LXRgiIsImRhdGVfZnJvbSI6IjIwMjYtMTEtMDgiLCJkYXRlX3RvIjoiMjAyNi0xMS0wOCIsImRlcGFydHVyZSI6ItCc0L7RgdC60LLQsCIsImxpbWl0IjozLCJuaWdodHNfbWF4Ijo3LCJuaWdodHNfbWluIjo3fSwic2NoZW1hX3ZlcnNpb24iOjEsInNvdXJjZSI6ImFpMTE1MF9hY3R1YWxfc291cmNlX3JlY2VpcHQiLCJzdGF0dXMiOiJob2xkIiwic3VwcGxpZXJfc2VhcmNoX3JlcXVlc3RlZCI6ZmFsc2UsInVuZmlsdGVyZWRfZmFsbGJhY2siOmZhbHNlfSwid3JhcHBlciI6eyJjYW5kaWRhdGVfcmVhZGVyX2Jsb2IiOiJjZTdjYjQ2MWUwM2NlZGJiM2I4MWEzNTQ4ZDdmMmNjZmY5Y2NkZTQ4IiwiZHJpdmVyX3NoYTI1NiI6IjViY2UxN2FkZDY4ZDM4Yzg0YWFkMjU4ZGMyZTZlZjhlZWJkNjcxOGU4YmExOTBkYjdhZmRiNjkyMDE2YzhkODkiLCJlZmZlY3RpdmVfZHJpdmVyX3NoYTI1NiI6IjYyZTczZTJjNGQ5NzFmMzgyYTM3NjAyNDFiNjgyZGM4M2I2MmEzOWRlMTgwZmVlNDhkMGY2OTU3NDQ1MGEzZTYiLCJleHBpcmVzX2Vwb2NoIjoxNzkyOTk3OTM3LCJpc3N1ZWRfZXBvY2giOjE3OTI5OTcwMzcsIm9wZXJhdGlvbiI6ImFpMTE1MC1zb3VyY2UtcmVjZWlwdC0yMDI2MTAyNi12MSIsInJldmlld2VkX3NvdXJjZV9zaGEiOiJlODdiYjUxMTJhOWM5OTUzOWZlNzU3MTZjZGQzNmY0NDNmNzJhNmEwIiwicnVuX2F0dGVtcHQiOjEsInJ1bl9pZCI6IjQwMjgxNTMxNDQ3IiwicnVudGltZV9zaGEiOiIxOWE4Mzk1MzZlMDJiNWZiOTAwNmQ0Y2FiNjQ3MTliMWZkZjg3NjlmIn19Cg==',true);
    if (!is_string($discoveryRaw)||hash('sha256',$discoveryRaw)!=='49f4721de2d61e8ee27fe0ff18cf78c176062238eeb71c2961d960cfa91fd8d7') throw new RuntimeException();
    $discovery=json_decode($discoveryRaw,true,48,JSON_THROW_ON_ERROR);
    $prior=$discovery['receipt']??null;
    if (!is_array($prior)||($prior['http_request_count']??null)!==1||!array_key_exists('candidate_result',$prior)||$prior['candidate_result']!==null
        ||($prior['http_requests'][0]['kind']??null)!=='meal_catalogue'
        ||($prior['http_requests'][0]['status']??null)!==200) throw new RuntimeException();
    $catalogue=$prior['catalogue'];
    if (($catalogue['ok']??null)!==true||($catalogue['complete']??null)!==true||($catalogue['available']??null)!==true
        ||($catalogue['provider']??null)!=='tourvisor'||($catalogue['scope_key']??null)!=='global'
        ||($catalogue['revision']??null)!=='8642f4bcdb78685f31e3caba9668be157dd267cb964e49e197f193b8ccd8ee18') throw new RuntimeException();
    $matches=[];
    foreach ($catalogue['plans'] as $plan) {
        if (($plan['meal_plan_id']??null)===7&&($plan['code']??null)==='all-inclusive'
            &&($plan['filter_supported']??null)===true&&($plan['native_count']??null)===1) $matches[]=$plan;
    }
    if (count($matches)!==1) throw new RuntimeException();
    $selectedId=$matches[0]['meal_plan_id'];$expectedRevision=$catalogue['revision'];
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
    $http=[];$directories=[];$latestPlan=null;$latestRevision=null;$bindingGuardBlocked=false;
    $transport=static function(string $url,array $envelope,array $limits) use($fetch,&$http,$selectedId,$expectedRevision,&$latestPlan,&$latestRevision,&$bindingGuardBlocked): array {
        if (isset($envelope['params'])) {
            if ($latestRevision!==$expectedRevision||!is_array($latestPlan)||($latestPlan['id']??null)!==$selectedId
                ||($latestPlan['code']??null)!=='all-inclusive'||!is_array($latestPlan['nativeIds']??null)
                ||count($latestPlan['nativeIds'])!==1||!is_string($latestPlan['nativeIds'][0]??null)
                ||trim($latestPlan['nativeIds'][0])!==$latestPlan['nativeIds'][0]
                ||($envelope['params']['meal']??null)!==$latestPlan['nativeIds'][0]) {
                $bindingGuardBlocked=true;throw new RuntimeException();
            }
        }
        if (count($http)>=2) throw new RuntimeException();
        $index=count($http);
        $http[]=['kind'=>isset($envelope['action'])?'meal_catalogue':'listing',
            'timeout_ms'=>$limits['timeout_ms'],'max_bytes'=>$limits['max_bytes']];
        $response=$fetch->invoke(null,$url,$envelope,$limits);
        $http[$index]['status']=is_int($response['status']??null)?$response['status']:null;
        $http[$index]['body_bytes']=is_string($response['body']??null)?strlen($response['body']):0;
        if (isset($envelope['action'])) {
            $latestPlan=null;$latestRevision=null;
            try {
                $wire=json_decode($response['body']??'',true,48,JSON_THROW_ON_ERROR);
                $latestRevision=$wire['data']['revision']??null;
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
        'nights_min'=>7,'nights_max'=>7,'adults'=>2,'child_ages'=>[],'limit'=>3,'meal_plan_id'=>$selectedId];
    $phase='candidate';
    // Exactly one first candidate call; read() performs only the planned mapping recheck + listing.
    $result=$reader->read($args);
    $status='hold';$reason=$bindingGuardBlocked?'canonical_binding_changed_before_listing':($result['reason']??'candidate_unavailable');
    if (($result['ok']??false)===true) {$status=$result['candidates']===[]?'empty_retained_scope':'candidates_observed';$reason=null;}
    $phase='rollback';$pdo->rollBack();
    $receipt=['source'=>'ai1150_actual_source_receipt','schema_version'=>1,
        'operation'=>'ai1150-source-receipt-20261026-v1','read_stage'=>'candidate_after_catalogue_discovery',
        'status'=>$status,'reason'=>$reason,'captured_at'=>gmdate('Y-m-d\TH:i:s\Z'),
        'candidate_reader_blob'=>'ce7cb461e03cedbb3b81a3548d7f2ccff9ccde48',
        'execution'=>'reviewed_candidate_in_memory_not_installed','requested_scope'=>$args,
        'requested_meal_code'=>'all-inclusive','selected_canonical_code'=>'all-inclusive',
        'catalogue_discovery_reused'=>true,
        'discovery_receipt_sha256'=>'49f4721de2d61e8ee27fe0ff18cf78c176062238eeb71c2961d960cfa91fd8d7',
        'catalogue'=>$catalogue,'matching_plan_count'=>count($matches),'candidate_result'=>$result,
        'directory_bindings'=>$directories,'http_requests'=>$http,
        'previous_http_request_count'=>1,'http_request_count'=>count($http),'total_http_request_count'=>1+count($http),
        'candidate_count'=>count($result['candidates']??[]),
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
