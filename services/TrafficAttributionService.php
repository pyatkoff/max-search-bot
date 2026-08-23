<?php

/**
 * Runtime traffic metadata for one chat.
 *
 * Keeps yclid/region/campaign file persistence out of the legacy base class.
 * The service does not know about Bitrix and never reads secrets.
 */
class TrafficAttributionService
{
    public static function file($baseDir, $chatID)
    {
        $dir = rtrim((string)$baseDir, '/') . '/traffic';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        return $dir . '/' . preg_replace('/[^0-9\-]/', '', (string)$chatID) . '.json';
    }

    public static function save($baseDir, $chatID, $yclid = '', $region = '', $campaign = '', $raw = '')
    {
        $data = [
            'chat_id' => (string)$chatID,
            'yclid' => (string)$yclid,
            'region_id' => (string)$region,
            'campaign_id' => (string)$campaign,
            'raw' => (string)$raw,
            'updated_at' => date('c'),
        ];

        $file = self::file($baseDir, $chatID);
        $tmp = $file . '.tmp';
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) return false;
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
        @chmod($tmp, 0644);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }
        return $data;
    }

    public static function get($baseDir, $chatID)
    {
        $file = self::file($baseDir, $chatID);
        if (!is_file($file) || !is_readable($file)) return [];
        $data = json_decode((string)@file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    public static function buildMiniappUrl($botUrl, array $meta, $latestYclid = '')
    {
        $yclid = trim((string)$latestYclid);
        if ($yclid === '') $yclid = trim((string)($meta['yclid'] ?? ''));
        $region = trim((string)($meta['region_id'] ?? ''));
        $campaign = trim((string)($meta['campaign_id'] ?? ''));

        $start = $yclid;
        if ($region !== '' || $campaign !== '') {
            $start .= '_region_' . $region . '_campaign_' . $campaign;
        }
        if ($start === '') $start = '0';

        return rtrim((string)$botUrl, '?&') . '?startapp=' . rawurlencode($start);
    }
}
