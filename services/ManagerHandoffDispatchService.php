<?php
require_once __DIR__ . '/IntegrationRegistry.php';
require_once __DIR__ . '/DialogueView.php';
require_once __DIR__ . '/ConversationControlService.php';
require_once __DIR__ . '/ManagerAvailabilityService.php';
require_once __DIR__ . '/ManagerRequestService.php';

/**
 * Chooses the customer-facing manager handoff presentation from the same
 * availability policy regardless of whether handoff started from AI or a callback.
 */
class ManagerHandoffDispatchService
{
    public static function dispatch($chatId, string $platform, string $name = '', bool $fromTours = false, ?int $now = null): array
    {
        $platform = strtolower(trim($platform));
        $conversation = ConversationControlService::statusByChat($platform, $chatId);
        $withinWorkingHours = ManagerAvailabilityService::withinWorkingHours($now);
        $managerAvailable = false;

        if ($withinWorkingHours && $conversation) {
            try {
                $managerAvailable = ManagerAvailabilityService::anyWorkingForConversation($conversation);
            } catch (Throwable $ignored) {
                $managerAvailable = false;
            }
        }

        if ($managerAvailable) {
            $model = ManagerRequestService::prepare($chatId, $name, $fromTours);
            MaxSearchApi::deletePrevMessage($chatId);
            $buttons = [[['text'=>'↩️ Вернуться','callback_data'=>(string)$model['back_callback']]]];
            $sent = IntegrationRegistry::messenger()->sendWithButtons(
                $chatId,
                (string)$model['online_text'],
                $buttons
            );
        } else {
            $sent = DialogueView::managerRequest(
                $chatId,
                $name,
                $fromTours,
                !$withinWorkingHours
            );
        }

        return [
            'sent'=>(bool)$sent,
            'manager_available'=>$managerAvailable,
            'within_working_hours'=>$withinWorkingHours,
        ];
    }
}
