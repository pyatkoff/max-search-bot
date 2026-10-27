<?php
declare(strict_types=1);

/** Purpose-bound signed requests for a bounded, read-only MAX lookup. */
final class MaxTrackerLookupProtocol
{
    public const PURPOSE = 'anytour-max-tracker-lookup-v1';
    public const MAX_USERS = 25;
    public const MAX_BODY = 8192;
    public const MAX_RESPONSE = 32768;

    public static function signature(string $body, string $privateKey): string
    {
        $key=openssl_pkey_get_private($privateKey);
        $details=$key?openssl_pkey_get_details($key):false;
        if(!$details||$details['type']!==OPENSSL_KEYTYPE_RSA||$details['bits']<2048
            ||!openssl_sign(self::PURPOSE."\n".$body,$signature,$key,OPENSSL_ALGO_SHA256))throw new InvalidArgumentException('lookup_auth_missing');
        return base64_encode($signature);
    }

    public static function body(string $counterId, array $userIds, int $now, ?string $nonce = null, ?array $membership = null): string
    {
        $data = ['version'=>1,'counter_id'=>$counterId,'user_ids'=>array_values($userIds),
            'issued_at'=>$now,'nonce'=>$nonce ?? bin2hex(random_bytes(16))];
        if ($membership !== null) $data['membership']=$membership;
        self::validate($data, $now, $counterId);
        return json_encode($data, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    }

    public static function authenticate(string $body, string $signature, string $publicKey, int $now, string $counterId): array
    {
        $raw=base64_decode($signature,true);
        $key=openssl_pkey_get_public($publicKey);$details=$key?openssl_pkey_get_details($key):false;
        if($body===''||strlen($body)>self::MAX_BODY||!is_string($raw)||strlen($raw)>1024
            ||!$details||$details['type']!==OPENSSL_KEYTYPE_RSA||$details['bits']<2048
            ||openssl_verify(self::PURPOSE."\n".$body,$raw,$key,OPENSSL_ALGO_SHA256)!==1)throw new InvalidArgumentException('lookup_unauthorized');
        $data=json_decode($body,true,16,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);
        if (!is_array($data)) throw new InvalidArgumentException('lookup_invalid_body');
        self::validate($data,$now,$counterId);
        return $data;
    }

    private static function validate(array $data, int $now, string $counterId): void
    {
        if (($data['version']??null)!==1 || ($data['counter_id']??null)!==$counterId
            || !self::id($counterId) || !is_int($data['issued_at']??null)
            || abs($now-$data['issued_at'])>90 || !is_string($data['nonce']??null)
            || !preg_match('/\A[a-f0-9]{32}\z/D',$data['nonce']) || !is_array($data['user_ids']??null)
            || count($data['user_ids'])>self::MAX_USERS || !array_is_list($data['user_ids'])) {
            throw new InvalidArgumentException('lookup_invalid_body');
        }
        $seen=[];
        foreach ($data['user_ids'] as $id) {
            if (!self::id($id) || isset($seen[$id])) throw new InvalidArgumentException('lookup_invalid_users');
            $seen[$id]=true;
        }
        if (array_key_exists('membership',$data)) {
            $membership=$data['membership'];
            if (!is_array($membership) || array_keys($membership)!==['not_before_unix','cutoff_unix']
                || !is_int($membership['not_before_unix']) || !is_int($membership['cutoff_unix'])
                || $membership['not_before_unix']<946684800
                || $membership['not_before_unix']>$membership['cutoff_unix']
                || abs($now-$membership['cutoff_unix'])>90) {
                throw new InvalidArgumentException('lookup_invalid_membership_window');
            }
        }
    }

    public static function id($value, int $digits=20): bool
    {
        return is_string($value) && strlen($value)<=$digits && preg_match('/\A[1-9][0-9]*\z/D',$value)===1;
    }
}
