<?php
declare(strict_types=1);
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
require_once __DIR__.'/services/ConversationDb.php';
require_once __DIR__.'/services/ProjectFunnelEntry.php';
$method=$_SERVER['REQUEST_METHOD']??'GET';
$json=static function(int $status,array $data): never {
    http_response_code($status);header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);exit;
};
if(!in_array($method,['GET','POST'],true)){header('Allow: GET, POST');$json(405,['ok'=>false,'error'=>'method_not_allowed']);}
if(!is_file(__DIR__.'/config.php'))$json(503,['ok'=>false,'error'=>'not_configured']);
require_once __DIR__.'/config.php';
$project=$_GET['project']??null;
$connection=is_string($project)?ProjectMaxConnection::resolve($project):null;
$settings=$connection?ProjectFunnelSettings::load($connection):null;
if(!$settings)$json(404,['ok'=>false,'error'=>'project_unavailable']);
try{
    if($method==='POST'){
        $body=file_get_contents('php://input',false,null,0,32769);
        if(!is_string($body)||strlen($body)>32768)throw new InvalidArgumentException('invalid_request');
        $input=json_decode($body,true,8,JSON_THROW_ON_ERROR);
        if(!is_array($input)||array_is_list($input))throw new InvalidArgumentException('invalid_request');
        $result=(new ProjectFunnelEntry(ConversationDb::connection(),$connection,$settings))->capture($input,time());
        $json(200,$result);
    }
    if(($_GET['format']??'')==='csv'){
        $csv=(new ProjectFunnelEntry(ConversationDb::connection(),$connection,$settings))->feed();
        header('Content-Type: text/csv; charset=utf-8');echo $csv;exit;
    }
}catch(InvalidArgumentException|JsonException $e){$json(400,['ok'=>false,'error'=>'invalid_launch']);}
catch(Throwable $e){$json(503,['ok'=>false,'error'=>'temporarily_unavailable']);}
header('Content-Type: text/html; charset=utf-8');
$public=json_encode(ProjectFunnelSettings::publicClient($settings),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR);
$brand=htmlspecialchars($settings['brand'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
?>
<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="robots" content="noindex,nofollow"><title><?= $brand ?> — переход в MAX</title>
<style>html{color-scheme:light dark;font:17px/1.5 system-ui,sans-serif}body{margin:0;min-height:100svh;display:grid;place-items:center;background:#f3f5f8;color:#152238}main{box-sizing:border-box;width:min(100% - 32px,440px);padding:32px;border-radius:24px;background:#fff;box-shadow:0 12px 40px #1522380c}h1{font-size:24px;line-height:1.25;margin:16px 0 10px}p{color:#526078}.brand{font-weight:650;font-size:15px;color:#526078}.actions{display:grid;gap:12px;margin-top:24px}button,a{font:inherit;box-sizing:border-box;padding:14px 18px;border-radius:12px;text-align:center;text-decoration:none}button{border:1px solid #bdc8d8;background:transparent;color:inherit;cursor:pointer}a{background:#3168e0;color:#fff}[hidden]{display:none!important}@media(prefers-color-scheme:dark){body{background:#101723;color:#edf2fc}main{background:#192334}p,.brand{color:#b4c0d4}}</style>
<main aria-busy="true"><div class="brand"><?= $brand ?></div><div role="status" aria-live="polite"><h1 id="status-title">Открываем предложение</h1><p id="status-copy">Подготавливаем переход к публикации в MAX…</p></div><div class="actions"><a id="continue" hidden>Открыть публикацию</a><button id="retry" type="button" hidden>Повторить переход</button></div><noscript><p>Для перехода включите JavaScript и откройте ссылку внутри MAX.</p></noscript></main>
<script id="funnel-config" type="application/json"><?= $public ?></script><script src="assets/project-funnel.js" defer></script></html>
