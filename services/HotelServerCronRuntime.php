<?php
declare(strict_types=1);
require_once __DIR__.'/HotelServerCycle.php';

/** Trusted server bootstrap only. No default provider, campaign creation or enable. */
final class HotelServerCronRuntime
{
    public static function tick(array $flags,callable $context): array
    {
        foreach(['enabled','dispatch_allowed','replacement_dispatch_allowed'] as $key)
            if(($flags[$key]??null)!==true)return ['handled'=>false,'state'=>'disabled','provider_calls'=>0];
        try{
            $v=$context();
            if(!is_array($v)||!(($v['pdo']??null) instanceof PDO)||!(($v['provider']??null) instanceof HotelServerProvider))
                return ['handled'=>true,'state'=>'hold','reason'=>'server_provider_not_configured'];
            $p=$v['pdo'];
            if($p->inTransaction()||$p->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION)
                return ['handled'=>true,'state'=>'hold','reason'=>'server_context_unavailable'];
            $s=$p->query('SELECT campaign_id,bootstrap_sha256 FROM hotel_server_campaigns WHERE enabled=1 LIMIT 2');
            $rows=$s->fetchAll(PDO::FETCH_ASSOC);$s->closeCursor();
            if(count($rows)!==1)return ['handled'=>true,'state'=>'hold','reason'=>'one_enabled_campaign_required'];
            if(array_key_exists('expected_campaign_id',$v)||array_key_exists('expected_bootstrap_sha256',$v)){
                if(($v['expected_campaign_id']??null)!==$rows[0]['campaign_id']
                    ||($v['expected_bootstrap_sha256']??null)!==$rows[0]['bootstrap_sha256'])
                    return ['handled'=>true,'state'=>'hold','reason'=>'server_campaign_pin_mismatch'];
            }
            $lane=new HotelServerLane($p);
            $cycle=new HotelServerCycle($p,$lane,$v['provider'],$v['clock']??null);
            $result=$cycle->tick($rows[0]['campaign_id']);
            // The review is durable before this separate local acceptance transaction.
            if($result['state']==='reviewed')$result=$cycle->acceptReviewed($rows[0]['campaign_id'],$result['baseline_sha256']);
            $summary=['handled'=>true,'state'=>$result['state'],'worker_completed'=>true,'historical_accepted'=>$result['historical_accepted']??19,'new_accepted'=>$result['new_accepted']??null];
            if(array_key_exists('catalog',$v))$summary['catalog']=self::projectOne($p,$lane,$rows[0]['campaign_id'],$v['catalog']);
            return $summary;
        }catch(Throwable $ignored){
            // A send could have happened. Never claim zero calls or fall back to legacy.
            return ['handled'=>true,'state'=>'hold','reason'=>'server_tick_unconfirmed'];
        }
    }

    /** One local projection per existing cron invocation; never dispatch or re-accept. */
    private static function projectOne(PDO $app,HotelServerLane $lane,string $campaign,mixed $factory): array
    {
        try{
            // Old completed/disabled campaigns retain their pending receipts after handoff.
            // The LEFT JOIN keeps a malformed/missing receipt visible to the canonical guard.
            $s=$app->prepare("SELECT o.campaign_id,o.receipt_sha256 FROM hotel_server_catalog_outbox o"
                ." JOIN hotel_server_campaigns c ON c.campaign_id=o.campaign_id"
                ." LEFT JOIN hotel_server_receipts r ON r.campaign_id=o.campaign_id AND r.receipt_sha256=o.receipt_sha256"
                ." WHERE o.state='pending' AND (o.campaign_id=? OR (c.state='complete' AND c.enabled=0))"
                ." ORDER BY o.campaign_id,r.stage_index,o.receipt_sha256 LIMIT 1");
            $s->execute([$campaign]);$row=$s->fetch(PDO::FETCH_ASSOC);$s->closeCursor();
            if(!$row)return ['state'=>'idle','projected'=>0];
            if(!is_callable($factory))throw new LogicException('catalog_factory_required');
            $catalog=$factory();
            if(!($catalog instanceof PDO)||$catalog===$app||$catalog->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION)
                throw new LogicException('separate_catalog_PDO_required');
            require_once __DIR__.'/HotelServerCatalogProjector.php';
            (new HotelServerCatalogProjector($app,$catalog,$lane))->project($row['campaign_id'],$row['receipt_sha256']);
            return ['state'=>'projected','projected'=>1];
        }catch(Throwable $ignored){
            // The catalogue may have committed before an ACK failed. Keep the durable
            // research outcome and exact pending receipt; never invent a zero-write result.
            return ['state'=>'unavailable','projected'=>null,'reason'=>'catalog_projection_unconfirmed'];
        }
    }
}

