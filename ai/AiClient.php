<?php
class AiClient
{
    public static function requestJson(string $instructions, array $payload)
    {
        if (!defined('OPENAI_API_KEY') || OPENAI_API_KEY === '' || OPENAI_API_KEY === 'PASTE_OPENAI_API_KEY_HERE') {
            return ['_error' => 'OPENAI_API_KEY_NOT_SET'];
        }

        $body = [
            'model' => defined('OPENAI_MODEL') ? OPENAI_MODEL : 'gpt-5.6-luna',
            'input' => [
                [
                    'role' => 'developer',
                    'content' => [['type'=>'input_text','text'=>$instructions]],
                ],
                [
                    'role' => 'user',
                    'content' => [['type'=>'input_text','text'=>json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]],
                ],
            ],
            'max_output_tokens' => 1200,
        ];

        $ch = curl_init('https://api.openai.com/v1/responses');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // Webhook MAX не должен ждать AI десятки секунд:
        // иначе MAX считает доставку неуспешной и повторяет update.
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 12);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . OPENAI_API_KEY,
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $errno) return ['_error'=>'CURL_'.$errno, '_detail'=>$error];
        $response = json_decode($raw, true);
        if ($http < 200 || $http >= 300 || !is_array($response)) return ['_error'=>'HTTP_'.$http, '_detail'=>$raw];

        $text = '';
        if (!empty($response['output_text']) && is_string($response['output_text'])) $text = $response['output_text'];
        if ($text === '' && !empty($response['output']) && is_array($response['output'])) {
            foreach ($response['output'] as $item) {
                if (($item['type'] ?? '') !== 'message') continue;
                foreach (($item['content'] ?? []) as $content) {
                    if (($content['type'] ?? '') === 'output_text' && isset($content['text'])) $text .= $content['text'];
                }
            }
        }
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);
        $json = json_decode($text, true);
        if (!is_array($json)) return ['_error'=>'BAD_JSON', '_detail'=>$text];
        return $json;
    }
}
