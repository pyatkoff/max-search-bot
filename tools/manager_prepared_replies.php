<?php
declare(strict_types=1);

if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
if (($argv[1]??'')!=='tick'||count($argv)!==2) {
    fwrite(STDERR,"Usage: php tools/manager_prepared_replies.php tick\n");exit(2);
}
require_once dirname(__DIR__).'/services/ManagerPreparationHealth.php';
$stage='bootstrap';$finished=false;
ManagerPreparationHealth::record('running',$stage);
register_shutdown_function(static function() use (&$finished,&$stage): void {
    if (!$finished) ManagerPreparationHealth::record('failed',$stage);
});

try {
    require_once dirname(__DIR__).'/config.php';
    require_once dirname(__DIR__).'/services/ManagerDraftPilotCollector.php';
    require_once dirname(__DIR__).'/services/ManagerPreparedReplyService.php';
    require_once dirname(__DIR__).'/services/ManagerPreparationMcpService.php';
    $pdo=ConversationDb::connection();
    ProjectAccessService::initializeReadOnly();
    // Avoid scanning conversations until a real authorized event subscription
    // exists. A deployed cron by itself is not a connected ChatGPT worker.
    $service=new ManagerPreparationMcpService();
    $stage='subscription';
    if (!$service->hasActiveSubscriptions()) {
        ManagerPreparationHealth::record('awaiting_connection',$stage);$finished=true;
        echo "{\"ok\":true,\"state\":\"awaiting_connection\",\"provider_calls\":0}\n";exit;
    }
    $project=ProjectConfig::projectId();
    $q=$pdo->prepare('SELECT DISTINCT m.id FROM managers m JOIN manager_projects mp ON mp.manager_id=m.id JOIN projects p ON p.id=mp.project_id WHERE m.is_active=1 AND p.is_active=1 AND p.project_key=? ORDER BY m.id');
    $q->execute([$project]);
    $cohort=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
    if (!$cohort) throw new RuntimeException('empty_cohort');
    $stage='collection';
    $batch=ManagerDraftPilotCollector::collect($pdo,$cohort,20,null,null,null,true);
    if (empty($batch['ok'])) throw new RuntimeException('collection_failed');
    $stage='enqueue';
    $queued=ManagerPreparedReplyService::enqueue($batch['conversations']);
    $stage='delivery';
    $delivered=$service->deliverPending();
    // Aggregate counters only; snapshots and proposals never go to cron logs.
    $queueCounts=[];$deliveryCounts=[];
    foreach(['inserted','existing','skipped'] as $key) if(is_int($queued[$key]??null)) $queueCounts[$key]=$queued[$key];
    foreach($delivered as $key=>$value) if(is_int($value)&&preg_match('/\A[a-z_]{1,40}\z/D',(string)$key)) $deliveryCounts[$key]=$value;
    if(empty($queued['ok'])) {$stage='enqueue';throw new RuntimeException('preparation_incomplete');}
    ManagerPreparationHealth::record('ok','complete',['candidates'=>$batch['candidate_count'],'captured'=>$batch['exported_count']]+$queueCounts+$deliveryCounts);
    $finished=true;
    echo json_encode(['ok'=>true,'candidates'=>$batch['candidate_count'],'captured'=>$batch['exported_count'],
        'queue'=>$queueCounts,'delivery'=>$deliveryCounts,'provider_calls'=>0],JSON_THROW_ON_ERROR)."\n";
} catch(Throwable $ignored) {
    ManagerPreparationHealth::record('failed',$stage);$finished=true;
    fwrite(STDERR,"manager_preparation_tick_failed\n");exit(1);
}
