<?php
declare(strict_types=1);
require __DIR__.'/monitor-price-contract.php';
$cases=0;
function check(bool $ok,string $label):void {global $cases;if(!$ok)throw new RuntimeException($label);$cases++;}
function rejects(callable $fn,string $reason):void {try{$fn();}catch(RuntimeException $e){check($e->getMessage()===$reason,'wrong_failure:'.$e->getMessage());return;}throw new RuntimeException('not_rejected:'.$reason);}
$now=1794409200;$iso=static fn(int $t):string=>gmdate('Y-m-d\TH:i:s\Z',$t);
$offer=['recordId'=>str_repeat('a',64),'tourvisorHotelId'=>1183,'price'=>148090,'tourId'=>'tour-one','departureId'=>1,'departureDate'=>'2027-04-04','nights'=>5,'adults'=>2,'childrenCount'=>0,'currency'=>'RUB','roomType'=>'стандартный номер','mealId'=>7,'currentPriceVerified'=>false,'fullPackagePriceVerified'=>false,'availabilityVerified'=>false,'fuelIncluded'=>null,'source'=>'scheduled_monitor','priceKind'=>'listing_observation','ageSecondsAtExport'=>7200,'expiresAt'=>$iso($now+900)];
$snapshot=['format'=>'anytour-hotel-monitor-prices-v1','identityNamespace'=>'tourvisor','source'=>'scheduled_monitor','observationWindowHours'=>24,'supplierRequests'=>0,'writes'=>0,'timeBasis'=>'database-relative; stored observation timezone is not asserted','sourceUrl'=>'https://anytoour.ru/data/hotel-monitor-prices-read-v1.php?tourvisorHotelIds%5B%5D=1183','generatedAt'=>$iso($now),'scope'=>['tourvisorHotelIds'=>[1183],'departureId'=>1,'adults'=>2,'childrenCount'=>0,'currency'=>'RUB','nightsFrom'=>5,'nightsTo'=>14,'dateFrom'=>'2026-11-12','dateTo'=>'2027-10-31'],'item'=>['tourvisorHotelId'=>1183,'state'=>'observed','offers'=>[$offer],'coverage'=>['searchWindowsComplete'=>false]]];
$price=monitor_price_observation($snapshot,1183,$now);
check($price['price']===148090&&$price['offer']['tourId']==='tour-one'&&$price['availability_verified']===false,'minimum_exact_without_quote');
check($price['fuel_added_separately']===false,'no_fuel_surcharge_added');
check(monitor_price_operation($price)===monitor_price_operation(monitor_price_observation($snapshot,1183,$now+10)),'stable_operation');
$text="✈️ Туры в Отель\n\n💰 От 148 090 ₽ за двоих\n✈️ Вылет из Москвы 04.04.2027 · 5 ночей\n👥 2 взрослых\n🛏 стандартный номер\n🍽 Всё включено\nЦена найдена мониторингом 11.11.2026.\n\nУзнать стоимость тура";
monitor_price_validate_text($text,$price);check(true,'valid_caption');
foreach(['identityNamespace'=>'anex','supplierRequests'=>1,'observationWindowHours'=>48] as $k=>$v){$bad=$snapshot;$bad[$k]=$v;rejects(fn()=>monitor_price_observation($bad,1183,$now),'monitor_source_invalid');}
foreach(['https://user@anytoour.ru/data/hotel-monitor-prices-read-v1.php','https://example.org/data/hotel-monitor-prices-read-v1.php'] as $url){$bad=$snapshot;$bad['sourceUrl']=$url;rejects(fn()=>monitor_price_observation($bad,1183,$now),'monitor_source_url_invalid');}
rejects(fn()=>monitor_price_observation($snapshot,1173,$now),'monitor_identity_invalid');
$bad=$snapshot;$bad['scope']['departureId']=2;rejects(fn()=>monitor_price_observation($bad,1183,$now),'monitor_scope_invalid');
foreach(['currentPriceVerified','fullPackagePriceVerified','availabilityVerified'] as $k){$bad=$snapshot;$bad['item']['offers'][0][$k]=true;rejects(fn()=>monitor_price_observation($bad,1183,$now),'monitor_verification_claim_invalid');}
$bad=$snapshot;$bad['item']['offers'][0]['fuelIncluded']=true;rejects(fn()=>monitor_price_observation($bad,1183,$now),'monitor_price_basis_invalid');
$bad=$snapshot;$bad['item']['offers'][0]['nights']=1;rejects(fn()=>monitor_price_observation($bad,1183,$now),'monitor_terms_invalid');
$bad=$snapshot;$bad['item']['offers'][0]['departureDate']='2027-02-30';rejects(fn()=>monitor_price_observation($bad,1183,$now),'monitor_departure_invalid');
$bad=$snapshot;$bad['item']['offers'][]=array_replace($offer,['recordId'=>str_repeat('b',64),'price'=>140000]);rejects(fn()=>monitor_price_observation($bad,1183,$now),'monitor_price_invalid');
rejects(fn()=>monitor_price_observation($snapshot,1183,$now+901),'monitor_price_expired');
check(monitor_price_observation($snapshot,1183,$now+901,false)['price']===148090,'confirmed_receipt_survives_transport_expiry');
rejects(fn()=>monitor_price_observation($snapshot,1183,$now+86400,false),'monitor_price_expired');
foreach(['148 090'=>'149 090','5 ночей'=>'7 ночей','Всё включено'=>'Завтраки'] as $old=>$new)rejects(fn()=>monitor_price_validate_text(str_replace($old,$new,$text),$price),'monitor_text_conditions_mismatch');
rejects(fn()=>monitor_price_validate_text($text."\nУзнать стоимость тура",$price),'monitor_text_conditions_mismatch');
rejects(fn()=>monitor_price_validate_text($text.str_repeat('я',1100),$price),'monitor_caption_too_long');
$binding=['tourvisor_hotel_id'=>1183,'posts'=>['max'=>'https://max.ru/channel_anytour/existing','telegram'=>'https://t.me/any_toour/316'],'offer_ids'=>['max'=>'max_opaque_native','telegram'=>'tg_opaque_native'],'destinations'=>['max'=>'https://max.ru/bot?startapp=retained_tracking','telegram'=>'https://t.me/any_toour/316']];
$journal=['complete'=>true,'operation'=>monitor_price_operation($price),'manifest_hash'=>str_repeat('c',64),'monitor_snapshot'=>$snapshot,'feed_binding'=>$binding,'posts'=>[]];
foreach(['max','telegram'] as $platform){$t=$text.($platform==='telegram'?"\nФото и особенности отеля.":'');$journal['posts'][$platform]=['state'=>'confirmed','receipt'=>['platform'=>$platform,'state'=>'sent','existing_message_reused'=>true,'body_photo_button_verified'=>true,'monitor_price'=>$price,'text'=>$t,'text_sha256'=>hash('sha256',$t),'post_url'=>$binding['posts'][$platform]]];}
$binding['operation']=$journal['operation'];$binding['manifest_hash']=$journal['manifest_hash'];
$update=monitor_price_confirmed_journal($journal,$binding,$now);check(count($update['receipts'])===2,'both_platform_receipts');
$bad=$journal;$bad['complete']=false;rejects(fn()=>monitor_price_confirmed_journal($bad,$binding,$now),'monitor_posts_not_confirmed');
$bad=$journal;unset($bad['posts']['telegram']);rejects(fn()=>monitor_price_confirmed_journal($bad,$binding,$now),'monitor_posts_not_confirmed');
$bad=$journal;$bad['posts']['max']['state']='edit_started';rejects(fn()=>monitor_price_confirmed_journal($bad,$binding,$now),'monitor_receipt_mismatch');
$bad=$journal;$bad['posts']['max']['receipt']['text'].='changed';rejects(fn()=>monitor_price_confirmed_journal($bad,$binding,$now),'monitor_receipt_mismatch');
$bad=$binding;$bad['offer_ids']['max']='max_wrong_hotel';rejects(fn()=>monitor_price_confirmed_journal($journal,$bad,$now),'monitor_feed_binding_changed');
$other='<offer id="tg_other"><url>https://t.me/any_toour/999</url><picture>preserve.jpg</picture><description>Untouched</description></offer>';
$xml='<?xml version="1.0" encoding="UTF-8"?>'."\n".'<yml_catalog date="2026-11-11 00:00"><shop><currencies><currency id="RUB" rate="1"/></currencies><offers>';
foreach(['max','telegram'] as $platform)$xml.='<offer id="'.$binding['offer_ids'][$platform].'"><url><![CDATA['.$binding['destinations'][$platform].']]></url><picture>exact-photo.jpg</picture><description>Original</description></offer>';
$xml.=$other.'</offers></shop></yml_catalog>';
$next=monitor_price_render_feed($xml,[$update],'hotel-posts-all.xml',$now);
check(substr_count($next,'<price>148090</price>')===2&&substr_count($next,'<currencyId>RUB</currencyId>')===2,'same_feed_price');
check(str_contains($next,$other)&&substr_count($next,'exact-photo.jpg')===2,'untouched_offer_and_photos');
check(str_contains($next,'startapp=retained_tracking'),'tracking_preserved');
check(monitor_price_render_feed($next,[$update],'hotel-posts-all.xml',$now)===$next,'replay_exact_bytes');
check(substr_count(monitor_price_render_feed($xml,[$update],'hotel-posts-max.xml',$now),'<price>')===1,'max_file_scope');
$bad=str_replace('retained_tracking','changed_tracking',$xml);rejects(fn()=>monitor_price_render_feed($bad,[$update],'hotel-posts-all.xml',$now),'monitor_feed_destination_changed');
rejects(fn()=>monitor_price_render_feed('<!DOCTYPE x>'.$xml,[$update],'hotel-posts-all.xml',$now),'monitor_feed_invalid');
rejects(fn()=>monitor_price_render_feed($xml,[$update,$update],'hotel-posts-all.xml',$now),'monitor_feed_target_invalid');
if(isset($argv[1])){
    $real=json_decode(file_get_contents($argv[1]),true,64,JSON_THROW_ON_ERROR);$realUpdates=[];
    foreach($real['hotels'] as $hotel){if(!isset($hotel['updatePlan']))continue;$s=$hotel['source'];$p=monitor_price_observation($s,(int)$hotel['tourvisorHotelId'],monitor_price_time($s['generatedAt']));$plan=$hotel['updatePlan'];
        check($p['price']===$plan['price']&&$p['record_id']===$plan['priceRecordId'],'recorded_handoff_exact');
        foreach(['max','telegram'] as $channel){monitor_price_validate_text($plan['posts'][$channel]['text'],$p);check(true,'recorded_caption');}
        $realUpdates[]=['price'=>$p,'binding'=>['offer_ids'=>$hotel['feedBinding']['offerIds'],'destinations'=>$hotel['feedBinding']['destinations']],'receipts'=>['max'=>['text'=>$plan['posts']['max']['text']],'telegram'=>['text'=>$plan['posts']['telegram']['text']]]];
    }
    if(isset($argv[2])){$realXml=file_get_contents($argv[2]);$patched=monitor_price_render_feed($realXml,$realUpdates,'hotel-posts-all.xml',$now);check(substr_count($patched,'<price>')===count($realUpdates)*2,'real_feed_price_rows');
        preg_match_all('/<offer id="([^"]+)">.*?<\/offer>/s',$realXml,$before,PREG_SET_ORDER);preg_match_all('/<offer id="([^"]+)">.*?<\/offer>/s',$patched,$after,PREG_SET_ORDER);$changed=0;
        foreach($before as $i=>$row){check($row[1]===$after[$i][1],'real_feed_order_ids');if($row[0]!==$after[$i][0])$changed++;}check($changed===count($realUpdates)*2,'only_selected_real_offers_changed');
    }
}
echo json_encode(['ok'=>true,'cases'=>$cases,'supplier_calls'=>0,'post_writes'=>0,'feed_writes'=>0],JSON_THROW_ON_ERROR),"\n";
