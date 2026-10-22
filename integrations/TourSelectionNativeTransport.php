<?php
declare(strict_types=1);
require_once __DIR__.'/../services/MaxTlsConfig.php';

/** Three-photo cards only. Creation is called after the durable application fence. */
final class TourSelectionNativeTransport
{
    private $exchange;
    public function __construct(?callable $exchange=null) {$this->exchange=$exchange??[self::class,'exchange'];}

    public function prepare(array $binding,array $images): array
    {
        $channel=$binding['channel'];$request=$this->exchange;
        $me=$request($channel,$channel==='max'?'GET':'POST',$channel==='max'?'/me':'getMe',[],[],[]);
        $bot=self::decimal($me['data']['user_id']??$me['data']['id']??null);
        if ($bot===null || ($me['data']['is_bot']??false)!==true) throw new RuntimeException('recipient_unverified');
        $user=$binding['external_user_id'];
        if (self::decimal($user)===null) throw new RuntimeException('recipient_unverified');
        if ($channel==='telegram') {
            $chat=$request($channel,'POST','getChat',[],['chat_id'=>$user],[]);
            if ($binding['external_chat_id']!==$user || ($chat['data']['type']??'')!=='private' || self::decimal($chat['data']['id']??null)!==$user) throw new RuntimeException('recipient_unverified');
            return ['bot'=>$bot,'user'=>$user,'chat'=>$user,'tokens'=>[]];
        }
        $mid=$binding['proof_mid']??'';
        if (!self::mid($mid)) throw new RuntimeException('recipient_unverified');
        $response=$request('max','GET','/messages/'.rawurlencode($mid),[],[],[]);$message=$response['data']['message']??$response['data'];
        if (self::decimal($message['sender']['user_id']??null)!==$user || self::decimal($message['recipient']['user_id']??null)!==$bot || ($message['recipient']['chat_type']??'')!=='dialog' || self::messageId('max',$message)!==$mid) throw new RuntimeException('recipient_unverified');
        $chat=(string)($message['recipient']['chat_id']??'');
        if (!preg_match('/\A-?[1-9][0-9]{0,18}\z/D',$chat)) throw new RuntimeException('recipient_unverified');
        $tokens=[];
        foreach ($images as $image) {
            $reservation=$request('max','POST','/uploads',['type'=>'image'],[],[]);$url=$reservation['data']['url']??$reservation['data']['upload_url']??'';
            if (!self::uploadUrl($url)) throw new RuntimeException('photo_upload_unavailable');
            $uploaded=$request('upload','POST',$url,[],[],[$image]);$photos=$uploaded['data']['photos']??null;
            if (!is_array($photos) || count($photos)!==1) throw new RuntimeException('photo_upload_unavailable');
            $photo=reset($photos);$token=$photo['token']??null;
            if (!is_string($token) || $token==='' || strlen($token)>4096) throw new RuntimeException('photo_upload_unavailable');
            $tokens[]=$token;
        }
        if (count($tokens)!==3) throw new RuntimeException('photo_upload_unavailable');
        return ['bot'=>$bot,'user'=>$user,'chat'=>$chat,'tokens'=>$tokens];
    }

    public function create(string $channel,array $card,array $identity,array $images): array
    {
        $request=$this->exchange;$body=$card[$channel];
        if ($channel==='max') {
            $photos=array_map(static fn(string $token):array=>['type'=>'image','payload'=>['token'=>$token]],$identity['tokens']);
            $body['attachments']=array_merge($photos,$body['attachments']);
            $response=$request('max','POST','/messages',['user_id'=>$identity['user']],$body,[]);
        } else $response=$request('telegram','POST','sendRichMessage',[],['chat_id'=>$identity['user']]+$body,$images);
        $message=$response['data']['message']??$response['data']??null;
        return ['mid'=>is_array($message)?self::messageId($channel,$message):null,'message'=>is_array($message)?$message:null];
    }

    public function verify(string $channel,array $card,array $identity,array $receipt): bool
    {
        $message=$receipt['message']??null;$mid=$receipt['mid']??null;
        if (!self::mid($mid)) return false;
        if ($channel==='max') {
            $request=$this->exchange;$response=$request('max','GET','/messages/'.rawurlencode($mid),[],[],[]);
            $message=$response['data']['message']??$response['data']??null;
        }
        return self::matches($channel,$card,$identity,$mid,$message);
    }

    public static function matches(string $channel,array $card,array $identity,string $mid,$message): bool
    {
        if (!is_array($message) || self::messageId($channel,$message)!==$mid) return false;
        if ($channel==='telegram') {
            if (self::decimal($message['chat']['id']??null)!==$identity['user'] || ($message['chat']['type']??'')!=='private' || self::decimal($message['from']['id']??null)!==$identity['bot'] || ($message['from']['is_bot']??false)!==true) return false;
            $blocks=$message['rich_message']['blocks']??null;$wanted=$card['telegram']['rich_message']['blocks'];
            if (!is_array($blocks) || count($blocks)!==5 || ($blocks[0]['type']??'')!=='slideshow' || count($blocks[0]['blocks']??[])!==3) return false;
            foreach ($blocks[0]['blocks'] as $block) {
                if (($block['type']??'')!=='photo' || !is_array($block['photo']??null) || !$block['photo']) return false;
                foreach ($block['photo'] as $p) if (!is_array($p) || !is_string($p['file_id']??null) || $p['file_id']==='' || (int)($p['width']??0)<1 || (int)($p['height']??0)<1) return false;
            }
            foreach ([1,2,3,4] as $i) if (($blocks[$i]['type']??'')!==$wanted[$i]['type'] || self::text($blocks[$i]['text']??null)!==self::text($wanted[$i]['text'])) return false;
            return ($message['reply_markup']??null)==$card['telegram']['reply_markup'];
        }
        if (self::decimal($message['sender']['user_id']??null)!==$identity['bot'] || self::decimal($message['recipient']['user_id']??null)!==$identity['user'] || (string)($message['recipient']['chat_id']??'')!==$identity['chat'] || ($message['recipient']['chat_type']??'')!=='dialog' || ($message['body']['text']??null)!==$card['plain_text']) return false;
        $attachments=$message['body']['attachments']??null;$markup=$message['body']['markup']??null;
        if (!is_array($attachments) || count($attachments)!==4 || !is_array($markup) || count($markup)!==2) return false;
        foreach ([0,1,2] as $i) if (($attachments[$i]['type']??'')!=='image' || empty($attachments[$i]['payload'])) return false;
        foreach ($card['max_markup'] as $span) if (!in_array($span,$markup,true)) return false;
        return $attachments[3]==$card['max']['attachments'][0];
    }

    public static function messageId(string $channel,array $message): ?string
    {
        $id=$channel==='telegram'?($message['message_id']??null):($message['body']['mid']??$message['mid']??null);
        if ($channel==='telegram') return self::decimal($id);
        return self::mid($id)?$id:null;
    }
    private static function decimal($id): ?string {return (is_int($id)||is_string($id))&&preg_match('/\A[1-9][0-9]{0,18}\z/D',(string)$id)?(string)$id:null;}
    private static function mid($id): bool {return is_string($id) && preg_match('/\A[A-Za-z0-9_.-]{1,191}\z/D',$id)===1;}
    private static function text($value,bool $bold=false,int $depth=0): ?array
    {
        if ($depth>16) return null;if (is_string($value)) return $value===''?[]:[[$bold,$value]];if (!is_array($value)) return null;
        if (!array_is_list($value)) return ($value['type']??'')==='bold'&&count($value)===2?self::text($value['text']??null,true,$depth+1):null;
        $out=[];foreach ($value as $part) {$segments=self::text($part,$bold,$depth+1);if ($segments===null) return null;foreach ($segments as $s) {$last=count($out)-1;if ($last>=0&&$out[$last][0]===$s[0]) $out[$last][1].=$s[1];else $out[]=$s;}}return $out;
    }
    private static function uploadUrl($url): bool
    {
        if (!is_string($url) || strlen($url)>8192 || preg_match('/[\x00-\x20\x7f\\\\]/',$url)) return false;$p=parse_url($url);
        return is_array($p) && ($p['scheme']??'')==='https' && strtolower($p['host']??'')==='iu.oneme.ru' && ($p['path']??'')==='/uploadImage' && !isset($p['user']) && !isset($p['pass']) && !isset($p['fragment']) && (!isset($p['port'])||$p['port']===443) && !empty($p['query']);
    }

    /** Fixed provider origins; bot authorization is never forwarded to upload hosts. */
    public static function exchange(string $channel,string $method,string $path,array $query,array $body,array $images): array
    {
        if (!extension_loaded('curl')) return ['data'=>null];
        $token=$channel==='telegram'?(defined('TELEGRAM_BOT_TOKEN')?(string)TELEGRAM_BOT_TOKEN:''):(defined('MAX_SEARCH_TOKEN')?(string)MAX_SEARCH_TOKEN:'');
        if ($channel==='upload') {if (!self::uploadUrl($path)||count($images)!==1) return ['data'=>null];$url=$path;$headers=[];$fields=['data'=>new CURLFile($images[0]['path'],$images[0]['mime'],'offer-photo')];}
        elseif ($channel==='telegram') {
            if ($token==='' || !in_array($path,['getMe','getChat','sendRichMessage'],true)||$method!=='POST'||$query!==[]) return ['data'=>null];
            $url='https://api.telegram.org/bot'.$token.'/'.$path;$headers=['Content-Type: application/json'];$fields=json_encode($body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            if ($images!==[]) {$headers=[];$fields=['chat_id'=>$body['chat_id'],'rich_message'=>json_encode($body['rich_message'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),'reply_markup'=>json_encode($body['reply_markup'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)];foreach ($images as $i=>$image) $fields['photo'.$i]=new CURLFile($image['path'],$image['mime'],'photo'.$i);}
        } elseif ($channel==='max') {
            if ($token==='' || !in_array($method,['GET','POST'],true) || !preg_match('/\A\/(?:me|uploads|messages(?:\/[A-Za-z0-9_.-]{1,191})?)\z/D',$path)) return ['data'=>null];
            $url='https://platform-api2.max.ru'.$path.($query?'?'.http_build_query($query):'');$headers=['Authorization: '.$token,'Content-Type: application/json'];$fields=$body===[]?null:json_encode($body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        } else return ['data'=>null];
        $raw='';$ch=curl_init($url);$options=($channel==='telegram'?[CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]:MaxTlsConfig::strictCurlOptions())+[
            CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>$channel==='upload'?12:($path==='sendRichMessage'||($method==='POST'&&$path==='/messages')?20:6),CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk) use (&$raw):int {if (strlen($raw)+strlen($chunk)>262144) return 0;$raw.=$chunk;return strlen($chunk);},
        ];if ($fields!==null) $options[CURLOPT_POSTFIELDS]=$fields;
        curl_setopt_array($ch,$options);$ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_errno($ch);curl_close($ch);
        $data=$ok!==false&&!$error&&$status>=200&&$status<300?json_decode($raw,true,64,JSON_BIGINT_AS_STRING):null;
        if ($channel==='telegram') $data=is_array($data)&&($data['ok']??false)===true?($data['result']??null):null;
        return ['data'=>is_array($data)?$data:null];
    }
}
