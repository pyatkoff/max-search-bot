<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors','0');ini_set('log_errors','0');
if(count($argv)!==2||!in_array($argv[1],['inspect','migrate083','retire-old'],true)){fwrite(STDERR,"Usage: php tools/hotel_retirement_ops.php inspect|migrate083|retire-old\n");exit(2);}
$root=dirname(__DIR__);$app=null;$locked=false;$code=0;
try{
    if(realpath($root)!==realpath('/var/www/anytoour/data/www/app.anytoour.ru'))throw new RuntimeException('retirement_server_scope_mismatch');
    $level=ob_get_level();ob_start();
    try{require $root.'/config.php';require_once $root.'/services/ProjectAccessService.php';require_once $root.'/services/HotelRetirementServerOps.php';}
    finally{while(ob_get_level()>$level)ob_end_clean();}
    ProjectAccessService::initializeReadOnly();if(ProjectConfig::projectId()!=='anytour')throw new RuntimeException('retirement_server_scope_mismatch');
    $app=ConversationDb::connection();$uid=function_exists('posix_geteuid')?posix_geteuid():null;
    $paths=['/root/.my.cnf','/etc/mysql/debian.cnf'];$account=$uid!==null&&function_exists('posix_getpwuid')?posix_getpwuid($uid):false;
    if(is_array($account)&&is_string($account['dir']??null)&&str_starts_with($account['dir'],'/'))$paths[]=rtrim($account['dir'],'/').'/.my.cnf';
    $paths=array_values(array_unique($paths));
    $context=['euid'=>$uid,'app_host'=>CONVERSATION_DB_HOST,'profile_paths'=>$paths];
    $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_STRINGIFY_FETCHES=>false,PDO::ATTR_TIMEOUT=>3];
    $dsn='mysql:host='.CONVERSATION_DB_HOST.';dbname='.CONVERSATION_DB_NAME.';charset=utf8mb4';
    $factories=[];
    if($uid===0&&in_array(CONVERSATION_DB_HOST,['localhost','127.0.0.1','::1'],true)){
        $factories['uid0_socket']=static function(array$m)use($options):PDO{
            $socket=$m['socket'];
            if(!is_string($socket)||!str_starts_with($socket,'/')||str_contains($socket,';')||str_contains($socket,"\0")||@filetype($socket)!=='socket')throw new RuntimeException('maintenance_socket_unavailable');
            return new PDO('mysql:unix_socket='.$socket.';dbname='.CONVERSATION_DB_NAME.';charset=utf8mb4','root','',$options);
        };
    }
    foreach($paths as$i=>$path)$factories['existing_profile_'.$i]=static fn(array$m):PDO=>HotelRetirementServerOps::profileConnection($path,$context,$m,static fn(string$user,string$pass):PDO=>new PDO($dsn,$user,$pass,$options));
    if($argv[1]==='inspect'){
        $report=[];HotelRetirementServerOps::existingAdmin($app,$factories,$report);
        $result=['read_only'=>true,'process_euid'=>$uid,'existing_providers'=>$report,'credentials_exposed'=>false,'new_start_allowed'=>false];
    }else{
        if((int)$app->query("SELECT GET_LOCK('anytour.hotel-retirement-20261101',0)")->fetchColumn()!==1)throw new RuntimeException('retirement_operation_busy');
        $locked=true;
        if($argv[1]==='migrate083'){
            $result=HotelRetirementServerOps::migrate083($app,$factories,static fn(PDO$p)=>new MigrationRunner($root.'/migrations',$p),$root.'/migrations/'.HotelRetirementServerOps::VERSION);
        }else{
            $q=$app->prepare('SELECT state FROM hotel_work_queues WHERE queue_id=?');$q->execute([HotelRetirementServerOps::QUEUE]);$state=$q->fetchColumn();
            if($state===false)$result=['old_queue'=>'absent','new_start_allowed'=>false];
            elseif($state==='retired'&&HotelRetirementServerOps::triggerCount($app)===37)$result=HotelRetirementServerOps::verifyRetired($app)+['already_recorded'=>true];
            else{
                $report=[];$p=HotelRetirementServerOps::existingAdmin($app,$factories,$report);
                $authorize=static function(PDO$db,int$id):bool{
                    $s=$db->prepare("SELECT 1 FROM managers m JOIN manager_projects mp ON mp.manager_id=m.id JOIN projects p ON p.id=mp.project_id WHERE m.id=? AND m.role='admin' AND m.is_active=1 AND p.project_key='anytour' AND p.is_active=1 FOR UPDATE");
                    $s->execute([$id]);return(bool)$s->fetchColumn();
                };
                $old=umask(0077);
                try{$result=HotelRetirementServerOps::retireOld($p,dirname($root,2).'/.hotel-retirement-20261101',$authorize);}
                finally{umask($old);}
            }
        }
    }
}catch(HotelExistingAdminUnavailable$e){
    $result=['state'=>'HOLD','reason'=>'existing_admin_context_unavailable','process_euid'=>function_exists('posix_geteuid')?posix_geteuid():null,
        'existing_providers'=>$e->report,'credentials_exposed'=>false,'new_start_allowed'=>false];$code=3;
}catch(Throwable$e){
    $allowed=['retirement_server_scope_mismatch','retirement083_source_mismatch','migration_checksum_mismatch','retirement083_pending_set_mismatch','retirement083_partial_trigger_inventory','retirement083_journal_unconfirmed','retirement083_barrier_unconfirmed',
        'retirement_operation_busy','retirement_original_state_mismatch','retirement_private_intent_unavailable','retirement_preflight_mismatch','retirement_intent_conflict',
        'retirement_readback_unconfirmed','retirement_original_queue_changed','retirement_original_receipts_changed','retirement_original_receipt_hash_mismatch','retirement_delivery_not_fenced','retirement_lane_not_released',
        'retirement_admin_required','retirement_owner_CAS','retirement_CAS_conflict','retirement_external_isolation_required','retirement_database_barrier_required','retirement_current_lane_required','retirement_replay_conflict'];
    $result=['state'=>'HOLD','reason'=>in_array($e->getMessage(),$allowed,true)?$e->getMessage():'retirement_server_operation_unavailable','credentials_exposed'=>false,'new_start_allowed'=>false];$code=1;
}finally{if($locked&&$app instanceof PDO){try{$app->query("SELECT RELEASE_LOCK('anytour.hotel-retirement-20261101')");}catch(Throwable$ignored){}}}
echo json_encode($result,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;exit($code);
