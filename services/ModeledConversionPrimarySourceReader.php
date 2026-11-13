<?php
declare(strict_types=1);
require_once __DIR__.'/ModeledConversionStatusBudget.php';
require_once __DIR__.'/ModeledConversionProfiles.php';
require_once __DIR__.'/ModeledConversionValueIdentityEvidence.php';
require_once __DIR__.'/ModeledConversionValueVisitEvidence.php';
require_once __DIR__.'/TrafficAttributionService.php';

/** Exact original-case source inspection. Hints only; no provider, Native or financial operation. */
final class ModeledConversionPrimarySourceReader
{
    private string $root;
    private int $owner;
    public function __construct(private PDO $pdo,private $authorize,private ModeledConversionStatusBudget $budget,?string $appRoot=null)
    {
        $this->root=$appRoot??dirname(__DIR__);
        $owner=@fileowner(__FILE__);$this->owner=is_int($owner)?$owner:-1;
    }
    public function read(int $now):array
    {
        $this->auth();
        if($this->pdo->inTransaction())return $this->unavailable('outer_transaction');
        try{
            return $this->budget->withReservedBytes(8192,function()use($now):array{
                $mysql=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
                $length=$mysql?'OCTET_LENGTH(last_receipt_json)':'LENGTH(CAST(last_receipt_json AS BLOB))';
                $settings=$this->query("SELECT goal_id,goal_intent,contract_version,CASE WHEN ".$length."<=8192 THEN last_receipt_json ELSE NULL END AS receipt_json"
                    ." FROM modeled_conversion_value_export_settings WHERE project_key='anytour' AND counter_id='98615635' AND HEX(project_key)=HEX('anytour') AND HEX(counter_id)=HEX('98615635') LIMIT 1",[]);
                if(count($settings)!==1||(string)$settings[0]['goal_id']!=='678187097'||$settings[0]['goal_intent']!=='verified'
                    ||$settings[0]['contract_version']!==ModeledConversionValueContract::VERSION
                    ||!is_string($settings[0]['receipt_json']??null))return $this->unavailable('original_frame_unavailable');
                $saved=json_decode($settings[0]['receipt_json'],true,32,JSON_THROW_ON_ERROR);
                if(!is_array($saved)||array_is_list($saved))return $this->unavailable('original_frame_unavailable');
                $namespace=ModeledConversionProfiles::assessmentNamespace();
                $frame=ModeledConversionValueVisitEvidence::decode($saved['finite_visit_evidence']??null,$namespace,$now);
                if($frame===null)return $this->unavailable('original_frame_unavailable');
                $ids=array_column($frame['r'],1);
                $names=['id','value_profile_id','project_key','platform','conversation_id','external_user_id','counter_id',
                    'target','transport_target','value_contract','event_unix','value_rub','state','upload_id','value_api_accepted_at_unix',
                    'profile_project','profile_platform','profile_conversation','profile_namespace','profile_kind','profile_observed'];
                $fields=['o.id','o.value_profile_id','o.project_key','o.platform','o.conversation_id','o.external_user_id','o.counter_id',
                    'o.target','o.transport_target','o.value_contract','o.event_unix','o.value_rub','o.state','o.upload_id','o.value_api_accepted_at_unix',
                    'p.project_key','p.platform','p.conversation_id','p.assessment_namespace','p.assessment_kind','p.observed_at_unix'];
                $inner='SELECT o.id AS binding_order,JSON_ARRAY('.implode(',',$fields).') AS packet'
                    .' FROM max_channel_conversion_outbox o JOIN modeled_conversion_profile_versions p ON p.id=o.value_profile_id'
                    .' WHERE o.id IN (?,?,?) ORDER BY o.id LIMIT 4 OFFSET 0';
                $bytes=$mysql?'OCTET_LENGTH(JSON_QUOTE(CAST(packet AS CHAR)))':'LENGTH(CAST(JSON_QUOTE(CAST(packet AS TEXT)) AS BLOB))';
                $packets=$this->query('SELECT CASE WHEN SUM('.$bytes.'+24) OVER ()+2<=8192 THEN packet ELSE NULL END AS packet'
                    .' FROM ('.$inner.') primary_source_original_bindings ORDER BY binding_order',$ids);
                if(count($packets)!==3)return $this->unavailable('original_binding_unverified');
                $bound=[];
                foreach($packets as$packet){
                    if(!is_string($packet['packet']??null))return $this->unavailable('original_binding_unverified');
                    $values=json_decode($packet['packet'],true,8,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);
                    if(!is_array($values)||count($values)!==count($names))return $this->unavailable('original_binding_unverified');
                    $row=array_combine($names,$values);
                    $ref=ModeledConversionValueIdentityEvidence::reference($row,$namespace);
                    if($ref===null||isset($bound[$ref])||$row['platform']!=='max'||$row['profile_project']!=='anytour'
                        ||$row['profile_platform']!=='max'||(string)$row['profile_conversation']!==(string)$row['conversation_id']
                        ||$row['profile_namespace']!==$namespace||$row['profile_kind']!=='repair'
                        ||!preg_match('/\A[1-9][0-9]{0,9}\z/D',(string)$row['profile_observed'])
                        ||(int)$row['profile_observed']<946684800||(int)$row['profile_observed']>ModeledConversionValueVisitEvidence::CHECKPOINT)
                        return $this->unavailable('original_binding_unverified');
                    $bound[$ref]=$row;
                }
                foreach($frame['r']as$tuple){
                    $row=$bound[$tuple[0]]??null;
                    if($row===null||(string)$row['id']!==$tuple[1]||(string)$row['event_unix']!==(string)$tuple[15])
                        return $this->unavailable('original_binding_unverified');
                }
                $out=$this->base()+['available'=>true,'checked_at'=>gmdate('Y-m-d\TH:i:s\Z',$now),
                    'assessment_namespace'=>$namespace,'original_binding_verified'=>true,'rows'=>[]];
                foreach($frame['r']as$i=>$tuple)$out['rows'][]=$this->fileView($bound[$tuple[0]],$tuple,$namespace,$now,$i);
                $this->auth();
                if(strlen(json_encode($out,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))>28000)throw new ModeledConversionStatusLimit('byte_limit');
                return $out;
            });
        }catch(ModeledConversionStatusLimit|DomainException $error){throw $error;}
        catch(PDOException|JsonException $ignored){return $this->unavailable('primary_source_storage_unavailable');}
    }
    private function fileView(array $row,array $tuple,string $namespace,int $now,int $case):array
    {
        $out=['case_index'=>$case,'event_ref'=>$tuple[0],'event_at'=>gmdate('Y-m-d\TH:i:s\Z',$tuple[15]),
            'available'=>false,'reason'=>null,'status'=>'unknown','yclid_hint_sha256'=>null,'yclid_matches_raw_payload'=>null,
            'current_file_sha256'=>null,'current_file_locator_sha256'=>null,'writer_timestamp'=>null,
            'first_saved_at'=>null,'first_enriched_at'=>null,'history_complete'=>false];
        $fail=static fn(string $reason):array=>array_replace($out,['reason'=>$reason]);
        if(!in_array($row['state'],['pending','ready'],true)||!in_array($row['upload_id'],[null,''],true)||$row['value_api_accepted_at_unix']!==null)
            return $fail('existing_or_unknown_financial_receipt_not_rechecked');
        $uid=(string)$row['external_user_id'];
        $chat='-'.$uid;
        $root=realpath($this->root);
        if($root===false||$root!==rtrim($this->root,DIRECTORY_SEPARATOR)||$this->owner<0)return $fail('source_directory_unavailable');
        $directory=$root.'/traffic';
        clearstatcache(true,$directory);$dir=@lstat($directory);
        if(!$this->regular($dir,0040000)||($dir['mode']&0022)!==0)return $fail('source_directory_unavailable');
        $path=$directory.'/'.$chat.'.json';
        clearstatcache(true,$path);$before=@lstat($path);
        if($before===false)return $fail('not_found');
        if(!$this->regular($before,0100000)||($before['mode']&0022)!==0)return $fail('unsafe_file');
        if($before['size']>8192)return $fail('too_large');
        $this->auth();
        $handle=@fopen($path,'rb');if($handle===false)return $fail('unreadable');
        try{
            $opened=@fstat($handle);clearstatcache(true,$path);$named=@lstat($path);
            if(!$this->same($before,$opened)||!$this->same($before,$named))return $fail('changed_during_read');
            $raw=stream_get_contents($handle,8193);
            if(!is_string($raw))return $fail('unreadable');
            $this->budget->consumeBytes(strlen($raw));$this->auth();
            clearstatcache(true,$path);clearstatcache(true,$directory);
            if(!$this->same($before,@fstat($handle))||!$this->same($before,@lstat($path))
                ||!$this->same($dir,@lstat($directory)))return $fail('changed_during_read');
        }finally{fclose($handle);}
        if(strlen($raw)>8192)return $fail('too_large');
        if(strlen($raw)!==$before['size'])return $fail('changed_during_read');
        try{$data=json_decode($raw,true,8,JSON_THROW_ON_ERROR);}catch(JsonException $ignored){return $fail('malformed');}
        $keys=['chat_id','yclid','region_id','campaign_id','entry_channel','raw','updated_at','post_id','offer_id'];
        if(!is_array($data)||array_is_list($data)||array_diff(array_keys($data),$keys)
            ||($data['chat_id']??null)!==$chat||!is_string($data['yclid']??null)||!is_string($data['raw']??null)
            ||strlen($data['raw'])>512||!is_string($data['updated_at']??null)
            ||preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:Z|[+-][0-9]{2}:[0-9]{2})\z/D',$data['updated_at'])!==1)
            return $fail('malformed');
        try{$clock=new DateTimeImmutable($data['updated_at']);}catch(Throwable $ignored){return $fail('malformed');}
        if($clock->format('Y-m-d\TH:i:sP')!==str_replace('Z','+00:00',$data['updated_at'])
            ||$clock->getTimestamp()<946684800||$clock->getTimestamp()>$now)return $fail('malformed');
        if($clock->getTimestamp()>$tuple[15])return $fail('after_event');
        $yclid=$data['yclid'];
        if($yclid!==''&&preg_match('/\A[1-9][0-9]{5,39}\z/D',$yclid)!==1)return $fail('malformed');
        $fingerprint=hash('sha256',$raw);
        $out['available']=true;$out['status']=$yclid===''?'yclid_not_present_in_latest_file':'saved_yclid_hint';
        $out['current_file_sha256']=$fingerprint;
        $out['current_file_locator_sha256']=hash('sha256',json_encode(['primary_traffic_file_v1',$namespace,$tuple[0],$uid,$fingerprint],JSON_THROW_ON_ERROR));
        $out['writer_timestamp']=gmdate('Y-m-d\TH:i:s\Z',$clock->getTimestamp());
        if($yclid!==''){
            $out['yclid_hint_sha256']=hash('sha256',json_encode(['primary_traffic_yclid_v1',$namespace,$tuple[0],$fingerprint,$yclid],JSON_THROW_ON_ERROR));
            $out['yclid_matches_raw_payload']=TrafficAttributionService::parseStartPayload($data['raw'])['yclid']===$yclid;
        }
        return $out;
    }
    private function regular(mixed $stat,int $type):bool
    {return is_array($stat)&&($stat['mode']&0170000)===$type&&$stat['uid']===$this->owner;}
    private function same(mixed $before,mixed $after):bool
    {
        if(!is_array($before)||!is_array($after))return false;
        foreach(['dev','ino','mode','uid','gid','size','mtime','ctime']as$key)if($before[$key]!==$after[$key])return false;
        return true;
    }
    private function query(string $sql,array $args):array
    {
        $q=$this->budget->prepare($this->pdo,$sql);$q->execute($args);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
        $this->budget->consumeBytes(strlen(json_encode($rows,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)));$this->auth();return $rows;
    }
    private function auth():void{$this->budget->check();($this->authorize)();$this->budget->check();}
    private function base():array
    {
        return ['version'=>'frozen_primary_traffic_review_v1','basis'=>'existing_latest_traffic_files_for_original_three_events',
            'source_counter_binding'=>'not_recorded_in_file','timestamp_semantics'=>'latest_metadata_writer_timestamp_not_visit_or_first_save',
            'history_complete'=>false,'financial_permission'=>false,'visit_linkage'=>'not_checked','provider_requests'=>0,'provider_requests_in_reader'=>0,
            'model_calls'=>0,'conversion_uploads'=>0];
    }
    private function unavailable(string $reason):array{return $this->base()+['available'=>false,'reason'=>$reason];}
}
