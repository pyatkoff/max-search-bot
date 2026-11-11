<?php
declare(strict_types=1);

/** The same real poll/JSON SQL runs on SQLite and the existing isolated MySQL job. */
function regionalNotificationFeedChecks(PDO $pdo): void
{
    $tables=['conversations','messages','managers','manager_projects','manager_groups','manager_group_members',
        'conversation_sources','manager_regional_access','manager_group_territories','manager_group_territory_regions','manager_reads'];
    $snapshot=static function()use($pdo,$tables):array{
        $rows=[];foreach($tables as$table)$rows[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
        return$rows;
    };
    $before=$snapshot();
    $receipt=static fn(string$region,string$platform):array=>[
        'version'=>1,'yandex_region_id'=>$region,'name'=>'Synthetic advertising region','kind'=>'advertising',
        'source'=>$platform==='telegram'?'telegram_start_payload':($platform==='website'?'website_entry':'max_start_payload'),
        'platform'=>$platform,'recorded_at_unix'=>1794300000,'receipt_sha256'=>hash('sha256','synthetic-'.$platform.'-'.$region),
    ];
    $metadata=static fn(array$first,?array$latest=null):string=>json_encode(['first_entry_region'=>$first,'entry_region'=>$latest??$first],JSON_THROW_ON_ERROR);
    $pdo->beginTransaction();
    try{
        $pdo->exec('DELETE FROM messages;DELETE FROM conversations');
        $pdo->exec('INSERT INTO manager_regional_access VALUES(7,1,1);INSERT INTO manager_groups VALUES(10,1,1);INSERT INTO manager_group_members VALUES(7,10)');
        $anchor=YandexRegionCatalog::findByYandexId('62');
        if(!is_array($anchor)||count($anchor['hierarchy']??[])<2)throw new RuntimeException('regional_feed_fixture_anchor_missing');
        $q=$pdo->prepare('INSERT INTO manager_group_territories VALUES(?,?,?,?,?)');
        $q->execute([10,'62',json_encode($anchor['hierarchy'],JSON_THROW_ON_ERROR),json_encode(array_slice($anchor['hierarchy'],0,2),JSON_THROW_ON_ERROR),RegionalTerritoryCatalog::manifestHash()]);
        $pdo->exec("INSERT INTO manager_group_territory_regions VALUES(10,'62','62')");
        $q=$pdo->prepare("INSERT INTO conversations(id,project_key,source_id,status,manager_id,is_test,started_at,last_message_at,channel,metadata_json) VALUES(?,'allowed',0,'waiting_manager',NULL,0,'2026-11-11 12:00:00','2026-11-11 12:00:00',?,?)");
        $q->execute([1001,'max',$metadata($receipt('62','max'))]);
        $q->execute([1002,'max',$metadata($receipt('51','max'))]);
        $message=$pdo->prepare("INSERT INTO messages(id,conversation_id,direction,sender_type,metadata_json) VALUES(?,?,'inbound','customer','{\"type\":\"message\",\"private_token\":\"synthetic-private\"}')");
        $set=$pdo->prepare('UPDATE conversations SET channel=?,metadata_json=? WHERE id=?');
        $events=static function()use($pdo):array{
            $sqlite=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
            if($sqlite)$pdo->exec('PRAGMA query_only=ON');
            try{return array_column(ManagerIncomingNotificationService::poll(7,0)['events'],'message_id');}
            finally{if($sqlite)$pdo->exec('PRAGMA query_only=OFF');}
        };
        foreach(['max','telegram','website']as$platform){
            $pdo->exec('DELETE FROM messages');
            $set->execute([$platform,$metadata($receipt('62',$platform)),1001]);
            $set->execute([$platform,$metadata($receipt('51',$platform)),1002]);
            for($i=1;$i<=501;$i++)$message->execute([$i,1002]);
            $message->execute([1002,1001]);
            check('501 foreign arrivals cannot starve actual regional '.$platform.' poll',$events(),[1002]);
        }
        $pdo->exec('UPDATE conversations SET manager_id=0 WHERE id=1001');
        check('canonical unowned zero-manager rows retain the same feed access as NULL',$events(),[1002]);
        $pdo->exec('UPDATE conversations SET manager_id=NULL WHERE id=1001');
        $bad=$receipt('62','website');$bad['version']='1';
        $set->execute(['website',$metadata($bad),1002]);
        check('501 malformed receipts are rejected before the regional window cap',$events(),[1002]);
        $set->execute(['website',$metadata($receipt('51','website'),$receipt('62','website')),1002]);
        $set->execute(['website',$metadata($receipt('62','website'),$receipt('51','website')),1001]);
        $result=ManagerIncomingNotificationService::poll(7,99999);
        check('first receipt controls access while latest click and high cursor do not',array_column($result['events'],'message_id'),[1002]);
        check('regional event response retains exact private projection',$result,['manager_id'=>7,'cursor'=>1002,'events'=>[['conversation_id'=>1001,'message_id'=>1002]],'has_more'=>false]);
        $pdo->exec("INSERT INTO conversation_sources VALUES(77,99,'none',0,0,1);UPDATE conversations SET source_id=77 WHERE id=1001");
        check('canonical source-group policy still runs after the regional candidate gate',$events(),[]);
        $pdo->exec('UPDATE conversations SET source_id=0 WHERE id=1001;UPDATE managers SET is_working=0 WHERE id=7');
        check('off-shift manager receives no unassigned regional events',$events(),[]);
        $pdo->exec("UPDATE conversations SET status='manager',manager_id=7 WHERE id=1001");
        $set->execute(['website',$metadata($receipt('51','website')),1001]);
        check('off-shift own assignment retains ordinary access even outside its region',$events(),[1002]);
        $pdo->exec("UPDATE managers SET is_working=1 WHERE id=7;UPDATE conversations SET status='waiting_manager',manager_id=NULL WHERE id=1001");
        $set->execute(['website',$metadata($receipt('62','website')),1001]);
        $pdo->exec('DELETE FROM manager_group_territories');
        check('revoked territory cannot restore unassigned event access',$events(),[]);
        $pdo->prepare('INSERT INTO manager_group_territories VALUES(?,?,?,?,?)')->execute([10,'62',json_encode($anchor['hierarchy'],JSON_THROW_ON_ERROR),json_encode(array_slice($anchor['hierarchy'],0,2),JSON_THROW_ON_ERROR),RegionalTerritoryCatalog::manifestHash()]);
        $pdo->exec('DELETE FROM messages');
        for($i=1;$i<=500;$i++)$message->execute([$i,1001]);
        check('500 genuinely authorized regional events remain available',count($events()),500);
        $message->execute([501,1001]);$overflow=false;
        try{$events();}catch(RuntimeException$error){$overflow=$error->getMessage()==='notification_window_overflow';}
        check('501 genuinely authorized regional events retain explicit overflow',$overflow,true);
        $pdo->exec("UPDATE managers SET role='admin' WHERE id=7");
        $set->execute(['website',$metadata($receipt('51','website')),1001]);
        $overflow=false;try{$events();}catch(RuntimeException$error){$overflow=$error->getMessage()==='notification_window_overflow';}
        check('admin visibility remains unrestricted despite a durable regional flag',$overflow,true);
        $pdo->exec("UPDATE managers SET role='manager' WHERE id=7;DELETE FROM manager_projects WHERE manager_id=7");
        check('revoked project reveals no event or foreign high-water',$events(),[]);
    }finally{
        $pdo->rollBack();
    }
    check('regional fixture rollback preserves original HTTP arrivals and all business state',$snapshot(),$before);
}
