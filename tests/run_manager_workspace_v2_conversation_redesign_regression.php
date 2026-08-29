<?php

declare(strict_types=1);
$root=dirname(__DIR__);
$page=(string)file_get_contents($root.'/manager/index.php');
$js=(string)file_get_contents($root.'/manager/assets/workspace-v2-conversation.js');
$css=(string)file_get_contents($root.'/manager/assets/workspace-v2-conversation.css');
$mobileCss=(string)file_get_contents($root.'/manager/assets/workspace-v2-mobile.css');
$passed=0;$failed=0;
function checkConversationUi(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}
function conversationAssetLoaded(string $html,string $file):bool{return strpos($html,'assets/'.$file)!==false||strpos($html,"workspaceAsset('{$file}')")!==false;}
checkConversationUi('conversation stylesheet is isolated',conversationAssetLoaded($page,'workspace-v2-conversation.css')&&strlen($css)>1000);
checkConversationUi('header exposes customer identity and technical state separately',strpos($page,'id="conversationAvatar"')!==false&&strpos($page,'id="conversationState"')!==false&&strpos($js,'renderHeader(c)')!==false);
checkConversationUi('messages distinguish tourist AI and manager',strpos($js,"who==='customer'?'Турист':who==='manager'?'Менеджер':'AI'")!==false&&strpos($css,'.msg.customer')!==false&&strpos($css,'.msg.ai')!==false&&strpos($css,'.msg.manager')!==false);
checkConversationUi('original transcript remains rendered from detail messages',strpos($js,'renderMessages(d.messages||[]')!==false&&strpos($js,'body.textContent=m.text||')!==false);
checkConversationUi('conversation detail loads against target before committing current id',strpos($js,"api('detail',{conversation_id:target})")!==false&&strpos($js,"pipe('detail',{conversation_id:target})")!==false&&strpos($js,'S.current=target;S.detail={...d,lead:p}')!==false);
checkConversationUi('failed detail keeps previous conversation active and explicit',strpos($js,'if(!d?.ok||!p?.ok)')!==false&&strpos($js,'Текущий диалог не изменён.')!==false&&strpos($js,'conversationLoadStatus')!==false&&strpos($css,'.conversationLoadStatus.error')!==false);
checkConversationUi('stale rapid-click detail response cannot overwrite newer selection',strpos($js,'openSeq=0')!==false&&strpos($js,'seq=++openSeq')!==false&&substr_count($js,'if(seq!==openSeq)return false')>=2);
checkConversationUi('successful detail read marks unread only after atomic commit',($commitPos=strpos($js,'S.current=target;S.detail={...d,lead:p}'))!==false&&($readPos=strpos($js,'WorkspaceV2Inbox?.markRead(S.current)'))!==false&&$commitPos<$readPos);
checkConversationUi('media and active inbox switch only after successful detail commit',($commitPos2=strpos($js,'S.current=target;S.detail={...d,lead:p}'))!==false&&($mediaPos=strpos($js,'WorkspaceV2Media?.configure(S.csrf,S.current)'))!==false&&($activePos=strpos($js,'WorkspaceV2Inbox?.markActive(S.current)'))!==false&&$commitPos2<$mediaPos&&$commitPos2<$activePos);
checkConversationUi('composer has quick replies without auto send',strpos($page,'class="quickReplies"')!==false&&strpos($js,"b.onclick=()=>{reply.value=b.dataset.reply||''")!==false);
$inputAutosizes=strpos($js,"reply.addEventListener('input',autoGrow)")!==false||strpos($js,"reply.addEventListener('input',()=>{saveDraft();autoGrow()})")!==false;
checkConversationUi('composer autosizes and supports explicit keyboard submit',$inputAutosizes&&strpos($js,"e.metaKey||e.ctrlKey")!==false&&strpos($js,'form.requestSubmit()')!==false);
checkConversationUi('send path preserves source-pinned text and media backends',strpos($js,'const target=Number(S.current||0),generation=openSeq,text=')!==false&&strpos($js,"api('send',{conversation_id:target,text})")!==false&&strpos($js,'WorkspaceV2Media.send(text)')!==false);
checkConversationUi('double submit is guarded in UI',strpos($js,'if(busy)return')!==false&&strpos($js,'setBusy(true)')!==false&&strpos($js,'setBusy(false)')!==false);
checkConversationUi('read-only conversation shows explicit reply lock reason',strpos($page,'id="composerLocked"')!==false&&strpos($js,'Переписку можно читать без назначения')!==false);
$mobileUsable=strpos($css,'@media(max-width:900px)')!==false
    && strpos($css,'height:100dvh;max-height:100dvh')!==false
    && strpos($css,'.messages{padding:12px 10px 14px}')!==false
    && strpos($css,'.composer{padding:7px 8px')!==false;
checkConversationUi('mobile conversation remains full-screen and usable',$mobileUsable);
$mobileTyping=strpos($mobileCss,'@media(max-width:520px)')!==false
    && strpos($mobileCss,'.quickReplies{order:2')!==false
    && strpos($mobileCss,'.composerSurface{order:1')!==false
    && strpos($mobileCss,'.composer:focus-within .quickReplies{display:none}')!==false
    && strpos($mobileCss,'.composer textarea{min-height:46px;max-height:128px')!==false
    && strpos($mobileCss,'.composer .sendBtn{width:42px;min-width:42px')!==false;
checkConversationUi('mobile typing keeps reply surface primary and hides shortcuts while focused',$mobileTyping);
$scrollContract=strpos($css,'.conversationZone{background:#f4f7f9;min-height:0;overflow:hidden}')!==false
    && strpos($css,'.messages{min-height:0;flex:1 1 auto')!==false
    && strpos($css,'overflow-y:auto;overflow-x:hidden')!==false
    && strpos($css,'.composer{position:relative;z-index:5;flex:0 0 auto;display:grid')!==false;
checkConversationUi('long transcript cannot push composer out of viewport',$scrollContract);
$composerContract=strpos($css,'display:grid;grid-template-columns:minmax(0,1fr);align-content:start;width:100%')!==false
    && strpos($css,'.composerSurface{display:grid;grid-template-columns:minmax(0,1fr) auto')!==false;
checkConversationUi('composer overrides legacy horizontal flex layout',$composerContract);
$mediaContract=strpos($css,'.attachments img,.attachments video{display:block;width:auto;height:auto')!==false
    && strpos($css,'max-height:420px;object-fit:contain')!==false
    && strpos($js,'function looksLikeImage')!==false
    && strpos($js,'function mediaFallback')!==false;
checkConversationUi('conversation media is bounded and historical images degrade safely',$mediaContract);
echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";exit($failed?1:0);
