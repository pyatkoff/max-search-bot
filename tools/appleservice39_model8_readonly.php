<?php
declare(strict_types=1);
// One fixed read-only observation. Never adopts, resets, creates, edits or sends.
ini_set('display_errors', '0');
$root='/var/www/anytoour/data/www/app.anytoour.ru';
$expected='0a397bd1b2b84648bd5ca23dcd113d567d0cc5bd';
$pdo=null;
function demand(bool $ok,string $reason): void { if(!$ok)throw new RuntimeException($reason); }
function gitRead(array $args): string {
    $p=proc_open(array_merge(['git','-C','/var/www/anytoour/data/www/app.anytoour.ru'],$args),[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
    demand(is_resource($p),'checkout_unavailable');fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[2]);
    demand(proc_close($p)===0,'checkout_unavailable');return trim($out);
}
try {
    demand(PHP_SAPI==='cli'&&realpath($root)===$root,'cli_target_required');
    demand(gitRead(['rev-parse','HEAD'])===$expected&&gitRead(['status','--porcelain','--untracked-files=no'])==='','checkout_changed');
    require_once $root.'/config.php';
    require_once $root.'/services/ConversationDb.php';
    require_once $root.'/services/AppleService39PilotPublisher.php';
    $connection=ProjectMaxConnection::resolve('appleservice39');
    demand($connection!==null&&$connection->botKey()==='id390521129062_bot'&&$connection->baseUrl()==='https://platform-api2.max.ru','connection_changed');
    $pdo=ConversationDb::connection();$pdo->exec('SET TRANSACTION READ ONLY');$pdo->beginTransaction();
    $store=new AppleService39PilotPublicationStore($pdo);$campaign=$store->campaign();
    demand(is_array($campaign)&&$campaign['project_key']==='appleservice39'&&$campaign['bot_key']==='id390521129062_bot'&&(string)$campaign['channel_id']==='-80917954947727','campaign_changed');
    $row=$store->operation('model-iphone-8','create');
    demand(is_array($row)&&$row['state']==='unknown'&&(int)$row['write_attempts']===1&&$row['external_message_id']===null&&$row['public_url']===null,'original_unknown_changed');
    $source=$pdo->prepare("SELECT s.id FROM managers m JOIN manager_projects mp ON mp.manager_id=m.id JOIN projects p ON p.id=mp.project_id JOIN conversation_sources s ON s.project_id=p.id WHERE m.id=2 AND m.role='admin' AND m.is_active=1 AND p.project_key=? AND p.is_active=1 AND s.source_key=? AND s.channel='max' AND s.is_active=1 AND s.handling_mode IN ('manager','ask')");
    $source->execute([AppleService39PilotManifest::PROJECT,AppleService39PilotManifest::SOURCE]);$ids=$source->fetchAll(PDO::FETCH_COLUMN);
    demand(count($ids)===1&&(int)$ids[0]===(int)$campaign['source_id']&&(int)$campaign['admin_manager_id']===2,'campaign_changed');
    $started=(int)$row['started_ms'];
    demand($started>=1794482880000&&$started<=1794483000000&&($row['request']['revision']??null)==='coverage-v1','original_request_changed');
    $request=$row['request'];$requestHash=$row['payload_sha256'];
    demand($store->coverageRequest('model-iphone-8','create')===$request,'original_request_changed');
    demand(hash('sha256',json_encode($request,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))===$requestHash,'request_hash_changed');
    $transport=new AppleService39ChannelTransport($connection,'-80917954947727');
    $me=$transport->me();$channel=$transport->channel();$member=$transport->membership();
    demand(AppleService39ChannelTransport::accepted($me)&&($me['data']['is_bot']??null)===true&&($me['data']['username']??null)==='id390521129062_bot','bot_identity_changed');
    demand(AppleService39ChannelTransport::accepted($channel)&&(string)($channel['data']['chat_id']??'')==='-80917954947727'&&($channel['data']['type']??null)==='channel','channel_identity_changed');
    demand(AppleService39ChannelTransport::accepted($member)&&($member['data']['is_admin']??null)===true,'channel_admin_required');
    $raw='';$curl=curl_init('https://platform-api2.max.ru/messages?chat_id=-80917954947727&count=100');
    demand($curl!==false,'provider_read_unavailable');
    curl_setopt_array($curl,MaxTlsConfig::strictCurlOptions()+[
        CURLOPT_HTTPGET=>true,CURLOPT_HTTPHEADER=>['Accept: application/json','Authorization: '.$connection->token()],
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>10,
        CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>static function($h,string $chunk)use(&$raw):int{if(strlen($raw)+strlen($chunk)>2097152)return 0;$raw.=$chunk;return strlen($chunk);}
    ]);
    $done=curl_exec($curl);$http=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);$failed=$done===false||curl_errno($curl)!==0;curl_close($curl);
    $data=json_decode($raw,true,32,JSON_THROW_ON_ERROR);unset($raw);
    demand(!$failed&&$http===200&&is_array($data)&&is_array($data['messages']??null)&&count($data['messages'])<=100,'provider_read_unavailable');
    $candidates=[];$timestamps=[];
    foreach($data['messages'] as $message){
        if(!is_array($message))throw new RuntimeException('provider_read_invalid');
        $timestamp=$message['timestamp']??null;if(is_int($timestamp))$timestamps[]=$timestamp;
        if(($message['body']['text']??null)!==($request['native_text']??null))continue;
        $mid=$message['body']['mid']??null;$url=$message['url']??null;
        demand(is_string($mid)&&preg_match('/\A[A-Za-z0-9_.-]{1,191}\z/D',$mid)===1,'provider_identity_invalid');
        $photo=null;$tokenMatch=false;$buttons=[];
        foreach($message['body']['attachments']??[] as $attachment){
            if(($attachment['type']??null)==='image'){$photo=$attachment['payload']['photo_id']??null;$token=$attachment['payload']['token']??null;$tokenMatch=is_string($token)&&hash_equals($request['upload_token_sha256'],hash('sha256',$token));}
            if(($attachment['type']??null)==='inline_keyboard')$buttons=$attachment['payload']['buttons']??[];
        }
        $expectedButtons=[];foreach($request['body']['attachments']??[] as $attachment)if(($attachment['type']??null)==='inline_keyboard')$expectedButtons=$attachment['payload']['buttons']??[];
        $sender=$message['sender']??null;
        $candidates[]=['message_id'=>$mid,'url'=>is_string($url)&&preg_match('~\Ahttps://max\.ru/channel_appleservice39ru/[A-Za-z0-9_-]{1,191}\z~D',$url)===1?$url:null,
            'timestamp'=>$timestamp,'in_original_window'=>is_int($timestamp)&&$timestamp>=$started-2000&&$timestamp<=$started+120000,
            'recipient_matches'=>(string)($message['recipient']['chat_id']??'')==='-80917954947727'&&($message['recipient']['chat_type']??null)==='channel',
            'sender_matches'=>$sender===null||is_array($sender)&&(string)($sender['user_id']??'')===(string)$me['data']['user_id'],
            'text_matches'=>true,'markup_matches'=>AppleService39LandingRevision::sameMarkup($message['body']['markup']??null,$request['native_markup']),
            'buttons_match'=>$buttons===$expectedButtons,'photo_id'=>is_scalar($photo)?(string)$photo:null,'original_upload_token_matches'=>$tokenMatch];
    }
    $after=$store->operation('model-iphone-8','create');demand($after===$row,'original_state_changed');
    $pdo->rollBack();$pdo=null;
    demand(gitRead(['rev-parse','HEAD'])===$expected&&gitRead(['status','--porcelain','--untracked-files=no'])==='','checkout_changed');
    echo json_encode(['ok'=>true,'observation_only'=>true,'application_sha'=>$expected,'original_run'=>43208426540,'post_id'=>'model-iphone-8',
        'original_request_sha256'=>$requestHash,'original_started_ms'=>$started,'retained_state'=>'unknown','write_attempts'=>1,
        'returned_messages'=>count($data['messages']),'oldest_returned_ms'=>$timestamps===[]?null:min($timestamps),'newest_returned_ms'=>$timestamps===[]?null:max($timestamps),
        'candidates'=>$candidates,'provider_writes'=>0,'database_writes'=>0,'original_state_unchanged'=>true,'absence_proven'=>false],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $error){
    if($pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();
    $allowed=['cli_target_required','checkout_unavailable','checkout_changed','connection_changed','campaign_changed','original_unknown_changed','original_request_changed','request_hash_changed','bot_identity_changed','channel_identity_changed','channel_admin_required','provider_read_unavailable','provider_read_invalid','provider_identity_invalid','original_state_changed'];
    echo json_encode(['ok'=>false,'reason'=>in_array($error->getMessage(),$allowed,true)?$error->getMessage():'readonly_inspection_failed','provider_writes'=>0,'database_writes'=>0]).PHP_EOL;exit(1);
}
