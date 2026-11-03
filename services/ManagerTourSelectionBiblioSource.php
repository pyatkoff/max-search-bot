<?php
declare(strict_types=1);
require_once __DIR__.'/ManagerTourSelectionBiblioImportDraft.php';
require_once __DIR__.'/ManagerTourSelectionImportDraft.php';

/** Read-only public-source intake, scoped to the existing protected selection. */
final class ManagerTourSelectionBiblioSource
{
    public const MAX_BYTES=2097152;
    private const ORIGIN='https://www.bgoperator.ru';

    /** The cookie/session ID is known to its owner, so it is never a signing secret. */
    public static function sessionKey(array &$state,string $sessionHash): string
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/D',$sessionHash)) throw new InvalidArgumentException('Invalid source session binding');
        $field='tour_selection_biblio_signing_secret';
        if (!is_string($state[$field]??null) || !preg_match('/\A[a-f0-9]{64}\z/D',$state[$field])) $state[$field]=bin2hex(random_bytes(32));
        return hash_hmac('sha256','biblio-source-session-v1|'.$sessionHash,$state[$field]);
    }

    public static function search(int $actor,int $conversation,string $session,array $data,?callable $fetch=null): array
    {
        $context=ManagerTourSelectionService::sourceContext($actor,$conversation);
        if (empty($context['ok'])) return $context;
        try {
            $url=self::searchUrl($data['url']??null);
            if ($url===null) return self::error('biblio_invalid_url');
            $offset=$data['offset']??0;
            if (!is_int($offset) || $offset<0 || $offset>500) return self::error('biblio_invalid_url');
            $quotes=self::parse(self::read($url,$fetch),$url);
            if (!self::sameContext($context,ManagerTourSelectionService::sourceContext($actor,$conversation))) return self::error('draft_changed');
            $rows=[];
            foreach (array_slice($quotes,$offset,20) as $quote) $rows[]=['offer'=>$quote['offer'],'raw'=>$quote['raw'],'ticket'=>self::seal($session,$actor,$conversation,$context,$quote,'quote')];
            return ['ok'=>true,'offers'=>$rows,'total'=>count($quotes),'next_offset'=>count($quotes)>$offset+20?$offset+20:null,'version'=>$context['version']];
        } catch (RuntimeException $e) {return self::error(in_array($e->getMessage(),['biblio_source_unavailable','biblio_source_changed','biblio_no_quotes'],true)?$e->getMessage():'biblio_source_unavailable');}
    }

    public static function review(int $actor,int $conversation,string $session,array $data,?callable $fetch=null): array
    {
        $context=ManagerTourSelectionService::sourceContext($actor,$conversation);
        if (empty($context['ok'])) return $context;
        $ticket=self::open($data['ticket']??null,$session,$actor,$conversation,'quote');
        if ($ticket===null) return self::error('biblio_expired');
        if (!self::sameContext($context,$ticket)) return self::error('draft_changed');
        try {
            $quote=$ticket['observation'];
            $photos=self::gallery(self::read($quote['url'],$fetch),$quote['keys']['hotel'],$quote['offer']['hotel']);
            if (!$photos) return self::error('biblio_photos_unavailable');
            $quote['photos']=$photos;
            if (!self::sameContext($context,ManagerTourSelectionService::sourceContext($actor,$conversation))) return self::error('draft_changed');
            if (ManagerTourSelectionImportDraft::normalize($quote)===null) return self::error('biblio_source_changed');
            return ['ok'=>true,'offer'=>$quote['offer'],'raw'=>$quote['raw'],'photos'=>$photos,'ticket'=>self::seal($session,$actor,$conversation,$context,$quote,'ready',$ticket['expires']),'version'=>$context['version']];
        } catch (RuntimeException $e) {return self::error('biblio_source_unavailable');}
    }

    private static function sameContext(array $a,array $b): bool
    {
        return ($a['version']??null)===($b['version']??null) && is_string($a['context_hash']??null) && is_string($b['context_hash']??null) && hash_equals($a['context_hash'],$b['context_hash']);
    }

    private static function seal(string $session,int $actor,int $conversation,array $context,array $quote,string $stage,?int $expires=null): string
    {
        $body=json_encode(['schema'=>1,'actor'=>$actor,'conversation'=>$conversation,'version'=>$context['version'],'context_hash'=>$context['context_hash'],'expires'=>$expires??time()+600,'stage'=>$stage,'observation'=>$quote],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $encoded=rtrim(strtr(base64_encode($body),'+/','-_'),'=');
        return $encoded.'.'.hash_hmac('sha256',$encoded,'biblio-preview-v1|'.$session);
    }

    /** No source URL containing birth dates/names is retained in these tickets. */
    public static function open($token,string $session,int $actor,int $conversation,string $stage,bool $allowExpired=false): ?array
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/D',$session) || !is_string($token) || strlen($token)>32768 || !preg_match('/\A([A-Za-z0-9_-]+)\.([a-f0-9]{64})\z/D',$token,$m) || !hash_equals(hash_hmac('sha256',$m[1],'biblio-preview-v1|'.$session),$m[2])) return null;
        $json=base64_decode(strtr($m[1],'-_','+/'),true);
        try {$data=is_string($json)?json_decode($json,true,24,JSON_THROW_ON_ERROR):null;} catch (JsonException $e) {return null;}
        if (!is_array($data) || ($data['schema']??null)!==1 || ($data['actor']??null)!==$actor || ($data['conversation']??null)!==$conversation || ($data['stage']??null)!==$stage || !is_int($data['version']??null) || $data['version']<0 || !is_string($data['context_hash']??null) || !preg_match('/\A[a-f0-9]{64}\z/D',$data['context_hash']) || !is_int($data['expires']??null) || (!$allowExpired && $data['expires']<time()) || $data['expires']>time()+600 || !is_array($data['observation']??null)) return null;
        return $data;
    }

    /** Strict public GET only. Booking/auth endpoints never pass this boundary. */
    public static function searchUrl($value): ?string
    {
        if (!is_string($value) || strlen($value)>4096 || preg_match('/[\x00-\x20\x7f]/',$value)) return null;
        $p=parse_url($value);
        if (!$p || ($p['scheme']??'')!=='https' || ($p['host']??'')!=='www.bgoperator.ru' || ($p['path']??'')!=='/price.shtml' || isset($p['port']) || isset($p['user']) || isset($p['pass']) || isset($p['fragment'])) return null;
        $q=[];
        foreach (explode('&',$p['query']??'') as $part) {
            $bits=explode('=',$part,2);
            if (count($bits)!==2) return null;
            [$key,$v]=array_map('rawurldecode',$bits);
            if (isset($q[$key]) || !in_array($key,['action','tid','flt','flt2','id_price','data','d2','f7','p','bfr','f3','f8','F4','hotels','resorts'],true)) return null;
            if ($key==='action') {if ($v!=='price') return null;}
            elseif ($key==='tid') {if ($v!=='211') return null;}
            elseif (in_array($key,['data','d2'],true)) {if (self::date($v)===null) return null;}
            elseif ($key==='p') {if (!preg_match('/\A[01][0-9]{9,13}(?:\.[01][0-9]{9,13}){0,36}\z/D',$v)) return null;}
            elseif ($key==='id_price' && $v==='-1') {}
            elseif (!preg_match('/\A[0-9]{1,15}(?:,[0-9]{1,15})*\z/D',$v)) return null;
            $q[$key]=$v;
        }
        foreach (['action','tid','flt','flt2','id_price','data','f7','p'] as $key) if (!isset($q[$key])) return null;
        if (!preg_match('/\A[1-9][0-9]?\z/D',$q['f7']) || (int)$q['f7']>60) return null;
        // Request a full placement price; the manager still explicitly reviews
        // the displayed amount before any draft mutation.
        $q['bfr']='1';
        return self::ORIGIN.'/price.shtml?'.http_build_query($q,'','&',PHP_QUERY_RFC3986);
    }

    private static function read(string $url,?callable $fetch): string
    {
        $hotel=preg_match('~\Ahttps://www\.bgoperator\.ru/price\.shtml\?action=shw&code=[1-9][0-9]{0,14}&flt=[1-9][0-9]{0,14}&tid=26\z~D',$url)===1;
        if (!$hotel && self::searchUrl($url)!==$url) throw new RuntimeException('biblio_source_unavailable');
        if ($fetch!==null) $html=$fetch($url);
        else {
            if (!function_exists('curl_init')) throw new RuntimeException('biblio_source_unavailable');
            $ip=null;
            foreach ((array)@dns_get_record('www.bgoperator.ru',DNS_A) as $r) if (self::publicAddress($r['ip']??'')) {$ip=$r['ip'];break;}
            if ($ip===null) throw new RuntimeException('biblio_source_unavailable');
            $h=curl_init($url);$html='';$type='';
            if ($h===false) throw new RuntimeException('biblio_source_unavailable');
            try {
                $configured=curl_setopt_array($h,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROXY=>'',CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_RESOLVE=>['www.bgoperator.ru:443:'.$ip],CURLOPT_CONNECTTIMEOUT_MS=>2500,CURLOPT_TIMEOUT_MS=>12000,CURLOPT_ENCODING=>'',CURLOPT_USERAGENT=>'AnyTour-Selection-Biblio/1.0',CURLOPT_HTTPHEADER=>['Accept: text/html'],CURLOPT_MAXFILESIZE=>self::MAX_BYTES,
                    CURLOPT_WRITEFUNCTION=>static function($handle,string $bytes) use (&$html): int {if (strlen($html)+strlen($bytes)>self::MAX_BYTES) return 0;$html.=$bytes;return strlen($bytes);},
                    CURLOPT_HEADERFUNCTION=>static function($handle,string $line) use (&$type): int {if (stripos($line,'Content-Type:')===0) $type=trim(substr($line,13));return strlen($line);}]);
                if (!$configured || curl_exec($h)===false || curl_errno($h)!==0 || curl_getinfo($h,CURLINFO_HTTP_CODE)!==200 || stripos($type,'text/html')!==0) throw new RuntimeException('biblio_source_unavailable');
            } finally {curl_close($h);}
        }
        if (!is_string($html) || $html==='' || strlen($html)>self::MAX_BYTES || preg_match('//u',$html)!==1) throw new RuntimeException('biblio_source_unavailable');
        return $html;
    }

    public static function publicAddress(string $ip): bool
    {
        if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) return false;
        $n=ip2long($ip);
        foreach ([[0x64400000,0xffc00000],[0xc0000000,0xffffff00],[0xc0000200,0xffffff00],[0xc6120000,0xfffe0000],[0xc6336400,0xffffff00],[0xcb007100,0xffffff00],[0xe0000000,0xf0000000]] as [$prefix,$mask]) if (($n&$mask)===$prefix) return false;
        return true;
    }

    private static function document(string $html): DOMXPath
    {
        if ($html==='' || strlen($html)>self::MAX_BYTES || preg_match('//u',$html)!==1) throw new RuntimeException('biblio_source_changed');
        $old=libxml_use_internal_errors(true);
        try {$doc=new DOMDocument();$doc->resolveExternals=false;$doc->substituteEntities=false;$doc->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);return new DOMXPath($doc);}
        finally {libxml_clear_errors();libxml_use_internal_errors($old);}
    }

    private static function cls(string $name): string {return 'contains(concat(" ",normalize-space(@class)," ")," '.$name.' ")';}
    private static function nodes(DOMXPath $x,string $path,?DOMNode $node=null): array {return iterator_to_array($x->query($path,$node));}
    private static function one(DOMXPath $x,string $path,DOMNode $node): DOMElement
    {
        $nodes=self::nodes($x,$path,$node);
        if (count($nodes)!==1 || !($nodes[0] instanceof DOMElement)) throw new RuntimeException('biblio_source_changed');
        return $nodes[0];
    }
    private static function text(string $value): string {return trim(preg_replace('/\s+/u',' ',str_replace(["\xc2\xa0","\xe2\x80\x8b"],[' ',''],$value))??'');}
    private static function date(string $text): ?string
    {
        if (!preg_match('/\A([0-9]{2})\.([0-9]{2})\.([0-9]{4})\z/D',$text,$m) || !checkdate((int)$m[2],(int)$m[1],(int)$m[3])) return null;
        return $m[3].'-'.$m[2].'-'.$m[1];
    }

    /** Official public page shapes only; no JavaScript execution or API login. */
    public static function parse(string $html,string $url,?string $observed=null): array
    {
        if (self::searchUrl($url)===null) throw new RuntimeException('biblio_source_changed');
        parse_str((string)parse_url($url,PHP_URL_QUERY),$query);
        $x=self::document($html);$meta=null;
        foreach (self::nodes($x,'//script[not(@src)]') as $script) {
            $text=$script->textContent;$end=strpos($text,'mods.initPf(');$start=strpos($text,'var data');
            if ($end===false || $start===false || $start>$end) continue;
            $from=strpos($text,'{',$start);$until=strrpos(substr($text,0,$end),'}');
            if ($from===false || $until===false || $until<$from) continue;
            try {$meta=json_decode(substr($text,$from,$until-$from+1),true,16,JSON_THROW_ON_ERROR);} catch (JsonException $e) {throw new RuntimeException('biblio_source_changed');}
        }
        if (!is_array($meta) || (string)($meta['info']['flt']??'')!==$query['flt'] || (string)($meta['info']['flt2']??'')!==$query['flt2'] || (string)($meta['id']??'')!==$query['id_price']) throw new RuntimeException('biblio_source_changed');
        $currency=match($meta['info']['currency']??'') {'RUR','RUB'=>'RUB','EUR'=>'EUR','USD'=>'USD',default=>null};
        if ($currency===null || self::date($meta['start']['df']??'')!==self::date($query['data'])) throw new RuntimeException('biblio_source_changed');
        $country=self::text((string)($meta['info']['fltName']??''));$departure=self::text((string)($meta['info']['flt2Name']??''));
        if ($country==='' || $departure==='') throw new RuntimeException('biblio_source_changed');
        $quotes=[];$observed??=gmdate('Y-m-d\TH:i:s\Z');
        foreach (self::nodes($x,'//table['.self::cls('b-pr_t').']') as $table) {
          $header=null;
          foreach (self::nodes($x,'./tr|./tbody/tr|./thead/tr|./tfoot/tr',$table) as $row) {
            if (str_contains(' '.$row->getAttribute('class').' ',' b-pr_t_hl ')) {$header=$row;continue;}
            if (!$header || !self::nodes($x,'.//td['.self::cls('c_pe').']',$row)) continue;
            try {$quote=self::row($x,$header,$row,$query,$currency,$country,$departure,$observed);}
            catch (RuntimeException $e) {continue;}
            if ($quote!==null) $quotes[]=$quote;
            if (count($quotes)>=500) break 2;
          }
        }
        if (!$quotes) throw new RuntimeException('biblio_no_quotes');
        return $quotes;
    }

    private static function row(DOMXPath $x,DOMElement $header,DOMElement $row,array $query,string $currency,string $country,string $departure,string $observed): ?array
    {
        $name=self::one($x,'.//div['.self::cls('name').' and @data-hid]',$header);$hotel=$name->getAttribute('data-hid');
        if (!preg_match('/\A[1-9][0-9]{0,14}\z/D',$hotel)) return null;
        $anchor=self::one($x,'.//a[@href]',$name);$rawHotel=self::text($anchor->textContent);$stars=null;$hotelName=$rawHotel;
        if (preg_match('/\A(.+?)\s+([0-5])\*\z/uD',$rawHotel,$hm)) {$hotelName=$hm[1];$stars=(int)$hm[2];}
        $resort=self::text(self::one($x,'./span',$name)->textContent);
        $groupHeader=self::one($x,'.//th['.self::cls('h_gp').' and @data-group]',$header);$group=$groupHeader->getAttribute('data-group');
        $date=self::date(self::text(self::one($x,'.//th['.self::cls('c_de').']//b',$header)->textContent));
        if ($date===null || $date<self::date($query['data']) || $date>self::date($query['d2']??$query['data'])) return null;
        $nightCell=self::one($x,'./td['.self::cls('c_dn').']',$row);$nights=self::text(self::one($x,'.//b',$nightCell)->textContent);
        if (!preg_match('/\A[1-9][0-9]?\z/D',$nights) || (int)$nights>60 || (int)$nights!==(int)$query['f7']) return null;
        if (!preg_match('/\Aдо ([0-9.]{10})(?:\s|\z)/u',$nightCell->getAttribute('title'),$em) || !($end=self::date($em[1]))) return null;
        $roomCell=self::one($x,'./td['.self::cls('c_ns').']',$row);$parts=[];$part='';
        foreach ($roomCell->childNodes as $child) {if ($child instanceof DOMElement && strtolower($child->tagName)==='br') {$parts[]=self::text($part);$part='';} else $part.=$child->textContent;}$parts[]=self::text($part);
        if (count($parts)!==2 || $parts[0]==='' || $parts[1]==='') return null;
        [$room,$meal]=$parts;
        $components=[];$services=[];$flights=[];$stayFrom=null;$stayUntil=null;
        foreach (self::nodes($x,'./td['.self::cls('c_cn').']//li[@title]',$row) as $component) {
            $label=self::text(strip_tags(preg_replace('~<br\s*/?>~i',' ',html_entity_decode($component->getAttribute('title'),ENT_QUOTES|ENT_HTML5,'UTF-8'))));$class=' '.$component->getAttribute('class').' ';
            $components[]=['class'=>$component->getAttribute('class'),'label'=>$label,'identity'=>$component->getAttribute('urr')];
            if (str_contains($class,' i_an ')) {if (!preg_match('/с ([0-9.]{10}) по ([0-9.]{10}) \(([0-9]+) /u',$label,$sm) || (string)(int)$sm[3]!==$nights) return null;$stayFrom=self::date($sm[1]);$stayUntil=self::date($sm[2]);}
            elseif (str_contains($class,' i_ft ')) $flights[]=$label;
            else if ($label!=='') $services[]=$label;
        }
        if (!$stayFrom || !$stayUntil) return null;
        $priceCell=self::one($x,'./td['.self::cls('c_pe').']//div['.self::cls('pe').']',$row);
        $priceNode=self::one($x,'.//b['.self::cls('r').']',$priceCell);$price=self::text($priceNode->textContent);$price=preg_replace('/\s/u','',$price);
        if (!is_string($price) || !preg_match('/\A[1-9][0-9]{0,8}(?:[.,][0-9]{1,2})?\z/D',$price)) return null;$price=str_replace(',','.',$price);
        // A foreign-currency price list can display a RUB equivalent. Read the
        // actual price suffix, never label that displayed amount with info.currency.
        $suffix='';for($node=$priceNode->nextSibling;$node && !($node instanceof DOMElement && strtolower($node->tagName)==='a');$node=$node->nextSibling) $suffix.=$node->textContent;
        $currency=match(self::text($suffix)) {'р.','руб.','RUR','RUB','₽'=>'RUB','EUR','€'=>'EUR','USD','$'=>'USD',default=>null};
        if ($currency===null) return null;
        $buy=self::one($x,'.//a[@href]',$priceCell);$bp=parse_url(html_entity_decode($buy->getAttribute('href'),ENT_QUOTES|ENT_HTML5,'UTF-8'));
        if (!$bp || ($bp['scheme']??'')!=='https' || ($bp['host']??'')!=='www.bgoperator.ru' || ($bp['path']??'')!=='/zaya') return null;
        parse_str($bp['query']??'',$q);
        if (($q['dt']??null)!==substr($date,8,2).'.'.substr($date,5,2).'.'.substr($date,0,4) || ($q['kol']??null)!==$nights || ($q['cntr']??null)!==$query['flt'] || ($q['templ']??null)!==($query['id_price']==='-1'?'0':$query['id_price']) || ($q['p']??null)!==$query['p'] || ($q['bfr']??null)!=='1') return null;
        $party=self::party($query['p'],$group,$date);
        if ($party===null) return null;
        $partyText=self::text($groupHeader->textContent);$ages=self::ages($query['p'],$group,$date);
        if (!preg_match('/\A([1-9][0-9]?) взросл/u',$partyText,$adultMatch) || (int)$adultMatch[1]!==$party['adults'] || !preg_match('/\(([0-9]+(?:\/[0-9]+)*)\)\z/D',$partyText,$ageMatch)) return null;
        $shownAges=array_map('intval',explode('/',$ageMatch[1]));sort($ages);sort($shownAges);if ($ages!==$shownAges) return null;
        $carrier=self::text(self::one($x,'./td['.self::cls('c_ar').']',$row)->textContent);
        $transport=$carrier!==''?'Перевозчик: '.$carrier.'. ':'';
        $transport.=count($flights)?'В пакете указано транспортных участков: '.count($flights).'. Номера рейсов, время и багаж требуют проверки.':'Отметка о перелёте в строке отсутствует. Требует проверки.';
        $priceNote=self::text($buy->getAttribute('title'));if ($priceNote!=='') $priceNote='Валюта прайс-листа: '.$priceNote.'. Показанная сумма сохранена без самостоятельного пересчёта.';
        $keyParts=array_intersect_key($q,array_flip(['prx','templ','dt','kol','otn','otv','sr','c','ins']));$keyParts['party']=$party;ksort($keyParts);
        $rawParty=$party['adults'].'AD'.($party['child_ages']?' + '.count($party['child_ages']).'CH ('.implode(', ',$party['child_ages']).')':'');
        $quote=['adapter'=>'biblio-public-v1','url'=>ManagerTourSelectionBiblioImportDraft::hotelUrl($hotel,$query['flt']),'observed_at'=>$observed,'quote_id'=>hash('sha256',json_encode($keyParts,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)),
            'keys'=>['hotel'=>$hotel,'country'=>$query['flt'],'departure'=>$query['flt2'],'route'=>$query['id_price'],'room'=>(string)($q['otn']??''),'placement'=>(string)($q['otv']??''),'group'=>$group,'components_hash'=>hash('sha256',json_encode([$components,$transport,$priceNote],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))],
            'offer'=>['hotel'=>$hotelName,'stars'=>$stars,'destination'=>$country.', '.$resort,'departure'=>$departure,'date_from'=>$date,'nights'=>(int)$nights,'adults'=>$party['adults'],'child_ages'=>$party['child_ages'],'room'=>$room,'meal'=>$meal,'amount'=>$price,'currency'=>$currency],
            'raw'=>['hotel'=>$rawHotel,'date'=>$date,'end_date'=>$end,'stay_from'=>$stayFrom,'stay_until'=>$stayUntil,'nights'=>$nights,'room'=>$room,'meal'=>$meal,'price'=>$price.' '.$currency,'party'=>$rawParty,'transport'=>$transport,'services'=>implode("\n",$services),'components'=>implode("\n",array_column($components,'label')),'price_basis'=>'party_confirmed','price_note'=>$priceNote]];
        return ManagerTourSelectionBiblioImportDraft::normalize($quote)===null?null:$quote;
    }

    /** Source encoding is birth-flag + ddMMyyyy + placement-group digits. */
    public static function party(string $code,string $group,string $date): ?array
    {
        $people=self::ages($code,$group,$date);if ($people===null) return null;
        $adults=0;$ages=[];
        foreach ($people as $age) {if ($age>=18 && $age<=110) $adults++;elseif ($age<18) $ages[]=$age;else return null;}
        if ($adults<1 || $adults>20 || count($ages)>10) return null;
        return ['adults'=>$adults,'child_ages'=>$ages];
    }

    private static function ages(string $code,string $group,string $date): ?array
    {
        if (!preg_match('/\A[0-4]\z/D',$group)) return null;
        $ages=[];$travel=new DateTimeImmutable($date,new DateTimeZone('UTC'));
        foreach (explode('.',$code) as $person) {
            if (!preg_match('/\A[01]([0-9]{2})([0-9]{2})([0-9]{4})([0-4]{1,5})\z/D',$person,$m) || !checkdate((int)$m[2],(int)$m[1],(int)$m[3]) || count(array_unique(str_split($m[4])))!==strlen($m[4])) return null;
            if (!str_contains($m[4],$group)) continue;
            $birth=new DateTimeImmutable($m[3].'-'.$m[2].'-'.$m[1],new DateTimeZone('UTC'));if ($birth>$travel) return null;$age=(int)$birth->diff($travel)->y;
            if ($age>110) return null;$ages[]=$age;
        }
        return $ages;
    }

    public static function gallery(string $html,string $hotel,string $name): array
    {
        $x=self::document($html);$headings=self::nodes($x,'//h1['.self::cls('title').']');
        if (count($headings)!==1 || preg_replace('/\s+[0-5]\*\z/u','',self::text($headings[0]->textContent))!==$name) return [];
        $bound=false;
        foreach (self::nodes($x,'//nav['.self::cls('b-htl_nav').']//a[@href]') as $a) {parse_str((string)parse_url(html_entity_decode($a->getAttribute('href'),ENT_QUOTES|ENT_HTML5,'UTF-8'),PHP_URL_QUERY),$q);if (($q['action']??'')==='shw' && ($q['code']??'')===$hotel) $bound=true;}
        if (!$bound) return [];
        $photos=[];
        foreach (self::nodes($x,'//div['.self::cls('b-htl_gry').']//a['.self::cls('link').' and @href]') as $a) {
            $p=$a->getAttribute('href');$url=str_starts_with($p,'/')?self::ORIGIN.$p:$p;
            if (ManagerTourSelectionBiblioImportDraft::photoUrl($url) && !in_array($url,$photos,true)) $photos[]=$url;
            if (count($photos)===3) break;
        }
        return $photos;
    }

    private static function error(string $code): array {return ['ok'=>false,'error'=>$code];}
}
