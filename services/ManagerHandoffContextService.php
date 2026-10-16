<?php

require_once __DIR__ . '/TripStateService.php';
require_once __DIR__ . '/ManagerSummaryService.php';

/**
 * Builds panel-only context for a manager before the first human reply.
 * Nothing produced here is sent to the tourist or persisted as a lead message.
 */
class ManagerHandoffContextService
{
    public static function build(array $aiContext, array $messages, array $handoffContext = []): string
    {
        $state = TripStateService::fromLegacyAiContext($aiContext);
        $summary = trim(ManagerSummaryService::build($state));
        $note = self::latestMeaningfulCustomerNote($messages, (string)($handoffContext['created_at'] ?? ''));
        $transcript = self::customerTranscript($messages);

        if ($note !== '') {
            $summary .= ($summary !== '' ? "\n" : '') . 'Дополнение туриста: ' . $note;
        }

        if (!empty($handoffContext['from_tours'])) {
            $summary .= ($summary !== '' ? "\n" : '')
                . 'Показано/реакция: запрос менеджера сделан после экрана с турами; '
                . 'конкретный просмотр, выбор или реакция не зафиксированы.';
            $summary .= "\nСледующее действие: продолжить от уже показанной выдачи; "
                . 'реакцию на варианты уточнять только если она нужна, чтобы изменить следующее предложение.';
        } else {
            $summary .= ($summary !== '' ? "\n" : '')
                . 'Следующее действие: не повторять известное; уточнить только перечисленное в «Не указано для поиска», '
                . 'если оно есть, и перейти к первому подходящему предложению.';
        }

        $parts = [];
        if ($transcript !== '') {
            $parts[] = "🗣 Что писал турист\n" . $transcript;
        }
        if ($summary !== '') {
            $parts[] = "📋 Сводка по параметрам\n" . $summary;
        }

        return trim(implode("\n\n", $parts));
    }

    /**
     * Reuse public media from the already-authorized, hydrated transcript only.
     * Keep original URLs: the synthetic summary is not a stored message and its
     * ID 0 must never be used to resolve a Telegram file. Do not copy private
     * provider references or manufacture a URL for a token-only attachment.
     */
    public static function customerAttachments(array $messages): array
    {
        $items = [];
        $seen = [];
        $labels = ['image'=>'Фото','video'=>'Видео','audio'=>'Аудио','file'=>'Файл'];
        foreach ($messages as $message) {
            if (!is_array($message)
                || (int)($message['id'] ?? 0) <= 0
                || ($message['direction'] ?? '') !== 'inbound'
                || ($message['sender_type'] ?? '') !== 'customer') {
                continue;
            }
            foreach ((array)($message['attachments'] ?? []) as $index => $attachment) {
                if (!is_array($attachment)) continue;
                $type = $attachment['type'] ?? null;
                $url = $attachment['url'] ?? null;
                if (!is_string($type) || !isset($labels[$type]) || !is_string($url)) continue;
                if (!preg_match('~^(?:https://|media-file\.php\?)~i', $url)
                    || preg_match('/[\x00-\x20\x7f]/', $url)) continue;
                $key = (int)$message['id'] . ':' . (string)$index;
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $name = is_string($attachment['name'] ?? null) ? $attachment['name'] : '';
                $items[] = ['type'=>$type, 'url'=>$url, 'name'=>$name !== '' ? $name : $labels[$type]];
            }
        }
        // Detail is chronological; keep only the twenty most recent attachments.
        return array_slice($items, -20);
    }

    /**
     * Preserve the tourist's own wording for the manager. Callback payloads are
     * implementation details and are deliberately omitted, but genuine typed
     * messages (including short answers such as "Октябрь" or "3х разовое") stay.
     */
    public static function customerTranscript(array $messages): string
    {
        $lines = [];
        foreach ($messages as $message) {
            if ((string)($message['direction'] ?? '') !== 'inbound'
                || (string)($message['sender_type'] ?? '') !== 'customer') {
                continue;
            }

            $text = trim((string)($message['text'] ?? ''));
            if ($text === '' || self::isCallback($text)) continue;

            $text = preg_replace('/\s+/u', ' ', $text) ?: $text;
            $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
            if ($length > 500) {
                $text = function_exists('mb_substr')
                    ? mb_substr($text, 0, 500, 'UTF-8') . '…'
                    : substr($text, 0, 500) . '…';
            }
            $lines[] = '• ' . $text;
        }

        if (count($lines) > 20) {
            $lines = array_slice($lines, -20);
            array_unshift($lines, '• … более ранние сообщения доступны выше в истории');
        }

        return implode("\n", $lines);
    }

    public static function firstReplyGuidance(): string
    {
        return "💬 Первый ответ менеджера\n"
            . "Перед вами есть и дословные сообщения туриста, и структурированная сводка. "
            . "Не просите туриста повторять уже указанные пожелания или параметры. "
            . "Подтвердите, что видите запрос, и переходите к полезному предложению либо уточняйте только то, чего действительно не хватает. "
            . "Если бюджет уже указан в сводке, не спрашивайте его повторно. "
            . "Если бюджет не указан и он действительно нужен до первого предложения, уточните его одним необязательным вопросом; "
            . "если подходящий вариант можно показать без него, не задерживайте предложение. "
            . "Особые пожелания не переспрашивайте, когда они уже есть выше; уточняйте новые только если они реально повлияют на выбор следующего варианта. "
            . "Бюджет и пожелания из сводки учитывайте отдельно: текущая ссылка на сайт не подтверждает их применение как фильтров, поэтому перед первым предложением проверьте, что вариант им соответствует.";
    }

    public static function hasManagerReply(array $messages): bool
    {
        foreach ($messages as $message) {
            if ((string)($message['direction'] ?? '') === 'outbound'
                && (string)($message['sender_type'] ?? '') === 'manager') {
                return true;
            }
        }
        return false;
    }

    private static function latestMeaningfulCustomerNote(array $messages, string $handoffCreatedAt = ''): string
    {
        $handoffAt = self::parseMessageTime($handoffCreatedAt);

        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $message = $messages[$i] ?? [];
            if ((string)($message['direction'] ?? '') !== 'inbound'
                || (string)($message['sender_type'] ?? '') !== 'customer') {
                continue;
            }

            if ($handoffAt !== null) {
                $messageAt = self::parseMessageTime((string)($message['created_at'] ?? ''));
                if ($messageAt === null || $messageAt <= $handoffAt) continue;
            }

            $text = trim((string)($message['text'] ?? ''));
            if ($text === '' || self::isCallback($text) || self::isPhone($text)) continue;

            // Explicit short retractions must stop the backward note heuristic:
            // otherwise an older request can be presented as the current addition.
            if (self::isExplicitNoteRetraction($text)) return '';

            $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
            if ($length < 18 || !preg_match('/\s/u', $text)) continue;

            if ($length > 500) {
                $text = function_exists('mb_substr')
                    ? mb_substr($text, 0, 500, 'UTF-8') . '…'
                    : substr($text, 0, 500) . '…';
            }
            return preg_replace('/\s+/u', ' ', $text) ?: $text;
        }
        return '';
    }

    private static function parseMessageTime(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') return null;

        try {
            return (new DateTimeImmutable($value))->getTimestamp();
        } catch (Throwable $ignored) {
            return null;
        }
    }

    private static function isExplicitNoteRetraction(string $text): bool
    {
        $normalized = trim($text);
        $normalized = function_exists('mb_strtolower')
            ? mb_strtolower($normalized, 'UTF-8')
            : strtolower($normalized);
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?: $normalized;
        $normalized = trim($normalized, " \t\n\r\0\x0B.!?");

        return in_array($normalized, [
            'неважно', 'не важно',
            'уже неважно', 'уже не важно',
            'нет, неважно', 'нет, не важно',
        ], true);
    }

    private static function isCallback(string $text): bool
    {
        $payload = trim($text);
        if (preg_match('/^g1_[a-f0-9]{8}_(.+)$/', $payload, $m)) {
            $payload = trim((string)$m[1]);
        }

        if (in_array($payload, [
            'ai_start','search_options','start_search',
            'manager_request','manager_after_tours','phone_manual',
            'show_tours','tours_checked','tours_found',
            'edit_params','restart','back_phone',
        ], true)) {
            return true;
        }

        foreach (['pick_','adults_','child_','star_','meal_','nights_','month_change_','back_','edit_','finish'] as $prefix) {
            if (strpos($payload, $prefix) === 0) return true;
        }

        return false;
    }

    private static function isPhone(string $text): bool
    {
        return (bool)preg_match('/^(?:\+?7|8)[\s\-\(\)]*(?:\d[\s\-\(\)]*){10}$/u', $text);
    }
}
