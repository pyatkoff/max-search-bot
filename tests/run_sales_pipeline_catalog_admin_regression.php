<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$api=(string)file_get_contents($root.'/manager/pipeline-api.php');
$page=(string)file_get_contents($root.'/manager/pipeline-admin.php');
$js=(string)file_get_contents($root.'/manager/assets/pipeline-admin.js');
$css=(string)file_get_contents($root.'/manager/assets/pipeline-admin.css');
$svc=(string)file_get_contents($root.'/services/SalesPipelineCatalogAdminService.php');
$client=(string)file_get_contents($root.'/manager/assets/manager-http-client.js');

$passed=0;$failed=0;
function pcCheck(string $name,bool $ok):void{global $passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;}else{echo "FAIL  {$name}\n";$failed++;}}

pcCheck('pipeline catalog writes are admin gated',substr_count($api,'ManagerHttp::requireAdmin($m)')>=3&&strpos($api,"$action==='save_stage'")!==false&&strpos($api,"$action==='save_tag'")!==false);
pcCheck('catalog service owns stage and tag persistence',strpos($svc,'final class SalesPipelineCatalogAdminService')!==false&&strpos($svc,'UPDATE lead_stages')!==false&&strpos($svc,'UPDATE lead_tags')!==false);
pcCheck('catalog changes enter audit trail',strpos($svc,"'lead_stage_updated'")!==false&&strpos($svc,"'lead_tag_updated'")!==false&&strpos($svc,'AuditLogService::record')!==false);
pcCheck('won stage is always terminal',strpos($svc,'if($won)$terminal=1;')!==false);
pcCheck('admin page states business funnel boundary',strpos($page,'Технические состояния диалога здесь не меняются')!==false&&strpos($page,'pipeline-admin.js')!==false&&strpos($page,'pipeline-admin.css')!==false);
pcCheck('pipeline admin uses shared HTTP boundary',strpos($js,"ManagerHttpClient.request(action,data,S.csrf,'pipeline-api.php')")!==false&&strpos($client,"endpoint='api.php'")!==false&&strpos($client,'fetch(endpoint')!==false);
pcCheck('pipeline admin has responsive layout contracts',strpos($css,'@media(max-width:760px)')!==false&&strpos($css,'@media(max-width:460px)')!==false);
pcCheck('stage key is immutable after selecting existing stage',strpos($js,"$('stageKey').readOnly=true")!==false);

$total=$passed+$failed;echo "\n--------------------------\nTOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";exit($failed?1:0);
