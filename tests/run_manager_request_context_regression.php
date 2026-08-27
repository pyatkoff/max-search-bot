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
ctxCheck('session cookie policy stays centralized',strpos($source,"'path' => '/max-search/manager/'")!==false&&strpos($source,"'secure' => true")!==false&&strpos($source,"'httponly' => true")!==false&&strpos($source,"'samesite' => 'Lax'")!==false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
