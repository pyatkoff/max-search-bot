<?php

declare(strict_types=1);

/**
 * Separate connection boundary for the hotel/catalog database.
 *
 * The bot/manager operational database must stay independent from the
 * potentially large hotel catalogue. Credentials are supplied only through
 * the external production config and are never committed to the repository.
 */
final class HotelDatabase
{
    private static ?PDO $pdo = null;

    public static function configured(): bool
    {
        return defined('HOTEL_DB_HOST')
            && defined('HOTEL_DB_NAME')
            && defined('HOTEL_DB_USER')
            && defined('HOTEL_DB_PASS')
            && (string) HOTEL_DB_NAME !== '';
    }

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) return self::$pdo;
        if (!self::configured()) {
            throw new RuntimeException('Hotel database is not configured');
        }

        $dsn = 'mysql:host=' . HOTEL_DB_HOST . ';dbname=' . HOTEL_DB_NAME . ';charset=utf8mb4';
        self::$pdo = new PDO($dsn, HOTEL_DB_USER, HOTEL_DB_PASS, [
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
