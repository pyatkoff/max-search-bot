<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$migration=(string)file_get_contents($root.'/migrations/022_source_handling_mode.sql');
$routingPage=(string)file_get_contents($root.'/manager/routing.php');
$routingJs=(string)file_get_contents($root.'/manager/assets/routing.js');
$routingService=(string)file_get_contents($root.'/services/RoutingAdminService.php');
$api=(string)file_get_contents($root.'/manager/api.php');
$sourceHandling=(string)file_get_contents($root.'/services/SourceHandlingService.php');
$dispatcher=(string)file_get_contents($root.'/services/IncomingUpdateDispatcher.php');

$passed=0;$failed=0;
function shmCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo"PASS  {$name}\n";$passed++;return;}echo"FAIL  {$name}\n";$failed++;}

shmCheck('migration adds explicit source handling mode',strpos($migration,"handling_mode ENUM('ai','manager','ask')")!==false&&strpos($migration,"DEFAULT 'ai'")!==false);
shmCheck('routing admin exposes all three start modes',strpos($routingPage,'id="handlingMode"')!==false&&strpos($routingPage,'Сначала AI')!==false&&strpos($routingPage,'Сразу менеджеру')!==false&&strpos($routingPage,'Спросить клиента')!==false);
shmCheck('routing admin persists and restores handling mode',strpos($routingJs,"$('handlingMode').value=s.handling_mode||'ai'")!==false&&strpos($routingJs,'handling_mode')!==false);
shmCheck('routing service validates allowed modes',strpos($routingService,"['ai','manager','ask']")!==false&&strpos($routingService,"invalid_handling_mode")!==false);
shmCheck('routing API forwards handling mode only as source config',strpos($api,"(string)($data['handling_mode']??'ai')")!==false);
shmCheck('ask mode offers manager or self service',strpos($sourceHandling,'source_choice_manager')!==false&&strpos($sourceHandling,'source_choice_ai')!==false&&strpos($sourceHandling,'Позвать менеджера')!==false&&strpos($sourceHandling,'Подобрать тур самостоятельно')!==false);
shmCheck('manager-first reuses existing handoff dispatcher',strpos($sourceHandling,'ManagerHandoffDispatchService::dispatch')!==false&&strpos($sourceHandling,'ConversationControlService::markWaitingByChat')!==false);
shmCheck('source policy does not emit metrika goals',strpos($sourceHandling,'queueMetrikaGoal')===false&&strpos($sourceHandling,'MetrikaConversionGoalService')===false);
shmCheck('source policy does not mutate manager shifts or bonuses',strpos($sourceHandling,'setWorking')===false&&strpos($sourceHandling,'priority')===false&&strpos($sourceHandling,'bonus')===false);
shmCheck('dispatcher runs source policy before AI application',strpos($dispatcher,'SourceHandlingService::handle($incoming)')!==false&&strpos($dispatcher,'SourceHandlingService::handle($incoming)')<strpos($dispatcher,'$this->application->dispatch($incoming)'));
shmCheck('manager choice remains suppressive if handoff send fails',strpos($sourceHandling,"self::handoff($incoming,$platform,$chatId,'source_policy');\n                return true;")!==false&&strpos($sourceHandling,"self::handoff($incoming,$platform,$chatId,'source_choice');\n                    return true;")!==false);
shmCheck('handoff back explicitly restores AI choice',strpos($sourceHandling,"self::recordChoice($platform,$chatId,self::AI,'handoff_back')")!==false);

echo"\nRESULT: {$passed} passed, {$failed} failed\n";
exit($failed?1:0);
