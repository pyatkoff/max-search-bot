<?php

declare(strict_types=1);

/**
 * One owner for MAX API TLS settings.
 *
 * New hosts can use a deployment-managed CA bundle without disabling peer
 * verification. Legacy runtime remains compatible until its transport is fully
 * migrated to trusted CA verification.
 */
final class MaxTlsConfig
{
    private const DEFAULT_CA_BUNDLE = '/var/www/anytoour/data/config/max-ca-bundle.crt';

    public static function caBundle(): string
    {
        $configured = trim((string)(getenv('MAX_SEARCH_MAX_CA_BUNDLE') ?: ''));
        $path = $configured !== '' ? $configured : self::DEFAULT_CA_BUNDLE;
        return is_file($path) && is_readable($path) ? $path : '';
    }

    /** @return array<int,mixed> */
    public static function curlOptions(bool $allowLegacyInsecure = false): array
    {
        if (($allowLegacyInsecure || getenv('MAX_SEARCH_MAX_API_INSECURE_COMPAT') === '1') && self::caBundle() === '') {
            return [
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
            ];
        }

        return self::strictCurlOptions();
    }

    /**
     * Verified TLS options for preflight and migrated transports.
     *
     * This deliberately ignores the legacy insecure compatibility flag so a
     * green preflight proves that certificate verification really succeeded.
     *
     * @return array<int,mixed>
     */
    public static function strictCurlOptions(): array
    {
        $options = [
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        $bundle = self::caBundle();
        if ($bundle !== '') {
            $options[CURLOPT_CAINFO] = $bundle;
        }

        return $options;
    }
}
