<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/services/MonthlyReplyQualityReadService.php';

/** Additional synthetic fixture, invoked by the existing SQLite/native-MySQL reader entry. */
function monthlyReplyQualityReadRegression(PDO $pdo): int
{
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    if($mysql&&preg_match('/\Amaxbot_prepared_test(?:_[A-Za-z0-9_]+)?\z/D',(string)$pdo->query('SELECT DATABASE()')->fetchColumn())!==1)
        throw new RuntimeException('monthly_quality_fixture_database_rejected');
    $tables=['telegram_dialogue_messages','telegram_archive_accounts','telegram_dialogue_connections',
        'modeled_conversion_profile_versions','manager_prepared_reply_input_links','manager_prepared_reply_inputs','manager_ai_draft_inputs',
        'manager_ai_drafts','manager_prepared_replies','virtual_reply_sampling_claims','virtual_reply_case_sampling',
        'virtual_reply_sampling_aliases','virtual_reply_sampling_groups','virtual_reply_jobs','virtual_reply_cases'];
    $drop=static function()use($pdo,$tables):void{foreach($tables as $table)$pdo->exec('DROP TABLE IF EXISTS '.$table);};
    $drop();$count=0;
    $check=static function(bool $ok,string $name)use(&$count):void{if(!$ok)throw new RuntimeException('monthly_quality_failed:'.$name);$count++;echo 'monthly quality PASS '.$name."\n";};
    $reject=static function(callable $call,string $reason)use($check):void{
        try{$call();$check(false,'expected_'.$reason);}catch(DialogueReviewReadException $e){$check($e->reason===$reason,'reject_'.$reason);}
    };
    $long=$mysql?'LONGTEXT':'TEXT';$engine=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':'';
    $json=static fn(array $v):string=>json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    try{
        ProjectConfig::resetForTests(['id'=>'anytour']);
        $pdo->exec("CREATE TABLE telegram_archive_accounts(project_key VARCHAR(64),bot_key VARCHAR(96),account_id BIGINT,connection_id VARCHAR(64),approved INTEGER,provider_enabled INTEGER,manager_id BIGINT)$engine");
        $pdo->exec("CREATE TABLE telegram_dialogue_connections(project_key VARCHAR(64),bot_key VARCHAR(96),connection_id VARCHAR(64),account_id BIGINT,is_enabled INTEGER)$engine");
        $pdo->exec("CREATE TABLE telegram_dialogue_messages(id BIGINT PRIMARY KEY,project_key VARCHAR(64),account_id BIGINT,chat_id BIGINT,message_id BIGINT,version_at BIGINT,source_kind VARCHAR(32),is_deleted INTEGER,record_json $long)$engine");
        $pdo->exec('CREATE UNIQUE INDEX uq_telegram_dialogue_message ON telegram_dialogue_messages(project_key,account_id,chat_id,message_id)');
        $pdo->exec("INSERT INTO telegram_archive_accounts VALUES('anytour','MonthlyArchiveFixture',8100,'monthly-fixture',1,1,NULL)");
        $pdo->exec("INSERT INTO telegram_dialogue_connections VALUES('anytour','MonthlyArchiveFixture','monthly-fixture',8100,1)");
        $pdo->exec("CREATE TABLE manager_ai_drafts(draft_id VARCHAR(32) PRIMARY KEY,conversation_id BIGINT,manager_id BIGINT,project_key VARCHAR(64),context_revision VARCHAR(64),instruction_revision VARCHAR(64),latest_message_id BIGINT,proposal_json $long,created_at VARCHAR(32),preparation_job_id VARCHAR(64))$engine");
        $pdo->exec("CREATE TABLE manager_prepared_replies(job_id VARCHAR(64) PRIMARY KEY,conversation_id BIGINT,manager_id BIGINT,project_key VARCHAR(64),context_revision VARCHAR(64),instruction_revision VARCHAR(64),latest_message_id BIGINT,status VARCHAR(20),proposal_json $long,proposal_sha256 VARCHAR(64),prepared_at VARCHAR(32))$engine");
        $pdo->exec("CREATE TABLE manager_ai_draft_inputs(draft_id VARCHAR(32) PRIMARY KEY,input_json $long,input_sha256 VARCHAR(64))$engine");
        $pdo->exec("CREATE TABLE manager_prepared_reply_inputs(receipt_id VARCHAR(64) PRIMARY KEY,job_id VARCHAR(64),packet_json $long,packet_sha256 VARCHAR(64))$engine");
        $pdo->exec("CREATE TABLE manager_prepared_reply_input_links(job_id VARCHAR(64) PRIMARY KEY,receipt_id VARCHAR(64))$engine");
        $pdo->exec("CREATE TABLE virtual_reply_cases(case_id VARCHAR(64) PRIMARY KEY,project_key VARCHAR(64),source_kind VARCHAR(32),source_id BIGINT,source_revision VARCHAR(64),state VARCHAR(20),reference_time VARCHAR(20),payload_json $long)$engine");
        $pdo->exec("CREATE TABLE virtual_reply_jobs(case_id VARCHAR(64),role VARCHAR(20),status VARCHAR(20),completed_at VARCHAR(32),instruction_revision VARCHAR(64),result_json $long,result_sha256 VARCHAR(64),instructions $long,profile_json $long,schema_json $long,PRIMARY KEY(case_id,role))$engine");
        $pdo->exec("CREATE TABLE virtual_reply_sampling_groups(group_key VARCHAR(64) PRIMARY KEY,cohort VARCHAR(32))$engine");
        $pdo->exec("CREATE TABLE virtual_reply_case_sampling(case_id VARCHAR(64) PRIMARY KEY,group_key VARCHAR(64))$engine");
        $pdo->exec("CREATE TABLE virtual_reply_sampling_aliases(alias_key VARCHAR(64))$engine");
        $pdo->exec("CREATE TABLE virtual_reply_sampling_claims(case_id VARCHAR(64))$engine");
        foreach([9001,9002] as $id){
            $pdo->prepare('INSERT INTO customer_channels VALUES(?,?,?,?,?,?)')->execute([$id,1000+$id,'anytour','max',(string)(90000+$id),(string)(90000+$id)]);
            $pdo->prepare('INSERT INTO conversations VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$id,1000+$id,$id,'anytour','max',(string)(90000+$id),$id===9002?'closed':'ai',null,1,0,'2026-11-01 10:00:00','2026-11-01 10:00:00','2026-11-01 10:00:00']);
        }
        $pdo->prepare('INSERT INTO messages VALUES(?,?,?,?,?,?,?,?)')->execute([9000000,9001,'inbound','customer','max','Синтетический запрос', $json(['type'=>'message']), '2026-11-01 10:00:00']);
        $a=str_repeat('a',32);$b=str_repeat('b',32);$closed=str_repeat('c',32);$prepared=str_repeat('d',64);
        $proposal=$json(['ok'=>true,'should_draft'=>true,'draft'=>'Синтетическое предложение.','next_action'=>'clarify','checks'=>[]]);
        foreach([[$a,9001,'2026-11-01 10:00:00'],[$b,9001,'2026-11-02 10:00:00'],[$closed,9002,'2026-11-03 10:00:00'],[str_repeat('e',32),9001,'2026-09-01 10:00:00']]as[$id,$cid,$at])
            $pdo->prepare('INSERT INTO manager_ai_drafts VALUES(?,?,?,?,?,?,?,?,?,?)')->execute([$id,$cid,2,'anytour',str_repeat('1',64),str_repeat('2',64),9000000,$proposal,$at,null]);
        $pdo->prepare('INSERT INTO manager_prepared_replies VALUES(?,?,?,?,?,?,?,?,?,?,?)')->execute([$prepared,9001,2,'anytour',str_repeat('1',64),str_repeat('2',64),9000000,'ready',$proposal,hash('sha256',$proposal),'2026-11-01 11:00:00']);
        $input=$json(['text'=>str_repeat('🌴',6000),'access_token'=>'SYNTHETIC_CREDENTIAL_MUST_NOT_DISCLOSE']);
        $pdo->prepare('INSERT INTO manager_ai_draft_inputs VALUES(?,?,?)')->execute([$a,$input,hash('sha256',$input)]);
        $oversized=$json(['text'=>str_repeat('x',1048576)]);$pdo->prepare('INSERT INTO manager_ai_draft_inputs VALUES(?,?,?)')->execute([$b,$oversized,hash('sha256',$oversized)]);
        $receipt=str_repeat('f',64);$packet=$json(['context'=>['messages'=>[['id'=>9000000,'text'=>'Синтетический замороженный ввод']]],'meaning'=>'normalized_not_external_wire']);
        $pdo->prepare('INSERT INTO manager_prepared_reply_inputs VALUES(?,?,?,?)')->execute([$receipt,$prepared,$packet,hash('sha256',$packet)]);
        $pdo->prepare('INSERT INTO manager_prepared_reply_input_links VALUES(?,?)')->execute([$prepared,$receipt]);
        foreach([['3','development'],['4','reserved']] as [$digit,$cohort]){
            $case=str_repeat($digit,64);$group=str_repeat($digit,64);
            $pdo->prepare('INSERT INTO virtual_reply_cases VALUES(?,?,?,?,?,?,?,?)')->execute([$case,'anytour','conversation',9000000,str_repeat('5',64),'active','2026-11-01T10:00:00Z',$json(['messages'=>[['id'=>9000000,'text'=>'Синтетический исходный вопрос']],'history_incomplete'=>false])]);
            $pdo->prepare('INSERT INTO virtual_reply_jobs VALUES(?,?,?,?,?,?,?,?,?,?)')->execute([$case,'author','completed','2026-11-01 12:00:00',str_repeat('6',64),$proposal,hash('sha256',$proposal),'Synthetic frozen instructions','{}','{}']);
            $pdo->prepare('INSERT INTO virtual_reply_sampling_groups VALUES(?,?)')->execute([$group,$cohort]);
            $pdo->prepare('INSERT INTO virtual_reply_case_sampling VALUES(?,?)')->execute([$case,$group]);
        }

        $ascii=$mysql?' CHARACTER SET ascii COLLATE ascii_bin':'';
        $pdo->exec("CREATE TABLE modeled_conversion_profile_versions(id BIGINT PRIMARY KEY,project_key VARCHAR(40)$ascii,platform VARCHAR(16)$ascii,customer_key VARCHAR(40)$ascii,conversation_id VARCHAR(40)$ascii,observed_at_unix BIGINT,profile_json $long)$engine");
        $pdo->exec("ALTER TABLE conversation_events ADD COLUMN actor_type VARCHAR(16)");
        $pdo->exec("ALTER TABLE conversation_events ADD COLUMN payload_json $long");
        $subject=['project_key'=>'anytour','platform'=>'max','customer_key'=>'10002','conversation_id'=>'9002'];
        $at='2026-11-01T10:00:00Z';
        $prediction=(new ModeledConversionSemanticPrediction($subject,$at,$at,$at,'1','1'))->finish([]);
        $wrapper=['subject'=>$subject,'access_status'=>'allowed','profile'=>['prediction_at'=>$at],
            'semantic_prediction'=>$prediction,'contextual_signals'=>['coverage'=>['window_limited'=>true,
                'prediction_source_continuity'=>['unavailable_event_barriers'=>2]]]];
        $pdo->prepare('INSERT INTO modeled_conversion_profile_versions VALUES(?,?,?,?,?,?,?)')
            ->execute([9002,'anytour','max','10002','9002',1793527200,$json($wrapper)]);
        for($i=0;$i<52;$i++)$pdo->prepare('INSERT INTO conversation_events(id,conversation_id,event_type,actor_type,payload_json) VALUES(?,?,?,?,?)')
            ->execute([9000001+$i,9002,'synthetic_service_event','system',$json(ConversationRecordClock::stamp(
                ['credential'=>'SYNTHETIC_EVENT_PAYLOAD_MUST_NOT_COPY'],1793527200+$i))]);
        $pdo->prepare('INSERT INTO conversation_events(id,conversation_id,event_type,actor_type,payload_json) VALUES(?,?,?,?,?)')
            ->execute([9999999,9001,'foreign_native_event','customer','{}']);

        $now=1793793600;$clock=static fn():int=>$now;$key=str_repeat('q',32);$connection=str_repeat('c',32);
        $nativeMarker=['schema_version'=>1,'producer'=>'TelegramDialogueNormalizer.v1',
            'source_format'=>'telegram_bot_api_message','kind'=>'text','text_origin'=>'unknown'];
        foreach([[9100000,8800,20,'desktop_import','ARCHIVE_DESKTOP_ANCHOR'],
            [9100001,8800,21,'business_message','ARCHIVE_OLDER_SIBLING'],
            [9100002,90000008800,2,'business_message','ARCHIVE_SECOND_SELECTED'],
            [9100003,90000008800,1,'business_message','ARCHIVE_NEWEST_SELECTED +7 999 123-45-67']] as [$id,$chat,$messageId,$source,$text]){
            $record=['account_id'=>'8100','chat_id'=>(string)$chat,'message_id'=>(string)$messageId,
                'sender_id'=>(string)$chat,'sender_type'=>'customer','direction'=>'inbound',
                'created_at'=>$now-30,'version_at'=>$now-10,'text'=>$text,'links'=>[],'attachments'=>[],
                'reply_to_message_id'=>null,'is_forwarded'=>false,'is_deleted'=>false,
                'source_kind'=>$source,'content_coverage'=>'text_or_caption','bot_key'=>'MonthlyArchiveFixture'];
            if($source==='business_message')$record['native_content']=$nativeMarker;
            $pdo->prepare('INSERT INTO telegram_dialogue_messages VALUES(?,?,?,?,?,?,?,?,?)')
                ->execute([$id,'anytour',8100,$chat,$messageId,$record['version_at'],$source,0,$json($record)]);
        }
        $service=new MonthlyReplyQualityReadService($pdo,$clock,static fn():string=>$key);
        $fingerprint=static function()use($pdo,$tables):string{$rows=[];foreach([...$tables,'messages','conversations','customer_channels','conversation_events','manager_conversation_reads'] as $table)$rows[$table]=$pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);return hash('sha256',serialize($rows));};
        $before=$fingerprint();$first=$service->inventory(1,$connection,['cursor'=>'qh.manual.start','limit'=>1]);
        $check(count($first['records'])===1&&$first['has_more']===true&&$first['quality_period']['timezone']==='Europe/Moscow','manual_keyset_starts_bounded_moscow_month');
        $records=$first['records'];$page=$first;
        while($page['has_more']){$page=$service->inventory(1,$connection,['cursor'=>$page['next_cursor'],'limit'=>1]);$records=array_merge($records,$page['records']);}
        $check(array_column($records,'record_id')===[$a,$b,$closed],'all_retained_monthly_manual_versions_including_closed_without_old_rows');
        $readAll=static function(string $ref)use($service,$connection,$check):array{
            $page=$service->context(1,$connection,['dialogue_ref'=>$ref]);$bytes='';$chunks=0;$projection=null;$inputRef=$page['input_ref']??null;
            while(true){
                if(($page['availability']??null)!=='available')return[$page,null,$chunks,$inputRef];
                $part=$page['content'];$check(preg_match('//u',$part['fragment'])===1&&$part['offset_bytes']===strlen($bytes),'json_fragment_is_utf8_and_contiguous');
                if($projection!==null)$check($part['projection_sha256']===$projection,'projection_stable_across_chunks');
                $projection=$part['projection_sha256'];$bytes.=$part['fragment'];$chunks++;
                if(!$page['has_more'])break;
                $page=$service->context(1,$connection,['dialogue_ref'=>$ref,'cursor'=>$page['next_cursor']]);
            }
            $check(strlen($bytes)===$page['content']['total_bytes']&&hash('sha256',$bytes)===$projection,'complete_projection_reassembled_exactly');
            return[$page,json_decode($bytes,true,64,JSON_THROW_ON_ERROR),$chunks,$inputRef];
        };
        [$answer,$value,$chunks,$inputRef]=$readAll($records[0]['record_ref']);
        $check($value['draft']==='Синтетическое предложение.'&&$answer['customer_sends']===0,'retained_answer_only_no_generation_or_send');
        [$inputPage,$inputValue,$inputChunks]=$readAll($inputRef);
        $check($inputChunks>1&&$inputValue['text']===str_repeat('🌴',6000)&&$inputValue['access_token']==='[credential omitted]','full_unicode_input_paged_and_credentials_minimized');
        [$ignored,$ignoredValue,$ignoredChunks,$tooBigRef]=$readAll($records[1]['record_ref']);
        DialogueReviewTraceStatement::$payloadBytes=0;
        $tooBig=$service->context(1,$connection,['dialogue_ref'=>$tooBigRef]);
        $check($tooBig['availability']==='oversized_retained_context'&&DialogueReviewTraceStatement::$payloadBytes===0,'oversized_context_refused_before_payload_copy');
        $p=$service->inventory(1,$connection,['cursor'=>'qh.prepared.start']);
        $check(count($p['records'])===1,'retained_prepared_answer_does_not_depend_on_pending_queue');
        [$pr,$pv,$pc,$pi]=$readAll($p['records'][0]['record_ref']);[$ip,$iv]=$readAll($pi);
        $check($iv['context']['messages'][0]['text']==='Синтетический замороженный ввод','prepared_history_reads_original_linked_input_without_recapture');
        $virtual=$service->inventory(1,$connection,['cursor'=>'qh.virtual.start']);
        $check(array_column($virtual['records'],'record_id')===[str_repeat('3',64)],'completed_author_without_reviewer_visible_reserved_control_withheld');
        [$vr,$vv,$vc,$vi]=$readAll($virtual['records'][0]['record_ref']);[$vip,$viv]=$readAll($vi);
        $check($viv['payload']['messages'][0]['text']==='Синтетический исходный вопрос'&&$viv['retained_author_contract']['instructions']==='Synthetic frozen instructions','virtual_history_retains_frozen_prefix_and_contract_not_future_thread');
        $native=$service->inventory(1,$connection,['cursor'=>'qh.native.start','limit'=>25]);$nativeRecords=$native['records'];
        while($native['has_more']){$native=$service->inventory(1,$connection,['cursor'=>$native['next_cursor'],'limit'=>25]);$nativeRecords=array_merge($nativeRecords,$native['records']);}
        $closedNative=array_values(array_filter($nativeRecords,static fn(array $r):bool=>($r['dialogue_id']??null)==='9002'));
        $check(count($closedNative)===1,'separate_history_mode_includes_closed_native_dialogue');
        $nr=$service->context(1,$connection,['dialogue_ref'=>$closedNative[0]['record_ref']]);
        $check(($nr['saved_profile']['availability']??null)==='not_requested','historical_reply_review_does_not_disclose_unrequested_forecast');

        // Exercise the real quality wrapper, including both independent grants, using returned references only.
        $archiveKey=str_repeat('a',32);$qualityAllowed=true;$archiveAllowed=true;
        $qualityProvider=static function()use(&$qualityAllowed,$key):string{if(!$qualityAllowed)throw new DomainException();return$key;};
        $archiveProvider=static function()use(&$archiveAllowed,$archiveKey):string{if(!$archiveAllowed)throw new DomainException();return$archiveKey;};
        $archiveService=new MonthlyReplyQualityReadService($pdo,$clock,$qualityProvider,$archiveProvider);
        $archiveInventory=$archiveService->inventory(1,$connection,['cursor'=>'qh.archive.start','limit'=>1]);
        $recentDescriptor=$archiveInventory['newest_retained']??[];
        $check(is_string($recentDescriptor['start_cursor']??null)&&str_starts_with($recentDescriptor['start_cursor'],'qh.')
            &&($recentDescriptor['order']??null)==='archive_record_id_desc'
            &&($recentDescriptor['clock_basis']??null)==='stored_record_order_not_receipt_time'
            &&($recentDescriptor['existing_row_edits_are_not_reordered']??null)===true,
            'archive_recent_descriptor_is_wrapped_and_does_not_claim_receipt_or_edit_order');
        $recent=$archiveService->inventory(1,$connection,['cursor'=>$recentDescriptor['start_cursor'],'limit'=>1]);
        $selected=$recent['records'][0]??[];$selectedRef=$selected['record_ref']??'';
        $recentJson=$json($recent);
        $check(count($recent['records']??[])===1&&($selected['source_ref']??null)==='archive_message:9100003'
            &&is_string($selectedRef)&&str_starts_with($selectedRef,'qh.')
            &&!isset($selected['message_ref'])&&!isset($recent['recent_business_records'])
            &&!str_contains($recentJson,'ARCHIVE_NEWEST_SELECTED')&&!str_contains($recentJson,'+7 999')
            &&$recent['has_more']===true&&str_starts_with($recent['next_cursor'],'qh.'),
            'archive_recent_metadata_wraps_exact_business_record_and_continuation_without_text');
        $exact=$archiveService->context(1,$connection,['dialogue_ref'=>$selectedRef,'limit'=>100]);
        $exactMessage=$exact['messages'][0]??[];
        $check(count($exact['messages']??[])===1&&($exactMessage['source_ref']??null)==='archive_message:9100003'
            &&($exactMessage['native_message_id']??null)==='1'&&($exactMessage['read_status']??null)==='available'
            &&($exactMessage['native_content']??null)===$nativeMarker&&($exactMessage['role_verified']??null)===true
            &&($exactMessage['role']??null)==='customer'&&($exactMessage['direction']??null)==='inbound'
            &&str_starts_with($exactMessage['text']??'','ARCHIVE_NEWEST_SELECTED')
            &&!str_contains($json($exact),'ARCHIVE_SECOND_SELECTED')&&!str_contains($json($exact),'+7 999'),
            'wrapped_exact_archive_read_selects_business_only_chat_small_message_id_and_preserves_projection');
        $check(($exact['selection_scope']??null)==='selected_record_only'
            &&$exact['stored_records_within_fence']===1&&$exact['history_complete']===false
            &&$exact['has_more']===false&&$exact['next_cursor']===null&&$exact['page_complete']===true
            &&$exactMessage['historical_first_available_at']===null
            &&$exact['dialogue_ref']===$selectedRef&&$exact['quality_period']['timezone']==='Europe/Moscow',
            'wrapped_exact_archive_read_keeps_single_record_scope_and_unknown_historical_availability');
        $recentIds=[$selected['source_ref']];$recentPage=$recent;
        while($recentPage['has_more']){
            $recentPage=$archiveService->inventory(1,$connection,['cursor'=>$recentPage['next_cursor'],'limit'=>1]);
            array_push($recentIds,...array_column($recentPage['records'],'source_ref'));
            if(count($recentIds)>3)throw new RuntimeException('monthly_recent_archive_paging_did_not_advance');
        }
        $check($recentIds===['archive_message:9100003','archive_message:9100002','archive_message:9100001']
            &&$recentPage['next_cursor']===null,'wrapped_archive_recent_pages_descend_without_desktop_rows_or_duplicates');
        $ordinaryArchive=new ArchiveResearchReadService($pdo,$clock,$archiveProvider);
        $ordinaryInventory=$ordinaryArchive->inventory(1,$connection,['limit'=>25]);
        $check(count($ordinaryInventory['chats'])===1&&!isset($ordinaryInventory['newest_retained']),
            'ordinary_archive_inventory_keeps_desktop_anchor_and_no_quality_recent_descriptor');
        $archiveAllowed=false;
        $reject(fn()=>$archiveService->inventory(1,$connection,['cursor'=>$recentDescriptor['start_cursor']]),'forbidden');
        $reject(fn()=>$archiveService->context(1,$connection,['dialogue_ref'=>$selectedRef]),'forbidden');
        $archiveAllowed=true;$qualityAllowed=false;
        $reject(fn()=>$archiveService->inventory(1,$connection,['cursor'=>$recentDescriptor['start_cursor']]),'forbidden');
        $reject(fn()=>$archiveService->context(1,$connection,['dialogue_ref'=>$selectedRef]),'forbidden');
        $qualityAllowed=true;$lateArchiveCalls=0;
        $lateArchiveService=new MonthlyReplyQualityReadService($pdo,$clock,$qualityProvider,
            static function()use(&$lateArchiveCalls,$archiveKey):string{if(++$lateArchiveCalls===3)throw new DomainException();return$archiveKey;});
        $reject(fn()=>$lateArchiveService->context(1,$connection,['dialogue_ref'=>$selectedRef]),'permission_changed');
        $lateQualityCalls=0;
        $lateQualityService=new MonthlyReplyQualityReadService($pdo,$clock,
            static function()use(&$lateQualityCalls,$key):string{if(++$lateQualityCalls===3)throw new DomainException();return$key;},$archiveProvider);
        $reject(fn()=>$lateQualityService->context(1,$connection,['dialogue_ref'=>$selectedRef]),'permission_changed');
        $check($lateArchiveCalls===3&&$lateQualityCalls===3,'exact_archive_context_rechecks_both_grants_after_source_read');
        $forecastArchive=new MonthlyReplyQualityReadService($pdo,$clock,$qualityProvider,$archiveProvider,true);
        $reject(fn()=>$forecastArchive->inventory(1,$connection,['cursor'=>$recentDescriptor['start_cursor']]),'invalid_arguments');
        $reject(fn()=>$forecastArchive->context(1,$connection,['dialogue_ref'=>$selectedRef]),'invalid_arguments');

        $review=new MonthlyReplyQualityReadService($pdo,$clock,static fn():string=>$key,null,true);
        $rp=$review->inventory(1,$connection,['cursor'=>'qh.native.start','limit'=>25]);
        $check(is_string($rp['newest_retained']['start_cursor']??null)
            &&str_starts_with($rp['newest_retained']['start_cursor'],'qh.'),'retained_newest_descriptor_is_wrapped_in_the_quality_family');
        $latest=$review->inventory(1,$connection,['cursor'=>$rp['newest_retained']['start_cursor'],'limit'=>2]);
        // The parent reader fixture retains a valid maximum-BIGINT subject above9002.
        $record=array_values(array_filter($latest['records'],static fn(array $r):bool=>($r['dialogue_id']??null)==='9002'))[0]??[];
        $check(($record['dialogue_id']??null)==='9002'&&$record['status']==='closed'
            &&$record['saved_profile_ref']===hash('sha256','intake_profile_v1:9002'),
            'retained_review_joins_exact_closed_subject_to_anonymous_saved_profile_reference');
        $context=$review->context(1,$connection,['dialogue_ref'=>$record['record_ref']]);
        $check(($context['saved_profile']['availability']??null)==='available'
            &&($context['saved_profile']['input_diagnostics']['continuity']['unavailable_event_barriers']??null)===2,
            'dual_read_mode_returns_only_the_valid_bound_saved_forecast_and_fixed_coverage');
        $events=$context['source_event_diagnostics']??[];
        $check(count($events['events']??[])===50&&$events['complete_within_bound']===false
            &&$events['payloads_copied']===false&&!str_contains(json_encode($context),'SYNTHETIC_EVENT_PAYLOAD_MUST_NOT_COPY')
            &&!str_contains(json_encode($events),'foreign_native_event'),
            'bounded_event_metadata_keeps_scope_clock_and_omissions_without_payloads');
        $ordinary=$service->context(1,$connection,['dialogue_ref'=>$record['record_ref']]);
        $check(!isset($ordinary['source_event_diagnostics'])&&$ordinary['saved_profile']['availability']==='not_requested',
            'existing_quality_only_context_does_not_gain_forecast_or_event_diagnostics');
        $reject(fn()=>$review->inventory(1,$connection,['cursor'=>'qh.manual.start']),'invalid_arguments');
        $reject(fn()=>$review->context(1,$connection,['dialogue_ref'=>$records[0]['record_ref']]),'invalid_arguments');
        $revokedCalls=0;
        $revokedReview=new MonthlyReplyQualityReadService($pdo,$clock,static function()use(&$revokedCalls,$key):string{
            if(++$revokedCalls===3)throw new DomainException();return$key;
        },null,true);
        $reject(fn()=>$revokedReview->context(1,$connection,['dialogue_ref'=>$record['record_ref']]),'permission_changed');

        $reject(fn()=>$service->context(2,$connection,['dialogue_ref'=>$records[0]['record_ref']]),'forbidden');
        $reject(fn()=>$service->context(1,str_repeat('d',32),['dialogue_ref'=>$records[0]['record_ref']]),'stale_cursor');
        $bad=$records[0]['record_ref'];$bad[strlen($bad)-1]=$bad[strlen($bad)-1]==='0'?'1':'0';
        $reject(fn()=>$service->context(1,$connection,['dialogue_ref'=>$bad]),'stale_cursor');
        $reject(fn()=>$service->inventory(1,$connection,['cursor'=>'qh.archive.start']),'forbidden');
        $calls=0;$lateDeny=new MonthlyReplyQualityReadService($pdo,$clock,static function()use(&$calls,$key):string{if(++$calls===2)throw new DomainException();return$key;});
        $reject(fn()=>$lateDeny->context(1,$connection,['dialogue_ref'=>$records[0]['record_ref']]),'forbidden');
        $check($fingerprint()===$before&&!$pdo->inTransaction(),'all_history_reads_preserve_sources_jobs_quotas_and_markers');
        $firstPart=$service->context(1,$connection,['dialogue_ref'=>$inputRef]);
        $changed=$json(['text'=>str_repeat('🌵',6000),'access_token'=>'SYNTHETIC_CREDENTIAL_MUST_NOT_DISCLOSE']);
        $pdo->prepare('UPDATE manager_ai_draft_inputs SET input_json=?,input_sha256=? WHERE draft_id=?')->execute([$changed,hash('sha256',$changed),$a]);
        $reject(fn()=>$service->context(1,$connection,['dialogue_ref'=>$inputRef,'cursor'=>$firstPart['next_cursor']]),'source_changed');
        return$count;
    }finally{
        if($pdo->inTransaction())$pdo->rollBack();
        $drop();
        $pdo->exec('DELETE FROM messages WHERE id=9000000');
        $pdo->exec('DELETE FROM conversation_events WHERE id>=9000001');
        $pdo->exec('DELETE FROM conversations WHERE id IN (9001,9002)');
        $pdo->exec('DELETE FROM customer_channels WHERE id IN (9001,9002)');
        ProjectConfig::resetForTests(null);
    }
}
