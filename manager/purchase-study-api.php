<?php
declare(strict_types=1);
ini_set('display_errors','0');
require_once dirname(__DIR__).'/config.php';
require_once __DIR__.'/lib/ManagerHttp.php';
ManagerHttp::startJson();
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Vary: Cookie');
try{
    ProjectAccessService::initializeReadOnly();
    $manager=ManagerHttp::requireManager();
    ManagerHttp::requireAdmin($manager);
    if(($_SERVER['REQUEST_METHOD']??'')!=='GET')ManagerHttp::respond(['ok'=>false,'error'=>'method_not_allowed'],405);
    $operational=$_GET===['view'=>'operational'];
    if(($_GET&&!$operational)||$_POST)ManagerHttp::respond(['ok'=>false,'error'=>'unexpected_parameters'],422);
    if(ProjectConfig::projectId()!=='anytour')ManagerHttp::respond(['ok'=>false,'error'=>'project_unavailable'],404);
    if(!ProjectAccessService::canAccess((int)$manager['id'],'anytour'))ManagerHttp::respond(['ok'=>false,'error'=>'forbidden'],403);
    session_write_close();
    if($operational){
        $pdo=ConversationDb::connection();
        if($pdo->inTransaction())throw new RuntimeException('auth_snapshot');
        require_once dirname(__DIR__).'/services/PurchaseStudyOperationalSnapshot.php';
        $snapshot=PurchaseStudyOperationalSnapshot::read();
        // Recheck access even when the private read failed. No transaction snapshot.
        if($pdo->inTransaction())throw new RuntimeException('auth_snapshot');
        $fresh=ManagerHttp::requireManager();
        ManagerHttp::requireAdmin($fresh);
        if((int)$fresh['id']!==(int)$manager['id']||ProjectConfig::projectId()!=='anytour'
            ||!ProjectAccessService::canAccess((int)$fresh['id'],'anytour'))
            ManagerHttp::respond(['ok'=>false,'error'=>'forbidden'],403);
        $ok=$snapshot['status']==='saved_metadata_valid';
        $reply=['ok'=>$ok,'operational'=>$snapshot];
        if(strlen(json_encode($reply,JSON_THROW_ON_ERROR))>2048)
            ManagerHttp::respond(['ok'=>false,'error'=>'study_unavailable'],503);
        ManagerHttp::respond($reply,$ok?200:503);
    }
    require_once dirname(__DIR__).'/services/ModeledConversionPurchaseStudy.php';
    $status=(new ModeledConversionPurchaseStudy(ConversationDb::connection()))->report();
    $ok=($status['status']??'failed')!=='failed';
    ManagerHttp::respond(['ok'=>$ok,'study'=>$status,'interpretation'=>[
        'target'=>'manager_recorded_inquiry_outcome_30d_v1',
        'horizon_days'=>30,'unit'=>'new_canonical_lead_first_text_profile',
        'ground_truth'=>'manager_operational_assertion_not_independent_payment',
        'coverage'=>'admitted_journal_receipts_only_not_complete_business_capture',
        'comparison'=>'same_current_signals_versus_same_signals_plus_profile_conditions',
        'metrics_scope'=>'resolved_conditional_subset_with_person_time_holdout',
        'monetary_export_allowed'=>false,
    ]],$ok?200:503);
}catch(Throwable $ignored){ManagerHttp::respond(['ok'=>false,'error'=>'study_unavailable'],503);}
