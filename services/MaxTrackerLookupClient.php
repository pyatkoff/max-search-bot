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

    public function lookupWithMembership(string $counterId,array $users,int $notBefore,int $cutoff): array
    {
        return $this->request($counterId,$users,['not_before_unix'=>$notBefore,'cutoff_unix'=>$cutoff]);
    }

    /** Same authenticated SELECT-only endpoint; never an upload or identity guess. */
    public function currentMemberPage(string $counterId,string $after): array
    {
        return $this->request($counterId,[],null,$after);
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

    private function request(string $counterId,array $users,?array $membership,?string $memberAfter=null): array
    {
        $body=MaxTrackerLookupProtocol::body($counterId,$users,time(),null,$membership,$memberAfter);
        $signature=MaxTrackerLookupProtocol::signature($body,MaxTrackerLookupConfig::privateKey());
        $response=''; $tooLarge=false;
        $ch=curl_init(MaxTrackerLookupConfig::endpoint());
        if ($ch===false)throw new RuntimeException('tracker_transport_failed');
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_RETURNTRANSFER=>false,
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json','X-Max-Attribution-Signature: '.$signature],
            CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk)use(&$response,&$tooLarge):int{
                if(strlen($response)+strlen($chunk)>MaxTrackerLookupProtocol::MAX_RESPONSE){$tooLarge=true;return 0;}
                $response.=$chunk;return strlen($chunk);
            }]);
        $ok=curl_exec($ch);$errno=curl_errno($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        if($ok===false||$errno!==0||$status!==200||$tooLarge)throw new RuntimeException('tracker_transport_failed');
        $d=json_decode($response,true,16,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);
        if($memberAfter!==null)return self::validateMemberPage($d,$counterId,$memberAfter);
        return ['identities'=>self::validateResponse($d,$counterId,$users),
            'membership'=>self::validateMembership($d,$users,$membership!==null)];
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

