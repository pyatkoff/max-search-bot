<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$base=dirname(__DIR__);require_once $base.'/config.php';require_once $base.'/services/ConversationDb.php';
$login=trim((string)($argv[1]??''));$password=(string)($argv[2]??'');$name=trim((string)($argv[3]??''));
if($login===''||strlen($password)<8){fwrite(STDERR,"Usage: php tools/manager_user.php <login> <password>=8+ [display name]\n");exit(2);}
try{$pdo=ConversationDb::connection();$hash=password_hash($password,PASSWORD_DEFAULT);$q=$pdo->prepare('SELECT id FROM managers WHERE login=? LIMIT 1');$q->execute([$login]);$id=(int)$q->fetchColumn();if($id){$pdo->prepare('UPDATE managers SET password_hash=?,display_name=COALESCE(NULLIF(?,\'\'),display_name),is_active=1 WHERE id=?')->execute([$hash,$name,$id]);echo "MANAGER UPDATED\nID: {$id}\nLOGIN: {$login}\n";}else{$q=$pdo->prepare('INSERT INTO managers (login,password_hash,display_name,is_active) VALUES (?,?,?,1)');$q->execute([$login,$hash,$name!==''?$name:null]);echo "MANAGER CREATED\nID: ".$pdo->lastInsertId()."\nLOGIN: {$login}\n";}}catch(Throwable$e){fwrite(STDERR,"ERROR: ".$e->getMessage()."\n");exit(1);}
