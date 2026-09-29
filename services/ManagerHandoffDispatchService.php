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
        // Explicit manager intent is independent of clock and confirmation delivery.
        return true;
    }

    /**
     * Applies an already-computed queue decision without re-evaluating working
     * hours, manager availability or routing policy.
     */
    public static function applyQueueDecision(
        array $handoff,
        string $platform,
        $chatId,
        array $payload = [],
        ?callable $markWaiting = null
    ): bool {
        if (array_key_exists('queue_applied', $handoff)) return (bool)$handoff['queue_applied'];
        if (empty($handoff['queue_waiting'])) return false;
        if ($markWaiting !== null) return (bool)$markWaiting($platform, $chatId, $payload);
        return ConversationControlService::markWaitingByChat($platform, $chatId, $payload);
    }

    public static function sourceEntryText(?int $now = null): string
    {
        return ManagerRequestService::sourceEntryMessageText(ManagerAvailabilityService::withinWorkingHours($now));
    }

    public static function dispatch($chatId, string $platform, string $name = '', bool $fromTours = false, ?int $now = null, bool $sourceEntry = false): array
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

        // Persist intent before claim preparation or any external customer send.
        $queued = self::applyQueueDecision(['queue_waiting'=>true], $platform, $chatId, [
            'source'=>'manager_dispatch',
            'manager_available'=>$managerAvailable,
            'within_working_hours'=>$withinWorkingHours,
        ]);
        $result = ['sent'=>false, 'manager_available'=>$managerAvailable,
            'within_working_hours'=>$withinWorkingHours, 'queue_waiting'=>true,
            'queue_applied'=>$queued];
        if (!$queued) return $result;
        if ($conversation && in_array((string)$conversation['status'], ['manager','waiting_manager'], true)) {
            $result['sent'] = true; // Already handed off: no duplicate confirmation or push.
            return $result;
        }

        try {
            $model = ManagerRequestService::prepare($chatId, $name, $fromTours);
            MaxSearchApi::deletePrevMessage($chatId);
            $buttons = [[['text'=>'↩️ Вернуться','callback_data'=>(string)$model['back_callback']]]];
            if ($withinWorkingHours) {
                $text = (string)($managerAvailable ? $model['online_text'] : $model['working_wait_text']);
            } else {
                $text = (string)$model['outside_hours_text'];
            }
            if ($sourceEntry) {
                $text = ManagerRequestService::sourceEntryMessageText($withinWorkingHours);
            }
            $result['sent'] = (bool)IntegrationRegistry::messenger()->sendWithButtons($chatId, $text, $buttons);
        } catch (Throwable $ignored) {
            // A failed acknowledgement must never undo or hide the saved request.
            $result['sent'] = false;
        }
        return $result;
    }
}
