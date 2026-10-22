<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
$pdo=null;$safe=false;$tables=['manager_tour_selection_cards','manager_tour_selection_deliveries','manager_tour_selection_publications'];
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
} finally {
    if ($pdo && $safe) {if ($pdo->inTransaction()) $pdo->rollBack();foreach ($tables as $table) $pdo->exec('DROP TABLE IF EXISTS '.$table);}
}
