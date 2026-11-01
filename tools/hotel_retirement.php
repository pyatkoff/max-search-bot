<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(count($argv)!==3||!in_array($argv[1],['preflight','retire'],true)){fwrite(STDERR,"Usage: php tools/hotel_retirement.php preflight|retire PRIVATE_REQUEST.json\n");exit(2);}
try{
    require_once dirname(__DIR__).'/config.php';require_once dirname(__DIR__).'/services/ProjectAccessService.php';require_once dirname(__DIR__).'/services/HotelWorkRetirement.php';
    ProjectAccessService::initializeReadOnly();if(ProjectConfig::projectId()!=='anytour')throw new DomainException();
    if(!is_file($argv[2])||filesize($argv[2])>65536)throw new InvalidArgumentException();
    $a=json_decode((string)file_get_contents($argv[2]),true,32,JSON_THROW_ON_ERROR);
    $authorize=static function(PDO$p,int$id):bool{
        $s=$p->prepare("SELECT 1 FROM managers m JOIN manager_projects mp ON mp.manager_id=m.id JOIN projects p ON p.id=mp.project_id WHERE m.id=? AND m.role='admin' AND m.is_active=1 AND p.project_key='anytour' AND p.is_active=1".($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));$s->execute([$id]);return(bool)$s->fetchColumn();
    };
    $s=new HotelWorkRetirement(ConversationDb::connection(),$a['admin_id'],$authorize);
    $result=$argv[1]==='preflight'?$s->preflight($a['queue_id'],$a['expected_owner_connection_id']):$s->retire($a['queue_id'],$a['expected_owner_connection_id'],$a['expected_state_sha256'],$a['request_id'],$a['backup'],$a['isolation']);
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
}catch(Throwable$e){
    $safe=['retirement_admin_required','retirement_owner_CAS','retirement_backup_required','retirement_external_isolation_required','retirement_CAS_conflict','retirement_database_barrier_required','retirement_current_lane_required','retirement_replay_conflict','retirement_snapshot_limit','retirement_sha'];
    $reason=in_array($e->getMessage(),$safe,true)?$e->getMessage():'administrative_retirement_unavailable';
    fwrite(STDERR,json_encode(['state'=>'HOLD','reason'=>$reason,'dispatch_authority'=>false],JSON_THROW_ON_ERROR)."\n");exit(1);
}

