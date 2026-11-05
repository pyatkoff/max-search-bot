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

    public static function body(string $counterId, array $userIds, int $now, ?string $nonce = null, ?array $membership = null, ?string $memberAfter = null, ?string $historyAfter = null): string
    {
        $data = ['version'=>1,'counter_id'=>$counterId,'user_ids'=>array_values($userIds),
            'issued_at'=>$now,'nonce'=>$nonce ?? bin2hex(random_bytes(16))];
        if ($membership !== null) $data['membership']=$membership;
        if ($memberAfter !== null) $data['member_page_after']=$memberAfter;
        if ($historyAfter !== null) $data['history_member_page_after']=$historyAfter;
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
        if (array_key_exists('member_page_after',$data) && (!is_string($data['member_page_after'])
            || preg_match('/\A(?:0|[1-9][0-9]{0,19})\z/D',$data['member_page_after'])!==1
            || $data['user_ids']!==[] || array_key_exists('membership',$data)))
            throw new InvalidArgumentException('lookup_invalid_member_page');
        if (array_key_exists('history_member_page_after',$data)) {
            if (!is_string($data['history_member_page_after']) || $data['user_ids']!==[]
                || array_key_exists('member_page_after',$data) || array_key_exists('membership',$data))
                throw new InvalidArgumentException('lookup_invalid_history_cursor');
            self::historyCursor($data['history_member_page_after'],$now,$counterId);
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


    /** A fixed retained-history prefix; it is not an HL9 record cursor. */
    public static function historyCursor(string $cursor,int $now,string $counterId):?array
    {
        if (!self::id($counterId) || $now<946684800) throw new InvalidArgumentException('lookup_invalid_history_cursor');
        if ($cursor==='0') return null;
        if (strlen($cursor)>512 || !preg_match('/\Amh1\.([A-Za-z0-9_-]+)\z/D',$cursor,$match))
            throw new InvalidArgumentException('lookup_invalid_history_cursor');
        $encoded=$match[1];
        if (strlen($encoded)%4===1) throw new InvalidArgumentException('lookup_invalid_history_cursor');
        $json=base64_decode(strtr($encoded,'-_','+/').str_repeat('=',(4-strlen($encoded)%4)%4),true);
        if (!is_string($json) || rtrim(strtr(base64_encode($json),'+/','-_'),'=')!==$encoded)
            throw new InvalidArgumentException('lookup_invalid_history_cursor');
        try { $v=json_decode($json,true,4,JSON_THROW_ON_ERROR); }
        catch (Throwable $ignored) { throw new InvalidArgumentException('lookup_invalid_history_cursor'); }
        if (!is_array($v) || !array_is_list($v) || count($v)!==6 || $v[0]!==1 || $v[1]!==$counterId
            || !is_int($v[2]) || $v[2]<946684800 || $v[2]>$now || $now-$v[2]>900
            || !self::id($v[3]) || !self::id($v[4])
            || strlen($v[4])>strlen($v[3]) || (strlen($v[4])===strlen($v[3]) && strcmp($v[4],$v[3])>0)
            || !is_string($v[5]) || preg_match('/\A[a-f0-9]{64}\z/D',$v[5])!==1
            || json_encode($v,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)!==$json)
            throw new InvalidArgumentException('lookup_invalid_history_cursor');
        return ['counter_id'=>$v[1],'cutoff_unix'=>$v[2],'history_fence_id'=>$v[3],
            'before_id'=>$v[4],'source_sha256'=>$v[5]];
    }

    public static function encodeHistoryCursor(string $counterId,int $cutoffUnix,string $fenceId,string $beforeId,string $sourceSha256):string
    {
        $json=json_encode([1,$counterId,$cutoffUnix,$fenceId,$beforeId,$sourceSha256],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $cursor='mh1.'.rtrim(strtr(base64_encode($json),'+/','-_'),'=');
        self::historyCursor($cursor,$cutoffUnix,$counterId);
        return $cursor;
    }

    public static function id($value, int $digits=20): bool
    {
        return is_string($value) && strlen($value)<=$digits && preg_match('/\A[1-9][0-9]*\z/D',$value)===1;
    }
}

