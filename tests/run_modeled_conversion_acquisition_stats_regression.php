<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/services/ModeledConversionAcquisitionStatsReader.php';

/** Shared SQLite/native-MySQL assertions. Call only with a fresh disposable fixture database. */
function modeledAcquisitionAssertions(PDO $pdo):int
{
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $int=$mysql?'BIGINT UNSIGNED':'INTEGER';$text=$mysql?'VARCHAR(64)':'TEXT';
    foreach([
        "customers(id $int PRIMARY KEY)",
        "customer_channels(id $int PRIMARY KEY,customer_id $int,project_key $text,channel $text,external_user_id $text,external_chat_id $text)",
        "conversations(id $int PRIMARY KEY,customer_id $int,customer_channel_id $int,project_key $text,channel $text,external_chat_id $text,is_test INTEGER,status $text)",
        "messages(id $int PRIMARY KEY,conversation_id $int,channel $text,direction $text,sender_type $text,text TEXT)",
        "conversation_events(id $int PRIMARY KEY,conversation_id $int,event_type $text,actor_type $text,actor_id VARCHAR(128),payload_json TEXT,created_at DATETIME)"
    ]as$ddl)$pdo->exec('CREATE TABLE '.$ddl);
    if($mysql){
        $pdo->exec(file_get_contents(dirname(__DIR__).'/migrations/065_modeled_conversion_stream.sql'));
        $pdo->exec(file_get_contents(dirname(__DIR__).'/migrations/066_modeled_conversion_profiles.sql'));
    }else{
        $pdo->exec('CREATE TABLE modeled_conversion_stream_versions(id INTEGER PRIMARY KEY,project_key TEXT,source_kind TEXT,source_id INTEGER,version_no INTEGER,revision_hash TEXT,observed_at_unix INTEGER,snapshot_json TEXT)');
        $pdo->exec('CREATE TABLE modeled_conversion_profile_versions(id INTEGER PRIMARY KEY,project_key TEXT,platform TEXT,customer_key TEXT,conversation_id TEXT,journal_cursor INTEGER,observed_at_unix INTEGER,input_sha256 TEXT,profile_json TEXT)');
        $pdo->exec('CREATE INDEX acquisition_scope_fixture ON modeled_conversion_profile_versions(project_key,platform,customer_key,conversation_id,id)');
    }
    $passed=0;
    $check=static function(bool $ok,string $label)use(&$passed):void{
        if(!$ok)throw new RuntimeException('acquisition fixture: '.$label);
        $passed++;echo 'PASS acquisition '.$label.PHP_EOL;
    };
    $now=strtotime('2026-11-04 12:00:00 UTC');
    $profileId=100;$journalId=1000;$messageId=10000;$eventId=100;
    $json=static fn(array $a):string=>json_encode($a,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    $iso=static fn(int $at):string=>gmdate('Y-m-d\TH:i:s\Z',$at);
    $exec=static function(string $sql,array $args=[])use($pdo):void{$q=$pdo->prepare($sql);$q->execute($args);};
    $reset=static function()use($pdo,&$profileId,&$journalId,&$messageId,&$eventId):void{
        foreach(['modeled_conversion_profile_versions','modeled_conversion_stream_versions','conversation_events','messages','conversations','customer_channels','customers']as$table)$pdo->exec('DELETE FROM '.$table);
        $profileId=100;$journalId=1000;$messageId=10000;$eventId=100;
    };
    $person=static function(int $customer,string $platform,?int $conversation=null,int $test=0,string $status='closed')use($pdo,$exec):int{
        $conversation??=$customer;
        $q=$pdo->prepare('SELECT COUNT(*) FROM customers WHERE id=?');$q->execute([$customer]);
        if(!(int)$q->fetchColumn())$exec('INSERT INTO customers(id) VALUES(?)',[$customer]);
        $external='900719925474'.(string)$customer;$chat=$platform==='max'?'-'.$external:$external;
        $exec('INSERT INTO customer_channels(id,customer_id,project_key,channel,external_user_id,external_chat_id) VALUES(?,?,?,?,?,?)',[$conversation,$customer,'anytour',$platform,$external,$chat]);
        $exec('INSERT INTO conversations(id,customer_id,customer_channel_id,project_key,channel,external_chat_id,is_test,status) VALUES(?,?,?,?,?,?,?,?)',[$conversation,$customer,$conversation,'anytour',$platform,$chat,$test,$status]);
        return$conversation;
    };
    $persist=static function(int $customer,string $platform,int $conversation,array $wrapper,int $observed)use($exec,$json,&$profileId,&$journalId):int{
        $id=++$profileId;
        $exec('INSERT INTO modeled_conversion_profile_versions(id,project_key,platform,customer_key,conversation_id,journal_cursor,observed_at_unix,input_sha256,profile_json) VALUES(?,?,?,?,?,?,?,?,?)',
            [$id,'anytour',$platform,(string)$customer,(string)$conversation,$journalId,$observed,str_repeat('a',64),$json($wrapper)]);
        return$id;
    };
    $packet=static function(int $customer,string $platform,int $conversation,array $moments,int $observed,?callable $alter=null)use($exec,$json,$iso,$persist,&$journalId,&$messageId):array{
        $subject=['project_key'=>'anytour','platform'=>$platform,'customer_key'=>(string)$customer,'conversation_id'=>(string)$conversation];
        $events=[];$evidence=[];$refs=[];
        foreach($moments as$m){
            $at=$m['at'];$available=$m['available']??$at+2;$version=$m['version']??1;
            $jid=(string)++$journalId;$mid=(string)++$messageId;$ref='journal:'.$jid;
            $snapshot=['source_kind'=>'message','source_id'=>$mid,'source_status'=>'present','project_key'=>'anytour',
                'platform'=>$platform,'customer_key'=>$customer,'conversation_id'=>$conversation,
                'source_timestamp_basis'=>'canonical_recording_unix_v1','source_created_at_unix'=>(($m['bad_clock']??false)?null:$at),
                'payload'=>['direction'=>'inbound','sender_type'=>'customer','text'=>'SYNTHETIC_PRIVATE_TEXT_DO_NOT_PROJECT']];
            $exec('INSERT INTO modeled_conversion_stream_versions(id,project_key,source_kind,source_id,version_no,revision_hash,observed_at_unix,snapshot_json) VALUES(?,?,?,?,?,?,?,?)',
                [$jid,'anytour','message',$mid,$version,str_repeat('b',64),$available,$json($snapshot)]);
            $exec('INSERT INTO messages(id,conversation_id,channel,direction,sender_type,text) VALUES(?,?,?,?,?,?)',
                [$mid,$conversation,$platform,'inbound','customer','SYNTHETIC_PRIVATE_TEXT_DO_NOT_PROJECT']);
            $r=['source'=>'message','id'=>$ref,'at'=>$iso($at),'available_at'=>$iso($available)];
            $evidence[]=['kind'=>'customer_message','id'=>'message:'.$ref,'subject'=>$subject,'at'=>$iso($at),'available_at'=>$iso($available),'references'=>[$r]];
            $events[]=['goal'=>$m['goal']??'substantive_interest','source_ref'=>$ref,'episode'=>1,'certainty'=>'rule_candidate','rule'=>'customer_trip_conditions'];
            $refs[]=['journal_id'=>$jid,'source_kind'=>'message','source_id'=>$mid,'source_version_no'=>$version,'observed_at'=>$iso($available),
                'progress_journal_id'=>$jid,'snapshot_sha256'=>str_repeat('c',64),'source_status'=>'present'];
        }
        $wrapper=['subject'=>$subject,'access_status'=>'allowed','source_fence_journal_id'=>(string)$journalId,
            'profile'=>['observation_input'=>['subject'=>$subject,'prediction_at'=>$iso($observed),'evidence'=>$evidence]],
            'source_references'=>$refs,'coverage'=>['window_limited'=>false,'source_history_complete'=>false],
            'contextual_signals'=>['version'=>'live_contextual_ordinal_v1','extractor_version'=>'ru_archive_contextual_v2',
                'input_cutoff_at'=>$iso($observed),'prediction_at'=>$iso($observed),'events'=>$events]];
        if($alter)$alter($wrapper);
        $persist($customer,$platform,$conversation,$wrapper,$observed);
        return$wrapper;
    };
    $join=static function(int $conversation,int $at,int $recorded,?string $actor=null,?callable $alter=null,string $type='channel_subscription_observed')use($exec,$json,$iso,&$eventId):void{
        $id=++$eventId;
        $payload=['version'=>1,'origin'=>'existing_membership_producer','source'=>'max_membership_history',
            'identity_scope'=>'same_chat_and_source','state'=>'subscribed','occurred_at_unix'=>$at,
            '_recorded_clock'=>['version'=>1,'basis'=>'server_recording_unix','unix'=>$recorded]];
        if($alter)$alter($payload);
        $exec('INSERT INTO conversation_events(id,conversation_id,event_type,actor_type,actor_id,payload_json,created_at) VALUES(?,?,?,?,?,?,?)',
            [$id,$conversation,$type,'system',$actor??'max-membership:'.$id,$json($payload),gmdate('Y-m-d H:i:s',$recorded)]);
    };
    $read=static fn(array $input=[]) :array=>(new ModeledConversionAcquisitionStatsReader($pdo,static function():void{}))->read($input,$now);
    $m=static fn(array $r):array=>$r['platforms']['max'];
    $normal=static function()use($reset,$person,$packet,$join,$now):void{
        $reset();$person(10,'max');$packet(10,'max',10,[['at'=>$now-3600]],$now-60);$join(10,$now-7200,$now-30);
    };
    $normal();$r=$read();
    $check($r['available']&&$m($r)['inquiries']===1&&$m($r)['valid_pairs']===1&&$m($r)['median_seconds']===3600,'exact saved MAX pair and late-observed descriptive join');
    $check($r['sources']['profiles_examined']===1&&$r['sources']['first_profile_at']===$iso($now-60)&&$r['sources']['last_profile_at']===$iso($now-60),'saved history dates and full profile count');
    $check(!str_contains(json_encode($r),'SYNTHETIC_PRIVATE_TEXT')&&!str_contains(json_encode($r),'90071992547410')&&!str_contains(json_encode($r),'journal:1001'),'aggregate output contains no text identity or source references');
    $check($m($r)['valid_pairs']+array_sum($m($r)['excluded'])===$m($r)['inquiries'],'exclusive MAX coverage partition');
    $reset();$person(11,'telegram');$packet(11,'telegram',11,[['at'=>$now-3600]],$now-60);$r=$read();
    $t=$r['platforms']['telegram'];
    $check($r['available']&&$t['inquiries']===1&&$t['valid_pairs']===0&&$t['coverage_pct']===null&&$t['median_seconds']===null
        &&$t['p90_seconds']===null&&$t['excluded']['membership_unavailable']===1&&$t['membership_status']==='unavailable_pending_bitrix','Telegram inquiries real while membership timing remains unavailable');
    $r=$read(['platform'=>'max']);$check($r['available']&&$m($r)['inquiries']===0&&$r['platforms']['telegram']['inquiries']===0,'platform filtering preserves both closed aggregate shapes');
    $reset();$person(10,'max');$packet(10,'max',10,[],$now-60);$r=$read();
    $check($r['available']&&$m($r)['inquiries']===0&&$m($r)['coverage_pct']===null&&$m($r)['median_seconds']===null,'empty or greeting-only saved profile is not a substantive inquiry');
    $normal();$exec('DELETE FROM conversation_events');$r=$read();
    $check($m($r)['inquiries']===1&&$m($r)['valid_pairs']===0&&$m($r)['excluded']['join_missing']===1,'missing membership stays unknown without losing inquiry denominator');
    $normal();$exec('DELETE FROM conversation_events');$join(10,$now-3500,$now-30);$r=$read();
    $check($m($r)['valid_pairs']===0&&$m($r)['excluded']['join_after_inquiry']===1&&$m($r)['median_seconds']===null,'join after inquiry is excluded without absolute or clamped interval');
    $normal();$join(10,$now-8000,$now-20);$r=$read();
    $check($m($r)['valid_pairs']===0&&$m($r)['excluded']['join_ambiguous']===1,'multiple observed join periods never choose arbitrary nearest join');
    $normal();$join(10,$now-8000,$now-20,null,static function(array &$p):void{$p['_recorded_clock']['unix']='invalid';});$r=$read();
    $check($m($r)['valid_pairs']===0&&$m($r)['excluded']['join_ambiguous']===1&&$r['sources']['missing_clock']>0,'valid join plus trusted corrupt second receipt cannot create false pair');
    $normal();$join(10,$now-8000,$now-20,'max-membership-conflict:synthetic');$r=$read();
    $check($m($r)['valid_pairs']===0&&$m($r)['excluded']['join_ambiguous']===1,'producer conflict receipt blocks interval');
    $normal();$join(10,$now-8000,$now-20,null,static function(array &$p):void{$p['origin']='untrusted';});$r=$read();
    $check($m($r)['valid_pairs']===1,'untrusted event does not manufacture or replace a subscription period');
    $normal();$person(10,'max',12,0,'closed');$r=$read();
    $check($m($r)['inquiries']===1&&$m($r)['valid_pairs']===0&&$m($r)['excluded']['identity_ambiguous']===1,'multiple current bound conversations including closed conversation are ambiguous');
    $normal();$exec("UPDATE customer_channels SET external_chat_id='-999' WHERE id=10");$r=$read();
    $check($m($r)['valid_pairs']===0&&$m($r)['excluded']['identity_ambiguous']===1,'MAX customer-channel chat must exactly equal canonical conversation chat');
    $normal();$join(10,$now-100,$now-20,null,null,'channel_retention_30m_observed');$r=$read();
    $check($m($r)['valid_pairs']===1&&$m($r)['median_seconds']===3600,'30-minute receipt is not substituted for join or exact leave time');
    $reset();$person(10,'max');$old=$packet(10,'max',10,[['at'=>strtotime('2026-10-20 12:00:00 UTC')]],$now-600);
    $new=$packet(10,'max',10,[['at'=>$now-3600]],$now-60);
    $r=$read(['period'=>7]);
    $check($r['available']&&$m($r)['inquiries']===0,'first semantic MIN spans immutable versions before selected period filter');
    $reset();$person(10,'max',10,0,'closed');$packet(10,'max',10,[['at'=>strtotime('2026-10-20 12:00:00 UTC')]],$now-600);
    $person(10,'max',12,0,'manager');$packet(10,'max',12,[['at'=>$now-3600]],$now-60);$r=$read(['period'=>7]);
    $check($m($r)['inquiries']===0,'closed earlier conversation prevents new conversation from becoming false first inquiry');
    $reset();$person(10,'max');$packet(10,'max',10,[['at'=>$now-3600,'version'=>2]],$now-60);$r=$read();
    $check($r['available']&&$m($r)['inquiries']===0&&$r['sources']['revised_sources']===1,'revised source cannot backdate first inquiry');
    $reset();$person(10,'max');$packet(10,'max',10,[['at'=>$now-3600,'bad_clock'=>true]],$now-60);$r=$read();
    $check($m($r)['inquiries']===0&&$r['sources']['missing_clock']===1,'unresolved source clock does not become zero elapsed time');
    $reset();$person(10,'max');$packet(10,'max',10,[['at'=>$now-3600]],$now-60,static function(array &$p):void{$p['profile']['observation_input']['evidence'][]=$p['profile']['observation_input']['evidence'][0];});$r=$read();
    $check($m($r)['inquiries']===0&&$r['sources']['unusable_semantic_candidates']===1,'duplicate evidence reference is ambiguous instead of JSON_SEARCH one');
    $reset();$person(10,'max');$packet(10,'max',10,[['at'=>$now-3600]],$now-60,static function(array &$p):void{$p['source_references'][]=$p['source_references'][0];});$r=$read();
    $check($m($r)['inquiries']===0,'duplicate source reference is not silently selected');
    $reset();$person(10,'max');$packet(10,'max',10,[['at'=>$now-3600,'goal'=>'substantiveXinterest']],$now-60);$r=$read();
    $check($m($r)['inquiries']===0,'JSON_SEARCH underscore wildcard cannot admit different goal name');
    $reset();$person(10,'max');$packet(10,'max',10,[['at'=>$now-3600,'goal'=>'SUBSTANTIVE_INTEREST']],$now-60);$r=$read();
    $check($m($r)['inquiries']===0,'case-insensitive native collation cannot admit different semantic goal');
    $reset();$person(10,'max');$packet(10,'max',10,[['at'=>$now-3600]],$now-60,static function(array &$p):void{
        $p['contextual_signals']['events']=array_merge(array_fill(0,60,['goal'=>'office_question','source_ref'=>'journal:999']),$p['contextual_signals']['events']);
        $p['semantic_prediction']=['status'=>'target_already_observed'];
    });$r=$read();
    $check($m($r)['inquiries']===1,'goal beyond first fifty events remains visible and known outcome does not erase historical inquiry');
    $reset();$person(10,'max');$packet(10,'max',10,[['at'=>$now-3600]],$now-60,static function(array &$p)use($iso,$now):void{$p['contextual_signals']['input_cutoff_at']=$iso($now-61);});$r=$read();
    $check($m($r)['inquiries']===1,'canonical extraction one second after cutoff remains admitted');
    $reset();$person(10,'max');$packet(10,'max',10,[['at'=>$now-3600]],$now-60,static function(array &$p)use($iso,$now):void{$p['contextual_signals']['input_cutoff_at']=$iso($now-59);});$r=$read();
    $check($m($r)['inquiries']===0,'prediction earlier than input cutoff is rejected');
    $normal();$exec('UPDATE messages SET conversation_id=999 WHERE id=10001');$r=$read();
    $check($m($r)['inquiries']===0,'historical profile cannot resurrect moved native message ownership');
    $normal();$exec('DELETE FROM messages WHERE id=10001');$r=$read();
    $check($m($r)['inquiries']===0,'deleted native source is not resurrected from stored historical profile');
    $normal();$packet(10,'max',10,[['at'=>$now-30,'goal'=>'office_question']],$now-10,static function(array &$p):void{$p['access_status']='denied';$p['profile']['observation_input']['evidence']=[];$p['contextual_signals']['events']=[];});$r=$read();
    $check($m($r)['inquiries']===0,'latest access denial blocks all historical profile facts');
    $reset();$person(2,'max');$packet(2,'max',2,[['at'=>$now-3600]],$now-60);$person(15,'max',15,1);$packet(15,'max',15,[['at'=>$now-3600]],$now-60);$r=$read();
    $check($m($r)['inquiries']===0,'owner demo and test conversations are excluded before cohort calculation');
    $reset();$person(10,'max');$packet(10,'max',10,[['at'=>$now+10]],$now+20);$r=$read();
    $check($m($r)['inquiries']===0,'as-of fence excludes future profile and evidence');
    $reset();$durations=[3599,3600,86400,604800,2592000,2592001];
    foreach($durations as$i=>$seconds){$c=20+$i;$person($c,'max');$packet($c,'max',$c,[['at'=>$now-3600]],$now-60);$join($c,$now-3600-$seconds,$now-30);}
    $r=$read();$v=$m($r);
    $check($v['inquiries']===6&&$v['valid_pairs']===6&&$v['coverage_pct']===100.0&&$v['median_seconds']===345600&&$v['p90_seconds']===2592001,'median nearest-rank p90 and valid pair coverage use actual intervals');
    $check(array_values($v['bins'])===[1,1,1,2,1]&&array_sum($v['bins'])===6,'elapsed bins have explicit hour day week and thirty-day boundaries');
    $reset();$person(10,'max');$packet(10,'max',10,[['at'=>$now-3600,'version'=>'1']],$now-60);$r=$read();
    $check($m($r)['inquiries']===0,'string source revision does not coerce into canonical integer version one');
    $reset();$person(10,'max');$packet(10,'max',10,[['at'=>$now-3600,'version'=>true]],$now-60);$r=$read();
    $check($m($r)['inquiries']===0,'boolean source revision does not coerce into canonical integer version one');
    $reset();$person(10,'max');$packet(10,'max',10,[['at'=>strtotime('2026-11-03 21:59:59 UTC')]],$now-60);
    $person(11,'max');$packet(11,'max',11,[['at'=>strtotime('2026-11-03 22:00:00 UTC')]],$now-60);
    $oldDay=$read(['date'=>'2026-11-03','period'=>7]);$newDay=$read(['date'=>'2026-11-04','period'=>7]);
    $check($m($oldDay)['inquiries']===1&&$m($newDay)['inquiries']===2,'Kaliningrad inclusive end day uses occurrence rather than profile or availability date');
    $normal();$calls=0;$countAuth=static function()use(&$calls):void{$calls++;};
    (new ModeledConversionAcquisitionStatsReader($pdo,$countAuth))->read([],$now);$last=$calls;
    $calls=0;$revoked=false;
    try{(new ModeledConversionAcquisitionStatsReader($pdo,static function()use(&$calls,$last):void{
        if(++$calls===$last)throw new RuntimeException('synthetic_revocation');
    }))->read([],$now);}catch(RuntimeException $e){$revoked=$e->getMessage()==='synthetic_revocation';}
    $check($revoked&&!$pdo->inTransaction(),'final authorization revocation denies entire aggregate and closes reader transaction');
    $before=[];foreach(['customers','customer_channels','conversations','messages','conversation_events','modeled_conversion_stream_versions','modeled_conversion_profile_versions']as$table)
        $before[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $read();$unchanged=true;foreach($before as$table=>$rows)$unchanged=$unchanged&&$rows===$pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $check($unchanged&&!$pdo->inTransaction(),'successful read changes no source rows and leaves no transaction');
    $pdo->beginTransaction();$r=$read();$check(!$r['available']&&$r['reason']==='outer_transaction'&&$pdo->inTransaction(),'outer transaction is rejected without closing caller transaction');$pdo->rollBack();
    foreach([['date'=>'2026-02-30'],['date'=>'2026-11-05'],['period'=>8],['period'=>'30'],['platform'=>'other']]as$invalid){
        $rejected=false;try{$read($invalid);}catch(InvalidArgumentException $e){$rejected=true;}$check($rejected,'invalid filter remains rejected '.json_encode($invalid));
    }
    $normal();$pdo->beginTransaction();
    for($batch=0;$batch<251;$batch++){
        $parts=[];$args=[];$size=min(200,50001-$batch*200);
        for($i=0;$i<$size;$i++){
            $id=100000+$batch*200+$i;$parts[]='(?,?,?,?,?,?,?,?,?)';
            array_push($args,$id,'anytour','max','10','10',$id,$now-1,str_repeat('d',64),'{"access_status":"allowed"}');
        }
        if($size)$exec('INSERT INTO modeled_conversion_profile_versions(id,project_key,platform,customer_key,conversation_id,journal_cursor,observed_at_unix,input_sha256,profile_json) VALUES '.implode(',',$parts),$args);
    }
    $pdo->commit();$r=$read();
    $check(!$r['available']&&$r['reason']==='record_limit'&&$r['platforms']===null,'full-history limit returns unavailable rather than silently complete recent sample');
    return$passed;
}

if(realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){
    $pdo=new PDO('sqlite::memory:');
    $passed=modeledAcquisitionAssertions($pdo);
    echo 'MODELED ACQUISITION STATS: '.$passed.' PASS'.PHP_EOL;
}
