<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/services/ModeledConversionValueDeliveryReader.php';

/** Shared original populated SQLite/native-MySQL fixture; synthetic data only. */
function forecastProcessingFrontierFixture(PDO $p): array
{
    foreach(['max_channel_conversion_outbox','modeled_conversion_profile_versions']as$table)$p->exec('DROP TABLE IF EXISTS '.$table);
    $p->exec("CREATE TABLE modeled_conversion_profile_versions(id BIGINT PRIMARY KEY,project_key VARCHAR(40),platform VARCHAR(16),customer_key VARCHAR(40),conversation_id BIGINT,observed_at_unix BIGINT,assessment_namespace CHAR(64),assessment_kind VARCHAR(8),profile_json TEXT)");
    $p->exec("CREATE TABLE max_channel_conversion_outbox(id BIGINT PRIMARY KEY,project_key VARCHAR(40),platform VARCHAR(16),conversation_id BIGINT,counter_id VARCHAR(20),transport_target VARCHAR(64),value_contract VARCHAR(64),target VARCHAR(100),value_profile_id BIGINT,value_rub DECIMAL(12,2),state VARCHAR(24),upload_status VARCHAR(32),upload_id VARCHAR(40),external_user_id VARCHAR(20),event_unix BIGINT,value_stage VARCHAR(40),last_error TEXT,value_ready_at_unix BIGINT,value_api_accepted_at_unix BIGINT,value_processed_at_unix BIGINT)");
    $p->exec('CREATE INDEX idx_value_delivery_receipt ON max_channel_conversion_outbox(project_key,counter_id,transport_target,value_contract,value_api_accepted_at_unix)');
    $profileCut=1794446184;$apiCut=1794437904;$now=max(time(),$profileCut+600);$ns=ModeledConversionProfiles::assessmentNamespace();
    $profile=$p->prepare("INSERT INTO modeled_conversion_profile_versions VALUES(?,'anytour',?,?,?,?,?,'repair','PRIVATE_FRONTIER_PROFILE')");
    $event=$p->prepare("INSERT INTO max_channel_conversion_outbox VALUES(?,'anytour',?,?,'98615635',?,?,?,?,?,?,?,?,?,?,'specific_request',?,?,?,?)");
    $add=static function(int $id,bool $later=false)use($profile,$event,$profileCut,$apiCut,$ns):void{
        $platform=$id%2?'max':'telegram';$born=$later?$profileCut+1:$apiCut-120;$at=$later?$profileCut:$apiCut-180;
        $api=$id<=20?$apiCut-60+$id:($id<=23?$apiCut+60:null);
        $state=$id<=8?'processed':($id===17?'unmatched':($api!==null?'accepted':($id%2?'ready':'pending')));
        $status=$id<=8?'PROCESSED':($id===17?'LINKAGE_FAILURE':($api!==null?'UPLOADED':null));
        $profile->execute([$id,$platform,(string)(100+$id),200+$id,$born,$ns]);
        $event->execute([$id,$platform,200+$id,ModeledConversionValueContract::TARGET,ModeledConversionValueContract::VERSION,
            ModeledConversionValueContract::predictionKey((string)$id),$id,10+$id,$state,$status,$api===null?null:(string)(400+$id),
            (string)(300+$id),$at,$state==='pending'?'identity_not_found':'',$state==='pending'?null:$at+1,$api,$id<=8?$api+1:null]);
    };
    for($id=1;$id<=59;$id++)$add($id);$add(80,true);
    $profile->execute([90,'max','190',290,$apiCut-120,$ns]);
    $poll=ModeledConversionReceiptPollEvidence::begin($now-3);
    $poll=ModeledConversionReceiptPollEvidence::append($poll,['v'=>1,'counter_id'=>'98615635',
        'receipt_ref'=>hash('sha256','modeled_value_upload_receipt_v1|417'),'checked_at_unix'=>$now-1,'stage'=>7,
        'http_status'=>200,'transport_error_code'=>0,'provider_status'=>'LINKAGE_FAILURE'],$now);
    $read=static fn(?ModeledConversionStatusBudget $budget=null):array=>
        (new ModeledConversionValueDeliveryReader($p,static function():void{},$budget??new ModeledConversionStatusBudget()))->repairAssessments($now,null,null,null,$poll);
    $snapshot=static fn():string=>json_encode([$p->query('SELECT * FROM modeled_conversion_profile_versions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $p->query('SELECT * FROM max_channel_conversion_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)],JSON_THROW_ON_ERROR);

    return [$profileCut,$apiCut,$now,$ns,$profile,$event,$add,$poll,$read,$snapshot];
}
