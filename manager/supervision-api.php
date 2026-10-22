<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/config.php';
require_once __DIR__.'/lib/ManagerHttp.php';
require_once dirname(__DIR__).'/services/ManagerSupervisorAccessService.php';
require_once dirname(__DIR__).'/services/ManagerDialogueAnalyticsService.php';
function supervisionRespond(array $data,int $status=200): never {ManagerHttp::respond($data+['viewer_id'=>ManagerHttp::managerId()],$status);}
ManagerHttp::startJson();
if(($_SERVER['REQUEST_METHOD']??'')!=='POST')supervisionRespond(['ok'=>false,'error'=>'method_not_allowed'],405);
try {
    ProjectAccessService::initializeReadOnly();
    $manager=ManagerHttp::requireManager();$data=ManagerHttp::body();
    $actor=(int)$manager['id'];$action=$data['action']??'';
    if($action==='identity')supervisionRespond(['ok'=>true,'manager'=>array_intersect_key($manager,array_flip(['id','display_name','role'])),'csrf'=>ManagerHttp::csrf(true)]);
    ManagerHttp::requireCsrf($data);
    if($action==='scope'){
        $scopes=ManagerSupervisorAccessService::scopes($actor);$args=[];$predicate=ManagerSupervisorAccessService::predicate($scopes,'c',$args);
        $q=ConversationDb::connection()->prepare("SELECT DISTINCT m.id,COALESCE(m.display_name,m.login) AS name FROM conversations c JOIN managers m ON m.id=c.manager_id WHERE c.is_test=0 AND NOT ".InternalOwnerDialogueScope::conversationSql('c')." AND $predicate ORDER BY name,m.id");$q->execute($args);
        supervisionRespond(['ok'=>true,'scopes'=>$scopes,'managers'=>$q->fetchAll(PDO::FETCH_ASSOC)]);
    }
    if($action==='analytics'){
        session_write_close();$result=ManagerDialogueAnalyticsService::read($actor,$data);
        if(!session_start(['read_and_close'=>true])||ManagerHttp::managerId()!==$actor||!ManagerRequestContext::validCsrf((string)$data['csrf']))supervisionRespond(['ok'=>false,'error'=>'unauthorized'],401);
        supervisionRespond($result,!empty($result['ok'])?200:(($result['error']??'')==='forbidden'?403:(($result['error']??'')==='analytics_unavailable'?503:422)));
    }
    ManagerHttp::requireAdmin($manager);
    if($action==='directory'){
        $pdo=ConversationDb::connection();$projects=ProjectAccessService::projectsForManager($actor);$ids=array_map('intval',array_column($projects,'id'));
        if(!$ids)supervisionRespond(['ok'=>true,'projects'=>[],'managers'=>[],'sources'=>[],'grants'=>[]]);
        $in=implode(',',array_fill(0,count($ids),'?'));
        $q=$pdo->prepare("SELECT DISTINCT m.id,m.login,m.display_name,mp.project_id FROM managers m JOIN manager_projects mp ON mp.manager_id=m.id WHERE m.is_active=1 AND mp.project_id IN ($in) ORDER BY m.id,mp.project_id");$q->execute($ids);$accounts=$q->fetchAll(PDO::FETCH_ASSOC);
        $q=$pdo->prepare("SELECT id,project_id,display_name,channel FROM conversation_sources WHERE project_id IN ($in) AND is_active=1 ORDER BY id");$q->execute($ids);$sources=$q->fetchAll(PDO::FETCH_ASSOC);
        $q=$pdo->prepare("SELECT manager_id,project_id,enabled,include_unsourced,version FROM manager_supervision WHERE project_id IN ($in)");$q->execute($ids);$grants=$q->fetchAll(PDO::FETCH_ASSOC);
        $q=$pdo->prepare("SELECT manager_id,project_id,source_id FROM manager_supervision_sources WHERE project_id IN ($in)");$q->execute($ids);$bindings=$q->fetchAll(PDO::FETCH_ASSOC);
        foreach($grants as &$grant){$grant['source_ids']=[];foreach($bindings as $binding)if($binding['manager_id']==$grant['manager_id']&&$binding['project_id']==$grant['project_id'])$grant['source_ids'][]=(int)$binding['source_id'];}unset($grant);
        supervisionRespond(['ok'=>true,'projects'=>$projects,'managers'=>$accounts,'sources'=>$sources,'grants'=>$grants]);
    }
    if($action==='set'){
        foreach(['manager_id','project_id','expected_version'] as $key)if(!is_int($data[$key]??null))supervisionRespond(['ok'=>false,'error'=>'invalid_request'],422);
        foreach(['enabled','include_unsourced'] as $key)if(!is_bool($data[$key]??null))supervisionRespond(['ok'=>false,'error'=>'invalid_request'],422);
        if(!is_array($data['source_ids']??null))supervisionRespond(['ok'=>false,'error'=>'invalid_request'],422);
        $r=ManagerSupervisorAccessService::set($actor,$data['manager_id'],$data['project_id'],$data['source_ids'],$data['include_unsourced'],$data['enabled'],$data['expected_version']);
        supervisionRespond($r,!empty($r['ok'])?200:(($r['error']??'')==='access_conflict'?409:(in_array($r['error']??'',['forbidden','project_access_required','source_access_denied'],true)?403:503)));
    }
    supervisionRespond(['ok'=>false,'error'=>'unknown_action'],400);
}catch(Throwable $ignored){supervisionRespond(['ok'=>false,'error'=>'analytics_unavailable'],503);}
