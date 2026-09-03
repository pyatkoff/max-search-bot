<?php

declare(strict_types=1);

$root=dirname(__DIR__);
require_once $root.'/services/ManagerConversationAccessPolicy.php';

$passed=0;$failed=0;
function accessCheck(string $name,bool $ok):void{global $passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

accessCheck('admin may edit a visible assigned conversation',ManagerConversationAccessPolicy::canEditVisibleConversation(['manager_id'=>99],['id'=>4,'role'=>'admin'])===true);
accessCheck('admin may edit a visible unassigned conversation',ManagerConversationAccessPolicy::canEditVisibleConversation(['manager_id'=>null],['id'=>4,'role'=>'admin'])===true);
accessCheck('admin role keeps legacy edit semantics without relying on assignment id',ManagerConversationAccessPolicy::canEditVisibleConversation(['manager_id'=>99],['role'=>'admin'])===true);
accessCheck('assigned manager may edit own visible conversation',ManagerConversationAccessPolicy::canEditVisibleConversation(['manager_id'=>4],['id'=>4,'role'=>'manager'])===true);
accessCheck('other manager may not edit a visible conversation',ManagerConversationAccessPolicy::canEditVisibleConversation(['manager_id'=>5],['id'=>4,'role'=>'manager'])===false);
accessCheck('unassigned conversation is not editable by ordinary manager',ManagerConversationAccessPolicy::canEditVisibleConversation(['manager_id'=>null],['id'=>4,'role'=>'manager'])===false);
accessCheck('missing manager identity cannot edit',ManagerConversationAccessPolicy::canEditVisibleConversation(['manager_id'=>4],['role'=>'manager'])===false);

$policy=(string)file_get_contents($root.'/services/ManagerConversationAccessPolicy.php');
$context=(string)file_get_contents($root.'/services/ManagerRequestContext.php');
$conversations=(string)file_get_contents($root.'/services/ManagerConversationService.php');
$http=(string)file_get_contents($root.'/manager/lib/ManagerHttp.php');
$pipeline=(string)file_get_contents($root.'/manager/pipeline-api.php');
$mainApi=(string)file_get_contents($root.'/manager/api.php');

accessCheck('policy is the single Manager conversation visibility delegate',strpos($policy,'RoutingAccessService::canSeeConversation($managerId, $conversation)')!==false&&substr_count($conversations,'ManagerConversationAccessPolicy::canView(')>=4&&strpos($conversations,'RoutingAccessService::canSeeConversation(')===false);
accessCheck('request context delegates visible-conversation edit ownership to policy',strpos($context,'ManagerConversationAccessPolicy::canEditVisibleConversation($conversation, $manager)')!==false&&strpos($context,'$assignedManagerId')===false);
accessCheck('Manager HTTP keeps authorization transport-only',strpos($http,'ManagerRequestContext::canEditAssignedConversation($conversation,$manager)')!==false&&strpos($http,'ManagerConversationAccessPolicy')===false&&strpos($http,'ManagerConversationService')===false);
accessCheck('main and pipeline APIs share policy-backed conversation service boundary',strpos($mainApi,'ManagerConversationService::detail(')!==false&&strpos($pipeline,'ManagerConversationService::visibleConversation(')!==false&&strpos($pipeline,'ManagerHttp::requireConversationEdit(')!==false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
