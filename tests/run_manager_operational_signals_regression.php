<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/AdminDirectoryService.php';

$passed=0;$failed=0;
function sigCheck(string $name,$actual,$expected): void {
    global $passed,$failed;
    if($actual===$expected){echo "PASS  {$name}\n";$passed++;return;}
    echo "FAIL  {$name}\n";
    echo '      expected: '.json_encode($expected,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    echo '      actual:   '.json_encode($actual,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    $failed++;
}

$workingUnreachable=AdminDirectoryService::withOperationalSignals(
    ['id'=>4,'is_working'=>1],
    ['notification_path_usable'=>false,'notification_path_reason'=>'no_subscription','subscription_count'=>0,'healthy_subscription_count'=>0,'last_success_at'=>null,'last_error_at'=>null]
);
sigCheck('working flag remains true when push is unreachable',$workingUnreachable['is_working'],true);
sigCheck('reachability remains independently false',$workingUnreachable['is_reachable'],false);
sigCheck('unreachable reason is exposed',$workingUnreachable['reachability_reason'],'no_subscription');

$workingReachable=AdminDirectoryService::withOperationalSignals(
    ['id'=>5,'is_working'=>1],
    ['notification_path_usable'=>true,'notification_path_reason'=>'healthy_subscription','subscription_count'=>2,'healthy_subscription_count'=>2,'last_success_at'=>'2026-08-26 12:00:00','last_error_at'=>null]
);
sigCheck('working reachable manager stays working',$workingReachable['is_working'],true);
sigCheck('working reachable manager exposes reachable',$workingReachable['is_reachable'],true);
sigCheck('all subscriptions exposed',$workingReachable['push_subscription_count'],2);
sigCheck('healthy subscriptions exposed',$workingReachable['healthy_push_subscription_count'],2);

$offShiftReachable=AdminDirectoryService::withOperationalSignals(
    ['id'=>9,'is_working'=>0],
    ['notification_path_usable'=>true,'notification_path_reason'=>'healthy_subscription','subscription_count'=>1,'healthy_subscription_count'=>1]
);
sigCheck('reachable does not silently imply working',$offShiftReachable['is_working'],false);
sigCheck('technical reachability survives off-shift state',$offShiftReachable['is_reachable'],true);

$total=$passed+$failed;
echo "\n--------------------------\n";
echo "TOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed>0?1:0);
