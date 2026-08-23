<?php

class MaxTransport
{
    /**
     * Low-level MAX API request.
     *
     * IMPORTANT: SSL verification is intentionally disabled here exactly as it
     * was in MaxSearchBase. This is a compatibility move only; do not change
     * this behavior until the certificate issue is handled separately.
     */
    public static function request($baseUrl, $token, $httpMethod, $path, array $query = [], $body = null, $logFile = null)
    {
        $url = rtrim((string)$baseUrl, '/') . '/' . ltrim((string)$path, '/');
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $ch = curl_init($url);

        // Preserved production workaround: DO NOT CHANGE as part of refactor.
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper((string)$httpMethod));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: ' . (string)$token,
            'Content-Type: application/json',
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $errno) {
            self::log($logFile, 'API CURL ERROR ' . $errno . ': ' . $error);
            return false;
        }

        $data = json_decode($response, true);
        if ($httpCode < 200 || $httpCode >= 300) {
            self::log($logFile, 'API HTTP ' . $httpCode . ': ' . $response);
            return false;
        }
        return is_array($data) ? $data : true;
    }

    public static function deleteMessage($baseUrl, $token, $messageId, $logFile = null)
    {
        $messageId = trim((string)$messageId);
        if ($messageId === '') return false;
        return self::request($baseUrl, $token, 'DELETE', '/messages', ['message_id'=>$messageId], null, $logFile);
    }

    public static function send($baseUrl, $token, $chatId, $text, $logFile = null)
    {
        $res = self::request(
            $baseUrl,
            $token,
            'POST',
            '/messages',
            ['user_id'=>self::externalUserId($chatId)],
            ['text'=>(string)$text, 'format'=>'html'],
            $logFile
        );
        return self::extractMessageId($res);
    }

    public static function sendWithButtons($baseUrl, $token, $chatId, $text, $buttons, $logFile = null)
    {
        $maxButtons = self::convertButtons($buttons);
        $body = ['text'=>(string)$text, 'format'=>'html'];
        if ($maxButtons) {
            $body['attachments'] = [[
                'type'=>'inline_keyboard',
                'payload'=>['buttons'=>$maxButtons],
            ]];
        }

        $res = self::request(
            $baseUrl,
            $token,
            'POST',
            '/messages',
            ['user_id'=>self::externalUserId($chatId)],
            $body,
            $logFile
        );
        return self::extractMessageId($res);
    }

    public static function convertButtons($buttons)
    {
        $out = [];
        foreach ((array)$buttons as $row) {
            $newRow = [];
            foreach ((array)$row as $button) {
                if (!is_array($button) || empty($button['text'])) continue;

                if (!empty($button['request_contact'])) {
                    $newRow[] = [
                        'type'=>'request_contact',
                        'text'=>(string)$button['text'],
                    ];
                } elseif (array_key_exists('callback_data', $button)) {
                    $newRow[] = [
                        'type'=>'callback',
                        'text'=>(string)$button['text'],
                        'payload'=>(string)$button['callback_data'],
                    ];
                } elseif (!empty($button['url'])) {
                    $newRow[] = [
                        'type'=>'link',
                        'text'=>(string)$button['text'],
                        'url'=>(string)$button['url'],
                    ];
                }
            }
            if ($newRow) $out[] = $newRow;
        }
        return $out;
    }

    public static function externalUserId($internalId)
    {
        return abs((int)$internalId);
    }

    public static function extractMessageId($res)
    {
        if (!is_array($res)) return false;
        if (!empty($res['message']['body']['mid'])) return $res['message']['body']['mid'];
        if (!empty($res['body']['mid'])) return $res['body']['mid'];
        if (!empty($res['message']['mid'])) return $res['message']['mid'];
        return false;
    }

    public static function log($logFile, $data)
    {
        if (!$logFile) return;
        @file_put_contents(
            $logFile,
            date('d.m.Y H:i:s') . '--- ' . (is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "\r\n",
            FILE_APPEND
        );
    }
}
