<?php
declare(strict_types=1);
ini_set('display_errors','0');
require_once dirname(__DIR__).'/config.php';
require_once __DIR__.'/lib/ManagerHttp.php';
require_once dirname(__DIR__).'/services/ModeledConversionValueRegistryView.php';
require_once dirname(__DIR__).'/services/ModeledConversionDeliveryRegistryView.php';
require_once dirname(__DIR__).'/services/ModeledConversionAcquisitionStatsView.php';
require_once dirname(__DIR__).'/services/ModeledConversionDailyReconciliationView.php';
require_once dirname(__DIR__).'/services/ModeledConversionForecastPaymentView.php';
require_once dirname(__DIR__).'/services/MaxMemberHistoryView.php';

ManagerHttp::startJson();
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Vary: Cookie');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");

$view='delivery';$after='0';$deliveryInput=[];$acquisitionInput=[];$analyticsInput=[];$monitor=[];
try{
    // Must precede manager lookup: auth must not invoke the legacy project UPSERT.
    ProjectAccessService::initializeReadOnly();
    $manager=ManagerHttp::requireManager();
    ManagerHttp::requireAdmin($manager);
    $actor=(int)$manager['id'];
    if(($_SERVER['REQUEST_METHOD']??'')!=='GET')ManagerHttp::respond(['ok'=>false,'error'=>'method_not_allowed'],405);
    $view=$_GET['view']??'delivery';
    if(!is_string($view)||!in_array($view,['delivery','members','profiles','acquisition','reconciliation','forecast-payments'],true))
        ManagerHttp::respond(['ok'=>false,'error'=>'unexpected_parameters'],422);
    $allowed=match($view){'delivery'=>['view','date','platform','cursor'],'acquisition'=>['view','date','period','platform'],
        'reconciliation'=>['view','date','platform'],'forecast-payments'=>['view','date','period','platform','cursor'],default=>['view','after']};
    if($_POST||array_diff(array_keys($_GET),$allowed))
        ManagerHttp::respond(['ok'=>false,'error'=>'unexpected_parameters'],422);
    if($view==='delivery'){
        foreach(['date','platform','cursor']as$key)if(isset($_GET[$key])){
            if(!is_string($_GET[$key])||strlen($_GET[$key])>1024)
                ManagerHttp::respond(['ok'=>false,'error'=>'unexpected_parameters'],422);
            $deliveryInput[$key]=$_GET[$key];
        }
        $deliveryInput['date']=$deliveryInput['date']??(new DateTimeImmutable('now',new DateTimeZone('Europe/Kaliningrad')))->format('Y-m-d');
        $deliveryInput['platform']=$deliveryInput['platform']??'all';
    }elseif(in_array($view,['reconciliation','forecast-payments'],true)){
        foreach(array_diff($allowed,['view'])as$key)if(isset($_GET[$key])){
            if(!is_string($_GET[$key])||strlen($_GET[$key])>($key==='cursor'?1024:32))
                ManagerHttp::respond(['ok'=>false,'error'=>'unexpected_parameters'],422);
            $analyticsInput[$key]=$_GET[$key];
        }
        $analyticsInput['date']=$analyticsInput['date']??(new DateTimeImmutable('now',new DateTimeZone('Europe/Kaliningrad')))->format('Y-m-d');
        $analyticsInput['platform']=$analyticsInput['platform']??'all';
        if($view==='forecast-payments'){
            $analyticsInput['period']=$analyticsInput['period']??'30';
            if(!in_array($analyticsInput['period'],['7','30','90'],true))
                ManagerHttp::respond(['ok'=>false,'error'=>'unexpected_parameters'],422);
            $analyticsInput['period']=(int)$analyticsInput['period'];
        }
    }elseif($view==='acquisition'){
        foreach(['date','period','platform']as$key)if(isset($_GET[$key])){
            if(!is_string($_GET[$key])||strlen($_GET[$key])>32)
                ManagerHttp::respond(['ok'=>false,'error'=>'unexpected_parameters'],422);
            $acquisitionInput[$key]=$_GET[$key];
        }
        $acquisitionInput['date']=$acquisitionInput['date']??(new DateTimeImmutable('now',new DateTimeZone('Europe/Kaliningrad')))->format('Y-m-d');
        $acquisitionInput['period']=$acquisitionInput['period']??'30';
        if(!in_array($acquisitionInput['period'],['7','30','90'],true))
            ManagerHttp::respond(['ok'=>false,'error'=>'unexpected_parameters'],422);
        $acquisitionInput['period']=(int)$acquisitionInput['period'];
        $acquisitionInput['platform']=$acquisitionInput['platform']??'all';
    }elseif($view==='members'){
        if(isset($_GET['after'])&&(!is_string($_GET['after'])||!MaxMemberHistoryViewCursor::shape($_GET['after'])))
            ManagerHttp::respond(['ok'=>false,'error'=>'unexpected_parameters'],422);
        $after=$_GET['after']??'0';
    }else{
        if(isset($_GET['after'])&&(!is_string($_GET['after'])||preg_match('/\A(?:0|[1-9][0-9]{0,19})\z/D',$_GET['after'])!==1))
            ManagerHttp::respond(['ok'=>false,'error'=>'unexpected_parameters'],422);
        $after=$_GET['after']??'0';
    }
    if(ProjectConfig::projectId()!=='anytour')ManagerHttp::respond(['ok'=>false,'error'=>'project_unavailable'],404);
    if(!ProjectAccessService::canAccess($actor,'anytour'))ManagerHttp::respond(['ok'=>false,'error'=>'forbidden'],403);
    $pdo=ConversationDb::connection();
    if($pdo->inTransaction())throw new RuntimeException('auth_snapshot');
    $cursorKey=hash('sha256',session_id().'|modeled-delivery-registry-v1|'.$actor);
    // A session cookie is client-visible and cannot itself be a cursor signing secret.
    // Keep an independent random key in the existing private administrator session.
    $forecastCursorKey='';
    if($view==='forecast-payments'){
        $savedKey=$_SESSION['modeled_forecast_payment_cursor_key']??null;
        if(!is_string($savedKey)||preg_match('/\\A[a-f0-9]{64}\\z/D',$savedKey)!==1){
            $savedKey=bin2hex(random_bytes(32));
            $_SESSION['modeled_forecast_payment_cursor_key']=$savedKey;
        }
        $forecastCursorKey=hash_hmac('sha256','modeled-forecast-payment-v1|'.$actor,$savedKey);
    }
    $memberCursorBinding=session_id().'|max-history-owner-view-v1|'.$actor;
    session_write_close();

    $page=null;$unavailable=false;
    try{
        require_once dirname(__DIR__).'/services/ModeledConversionValueRegistry.php';
        if($view==='delivery'){
            require_once dirname(__DIR__).'/services/ModeledConversionDeliveryRegistry.php';
            require_once dirname(__DIR__).'/services/ModeledConversionDeliveryHealth.php';
            $authorize=static function()use($actor):void{
                $current=ManagerAuthService::byId($actor);
                if(!$current||!ManagerAuthService::isAdmin($current)
                    ||ProjectConfig::projectId()!=='anytour'||!ProjectAccessService::canAccess($actor,'anytour'))
                    throw new RuntimeException('delivery_registry_access');
            };
            $page=(new ModeledConversionDeliveryRegistry($pdo,$authorize,$cursorKey))->read($deliveryInput,time());
            $unavailable=($page['available']??false)!==true;
            $monitor=ModeledConversionDeliveryHealth::externalStatus(new ModeledConversionStatusBudget(),$authorize,time());
        }elseif(in_array($view,['reconciliation','forecast-payments'],true)){
            $authorize=static function()use($actor):void{
                $current=ManagerAuthService::byId($actor);
                if(!$current||!ManagerAuthService::isAdmin($current)
                    ||ProjectConfig::projectId()!=='anytour'||!ProjectAccessService::canAccess($actor,'anytour'))
                    throw new RuntimeException('conversion_analytics_access');
            };
            if($view==='reconciliation'){
                require_once dirname(__DIR__).'/services/ModeledConversionDailyReconciliationReader.php';
                $page=(new ModeledConversionDailyReconciliationReader($pdo,$authorize))->read($analyticsInput,time());
            }else{
                require_once dirname(__DIR__).'/services/ModeledConversionForecastPaymentReader.php';
                $page=(new ModeledConversionForecastPaymentReader($pdo,$authorize,$forecastCursorKey))->read($analyticsInput,time());
            }
            $unavailable=($page['available']??false)!==true;
        }elseif($view==='acquisition'){
            require_once dirname(__DIR__).'/services/ModeledConversionAcquisitionStatsReader.php';
            $authorize=static function()use($actor):void{
                $current=ManagerAuthService::byId($actor);
                if(!$current||!ManagerAuthService::isAdmin($current)
                    ||ProjectConfig::projectId()!=='anytour'||!ProjectAccessService::canAccess($actor,'anytour'))
                    throw new RuntimeException('acquisition_stats_access');
            };
            $page=(new ModeledConversionAcquisitionStatsReader($pdo,$authorize))->read($acquisitionInput,time());
            $unavailable=($page['available']??false)!==true;
        }else{
            $registry=new ModeledConversionValueRegistry($pdo);
            // Preserve the legacy, separately labelled research views.
            if($view==='members'){
                require_once dirname(__DIR__).'/services/MaxTrackerLookupClient.php';
                $memberCursorKey=hash_hmac('sha256',$memberCursorBinding,MaxTrackerLookupConfig::privateKey());
                $sourceAfter=MaxMemberHistoryViewCursor::unwrap($after,$memberCursorKey,time());
                $page=$registry->historyMembers((new MaxTrackerLookupClient())->historyMemberPage('98615635',$sourceAfter),$sourceAfter,$memberCursorKey,time());
            }else{
                $page=$registry->profiles($after,time());
            }
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
    echo $view==='reconciliation'
        ?ModeledConversionDailyReconciliationView::render($page,$analyticsInput,$unavailable)
        :($view==='forecast-payments'?ModeledConversionForecastPaymentView::render($page,$analyticsInput,$unavailable)
        :($view==='delivery'
        ?ModeledConversionDeliveryRegistryView::render($page,$deliveryInput,$monitor,$unavailable)
        :($view==='acquisition'?ModeledConversionAcquisitionStatsView::render($page,$acquisitionInput,$unavailable)
            :($view==='members'?MaxMemberHistoryView::render($page,$unavailable)
                :ModeledConversionValueRegistryView::render($view,$page,$after,$unavailable)))));
}catch(Throwable $ignored){
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo $view==='reconciliation'
        ?ModeledConversionDailyReconciliationView::render(null,$analyticsInput,true)
        :($view==='forecast-payments'?ModeledConversionForecastPaymentView::render(null,$analyticsInput,true)
        :($view==='delivery'
        ?ModeledConversionDeliveryRegistryView::render(null,$deliveryInput,[],true)
        :($view==='acquisition'?ModeledConversionAcquisitionStatsView::render(null,$acquisitionInput,true)
            :($view==='members'?MaxMemberHistoryView::render(null,true)
                :ModeledConversionValueRegistryView::render($view,null,$after,true)))));
}
