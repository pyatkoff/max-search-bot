<?php
require_once __DIR__ . '/../services/DiagnosticLogger.php';
require_once __DIR__ . '/../services/IntegrationRegistry.php';

class ManagerAction
{
    public static function plan(array $tripState, array $userContext = []): array
    {
        $destinationPlan = IntegrationRegistry::leadDestination()->plan($tripState, $userContext);
        return [
            'action'=>'MANAGER',
            'summary'=>(string)($destinationPlan['summary'] ?? ''),
            'destination_plan'=>$destinationPlan,
        ];
    }

    public static function execute($chatId, array $tripState, array $userContext = [], string $name = '', bool $fromTours = false): bool
    {
        $plan = self::plan($tripState, $userContext);
        MaxSearchApi::funnelLog($chatId, 'manager_request', ['source'=>'ai_v2_action']);
        MaxSearchApi::queueMetrikaGoal($chatId, 'max_manager_request');
        DiagnosticLogger::log('dialogue_v2_live', 'manager_summary', [
            'summary'=>$plan['summary'],
            'destination_provider'=>$plan['destination_plan']['provider'] ?? null,
        ], $chatId);

        // Execution intentionally stays on the current proven Bitrix/MAX flow.
        // The destination contract is now separated, so another CRM can replace it later.
        MaxSearchApi::showManagerRequest($chatId, $name, $fromTours);
        return true;
    }
}
