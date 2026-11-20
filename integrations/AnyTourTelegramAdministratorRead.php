<?php

declare(strict_types=1);

/** Single-channel Telegram administration read. Used only by the isolated audit. */
final class AnyTourTelegramAdministratorRead
{
    private const CHAT_ID = -1001845835645;
    private const MAX_BYTES = 131072;

    public static function read(int $chatId, bool $returnBots): array
    {
        if ($chatId !== self::CHAT_ID || $returnBots !== true) {
            return ['ok'=>false, 'state'=>'outside_exact_read_scope'];
        }

        $token = defined('TELEGRAM_BOT_TOKEN') ? (string)TELEGRAM_BOT_TOKEN : '';
        if ($token === '' || !extension_loaded('curl')) {
            return ['ok'=>false, 'state'=>'installed_telegram_read_unavailable'];
        }

        $body = json_encode(['chat_id'=>self::CHAT_ID, 'return_bots'=>true], JSON_THROW_ON_ERROR);
        $raw = '';
        $curl = curl_init('https://api.telegram.org/bot'.$token.'/getChatAdministrators');
        curl_setopt_array($curl, [
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>$body,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT=>4,
            CURLOPT_TIMEOUT=>10,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_WRITEFUNCTION=>static function ($handle, string $chunk) use (&$raw): int {
                if (strlen($raw) + strlen($chunk) > self::MAX_BYTES) return 0;
                $raw .= $chunk;
                return strlen($chunk);
            },
        ]);

        try {
            $received = curl_exec($curl);
            $http = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $errno = curl_errno($curl);
        } finally {
            curl_close($curl);
        }

        if ($received === false || $errno !== 0) {
            return ['ok'=>false, 'state'=>'telegram_transport_failure', 'http'=>$http, 'curl_errno'=>$errno];
        }

        $value = json_decode($raw, true, 32);
        unset($raw);
        if ($http !== 200 || ($value['ok'] ?? false) !== true || !is_array($value['result'] ?? null)
            || !array_is_list($value['result'])) {
            return ['ok'=>false, 'state'=>'telegram_admin_read_not_confirmed', 'http'=>$http,
                'telegram_error_code'=>is_int($value['error_code'] ?? null) ? $value['error_code'] : null];
        }

        return ['ok'=>true, 'state'=>'telegram_admin_list_read', 'http'=>$http,
            'bots'=>self::botRights($value['result'])];
    }

    private static function botRights(array $members): array
    {
        $bots = [];
        foreach ($members as $member) {
            $user = $member['user'] ?? [];
            $username = $user['username'] ?? null;
            if (($user['is_bot'] ?? false) !== true || !is_string($username)
                || !preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $username)) continue;

            $bots[] = [
                'username'=>$username,
                'can_edit_messages'=>($member['can_edit_messages'] ?? false) === true,
                'can_post_messages'=>($member['can_post_messages'] ?? false) === true,
            ];
        }
        usort($bots, static fn(array $a, array $b): int => strcasecmp($a['username'], $b['username']));
        return $bots;
    }
}
