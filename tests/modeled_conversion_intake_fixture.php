<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/ModeledConversionIntakeCoverageReader.php';

/** Temporary tables only: canonical source capture, independent counterexamples and native MySQL. */
function modeledIntakeCoverageAssertions(PDO $p,callable $check):void
{
    $mysql=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$tables=[];
    $create=static function(string $name,string $columns)use($p,&$tables):void{
        $p->exec('CREATE TEMPORARY TABLE '.$name.'('.$columns.')');$tables[]=$name;
    };
    try{
        $create('customers','id BIGINT PRIMARY KEY');
        $create('customer_channels','id BIGINT PRIMARY KEY,customer_id BIGINT,project_key VARCHAR(40),channel VARCHAR(16),external_user_id VARCHAR(40),external_chat_id VARCHAR(40)');
        $create('conversations','id BIGINT PRIMARY KEY,customer_id BIGINT,customer_channel_id BIGINT,project_key VARCHAR(40),channel VARCHAR(16),external_chat_id VARCHAR(40),is_test INT');
        $create('messages','id BIGINT PRIMARY KEY,conversation_id BIGINT,channel VARCHAR(16),direction VARCHAR(16),sender_type VARCHAR(16),text TEXT,metadata_json TEXT,created_at DATETIME');
        $create('conversation_events','id BIGINT PRIMARY KEY,conversation_id BIGINT,event_type VARCHAR(64),actor_type VARCHAR(16),payload_json TEXT,created_at DATETIME');
        $create('advertising_visit_observations','id BIGINT PRIMARY KEY,project_key VARCHAR(40),source_key VARCHAR(32),platform VARCHAR(16),counter_id VARCHAR(20),source_cursor BIGINT,visit_id VARCHAR(40),visit_at_unix BIGINT,observed_at_unix BIGINT,external_user_id VARCHAR(40),identity_status VARCHAR(20),client_id VARCHAR(40),yclid VARCHAR(40),campaign_id VARCHAR(40),region_id VARCHAR(40),payload_sha256 CHAR(64),received_at_unix BIGINT');
        $create('modeled_conversion_stream_state','project_key VARCHAR(40) PRIMARY KEY,enabled INT,started_at_unix BIGINT,state_json TEXT,quota_day VARCHAR(10),quota_used BIGINT,ticks BIGINT,last_receipt_json TEXT');
        $create('modeled_conversion_stream_sources','project_key VARCHAR(40),source_kind VARCHAR(16),source_id BIGINT,latest_hash CHAR(64),version_no BIGINT,observed_at_unix BIGINT,PRIMARY KEY(project_key,source_kind,source_id)');
        $create('modeled_conversion_stream_versions','id '.($mysql?'BIGINT AUTO_INCREMENT':'INTEGER').' PRIMARY KEY,project_key VARCHAR(40),source_kind VARCHAR(16),source_id BIGINT,version_no BIGINT,revision_hash CHAR(64),observed_at_unix BIGINT,snapshot_json TEXT,UNIQUE(project_key,source_kind,source_id,version_no)');
        $create('modeled_conversion_profile_sources','project_key VARCHAR(40),source_kind VARCHAR(16),source_id BIGINT,platform VARCHAR(16),customer_key VARCHAR(40),conversation_id VARCHAR(40),journal_id BIGINT,source_status VARCHAR(24),PRIMARY KEY(project_key,source_kind,source_id)');
        $create('modeled_conversion_profiles_state','project_key VARCHAR(40) PRIMARY KEY,enabled INT,journal_cursor BIGINT,last_receipt_json TEXT,last_error_at_unix BIGINT,last_error_category VARCHAR(40)');
        $create('modeled_conversion_profile_versions','id BIGINT PRIMARY KEY,project_key VARCHAR(40),platform VARCHAR(16),customer_key VARCHAR(40),conversation_id VARCHAR(40),journal_cursor BIGINT,observed_at_unix BIGINT,assessment_namespace CHAR(64),profile_json TEXT');
        $create('modeled_conversion_value_export_settings','project_key VARCHAR(40) PRIMARY KEY,counter_id VARCHAR(20),profile_cursor BIGINT,mode VARCHAR(16),goal_id VARCHAR(20),contract_version VARCHAR(64)');
        $create('max_channel_conversion_outbox','id BIGINT PRIMARY KEY,project_key VARCHAR(40),counter_id VARCHAR(20),platform VARCHAR(16),conversation_id BIGINT,external_user_id VARCHAR(40),transport_target VARCHAR(64),value_contract VARCHAR(64),event_unix BIGINT,state VARCHAR(24),value_rub DECIMAL(12,2),client_id VARCHAR(40)');
        $now=1794826800; // Fixed synthetic recording day, never the production clock.
        $stream=new ModeledConversionStream($p);$stream->start($now-3600);
        $stamp=json_encode(ConversationRecordClock::stamp(['private'=>'PRIVATE_INTAKE_CLOCK'],$now-1800),JSON_THROW_ON_ERROR);
        $message=$p->prepare('INSERT INTO messages VALUES(?,?,?,?,?,?,?,?)');
        for($i=1;$i<=8;$i++){
            $id=41000+$i;$platform=$i>=7?'telegram':'max';$peer=(string)(71000+$i);$chat=$platform==='max'?'-'.$peer:$peer;
            $p->prepare('INSERT INTO customers VALUES(?)')->execute([$id]);
            $p->prepare("INSERT INTO customer_channels VALUES(?,?,'anytour',?,?,?)")->execute([$id,$id,$platform,$peer,$chat]);
            $p->prepare("INSERT INTO conversations VALUES(?,?,?,'anytour',?,?,0)")->execute([$id,$id,$id,$platform,$chat]);
            if($i===2)continue;
            $message->execute([1000+$i,$id,$platform,'inbound','customer','PRIVATE_NATIVE_INTAKE_TEXT_'.$i,$stamp,gmdate('Y-m-d H:i:s',$now-1800)]);
        }
        $p->prepare("INSERT INTO conversation_events VALUES(1001,41001,'customer_need','customer',?,?)")->execute([$stamp,gmdate('Y-m-d H:i:s',$now-1800)]);
        $message->execute([2001,41001,'max','inbound','manager','PRIVATE_OPERATOR_NOT_CUSTOMER',$stamp,gmdate('Y-m-d H:i:s',$now-1800)]);
        $message->execute([2002,41001,'max','inbound','customer','PRIVATE_CLOCKLESS_NATIVE','{}',gmdate('Y-m-d H:i:s',$now-1800)]);
        $captured=$stream->tick($now-1200);
        $message->execute([1002,41002,'max','inbound','customer','PRIVATE_FIRST_UNCAPTURED_INPUT',$stamp,gmdate('Y-m-d H:i:s',$now-1800)]);
        $check('intake fixture uses real canonical native capture and both source kinds',($captured['new_revisions']??0)>=9);
        $p->exec("INSERT INTO modeled_conversion_profiles_state VALUES('anytour',1,100000,NULL,NULL,NULL)");
        $p->prepare("INSERT INTO modeled_conversion_value_export_settings VALUES('anytour','98615635',4,'send','678187097',?)")->execute([ModeledConversionValueContract::VERSION]);
        $sources=$p->query("SELECT * FROM modeled_conversion_stream_versions WHERE source_kind IN ('message','event') ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        foreach($sources as$row){
            $v=json_decode($row['snapshot_json'],true,32,JSON_THROW_ON_ERROR);
            if($v['conversation_id']==41003)continue;
            $p->prepare("INSERT INTO modeled_conversion_profile_sources VALUES('anytour',?,?,?,?,?,?,'present')")
                ->execute([$row['source_kind'],$row['source_id'],$v['platform'],(string)$v['customer_key'],(string)$v['conversation_id'],$row['id']]);
        }
        $namespace=ModeledConversionProfiles::assessmentNamespace();
        foreach([1=>41001,2=>41008,3=>41006,4=>41007,5=>41005,6=>41004]as$id=>$conversation){
            $platform=$conversation>=41007?'telegram':'max';
            $p->prepare("INSERT INTO modeled_conversion_profile_versions VALUES(?,'anytour',?,?,?,?,?,?,?)")
                ->execute([$id,$platform,(string)$conversation,(string)$conversation,$id===6?0:100000,$now-1000,in_array($id,[3,6],true)?str_repeat('f',64):$namespace,'{"private":"PRIVATE_PROFILE_NOT_COPIED"}']);
        }
        foreach([[1,41001,'max','71001','processed',333,'88001'],[2,41007,'telegram','71007','pending',500,null],[3,41008,'telegram','71008','pending',700,null]]as$r)
            $p->prepare("INSERT INTO max_channel_conversion_outbox VALUES(?,'anytour','98615635',?,?,?,'anytour_modeled_value_v1',?,? ,?,?,?)")
                ->execute([$r[0],$r[2],$r[1],$r[3],ModeledConversionValueContract::VERSION,$now-900,$r[4],$r[5],$r[6]]);
        foreach([[1,'71007','88007'],[2,'71007','99007'],[3,'71008','88008'],[4,'71008','88008']]as$r)
            $p->prepare("INSERT INTO advertising_visit_observations(id,project_key,source_key,platform,counter_id,external_user_id,identity_status,client_id,visit_at_unix,observed_at_unix,received_at_unix) VALUES(?,'anytour','tgmini','telegram','98615635',?,'verified',?,?,?,?)")
                ->execute([$r[0],$r[1],$r[2],$now-2000,$now-1900,$now-1900]);
        $snapshot=static function()use($p,$tables):string{
            $rows=[];foreach($tables as$t)$rows[$t]=$p->query('SELECT * FROM '.$t)->fetchAll(PDO::FETCH_ASSOC);
            return hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR));
        };
        $before=$snapshot();$auth=0;$budget=new ModeledConversionStatusBudget();
        $authorize=static function()use(&$auth,$budget):void{$auth++;$budget->consumeBytes(128);};
        if($mysql)$p->exec('SET SESSION TRANSACTION READ ONLY');else$p->exec('PRAGMA query_only=ON');
        try{$r=(new ModeledConversionIntakeCoverageReader($p,$authorize,$budget))->read($now);}
        finally{if($mysql)$p->exec('SET SESSION TRANSACTION READ WRITE');else$p->exec('PRAGMA query_only=OFF');}
        $a=$r['all']??[];
        $check('full native intake includes messages and customer events and detects the four independent pipeline gaps',
            ($r['available']??false)&&($r['coverage']['native_clock_scope_complete']??false)&&$a['inputs']===9&&$a['conversations']===8
            &&$a['wait_capture']===1&&$a['wait_index']===1&&$a['wait_profile']===1&&$a['wait_collector']===1
            &&$a['profiled']===5&&$a['collected']===4&&$a['late_capture']===1&&$a['late_profile']===2&&$a['late_collector']===1);
        $check('source indexing and fresh-profile coverage distinguish a missing snapshot from an older saved model',
            $a['captured']===7&&$a['indexed']===6&&$a['no_increment']===1&&$a['current_model_profiles']===4
            &&ModeledConversionDeliveryHealth::intakeFlags($r)===['intake_capture_stale','intake_profile_stale','intake_value_collection_stale']);
        $check('cohort value and Telegram identity coverage preserve native bindings and distinct ClientIDs without financial permission',
            $a['goal_events']===3&&$a['goal_value_rub']===1533.0&&$a['goal_processed']===1&&$a['goal_pending']===2
            &&$a['stored_identity_conversations']===1&&$r['by_platform']['telegram']['tg_unique']===1
            &&$r['by_platform']['telegram']['tg_ambiguous']===1&&$r['provider_requests']===0&&$r['conversion_uploads']===0);
        $safe=json_encode($r,JSON_THROW_ON_ERROR);
        $check('intake returns no native IDs source text private documents or query and leaves the whole source snapshot unchanged',
            !str_contains($safe,'PRIVATE_')&&!str_contains($safe,'41001')&&!str_contains($safe,'88008')
            &&!str_contains($safe,'payload_hash')&&!str_contains($safe,'source_id')&&$snapshot()===$before&&$auth>=5
            &&$budget->remainingBytes()>49152);
        $denied=false;$calls=0;
        try{(new ModeledConversionIntakeCoverageReader($p,static function()use(&$calls):void{if(++$calls===5)throw new DomainException('revoked');},new ModeledConversionStatusBudget()))->read($now);}
        catch(DomainException $expected){$denied=true;}
        $check('revocation after aggregate copy suppresses the complete native intake result',$denied&&$calls===5&&$snapshot()===$before);
        $small=new ModeledConversionStatusBudget();$small->consumeBytes(60000);
        $none=(new ModeledConversionIntakeCoverageReader($p,static function():void{},$small))->read($now);
        $check('intake low headroom stays unavailable rather than reporting successful zero coverage',
            !$none['available']&&$none['reason']==='budget_headroom'&&ModeledConversionDeliveryHealth::intakeFlags($none)===[]);
        // Fresh unresolved inputs wait inside the existing grace; a new native edit remains visible.
        $p->prepare("UPDATE messages SET metadata_json=? WHERE id=1002")->execute([json_encode(ConversationRecordClock::stamp([],$now-1),JSON_THROW_ON_ERROR)]);
        $fresh=(new ModeledConversionIntakeCoverageReader($p,static function():void{},new ModeledConversionStatusBudget()))->read($now);
        $check('fresh uncaptured input is visible immediately without a premature technical alarm',
            $fresh['available']&&$fresh['all']['wait_capture']===1&&$fresh['all']['late_capture']===0);
        $p->exec("UPDATE messages SET text='PRIVATE_EDIT_AFTER_CAPTURE' WHERE id=1006");
        $edited=(new ModeledConversionIntakeCoverageReader($p,static function():void{},new ModeledConversionStatusBudget()))->read($now);
        $check('an edited old input with no new recording clock is visible without aging the edit from its original creation',
            $edited['available']&&$edited['all']['wait_capture']===2&&$edited['all']['late_capture']===0);
        $p->prepare("UPDATE conversation_events SET actor_type='manager' WHERE id=1001")->execute();
        $roles=(new ModeledConversionIntakeCoverageReader($p,static function():void{},new ModeledConversionStatusBudget()))->read($now);
        $check('manager events and unmarked legacy clocks never enter the current customer-clock denominator',
            $roles['available']&&$roles['all']['inputs']===8&&$roles['all']['conversations']===8);
    }finally{
        if($mysql)$p->exec('SET SESSION TRANSACTION READ WRITE');else$p->exec('PRAGMA query_only=OFF');
        foreach(array_reverse($tables)as$table)$p->exec($mysql?'DROP TEMPORARY TABLE '.$table:'DROP TABLE temp.'.$table);
    }
}
