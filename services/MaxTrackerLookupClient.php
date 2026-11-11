<?php
declare(strict_types=1);
require_once __DIR__.'/MaxTrackerLookupConfig.php';
require_once __DIR__.'/MaxTrackerLookupProtocol.php';

final class MaxTrackerLookupClient
{
    public function lookup(string $counterId,array $users): array
    {
        return $this->request($counterId,$users,null)['identities'];
    }

    /** One optional finite observation piggybacks on the already required ordinary signed batch. */
    public function lookupWithCounterScopeEvidence(string $counterId,array $users,string $evidenceUser,?callable $beforeRequest=null):array
    {
        return $this->request($counterId,$users,null,null,null,$evidenceUser,$beforeRequest);
    }

    public function lookupWithMembership(string $counterId,array $users,int $notBefore,int $cutoff): array
    {
        return $this->request($counterId,$users,['not_before_unix'=>$notBefore,'cutoff_unix'=>$cutoff]);
    }

    /** Same authenticated SELECT-only endpoint; never an upload or identity guess. */
    public function currentMemberPage(string $counterId,string $after): array
    {
        return $this->request($counterId,[],null,$after);
    }

    /** History-only owner viewing; never the legacy member-valuation contract. */
    public function historyMemberPage(string $counterId,string $after):array
    {
        return $this->request($counterId,[],null,null,$after);
    }

    public static function validateHistoryMemberPage($data,string $counter,string $after,?int $now=null):array
    {
        $now=$now??time();$request=MaxTrackerLookupProtocol::historyCursor($after,$now,$counter);
        $keys=static function($value,array $expected):bool{
            if(!is_array($value))return false;$actual=array_keys($value);sort($actual);sort($expected);return$actual===$expected;
        };
        $less=static fn(string $a,string $b):bool=>strlen($a)!==strlen($b)?strlen($a)<strlen($b):strcmp($a,$b)<0;
        $p=$data['history_member_page']??null;
        if(!$keys($data,['ok','version','counter_id','tracker_timezone','history_member_page'])
            ||$data['ok']!==true||$data['version']!==1||$data['counter_id']!==$counter||$data['tracker_timezone']!=='Europe/London'
            ||!$keys($p,['basis','rows','cutoff_unix','history_fence_id','next_cursor','has_more','scanned_event_count','omitted_unknown_count','coverage'])
            ||$p['basis']!=='retained_membership_history_v1'||$p['coverage']!=='bounded_retained_history_not_live_platform_verification'
            ||!is_int($p['cutoff_unix'])||$p['cutoff_unix']<946684800||$p['cutoff_unix']>$now||$now-$p['cutoff_unix']>900
            ||!is_string($p['history_fence_id'])||($p['history_fence_id']!=='0'&&!MaxTrackerLookupProtocol::id($p['history_fence_id']))
            ||!is_array($p['rows'])||!array_is_list($p['rows'])||count($p['rows'])>5||!is_bool($p['has_more'])
            ||!is_int($p['scanned_event_count'])||$p['scanned_event_count']<0||$p['scanned_event_count']>64
            ||!is_int($p['omitted_unknown_count'])||$p['omitted_unknown_count']<0||$p['omitted_unknown_count']>$p['scanned_event_count']
            ||$p['scanned_event_count']<count($p['rows'])
            ||($p['next_cursor']!==null&&!is_string($p['next_cursor'])))throw new RuntimeException('tracker_history_page_invalid');
        if($request!==null&&($p['cutoff_unix']!==$request['cutoff_unix']||$p['history_fence_id']!==$request['history_fence_id']))
            throw new RuntimeException('tracker_history_page_invalid');
        $last=null;$seen=[];
        foreach($p['rows']as$r){
            if(!$keys($r,['external_user_id','source_id','state','last_event_record_id','last_event_recorded_at_unix',
                'episode_join_record_id','episode_join_recorded_at_unix','entry_basis']))throw new RuntimeException('tracker_history_page_invalid');
            foreach(['external_user_id','source_id','last_event_record_id','episode_join_record_id']as$k)
                if(!MaxTrackerLookupProtocol::id($r[$k]))throw new RuntimeException('tracker_history_page_invalid');
            $identity=$r['external_user_id'].':'.$r['source_id'];
            if(isset($seen[$identity])||$r['state']!=='observed_subscribed'
                ||!in_array($r['entry_basis'],['after_observed_leave','first_retained_join'],true)
                ||!is_int($r['last_event_recorded_at_unix'])||!is_int($r['episode_join_recorded_at_unix'])
                ||$r['episode_join_recorded_at_unix']<946684800||$r['episode_join_recorded_at_unix']>$r['last_event_recorded_at_unix']
                ||$r['last_event_recorded_at_unix']>$p['cutoff_unix']
                ||$less($p['history_fence_id'],$r['last_event_record_id'])||$less($p['history_fence_id'],$r['episode_join_record_id'])
                ||($last!==null&&!$less($r['last_event_record_id'],$last))
                ||($request!==null&&!$less($r['last_event_record_id'],$request['before_id'])))
                throw new RuntimeException('tracker_history_page_invalid');
            $seen[$identity]=true;$last=$r['last_event_record_id'];
        }
        if($p['history_fence_id']==='0'&&($p['rows']!==[]||$p['scanned_event_count']!==0||$p['has_more']||$p['next_cursor']!==null))
            throw new RuntimeException('tracker_history_page_invalid');
        if($p['has_more']){
            if($p['next_cursor']===null||$p['scanned_event_count']===0)throw new RuntimeException('tracker_history_page_invalid');
            $next=MaxTrackerLookupProtocol::historyCursor($p['next_cursor'],$now,$counter);
            if($next===null||$next['cutoff_unix']!==$p['cutoff_unix']||$next['history_fence_id']!==$p['history_fence_id']
                ||($request!==null&&($next['source_sha256']!==$request['source_sha256']||!$less($next['before_id'],$request['before_id'])))
                ||($last!==null&&$less($last,$next['before_id'])))throw new RuntimeException('tracker_history_page_invalid');
        }elseif($p['next_cursor']!==null)throw new RuntimeException('tracker_history_page_invalid');
        return$p;
    }

    private static function validateMemberPage($d,string $counter,string $after):array
    {
        $p=$d['member_page']??null;
        if(($d['ok']??null)!==true||($d['counter_id']??null)!==$counter||($d['version']??null)!==1
            ||($d['tracker_timezone']??null)!=='Europe/London'||!is_array($p)||!is_array($p['members']??null)
            ||!array_is_list($p['members'])||count($p['members'])>5||!is_bool($p['has_more']??null)
            ||!is_int($p['observed_at_unix']??null)||abs(time()-$p['observed_at_unix'])>90||!is_string($p['next_after']??null))
            throw new RuntimeException('tracker_member_page_invalid');
        $last=$after;
        foreach($p['members']as$r){
            foreach(['record_id','source_id','external_user_id']as$k)
                if(!MaxTrackerLookupProtocol::id($r[$k]??null))throw new RuntimeException('tracker_member_page_invalid');
            if(strlen($r['record_id'])<strlen($last)||(strlen($r['record_id'])===strlen($last)&&strcmp($r['record_id'],$last)<=0)
                ||($r['state']??null)!=='subscribed'||!is_int($r['subscribed_at_unix']??null)
                ||$r['subscribed_at_unix']<946684800||$r['subscribed_at_unix']>$p['observed_at_unix'])
                throw new RuntimeException('tracker_member_page_invalid');
            if(($r['retention_checked_at_unix']??null)!==null&&(!is_int($r['retention_checked_at_unix'])
                ||$r['retention_checked_at_unix']<$r['subscribed_at_unix']+1800
                ||$r['retention_checked_at_unix']>$p['observed_at_unix']||!MaxTrackerLookupProtocol::id($r['retention_record_id']??null)))
                throw new RuntimeException('tracker_member_page_invalid');
            if(($r['attribution']['status']??null)==='known'&&(!MaxTrackerLookupProtocol::id($r['attribution']['client_id']??null,40)
                ||($r['attribution']['basis']??null)!=='current_tracker_row_not_historical_visit'))
                throw new RuntimeException('tracker_member_page_invalid');
            $last=$r['record_id'];
        }
        if($p['next_after']!==$last)throw new RuntimeException('tracker_member_page_invalid');
        return $p;
    }

    private function request(string $counterId,array $users,?array $membership,?string $memberAfter=null,?string $historyAfter=null,?string $evidenceUser=null,?callable $beforeRequest=null): array
    {
        $body=MaxTrackerLookupProtocol::body($counterId,$users,time(),null,$membership,$memberAfter,$historyAfter,$evidenceUser);
        $signature=MaxTrackerLookupProtocol::signature($body,MaxTrackerLookupConfig::privateKey());
        // The caller durably binds this exact body hash before an optional observation may run.
        $evidenceIntent=$evidenceUser!==null&&$beforeRequest!==null&&$beforeRequest(hash('sha256',$body))===true;
        $headers=['Content-Type: application/json','Accept: application/json','X-Max-Attribution-Signature: '.$signature];
        if($evidenceIntent)$headers[]='X-Max-Attribution-Evidence: '.MaxTrackerLookupProtocol::COUNTER_SCOPE_HEADER;
        $response=''; $tooLarge=false;
        $ch=curl_init(MaxTrackerLookupConfig::endpoint());
        if ($ch===false)throw new RuntimeException('tracker_transport_failed');
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_RETURNTRANSFER=>false,
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk)use(&$response,&$tooLarge):int{
                if(strlen($response)+strlen($chunk)>MaxTrackerLookupProtocol::MAX_RESPONSE){$tooLarge=true;return 0;}
                $response.=$chunk;return strlen($chunk);
            }]);
        $ok=curl_exec($ch);$errno=curl_errno($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        if($ok===false||$errno!==0||$status!==200||$tooLarge)throw new RuntimeException('tracker_transport_failed');
        $d=json_decode($response,true,16,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);
        if($historyAfter!==null)return self::validateHistoryMemberPage($d,$counterId,$historyAfter);
        if($memberAfter!==null)return self::validateMemberPage($d,$counterId,$memberAfter);
        $out=['identities'=>self::validateResponse($d,$counterId,$users),
            'membership'=>self::validateMembership($d,$users,$membership!==null)];
        if($evidenceUser!==null)$out['counter_scope_evidence']=$evidenceIntent
            ?self::validateCounterScopeEvidence($d['counter_scope_evidence']??null,hash('sha256',$body))
            :['available'=>false,'reason'=>'intent_not_persisted','request_sha256'=>hash('sha256',$body)];
        return $out;
    }

    /** Optional unsupported or malformed evidence never poisons the ordinary identity response. */
    public static function validateCounterScopeEvidence(mixed $saved,string $requestHash,?int $now=null):array
    {
        $unknown=['available'=>false,'reason'=>$saved===null?'unsupported':'invalid_response','request_sha256'=>$requestHash];
        $now=$now??time();
        if(!is_array($saved)||preg_match('/\A[a-f0-9]{64}\z/D',$requestHash)!==1||($saved['v']??null)!==1
            ||($saved['counter_id']??null)!=='98615635'||($saved['request_sha256']??null)!==$requestHash
            ||!is_int($saved['checked_at_unix']??null)||$saved['checked_at_unix']<946684800||$saved['checked_at_unix']>$now
            ||$now-$saved['checked_at_unix']>90||!in_array($saved['status']??null,['observed','unavailable'],true))return $unknown;
        $keys=['v','counter_id','request_sha256','checked_at_unix','status','complete_within_bound',
            'scoped_rows','empty_client_id_rows','noncanonical_client_id_rows','canonical_client_id_rows'];
        if($saved['status']==='unavailable')$keys[]='reason';
        $actual=array_keys($saved);sort($actual);sort($keys);if($actual!==$keys)return $unknown;
        $counts=[];foreach(['scoped_rows','empty_client_id_rows','noncanonical_client_id_rows','canonical_client_id_rows']as$key){
            $n=$saved[$key];if($saved['status']==='unavailable'){if($n!==null)return $unknown;}
            elseif(!is_int($n)||$n<0||$n>64)return $unknown;$counts[$key]=$n;
        }
        if($saved['status']==='unavailable'){
            if($saved['complete_within_bound']!==false||!in_array($saved['reason'],
                ['invalid_request','storage_unavailable','point_index_unavailable','row_limit'],true))return $unknown;
            return ['available'=>false,'reason'=>$saved['reason'],'request_sha256'=>$requestHash,
                'checked_at_unix'=>$saved['checked_at_unix'],'complete_within_bound'=>false];
        }
        if($saved['complete_within_bound']!==true||$counts['empty_client_id_rows']+$counts['noncanonical_client_id_rows']
            +$counts['canonical_client_id_rows']!==$counts['scoped_rows'])return $unknown;
        return array_merge(['available'=>true,'basis'=>'exact_effective_counter_scope_only','request_sha256'=>$requestHash,
            'checked_at_unix'=>$saved['checked_at_unix'],'complete_within_bound'=>true,
            'local_scope_zero_is_not_global_tracker_absence'=>true,'financial_permission'=>false],$counts);
    }

    public static function validateResponse($d,string $counterId,array $users): array
    {
        if(!is_array($d)||($d['ok']??null)!==true||($d['version']??null)!==1||($d['counter_id']??null)!==$counterId
            ||($d['tracker_timezone']??null)!=='Europe/London'||!is_array($d['users']??null)||count($d['users'])!==count($users))throw new RuntimeException('tracker_response_invalid');
        $wanted=array_fill_keys($users,true);$out=[];
        foreach($d['users'] as $row){
            if(!is_array($row))throw new RuntimeException('tracker_response_invalid');
            $id=$row['external_user_id']??null;
            if(!MaxTrackerLookupProtocol::id($id)||!isset($wanted[$id])||array_key_exists($id,$out)||!is_bool($row['found']??null))throw new RuntimeException('tracker_response_invalid');
            if($row['found']===true&&(!MaxTrackerLookupProtocol::id($row['client_id']??null,40)||!MaxTrackerLookupProtocol::id($row['record_id']??null)))throw new RuntimeException('tracker_response_invalid');
            if($row['found']===true&&($row['observed_at_unix']??null)!==null&&!is_int($row['observed_at_unix']))throw new RuntimeException('tracker_response_invalid');
            $out[$id]=$row['found']?['external_user_id'=>$id,'client_id'=>$row['client_id'],'record_id'=>$row['record_id'],'observed_at_unix'=>$row['observed_at_unix']??null]:null;
        }
        return $out;
    }

    private static function validateMembership($d,array $users,bool $required): array
    {
        if(!$required)return [];
        $wanted=array_fill_keys($users,true);$out=[];$total=0;
        foreach($d['users']as$row){
            $id=$row['external_user_id']??null;$receipts=$row['membership_receipts']??null;
            if(!is_string($id)||!isset($wanted[$id])||!is_array($receipts)||!array_is_list($receipts)||count($receipts)>20)
                throw new RuntimeException('tracker_response_invalid');
            $clean=[];
            foreach($receipts as$receipt){
                if(!is_array($receipt))throw new RuntimeException('tracker_response_invalid');
                $kind=$receipt['kind']??null;$state=$receipt['state']??null;$source=$receipt['source_record_id']??null;
                $valid=MaxTrackerLookupProtocol::id($source)&&is_int($receipt['occurred_at_unix']??null)
                    &&($receipt['occurred_at_unix']??0)>=946684800;
                if($kind==='subscription')$valid=$valid&&$state==='subscribed'&&array_keys($receipt)===['kind','source_record_id','state','occurred_at_unix'];
                elseif($kind==='retention_30m')$valid=$valid&&in_array($state,['retained','not_retained'],true)
                    &&MaxTrackerLookupProtocol::id($receipt['subscription_source_record_id']??null)
                    &&($receipt['minimum_age_seconds']??null)===1800
                    &&array_keys($receipt)===['kind','source_record_id','subscription_source_record_id','state','occurred_at_unix','minimum_age_seconds'];
                else$valid=false;
                if(!$valid)throw new RuntimeException('tracker_response_invalid');
                $clean[]=$receipt;$total++;
            }
            $out[$id]=$clean;
        }
        if($total>100)throw new RuntimeException('tracker_response_invalid');
        return $out;
    }
}

