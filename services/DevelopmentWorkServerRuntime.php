<?php
declare(strict_types=1);
require_once __DIR__.'/DevelopmentWorkQueue.php';

/** Existing ordinary minute CLI only; no endpoint, timer, bootstrap or manual tick. */
final class DevelopmentWorkServerRuntime
{
    public static function tick(PDO $pdo,string $trigger,callable $authorize,?DevelopmentWorkServerProvider $provider=null,$clock=null,?DevelopmentWorkServerAmendmentGate $gate=null,?callable $roadmapSender=null): array
    {
        $now=$clock===null?time():$clock();if(!is_int($now)||$now<1)throw new DomainException('development_server_clock');
        $queue=new DevelopmentWorkQueue($pdo,$clock);
        $roadmap=$queue->roadmapTick(['trigger'=>$trigger,'cron_id'=>bin2hex(random_bytes(32)),'started_at'=>$now],$roadmapSender);
        if(($roadmap['handled']??false)===true)return $roadmap;
        $s=$pdo->prepare('SELECT q.admin_id,c.execution_connection_id AS oauth_connection_id FROM development_work_server_controls c JOIN development_work_queues q ON q.queue_id=c.queue_id WHERE q.queue_id=?');
        $s->execute([DevelopmentWorkServerContract::QUEUE]);$owner=$s->fetch(PDO::FETCH_ASSOC);$s->closeCursor();
        if(!$owner)return ['handled'=>false,'provider_calls'=>0];
        if($trigger!=='cron')return ['handled'=>true,'state'=>'hold','reason'=>'ordinary_cron_required','provider_calls'=>0];
        if(!$authorize($owner['oauth_connection_id'],(int)$owner['admin_id']))
            return ['handled'=>true,'state'=>'hold','reason'=>'owner_authority_unavailable','provider_calls'=>0];
        $now=$clock===null?time():$clock();
        if(!is_int($now)||$now<1)throw new DomainException('development_server_clock');
        return (new DevelopmentWorkQueue($pdo,$clock))->serverTick($provider??new DevelopmentWorkServerProvider(),
            ['trigger'=>'cron','cron_id'=>bin2hex(random_bytes(32)),'started_at'=>$now],$gate);
    }
}
