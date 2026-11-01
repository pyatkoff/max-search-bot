<?php
declare(strict_types=1);
ini_set('display_errors','0');ini_set('log_errors','0');
$level=ob_get_level();ob_start(static fn(string $discard):string=>'');
$p=null;$locked=false;$phase='bind';$invoked=false;
$out=['operation'=>'hotel083-canonical-runner-v1','full_deploy'=>false,'retire_old_invoked'=>false,
    'new_collection_invoked'=>false,'app_files_written'=>false,'settings_changed'=>false];
try{
    $root='/var/www/anytoour/data/www/app.anytoour.ru';
    if(PHP_SAPI!=='cli'||count($argv)!==1||realpath(getcwd())!==$root
        ||!function_exists('posix_geteuid')||posix_geteuid()!==1012)
        throw new RuntimeException('migration083_target_unconfirmed');
    require $root.'/config.php';
    require_once $root.'/services/ConversationDb.php';
    require_once $root.'/services/HotelRetirementServerOps.php';
    $file=$root.'/migrations/083_hotel_work_retirement.sql';
    $sha='1d39818eb8841aa80d8427dab7cb82562b02e2d6665529e7b8515044d18562ae';
    if(!hash_equals($sha,hash_file('sha256',$file)))throw new RuntimeException('migration083_source_mismatch');
    $p=ConversationDb::connection();
    if($p->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'
        ||$p->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION||$p->inTransaction())
        throw new RuntimeException('migration083_connection_unconfirmed');
    $s=$p->prepare('SELECT DATABASE() AS db,CURRENT_USER() AS account,VERSION() AS version,@@version_comment AS comment,@@hostname AS host,@@port AS port,@@server_id AS server_id');
    if(!$s||!$s->execute())throw new RuntimeException('migration083_target_unconfirmed');
    $id=$s->fetch(PDO::FETCH_ASSOC);
    if($s->closeCursor()!==true)throw new RuntimeException('migration083_connection_unconfirmed');
    if(!is_array($id)||!hash_equals('9c5a356696060c37a9ac581c9849f8c53e38a29296eedee1c536f9ce794b3389',hash('sha256',json_encode($id,JSON_THROW_ON_ERROR))))
        throw new RuntimeException('migration083_target_unconfirmed');
    unset($id);$out['target_binding_confirmed']=true;
    $trust=$p->query('SELECT @@GLOBAL.log_bin_trust_function_creators')->fetchColumn();
    $out['trust_runtime_is_one']=(string)$trust==='1';
    if(!$out['trust_runtime_is_one'])throw new RuntimeException('migration083_runtime_setting_not_one');
    if((string)$p->query("SELECT GET_LOCK('anytour.hotel-retirement-20261101',0)")->fetchColumn()!=='1')
        throw new RuntimeException('migration083_operation_busy');
    $locked=true;$phase='preflight';
    $runner=new MigrationRunner($root.'/migrations',$p);
    $pending=[];
    foreach($runner->status()as$r){
        if($r['applied']&&!$r['checksum_ok'])throw new RuntimeException('migration083_journal_mismatch');
        if(!$r['applied'])$pending[]=$r['version'];
    }
    if($pending===[]){
        if(HotelRetirementServerOps::triggerCount($p)!==37)throw new RuntimeException('migration083_barrier_unconfirmed');
        $state='already_applied';
    }else{
        if($pending!==['083_hotel_work_retirement.sql'])throw new RuntimeException('migration083_pending_set_mismatch');
        if(HotelRetirementServerOps::triggerCount($p)!==0)throw new RuntimeException('migration083_partial_barriers');
        // Same installed MigrationRunner used by tools/conversation_db.php migrate.
        // MySQL itself enforces actual TRIGGER privileges; no grants or role changes.
        $phase='apply083';$invoked=true;$result=$runner->migrate();
        if($result['baselined']!==[]||array_column($result['executed'],'version')!==['083_hotel_work_retirement.sql']||$result['pending']!==[])
            throw new RuntimeException('migration083_execution_unconfirmed');
        $phase='readback';
        foreach($runner->status()as$r)if(!$r['applied']||!$r['checksum_ok'])throw new RuntimeException('migration083_journal_mismatch');
        if(HotelRetirementServerOps::triggerCount($p)!==37)throw new RuntimeException('migration083_barrier_unconfirmed');
        $state='applied';
    }
    $out+=['state'=>$state,'checksum'=>$sha,'trigger_count'=>37,'journal_readback_verified'=>true];
}catch(Throwable $e){
    $allowed=['migration083_target_unconfirmed','migration083_source_mismatch','migration083_runtime_setting_not_one',
        'migration083_operation_busy','migration083_connection_unconfirmed','migration083_journal_mismatch','migration083_pending_set_mismatch',
        'migration083_partial_barriers','migration083_execution_unconfirmed','migration083_barrier_unconfirmed'];
    $out['state']='HOLD';$out['phase']=$phase;
    $out['reason']=in_array($e->getMessage(),$allowed,true)?$e->getMessage():'migration083_operation_unconfirmed';
    $out['mysql_errno']=null;
    for($err=$e,$i=0;$err!==null&&$i<4;$err=$err->getPrevious(),++$i)
        if($err instanceof PDOException&&isset($err->errorInfo[1])
            &&preg_match('/^[0-9]{1,5}$/D',(string)$err->errorInfo[1])){$out['mysql_errno']=(int)$err->errorInfo[1];break;}
    $out['reconciliation_required']=$invoked;
}finally{
    if($locked&&$p instanceof PDO){try{$p->query("SELECT RELEASE_LOCK('anytour.hotel-retirement-20261101')");}catch(Throwable $ignored){}}
    while(ob_get_level()>$level)ob_end_clean();
}
$out['migration_runner_invoked']=$invoked;
echo json_encode($out,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
exit(in_array($out['state'],['applied','already_applied'],true)?0:1);
