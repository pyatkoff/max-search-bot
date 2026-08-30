<?php

declare(strict_types=1);

require_once __DIR__ . '/HotelDatabase.php';

/**
 * Travel directories backed by the standalone AnyTour catalogue database.
 *
 * The legacy $hlId argument is intentionally retained in public methods so
 * callers can migrate without a big-bang signature change. It is ignored by
 * this implementation.
 */
class TravelDirectoryRepository
{
    private static function pdo(): PDO
    {
        return HotelDatabase::connection();
    }

    public static function cityById($hlId, $cityId)
    {
        $stmt = self::pdo()->prepare('SELECT name FROM catalog_departures WHERE id = :id AND is_active = 1 LIMIT 1');
        $stmt->execute(['id' => $cityId]);
        $row = $stmt->fetch();
        return is_array($row) && array_key_exists('name', $row) ? $row['name'] : false;
    }

    public static function cityFromById($hlId, $cityId)
    {
        // catalog_departures has one canonical display name. Preserve the
        // legacy method contract by returning it until a dedicated grammatical
        // departure form is explicitly added to the catalogue schema.
        return self::cityById($hlId, $cityId);
    }

    public static function cityByName($hlId, $name)
    {
        $stmt = self::pdo()->prepare('SELECT id, name FROM catalog_departures WHERE name = :name AND is_active = 1 LIMIT 1');
        $stmt->execute(['name' => $name]);
        $row = $stmt->fetch();
        if (!is_array($row)) return false;
        return ['NAME' => $row['name'], 'ID' => $row['id']];
    }

    public static function countryById($hlId, $countryId)
    {
        $stmt = self::pdo()->prepare('SELECT name FROM catalog_countries WHERE id = :id AND is_active = 1 LIMIT 1');
        $stmt->execute(['id' => $countryId]);
        $row = $stmt->fetch();
        return is_array($row) && array_key_exists('name', $row) ? $row['name'] : false;
    }

    public static function countryByName($hlId, $name)
    {
        $stmt = self::pdo()->prepare('SELECT id, name FROM catalog_countries WHERE name = :name AND is_active = 1 LIMIT 1');
        $stmt->execute(['name' => $name]);
        $row = $stmt->fetch();
        if (!is_array($row)) return false;
        return ['NAME' => $row['name'], 'ID' => $row['id']];
    }

    // Row conversion helpers stay available for compatibility with existing
    // tests and callers while runtime reads use the standalone schema above.
    public static function cityNameFromRow($row)
    {
        if (!is_array($row)) return false;
        if (array_key_exists('name', $row)) return $row['name'];
        return array_key_exists('UF_NAME', $row) ? $row['UF_NAME'] : false;
    }

    public static function cityFromNameFromRow($row)
    {
        if (!is_array($row)) return false;
        if (array_key_exists('name', $row)) return $row['name'];
        return array_key_exists('UF_NAME2', $row) ? $row['UF_NAME2'] : false;
    }

    public static function cityRecordFromRow($row)
    {
        if (!is_array($row)) return false;
        if (array_key_exists('name', $row) && array_key_exists('id', $row)) {
            return ['NAME' => $row['name'], 'ID' => $row['id']];
        }
        if (!array_key_exists('UF_NAME', $row) || !array_key_exists('UF_DEPID', $row)) return false;
        return ['NAME' => $row['UF_NAME'], 'ID' => $row['UF_DEPID']];
    }

    public static function countryNameFromRow($row)
    {
        if (!is_array($row)) return false;
        if (array_key_exists('name', $row)) return $row['name'];
        return array_key_exists('UF_NAME', $row) ? $row['UF_NAME'] : false;
    }

    public static function countryRecordFromRow($row)
    {
        if (!is_array($row)) return false;
        if (array_key_exists('name', $row) && array_key_exists('id', $row)) {
            return ['NAME' => $row['name'], 'ID' => $row['id']];
        }
        if (!array_key_exists('UF_NAME', $row) || !array_key_exists('UF_CID', $row)) return false;
        return ['NAME' => $row['UF_NAME'], 'ID' => $row['UF_CID']];
    }

    public static function mealMap(): array
    {
        return [
            'all'=>'ЛЮБОЕ',
            '999'=>'ЛЮБОЕ',
            '7'=>'ВСЕ ВКЛЮЧЕНО',
            '3'=>'ЗАВТРАК',
            '4'=>'ПОЛУПАНСИОН',
            '5'=>'ПОЛНЫЙ ПАНСИОН',
        ];
    }
}
