<?php
declare(strict_types=1);
// Fixed, read-only preflight for the two directly authorized publication targets.
ini_set('display_errors','0');ini_set('log_errors','0');
$out=['operation'=>'content100-bot-publication-20261101-v1','mode'=>'read_only_preflight','ok'=>false,'post_write_attempts'=>0,'channels'=>[]];
try {
    if(PHP_SAPI!=='cli'||trim((string)shell_exec('git rev-parse HEAD'))!==EXPECTED_INSTALLED_SHA)throw new RuntimeException('installed_source_changed');
    ob_start();try {
        require getcwd().'/config.php';
        require_once getcwd().'/services/ChannelPostRuntime.php';
        require_once getcwd().'/integrations/ChannelPostApiTransport.php';
        require_once getcwd().'/services/ChannelPostButtonTransport.php';
    } finally {ob_end_clean();}
    $pdo=ConversationDb::connection();$pdo->exec('START TRANSACTION READ ONLY');
    $registry=new ChannelPostRegistryService($pdo);
    foreach(['max','telegram'] as $platform){
        $identity=ChannelPostRuntime::identity($platform);
        $row=['platform'=>$platform,'target'=>$platform==='max'?'https://max.ru/channel_anytour':'https://t.me/any_toour','ready'=>false];
        if(!$identity){$row['reason']='runtime_identity_unavailable';$out['channels'][]=$row;continue;}
        $row['configured_bot']=$identity['bot_key'];
        $expectedBot=$platform==='max'?'id9704048781_1_bot':'anyru_bot';
        if($identity['project_key']!=='anytour'||$identity['bot_key']!==$expectedBot){$row['reason']='configured_bot_identity_mismatch';$out['channels'][]=$row;continue;}
        $response=ChannelPostApiTransport::buttonRequest($platform,$platform==='max'?'GET':'POST',$platform==='max'?'/me':'getMe',[],$platform==='max'?null:[]);
        $me=$platform==='max'?($response['data']??null):($response['data']['result']??null);
        if(!empty($response['transport_error'])||$response['http']!==200||!is_array($me)||strtolower((string)($me['username']??''))!==strtolower($expectedBot)){$row['reason']='provider_bot_identity_unconfirmed';$out['channels'][]=$row;continue;}
        $id=$platform==='max'?'-80492244209683':null;
        if($platform==='telegram'){
            $r=ChannelPostApiTransport::buttonRequest('telegram','POST','getChat',[],['chat_id'=>'@any_toour']);
            $chat=$r['data']['result']??null;
            if(!empty($r['transport_error'])||$r['http']!==200||!is_array($chat)||($chat['type']??'')!=='channel'||strtolower((string)($chat['username']??''))!=='any_toour'){$row['reason']='telegram_channel_not_resolved_for_bot';$out['channels'][]=$row;continue;}
            $id=(string)$chat['id'];
            $member=ChannelPostApiTransport::buttonRequest('telegram','POST','getChatMember',[],['chat_id'=>$id,'user_id'=>$me['id']]);
            $rights=$member['data']['result']??[];
            $row['bot_membership']=$rights['status']??'unconfirmed';
            $row['can_post_messages']=($rights['can_post_messages']??false)===true;
            $row['can_edit_messages']=($rights['can_edit_messages']??false)===true;
        }
        $row['channel_id']=$id;
        $channel=$registry->channel('anytour',$platform,$expectedBot,$id,false);
        if(!is_array($channel)){
            $row['reason']='channel_not_registered';
            $q=ConversationDb::connection()->prepare("SELECT s.source_key,s.display_name,s.handling_mode FROM conversation_sources s JOIN projects p ON p.id=s.project_id WHERE p.project_key=? AND s.channel=? AND s.is_active=1 AND s.handling_mode IN ('manager','ask') ORDER BY s.id LIMIT 20");
            $q->execute(['anytour',$platform]);$row['existing_source_choices']=$q->fetchAll(PDO::FETCH_ASSOC);
            $out['channels'][]=$row;continue;
        }
        foreach(['id','config_version','source_key','button_text','is_enabled'] as $key)$row[$key]=$channel[$key]??null;
        $verification=(new ChannelPostButtonTransport())->verifyChannel($channel);
        $row['verification_state']=$verification['state']??'unconfirmed';
        $row['ready']=($verification['state']??'')==='ready';
        if($platform==='telegram'&&empty($row['can_post_messages'])){$row['ready']=false;$row['reason']='bot_requires_channel_posting_right';}
        $out['channels'][]=$row;
    }
    $out['ok']=true;$out['installed_sha']=EXPECTED_INSTALLED_SHA;
} catch(Throwable $e){$out['error']=$e->getMessage()==='installed_source_changed'?'installed_source_changed':'bounded_read_only_preflight_failed';}
if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
exit($out['ok']?0:2);
