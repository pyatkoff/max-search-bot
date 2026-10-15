<?php
declare(strict_types=1);

require_once __DIR__.'/../services/MaxTlsConfig.php';

/** Private-dialogue portraits only. No writes, retries, redirects or raw URL logging. */
final class MaxCustomerAvatarAdapter
{
    private const API = 'https://platform-api2.max.ru';
    // The public official https://max.ru/anytour page links its portrait here.
    // Keep the exact origin; an unknown CDN must fall back to initials.
    private const IMAGE_HOST = 'i.oneme.ru';
    private $exchange;
    private $resolver;
    private $clock;

    /** Injectable transports/clock are for isolated tests, never browser input. */
    public function __construct(?callable $exchange=null, ?callable $resolver=null, ?callable $clock=null)
    {
        $this->exchange=$exchange ?? [self::class,'exchange'];
        $this->resolver=$resolver ?? [self::class,'resolve'];
        $this->clock=$clock ?? static fn():float=>microtime(true);
    }

    public function photo(string $userId, string $mid, int $limit): ?string
    {
        $token=defined('MAX_SEARCH_TOKEN')?trim((string)MAX_SEARCH_TOKEN):'';
        if (self::decimalId($userId)===null || !preg_match('/\A(?:mid\.)?[A-Za-z0-9_-]{1,200}\z/D',$mid)
            || $token==='' || preg_match('/[\x00-\x20\x7f]/',$token) || $limit<1 || $limit>262144) return null;
        $deadline=($this->clock)()+5.0;
        try {
            $message=$this->json(self::API.'/messages/'.rawurlencode($mid),$token,$deadline);
            $recipient=is_array($message['recipient']??null)?$message['recipient']:[];
            $chat=self::chatId($recipient['chat_id']??null);
            if (($message['body']['mid']??null)!==$mid || self::decimalId($message['sender']['user_id']??null)!==$userId
                || ($recipient['chat_type']??null)!=='dialog' || $chat===null) return null;
            unset($message,$recipient);
            $data=$this->json(self::API.'/chats/'.$chat,$token,$deadline);
            $user=is_array($data['dialog_with_user']??null)?$data['dialog_with_user']:[];
            if (($data['type']??null)!=='dialog' || self::chatId($data['chat_id']??null)!==$chat
                || self::decimalId($user['user_id']??null)!==$userId) return null;
            // Fetch only the documented thumbnail, never Chat.icon or a full-size fallback.
            $url=$user['avatar_url']??null;
            if (!self::imageUrl($url)) return null;
            unset($data,$user);
            $budget=$this->budget($deadline);
            if ($budget<=0) return null;
            $ips=($this->resolver)(self::IMAGE_HOST,min(600,$budget));
            if (!is_array($ips) || !$ips || count($ips)>32) return null;
            foreach ($ips as $ip) if (!is_string($ip) || !self::publicIpv4($ip)) return null;
            // Pin the checked address. DNS changes cannot redirect this request.
            return $this->read($url,[],$limit,$deadline,$ips[0]);
        } catch (Throwable $ignored) {return null;}
    }

    public static function decimalId($value): ?string
    {
        if (is_int($value) && $value>0) $value=(string)$value;
        if (!is_string($value) || !preg_match('/\A[1-9][0-9]{0,18}\z/D',$value)) return null;
        return strlen($value)<19 || strcmp($value,'9223372036854775807')<=0?$value:null;
    }

    public static function chatId($value): ?string
    {
        if (is_int($value) && $value!==0) $value=(string)$value;
        if (!is_string($value) || !preg_match('/\A-?[1-9][0-9]{0,18}\z/D',$value)) return null;
        $negative=$value[0]==='-';$digits=$negative?substr($value,1):$value;
        return strlen($digits)<19 || strcmp($digits,$negative?'9223372036854775808':'9223372036854775807')<=0?$value:null;
    }

    public static function imageUrl($url): bool
    {
        if (!is_string($url) || $url==='' || strlen($url)>4096 || preg_match('/[\x00-\x20\x7f\\\\]/',$url)) return false;
        $p=@parse_url($url);
        return is_array($p) && ($p['scheme']??'')==='https' && ($p['host']??'')===self::IMAGE_HOST
            && (!isset($p['port']) || $p['port']===443)
            && !isset($p['user']) && !isset($p['pass']) && !isset($p['fragment'])
            && str_starts_with((string)($p['path']??''),'/');
    }

    /** IPv4-only pinning; reject special-use, multicast and carrier NAT too. */
    public static function publicIpv4(string $ip): bool
    {
        if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) return false;
        $n=unpack('N',inet_pton($ip))[1];
        foreach ([[0,8],[0x64400000,10],[0xc0000000,24],[0xc0000200,24],[0xc0586300,24],
            [0xc6120000,15],[0xc6336400,24],[0xcb007100,24],[0xe0000000,4],[0xf0000000,4]] as [$base,$bits]) {
            if (($n & (0xffffffff << (32-$bits)))===$base) return false;
        }
        return true;
    }

    private function budget(float $deadline): int
    {
        return max(0,(int)(($deadline-($this->clock)())*1000));
    }

    private function json(string $url, string $token, float $deadline): ?array
    {
        $bytes=$this->read($url,['Authorization: '.$token,'Accept: application/json'],65536,$deadline);
        if ($bytes===null) return null;
        try {$data=json_decode($bytes,true,24,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}
        catch (Throwable $ignored) {return null;}
        return is_array($data)?$data:null;
    }

    private function read(string $url, array $headers, int $limit, float $deadline, ?string $ip=null): ?string
    {
        $budget=$this->budget($deadline);if ($budget<=0) return null;
        $bytes='';$tooLarge=false;
        $options=[CURLOPT_CUSTOMREQUEST=>'GET',CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_CONNECTTIMEOUT_MS=>min(800,$budget),CURLOPT_TIMEOUT_MS=>$budget,
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_PROXY=>'',CURLOPT_RETURNTRANSFER=>false,
            CURLOPT_WRITEFUNCTION=>static function($h,string $chunk) use(&$bytes,&$tooLarge,$limit):int {
                if (strlen($bytes)+strlen($chunk)>$limit) {$tooLarge=true;return 0;}
                $bytes.=$chunk;return strlen($chunk);
            }]+MaxTlsConfig::strictCurlOptions();
        if ($ip!==null) {
            if ($headers!==[] || !self::imageUrl($url) || !self::publicIpv4($ip)) return null;
            $options[CURLOPT_RESOLVE]=[self::IMAGE_HOST.':443:'.$ip];
            $options[CURLOPT_IPRESOLVE]=CURL_IPRESOLVE_V4;
        } elseif (!preg_match('~\Ahttps://platform-api2\.max\.ru/(?:messages/(?:mid\.)?[A-Za-z0-9_-]{1,200}|chats/-?[1-9][0-9]{0,18})\z~D',$url)) return null;
        $receipt=($this->exchange)($url,$options);
        if ($this->budget($deadline)<=0 || $tooLarge || !is_array($receipt)
            || !empty($receipt['transport_error']) || ($receipt['http_status']??0)!==200
            || ($receipt['ssl_verify_result']??-1)!==0 || ($ip!==null && ($receipt['primary_ip']??'')!==$ip)) return null;
        return $bytes;
    }

    private static function exchange(string $url, array $options): array
    {
        $ch=curl_init($url);curl_setopt_array($ch,$options);
        $ok=curl_exec($ch);$receipt=['http_status'=>(int)curl_getinfo($ch,CURLINFO_HTTP_CODE),
            'ssl_verify_result'=>(int)curl_getinfo($ch,CURLINFO_SSL_VERIFYRESULT),
            'primary_ip'=>(string)curl_getinfo($ch,CURLINFO_PRIMARY_IP),'transport_error'=>$ok===false || curl_errno($ch)!==0];
        curl_close($ch);return $receipt;
    }

    /** One bounded UDP A lookup; avoids unbounded libc DNS in the request worker. */
    private static function resolve(string $host, int $budget): array
    {
        if ($host!==self::IMAGE_HOST || $budget<=0) return [];
        $config=@file_get_contents('/etc/resolv.conf',false,null,0,16384);
        if (!is_string($config) || !preg_match('/^\s*nameserver\s+([^\s#]+)/m',$config,$m)
            || !filter_var($m[1],FILTER_VALIDATE_IP)) return [];
        $resolver=str_contains($m[1],':')?'['.$m[1].']':$m[1];
        $deadline=microtime(true)+min(600,$budget)/1000;
        $socket=@stream_socket_client('udp://'.$resolver.':53',$errno,$error,min(0.6,$budget/1000));
        if (!$socket) return [];
        try {
            $id=random_int(1,65535);$question='';
            foreach (explode('.',$host) as $label) $question.=chr(strlen($label)).$label;
            $question.="\0".pack('nn',1,1);
            $packet=pack('nnnnnn',$id,0x0100,1,0,0,0).$question;
            if (@fwrite($socket,$packet)!==strlen($packet)) return [];
            $left=max(0,(int)(($deadline-microtime(true))*1000000));if ($left===0) return [];
            stream_set_timeout($socket,0,$left);$response=@fread($socket,4096);
            return is_string($response)?self::dnsAddresses($response,$id,$question):[];
        } finally {fclose($socket);}
    }

    /** Parse only bounded, matching, nontruncated DNS answers from that resolver. */
    private static function dnsAddresses(string $packet, int $id, string $question): array
    {
        if (strlen($packet)<12 || strlen($packet)>4096) return [];
        $h=unpack('nid/nflags/nquestions/nanswers/nauthority/nadditional',substr($packet,0,12));
        if ($h['id']!==$id || ($h['flags']&0xfa0f)!==0x8000 || $h['questions']!==1
            || $h['answers']<1 || $h['answers']>32 || substr($packet,12,strlen($question))!==$question) return [];
        $offset=12+strlen($question);$records=[];$aliases=[];$length=strlen($packet);
        for ($answer=0;$answer<$h['answers'];$answer++) {
            $name=self::dnsName($packet,$offset);if ($name===null) return [];
            if ($offset+10>$length) return [];
            $r=unpack('ntype/nclass/Nttl/nsize',substr($packet,$offset,10));$offset+=10;
            if ($offset+$r['size']>$length) return [];
            if ($r['class']===1 && $r['type']===1) {
                if ($r['size']!==4) return [];
                $ip=inet_ntop(substr($packet,$offset,4));
                if (!self::publicIpv4($ip)) return [];
                $records[$name][]=$ip;
            }
            if ($r['class']===1 && $r['type']===5) {
                $end=$offset;$alias=self::dnsName($packet,$end);
                if ($alias===null || $end!==$offset+$r['size'] || isset($aliases[$name])) return [];
                $aliases[$name]=$alias;
            }
            $offset+=$r['size'];
        }
        $name=self::IMAGE_HOST;$seen=[];
        for ($i=0;$i<16;$i++) {
            if (isset($seen[$name]) || (isset($records[$name]) && isset($aliases[$name]))) return [];
            $seen[$name]=true;
            if (isset($records[$name])) return array_values(array_unique($records[$name]));
            if (!isset($aliases[$name])) return [];
            $name=$aliases[$name];
        }
        return [];
    }

    /** DNS compression pointers never escape the bounded response or loop. */
    private static function dnsName(string $packet, int &$offset): ?string
    {
        $at=$offset;$end=null;$parts=[];$seen=[];$length=strlen($packet);$bytes=0;
        for ($i=0;$i<128;$i++) {
            if ($at>=$length || isset($seen[$at])) return null;
            $seen[$at]=true;$size=ord($packet[$at++]);
            if (($size&0xc0)===0xc0) {
                if ($at>=$length) return null;
                $pointer=(($size&0x3f)<<8)|ord($packet[$at++]);
                if ($pointer<12 || $pointer>=$at-2) return null;
                if ($end===null) $end=$at;
                $at=$pointer;continue;
            }
            if (($size&0xc0)!==0 || $size>63 || $at+$size>$length) return null;
            if ($size===0) {$offset=$end??$at;return $parts?strtolower(implode('.',$parts)):null;}
            $label=substr($packet,$at,$size);
            if (!preg_match('/\A[A-Za-z0-9_-]+\z/D',$label) || ($bytes+=$size+1)>254) return null;
            $parts[]=$label;$at+=$size;
        }
        return null;
    }
}
