<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$js=(string)file_get_contents($root.'/manager/assets/workspace-v2-conversation.js');
$passed=0;$failed=0;
function lifecycleCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

lifecycleCheck('lifecycle actions keep explicit take release close reopen controls',strpos($js,"change('take')")!==false&&strpos($js,"change('release')")!==false&&strpos($js,"change('close')")!==false&&strpos($js,"change('reopen')")!==false);
lifecycleCheck('lifecycle mutation is guarded by shared busy state and owned completion',strpos($js,'async function change(a)')!==false&&strpos($js,'if(busy)return;')!==false&&strpos($js,'setBusy(true);setLoadStatus(copy.loading')!==false&&strpos($js,'if(lifecycleFlight===flight){lifecycleFlight=null;setBusy(false)')!==false);
lifecycleCheck('bound lifecycle buttons disable even after presentation moves them into a disclosure',strpos($js,"document.querySelectorAll('[data-conversation-lifecycle]')")!==false&&strpos($js,"b.dataset.conversationLifecycle='1'")!==false&&strpos($js,'b.disabled=busy')!==false&&strpos($js,'applyInteractionState();autoGrow()')!==false);
lifecycleCheck('lifecycle request is pinned to original conversation account and auth generation',strpos($js,'const target=Number(S.current||0),generation=openSeq')!==false&&strpos($js,'api(a,{conversation_id:target},{signal:controller?.signal})')!==false&&strpos($js,'S.authGeneration===flight.auth&&Number(S.manager?.id)===flight.manager')!==false);
lifecycleCheck('late lifecycle completion cannot reopen after navigation or change current files',strpos($js,'current=()=>sameSession()&&openSeq===generation&&Number(S.current)===target')!==false&&strpos($js,'if(current()){')!==false&&strpos($js,'open(target,{preserveMessageScroll:true,preserveHistory:true,preserveAttachment:true')!==false);
lifecycleCheck('successful mutation refreshes Inbox without retaining its write lock',strpos($js,'void Promise.resolve(window.WorkspaceV2Inbox?.load({preserveScroll:true})).catch(()=>{})')!==false&&strpos($js,'if(sameSession())try{void Promise.resolve')!==false);
lifecycleCheck('backend lifecycle reasons are visible inline without text parsing',strpos($js,'lifecycleReasons[j.error]')!==false&&strpos($js,'conversationLifecycleStatus')!==false&&strpos($js,'message.textContent=lifecycleIssue.message')!==false);
lifecycleCheck('each lifecycle action has progress success and failure copy',strpos($js,"take:{loading:'Берём диалог…',success:'Диалог назначен вам',error:'Не удалось взять диалог'}")!==false&&strpos($js,"release:{loading:'Передаём диалог боту…'")!==false&&strpos($js,"close:{loading:'Закрываем диалог…'")!==false&&strpos($js,"reopen:{loading:'Переоткрываем диалог…'")!==false);
lifecycleCheck('send path shares busy lock with lifecycle mutations',strpos($js,'async function sendReply(')!==false&&strpos($js,'if(busy||(deliverySuspended()&&!pendingAttempt()))return;')!==false&&substr_count($js,'setBusy(true)')>=2&&substr_count($js,'setBusy(false)')>=2);
lifecycleCheck('unknown result blocks automatic mutation and send until current-state check',strpos($js,'if(accessLost||S.authExpired||lifecycleBlocked())return;')!==false&&substr_count($js,'if(accessLost||S.authExpired||lifecycleBlocked())return;')>=2&&strpos($js,'const lifecycleUnknown=')!==false&&strpos($js,'Результат действия не подтверждён')!==false);
lifecycleCheck('explicit state check uses bounded visibility-only API and never infers operation receipt',strpos($js,"api('lifecycle_state',{conversation_id:issue.target},{isCurrent:current,signal:controller?.signal})")!==false&&strpos($js,'Это не подтверждение предыдущего действия.')!==false&&strpos($js,'lifecycleIssue===issue&&issue.read===read&&lifecycleBlocked()&&openSeq===generation')!==false);
lifecycleCheck('confirmed save stays confirmed across later refresh failure',strpos($js,'confirmed=true;')!==false&&strpos($js,"copy.success+'. Переписка пока не обновлена.")!==false);
lifecycleCheck('bounded transport abort is not a retry and pending lock has an owner',strpos($js,'setTimeout(()=>controller.abort(),20000)')!==false&&strpos($js,'if(lifecycleFlight===flight)')!==false);
lifecycleCheck('slice does not alter protected analytics or routing boundaries',stripos($js,'metrika')===false&&stripos($js,'routing_bonus')===false&&stripos($js,'set_working')===false&&strpos($js,'LeadDestination')===false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
