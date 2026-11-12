<?php
declare(strict_types=1);
putenv('ANYTOUR_STATIC_FEED_LIBRARY_ONLY=1');
require $argv[1]??__DIR__.'/publish-commercial-channel-hotel-feeds-20261103-1.extension-candidate.php';
function extension_rejects(callable $f,string $why):void{
 $caught=false;try{$f();}catch(RuntimeException $e){$caught=$e->getMessage()===$why;}
 upload_insist($caught,'fixture_expected_'.$why);
}
$op='commercial-hotel-batch-20261112-45-v1';$now=1794470400;
$row=['publication_operation'=>$op,'publication_summary_sha256'=>'','hotel_id'=>2,'logical_id'=>'fixture2',
 'text_sha256'=>str_repeat('a',64),'photo_sha256'=>str_repeat('b',64),
 'photo_url'=>'https://raw.githubusercontent.com/pyatkoff/max-search-bot/'.str_repeat('c',40).'/.github/traffic-publish/fixture/2.png',
 'name'=>'Fixture Hotel','destination'=>'Хургада','telegram_post_url'=>'https://t.me/any_toour/200',
 'max_post_url'=>'https://max.ru/channel_anytour/fixture2','public_route_alias'=>''];
$row['public_route_alias']='h1_'.substr(hash('sha256','anytour|max_2|'.$row['max_post_url']),0,32);
$posts=[];foreach(['telegram','max'] as $platform)$posts[]=['hotel_id'=>2,'platform'=>$platform,'logical_id'=>'fixture2','state'=>'sent',
 'body_photo_button_verified'=>true,'button_text'=>'✈️ Узнать стоимость тура','post_url'=>$row[$platform.'_post_url'],
 'text_sha256'=>$row['text_sha256'],'photo_sha256'=>$row['photo_sha256'],'photo_url'=>$row['photo_url'],'publication_id'=>2,'version_id'=>3];
$summary=json_encode(['ok'=>true,'operation'=>$op,'mode'=>'publish_approved_commercial_batch','posts'=>$posts],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
$row['publication_summary_sha256']=hash('sha256',$summary);$rows=static_extension_rows([$row],[$op=>$summary]);
$blocks=[];foreach(['tg','max'] as $platform)$blocks[$platform]='      <offer id="'.$platform.'_1">'."\n".
 '        <url>https://example.test/'.$platform.'</url>'."\n".
 '        <price>123456</price>'."\n".'        <currencyId>RUR</currencyId>'."\n".
 '        <name>Existing priced hotel</name>'."\n".
 '        <description>Сохранённая цена и условия: 7 ночей, два взрослых, питание.</description>'."\n".
 '        <custom_label_2>'.str_repeat('X',700).'</custom_label_2>'."\n".'      </offer>'."\n";
$feeds=[];$next=[];foreach(['hotel-posts-telegram.xml','hotel-posts-max.xml','hotel-posts-all.xml'] as $name){
 $body=$name==='hotel-posts-all.xml'?$blocks['tg'].$blocks['max']:$blocks[$name==='hotel-posts-max.xml'?'max':'tg'];
 $xml='<?xml version="1.0" encoding="UTF-8"?>'."\n".'<yml_catalog date="2026-11-11 03:00">'."\n  <shop>\n    <offers>\n".$body."    </offers>\n  </shop>\n</yml_catalog>\n";
 $feeds[$name]=$xml;$target=static_extension_render_feed($xml,$rows,$name,$now,1);$next[$name]=$target;
 upload_insist(substr_count($target,$body)===1,'fixture_priced_prior_bytes_preserved');
 $oldPriceCount=substr_count($xml,'<price>');upload_insist(substr_count($target,'<price>')===$oldPriceCount,'fixture_no_new_unproven_prices');
 upload_insist(substr_count($target,'<offer id="')===($name==='hotel-posts-all.xml'?4:2),'fixture_exact_new_pair');
 static_extension_assert_content($xml,hash('sha256',$xml),$target,$rows,$name,$now,1);
}
extension_rejects(fn()=>static_extension_rows([$row,$row],[$op=>$summary]),'extension_identity_invalid');
extension_rejects(fn()=>static_extension_rows([$row],[$op=>$summary.' ']),'extension_original_receipt_changed');
$changed=$row;$changed['max_post_url']='https://max.ru/channel_anytour/wrong';
$changed['public_route_alias']='h1_'.substr(hash('sha256','anytour|max_2|'.$changed['max_post_url']),0,32);
extension_rejects(fn()=>static_extension_rows([$changed],[$op=>$summary]),'extension_original_post_changed');
$name='hotel-posts-telegram.xml';
extension_rejects(fn()=>static_extension_assert_content($feeds[$name],str_repeat('0',64),$next[$name],$rows,$name,$now,1),'extension_previous_bytes_changed');
extension_rejects(fn()=>static_extension_assert_content($feeds[$name],hash('sha256',$feeds[$name]),str_replace('<price>123456</price>','<price>123457</price>',$next[$name]),$rows,$name,$now,1),'extension_feed_content_mismatch');
extension_rejects(fn()=>static_extension_render_feed(str_replace('id="tg_1"','id="tg_2"',$feeds[$name]),$rows,$name,$now,1),'extension_offer_already_present');
$route=['operation'=>'commercial-max2-fixture-install1','ok'=>true,'state'=>'INSTALLED_AND_NATIVE_VERIFIED',
 'checks'=>['ok'=>true,'exact_total_routes'=>2,'new_native_routes_verified'=>1,'previous1_rows_identical'=>true,
 'private_projection_http_status'=>404,'save_calls'=>0,'project_active'=>true,'counter_config_matches'=>true,'required_post_fields_ready'=>true],
 'provider_calls'=>0,'db_writes'=>0,'message_sends'=>0,'feed_writes'=>0];
$raw=json_encode($route,JSON_THROW_ON_ERROR);static_extension_route_receipt($raw,hash('sha256',$raw),$route['operation'],1,1);
extension_rejects(fn()=>static_extension_route_receipt($raw.' ',hash('sha256',$raw),$route['operation'],1,1),'extension_route_receipt_changed');
$route['checks']['previous1_rows_identical']=false;$raw=json_encode($route,JSON_THROW_ON_ERROR);
extension_rejects(fn()=>static_extension_route_receipt($raw,hash('sha256',$raw),$route['operation'],1,1),'extension_route_scope_changed');
echo "STATIC_FEED_EXTENSION_OK cases=12 prior_priced_offers=byte_exact original_receipt=required duplicate_and_changed_inputs=rejected native_route=required provider_calls=0 server_calls=0 writes=0\n";

