<?php

declare(strict_types=1);
require_once __DIR__ . '/../services/InteractionGuard.php';

$passed=0;$failed=0;
function emCheck(string $name,$actual,$expected):void{global$passed,$failed;if($actual===$expected){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}: expected ".var_export($expected,true).", got ".var_export($actual,true)."\n";$failed++;}

$source=(string)file_get_contents(__DIR__.'/../actions/callbacks/EditCallbackAction.php');
emCheck('edit menu delegates duplicate safety to shared guard',strpos($source,"InteractionGuard::suppressDuplicateCallback(\$chatId, 'edit_params', 'edit_menu', 2.0)")!==false,true);
emCheck('edit menu no longer owns file locking',strpos($source,'fopen(')===false&&strpos($source,'flock(')===false,true);
emCheck('legacy duplicate text log remains for operational continuity',strpos($source,'DUPLICATE_EDIT_MENU_CALLBACK_SKIPPED')!==false,true);

emCheck('generic first callback timing is allowed',InteractionGuard::isDuplicate('edit_params',0.0,'edit_params',100.0,2.0),false);
emCheck('generic immediate duplicate is suppressed',InteractionGuard::isDuplicate('edit_params',100.0,'edit_params',100.0,2.0),true);
emCheck('generic one-second duplicate is suppressed',InteractionGuard::isDuplicate('edit_params',100.0,'edit_params',101.0,2.0),true);
emCheck('generic two-second callback is allowed',InteractionGuard::isDuplicate('edit_params',100.0,'edit_params',102.0,2.0),false);
emCheck('generic older timestamp does not suppress',InteractionGuard::isDuplicate('edit_params',101.0,'edit_params',100.0,2.0),false);

$chatId=random_int(1000000,9999999);
$scope='edit_menu';
@unlink(InteractionGuard::lockPath($chatId,'dedupe.'.$scope));
$diagnosticFile=sys_get_temp_dir().'/max-search-edit-menu-'.bin2hex(random_bytes(4)).'.log';
DiagnosticLogger::setFile($diagnosticFile);
try{
    emCheck('first edit-menu callback passes centralized guard',InteractionGuard::suppressDuplicateCallback($chatId,'edit_params',$scope,2.0),false);
    emCheck('second edit-menu callback is consumed centrally',InteractionGuard::suppressDuplicateCallback($chatId,'edit_params',$scope,2.0),true);
    $lines=is_file($diagnosticFile)?file($diagnosticFile,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES):[];
    $last=$lines?json_decode((string)end($lines),true):null;
    emCheck('central duplicate diagnostic reason',$last['data']['reason']??null,'duplicate');
    emCheck('central duplicate diagnostic scope',$last['data']['scope']??null,$scope);
    emCheck('central duplicate diagnostic payload',$last['data']['payload']??null,'edit_params');
}finally{
    @unlink($diagnosticFile);
    @unlink(InteractionGuard::lockPath($chatId,'dedupe.'.$scope));
    DiagnosticLogger::setFile('');
}

$total=$passed+$failed;echo "\nTOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";exit($failed?1:0);
