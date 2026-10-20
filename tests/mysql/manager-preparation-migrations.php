<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$pdo=null;$safe=false;$tables=['manager_preparation_oauth_tokens','manager_preparation_oauth_codes','manager_preparation_oauth_grants','manager_preparation_oauth_clients','manager_preparation_events','manager_prepared_replies','manager_ai_drafts','conversations','managers'];
try{
    $dsn=(string)getenv('PREPARED_REPLY_MYSQL_DSN');
    if(preg_match('/\Amysql:.*(?:\Amysql:|;)dbname=(maxbot_prepared_test(?:_[a-zA-Z0-9_]+)?)(?:;|\z)/D',$dsn,$m)!==1)throw new RuntimeException('unsafe_fixture_database');
    $pdo=new PDO($dsn,getenv('PREPARED_REPLY_MYSQL_USER')?:null,getenv('PREPARED_REPLY_MYSQL_PASS')?:null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    if($pdo->query('SELECT DATABASE()')->fetchColumn()!==$m[1])throw new RuntimeException('unsafe_fixture_database');
    $safe=true;
    foreach($tables as $table)$pdo->exec('DROP TABLE IF EXISTS '.$table);
    $pdo->exec('CREATE TABLE managers(id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE conversations(id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
    foreach(['043_manager_ai_draft_feedback.sql','044_manager_prepared_replies.sql','045_manager_preparation_oauth.sql','046_manager_preparation_events.sql','047_prepared_reply_feedback.sql']as $name){
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
    echo "PASS native OAuth family cascade and bounded event BLOB roundtrip\n";
}catch(Throwable $ignored){fwrite(STDERR,"FAIL native preparation migrations\n");exit(1);}finally{
    if($safe&&$pdo instanceof PDO)foreach($tables as $table)$pdo->exec('DROP TABLE IF EXISTS '.$table);
}
