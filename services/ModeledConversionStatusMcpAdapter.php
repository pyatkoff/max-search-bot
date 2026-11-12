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
            'description'=>'Without arguments, read a bounded private aggregate check of already saved AnyTour V1 contextual purchase hypotheses and later payment-evidence comparisons. Checks immutable derived profile snapshots, fixed scalar input-omission diagnosis over at most100 saved versions with at most8 anonymous distinct-case summaries, fixed goal678187097 value-delivery aggregates cached Metrika goal-session totals and a saved anonymous per-event visit reconciliation cohort, not customer transcripts. With receipt_ref, read only one bounded primary saved-ledger outcome for that opaque receipt; no native IDs or money are returned. No live external API request occurs in this read. Coverage is limited to the reported window; an idle latest tick is not proof that no forecast was saved. Read-only: no capture, manual tick, customer send, source/read-marker change, model call or advertising export.',
            'inputSchema'=>['type'=>'object','properties'=>['receipt_ref'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$',
                'description'=>'Optional opaque receipt reference already returned by this private status. Reads only its saved primary ledger result; never polls or sends.']],'additionalProperties'=>false],
            'annotations'=>['readOnlyHint'=>true,'destructiveHint'=>false,'idempotentHint'=>true,'openWorldHint'=>false],
            'securitySchemes'=>$security,'_meta'=>['securitySchemes'=>$security]]];
    }
    public function call(string $name,array $args,int $admin,string $connection,callable $authorize,ModeledConversionStatusBudget $budget):array
    {
        $receipt=$args['receipt_ref']??null;
        if($name!==self::NAME||($args!==[]&&(count($args)!==1||array_keys($args)!==['receipt_ref']
            ||!is_string($receipt)||preg_match('/\A[a-f0-9]{64}\z/D',$receipt)!==1)))throw new InvalidArgumentException();
        if($admin<=0||preg_match('/\\A[a-f0-9]{32}\\z/D',$connection)!==1)throw new DomainException();
        $budget->check();$authorize();
        if(is_callable($this->store))$result=($this->store)($admin,$connection,$budget,$args);
        elseif($receipt!==null){
            require_once __DIR__.'/ModeledConversionValueDeliveryReader.php';
            $reader=new ModeledConversionValueDeliveryReader($this->pdo,$authorize,$budget);
            $result=$reader->savedReceiptStatus($receipt,time());
        }else{
            require_once __DIR__.'/ModeledConversionForecastStatusReader.php';
            $reader=$this->store??new ModeledConversionForecastStatusReader($this->pdo,$authorize,$budget);
            $result=$reader->read();
        }
        $budget->check();$authorize();$budget->check();
        if(!is_array($result)||array_is_list($result)
            ||strlen(json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))>30000)throw new ModeledConversionStatusLimit('byte_limit');
        return $result;
    }
}
