<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/services/ModeledConversionAcquisitionStatsReader.php';
require_once __DIR__.'/run_advertising_visits_regression.php';

/** Shared SQLite/native-MySQL assertions. Call only with a fresh disposable fixture database. */
function modeledAcquisitionAssertions(PDO $pdo):int
{
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    advertisingVisitFixtureSchema($pdo);
    if(!$mysql){
        $pdo->exec('CREATE INDEX acquisition_entry_identity_fixture ON advertising_visit_observations(project_key,source_key,platform,counter_id,external_user_id,identity_status,observed_at_unix)');
        $pdo->exec('CREATE INDEX acquisition_entry_visit_fixture ON advertising_visit_observations(project_key,source_key,platform,counter_id,visit_id)');
    }
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
        foreach(['advertising_visit_observations','advertising_visit_cursors','modeled_conversion_profile_versions','modeled_conversion_stream_versions','conversation_events','messages','conversations','customer_channels','customers']as$table)$pdo->exec('DELETE FROM '.$table);
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
    $visit=static function(int $id,int $at,array $changes=[])use($exec,$now):void{
        $row=array_replace(['project_key'=>'anytour','source_key'=>'tgmini','platform'=>'telegram','counter_id'=>'98615635',
            'source_cursor'=>(string)$id,'visit_id'=>'entry-'.$id,'visit_at_unix'=>$at,'observed_at_unix'=>$at+1,
            'external_user_id'=>'90071992547411','identity_status'=>'verified','client_id'=>'17929400001234567890',
            'yclid'=>'18446744073709551614','campaign_id'=>'12','region_id'=>'213','payload_sha256'=>str_repeat('a',64),'received_at_unix'=>$now-5],$changes);
        $exec('INSERT INTO advertising_visit_observations('.implode(',',array_keys($row)).') VALUES('.implode(',',array_fill(0,count($row),'?')).')',array_values($row));
    };
    $tg=static function()use($reset,$person,$packet,$now):void{$reset();$person(11,'telegram');$packet(11,'telegram',11,[['at'=>$now-3600]],$now-60);};
    $entry=static fn(array $r):array=>$r['platforms']['telegram']['entry_context']['counts'];
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
    $check($entry($r)===['prior_observed'=>0,'ambiguous'=>0,'late_only'=>0,'no_data'=>1]
        &&$m($r)['entry_context']===['status'=>'not_supported','basis'=>'max_identity_mapping_not_visit','counts'=>null],
        'no Telegram observations stay no data while MAX mapping is not promoted to a visit');
    foreach([-1=>'prior_observed',0=>'prior_observed',1=>'late_only']as$offset=>$category){
        $tg();$visit(1,$now-3600+$offset);$r=$read();
        $check($r['available']&&$entry($r)[$category]===1&&array_sum($entry($r))===1,
            'entry offset '.$offset.' uses original journal message second, not later profile freeze');
    }
    $tg();$visit(1,$now-7200);$visit(2,$now-3601);$r=$read();
    $check($entry($r)['prior_observed']===1,'repeated entries for one ClientID remain observed context, not a unique-visit claim');
    $visit(3,$now-3601,['client_id'=>'17929400001234567891']);$r=$read();
    $check($entry($r)['ambiguous']===1&&$entry($r)['prior_observed']===0,'two prior ClientIDs are ambiguous rather than nearest or latest selection');
    $tg();$visit(1,$now-7200);$visit(2,$now-3000,['client_id'=>'17929400001234567891']);$r=$read();
    $check($entry($r)['prior_observed']===1,'later different ClientID does not rewrite earlier available entry context');
    $tg();$visit(1,$now-7200,['observed_at_unix'=>$now-10]);$r=$read();
    $check($entry($r)['prior_observed']===1,'late received history is descriptive as-of context using retained original entry time');
    $tg();$visit(1,$now-7200);for($i=2;$i<=130;$i++)$visit($i,$now-3000+$i);$r=$read();
    $check($entry($r)['prior_observed']===1&&$entry($r)['late_only']===0,'older prior entry is not hidden behind more than one page of later entries');
    $tg();$visit(1,$now-7200,['identity_status'=>'unverified']);
    $visit(2,$now-7200,['client_id'=>'']);$r=$read();
    $check($entry($r)['no_data']===1,'unverified raw IDs and a click ID without ClientID never substitute for admitted entry identity');
    $packet(11,'telegram',11,[['at'=>$now-3500]],$now-30,static function(array &$p):void{
        $p['client_id']='17929400001234567890';$p['yclid']='18446744073709551614';$p['visit_at_unix']=1790000000;
    });$r=$read();
    $check($entry($r)['no_data']===1&&!str_contains(json_encode($r),'17929400001234567890')
        &&!str_contains(json_encode($r),'18446744073709551614')&&!str_contains(json_encode($r),'90071992547411'),
        'raw supplied profile IDs neither manufacture context nor leave aggregate response');
    foreach(['project_key'=>'ANYTOUR','source_key'=>'TGMINI','platform'=>'Telegram','counter_id'=>'986156350','identity_status'=>'Verified','external_user_id'=>'90071992547412']as$field=>$value){
        $tg();$visit(1,$now-7200,[$field=>$value]);$r=$read();
        $check($r['available']&&$entry($r)['no_data']===1,'entry scope and verified identity are exact: '.$field);
    }
    $tg();$visit(1,$now-7200);$visit(2,$now-7200,['visit_id'=>'entry-1','external_user_id'=>'90071992547412']);$r=$read();
    $check($entry($r)['ambiguous']===1,'conflicting verified owners of the same entry cannot produce positive context');
    $tg();$exec("UPDATE customer_channels SET external_chat_id='different' WHERE id=11");$visit(1,$now-7200);$r=$read();
    $check($entry($r)['ambiguous']===1,'native chat identity conflict stays ambiguous rather than no data or prior entry');
    $tg();$person(11,'telegram',12);$packet(11,'telegram',12,[['at'=>$now-1800]],$now-30);
    $exec("UPDATE customer_channels SET external_user_id='90071992547412',external_chat_id='90071992547412' WHERE id=12");
    $exec("UPDATE conversations SET external_chat_id='90071992547412' WHERE id=12");
    $visit(1,$now-7200,['external_user_id'=>'90071992547412']);$r=$read();
    $check($entry($r)['no_data']===1,'later conversation identity is not substituted for earliest substantive message identity');
    $visit(2,$now-7200);$r=$read();$check($entry($r)['prior_observed']===1,'entry joins exact earliest conversation even when same customer has another native identity');
    foreach([
        ['visit_at_unix'=>1],['observed_at_unix'=>$now-7201],['observed_at_unix'=>$now+1],
        ['received_at_unix'=>$now+1],['received_at_unix'=>$now-8000],['client_id'=>'not-a-client-id'],
    ]as$bad){
        $tg();$visit(1,$now-7200,$bad);$r=$read();
        $check(!$r['available']&&$r['reason']==='entry_context_invalid'&&$r['platforms']===null,
            'invalid entry metadata fails visibly without false no-data totals: '.key($bad));
    }
    $tg();$pdo->exec('ALTER TABLE advertising_visit_observations RENAME TO acquisition_unavailable_visits');$r=$read();
    $check(!$r['available']&&$r['reason']==='storage_unavailable'&&$r['platforms']===null,'missing observation storage is unavailable, never zero observed entries');
    $pdo->exec('ALTER TABLE acquisition_unavailable_visits RENAME TO advertising_visit_observations');
    $reset();for($id=1000;$id<=1100;$id++){
        $person($id,'telegram');$packet($id,'telegram',$id,[['at'=>$now-3600]],$now-60);
        $visit($id,$now-7200,['external_user_id'=>'900719925474'.$id]);
    }
    $beforeVisits=$pdo->query('SELECT * FROM advertising_visit_observations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);$r=$read();
    $check($r['available']&&$entry($r)['prior_observed']===101&&array_sum($entry($r))===101&&$r['query_count']<35,
        'entry metadata queries batch more than one hundred native identities without N plus one requests');
    $check($beforeVisits===$pdo->query('SELECT * FROM advertising_visit_observations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)
        &&!$pdo->inTransaction(),'entry context projection changes no observation rows');
    $tg();$r=$read();
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
    $reset();$person(10,'max');$packet(10,'max',10,[['at'=>$now-3600]],$now-60,static function(array &$p):void{
        unset($p['profile']['observation_input']['subject']['platform']);
    });$r=$read();
    $check($m($r)['inquiries']===0&&$r['sources']['profiles_without_usable_packet']===1,'missing middle metadata path cannot shift multipath slots into an admitted identity');
    $reset();$person(10,'max');$packet(10,'max',10,[['at'=>$now-3600]],$now-60,static function(array &$p):void{
        $p['profile']['observation_input']['subject']['platform']=['private'=>'SYNTHETIC_PRIVATE_METADATA_DO_NOT_PROJECT'];
    });$r=$read();
    $check($m($r)['inquiries']===0&&$r['sources']['profiles_without_usable_packet']===1
        &&!str_contains(json_encode($r),'SYNTHETIC_PRIVATE_METADATA'),'nested wrong-type metadata is rejected and its private body never reaches aggregate output');
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
    $reset();$person(10,'max');$packet(10,'max',10,[['at'=>strtotime('2026-11-03T20:59:59Z')]],$now-60);
    $person(11,'max');$packet(11,'max',11,[['at'=>strtotime('2026-11-03T21:00:00Z')]],$now-60);
    $person(12,'max');$packet(12,'max',12,[['at'=>strtotime('2026-11-03T21:59:59Z')]],$now-60);
    $oldDay=$read(['date'=>'2026-11-03','period'=>7]);$newDay=$read(['date'=>'2026-11-04','period'=>7]);
    $check($m($oldDay)['inquiries']===1&&$m($newDay)['inquiries']===3,'Moscow inclusive end day uses occurrence rather than profile or availability date');
    $reset();$calendarReader=new ModeledConversionAcquisitionStatsReader($pdo,static function():void{});
    foreach([
        ['2026-11-03T20:59:59Z','2026-11-03'],['2026-11-03T21:00:00Z','2026-11-04'],['2026-11-03T21:30:00Z','2026-11-04'],
        ['2026-12-31T20:59:59Z','2026-12-31'],['2026-12-31T21:00:00Z','2027-01-01'],['2026-12-31T21:00:01Z','2027-01-01'],
    ]as[$instant,$date]){
        $at=strtotime($instant);$automatic=$calendarReader->read([],$at);$explicit=$calendarReader->read(['date'=>$date],$at);
        $check($automatic['available']&&$automatic['filters']['date']===$date&&$automatic['window']['timezone']==='Europe/Moscow'
            &&$explicit['available']&&$automatic['window']===$explicit['window'],'default and explicit date agree at Moscow midnight '.$instant);
    }
    $future=false;try{$calendarReader->read(['date'=>'2027-01-01'],strtotime('2026-12-31T20:59:59Z'));}catch(InvalidArgumentException $e){$future=true;}
    $check($future,'next Moscow day remains a future filter until midnight');
    foreach([7=>'2026-12-25T21:00:00Z',30=>'2026-12-02T21:00:00Z',90=>'2026-10-03T21:00:00Z']as$period=>$start){
        $reset();$end='2027-01-01T21:00:00Z';$calendarNow=strtotime('2027-01-02T12:00:00Z');
        foreach([strtotime($start)-1,strtotime($start),strtotime($end)-1,strtotime($end)]as$i=>$at){
            $id=20+$i;$person($id,'max');$packet($id,'max',$id,[['at'=>$at]],$calendarNow-60);
        }
        $saved=[];foreach(['customers','customer_channels','conversations','messages','conversation_events','modeled_conversion_stream_versions','modeled_conversion_profile_versions']as$table)
            $saved[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $r=$calendarReader->read(['date'=>'2027-01-01','period'=>$period],$calendarNow);
        $check($r['available']&&$r['window']===['from'=>$start,'until'=>$end,'timezone'=>'Europe/Moscow']&&$m($r)['inquiries']===2,
            'Moscow '.$period.' day window includes its first instant and excludes its next midnight');
        $unchanged=true;foreach($saved as$table=>$rows)$unchanged=$unchanged&&$rows===$pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $check($unchanged&&!$pdo->inTransaction(),'calendar projection preserves original UTC records and identities for '.$period.' days');
    }
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
    // Candidate capsule shape failures keep the same eligibility and diagnostic
    // counters. JSON null is a retained value, not a missing path or an array.
    $capsuleShapes=['missing_evidence','null_evidence','missing_sources','null_sources','missing_events','null_events'];
    if($mysql)$capsuleShapes=[...$capsuleShapes,'scalar_evidence','scalar_sources','scalar_events'];
    foreach($capsuleShapes as$shape){
        $reset();$person(10,'max');
        $packet(10,'max',10,[['at'=>$now-3600]],$now-60,static function(array &$p)use($shape):void{
            if($shape==='missing_evidence')unset($p['profile']['observation_input']['evidence']);
            if($shape==='null_evidence')$p['profile']['observation_input']['evidence']=null;
            if($shape==='scalar_evidence')$p['profile']['observation_input']['evidence']='not_an_array';
            if($shape==='missing_sources')unset($p['source_references']);
            if($shape==='null_sources')$p['source_references']=null;
            if($shape==='scalar_sources')$p['source_references']='not_an_array';
            if($shape==='missing_events')unset($p['contextual_signals']['events']);
            if($shape==='null_events')$p['contextual_signals']['events']=null;
            if($shape==='scalar_events')$p['contextual_signals']['events']='not_an_array';
        });
        $join(10,$now-7200,$now-30);$r=$read();
        $hasTarget=!str_ends_with($shape,'events');
        $check($r['available']&&$m($r)['inquiries']===0&&$r['sources']['profiles_examined']===1
            &&$r['sources']['unusable_semantic_candidates']===($hasTarget?1:0)
            &&$r['sources']['missing_clock']===0&&$r['sources']['revised_sources']===0
            &&!$pdo->inTransaction(),'missing/null/nonarray capsule input preserves rejection counters: '.$shape);
    }

    // Packing the saved source may not coerce its clock type, shift a missing
    // middle slot, or hide an already known source revision behind missing data.
    foreach(['missing_snapshot_direction','string_snapshot_clock','revised_missing_snapshot']as$shape){
        $reset();$person(10,'max');
        $packet(10,'max',10,[['at'=>$now-3600,'version'=>$shape==='revised_missing_snapshot'?2:1]],$now-60);
        $join(10,$now-7200,$now-30);
        $saved=$pdo->query('SELECT id,snapshot_json FROM modeled_conversion_stream_versions ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $snapshot=json_decode((string)$saved['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
        if($shape==='string_snapshot_clock')$snapshot['source_created_at_unix']=(string)$snapshot['source_created_at_unix'];
        else unset($snapshot['payload']['direction']);
        $exec('UPDATE modeled_conversion_stream_versions SET snapshot_json=? WHERE id=?',[$json($snapshot),$saved['id']]);
        $r=$read();$revised=$shape==='revised_missing_snapshot';
        $check($r['available']&&$m($r)['inquiries']===0&&$r['sources']['unusable_semantic_candidates']===0
            &&$r['sources']['revised_sources']===($revised?1:0)&&$r['sources']['missing_clock']===($revised?0:1)
            &&!$pdo->inTransaction(),'saved scalar capsule preserves strict types and revision/clock rejection precedence: '.$shape);
    }

    // JSON_TABLE JSON columns must retain the original JSON type. Required
    // identity metadata never becomes a string through SQL column coercion.
    foreach(['null'=>null,'number'=>17,'boolean'=>true,'array'=>['max']]as$type=>$value){
        $reset();$person(10,'max');
        $packet(10,'max',10,[['at'=>$now-3600]],$now-60,static function(array &$p)use($value):void{
            $p['profile']['observation_input']['subject']['platform']=$value;
        });
        $join(10,$now-7200,$now-30);$r=$read();
        $check($r['available']&&$m($r)['inquiries']===0&&$r['sources']['profiles_examined']===1
            &&$r['sources']['profiles_without_usable_packet']===1
            &&$r['sources']['unusable_semantic_candidates']===0,
            'required raw metadata type is not coerced by projection: '.$type);
    }
    $optionalShapes=['missing_limited','null_limited','array_limited','missing_events','null_events'];
    if($mysql)$optionalShapes[]='string_true_limited';
    foreach($optionalShapes as$shape){
        $reset();$person(10,'max');
        $packet(10,'max',10,[['at'=>$now-3600]],$now-60,static function(array &$p)use($shape):void{
            if($shape==='missing_limited')unset($p['coverage']['window_limited']);
            if($shape==='null_limited')$p['coverage']['window_limited']=null;
            if($shape==='array_limited')$p['coverage']['window_limited']=[true];
            if($shape==='string_true_limited')$p['coverage']['window_limited']='true';
            if($shape==='missing_events')unset($p['contextual_signals']['events']);
            if($shape==='null_events')$p['contextual_signals']['events']=null;
        });
        $join(10,$now-7200,$now-30);$r=$read();$hasGoal=!str_ends_with($shape,'events');
        $check($r['available']&&$r['sources']['profiles_examined']===1
            &&$r['sources']['profiles_without_usable_packet']===0
            &&$m($r)['inquiries']===($hasGoal?1:0)&&$m($r)['valid_pairs']===($hasGoal?1:0)
            &&$r['sources']['limited_profile_windows']===($shape==='string_true_limited'?1:0),
            'optional metadata absence/null/type preserves packet admission and goal search: '.$shape);
    }
    $reset();$person(10,'max');$tooManyGoals=[];
    for($i=0;$i<51;$i++)$tooManyGoals[]=['at'=>$now-3600+$i];
    $packet(10,'max',10,$tooManyGoals,$now-60);$join(10,$now-7200,$now-30);$r=$read();
    $check(!$r['available']&&$r['reason']==='source_shape_limit'&&$r['platforms']===null&&!$pdo->inTransaction(),
        'raw events array keeps all goal indices and enforces the existing fifty-target shape limit');

    // The live failure was below the work cap: latest allowed JSON was revisited
    // for every historical version. Build a real-shaped service-only history and
    // one latest 50-message packet, rather than testing only the >50k early exit.
    $reset();$person(10,'max');$historyRefs=[];
    $subject=['project_key'=>'anytour','platform'=>'max','customer_key'=>'10','conversation_id'=>'10'];
    $pdo->beginTransaction();
    for($i=0;$i<1999;$i++){
        $at=$now-14000+$i;$observed=$at+2;$jid=(string)++$journalId;$mid=(string)++$messageId;
        $snapshot=['source_kind'=>'message','source_id'=>$mid,'source_status'=>'present','project_key'=>'anytour',
            'platform'=>'max','customer_key'=>10,'conversation_id'=>10,'source_timestamp_basis'=>'canonical_recording_unix_v1',
            'source_created_at_unix'=>$at,'payload'=>['direction'=>'inbound','sender_type'=>'customer','text'=>'/synthetic_callback',
                'metadata_json'=>$json(['type'=>'callback','_recorded_clock'=>['version'=>1,'basis'=>'server_recording_unix','unix'=>$at]])]];
        $exec('INSERT INTO messages(id,conversation_id,channel,direction,sender_type,text) VALUES(?,?,?,?,?,?)',[$mid,10,'max','inbound','customer','/synthetic_callback']);
        $exec('INSERT INTO modeled_conversion_stream_versions(id,project_key,source_kind,source_id,version_no,revision_hash,observed_at_unix,snapshot_json) VALUES(?,?,?,?,?,?,?,?)',
            [$jid,'anytour','message',$mid,1,str_repeat('e',64),$observed,$json($snapshot)]);
        $historyRefs[]=['journal_id'=>$jid,'source_kind'=>'message','source_id'=>$mid,'source_version_no'=>1,
            'observed_at'=>$iso($observed),'progress_journal_id'=>$jid,'snapshot_sha256'=>str_repeat('f',64),'source_status'=>'present'];
        $historyRefs=array_slice($historyRefs,-50);
        $historical=['subject'=>$subject,'access_status'=>'allowed','source_fence_journal_id'=>$jid,
            'profile'=>['observation_input'=>['subject'=>$subject,'prediction_at'=>$iso($observed),'evidence'=>[]]],
            'source_references'=>$historyRefs,'coverage'=>['window_limited'=>$i>=50,'source_history_complete'=>false],
            'contextual_signals'=>['version'=>'live_contextual_ordinal_v1','extractor_version'=>'ru_archive_contextual_v2',
                'input_cutoff_at'=>$iso($observed),'prediction_at'=>$iso($observed),'events'=>[]]];
        $persist(10,'max',10,$historical,$observed);
    }
    $largeMoments=[];for($i=0;$i<50;$i++)$largeMoments[]=['at'=>$now-7200+$i,'goal'=>$i===0?'substantive_interest':'office_question'];
    $large=$packet(10,'max',10,$largeMoments,$now-60);$largeBytes=strlen($json($large));
    $join(10,$now-10800,$now-30);$pdo->commit();
    $check($largeBytes>32768&&count($large['source_references'])===50,'below-cap scale uses a genuine 50-source large latest profile');
    $scaleDigest=static function()use($pdo):string{
        $hash=hash_init('sha256');
        foreach(['modeled_conversion_profile_versions','modeled_conversion_stream_versions','messages','conversation_events']as$table){
            $statement=$pdo->query('SELECT * FROM '.$table.' ORDER BY id');
            while($row=$statement->fetch(PDO::FETCH_ASSOC))hash_update($hash,json_encode($row,JSON_THROW_ON_ERROR));
        }
        return hash_final($hash);
    };
    $scaleBefore=$scaleDigest();$scaleStarted=microtime(true);$r=$read();$scaleElapsed=(int)round((microtime(true)-$scaleStarted)*1000);
    echo 'ACQUISITION_SCALE_FIXTURE_TECHNICAL '.json_encode(['available'=>$r['available'],'reason'=>$r['reason'],
        'profiles_examined'=>$r['sources']['profiles_examined'],'query_count'=>$r['query_count'],
        'elapsed_ms'=>$scaleElapsed,'largest_profile_bytes'=>$largeBytes],JSON_THROW_ON_ERROR).PHP_EOL;
    $check($r['available']&&$r['sources']['profiles_examined']===2000&&$m($r)['inquiries']===1&&$m($r)['valid_pairs']===1
        &&$m($r)['median_seconds']===3600,'materialized latest admission completes full below-cap history and preserves first inquiry');
    $check($scaleDigest()===$scaleBefore&&!$pdo->inTransaction(),'large full-history read preserves all fixture rows and closes transaction');

    // Diverse latest scopes: recomputing all large latest packets for each page
    // must not grow with pages x people. All identities below are synthetic.
    $reset();$pdo->beginTransaction();$perPersonRefs=[];
    for($customer=100;$customer<350;$customer++)$person($customer,'max');
    for($round=0;$round<7;$round++)for($offset=0;$offset<250;$offset++){
        $customer=100+$offset;$at=$now-14000+$round*300+$offset;$observed=$at+2;
        $jid=(string)++$journalId;$mid=(string)++$messageId;
        $snapshot=['source_kind'=>'message','source_id'=>$mid,'source_status'=>'present','project_key'=>'anytour',
            'platform'=>'max','customer_key'=>$customer,'conversation_id'=>$customer,'source_timestamp_basis'=>'canonical_recording_unix_v1',
            'source_created_at_unix'=>$at,'payload'=>['direction'=>'inbound','sender_type'=>'customer','text'=>'/synthetic_callback',
                'metadata_json'=>$json(['type'=>'callback','_recorded_clock'=>['version'=>1,'basis'=>'server_recording_unix','unix'=>$at]])]];
        $exec('INSERT INTO messages(id,conversation_id,channel,direction,sender_type,text) VALUES(?,?,?,?,?,?)',[$mid,$customer,'max','inbound','customer','/synthetic_callback']);
        $exec('INSERT INTO modeled_conversion_stream_versions(id,project_key,source_kind,source_id,version_no,revision_hash,observed_at_unix,snapshot_json) VALUES(?,?,?,?,?,?,?,?)',
            [$jid,'anytour','message',$mid,1,str_repeat('e',64),$observed,$json($snapshot)]);
        $perPersonRefs[$customer][]=['journal_id'=>$jid,'source_kind'=>'message','source_id'=>$mid,'source_version_no'=>1,
            'observed_at'=>$iso($observed),'progress_journal_id'=>$jid,'snapshot_sha256'=>str_repeat('f',64),'source_status'=>'present'];
        $subject=['project_key'=>'anytour','platform'=>'max','customer_key'=>(string)$customer,'conversation_id'=>(string)$customer];
        $historical=['subject'=>$subject,'access_status'=>'allowed','source_fence_journal_id'=>$jid,
            'profile'=>['observation_input'=>['subject'=>$subject,'prediction_at'=>$iso($observed),'evidence'=>[]]],
            'source_references'=>$perPersonRefs[$customer],'coverage'=>['window_limited'=>false,'source_history_complete'=>false],
            'contextual_signals'=>['version'=>'live_contextual_ordinal_v1','extractor_version'=>'ru_archive_contextual_v2',
                'input_cutoff_at'=>$iso($observed),'prediction_at'=>$iso($observed),'events'=>[]]];
        $persist($customer,'max',$customer,$historical,$observed);
    }
    $minimumLatestBytes=PHP_INT_MAX;
    for($offset=0;$offset<250;$offset++){
        $customer=100+$offset;$moments=[];
        for($i=0;$i<50;$i++)$moments[]=['at'=>$now-7200+$offset+$i,'available'=>$now-2000+$offset,
            'goal'=>$i===0?'substantive_interest':'office_question'];
        $latest=$packet($customer,'max',$customer,$moments,$now-1000+$offset,static function(array &$p):void{$p['coverage']['window_limited']=true;});
        $minimumLatestBytes=min($minimumLatestBytes,strlen($json($latest)));
        $join($customer,$now-10800+$offset,$now-30);
    }
    $pdo->commit();$diverseBefore=$scaleDigest();$diverseStarted=microtime(true);$r=$read(['period'=>7]);
    $diverseElapsed=(int)round((microtime(true)-$diverseStarted)*1000);
    echo 'ACQUISITION_DIVERSE_SCALE_FIXTURE_TECHNICAL '.json_encode(['available'=>$r['available'],'reason'=>$r['reason'],
        'profiles_examined'=>$r['sources']['profiles_examined'],'query_count'=>$r['query_count'],'elapsed_ms'=>$diverseElapsed,
        'fixture_customers'=>250,'smallest_latest_profile_bytes'=>$minimumLatestBytes],JSON_THROW_ON_ERROR).PHP_EOL;
    $check($r['available']&&$minimumLatestBytes>32768&&$r['sources']['profiles_examined']===2000
        &&$m($r)['inquiries']===250&&$m($r)['valid_pairs']===250&&$m($r)['median_seconds']===3600&&$m($r)['p90_seconds']===3600
        &&$m($r)['valid_pairs']+array_sum($m($r)['excluded'])===250,'one admission handles 250 diverse large latest scopes and all historical versions');
    $check($scaleDigest()===$diverseBefore&&!$pdo->inTransaction()
        &&!str_contains(json_encode($r),'SYNTHETIC_PRIVATE_TEXT'),'diverse full-history read preserves rows and returns no private body');

    if($mysql){
        // Real MEDIUMTEXT profiles can carry irrelevant large private sections.
        // Fifty substantive targets per profile must not copy the whole document
        // through every target/ref/search materialization. SQLite stays unchanged.
        $reset();$pdo->beginTransaction();$minimumWideBytes=PHP_INT_MAX;
        $wideMarker='SYNTHETIC_IRRELEVANT_PRIVATE_PADDING_NOT_PROJECTED_';
        $widePadding=$wideMarker.str_repeat('w',2*1024*1024);
        for($offset=0;$offset<16;$offset++){
            $customer=700+$offset;$person($customer,'max');$moments=[];
            for($i=0;$i<50;$i++)$moments[]=['at'=>$now-7200+$offset+$i];
            $wideProfile=$packet($customer,'max',$customer,$moments,$now-60,
                static function(array &$p)use($widePadding):void{$p['unrelated_private_padding']=$widePadding;});
            $minimumWideBytes=min($minimumWideBytes,strlen($json($wideProfile)));
            $join($customer,$now-10800+$offset,$now-30);
        }
        $pdo->commit();unset($wideProfile,$widePadding);
        $wideBefore=$scaleDigest();$wideStarted=microtime(true);$r=$read(['period'=>7]);
        $wideElapsed=(int)round((microtime(true)-$wideStarted)*1000);
        echo 'ACQUISITION_COMPACT_CAPSULE_FIXTURE_TECHNICAL '.json_encode([
            'available'=>$r['available'],'reason'=>$r['reason'],'profiles_examined'=>$r['sources']['profiles_examined'],
            'query_count'=>$r['query_count'],'elapsed_ms'=>$wideElapsed,'smallest_profile_bytes'=>$minimumWideBytes
        ],JSON_THROW_ON_ERROR).PHP_EOL;
        $check($r['available']&&$minimumWideBytes>2*1024*1024&&$r['sources']['profiles_examined']===16
            &&$m($r)['inquiries']===16&&$m($r)['valid_pairs']===16&&$m($r)['median_seconds']===3600
            &&$m($r)['p90_seconds']===3600&&array_sum($m($r)['bins'])===16
            &&$m($r)['valid_pairs']+array_sum($m($r)['excluded'])===16
            &&$wideElapsed<=5000,
            'wide native profiles and repeated semantic targets preserve exact pairs inside the unchanged read budget');
        $check($scaleDigest()===$wideBefore&&!$pdo->inTransaction()
            &&!str_contains(json_encode($r,JSON_THROW_ON_ERROR),$wideMarker)
            &&!str_contains(json_encode($r,JSON_THROW_ON_ERROR),'SYNTHETIC_PRIVATE_TEXT'),
            'wide native candidate read preserves all source rows and returns no irrelevant private sections');
    }

    // Metadata pages must retain malformed rows rather than shorten a 128-row
    // page and hide a later valid first inquiry. None of these clocks is private.
    $reset();$person(40,'max');$pdo->beginTransaction();
    for($i=0;$i<130;$i++){
        $packet(40,'max',40,[['at'=>$now-10000+$i,'goal'=>$i===129?'substantive_interest':'office_question']],$now-8000+$i,
            static function(array &$p)use($i):void{
                if($i===10)$p['profile']['observation_input']['subject']['platform']=null;
                if($i===64)$p['profile']['observation_input']['subject']['platform']=['max'];
                if($i===127)$p['profile']['observation_input']['subject']['platform']=17;
            });
        if($i===70)$exec('UPDATE modeled_conversion_profile_versions SET profile_json=? WHERE id=?',['null',$profileId]);
    }
    $join(40,$now-10000+129-3600,$now-30);$pdo->commit();
    $boundaryBefore=$scaleDigest();$r=$read(['period'=>7]);
    $check($r['available']&&$r['sources']['profiles_examined']===130
        &&$r['sources']['profiles_without_usable_packet']===4&&$m($r)['inquiries']===1
        &&$m($r)['valid_pairs']===1&&$m($r)['median_seconds']===3600,
        'raw scalar metadata keeps malformed middle packets and reaches the first inquiry beyond page 128');
    $check($scaleDigest()===$boundaryBefore&&!$pdo->inTransaction(),
        'metadata page-boundary scan preserves all fixture sources and closes its read transaction');

    // Sixty-five conversations force a person's histories across a 64-scope
    // boundary regardless of ordering. They share the one canonical channel row.
    $reset();$person(600,'max',6000);
    $q=$pdo->query('SELECT external_chat_id FROM customer_channels WHERE id=6000');$sharedChat=(string)$q->fetchColumn();
    for($i=1;$i<65;$i++)$exec('INSERT INTO conversations(id,customer_id,customer_channel_id,project_key,channel,external_chat_id,is_test,status) VALUES(?,?,?,?,?,?,?,?)',
        [6000+$i,600,6000,'anytour','max',$sharedChat,0,'closed']);
    for($i=0;$i<65;$i++)$packet(600,'max',6000+$i,[['at'=>$i===0?$now-20*86400:$now-3600+$i]],$now-60);
    $reuseReader=new ModeledConversionAcquisitionStatsReader($pdo,static function():void{});
    $r=$reuseReader->read(['period'=>7],$now);
    $check($r['available']&&$r['sources']['profiles_examined']===65&&$m($r)['inquiries']===0,'first inquiry MIN is global across more than 64 admitted scopes before date filter');
    $packet(600,'max',6000,[['at'=>$now-30,'goal'=>'office_question']],$now-10,static function(array &$p):void{
        $p['access_status']='denied';$p['profile']['observation_input']['evidence']=[];$p['contextual_signals']['events']=[];
    });
    $r=$reuseReader->read(['period'=>7],$now);
    $check($r['available']&&$r['sources']['profiles_examined']===64&&$m($r)['inquiries']===1&&$m($r)['valid_pairs']===0
        &&$m($r)['excluded']['identity_ambiguous']===1&&!$pdo->inTransaction(),'reused reader rebuilds latest access admission without resurrecting denied cross-batch history');

    $tg();$pdo->beginTransaction();
    for($id=1;$id<=50001;$id++)$visit($id,$now-7200);
    $pdo->commit();$capStarted=microtime(true);$r=$read();
    echo 'ACQUISITION_ENTRY_CAP_FIXTURE_TECHNICAL '.json_encode(['reason'=>$r['reason'],
        'query_count'=>$r['query_count'],'elapsed_ms'=>(int)round((microtime(true)-$capStarted)*1000)],JSON_THROW_ON_ERROR).PHP_EOL;
    $check(!$r['available']&&in_array($r['reason'],['record_limit','byte_limit','time_limit'],true)&&$r['platforms']===null,
        'capped observation history cannot publish a partial prior-entry or zero-data partition');

    $normal();$pdo->beginTransaction();
    for($batch=0;$batch<251;$batch++){
        $parts=[];$args=[];$size=min(200,50001-$batch*200);
        for($i=0;$i<$size;$i++){
            $id=100000+$batch*200+$i;$parts[]='(?,?,?,?,?,?,?,?,?)';
            array_push($args,$id,'anytour','max','10','10',$id,$now-1,str_repeat('d',64),'{"access_status":"allowed"}');
        }
        if($size)$exec('INSERT INTO modeled_conversion_profile_versions(id,project_key,platform,customer_key,conversation_id,journal_cursor,observed_at_unix,input_sha256,profile_json) VALUES '.implode(',',$parts),$args);
    }
    $pdo->commit();$capStarted=microtime(true);$r=$read();
    echo 'ACQUISITION_CAP_FIXTURE_TECHNICAL '.json_encode(['reason'=>$r['reason'],
        'query_count'=>$r['query_count'],'elapsed_ms'=>(int)round((microtime(true)-$capStarted)*1000)],JSON_THROW_ON_ERROR).PHP_EOL;
    $check(!$r['available']&&$r['reason']==='record_limit'&&$r['platforms']===null,'full-history limit returns unavailable rather than silently complete recent sample');
    return$passed;
}

if(realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){
    $pdo=new PDO('sqlite::memory:');
    $passed=modeledAcquisitionAssertions($pdo);
    echo 'MODELED ACQUISITION STATS: '.$passed.' PASS'.PHP_EOL;
}
