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
            self::feedback($incoming, self::savedFeedback($result, true), $feedback);
            try {
                if ($notify !== null) $notify((int)$result['conversation_id']);
                else ManagerPushService::notifyConversation((int)$result['conversation_id'], 'Клиенту понравился тур');
            } catch (Throwable $ignored) {
                // The preference is already durable; a push failure must not replay it.
            }
        } elseif (!empty($result['ok']) && (int)($result['conversation_id'] ?? 0) > 0) {
            self::feedback($incoming, self::savedFeedback($result, false), $feedback);
        } elseif (empty($result['ok'])) {
            self::feedback($incoming, 'Не удалось сохранить выбор. Попробуйте нажать «Нравится» ещё раз.', $feedback);
        }
        // Repeated presses report the saved state without another save or manager push.
        return true;
    }

    private static function savedFeedback(array $result, bool $changed): string
    {
        $offer = $result['offer'] ?? null;
        $snapshot = is_array($offer) ? ($offer['snapshot'] ?? null) : null;
        $hotel = is_array($snapshot) ? ($snapshot['hotel'] ?? null) : null;
        if (!is_string($hotel) || strlen($hotel) > 764 || preg_match('//u', $hotel) !== 1
            || preg_match('/[\x00-\x1f\x7f]/u', $hotel) || preg_match_all('/./us', $hotel) > 191) $hotel = '';
        // Both default transports interpret HTML; only the canonical saved label is dynamic.
        $hotel = htmlspecialchars(trim($hotel), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $text = $changed ? ($hotel === '' ? '✅ Выбор сохранён.' : '✅ Сохранён вариант: '.$hotel.'.')
            : ($hotel === '' ? 'Этот вариант уже сохранён.' : 'Этот вариант уже сохранён: '.$hotel.'.');
        return $text.($changed ? "\nМенеджер увидит этот тур и его условия." : '')
            ."\nМожно отметить другие варианты или написать вопрос.";
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
