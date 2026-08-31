<?php

declare(strict_types=1);

require_once __DIR__ . '/HotelDatabase.php';

/**
 * Catalog read boundary used by DestinationResolver.
 *
 * Legacy production remains on Bitrix unless MAX_SEARCH_DESTINATION_STORAGE is
 * explicitly set to "mysql". The MySQL adapter deliberately returns the old
 * UF_* row shape so the resolver's business rules do not change during the
 * infrastructure migration.
 */
final class DestinationCatalogRepository
{
    private const COUNTRY_HL = 2;
    private const REGION_HL = 3;
    private const HOTEL_HL = 6;

    public static function storage(): string
    {
        $storage = defined('MAX_SEARCH_DESTINATION_STORAGE')
            ? strtolower(trim((string) MAX_SEARCH_DESTINATION_STORAGE))
            : 'bitrix';

        if (!in_array($storage, ['bitrix', 'mysql'], true)) {
            throw new RuntimeException('Unsupported destination catalog storage: ' . $storage);
        }
        return $storage;
    }

    public static function query(int $legacyHlId, array $filter, array $select, int $limit = 20): array
    {
        if (self::storage() === 'mysql') {
            return self::mysqlQuery($legacyHlId, $filter, $limit);
        }
        return self::bitrixQuery($legacyHlId, $filter, $select, $limit);
    }

    public static function legacyShape(string $type, array $row): array
    {
        if ($type === 'country') {
            return [
                'UF_CID' => (int)($row['id'] ?? 0),
                'UF_NAME' => (string)($row['name'] ?? ''),
            ];
        }
        if ($type === 'region') {
            return [
                'UF_TID' => (int)($row['id'] ?? 0),
                'UF_CID' => (int)($row['country_id'] ?? 0),
                'UF_NAME' => (string)($row['name'] ?? ''),
                'UF_PARENT_TID' => (int)($row['parent_id'] ?? 0),
            ];
        }
        if ($type === 'hotel') {
            return [
                'UF_HID' => (int)($row['id'] ?? 0),
                'UF_NAME' => (string)($row['name'] ?? ''),
                'UF_CID' => (int)($row['country_id'] ?? 0),
                'UF_TID' => (int)($row['region_id'] ?? 0),
                'UF_RATE' => $row['rating'] ?? null,
            ];
        }
        throw new InvalidArgumentException('Unknown destination catalog row type: ' . $type);
    }

    private static function mysqlQuery(int $legacyHlId, array $filter, int $limit): array
    {
        [$type, $table] = self::mysqlSource($legacyHlId);
        $limit = max(1, min(500, $limit));
        $where = ['is_active = 1'];
        $params = [];

        foreach ($filter as $key => $value) {
            switch ((string)$key) {
                case '=UF_NAME':
                    $where[] = 'name = :name_exact';
                    $params[':name_exact'] = trim((string)$value);
                    break;
                case '%UF_NAME':
                    $where[] = 'name LIKE :name_like';
                    $params[':name_like'] = '%' . trim((string)$value) . '%';
                    break;
                case '=UF_CID':
                    if ($type === 'country') {
                        $where[] = 'id = :country_id';
                    } else {
                        $where[] = 'country_id = :country_id';
                    }
                    $params[':country_id'] = (int)$value;
                    break;
                case '=UF_TID':
                    if ($type === 'region') {
                        $where[] = 'id = :region_id';
                    } elseif ($type === 'hotel') {
                        $where[] = 'region_id = :region_id';
                    } else {
                        throw new InvalidArgumentException('UF_TID is not valid for country catalog queries');
                    }
                    $params[':region_id'] = (int)$value;
                    break;
                default:
                    throw new InvalidArgumentException('Unsupported destination catalog filter: ' . (string)$key);
            }
        }

        $columns = $type === 'country'
            ? 'id, name'
            : ($type === 'region'
                ? 'id, country_id, name'
                : 'id, country_id, region_id, name, rating');

        $sql = 'SELECT ' . $columns . ' FROM ' . $table
            . ' WHERE ' . implode(' AND ', $where)
            . ' LIMIT ' . $limit;
        $stmt = HotelDatabase::connection()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        return array_map(static fn(array $row): array => self::legacyShape($type, $row), $rows ?: []);
    }

    private static function mysqlSource(int $legacyHlId): array
    {
        if ($legacyHlId === self::COUNTRY_HL) return ['country', 'catalog_countries'];
        if ($legacyHlId === self::REGION_HL) return ['region', 'catalog_regions'];
        if ($legacyHlId === self::HOTEL_HL) return ['hotel', 'catalog_hotels'];
        throw new InvalidArgumentException('Unsupported legacy destination HL id: ' . $legacyHlId);
    }

    private static function bitrixQuery(int $legacyHlId, array $filter, array $select, int $limit): array
    {
        \Bitrix\Main\Loader::includeModule('highloadblock');
        $hlblock = \Bitrix\Highloadblock\HighloadBlockTable::getById($legacyHlId)->fetch();
        if (!$hlblock) return [];
        $entity = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($hlblock);
        $class = $entity->getDataClass();
        $res = $class::getList([
            'filter' => $filter,
            'select' => $select,
            'limit' => max(1, min(500, $limit)),
        ]);
        $rows = [];
        while ($row = $res->fetch()) $rows[] = $row;
        return $rows;
    }
}
