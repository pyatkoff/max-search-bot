<?php
require_once __DIR__ . '/../services/DiagnosticLogger.php';
require_once __DIR__ . '/../services/IntegrationRegistry.php';
require_once __DIR__ . '/../services/ConversationRecorder.php';
require_once __DIR__ . '/../services/ConversationControlService.php';
require_once __DIR__ . '/../services/ManagerHandoffDispatchService.php';
require_once __DIR__ . '/../services/ProjectConfig.php';

class ManagerAction
{
    public static function plan(array $tripState, array $userContext = []): array
    {
        $destinationPlan = IntegrationRegistry::leadDestination()->plan($tripState, $userContext);
        return ['action'=>'MANAGER','summary'=>(string)($destinationPlan['summary'] ?? ''),'destination_plan'=>$destinationPlan];
    }

    public static function execute($chatId, array $tripState, array $userContext = [], string $name = '', bool $fromTours = false): bool
    {
        $plan = self::plan($tripState, $userContext);
        MaxSearchApi::funnelLog($chatId, 'manager_request', ['source'=>'ai_v2_action']);
        MaxSearchApi::queueMetrikaGoal($chatId, 'max_manager_request');
        DiagnosticLogger::log('dialogue_v2_live','manager_summary',['summary'=>$plan['summary'],'destination_provider'=>$plan['destination_plan']['provider'] ?? null],$chatId);

        $platform = strtolower(trim((string)($userContext['platform'] ?? ProjectConfig::get('messenger.provider', 'max'))));
        $handoff = ManagerHandoffDispatchService::dispatch($chatId, $platform, $name, $fromTours);
        $eventType = !empty($handoff['queue_waiting']) ? 'manager_request' : 'manager_request_deferred';

        ConversationRecorder::eventByChat($platform,$chatId,$eventType,[
            'summary'=>$plan['summary'],
            'from_tours'=>$fromTours,
            'manager_available'=>$handoff['manager_available'],
            'within_working_hours'=>$handoff['within_working_hours'],
        ],'ai');

        if (!empty($handoff['queue_waiting'])) ConversationControlService::markWaitingByChat($platform,$chatId,[
            'summary'=>$plan['summary'],
            'from_tours'=>$fromTours,
            'manager_available'=>$handoff['manager_available'],
            'within_working_hours'=>$handoff['within_working_hours'],
        ]);
        return (bool)$handoff['sent'];
    }
}
