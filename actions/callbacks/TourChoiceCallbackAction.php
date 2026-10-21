<?php
require_once dirname(__DIR__, 2) . '/services/TourChoiceService.php';
require_once dirname(__DIR__, 2) . '/services/ConversationDb.php';
require_once dirname(__DIR__, 2) . '/services/ProjectConfig.php';
require_once dirname(__DIR__, 2) . '/services/ManagerPushService.php';
require_once dirname(__DIR__, 2) . '/services/IntegrationRegistry.php';

/** A card reaction records a preference; it never requests a manager or books a tour. */
class TourChoiceCallbackAction
{
    public static function handles(string $payload): bool
    {
        return str_starts_with($payload, 'tour_like_');
    }

    public static function handle(array $incoming, ?callable $save = null, ?callable $notify = null, ?callable $feedback = null): bool
    {
        if (($incoming['type'] ?? '') !== 'callback'
            || !self::handles((string)($incoming['callback_data'] ?? ''))) return false;

        // The webhook's server configuration owns the project, never the callback body.
        $incoming['project_key'] = ProjectConfig::projectId();
        try {
            $result = $save !== null ? $save($incoming)
                : (ConversationDb::isConfigured() ? TourChoiceService::like(ConversationDb::connection(), $incoming) : ['ok'=>false]);
        } catch (Throwable $ignored) {
            $result = ['ok'=>false];
        }

        if (!empty($result['ok']) && !empty($result['changed']) && (int)($result['conversation_id'] ?? 0) > 0) {
            // Confirmation follows the durable save and precedes the independent push.
            self::feedback($incoming, '✅ Выбор сохранён. Менеджер увидит этот тур и его условия.', $feedback);
            try {
                if ($notify !== null) $notify((int)$result['conversation_id']);
                else ManagerPushService::notifyConversation((int)$result['conversation_id'], 'Клиенту понравился тур');
            } catch (Throwable $ignored) {
                // The preference is already durable; a push failure must not replay it.
            }
        } elseif (empty($result['ok'])) {
            self::feedback($incoming, 'Не удалось сохранить выбор. Попробуйте нажать «Нравится» ещё раз.', $feedback);
        }
        // A duplicate remains acknowledged without another message or manager push.
        return true;
    }

    private static function feedback(array $incoming, string $text, ?callable $send): void
    {
        try {
            if ($send !== null) $send($incoming, $text);
            else IntegrationRegistry::messenger()->send($incoming['user']['chat_id'], $text);
        } catch (Throwable $ignored) {
            // A transport failure cannot undo or replay the saved preference.
        }
    }
}
