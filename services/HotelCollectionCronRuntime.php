<?php
declare(strict_types=1);
/** Composition hook for the existing HOTEL cron. Configuration is trusted server
 * bootstrap code, never an MCP argument. No default native adapter or activation.
 */
final class HotelCollectionCronRuntime
{
    private static $tick=null;
    public static function configure(callable $tick):void
    {
        if(self::$tick!==null)throw new LogicException('hotel_cycle_runtime_already_configured');
        self::$tick=$tick;
    }
    /** Explicit trusted bootstrap only. Neither subscriptions nor task settings
     * are changed here. The provider supplies already persisted control plans,
     * never commands inferred from a webhook or a worker's free-form output.
     */
    public static function configureAutomations(callable $plans):void
    {
        self::configure(static function(PDO $pdo)use($plans):int{
            require_once __DIR__.'/HotelNativeAutomationAdapter.php';
            require_once __DIR__.'/HotelCollectionCoordinator.php';
            $batch=$plans($pdo);
            if(!is_array($batch)||!array_is_list($batch)||count($batch)>20)throw new RuntimeException('native_cron_plan_invalid');
            $processed=0;
            foreach($batch as$p){
                $keys=['admin_id','connection_id','queue_id','stage_id','generation','control_capability','event_id','intent','setup','replacement_worker_setup'];
                if(!is_array($p)||count($p)!==count($keys)||array_diff(array_keys($p),$keys)
                    ||!is_int($p['admin_id'])||!is_string($p['connection_id'])||!is_array($p['intent'])
                    ||!is_array($p['setup'])||!is_array($p['replacement_worker_setup']))throw new RuntimeException('native_cron_plan_invalid');
                // Missing/null/string/mismatched switches grant no authority.
                if(($p['setup']['dispatch_allowed']??null)!==true||($p['replacement_worker_setup']['dispatch_allowed']??null)!==true)continue;
                $adapter=new HotelNativeAutomationAdapter($pdo,$p['admin_id'],$p['connection_id']);
                $coordinator=new HotelCollectionCoordinator($pdo,$p['admin_id'],$p['connection_id'],$adapter);
                $coordinator->dispatch($p['queue_id'],$p['stage_id'],$p['generation'],$p['control_capability'],$p['event_id'],$p['intent']);
                ++$processed;
            }
            return $processed;
        });
    }
    public static function tick(PDO $pdo):array
    {
        if(self::$tick===null)return ['configured'=>false,'processed'=>0];
        $processed=(self::$tick)($pdo);
        if(!is_int($processed)||$processed<0||$processed>20)throw new RuntimeException('hotel_cycle_runtime_invalid_count');
        return ['configured'=>true,'processed'=>$processed];
    }
}
