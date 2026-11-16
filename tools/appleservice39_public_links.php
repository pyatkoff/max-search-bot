<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');

/** Fixed one-post content operation. No batch, arbitrary target, credential or retry input. */
const PL_ROOT = '/var/www/anytoour/data/www/app.anytoour.ru';
const PL_APP = 'e64ca705fb91463362b73c3531c162af77503f35';
const PL_CHANNEL = '-80917954947727';
const PL_PIN = 'mid.ffffb667d20a157101a1bd6d233963da';
const PL_POST = 'model-iphone-x';
const PL_KEY = 'pl1-model-iphone-x';
const PL_REVISION = 'public-links-canary-20261116';
const PL_PACKAGE_SHA256 = '7c65b1e87704603f30241329a80ab83a973c852128e9a90fe96224ac46066809';
const PL_PACKAGE_BASE64 = 'eyJzY2hlbWFfdmVyc2lvbiI6MSwicmV2aXNpb24iOiJwdWJsaWMtbGlua3MtY2FuYXJ5LTIwMjYxMTE2IiwicHJpb3JfbmF0aXZlX2F1ZGl0X3J1biI6NDM2MTY3OTYxOTksInByaW9yX3ZlcmlmaWVkX29wZXJhdGlvbnMiOjM2MiwidGFyZ2V0cyI6eyJtb2RlbC1pcGhvbmUteCI6eyJwb3N0X2lkIjoibW9kZWwtaXBob25lLXgiLCJtaWQiOiJtaWQuZmZmZmI2NjdkMjBhMTU3MTAxYTFiZDZjZmNiMTBhNGEiLCJ1cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FwcGxlc2VydmljZTM5cnUvQWFHOWJQeXhDa28iLCJwaG90b19pZCI6IjQ2NzUwODk4MjU3IiwiY3RhX2xhYmVsIjoi0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgNC10LzQvtC90YLQsCIsImN0YV91cmwiOiJodHRwczovL21heC5ydS9pZDM5MDUyMTEyOTA2Ml9ib3Q/c3RhcnQ9YXMzOV9tb2RlbC1pcGhvbmUteCIsImJlZm9yZV90ZXh0Ijoi0KDQtdC80L7QvdGCIGlQaG9uZSBYINCyINCa0LDQu9C40L3QuNC90LPRgNCw0LTQtVxuXG7QkNC60LrRg9C80YPQu9GP0YLQvtGALCDRgdGC0LXQutC70L4g0LjQu9C4INC00LjRgdC/0LvQtdC5IOKAlCDQstGL0LHQtdGA0LjRgtC1INC/0L7QtNGF0L7QtNGP0YnRg9GOINGD0YHQu9GD0LPRgyDQv9C+INGB0YHRi9C70LrQsNC8INC90LjQttC1LlxuXG7Ql9Cw0LzQtdC90LAg0LDQutC60YPQvNGD0LvRj9GC0L7RgNCwINC/0L4g0L/RgNCw0LnRgdGDIOKAlCAzIDUwMCDigr0uINCU0LjQsNCz0L3QvtGB0YLQuNC60LAg0LHQtdGB0L/Qu9Cw0YLQvdCw0Y8uINCQ0LrRgtGD0LDQu9GM0L3Rg9GOINGG0LXQvdGDINC4INC90LDQu9C40YfQuNC1INC00LXRgtCw0LvQuCDRg9GC0L7Rh9C90LjQvCDQtNC+INGA0LXQvNC+0L3RgtCwLlxu0JfQsNC80LXQvdCwINGB0YLQtdC60LvQsCDQsdC10Lcg0LTQuNGB0L/Qu9C10Y86INCh0YLQvtC40LzQvtGB0YLRjCDQv9C+INC/0YDQsNC50YHRgyDigJQgNSAwMDAg4oK9LiDQkNC60YLRg9Cw0LvRjNC90YPRjiDRhtC10L3RgyDQuCDQvdCw0LvQuNGH0LjQtSDQtNC10YLQsNC70Lgg0YPRgtC+0YfQvdC40Lwg0LTQviDRgNC10LzQvtC90YLQsC5cbtCX0LDQvNC10L3QsCDQtNC40YHQv9C70LXRjzog0J7RgNC40LPQuNC90LDQu9GM0L3Ri9C5INC00LjRgdC/0LvQtdC5IOKAlCAxMiAwMDAg4oK9LiDQkNC90LDQu9C+0LMg4oCUIDYgMDAwIOKCvS4g0KbQtdC90Ysg0L/QviDQv9GA0LDQudGB0YMuINCj0YLQvtGH0L3QuNC8INC90LDQu9C40YfQuNC1INC4INGD0YHQu9C+0LLQuNGPINCy0YvQsdGA0LDQvdC90L7Qs9C+INCy0LDRgNC40LDQvdGC0LAuXG5cbtCT0LDRgNCw0L3RgtC40Y8g4oCUIDMg0LzQtdGB0Y/RhtCwLlxuXG7QndCw0LbQvNC40YLQtSDCq9Cj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YDQtdC80L7QvdGC0LDCuywg0YfRgtC+0LHRiyDQvtC/0LjRgdCw0YLRjCDQv9GA0L7QsdC70LXQvNGDINC4INGB0L7Qs9C70LDRgdC+0LLQsNGC0Ywg0YDQtdC80L7QvdGCLiDQndCw0LvQuNGH0LjQtSDQtNC10YLQsNC70LgsINC+0LrQvtC90YfQsNGC0LXQu9GM0L3Rg9GOINGB0YLQvtC40LzQvtGB0YLRjCDQuCDRgdGA0L7QuiDRg9GC0L7Rh9C90LjQvCDQtNC+INC90LDRh9Cw0LvQsCDRgNCw0LHQvtGCLlxuXG5BcHBsZVNlcnZpY2UzOSDCtyDQmtCw0LvQuNC90LjQvdCz0YDQsNC0XG7Qm9C10L3QuNC90YHQutC40Lkg0L/RgNC+0YHQv9C10LrRgiwgMTggwrcgNCDRjdGC0LDQtiwg0L7RhNC40YEgNDA0XG5cbtCS0YvQsdC10YDQuNGC0LUg0YDQtdC80L7QvdGCXG7QlNC40YHQv9C70LXQuVxu0KHRgtC10LrQu9C+INGN0LrRgNCw0L3QsFxu0JDQutC60YPQvNGD0LvRj9GC0L7RgFxu0JzQtdC90Y4g0YDQtdC80L7QvdGC0LAiLCJiZWZvcmVfbWFya3VwIjpbeyJ0eXBlIjoic3Ryb25nIiwiZnJvbSI6MCwibGVuZ3RoIjozMH0seyJ0eXBlIjoibGluayIsImZyb20iOjczNSwibGVuZ3RoIjo3LCJ1cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FwcGxlc2VydmljZTM5cnUvQWFHOWJMN2tXZjQifSx7InR5cGUiOiJsaW5rIiwiZnJvbSI6NzQzLCJsZW5ndGgiOjEzLCJ1cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FwcGxlc2VydmljZTM5cnUvQWFHOWJMb09FMzgifSx7InR5cGUiOiJsaW5rIiwiZnJvbSI6NzU3LCJsZW5ndGgiOjExLCJ1cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FwcGxlc2VydmljZTM5cnUvQWFHOUhRYnNYdmsifSx7InR5cGUiOiJsaW5rIiwiZnJvbSI6NzY5LCJsZW5ndGgiOjEyLCJ1cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FwcGxlc2VydmljZTM5cnUvQWFHOWJTTTVZOW8ifV0sImJlZm9yZV90ZXh0X3NoYTI1NiI6Ijc4MmUxMDdjNDFkZTZjNzA0NzZlZTZjMDk4YTcyYTBlYWI0MWVmNTBlMDJhZmYxY2NhNGFkMDVlM2IyODljMTMiLCJiZWZvcmVfbWFya3VwX3NoYTI1NiI6IjkzZjYzZWIwZjZjMmVjY2ZiODhjNWIzZDNiMzQwYmM5ZWU0ZTFiYTRiZWUyZTc3ZTBjYjg2NDllMDM2MzRiZmMiLCJhZnRlciI6eyJib2R5Ijp7InRleHQiOiLQoNC10LzQvtC90YIgaVBob25lIFgg0LIg0JrQsNC70LjQvdC40L3Qs9GA0LDQtNC1XG5cbtCQ0LrQutGD0LzRg9C70Y/RgtC+0YAg4oCUIDMgNTAwIOKCvS4g0JTQuNCw0LPQvdC+0YHRgtC40LrQsCDQsdC10YHQv9C70LDRgtC90LDRjy5cbtCh0YLQtdC60LvQviDQsdC10Lcg0LfQsNC80LXQvdGLINC00LjRgdC/0LvQtdGPIOKAlCA1IDAwMCDigr0uXG7QlNC40YHQv9C70LXQuTog0L7RgNC40LPQuNC90LDQu9GM0L3Ri9C5IOKAlCAxMiAwMDAg4oK9LCDQsNC90LDQu9C+0LMg4oCUIDYgMDAwIOKCvS5cblxu0JPQsNGA0LDQvdGC0LjRjyDigJQgMyDQvNC10YHRj9GG0LAuINCd0LDQu9C40YfQuNC1INC00LXRgtCw0LvQuCwg0YHRgtC+0LjQvNC+0YHRgtGMINC4INGB0YDQvtC6INGB0L7Qs9C70LDRgdGD0LXQvCDQtNC+INGA0LXQvNC+0L3RgtCwLlxuXG5BcHBsZVNlcnZpY2UzOSDCtyDQm9C10L3QuNC90YHQutC40Lkg0L/RgNC+0YHQv9C10LrRgiwgMThcbjQg0Y3RgtCw0LYsINC+0YTQuNGBIDQwNFxuXG7QndCw0LbQvNC40YLQtSDCq9Cj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YDQtdC80L7QvdGC0LDCuy5cblxu0JLRi9Cx0LXRgNC40YLQtSDRgNC10LzQvtC90YJcbtCU0LjRgdC/0LvQtdC5XG5odHRwczovL21heC5ydS9jaGFubmVsX2FwcGxlc2VydmljZTM5cnUvQWFHOWJMN2tXZjRcbtCh0YLQtdC60LvQviDRjdC60YDQsNC90LBcbmh0dHBzOi8vbWF4LnJ1L2NoYW5uZWxfYXBwbGVzZXJ2aWNlMzlydS9BYUc5YkxvT0UzOFxu0JDQutC60YPQvNGD0LvRj9GC0L7RgFxuaHR0cHM6Ly9tYXgucnUvY2hhbm5lbF9hcHBsZXNlcnZpY2UzOXJ1L0FhRzlIUWJzWHZrXG7QnNC10L3RjiDRgNC10LzQvtC90YLQsFxuaHR0cHM6Ly9tYXgucnUvY2hhbm5lbF9hcHBsZXNlcnZpY2UzOXJ1L0FhRzliU001WTlvIiwibm90aWZ5IjpmYWxzZX0sIm5hdGl2ZV90ZXh0Ijoi0KDQtdC80L7QvdGCIGlQaG9uZSBYINCyINCa0LDQu9C40L3QuNC90LPRgNCw0LTQtVxuXG7QkNC60LrRg9C80YPQu9GP0YLQvtGAIOKAlCAzIDUwMCDigr0uINCU0LjQsNCz0L3QvtGB0YLQuNC60LAg0LHQtdGB0L/Qu9Cw0YLQvdCw0Y8uXG7QodGC0LXQutC70L4g0LHQtdC3INC30LDQvNC10L3RiyDQtNC40YHQv9C70LXRjyDigJQgNSAwMDAg4oK9Llxu0JTQuNGB0L/Qu9C10Lk6INC+0YDQuNCz0LjQvdCw0LvRjNC90YvQuSDigJQgMTIgMDAwIOKCvSwg0LDQvdCw0LvQvtCzIOKAlCA2IDAwMCDigr0uXG5cbtCT0LDRgNCw0L3RgtC40Y8g4oCUIDMg0LzQtdGB0Y/RhtCwLiDQndCw0LvQuNGH0LjQtSDQtNC10YLQsNC70LgsINGB0YLQvtC40LzQvtGB0YLRjCDQuCDRgdGA0L7QuiDRgdC+0LPQu9Cw0YHRg9C10Lwg0LTQviDRgNC10LzQvtC90YLQsC5cblxuQXBwbGVTZXJ2aWNlMzkgwrcg0JvQtdC90LjQvdGB0LrQuNC5INC/0YDQvtGB0L/QtdC60YIsIDE4XG40INGN0YLQsNC2LCDQvtGE0LjRgSA0MDRcblxu0J3QsNC20LzQuNGC0LUgwqvQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGA0LXQvNC+0L3RgtCwwrsuXG5cbtCS0YvQsdC10YDQuNGC0LUg0YDQtdC80L7QvdGCXG7QlNC40YHQv9C70LXQuVxuaHR0cHM6Ly9tYXgucnUvY2hhbm5lbF9hcHBsZXNlcnZpY2UzOXJ1L0FhRzliTDdrV2Y0XG7QodGC0LXQutC70L4g0Y3QutGA0LDQvdCwXG5odHRwczovL21heC5ydS9jaGFubmVsX2FwcGxlc2VydmljZTM5cnUvQWFHOWJMb09FMzhcbtCQ0LrQutGD0LzRg9C70Y/RgtC+0YBcbmh0dHBzOi8vbWF4LnJ1L2NoYW5uZWxfYXBwbGVzZXJ2aWNlMzlydS9BYUc5SFFic1h2a1xu0JzQtdC90Y4g0YDQtdC80L7QvdGC0LBcbmh0dHBzOi8vbWF4LnJ1L2NoYW5uZWxfYXBwbGVzZXJ2aWNlMzlydS9BYUc5YlNNNVk5byIsIm5hdGl2ZV9tYXJrdXAiOlt7InR5cGUiOiJsaW5rIiwiZnJvbSI6MzY1LCJsZW5ndGgiOjUxLCJ1cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FwcGxlc2VydmljZTM5cnUvQWFHOWJMN2tXZjQifSx7InR5cGUiOiJsaW5rIiwiZnJvbSI6NDMxLCJsZW5ndGgiOjUxLCJ1cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FwcGxlc2VydmljZTM5cnUvQWFHOWJMb09FMzgifSx7InR5cGUiOiJsaW5rIiwiZnJvbSI6NDk1LCJsZW5ndGgiOjUxLCJ1cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FwcGxlc2VydmljZTM5cnUvQWFHOUhRYnNYdmsifSx7InR5cGUiOiJsaW5rIiwiZnJvbSI6NTYwLCJsZW5ndGgiOjUxLCJ1cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FwcGxlc2VydmljZTM5cnUvQWFHOWJTTTVZOW8ifV0sImF1dG9saW5rX2FjY2VwdGFuY2VfcmVxdWlyZWQiOnRydWUsImNvcHlfc2hvcnRlbmVkIjp0cnVlfX19fQ==';

function pl_check(bool $ok, string $reason): void { if (!$ok) throw new RuntimeException($reason); }
function pl_json($value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
function pl_canonical($value) {
    if (!is_array($value)) return $value;
    if (!array_is_list($value)) ksort($value);
    foreach ($value as &$item) $item = pl_canonical($item);
    unset($item); return $value;
}
function pl_digest($value): string { return hash('sha256', pl_json(pl_canonical($value))); }
function pl_emit(array $value): void { echo pl_json($value).PHP_EOL; }
function pl_invoke(object $owner, string $method, ...$arguments) { return (new ReflectionMethod($owner, $method))->invoke($owner, ...$arguments); }
function pl_reason(Throwable $error): string {
    $reason = $error->getMessage();
    return preg_match('/\A[a-z_]{2,100}\z/D', $reason) ? $reason : 'canary_operation_unavailable';
}
function pl_native_url(string $url): bool {
    return preg_match('~\Ahttps://max\.ru/channel_appleservice39ru/[A-Za-z0-9_-]+\z~D', $url) === 1;
}

/** A successful text edit alone cannot accept the workaround: all four real URLs must be links. */
function pl_require_self_links(string $text, array $actual, array $expected): void {
    pl_check(preg_match('/[\x{10000}-\x{10ffff}]/u', $text) === 0, 'unsupported_native_character');
    pl_check(array_is_list($actual) && count($actual) === 4 && count($expected) === 4, 'native_autolinks_missing');
    usort($actual, static fn($a, $b) => ($a['from'] ?? -1) <=> ($b['from'] ?? -1));
    foreach ($expected as $i => $span) {
        $seen = $actual[$i];
        pl_check(is_array($seen) && ($seen['type'] ?? null) === 'link'
            && ($seen['from'] ?? null) === $span['from'] && ($seen['length'] ?? null) === $span['length']
            && ($seen['url'] ?? null) === $span['url'] && pl_native_url($span['url'])
            && mb_substr($text, $span['from'], $span['length'], 'UTF-8') === $span['url'], 'native_autolink_mismatch');
    }
}

function pl_package(): array {
    $raw = base64_decode(PL_PACKAGE_BASE64, true);
    pl_check(is_string($raw) && hash('sha256', $raw) === PL_PACKAGE_SHA256, 'fixed_package_changed');
    $package = json_decode($raw, true, 24, JSON_THROW_ON_ERROR);
    pl_check(($package['schema_version'] ?? null) === 1 && ($package['revision'] ?? null) === PL_REVISION
        && ($package['prior_native_audit_run'] ?? null) === 43616796199
        && ($package['prior_verified_operations'] ?? null) === 362
        && array_keys($package['targets'] ?? []) === [PL_POST], 'fixed_canary_scope_changed');
    $target = $package['targets'][PL_POST];
    pl_check($target['post_id'] === PL_POST && $target['url'] === 'https://max.ru/channel_appleservice39ru/AaG9bPyxCko'
        && $target['mid'] === 'mid.ffffb667d20a157101a1bd6cfcb10a4a' && $target['photo_id'] === '46750898257'
        && $target['cta_label'] === 'Узнать стоимость ремонта'
        && $target['cta_url'] === 'https://max.ru/id390521129062_bot?start=as39_model-iphone-x'
        && hash('sha256', $target['before_text']) === $target['before_text_sha256']
        && pl_digest($target['before_markup']) === $target['before_markup_sha256'], 'fixed_canary_identity_changed');
    $next = $target['after'];
    pl_check(array_keys($next['body']) === ['text', 'notify'] && $next['body']['notify'] === false
        && $next['body']['text'] === $next['native_text'] && mb_strlen($next['native_text'], 'UTF-8') <= 4000
        && $next['autolink_acceptance_required'] === true, 'fixed_plain_body_changed');
    $beforeUrls = array_column(array_values(array_filter($target['before_markup'], static fn($s) => $s['type'] === 'link')), 'url');
    pl_check(array_column($next['native_markup'], 'url') === $beforeUrls, 'original_destinations_changed');
    pl_require_self_links($next['native_text'], $next['native_markup'], $next['native_markup']);
    preg_match_all('/[0-9][0-9 ]* ₽/u', $target['before_text'], $beforePrices);
    preg_match_all('/[0-9][0-9 ]* ₽/u', $next['native_text'], $afterPrices);
    pl_check($beforePrices[0] === $afterPrices[0] && count($beforePrices[0]) === 4
        && str_contains($next['native_text'], 'Диагностика бесплатная.')
        && str_contains($next['native_text'], 'оригинальный — 12 000 ₽, аналог — 6 000 ₽')
        && str_contains($next['native_text'], '3 месяца') && str_contains($next['native_text'], 'офис 404'), 'confirmed_facts_changed');
    return $package;
}

function pl_self_test(): void {
    $p = pl_package(); $t = $p['targets'][PL_POST]; $next = $t['after']; $checks = 1;
    $reject = static function (callable $action, string $reason) use (&$checks): void {
        try { $action(); } catch (RuntimeException $error) {
            pl_check($error->getMessage() === $reason, 'self_test_wrong_refusal'); ++$checks; return;
        }
        throw new RuntimeException('self_test_missing_refusal');
    };
    $reject(static fn() => pl_require_self_links($next['native_text'], [], $next['native_markup']), 'native_autolinks_missing');
    $wrong = $next['native_markup']; $wrong[0]['url'] = 'https://example.com/';
    $reject(static fn() => pl_require_self_links($next['native_text'], $wrong, $next['native_markup']), 'native_autolink_mismatch');
    $wrong = $next['native_markup']; $wrong[0]['from']++;
    $reject(static fn() => pl_require_self_links($next['native_text'], $wrong, $next['native_markup']), 'native_autolink_mismatch');
    $wrong = $next['native_markup']; $wrong[0]['length']--;
    $reject(static fn() => pl_require_self_links($next['native_text'], $wrong, $next['native_markup']), 'native_autolink_mismatch');
    $wrong = $next['native_markup']; $wrong[0]['type'] = 'strong';
    $reject(static fn() => pl_require_self_links($next['native_text'], $wrong, $next['native_markup']), 'native_autolink_mismatch');
    $wrong = $next['native_markup']; $wrong[] = $wrong[0];
    $reject(static fn() => pl_require_self_links($next['native_text'], $wrong, $next['native_markup']), 'native_autolinks_missing');
    $oldLinks = array_values(array_filter($t['before_markup'], static fn($s) => $s['type'] === 'link'));
    $reject(static fn() => pl_require_self_links($t['before_text'], $oldLinks, $oldLinks), 'native_autolink_mismatch');
    $reject(static fn() => pl_require_self_links($next['native_text']."\u{1F600}", $next['native_markup'], $next['native_markup']), 'unsupported_native_character');
    pl_require_self_links($next['native_text'], array_reverse($next['native_markup']), $next['native_markup']); ++$checks;
    pl_emit(['ok' => true, 'state' => 'canary_source_checked', 'checks' => $checks, 'target_count' => 1,
        'link_count' => 4, 'plain_body_characters' => mb_strlen($next['native_text'], 'UTF-8'),
        'price_amounts_preserved' => 4, 'provider_writes' => 0, 'batch_operation_available' => false]);
}

function pl_git(array $arguments): string {
    $pipes = []; $process = proc_open(['git', '-C', PL_ROOT, ...$arguments], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    pl_check(is_resource($process), 'checkout_read_failed'); fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[2]);
    pl_check(proc_close($process) === 0 && is_string($out), 'checkout_read_failed'); return trim($out);
}
function pl_original_rows(array $rows): array { return array_values(array_filter($rows, static fn($r) => $r['post_key'] !== PL_KEY)); }
function pl_original_verified(array $row): bool {
    return $row['state'] === 'verified' && ((int)$row['write_attempts'] === 1
        || ((int)$row['write_attempts'] === 0 && $row['operation'] === 'pin'
            && ($row['outcome']['already_present'] ?? null) === true && ($row['outcome']['readback'] ?? null) === true));
}
function pl_fingerprint(array $snapshot): string {
    return pl_digest([$snapshot['mid'], $snapshot['url'], $snapshot['text'], $snapshot['markup'], $snapshot['photo'], $snapshot['buttons']]);
}

$store = null; $active = null; $acknowledged = null;
try {
    pl_check($argc === 2 && in_array($argv[1], ['--self-test', '--apply-canary'], true), 'fixed_canary_operation_required');
    if ($argv[1] === '--self-test') { pl_self_test(); exit; }
    pl_check(preg_match('/\A[a-f0-9]{40}\z/D', PL_APP) === 1, 'actual_application_not_bound');
    $package = pl_package(); $target = $package['targets'][PL_POST]; $next = $target['after'];
    $private = '/var/www/anytoour/data/config/max-search.php';
    pl_check(is_file($private) && !is_link($private), 'protected_connection_unavailable');
    $privateHash = hash_file('sha256', $private);
    $safe = static fn(): bool => pl_git(['rev-parse', 'HEAD']) === PL_APP
        && pl_git(['status', '--porcelain', '--untracked-files=no']) === ''
        && is_file($private) && !is_link($private) && hash_file('sha256', $private) === $privateHash;
    pl_check($safe(), 'installed_checkout_changed');
    require_once PL_ROOT.'/config.php';
    require_once PL_ROOT.'/services/ConversationDb.php';
    require_once PL_ROOT.'/services/AppleService39PilotPublisher.php';
    $pdo = ConversationDb::connection(); pl_check(!$pdo->inTransaction(), 'autocommit_required');
    $store = new AppleService39PilotPublicationStore($pdo); $original = $store->rows();
    pl_check(count($original) === 362 && count(array_filter($original, static fn($r) => !pl_original_verified($r))) === 0
        && count(array_filter($original, static fn($r) => $r['post_key'] === PL_KEY)) === 0, 'original_operations_changed');
    $creates = array_values(array_filter($original, static fn($r) => $r['operation'] === 'create'));
    pl_check(count($creates) === 69, 'native_inventory_changed');
    $create = $store->operation(PL_POST, 'create');
    pl_check(is_array($create) && $create['state'] === 'verified' && $create['external_message_id'] === $target['mid']
        && $create['public_url'] === $target['url'], 'original_publication_changed');
    $manifest = AppleService39PilotManifest::installed();
    $pub = new AppleService39PilotPublisher($pdo, $manifest, PL_CHANNEL, $safe, null, null,
        new AppleService39CatalogueRevision($manifest), new AppleService39SearchIntentRevision($manifest));
    $store->assertBinding(pl_invoke($pub, 'preflight'));
    pl_check(pl_invoke($pub, 'pinnedMid') === PL_PIN, 'pin_changed');
    $connection = ProjectMaxConnection::resolve('appleservice39'); pl_check($connection !== null, 'connection_unavailable');
    $transport = new AppleService39ChannelTransport($connection, PL_CHANNEL);
    $observe = static function () use ($transport, $pub, $target): array {
        $response = $transport->message($target['mid']);
        pl_check(AppleService39ChannelTransport::accepted($response), 'native_read_failed');
        $message = $response['data']['message'] ?? $response['data'];
        $body = $message['body'] ?? null; pl_check(is_array($body), 'native_body_missing');
        $attachments = $body['attachments'] ?? null;
        pl_check(is_array($attachments) && array_is_list($attachments) && count($attachments) === 2, 'native_attachment_shape_changed');
        $types = array_column($attachments, 'type'); $imageAt = array_search('image', $types, true); $keyAt = array_search('inline_keyboard', $types, true);
        pl_check($imageAt !== false && $keyAt !== false && $imageAt !== $keyAt, 'native_attachment_shape_changed');
        $buttons = $attachments[$keyAt]['payload']['buttons'] ?? null;
        $expectedButtons = [[['type' => 'link', 'text' => $target['cta_label'], 'url' => $target['cta_url']]]];
        pl_check(pl_invoke($pub, 'sameButtons', $buttons, $expectedButtons), 'native_single_cta_changed');
        $photo = (string)(pl_invoke($pub, 'imagePayload', $message)['photo_id'] ?? '');
        pl_check($photo === $target['photo_id'], 'native_approved_photo_changed');
        $text = $body['text'] ?? null; $markup = $body['markup'] ?? [];
        pl_check(is_string($text) && is_array($markup), 'native_content_unavailable');
        $expected = ['text' => $text, 'attachments' => $attachments];
        $native = ['native_text' => $text, 'native_markup' => $markup];
        pl_check(pl_invoke($pub, 'verifyMessageContent', $message, $target['mid'], $expected, $native) === $target['url'], 'native_identity_changed');
        return ['mid' => $target['mid'], 'url' => $target['url'], 'text' => $text, 'markup' => $markup, 'photo' => $photo, 'buttons' => $buttons];
    };
    $guard = static function () use ($safe, $store, $original, $pub): void {
        pl_check($safe() && pl_original_rows($store->rows()) === $original, 'source_or_original_operations_changed');
        pl_check(pl_invoke($pub, 'pinnedMid') === PL_PIN, 'pin_changed');
    };
    $before = $observe();
    pl_check(hash('sha256', $before['text']) === $target['before_text_sha256']
        && pl_digest($before['markup']) === $target['before_markup_sha256'], 'native_preimage_changed');
    $guard();
    $request = ['revision' => PL_REVISION, 'revision_sha256' => PL_PACKAGE_SHA256, 'target_post' => PL_POST,
        'message_id' => $before['mid'], 'url' => $before['url'], 'original_operations_sha256' => pl_digest($original),
        'before_text_sha256' => $target['before_text_sha256'], 'before_markup_sha256' => $target['before_markup_sha256'],
        'preserved_photo_id' => $before['photo'], 'preserved_buttons' => $before['buttons'],
        'body' => $next['body'], 'native_text' => $next['native_text'], 'native_markup' => $next['native_markup']];
    $row = $store->prepare(PL_KEY, 'links', $request);
    pl_check($row['state'] === 'prepared' && (int)$row['write_attempts'] === 0, 'canary_operation_already_attempted');
    $active = $row;
    pl_invoke($pub, 'beforeWrite', $row, static function () use ($guard, $observe, $before): void {
        $guard(); pl_check(pl_fingerprint($observe()) === pl_fingerprint($before), 'native_preimage_changed');
    });
    $store->rememberMessage($row, $before['mid'], $before['url']);
    // This is the only provider write. Omitting attachments preserves the approved photo and exact button.
    $ack = $transport->edit($before['mid'], $next['body']);
    $acknowledged = AppleService39ChannelTransport::accepted($ack) && ($ack['data']['success'] ?? null) === true;
    $after = $observe();
    pl_check($after['text'] === $next['native_text'], 'native_text_readback_mismatch');
    pl_require_self_links($after['text'], $after['markup'], $next['native_markup']);
    pl_check($after['photo'] === $before['photo'] && pl_canonical($after['buttons']) === pl_canonical($before['buttons']), 'native_media_or_cta_changed');
    $store->finish($row, 'verified', ['readback' => true, 'native_text_sha256' => hash('sha256', $after['text']),
        'native_markup_sha256' => pl_digest($after['markup']), 'native_autolinks' => 4, 'photo_preserved' => true,
        'single_cta_preserved' => true, 'provider_acknowledged' => $acknowledged, 'public_acceptance' => 'pending']);
    $active = null; $guard();
    pl_check(count($store->rows()) === 363, 'canary_operation_count_changed');
    pl_emit(['ok' => true, 'state' => 'canary_native_verified_pending_public_acceptance', 'post_id' => PL_POST,
        'url' => $after['url'], 'native_text' => $after['text'], 'native_markup' => $after['markup'],
        'photo_id' => $after['photo'], 'single_cta_preserved' => true, 'prior_operations_preserved' => 362,
        'verified_operations' => 363, 'provider_edits' => 1, 'provider_creates' => 0, 'provider_uploads' => 0,
        'batch_operation_available' => false, 'automatic_retry' => false]);
} catch (Throwable $error) {
    if ($store instanceof AppleService39PilotPublicationStore && is_array($active)) {
        try {
            $current = $store->operation($active['post_key'], $active['operation']);
            if (is_array($current) && in_array($current['state'], ['write_started', 'unknown'], true)
                && $current['payload_sha256'] === $active['payload_sha256']) {
                $store->finish($current, 'unknown', $current['outcome'] + ['reason' => pl_reason($error),
                    'provider_acknowledged' => $acknowledged, 'automatic_retry' => false]);
            }
        } catch (Throwable $ignored) { /* The original durable claim remains held. */ }
    }
    pl_emit(['ok' => false, 'state' => 'canary_stopped', 'reason' => pl_reason($error),
        'error_class' => get_class($error), 'line' => $error->getLine(), 'automatic_retry' => false,
        'batch_operation_available' => false]); exit(1);
}
