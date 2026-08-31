<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/services/SearchDateSummary.php';

$passed=0;$failed=0;
function dateSummaryCheck(string $name,$actual,$expected):void{global$passed,$failed;if($actual===$expected){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";echo '      expected: '.json_encode($expected,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";echo '      actual:   '.json_encode($actual,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";$failed++;}

$today=new DateTimeImmutable('2026-08-28 00:00:00',new DateTimeZone('Europe/Kaliningrad'));
dateSummaryCheck('conversation 494 keeps selected 13 September visible',SearchDateSummary::line('13.09.2026',$today),'📅 Вылет 13.09.2026 · ищем 10.09–16.09.2026');
dateSummaryCheck('near-today search window is clipped without changing preferred date',SearchDateSummary::line('29.08.2026',$today),'📅 Вылет 29.08.2026 · ищем 28.08–01.09.2026');
dateSummaryCheck('empty date stays absent',SearchDateSummary::line('',$today),null);
$summary=['✈️ Москва → Турция','📅 10.09.2026 — 16.09.2026'];
dateSummaryCheck('legacy technical window line is replaced',SearchDateSummary::replaceDateLine($summary,'13.09.2026',$today),['✈️ Москва → Турция','📅 Вылет 13.09.2026 · ищем 10.09–16.09.2026']);
$source=(string)file_get_contents(dirname(__DIR__).'/services/DialogueView.php');
dateSummaryCheck('confirmation delegates date presentation to dedicated formatter',strpos($source,'SearchDateSummary::replaceDateLine')!==false,true);
dateSummaryCheck('confirmation masks date before legacy base formatter',strpos($source,'$summaryData[MaxSearchApi::$statusDate] = null;')!==false,true);
dateSummaryCheck('confirmation passes masked summary data to legacy formatter',strpos($source,'MaxSearchApi::formatSavedData($summaryData)')!==false,true);
dateSummaryCheck('search semantics remain outside presentation formatter',strpos((string)file_get_contents(dirname(__DIR__).'/services/SearchDateSummary.php'),'TourResultsService')===false,true);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
