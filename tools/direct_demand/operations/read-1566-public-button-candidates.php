<?php
declare(strict_types=1);
// Existing HOTEL reader: public discovery only. No native admission or write mode.
require dirname(__DIR__,3).'/accepted/hotel-posts-telegram-main19.php';
function requireScan(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function normScan(string $s):string{
 $s=mb_strtoupper(html_entity_decode($s,ENT_QUOTES|ENT_HTML5,'UTF-8'),'UTF-8');
 $s=strtr($s,['Ё'=>'Е','Ö'=>'O','Ü'=>'U','Ç'=>'C','İ'=>'I','É'=>'E','Á'=>'A','Ä'=>'A','Ñ'=>'N','È'=>'E','Ô'=>'O']);
 return trim(preg_replace('/[^\p{L}\p{N}]+/u',' ',$s));
}
function getScan(string $url,int $limit=1500000):array{
 $allowed=$url==='https://app.anytoour.ru/feed/hotel-posts-telegram.xml'||preg_match('~\Ahttps://t\.me/s/anytour(?:\?before=[1-9][0-9]{0,8})?\z~D',$url);
 requireScan((bool)$allowed,'public_scope_invalid');$body='';$retry=null;
 $c=curl_init($url);curl_setopt_array($c,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>18,CURLOPT_USERAGENT=>'Mozilla/5.0',CURLOPT_HEADERFUNCTION=>static function($c,string $line)use(&$retry):int{if(stripos($line,'retry-after:')===0)$retry=substr(trim(substr($line,12)),0,160);return strlen($line);},CURLOPT_WRITEFUNCTION=>static function($c,string $chunk)use(&$body,$limit):int{if(strlen($body)+strlen($chunk)>$limit)return 0;$body.=$chunk;return strlen($chunk);}]);
 $ok=curl_exec($c)!==false&&curl_errno($c)===0;$status=(int)curl_getinfo($c,CURLINFO_HTTP_CODE);curl_close($c);
 return ['ok'=>$ok&&$status===200,'status'=>$status,'retry_after'=>$retry,'body'=>$body,'sha256'=>hash('sha256',$body)];
}
$out=['schema'=>1,'operation'=>'hotel_telegram_button_candidate_public_scan','write_admission'=>false,'provider_writes'=>0,'production_writes'=>0,'bot_starts'=>0,'ok'=>false,'pages'=>[],'posts'=>[]];$start=microtime(true);
try{
 $f=getScan('https://app.anytoour.ru/feed/hotel-posts-telegram.xml',4000000);requireScan($f['ok'],'feed_unavailable');
 $doc=new DOMDocument();requireScan($doc->loadXML($f['body'],LIBXML_NONET|LIBXML_NOCDATA),'feed_invalid');$catalog=[];
 foreach($doc->getElementsByTagName('offer') as $offer){$id=$offer->getAttribute('id');requireScan((bool)preg_match('/\Atg_([1-9][0-9]*)\z/D',$id,$m),'offer_identity_invalid');
  $name=trim((string)$offer->getElementsByTagName('name')->item(0)?->textContent);$title=preg_replace('/\A(?:Путевки|Путёвки|Туры)\s+в\s+/ui','',$name);$normal=normScan($title);$short=trim(preg_replace('/\b(?:HOTEL|RESORT|SPA|THE|HOTELS)\b/u','',$normal));$short=preg_replace('/\s+/u',' ',$short);
  $excluded=preg_match('/доминикан|punta cana|la romana|bayahibe|bavaro/iu',$offer->textContent)===1;
  $catalog[(int)$m[1]]=['hotel_id'=>(int)$m[1],'name'=>$name,'source_url'=>trim((string)$offer->getElementsByTagName('url')->item(0)?->textContent),'patterns'=>array_values(array_unique(array_filter([$normal,$short],static fn($s)=>mb_strlen($s)>=7))),'excluded_direction'=>$excluded];
 }
 requireScan(count($catalog)===1055,'feed_inventory_changed');$raw=anytourTelegramFeedOfferUrls($f['body']);$map=anytourTelegramFeedDestinationPosts($raw);
 $blockedHotels=array_fill_keys([245,82716,91404,1439],true);$blockedTargets=array_fill_keys([1047,1058,1103,1109,1584,1585,1586,1620,1651,1653,1654,1665,1679,1695,1696,1700,1705,1706,1709,1723,1724,1735,1739,1741,1742,1743,1744,1746,1748,1548,907,1136,1042,1012,220,1016,1227,913,954,1788,923,930,266,594,716,1043,1207,1678],true);
 foreach($map as $offer=>$url)if(preg_match('~\Ahttps://t\.me/anytour/([1-9][0-9]*)\z~D',$url,$m)){$blockedTargets[(int)$m[1]]=true;$blockedHotels[(int)substr($offer,3)]=true;}
 $out['feed_sha256']=$f['sha256'];$out['feed_count']=count($catalog);$out['rank_source']=['operation'=>'first_party_hotel_entry_rank_v1','from_utc'=>'2026-11-16T21:00:00Z','until_utc_exclusive'=>'2026-11-23T21:00:00Z','real_advertising_click_rank_asserted'=>false];$rankIds=[64918, 241, 293, 358, 1569, 2493, 4079, 4091, 7458, 15693, 75100, 246, 253, 466, 132, 150, 206, 231, 266, 288, 371, 483, 713, 768, 1096, 1257, 1972, 2588, 11791, 12111, 14140, 26844, 28460, 54605, 64914, 82106, 107938, 43689, 236, 381, 454, 465, 1035, 1105, 1451, 1960, 2470, 3415, 4270, 50869, 51238, 56092, 63191, 77914, 79071, 85417, 106, 109, 114, 125, 133, 174, 195, 240, 247, 263, 268, 272, 287, 302, 341, 389, 393, 408, 415, 441, 497, 513, 599, 632, 706, 800, 932, 948, 954, 955, 975, 996, 1032, 1033, 1055, 1067, 1074, 1100, 1124, 1142, 1143, 1149, 1151, 1221, 1313, 1334, 1336, 1344, 1402, 1420, 1421, 1429, 1439, 1588, 1589, 1647, 1959, 1971, 1981, 2435, 2436, 2457, 2458, 2478, 2522, 2794, 3405, 3452, 4054, 4060, 4074, 8020, 8094, 9321, 9373, 12107, 12114, 13870, 14135, 15873, 15879, 22791, 23723, 27426, 27652, 28272, 28327, 28509, 28581, 28754, 30158, 32322, 32342, 40222, 41149, 41495, 45496, 46670, 49084, 54193, 55511, 57650, 58088, 61159, 61623, 65108, 65164, 65712, 65877, 66547, 66564, 69116, 73082, 73343, 73477, 74503, 76318, 80713, 82504, 82606, 82716, 90236, 91404, 98723, 115918, 126417, 127988, 131884];$rankByHotel=array_flip($rankIds);
 $before=null;$seen=[];$candidates=[];$cls=static fn($s)=>'contains(concat(" ",normalize-space(@class)," ")," '.$s.' ")';
 for($page=0;$page<40;$page++){
  if(microtime(true)-$start>120){$out['stop_reason']='public_time_budget';break;}
  $url='https://t.me/s/anytour'.($before===null?'':'?before='.$before);$r=getScan($url);$out['pages'][]=['url'=>$url,'status'=>$r['status'],'sha256'=>$r['sha256'],'retry_after'=>$r['retry_after']];
  if(!$r['ok']){$out['stop_reason']=$r['status']===429?'retry_after_held':'page_unavailable';break;}
  $dom=new DOMDocument();$prev=libxml_use_internal_errors(true);try{$loaded=$dom->loadHTML($r['body'],LIBXML_NONET);}finally{libxml_clear_errors();libxml_use_internal_errors($prev);}requireScan($loaded,'archive_html_invalid');
  $xp=new DOMXPath($dom);$nodes=$xp->query('//*[@data-post]');$ids=[];
  foreach($nodes as $node){if(!preg_match('~\Aanytour/([1-9][0-9]*)\z~D',$node->getAttribute('data-post'),$m))continue;$mid=(int)$m[1];$ids[]=$mid;if(isset($seen[$mid]))continue;$seen[$mid]=true;
   $texts=$xp->query('.//*['.$cls('tgme_widget_message_text').']',$node);$photos=$xp->query('.//*['.$cls('tgme_widget_message_photo_wrap').']',$node);$dates=$xp->query('.//time[@datetime]',$node);$other=$xp->query('.//video|.//audio|.//*['.$cls('tgme_widget_message_video_thumb').' or '.$cls('tgme_widget_message_roundvideo').']',$node);
   $html='';if($texts->length===1)foreach($texts->item(0)->childNodes as $c)$html.=$dom->saveHTML($c);$caption=html_entity_decode(strip_tags(preg_replace('~<br\s*/?>~i',"\n",$html)),ENT_QUOTES|ENT_HTML5,'UTF-8');$normal=' '.normScan($caption).' ';$matches=[];
   foreach($catalog as $hid=>$hotel){if($hotel['excluded_direction'])continue;foreach($hotel['patterns'] as $term)if(str_contains($normal,' '.$term.' ')){$matches[$hid]=$hotel;break;}}
   $buttons=[];foreach($xp->query('.//a['.$cls('tgme_widget_message_inline_button').']',$node)as$a){$href=$a->getAttribute('href');$buttons[]=['text'=>trim($a->textContent),'url_sha256'=>hash('sha256',$href),'hotel_token_url'=>preg_match('~\Ahttps://t\.me/anyru_bot\?start=p_[A-Za-z0-9_-]{8,60}\z~D',$href)?$href:null,'legacy_moscow_entry'=>str_contains($href,'tg_anytour_msk')];}
   $review=preg_match('/отзыв|впечатлен|наши? турист|наши? менеджер|мы (?:были|отдыхали|побывали)|я (?:был|была|отдыхал|отдыхала)|вернулись|инспекц|рассказывают|рассказывает|благодарн|спасибо/iu',$caption)===1;
   $terms=preg_match('/вылет|заезд|цена|стоимост|\d[\d\s]*(?:₽|руб[.\s]|\$|€)|\b\d{1,2}[.\/]\d{1,2}\b/iu',$caption)===1;
   $entry=['mid'=>$mid,'url'=>'https://t.me/anytour/'.$mid,'published_at'=>$dates->length===1?$dates->item(0)->getAttribute('datetime'):null,'caption'=>$caption,'caption_sha256'=>hash('sha256',$caption),'caption_nodes'=>$texts->length,'photos'=>$photos->length,'other_media'=>$other->length,'buttons'=>$buttons,'hotel_matches'=>array_values($matches),'lexical_protection_hold'=>$review,'commercial_terms_need_review'=>$terms,'write_admission'=>false];
   $reason=isset($blockedTargets[$mid])?'known_occupied_reserved_rejected_or_protected_target':($review||$other->length>0?'review_or_video_hold':($texts->length!==1||$photos->length!==1?'not_single_commercial_photo':(count($matches)!==1?'hotel_identity_not_unique':(isset($blockedHotels[array_key_first($matches)])?'hotel_already_assigned':(array_filter($buttons,static fn($b)=>$b['hotel_token_url']!==null)?'hotel_button_already_seen':($terms?'commercial_terms_need_review':'public_button_pilot_candidate'))))));
   $entry['screening']=$reason;if($reason==='public_button_pilot_candidate')$candidates[]=$entry;$out['posts'][]=$entry;
  }
  if($ids===[]){$out['stop_reason']='archive_end';break;}$next=min($ids);if($before!==null&&$next>=$before){$out['stop_reason']='pagination_not_advancing';break;}$before=$next;
 }
 foreach($candidates as &$candidate){$candidate['first_party_rank']=isset($rankByHotel[$candidate['hotel_matches'][0]['hotel_id']])?$rankByHotel[$candidate['hotel_matches'][0]['hotel_id']]+1:null;}unset($candidate);usort($candidates,static fn($a,$b)=>($a['first_party_rank']??9999)<=>($b['first_party_rank']??9999));$out['posts_seen']=count($seen);$out['public_candidates']=$candidates;$out['screening_counts']=array_count_values(array_column($out['posts'],'screening'));$out['coverage_complete']=($out['stop_reason']??null)==='archive_end';$out['ok']=true;
}catch(Throwable $e){$out['reason']=get_class($e)===RuntimeException::class?$e->getMessage():'public_scan_unavailable';}
$out['read_at_utc']=gmdate(DATE_ATOM);$out['read_elapsed_ms']=(int)((microtime(true)-$start)*1000);
file_put_contents('pub/public-button-candidates.json',json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
echo json_encode(['ok'=>$out['ok'],'posts'=>$out['posts_seen']??0,'candidates'=>count($out['public_candidates']??[]),'stop'=>$out['stop_reason']??null,'reason'=>$out['reason']??null]),PHP_EOL;
exit($out['ok']?0:2);
