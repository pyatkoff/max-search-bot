<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$pdo=null;$safe=false;$tables=['virtual_reply_jobs','virtual_reply_cases','virtual_reply_cursors','virtual_reply_quotas','manager_assistant_decisions','manager_preparation_oauth_tokens','manager_preparation_oauth_codes','manager_preparation_oauth_grants','manager_preparation_oauth_clients','manager_preparation_events','manager_prepared_replies','manager_ai_drafts','telegram_dialogue_messages','customer_channels','conversations','managers'];
try{
    $dsn=(string)getenv('PREPARED_REPLY_MYSQL_DSN');
    if(preg_match('/\Amysql:.*(?:\Amysql:|;)dbname=(maxbot_prepared_test(?:_[a-zA-Z0-9_]+)?)(?:;|\z)/D',$dsn,$m)!==1)throw new RuntimeException('unsafe_fixture_database');
    $pdo=new PDO($dsn,getenv('PREPARED_REPLY_MYSQL_USER')?:null,getenv('PREPARED_REPLY_MYSQL_PASS')?:null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    if($pdo->query('SELECT DATABASE()')->fetchColumn()!==$m[1])throw new RuntimeException('unsafe_fixture_database');
    $safe=true;
    foreach($tables as $table)$pdo->exec('DROP TABLE IF EXISTS '.$table);
    $pdo->exec('CREATE TABLE managers(id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE conversations(id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
    foreach(['043_manager_ai_draft_feedback.sql','044_manager_prepared_replies.sql','045_manager_preparation_oauth.sql','046_manager_preparation_events.sql','047_prepared_reply_feedback.sql','048_manager_assistant_decisions.sql','049_virtual_reply_learning.sql']as $name){
        $pdo->exec(file_get_contents(dirname(__DIR__,2).'/migrations/'.$name));
        echo 'PASS native MySQL forward migration '.$name."\n";
    }
    $pdo->exec('INSERT INTO managers VALUES(1)');
    $pdo->exec('INSERT INTO conversations VALUES(1)');
    $feedback=$pdo->prepare('INSERT INTO manager_ai_drafts (draft_id,conversation_id,manager_id,project_key,context_revision,instruction_revision,latest_message_id,proposal_json,created_at,preparation_job_id) VALUES(?,?,?,?,?,?,?,?,?,?)');
    $feedback->execute([str_repeat('a',32),1,1,'anytour',str_repeat('b',64),str_repeat('c',64),2,'synthetic immutable proposal',gmdate('Y-m-d H:i:s'),str_repeat('d',64)]);
    $feedbackBefore=$pdo->query('SELECT * FROM manager_ai_drafts')->fetchAll();
    $pdo->exec(file_get_contents(dirname(__DIR__,2).'/migrations/047_prepared_reply_feedback.sql'));
    if($pdo->query('SELECT * FROM manager_ai_drafts')->fetchAll()!==$feedbackBefore)throw new RuntimeException('feedback_migration_retry_changed_data');
    echo "PASS native MySQL prepared feedback column and migration retry preserve provenance\n";
    $decision=$pdo->prepare('INSERT INTO manager_assistant_decisions (manager_id,conversation_id,project_key,recommendation_revision,context_revision,instruction_revision,decision,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)');
    $decision->execute([1,1,'anytour',str_repeat('1',64),str_repeat('2',64),str_repeat('3',64),'done',gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s')]);
    $decisionsBefore=$pdo->query('SELECT * FROM manager_assistant_decisions')->fetchAll();
    $pdo->exec(file_get_contents(dirname(__DIR__,2).'/migrations/048_manager_assistant_decisions.sql'));
    if($pdo->query('SELECT * FROM manager_assistant_decisions')->fetchAll()!==$decisionsBefore)throw new RuntimeException('assistant_migration_retry_changed_data');
    $duplicateRejected=false;
    try{$decision->execute([1,1,'anytour',str_repeat('1',64),str_repeat('2',64),str_repeat('3',64),'irrelevant',gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s')]);}catch(PDOException $error){$duplicateRejected=$error->getCode()==='23000';}
    if(!$duplicateRejected)throw new RuntimeException('assistant_duplicate_revision_allowed');
    echo "PASS native MySQL assistant migration retry and revision uniqueness\n";
    require_once dirname(__DIR__,2).'/services/VirtualReplyStore.php';
    $virtualReader=new class($pdo){
        public string $text='Египет, трое взрослых';
        public int $lastId=1;
        private PDO $pdo;
        public function __construct(PDO $pdo){$this->pdo=$pdo;}
        public function recent(string $source,int $limit):array{return $this->scan($source,0,$limit);}
        public function scan(string $source,int $after,int $limit):array{
            $ids=$source==='conversation'&&$limit>0?array_values(array_filter(range(1,$this->lastId),static fn(int $id):bool=>$id>$after)):[];
            $selected=array_slice($ids,0,$limit);
            return ['source_ids'=>$selected,'last_id'=>$selected?end($selected):$after,'has_more'=>count($ids)>$limit];
        }
        public function snapshot(string $source,int $id):?array{
            if($this->pdo->inTransaction())throw new RuntimeException('virtual_fixture_recheck_in_transaction');
            if($source!=='conversation'||$id<1||$id>$this->lastId)return null;
            $text=$id===1?$this->text:'Synthetic triage question '.$id;
            return ['project_key'=>'anytour','source_kind'=>$source,'source_id'=>$id,
                'source_revision'=>hash('sha256',$text),'reference_time'=>'2026-10-20T10:00:00Z','mode'=>'live',
                'evidence_message_ids'=>[$id],'payload'=>['messages'=>[['id'=>$id,'sender_type'=>'tourist','text'=>$text,
                    'created_at'=>'2026-10-20T10:00:00Z']],'history_incomplete'=>false,
                    'cutoff'=>['source_id'=>$id,'reference_time'=>'2026-10-20T10:00:00Z']]];
        }
    };
    $virtualNow=1792490400;
    $virtualStore=new VirtualReplyStore($pdo,$virtualReader,static function()use(&$virtualNow):int{return $virtualNow;});
    if($virtualStore->capture('conversation','fresh',10)['captured']!==1)throw new RuntimeException('virtual_native_capture_failed');
    $authorClaim=$virtualStore->claim('author',1,1)['jobs'][0]??null;
    if(!$authorClaim||$authorClaim['profile']!==VirtualReplyProtocol::profile('author'))throw new RuntimeException('virtual_native_author_claim_failed');
    $virtualCase=$authorClaim['case_id'];
    $authorContext=$virtualStore->context($virtualCase,'author',$authorClaim['claim_token'],1);
    if($authorContext['instructions']!==VirtualReplyProtocol::instructions('author')
        ||$authorContext['instruction_revision']!==VirtualReplyProtocol::revision('author'))throw new RuntimeException('virtual_native_author_contract_changed');
    $authorResult=['ok'=>true,'should_draft'=>true,'draft'=>'На какие даты планируете поездку?',
        'next_action'=>'clarify','reason'=>'Направление и состав известны, даты не названы.',
        'checks'=>[],'evidence_message_ids'=>[1],'history_incomplete'=>false];
    $authorMetadata=['model'=>'gpt-6.1-sol','reasoning_effort'=>'low','service_tier'=>'unknown'];
    $authorSaved=$virtualStore->save($virtualCase,'author',$authorClaim['claim_token'],1,$authorResult,$authorMetadata);
    $authorDuplicate=$virtualStore->save($virtualCase,'author',$authorClaim['claim_token'],1,$authorResult,$authorMetadata);
    if($authorSaved['duplicate']!==false||$authorDuplicate['duplicate']!==true
        ||$virtualStore->claim('reviewer',1,1)['jobs']!==[])throw new RuntimeException('virtual_native_author_save_or_delay_failed');
    $virtualNow+=60;
    $reviewerClaim=$virtualStore->claim('reviewer',1,1)['jobs'][0]??null;
    if(!$reviewerClaim||$reviewerClaim['profile']!==VirtualReplyProtocol::profile('reviewer')
        ||$reviewerClaim['profile']['model']!=='gpt-6-astra'
        ||$reviewerClaim['profile']['reasoning']['effort']!=='high')throw new RuntimeException('virtual_native_reviewer_claim_failed');
    $reviewerContext=$virtualStore->context($virtualCase,'reviewer',$reviewerClaim['claim_token'],1);
    if($reviewerContext['payload']['author_result']!==$authorResult
        ||$reviewerContext['instructions']!==VirtualReplyProtocol::instructions('reviewer'))throw new RuntimeException('virtual_native_reviewer_context_changed');
    $reviewerResult=['ok'=>true,'verdict'=>'pass','issues'=>[],'corrected_draft'=>null,'lesson_candidate'=>null];
    $virtualStore->save($virtualCase,'reviewer',$reviewerClaim['claim_token'],1,$reviewerResult,
        ['model'=>'gpt-6-astra','reasoning_effort'=>'high','service_tier'=>'unknown']);
    $virtualReview=$virtualStore->review($virtualCase);
    if($virtualReview['author_result']!==$authorResult||$virtualReview['review_result']!==$reviewerResult
        ||$virtualReview['author_metadata']['provider_verified']!==false
        ||$virtualReview['review_metadata']['provider_verified']!==false
        ||$virtualReview['author_metadata']['declared_profile']['service_tier']!=='unknown'
        ||$virtualReview['review_metadata']['declared_profile']['service_tier']!=='unknown'
        ||$virtualReview['author_metadata']['requested_profile']!==VirtualReplyProtocol::profile('author')
        ||$virtualReview['review_metadata']['requested_profile']!==VirtualReplyProtocol::profile('reviewer'))throw new RuntimeException('virtual_native_frozen_result_or_profile_changed');
    echo "PASS native MySQL virtual author and independent Astra review preserve exact frozen results and unknown actual tier\n";
    $pdo->prepare('UPDATE virtual_reply_jobs SET instruction_revision=? WHERE case_id=?')->execute([str_repeat('9',64),$virtualCase]);
    if($virtualStore->review($virtualCase)['author_result']!==$authorResult)throw new RuntimeException('virtual_native_instruction_change_lost_evidence');
    $virtualStore->disposition($virtualCase,['status'=>'applied','reason'=>'Synthetic instruction correction',
        'change_url'=>'https://github.com/pyatkoff/max-search-bot/pull/123','validation'=>'Synthetic separate-case and counterexample checks'],1);
    echo "PASS native MySQL instruction drift preserves completed lesson evidence and disposition\n";
    $virtualTables=['virtual_reply_cases'=>'id','virtual_reply_jobs'=>'case_id,role',
        'virtual_reply_cursors'=>'project_key,source_kind,lane','virtual_reply_quotas'=>'project_key,day_utc,role'];
    $virtualBefore=[];
    foreach($virtualTables as $table=>$order)$virtualBefore[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY '.$order)->fetchAll();
    $pdo->exec(file_get_contents(dirname(__DIR__,2).'/migrations/049_virtual_reply_learning.sql'));
    foreach($virtualTables as $table=>$order){
        if($pdo->query('SELECT * FROM '.$table.' ORDER BY '.$order)->fetchAll()!==$virtualBefore[$table])throw new RuntimeException('virtual_migration_retry_changed_data');
    }
    echo "PASS native MySQL virtual migration retry preserves cases, jobs, cursors and quotas\n";
    $virtualReader->text='Турция, трое взрослых';
    $virtualChanged=false;
    try{$virtualStore->review($virtualCase);}catch(RuntimeException $error){$virtualChanged=$error->getMessage()==='virtual_reply_source_changed';}
    $oldJobs=$pdo->prepare('SELECT COUNT(*) FROM virtual_reply_jobs WHERE case_id=?');$oldJobs->execute([$virtualCase]);
    $currentVirtual=$pdo->query('SELECT case_id,payload_json FROM virtual_reply_cases WHERE source_kind=\'conversation\' AND source_id=1')->fetch();
    if(!$virtualChanged||(int)$oldJobs->fetchColumn()!==0||!$currentVirtual||$currentVirtual['case_id']===$virtualCase
        ||$virtualStore->reviews(10)['reviews']!==[]
        ||json_decode($currentVirtual['payload_json'],true,64,JSON_THROW_ON_ERROR)['messages'][0]['text']!==$virtualReader->text)throw new RuntimeException('virtual_native_source_change_not_purged');
    echo "PASS native MySQL source revision change purges previous results and recaptures current context\n";
    // The recaptured source 1 and these new cases use fresh lifecycle attempts;
    // the original frozen results above are never rewritten for this fixture.
    $virtualReader->lastId=32;
    if($virtualStore->capture('conversation','fresh',100)['captured']!==31)throw new RuntimeException('virtual_native_triage_capture_failed');
    for($batch=0;$batch<2;$batch++)foreach($virtualStore->claim('author',1,20)['jobs']as $job){
        $context=$virtualStore->context($job['case_id'],'author',$job['claim_token'],1);
        $result=$authorResult;$result['evidence_message_ids']=[$context['source']['id']];
        $virtualStore->save($job['case_id'],'author',$job['claim_token'],1,$result,$authorMetadata);
        $virtualNow++;
    }
    $virtualNow+=60;$triageCases=[];
    for($batch=0;$batch<2;$batch++)foreach($virtualStore->claim('reviewer',1,20)['jobs']as $job){
        $context=$virtualStore->context($job['case_id'],'reviewer',$job['claim_token'],1);$id=$context['source']['id'];
        $result=$reviewerResult;
        if(in_array($id,[2,3,4,5,6,32],true))$result=['ok'=>true,'verdict'=>$id===3?'insufficient_context':'revise',
            'issues'=>[['category'=>'context','severity'=>'minor','explanation'=>'Synthetic bounded context finding.','evidence_message_ids'=>[$id]]],
            'corrected_draft'=>null,'lesson_candidate'=>null];
        $virtualStore->save($job['case_id'],'reviewer',$job['claim_token'],1,$result,
            ['model'=>'gpt-6-astra','reasoning_effort'=>'high','service_tier'=>'unknown']);
        $triageCases[$id]=$job['case_id'];$virtualNow++;
    }
    if(count($triageCases)!==32)throw new RuntimeException('virtual_native_triage_lifecycle_incomplete');
    foreach([4=>'rejected',5=>'duplicate',6=>'applied']as $id=>$status)$virtualStore->disposition($triageCases[$id],
        ['status'=>$status,'reason'=>'Synthetic reviewed disposition.','change_url'=>$status==='rejected'?null:'https://github.com/pyatkoff/max-search-bot/pull/123',
            'validation'=>'Synthetic separate-case validation.'],1);
    $triagePage=$virtualStore->reviews(20);$triageAll=$virtualStore->reviews(100);
    $triageOrder=array_merge([2,3,32],range(31,4),[1]);
    if(array_column($triagePage['reviews'],'source_id')!==array_slice($triageOrder,0,20)
        ||$triagePage['has_more']!==true||array_column($triageAll['reviews'],'source_id')!==$triageOrder
        ||$triageAll['has_more']!==false||$virtualStore->reviews(20)!==$triagePage)throw new RuntimeException('virtual_native_triage_order_or_page_failed');
    if(array_column($triageAll['reviews'],'requires_disposition')!==array_merge([true,true,true],array_fill(0,29,false))
        ||array_column(array_slice($triageAll['reviews'],0,3),'verdict')!==['revise','insufficient_context','revise']
        ||$triageAll['reviews'][3]['disposition']['status']!=='unresolved')throw new RuntimeException('virtual_native_triage_metadata_failed');
    echo "PASS native MySQL oldest unresolved findings precede twenty-five newer passes with stable pages and disposition metadata\n";
    // Extend only this disposable fixture after the migration/lifecycle checks.
    // Peers are synthetic; no transcript or native production identity is used.
    require_once dirname(__DIR__,2).'/services/InternalOwnerDialogueScope.php';
    $pdo->exec('ALTER TABLE conversations ADD project_key VARCHAR(64),ADD channel VARCHAR(32),ADD customer_id BIGINT UNSIGNED,ADD customer_channel_id BIGINT UNSIGNED');
    $pdo->exec('CREATE TABLE customer_channels(id BIGINT UNSIGNED PRIMARY KEY,customer_id BIGINT UNSIGNED,project_key VARCHAR(64),channel VARCHAR(32),external_user_id VARCHAR(191),external_chat_id VARCHAR(191)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE telegram_dialogue_messages(id BIGINT UNSIGNED PRIMARY KEY,project_key VARCHAR(64),account_id BIGINT UNSIGNED,chat_id BIGINT UNSIGNED) ENGINE=InnoDB');
    $pdo->exec("UPDATE conversations SET project_key='anytour',channel='telegram',customer_id=11,customer_channel_id=71 WHERE id=1;
        INSERT INTO conversations VALUES(2,'anytour','max',12,72),(101,'anytour','telegram',13,NULL),(102,'other','telegram',14,NULL);
        INSERT INTO customer_channels VALUES(71,11,'anytour','telegram','91001','91011'),(72,12,'anytour','max','93001','93011');
        INSERT INTO telegram_dialogue_messages VALUES(1,'anytour',50001,91001),(2,'anytour',50001,92002),(3,'anytour',50001,91011),(4,'other',50001,91001),(5,'anytour',50001,93001)");
    $internalConversation=InternalOwnerDialogueScope::conversationSql('c');
    if(array_map('intval',$pdo->query('SELECT c.id FROM conversations c WHERE NOT '.$internalConversation.' ORDER BY c.id')->fetchAll(PDO::FETCH_COLUMN))!==[101,102])throw new RuntimeException('internal_native_conversation_scope_failed');
    $contrasts="(SELECT 1 AS id,'other' AS project_key,'telegram' AS channel UNION ALL SELECT 1,'anytour','max' UNION ALL SELECT 2,'anytour','telegram') c";
    if((int)$pdo->query('SELECT COUNT(*) FROM '.$contrasts.' WHERE NOT '.$internalConversation)->fetchColumn()!==3)throw new RuntimeException('internal_native_scope_contrasts_failed');
    echo "PASS native MySQL internal conversation predicate preserves other project/channel tuples\n";
    $internalArchive=InternalOwnerDialogueScope::archiveSql('m');
    $eligibleArchive='SELECT m.id FROM telegram_dialogue_messages m WHERE NOT '.$internalArchive.' ORDER BY m.id';
    if(array_map('intval',$pdo->query($eligibleArchive.' LIMIT 1')->fetchAll(PDO::FETCH_COLUMN))!==[2]
        ||array_map('intval',$pdo->query($eligibleArchive)->fetchAll(PDO::FETCH_COLUMN))!==[2,4,5])throw new RuntimeException('internal_native_archive_scope_or_limit_failed');
    foreach(["customer_id=99","channel='max'","project_key='other'","external_user_id='091001',external_chat_id='091011'"]as $mismatch){
        $pdo->exec('UPDATE customer_channels SET '.$mismatch.' WHERE id=71');
        if(array_map('intval',$pdo->query($eligibleArchive)->fetchAll(PDO::FETCH_COLUMN))!==[1,2,3,4,5])throw new RuntimeException('internal_native_archive_binding_failed');
        $pdo->exec("UPDATE customer_channels SET customer_id=11,channel='telegram',project_key='anytour',external_user_id='91001',external_chat_id='91011' WHERE id=71");
    }
    echo "PASS native MySQL correlated archive CAST scope excludes only bound owner peers before LIMIT\n";
    $client=str_repeat('a',48);$family=str_repeat('b',32);
    $pdo->prepare('INSERT INTO manager_preparation_oauth_clients VALUES(?,?,?,?)')->execute([$client,'https://chatgpt.com/connector_platform_oauth_redirect',str_repeat('c',64),time()]);
    $pdo->prepare('INSERT INTO manager_preparation_oauth_grants VALUES(?,?,?,?,?,?,?,?,NULL)')->execute([$family,$client,1,'anytour','https://app.anytoour.ru/manager/ai-mcp.php','queue:read prepared:write',time(),time()+3600]);
    $pdo->prepare('INSERT INTO manager_preparation_oauth_codes VALUES(?,?,?,?,?,NULL)')->execute([str_repeat('d',64),$family,'https://chatgpt.com/connector_platform_oauth_redirect',str_repeat('e',43),time()+300]);
    $pdo->prepare('INSERT INTO manager_preparation_oauth_tokens VALUES(?,?,?,?,NULL)')->execute([str_repeat('f',64),$family,'access',time()+3600]);
    $jobs=json_encode(array_fill(0,200,str_repeat('1',64)),JSON_THROW_ON_ERROR);
    $q=$pdo->prepare('INSERT INTO manager_preparation_events (subscription_id,admin_id,oauth_connection_id,project_key,event_name,arguments_json,callback_url,signing_secret,verified_at,expires_at,observed_jobs_json,pending_jobs_json,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $now=gmdate('Y-m-d H:i:s');
    $q->execute(['sub_'.str_repeat('2',64),1,$family,'anytour','manager_replies.pending','{"project_key":"anytour"}','https://callback.example.com/event','whsec_'.base64_encode(str_repeat('x',32)),$now,gmdate('Y-m-d H:i:s',time()+3600),$jobs,$jobs,$now,$now]);
    if($pdo->query('SELECT LENGTH(observed_jobs_json)=LENGTH(pending_jobs_json) FROM manager_preparation_events')->fetchColumn()!=1)throw new RuntimeException('binary_roundtrip_failed');
    $pdo->exec('DELETE FROM managers WHERE id=1');
    if((int)$pdo->query('SELECT COUNT(*) FROM manager_preparation_oauth_tokens')->fetchColumn()!==0)throw new RuntimeException('grant_cascade_failed');
    if((int)$pdo->query('SELECT COUNT(*) FROM manager_assistant_decisions')->fetchColumn()!==0)throw new RuntimeException('assistant_manager_cascade_failed');
    echo "PASS native OAuth family cascade and bounded event BLOB roundtrip\n";
}catch(Throwable $error){
    // Never print exception text, SQL, values or connection details in CI.
    $state=$error instanceof PDOException?(string)($error->errorInfo[0]??$error->getCode()):'';
    $driver=$error instanceof PDOException?(string)($error->errorInfo[1]??''):'';
    $state=preg_match('/\A[A-Z0-9]{5}\z/D',$state)===1?$state:'unknown';
    $driver=preg_match('/\A[0-9]{1,10}\z/D',$driver)===1?$driver:'unknown';
    fwrite(STDERR,"FAIL native preparation migrations sqlstate=$state driver=$driver\n");exit(1);
}finally{
    if($safe&&$pdo instanceof PDO)foreach($tables as $table)$pdo->exec('DROP TABLE IF EXISTS '.$table);
}
