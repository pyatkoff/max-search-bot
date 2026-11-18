<?php
declare(strict_types=1);
require_once __DIR__.'/monitor-price-hook-contract.php';
require_once __DIR__.'/test-monitor-price-expansion1000-prefix.php';
[$raw,$accepted877PackedSha]=monitor_price_test_accepted877(file_get_contents(__DIR__.'/monitor-price-expanded-roster-20261111.json.gz'));
// Authenticate append877, then reconstruct accepted660 exact bytes. Historical
// 20/32 proofs below remain unchanged, including their original byte hashes.
$expansion877Raw=file_get_contents(__DIR__.'/monitor-price-expansion877-proof-20261118.json');
if(hash('sha256',$expansion877Raw)!=='a650e2821a6394038c9585e902c331d1ca117f31619cbac34a3a6926e4034b47')throw new RuntimeException('expansion877_proof_changed');
$expansion877=json_decode($expansion877Raw,true,64,JSON_THROW_ON_ERROR);
if($accepted877PackedSha!==$expansion877['roster_gzip_sha256'])throw new RuntimeException('expansion877_packed_changed');
$expanded877=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
if(count($expanded877['rows'])!==877||array_column(array_slice($expanded877['rows'],660),'tourvisor_hotel_id')!==array_column($expansion877['append217'],'tourvisor_hotel_id'))throw new RuntimeException('expansion877_scope_changed');
$canonical877=function($value)use(&$canonical877){if(!is_array($value))return $value;if(!array_is_list($value))ksort($value,SORT_STRING);foreach($value as $key=>$item)$value[$key]=$canonical877($item);return $value;};
$prefix660Hash=hash('sha256',json_encode($canonical877(array_slice($expanded877['rows'],0,660)),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
if($prefix660Hash!=='406293597fa7ddeb7d3c58dd08d2c44ad43345923a0385f9eaea33798ad2d0db'||$prefix660Hash!==$expansion877['previous660_canonical_sha256'])throw new RuntimeException('accepted660_canonical_changed');
$raw=substr($raw,0,2021244)."\n  ]\n}\n";
if(strlen($raw)!==2021251||hash('sha256',$raw)!=='b81a0ff17d97a0a21c9f7f49797b50ffe1803483d58f0e569d52c36d480fc4ac')throw new RuntimeException('accepted660_exact_bytes_changed');
// Later append-only rosters must reconstruct the exact accepted628 bytes before this original cohort test.
$extension=json_decode(file_get_contents(__DIR__.'/monitor-price-expansion32-proof-20261116.json'),true,64,JSON_THROW_ON_ERROR);
if(hash('sha256',$raw)!==$extension['new_decompressed_sha256']||$extension['previous_count']!==628||$extension['next_count']!==660)throw new RuntimeException('expanded_roster_proof_changed');
$raw=substr($raw,0,$extension['old_rows_prefix_bytes']).substr($raw,$extension['old_rows_prefix_bytes']+$extension['appended_bytes']);
if(strlen($raw)!==$extension['old_decompressed_bytes']||hash('sha256',$raw)!==$extension['old_decompressed_sha256'])throw new RuntimeException('old628_bytes_changed');
$all=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
$proof=json_decode(file_get_contents(__DIR__.'/monitor-price-expansion20-proof-20261116.json'),true,64,JSON_THROW_ON_ERROR);
$ids=[88169,547,15774,12125,57335,2480,2417,2418,2468,12127,27481,23289,50262,72939,2489,85417,2466,2460,99713,43676];
$checks=0;
function check20(bool $ok,string $code):void {global $checks;$checks++;if(!$ok)throw new RuntimeException($code);}
check20($proof['new_tourvisor_ids']===$ids&&$proof['previous_count']===608&&$proof['next_count']===628,'scope_changed');
check20(hash('sha256',substr($raw,0,$proof['old_rows_prefix_bytes']))===$proof['old_rows_prefix_sha256'],'old608_prefix_changed');
check20(hash('sha256',$raw)===$proof['new_decompressed_sha256'],'new_roster_changed');
check20(count($all['rows'])===628&&count(array_unique(array_column($all['rows'],'tourvisor_hotel_id')))===628,'roster_not_unique');
$rows=array_slice($all['rows'],608);check20(array_column($rows,'tourvisor_hotel_id')===$ids,'new20_order_changed');
$handoff=json_decode(file_get_contents(__DIR__.'/monitor-price-six-20261111-handoff.json'),true,64,JSON_THROW_ON_ERROR);
$baseline=$handoff['hotels'][0]['source'];
foreach($rows as $i=>$row){
 $p=$proof['rows'][$i];$tv=$row['tourvisor_hotel_id'];$text=$p['original_text'];
 check20($tv===$p['tourvisor_hotel_id']&&$row['hotel_id']===$tv&&$row['logical_id']==='commercial-hotel-'.$tv.'-v1','native_identity_changed');
 check20($row['original_publication_operation']===$p['operation']&&$row['cover_sha256']===$p['photo_sha256']&&$row['original_text_sha256']===hash('sha256',$text),'original_publication_binding_changed');
 $snapshot=$baseline;$snapshot['scope']['tourvisorHotelIds']=[$tv];$snapshot['item']['tourvisorHotelId']=$tv;
 foreach($snapshot['item']['offers'] as $n=>$offer){$snapshot['item']['offers'][$n]['tourvisorHotelId']=$tv;$snapshot['item']['offers'][$n]['recordId']=hash('sha256','expansion20-fixture-'.$tv.'-'.$n);}
 $price=monitor_price_observation($snapshot,$tv,strtotime($snapshot['generatedAt']));
 foreach(['max','telegram'] as $channel){
  $template=$row['templates'][$channel];check20(str_starts_with($template,$text."\n\n")&&$row['expected_text_sha256'][$channel]===hash('sha256',$text),'accepted_caption_lost');
  $draft=monitor_hook_draft($template,$price);check20(str_starts_with($draft,$text."\n\n")&&substr_count($draft,'Узнать стоимость тура')===1,'facts_or_disclosures_changed');
  check20(strlen(mb_convert_encoding($draft,'UTF-16LE','UTF-8'))/2<=1024,'caption_too_long');
  check20($row['feed_binding']['posts'][$channel]===$row['posts'][$channel]&&$row['feed_binding']['offer_ids'][$channel]===($channel==='max'?'max_':'tg_').$tv,'feed_binding_changed');
  $pub=['publication_id'=>10+$i,'version_id'=>20+$i,'external_message_id'=>'fixture','snapshot'=>['text'=>$text,'post_url'=>$row['posts'][$channel],'metadata'=>[]]];
  check20(monitor_hook_caption_allowed($pub,$row,$channel,null),'original_caption_rejected');
  $pub['snapshot']['text'].=' manual';check20(!monitor_hook_caption_allowed($pub,$row,$channel,null),'manual_edit_accepted');
  $pub['snapshot']['text']=$draft;$pub['snapshot']['metadata']['monitor_price_record_id']=$price['record_id'];
  check20(!monitor_hook_caption_allowed($pub,$row,$channel,null),'prior_automated_edit_without_receipt_accepted');
 }
}
check20(count(array_unique(array_merge(array_column(array_column($all['rows'],'posts'),'max'),array_column(array_column($all['rows'],'posts'),'telegram'))))===1256,'duplicate_post_url');
echo 'MONITOR_EXPANSION20_OK checks='.$checks.' roster=628 old608_preserved=1 new20=20 provider_calls=0 supplier_calls=0 writes=0',PHP_EOL;
