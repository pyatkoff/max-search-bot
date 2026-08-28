<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$js=(string)file_get_contents($root.'/manager/assets/workspace-v2-conversation.js');
$passed=0;$failed=0;
function lifecycleCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

lifecycleCheck('lifecycle actions keep explicit take release close reopen controls',strpos($js,"change('take')")!==false&&strpos($js,"change('release')")!==false&&strpos($js,"change('close')")!==false&&strpos($js,"change('reopen')")!==false);
lifecycleCheck('lifecycle mutation is guarded by shared busy state',strpos($js,'async function change(a)')!==false&&strpos($js,'if(busy)return;')!==false&&strpos($js,'setBusy(true);setLoadStatus(copy.loading')!==false&&strpos($js,'finally{setBusy(false)}')!==false);
lifecycleCheck('conversation action buttons disable during an in-flight mutation',strpos($js,"document.querySelectorAll('#conversationActions button')")!==false&&strpos($js,'b.disabled=busy')!==false&&strpos($js,'applyInteractionState();autoGrow()')!==false);
lifecycleCheck('lifecycle request is pinned to the original conversation id',strpos($js,'const target=Number(S.current||0),generation=openSeq')!==false&&strpos($js,"api(a,{conversation_id:target})")!==false);
lifecycleCheck('late lifecycle completion cannot reopen a conversation after navigation',strpos($js,'const stillCurrent=openSeq===generation&&Number(S.current)===target')!==false&&strpos($js,'if(stillCurrent){const refreshed=await open(target')!==false);
lifecycleCheck('successful mutation still refreshes Inbox projection',strpos($js,'await window.WorkspaceV2Inbox?.load({preserveScroll:true})')!==false);
lifecycleCheck('backend lifecycle errors are visible inline',strpos($js,"j?.error_message||copy.error")!==false&&strpos($js,"setLoadStatus(copy.error,'error')")!==false);
lifecycleCheck('each lifecycle action has progress success and failure copy',strpos($js,"take:{loading:'Берём лид…',success:'Лид назначен вам',error:'Не удалось взять лид'}")!==false&&strpos($js,"release:{loading:'Возвращаем лид AI…'")!==false&&strpos($js,"close:{loading:'Закрываем диалог…'")!==false&&strpos($js,"reopen:{loading:'Переоткрываем диалог…'")!==false);
lifecycleCheck('send path shares busy lock with lifecycle mutations',strpos($js,'async function sendReply()')!==false&&strpos($js,'if(busy||deliverySuspended())return;')!==false&&substr_count($js,'setBusy(true)')>=2&&substr_count($js,'setBusy(false)')>=2);
lifecycleCheck('slice does not alter protected analytics or routing boundaries',stripos($js,'metrika')===false&&stripos($js,'routing_bonus')===false&&stripos($js,'set_working')===false&&strpos($js,'LeadDestination')===false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
