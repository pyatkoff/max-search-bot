<?php
require_once __DIR__ . '/DialogueView.php';

class EditFlowService
{
    private static function snapshotFile($chatId): string
    {
        $dir = sys_get_temp_dir() . '/max-search-edit-snapshots';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        return $dir . '/' . hash('sha256', (string)$chatId) . '.json';
    }

    public static function begin($chatId, string $field): void
    {
        $file = self::snapshotFile($chatId);
        if (!is_file($file)) {
            $snapshot = (array)MaxSearchApi::getSavedData($chatId);
            @file_put_contents($file, json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        }
        MaxSearchApi::setEditMode($chatId, $field);
    }

    public static function clearSnapshot($chatId): void
    {
        $file = self::snapshotFile($chatId);
        if (is_file($file)) @unlink($file);
    }

    public static function finishIfNeeded($chatId, string $field): bool
    {
        if ((string)MaxSearchApi::getEditMode($chatId) !== $field) return false;

        $snapshot = self::readSnapshot($chatId);
        $current = (array)MaxSearchApi::getSavedData($chatId);
        $editedStatuses = self::editedStatuses($field);
        foreach (self::missingSnapshotValues($current, $snapshot, $editedStatuses) as $status => $value) {
            MaxSearchApi::appendStatusValue($chatId, $status, $value);
        }

        MaxSearchApi::setEditMode($chatId, '');
        self::clearSnapshot($chatId);
        DialogueView::check($chatId);
        return true;
    }

    public static function missingSnapshotValues(array $current, array $snapshot, array $editedStatuses): array
    {
        $out = [];
        foreach ($snapshot as $status => $value) {
            if (in_array((string)$status, array_map('strval', $editedStatuses), true)) continue;
            if ($value === null || $value === '') continue;
            if (!array_key_exists($status, $current) || $current[$status] === null || $current[$status] === '') {
                $out[$status] = $value;
            }
        }
        return $out;
    }

    private static function readSnapshot($chatId): array
    {
        $file = self::snapshotFile($chatId);
        if (!is_file($file)) return [];
        $decoded = json_decode((string)@file_get_contents($file), true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function editedStatuses(string $field): array
    {
        switch ($field) {
            case 'city': return [MaxSearchApi::$statusCityChoose];
            case 'country': return [MaxSearchApi::$statusContryChoose];
            case 'tourists': return [MaxSearchApi::$statusAdults, MaxSearchApi::$statusChild, MaxSearchApi::$statusAge];
            case 'stars': return [MaxSearchApi::$statusStars];
            case 'meal': return [MaxSearchApi::$statusMeal];
            case 'nights': return [MaxSearchApi::$statusNights];
            case 'date': return [MaxSearchApi::$statusDate];
            default: return [];
        }
    }
}
