<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/DialogueReviewReadService.php';

/** Synthetic instrumentation: SQL structure and copied byte counts, never source values or parameters. */
final class DialogueReviewTraceStatement extends PDOStatement
{
    public static array $queries=[];
    public static int $payloadBytes=0;
    public static ?string $failureNeedle=null;
    public static ?PDOException $failure=null;
    protected function __construct(){}
    public function execute(?array $params=null):bool
    {
        self::$queries[]=$this->queryString;
        if(self::$failureNeedle!==null&&str_contains($this->queryString,self::$failureNeedle)){
            self::$failureNeedle=null;throw self::$failure??new PDOException('synthetic failure');
        }
        return parent::execute($params);
    }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed
    {
        $row=parent::fetch($mode,$cursorOrientation,$cursorOffset);self::countPayload($row);return$row;
    }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array
    {
        $rows=parent::fetchAll($mode,...$args);foreach($rows as$row)self::countPayload($row);return$rows;
    }
    private static function countPayload(mixed $row):void
    {
        if(!is_array($row))return;
        foreach(['text','metadata_json','profile_json','json']as$key)if(is_string($row[$key]??null))self::$payloadBytes+=strlen($row[$key]);
    }
}

/** Fixture only. A native caller must provide an isolated disposable prepared-test database. */
function dialogueReviewReadRegression(PDO $pdo):int
{
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    if($mysql&&preg_match('/\Amaxbot_prepared_test(?:_[A-Za-z0-9_]+)?\z/D',(string)$pdo->query('SELECT DATABASE()')->fetchColumn())!==1)
        throw new RuntimeException('dialogue_review_fixture_database_rejected');
    $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
    $originalStatement=$pdo->getAttribute(PDO::ATTR_STATEMENT_CLASS);
    $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[DialogueReviewTraceStatement::class]);
    $tables=['modeled_conversion_profile_versions','manager_conversation_reads','conversation_events','messages','conversations','customer_channels','manager_projects','managers','projects'];
    $drop=static function()use($pdo,$tables):void{foreach($tables as$table)$pdo->exec('DROP TABLE IF EXISTS '.$table);};
    $drop();$checks=0;
    $check=static function(bool $ok,string $name)use(&$checks):void{
        if(!$ok)throw new RuntimeException('dialogue_review_check_failed:'.$name);
        ++$checks;echo 'dialogue review PASS '.$name."\n";
    };
    $reject=static function(callable $call,string $reason)use($check):void{
        try{$call();$check(false,'expected_'.$reason);}
        catch(DialogueReviewReadException $e){$check($e->reason===$reason,'reject_'.$reason);}
    };
    try{
        $integer=$mysql?'BIGINT':'INTEGER';$text=$mysql?'LONGTEXT':'TEXT';$engine=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':'';
        $pdo->exec("CREATE TABLE projects(id $integer PRIMARY KEY,project_key VARCHAR(64),is_active INTEGER)$engine");
        $pdo->exec("CREATE TABLE managers(id $integer PRIMARY KEY,role VARCHAR(32),is_active INTEGER)$engine");
        $pdo->exec("CREATE TABLE manager_projects(manager_id $integer,project_id $integer)$engine");
        $pdo->exec("CREATE TABLE customer_channels(id $integer PRIMARY KEY,customer_id $integer,project_key VARCHAR(64),channel VARCHAR(32),external_user_id VARCHAR(191),external_chat_id VARCHAR(191))$engine");
        $pdo->exec("CREATE TABLE conversations(id $integer PRIMARY KEY,customer_id $integer,customer_channel_id $integer,project_key VARCHAR(64),channel VARCHAR(32),external_chat_id VARCHAR(191),status VARCHAR(32),manager_id $integer,source_id $integer,is_test INTEGER,started_at VARCHAR(32),last_message_at VARCHAR(32),updated_at VARCHAR(32))$engine");
        $pdo->exec("CREATE TABLE messages(id $integer PRIMARY KEY,conversation_id $integer,direction VARCHAR(16),sender_type VARCHAR(16),channel VARCHAR(32),text $text,metadata_json $text,created_at VARCHAR(32))$engine");
        $pdo->exec('CREATE INDEX ix_dialogue_review_messages ON messages(conversation_id,id)');
        $pdo->exec("CREATE TABLE conversation_events(id $integer PRIMARY KEY,conversation_id $integer,event_type VARCHAR(64))$engine");
        $pdo->exec('CREATE INDEX ix_dialogue_review_events ON conversation_events(conversation_id,id)');
        $pdo->exec("CREATE TABLE manager_conversation_reads(manager_id $integer,conversation_id $integer,last_read_message_id $integer)$engine");
        $createProfiles=static function()use($pdo,$integer,$text,$engine):void{
            $pdo->exec("CREATE TABLE modeled_conversion_profile_versions(id $integer PRIMARY KEY,project_key VARCHAR(40),platform VARCHAR(16),customer_key VARCHAR(40),conversation_id VARCHAR(40),observed_at_unix $integer,profile_json $text)$engine");
            $pdo->exec('CREATE INDEX ix_dialogue_review_profile_scope ON modeled_conversion_profile_versions(project_key,platform,customer_key,conversation_id,id)');
        };
        $createProfiles();
        $pdo->exec("INSERT INTO projects VALUES(1,'anytour',1),(2,'foreign',1)");
        $pdo->exec("INSERT INTO managers VALUES(1,'admin',1),(2,'manager',1),(3,'admin',1),(4,'admin',0),(5,'admin',1)");
        $pdo->exec('INSERT INTO manager_projects VALUES(1,1),(2,1),(3,1),(4,1),(5,2)');
        $conversation=static function(int $id,string $channel='max',string $status='ai',string $project='anytour',int $test=0)use($pdo):void{
            $pdo->prepare('INSERT INTO customer_channels VALUES(?,?,?,?,?,?)')->execute([$id,1000+$id,$project,$channel,'private-user-'.$id,'private-chat-'.$id]);
            $pdo->prepare('INSERT INTO conversations VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$id,1000+$id,$id,$project,$channel,'private-chat-'.$id,$status,$status==='manager'?2:null,1,$test,'2026-11-01 10:00:00','2026-11-04 12:00:00','2026-11-04 12:00:00']);
        };
        $conversation(1,'telegram');$conversation(2);
        $conversation(10);$conversation(11,'telegram','waiting_manager');$conversation(12,'max','manager');
        $conversation(13,'telegram','closed');$conversation(14,'website');$conversation(15,'max','ai','foreign');
        $conversation(16,'max','ai','anytour',1);$conversation(17);$conversation(18);$conversation(19);
        $pdo->exec('UPDATE customer_channels SET customer_id=9999 WHERE id=17');
        $pdo->exec("UPDATE customer_channels SET channel='telegram' WHERE id=18");
        $pdo->exec("UPDATE customer_channels SET project_key='foreign' WHERE id=19");
        $now=1793793600;$key=str_repeat('k',32);$connection=str_repeat('c',32);
        $message=static function(int $id,int $conversationId,string $value,array|string|null $metadata=[],string $sender='customer',string $direction='inbound',?string $channel=null)use($pdo):void{
            if($channel===null){$q=$pdo->prepare('SELECT channel FROM conversations WHERE id=?');$q->execute([$conversationId]);$channel=(string)$q->fetchColumn();}
            $json=is_array($metadata)?json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR):$metadata;
            $pdo->prepare('INSERT INTO messages VALUES(?,?,?,?,?,?,?,?)')->execute([$id,$conversationId,$direction,$sender,$channel,$value,$json,'2026-11-04 09:00:00']);
        };
        $clockMetadata=ConversationRecordClock::stamp(['type'=>'message'],$now-100);
        $message(100,10,'Ищем тур из Казани: двое взрослых, 150000 ₽.',$clockMetadata);
        $message(101,10,'На какие даты рассматриваете поездку?',$clockMetadata,'ai','outbound');
        $message(102,10,'Спасибо &amp; детали.',['type'=>'message'],'manager','outbound');
        $message(103,10,'Роль противоречит направлению.',['type'=>'message'],'customer','outbound');
        $message(104,10,'manager_request',['type'=>'callback']);
        $message(105,10,'Фото и https://example.test/private; +7 999 123-45-67',[
            'type'=>'message','attachments'=>[['type'=>'image','token'=>'PRIVATE_MEDIA_TOKEN','url'=>'PRIVATE_MEDIA_URL']],
            'linked_message'=>['type'=>'reply','text'=>'UNREAD_LINKED_TEXT']]);
        $message(106,10,'Текст с недоступными метаданными.','{');
        $message(107,10,'Продолжаем подбор.',ConversationRecordClock::stamp(['type'=>'message'],$now),'ai','outbound');
        $message(200,11,'Когда можно обсудить варианты?',['type'=>'message']);
        $message(201,11,'Уточню длительность.',['type'=>'message'],'ai','outbound');
        $message(300,12,'Вариант менеджера.',['type'=>'message'],'manager','outbound');
        $message(301,12,'Неизвестный тип сохранённого события.',['type'=>['invalid']]);
        foreach([1,2,13,14,15,16,17,18,19]as$id)$message(1000+$id,$id,'MUST_NOT_DISCLOSE_'.$id,['type'=>'message']);
        $pdo->exec("INSERT INTO conversation_events VALUES(1,10,'manager_message_edited'),(2,11,'waiting_manager')");
        $pdo->exec('INSERT INTO manager_conversation_reads VALUES(1,10,99)');
        require_once dirname(__DIR__).'/services/ModeledConversionSemanticPrediction.php';
        $profile=static function(int $conversationId,string $channel,int $time):array{
            $subject=['project_key'=>'anytour','platform'=>$channel,'customer_key'=>(string)(1000+$conversationId),'conversation_id'=>(string)$conversationId];
            $at=gmdate('Y-m-d\TH:i:s\Z',$time);
            $prediction=(new ModeledConversionSemanticPrediction($subject,$at,$at,$at,'10','10'))->finish([]);
            return['subject'=>$subject,'access_status'=>'allowed','profile'=>['prediction_at'=>$at],'semantic_prediction'=>$prediction];
        };
        $saveProfile=static function(int $id,int $conversationId,string $channel,int $time,array|string $value)use($pdo):void{
            $json=is_array($value)?json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR):$value;
            $pdo->prepare('INSERT INTO modeled_conversion_profile_versions VALUES(?,?,?,?,?,?,?)')
                ->execute([$id,'anytour',$channel,(string)(1000+$conversationId),(string)$conversationId,$time,$json]);
        };
        $saveProfile(1,10,'max',$now-60,$profile(10,'max',$now-60));
        $fingerprint=static function()use($pdo,$tables):string{
            $data=[];foreach($tables as$table)$data[$table]=$pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
            return hash('sha256',json_encode($data,JSON_THROW_ON_ERROR));
        };
        $blockedWrite=false;$probe=true;$keyCalls=0;
        $clock=static function()use(&$now,$pdo,&$blockedWrite,&$probe):int{
            if($probe&&$pdo->inTransaction()){
                $probe=false;
                try{$pdo->exec('INSERT INTO manager_conversation_reads VALUES(99,10,999)');}
                catch(PDOException $e){$blockedWrite=true;}
            }
            return$now;
        };
        $provider=static function(int $actor,string $family)use(&$key,&$keyCalls,$pdo):string{
            if($pdo->inTransaction())throw new RuntimeException('key_in_stale_snapshot');
            ++$keyCalls;return$key;
        };
        $service=new DialogueReviewReadService($pdo,$clock,$provider);
        $before=$fingerprint();DialogueReviewTraceStatement::$queries=[];
        $firstInventory=$service->inventory(1,$connection,['limit'=>1]);
        $check($blockedWrite&&$keyCalls===2&&!$pdo->inTransaction(),'actual_readonly_transaction_and_fresh_key_checks');
        $check($before===$fingerprint(),'no_source_read_marker_or_profile_writes');
        $check($firstInventory['timezone']==='Europe/Moscow'&&str_ends_with($firstInventory['as_of_moscow'],'+03:00'),'moscow_report_clock');
        $check($firstInventory['dialogues'][0]['dialogue_id']==='10'&&$firstInventory['dialogues'][0]['status']==='ai','ai_included_without_manager_request');
        $check($firstInventory['total_open_at_this_read']===3&&$firstInventory['has_more'],'native_identity_owner_test_channel_and_closed_scope');
        $all=$firstInventory['dialogues'];$cursor=$firstInventory['next_cursor'];$pages=1;
        while($cursor!==null){
            $page=$service->inventory(1,$connection,['limit'=>1,'cursor'=>$cursor]);
            array_push($all,...$page['dialogues']);$cursor=$page['next_cursor'];if(++$pages>10)throw new RuntimeException('inventory_loop');
        }
        $check(array_column($all,'dialogue_id')===['10','11','12'],'metadata_keyset_pagination_has_no_gap_or_duplicate');
        $refs=array_column($all,'dialogue_ref','dialogue_id');
        $inventoryJson=json_encode($all,JSON_THROW_ON_ERROR);
        $check(!str_contains($inventoryJson,'private-user-')&&!str_contains($inventoryJson,'private-chat-')
            &&!str_contains($inventoryJson,'Ищем')&&!str_contains($inventoryJson,'MUST_NOT_DISCLOSE'),'inventory_is_text_free_without_native_peer_identifiers');
        $check($firstInventory['coverage']['change_hint_is_complete_edit_revision']===false
            &&$firstInventory['coverage']['semantic_review_complete']===false,'inventory_does_not_claim_edit_or_semantic_completeness');
        $newestStart=$firstInventory['newest_open']['start_cursor']??null;
        $check(is_string($newestStart)&&$firstInventory['newest_open']['order']==='conversation_id_desc'
            &&$firstInventory['newest_open']['latest_activity_inventory']===false
            &&$firstInventory['newest_open']['all_today_coverage']===false,'ordinary_inventory_advertises_bounded_newest_open_start');
        $newestBefore=$fingerprint();$newestAll=[];$newestCursor=$newestStart;$newestPages=0;
        do{
            $newestPage=$service->inventory(1,$connection,['cursor'=>$newestCursor,'limit'=>1]);
            array_push($newestAll,...$newestPage['dialogues']);$newestCursor=$newestPage['next_cursor'];
            if(++$newestPages>4)throw new RuntimeException('newest_inventory_loop');
        }while($newestCursor!==null);
        $check(array_column($newestAll,'dialogue_id')===['12','11','10']&&$newestPages===3
            &&$newestPage['total_open_at_this_read']===3,'newest_keyset_has_no_gap_duplicate_or_excluded_dialogue');
        $check($firstInventory['dialogues'][0]['last_message_clock']['at']===gmdate('Y-m-d\TH:i:s\Z',$now)
            &&$newestAll[0]['dialogue_id']==='12','newest_orders_conversation_ids_not_old_dialogue_recent_activity');
        $check($newestPage['coverage']['order']==='conversation_id_desc'
            &&$newestPage['coverage']['latest_activity_inventory']===false&&$newestPage['coverage']['all_today_coverage']===false
            &&$newestPage['coverage']['inventory_traversal_exhausted_within_id_fence']===true
            &&$newestPage['coverage']['new_or_reopened_rows_may_change_between_pages']===true
            &&$newestPage['coverage']['transcripts_read']===false&&$newestPage['coverage']['semantic_review_complete']===false
            &&!array_key_exists('order',$firstInventory['coverage']),'newest_coverage_preserves_current_state_and_review_limits');
        $check($newestBefore===$fingerprint()&&$newestPage['database_writes']===0&&$newestPage['read_markers_changed']===0
            &&$newestPage['customer_sends']===0&&$newestPage['model_calls']===0&&$newestPage['external_fetches']===0,
            'newest_inventory_changes_no_sources_read_markers_or_external_state');
        $newestContext=$service->context(1,$connection,['dialogue_ref'=>$newestAll[0]['dialogue_ref'],'limit'=>1]);
        $check($newestContext['messages'][0]['message_id']==='301','newest_returns_ordinary_scoped_context_references');
        $historical=new DialogueReviewReadService($pdo,$clock,$provider,historyAudit:true);
        $historicalInventory=$historical->inventory(1,$connection,['limit'=>50]);
        $check(!array_key_exists('newest_open',$historicalInventory)
            &&in_array('13',array_column($historicalInventory['dialogues'],'dialogue_id'),true),
            'historical_inventory_retains_closed_scope_without_newest_descriptor');
        $reject(fn()=>$historical->inventory(1,$connection,['cursor'=>$newestStart]),'stale_cursor');
        $full=$service->context(1,$connection,['dialogue_ref'=>$refs['10'],'limit'=>100]);
        $byId=array_column($full['messages'],null,'message_id');
        $check(count($full['messages'])===8&&!$full['has_more']&&$full['next_cursor']===null,'complete_small_text_history');
        $check($byId['100']['clock']['at']===gmdate('Y-m-d\TH:i:s\Z',$now-100)
            &&str_ends_with($byId['100']['clock']['at_moscow'],'+03:00'),'validated_recording_receipt_overrides_bare_wall_time');
        $check($byId['102']['clock']['at']===null&&$byId['102']['clock']['recorded_local_value']==='2026-11-04 09:00:00','unknown_legacy_datetime_not_invented_utc');
        $check($byId['102']['text']==='Спасибо & детали.'&&$byId['102']['role']==='manager','canonical_manager_entity_decoding');
        $check($byId['101']['role']==='bot'&&$byId['103']['role']==='unknown'&&$byId['104']['event_type']==='callback','bot_roles_and_actions_not_customer_intent');
        $textJson=json_encode($full,JSON_THROW_ON_ERROR);
        $check(!str_contains($textJson,'PRIVATE_MEDIA')&&!str_contains($textJson,'UNREAD_LINKED_TEXT')&&!str_contains($textJson,'example.test')
            &&!str_contains($textJson,'999 123')&&$byId['105']['media_unread']&&$byId['105']['linked_context_unread'],'contact_minimization_and_explicit_unread_media');
        $check($byId['106']['metadata_complete']===false&&$byId['106']['read_status']==='available'
            &&$byId['106']['event_type']==='unknown','malformed_metadata_keeps_text_with_unknown_coverage');
        $wrongType=$service->context(1,$connection,['dialogue_ref'=>$refs['12'],'limit'=>1]);
        $check($wrongType['messages'][0]['event_type']==='unknown'&&$wrongType['messages'][0]['text']==='Неизвестный тип сохранённого события.','nonstring_event_type_is_unknown_without_cast_warning');
        $check($full['saved_profile']['availability']==='available'&&$full['saved_profile']['profile_id']==='1'
            &&$full['saved_profile']['projection']['semantic_prediction']['value']['status']==='insufficient_context','stored_profile_uses_existing_validated_projection');
        $check($full['coverage']['saved_profile_refs']==='immutable_journal_refs_not_asserted_quotes_of_current_message_rows'
            &&!$full['coverage']['source_post_or_selection_pages_fetched'],'saved_forecast_is_not_a_fresh_quote_or_fetched_source_post');
        $again=$service->context(1,$connection,['dialogue_ref'=>$refs['10'],'limit'=>100]);
        $check($full['context_revision']===$again['context_revision'],'same_source_page_has_stable_revision_despite_new_reference');
        $small=$service->context(1,$connection,['dialogue_ref'=>$refs['10'],'limit'=>2]);
        $readIds=array_column($small['messages'],'message_id');$next=$small['next_cursor'];$pageCount=1;
        $message(108,10,'После исходного cutoff.',['type'=>'message']);
        $pdo->prepare('UPDATE messages SET text=? WHERE id=100')->execute(['Исправлено: бюджет 170000 ₽.']);
        $newProfile=$profile(10,'max',$now-30);$newProfile['semantic_prediction']['prediction_digest']=str_repeat('0',64);
        $saveProfile(2,10,'max',$now-30,$newProfile);
        $foundEdit=false;
        while($next!==null){
            $page=$service->context(1,$connection,['dialogue_ref'=>$small['dialogue_ref'],'cursor'=>$next,'limit'=>2]);
            $check(($page['saved_profile']['profile_id']??null)==='1','continuation_keeps_exact_selected_saved_profile');
            foreach($page['messages']as$m){$readIds[]=$m['message_id'];if($m['message_id']==='100')$foundEdit=$m['text']==='Исправлено: бюджет 170000 ₽.';}
            $next=$page['next_cursor'];if(++$pageCount>10)throw new RuntimeException('context_loop');
        }
        sort($readIds,SORT_NUMERIC);
        $check($readIds===['100','101','102','103','104','105','106','107']&&$foundEdit,'paged_history_fixed_insert_fence_and_honest_mutable_edits');
        $fresh=$service->context(1,$connection,['dialogue_ref'=>$refs['10'],'limit'=>100]);
        $check($fresh['context_revision']!==$full['context_revision']&&in_array('108',array_column($fresh['messages'],'message_id'),true),'new_pass_reads_new_message_and_detects_source_edit');
        $check($fresh['saved_profile']['availability']==='unavailable'&&$fresh['saved_profile']['profile_id']==='2','invalid_latest_profile_never_falls_back_to_old_positive');
        $pdo->exec('DELETE FROM modeled_conversion_profile_versions WHERE id=2');
        $unprofiled=$service->context(1,$connection,['dialogue_ref'=>$refs['11'],'limit'=>1]);
        $check($unprofiled['saved_profile']['availability']==='not_saved'&&count($unprofiled['messages'])===1,'missing_saved_profile_does_not_block_live_text');
        $saveProfile(3,11,'telegram',$now-20,$profile(11,'telegram',$now-20));
        $unprofiledNext=$service->context(1,$connection,['dialogue_ref'=>$refs['11'],'cursor'=>$unprofiled['next_cursor'],'limit'=>1]);
        $check($unprofiledNext['saved_profile']['availability']==='not_saved','not_saved_marker_is_stable_during_continuation');
        $pdo->exec('DROP TABLE modeled_conversion_profile_versions');
        $missingStorage=$service->context(1,$connection,['dialogue_ref'=>$refs['11'],'limit'=>1]);
        $check($missingStorage['saved_profile']['reason']==='profile_storage_unavailable'&&count($missingStorage['messages'])===1,'missing_optional_profile_storage_keeps_transcript_readable');
        $createProfiles();$saveProfile(4,11,'telegram',$now-20,$profile(11,'telegram',$now-20));
        $missingNext=$service->context(1,$connection,['dialogue_ref'=>$refs['11'],'cursor'=>$missingStorage['next_cursor'],'limit'=>1]);
        $check($missingNext['saved_profile']['reason']==='previous_profile_unavailable','unavailable_profile_marker_does_not_upgrade_mid_snapshot');
        $check($service->context(1,$connection,['dialogue_ref'=>$refs['11']])['saved_profile']['availability']==='available','fresh_pass_can_read_restored_optional_profile');
        $wrong=$profile(10,'max',$now-10);$wrong['subject']['customer_key']='9999';
        $saveProfile(5,10,'max',$now-10,$wrong);
        $check($service->context(1,$connection,['dialogue_ref'=>$refs['10']])['saved_profile']['availability']==='unavailable','saved_wrapper_subject_must_match_native_identity');
        $saveProfile(6,10,'max',$now-5,str_repeat('x',DialogueReviewReadService::MAX_PROFILE_BYTES+1));
        DialogueReviewTraceStatement::$payloadBytes=0;
        $oversizeProfile=$service->context(1,$connection,['dialogue_ref'=>$refs['10'],'limit'=>1]);
        $check($oversizeProfile['saved_profile']['availability']==='unavailable'&&DialogueReviewTraceStatement::$payloadBytes<1000,'oversized_profile_not_materialized_and_text_remains_available');

        $conversation(20);$message(400,20,'После большого сообщения.',['type'=>'message']);
        $message(401,20,str_repeat('SECRET_LARGE_BODY',3000),['type'=>'message']);
        $emptyConversation=21;$conversation($emptyConversation);
        $newInventory=$service->inventory(1,$connection,['limit'=>50]);$newRefs=array_column($newInventory['dialogues'],'dialogue_ref','dialogue_id');
        DialogueReviewTraceStatement::$payloadBytes=0;
        $large=$service->context(1,$connection,['dialogue_ref'=>$newRefs['20'],'limit'=>1]);
        $check($large['messages'][0]['read_status']==='record_exceeds_page_budget'&&$large['messages'][0]['text']===null
            &&DialogueReviewTraceStatement::$payloadBytes===0&&$large['has_more'],'oversized_message_is_metadata_omission_without_payload_copy');
        $tail=$service->context(1,$connection,['dialogue_ref'=>$newRefs['20'],'cursor'=>$large['next_cursor'],'limit'=>1]);
        $check($tail['messages'][0]['text']==='После большого сообщения.'&&!$tail['has_more'],'oversized_record_advances_without_losing_older_tail');
        $empty=$service->context(1,$connection,['dialogue_ref'=>$newRefs['21']]);
        $check($empty['messages']===[]&&!$empty['has_more']&&$empty['coverage']['history_traversal_exhausted_within_id_fence'],'empty_live_dialogue_is_available_not_unavailable');

        // More than a full context page, with JSON escaping large enough to exercise output trimming.
        $conversation(22);
        for($i=0;$i<137;$i++)$message(500+$i,22,$i<120?'Синтетическая строка '.$i:str_repeat('"\\',1400),['type'=>'message']);
        $longRef=array_column($service->inventory(1,$connection,['limit'=>50])['dialogues'],'dialogue_ref','dialogue_id')['22'];
        $longIds=[];$longCursor=null;$longPages=0;
        do{
            $args=['dialogue_ref'=>$longRef,'limit'=>100];if($longCursor!==null)$args['cursor']=$longCursor;
            $page=$service->context(1,$connection,$args);
            $check(strlen(json_encode($page,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))<=DialogueReviewReadService::MAX_OUTPUT_BYTES,'whole_context_output_respects_adapter_byte_budget');
            array_push($longIds,...array_column($page['messages'],'message_id'));$longCursor=$page['next_cursor'];$longRef=$page['dialogue_ref'];
            if(++$longPages>25)throw new RuntimeException('long_context_loop');
        }while($longCursor!==null);
        sort($longIds,SORT_NUMERIC);
        $check(count($longIds)===137&&count(array_unique($longIds))===137&&$longIds[0]==='500'&&$longIds[136]==='636','long_history_and_output_trimming_have_no_gap_or_duplicate');
        $conversation(23);$message(700,23,'Сохранённый предыдущий контекст.',['type'=>'message']);
        $message(701,23,str_repeat('/"\\',10750),['type'=>'message']);
        $slashRef=array_column($service->inventory(1,$connection,['limit'=>50])['dialogues'],'dialogue_ref','dialogue_id')['23'];
        $slashPage=$service->context(1,$connection,['dialogue_ref'=>$slashRef,'limit'=>1]);
        $check(strlen(json_encode($slashPage,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))<=65536
            &&$slashPage['messages'][0]['read_status']==='response_record_limit'&&$slashPage['messages'][0]['text']===null
            &&$slashPage['next_cursor']!==null,'slash_and_escape_heavy_record_is_bounded_by_real_adapter_encoding');
        $slashTail=$service->context(1,$connection,['dialogue_ref'=>$slashRef,'cursor'=>$slashPage['next_cursor'],'limit'=>1]);
        $check($slashTail['messages'][0]['message_id']==='700'&&!$slashTail['has_more'],'response_record_omission_advances_to_older_context');
        $oldInventory=$service->inventory(1,$connection,['limit'=>1]);
        $newestFenced=$service->inventory(1,$connection,['cursor'=>$oldInventory['newest_open']['start_cursor'],'limit'=>1]);
        $conversation(999);
        $oldIds=array_column($oldInventory['dialogues'],'dialogue_id');$cursor=$oldInventory['next_cursor'];
        while($cursor!==null){$page=$service->inventory(1,$connection,['cursor'=>$cursor]);array_push($oldIds,...array_column($page['dialogues'],'dialogue_id'));$cursor=$page['next_cursor'];}
        $check(!in_array('999',$oldIds,true)&&in_array('999',array_column($service->inventory(1,$connection,['limit'=>50])['dialogues'],'dialogue_id'),true),'inventory_id_fence_defers_new_conversation_to_next_pass');
        $newestIds=array_column($newestFenced['dialogues'],'dialogue_id');$newestCursor=$newestFenced['next_cursor'];
        while($newestCursor!==null){
            $newestPage=$service->inventory(1,$connection,['cursor'=>$newestCursor,'limit'=>2]);
            $check($newestPage['snapshot']===$newestFenced['snapshot'],'newest_continuation_preserves_original_fence_and_start');
            array_push($newestIds,...array_column($newestPage['dialogues'],'dialogue_id'));$newestCursor=$newestPage['next_cursor'];
            if(count($newestIds)>50)throw new RuntimeException('newest_fence_loop');
        }
        $freshNewest=$service->inventory(1,$connection,['cursor'=>$newestPage['newest_open']['start_cursor'],'limit'=>1]);
        $check($newestIds===array_reverse($oldIds)&&!in_array('999',$newestIds,true)
            &&$freshNewest['dialogues'][0]['dialogue_id']==='999','newest_continuation_excludes_insert_but_returned_fresh_start_includes_it');
        $existingCount=$service->inventory(1,$connection)['total_open_at_this_read'];
        for($i=2000;$i<2230;$i++)$conversation($i,$i%2===0?'max':'telegram');
        $censusIds=[];$censusCursor=null;$censusPages=0;
        do{
            $args=['limit'=>50];if($censusCursor!==null)$args['cursor']=$censusCursor;
            $page=$service->inventory(1,$connection,$args);
            array_push($censusIds,...array_column($page['dialogues'],'dialogue_id'));$censusCursor=$page['next_cursor'];
            if(++$censusPages>10)throw new RuntimeException('large_inventory_loop');
        }while($censusCursor!==null);
        $check(count($censusIds)===$existingCount+230&&count(array_unique($censusIds))===count($censusIds)
            &&in_array('2000',$censusIds,true)&&in_array('2229',$censusIds,true),'all_current_census_is_not_limited_to_inbox_100_or_200');
        $newestCensus=[];$newestCursor=$page['newest_open']['start_cursor'];$newestPages=0;
        do{
            $newestPage=$service->inventory(1,$connection,['cursor'=>$newestCursor,'limit'=>50]);
            $check($newestPage['returned']<=50&&strlen(json_encode($newestPage,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))<=DialogueReviewReadService::MAX_OUTPUT_BYTES,
                'newest_inventory_keeps_existing_page_and_output_limits');
            array_push($newestCensus,...array_column($newestPage['dialogues'],'dialogue_id'));$newestCursor=$newestPage['next_cursor'];
            if(++$newestPages>10)throw new RuntimeException('newest_census_loop');
        }while($newestCursor!==null);
        $check($newestCensus===array_reverse($censusIds),'large_newest_census_reverses_same_authorized_population_without_gaps');
        $pdo->exec("INSERT INTO conversations SELECT 9223372036854775807,customer_id,customer_channel_id,project_key,channel,external_chat_id,status,manager_id,source_id,is_test,started_at,last_message_at,updated_at FROM conversations WHERE id=10");
        $wideInventory=$service->inventory(1,$connection,['limit'=>1]);
        $wideNewest=$service->inventory(1,$connection,['cursor'=>$wideInventory['newest_open']['start_cursor'],'limit'=>1]);
        $wideTail=$service->inventory(1,$connection,['cursor'=>$wideNewest['next_cursor'],'limit'=>1]);
        $check($wideNewest['dialogues'][0]['dialogue_id']==='9223372036854775807'
            &&$wideNewest['snapshot']['conversation_id_fence']==='9223372036854775807'
            &&$wideTail['dialogues'][0]['dialogue_id']==='2229','newest_bigint_fence_and_exclusive_before_do_not_overflow');

        $reject(fn()=>$service->inventory(1,$connection,['limit'=>51]),'invalid_arguments');
        $reject(fn()=>$service->context(1,$connection,['dialogue_ref'=>$refs['10'],'limit'=>101]),'invalid_arguments');
        $reject(fn()=>$service->inventory(1,$connection,['cursor'=>null]),'invalid_arguments');
        $reject(fn()=>$service->context(1,$connection,[]),'invalid_arguments');
        $reject(fn()=>$service->context(1,$connection,['dialogue_ref'=>$refs['10'],'conversation_id'=>10]),'invalid_arguments');
        $reject(fn()=>$service->context(1,$connection,['dialogue_ref'=>$refs['10'].'x']),'stale_cursor');
        $reject(fn()=>$service->context(3,$connection,['dialogue_ref'=>$refs['10']]),'stale_cursor');
        $reject(fn()=>$service->context(1,str_repeat('d',32),['dialogue_ref'=>$refs['10']]),'stale_cursor');
        $reject(fn()=>$service->context(1,$connection,['dialogue_ref'=>$refs['11'],'cursor'=>$small['next_cursor']]),'stale_cursor');
        $reject(fn()=>$service->context(1,$connection,['dialogue_ref'=>$refs['10'],'cursor'=>$firstInventory['next_cursor']]),'stale_cursor');
        $tamperedNewest=substr($newestStart,0,-1).(str_ends_with($newestStart,'0')?'1':'0');
        $reject(fn()=>$service->inventory(1,$connection,['cursor'=>$tamperedNewest]),'stale_cursor');
        $reject(fn()=>$service->inventory(3,$connection,['cursor'=>$newestStart]),'stale_cursor');
        $reject(fn()=>$service->inventory(1,str_repeat('d',32),['cursor'=>$newestStart]),'stale_cursor');
        $reject(fn()=>$service->inventory(1,$connection,['cursor'=>$refs['10']]),'stale_cursor');
        $reject(fn()=>$service->inventory(1,$connection,['cursor'=>$small['next_cursor']]),'stale_cursor');
        $reject(fn()=>$service->context(1,$connection,['dialogue_ref'=>$newestStart]),'stale_cursor');
        $reject(fn()=>$service->context(1,$connection,['dialogue_ref'=>$refs['10'],'cursor'=>$newestStart]),'stale_cursor');
        foreach([2,4,5]as$actor)$reject(fn()=>$service->inventory($actor,$connection),'forbidden');
        $decode=static function(string $token):array{return json_decode(base64_decode(strtr(explode('.',$token)[0],'-_','+/')),true,16,JSON_THROW_ON_ERROR);};
        $sign=static function(array $value)use(&$key,$connection):string{
            $body=rtrim(strtr(base64_encode(json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),'+/','-_'),'=');
            return$body.'.'.hash_hmac('sha256',"anytour.dialogue-review.v1\0"."1"."\0".$connection."\0anytour\0".$body,$key);
        };
        $forged=$decode($refs['10']);$forged['d']='15';
        $reject(fn()=>$service->context(1,$connection,['dialogue_ref'=>$sign($forged)]),'dialogue_unavailable');
        $forged=$decode($refs['10']);$forged['d']='13';
        $reject(fn()=>$service->context(1,$connection,['dialogue_ref'=>$sign($forged)]),'dialogue_unavailable');
        $forged=$decode($refs['10']);$forged['d']='2';
        $reject(fn()=>$service->context(1,$connection,['dialogue_ref'=>$sign($forged)]),'dialogue_unavailable');
        $forged=$decode($small['next_cursor']);$forged['m']='99999999999999999999';
        $reject(fn()=>$service->context(1,$connection,['dialogue_ref'=>$refs['10'],'cursor'=>$sign($forged)]),'stale_cursor');
        $forged=$decode($firstInventory['next_cursor']);$forged['f']='99999999999999999999';
        $reject(fn()=>$service->inventory(1,$connection,['cursor'=>$sign($forged)]),'stale_cursor');
        $newestState=$decode($newestStart);
        $check($newestState['k']==='inventory_newest'&&$newestState['f']==='12'&&$newestState['a']==='0'
            &&$newestState['t']===$now&&$newestState['e']===$now+DialogueReviewReadService::CURSOR_TTL,
            'newest_start_is_signed_for_its_kind_fence_and_existing_ttl');
        foreach([['f','18446744073709551615'],['a','13']]as[$field,$value]){
            $forged=$newestState;$forged[$field]=$value;
            $reject(fn()=>$service->inventory(1,$connection,['cursor'=>$sign($forged)]),'stale_cursor');
        }
        $now+=DialogueReviewReadService::CURSOR_TTL+1;
        $reject(fn()=>$service->context(1,$connection,['dialogue_ref'=>$refs['10']]),'stale_cursor');
        $reject(fn()=>$service->inventory(1,$connection,['cursor'=>$newestStart]),'stale_cursor');$now-=DialogueReviewReadService::CURSOR_TTL+1;
        $key=str_repeat('z',32);$reject(fn()=>$service->context(1,$connection,['dialogue_ref'=>$refs['10']]),'stale_cursor');
        $reject(fn()=>$service->inventory(1,$connection,['cursor'=>$newestStart]),'stale_cursor');$key=str_repeat('k',32);
        $pdo->beginTransaction();$reject(fn()=>$service->inventory(1,$connection),'transaction_active');
        $check($pdo->inTransaction(),'caller_transaction_is_not_rolled_back');$pdo->rollBack();
        if(!$mysql){
            $pdo->exec('PRAGMA query_only=ON');$service->inventory(1,$connection);
            $check((int)$pdo->query('PRAGMA query_only')->fetchColumn()===1,'preexisting_sqlite_readonly_setting_preserved');
            $pdo->exec('PRAGMA query_only=OFF');
        }
        $revocations=0;
        $revoker=static function(int $actor,string $family)use($pdo,&$revocations,&$key):string{
            if($pdo->inTransaction())throw new RuntimeException('authorization_in_old_snapshot');
            if(++$revocations===2)$pdo->exec('UPDATE managers SET is_active=0 WHERE id=1');return$key;
        };
        $race=new DialogueReviewReadService($pdo,static fn():int=>1793793600,$revoker);
        $reject(fn()=>$race->context(1,$connection,['dialogue_ref'=>$refs['10']]),'permission_changed');
        $check(!$pdo->inTransaction(),'revoked_admin_withholds_payload_and_rolls_back');$pdo->exec('UPDATE managers SET is_active=1 WHERE id=1');
        $revocations=0;$reject(fn()=>$race->inventory(1,$connection,['cursor'=>$newestStart]),'permission_changed');
        $check(!$pdo->inTransaction(),'newest_inventory_withholds_payload_after_admin_revocation');$pdo->exec('UPDATE managers SET is_active=1 WHERE id=1');
        $scopeCalls=0;
        $scopeChanger=static function(int $actor,string $family)use($pdo,&$scopeCalls,&$key):string{
            if(++$scopeCalls===2)$pdo->exec('UPDATE customer_channels SET customer_id=9999 WHERE id=10');return$key;
        };
        $scopeRace=new DialogueReviewReadService($pdo,static fn():int=>1793793600,$scopeChanger);
        $reject(fn()=>$scopeRace->context(1,$connection,['dialogue_ref'=>$refs['10']]),'source_changed');
        $pdo->exec('UPDATE customer_channels SET customer_id=1010 WHERE id=10');
        $pdo->exec("UPDATE customer_channels SET external_user_id='changed-private-user' WHERE id=10");
        $reject(fn()=>$service->context(1,$connection,['dialogue_ref'=>$refs['10']]),'source_changed');
        $pdo->exec("UPDATE customer_channels SET external_user_id='private-user-10' WHERE id=10");
        $rotationCalls=0;
        $rotator=static function()use(&$rotationCalls):string{return ++$rotationCalls===1?str_repeat('k',32):str_repeat('z',32);};
        $rotation=new DialogueReviewReadService($pdo,static fn():int=>1793793600,$rotator);
        $reject(fn()=>$rotation->context(1,$connection,['dialogue_ref'=>$refs['10']]),'stale_cursor');
        $preDenied=new DialogueReviewReadService($pdo,null,static function():string{throw new DomainException('PRIVATE AUTH');});
        $reject(fn()=>$preDenied->inventory(1,$connection),'forbidden');
        $denials=0;
        $denyAfter=static function()use(&$denials):string{if(++$denials===2)throw new DomainException('PRIVATE AUTH');return str_repeat('k',32);};
        $postDenied=new DialogueReviewReadService($pdo,static fn():int=>1793793600,$denyAfter);
        $reject(fn()=>$postDenied->context(1,$connection,['dialogue_ref'=>$refs['10']]),'permission_changed');
        $denials=0;$reject(fn()=>$postDenied->inventory(1,$connection,['cursor'=>$newestStart]),'permission_changed');
        foreach([[3024,'time_limit'],[1054,'unavailable']]as[$code,$reason]){
            $error=new PDOException('PRIVATE SQL BINDINGS');$error->errorInfo=['HY000',$code,'PRIVATE DRIVER ERROR'];
            DialogueReviewTraceStatement::$failure=$error;DialogueReviewTraceStatement::$failureNeedle='COUNT(*) AS total';
            $reject(fn()=>$service->inventory(1,$connection),$reason);
            $check(!$pdo->inTransaction(),'database_failure_restores_transaction_'.$code);
        }
        DialogueReviewTraceStatement::$failure=null;DialogueReviewTraceStatement::$failureNeedle=null;
        $finalBefore=$fingerprint();DialogueReviewTraceStatement::$queries=[];
        $service->inventory(1,$connection);$service->inventory(1,$connection,['cursor'=>$newestStart]);
        $service->context(1,$connection,['dialogue_ref'=>$refs['10']]);
        $check(!array_filter(DialogueReviewTraceStatement::$queries,static fn(string $sql):bool=>!str_starts_with($sql,'SELECT '))
            &&$finalBefore===$fingerprint(),'all_service_data_queries_select_only_and_sources_unchanged');

        // The ordinary transcript remains unchanged. Only the separately verified
        // constructor purpose can receive strict public-share references.
        $sourceAuthorizations=0;
        $sourceAuthorize=static function(int $actor,string $family)use($connection,&$sourceAuthorizations):void{
            if($actor!==1||$family!==$connection)throw new DomainException();
            ++$sourceAuthorizations;
        };
        $withSources=new DialogueReviewReadService($pdo,static fn():int=>1793793600,$provider,$sourceAuthorize);
        $sourceText='Вариант http://www.qui-quo.ru/AB12-CD34). https://qui-quo.ru/AB12-CD34 '
            .'https://qui-quo.ru/EF56-GH78?token=PRIVATE_SOURCE_SECRET '
            .'https://user@qui-quo.ru/IJ90-KL12 https://example.test/PRIVATE_OTHER '
            .'person@example.test +7 999 123-45-67';
        $message(9000001,10,$sourceText,['type'=>'message'],'manager','outbound');
        $plain=$service->context(1,$connection,['dialogue_ref'=>$refs['10'],'limit'=>1]);
        $packet=$withSources->context(1,$connection,['dialogue_ref'=>$refs['10'],'limit'=>1]);
        $row=$packet['messages'][0];$projection=$row['selection_sources'];$reference=$projection['references'][0]??[];
        $withoutProjection=$row;unset($withoutProjection['selection_sources']);
        $check($withoutProjection===$plain['messages'][0]&&!array_key_exists('selection_sources',$plain['messages'][0])
            &&$row['links_redacted']&&$row['selection_links_unread'],'ordinary_transcript_and_redaction_remain_identical');
        $check($sourceAuthorizations===2&&count($projection['references'])===1
            &&$reference===['message_id'=>'9000001','source_ref'=>'message:9000001','message_revision'=>$row['revision'],
                'provider'=>'qui_quo_public_selection','url'=>'https://qui-quo.ru/AB12-CD34',
                'untrusted_source'=>true,'source_page_fetched'=>false],
            'bounded_reference_has_exact_row_revision_and_canonical_provider_provenance');
        $serialized=json_encode($packet,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $check(!str_contains($row['text'],'https://')&&!str_contains($serialized,'PRIVATE_SOURCE_SECRET')
            &&!str_contains($serialized,'PRIVATE_OTHER')&&!str_contains($serialized,'person@example.test')
            &&!str_contains($serialized,'999 123')&&!$projection['complete']&&$projection['unsupported_links_within_limit']===1,
            'other_links_credentials_query_secrets_and_contacts_never_enter_reference_output');
        $previousRevision=$reference['message_revision'];
        $pdo->prepare('UPDATE messages SET text=? WHERE id=9000001')->execute([$sourceText.' Условия изменены.']);
        $changed=$withSources->context(1,$connection,['dialogue_ref'=>$refs['10'],'limit'=>1]);
        $check($changed['messages'][0]['revision']!==$previousRevision
            &&$changed['messages'][0]['selection_sources']['references'][0]['message_revision']===$changed['messages'][0]['revision'],
            'same_url_after_message_edit_binds_the_actual_new_source_revision');
        foreach([
            ['ai','outbound',['type'=>'message']],['bot','outbound',['type'=>'message']],
            ['system','outbound',['type'=>'message']],['manager','inbound',['type'=>'message']],
            ['customer','outbound',['type'=>'message']],['customer','inbound',['type'=>'callback']],
            ['manager','outbound','{'],
        ]as$i=>[$sender,$direction,$metadata]){
            $message(9000010+$i,10,'https://qui-quo.ru/AB12-CD34',$metadata,$sender,$direction);
            $excluded=$withSources->context(1,$connection,['dialogue_ref'=>$refs['10'],'limit'=>1]);
            $check($excluded['messages'][0]['selection_sources']['references']===[],
                'non_message_bot_system_or_inconsistent_role_has_no_public_reference_'.$i);
        }
        $many='';for($i=1;$i<=45;++$i)$many.='https://qui-quo.ru/CCCC-'.str_pad((string)$i,4,'0',STR_PAD_LEFT).' ';
        $message(9000050,10,$many,['type'=>'message'],'manager','outbound');
        $limited=$withSources->context(1,$connection,['dialogue_ref'=>$refs['10'],'limit'=>1]);
        $projection=$limited['messages'][0]['selection_sources'];
        $check(array_column($projection['references'],'url')===['https://qui-quo.ru/CCCC-0044','https://qui-quo.ru/CCCC-0045']
            &&!$projection['complete']&&$projection['classifier_limit_reached']&&$projection['references_omitted_within_limit']===39,
            'per_message_and_existing_classifier_caps_keep_latest_references_with_honest_omission');
        for($i=1;$i<=11;++$i){
            $prefix='https://qui-quo.ru/A'.str_pad((string)$i,3,'0',STR_PAD_LEFT).'-';
            $message(9000100+$i,10,$prefix.'0001 '.$prefix.'0002 '.$prefix.'0003',['type'=>'message'],'manager','outbound');
        }
        foreach([1,2]as$reuse){
            $limited=$withSources->context(1,$connection,['dialogue_ref'=>$refs['10'],'limit'=>11]);$count=0;$bound=true;
            foreach($limited['messages']as$item){
                foreach($item['selection_sources']['references']as$source){
                    ++$count;$bound=$bound&&$source['message_id']===$item['message_id']&&$source['message_revision']===$item['revision'];
                }
            }
            $check($bound&&$count===20&&$limited['messages'][0]['message_id']==='9000101'
                &&$limited['messages'][0]['selection_sources']['references']===[]
                &&!$limited['messages'][0]['selection_sources']['complete'],
                'page_cap_applies_to_newest_rows_and_resets_on_reader_reuse_'.$reuse);
        }
        $message(9000200,10,str_repeat('"',32650).' https://qui-quo.ru/AB12-CD34',['type'=>'message'],'manager','outbound');
        $oversized=$withSources->context(1,$connection,['dialogue_ref'=>$refs['10'],'limit'=>1]);
        $check($oversized['messages'][0]['read_status']==='response_record_limit'
            &&$oversized['messages'][0]['selection_sources']['references']===[]
            &&$oversized['messages'][0]['selection_sources']['reason']==='response_record_limit'
            &&strlen(json_encode($oversized,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))<=65536,
            'response_omission_never_leaves_capability_links_from_an_omitted_record');
        $message(9000300,10,'https://qui-quo.ru/ZX90-YW87',['type'=>'message'],'manager','outbound');
        $beforePurpose=$fingerprint();DialogueReviewTraceStatement::$queries=[];
        $withSources->context(1,$connection,['dialogue_ref'=>$refs['10'],'limit'=>1]);
        $check($beforePurpose===$fingerprint()
            &&!array_filter(DialogueReviewTraceStatement::$queries,static fn(string $sql):bool=>!str_starts_with($sql,'SELECT ')),
            'purpose_projection_remains_select_only_without_source_or_read_marker_writes');
        $purposeCalls=0;
        $purposeRevoker=static function()use(&$purposeCalls):void{if(++$purposeCalls===2)throw new DomainException('PRIVATE_GRANT');};
        $purposeRace=new DialogueReviewReadService($pdo,static fn():int=>1793793600,$provider,$purposeRevoker);
        $reject(fn()=>$purposeRace->context(1,$connection,['dialogue_ref'=>$refs['10'],'limit'=>1]),'permission_changed');
        $check($purposeCalls===2&&!$pdo->inTransaction(),'purpose_revocation_after_projection_withholds_the_whole_packet');
        foreach([
            ["UPDATE managers SET is_active=0 WHERE id=1","UPDATE managers SET is_active=1 WHERE id=1",'permission_changed'],
            ["UPDATE projects SET is_active=0 WHERE id=1","UPDATE projects SET is_active=1 WHERE id=1",'permission_changed'],
            ["UPDATE customer_channels SET customer_id=9999 WHERE id=10","UPDATE customer_channels SET customer_id=1010 WHERE id=10",'source_changed'],
        ]as[$withdraw,$restore,$reason]){
            $purposeCalls=0;
            $lateChange=static function()use(&$purposeCalls,$pdo,$withdraw):void{if(++$purposeCalls===2)$pdo->exec($withdraw);};
            $lateReader=new DialogueReviewReadService($pdo,static fn():int=>1793793600,$provider,$lateChange);
            $reject(fn()=>$lateReader->context(1,$connection,['dialogue_ref'=>$refs['10'],'limit'=>1]),$reason);
            $pdo->exec($restore);
        }
        $prePurpose=new DialogueReviewReadService($pdo,static fn():int=>1793793600,$provider,
            static function():void{throw new DomainException('PRIVATE_GRANT');});
        DialogueReviewTraceStatement::$queries=[];
        $reject(fn()=>$prePurpose->context(1,$connection,['dialogue_ref'=>$refs['10'],'limit'=>1]),'forbidden');
        $check(DialogueReviewTraceStatement::$queries===[],'missing_purpose_grant_reads_no_message_or_project_rows');
        require_once __DIR__.'/monthly_reply_quality_history_fixture.php';
        $checks+=monthlyReplyQualityReadRegression($pdo);
        echo 'Dialogue review read checks: '.$checks." passed.\n";return$checks;
    }finally{
        if($pdo->inTransaction())$pdo->rollBack();
        if(!$mysql)$pdo->exec('PRAGMA query_only=OFF');
        DialogueReviewTraceStatement::$failure=null;DialogueReviewTraceStatement::$failureNeedle=null;
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,$originalStatement);$drop();
    }
}

if(realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){
    if(PHP_SAPI!=='cli')throw new RuntimeException('dialogue_review_fixture_cli_only');
    $args=array_slice($argv,1);
    if($args===[]){
        if(!extension_loaded('pdo_sqlite'))throw new RuntimeException('dialogue_review_fixture_sqlite_required');
        dialogueReviewReadRegression(new PDO('sqlite::memory:'));
    }elseif($args===['--mysql']){
        $dsn=(string)getenv('PREPARED_REPLY_MYSQL_DSN');
        if(preg_match('/\Amysql:.*(?:\Amysql:|;)dbname=(maxbot_prepared_test(?:_[a-zA-Z0-9_]+)?)(?:;|\z)/D',$dsn,$match)!==1)
            throw new RuntimeException('unsafe_dialogue_review_fixture_database');
        $pdo=new PDO($dsn,getenv('PREPARED_REPLY_MYSQL_USER')?:null,getenv('PREPARED_REPLY_MYSQL_PASS')?:null,
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        if($pdo->query('SELECT DATABASE()')->fetchColumn()!==$match[1])throw new RuntimeException('unsafe_dialogue_review_fixture_database');
        dialogueReviewReadRegression($pdo);
    }else throw new RuntimeException('dialogue_review_fixture_arguments');
}
