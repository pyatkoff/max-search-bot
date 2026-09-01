<?php

declare(strict_types=1);
$root=dirname(__DIR__);
$index=(string)file_get_contents($root.'/manager/index.php');
$core=(string)file_get_contents($root.'/manager/assets/workspace-v2.js');
$inbox=(string)file_get_contents($root.'/manager/assets/workspace-v2-inbox.js');
$filters=(string)file_get_contents($root.'/manager/assets/workspace-v2-filters.js');
$conversation=(string)file_get_contents($root.'/manager/assets/workspace-v2-conversation.js');
$jump=(string)file_get_contents($root.'/manager/assets/workspace-v2-jump.js');
$shortcuts=(string)file_get_contents($root.'/manager/assets/workspace-v2-shortcuts.js');
$lead=(string)file_get_contents($root.'/manager/assets/workspace-v2-lead-card.js');
$tasks=(string)file_get_contents($root.'/manager/assets/workspace-v2-tasks.js');
$pipeline=(string)file_get_contents($root.'/manager/assets/workspace-v2-pipeline.js');
$taskDraft=(string)file_get_contents($root.'/manager/assets/workspace-v2-task-draft.js');
$passed=0;$failed=0;
function stableCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}
stableCheck('manager index is redirect-free and owns the workspace implementation',strpos($index,"header('Location:")===false&&strpos($index,'workspace-v2.php')===false&&strpos($index,'id="workspaceRoot"')!==false&&strpos($index,'class="zone inboxZone"')!==false&&strpos($index,'id="conversationZone"')!==false&&strpos($index,'id="leadZone"')!==false);
stableCheck('explicit index canonicalizes without network navigation',strpos($core,'history.replaceState')!==false&&strpos($core,"location.replace(")===false&&strpos($core,'/\\/index\\.php$/')!==false&&strpos($core,'workspace-v2')===false);
stableCheck('opening a lead does not force a full inbox reload',strpos($conversation,"await window.WorkspaceV2Inbox?.load()")===false&&strpos($conversation,'WorkspaceV2Inbox?.markActive')!==false);
stableCheck('inbox refresh preserves scroll for local mutations',strpos($inbox,'scrollTop=box.scrollTop')!==false&&strpos($inbox,'if(preserveScroll)box.scrollTop=scrollTop')!==false&&strpos($inbox,'replaceChildren(frag)')!==false);
stableCheck('same-conversation refresh can preserve transcript position',strpos($conversation,'distanceFromBottom')!==false&&strpos($conversation,'preserveMessageScroll')!==false&&strpos($conversation,'stickToBottom')!==false);
stableCheck('long transcripts expose a dedicated jump-to-latest control without owning message rendering',strpos($index,"workspaceAsset('workspace-v2-jump.js')")!==false&&strpos($index,"workspaceAsset('workspace-v2-jump.css')")!==false&&strpos($jump,"distanceFromLatest()>160")!==false&&strpos($jump,"box.addEventListener('scroll',update")!==false&&strpos($jump,"scrollToLatest({smooth:true})")!==false&&strpos($jump,'MutationObserver')!==false&&strpos($jump,'replaceChildren')===false);
stableCheck('jump-to-latest remains hidden at the latest message and before a conversation is selected',strpos($jump,"hasConversation=Number(S.current||0)>0")!==false&&strpos($jump,"button.classList.toggle('hidden',!(hasConversation&&farFromLatest))")!==false);
stableCheck('workspace keyboard shortcuts are isolated and bound through the canonical workspace bootstrap',strpos($index,"workspaceAsset('workspace-v2-shortcuts.js')")!==false&&strpos($shortcuts,'window.WorkspaceV2Shortcuts=')!==false&&strpos($shortcuts,"document.addEventListener('keydown',onKeydown)")!==false&&strpos($shortcuts,'replaceChildren')===false&&strpos($core,'window.WorkspaceV2Shortcuts?.bind()')!==false);
stableCheck('shortcuts cover search attention composer and lead card without intercepting typing',strpos($shortcuts,"e.key==='/')")!==false&&strpos($shortcuts,"key==='j'&&openNextAttention()")!==false&&strpos($shortcuts,"key==='r'&&focusComposer()")!==false&&strpos($shortcuts,"key==='l'&&openLeadCard()")!==false&&strpos($shortcuts,'if(editable)return')!==false&&strpos($shortcuts,"target.tagName")!==false);
stableCheck('attention shortcut only targets visible inbox urgency or unread evidence',strpos($shortcuts,"#inboxList .leadItem")!==false&&strpos($shortcuts,"classList.contains('waitUrgent')")!==false&&strpos($shortcuts,"classList.contains('waitWarn')")!==false&&strpos($shortcuts,"querySelector('.unreadBadge')")!==false&&strpos($shortcuts,'next?.click()')!==false);
stableCheck('shortcuts advertise key bindings through aria-keyshortcuts',strpos($shortcuts,"setAttribute('aria-keyshortcuts','/')")!==false&&strpos($shortcuts,"setAttribute('aria-keyshortcuts','Alt+J')")!==false&&strpos($shortcuts,"setAttribute('aria-keyshortcuts','Alt+R')")!==false&&strpos($shortcuts,"setAttribute('aria-keyshortcuts','Alt+L')")!==false);
stableCheck('lead task mutations refresh only target-pinned lead data',strpos($lead,'WorkspaceV2Tasks.render(root,{tasks,canEdit,conversationId})')!==false&&strpos($tasks,'refreshLeadData({refreshInbox:true,conversationId:target})')!==false&&strpos($tasks,'WorkspaceV2Conversation.open(S.current)')===false&&strpos($conversation,'conversationId=S.current')!==false&&strpos($conversation,'const stillCurrent=Number(S.current)===target')!==false);
stableCheck('pipeline mutations use target-pinned refresh and do not reopen transcript',substr_count($pipeline,'await refreshAfterSave(target)')>=2&&strpos($pipeline,'refreshLeadData({refreshInbox:true,conversationId:target})')!==false&&strpos($pipeline,'WorkspaceV2Conversation.open(S.current)')===false);
stableCheck('filters intentionally reset inbox scroll through dedicated filter owner',strpos($filters,'async function apply()')!==false&&strpos($filters,'load({preserveScroll:false})')!==false&&substr_count($filters,'await apply()')>=5&&strpos($pipeline,'load({preserveScroll:false})')===false);
stableCheck('task draft persistence is isolated in its own workspace module',strpos($index,"workspaceAsset('workspace-v2-task-draft.js')")!==false&&strpos($taskDraft,'window.WorkspaceV2TaskDraft=')!==false&&strpos($taskDraft,'workspaceV2.taskDraft.')!==false&&strpos($taskDraft,'sessionStorage')!==false);
stableCheck('task drafts are scoped per lead and fail open around browser storage',strpos($taskDraft,'currentId()')!==false&&strpos($taskDraft,'keyFor(id=currentId())')!==false&&substr_count($taskDraft,'catch(e)')>=3);
stableCheck('successful task creation does not restore a stale draft during lead refresh',strpos($taskDraft,'pending.add(key)')!==false&&strpos($taskDraft,'!pending.has(key)')!==false&&strpos($taskDraft,'if(result!==false)clear(key)')!==false&&strpos($taskDraft,'pending.delete(key)')!==false);
echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";exit($failed?1:0);