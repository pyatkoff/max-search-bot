<?php
declare(strict_types=1);
function maxAttributionFixture(): PDO
{
    $dsn=getenv('MAX_ATTRIBUTION_TEST_MYSQL_DSN');
    if($dsn){
        if(!preg_match('/dbname=max_attribution_test(?:;|$)/',$dsn))throw new RuntimeException('isolated_test_database_required');
        $p=new PDO($dsn,getenv('MAX_ATTRIBUTION_TEST_MYSQL_USER')?:'root',getenv('MAX_ATTRIBUTION_TEST_MYSQL_PASS')?:'', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
        $p->exec("SET time_zone='+00:00'");
        foreach(['max_sub_links','b_iblock_property','b_iblock_element_property','max_channel_conversion_outbox','max_channel_attribution_settings','conversation_events','messages','post_entries','post_publications','post_channels','conversations','conversation_sources','customer_channels','projects'] as $t)$p->exec('DROP TABLE IF EXISTS '.$t);
    }else{$p=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);}
    $sqlite=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';$pk=$sqlite?'INTEGER PRIMARY KEY AUTOINCREMENT':'BIGINT PRIMARY KEY AUTO_INCREMENT';
    foreach([
        'projects (id INTEGER PRIMARY KEY,project_key VARCHAR(64))',
        'customer_channels (id INTEGER PRIMARY KEY,project_key VARCHAR(64),channel VARCHAR(16),external_user_id VARCHAR(20))',
        'conversations (id INTEGER PRIMARY KEY,customer_channel_id INTEGER,project_key VARCHAR(64),channel VARCHAR(16),external_chat_id VARCHAR(20),is_test INTEGER,status VARCHAR(16),source_id INTEGER DEFAULT 1,started_at DATETIME DEFAULT NULL)',
        'conversation_sources (id INTEGER PRIMARY KEY,project_id INTEGER,channel VARCHAR(16),handling_mode VARCHAR(16))',
        'post_channels (id INTEGER PRIMARY KEY,project_id INTEGER,platform VARCHAR(16),bot_key VARCHAR(191))',
        'post_publications (id INTEGER PRIMARY KEY,post_channel_id INTEGER)',
        'post_entries (id INTEGER PRIMARY KEY,conversation_id INTEGER,publication_id INTEGER,created_at DATETIME)',
        'messages (id INTEGER PRIMARY KEY,conversation_id INTEGER,channel VARCHAR(16),direction VARCHAR(16),sender_type VARCHAR(16),created_at DATETIME,metadata_json TEXT)',
        'conversation_events (id '.$pk.',conversation_id INTEGER,event_type VARCHAR(80),actor_type VARCHAR(16),payload_json TEXT)',
    ] as $ddl)$p->exec('CREATE TABLE '.$ddl);
    if(!$sqlite){$p->exec(file_get_contents(dirname(__DIR__,2).'/migrations/037_max_channel_conversion_outbox.sql'));}
    else{
        $p->exec('CREATE TABLE max_channel_attribution_settings (project_key TEXT PRIMARY KEY,mode TEXT,counter_id TEXT,activated_at_unix INTEGER,message_cursor INTEGER DEFAULT 0)');
        $p->exec("INSERT INTO max_channel_attribution_settings VALUES ('anytour','off','98615635',NULL,0)");
        $p->exec('CREATE TABLE max_channel_conversion_outbox (id INTEGER PRIMARY KEY AUTOINCREMENT,project_key TEXT,conversation_id INTEGER,external_user_id TEXT,counter_id TEXT,target TEXT,event_unix INTEGER,client_id TEXT,tracker_record_id TEXT,tracker_observed_unix INTEGER,state TEXT DEFAULT \'pending\',next_attempt_unix INTEGER DEFAULT 0,claim_token TEXT,claimed_at_unix INTEGER,attempts INTEGER DEFAULT 0,upload_id TEXT,upload_status TEXT,last_error TEXT DEFAULT \'\',UNIQUE(project_key,conversation_id,target))');
    }
    $p->exec("INSERT INTO projects VALUES(1,'anytour'),(2,'other')");
    $p->exec("INSERT INTO customer_channels VALUES(1,'anytour','max','1234567890123456789'),(2,'anytour','telegram','1234567890123456789'),(3,'other','max','1234567890123456789')");
    $p->exec("INSERT INTO conversations(id,customer_channel_id,project_key,channel,external_chat_id,is_test,status) VALUES(1,1,'anytour','max','-1234567890123456789',0,'waiting_manager'),(2,2,'anytour','telegram','-1234567890123456789',0,'waiting_manager'),(3,3,'other','max','-1234567890123456789',0,'waiting_manager'),(4,1,'anytour','max','-1234567890123456789',1,'waiting_manager'),(5,1,'anytour','max','-1234567890123456788',0,'waiting_manager'),(6,1,'anytour','max','-1234567890123456789',0,'waiting_manager')");
    $p->exec("INSERT INTO conversation_sources VALUES(1,1,'max','bot'),(2,1,'max','manager'),(3,2,'max','manager')");
    $p->exec("UPDATE conversations SET started_at='2026-10-15 10:00:00'");
    $p->exec("INSERT INTO conversations VALUES(7,1,'anytour','max','-1234567890123456789',0,'waiting_manager',2,'2026-10-15 10:00:00')");
    $p->exec("INSERT INTO post_channels VALUES(1,1,'max','id9704048781_1_bot'),(2,1,'max','other_bot'),(3,2,'max','id9704048781_1_bot')");
    $p->exec('INSERT INTO post_publications VALUES(1,1),(2,2),(3,3)');
    foreach([1,2,3,4,5] as $id)$p->prepare('INSERT INTO post_entries VALUES(?,?,1,?)')->execute([$id,$id,'2026-10-15 10:00:00']);
    $p->exec("INSERT INTO post_entries VALUES(6,6,2,'2026-10-15 10:00:00')");
    $p->exec("UPDATE max_channel_attribution_settings SET mode='collect',activated_at_unix=1792058400");
    $messages=[
        [1,'inbound','customer','09:59:59','message'],
        [2,'inbound','customer','10:00:01','callback'],
        [3,'inbound','customer','10:00:02','message'],
        [4,'outbound','bot','10:00:03','message'],
        [5,'outbound','manager','10:00:04','message'],
        [6,'inbound','customer','10:00:04','callback'],
        [7,'inbound','customer','10:00:04','message'],
        [8,'outbound','manager','10:00:05','message'],
        [9,'inbound','customer','10:00:06','message'],
    ];
    foreach($messages as [$id,$direction,$sender,$time,$type])$p->prepare('INSERT INTO messages VALUES(?,1,\'max\',?,?,?,?)')->execute([$id,$direction,$sender,'2026-10-15 '.$time,json_encode(['type'=>$type])]);
    return $p;
}
