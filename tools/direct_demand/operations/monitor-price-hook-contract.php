<?php
declare(strict_types=1);
require_once __DIR__.'/monitor-price-contract.php';

/** Pure preparation for the existing editor; no search, transport or storage. */
function monitor_hook_draft(string $template,array $price):string {
    $slot='💰 [Минимальная актуальная цена тура — подтвердить перед выпуском]';
    $terms='[Город вылета · даты · ночи · состав туристов · номер · питание · время проверки]';
    monitor_price_require(substr_count($template,$slot)===1&&substr_count($template,$terms)===1,'hook_template_changed');
    $o=$price['offer'];$n=$o['nights'];
    $night=$n>=11&&$n<=14?'ночей':($n%10===1?'ночь':(in_array($n%10,[2,3,4],true)?'ночи':'ночей'));
    $money=number_format((float)$o['price'],floor((float)$o['price'])===(float)$o['price']?0:2,',',' ');
    if(str_contains($money,','))$money=rtrim(rtrim($money,'0'),',');
    $block='💰 От '.$money." ₽ за двоих\n✈️ Вылет из Москвы ".implode('.',array_reverse(explode('-',$o['departureDate']))).' · '.$n.' '.$night."\n👥 2 взрослых\n🛏 ".$o['roomType']."\n🍽 ".monitor_price_meals()[$o['mealId']];
    $stamp=(new DateTimeImmutable($price['observed_at']))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('d.m.Y, H:i');
    $lines=explode("\n",$template);
    foreach($lines as $i=>$line)if($i>=2&&preg_match('/all\s*inclusive|вс[её]\s+включено/iu',$line))unset($lines[$i]);
    $text=str_replace([$slot,$terms],[$block,'Цена найдена мониторингом '.$stamp.' МСК. Стоимость и наличие могут измениться.'],implode("\n",$lines));
    $text=preg_replace('/\n{3,}/',"\n\n",$text);monitor_price_validate_text($text,$price);return $text;
}

/** A later automated edit is admissible only through its completed original journal. */
function monitor_hook_caption_allowed(array $publication,array $row,string $platform,?array $prior):bool {
    $s=$publication['snapshot']??[];$hash=hash('sha256',$s['text']??'');
    $record=$s['metadata']['monitor_price_record_id']??null;
    if($record===null)return hash_equals($row['expected_text_sha256'][$platform],$hash);
    if(!is_string($record)||!preg_match('/\A[a-f0-9]{64}\z/D',$record)||!$prior||($prior['complete']??false)!==true
        ||($prior['operation']??'')!=='commercial-monitor-price-h'.$row['tourvisor_hotel_id'].'-'.$record
        ||($prior['feed_binding']??null)!==$row['feed_binding'])return false;
    foreach($prior['posts']??[] as $post){$r=$post['receipt']??[];
        if(($post['state']??'')==='confirmed'&&($r['platform']??'')===$platform)
            return ($r['version_id']??null)===(int)($publication['version_id']??0)&&($r['publication_id']??null)===(int)($publication['publication_id']??0)
                &&($r['message_id']??null)===($publication['external_message_id']??null)&&($r['post_url']??null)===($s['post_url']??null)
                &&($r['text_sha256']??null)===$hash&&($r['text']??null)===($s['text']??null)
                &&($r['existing_message_reused']??false)===true&&($r['body_photo_button_verified']??false)===true;
    }return false;
}

function monitor_hook_feed_operation(string $postOperation,string $date):string {
    // Stable across retries; within the existing feed writer's operation grammar.
    return 'commercial-channel-hotel-feeds-'.$date.'-update'.(string)hexdec(substr(hash('sha256',$postOperation),0,14));
}

function monitor_hook_source(array $payload,int $tv,string $url):array {
    monitor_price_require(($payload['ok']??false)===true,'hook_monitor_read_failed');
    $items=$payload['items']??[];$matches=array_values(array_filter($items,static fn($x)=>($x['tourvisorHotelId']??null)===$tv));
    monitor_price_require(count($matches)===1,'hook_monitor_identity_missing');
    unset($payload['ok'],$payload['items']);$payload['item']=$matches[0];$payload['sourceUrl']=$url;return $payload;
}
