<?php

declare(strict_types=1);

$api=(string)file_get_contents(dirname(__DIR__).'/manager/api.php');
$ui=(string)file_get_contents(dirname(__DIR__).'/manager/index.php');
$conversations=(string)file_get_contents(dirname(__DIR__).'/services/ManagerConversationService.php');
$pipeline=(string)file_get_contents(dirname(__DIR__).'/services/SalesPipelineService.php');
$passed=0;$failed=0;
function pipelineUiCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

pipelineUiCheck('API exposes stage and tag catalog',strpos($api,"\$action==='pipeline_catalog'")!==false && strpos($api,'SalesPipelineService::stages()')!==false && strpos($api,'SalesPipelineService::tags()')!==false);
pipelineUiCheck('detail exposes business pipeline separately',strpos($api,"\$d['pipeline']=SalesPipelineService::conversationSnapshot")!==false && strpos($conversations,'c.status,c.lead_stage_key')!==false);
pipelineUiCheck('lead edits are role or ownership gated',strpos($api,'function canEditLead')!==false && strpos($api,"\$action==='set_lead_stage'")!==false && strpos($api,"\$action==='set_lead_tags'")!==false && substr_count($api,'canEditLead($d,$m,$isAdmin)')===2);
pipelineUiCheck('inbox supports stage filter',strpos($conversations,"\$where[]='c.lead_stage_key=?'")!==false && strpos($api,"'lead_stage_key'")!==false);
pipelineUiCheck('inbox supports tag filter',strpos($conversations,'EXISTS (SELECT 1 FROM conversation_lead_tags clt_filter')!==false && strpos($api,"'lead_tag_id'")!==false);
pipelineUiCheck('inbox rows expose stage and tags',strpos($pipeline,'decorateConversationRows')!==false && strpos($conversations,'SalesPipelineService::decorateConversationRows($rows)')!==false);
pipelineUiCheck('manager UI has stage and tag filters',strpos($ui,'id="leadStageFilter"')!==false && strpos($ui,'id="leadTagFilter"')!==false && strpos($ui,'lead_stage_key:S.leadStageFilter')!==false && strpos($ui,'lead_tag_id:S.leadTagFilter')!==false);
pipelineUiCheck('manager UI renders editable lead controls',strpos($ui,'id="leadControls"')!==false && strpos($ui,'function renderLeadControls')!==false && strpos($ui,"api('set_lead_stage'")!==false && strpos($ui,"api('set_lead_tags'")!==false);
pipelineUiCheck('technical dialogue status remains visible beside business stage',strpos($ui,'statusText(c.status)')!==false && strpos($ui,'leadStageTag')!==false && strpos($conversations,"elseif(\$queue==='closed')")!==false);
pipelineUiCheck('contact information is surfaced near lead controls',strpos($ui,'c.phone?esc(c.phone)')!==false && strpos($conversations,'cu.display_name,cu.phone,cu.email')!==false);
pipelineUiCheck('routing source save contract remains intact',strpos($api,"(string)(\$data['source_key']??'')")!==false && strpos($api,'RoutingAdminService::saveSource')!==false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
