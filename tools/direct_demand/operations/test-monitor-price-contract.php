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

// Preserve the accepted legacy closing sentence when adding a fresh price block.
$acceptedCta='✈️ Подберём подходящий номер и рассчитаем тур с перелётом на ваши даты.';
$legacyText=str_replace('Узнать стоимость тура',$acceptedCta,$text);
monitor_price_validate_text($legacyText,$price);check(true,'accepted_legacy_cta_preserved');
rejects(fn()=>monitor_price_validate_text($legacyText."\nУзнать стоимость тура",$price),'monitor_text_conditions_mismatch');
rejects(fn()=>monitor_price_validate_text(str_replace($acceptedCta,'',$legacyText),$price),'monitor_text_conditions_mismatch');

// Synthetic journal/transport fixtures use the five actual frozen roster identities.
// They are never persisted as production receipts and contain no actual start tokens.
$roster=json_decode(gzdecode(file_get_contents(__DIR__.'/monitor-price-expanded-roster-20261111.json.gz')),true,64,JSON_THROW_ON_ERROR)['rows'];
$legacyRows=array_values(array_filter($roster,static fn($r)=>array_key_exists('legacy_proof',$r)));
check(array_column($legacyRows,'tourvisor_hotel_id')===[310,469,498,1971,1981],'exact_legacy_five_scope');
foreach($legacyRows as $row){
    $binding=monitor_price_legacy_binding($row);$tv=$row['tourvisor_hotel_id'];
    check($binding['publisher_hotel_id']===$row['hotel_id'],'nullable_or_typed_role_preserved');
    $fixture=['operation'=>$binding['prior_price']['operation'],'manifest_hash'=>$binding['prior_price']['manifest_hash'],'configs'=>[],'posts'=>[],'complete'=>true];
    foreach(['max','telegram'] as $platform){
        $token='test-only-'.$platform.'-'.$tv;$button='https://example.invalid/'.$token;
        $row['legacy_proof']['contexts'][$platform]['start_payload_sha256']=hash('sha256',$token);
        $row['legacy_proof']['contexts'][$platform]['button_url_sha256']=hash('sha256',$button);
        $c=$row['legacy_proof']['contexts'][$platform];$r=array_intersect_key($c,array_flip(['publication_id','message_id','version_id','logical_id','source_key','post_url','photo_signature']));
        $r+=['platform'=>$platform,'state'=>'sent','body_photo_button_verified'=>true,'existing_message_reused'=>true,
            'text_sha256'=>$binding['prior_price']['text_sha256'],'button_url'=>$button];
        $fixture['posts'][$platform.':'.$binding['prior_price']['source_key']]=['state'=>'confirmed','receipt'=>$r];
    }
    $encode=static fn(array $v):string=>json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    $raw=$encode($fixture);check(count(monitor_price_legacy_receipts($row,$raw))===2,'two_original_legacy_receipts');
    $bad=$row;$bad['hotel_id']=$tv;rejects(fn()=>monitor_price_legacy_binding($bad),'canonical_original_receipt_mismatch');
    $bad=$row;$bad['hotel_id']=0;rejects(fn()=>monitor_price_legacy_binding($bad),'canonical_original_receipt_mismatch');
    $bad=$row;$bad['hotel_id']=(string)($row['hotel_id']??'');rejects(fn()=>monitor_price_legacy_binding($bad),'canonical_original_receipt_mismatch');
    $bad=$row;$bad['tourvisor_hotel_id']=9373;rejects(fn()=>monitor_price_legacy_binding($bad),'canonical_original_receipt_mismatch');
    $bad=$row;unset($bad['legacy_proof']['publisher_hotel_id']);rejects(fn()=>monitor_price_legacy_binding($bad),'canonical_original_receipt_mismatch');
    $bad=$row;$bad['legacy_proof']['publisher_hotel_id']=$tv===498?877:228;rejects(fn()=>monitor_price_legacy_binding($bad),'canonical_original_receipt_mismatch');
    $bad=$row;$bad['original_publication_operation']='invented-original';rejects(fn()=>monitor_price_legacy_binding($bad),'canonical_original_receipt_mismatch');
    $bad=$row;$bad['cover_sha256']=str_repeat('0',64);rejects(fn()=>monitor_price_legacy_binding($bad),'canonical_original_receipt_mismatch');
    $bad=$row;$bad['legacy_proof']['prior_price']['operation']='../foreign';rejects(fn()=>monitor_price_legacy_binding($bad),'canonical_original_receipt_mismatch');
    $bad=$row;$bad['legacy_proof']['prior_price']['source_key']='other';rejects(fn()=>monitor_price_legacy_binding($bad),'canonical_original_receipt_mismatch');
    $bad=$row;unset($bad['legacy_proof']['contexts']['telegram']);rejects(fn()=>monitor_price_legacy_binding($bad),'canonical_original_receipt_mismatch');
    $bad=$row;$bad['legacy_proof']['contexts']['max']['post_url'].='-foreign';rejects(fn()=>monitor_price_legacy_binding($bad),'canonical_original_receipt_mismatch');
    $bad=$fixture;$bad['complete']=false;rejects(fn()=>monitor_price_legacy_receipts($row,$encode($bad)),'hook_legacy_source_unresolved_stop');
    $bad=$fixture;unset($bad['posts']['telegram:'.$binding['prior_price']['source_key']]);rejects(fn()=>monitor_price_legacy_receipts($row,$encode($bad)),'hook_legacy_source_unresolved_stop');
    $bad=$fixture;$bad['posts']['max:'.$binding['prior_price']['source_key']]['state']='UNKNOWN';rejects(fn()=>monitor_price_legacy_receipts($row,$encode($bad)),'hook_legacy_source_unresolved_stop');
    $bad=$fixture;$bad['manifest_hash']=str_repeat('0',64);rejects(fn()=>monitor_price_legacy_receipts($row,$encode($bad)),'canonical_original_receipt_mismatch');
    $bad=$fixture;$bad['operation'].='-other';rejects(fn()=>monitor_price_legacy_receipts($row,$encode($bad)),'canonical_original_receipt_mismatch');
    rejects(fn()=>monitor_price_legacy_receipts($row,rtrim($raw,"\n")),'hook_legacy_source_incomplete_stop');
    rejects(fn()=>monitor_price_legacy_receipts($row,"{broken}\n"),'hook_legacy_source_incomplete_stop');
    $bad=$fixture;$bad['complete']=false;rejects(fn()=>monitor_price_legacy_receipts($row,$raw.$encode($bad)),'hook_legacy_source_unresolved_stop');
    foreach(['logical_id','publication_id','message_id','post_url','photo_signature','text_sha256','button_url'] as $field){
        $bad=$fixture;$bad['posts']['max:'.$binding['prior_price']['source_key']]['receipt'][$field]=$field==='publication_id'?999999:'foreign';
        rejects(fn()=>monitor_price_legacy_receipts($row,$encode($bad)),'canonical_original_receipt_mismatch');
    }
    foreach(['max','telegram'] as $platform){
        $c=$row['legacy_proof']['contexts'][$platform];$token='test-only-'.$platform.'-'.$tv;$button='https://example.invalid/'.$token;
        $slot="💰 [Минимальная актуальная цена тура — подтвердить перед выпуском]\n[Город вылета · даты · ночи · состав туристов · номер · питание · время проверки]\n\n";
        $caption=str_replace($slot,'',$row['templates'][$platform]);
        $p=['publication_id'=>$c['publication_id'],'version_id'=>$c['baseline_version_id'],'external_message_id'=>$c['message_id'],
            'project_key'=>'anytour','platform'=>$platform,'state'=>'sent','source_key'=>$c['source_key'],'start_payload'=>$token,
            'bot_key'=>$platform==='max'?'id9704048781_1_bot':'anyru_bot','channel_id'=>$platform==='max'?'-80492244209683':'-1002262666616',
            'snapshot'=>['text'=>$caption,'post_url'=>$c['post_url'],'attachments'=>[['type'=>'image','provider'=>$platform,'photo_id'=>explode(':',$c['photo_signature'],2)[1]]]]];
        check(monitor_price_legacy_publication_matches($p,$row,$platform,$button),'actual_legacy_identity_with_test_transport');
        check(monitor_price_legacy_initial_caption_matches($p,$row,$platform),'exact_accepted_editorial_baseline');
        $bad=$p;$bad['version_id']++;check(!monitor_price_legacy_initial_caption_matches($bad,$row,$platform),'same_caption_new_manual_version_stops');
        $bad=$p;$bad['version_id']=$c['version_id'];check(!monitor_price_legacy_initial_caption_matches($bad,$row,$platform),'old_price_version_stops');
        $bad=$p;$bad['snapshot']['text'].=' edited';check(!monitor_price_legacy_initial_caption_matches($bad,$row,$platform),'manual_caption_stops');
        $bad=$p;$bad['snapshot']['metadata']['monitor_price_record_id']=str_repeat('a',64);check(!monitor_price_legacy_initial_caption_matches($bad,$row,$platform),'later_monitor_needs_own_completed_receipt');
        foreach(['start_payload','source_key','external_message_id','bot_key','channel_id'] as $field){$bad=$p;$bad[$field]='foreign';check(!monitor_price_legacy_publication_matches($bad,$row,$platform,$button),'changed_physical_identity_stops');}
        $bad=$p;$bad['snapshot']['attachments'][0]['photo_id']='foreign';check(!monitor_price_legacy_publication_matches($bad,$row,$platform,$button),'changed_photo_stops');
        $bad=$p;$bad['claim_token']='active';check(!monitor_price_legacy_publication_matches($bad,$row,$platform,$button),'claimed_publication_stops');
        check(!monitor_price_legacy_publication_matches($p,$row,$platform,$button.'changed'),'changed_button_stops');
    }
}
// Exact individual closing paragraphs remain restricted to their own native hotel.
$ownClosings=[
    140=>'✈️ Подберём категорию под состав семьи и рассчитаем тур в Golden Beach с перелётом на ваши даты.',
    386=>'✈️ Подберём категорию и расположение номера, рассчитаем тур с перелётом на ваши даты.',
    1033=>'✈️ Подберём семейный номер в Barut Hemera и рассчитаем тур с перелётом на ваши даты.',
    1095=>'✈️ Подберём тур в Side Star Resort с перелётом и подходящим номером. Рассчитаем полную стоимость на вашу семью.',
    8053=>'✈️ Подберём номер и питание, рассчитаем тур с перелётом на ваши даты.',
    33313=>'✈️ Подберём номер и питание в Sugar Marina Surf, рассчитаем тур с перелётом на ваши даты и состав семьи.',
    58987=>'✈️ Подберём номер и питание в Seashells, рассчитаем тур с перелётом на ваши даты.',
];
foreach($ownClosings as $tv=>$closing){
    $p=$price;$p['tourvisor_hotel_id']=$tv;$draft=str_replace('Узнать стоимость тура',$closing,$text);
    monitor_price_validate_text($draft,$p);check(true,'accepted_own_closing');
    rejects(fn()=>monitor_price_validate_text($draft,$price),'monitor_text_conditions_mismatch');
    rejects(fn()=>monitor_price_validate_text($draft."\n\n".$closing,$p),'monitor_text_conditions_mismatch');
    rejects(fn()=>monitor_price_validate_text($draft."\nUnapproved tail",$p),'monitor_text_conditions_mismatch');
    rejects(fn()=>monitor_price_validate_text($draft."\nУзнать стоимость тура",$p),'monitor_text_conditions_mismatch');
    rejects(fn()=>monitor_price_validate_text($text."\n\n".$closing,$price),'monitor_text_conditions_mismatch');
}
$foreign=$price;$foreign['tourvisor_hotel_id']=140;
rejects(fn()=>monitor_price_validate_text(str_replace('Узнать стоимость тура',$ownClosings[386],$text),$foreign),'monitor_text_conditions_mismatch');
rejects(fn()=>monitor_price_validate_text(str_replace('Узнать стоимость тура',$ownClosings[140]."\n\n".$ownClosings[386],$text),$foreign),'monitor_text_conditions_mismatch');

// Read-only parser fixtures are synthetic, including all tokens and the 48 unrelated batch members.
// Production contexts are copied from the reviewed roster; no fixture is a production receipt.
function caption_test_raw(array $journal):string{return json_encode($journal,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";}
function caption_test_parse(string $raw,array $source):array{
    $stream=fopen('php://temp','w+b');fwrite($stream,$raw);rewind($stream);
    try{return monitor_price_caption_journal($stream,$source);}finally{fclose($stream);}
}
$captionRows=array_values(array_filter($roster,static fn($r)=>array_key_exists('caption_proof',$r)));
check(array_column($captionRows,'tourvisor_hotel_id')===[381,67910,68323],'exact_caption_fallback_three');
foreach($captionRows as $row){
    $b=monitor_price_caption_binding($row);$tv=$row['tourvisor_hotel_id'];
    $fixture=$b['source']+['snapshot_sha256'=>str_repeat('d',64),'complete'=>true,'posts'=>[]];
    foreach(['max','telegram'] as $platform){
        $c=$b['contexts'][$platform];$token='p_test_only_'.$tv.'_'.$platform;$button='https://example.invalid/bot?start='.$token;
        $caption=str_replace("💰 [Минимальная актуальная цена тура — подтвердить перед выпуском]\n[Город вылета · даты · ночи · состав туристов · номер · питание · время проверки]\n\n",'',$row['templates'][$platform]);
        check(hash('sha256',$caption)===$c['text_sha256'],'real_caption_baseline_matches_template');
        $c['button_url_sha256']=hash('sha256',$button);$c['previous_text_sha256']=hash('sha256','fixture old caption');
        $row['caption_proof']['contexts'][$platform]=$c;
        $plan=array_intersect_key($c,array_flip(['hotel_id','platform','publication_id','message_id','post_url','source_key','photo_signature','button_url_sha256','button_text']));
        $plan+=['version_id'=>$c['previous_version_id'],'new_text'=>$caption,'old_text'=>'fixture old caption',
            'new_text_sha256'=>$c['text_sha256'],'old_text_sha256'=>$c['previous_text_sha256'],
            'attachments_sha256'=>$c['previous_attachments_sha256'],'button_url'=>$button,'token'=>$token];
        $fixture['posts'][$platform.':'.$c['publication_id']]=['state'=>'confirmed','plan'=>$plan,'receipt'=>$c];
    }
    for($i=0;$i<48;$i++)$fixture['posts']['max:'.(900000+$i)]=['state'=>'confirmed','receipt'=>['platform'=>'max','publication_id'=>900000+$i,'state'=>'sent',
        'body_photo_button_verified'=>true,'formatting_verified'=>true,'existing_message_reused'=>true,'existing_token_resolves_to_current_version'=>true]];
    $first=$fixture;$first['complete']=false;$first['posts']=[];
    $raw=caption_test_raw($first);$progress=$first;
    foreach(['max'=>'text_edit_returned','telegram'=>'provider_response_recorded'] as $platform=>$returned){
        $key=$platform.':'.$row['caption_proof']['contexts'][$platform]['publication_id'];$member=$fixture['posts'][$key];
        unset($member['receipt']);$member['state']='edit_started';$progress['posts'][$key]=$member;$raw.=caption_test_raw($progress);
        $progress['posts'][$key]['state']=$returned;$raw.=caption_test_raw($progress);
        $progress['posts'][$key]=$fixture['posts'][$key];$raw.=caption_test_raw($progress);
    }
    $raw.=caption_test_raw($fixture);
    $terminal=caption_test_parse($raw,$b['source']);$selected=monitor_price_caption_receipts($row,$terminal);
    check(count($selected)===2&&!isset($selected['max']['token']),'caption_pair_returns_only_token_hash');
    check(caption_test_parse($raw.caption_test_raw($fixture),$b['source'])===$terminal,'identical_completed_line_accepted');
    $bad=$row;$bad['hotel_id']=$tv;rejects(fn()=>monitor_price_caption_binding($bad),'canonical_original_receipt_mismatch');
    $bad=$row;$bad['caption_proof']['publisher_hotel_id']=$tv;rejects(fn()=>monitor_price_caption_binding($bad),'canonical_original_receipt_mismatch');
    $bad=$row;$bad['caption_proof']['native_projection_sha256']=str_repeat('a',64);rejects(fn()=>monitor_price_caption_binding($bad),'canonical_original_receipt_mismatch');
    $bad=$row;$bad['original_publication_operation']='invented';rejects(fn()=>monitor_price_caption_binding($bad),'canonical_original_receipt_mismatch');
    foreach(['max','telegram'] as $platform){
        $c=$row['caption_proof']['contexts'][$platform];$member=$fixture['posts'][$platform.':'.$c['publication_id']];$plan=$member['plan'];
        $p=['publication_id'=>$c['publication_id'],'version_id'=>$c['version_id'],'external_message_id'=>$c['message_id'],
            'project_key'=>'anytour','platform'=>$platform,'state'=>'sent','source_key'=>$c['source_key'],'start_payload'=>$plan['token'],
            'bot_key'=>$platform==='max'?'id9704048781_1_bot':'anyru_bot','channel_id'=>$platform==='max'?'-80492244209683':'-1002262666616',
            'snapshot'=>['text'=>$plan['new_text'],'post_url'=>$c['post_url'],'attachments'=>[['type'=>'image','provider'=>$platform,'photo_id'=>explode(':',$c['photo_signature'],2)[1]]]]];
        check(monitor_price_caption_publication_matches($p,$row,$platform,$plan['button_url'],$selected[$platform]),'caption_original_physical_binding');
        check(monitor_price_caption_initial_caption_matches($p,$row,$platform),'source_and_initial_versions_equal');
        $bad=$p;$bad['version_id']++;check(!monitor_price_caption_initial_caption_matches($bad,$row,$platform),'caption_same_text_manual_revision_stops');
        $bad=$p;$bad['snapshot']['metadata']['monitor_price_record_id']=str_repeat('a',64);check(!monitor_price_caption_initial_caption_matches($bad,$row,$platform),'caption_next_cycle_requires_monitor_journal');
        foreach(['start_payload','source_key','external_message_id','bot_key','channel_id'] as $field){$bad=$p;$bad[$field]='changed';check(!monitor_price_caption_publication_matches($bad,$row,$platform,$plan['button_url'],$selected[$platform]),'caption_physical_drift_stops');}
        $bad=$p;$bad['snapshot']['attachments'][0]['photo_id']='foreign';check(!monitor_price_caption_publication_matches($bad,$row,$platform,$plan['button_url'],$selected[$platform]),'caption_photo_drift_stops');
        check(!monitor_price_caption_publication_matches($p,$row,$platform,$plan['button_url'].'changed',$selected[$platform]),'caption_button_drift_stops');
    }
    // Shared-batch safety must include unrelated posts, not just these two selected targets.
    $bad=$fixture;$bad['posts']['max:900000']['state']='UNKNOWN';rejects(fn()=>caption_test_parse(caption_test_raw($bad),$b['source']),'hook_caption_source_unresolved_stop');
    $bad=$fixture;unset($bad['posts']['max:900000']);rejects(fn()=>caption_test_parse(caption_test_raw($bad),$b['source']),'hook_caption_source_unresolved_stop');
    $bad=$fixture;$bad['posts']['max:900000']['receipt']['formatting_verified']=false;rejects(fn()=>caption_test_parse(caption_test_raw($bad),$b['source']),'hook_caption_source_unresolved_stop');
    $bad=$fixture;$bad['complete']=false;rejects(fn()=>caption_test_parse($raw.caption_test_raw($bad),$b['source']),'hook_caption_source_unresolved_stop');
    $bad=$fixture;$bad['posts']['max:900000']['receipt']['version_id']=999;rejects(fn()=>caption_test_parse($raw.caption_test_raw($bad),$b['source']),'hook_caption_source_unresolved_stop');
    foreach(['manifest_sha256','installed_sha','operation'] as $field){$bad=$fixture;$bad[$field]='changed';rejects(fn()=>caption_test_parse(caption_test_raw($bad),$b['source']),'canonical_original_receipt_mismatch');}
    rejects(fn()=>caption_test_parse(rtrim($raw,"\n"),$b['source']),'hook_caption_source_incomplete_stop');
    rejects(fn()=>caption_test_parse("{broken}\n",$b['source']),'hook_caption_source_incomplete_stop');
    $key='max:'.$row['caption_proof']['contexts']['max']['publication_id'];
    foreach(['token','new_text','old_text','photo_signature','button_url'] as $field){$bad=$fixture;$bad['posts'][$key]['plan'][$field]='changed';rejects(fn()=>monitor_price_caption_receipts($row,$bad),'canonical_original_receipt_mismatch');}
    $bad=$fixture;$bad['posts'][$key]['receipt']['version_id']++;rejects(fn()=>monitor_price_caption_receipts($row,$bad),'canonical_original_receipt_mismatch');
}
// Reject an oversized stream by metadata before reading or allocating a 128 MiB fixture.
class MonitorCaptionOversizeStream {
    public $context;
    public function stream_open($path,$mode,$options,&$opened_path):bool{return true;}
    public function stream_stat():array{return ['size'=>134217729];}
    public function stream_tell():int{return 0;}
    public function stream_eof():bool{return true;}
}
stream_wrapper_register('captionoversize',MonitorCaptionOversizeStream::class);
$oversize=fopen('captionoversize://test','r');
rejects(fn()=>monitor_price_caption_journal($oversize,$captionRows[0]['caption_proof']['source']),'hook_caption_source_incomplete_stop');
fclose($oversize);stream_wrapper_unregister('captionoversize');

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
