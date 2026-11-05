<?php
declare(strict_types=1);
require_once __DIR__.'/MaxTrackerLookupProtocol.php';

/** Bind an internal history cursor to the current owner session; no stored state or IO. */
final class MaxMemberHistoryViewCursor
{
    public static function shape(string $value):bool
    {
        return$value==='0'||(strlen($value)<=1024&&preg_match('/\Ahmv1\.([A-Za-z0-9_-]{1,700})\.([a-f0-9]{64})\z/D',$value)===1);
    }
    public static function wrap(string $cursor,string $key,int $now):string
    {
        self::key($key);if(MaxTrackerLookupProtocol::historyCursor($cursor,$now,'98615635')===null)
            throw new InvalidArgumentException('member_view_cursor');
        $encoded=rtrim(strtr(base64_encode($cursor),'+/','-_'),'=');
        return'hmv1.'.$encoded.'.'.hash_hmac('sha256',"max-history-owner-view-v1\n".$encoded,$key);
    }
    public static function unwrap(string $value,string $key,int $now):string
    {
        self::key($key);if($value==='0')return'0';
        if(!self::shape($value)||preg_match('/\Ahmv1\.([A-Za-z0-9_-]+)\.([a-f0-9]{64})\z/D',$value,$m)!==1
            ||!hash_equals(hash_hmac('sha256',"max-history-owner-view-v1\n".$m[1],$key),$m[2]))
            throw new InvalidArgumentException('member_view_cursor');
        $decoded=base64_decode(strtr($m[1],'-_','+/'),true);
        if(!is_string($decoded)||strlen($decoded)>512||rtrim(strtr(base64_encode($decoded),'+/','-_'),'=')!==$m[1]
            ||MaxTrackerLookupProtocol::historyCursor($decoded,$now,'98615635')===null)
            throw new InvalidArgumentException('member_view_cursor');
        return$decoded;
    }
    private static function key(string $key):void
    {
        if(preg_match('/\A[a-f0-9]{64}\z/D',$key)!==1)throw new InvalidArgumentException('member_view_cursor_key');
    }
}
