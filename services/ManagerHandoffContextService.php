<?php

require_once __DIR__ . '/TripStateService.php';
require_once __DIR__ . '/ManagerSummaryService.php';

/**
 * Builds panel-only context for a manager before the first human reply.
 * Nothing produced here is sent to the tourist or persisted as a lead message.
 */
class ManagerHandoffContextService
{
    public static function build(array $aiContext, array $messages): string
    {
        $state = TripStateService::fromLegacyAiContext($aiContext);
        $summary = trim(ManagerSummaryService::build($state));
        $note = self::latestMeaningfulCustomerNote($messages);
        $transcript = self::customerTranscript($messages);

        if ($note !== '') {
            $summary .= ($summary !== '' ? "\n" : '') . 'Дополнение туриста: ' . $note;
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
            . "Не просите туриста повторять уже указанные пожелания. Подтвердите, что видите запрос, "
            . "и уточните только то, чего действительно не хватает — обычно бюджет или особые пожелания.";
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

    private static function latestMeaningfulCustomerNote(array $messages): string
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $message = $messages[$i] ?? [];
            if ((string)($message['direction'] ?? '') !== 'inbound'
                || (string)($message['sender_type'] ?? '') !== 'customer') {
                continue;
            }

            $text = trim((string)($message['text'] ?? ''));
            if ($text === '' || self::isCallback($text) || self::isPhone($text)) continue;

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

    private static function isCallback(string $text): bool
    {
        return (bool)preg_match('/^[a-z][a-z0-9_]*(?:[-.][a-z0-9_]+)*$/i', $text);
    }

    private static function isPhone(string $text): bool
    {
        return (bool)preg_match('/^(?:\+?7|8)[\s\-\(\)]*(?:\d[\s\-\(\)]*){10}$/u', $text);
    }
}
