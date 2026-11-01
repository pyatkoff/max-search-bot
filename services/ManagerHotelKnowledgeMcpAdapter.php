<?php
declare(strict_types=1);
require_once __DIR__.'/ManagerHotelDemandInventory.php';
require_once __DIR__.'/ManagerPreparationOAuth.php';

/** Legacy-admin-only caller owns fresh authorization before and after packaging. */
final class ManagerHotelKnowledgeMcpAdapter
{
    public const NAME='hotel_knowledge_demand_inventory';
    public function __construct(private PDO $pdo,private $clock=null) {}
    public static function tools(): array
    {
        $security=[['type'=>'oauth2','scopes'=>ManagerPreparationOAuth::SCOPES]];
        return [['name'=>self::NAME,'description'=>'Read a private bounded AnyTour hotel evidence inventory from the latest immutable selection publications (default/max 20, max 20 cards each). Separately counts exact verified native card deliveries and native inquiries; reports bounds, unknowns and known-test exclusions. Other sessions remain unclassified, not proven real customers. No transcripts, customer or manager identities, unique-person/trip inference, archive scan, research, provider calls, queue changes or writes. Prepared/uncertain delivery is not sent; this is not a current-trip or popularity ranking.',
            'inputSchema'=>['type'=>'object','properties'=>['limit'=>['type'=>'integer','minimum'=>1,'maximum'=>20,'default'=>20]],'additionalProperties'=>false],
            'annotations'=>['readOnlyHint'=>true,'destructiveHint'=>false,'idempotentHint'=>true,'openWorldHint'=>false],
            'securitySchemes'=>$security,'_meta'=>['securitySchemes'=>$security]]];
    }
    public function call(string $name,array $args): ?array
    {
        if($name!==self::NAME)return null;
        if(($args!==[]&&array_is_list($args))||array_diff(array_keys($args),['limit'])
            ||(array_key_exists('limit',$args)&&(!is_int($args['limit'])||$args['limit']<1||$args['limit']>20)))throw new InvalidArgumentException();
        return ManagerHotelDemandInventory::read($this->pdo,$args['limit']??20,$this->clock===null?null:(int)($this->clock)());
    }
}
