<?php
declare(strict_types=1);
require_once __DIR__.'/ProjectConfig.php';

/** Dedicated public verification keys only; never borrows customer/bot credentials. */
final class Search3TourHandoffTrust
{
    private static ?array $keys=null;
    public static function keys(): array
    {
        if (self::$keys!==null) return self::$keys;
        $raw=@file_get_contents(dirname(__DIR__).'/resources/search3-handoff-producers.json',false,null,0,16385);
        $data=is_string($raw)&&strlen($raw)<=16384?json_decode($raw,true):null;
        if (!is_array($data)||($data['schema']??null)!==1||!is_array($data['keys']??null)||count($data['keys'])>8) return self::$keys=[];
        return self::$keys=$data['keys'];
    }
    public static function producer(string $kid,?int $now=null): ?array
    {
        $now??=time();$key=self::keys()[$kid]??null;
        if (!preg_match('/\A[a-z0-9_-]{1,48}\z/D',$kid)||!is_array($key)||($key['producer']??null)!=='search3'||($key['project']??null)!==ProjectConfig::projectId()
            ||!is_int($key['not_before']??null)||!is_int($key['not_after']??null)||$now<$key['not_before']||$now>=$key['not_after']) return null;
        $public=is_string($key['public_key']??null)?base64_decode($key['public_key'],true):false;
        return is_string($public)&&strlen($public)===32?['id'=>$kid,'public_key'=>$public]:null;
    }
    public static function verify(string $raw,array $server,?int $now=null): array
    {
        $now??=time();$kid=(string)($server['HTTP_X_ANYTOUR_SEARCH3_KEY']??'');$stamp=(string)($server['HTTP_X_ANYTOUR_SEARCH3_TIMESTAMP']??'');$sig=(string)($server['HTTP_X_ANYTOUR_SEARCH3_SIGNATURE']??'');$key=self::producer($kid,$now);
        if (!$key||!preg_match('/\A[1-9][0-9]{9}\z/D',$stamp)||abs($now-(int)$stamp)>300||!preg_match('/\A[A-Za-z0-9_-]{86}\z/D',$sig)||!function_exists('sodium_crypto_sign_verify_detached')) return ['ok'=>false,'error'=>'unauthorized'];
        $signature=base64_decode(strtr($sig,'-_','+/').'==',true);
        $message="anytour-search3-handoff-v1\nPOST\n/search3/tour-handoff.php\n".$stamp."\n".hash('sha256',$raw);
        if (!is_string($signature)||strlen($signature)!==64||rtrim(strtr(base64_encode($signature),'+/','-_'),'=')!==$sig||!sodium_crypto_sign_verify_detached($signature,$message,$key['public_key'])) return ['ok'=>false,'error'=>'unauthorized'];
        return ['ok'=>true,'producer'=>$key];
    }
}
