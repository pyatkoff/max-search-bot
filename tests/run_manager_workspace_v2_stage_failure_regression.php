<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$js=(string)file_get_contents($root.'/manager/assets/workspace-v2-lead-card.js');
$passed=0;$failed=0;
function sfCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

sfCheck('stage mutation catches rejected async requests',strpos($js,"catch(e){if(stage.isConnected)stage.value=previousStage;if(sameLead(target))window.WorkspaceV2Pipeline?.setSalesSaveState('Не удалось изменить этап','error')}")!==false);
sfCheck('stage mutation restores previous value for explicit API failure',substr_count($js,'stage.value=previousStage')>=2);
sfCheck('stage mutation reports failure for both false result and rejection',substr_count($js,"setSalesSaveState('Не удалось изменить этап','error')")>=2);
sfCheck('stage mutation always restores control availability',strpos($js,'finally{if(stage.isConnected)stage.disabled=false}')!==false);
sfCheck('stage mutation refreshes lead data only after successful write',strpos($js,"await window.WorkspaceV2Conversation?.refreshLeadData({refreshInbox:true,conversationId:target});if(sameLead(target))window.WorkspaceV2Pipeline?.setSalesSaveState('Этап сохранён','success')")!==false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
