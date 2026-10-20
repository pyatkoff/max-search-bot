<?php

declare(strict_types=1);

require_once __DIR__.'/ManagerDraftClient.php';
require_once dirname(__DIR__).'/services/VirtualReplyProtocol.php';

/** Separate private Responses transport. No production draft constants, state or delivery. */
final class VirtualReplyClient
{
    public const MAX_REQUEST_BYTES = 196608;
    public const MAX_RESPONSE_BYTES = 131072;
    private const ENDPOINT = 'https://api.openai.com/v1/responses';

    public static function requestBody(string $role,string $instructions,array $payload): array
    {
        $profile=VirtualReplyProtocol::profile($role);
        if (trim($instructions)==='' || preg_match('//u',$instructions)!==1) throw new InvalidArgumentException('invalid_virtual_reply_instructions');
        $body=$profile+['store'=>false,'input'=>[
            ['role'=>'developer','content'=>[['type'=>'input_text','text'=>$instructions]]],
            ['role'=>'user','content'=>[['type'=>'input_text','text'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]]],
        ],'text'=>['format'=>['type'=>'json_schema','name'=>'virtual_reply_'.$role,'strict'=>true,'schema'=>VirtualReplyProtocol::schema($role)]]];
        if (strlen(json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))>self::MAX_REQUEST_BYTES) {
            throw new InvalidArgumentException('virtual_reply_request_too_large');
        }
        return $body;
    }

    /** One bounded attempt only. Caller owns reservation, semantic validation and unknown outcomes. */
    public static function request(string $role,string $instructions,array $payload,?callable $metadataObserver=null): array
    {
        try { $body=self::requestBody($role,$instructions,$payload); }
        catch (Throwable $ignored) { return ['_error'=>'invalid_virtual_reply_request']; }
        if (!defined('OPENAI_API_KEY') || !is_string(OPENAI_API_KEY) || trim(OPENAI_API_KEY)===''
            || OPENAI_API_KEY==='PASTE_OPENAI_API_KEY_HERE' || strlen(OPENAI_API_KEY)>4096
            || preg_match('/[\x00-\x20\x7F]/',OPENAI_API_KEY)) return ['_error'=>'provider_not_configured'];
        if (!function_exists('curl_init')) return ['_error'=>'provider_unavailable'];
        try {
            $encoded=json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            $bytes='';$oversized=false;
            $handle=curl_init(self::ENDPOINT);
            if ($handle===false) return ['_error'=>'provider_unavailable'];
            try {
                $configured=curl_setopt_array($handle,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$encoded,
                    CURLOPT_HTTPHEADER=>['Authorization: Bearer '.OPENAI_API_KEY,'Content-Type: application/json'],
                    CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>$role==='author'?45:90,CURLOPT_FOLLOWLOCATION=>false,
                    CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
                    CURLOPT_PROXY=>'',CURLOPT_RETURNTRANSFER=>false,
                    CURLOPT_WRITEFUNCTION=>static function($ignored,string $chunk) use (&$bytes,&$oversized): int {
                        if (strlen($bytes)+strlen($chunk)>self::MAX_RESPONSE_BYTES) {$oversized=true;return 0;}
                        $bytes.=$chunk;return strlen($chunk);
                    }]);
                if (!$configured) return ['_error'=>'provider_unavailable'];
                $success=curl_exec($handle);
                $errno=curl_errno($handle);$status=(int)curl_getinfo($handle,CURLINFO_HTTP_CODE);
            } finally {curl_close($handle);}
            if ($oversized) return ['_error'=>'provider_response_too_large'];
            if ($errno===CURLE_OPERATION_TIMEDOUT) return ['_error'=>'provider_timeout'];
            if ($success===false || $errno!==0) return ['_error'=>'provider_unavailable'];
            return self::parseResponse($bytes,$status,$metadataObserver);
        } catch (Throwable $ignored) {return ['_error'=>'provider_unavailable'];}
    }

    /** Pure decoder shared with the manager transport; never returns raw provider bodies. */
    public static function parseResponse(string $bytes,int $httpStatus,?callable $metadataObserver=null): array
    {
        return ManagerDraftClient::parseResponse($bytes,$httpStatus,$metadataObserver);
    }
}
