<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$leadCardJs=(string)file_get_contents($root.'/manager/assets/workspace-v2-lead-card.js');
$pipelineJs=(string)file_get_contents($root.'/manager/assets/workspace-v2-pipeline.js');
$inboxJs=(string)file_get_contents($root.'/manager/assets/workspace-v2-inbox.js');
$kanbanJs=(string)file_get_contents($root.'/manager/assets/workspace-v2-kanban.js');
$js=$leadCardJs."\n".$pipelineJs;
$css=(string)file_get_contents($root.'/manager/assets/workspace-v2.css')."\n".(string)file_get_contents($root.'/manager/assets/workspace-v2-lead-card.css');
$api=(string)file_get_contents($root.'/manager/pipeline-api.php');
$http=(string)file_get_contents($root.'/manager/lib/ManagerHttp.php');
$service=(string)file_get_contents($root.'/services/SalesPipelineService.php');
$inboxService=(string)file_get_contents($root.'/services/ManagerLeadInboxService.php');
$context=(string)file_get_contents($root.'/services/ManagerRequestContext.php');
$migration=(string)file_get_contents($root.'/migrations/021_lead_sale_tracking.sql');
$passed=0;$failed=0;
function outcomeCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

outcomeCheck('catalog exposes outcomes and close reasons',strpos($api,"'outcomes'=>SalesPipelineService::outcomeOptions()")!==false&&strpos($api,"'close_reasons'=>SalesPipelineService::closeReasonOptions()")!==false);
outcomeCheck('detail snapshot includes outcome',strpos($service,"'outcome'=>self::outcomeForConversation")!==false);
outcomeCheck('workspace renders outcome controls',strpos($js,'id="leadOutcome"')!==false&&strpos($js,'id="leadCloseReason"')!==false&&strpos($js,'id="leadOutcomeNote"')!==false);
outcomeCheck('lost outcome requires structured reason',strpos($pipelineJs,"outcome==='lost'&&!closeReason")!==false&&strpos($service,"\$outcome==='lost'")!==false);
outcomeCheck('workspace persists outcome through pipeline API',strpos($pipelineJs,"pipe('set_outcome'")!==false&&strpos($api,"\$action==='set_outcome'")!==false&&strpos($api,'SalesPipelineService::setOutcome')!==false);
outcomeCheck('outcome controls respect shared pipeline ownership',strpos($leadCardJs,"canEdit?'':'disabled'")!==false&&strpos($leadCardJs,'WorkspaceV2Pipeline?.bindSalesEditor({canEdit,pipeline})')!==false&&strpos($api,'ManagerHttp::requireConversationEdit($c,$m);')!==false&&strpos($http,'ManagerRequestContext::canEditAssignedConversation')!==false&&strpos($context,'canEditAssignedConversation')!==false);
outcomeCheck('outcome UI has dedicated styling',strpos($css,'.outcomeBox')!==false&&strpos($css,'.outcomeNote')!==false&&strpos($css,'.outcomeSave')!==false);
outcomeCheck('outcome save is enabled only after a user edit',strpos($leadCardJs,'id="saveOutcome" class="actionBtn primary outcomeSave" type="button" disabled')!==false&&strpos($pipelineJs,'function setOutcomeDirty(dirty=true)')!==false&&strpos($pipelineJs,'button.disabled=!outcomeDirty')!==false);
outcomeCheck('all editable outcome fields mark state dirty',strpos($pipelineJs,'outcomeEl.onchange=')!==false&&strpos($pipelineJs,'reasonEl.onchange=')!==false&&strpos($pipelineJs,'noteEl.oninput=')!==false&&substr_count($pipelineJs,'setOutcomeDirty(true)')>=3);
outcomeCheck('dirty state is explicit and save errors keep edits retryable',strpos($pipelineJs,'Есть несохранённые изменения')!==false&&strpos($css,'.outcomeSaveStatus.dirty')!==false&&strpos($pipelineJs,"outcomeDirty=true;setOutcomeSaveState('Не удалось сохранить результат','error')")!==false);
outcomeCheck('transport failure keeps outcome edits retryable and surfaces the same error state',strpos($pipelineJs,"}catch(e){if(sameLead(target)){outcomeDirty=true;setOutcomeSaveState('Не удалось сохранить результат','error')}}finally{")!==false&&substr_count($pipelineJs,"setOutcomeSaveState('Не удалось сохранить результат','error')")>=2);
outcomeCheck('sale tracking uses forward-only conversation fields',strpos($migration,'lead_sale_amount DECIMAL(12,2)')!==false&&strpos($migration,'lead_sale_date DATE')!==false);
outcomeCheck('won outcome exposes amount and sale date controls',strpos($leadCardJs,'id="leadSaleWrap"')!==false&&strpos($leadCardJs,'id="leadSaleAmount"')!==false&&strpos($leadCardJs,'id="leadSaleDate"')!==false&&strpos($leadCardJs,"outcome.outcome==='won'")!==false);
outcomeCheck('sale fields are submitted only through existing outcome boundary',strpos($pipelineJs,'sale_amount:saleAmount')!==false&&strpos($pipelineJs,'sale_date:saleDate')!==false&&strpos($api,"isset(\$data['sale_amount'])")!==false&&strpos($api,"isset(\$data['sale_date'])")!==false);
outcomeCheck('backend owns sale validation and clears sale facts for non-won outcomes',strpos($service,"if(\$outcome==='won')")!==false&&strpos($service,'is_numeric($rawAmount)')!==false&&strpos($service,"createFromFormat('!Y-m-d'")!==false&&strpos($service,'lead_sale_amount=?,lead_sale_date=?')!==false);
outcomeCheck('sale edits participate in dirty-state protection',strpos($pipelineJs,'saleAmountEl.oninput=()=>setOutcomeDirty(true)')!==false&&strpos($pipelineJs,'saleDateEl.onchange=()=>setOutcomeDirty(true)')!==false);
outcomeCheck('lead inbox projection exposes persisted sale facts',strpos($inboxService,'c.lead_sale_amount,c.lead_sale_date')!==false&&strpos($inboxService,"\$row['lead_sale_amount']")!==false&&strpos($inboxService,"\$row['lead_sale_date']")!==false);
outcomeCheck('inbox owns shared sale summary formatting',strpos($inboxJs,'function saleSummary(c)')!==false&&strpos($inboxJs,'formatSaleAmount')!==false&&strpos($inboxJs,'formatSaleDate')!==false&&strpos($inboxJs,"String(c?.lead_outcome||'open')!=='won'")!==false);
outcomeCheck('list and Kanban surface won sale facts without new persistence path',strpos($inboxJs,"sale=saleSummary(c)")!==false&&strpos($kanbanJs,"sale=window.WorkspaceV2Inbox?.saleSummary(c)")!==false&&substr_count($inboxJs,'Продажа:')>=1&&substr_count($kanbanJs,'Продажа:')>=1);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
