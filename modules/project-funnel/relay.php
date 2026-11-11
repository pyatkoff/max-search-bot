<?php
declare(strict_types=1);
/** Deploy this identical file as /new/<project_key>/index.php on a branded miniapp host. */
header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');header('X-Content-Type-Options: nosniff');
$project=basename(dirname((string)($_SERVER['SCRIPT_FILENAME']??'')));
$method=$_SERVER['REQUEST_METHOD']??'GET';
$fail=static function(int $status):never{http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo '{"ok":false,"error":"temporarily_unavailable"}';exit;};
if(!preg_match('/\A[a-z][a-z0-9_]{0,39}\z/D',$project))$fail(404);
if(!in_array($method,['GET','POST'],true)){header('Allow: GET, POST');$fail(405);}
$url='https://app.anytoour.ru/project_funnel.php?project='.$project;
if($method==='GET'&&($_GET['format']??'')==='csv')$url.='&format=csv';
$body='';if($method==='POST'){$body=file_get_contents('php://input',false,null,0,32769);if(!is_string($body)||strlen($body)>32768)$fail(400);}
if(!function_exists('curl_init'))$fail(503);
$ch=curl_init($url);$response='';$tooLarge=false;
curl_setopt_array($ch,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>8,
    CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: text/html,application/json,text/csv'],
    CURLOPT_WRITEFUNCTION=>static function($ch,string $part)use(&$response,&$tooLarge):int{if(strlen($response)+strlen($part)>1048576){$tooLarge=true;return 0;}$response.=$part;return strlen($part);}]);
if($method==='POST')curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body]);
$ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$type=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);curl_close($ch);
if($ok===false||$tooLarge||!in_array($status,[200,400,404,503],true))$fail(503);
if(str_starts_with($type,'text/html')&&$method==='GET'){
    // The shared renderer/client remain on the application host. This relay
    // preserves the MAX webview origin and forwards no cookies or credentials.
    $response=str_replace('src="assets/project-funnel.js"','src="https://app.anytoour.ru/assets/project-funnel.js"',$response);
    header('Content-Type: text/html; charset=utf-8');
}elseif(str_starts_with($type,'application/json'))header('Content-Type: application/json; charset=utf-8');
elseif(str_starts_with($type,'text/csv')&&$method==='GET')header('Content-Type: text/csv; charset=utf-8');
else $fail(503);
http_response_code($status);echo $response;
