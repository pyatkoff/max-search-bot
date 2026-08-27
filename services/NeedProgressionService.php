<?php
require_once __DIR__ . '/DialogueView.php';
require_once __DIR__ . '/MissingFieldQuestionService.php';

/**
 * Canonical progression boundary after trip-need state has been mutated.
 *
 * The ordering of missing fields remains owned by MaxSearchApi for now; this
 * service owns the single decision between completing into the check screen
 * and asking the next deterministic missing-field question.
 */
class NeedProgressionService
{
    public static function advance($chatId, array $questionOptions = []): array
    {
        $missing = class_exists('MaxSearchApi')
            ? MaxSearchApi::getAiMissingFields($chatId)
            : [];

        if (empty($missing)) {
            DialogueView::check($chatId);
            return [
                'complete' => true,
                'missing' => [],
                'next_field' => null,
            ];
        }

        MissingFieldQuestionService::sendForMissing($chatId, $missing, $questionOptions);
        return [
            'complete' => false,
            'missing' => $missing,
            'next_field' => (string)$missing[0],
        ];
    }
}
