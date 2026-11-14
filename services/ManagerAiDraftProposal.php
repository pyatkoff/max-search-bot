<?php

declare(strict_types=1);

require_once __DIR__.'/ManagerPromiseCandidates.php';

/** Pure wire codec. Historical eight-field proposals keep their exact canonical bytes. */
final class ManagerAiDraftProposal
{
    public const MAX_DRAFT_CHARACTERS = 3000;
    private const LEGACY_FIELDS = ['ok','should_draft','draft','next_action','reason','checks','evidence_message_ids','history_incomplete'];
    private const FIELDS = [...self::LEGACY_FIELDS,'manager_promises'];
    private const ACTIONS = ['clarify','answer_question','check_offer','prepare_selection','no_contact'];

    /** Missing manager_promises means legacy, never an implicit stored empty field. */
    public static function fields(array $proposal): ?array
    {
        $fields = array_key_exists('manager_promises', $proposal) ? self::FIELDS : self::LEGACY_FIELDS;
        return !array_is_list($proposal) && count($proposal) === count($fields)
            && !array_diff($fields, array_keys($proposal)) ? $fields : null;
    }

    /** Ordering is separate from semantic validation, as in the historical hash reader. */
    public static function ordered(array $proposal): ?array
    {
        $fields = self::fields($proposal);
        if ($fields === null) return null;
        $ordered = [];
        foreach ($fields as $field) $ordered[$field] = $proposal[$field];
        return $ordered;
    }

    public static function json(array $proposal): ?string
    {
        $ordered = self::ordered($proposal);
        if ($ordered === null) return null;
        try { return json_encode($ordered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
        catch (Throwable $ignored) { return null; }
    }

    /** Shared shape validation; current source authority is checked by the draft service. */
    public static function valid(mixed $value, ?array $allowedIds = null): bool
    {
        if (!is_array($value) || self::fields($value) === null
            || $value['ok'] !== true || !is_bool($value['should_draft']) || !is_bool($value['history_incomplete'])
            || !is_string($value['next_action']) || !in_array($value['next_action'], self::ACTIONS, true)
            || !self::text($value['reason'], 1, 1000)
            || !is_array($value['checks']) || !array_is_list($value['checks']) || count($value['checks']) > 10
            || !is_array($value['evidence_message_ids']) || !array_is_list($value['evidence_message_ids'])
            || !$value['evidence_message_ids'] || count($value['evidence_message_ids']) > 20) return false;
        if ($value['should_draft']) {
            if ($value['next_action'] === 'no_contact' || !self::text($value['draft'], 1, self::MAX_DRAFT_CHARACTERS)) return false;
        } elseif ($value['draft'] !== null) return false;
        foreach ($value['checks'] as $check) if (!self::text($check, 1, 400)) return false;
        $seen = [];
        foreach ($value['evidence_message_ids'] as $id) {
            if (!is_int($id) || $id <= 0 || isset($seen[$id]) || ($allowedIds !== null && !isset($allowedIds[$id]))) return false;
            $seen[$id] = true;
        }
        if (array_key_exists('manager_promises', $value)) {
            if (!ManagerPromiseCandidates::validShape($value['manager_promises'])) return false;
            foreach ($value['manager_promises'] as $promise) {
                if ($allowedIds !== null && !isset($allowedIds[$promise['source_message_id']])) return false;
            }
        }
        return true;
    }

    private static function text(mixed $value, int $minimum, int $maximum): bool
    {
        return is_string($value) && strlen($value) <= $maximum * 4 && preg_match('//u', $value) === 1
            && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 0
            && preg_match_all('/./us', $value) <= $maximum && preg_match_all('/./us', trim($value)) >= $minimum;
    }
}
