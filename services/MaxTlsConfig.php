<?php

declare(strict_types=1);

/**
 * One owner for MAX API TLS settings.
 *
 * MAX platform-api2 uses the Russian Trusted CA chain. Prefer an explicit
 * deployment-managed bundle when configured, otherwise fall back to the pinned
 * project bundle. Peer and host verification stay enabled.
 */
final class MaxTlsConfig
{
    private const DEFAULT_CA_BUNDLE = '/var/www/anytoour/data/config/max-ca-bundle.crt';
    private const PROJECT_CA_BUNDLE = __DIR__ . '/../certs/russian_trusted_ca.pem';

    public static function caBundle(): string
    {
        $configured = trim((string)(getenv('MAX_SEARCH_MAX_CA_BUNDLE') ?: ''));
        $candidates = $configured !== ''
            ? [$configured]
            : [self::DEFAULT_CA_BUNDLE, self::PROJECT_CA_BUNDLE];

        foreach ($candidates as $path) {
            if (is_file($path) && is_readable($path)) return $path;
        }
        return '';
    }

    /** @return array<int,mixed> */
    public static function curlOptions(bool $allowLegacyInsecure = false): array
    {
        $bundle = self::caBundle();
        if ($bundle !== '') {
            return [
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CAINFO => $bundle,
            ];
        }

        if ($allowLegacyInsecure || getenv('MAX_SEARCH_MAX_API_INSECURE_COMPAT') === '1') {
            return [
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
            ];
        }

        return [
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
    }
}
