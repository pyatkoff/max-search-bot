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
            'description'=>'Without arguments, read bounded private operational delivery status: complete Moscow-day accrual, API-receipt and processing-observation aggregates for MAX and Telegram using the existing owner reconciliation scope, saved processing state and monitor health. The separate recent ledger suffix is explicitly bounded and is not a daily total. A separate common_v2 block reads saved MAX2 V2 state, bounded current-activation increments and exact saved receipt evidence; its initial reserve is only for the included bound accounts, not all early MAX2 launches. V2 saved processing does not prove a visit or Direct attribution. Historical repair and Native source readers are not executed in this default view. With date (YYYY-MM-DD) and optional platform (all, max or telegram), read only the selected Moscow-day saved reconciliation under the same owner scope and budget. Selected-day row states are current, not an end-of-day snapshot; receipt and processing observation dates do not prove individual visit linkage. This date form is mutually exclusive with all other arguments. With visit_reconciliation:true, read only the existing saved anonymous visit-reconciliation checkpoint, cohort totals and per-event reasons under the same read budget; no fresh visit check or financial action occurs. With primary_source_review:true, read only the three frozen original-event bindings and their existing latest MAX traffic files; no Native or provider query, financial permission or history completeness is implied. With receipt_ref, read only one bounded primary saved-ledger outcome for that opaque receipt; no native IDs or money are returned. No live external API request occurs in this read. Coverage is limited to the reported window; an idle latest tick is not proof that no forecast was saved. Read-only: no capture, manual tick, customer send, source/read-marker change, model call or advertising export.',
            'inputSchema'=>['type'=>'object','properties'=>['receipt_ref'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$',
                'description'=>'Optional opaque receipt reference already returned by this private status. Reads only its saved primary ledger result; never polls or sends.'],
                'primary_source_review'=>['type'=>'boolean','const'=>true,'description'=>'Inspect only the existing latest traffic files for the three frozen original events; mutually exclusive with receipt_ref and visit_reconciliation. Read-only hints, no provider query or financial admission.'],
                'visit_reconciliation'=>['type'=>'boolean','const'=>true,'description'=>'Read only the existing saved anonymous visit-reconciliation evidence; mutually exclusive with all other arguments. Never refreshes, polls, sends or changes the original cohort.'],
                'date'=>['type'=>'string','pattern'=>'^20[0-9]{2}-[0-9]{2}-[0-9]{2}$','description'=>'Explicit valid calendar date in Europe/Moscow, no later than today. Selects only saved day aggregates; mutually exclusive with receipt_ref, primary_source_review and visit_reconciliation.'],
                'platform'=>['type'=>'string','enum'=>['all','max','telegram'],'description'=>'Optional filter only with date; defaults to all. Does not resolve identities or send conversions.']],'additionalProperties'=>false],
            'annotations'=>['readOnlyHint'=>true,'destructiveHint'=>false,'idempotentHint'=>true,'openWorldHint'=>false],
            'securitySchemes'=>$security,'_meta'=>['securitySchemes'=>$security]]];
    }
    public function call(string $name,array $args,int $admin,string $connection,callable $authorize,ModeledConversionStatusBudget $budget):array
    {
        $receipt=$args['receipt_ref']??null;
        $primary=$args===['primary_source_review'=>true];
        $visit=$args===['visit_reconciliation'=>true];
        $daily=self::validDayInput($args);
        $validReceipt=count($args)===1&&array_keys($args)===['receipt_ref']
            &&is_string($receipt)&&preg_match('/\A[a-f0-9]{64}\z/D',$receipt)===1;
        if($name!==self::NAME||($args!==[]&&!$validReceipt&&!$primary&&!$visit&&!$daily))throw new InvalidArgumentException();
        if($admin<=0||preg_match('/\\A[a-f0-9]{32}\\z/D',$connection)!==1)throw new DomainException();
        $budget->check();$authorize();
        if(is_callable($this->store))$result=($this->store)($admin,$connection,$budget,$args);
        elseif($daily){
            require_once __DIR__.'/ModeledConversionDailyReconciliationReader.php';
            $result=(new ModeledConversionDailyReconciliationReader($this->pdo,$authorize,null,$budget))->read($args,time());
        }
        elseif($visit){
            require_once __DIR__.'/ModeledConversionVisitProof.php';
            $result=ModeledConversionVisitProof::privateSummary(time(),$budget);
        }
        elseif($primary){
            require_once __DIR__.'/ModeledConversionPrimarySourceReader.php';
            $result=(new ModeledConversionPrimarySourceReader($this->pdo,$authorize,$budget))->read(time());
        }
        elseif($receipt!==null){
            require_once __DIR__.'/ModeledConversionValueDeliveryReader.php';
            $reader=new ModeledConversionValueDeliveryReader($this->pdo,$authorize,$budget);
            $result=$reader->savedReceiptStatus($receipt,time(),true);
        }else{
            require_once __DIR__.'/ModeledConversionForecastStatusReader.php';
            $reader=$this->store??new ModeledConversionForecastStatusReader($this->pdo,$authorize,$budget);
            $result=$reader->readOperational();
        }
        $budget->check();$authorize();$budget->check();
        if(!is_array($result)||array_is_list($result)
            ||strlen(json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))>30000)throw new ModeledConversionStatusLimit('byte_limit');
        return $result;
    }
    private static function validDayInput(array $args):bool
    {
        if(!isset($args['date'])||array_diff(array_keys($args),['date','platform'])
            ||!is_string($args['date'])||preg_match('/\A20[0-9]{2}-[0-9]{2}-[0-9]{2}\z/D',$args['date'])!==1
            ||(array_key_exists('platform',$args)&&!in_array($args['platform'],['all','max','telegram'],true)))return false;
        $day=DateTimeImmutable::createFromFormat('!Y-m-d',$args['date'],new DateTimeZone('Europe/Moscow'));
        return $day!==false&&$day->format('Y-m-d')===$args['date']&&$day->getTimestamp()<=time();
    }
}
