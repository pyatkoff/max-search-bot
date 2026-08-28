<?php

declare(strict_types=1);
$root=dirname(__DIR__);
$page=(string)file_get_contents($root.'/manager/workspace-v2.php');
$js=(string)file_get_contents($root.'/manager/assets/workspace-v2-conversation.js');
$css=(string)file_get_contents($root.'/manager/assets/workspace-v2-conversation.css');
$passed=0;$failed=0;
function checkConversationUi(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}
checkConversationUi('conversation stylesheet is isolated',strpos($page,'assets/workspace-v2-conversation.css')!==false&&strlen($css)>1000);
checkConversationUi('header exposes customer identity and technical state separately',strpos($page,'id="conversationAvatar"')!==false&&strpos($page,'id="conversationState"')!==false&&strpos($js,'renderHeader(c)')!==false);
checkConversationUi('messages distinguish tourist AI and manager',strpos($js,"who==='customer'?'Турист':who==='manager'?'Менеджер':'AI'")!==false&&strpos($css,'.msg.customer')!==false&&strpos($css,'.msg.ai')!==false&&strpos($css,'.msg.manager')!==false);
checkConversationUi('original transcript remains rendered from detail messages',strpos($js,'renderMessages(d.messages||[]')!==false&&strpos($js,'body.textContent=m.text||')!==false);
checkConversationUi('composer has quick replies without auto send',strpos($page,'class="quickReplies"')!==false&&strpos($js,"b.onclick=()=>{reply.value=b.dataset.reply||''")!==false);
$inputAutosizes=strpos($js,"reply.addEventListener('input',autoGrow)")!==false||strpos($js,"reply.addEventListener('input',()=>{saveDraft();autoGrow()})")!==false;
checkConversationUi('composer autosizes and supports explicit keyboard submit',$inputAutosizes&&strpos($js,"e.metaKey||e.ctrlKey")!==false&&strpos($js,'form.requestSubmit()')!==false);
checkConversationUi('send path preserves text and media backends',strpos($js,"api('send',{conversation_id:S.current,text})")!==false&&strpos($js,'WorkspaceV2Media.send(text)')!==false);
checkConversationUi('double submit is guarded in UI',strpos($js,'if(busy)return')!==false&&strpos($js,'setBusy(true)')!==false&&strpos($js,'setBusy(false)')!==false);
checkConversationUi('read-only conversation shows explicit reply lock reason',strpos($page,'id="composerLocked"')!==false&&strpos($js,'Переписку можно читать без назначения')!==false);
checkConversationUi('mobile conversation remains full-screen and usable',strpos($css,'@media(max-width:900px)')!==false&&strpos($css,'.messages{padding:16px 12px 24px}')!==false&&strpos($css,'.composer{padding:8px 9px')!==false);
echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";exit($failed?1:0);
