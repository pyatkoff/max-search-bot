<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once dirname(__DIR__).'/services/ArchiveResearchReadService.php';
require_once __DIR__.'/run_advertising_visits_regression.php';

/** Shared required archive fixture; retain SQL structure without logging parameter values. */
final class TelegramBusinessIdentityTraceStatement extends PDOStatement
{
    public static array $queries=[];
    public static $duringExecute=null;
    protected function __construct() {}
    public function execute(?array $params=null):bool
    {
        self::$queries[]=$this->queryString;
        if (is_callable(self::$duringExecute)) (self::$duringExecute)($this->queryString);
        return parent::execute($params);
    }
}

/** Caller must supply a disposable fixture database, never an application database. */
function telegramBusinessIdentityAssertions(PDO $pdo,?callable $fixtureConsumer=null):int
{
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    if ($mysql && preg_match('/\Amaxbot_prepared_test(?:_[A-Za-z0-9_]+)?\z/D',(string)$pdo->query('SELECT DATABASE()')->fetchColumn())!==1)
        throw new RuntimeException('unsafe_fixture_database');
    $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $oldStatementClass=$pdo->getAttribute(PDO::ATTR_STATEMENT_CLASS);
    $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[TelegramBusinessIdentityTraceStatement::class]);
    $tables=['telegram_dialogue_messages','telegram_archive_accounts','telegram_dialogue_connections',
        'customer_channels','conversations','projects','advertising_visit_observations','advertising_visit_cursors',
        'advertising_signals','max_channel_conversion_outbox'];
    $drop=static function()use($pdo,$tables):void { foreach($tables as$table)$pdo->exec('DROP TABLE IF EXISTS '.$table); };
    $drop();
    $passed=0;
    $check=static function(bool $ok,string $label)use(&$passed):void {
        if (!$ok) throw new RuntimeException('business_identity_check:'.$label);
        $passed++; echo 'PASS business identity '.$label.PHP_EOL;
    };
    $reject=static function(callable $call,string $reason,string $label)use($check):void {
        try { $call(); $check(false,$label); }
        catch(ArchiveResearchReadException $e) {
            $check($e->reason===$reason && $e->getMessage()==='archive_research_'.$reason,$label);
        }
    };
    $run=static function(string $sql,array $args=[])use($pdo):void { $q=$pdo->prepare($sql); $q->execute($args); };
    $json=static fn(array $value):string=>json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    $cutoff=(int)strtotime('2026-11-26 11:05:00 UTC');
    $now=$cutoff+3600; $messageAt=$cutoff-60;
    $account='600000010001'; $secondAccount='600000010002'; $peer='700000020001'; $otherPeer='700000020002';
    $cid='17929400001234567890'; $click='18446744073709551614';
    try {
        $integer=$mysql?'BIGINT':'INTEGER';
        $scope=$mysql?'VARCHAR(96)':'TEXT COLLATE NOCASE';
        $text=$mysql?'LONGTEXT':'TEXT';
        foreach([
            "projects(id $integer PRIMARY KEY,project_key $scope,is_active INTEGER)",
            "conversations(id $integer PRIMARY KEY,customer_id $integer,customer_channel_id $integer,project_key $scope,channel $scope,external_chat_id $scope,is_test INTEGER)",
            "customer_channels(id $integer PRIMARY KEY,customer_id $integer,project_key $scope,channel $scope,external_user_id $scope,external_chat_id $scope)",
            "telegram_archive_accounts(project_key $scope,bot_key $scope,account_id $integer,connection_id $scope,approved INTEGER,provider_enabled INTEGER,manager_id $integer)",
            "telegram_dialogue_connections(project_key $scope,bot_key $scope,connection_id $scope,account_id $integer,is_enabled INTEGER)",
            "telegram_dialogue_messages(id $integer PRIMARY KEY,project_key $scope,account_id $integer,chat_id $integer,message_id $integer,version_at $integer,source_kind $scope,is_deleted INTEGER,record_json $text)",
            "advertising_signals(id $integer PRIMARY KEY,project_key $scope,source_key $scope,platform $scope,counter_id $scope,conversation_id $scope,external_user_id $scope,signal_key $scope,source_type $scope,source_id $scope,occurred_at_unix $integer,captured_at_unix $integer)",
            "max_channel_conversion_outbox(id $integer PRIMARY KEY,project_key $scope,platform $scope,counter_id $scope,conversation_id $integer,external_user_id $scope,identity_source_key $scope,source_signal_id $integer,target $scope,event_unix $integer,state $scope)"
        ]as$ddl)$pdo->exec('CREATE TABLE '.$ddl);
        $pdo->exec('CREATE UNIQUE INDEX uq_telegram_dialogue_message ON telegram_dialogue_messages(project_key,account_id,chat_id,message_id)');
        advertisingVisitFixtureSchema($pdo);
        $pdo->exec('CREATE INDEX business_identity_fixture ON advertising_visit_observations(project_key,source_key,platform,counter_id,external_user_id,identity_status,observed_at_unix)');
        $pdo->exec('CREATE INDEX business_visit_fixture ON advertising_visit_observations(project_key,source_key,platform,counter_id,visit_id)');

        $reset=static function()use($pdo,$tables,$run,$account,$secondAccount):void {
            TelegramBusinessIdentityTraceStatement::$duringExecute=null;
            foreach($tables as$table)$pdo->exec('DELETE FROM '.$table);
            $run("INSERT INTO projects VALUES(1,'anytour',1),(2,'foreign',1)");
            foreach([
                [$account,'FixtureBotA','fixture-connection-a',1,1,'anytour'],
                [$secondAccount,'FixtureBotB','fixture-connection-b',1,1,'anytour'],
                ['600000010003','FixtureDenied','fixture-connection-c',0,1,'anytour'],
                ['600000010004','FixtureDisconnected','fixture-connection-d',1,0,'anytour'],
                ['600000010005','FixtureForeign','fixture-connection-e',1,1,'foreign']
            ]as[$a,$bot,$connection,$approved,$enabled,$project]){
                $run('INSERT INTO telegram_archive_accounts VALUES(?,?,?,?,?,1,NULL)',[$project,$bot,$a,$connection,$approved]);
                $run('INSERT INTO telegram_dialogue_connections VALUES(?,?,?,?,?)',[$project,$bot,$connection,$a,$enabled]);
            }
        };
        $message=static function(int $id,array $changes=[],array $columns=[])use($run,$json,$account,$peer,$messageAt):void {
            $r=array_replace(['account_id'=>$account,'chat_id'=>$peer,'message_id'=>(string)(90000000+$id),
                'sender_id'=>$peer,'sender_type'=>'customer','direction'=>'inbound','created_at'=>$messageAt,
                'version_at'=>$messageAt,'source_kind'=>'business_message','bot_key'=>'FixtureBotA',
                'connection_id'=>'fixture-connection-a','is_deleted'=>false,'is_forwarded'=>false,
                'text'=>'SYNTHETIC_PRIVATE_CONTENT_MUST_NOT_BE_FETCHED','attachments'=>[],'links'=>[]],$changes);
            $c=array_replace(['project_key'=>'anytour','account_id'=>$r['account_id'],'chat_id'=>$r['chat_id'],
                'message_id'=>$r['message_id'],'version_at'=>$r['version_at'],'source_kind'=>$r['source_kind'],
                'is_deleted'=>(int)$r['is_deleted']],$columns);
            $run('INSERT INTO telegram_dialogue_messages VALUES(?,?,?,?,?,?,?,?,?)',[$id,$c['project_key'],
                $c['account_id'],$c['chat_id'],$c['message_id'],$c['version_at'],$c['source_kind'],$c['is_deleted'],$json($r)]);
        };
        $visit=static function(int $id,array $changes=[])use($run,$peer,$messageAt,$cid,$click):void {
            $r=array_replace(['project_key'=>'anytour','source_key'=>'tgmini','platform'=>'telegram','counter_id'=>'98615635',
                'source_cursor'=>(string)$id,'visit_id'=>'fixture-entry-'.$id,'visit_at_unix'=>$messageAt-300,
                'observed_at_unix'=>$messageAt-299,'external_user_id'=>$peer,'identity_status'=>'verified',
                'client_id'=>$cid,'yclid'=>$click,'campaign_id'=>'12','region_id'=>'213',
                'payload_sha256'=>str_repeat('a',64),'received_at_unix'=>$messageAt-298],$changes);
            $run('INSERT INTO advertising_visit_observations('.implode(',',array_keys($r)).') VALUES('.implode(',',array_fill(0,count($r),'?')).')',array_values($r));
        };
        $native=static function(int $id,string $who,int $test=0,array $changes=[])use($run):void {
            $r=array_replace(['project_key'=>'anytour','channel'=>'telegram','external_user_id'=>$who,'external_chat_id'=>$who],$changes);
            $run('INSERT INTO customer_channels VALUES(?,?,?,?,?,?)',[$id,$id,$r['project_key'],$r['channel'],$r['external_user_id'],$r['external_chat_id']]);
            $run('INSERT INTO conversations VALUES(?,?,?,?,?,?,?)',[$id,$id,$id,$r['project_key'],$r['channel'],$r['external_chat_id'],$test]);
        };
        $read=static function(?string $fence=null,?callable $authority=null,?int $at=null)use($pdo,$cutoff,$now):array {
            $fence??=(string)$pdo->query('SELECT COALESCE(MAX(id),0) FROM telegram_dialogue_messages')->fetchColumn();
            return(new ArchiveResearchReadService($pdo,static fn()=>$now,null,true))
                ->operatorBusinessIdentitySummary($authority??static function():void{},$fence,$at??$cutoff);
        };
        $windowRead=static function(?string $suffix=null,?string $fence=null,?callable $authority=null,?int $from=null,?int $at=null)use($pdo,$cutoff,$now):array {
            $fence??=(string)$pdo->query('SELECT COALESCE(MAX(id),0) FROM telegram_dialogue_messages')->fetchColumn();
            return(new ArchiveResearchReadService($pdo,static fn()=>$now,null,true))
                ->businessIdentityWindow($authority??static function():void{},$fence,$from??$cutoff-7*86400,$at??$cutoff,$suffix);
        };
        $fingerprint=static function()use($pdo,$tables,$json):string {
            $rows=[]; foreach($tables as$table)$rows[$table]=$pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
            return hash('sha256',$json($rows));
        };
        $private=static function(array $r)use($check,$json,$account,$secondAccount,$peer,$otherPeer,$cid,$click):void {
            $out=$json($r);
            foreach([$account,$secondAccount,$peer,$otherPeer,$cid,$click,'SYNTHETIC_PRIVATE_CONTENT','fixture-entry-',
                'fixture-connection-','FixtureBot','"account_id"','"chat_id"','"external_user_id"','"client_id"','"yclid"']as$needle)
                if(str_contains($out,$needle))throw new RuntimeException('business_identity_check:aggregate_privacy');
            $check(true,'aggregate_contains_no_source_identity_or_content');
        };

        // Include-safe bridge for another real adapter test using this disposable source.
        if($fixtureConsumer!==null) {
            $reset();
            $fixtureConsumer(['pdo'=>$pdo,'message'=>$message,'visit'=>$visit,'native'=>$native,'run'=>$run,
                'reset'=>$reset,'read'=>$read,'window_read'=>$windowRead,'cutoff'=>$cutoff,'now'=>$now,'message_at'=>$messageAt,
                'account'=>$account,'second_account'=>$secondAccount,'peer'=>$peer,'other_peer'=>$otherPeer,
                'client_id'=>$cid,'click_id'=>$click]);
            return 0;
        }

        $metric=static fn(array $r,string $key,string $unit='events'):int=>$r['identities'][$unit][$key];
        $counts=static function(array $r)use($check):void {
            $c=$r['counts']; $selected=$c['selected_records']; unset($c['selected_records']);
            $check(array_sum($c)===$selected,'selected_records_have_one_admission_outcome');
        };

        // One exact prior visit. Text stays in the synthetic database even though it exists.
        $reset(); $message(10); $visit(1);
        $before=$fingerprint(); $authCalls=0; $writeBlocked=false;
        TelegramBusinessIdentityTraceStatement::$queries=[];
        TelegramBusinessIdentityTraceStatement::$duringExecute=static function(string $sql)use($pdo,&$writeBlocked):void {
            if(!$writeBlocked && $pdo->inTransaction() && str_contains($sql,'FROM telegram_dialogue_messages')) {
                try { $pdo->exec("INSERT INTO projects VALUES(90,'must_not_write',1)"); }
                catch(PDOException $e) { $writeBlocked=true; }
            }
        };
        $r=$read(null,static function()use($pdo,&$authCalls):void {
            if($pdo->inTransaction())throw new RuntimeException('authority_inside_old_snapshot');
            $authCalls++;
        });
        TelegramBusinessIdentityTraceStatement::$duringExecute=null;
        $queries=TelegramBusinessIdentityTraceStatement::$queries;
        $check($r['ok']===true && $r['schema_version']===1 && $r['operation']==='business_identity_summary','fixed_report_contract');
        $check($r['counts']['selected_records']===1 && $r['counts']['verified_inbound_records']===1,'one_verified_customer_message');
        $check($metric($r,'prior_visit')===1 && $metric($r,'prior_visit_21d')===1
            && $metric($r,'prior_visit_available_at_message')===1,'prior_visit_uses_message_clock');
        $check($metric($r,'verified_prior_match')===1 && $metric($r,'verified_prior_match_21d')===1,
            'verified_prior_match_requires_valid_unconflicted_history');
        $check($r['identities']['candidate_history_complete']===true && $r['identities']['candidate_observation_rows']===1
            && $r['identities']['candidate_history_scope']==='current_saved_rows_for_selected_peers_not_full_source_history',
            'candidate_completeness_applies_only_to_current_selected_peer_history');
        $check($metric($r,'unique_client_id')===1 && $metric($r,'click_present')===1
            && $metric($r,'same_observation_pair')===1,'same_verified_observation_proves_pair');
        $check($r['distinct']['account_chat_pairs']===1 && $r['distinct']['telegram_peers']===1,'explicit_distinct_denominators');
        $check($r['coverage']['brand']==='unknown' && $r['coverage']['legacy_non_test']==='unknown'
            && $r['coverage']['history_complete']===false && $r['selection']['historical_snapshot']===false,
            'project_admission_is_not_brand_or_historical_completeness');
        $check($authCalls===2 && !$pdo->inTransaction() && $writeBlocked,'real_readonly_snapshot_and_fresh_authority');
        $check($before===$fingerprint(),'source_tables_unchanged');
        $check(!array_filter($queries,static fn(string $q):bool=>preg_match('/(?:SELECT|,)\s*m\.record_json\s*(?:,|AS\b|FROM\b)/i',$q)===1
                || preg_match('/\$\.(?:text|caption|attachments|links)/i',$q)===1),'no_raw_record_or_message_content_selected');
        $private($r); $counts($r);

        $message(20);
        $message(30,['account_id'=>$secondAccount,'bot_key'=>'FixtureBotB','connection_id'=>'fixture-connection-b']);
        $r=$read();
        $check($r['counts']['verified_inbound_records']===3 && $r['distinct']['account_chat_pairs']===2
            && $r['distinct']['telegram_peers']===1,'repeated_messages_and_accounts_do_not_invent_people');
        $check($metric($r,'same_observation_pair')===3 && $metric($r,'same_observation_pair','unique_peers')===1,
            'events_and_unique_peer_metrics_have_separate_denominators');

        // Scope is applied before the finite newest-record selection.
        $reset(); $message(10); $visit(1);
        $native(1,'700000029999');
        $message(20,['chat_id'=>'700000029999','sender_id'=>'700000029999']);
        $message(30,['chat_id'=>'777000','sender_id'=>'777000']);
        $message(40,['source_kind'=>'desktop_import']);
        $message(50,['account_id'=>'600000010003','bot_key'=>'FixtureDenied','connection_id'=>'fixture-connection-c']);
        $message(60,['account_id'=>'600000010004','bot_key'=>'FixtureDisconnected','connection_id'=>'fixture-connection-d']);
        $message(70,['account_id'=>'600000010005','bot_key'=>'FixtureForeign','connection_id'=>'fixture-connection-e'],['project_key'=>'foreign']);
        $r=$read('40');
        $check($r['counts']['selected_records']===1 && $r['counts']['verified_inbound_records']===1,
            'owner_service_desktop_denied_and_foreign_sources_are_outside_cohort');
        $private($r);

        $reset(); $message(10); $native(1001,$peer,1); $visit(1); $r=$read();
        $check($r['counts']['excluded_known_test_records']===1 && $r['counts']['verified_inbound_records']===0,
            'known_native_test_peer_cannot_be_an_attributed_customer');
        $counts($r);
        $reset(); $message(10,['chat_id'=>$account,'sender_id'=>$account]); $r=$read();
        $check($r['counts']['excluded_service_records']===1,'archive_account_self_message_is_excluded');
        $reset(); $message(10,['is_deleted'=>true]); $r=$read();
        $check($r['counts']['excluded_deleted_records']===1 && $r['counts']['verified_inbound_records']===0,'deleted_record_is_not_recovered');

        foreach([
            'account_id_numeric'=>['account_id'=>(int)$account],
            'sender_id_numeric'=>['sender_id'=>(int)$peer],
            'created_at_string'=>['created_at'=>(string)$messageAt],
            'version_at_string'=>['version_at'=>(string)$messageAt],
            'is_deleted_integer'=>['is_deleted'=>0],
            'direction_array'=>['direction'=>['inbound']],
            'sender_type_array'=>['sender_type'=>['customer']],
            'created_after_version'=>['created_at'=>$messageAt+1],
            'zero_created_at'=>['created_at'=>0]
        ]as$label=>$changes) {
            $reset(); $message(10,$changes); $r=$read();
            $check($r['counts']['excluded_metadata_records']===1 && $r['counts']['verified_inbound_records']===0,'reject_metadata_'.$label);
        }
        foreach(['account_id'=>$secondAccount,'chat_id'=>$otherPeer,'message_id'=>'90009999','version_at'=>$messageAt+1]as$field=>$value) {
            $reset(); $message(10,[],[$field=>$value]); $r=$read();
            $check($r['counts']['excluded_metadata_records']===1,'column_json_binding_'.$field);
        }
        $reset(); $message(10); $run("UPDATE telegram_dialogue_messages SET record_json='{' WHERE id=10"); $r=$read();
        $check($r['counts']['excluded_metadata_records']===1,'malformed_json_remains_unreadable');
        $reset(); $message(10,['text'=>str_repeat('x',1048577)]); $r=$read();
        $check($r['counts']['excluded_metadata_records']===1,'oversized_record_is_not_materialized');
        foreach(['bot_key'=>'NotApproved','connection_id'=>'previous-connection']as$field=>$value) {
            $reset(); $message(10,[$field=>$value]); $r=$read();
            $check($r['counts']['excluded_permission_records']===1,'current_permission_binding_'.$field);
        }
        $reset(); $message(10,['connection_id'=>null]); $r=$read();
        $check($r['counts']['excluded_permission_records']===1,'missing_connection_cannot_prove_permission');
        foreach([
            ['sender_id'=>$otherPeer], ['sender_type'=>'bot'], ['direction'=>'outbound'],
            ['sender_type'=>'manager','sender_id'=>$account,'direction'=>'inbound']
        ]as$i=>$changes) {
            $reset(); $message(10,$changes); $r=$read();
            $check($r['counts']['excluded_role_records']===1,'contradictory_role_'.$i);
        }
        $reset(); $message(10,['sender_id'=>$account,'sender_type'=>'manager','direction'=>'outbound']); $r=$read();
        $check($r['counts']['verified_outbound_records']===1 && $r['counts']['verified_inbound_records']===0,'manager_message_is_not_customer_entry');
        $reset(); $message(10,['version_at'=>$cutoff+1]); $r=$read();
        $check($r['counts']['cutoff_advanced_records']===1 && $r['counts']['verified_inbound_records']===0,'edited_after_cutoff_is_not_historical_evidence');

        foreach([-1,0,1]as$offset) {
            $reset(); $message(10); $at=$messageAt+$offset;
            $visit(1,['visit_at_unix'=>$at,'observed_at_unix'=>$at,'received_at_unix'=>$at]); $r=$read();
            $check($metric($r,'prior_visit')===($offset<=0?1:0) && $metric($r,'future_visit')===($offset>0?1:0),
                'not_later_boundary_'.$offset);
        }
        foreach([0,1]as$extraSecond) {
            $reset(); $message(10); $at=$messageAt-21*86400-$extraSecond;
            $visit(1,['visit_at_unix'=>$at,'observed_at_unix'=>$at,'received_at_unix'=>$at]); $r=$read();
            $check($metric($r,'prior_visit')===1 && $metric($r,'prior_visit_21d')===($extraSecond===0?1:0)
                && $metric($r,'verified_prior_match_21d')===($extraSecond===0?1:0),
                'diagnostic_21_day_boundary_'.$extraSecond);
        }
        $reset(); $message(10); $visit(1,['observed_at_unix'=>$messageAt+1,'received_at_unix'=>$messageAt+2]); $r=$read();
        $check($metric($r,'prior_visit')===1 && $metric($r,'late_observed')===1 && $metric($r,'late_imported')===1
            && $metric($r,'prior_visit_available_at_message')===0,'late_enrichment_is_separate_from_original_visit');
        $reset(); $message(10); $visit(1,['received_at_unix'=>$messageAt+1]); $r=$read();
        $check($metric($r,'late_observed')===0 && $metric($r,'late_imported')===1
            && $metric($r,'prior_visit_available_at_message')===0,'late_local_import_is_separate');

        $reset(); $message(10); $visit(1); $visit(2); $r=$read();
        $check($metric($r,'unique_client_id')===1 && $metric($r,'multiple_client_ids')===0,'repeated_same_client_id_stays_unique');
        $visit(3,['client_id'=>'17929400001234567891']); $r=$read();
        $check($metric($r,'multiple_client_ids')===1 && $metric($r,'unique_client_id')===0
            && $metric($r,'same_observation_pair')===0,'distinct_client_ids_are_ambiguous_not_latest_wins');
        $reset(); $message(10); $visit(1,['yclid'=>'']); $visit(2,['client_id'=>'']); $r=$read();
        $check($metric($r,'unique_client_id')===1 && $metric($r,'click_present')===1
            && $metric($r,'same_observation_pair')===0,'client_and_click_on_different_observations_do_not_form_pair');
        $reset(); $message(10); $visit(1,['client_id'=>'']); $r=$read();
        $check($metric($r,'client_id_missing')===1 && $metric($r,'prior_visit')===1
            && $metric($r,'unique_client_id')===0,'verified_visit_without_client_id_is_explicit');
        $reset(); $message(10); $r=$read();
        $check($metric($r,'missing_history')===1 && $metric($r,'prior_visit')===0,'missing_history_is_not_a_positive_match');
        $reset(); $message(10); $visit(1); $visit(2,['visit_id'=>'fixture-entry-1','external_user_id'=>$otherPeer]); $r=$read();
        $check($metric($r,'identity_conflict')===1 && $metric($r,'unique_client_id')===0
            && $metric($r,'same_observation_pair')===0 && $metric($r,'verified_prior_match')===0
            && $metric($r,'verified_prior_match_21d')===0,'same_visit_with_conflicting_owner_is_unattributable');

        foreach([
            'project_key'=>'ANYTOUR','source_key'=>'TGMINI','platform'=>'Telegram','counter_id'=>'986156350',
            'identity_status'=>'Verified','external_user_id'=>$otherPeer
        ]as$field=>$value) {
            $reset(); $message(10); $visit(1,[$field=>$value]); $r=$read();
            $check($metric($r,'missing_history')===1 && $metric($r,'prior_visit')===0,'exact_visit_scope_'.$field);
        }
        foreach([
            'before_epoch'=>['visit_at_unix'=>1],
            'observed_before_visit'=>['observed_at_unix'=>$messageAt-301],
            'observation_after_import_tolerance'=>['received_at_unix'=>$messageAt-700],
            'future_observation'=>['observed_at_unix'=>$now+1,'received_at_unix'=>$now+1],
            'future_import'=>['received_at_unix'=>$now+1],
            'malformed_client'=>['client_id'=>'1e20'],
            'malformed_click'=>['yclid'=>'not-a-decimal'],
            'space_click'=>['yclid'=>' '],
            'spaces_click'=>['yclid'=>'  '],
            'null_click'=>['yclid'=>null]
        ]as$label=>$changes) {
            if($mysql && $label==='null_click')continue; // Real MySQL schema rejects NULL before a reader can observe it.
            $reset(); $message(10); $visit(1,$changes); $r=$read();
            $check($metric($r,'invalid_history')===1 && $metric($r,'unique_client_id')===0
                && $metric($r,'same_observation_pair')===0 && $metric($r,'verified_prior_match')===0
                && $metric($r,'verified_prior_match_21d')===0,'invalid_history_'.$label);
            $private($r);
        }

        // An index fence is a bounded current selection, not a manufactured old snapshot.
        $reset(); for($i=1;$i<=27;$i++)$message($i);
        $r=$read('26');
        $check($r['counts']['selected_records']===25 && $r['counts']['verified_inbound_records']===25
            && $r['selection']['fence']==='26' && $r['selection']['limit']===25,'latest_25_with_original_fence');
        $check($r['selection']['historical_snapshot']===false,'fence_never_claims_immutable_historical_state');
        $counts($r);

        // Current native/signal/outbox overlap prevents a new-source count being mistaken for new conversions.
        $reset(); $message(10); $visit(1); $native(1001,$peer);
        foreach([1,2]as$id)$run('INSERT INTO advertising_signals(id,project_key,source_key,platform,counter_id,external_user_id,occurred_at_unix) VALUES(?,?,?,?,?,?,?)',
            [$id,'anytour','tgmini','telegram','98615635',$peer,$messageAt]);
        $run('INSERT INTO max_channel_conversion_outbox(id,project_key,identity_source_key,platform,counter_id,external_user_id,event_unix,state) VALUES(?,?,?,?,?,?,?,?)',
            [1,'anytour','tgmini','telegram','98615635',$peer,$messageAt,'pending']);
        $r=$read();
        $check($r['overlap']['status']==='available' && $r['overlap']['native_bot_peers']===1
            && $r['overlap']['signal_peers']===1 && $r['overlap']['outbox_peers']===1,'current_overlap_counts_distinct_exact_peers');
        $check($r['coverage']['qualified_conversion_total']===null && $r['conversion_uploads']===0
            && $r['model_calls']===0 && $r['database_writes']===0,'overlap_does_not_authorize_valuation_or_upload');
        $run("UPDATE advertising_signals SET source_key='TGMINI'");
        $run("UPDATE max_channel_conversion_outbox SET platform='Telegram'"); $r=$read();
        $check($r['overlap']['signal_peers']===0 && $r['overlap']['outbox_peers']===0,'overlap_uses_exact_source_and_platform');

        $reset(); $message(10); $visit(1); $r=$read();
        $check($r['import']['checkpoint_present']===false && $r['import']['cursor_bound']===false
            && $r['import']['last_observation_received_at_unix']===null,'missing_import_checkpoint_is_explicit');
        $run("INSERT INTO advertising_visit_cursors VALUES('anytour','tgmini','telegram','98615635','1')"); $r=$read();
        $check($r['import']['checkpoint_present']===true && $r['import']['cursor_bound']===true
            && $r['import']['last_observation_received_at_unix']===$messageAt-298,'checkpoint_binds_exact_saved_observation');
        $check($r['import']['freshness']==='unknown' && $r['import']['history_complete']===false,
            'last_checkpoint_never_proves_source_freshness_or_complete_delivery');
        $run("UPDATE advertising_visit_cursors SET last_cursor='2'"); $r=$read();
        $check($r['import']['checkpoint_present']===true && $r['import']['cursor_bound']===false
            && $r['import']['last_observation_received_at_unix']===null,'unbound_checkpoint_never_borrows_another_receipt');

        $reset(); $message(10,[],['project_key'=>'ANYTOUR']); $r=$read();
        $check($r['counts']['selected_records']===0,'archive_project_scope_is_exact_under_nocase_collation');

        // Complete-candidate sentinel must fail closed rather than return a truncated match.
        $reset(); $message(10); for($i=1;$i<=1100;$i++)$visit($i);
        $reject(static fn()=>$read(),'source_limit','history_sentinel_withholds_partial_aggregate');

        $reset(); $message(10); $visit(1);
        $reject(static fn()=>$read(null,static function():void { throw new DomainException('SYNTHETIC_PRIVATE_AUTH'); }),
            'forbidden','pre_read_denial_has_no_private_details');
        $afterCalls=0;
        $reject(static fn()=>$read(null,static function()use(&$afterCalls,$pdo):void {
            if(++$afterCalls===2)$pdo->exec('UPDATE telegram_archive_accounts SET approved=0');
        }),'permission_changed','post_rollback_revocation_withholds_result');
        $check(!$pdo->inTransaction(),'revocation_does_not_leave_a_transaction');
        $reset(); $message(10);
        $pdo->beginTransaction();
        $reject(static fn()=>$read(),'transaction_active','caller_transaction_is_rejected');
        $check($pdo->inTransaction(),'caller_still_owns_its_transaction'); $pdo->rollBack();
        $reject(static fn()=>(new ArchiveResearchReadService($pdo,static fn()=>$now))->operatorBusinessIdentitySummary(static function():void{},'10',$cutoff),
            'forbidden','quality_history_authority_is_required');
        $reject(static fn()=>$read('0'),'invalid_arguments','zero_fence_is_not_an_unbounded_request');
        $reject(static fn()=>$read('11'),'source_changed','fence_beyond_current_source_is_rejected');
        $reject(static fn()=>$read(null,null,$now+1),'invalid_arguments','future_cutoff_is_rejected');

        // The bounded week uses original typed creation instants, not recent row edits.
        $from=$cutoff-7*86400;
        $reset();
        $message(10,['created_at'=>$from,'version_at'=>$from]);
        $message(20,['created_at'=>$cutoff,'version_at'=>$cutoff]);
        $message(30,['created_at'=>$from-1,'version_at'=>$cutoff-1]);
        $message(40,['created_at'=>$cutoff+1,'version_at'=>$cutoff+1]);
        $visit(1,['visit_at_unix'=>$from-300,'observed_at_unix'=>$from-299,'received_at_unix'=>$from-298]);
        TelegramBusinessIdentityTraceStatement::$queries=[];
        $windowBefore=$fingerprint(); $windowAuthCalls=0;
        $r=$windowRead(null,null,static function()use($pdo,&$windowAuthCalls):void {
            if($pdo->inTransaction())throw new RuntimeException('authority_inside_old_snapshot');
            $windowAuthCalls++;
        });
        $windowSql=TelegramBusinessIdentityTraceStatement::$queries;
        $check($r['operation']==='business_identity_window_summary' && $r['period']['from_unix']===$from
            && $r['period']['until_unix']===$cutoff && $r['period']['timezone']==='Europe/Moscow'
            && $r['period']['bounds']==='inclusive','week_has_explicit_original_inclusive_bounds');
        $check($r['counts']['selected_records']===2 && $r['counts']['verified_inbound_records']===2
            && $r['period']['dated_candidate_records']===2 && $r['period']['unknown_created_at_records']===0,
            'week_keeps_both_endpoints_excludes_old_edited_and_future_created');
        $check($metric($r,'verified_prior_match')===2 && $metric($r,'verified_prior_match','unique_peers')===1,
            'week_identity_preserves_event_and_peer_denominators');
        $check($r['selection']['complete']===true && $r['selection']['historical_snapshot']===false
            && $r['partition']['peer_suffix']===null && $r['partition']['single_snapshot']===true,
            'complete_current_week_candidates_are_not_an_immutable_history_claim');
        $check($r['budgets']['max_projected_rows']===1001 && $r['budgets']['max_projected_bytes']===1048576
            && (float)$r['budgets']['max_read_seconds']===8.0 && $r['selection']['limit']<=500,
            'week_retains_original_finite_resource_caps');
        $check($windowAuthCalls===2 && !$pdo->inTransaction() && $windowBefore===$fingerprint(),
            'week_rechecks_authority_and_leaves_sources_unchanged');
        $check(!array_filter($windowSql,static fn(string $q):bool=>preg_match('/(?:SELECT|,)\s*m\.record_json\s*(?:,|AS\b|FROM\b)/i',$q)===1
                || preg_match('/\$\.(?:text|caption|attachments|links)/i',$q)===1),'week_never_selects_message_content');
        $private($r); $counts($r);

        foreach(['missing'=>null,'string'=>(string)$messageAt,'text_date'=>'2026-11-26 11:04:00',
            'zero'=>0,'before_epoch'=>1,'after_snapshot'=>$now+1]as$label=>$invalidCreated) {
            $reset(); $message(10,['created_at'=>$invalidCreated]); $r=$windowRead();
            $check($r['counts']['selected_records']===1 && $r['counts']['excluded_metadata_records']===1
                && $r['counts']['verified_inbound_records']===0 && $r['period']['dated_candidate_records']===0
                && $r['period']['unknown_created_at_records']===1 && $r['period']['invalid_clock_records']===0,
                'week_unknown_creation_is_not_a_known_week_event_'.$label);
        }
        $reset(); $message(10); $run("UPDATE telegram_dialogue_messages SET record_json='{' WHERE id=10"); $r=$windowRead();
        $check($r['period']['unknown_created_at_records']===1 && $r['counts']['excluded_metadata_records']===1
            && $r['period']['unknown_clock_scope']==='retained_business_records_in_partition_not_proven_in_period',
            'week_malformed_source_clock_has_explicit_unbounded_date_coverage');
        $reset(); $message(10,['created_at'=>$from+1,'version_at'=>$from-1]); $r=$windowRead();
        $check($r['counts']['selected_records']===1 && $r['period']['invalid_clock_records']===1
            && $r['period']['dated_candidate_records']===0 && $r['counts']['excluded_metadata_records']===1,
            'week_does_not_drop_contradictory_clock_using_version_lower_bound');
        $reset(); $message(10,['created_at'=>$from-1,'version_at'=>null]); $r=$windowRead();
        $check($r['period']['invalid_clock_records']===1 && $r['period']['dated_candidate_records']===0
            && $r['counts']['excluded_metadata_records']===1,'week_old_creation_with_unknown_version_remains_unknown');
        $reset(); $message(10,['version_at'=>$cutoff+1]); $r=$windowRead();
        $check($r['period']['dated_candidate_records']===1 && $r['counts']['cutoff_advanced_records']===1
            && $r['counts']['verified_inbound_records']===0,'week_rejects_current_versions_created_by_later_edits');
        $reset(); $message(10,['direction'=>['inbound']]); $r=$windowRead();
        $check($r['period']['dated_candidate_records']===1 && $r['counts']['excluded_metadata_records']===1
            && $r['counts']['verified_inbound_records']===0,'week_keeps_metadata_rejection_separate_from_date_coverage');

        // Decimal suffix partitions keep every message for a peer together across accounts.
        $reset();
        $partitionPeers=['700000020003','700000020013','700000030013','700000020023',
            '700000020130','700000020000','9007199254741003','3'];
        foreach($partitionPeers as$i=>$who) {
            $message(10+$i,['chat_id'=>$who,'sender_id'=>$who]);
            $visit($i+1,['external_user_id'=>$who,'client_id'=>'1792940000123456789'.(string)$i]);
        }
        $message(30,['chat_id'=>$partitionPeers[0],'sender_id'=>$partitionPeers[0]]);
        $message(31,['chat_id'=>$partitionPeers[0],'sender_id'=>$partitionPeers[0],
            'account_id'=>$secondAccount,'bot_key'=>'FixtureBotB','connection_id'=>'fixture-connection-b']);
        $message(32,['chat_id'=>'700000040003','sender_id'=>'700000040003','created_at'=>null]);
        $native(1001,$partitionPeers[0]);
        $run('INSERT INTO advertising_signals(id,project_key,source_key,platform,counter_id,external_user_id,occurred_at_unix) VALUES(?,?,?,?,?,?,?)',
            [1,'anytour','tgmini','telegram','98615635',$partitionPeers[0],$messageAt]);
        $run('INSERT INTO max_channel_conversion_outbox(id,project_key,identity_source_key,platform,counter_id,external_user_id,event_unix,state) VALUES(?,?,?,?,?,?,?,?)',
            [1,'anytour','tgmini','telegram','98615635',$partitionPeers[0],$messageAt,'pending']);
        $whole=$windowRead(); $parts=[];
        for($digit=0;$digit<10;$digit++)$parts[]=$windowRead((string)$digit);
        $assertPartition=static function(array $parent,array $children,string $label)use($check):void {
            foreach(['counts','distinct']as$section) {
                $sums=array_fill_keys(array_keys($parent[$section]),0);
                foreach($children as$child)foreach($sums as$key=>$unused)$sums[$key]+=$child[$section][$key];
                $check($sums===$parent[$section],$label.'_'.$section.'_additive_without_duplicates');
            }
            foreach(['events','unique_peers']as$unit) {
                $sums=array_fill_keys(array_keys($parent['identities'][$unit]),0);
                foreach($children as$child)foreach($sums as$key=>$unused)$sums[$key]+=$child['identities'][$unit][$key];
                $check($sums===$parent['identities'][$unit],$label.'_'.$unit.'_additive_without_duplicates');
            }
            foreach(['native_bot_peers','signal_peers','outbox_peers']as$key)
                if(array_sum(array_column(array_column($children,'overlap'),$key))!==$parent['overlap'][$key])
                    throw new RuntimeException('business_identity_check:partition_overlap_mismatch');
            foreach(['dated_candidate_records','unknown_created_at_records','invalid_clock_records']as$key)
                if(array_sum(array_column(array_column($children,'period'),$key))!==$parent['period'][$key])
                    throw new RuntimeException('business_identity_check:partition_period_mismatch');
            $check(!array_filter($children,static fn(array $child):bool=>$child['scope_fingerprint']!==$parent['scope_fingerprint'])
                && array_sum(array_column(array_column($children,'identities'),'candidate_observation_rows'))===$parent['identities']['candidate_observation_rows'],
                $label.'_one_scope_and_complete_candidate_history');
        };
        $assertPartition($whole,$parts,'one_digit_partition');
        $check($whole['counts']['verified_inbound_records']===10 && $whole['distinct']['telegram_peers']===8
            && $whole['distinct']['account_chat_pairs']===9,'whole_week_preserves_multiple_messages_and_cross_account_pairs');
        $check($whole['period']['unknown_created_at_records']===1 && $whole['counts']['excluded_metadata_records']===1
            && $whole['period']['dated_candidate_records']===10,'undated_partition_member_is_not_added_to_known_week_totals');
        $parentThree=$windowRead('3'); $childrenThree=[];
        for($digit=0;$digit<10;$digit++)$childrenThree[]=$windowRead((string)$digit.'3');
        $assertPartition($parentThree,$childrenThree,'two_digit_partition');
        $check($childrenThree[0]['partition']['peer_suffix']==='03' && $childrenThree[0]['distinct']['telegram_peers']===3
            && $childrenThree[0]['counts']['verified_inbound_records']===5,'leading_zero_suffix_preserves_large_ids_and_short_peer_three');
        $check($childrenThree[1]['distinct']['telegram_peers']===2 && $childrenThree[1]['counts']['verified_inbound_records']===2,
            'distinct_peers_with_same_two_digit_suffix_are_not_collapsed');
        $thirty=$windowRead('30');
        $check($thirty['counts']['verified_inbound_records']===1 && $parentThree['counts']['verified_inbound_records']===8,
            'child_digit_is_prepended_not_appended_to_parent_suffix');
        $private($whole); $private($parentThree); $private($childrenThree[0]);

        // More than one canonical visit batch is still one complete bounded aggregate.
        $reset();
        for($i=1;$i<=102;$i++) {
            $who='71000002'.str_pad((string)$i,4,'0',STR_PAD_LEFT);
            $message($i,['chat_id'=>$who,'sender_id'=>$who]); $visit($i,['external_user_id'=>$who]);
            $native(1000+$i,$who,$i===102?1:0);
            $run('INSERT INTO advertising_signals(id,project_key,source_key,platform,counter_id,external_user_id,occurred_at_unix) VALUES(?,?,?,?,?,?,?)',
                [$i,'anytour','tgmini','telegram','98615635',$who,$messageAt]);
            $run('INSERT INTO max_channel_conversion_outbox(id,project_key,identity_source_key,platform,counter_id,external_user_id,event_unix,state) VALUES(?,?,?,?,?,?,?,?)',
                [$i,'anytour','tgmini','telegram','98615635',$who,$messageAt,'pending']);
        }
        $r=$windowRead();
        $check($r['counts']['verified_inbound_records']===101 && $r['distinct']['telegram_peers']===101
            && $metric($r,'verified_prior_match')===101 && $r['identities']['candidate_observation_rows']===101,
            'week_joins_more_than_one_hundred_peers_in_complete_canonical_batches');
        $check($r['counts']['excluded_known_test_records']===1 && $r['overlap']['native_bot_peers']===101
            && $r['overlap']['signal_peers']===101 && $r['overlap']['outbox_peers']===101,
            'week_native_test_and_overlap_are_not_truncated_at_twenty_six_peers');

        $reset();
        for($i=1;$i<=1100;$i++) {
            $who='72000002'.str_pad((string)$i,4,'0',STR_PAD_LEFT);
            $message($i,['chat_id'=>$who,'sender_id'=>$who]);
        }
        $reject(static fn()=>$windowRead(),'window_partition_limit','week_overflow_never_returns_partial_zeroes_or_truncated_counts');
        $r=$windowRead('0');
        $check($r['selection']['complete']===true && $r['counts']['verified_inbound_records']===110
            && $r['distinct']['telegram_peers']===110,'bounded_suffix_can_complete_after_whole_week_limit');
        $reset();
        for($i=1;$i<=1100;$i++)$message($i,['chat_id'=>'73000002'.$i.'03','sender_id'=>'73000002'.$i.'03']);
        $reject(static fn()=>$windowRead('03'),'window_partition_limit','two_digit_leaf_retains_the_original_cap');

        $reset(); $message(10);
        for($i=1;$i<=99;$i++) {
            $a='74000002'.str_pad((string)$i,4,'0',STR_PAD_LEFT); $bot='FixturePermission'.$i; $connection='fixture-permission-'.$i;
            $run("INSERT INTO telegram_archive_accounts VALUES('anytour',?,?,?,1,1,NULL)",[$bot,$a,$connection]);
            $run("INSERT INTO telegram_dialogue_connections VALUES('anytour',?,?,?,1)",[$bot,$connection,$a]);
        }
        $reject(static fn()=>$windowRead(),'source_limit','global_permission_overflow_is_not_a_splittable_window');

        $reset(); $message(10); $visit(1);
        foreach(['','003','-1','1x',' 3']as$i=>$invalidSuffix)
            $reject(static fn()=>$windowRead($invalidSuffix),'invalid_arguments','week_rejects_invalid_suffix_'.$i);
        $reject(static fn()=>$windowRead(null,null,null,$cutoff+1),'invalid_arguments','week_rejects_reversed_interval');
        $weekRevocations=0;
        $reject(static fn()=>$windowRead('1',null,static function()use(&$weekRevocations,$pdo):void {
            if(++$weekRevocations===2)$pdo->exec('UPDATE telegram_archive_accounts SET approved=0');
        }),'permission_changed','week_partition_withholds_result_after_permission_revocation');
        $check(!$pdo->inTransaction(),'week_refusal_releases_only_its_owned_transaction');
        $reset(); $message(10);

        // Optional source absence retains explicit unknowns; mandatory history still fails closed.
        $pdo->exec('DROP TABLE advertising_signals'); $r=$read();
        $check($r['overlap']['status']==='partial' && $r['overlap']['signal_status']==='unknown'
            && $r['overlap']['signal_peers']===null && $r['overlap']['outbox_peers']===0,
            'missing_optional_signals_is_unknown_not_zero');
        $pdo->exec('DROP TABLE max_channel_conversion_outbox'); $r=$read();
        $check($r['overlap']['outbox_status']==='unknown' && $r['overlap']['outbox_peers']===null
            && $r['overlap']['signal_peers']===null,'missing_optional_outbox_is_unknown_not_zero');
        $pdo->exec('DROP TABLE advertising_visit_cursors'); $r=$read();
        $check($r['import']['status']==='unknown' && $r['import']['checkpoint_present']===null
            && $r['import']['cursor_bound']===null && $r['import']['last_observation_received_at_unix']===null,
            'missing_optional_checkpoint_is_unknown_not_zero');
        $pdo->exec('DROP TABLE advertising_visit_observations');
        $reject(static fn()=>$read(),'unavailable','unavailable_history_does_not_become_no_matches');

        return $passed;
    } finally {
        TelegramBusinessIdentityTraceStatement::$duringExecute=null;
        if($pdo->inTransaction())$pdo->rollBack();
        $drop(); $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,$oldStatementClass);
    }
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__) {
    try {
        $total=telegramBusinessIdentityAssertions(new PDO('sqlite::memory:'));
        echo 'TELEGRAM BUSINESS IDENTITY SUMMARY REGRESSION PASSED '.$total.PHP_EOL;
    } catch(Throwable $e) {
        $label=str_starts_with($e->getMessage(),'business_identity_check:')?$e->getMessage():'source_or_fixture_failure';
        fwrite(STDERR,'FAIL telegram business identity '.$label.PHP_EOL);
        exit(1);
    }
}
