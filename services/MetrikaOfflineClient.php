<?php
declare(strict_types=1);
require_once __DIR__.'/MaxTrackerLookupProtocol.php';
require_once __DIR__.'/MaxChannelConversionOutbox.php';

/** Existing counter/token, new ClientId CSV transport. No raw API logging. */
final class MetrikaOfflineClient
{
    private string $counter;
    private string $token;
    private $transport;
    public function __construct(string $counter,string $token,?callable $transport=null)
    {
        if(!MaxTrackerLookupProtocol::id($counter)||$token==='')throw new RuntimeException('metrika_config_missing');
        $this->counter=$counter;$this->token=$token;$this->transport=$transport;
    }

    public static function comment(array $event): string
    {
        return 'MaxChannel'.hash('sha256',implode('|',[(string)$event['project_key'],(string)$event['conversation_id'],(string)$event['target'],(string)$event['event_unix']]));
    }

    public static function csv(array $event,int $now): string
    {
        if(!MaxTrackerLookupProtocol::id($event['client_id']??null,40)
            ||!in_array($event['target']??'',MaxChannelConversionOutbox::TARGETS,true)
            ||!is_numeric($event['event_unix']??null)||(int)$event['event_unix']<=0||(int)$event['event_unix']>$now
            ||(int)$event['event_unix']<$now-21*86400)throw new InvalidArgumentException('invalid_conversion_event');
        $f=fopen('php://temp','w+');fputcsv($f,['ClientId','Target','DateTime']);
        fputcsv($f,[$event['client_id'],$event['target'],(string)$event['event_unix']]);rewind($f);$csv=stream_get_contents($f);fclose($f);return $csv;
    }

    public function upload(array $event,int $now): array
    {
        if(($event['counter_id']??null)!==$this->counter)throw new InvalidArgumentException('wrong_conversion_counter');
        $csv=self::csv($event,$now);$result=$this->request('POST','offline_conversions/upload?type=BASIC&comment='.rawurlencode(self::comment($event)),$csv);
        $error=(int)($result['error']??0);$http=(int)($result['http']??0);
        if($error!==0)return ['outcome'=>in_array($error,[5,6,7],true)?'retry':'unknown','reason'=>in_array($error,[5,6,7],true)?'transport_not_sent':'upload_outcome_unknown'];
        if(in_array($http,[420,429],true))return ['outcome'=>'retry','reason'=>'rate_limited'];
        if($http>=400&&$http<500)return ['outcome'=>'rejected','reason'=>'api_rejected'];
        $d=$this->decode($result);$u=$d['uploading']??null;
        if($http>=200&&$http<300&&is_array($u)&&MaxTrackerLookupProtocol::id((string)($u['id']??''))
            &&($u['client_id_type']??'')==='CLIENT_ID')return ['outcome'=>'accepted','upload_id'=>(string)$u['id'],'status'=>(string)($u['status']??'UPLOADED')];
        return ['outcome'=>'unknown','reason'=>'upload_outcome_unknown'];
    }

    public function poll(string $uploadId): ?array
    {
        if(!MaxTrackerLookupProtocol::id($uploadId))return null;
        $r=$this->request('GET','offline_conversions/uploading/'.$uploadId);
        if((int)($r['error']??0)!==0||(int)($r['http']??0)!==200)return null;
        $d=$this->decode($r);$u=$d['uploading']??null;
        return is_array($u)&&(string)($u['id']??'')===$uploadId&&($u['client_id_type']??'')==='CLIENT_ID'?$u:null;
    }

    /** Read-only recovery of a lost POST acknowledgement; no automatic resend. */
    public function findUnknown(array $event): ?array
    {
        $comment=self::comment($event);$matches=[];
        for($page=0;$page<3;$page++){
            $r=$this->request('GET','offline_conversions/uploadings?limit=100&offset='.($page*100));
            if((int)($r['error']??0)!==0||(int)($r['http']??0)!==200)return null;
            $d=$this->decode($r);$list=$d['uploadings']??null;if(!is_array($list))return null;
            foreach($list as $u)if(is_array($u)&&($u['comment']??'')===$comment&&($u['client_id_type']??'')==='CLIENT_ID'
                &&MaxTrackerLookupProtocol::id((string)($u['id']??'')))$matches[(string)$u['id']]=$u;
            if(count($list)<100)break;
        }
        return count($matches)===1?array_values($matches)[0]:null;
    }

    public function goalsReady(): bool
    {
        $r=$this->request('GET','goals');if((int)($r['http']??0)!==200||(int)($r['error']??0)!==0)return false;
        $d=$this->decode($r);$found=[];
        foreach((array)($d['goals']??[]) as $goal){
            if(($goal['type']??'')!=='action')continue;
            foreach((array)($goal['conditions']??[]) as $condition)if(($condition['type']??'')==='exact')$found[(string)($condition['url']??'')]=true;
        }
        foreach(MaxChannelConversionOutbox::TARGETS as $target)if(!isset($found[$target]))return false;
        return true;
    }

    private function decode(array $response): array
    {
        $data=json_decode((string)($response['body']??''),true,32,JSON_BIGINT_AS_STRING);return is_array($data)?$data:[];
    }

    private function request(string $method,string $path,?string $csv=null): array
    {
        $url='https://api-metrika.yandex.net/management/v1/counter/'.$this->counter.'/'.$path;
        if($this->transport)return ($this->transport)($method,$url,$csv);
        $temp=null;$ch=null;
        try{
            $body='';$tooLarge=false;$ch=curl_init($url);if($ch===false)throw new RuntimeException('metrika_transport_failed');
            $options=[CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_HTTPHEADER=>['Authorization: OAuth '.$this->token,'Accept: application/json'],
                CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk)use(&$body,&$tooLarge):int{
                    if(strlen($body)+strlen($chunk)>1048576){$tooLarge=true;return 0;}$body.=$chunk;return strlen($chunk);
                }];
            if($method==='POST'){
                $temp=tempnam(sys_get_temp_dir(),'max-channel-csv-');if($temp===false)throw new RuntimeException('private_csv_unavailable');
                chmod($temp,0600);if(file_put_contents($temp,$csv)!==strlen((string)$csv))throw new RuntimeException('private_csv_unavailable');
                $options[CURLOPT_POST]=true;$options[CURLOPT_POSTFIELDS]=['file'=>new CURLFile($temp,'text/csv','offline_conversions.csv')];
            }
            curl_setopt_array($ch,$options);$ok=curl_exec($ch);
            return ['http'=>(int)curl_getinfo($ch,CURLINFO_HTTP_CODE),'error'=>$tooLarge?23:curl_errno($ch),'body'=>$body];
        }finally{if($ch!==null&&$ch!==false)curl_close($ch);if(is_string($temp)&&is_file($temp))unlink($temp);}
    }
}
