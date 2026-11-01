<?php
declare(strict_types=1);
// Explicit human authorization: apply pending083 with existing maintenance authority.
ini_set('display_errors','0');ini_set('log_errors','0');
$out=['operation'=>'migration083-maintenance-20261101-v1','ok'=>false,'migration_apply_attempts'=>0,'settings_changed'=>false,'application_grants_changed'=>false];
$lock=null;$maintenance=null;
function require083(bool $ok,string $reason):void{if(!$ok)throw new RuntimeException($reason);}
try {
    require083(PHP_SAPI==='cli'&&trim((string)shell_exec('git rev-parse HEAD'))===EXPECTED_INSTALLED_SHA,'installed_source_changed');
    ob_start();try{require getcwd().'/config.php';require_once getcwd().'/services/MigrationRunner.php';}finally{ob_end_clean();}
    $app=ConversationDb::connection();
    $version='083_hotel_work_retirement.sql';$file=getcwd().'/migrations/'.$version;
    require083(hash_file('sha256',$file)===MIGRATION_SHA256,'migration_content_changed');
    $journal=$app->query('SELECT version,checksum FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_KEY_PAIR);
    $pending=[];
    foreach(glob(getcwd().'/migrations/*.sql')?:[] as $path){$name=basename($path);if(isset($journal[$name]))require083(hash_equals($journal[$name],hash_file('sha256',$path)),'existing_checksum_mismatch');else $pending[]=$name;}
    $q=$app->prepare("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND (TRIGGER_NAME LIKE 'hotel_retired_%' OR TRIGGER_NAME LIKE 'hotel_retirement_immutable_%') ORDER BY TRIGGER_NAME");$q->execute();$triggers=$q->fetchAll(PDO::FETCH_COLUMN);
    preg_match_all('/CREATE TRIGGER ([A-Za-z0-9_]+)/',(string)file_get_contents($file),$matches);$expected=$matches[1];sort($expected);sort($triggers);
    if(isset($journal[$version])){require083($triggers===$expected,'journal_trigger_state_mismatch');$out+=['state'=>'already_applied_verified','trigger_count'=>count($triggers)];$out['ok']=true;}
    else {
        require083($pending===[$version],'pending_scope_changed');require083($triggers===[],'partial_trigger_installation_requires_reconciliation');
        require083((int)$app->query('SELECT COUNT(*) FROM hotel_work_retirements')->fetchColumn()===0,'retirement_state_changed');
        // Use only an existing documented project connection. No credential
        // search, new grants, global-variable changes or password output.
        // The project already documents this second connection in config.example.php.
        // Use it only for the same configured host and exact conversation schema;
        // no other database, filesystem credential lookup or guessed account.
        require083(defined('ANYTOUR_DATA_DB_HOST')&&defined('ANYTOUR_DATA_DB_USER')&&defined('ANYTOUR_DATA_DB_PASSWORD')&&trim((string)ANYTOUR_DATA_DB_USER)!=='','no_additional_configured_project_connection');
        require083((string)ANYTOUR_DATA_DB_HOST===(string)CONVERSATION_DB_HOST,'additional_project_connection_targets_different_host');
        require083((string)ANYTOUR_DATA_DB_USER!==(string)CONVERSATION_DB_USER,'additional_project_connection_same_restricted_account');
        $defaults=['host'=>(string)ANYTOUR_DATA_DB_HOST,'user'=>(string)ANYTOUR_DATA_DB_USER,'password'=>(string)ANYTOUR_DATA_DB_PASSWORD];
        $out['connection_source']='existing_documented_project_data_config_same_host';
        $database=(string)CONVERSATION_DB_NAME;require083(preg_match('/\A[A-Za-z0-9_-]+\z/',$database)===1,'database_identity_invalid');
        if(!empty($defaults['socket'])){require083(str_starts_with($defaults['socket'],'/')&&!str_contains($defaults['socket'],';'),'maintenance_socket_invalid');$dsn='mysql:unix_socket='.$defaults['socket'].';dbname='.$database.';charset=utf8mb4';}
        else{$host=$defaults['host']??'localhost';$port=$defaults['port']??'3306';require083(preg_match('/\A[A-Za-z0-9_.:-]+\z/',$host)===1&&ctype_digit($port),'maintenance_endpoint_invalid');$dsn='mysql:host='.$host.';port='.$port.';dbname='.$database.';charset=utf8mb4';}
        try{$maintenance=new PDO($dsn,$defaults['user'],$defaults['password']??'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>5]);}catch(Throwable $e){throw new RuntimeException('existing_maintenance_authentication_unavailable');}finally{unset($defaults,$dsn);}
        require083((string)$maintenance->query('SELECT @@server_uuid')->fetchColumn()===(string)$app->query('SELECT @@server_uuid')->fetchColumn(),'maintenance_server_mismatch');
        require083((string)$maintenance->query('SELECT DATABASE()')->fetchColumn()===$database,'maintenance_schema_mismatch');
        require083((string)$maintenance->query('SELECT CURRENT_USER()')->fetchColumn()!==(string)$app->query('SELECT CURRENT_USER()')->fetchColumn(),'separate_maintenance_authority_required');
        $grants=$maintenance->query('SHOW GRANTS FOR CURRENT_USER')->fetchAll(PDO::FETCH_COLUMN);$canSuper=false;
        foreach($grants as $grant)if(preg_match('/GRANT (?:ALL PRIVILEGES|[^\n]*\bSUPER\b[^\n]*) ON \*\.\*/i',$grant))$canSuper=true;unset($grants);
        require083($canSuper,'existing_maintenance_authority_insufficient');
        require083((int)$maintenance->query("SELECT GET_LOCK('anytour_exact_migration_083',0)")->fetchColumn()===1,'maintenance_operation_busy');
        $dir='/var/www/anytoour/data/migration-operations/083-maintenance-20261101';
        require083(!is_link(dirname($dir))&&!is_link($dir),'invalid_receipt_directory');
        if(!is_dir($dir))require083(mkdir($dir,0700,true),'receipt_directory_unavailable');
        require083(realpath($dir)===$dir&&!is_link($dir.'/receipt.json'),'invalid_receipt_path');
        $lock=fopen($dir.'/receipt.json','c+');require083(is_resource($lock)&&flock($lock,LOCK_EX|LOCK_NB),'maintenance_operation_busy');
        require083(stream_get_contents($lock)==='','previous_apply_requires_reconciliation');
        $receipt=json_encode(['operation'=>$out['operation'],'state'=>'apply_started','expected_sha'=>EXPECTED_INSTALLED_SHA,'migration_sha256'=>MIGRATION_SHA256],JSON_THROW_ON_ERROR);require083(fwrite($lock,$receipt)===strlen($receipt)&&fflush($lock),'receipt_write_failed');if(function_exists('fsync'))require083(fsync($lock),'receipt_write_failed');
        $out['migration_apply_attempts']=1;
        $result=(new MigrationRunner(getcwd().'/migrations',$maintenance))->migrate();
        require083($result['baselined']===[]&&$result['pending']===[]&&array_column($result['executed'],'version')===[$version],'migration_result_scope_mismatch');
        $q=$maintenance->prepare('SELECT checksum FROM schema_migrations WHERE version=?');$q->execute([$version]);require083($q->fetchColumn()===MIGRATION_SHA256,'migration_journal_readback_failed');
        $q=$maintenance->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND (TRIGGER_NAME LIKE 'hotel_retired_%' OR TRIGGER_NAME LIKE 'hotel_retirement_immutable_%') ORDER BY TRIGGER_NAME");$actual=$q->fetchAll(PDO::FETCH_COLUMN);sort($actual);require083($actual===$expected,'trigger_readback_failed');
        $out+=['state'=>'applied_and_journal_verified','trigger_count'=>count($actual),'pending'=>0];$out['ok']=true;
        rewind($lock);ftruncate($lock,0);$receipt=json_encode($out,JSON_THROW_ON_ERROR);require083(fwrite($lock,$receipt)===strlen($receipt)&&fflush($lock),'receipt_write_failed');if(function_exists('fsync'))require083(fsync($lock),'receipt_write_failed');
    }
}catch(Throwable $e){
    $known=['installed_source_changed','migration_content_changed','existing_checksum_mismatch','journal_trigger_state_mismatch','pending_scope_changed','partial_trigger_installation_requires_reconciliation','retirement_state_changed','no_additional_configured_project_connection','additional_project_connection_targets_different_host','additional_project_connection_same_restricted_account','existing_maintenance_profile_tool_unavailable','maintenance_profile_unavailable','existing_maintenance_profile_not_configured','database_identity_invalid','maintenance_socket_invalid','maintenance_endpoint_invalid','existing_maintenance_authentication_unavailable','maintenance_server_mismatch','maintenance_schema_mismatch','separate_maintenance_authority_required','existing_maintenance_authority_insufficient','maintenance_operation_busy','invalid_receipt_directory','receipt_directory_unavailable','invalid_receipt_path','previous_apply_requires_reconciliation','receipt_write_failed','migration_result_scope_mismatch','migration_journal_readback_failed','trigger_readback_failed'];
    $out['reason']=in_array($e->getMessage(),$known,true)?$e->getMessage():($out['migration_apply_attempts']?'migration_apply_outcome_requires_reconciliation':'bounded_maintenance_preflight_failed');
}
if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}
if($maintenance instanceof PDO){try{$maintenance->query("SELECT RELEASE_LOCK('anytour_exact_migration_083')");}catch(Throwable $ignored){}}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),PHP_EOL;exit($out['ok']?0:2);
