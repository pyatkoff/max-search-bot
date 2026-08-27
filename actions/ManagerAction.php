<?php
require_once __DIR__ . '/../services/DiagnosticLogger.php';
require_once __DIR__ . '/../services/IntegrationRegistry.php';
require_once __DIR__ . '/../services/DialogueView.php';
require_once __DIR__ . '/../services/ConversationRecorder.php';
require_once __DIR__ . '/../services/ConversationControlService.php';
require_once __DIR__ . '/../services/ManagerAvailabilityService.php';
require_once __DIR__ . '/../services/ManagerRequestService.php';
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
        $conversation = ConversationControlService::statusByChat($platform, $chatId);
        $withinWorkingHours = ManagerAvailabilityService::withinWorkingHours();
        $managerAvailable = false;
        if ($withinWorkingHours && $conversation) {
            try { $managerAvailable = ManagerAvailabilityService::anyWorkingForConversation($conversation); } catch (Throwable $ignored) {}
        }

        ConversationRecorder::eventByChat($platform,$chatId,'manager_request',[
            'summary'=>$plan['summary'],
            'from_tours'=>$fromTours,
            'manager_available'=>$managerAvailable,
            'within_working_hours'=>$withinWorkingHours,
        ],'ai');

        if ($managerAvailable) {
            $model = ManagerRequestService::prepare($chatId, $name, $fromTours);
            MaxSearchApi::deletePrevMessage($chatId);
            $buttons = [[['text'=>'↩️ Вернуться','callback_data'=>(string)$model['back_callback']]]];
            $sent = IntegrationRegistry::messenger()->sendWithButtons($chatId, (string)$model['online_text'], $buttons);
        } else {
            $sent = DialogueView::managerRequest($chatId, $name, $fromTours, !$withinWorkingHours);
        }

        if ($sent) ConversationControlService::markWaitingByChat($platform,$chatId,[
            'summary'=>$plan['summary'],
            'from_tours'=>$fromTours,
            'manager_available'=>$managerAvailable,
            'within_working_hours'=>$withinWorkingHours,
        ]);
        return (bool)$sent;
    }
}
