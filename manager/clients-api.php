<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/config.php';
require_once __DIR__.'/lib/ManagerHttp.php';
require_once dirname(__DIR__).'/services/ManagerClientOnboardingService.php';
ManagerHttp::startJson();
if(($_SERVER['REQUEST_METHOD']??'')!=='POST')ManagerHttp::respond(['ok'=>false,'error'=>'method_not_allowed'],405);
if((int)($_SERVER['CONTENT_LENGTH']??0)>250000)ManagerHttp::respond(['ok'=>false,'error'=>'request_too_large'],413);
try{ProjectAccessService::initializeReadOnly();}catch(Throwable $e){ManagerHttp::respond(['ok'=>false,'error'=>'storage_unavailable'],503);}
$manager=ManagerHttp::requireManager();$data=ManagerHttp::body();$action=$data['action']??'';
if($action==='me')ManagerHttp::respond(['ok'=>true,'manager'=>['id'=>(int)$manager['id'],'display_name'=>$manager['display_name'],'role'=>$manager['role']],'csrf'=>ManagerHttp::csrf(true)]);
ManagerHttp::requireCsrf($data);
if(($data['expected_actor']??null)!==(int)$manager['id'])ManagerHttp::respond(['ok'=>false,'error'=>'identity_changed'],409);
if(!in_array($action,['list','detail','create','configure','verify','activate','receipt','cancel'],true))ManagerHttp::respond(['ok'=>false,'error'=>'unknown_action'],400);
if(!in_array($action,['list','detail'],true))ManagerHttp::requireAdmin($manager);
$allowed=['action','csrf','expected_actor'];
if(in_array($action,['detail','configure','verify','activate'],true))$allowed[]='project_id';
if(in_array($action,['activate','receipt','cancel'],true))$allowed[]='operation_id';
if(in_array($action,['create','configure'],true))$allowed[]='client';
if(array_diff(array_keys($data),$allowed))ManagerHttp::respond(['ok'=>false,'error'=>'invalid_request'],422);
if(in_array($action,['activate','receipt','cancel'],true)&&!is_string($data['operation_id']??null))ManagerHttp::respond(['ok'=>false,'error'=>'invalid_operation'],422);
if(in_array($action,['detail','configure','verify','activate'],true)&&(!is_int($data['project_id']??null)||$data['project_id']<=0))ManagerHttp::respond(['ok'=>false,'error'=>'invalid_project'],422);
// Provider observation can be slow. Auth and CSRF stay with the canonical owner;
// no native session or SQL transaction is held across an external request.
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
try{
    $service=new ManagerClientOnboardingService(ProjectClientRegistry::current());$actor=(int)$manager['id'];
    $result=match($action){
        'list'=>$service->listing($actor),'detail'=>$service->detail($actor,$data['project_id']),
        'create'=>is_array($data['client']??null)?$service->create($actor,$data['client']):['ok'=>false,'error'=>'invalid_client'],
        'configure'=>is_array($data['client']??null)?$service->configure($actor,$data['project_id'],$data['client']):['ok'=>false,'error'=>'invalid_client'],
        'verify'=>$service->verify($actor,$data['project_id']),
        'activate'=>$service->activate($actor,$data['project_id'],(string)($data['operation_id']??'')),
        'receipt'=>$service->receipt($actor,(string)($data['operation_id']??'')),
        'cancel'=>$service->cancel($actor,(string)($data['operation_id']??'')),
    };
}catch(InvalidArgumentException $e){$result=['ok'=>false,'error'=>$e->getMessage()];}
catch(Throwable $e){error_log('CLIENT_ONBOARDING_FAILURE '.get_class($e));ManagerHttp::respond(['ok'=>false,'error'=>'storage_unavailable'],503);}
ManagerHttp::respond($result,!empty($result['ok'])?200:match($result['error']??''){'forbidden'=>403,'not_found'=>404,'storage_unavailable'=>503,default=>422});
