<?php
declare(strict_types=1);
ini_set('display_errors','0');
require_once dirname(__DIR__).'/config.php';
require_once __DIR__.'/lib/ManagerHttp.php';
require_once dirname(__DIR__).'/services/ModeledConversionValueRegistryView.php';

ManagerHttp::startJson();
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Vary: Cookie');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");

$view='members';$after='0';
try{
    // Must precede manager lookup: auth must not invoke the legacy project UPSERT.
    ProjectAccessService::initializeReadOnly();
    $manager=ManagerHttp::requireManager();
    ManagerHttp::requireAdmin($manager);
    $actor=(int)$manager['id'];
    if(($_SERVER['REQUEST_METHOD']??'')!=='GET')ManagerHttp::respond(['ok'=>false,'error'=>'method_not_allowed'],405);
    if($_POST||array_diff(array_keys($_GET),['view','after'])
        ||(isset($_GET['view'])&&(!is_string($_GET['view'])||!in_array($_GET['view'],['members','profiles'],true)))
        ||(isset($_GET['after'])&&(!is_string($_GET['after'])||preg_match('/\A(?:0|[1-9][0-9]{0,19})\z/D',$_GET['after'])!==1)))
        ManagerHttp::respond(['ok'=>false,'error'=>'unexpected_parameters'],422);
    $view=$_GET['view']??'members';$after=$_GET['after']??'0';
    if(ProjectConfig::projectId()!=='anytour')ManagerHttp::respond(['ok'=>false,'error'=>'project_unavailable'],404);
    if(!ProjectAccessService::canAccess($actor,'anytour'))ManagerHttp::respond(['ok'=>false,'error'=>'forbidden'],403);
    $pdo=ConversationDb::connection();
    if($pdo->inTransaction())throw new RuntimeException('auth_snapshot');
    session_write_close();

    $page=null;$unavailable=false;
    try{
        require_once dirname(__DIR__).'/services/ModeledConversionValueRegistry.php';
        $registry=new ModeledConversionValueRegistry($pdo);
        // One explicit bounded page. No retry, background refresh or full-cohort scan.
        if($view==='members'){
            require_once dirname(__DIR__).'/services/MaxTrackerLookupClient.php';
            $page=$registry->members((new MaxTrackerLookupClient())->currentMemberPage('98615635',$after));
        }else{
            $page=$registry->profiles($after,time());
        }
    }catch(Throwable $ignored){$page=null;$unavailable=true;}

    // Reopen the current session and recheck live authorization after the private read,
    // including a failed tracker read. Never return results to a changed/revoked actor.
    if($pdo->inTransaction())throw new RuntimeException('auth_snapshot');
    if(!session_start(['read_and_close'=>true])||ManagerHttp::managerId()!==$actor)
        ManagerHttp::respond(['ok'=>false,'error'=>'unauthorized'],401);
    $fresh=ManagerHttp::requireManager();
    ManagerHttp::requireAdmin($fresh);
    if((int)$fresh['id']!==$actor||ProjectConfig::projectId()!=='anytour'
        ||!ProjectAccessService::canAccess($actor,'anytour'))
        ManagerHttp::respond(['ok'=>false,'error'=>'forbidden'],403);

    http_response_code($unavailable?503:200);
    header('Content-Type: text/html; charset=utf-8');
    echo ModeledConversionValueRegistryView::render($view,$page,$after,$unavailable);
}catch(Throwable $ignored){
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo ModeledConversionValueRegistryView::render($view,null,$after,true);
}
