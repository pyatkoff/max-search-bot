<?php

require_once __DIR__ . '/AiRuntimeLogger.php';

class ProjectHealth
{
    public static function collect($baseDir)
    {
        return [
            'generated_at' => date('c'),
            'git' => self::gitInfo($baseDir),
            'php' => self::phpInfo(),
            'config' => self::configInfo($baseDir),
            'features' => self::featureInfo($baseDir),
            'tourvisor_routes' => self::routesInfo($baseDir),
            'runtime' => self::runtimeInfo($baseDir),
            'max_update_dedupe' => self::dedupeInfo($baseDir),
        ];
    }

    private static function gitInfo($baseDir)
    {
        $cwd = getcwd();
        @chdir($baseDir);
        $commit = trim((string)@shell_exec('git rev-parse HEAD 2>/dev/null'));
        $branch = trim((string)@shell_exec('git rev-parse --abbrev-ref HEAD 2>/dev/null'));
        $status = trim((string)@shell_exec('git status --porcelain 2>/dev/null'));
        if ($cwd) @chdir($cwd);

        return [
            'commit' => $commit,
            'short_commit' => $commit !== '' ? substr($commit, 0, 7) : '',
            'branch' => $branch,
            'clean' => ($status === ''),
        ];
    }

    private static function phpInfo()
    {
        $paths = [];
        $current = defined('PHP_BINARY') ? PHP_BINARY : '';
        if ($current !== '') $paths[] = $current;

        foreach (['/usr/bin/php*', '/usr/local/bin/php*', '/opt/php*/bin/php', '/opt/php*/usr/bin/php'] as $pattern) {
            foreach ((array)glob($pattern) as $path) {
                if (is_file($path) && is_executable($path)) $paths[] = $path;
            }
        }
        $paths = array_values(array_unique($paths));

        $binaries = [];
        foreach ($paths as $path) {
            $cmd = escapeshellarg($path) . ' -r ' . escapeshellarg('echo PHP_VERSION;') . ' 2>/dev/null';
            $version = trim((string)@shell_exec($cmd));
            if ($version !== '') $binaries[] = ['path'=>$path, 'version'=>$version];
        }

        return [
            'current_binary' => $current,
            'current_version' => PHP_VERSION,
            'binaries' => $binaries,
        ];
    }

    private static function configInfo($baseDir)
    {
        $file = $baseDir . '/config.php';
        $names = [];
        if (is_file($file) && is_readable($file)) {
            $text = (string)file_get_contents($file);
            if (preg_match_all('/define\s*\(\s*[\'\"]([A-Z0-9_]+)[\'\"]/i', $text, $m)) {
                $names = array_values(array_unique($m[1]));
                sort($names);
            }
        }

        return [
            'exists' => is_file($file),
            'readable' => is_readable($file),
            'defined_names' => $names,
            'required_contract' => [
                'OPENAI_API_KEY',
                'OPENAI_MODEL',
                'TOURVISOR_JWT',
            ],
            'missing_known_names' => array_values(array_diff(['OPENAI_API_KEY','TOURVISOR_JWT'], $names)),
        ];
    }

    private static function featureInfo($baseDir)
    {
        $file = $baseDir . '/project_features.php';
        if (!is_file($file) || !is_readable($file)) {
            return ['exists'=>false, 'readable'=>false, 'ai_v2'=>[]];
        }
        $features = require $file;
        return [
            'exists'=>true,
            'readable'=>true,
            'ai_v2'=>is_array($features) ? (array)($features['ai_v2'] ?? []) : [],
        ];
    }

    private static function routesInfo($baseDir)
    {
        $file = $baseDir . '/tourvisor_routes.json';
        if (!is_file($file) || !is_readable($file)) {
            return ['exists'=>false, 'readable'=>false];
        }

        $mtime = filemtime($file);
        $data = json_decode((string)file_get_contents($file), true);
        $departures = 0;
        $routes = 0;
        $dates = 0;
        if (is_array($data)) {
            foreach ((array)($data['departures'] ?? []) as $dep) {
                $departures++;
                foreach ((array)($dep['countries'] ?? []) as $country) {
                    $routes++;
                    $dates += count((array)($country['dates'] ?? []));
                }
            }
        }

        return [
            'exists' => true,
            'readable' => true,
            'valid_json' => is_array($data),
            'mtime' => $mtime ? date('c', $mtime) : null,
            'age_minutes' => $mtime ? (int)floor((time() - $mtime) / 60) : null,
            'size_bytes' => filesize($file),
            'sha256' => hash_file('sha256', $file),
            'departures' => $departures,
            'routes' => $routes,
            'dates' => $dates,
        ];
    }

    private static function runtimeInfo($baseDir)
    {
        $files = [
            'ai_debug.log' => AiRuntimeLogger::debugFile(),
            'cron_followup.log' => $baseDir . '/cron_followup.log',
            'funnel.csv' => $baseDir . '/funnel.csv',
            'metrika_events.log' => $baseDir . '/metrika_events.log',
            'metrika_offline_queue.csv' => $baseDir . '/metrika_offline_queue.csv',
            'structured_events.log' => $baseDir . '/structured_events.log',
        ];
        $out = [];
        foreach ($files as $name => $path) {
            $mtime = is_file($path) ? filemtime($path) : false;
            $out[$name] = [
                'exists' => is_file($path),
                'writable' => is_file($path) ? is_writable($path) : is_writable(dirname($path)),
                'mtime' => $mtime ? date('c', $mtime) : null,
                'age_minutes' => $mtime ? (int)floor((time() - $mtime) / 60) : null,
            ];
        }
        return $out;
    }

    private static function dedupeInfo($baseDir)
    {
        $service = $baseDir . '/services/IncomingUpdateDeduplicator.php';
        if (!is_file($service) || !is_readable($service)) {
            return ['ok'=>false, 'error'=>'deduplicator_unavailable'];
        }

        require_once $service;
        if (!class_exists('IncomingUpdateDeduplicator')) {
            return ['ok'=>false, 'error'=>'deduplicator_class_missing'];
        }

        $storage = sys_get_temp_dir() . '/max-search-bot-dedupe-health-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.json';
        $event = [
            'update_type' => 'message_callback',
            'callback' => ['callback_id' => 'health-' . bin2hex(random_bytes(8))],
        ];

        try {
            $first = IncomingUpdateDeduplicator::claim($event, $storage);
            $second = IncomingUpdateDeduplicator::claim($event, $storage);
            return [
                'ok' => ($first === true && $second === false),
                'first_accepted' => ($first === true),
                'duplicate_rejected' => ($second === false),
                'storage_created' => is_file($storage),
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'error' => get_class($e) . ': ' . $e->getMessage(),
            ];
        } finally {
            @unlink($storage);
        }
    }
}
