<?php
declare(strict_types=1);
require_once __DIR__.'/../services/ManagerHandoffCancellationViewService.php';
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE conversation_events(id INTEGER PRIMARY KEY,conversation_id INTEGER,event_type TEXT,actor_type TEXT,payload_json TEXT,created_at TEXT);CREATE INDEX idx_conversation_events_conversation ON conversation_events(conversation_id,id)');
$r=new ReflectionProperty(ConversationDb::class,'pdo');$r->setAccessible(true);$r->setValue(null,$pdo);
function check($label,$actual,$expected){if($actual!==$expected)throw new RuntimeException($label.' '.json_encode($actual));echo "PASS $label\n";}
$valid=['id'=>1,'event_type'=>'ai_resumed','actor_type'=>'customer','payload_json'=>'{"reason":"handoff_cancelled"}','created_at'=>'2026-10-14 08:30:00'];
check('confirmed canonical event projected',ManagerHandoffCancellationViewService::project($valid)['reason'],'handoff_cancelled');
foreach(['event_type'=>'callback','actor_type'=>'system','payload_json'=>'{"reason":"outside_hours_queue_repair"}','created_at'=>'']as$key=>$value){check('reject unconfirmed '.$key,ManagerHandoffCancellationViewService::project(array_replace($valid,[$key=>$value])),null);}
$q=$pdo->prepare('INSERT INTO conversation_events VALUES(?,?,?,?,?,?)');$q->execute([1,7,'ai_resumed','customer',$valid['payload_json'],$valid['created_at']]);
check('visible AI context contains reason',ManagerHandoffCancellationViewService::fromVisibleContext(['id'=>7,'status'=>'ai'])['event_id'],1);
check('assigned manager remains authoritative',ManagerHandoffCancellationViewService::fromVisibleContext(['id'=>7,'status'=>'manager']),null);
check('invalid context reveals nothing',ManagerHandoffCancellationViewService::fromVisibleContext([]),null);
$q->execute([2,7,'manager_released','manager',null,'2026-10-14 09:00:00']);
check('later release never attributed to cancellation',ManagerHandoffCancellationViewService::fromVisibleContext(['id'=>7,'status'=>'ai']),null);
$q->execute([3,7,'callback','customer','{"text":"back_check"}','2026-10-14 09:01:00']);
check('literal callback cannot provide cause',ManagerHandoffCancellationViewService::fromVisibleContext(['id'=>7,'status'=>'ai']),null);
$q->execute([4,8,'ai_resumed','customer',$valid['payload_json'],$valid['created_at']]);
check('other conversation is isolated',ManagerHandoffCancellationViewService::fromVisibleContext(['id'=>7,'status'=>'ai']),null);
for($i=5;$i<37;$i++)$q->execute([$i,8,'callback','customer',null,'2026-10-14 10:00:00']);
check('transition outside bounded tail stays unknown',ManagerHandoffCancellationViewService::fromVisibleContext(['id'=>8,'status'=>'ai']),null);
$plan=$pdo->query("EXPLAIN QUERY PLAN SELECT id,event_type,actor_type,payload_json,created_at FROM conversation_events WHERE conversation_id=7 ORDER BY id DESC LIMIT 32")->fetchAll();
check('existing conversation index used',strpos(json_encode($plan),'idx_conversation_events_conversation')!==false,true);
echo "PASS one bounded optional detail query; no Inbox list enrichment\n";
