<?php
declare(strict_types=1);
require_once __DIR__.'/ModeledConversionStatusBudget.php';

/** Private metadata of the local checker. Never opens its credential state. */
final class ModeledConversionPipelineMonitorStatus
{
    private const FLAGS=['delivery_tick_stale','profile_tick_stale','delivery_tick_errors','profile_tick_errors',
        'profile_disabled','ready_queue_stale','upload_outcome_unknown','api_row_rejected','linkage_failures_increased','report_unavailable',
        'storage_unavailable','configuration_unavailable','invalid_clock','health_metadata_unavailable','visit_proof_stale','visit_proof_refresh_failed'];
    private const ACTIVATION_KEYS=['schema_version','project','counter_id','role','operation','activated_at','source_sha','state_imported'];
    private const RECEIPT_KEYS=['schema_version','project','counter_id','role','origin','checked_at','status','flags',
        'notification','last_notification_at','source_sha','cycle_id','ok','checks'];

    public static function read(ModeledConversionStatusBudget $budget,callable $authorize,int $now,?string $directory=null):array
    {
        $guard=static function()use($budget,$authorize):void{$budget->check();$authorize();$budget->check();};
        $finish=static function(array $out)use($guard):array{$guard();return$out;};
        $guard();
        $out=['available'=>false,'active'=>null,'reason'=>'not_recorded','origin'=>null,'activated_at'=>null,
            'waiting_first_tick'=>false,'checked_at'=>null,'fresh'=>false,'status'=>null,'flags'=>[],
            'notification'=>null,'last_notification_at'=>null,'source_sha'=>null,'cycle_id'=>null,'ok'=>null,
            'scheduled'=>['available'=>false,'last_checked_at'=>null,'fresh'=>null,'success_streak'=>0,'regular'=>false,'checks'=>[]]];
        if($now<946684800||$now>253402300799){$out['reason']='invalid_clock';return$finish($out);}
        $dir=$directory??dirname(__DIR__,2).'/max-search-bot-modeled-pipeline';
        $guard();clearstatcache(true,$dir);$stat=@lstat($dir);$guard();
        if($stat===false){
            if(!is_readable(dirname($dir))||!is_executable(dirname($dir))){$out['reason']='unsafe_storage';return$finish($out);}
            $out['active']=false;$out['reason']='not_activated';return$finish($out);
        }
        if(!self::safeMode($stat,0040000,0700)||!is_readable($dir)||!is_executable($dir)){$out['reason']='unsafe_storage';return$finish($out);}
        $activation=self::document($dir,'activation.json',2048,$budget,$guard);
        if($activation['kind']==='missing'){$out['active']=false;$out['reason']='not_activated';return$finish($out);}
        if($activation['kind']!=='ok'){$out['reason']='invalid_activation';return$finish($out);}
        try{$a=self::activation($activation['data'],$now);}
        catch(UnexpectedValueException $ignored){$out['reason']='invalid_activation';return$finish($out);}
        $out['active']=true;$out['activated_at']=$a['activated_at'];
        $receipt=self::document($dir,'pipeline-checks.json',4096,$budget,$guard);
        if($receipt['kind']==='missing'){
            $out['waiting_first_tick']=$now-$a['activated_unix']<=600;
            return$finish($out);
        }
        if($receipt['kind']!=='ok'){$out['reason']='invalid_metadata';return$finish($out);}
        try{$r=self::receipt($receipt['data'],$now,$a['activated_unix']);}
        catch(UnexpectedValueException $ignored){$out['reason']='invalid_metadata';return$finish($out);}
        $checks=$r['checks'];$count=count($checks);$streak=0;
        for($i=$count-1;$i>=0;$i--){
            if(!$checks[$i]['ok'])break;
            if($i===$count-1&&(!$r['ok']||$r['notification']==='unknown'))break;
            if($i<$count-1&&self::clock($checks[$i+1]['checked_at'],$now)-self::clock($checks[$i]['checked_at'],$now)>900)break;
            $streak++;
        }
        $last=$count===0?null:$checks[$count-1]['checked_at'];
        $scheduledFresh=$last!==null&&$now-self::clock($last,$now)<=900;
        $out=array_replace($out,['available'=>true,'reason'=>null,'origin'=>'server_cron','checked_at'=>$r['checked_at'],
            'fresh'=>$now-$r['checked_unix']<=900,'status'=>$r['status'],'flags'=>$r['flags'],
            'notification'=>$r['notification'],'last_notification_at'=>$r['last_notification_at'],
            'source_sha'=>$r['source_sha'],'cycle_id'=>$r['cycle_id'],'ok'=>$r['ok'],
            'scheduled'=>['available'=>$count>0,'last_checked_at'=>$last,'fresh'=>$last===null?null:$scheduledFresh,
                'success_streak'=>$streak,'regular'=>$streak===3&&$scheduledFresh,'checks'=>$checks]]);
        return$finish($out);
    }

    /** Null means the pre-cutover legacy check still owns full SQL coverage. */
    public static function availability(int $now,?string $directory=null):?array
    {
        $p=self::read(new ModeledConversionStatusBudget(),static function():void{},$now,$directory);
        if($p['active']===false)return null;
        $flags=[];
        if($p['active']===true&&$p['waiting_first_tick']){
            $status='ok';
        }elseif($p['available']!==true||$p['fresh']!==true){
            $status='unavailable';$flags=['pipeline_checker_stale'];
        }elseif($p['ok']!==true||$p['notification']==='unknown'){
            $status='unavailable';$flags=['pipeline_checker_notification_unknown'];
        }else{
            // Business incidents belong to the local sender. This role checks its liveness.
            $status='ok';
        }
        return['schema_version'=>1,'project'=>'anytour','counter_id'=>'98615635','status'=>$status,'flags'=>$flags,
            'monitor_scope'=>'external','waiting_first_tick'=>$p['waiting_first_tick'],
            'pipeline_checked_at'=>$p['checked_at'],'pipeline_fresh'=>$p['fresh'],
            'pipeline_reason'=>$p['reason'],'pipeline_activated_at'=>$p['activated_at']];
    }

    /** Only two typed, bounded files are admitted; raw state and runtime journals are excluded. */
    private static function document(string $dir,string $name,int $limit,ModeledConversionStatusBudget $budget,callable $guard):array
    {
        if(!in_array($name,['activation.json','pipeline-checks.json'],true))throw new LogicException('pipeline_document');
        $path=$dir.'/'.$name;$guard();clearstatcache(true,$path);$before=@lstat($path);$guard();
        if($before===false)return['kind'=>'missing'];
        if(!self::safeMode($before,0100000,0600)||!is_int($before['size']??null)||$before['size']<1||$before['size']>$limit)
            return['kind'=>'invalid'];
        $handle=@fopen($path,'rb');
        if($handle===false){$guard();return['kind'=>'invalid'];}
        try{
            $guard();$opened=fstat($handle);
            if(!is_array($opened)||!self::safeMode($opened,0100000,0600)
                ||($opened['ino']??null)!==($before['ino']??null)||($opened['dev']??null)!==($before['dev']??null)
                ||($opened['size']??null)!==($before['size']??null))return['kind'=>'invalid'];
            $raw=@stream_get_contents($handle,$limit+1);$after=fstat($handle);$guard();
            if(is_string($raw))$budget->consumeBytes(strlen($raw));
            if(!is_string($raw)||strlen($raw)>$limit||strlen($raw)!==$opened['size']
                ||!is_array($after)||!self::safeMode($after,0100000,0600)||($after['size']??null)!==$opened['size'])return['kind'=>'invalid'];
        }finally{fclose($handle);$guard();}
        try{$data=json_decode($raw,true,16,JSON_THROW_ON_ERROR);}
        catch(JsonException $ignored){return['kind'=>'invalid'];}
        return is_array($data)?['kind'=>'ok','data'=>$data]:['kind'=>'invalid'];
    }
    private static function activation(array $r,int $now):array
    {
        self::onlyKeys($r,self::ACTIVATION_KEYS);
        if(!self::scope($r)||!is_string($r['operation']??null)
            ||preg_match('/\A[1-9][0-9]{0,19}\z/D',$r['operation'])!==1||($r['state_imported']??null)!==true)
            throw new UnexpectedValueException('pipeline_activation');
        $when=self::clock($r['activated_at']??null,$now);self::sha($r['source_sha']??null);
        return['activated_at'=>$r['activated_at'],'activated_unix'=>$when];
    }
    private static function receipt(array $r,int $now,int $activated):array
    {
        self::onlyKeys($r,self::RECEIPT_KEYS);
        if(!self::scope($r)||($r['origin']??null)!=='server_cron'
            ||!in_array($r['status']??null,['ok','off','degraded','unavailable'],true)
            ||!in_array($r['notification']??null,['quiet','sent','unknown'],true)||!is_bool($r['ok']??null))
            throw new UnexpectedValueException('pipeline_receipt');
        $when=self::clock($r['checked_at']??null,$now);
        if($when<$activated)throw new UnexpectedValueException('pipeline_clock');
        self::sha($r['source_sha']??null);self::cycle($r['cycle_id']??null);
        $flags=$r['flags']??null;
        if(!is_array($flags)||!array_is_list($flags)||count($flags)>20)throw new UnexpectedValueException('pipeline_flags');
        foreach($flags as$flag)if(!is_string($flag)||!in_array($flag,self::FLAGS,true))throw new UnexpectedValueException('pipeline_flags');
        if(in_array($r['status'],['ok','off'],true)&&$flags!==[])throw new UnexpectedValueException('pipeline_flags');
        $last=$r['last_notification_at']??null;
        if($last!==null&&self::clock($last,$now)>$when)throw new UnexpectedValueException('pipeline_clock');
        $history=$r['checks']??null;
        if(!is_array($history)||!array_is_list($history)||count($history)>3)throw new UnexpectedValueException('pipeline_history');
        $checks=[];$ids=[];$previous=null;
        foreach($history as$check){
            if(!is_array($check))throw new UnexpectedValueException('pipeline_history');
            self::onlyKeys($check,['cycle_id','checked_at','status','ok','source_sha']);
            self::cycle($check['cycle_id']??null);self::sha($check['source_sha']??null);
            $at=self::clock($check['checked_at']??null,$now);
            if($at<$activated||$at>$when||($previous!==null&&$at<=$previous)||isset($ids[$check['cycle_id']])
                ||!in_array($check['status']??null,['ok','off','degraded','unavailable'],true)
                ||!is_bool($check['ok']??null)||($check['ok']&&!in_array($check['status'],['ok','off'],true)))
                throw new UnexpectedValueException('pipeline_history');
            $ids[$check['cycle_id']]=true;$previous=$at;
            $checks[]=['cycle_id'=>$check['cycle_id'],'checked_at'=>$check['checked_at'],
                'status'=>$check['status'],'ok'=>$check['ok'],'source_sha'=>$check['source_sha']];
        }
        if($checks!==[]){
            $tail=$checks[count($checks)-1];
            foreach(['cycle_id','checked_at','status','source_sha']as$key)if($tail[$key]!==$r[$key])
                throw new UnexpectedValueException('pipeline_history');
        }
        return['checked_at'=>$r['checked_at'],'checked_unix'=>$when,'status'=>$r['status'],
            'flags'=>array_values(array_unique($flags)),'notification'=>$r['notification'],'last_notification_at'=>$last,
            'source_sha'=>$r['source_sha'],'cycle_id'=>$r['cycle_id'],'ok'=>$r['ok'],'checks'=>$checks];
    }
    private static function scope(array $r):bool
    {
        return($r['schema_version']??null)===1&&($r['project']??null)==='anytour'
            &&($r['counter_id']??null)==='98615635'&&($r['role']??null)==='pipeline';
    }
    private static function onlyKeys(array $r,array $keys):void
    {
        if(array_diff(array_keys($r),$keys)!==[]||array_diff($keys,array_keys($r))!==[])
            throw new UnexpectedValueException('pipeline_fields');
    }
    private static function clock(mixed $value,int $now):int
    {
        if(!is_string($value)||preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D',$value)!==1)
            throw new UnexpectedValueException('pipeline_clock');
        $unix=strtotime($value);
        if($unix===false||$unix<946684800||$unix>$now+60||gmdate('Y-m-d\TH:i:s\Z',$unix)!==$value)
            throw new UnexpectedValueException('pipeline_clock');
        return$unix;
    }
    private static function sha(mixed $value):void
    {
        if(!is_string($value)||preg_match('/\A[0-9a-f]{40}\z/D',$value)!==1)throw new UnexpectedValueException('pipeline_source');
    }
    private static function cycle(mixed $value):void
    {
        if(!is_string($value)||preg_match('/\A[0-9a-f]{32}\z/D',$value)!==1)throw new UnexpectedValueException('pipeline_cycle');
    }
    private static function safeMode(array $stat,int $type,int $permissions):bool
    {
        return is_int($stat['mode']??null)&&($stat['mode']&0170000)===$type&&($stat['mode']&0777)===$permissions;
    }
}
