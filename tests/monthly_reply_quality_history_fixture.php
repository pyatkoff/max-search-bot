<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/services/MonthlyReplyQualityReadService.php';

/** Additional synthetic fixture, invoked by the existing SQLite/native-MySQL reader entry. */
function monthlyReplyQualityReadRegression(PDO $pdo): int
{
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    if($mysql&&preg_match('/\Amaxbot_prepared_test(?:_[A-Za-z0-9_]+)?\z/D',(string)$pdo->query('SELECT DATABASE()')->fetchColumn())!==1)
        throw new RuntimeException('monthly_quality_fixture_database_rejected');
    $tables=['manager_prepared_reply_input_links','manager_prepared_reply_inputs','manager_ai_draft_inputs',
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
        $now=1793793600;$clock=static fn():int=>$now;$key=str_repeat('q',32);$connection=str_repeat('c',32);
        $service=new MonthlyReplyQualityReadService($pdo,$clock,static fn():string=>$key);
        $fingerprint=static function()use($pdo,$tables):string{$rows=[];foreach([...$tables,'messages','conversations','customer_channels'] as $table)$rows[$table]=$pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);return hash('sha256',serialize($rows));};
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
        $pdo->exec('DELETE FROM conversations WHERE id IN (9001,9002)');
        $pdo->exec('DELETE FROM customer_channels WHERE id IN (9001,9002)');
        ProjectConfig::resetForTests(null);
    }
}
