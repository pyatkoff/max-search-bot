<?php
declare(strict_types=1);
ini_set('display_errors','0');ini_set('log_errors','0');
$level=ob_get_level();ob_start(static fn(string $discard):string=>'');
$p=null;$locked=false;$invoked=false;$mask=null;$phase='bind';
$out=['operation'=>'hotel-retire-old-v1','full_deploy'=>false,'migration_invoked'=>false,
    'new_collection_invoked'=>false,'app_files_written'=>false,'settings_changed'=>false];
try{
    $root='/var/www/anytoour/data/www/app.anytoour.ru';
    $queue='HOTEL-CONTINUOUS-20261027';$owner='88998d4435eb05ce804131a2e4ece19b';
    if(PHP_SAPI!=='cli'||count($argv)!==1||realpath(getcwd())!==$root
        ||!function_exists('posix_geteuid')||posix_geteuid()!==1012)
        throw new RuntimeException('retirement_target_unconfirmed');
    require $root.'/config.php';
    require_once $root.'/services/ConversationDb.php';
    require_once $root.'/services/HotelRetirementServerOps.php';
    $sha='1d39818eb8841aa80d8427dab7cb82562b02e2d6665529e7b8515044d18562ae';
    if(HotelRetirementServerOps::QUEUE!==$queue
        ||!hash_equals($sha,hash_file('sha256',$root.'/migrations/083_hotel_work_retirement.sql')))
        throw new RuntimeException('retirement083_source_mismatch');
    $p=ConversationDb::connection();
    if($p->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'
        ||$p->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION||$p->inTransaction())
        throw new RuntimeException('retirement_connection_unconfirmed');
    $s=$p->prepare('SELECT DATABASE() AS db,CURRENT_USER() AS account,VERSION() AS version,@@version_comment AS comment,@@hostname AS host,@@port AS port,@@server_id AS server_id');
    if(!$s||!$s->execute())throw new RuntimeException('retirement_target_unconfirmed');
    $id=$s->fetch(PDO::FETCH_ASSOC);
    if($s->closeCursor()!==true)throw new RuntimeException('retirement_connection_unconfirmed');
    if(!is_array($id)||!hash_equals('9c5a356696060c37a9ac581c9849f8c53e38a29296eedee1c536f9ce794b3389',hash('sha256',json_encode($id,JSON_THROW_ON_ERROR))))
        throw new RuntimeException('retirement_target_unconfirmed');
    unset($id);$out['target_binding_confirmed']=true;
    if((string)$p->query("SELECT GET_LOCK('anytour.hotel-retirement-20261101',0)")->fetchColumn()!=='1')
        throw new RuntimeException('retirement_operation_busy');
    $locked=true;$phase='preflight';
    $s=$p->prepare('SELECT checksum,baseline FROM schema_migrations WHERE version=?');
    if(!$s||!$s->execute(['083_hotel_work_retirement.sql']))throw new RuntimeException('retirement083_journal_unconfirmed');
    $rows=$s->fetchAll(PDO::FETCH_ASSOC);$s->closeCursor();
    if(count($rows)!==1||!hash_equals($sha,(string)$rows[0]['checksum'])||(int)$rows[0]['baseline']!==0)
        throw new RuntimeException('retirement083_journal_unconfirmed');
    if(HotelRetirementServerOps::triggerCount($p)!==37)throw new RuntimeException('retirement083_barrier_unconfirmed');
    $out['migration083_verified']=true;
    $authorize=static function(PDO$db,int$admin)use($queue,$owner):bool{
        if($admin!==2)return false;
        $s=$db->prepare("SELECT 1 FROM managers m JOIN manager_projects mp ON mp.manager_id=m.id JOIN projects p ON p.id=mp.project_id WHERE m.id=? AND m.role='admin' AND m.is_active=1 AND p.project_key='anytour' AND p.is_active=1 FOR UPDATE");
        if(!$s||!$s->execute([$admin]))throw new RuntimeException('retirement_authorization_unconfirmed');
        if(!$s->fetchColumn())return false;
        // These rows are also locked by the canonical service inside each transaction.
        $s=$db->prepare("SELECT 1 FROM hotel_work_queues WHERE queue_id=? AND project_key='anytour' AND admin_id=? AND oauth_connection_id=? AND stage_index=41 AND accepted_batches=19 AND state IN ('claimed','unknown','retired') FOR UPDATE");
        if(!$s||!$s->execute([$queue,$admin,$owner]))throw new RuntimeException('retirement_authorization_unconfirmed');
        if(!$s->fetchColumn())return false;
        // Fail before mutation on any pre-existing receipt corruption.
        $s=$db->prepare('SELECT receipt_json,receipt_sha256 FROM hotel_work_receipts WHERE queue_id=? FOR UPDATE');
        if(!$s||!$s->execute([$queue]))throw new RuntimeException('retirement_authorization_unconfirmed');
        while(($r=$s->fetch(PDO::FETCH_ASSOC))!==false){
            if(!is_string($r['receipt_json']??null)||!is_string($r['receipt_sha256']??null)
                ||!hash_equals($r['receipt_sha256'],hash('sha256',$r['receipt_json'])))
                throw new RuntimeException('retirement_original_receipt_hash_mismatch');
        }
        if($s->closeCursor()!==true)throw new RuntimeException('retirement_authorization_unconfirmed');
        return true;
    };
    if(!$authorize($p,2))throw new RuntimeException('retirement_admin_owner_pin_unconfirmed');
    $out['admin_owner_pin_confirmed']=true;
    $mask=umask(0077);$phase='retire_old';$invoked=true;
    $r=HotelRetirementServerOps::retireOld($p,dirname($root,2).'/.hotel-retirement-20261101',$authorize);
    $phase='readback';
    if(($r['queue_id']??null)!==$queue||($r['state']??null)!=='retired'
        ||($r['CENTRAL41']??null)!=='UNKNOWN'||($r['accepted_batches']??null)!==19
        ||($r['acceptance_credit']??null)!==0||($r['original_receipts_preserved']??null)!==true
        ||($r['old_subscriptions_inactive']??null)!==true||($r['lane_free']??null)!==true
        ||($r['new_start_allowed']??null)!==false||($r['new_queue_created']??null)!==false
        ||($r['native_terminal_verified']??null)!==false)
        throw new RuntimeException('retirement_readback_unconfirmed');
    $out+=['state'=>'retired','readback'=>$r,'migration083_checksum'=>$sha];
}catch(Throwable$e){
    $allowed=['retirement_target_unconfirmed','retirement083_source_mismatch','retirement_connection_unconfirmed',
        'retirement083_journal_unconfirmed','retirement083_barrier_unconfirmed','retirement_operation_busy',
        'retirement_authorization_unconfirmed','retirement_admin_owner_pin_unconfirmed','retirement_original_receipt_hash_mismatch',
        'retirement_original_state_mismatch','retirement_private_intent_unavailable','retirement_preflight_mismatch',
        'retirement_intent_conflict','retirement_readback_unconfirmed','retirement_original_queue_changed',
        'retirement_original_receipts_changed','retirement_delivery_not_fenced','retirement_lane_not_released',
        'retirement_admin_required','retirement_owner_CAS','retirement_CAS_conflict','retirement_external_isolation_required',
        'retirement_database_barrier_required','retirement_current_lane_required','retirement_replay_conflict',
        'retirement_snapshot_limit','retirement_foreign_transaction'];
    $out['state']='HOLD';$out['phase']=$phase;
    $out['reason']=in_array($e->getMessage(),$allowed,true)?$e->getMessage():'retirement_operation_unconfirmed';
    $out['mysql_errno']=null;
    for($err=$e,$i=0;$err!==null&&$i<4;$err=$err->getPrevious(),++$i)
        if($err instanceof PDOException&&isset($err->errorInfo[1])
            &&preg_match('/^[0-9]{1,5}$/D',(string)$err->errorInfo[1])){$out['mysql_errno']=(int)$err->errorInfo[1];break;}
    $out['reconciliation_required']=$invoked;
}finally{
    if($mask!==null)umask($mask);
    if($locked&&$p instanceof PDO){try{$p->query("SELECT RELEASE_LOCK('anytour.hotel-retirement-20261101')");}catch(Throwable$ignored){}}
    while(ob_get_level()>$level)ob_end_clean();
}
$out['retirement_service_invoked']=$invoked;
echo json_encode($out,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
exit($out['state']==='retired'?0:1);
