<?php
declare(strict_types=1);
ini_set('display_errors','0');ini_set('log_errors','0');
const CHUNK_READ_OPERATION='hotel-server-private-cgi-readback-v1';
const CHUNK_READ_INSTALLED='0fb9d88cd714ae951baa68f013b7a46472af06c4';
const CHUNK_READ_ID='8e4c0372a0338bc98429d47bc0fe3327238f5be69d94c7d28dbf977647a85e35';
const CHUNK_READ_TICKET_SHA='5e91679b20b7906686fc6f02b02841fbec615af99a3177a56d3f36e904b8dc43';
const CHUNK_READ_PAYLOAD_SHA='a85746bce5b71d4d241777a30e6bd643efecbd512bc5a50a2b741336012e84eb';
const CHUNK_READ_FIRST_SHA='fa65d127d3dba4b8d76cb320b1f71f2290b2fefd362ec488f87789d37da43275';
const CHUNK_READ_RECIPIENT_SHA='71f2b177d6baa924fa466765027c37f51a00f23f803a6d900060bf42944d237d';
const CHUNK_READ_WINDOW_FROM=1793642985; // 2026-11-02T18:09:45Z.
const CHUNK_READ_WINDOW_TO=1793643045;   // 2026-11-02T18:10:45Z.
$level=ob_get_level();ob_start(static fn(string $ignored):string=>'');
$fixed=['operation'=>CHUNK_READ_OPERATION,'database_writes'=>0,'provider_calls'=>0,'runtime_enable_writes'=>0];
$out=$fixed+['state'=>'HOLD','reason'=>'target_unconfirmed'];$lock=null;$pdo=null;$target=false;
$report=['schema'=>CHUNK_READ_OPERATION,'operation_id'=>CHUNK_READ_ID,'state'=>'HOLD','reason'=>'readback_unconfirmed',
    'operator_provider_calls'=>0,'database_writes'=>0,'runtime_enable_writes'=>0,'server_file_writes'=>0,
    'resend_authorized'=>false,'window_utc'=>['from'=>gmdate('c',CHUNK_READ_WINDOW_FROM),'to'=>gmdate('c',CHUNK_READ_WINDOW_TO)],
    'client_attempt'=>['index'=>0,'bytes'=>65536,'sha256'=>CHUNK_READ_FIRST_SHA,'started_at_utc'=>'2026-11-02T18:09:57.494058Z','client_outcome'=>'HTTP404_handler_unavailable_terminal','http_status'=>404,'response_body_sha256'=>'0467ddb0c0de6540c5fbc15b6137de5b88ccaf91ea32090e6bcdb4dfe6dcc0d4']];
function chunkFail(string $why):never {throw new DomainException($why);}
function chunkDir(string $path):void {
    clearstatcache(true,$path);
    if(is_link($path)||realpath($path)!==$path||!is_dir($path)||(fileperms($path)&0777)!==0700||fileowner($path)!==posix_geteuid())
        chunkFail('private_channel_unconfirmed');
}
/** Only fixed metadata and a streamed digest leave this function; file bytes never do. */
function chunkMeta(string $path,int $max):array {
    clearstatcache(true,$path);$s=lstat($path);if($s===false)return ['exists'=>false];
    if(is_link($path)||!is_file($path)||($s['mode']&0777)!==0600||$s['uid']!==posix_geteuid()||$s['size']<0||$s['size']>$max)
        chunkFail('private_channel_unconfirmed');
    $f=fopen($path,'rb');if(!$f)chunkFail('readback_unconfirmed');
    try{
        $opened=fstat($f);if($s['dev']!==$opened['dev']||$s['ino']!==$opened['ino'])chunkFail('private_file_changed');
        $ctx=hash_init('sha256');$read=hash_update_stream($ctx,$f);$sha=hash_final($ctx);$end=fstat($f);
    }finally{fclose($f);}
    clearstatcache(true,$path);$after=lstat($path);
    $stable=is_array($after)&&$s['dev']===$after['dev']&&$s['ino']===$after['ino']&&$s['size']===$after['size']
        &&$s['mtime']===$after['mtime']&&$s['ctime']===$after['ctime']&&$read===$s['size']
        &&$end['size']===$s['size']&&$end['mtime']===$s['mtime'];
    return ['exists'=>true,'bytes'=>$s['size'],'mode'=>decoct($s['mode']&0777),'uid'=>$s['uid'],
        'modified_at_utc'=>gmdate('c',$s['mtime']),'changed_at_utc'=>gmdate('c',$s['ctime']),'sha256'=>$sha,'stable'=>$stable];
}
/** Metadata only for the one potentially untracked root configuration file; never follow links. */
function chunkHtaccessMeta(string $path):array {
    clearstatcache(true,$path);$s=lstat($path);if($s===false)return ['exists'=>false];
    $meta=['exists'=>true,'bytes'=>$s['size'],'mode'=>decoct($s['mode']&0777),'uid'=>$s['uid'],
        'symlink'=>is_link($path),'regular_file'=>is_file($path),'hash_performed'=>false];
    if($meta['symlink']||!$meta['regular_file']||!is_readable($path)||$s['size']<0||$s['size']>65536)return $meta;
    $f=fopen($path,'rb');if(!$f)return $meta;
    try{
        $opened=fstat($f);if($opened['ino']!==$s['ino']||$opened['dev']!==$s['dev'])return $meta+['stable'=>false];
        $ctx=hash_init('sha256');$read=hash_update_stream($ctx,$f);$sha=hash_final($ctx);$end=fstat($f);
    }finally{fclose($f);}
    clearstatcache(true,$path);$after=lstat($path);
    $meta['hash_performed']=true;$meta['sha256']=$sha;
    $meta['stable']=is_array($after)&&$s['dev']===$after['dev']&&$s['ino']===$after['ino']&&$s['size']===$after['size']
        &&$s['mtime']===$after['mtime']&&$s['ctime']===$after['ctime']&&$read===$s['size']&&$end['size']===$s['size'];
    return $meta;
}
/** Bounded same-host log tail. No foreign request record is emitted. */
function chunkLogTail(string $path):array {
    if(is_link($path)||!is_file($path)||!is_readable($path))return ['available'=>false,'lines'=>[]];
    $f=fopen($path,'rb');if(!$f)return ['available'=>false,'lines'=>[]];
    try{
        $s=fstat($f);$start=max(0,$s['size']-1048576);
        if(fseek($f,$start)!==0)chunkFail('log_read_unconfirmed');
        $raw=stream_get_contents($f,min(1048576,$s['size']-$start));$end=fstat($f);
        if(!is_string($raw))chunkFail('log_read_unconfirmed');
    }finally{fclose($f);}
    clearstatcache(true,$path);$after=lstat($path);$lines=explode("\n",$raw);
    if($start>0)array_shift($lines);
    return ['available'=>true,'read_bytes'=>strlen($raw),'tail_limited'=>$start>0,'read_end_byte'=>$s['size'],
        'identity_stable'=>is_array($after)&&$s['dev']===$after['dev']&&$s['ino']===$after['ino']&&$s['ino']===$end['ino'],
        'lines'=>$lines];
}

/** Read only the three named own-vhost files and explicit per-user PHP wrapper references. */
function chunkVhostEvidence(string $path,array &$wrappers,int &$rowBudget,bool $wrapper=false):array {
    clearstatcache(true,$path);$s=lstat($path);
    if($s===false)return ['path'=>$path,'available'=>false,'reason'=>'absent_or_unreadable'];
    if(is_link($path)||!is_file($path)||!is_readable($path)||$s['size']<1||$s['size']>131072)
        return ['path'=>$path,'available'=>false,'reason'=>'bounded_regular_read_unavailable','bytes'=>$s['size'],'symlink'=>is_link($path)];
    $f=fopen($path,'rb');if(!$f)return ['path'=>$path,'available'=>false,'reason'=>'unreadable'];
    try{$opened=fstat($f);if($s['dev']!==$opened['dev']||$s['ino']!==$opened['ino'])chunkFail('vhost_file_changed');
        $raw=stream_get_contents($f,131073);$end=fstat($f);}finally{fclose($f);}
    clearstatcache(true,$path);$after=lstat($path);
    if(!is_string($raw)||strlen($raw)!==$s['size']||!is_array($after)||$s['dev']!==$after['dev']||$s['ino']!==$after['ino']
        ||$s['size']!==$after['size']||$s['mtime']!==$after['mtime']||$end['size']!==$s['size'])chunkFail('vhost_file_changed');
    $out=['path'=>$path,'available'=>true,'bytes'=>$s['size'],'sha256'=>hash('sha256',$raw),'stable'=>true,'selected_directives'=>[]];
    if(str_contains($raw,"\0"))return $out+['reason'=>'non_text_not_returned'];
    if(!$wrapper&&!preg_match('/(?:ServerName|server_name)\s+[^\r\n]*\bapp\.anytoour\.ru\b/i',$raw))
        return $out+['reason'=>'own_host_marker_unconfirmed'];
    $aliases=[];$actions=[];$selected=[];$truncated=false;
    foreach(explode("\n",$raw) as $n=>$original){
        $line=trim($original);if($line===''||str_starts_with($line,'#')||str_starts_with($line,';')||strlen($line)>2048)continue;
        $line=preg_replace('/\s+#.*$/','',$line);
        $row=null;$safe=null;
        if(preg_match('/\bdisable_functions\b/i',$line)){
            $row=['directive'=>'disable_functions','posix_geteuid_disabled'=>preg_match('/(?:^|[,=;\s"\'])posix_geteuid(?:$|[,\s"\';])/i',$line)===1,
                'other_function_names_omitted'=>true];
        }elseif(preg_match('/\bopen_basedir\b\s*(?:=|\s)\s*(.+)$/i',$line,$m)){
            $value=trim($m[1]," \t;\"'");$literal=preg_match('~\A[A-Za-z0-9_./: -]*\z~D',$value)===1;
            $allowed=null;if($literal){$allowed=$value===''||strtolower($value)==='none';
                foreach(explode(':',$value) as $base){$base=trim($base);if($base==='/')$allowed=true;$base=rtrim($base,'/');if($base!==''&&($base==='/var/www/anytoour/data/www/max-search-bot-hotel-server'
                    ||str_starts_with('/var/www/anytoour/data/www/max-search-bot-hotel-server/',$base.'/')))$allowed=true;}}
            $row=['directive'=>'open_basedir','literal_private_sibling_allowed'=>$allowed,'value'=>$literal?substr($value,0,256):'[nonliteral value omitted]'];
        }elseif(preg_match('/\b(opcache\.[A-Za-z0-9_]+)\b\s*(?:=|\s)\s*([^#\r\n]+)/i',$line,$m)){
            $value=trim($m[2]," \t;\"'");$safeValue=preg_match('/\A(?:[0-9]+|on|off|true|false|yes|no)\z/iD',$value)?$value:'[nonboolean/non-numeric value omitted]';
            $row=['directive'=>$m[1],'value'=>$safeValue];
        }elseif(preg_match('/^\s*(?:export\s+)?PHPRC\s*=\s*["\']?([\/A-Za-z0-9_.-]+)["\']?\s*;?\s*$/',$line,$m)){
            $row=['directive'=>'PHPRC','path'=>$m[1],'referenced_ini_not_read'=>true];
        }elseif(preg_match('~(?:^|[\s"\'])((?:/[A-Za-z0-9_.-]+)+/(?:php-cgi[0-9.]*|php[0-9.]*))(?:[\s"\';]|$)~',$line,$m)&&$wrapper){
            $row=['directive'=>'php_binary_reference','path'=>$m[1],'binary_not_read_or_executed'=>true];
        }elseif(preg_match('/^(CGIPassAuth|AllowOverride)\s+([A-Za-z ]+)\s*$/i',$line,$m)){
            $safe=$m[1].' '.$m[2];
        }elseif(preg_match('/^FcgidPassHeader\s+(Authorization|HTTPS|X-Forwarded-Proto|X-HTTPS)\s*$/i',$line,$m)){
            $safe='FcgidPassHeader '.$m[1];
        }elseif(preg_match('/^(?:AssignUserID|SuexecUserGroup|UserGroup|User|Group)\s+[A-Za-z0-9_. -]+\s*$/i',$line)){
            $safe=$line;
        }elseif(preg_match('~^(DocumentRoot|root)\s+["\']?(/[A-Za-z0-9_./-]+)["\']?\s*;?\s*$~i',$line,$m)){
            $safe=$m[1].' '.$m[2];
        }elseif(preg_match('~^<Directory\s+["\']?(/var/www/anytoour/data/www/app\.anytoour\.ru/?)["\']?>$~i',$line,$m)){
            $safe='<Directory '.$m[1].'>';
        }elseif(preg_match('/^Options\s+([A-Za-z+\- ]+)$/i',$line,$m)&&stripos($m[1],'ExecCGI')!==false){
            $safe=$line;
        }elseif(preg_match('~^FcgidWrapper\s+["\']?(/[A-Za-z0-9_./-]+)["\']?(?:\s|$)~i',$line,$m)){
            $safe='FcgidWrapper '.$m[1];
            if(str_starts_with($m[1],'/var/www/php-bin/anytoour/')&&!str_contains($m[1],'/../'))$wrappers[$m[1]]=true;
        }elseif(preg_match('~^ScriptAlias\s+["\']?(/php-bin/[^"\s]*)["\']?\s+["\']?(/var/www/php-bin/anytoour/[^"\s]*)["\']?~i',$line,$m)){
            $safe='ScriptAlias '.$m[1].' '.$m[2];$aliases[$m[1]]=$m[2];
        }elseif(preg_match('~^Action\s+([A-Za-z0-9_/+.-]+)\s+["\']?(/php-bin/[A-Za-z0-9_./-]+)["\']?\s*$~i',$line,$m)){
            $safe='Action '.$m[1].' '.$m[2];$actions[]=$m[2];
        }elseif(preg_match('/^(AddHandler|SetHandler)\s+([A-Za-z0-9_\/+.-]+)(?:\s+(\.[A-Za-z0-9. ]+))?$/i',$line,$m)
            &&preg_match('/php|cgi/i',$m[2])){
            $safe=substr($line,0,256);
        }elseif(preg_match('~^(?:<VirtualHost|Listen|listen|proxy_pass|fastcgi_pass|ProxyPass)\b.*(?:127\.0\.0\.1|\[::1\]|localhost)~i',$line)){
            if(preg_match('~(?:https?://)?(?:127\.0\.0\.1|\[::1\]|localhost)(?::[0-9]{1,5})?~i',$line,$m)){
                preg_match('/^<?([A-Za-z_]+)/',$line,$name);$safe=($name[1]??'loopback').' '.$m[0];
            }
        }elseif(preg_match('/^(proxy_set_header|fastcgi_param|FcgidInitialEnv|SetEnv)\s+(Authorization|HTTP_AUTHORIZATION|HTTPS|X-Forwarded-Proto|X-HTTPS)\s+(.+)$/i',$line,$m)){
            $value=trim($m[3]," \t;\"'");$tokens=preg_split('/\s+/',$value);$ok=true;
            foreach($tokens as $token)if(!preg_match('/\A(?:\$[A-Za-z_][A-Za-z0-9_]*|%\{[A-Za-z0-9_:.-]+\}|https?|on|off|[01]|if_not_empty)\z/iD',$token))$ok=false;
            $safe=$m[1].' '.$m[2].' '.($ok?$value:'[literal value omitted]');
        }elseif(preg_match('/^(SetEnvIfNoCase|SetEnvIf)\s+(Authorization|X-Forwarded-Proto|X-HTTPS|HTTPS)\s+/i',$line,$m)){
            $safe=$m[1].' '.$m[2].' [pattern omitted]';
            if(preg_match('/\b(HTTP_AUTHORIZATION|HTTPS)=([^\s;]+)/',$line,$assignment)){
                $v=trim($assignment[2],"\"'");$ok=preg_match('/\A(?:\$[0-9]|%\{[A-Za-z0-9_:.-]+\}|on|off|[01])\z/iD',$v);
                $safe.=' '.$assignment[1].'='.($ok?$v:'[literal value omitted]');
            }
        }elseif(preg_match('/^(RewriteCond|RewriteRule)\s+/i',$line,$m)&&preg_match('/AUTHORIZATION|HTTPS|X-Forwarded-Proto|X-HTTPS/i',$line)){
            preg_match_all('/%\{(?:HTTP:Authorization|HTTP:X-Forwarded-Proto|HTTP:X-HTTPS|HTTPS)\}/i',$line,$vars);
            $safe=$m[1].' '.implode(' ',array_unique($vars[0])).' [patterns/targets omitted]';
            if(preg_match('/\bE=(HTTP_AUTHORIZATION|HTTPS):(%\{[A-Za-z0-9_:.-]+\}|on|off|[01])(?=,|\]|$)/i',$line,$e))$safe.=' E='.$e[1].':'.$e[2];
        }
        if($row===null&&$safe!==null)$row=['directive_line'=>substr($safe,0,384)];
        if($row!==null){
            if($rowBudget<1){$truncated=true;break;}$rowBudget--;$selected[]=['line_number'=>$n+1]+$row;
        }
    }
    foreach($actions as $action)foreach($aliases as $prefix=>$dest)if(str_starts_with($action,$prefix)){
        $wrapperPath=$dest.substr($action,strlen($prefix));
        if(str_starts_with($wrapperPath,'/var/www/php-bin/anytoour/')&&!str_contains($wrapperPath,'/../'))$wrappers[$wrapperPath]=true;
    }
    unset($raw);$out['selected_directives']=$selected;$out['selection_truncated']=$truncated;return $out;
}

function chunkSeal(array $payload):array {
    $recipient=<<<'PUBLIC_RECIPIENT'
-----BEGIN PUBLIC KEY-----
MIIBojANBgkqhkiG9w0BAQEFAAOCAY8AMIIBigKCAYEAuN8W3YQpfXZak/D2Whmc
58DfcWVrLJRumXPFLQjaHhcueBq9zWnFf6jp8/x9IIZ/CZDz/vxvdh7PmSAW/Faa
ciVa2HDRJmxO3ZteBVcnVWatO9j1GrbvAZc/OCYxflqBwueetSikhlqFdeqKDok4
gKE81+uPhl28Um8leV4+ouLmM9Pic4gDSqL87uMyKSe1O5lDmUbvJm3Qyfs+ZfOn
PBuEsUuvMUkVZ7c6FrhY74pEzyT6Mx6XdnnVdyZ+ksGcmCAroAQoPtia7ebv17Xi
pJjgwEokntbAAlvqwrbH8X7X+MpeI84EXAh6kJOPxCj8l/Oxb1xdZR/wC0DorQkj
R7yipD6y0nyjWWHdwucd4Lty0H332crtnxktGYIfymRXprZb/creHaGOw39gIvGV
T8YNFRu1JmL3tbjxnO4ekvJfvOzwErVxLahHT0RJCcZ43a7zga+9oMaFLII9RqGd
LPAX0T14+63yqvazaMLpk7UOTjSedaIGCxEfuviVR4pzAgMBAAE=
-----END PUBLIC KEY-----
PUBLIC_RECIPIENT;
    $recipient.="\n";if(!hash_equals(CHUNK_READ_RECIPIENT_SHA,hash('sha256',$recipient)))chunkFail('sealing_unavailable');
    $plain=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);if(strlen($plain)>65536)chunkFail('readback_bounds_unconfirmed');
    $key=random_bytes(32);$nonce=random_bytes(12);$tag='';$wrapped='';$aad=CHUNK_READ_OPERATION.'|'.CHUNK_READ_INSTALLED.'|'.CHUNK_READ_ID;
    $cipher=openssl_encrypt($plain,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag,$aad,16);
    if(!is_string($cipher)||strlen($tag)!==16||!openssl_public_encrypt($key,$wrapped,$recipient,OPENSSL_PKCS1_OAEP_PADDING)||strlen($wrapped)!==384)
        chunkFail('sealing_unavailable');
    unset($key,$plain);
    return ['operation'=>CHUNK_READ_OPERATION,'database_writes'=>0,'provider_calls'=>0,'runtime_enable_writes'=>0,
        'state'=>'sealed','algorithm'=>'RSA-OAEP-SHA1+A256GCM','recipient_sha256'=>CHUNK_READ_RECIPIENT_SHA,'aad'=>$aad,
        'key'=>base64_encode($wrapped),'nonce'=>base64_encode($nonce),'tag'=>base64_encode($tag),'ciphertext'=>base64_encode($cipher),
        'ciphertext_sha256'=>hash('sha256',$cipher)];
}
try {
    $app='/var/www/anytoour/data/www/app.anytoour.ru';
    if(PHP_SAPI!=='cli'||count($argv)!==1||realpath(getcwd())!==$app||!function_exists('posix_geteuid')||posix_geteuid()!==1012)
        chunkFail('target_unconfirmed');
    $target=true;$report['read_at_utc']=gmdate('c');
    $lines=[];$code=1;exec('git -C '.escapeshellarg($app).' rev-parse HEAD 2>/dev/null',$lines,$code);
    $installed=trim(implode('',$lines));$report['installed_sha']=$installed;
    if($code!==0||$installed!==CHUNK_READ_INSTALLED)chunkFail('installed_release_unconfirmed');
    if(!hash_equals('f0180d547da2566d6cbc1fe0ef34530837e3c0c87e0a5cf9c407094ef07f4ee1',hash_file('sha256',$app.'/services/HotelServerPrivateUpload.php')))
        chunkFail('installed_helper_unconfirmed');
    $report['application_root_htaccess']=chunkHtaccessMeta($app.'/.htaccess');
    $report['expected_root_htaccess_sha256']='a4c8912801aa74844279e2c2c09230e3f555da54e16a9a6a31ec3005ec9ed8ff';
    $report['root_htaccess_matches_installed_source']=($report['application_root_htaccess']['stable']??false)===true
        &&hash_equals($report['expected_root_htaccess_sha256'],$report['application_root_htaccess']['sha256']??'');
    $root=dirname($app).'/max-search-bot-hotel-server';$dir=$root.'/ingress-v1';chunkDir($root);chunkDir($dir);
    $lockMeta=chunkMeta($dir.'/upload.lock',1);$report['upload_lock_metadata']=$lockMeta;
    if(!$lockMeta['exists']||$lockMeta['bytes']!==0||$lockMeta['stable']!==true)chunkFail('existing_upload_lock_unconfirmed');
    $lock=fopen($dir.'/upload.lock','rb');if(!$lock)chunkFail('readback_unconfirmed');
    $report['upload_lock_free']=flock($lock,LOCK_SH|LOCK_NB);
    if($report['upload_lock_free']!==true)chunkFail('active_upload_requires_wait');
    $files=[];
    foreach(['ticket.json'=>4096,'upload.part'=>8388608,'sealed-inputs.bin'=>8388608] as $name=>$limit)$files[$name]=chunkMeta($dir.'/'.$name,$limit);
    $chunks=$dir.'/chunks';$chunkEntries=[];$chunkDirPresent=file_exists($chunks)||is_link($chunks);
    if($chunkDirPresent){
        chunkDir($chunks);$iter=new DirectoryIterator($chunks);
        foreach($iter as $entry){
            if($entry->isDot())continue;
            $name=$entry->getFilename();if(count($chunkEntries)>=174)chunkFail('unexpected_chunk_directory');
            if(!preg_match('/\A(?:0[0-7][0-9]|08[0-6])\.bin(?:\.part)?\z/D',$name)||$entry->isLink()||!$entry->isFile())
                chunkFail('unexpected_chunk_directory');
            $chunkEntries[]=$name;
        }
        sort($chunkEntries);
        foreach(['000.bin','000.bin.part'] as $name)$files['chunks/'.$name]=chunkMeta($chunks.'/'.$name,65536);
    }else{
        $files['chunks/000.bin']=['exists'=>false];$files['chunks/000.bin.part']=['exists'=>false];
    }
    $report['files']=$files;$report['chunks_directory_present']=$chunkDirPresent;$report['chunk_entries']=$chunkEntries;
    $report['ticket_sha256_matches_renewal']=($files['ticket.json']['exists']??false)
        &&($files['ticket.json']['stable']??false)===true&&hash_equals(CHUNK_READ_TICKET_SHA,$files['ticket.json']['sha256']??'');
    $report['expected_ticket_sha256']=CHUNK_READ_TICKET_SHA;
    if($report['ticket_sha256_matches_renewal']){
        // Only this known pinned ticket is parsed; the bearer token/hash is not copied into the report.
        $raw=file_get_contents($dir.'/ticket.json');if(!is_string($raw)||!hash_equals(CHUNK_READ_TICKET_SHA,hash('sha256',$raw)))chunkFail('private_file_changed');
        $ticket=json_decode($raw,true,16,JSON_THROW_ON_ERROR);unset($raw);
        if(($ticket['operation_id']??null)!==CHUNK_READ_ID||($ticket['payload_bytes']??null)!==5696586
            ||($ticket['payload_sha256']??null)!==CHUNK_READ_PAYLOAD_SHA)chunkFail('ticket_scope_unconfirmed');
        $report['ticket']=['schema'=>$ticket['schema'],'operation_id'=>$ticket['operation_id'],'payload_bytes'=>$ticket['payload_bytes'],
            'payload_sha256'=>$ticket['payload_sha256'],'created_at'=>$ticket['created_at'],'expires_at'=>$ticket['expires_at'],
            'expired_at_read'=>time()>=$ticket['expires_at']];
        unset($ticket);
    }
    $report['runtime_file_present']=file_exists($root.'/runtime.json')||is_link($root.'/runtime.json');
    $report['runtime_staging_present']=file_exists($root.'/runtime.activation-20261102.part')||is_link($root.'/runtime.activation-20261102.part');
    // Existing same-host logs only. UTC conversion uses the access log's explicit numeric offset.
    $logRoot=dirname(dirname($app)).'/logs';$access=chunkLogTail($logRoot.'/app.anytoour.ru.access.log');
    $accessRows=[];$accessOffsets=[];$stampMin=null;$stampMax=null;
    foreach($access['lines'] as $line){
        if(strlen($line)>4096)continue;
        if(!preg_match('/\[([0-9]{2}\/[A-Za-z]{3}\/[0-9]{4}:[0-9]{2}:[0-9]{2}:[0-9]{2} ([+-][0-9]{4}))\]/',$line,$date))continue;
        $dt=DateTimeImmutable::createFromFormat('!d/M/Y:H:i:s O',$date[1]);if($dt===false)continue;$stamp=$dt->getTimestamp();
        $stampMin=$stampMin===null?$stamp:min($stampMin,$stamp);$stampMax=$stampMax===null?$stamp:max($stampMax,$stamp);$accessOffsets[$date[2]]=true;
        if($stamp<CHUNK_READ_WINDOW_FROM||$stamp>CHUNK_READ_WINDOW_TO)continue;
        if(!preg_match('~"(POST|GET|HEAD) (/hotel-server-input\.php) HTTP/(?:1\.[01]|2(?:\.0)?|3(?:\.0)?)"\s+([1-5][0-9]{2})\s+([0-9]+|-)(?:\s|$)~',$line,$m))continue;
        if(count($accessRows)>=16)chunkFail('endpoint_log_bounds_exceeded');
        $accessRows[]=['at_utc'=>gmdate('c',$stamp),'logged_offset'=>$date[2],'method'=>$m[1],'path'=>$m[2],
            'status'=>(int)$m[3],'response_bytes'=>$m[4]==='-'?null:(int)$m[4]];
    }
    unset($access['lines']);
    $access['window_covered_by_tail']=($access['identity_stable']??false)&&$stampMin!==null&&$stampMin<=CHUNK_READ_WINDOW_FROM&&$stampMax>=CHUNK_READ_WINDOW_TO;
    $access['own_endpoint_rows']=$accessRows;$access['own_endpoint_post_count']=count(array_filter($accessRows,static fn(array $r):bool=>$r['method']==='POST'));
    $access['timezone_offsets_observed']=array_keys($accessOffsets);$report['access_log']=$access;
    $error=chunkLogTail($logRoot.'/app.anytoour.ru.error.log');$errorRows=[];
    foreach($error['lines'] as $line){
        if(strlen($line)>4096)continue;
        $body=preg_split('/,\s*referer:/i',$line,2)[0];
        if(!str_contains($body,'/hotel-server-input.php')&&!str_contains($body,'HotelServerPrivateUpload.php'))continue;
        if(!preg_match('/^\[([A-Za-z]{3} [A-Za-z]{3} [ 0-9][0-9] [0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})? [0-9]{4})\]/',$body,$date))continue;
        $times=[];
        foreach(array_keys($accessOffsets) as $offset){
            $format=str_contains($date[1],'.')?'!D M d H:i:s.u Y O':'!D M d H:i:s Y O';
            $dt=DateTimeImmutable::createFromFormat($format,$date[1].' '.$offset);
            if($dt!==false&&$dt->getTimestamp()>=CHUNK_READ_WINDOW_FROM&&$dt->getTimestamp()<=CHUNK_READ_WINDOW_TO)
                $times[]=gmdate('c',$dt->getTimestamp());
        }
        if($times===[])continue;
        if(count($errorRows)>=12)chunkFail('endpoint_log_bounds_exceeded');
        $safe=preg_replace('/\[client [^\]]+\]/','[client redacted]',$body);
        $safe=preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/=-]+/i','Bearer [redacted]',(string)$safe);
        $safe=preg_replace('~(https?://[^\s?]+)\?[^\s]+~','$1?[redacted]',(string)$safe);
        $errorRows[]=['possible_at_utc'=>$times,'time_basis'=>'same_host_access_log_offset_inference',
            'line'=>substr((string)$safe,0,1536),'line_sha256'=>hash('sha256',$body)];
    }
    unset($error['lines']);$error['own_endpoint_rows']=$errorRows;$error['generic_unattributed_errors_excluded']=true;
    $error['time_basis']='same_host_access_log_offsets_only; no PHP timezone assumption';$report['error_log']=$error;
    $vhostRows=[];$wrapperPaths=[];$directiveBudget=48;
    foreach(['/etc/httpd/conf/vhosts/anytoour/app.anytoour.ru.conf',
        '/etc/apache2/conf/vhosts/anytoour/app.anytoour.ru.conf','/etc/nginx/vhosts/anytoour/app.anytoour.ru.conf'] as $configPath)
        $vhostRows[]=chunkVhostEvidence($configPath,$wrapperPaths,$directiveBudget);
    $report['exact_own_vhost_configs']=$vhostRows;$wrapperRows=[];$seenWrappers=[];
    foreach(array_keys($wrapperPaths) as $wrapperPath){
        if(count($seenWrappers)>=2)break;$seenWrappers[]=$wrapperPath;
        $wrapperRows[]=chunkVhostEvidence($wrapperPath,$wrapperPaths,$directiveBudget,true);
    }
    $report['explicit_own_php_wrapper_configs']=$wrapperRows;
    $report['global_configs_scanned']=false;$report['web_opcache_execution_version_proven']=false;
    require_once $app.'/config.php';require_once $app.'/services/ProjectAccessService.php';
    ProjectAccessService::initializeReadOnly();$pdo=ConversationDb::connection();
    if($pdo->inTransaction()||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql')chunkFail('database_read_unconfirmed');
    $pdo->exec('SET TRANSACTION READ ONLY');$pdo->beginTransaction();
    $s=$pdo->prepare('SELECT state,enabled,stage_cursor,new_accepted FROM hotel_server_campaigns WHERE campaign_id=? LIMIT 1');
    $s->execute(['HOTEL-SERVER-20261102']);$campaign=$s->fetch(PDO::FETCH_ASSOC);$s->closeCursor();
    $report['campaign_present']=$campaign!==false;
    if($campaign!==false)$report['campaign']=['state'=>$campaign['state'],'enabled'=>(int)$campaign['enabled']===1,
        'stage_cursor'=>(int)$campaign['stage_cursor'],'new_accepted'=>(int)$campaign['new_accepted']];
    $report['any_campaign_present']=$pdo->query('SELECT 1 FROM hotel_server_campaigns LIMIT 1')->fetchColumn()!==false;
    $pdo->commit();
    foreach($files as $meta)if(($meta['exists']??false)&&($meta['stable']??false)!==true)chunkFail('private_file_changed');
    $first=$files['chunks/000.bin'];$partial=$files['chunks/000.bin.part'];$final=$files['sealed-inputs.bin'];
    $observation='inconclusive';
    if($final['exists'])$observation=$final['bytes']===5696586&&hash_equals(CHUNK_READ_PAYLOAD_SHA,$final['sha256'])?'complete_payload_observed':'foreign_or_partial_payload_hold';
    elseif($files['upload.part']['exists'])$observation='partial_payload_observed_hold';
    elseif($partial['exists'])$observation='chunk0_partial_observed_hold';
    elseif($first['exists'])$observation=$first['bytes']===65536&&hash_equals(CHUNK_READ_FIRST_SHA,$first['sha256'])?'chunk0_stored_observed':'foreign_or_partial_chunk0_hold';
    elseif($chunkEntries===[]&&$report['ticket_sha256_matches_renewal'])$observation='chunk0_absent_observed';
    $report['server_observation']=$observation;$report['http_outcome_proven_by_client']=true;
    $report['log_absence_is_not_proof_of_safe_resend']=true;$report['state']='readback';unset($report['reason']);
}catch(Throwable $e){
    $why=$e->getMessage();$report['reason']=preg_match('/\A[a-z][a-z0-9_]{1,100}\z/D',$why)?$why:'readback_unconfirmed';
}finally{
    if($pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();
    if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}while(ob_get_level()>$level)ob_end_clean();
}
try{if($target)$out=chunkSeal($report);}
catch(Throwable $ignored){$out=$fixed+['state'=>'HOLD','reason'=>'sealing_unavailable'];}
echo json_encode($out,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
exit($report['state']==='readback'&&$out['state']==='sealed'?0:1);

