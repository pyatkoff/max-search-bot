<?php

declare(strict_types=1);
$root=dirname(__DIR__);
$service=(string)file_get_contents($root.'/services/ManagerConversationService.php');
$api=(string)file_get_contents($root.'/manager/api.php');
$lead=(string)file_get_contents($root.'/manager/assets/workspace-v2-lead-card.js');
$css=(string)file_get_contents($root.'/manager/assets/workspace-v2-lead-card.css');
$passed=0;$failed=0;
function ownerCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}
ownerCheck('manager API exposes admin-only reassignment',strpos($api,"if(\$action==='reassign_manager')")!==false&&strpos($api,'ManagerHttp::requireAdmin($m);')!==false&&strpos($api,'ManagerConversationService::reassign')!==false);
ownerCheck('reassignment rejects inactive unknown or inaccessible target',strpos($service,'ManagerAuthService::byId($targetManagerId)')!==false&&strpos($service,'RoutingAccessService::canSeeConversation($targetManagerId,$row)')!==false&&strpos($service,"(string)\$row['status']==='closed'")!==false);
ownerCheck('reassignment serializes ownership changes',strpos($service,'accessibleConversation($conversationId,$adminId,true)')!==false&&strpos($service,'beginTransaction()')!==false&&strpos($service,'released_at=NOW() WHERE conversation_id=? AND released_at IS NULL')!==false);
ownerCheck('reassignment keeps assignment history',strpos($service,"'admin_reassign'")!==false&&strpos($service,"'manager_reassigned'")!==false&&strpos($service,"'from_manager_id'=>\$previous?:null")!==false&&strpos($service,"'to_manager_id'=>\$targetManagerId")!==false);
ownerCheck('lead owner editor is admin-only and unavailable on closed leads',strpos($lead,"S.manager?.role!=='admin'")!==false&&strpos($lead,"String(c?.status||'')==='closed'")!==false&&strpos($lead,'Ответственный менеджер')!==false);
ownerCheck('lead owner editor uses loaded manager directory',strpos($lead,'S.filterManagers')!==false&&strpos($lead,'leadOwnerManager')!==false&&strpos($lead,'leadOwnerSave')!==false);
ownerCheck('owner save is explicit and prevents same-owner mutation',strpos($lead,"target===current")!==false&&strpos($lead,"api('reassign_manager'")!==false&&strpos($lead,"button.textContent='Передаём…'")!==false);
ownerCheck('successful reassignment does not pull user back to a stale lead',strpos($lead,'if(sameLead(conversationId))await window.WorkspaceV2Conversation?.open')!==false&&strpos($lead,'WorkspaceV2Inbox?.load({preserveScroll:true})')!==false);
ownerCheck('owner editor has responsive lead-card styling',strpos($css,'.leadOwnerEditor')!==false&&strpos($css,'.leadOwnerEditorRow')!==false&&strpos($css,'grid-template-columns:1fr')!==false);
echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";exit($failed?1:0);
