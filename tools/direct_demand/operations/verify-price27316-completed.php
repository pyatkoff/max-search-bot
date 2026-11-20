<?php
declare(strict_types=1);
// Independent readback only. Bundled with frozen helpers, never either write driver.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$out=['ok'=>false,'mode'=>'verify_completed_read_only','max_puts'=>0,'max_gets'=>0,'new_posts'=>0,'supplier_calls'=>0,'database_write_calls'=>0,'journal_appends'=>0,'feed_resume_calls'=>0,'telegram_edit_attempts'=>0];
$locks=[];$pdo=null;
try {
    reconcile_need(count($argv)===1,'verify_arguments_invalid');
    $out['runtime_sha']=reconcile_runtime();
    [$globalLock]=reconcile_open(RECONCILE_ROOT.'/monitor-price-after-collection-v1.jsonl',500000000,false,$locks,true);
    $hash=hash_init('sha256');$bytes=0;$lines=0;$last='';
    while($bytes<443818242&&($line=fgets($globalLock))!==false){
        reconcile_need(str_ends_with($line,"\n"),'verify_prefix_line_invalid');
        $bytes+=strlen($line);$lines++;hash_update($hash,$line);$last=$line;
    }
    reconcile_need($bytes===443818242&&$lines===4352&&hash_final($hash)===RECONCILE_GLOBAL_SHA
        &&hash('sha256',$last)==='5660cc6dad9e6a19900dd251da2cd0d8470182f114ac2dc98a2cccf2e38314b5','verify_original_global_prefix_changed');
    $originalGlobal=json_decode($last,true,64,JSON_THROW_ON_ERROR);
    $suffix=stream_get_contents($globalLock,5000001);
    reconcile_need(is_string($suffix)&&strlen($suffix)>0&&strlen($suffix)<=5000000&&feof($globalLock)&&str_ends_with($suffix,"\n"),'verify_global_suffix_invalid');
    $files=null;$feedOperation=null;$global=null;$starts=0;$firstStart=null;
    foreach(explode("\n",substr($suffix,0,-1)) as $line){
        $entry=json_decode($line,true,64,JSON_THROW_ON_ERROR);$global=$entry;
        if(($entry['pending']['operation']??null)===RECONCILE_OPERATION&&($entry['pending']['phase']??null)==='feed_started'){
            $starts++;
            if($starts===1){$firstStart=$entry;}
            elseif($starts===2){$expectedFailure=$firstStart;$expectedFailure['pending']['child_failure']=['exit_code'=>2,'error'=>'monitor_receipt_busy'];reconcile_need($entry===$expectedFailure,'verify_unknown_feed_failure');}
            else{throw new RuntimeException('verify_extra_feed_start');}
            $files=$entry['pending']['files'];$feedOperation=$entry['pending']['feed_operation'];
        }
    }
    reconcile_need($starts===2&&is_array($global)&&$global['pending']===null&&count($global['completed'])===589
        &&($global['completed'][RECONCILE_TV]['operation']??null)===RECONCILE_OPERATION
        &&($global['completed'][RECONCILE_TV]['feed_operation']??null)===$feedOperation,'verify_feed_completion_changed');
    $oldOthers=$originalGlobal['completed'];$newOthers=$global['completed'];unset($oldOthers[RECONCILE_TV],$newOthers[RECONCILE_TV]);
    reconcile_need(count($oldOthers)===588&&$oldOthers===$newOthers&&($global['holds']??[])===($originalGlobal['holds']??[]),'verify_other_hotels_changed');
    [$childLock,$raw]=reconcile_open(RECONCILE_ROOT.'/'.RECONCILE_OPERATION.'/receipt.jsonl',10000000,false,$locks);
    reconcile_need(strlen($raw)>40405&&hash('sha256',substr($raw,0,40405))===RECONCILE_ORIGINAL_SHA&&str_ends_with($raw,"\n"),'verify_child_prefix_changed');
    $originalLines=explode("\n",rtrim(substr($raw,0,40405),"\n"));
    $originalChild=json_decode(end($originalLines),true,64,JSON_THROW_ON_ERROR);
    $childLines=explode("\n",substr($raw,0,-1));$child=json_decode(end($childLines),true,64,JSON_THROW_ON_ERROR);
    reconcile_need(($child['complete']??null)===true&&($child['manifest_hash']??null)===RECONCILE_MANIFEST
        &&($child['monitor_snapshot']??null)===$originalChild['monitor_snapshot']
        &&($child['reconciliation']['max_puts']??null)===0&&($child['reconciliation']['max_confirm_calls']??null)===1
        &&($child['reconciliation']['telegram_first_edit_attempts']??null)===1,'verify_child_completion_changed');
    $binding=$child['feed_binding']+['operation'=>RECONCILE_OPERATION,'manifest_hash'=>RECONCILE_MANIFEST];
    monitor_price_confirmed_journal($child,$binding,time());
    ob_start();try{
        require RECONCILE_RUNTIME.'/config.php';require_once RECONCILE_RUNTIME.'/services/ChannelPostRuntime.php';
        require_once RECONCILE_RUNTIME.'/services/ChannelPostAdminService.php';require_once RECONCILE_RUNTIME.'/services/ChannelPostEntryService.php';
        require_once RECONCILE_RUNTIME.'/integrations/ChannelPostApiTransport.php';require_once RECONCILE_RUNTIME.'/services/ChannelPostButtonTransport.php';
    }finally{ob_end_clean();}
    $pdo=ConversationDb::connection();$pdo->exec('SET SESSION TRANSACTION READ ONLY');$pdo->beginTransaction();$out['database_session_read_only']=true;
    $registry=new ChannelPostRegistryService($pdo);$receipts=[];
    foreach(['max','telegram'] as $platform){
        $post=$child['posts'][$platform.':'.RECONCILE_KEY]??null;
        reconcile_need(is_array($post)&&$post['state']==='confirmed','verify_post_not_confirmed');
        $plan=$post['plan'];$id=$platform==='max'?400:395;
        reconcile_need(($plan['publication_id']??null)===$id&&($plan['key']??null)===RECONCILE_KEY
            &&($plan['logical_id']??null)===RECONCILE_LOGICAL_ID,'verify_target_plan_changed');
        $p=$registry->publication($id);
        reconcile_need(is_array($p)&&$p['platform']===$platform&&$p['project_key']==='anytour'
            &&$p['bot_key']===($platform==='max'?'id9704048781_1_bot':'anyru_bot')
            &&$p['channel_id']===($platform==='max'?'-80492244209683':'-1002262666616')
            &&($p['snapshot']['metadata']['monitor_price_record_id']??null)===RECONCILE_RECORD
            &&(int)$p['version_id']===(int)$post['receipt']['version_id'],'verify_registry_record_changed');
        $receipts[$platform]=reconcile_final_receipt($registry,$p,$plan);
        if($platform==='max')reconcile_read_max($plan,$p,$out);
        else {
            $message=$post['provider_response']['data']['result']??null;
            reconcile_need(is_array($message)&&(string)($message['chat']['id']??'')==='-1002262666616'
                &&($message['chat']['username']??null)==='any_toour'&&(string)($message['message_id']??'')==='156','verify_telegram_receipt_identity_changed');
            $snapshot=ChannelPostIncomingAdapter::telegramSnapshot($message);
            reconcile_need(is_array($snapshot)&&snapshotMatches($p,$snapshot,$plan),'verify_telegram_receipt_not_proven');
            $link=monitorEditorPriceLink($plan['text'],'telegram',ChannelPostButtonTransport::buttonUrl($p));
            reconcile_need(monitorEditorPriceLinkVerified('telegram',$message,$plan['text'],$link),'verify_telegram_price_link_changed');
            // Public TG pages may be blocked/redirected and are not a provider-authenticated GET API.
            // Never turn a failed optional scrape into a false claim of live Telegram verification.
            $out['telegram_verification']=['authenticated_edit_response_in_durable_journal'=>true,'current_registry_and_token'=>true,'fresh_provider_get'=>false,'public_page_checked'=>false];
        }
    }
    reconcile_need(is_array($files)&&array_column($files,'name')===['hotel-posts-telegram.xml','hotel-posts-max.xml','hotel-posts-all.xml'],'verify_feed_file_scope_changed');
    $out['feeds']=[];
    foreach($files as $file){
        reconcile_need(is_string($file['sha256']??null)&&preg_match('/\A[a-f0-9]{64}\z/D',$file['sha256'])===1,'verify_feed_hash_invalid');
        $path=getcwd().'/feed/'.$file['name'];
        reconcile_need(!is_link($path)&&is_file($path)&&hash_file('sha256',$path)===$file['sha256'],'verify_local_feed_changed');
        $public=hook_get('https://app.anytoour.ru/feed/'.$file['name']);
        reconcile_need(hash('sha256',$public)===$file['sha256'],'verify_public_feed_changed');
        $out['feeds'][]=['name'=>$file['name'],'sha256'=>$file['sha256'],'local_and_public_match'=>true];
    }
    reconcile_assert_prefix($childLock,substr($raw,0,40405));reconcile_assert_global_prefix($globalLock);
    $out['ok']=true;$out['original_operation']=RECONCILE_OPERATION;$out['original_record']=RECONCILE_RECORD;
    $out['original_global_prefix_preserved']=true;$out['original_child_prefix_preserved']=true;$out['previous_hotels_preserved']=588;
    $out['pending_cleared']=true;$out['child_complete']=true;$out['feed_operation']=$feedOperation;
    $out['current_receipts']=$receipts;$out['nightly_cycle_state']=$global['nightly_cycle']['state']??null;
    $out['closure_verified']=false;$out['checked_at']=gmdate('c');
}catch(Throwable $e){$out['error']=preg_match('/\A[a-z][a-z0-9_]{1,120}\z/D',$e->getMessage())?$e->getMessage():'verify_failed';}
if($pdo instanceof PDO&&$pdo->inTransaction()){$pdo->rollBack();$out['database_transaction_rolled_back']=true;}
foreach(array_reverse($locks) as $lock)if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}
echo 'PRICE27316_COMPLETED_VERIFICATION='.reconcile_json($out)."\n";exit($out['ok']?0:2);
