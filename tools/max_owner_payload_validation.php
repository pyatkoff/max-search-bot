<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
ini_set('display_errors','0');ini_set('log_errors','0');

// ':' makes this fixed sentinel invalid as a MAX message ID. No real IDs are accepted.
const OWNER_PAYLOAD_SENTINEL='INVALID-MID:ANYTOUR-PAYLOAD-VALIDATION-20261021';

function payloadValidationMessage($value,string $token): ?string
{
    if (!is_string($value)) return null;
    if ($token!=='') $value=str_replace($token,'[redacted-token]',$value);
    $value=preg_replace('~(?:[a-z][a-z0-9+.-]{1,15}://|www\.)[^\s<>"\x27]+~iu','[redacted-url]',$value);
    $value=preg_replace('/[0-9]{5,}/','[redacted-number]',$value);
    $value=preg_replace('/[\p{C}]+/u',' ',$value);
    if (!is_string($value)) return null;
    // Never export an echoed JSON/body, HTML page or a source dump inside message.
    $source=strpos($value,'[Source:');
    if ($source!==false) $value=substr($value,0,$source).'[source omitted]';
    if (preg_match('~<[^>]+>|["\x27](?:text|attachments|payload|buttons)["\x27]\s*[:=]~u',$value)) return '[structured provider message omitted]';
    $value=substr($value,0,2048);
    while ($value!==''&&preg_match('//u',$value)!==1) $value=substr($value,0,-1);
    return $value;
}

function payloadValidationSummary(string $bytes,int $status,int $curl,int $tls,bool $overflow,string $token): array
{
    $data=json_decode($bytes,true,32,JSON_BIGINT_AS_STRING);
    $object=is_array($data)&&!array_is_list($data);$message=$object?($data['message']??null):null;
    $code=$object?($data['code']??null):null;
    return ['http_status'=>max(0,min(599,$status)),'curl_code'=>max(0,min(999,$curl)),
        'tls_verified'=>$tls===0,'response_complete'=>$curl===0&&!$overflow,'json_object'=>$object,
        'api_code'=>in_array($code,['proto.payload','proto.query','proto.auth','access.denied','chat.not.found','user.not.found','message.not.found','attachment.not.ready'],true)?$code:null,
        'message'=>payloadValidationMessage($message,$token),
        'message_sha256'=>is_string($message)?hash('sha256',$message):null];
}

/** GET and PUT only, both using the same fixed invalid sentinel; no real target/query inputs. */
function payloadValidationRequest(bool $edit,?array $body,string $token): array
{
    $url='https://platform-api2.max.ru'.($edit
        ? '/messages?'.http_build_query(['message_id'=>OWNER_PAYLOAD_SENTINEL])
        : '/messages/'.rawurlencode(OWNER_PAYLOAD_SENTINEL));
    $bytes='';$overflow=false;$ch=curl_init($url);
    $options=MaxTlsConfig::strictCurlOptions()+[
        CURLOPT_CUSTOMREQUEST=>$edit?'PUT':'GET',CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER=>['Authorization: '.$token,'Content-Type: application/json'],
        CURLOPT_WRITEFUNCTION=>static function($ch,string $part) use (&$bytes,&$overflow): int {
            if (strlen($bytes)+strlen($part)>65536) { $overflow=true;return 0; }
            $bytes.=$part;return strlen($part);
        }];
    if ($edit) $options[CURLOPT_POSTFIELDS]=json_encode($body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    curl_setopt_array($ch,$options);curl_exec($ch);
    $result=payloadValidationSummary($bytes,(int)curl_getinfo($ch,CURLINFO_HTTP_CODE),curl_errno($ch),(int)curl_getinfo($ch,CURLINFO_SSL_VERIFYRESULT),$overflow,$token);
    curl_close($ch);return $result;
}

function payloadValidationRun(callable $exchange,array $body,string $token): array
{
    $out=['state'=>'blocked','requests'=>1,'post_requests'=>0,'fixed_invalid_target'=>true];
    $out['probe_get']=$exchange(false,null,$token);
    $get=$out['probe_get'];
    if (!$get['response_complete']||!$get['tls_verified']||!$get['json_object']||!in_array($get['http_status'],[400,404],true)) return $out;
    $out['requests']=2;$out['probe_put']=$exchange(true,$body,$token);$put=$out['probe_put'];
    $out['state']=$put['response_complete']&&$put['tls_verified']
        ? ($put['http_status']===400?'payload_rejected':($put['http_status']===404?'target_rejected':($put['http_status']>=200&&$put['http_status']<300?'unexpected_put_success':'inconclusive')))
        : 'inconclusive';
    return $out;
}

if (($argv[1]??null)==='--self-test'&&count($argv)===2) {
    $checks=0;$check=static function(bool $ok)use(&$checks):void{$checks++;if(!$ok)throw new RuntimeException('offline check '.$checks);};
    $token='PRIVATE_FIXTURE_TOKEN';$message=payloadValidationMessage("Missing link $token https://private.invalid/path?token=x 123456789\n",$token);
    $check(!str_contains($message,$token)&&!str_contains($message,'private.invalid')&&!str_contains($message,'123456789'));
    $check(!str_contains($message,"\n")&&str_contains($message,'Missing link'));
    $long=payloadValidationMessage(str_repeat('Ж',3000),$token);$check(strlen($long)<=2048&&preg_match('//u',$long)===1);
    $check(payloadValidationMessage(['secret'=>'raw'],$token)===null);
    $check(payloadValidationMessage('bad {"text":"RAW_BODY","attachments":[]}',$token)==='[structured provider message omitted]');
    $check(!str_contains(payloadValidationMessage('bad [Source: RAW_BODY]',$token),'RAW_BODY'));
    $summary=payloadValidationSummary('{"code":"proto.payload","message":"Missing link","secret":"RAW_RESPONSE"}',400,0,0,false,$token);
    $check($summary['api_code']==='proto.payload'&&$summary['message']==='Missing link'&&!str_contains(json_encode($summary),'RAW_RESPONSE'));
    $check(payloadValidationSummary('{"code":"PRIVATE_FIXTURE_TOKEN","message":"x"}',400,0,0,false,$token)['api_code']===null);
    $body=['text'=>'fixture','attachments'=>[]];$calls=[];
    $exchange=static function(bool $edit,?array $received,string $secret)use(&$calls,$body,$summary):array{
        $calls[]=$edit?'PUT':'GET';if($edit&&$received!==$body)throw new RuntimeException('changed body');
        return array_replace($summary,['http_status'=>$edit?400:404]);};
    $run=payloadValidationRun($exchange,$body,$token);
    $check($calls===['GET','PUT']&&$run['requests']===2&&$run['post_requests']===0&&$run['state']==='payload_rejected');
    foreach ([['http_status'=>200],['curl_code'=>28,'response_complete'=>false],['response_complete'=>false],['json_object'=>false],['tls_verified'=>false]] as $change) {
        $calls=[];$run=payloadValidationRun(static function(bool $edit,?array $unused,string $unusedToken)use(&$calls,$summary,$change):array{$calls[]=$edit?'PUT':'GET';return array_replace($summary,$change);},$body,$token);
        $check($calls===['GET']&&$run['state']==='blocked');
    }
    echo json_encode(['offline_checks'=>$checks,'network_requests'=>0])."\n";exit;
}

$level=ob_get_level();ob_start();$out=['state'=>'configuration_unavailable','requests'=>0,'post_requests'=>0,'fixed_invalid_target'=>true];
try {
    if (count($argv)!==1||getcwd()!=='/var/www/anytoour/data/www/app.anytoour.ru') throw new RuntimeException('scope');
    $root=getcwd();define('MAX_OWNER_PREVIEW_OFFLINE_TEST',true);
    require_once $root.'/tools/max_owner_tour_preview.php';
    require_once $root.'/config.php';$project=require $root.'/project.php';
    $token=defined('MAX_SEARCH_TOKEN')?(string)MAX_SEARCH_TOKEN:'';
    if (!is_array($project)||($project['id']??null)!=='anytour'||$token===''||strlen($token)>4096||preg_match('/[\x00-\x20\x7f]/',$token)) throw new RuntimeException('project');
    $body=ownerPreviewBody();
    $out=payloadValidationRun('payloadValidationRequest',$body,$token);
    $out['request_body_sha256']=hash('sha256',json_encode($body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
} catch (Throwable $e) { $out['state']='inconclusive'; }
finally { while(ob_get_level()>$level)ob_end_clean(); }
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
