<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$page=(string)file_get_contents($root.'/manager/workspace-v2.php');
$js=(string)file_get_contents($root.'/manager/assets/workspace-v2-conversation.js');
$css=(string)file_get_contents($root.'/manager/assets/workspace-v2-conversation.css');
$passed=0;$failed=0;
function ccheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

ccheck('workspace loads dedicated conversation stylesheet',strpos($page,'assets/workspace-v2-conversation.css')!==false);
ccheck('conversation header has stable identity shell',strpos($page,'conversationIdentity')!==false&&strpos($page,'conversationAvatar')!==false&&strpos($page,'conversationMeta')!==false);
ccheck('composer exposes explicit send button and keyboard hint',strpos($page,'id="sendReply"')!==false&&strpos($page,'Shift+Enter')!==false);
ccheck('enter sends while shift enter remains newline',strpos($js,"e.key==='Enter'&&!e.shiftKey")!==false&&strpos($js,'composer.requestSubmit()')!==false);
ccheck('composer textarea autosizes without page rerender',strpos($js,'function autosize()')!==false&&strpos($js,"input.style.height='auto'")!==false);
ccheck('client prevents parallel sends',strpos($js,'if(sending||!S.current)return')!==false&&strpos($js,'setSending(true)')!==false&&strpos($js,'setSending(false)')!==false);
ccheck('conversation keeps stability scroll contract',strpos($js,'preserveMessageScroll')!==false&&strpos($js,'preserveScroll:true')!==false);
ccheck('conversation stylesheet has mobile-first composer treatment',strpos($css,'@media(max-width:900px)')!==false&&strpos($css,'env(safe-area-inset-bottom)')!==false&&strpos($css,'.sendBtn:after')!==false);
ccheck('redesign does not mutate routing or shift state',stripos($js.' '.$css,'set_working')===false&&stripos($js.' '.$css,'routing')===false);
ccheck('redesign does not touch metrika or lead delivery',stripos($js.' '.$css,'metrika')===false&&strpos($js,'LeadDestination')===false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
