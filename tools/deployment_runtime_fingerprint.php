<?php
declare(strict_types=1);

/**
 * Bounded, read-only evidence for the existing deployment owner.
 * No config execution, network, database, locks, file writes or secret creation.
 * This proves deployment-owned inputs, not arbitrary server/FPM attestation.
 * A known result is evidence only: the caller still owns provenance and gates.
 */
final class DeploymentFingerprintFailure extends RuntimeException
{
    public function __construct(public readonly string $status, string $reason)
    {
        parent::__construct($reason);
    }
}

final class DeploymentStaticConfig
{
    private array $values = [];
    private array $files = [];
    private array $loading = [];
    private int $bytes = 0;

    public function __construct(private readonly Closure $read, private readonly array $roots) {}

    private static function unsupported(): never
    {
        throw new DeploymentFingerprintFailure('unknown', 'configuration_loader_unsupported');
    }

    public function load(string $file): array
    {
        $this->includeFile($file, true, 0);
        ksort($this->files, SORT_STRING);
        ksort($this->values, SORT_STRING);
        return ['files' => $this->files, 'constants' => $this->values];
    }

    private function includeFile(string $file, bool $once, int $depth): void
    {
        if ($depth > 8 || !str_starts_with($file, '/') || str_contains($file, "\0")) self::unsupported();
        // Includes must be literal, canonical, and within the explicit app/config roots.
        $allowed = false;
        foreach ($this->roots as $root) if (str_starts_with($file, $root . '/')) $allowed = true;
        if (!$allowed || str_contains($file, '/../') || str_contains($file, '/./')) self::unsupported();
        if (isset($this->loading[$file])) self::unsupported();
        if ($once && isset($this->files[$file])) return;
        if (count($this->files) >= 16) self::unsupported();
        $raw = ($this->read)($file, 262144);
        if (($this->bytes += strlen($raw)) > 1048576) self::unsupported();
        $this->files[$file] = hash('sha256', $raw);
        $this->loading[$file] = true;
        try { $source = token_get_all($raw, TOKEN_PARSE); }
        catch (Throwable $ignored) { self::unsupported(); }
        $tokens = [];
        if (($source[0][0] ?? null) !== T_OPEN_TAG) self::unsupported();
        foreach ($source as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_CLOSE_TAG], true)) continue;
                if ($token[0] === T_INLINE_HTML) { if (trim($token[1]) !== '') self::unsupported(); continue; }
                $tokens[] = [$token[0], $token[1]];
            } else $tokens[] = [0, $token];
        }
        $at = 0;
        while ($at < count($tokens)) $this->statement($tokens, $at, $file, $depth, true);
        unset($this->loading[$file]);
    }

    private static function take(array $tokens, int &$at, string $text): void
    {
        if (($tokens[$at][1] ?? null) !== $text) self::unsupported();
        $at++;
    }

    private function statement(array $tokens, int &$at, string $file, int $depth, bool $active): void
    {
        $token = $tokens[$at] ?? [null, null];
        if ($token[1] === ';') { $at++; return; }
        if ($token[0] === T_DECLARE) {
            $at++; self::take($tokens, $at, '('); self::take($tokens, $at, 'strict_types');
            self::take($tokens, $at, '='); self::take($tokens, $at, '1');
            self::take($tokens, $at, ')'); self::take($tokens, $at, ';'); return;
        }
        if ($token[0] === T_IF) {
            $at++; self::take($tokens, $at, '(');
            $negated = ($tokens[$at][1] ?? null) === '!';
            if ($negated) $at++;
            self::take($tokens, $at, 'defined'); self::take($tokens, $at, '(');
            $name = $this->expression($tokens, $at, $file);
            // Only constants established by this static graph can select a
            // branch. A predefined/external constant belongs to an unproved
            // runtime context; silently treating it as absent would omit inputs.
            if (!is_string($name) || !preg_match('/\A[A-Z][A-Z0-9_]{0,127}\z/D', $name) || defined($name)) self::unsupported();
            self::take($tokens, $at, ')'); self::take($tokens, $at, ')');
            $condition = array_key_exists($name, $this->values);
            $condition = $negated ? !$condition : $condition;
            if (($tokens[$at][1] ?? null) === '{') {
                $at++;
                while (($tokens[$at][1] ?? null) !== '}') {
                    if ($at >= count($tokens)) self::unsupported();
                    $this->statement($tokens, $at, $file, $depth, $active && $condition);
                }
                $at++;
            } else $this->statement($tokens, $at, $file, $depth, $active && $condition);
            return;
        }
        if (in_array($token[0], [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE], true)) {
            $at++;
            $target = $this->expression($tokens, $at, $file);
            self::take($tokens, $at, ';');
            if (!is_string($target)) self::unsupported();
            if ($active) $this->includeFile($target, in_array($token[0], [T_REQUIRE_ONCE, T_INCLUDE_ONCE], true), $depth + 1);
            return;
        }
        if ($token[0] === T_CONST) {
            $at++; $name = $tokens[$at++][1] ?? null;
            self::take($tokens, $at, '=');
            $value = $this->expression($tokens, $at, $file);
            self::take($tokens, $at, ';');
            $this->define($name, $value, $active); return;
        }
        if ($token[0] === T_STRING && strtolower((string)$token[1]) === 'define') {
            $at++; self::take($tokens, $at, '(');
            $name = $this->expression($tokens, $at, $file);
            self::take($tokens, $at, ',');
            $value = $this->expression($tokens, $at, $file);
            self::take($tokens, $at, ')'); self::take($tokens, $at, ';');
            $this->define($name, $value, $active); return;
        }
        // No eval/require fallback: calls, assignments, environment loaders and
        // arbitrary conditionals remain explicitly unsupported.
        self::unsupported();
    }

    private function define(mixed $name, mixed $value, bool $active): void
    {
        if (!is_string($name) || !preg_match('/\A[A-Z][A-Z0-9_]{0,127}\z/D', $name) || defined($name)) self::unsupported();
        if (!$active) return;
        if (array_key_exists($name, $this->values)) self::unsupported();
        if (count($this->values) >= 512) self::unsupported();
        $this->values[$name] = $value;
    }

    private function expression(array $tokens, int &$at, string $file): mixed
    {
        $value = $this->atom($tokens, $at, $file);
        while (($tokens[$at][1] ?? null) === '.') {
            $at++; $next = $this->atom($tokens, $at, $file);
            if (!is_string($value) || !is_string($next)) self::unsupported();
            $value .= $next;
        }
        if (is_string($value) && strlen($value) > 65536) self::unsupported();
        return $value;
    }

    private function atom(array $tokens, int &$at, string $file): mixed
    {
        [$id, $text] = $tokens[$at++] ?? [null, null];
        if ($text === '(') {
            $value = $this->expression($tokens, $at, $file);
            self::take($tokens, $at, ')'); return $value;
        }
        if ($id === T_CONSTANT_ENCAPSED_STRING) return self::literal($text);
        if ($id === T_DIR) return dirname($file);
        if ($id === T_FILE) return $file;
        if ($id === T_LNUMBER && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $text) && strlen($text) < 19) return (int)$text;
        if ($id === T_DNUMBER && is_finite((float)$text)) return (float)$text;
        if ($text === '-') {
            $value = $this->atom($tokens, $at, $file);
            if (!is_int($value) && !is_float($value)) self::unsupported();
            return -$value;
        }
        if ($id === T_STRING) {
            $lower = strtolower($text);
            if (in_array($lower, ['true', 'false', 'null'], true)) return match ($lower) { 'true' => true, 'false' => false, default => null };
            if ($lower === 'dirname') {
                self::take($tokens, $at, '(');
                $path = $this->expression($tokens, $at, $file); $levels = 1;
                if (($tokens[$at][1] ?? null) === ',') { $at++; $levels = $this->expression($tokens, $at, $file); }
                self::take($tokens, $at, ')');
                if (!is_string($path) || !is_int($levels) || $levels < 1 || $levels > 8) self::unsupported();
                return dirname($path, $levels);
            }
            if (array_key_exists($text, $this->values)) return $this->values[$text];
        }
        self::unsupported();
    }

    private static function literal(string $quoted): string
    {
        $quote = $quoted[0]; $body = substr($quoted, 1, -1); $value = '';
        for ($i = 0, $n = strlen($body); $i < $n; $i++) {
            if ($body[$i] !== '\\' || $i + 1 === $n) { $value .= $body[$i]; continue; }
            $next = $body[++$i];
            if ($quote === "'") { $value .= in_array($next, ['\\', "'"], true) ? $next : '\\' . $next; continue; }
            $escapes = ['n' => "\n", 'r' => "\r", 't' => "\t", 'v' => "\v", 'e' => "\x1b", 'f' => "\f", '\\' => '\\', '$' => '$', '"' => '"'];
            if (isset($escapes[$next])) $value .= $escapes[$next];
            elseif ($next === 'x' && isset($body[$i + 1]) && ctype_xdigit($body[$i + 1])) {
                $hex = $body[++$i];
                if (isset($body[$i + 1]) && ctype_xdigit($body[$i + 1])) $hex .= $body[++$i];
                $value .= chr(hexdec($hex));
            } elseif ($next >= '0' && $next <= '7') {
                $octal = $next;
                for ($j = 0; $j < 2 && isset($body[$i + 1]) && $body[$i + 1] >= '0' && $body[$i + 1] <= '7'; $j++) $octal .= $body[++$i];
                $value .= chr(octdec($octal) & 255);
            } elseif ($next === 'u') self::unsupported();
            else $value .= '\\' . $next;
        }
        return $value;
    }
}

final class DeploymentRuntimeFingerprint
{
    public const PROTOCOL = 'anytour-deployment-runtime-v1';
    private const DEVELOPMENT_ONLY = ['tests/run_purchase_study_runtime.py'];
    private array $observed = [];
    private array $absent = [];
    private array $links = [];
    private array $directories = [];
    private array $directoryMetadata = [];
    private int $totalBytes = 0;

    public static function encoded(array $value): string
    {
        $sort = static function (array $items) use (&$sort): array {
            if (!array_is_list($items)) ksort($items, SORT_STRING);
            foreach ($items as $key => $item) if (is_array($item)) $items[$key] = $sort($item);
            return $items;
        };
        return json_encode($sort($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . "\n";
    }

    private static function fail(string $reason, string $status = 'unknown'): never
    {
        throw new DeploymentFingerprintFailure($status, $reason);
    }

    private static function directory(string $path): string
    {
        if ($path === '' || $path[0] !== '/' || is_link($path) || !is_dir($path) || realpath($path) !== $path) self::fail('input_directory_unconfirmed');
        return $path;
    }

    private function inspect(string $path, int $limit, bool $retain): array
    {
        clearstatcache(true, $path);
        $before = @lstat($path);
        if (!$before) self::fail('input_file_unavailable');
        if (($before['mode'] & 0170000) !== 0100000 || realpath($path) !== $path) self::fail('input_file_unsafe', 'blocked');
        if ($before['size'] < 0 || $before['size'] > $limit) self::fail('input_size_limit');
        $handle = @fopen($path, 'rb');
        if (!$handle) self::fail('input_file_unavailable');
        $sha256 = hash_init('sha256'); $blob = hash_init('sha1');
        hash_update($blob, 'blob ' . $before['size'] . "\0");
        $bytes = ''; $size = 0;
        try {
            $opened = fstat($handle);
            if (!$opened || $opened['ino'] !== $before['ino'] || $opened['dev'] !== $before['dev']) self::fail('input_changed');
            while (!feof($handle)) {
                $chunk = fread($handle, 65536);
                if ($chunk === false || ($chunk === '' && !feof($handle))) self::fail('input_file_unavailable');
                $size += strlen($chunk);
                if ($size > $limit) self::fail('input_size_limit');
                hash_update($sha256, $chunk); hash_update($blob, $chunk);
                if ($retain) $bytes .= $chunk;
            }
            $after = fstat($handle);
        } finally { fclose($handle); }
        clearstatcache(true, $path); $named = @lstat($path);
        foreach (['dev', 'ino', 'mode', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $field) {
            if (!$after || !$named || $before[$field] !== $after[$field] || $before[$field] !== $named[$field]) self::fail('input_changed');
        }
        if ($size !== $before['size']) self::fail('input_changed');
        return ['sha256' => hash_final($sha256), 'blob' => hash_final($blob), 'mode' => $before['mode'] & 0777,
            'owner' => $before['uid'], 'group' => $before['gid'], 'bytes' => $size, 'content' => $bytes];
    }

    private function file(string $path, int $limit = 1048576, bool $retain = true): array
    {
        $value = $this->inspect($path, $limit, $retain);
        if (($this->totalBytes += $value['bytes']) > 536870912) self::fail('input_size_limit');
        $identity = array_diff_key($value, ['content' => true]);
        if (isset($this->observed[$path]) && $this->observed[$path] !== $identity) self::fail('input_changed');
        $this->observed[$path] = $identity;
        return $value;
    }

    private function read(string $path, int $limit = 1048576): string { return $this->file($path, $limit)['content']; }

    private function json(string $path, int $limit = 32768): array
    {
        $value = json_decode($this->read($path, $limit), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($value) || array_is_list($value)) self::fail('receipt_invalid', 'blocked');
        return $value;
    }

    private function missing(string $path): bool
    {
        clearstatcache(true, $path);
        if (file_exists($path) || is_link($path)) return false;
        // An unreadable existing ancestor is not proof of absence.
        $parent = dirname($path);
        while (!file_exists($parent) && !is_link($parent) && $parent !== '/') $parent = dirname($parent);
        if (is_link($parent) || realpath($parent) !== $parent || !is_dir($parent) || !is_readable($parent) || !is_executable($parent)) self::fail('input_absence_unconfirmed');
        $this->absent[$path] = true;
        return true;
    }

    private function names(string $directory): array
    {
        self::directory($directory);
        $names = @scandir($directory);
        if ($names === false || count($names) > 1026) self::fail('directory_unconfirmed');
        $names = array_values(array_diff($names, ['.', '..'])); sort($names, SORT_STRING);
        if (isset($this->directories[$directory]) && $this->directories[$directory] !== $names) self::fail('input_changed');
        $this->directories[$directory] = $names;
        return $names;
    }

    private function link(string $path): string
    {
        clearstatcache(true, $path);
        if (!is_link($path)) self::fail('component_pointer_unconfirmed', 'blocked');
        $target = readlink($path);
        if (!is_string($target)) self::fail('component_pointer_unconfirmed', 'blocked');
        if (isset($this->links[$path]) && $this->links[$path] !== $target) self::fail('input_changed');
        $this->links[$path] = $target;
        return $target;
    }

    private function recheck(): void
    {
        foreach ($this->observed as $path => $before) {
            $after = $this->inspect($path, max($before['bytes'], 1), false);
            if ($before !== array_diff_key($after, ['content' => true])) self::fail('input_changed');
        }
        foreach ($this->absent as $path => $_) if (!$this->missing($path)) self::fail('input_changed');
        foreach ($this->links as $path => $target) if ($this->link($path) !== $target) self::fail('input_changed');
        foreach ($this->directories as $path => $names) if ($this->names($path) !== $names) self::fail('input_changed');
        foreach ($this->directoryMetadata as $path => $before) {
            clearstatcache(true, $path); $stat = @lstat($path);
            if (!$stat || [$stat['mode'], $stat['uid'], $stat['gid']] !== $before || realpath($path) !== $path) self::fail('input_changed');
        }
    }

    private static function git(string $root, array $arguments, int $limit = 16777216): string
    {
        if (!function_exists('proc_open')) self::fail('git_unavailable');
        $process = @proc_open(['git', '--no-optional-locks', '--no-replace-objects', '-C', $root, ...$arguments],
            [['pipe', 'r'], ['pipe', 'w'], ['file', '/dev/null', 'w']], $pipes, null,
            ['PATH' => '/usr/bin:/bin', 'LANG' => 'C', 'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_OPTIONAL_LOCKS' => '0', 'GIT_TERMINAL_PROMPT' => '0']);
        if (!is_resource($process)) self::fail('git_unavailable');
        fclose($pipes[0]); stream_set_blocking($pipes[1], false); $output = ''; $deadline = microtime(true) + 20;
        try {
            while (!feof($pipes[1])) {
                if (microtime(true) > $deadline) self::fail('git_read_unavailable');
                $read = [$pipes[1]]; $write = null; $except = null;
                if (stream_select($read, $write, $except, 0, 200000) === false) self::fail('git_read_unavailable');
                if (!$read) continue;
                $chunk = fread($pipes[1], 65536);
                if ($chunk === false) self::fail('git_read_unavailable');
                $output .= $chunk;
                if (strlen($output) > $limit) self::fail('git_read_limit');
            }
            fclose($pipes[1]); $code = proc_close($process); $process = null;
            if ($code !== 0) self::fail('git_read_unavailable');
            return $output;
        } finally {
            if (is_resource($process)) { proc_terminate($process); if (is_resource($pipes[1])) fclose($pipes[1]); proc_close($process); }
        }
    }

    private function tracked(string $root, string $sha): array
    {
        $rows = explode("\0", self::git($root, ['ls-tree', '-r', '-z', '--full-tree', $sha]));
        if (count($rows) > 50001) self::fail('tracked_input_limit');
        $inputs = [];
        foreach ($rows as $row) {
            if ($row === '') continue;
            if (!preg_match('/\A(100644|100755) blob ([a-f0-9]{40})\t(.+)\z/sD', $row, $match)) self::fail('tracked_type_unsupported');
            $name = $match[3];
            if ($name === '' || $name[0] === '/' || preg_match('~(?:^|/)\.\.(?:/|$)|[\x00-\x1f\\\\]~', $name)) self::fail('tracked_path_unsupported');
            $file = $this->file($root . '/' . $name, 134217728, false);
            $mode = ($file['mode'] & 0100) !== 0 ? '100755' : '100644';
            if ($file['blob'] !== $match[2] || $mode !== $match[1]) self::fail('tracked_application_drift', 'blocked');
            if (!in_array($name, self::DEVELOPMENT_ONLY, true)) $inputs[$name] = ['mode' => $mode, 'sha256' => $file['sha256']];
        }
        if (!$inputs) self::fail('tracked_application_unconfirmed');
        ksort($inputs, SORT_STRING); return $inputs;
    }

    private function privateMetadata(string $path): array
    {
        $parent = self::directory(dirname($path));
        clearstatcache(true, $parent); $stat = @lstat($parent);
        if (!$stat) self::fail('configuration_directory_unconfirmed');
        $identity = [$stat['mode'], $stat['uid'], $stat['gid']];
        if (isset($this->directoryMetadata[$parent]) && $this->directoryMetadata[$parent] !== $identity) self::fail('input_changed');
        $this->directoryMetadata[$parent] = $identity;
        $file = $this->observed[$path];
        return ['mode' => $file['mode'], 'owner' => $file['owner'], 'group' => $file['group'],
            'directory_mode' => $stat['mode'] & 0777, 'directory_owner' => $stat['uid'], 'directory_group' => $stat['gid']];
    }

    private function optionalFile(string $path, int $limit = 65536): ?array
    {
        if ($this->missing($path)) return null;
        $file = $this->file($path, $limit, false);
        return ['sha256' => $file['sha256'], 'metadata' => $this->privateMetadata($path)];
    }

    private function configuration(string $root, array $options): array
    {
        $configRoot = self::directory($options['private-config-root'] ?? '');
        $config = $options['config'] ?? '';
        if ($config !== $root . '/config.php') self::fail('configuration_entry_unconfirmed');
        $parser = new DeploymentStaticConfig(fn(string $path, int $limit): string => $this->read($path, $limit), [$root, $configRoot]);
        $projection = $parser->load($config);
        $values = $projection['constants'];
        foreach ($projection['files'] as $path => $_) $projection['file_metadata'][$path] = $this->privateMetadata($path);
        // Legacy bootstraps can load configuration outside this bounded graph.
        if (($values['MAX_SEARCH_STANDALONE_RUNTIME'] ?? null) !== true) self::fail('configuration_runtime_unsupported');
        // Matches the existing LeadBridgeConfig signing source, without executing it.
        $secret = $values['MAX_SEARCH_LEAD_BRIDGE_SECRET'] ?? '';
        if (!is_string($secret)) self::fail('configuration_key_unavailable');
        $secret = trim($secret);
        if ($secret === '') $secret = trim(is_string($values['MAX_SEARCH_TOKEN'] ?? null) ? $values['MAX_SEARCH_TOKEN'] : '');
        if (strlen($secret) < 32 || strlen($secret) > 8192 || count(array_unique(str_split($secret))) < 12
            || preg_match('/example|placeholder|change.?me|your.?token|test.?secret/i', $secret)) self::fail('configuration_key_unavailable');
        $key = hash_hmac('sha256', self::PROTOCOL . "\0private-core-key", $secret, true);
        $keyId = hash_hmac('sha256', self::PROTOCOL . "\0key-id", $key);
        // Deployment-owned environment only. CI run IDs/SSH session metadata are not configuration.
        $environment = [];
        foreach (getenv() as $name => $value) if (preg_match('/\A(?:MAX_SEARCH_|OPENAI_|MANAGER_AI_|TELEGRAM_|CONVERSATION_DB_|ANYTOUR_DATA_DB_|TOURVISOR_|WEBSITE_)/D', $name)) $environment[$name] = $value;
        ksort($environment, SORT_STRING);
        $projection['environment'] = $environment;
        $projection['optional_files'] = [
            'hotel' => $this->optionalFile(dirname($root) . '/max-search-bot-hotel-server/runtime.json'),
            'attribution_key' => $this->optionalFile(dirname($root, 2) . '/private/max-attribution/signing.pem'),
            'monitor_activation' => $this->optionalFile(dirname($root) . '/max-search-bot-modeled-pipeline/activation.json'),
        ];
        $archiveRoot = $values['MAX_SEARCH_TELEGRAM_ARCHIVE_PRIVATE_DIR'] ?? dirname($root) . '/max-search-bot-telegram-archive';
        if (!is_string($archiveRoot) || $archiveRoot === '' || $archiveRoot[0] !== '/') self::fail('configuration_path_unconfirmed');
        $archive = $archiveRoot . '/bot.json';
        $projection['archive'] = null;
        if (!$this->missing($archive)) {
            $value = $this->json($archive, 16384);
            if (!in_array($value['webhook_state'] ?? null, ['ready', 'prepared'], true)) self::fail('archive_configuration_unconfirmed');
            // checked_at/webhook readiness are observations, not credential changes.
            $allowed = ['username', 'token', 'bot_id', 'secret', 'can_connect_to_business', 'webhook_state', 'checked_at', 'created_at'];
            if (array_diff(array_keys($value), $allowed)) self::fail('archive_configuration_unsupported');
            foreach (['username', 'token', 'bot_id', 'secret', 'can_connect_to_business'] as $name) {
                if (!array_key_exists($name, $value)) self::fail('archive_configuration_unconfirmed');
                $projection['archive'][$name] = $value[$name];
            }
            $projection['archive']['file_metadata'] = $this->privateMetadata($archive);
        }
        $ca = trim((string)($environment['MAX_SEARCH_MAX_CA_BUNDLE'] ?? ''));
        if ($ca === '') $ca = $configRoot . '/max-ca-bundle.crt';
        if ($ca[0] !== '/') self::fail('configuration_path_unconfirmed');
        $projection['optional_files']['ca_bundle'] = $this->optionalFile($ca, 1048576);
        return [$projection, $key, $keyId];
    }

    private function cron(string $root, array $options, ?string $stdin): array
    {
        $path = $options['cron-file'] ?? '';
        if ($path === '-') {
            if ($stdin === null || strlen($stdin) > 65536 || str_contains($stdin, "\0")) self::fail('cron_snapshot_unconfirmed');
            $raw = $stdin;
        } else $raw = $this->read($path, 65536);
        $owned = []; $needles = [$root . '/cron_metrika.php', $root . '/tools/channel_post_worker.php --platform=max --run --reconcile',
            $root . '/tools/manager_prepared_replies.php tick', $root . '/tools/hotel_work.php tick', $root . '/tools/development_work.php tick'];
        $counts = array_fill(0, count($needles), 0);
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\s*=/D', $line) || str_contains($line, $root . '/')) $owned[] = $line;
            foreach ($needles as $index => $needle) if (str_contains($line, $needle)) $counts[$index]++;
        }
        if ($counts !== array_fill(0, count($needles), 1)) self::fail('owned_cron_unconfirmed', 'blocked');
        return $owned;
    }

    private function current(string $releases): string
    {
        self::directory($releases);
        if (!$this->missing($releases . '/pending.json')) self::fail('component_operation_pending', 'blocked');
        $target = $this->link($releases . '/current');
        if (!preg_match('~\A' . preg_quote($releases, '~') . '/[a-f0-9]{64}-[a-f0-9]{64}\z~D', $target) || realpath($target) !== $target) self::fail('component_pointer_unconfirmed', 'blocked');
        return $target;
    }

    private function backendInputs(string $root, array $spec, bool $public): void
    {
        $paths = $spec['backend_inputs'] ?? null;
        if (!is_array($paths) || !array_is_list($paths) || count($paths) > 256) self::fail('component_spec_unconfirmed');
        if ($public) $paths = [...$paths, ...array_values($spec['files'] ?? [])];
        foreach (array_unique($paths) as $path) {
            if (!is_string($path) || $path === '' || $path[0] === '/' || str_contains($path, '..') || str_contains($path, '\\')) self::fail('component_spec_unconfirmed');
            $this->file($root . '/' . $path, 16777216, false);
        }
    }

    private function components(string $root, array $options): array
    {
        // These modules only declare classes when included; no runtime config is loaded.
        require_once dirname(__DIR__) . '/modules/tour-selections/deploy.php';
        $site = self::directory($options['public-site'] ?? '');
        $publicRoot = $options['public-releases'] ?? '';
        $editorRoot = $options['editor-releases'] ?? '';
        $marker = $options['public-marker'] ?? '';
        $publicDir = $this->current($publicRoot); $editorDir = $this->current($editorRoot);
        $spec = $this->json($root . '/modules/tour-selections/module.json');
        $this->backendInputs($root, $spec, true);
        $fingerprint = ManagerTourSelectionPublicHost::fingerprint($root);
        $seal = $this->json($publicDir . '/deployment.json'); $release = $this->json($publicDir . '/release.json');
        $identity = $release; unset($identity['package_id']);
        $id = $release['package_id'] ?? '';
        if (!is_string($id) || !preg_match('/\A[a-f0-9]{64}\z/D', $id) || hash('sha256', ManagerTourSelectionEditorHost::encoded($identity)) !== $id
            || ($release['contract'] ?? null) !== 'selection-php-view-v1'
            || $publicDir !== $publicRoot . '/' . $id . '-' . $fingerprint
            || ($seal['contract'] ?? null) !== ManagerTourSelectionPublicHost::CONTRACT || ($seal['origin'] ?? null) !== ManagerTourSelectionPublicHost::ORIGIN
            || ($seal['fingerprint'] ?? null) !== $fingerprint || ($seal['package_id'] ?? null) !== $id
            || !is_string($seal['source'] ?? null) || !preg_match('/\A[a-f0-9]{40}\z/D', $seal['source'])
            || self::encoded($seal) !== self::encoded($this->json($marker, 4096))) self::fail('public_component_unconfirmed', 'blocked');
        $names = array_keys($release['files'] ?? []); sort($names);
        if ($names !== ['activity.js', 'selection.css', 'view.php']) self::fail('public_component_unconfirmed', 'blocked');
        foreach ($names as $name) {
            $payload = $this->file($publicDir . '/' . $name);
            $backend = $this->file($root . '/selection/' . $name);
            if (($release['files'][$name]['sha256'] ?? null) !== $payload['sha256'] || ($release['files'][$name]['bytes'] ?? null) !== $payload['bytes'] || $backend['sha256'] !== $payload['sha256']) self::fail('public_component_unconfirmed', 'blocked');
        }
        $host = new TourSelectionHostDeployment($root, $site, $publicRoot, $marker);
        foreach (['index.php', 'photo.php', 'activity.php'] as $name) if ($this->read($site . '/' . $name) !== $host->front($name)) self::fail('public_route_changed', 'blocked');
        foreach (['selection.css', 'activity.js'] as $name) if ($this->link($site . '/' . $name) !== $publicRoot . '/current/' . $name) self::fail('public_route_changed', 'blocked');
        if (array_diff($this->names($site), ['index.php', 'photo.php', 'activity.php', 'selection.css', 'activity.js', 'editor.php', '.well-known'])) self::fail('public_route_changed', 'blocked');
        $public = ['package_id' => $id, 'source_sha' => $seal['source'], 'backend_fingerprint' => $fingerprint,
            'state_digest' => hash('sha256', self::encoded(['seal' => $seal, 'release' => $release, 'routes' => 'selection-public-host-v1']))];

        $editorSpec = $this->json($root . '/modules/tour-selections/editor.json');
        $this->backendInputs($root, $editorSpec, false);
        $editorSeal = $this->json($editorDir . '/deployment.json'); $editorRelease = $this->json($editorDir . '/release.json');
        foreach (ManagerTourSelectionEditorHost::FILES as $name) $this->file($editorDir . '/' . $name);
        $installed = ManagerTourSelectionEditorHost::installed($root, $editorRoot);
        if ($installed === null || ($installed['directory'] ?? null) !== $editorDir || ManagerTourSelectionEditorHost::entry($root, $editorRoot) === null
            || self::encoded($editorSeal) !== self::encoded($this->json($editorRoot . '/active.json'))
            || self::encoded($this->json($editorDir . '/accepted.json')) !== self::encoded(['schema' => 1, 'package_id' => $installed['package_id'], 'seal_sha256' => $this->observed[$editorDir . '/deployment.json']['sha256']])
            || $this->read($site . '/editor.php') !== ManagerTourSelectionEditorHost::front($root)) self::fail('editor_component_unconfirmed', 'blocked');
        $operations = $editorRoot . '/operations'; $operationProof = null;
        if (!$this->missing($operations)) {
            $files = $this->names($operations);
            if (count($files) > 256) self::fail('editor_operation_unconfirmed', 'blocked');
            foreach ($files as $name) {
                if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{7,95}\.json\z/D', $name)) self::fail('editor_operation_unconfirmed', 'blocked');
                $operation = $this->json($operations . '/' . $name);
                if (!in_array($operation['state'] ?? null, ['activated', 'rolled_back'], true)) self::fail('editor_operation_unconfirmed', 'blocked');
                if ($name === ($editorSeal['operation'] ?? '') . '.json') $operationProof = $operation;
            }
        }
        $releaseKind = $editorSeal['release_kind'] ?? 'normal';
        if (isset($editorSeal['release_kind'])) {
            if (!in_array($editorSeal['release_kind'], ['common', 'independent'], true)
                || !is_string($editorSeal['backend_source'] ?? null) || !preg_match('/\A[a-f0-9]{40}\z/D', $editorSeal['backend_source'])
                || $operationProof !== ['state' => 'activated', 'package_id' => $installed['package_id'], 'source' => $installed['source']]) self::fail('editor_operation_unconfirmed', 'blocked');
        }
        $acceptance = 'installed';
        if ($releaseKind === 'independent') {
            $accepted = ManagerTourSelectionEditorHost::acceptedPrior($editorRoot);
            if ($accepted === null || self::encoded($accepted) !== self::encoded($installed)) self::fail('editor_independent_unconfirmed', 'blocked');
            $acceptance = 'independent_activated';
        }
        $editor = ['package_id' => $installed['package_id'], 'source_sha' => $installed['source'], 'backend_fingerprint' => $installed['fingerprint'],
            'state_digest' => hash('sha256', self::encoded(['seal' => $editorSeal, 'release' => $editorRelease, 'operation' => $operationProof])),
            'release_kind' => $releaseKind, 'acceptance' => $acceptance];
        return ['public' => $public, 'editor' => $editor];
    }

    public static function collect(array $options, ?string $cronStdin = null): array
    {
        $requestedMode = $options['mode'] ?? 'all';
        $mode = in_array($requestedMode, ['core', 'components', 'all'], true) ? $requestedMode : 'invalid';
        $sha = $options['application-sha'] ?? null;
        $result = ['schema' => 1, 'protocol' => self::PROTOCOL, 'mode' => $mode, 'status' => 'unknown', 'reason_code' => null,
            'application_sha' => is_string($sha) && preg_match('/\A[a-f0-9]{40}\z/D', $sha) ? $sha : null,
            'core_digest' => null, 'configuration_key_id' => null, 'components' => ['public' => null, 'editor' => null], 'digest' => null];
        $collector = new self();
        // Warnings must never disclose a private pathname/value to deployment logs.
        set_error_handler(static function (): never { throw new DeploymentFingerprintFailure('unknown', 'input_read_unavailable'); });
        try {
            if (!in_array($mode, ['core', 'components', 'all'], true) || $result['application_sha'] === null) self::fail('arguments_invalid');
            $root = self::directory($options['root'] ?? '');
            if (trim(self::git($root, ['rev-parse', '--verify', 'HEAD'], 128)) !== $sha) self::fail('application_sha_mismatch', 'blocked');
            if ($mode !== 'components') {
                $tracked = $collector->tracked($root, $sha);
                [$configuration, $key, $keyId] = $collector->configuration($root, $options);
                $core = ['schema' => 1, 'policy' => 'anytour-development-content-v1', 'tracked' => $tracked,
                    'configuration' => $configuration, 'owned_cron' => $collector->cron($root, $options, $cronStdin)];
                $result['core_digest'] = hash_hmac('sha256', self::PROTOCOL . "\0core\0" . self::encoded($core), $key);
                $result['configuration_key_id'] = $keyId;
            }
            if ($mode !== 'core') $result['components'] = $collector->components($root, $options);
            $collector->recheck();
            if (trim(self::git($root, ['rev-parse', '--verify', 'HEAD'], 128)) !== $sha) self::fail('application_changed', 'blocked');
            $result['digest'] = hash('sha256', self::encoded(['schema' => 1, 'application_sha' => $sha, 'core_digest' => $result['core_digest'],
                'configuration_key_id' => $result['configuration_key_id'], 'components' => $result['components']]));
            $result['status'] = 'known';
        } catch (DeploymentFingerprintFailure $error) {
            $result['status'] = $error->status; $result['reason_code'] = $error->getMessage();
        } catch (Throwable $ignored) { $result['reason_code'] = 'input_unconfirmed'; }
        finally { restore_error_handler(); }
        if ($result['status'] !== 'known') {
            $result['core_digest'] = null; $result['configuration_key_id'] = null;
            $result['components'] = ['public' => null, 'editor' => null]; $result['digest'] = null;
        }
        return $result;
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $options = []; $allowed = ['mode', 'root', 'application-sha', 'config', 'private-config-root', 'cron-file', 'public-site', 'public-releases', 'public-marker', 'editor-releases'];
    $invalid = false;
    for ($index = 1; $index < count($argv); $index++) {
        if (!preg_match('/\A--([a-z-]+)(?:=(.*))?\z/sD', $argv[$index], $match) || !in_array($match[1], $allowed, true) || isset($options[$match[1]])) { $invalid = true; break; }
        $value = $match[2] ?? ($argv[++$index] ?? null);
        if (!is_string($value) || $value === '' || str_starts_with($value, '--')) { $invalid = true; break; }
        $options[$match[1]] = $value;
    }
    if ($invalid) $options['mode'] = 'invalid';
    $stdin = ($options['cron-file'] ?? '') === '-' ? stream_get_contents(STDIN, 65537) : null;
    // Decisions (including unknown/blocked) are data. Every caller must validate status.
    echo DeploymentRuntimeFingerprint::encoded(DeploymentRuntimeFingerprint::collect($options, is_string($stdin) ? $stdin : null));
}
