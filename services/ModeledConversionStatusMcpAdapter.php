<?php
declare(strict_types=1);
require_once __DIR__.'/ManagerPreparationOAuth.php';
require_once __DIR__.'/ModeledConversionStatusBudget.php';

/** Current private aggregate status under its own explicit OAuth capability. */
final class ModeledConversionStatusMcpAdapter
{
    public const NAME='get_modeled_conversion_status';
    public function __construct(private PDO $pdo,private $store=null){}
    public static function tools():array
    {
        $security=[['type'=>'oauth2','scopes'=>[ManagerPreparationOAuth::FORECAST_SCOPE]]];
        return [['name'=>self::NAME,
            'description'=>'Read a bounded private aggregate check of already saved AnyTour live signals and early ordinal forecasts. Checks immutable derived profile snapshots, not customer transcripts. Coverage is limited to the reported window; an idle latest tick is not proof that no forecast was saved. Read-only: no capture, manual tick, customer send, source/read-marker change, model call or advertising export.',
            'inputSchema'=>['type'=>'object','properties'=>(object)[],'additionalProperties'=>false],
            'annotations'=>['readOnlyHint'=>true,'destructiveHint'=>false,'idempotentHint'=>true,'openWorldHint'=>false],
            'securitySchemes'=>$security,'_meta'=>['securitySchemes'=>$security]]];
    }
    public function call(string $name,array $args,int $admin,string $connection,callable $authorize,ModeledConversionStatusBudget $budget):array
    {
        if($name!==self::NAME||$args!==[])throw new InvalidArgumentException();
        if($admin<=0||preg_match('/\\A[a-f0-9]{32}\\z/D',$connection)!==1)throw new DomainException();
        $budget->check();$authorize();
        if(is_callable($this->store))$result=($this->store)($admin,$connection,$budget);
        else{
            require_once __DIR__.'/ModeledConversionStatusReader.php';
            $reader=$this->store??new ModeledConversionStatusReader($this->pdo,$authorize,$budget);
            $result=$reader->read();
        }
        $budget->check();$authorize();$budget->check();
        if(!is_array($result)||array_is_list($result)
            ||strlen(json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))>30000)throw new ModeledConversionStatusLimit('byte_limit');
        return $result;
    }
}
