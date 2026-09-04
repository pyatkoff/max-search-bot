<?php

require_once __DIR__ . '/ConversationStateRepository.php';

/**
 * Update a wizard value only when that step already exists in the current
 * dialogue session.
 *
 * This boundary deliberately does not append a status row. It preserves the
 * storage backend's existing value representation and returns false for a
 * missing or pre-start step.
 */
final class ExistingWizardStepApplicationService
{
    public static function apply($chatId, $statusId, $value): bool
    {
        if (!class_exists('MaxSearchApi')) return false;

        return (bool)ConversationStateRepository::saveLastValue(
            MaxSearchApi::$HL,
            $chatId,
            $statusId,
            $value,
            MaxSearchApi::$statusStart
        );
    }
}
