<?php
require_once __DIR__ . '/NeedApplicationService.php';
require_once __DIR__ . '/NeedProgressionService.php';

/**
 * Canonical AI boundary for applying resolved need parameters and advancing dialogue.
 * Keeps AiMessageHandler from owning the apply -> read-missing -> progress sequence.
 */
class AiNeedCompletionService
{
    /**
     * Apply resolved parameters and advance the dialogue once.
     *
     * Result contract: `applied` is always a map of successfully applied
     * field names to true. This is shared with resolveApplyAndAdvance().
     *
     * @return array{applied: array<string, bool>, missing: array, complete: bool, next_field: ?string}
     */
    public static function applyAndAdvance($chatId, array $params, array $questionOptions = []): array
    {
        $applied = NeedApplicationService::applyParameters($chatId, $params);
        $progress = NeedProgressionService::advance($chatId, $questionOptions);

        return self::result($applied, $progress);
    }

    /**
     * Resolve one field, apply it and advance only after a successful write.
     *
     * `applied` deliberately replaces NeedApplicationService's boolean with
     * the same field map returned by applyAndAdvance().
     *
     * @return array{recognized: bool, value: mixed, source: string, confidence: float, applied: array<string, bool>, advanced: bool, missing: array, complete: bool, next_field: ?string}
     */
    public static function resolveApplyAndAdvance(
        $chatId,
        string $field,
        string $text,
        array $context = [],
        array $questionOptions = []
    ): array {
        $resolution = NeedApplicationService::resolveAndApply($chatId, $field, $text, $context);

        if (empty($resolution['recognized']) || empty($resolution['applied'])) {
            return array_merge($resolution, [
                'applied' => [],
                'advanced' => false,
                'missing' => [],
                'complete' => false,
                'next_field' => null,
            ]);
        }

        $progress = NeedProgressionService::advance($chatId, $questionOptions);

        return array_merge($resolution, self::result(
            [$field => true],
            $progress
        ), ['advanced' => true]);
    }

    private static function result(array $applied, array $progress): array
    {
        return [
            'applied' => $applied,
            'missing' => is_array($progress['missing'] ?? null) ? $progress['missing'] : [],
            'complete' => !empty($progress['complete']),
            'next_field' => $progress['next_field'] ?? null,
        ];
    }
}
