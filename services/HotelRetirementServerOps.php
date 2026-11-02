<?php
declare(strict_types=1);
require_once __DIR__.'/MigrationRunner.php';
require_once __DIR__.'/HotelWorkRetirement.php';

final class HotelExistingAdminUnavailable extends RuntimeException
{
    public function __construct(public readonly array $report){parent::__construct('existing_admin_context_unavailable');}
}

/** Exact existing-contour maintenance. Never dispatches research or changes grants. */
final class HotelRetirementServerOps
{
    public const QUEUE='HOTEL-CONTINUOUS-20261027';
    public const VERSION='083_hotel_work_retirement.sql';
    public const MIGRATION_SHA='1d39818eb8841aa80d8427dab7cb82562b02e2d6665529e7b8515044d18562ae';
    public const BACKUP_SHA='61917edb636c752176a5d369d885e1aea249dae8be0d1764d46d7b744b5ca1f2';
    public const REQUEST='retire-HOTEL-CONTINUOUS-20261027-owner-decision-20261101';
    public const META='SELECT DATABASE() AS db,@@hostname AS host,@@port AS port,@@server_id AS server_id,@@socket AS socket,@@log_bin AS binlog,@@log_bin_trust_function_creators AS trust_creators,VERSION() AS version';
    private const GRANTEE="CONCAT(QUOTE(SUBSTRING_INDEX(CURRENT_USER(),'@',1)),'@',QUOTE(SUBSTRING_INDEX(CURRENT_USER(),'@',-1)))";
    public const GLOBALS='SELECT PRIVILEGE_TYPE FROM information_schema.USER_PRIVILEGES WHERE GRANTEE='.self::GRANTEE;
    public const SCHEMA='SELECT PRIVILEGE_TYPE FROM information_schema.SCHEMA_PRIVILEGES WHERE TABLE_SCHEMA=DATABASE() AND GRANTEE='.self::GRANTEE;

    public static function metadata(PDO $p):array
    {
        if($p->inTransaction())throw new RuntimeException('maintenance_foreign_transaction');
        $p->exec('SET TRANSACTION READ ONLY');$p->beginTransaction();
        try{
            $m=$p->query(self::META)->fetchAll(PDO::FETCH_ASSOC);
            $g=$p->query(self::GLOBALS)->fetchAll(PDO::FETCH_COLUMN);
            $s=$p->query(self::SCHEMA)->fetchAll(PDO::FETCH_COLUMN);
            if(count($m)!==1)throw new RuntimeException('maintenance_metadata_unavailable');
            foreach(['db','host','port','server_id','socket','binlog','trust_creators','version']as$k)
                if(!array_key_exists($k,$m[0])||!is_scalar($m[0][$k]))throw new RuntimeException('maintenance_metadata_unavailable');
            $super=in_array('SUPER',$g,true);$trigger=in_array('TRIGGER',[...$g,...$s],true);
            return['meta'=>$m[0],'super'=>$super,'trigger'=>$trigger,
                'eligible'=>$trigger&&($super||(string)$m[0]['binlog']==='0'||(string)$m[0]['trust_creators']==='1')];
        }finally{if($p->inTransaction())$p->rollBack();}
    }
    private static function target(array $m):string
    {return self::json(array_intersect_key($m,array_flip(['db','host','port','server_id','version'])));}
    public static function existingAdmin(PDO $app,iterable $factories,array &$report):PDO
    {
        $base=self::metadata($app);
        $report=['application'=>['authenticated'=>true,'visible_super'=>$base['super'],'visible_trigger'=>$base['trigger'],'eligible'=>$base['eligible']]];
        if($base['eligible'])return$app;
        foreach($factories as$name=>$factory){
            try{
                $p=$factory($base['meta']);$m=self::metadata($p);
                $same=self::target($base['meta'])===self::target($m['meta']);
                $report[$name]=['authenticated'=>true,'same_application_database'=>$same,'visible_super'=>$m['super'],'visible_trigger'=>$m['trigger'],'eligible'=>$same&&$m['eligible']];
                if($same&&$m['eligible'])return$p;
            }catch(Throwable$e){$report[$name]=['authenticated'=>false,'reason'=>'existing_provider_unavailable'];}
        }
        throw new HotelExistingAdminUnavailable($report);
    }
    /** Reads only a pre-existing private client profile, solely for same-target login. */
    public static function profileConnection(string $path,array $context,array $target,callable $open):PDO
    {
        if(!in_array($path,$context['profile_paths']??[],true))throw new RuntimeException('maintenance_profile_not_allowed');
        $s=@lstat($path);
        if(!$s||($s['mode']&0170000)!==0100000||!in_array($s['uid'],[0,$context['euid']],true)||($s['mode']&0077)!==0||!is_readable($path))
            throw new RuntimeException('maintenance_profile_not_trusted');
        $parsed=@parse_ini_file($path,true,INI_SCANNER_RAW);$c=is_array($parsed)?($parsed['client']??null):null;
        if(!is_array($c)||!is_string($c['user']??null)||$c['user']==='')throw new RuntimeException('maintenance_profile_not_supported');
        $local=['localhost','127.0.0.1','::1'];$host=$context['app_host'];
        if(isset($c['host'])){
            if($c['host']!==$host&&!(in_array($host,$local,true)&&in_array($c['host'],$local,true)))throw new RuntimeException('maintenance_target_mismatch');
        }elseif(!in_array($host,$local,true))throw new RuntimeException('maintenance_target_unknown');
        if(isset($c['port'])&&(string)$c['port']!==(string)$target['port'])throw new RuntimeException('maintenance_target_mismatch');
        if(isset($c['socket'])&&(!in_array($host,$local,true)||$c['socket']!==$target['socket']))throw new RuntimeException('maintenance_target_mismatch');
        if(!is_string($c['password']??''))throw new RuntimeException('maintenance_profile_not_supported');
        return$open($c['user'],$c['password']??'');
    }
    public static function triggerNames():array
    {
        $n=[];foreach(HotelWorkRetirement::TABLES as$i=>$t)foreach(['insert','update','delete']as$op)$n[]='hotel_retired_'.$i.'_'.$op;
        return[...$n,'hotel_retired_lane_insert','hotel_retired_lane_update','hotel_retirement_immutable_update','hotel_retirement_immutable_delete'];
    }
    public static function triggerCount(PDO $p):int
    {
        if($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
            $q=$p->query("SELECT name FROM sqlite_master WHERE type='trigger'");
        }else $q=$p->query('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()');
        return count(array_intersect(self::triggerNames(),$q->fetchAll(PDO::FETCH_COLUMN)));
    }
    public static function migrate083(PDO $app,iterable $factories,callable $runnerFactory,string $sqlPath):array
    {
        if(!hash_equals(self::MIGRATION_SHA,hash_file('sha256',$sqlPath)))throw new RuntimeException('retirement083_source_mismatch');
        $status=$runnerFactory($app)->status();$pending=[];
        foreach($status as$r){if($r['applied']&&!$r['checksum_ok'])throw new RuntimeException('migration_checksum_mismatch');if(!$r['applied'])$pending[]=$r['version'];}
        if(!in_array(self::VERSION,$pending,true))return['migration083'=>'already_applied','admin_provider_used'=>false];
        if($pending!==[self::VERSION])throw new RuntimeException('retirement083_pending_set_mismatch');
        $report=[];$p=self::existingAdmin($app,$factories,$report);
        if(self::triggerCount($p)!==0)throw new RuntimeException('retirement083_partial_trigger_inventory');
        $result=$runnerFactory($p)->migrate();
        $executed=array_column($result['executed'],'version');
        if($executed!==[self::VERSION]||$result['pending']!==[])throw new RuntimeException('retirement083_journal_unconfirmed');
        foreach($runnerFactory($p)->status()as$r)if(!$r['applied']||!$r['checksum_ok'])throw new RuntimeException('retirement083_journal_unconfirmed');
        if(self::triggerCount($p)!==37)throw new RuntimeException('retirement083_barrier_unconfirmed');
        return['migration083'=>'applied','checksum'=>self::MIGRATION_SHA,'trigger_count'=>37,'existing_providers'=>$report,'new_privileges_granted'=>false,'global_settings_changed'=>false];
    }
    public static function policy():array
    {
        return['version'=>'retained-input-isolation-v1','backup_sha256'=>self::BACKUP_SHA,'backup_bytes'=>246123355,
            'custody'=>'Prior operator-verified private read-only snapshot outside hosted Library APIs; no Mac or archive access by this operation.',
            'operator_hash_receipt_utc'=>'2026-11-01T15:05:22.967454+00:00',
            'old_queue'=>self::QUEUE,'old_mutable_Library_inputs_admissible'=>false,'old_queue_results_admissible_after_fence'=>false,
            'future_initial_input'=>'Only exact bytes matching the retained archive hash and size',
            'future_authorized_queue_ids'=>[],'new_start_allowed'=>false,'native_terminal_asserted'=>false,'global_Library_revocation_asserted'=>false,
            'external_effect_scope'=>'Old native/Library/callback effects on the retained base and admitted results are fenced, not globally stopped.',
            'server_callback_scope'=>'Late writes to the old queue and its receipts/events/audits are fenced by this same atomic retirement transaction, not by an invented prior marker.'];
    }
    private static function rows(PDO$p,string$table,string$queue):array
    {
        $s=$p->prepare('SELECT * FROM '.$table.' WHERE queue_id=?');$s->execute([$queue]);return$s->fetchAll(PDO::FETCH_ASSOC);
    }
    public static function verifyRetired(PDO$p,string$queue=self::QUEUE):array
    {
        $a=self::rows($p,'hotel_work_retirements',$queue);
        if(count($a)!==1||!hash_equals($a[0]['original_state_sha256'],hash('sha256',$a[0]['snapshot_bytes'])))throw new RuntimeException('retirement_readback_unconfirmed');
        $snapshot=json_decode($a[0]['snapshot_bytes'],true,64,JSON_THROW_ON_ERROR);$receipt=json_decode($a[0]['receipt_bytes'],true,32,JSON_THROW_ON_ERROR);
        $q=self::rows($p,'hotel_work_queues',$queue);$before=$snapshot['hotel_work_queues'][0]??[];
        if(($before['queue_id']??null)!==$queue||!in_array($before['state']??null,['claimed','unknown'],true)||($receipt['queue_id']??null)!==$queue||($receipt['state']??null)!=='retired')throw new RuntimeException('retirement_readback_unconfirmed');
        if(count($q)!==1||$q[0]['state']!=='retired'||(int)$q[0]['stage_index']!==41||(int)$q[0]['accepted_batches']!==19)throw new RuntimeException('retirement_readback_unconfirmed');
        foreach($before as$k=>$v)if(!in_array($k,['state','updated_at'],true)&&$q[0][$k]!==$v)throw new RuntimeException('retirement_original_queue_changed');
        $now=self::rows($p,'hotel_work_receipts',$queue);$old=$snapshot['hotel_work_receipts']??[];
        $normalize=static function(array$x):array{$b=array_map(static fn($r)=>self::json($r),$x);sort($b,SORT_STRING);return$b;};
        if($normalize($now)!==$normalize($old))throw new RuntimeException('retirement_original_receipts_changed');
        foreach($now as$r)if(!hash_equals($r['receipt_sha256'],hash('sha256',$r['receipt_json'])))throw new RuntimeException('retirement_original_receipt_hash_mismatch');
        if(($receipt['original_operation_outcome']??null)!=='UNKNOWN'||($receipt['acceptance_credit']??null)!==0||($receipt['backup_sha256']??null)!==self::BACKUP_SHA||self::triggerCount($p)!==37)throw new RuntimeException('retirement_readback_unconfirmed');
        $s=$p->prepare('SELECT COUNT(*) FROM hotel_work_events WHERE queue_id=? AND project_key=? AND (is_active<>0 OR pending_event_id IS NOT NULL OR pending_body IS NOT NULL OR lease_token IS NOT NULL OR lease_until IS NOT NULL)');$s->execute([$queue,'anytour']);
        if((int)$s->fetchColumn()!==0)throw new RuntimeException('retirement_delivery_not_fenced');
        $s=$p->prepare('SELECT queue_id FROM hotel_work_lane WHERE lane_key=?');$s->execute(['HOTEL']);$lane=$s->fetchColumn();
        if($lane===$queue)throw new RuntimeException('retirement_lane_not_released');
        return['queue_id'=>$queue,'state'=>'retired','CENTRAL41'=>'UNKNOWN','accepted_batches'=>19,'acceptance_credit'=>0,
            'receipt_sha256'=>hash('sha256',$a[0]['receipt_bytes']),'snapshot_sha256'=>$a[0]['original_state_sha256'],
            'backup_sha256'=>self::BACKUP_SHA,'original_receipts_preserved'=>true,'old_subscriptions_inactive'=>true,
            'lane_free'=>$lane===null,'new_start_allowed'=>false,'new_queue_created'=>false,'native_terminal_verified'=>false,'retirement_created_at'=>$a[0]['created_at']];
    }
    public static function retireOld(PDO$p,string$directory,callable$authorize,?callable$fault=null,string$queue=self::QUEUE,int$admin=2):array
    {
        $q=self::rows($p,'hotel_work_queues',$queue);if($q===[])return['old_queue'=>'absent','new_start_allowed'=>false];
        if(self::rows($p,'hotel_work_retirements',$queue)!==[])return self::verifyRetired($p,$queue)+['already_recorded'=>true];
        if((int)$q[0]['admin_id']!==$admin||(int)$q[0]['stage_index']!==41||(int)$q[0]['accepted_batches']!==19||!in_array($q[0]['state'],['claimed','unknown'],true))throw new RuntimeException('retirement_original_state_mismatch');
        if(!is_dir($directory)&&!mkdir($directory,0700,true))throw new RuntimeException('retirement_private_intent_unavailable');
        $st=lstat($directory);$uid=function_exists('posix_geteuid')?posix_geteuid():$st['uid'];
        if(($st['mode']&0170000)!==0040000||($st['mode']&0077)!==0||$st['uid']!==$uid)throw new RuntimeException('retirement_private_intent_unavailable');
        $lock=fopen($directory.'/operation.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('retirement_operation_busy');
        try{
            $service=new HotelWorkRetirement($p,$admin,$authorize);
            $path=$directory.'/original-request.json';
            if(is_file($path)){
                $st=lstat($path);if(($st['mode']&0170000)!==0100000||($st['mode']&0077)!==0||$st['uid']!==$uid||$st['size']>16384)throw new RuntimeException('retirement_private_intent_unavailable');
                $intent=json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
            }else{
                $pre=$service->preflight($queue,$q[0]['oauth_connection_id']);
                if(!$pre['eligible']||$pre['already_retired']||$pre['stage_index']!==41||$pre['accepted_batches']!==19)throw new RuntimeException('retirement_preflight_mismatch');
                $policy=self::policy();$proof=hash('sha256',self::json($policy));
                $intent=['queue'=>$queue,'owner'=>$q[0]['oauth_connection_id'],'expected'=>$pre['expected_state_sha256'],'request'=>self::REQUEST,
                    'policy'=>$policy,'backup'=>['reference'=>'operator-private-snapshot:AnyTour_hotels_backup_20261101.zip','sha256'=>self::BACKUP_SHA,'bytes'=>246123355,'verified_by_operator'=>true],
                    'isolation'=>['native_writers_stopped_or_fenced'=>true,'library_writers_stopped_or_fenced'=>true,'pending_callbacks_fenced'=>true,
                        'scope'=>'retained-input-isolation-v1','evidence_sha256'=>$proof,'new_start_allowed'=>false,'native_terminal_asserted'=>false,'global_Library_revocation_asserted'=>false]];
                $bytes=self::json($intent);$tmp=$path.'.tmp';$f=@fopen($tmp,'x');if(!$f)throw new RuntimeException('retirement_private_intent_unavailable');
                try{
                    if(!chmod($tmp,0600)||fwrite($f,$bytes)!==strlen($bytes)||!fflush($f)||!function_exists('fsync')||!fsync($f))throw new RuntimeException('retirement_private_intent_unavailable');
                }finally{fclose($f);}
                if(!rename($tmp,$path)||(string)file_get_contents($path)!==$bytes)throw new RuntimeException('retirement_private_intent_unavailable');
            }
            $policy=self::policy();
            if(($intent['queue']??null)!==$queue||($intent['request']??null)!==self::REQUEST||($intent['backup']['sha256']??null)!==self::BACKUP_SHA||($intent['isolation']['evidence_sha256']??null)!==hash('sha256',self::json($policy))||self::json($intent['policy']??null)!==self::json($policy))throw new RuntimeException('retirement_intent_conflict');
            if($fault!==null)$fault('intent_persisted');
            $service->retire($queue,$intent['owner'],$intent['expected'],$intent['request'],$intent['backup'],$intent['isolation']);
            if($fault!==null)$fault('retirement_committed');
            return self::verifyRetired($p,$queue);
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    private static function json(mixed$x):string
    {
        $sort=function(mixed$v)use(&$sort):mixed{if(!is_array($v))return$v;if(!array_is_list($v))ksort($v,SORT_STRING);foreach($v as$k=>$n)$v[$k]=$sort($n);return$v;};
        return json_encode($sort($x),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }
}
