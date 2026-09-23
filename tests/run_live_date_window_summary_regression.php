<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/services/SearchDateSummary.php';

$passed=0;$failed=0;
function dateSummaryCheck(string $name,$actual,$expected):void{global$passed,$failed;if($actual===$expected){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";echo '      expected: '.json_encode($expected,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";echo '      actual:   '.json_encode($actual,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";$failed++;}

$today=new DateTimeImmutable('2026-08-28 00:00:00',new DateTimeZone('Europe/Kaliningrad'));
dateSummaryCheck('conversation 494 keeps selected 13 September exact in confirmation',SearchDateSummary::line('13.09.2026',$today),'📅 Вылет 13.09.2026 · поиск на эту дату');
dateSummaryCheck('near-today selected date is not widened in customer summary',SearchDateSummary::line('29.08.2026',$today),'📅 Вылет 29.08.2026 · поиск на эту дату');
dateSummaryCheck('empty date stays absent',SearchDateSummary::line('',$today),null);
$summary=['✈️ Москва → Турция','📅 10.09.2026 — 16.09.2026'];
dateSummaryCheck('legacy technical window line is replaced by exact selected date',SearchDateSummary::replaceDateLine($summary,'13.09.2026',$today),['✈️ Москва → Турция','📅 Вылет 13.09.2026 · поиск на эту дату']);
$view=(string)file_get_contents(dirname(__DIR__).'/services/DialogueView.php');
dateSummaryCheck('calendar copy says search opens on selected date',strpos($view,'Поиск откроется на эту дату')!==false,true);
dateSummaryCheck('calendar copy does not promise nearby-date filtering',strpos($view,'В поиске посмотрим даты рядом с ней')===false,true);
dateSummaryCheck('confirmation delegates date presentation to dedicated formatter',strpos($view,'SearchDateSummary::replaceDateLine')!==false,true);
dateSummaryCheck('confirmation masks date before legacy base formatter',strpos($view,'$summaryData[MaxSearchApi::$statusDate] = null;')!==false,true);
dateSummaryCheck('confirmation passes masked summary data to legacy formatter',strpos($view,'MaxSearchApi::formatSavedData($summaryData)')!==false,true);
$handoff=(string)file_get_contents(dirname(__DIR__).'/services/TourSearchHandoffService.php');
dateSummaryCheck('search handoff still sends one exact date to both bounds',substr_count($handoff,"'dateFrom' => \$date")===2&&substr_count($handoff,"'dateTo' => \$date")===2,true);
dateSummaryCheck('presentation no longer invents plus-minus-three customer flexibility',strpos((string)file_get_contents(dirname(__DIR__).'/services/SearchDateSummary.php'),"modify('-3 days')")===false,true);
dateSummaryCheck('search semantics remain outside presentation formatter',strpos((string)file_get_contents(dirname(__DIR__).'/services/SearchDateSummary.php'),'TourResultsService')===false,true);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
