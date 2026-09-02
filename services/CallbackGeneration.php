<?php

declare(strict_types=1);

/**
 * Pure callback payload generation/version codec.
 *
 * Business actions stay unaware of the transport suffix. The controller
 * validates one-shot generations through InteractionGuard and then dispatches
 * the normalized legacy payload to the existing action owners.
 */
final class CallbackGeneration
{
    private const VERSION = 'g1';
    private const TOKEN_PATTERN = '[a-f0-9]{8}';

    public static function token(): string
    {
        try {
            return bin2hex(random_bytes(4));
        } catch (Throwable $e) {
            return substr(hash('sha256', uniqid('', true)), 0, 8);
        }
    }

    public static function wrap(string $payload, string $generation): string
    {
        $payload = trim($payload);
        $generation = strtolower(trim($generation));
        if ($payload === '' || !preg_match('/^' . self::TOKEN_PATTERN . '$/', $generation)) {
            return $payload;
        }
        return self::VERSION . '_' . $generation . '_' . $payload;
    }

    public static function parse(string $payload): ?array
    {
        if (!preg_match('/^' . self::VERSION . '_(' . self::TOKEN_PATTERN . ')_(.+)$/', $payload, $m)) {
            return null;
        }
        $base = trim((string)$m[2]);
        if ($base === '') return null;
        return [
            'version' => self::VERSION,
            'generation' => (string)$m[1],
            'payload' => $base,
        ];
    }

    public static function base(string $payload): string
    {
        $parsed = self::parse($payload);
        return $parsed === null ? $payload : (string)$parsed['payload'];
    }
}
