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
            ];
        }
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
        ];

        if (!self::tableExists($pdo, 'managers')) {
            $result['ok'] = false;
            $result['error'] = 'managers_table_missing';
            return $result;
        }

        $managers = $pdo->query("SELECT id,login,display_name,is_working FROM managers WHERE is_active=1 AND is_working=1 ORDER BY id")->fetchAll();
        foreach ($managers as $manager) {
            $id = (int)$manager['id'];
            $entry = [
                'manager_id' => $id,
                'login' => (string)($manager['login'] ?? ''),
                'display_name' => (string)($manager['display_name'] ?? ''),
                'subscription_count' => 0,
                'healthy_subscription_count' => 0,
                'last_success_at' => null,
                'last_error_at' => null,
                'last_error' => null,
            ];

            if ($table) {
                $q = $pdo->prepare("SELECT COUNT(*) AS subscription_count,
                    SUM(CASE WHEN last_error_at IS NULL OR (last_success_at IS NOT NULL AND last_success_at>=last_error_at) THEN 1 ELSE 0 END) AS healthy_subscription_count,
                    MAX(last_success_at) AS last_success_at,
                    MAX(last_error_at) AS last_error_at
                    FROM manager_push_subscriptions WHERE manager_id=?");
                $q->execute([$id]);
                $stats = $q->fetch() ?: [];
                $entry['subscription_count'] = (int)($stats['subscription_count'] ?? 0);
                $entry['healthy_subscription_count'] = (int)($stats['healthy_subscription_count'] ?? 0);
                $entry['last_success_at'] = $stats['last_success_at'] ?? null;
                $entry['last_error_at'] = $stats['last_error_at'] ?? null;

                $q = $pdo->prepare("SELECT last_error FROM manager_push_subscriptions WHERE manager_id=? AND last_error IS NOT NULL ORDER BY last_error_at DESC,id DESC LIMIT 1");
                $q->execute([$id]);
                $lastError = $q->fetchColumn();
                $entry['last_error'] = $lastError === false ? null : (string)$lastError;
            }

            if ($entry['subscription_count'] === 0) {
                $result['missing_subscription_manager_ids'][] = $id;
            }
            if ($entry['last_error_at'] !== null && ($entry['last_success_at'] === null || $entry['last_error_at'] > $entry['last_success_at'])) {
                $result['recent_error_manager_ids'][] = $id;
            }
            $result['working_managers'][] = $entry;
        }

        $result['ok'] = $table
            && count($result['missing_subscription_manager_ids']) === 0
            && count($result['recent_error_manager_ids']) === 0;
        return $result;
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        $q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
        $q->execute([$table]);
        return (int)$q->fetchColumn() > 0;
    }
}
