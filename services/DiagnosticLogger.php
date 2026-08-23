<?php

class DiagnosticLogger
{
    private static $file;

    public static function log($component, $event, array $data = [], $chatId = null, $level = 'info')
    {
        $record = [
            'ts' => date('c'),
            'level' => (string)$level,
            'component' => (string)$component,
            'event' => (string)$event,
        ];

        if ($chatId !== null && $chatId !== '') {
            $record['chat_id'] = $chatId;
        }
        if ($data) {
            $record['data'] = self::sanitize($data);
        }

        $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) return false;

        return @file_put_contents(self::file(), $json . PHP_EOL, FILE_APPEND | LOCK_EX) !== false;
    }

    public static function error($component, $event, array $data = [], $chatId = null)
    {
        return self::log($component, $event, $data, $chatId, 'error');
    }

    public static function setFile($file)
    {
        self::$file = (string)$file;
    }

    private static function file()
    {
        return self::$file ?: dirname(__DIR__) . '/structured_events.log';
    }

    private static function sanitize(array $data)
    {
        $out = [];
        foreach ($data as $key => $value) {
            $keyString = strtolower((string)$key);
            if (preg_match('/token|secret|password|authorization|api[_-]?key/', $keyString)) {
                $out[$key] = '[redacted]';
                continue;
            }
            if (is_array($value)) {
                $out[$key] = self::sanitize($value);
                continue;
            }
            if (is_string($value)) {
                $value = preg_replace('/(?<!\d)(?:\+7|8)[\s\-\(\)]*(?:\d[\s\-\(\)]*){10}(?!\d)/u', '[phone-redacted]', $value);
                if (strlen($value) > 4000) $value = substr($value, 0, 4000) . '…';
            }
            $out[$key] = $value;
        }
        return $out;
    }
}
