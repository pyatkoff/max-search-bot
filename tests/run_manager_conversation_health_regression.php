<?php
$src=(string)file_get_contents(__DIR__.'/../services/ManagerConversationProductionHealth.php');$failed=0;
function mh($n,$ok){global $failed;echo($ok?'PASS  ':'FAIL  ').$n.PHP_EOL;if(!$ok)$failed++;}
mh('health is read only',strpos($src,'UPDATE ')===false&&strpos($src,'INSERT ')===false&&strpos($src,'DELETE ')===false);
mh('health checks MAX local media descriptors',strpos($src,"'max_media_local'")!==false);
mh('health checks reply metadata',strpos($src,"'linked_invalid'")!==false);
mh('health checks edit metadata',strpos($src,"'edited_invalid'")!==false);
mh('health locks Moscow projection contract',strpos($src,"timeZone:'Europe/Moscow'")!==false);
exit($failed?1:0);
