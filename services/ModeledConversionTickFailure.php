<?php
declare(strict_types=1);

/** Bounded private failure metadata shared by the writer and its read-only projection. */
final class ModeledConversionTickFailure
{
    private const PHASES=['recover','price_continuity','collect_profiles','collect_history',
        'resolve_identity','goal_check','upload','reconcile_receipts'];
    private const CATEGORIES=['storage_failed','configuration_unavailable','goal_verification_failed',
        'goal_read_failed','processing_failed','transport_not_sent','rate_limited','api_rejected','upload_failed'];

    public static function normalize(mixed $value,int $from,int $until):?array
    {
        if(!is_array($value)||!is_int($value['tick_at_unix']??null)
            ||$value['tick_at_unix']<$from||$value['tick_at_unix']>$until
            ||!in_array($value['phase']??null,self::PHASES,true)
            ||!in_array($value['category']??null,self::CATEGORIES,true))return null;
        // Rebuild only fixed fields; arbitrary exception/provider data is never retained or returned.
        return ['tick_at_unix'=>$value['tick_at_unix'],'phase'=>$value['phase'],'category'=>$value['category']];
    }

    public static function fromException(int $at,string $phase,Throwable $error):array
    {
        $category='processing_failed';
        if($error instanceof PDOException)$category='storage_failed';
        elseif($phase==='goal_check'){
            $category=match($error->getMessage()){
                'value_api_unavailable','metrika_config_missing'=>'configuration_unavailable',
                'value_goal_changed','modeled_goal_conflict','wrong_goal_counter'=>'goal_verification_failed',
                'goals_read_failed'=>'goal_read_failed',
                default=>'processing_failed',
            };
        }
        return ['tick_at_unix'=>$at,'phase'=>$phase,'category'=>$category];
    }

    public static function fromUpload(int $at,array $response):array
    {
        $reason=$response['reason']??null;
        return ['tick_at_unix'=>$at,'phase'=>'upload',
            'category'=>in_array($reason,['transport_not_sent','rate_limited','api_rejected'],true)?$reason:'upload_failed'];
    }
}
