<?php
declare(strict_types=1);
require_once __DIR__ . '/Max2ValueLookupRepositoryV2.php';

/** Fixed existing key/settings paths; no new credential, Bitrix bootstrap or financial capability. */
final class Max2ValueLookupRuntimeV2
{
    public const SITE = '/var/www/www-root/data/www/tour-max.ru';
    public const SIDECAR = self::SITE . '/new/max2-value-attribution';
    public const VERIFICATION_FILE = self::SITE . '/new/max-attribution/verification.pem';
    public const SETTINGS_FILE = self::SITE . '/bitrix/.settings.php';
    public const PUBLIC_KEY_DER_SHA256 = 'b5a8227d84674149ae1384772bc3ce2e153ed67de1b9e19d6721965dc8203702';
    // Sealed runtime admission, independently of the same digest inside an incoming request.
    public const ADMITTED_PRODUCER_CONTRACT = '3a563c1b7921915fd8b17c08f585cde88127904170a086c291a5677d49cf365e';

    private static function exactRegularFile(string $path, int $maximum): array
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000 || is_link($path)
            || realpath($path) !== $path || !is_readable($path)
            || $stat['size'] < 1 || $stat['size'] > $maximum || ($stat['mode'] & 0022) !== 0) {
            throw new RuntimeException('runtime_dependency_unavailable');
        }
        return $stat;
    }
    private static function sameFile(array $before, array $after): bool
    {
        foreach (['dev', 'ino', 'mode', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $key) {
            if (($before[$key] ?? null) !== ($after[$key] ?? null)) return false;
        }
        return true;
    }
    private static function deployment(): void
    {
        if (realpath(dirname(__DIR__)) !== self::SIDECAR
            || Max2ValueLookupProtocolV2::PRODUCER_CONTRACT_SHA256 !== self::ADMITTED_PRODUCER_CONTRACT) {
            throw new RuntimeException('sidecar_admission_unavailable');
        }
    }
    /** Expected pin is trusted code/test input, never supplied by HTTP. */
    public static function validatePublicKey(string $pem, string $expectedPin = self::PUBLIC_KEY_DER_SHA256): string
    {
        if (strlen($pem) < 100 || strlen($pem) > 16384 || strpos($pem, '-----BEGIN PUBLIC KEY-----') !== 0
            || strpos($pem, 'PRIVATE') !== false || preg_match('/\A[a-f0-9]{64}\z/D', $expectedPin) !== 1) {
            throw new RuntimeException('verification_key_unavailable');
        }
        $key = @openssl_pkey_get_public($pem); $details = $key ? openssl_pkey_get_details($key) : false;
        if (!is_array($details) || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 2048) {
            throw new RuntimeException('verification_key_unavailable');
        }
        $der = base64_decode(preg_replace('/-----BEGIN PUBLIC KEY-----|-----END PUBLIC KEY-----|\s/', '', $details['key']), true);
        if (!is_string($der) || !hash_equals($expectedPin, hash('sha256', $der))) {
            throw new RuntimeException('verification_key_unavailable');
        }
        return $pem;
    }
    public static function publicKey(): string
    {
        self::deployment();
        $before = self::exactRegularFile(self::VERIFICATION_FILE, 16384);
        $pem = @file_get_contents(self::VERIFICATION_FILE, false, null, 0, 16385);
        if (!is_string($pem) || !self::sameFile($before, self::exactRegularFile(self::VERIFICATION_FILE, 16384))) {
            throw new RuntimeException('verification_key_unavailable');
        }
        return self::validatePublicKey($pem);
    }
    /** Pure validation of the already loaded, existing Bitrix connection; never log this result. */
    public static function connectionArguments(array $settings): array
    {
        $database = $settings['connections']['value']['default'] ?? null;
        if (!is_array($database)) throw new RuntimeException('storage_unavailable');
        $host = $database['host'] ?? 'localhost'; $name = $database['database'] ?? null;
        $login = $database['login'] ?? null; $password = $database['password'] ?? null;
        if (!is_string($host) || $host === '' || strlen($host) > 255 || !is_string($name) || $name === ''
            || strlen($name) > 128 || strpbrk($host . $name, ";\r\n\0") !== false
            || !is_string($login) || $login === '' || strlen($login) > 255 || !is_string($password) || strlen($password) > 4096) {
            throw new RuntimeException('storage_unavailable');
        }
        return ['dsn' => 'mysql:host=' . $host . ';dbname=' . $name . ';charset=utf8mb4',
            'login' => $login, 'password' => $password];
    }
    private static function connection(): PDO
    {
        self::deployment();
        $before = self::exactRegularFile(self::SETTINGS_FILE, 262144);
        $level = ob_get_level(); ob_start();
        try { $settings = require self::SETTINGS_FILE; }
        finally { while (ob_get_level() > $level) ob_end_clean(); }
        if (!is_array($settings) || !self::sameFile($before, self::exactRegularFile(self::SETTINGS_FILE, 262144))) {
            throw new RuntimeException('storage_unavailable');
        }
        $args = self::connectionArguments($settings); unset($settings);
        try {
            return new PDO($args['dsn'], $args['login'], $args['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 5, PDO::ATTR_PERSISTENT => false]);
        } finally { unset($args); }
    }
    /** Called only by the HTTP kernel after the NEW signature and canonical request validate. */
    public static function read(string $authenticatedBody, int $now): array
    {
        $repository = new Max2ValueLookupRepositoryV2(self::connection(), self::ADMITTED_PRODUCER_CONTRACT);
        return $repository->read($authenticatedBody, $now);
    }
}
