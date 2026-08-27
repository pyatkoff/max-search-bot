<?php

declare(strict_types=1);
$root=dirname(__DIR__);
$inbox=(string)file_get_contents($root.'/manager/assets/workspace-v2-inbox.js');
$conversation=(string)file_get_contents($root.'/manager/assets/workspace-v2-conversation.js');
$lead=(string)file_get_contents($root.'/manager/assets/workspace-v2-lead-card.js');
$pipeline=(string)file_get_contents($root.'/manager/assets/workspace-v2-pipeline.js');
$passed=0;$failed=0;
function stableCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}
stableCheck('opening a lead does not force a full inbox reload',strpos($conversation,"await window.WorkspaceV2Inbox?.load()")===false&&strpos($conversation,'WorkspaceV2Inbox?.markActive')!==false);
stableCheck('inbox refresh preserves scroll for local mutations',strpos($inbox,'scrollTop=box.scrollTop')!==false&&strpos($inbox,'if(preserveScroll)box.scrollTop=scrollTop')!==false&&strpos($inbox,'replaceChildren(frag)')!==false);
stableCheck('same-conversation refresh can preserve transcript position',strpos($conversation,'distanceFromBottom')!==false&&strpos($conversation,'preserveMessageScroll')!==false&&strpos($conversation,'stickToBottom')!==false);
stableCheck('lead card mutations refresh only lead data',strpos($lead,'refreshLeadData({refreshInbox:true})')!==false&&strpos($lead,'WorkspaceV2Conversation.open(S.current)')===false);
stableCheck('pipeline mutations do not reopen the transcript',substr_count($pipeline,'refreshLeadData({refreshInbox:true})')>=2&&strpos($pipeline,'WorkspaceV2Conversation.open(S.current)')===false);
stableCheck('filters intentionally reset inbox scroll through one owner',strpos($pipeline,'async function applyFilters()')!==false&&strpos($pipeline,'load({preserveScroll:false})')!==false&&substr_count($pipeline,'await applyFilters()')>=5);
echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";exit($failed?1:0);
