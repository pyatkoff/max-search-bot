<?php

declare(strict_types=1);

require_once __DIR__ . '/HotelDatabase.php';

/**
 * Cutover guard for the Tourvisor IDs relied on by the shared dialogue flow.
 *
 * The standalone catalogue stores upstream Tourvisor IDs directly as primary
 * keys. These canonical IDs are also the values historically carried by the
 * legacy UF_DEPID / UF_CID fields, so a missing row is a cutover blocker.
 */
final class CatalogIdCompatibility
{
    private const REQUIRED_DEPARTURE_IDS = [1, 5, 10, 12];
    private const REQUIRED_COUNTRY_IDS = [1, 2, 4, 8, 9, 12];

    public static function requiredDepartureIds(): array
    {
        return self::REQUIRED_DEPARTURE_IDS;
    }

    public static function requiredCountryIds(): array
    {
        return self::REQUIRED_COUNTRY_IDS;
    }

    public static function inspect(): array
    {
        if (!HotelDatabase::configured()) {
            return [
                'compatible' => false,
                'missing_departure_ids' => self::REQUIRED_DEPARTURE_IDS,
                'missing_country_ids' => self::REQUIRED_COUNTRY_IDS,
                'error' => 'catalog_db_not_configured',
            ];
        }

        try {
            $pdo = HotelDatabase::connection();
            $missingDepartures = self::missingIds($pdo, 'catalog_departures', self::REQUIRED_DEPARTURE_IDS);
            $missingCountries = self::missingIds($pdo, 'catalog_countries', self::REQUIRED_COUNTRY_IDS);

            return [
                'compatible' => $missingDepartures === [] && $missingCountries === [],
                'missing_departure_ids' => $missingDepartures,
                'missing_country_ids' => $missingCountries,
                'error' => null,
            ];
        } catch (Throwable $e) {
            return [
                'compatible' => false,
                'missing_departure_ids' => self::REQUIRED_DEPARTURE_IDS,
                'missing_country_ids' => self::REQUIRED_COUNTRY_IDS,
                'error' => 'catalog_id_check_failed',
            ];
        }
    }

    private static function missingIds(PDO $pdo, string $table, array $required): array
    {
        $placeholders = implode(',', array_fill(0, count($required), '?'));
        $stmt = $pdo->prepare("SELECT id FROM {$table} WHERE is_active = 1 AND id IN ({$placeholders})");
        $stmt->execute($required);
        $present = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $missing = array_values(array_diff($required, $present));
        sort($missing);
        return $missing;
    }
}
