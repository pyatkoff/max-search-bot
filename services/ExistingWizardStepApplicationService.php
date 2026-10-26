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
                if (count($matches) === 1) {
                    $recorded = ConversationRecorder::wizardValueApplied($chatId, $matches[0], (string)$value);
                    if (!$recorded) self::logReceiptOmission($matches[0]);
                } else {
                    self::logReceiptOmission('unknown', 'status_mapping_ambiguous');
                }
            } catch (Throwable $ignored) {}
        }
        return $applied;
    }

    private static function logReceiptOmission(string $field, ?string $reason = null): void
    {
        if (!class_exists('DiagnosticLogger')) return;
        if ($reason === null && method_exists('ConversationRecorder', 'wizardReceiptReason')) {
            $reason = ConversationRecorder::wizardReceiptReason();
        }
        $allowed = ['input_unavailable','duplicate_input','project_mismatch','chat_mismatch',
            'unsupported_field','invalid_value','write_failed','status_mapping_ambiguous'];
        if (!in_array($reason, $allowed, true)) $reason = 'input_unavailable';
        try {
            DiagnosticLogger::log('conversation_mirror', 'wizard_receipt_omitted',
                ['reason'=>$reason, 'field'=>$field], null, 'warning');
        } catch (Throwable $ignored) {}
    }
}
