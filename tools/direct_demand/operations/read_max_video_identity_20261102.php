<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(2);
ini_set('display_errors','0'); ini_set('log_errors','0'); error_reporting(E_ALL);
set_error_handler(static function (): void { throw new RuntimeException('read_probe_error'); });
ob_start(); $pdo=null; $out=['schema'=>'anytour.max_video_identity.v1','ok'=>false,'provider_writes'=>0,'database_writes'=>0];
const READ_ROOT='/var/www/anytoour/data/www/app.anytoour.ru';
const READ_TARGETS=[
 'FIRST'=>['channel'=>'-72182945320979','mid'=>'mid.ffffbe59992f6fed01a19c17752265c8','timestamp'=>'1793620145442'],
 'SECOND'=>['channel'=>'-68765999669267','mid'=>'mid.ffffc1752b045fed01a19c183f535af2','timestamp'=>'1793620197203'],
];
try {
 $target=getenv('MAX_REPAIR_TARGET') ?: ''; if(!isset(READ_TARGETS[$target]))throw new RuntimeException('invalid_target'); $t=READ_TARGETS[$target]; $out['target']=$target;
 require_once READ_ROOT.'/config.php'; require_once READ_ROOT.'/services/ConversationDb.php';
 $pdo=ConversationDb::connection(); $pdo->exec('START TRANSACTION READ ONLY');
 require_once READ_ROOT.'/services/ChannelPostRuntime.php'; require_once READ_ROOT.'/integrations/ChannelPostApiTransport.php';
 $identity=ChannelPostRuntime::identity('max');
 $q=$pdo->prepare("SELECT pp.id AS publication_id,pv.id AS version_id,pv.state,pv.error_reason,pv.write_attempts,pv.snapshot_json FROM post_publications pp JOIN post_versions pv ON pv.id=pp.current_version_id AND pv.publication_id=pp.id JOIN post_channels pc ON pc.id=pp.post_channel_id JOIN projects p ON p.id=pc.project_id WHERE p.project_key=? AND pc.platform='max' AND pc.bot_key=? AND pc.channel_id=? AND pp.external_message_id=? LIMIT 2");
 $q->execute([$identity['project_key'],$identity['bot_key'],$t['channel'],$t['mid']]); $rows=$q->fetchAll(PDO::FETCH_ASSOC); if(count($rows)!==1)throw new RuntimeException('registry_identity');
 $row=$rows[0]; $old=json_decode((string)$row['snapshot_json'],true); unset($row['snapshot_json']); $out['registry']=$row;
 $out['stored']=videoReadSummary(is_array($old['raw_body'] ?? null)?$old['raw_body']:[]);
 $out['reads']=[];
 for($i=0;$i<2;$i++){
  $r=ChannelPostApiTransport::buttonRequest('max','GET','/messages/'.rawurlencode($t['mid']),[],null);
  if(!empty($r['transport_error']) || (int)($r['http']??0)!==200 || !is_array($r['data']??null))throw new RuntimeException('provider_read');
  $m=$r['data']; if(($m['body']['mid']??'')!==$t['mid'] || (string)($m['recipient']['chat_id']??'')!==$t['channel'] || (string)($m['timestamp']??'')!==$t['timestamp'])throw new RuntimeException('provider_identity');
  $out['reads'][]=videoReadSummary($m);
 }
 $out['ok']=true;
}catch(Throwable $e){$out['reason']='read_probe_failed';}
if($pdo instanceof PDO && $pdo->inTransaction())$pdo->rollBack();
if(ob_get_level())ob_end_clean(); echo json_encode($out,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL; exit($out['ok']?0:1);
function videoReadSummary(array $m): array {
 $b=is_array($m['body']??null)?$m['body']:[]; $out=['text_hash'=>hash('sha256',json_encode($b['text']??null)),'markup_hash'=>hash('sha256',json_encode($b['markup']??null)),'format_hash'=>hash('sha256',json_encode($b['format']??null)),'link_hash'=>hash('sha256',json_encode($m['link']??null)),'videos'=>[],'keyboards'=>0];
 foreach(($b['attachments']??[]) as $a){if(($a['type']??'')==='video')$out['videos'][]=$a['payload']??[];if(($a['type']??'')==='inline_keyboard')$out['keyboards']++;}
 return $out;
}
