<?php
declare(strict_types=1);
require_once __DIR__.'/Max2CommonFrameV2.php';
require_once __DIR__.'/Max2CommonEntryEvidenceV2.php';
if (!class_exists('Max2ValueBindingPolicyV2',false)) require_once __DIR__.'/Max2ValueBindingPolicyV2.php';

/** Joins a saved substantive inquiry to its own authenticated native message frame. */
final class Max2CommonValueBindingV2
{
    private Closure $identity;
    public function __construct(private PDO $pdo, private string $mapFile, callable $savedIdentity)
    { $this->identity = Closure::fromCallable($savedIdentity); }
    private static function hold(string $reason): array { return ['status'=>'HOLD','reason'=>$reason,'provider_calls'=>0]; }
    private function rows(string $sql, array $args): array
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') $sql = preg_replace('/\ASELECT /','SELECT /*+ MAX_EXECUTION_TIME(250) */ ',$sql,1);
        $q = $this->pdo->prepare($sql); $q->execute($args); $r = $q->fetchAll(PDO::FETCH_ASSOC); $q->closeCursor(); return $r;
    }
    private function frame(string $key, array $s, array $a): ?array
    {
        if (!Max2CommonFrameV2::sha($key)) return null;
        $length = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'OCTET_LENGTH(frame_json)' : 'LENGTH(CAST(frame_json AS BLOB))';
        $rows = $this->rows('SELECT frame_key,project_key,platform,external_user_id,customer_channel_id,customer_key,conversation_id,'
            .'entry_id,publication_id,version_id,entry_key,observed_at_unix,frame_sha256,activation_sha256,'
            .'CASE WHEN '.$length.'<=16384 THEN frame_json ELSE NULL END AS frame_json FROM max2_common_entry_frames_v2 WHERE frame_key=? LIMIT 1',[$key]);
        if (count($rows)!==1) return null;
        $r = $rows[0]; $f = Max2CommonFrameV2::decoded($r['frame_json'],$r['frame_sha256']);
        if ($f === null || ($f['version'] ?? null)!==1 || ($f['frame_key'] ?? null)!==$key || $r['frame_key']!==$key
            || $r['project_key']!==$s['project_key'] || $r['platform']!==$s['platform']
            || (string)$r['customer_key']!==$s['customer_key'] || (string)$r['conversation_id']!==$s['conversation_id']
            || $r['activation_sha256']!==$a['activation_sha256'] || ($f['activation_sha256'] ?? null)!==$a['activation_sha256']
            || !is_int($f['observed_at_unix'] ?? null) || (string)$f['observed_at_unix']!==(string)$r['observed_at_unix']
            || $f['observed_at_unix']<$a['activated_at_unix']) return null;
        return ['record'=>$r,'frame'=>$f];
    }
    private static function actor(array $fromFrame, array $saved, array $row): bool
    {
        if (!Max2ValueBindingPolicyV2::sameSubject($fromFrame['subject'] ?? null,$saved['subject'])) return false;
        foreach (['external_user_id','external_chat_id','customer_channel_id'] as $k)
            if (($fromFrame[$k] ?? null)!==($saved['actor_context'][$k] ?? null)) return false;
        return ($row['external_user_id'] ?? null)===$fromFrame['external_user_id']
            && (string)($row['customer_channel_id'] ?? '')===$fromFrame['customer_channel_id']
            && Max2CommonFrameV2::sha($fromFrame['subject_actor_binding_sha256'] ?? null);
    }
    /** Gateway and claim reader are internal app objects, never user-provided DTOs. */
    public function project(array $saved, array $activation, object $gateway, object $claims, int $now): array
    {
        if (!Max2CommonFrameV2::active($activation,$now) || ($saved['status'] ?? null)!=='ADMITTED_SAVED_VALUE'
            || !Max2ValueBindingPolicyV2::subject($saved['subject'] ?? null) || !is_array($saved['inquiry'] ?? null)
            || !is_array($saved['estimate'] ?? null) || !is_array($saved['actor_context'] ?? null)
            || $this->pdo->inTransaction()) return self::hold('SAVED_PROJECTION_REQUIRED');
        try {
            $s = $saved['subject']; $inquiry = $saved['inquiry'];
            $key = Max2ValueBindingPolicyV2::inquiryKey($s,$inquiry['journal_id']);
            if ($s['platform']==='telegram') return $this->telegram($saved,$activation,$key,$now);
            $messageKey = Max2CommonFrameV2::messageKey($s,$inquiry['native_message_id']);
            $message = $this->frame($messageKey,$s,$activation);
            if ($message === null) return self::hold('AUTHENTICATED_INQUIRY_FRAME_MISSING');
            $mf = $message['frame']; $mr = $message['record'];
            if (($mf['origin'] ?? null)!=='authenticated_max_customer_message_v2'
                || ($mf['native_message_id'] ?? null)!==$inquiry['native_message_id']
                || !is_array($mf['actor'] ?? null) || !self::actor($mf['actor'],$saved,$mr)
                || $mr['entry_id']!==null || $mr['publication_id']!==null || $mr['version_id']!==null || $mr['entry_key']!==null
                || $mf['observed_at_unix']<$inquiry['recorded_at_unix'] || $mf['observed_at_unix']>$saved['estimate']['profile_observed_at_unix']
                || $mf['observed_at_unix']-$inquiry['recorded_at_unix']>300) return self::hold('AUTHENTICATED_INQUIRY_FRAME_CONFLICT');
            $native = $this->rows('SELECT external_message_id,sender_id FROM messages WHERE id=? AND conversation_id=? AND channel=? AND direction=? AND sender_type=? LIMIT 1',
                [$inquiry['native_message_id'],$s['conversation_id'],'max','inbound','customer']);
            if (count($native)!==1 || !is_string($native[0]['external_message_id']) || (string)$native[0]['sender_id']!==$mf['actor']['external_user_id']
                || hash('sha256',$native[0]['external_message_id'])!==($mf['native_external_message_sha256'] ?? null)) return self::hold('INQUIRY_NATIVE_FRAME_CHANGED');
            $actor = $mf['actor']; $actor['authenticated_actor'] = true;
            $context = ['event_ref'=>$key,'frame_sha256'=>$mr['frame_sha256'],'cutoff_unix'=>$inquiry['recorded_at_unix']];
            $private = $gateway->lookup($actor['external_user_id'],$context);
            if (!is_array($private) || !is_array($private['receipt'] ?? null)) return self::hold('OWN_EARLY_LOOKUP_UNAVAILABLE');
            $r = $private['receipt'];
            if (($r['action'] ?? null)!=='list' || ($r['context'] ?? null)!==$context || ($r['external_user_id'] ?? null)!==$actor['external_user_id']
                || ($r['coverage'] ?? null)!=='all_exact_actor_own_early_reserves_at_observation'
                || ($r['complete_within_bound'] ?? null)!==true || ($r['possible_max2_unresolved'] ?? null)!==false
                || !is_array($r['candidates'] ?? null) || !array_is_list($r['candidates'])
                || !Max2CommonFrameV2::sha($private['source_receipt_sha256'] ?? null)) return self::hold('OWN_EARLY_LOOKUP_INCOMPLETE');
            $proof = ['version'=>2,'counter_id'=>98615635,'subject'=>$s,'inquiry_key'=>$key,
                'source_receipt_sha256'=>$private['source_receipt_sha256'],'complete'=>true,'own_reserve_only'=>$r['own_reserve_only'] ?? null,
                'producer_contract_sha256'=>$r['producer_contract_sha256'] ?? null,'observed_at_unix'=>$r['checked_at_unix'] ?? null,
                'blank_row_count'=>$r['blank_row_count'] ?? null,'cutoff_unix'=>$inquiry['recorded_at_unix'],
                'candidates'=>$r['candidates'],'status'=>$r['status'] ?? null,'coverage'=>$r['coverage'],
                'possible_max2_unresolved'=>false,'actor_binding'=>$actor];
            if (count($proof['candidates'])===1) {
                if (($mf['prospective_entry_count_bound'] ?? null)!==1 || !Max2CommonFrameV2::sha($mf['entry_frame_key'] ?? null)) return self::hold('EXACT_INQUIRY_ENTRY_UNPROVED');
                $entry = $this->frame($mf['entry_frame_key'],$s,$activation);
                if ($entry === null) return self::hold('ENTRY_FRAME_MISSING');
                $ef = $entry['frame']; $er = $entry['record']; $e = $ef['entry'] ?? null;
                if (($ef['origin'] ?? null)!=='authenticated_max_post_entry_v2' || !is_array($e) || !self::actor($e,$saved,$er)
                    || $ef['observed_at_unix']>$inquiry['recorded_at_unix']) return self::hold('INQUIRY_ENTRY_FRAME_CONFLICT');
                foreach (['entry_id','publication_id','version_id','entry_key'] as $field)
                    if ((string)($er[$field] ?? '')!==($e[$field] ?? null)) return self::hold('ENTRY_FRAME_COLUMNS_CHANGED');
                $current = (new Max2CommonEntryEvidenceV2($this->pdo,$this->mapFile))->read($e['entry_id'],$s['conversation_id'],$actor['external_user_id'],$e['source_event_key'] ?? '');
                if ($current === null || $current!=$e) return self::hold('IMMUTABLE_PUBLICATION_PAIR_CHANGED');
                $e['authenticated_actor']=true; $e['exact_inquiry_context']=true; $proof['entry']=$e;
                $candidate=$proof['candidates'][0]; $claim=$claims->receiptClaim($candidate['tracker_record_id'],$key);
                if (($claim['status'] ?? null)!=='OBSERVED') return self::hold('OWN_RECEIPT_CLAIM_UNKNOWN');
                if (isset($claim['immutable_tuple_sha256']) && $claim['immutable_tuple_sha256']!==$candidate['immutable_tuple_sha256']) return self::hold('OWN_RECEIPT_TUPLE_CHANGED');
                $candidate['binding_state']=$claim['binding_state'];
                if (isset($claim['bound_inquiry_key'])) $candidate['bound_inquiry_key']=$claim['bound_inquiry_key'];
                $proof['candidates'][0]=$candidate;
            }
            $binding=Max2ValueBindingPolicyV2::resolve($s,$inquiry,$proof);
            if (($binding['status'] ?? null)==='HOLD') return $binding;
            if ($binding['status']==='UNIQUE_OBSERVED_MAX2') {
                $checked=$gateway->recheck($private,$binding['tracker_record_id']);
                if (!is_array($checked) || !is_array($checked['receipt'] ?? null)) return self::hold('EXACT_EARLY_RECHECK_UNAVAILABLE');
                $rr=$checked['receipt']; $rc=$rr['candidates'][0] ?? null;
                if (($rr['status'] ?? null)!=='match' || ($rr['action'] ?? null)!=='recheck'
                    || ($rr['coverage'] ?? null)!=='exact_selected_record_only' || !is_array($rc)
                    || ($rc['tracker_record_id'] ?? null)!==$binding['tracker_record_id'] || ($rc['immutable_tuple_sha256'] ?? null)!==$binding['immutable_tuple_sha256'])
                    return self::hold('EXACT_EARLY_RECHECK_CHANGED');
                $identity=['admitted'=>true,'subject'=>$s,'inquiry_key'=>$key,'client_id'=>$binding['client_id'],
                    'basis'=>'bound_max2_receipt','source_sha256'=>$private['source_receipt_sha256']];
                $recheck=['status'=>'match','action'=>'recheck','coverage'=>'exact_selected_record_only',
                    'tracker_record_id'=>$rc['tracker_record_id'],'immutable_tuple_sha256'=>$rc['immutable_tuple_sha256'],
                    'source_sha256'=>$checked['source_receipt_sha256'],'checked_at_unix'=>$rr['checked_at_unix']];
            } else { $identity=($this->identity)($saved,$key); $recheck=[]; }
            if (!is_array($identity)) return self::hold('SAVED_DELIVERY_IDENTITY_UNAVAILABLE');
            return ['status'=>'READY','binding'=>$binding,'estimate'=>$saved['estimate'],'identity'=>$identity,'recheck'=>$recheck];
        } catch (Throwable $ignored) { return self::hold('BINDING_SOURCE_UNAVAILABLE'); }
    }
    private function telegram(array $saved,array $activation,string $key,int $now): array
    {
        $s=$saved['subject']; $inquiry=$saved['inquiry'];
        // This first release admits only a native Telegram origin without a linked
        // MAX customer channel. Cross-channel identity is not guessed.
        $cross=$this->rows("SELECT id FROM customer_channels WHERE project_key='anytour' AND customer_id=? AND channel='max' LIMIT 1",[$s['customer_key']]);
        if ($cross!==[]) return self::hold('CROSS_CHANNEL_EARLY_RESERVE_UNRESOLVED');
        $identity=($this->identity)($saved,$key);
        if (!is_array($identity) || ($identity['admitted'] ?? null)!==true || !Max2CommonFrameV2::sha($identity['source_sha256'] ?? null))
            return self::hold('SAVED_DELIVERY_IDENTITY_UNAVAILABLE');
        $source=hash('sha256',Max2CommonFrameV2::canonical(['purpose'=>'max2/common/native-telegram-origin/v2','subject'=>$s,
            'inquiry_key'=>$key,'profile_sha256'=>$saved['estimate']['profile_sha256'],'identity_sha256'=>$identity['source_sha256'],
            'observed_at_unix'=>$now,'activation_sha256'=>$activation['activation_sha256'],'linked_max_channels_observed'=>0]));
        $proof=['version'=>2,'counter_id'=>98615635,'subject'=>$s,'inquiry_key'=>$key,'source_receipt_sha256'=>$source,
            'complete'=>true,'own_reserve_only'=>true,'producer_contract_sha256'=>Max2ValueBindingPolicyV2::PRODUCER_CONTRACT_SHA256,
            'observed_at_unix'=>$now,'blank_row_count'=>0,'cutoff_unix'=>$inquiry['recorded_at_unix'],'candidates'=>[],
            'status'=>'complete_empty','coverage'=>'admitted_origin_without_own_early_reserve','possible_max2_unresolved'=>false,
            'origin'=>['admitted'=>true,'subject'=>$s,'inquiry_key'=>$key,'source_sha256'=>$source]];
        $binding=Max2ValueBindingPolicyV2::resolve($s,$inquiry,$proof);
        if (($binding['status'] ?? null)==='HOLD') return $binding;
        return ['status'=>'READY','binding'=>$binding,'estimate'=>$saved['estimate'],'identity'=>$identity,'recheck'=>[]];
    }
}
