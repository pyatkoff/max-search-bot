<?php
declare(strict_types=1);
// Streamed CLI reader only. No installation, imports, workers, writes or customer data.
if (PHP_SAPI !== 'cli') exit(2);
ini_set('display_errors', 'stderr');
ini_set('log_errors', '0');
$root='/var/www/anytoour/data/www/app.anytoour.ru';
$expected='f31769918fb0aadfcc09f9bb2a305111a9c58fc7';
$sha=trim((string)shell_exec('git -C '.escapeshellarg($root).' rev-parse HEAD 2>/dev/null'));
$dirty=trim((string)shell_exec('git -C '.escapeshellarg($root).' status --porcelain --untracked-files=no 2>/dev/null'));
if ($sha!==$expected || $dirty!=='') {echo json_encode(['ok'=>false,'reason'=>'installed_checkout_changed','installed_sha'=>$sha]);exit(2);}
ob_start();
try {
    require_once $root.'/config.php';
    require_once $root.'/services/ChannelPostRuntime.php';
    require_once $root.'/integrations/ChannelPostApiTransport.php';
    $identities=ChannelPostRuntime::botIdentities();
    if (!$identities || !ConversationDb::isConfigured()) throw new RuntimeException('configuration_unavailable');
    $pdo=ConversationDb::connection();
    $pdo->exec('START TRANSACTION READ ONLY');
    $q=$pdo->prepare('SELECT pc.id,pc.platform,pc.bot_key,pc.channel_id,pc.display_name,pc.button_text,pc.is_enabled,pc.config_version FROM post_channels pc JOIN projects p ON p.id=pc.project_id WHERE p.project_key=? AND p.is_active=1 ORDER BY pc.platform,pc.id LIMIT 21');
    $q->execute([ProjectConfig::projectId()]);$channels=$q->fetchAll(PDO::FETCH_ASSOC);
    if(count($channels)>20)throw new RuntimeException('channel_limit');
    $out=['ok'=>true,'schema'=>'anytour.funnel.native_channel_read.v1','generated_at_utc'=>gmdate('c'),'installed_sha'=>$sha,'read_only'=>true,'limits'=>['channels'=>20,'saved_posts_per_channel'=>200,'max_history_pages_per_channel'=>10,'max_history_page_size'=>100],'channels'=>[]];
    foreach($channels as $channel){
        $platform=(string)$channel['platform'];$identity=$identities[$platform]??null;
        if(!$identity || $identity['bot_key']!==$channel['bot_key'])continue;
        $count=$pdo->prepare('SELECT COUNT(*) FROM post_publications WHERE post_channel_id=?');$count->execute([$channel['id']]);
        $channel['saved_publication_total']=(int)$count->fetchColumn();
        $posts=$pdo->prepare('SELECT pp.id AS publication_id,pp.external_message_id,pv.version_number,pv.state,pv.error_reason,pv.content_hash,pv.snapshot_json,pv.created_at FROM post_publications pp JOIN post_versions pv ON pv.id=pp.current_version_id WHERE pp.post_channel_id=? ORDER BY pp.id DESC LIMIT 200');
        $posts->execute([$channel['id']]);$saved=[];
        foreach($posts->fetchAll(PDO::FETCH_ASSOC)as $row){$row['snapshot']=trimSnapshot(json_decode((string)$row['snapshot_json'],true));unset($row['snapshot_json']);$saved[]=$row;}
        $channel['saved_posts']=$saved;$channel['saved_posts_truncated']=$channel['saved_publication_total']>count($saved);
        $channel['bot_rights']=rights($platform,(string)$channel['channel_id'],$identity['bot_key']);
        $channel['history_posts']=[];$channel['history_complete']=false;
        if($platform==='max' && !empty($channel['bot_rights']['read_ready'])){
            $upper=(int)floor(microtime(true)*1000);$seen=[];
            for($page=0;$page<10;$page++){
                $r=ChannelPostApiTransport::buttonRequest('max','GET','/messages',['chat_id'=>(string)$channel['channel_id'],'from'=>$upper,'to'=>1,'count'=>100],null);
                if(!readable($r,'max')){$channel['history_error']=['page'=>$page+1,'http'=>(int)($r['http']??0)];break;}
                $messages=$r['data']['messages']??null;if(!is_array($messages)){$channel['history_error']=['page'=>$page+1,'reason'=>'invalid_list'];break;}
                $oldest=$upper;$boundary=[];
                foreach($messages as $m){
                    if((string)($m['recipient']['chat_id']??'')!==(string)$channel['channel_id'] || ($m['recipient']['chat_type']??'')!=='channel')continue;
                    $mid=(string)($m['body']['mid']??'');$ts=(int)($m['timestamp']??0);if($mid===''||$ts<=0)continue;
                    if($ts<$oldest){$oldest=$ts;$boundary=[$mid];}elseif($ts===$oldest)$boundary[]=$mid;
                    if(isset($seen[$mid]))continue;$seen[$mid]=true;
                    $snapshot=ChannelPostIncomingAdapter::maxSnapshot($m);
                    if(is_array($snapshot))$channel['history_posts'][]=['external_message_id'=>$mid,'published_ms'=>$ts,'snapshot'=>trimSnapshot($snapshot)];
                }
                $channel['history_pages_read']=$page+1;
                if(count($messages)<100){$channel['history_complete']=true;break;}
                if($oldest>=$upper || count($boundary)>1){$channel['history_pagination_hold']='inclusive_equal_timestamp_boundary';break;}
                $upper=$oldest;
            }
        }
        $out['channels'][]=$channel;
    }
    $pdo->rollBack();
    $out['summary']=['channels'=>count($out['channels']),'saved_posts'=>array_sum(array_map(static fn($c)=>count($c['saved_posts']),$out['channels'])),'max_history_posts'=>array_sum(array_map(static fn($c)=>count($c['history_posts']),$out['channels']))];
    ob_end_clean();$encoded=json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    if(strlen($encoded)>12000000)throw new RuntimeException('output_limit');
    echo $encoded.PHP_EOL;
}catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();if(ob_get_level())ob_end_clean();echo json_encode(['ok'=>false,'reason'=>'channel_read_failed','installed_sha'=>$sha]).PHP_EOL;exit(2);}

function readable(array $r,string $platform):bool{return empty($r['transport_error'])&&(int)($r['http']??0)>=200&&(int)($r['http']??0)<300&&is_array($r['data']??null)&&($platform!=='telegram'||($r['data']['ok']??false)===true);}
function rights(string $platform,string $channelId,string $bot):array{
    $me=ChannelPostApiTransport::buttonRequest($platform,$platform==='max'?'GET':'POST',$platform==='max'?'/me':'getMe',[],$platform==='max'?null:[]);
    if(!readable($me,$platform))return ['read_ready'=>false,'http'=>(int)($me['http']??0),'reason'=>'identity_read_failed'];
    $m=$platform==='max'?$me['data']:$me['data']['result'];
    if(($m['is_bot']??false)!==true||($m['username']??'')!==$bot)return ['read_ready'=>false,'reason'=>'identity_mismatch'];
    $r=ChannelPostApiTransport::buttonRequest($platform,$platform==='max'?'GET':'POST',$platform==='max'?'/chats/'.$channelId.'/members/me':'getChatMember',[],$platform==='max'?null:['chat_id'=>$channelId,'user_id'=>$m['id']]);
    if(!readable($r,$platform))return ['read_ready'=>false,'http'=>(int)($r['http']??0),'reason'=>'rights_read_failed'];
    $a=$platform==='max'?$r['data']:$r['data']['result'];
    if($platform==='max'){$permissions=(array)($a['permissions']??[]);$admin=($a['is_admin']??false)===true;return ['is_admin'=>$admin,'read_ready'=>$admin&&in_array('read_all_messages',$permissions,true),'edit_ready'=>$admin&&(in_array('edit',$permissions,true)||in_array('edit_message',$permissions,true))];}
    return ['is_admin'=>in_array($a['status']??'',['creator','administrator'],true),'read_ready'=>false,'history_api_available'=>false,'edit_ready'=>($a['status']??'')==='creator'||($a['can_edit_messages']??false)===true];
}
function trimSnapshot($s):array{
    if(!is_array($s))return [];$out=[];
    foreach(['text','attachments','keyboard','markup','metadata','forward','post_url','format']as $key)if(array_key_exists($key,$s))$out[$key]=dropTokens($s[$key]);
    return $out;
}
function dropTokens($v){if(!is_array($v))return $v;$r=[];foreach($v as $k=>$x){if(in_array((string)$k,['token','access_token','claim_token','start_payload'],true))continue;$r[$k]=dropTokens($x);}return $r;}
