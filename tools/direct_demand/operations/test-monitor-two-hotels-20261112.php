<?php
declare(strict_types=1);
define('MONITOR_TWO_READ_LIBRARY_ONLY',true);
require __DIR__.'/read-monitor-two-hotels-20261112.php';
$cases=0;
function two_test(bool $ok,string $reason):void { global $cases;monitor_two_need($ok,$reason);$cases++; }
function two_test_reject(callable $fn,string $expected):void {
    $code=null;try{$fn();}catch(Throwable $e){$code=$e->getMessage();}
    two_test($code===$expected,'two_test_unexpected_rejection');
}
$generated=strtotime('2026-11-12T08:00:00Z');
$query=['tourvisorHotelIds'=>[1142,43060],'departureId'=>1,'dateFrom'=>'2026-11-13','dateTo'=>'2027-10-31'];
$url='https://anytoour.ru/data/hotel-monitor-prices-read-v1.php?'.http_build_query($query,'','&',PHP_QUERY_RFC3986);
$payload=['ok'=>true,'format'=>'anytour-hotel-monitor-prices-v1','identityNamespace'=>'tourvisor',
    'source'=>'scheduled_monitor','observationWindowHours'=>24,'supplierRequests'=>0,'writes'=>0,
    'timeBasis'=>'database-relative; stored observation timezone is not asserted','generatedAt'=>gmdate('Y-m-d\TH:i:s\Z',$generated),
    'scope'=>$query+['adults'=>2,'childrenCount'=>0,'currency'=>'RUB','nightsFrom'=>5,'nightsTo'=>14],'items'=>[]];
foreach([1142,43060] as $tv)$payload['items'][]=['tourvisorHotelId'=>$tv,'state'=>'observed','offers'=>[[
    'tourvisorHotelId'=>$tv,'departureId'=>1,'adults'=>2,'childrenCount'=>0,'currency'=>'RUB','nights'=>7,
    'tourId'=>'synthetic-'.$tv,'roomType'=>'Standard','roomId'=>null,'mealId'=>7,'departureDate'=>'2026-12-03',
    'price'=>100000,'recordId'=>hash('sha256','synthetic-'.$tv),'currentPriceVerified'=>false,
    'fullPackagePriceVerified'=>false,'availabilityVerified'=>false,'fuelIncluded'=>null,
    'source'=>'scheduled_monitor','priceKind'=>'listing_observation','ageSecondsAtExport'=>3600,
    'expiresAt'=>gmdate('Y-m-d\TH:i:s\Z',$generated+900),
]]];
$baseline=$payload;$result=monitor_two_payload($payload,$url,$generated+1,$query);
two_test($result['hotels'][0]['snapshot_admitted']&&$result['hotels'][1]['snapshot_admitted']
    &&$result['hotels'][0]['offers'][0]['age_seconds_at_read']===3601,'two_test_valid_read_rejected');
two_test($payload===$baseline&&$result['hotels'][1]['term_failures']===[],'two_test_nullable_room_or_input_changed');

$bad=$payload;$bad['items'][1]['offers'][0]['mealId']=6;
$result=monitor_two_payload($bad,$url,$generated+1,$query);
two_test($result['hotels'][1]['contract_error']==='monitor_terms_invalid'
    &&array_column($result['hotels'][1]['term_failures'],'path')===['offers[0].mealId']
    &&$result['hotels'][1]['term_failures'][0]['actual']===['type'=>'int','value'=>6]
    &&$result['hotels'][0]['snapshot_admitted'],'two_test_unknown_meal_not_exact');

$bad=$payload;$bad['items'][1]['offers'][0]['nights']='7';
$result=monitor_two_payload($bad,$url,$generated+1,$query);
two_test($result['hotels'][1]['contract_error']==='monitor_terms_invalid'
    &&array_column($result['hotels'][1]['term_failures'],'path')===['offers[0].nights']
    &&$result['hotels'][1]['term_failures'][0]['actual']===['type'=>'string','value'=>'7'],'two_test_nights_coerced');

$bad=$payload;$later=$bad['items'][1]['offers'][0];$later['price']=100001;
$later['recordId']=hash('sha256','synthetic-later');$later['roomType']=' ';
$bad['items'][1]['offers'][]=$later;$before=$bad;
$result=monitor_two_payload($bad,$url,$generated+1,$query);
two_test(!$result['hotels'][1]['snapshot_admitted']&&$result['hotels'][1]['contract_error']==='monitor_terms_invalid'
    &&$result['hotels'][1]['selected_offer_index']===0&&$result['hotels'][1]['offers'][0]['term_predicate_passed']
    &&array_column($result['hotels'][1]['term_failures'],'path')===['offers[1].roomType']
    &&$bad===$before,'two_test_invalid_nonminimum_offer_hidden');

$absent=$payload;$absent['items'][0]['state']='no_fresh_observation';$absent['items'][0]['offers']=[];
$result=monitor_two_payload($absent,$url,$generated+1,$query);
two_test(!$result['hotels'][0]['snapshot_admitted']&&$result['hotels'][0]['contract_error']==='no_fresh_observation'
    &&$result['hotels'][0]['selected_offer_index']===null&&$result['hotels'][1]['snapshot_admitted'],'two_test_missing_observation_replaced');

$stale=$payload;$stale['items'][0]['offers'][0]['ageSecondsAtExport']=86390;
$stale['items'][0]['offers'][0]['expiresAt']=gmdate('Y-m-d\TH:i:s\Z',$generated+10);
$result=monitor_two_payload($stale,$url,$generated+20,$query);
two_test($result['hotels'][0]['contract_error']==='monitor_price_expired'
    &&$result['hotels'][0]['offers'][0]['age_seconds_at_read']===86410
    &&!$result['hotels'][0]['offers'][0]['observation_within_24h']
    &&!$result['hotels'][0]['offers'][0]['export_not_expired'],'two_test_stale_observation_called_fresh');

$wrong=$payload;$wrong['items'][1]=$wrong['items'][0];
two_test_reject(static fn()=>monitor_two_payload($wrong,$url,$generated+1,$query),'two_monitor_items_scope_changed');
$wrong=$payload;$wrong['scope']['tourvisorHotelIds']=[1142,43061];
two_test_reject(static fn()=>monitor_two_payload($wrong,$url,$generated+1,$query),'two_monitor_identity_scope_changed');
$wrong=$payload;$wrong['writes']=1;
two_test_reject(static fn()=>monitor_two_payload($wrong,$url,$generated+1,$query),'two_monitor_read_only_contract_changed');
echo 'MONITOR_TWO_DIAGNOSTICS_OK cases='.$cases." no_network=1 no_writes=1\n";

