<?php

declare(strict_types=1);

/** Isolated, stateless transport. Existing bot AiClient and runtime settings stay untouched. */
final class ManagerDraftClient
{
    public const MAX_RESPONSE_BYTES = 131072;
    private const MAX_OUTPUT_BYTES = 24576;
    private const MAX_REQUEST_BYTES = 196608;
    private const ENDPOINT = 'https://api.openai.com/v1/responses';

    public static function requestJson(string $instructions,array $payload): array
    {
        if (!defined('OPENAI_API_KEY') || !is_string(OPENAI_API_KEY) || trim(OPENAI_API_KEY)===''
            || OPENAI_API_KEY==='PASTE_OPENAI_API_KEY_HERE' || strlen(OPENAI_API_KEY)>4096
            || preg_match('/[\x00-\x20\x7F]/',OPENAI_API_KEY)) return ['_error'=>'provider_not_configured'];
        if (!function_exists('curl_init')) return ['_error'=>'provider_unavailable'];
        try {
            $body=self::requestBody($instructions,$payload);
            $encoded=json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            if (strlen($encoded)>self::MAX_REQUEST_BYTES) return ['_error'=>'provider_unavailable'];
            $bytes='';$oversized=false;
            $handle=curl_init(self::ENDPOINT);
            if ($handle===false) return ['_error'=>'provider_unavailable'];
            try {
                $configured=curl_setopt_array($handle,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$encoded,
                    CURLOPT_HTTPHEADER=>['Authorization: Bearer '.OPENAI_API_KEY,'Content-Type: application/json'],
                    CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>20,CURLOPT_FOLLOWLOCATION=>false,
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
            return self::parseResponse($bytes,$status);
        } catch (Throwable $ignored) {return ['_error'=>'provider_unavailable'];}
    }

    /** No credentials in this pure request projection; usable by offline regression/evaluation. */
    public static function requestBody(string $instructions,array $payload): array
    {
        $model=defined('OPENAI_MODEL') ? OPENAI_MODEL : 'gpt-5.6-luna';
        if (!is_string($model) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D',$model)!==1) {
            throw new InvalidArgumentException('invalid_draft_model');
        }
        return ['model'=>$model,'store'=>false,'max_output_tokens'=>2200,
            'input'=>[
                ['role'=>'developer','content'=>[['type'=>'input_text','text'=>$instructions]]],
                ['role'=>'user','content'=>[['type'=>'input_text','text'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]]],
            ],
            'text'=>['format'=>['type'=>'json_schema','name'=>'manager_reply_draft','strict'=>true,'schema'=>self::responseSchema()]]];
    }

    public static function responseSchema(): array
    {
        $properties=['ok'=>['type'=>'boolean','enum'=>[true]],'should_draft'=>['type'=>'boolean'],
            'draft'=>['type'=>['string','null']],
            'next_action'=>['type'=>'string','enum'=>['clarify','answer_question','check_offer','prepare_selection','no_contact']],
            'reason'=>['type'=>'string'],'checks'=>['type'=>'array','items'=>['type'=>'string']],
            'evidence_message_ids'=>['type'=>'array','items'=>['type'=>'integer']],
            'history_incomplete'=>['type'=>'boolean']];
        return ['type'=>'object','properties'=>$properties,'required'=>array_keys($properties),'additionalProperties'=>false];
    }

    /** Decode only a completed assistant JSON response. Never return provider bodies or exception messages. */
    public static function parseResponse(string $bytes,int $httpStatus): array
    {
        if (strlen($bytes)>self::MAX_RESPONSE_BYTES) return ['_error'=>'provider_response_too_large'];
        if ($httpStatus<200 || $httpStatus>=300) return ['_error'=>'provider_unavailable'];
        try {$response=json_decode($bytes,true,32,JSON_THROW_ON_ERROR);}
        catch (Throwable $ignored) {return ['_error'=>'provider_unavailable'];}
        if (!is_array($response) || array_is_list($response) || !empty($response['error'])) return ['_error'=>'provider_unavailable'];
        if (($response['status']??'')!=='completed' || !empty($response['incomplete_details'])) return ['_error'=>'provider_incomplete'];
        if (!isset($response['output']) || !is_array($response['output']) || !array_is_list($response['output'])) return ['_error'=>'provider_unavailable'];
        $text='';$messages=0;
        foreach ($response['output'] as $item) {
            if (!is_array($item)) return ['_error'=>'provider_unavailable'];
            if (($item['type']??'')==='reasoning') continue;
            if (($item['type']??'')!=='message' || ($item['role']??'')!=='assistant'
                || (isset($item['status']) && $item['status']!=='completed') || ++$messages>1
                || !isset($item['content']) || !is_array($item['content']) || !array_is_list($item['content'])) return ['_error'=>'provider_unavailable'];
            foreach ($item['content'] as $content) {
                if (!is_array($content)) return ['_error'=>'provider_unavailable'];
                if (($content['type']??'')==='refusal') return ['_error'=>'provider_refused'];
                if (($content['type']??'')!=='output_text' || !isset($content['text']) || !is_string($content['text'])) return ['_error'=>'provider_unavailable'];
                if (strlen($text)+strlen($content['text'])>self::MAX_OUTPUT_BYTES) return ['_error'=>'provider_response_too_large'];
                $text.=$content['text'];
            }
        }
        try {$result=json_decode($text,true,16,JSON_THROW_ON_ERROR);}
        catch (Throwable $ignored) {return ['_error'=>'provider_unavailable'];}
        return is_array($result) && !array_is_list($result) ? $result : ['_error'=>'provider_unavailable'];
    }
}
