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
    public static function apply($chatId, $statusId, $value, bool $observeCustomerInput = false): bool
    {
        if (!class_exists('MaxSearchApi')) return false;

        $applied = (bool)ConversationStateRepository::saveLastValue(
            MaxSearchApi::$HL,
            $chatId,
            $statusId,
            $value,
            MaxSearchApi::$statusStart
        );
        if ($applied && $observeCustomerInput && class_exists('ConversationRecorder', false)
            && method_exists('ConversationRecorder', 'wizardValueApplied')) {
            try {
                $matches = [];
                foreach (['statusCityChoose'=>'city','statusContryChoose'=>'country','statusAdults'=>'adults',
                    'statusChild'=>'children','statusAge'=>'child_ages','statusStars'=>'stars',
                    'statusMeal'=>'meal','statusNights'=>'nights','statusDate'=>'date'] as $property=>$field) {
                    if (property_exists('MaxSearchApi', $property) && (int)MaxSearchApi::${$property} === (int)$statusId) $matches[] = $field;
                }
                if (count($matches) === 1) ConversationRecorder::wizardValueApplied($chatId, $matches[0], (string)$value);
            } catch (Throwable $ignored) {}
        }
        return $applied;
    }
}
