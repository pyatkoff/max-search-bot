<?php
declare(strict_types=1);

require_once __DIR__.'/../services/PromotedHotelPriceWriter.php';

function promotedPriceCheck(bool $ok, string $case): void
{
    if (!$ok) throw new RuntimeException($case);
}

$caption = "✈️ Seagull & Sea\n\nХургада, Египет";
$price = "💰 От 102 233 ₽ за двоих\n✈️ Вылет из Москвы 16.01.2027 · 5 ночей\n"
    ."👥 2 взрослых\n🛏 standard room\n🍽 Всё включено\n"
    ."Цена найдена мониторингом 19.11.2026, 04:01 МСК. Стоимость и наличие могут измениться.";
$first = PromotedHotelPriceWriter::revision($caption,$price,true);
promotedPriceCheck($first === $caption."\n\n".$price, 'caption_copy_changed');
$next = str_replace(['102 233','04:01'],['102 222','04:02'],$price);
promotedPriceCheck(PromotedHotelPriceWriter::revision($first,$next,true) === $caption."\n\n".$next, 'duplicate_price_blocks');
promotedPriceCheck(PromotedHotelPriceWriter::revision($caption."\n\n".$next,$price,true) === $caption."\n\n".$next, 'older_price_overwrite');
promotedPriceCheck(PromotedHotelPriceWriter::revision($first,$price,true) === $first, 'same_price_not_idempotent');
$length = intdiv(strlen(mb_convert_encoding('✈️ Seagull & Sea','UTF-16LE','UTF-8')),2);
$html = PromotedHotelPriceWriter::maxHtml($caption,$first,[['type'=>'strong','from'=>0,'length'=>$length]]);
promotedPriceCheck(str_starts_with($html,"<b>✈️ Seagull &amp; Sea</b>\n\n") && str_ends_with($html,$price), 'max_markup_lost');

foreach ([
    fn()=>PromotedHotelPriceWriter::revision('Старая цена 100 000 ₽',$price,true),
    fn()=>PromotedHotelPriceWriter::revision(str_repeat('😀',510),$price,true),
    fn()=>PromotedHotelPriceWriter::maxHtml('😀 open','😀 open tail',[['type'=>'strong','from'=>1,'length'=>1]]),
    fn()=>PromotedHotelPriceWriter::maxHtml('old','new',[['type'=>'strong','from'=>0,'length'=>3]]),
] as $reject) {
    try { $reject(); } catch (RuntimeException) { continue; }
    throw new RuntimeException('unsafe_revision_accepted');
}
echo "promoted hotel price editor regression passed\n";
