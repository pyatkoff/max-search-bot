<?php

class ProjectHealth
{
    public static function collect($baseDir)
    {
        return [
            'generated_at' => date('c'),
            'git' => self::gitInfo($baseDir),
            'php' => self::phpInfo(),
            'config' => self::configInfo($baseDir),
            'tourvisor_routes' => self::routesInfo($baseDir),
            'runtime' => self::runtimeInfo($baseDir),
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
            'ai_debug.log',
            'cron_followup.log',
            'funnel.csv',
            'metrika_events.log',
            'metrika_offline_queue.csv',
        ];
        $out = [];
        foreach ($files as $name) {
            $path = $baseDir . '/' . $name;
            $mtime = is_file($path) ? filemtime($path) : false;
            $out[$name] = [
                'exists' => is_file($path),
                'writable' => is_file($path) ? is_writable($path) : is_writable($baseDir),
                'mtime' => $mtime ? date('c', $mtime) : null,
                'age_minutes' => $mtime ? (int)floor((time() - $mtime) / 60) : null,
            ];
        }
        return $out;
    }
}
