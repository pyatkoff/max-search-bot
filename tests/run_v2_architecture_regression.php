<?php
declare(strict_types=1);
require_once __DIR__ . '/../services/ProjectConfig.php';
require_once __DIR__ . '/../services/TripStateRepository.php';
require_once __DIR__ . '/../services/ManagerSummaryService.php';
require_once __DIR__ . '/../services/SearchRequestBuilder.php';
require_once __DIR__ . '/../services/ActionRouter.php';

$pass=0;$fail=0;
function v2check(string $name,$actual,$expected):void{global$pass,$fail;if($actual===$expected){echo"PASS  $name\n";$pass++;}else{echo"FAIL  $name\n expected: ".json_encode($expected,JSON_UNESCAPED_UNICODE)."\n actual: ".json_encode($actual,JSON_UNESCAPED_UNICODE)."\n";$fail++;}}

ProjectConfig::resetForTests(['id'=>'test','search'=>['base_domain'=>'https://example.test','claim_base_domain'=>'https://claim.example.test','tracking_base_domain'=>'https://tracking.example.test','claim_path'=>'/search/{code}/'],'state'=>['v2_store_dir'=>'runtime/test_trip_state']]);
v2check('project id',ProjectConfig::projectId(),'test');
v2check('public base',ProjectConfig::baseDomain(),'https://example.test');
v2check('claim base is independent',ProjectConfig::claimBaseDomain(),'https://claim.example.test');
v2check('claim url',ProjectConfig::claimUrl('abc','123'),'https://claim.example.test/search/abc/?yclid=123');
v2check('tracking base independent',ProjectConfig::trackingBaseDomain(),'https://tracking.example.test');

ProjectConfig::resetForTests(['id'=>'fallback','search'=>['base_domain'=>'https://fallback.test','claim_path'=>'/search/{code}/']]);
v2check('claim base falls back to public base',ProjectConfig::claimBaseDomain(),'https://fallback.test');
v2check('claim url fallback remains backward compatible',ProjectConfig::claimUrl('abc','123'),'https://fallback.test/search/abc/?yclid=123');

ProjectConfig::resetForTests(['id'=>'test','search'=>['base_domain'=>'https://example.test','claim_base_domain'=>'https://claim.example.test','tracking_base_domain'=>'https://tracking.example.test','claim_path'=>'/search/{code}/'],'state'=>['v2_store_dir'=>'runtime/test_trip_state']]);
define('MAX_SEARCH_PUBLIC_BASE_URL','https://public.override.test/');
define('MAX_SEARCH_TRACKING_BASE_URL','https://tracking.override.test/');
v2check('public base override',ProjectConfig::baseDomain(),'https://public.override.test');
v2check('tracking base override',ProjectConfig::trackingBaseDomain(),'https://tracking.override.test');
v2check('configured claim base is not moved by public override',ProjectConfig::claimBaseDomain(),'https://claim.example.test');
v2check('claim url keeps configured claim origin',ProjectConfig::claimUrl('abc','123'),'https://claim.example.test/search/abc/?yclid=123');

$base=sys_get_temp_dir().'/max-search-v2-'.uniqid();@mkdir($base,0755,true);
$state=['departure'=>['city_id'=>1,'city'=>'Москва'],'destination'=>['country_id'=>4,'country'=>'Турция'],'dates'=>['from'=>'10.09.2026','to'=>'12.09.2026'],'nights'=>['min'=>7,'max'=>10],'tourists'=>['adults'=>2,'children'=>1,'children_ages'=>[6]],'budget'=>['max'=>180000,'currency'=>'RUB'],'hotel'=>['stars_min'=>5,'meal'=>'all_inclusive'],'preferences'=>['первая линия','детский клуб'],'negative_preferences'=>['шумный отель'],'meta'=>[]];
v2check('state save',TripStateRepository::save(-123,$state,$base),true);
$loaded=TripStateRepository::load(-123,$base);v2check('state budget persisted',$loaded['budget']['max']??null,180000);v2check('state preferences persisted',$loaded['preferences']??[],['первая линия','детский клуб']);
$legacy=$state;$legacy['budget']['max']=null;$overlay=TripStateRepository::overlay($legacy,$loaded);v2check('overlay restores extended budget',$overlay['budget']['max'],180000);

$request=SearchRequestBuilder::fromTripState($state);v2check('search ready',SearchRequestBuilder::isReady($request),true);v2check('search budget',$request['budget_max']??null,180000);v2check('search ages',$request['children_ages']??[],[6]);
$summary=ManagerSummaryService::build($state,['region_id'=>5,'campaign_id'=>77,'source'=>'yandex']);v2check('summary route',strpos($summary,'Москва → Турция')!==false,true);v2check('summary budget',strpos($summary,'180 000 RUB')!==false,true);v2check('summary preference',strpos($summary,'детский клуб')!==false,true);

$r=ActionRouter::route(['action'=>RulesEngine::ASK,'next_field'=>'dates','missing'=>['dates']]);v2check('action router ask',$r['handler'],'ask');
$r=ActionRouter::route(['action'=>RulesEngine::MANAGER]);v2check('action router manager',$r['handler'],'manager');

TripStateRepository::delete(-123,$base);v2check('state delete',TripStateRepository::load(-123,$base),[]);
@rmdir(ProjectConfig::v2StoreDir($base));@rmdir(dirname(ProjectConfig::v2StoreDir($base)));@rmdir($base);
ProjectConfig::resetForTests(null);
$total=$pass+$fail;echo"\n--------------------------\nTOTAL $total | PASS $pass | FAIL $fail\n";exit($fail?1:0);
