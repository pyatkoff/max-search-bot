<?php

declare(strict_types=1);
$root=dirname(__DIR__);
$index=(string)file_get_contents($root.'/manager/index.php');
$core=(string)file_get_contents($root.'/manager/assets/workspace-v2.js');
$inbox=(string)file_get_contents($root.'/manager/assets/workspace-v2-inbox.js');
$conversation=(string)file_get_contents($root.'/manager/assets/workspace-v2-conversation.js');
$lead=(string)file_get_contents($root.'/manager/assets/workspace-v2-lead-card.js');
$pipeline=(string)file_get_contents($root.'/manager/assets/workspace-v2-pipeline.js');
$passed=0;$failed=0;
function stableCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}
stableCheck('manager index is redirect-free and owns the workspace implementation',strpos($index,"header('Location:")===false&&strpos($index,'workspace-v2.php')===false&&strpos($index,'id="workspaceRoot"')!==false&&strpos($index,'class="zone inboxZone"')!==false&&strpos($index,'id="conversationZone"')!==false&&strpos($index,'id="leadZone"')!==false);
stableCheck('explicit index canonicalizes without network navigation',strpos($core,'history.replaceState')!==false&&strpos($core,"location.replace(")===false&&strpos($core,'/\\/index\\.php$/')!==false&&strpos($core,'workspace-v2')===false);
stableCheck('opening a lead does not force a full inbox reload',strpos($conversation,"await window.WorkspaceV2Inbox?.load()")===false&&strpos($conversation,'WorkspaceV2Inbox?.markActive')!==false);
stableCheck('inbox refresh preserves scroll for local mutations',strpos($inbox,'scrollTop=box.scrollTop')!==false&&strpos($inbox,'if(preserveScroll)box.scrollTop=scrollTop')!==false&&strpos($inbox,'replaceChildren(frag)')!==false);
stableCheck('same-conversation refresh can preserve transcript position',strpos($conversation,'distanceFromBottom')!==false&&strpos($conversation,'preserveMessageScroll')!==false&&strpos($conversation,'stickToBottom')!==false);
stableCheck('lead card mutations refresh only target-pinned lead data',strpos($lead,'refreshLeadData({refreshInbox:true,conversationId:target})')!==false&&strpos($lead,'WorkspaceV2Conversation.open(S.current)')===false&&strpos($conversation,'conversationId=S.current')!==false&&strpos($conversation,'const stillCurrent=Number(S.current)===target')!==false);
stableCheck('pipeline mutations use target-pinned refresh and do not reopen transcript',substr_count($pipeline,'await refreshAfterSave(target)')>=2&&strpos($pipeline,'refreshLeadData({refreshInbox:true,conversationId:target})')!==false&&strpos($pipeline,'WorkspaceV2Conversation.open(S.current)')===false);
stableCheck('filters intentionally reset inbox scroll through one owner',strpos($pipeline,'async function applyFilters()')!==false&&strpos($pipeline,'load({preserveScroll:false})')!==false&&substr_count($pipeline,'await applyFilters()')>=5);
echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";exit($failed?1:0);
