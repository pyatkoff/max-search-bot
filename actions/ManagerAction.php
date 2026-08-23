<?php
require_once __DIR__ . '/../services/ManagerSummaryService.php';
require_once __DIR__ . '/../services/DiagnosticLogger.php';

class ManagerAction
{
    public static function plan(array $tripState, array $userContext = []): array
    {
        return [
            'action'=>'MANAGER',
            'summary'=>ManagerSummaryService::build($tripState, $userContext),
        ];
    }

    public static function execute($chatId, array $tripState, array $userContext = [], string $name = '', bool $fromTours = false): bool
    {
        $plan = self::plan($tripState, $userContext);
        MaxSearchApi::funnelLog($chatId, 'manager_request', ['source'=>'ai_v2_action']);
        MaxSearchApi::queueMetrikaGoal($chatId, 'max_manager_request');
        DiagnosticLogger::log('dialogue_v2_live', 'manager_summary', [
            'summary'=>$plan['summary'],
        ], $chatId);
        MaxSearchApi::showManagerRequest($chatId, $name, $fromTours);
        return true;
    }
}
