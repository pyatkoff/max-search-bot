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
    public static function tick(PDO $pdo):array
    {
        if(self::$tick===null)return ['configured'=>false,'processed'=>0];
        $processed=(self::$tick)($pdo);
        if(!is_int($processed)||$processed<0||$processed>20)throw new RuntimeException('hotel_cycle_runtime_invalid_count');
        return ['configured'=>true,'processed'=>$processed];
    }
}
