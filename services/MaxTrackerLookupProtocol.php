<?php
declare(strict_types=1);

/** Purpose-bound signed requests for a bounded, read-only MAX lookup. */
final class MaxTrackerLookupProtocol
{
    public const PURPOSE = 'anytour-max-tracker-lookup-v1';
    public const MAX_USERS = 25;
    public const MAX_BODY = 8192;
    public const MAX_RESPONSE = 32768;
    public const COUNTER_SCOPE_HEADER = 'counter-scope-v1';

    public static function signature(string $body, string $privateKey): string
    {
        $key=openssl_pkey_get_private($privateKey);
        $details=$key?openssl_pkey_get_details($key):false;
        if(!$details||$details['type']!==OPENSSL_KEYTYPE_RSA||$details['bits']<2048
            ||!openssl_sign(self::PURPOSE."\n".$body,$signature,$key,OPENSSL_ALGO_SHA256))throw new InvalidArgumentException('lookup_auth_missing');
        return base64_encode($signature);
    }

    public static function body(string $counterId, array $userIds, int $now, ?string $nonce = null, ?array $membership = null, ?string $memberAfter = null, ?string $historyAfter = null, ?string $evidenceUser = null): string
    {
        $data = ['version'=>1,'counter_id'=>$counterId,'user_ids'=>array_values($userIds),
            'issued_at'=>$now,'nonce'=>$nonce ?? bin2hex(random_bytes(16))];
        if ($membership !== null) $data['membership']=$membership;
        if ($memberAfter !== null) $data['member_page_after']=$memberAfter;
        if ($historyAfter !== null) $data['history_member_page_after']=$historyAfter;
        if($evidenceUser!==null){
            if($counterId!=='98615635'||!self::id($evidenceUser)||!in_array($evidenceUser,$userIds,true)||$membership!==null||$memberAfter!==null||$historyAfter!==null)
                throw new InvalidArgumentException('lookup_invalid_counter_scope_request');
            $data['counter_scope_evidence']=['v'=>1,'user_id'=>$evidenceUser];
        }
        self::validate($data, $now, $counterId);
        return json_encode($data, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    }

    public static function authenticate(string $body, string $signature, string $publicKey, int $now, string $counterId): array
    {
        $raw=base64_decode($signature,true);
        $key=openssl_pkey_get_public($publicKey);$details=$key?openssl_pkey_get_details($key):false;
        if($body===''||strlen($body)>self::MAX_BODY||!is_string($raw)||strlen($raw)>1024
            ||!$details||$details['type']!==OPENSSL_KEYTYPE_RSA||$details['bits']<2048
            ||openssl_verify(self::PURPOSE."\n".$body,$raw,$key,OPENSSL_ALGO_SHA256)!==1)throw new InvalidArgumentException('lookup_unauthorized');
        $data=json_decode($body,true,16,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);
        if (!is_array($data)) throw new InvalidArgumentException('lookup_invalid_body');
        self::validate($data,$now,$counterId);
        return $data;
    }

    /** Optional evidence never extends the authenticated ordinary batch or its fixed counter. */
    public static function counterScopeRequest(array $data,string $header):?string
    {
        if($header!==self::COUNTER_SCOPE_HEADER)return null;
        $option=$data['counter_scope_evidence']??null;
        if(!is_array($option)||count($option)!==2||($option['v']??null)!==1||!self::id($option['user_id']??null)
            ||($data['counter_id']??null)!=='98615635'||!is_array($data['user_ids']??null)
            ||!in_array($option['user_id'],$data['user_ids'],true)||array_key_exists('membership',$data)
            ||array_key_exists('member_page_after',$data)||array_key_exists('history_member_page_after',$data))return null;
        return $option['user_id'];
    }


    public const RETAINED_CLICK_HEADER='retained-click-candidate-v1';
    public static function retainedClickContext(array $data,string $header,int $now):?array
    {
        if($header!==self::RETAINED_CLICK_HEADER)return null;
        $c=$data['retained_click_candidate']??null;
        $keys=['v','user_id','event_ref','frame_sha256','event_at_unix'];
        if(!is_array($c)||count($c)!==count($keys)||array_diff(array_keys($c),$keys)||($c['v']??null)!==1
            ||($data['counter_id']??null)!=='98615635'||($data['user_ids']??null)!==[$c['user_id']??null]
            ||!self::id($c['user_id']??null)||!is_int($c['event_at_unix']??null)||$c['event_at_unix']<946684800||$c['event_at_unix']>$now
            ||array_key_exists('membership',$data)||array_key_exists('member_page_after',$data)||array_key_exists('history_member_page_after',$data)
            ||array_key_exists('counter_scope_evidence',$data))throw new InvalidArgumentException('lookup_invalid_retained_click_request');
        foreach(['event_ref','frame_sha256']as$k)if(!is_string($c[$k]??null)||preg_match('/\A[a-f0-9]{64}\z/D',$c[$k])!==1)
            throw new InvalidArgumentException('lookup_invalid_retained_click_request');
        return $c;
    }
    /** A recorded tracker click is not proof of its original save/enrichment or a Metrika visit. */
    public static function retainedClickResult(mixed $wire,string $requestHash,int $received,int $event):?array
    {
        if(!is_array($wire)||count($wire)!==3||array_diff(array_keys($wire),['v','request_sha256','result'])
            ||($wire['v']??null)!==1||($wire['request_sha256']??null)!==$requestHash)return null;
        $r=$wire['result']??null;
        if(!is_array($r)||!array_is_list($r)||count($r)!==9||!is_int($r[0])||$r[0]<0||$r[0]>10
            ||!is_int($r[1])||$r[1]<946684800||abs($r[1]-$received)>90)return null;
        foreach([2,3]as$i)if($r[$i]!==null&&(!is_int($r[$i])||$r[$i]<0||$r[$i]>64))return null;
        if($r[0]<=2&&($r[2]===null||$r[3]===null||$r[3]>$r[2]))return null;
        if($r[0]!==1)return $r[4]===0&&array_slice($r,5)===[null,null,null,null]?$r:null;
        if(!in_array($r[4],[1,2],true)||!self::id($r[6])||!is_int($r[8])||$r[8]<946684800||$r[8]>$event||$r[8]>$r[1])return null;
        foreach([5,7]as$i)if(!is_string($r[$i])||preg_match('/\A[a-f0-9]{64}\z/D',$r[$i])!==1)return null;
        return $r;
    }

    public const RETAINED_REHYDRATE_HEADER='retained-click-rehydrate-v1';

    /** Exact saved selection only; never another preferred-source selection or delivery permission. */
    public static function retainedClickRehydrateContext(array $data,string $header,int $now):?array
    {
        $present=array_key_exists('retained_click_rehydrate',$data);
        if($header!==self::RETAINED_REHYDRATE_HEADER){
            if($present)throw new InvalidArgumentException('lookup_invalid_retained_rehydrate_request');
            return null;
        }
        $c=$data['retained_click_rehydrate']??null;
        $outer=['version','counter_id','user_ids','issued_at','nonce','retained_click_rehydrate'];
        $keys=['v','selection_context','selection_request_sha256','selected_record_id','selected_kind',
            'selected_candidate_sha256','selected_record_sha256','selected_recorded_at_unix'];
        if(count($data)!==count($outer)||array_diff(array_keys($data),$outer)||!is_array($c)||count($c)!==count($keys)
            ||array_diff(array_keys($c),$keys)||($c['v']??null)!==1||($c['selected_kind']??null)!==2
            ||!is_array($c['selection_context']??null)||array_keys($c['selection_context'])!==['v','user_id','event_ref','frame_sha256','event_at_unix']
            ||!self::id($c['selected_record_id']??null)||!is_int($c['selected_recorded_at_unix']??null))
            throw new InvalidArgumentException('lookup_invalid_retained_rehydrate_request');
        $ctx=self::retainedClickContext(['counter_id'=>$data['counter_id']??null,'user_ids'=>$data['user_ids']??null,
            'retained_click_candidate'=>$c['selection_context']],self::RETAINED_CLICK_HEADER,$now);
        if($c['selected_recorded_at_unix']<946684800||$c['selected_recorded_at_unix']>$ctx['event_at_unix'])
            throw new InvalidArgumentException('lookup_invalid_retained_rehydrate_request');
        foreach(['selection_request_sha256','selected_candidate_sha256','selected_record_sha256']as$k)
            if(!is_string($c[$k]??null)||preg_match('/\A[a-f0-9]{64}\z/D',$c[$k])!==1)
                throw new InvalidArgumentException('lookup_invalid_retained_rehydrate_request');
        return $c;
    }

    public static function retainedClickRehydrateBody(array $selection,int $now,?string $nonce=null):string
    {
        $data=['version'=>1,'counter_id'=>'98615635','user_ids'=>[$selection['selection_context']['user_id']??null],
            'issued_at'=>$now,'nonce'=>$nonce??bin2hex(random_bytes(16)),'retained_click_rehydrate'=>$selection];
        self::validate($data,$now,'98615635');
        return json_encode($data,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }

    /** Recompute both OLD hashes over the same normalized seven-field source tuple and London clock. */
    public static function retainedClickRehydrateMatches(array $selection,mixed $tuple,int $now):bool
    {
        try{
            self::retainedClickRehydrateBody($selection,$now,str_repeat('0',32));
            if(!is_array($tuple)||!array_is_list($tuple)||count($tuple)!==7
                ||($tuple[0]??null)!==$selection['selected_record_id']||($tuple[1]??null)!==$selection['selection_context']['user_id']
                ||(!self::id($tuple[2]??null,40)&&($tuple[2]??null)!=='')
                ||!self::id($tuple[3]??null,40)||!self::id($tuple[5]??null)||($tuple[6]??null)!==1)return false;
            require_once __DIR__.'/MaxSubscriptionAttributionPolicy.php';
            $at=MaxSubscriptionAttributionPolicy::eventTimeUnix($tuple[4],new DateTimeZone('Europe/London'));
            if($at!==$selection['selected_recorded_at_unix']||$at>$now)return false;
            $fingerprint=hash('sha256',json_encode($tuple,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
            $candidate=hash('sha256',json_encode([self::RETAINED_CLICK_HEADER,$selection['selection_request_sha256'],
                $selection['selection_context'],2,$tuple[3],$tuple[0],$fingerprint,$at],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
            return hash_equals($selection['selected_record_sha256'],$fingerprint)&&hash_equals($selection['selected_candidate_sha256'],$candidate);
        }catch(Throwable $ignored){return false;}
    }

    /** The raw tuple is private server-to-app material; callers must never project or log it. */
    public static function retainedClickRehydrateResult(mixed $wire,array $selection,string $requestHash,int $received):?array
    {
        if(!is_array($wire)||count($wire)!==4||array_diff(array_keys($wire),['v','request_sha256','result','financial_permission'])
            ||($wire['v']??null)!==1||preg_match('/\A[a-f0-9]{64}\z/D',$requestHash)!==1
            ||($wire['request_sha256']??null)!==$requestHash||($wire['financial_permission']??null)!==false)return null;
        $r=$wire['result']??null;
        if(!is_array($r)||!array_is_list($r)||count($r)!==3||!is_int($r[0])||$r[0]<0||$r[0]>7
            ||!is_int($r[1])||$r[1]<946684800||abs($r[1]-$received)>90)return null;
        if($r[0]!==1)return $r[2]===null?$r:null;
        return $r[1]>=($selection['selected_recorded_at_unix']??PHP_INT_MAX)&&self::retainedClickRehydrateMatches($selection,$r[2],$received)?$r:null;
    }

    private static function validate(array $data, int $now, string $counterId): void
    {
        if (($data['version']??null)!==1 || ($data['counter_id']??null)!==$counterId
            || !self::id($counterId) || !is_int($data['issued_at']??null)
            || abs($now-$data['issued_at'])>90 || !is_string($data['nonce']??null)
            || !preg_match('/\A[a-f0-9]{32}\z/D',$data['nonce']) || !is_array($data['user_ids']??null)
            || count($data['user_ids'])>self::MAX_USERS || !array_is_list($data['user_ids'])) {
            throw new InvalidArgumentException('lookup_invalid_body');
        }
        $seen=[];
        foreach ($data['user_ids'] as $id) {
            if (!self::id($id) || isset($seen[$id])) throw new InvalidArgumentException('lookup_invalid_users');
            $seen[$id]=true;
        }
        if(array_key_exists('retained_click_rehydrate',$data))
            self::retainedClickRehydrateContext($data,self::RETAINED_REHYDRATE_HEADER,$now);
        if (array_key_exists('member_page_after',$data) && (!is_string($data['member_page_after'])
            || preg_match('/\A(?:0|[1-9][0-9]{0,19})\z/D',$data['member_page_after'])!==1
            || $data['user_ids']!==[] || array_key_exists('membership',$data)))
            throw new InvalidArgumentException('lookup_invalid_member_page');
        if (array_key_exists('history_member_page_after',$data)) {
            if (!is_string($data['history_member_page_after']) || $data['user_ids']!==[]
                || array_key_exists('member_page_after',$data) || array_key_exists('membership',$data))
                throw new InvalidArgumentException('lookup_invalid_history_cursor');
            self::historyCursor($data['history_member_page_after'],$now,$counterId);
        }
        if (array_key_exists('membership',$data)) {
            $membership=$data['membership'];
            if (!is_array($membership) || array_keys($membership)!==['not_before_unix','cutoff_unix']
                || !is_int($membership['not_before_unix']) || !is_int($membership['cutoff_unix'])
                || $membership['not_before_unix']<946684800
                || $membership['not_before_unix']>$membership['cutoff_unix']
                || abs($now-$membership['cutoff_unix'])>90) {
                throw new InvalidArgumentException('lookup_invalid_membership_window');
            }
        }
    }


    /** A fixed retained-history prefix; it is not an HL9 record cursor. */
    public static function historyCursor(string $cursor,int $now,string $counterId):?array
    {
        if (!self::id($counterId) || $now<946684800) throw new InvalidArgumentException('lookup_invalid_history_cursor');
        if ($cursor==='0') return null;
        if (strlen($cursor)>512 || !preg_match('/\Amh1\.([A-Za-z0-9_-]+)\z/D',$cursor,$match))
            throw new InvalidArgumentException('lookup_invalid_history_cursor');
        $encoded=$match[1];
        if (strlen($encoded)%4===1) throw new InvalidArgumentException('lookup_invalid_history_cursor');
        $json=base64_decode(strtr($encoded,'-_','+/').str_repeat('=',(4-strlen($encoded)%4)%4),true);
        if (!is_string($json) || rtrim(strtr(base64_encode($json),'+/','-_'),'=')!==$encoded)
            throw new InvalidArgumentException('lookup_invalid_history_cursor');
        try { $v=json_decode($json,true,4,JSON_THROW_ON_ERROR); }
        catch (Throwable $ignored) { throw new InvalidArgumentException('lookup_invalid_history_cursor'); }
        if (!is_array($v) || !array_is_list($v) || count($v)!==6 || $v[0]!==1 || $v[1]!==$counterId
            || !is_int($v[2]) || $v[2]<946684800 || $v[2]>$now || $now-$v[2]>900
            || !self::id($v[3]) || !self::id($v[4])
            || strlen($v[4])>strlen($v[3]) || (strlen($v[4])===strlen($v[3]) && strcmp($v[4],$v[3])>0)
            || !is_string($v[5]) || preg_match('/\A[a-f0-9]{64}\z/D',$v[5])!==1
            || json_encode($v,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)!==$json)
            throw new InvalidArgumentException('lookup_invalid_history_cursor');
        return ['counter_id'=>$v[1],'cutoff_unix'=>$v[2],'history_fence_id'=>$v[3],
            'before_id'=>$v[4],'source_sha256'=>$v[5]];
    }

    public static function encodeHistoryCursor(string $counterId,int $cutoffUnix,string $fenceId,string $beforeId,string $sourceSha256):string
    {
        $json=json_encode([1,$counterId,$cutoffUnix,$fenceId,$beforeId,$sourceSha256],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $cursor='mh1.'.rtrim(strtr(base64_encode($json),'+/','-_'),'=');
        self::historyCursor($cursor,$cutoffUnix,$counterId);
        return $cursor;
    }

    public static function id($value, int $digits=20): bool
    {
        return is_string($value) && strlen($value)<=$digits && preg_match('/\A[1-9][0-9]*\z/D',$value)===1;
    }
}

