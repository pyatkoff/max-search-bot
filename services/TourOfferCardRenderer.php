<?php
declare(strict_types=1);

require_once __DIR__.'/TourChoiceService.php';

/** Pure presentation of an immutable offer; no uploads, sends or price verification. */
final class TourOfferCardRenderer
{
    /**
     * Photo URLs describe explicit source material, not permission to fetch arbitrary URLs.
     * The delivery owner must separately authorize/download them and prepend MAX photo tokens.
     * MAX markup positions use UTF-16 code units, including supplementary characters.
     *
     * @throws InvalidArgumentException
     */
    public static function render(array $snapshot, array $presentation, string $callback): array
    {
        $snapshot = TourChoiceService::normalize($snapshot);
        if ($snapshot === null) throw new InvalidArgumentException('Invalid offer snapshot');
        if (strlen($callback) > 64 || preg_match('/\Atour_like_[1-9][0-9]{0,18}_[a-f0-9]{24}\z/D', $callback) !== 1) {
            throw new InvalidArgumentException('Invalid offer callback');
        }
        $presentation = self::presentation($presentation);
        $compact = ($presentation['layout'] ?? null) === 'compact-v1';
        if ($compact && !$snapshot['is_demo']) throw new InvalidArgumentException('Compact example requires explicit demo conditions');
        $title = $snapshot['hotel'];
        $destination = $presentation['destination'] === '' ? '' : ' · '.$presentation['destination'];
        $heading = $title.$destination;
        $partySize = $snapshot['adults'] + count($snapshot['child_ages']);
        $basis = $partySize === 1 ? 'за одного' : ($partySize === 2 ? 'за двоих' : 'за '.$partySize.' '.self::plural($partySize, ['человек','человека','человек']));
        $price = self::amount($snapshot['amount']).' '.['RUB'=>'₽', 'EUR'=>'€', 'USD'=>'$'][$snapshot['currency']].' '.$basis;
        $conditions = [
            '📅 '.($compact ? 'С '.self::dates($snapshot['date_from'], $snapshot['date_from']) : self::dates($snapshot['date_from'], $snapshot['date_to'])).' · '.$snapshot['nights'].' '.self::plural($snapshot['nights'], ['ночь','ночи','ночей']),
            '✈️ '.$snapshot['departure'].' · '.self::party($snapshot),
            '🛏️ '.$snapshot['room'],
            '🍽️ '.$snapshot['meal'],
        ];
        foreach ($snapshot['flights'] as $flight) $conditions[] = 'Перелёт: '.$flight;
        foreach ($presentation['inclusions'] as $inclusion) $conditions[] = $inclusion;
        $details = implode("\n", $conditions);
        if ($snapshot['rationale'] !== '') $details .= "\n\nПочему этот вариант: ".$snapshot['rationale'];
        if ($snapshot['compromise'] !== '') $details .= "\n\nУчесть: ".$snapshot['compromise'];
        $footer = [];
        if ($snapshot['is_demo']) $footer[] = $compact ? 'Демо: цена и условия — пример.' : 'Демо: даты, цена и условия — пример.';
        if (!$compact) $footer[] = 'Актуальность цены не проверена.';
        if ($presentation['room_illustrative']) $footer[] = $compact ? 'Фото номера — пример интерьера.' : 'Фото номера — пример интерьера отеля.';
        $footer = implode("\n", $footer);
        $plain = $heading."\n".$price."\n".$details."\n\n".$footer;
        $photos = [];
        foreach ($presentation['photos'] as $index=>$photo) {
            $photos[] = ['type'=>'photo', 'photo'=>['type'=>'photo', 'media'=>'attach://photo'.$index]];
        }
        return [
            'snapshot'=>$snapshot,
            'presentation'=>$presentation,
            'telegram'=>[
                'rich_message'=>['blocks'=>[
                    ['type'=>'slideshow', 'blocks'=>$photos],
                    ['type'=>'paragraph', 'text'=>$destination === '' ? ['type'=>'bold', 'text'=>$title] : [['type'=>'bold', 'text'=>$title], $destination]],
                    ['type'=>'paragraph', 'text'=>['type'=>'bold', 'text'=>$price]],
                    ['type'=>'paragraph', 'text'=>$details],
                    ['type'=>'footer', 'text'=>$footer],
                ], 'skip_entity_detection'=>true],
                'reply_markup'=>['inline_keyboard'=>[[['text'=>'Нравится', 'callback_data'=>$callback]]]],
            ],
            'max'=>[
                'text'=>'<b>'.self::html($title).'</b>'.self::html($destination)."\n<b>".self::html($price)."</b>\n".self::html($details)."\n\n".self::html($footer),
                'format'=>'html',
                'attachments'=>[['type'=>'inline_keyboard', 'payload'=>['buttons'=>[[['type'=>'callback', 'text'=>'Нравится', 'payload'=>$callback]]]]]],
            ],
            'plain_text'=>$plain,
            'max_markup'=>[
                ['type'=>'strong', 'from'=>0, 'length'=>self::utf16Length($title)],
                ['type'=>'strong', 'from'=>self::utf16Length($heading."\n"), 'length'=>self::utf16Length($price)],
            ],
        ];
    }

    private static function presentation(array $input): array
    {
        if (array_diff(array_keys($input), ['destination','inclusions','photos','room_illustrative','layout']) !== []
            || !is_bool($input['room_illustrative'] ?? null)) throw new InvalidArgumentException('Invalid offer presentation');
        if (array_key_exists('layout', $input) && $input['layout'] !== 'compact-v1') throw new InvalidArgumentException('Invalid card layout');
        $destination = self::text($input['destination'] ?? '', 200, true);
        $inclusions = $input['inclusions'] ?? [];
        if (!is_array($inclusions) || !array_is_list($inclusions) || count($inclusions) > 6) throw new InvalidArgumentException('Invalid included services');
        foreach ($inclusions as &$inclusion) $inclusion = self::text($inclusion, 300);
        unset($inclusion);
        $photos = $input['photos'] ?? null;
        if (!is_array($photos) || !array_is_list($photos) || count($photos) !== 3) throw new InvalidArgumentException('Three ordered photos required');
        foreach (['cover','beach','room'] as $index=>$role) {
            $photo = $photos[$index];
            if (!is_array($photo) || array_diff(array_keys($photo), ['role','url']) !== [] || ($photo['role'] ?? null) !== $role
                || !self::photoUrl($photo['url'] ?? null)) throw new InvalidArgumentException('Invalid photo source');
            $photos[$index] = ['role'=>$role, 'url'=>$photo['url']];
        }
        $result = ['destination'=>$destination, 'inclusions'=>$inclusions, 'photos'=>$photos, 'room_illustrative'=>$input['room_illustrative']];
        if (array_key_exists('layout', $input)) $result['layout'] = $input['layout'];
        return $result;
    }

    private static function photoUrl($url): bool
    {
        if (!is_string($url) || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)
            || preg_match('/%(?:0[0-9a-f]|1[0-9a-f]|7f)/i', $url)) return false;
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['port']) || isset($parts['fragment']) || !is_string($parts['host'] ?? null)) return false;
        $host = $parts['host'];
        return strlen($host) <= 253 && preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/iD', $host) === 1
            && preg_match('/\.(?:local|localhost|internal|test|invalid|example|onion)\z/iD', $host) !== 1;
    }

    private static function text($value, int $max, bool $empty = false): string
    {
        if (!is_string($value) || preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x1f\x7f]/u', $value)) throw new InvalidArgumentException('Invalid presentation text');
        $value = trim($value);
        if ((!$empty && $value === '') || preg_match_all('/./us', $value) > $max) throw new InvalidArgumentException('Invalid presentation text');
        return $value;
    }

    private static function amount(string $amount): string
    {
        $parts = explode('.', $amount);
        return preg_replace('/\B(?=(?:[0-9]{3})+(?![0-9]))/', ' ', $parts[0]).(isset($parts[1]) ? ','.$parts[1] : '');
    }

    private static function dates(string $from, string $to): string
    {
        $months = [1=>'января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'];
        [$fy,$fm,$fd] = array_map('intval', explode('-', $from));
        [$ty,$tm,$td] = array_map('intval', explode('-', $to));
        if ($from === $to) return $fd.' '.$months[$fm].' '.$fy;
        if ($fy === $ty && $fm === $tm) return $fd.'–'.$td.' '.$months[$tm].' '.$ty;
        if ($fy === $ty) return $fd.' '.$months[$fm].' — '.$td.' '.$months[$tm].' '.$ty;
        return $fd.' '.$months[$fm].' '.$fy.' — '.$td.' '.$months[$tm].' '.$ty;
    }

    private static function party(array $snapshot): string
    {
        $adults = $snapshot['adults'];
        $party = $adults.' '.self::plural($adults, ['взрослый','взрослых','взрослых']);
        $children = count($snapshot['child_ages']);
        if ($children > 0) {
            $ages = array_map(static fn(int $age): string=>$age.' '.self::plural($age, ['год','года','лет']), $snapshot['child_ages']);
            $party .= ' · '.$children.' '.self::plural($children, ['ребёнок','ребёнка','детей']).' ('.implode(', ', $ages).')';
        }
        return $party;
    }

    private static function plural(int $value, array $forms): string
    {
        if ($value % 100 >= 11 && $value % 100 <= 14) return $forms[2];
        return $value % 10 === 1 ? $forms[0] : ($value % 10 >= 2 && $value % 10 <= 4 ? $forms[1] : $forms[2]);
    }

    private static function html(string $value): string { return htmlspecialchars($value, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
    private static function utf16Length(string $value): int { return preg_match_all('/./us', $value) + preg_match_all('/[\x{10000}-\x{10FFFF}]/u', $value); }
}
