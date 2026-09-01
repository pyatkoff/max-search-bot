<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$adapter=(string)file_get_contents($root.'/integrations/TelegramMessengerAdapter.php');
$outbound=(string)file_get_contents($root.'/services/ManagerOutboundService.php');
$upload=(string)file_get_contents($root.'/manager/media-upload.php');
$ui=(string)file_get_contents($root.'/manager/assets/workspace-v2-media.js');
$passed=0;$failed=0;
function tgMediaCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

tgMediaCheck('Telegram adapter exposes manager media send boundary',strpos($adapter,'public function sendMedia(')!==false);
tgMediaCheck('Telegram media maps all normalized attachment types to Bot API methods',strpos($adapter,"'image'=>'sendPhoto'")!==false&&strpos($adapter,"'video'=>'sendVideo'")!==false&&strpos($adapter,"'audio'=>'sendAudio'")!==false&&strpos($adapter,"'file'=>'sendDocument'")!==false);
tgMediaCheck('Telegram media uses correct multipart fields',strpos($adapter,"'image'=>'photo'")!==false&&strpos($adapter,"'video'=>'video'")!==false&&strpos($adapter,"'audio'=>'audio'")!==false&&strpos($adapter,"'file'=>'document'")!==false&&strpos($adapter,'new CURLFile(')!==false);
tgMediaCheck('Telegram multipart transport sends raw POST fields instead of urlencoding file data',strpos($adapter,'CURLOPT_POSTFIELDS, $multipart ? $payload : http_build_query($payload)')!==false);
tgMediaCheck('Telegram media preserves optional caption HTML mode',strpos($adapter,"\$payload['caption']=\$text")!==false&&strpos($adapter,"\$payload['parse_mode']='HTML'")!==false);
tgMediaCheck('Telegram media records attachment metadata for Workspace history',strpos($adapter,"ConversationRecorder::outbound('telegram'")!==false&&strpos($adapter,"['attachments'=>[\$attachment]]")!==false&&strpos($adapter,"\$attachment['url']=trim(\$previewUrl)")!==false);
tgMediaCheck('manager outbound media supports MAX and Telegram only',strpos($outbound,"\$channel!=='max'&&\$channel!=='telegram'")!==false);
tgMediaCheck('manager outbound media keeps ownership guard',strpos($outbound,"(int)\$c['manager_id']!==\$managerId")!==false&&strpos($outbound,"(string)\$c['status']!=='manager'")!==false);
tgMediaCheck('manager outbound media selects channel adapter without changing MAX suspended guard',strpos($outbound,"\$channel==='max'?new MaxMessengerAdapter")!==false&&strpos($outbound,"new TelegramMessengerAdapter(null,'manager')")!==false&&strpos($outbound,"if(\$channel==='max')")!==false&&strpos($outbound,'unresolvedSuspendedFailure')!==false);
tgMediaCheck('manager media lifecycle and manager-reply conversion remain shared',strpos($outbound,"['channel'=>\$channel")!==false&&strpos($outbound,'MetrikaConversionGoalService::managerReply($conversationId)')!==false);
tgMediaCheck('existing Workspace composer sends the same upload request for Telegram and MAX',strpos($ui,"fetch('media-upload.php'")!==false&&strpos($ui,"data.append('conversation_id'")!==false&&strpos($upload,'ManagerOutboundService::sendMedia')!==false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
