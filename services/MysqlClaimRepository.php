<?php

declare(strict_types=1);

require_once __DIR__ . '/RuntimeStorage.php';

final class MysqlClaimRepository
{
    private static function pdo(): PDO
    {
        return RuntimeStorage::connection();
    }

    private static function projectKey(): string
    {
        return RuntimeStorage::projectKey();
    }

    public static function create($chatID, array $data): bool
    {
        $stmt = self::pdo()->prepare(
            'INSERT INTO runtime_claims '
            . '(project_key, chat_id, name, city_id, country_id, adults, children, child_ages, stars, meal_id, nights, departure_date, code) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        return $stmt->execute([
            self::projectKey(),
            (string)$chatID,
            (string)($data['UF_NAME'] ?? ''),
            (int)($data['UF_CITY'] ?? 0),
            (int)($data['UF_COUNTRY'] ?? 0),
            (int)($data['UF_ADULTS'] ?? 0),
            (int)($data['UF_CHILD'] ?? 0),
            (string)($data['UF_AGE'] ?? ''),
            (int)($data['UF_STARS'] ?? 0),
            (string)($data['UF_MEAL'] ?? ''),
            (string)($data['UF_NIGHTS'] ?? ''),
            (string)($data['UF_DATE_DEPART'] ?? ''),
            (string)($data['UF_CODE'] ?? ''),
        ]);
    }

    public static function latestForChat($chatID)
    {
        $stmt = self::pdo()->prepare('SELECT * FROM runtime_claims WHERE project_key = ? AND chat_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([self::projectKey(), (string)$chatID]);
        return self::legacyRow($stmt->fetch(PDO::FETCH_ASSOC));
    }

    public static function byCode($code)
    {
        $stmt = self::pdo()->prepare('SELECT * FROM runtime_claims WHERE project_key = ? AND code = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([self::projectKey(), (string)$code]);
        $row = self::legacyRow($stmt->fetch(PDO::FETCH_ASSOC));
        return $row ?: [];
    }

    public static function setPhone($claimId, $phone): bool
    {
        $stmt = self::pdo()->prepare('UPDATE runtime_claims SET phone = ? WHERE project_key = ? AND id = ?');
        return $stmt->execute([(string)$phone, self::projectKey(), (int)$claimId]);
    }

    public static function markPhoneAsked($claimId, $value = true): bool
    {
        $stmt = self::pdo()->prepare('UPDATE runtime_claims SET phone_asked = ? WHERE project_key = ? AND id = ?');
        return $stmt->execute([$value ? 1 : 0, self::projectKey(), (int)$claimId]);
    }

    private static function legacyRow($row)
    {
        if (!is_array($row)) return false;
        return [
            'ID' => (int)$row['id'],
            'UF_CHAT_ID' => $row['chat_id'],
            'UF_NAME' => $row['name'],
            'UF_CITY' => (int)$row['city_id'],
            'UF_COUNTRY' => (int)$row['country_id'],
            'UF_ADULTS' => (int)$row['adults'],
            'UF_CHILD' => (int)$row['children'],
            'UF_AGE' => $row['child_ages'],
            'UF_STARS' => (int)$row['stars'],
            'UF_MEAL' => $row['meal_id'],
            'UF_NIGHTS' => $row['nights'],
            'UF_DATE_DEPART' => $row['departure_date'],
            'UF_CODE' => $row['code'],
            'UF_PHONE' => $row['phone'],
            'UF_PHONE_ASKED' => (bool)$row['phone_asked'],
            'UF_DATE' => $row['created_at'],
        ];
    }
}
