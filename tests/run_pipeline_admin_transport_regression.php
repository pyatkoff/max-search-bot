<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$js=(string)file_get_contents($root.'/manager/assets/pipeline-admin.js');
$api=(string)file_get_contents($root.'/manager/pipeline-api.php');
$service=(string)file_get_contents($root.'/services/SalesPipelineCatalogAdminService.php');

$passed=0;$failed=0;
function pipelineAdminCheck(string $name,bool $ok):void{global $passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

pipelineAdminCheck('pipeline admin has one guarded API boundary',strpos($js,'async function safeApi(')!==false&&strpos($js,'try{return await api(action,data)}catch(e)')!==false);
pipelineAdminCheck('identity transport failure is explicit and does not reveal admin app',strpos($js,"try{me=await ManagerHttpClient.request('me')}catch(e){gateMessage('Не удалось проверить доступ. Проверьте соединение и обновите страницу.');return}")!==false&&preg_match("/catch\(e\)\{gateMessage\([^}]+return\}[\\s\\S]*?if\(!me\.ok/",$js)===1);
pipelineAdminCheck('role denial remains distinct from transport failure',strpos($js,"gateMessage('Доступно только администратору.')")!==false&&strpos($js,"gateMessage('Не удалось проверить доступ. Проверьте соединение и обновите страницу.')")!==false);
pipelineAdminCheck('catalog load reports transport failure',strpos($js,"safeApi('admin_catalog',{},'Не удалось загрузить этапы и теги. Проверьте соединение и повторите попытку.')")!==false);
pipelineAdminCheck('stage save reports transport failure and preserves form',strpos($js,"safeApi('save_stage'")!==false&&strpos($js,"'Этап не сохранён. Проверьте соединение и повторите попытку.'")!==false&&preg_match("/safeApi\('save_stage'[\\s\\S]*?if\(!r\)return;[\\s\\S]*?clearStage\(\)/",$js)===1);
pipelineAdminCheck('tag save reports transport failure and preserves form',strpos($js,"safeApi('save_tag'")!==false&&strpos($js,"'Тег не сохранён. Проверьте соединение и повторите попытку.'")!==false&&preg_match("/safeApi\('save_tag'[\\s\\S]*?if\(!r\)return;[\\s\\S]*?clearTag\(\)/",$js)===1);
pipelineAdminCheck('pipeline catalog service distinguishes duplicate keys from storage failures',strpos($service,'isDuplicateKeyFailure($e)')!==false&&strpos($service,"'duplicate_stage_key':'save_failed'")!==false&&strpos($service,"'duplicate_tag_key':'save_failed'")!==false&&strpos($service,'$driverCode===1062')!==false);
pipelineAdminCheck('pipeline API maps catalog conflict server and not-found statuses',strpos($api,'function pipelineCatalogSaveStatus(')!==false&&strpos($api,"['duplicate_stage_key','duplicate_tag_key']")!==false&&strpos($api,"if(\$error==='save_failed')return 500;")!==false&&strpos($api,"if(\$error==='not_found')return 404;")!==false);
pipelineAdminCheck('stage backend failures have specific operator messages',strpos($js,'function stageErrorText(')!==false&&strpos($js,"duplicate_stage_key:'Этап с таким кодом уже существует.'")!==false&&strpos($js,"save_failed:'Этап не сохранён из-за ошибки сервера. Повторите попытку.'")!==false&&strpos($js,"status(stageErrorText(r.error,r.usage_count))")!==false);
pipelineAdminCheck('tag backend failures have specific operator messages',strpos($js,'function tagErrorText(')!==false&&strpos($js,"duplicate_tag_key:'Тег с таким кодом уже существует.'")!==false&&strpos($js,"save_failed:'Тег не сохранён из-за ошибки сервера. Повторите попытку.'")!==false&&strpos($js,"status(tagErrorText(r.error,r.usage_count))")!==false);
pipelineAdminCheck('validation errors remain distinct from server and transport failures',strpos($js,"invalid_stage_key:'Код этапа должен содержать")!==false&&strpos($js,"invalid_tag_key:'Код тега должен содержать")!==false&&strpos($js,"invalid_display_name:'Укажите название этапа")!==false&&strpos($js,"invalid_display_name:'Укажите название тега")!==false);
pipelineAdminCheck('stage duplicate submit is ignored while save is in flight',strpos($js,'if(S.saving.stage)return;')!==false&&preg_match("/setFormSaving\('stage',true\);[\\s\\S]*?safeApi\('save_stage'[\\s\\S]*?setFormSaving\('stage',false\);/",$js)===1);
pipelineAdminCheck('tag duplicate submit is ignored while save is in flight',strpos($js,'if(S.saving.tag)return;')!==false&&preg_match("/setFormSaving\('tag',true\);[\\s\\S]*?safeApi\('save_tag'[\\s\\S]*?setFormSaving\('tag',false\);/",$js)===1);
pipelineAdminCheck('submit buttons are disabled only during their own save',strpos($js,"const form=$(kind==='stage'?'stageForm':'tagForm');")!==false&&strpos($js,"submit.disabled=Boolean(saving)")!==false);

$total=$passed+$failed;
echo "\n--------------------------\nTOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
