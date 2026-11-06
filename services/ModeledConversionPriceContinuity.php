<?php
declare(strict_types=1);
require_once __DIR__.'/ModeledConversionValueContract.php';

/** Derived monetary continuity. Never infers a current trip price from an older conversation. */
final class ModeledConversionPriceContinuity
{
    public const VERSION='retained_priced_base_v1';
    public function __construct(private PDO $pdo){}

    public function adjust(array $wrapper,array $candidate,?string $beforeOutbox=null):array
    {
        $decision=['candidate'=>$candidate,'basis'=>null];
        if(empty($candidate['prediction_value']))return $decision;
        $money=ModeledConversionDealValue::saved($wrapper);
        if($money===null)throw new RuntimeException('price_current_capsule');
        if($money['deal_amount_rub']['point']!==null)return $decision;
        $scope=$wrapper['subject'];$mysql=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $safe="CASE WHEN JSON_VALID(p.profile_json) THEN p.profile_json ELSE '{}' END";
        $point="JSON_EXTRACT(".$safe.",'$.deal_value_prediction.deal_amount_rub.point')";
        $type=$mysql?'JSON_TYPE('.$point.')':"json_type(".$safe.",'$.deal_value_prediction.deal_amount_rub.point')";
        $sql="SELECT p.id,p.customer_key,JSON_EXTRACT(".$safe.",'$.subject') AS subject_json,JSON_EXTRACT(".$safe.",'$.access_status') AS access_json,"
            ."JSON_EXTRACT(".$safe.",'$.semantic_prediction') AS prediction_json,JSON_EXTRACT(".$safe.",'$.deal_value_prediction') AS money_json"
            ." FROM max_channel_conversion_outbox o JOIN modeled_conversion_profile_versions p ON p.id=o.value_profile_id"
            ." AND p.project_key=o.project_key AND p.platform=o.platform AND p.conversation_id=o.conversation_id"
            ." WHERE o.project_key='anytour' AND o.counter_id='98615635' AND o.transport_target=? AND o.value_contract=?"
            ." AND o.conversation_id=? AND o.platform=? AND o.value_rub>0 AND ".$type."=".($mysql?"'INTEGER'":"'integer'");
        $args=[ModeledConversionValueContract::TARGET,ModeledConversionValueContract::VERSION,$scope['conversation_id'],$scope['platform']];
        if($beforeOutbox!==null){$sql.=' AND o.id<?';$args[]=$beforeOutbox;}
        $q=$this->pdo->prepare($sql.' ORDER BY o.id DESC LIMIT 8');$q->execute($args);$references=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
        $bytes=0;
        foreach($references as$ref){
            foreach(['subject_json','prediction_json','money_json']as$key)$bytes+=strlen((string)($ref[$key]??''));
            if($bytes>131072)throw new RuntimeException('price_reference_budget');
            try{
                $previous=['access_status'=>json_decode($ref['access_json']??'null',true,8,JSON_THROW_ON_ERROR),'subject'=>json_decode($ref['subject_json']??'null',true,32,JSON_THROW_ON_ERROR),
                    'semantic_prediction'=>json_decode($ref['prediction_json']??'null',true,32,JSON_THROW_ON_ERROR),
                    'deal_value_prediction'=>json_decode($ref['money_json']??'null',true,32,JSON_THROW_ON_ERROR)];
            }catch(JsonException $ignored){continue;}
            if((string)$ref['customer_key']!==$scope['customer_key']||!ModeledConversionValueContract::sameSubject($previous['subject'],$scope)
                ||!is_array($previous['semantic_prediction'])||!ModeledConversionSemanticPrediction::digestMatches($previous['semantic_prediction']))continue;
            $priced=ModeledConversionDealValue::saved($previous);
            if($priced===null||!is_int($priced['deal_amount_rub']['point']))continue;
            $base=min($money['full_value_base_rub'],$priced['full_value_base_rub']);
            if($base>=$money['full_value_base_rub'])return $decision;
            $p=$wrapper['semantic_prediction'];$predictive=($p['mode']??null)==='contextual_advertising_v1'&&($p['status']??null)==='predicted';
            $weight=$predictive?$p['estimated_probability']:ModeledConversionValueContract::VALUES[$candidate['rank']]/25000;
            $adjusted=(int)round($weight*$base);
            $decision['basis']=['version'=>self::VERSION,'reason'=>'missing_current_price_retains_prior_priced_value_cap',
                'reference_profile_id'=>(string)$ref['id'],'reference_deal_rub'=>$priced['deal_amount_rub']['point'],
                'reference_base_rub'=>$priced['full_value_base_rub'],'frozen_base_rub'=>$money['full_value_base_rub'],
                'applied_base_rub'=>$base,'weight'=>$weight,'weight_basis'=>$predictive?'frozen_probability':'stage_fraction',
                'frozen_target_rub'=>$candidate['value'],'applied_target_rub'=>$adjusted];
            $decision['candidate']['value']=$adjusted;return $decision;
        }
        return $decision;
    }

    public function remember(string $id,string $action,int $original,int $applied,int $reserved,array $basis,int $now):void
    {
        $json=json_encode($basis,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if(strlen($json)>4096)throw new RuntimeException('price_audit_budget');
        $insert=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'INSERT IGNORE':'INSERT OR IGNORE';
        $q=$this->pdo->prepare($insert." INTO modeled_conversion_price_audit(outbox_id,action,original_value_rub,applied_value_rub,prior_reserved_rub,assessed_at_unix,basis_json,basis_sha256) VALUES(?,?,?,?,?,?,?,?)");
        $q->execute([$id,$action,$original,$applied,$reserved,$now,$json,hash('sha256',$json)]);
    }

    /** One existing cron tick, no network. Claims/uploads are never changed or replayed. */
    public function reconcile(int $now,callable $eligible):array
    {
        if($this->pdo->inTransaction())throw new RuntimeException('price_audit_outer_transaction');
        $mysql=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$this->pdo->beginTransaction();
        try{
            $s=$this->pdo->query("SELECT mode,goal_id,activated_at_unix FROM modeled_conversion_value_export_settings WHERE project_key='anytour'".($mysql?' FOR UPDATE':''))->fetch(PDO::FETCH_ASSOC);
            if(!$s||$s['mode']!=='send'||$s['goal_id']!=='678187097')throw new RuntimeException('price_audit_configuration');
            $q=$this->pdo->prepare("SELECT id,target FROM max_channel_conversion_outbox WHERE project_key='anytour' AND counter_id='98615635' AND transport_target=? AND value_contract=? ORDER BY id DESC LIMIT 1001");
            $q->execute([ModeledConversionValueContract::TARGET,ModeledConversionValueContract::VERSION]);$meta=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();$meta=array_reverse($meta);
            $worked=0;$unprocessed=0;
            foreach($meta as$m){
                if(!is_string($m['target'])||preg_match('/\Aanytour_value_pred_[1-9][0-9]{0,19}_v1\z/D',$m['target'])!==1)continue;
                $q=$this->pdo->prepare('SELECT outbox_id FROM modeled_conversion_price_audit WHERE outbox_id=?');$q->execute([$m['id']]);$known=$q->fetchColumn();$q->closeCursor();if($known!==false)continue;
                if($worked>=32){$unprocessed++;continue;}$worked++;
                $q=$this->pdo->prepare("SELECT * FROM max_channel_conversion_outbox WHERE id=? AND project_key='anytour'".($mysql?' FOR UPDATE':''));
                $q->execute([$m['id']]);$r=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();if(!$r)throw new RuntimeException('price_audit_row_changed');
                $original=(int)$r['value_rub'];$applied=$original;$action='unchanged';$basis=['version'=>self::VERSION,'reason'=>'no_price_loss_increase'];$reserved=0;
                $q=$this->pdo->prepare("SELECT COALESCE(SUM(value_rub),0) FROM max_channel_conversion_outbox WHERE project_key='anytour' AND conversation_id=? AND transport_target=? AND value_contract=? AND id<?");
                $q->execute([$r['conversation_id'],ModeledConversionValueContract::TARGET,ModeledConversionValueContract::VERSION,$r['id']]);$reserved=(int)$q->fetchColumn();$q->closeCursor();
                $length=$mysql?'OCTET_LENGTH(profile_json)':'LENGTH(CAST(profile_json AS BLOB))';
                $q=$this->pdo->prepare("SELECT customer_key,CASE WHEN ".$length."<=131072 THEN profile_json ELSE NULL END AS profile_json FROM modeled_conversion_profile_versions WHERE id=? AND project_key='anytour' AND platform=? AND conversation_id=?");
                $q->execute([$r['value_profile_id'],$r['platform'],$r['conversation_id']]);$profile=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
                $w=null;try{if(is_string($profile['profile_json']??null))$w=json_decode($profile['profile_json'],true,64,JSON_THROW_ON_ERROR);}catch(JsonException $ignored){}
                $subject=['project_key'=>'anytour','platform'=>$r['platform'],'customer_key'=>(string)($profile['customer_key']??''),'conversation_id'=>(string)$r['conversation_id']];
                $candidate=is_array($w)?ModeledConversionValueContract::candidate($w,$subject,(int)$s['activated_at_unix'],$now):null;
                if(!$candidate||empty($candidate['prediction_value'])||$r['target']!==ModeledConversionValueContract::predictionKey((string)$r['value_profile_id'])||!$eligible($r)){
                    $action='unverified';$basis['reason']='saved_binding_or_scope_unverified';
                }else{
                    $decision=$this->adjust($w,$candidate,(string)$r['id']);
                    if($decision['basis']!==null){
                        $basis=$decision['basis'];$expected=max(0,$decision['candidate']['value']-$reserved);
                        if($expected<$original){
                            if(in_array($r['state'],['pending','ready'],true)&&empty($r['upload_id'])&&$r['claimed_at_unix']===null&&(int)$r['attempts']===0){
                                $applied=$expected;$action=$expected===0?'voided':'reduced';
                                $q=$this->pdo->prepare("UPDATE max_channel_conversion_outbox SET value_rub=?,state=CASE WHEN ?=0 THEN 'failed' ELSE state END,last_error=CASE WHEN ?=0 THEN 'price_loss_reconciled' ELSE last_error END WHERE id=? AND state=? AND value_rub=? AND upload_id IS NULL AND claimed_at_unix IS NULL AND attempts=0");
                                $q->execute([$applied,$applied,$applied,$r['id'],$r['state'],$r['value_rub']]);
                                if($q->rowCount()!==1)throw new RuntimeException('price_audit_cas');
                            }else{$action='review_required';$basis['reason']='price_loss_upload_already_claimed_or_recorded';}
                        }
                    }
                }
                $this->remember((string)$r['id'],$action,$original,$applied,$reserved,$basis,$now);
            }
            $this->pdo->commit();
            $q=$this->pdo->query("SELECT action,COUNT(*) AS events,COALESCE(SUM(original_value_rub-applied_value_rub),0) AS removed_rub FROM (SELECT action,original_value_rub,applied_value_rub FROM modeled_conversion_price_audit ORDER BY outbox_id DESC LIMIT 1001) bounded GROUP BY action");
            $summary=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();$actions=[];
            foreach($summary as$row)$actions[$row['action']]=['events'=>(int)$row['events'],'removed_rub'=>(int)$row['removed_rub']];
            $q=$this->pdo->query("SELECT action,original_value_rub,applied_value_rub,prior_reserved_rub,assessed_at_unix FROM modeled_conversion_price_audit WHERE action IN ('voided','reduced','review_required') ORDER BY outbox_id DESC LIMIT 16");
            $corrections=[];foreach($q->fetchAll(PDO::FETCH_ASSOC)as$row)$corrections[]=['action'=>$row['action'],'original_rub'=>(int)$row['original_value_rub'],'applied_rub'=>(int)$row['applied_value_rub'],'prior_reserved_rub'=>(int)$row['prior_reserved_rub'],'assessed_at'=>gmdate('Y-m-d\TH:i:s\Z',(int)$row['assessed_at_unix'])];$q->closeCursor();
            return ['version'=>self::VERSION,'checked_at'=>gmdate('Y-m-d\TH:i:s\Z',$now),'goal_rows_scanned'=>count($meta),
                'complete'=>count($meta)<1001&&$unprocessed===0,'unprocessed_candidates'=>$unprocessed,'actions'=>$actions,'corrections'=>$corrections];
        }catch(Throwable $error){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $error;}
    }

    public function maySend(array $row):bool
    {
        if(!str_starts_with((string)$row['target'],'anytour_value_pred_'))return true;
        $q=$this->pdo->prepare("SELECT action,applied_value_rub FROM modeled_conversion_price_audit WHERE outbox_id=?");$q->execute([$row['id']]);$a=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        return $a&&in_array($a['action'],['unchanged','rebased','reduced'],true)&&(int)$a['applied_value_rub']===(int)$row['value_rub']&&(int)$row['value_rub']>0;
    }
}
