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
            $s=$p->query('SELECT campaign_id FROM hotel_server_campaigns WHERE enabled=1 LIMIT 2');
            $ids=$s->fetchAll(PDO::FETCH_COLUMN);$s->closeCursor();
            if(count($ids)!==1)return ['handled'=>true,'state'=>'hold','reason'=>'one_enabled_campaign_required'];
            $cycle=new HotelServerCycle($p,new HotelServerLane($p),$v['provider'],$v['clock']??null);
            $result=$cycle->tick($ids[0]);
            return ['handled'=>true,'state'=>$result['state'],'historical_accepted'=>$result['historical_accepted']??19,'new_accepted'=>$result['new_accepted']??null];
        }catch(Throwable $ignored){
            // A send could have happened. Never claim zero calls or fall back to legacy.
            return ['handled'=>true,'state'=>'hold','reason'=>'server_tick_unconfirmed'];
        }
    }
}
