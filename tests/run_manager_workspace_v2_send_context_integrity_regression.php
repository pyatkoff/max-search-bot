<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$conversation=(string)file_get_contents($root.'/manager/assets/workspace-v2-conversation.js');
$media=(string)file_get_contents($root.'/manager/assets/workspace-v2-media.js');
$passed=0;$failed=0;
function sciCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

sciCheck('text send captures source conversation before await',strpos($conversation,'const target=Number(S.current||0),generation=openSeq,text=')!==false&&strpos($conversation,"api('send',{conversation_id:target,text})")!==false);
sciCheck('successful send clears unchanged draft for source conversation and authenticated owner only',strpos($conversation,'drafts.delete(target)')!==false&&strpos($conversation,'owner===replySessionOwner&&owner===Number(S.manager?.id)&&drafts.get(target)?.text===draftText')!==false&&strpos($conversation,'drafts.delete(Number(S.current))')===false);
// These contracts describe the current controller owner, not the old error copy
// or the removed 1.4-second success timer. Executed race/failure behavior is also
// covered by the required reauth/composer Node regression using the real asset.
$sendStart=strpos($conversation,'async function sendReply(){');
$sendEnd=strpos($conversation,'function bind(){',$sendStart===false?0:$sendStart);
$send=$sendStart!==false&&$sendEnd!==false?substr($conversation,$sendStart,$sendEnd-$sendStart):'';
sciCheck('send feedback is pinned to authenticated session and source conversation',strpos($send,'const sameSession=()=>!S.authExpired&&S.authGeneration===authGeneration&&Number(S.manager?.id)===manager')!==false&&strpos($send,'const sameTarget=()=>sameSession()&&Number(S.current)===target')!==false&&strpos($send,'const currentAttempt=()=>sameTarget()&&openSeq===generation')!==false);
sciCheck('composer cleanup is gated to current send attempt',strpos($send,'const stillCurrent=currentAttempt();')!==false&&strpos($send,"if(stillCurrent){\n      if($('replyText').value===draftText)$('replyText').value='';")!==false);
sciCheck('post-send refresh preserves source, attachments and its own generation',strpos($send,"const refreshing=open(target,{stickToBottom:true,mobileHistory:'none',preserveAttachment:true})")!==false&&strpos($send,'refreshGeneration=openSeq;refreshed=await refreshing;')!==false&&strpos($send,'if(sameTarget()&&openSeq===refreshGeneration)setReplyStatus')!==false&&strpos($send,'open(S.current')===false);
sciCheck('success feedback has no delayed timer that can clear another attempt',strpos($send,'setTimeout(')===false&&strpos($send,"setReplyStatus('Отправлено','success')")!==false);
sciCheck('send failure cannot paint a newer conversation or session',strpos($send,'if(currentAttempt()&&failure&&S.detail?.conversation)')!==false&&strpos($send,"if(currentAttempt())setReplyStatus(sendFailureNotice(failure,j?.error_message),'error')")!==false);
sciCheck('unknown network result is scoped and never labelled definite non-delivery',strpos($send,"catch(e){\n      if(currentAttempt())setReplyStatus(sendFailureNotice(null),'error')")!==false&&strpos($send,"setReplyStatus('Не удалось отправить сообщение'")===false);
sciCheck('confirmed send and Inbox refresh failures have separate boundaries',strpos($send,"if(j?.ok!==true)")!==false&&strpos($send,"Отправлено. Переписку пока не удалось обновить.")!==false&&strpos($send,"if(sameSession())try{await window.WorkspaceV2Inbox?.load({preserveScroll:true})}catch(e){}")!==false);
sciCheck('send path has no automatic retry or duplicate transport call',substr_count($send,"api('send',")===1&&substr_count($send,'WorkspaceV2Media.send(text)')===1&&strpos($send,'setInterval(')===false);
sciCheck('media upload captures source conversation id before request',strpos($media,'const target=state.conversationId,data=new FormData()')!==false&&strpos($media,"data.append('conversation_id',String(target))")!==false);
sciCheck('media auth expiry delegates to canonical recovery owner',strpos($media,'showAuthRecovery')!==false&&strpos($media,"error:'unauthorized'")!==false&&strpos($media,'location.href')===false&&strpos($media,'location.reload')===false);
sciCheck('media request keeps same-origin session credentials',strpos($media,"credentials:'same-origin'")!==false);

$total=$passed+$failed;
echo "\n--------------------------\nTOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
