<?php

class IncomingUpdateDeduplicator
{
    private const TTL_SECONDS = 86400;
    private const MAX_ENTRIES = 5000;

    public static function claim(array $update, $storageFile = null)
    {
        $key = self::key($update);
        if ($key === '') return true;

        $file = $storageFile ?: (sys_get_temp_dir() . '/max-search-bot-update-dedupe.json');
        $fh = @fopen($file, 'c+');
        if (!$fh) return true; // Never break webhook processing because dedupe storage is unavailable.

        try {
            if (!flock($fh, LOCK_EX)) return true;
            rewind($fh);
            $raw = stream_get_contents($fh);
            $state = json_decode((string)$raw, true);
            if (!is_array($state)) $state = [];

            $now = time();
            $cutoff = $now - self::TTL_SECONDS;
            foreach ($state as $storedKey => $ts) {
                if ((int)$ts < $cutoff) unset($state[$storedKey]);
            }

            if (isset($state[$key])) {
                flock($fh, LOCK_UN);
                return false;
            }

            $state[$key] = $now;
            if (count($state) > self::MAX_ENTRIES) {
                asort($state, SORT_NUMERIC);
                $state = array_slice($state, -self::MAX_ENTRIES, null, true);
            }

            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            fflush($fh);
            flock($fh, LOCK_UN);
            return true;
        } finally {
            fclose($fh);
        }
    }

    public static function key(array $update)
    {
        $type = (string)($update['update_type'] ?? '');

        if ($type === 'message_callback') {
            $callbackId = (string)($update['callback']['callback_id'] ?? '');
            if ($callbackId !== '') return 'callback:' . $callbackId;
        }

        if ($type === 'message_created') {
            $mid = (string)($update['message']['body']['mid'] ?? '');
            if ($mid !== '') return 'message:' . $mid;
        }

        if ($type === 'bot_started') {
            $userId = (string)($update['user']['user_id'] ?? $update['user']['id'] ?? $update['user_id'] ?? '');
            $timestamp = (string)($update['timestamp'] ?? '');
            $payload = (string)($update['payload'] ?? $update['start_payload'] ?? '');
            if ($userId !== '' || $timestamp !== '' || $payload !== '') {
                return 'bot_started:' . hash('sha256', $userId . '|' . $timestamp . '|' . $payload);
            }
        }

        return '';
    }
}
