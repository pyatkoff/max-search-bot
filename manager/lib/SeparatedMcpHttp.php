<?php
declare(strict_types=1);
// Included only by the two fixed endpoint entrypoints. No request-selectable profile.
if (!isset($surfaceProfile)||!in_array($surfaceProfile,['replies','hotels'],true)) {http_response_code(404);exit;}
require_once dirname(__DIR__,2).'/services/ManagerPreparationOAuth.php';
require_once dirname(__DIR__,2).'/services/SeparatedMcpService.php';
require_once dirname(__DIR__,2).'/services/ManagerPreparationMcpDiagnostics.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$isolatedMcpRpc=null;
$isolatedMcpStage='origin';
function isolatedMcpResponse(array $body,int $status=200): never
{
    global $isolatedMcpRpc,$isolatedMcpStage,$surfaceProfile;
    ManagerPreparationMcpDiagnostics::record($_SERVER,$isolatedMcpRpc,$status,$isolatedMcpStage,$body['error']['code']??null,$surfaceProfile);
    http_response_code($status);echo json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);exit;
}
try {
    $origin=(string)($_SERVER['HTTP_ORIGIN']??'');
    if ($origin!==''&&!in_array($origin,['https://chatgpt.com','https://chat.openai.com'],true)) isolatedMcpResponse(['error'=>['code'=>-32001,'message'=>'Forbidden origin']],403);
    $isolatedMcpStage='credentials';
    require_once dirname(__DIR__,2).'/config.php';
    $header=(string)($_SERVER['HTTP_AUTHORIZATION']??'');
    if ($header==='') $header=(string)($_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'');
    if ($header===''&&function_exists('getallheaders')) foreach (getallheaders() as $name=>$value) {
        if (strcasecmp((string)$name,'Authorization')===0) {$header=(string)$value;break;}
    }
    // Record only the effective real header category; invalid direct values never fall back.
    $_SERVER['HTTP_AUTHORIZATION']=$header;
    $context=(new ManagerPreparationOAuth(null,null,$surfaceProfile))->contextForBearer($header);
    if ($context===null) {
        $definition=ManagerPreparationOAuth::profileDefinition($surfaceProfile);
        header('WWW-Authenticate: Bearer resource_metadata="'.$definition['endpoint'].'?action=resource", scope="'.implode(' ',$definition['scopes']).'"');
        isolatedMcpResponse(['jsonrpc'=>'2.0','id'=>null,'error'=>['code'=>-32001,'message'=>'OAuth connection required']],401);
    }
    $isolatedMcpStage='http_method';
    if (($_SERVER['REQUEST_METHOD']??'')!=='POST') {header('Allow: POST');isolatedMcpResponse(['error'=>['code'=>-32600,'message'=>'POST required']],405);}
    $isolatedMcpStage='body';
    if (isset($_SERVER['CONTENT_LENGTH'])&&(int)$_SERVER['CONTENT_LENGTH']>196608) isolatedMcpResponse(['error'=>['code'=>-32600,'message'=>'Request too large']],413);
    $raw=file_get_contents('php://input',false,null,0,196609);
    if (!is_string($raw)||strlen($raw)>196608) isolatedMcpResponse(['error'=>['code'=>-32600,'message'=>'Request too large']],413);
    try {$rpc=json_decode($raw,true,32,JSON_THROW_ON_ERROR);} catch(Throwable $error) {isolatedMcpResponse(['jsonrpc'=>'2.0','id'=>null,'error'=>['code'=>-32700,'message'=>'Parse error']],400);}
    if (!is_array($rpc)||array_is_list($rpc)||($rpc['jsonrpc']??null)!=='2.0') isolatedMcpResponse(['jsonrpc'=>'2.0','id'=>null,'error'=>['code'=>-32600,'message'=>'Invalid request']],400);
    $isolatedMcpRpc=$rpc;
    $isolatedMcpStage='rpc';
    if (!array_key_exists('id',$rpc)) {
        if (($rpc['method']??null)==='notifications/initialized') {
            ManagerPreparationMcpDiagnostics::record($_SERVER,$rpc,202,'complete',null,$surfaceProfile);
            http_response_code(202);exit;
        }
        isolatedMcpResponse(['jsonrpc'=>'2.0','id'=>null,'error'=>['code'=>-32600,'message'=>'Request ID required']],400);
    }
    if ((!is_int($rpc['id'])&&!is_string($rpc['id']))||(is_string($rpc['id'])&&strlen($rpc['id'])>128)) isolatedMcpResponse(['jsonrpc'=>'2.0','id'=>null,'error'=>['code'=>-32600,'message'=>'Invalid request ID']],400);
    $response=(new SeparatedMcpService($surfaceProfile))->handle($rpc,$context['admin_id'],$context['connection_id'],$context['scopes']);
    $isolatedMcpStage='complete';
    isolatedMcpResponse($response);
} catch(Throwable $error) {
    $isolatedMcpStage='unavailable';
    isolatedMcpResponse(['jsonrpc'=>'2.0','id'=>null,'error'=>['code'=>-32603,'message'=>'Separated service unavailable']],503);
}
