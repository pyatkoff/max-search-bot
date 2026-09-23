<?php

declare(strict_types=1);

final class LeadReceiverConfigEditor
{
    public const KEY = 'MAX_SEARCH_LEAD_RECEIVER_URL';
    public const REQUIRED_PATH = '/max-search/lead-receiver.php';

    public static function validateUrl(string $url): array
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (!is_array($parts)) throw new InvalidArgumentException('invalid_lead_receiver_url');
        if (strtolower((string)($parts['scheme'] ?? '')) !== 'https') throw new InvalidArgumentException('lead_receiver_requires_https');
        $host = strtolower(trim((string)($parts['host'] ?? '')));
        if ($host === '' || preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/D', $host) !== 1) throw new InvalidArgumentException('invalid_lead_receiver_host');
        if ($host === 'app.anytoour.ru' || str_ends_with($host, '.app.anytoour.ru')) throw new InvalidArgumentException('lead_receiver_must_be_legacy_host');
        if ((string)($parts['path'] ?? '') !== self::REQUIRED_PATH) throw new InvalidArgumentException('invalid_lead_receiver_path');
        foreach (['user','pass','port','query','fragment'] as $key) {
            if (array_key_exists($key, $parts)) throw new InvalidArgumentException('lead_receiver_url_extras_not_allowed');
        }
        return ['url'=>$url,'host'=>$host,'path'=>self::REQUIRED_PATH];
    }

    public static function rewrite(string $source, string $url): string
    {
        self::validateUrl($url);
        $key = preg_quote(self::KEY, '/');
        $lines = preg_split('/\R/', $source) ?: [];
        $lines = array_values(array_filter($lines, static function (string $line) use ($key): bool {
            return preg_match('/^\s*define\s*\(\s*[\'\"]'.$key.'[\'\"]\s*,.*\)\s*;\s*$/', $line) !== 1
                && preg_match('/^\s*const\s+'.$key.'\s*=.*;\s*$/', $line) !== 1;
        }));
        $insertAt = count($lines);
        for ($i = count($lines) - 1; $i >= 0; --$i) {
            if (trim((string)$lines[$i]) === '?>') { $insertAt = $i; break; }
        }
        array_splice($lines, $insertAt, 0, ["define('".self::KEY."', ".var_export($url, true).");"]);
        return rtrim(implode(PHP_EOL, $lines)) . PHP_EOL;
    }

    public static function directValue(string $source): ?string
    {
        $key = preg_quote(self::KEY, '/');
        if (!preg_match_all('/^\s*define\s*\(\s*[\'\"]'.$key.'[\'\"]\s*,\s*([\'\"])(.*?)\1\s*\)\s*;\s*$/m', $source, $matches)) return null;
        $values = $matches[2] ?? [];
        if (!$values) return null;
        return (string)end($values);
    }
}
