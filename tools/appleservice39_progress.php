<?php
declare(strict_types=1);ini_set('display_errors','0');
try{
$root='/var/www/anytoour/data/www/app.anytoour.ru';
require_once $root.'/config.php';require_once $root.'/services/ConversationDb.php';
$p=ConversationDb::connection();$p->exec('SET TRANSACTION READ ONLY');$p->beginTransaction();
$q=$p->prepare("SELECT c.campaign_key FROM appleservice39_pilot_campaigns c JOIN conversation_sources s ON s.id=c.source_id JOIN projects r ON r.id=s.project_id JOIN manager_projects mp ON mp.project_id=r.id JOIN managers m ON m.id=mp.manager_id WHERE c.campaign_key='appleservice39-pilot-v1' AND c.project_key='appleservice39' AND c.bot_key='id390521129062_bot' AND c.channel_id='-80917954947727' AND m.id=2 AND m.role='admin' AND m.is_active=1 AND r.project_key='appleservice39' AND r.is_active=1 AND s.is_active=1");
$q->execute();$a=$q->fetchAll(PDO::FETCH_COLUMN);if(count($a)!==1)throw new RuntimeException('scope_changed');
$q=$p->prepare("SELECT post_key,state,write_attempts,external_message_id,public_url,outcome_json FROM appleservice39_pilot_operations WHERE campaign_key='appleservice39-pilot-v1' AND operation='create' ORDER BY id");$q->execute();$rows=$q->fetchAll(PDO::FETCH_ASSOC);
$verified=[];$held=[];$first=null;
foreach($rows as $r){if($r['state']==='verified'){$verified[]=['post_id'=>$r['post_key'],'url'=>$r['public_url']];}elseif(in_array($r['state'],['write_started','unknown','rejected'],true))$held[]=['post_id'=>$r['post_key'],'state'=>$r['state']];if($r['post_key']==='model-iphone-8'){$o=json_decode($r['outcome_json'],true);$first=['post_id'=>$r['post_key'],'state'=>$r['state'],'url'=>$r['public_url'],'original_attempt_outcome'=>$o['original_attempt_outcome']??null,'total_write_attempts'=>$o['owner_authorized_retry']['total_write_attempts']??null];}}
$p->rollBack();echo json_encode(['ok'=>true,'read_only'=>true,'verified_posts'=>count($verified),'new_verified'=>count($verified)-30,'held'=>$held,'first_model'=>$first,'verified'=>$verified,'database_writes'=>0,'provider_writes'=>0],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $e){if(isset($p)&&$p->inTransaction())$p->rollBack();echo '{"ok":false,"read_only":true,"reason":"progress_unavailable"}'.PHP_EOL;exit(1);}
