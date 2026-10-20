<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$pdo=null;$safe=false;$tables=['virtual_reply_jobs','virtual_reply_cases','virtual_reply_cursors','virtual_reply_quotas','manager_assistant_decisions','manager_preparation_oauth_tokens','manager_preparation_oauth_codes','manager_preparation_oauth_grants','manager_preparation_oauth_clients','manager_preparation_events','manager_prepared_replies','manager_ai_drafts','conversations','managers'];
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
        private PDO $pdo;
        public function __construct(PDO $pdo){$this->pdo=$pdo;}
        public function recent(string $source,int $limit):array{return $this->scan($source,0,$limit);}
        public function scan(string $source,int $after,int $limit):array{
            $ids=$source==='conversation'&&$after<1&&$limit>0?[1]:[];
            return ['source_ids'=>$ids,'last_id'=>$ids?1:$after,'has_more'=>false];
        }
        public function snapshot(string $source,int $id):?array{
            if($this->pdo->inTransaction())throw new RuntimeException('virtual_fixture_recheck_in_transaction');
            if($source!=='conversation'||$id!==1)return null;
            return ['project_key'=>'anytour','source_kind'=>$source,'source_id'=>$id,
                'source_revision'=>hash('sha256',$this->text),'reference_time'=>'2026-10-20T10:00:00Z','mode'=>'live',
                'evidence_message_ids'=>[1],'payload'=>['messages'=>[['id'=>1,'sender_type'=>'tourist','text'=>$this->text,
                    'created_at'=>'2026-10-20T10:00:00Z']],'history_incomplete'=>false,
                    'cutoff'=>['source_id'=>1,'reference_time'=>'2026-10-20T10:00:00Z']]];
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
}catch(Throwable $ignored){fwrite(STDERR,"FAIL native preparation migrations\n");exit(1);}finally{
    if($safe&&$pdo instanceof PDO)foreach($tables as $table)$pdo->exec('DROP TABLE IF EXISTS '.$table);
}
