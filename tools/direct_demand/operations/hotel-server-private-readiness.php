<?php
declare(strict_types=1);
/* One same-owner final-stage correction through the existing private channel.
 * No tick/provider/history scan, next campaign, runtime/config/scheduler write.
 * The separate correction never replaces the original negative provider review.
 */
const HOTEL_EXPECTED_INSTALLED_SHA='c71a6278edb388ba1597e65af12d532729b05b53';
const HOTEL_OPERATION='1722ac83d29680932681969e8751b634571fd19613193e3541ed43f49ea79fd0';
const HOTEL_CAMPAIGN='HOTEL-SERVER-20261103-B03';
const HOTEL_CORRECTION_SHA='e781a9c736316e43a940ccddd8b422c6c9970a7e81bc00405190300c6c42cd56';
const HOTEL_CAPSULE_SHA='6e94ea99e0d5f486b462bbf0885996a0375af243bb0ab76c972cec1f6bbaf5bb';
const HOTEL_INPUT_RECIPIENT_SHA='137da1e625bcd005e4db69335781f0396fb44995b9668e1b35111f2307810388';
const HOTEL_EXPECTED_RUNTIME_SHA='458f612752afba2debf9e7b7e66dfe695977c1a9726a6b9a0bc36896bbc1b017';
const HOTEL_EXPECTED_BOOTSTRAP_SHA='bfe4ac73ad393cfd990654a5dccf9cb37fb5f040d327e3aa6732c0e7756b5aad';
const HOTEL_STATUS_KIND='hotel-server-private-correction-v1';
const HOTEL_RECEIPT_RECIPIENT_SHA='71f2b177d6baa924fa466765027c37f51a00f23f803a6d900060bf42944d237d';
ini_set('display_errors','0');ini_set('log_errors','0');
$level=ob_get_level();ob_start(static fn(string $ignored):string=>'');$pdo=null;$lock=null;
$report=['schema'=>HOTEL_STATUS_KIND,'operation_id'=>HOTEL_OPERATION,'campaign_id'=>HOTEL_CAMPAIGN,
    'correction_sha256'=>HOTEL_CORRECTION_SHA,'state'=>'HOLD','operator_provider_calls'=>0,
    'database_mutation_started'=>false,'correction_committed'=>false,'mutation_outcome'=>'not_started',
    'operator_runtime_config_writes'=>0,'operator_health_payload_writes'=>0,
    'runtime_config_changed'=>false,'cron_changed'=>false,'new_campaign_started'=>false];

function hotelFail(string $reason): never { throw new DomainException($reason); }

function hotelKeys(array $value,array $expected): void {
    $keys=array_keys($value);sort($keys);sort($expected);if($keys!==$expected)hotelFail('private_shape_unconfirmed');
}

function hotelDir(string $path): void {
    clearstatcache(true,$path);
    if(is_link($path)||realpath($path)!==$path||!is_dir($path)||(fileperms($path)&0777)!==0700
        ||fileowner($path)!==posix_geteuid())hotelFail('private_directory_unconfirmed');
}

function hotelRead(string $path,int $limit,?int $size=null,?string $sha=null): string {
    clearstatcache(true,$path);$before=lstat($path);
    if(!is_array($before)||is_link($path)||!is_file($path)||($before['mode']&0777)!==0600
        ||$before['uid']!==posix_geteuid()||$before['size']<0||($before['size']===0&&$size!==0)||$before['size']>$limit
        ||($size!==null&&$before['size']!==$size))hotelFail('private_file_unconfirmed');
    $h=fopen($path,'rb');if(!$h)hotelFail('private_file_unconfirmed');
    try{$opened=fstat($h);if($opened['dev']!==$before['dev']||$opened['ino']!==$before['ino'])hotelFail('private_file_changed');
        $raw=stream_get_contents($h,$limit+1);}finally{fclose($h);}
    clearstatcache(true,$path);$after=lstat($path);
    if(!is_string($raw)||strlen($raw)!==$before['size']||$before['dev']!==$after['dev']
        ||$before['ino']!==$after['ino']||$before['mtime']!==$after['mtime']||$before['size']!==$after['size']
        ||($sha!==null&&!hash_equals($sha,hash('sha256',$raw))))hotelFail('private_file_changed');
    return $raw;
}

function hotelJson(string $raw): array {
    $v=json_decode($raw,true,64,JSON_THROW_ON_ERROR);if(!is_array($v))hotelFail('private_shape_unconfirmed');return $v;
}

function hotelInstalled(string $app): string {
    $out=[];$code=1;exec('git -C '.escapeshellarg($app).' rev-parse HEAD 2>/dev/null',$out,$code);
    $sha=trim(implode('', $out));
    if($code!==0||!preg_match('/\A[a-f0-9]{40}\z/D',HOTEL_EXPECTED_INSTALLED_SHA)||$sha!==HOTEL_EXPECTED_INSTALLED_SHA)
        hotelFail('installed_release_unconfirmed');return $sha;
}

function hotelSeal(array $payload): array {
    $recipient=<<<'PUBLIC_RECEIPT_RECIPIENT'
-----BEGIN PUBLIC KEY-----
MIIBojANBgkqhkiG9w0BAQEFAAOCAY8AMIIBigKCAYEAuN8W3YQpfXZak/D2Whmc
58DfcWVrLJRumXPFLQjaHhcueBq9zWnFf6jp8/x9IIZ/CZDz/vxvdh7PmSAW/Faa
ciVa2HDRJmxO3ZteBVcnVWatO9j1GrbvAZc/OCYxflqBwueetSikhlqFdeqKDok4
gKE81+uPhl28Um8leV4+ouLmM9Pic4gDSqL87uMyKSe1O5lDmUbvJm3Qyfs+ZfOn
PBuEsUuvMUkVZ7c6FrhY74pEzyT6Mx6XdnnVdyZ+ksGcmCAroAQoPtia7ebv17Xi
pJjgwEokntbAAlvqwrbH8X7X+MpeI84EXAh6kJOPxCj8l/Oxb1xdZR/wC0DorQkj
R7yipD6y0nyjWWHdwucd4Lty0H332crtnxktGYIfymRXprZb/creHaGOw39gIvGV
T8YNFRu1JmL3tbjxnO4ekvJfvOzwErVxLahHT0RJCcZ43a7zga+9oMaFLII9RqGd
LPAX0T14+63yqvazaMLpk7UOTjSedaIGCxEfuviVR4pzAgMBAAE=
-----END PUBLIC KEY-----
PUBLIC_RECEIPT_RECIPIENT;
    $recipient.="\n";
    if(!hash_equals(HOTEL_RECEIPT_RECIPIENT_SHA,hash('sha256',$recipient)))hotelFail('receipt_recipient_unconfirmed');
    $aad=HOTEL_STATUS_KIND.'|'.HOTEL_EXPECTED_INSTALLED_SHA.'|'.HOTEL_OPERATION;
    $plain=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    if(strlen($plain)>65536)hotelFail('receipt_bounds_unconfirmed');
    $key=random_bytes(32);$nonce=random_bytes(12);$tag='';$wrapped='';
    $cipher=openssl_encrypt($plain,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag,$aad,16);
    if(!is_string($cipher)||strlen($tag)!==16||!openssl_public_encrypt($key,$wrapped,$recipient,OPENSSL_PKCS1_OAEP_PADDING))hotelFail('sealing_unavailable');
    unset($key,$plain);
    $envelope=['operation'=>HOTEL_STATUS_KIND,'state'=>'sealed','operator_provider_calls'=>0,
        'algorithm'=>'RSA-OAEP-SHA1+A256GCM','recipient_sha256'=>HOTEL_RECEIPT_RECIPIENT_SHA,'aad'=>$aad,
        'key'=>base64_encode($wrapped),'nonce'=>base64_encode($nonce),'tag'=>base64_encode($tag),
        'ciphertext'=>base64_encode($cipher),'ciphertext_sha256'=>hash('sha256',$cipher)];
    if(strlen(json_encode($envelope,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))+1>98304)hotelFail('wire_receipt_bounds_unconfirmed');
    return $envelope;
}

function hotelHash(mixed $value): string {
    if(!is_string($value)||!preg_match('/\A[a-f0-9]{64}\z/D',$value))hotelFail('saved_hash_unconfirmed');return $value;
}

function hotelRuntime(string $app): array {
    $dir=dirname($app).'/max-search-bot-hotel-server';hotelDir($dir);
    $bytes=hotelRead($dir.'/runtime.json',65536,null,HOTEL_EXPECTED_RUNTIME_SHA);$runtime=hotelJson($bytes);
    hotelKeys($runtime,['bootstrap_sha256','campaign_id','flags','policy','schema']);
    if(($runtime['schema']??null)!=='hotel-server-runtime-v1'||($runtime['campaign_id']??null)!==HOTEL_CAMPAIGN
        ||($runtime['bootstrap_sha256']??null)!==HOTEL_EXPECTED_BOOTSTRAP_SHA||!is_array($runtime['flags']??null)
        ||!is_array($runtime['policy']??null))hotelFail('runtime_pin_unconfirmed');
    hotelKeys($runtime['flags'],['dispatch_allowed','enabled','replacement_dispatch_allowed']);
    foreach($runtime['flags'] as $flag)if($flag!==true)hotelFail('runtime_flags_unconfirmed');
    return ['configuration_sha256'=>hash('sha256',$bytes),'campaign_id'=>$runtime['campaign_id'],
        'bootstrap_sha256'=>$runtime['bootstrap_sha256'],'flags'=>$runtime['flags']];
}

function hotelCorrection(string $root): array {
    $encoded='eyJhYWQiOiJob3RlbC1zZXJ2ZXItY29ycmVjdGlvbi1jYXBzdWxlLXYxfGU3ODFhOWM3MzYzMTZlNDNhOTQwY2NkZGQ4YjQyMmM2Yzk5NzBhN2U4MWJjMDA0MDUxOTAzMDBjNmM0MmNkNTZ8MTM3ZGExZTYyNWJjZDAwNWU0ZGI2OTMzNTc4MWYwMzk2ZmI0NDk5NWI5NjY4ZTFiMzUxMTFmMjMwNzgxMDM4OCIsImFsZ29yaXRobSI6IlJTQS1PQUVQLVNIQTErQTI1NkdDTSIsImNpcGhlcnRleHQiOiJ3WUlGTHYzc2JreGdlTnE1US95WFFXczZOZmlidkRFU2JJK3Q5ZXY3UTZkYm1qbFZkZytQYnFmc0Q2TEZ5Vmw5ZTNWRlF1ekFuWEgrYmxwVGMzb2RZaC9rRFVvTXZLWlJDZGJDS3FlWkY0d09LeHN5Umw1WHdXWHFRYkJTbFpwU3VaZGM2NXNPTmNRRGFEWFMweTgvcndqbll3WGtaaEpLbHIvUWVSVXh1YURWeCtRbHpHVFl6YmlEVE43dHBCR2d0b1JSdTNJVXc0M0tXTWtXUzdReFdLaFRmc3NrNENReVZjWmdSSzZCZytqZ0dwVTl1VkJENmFJVnBCdHpZREZGMmxmbit0REE4R3A4R3RJcm50VlJocTA5eTY1N2diUnVEY1I0ckp0aGI1U0R5ZkFPaUR3VFV6d2UvUDFycEtEMENURDBBOElxL0N2V1VxMDR3WFZwcm9lN1J5bkI2UjN3UnVJVDhFUnFVdzJlQjRpMklmOWJFK3lSWEhKRmwxbSt6TkppLytSdyttcGltZ3FuVndVTmVlYzM0T1VMR0pCdHBVeG5hZnY1NS9FUk4xRnVkWDRKbER0T0VqRDFzZzAyZTNqcU5RZGFKTDMvRFA0L1ZQTmt6K0hEdFpHUGxYakFEK1M1dWNMZ2ZmWnN5SUNQUXo5TGsyUWUwekFOZ0VIellQcnp6OEdNdWlTekFRMlZvc01wZzI2akg5eWJJekJxaTlpQW9KSjdhWjZyQXQzaTN6Qzl2dlZKTnJHb2llblVuZ0tWMENZY1MyemtsNk44aUlMcWVmbVAxTG82d3lwa3NOSkZZTUVBeU8rRkE5R3VOS1NDWFZhVGhKZENZUVg2SHFMa0pDSkExUmRndzlOMFhOQkpHenZVZWhkbFNZL01kbUZpQ2F1d1JXOXlpRHdDekI2UFNKdVdHSk9nQmszYjZJMXdOTmFVMW5vZng3TGZGTmU4YUtIR08xQ0txRTNjUkZ5MHM4V3FLSmVLNWVlZUNpWkFZcnRXcGZ6NWhoQ1ZUNEptNGQvakRzcklVR0RWY3h5dDdKZnpERUtrU2o3d0Rzam9TWHRYTjBReDFiTXF5MCtZU1ZkVkVwa0tRaEZ6ck1FbDNldENJV216SVJxT2ZwQ3NMKzZhN2dQK29GYlhJSGdyc2lnSE5kbm56NmZ1TlVCMlIvT3BhN21mdXRacmMwWFE2aFdvcDBUZUJRaXJDQnpaWkU0dDErTStSTnFCZ2dXUVFNYUNrclMrTE1qUkpBaUVnbHV3Q3NYdjV0cThHUzl0d3k5Rm9Qd1pPVXVYdVJiOHdxOFlHbnNKY1BXMTZmQlJEd0JFYjBScTNSbmtHVzlvSkJzMEtEd3Z1a3VMWnFEM2ErSlFjeko5bU4wNTFiZ0FIaDl1VmRKTWVTaHh6Tk9JT1ZERE5lcWpSajI3dkVpN1RiQ1RvYVROMm01c3MwWnlhVlVsZksraWJoSWx6SXA5KzdiS3l1SUJWcjZvQy83bWtBdkZLZXVHUGR2ZmhnRU15QXBFUlBqOC93eUtubVNnaWc4L09VSVVmS1ZoTVNSVTFQc2p5bE5UcVBDTk5RYXRRMFd6c2tWd1pEZFdMUGQzc09qdDFyOXdyVy9ZMXZsSUVvODhvOVArZGVtQWd3MU9FSDk2ZnM3UkFSUmhCYkphL1pIaEVROXZhTWlTRjFQaGxLMHdSa1I3L0pMeWpwM01nQWdhVjY5ZDlPY3Z2WWJDZXpMUEFJTVp6QVZ2Zi9kZlkrQ29VVmxVUEJXaGtwUUVPTDU0NVI2OUpTaDNFUFpuemZwV0N2Nk1XWGtWVCtaM2xVaWVOS3BNYkZSL05LMDJ5Q2MyYnlBQ2JjeEJ3SEgvK1lMcE84UnZxS1JDV2NkbmRuc25wMFBnVi90cmlTL0xudkNQNzd5Z3Rhd3A4UFVaYkhwL0pVMHlCUWxDeEpWeGFqUHEwMEtQL1A5Vis1SGJnREdTZWVvc2Y0cW1WbHJiZm90b0xxY1NGcCtwWUtwWE01NjFhd3lISlBYTWQ2SFRoRjEwNFJFQkVqcCtxU3NYcVNGMTZINmNLdGdjY1BKU2lOTTYxbjhiRGFPSGpHK3NhR21IS3RwTjB2am5KekdoNkhTNWYzUGVZb092K3poSk1IQ3NLOHQ1Wkx4bTZKRDI0bnltdk1Cam9tY2ExTExncEpUV3BwTWQxNnpwMlU0Y3hyTzJraFVMa2hoeWMwYlZZc3RRZitpcGwzNzlWMFRqTXBKeFo3cnFKeU0vcDhabWRRUXRmV0UwSkt3S3JwUko1SmQxalRpVjgzS1N5a0VrRjhIQThtZmRxaUU3d3pva2kzVUIzRXVDeXBJSFc1b3Y3YW1hQnpWMkRQOVFGbUNhOCtuU3RFVFErbXhlOUU2T1ppUUp1TlRZKzlVbHhuZzdHdVEyUDI4MVRiZlN3U1NCeUZ0WEJHemNmeUpBVC8wb0ZIVTkyQlhuaWFZSy9TbUh1VVBSV3lTemlIU1hSWTRuYUQ1Qk9zVndRV1MwQzFQejR3a3VsV0hPQS9NZEpRbzZPUk9ZNlRnZTVYcG40VU9NdTJwWXgxTmUyOFh6T1E5NkVtWitHZ3ZGQWUrSU8zTlFLQWo3dmNzQVZoVkQ3dEJSeUU0cWV0YXd0aGo1cDJSSm90ak95SkJUV09lb1pUcVNPenhMSVhIZFlzMXJHUnc4aHROQ3BQVE13V1FSZ3V2eDlaL3JzZDAzTjlJL00wUjdrSUJRZUlaWG9SWXVHVWJTYUF0emJKbXF6SzBGbEdlMzZHbVNQUDJsV2JNcEp1UkdzNXdtTHIzKzc3K29PeUVBMDFHcXVFbFJjaDRVK3NTUzlXNGMzZ2pjSGkwREFUUi9Vd0t4ZmJuaVFzVExKSjVyaGlGeDd4dDRmTXF2dU84TUMrc0x0VlZvY3lzSVZsY0ZlTXRmUjlFUkNRaW52MDl2U1k5TmhQbnAzUlMyTnFRWkJXVmxFOWtVa0YzNTFrcittQndPK1F6c0MxQmFKeUdHbHFDcFZDeTZ4VVAzQ01abUMxU3J0WkNPSnJheXJKck9TVm1aM3NUbTNpR04wcm1WVHdHZVBKdTBXcDFPSkVBNGwwSmpKR3dYMWtaR0FLNUhTU3FuVWFiSGY2eHpnMThFQWY4dXVENzJDdHZwU01FRERMVTkxTFdNM2JRRGlGV3lyMlVVVkM5RUp2cTRILzFqMDFPQjJWYk5TNXNyR3lyV3RzanZWVnREbkZCQ2xiUmlkOUVpZHk3cXpnbW4xcnU4cjV4NldzbWppMXVTbEpDc0wxT05aWVUzdC9ITnMwdUJSclNadnUzdTFkNHdvZTdMN2dMOHMvU3VTL1JRVFRLaEk1ZFpEYWQyUWo1QVozdHRkaHRpSUg1QjlHV0tUWURnOXkrTnVSMVo0NFhOWjlhWEJ0R2FIS0FUZVBDSXlhc2V5WEw4blR2OW91RnJ6dW03NjBObWhGMjhWVVlOaEk0VjZlZGs4QXoreC8wajRVMGgzdnV4Qk43Wk9WcDlwR2FoaW9Yb0xBRHFyRU9rMnlRMUFBajY3Unh4UEpJWGpFT0kzNWhoMXg3cm9MdlhYL0RUN1pROWtlaEg5TERPVGg0YjZEdFNKakYrYVBMVWpVcFM2VmtuMXN1bXFtQXREcTRBUjZaeTJnSnhGMDRtYXRmT3VPQTZmUFZzMmpMMjBYNDRqeXJEdmtFQzIyRzcyVFp5a0ExYi95Rm5OdnNpZXYzZkxiUENFN3FYSHRSRjRVdnBPWW55NHdXWTBLeEpGcXYvbHMxR1ZnbUNDQU9EcW5HdHdGOE1aVnQ5aXcyM003R1pnaXJCYUxWQVBIL1JCeEN4NG1kbUl3em9rdlc5dEMra04rUzJ5MTd5cUJwc0xObWdOZFUvK0N1QTkrMmhuZnF5T1BRc0hHYVh5dHlqRFR2aWdRR2pwREliWDZiSWtXQ2o2Njl2YmlHc0xoV1Z0ZWJqTzh3eSthdEtmaDhxUnlvWHc4TTJhWSsrS1VjZUNMeWd6cEs3SFRKOEs5MDRIbWxEY3FwWENtSWhRTm1iMWkxM2NwVUtRZGxCMllXSU5zeStpWjFidEcyK2d1ekxLMHdZUmhNTjBJM2dqcjY1NFlUZUxCMzFDSVlLbFBJcWFkeG0rMmY2TEs1VFJIMlFmeitvajFBaUhIVHl1enBXTUk1Zmc1NXMvdzV2YWJva2lZMTFEU0QwMkhVWHROdjNZWm1VQmdHSmtMTzRHY0puMFVVTm9Hc3hKK1RHUTliWVFFT2E1ZmpyV0lZRER2Z20ybktDaldDSGQrUVNiWld3aCtmVEh4Nkh3WWo3Rm1Yc2NSTFA5VnNRb1RwanFPa2tXMU51eUtxQlY0Q0lET2pqN3pIOE4xdGcvL3JINlFrU3ZSa2JqMUplMUZiTmc4MDAyUWQrS3VNcHNRdmlRYWcyaGhnSjhNZWRWNzNMeFRnMGplTXpaZEYzV1J1cHZYekJsM1dCZ2hJQXVDRUdXc1MrSDJZMGZqMzU1YVl3UGV2M2dDYmowdkI0Y0kxWHRxak1vUm55bzVGbnZEV3JHSGN2MEkvNUZxMkh2MWpwcnVmZHZwN3lxcXMrelREOGtJcmVVWEVROGR1Z0l3ZW85dktjWWNaeVZ3aldxdHFUZnhqT2lxNXhqaFlIcit5UkdIS2gzU2xjcUxoRXY4SlVxN3NRRlB5SnNuRnM4MVh2UklWVXIvSWtVOGJKcW5Wd24zNzhzOTAzM1VydWo1dkRDRzNKQjlxVkN0WjRQSHBiT3BiUnVvZ0FTMzcvNzlBRVZaWDViUWVsUWhNZkQ4TDdoYnUxclpwU2hwaTExV0FXdlZxTXB5UDlJTjR5NUdOcG9pSktMbnhoQ1pHWjNCam83VEFxTTBjeDlWL05pTVZaaE8wdTk2anBQNEU4SVpmMWJCRlNlK1pUR3RkVHRPNGttY2xmRk9kSURYNTBKYXdPK3JtTG5tODZ1a2hNbTkyZWdtQ1Y3Zng5dnBwbGhXRXVMQnZDazhMZjlyR09EWEtVak5WbnJFNjJFRjgzblNUZVdUY25CUlRWeGNoRWdyS1NVM2pjanBCTkJZMElQTkxNcktKeU9xOG9yYmtYejl3Zkc3UTB6dEd4UDFiYkZrYTFLOW90WUFwOFE3a2pRSGF5TUxWcDJrN1V5SjMwTlYzMWdBSUx2MXpXL2FETXJhdkNaNXZOd3c5UEtTSXhob0FJN1hGR0FYYzlPZE9aYWFLcmtHZHAyc09oaDZoSjRhK1RlT0RFdm1hLzBjMWtzYVE1b2hVcUNBNjVMQ0ppSmFLcXVjR01VczVXbTZEVzd5MTBMWDh2eHFaWVF4L29mWjJPaW1YWXg0OUNrdDlOQ3laR254Yk9OeXUrUXhPbFFMWDFUWHYvUHduRnpLZXYyYWdiYmlaNjFWQytlNGdSdjdrRTdtTUxHSEIvZWc1UW5QMHJsejByQ0JEWFF5OTY0OXQ4cWJleXErcmZyTFVLQ2tJQ0UvejE4M3pycko1NmJlTmQ5czNQWjF0bk05ZEpQaFh4QXZZYm1GNEw3MHoxK0hrOXdrYzQ4RlRrUGF2bS9iU3k4azhJaDA4bWNqM3RIOFFFb0RmQmo1UnJPbzlvRlVqOGQxVmUxSXpoNGRKeXNYU0loeDRDUTBjQ0d5TnZrR0xDYXloTjJpMm9WcWMzNDUrd1A1d3FvVmFBSHBGSVBOcmhyMlJEWFVNeUVRNlVzazRneWl2cXp1eWgzSng3ZkdnMFdIbkt3RnRRYjVwZkRtNGFmT0RyaHNYZExPMlYvOTBoRHBmVEZXWEJmcFJtTDExNnRhbXgwWnRrOHN6MHhyUGw0L2Mya1ZrN25KUGU3MWlxNFRTYjg4R2s5Nlp0RVM4L09DTW81N0VZZWYvL3ZoNmFRdldDSVVnbThJRmhVcHJpV2VJdTJoRUE1cU91SlcrenoxQ1crUG9icnhhdlowRTZmWUg5ZUdOU3U3Q0l0UVlQZUlwOEt5YXZlTEtzTklDY0Q0Q0Z0VVdzUW5JdGp6YXhxVy9IcTFHVXhkMmc3Y2IzRklIeFhQSFFXMUZKaHAvRW9QMDQ2K3FuOTJDMGhtalRoeEpkZC8zcWVodU83U0laTzJRM2RkRlkxcGYvL1RyQy93djE3cHluZ08weXBOZFZxSlhMMVpZMSswd3M5YVd6d2ZZVTJrd0tXelFPN0QyckpGVURJSzR2b2FETEVIUjN0bjJRQ3hueWRaV3pHbTl4WlBOQ1ppdmswZTBDSGhjbFhvU3d1ZHUxUkk2cmRxemtTSGVtYmtuQ1hjcjdOdklqVldnSmNFRUJCZjdQM1dJaG81aDl1WUtSVkJwSDgrNURrSS9wWmdyaVdjdmM0ZENMb3BaSXNkWGRieWxrR21ON0F4WDFMRjFod0xFWjBSb0RFb0hsY2pESzlLZ204OUlJMW1ETDRZYkJoZDczU2V1Z1htQ0dLTmxBaWVvcVg4Q2E2bEwzbXY3WUcxZGlaOTRpcHBPam9OTDZMSmhJZVJvRzM4K2VxeGFTRWpMcTZOTUpTSkw4K1J0eENKUmkzNGxnZjJkb1l0TEhBcDA5TjQ2RloyQnlNNk1RTTYrNjBWeURqN3UzMDQyTGhnOUdqaFJqaGJUbUtUcmRlRlVZYUhNVUVYVEhHSHVCMzROL0RjQ2hXQy94NDgyaGRtMUlTQ0t2R0RHOStCM2JEQTJZUFhLSE9NMmh0YXYvUzV5dzNFUHFocmlKU1hoYmFTSVEyN3doNXh6RkRPcGFnT3gvUjdUc0gvdW85ZW1pNFljUlZwcGxTM0tUcnRZNnNHQlh3SEVCNEZBMkpaSUxqTXJqL2F4TWZBMWxxS0NaU256UTdHeG10Q3ZzbjBZQ0I1bFk0SzZ0ZXlLTUdqanZpTlQwMEphSjI2OHozYWd4d1hyWTA5aTY1RVBvMlJObmJvWEFFdUVRcXFqUmxGZjNLTmkzZG5jOEV3WksxZDAyVmZNWVhWMTdGd2JpZjR2Rzc3bHZpN3huTytNRSt0MEtaczE0VkdYV3NBY05hTWtabURHQ3h5STErZ054UTFDcklvU2lQd1JZeVlwK2taejdJZjZaSWdnOUltUFZia0JKUGVLdmNsU1dSNFZGd1QvSlpjdTZPa1owMXcvUmF1ZlB3eVFRd3F5cSs3VFc2Q3hWakpkZDlyVW01cW9NU2ZzY2lDM3RVdlhTSWNGZ1RHODNMUSsvMVY1VjZjcEVxa2czNVB6N3FnSXhEYmxrQWFEZ2U0L1F0SjVVWWJJN2NEb3k3T2IrOWRqc0VpN29sYktPTGQyZ3VUdnF6UDhpZXFBdmI3L0lWWEJocTJXdWZnPT0iLCJjaXBoZXJ0ZXh0X3NoYTI1NiI6Ijg4MzUwNjQ1ZGIwZjk3NGIyNWEwMDI5ZGNhNzY5MDdkYjFhY2Y1ZWU0Y2UyOTIyYjk1YTRmYzcwNDY0MDNjMDMiLCJrZXkiOiJRK283aFkxa1VOWnpqV1VXSWtwWVRlOHFzOFVhVUZGdTlIb1NVQ0JTbjM3QU8xcE0zd3B3aFVVN3BNdlVkMlpadktmMnp2OFBTcHNFcGRhbkdCdmRPdFoxcmdGVnJVQWdDM21RKy9jOUtwb3hKb21EUjhjU1lBZjlRaDBWZTBZVVo2dEpCTEJ6OE5JcTY5TkJpT3NkRm1iTEgwYkhxaGNKaGVKL0FYNExISzFpOW5ldGR1dm44YWRiZ00zbGNwZXZsdkdQeHY1RG9lQlhDLzRmRkRaMVJPQlluYmpjYkQrdXQvc25HUitXQmZydTNiSUZpM1F4bU1JNFgrZzQ4QmdncllWeWVoYmNBdWRLK1Nnc0FsYmYzVjFoKzQvQ05wL3FiRTFtSXVhRGtaK1Avd0RKOGc1ckk3V0Y2ZDNpQUZFRDRXb2JCT3JFWG45ZGpGb3hncVJOYjdCYmJKSDhqcjJCVGJBRDZGek1MV2o5cE5rdjl5UlgyK3N4Y1RWM0x4clkzSldNR2VKTkV1dnovTDJXUVFRRUkwWmJmYi9JSDROREEwSllVWUwzRHdKNDJEdXpYOTByaU9hY2dFYUhDUnQ2aHhFSnk4S2Q3ZG96NkgzdWJxMVhNaHh0ZEFKYnFiNHlMRnBVc1d1UFoyajd2WXN4ZkVZSFBxbmdnMEc2ejc3NiIsIm5vbmNlIjoiUXRMcXZ4bDRIRnRzck5oViIsInBsYWludGV4dF9ieXRlcyI6NDEyOSwicGxhaW50ZXh0X3NoYTI1NiI6ImU3ODFhOWM3MzYzMTZlNDNhOTQwY2NkZGQ4YjQyMmM2Yzk5NzBhN2U4MWJjMDA0MDUxOTAzMDBjNmM0MmNkNTYiLCJyZWNpcGllbnRfc2hhMjU2IjoiMTM3ZGExZTYyNWJjZDAwNWU0ZGI2OTMzNTc4MWYwMzk2ZmI0NDk5NWI5NjY4ZTFiMzUxMTFmMjMwNzgxMDM4OCIsInNjaGVtYSI6ImhvdGVsLXNlcnZlci1jb3JyZWN0aW9uLWNhcHN1bGUtdjEiLCJ0YWciOiJVSmFVMkwyZ1V2UWdkVFVYY09NamFBPT0ifQ==';$raw=base64_decode($encoded,true);
    if(!is_string($raw)||strlen($raw)>16384||!hash_equals(HOTEL_CAPSULE_SHA,hash('sha256',$raw)))hotelFail('correction_capsule_unconfirmed');
    $v=hotelJson($raw);
    hotelKeys($v,['schema','algorithm','recipient_sha256','aad','plaintext_bytes','plaintext_sha256','key','nonce','tag','ciphertext','ciphertext_sha256']);
    $aad='hotel-server-correction-capsule-v1|'.HOTEL_CORRECTION_SHA.'|'.HOTEL_INPUT_RECIPIENT_SHA;
    if($v['schema']!=='hotel-server-correction-capsule-v1'||$v['algorithm']!=='RSA-OAEP-SHA1+A256GCM'
        ||$v['recipient_sha256']!==HOTEL_INPUT_RECIPIENT_SHA||$v['aad']!==$aad
        ||$v['plaintext_sha256']!==HOTEL_CORRECTION_SHA||$v['plaintext_bytes']!==4129)hotelFail('correction_capsule_unconfirmed');
    $d=[];foreach(['key','nonce','tag','ciphertext'] as $k){$d[$k]=is_string($v[$k])?base64_decode($v[$k],true):false;if(!is_string($d[$k]))hotelFail('correction_capsule_unconfirmed');}
    if(strlen($d['key'])!==384||strlen($d['nonce'])!==12||strlen($d['tag'])!==16||strlen($d['ciphertext'])!==$v['plaintext_bytes']
        ||hash('sha256',$d['ciphertext'])!==$v['ciphertext_sha256'])hotelFail('correction_capsule_unconfirmed');
    $ingress=$root.'/ingress-v1';hotelDir($ingress);
    $public=hotelRead($ingress.'/recipient.public.pem',4096,null,HOTEL_INPUT_RECIPIENT_SHA);
    $private=hotelRead($ingress.'/recipient.private.pem',8192);
    $handle=openssl_pkey_get_private($private);$details=$handle===false?false:openssl_pkey_get_details($handle);
    if(!is_array($details)||!hash_equals(HOTEL_INPUT_RECIPIENT_SHA,hash('sha256',$details['key']??'')))hotelFail('existing_ingress_identity_unconfirmed');
    $key='';if(!openssl_private_decrypt($d['key'],$key,$handle,OPENSSL_PKCS1_OAEP_PADDING)||strlen($key)!==32)hotelFail('correction_decrypt_unconfirmed');
    $plain=openssl_decrypt($d['ciphertext'],'aes-256-gcm',$key,OPENSSL_RAW_DATA,$d['nonce'],$d['tag'],$aad);
    unset($private,$handle,$key,$d,$v);
    if(!is_string($plain)||strlen($plain)!==4129||hash('sha256',$plain)!==HOTEL_CORRECTION_SHA)hotelFail('correction_decrypt_unconfirmed');
    $correction=hotelJson($plain);
    if(HotelServerInputs::json($correction)!==$plain)hotelFail('correction_canonical_bytes_unconfirmed');
    return $correction;
}

try{
    $app='/var/www/anytoour/data/www/app.anytoour.ru';$run=getenv('HOTEL_OPERATION_RUN_ID');
    if(PHP_SAPI!=='cli'||count($argv)!==1||realpath(getcwd())!==$app||!function_exists('posix_geteuid')||posix_geteuid()!==1012)hotelFail('target_unconfirmed');
    if(!is_string($run)||!preg_match('/\A[1-9][0-9]{6,20}\z/D',$run))hotelFail('invocation_unconfirmed');
    $identity=['campaign_id'=>HOTEL_CAMPAIGN,'correction_sha256'=>HOTEL_CORRECTION_SHA,
        'installed_sha'=>HOTEL_EXPECTED_INSTALLED_SHA,'schema'=>HOTEL_STATUS_KIND];
    if(hash('sha256',json_encode($identity,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))!==HOTEL_OPERATION)hotelFail('correction_operation_identity');
    $report['github_run_id']=$run;$report['installed_sha']=hotelInstalled($app);hotelSeal(['state'=>'preflight','operator_provider_calls'=>0]);
    $root=dirname($app).'/max-search-bot-hotel-server';hotelDir($root);hotelDir($root.'/ingress-v1');
    $lockPath=$root.'/ingress-v1/channel.lock';hotelRead($lockPath,1,0);
    $lock=fopen($lockPath,'rb');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))hotelFail('active_private_operation');
    $opened=fstat($lock);clearstatcache(true,$lockPath);$actual=lstat($lockPath);
    if(!is_array($actual)||$opened['dev']!==$actual['dev']||$opened['ino']!==$actual['ino'])hotelFail('private_admin_lock_changed');
    $report['runtime_before']=hotelRuntime($app);
    require_once $app.'/config.php';require_once $app.'/services/ConversationDb.php';
    require_once $app.'/services/HotelServerCycle.php';
    if(!method_exists(HotelServerCycle::class,'correctRejectedFinalStage'))hotelFail('correction_api_unavailable');
    $correction=hotelCorrection($root);
    $pdo=ConversationDb::connection();
    if($pdo->inTransaction()||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION)hotelFail('application_database_unconfirmed');
    $cycle=new HotelServerCycle($pdo,new HotelServerLane($pdo),null);
    $report['database_mutation_started']=true;$report['mutation_outcome']='in_progress';
    $result=$cycle->correctRejectedFinalStage(HOTEL_CAMPAIGN,$correction,
        static fn():bool=>hash('sha256',HotelServerInputs::json($correction))===HOTEL_CORRECTION_SHA);
    $report['correction_committed']=true;$report['mutation_outcome']='committed';$report['correction_result']=$result;$report['committed_at_utc']=gmdate('Y-m-d\TH:i:s\Z');
    if(($result['state']??null)!=='complete'||($result['stage_index']??null)!==4||($result['new_accepted']??null)!==4
        ||($result['correction_sha256']??null)!==HOTEL_CORRECTION_SHA||($result['already_applied']??null)!==false)hotelFail('correction_original_outcome_required');
    $s=$pdo->prepare('SELECT state,enabled,stage_cursor,new_accepted,baseline_sha256,inputs_sha256,bootstrap_sha256,reserved_calls,reserved_tokens,reserved_micros FROM hotel_server_campaigns WHERE campaign_id=?');
    $s->execute([HOTEL_CAMPAIGN]);$saved=$s->fetch(PDO::FETCH_ASSOC);$s->closeCursor();
    if(!$saved||$saved['state']!=='complete'||(int)$saved['enabled']!==1||(int)$saved['stage_cursor']!==4||(int)$saved['new_accepted']!==4
        ||$saved['baseline_sha256']!==$result['baseline_sha256']||$saved['inputs_sha256']!==$correction['proposal']['inputs_sha256']
        ||$saved['bootstrap_sha256']!==HOTEL_EXPECTED_BOOTSTRAP_SHA||(int)$saved['reserved_calls']!==8
        ||(int)$saved['reserved_tokens']!==8417600||(int)$saved['reserved_micros']!==88000000)hotelFail('correction_postcondition_unconfirmed');
    $report['campaign_after']=$saved;hotelInstalled($app);
    $report['state']='correction_committed';
    // Existing verifier only observes two natural future cron completions. It never ticks.
    $out=[];$code=1;exec('/usr/bin/php '.escapeshellarg($app.'/tools/hotel_work.php').' verify-cron 2>&1',$out,$code);
    $raw=implode("\n",$out);if(strlen($raw)>8192)hotelFail('correction_cron_proof_unconfirmed');
    $proof=hotelJson($raw);$report['cron_verification']=$proof;
    if($code!==0||($proof['ok']??null)!==true||($proof['state']??null)!=='scheduled_ticks_verified'||($proof['provider_calls']??null)!==0
        ||($proof['proof']['first_state']??null)!=='ok'||($proof['proof']['second_state']??null)!=='ok'
        ||($proof['proof']['first_stage']??null)!=='server_cycle'||($proof['proof']['second_stage']??null)!=='server_cycle')hotelFail('correction_cron_proof_unconfirmed');
    $report['runtime_after']=hotelRuntime($app);hotelInstalled($app);$report['state']='corrected_and_cron_verified';$report['finished_at_utc']=gmdate('Y-m-d\TH:i:s\Z');
}catch(Throwable $e){
    $message=$e->getMessage();$report['state']=($report['correction_committed']??false)?'CORRECTION_COMMITTED_HOLD':'HOLD';
    if($report['database_mutation_started']&&!$report['correction_committed']){
        $report['mutation_outcome']=$e instanceof DomainException?'rejected_atomically':'unconfirmed_no_retry';
        if(!($e instanceof DomainException))$report['state']='MUTATION_OUTCOME_UNCONFIRMED';
    }
    $report['reason']=preg_match('/\A[a-z][a-zA-Z0-9_]{1,100}\z/D',$message)?$message:'correction_outcome_unconfirmed';
}finally{
    if($pdo instanceof PDO&&$pdo->inTransaction())try{$pdo->rollBack();}catch(Throwable $ignored){}
    if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}
    while(ob_get_level()>$level)ob_end_clean();
}
try{$envelope=($report['reason']??null)==='target_unconfirmed'
    ?['operation'=>HOTEL_STATUS_KIND,'state'=>'HOLD','reason'=>'target_unconfirmed','operator_provider_calls'=>0]:hotelSeal($report);
}catch(Throwable $ignored){$envelope=['operation'=>HOTEL_STATUS_KIND,'state'=>'HOLD','reason'=>'sealed_readback_result_unconfirmed','operator_provider_calls'=>0];}
echo json_encode($envelope,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
exit($report['state']==='corrected_and_cron_verified'&&$envelope['state']==='sealed'?0:1);
