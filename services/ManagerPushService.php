<?php
require_once __DIR__ . '/ConversationDb.php';
require_once __DIR__ . '/RoutingAccessService.php';
require_once __DIR__ . '/ManagerPriorityService.php';
require_once __DIR__ . '/ManagerPushConnectionService.php';
require_once __DIR__ . '/ManagerPushPresentation.php';
require_once __DIR__ . '/ManagerPushContext.php';

class ManagerPushService
{
    private static function b64u(string $v): string { return rtrim(strtr(base64_encode($v), '+/', '-_'), '='); }
    private static function b64ud(string $v): string { $v=strtr($v,'-_','+/'); $v.=str_repeat('=',(4-strlen($v)%4)%4); return (string)base64_decode($v,true); }
    private static function dispatchId(): string
    {
        try { return bin2hex(random_bytes(8)); }
        catch (Throwable $e) { return str_replace('.', '', uniqid('', true)); }
    }

    private static function configPath(): string { return dirname(__DIR__) . '/.runtime/manager_push_vapid.php'; }

    public static function vapid(): array
    {
        $path=self::configPath();
        if(is_file($path)){
            $cfg=require $path;
            if(is_array($cfg)&&!empty($cfg['private_pem'])&&!empty($cfg['public_key'])) return $cfg;
        }
        $dir=dirname($path); if(!is_dir($dir)) @mkdir($dir,0700,true);
        $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);
        if(!$key) throw new RuntimeException('cannot_generate_vapid');
        $pem=''; openssl_pkey_export($key,$pem);
        $d=openssl_pkey_get_details($key); $ec=(array)($d['ec']??[]);
        $x=(string)($ec['x']??''); $y=(string)($ec['y']??'');
        if(strlen($x)!==32||strlen($y)!==32) throw new RuntimeException('invalid_vapid_key');
        $cfg=['private_pem'=>$pem,'public_key'=>self::b64u("\x04".$x.$y),'subject'=>'mailto:admin@anytoour.ru'];
        $tmp=$path.'.tmp.'.bin2hex(random_bytes(4));
        file_put_contents($tmp,"<?php\nreturn ".var_export($cfg,true).";\n",LOCK_EX); @chmod($tmp,0600); rename($tmp,$path);
        return $cfg;
    }

    public static function publicKey(): string { return (string)self::vapid()['public_key']; }

    /** Sales reminders keep canonical access, independently of technical status/shift. */
    public static function notifyManager(int $managerId,int $conversationId,string $title,string $body): array
    {
        if($managerId<=0||$conversationId<=0)return['attempted'=>0,'delivered'=>0];
        $dispatchId=self::dispatchId();
        try{
            $pdo=ConversationDb::connection();
            if(self::ambientTransaction($pdo,$conversationId,$dispatchId))return['attempted'=>0,'delivered'=>0];
            $conversation=self::conversation($pdo,$conversationId);
            if(!$conversation||!RoutingAccessService::canNotifyTaskOwner($managerId,$conversation))return['attempted'=>0,'delivered'=>0];
            $subs=ManagerPushConnectionService::activeSubscriptions($pdo,[$managerId]);
            if(!$subs){
                if(class_exists('DiagnosticLogger')){try{DiagnosticLogger::log('manager_push','no_subscription',['dispatch_id'=>$dispatchId,'conversation_id'=>$conversationId,'manager_id'=>$managerId,'notification_kind'=>'lead_task_reminder'],null,'warning');}catch(Throwable $ignored){}}
                return['attempted'=>0,'delivered'=>0];
            }
            $payload=json_encode(['title'=>trim($title)!==''?$title:'Напоминание AnyTour','body'=>$body,'conversationId'=>$conversationId,'url'=>'./'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $delivered=0;
            foreach($subs as $sub){
                try{if(self::send($sub,(string)$payload,$conversationId,$dispatchId,$conversation,true))$delivered++;}
                catch(Throwable $e){if(class_exists('DiagnosticLogger')){try{DiagnosticLogger::log('manager_push','delivery_exception',['dispatch_id'=>$dispatchId,'conversation_id'=>$conversationId,'manager_id'=>$managerId,'subscription_id'=>(int)($sub['id']??0),'notification_kind'=>'lead_task_reminder','error'=>$e->getMessage()],null,'warning');}catch(Throwable $ignored){}}}
            }
            return['attempted'=>count($subs),'delivered'=>$delivered];
        }catch(Throwable $e){
            if(class_exists('DiagnosticLogger')){try{DiagnosticLogger::log('manager_push','notify_manager_failed',['dispatch_id'=>$dispatchId,'conversation_id'=>$conversationId,'manager_id'=>$managerId,'notification_kind'=>'lead_task_reminder','error'=>$e->getMessage()],null,'warning');}catch(Throwable $ignored){}}
            return['attempted'=>0,'delivered'=>0,'error'=>$e->getMessage()];
        }
    }

    public static function notifyConversation(int $conversationId,string $body='Клиент написал в диалог',string $externalMessageId=''): void
    {
        $dispatchId=self::dispatchId();
        try{
            $pdo=ConversationDb::connection();
            if(self::ambientTransaction($pdo,$conversationId,$dispatchId))return;
            $c=self::conversation($pdo,$conversationId);if(!$c)return;
            $managers=[];
            if((string)$c['status']==='manager' && !empty($c['manager_id'])){
                $owner=(int)$c['manager_id'];if(RoutingAccessService::canSeeConversation($owner,$c))$managers=[$owner];
            }
            elseif((string)$c['status']==='waiting_manager'){
                $r=$pdo->query('SELECT id FROM managers WHERE is_active=1 AND is_working=1');
                foreach($r->fetchAll() as $m){$id=(int)$m['id']; if(RoutingAccessService::canSeeConversation($id,$c))$managers[]=$id;}
                if($managers){
                    $eligible=$managers;$scoreBreakdown=ManagerPriorityService::scoreBreakdown($eligible,$c);$scores=[];foreach($scoreBreakdown as $mid=>$detail)$scores[(int)$mid]=(int)$detail['final'];$preferred=ManagerPriorityService::preferred($eligible,$c);
                    if($preferred)$managers=$preferred;
                    if(class_exists('DiagnosticLogger')){try{DiagnosticLogger::log('manager_priority','push_selected',['dispatch_id'=>$dispatchId,'conversation_id'=>$conversationId,'eligible_manager_ids'=>$eligible,'selected_manager_ids'=>$managers,'scores'=>$scores,'score_breakdown'=>$scoreBreakdown,'entry_channel'=>$c['entry_channel']??null],null,'info');}catch(Throwable $ignored){}}
                }
            } else return;
            if(!$managers)return;
            // Read-only correlation is best effort and cannot prevent an existing push.
            $incomingId=0;$incoming=null;
            if($externalMessageId!==''){
                try{
                    $arrival=$pdo->prepare("SELECT id,text,metadata_json FROM messages WHERE conversation_id=? AND direction='inbound' AND sender_type='customer' AND external_message_id=? ORDER BY id DESC LIMIT 1");
                    $arrival->execute([$conversationId,$externalMessageId]);$incoming=$arrival->fetch(PDO::FETCH_ASSOC)?:null;$incomingId=(int)($incoming['id']??0);
                }catch(Throwable $ignored){}
            }
            $context=null;
            if($externalMessageId==='')$context=ManagerPushContext::forNotice($pdo,$conversationId,$body);
            $payload=json_encode(ManagerPushPresentation::conversation($c,$body,$incoming,$context),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $subs=ManagerPushConnectionService::activeSubscriptions($pdo,$managers);$subscribed=[];
            foreach($subs as $sub)$subscribed[(int)$sub['manager_id']]=true;
            if(class_exists('DiagnosticLogger')){
                foreach($managers as $managerId){if(isset($subscribed[(int)$managerId]))continue;try{DiagnosticLogger::log('manager_push','no_subscription',['dispatch_id'=>$dispatchId,'conversation_id'=>$conversationId,'manager_id'=>(int)$managerId],null,'warning');}catch(Throwable $ignored){}}
            }
            foreach($subs as $sub){
                try{
                    $deliveryPayload=$payload;
                    if($incomingId>0){
                        $notification=json_decode((string)$payload,true);
                        $notification['incomingMessageId']=$incomingId;
                        $notification['notificationManagerId']=(int)$sub['manager_id'];
                        $notification['notificationKind']='customer_message';
                        $deliveryPayload=json_encode($notification,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                    }
                    self::send($sub,(string)$deliveryPayload,$conversationId,$dispatchId,$c);
                }
                catch(Throwable $e){if(class_exists('DiagnosticLogger')){try{DiagnosticLogger::log('manager_push','delivery_exception',['dispatch_id'=>$dispatchId,'conversation_id'=>$conversationId,'manager_id'=>(int)($sub['manager_id']??0),'subscription_id'=>(int)($sub['id']??0),'error'=>$e->getMessage()],null,'warning');}catch(Throwable $ignored){}}}
            }
        }catch(Throwable $e){ if(class_exists('DiagnosticLogger')){try{DiagnosticLogger::log('manager_push','notify_failed',['dispatch_id'=>$dispatchId,'conversation_id'=>$conversationId,'error'=>$e->getMessage()],null,'warning');}catch(Throwable $ignored){}} }
    }

    /** Concrete callers notify after commit/autocommit; never use an outer snapshot. */
    private static function ambientTransaction(PDO$pdo,int$conversationId,string$dispatchId): bool
    {
        if(!$pdo->inTransaction())return false;
        if(class_exists('DiagnosticLogger')){try{DiagnosticLogger::log('manager_push','dispatch_blocked',['dispatch_id'=>$dispatchId,'conversation_id'=>$conversationId,'reason'=>'ambient_transaction'],null,'warning');}catch(Throwable$ignored){}}
        return true;
    }

    private static function conversation(PDO$pdo,int$id): ?array
    {
        $q=$pdo->prepare('SELECT c.id,c.customer_id,c.project_key,c.source_id,c.status,c.manager_id,c.started_at,c.last_message_at,c.channel,c.entry_channel,c.attribution_region,c.attribution_campaign,cu.display_name,p.display_name AS project_name,s.display_name AS source_name,s.source_key FROM conversations c JOIN customers cu ON cu.id=c.customer_id LEFT JOIN projects p ON p.project_key=c.project_key LEFT JOIN conversation_sources s ON s.id=c.source_id WHERE c.id=? LIMIT 1');
        $q->execute([$id]);return$q->fetch()?:null;
    }

    private static function dispatchAllowed(array$sub,array$expected,int$conversationId,bool$taskReminder): bool
    {
        $pdo=ConversationDb::connection();if($pdo->inTransaction())return false;
        $current=self::conversation($pdo,$conversationId);if(!$current)return false;
        foreach(['id','customer_id','source_id','manager_id']as$field)if((int)($current[$field]??0)!==(int)($expected[$field]??0))return false;
        foreach(['project_key','channel']as$field)if((string)($current[$field]??'')!==(string)($expected[$field]??''))return false;
        if(!$taskReminder&&(string)$current['status']!==(string)$expected['status'])return false;
        $managerId=(int)($sub['manager_id']??0);
        if(!($taskReminder?RoutingAccessService::canNotifyTaskOwner($managerId,$current):RoutingAccessService::canSeeConversation($managerId,$current)))return false;
        return ManagerPushConnectionService::isCurrent($pdo,$sub);
    }

    private static function send(array $sub,string $payload,int $conversationId,string $dispatchId,array$conversation,bool$taskReminder=false): bool
    {
        if(!ManagerPushConnectionService::isCurrent(ConversationDb::connection(),$sub))return false;
        $cfg=self::vapid(); $endpoint=(string)$sub['endpoint'];
        $clientPub=self::b64ud((string)$sub['p256dh']); $auth=self::b64ud((string)$sub['auth_secret']);
        if(strlen($clientPub)!==65||strlen($auth)<16) throw new RuntimeException('invalid_subscription_key');
        $server=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);
        $sd=openssl_pkey_get_details($server); $sec=(array)($sd['ec']??[]); $serverPub="\x04".(string)$sec['x'].(string)$sec['y'];
        $clientPem="-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(hex2bin('3059301306072A8648CE3D020106082A8648CE3D030107034200').$clientPub),64,"\n")."-----END PUBLIC KEY-----\n";
        $clientKey=openssl_pkey_get_public($clientPem); if(!$clientKey) throw new RuntimeException('bad_client_key');
        $shared=openssl_pkey_derive($clientKey,$server,32); if($shared===false) throw new RuntimeException('ecdh_failed');
        $prkKey=hash_hmac('sha256',$shared,$auth,true);
        $ikm=self::hkdfExpand($prkKey,"WebPush: info\0".$clientPub.$serverPub,32);
        $salt=random_bytes(16); $prk=hash_hmac('sha256',$ikm,$salt,true);
        $cek=self::hkdfExpand($prk,"Content-Encoding: aes128gcm\0",16); $nonce=self::hkdfExpand($prk,"Content-Encoding: nonce\0",12);
        $tag=''; $cipher=openssl_encrypt($payload."\x02",'aes-128-gcm',$cek,OPENSSL_RAW_DATA,$nonce,$tag,'',16); if($cipher===false) throw new RuntimeException('encrypt_failed');
        $body=$salt.pack('N',4096).chr(strlen($serverPub)).$serverPub.$cipher.$tag;
        $parts=parse_url($endpoint); $aud=($parts['scheme']??'https').'://'.($parts['host']??'');
        $jwt=self::vapidJwt($aud,(string)$cfg['subject'],(string)$cfg['private_pem']);
        $headers=['TTL: 60','Content-Encoding: aes128gcm','Content-Type: application/octet-stream','Authorization: vapid t='.$jwt.', k='.(string)$cfg['public_key'],'Content-Length: '.strlen($body)];
        if(!self::dispatchAllowed($sub,$conversation,$conversationId,$taskReminder))return false;
        $ch=curl_init($endpoint); curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_HEADER=>false]); curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
        $pdo=ConversationDb::connection();$managerId=(int)($sub['manager_id']??0);$subscriptionId=(int)($sub['id']??0);
        ManagerPushConnectionService::deliveryResult($pdo,$sub,$code,$err);
        if($code>=200&&$code<300){
            if(class_exists('DiagnosticLogger')){try{DiagnosticLogger::log('manager_push','delivery_success',['dispatch_id'=>$dispatchId,'conversation_id'=>$conversationId,'manager_id'=>$managerId,'subscription_id'=>$subscriptionId,'http_code'=>$code],null,'info');}catch(Throwable $ignored){}}
            return true;
        }
        if(in_array($code,[404,410],true)){
            if(class_exists('DiagnosticLogger')){try{DiagnosticLogger::log('manager_push','subscription_expired',['dispatch_id'=>$dispatchId,'conversation_id'=>$conversationId,'manager_id'=>$managerId,'subscription_id'=>$subscriptionId,'http_code'=>$code],null,'warning');}catch(Throwable $ignored){}}
            return false;
        }
        if(class_exists('DiagnosticLogger')){try{DiagnosticLogger::log('manager_push','delivery_failed',['dispatch_id'=>$dispatchId,'conversation_id'=>$conversationId,'manager_id'=>$managerId,'subscription_id'=>$subscriptionId,'http_code'=>$code,'error'=>mb_substr($err,0,300)],null,'warning');}catch(Throwable $ignored){}}
        return false;
    }

    private static function hkdfExpand(string $prk,string $info,int $len): string { $out='';$t='';$i=1;while(strlen($out)<$len){$t=hash_hmac('sha256',$t.$info.chr($i++),$prk,true);$out.=$t;}return substr($out,0,$len); }
    private static function vapidJwt(string $aud,string $sub,string $privatePem): string
    {
        $head=self::b64u(json_encode(['typ'=>'JWT','alg'=>'ES256'],JSON_UNESCAPED_SLASHES));
        $pay=self::b64u(json_encode(['aud'=>$aud,'exp'=>time()+43200,'sub'=>$sub],JSON_UNESCAPED_SLASHES));
        $input=$head.'.'.$pay; $sig=''; if(!openssl_sign($input,$sig,$privatePem,OPENSSL_ALGO_SHA256)) throw new RuntimeException('vapid_sign_failed');
        return $input.'.'.self::b64u(self::derToJose($sig));
    }
    private static function derToJose(string $der): string
    {
        $p=0; if(ord($der[$p++])!==0x30)throw new RuntimeException('bad_der'); self::derLen($der,$p); if(ord($der[$p++])!==0x02)throw new RuntimeException('bad_der'); $lr=self::derLen($der,$p);$r=substr($der,$p,$lr);$p+=$lr;if(ord($der[$p++])!==0x02)throw new RuntimeException('bad_der');$ls=self::derLen($der,$p);$s=substr($der,$p,$ls);
        $r=str_pad(ltrim($r,"\0"),32,"\0",STR_PAD_LEFT);$s=str_pad(ltrim($s,"\0"),32,"\0",STR_PAD_LEFT);return substr($r,-32).substr($s,-32);
    }
    private static function derLen(string $der,int &$p): int { $l=ord($der[$p++]);if(($l&0x80)===0)return$l;$n=$l&0x7f;$v=0;for($i=0;$i<$n;$i++)$v=($v<<8)|ord($der[$p++]);return$v; }
}
