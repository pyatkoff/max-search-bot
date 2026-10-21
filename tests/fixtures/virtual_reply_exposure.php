<?php

declare(strict_types=1);

require_once dirname(__DIR__,2).'/services/ProjectConfig.php';
require_once dirname(__DIR__,2).'/services/VirtualReplySourceReader.php';

/**
 * Execute the real identity reader against minimal scoped source tables.
 * MySQL tables are connection-local shadows; persistent fixtures are untouched.
 * SQLite callers must supply a new in-memory connection.
 */
function virtualReplyExposureAssertions(PDO $pdo,callable $check): void
{
    ProjectConfig::resetForTests(['id'=>'anytour']);
    $temporary=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'TEMPORARY ':'';
    $tables=[
        'projects'=>'project_key VARCHAR(64) PRIMARY KEY,is_active INTEGER',
        'conversations'=>'id INTEGER PRIMARY KEY,project_key VARCHAR(64),is_test INTEGER,channel VARCHAR(32),customer_id INTEGER,customer_channel_id INTEGER',
        'customer_channels'=>'id INTEGER PRIMARY KEY,customer_id INTEGER,project_key VARCHAR(64),channel VARCHAR(32),external_user_id VARCHAR(32),external_chat_id VARCHAR(32)',
        'messages'=>'id INTEGER PRIMARY KEY,conversation_id INTEGER',
        'telegram_dialogue_messages'=>'id INTEGER PRIMARY KEY,project_key VARCHAR(64),account_id VARCHAR(20),chat_id VARCHAR(20),source_kind VARCHAR(32),record_json LONGTEXT',
        'telegram_archive_accounts'=>'project_key VARCHAR(64),bot_key VARCHAR(96),account_id VARCHAR(20),connection_id VARCHAR(64),approved INTEGER,provider_enabled INTEGER,manager_id INTEGER',
        'telegram_dialogue_connections'=>'project_key VARCHAR(64),bot_key VARCHAR(96),connection_id VARCHAR(64),account_id VARCHAR(20),is_enabled INTEGER',
    ];
    foreach ($tables as $name=>$columns) $pdo->exec('CREATE '.$temporary.'TABLE '.$name.' ('.$columns.')');
    $run=static function(string $sql,array $args=[])use($pdo):void {$q=$pdo->prepare($sql);$q->execute($args);};
    $run('INSERT INTO projects VALUES (?,?),(?,?)',['anytour',1,'other',1]);
    $run('INSERT INTO conversations VALUES (?,?,?,?,?,?),(?,?,?,?,?,?),(?,?,?,?,?,?)',
        [1,'anytour',0,'telegram',11,71,2,'anytour',1,'max',12,72,3,'other',0,'telegram',13,73]);
    $run('INSERT INTO customer_channels VALUES (?,?,?,?,?,?),(?,?,?,?,?,?),(?,?,?,?,?,?)',
        [71,11,'anytour','telegram','91001','91001',72,12,'anytour','max','91002','91002',73,13,'other','telegram','91003','91003']);
    $run('INSERT INTO messages VALUES (?,?),(?,?),(?,?),(?,?)',[1,1,2,2,3,3,4,99]);
    $run('INSERT INTO telegram_archive_accounts VALUES (?,?,?,?,?,?,?)',['anytour','archive','100','current',1,1,7]);
    $run('INSERT INTO telegram_dialogue_connections VALUES (?,?,?,?,?)',['anytour','archive','current','100',1]);
    $records=[
        1=>['anytour','100','91001','business_message','{"bot_key":"archive","text":"private synthetic content"}'],
        2=>['anytour','100','91001','business_message','{"bot_key":"unapproved"}'],
        3=>['other','100','91001','business_message','{"bot_key":"archive"}'],
        4=>['anytour','100','91001','desktop_import','{"bot_key":null}'],
        5=>['anytour','100','91001','business_message','{"bot_key":null}'],
        6=>['anytour','100','91001','business_message','{"bot_key":"archive","text":"'.str_repeat('x',262144).'"}'],
    ];
    foreach ($records as $id=>$record) $run('INSERT INTO telegram_dialogue_messages VALUES (?,?,?,?,?,?)',[$id,...$record]);
    $reader=new VirtualReplySourceReader($pdo);
    $known=VirtualReplySamplingIdentity::archive('91001','');
    $conversation=$reader->exposureIdentity('conversation',1);
    $check('exposure reader maps permitted internal/test entity identity without any conversation text columns',
        $conversation===VirtualReplySamplingIdentity::conversation(1,11,'telegram',['91001','91001'],'')
        && $reader->exposureIdentity('conversation',2)===VirtualReplySamplingIdentity::conversation(2,12,'max',['91002','91002'],''));
    $run('UPDATE customer_channels SET customer_id=? WHERE id=?',[99,71]);
    $incomplete=$reader->exposureIdentity('conversation',1);
    $check('exposure reader does not invent peer aliases from an incomplete binding',
        !$incomplete['identity_complete'] && array_intersect($incomplete['identity_keys'],$known['identity_keys'])===[]);
    $run('UPDATE customer_channels SET customer_id=?,external_user_id=? WHERE id=?',[11,'091001',71]);
    $ambiguous=$reader->exposureIdentity('conversation',1);
    $check('exposure reader refuses noncanonical Telegram peer binding',
        !$ambiguous['identity_complete'] && array_intersect($ambiguous['identity_keys'],$known['identity_keys'])===[]);
    $check('actual archive bot scalar projection returns only the approved whole-group hashes',
        $reader->exposureIdentity('telegram_archive',1)===$known
        && array_keys($known)===['identity_keys','identity_complete','kind'] && $known['kind']==='substantive'
        && count(array_filter($known['identity_keys'],static fn($key):bool=>preg_match('/\A[a-f0-9]{64}\z/D',$key)===1))===count($known['identity_keys']));
    $check('archive identity does not let one approved bot authorize another bot on the same account',
        $reader->exposureIdentity('telegram_archive',2)===null);
    $check('exposure reader preserves project scope and hard-missing/orphaned source fences',
        $reader->exposureIdentity('conversation',3)===null && $reader->exposureIdentity('conversation',4)===null
        && $reader->exposureIdentity('conversation',99)===null && $reader->exposureIdentity('telegram_archive',3)===null
        && $reader->exposureIdentity('telegram_archive',99)===null);
    $check('archive exposure repair distinguishes account-permitted desktop history from a missing business bot',
        $reader->exposureIdentity('telegram_archive',4)===$known && $reader->exposureIdentity('telegram_archive',5)===null);
    $check('archive exposure parsing is bounded before valid oversized JSON work or materialization',
        $reader->exposureIdentity('telegram_archive',6)===null && !$pdo->inTransaction());
    foreach (['approved','provider_enabled'] as $flag) {
        $run('UPDATE telegram_archive_accounts SET '.$flag.'=?',[0]);
        $check('archive exposure repair requires current '.$flag,
            $reader->exposureIdentity('telegram_archive',1)===null && $reader->exposureIdentity('telegram_archive',4)===null);
        $run('UPDATE telegram_archive_accounts SET '.$flag.'=?',[1]);
    }
    $run('UPDATE telegram_dialogue_connections SET is_enabled=?',[0]);
    $check('archive exposure repair requires the enabled current connection',$reader->exposureIdentity('telegram_archive',1)===null);
    $run('UPDATE telegram_dialogue_connections SET is_enabled=?,account_id=?',[1,'999']);
    $check('archive exposure repair verifies the current connection account',$reader->exposureIdentity('telegram_archive',1)===null);
    $run('UPDATE telegram_dialogue_connections SET account_id=?',['100']);
    $invalid=['{','null','[]','{"bot_key":12}','{"bot_key":true}','{"bot_key":[]}','{"bot_key":{}}',
        '{"bot_key":"archive","bot_key":"unapproved"}','{"bot_key":"unapproved","bot_key":"archive"}',
        '{"bot_key":"archive","nested":{"bot_key":"unapproved"}}',
        '{"bot_key":"archive","bot_\\u006bey":"unapproved"}','{"bot_\\u006bey":"unapproved","bot_key":"archive"}'];
    $results=[];
    foreach ($invalid as $record) {$run('UPDATE telegram_dialogue_messages SET record_json=? WHERE id=?',[$record,1]);$results[]=$reader->exposureIdentity('telegram_archive',1);}
    $check('actual archive JSON projection fails closed for invalid, nonstring, duplicate and escaped bot keys',
        $results===array_fill(0,count($invalid),null));
    $run('UPDATE telegram_dialogue_messages SET record_json=? WHERE id=?',['{"bot_key":"archive","text":"private content never returned"}',1]);
    $check('archive exposure repair recovers only permitted metadata after malformed input without fetching content',
        $reader->exposureIdentity('telegram_archive',1)===$known && !$pdo->inTransaction());
    $run('UPDATE projects SET is_active=? WHERE project_key=?',[0,'anytour']);
    $check('inactive project denies both exposure identity sources',
        $reader->exposureIdentity('conversation',2)===null && $reader->exposureIdentity('telegram_archive',1)===null);
    $run('UPDATE projects SET is_active=? WHERE project_key=?',[1,'anytour']);
    $run('DELETE FROM telegram_dialogue_messages WHERE id=?',[1]);
    $run('DELETE FROM messages WHERE id=?',[2]);
    $check('hard-deleted identity sources remain unresolved without reconstructing content',
        $reader->exposureIdentity('telegram_archive',1)===null && $reader->exposureIdentity('conversation',2)===null);
}
