<?php
require_once __DIR__ . '/ConversationDb.php';
require_once __DIR__ . '/RoutingAccessService.php';
require_once __DIR__ . '/ManagerPriorityService.php';

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
        $cfg=['private_pem'=>$pem,'public_key'=>self::b64u("\x04".$x.$y),'subject'=>'mailto:admin@anytour.online'];
        $tmp=$path.'.tmp.'.bin2hex(random_bytes(4));
        file_put_contents($tmp,"<?php\nreturn ".var_export($cfg,true).";\n",LOCK_EX); @chmod($tmp,0600); rename($tmp,$path);
        return $cfg;
    }

    public static function publicKey(): string { return (string)self::vapid()['public_key']; }

    public static function saveSubscription(int $managerId,array $subscription,string $userAgent=''): bool
    {
        $endpoint=trim((string)($subscription['endpoint']??''));
        $keys=(array)($subscription['keys']??[]); $p256dh=trim((string)($keys['p256dh']??'')); $auth=trim((string)($keys['auth']??''));
        if($endpoint===''||$p256dh===''||$auth==='') return false;
        $hash=hash('sha256',$endpoint);
        $q=ConversationDb::connection()->prepare('INSERT INTO manager_push_subscriptions (manager_id,endpoint,endpoint_hash,p256dh,auth_secret,user_agent) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE endpoint=VALUES(endpoint),p256dh=VALUES(p256dh),auth_secret=VALUES(auth_secret),user_agent=VALUES(user_agent),updated_at=NOW()');
        return $q->execute([$managerId,$endpoint,$hash,$p256dh,$auth,mb_substr($userAgent,0,500)]);
    }

    public static function notifyConversation(int $conversationId,string $body='Клиент написал в диалог'): void
    {
        $dispatchId=self::dispatchId();
        try{
            $pdo=ConversationDb::connection();
            $q=$pdo->prepare('SELECT c.id,c.project_key,c.source_id,c.status,c.manager_id,c.started_at,c.last_message_at,c.channel,c.entry_channel,c.attribution_region,c.attribution_campaign,cu.display_name,p.display_name AS project_name,s.display_name AS source_name,s.source_key FROM conversations c JOIN customers cu ON cu.id=c.customer_id LEFT JOIN projects p ON p.project_key=c.project_key LEFT JOIN conversation_sources s ON s.id=c.source_id WHERE c.id=? LIMIT 1');
            $q->execute([$conversationId]); $c=$q->fetch(); if(!$c) return;
            $managers=[];
            if((string)$c['status']==='manager' && !empty($c['manager_id'])) $managers=[(int)$c['manager_id']];
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
            $title=trim((string)($c['display_name']??'')); if($title==='')$title='Новый диалог AnyTour';
            $ctx=array_values(array_filter([(string)($c['project_name']??$c['project_key']??''),(string)($c['source_name']??''),strtoupper((string)($c['channel']??''))]));
            $payload=json_encode(['title'=>$title,'body'=>($ctx?implode(' · ',$ctx).' — ':'').$body,'conversationId'=>$conversationId,'url'=>'./'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $in=implode(',',array_fill(0,count($managers),'?'));
            $q=$pdo->prepare("SELECT * FROM manager_push_subscriptions WHERE manager_id IN ($in)"); $q->execute($managers);
            $subs=$q->fetchAll();$subscribed=[];
            foreach($subs as $sub)$subscribed[(int)$sub['manager_id']]=true;
            if(class_exists('DiagnosticLogger')){
                foreach($managers as $managerId){if(isset($subscribed[(int)$managerId]))continue;try{DiagnosticLogger::log('manager_push','no_subscription',['dispatch_id'=>$dispatchId,'conversation_id'=>$conversationId,'manager_id'=>(int)$managerId],null,'warning');}catch(Throwable $ignored){}}
            }
            foreach($subs as $sub){
                try{self::send($sub,(string)$payload,$conversationId,$dispatchId);}
                catch(Throwable $e){if(class_exists('DiagnosticLogger')){try{DiagnosticLogger::log('manager_push','delivery_exception',['dispatch_id'=>$dispatchId,'conversation_id'=>$conversationId,'manager_id'=>(int)($sub['manager_id']??0),'subscription_id'=>(int)($sub['id']??0),'error'=>$e->getMessage()],null,'warning');}catch(Throwable $ignored){}}}
            }
        }catch(Throwable $e){ if(class_exists('DiagnosticLogger')){try{DiagnosticLogger::log('manager_push','notify_failed',['dispatch_id'=>$dispatchId,'conversation_id'=>$conversationId,'error'=>$e->getMessage()],null,'warning');}catch(Throwable $ignored){}} }
    }

    private static function send(array $sub,string $payload,int $conversationId,string $dispatchId): void
    {
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
        $ch=curl_init($endpoint); curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_HEADER=>false]); curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
        $pdo=ConversationDb::connection();$managerId=(int)($sub['manager_id']??0);$subscriptionId=(int)($sub['id']??0);
        if($code>=200&&$code<300){
            $pdo->prepare('UPDATE manager_push_subscriptions SET last_success_at=NOW(),last_error=NULL WHERE id=?')->execute([$subscriptionId]);
            if(class_exists('DiagnosticLogger')){try{DiagnosticLogger::log('manager_push','delivery_success',['dispatch_id'=>$dispatchId,'conversation_id'=>$conversationId,'manager_id'=>$managerId,'subscription_id'=>$subscriptionId,'http_code'=>$code],null,'info');}catch(Throwable $ignored){}}
            return;
        }
        if(in_array($code,[404,410],true)){
            if(class_exists('DiagnosticLogger')){try{DiagnosticLogger::log('manager_push','subscription_expired',['dispatch_id'=>$dispatchId,'conversation_id'=>$conversationId,'manager_id'=>$managerId,'subscription_id'=>$subscriptionId,'http_code'=>$code],null,'warning');}catch(Throwable $ignored){}}
            $pdo->prepare('DELETE FROM manager_push_subscriptions WHERE id=?')->execute([$subscriptionId]);return;
        }
        $pdo->prepare('UPDATE manager_push_subscriptions SET last_error_at=NOW(),last_error=? WHERE id=?')->execute([mb_substr('HTTP '.$code.' '.$err,0,500),$subscriptionId]);
        if(class_exists('DiagnosticLogger')){try{DiagnosticLogger::log('manager_push','delivery_failed',['dispatch_id'=>$dispatchId,'conversation_id'=>$conversationId,'manager_id'=>$managerId,'subscription_id'=>$subscriptionId,'http_code'=>$code,'error'=>mb_substr($err,0,300)],null,'warning');}catch(Throwable $ignored){}}
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
