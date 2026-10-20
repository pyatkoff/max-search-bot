<?php

declare(strict_types=1);

/** Isolated, stateless transport. Existing bot AiClient and runtime settings stay untouched. */
final class ManagerDraftClient
{
    public const MAX_RESPONSE_BYTES = 131072;
    private const MAX_OUTPUT_BYTES = 24576;
    public const MAX_REQUEST_BYTES = 196608;
    public const MAX_IMAGE_REQUEST_BYTES = 6291456;
    private const ENDPOINT = 'https://api.openai.com/v1/responses';

    public static function requestJson(string $instructions,array $payload,?callable $metadataObserver=null,array $images=[]): array
    {
        if (!defined('OPENAI_API_KEY') || !is_string(OPENAI_API_KEY) || trim(OPENAI_API_KEY)===''
            || OPENAI_API_KEY==='PASTE_OPENAI_API_KEY_HERE' || strlen(OPENAI_API_KEY)>4096
            || preg_match('/[\x00-\x20\x7F]/',OPENAI_API_KEY)) return ['_error'=>'provider_not_configured'];
        if (!function_exists('curl_init')) return ['_error'=>'provider_unavailable'];
        try {
            $body=self::requestBody($instructions,$payload,$images);
            $encoded=json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            if (strlen($encoded)>self::MAX_REQUEST_BYTES+($images?self::MAX_IMAGE_REQUEST_BYTES:0)) return ['_error'=>'provider_unavailable'];
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
            return self::parseResponse($bytes,$status,$metadataObserver);
        } catch (Throwable $ignored) {return ['_error'=>'provider_unavailable'];}
    }

    /** No credentials in this pure request projection; usable by offline regression/evaluation. */
    public static function requestBody(string $instructions,array $payload,array $images=[]): array
    {
        if (count($images)>4) throw new InvalidArgumentException('invalid_image_input');
        $imageBytes=0;
        foreach ($images as $image) {
            if (!is_array($image)||array_keys($image)!==['type','image_url','detail']||$image['type']!=='input_image'
                ||$image['detail']!=='high'||!is_string($image['image_url'])
                ||preg_match('~\Adata:image/(?:jpeg|png|webp);base64,[A-Za-z0-9+/]+={0,2}\z~D',$image['image_url'])!==1) throw new InvalidArgumentException('invalid_image_input');
            $imageBytes+=strlen($image['image_url']);
        }
        if ($imageBytes>self::MAX_IMAGE_REQUEST_BYTES) throw new InvalidArgumentException('invalid_image_input');
        $model=defined('MANAGER_AI_DRAFT_MODEL') ? MANAGER_AI_DRAFT_MODEL : 'gpt-6.1-sol';
        $effort=defined('MANAGER_AI_DRAFT_REASONING') ? MANAGER_AI_DRAFT_REASONING : 'low';
        $tier=defined('MANAGER_AI_DRAFT_SERVICE_TIER') ? MANAGER_AI_DRAFT_SERVICE_TIER : 'default';
        if (!is_string($model) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D',$model)!==1) {
            throw new InvalidArgumentException('invalid_draft_model');
        }
        if (!is_string($effort) || !in_array($effort,['low','medium','high','xhigh','max'],true)) {
            throw new InvalidArgumentException('invalid_draft_reasoning');
        }
        if (!is_string($tier) || !in_array($tier,['default','fast','priority'],true)) {
            throw new InvalidArgumentException('invalid_draft_service_tier');
        }
        return ['model'=>$model,'reasoning'=>['effort'=>$effort],'service_tier'=>$tier,'store'=>false,'max_output_tokens'=>2200,
            'input'=>[
                ['role'=>'developer','content'=>[['type'=>'input_text','text'=>$instructions]]],
                ['role'=>'user','content'=>array_merge([['type'=>'input_text','text'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]],$images)],
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
    public static function parseResponse(string $bytes,int $httpStatus,?callable $metadataObserver=null): array
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
        if (!is_array($result) || array_is_list($result)) return ['_error'=>'provider_unavailable'];
        if ($metadataObserver!==null) {
            try {$metadataObserver(self::responseMetadata($response));}
            catch (Throwable $ignored) { /* Optional evaluation must not alter a draft or expose errors. */ }
        }
        return $result;
    }

    /** Bounded technical metadata only. No response IDs, content, raw bodies or logging. */
    private static function responseMetadata(array $response): array
    {
        $model=$response['model']??null;$tier=$response['service_tier']??null;
        $metadata=['model'=>is_string($model) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D',$model)===1 ? $model : null,
            'service_tier'=>is_string($tier) && in_array($tier,['default','fast','priority','ultrafast','flex','scale'],true) ? $tier : null,
            'usage'=>[]];
        $usage=$response['usage']??null;
        if (!is_array($usage) || array_is_list($usage)) return $metadata;
        foreach (['input_tokens','output_tokens','total_tokens'] as $field) {
            $value=$usage[$field]??null;
            if (is_int($value) && $value>=0 && $value<=10000000) $metadata['usage'][$field]=$value;
        }
        foreach (['input_tokens_details'=>'cached_tokens','output_tokens_details'=>'reasoning_tokens'] as $section=>$field) {
            $value=is_array($usage[$section]??null) ? ($usage[$section][$field]??null) : null;
            if (is_int($value) && $value>=0 && $value<=10000000) $metadata['usage'][$section]=[$field=>$value];
        }
        return $metadata;
    }
}
