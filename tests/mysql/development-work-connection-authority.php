<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/services/DevelopmentWorkMcpService.php';
require_once dirname(__DIR__,2).'/services/ManagerPreparationMcpService.php';
require_once dirname(__DIR__).'/fixtures/development_work_queue.php';

function developmentHandoffTestTokens(ManagerPreparationOAuth $oauth,string $scope,string $resource,string $bucket): array
{
    $client=$oauth->registerClient(['redirect_uris'=>[ManagerPreparationOAuth::REDIRECT_URI],'scope'=>$scope],$bucket)['client_id'];
    $verifier=str_repeat('v',43);$session=['csrf'=>'handoff-synthetic-csrf'];
    $pending=$oauth->beginAuthorization($session,['client_id'=>$client,'redirect_uri'=>ManagerPreparationOAuth::REDIRECT_URI,
        'response_type'=>'code','resource'=>$resource,'scope'=>$scope,'state'=>'handoff-synthetic-state',
        'code_challenge_method'=>'S256','code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=')]);
    $url=$oauth->completeAuthorization($session,$pending,1,$session['csrf'],true);
    parse_str((string)parse_url($url,PHP_URL_QUERY),$callback);
    return $oauth->exchange(['grant_type'=>'authorization_code','client_id'=>$client,
        'redirect_uri'=>ManagerPreparationOAuth::REDIRECT_URI,'resource'=>$resource,'code'=>$callback['code'],'code_verifier'=>$verifier]);
}

function developmentHandoffAuthorityChecks(PDO $pdo,int $now,callable $check): void
{
    $scope=ManagerPreparationOAuth::DEVELOPMENT_SCOPE;$clock=static fn():int=>$now;
    $snapshot=static function()use($pdo):array {
        $out=[];foreach ([...DevelopmentWorkTestSchema::tables(),'managers','projects','manager_projects',
            'manager_preparation_oauth_clients','manager_preparation_oauth_grants','manager_preparation_oauth_codes','manager_preparation_oauth_tokens'] as $table) {
            $rows=$pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
            usort($rows,static fn(array $a,array $b):int=>strcmp(json_encode($a),json_encode($b)));$out[$table]=$rows;
        }return $out;
    };
    foreach ([ManagerPreparationOAuth::PROFILE_LEGACY,ManagerPreparationOAuth::PROFILE_DEVELOPMENT] as $profile) {
        foreach (['success','old_revoked','old_expired','current_revoked','old_foreign_admin','old_foreign_project','old_no_scope','old_wrong_resource','old_wrong_namespace','old_callback','old_missing'] as $case) {
            DevelopmentWorkTestSchema::create($pdo);
            $legacy=new ManagerPreparationOAuth($pdo,$clock);
            $oldTokens=developmentHandoffTestTokens($legacy,$scope,ManagerPreparationOAuth::RESOURCE,'handoff-old-'.$profile.'-'.$case);
            $old=$legacy->connectionForBearer('Bearer '.$oldTokens['access_token']);
            $targetOAuth=new ManagerPreparationOAuth($pdo,$clock,$profile);
            $resource=ManagerPreparationOAuth::resourceMetadata($profile)['resource'];
            $targetTokens=developmentHandoffTestTokens($targetOAuth,$scope,$resource,'handoff-target-'.$profile.'-'.$case);
            $target=$targetOAuth->connectionForBearer('Bearer '.$targetTokens['access_token']);
            $queue=new DevelopmentWorkQueue($pdo,$clock);
            $task=['task_id'=>'A','owner_task_id'=>'SYNTHETIC-OWNER','reviewer_task_id'=>'SYNTHETIC-REVIEWER',
                'input_sha256'=>str_repeat('1',64),'acceptance_contract_sha256'=>str_repeat('2',64),'deps'=>[],'priority'=>10];
            $initial=$queue->create(1,$old,['queue_id'=>'HANDOFF_SAMPLE','plan_sha256'=>str_repeat('0',64),'tasks'=>[$task]]);
            $service=$profile===ManagerPreparationOAuth::PROFILE_DEVELOPMENT
                ?new DevelopmentWorkMcpService($pdo,$queue,$clock)
                :new ManagerPreparationMcpService($pdo,clock:$clock,developmentStore:$queue);
            $call=static fn(string $name,array $args):array=>$service->handle(
                ['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/call','params'=>['name'=>$name,'arguments'=>$args]],1,$target,[$scope]);
            $oldRow=$pdo->query("SELECT * FROM manager_preparation_oauth_grants WHERE grant_id='".$old."'")->fetch(PDO::FETCH_ASSOC);
            if ($case==='old_revoked') $pdo->prepare('UPDATE manager_preparation_oauth_grants SET revoked_at=? WHERE grant_id=?')->execute([$now,$old]);
            if ($case==='old_expired') $pdo->prepare('UPDATE manager_preparation_oauth_grants SET expires_at=? WHERE grant_id=?')->execute([$now-1,$old]);
            if ($case==='current_revoked') $pdo->prepare('UPDATE manager_preparation_oauth_grants SET revoked_at=? WHERE grant_id=?')->execute([$now,$target]);
            if ($case==='old_foreign_admin') $pdo->prepare('UPDATE manager_preparation_oauth_grants SET admin_id=2 WHERE grant_id=?')->execute([$old]);
            if ($case==='old_foreign_project') $pdo->prepare('UPDATE manager_preparation_oauth_grants SET project_key=? WHERE grant_id=?')->execute(['other',$old]);
            if ($case==='old_no_scope') $pdo->prepare('UPDATE manager_preparation_oauth_grants SET scopes=? WHERE grant_id=?')->execute(['archive:read',$old]);
            if ($case==='old_wrong_resource') $pdo->prepare('UPDATE manager_preparation_oauth_grants SET resource=? WHERE grant_id=?')->execute(['https://invalid.example/mcp',$old]);
            if ($case==='old_wrong_namespace') {
                $targetRow=$pdo->query("SELECT client_id FROM manager_preparation_oauth_grants WHERE grant_id='".$target."'")->fetch(PDO::FETCH_ASSOC);
                // The development-only client cannot be used for the legacy audience.
                if ($profile===ManagerPreparationOAuth::PROFILE_DEVELOPMENT)
                    $pdo->prepare('UPDATE manager_preparation_oauth_grants SET client_id=? WHERE grant_id=?')->execute([$targetRow['client_id'],$old]);
                else $pdo->prepare('UPDATE manager_preparation_oauth_grants SET resource=? WHERE grant_id=?')->execute([ManagerPreparationOAuth::DEVELOPMENT_RESOURCE,$old]);
            }
            if ($case==='old_callback') $pdo->prepare('UPDATE manager_preparation_oauth_clients SET redirect_uri=? WHERE client_id=?')
                ->execute(['https://invalid.example/callback',$oldRow['client_id']]);
            if ($case==='old_missing') $pdo->prepare('DELETE FROM manager_preparation_oauth_grants WHERE grant_id=?')->execute([$old]);
            $args=['queue_id'=>'HANDOFF_SAMPLE','old_connection_id'=>$old,'expected_caller_connection_id'=>$target];
            $before=$snapshot();$reply=$call('development_work_handoff_preflight',$args);
            $pre=$reply['result']['structuredContent']??null;
            $allowed=in_array($case,['success','old_revoked','old_expired'],true);
            $check($allowed?is_array($pre)&&$pre['eligible']===true&&!isset($reply['error']):isset($reply['error'])||($pre['eligible']??false)!==true,
                'canonical_handoff_preflight_'.$profile.'_'.$case);
            $check($before===$snapshot(),'canonical_preflight_all_rows_unchanged_'.$profile.'_'.$case);
            if (!$allowed) continue;
            $unavailable=$call('development_work_status',['queue_id'=>'HANDOFF_SAMPLE']);
            $check(($unavailable['result']['structuredContent']['reason']??null)==='OWNED_QUEUE_UNAVAILABLE',
                'ordinary_status_stays_exact_connection_before_recovery_'.$profile.'_'.$case);
            $commit=array_intersect_key($pre,array_flip(['queue_id','task_id','spec_sha256','expected_plan_sha256',
                'expected_pending_event_id','old_connection_id','expected_caller_connection_id']));
            $commit['decision']=['decision_sha256'=>str_repeat('4',64),'readback_sha256'=>str_repeat('5',64),'reviewer_run_id'=>'SYNTHETIC-INDEPENDENT-REVIEW'];
            $authBefore=array_intersect_key($snapshot(),array_flip(['managers','projects','manager_projects',
                'manager_preparation_oauth_clients','manager_preparation_oauth_grants','manager_preparation_oauth_codes','manager_preparation_oauth_tokens']));
            $reply=$call('development_work_handoff_connection',$commit);$done=$reply['result']['structuredContent']??[];
            $check(($done['handoff_status']??null)==='retired_unclaimed_connection_handed_off'&&$done['accepted_count']===0
                &&$done['state']==='blocked'&&$done['task_id']===null,'actual_mcp_handoff_keeps_original_unaccepted_'.$profile.'_'.$case);
            $after=array_intersect_key($snapshot(),$authBefore);
            $check($after===$authBefore,'handoff_does_not_issue_revoke_refresh_or_mutate_any_authority_'.$profile.'_'.$case);
            $owned=$call('development_work_status',['queue_id'=>'HANDOFF_SAMPLE'])['result']['structuredContent']??[];
            $check(($owned['ok']??false)===true&&$owned['last_retirement']['expected_pending_event_id']===$commit['expected_pending_event_id'],
                'actual_mcp_owned_status_valid_after_historical_binding_change_'.$profile.'_'.$case);
            $before=$snapshot();$repeat=$call('development_work_handoff_connection',$commit);
            $check(($repeat['result']['structuredContent']['handoff_status']??null)==='already_recorded'&&$before===$snapshot(),
                'actual_mcp_handoff_idempotent_readback_all_rows_unchanged_'.$profile.'_'.$case);
        }
    }
}

function developmentHandoffNativeAuthority(): void
{
    $pdo=null;$safe=false;
    $authTables=['manager_preparation_oauth_tokens','manager_preparation_oauth_codes','manager_preparation_oauth_grants',
        'manager_preparation_oauth_clients','manager_projects','projects','managers'];
    try {
        $dsn=(string)getenv('PREPARED_REPLY_MYSQL_DSN');
        if (preg_match('/\Amysql:.*(?:\Amysql:|;)dbname=(maxbot_prepared_test(?:_[a-zA-Z0-9_]+)?)(?:;|\z)/D',$dsn,$match)!==1)
            throw new RuntimeException('unsafe_fixture_database');
        $pdo=new PDO($dsn,getenv('PREPARED_REPLY_MYSQL_USER')?:null,getenv('PREPARED_REPLY_MYSQL_PASS')?:null,
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        if ($pdo->query('SELECT DATABASE()')->fetchColumn()!==$match[1]) throw new RuntimeException('unsafe_fixture_database');
        $safe=true;foreach ($authTables as $table) $pdo->exec('DROP TABLE IF EXISTS '.$table);
        $pdo->exec("CREATE TABLE managers(id BIGINT UNSIGNED PRIMARY KEY,role VARCHAR(32),is_active INT,is_working INT) ENGINE=InnoDB;
            CREATE TABLE projects(id BIGINT UNSIGNED PRIMARY KEY,project_key VARCHAR(64),display_name VARCHAR(255),is_active INT) ENGINE=InnoDB;
            CREATE TABLE manager_projects(manager_id BIGINT UNSIGNED,project_id BIGINT UNSIGNED) ENGINE=InnoDB;
            INSERT INTO managers VALUES(1,'admin',1,0),(2,'manager',1,1);
            INSERT INTO projects VALUES(1,'anytour','Synthetic project',1);
            INSERT INTO manager_projects VALUES(1,1),(2,1);");
        $pdo->exec(file_get_contents(dirname(__DIR__,2).'/migrations/045_manager_preparation_oauth.sql'));
        (new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
        (new ReflectionProperty(ProjectAccessService::class,'schemaReady'))->setValue(null,true);
        ProjectConfig::resetForTests(['id'=>'anytour','brand'=>['name'=>'Synthetic project']]);
        $count=0;developmentHandoffAuthorityChecks($pdo,time(),static function(bool $ok,string $label)use(&$count):void {
            if (!$ok) throw new RuntimeException('native_handoff_authority_assertion');++$count;
        });
        echo 'DEVELOPMENT HANDOFF NATIVE AUTHORITY: '.$count." PASS\n";
    } catch (Throwable $error) {
        fwrite(STDERR,"FAIL native development handoff authority\n");exit(1);
    } finally {
        (new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,null);
        (new ReflectionProperty(ProjectAccessService::class,'schemaReady'))->setValue(null,false);
        if ($safe&&$pdo instanceof PDO) foreach ($authTables as $table) $pdo->exec('DROP TABLE IF EXISTS '.$table);
    }
}
