<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/ManagerAvailabilityService.php';
require_once __DIR__ . '/../services/ManagerRequestService.php';
require_once __DIR__ . '/../services/ManagerPhoneFallbackService.php';

$passed=0;$failed=0;
function mpfCheck(string $name,$actual,$expected):void{global$passed,$failed;if($actual===$expected){echo"PASS  {$name}\n";$passed++;return;}echo"FAIL  {$name}\n";echo'      expected: '.json_encode($expected,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";echo'      actual:   '.json_encode($actual,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";$failed++;}

$tz = new DateTimeZone(ManagerAvailabilityService::BUSINESS_TIMEZONE);
$at = static function(string $value) use ($tz): int { return (new DateTimeImmutable($value,$tz))->getTimestamp(); };
mpfCheck('before 10 is outside working hours',ManagerAvailabilityService::withinWorkingHours($at('2026-08-27 09:59:59')),false);
mpfCheck('10 starts working hours',ManagerAvailabilityService::withinWorkingHours($at('2026-08-27 10:00:00')),true);
mpfCheck('19:59 remains working hours',ManagerAvailabilityService::withinWorkingHours($at('2026-08-27 19:59:59')),true);
mpfCheck('20:00 ends working hours',ManagerAvailabilityService::withinWorkingHours($at('2026-08-27 20:00:00')),false);
mpfCheck('fallback delay is exactly five minutes',ManagerPhoneFallbackService::DELAY_SECONDS,300);
mpfCheck('fallback copy explains manager has not replied',strpos(ManagerRequestService::fallbackMessageText(),'не успел ответить')!==false,true);
mpfCheck('fallback copy offers phone without claiming it is mandatory',strpos(ManagerRequestService::fallbackMessageText(),'можете оставить номер')!==false,true);
mpfCheck('outside-hours copy promises next working period',strpos(ManagerRequestService::outsideHoursMessageText(),'следующий рабочий период')!==false,true);
mpfCheck('outside-hours copy preserves self-service route',strpos(ManagerRequestService::outsideHoursMessageText(),'вернуться к вариантам туров')!==false,true);

$serviceSource=(string)file_get_contents(__DIR__ . '/../services/ManagerPhoneFallbackService.php');
$dispatchSource=(string)file_get_contents(__DIR__ . '/../services/ManagerHandoffDispatchService.php');
$callbackSource=(string)file_get_contents(__DIR__ . '/../actions/callbacks/ManagerCallbackAction.php');
$viewSource=(string)file_get_contents(__DIR__ . '/../services/DialogueView.php');
$cronSource=(string)file_get_contents(__DIR__ . '/../cron_followup.php');
mpfCheck('service considers waiting and already-taken conversations',strpos($serviceSource,"c.status IN ('waiting_manager','manager')")!==false,true);
mpfCheck('service serializes concurrent fallback attempts',strpos($serviceSource,'GET_LOCK(?,0)')!==false && strpos($serviceSource,'RELEASE_LOCK(?)')!==false,true);
mpfCheck('manager reply suppresses fallback',substr_count($serviceSource,'self::hasManagerReply(')>=2,true);
mpfCheck('successful fallback is idempotently marked',strpos($serviceSource,"'manager_phone_fallback_sent'")!==false,true);
mpfCheck('sent or failed fallback suppresses repeat',strpos($serviceSource,"event_type IN ('manager_phone_fallback_sent','manager_phone_fallback_failed')")!==false,true);
mpfCheck('failed delivery is recorded once as terminal attempt',strpos($serviceSource,"'manager_phone_fallback_failed'")!==false && strpos($serviceSource,'One external fallback attempt per manager request')!==false,true);
mpfCheck('existing phone suppresses fallback',strpos($serviceSource,"['UF_PHONE']")!==false,true);
mpfCheck('manager handoff online claim is gated by working hours',strpos($dispatchSource,'if ($withinWorkingHours && $conversation)')!==false,true);
mpfCheck('callback handoff uses same availability dispatch as AI path',strpos($callbackSource,'ManagerHandoffDispatchService::dispatch')!==false,true);
mpfCheck('outside hours select truthful handoff copy',strpos($dispatchSource,'!$withinWorkingHours')!==false && strpos($viewSource,"outside_hours_text")!==false,true);
mpfCheck('cron executes manager phone fallback',strpos($cronSource,'ManagerPhoneFallbackService::runDue($now)')!==false,true);
mpfCheck('cron reports fallback outcome',strpos($cronSource,'manager_phone_sent=')!==false,true);

$total=$passed+$failed;echo"\n--------------------------\n";echo"TOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";exit($failed>0?1:0);
