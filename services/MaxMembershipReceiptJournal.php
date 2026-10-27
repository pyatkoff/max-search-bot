<?php
declare(strict_types=1);
require_once __DIR__.'/ConversationRecordClock.php';

/** Mirrors exact tracker receipts into the existing conversation event journal. */
final class MaxMembershipReceiptJournal
{
    private PDO $pdo;
    private bool $mysql;
    public function __construct(PDO $pdo){$this->pdo=$pdo;$this->mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';}

    /** Bindings are exact external users and conversation ids selected by the existing worker. */
    public function record(array $bindings,array $receipts,int $now,int $notBefore): array
    {
        if($now<946684800||$notBefore<946684800||$notBefore>$now||count($bindings)>25)return ['inserted'=>0,'unchanged'=>0,'conflicts'=>0];
        $lock=null;
        if($this->mysql){
            $lock='max-membership-journal:anytour';
            if((string)$this->query('SELECT GET_LOCK(?,0)',[$lock])->fetchColumn()!=='1')return ['inserted'=>0,'unchanged'=>0,'conflicts'=>0];
        }
        $stats=['inserted'=>0,'unchanged'=>0,'conflicts'=>0];
        try{
            if($this->pdo->inTransaction())throw new RuntimeException('membership_outer_transaction');
            $this->pdo->beginTransaction();
            foreach($bindings as$binding){
                if(!is_array($binding))continue;
                $user=$binding['external_user_id']??null;$conversationIds=$binding['conversation_ids']??null;
                if(!self::id($user)||!is_array($conversationIds)||count($conversationIds)>10)continue;
                $conversationIds=array_values(array_unique(array_map('strval',$conversationIds)));
                $owned=$this->ownedConversations($user,$notBefore);
                // A person can have multiple dialogue/trip episodes.  Without
                // an exact episode owner, keep the membership receipt unknown.
                if(count($conversationIds)!==1||count($owned)!==1||$owned[0]!==$conversationIds[0])continue;
                $rows=$receipts[$user]??[];
                if(!is_array($rows)||count($rows)>20)continue;
                foreach($conversationIds as$conversationId){
                    if(!self::id($conversationId)||!$this->owns($conversationId,$user))continue;
                    $subscriptions=[];
                    foreach($rows as$row)if(($row['kind']??null)==='subscription'){
                        $source=(string)($row['source_record_id']??'');
                        if(!self::id($source)||($row['state']??null)!=='subscribed'||!self::time($row['occurred_at_unix']??null,$now))continue;
                        $payload=['version'=>1,'origin'=>'existing_membership_producer','source'=>'max_membership_history',
                            'identity_scope'=>'same_chat_and_source','state'=>'subscribed','occurred_at_unix'=>$row['occurred_at_unix']];
                        $subscriptions[$source]=$this->insert($conversationId,'channel_subscription_observed','max-membership:'.$source,$payload,$now,$stats);
                    }
                    foreach($rows as$row)if(($row['kind']??null)==='retention_30m'){
                        $source=(string)($row['source_record_id']??'');$subscription=(string)($row['subscription_source_record_id']??'');
                        $state=$row['state']??null;
                        if(!self::id($source)||!self::id($subscription)||!in_array($state,['retained','not_retained'],true)
                            ||($row['minimum_age_seconds']??null)!==1800||!self::time($row['occurred_at_unix']??null,$now))continue;
                        $subscriptionEvent=$subscriptions[$subscription]??$this->existingSubscription($conversationId,$subscription);
                        if($subscriptionEvent===null)continue;
                        $payload=['version'=>1,'origin'=>'existing_membership_producer','source'=>'max_membership_history',
                            'identity_scope'=>'same_chat_and_source','state'=>$state,'occurred_at_unix'=>$row['occurred_at_unix'],
                            'subscription_event_id'=>$subscriptionEvent,'minimum_age_seconds'=>1800];
                        $actor='max-retention:'.$subscription.':'.$state.':'.$source;
                        $this->insert($conversationId,'channel_retention_30m_observed',$actor,$payload,$now,$stats);
                    }
                }
            }
            $this->pdo->commit();return $stats;
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$e;}
        finally{if($lock!==null){try{$this->query('SELECT RELEASE_LOCK(?)',[$lock]);}catch(Throwable $ignored){}}}
    }

    private function ownedConversations(string $user,int $notBefore): array
    {
        $start=ConversationRecordClock::sql('c.metadata_json',$this->mysql);
        $bound=$this->mysql?'CAST(? AS UNSIGNED)':'CAST(? AS INTEGER)';
        $q=$this->query("SELECT c.id FROM conversations c JOIN customer_channels cc ON cc.id=c.customer_channel_id AND cc.project_key=c.project_key AND cc.channel=c.channel WHERE c.project_key='anytour' AND c.channel='max' AND c.is_test=0 AND c.external_chat_id=? AND cc.external_user_id=? AND ($start)>=$bound ORDER BY c.id LIMIT 2",
            ['-'.$user,$user,$notBefore]);
        return array_map('strval',$q->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Exact currently admitted AnyTour MAX subject; no identity inference. */
    private function owns(string $conversationId,string $user): bool
    {
        $q=$this->query("SELECT 1 FROM conversations c JOIN customer_channels cc ON cc.id=c.customer_channel_id AND cc.project_key=c.project_key AND cc.channel=c.channel WHERE c.id=? AND c.project_key='anytour' AND c.channel='max' AND c.is_test=0 AND c.external_chat_id=? AND cc.external_user_id=? LIMIT 1",
            [$conversationId,'-'.$user,$user]);
        return $q->fetchColumn()!==false;
    }

    private function existingSubscription(string $conversationId,string $source): ?string
    {
        $q=$this->query("SELECT id FROM conversation_events WHERE conversation_id=? AND event_type='channel_subscription_observed' AND actor_type='system' AND actor_id=? ORDER BY id LIMIT 1",
            [$conversationId,'max-membership:'.$source]);
        $id=(string)$q->fetchColumn();return self::id($id)?$id:null;
    }

    private function insert(string $conversationId,string $type,string $actor,array $payload,int $now,array &$stats): string
    {
        $json=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $q=$this->query('SELECT id,payload_json FROM conversation_events WHERE conversation_id=? AND event_type=? AND actor_type=? AND actor_id=? ORDER BY id LIMIT 1',
            [$conversationId,$type,'system',$actor]);$old=$q->fetch(PDO::FETCH_ASSOC);
        if($old&&hash_equals(self::logicalPayload((string)$old['payload_json']),$json)){$stats['unchanged']++;return(string)$old['id'];}
        if($old){
            $actor='max-membership-conflict:'.substr(hash('sha256',$type."\n".$json),0,24);
            $conflict=$this->query('SELECT id,payload_json FROM conversation_events WHERE conversation_id=? AND event_type=? AND actor_type=? AND actor_id=? ORDER BY id LIMIT 1',
                [$conversationId,$type,'system',$actor])->fetch(PDO::FETCH_ASSOC);
            if($conflict&&hash_equals(self::logicalPayload((string)$conflict['payload_json']),$json)){$stats['unchanged']++;return(string)$conflict['id'];}
            if($conflict)throw new RuntimeException('membership_conflict_key_collision');
            $stats['conflicts']++;
        }
        $wall=$this->mysql?'FROM_UNIXTIME(?)':'?';
        $this->query('INSERT INTO conversation_events (conversation_id,event_type,actor_type,actor_id,payload_json,created_at) VALUES (?,?,?,?,?,'.$wall.')',
            [$conversationId,$type,'system',$actor,json_encode(ConversationRecordClock::stamp($payload,$now),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$this->mysql?$now:gmdate('Y-m-d H:i:s',$now)]);
        $stats['inserted']++;return(string)$this->pdo->lastInsertId();
    }

    private static function logicalPayload(string $json): string
    {
        if(ConversationRecordClock::read($json)===null)return $json;
        $payload=json_decode($json,true,32,JSON_THROW_ON_ERROR);unset($payload['_recorded_clock']);
        return json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }

    private function query(string $sql,array $values): PDOStatement{$q=$this->pdo->prepare($sql);$q->execute($values);return$q;}
    private static function id($value):bool{return is_string($value)&&preg_match('/\A[1-9][0-9]{0,19}\z/D',$value)===1;}
    private static function time($value,int $now):bool{return is_int($value)&&$value>=946684800&&$value<=$now;}
}
