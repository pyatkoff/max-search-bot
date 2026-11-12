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
            'description'=>'Without arguments, read a bounded private aggregate check of already saved AnyTour V1 contextual purchase hypotheses and later payment-evidence comparisons. Checks immutable derived profile snapshots, fixed scalar input-omission diagnosis over at most100 saved versions with at most8 anonymous distinct-case summaries, fixed goal678187097 value-delivery aggregates cached Metrika goal-session totals and a saved anonymous per-event visit reconciliation cohort, not customer transcripts. With receipt_ref, read only one bounded primary saved-ledger outcome for that opaque receipt; no native IDs or money are returned. With view=retained_relation_outcomes, read the saved two-case outcome projection and its current original-case binding without the aggregate roster; no provider or source request occurs. No live external API request occurs in this read. Coverage is limited to the reported window; an idle latest tick is not proof that no forecast was saved. Read-only: no capture, manual tick, customer send, source/read-marker change, model call or advertising export.',
            'inputSchema'=>['type'=>'object','properties'=>['receipt_ref'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$',
                'description'=>'Optional opaque receipt reference already returned by this private status. Reads only its saved primary ledger result; never polls or sends.'],
                'view'=>['type'=>'string','enum'=>['retained_relation_outcomes'],
                    'description'=>'Optional saved-only two-case outcomes with their current original-case binding. Mutually exclusive with receipt_ref; does not poll, send or change the default aggregate.']],'additionalProperties'=>false],
            'annotations'=>['readOnlyHint'=>true,'destructiveHint'=>false,'idempotentHint'=>true,'openWorldHint'=>false],
            'securitySchemes'=>$security,'_meta'=>['securitySchemes'=>$security]]];
    }
    public function call(string $name,array $args,int $admin,string $connection,callable $authorize,ModeledConversionStatusBudget $budget):array
    {
        $receipt=$args['receipt_ref']??null;$view=$args['view']??null;
        $valid=$args===[]||(count($args)===1&&(
            (array_keys($args)===['receipt_ref']&&is_string($receipt)&&preg_match('/\A[a-f0-9]{64}\z/D',$receipt)===1)
            ||(array_keys($args)===['view']&&$view==='retained_relation_outcomes')));
        if($name!==self::NAME||!$valid)throw new InvalidArgumentException();
        if($admin<=0||preg_match('/\\A[a-f0-9]{32}\\z/D',$connection)!==1)throw new DomainException();
        $budget->check();$authorize();
        if(is_callable($this->store))$result=($this->store)($admin,$connection,$budget,$args);
        elseif($receipt!==null){
            require_once __DIR__.'/ModeledConversionValueDeliveryReader.php';
            $reader=new ModeledConversionValueDeliveryReader($this->pdo,$authorize,$budget);
            $result=$reader->savedReceiptStatus($receipt,time());
        }elseif($view!==null){
            require_once __DIR__.'/ModeledConversionValueDeliveryReader.php';
            $reader=new ModeledConversionValueDeliveryReader($this->pdo,$authorize,$budget);
            $result=$this->retainedOutcomeView($reader->read(time()),time());
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

    /** Project existing validated saved evidence before the aggregate-only response budget is applied. */
    private function retainedOutcomeView(array $delivery,int $now):array
    {
        $base=['view'=>'retained_relation_outcomes','basis'=>'saved_bound_retained_relation_outcomes',
            'goal_id'=>'678187097','counter_id'=>'98615635','checked_at'=>gmdate('Y-m-d\TH:i:s\Z',$now),
            'provider_requests'=>0,'conversion_uploads'=>0,'financial_permission'=>false,'current_eligibility'=>'not_assessed'];
        if(($delivery['available']??false)!==true)
            return ['available'=>false,'reason'=>'delivery_evidence_unavailable']+$base;
        $repair=$delivery['repair_assessments']??[];
        $original=$repair['finite_visit_evidence']??[];
        if(($repair['available']??false)!==true||($original['available']??false)!==true)
            return ['available'=>false,'reason'=>'original_current_binding_unavailable']+$base;
        $outcomes=$repair['retained_relation_outcomes']??null;
        if(!is_array($outcomes))
            return ['available'=>false,'reason'=>'saved_outcome_projection_unavailable']+$base;
        // The delivery reader owns the exact namespace/frame, two-PK cache whitelist and read limits.
        // No historical roster, monetary aggregate, profile body or raw capsule is copied into this view.
        return ['available'=>($outcomes['available']??false)===true]+$base+
            ['original_cases'=>$original,'retained_relation_outcomes'=>$outcomes];
    }
}
