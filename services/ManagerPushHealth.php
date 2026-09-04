<?php

class ManagerPushHealth
{
    public static function collect(PDO $pdo): array
    {
        try {
            return self::collectUnsafe($pdo);
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'error' => get_class($e).': '.$e->getMessage(),
                'subscription_table_exists' => null,
                'working_managers' => [],
                'missing_subscription_manager_ids' => [],
                'recent_error_manager_ids' => [],
                'unusable_notification_path_manager_ids' => [],
                'working_manager_notification_path_ok' => false,
                'working_manager_count' => 0,
                'usable_notification_path_count' => 0,
                'no_subscription_count' => 0,
                'unhealthy_subscription_count' => 0,
                'other_unusable_count' => 0,
            ];
        }
    }

    public static function statusForManager(PDO $pdo, int $managerId): array
    {
        try {
            $table = self::tableExists($pdo, 'manager_push_subscriptions');
            if (!self::tableExists($pdo, 'managers')) {
                return self::unavailableManagerStatus($managerId, false, 'managers_table_missing');
            }

            $q = $pdo->prepare('SELECT id,login,display_name,is_active,is_working FROM managers WHERE id=? LIMIT 1');
            $q->execute([$managerId]);
            $manager = $q->fetch();
            if (!$manager || !(int)($manager['is_active'] ?? 0)) {
                return self::unavailableManagerStatus($managerId, false, 'manager_inactive');
            }

            $entry = [
                'manager_id' => (int)$manager['id'],
                'login' => (string)($manager['login'] ?? ''),
                'display_name' => (string)($manager['display_name'] ?? ''),
                'is_working' => (bool)($manager['is_working'] ?? false),
                'subscription_table_exists' => $table,
                'subscription_count' => 0,
                'healthy_subscription_count' => 0,
                'notification_path_usable' => false,
                'notification_path_reason' => $table ? 'no_subscription' : 'subscription_table_missing',
                'last_success_at' => null,
                'last_error_at' => null,
                'last_error' => null,
            ];

            if (!$table) return $entry;

            $q = $pdo->prepare("SELECT COUNT(*) AS subscription_count,
                SUM(CASE WHEN last_error_at IS NULL OR (last_success_at IS NOT NULL AND last_success_at>=last_error_at) THEN 1 ELSE 0 END) AS healthy_subscription_count,
                MAX(last_success_at) AS last_success_at,
                MAX(last_error_at) AS last_error_at
                FROM manager_push_subscriptions WHERE manager_id=?");
            $q->execute([$managerId]);
            $stats = $q->fetch() ?: [];
            $entry['subscription_count'] = (int)($stats['subscription_count'] ?? 0);
            $entry['healthy_subscription_count'] = (int)($stats['healthy_subscription_count'] ?? 0);
            $entry['last_success_at'] = $stats['last_success_at'] ?? null;
            $entry['last_error_at'] = $stats['last_error_at'] ?? null;

            $q = $pdo->prepare("SELECT last_error FROM manager_push_subscriptions WHERE manager_id=? AND last_error IS NOT NULL ORDER BY last_error_at DESC,id DESC LIMIT 1");
            $q->execute([$managerId]);
            $lastError = $q->fetchColumn();
            $entry['last_error'] = $lastError === false ? null : (string)$lastError;

            if ($entry['healthy_subscription_count'] > 0) {
                $entry['notification_path_usable'] = true;
                $entry['notification_path_reason'] = 'healthy_subscription';
            } elseif ($entry['subscription_count'] > 0) {
                $entry['notification_path_reason'] = 'subscription_unhealthy';
            }

            return $entry;
        } catch (Throwable $e) {
            return self::unavailableManagerStatus($managerId, false, 'health_check_failed', get_class($e).': '.$e->getMessage());
        }
    }

    private static function unavailableManagerStatus(int $managerId, bool $working, string $reason, ?string $error=null): array
    {
        return [
            'manager_id' => $managerId,
            'is_working' => $working,
            'subscription_table_exists' => null,
            'subscription_count' => 0,
            'healthy_subscription_count' => 0,
            'notification_path_usable' => false,
            'notification_path_reason' => $reason,
            'last_success_at' => null,
            'last_error_at' => null,
            'last_error' => $error,
        ];
    }

    private static function collectUnsafe(PDO $pdo): array
    {
        $table = self::tableExists($pdo, 'manager_push_subscriptions');
        $result = [
            'ok' => true,
            'error' => null,
            'subscription_table_exists' => $table,
            'working_managers' => [],
            'missing_subscription_manager_ids' => [],
            'recent_error_manager_ids' => [],
            'unusable_notification_path_manager_ids' => [],
            'working_manager_notification_path_ok' => true,
            'working_manager_count' => 0,
            'usable_notification_path_count' => 0,
            'no_subscription_count' => 0,
            'unhealthy_subscription_count' => 0,
            'other_unusable_count' => 0,
        ];

        if (!self::tableExists($pdo, 'managers')) {
            $result['ok'] = false;
            $result['working_manager_notification_path_ok'] = false;
            $result['error'] = 'managers_table_missing';
            return $result;
        }

        $managers = $pdo->query("SELECT id,login,display_name,is_working FROM managers WHERE is_active=1 AND is_working=1 ORDER BY id")->fetchAll();
        foreach ($managers as $manager) {
            $id = (int)$manager['id'];
            $entry = self::statusForManager($pdo, $id);
            $result['working_manager_count']++;

            if ($entry['notification_path_usable']) {
                $result['usable_notification_path_count']++;
            } elseif ($entry['notification_path_reason'] === 'no_subscription') {
                $result['no_subscription_count']++;
            } elseif ($entry['notification_path_reason'] === 'subscription_unhealthy') {
                $result['unhealthy_subscription_count']++;
            } else {
                $result['other_unusable_count']++;
            }

            if ($entry['subscription_count'] === 0) {
                $result['missing_subscription_manager_ids'][] = $id;
            }
            if ($entry['last_error_at'] !== null && ($entry['last_success_at'] === null || $entry['last_error_at'] > $entry['last_success_at'])) {
                $result['recent_error_manager_ids'][] = $id;
            }
            if (!$entry['notification_path_usable']) {
                $result['unusable_notification_path_manager_ids'][] = $id;
            }
            $result['working_managers'][] = $entry;
        }

        $result['working_manager_notification_path_ok'] = $table
            && count($result['unusable_notification_path_manager_ids']) === 0;
        $result['ok'] = $result['working_manager_notification_path_ok'];
        return $result;
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        $q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
        $q->execute([$table]);
        return (int)$q->fetchColumn() > 0;
    }
}
