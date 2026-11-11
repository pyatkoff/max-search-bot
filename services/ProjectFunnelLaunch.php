<?php
declare(strict_types=1);

/** Shared MAX2 launch contract. No request can select a credential or destination URL. */
final class ProjectFunnelLaunch
{
    public static function ref($value): string
    {
        if(!is_string($value)||!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}\z/D',$value))throw new InvalidArgumentException('invalid_publication_reference');
        return $value;
    }
    public static function parse(string $raw): array
    {
        if(strlen($raw)>512||preg_match('/[\x00-\x20\x7f]/',$raw))throw new InvalidArgumentException('invalid_start');
        if($raw==='')return ['kind'=>'publication','post_id'=>'','offer_id'=>'','yclid'=>'','region_id'=>'','campaign_id'=>''];
        if(preg_match('/\Apost1_(.+)\z/D',$raw,$m))return ['kind'=>'publication','post_id'=>self::ref($m[1]),'offer_id'=>'','yclid'=>'','region_id'=>'','campaign_id'=>''];
        if(!preg_match('/\A([1-9][0-9]{0,39})_region_([1-9][0-9]{0,19})_campaign_([0-9]{1,20})_ctx1_([A-Za-z0-9_-]{1,340})\z/D',$raw,$m))throw new InvalidArgumentException('invalid_start');
        $plain=base64_decode(strtr($m[4],'-_','+/'),true);
        try{$context=$plain===false?null:json_decode($plain,true,4,JSON_THROW_ON_ERROR);}catch(Throwable $e){throw new InvalidArgumentException('invalid_context');}
        if(!is_array($context)||array_keys($context)!==['post_id','offer_id'])throw new InvalidArgumentException('invalid_context');
        $post=self::ref($context['post_id']);$offer=self::ref($context['offer_id']);
        if(self::context($post,$offer)!==$m[4])throw new InvalidArgumentException('invalid_context');
        return ['kind'=>'advertising','post_id'=>$post,'offer_id'=>$offer,'yclid'=>$m[1],'region_id'=>$m[2],'campaign_id'=>ltrim($m[3],'0')?:'0'];
    }
    public static function context(string $post,string $offer): string
    {
        return rtrim(strtr(base64_encode(json_encode(['post_id'=>self::ref($post),'offer_id'=>self::ref($offer)],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),'+/','-_'),'=');
    }
    /** Unexpanded Direct macros intentionally remain literal in the exported feed. */
    public static function feedUrl(string $bot,string $post,string $offer): string
    {
        if(!preg_match('/\A[A-Za-z0-9_]{3,100}_bot\z/D',$bot))throw new InvalidArgumentException('invalid_bot');
        $payload='{yclid}_region_{region_id}_campaign_{campaign_id}_ctx1_'.self::context($post,$offer);
        if(strlen($payload)>512)throw new InvalidArgumentException('invalid_start');
        return 'https://max.ru/'.$bot.'?startapp='.$payload;
    }
    /** Decode exactly once; reject duplicate keys before checking the signature. */
    public static function pairs(string $raw): array
    {
        if($raw===''||strlen($raw)>24000)throw new InvalidArgumentException('invalid_init_data');$out=[];
        foreach(explode('&',$raw)as$part){
            if(!str_contains($part,'=')||preg_match('/%(?![0-9a-fA-F]{2})/',$part))throw new InvalidArgumentException('invalid_init_data');
            [$key,$value]=explode('=',$part,2);$key=urldecode($key);$value=urldecode($value);
            if(!preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D',$key)||array_key_exists($key,$out)||strpbrk($value,"\r\n\0")!==false)throw new InvalidArgumentException('invalid_init_data');
            $out[$key]=$value;
        }
        return $out;
    }
    /** The official MAX two-step HMAC; verified identity does not prove ownership of a paid click. */
    public static function verify(string $raw,string $token,int $now): array
    {
        if($token==='')throw new RuntimeException('connection_unavailable');$pairs=self::pairs($raw);$hash=$pairs['hash']??'';
        if(!preg_match('/\A[0-9a-f]{64}\z/Di',$hash))throw new InvalidArgumentException('invalid_signature');unset($pairs['hash']);ksort($pairs,SORT_STRING);
        $lines=[];foreach($pairs as$key=>$value)$lines[]=$key.'='.$value;
        $expected=hash_hmac('sha256',implode("\n",$lines),hash_hmac('sha256',$token,'WebAppData',true));
        if(!hash_equals($expected,strtolower($hash)))throw new InvalidArgumentException('invalid_signature');
        $date=$pairs['auth_date']??'';
        if(!preg_match('/\A[0-9]{1,12}\z/D',$date)||(int)$date<946684800||$now-(int)$date>3600||(int)$date>$now+60)throw new InvalidArgumentException('expired_launch');
        try{$user=json_decode($pairs['user']??'',true,8,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}catch(Throwable $e){throw new InvalidArgumentException('invalid_user');}
        $id=$user['id']??null;
        if((!is_int($id)&&!is_string($id))||!preg_match('/\A[1-9][0-9]{0,18}\z/D',(string)$id)||!empty($user['is_bot']))throw new InvalidArgumentException('invalid_user');
        if(isset($pairs['start_param'],$pairs['startapp'])&&$pairs['start_param']!==$pairs['startapp'])throw new InvalidArgumentException('conflicting_start');
        $start=$pairs['start_param']??$pairs['startapp']??'';
        return ['external_user_id'=>(string)$id,'auth_date'=>(int)$date,'start_param'=>$start,'payload'=>self::parse($start),
            'visit_id'=>'max1_'.hash_hmac('sha256',implode("\n",$lines),$token)];
    }
}
