<?php
declare(strict_types=1);
require_once __DIR__.'/monitor-price-contract.php';

function monitor_two_need(bool $ok,string $reason):void { if(!$ok)throw new RuntimeException($reason); }
function monitor_two_code(Throwable $e):string {
    return preg_match('/\A[a-z][a-z0-9_]{1,100}\z/D',$e->getMessage())===1?$e->getMessage():'monitor_validation_exception';
}
function monitor_two_value($value):array {
    $out=['type'=>get_debug_type($value)];
    if(is_string($value)&&strlen($value)>1024)return $out+['value_omitted'=>true,'bytes'=>strlen($value),'sha256'=>hash('sha256',$value)];
    if(is_scalar($value)||$value===null)$out['value']=$value;
    return $out;
}
/** Individual conjuncts of the unchanged monitor_terms_invalid guard; no coercion/fallback. */
function monitor_two_term_failures(array $offer,int $tv,int $index):array {
    $meal=$offer['mealId']??0;$meals=monitor_price_meals();
    $mealAccepted=(is_int($meal)||is_string($meal)||is_float($meal)||is_bool($meal))&&isset($meals[$meal]);
    $checks=[
        'tourvisorHotelId'=>[($offer['tourvisorHotelId']??null)===$tv,'exact integer '.$tv],
        'departureId'=>[($offer['departureId']??null)===1,'exact integer 1'],
        'adults'=>[($offer['adults']??null)===2,'exact integer 2'],
        'childrenCount'=>[($offer['childrenCount']??null)===0,'exact integer 0'],
        'currency'=>[($offer['currency']??null)==='RUB','RUB'],
        'nights'=>[is_int($offer['nights']??null)&&$offer['nights']>=5&&$offer['nights']<=14,'integer from 5 to 14'],
        'tourId'=>[is_string($offer['tourId']??null)&&$offer['tourId']!=='','nonempty string'],
        'roomType'=>[is_string($offer['roomType']??null)&&trim($offer['roomType'])!=='','nonblank string'],
        'mealId'=>[$mealAccepted,'accepted meal ID: '.implode(',',array_keys($meals))],
    ];
    $failed=[];
    foreach($checks as $field=>[$valid,$expected])if(!$valid)$failed[]=[
        'path'=>'offers['.$index.'].'.$field,'field'=>$field,'present'=>array_key_exists($field,$offer),
        'actual'=>monitor_two_value($offer[$field]??null),'expected'=>$expected,
    ];
    return $failed;
}
function monitor_two_epoch($value):?int { try{return monitor_price_time($value);}catch(Throwable $e){return null;} }
function monitor_two_payload(array $payload,string $url,int $now,array $requested):array {
    monitor_two_need(($payload['ok']??null)===true,'two_monitor_read_failed');
    monitor_two_need(($payload['supplierRequests']??null)===0&&($payload['writes']??null)===0,'two_monitor_read_only_contract_changed');
    $scope=$payload['scope']??[];$ids=$scope['tourvisorHotelIds']??null;
    monitor_two_need(is_array($ids)&&count($ids)===2&&count(array_filter($ids,'is_int'))===2,'two_monitor_identity_scope_changed');
    sort($ids);
    monitor_two_need($ids===[1142,43060]&&($scope['departureId']??null)===1
        &&($scope['dateFrom']??null)===($requested['dateFrom']??null)&&($scope['dateTo']??null)===($requested['dateTo']??null),'two_monitor_identity_scope_changed');
    $items=$payload['items']??null;
    monitor_two_need(is_array($items)&&count($items)===2,'two_monitor_items_scope_changed');
    $byId=[];
    foreach($items as $item){
        monitor_two_need(is_array($item)&&in_array($item['tourvisorHotelId']??null,[1142,43060],true)
            &&!isset($byId[$item['tourvisorHotelId']]),'two_monitor_items_scope_changed');
        $byId[$item['tourvisorHotelId']]=$item;
    }
    $result=['requested_scope'=>$requested,'generated_at'=>$payload['generatedAt']??null,
        'source_time_basis'=>$payload['timeBasis']??null,'hotels'=>[]];
    foreach([1142,43060] as $tv){
        $snapshot=$payload;unset($snapshot['ok'],$snapshot['items']);
        $snapshot['item']=$byId[$tv];$snapshot['sourceUrl']=$url;
        $state=$snapshot['item']['state']??null;
        monitor_two_need(in_array($state,['observed','no_fresh_observation'],true),'two_monitor_item_state_changed');
        $offers=$snapshot['item']['offers']??[];
        monitor_two_need(is_array($offers)&&count($offers)<=3,'two_monitor_offer_scope_changed');
        $hotel=['tourvisor_hotel_id'=>$tv,'source_state'=>$state,'snapshot_admitted'=>false,'contract_error'=>null,
            'selected_offer_index'=>null,'offers'=>[],'term_failures'=>[]];
        if($state==='no_fresh_observation'){
            monitor_two_need($offers===[],'two_monitor_absent_state_with_offers');
            $hotel['contract_error']='no_fresh_observation';
        }else{
            try{monitor_price_observation($snapshot,$tv,$now);$hotel['snapshot_admitted']=true;}
            catch(Throwable $e){$hotel['contract_error']=monitor_two_code($e);}
            if($offers!==[])$hotel['selected_offer_index']=0;
        }
        $generated=monitor_two_epoch($snapshot['generatedAt']??null);
        foreach($offers as $index=>$offer){
            monitor_two_need(is_int($index)&&is_array($offer),'two_monitor_offer_shape_invalid');
            $failures=monitor_two_term_failures($offer,$tv,$index);
            $hotel['term_failures']=array_merge($hotel['term_failures'],$failures);
            $age=$offer['ageSecondsAtExport']??null;$expiry=monitor_two_epoch($offer['expiresAt']??null);
            $elapsed=$generated!==null?max(0,$now-$generated):null;
            $effectiveAge=is_int($age)&&$age>=0&&$elapsed!==null?$age+$elapsed:null;
            $record=$offer['recordId']??null;
            $hotel['offers'][]=[
                'offer_index'=>$index,'record_id'=>is_string($record)&&preg_match('/\A[a-f0-9]{64}\z/D',$record)===1?$record:null,
                'same_as_original_held_record'=>$tv===1142?$record==='41661d209964ffc44d0285473085d0a86d149f35642ec95558769a70d4346839':null,
                'price'=>monitor_two_value($offer['price']??null),'departure_date'=>monitor_two_value($offer['departureDate']??null),
                'nights'=>monitor_two_value($offer['nights']??null),'room_type'=>monitor_two_value($offer['roomType']??null),
                'meal_id'=>monitor_two_value($offer['mealId']??null),'age_seconds_at_export'=>monitor_two_value($age),
                'age_seconds_at_read'=>$effectiveAge,'expires_at'=>$offer['expiresAt']??null,
                'observation_within_24h'=>$effectiveAge!==null&&$effectiveAge<86400&&$generated<=$now+60,
                'export_not_expired'=>$expiry!==null&&$expiry>$now,
                'term_predicate_passed'=>$failures===[],
            ];
        }
        // Every returned offer is validated. Never choose another one to evade the guard.
        $hotel['all_term_predicates_passed']=$state==='observed'&&$offers!==[]&&$hotel['term_failures']===[];
        $result['hotels'][]=$hotel;
    }
    return $result;
}

if(defined('MONITOR_TWO_READ_LIBRARY_ONLY')&&MONITOR_TWO_READ_LIBRARY_ONLY)return;
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');ini_set('log_errors','0');
$out=['ok'=>false,'mode'=>'read_only_two_monitor_hotels','diagnostic_only'=>true,'monitor_reads'=>0,
    'supplier_calls'=>0,'provider_calls'=>0,'database_writes'=>0,'journal_writes'=>0,'post_edits'=>0,
    'feed_writes'=>0,'hold_writes'=>0,'source_response_sha256'=>null];
try{
    monitor_two_need(count($argv)===1,'two_monitor_arguments_invalid');
    $today=new DateTimeImmutable('today',new DateTimeZone('Europe/Moscow'));
    $query=['tourvisorHotelIds'=>[1142,43060],'departureId'=>1,
        'dateFrom'=>$today->modify('+1 day')->format('Y-m-d'),
        'dateTo'=>$today->modify('first day of this month')->modify('+12 months')->modify('-1 day')->format('Y-m-d')];
    $url='https://anytoour.ru/data/hotel-monitor-prices-read-v1.php?'.http_build_query($query,'','&',PHP_QUERY_RFC3986);
    $raw='';$curl=curl_init($url);monitor_two_need($curl!==false,'two_monitor_read_start_failed');
    curl_setopt_array($curl,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>40,
        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER=>['Cache-Control: no-cache'],
        CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk)use(&$raw):int{if(strlen($raw)+strlen($chunk)>2097152)return 0;$raw.=$chunk;return strlen($chunk);}]);
    $out['monitor_reads']=1;$success=curl_exec($curl);$http=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
    $out['http_status']=$http;monitor_two_need($success===true&&$http===200,'two_monitor_http_read_failed');
    $out['source_response_sha256']=hash('sha256',$raw);
    try{$payload=json_decode($raw,true,64,JSON_THROW_ON_ERROR);}catch(Throwable $e){throw new RuntimeException('two_monitor_response_json_invalid');}
    monitor_two_need(is_array($payload),'two_monitor_response_shape_invalid');
    $now=time();$out+=monitor_two_payload($payload,$url,$now,$query);
    $out['read_at']=gmdate('c',$now);$out['ok']=true;
}catch(Throwable $e){$out['error']=monitor_two_code($e);}
echo 'MONITOR_TWO_HOTEL_DIAGNOSTICS='.json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
exit($out['ok']?0:2);

