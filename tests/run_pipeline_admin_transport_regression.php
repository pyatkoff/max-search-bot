<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$js=(string)file_get_contents($root.'/manager/assets/pipeline-admin.js');

$passed=0;$failed=0;
function pipelineAdminCheck(string $name,bool $ok):void{global $passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

pipelineAdminCheck('pipeline admin has one guarded API boundary',strpos($js,'async function safeApi(')!==false&&strpos($js,'try{return await api(action,data)}catch(e)')!==false);
pipelineAdminCheck('identity transport failure is explicit and does not reveal admin app',strpos($js,"try{me=await ManagerHttpClient.request('me')}catch(e){gateMessage('Не удалось проверить доступ. Проверьте соединение и обновите страницу.');return}")!==false&&preg_match("/catch\(e\)\{gateMessage\([^}]+return\}[\\s\\S]*?if\(!me\.ok/",$js)===1);
pipelineAdminCheck('role denial remains distinct from transport failure',strpos($js,"gateMessage('Доступно только администратору.')")!==false&&strpos($js,"gateMessage('Не удалось проверить доступ. Проверьте соединение и обновите страницу.')")!==false);
pipelineAdminCheck('catalog load reports transport failure',strpos($js,"safeApi('admin_catalog',{},'Не удалось загрузить этапы и теги. Проверьте соединение и повторите попытку.')")!==false);
pipelineAdminCheck('stage save reports transport failure and preserves form',strpos($js,"safeApi('save_stage'")!==false&&strpos($js,"'Этап не сохранён. Проверьте соединение и повторите попытку.'")!==false&&preg_match("/safeApi\('save_stage'[\\s\\S]*?if\(!r\)return;[\\s\\S]*?clearStage\(\)/",$js)===1);
pipelineAdminCheck('tag save reports transport failure and preserves form',strpos($js,"safeApi('save_tag'")!==false&&strpos($js,"'Тег не сохранён. Проверьте соединение и повторите попытку.'")!==false&&preg_match("/safeApi\('save_tag'[\\s\\S]*?if\(!r\)return;[\\s\\S]*?clearTag\(\)/",$js)===1);
pipelineAdminCheck('server validation errors remain distinct from transport errors',strpos($js,"r.error==='stage_in_use'")!==false&&strpos($js,"r.error==='tag_in_use'")!==false&&strpos($js,'Проверьте код и название.')!==false);
pipelineAdminCheck('stage duplicate submit is ignored while save is in flight',strpos($js,'if(S.saving.stage)return;')!==false&&preg_match("/setFormSaving\('stage',true\);[\\s\\S]*?safeApi\('save_stage'[\\s\\S]*?setFormSaving\('stage',false\);/",$js)===1);
pipelineAdminCheck('tag duplicate submit is ignored while save is in flight',strpos($js,'if(S.saving.tag)return;')!==false&&preg_match("/setFormSaving\('tag',true\);[\\s\\S]*?safeApi\('save_tag'[\\s\\S]*?setFormSaving\('tag',false\);/",$js)===1);
pipelineAdminCheck('submit buttons are disabled only during their own save',strpos($js,"const form=$(kind==='stage'?'stageForm':'tagForm');")!==false&&strpos($js,"submit.disabled=Boolean(saving)")!==false);

$total=$passed+$failed;
echo "\n--------------------------\nTOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
