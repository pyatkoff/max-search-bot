<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$page=(string)file_get_contents($root.'/manager/index.php');
$core=(string)file_get_contents($root.'/manager/assets/workspace-v2.js');
$mobile=(string)file_get_contents($root.'/manager/assets/workspace-v2-mobile.js');
$mobileCss=(string)file_get_contents($root.'/manager/assets/workspace-v2-mobile.css');
$conversation=(string)file_get_contents($root.'/manager/assets/workspace-v2-conversation.js');
$lead=(string)file_get_contents($root.'/manager/assets/workspace-v2-lead-card.js');
$passed=0;$failed=0;
function mobileCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}
function mobileAssetPos(string $html,string $file){$static=strpos($html,'assets/'.$file);if($static!==false)return$static;return strpos($html,"workspaceAsset('{$file}')");}

$mobileCssPos=mobileAssetPos($page,'workspace-v2-mobile.css');$mobileJsPos=mobileAssetPos($page,'workspace-v2-mobile.js');
mobileCheck('workspace loads dedicated mobile navigation assets',$mobileCssPos!==false&&$mobileJsPos!==false);
$conversationPos=mobileAssetPos($page,'workspace-v2-conversation.js');$mobilePos=$mobileJsPos;$bootstrapPos=mobileAssetPos($page,'workspace-v2-bootstrap.js');
mobileCheck('mobile owner loads after feature modules and before bootstrap',$conversationPos!==false&&$mobilePos!==false&&$bootstrapPos!==false&&$conversationPos<$mobilePos&&$mobilePos<$bootstrapPos);
mobileCheck('workspace boot binds one mobile navigation owner',strpos($core,'WorkspaceV2Mobile?.bind()')!==false);
mobileCheck('mobile state has explicit inbox conversation lead screens',strpos($mobile,"screen='inbox'")!==false&&strpos($mobile,"next==='lead'?'lead':next==='conversation'?'conversation':'inbox'")!==false);
mobileCheck('mobile history starts inside app and records screen transitions',strpos($mobile,"history.replaceState(currentState('inbox')")!==false&&strpos($mobile,'history.pushState(state')!==false&&strpos($mobile,"window.addEventListener('popstate'")!==false);
mobileCheck('browser back restores lead conversation inbox without leaving on first step',strpos($mobile,"if(screen==='lead'||screen==='conversation'){history.back();return}")!==false&&strpos($mobile,"target==='lead'||target==='conversation'")!==false);
mobileCheck('conversation delegates successful open to mobile owner',strpos($conversation,'WorkspaceV2Mobile.conversationOpened')!==false&&strpos($conversation,"mobileHistory||'push'")!==false);
mobileCheck('local conversation mutations do not create duplicate mobile history',substr_count($conversation,"mobileHistory:'none'")>=2);
mobileCheck('conversation no longer owns mobile back or lead transition',strpos($conversation,"$('mobileBack').onclick")===false&&strpos($conversation,"$('mobileLeadBtn').onclick")===false&&strpos($conversation,"$('leadZone').onclick")===false);
mobileCheck('lead card no longer owns mobile close transition',strpos($lead,"$('mobileLeadClose')")===false&&strpos($lead,"classList.remove('open')")===false);
mobileCheck('reply drafts are scoped by conversation',strpos($conversation,'const drafts=new Map()')!==false&&strpos($conversation,'saveDraft(previous)')!==false&&strpos($conversation,'restoreDraft(S.current)')!==false);
mobileCheck('reply draft cleanup is pinned to successful source conversation',strpos($conversation,'drafts.delete(target)')!==false&&strpos($conversation,'const stillCurrent=openSeq===generation&&Number(S.current)===target')!==false&&strpos($conversation,"if(stillCurrent){\n      $('replyText').value='';")!==false&&strpos($conversation,'drafts.delete(Number(S.current))')===false);
mobileCheck('typing and quick replies update the current draft',strpos($conversation,"reply.addEventListener('input',()=>{saveDraft();autoGrow()})")!==false&&strpos($conversation,"reply.value=b.dataset.reply||'';saveDraft()")!==false);
mobileCheck('mobile CSS uses one explicit screen state model',strpos($mobileCss,'data-mobile-screen="inbox"')!==false&&strpos($mobileCss,'data-mobile-screen="conversation"')!==false&&strpos($mobileCss,'data-mobile-screen="lead"')!==false);
mobileCheck('mobile viewport and safe area are stable',strpos($mobileCss,'100dvh')!==false&&strpos($mobileCss,'env(safe-area-inset-bottom)')!==false&&strpos($mobileCss,'overscroll-behavior')!==false);
mobileCheck('reduced motion is respected',strpos($mobileCss,'prefers-reduced-motion:reduce')!==false);
$all=$mobile."\n".$mobileCss;
mobileCheck('mobile navigation never mutates shift routing analytics or lead delivery',stripos($all,'set_working')===false&&stripos($all,'routing_bonus')===false&&stripos($all,'metrika')===false&&strpos($all,'LeadDestination')===false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
