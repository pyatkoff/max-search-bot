<?php
declare(strict_types=1);
require_once __DIR__.'/monitor-price-hook-contract.php';
$raw=gzdecode(file_get_contents(__DIR__.'/monitor-price-expanded-roster-20261111.json.gz'));
// Authenticate append877, then reconstruct accepted660 exact bytes. Historical
// 20/32 proofs below remain unchanged, including their original byte hashes.
$expansion877Raw=file_get_contents(__DIR__.'/monitor-price-expansion877-proof-20261118.json');
if(hash('sha256',$expansion877Raw)!=='a650e2821a6394038c9585e902c331d1ca117f31619cbac34a3a6926e4034b47')throw new RuntimeException('expansion877_proof_changed');
$expansion877=json_decode($expansion877Raw,true,64,JSON_THROW_ON_ERROR);
if(hash_file('sha256',__DIR__.'/monitor-price-expanded-roster-20261111.json.gz')!==$expansion877['roster_gzip_sha256'])throw new RuntimeException('expansion877_packed_changed');
$expanded877=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
if(count($expanded877['rows'])!==877||array_column(array_slice($expanded877['rows'],660),'tourvisor_hotel_id')!==array_column($expansion877['append217'],'tourvisor_hotel_id'))throw new RuntimeException('expansion877_scope_changed');
$canonical877=function($value)use(&$canonical877){if(!is_array($value))return $value;if(!array_is_list($value))ksort($value,SORT_STRING);foreach($value as $key=>$item)$value[$key]=$canonical877($item);return $value;};
$prefix660Hash=hash('sha256',json_encode($canonical877(array_slice($expanded877['rows'],0,660)),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
if($prefix660Hash!=='406293597fa7ddeb7d3c58dd08d2c44ad43345923a0385f9eaea33798ad2d0db'||$prefix660Hash!==$expansion877['previous660_canonical_sha256'])throw new RuntimeException('accepted660_canonical_changed');
$raw=substr($raw,0,2021244)."\n  ]\n}\n";
if(strlen($raw)!==2021251||hash('sha256',$raw)!=='b81a0ff17d97a0a21c9f7f49797b50ffe1803483d58f0e569d52c36d480fc4ac')throw new RuntimeException('accepted660_exact_bytes_changed');
$all=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
$proof=json_decode(file_get_contents(__DIR__.'/monitor-price-expansion32-proof-20261116.json'),true,64,JSON_THROW_ON_ERROR);
$ids=[548,2947,4032,43675,48263,82790,24743,66547,60232,78033,43569,78002,2451,63208,2491,2470,64954,2479,2493,76318,53617,12124,76011,65108,123308,65938,80008,2436,2444,2445,218,63191];
$checks=0;
function check32(bool $ok,string $code):void {global $checks;$checks++;if(!$ok)throw new RuntimeException($code);}
check32($proof['new_tourvisor_ids']===$ids&&$proof['previous_count']===628&&$proof['next_count']===660,'scope_changed');
check32(hash('sha256',substr($raw,0,$proof['old_rows_prefix_bytes']))===$proof['old_rows_prefix_sha256'],'old608_prefix_changed');
check32(hash('sha256',$raw)===$proof['new_decompressed_sha256'],'new_roster_changed');
check32(count($all['rows'])===660&&count(array_unique(array_column($all['rows'],'tourvisor_hotel_id')))===660,'roster_not_unique');
$rows=array_slice($all['rows'],628);check32(array_column($rows,'tourvisor_hotel_id')===$ids,'new20_order_changed');
$handoff=json_decode(file_get_contents(__DIR__.'/monitor-price-six-20261111-handoff.json'),true,64,JSON_THROW_ON_ERROR);
$baseline=$handoff['hotels'][0]['source'];
foreach($rows as $i=>$row){
 $p=$proof['rows'][$i];$tv=$row['tourvisor_hotel_id'];$text=$p['original_text'];
 check32($tv===$p['tourvisor_hotel_id']&&$row['hotel_id']===$tv&&$row['logical_id']==='commercial-hotel-'.$tv.'-v1','native_identity_changed');
 check32($row['original_publication_operation']===$p['operation']&&$row['cover_sha256']===$p['photo_sha256']&&$row['original_text_sha256']===hash('sha256',$text),'original_publication_binding_changed');
 $snapshot=$baseline;$snapshot['scope']['tourvisorHotelIds']=[$tv];$snapshot['item']['tourvisorHotelId']=$tv;
 foreach($snapshot['item']['offers'] as $n=>$offer){$snapshot['item']['offers'][$n]['tourvisorHotelId']=$tv;$snapshot['item']['offers'][$n]['recordId']=hash('sha256','expansion32-fixture-'.$tv.'-'.$n);}
 $price=monitor_price_observation($snapshot,$tv,strtotime($snapshot['generatedAt']));
 foreach(['max','telegram'] as $channel){
  $template=$row['templates'][$channel];check32(str_starts_with($template,$text."\n\n")&&$row['expected_text_sha256'][$channel]===hash('sha256',$text),'accepted_caption_lost');
  $draft=monitor_hook_draft($template,$price);check32(str_starts_with($draft,$text."\n\n")&&substr_count($draft,'Узнать стоимость тура')===1,'facts_or_disclosures_changed');
  check32(strlen(mb_convert_encoding($draft,'UTF-16LE','UTF-8'))/2<=1024,'caption_too_long');
  check32($row['feed_binding']['posts'][$channel]===$row['posts'][$channel]&&$row['feed_binding']['offer_ids'][$channel]===($channel==='max'?'max_':'tg_').$tv,'feed_binding_changed');
  $pub=['publication_id'=>10+$i,'version_id'=>20+$i,'external_message_id'=>'fixture','snapshot'=>['text'=>$text,'post_url'=>$row['posts'][$channel],'metadata'=>[]]];
  check32(monitor_hook_caption_allowed($pub,$row,$channel,null),'original_caption_rejected');
  $pub['snapshot']['text'].=' manual';check32(!monitor_hook_caption_allowed($pub,$row,$channel,null),'manual_edit_accepted');
  $pub['snapshot']['text']=$draft;$pub['snapshot']['metadata']['monitor_price_record_id']=$price['record_id'];
  check32(!monitor_hook_caption_allowed($pub,$row,$channel,null),'prior_automated_edit_without_receipt_accepted');
 }
}
check32(count(array_unique(array_merge(array_column(array_column($all['rows'],'posts'),'max'),array_column(array_column($all['rows'],'posts'),'telegram'))))===1320,'duplicate_post_url');
echo 'MONITOR_EXPANSION32_OK checks='.$checks.' roster=660 old628_preserved=1 new32=32 provider_calls=0 supplier_calls=0 writes=0',PHP_EOL;
