<?php

declare(strict_types=1);

$root=dirname(__DIR__);
require_once $root.'/services/ManagerAuthService.php';

$conversation=(string)file_get_contents($root.'/services/ManagerConversationService.php');
$routing=(string)file_get_contents($root.'/services/RoutingAccessService.php');
$request=(string)file_get_contents($root.'/services/ManagerRequestContext.php');
$auth=(string)file_get_contents($root.'/services/ManagerAuthService.php');

$passed=0;$failed=0;
function mrpCheck(string $name,bool $ok):void{global $passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

mrpCheck('admin role policy preserves exact admin semantics',ManagerAuthService::isAdmin(['role'=>'admin'])===true&&ManagerAuthService::isAdmin(['role'=>'manager'])===false&&ManagerAuthService::isAdmin([])===false&&ManagerAuthService::isAdmin(null)===false);
mrpCheck('working policy preserves truthy shift semantics',ManagerAuthService::isWorking(['is_working'=>true])===true&&ManagerAuthService::isWorking(['is_working'=>1])===true&&ManagerAuthService::isWorking(['is_working'=>0])===false&&ManagerAuthService::isWorking([])===false&&ManagerAuthService::isWorking(null)===false);
mrpCheck('request context delegates admin policy',strpos($request,'return ManagerAuthService::isAdmin($manager);')!==false&&strpos($request,"['role']")===false);
mrpCheck('routing delegates admin and working policy',strpos($routing,'ManagerAuthService::isAdmin($manager)')!==false&&strpos($routing,'ManagerAuthService::isWorking($manager)')!==false&&strpos($routing,"['role']")===false&&strpos($routing,"['is_working']")===false);
mrpCheck('conversation service delegates admin policy',substr_count($conversation,'ManagerAuthService::isAdmin($manager)')>=2&&strpos($conversation,"['role']")===false);
mrpCheck('auth service is canonical role policy owner',strpos($auth,'function isAdmin(?array $manager)')!==false&&strpos($auth,'function isWorking(?array $manager)')!==false);

$total=$passed+$failed;
echo "\n--------------------------\nTOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
