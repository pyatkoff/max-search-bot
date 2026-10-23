<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
require_once dirname(__DIR__).'/support/manager-tour-interest.php';
$native=null;$interestSafe=false;
$interestTables=['manager_tour_selection_interest','manager_tour_selection_link_history','manager_tour_selection_links','manager_tour_selection_cards','manager_tour_selection_deliveries','manager_tour_selection_publications','manager_tour_selection_operations','manager_tour_selection_drafts'];
try {
    $dsn=(string)getenv('PREPARED_REPLY_MYSQL_DSN');
    if (!preg_match('/\Amysql:.*(?:\Amysql:|;)dbname=(maxbot_prepared_test(?:_[a-zA-Z0-9_]+)?)(?:;|\z)/D',$dsn,$m)) throw new RuntimeException('unsafe_fixture_database');
    $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
    $native=new PDO($dsn,getenv('PREPARED_REPLY_MYSQL_USER')?:null,getenv('PREPARED_REPLY_MYSQL_PASS')?:null,$options);
    if ($native->query('SELECT DATABASE()')->fetchColumn()!==$m[1]) throw new RuntimeException('unsafe_fixture_database');$interestSafe=true;
    foreach ($interestTables as $table) $native->exec('DROP TABLE IF EXISTS '.$table);
    $seed=deliveryDb();
    foreach ($seed->query("SELECT name,sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' AND name NOT LIKE 'manager_tour_selection_%'")->fetchAll() as $table) {
        $sql=preg_replace('/\bINTEGER PRIMARY KEY AUTOINCREMENT\b/','BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY',$table['sql']);
        $sql=preg_replace('/\bINTEGER\b/','BIGINT',$sql);$sql=str_replace('created_at TEXT DEFAULT CURRENT_TIMESTAMP','created_at DATETIME DEFAULT CURRENT_TIMESTAMP',$sql);
        $native->exec(preg_replace('/\ACREATE TABLE /','CREATE TEMPORARY TABLE ',$sql));
        foreach ($seed->query('SELECT * FROM '.$table['name'])->fetchAll() as $row) {
            $q=$native->prepare('INSERT INTO '.$table['name'].'('.implode(',',array_keys($row)).') VALUES('.implode(',',array_fill(0,count($row),'?')).')');$q->execute(array_values($row));
        }
    }
    foreach (['053_manager_tour_selections.sql','054_manager_tour_selection_publications.sql'] as $file) $native->exec(file_get_contents(dirname(__DIR__,2).'/migrations/'.$file));
    (new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$native);
    interestScenarios($native);
    $before=$native->query('SELECT * FROM manager_tour_selection_interest ORDER BY event_id')->fetchAll();interestSchema($native);
    deliveryCheck('native058 retry preserves original observation identities and native receipts',$native->query('SELECT * FROM manager_tour_selection_interest ORDER BY event_id')->fetchAll()===$before);
    $second=new PDO($dsn,getenv('PREPARED_REPLY_MYSQL_USER')?:null,getenv('PREPARED_REPLY_MYSQL_PASS')?:null,$options);$second->exec('SET SESSION innodb_lock_wait_timeout=1');
    $event=$before[0]['event_id'];$native->beginTransaction();$q=$native->prepare('SELECT event_id FROM manager_tour_selection_interest WHERE event_id=? FOR UPDATE');$q->execute([$event]);$q->fetchColumn();
    $blocked=false;try {$q=$second->prepare('DELETE FROM manager_tour_selection_interest WHERE event_id=?');$q->execute([$event]);}catch(PDOException $e){$blocked=(int)($e->errorInfo[1]??0)===1205;}$native->commit();
    deliveryCheck('native observation ownership serializes concurrent cleanup',$blocked);
    $duplicate=false;try {$q=$second->prepare('INSERT INTO manager_tour_selection_interest SELECT * FROM manager_tour_selection_interest WHERE event_id=?');$q->execute([$event]);}catch(PDOException $e){$duplicate=$e->getCode()==='23000';}
    deliveryCheck('native exact operation key cannot create a second observation',$duplicate);
    echo "NATIVE INTEREST TOTAL {$checks} PASS\n";
} finally {
    if ($native && $interestSafe) {if($native->inTransaction())$native->rollBack();foreach($interestTables as $table)$native->exec('DROP TABLE IF EXISTS '.$table);}
}
