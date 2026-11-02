<?php
declare(strict_types=1);

/* DRAFT ONLY. Fill the installed-SHA pin after the original hotel release succeeds.
 * The authenticated existing administrator invokes this once for the already
 * owner-authorized operation. Artifact provenance strings are not authorization.
 * No provider send, tick, legacy queue mutation, migration or credential export.
 */
const HOTEL_EXPECTED_INSTALLED_SHA='326048d7e9ee3b035699f2b5385d07e5f0b3db8d';
const HOTEL_OPERATION='8e4c0372a0338bc98429d47bc0fe3327238f5be69d94c7d28dbf977647a85e35';
const HOTEL_CAMPAIGN='HOTEL-SERVER-20261102';
const HOTEL_ACTIVATION_KIND='hotel-server-private-activation-v1';
const HOTEL_RECEIPT_RECIPIENT_SHA='71f2b177d6baa924fa466765027c37f51a00f23f803a6d900060bf42944d237d';
const HOTEL_INPUT_RECIPIENT_SHA='137da1e625bcd005e4db69335781f0396fb44995b9668e1b35111f2307810388';
const HOTEL_RETIREMENT_SHA='9ae2171a61e2da2c6df22cfad8f6498aee3bb8631f3404e17da9d0a85e6ee1dd';
const HOTEL_PAYLOAD_SHA='a85746bce5b71d4d241777a30e6bd643efecbd512bc5a50a2b741336012e84eb';
const HOTEL_PLAIN_SHA='c738402b7b222cc181c2c65c35a5bf82b312807cfdf658469c6d65e83e2f2d41';

ini_set('display_errors','0');ini_set('log_errors','0');
$level=ob_get_level();ob_start(static fn(string $ignored):string=>'');$lock=null;$work=null;$ownedWork=false;$mask=umask(0077);
$report=['schema'=>HOTEL_ACTIVATION_KIND,'operation_id'=>HOTEL_OPERATION,'campaign_id'=>HOTEL_CAMPAIGN,
    'state'=>'HOLD','phase'=>'target','operator_provider_calls'=>0,'database_mutation_started'=>false,
    'runtime_file_installed'=>false,'first_new_acceptance_confirmed'=>false];
$envelope=['operation'=>HOTEL_ACTIVATION_KIND,'state'=>'HOLD','reason'=>'target_unconfirmed','operator_provider_calls'=>0];

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
function hotelWriteNew(string $path,string $raw): void {
    if(file_exists($path)||is_link($path))hotelFail('prior_operation_requires_readback');
    $h=fopen($path,'x');if(!$h)hotelFail('private_write_unconfirmed');
    try{
        if(!chmod($path,0600))hotelFail('private_write_unconfirmed');$offset=0;$length=strlen($raw);
        while($offset<$length){$n=fwrite($h,substr($raw,$offset,65536));if(!is_int($n)||$n<1)hotelFail('private_write_unconfirmed');$offset+=$n;}
        if(!fflush($h)||(function_exists('fsync')&&!fsync($h)))hotelFail('private_write_unconfirmed');
    }finally{fclose($h);}
    hotelRead($path,max(1,strlen($raw)),strlen($raw),hash('sha256',$raw));
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
function hotelExtract(string $zipFile,string $destination,int $expectedCount,int $expectedTotal,?array $pins): void {
    if(file_exists($destination)||is_link($destination)||!mkdir($destination,0700))hotelFail('prior_operation_requires_readback');
    hotelDir($destination);$z=new ZipArchive();
    if($z->open($zipFile,ZipArchive::CHECKCONS)!==true)hotelFail('private_archive_unconfirmed');
    try{
        if($z->numFiles!==$expectedCount)hotelFail('private_archive_unconfirmed');$seen=[];$total=0;
        for($i=0;$i<$z->numFiles;$i++){
            $st=$z->statIndex($i);$name=$st['name']??null;
            if(!is_string($name)||!preg_match('~\A[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)*\.(?:json|jsonl|zip)\z~D',$name)
                ||isset($seen[$name])||!is_int($st['size']??null)||$st['size']<1||$st['size']>4266944
                ||!in_array($st['comp_method']??null,[0,8],true)||($st['encryption_method']??0)!==0)hotelFail('private_archive_unconfirmed');
            $opsys=0;$attr=0;if(!$z->getExternalAttributesIndex($i,$opsys,$attr)||!in_array((($attr>>16)&0170000),[0,0100000],true))
                hotelFail('private_archive_type_unconfirmed');
            if($pins!==null&&!isset($pins[$name]))hotelFail('private_archive_unconfirmed');
            $seen[$name]=true;$total+=$st['size'];if($total>$expectedTotal)hotelFail('private_archive_unconfirmed');
        }
        if($total!==$expectedTotal||($pins!==null&&count($seen)!==count($pins)))hotelFail('private_archive_unconfirmed');
        foreach(array_keys($seen) as $name){
            $raw=$z->getFromName($name);if(!is_string($raw))hotelFail('private_archive_unconfirmed');
            $st=$z->statName($name);if(strlen($raw)!==$st['size'])hotelFail('private_archive_unconfirmed');
            if($pins!==null&&(strlen($raw)!==$pins[$name][0]||!hash_equals($pins[$name][1],hash('sha256',$raw))))hotelFail('private_member_pin_unconfirmed');
            $parts=explode('/',$name);array_pop($parts);$dir=$destination;
            foreach($parts as $part){$dir.='/'.$part;if(!file_exists($dir)&&!is_link($dir)&&!mkdir($dir,0700))hotelFail('private_write_unconfirmed');hotelDir($dir);}
            hotelWriteNew($destination.'/'.$name,$raw);unset($raw);
        }
    }finally{$z->close();}
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
    $aad=HOTEL_ACTIVATION_KIND.'|'.HOTEL_EXPECTED_INSTALLED_SHA.'|'.HOTEL_OPERATION;
    $plain=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    if(strlen($plain)>65536)hotelFail('receipt_bounds_unconfirmed');
    $key=random_bytes(32);$nonce=random_bytes(12);$tag='';$wrapped='';
    $cipher=openssl_encrypt($plain,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag,$aad,16);
    if(!is_string($cipher)||strlen($tag)!==16||!openssl_public_encrypt($key,$wrapped,$recipient,OPENSSL_PKCS1_OAEP_PADDING))hotelFail('sealing_unavailable');
    unset($key,$plain);
    return ['operation'=>HOTEL_ACTIVATION_KIND,'state'=>'sealed','operator_provider_calls'=>0,
        'algorithm'=>'RSA-OAEP-SHA1+A256GCM','recipient_sha256'=>HOTEL_RECEIPT_RECIPIENT_SHA,'aad'=>$aad,
        'key'=>base64_encode($wrapped),'nonce'=>base64_encode($nonce),'tag'=>base64_encode($tag),
        'ciphertext'=>base64_encode($cipher),'ciphertext_sha256'=>hash('sha256',$cipher)];
}

try{
    $app='/var/www/anytoour/data/www/app.anytoour.ru';$run=getenv('HOTEL_OPERATION_RUN_ID');
    if(PHP_SAPI!=='cli'||count($argv)!==1||realpath(getcwd())!==$app||!function_exists('posix_geteuid')||posix_geteuid()!==1012)
        hotelFail('target_unconfirmed');
    if(!is_string($run)||!preg_match('/\A[1-9][0-9]{6,20}\z/D',$run))hotelFail('invocation_unconfirmed');
    $report['github_run_id']=$run;$report['installed_sha']=hotelInstalled($app);
    if(!class_exists('ZipArchive')||!function_exists('openssl_private_decrypt')||!function_exists('link'))hotelFail('server_capability_missing');
    // Verify that the receipt channel can seal before any persistent mutation.
    hotelSeal(['schema'=>HOTEL_ACTIVATION_KIND,'state'=>'preflight','operator_provider_calls'=>0]);
    $root=dirname($app).'/max-search-bot-hotel-server';$ingress=$root.'/ingress-v1';hotelDir($root);hotelDir($ingress);
    if(file_exists($root.'/runtime.json')||is_link($root.'/runtime.json'))hotelFail('prior_runtime_requires_readback');
    $lockFile=$ingress.'/upload.lock';hotelRead($lockFile,1,0,null);
    $lock=fopen($lockFile,'rb');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))hotelFail('upload_owner_active');
    if(file_exists($ingress.'/upload.part')||is_link($ingress.'/upload.part'))hotelFail('upload_partial_held');
    $report['phase']='decrypt';
    $sealed=hotelJson(hotelRead($ingress.'/sealed-inputs.bin',8388608,5696586,HOTEL_PAYLOAD_SHA));
    hotelKeys($sealed,['aad','algorithm','ciphertext','ciphertext_sha256','key','nonce','operation_id','plaintext_bytes','plaintext_sha256','recipient_sha256','schema','tag']);
    if($sealed['schema']!=='hotel-private-inputs-sealed-v1'||$sealed['algorithm']!=='RSA-OAEP-SHA1+A256GCM'
        ||$sealed['operation_id']!==HOTEL_OPERATION||$sealed['recipient_sha256']!==HOTEL_INPUT_RECIPIENT_SHA
        ||$sealed['plaintext_bytes']!==4271535||$sealed['plaintext_sha256']!==HOTEL_PLAIN_SHA
        ||$sealed['aad']!=='hotel-private-inputs-v1|'.HOTEL_OPERATION.'|'.HOTEL_INPUT_RECIPIENT_SHA)hotelFail('sealed_payload_pin_unconfirmed');
    $decoded=[];foreach(['key','nonce','tag','ciphertext'] as $k){if(!is_string($sealed[$k]))hotelFail('sealed_payload_pin_unconfirmed');$decoded[$k]=base64_decode($sealed[$k],true);if(!is_string($decoded[$k]))hotelFail('sealed_payload_pin_unconfirmed');}
    if(strlen($decoded['key'])!==384||strlen($decoded['nonce'])!==12||strlen($decoded['tag'])!==16||strlen($decoded['ciphertext'])!==4271535
        ||!hash_equals($sealed['ciphertext_sha256'],hash('sha256',$decoded['ciphertext'])))hotelFail('sealed_payload_pin_unconfirmed');
    $private=hotelRead($ingress.'/recipient.private.pem',8192);$public=hotelRead($ingress.'/recipient.public.pem',4096,null,HOTEL_INPUT_RECIPIENT_SHA);
    $privateHandle=openssl_pkey_get_private($private);$details=$privateHandle?openssl_pkey_get_details($privateHandle):false;
    if(!is_array($details)||!hash_equals(HOTEL_INPUT_RECIPIENT_SHA,hash('sha256',$details['key'])))hotelFail('ingress_recipient_unconfirmed');
    $key='';if(!openssl_private_decrypt($decoded['key'],$key,$privateHandle,OPENSSL_PKCS1_OAEP_PADDING)||strlen($key)!==32)hotelFail('decrypt_unconfirmed');
    $plain=openssl_decrypt($decoded['ciphertext'],'aes-256-gcm',$key,OPENSSL_RAW_DATA,$decoded['nonce'],$decoded['tag'],$sealed['aad']);
    unset($key,$private,$privateHandle,$decoded,$sealed);
    if(!is_string($plain)||strlen($plain)!==4271535||!hash_equals(HOTEL_PLAIN_SHA,hash('sha256',$plain)))hotelFail('decrypt_unconfirmed');
    $candidateWork=$root.'/activation-20261102';if(file_exists($candidateWork)||is_link($candidateWork)||!mkdir($candidateWork,0700))hotelFail('prior_operation_requires_readback');
    hotelDir($candidateWork);$work=$candidateWork;$ownedWork=true;
    hotelWriteNew($work.'/intent.json',json_encode(['schema'=>HOTEL_ACTIVATION_KIND,'operation_id'=>HOTEL_OPERATION,'github_run_id'=>$run,
        'installed_sha'=>HOTEL_EXPECTED_INSTALLED_SHA,'started_at'=>gmdate('c'),'provider_calls_by_operator'=>0],JSON_THROW_ON_ERROR));
    hotelWriteNew($work.'/payload.zip',$plain);unset($plain);
    $pins=[
        'activation.json'=>[927,HOTEL_OPERATION],
        'budget-attestation.json'=>[1749,'8b24daf667ecab0fbbc9c67f2ea21df153938ba238709d1f04253f84212acee8'],
        'campaign-plan.json'=>[841,'e5db65250b2f7a1567328e64f14c0b78dfdab44dbc6908a633d2e6ead2c99db4'],
        'inputs.zip'=>[4266944,'7ce1a27c74b4b98f58f4b31afc4cb1a923e29acb8613ee36b7fedecd752b4320'],
        'provider-policy.json'=>[500,'660d999fae112ccbc0a0ae5a874fb0515187cd9fa5403e4a1c65792b04056305']];
    $report['phase']='staging';hotelExtract($work.'/payload.zip',$work.'/payload',5,4270961,$pins);
    hotelExtract($work.'/payload/inputs.zip',$work.'/inputs',54,45016274,null);
    $activation=hotelJson(hotelRead($work.'/payload/activation.json',4096,927,HOTEL_OPERATION));
    $plan=hotelJson(hotelRead($work.'/payload/campaign-plan.json',4096,841,$pins['campaign-plan.json'][1]));
    $policy=hotelJson(hotelRead($work.'/payload/provider-policy.json',4096,500,$pins['provider-policy.json'][1]));
    if($activation['campaign_id']!==HOTEL_CAMPAIGN||$activation['retirement_sha256']!==HOTEL_RETIREMENT_SHA
        ||$activation['new_acceptance_credit_at_bootstrap']!==0||$activation['central41']!=='UNKNOWN'
        ||$policy['valid_until']<=time()+900||$plan['limits']['deadline']!==$policy['valid_until'])hotelFail('activation_policy_unconfirmed');
    hotelRead($work.'/inputs/input-manifest.json',65536,null,'c04671b718dd96ec069662bb68f9a5b9dead4d80831694724137220eb0928668');
    $report['phase']='admission';require_once $app.'/config.php';require_once $app.'/services/ProjectAccessService.php';
    ProjectAccessService::initializeReadOnly();$pdo=ConversationDb::connection();
    if($pdo->inTransaction()||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql')hotelFail('application_database_unconfirmed');
    require_once $app.'/services/HotelServerCycle.php';require_once $app.'/services/HotelServerAiProvider.php';require_once $app.'/services/HotelServerRuntimeConfig.php';
    foreach(['HOTEL_SERVER_CYCLE_ENABLED','HOTEL_SERVER_DISPATCH_ALLOWED','HOTEL_SERVER_REPLACEMENT_DISPATCH_ALLOWED'] as $f)if(defined($f))hotelFail('runtime_configuration_conflict');
    foreach(['ANYTOUR_HOTEL_SERVER_PROVIDER','ANYTOUR_HOTEL_SERVER_AI_POLICY'] as $f)if(array_key_exists($f,$GLOBALS))hotelFail('runtime_configuration_conflict');
    if(HotelServerRuntimeConfig::load($app)!==null)hotelFail('prior_runtime_requires_readback');
    $lane=$pdo->query("SELECT queue_id FROM hotel_work_lane WHERE lane_key='HOTEL'")->fetch(PDO::FETCH_ASSOC);
    if(!is_array($lane)||$lane['queue_id']!==null)hotelFail('hotel_lane_occupied');
    $stmt=$pdo->prepare('SELECT campaign_id FROM hotel_server_campaigns WHERE campaign_id=? OR enabled=1 LIMIT 1');$stmt->execute([HOTEL_CAMPAIGN]);
    if($stmt->fetch(PDO::FETCH_ASSOC)!==false)hotelFail('prior_campaign_requires_readback');$stmt->closeCursor();
    $inputs=HotelServerInputs::load($pdo,HOTEL_RETIREMENT_SHA,$work.'/inputs',$work.'/inputs/input-manifest.json');$package=$inputs->package();
    $admission=$package['retained_admission']??[];
    foreach(['historical_accepted'=>19,'available_accepted'=>13,'unavailable_accepted'=>6,'unavailable_quarantine'=>1,
        'unknown_legacy_overlap'=>true,'new_acceptance_credit'=>0,'duplicate_prevention'=>'available_confirmed_scopes_only'] as $k=>$v)
        if(($admission[$k]??null)!==$v)hotelFail('retained_admission_unconfirmed');
    $packageBytes=strlen(HotelServerInputs::json($package));
    $packetLimit=(int)$pdo->query('SELECT @@max_allowed_packet')->fetchColumn();
    if($packetLimit<65536||$packageBytes>$packetLimit-65536)hotelFail('application_packet_limit_exceeded');
    $provider=new HotelServerAiProvider($policy);
    foreach(['producer','reviewer'] as $role)if(!$provider->ready($role,$plan['models'][$role]))hotelFail('provider_not_ready');
    if($provider->principal('producer')===$provider->principal('reviewer'))hotelFail('independent_reviewer_required');
    // Pure local producer-body preflight: no attempt, outbox, usage or provider call is created.
    $known=[];foreach($package['retained']['model_records'] as $r)$known[HotelServerInputs::key($r['scope'])]=$r;
    foreach($package['accepted_context'] as $r)$known[HotelServerInputs::key($r['scope'])]=$r;ksort($known);
    foreach($plan['stages'] as $index=>$scopes){
        $hotels=[];foreach($scopes as $scope){$scopeKey=HotelServerInputs::key($scope);HotelRetainedBaseline::assertScope($package['retained'],$scope);
            if(isset($package['exclusions'][$scopeKey])||!isset($package['stage_contexts'][$scopeKey]))hotelFail('planned_scope_held');
            $s=$pdo->prepare('SELECT semantic_key FROM hotel_server_records WHERE semantic_key=? LIMIT 1');$s->execute([$scopeKey]);if($s->fetch(PDO::FETCH_ASSOC)!==false)hotelFail('planned_scope_already_accepted');$s->closeCursor();
            $hotels[$scope['country']."\0".$scope['hotel']]=true;}
        $records=array_values(array_filter($known,static fn(array $r):bool=>isset($hotels[$r['scope']['country']."\0".$r['scope']['hotel']])));
        $view=['schema'=>'hotel-server-accepted-view-v1','baseline_sha256'=>$package['pins']['baseline'],'retained_baseline_sha256'=>$package['pins']['baseline'],'records'=>$records];
        $view['view_sha256']=hash('sha256',HotelServerInputs::json($view));
        $provider->preflight(['research_mode'=>$plan['research_mode'],'schema'=>'hotel-server-attempt-v1','campaign_id'=>HOTEL_CAMPAIGN,'stage_index'=>$index,
            'attempt_id'=>hash('sha256','LOCAL_PREFLIGHT_ONLY|'.HOTEL_OPERATION.'|'.$index),'role'=>'producer','principal'=>$provider->principal('producer'),
            'model'=>$plan['models']['producer'],'baseline_sha256'=>$package['pins']['baseline'],'inputs_sha256'=>$package['inputs_sha256'],
            'scopes'=>$scopes,'accepted_context'=>$view,'dependencies'=>array_map(static fn(array $s):array=>$package['stage_contexts'][HotelServerInputs::key($s)],$scopes),
            'criteria_sha256'=>$package['pins']['criteria'],'candidate'=>null,'candidate_sha256'=>null,'limits'=>$plan['limits']]);
    }
    unset($known,$records,$view);
    $bootstrapPin=hash('sha256',HotelServerInputs::json([$package['inputs_sha256'],$plan]));
    hotelWriteNew($work.'/validated-inputs.json',HotelServerInputs::json(['schema'=>'hotel-server-private-admission-v1',
        'operation_id'=>HOTEL_OPERATION,'inputs_sha256'=>$package['inputs_sha256'],'bootstrap_sha256'=>$bootstrapPin,
        'retained_admission'=>$admission,'package_bytes'=>$packageBytes,'application_max_allowed_packet'=>$packetLimit,
        'producer_preflights'=>count($plan['stages']),'provider_calls_by_operator'=>0,'database_writes'=>0]));
    $runtime=['schema'=>'hotel-server-runtime-v1','campaign_id'=>HOTEL_CAMPAIGN,'bootstrap_sha256'=>$bootstrapPin,
        'policy'=>$policy,'flags'=>['enabled'=>true,'dispatch_allowed'=>true,'replacement_dispatch_allowed'=>true]];
    $runtimeBytes=HotelServerInputs::json($runtime);$runtimeTemp=$root.'/runtime.activation-20261102.part';
    if(file_exists($runtimeTemp)||is_link($runtimeTemp))hotelFail('prior_operation_requires_readback');
    hotelWriteNew($runtimeTemp,$runtimeBytes); // Dormant, fixed private staging path; the cron does not load it.
    $authorized=static function()use($app,$root):bool{
        hotelInstalled($app);if(file_exists($root.'/runtime.json')||is_link($root.'/runtime.json'))hotelFail('prior_runtime_requires_readback');return true;
    };
    $cycle=new HotelServerCycle($pdo,new HotelServerLane($pdo),$provider);
    $report['phase']='bootstrap';
    hotelWriteNew($work.'/bootstrap-intent.json',HotelServerInputs::json(['operation_id'=>HOTEL_OPERATION,'campaign_id'=>HOTEL_CAMPAIGN,
        'bootstrap_sha256'=>$bootstrapPin,'github_run_id'=>$run,'state'=>'mutation_starting','provider_calls_by_operator'=>0]));
    $report['database_mutation_started']=true;
    $disabled=$cycle->bootstrap(HOTEL_CAMPAIGN,$inputs,$plan,$authorized);
    if($disabled['enabled']!==false||$disabled['state']!=='disabled'||$disabled['new_accepted']!==0||$disabled['bootstrap_sha256']!==$bootstrapPin)
        hotelFail('bootstrap_readback_unconfirmed');
    hotelWriteNew($work.'/bootstrap-readback.json',HotelServerInputs::json($disabled));
    $report['phase']='enable';$enabled=$cycle->enable(HOTEL_CAMPAIGN,$bootstrapPin,$authorized);
    if($enabled['enabled']!==true||$enabled['state']!=='ready'||$enabled['new_accepted']!==0||$enabled['bootstrap_sha256']!==$bootstrapPin)
        hotelFail('enable_readback_unconfirmed');
    hotelWriteNew($work.'/enable-readback.json',HotelServerInputs::json($enabled));
    $report['phase']='runtime_publication';hotelInstalled($app);
    // link() atomically publishes the fully fsynced file and cannot overwrite another runtime.
    if(file_exists($root.'/runtime.json')||is_link($root.'/runtime.json')||!link($runtimeTemp,$root.'/runtime.json'))hotelFail('runtime_publication_unconfirmed');
    $report['runtime_file_installed']=true;
    hotelRead($root.'/runtime.json',65536,strlen($runtimeBytes),hash('sha256',$runtimeBytes));
    $loaded=HotelServerRuntimeConfig::load($app);if(($loaded['configuration_sha256']??null)!==hash('sha256',$runtimeBytes))hotelFail('runtime_readback_unconfirmed');
    // The old staged inode may remain after an unlink error; it never starts another operation.
    if(!unlink($runtimeTemp))hotelFail('runtime_staging_cleanup_unconfirmed');
    $report+=['activated_at_utc'=>gmdate('c'),'inputs_sha256'=>$package['inputs_sha256'],'bootstrap_sha256'=>$bootstrapPin,
        'runtime_sha256'=>hash('sha256',$runtimeBytes),'retained_admission'=>$admission,'enabled_snapshot'=>$enabled,
        'new_acceptance_credit_at_bootstrap'=>0,'expected_stages'=>count($plan['stages']),'maximum_calls'=>$plan['limits']['max_calls'],
        'source_mode'=>$plan['research_mode'],'central41'=>'UNKNOWN','continuation'=>'existing_cron_only'];
    $report['phase']='complete';$report['state']='activated';
}catch(Throwable $e){
    $message=$e->getMessage();$report['reason']=preg_match('/\A[a-z][a-zA-Z0-9_]{1,100}\z/D',$message)?$message:'activation_unconfirmed';
}finally{
    if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}umask($mask);
    while(ob_get_level()>$level)ob_end_clean();
}
try{
    if(($report['reason']??null)==='target_unconfirmed')$envelope=['operation'=>HOTEL_ACTIVATION_KIND,'state'=>'HOLD','reason'=>'target_unconfirmed','operator_provider_calls'=>0];
    else{$envelope=hotelSeal($report);if($ownedWork&&$work!==null&&is_dir($work)&&!file_exists($work.'/result.sealed.json'))hotelWriteNew($work.'/result.sealed.json',json_encode($envelope,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));}
}catch(Throwable $ignored){$envelope=['operation'=>HOTEL_ACTIVATION_KIND,'state'=>'HOLD','reason'=>'sealed_activation_result_unconfirmed','operator_provider_calls'=>0];}
echo json_encode($envelope,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";exit($report['state']==='activated'&&$envelope['state']==='sealed'?0:1);
