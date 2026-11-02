<?php
declare(strict_types=1);

if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
if (count($argv)!==2||!in_array($argv[1],['tick','status','verify-cron'],true)) {
    fwrite(STDERR,"Usage: php tools/hotel_work.php tick|status|verify-cron\n");exit(2);
}
require_once dirname(__DIR__).'/services/HotelWorkHealth.php';
if ($argv[1]==='status') {
    echo json_encode(['ok'=>true,'tick'=>HotelWorkHealth::snapshot(),'provider_calls'=>0],JSON_THROW_ON_ERROR)."\n";exit;
}
if ($argv[1]==='verify-cron') {
    require_once dirname(__DIR__).'/services/HotelWorkCronVerifier.php';
    $cutoff=time();$verifier=new HotelWorkCronVerifier($cutoff);$deadline=$cutoff+150;
    try {
        do {
            $proof=$verifier->observe(HotelWorkHealth::snapshot(),time());
            if ($proof!==null) {
                echo json_encode(['ok'=>true,'state'=>'scheduled_ticks_verified','proof'=>$proof,'provider_calls'=>0],JSON_THROW_ON_ERROR)."\n";exit;
            }
            sleep(5);
        } while(time()<=$deadline);
        $reason='hotel_cron_two_future_ticks_missing';
    } catch (Throwable $error) {
        $reason=in_array($error->getMessage(),['hotel_cron_worker_failed','hotel_cron_interval_out_of_bounds'],true)
            ?$error->getMessage():'hotel_cron_verification_unavailable';
    }
    fwrite(STDERR,json_encode(['ok'=>false,'state'=>'cron_verification_failed','reason'=>$reason,'provider_calls'=>0],JSON_THROW_ON_ERROR)."\n");exit(1);
}
$stage='bootstrap';$finished=false;$trigger=getenv('HOTEL_WORK_TRIGGER')==='cron'?'cron':'manual';
HotelWorkHealth::record('running',$stage,[],$trigger);
register_shutdown_function(static function() use (&$finished,&$stage,$trigger): void {
    if (!$finished) HotelWorkHealth::record('failed',$stage,[],$trigger);
});
try {
    require_once dirname(__DIR__).'/config.php';
    require_once dirname(__DIR__).'/services/ProjectAccessService.php';
    require_once dirname(__DIR__).'/services/HotelWorkEventService.php';
    ProjectAccessService::initializeReadOnly();
    $pdo=ConversationDb::connection();
    // New server mode is evaluated BEFORE the legacy subscription prerequisite.
    // Missing/mismatched flags do not load a provider or query the new schema.
    require_once dirname(__DIR__).'/services/HotelServerCronRuntime.php';
    require_once dirname(__DIR__).'/services/HotelServerRuntimeConfig.php';
    $privateRuntime=HotelServerRuntimeConfig::load(dirname(__DIR__));
    if($privateRuntime!==null){
        foreach(['HOTEL_SERVER_CYCLE_ENABLED','HOTEL_SERVER_DISPATCH_ALLOWED','HOTEL_SERVER_REPLACEMENT_DISPATCH_ALLOWED'] as $flag)
            if(defined($flag))throw new DomainException('hotel_runtime_configuration_conflict');
        foreach(['ANYTOUR_HOTEL_SERVER_PROVIDER','ANYTOUR_HOTEL_SERVER_AI_POLICY'] as $name)
            if(array_key_exists($name,$GLOBALS))throw new DomainException('hotel_runtime_configuration_conflict');
    }
    $server=HotelServerCronRuntime::tick($privateRuntime['flags']??[
        'enabled'=>defined('HOTEL_SERVER_CYCLE_ENABLED')?HOTEL_SERVER_CYCLE_ENABLED:false,
        'dispatch_allowed'=>defined('HOTEL_SERVER_DISPATCH_ALLOWED')?HOTEL_SERVER_DISPATCH_ALLOWED:false,
        'replacement_dispatch_allowed'=>defined('HOTEL_SERVER_REPLACEMENT_DISPATCH_ALLOWED')?HOTEL_SERVER_REPLACEMENT_DISPATCH_ALLOWED:false,
    ],static function()use($pdo,$privateRuntime):array{
        $provider=$GLOBALS['ANYTOUR_HOTEL_SERVER_PROVIDER']??null;
        if($provider===null&&($privateRuntime!==null||array_key_exists('ANYTOUR_HOTEL_SERVER_AI_POLICY',$GLOBALS))){
            require_once dirname(__DIR__).'/services/HotelServerAiProvider.php';
            $policy=$privateRuntime['policy']??$GLOBALS['ANYTOUR_HOTEL_SERVER_AI_POLICY'];
            if(!is_array($policy))throw new DomainException('hotel_provider_policy_required');
            $provider=new HotelServerAiProvider($policy);
        }
        $context=['pdo'=>$pdo,'provider'=>$provider];
        if($privateRuntime!==null)$context+=['expected_campaign_id'=>$privateRuntime['campaign_id'],
            'expected_bootstrap_sha256'=>$privateRuntime['bootstrap_sha256']];
        return $context;
    });
    if($server['handled']){
        $ok=$server['state']!=='hold';
        HotelWorkHealth::record($ok?'ok':'failed','server_cycle',[],$trigger);$finished=true;
        echo json_encode(['ok'=>$ok,'state'=>$server['state'],'server_cycle'=>true],JSON_THROW_ON_ERROR)."\n";exit($ok?0:1);
    }
    $events=new HotelWorkEventService($pdo,connectionAuthorized:
        static fn(string $connection,int $admin,array $required):bool=>$required===[ManagerPreparationOAuth::HOTEL_SCOPE]
            &&(new ManagerPreparationOAuth($pdo))->eventConnectionAuthorizedAcrossProfiles('hotels',$connection,$admin));
    $stage='subscription';
    // A real current hotel OAuth grant and verified subscription are prerequisites.
    // The CLI never starts a hosted worker or invokes any model/provider itself.
    if (!$events->hasActiveSubscriptions()) {
        HotelWorkHealth::record('awaiting_connection',$stage,[],$trigger);$finished=true;
        echo "{\"ok\":true,\"state\":\"awaiting_connection\",\"provider_calls\":0}\n";exit;
    }
    $stage='delivery';$counts=$events->deliverPending();
    if (($counts['stopped']??0)>0) {
        HotelWorkHealth::record('failed',$stage,$counts,$trigger);$finished=true;
        echo json_encode(['ok'=>false,'state'=>'delivery_stopped','counts'=>$counts,'provider_calls'=>0],JSON_THROW_ON_ERROR)."\n";exit(1);
    }
    HotelWorkHealth::record('ok','complete',$counts,$trigger);$finished=true;
    echo json_encode(['ok'=>true,'counts'=>$counts,'provider_calls'=>0],JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $ignored) {
    HotelWorkHealth::record('failed',$stage,[],$trigger);$finished=true;
    // Fixed stage only: no SQL, exception text, queue IDs, callback or credentials.
    fwrite(STDERR,json_encode(['ok'=>false,'state'=>'unavailable','stage'=>$stage,'provider_calls'=>0],JSON_THROW_ON_ERROR)."\n");exit(1);
}

