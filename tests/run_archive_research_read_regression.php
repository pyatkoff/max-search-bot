<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/ArchiveResearchReadService.php';
require_once __DIR__.'/support/archive-predictive-study-store.php';

/** Fixture-only PDO instrumentation; records SQL structure, never bound source values. */
final class ArchiveResearchTraceStatement extends PDOStatement
{
    public static array $queries=[];
    public static ?string $failureNeedle=null;
    public static ?PDOException $failure=null;
    public static $duringExecute=null;
    protected function __construct() {}
    public function execute(?array$params=null):bool
    {
        self::$queries[]=$this->queryString;
        if(is_callable(self::$duringExecute))(self::$duringExecute)($this->queryString);
        if(self::$failureNeedle!==null&&str_contains($this->queryString,self::$failureNeedle)){
            self::$failureNeedle=null;
            throw self::$failure??new PDOException('synthetic fixture failure');
        }
        return parent::execute($params);
    }
}

/** Synthetic fixture only. Caller owns an isolated disposable native test database. */
function archiveResearchReadRegression(PDO $pdo): int
{
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    if($mysql&&!preg_match('/\Amaxbot_prepared_test(?:_[A-Za-z0-9_]+)?\z/D',(string)$pdo->query('SELECT DATABASE()')->fetchColumn()))
        throw new RuntimeException('archive_fixture_database_rejected');
    $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $statementClass=$pdo->getAttribute(PDO::ATTR_STATEMENT_CLASS);
    $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[ArchiveResearchTraceStatement::class]);
    $tables=['telegram_dialogue_messages','telegram_archive_accounts','telegram_dialogue_connections','customer_channels','conversations','projects'];
    $drop=static function()use($pdo,$tables):void{foreach($tables as$t)$pdo->exec('DROP TABLE IF EXISTS '.$t);};
    $drop();$checks=0;
    $check=static function(bool$ok,string$name)use(&$checks):void{if(!$ok)throw new RuntimeException('archive_read_check_failed:'.$name);$checks++;echo "archive read PASS ".$name."\n";};
    $reject=static function(callable$call,string$reason)use($check):void{
        try{$call();$check(false,'expected_'.$reason);}catch(ArchiveResearchReadException$e){$check($e->reason===$reason,'reject_'.$reason);}
    };
    try{
        $integer=$mysql?'BIGINT':'INTEGER';$text=$mysql?'LONGTEXT':'TEXT';
        $pdo->exec("CREATE TABLE projects(id $integer PRIMARY KEY,project_key VARCHAR(64) NOT NULL,is_active INTEGER NOT NULL)");
        $pdo->exec("CREATE TABLE conversations(id $integer PRIMARY KEY,project_key VARCHAR(64),channel VARCHAR(32),customer_channel_id $integer,customer_id $integer)");
        $pdo->exec("CREATE TABLE customer_channels(id $integer PRIMARY KEY,customer_id $integer,project_key VARCHAR(64),channel VARCHAR(32),external_user_id VARCHAR(191),external_chat_id VARCHAR(191))");
        $pdo->exec("CREATE TABLE telegram_archive_accounts(project_key VARCHAR(64),bot_key VARCHAR(96),account_id $integer,connection_id VARCHAR(64),approved INTEGER,provider_enabled INTEGER,manager_id $integer)");
        $pdo->exec("CREATE TABLE telegram_dialogue_connections(project_key VARCHAR(64),bot_key VARCHAR(96),connection_id VARCHAR(64),account_id $integer,is_enabled INTEGER)");
        $pdo->exec("CREATE TABLE telegram_dialogue_messages(id $integer PRIMARY KEY,project_key VARCHAR(64),account_id $integer,chat_id $integer,message_id $integer,version_at $integer,source_kind VARCHAR(32),is_deleted INTEGER,record_json $text)");
        $pdo->exec("CREATE UNIQUE INDEX uq_telegram_dialogue_message ON telegram_dialogue_messages(project_key,account_id,chat_id,message_id)");
        $pdo->exec("INSERT INTO projects VALUES(1,'anytour',1),(2,'foreign',1)");
        $pdo->exec("INSERT INTO conversations VALUES(1,'anytour','telegram',1,10)");
        $pdo->exec("INSERT INTO customer_channels VALUES(1,10,'anytour','telegram','999','999')");
        foreach([[100,'ArchiveA','a',1,1,'anytour'],[101,'ArchiveB','b',1,1,'anytour'],[102,'Revoked','c',0,1,'anytour'],[103,'Disconnected','d',1,0,'anytour'],[104,'Foreign','e',1,1,'foreign']]as[$a,$b,$c,$approved,$enabled,$project]){
            $pdo->prepare('INSERT INTO telegram_archive_accounts VALUES(?,?,?,?,?,1,NULL)')->execute([$project,$b,$a,$c,$approved]);
            $pdo->prepare('INSERT INTO telegram_dialogue_connections VALUES(?,?,?,?,?)')->execute([$project,$b,$c,$a,$enabled]);
        }
        $record=static function(int$a,int$c,int$n,string$text,array$extra=[]):array{
            return array_replace(['account_id'=>(string)$a,'chat_id'=>(string)$c,'message_id'=>(string)$n,'sender_id'=>(string)$c,
                'sender_type'=>'customer','direction'=>'inbound','created_at'=>1700000000,'version_at'=>1700000000,
                'text'=>$text,'links'=>[],'attachments'=>[],'reply_to_message_id'=>null,'is_forwarded'=>false,'is_deleted'=>false,
                'source_kind'=>'desktop_import','content_coverage'=>'text_or_caption'],$extra);
        };
        $seed=static function(int$id,int$a,int$c,int$n,string$text,array$extra=[],string$project='anytour')use($pdo,$record):void{
            $r=$record($a,$c,$n,$text,$extra);
            $pdo->prepare('INSERT INTO telegram_dialogue_messages VALUES(?,?,?,?,?,?,?,?,?)')
                ->execute([$id,$project,$a,$c,$n,$r['version_at'],$r['source_kind'],(int)$r['is_deleted'],json_encode($r,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
        };
        $seed(10,100,200,30,'Пришлите договор.');
        $seed(20,100,200,10,'Здравствуйте.',['sender_id'=>'100','sender_type'=>'manager','direction'=>'outbound']);
        $seed(30,100,200,20,'Ищем тур в июле.');
        $seed(40,100,200,40,'Поступление подтверждено.',['sender_id'=>'100','sender_type'=>'manager','direction'=>'outbound','source_kind'=>'business_message','bot_key'=>'ArchiveA']);
        $seed(50,100,201,1,'unused');$pdo->exec("UPDATE telegram_dialogue_messages SET record_json='{' WHERE id=50");
        $seed(60,100,201,2,'',['is_deleted'=>true]);
        $seed(70,100,201,3,'Смотрите https://example.test/private +7 999 123-45-67 @synthetic_handle',
            ['is_forwarded'=>true,'attachments'=>[['type'=>'image','reference'=>'private/photo.jpg','availability'=>'not_exported']]]);
        $seed(80,101,200,1,'Другой источник.');
        $seed(90,102,300,1,'DENIED');
        $seed(100,103,301,1,'DISCONNECTED');
        $seed(110,104,302,1,'FOREIGN',[],'foreign');
        $seed(120,100,999,1,'INTERNAL OWNER');
        $seed(130,100,777000,1,'SERVICE');
        $seed(140,100,202,1,'Роль не установлена.',['sender_id'=>'500']);
        $seed(150,100,203,1,'WRONG BOT PRIVATE BODY',['bot_key'=>'NotApproved']);
        $seed(160,100,204,1,str_repeat('x',140000));
        $seed(170,100,204,2,'Доступный хвост.');
        $seed(180,100,999,2,'OWNER UPPER BOUND');
        $seed(190,100,777000,2,'SERVICE UPPER BOUND');
        $now=1700000100;$key=str_repeat('k',32);$blockedWrite=false;$keyCalls=0;
        $clock=static function()use(&$now,$pdo,&$blockedWrite):int{
            if($pdo->inTransaction()){
                try{$pdo->exec("INSERT INTO projects VALUES(99,'must_not_write',1)");}
                catch(PDOException$e){$blockedWrite=true;}
            }
            return$now;
        };
        $provider=static function(int$admin,string$connection)use(&$key,$pdo,&$keyCalls):string{
            if($pdo->inTransaction())throw new RuntimeException('key_provider_called_in_old_snapshot');
            $keyCalls++;return$key;
        };
        $service=new ArchiveResearchReadService($pdo,$clock,$provider);
        $connection=str_repeat('c',43);
        $fingerprint=static function()use($pdo):string{
            $rows=$pdo->query('SELECT * FROM telegram_dialogue_messages ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
            return hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR));
        };
        $before=$fingerprint();ArchiveResearchTraceStatement::$queries=[];
        $inventory=$service->inventory(1,$connection,['limit'=>1]);$inventorySql=ArchiveResearchTraceStatement::$queries;
        $check($inventory['database_writes']===0&&$blockedWrite,'actual_readonly_transaction_rejects_write');
        $study=$inventory['historical_prefix_study']??null;
        $check(is_array($study)&&($study['available']??null)===false
            &&in_array($study['status']??null,['not_built','unavailable','scope_changed','busy'],true)
            &&!array_key_exists('counts',$study)&&!array_key_exists('multipliers',$study),
            'ordinary_inventory_exposes_missing_or_unadmitted_private_study_as_unknown_not_zero');
        if(!file_exists(ArchivePredictiveStudyStore::DIRECTORY)&&!is_link(ArchivePredictiveStudyStore::DIRECTORY))
            $check($study===['available'=>false,'status'=>'not_built'],
                'fixed_private_study_read_does_not_create_a_missing_directory');

        $check(!$pdo->inTransaction()&&$keyCalls===2,'fresh_key_checks_outside_transaction');
        $check(count($inventorySql)>0&&!array_filter($inventorySql,static fn($sql)=>preg_match('/record_json|JSON_EXTRACT|JSON_VALID|\bLENGTH\s*\(/i',$sql)),'inventory_executes_metadata_sql_without_json_or_lengths');
        $seekSql=array_values(array_filter($inventorySql,static fn($sql)=>str_contains($sql,'ORDER BY m.chat_id,m.message_id LIMIT 1')));
        $check(count($seekSql)===2&&!array_filter($seekSql,static fn($sql)=>
            !str_contains($sql,'AND m.account_id=? AND m.chat_id>?')
            ||!str_contains($sql,'m.chat_id<>777000 AND NOT EXISTS')
            ||!str_contains($sql,"m.source_kind='desktop_import'")
            ||str_contains($sql,'GROUP BY')||str_contains($sql,'m.account_id>? OR')),'inventory_seeks_scoped_keys_before_limit');
        $metadataSql=array_values(array_filter($inventorySql,static fn($sql)=>str_contains($sql,'AS deleted_records')));
        $check(count($metadataSql)===1&&str_contains($metadataSql[0],'AND m.account_id=? AND m.chat_id IN (?)')
            &&!str_contains($metadataSql[0],' LIMIT '),'inventory_aggregates_only_selected_keys_not_sentinel');
        $check(!$mysql||!array_filter($seekSql,static fn($sql)=>!str_contains($sql,'FORCE INDEX (uq_telegram_dialogue_message)')),'native_seek_uses_existing_ordered_chat_index');
        $check($inventory['snapshot']['row_fence']==='190'&&$inventory['totals']['account_chat_pairs']===6,'owner_and_service_may_set_cutoff_but_are_not_returned');
        $check($inventory['totals']['source_json_bytes']===null&&$inventory['totals']['source_json_bytes_status']==='not_scanned'
            &&$inventory['chats'][0]['source_json_bytes']===null,'unscanned_archive_byte_volume_is_unknown');
        $check($before===$fingerprint()&&(int)$pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn()===2,'data_unchanged');
        $all=$inventory['chats'];$cursor=$inventory['next_cursor'];$pages=1;
        while($cursor!==null){$v=$service->inventory(1,$connection,['limit'=>1,'cursor'=>$cursor]);array_push($all,...$v['chats']);$cursor=$v['next_cursor'];if(++$pages>15)throw new RuntimeException('paging_did_not_advance');}
        $check(count($all)===6&&count(array_unique(array_column($all,'chat_ref')))===6,'distinct_account_chat_keyset_pages');
        $check(count($inventory['accounts'])===2&&$inventory['totals']['account_chat_pairs']===6,'canonical_admission_owner_and_service_exclusion');
        $serialized=json_encode($inventory,JSON_THROW_ON_ERROR);
        $check(!str_contains($serialized,'"account_id"')&&!str_contains($serialized,'"chat_id"')&&!str_contains($serialized,'DENIED'),'metadata_has_no_native_client_ids_or_text');
        $check($inventory['coverage']['accepted_16000_membership']==='unverified'&&$inventory['coverage']['brand']==='unknown','does_not_invent_accepted_set_or_brand');
        $refs=[];
        foreach($all as$chat){
            $payload=explode('.',$chat['chat_ref'])[0];
            $decoded=json_decode(base64_decode(strtr($payload,'-_','+/')),true);
            $refs[$decoded['a'].':'.$decoded['c']]=$chat['chat_ref'];
        }
        $ref=$refs['100:200'];$first=$service->history(1,$connection,['chat_ref'=>$ref,'limit'=>2]);
        $check(array_column($first['messages'],'native_message_id')===['10','20'],'native_order_not_import_row_order');
        $seed(1000,100,200,50,'AFTER ORIGINAL FENCE',['source_kind'=>'business_message','bot_key'=>'ArchiveA']);
        $updated=$record(100,200,30,'Обновлённое сообщение.',['version_at'=>1700000050]);
        $pdo->prepare('UPDATE telegram_dialogue_messages SET version_at=?,record_json=? WHERE id=10')->execute([1700000050,json_encode($updated,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
        $second=$service->history(1,$connection,['chat_ref'=>$ref,'cursor'=>$first['next_cursor'],'limit'=>2]);
        $check(array_column($second['messages'],'native_message_id')===['30','40']&&!$second['has_more'],'fixed_row_fence_excludes_new_import_rows');
        $check($second['messages'][0]['text']==='Обновлённое сообщение.'&&$second['snapshot']['mutable_record_versions']===true,'edits_are_current_versions_not_immutable_claim');
        $check($second['messages'][1]['role']==='manager'&&$second['messages'][1]['role_verified'],'source_bound_manager_role');
        $bad=$service->history(1,$connection,['chat_ref'=>$refs['100:201'],'limit'=>1]);
        $check($bad['messages'][0]['read_status']==='malformed_record'&&$bad['next_cursor']!==null,'malformed_advances_cursor');
        $deleted=$service->history(1,$connection,['chat_ref'=>$refs['100:201'],'cursor'=>$bad['next_cursor'],'limit'=>1]);
        $check($deleted['messages'][0]['deleted']&&$deleted['messages'][0]['text']===null&&$deleted['next_cursor']!==null,'deleted_not_recovered_from_text');
        $media=$service->history(1,$connection,['chat_ref'=>$refs['100:201'],'cursor'=>$deleted['next_cursor'],'limit'=>1]);
        $check($media['messages'][0]['forwarded']===true&&$media['messages'][0]['media'][0]['availability']==='not_exported','forward_and_unread_media_retained');
        $mediaJson=json_encode($media,JSON_THROW_ON_ERROR);
        $check(!str_contains($mediaJson,'private/photo')&&!str_contains($mediaJson,'example.test')&&!str_contains($mediaJson,'@synthetic_handle'),'no_attachment_urls_or_visible_contact_handles');
        $unknown=$service->history(1,$connection,['chat_ref'=>$refs['100:202']]);
        $check($unknown['messages'][0]['role']==='unknown'&&!$unknown['messages'][0]['role_verified'],'contradictory_role_stays_unknown');
        $denied=$service->history(1,$connection,['chat_ref'=>$refs['100:203']]);
        $check($denied['messages'][0]['read_status']==='permission_unverified'&&!str_contains(json_encode($denied),'WRONG BOT'),'declared_bot_fenced_before_payload');
        $large=$service->history(1,$connection,['chat_ref'=>$refs['100:204'],'limit'=>1]);
        $check($large['messages'][0]['read_status']==='record_exceeds_page_budget'&&$large['page_source_json_bytes']===0&&$large['next_cursor']!==null,'large_record_not_materialized_or_silently_dropped');
        $tail=$service->history(1,$connection,['chat_ref'=>$refs['100:204'],'cursor'=>$large['next_cursor'],'limit'=>1]);
        $check($tail['messages'][0]['text']==='Доступный хвост.'&&!$tail['has_more'],'oversized_record_does_not_loop');
        $reject(fn()=>$service->inventory(1,$connection,['limit'=>51]),'invalid_arguments');
        $reject(fn()=>$service->history(1,$connection,['chat_ref'=>$ref,'limit'=>101]),'invalid_arguments');
        $reject(fn()=>$service->inventory(1,$connection,['cursor'=>null]),'invalid_arguments');
        $reject(fn()=>$service->history(1,$connection,['chat_ref'=>$ref.'x']),'stale_cursor');
        $reject(fn()=>$service->history(2,$connection,['chat_ref'=>$ref]),'stale_cursor');
        $reject(fn()=>$service->history(1,str_repeat('d',43),['chat_ref'=>$ref]),'stale_cursor');
        $reject(fn()=>$service->history(1,$connection,['chat_ref'=>$refs['101:200'],'cursor'=>$first['next_cursor']]),'stale_cursor');
        $sign=static function(array$v)use(&$key,$connection):string{
            $b=rtrim(strtr(base64_encode(json_encode($v,JSON_THROW_ON_ERROR)),'+/','-_'),'=');
            return$b.'.'.hash_hmac('sha256',"archive-research-v1\0"."1"."\0".$connection."\0anytour\0".$b,$key);
        };
        $rawRef=explode('.',$ref)[0];$payload=json_decode(base64_decode(strtr($rawRef,'-_','+/')),true);
        $forged=$payload;$forged['f']='99999999999999999999';
        $reject(fn()=>$service->history(1,$connection,['chat_ref'=>$sign($forged)]),'stale_cursor');
        $wrongSchema=$payload;$wrongSchema['v']=2;
        $reject(fn()=>$service->history(1,$connection,['chat_ref'=>$sign($wrongSchema)]),'stale_cursor');
        $forged=$payload;$forged['a']='102';$forged['c']='300';
        $reject(fn()=>$service->history(1,$connection,['chat_ref'=>$sign($forged)]),'forbidden');
        $now+=ArchiveResearchReadService::CURSOR_TTL+1;
        $reject(fn()=>$service->history(1,$connection,['chat_ref'=>$ref]),'stale_cursor');$now=1700000100;
        $key=str_repeat('z',32);$reject(fn()=>$service->history(1,$connection,['chat_ref'=>$ref]),'stale_cursor');$key=str_repeat('k',32);
        $pdo->beginTransaction();$reject(fn()=>$service->inventory(1,$connection),'transaction_active');
        $check($pdo->inTransaction(),'caller_transaction_not_rolled_back');$pdo->rollBack();
        $revocationCalls=0;
        $revoker=static function(int$a,string$c)use($pdo,&$revocationCalls,&$key):string{
            if($pdo->inTransaction())throw new RuntimeException('old_snapshot_key_call');
            if(++$revocationCalls===2)$pdo->exec("UPDATE telegram_archive_accounts SET approved=0 WHERE account_id=100");
            return$key;
        };
        $race=new ArchiveResearchReadService($pdo,static fn()=>1700000100,$revoker);
        $reject(fn()=>$race->history(1,$connection,['chat_ref'=>$ref]),'permission_changed');
        $check(!$pdo->inTransaction(),'revocation_error_rolls_back');
        $pdo->exec("UPDATE telegram_archive_accounts SET approved=1 WHERE account_id=100");
        $pdo->exec("UPDATE telegram_archive_accounts SET connection_id='old-no-connection' WHERE account_id=100");
        $reject(fn()=>$service->history(1,$connection,['chat_ref'=>$ref]),'forbidden');
        $pdo->exec("UPDATE telegram_archive_accounts SET connection_id='a' WHERE account_id=100");
        $pdo->exec("UPDATE projects SET is_active=0 WHERE project_key='anytour'");
        $reject(fn()=>$service->inventory(1,$connection),'forbidden');
        $pdo->exec("UPDATE projects SET is_active=1 WHERE project_key='anytour'");
        $rotationCalls=0;
        $rotating=static function(int$a,string$c)use(&$rotationCalls):string{return ++$rotationCalls===1?str_repeat('k',32):str_repeat('z',32);};
        $rotated=new ArchiveResearchReadService($pdo,static fn()=>1700000100,$rotating);
        $reject(fn()=>$rotated->inventory(1,$connection),'stale_cursor');
        $check(!$pdo->inTransaction(),'mid_read_key_rotation_withholds_payload_and_rolls_back');
        $preDenied=new ArchiveResearchReadService($pdo,null,static function():string{throw new DomainException('PRIVATE PRE AUTH');});
        $reject(fn()=>$preDenied->inventory(1,$connection),'forbidden');
        $postCalls=0;
        $postProvider=static function()use(&$postCalls):string{if(++$postCalls===2)throw new DomainException('PRIVATE POST AUTH');return str_repeat('k',32);};
        $postDenied=new ArchiveResearchReadService($pdo,null,$postProvider);
        $reject(fn()=>$postDenied->inventory(1,$connection),'permission_changed');
        $check(!$pdo->inTransaction(),'post_auth_denial_withholds_payload_and_rolls_back');

        // The real MySQL hint is exercised on synthetic rows, not production data.
        // A table predicate avoids the scalar SLEEP return-1 shortcut; without the hint this is bounded by two 0.2s waits.
        $nativeError=null;
        if($mysql){
            $pdo->exec('START TRANSACTION READ ONLY');
            try{$pdo->query('SELECT /*+ MAX_EXECUTION_TIME(1) */ 1 AS synthetic_marker FROM projects WHERE SLEEP(0.2)')->fetchAll();}
            catch(PDOException$e){$nativeError=$e;}
            finally{if($pdo->inTransaction())$pdo->rollBack();}
            $check($nativeError instanceof PDOException&&in_array((int)($nativeError->errorInfo[1]??0),[3024,1969],true),'native_mysql_execution_timeout_is_observed');
        }
        foreach([['HY000',3024,'time_limit','HY'],['HY000',1969,'time_limit','HY'],['42S22',1054,'unavailable','42'],['PRIVATE BAD STATE',999999,'unavailable',null]]as[$state,$code,$reason,$class]){
            $error=$nativeError&&$code===3024?$nativeError:new PDOException('PRIVATE SQL TEXT AND BINDINGS');
            if(!($nativeError&&$code===3024))$error->errorInfo=[$state,$code,'PRIVATE DRIVER MESSAGE'];
            ArchiveResearchTraceStatement::$failure=$error;
            ArchiveResearchTraceStatement::$failureNeedle='COALESCE(MAX(m.id),0)';
            $beforeFailure=$fingerprint();
            try{$service->inventory(1,$connection);$check(false,'expected_database_failure');}
            catch(ArchiveResearchReadException$e){
                $check($e->reason===$reason&&$e->stage==='fence'&&$e->sqlstateClass===$class
                    &&$e->driverCode===($code<=65535?$code:null),'database_failure_maps_fixed_stage_class_and_code_'.$code);
                $check(!str_contains($e->getMessage(),'PRIVATE')&&!$pdo->inTransaction()
                    &&$beforeFailure===$fingerprint(),'database_failure_hides_sql_and_rolls_back_'.$code);
            }
        }
        ArchiveResearchTraceStatement::$failure=null;ArchiveResearchTraceStatement::$failureNeedle=null;
        $sanitized=new ArchiveResearchReadException('unavailable','PRIVATE STAGE','PRIVATE CLASS',999999);
        $check($sanitized->stage==='unknown'&&$sanitized->sqlstateClass===null&&$sanitized->driverCode===null,'diagnostic_fields_reject_arbitrary_strings_and_codes');
        // Dense chats, an empty account before the cursor and restricted-only/trailing-empty accounts.
        foreach([[99,'EmptyBefore','empty-before'],[105,'OwnerOnly','owner-only'],[107,'After','after'],[108,'EmptyAfter','empty-after']]as[$a,$b,$c]){
            $pdo->prepare('INSERT INTO telegram_archive_accounts VALUES(?,?,?,?,1,1,NULL)')->execute(['anytour',$b,$a,$c]);
            $pdo->prepare('INSERT INTO telegram_dialogue_connections VALUES(?,?,?,?,1)')->execute(['anytour',$b,$c,$a]);
        }
        for($n=1;$n<=220;$n++)$seed(2000+$n,100,199,$n,'Synthetic dense chat.');
        $seed(2300,105,999,1,'OWNER ONLY');$seed(2301,105,777000,1,'SERVICE ONLY');
        $seed(2302,107,1,1,'Synthetic next account.');
        $seed(2303,100,198,1,'BUSINESS ONLY',['source_kind'=>'business_message','bot_key'=>'ArchiveA']);
        ArchiveResearchTraceStatement::$queries=[];
        $dense=$service->inventory(1,$connection,['limit'=>1]);
        $check($dense['chats'][0]['message_records']===220&&$dense['has_more'],'dense_chat_is_one_key_with_exact_selected_metadata');
        $pageKeys=[];$pageCounts=[];$cursor=null;$pages=0;
        do{
            $args=['limit'=>2];if($cursor!==null)$args['cursor']=$cursor;
            $page=$service->inventory(1,$connection,$args);
            foreach($page['chats']as$chat){
                $body=explode('.',$chat['chat_ref'])[0];
                $v=json_decode(base64_decode(strtr($body,'-_','+/')),true);
                $id=$v['a'].':'.$v['c'];$pageKeys[]=$id;$pageCounts[$id]=$chat;
            }
            $cursor=$page['next_cursor'];if(++$pages>10)throw new RuntimeException('paging_did_not_advance');
        }while($cursor!==null);
        $check($pageKeys===['100:199','100:200','100:201','100:202','100:203','100:204','101:200','107:1'],
            'bounded_seek_pages_cross_accounts_skip_empty_owner_service_and_business_only_chats');
        $check($pageCounts['100:200']['message_records']===3&&$pageCounts['100:201']['deleted_records']===1,
            'selected_chat_counts_keep_historical_and_deleted_semantics');
        $error=new PDOException('PRIVATE SEEK SQL');$error->errorInfo=['HY000',3024,'PRIVATE'];
        ArchiveResearchTraceStatement::$failure=$error;
        ArchiveResearchTraceStatement::$failureNeedle='ORDER BY m.chat_id,m.message_id LIMIT 1';
        try{$service->inventory(1,$connection);$check(false,'expected_inventory_seek_timeout');}
        catch(ArchiveResearchReadException$e){$check($e->reason==='time_limit'&&$e->stage==='inventory_page'
            &&$e->sqlstateClass==='HY'&&$e->driverCode===3024&&!$pdo->inTransaction(),'inventory_seek_timeout_maps_and_rolls_back');}
        ArchiveResearchTraceStatement::$failure=null;ArchiveResearchTraceStatement::$failureNeedle=null;
        ArchiveResearchTraceStatement::$queries=[];
        $final=$service->inventory(1,$connection,['limit'=>50]);
        $finalSeeks=array_filter(ArchiveResearchTraceStatement::$queries,static fn($sql)=>str_contains($sql,'ORDER BY m.chat_id,m.message_id LIMIT 1'));
        $check(count($finalSeeks)<=count($final['chats'])+6&&!$final['has_more']&&$final['next_cursor']===null,
            'seek_probes_bounded_by_page_keys_plus_current_accounts');
        $check(strlen(json_encode($final,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))<=ArchiveResearchReadService::MAX_RESPONSE_BYTES,'full_envelope_byte_cap');
        $check(!$pdo->inTransaction(),'successful_read_rolls_back');
        if(!$mysql)$check((int)$pdo->query('PRAGMA query_only')->fetchColumn()===0,'sqlite_query_only_restored');
        // Explicit feature mode is opt-in through a separate cursor; old inventory pages stay metadata-only.
        $seed(2400,100,197,1,'Готовы бронировать, пришлите ссылку на оплату. https://example.test/private +7 999 123-45-67 @synthetic_handle');
        $seed(2401,100,197,2,'Деньги поступили, оплату вижу.',['sender_id'=>'100','sender_type'=>'manager','direction'=>'outbound']);
        $seed(2402,100,197,3,'Я оплатил.',['sender_id'=>'500']);
        $seed(2403,100,197,4,'Я оплатил.',['is_forwarded'=>true]);
        $seed(2404,100,197,5,'Завтра оплачу.');
        $seed(2405,100,197,6,'Не готов бронировать, если отпуск подтвердят.');
        $seed(2406,100,197,7,'Я оплатил.',['is_forwarded'=>null]);
        for($n=1;$n<=1005;$n++)$seed(5000+$n,100,194,$n,'Synthetic metadata row budget.');
        $seed(2500,100,195,1,str_repeat('z',ArchiveResearchReadService::MAX_RECORD_BYTES+100));
        for($n=1;$n<=60;$n++)$seed(2600+$n,100,196,$n,str_repeat('b',20000));
        ArchiveResearchTraceStatement::$queries=[];
        $metadataOnly=$service->inventory(1,$connection,['limit'=>1]);
        $featureStart=$metadataOnly['feature_scan']['start_cursor'];
        $check($metadataOnly['feature_scan']['invoked']===false&&$metadataOnly['feature_scan']['explicit_stored_text_read']===true
            &&$metadataOnly['feature_scan']['operation']==='lexical_feature_inventory'
            &&$metadataOnly['feature_scan']['pattern_set_version']===ArchiveResearchFeatureExtractor::PATTERN_SET_VERSION
            &&!array_filter(ArchiveResearchTraceStatement::$queries,static fn($sql)=>str_contains($sql,'record_json')),
            'feature_descriptor_is_explicit_without_reading_stored_text');
        ArchiveResearchTraceStatement::$queries=[];
        $legacyPage=$service->inventory(1,$connection,['cursor'=>$metadataOnly['next_cursor'],'limit'=>1]);
        $check(!isset($legacyPage['windows'])&&isset($legacyPage['chats'])
            &&!array_filter(ArchiveResearchTraceStatement::$queries,static fn($sql)=>str_contains($sql,'record_json')),
            'old_inventory_cursor_never_switches_to_text_mode');
        $beforeFeatures=$fingerprint();$blockedWrite=false;
        $featureFirst=$service->inventory(1,$connection,['cursor'=>$featureStart,'limit'=>50]);
        $check($blockedWrite&&!$pdo->inTransaction()&&$beforeFeatures===$fingerprint(),'feature_read_is_readonly_and_rolls_back');
        $context=$service->history(1,$connection,['chat_ref'=>$featureFirst['windows'][0]['chat_ref'],'limit'=>1]);
        $check($context['messages'][0]['native_message_id']==='1'
            &&$context['messages'][0]['text']==='Synthetic metadata row budget.','feature_window_chat_ref_opens_existing_full_context_reader');
        $check($featureFirst['operation']==='lexical_feature_inventory'&&$featureFirst['stored_text_read']===true
            &&$featureFirst['model_calls']===0&&$featureFirst['cumulative_counts']===null
            &&$featureFirst['semantic_review_complete']===false,'feature_output_is_windowed_lexical_research_without_labels');
        $check($featureFirst['page_metadata_rows']<=1000&&$featureFirst['page_source_json_bytes']<=1048576
            &&$featureFirst['has_more']&&$featureFirst['page_returned_records']>0
            &&strlen(json_encode($featureFirst,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))<=65536,
            'feature_metadata_source_and_response_budgets_preserve_progress');
        $repeat=$service->inventory(1,$connection,['cursor'=>$featureStart,'limit'=>50]);
        $check(json_encode($featureFirst,JSON_THROW_ON_ERROR)===json_encode($repeat,JSON_THROW_ON_ERROR),
            'same_source_window_and_run_have_deterministic_counts_evidence_and_ids');
        $oldVersion=$pdo->query('SELECT record_json FROM telegram_dialogue_messages WHERE id=5001')->fetchColumn();
        $edited=json_decode($oldVersion,true,64,JSON_THROW_ON_ERROR);$edited['text']='Готовы бронировать.';
        $pdo->prepare('UPDATE telegram_dialogue_messages SET record_json=? WHERE id=5001')->execute([json_encode($edited,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
        $changedVersion=$service->inventory(1,$connection,['cursor'=>$featureStart,'limit'=>50]);
        $check($changedVersion['windows'][0]['chat_key']===$featureFirst['windows'][0]['chat_key']
            &&$changedVersion['windows'][0]['source_window_id']!==$featureFirst['windows'][0]['source_window_id'],
            'feature_window_hash_tracks_current_body_changes_even_without_version_timestamp_change');
        $pdo->prepare('UPDATE telegram_dialogue_messages SET record_json=? WHERE id=5001')->execute([$oldVersion]);
        $featureWindows=[];$featureCursor=$featureStart;$featurePages=0;$sawRowBoundary=false;$sawByteBoundary=false;
        do{
            $page=$service->inventory(1,$connection,['cursor'=>$featureCursor,'limit'=>50]);
            $sawRowBoundary=$sawRowBoundary||$page['page_metadata_rows']===1000;
            $sawByteBoundary=$sawByteBoundary||($page['page_source_json_bytes']>900000&&$page['has_more']);
            $check($page['page_metadata_rows']<=1000&&$page['page_source_json_bytes']<=1048576
                &&count($page['windows'])<=50&&strlen(json_encode($page,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))<=65536,
                'feature_each_page_stays_within_hard_budgets');
            $check(array_sum(array_map(static fn($w)=>count($w['evidence']),$page['windows']))<=8
                &&array_sum(array_map(static fn($w)=>count($w['control_samples']),$page['windows']))<=4,
                'feature_evidence_and_control_samples_are_bounded_per_response');
            array_push($featureWindows,...$page['windows']);$featureCursor=$page['next_cursor'];
            if(++$featurePages>12)throw new RuntimeException('feature_paging_did_not_advance');
        }while($featureCursor!==null);
        $check($sawRowBoundary&&$sawByteBoundary,'feature_row_and_raw_byte_boundaries_are_both_exercised');
        $featureRecords=0;$sourceKinds=['desktop_import'=>0,'business_message'=>0];$counts=[];$patternFlags=[];$plainCounts=[];$readCoverage=[];$extractCoverage=[];$flagCoverage=[];$chatWindows=[];
        $evidenceJson='';
        foreach($featureWindows as$window){
            $check(array_sum($window['source_kind_counts'])===$window['source_records'],'feature_window_source_kinds_cover_all_returned_records');
            foreach($window['source_kind_counts']as$kind=>$n)$sourceKinds[$kind]+=$n;
            $check(array_sum($window['timing_window']['verified_role_message_counts'])===$window['source_records']
                &&$window['timing_window']['version']==='observed_role_switch_v1','feature_window_includes_observed_role_and_timing_coverage');
            $featureRecords+=$window['source_records'];$chatWindows[$window['chat_key']]=($chatWindows[$window['chat_key']]??0)+1;
            foreach($window['pattern_message_counts']as$id=>$n)$counts[$id]=($counts[$id]??0)+$n;
            foreach($window['candidate_flag_counts']as$id=>$values)foreach($values as$flag=>$n)$patternFlags[$id][$flag]=($patternFlags[$id][$flag]??0)+$n;
            foreach($window['unforwarded_verified_role_counts']as$id=>$n)$plainCounts[$id]=($plainCounts[$id]??0)+$n;
            foreach($window['read_status_counts']as$id=>$n)$readCoverage[$id]=($readCoverage[$id]??0)+$n;
            foreach($window['extractor_status_counts']as$id=>$n)$extractCoverage[$id]=($extractCoverage[$id]??0)+$n;
            foreach($window['flag_counts']as$id=>$n)$flagCoverage[$id]=($flagCoverage[$id]??0)+$n;
            foreach([...$window['evidence'],...$window['control_samples']]as$proof){
                $check(strlen($proof['fragment'])<=384&&preg_match('//u',$proof['fragment'])===1
                    &&str_starts_with($proof['source_ref'],'archive_message:'),'feature_evidence_is_bounded_utf8_with_source_reference');
                $evidenceJson.=json_encode($proof,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            }
        }
        $check($sourceKinds===['desktop_import'=>1305,'business_message'=>2],'feature_denominator_separates_historical_import_and_linked_business_records');
        $check($featureRecords===1307&&count($chatWindows)===12
            &&count(array_unique(array_column($featureWindows,'source_window_id')))===count($featureWindows)
            &&max($chatWindows)>1,'feature_keyset_covers_each_source_prefix_and_preserves_chat_keys_across_windows');
        $check(($readCoverage['oversized_record']??0)===1&&($readCoverage['malformed_record']??0)===1
            &&($readCoverage['deleted']??0)===1&&($readCoverage['permission_unverified']??0)===1
            &&($extractCoverage['unknown_role']??0)===2&&($extractCoverage['text_too_large']??0)===1,
            'feature_skipped_record_and_role_coverage_are_explicit');
        $check(($counts['payment_self_report']??0)===2&&($plainCounts['payment_self_report']??0)===0
            &&($counts['manager_payment_ack']??0)===1&&($flagCoverage['unknown_forwarded']??0)>=1
            &&($flagCoverage['forwarded']??0)>=2&&($patternFlags['ready_to_book']['negation']??0)===1
            &&($patternFlags['ready_to_book']['conditional']??0)===1,
            'feature_money_role_forwarding_and_qualifier_counts_do_not_become_events');
        $check(!str_contains($evidenceJson,'example.test')&&!str_contains($evidenceJson,'999 123-45-67')
            &&!str_contains($evidenceJson,'@synthetic_handle')&&!str_contains($evidenceJson,'WRONG BOT')
            &&!str_contains($evidenceJson,'BUSINESS ONLY'),'feature_evidence_uses_minimized_admitted_text_only');
        $decodeToken=static fn(string$token)=>json_decode(base64_decode(strtr(explode('.',$token)[0],'-_','+/')),true);
        $featureState=$decodeToken($featureFirst['next_cursor']);
        $check($featureState['k']==='feature_inventory'&&$featureState['n']!=='0'
            &&!isset($featureState['counts'])&&!isset($featureState['permissions']),'feature_cursor_contains_position_not_cumulative_authority');
        $now+=ArchiveResearchReadService::CURSOR_TTL-1;
        $renewed=$service->inventory(1,$connection,['cursor'=>$featureFirst['next_cursor'],'limit'=>1]);
        $check($decodeToken($renewed['next_cursor'])['e']>$featureState['e'],'feature_cursor_renews_expiry_only_after_fresh_authorized_read');
        $now=1700000100;
        $badFeature=$decodeToken($featureStart);$badFeature['k']='unknown_feature_mode';
        $reject(fn()=>$service->inventory(1,$connection,['cursor'=>$sign($badFeature)]),'stale_cursor');
        $badFeature=$decodeToken($featureStart);$badFeature['counts']=999;
        $reject(fn()=>$service->inventory(1,$connection,['cursor'=>$sign($badFeature)]),'stale_cursor');
        $reject(fn()=>$service->history(1,$connection,['chat_ref'=>$featureStart]),'stale_cursor');
        $reject(fn()=>$service->inventory(1,$connection,['cursor'=>$first['next_cursor']]),'stale_cursor');
        $badFeature=$decodeToken($featureStart);$badFeature['a']='102';$badFeature['c']='300';
        $reject(fn()=>$service->inventory(1,$connection,['cursor'=>$sign($badFeature)]),'forbidden');
        $badFeature=$decodeToken($featureStart);$badFeature['a']='100';$badFeature['c']='198';
        $businessOnly=$service->inventory(1,$connection,['cursor'=>$sign($badFeature),'limit'=>1]);
        $check(!str_contains(json_encode($businessOnly),'BUSINESS ONLY'),'forged_feature_position_cannot_create_historical_chat_admission');
        $featureRevocations=0;
        $featureRevoker=static function(int$a,string$c)use($pdo,&$featureRevocations,&$key):string{
            if(++$featureRevocations===2)$pdo->exec("UPDATE telegram_archive_accounts SET approved=0 WHERE account_id=100");
            return$key;
        };
        $featureRace=new ArchiveResearchReadService($pdo,static fn()=>1700000100,$featureRevoker);
        $reject(fn()=>$featureRace->inventory(1,$connection,['cursor'=>$featureStart]),'permission_changed');
        $check(!$pdo->inTransaction(),'feature_post_read_revocation_withholds_all_windows');
        $pdo->exec("UPDATE telegram_archive_accounts SET approved=1 WHERE account_id=100");
        $pdo->exec("UPDATE telegram_archive_accounts SET approved=0 WHERE account_id=100");
        $remainingFence=(int)$pdo->query("SELECT MAX(id) FROM telegram_dialogue_messages WHERE project_key='anytour' AND account_id IN (101,105,107)")->fetchColumn();
        $check($remainingFence<(int)$featureState['f'],'feature_revocation_fixture_shrinks_current_fence');
        $reject(fn()=>$service->inventory(1,$connection,['cursor'=>$featureFirst['next_cursor']]),'forbidden');
        $check(!$pdo->inTransaction(),'feature_current_account_denial_precedes_smaller_fence_recovery');
        $pdo->exec("UPDATE telegram_archive_accounts SET approved=1 WHERE account_id=100");
        // Numeric timing is tested on synthetic projected edges, independent of lexical cues.
        $timingMethod=new ReflectionMethod(ArchiveResearchReadService::class,'timingWindow');$timingMethod->setAccessible(true);
        $timed=static fn(string$role,?int$time,int$id,array$extra=[]):array=>array_replace(
            ['read_status'=>'available','role'=>$role,'role_verified'=>$role!=='unknown','created_at'=>$time,'source_ref'=>'archive_message:'.$id],$extra);
        $sequence=[$timed('customer',100,1),$timed('customer',130,2),$timed('manager',180,3),
            $timed('manager',200,4),$timed('customer',500,5),$timed('manager',2400,6)];
        $whole=$timingMethod->invoke(null,$sequence);
        $check($whole['response_delta_histograms_seconds']['manager_response']===['le_60'=>1,'le_7200'=>1]
            &&$whole['response_delta_histograms_seconds']['customer_response']===['le_300'=>1]
            &&$whole['verified_role_message_counts']===['customer'=>3,'manager'=>3,'unknown'=>0],
            'timing_coalesces_same_role_runs_using_last_observed_timestamp');
        $joinHist=static function(array$left,array$right):array{
            $hist=['customer_response'=>[],'manager_response'=>[]];
            foreach([$left,$right]as$window)foreach($window['response_delta_histograms_seconds']as$name=>$bins)
                foreach($bins as$bin=>$n)$hist[$name][$bin]=($hist[$name][$bin]??0)+$n;
            $before=$left['last_edge'];$after=$right['first_edge'];
            if($before!==null&&$after!==null&&$after['time']>=$before['time']&&$after['role']!==$before['role']){
                $delta=$after['time']-$before['time'];$bin='gt_604800';
                foreach([60,300,1800,7200,86400,604800]as$ceiling)if($delta<=$ceiling){$bin='le_'.$ceiling;break;}
                $name=$after['role'].'_response';$hist[$name][$bin]=($hist[$name][$bin]??0)+1;
            }
            return$hist;
        };
        foreach([2,3]as$split){
            $left=$timingMethod->invoke(null,array_slice($sequence,0,$split));$right=$timingMethod->invoke(null,array_slice($sequence,$split));
            $check($joinHist($left,$right)==$whole['response_delta_histograms_seconds'],
                'timing_adjacent_window_edges_reconstruct_whole_observed_sequence_'.$split);
        }
        $barriers=$timingMethod->invoke(null,[$timed('customer',100,1),$timed('unknown',110,2),$timed('manager',150,3),
            $timed('customer',null,4),$timed('manager',160,5),$timed('unknown',170,6,['read_status'=>'deleted']),
            $timed('customer',180,7),$timed('customer',170,8),$timed('manager',200,9)]);
        $check($barriers['barriers']===['source_unavailable'=>1,'unknown_role'=>1,'unknown_time'=>1,'nonmonotonic_time'=>1]
            &&$barriers['response_delta_histograms_seconds']===['customer_response'=>[],'manager_response'=>['le_60'=>1]],
            'timing_unknown_missing_deleted_and_reversed_clock_break_previous_continuity');
        $edgeLeft=$timingMethod->invoke(null,[$timed('customer',100,1),$timed('unknown',110,2,['read_status'=>'deleted'])]);
        $edgeRight=$timingMethod->invoke(null,[$timed('unknown',120,3),$timed('manager',130,4)]);
        $check($edgeLeft['last_edge']===null&&$edgeRight['first_edge']===null
            &&$joinHist($edgeLeft,$edgeRight)===['customer_response'=>[],'manager_response'=>[]],
            'timing_window_boundaries_do_not_bridge_through_unknown_or_unavailable_records');
        $clockSequence=[$timed('customer',100,1),$timed('manager',90,2),$timed('customer',120,3)];
        $clockWhole=$timingMethod->invoke(null,$clockSequence);$clockLeft=$timingMethod->invoke(null,array_slice($clockSequence,0,1));
        $clockRight=$timingMethod->invoke(null,array_slice($clockSequence,1));
        $check($clockWhole['barriers']['nonmonotonic_time']===1
            &&$joinHist($clockLeft,$clockRight)==$clockWhole['response_delta_histograms_seconds'],
            'timing_cross_window_negative_clock_delta_is_withheld_without_changing_next_baseline');
        // Rich small chats exercise whole-output prefix trimming independently of source/metadata limits.
        $pdo->exec("INSERT INTO telegram_archive_accounts VALUES('anytour','Wire',109,'wire',1,1,NULL)");
        $pdo->exec("INSERT INTO telegram_dialogue_connections VALUES('anytour','Wire','wire',109,1)");
        $rich='В прошлом году не готовы бронировать, если возможно завтра готовы оплатить. Только у вас, где офис? '
            .'Пришлите договор, форму оплаты картой и ссылку на оплату. Я оплатил остаток и доплату за бизнес-класс. '
            .'Возврат? Бронь подтверждена, получил документы. Подберите другие варианты, купил в другом агентстве, позже. '
            .'https://qui-quo.ru/private';
        for($n=0;$n<50;$n++)$seed(7000+$n,109,10000+$n,1,$rich,['created_at'=>null,'is_forwarded'=>true,
            'attachments'=>[['type'=>'image','reference'=>'private.jpg','availability'=>'not_exported']]]);
        $wireMetadata=$service->inventory(1,$connection,['limit'=>50]);$wireStart=$wireMetadata['feature_scan']['start_cursor'];
        $wireAlias=null;
        foreach($wireMetadata['chats']as$chat)if($decodeToken($chat['chat_ref'])['a']==='109'){$wireAlias=$chat['account_alias'];break;}
        $check($wireAlias!==null,'wire_fixture_account_is_currently_admitted');
        $wireCursor=$wireStart;$wirePages=0;$trimmed=false;$wireKeys=[];$wireCount=0;
        do{
            $page=$service->inventory(1,$connection,['cursor'=>$wireCursor,'limit'=>50]);
            $trimmed=$trimmed||$page['read_but_not_returned_records']>0;
            $check(strlen(json_encode($page,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))<=65536,
                'rich_feature_output_is_trimmed_to_whole_response_budget');
            foreach($page['windows']as$window)if($window['account_alias']===$wireAlias){
                $wireKeys[]=$window['chat_key'];$wireCount+=$window['source_records'];
                $check($window['source_records']===1&&count($window['pattern_message_counts'])>=12,
                    'wire_trim_fixture_contains_rich_single_record_windows');
            }
            $wireCursor=$page['next_cursor'];if(++$wirePages>20)throw new RuntimeException('feature_wire_paging_did_not_advance');
        }while($wireCursor!==null);
        $check($trimmed&&$wireCount===50&&count($wireKeys)===50&&count(array_unique($wireKeys))===50,
            'wire_prefix_trim_advances_only_returned_windows_without_gaps_or_duplicates');
        $endState=$decodeToken($wireStart);$endState['a']='109';$endState['c']='10048';$endState['n']='1';
        $lastWindow=$service->inventory(1,$connection,['cursor'=>$sign($endState),'limit'=>1]);
        $check(count($lastWindow['windows'])===1&&$lastWindow['has_more']&&$lastWindow['next_cursor']!==null,
            'limit_one_last_chat_returns_progress_cursor_without_unbounded_lookahead');
        $terminal=$service->inventory(1,$connection,['cursor'=>$lastWindow['next_cursor'],'limit'=>1]);
        $check($terminal['windows']===[]&&!$terminal['has_more']&&$terminal['next_cursor']===null
            &&$terminal['scan_traversal_exhausted_within_fence'],'conservative_feature_cursor_reaches_empty_terminal_page');
        // Contextual V1 uses the same private read owner, with explicit opt-in and bounded state.
        ArchiveResearchTraceStatement::$queries=[];
        $semMeta=$service->inventory(1,$connection,['limit'=>1]);
        $check(isset($semMeta['semantic_scan']['start_cursor'])&&!$semMeta['semantic_scan']['invoked'],
            'semantic_descriptor_does_not_invoke_contextual_pass');
        $payloadQueries=array_filter(ArchiveResearchTraceStatement::$queries,static fn($sql)=>str_contains($sql,'SELECT m.id,m.record_json'));
        $check(!$payloadQueries,'ordinary_inventory_does_not_select_message_payload');
        $semStart=$semMeta['semantic_scan']['start_cursor'];$semToken=$decodeToken($semStart);
        $check($semToken['k']==='semantic_inventory'&&ArchiveResearchSemanticExtractor::validState($semToken['s'])
            &&strlen($semStart)<=2048,'semantic_start_has_valid_bounded_initial_state');
        $check($semMeta['semantic_scan']['rule_revision']===ArchiveResearchSemanticExtractor::RULE_REVISION,
            'semantic_descriptor_identifies_rule_revision');
        foreach([['unexpected'=>1],['v'=>2],['e'=>-1],['money'=>'foreign:path'],['q'=>'unrecognized']]as$alter){
            $bad=$semToken;$bad['s']=array_replace($bad['s'],$alter);
            $reject(fn()=>$service->inventory(1,$connection,['cursor'=>$sign($bad)]),'stale_cursor');
        }
        $reject(fn()=>$service->history(1,$connection,['chat_ref'=>$semStart]),'stale_cursor');
        $bad=$semToken;$bad['a']='102';$bad['c']='300';
        $reject(fn()=>$service->inventory(1,$connection,['cursor'=>$sign($bad)]),'forbidden');
        $pdo->exec("INSERT INTO telegram_archive_accounts VALUES('anytour','Semantic',110,'sem',1,1,NULL)");
        $pdo->exec("INSERT INTO telegram_dialogue_connections VALUES('anytour','Semantic','sem',110,1)");
        for($n=1;$n<=130;$n++){
            $text=$n===1?'Нужен тур.':($n===99?'Оплату получила.':($n===101?'Тур подтверждён.':'Спасибо.'));
            $extra=['created_at'=>1700000000+$n];
            if(in_array($n,[99,101],true))$extra+=['sender_id'=>'110','sender_type'=>'manager','direction'=>'outbound'];
            $seed(10000+$n,110,20000,$n,$text,$extra);
        }
        $seed(10200,110,20001,1,'Тур подтверждён.',['sender_id'=>'110','sender_type'=>'manager','direction'=>'outbound']);
        $semFresh=$service->inventory(1,$connection,['limit'=>1]);$semState=$decodeToken($semFresh['semantic_scan']['start_cursor']);
        $semState['a']='110';$semState['c']='20000';
        $semFirst=$service->inventory(1,$connection,['cursor'=>$sign($semState),'limit'=>1]);
        $check($semFirst['operation']==='contextual_semantic_inventory'&&$semFirst['model_calls']===0
            &&$semFirst['database_writes']===0&&!$semFirst['persistent_annotations_written'],'semantic_pass_is_read_only_and_explicit');
        $check($semFirst['rule_revision']===ArchiveResearchSemanticExtractor::RULE_REVISION,
            'semantic_page_identifies_rule_revision');
        $w=$semFirst['windows'][0];
        $check($w['source_records']===100&&($w['goal_event_counts']['manager_payment_ack']??0)===1
            &&!isset($w['goal_event_counts']['purchase_supported_candidate']),'semantic_first_window_stops_before_later_booking');
        $nextState=$decodeToken($semFirst['next_cursor']);
        $check($nextState['n']==='100'&&$nextState['s']['money']!==null
            &&strlen(json_encode($nextState['s']))<=1024&&strlen($semFirst['next_cursor'])<=2048,'semantic_cursor_carries_only_bounded_prefix_context');
        $semSecond=$service->inventory(1,$connection,['cursor'=>$semFirst['next_cursor'],'limit'=>1]);
        $w2=$semSecond['windows'][0];
        $check($w2['source_records']===30&&($w2['goal_event_counts']['booking_confirmation']??0)===1
            &&($w2['goal_event_counts']['purchase_supported_candidate']??0)===1,'semantic_continuation_links_only_same_chat_candidate_episode');
        $semThird=$service->inventory(1,$connection,['cursor'=>$semSecond['next_cursor'],'limit'=>1]);
        $check(($semThird['windows'][0]['goal_event_counts']['booking_confirmation']??0)===1
            &&!isset($semThird['windows'][0]['goal_event_counts']['purchase_supported_candidate']),'semantic_new_chat_cannot_inherit_receipt');
        $semEnd=$service->inventory(1,$connection,['cursor'=>$semThird['next_cursor'],'limit'=>1]);
        $check(!$semEnd['has_more']&&$semEnd['windows']===[]&&$semEnd['scan_traversal_exhausted_within_fence'],
            'semantic_scan_reaches_explicit_empty_terminal');
        $renewed=new ArchiveResearchReadService($pdo,static fn()=>1700000200,static fn(int$a,string$c):string=>$key);
        $renewPage=$renewed->inventory(1,$connection,['cursor'=>$semFirst['next_cursor'],'limit'=>1]);
        $check($decodeToken($renewPage['next_cursor'])['e']===1700001400,'semantic_authorized_continuation_renews_expiry');
        $semRevokeCount=0;
        $semRevoker=static function(int$a,string$c)use($pdo,&$semRevokeCount,&$key):string{
            if(++$semRevokeCount===2)$pdo->exec("UPDATE telegram_archive_accounts SET approved=0 WHERE account_id=110");
            return$key;
        };
        $semRace=new ArchiveResearchReadService($pdo,static fn()=>1700000100,$semRevoker);
        $reject(fn()=>$semRace->inventory(1,$connection,['cursor'=>$semFirst['next_cursor']]),'permission_changed');
        $reject(fn()=>$service->inventory(1,$connection,['cursor'=>$semFirst['next_cursor']]),'forbidden');
        $pdo->exec("UPDATE telegram_archive_accounts SET approved=1 WHERE account_id=110");
        // Dense synthetic windows exercise contextual output trimming, including single-window bounds.
        $dense='Ищу тур, бюджет 100000. Давайте бронируем. Оплатил. Где офис? Оплата картой? Подумаем.';
        for($c=21000;$c<21050;$c++)for($n=1;$n<=2;$n++)$seed(20000+($c-21000)*2+$n,110,$c,$n,$dense);
        $denseMeta=$service->inventory(1,$connection,['limit'=>1]);$denseState=$decodeToken($denseMeta['semantic_scan']['start_cursor']);
        $denseState['a']='110';$denseState['c']='21000';$denseCursor=$sign($denseState);
        $denseRows=0;$denseSeen=[];$densePages=0;
        do{
            $page=$service->inventory(1,$connection,['cursor'=>$denseCursor,'limit'=>50]);
            $check(strlen(json_encode($page,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))<=65536,'semantic_entire_output_is_bounded');
            foreach($page['windows']as$window){$denseRows+=$window['source_records'];$denseSeen[]=$window['source_window_id'];
                $check($window['episode_windows'][0]['ordinal']===1,'semantic_context_resets_for_each_dense_chat');}
            $denseCursor=$page['next_cursor'];
            if(++$densePages>20)throw new RuntimeException('semantic_wire_paging_did_not_advance');
        }while($denseCursor!==null);
        $check($denseRows===100&&count($denseSeen)===50&&count(array_unique($denseSeen))===50,
            'semantic_returned_prefix_has_no_skips_or_duplicates');
        // Independent synthetic population for an explicit metadata-issued suffix.
        $pdo->exec("UPDATE telegram_archive_accounts SET provider_enabled=0");
        foreach([[120,'ResumeA','resume-a'],[121,'ResumeB','resume-b']]as[$a,$bot,$conn]){
            $pdo->prepare('INSERT INTO telegram_archive_accounts VALUES(?,?,?,?,1,1,NULL)')
                ->execute(['anytour',$bot,$a,$conn]);
            $pdo->prepare('INSERT INTO telegram_dialogue_connections VALUES(?,?,?,?,1)')
                ->execute(['anytour',$bot,$conn,$a]);
        }
        $seed(30001,120,40000,1,'Нужен тур.',['created_at'=>1699999800]);
        $seed(30002,120,40000,2,'Оплату получила.',
            ['sender_id'=>'120','sender_type'=>'manager','direction'=>'outbound','created_at'=>1699999801]);
        $seed(30003,120,40001,1,'Тур подтверждён.',
            ['sender_id'=>'120','sender_type'=>'manager','direction'=>'outbound','created_at'=>1699999850]);
        for($n=1;$n<=130;$n++){
            $text=$n===1?'Нужен тур.':($n===99?'Оплату получила.':($n===101?'Тур подтверждён.':'Спасибо.'));
            $extra=['created_at'=>1699999900+$n];
            if(in_array($n,[99,101],true))$extra+=['sender_id'=>'120','sender_type'=>'manager','direction'=>'outbound'];
            $seed(31000+$n,120,40002,$n,$text,$extra);
        }
        $seed(32001,121,50000,1,'Ищем тур в июле.',['created_at'=>1699999900]);
        $resumeBefore=$fingerprint();$blockedWrite=false;ArchiveResearchTraceStatement::$queries=[];
        $resumeMetaFirst=$service->inventory(1,$connection,['limit'=>1]);
        $ordinaryCheckpoint=$decodeToken($resumeMetaFirst['next_cursor']);
        $check($ordinaryCheckpoint['k']==='inventory'&&$ordinaryCheckpoint['a']==='120'
            &&$ordinaryCheckpoint['c']==='40000'&&!isset($resumeMetaFirst['semantic_scan']['resume_from_page']),
            'suffix_fixture_obtains_an_actual_exclusive_metadata_checkpoint');
        $resumeMeta=$service->inventory(1,$connection,['cursor'=>$resumeMetaFirst['next_cursor'],'limit'=>1]);
        $resume=$resumeMeta['semantic_scan']['resume_from_page']??null;
        $check(is_array($resume)&&count($resume)===4
            &&!array_diff(array_keys($resume),['start_cursor','range','whole_archive','context'])
            &&is_string($resume['start_cursor']??null)&&$resume['range']==='this_metadata_page_and_following_chats'
            &&$resume['whole_archive']===false&&$resume['context']==='fresh_at_next_chat',
            'ordinary_metadata_continuation_offers_only_explicit_fresh_suffix_semantics');
        $payloadQueries=array_filter(ArchiveResearchTraceStatement::$queries,
            static fn($sql)=>str_contains($sql,'SELECT m.id,m.record_json'));
        $check(!$payloadQueries,'suffix_descriptor_does_not_read_payload_before_explicit_selection');
        $resumeCursor=$resume['start_cursor'];$resumeState=$decodeToken($resumeCursor);
        $fullState=$decodeToken($resumeMeta['semantic_scan']['start_cursor']);$base=['120','40000'];
        $metadataClockBefore=$now;$now=1700000200;ArchiveResearchTraceStatement::$queries=[];
        $renewedMetadata=$service->inventory(1,$connection,['cursor'=>$resumeMetaFirst['next_cursor'],'limit'=>1]);
        $metadataPayloadQueries=array_filter(ArchiveResearchTraceStatement::$queries,
            static fn($sql)=>str_contains($sql,'SELECT m.id,m.record_json'));
        $check($decodeToken($renewedMetadata['next_cursor'])['e']===1700001400
            &&$decodeToken($renewedMetadata['chats'][0]['chat_ref'])['e']===1700001400&&!$metadataPayloadQueries,
            'valid_ordinary_metadata_continuation_renews_reference_expiry_without_payload');
        $now=$ordinaryCheckpoint['e']+1;
        $reject(fn()=>$service->inventory(1,$connection,['cursor'=>$resumeMetaFirst['next_cursor'],'limit'=>1]),'stale_cursor');
        $now=$metadataClockBefore;
        $check($resumeState['k']==='semantic_inventory'&&$resumeState['a']===$base[0]&&$resumeState['c']===$base[1]
            &&$resumeState['n']==='0'&&$resumeState['b']===$base
            &&$resumeState['s']===ArchiveResearchSemanticExtractor::initialState()
            &&$resumeState['f']===$ordinaryCheckpoint['f']&&$resumeState['e']===$now+ArchiveResearchReadService::CURSOR_TTL
            &&$resumeState['x']!==$fullState['x']&&strlen($resumeCursor)<=2048,
            'suffix_cursor_binds_checkpoint_fence_fresh_run_expiry_and_empty_context');
        $check($fullState['a']==='0'&&$fullState['c']==='0'&&$fullState['n']==='0'&&!isset($fullState['b']),
            'ordinary_metadata_continuation_preserves_the_original_full_archive_start');
        $fullRead=$service->inventory(1,$connection,['cursor'=>$resumeMeta['semantic_scan']['start_cursor'],'limit'=>1]);
        $check($fullRead['scan_range']==='from_archive_beginning'
            &&$fullRead['windows'][0]['source_records']===2
            &&($fullRead['windows'][0]['goal_event_counts']['manager_payment_ack']??0)===1
            &&!isset($decodeToken($fullRead['next_cursor'])['b']),
            'full_semantic_cursors_without_suffix_base_remain_valid_and_honest');
        $suffixFirst=$service->inventory(1,$connection,['cursor'=>$resumeCursor,'limit'=>1]);
        $suffixWindow=$suffixFirst['windows'][0];
        $check($suffixFirst['scan_range']==='suffix_after_inventory_checkpoint'
            &&$suffixWindow['source_records']===1
            &&$suffixWindow['episode_windows'][0]['first_source_ref']==='archive_message:30003'
            &&($suffixWindow['goal_event_counts']['booking_confirmation']??0)===1
            &&!isset($suffixWindow['goal_event_counts']['manager_payment_ack'])
            &&!isset($suffixWindow['goal_event_counts']['purchase_supported_candidate'])
            &&$suffixWindow['context_after']['linked_purchase_candidate']===false,
            'suffix_skips_checkpoint_chat_and_cannot_inherit_its_money');
        $suffixRows=$suffixWindow['source_records'];$suffixIds=[$suffixWindow['source_window_id']];
        $suffixCursor=$suffixFirst['next_cursor'];$suffixPages=0;$sawWithinChatPurchase=false;$crossAccountCursor=null;
        while($suffixCursor!==null){
            $continuation=$decodeToken($suffixCursor);
            $check(($continuation['b']??null)===$base&&strlen($suffixCursor)<=2048,
                'every_suffix_continuation_preserves_the_original_base');
            if($continuation['a']==='121')$crossAccountCursor=$suffixCursor;
            $suffixPage=$service->inventory(1,$connection,['cursor'=>$suffixCursor,'limit'=>1]);
            $check($suffixPage['scan_range']==='suffix_after_inventory_checkpoint'
                &&$suffixPage['rule_revision']===ArchiveResearchSemanticExtractor::RULE_REVISION,
                'every_suffix_page_including_eof_retains_its_scope_and_rule_revision');
            foreach($suffixPage['windows']as$window){
                $suffixRows+=$window['source_records'];$suffixIds[]=$window['source_window_id'];
                if(($window['goal_event_counts']['purchase_supported_candidate']??0)===1)$sawWithinChatPurchase=true;
            }
            $suffixCursor=$suffixPage['next_cursor'];
            if(++$suffixPages>8)throw new RuntimeException('suffix_fixture_did_not_advance');
        }
        $check($suffixRows===132&&count($suffixIds)===4&&count(array_unique($suffixIds))===4&&$sawWithinChatPurchase,
            'suffix_continuation_covers_only_remaining_records_and_keeps_legitimate_same_chat_context');
        $check(!$suffixPage['has_more']&&$suffixPage['windows']===[]&&$suffixPage['next_cursor']===null
            &&$suffixPage['scan_traversal_exhausted_within_fence']
            &&$suffixPage['scan_range']==='suffix_after_inventory_checkpoint',
            'suffix_eof_is_exhaustion_of_the_suffix_not_a_full_archive_claim');
        foreach([null,'invalid',[],['120'],['120','40000','extra'],['account'=>'120','chat'=>'40000'],
            ['0','40000'],['120','0'],[120,'40000'],['120',40000],['0120','40000']]as$badBase){
            $bad=$resumeState;$bad['b']=$badBase;
            $reject(fn()=>$service->inventory(1,$connection,['cursor'=>$sign($bad)]),'stale_cursor');
        }
        foreach([['120','40001'],['121','50000']]as$futureBase){
            $bad=$resumeState;$bad['b']=$futureBase;
            $reject(fn()=>$service->inventory(1,$connection,['cursor'=>$sign($bad)]),'stale_cursor');
        }
        $bad=$resumeState;$bad['b']=['102','300'];
        $reject(fn()=>$service->inventory(1,$connection,['cursor'=>$sign($bad)]),'forbidden');
        $bad=$ordinaryCheckpoint;$bad['b']=$base;
        $reject(fn()=>$service->inventory(1,$connection,['cursor'=>$sign($bad)]),'stale_cursor');
        $bad=$decodeToken($resumeMeta['feature_scan']['start_cursor']);$bad['b']=$base;
        $reject(fn()=>$service->inventory(1,$connection,['cursor'=>$sign($bad)]),'stale_cursor');
        $reject(fn()=>$service->inventory(2,$connection,['cursor'=>$resumeCursor]),'stale_cursor');
        $reject(fn()=>$service->inventory(1,str_repeat('d',43),['cursor'=>$resumeCursor]),'stale_cursor');
        $check(is_string($crossAccountCursor),'suffix_fixture_reaches_a_later_allowed_account');
        $pdo->exec("UPDATE telegram_archive_accounts SET approved=0 WHERE account_id=120");
        $reject(fn()=>$service->inventory(1,$connection,['cursor'=>$crossAccountCursor]),'forbidden');
        $pdo->exec("UPDATE telegram_archive_accounts SET approved=1 WHERE account_id=120");
        $check($resumeBefore===$fingerprint()&&$blockedWrite&&!$pdo->inTransaction()
            &&(int)$pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn()===2,
            'suffix_reads_reject_business_writes_preserve_sources_and_close_transactions');

        // Internal server-study reads use real operator authority, never a manufactured OAuth identity.
        $operator=new ArchiveResearchReadService($pdo,$clock);$operatorCalls=0;$operatorWriteDenied=false;
        $operatorAuthority=static function()use($pdo,&$operatorCalls):void{
            if($pdo->inTransaction())throw new RuntimeException('authority_in_snapshot');$operatorCalls++;
        };
        ArchiveResearchTraceStatement::$duringExecute=static function(string$sql)use($pdo,&$operatorWriteDenied):void{
            if(!str_contains($sql,'SELECT m.id,m.record_json'))return;
            ArchiveResearchTraceStatement::$duringExecute=null;
            try{$pdo->exec("INSERT INTO projects VALUES(99,'operator_must_not_write',1)");}
            catch(PDOException$e){$operatorWriteDenied=true;}
        };
        $seed(33001,120,40003,1,'PRIVATE_OPERATOR_WRONG_BOT',['bot_key'=>'NotApproved']);
        $seed(33002,120,40003,2,'PRIVATE_OPERATOR_DELETED',['is_deleted'=>true]);
        $operatorBefore=$fingerprint();$operatorRows=[];$operatorWindows=[];$operatorPosition=[];$operatorPages=0;$operatorFirst=null;
        do{
            $op=$operator->operatorStudyPage($operatorAuthority,$operatorPosition,2);$operatorPages++;
            if($operatorFirst===null)$operatorFirst=$op;
            $check(count($op)===10&&!array_diff(array_keys($op),['project_key','source_fence','scope_digest','expected','windows',
                'next_position','has_more','metadata_rows','source_json_bytes','returned_records'])
                &&$op['project_key']==='anytour'&&preg_match('/\A[a-f0-9]{64}\z/D',$op['scope_digest'])===1
                &&$op['metadata_rows']<=1000&&$op['source_json_bytes']<=1048576
                &&strlen(json_encode($op,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))<=2097152,
                'operator_page_is_a_bounded_private_source_projection_'.$operatorPages);
            $pageRows=0;$canonicalItemsValid=true;
            foreach($op['windows']as$window){
                $check(count($window)===5&&!array_diff(array_keys($window),['account_id','chat_id','account_alias','chat_window_complete','messages'])
                    &&in_array($window['account_id'],['120','121'],true)&&is_bool($window['chat_window_complete']),
                    'operator_window_retains_only_its_admitted_native_scope');
                foreach($window['messages']as$item){
                    $operatorRows[]=$item;$pageRows++;
                    $canonicalItemsValid=$canonicalItemsValid&&$item['historical_first_available_at']===null
                        &&!array_key_exists('record_json',$item)&&in_array($item['source_kind'],['desktop_import','business_message'],true);
                }
                $operatorWindows[]=$window;
            }
            $check($canonicalItemsValid,'operator_messages_use_canonical_projection_not_raw_json');
            $check($op['returned_records']===$pageRows&&is_bool($op['has_more'])
                &&($operatorPages===1?$op['expected']===['desktop_records'=>136,'account_chat_pairs'=>5]:$op['expected']===null),
                'operator_expected_counts_are_initial_only_and_rows_are_returned_counts');
            if($op['has_more']){
                $p=$op['next_position'];
                $check(is_array($p)&&count($p)===5&&!array_diff(array_keys($p),['f','a','c','n','scope_digest'])
                    &&$p['scope_digest']===$op['scope_digest']&&$p['f']===$op['source_fence'],
                    'operator_continuation_pins_source_fence_and_current_scope_digest');
                $operatorPosition=$p;
            }else $check($op['next_position']===null,'operator_eof_has_no_continuation');
            if($operatorPages>8)throw new RuntimeException('operator_fixture_did_not_advance');
        }while($op['has_more']);
        ArchiveResearchTraceStatement::$duringExecute=null;
        $check(count($operatorRows)===136&&count(array_unique(array_column($operatorRows,'source_ref')))===136
            &&$operatorCalls===$operatorPages*2&&$operatorWriteDenied&&!$pdo->inTransaction()
            &&$operatorBefore===$fingerprint(),
            'keyless_operator_pages_cover_all_admitted_sources_once_with_fresh_authority_and_readonly_transactions');
        $unknowns=[];foreach($operatorRows as$item)$unknowns[$item['source_ref']]=$item;
        $check(($unknowns['archive_message:33001']['read_status']??null)==='permission_unverified'
            &&($unknowns['archive_message:33002']['read_status']??null)==='deleted'
            &&$unknowns['archive_message:33001']['text']===null&&$unknowns['archive_message:33002']['text']===null
            &&!str_contains(json_encode($operatorRows,JSON_THROW_ON_ERROR),'PRIVATE_OPERATOR_'),
            'operator_counts_unavailable_metadata_without_reading_or_leaking_denied_payloads');
        $canonical=$service->history(1,$connection,['chat_ref'=>$resumeMetaFirst['chats'][0]['chat_ref'],'limit'=>100]);
        $check($operatorWindows[0]['messages']===$canonical['messages'],
            'internal_operator_and_authenticated_history_share_the_exact_canonical_message_projection');
        foreach([0,51]as$badLimit)$reject(fn()=>$operator->operatorStudyPage($operatorAuthority,[],$badLimit),'invalid_arguments');
        foreach([
            ['unexpected'=>'x'],array_diff_key($operatorPosition,['f'=>true]),
            array_replace($operatorPosition,['a'=>120]),array_replace($operatorPosition,['a'=>'0120']),
            array_replace($operatorPosition,['a'=>'0']),array_replace($operatorPosition,['scope_digest'=>'invalid'])
        ]as$badPosition)$reject(fn()=>$operator->operatorStudyPage($operatorAuthority,$badPosition,2),'invalid_arguments');
        $badFence=$operatorPosition;$badFence['f']='999999999';
        $reject(fn()=>$operator->operatorStudyPage($operatorAuthority,$badFence,2),'source_changed');
        $badScope=$operatorPosition;$badScope['scope_digest']=str_repeat('0',64);
        $reject(fn()=>$operator->operatorStudyPage($operatorAuthority,$badScope,2),'permission_changed');
        $pinned=$operatorFirst['next_position'];
        $pdo->exec("UPDATE telegram_archive_accounts SET approved=0 WHERE account_id=120");
        $reject(fn()=>$operator->operatorStudyPage($operatorAuthority,$pinned,2),'forbidden');
        $pdo->exec("UPDATE telegram_archive_accounts SET approved=1 WHERE account_id=120");
        $pdo->exec("UPDATE telegram_archive_accounts SET approved=0 WHERE account_id=121");
        $reject(fn()=>$operator->operatorStudyPage($operatorAuthority,$pinned,2),'permission_changed');
        $pdo->exec("UPDATE telegram_archive_accounts SET approved=1 WHERE account_id=121");
        $guardCalls=0;
        $reject(fn()=>$operator->operatorStudyPage(static function()use(&$guardCalls):void{
            if(++$guardCalls===2)throw new RuntimeException('private_authority_failure');
        },[],1),'forbidden');
        $check($guardCalls===2&&!$pdo->inTransaction(),'operator_authority_revocation_after_snapshot_withholds_the_page');
        $permissionCalls=0;
        $reject(fn()=>$operator->operatorStudyPage(static function()use($pdo,&$permissionCalls):void{
            if($pdo->inTransaction())throw new RuntimeException('authority_in_snapshot');
            if(++$permissionCalls===2)$pdo->exec("UPDATE telegram_archive_accounts SET approved=0 WHERE account_id=121");
        },[],1),'permission_changed');
        $pdo->exec("UPDATE telegram_archive_accounts SET approved=1 WHERE account_id=121");
        $check(!$pdo->inTransaction(),'operator_current_account_policy_is_rechecked_outside_the_snapshot');
        $pdo->beginTransaction();$reject(fn()=>$operator->operatorStudyPage($operatorAuthority,[],1),'transaction_active');
        $check($pdo->inTransaction(),'operator_does_not_close_a_callers_transaction');$pdo->rollBack();
        ArchiveResearchTraceStatement::$failureNeedle='SELECT m.id,m.record_json';
        ArchiveResearchTraceStatement::$failure=new PDOException('PrivateOperatorFixtureMessage');
        $reject(fn()=>$operator->operatorStudyPage($operatorAuthority,[],1),'unavailable');
        ArchiveResearchTraceStatement::$failure=null;ArchiveResearchTraceStatement::$failureNeedle=null;
        $check(!$pdo->inTransaction()&&$operatorBefore===$fingerprint(),
            'operator_source_failure_is_closed_and_preserves_source_records');

        // Canonical redaction can expand source text; private output still advances only a bounded prefix.
        $pdo->exec("UPDATE telegram_archive_accounts SET provider_enabled=0");
        $pdo->exec("INSERT INTO telegram_archive_accounts VALUES('anytour','OperatorLarge',140,'operator-large',1,1,NULL)");
        $pdo->exec("INSERT INTO telegram_dialogue_connections VALUES('anytour','OperatorLarge','operator-large',140,1)");
        $seed(40001,140,60000,1,str_repeat('@abcd ',45000));
        $seed(40002,140,60000,2,str_repeat('@abcd ',45000));
        $seed(40003,140,60001,1,str_repeat('@abcd ',90000));
        $largeBefore=$fingerprint();$position=[];$largeRows=[];$largePages=0;$firstLarge=null;
        do{
            $large=$operator->operatorStudyPage($operatorAuthority,$position,2);$largePages++;
            if($firstLarge===null)$firstLarge=$large;
            $bytes=strlen(json_encode($large,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
            $check($bytes<=2097152&&$large['source_json_bytes']<=1048576,
                'operator_private_output_remains_bounded_after_real_canonical_redaction');
            foreach($large['windows']as$window)foreach($window['messages']as$item)$largeRows[]=$item;
            $position=$large['next_position'];
            if($largePages>8)throw new RuntimeException('operator_large_fixture_did_not_advance');
        }while($large['has_more']);
        $check($firstLarge['returned_records']===1&&$firstLarge['next_position']['n']==='1'
            &&$firstLarge['windows'][0]['chat_window_complete']===false
            &&strlen(json_encode($firstLarge,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))>65536,
            'operator_output_trim_rewinds_to_returned_prefix_without_widening_MCP_wire_limit');
        $check(count($largeRows)===3&&count(array_unique(array_column($largeRows,'source_ref')))===3
            &&$largeRows[0]['read_status']==='available'&&$largeRows[1]['read_status']==='available'
            &&$largeRows[2]['read_status']==='response_record_limit'&&$largeRows[2]['text']===null
            &&$largeBefore===$fingerprint()&&!$pdo->inTransaction(),
            'one_overlarge_projected_record_remains_an_explicit_unknown_and_does_not_hide_its_tail');
        $largeMeta=$service->inventory(1,$connection,['limit'=>1]);
        $publicHistory=$service->history(1,$connection,['chat_ref'=>$largeMeta['chats'][0]['chat_ref'],'limit'=>1]);
        $check(strlen(json_encode($publicHistory,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))<=65536
            &&$publicHistory['messages'][0]['read_status']==='record_exceeds_page_budget'&&$publicHistory['messages'][0]['text']===null,
            'existing_OAuth_history_keeps_its_original_wire_and_source_byte_caps');

        $checks+=archivePredictiveStudyStoreRegression();
        echo 'Archive research read checks: '.$checks." passed.\n";
        return$checks;
    }finally{
        if($pdo->inTransaction())$pdo->rollBack();
        if(!$mysql)$pdo->exec('PRAGMA query_only=OFF');
        ArchiveResearchTraceStatement::$failure=null;ArchiveResearchTraceStatement::$failureNeedle=null;ArchiveResearchTraceStatement::$duringExecute=null;
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,$statementClass);
        $drop();
    }
}
if(realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){
    if(!extension_loaded('pdo_sqlite'))throw new RuntimeException('archive_fixture_sqlite_required');
    archiveResearchReadRegression(new PDO('sqlite::memory:'));
}
