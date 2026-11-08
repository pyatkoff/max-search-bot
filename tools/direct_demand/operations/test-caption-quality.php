<?php
declare(strict_types=1);
function need(bool $ok, string $reason): void { if (!$ok) throw new RuntimeException($reason); }
require __DIR__.'/caption-quality-functions.php';
function rejects(callable $call, string $label): void {
    try { $call(); } catch (RuntimeException $e) { return; }
    throw new RuntimeException('did_not_reject_'.$label);
}
$raw = gzdecode(file_get_contents(__DIR__.'/commercial-content-quality-20261108-inputs.json.gz'));
need(is_string($raw) && hash('sha256',$raw) === 'ef5e14d2aba7f4b412acfcfd71967ae986d2876d7e3ba14361119cff62b815fd', 'reviewed_cohort_changed');
$cohort = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
foreach ($cohort['rows'] as $row) {
    $result = captionQualityProviderText($row['platform'],$row['old_text'],$row['new_text']);
    $first = explode("\n",$row['new_text'],2)[0];
    need($result['markup'][0]['length'] === intdiv(strlen(mb_convert_encoding($first,'UTF-16LE','UTF-8')),2), 'utf16_heading_length');
    if ($row['platform'] === 'max') {
        need(html_entity_decode(strip_tags($result['max_html']),ENT_QUOTES,'UTF-8') === $row['new_text'], 'html_text_roundtrip');
    } else { need($result['max_html'] === null, 'telegram_has_no_html_mode'); }
}
$old = "✈️ Туры в A & B 🏖\nСтарый текст.";
$new = "✈️ Туры в A & B 🏖\nТекст с <уголками> & кавычками.";
$max = captionQualityProviderText('max',$old,$new);
need(str_contains($max['max_html'],'A &amp; B') && str_contains($max['max_html'],'&lt;уголками&gt;'), 'html_escaping');
need($max['markup'][0]['length'] === 18, 'astral_emoji_counted_as_two_utf16_units');
rejects(fn()=>captionQualityProviderText('max',$old,str_replace('A & B','C',$new)),'changed_heading');
rejects(fn()=>captionQualityProviderText('telegram',$old,$old),'same_caption');
rejects(fn()=>captionQualityProviderText('max',$old,$new."\nНажмите кнопку"),'legacy_cta');
rejects(fn()=>captionQualityProviderText('max',$old,$new.str_repeat('я',1024)),'overlong_caption');
$canary = ['operation'=>'commercial-content-quality-20261108-cohort159-canary-v1',
    'update_kind'=>'approved_existing_caption_quality_revision',
    'quality_cohort_sha256'=>'ef5e14d2aba7f4b412acfcfd71967ae986d2876d7e3ba14361119cff62b815fd',
    'quality_authorization'=>'user_20261108_accepted_content_quality_followup',
    'blocked_operation_not_resumed'=>'commercial-hotel-caption-review6706115450-20261107-1-v1',
    'batch_index'=>0,'batch_kind'=>'canary','predecessor'=>null,'rows'=>array_slice($cohort['rows'],0,8)];
captionQualityScope($canary);
$blocked = $canary; $blocked['operation'] = $canary['blocked_operation_not_resumed'];
rejects(fn()=>captionQualityScope($blocked),'consumed_old_operation');
$different = $canary; $different['quality_cohort_sha256'] = str_repeat('0',64);
rejects(fn()=>captionQualityScope($different),'different_revision');
$wrong = $canary; $wrong['rows'][0]['hotel_id'] = 1543;
rejects(fn()=>captionQualityScope($wrong),'changed_canary_hotel');
echo "CAPTION_QUALITY_NATIVE_CHECKS_OK reviewed_post_texts=318 old_operation_not_replayed=1\n";
