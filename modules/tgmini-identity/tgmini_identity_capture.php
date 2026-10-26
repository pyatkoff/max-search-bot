<?php
/** Private observations of Mini App entries. No goals, uploads or bot calls. PHP 7.4+. */
final class TgminiIdentityCapture
{
    const BUILD = 'anytour-tgmini-20261026-2';
    const TELEGRAM_PUBLIC_KEY = 'e7bf03a2fa4602af4580703d88dda5bb59f32ed8b02a56c187fe7d34caed242d';
    // Public bot ID is filled from the supplied webhook; no bot token is required.
    const BOT_ID = '7931041058';
    private $options;

    public function __construct(array $options = []) { $this->options = $options; }

    private function decimal($value, $length)
    {
        if (!is_string($value) && !is_int($value)) return '';
        $value = (string)$value;
        return strlen($value) <= $length && preg_match('/\A[1-9][0-9]*\z/D', $value) ? $value : '';
    }

    /** An unsigned/expired ID is retained only as unverified, never as a trusted join. */
    public function identity($raw, $postedId, $now = null)
    {
        $now = $now === null ? time() : $now;
        $id = $this->decimal($postedId, 32);
        $data = [];
        $valid = is_string($raw) && strlen($raw) <= 16384;
        if ($valid && $raw !== '') {
            foreach (explode('&', $raw) as $part) {
                $pair = explode('=', $part, 2);
                $key = urldecode($pair[0]);
                if (count($pair) !== 2 || !preg_match('/\A[a-zA-Z0-9_]+\z/D', $key) || array_key_exists($key, $data) || count($data) >= 32) {
                    $valid = false;
                    break;
                }
                $data[$key] = urldecode($pair[1]);
            }
        }
        $signedId = '';
        if ($valid && isset($data['user'])) {
            $user = json_decode($data['user'], true, 32, JSON_BIGINT_AS_STRING);
            if (is_array($user)) $signedId = $this->decimal($user['id'] ?? '', 32);
            if ($signedId !== '') $id = $signedId;
        }
        $result = ['external_user_id' => $id, 'identity_status' => $id === '' ? 'missing' : 'unverified'];
        $date = $data['auth_date'] ?? '';
        if (!$valid || $signedId === '' || !preg_match('/\A[1-9][0-9]{8,11}\z/D', $date)
            || (int)$date < $now - 3600 || (int)$date > $now + 60
            || !function_exists('sodium_crypto_sign_verify_detached')) return $result;
        $encoded = $data['signature'] ?? '';
        if (!preg_match('/\A[A-Za-z0-9_-]{86}={0,2}\z/D', $encoded)) return $result;
        $signature = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($signature === false || strlen($signature) !== 64) return $result;
        unset($data['hash'], $data['signature']);
        ksort($data, SORT_STRING);
        $parts = [];
        foreach ($data as $key => $value) $parts[] = $key . '=' . $value;
        $botId = $this->options['bot_id'] ?? self::BOT_ID;
        $publicKey = @hex2bin($this->options['telegram_public_key'] ?? self::TELEGRAM_PUBLIC_KEY);
        if ($publicKey === false || strlen($publicKey) !== 32) return $result;
        try {
            if (sodium_crypto_sign_verify_detached($signature, $botId . ":WebAppData\n" . implode("\n", $parts), $publicKey)) {
                $result['external_user_id'] = $signedId;
                $result['identity_status'] = 'verified';
            }
        } catch (Throwable $e) { /* Do not promote an unverifiable identity. */ }
        return $result;
    }

    private function storageDirectory()
    {
        $root = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
        if (!$root || $root === DIRECTORY_SEPARATOR) throw new RuntimeException('private_storage_unavailable');
        $dir = $this->options['storage_dir'] ?? (defined('TGMINI_IDENTITY_DATA_DIR') ? TGMINI_IDENTITY_DATA_DIR : '');
        if ($dir === '') {
            // Common Bitrix hosting: .../data/www/site or .../public_html.
            $base = dirname($root);
            if (in_array(basename($base), ['www', 'htdocs', 'httpdocs', 'public_html'], true)) $base = dirname($base);
            $dir = $base . '/.tgmini-identity-anytour';
        }
        if (!is_string($dir) || $dir === '' || $dir[0] !== '/' || strpos($dir, "\0") !== false) throw new RuntimeException('private_storage_unavailable');
        // Resolve the existing parent first; never create storage inside the document root.
        $parent = realpath(dirname($dir));
        if (!$parent || $parent === $root || strpos($parent . '/', $root . '/') === 0 || is_link($dir)) throw new RuntimeException('private_storage_unavailable');
        if (!is_dir($dir) && !@mkdir($dir, 0700)) throw new RuntimeException('private_storage_unavailable');
        $real = realpath($dir);
        if (!$real || $real === $root || strpos($real . '/', $root . '/') === 0 || !@chmod($real, 0700) || !is_writable($real)) throw new RuntimeException('private_storage_unavailable');
        return $real;
    }

    /** Called only by the authenticated feed; never returned by public health. */
    public function privateJournalPathForTransfer()
    {
        return $this->storageDirectory() . '/entries-v1.jsonl';
    }

    private function openJournal($dir)
    {
        $path = $dir . '/entries-v1.jsonl';
        if (is_link($path)) throw new RuntimeException('private_storage_unavailable');
        $handle = @fopen($path, 'c+b');
        if (!$handle) throw new RuntimeException('private_storage_unavailable');
        $stat = fstat($handle);
        if (!$stat || ($stat['mode'] & 0170000) !== 0100000 || !@chmod($path, 0600)) {
            fclose($handle);
            throw new RuntimeException('private_storage_unavailable');
        }
        return $handle;
    }

    public function capture(array $input, $counterId, array $identity)
    {
        $handle = null;
        $locked = false;
        try {
            $counter = $this->decimal($counterId, 20);
            if ($counter === '') throw new RuntimeException('invalid_counter');
            $handle = $this->openJournal($this->storageDirectory());
            $deadline = microtime(true) + 0.15;
            do {
                $locked = flock($handle, LOCK_EX | LOCK_NB);
                if (!$locked) usleep(10000);
            } while (!$locked && microtime(true) < $deadline);
            if (!$locked) throw new RuntimeException('journal_busy');
            if (fseek($handle, 0, SEEK_END) !== 0) throw new RuntimeException('journal_write_failed');
            $offset = ftell($handle);
            if ($offset === false) throw new RuntimeException('journal_write_failed');
            $limit = $this->options['max_bytes'] ?? 536870912;
            if ($offset > 0) {
                fseek($handle, -1, SEEK_END);
                if (fread($handle, 1) !== "\n") throw new RuntimeException('journal_incomplete');
                fseek($handle, 0, SEEK_END);
            }
            $now = time();
            $row = [
                'source_cursor' => (string)($offset + 1),
                'visit_id' => 'tg-' . bin2hex(random_bytes(16)),
                'visit_at_unix' => $now, 'observed_at_unix' => $now,
                'external_user_id' => $identity['external_user_id'],
                'identity_status' => $identity['identity_status'],
                'client_id' => $this->decimal($input['clientId'] ?? '', 40),
                'yclid' => $this->decimal($input['yclid'] ?? '', 40),
                'campaign_id' => $this->decimal($input['campaign_id'] ?? '', 20),
                'region_id' => $this->decimal($input['region'] ?? '', 20),
            ];
            $record = ['schema_version' => 1, 'project_key' => 'anytour', 'source_key' => 'tgmini', 'platform' => 'telegram', 'counter_id' => $counter, 'observation' => $row];
            $line = json_encode($record, JSON_UNESCAPED_SLASHES);
            if ($line === false) throw new RuntimeException('journal_write_failed');
            $line .= "\n";
            if ($offset + strlen($line) > $limit) throw new RuntimeException('journal_full');
            $written = 0;
            while ($written < strlen($line)) {
                $n = @fwrite($handle, substr($line, $written));
                if ($n === false || $n === 0) break;
                $written += $n;
            }
            if ($written !== strlen($line) || !fflush($handle)) {
                @ftruncate($handle, $offset);
                @fflush($handle);
                throw new RuntimeException('journal_write_failed');
            }
            return ['status' => 'saved', 'identity_status' => $row['identity_status'], 'client_id_present' => $row['client_id'] !== ''];
        } catch (Throwable $e) {
            $allowed = ['private_storage_unavailable','invalid_counter','journal_busy','journal_write_failed','journal_incomplete','journal_full'];
            $error = in_array($e->getMessage(), $allowed, true) ? $e->getMessage() : 'capture_failed';
            return ['status' => $error];
        } finally {
            if (is_resource($handle)) { if ($locked) flock($handle, LOCK_UN); fclose($handle); }
        }
    }

    /** Public readiness only: never returns raw IDs, paths or records. */
    public function health()
    {
        $result = ['ok' => false, 'build' => self::BUILD, 'storage' => 'private_storage_unavailable', 'telegram_signature' => function_exists('sodium_crypto_sign_verify_detached') ? 'available' : 'unavailable', 'observations_present' => false, 'last_capture' => null];
        $handle = null;
        try {
            $dir = $this->storageDirectory();
            $path = $dir . '/entries-v1.jsonl';
            if (is_link($path)) throw new RuntimeException('private_storage_unavailable');
            if (is_file($path)) {
                if (!is_writable($path)) throw new RuntimeException('private_storage_unavailable');
                $handle = @fopen($path, 'rb');
                if (!$handle || !flock($handle, LOCK_SH | LOCK_NB)) throw new RuntimeException('journal_busy');
                $stat = fstat($handle);
                $size = $stat['size'];
                if ($size >= ($this->options['max_bytes'] ?? 536870912)) throw new RuntimeException('journal_full');
                if ($size > 0) {
                    fseek($handle, -min(4096, $size), SEEK_END);
                    $tail = stream_get_contents($handle);
                    if (substr($tail, -1) !== "\n") throw new RuntimeException('journal_incomplete');
                    $lines = explode("\n", rtrim($tail, "\n"));
                    $record = json_decode(end($lines), true);
                    if (!is_array($record) || !isset($record['observation']['observed_at_unix'])) throw new RuntimeException('journal_incomplete');
                    $row = $record['observation'];
                    $result['observations_present'] = true;
                    $result['last_capture'] = ['age_seconds' => max(0, time() - $row['observed_at_unix']), 'identity_status' => $row['identity_status'], 'client_id_present' => $row['client_id'] !== '', 'yclid_present' => $row['yclid'] !== ''];
                }
            }
            $result['ok'] = true;
            $result['storage'] = 'ready';
        } catch (Throwable $e) {
            $allowed = ['private_storage_unavailable','journal_busy','journal_full','journal_incomplete'];
            $result['storage'] = in_array($e->getMessage(), $allowed, true) ? $e->getMessage() : 'private_storage_unavailable';
        } finally {
            if (is_resource($handle)) { flock($handle, LOCK_UN); fclose($handle); }
        }
        return $result;
    }
}
