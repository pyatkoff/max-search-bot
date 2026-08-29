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
    public static function shouldQueueWaiting(bool $sent, bool $withinWorkingHours): bool
    {
        return $sent && $withinWorkingHours;
    }

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

        if ($withinWorkingHours) {
            // During the workday the manager request itself is the primary conversion.
            // Availability is an operational hint, not a reason to block the handoff on phone.
            // If nobody replies, the existing delayed fallback offers phone after 5 minutes.
            $model = ManagerRequestService::prepare($chatId, $name, $fromTours);
            MaxSearchApi::deletePrevMessage($chatId);
            $buttons = [[['text'=>'↩️ Вернуться','callback_data'=>(string)$model['back_callback']]]];
            $sent = IntegrationRegistry::messenger()->sendWithButtons(
                $chatId,
                (string)($managerAvailable ? $model['online_text'] : $model['working_wait_text']),
                $buttons
            );
        } else {
            // Outside working hours phone remains optional and the copy is explicit about
            // the next working period; self-service/tours remain available via Back.
            // This is a deferred contact offer, not an active manager queue request.
            $sent = DialogueView::managerRequest(
                $chatId,
                $name,
                $fromTours,
                true
            );
        }

        return [
            'sent'=>(bool)$sent,
            'manager_available'=>$managerAvailable,
            'within_working_hours'=>$withinWorkingHours,
            'queue_waiting'=>self::shouldQueueWaiting((bool)$sent, $withinWorkingHours),
        ];
    }
}
