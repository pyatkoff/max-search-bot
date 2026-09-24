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

        // An explicit clear starts the next budget from a clean semantic state:
        // neither a personal basis nor a hidden old currency may leak into a
        // later unqualified amount.
        if (array_key_exists('budget.max', $changes) && $changes['budget.max'] === null) {
            unset($next['currency'], $next['basis'], $next['basis_source']);
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

    /** Start-row envelope. Unknown nonempty values are never assumed to be ours. */
    public static function fromStartValue($raw): ?array
    {
        if ($raw === null || $raw === '') return [];
        if (!is_string($raw) || strlen($raw)>2048) return null;
        $value=json_decode($raw,true);
        if (!is_array($value) || ($value['kind']??null)!=='trip_budget_v1'
            || count($value)!==2 || !is_array($value['budget']??null)) return null;
        $budget=$value['budget'];
        if (array_diff(array_keys($budget),['max','currency','basis','basis_source'])!==[]
            || !array_key_exists('max',$budget)
            || ($budget['max']!==null && self::amount($budget['max'])===null)
            || self::currency($budget['currency']??'RUB')===null
            || (array_key_exists('basis',$budget) && self::basis($budget)===null)
            || (array_key_exists('basis_source',$budget) && !in_array($budget['basis_source'],['extracted','product_default'],true))) return null;
        return $budget;
    }

    public static function toStartValue(array $budget): string
    {
        $raw=json_encode(['kind'=>'trip_budget_v1','budget'=>$budget],JSON_THROW_ON_ERROR);
        if (self::fromStartValue($raw)===null) throw new InvalidArgumentException('invalid_budget_envelope');
        return $raw;
    }

}
