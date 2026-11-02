<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
ini_set('display_errors','0');ini_set('log_errors','0');error_reporting(E_ALL);
set_error_handler(static function():void{throw new RuntimeException('acceptance_read_failed');});
ob_start();$pdo=null;$out=['schema'=>'anytour.max_video_acceptance.v1','ok'=>false,'provider_writes'=>0,'database_writes'=>0];
const ACCEPT_ROOT='/var/www/anytoour/data/www/app.anytoour.ru';
const ACCEPT_TARGETS=[
 'FIRST'=>['channel'=>'-72182945320979','mid'=>'mid.ffffbe59992f6fed01a19c17752265c8','timestamp'=>'1793620145442','original_version'=>230],
 'SECOND'=>['channel'=>'-68765999669267','mid'=>'mid.ffffc1752b045fed01a19c183f535af2','timestamp'=>'1793620197203','original_version'=>231],
];
try{
 $target=getenv('MAX_REPAIR_TARGET')?:'';if(!isset(ACCEPT_TARGETS[$target]))throw new RuntimeException('invalid_target');$t=ACCEPT_TARGETS[$target];$out['target']=$target;
 require_once ACCEPT_ROOT.'/config.php';require_once ACCEPT_ROOT.'/services/ConversationDb.php';
 $pdo=ConversationDb::connection();$pdo->exec('START TRANSACTION READ ONLY');
 require_once ACCEPT_ROOT.'/services/ChannelPostRuntime.php';require_once ACCEPT_ROOT.'/services/ChannelPostButtonTransport.php';
 $identity=ChannelPostRuntime::identity('max');
 $q=$pdo->prepare("SELECT pp.id AS publication_id,pp.start_payload,pv.id AS version_id,pv.state,pv.write_attempts,orig.snapshot_json AS original_snapshot_json,pc.bot_url,pc.button_text FROM post_publications pp JOIN post_versions pv ON pv.id=pp.current_version_id AND pv.publication_id=pp.id JOIN post_versions orig ON orig.id=? AND orig.publication_id=pp.id JOIN post_channels pc ON pc.id=pp.post_channel_id JOIN projects p ON p.id=pc.project_id WHERE p.project_key=? AND pc.platform='max' AND pc.bot_key=? AND pc.channel_id=? AND pp.external_message_id=? LIMIT 2");
 $q->execute([$t['original_version'],$identity['project_key'],$identity['bot_key'],$t['channel'],$t['mid']]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);if(count($rows)!==1)throw new RuntimeException('registry_identity');$row=$rows[0];
 $url=ChannelPostButtonTransport::buttonUrl(['start_payload'=>$row['start_payload'],'channel'=>['bot_url'=>$row['bot_url']]]);if($url==='')throw new RuntimeException('button_identity');
 $old=json_decode((string)$row['original_snapshot_json'],true);$raw=$old['raw_body']??null;if(!is_array($raw))throw new RuntimeException('original_snapshot');
 $original=ChannelPostIncomingAdapter::maxSnapshot($raw);if(!is_array($original)||($original['metadata']['capability']??'')!=='supported')throw new RuntimeException('original_media_identity');
 $originalHash=ChannelPostIncomingAdapter::contentFingerprint($original,$url,(string)$row['bot_url']);$hashes=[];$buttons=[];$tokens=[];$preserved=[];
 for($i=0;$i<2;$i++){
  $r=ChannelPostApiTransport::buttonRequest('max','GET','/messages/'.rawurlencode($t['mid']),[],null);
  if(!empty($r['transport_error'])||(int)($r['http']??0)!==200||!is_array($r['data']??null))throw new RuntimeException('provider_read');$m=$r['data'];
  if(($m['body']['mid']??'')!==$t['mid']||(string)($m['recipient']['chat_id']??'')!==$t['channel']||(string)($m['timestamp']??'')!==$t['timestamp'])throw new RuntimeException('provider_identity');
  $s=ChannelPostIncomingAdapter::maxSnapshot($m);if(!is_array($s)||($s['metadata']['capability']??'')!=='supported')throw new RuntimeException('fresh_media_identity');
  $hashes[]=ChannelPostIncomingAdapter::contentFingerprint($s,$url,(string)$row['bot_url']);$buttons[]=ChannelPostIncomingAdapter::containsOnlyButton($s,$url,(string)$row['button_text']);
  $preserved[]=$hashes[$i]===$originalHash;$vt=[];foreach(($m['body']['attachments']??[]) as $a)if(($a['type']??'')==='video')$vt[]=$a['payload']['token']??null;$tokens[]=$vt;
 }
 $out+=['publication_id'=>(int)$row['publication_id'],'current_version_id'=>(int)$row['version_id'],'registry_state'=>$row['state'],'registry_write_attempts'=>(int)$row['write_attempts'],'original_version_id'=>$t['original_version'],'provider_gets'=>2,'button_verified'=>$buttons,'original_content_preserved'=>$preserved,'fingerprint_stable'=>$hashes[0]===$hashes[1],'reusable_tokens_equal'=>$tokens[0]===$tokens[1]];
 $out['ok']=$buttons===[true,true]&&$preserved===[true,true]&&$hashes[0]===$hashes[1]&&$row['state']==='sent';
}catch(Throwable $e){$out['reason']='acceptance_read_failed';}
if($pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();if(ob_get_level())ob_end_clean();echo json_encode($out,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL;exit($out['ok']?0:1);
