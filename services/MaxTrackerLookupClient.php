<?php
declare(strict_types=1);
require_once __DIR__.'/MaxTrackerLookupConfig.php';
require_once __DIR__.'/MaxTrackerLookupProtocol.php';

final class MaxTrackerLookupClient
{
    public function lookup(string $counterId,array $users): array
    {
        $body=MaxTrackerLookupProtocol::body($counterId,$users,time());
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
        return self::validateResponse($d,$counterId,$users);
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
}
