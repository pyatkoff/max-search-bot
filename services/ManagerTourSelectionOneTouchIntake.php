<?php
declare(strict_types=1);
require_once __DIR__.'/ManagerTourSelectionOneTouchSource.php';
require_once __DIR__.'/ManagerTourSelectionImportDraft.php';
require_once __DIR__.'/ManagerTourSelectionOneTouchCapture.php';

/** Existing editor context and stateless signed previews; not a second import store. */
final class ManagerTourSelectionOneTouchIntake
{
    public static function sessionKey(array &$state,string $sessionHash): string
    {
        if(!preg_match('/\A[a-f0-9]{64}\z/D',$sessionHash))throw new InvalidArgumentException('invalid_session');
        $key='tour_selection_onetouch_signing_secret';
        if(!is_string($state[$key]??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$state[$key]))$state[$key]=bin2hex(random_bytes(32));
        return hash_hmac('sha256','onetouch-source-session-v1|'.$sessionHash,$state[$key]);
    }

    public static function search(int $actor,int $conversation,string $session,array $data,?callable $transport=null): array
    {
        $context=ManagerTourSelectionService::sourceContext($actor,$conversation);if(empty($context['ok']))return $context;
        try{
            $expires=null;
            if(array_key_exists('capture',$data)){$capture=ManagerTourSelectionOneTouchCapture::read($data['capture']);$quotes=$capture['quotes'];$expires=$capture['expires'];}
            elseif($transport!==null){
                $url=$data['url']??'';if(!is_string($url))return self::error('onetouch_invalid_url');
                $intent=ManagerTourSelectionOneTouchSource::query($url);$result=$transport($url);
                if(!is_array($result)||!is_string($result['url']??null)||!is_string($result['observed_at']??null))self::unavailable();
                $actual=ManagerTourSelectionOneTouchSource::query($result['url']);
                if(array_diff_assoc(array_diff_key($intent,['rev'=>true]),array_diff_key($actual,['rev'=>true])))self::unavailable();
                $observed=ManagerTourSelectionOneTouchCapture::timestamp($result['observed_at']);
                if($observed>time()+2||time()-$observed>60)self::unavailable();$expires=$observed+600;
                $quotes=ManagerTourSelectionOneTouchSource::parse($result['response'],$result['url'],$result['form'],$result['observed_at']);
            }
            else return self::error('onetouch_capture_required');
            if(!self::sameContext($context,ManagerTourSelectionService::sourceContext($actor,$conversation)))return self::error('draft_changed');
            $offers=[];
            foreach($quotes as $quote)$offers[]=['offer'=>$quote['offer'],'raw'=>$quote['raw'],'ticket'=>self::seal($session,$actor,$conversation,$context,$quote,'quote',$expires)];
            return ['ok'=>true,'version'=>$context['version'],'offers'=>$offers,'total'=>count($offers)];
        }catch(Throwable $e){return self::error($e instanceof RuntimeException&&in_array($e->getMessage(),['onetouch_source_changed','onetouch_capture_expired','onetouch_capture_invalid'],true)?$e->getMessage():'onetouch_source_unavailable');}
    }

    public static function review(int $actor,int $conversation,string $session,array $data,?callable $fetch=null): array
    {
        $context=ManagerTourSelectionService::sourceContext($actor,$conversation);if(empty($context['ok']))return $context;
        $ticket=self::open($data['ticket']??null,$session,$actor,$conversation,'quote');if($ticket===null)return self::error('onetouch_expired');
        if(!self::sameContext($context,$ticket))return self::error('draft_changed');
        try{
            $quote=$ticket['observation'];$url=$quote['raw']['hotel_url'];
            if(($quote['raw']['capture_mode']??null)==='manager-file-v1'){
                $html=ManagerTourSelectionOneTouchCapture::decode($data['gallery_gzip_base64']??null,$quote['raw']['capture_gallery_sha256']);
                $native=ManagerTourSelectionOneTouchSource::gallery($html,$quote);$hashes=json_decode($quote['raw']['capture_photo_sha256'],true,8,JSON_THROW_ON_ERROR);$photos=array_keys($hashes);
                if($photos!==array_slice($native,0,count($photos)))return self::error('onetouch_photos_unavailable');$quote['photos']=$photos;
            }elseif($fetch!==null)$quote['photos']=ManagerTourSelectionOneTouchSource::gallery($fetch($url),$quote);
            else return self::error('onetouch_capture_required');
            if(!$quote['photos']||ManagerTourSelectionImportDraft::normalize($quote)===null)return self::error('onetouch_photos_unavailable');
            if(!self::sameContext($context,ManagerTourSelectionService::sourceContext($actor,$conversation)))return self::error('draft_changed');
            return ['ok'=>true,'version'=>$context['version'],'offer'=>$quote['offer'],'raw'=>$quote['raw'],'photos'=>$quote['photos'],'ticket'=>self::seal($session,$actor,$conversation,$context,$quote,'ready',$ticket['expires'])]+(!isset($quote['raw']['capture_mode'])?['source_reference'=>['quote_id'=>$quote['quote_id'],'url'=>$quote['url']]]:[]);
        }catch(Throwable $e){return self::error('onetouch_photos_unavailable');}
    }

    private const SEARCH_SECONDS=15;
    private const GALLERY_SECONDS=10;

    /** Only the explicitly assigned API callback performs a public source read. */
    public static function transport(string $url): array
    {
        $query=ManagerTourSelectionOneTouchSource::query($url);self::runtime();$deadline=microtime(true)+self::SEARCH_SECONDS;
        $formQuery=array_intersect_key($query,array_flip(['TOWNFROMINC','STATEINC','CHECKIN_BEG','CHECKIN_END','NIGHTS_FROM','NIGHTS_TILL','ADULT','CHILD','CURRENCY']));
        $formUrl='https://b2b.onetouch.travel/search_tour?'.http_build_query($formQuery,'','&',PHP_QUERY_RFC3986);
        return self::withPublicAddress($formUrl,'html',$deadline,static function(string $ip,float $end)use($query,$formUrl):array{
            $handle=curl_init();if($handle===false)self::unavailable();
            try{
                curl_setopt($handle,CURLOPT_COOKIEFILE,'');
                $form=self::readHttps($handle,$formUrl,'html',$ip,$end);$fresh=self::formContext($form,$query);
                $query['rev']=$fresh['rev'];$actual='https://b2b.onetouch.travel/search_tour?'.http_build_query($query,'','&',PHP_QUERY_RFC3986);
                ManagerTourSelectionOneTouchSource::query($actual);$cookies=curl_getinfo($handle,CURLINFO_COOKIELIST);
                if(!is_array($cookies)||!$cookies)self::unavailable();
                $response=self::readHttps($handle,$actual,'prices',$ip,$end,['X-CSRFToken: '.$fresh['csrf'],'X-Requested-With: XMLHttpRequest','Referer: '.$formUrl]);
                $sensitive=[$fresh['csrf']];foreach($cookies as $cookie){$parts=explode("\t",$cookie);$value=$parts[6]??'';if(strlen($value)>=8)$sensitive[]=$value;}
                foreach($sensitive as $value)if($value!==''&&(str_contains($response,$value)||str_contains($fresh['form'],$value)))self::unavailable();
                return ['url'=>$actual,'response'=>$response,'form'=>$fresh['form'],'observed_at'=>gmdate('Y-m-d\TH:i:s\Z')];
            }finally{curl_setopt($handle,CURLOPT_COOKIELIST,'ALL');curl_close($handle);}
        });
    }

    /** No search cookies or CSRF are transferred to the public hotel origin. */
    public static function fetchGallery(string $url): string
    {
        self::runtime();$deadline=microtime(true)+self::GALLERY_SECONDS;
        return self::withPublicAddress($url,'html',$deadline,static function(string $ip,float $end)use($url):string{
            $handle=curl_init();if($handle===false)self::unavailable();
            try{return self::readHttps($handle,$url,'html',$ip,$end);}finally{curl_close($handle);}
        });
    }

    /** The reader (and therefore source connect/TLS) cannot run until every
     * selectable resolved address has passed the public-destination guard.
     * Resolver injection is a private synthetic-test seam, never API input. */
    private static function withPublicAddress(string $url,string $kind,float $deadline,callable $read,?callable $resolve=null,?callable $clock=null): mixed
    {
        $policy=self::readPolicy($url,$kind);self::remaining($deadline,$clock!==null?$clock():null);
        $resolve??=static fn(string $host,float $end):array=>self::lookupA($host,$end);
        $ips=$resolve($policy['host'],$deadline);
        if(!is_array($ips)||!array_is_list($ips)||!$ips||count($ips)>16)self::unavailable();
        foreach($ips as $ip)if(!is_string($ip)||!self::publicIp($ip))self::unavailable();
        self::remaining($deadline,$clock!==null?$clock():null);return $read($ips[0],$deadline);
    }

    private static function systemFile(string $file,float $deadline): string
    {
        // This check precedes all filesystem reads. No Windows/Mac fallback.
        if(PHP_OS_FAMILY!=='Linux'||!in_array($file,['/etc/hosts','/etc/resolv.conf'],true))self::unavailable();
        self::remaining($deadline);$text=@file_get_contents($file,false,null,0,8193);self::remaining($deadline);
        if(!is_string($text)||strlen($text)>8192)self::unavailable();return $text;
    }

    private static function hostsAddress(string $host,string $text): ?array
    {
        $addresses=[];
        foreach(preg_split('/[\r\n]+/',$text) as $line){
            $line=trim(explode('#',$line,2)[0]);if($line==='')continue;$parts=preg_split('/\s+/',$line);
            $aliases=array_map(static fn($name)=>strtolower(rtrim($name,'.')),array_slice($parts,1));
            if(!in_array($host,$aliases,true))continue;
            if(!self::publicIp($parts[0]))self::unavailable();$addresses[$parts[0]]=true;
        }
        if(count($addresses)>1)self::unavailable();return $addresses?array_keys($addresses):null;
    }

    private static function assignedResolver(string $text): string
    {
        $server=null;
        foreach(preg_split('/[\r\n]+/',$text) as $line){
            $line=trim(preg_split('/[#;]/',$line,2)[0]);if($line==='')continue;$parts=preg_split('/\s+/',$line);
            if($parts[0]==='options'&&in_array('use-vc',$parts,true))self::unavailable();
            if($parts[0]!=='nameserver'||$server!==null)continue;
            if(count($parts)!==2||filter_var($parts[1],FILTER_VALIDATE_IP)===false)self::unavailable();$server=$parts[1];
        }
        if($server===null)self::unavailable();return $server;
    }

    private static function resolverPeer(string $server,string $peer): bool
    {
        if(preg_match('/\A\[([a-f0-9:.]+)\]:53\z/iD',$peer,$m)||preg_match('/\A([0-9.]+):53\z/D',$peer,$m))return @inet_pton($m[1])!==false&&inet_pton($server)===inet_pton($m[1]);
        return false;
    }

    private static function dnsQuestion(string $host,int $id): string
    {
        if(!in_array($host,['b2b.onetouch.travel','onetouch.travel'],true)||$id<0||$id>65535)self::unavailable();
        $wire=pack('nnnnnn',$id,0x0100,1,0,0,0);foreach(explode('.',$host) as $label)$wire.=chr(strlen($label)).$label;
        return $wire."\0".pack('nn',1,1);
    }

    private static function dnsName(string $packet,int &$offset): string
    {
        $cursor=$offset;$jumped=false;$seen=[];$labels=[];$length=0;
        for($step=0;$step<32;$step++){
            if($cursor>=strlen($packet)||isset($seen[$cursor]))self::unavailable();$seen[$cursor]=true;$size=ord($packet[$cursor]);
            if($size===0){if(!$jumped)$offset=$cursor+1;return strtolower(implode('.',$labels));}
            if(($size&0xc0)===0xc0){
                if($cursor+1>=strlen($packet))self::unavailable();$pointer=(($size&0x3f)<<8)|ord($packet[$cursor+1]);
                if($pointer<12||$pointer>=$cursor)self::unavailable();if(!$jumped)$offset=$cursor+2;$jumped=true;$cursor=$pointer;continue;
            }
            if(($size&0xc0)!==0||$size>63||$cursor+1+$size>strlen($packet))self::unavailable();
            $label=substr($packet,$cursor+1,$size);if(!preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/iD',$label))self::unavailable();
            $length+=$size+($labels?1:0);if($length>253)self::unavailable();$labels[]=$label;$cursor+=1+$size;
        }
        self::unavailable();
    }

    /** One bounded response only; no follow-up CNAME, TCP or alternate DNS. */
    private static function dnsAddresses(string $packet,string $host,int $id): array
    {
        if(strlen($packet)<17||strlen($packet)>4096)self::unavailable();$h=unpack('nid/nflags/nqd/nan/nns/nar',substr($packet,0,12));
        if($h['id']!==$id||($h['flags']&0x8000)===0||($h['flags']&0x7800)!==0||($h['flags']&0x0200)!==0||($h['flags']&0x004f)!==0||$h['qd']!==1||$h['an']<1||$h['an']>32||$h['ns']+$h['ar']>32)self::unavailable();
        $offset=12;if(self::dnsName($packet,$offset)!==$host||$offset+4>strlen($packet)||substr($packet,$offset,4)!==pack('nn',1,1))self::unavailable();$offset+=4;$addresses=[];$aliases=[];
        for($i=0;$i<$h['an']+$h['ns']+$h['ar'];$i++){
            $owner=self::dnsName($packet,$offset);if($offset+10>strlen($packet))self::unavailable();$rr=unpack('ntype/nclass/Nttl/nsize',substr($packet,$offset,10));$offset+=10;$end=$offset+$rr['size'];if($end>strlen($packet))self::unavailable();
            if($i<$h['an']){
                if($rr['class']!==1)self::unavailable();
                if($rr['type']===1){if($rr['size']!==4)self::unavailable();$addresses[$owner][]=inet_ntop(substr($packet,$offset,4));}
                elseif($rr['type']===5){$position=$offset;$alias=self::dnsName($packet,$position);if($alias===''||$position!==$end||isset($aliases[$owner])&&$aliases[$owner]!==$alias)self::unavailable();$aliases[$owner]=$alias;}
            }
            $offset=$end;
        }
        if($offset!==strlen($packet))self::unavailable();$visited=[];
        for($i=0;$i<8;$i++){
            if(isset($visited[$host]))self::unavailable();$visited[$host]=true;
            if(isset($addresses[$host])){if(isset($aliases[$host]))self::unavailable();$ips=array_values(array_unique($addresses[$host]));foreach($ips as $ip)if(!self::publicIp($ip))self::unavailable();return $ips;}
            if(!isset($aliases[$host]))self::unavailable();$host=$aliases[$host];
        }
        self::unavailable();
    }

    private static function lookupA(string $host,float $deadline): array
    {
        if(PHP_OS_FAMILY!=='Linux'||!in_array($host,['b2b.onetouch.travel','onetouch.travel'],true))self::unavailable();
        $override=self::hostsAddress($host,self::systemFile('/etc/hosts',$deadline));if($override!==null)return $override;
        $server=self::assignedResolver(self::systemFile('/etc/resolv.conf',$deadline));$dnsEnd=min($deadline,microtime(true)+1.5);$id=random_int(0,65535);$query=self::dnsQuestion($host,$id);
        $endpoint=str_contains($server,':')?'['.$server.']':$server;$socket=@stream_socket_client('udp://'.$endpoint.':53',$errno,$error,self::remaining($dnsEnd)/1000,STREAM_CLIENT_CONNECT);
        if($socket===false)self::unavailable();
        try{
            if(!stream_set_blocking($socket,false)||!self::resolverPeer($server,(string)stream_socket_get_name($socket,true))||@fwrite($socket,$query)!==strlen($query))self::unavailable();
            $ms=self::remaining($dnsEnd);$read=[$socket];$write=null;$except=null;
            if(@stream_select($read,$write,$except,intdiv($ms,1000),($ms%1000)*1000)!==1)self::unavailable();
            $peer='';$packet=@stream_socket_recvfrom($socket,4097,0,$peer);self::remaining($dnsEnd);
            if(!is_string($packet)||!self::resolverPeer($server,$peer))self::unavailable();
            return self::dnsAddresses($packet,$host,$id);
        }finally{fclose($socket);}
    }

    private static function runtime(): void
    {
        if(PHP_OS_FAMILY!=='Linux')self::unavailable();
        foreach(['curl_init','stream_socket_client','stream_select','stream_socket_recvfrom','file_get_contents'] as $function)if(!function_exists($function))self::unavailable();
        // Do not disable or replace a process-assigned proxy/egress. A proxy's
        // destination resolution cannot be verified by this direct reader.
        foreach(['HTTP_PROXY','http_proxy','HTTPS_PROXY','https_proxy','ALL_PROXY','all_proxy','RES_OPTIONS','LOCALDOMAIN'] as $key){$value=getenv($key);if(is_string($value)&&$value!=='')self::unavailable();}
    }

    private static function readPolicy(string $url,string $kind): array
    {
        $p=parse_url($url);
        if(!$p||strlen($url)>4096||preg_match('/[\x00-\x20\x7f]/',$url)||($p['scheme']??'')!=='https'||array_intersect(['user','pass','port','fragment'],array_keys($p)))self::unavailable();
        $host=$p['host']??'';$path=$p['path']??'';
        if($host==='b2b.onetouch.travel'&&$path==='/search_tour'){
            if($kind==='prices')ManagerTourSelectionOneTouchSource::query($url);
            elseif($kind==='html'){
                $form=[];foreach(explode('&',$p['query']??'') as $pair){if(!preg_match('/\A([A-Z_]+)=([0-9]+)\z/D',$pair,$m)||isset($form[$m[1]]))self::unavailable();$form[$m[1]]=$m[2];}
                $keys=['TOWNFROMINC','STATEINC','CHECKIN_BEG','CHECKIN_END','NIGHTS_FROM','NIGHTS_TILL','ADULT','CHILD','CURRENCY'];
                if(array_diff(array_keys($form),$keys)||array_diff($keys,array_keys($form))||$form['TOWNFROMINC']!=='2'||$form['STATEINC']!=='9'||$form['CHILD']!=='0'||$form['CURRENCY']!=='1'||!preg_match('/\A(?:[1-9]|1[0-4])\z/D',$form['ADULT'])||self::scalar($form['CHECKIN_BEG'],true)!==$form['CHECKIN_BEG']||$form['CHECKIN_BEG']!==$form['CHECKIN_END']||$form['NIGHTS_FROM']!==$form['NIGHTS_TILL']||!preg_match('/\A[1-9][0-9]?\z/D',$form['NIGHTS_FROM'])||(int)$form['NIGHTS_FROM']>60)self::unavailable();
            }else self::unavailable();
        }elseif($host==='onetouch.travel'&&$kind==='html'&&!isset($p['query'])&&preg_match('~-([1-9][0-9]{0,11})/$~D',$path,$m)&&ManagerTourSelectionOneTouchSource::hotelUrl($url,$m[1])===$url){
            // Exact linked public hotel page, never a booking or agent endpoint.
        }else self::unavailable();
        return ['host'=>$host,'types'=>$kind==='prices'?['text/javascript','application/javascript','application/ecmascript','text/ecmascript','application/x-ecmascript']:['text/html','application/xhtml+xml']];
    }

    private static function publicIp(string $ip): bool
    {
        // The observed source transport is IPv4. Fail closed for other families
        // rather than permitting mapped, scoped or ambiguous address literals.
        if(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)===false)return false;
        $n=unpack('N',inet_pton($ip))[1];
        foreach([['100.64.0.0',10],['192.0.0.0',24],['192.0.2.0',24],['198.18.0.0',15],['198.51.100.0',24],['203.0.113.0',24],['224.0.0.0',4],['240.0.0.0',4]] as [$start,$bits])if(($n>>(32-$bits))===(unpack('N',inet_pton($start))[1]>>(32-$bits)))return false;
        return true;
    }

    private static function remaining(float $deadline,?float $clock=null): int
    {
        $ms=(int)floor(($deadline-($clock??microtime(true)))*1000);if($ms<1)self::unavailable();return min(8000,$ms);
    }

    private static function responseAllowed(int $status,string $type,int $size,int $headers,array $types,int $age,bool $redirect,bool $encoded): bool
    {
        return $status===200&&in_array(strtolower(trim(explode(';',$type,2)[0])),$types,true)&&$size>0&&$size<=ManagerTourSelectionOneTouchSource::MAX_BYTES&&$headers<=32768&&$age===0&&!$redirect&&!$encoded;
    }

    private static function readHttps($handle,string $url,string $kind,string $ip,float $deadline,array $extra=[]): string
    {
        $policy=self::readPolicy($url,$kind);if(!self::publicIp($ip))self::unavailable();
        $body='';$headers=0;$age=0;$redirect=false;$encoded=false;
        $options=[CURLOPT_URL=>$url,CURLOPT_HTTPGET=>true,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4,CURLOPT_RESOLVE=>[$policy['host'].':443:'.$ip],CURLOPT_CONNECTTIMEOUT_MS=>min(3000,self::remaining($deadline)),CURLOPT_TIMEOUT_MS=>self::remaining($deadline),CURLOPT_RETURNTRANSFER=>false,CURLOPT_HTTPHEADER=>array_merge(['Accept: '.implode(', ',$policy['types']),'Accept-Encoding: identity','Cache-Control: no-cache, no-store','Pragma: no-cache'],$extra),
            CURLOPT_WRITEFUNCTION=>static function($h,string $chunk)use(&$body):int{if(strlen($body)+strlen($chunk)>ManagerTourSelectionOneTouchSource::MAX_BYTES)return 0;$body.=$chunk;return strlen($chunk);},
            CURLOPT_HEADERFUNCTION=>static function($h,string $line)use(&$headers,&$age,&$redirect,&$encoded):int{$headers+=strlen($line);if($headers>32768)return 0;if(preg_match('/\AAge:\s*(\d+)/i',$line,$m))$age=max($age,(int)$m[1]);if(preg_match('/\ALocation:/i',$line))$redirect=true;if(preg_match('/\AContent-Encoding:\s*([^\r\n]+)/i',$line,$m)&&strtolower(trim($m[1]))!=='identity')$encoded=true;return strlen($line);}];
        if(!curl_setopt_array($handle,$options)||curl_exec($handle)===false||curl_getinfo($handle,CURLINFO_SSL_VERIFYRESULT)!==0||curl_getinfo($handle,CURLINFO_PRIMARY_IP)!==$ip||curl_getinfo($handle,CURLINFO_PRIMARY_PORT)!==443||curl_getinfo($handle,CURLINFO_EFFECTIVE_URL)!==$url||!self::responseAllowed((int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE),(string)curl_getinfo($handle,CURLINFO_CONTENT_TYPE),strlen($body),$headers,$policy['types'],$age,$redirect,$encoded))self::unavailable();
        self::remaining($deadline);return $body;
    }

    private static function document(string $html): DOMXPath
    {
        if($html===''||strlen($html)>ManagerTourSelectionOneTouchSource::MAX_BYTES||preg_match('//u',$html)!==1||stripos($html,'<!ENTITY')!==false)self::unavailable();
        $old=libxml_use_internal_errors(true);
        try{$d=new DOMDocument();$d->resolveExternals=false;$d->substituteEntities=false;if(!$d->loadHTML('<?xml encoding="utf-8"?>'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING))self::unavailable();return new DOMXPath($d);}
        finally{libxml_clear_errors();libxml_use_internal_errors($old);}
    }

    private static function scalar(string $value,bool $date=false): string
    {
        if($date){foreach(['!d.m.Y','!Ymd','!Y-m-d'] as $format){$d=DateTimeImmutable::createFromFormat($format,$value);$errors=DateTimeImmutable::getLastErrors();if($d&&($errors===false||!$errors['warning_count']&&!$errors['error_count'])&&$d->format(substr($format,1))===$value)return $d->format('Ymd');}self::unavailable();}
        return preg_match('/\A[+-]?[0-9]+/',trim($value),$m)?(string)(int)$m[0]:'0';
    }

    private static function formContext(string $html,array $query): array
    {
        $x=self::document($html);$class=static fn(string $s)=>'contains(concat(" ",normalize-space(@class)," ")," '.$s.' ")';
        $tokens=[];foreach($x->query('//*['.$class('samo_container').'][@data-csrf-token]') as $node)$tokens[]=$node->getAttribute('data-csrf-token');
        if(count(array_unique($tokens))!==1||!preg_match('/\A[\x21-\x7e]{1,1024}\z/D',$tokens[0]??''))self::unavailable();
        $revs=[];foreach($x->query('//script[@src]') as $node){$src=$node->getAttribute('src');if(str_starts_with($src,'/'))$src='https://b2b.onetouch.travel'.$src;if(preg_match('~\Ahttps://b2b\.onetouch\.travel/public/js/(pack\.main\.js|search_tour/search_tour\.js)\?rev=([0-9]{1,12})\z~D',$src,$m))$revs[$m[1]][]=$m[2];}
        if(count($revs['pack.main.js']??[])!==1||count($revs['search_tour/search_tour.js']??[])!==1||$revs['pack.main.js'][0]!==$revs['search_tour/search_tour.js'][0])self::unavailable();
        $controls=[];$publicForm='';$cached=explode(' ','DISTRICTS STATEFROM STATEINC TOWNFROMINC TOURINC PROGRAMGROUPINC PROGRAMINC TOWNS HOTELS STARS MEALS ROOMS CURRENCY NIGHTS_FROM NIGHTS_TILL ADULT CHILD FILTER FREIGHT MOMENT_CONFIRM CHILD_IN_BED COMFORTABLE_SEATS COSTMIN COSTMAX CHECKIN_BEG CHECKIN_END HOTELSSEL TOURTYPE PRODUCTTYPE TOURGROUP FREIGHTTYPE TOWNS_ANY STARS_ANY HOTELS_ANY MEALS_ANY ROOMS_ANY hotelsearch UFILTER WITHOUT_PROMO CHECKIN_LIST NIGHTS_LIST townssearch');
        foreach($x->query('//select[@name]|//input[@name]') as $node){
            $name=$node->getAttribute('name');$classes=preg_split('/\s+/',trim($node->getAttribute('class')));if(!array_intersect($classes,$cached))continue;
            if($node->hasAttribute('disabled'))self::unavailable();
            if($node->tagName==='select'){
                $options=iterator_to_array($x->query('.//option',$node));$enabled=array_values(array_filter($options,static fn($o)=>!$o->hasAttribute('disabled')));
                if(in_array($name,['ADULT','CHILD'],true)&&!array_filter($enabled,static fn($o)=>$o->getAttribute('value')===$query[$name]))self::unavailable();
                $selected=array_values(array_filter($options,static fn($o)=>$o->hasAttribute('selected')));$multiple=$node->hasAttribute('multiple');if(!$selected&&!$multiple)$selected=array_slice($enabled,0,1);
                foreach($selected as $option)if($option->hasAttribute('disabled'))self::unavailable();
                $values=array_map(static fn($o)=>$o->getAttribute('value'),$selected);
                if($multiple)$value=implode(',',array_filter($values,static fn($v)=>$v!=='0'));
                else {if(count($values)!==1)self::unavailable();$value=self::scalar($values[0],in_array('date',$classes,true));}
                if(in_array($name,['TOWNFROMINC','STATEINC','CURRENCY'],true)){
                    if($multiple||count($selected)!==1)self::unavailable();$label=trim(preg_replace('/\s+/u',' ',$selected[0]->textContent));
                    if($label===''||strlen($label)>240)self::unavailable();$publicForm.='<select name="'.$name.'"><option selected value="'.$value.'">'.htmlspecialchars($label,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</option></select>';
                }
            }else{
                $type=strtolower($node->getAttribute('type')?:'text');$value=in_array($type,['checkbox','radio'],true)?($node->hasAttribute('checked')?'1':'0'):(in_array($type,['button','image'],true)?'':self::scalar($node->getAttribute('value'),in_array('date',$classes,true)));
            }
            if(in_array($name,['COSTMIN','COSTMAX'],true)&&$value==='0')continue;$controls[$name][]=$value;
        }
        foreach($x->query('//div['.$class('checklistbox').'][@name]') as $node){$name=$node->getAttribute('name');if(!in_array($name,$cached,true))continue;$values=[];
            foreach($x->query('.//input[@checked]',$node) as $input){$classes=preg_split('/\s+/',trim($input->getAttribute('class')));if($name==='STARS'&&!in_array('star',$classes,true)||$name==='TOWNS'&&in_array('district',$classes,true))continue;$v=self::scalar($input->getAttribute('value'));if($v!=='0')$values[]=$v;}$controls[$name][]=implode(',',$values);
        }
        $types=[];foreach($x->query('//input['.$class('hoteltype').'][@checked]') as $n)$types[]=self::scalar($n->getAttribute('value'));$controls['HOTELTYPES']=[implode(',',$types)];
        $parts=[];foreach($x->query('//input['.$class('PARTITION_PRICE').']') as $n)$parts[]=$n->hasAttribute('checked')?$n->getAttribute('value'):'';$controls['PARTITION_PRICE']=$parts?:[''];
        $expected=array_diff_key($query,array_flip(['samo_action','PRICEPAGE','DYN_SEPARATE','rev']));
        if(array_diff(array_keys($controls),array_keys($expected))||array_diff(array_keys($expected),array_keys($controls)))self::unavailable();
        foreach($controls as $name=>$values)foreach($values as $value)if($value!==$expected[$name])self::unavailable();
        return ['rev'=>$revs['pack.main.js'][0],'csrf'=>$tokens[0],'form'=>$publicForm];
    }

    private static function unavailable(): never{throw new RuntimeException('onetouch_source_unavailable');}


    private static function sameContext(array $a,array $b): bool{return ($a['version']??null)===($b['version']??null)&&is_string($a['context_hash']??null)&&is_string($b['context_hash']??null)&&hash_equals($a['context_hash'],$b['context_hash']);}
    private static function seal(string $key,int $actor,int $conversation,array $context,array $quote,string $stage,?int $expires=null): string
    {
        $body=json_encode(['schema'=>1,'actor'=>$actor,'conversation'=>$conversation,'version'=>$context['version'],'context_hash'=>$context['context_hash'],'expires'=>$expires??time()+600,'stage'=>$stage,'observation'=>$quote],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $encoded=rtrim(strtr(base64_encode($body),'+/','-_'),'=');return $encoded.'.'.hash_hmac('sha256',$encoded,'onetouch-preview-v1|'.$key);
    }
    public static function open($value,string $key,int $actor,int $conversation,string $stage,bool $allowExpired=false): ?array
    {
        if(!preg_match('/\A[a-f0-9]{64}\z/D',$key)||!is_string($value)||strlen($value)>32768||!preg_match('/\A([A-Za-z0-9_-]+)\.([a-f0-9]{64})\z/D',$value,$m)||!hash_equals(hash_hmac('sha256',$m[1],'onetouch-preview-v1|'.$key),$m[2]))return null;
        try{$payload=base64_decode(strtr($m[1],'-_','+/'),true);$d=is_string($payload)?json_decode($payload,true,24,JSON_THROW_ON_ERROR):null;}catch(JsonException $e){return null;}
        if(!is_array($d)||($d['schema']??null)!==1||($d['actor']??null)!==$actor||($d['conversation']??null)!==$conversation||($d['stage']??null)!==$stage||!is_int($d['version']??null)||$d['version']<0||!is_string($d['context_hash']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$d['context_hash'])||!is_int($d['expires']??null)||(!$allowExpired&&$d['expires']<time())||$d['expires']>time()+600||!is_array($d['observation']??null))return null;
        return $d;
    }

    private static function error(string $code):array{return ['ok'=>false,'error'=>$code];}
}
