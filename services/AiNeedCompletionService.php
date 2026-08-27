<?php
require_once __DIR__ . '/NeedApplicationService.php';
require_once __DIR__ . '/NeedProgressionService.php';

/**
 * Canonical AI boundary for applying resolved need parameters and advancing dialogue.
 * Keeps AiMessageHandler from owning the apply -> read-missing -> progress sequence.
 */
class AiNeedCompletionService
{
    public static function applyAndAdvance($chatId, array $params, array $questionOptions = []): array
    {
        $applied = NeedApplicationService::applyParameters($chatId, $params);
        $progress = NeedProgressionService::advance($chatId, $questionOptions);

        return [
            'applied' => $applied,
            'missing' => is_array($progress['missing'] ?? null) ? $progress['missing'] : [],
            'complete' => !empty($progress['complete']),
            'next_field' => $progress['next_field'] ?? null,
        ];
    }
}
