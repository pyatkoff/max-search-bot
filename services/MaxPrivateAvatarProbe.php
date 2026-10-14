<?php
declare(strict_types=1);

require_once __DIR__.'/MaxTlsConfig.php';

/** Explicit, metadata-only owner-test diagnostic. Never used by Manager requests. */
final class MaxPrivateAvatarProbe
{
    public const MAX_RESPONSE_BYTES = 65536;
    private const OWNER_CONVERSATION_ID = '2';
    private const OWNER_PROJECT_KEY = 'anytour';
    private const OWNER_ORIGINAL_NAME = 'Pavel';
    private PDO $database;
    private $exchange;

    /** The exchange injection is for offline regression; it cannot choose the URL. */
    public function __construct(PDO $database, ?callable $exchange=null)
    {
        $this->database=$database;
        $this->exchange=$exchange ?? [self::class,'exchange'];
    }

    public static function arguments(array $arguments): ?array
    {
        $values=[];
        foreach ($arguments as $argument) {
            if ($argument==='--confirm-owner-test' && !isset($values['confirm_owner_test'])) {
                $values['confirm_owner_test']=true;
            } elseif (is_string($argument) && preg_match('/\A--expected-sha=(.+)\z/D',$argument,$match)) {
                if (isset($values['expected_sha'])) return null;
                $values['expected_sha']=$match[1];
            } else return null;
        }
        return self::validInput($values)?$values:null;
    }

    public static function decimalId($value): ?string
    {
        if (is_int($value) && $value>0) $value=(string)$value;
        if (!is_string($value) || !preg_match('/\A[1-9][0-9]{0,18}\z/D',$value)) return null;
        return strlen($value)<19 || strcmp($value,'9223372036854775807')<=0 ? $value : null;
    }

    public static function blocked(string $reason): array
    {
        return ['ok'=>false,'reason'=>$reason,'request_attempted'=>false,'http_status'=>0,
            'type_is_dialog'=>false,'chat_id_match'=>false,'user_id_match'=>false,
            'has_avatar_url'=>false,'has_full_avatar_url'=>false];
    }

    public function run(array $input, string $installedSha, string $configuredProject, string $token): array
    {
        if (!self::validInput($input)) return self::blocked('invalid_arguments');
        if ($installedSha!==$input['expected_sha']) return self::blocked('installed_sha_mismatch');
        if ($configuredProject!==self::OWNER_PROJECT_KEY) return self::blocked('configured_project_mismatch');
        if ($token==='' || strlen($token)>4096 || preg_match('/[\r\n\x00]/',$token)) return self::blocked('configured_token_unavailable');
        $target=$this->target();
        if ($target===null) return self::blocked('target_unavailable');
        if (($target['project_key']??null)!==$configuredProject || ($target['channel']??null)!=='max'
            || self::decimalId($target['id']??null)!==self::OWNER_CONVERSATION_ID
            || ($target['original_display_name']??null)!==self::OWNER_ORIGINAL_NAME) return self::blocked('target_scope_mismatch');
        $chat=self::decimalId($target['conversation_chat_id']??null);
        if ($chat===null || self::decimalId($target['channel_chat_id']??null)!==$chat) return self::blocked('stored_private_chat_mismatch');
        $userId=self::decimalId($target['external_user_id']??null);
        if ($userId===null) return self::blocked('stored_user_invalid');

        $result=self::blocked('transport_error');
        $result['request_attempted']=true;
        $bytes='';$tooLarge=false;
        $options=[CURLOPT_CUSTOMREQUEST=>'GET',CURLOPT_HTTPHEADER=>['Authorization: '.$token,'Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>10,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_RETURNTRANSFER=>false,CURLOPT_PROXY=>'',
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk) use (&$bytes,&$tooLarge): int {
                if (strlen($bytes)+strlen($chunk)>self::MAX_RESPONSE_BYTES) {$tooLarge=true;return 0;}
                $bytes.=$chunk;return strlen($chunk);
            }]+MaxTlsConfig::strictCurlOptions();
        try {$receipt=($this->exchange)('https://platform-api2.max.ru/chats/'.$chat,$options);}
        catch (Throwable $error) {return $result;}
        $http=$receipt['http_status']??0;
        $result['http_status']=is_int($http) && $http>=100 && $http<=599 ? $http : 0;
        if ($tooLarge) {$result['reason']='response_too_large';return $result;}
        if (!empty($receipt['transport_error'])) return $result;
        if (($receipt['ssl_verify_result']??-1)!==0) {$result['reason']='strict_tls_failed';return $result;}
        if ($result['http_status']!==200) {$result['reason']='provider_http_error';return $result;}
        try {$data=json_decode($bytes,true,24,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}
        catch (Throwable $error) {$result['reason']='invalid_provider_json';return $result;}
        if (!is_array($data)) {$result['reason']='invalid_provider_json';return $result;}
        $user=is_array($data['dialog_with_user']??null)?$data['dialog_with_user']:[];
        $result['type_is_dialog']=($data['type']??null)==='dialog';
        $result['chat_id_match']=self::decimalId($data['chat_id']??null)===$chat;
        $result['user_id_match']=self::decimalId($user['user_id']??null)===$userId;
        if (!$result['type_is_dialog'] || !$result['chat_id_match'] || !$result['user_id_match']) {
            $result['reason']='provider_identity_mismatch';return $result;
        }
        $result['has_avatar_url']=self::photoUrlPresent($user['avatar_url']??null);
        $result['has_full_avatar_url']=self::photoUrlPresent($user['full_avatar_url']??null);
        $result['ok']=true;
        $result['reason']=$result['has_avatar_url']||$result['has_full_avatar_url']?'photo_url_present':'photo_url_absent';
        return $result;
    }

    private static function validInput(array $input): bool
    {
        return count($input)===2 && ($input['confirm_owner_test']??null)===true
            && is_string($input['expected_sha']??null) && (bool)preg_match('/\A[0-9a-f]{40}\z/D',$input['expected_sha']);
    }

    private function target(): ?array
    {
        if ($this->database->inTransaction()) return null;
        try {
            $this->database->exec('SET TRANSACTION READ ONLY');
            $this->database->beginTransaction();
            // The owner's pre-existing test dialogue is an ordinary conversation,
            // not an is_test fixture. Its exact identity is fixed here, never an
            // operator-supplied ID or a query across clients. No marker is changed.
            $query=$this->database->prepare('SELECT c.id,c.project_key,c.channel,cu.display_name AS original_display_name,c.external_chat_id AS conversation_chat_id,cc.external_chat_id AS channel_chat_id,cc.external_user_id FROM conversations c JOIN customers cu ON cu.id=c.customer_id JOIN customer_channels cc ON cc.id=c.customer_channel_id AND cc.customer_id=c.customer_id AND cc.project_key=c.project_key AND cc.channel=c.channel JOIN projects p ON p.project_key=c.project_key AND p.is_active=1 WHERE c.id=? AND c.project_key=? AND c.channel=? AND cu.display_name=? LIMIT 1');
            $query->execute([self::OWNER_CONVERSATION_ID,self::OWNER_PROJECT_KEY,'max',self::OWNER_ORIGINAL_NAME]);
            $row=$query->fetch(PDO::FETCH_ASSOC);
            return is_array($row)?$row:null;
        } catch (Throwable $error) {return null;}
        finally {if ($this->database->inTransaction()) $this->database->rollBack();}
    }

    private static function photoUrlPresent($value): bool
    {
        if (!is_string($value) || $value==='' || strlen($value)>4096 || preg_match('/[\x00-\x20\x7f]/',$value)) return false;
        $url=parse_url($value);
        return is_array($url) && ($url['scheme']??'')==='https' && !empty($url['host'])
            && !isset($url['user']) && !isset($url['pass']);
    }

    private static function exchange(string $url, array $options): array
    {
        $handle=curl_init($url);
        curl_setopt_array($handle,$options);
        $ok=curl_exec($handle);
        $receipt=['http_status'=>(int)curl_getinfo($handle,CURLINFO_HTTP_CODE),
            'transport_error'=>$ok===false || curl_errno($handle)!==0,
            'ssl_verify_result'=>(int)curl_getinfo($handle,CURLINFO_SSL_VERIFYRESULT)];
        curl_close($handle);
        return $receipt;
    }
}
