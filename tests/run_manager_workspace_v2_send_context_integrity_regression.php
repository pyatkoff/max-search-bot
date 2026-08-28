<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$conversation=(string)file_get_contents($root.'/manager/assets/workspace-v2-conversation.js');
$media=(string)file_get_contents($root.'/manager/assets/workspace-v2-media.js');
$passed=0;$failed=0;
function sciCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

sciCheck('text send captures source conversation before await',strpos($conversation,'const target=Number(S.current||0),generation=openSeq,text=')!==false&&strpos($conversation,"api('send',{conversation_id:target,text})")!==false);
sciCheck('successful send clears draft for source conversation only',strpos($conversation,'drafts.delete(target)')!==false&&strpos($conversation,'drafts.delete(Number(S.current))')===false);
sciCheck('composer cleanup is gated to still-current source conversation',strpos($conversation,'const stillCurrent=openSeq===generation&&Number(S.current)===target')!==false&&strpos($conversation,"if(stillCurrent){\n      $('replyText').value='';")!==false);
sciCheck('late send completion refreshes source only when still current',strpos($conversation,"await open(target,{stickToBottom:true,mobileHistory:'none'})")!==false&&strpos($conversation,"await open(S.current,{stickToBottom:true")===false);
sciCheck('success status timer cannot clear another conversation status',strpos($conversation,'const statusGeneration=openSeq')!==false&&strpos($conversation,'Number(S.current)===target&&openSeq===statusGeneration')!==false);
sciCheck('send failure does not paint delivery state onto a newer conversation',strpos($conversation,'if(stillCurrent&&failure&&S.detail?.conversation)')!==false&&strpos($conversation,'if(stillCurrent&&!S.authExpired)setReplyStatus')!==false);
sciCheck('media upload captures source conversation id before request',strpos($media,'const target=state.conversationId,data=new FormData()')!==false&&strpos($media,"data.append('conversation_id',String(target))")!==false);
sciCheck('media auth expiry delegates to canonical recovery owner',strpos($media,'showAuthRecovery')!==false&&strpos($media,"error:'unauthorized'")!==false&&strpos($media,'location.href')===false&&strpos($media,'location.reload')===false);
sciCheck('media request keeps same-origin session credentials',strpos($media,"credentials:'same-origin'")!==false);

$total=$passed+$failed;
echo "\n--------------------------\nTOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
