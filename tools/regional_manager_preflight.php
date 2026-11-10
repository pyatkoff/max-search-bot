<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');ini_set('log_errors','0');error_reporting(0);
const LINK_CONTEXT='c11c1105804124449759d8327664fc731c3905f30f1c6dd1371eddb1fa965c34';
const RECIPIENT_PUBLIC_KEY=<<<'KEY'
-----BEGIN PUBLIC KEY-----
MIIBojANBgkqhkiG9w0BAQEFAAOCAY8AMIIBigKCAYEAyFF+OvrOZbKf5G2n8coS
l5TXQLsI3lDnVlqlNJDOalEMZhWMehwK4TUM9DILEeIudGAlPZHLIca/TbzSuU2+
/gFtN8q8pgFD703bOAHbLtlBg3iVB5qDnCMJXHlZ1Yrs44ZEaefDqvHDeLLuuJRd
nreBn8CGh8RVMLQsAPEepRRnq8rH63AvU2NiX62wxvAEGTvhIkb3OKcvM1HFEF41
SgEA9pGST585ZXECQ0wr4qB9BO3pXZ/UJXDlFOjn2MfoAdFAYE53zFEs3DvMvcZ3
u1NZhG2RnLWWpDg2lXxXWj78py5A1104ry3mOr/PL0pR1U8UOLgQ3/aZhwAX3BjF
Yhk4E0V3hEwD6GlmLeans2lAB/wQMXNNVHMQq8FfhmPusprkuaZ2IXzHwiSdO2Ux
xoSGzhACbswbocxpT05xI0ziKFrfTjcYys1AnS6PUbq+VafwXjvGdvzRTmtuaxlD
wHU0TS+nUDPV+5LMTfqe91PgrgIQDEdnlOXFoNvnvY0PAgMBAAE=
-----END PUBLIC KEY-----
KEY;
function must(bool $ok):void{if(!$ok)throw new RuntimeException('preflight_refused');}
function seal(array $payload):array{
 $plain=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
 must(strlen($plain)<=262144);
 $key=random_bytes(32);$nonce=random_bytes(12);$tag='';
 $cipher=openssl_encrypt($plain,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag,'anytour-regional-manager-preflight-v1',16);
 must(is_string($cipher)&&openssl_public_encrypt($key,$wrapped,RECIPIENT_PUBLIC_KEY,OPENSSL_PKCS1_OAEP_PADDING));
 return ['version'=>1,'algorithm'=>'RSA-OAEP-SHA1+A256GCM','encrypted_key'=>base64_encode($wrapped),'nonce'=>base64_encode($nonce),'tag'=>base64_encode($tag),'ciphertext'=>base64_encode($cipher)];
}
try{
 set_time_limit(45);$root='/var/www/anytoour/data/www/app.anytoour.ru';
 ob_start();try{require_once $root.'/config.php';require_once $root.'/services/ConversationDb.php';require_once $root.'/services/ProjectAccessService.php';require_once $root.'/services/ManagerPreparationOAuth.php';}finally{ob_end_clean();}
 must(ProjectConfig::projectId()==='anytour');ProjectAccessService::initializeReadOnly();$pdo=ConversationDb::connection();
 $q=$pdo->prepare("SELECT id,project_key,display_name,is_active FROM projects WHERE project_key=? LIMIT 1");$q->execute(['anytour']);$project=$q->fetch(PDO::FETCH_ASSOC);must($project&&(int)$project['is_active']===1);
 // The receipt identifies the current caller for audit attribution. Account-write
 // authority comes separately from the explicit owner instruction and SSH operator.
 // No token, bearer, password/hash or synthesized browser session is read.
 $q=$pdo->prepare("SELECT grant_id,admin_id FROM manager_preparation_oauth_grants WHERE project_key=? AND revoked_at IS NULL AND expires_at>? ORDER BY grant_id LIMIT 501");$q->execute(['anytour',time()]);$grants=$q->fetchAll(PDO::FETCH_ASSOC);must(count($grants)<=500);
 $oauth=new ManagerPreparationOAuth($pdo);$actors=[];
 foreach($grants as $g){$metadata=$oauth->currentAuthorizationMetadata((string)$g['grant_id'],(int)$g['admin_id'],[ManagerPreparationOAuth::BOOKING_SCOPE]);if($metadata&&hash_equals(LINK_CONTEXT,$metadata['link_context']))$actors[(int)$g['admin_id']]=true;}
 must(count($actors)===1);$actor=(int)array_key_first($actors);must($oauth->activeAdmin($actor)&&ProjectAccessService::canAccess($actor,'anytour'));
 $q=$pdo->prepare("SELECT m.id,m.login,m.display_name,m.role,m.is_active,m.is_working FROM managers m JOIN manager_projects mp ON mp.manager_id=m.id WHERE mp.project_id=? ORDER BY m.id LIMIT 501");$q->execute([(int)$project['id']]);$managers=$q->fetchAll(PDO::FETCH_ASSOC);must(count($managers)<=500);
 $q=$pdo->prepare("SELECT id,group_key,display_name,is_active FROM manager_groups WHERE project_id=? ORDER BY id LIMIT 201");$q->execute([(int)$project['id']]);$groups=$q->fetchAll(PDO::FETCH_ASSOC);must(count($groups)<=200);
 foreach($groups as &$group){$q=$pdo->prepare('SELECT manager_id FROM manager_group_members WHERE group_id=? ORDER BY manager_id LIMIT 501');$q->execute([(int)$group['id']]);$group['member_ids']=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));must(count($group['member_ids'])<=500);}unset($group);
 $q=$pdo->prepare("SELECT id,source_key,channel,primary_group_id,fallback_mode,fallback_group_id,is_active FROM conversation_sources WHERE project_id=? ORDER BY id LIMIT 501");$q->execute([(int)$project['id']]);$sources=$q->fetchAll(PDO::FETCH_ASSOC);must(count($sources)<=500);
 $payload=['contract'=>'anytour-regional-manager-preflight-v1','captured_at'=>gmdate('Y-m-d\\TH:i:s\\Z'),'actor_id'=>$actor,'project'=>$project,'managers'=>$managers,'groups'=>$groups,'sources'=>$sources,'database_changed'=>false];
 echo 'REGIONAL_MANAGER_PREFLIGHT_PACKET='.json_encode(seal($payload),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $e){fwrite(STDERR,"REGIONAL_MANAGER_PREFLIGHT_ERROR=READ_REFUSED\n");exit(1);}
