<?php

declare(strict_types=1);

$base=dirname(__DIR__);
$service=(string)file_get_contents($base.'/services/ManagerConversationService.php');
$api=(string)file_get_contents($base.'/manager/api.php');
$access=(string)file_get_contents($base.'/services/ProjectAccessService.php');
$availability=(string)file_get_contents($base.'/services/ManagerAvailabilityService.php');
$admin=(string)file_get_contents($base.'/services/AdminDirectoryService.php');
$routing=(string)file_get_contents($base.'/services/RoutingAdminService.php');
$workspace=(string)file_get_contents($base.'/manager/index.php');
$workspaceJs=(string)file_get_contents($base.'/manager/assets/workspace-v2.js');
$adminJs=(string)file_get_contents($base.'/manager/assets/admin.js');
$workspaceNotifications=(string)file_get_contents($base.'/manager/assets/workspace-v2-notifications.js');
$managerPush=(string)file_get_contents($base.'/services/ManagerPushService.php');
$serviceWorker=(string)file_get_contents($base.'/manager/sw.js');
$diagnostics=(string)file_get_contents($base.'/.github/workflows/publish-conversation-diagnostics.yml');
$liveDiagnostics=(string)file_get_contents($base.'/.github/workflows/live-session-diagnostics.yml');
$auditMigration=(string)file_get_contents($base.'/migrations/006_admin_audit_log.sql');
$productionSnapshot=(string)file_get_contents($base.'/tools/production_snapshot.php');

$passed=0;$failed=0;
function mvCheck(string $name,bool $ok):void{global $passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

mvCheck('ordinary all queue includes unassigned plus own',strpos($service,"(c.manager_id IS NULL OR c.manager_id=?)")!==false);
mvCheck('own-only restriction is only for non-admin',strpos($service,"if(!\$isAdmin){\$where[]='(c.manager_id IS NULL OR c.manager_id=?)'")!==false);
mvCheck('admin manager filter is optional',strpos($service,"if(\$isAdmin && \$queue!=='mine')")!==false);
mvCheck('admin unassigned filter exists',strpos($service,"\$managerFilter==='unassigned'")!==false);
mvCheck('admin mine filter exists',strpos($service,"\$managerFilter==='mine'")!==false);
mvCheck('api forwards manager filter only for admin',strpos($api,"\$isAdmin?(string)(\$data['manager_filter']??''):''")!==false);
mvCheck('off-shift all queue falls back to mine only',strpos($api,"!\$isAdmin&&\$queue==='all'&&!ManagerAvailabilityService::isWorking")!==false);
mvCheck('off-shift waiting queue is not forcibly emptied',strpos($api,"\$counts['waiting']=['count'=>0,'unread'=>0]")===false);
mvCheck('waiting queue includes taken conversations awaiting first reply',strpos($service,"\$queue==='attention' || \$queue==='waiting'")!==false && strpos($service,"awaitingFirstReplySql('c')")!==false);
mvCheck('awaiting first reply requires manager outbound after latest request',strpos($service,"mr.sender_type='manager'")!==false && strpos($service,"mr.created_at>=")!==false);
mvCheck('urgent queue keeps ordinary manager scoped to own assignment',strpos($service,"if(!\$isAdmin){\$where[]='(c.manager_id IS NULL OR c.manager_id=?)';\$args[]=\$managerId;}")!==false);
mvCheck('urgent queue exposes latest request and awaiting marker',strpos($service,' AS manager_request_at,CASE WHEN ')!==false && strpos($service,' AS awaiting_first_reply')!==false);
mvCheck('urgent queue sorts oldest manager request first',strpos($service,"COALESCE(manager_request_at,c.last_message_at,c.started_at) ASC")!==false);

mvCheck('runtime does not auto-promote first manager',strpos($access,"UPDATE managers SET role='admin'")===false);
mvCheck('runtime does not auto-attach managers without project',strpos($access,'mp.manager_id IS NULL')===false);
mvCheck('working status writes audit',strpos($availability,'AuditLogService::record')!==false);
mvCheck('manager and project admin writes audit',substr_count($admin,'AuditLogService::record')>=2);
mvCheck('routing writes audit',substr_count($routing,'AuditLogService::record')>=2);
mvCheck('admin snapshot keeps directory when priority surface fails',strpos($api,"try{\n        \$admin['priority']=ManagerPriorityService::snapshot()")!==false && strpos($api,"\$admin['priority_available']=false")!==false && strpos($api,"\$admin['priority']=['rules'=>[],'rule_types'=>[]]")!==false);
mvCheck('admin UI disables only priority controls when priority snapshot is unavailable',strpos($adminJs,'priorityAvailable:false')!==false && strpos($adminJs,'S.priorityAvailable=S.data.priority_available===true')!==false && strpos($adminJs,"['ruleManager','ruleType','ruleValue','ruleBonus','ruleComment','ruleActive','saveRule']")!==false && strpos($adminJs,'el.disabled=!priorityAvailable')!==false);
mvCheck('admin UI explains priority outage while keeping manager directory rendering independent',strpos($adminJs,'Правила приоритета временно недоступны. Менеджеры и проекты продолжают работать.')!==false && strpos($adminJs,"$('managers').innerHTML=(d.managers||[])")!==false && strpos($adminJs,"if(!S.priorityAvailable||$('saveRule').disabled)return")!==false);
mvCheck('canonical manager entrypoint owns workspace markup',strpos($workspace,'id="workspaceRoot"')!==false && !is_file($base.'/manager/workspace-v2.php'));
mvCheck('explicit index URL canonicalizes to root without network redirect',strpos($workspaceJs,'history.replaceState')!==false && strpos($workspaceJs,'/\\/index\\.php$/')!==false && strpos($workspaceJs,'workspace-v2')===false && strpos($workspaceJs,'location.replace(')===false);
mvCheck('Workspace contains admin settings link',strpos($workspace,'id="adminLink"')!==false && strpos($workspace,'href="admin.php"')!==false && strpos($workspace,'Настройки и менеджеры')!==false);
mvCheck('Workspace admin settings link starts hidden',strpos($workspace,'id="adminLink" class="actionBtn hidden"')!==false);
mvCheck('Workspace admin settings link is role gated',strpos($workspaceJs,"adminLink.classList.toggle('hidden',S.manager.role!=='admin')")!==false);
mvCheck('Workspace registers manager service worker',strpos($workspaceNotifications,"serviceWorker.register('sw.js'")!==false);
mvCheck('Workspace notification click opens exact conversation',strpos($workspaceNotifications,"data.type==='OPEN_CONVERSATION'")!==false && strpos($workspaceNotifications,'WorkspaceV2Conversation?.open(Number(data.conversationId))')!==false);
mvCheck('server push includes project source and channel context',strpos($managerPush,"\$c['project_name']??\$c['project_key']")!==false && strpos($managerPush,"\$c['source_name']")!==false && strpos($managerPush,"\$c['channel']")!==false);
mvCheck('server push includes customer/body context',strpos($managerPush,"'body'=>(\$ctx?implode(' · ',\$ctx).' — ':'').\$body")!==false);
mvCheck('service worker targets exact conversation',strpos($serviceWorker,"conversationId:Number(data.conversationId||0)")!==false && strpos($serviceWorker,"postMessage({type:'OPEN_CONVERSATION',conversationId})")!==false);
mvCheck('audit table is versioned migration',strpos($auditMigration,'CREATE TABLE IF NOT EXISTS admin_audit_log')!==false);
mvCheck('production snapshot exposes entry-channel attribution',strpos($productionSnapshot,"'recent_entry_attribution'=>[]")!==false && strpos($productionSnapshot,"entry_channel IS NOT NULL AND entry_channel<>''")!==false);
mvCheck('production snapshot exposes manager priority push evidence',strpos($productionSnapshot,"'recent_manager_priority_events'=>[]")!==false && strpos($productionSnapshot,"'manager_priority','push_selected'")!==false);
mvCheck('priority diagnostics only tail bounded structured log',strpos($productionSnapshot,"tail -n 500")!==false && strpos($productionSnapshot,"if(count(\$matched)>=\$limit)break")!==false);
mvCheck('production gate checks deployed sha',strpos($diagnostics,'production_sha_mismatch')!==false);
mvCheck('production gate checks migration health',strpos($diagnostics,'migration_health_failed')!==false);
mvCheck('production gate checks manager visibility',strpos($diagnostics,'manager_visibility_health_failed')!==false);
mvCheck('live diagnostics waits for current deployed sha',strpos($liveDiagnostics,'Wait for production SHA')!==false && strpos($liveDiagnostics,'EXPECTED_SHA: ${{ github.sha }}')!==false);
mvCheck('live diagnostics fails stale production sha',strpos($liveDiagnostics,'production_sha_mismatch')!==false);

$total=$passed+$failed;
echo "\n--------------------------\nTOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
