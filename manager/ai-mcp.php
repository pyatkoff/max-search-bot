<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/services/ManagerPreparationOAuth.php';
require_once dirname(__DIR__).'/services/ManagerPreparationMcpService.php';
require_once dirname(__DIR__).'/services/ManagerPreparationMcpDiagnostics.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$preparationMcpRpc=null;
$preparationMcpStage='origin';

function preparationMcpResponse(array $body, int $status=200): never
{
    global $preparationMcpRpc,$preparationMcpStage;
    ManagerPreparationMcpDiagnostics::record($_SERVER,$preparationMcpRpc,$status,$preparationMcpStage,$body['error']['code']??null);
    http_response_code($status);
    echo json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    exit;
}

try {
    $origin=(string)($_SERVER['HTTP_ORIGIN']??'');
    if ($origin!==''&&!in_array($origin,['https://chatgpt.com','https://chat.openai.com'],true)) {
        preparationMcpResponse(['jsonrpc'=>'2.0','id'=>null,'error'=>['code'=>-32001,'message'=>'Forbidden origin']],403);
    }
    $preparationMcpStage='credentials';
    $header=(string)($_SERVER['HTTP_AUTHORIZATION']??'');
    if ($header==='') $header=(string)($_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'');
    if ($header===''&&function_exists('getallheaders')) {
        foreach (getallheaders() as $name=>$value) {
            if (strcasecmp((string)$name,'Authorization')===0) {$header=(string)$value;break;}
        }
    }
    // Normalize only the real Authorization header supplied by the web server.
    // Never accept forwarded/custom headers or fall back from an invalid value.
    $_SERVER['HTTP_AUTHORIZATION']=$header;
    $bearer=preg_match('/\ABearer ([A-Za-z0-9_-]{32,512})\z/D',$header,$m)===1?$m[1]:'';
    $authorization=$bearer!==''?ManagerPreparationOAuth::bearerContext($header):null;
    $actor=$authorization['admin_id']??null;
    $connection=$authorization['connection_id']??null;
    if ($actor===null||$connection===null) {
        header('WWW-Authenticate: Bearer resource_metadata="https://app.anytoour.ru/manager/ai-oauth.php?action=resource", scope="queue:read prepared:write"');
        preparationMcpResponse(['jsonrpc'=>'2.0','id'=>null,'error'=>['code'=>-32001,'message'=>'OAuth connection required']],401);
    }
    $preparationMcpStage='http_method';
    if (($_SERVER['REQUEST_METHOD']??'')!=='POST') {
        header('Allow: POST');
        preparationMcpResponse(['jsonrpc'=>'2.0','id'=>null,'error'=>['code'=>-32600,'message'=>'POST required']],405);
    }
    $preparationMcpStage='body';
    if (isset($_SERVER['CONTENT_LENGTH'])&&(int)$_SERVER['CONTENT_LENGTH']>196608) {
        preparationMcpResponse(['jsonrpc'=>'2.0','id'=>null,'error'=>['code'=>-32600,'message'=>'Request too large']],413);
    }
    $raw=file_get_contents('php://input',false,null,0,196609);
    if (!is_string($raw)||strlen($raw)>196608) {
        preparationMcpResponse(['jsonrpc'=>'2.0','id'=>null,'error'=>['code'=>-32600,'message'=>'Request too large']],413);
    }
    try {$rpc=json_decode($raw,true,32,JSON_THROW_ON_ERROR);} catch(Throwable $ignored) {
        preparationMcpResponse(['jsonrpc'=>'2.0','id'=>null,'error'=>['code'=>-32700,'message'=>'Parse error']],400);
    }
    if (!is_array($rpc)||array_is_list($rpc)||($rpc['jsonrpc']??null)!=='2.0') {
        preparationMcpResponse(['jsonrpc'=>'2.0','id'=>null,'error'=>['code'=>-32600,'message'=>'Invalid request']],400);
    }
    $preparationMcpRpc=$rpc;
    $preparationMcpStage='rpc';
    if (!array_key_exists('id',$rpc)) {
        if (($rpc['method']??'')==='notifications/initialized') {
            ManagerPreparationMcpDiagnostics::record($_SERVER,$rpc,202,'complete',null);
            http_response_code(202);exit;
        }
        preparationMcpResponse(['jsonrpc'=>'2.0','id'=>null,'error'=>['code'=>-32600,'message'=>'Request ID required']],400);
    }
    if ((!is_int($rpc['id'])&&!is_string($rpc['id']))||(is_string($rpc['id'])&&strlen($rpc['id'])>128)) {
        preparationMcpResponse(['jsonrpc'=>'2.0','id'=>null,'error'=>['code'=>-32600,'message'=>'Invalid request ID']],400);
    }
    $response=(new ManagerPreparationMcpService())->handle($rpc,$actor,$connection,$authorization['scopes']);
    $preparationMcpStage='complete';
    preparationMcpResponse($response);
} catch(Throwable $ignored) {
    $preparationMcpStage='unavailable';
    // No OAuth material, transcript, model text, SQL or callback is logged.
    preparationMcpResponse(['jsonrpc'=>'2.0','id'=>null,'error'=>['code'=>-32603,'message'=>'Preparation service unavailable']],503);
}
