<?php

declare(strict_types=1);

if (!class_exists('MaxSearchApi')) {
    class MaxSearchApi {}
}

require_once __DIR__ . '/../actions/callbacks/ToursCallbackAction.php';

$passed=0;$failed=0;
function liveToursCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

$source=(string)file_get_contents(__DIR__.'/../actions/callbacks/ToursCallbackAction.php');
liveToursCheck('live conversation 492 four-second repeat is suppressed',InteractionGuard::isDuplicate('show_tours',100.0,'show_tours',104.0,10.0));
liveToursCheck('same callback near ten-second boundary is suppressed',InteractionGuard::isDuplicate('show_tours',100.0,'show_tours',109.9,10.0));
liveToursCheck('same callback after ten-second boundary is allowed',!InteractionGuard::isDuplicate('show_tours',100.0,'show_tours',110.1,10.0));
liveToursCheck('live conversation 492 fifty-six-second retry remains allowed',!InteractionGuard::isDuplicate('show_tours',100.0,'show_tours',156.0,10.0));
liveToursCheck('different tours callback is not suppressed',!InteractionGuard::isDuplicate('show_tours',100.0,'tours_checked',104.0,10.0));
$showStart=strpos($source,'private static function handleShowTours');
$showEnd=strpos($source,'public static function handle(',$showStart===false?0:$showStart);
$showSource=$showStart!==false&&$showEnd!==false?substr($source,$showStart,$showEnd-$showStart):'';
liveToursCheck('show tours delegates serialization and duplicate marker to shared guard',strpos($showSource,'InteractionGuard::runDuplicateCallback(')!==false);
liveToursCheck('show tours preserves exact duplicate window',preg_match('/10\\.0\\s*,/s',$showSource)===1);
liveToursCheck('show tours action no longer owns lock timestamp or marker IO',strpos($showSource,'fopen(')===false&&strpos($showSource,'flock(')===false&&strpos($showSource,'microtime(')===false&&strpos($showSource,'fwrite(')===false);
liveToursCheck('duplicate show tours keeps legacy operational log',strpos($showSource,'DUPLICATE_SHOW_TOURS_CALLBACK_SKIPPED')!==false);
liveToursCheck('finish callbacks keep existing behavior outside duplicate guard',strpos($source,"if (strpos(\$q, 'finish') === 0)")!==false);

$diagnosticFile=sys_get_temp_dir().'/max-search-show-tours-'.bin2hex(random_bytes(4)).'.log';
$chatId=random_int(1000000,9999999);
$scope='tours_show_regression';
$lock=InteractionGuard::lockPath($chatId,$scope);
$calls=0;$duplicateHooks=0;$markerVisible=false;
DiagnosticLogger::setFile($diagnosticFile);
try{
    $first=InteractionGuard::runDuplicateCallback($chatId,'show_tours',$scope,10.0,function()use(&$calls,&$markerVisible,$lock):bool{
        $calls++;
        $state=is_file($lock)?json_decode((string)file_get_contents($lock),true):null;
        $markerVisible=($state['payload']??null)==='show_tours'&&isset($state['at'])&&is_numeric($state['at']);
        return true;
    });
    liveToursCheck('first show tours callback is accepted',$first&&$calls===1);
    liveToursCheck('accepted marker is persisted before outbound callback',$markerVisible);

    $second=InteractionGuard::runDuplicateCallback($chatId,'show_tours',$scope,10.0,function()use(&$calls):bool{$calls++;return true;},function(string $previousPayload,float $previousAt,float $now)use(&$duplicateHooks):void{$duplicateHooks++;});
    liveToursCheck('duplicate show tours callback is consumed',$second);
    liveToursCheck('duplicate show tours callback does not repeat outbound callback',$calls===1);
    liveToursCheck('duplicate compatibility hook runs exactly once',$duplicateHooks===1);
    $lines=is_file($diagnosticFile)?file($diagnosticFile,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES):[];
    $last=$lines?json_decode((string)end($lines),true):null;
    liveToursCheck('duplicate show tours structured reason is preserved',($last['data']['reason']??null)==='duplicate');
    liveToursCheck('duplicate show tours structured scope is preserved',($last['data']['scope']??null)===$scope);
    liveToursCheck('duplicate show tours structured payload is preserved',($last['data']['payload']??null)==='show_tours');
}finally{
    @unlink($diagnosticFile);
    @unlink($lock);
    DiagnosticLogger::setFile('');
}

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
