<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
$pdo=null;$second=null;$safe=false;$taskConnection=null;$tables=['manager_tour_selection_cards','manager_tour_selection_deliveries','manager_tour_selection_publications'];
try {
    $dsn=(string)getenv('PREPARED_REPLY_MYSQL_DSN');
    if (!preg_match('/\Amysql:.*(?:\Amysql:|;)dbname=(maxbot_prepared_test(?:_[a-zA-Z0-9_]+)?)(?:;|\z)/D',$dsn,$m)) throw new RuntimeException('unsafe_fixture_database');
    $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
    $pdo=new PDO($dsn,getenv('PREPARED_REPLY_MYSQL_USER')?:null,getenv('PREPARED_REPLY_MYSQL_PASS')?:null,$options);
    if ($pdo->query('SELECT DATABASE()')->fetchColumn()!==$m[1]) throw new RuntimeException('unsafe_fixture_database');$safe=true;
    foreach ($tables as $table) $pdo->exec('DROP TABLE IF EXISTS '.$table);
    $migration=file_get_contents(dirname(__DIR__,2).'/migrations/054_manager_tour_selection_publications.sql');$pdo->exec($migration);
    $id=str_repeat('a',32);$other=str_repeat('b',32);$hash=str_repeat('c',64);$now=gmdate('Y-m-d H:i:s');
    $insert=$pdo->prepare('INSERT INTO manager_tour_selection_publications VALUES(?,?,?,?,?,?,?,?)');$insert->execute([$id,1,1,$hash,7,'{}','{}',$now]);$insert->execute([$other,1,2,str_repeat('d',64),7,'{}','{}',$now]);
    $pdo->prepare('INSERT INTO manager_tour_selection_deliveries VALUES(?,?,?,?,?,?,?,?)')->execute([11,1,$hash,'tour_like_synthetic','pending','{}',$now,$now]);
    $q=$pdo->prepare('INSERT INTO manager_tour_selection_cards VALUES(?,?,?,?,?)');$q->execute([$id,0,11,'tour_like_synthetic',$now]);$q->execute([$other,0,11,'tour_like_synthetic',$now]);
    $before=[];foreach ($tables as $table) $before[$table]=$pdo->query('SELECT * FROM '.$table)->fetchAll();$pdo->exec($migration);
    foreach ($tables as $table) if ($pdo->query('SELECT * FROM '.$table)->fetchAll()!==$before[$table]) throw new RuntimeException('migration_retry_changed_receipts');
    echo "PASS native migration retry retains immutable publications and shared delivery fence\n";
    $rejected=false;try {$pdo->prepare('INSERT INTO manager_tour_selection_deliveries VALUES(?,?,?,?,?,?,?,?)')->execute([12,1,$hash,'another','pending','{}',$now,$now]);} catch (PDOException $e) {$rejected=$e->getCode()==='23000';}
    if (!$rejected) throw new RuntimeException('same_offer_can_bypass_creation_fence');echo "PASS native conversation-offer uniqueness prevents duplicate creation owners\n";
    $second=new PDO($dsn,getenv('PREPARED_REPLY_MYSQL_USER')?:null,getenv('PREPARED_REPLY_MYSQL_PASS')?:null,$options);$second->exec('SET SESSION innodb_lock_wait_timeout=1');
    $pdo->beginTransaction();$pdo->query('SELECT offer_id FROM manager_tour_selection_deliveries WHERE offer_id=11 FOR UPDATE')->fetchColumn();
    $pdo->exec("UPDATE manager_tour_selection_deliveries SET state='reserved' WHERE offer_id=11 AND state='pending'");$blocked=false;
    try {$second->exec("UPDATE manager_tour_selection_deliveries SET state='reserved' WHERE offer_id=11 AND state='pending'");} catch (PDOException $e) {$blocked=(int)($e->errorInfo[1]??0)===1205;}
    $pdo->commit();
    if (!$blocked || $second->exec("UPDATE manager_tour_selection_deliveries SET state='reserved' WHERE offer_id=11 AND state='pending'")!==0) throw new RuntimeException('concurrent_native_creation_not_fenced');
    echo "PASS native concurrent reservation blocks then observes already-reserved offer\n";
    $pdo->exec("UPDATE manager_tour_selection_deliveries SET state='unknown' WHERE offer_id=11");
    if ((int)$pdo->query("SELECT COUNT(*) FROM manager_tour_selection_cards c JOIN manager_tour_selection_deliveries d ON d.offer_id=c.offer_id WHERE d.state='unknown'")->fetchColumn()!==2) throw new RuntimeException('new_selection_lost_unknown_fence');
    echo "PASS native UNKNOWN state follows identical offer across selection versions\n";

    // The pure canonical task CAS shares the caller's native transaction. This
    // fixture covers actual InnoDB contention, not SQLite's lock-syntax adapter.
    require_once dirname(__DIR__,2).'/services/LeadTaskService.php';
    $ownedPromiseTables=['manager_promise_selection_operations','manager_promise_selection_intents','lead_tasks'];
    $tables=array_merge($ownedPromiseTables,$tables);
    foreach($ownedPromiseTables as $table)$pdo->exec('DROP TABLE IF EXISTS '.$table);
    // Do not replace identity tables owned by a preceding fixture. The native
    // runner's lifecycle must leave this dedicated test database clean.
    if((int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='conversations'")->fetchColumn()!==0)throw new RuntimeException('promise_fixture_requires_clean_conversation_table');
    $pdo->exec('CREATE TABLE conversations(id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');$tables[]='conversations';
    $pdo->exec('INSERT INTO conversations VALUES(1),(2)');
    foreach(['014_lead_tasks.sql','016_lead_task_pinning.sql','017_lead_task_reminders.sql'] as $file)
        $pdo->exec(file_get_contents(dirname(__DIR__,2).'/migrations/'.$file));
    $intentMigration=file_get_contents(dirname(__DIR__,2).'/migrations/109_manager_promise_selections.sql');
    $pdo->exec($intentMigration);
    $insertIntent=$pdo->prepare('INSERT INTO manager_promise_selection_intents(intent_id,conversation_id,promise_id,task_id,publication_id,publication_sha256,manifest_sha256,manifest_json,trip_id,profile_version,profile_sha256,profile_context_sha256,source_message_id,source_message_revision,source_author_manager_id,task_revision,scope_json,created_by_manager_id,state,active_promise_id,active_publication_id,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    // These rows exercise DDL constraints only; service proof validation is
    // covered by the real canonical delivery suite with complete manifests.
    $nativeIntent=static function(string $intent,string $promise,string $publication,int $cid=1)use($insertIntent,$now):void{
        $insertIntent->execute([$intent,$cid,$promise,101,$publication,str_repeat('c',64),hash('sha256','[]'),'[]',str_repeat('e',32),1,str_repeat('f',64),str_repeat('a',64),12,str_repeat('b',64),7,1,'{}',7,'armed',$promise,$publication,$now,$now]);
    };
    $intentA=str_repeat('1',32);$intentB=str_repeat('2',32);$intentC=str_repeat('3',32);$promise=str_repeat('4',64);$publication=str_repeat('5',32);
    $nativeIntent($intentA,$promise,$publication);$duplicatePromise=false;$duplicatePublication=false;
    try{$nativeIntent($intentB,$promise,str_repeat('6',32));}catch(PDOException $e){$duplicatePromise=(int)($e->errorInfo[1]??0)===1062;}
    try{$nativeIntent($intentB,str_repeat('7',64),$publication);}catch(PDOException $e){$duplicatePublication=(int)($e->errorInfo[1]??0)===1062;}
    if(!$duplicatePromise||!$duplicatePublication)throw new RuntimeException('native_active_intent_uniqueness_failed');
    echo "PASS full native109 migration enforces both active promise and publication uniqueness\n";
    $release=$pdo->prepare("UPDATE manager_promise_selection_intents SET state='cancelled',active_promise_id=NULL,active_publication_id=NULL,cancelled_at=? WHERE intent_id=?");
    $release->execute([$now,$intentA]);$nativeIntent($intentB,$promise,$publication);$release->execute([$now,$intentB]);$nativeIntent($intentC,$promise,$publication);
    if((int)$pdo->query('SELECT COUNT(*) FROM manager_promise_selection_intents WHERE active_promise_id IS NULL AND active_publication_id IS NULL')->fetchColumn()!==2
        ||(int)$pdo->query("SELECT COUNT(*) FROM manager_promise_selection_intents WHERE state='armed'")->fetchColumn()!==1)throw new RuntimeException('native_nullable_terminal_keys_failed');
    echo "PASS native terminal intents release nullable unique keys while retaining multiple historical records\n";
    $operation=$pdo->prepare('INSERT INTO manager_promise_selection_operations(manager_id,operation_id,conversation_id,action,intent_id,request_sha256,created_at) VALUES(?,?,?,?,?,?,?)');
    $op=str_repeat('8',32);$operation->execute([7,$op,1,'attach',$intentA,str_repeat('9',64),$now]);
    $pdo->beginTransaction();$intentRollback=str_repeat('a',32);$nativeIntent($intentRollback,str_repeat('b',64),str_repeat('c',32));$duplicateOperation=false;
    try{$operation->execute([7,$op,1,'cancel',$intentRollback,str_repeat('d',64),$now]);}catch(PDOException $e){$duplicateOperation=(int)($e->errorInfo[1]??0)===1062;}
    $pdo->rollBack();$q=$pdo->prepare('SELECT COUNT(*) FROM manager_promise_selection_intents WHERE intent_id=?');$q->execute([$intentRollback]);
    if(!$duplicateOperation||(int)$q->fetchColumn()!==0||(int)$pdo->query('SELECT COUNT(*) FROM manager_promise_selection_operations')->fetchColumn()!==1)throw new RuntimeException('native_operation_conflict_did_not_rollback_intent');
    echo "PASS native operation namespace rejects duplicate attach/cancel receipts and rolls back their intent\n";
    $foreignConversation=false;$foreignIntent=false;
    try{$nativeIntent(str_repeat('d',32),str_repeat('e',64),str_repeat('f',32),999);}catch(PDOException $e){$foreignConversation=(int)($e->errorInfo[1]??0)===1452;}
    try{$operation->execute([7,str_repeat('e',32),1,'attach',str_repeat('f',32),str_repeat('0',64),$now]);}catch(PDOException $e){$foreignIntent=(int)($e->errorInfo[1]??0)===1452;}
    $cascadeIntent=str_repeat('d',32);$nativeIntent($cascadeIntent,str_repeat('e',64),str_repeat('f',32),2);
    $operation->execute([7,str_repeat('e',32),2,'attach',$cascadeIntent,str_repeat('0',64),$now]);$pdo->exec('DELETE FROM conversations WHERE id=2');
    if(!$foreignConversation||!$foreignIntent||(int)$pdo->query('SELECT COUNT(*) FROM manager_promise_selection_intents WHERE conversation_id=2')->fetchColumn()!==0
        ||(int)$pdo->query('SELECT COUNT(*) FROM manager_promise_selection_operations WHERE conversation_id=2')->fetchColumn()!==0)throw new RuntimeException('native_promise_foreign_key_lifecycle_failed');
    echo "PASS native intent and operation foreign keys reject orphans and cascade only their conversation\n";
    $pdo->exec("INSERT INTO lead_tasks(id,conversation_id,title,status,is_pinned,assigned_manager_id,created_by_manager_id) VALUES(101,1,'Keep manual work','open',0,7,7),(102,1,'Complete once','open',1,7,7),(103,1,'Case','open',0,7,7)");
    $taskConnection=new ReflectionProperty(ConversationDb::class,'pdo');$taskConnection->setValue(null,$pdo);
    LeadTaskService::update(1,103,'case',null);$caseTask=$pdo->query('SELECT * FROM lead_tasks WHERE id=103')->fetch();LeadTaskService::update(1,103,'case',null);
    if((int)$caseTask['business_revision']!==2||$pdo->query('SELECT * FROM lead_tasks WHERE id=103')->fetch()!==$caseTask)throw new RuntimeException('native_case_only_edit_or_noop_revision_failed');
    echo "PASS native binary title comparison counts case-only edit once and preserves no-op saves\n";

    // Real native NULL/date semantics must preserve a still-valid reminder and
    // invalidate it only when the deadline changes, including clearing a date.
    $pdo->exec("UPDATE lead_tasks SET due_at_utc='2030-01-01 09:00:00',reminder_attempted_at_utc='2029-12-31 07:00:00',reminder_notified_at_utc='2029-12-31 07:00:01' WHERE id=103");
    $reminderBefore=$pdo->query('SELECT * FROM lead_tasks WHERE id=103')->fetch();
    LeadTaskService::update(1,103,'case','2030-01-01T12:00:00+03:00');
    if($pdo->query('SELECT * FROM lead_tasks WHERE id=103')->fetch()!==$reminderBefore)throw new RuntimeException('native_identical_due_reset_reminder');
    LeadTaskService::update(1,103,'CASE','2030-01-01T12:00:00+03:00');$titleOnly=$pdo->query('SELECT * FROM lead_tasks WHERE id=103')->fetch();
    if((int)$titleOnly['business_revision']!==3||$titleOnly['reminder_attempted_at_utc']!==$reminderBefore['reminder_attempted_at_utc']||$titleOnly['reminder_notified_at_utc']!==$reminderBefore['reminder_notified_at_utc'])throw new RuntimeException('native_title_edit_reset_reminder');
    LeadTaskService::update(1,103,'CASE','2030-01-02T12:00:00+03:00');$dateChanged=$pdo->query('SELECT * FROM lead_tasks WHERE id=103')->fetch();
    if((int)$dateChanged['business_revision']!==4||$dateChanged['due_at_utc']!=='2030-01-02 09:00:00'||$dateChanged['reminder_attempted_at_utc']!==null||$dateChanged['reminder_notified_at_utc']!==null)throw new RuntimeException('native_deadline_edit_kept_old_reminder');
    LeadTaskService::update(1,103,'CASE',null);$cleared=$pdo->query('SELECT * FROM lead_tasks WHERE id=103')->fetch();LeadTaskService::update(1,103,'CASE',null);
    if((int)$cleared['business_revision']!==5||$cleared['due_at_utc']!==null||$pdo->query('SELECT * FROM lead_tasks WHERE id=103')->fetch()!==$cleared)throw new RuntimeException('native_clear_deadline_not_idempotent');
    echo "PASS native deadline and NULL edits reset reminders only on a real due change\n";

    $pdo->beginTransaction();LeadTaskService::setCompleted(1,101,true);LeadTaskService::setCompleted(1,101,false);
    $second->beginTransaction();$taskConnection->setValue(null,$second);$blocked=false;
    try{LeadTaskService::completeForPromise($second,1,101,1,7);}catch(PDOException $e){$blocked=(int)($e->errorInfo[1]??0)===1205;}
    if($second->inTransaction())$second->rollBack();$pdo->commit();
    $second->beginTransaction();$changed=LeadTaskService::completeForPromise($second,1,101,1,7);$second->commit();
    $manual=$second->query('SELECT status,business_revision,title FROM lead_tasks WHERE id=101')->fetch();
    if(!$blocked||$changed!==['outcome'=>'changed','business_revision'=>3]||$manual['status']!=='open'||$manual['title']!=='Keep manual work')throw new RuntimeException('native_manual_reopen_not_fenced');
    echo "PASS native task completion waits for manual reopen then rejects its stale revision\n";

    $taskConnection->setValue(null,$pdo);$pdo->beginTransaction();$first=LeadTaskService::completeForPromise($pdo,1,102,1,7);
    $taskConnection->setValue(null,$second);$second->beginTransaction();$blocked=false;
    try{LeadTaskService::completeForPromise($second,1,102,1,7);}catch(PDOException $e){$blocked=(int)($e->errorInfo[1]??0)===1205;}
    if($second->inTransaction())$second->rollBack();$pdo->commit();
    $second->beginTransaction();$duplicate=LeadTaskService::completeForPromise($second,1,102,1,7);$second->commit();
    if(!$blocked||$first!==['outcome'=>'applied','business_revision'=>2]||$duplicate!==['outcome'=>'already_done','business_revision'=>2]
        ||(int)$second->query('SELECT business_revision FROM lead_tasks WHERE id=102')->fetchColumn()!==2)throw new RuntimeException('native_task_completion_not_exactly_once');
    echo "PASS native competing task completions converge on one done revision without overwrite\n";
} finally {
    if($second&&$second->inTransaction())$second->rollBack();
    if ($pdo && $safe) {if ($pdo->inTransaction()) $pdo->rollBack();foreach ($tables as $table) $pdo->exec('DROP TABLE IF EXISTS '.$table);}
    if($taskConnection)$taskConnection->setValue(null,null);
}
require __DIR__.'/manager-tour-collector.php';
require __DIR__.'/manager-tour-interest.php';
require __DIR__.'/hotel-demand-inventory.php';
require __DIR__.'/search3-tour-handoff.php';
