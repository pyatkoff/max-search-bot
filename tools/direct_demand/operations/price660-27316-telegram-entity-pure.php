<?php
declare(strict_types=1);

/**
 * PRICE660/27316 pure Telegram caption-entity plan, no actual bot call.
 * Must match the original installed editor's monitor-price inline-link rule.
 * Execution still requires fresh source, CAS, original locks and owner consent.
 */
function price27316_link_need(bool $v, string $reason): void {
    if(!$v) throw new RuntimeException($reason);
}
function price27316_utf16_length(string $value): int {
    price27316_link_need(preg_match('//u',$value)===1,'caption_invalid_utf8');
    $codepoints=preg_match_all('/./us',$value);
    $supplementary=preg_match_all('/[\x{10000}-\x{10FFFF}]/u',$value);
    price27316_link_need(is_int($codepoints)&&is_int($supplementary),'caption_utf16_calculation_failed');
    return $codepoints+$supplementary;
}
function price27316_telegram_caption_plan(string $text,string $buttonUrl):array {
    price27316_link_need(
        preg_match('~\Ahttps://t\.me/anyru_bot\?start=p_[A-Za-z0-9_-]{8,60}\z~D',$buttonUrl)===1,
        'not_canonical_existing_hotel_bot_url'
    );
    price27316_link_need(preg_match('//u',$text)===1&&strlen($text)>80
        &&mb_strlen($text,'UTF-8')<=1024&&substr_count($text,'💰')===1,
        'caption_price_scope_changed');
    $count=preg_match_all(
        '/^💰 (От (?:[0-9]{1,3}(?: [0-9]{3})*|[0-9]+)(?:,[0-9]{1,2})? ₽) за двоих$/mu',
        $text,$matches,PREG_OFFSET_CAPTURE
    );
    price27316_link_need($count===1,'caption_single_exact_price_label_required');
    [$label,$byteOffset]=$matches[1][0];
    $prefix=substr($text,0,$byteOffset);
    $suffix=substr($text,$byteOffset+strlen($label));
    price27316_link_need(strpos($prefix,"\n")!==false
        &&str_starts_with($text,'🏨 ')
        &&str_contains($suffix,"\n✈️ Вылет из Москвы ")
        &&str_contains($text,'Цена найдена мониторингом ')
        &&str_contains($text,'Стоимость и наличие могут измениться.'),
        'monitor_price_caption_template_changed');

    $offset=price27316_utf16_length($prefix);
    $length=price27316_utf16_length($label);
    price27316_link_need($offset>=1&&$length>=1&&$offset+$length<=price27316_utf16_length($text),
        'caption_link_range_invalid');
    $entity=['type'=>'text_link','offset'=>$offset,'length'=>$length,'url'=>$buttonUrl];
    return [
        'platform'=>'telegram',
        'caption'=>$text,
        'caption_entities'=>[$entity],
        'reply_markup'=>['inline_keyboard'=>[[[
            'text'=>'✈️ Узнать стоимость тура',
            'url'=>$buttonUrl,
        ]]]],
        'caption_sha256'=>hash('sha256',$text),
        'price_label'=>$label,
        'existing_message_id'=>'156',
        'existing_publication_id'=>395,
        'current_version_required'=>4200,
        'new_posts_allowed'=>0,
        'attachments_field_present'=>false,
        'provider_write_allowed_by_this_plan'=>false,
    ];
}

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(count($argv)===2&&$argv[1]==='--self-test'){
    $url='https://t.me/anyru_bot?start=p_1234567890abcdefghijklmnop';
    $text="🏨 REGNUM CARYA\n🌴 Отдых в Белеке\n\n💰 От 1 108 872 ₽ за двоих\n✈️ Вылет из Москвы 21.11.2026 · 5 ночей\n👥 2 взрослых\n🛏 sapphire villa\n🍽 Ultra All Inclusive\n\nЦена найдена мониторингом 17.11.2026, 09:55 МСК. Стоимость и наличие могут измениться.\n\nУзнать стоимость тура";
    $p=price27316_telegram_caption_plan($text,$url);
    $expectPrefix=substr($text,0,strpos($text,'От 1 108 872'));
    price27316_link_need($p['caption']===$text&&$p['price_label']==='От 1 108 872 ₽'
        &&$p['caption_entities'][0]['offset']===price27316_utf16_length($expectPrefix)
        &&$p['caption_entities'][0]['length']===price27316_utf16_length('От 1 108 872 ₽')
        &&$p['reply_markup']['inline_keyboard'][0][0]['url']===$url
        &&$p['attachments_field_present']===false&&$p['provider_write_allowed_by_this_plan']===false,
        'single_price_link_projection_failed');
    $rejected=0;
    $bad=[
        ['url','https://t.me/wrong_bot?start=p_1234567890abcdefghijklmnop'],
        ['url','https://t.me/anyru_bot?start=not_hotel'],
        ['caption',str_replace('От 1 108 872 ₽','1 108 872 ₽',$text)],
        ['caption',str_replace('💰 От 1 108 872 ₽ за двоих','💰 От 1 108 872 ₽ за двоих'."\n".'💰 От 2 000 000 ₽ за двоих',$text)],
        ['caption',str_replace('🏨 REGNUM CARYA','REGNUM CARYA',$text)],
        ['caption',str_replace('Стоимость и наличие могут измениться.','Цена гарантирована.',$text)],
        ['caption',str_replace('✈️ Вылет из Москвы','✈️ Вылет из Санкт-Петербурга',$text)],
    ];
    foreach($bad as [$kind,$value]){
        try{price27316_telegram_caption_plan($kind==='caption'?$value:$text,$kind==='url'?$value:$url);}
        catch(Throwable $e){$rejected++;continue;}
        throw new RuntimeException('unsafe_caption_or_url_admitted_'.$kind);
    }
    echo 'PRICE27316_TELEGRAM_ENTITY_PURE_OK controls='.($rejected+1)
        .' utf16_verified=1 no_new_post=1 no_provider_call=1 no_write=1',PHP_EOL;
    exit(0);
}
fwrite(STDERR,"Pure telegram entity projector: only --self-test. No live edit.\n");
exit(2);
