<?php
require_once dirname(__DIR__, 2) . '/services/DialogueView.php';

class ManagerCallbackAction
{
    public static function handles(string $q): bool
    {
        return in_array($q, ['manager_request','manager_after_tours','phone_manual'], true);
    }

    public static function handle(int $chatId, string $q, array $query): bool
    {
        if ($q === 'manager_request' || $q === 'manager_after_tours') {
            $afterTours = $q === 'manager_after_tours';
            MaxSearchApi::funnelLog($chatId, 'manager_request', ['source'=>$afterTours ? 'followup' : 'before_site']);
            if ($afterTours) MaxSearchApi::cancelToursFollowup($chatId);
            MaxSearchApi::queueMetrikaGoal($chatId, 'max_manager_request');
            return DialogueView::managerRequest($chatId, self::userName($query), $afterTours);
        }

        if ($q === 'phone_manual') return DialogueView::manualPhone($chatId);

        return false;
    }

    private static function userName(array $query): string
    {
        $from = (array)($query['from'] ?? []);
        $name = trim((string)($from['first_name'] ?? ''));
        $last = trim((string)($from['last_name'] ?? ''));
        if ($last !== '') $name = trim($name . ' ' . $last);
        if ($name === '') $name = trim((string)($from['username'] ?? ''));
        return $name;
    }
}
