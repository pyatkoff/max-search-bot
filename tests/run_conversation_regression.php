<?php

declare(strict_types=1);

require_once __DIR__ . '/../handlers/AiDateHandler.php';
require_once __DIR__ . '/../handlers/DepartureRouteAdviceHandler.php';
require_once __DIR__ . '/../services/DestinationAreaResolver.php';
require_once __DIR__ . '/../services/DestinationPreferenceResolver.php';
require_once __DIR__ . '/../DepartureRouteResolver.php';
require_once __DIR__ . '/../DepartureRouteAdvisor.php';

$passed = 0;
$failed = 0;
function convValue($value): string { if (is_bool($value)) return $value?'true':'false'; if($value===null)return 'null'; if(is_scalar($value))return(string)$value; return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
function convCheck(string $scenario,string $name,$actual,$expected):void { global $passed,$failed; if($actual===$expected){echo "PASS  [{$scenario}] {$name}\n";$passed++;return;} echo "FAIL  [{$scenario}] {$name}\n      expected: ".convValue($expected)."\n      actual:   ".convValue($actual)."\n";$failed++; }
function convPrivateStatic(string $class,string $method,array $args=[]){$ref=new ReflectionMethod($class,$method);$ref->setAccessible(true);return $ref->invokeArgs(null,$args);}
function cleanupPending($chatId):void{AiDateHandler::clear($chatId);}
function convFutureDate(int $day,int $month):string{$today=new DateTimeImmutable('today');$year=(int)$today->format('Y');$candidate=DateTimeImmutable::createFromFormat('!d.m.Y',sprintf('%02d.%02d.%04d',$day,$month,$year));if(!$candidate)throw new RuntimeException('Failed to build regression date');if($candidate<$today)$candidate=$candidate->modify('+1 year');return $candidate->format('d.m.Y');}

echo "MAX Search conversation regression suite\n========================================\n\n";
$routesFile=__DIR__.'/fixtures/tourvisor_routes.json'; $fallbacksFile=__DIR__.'/fixtures/departure_fallbacks.json';
$resolver=new DepartureRouteResolver($routesFile,$fallbacksFile); $advisor=new DepartureRouteAdvisor($resolver,$fallbacksFile);

$scenario='Kaliningrad -> where can I go';
$first='туры из Калининграда на неделю на двоих в августе';
convCheck($scenario,'departure-only text creates no destination part',convPrivateStatic(DestinationAreaResolver::class,'destinationPart',[$first]),'');
convCheck($scenario,'where-can-I-go creates no area tokens',convPrivateStatic(DestinationAreaResolver::class,'tokens',['куда можно?']),[]);
$advice=$advisor->getDestinations('Калининград','2026-08');
convCheck($scenario,'keeps Kaliningrad when direct route exists',$advice['fallback_used']??null,false);
convCheck($scenario,'offers direct destinations',$advice['status']??null,'direct_destinations');
convCheck($scenario,'first offered destination',$advice['destinations'][0]['country']??null,'Турция');

$scenario='Yaroslavl -> where can I go'; $advice=$advisor->getDestinations('Ярославль','2026-10');
convCheck($scenario,'fallback is explicit',$advice['fallback_used']??null,true);
convCheck($scenario,'requested departure preserved',$advice['requested_departure']??null,'Ярославль');
convCheck($scenario,'fallback departure is Moscow',$advice['fallback_departure']??null,'Москва');
convCheck($scenario,'fallback has destinations',count($advice['destinations']??[])>0,true);

$scenario='August -> 28-31'; $chat=-990001; cleanupPending($chat); $month=AiDateHandler::rememberMonthFromText($chat,'в августе');
convCheck($scenario,'month remembered',$month['month']??null,8); convCheck($scenario,'month-only has no exact date',$month['date']??null,null); $date=AiDateHandler::resolvePendingShortDate($chat,'28-31');
convCheck($scenario,'range resolves without asking month again',$date,convFutureDate(30,8)); convCheck($scenario,'pending month cleared after range',PendingMonthStore::get($chat),[]); cleanupPending($chat);

$scenario='September -> end of month'; $chat=-990002; cleanupPending($chat); $month=AiDateHandler::rememberMonthFromText($chat,'в сентябре');
convCheck($scenario,'month remembered',$month['month']??null,9); $date=AiDateHandler::resolvePendingShortDate($chat,'в конце месяца'); convCheck($scenario,'end of month resolves',$date,convFutureDate(27,9)); convCheck($scenario,'pending month cleared',PendingMonthStore::get($chat),[]); cleanupPending($chat);

$scenario='Vietnam -> 15 April'; convCheck($scenario,'date follow-up has no destination tokens',convPrivateStatic(DestinationAreaResolver::class,'tokens',['15 апреля']),[]); $date=DateParser::resolveDate('15 апреля'); convCheck($scenario,'date itself is parsed',!empty($date['date']),true);
$scenario='Meal follow-up'; convCheck($scenario,'breakfast and dinner have no area tokens',convPrivateStatic(DestinationAreaResolver::class,'tokens',['завтрак и ужин']),[]); convCheck($scenario,'all inclusive has no area tokens',convPrivateStatic(DestinationAreaResolver::class,'tokens',['все включено']),[]);

$scenario='Kaliningrad -> Thailand in October'; $route=$resolver->resolve('Калининград','Таиланд','2026-10');
convCheck($scenario,'status is fallback_available',$route['status']??null,'fallback_available'); convCheck($scenario,'fallback departure is Moscow',$route['fallback']['fallback_departure']??null,'Москва'); convCheck($scenario,'requested departure is preserved',$route['fallback']['requested_departure']??null,'Калининград');

$scenario='Kaliningrad -> Turkey in October'; $route=$resolver->resolve('Калининград','Турция','2026-10');
convCheck($scenario,'direct route exists as a programme',$route['route']['direct_charter']??null,true); convCheck($scenario,'but has no dates in October',$route['route']['available_in_period']??null,false); convCheck($scenario,'no invented fallback',$route['status']??null,'not_found');

$scenario='Yaroslavl -> Thailand in October'; $route=$resolver->resolve('Ярославль','Таиланд','2026-10');
convCheck($scenario,'status is fallback_available',$route['status']??null,'fallback_available'); convCheck($scenario,'fallback departure is Moscow',$route['fallback']['fallback_departure']??null,'Москва');

$scenario='Preference intent detection';
convCheck($scenario,'detects warm intent',DestinationPreferenceResolver::detectIntent('куда потеплее в октябре'),'warm');
convCheck($scenario,'detects warm sea intent',DestinationPreferenceResolver::detectIntent('хочу на тёплое море'),'warm');
convCheck($scenario,'detects sea intent',DestinationPreferenceResolver::detectIntent('хочу на море'),'sea');
convCheck($scenario,'ordinary discovery has no preference',DestinationPreferenceResolver::detectIntent('куда можно?'),null);

$scenario='Live where-to-go typo';
convCheck($scenario,'recognizes exact production typo',DepartureRouteAdviceHandler::isDiscoveryIntent('Из Москвы  кда небуть после 17 сентября  на 7 ночей все включено  для молодожоных'),true);
convCheck($scenario,'recognizes normal hyphenated form',DepartureRouteAdviceHandler::isDiscoveryIntent('Из Москвы куда-нибудь после 17 сентября'),true);
convCheck($scenario,'does not turn named destination into discovery',DepartureRouteAdviceHandler::isDiscoveryIntent('Из Москвы в Турцию после 17 сентября'),false);

$scenario='Warm filter only uses available charters'; $moscow=$resolver->getDirectDestinations('Москва','2026-10'); $available=array_column($moscow['destinations']??[],'country'); $warm=DestinationPreferenceResolver::filterAndRank($moscow['destinations']??[],'warm','2026-10'); $warmNames=array_column($warm,'country');
convCheck($scenario,'fixture has Thailand charter in October',in_array('Таиланд',$available,true),true);
convCheck($scenario,'fixture has UAE charter in October',in_array('ОАЭ',$available,true),true);
convCheck($scenario,'warm October recommendation keeps UAE',in_array('ОАЭ',$warmNames,true),true);
convCheck($scenario,'warm October profile filters Thailand',in_array('Таиланд',$warmNames,true),false);
convCheck($scenario,'filter cannot invent a country',count(array_diff($warmNames,$available)),0);

$scenario='Sea filter only uses available charters'; $sea=DestinationPreferenceResolver::filterAndRank($moscow['destinations']??[],'sea','2026-10'); $seaNames=array_column($sea,'country');
convCheck($scenario,'sea keeps Thailand because it is a sea destination',in_array('Таиланд',$seaNames,true),true);
convCheck($scenario,'sea keeps UAE',in_array('ОАЭ',$seaNames,true),true);
convCheck($scenario,'sea filter cannot invent a country',count(array_diff($seaNames,$available)),0);

$total=$passed+$failed; echo "\n----------------------------------------\nTOTAL {$total} | PASS {$passed} | FAIL {$failed}\n"; exit($failed>0?1:0);