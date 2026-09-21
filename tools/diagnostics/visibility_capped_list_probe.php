<?php
// Investigation branch only: stdin execution, no server files or data writes.
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
$pdo=null;$stage='bootstrap';
try {
    require getcwd().'/config.php';
    require getcwd().'/services/ManagerConversationService.php';
    $pdo=ConversationDb::connection();
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('SET TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    $stage='verify_existing_project';
    // The canonical schema cache normally becomes true after a project UPSERT.
    // Keep the DB read-only: verify that exact postcondition before memoizing it.
    // No permission, role, routing, assignment or visibility decision is skipped.
    $key=ProjectConfig::projectId();
    $name=(string)ProjectConfig::get('brand.name',$key);
    $q=$pdo->prepare('SELECT id,display_name,is_active FROM projects WHERE project_key=? LIMIT 1');
    $q->execute([$key]);$project=$q->fetch();
    if(!$project||(int)$project['id']<=0||(int)$project['is_active']!==1||(string)$project['display_name']!==($name!==''?$name:$key))throw new RuntimeException('project_postcondition_not_met');
    $bootstrapCache=new ReflectionProperty(ProjectAccessService::class,'schemaReady');
    $bootstrapCache->setValue(null,true);
    $stage='read_manager_lists';
    $managers=$pdo->query('SELECT id,role,is_working FROM managers WHERE is_active=1 ORDER BY id LIMIT 21')->fetchAll();
    if(count($managers)>20)throw new RuntimeException('manager_bound');
    $report=[];
    foreach($managers as $manager){
        $id=(int)$manager['id'];
        $all=ManagerConversationService::list($id,'all',200,'*');
        $mine=ManagerConversationService::list($id,'mine',200,'*');
        $waiting=ManagerConversationService::list($id,'waiting',200,'*');
        $allIds=array_fill_keys(array_map('intval',array_column($all,'id')),true);
        $mineIds=array_fill_keys(array_map('intval',array_column($mine,'id')),true);
        $counts=['all'=>count($all),'mine'=>count($mine),'waiting'=>count($waiting),'all_not_in_mine'=>count(array_diff_key($allIds,$mineIds)),'mine_not_in_all'=>count(array_diff_key($mineIds,$allIds)),'all_unassigned'=>0,'all_assigned_other'=>0,'waiting_own'=>0,'waiting_unassigned'=>0,'waiting_unassigned_in_all'=>0,'waiting_unassigned_missing_older'=>0,'waiting_unassigned_missing_tied'=>0,'waiting_unassigned_missing_newer'=>0];
        $cutoff='';
        foreach($all as $row){
            $owner=(int)($row['manager_id']??0);
            if($owner<=0)$counts['all_unassigned']++;
            elseif($owner!==$id)$counts['all_assigned_other']++;
            $activity=(string)($row['last_message_at']??$row['started_at']??'');
            if($cutoff===''||strcmp($activity,$cutoff)<0)$cutoff=$activity;
        }
        foreach($waiting as $row){
            $owner=(int)($row['manager_id']??0);
            if($owner===$id){$counts['waiting_own']++;continue;}
            if($owner>0)continue;
            $counts['waiting_unassigned']++;
            if(isset($allIds[(int)$row['id']])){$counts['waiting_unassigned_in_all']++;continue;}
            $activity=(string)($row['last_message_at']??$row['started_at']??'');
            $order=strcmp($activity,$cutoff);
            $counts['waiting_unassigned_missing_'.($order<0?'older':($order===0?'tied':'newer'))]++;
        }
        $report[]=['role'=>($manager['role']==='admin'?'admin':'manager'),'is_working'=>(bool)$manager['is_working'],'legacy_alarm'=>(bool)$manager['is_working']&&count($waiting)>0&&count($all)<=count($mine)]+$counts;
    }
    $pdo->rollBack();
    echo json_encode(['capture_ok'=>true,'generated_at'=>gmdate('c'),'purpose'=>'investigation_only_not_release_acceptance','existing_project_postcondition_verified'=>true,'database_read_only'=>true,'list_limit'=>200,'active_manager_count'=>count($managers),'rows'=>$report],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)."\n";
} catch(Throwable $e) {
    if($pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();
    $info=$e instanceof PDOException?($e->errorInfo??[]):[];
    $code=is_numeric($info[1]??null)?(int)$info[1]:0;
    file_put_contents('php://stderr',json_encode(['capture_ok'=>false,'stage'=>$stage,'error_class'=>get_class($e),'sql_driver_code'=>$code],JSON_THROW_ON_ERROR)."\n");
    exit(1);
}
