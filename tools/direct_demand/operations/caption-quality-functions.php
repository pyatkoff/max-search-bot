<?php
declare(strict_types=1);

/** A separate, newly authorized content revision inside the existing once-only editor. */
function isApprovedCaptionQuality(array $payload): bool
{
    return ($payload['update_kind'] ?? '') === 'approved_existing_caption_quality_revision';
}

function captionQualityScope(array $payload): void
{
    need(isApprovedCaptionQuality($payload)
        && ($payload['quality_cohort_sha256'] ?? '') === 'ef5e14d2aba7f4b412acfcfd71967ae986d2876d7e3ba14361119cff62b815fd'
        && ($payload['quality_authorization'] ?? '') === 'user_20261108_accepted_content_quality_followup'
        && ($payload['blocked_operation_not_resumed'] ?? '') === 'commercial-hotel-caption-review6706115450-20261107-1-v1',
        'approved_quality_revision_scope_changed');
    $prefix = 'commercial-content-quality-20261108-cohort159';
    $index = $payload['batch_index'] ?? null;
    $rows = $payload['rows'] ?? [];
    if (($payload['batch_kind'] ?? '') === 'canary') {
        need($index === 0 && ($payload['operation'] ?? '') === $prefix.'-canary-v1'
            && count($rows) === 8 && ($payload['predecessor'] ?? null) === null,
            'quality_canary_scope_changed');
        $pairs = [];
        foreach ($rows as $row) $pairs[] = $row['hotel_id'].':'.$row['platform'];
        sort($pairs);
        $expected = ['1352:max','1352:telegram','1074:max','1074:telegram','462:max','462:telegram','1659:max','1659:telegram'];
        sort($expected);
        need($pairs === $expected, 'quality_canary_hotels_changed');
    } else {
        need(($payload['batch_kind'] ?? '') === 'batch' && is_int($index) && $index >= 1 && $index <= 7
            && ($payload['operation'] ?? '') === $prefix.sprintf('-batch%03d-v1', $index)
            && count($rows) === ($index === 7 ? 10 : 50), 'quality_batch_scope_changed');
        $previous = $prefix.($index === 1 ? '-canary-v1' : sprintf('-batch%03d-v1', $index - 1));
        need(($payload['predecessor']['operation'] ?? '') === $previous
            && preg_match('/\A[a-f0-9]{64}\z/D', (string)($payload['predecessor']['manifest_sha256'] ?? '')) === 1,
            'quality_predecessor_changed');
        foreach ($rows as $row) {
            need(!in_array($row['hotel_id'], [1352,1074,462,1659], true), 'quality_canary_repeated');
        }
    }
}

/** Approved copy uses a bold unchanged heading and plain, escaped body on both providers. */
function captionQualityProviderText(string $platform, string $oldText, string $newText): array
{
    need(in_array($platform, ['max','telegram'], true)
        && mb_check_encoding($oldText, 'UTF-8') && mb_check_encoding($newText, 'UTF-8'),
        'quality_text_or_platform_invalid');
    $old = explode("\n", $oldText, 2);
    $new = explode("\n", $newText, 2);
    need(count($old) === 2 && count($new) === 2 && $old[0] === $new[0]
        && str_starts_with($new[0], '✈️ Туры в ') && !str_contains($new[0], 'с перел')
        && $oldText !== $newText && !str_contains($newText, "\r")
        && strlen(mb_convert_encoding($newText, 'UTF-16LE', 'UTF-8')) / 2 <= 1024,
        'quality_unchanged_short_heading_required');
    need(!str_contains($newText, 'https://anytour.online/poisk-turov/')
        && !str_contains($newText, '🕒 Проверено') && !str_contains($newText, '✈️ Вылет:')
        && !str_contains($newText, 'Нажмите'), 'quality_legacy_block_remaining');
    $length = intdiv(strlen(mb_convert_encoding($new[0], 'UTF-16LE', 'UTF-8')), 2);
    $markup = $platform === 'max'
        ? [['type'=>'strong','from'=>0,'length'=>$length]]
        : [['type'=>'bold','offset'=>0,'length'=>$length]];
    return ['markup'=>$markup, 'max_html'=>$platform === 'max'
        ? '<b>'.htmlspecialchars($new[0], ENT_QUOTES, 'UTF-8').'</b>'."\n".htmlspecialchars($new[1], ENT_QUOTES, 'UTF-8')
        : null];
}
