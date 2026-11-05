<?php
declare(strict_types=1);
require_once __DIR__.'/TourChoiceService.php';

/** Staff booking record for an exact customer choice. Never calls a supplier or transport. */
final class TourBookingService
{
    private const TYPE='tour_booking_record';

    public static function read(PDO $pdo,int $conversation,string $kind,int $sourceId,string $operation='',int $actor=0): array
    {
        if (!self::key($kind,$sourceId)) return self::error('invalid_source');
        $c=self::conversation($pdo,$conversation);
        if (!$c) return self::error('not_found');
        $record=self::latest($pdo,$conversation,$kind.':'.$sourceId);
        if ($record && $record['binding']!==self::binding($c)) return self::error('context_changed');
        $source=$record['source']??self::source($pdo,$conversation,$kind,$sourceId);
        if ($source===null) return self::error('source_unavailable');
        $receipt=null;
        if ($operation!=='') {
            if (!preg_match('/\A[a-f0-9]{32}\z/D',$operation)) return self::error('invalid_operation');
            $receipt=self::receipt($pdo,$conversation,$operation);
            if ($receipt && ($receipt['source_key']!==$kind.':'.$sourceId||$receipt['actor_id']!==$actor)) return self::error('operation_mismatch');
        }
        return ['ok'=>true,'source'=>$source,'record'=>self::view($record),'operation_found'=>$receipt!==null,'saved_revision'=>$receipt['revision']??null];
    }

    /** The canonical authorization callback is repeated after current account/conversation locks. */
    public static function save(PDO $pdo,int $actor,int $conversation,array $input,callable $authorize): array
    {
        $kind=$input['source_kind']??null;$id=$input['source_id']??null;$revision=$input['revision']??null;$op=$input['operation_id']??null;
        if (!is_string($kind)||!is_int($id)||!self::key($kind,$id)) return self::error('invalid_source');
        if (!is_int($revision)||$revision<0) return self::error('invalid_revision');
        if (!is_string($op)||!preg_match('/\A[a-f0-9]{32}\z/D',$op)) return self::error('invalid_operation');
        $fields=self::normalize($input['fields']??null);
        if ($fields===null) return self::error('invalid_fields');
        $hash=hash('sha256',json_encode([$kind,$id,$revision,$fields],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        if ($pdo->inTransaction()) throw new LogicException('Booking requires its own transaction');
        $pdo->beginTransaction();
        try {
            $lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            $q=$pdo->prepare('SELECT id,is_active FROM managers WHERE id=?'.$lock);$q->execute([$actor]);$m=$q->fetch(PDO::FETCH_ASSOC);
            $c=self::conversation($pdo,$conversation,true);
            if (!$m||(int)$m['is_active']!==1||!$c||$c['status']==='closed'||!$authorize()) {$pdo->rollBack();return self::error('forbidden');}
            $old=self::latest($pdo,$conversation,$kind.':'.$id,true);
            if ($old && $old['binding']!==self::binding($c)) {$pdo->rollBack();return self::error('context_changed');}
            $receipt=self::receipt($pdo,$conversation,$op,true);
            if ($receipt) {
                $pdo->rollBack();
                if ($receipt['actor_id']!==$actor||$receipt['request_hash']!==$hash) return self::error('operation_mismatch');
                return self::read($pdo,$conversation,$kind,$id,$op,$actor);
            }
            if (($old['revision']??0)!==$revision) {$pdo->rollBack();return self::error('conflict');}
            $source=$old['source']??self::source($pdo,$conversation,$kind,$id);
            if ($source===null) {$pdo->rollBack();return self::error('source_unavailable');}
            $state=$fields['state'];
            if ($old && (($old['fields']['state']==='cancelled')||($old['fields']['state']==='confirmed'&&!in_array($state,['confirmed','cancelled'],true)))) {$pdo->rollBack();return self::error('invalid_transition');}
            if (in_array($state,['ready','confirmed'],true)) {
                if (($source['demo']??false)||$fields['amount']===''||$fields['currency']===''||!$fields['available']||$fields['checked_at']===''||$fields['verification_source']===''||$fields['terms']===''||!$fields['customer_agreed']||$fields['agreement_evidence']==='') {$pdo->rollBack();return self::error('verification_required');}
                if ($state==='ready'&&time()-strtotime($fields['checked_at'])>86400) {$pdo->rollBack();return self::error('verification_expired');}
            }
            if ($state==='confirmed'&&($fields['operator']===''||$fields['booking_reference']===''||$fields['confirmed_at']==='')) {$pdo->rollBack();return self::error('booking_evidence_required');}
            if ($state==='cancelled'&&$fields['cancellation_reason']==='') {$pdo->rollBack();return self::error('cancellation_reason_required');}
            $record=['schema'=>1,'source_key'=>$kind.':'.$id,'binding'=>self::binding($c),'source'=>$source,'revision'=>$revision+1,'fields'=>$fields,'actor_id'=>$actor,'operation_id'=>$op,'request_hash'=>$hash];
            $now=gmdate('Y-m-d\TH:i:s\Z');
            $record['updated_at']=$now;
            $q=$pdo->prepare('INSERT INTO conversation_events (conversation_id,event_type,actor_type,actor_id,payload_json,created_at) VALUES (?,?,?,?,?,?)');
            $q->execute([$conversation,self::TYPE,'manager',(string)$actor,json_encode($record,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),gmdate('Y-m-d H:i:s')]);
            $pdo->commit();
            return ['ok'=>true,'source'=>$source,'record'=>self::view($record),'operation_found'=>true,'saved_revision'=>$record['revision']];
        } catch (Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    public static function normalize($value): ?array
    {
        if (!is_array($value)||!in_array($value['state']??null,['checking','ready','confirmed','cancelled'],true)) return null;
        $out=['state'=>$value['state']];
        foreach (['amount'=>16,'currency'=>3,'checked_at'=>20,'verification_source'=>300,'terms'=>2500,'agreement_evidence'=>500,'operator'=>160,'booking_reference'=>160,'confirmed_at'=>20,'cancellation_reason'=>500] as $key=>$max) {
            $s=$value[$key]??'';
            if (!is_string($s)||preg_match('//u',$s)!==1||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u',$s)||preg_match_all('/./us',$s)>$max) return null;
            $out[$key]=trim(str_replace(["\r\n","\r"],"\n",$s));
        }
        if ($out['amount']!==''&&(!preg_match('/\A[0-9]{1,12}(?:\.[0-9]{1,2})?\z/D',$out['amount'])||(float)$out['amount']<=0)) return null;
        if ($out['currency']!==''&&!in_array($out['currency'],['RUB','EUR','USD'],true)) return null;
        foreach (['checked_at','confirmed_at'] as $key) if ($out[$key]!=='') {
            $d=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$out[$key],new DateTimeZone('UTC'));
            if (!$d||$d->format('Y-m-d\TH:i:s\Z')!==$out[$key]||$d->getTimestamp()>time()+300) return null;
        }
        foreach (['available','customer_agreed'] as $key) {if (!is_bool($value[$key]??null)) return null;$out[$key]=$value[$key];}
        return $out;
    }

    private static function source(PDO $pdo,int $conversation,string $kind,int $id): ?array
    {
        // Reuse the canonical, allowlisted preference/inquiry projection. No token or original visitor identity.
        $choices=TourChoiceService::forConversation($pdo,$conversation,20);
        foreach ($choices[$kind==='native'?'offers':($kind==='web'?'web_inquiries':'search_inquiries')]??[] as $item) {
            if ((int)($item[$kind==='native'?'offer_id':'inquiry_id']??0)!==$id||($kind==='native'&&empty($item['liked_at']))) continue;
            return ['kind'=>$kind,'id'=>$id,'demo'=>$kind==='native'&&($item['snapshot']['is_demo']??false)===true,'choice'=>$item];
        }
        return null;
    }
    private static function conversation(PDO $pdo,int $id,bool $lock=false): ?array
    {
        $q=$pdo->prepare('SELECT id,project_key,customer_id,channel,external_chat_id,status FROM conversations WHERE id=?'.($lock&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));
        $q->execute([$id]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
    }
    private static function binding(array $c): array {return ['project_key'=>(string)$c['project_key'],'customer_id'=>(int)$c['customer_id'],'channel'=>(string)$c['channel'],'external_chat_id'=>(string)$c['external_chat_id']];}
    private static function key(string $kind,int $id): bool {return in_array($kind,['native','web','search'],true)&&$id>0;}
    private static function latest(PDO $pdo,int $conversation,string $key,bool $lock=false): ?array {return self::find($pdo,$conversation,'source_key',$key,$lock);}
    private static function receipt(PDO $pdo,int $conversation,string $op,bool $lock=false): ?array {return self::find($pdo,$conversation,'operation_id',$op,$lock);}
    private static function find(PDO $pdo,int $conversation,string $field,string $value,bool $lock): ?array
    {
        $json=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?"JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.".$field."'))":"JSON_EXTRACT(payload_json,'$.".$field."')";
        $q=$pdo->prepare('SELECT payload_json FROM conversation_events WHERE conversation_id=? AND event_type=? AND '.$json.'=? ORDER BY id DESC LIMIT 1'.($lock&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));
        $q->execute([$conversation,self::TYPE,$value]);$raw=$q->fetchColumn();
        if ($raw===false) return null;
        $v=json_decode((string)$raw,true,32,JSON_THROW_ON_ERROR);
        if (!is_array($v)||($v['schema']??null)!==1) throw new RuntimeException('Invalid booking record');
        return $v;
    }
    private static function view(?array $record): ?array {return $record===null?null:array_intersect_key($record,array_flip(['revision','fields','actor_id','updated_at']));}
    private static function error(string $code): array {return ['ok'=>false,'error'=>$code];}
}
