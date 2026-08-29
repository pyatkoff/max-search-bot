<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/services/ManagerRequestContext.php';

$passed=0;$failed=0;
function ctxCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}
ctxCheck('admin may edit any assigned conversation',ManagerRequestContext::canEditAssignedConversation(['manager_id'=>99],['id'=>4,'role'=>'admin'])===true);
ctxCheck('assigned manager may edit own conversation',ManagerRequestContext::canEditAssignedConversation(['manager_id'=>4],['id'=>4,'role'=>'manager'])===true);
ctxCheck('other manager may not edit conversation',ManagerRequestContext::canEditAssignedConversation(['manager_id'=>5],['id'=>4,'role'=>'manager'])===false);
ctxCheck('unassigned conversation is not editable by ordinary manager',ManagerRequestContext::canEditAssignedConversation(['manager_id'=>null],['id'=>4,'role'=>'manager'])===false);
$_SESSION=[];
ctxCheck('missing csrf is invalid',ManagerRequestContext::validCsrf('anything')===false);
$_SESSION['csrf']='token-123';
ctxCheck('matching csrf is valid',ManagerRequestContext::validCsrf('token-123')===true);
ctxCheck('different csrf is invalid',ManagerRequestContext::validCsrf('token-456')===false);
ctxCheck('existing csrf is preserved',ManagerRequestContext::csrf(true)==='token-123');
$source=(string)file_get_contents(dirname(__DIR__).'/services/ManagerRequestContext.php');
$http=(string)file_get_contents(dirname(__DIR__).'/manager/lib/ManagerHttp.php');
$api=(string)file_get_contents(dirname(__DIR__).'/manager/api.php');
$workspace=(string)file_get_contents(dirname(__DIR__).'/manager/index.php');
$admin=(string)file_get_contents(dirname(__DIR__).'/manager/admin.php');
$adminJs=(string)file_get_contents(dirname(__DIR__).'/manager/assets/admin.js');
$routing=(string)file_get_contents(dirname(__DIR__).'/manager/routing.php');
$routingJs=(string)file_get_contents(dirname(__DIR__).'/manager/assets/routing.js');
ctxCheck('session cookie policy stays centralized',strpos($source,"'path' => '/max-search/manager/'")!==false&&strpos($source,"'secure' => true")!==false&&strpos($source,"'httponly' => true")!==false&&strpos($source,"'samesite' => 'Lax'")!==false);
ctxCheck('Manager HTTP boundary delegates session to shared context',strpos($http,'ManagerRequestContext::startSession()')!==false&&strpos($http,'session_set_cookie_params')===false);
ctxCheck('Manager HTTP boundary delegates manager csrf and admin lookup',strpos($http,'ManagerRequestContext::manager()')!==false&&strpos($http,'ManagerRequestContext::csrf($rotate)')!==false&&strpos($http,'ManagerRequestContext::validCsrf')!==false&&strpos($http,'ManagerRequestContext::isAdmin($manager)')!==false);
ctxCheck('main manager API delegates request lifecycle to Manager HTTP boundary',strpos($api,"require_once __DIR__ . '/lib/ManagerHttp.php'")!==false&&strpos($api,'ManagerHttp::startJson();')!==false&&strpos($api,'ManagerHttp::body();')!==false&&strpos($api,'ManagerHttp::manager();')!==false&&strpos($api,'ManagerHttp::csrf(true);')!==false&&strpos($api,'ManagerHttp::requireCsrf($data);')!==false&&strpos($api,'ManagerHttp::requireAdmin($m);')!==false&&strpos($api,'ManagerHttp::isAdmin($m);')!==false&&strpos($api,'ManagerRequestContext::')===false);
ctxCheck('login and me still return csrf tokens',strpos($api,"'csrf'=>csrf()")!==false&&substr_count($api,"'csrf'=>csrf()")>=2);
ctxCheck('manager lifecycle actions remain intact',strpos($api,"\$action==='take'")!==false&&strpos($api,"\$action==='release'")!==false&&strpos($api,"\$action==='close'")!==false&&strpos($api,"\$action==='reopen'")!==false&&strpos($api,"\$action==='send'")!==false);
ctxCheck('canonical Manager shell delegates session to shared context',strpos($workspace,'ManagerRequestContext::startSession()')!==false&&strpos($workspace,'session_set_cookie_params')===false&&strpos($workspace,'session_start()')===false);
ctxCheck('admin shell delegates session to shared context',strpos($admin,'ManagerRequestContext::startSession()')!==false&&strpos($admin,'session_set_cookie_params')===false&&strpos($admin,'session_start()')===false);
ctxCheck('routing shell delegates session to shared context',strpos($routing,'ManagerRequestContext::startSession()')!==false&&strpos($routing,'session_set_cookie_params')===false&&strpos($routing,'session_start()')===false);
ctxCheck('admin and routing still gate product actions through me/admin API contract',strpos($adminJs,"j.manager.role!=='admin'")!==false&&strpos($routingJs,"j.manager.role!=='admin'")!==false&&strpos($adminJs,"api('admin_snapshot')")!==false&&strpos($routingJs,"api('routing_snapshot'")!==false);
echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
