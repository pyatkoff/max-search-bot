<?php
// Run only in a child PHP -n process: no cURL extension, credentials or network.
foreach (['CURLOPT_RETURNTRANSFER','CURLOPT_CONNECTTIMEOUT','CURLOPT_TIMEOUT','CURLOPT_POST',
    'CURLOPT_HTTPHEADER','CURLOPT_POSTFIELDS','CURLINFO_HTTP_CODE'] as $i=>$name) define($name,$i+1);
define('OPENAI_API_KEY','SYNTHETIC_NEVER_SENT');
if (!function_exists('curl_init')) {
function curl_init($url) {if ($url!=='https://api.openai.com/v1/responses') throw new RuntimeException('wrong_endpoint');return new stdClass();}
function curl_setopt($h,$option,$value) {$GLOBALS['options'][$option]=$value;return true;}
function curl_exec($h) {
    $GLOBALS['calls'][]=json_decode($GLOBALS['options'][CURLOPT_POSTFIELDS],true);
    return json_encode(['output_text'=>'{"intent":"general_question","changes":[],"confidence":[],"parameters":[],"reply":"synthetic"}']);
}
function curl_errno($h) {return 0;}
function curl_error($h) {return '';}
function curl_getinfo($h,$option) {return 200;}
function curl_close($h) {}
}
if (isset($argv[1])) require $argv[1];
require dirname(__DIR__,2).'/ai/AiRouter.php';
require dirname(__DIR__,2).'/ai/TouristExtractorV2.php';
require dirname(__DIR__,2).'/ai/ManagerDraftClient.php';
$a=AiRouter::parseTourRequest('Synthetic request',[]);
$b=TouristExtractorV2::extract('Synthetic request',[]);
echo json_encode(['calls'=>$GLOBALS['calls'],'router_reply'=>$a['reply'],'extractor_intent'=>$b['intent'],
    'manager'=>ManagerDraftClient::requestBody('Synthetic',[]),'timeout'=>$GLOBALS['options'][CURLOPT_TIMEOUT]]);
