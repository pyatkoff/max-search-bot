<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/config.php';
require_once __DIR__.'/lib/ManagerHttp.php';
require_once dirname(__DIR__).'/services/ManagerCustomerAvatarService.php';
ManagerHttp::start();
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
ManagerHttp::requireManager();
if (($_SERVER['REQUEST_METHOD']??'')!=='GET') {header('Allow: GET');http_response_code(405);exit;}
$id=filter_var($_GET['conversation_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$size=$_GET['size']??'thumb';$index=$_GET['photo']??'0';
$scoped=array_key_exists('account',$_GET);$account=$_GET['account']??null;
$validAccount=!$scoped || (is_string($account) && preg_match('/\A[1-9][0-9]{0,18}\z/D',$account)
    && filter_var($account,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])===ManagerHttp::managerId());
$channelScoped=array_key_exists('channel',$_GET);$channel=$_GET['channel']??null;
$validChannel=!$channelScoped || (is_string($channel) && in_array($channel,['telegram','max'],true));
$valid=$validAccount && $validChannel && is_string($size) && in_array($size,['thumb','full'],true) && is_string($index) && preg_match('/\A(?:0|[1-9][0-9]{0,2})\z/D',$index) && ($size==='full' || $index==='0');
try {$photo=$valid && $id!==false && $id!==null?(new ManagerCustomerAvatarService())->avatar(ManagerHttp::managerId(),$id,$size,(int)$index,$channelScoped?$channel:null):null;}
catch (Throwable $e) {$photo=null;}
if (!$photo) {http_response_code(404);exit;}
// Account/channel URLs separate the private daily cache across manager logins
// and messenger identity changes. Legacy clients revalidate on every request.
// A stale conditional request also follows the full ACL/image validation path.
$etag='"'.hash('sha256',json_encode([ManagerHttp::managerId(),$id,$channelScoped?$channel:null,$size,(int)$index,$photo['mime'],$photo['photo_count']??1,$photo['limited']??false,hash('sha256',$photo['bytes'])],JSON_THROW_ON_ERROR)).'"';
$dailyScope=$scoped && $channelScoped;
if ($dailyScope) {header_remove('Pragma');header_remove('Expires');}
header('Cache-Control: '.($dailyScope?'private, max-age=86400, must-revalidate':'private, no-cache, must-revalidate'));
header('Vary: Cookie');
header('ETag: '.$etag);
// A 304 updates cached response metadata too. Keep its MIME identical to the
// validated image rather than allowing a PHP/default response type to replace it.
header('Content-Type: '.$photo['mime']);
if ($size==='full') {
    header('X-Avatar-Photo-Count: '.$photo['photo_count']);
    if ($photo['limited']) header('X-Avatar-Photo-Limited: 1');
}
// Honor a bounded HTTP list of strong/weak validators; malformed input cannot
// create an unbounded parse or make an unrelated account's validator match.
$condition=(string)($_SERVER['HTTP_IF_NONE_MATCH']??'');
if (strlen($condition)<=8192) foreach (explode(',',$condition) as $candidate) {
    $candidate=trim($candidate);
    if (str_starts_with($candidate,'W/')) $candidate=substr($candidate,2);
    if ($candidate===$etag || $candidate==='*') {http_response_code(304);exit;}
}
header('Content-Length: '.strlen($photo['bytes']));
echo $photo['bytes'];
