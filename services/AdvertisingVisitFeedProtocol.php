<?php
declare(strict_types=1);
require_once __DIR__.'/AdvertisingVisitContract.php';

/** A signed bounded read request; the response is sealed to a separate receiver key. */
final class AdvertisingVisitFeedProtocol
{
    public const MAX_REQUEST = 2048;
    public const MAX_WIRE = 400000;
    private const DOMAIN = "ANYTOUR-ADVERTISING-READ/1\nPOST\n/tgmini/tgmini_identity_feed.php\n";

    public static function request(array $scope,array $checkpoint,string $signSecret,int $now): array
    {
        $body=json_encode(['schema_version'=>1,'scope'=>AdvertisingVisitContract::scope($scope),
            'checkpoint'=>$checkpoint,'issued_at_unix'=>$now,'nonce'=>bin2hex(random_bytes(16))],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        if (strlen($body)>self::MAX_REQUEST) self::fail('request_bound');
        return ['body'=>$body,'signature'=>bin2hex(sodium_crypto_sign_detached(self::DOMAIN.$body,$signSecret))];
    }

    /** Authenticate BEFORE parsing or opening any journal. No bearer/private key goes to the source. */
    public static function authenticate(string $body,string $signature,string $signPublic,array $scope,int $now): array
    {
        if (strlen($body)>self::MAX_REQUEST || !preg_match('/\A[a-f0-9]{128}\z/D',$signature)
            || strlen($signPublic)!==32 || !sodium_crypto_sign_verify_detached(hex2bin($signature),self::DOMAIN.$body,$signPublic)) self::fail('authentication');
        $request=json_decode($body,true,10,JSON_THROW_ON_ERROR);
        if (!is_array($request)) self::fail('request');
        self::fields($request,['schema_version','scope','checkpoint','issued_at_unix','nonce']);
        if ($request['schema_version']!==1 || !is_int($request['issued_at_unix'])
            || $request['issued_at_unix']<$now-120 || $request['issued_at_unix']>$now+60
            || !is_string($request['nonce']) || !preg_match('/\A[a-f0-9]{32}\z/D',$request['nonce'])) self::fail('request');
        if (!is_array($request['scope']) || AdvertisingVisitContract::scope($request['scope'])!==AdvertisingVisitContract::scope($scope)) self::fail('scope');
        if (!is_array($request['checkpoint'])) self::fail('checkpoint');
        self::fields($request['checkpoint'],['cursor','fingerprint']);
        $c=$request['checkpoint']['cursor'];$f=$request['checkpoint']['fingerprint'];
        if (!is_string($c) || !preg_match('/\A(?:0|[1-9][0-9]{0,19})\z/D',$c)
            || AdvertisingVisitContract::compare($c,(string)PHP_INT_MAX)>0
            || ($c==='0' ? $f!==null : !is_string($f)||!preg_match('/\A[a-f0-9]{64}\z/D',$f))) self::fail('checkpoint');
        return $request;
    }

    public static function seal(string $requestBody,array $read,string $boxPublic,int $now): string
    {
        self::fields($read,['page','has_more']);
        if (!is_array($read['page']) || !is_bool($read['has_more']) || strlen($boxPublic)!==32) self::fail('response');
        $plain=json_encode(['schema_version'=>1,'request_sha256'=>hash('sha256',$requestBody),
            'created_at_unix'=>$now,'read'=>$read],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        if (strlen($plain)>AdvertisingVisitContract::MAX_BYTES+1024) self::fail('response_bound');
        $wire=base64_encode(sodium_crypto_box_seal($plain,$boxPublic));
        if (strlen($wire)>self::MAX_WIRE) self::fail('response_bound');
        return $wire;
    }

    public static function open(string $wire,string $requestBody,string $boxPair,array $scope,array $checkpoint,int $now): array
    {
        if (strlen($wire)>self::MAX_WIRE) self::fail('response_bound');
        $cipher=base64_decode($wire,true);
        if ($cipher===false || strlen($cipher)<48) self::fail('response');
        $plain=sodium_crypto_box_seal_open($cipher,$boxPair);
        if ($plain===false || strlen($plain)>AdvertisingVisitContract::MAX_BYTES+1024) self::fail('response');
        $decoded=json_decode($plain,true,24,JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) self::fail('response');
        self::fields($decoded,['schema_version','request_sha256','created_at_unix','read']);
        if ($decoded['schema_version']!==1 || !is_string($decoded['request_sha256'])
            || !hash_equals(hash('sha256',$requestBody),$decoded['request_sha256'])
            || !is_int($decoded['created_at_unix']) || $decoded['created_at_unix']<$now-120 || $decoded['created_at_unix']>$now+60
            || !is_array($decoded['read'])) self::fail('response_binding');
        self::fields($decoded['read'],['page','has_more']);
        if (!is_bool($decoded['read']['has_more']) || !is_array($decoded['read']['page'])) self::fail('response');
        $page=AdvertisingVisitContract::page($decoded['read']['page'],$scope,$now);
        if ($page['after_cursor']!==$checkpoint['cursor']) self::fail('response_checkpoint');
        return ['page'=>$page,'has_more'=>$decoded['read']['has_more']];
    }

    private static function fields(array $value,array $keys): void
    {
        if (count($value)!==count($keys) || array_diff(array_keys($value),$keys)) self::fail('fields');
    }
    private static function fail(string $reason): void { throw new InvalidArgumentException('advertising_transport_'.$reason); }
}
