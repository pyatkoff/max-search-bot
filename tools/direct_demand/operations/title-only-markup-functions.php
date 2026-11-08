<?php
declare(strict_types=1);

// Pure helpers. The bounded executor supplies need(bool, string).
// No provider requests, registry changes, filesystem writes, or entry point.

function shortTitleUtf16Boundary(string $text16, int $offset): bool
{
    $units = intdiv(strlen($text16), 2);
    if ($offset < 0 || $offset > $units || strlen($text16) % 2 !== 0) return false;
    if ($offset === 0 || $offset === $units) return true;
    $before = ord($text16[$offset * 2 - 2]) | (ord($text16[$offset * 2 - 1]) << 8);
    $after = ord($text16[$offset * 2]) | (ord($text16[$offset * 2 + 1]) << 8);
    return !($before >= 0xD800 && $before <= 0xDBFF && $after >= 0xDC00 && $after <= 0xDFFF);
}

function shortTitleUtf16Slice(string $text16, int $offset, int $length): string
{
    need($length >= 0 && shortTitleUtf16Boundary($text16, $offset)
        && shortTitleUtf16Boundary($text16, $offset + $length), 'markup_splits_surrogate');
    return mb_convert_encoding(substr($text16, $offset * 2, $length * 2), 'UTF-8', 'UTF-16LE');
}

function shortTitleMarkupFields(array $span, string $platform): void
{
    $type = $span['type'] ?? null;
    need(is_string($type), 'markup_type_invalid');
    if ($platform === 'max') {
        need(in_array($type, ['strong', 'emphasized', 'underline', 'strikethrough', 'monospaced', 'link'], true),
            'max_markup_type_unsupported');
        $allowed = $type === 'link' ? ['type', 'from', 'length', 'url'] : ['type', 'from', 'length'];
        need(array_diff(array_keys($span), $allowed) === [], 'max_markup_fields_unsupported');
        if ($type === 'link') {
            $url = $span['url'] ?? null;
            need(is_string($url) && mb_check_encoding($url, 'UTF-8')
                && preg_match('~\Ahttps?://[^\x00-\x20\x7f]+\z~D', $url) === 1
                && is_string(parse_url($url, PHP_URL_HOST)) && parse_url($url, PHP_URL_HOST) !== '',
                'max_markup_link_unsupported');
        }
        return;
    }
    $simple = ['mention', 'hashtag', 'cashtag', 'bot_command', 'url', 'email', 'phone_number',
        'bold', 'italic', 'underline', 'strikethrough', 'spoiler', 'blockquote', 'expandable_blockquote', 'code'];
    $extra = ['pre'=>['language'], 'text_link'=>['url'], 'text_mention'=>['user'],
        'custom_emoji'=>['custom_emoji_id'], 'date_time'=>['unix_time', 'date_time_format']];
    need(in_array($type, $simple, true) || isset($extra[$type]), 'telegram_markup_type_unsupported');
    need(array_diff(array_keys($span), array_merge(['type', 'offset', 'length'], $extra[$type] ?? [])) === [],
        'telegram_markup_fields_unsupported');
    foreach (['language', 'url', 'custom_emoji_id', 'date_time_format'] as $field) {
        if (array_key_exists($field, $span)) need(is_string($span[$field]) && mb_check_encoding($span[$field], 'UTF-8'),
            'telegram_markup_value_invalid');
    }
    if ($type === 'text_link') need(is_string($span['url'] ?? null) && $span['url'] !== '', 'telegram_markup_link_invalid');
    if ($type === 'text_mention') need(is_array($span['user'] ?? null) && is_int($span['user']['id'] ?? null)
        && $span['user']['id'] > 0, 'telegram_markup_mention_invalid');
    if ($type === 'custom_emoji') need(is_string($span['custom_emoji_id'] ?? null)
        && preg_match('/\A[0-9]+\z/D', $span['custom_emoji_id']) === 1, 'telegram_markup_emoji_invalid');
    if (array_key_exists('unix_time', $span)) need(is_int($span['unix_time']), 'telegram_markup_datetime_invalid');
}

/** Preserve native spans around exactly one terminal first-line suffix deletion. */
function shortTitleProviderText(string $platform, string $oldText, string $newText, array $oldMarkup): array
{
    need(in_array($platform, ['max', 'telegram'], true), 'markup_platform_invalid');
    need(mb_check_encoding($oldText, 'UTF-8') && mb_check_encoding($newText, 'UTF-8'), 'markup_text_invalid');
    need(array_is_list($oldMarkup) && count($oldMarkup) <= 100, 'markup_list_invalid');
    $firstLineBytes = strcspn($oldText, "\r\n");
    $firstLine = substr($oldText, 0, $firstLineBytes);
    $suffix = null;
    foreach ([' с перелётом', ' с перелетом'] as $candidate) {
        if (str_ends_with($firstLine, $candidate)) { $suffix = $candidate; break; }
    }
    need(is_string($suffix) && strlen($firstLine) > strlen($suffix ?? ''), 'first_line_suffix_missing');
    $cutByte = $firstLineBytes - strlen($suffix);
    need($newText === substr($oldText, 0, $cutByte) . substr($oldText, $firstLineBytes),
        'only_first_line_suffix_removal_allowed');
    $old16 = mb_convert_encoding($oldText, 'UTF-16LE', 'UTF-8');
    $new16 = mb_convert_encoding($newText, 'UTF-16LE', 'UTF-8');
    $cutStart = intdiv(strlen(mb_convert_encoding(substr($oldText, 0, $cutByte), 'UTF-16LE', 'UTF-8')), 2);
    $cutEnd = intdiv(strlen(mb_convert_encoding($firstLine, 'UTF-16LE', 'UTF-8')), 2);
    $oldUnits = intdiv(strlen($old16), 2);
    $position = $platform === 'max' ? 'from' : 'offset';
    // MAX documents character offsets without naming their Unicode unit.
    // Before the first astral character, code-point and UTF-16 offsets agree.
    $firstAstral = null;
    if ($platform === 'max' && preg_match('/[\x{10000}-\x{10FFFF}]/u', $oldText, $astral, PREG_OFFSET_CAPTURE) === 1) {
        $firstAstral = mb_strlen(substr($oldText, 0, $astral[0][1]), 'UTF-8');
    }
    $adjusted = [];
    $ranges = [];
    foreach ($oldMarkup as $span) {
        need(is_array($span) && !array_is_list($span), 'markup_span_invalid');
        shortTitleMarkupFields($span, $platform);
        $start = $span[$position] ?? null;
        $length = $span['length'] ?? null;
        need(is_int($start) && is_int($length) && $start >= 0 && $start <= $oldUnits
            && $length > 0 && $length <= $oldUnits - $start, 'markup_range_invalid');
        $end = $start + $length;
        need($platform !== 'max' || $firstAstral === null || $end <= $firstAstral,
            'max_markup_unicode_units_unverified');
        need(shortTitleUtf16Boundary($old16, $start) && shortTitleUtf16Boundary($old16, $end), 'markup_splits_surrogate');
        $ranges[] = [$start, $end];
        $map = static fn(int $x): int => $x <= $cutStart ? $x : ($x >= $cutEnd ? $x - ($cutEnd - $cutStart) : $cutStart);
        $newStart = $map($start);
        $newEnd = $map($end);
        if ($newEnd === $newStart) continue;
        need(shortTitleUtf16Boundary($new16, $newStart) && shortTitleUtf16Boundary($new16, $newEnd), 'markup_splits_surrogate');
        $span[$position] = $newStart;
        $span['length'] = $newEnd - $newStart;
        $adjusted[] = $span;
    }
    if ($platform === 'telegram') return ['markup'=>$adjusted, 'max_html'=>null];
    usort($ranges, static fn(array $a, array $b): int => ($a[0] <=> $b[0]) ?: ($a[1] <=> $b[1]));
    $end = 0;
    foreach ($ranges as $range) {
        need($range[0] >= $end, 'max_overlapping_markup_unsupported');
        $end = $range[1];
    }
    usort($adjusted, static fn(array $a, array $b): int => ($a['from'] <=> $b['from']) ?: ($a['length'] <=> $b['length']));
    $tags = ['strong'=>'b', 'emphasized'=>'i', 'underline'=>'u', 'strikethrough'=>'s', 'monospaced'=>'code', 'link'=>'a'];
    $html = '';
    $cursor = 0;
    foreach ($adjusted as $span) {
        $html .= htmlspecialchars(shortTitleUtf16Slice($new16, $cursor, $span['from'] - $cursor), ENT_QUOTES, 'UTF-8');
        $tag = $tags[$span['type']];
        $attribute = $span['type'] === 'link' ? ' href="' . htmlspecialchars($span['url'], ENT_QUOTES, 'UTF-8') . '"' : '';
        $html .= '<' . $tag . $attribute . '>'
            . htmlspecialchars(shortTitleUtf16Slice($new16, $span['from'], $span['length']), ENT_QUOTES, 'UTF-8')
            . '</' . $tag . '>';
        $cursor = $span['from'] + $span['length'];
    }
    $html .= htmlspecialchars(shortTitleUtf16Slice($new16, $cursor, intdiv(strlen($new16), 2) - $cursor), ENT_QUOTES, 'UTF-8');
    return ['markup'=>$adjusted, 'max_html'=>$html];
}

function shortTitleMarkupCanonicalValue($value)
{
    if (!is_array($value)) return $value;
    if (!array_is_list($value)) ksort($value, SORT_STRING);
    foreach ($value as &$item) $item = shortTitleMarkupCanonicalValue($item);
    unset($item);
    return $value;
}

/** Span ordering and object-key ordering do not change formatting semantics. */
function shortTitleMarkupEqual(array $expected, array $actual): bool
{
    if (!array_is_list($expected) || !array_is_list($actual) || count($expected) !== count($actual)) return false;
    $normalized = [];
    try {
        foreach ([$expected, $actual] as $spans) {
            $items = [];
            foreach ($spans as $span) {
                if (!is_array($span) || array_is_list($span)) return false;
                $items[] = json_encode(shortTitleMarkupCanonicalValue($span),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
            }
            sort($items, SORT_STRING);
            $normalized[] = $items;
        }
    } catch (Throwable $ignored) { return false; }
    return $normalized[0] === $normalized[1];
}
