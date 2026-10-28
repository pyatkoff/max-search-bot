<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/ArchiveResearchReadService.php';

/** Synthetic fixture only. Caller owns an isolated disposable native test database. */
function archiveResearchReadRegression(PDO $pdo): int
{
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    if($mysql&&!preg_match('/\Amaxbot_prepared_test(?:_[A-Za-z0-9_]+)?\z/D',(string)$pdo->query('SELECT DATABASE()')->fetchColumn()))
        throw new RuntimeException('archive_fixture_database_rejected');
    $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
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
        $pdo->exec("CREATE UNIQUE INDEX archive_fixture_native ON telegram_dialogue_messages(project_key,account_id,chat_id,message_id)");
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
        $before=$fingerprint();$inventory=$service->inventory(1,$connection,['limit'=>1]);
        $check($inventory['database_writes']===0&&$blockedWrite,'actual_readonly_transaction_rejects_write');
        $check(!$pdo->inTransaction()&&$keyCalls===2,'fresh_key_checks_outside_transaction');
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
        $final=$service->inventory(1,$connection,['limit'=>50]);
        $check(strlen(json_encode($final,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))<=ArchiveResearchReadService::MAX_RESPONSE_BYTES,'full_envelope_byte_cap');
        $check(!$pdo->inTransaction(),'successful_read_rolls_back');
        if(!$mysql)$check((int)$pdo->query('PRAGMA query_only')->fetchColumn()===0,'sqlite_query_only_restored');
        echo 'Archive research read checks: '.$checks." passed.\n";
        return$checks;
    }finally{
        if($pdo->inTransaction())$pdo->rollBack();
        if(!$mysql)$pdo->exec('PRAGMA query_only=OFF');
        $drop();
    }
}
if(realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){
    if(!extension_loaded('pdo_sqlite'))throw new RuntimeException('archive_fixture_sqlite_required');
    archiveResearchReadRegression(new PDO('sqlite::memory:'));
}
