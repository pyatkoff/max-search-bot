<?php

/** Pure budget semantics. This class neither asks questions nor converts money. */
final class TripBudgetPolicy
{
    /**
     * Apply one budget change set atomically. Invalid fields must not replace a
     * known amount with a value expressed in an unsupported unit or currency.
     * `extracted` is model provenance, not proof of customer confirmation.
     */
    public static function apply(array $budget, array $changes): array
    {
        $changes = array_intersect_key($changes, array_flip(['budget.max', 'budget.currency', 'budget.basis']));
        if ($changes === []) return $budget;
        $next = $budget;

        if (array_key_exists('budget.max', $changes)) {
            $raw = $changes['budget.max'];
            $amount = self::amount($raw);
            if ($raw !== null && $amount === null) return $budget;
            $next['max'] = $amount;
        }
        if (array_key_exists('budget.currency', $changes)) {
            $currency = self::currency($changes['budget.currency']);
            if ($currency === null) return $budget;
            $next['currency'] = $currency;
        }
        if (array_key_exists('budget.basis', $changes)) {
            $basis = $changes['budget.basis'];
            if (!in_array($basis, ['total', 'per_person'], true)) return $budget;
            $next['basis'] = $basis;
            $next['basis_source'] = 'extracted';
        }

        // A cleared budget must not carry a personal basis into a later amount.
        if (array_key_exists('budget.max', $changes) && $changes['budget.max'] === null) {
            unset($next['basis'], $next['basis_source']);
            return $next;
        }
        if (self::amount($next['max'] ?? null) !== null) {
            if (!array_key_exists('basis', $next)) {
                $next['basis'] = 'total';
                $next['basis_source'] = 'product_default';
            } elseif (!in_array($next['basis'], ['total', 'per_person'], true)) {
                return $budget;
            }
            if (!array_key_exists('currency', $next)) $next['currency'] = 'RUB';
        }
        return $next;
    }

    /** Accept already-extracted amounts, not arbitrary tourist text. */
    public static function amount($value)
    {
        if (is_string($value)) {
            if (!preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/D', $value)) return null;
            $value = (float)$value;
        }
        if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value)
            || $value <= 0 || $value > 90071992547409 || round((float)$value, 2) != $value) {
            return null;
        }
        // Technical precision bound above keeps cents inside the exact-integer
        // range of IEEE doubles; it is not a tourism price/business threshold.
        return floor((float)$value) == $value ? (int)$value : (float)$value;
    }

    /** Currency identifiers only; no exchange rate or currency conversion. */
    public static function currency($value): ?string
    {
        return is_string($value) && preg_match('/^[A-Z]{3}$/D', $value) ? $value : null;
    }

    /** Missing qualifier uses the owner default; an invalid qualifier does not. */
    public static function basis(array $budget): ?string
    {
        if (!array_key_exists('basis', $budget)) return 'total';
        return in_array($budget['basis'], ['total', 'per_person'], true) ? $budget['basis'] : null;
    }
}
