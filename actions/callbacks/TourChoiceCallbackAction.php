<?php
require_once dirname(__DIR__, 2) . '/services/TourChoiceService.php';
require_once dirname(__DIR__, 2) . '/services/ConversationDb.php';
require_once dirname(__DIR__, 2) . '/services/ProjectConfig.php';
require_once dirname(__DIR__, 2) . '/services/ManagerPushService.php';

/** A card reaction records a preference; it never requests a manager or books a tour. */
class TourChoiceCallbackAction
{
    public static function handles(string $payload): bool
    {
        return str_starts_with($payload, 'tour_like_');
    }

    public static function handle(array $incoming, ?callable $save = null, ?callable $notify = null): bool
    {
        if (($incoming['type'] ?? '') !== 'callback'
            || !self::handles((string)($incoming['callback_data'] ?? ''))) return false;

        // The webhook's server configuration owns the project, never the callback body.
        $incoming['project_key'] = ProjectConfig::projectId();
        try {
            if ($save === null && !ConversationDb::isConfigured()) return true;
            $result = $save !== null ? $save($incoming)
                : TourChoiceService::like(ConversationDb::connection(), $incoming);
        } catch (Throwable $ignored) {
            return true;
        }

        if (!empty($result['ok']) && !empty($result['changed']) && (int)($result['conversation_id'] ?? 0) > 0) {
            try {
                if ($notify !== null) $notify((int)$result['conversation_id']);
                else ManagerPushService::notifyConversation((int)$result['conversation_id'], 'Клиенту понравился тур');
            } catch (Throwable $ignored) {
                // The preference is already durable; a push failure must not replay it.
            }
        }
        return true;
    }
}
