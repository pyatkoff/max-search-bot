<?php

declare(strict_types=1);

require_once __DIR__ . '/HotelDatabase.php';

/** Travel directories backed by the standalone AnyTour catalogue database. */
class TravelDirectoryRepository
{
    private static function pdo(): PDO { return HotelDatabase::connection(); }

    public static function cityById($hlId, $cityId)
    {
        $stmt = self::pdo()->prepare('SELECT name FROM catalog_departures WHERE id = :id AND is_active = 1 LIMIT 1');
        $stmt->execute(['id' => $cityId]);
        $row = $stmt->fetch();
        return is_array($row) && array_key_exists('name', $row) ? $row['name'] : false;
    }

    public static function cityFromById($hlId, $cityId)
    {
        return self::cityFromNameFromRow(self::departureNameRow($cityId));
    }

    private static function departureNameRow($cityId)
    {
        try {
            $stmt = self::pdo()->prepare('SELECT name, name_genitive FROM catalog_departures WHERE id = :id AND is_active = 1 LIMIT 1');
            $stmt->execute(['id' => $cityId]);
            return $stmt->fetch();
        } catch (PDOException $e) {
            if ((string) $e->getCode() !== '42S22') throw $e;
            $stmt = self::pdo()->prepare('SELECT name FROM catalog_departures WHERE id = :id AND is_active = 1 LIMIT 1');
            $stmt->execute(['id' => $cityId]);
            return $stmt->fetch();
        }
    }

    public static function activeDepartures(): array
    {
        try {
            $stmt = self::pdo()->query('SELECT id, name, name_genitive FROM catalog_departures WHERE is_active = 1 ORDER BY name');
            return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (PDOException $e) {
            if ((string) $e->getCode() !== '42S22') throw $e;
            $stmt = self::pdo()->query('SELECT id, name FROM catalog_departures WHERE is_active = 1 ORDER BY name');
            return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        }
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

    public static function cityNameFromRow($row)
    {
        if (!is_array($row)) return false;
        if (array_key_exists('name', $row)) return $row['name'];
        return array_key_exists('UF_NAME', $row) ? $row['UF_NAME'] : false;
    }

    public static function cityFromNameFromRow($row)
    {
        if (!is_array($row)) return false;
        if (array_key_exists('name_genitive', $row)) {
            $genitive = trim((string) $row['name_genitive']);
            if ($genitive !== '') return $genitive;
        }
        if (array_key_exists('name', $row)) return $row['name'];
        return array_key_exists('UF_NAME2', $row) ? $row['UF_NAME2'] : false;
    }

    public static function cityRecordFromRow($row)
    {
        if (!is_array($row)) return false;
        if (array_key_exists('name', $row) && array_key_exists('id', $row)) return ['NAME' => $row['name'], 'ID' => $row['id']];
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
        if (array_key_exists('name', $row) && array_key_exists('id', $row)) return ['NAME' => $row['name'], 'ID' => $row['id']];
        if (!array_key_exists('UF_NAME', $row) || !array_key_exists('UF_CID', $row)) return false;
        return ['NAME' => $row['UF_NAME'], 'ID' => $row['UF_CID']];
    }

    public static function mealMap(): array
    {
        return ['all'=>'ЛЮБОЕ','999'=>'ЛЮБОЕ','7'=>'ВСЕ ВКЛЮЧЕНО','3'=>'ЗАВТРАК','4'=>'ПОЛУПАНСИОН','5'=>'ПОЛНЫЙ ПАНСИОН'];
    }
}
