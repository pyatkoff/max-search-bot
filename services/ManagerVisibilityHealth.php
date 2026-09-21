<?php

declare(strict_types=1);

/** Read-model validation only; never reads/writes permissions or business state. */
final class ManagerVisibilityHealth
{
    public static function assess(array $manager, array $all, array $mine, array $waiting, int $limit = 200): array
    {
        $allIds = [];
        $oldest = null;
        foreach ($all as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0 || isset($allIds[$id])) return self::invalid();
            $allIds[$id] = true;
            $activity = self::activity($row);
            if ($activity !== null && ($oldest === null || strcmp($activity, $oldest) < 0)) $oldest = $activity;
        }
        $mineIds = array_fill_keys(array_map('intval', array_column($mine, 'id')), true);
        $result = ['ok'=>true, 'reason'=>null, 'unassigned_waiting_count'=>0, 'seen_in_all_count'=>0,
            'outside_page_count'=>0, 'missing_in_window_count'=>0, 'all_not_in_mine_count'=>count(array_diff_key($allIds, $mineIds))];
        if (empty($manager['is_working'])) return $result;
        if ($limit < 1 || count($all) > $limit) return self::invalid();
        $completeTimes = count(array_filter($all, static fn(array $row): bool => self::activity($row) !== null)) === count($all);
        foreach ($waiting as $row) {
            // Waiting includes already assigned first-reply cases; those need not
            // add any row beyond Mine. Only unassigned waiting proves this invariant.
            if ((int)($row['manager_id'] ?? 0) > 0) continue;
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0 || ($row['status'] ?? '') !== 'waiting_manager') return self::invalid();
            $result['unassigned_waiting_count']++;
            if (isset($allIds[$id])) { $result['seen_in_all_count']++; continue; }
            $activity = self::activity($row);
            // All is newest-first and capped. An older row, or a tie at its
            // non-unique boundary, can legitimately fall on the following page.
            if (count($all) === $limit && $completeTimes && $oldest !== null && $activity !== null && strcmp($activity, $oldest) <= 0) {
                $result['outside_page_count']++;
                continue;
            }
            $result['missing_in_window_count']++;
        }
        if ($result['missing_in_window_count'] > 0) {
            $result['ok'] = false;
            $result['reason'] = 'working_manager_waiting_missing_from_all';
        }
        return $result;
    }

    private static function activity(array $row): ?string
    {
        $value = $row['last_message_at'] ?? $row['started_at'] ?? null;
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value) === 1 ? $value : null;
    }

    private static function invalid(): array
    {
        return ['ok'=>false, 'reason'=>'invalid_manager_visibility_sample'];
    }
}
