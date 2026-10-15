<?php
declare(strict_types=1);
require_once __DIR__.'/MaxTrackerLookupProtocol.php';
require_once __DIR__.'/MaxTrackerLookupConfig.php';
require_once __DIR__.'/MaxChannelConversionOutbox.php';

/** Existing counter/token, new ClientId CSV transport. No raw API logging. */
final class MetrikaOfflineClient
{
    // Separate exact JS identifiers for channel ClientID uploads. Existing
    // canonical stage keys and legacy YCLID goal identifiers stay unchanged.
    public const CHANNEL_GOALS=[
        'max_bot_activity_start'=>['target'=>'max_channel_activity_start','name'=>'MAX канал: начало общения'],
        'max_manager_reply'=>['target'=>'max_channel_manager_reply','name'=>'MAX канал: ответ менеджера'],
        'max_customer_reply_after_manager'=>['target'=>'max_channel_customer_reply_after_manager','name'=>'MAX канал: клиент ответил менеджеру'],
    ];
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
        fputcsv($f,[$event['client_id'],self::CHANNEL_GOALS[$event['target']]['target'],(string)$event['event_unix']]);rewind($f);$csv=stream_get_contents($f);fclose($f);return $csv;
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
        try{
            $goals=$this->readGoals();
            foreach(self::CHANNEL_GOALS as $definition)if(count($this->exactGoals($goals,$definition['target']))!==1)return false;
            return true;
        }catch(Throwable $ignored){return false;}
    }

    /** Explicit operator provisioning only. Never edits or deletes any goal. */
    public function provisionChannelGoals(bool $apply=false): array
    {
        if($this->counter!=='98615635')throw new RuntimeException('wrong_goal_counter');
        $before=$this->readGoals();$receipts=[];$missing=[];
        foreach(self::CHANNEL_GOALS as $definition){
            $matches=$this->exactGoals($before,$definition['target']);
            if(count($matches)>1)throw new RuntimeException('ambiguous_channel_goal');
            if(count($matches)===1){$receipts[$definition['target']]=['id'=>(string)$matches[0]['id'],'created'=>false];continue;}
            foreach($before as $existing){
                if(($existing['name']??'')===$definition['name'])throw new RuntimeException('conflicting_channel_goal');
                foreach((array)($existing['conditions']??[]) as $condition)if(($condition['url']??'')===$definition['target'])throw new RuntimeException('conflicting_channel_goal');
            }
            $receipts[$definition['target']]=['id'=>null,'created'=>false];$missing[]=$definition;
        }
        foreach($apply?$missing:[] as $definition){
            $r=$this->request('POST','goals',null,['goal'=>['name'=>$definition['name'],'type'=>'action','conditions'=>[['type'=>'exact','url'=>$definition['target']]]]]);
            if((int)($r['error']??0)!==0||(int)($r['http']??0)!==200)throw new RuntimeException('goal_creation_requires_readback');
            $data=$this->decode($r);$matches=$this->exactGoals(isset($data['goal'])?[$data['goal']]:[],$definition['target']);
            if(count($matches)!==1)throw new RuntimeException('goal_creation_requires_readback');
            $receipts[$definition['target']]=['id'=>(string)$matches[0]['id'],'created'=>true];
        }
        if($apply){
            // Real API readback also proves that every prior goal still has its
            // original name/type/conditions. A concurrent change stops rollout.
            $after=$this->readGoals();$byId=[];foreach($after as $g)$byId[(string)($g['id']??'')]=$g;
            foreach($before as $g){$id=(string)($g['id']??'');if(!isset($byId[$id])||$this->goalSemantics($g)!==$this->goalSemantics($byId[$id]))throw new RuntimeException('existing_goal_changed');}
            foreach(self::CHANNEL_GOALS as $definition)if(count($this->exactGoals($after,$definition['target']))!==1)throw new RuntimeException('goal_creation_requires_readback');
        }
        return ['goals'=>$receipts,'existing_goals_checked'=>count($before),'existing_goals_unchanged'=>$apply,'conversion_requests'=>0];
    }

    private function readGoals(): array
    {
        $r=$this->request('GET','goals');$d=$this->decode($r);
        if((int)($r['error']??0)!==0||(int)($r['http']??0)!==200||!is_array($d['goals']??null))throw new RuntimeException('goals_read_failed');
        return $d['goals'];
    }

    private function exactGoals(array $goals,string $target): array
    {
        $matches=[];
        foreach($goals as $goal){
            if(!is_array($goal)||($goal['type']??'')!=='action'||!MaxTrackerLookupProtocol::id((string)($goal['id']??'')))continue;
            $conditions=$goal['conditions']??null;
            // One exact condition excludes additional matches in the same goal.
            if(is_array($conditions)&&count($conditions)===1&&($conditions[0]['type']??'')==='exact'&&($conditions[0]['url']??'')===$target)$matches[]=$goal;
        }
        return $matches;
    }

    private function goalSemantics(array $goal): string
    {
        $normalize=static function($value)use(&$normalize){if(is_array($value)){if(!array_is_list($value))ksort($value);foreach($value as &$v)$v=$normalize($v);unset($v);}return $value;};
        return json_encode($normalize(['name'=>$goal['name']??null,'type'=>$goal['type']??null,'conditions'=>$goal['conditions']??null]),JSON_THROW_ON_ERROR);
    }

    private function decode(array $response): array
    {
        $data=json_decode((string)($response['body']??''),true,32,JSON_BIGINT_AS_STRING);return is_array($data)?$data:[];
    }

    private function request(string $method,string $path,?string $csv=null,?array $json=null): array
    {
        $url='https://api-metrika.yandex.net/management/v1/counter/'.$this->counter.'/'.$path;
        if($this->transport)return ($this->transport)($method,$url,$json!==null?json_encode($json,JSON_THROW_ON_ERROR):$csv);
        $temp=null;$ch=null;
        try{
            $body='';$tooLarge=false;$ch=curl_init($url);if($ch===false)throw new RuntimeException('metrika_transport_failed');
            $options=[CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_HTTPHEADER=>['Authorization: OAuth '.$this->token,'Accept: application/json'],
                CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk)use(&$body,&$tooLarge):int{
                    if(strlen($body)+strlen($chunk)>1048576){$tooLarge=true;return 0;}$body.=$chunk;return strlen($chunk);
                }];
            if($method==='POST'&&$json!==null){
                $options[CURLOPT_POST]=true;$options[CURLOPT_POSTFIELDS]=json_encode($json,JSON_THROW_ON_ERROR);
                $options[CURLOPT_HTTPHEADER][]='Content-Type: application/json';
            }elseif($method==='POST'){
                $temp=tempnam(MaxTrackerLookupConfig::csvDirectory(),'conversion-');if($temp===false)throw new RuntimeException('private_csv_unavailable');
                chmod($temp,0600);if(file_put_contents($temp,$csv)!==strlen((string)$csv))throw new RuntimeException('private_csv_unavailable');
                $options[CURLOPT_POST]=true;$options[CURLOPT_POSTFIELDS]=['file'=>new CURLFile($temp,'text/csv','offline_conversions.csv')];
            }
            curl_setopt_array($ch,$options);$ok=curl_exec($ch);
            return ['http'=>(int)curl_getinfo($ch,CURLINFO_HTTP_CODE),'error'=>$tooLarge?23:curl_errno($ch),'body'=>$body];
        }finally{if($ch!==null&&$ch!==false)curl_close($ch);if(is_string($temp)&&is_file($temp))unlink($temp);}
    }
}
