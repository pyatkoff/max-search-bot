<?php
declare(strict_types=1);
require_once __DIR__.'/MaxTrackerLookupProtocol.php';
require_once __DIR__.'/MaxSubscriptionAttributionPolicy.php';

/** Direct SELECT-only access on the retained Bitrix host, without Bitrix bootstrap. */
final class MaxTrackerLookupRepository
{
    private PDO $pdo;
    public function __construct(PDO $pdo) { $this->pdo=$pdo; }

    public static function fromSettings(string $settingsPath): self
    {
        if (!is_file($settingsPath)) throw new RuntimeException('tracker_unavailable');
        $level=ob_get_level(); ob_start();
        try { $s=require $settingsPath; } finally { while(ob_get_level()>$level)ob_end_clean(); }
        $d=$s['connections']['value']['default']??null;
        if (!is_array($d)) throw new RuntimeException('tracker_unavailable');
        $host=(string)($d['host']??'localhost'); $name=(string)($d['database']??'');
        if (strpbrk($host.$name,";\r\n")!==false) throw new RuntimeException('tracker_unavailable');
        $pdo=new PDO('mysql:host='.$host.';dbname='.$name.';charset=utf8mb4',(string)($d['login']??''),(string)($d['password']??''),
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>5]);
        return new self($pdo);
    }

    public function lookup(string $counterId, array $userIds, ?array $membership = null): array
    {
        $validationNow=$membership['cutoff_unix']??1;
        MaxTrackerLookupProtocol::body($counterId,$userIds,$validationNow,str_repeat('0',32),$membership);
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql' || $this->pdo->inTransaction()) throw new RuntimeException('tracker_readonly_required');
        $this->pdo->exec('SET TRANSACTION READ ONLY');
        $this->pdo->beginTransaction();
        try {
            // UF_PLATFORM in this MAX-only table is a device platform (android/ios),
            // not a messenger namespace. MAX identity comes from this store itself.
            $sql="SELECT s.ID,s.UF_CLIENT_ID,s.UF_DATE FROM max_sub_links s WHERE s.UF_CHAT_ID=?"
                ." AND s.UF_CLIENT_ID REGEXP '^[1-9][0-9]{0,39}$' AND s.UF_CLIENT_ID NOT REGEXP '[^0-9]'"
                ." AND EXISTS (SELECT 1 FROM b_iblock_element_property ep JOIN b_iblock_property p ON p.ID=ep.IBLOCK_PROPERTY_ID"
                ." WHERE p.IBLOCK_ID=8 AND p.CODE='YA_METRIK_ID' AND ep.IBLOCK_ELEMENT_ID="
                ." CASE WHEN s.UF_METRIC_SOURCE IS NOT NULL AND s.UF_METRIC_SOURCE<>'' THEN s.UF_METRIC_SOURCE ELSE s.UF_SOURCE END AND ep.VALUE=?)"
                ." ORDER BY s.ID DESC LIMIT 1";
            $q=$this->pdo->prepare($sql); $found=[];
            foreach ($userIds as $userId) {
                $q->execute([$userId,$counterId]); $r=$q->fetch();
                if (!$r) { $found[]=['external_user_id'=>$userId,'found'=>false]; continue; }
                $cid=(string)$r['UF_CLIENT_ID']; $record=(string)$r['ID'];
                if (!MaxTrackerLookupProtocol::id($cid,40)||!MaxTrackerLookupProtocol::id($record)) throw new RuntimeException('tracker_invalid_record');
                $observed=null;
                try { $observed=MaxSubscriptionAttributionPolicy::eventTimeUnix((string)$r['UF_DATE'],new DateTimeZone('Europe/London')); } catch (InvalidArgumentException $ignored) {}
                $found[]=['external_user_id'=>$userId,'found'=>true,'client_id'=>$cid,'record_id'=>$record,'observed_at_unix'=>$observed];
            }
            if ($membership !== null) {
                $receipts=$this->membershipReceipts($counterId,$userIds,$membership);
                foreach ($found as &$row) $row['membership_receipts']=$receipts[$row['external_user_id']]??[];
                unset($row);
            }
            return $found;
        } finally { if($this->pdo->inTransaction())$this->pdo->rollBack(); }
    }

    /** Exact per-chat history after the caller's prospective fence; current state is never backdated. */
    private function membershipReceipts(string $counterId,array $userIds,array $window): array
    {
        $historyTable=$this->tableName(10);$mainTable=$this->tableName(9);$retentionTable=$this->tableName(12);
        $sql='SELECT h.ID,h.UF_CHAT_ID,h.UF_SOURCE,h.UF_TYPE,h.UF_DATE FROM `'.$historyTable.'` h'
            .' WHERE h.UF_CHAT_ID=? AND h.UF_TYPE IN (1,2) AND h.UF_DATE>=? AND h.UF_DATE<=?'
            .' AND EXISTS (SELECT 1 FROM b_iblock_element_property ep JOIN b_iblock_property p ON p.ID=ep.IBLOCK_PROPERTY_ID'
            ." WHERE p.IBLOCK_ID=8 AND p.CODE='YA_METRIK_ID' AND ep.IBLOCK_ELEMENT_ID=h.UF_SOURCE AND ep.VALUE=?)"
            .' ORDER BY h.UF_DATE,h.ID LIMIT 21';
        $q=$this->pdo->prepare($sql);$out=[];
        $source="CASE WHEN s.UF_METRIC_SOURCE IS NOT NULL AND s.UF_METRIC_SOURCE<>'' THEN s.UF_METRIC_SOURCE ELSE s.UF_SOURCE END";
        $retentionSql='SELECT r.ID,r.UF_SUB_ID,r.UF_DATE,s.UF_SUB_DATE,s.UF_TYPE,'.$source.' AS LINK_SOURCE FROM `'.$retentionTable.'` r'
            .' JOIN `'.$mainTable.'` s ON s.ID=r.UF_SUB_ID WHERE s.UF_CHAT_ID=? AND r.UF_DATE>=? AND r.UF_DATE<=?'
            .' AND EXISTS (SELECT 1 FROM b_iblock_element_property ep JOIN b_iblock_property p ON p.ID=ep.IBLOCK_PROPERTY_ID'
            ." WHERE p.IBLOCK_ID=8 AND p.CODE='YA_METRIK_ID' AND ep.IBLOCK_ELEMENT_ID=".$source.' AND ep.VALUE=?)'
            .' ORDER BY r.UF_DATE,r.ID LIMIT 21';
        $retentionQuery=$this->pdo->prepare($retentionSql);
        $zone=new DateTimeZone('Europe/London');
        $from=(new DateTimeImmutable('@'.(int)$window['not_before_unix']))->setTimezone($zone)->format('Y-m-d H:i:s');
        $to=(new DateTimeImmutable('@'.(int)$window['cutoff_unix']))->setTimezone($zone)->format('Y-m-d H:i:s');
        $budget=100;
        foreach($userIds as$userId){
            $q->execute([$userId,$from,$to,$counterId]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
            $retentionQuery->execute([$userId,$from,$to,$counterId]);$checkRows=$retentionQuery->fetchAll(PDO::FETCH_ASSOC);
            if(count($rows)>20||count($checkRows)>20){$out[$userId]=[];continue;}
            $checks=[];$invalid=false;
            foreach($checkRows as$row){
                $record=(string)$row['ID'];$subscription=(string)$row['UF_SUB_ID'];$sourceId=(string)$row['LINK_SOURCE'];$type=(string)$row['UF_TYPE'];
                if(!MaxTrackerLookupProtocol::id($record)||!MaxTrackerLookupProtocol::id($subscription)
                    ||!MaxTrackerLookupProtocol::id($sourceId)||$type!=='1'){$invalid=true;break;}
                try{$at=MaxSubscriptionAttributionPolicy::eventTimeUnix((string)$row['UF_DATE'],$zone);
                    $subscriptionAt=MaxSubscriptionAttributionPolicy::eventTimeUnix((string)$row['UF_SUB_DATE'],$zone);}
                catch(InvalidArgumentException $ignored){$invalid=true;break;}
                if($at<(int)$window['not_before_unix']||$at>(int)$window['cutoff_unix'])continue;
                $checks[]=['record_id'=>$record,'subscription_id'=>$subscription,'source'=>$sourceId,'at'=>$at,'subscription_at'=>$subscriptionAt];
            }
            if($invalid){$out[$userId]=[];continue;}
            $periods=[];$open=[];
            foreach($rows as$row){
                $record=(string)$row['ID'];$type=(string)$row['UF_TYPE'];$sourceId=(string)$row['UF_SOURCE'];
                if(!MaxTrackerLookupProtocol::id($record)||!MaxTrackerLookupProtocol::id((string)$row['UF_CHAT_ID'])
                    ||!MaxTrackerLookupProtocol::id($sourceId)||!in_array($type,['1','2'],true)){$invalid=true;break;}
                try{$at=MaxSubscriptionAttributionPolicy::eventTimeUnix((string)$row['UF_DATE'],$zone);}
                catch(InvalidArgumentException $ignored){$invalid=true;break;}
                if($at<(int)$window['not_before_unix']||$at>(int)$window['cutoff_unix'])continue;
                if($type==='1'){
                    // Repeated user_added while the same period is still open
                    // is another observation of that state, not a rejoin.
                    if(isset($open[$sourceId])&&$periods[$open[$sourceId]]['left']===null)continue;
                    $periods[]=['record_id'=>$record,'source'=>$sourceId,'at'=>$at,'left'=>null];$open[$sourceId]=count($periods)-1;
                }elseif(isset($open[$sourceId])&&$periods[$open[$sourceId]]['left']===null){
                    $periods[$open[$sourceId]]['left']=['record_id'=>$record,'at'=>$at];unset($open[$sourceId]);
                }
            }
            if($invalid){$out[$userId]=[];continue;}
            $receipts=[];
            foreach($periods as$period){
                $receipts[]=['kind'=>'subscription','source_record_id'=>$period['record_id'],
                    'state'=>'subscribed','occurred_at_unix'=>$period['at']];
                $check=$period['at']+1800;
                if($check>(int)$window['cutoff_unix'])continue;
                $left=$period['left'];$notRetained=$left!==null&&$left['at']<=$check;
                if($notRetained){
                    $receipts[]=['kind'=>'retention_30m','source_record_id'=>$left['record_id'],
                        'subscription_source_record_id'=>$period['record_id'],'state'=>'not_retained',
                        'occurred_at_unix'=>$check,'minimum_age_seconds'=>1800];
                    continue;
                }
                foreach($checks as$actual)if($actual['source']===$period['source']&&$actual['subscription_at']===$period['at']
                    &&$actual['at']>=$check&&($left===null||$left['at']>$actual['at'])){
                    $receipts[]=['kind'=>'retention_30m','source_record_id'=>$actual['record_id'],
                        'subscription_source_record_id'=>$period['record_id'],'state'=>'retained',
                        'occurred_at_unix'=>$actual['at'],'minimum_age_seconds'=>1800];break;
                }
            }
            if(count($receipts)>20){$out[$userId]=[];continue;}
            if(count($receipts)>$budget)throw new RuntimeException('tracker_membership_response_too_large');
            $budget-=count($receipts);
            $out[$userId]=$receipts;
        }
        return $out;
    }

    private function tableName(int $id): string
    {
        $q=$this->pdo->prepare('SELECT TABLE_NAME FROM b_hlblock_entity WHERE ID=?');$q->execute([$id]);$table=(string)$q->fetchColumn();
        if(!preg_match('/\A[a-z0-9_]{1,64}\z/D',$table))throw new RuntimeException('tracker_membership_unavailable');
        return$table;
    }
}
