<?php

declare(strict_types=1);

$base=dirname(__DIR__);
$service=(string)file_get_contents($base.'/services/ManagerPushHealth.php');
$snapshot=(string)file_get_contents($base.'/tools/production_snapshot.php');
$sw=(string)file_get_contents($base.'/manager/sw.js');
$enable=(string)file_get_contents($base.'/manager/push-enable.php');
$passed=0;$failed=0;
function mphCheck(string $name,bool $ok):void{global $passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

mphCheck('push health scopes to active working managers',strpos($service,'is_active=1 AND is_working=1')!==false);
mphCheck('push health exposes missing subscriptions',strpos($service,"'missing_subscription_manager_ids'")!==false && strpos($service,"'subscription_count'")!==false);
mphCheck('push health exposes latest send errors',strpos($service,"'recent_error_manager_ids'")!==false && strpos($service,'last_error_at')!==false && strpos($service,"'last_error'")!==false);
mphCheck('push health fails soft and exposes diagnostic error',strpos($service,'catch (Throwable $e)')!==false && strpos($service,"'error' => get_class(\$e)")!==false);
mphCheck('production snapshot includes manager push health',strpos($snapshot,"ManagerPushHealth.php")!==false && strpos($snapshot,"'manager_push_health'")!==false && strpos($snapshot,"'manager_push_ok'")!==false);
mphCheck('service worker repairs server push subscription',strpos($sw,'syncPushSubscription')!==false && strpos($sw,"push.php?action=key")!==false && strpos($sw,"action:'subscribe'")!==false);
mphCheck('service worker repairs subscription on activation',strpos($sw,"self.addEventListener('activate'")!==false && strpos($sw,'await syncPushSubscription()')!==false);
mphCheck('service worker replaces stale VAPID subscription',strpos($sw,'applicationServerKey')!==false && strpos($sw,'await sub.unsubscribe()')!==false);
mphCheck('push enable replaces stale VAPID subscription',strpos($enable,'applicationServerKey')!==false && strpos($enable,'await sub.unsubscribe()')!==false && strpos($enable,"action:'subscribe'")!==false);

$total=$passed+$failed;
echo "\n--------------------------\nTOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
