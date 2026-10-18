<?php

declare(strict_types=1);

/** Shared projection for transcript and selection fields; no I/O or metadata. */
final class ManagerAiTextRedactor
{
    public static function redact(string $text, bool &$selectionUnread, bool &$linksRedacted): string
    {
        $text = preg_replace_callback('~https?://[^\s<>"\x27]+~iu', static function (array $match) use (&$selectionUnread, &$linksRedacted): string {
            $linksRedacted = true;
            if (preg_match('~\Ahttps?://(?:www\.)?qui-quo\.ru(?:[/:?#]|\z)~i', $match[0])) {
                $selectionUnread = true;
                return '[ссылка на подборку не прочитана]';
            }
            return '[ссылка не прочитана]';
        }, $text) ?? $text;
        $text = preg_replace('/[A-Z0-9.!#$%&\x27*+\/=\?^_`{|}~-]+@[A-Z0-9](?:[A-Z0-9.-]*[A-Z0-9])?\.[A-Z]{2,}/iu', '[email скрыт]', $text) ?? $text;
        $input = $text;
        $text = preg_replace_callback('/(?<![\pL\pN])(?:\+?[78][ -]?(?:\([0-9]{3}\)|[0-9]{3})[ -]?[0-9]{3}[ -]?[0-9]{2}[ -]?[0-9]{2}|\+(?:[0-9][ ()-]*){9,14}[0-9])(?![\pL\pN])/u', static function (array $match) use ($input): string {
            [$value, $offset] = $match[0];
            $digits = preg_replace('/\D/', '', $value) ?? '';
            $count = strlen($digits);
            $before = substr($input, 0, $offset);
            $after = substr($input, $offset + strlen($value));
            $money = preg_match('/(?:бюджет|стоимость|цена|цену|сумма|сумму|доплата|доплату|итого|до)\s*[:=]?\s*\z/iu', $before)
                || preg_match('/\A\s*(?:₽|руб|RUB|USD|EUR|доллар|евро)/iu', $after);
            if ($money) return $value;
            return $count === 11 || ($value[0] === '+' && $count >= 10 && $count <= 15) ? '[телефон скрыт]' : $value;
        }, $text, -1, $count, PREG_OFFSET_CAPTURE) ?? $text;
        return $text;
    }
}
