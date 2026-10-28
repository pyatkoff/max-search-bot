<?php
declare(strict_types=1);

if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
if (count($argv)!==2||!in_array($argv[1],['tick','status','verify-cron'],true)) {
    fwrite(STDERR,"Usage: php tools/development_work.php tick|status|verify-cron\n");exit(2);
}
require_once dirname(__DIR__).'/services/DevelopmentWorkHealth.php';
if ($argv[1]==='status') {
    echo json_encode(['ok'=>true,'tick'=>DevelopmentWorkHealth::snapshot(),'provider_calls'=>0],JSON_THROW_ON_ERROR)."\n";exit;
}
if ($argv[1]==='verify-cron') {
    require_once dirname(__DIR__).'/services/DevelopmentWorkCronVerifier.php';
    $cutoff=time();$verifier=new DevelopmentWorkCronVerifier($cutoff);$deadline=$cutoff+150;
    try {
        do {
            $proof=$verifier->observe(DevelopmentWorkHealth::snapshot(),time());
            if ($proof!==null) {
                echo json_encode(['ok'=>true,'state'=>'scheduled_ticks_verified','proof'=>$proof,'provider_calls'=>0],JSON_THROW_ON_ERROR)."\n";exit;
            }
            sleep(5);
        } while(time()<=$deadline);
        $reason='development_cron_two_future_ticks_missing';
    } catch (Throwable $error) {
        $reason=in_array($error->getMessage(),['development_cron_worker_failed','development_cron_interval_out_of_bounds'],true)
            ?$error->getMessage():'development_cron_verification_unavailable';
    }
    fwrite(STDERR,json_encode(['ok'=>false,'state'=>'cron_verification_failed','reason'=>$reason,'provider_calls'=>0],JSON_THROW_ON_ERROR)."\n");exit(1);
}
$stage='bootstrap';$finished=false;$trigger=getenv('DEVELOPMENT_WORK_TRIGGER')==='cron'?'cron':'manual';
DevelopmentWorkHealth::record('running',$stage,[],$trigger);
register_shutdown_function(static function() use (&$finished,&$stage,$trigger): void {
    if (!$finished) DevelopmentWorkHealth::record('failed',$stage,[],$trigger);
});
try {
    require_once dirname(__DIR__).'/config.php';
    require_once dirname(__DIR__).'/services/ProjectAccessService.php';
    require_once dirname(__DIR__).'/services/DevelopmentWorkEventService.php';
    ProjectAccessService::initializeReadOnly();
    $events=new DevelopmentWorkEventService();$stage='subscription';
    // A real current development OAuth grant and verified subscription are prerequisites.
    // The CLI never starts a hosted worker or invokes any model/provider itself.
    if (!$events->hasActiveSubscriptions()) {
        DevelopmentWorkHealth::record('awaiting_connection',$stage,[],$trigger);$finished=true;
        echo "{\"ok\":true,\"state\":\"awaiting_connection\",\"provider_calls\":0}\n";exit;
    }
    $stage='delivery';$counts=$events->deliverPending();
    if (($counts['stopped']??0)>0) {
        DevelopmentWorkHealth::record('failed',$stage,$counts,$trigger);$finished=true;
        echo json_encode(['ok'=>false,'state'=>'delivery_stopped','counts'=>$counts,'provider_calls'=>0],JSON_THROW_ON_ERROR)."\n";exit(1);
    }
    DevelopmentWorkHealth::record('ok','complete',$counts,$trigger);$finished=true;
    echo json_encode(['ok'=>true,'counts'=>$counts,'provider_calls'=>0],JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $ignored) {
    DevelopmentWorkHealth::record('failed',$stage,[],$trigger);$finished=true;
    // Fixed stage only: no SQL, exception text, queue IDs, callback or credentials.
    fwrite(STDERR,json_encode(['ok'=>false,'state'=>'unavailable','stage'=>$stage,'provider_calls'=>0],JSON_THROW_ON_ERROR)."\n");exit(1);
}
