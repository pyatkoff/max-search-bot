<?php
declare(strict_types=1);

require_once __DIR__.'/ConversationRecordClock.php';
require_once __DIR__.'/InternalOwnerDialogueScope.php';
require_once __DIR__.'/ProjectConfig.php';
require_once __DIR__.'/DiagnosticLogger.php';

/** Passive history of successful CRM assertions, never proof of payment or a trip. */
final class ModeledConversionOutcomeReceipt
{
    public const EVENT_TYPE='sales_pipeline_outcome_observed';
    public const PRODUCER='sales_pipeline_manager_assertion';

    /**
     * Called once after a successful authoritative update and the ordinary goal.
     * The caller owns the connection/transaction. A missing receipt is a coverage
     * gap: this observer never retries, commits, rolls back, or repairs history.
     */
    public static function observe(PDO $pdo,int $conversationId,string $outcome,?string $reason,
        int $actor,?string $saleAmount,?string $saleDate): bool
    {
        try {
            if(ProjectConfig::projectId()!=='anytour')return false;
            if($conversationId<=0||!in_array($outcome,['won','lost','open'],true))throw new RuntimeException('invalid_applied_outcome');
            if($reason!==null&&preg_match('/\A[a-z0-9_-]{1,64}\z/D',$reason)!==1)throw new RuntimeException('invalid_applied_reason');
            if($outcome!=='lost'&&$reason!==null)throw new RuntimeException('unexpected_applied_reason');
            if($outcome!=='won'&&($saleAmount!==null||$saleDate!==null))throw new RuntimeException('unexpected_applied_sale');
            if($saleAmount!==null&&preg_match('/\A(?:0|[1-9][0-9]{0,9})\.[0-9]{2}\z/D',$saleAmount)!==1)throw new RuntimeException('invalid_applied_amount');
            if($saleDate!==null){
                $date=DateTimeImmutable::createFromFormat('!Y-m-d',$saleDate);
                if(preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D',$saleDate)!==1||!$date||$date->format('Y-m-d')!==$saleDate)throw new RuntimeException('invalid_applied_date');
            }
            $q=$pdo->prepare("SELECT c.id,c.project_key,c.channel,c.customer_id FROM conversations c "
                ."JOIN customer_channels cc ON cc.id=c.customer_channel_id AND cc.customer_id=c.customer_id "
                ."AND cc.project_key=c.project_key AND cc.channel=c.channel "
                ."WHERE c.id=? AND c.project_key='anytour' AND c.channel IN ('max','telegram') AND c.is_test=0 "
                .'AND NOT '.InternalOwnerDialogueScope::conversationSql('c').' LIMIT 2');
            if(!$q->execute([$conversationId]))throw new RuntimeException('scope_read_failed');
            $rows=$q->fetchAll(PDO::FETCH_ASSOC);
            if(count($rows)!==1)return false;
            $scope=$rows[0];
            if(preg_match('/\A[1-9][0-9]{0,19}\z/D',(string)$scope['customer_id'])!==1)return false;
            $recordedAt=time();
            $payload=ConversationRecordClock::stamp([
                'version'=>1,'origin'=>'existing_sales_pipeline','producer'=>self::PRODUCER,
                'evidence_basis'=>'manager_operational_assertion',
                'scope'=>['project_key'=>'anytour','platform'=>(string)$scope['channel'],
                    'customer_key'=>(string)$scope['customer_id'],'conversation_id'=>(string)$scope['id']],
                'outcome'=>$outcome,'reason_key'=>$reason,
                'sale_amount'=>$saleAmount,'sale_amount_basis'=>'sale_revenue_assertion_not_commission',
                'sale_date'=>$saleDate,'sale_date_precision'=>$saleDate===null?'unknown':'date_only',
                'actor_role'=>$actor>0?'manager':'system',
                'inquiry_binding'=>null,'profile_id'=>null,'episode_key'=>null,'previous_outcome'=>null,
                // Receipt identity only. It is not an idempotency/replay token.
                'operation_id'=>bin2hex(random_bytes(16)),
            ],$recordedAt);
            $json=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            $q=$pdo->prepare('INSERT INTO conversation_events (conversation_id,event_type,actor_type,actor_id,payload_json) VALUES (?,?,?,?,?)');
            if(!$q->execute([$conversationId,self::EVENT_TYPE,$actor>0?'manager':'system',$actor>0?(string)$actor:null,$json])||$q->rowCount()!==1)throw new RuntimeException('receipt_write_unconfirmed');
            return true;
        } catch(Throwable $ignored) {
            // Neither exception text nor scope/actor/customer data enters diagnostics.
            try {DiagnosticLogger::log('modeled_conversion_outcome','receipt_write_failed',['coverage'=>'unknown'],null,'warning');}catch(Throwable $ignoredLog){}
            return false;
        }
    }
}
