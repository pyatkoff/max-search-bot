<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
require_once dirname(__DIR__).'/support/manager-tour-collector.php';
$native=null;$collectorSafe=false;
$collectorTables=['manager_tour_selection_collectors','manager_tour_selection_operations','manager_tour_selection_drafts'];
try {
    $dsn=(string)getenv('PREPARED_REPLY_MYSQL_DSN');
    if (!preg_match('/\Amysql:.*(?:\Amysql:|;)dbname=(maxbot_prepared_test(?:_[a-zA-Z0-9_]+)?)(?:;|\z)/D',$dsn,$m)) throw new RuntimeException('unsafe_fixture_database');
    $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
    $native=new PDO($dsn,getenv('PREPARED_REPLY_MYSQL_USER')?:null,getenv('PREPARED_REPLY_MYSQL_PASS')?:null,$options);
    if ($native->query('SELECT DATABASE()')->fetchColumn()!==$m[1]) throw new RuntimeException('unsafe_fixture_database');
    $collectorSafe=true;
    foreach ($collectorTables as $table) $native->exec('DROP TABLE IF EXISTS '.$table);
    // Share synthetic business inputs; core identity tables stay connection-local.
    // Drafts, receipts and connection metadata use the actual native migrations.
    $seed=deliveryDb();
    foreach ($seed->query("SELECT name,sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' AND name NOT LIKE 'manager_tour_selection_%'")->fetchAll() as $table) {
        $sql=preg_replace('/\bINTEGER PRIMARY KEY AUTOINCREMENT\b/','BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY',$table['sql']);
        $sql=preg_replace('/\bINTEGER\b/','BIGINT',$sql);
        $sql=preg_replace('/\ACREATE TABLE /','CREATE TEMPORARY TABLE ',$sql);
        $native->exec($sql);
        foreach ($seed->query('SELECT * FROM '.$table['name'])->fetchAll() as $row) {
            $q=$native->prepare('INSERT INTO '.$table['name'].' ('.implode(',',array_keys($row)).') VALUES ('.implode(',',array_fill(0,count($row),'?')).')');$q->execute(array_values($row));
        }
    }
    $native->exec(file_get_contents(dirname(__DIR__,2).'/migrations/053_manager_tour_selections.sql'));
    collectorSchema($native);
    (new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$native);
    collectorScenarios($native);
    $before=$native->query('SELECT * FROM manager_tour_selection_collectors ORDER BY connection_id')->fetchAll();
    collectorSchema($native);
    deliveryCheck('native057 retry preserves connection expiry and revocation receipts',$native->query('SELECT * FROM manager_tour_selection_collectors ORDER BY connection_id')->fetchAll()===$before);
    $second=new PDO($dsn,getenv('PREPARED_REPLY_MYSQL_USER')?:null,getenv('PREPARED_REPLY_MYSQL_PASS')?:null,$options);$second->exec('SET SESSION innodb_lock_wait_timeout=1');
    $connection=str_repeat('3',32);
    $native->beginTransaction();$q=$native->prepare('SELECT connection_id FROM manager_tour_selection_collectors WHERE connection_id=? FOR UPDATE');$q->execute([$connection]);$q->fetchColumn();
    $blocked=false;
    try {$q=$second->prepare('UPDATE manager_tour_selection_collectors SET revoked_at=NOW() WHERE connection_id=?');$q->execute([$connection]);} catch(PDOException $e) {$blocked=(int)($e->errorInfo[1]??0)===1205;}
    $native->commit();
    deliveryCheck('native collector authorization lock serializes concurrent revocation',$blocked);
    echo "NATIVE COLLECTOR TOTAL {$checks} PASS\n";
} finally {
    if ($native && $collectorSafe) {if($native->inTransaction())$native->rollBack();foreach($collectorTables as $table)$native->exec('DROP TABLE IF EXISTS '.$table);}
}
