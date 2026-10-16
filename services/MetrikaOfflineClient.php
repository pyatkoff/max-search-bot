<?php
declare(strict_types=1);
require_once __DIR__.'/MaxTrackerLookupProtocol.php';
require_once __DIR__.'/MaxTrackerLookupConfig.php';
require_once __DIR__.'/MaxChannelConversionOutbox.php';

/** Existing counter/token, new ClientId CSV transport. No raw API logging. */
final class MetrikaOfflineClient
{
    // Historical wire identifiers remain valid for receipt recovery only.
    public const CHANNEL_GOALS=[
        'max_bot_activity_start'=>['target'=>'max_channel_activity_start','name'=>'MAX канал: начало общения'],
        'max_manager_reply'=>['target'=>'max_channel_manager_reply','name'=>'MAX канал: ответ менеджера'],
        'max_customer_reply_after_manager'=>['target'=>'max_channel_customer_reply_after_manager','name'=>'MAX канал: клиент ответил менеджеру'],
    ];
    public const SHARED_GOALS=[
        'max_bot_activity_start'=>['id'=>'611089509','target'=>'max_bot_activity_start','name'=>'max_bot_activity_start'],
        'max_manager_request'=>['id'=>'600725752','target'=>'max_manager_request','name'=>'max_manager_request'],
        'max_customer_reply_after_manager'=>['id'=>'605075686','target'=>'max_customer_reply_after_manager','name'=>'max_customer_reply_after_manager'],
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
            ||!in_array($event['target']??'',MaxChannelConversionOutbox::HISTORICAL_TARGETS,true)
            ||!is_numeric($event['event_unix']??null)||(int)$event['event_unix']<=0||(int)$event['event_unix']>$now
            ||(int)$event['event_unix']<$now-21*86400)throw new InvalidArgumentException('invalid_conversion_event');
        $allowed=[];
        if(isset(self::CHANNEL_GOALS[$event['target']]))$allowed[]=self::CHANNEL_GOALS[$event['target']]['target'];
        if(isset(self::SHARED_GOALS[$event['target']]))$allowed[]=self::SHARED_GOALS[$event['target']]['target'];
        if(!in_array($event['transport_target']??null,$allowed,true))throw new InvalidArgumentException('invalid_conversion_target');
        $f=fopen('php://temp','w+');fputcsv($f,['ClientId','Target','DateTime']);
        fputcsv($f,[$event['client_id'],$event['transport_target'],(string)$event['event_unix']]);rewind($f);$csv=stream_get_contents($f);fclose($f);return $csv;
    }

    public function upload(array $event,int $now): array
    {
        if(($event['counter_id']??null)!==$this->counter)throw new InvalidArgumentException('wrong_conversion_counter');
        if(!isset(self::SHARED_GOALS[$event['target']??''])||($event['transport_target']??null)!==self::SHARED_GOALS[$event['target']]['target'])throw new InvalidArgumentException('retired_conversion_target');
        $csv=self::csv($event,$now);$result=$this->request('POST','offline_conversions/upload?type=BASIC&comment='.rawurlencode(self::comment($event)),$csv);
        $error=(int)($result['error']??0);$http=(int)($result['http']??0);
        if($error!==0)return ['outcome'=>in_array($error,[5,6,7],true)?'retry':'unknown','reason'=>in_array($error,[5,6,7],true)?'transport_not_sent':'upload_outcome_unknown'];
        if(in_array($http,[420,429],true))return ['outcome'=>'retry','reason'=>'rate_limited'];
        if($http>=400&&$http<500)return ['outcome'=>'rejected','reason'=>'api_rejected'];
        $d=$this->decode($result);$u=$d['uploading']??null;
        if($http>=200&&$http<300&&$this->validReceipt($u)
            &&(!array_key_exists('comment',$u)||$u['comment']===self::comment($event)))return ['outcome'=>'accepted','upload_id'=>(string)$u['id'],'status'=>(string)($u['status']??'UPLOADED')];
        return ['outcome'=>'unknown','reason'=>'upload_outcome_unknown'];
    }

    public function poll(string $uploadId): ?array
    {
        if(!MaxTrackerLookupProtocol::id($uploadId))return null;
        $r=$this->request('GET','offline_conversions/uploading/'.$uploadId);
        if((int)($r['error']??0)!==0||(int)($r['http']??0)!==200)return null;
        $d=$this->decode($r);$u=$d['uploading']??null;
        return $this->validReceipt($u)&&(string)$u['id']===$uploadId?$u:null;
    }

    /** Read-only recovery of a lost POST acknowledgement; no automatic resend. */
    public function findUnknown(array $event): ?array
    {
        $comment=self::comment($event);$matches=[];
        for($page=0;$page<3;$page++){
            $r=$this->request('GET','offline_conversions/uploadings?limit=100&offset='.($page*100));
            if((int)($r['error']??0)!==0||(int)($r['http']??0)!==200)return null;
            $d=$this->decode($r);$list=$d['uploadings']??null;if(!is_array($list))return null;
            foreach($list as $u)if($this->validReceipt($u)&&($u['comment']??'')===$comment)$matches[(string)$u['id']]=$u;
            if(count($list)<100)break;
        }
        return count($matches)===1?array_values($matches)[0]:null;
    }

    /** The live BASIC API omits client_id_type; explicit conflicting values fail closed. */
    private function validReceipt($receipt): bool
    {
        return is_array($receipt)
            &&(is_int($receipt['id']??null)||is_string($receipt['id']??null))
            &&MaxTrackerLookupProtocol::id((string)$receipt['id'])
            &&in_array($receipt['status']??null,['PREPARED','UPLOADED','EXPORTED','MATCHED','PROCESSED','LINKAGE_FAILURE'],true)
            &&(!array_key_exists('type',$receipt)||$receipt['type']==='BASIC')
            &&(!array_key_exists('client_id_type',$receipt)||$receipt['client_id_type']==='CLIENT_ID');
    }

    public function goalsReady(): bool
    {
        try{
            $goals=$this->readGoals();
            $selected=$this->selectedGoals($goals);
            foreach(self::SHARED_GOALS as $target=>$definition){
                $matches=$this->exactGoals($goals,$target);
                if(count($matches)!==1||(string)$matches[0]['id']!==$definition['id'])return false;
                if(($selected[$target]['conditions'][0]['type']??null)!=='exact')return false;
            }
            return true;
        }catch(Throwable $ignored){return false;}
    }

    /** Explicit owner-selected existing IDs only: no creation, deletion or renaming. */
    public function configureSharedGoals(bool $apply=false): array
    {
        if($this->counter!=='98615635')throw new RuntimeException('wrong_goal_counter');
        $before=$this->readGoals();$selected=$this->selectedGoals($before);$receipts=[];$changed=[];
        foreach(self::SHARED_GOALS as $target=>$definition){
            $goal=$selected[$target];$needsUpdate=$goal['conditions'][0]['type']!=='exact';
            $receipts[$target]=['id'=>$definition['id'],'ready'=>!$needsUpdate,'updated'=>false];
        }
        foreach($apply?self::SHARED_GOALS:[] as $target=>$definition){
            $goal=$selected[$target];if($goal['conditions'][0]['type']==='exact')continue;
            // Check the exact ID again before editing; a concurrent operator change
            // must never be overwritten with this earlier snapshot.
            $fresh=$this->selectedGoals($this->readGoals())[$target];
            if($this->goalSemantics($fresh)!==$this->goalSemantics($goal))throw new RuntimeException('existing_goal_changed');
            $replacement=$goal;$replacement['conditions']=[['type'=>'exact','url'=>$target]];
            $payload=[];foreach(['id','name','type','default_price','is_favorite','status','flag','conditions'] as $key)if(array_key_exists($key,$replacement))$payload[$key]=$replacement[$key];
            $r=$this->request('PUT','goal/'.$definition['id'],null,['goal'=>$payload]);
            if((int)($r['error']??0)!==0||(int)($r['http']??0)!==200)throw new RuntimeException('goal_update_requires_readback');
            $data=$this->decode($r);$actual=$data['goal']??null;
            if(!is_array($actual)||$this->goalSemantics($actual)!==$this->goalSemantics($replacement))throw new RuntimeException('goal_update_requires_readback');
            $changed[$definition['id']]=$replacement;
            $receipts[$target]=['id'=>$definition['id'],'ready'=>true,'updated'=>true];
        }
        if($apply){
            $after=$this->readGoals();$byId=[];foreach($after as $goal)$byId[(string)($goal['id']??'')]=$goal;
            if(count($after)!==count($before))throw new RuntimeException('existing_goal_changed');
            foreach($before as $goal){
                $id=(string)($goal['id']??'');$expected=$changed[$id]??$goal;
                if(!isset($byId[$id])||$this->goalSemantics($expected)!==$this->goalSemantics($byId[$id]))throw new RuntimeException('existing_goal_changed');
            }
            foreach(self::SHARED_GOALS as $target=>$definition){
                $matches=$this->exactGoals($after,$target);
                if(count($matches)!==1||(string)$matches[0]['id']!==$definition['id'])throw new RuntimeException('goal_update_requires_readback');
            }
        }
        return ['goals'=>$receipts,'existing_goals_checked'=>count($before),'only_selected_conditions_changed'=>$apply,'goal_writes'=>count($changed),'conversion_requests'=>0];
    }

    private function selectedGoals(array $goals): array
    {
        $selected=[];
        foreach(self::SHARED_GOALS as $target=>$definition){
            $matches=array_values(array_filter($goals,static fn($goal)=>is_array($goal)&&(string)($goal['id']??'')===$definition['id']));
            if(count($matches)!==1)throw new RuntimeException('selected_goal_missing');
            $goal=$matches[0];$conditions=$goal['conditions']??null;
            if(($goal['type']??'')!=='action'||!is_string($goal['name']??null)||!is_array($conditions)||count($conditions)!==1
                ||!in_array($conditions[0]['type']??null,['contain','exact'],true)||($conditions[0]['url']??null)!==$target)throw new RuntimeException('selected_goal_conflict');
            foreach($goals as $other)if((string)($other['id']??'')!==$definition['id']){
                foreach((array)($other['conditions']??[]) as $condition)if(($condition['url']??null)===$target)throw new RuntimeException('ambiguous_shared_goal');
            }
            $selected[$target]=$goal;
        }
        return $selected;
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
        return json_encode($normalize(array_intersect_key($goal,array_fill_keys(['id','name','type','conditions','default_price','is_favorite','status','flag','goal_source','steps'],true))),JSON_THROW_ON_ERROR);
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
            if(in_array($method,['POST','PUT'],true)&&$json!==null){
                $options[CURLOPT_CUSTOMREQUEST]=$method;$options[CURLOPT_POSTFIELDS]=json_encode($json,JSON_THROW_ON_ERROR);
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

