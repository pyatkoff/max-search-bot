<?php

declare(strict_types=1);

/**
 * Separate connection boundary for the AnyTour hotel/catalog database.
 *
 * The bot/manager operational database stays independent from the hotel
 * catalogue. Production credentials are already provided by the external
 * config through the ANYTOUR_DATA_DB_* constants.
 */
final class HotelDatabase
{
    private static ?PDO $pdo = null;

    public static function configured(): bool
    {
        return defined('ANYTOUR_DATA_DB_HOST')
            && defined('ANYTOUR_DATA_DB_NAME')
            && defined('ANYTOUR_DATA_DB_USER')
            && defined('ANYTOUR_DATA_DB_PASSWORD')
            && (string) ANYTOUR_DATA_DB_NAME !== '';
    }

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) return self::$pdo;
        if (!self::configured()) {
            throw new RuntimeException('AnyTour data database is not configured');
        }

        $dsn = 'mysql:host=' . ANYTOUR_DATA_DB_HOST . ';dbname=' . ANYTOUR_DATA_DB_NAME . ';charset=utf8mb4';
        self::$pdo = new PDO($dsn, ANYTOUR_DATA_DB_USER, ANYTOUR_DATA_DB_PASSWORD, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return self::$pdo;
    }

    public static function resetForTests(): void
    {
        self::$pdo = null;
    }
}
