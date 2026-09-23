<?php

declare(strict_types=1);

/** Explicit tour-budget phrases only. Bare ages/dates/nights and offer prices are not budgets. */
final class BudgetParser
{
    public static function parse(string $text): ?array
    {
        if (strlen($text) > 16000 || !preg_match('//u', $text)) return null;
        $text = trim(str_replace(["\u{00A0}", "\u{202F}"], ' ', $text));
        // Questions/quoted examples about an amount are not an instruction to adopt it.
        if (preg_match('/[«»"“”]|https?:\/\/|(?:если|например|допустим|отстань|не\s+хочу|не\s+надо)\b/iu', $text)) return null;
        if (preg_match('/^(?:бюджет\s*(?:пока\s*)?(?:без\s*ограничений|не\s*ограничен)|(?:уберите|снимите)\s*(?:ограничение\s*(?:по\s*)?)?бюджет[ау]?)[.!\s]*$/iu', $text)) {
            return ['changes'=>['budget.max'=>null], 'only_budget'=>true];
        }
        if (preg_match('/^(?:(?:нет|да|имел[аи]? в виду)[,\s]+)?(?:бюджет\s*)?(на всех|общий|на человека|на одного|на каждого|с каждого)[.!\s]*$/iu', $text, $m)) {
            return ['changes'=>['budget.basis'=>self::personal($m[1])?'per_person':'total'], 'only_budget'=>true];
        }
        $number = '(?:[1-9]\d{0,2}(?: \d{3})+|[1-9]\d*)(?:[.,]\d{1,2})?';
        $unit = '(?:тысяч(?:и|у)?|тыс\.?|т\.?\s*р\.?|млн\.?|миллион(?:а|ов)?)';
        $currency = '(?:руб(?:лей|ля|ль)?\.?|RUB|₽|EUR|евро|€|USD|доллар(?:а|ов)?|\$)';
        $qualifier = '(?:на всех|общий|на человека|на одного|на каждого|с каждого)';
        $money = '(?<amount>'.$number.')\s*(?<unit>'.$unit.')?\s*(?<currency>'.$currency.')?(?:\s*(?<basis>'.$qualifier.'))?';
        // A named budget can occur in a rich request. Otherwise require a complete
        // money clause (possibly after a comma/semicolon), not arbitrary price text.
        // Explicit whole-trip basis may be stated either before or after the amount:
        // "бюджет на человека до 90 тыс" and "до 90 тыс на человека" are equivalent.
        $labelled = '/(?<![\pL\pN])(?:общий\s+)?бюджет\s*:?\s*(?:(?<basis_before>'.$qualifier.')\s*)?(?:(?:до|не более|не больше|максимум)\s*)?'.$money.'(?=$|[\s,;.!?])/iu';
        if (preg_match_all($labelled, $text, $matches, PREG_SET_ORDER|PREG_OFFSET_CAPTURE) === 1) {
            $m=$matches[0];
        } else {
            if (count($matches)>1) return null;
            $ceiling = '/(?<![\pL\pN])(?:до|не больше|не более|максимум)\s+'.$money.'(?=$|[\s,;.!?])/iu';
            $count = preg_match_all($ceiling, $text, $matches, PREG_SET_ORDER|PREG_OFFSET_CAPTURE);
            if ($count > 1) return null;
            if ($count === 0) {
                $clause = '/(?:^|[,;]\s*)'.$money.'[.!\s]*(?=$|[,;])/iu';
                if (preg_match_all($clause, $text, $matches, PREG_SET_ORDER|PREG_OFFSET_CAPTURE)!==1) return null;
            }
            $m=$matches[0];
            if (($m['unit'][0]??'')==='' && ($m['currency'][0]??'')==='') return null;
        }
        $span=$m[0][0];$offset=$m[0][1];
        $before=substr($text,0,$offset);$after=substr($text,$offset+strlen($span));
        // Validate the whole money clause, not just a regex prefix. Ambiguous
        // prices stay in the original message; never commit a guessed ceiling.
        $prefixParts=preg_split('/[.!?;\r\n]/u',$before) ?: [];
        $prefix=trim((string)end($prefixParts));
        if (preg_match('/^[^.!?;\r\n]*\?/u',$after)
            || preg_match('/^(?:какой|какая|какую|какие|сколько|хватит|достаточно|можно|подойд[её]т)\b/iu',$prefix)
            || preg_match('/^[.,]\d/u',$after)
            || preg_match('/^\s*,?\s*(?:или|либо)\b/iu',$after)
            || preg_match('/(?<![\pL\pN])'.$number.'\s*(?:'.$unit.'|'.$currency.')(?![\pL\pN])/iu',$before.' '.$after)) return null;
        // A price for just an adult/child/subset is not the total-party default.
        // Check both sides of the captured amount so prefix wording cannot be
        // mistaken for the whole-party product default.
        if (preg_match('/(?:^|[\s,;])(?:бюджет\s*)?(?:на|за|для)\s+(?:одного\s+)?(?:взросл\pL*|реб[её]н\pL*|двоих|троих|четверых|пятерых|\d+)\s*$/iu',$before)
            || preg_match('/^\s*(?:(?:на|за|для)\s+(?:одного\s+)?(?:взросл|реб[её]н|двоих|троих|четверых|пятерых|\d)|взросл|реб[её]н|[A-Z]{3}\b|юан|дирхам|бат\b)/iu',$after)) return null;

        // No partial capture of a range, per-night amount, or negated ceiling.
        if (preg_match('/(?:не\s*|от\s*)$/iu', $before)
            || preg_match('/(?:от|с)\s+'.$number.'\s*(?:'.$unit.')?\s*$/iu', $before)
            || preg_match('/^\s*(?:[-–—]\s*\d|(?:за|в)\s+(?:ноч|день|сут)|на\s+(?:ноч|день|сут))/iu', $after)
            || preg_match('/(?:экскурс|доплат|страхов|депозит|стоимост|стоит)/iu', $before.$after)) return null;
        $basisBefore=$m['basis_before'][0]??'';
        $basisAfter=$m['basis'][0]??'';
        if ($basisBefore!=='' && $basisAfter!=='' && self::personal($basisBefore)!==self::personal($basisAfter)) return null;
        $amount=(float)str_replace([' ', ','], ['', '.'], $m['amount'][0]);
        $scale=$m['unit'][0]??'';
        if (preg_match('/^т/iu',$scale)) $amount*=1000;
        elseif (preg_match('/^(?:млн|миллион)/iu',$scale)) $amount*=1000000;
        $amount=round($amount,2);
        if ($amount<=0 || $amount>90071992547409 || !is_finite($amount)) return null;
        $changes=['budget.max'=>floor($amount)==$amount?(int)$amount:$amount];
        $cur=$m['currency'][0]??'';
        if (preg_match('/р/iu',$scale)) {
            if ($cur!=='' && preg_match('/^(?:RUB|руб(?:лей|ля|ль)?\.?|₽)$/iu',$cur)!==1) return null;
            $cur='RUB';
        }
        if ($cur!=='') {
            $changes['budget.currency']=preg_match('/^(?:EUR|евро|€)$/iu',$cur)?'EUR':(preg_match('/^(?:USD|доллар|\$)/iu',$cur)?'USD':'RUB');
        }
        if ($basisAfter!=='') $changes['budget.basis']=self::personal($basisAfter)?'per_person':'total';
        elseif ($basisBefore!=='') $changes['budget.basis']=self::personal($basisBefore)?'per_person':'total';
        elseif (preg_match('/^общий\s+бюджет/iu',ltrim($span))) $changes['budget.basis']='total';
        $rest=trim($before.' '.$after," \t\r\n,;.!?");
        $only=$rest==='' || preg_match('/^(?:нет|да|тогда|давайте|теперь|лучше)$/iu',$rest)===1;
        return ['changes'=>$changes,'only_budget'=>$only];
    }

    private static function personal(string $value): bool
    {
        return preg_match('/человека|одного|каждого/iu',$value)===1;
    }
}
