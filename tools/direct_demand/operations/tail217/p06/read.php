<?php
declare(strict_types=1);
const EXPECTED_INSTALLED_SHA='8281b387f258932601439eb789560f6b2cbabd4d';
const FEED_EXPORT_INPUT_B64='W3siaG90ZWxfaWQiOjY1MzQxLCJuYW1lIjoiQWtyYSBGZXRoaXllIFRoZSBSZXNpZGVuY2UiLCJhY2NlcHRlZF9maXJzdF9saW5lIjoi4pyI77iPINCi0YPRgNGLINCyIEFrcmEgRmV0aGl5ZSBUaGUgUmVzaWRlbmNlIiwiaW1hZ2VfdXJsIjoiaHR0cHM6Ly9yYXcuZ2l0aHVidXNlcmNvbnRlbnQuY29tL3B5YXRrb2ZmL21heC1zZWFyY2gtYm90LzdhYzllZmJjYzBmMDQ1NWViMmU5ZDgxZTI1N2NiYTA0YjJkODBkMzcvLmdpdGh1Yi90cmFmZmljLXB1Ymxpc2gvY29tbWVyY2lhbC13YXZlMi10ci1lZzIwLTIwMjYxMTE3LzY1MzQxLnBuZyIsImltYWdlX3NoYTI1NiI6IjRiNjAzOTRiN2NkZWQxY2M1Zjk0MmRmYTQ1MzZmNDQzNDg1NDcxNTcwNDYwNGQyY2NhZDg5ZWJiNThmYmIwYWEifSx7ImhvdGVsX2lkIjo0OTA4NCwibmFtZSI6IlRpdGFuaWMgTHV4dXJ5IENvbGxlY3Rpb24gQm9kcnVtIiwiYWNjZXB0ZWRfZmlyc3RfbGluZSI6IuKciO+4jyDQotGD0YDRiyDQsiBUaXRhbmljIEx1eHVyeSBDb2xsZWN0aW9uIEJvZHJ1bSIsImltYWdlX3VybCI6Imh0dHBzOi8vcmF3LmdpdGh1YnVzZXJjb250ZW50LmNvbS9weWF0a29mZi9tYXgtc2VhcmNoLWJvdC83YWM5ZWZiY2MwZjA0NTVlYjJlOWQ4MWUyNTdjYmEwNGIyZDgwZDM3Ly5naXRodWIvdHJhZmZpYy1wdWJsaXNoL2NvbW1lcmNpYWwtd2F2ZTItdHItZWcyMC0yMDI2MTExNy80OTA4NC5wbmciLCJpbWFnZV9zaGEyNTYiOiI5ZWZkNzM3YzE1NmIzNzQ5YWU4ZmZkNWNhYTlhOGZiNzRiNTdjODc5ZTc5MWU1ZjhhMTY0YWNhZDZlNmQzZDM5In0seyJob3RlbF9pZCI6NzU1MzgsIm5hbWUiOiJUaGUgTm9ybSBDb2xsZWN0aW9uIERvb3JhIiwiYWNjZXB0ZWRfZmlyc3RfbGluZSI6IuKciO+4jyDQotGD0YDRiyDQsiBUaGUgTm9ybSBDb2xsZWN0aW9uIERvb3JhIiwiaW1hZ2VfdXJsIjoiaHR0cHM6Ly9yYXcuZ2l0aHVidXNlcmNvbnRlbnQuY29tL3B5YXRrb2ZmL21heC1zZWFyY2gtYm90LzdhYzllZmJjYzBmMDQ1NWViMmU5ZDgxZTI1N2NiYTA0YjJkODBkMzcvLmdpdGh1Yi90cmFmZmljLXB1Ymxpc2gvY29tbWVyY2lhbC13YXZlMi10ci1lZzIwLTIwMjYxMTE3Lzc1NTM4LnBuZyIsImltYWdlX3NoYTI1NiI6IjcxMmNlMjU0YTE3NDhiYWE1MTM0YTc3Nzk1OWYyZWVlZTg4OGRlNDlkMjljYzgyZWYwN2QwZjZhOGMzNzY0YjQifSx7ImhvdGVsX2lkIjo4MjY0OSwibmFtZSI6IlJpeG9zIFBhcmsgQmVsZWsiLCJhY2NlcHRlZF9maXJzdF9saW5lIjoi4pyI77iPINCi0YPRgNGLINCyIFJpeG9zIFBhcmsgQmVsZWsiLCJpbWFnZV91cmwiOiJodHRwczovL3Jhdy5naXRodWJ1c2VyY29udGVudC5jb20vcHlhdGtvZmYvbWF4LXNlYXJjaC1ib3QvN2FjOWVmYmNjMGYwNDU1ZWIyZTlkODFlMjU3Y2JhMDRiMmQ4MGQzNy8uZ2l0aHViL3RyYWZmaWMtcHVibGlzaC9jb21tZXJjaWFsLXdhdmUyLXRyLWVnMjAtMjAyNjExMTcvODI2NDkucG5nIiwiaW1hZ2Vfc2hhMjU2IjoiYmY0MTkxOTY2OTMxODRlMWQxMTI1NjZlNmVlNWIyMWEwZWI5ODcxNWRjNWYxZjQ2ODI3YzAxOTU4OGRkNmMxYSJ9LHsiaG90ZWxfaWQiOjM3Mzc2LCJuYW1lIjoiQWtyYSBGZXRoaXllIFRVSSBCbHVlIFNlbnNhdG9yaSIsImFjY2VwdGVkX2ZpcnN0X2xpbmUiOiLinIjvuI8g0KLRg9GA0Ysg0LIgQWtyYSBGZXRoaXllIFRVSSBCbHVlIFNlbnNhdG9yaSIsImltYWdlX3VybCI6Imh0dHBzOi8vcmF3LmdpdGh1YnVzZXJjb250ZW50LmNvbS9weWF0a29mZi9tYXgtc2VhcmNoLWJvdC83YWM5ZWZiY2MwZjA0NTVlYjJlOWQ4MWUyNTdjYmEwNGIyZDgwZDM3Ly5naXRodWIvdHJhZmZpYy1wdWJsaXNoL2NvbW1lcmNpYWwtd2F2ZTItdHItZWcyMC0yMDI2MTExNy8zNzM3Ni5wbmciLCJpbWFnZV9zaGEyNTYiOiJjM2Q4Yzg2ZGNlYTdlMjA0OTkzNjc1ZjFkZDNjYzdkODA1OTM0NWE1MGI3NWU2YWY4NDM5YzE1MjlmNGQ4ZTBkIn0seyJob3RlbF9pZCI6MTE0OCwibmFtZSI6IkJvdGFuaWsgSG90ZWwgJiBSZXNvcnQiLCJhY2NlcHRlZF9maXJzdF9saW5lIjoi4pyI77iPINCi0YPRgNGLINCyIEJvdGFuaWsgSG90ZWwgJiBSZXNvcnQiLCJpbWFnZV91cmwiOiJodHRwczovL3Jhdy5naXRodWJ1c2VyY29udGVudC5jb20vcHlhdGtvZmYvbWF4LXNlYXJjaC1ib3QvN2FjOWVmYmNjMGYwNDU1ZWIyZTlkODFlMjU3Y2JhMDRiMmQ4MGQzNy8uZ2l0aHViL3RyYWZmaWMtcHVibGlzaC9jb21tZXJjaWFsLXdhdmUyLXRyLWVnMjAtMjAyNjExMTcvMTE0OC5wbmciLCJpbWFnZV9zaGEyNTYiOiIzZDgzOTdjOTZiNWM5NDZjNTdiMjMzZDRlYjM1MDJlYTJhYTlmYmE4ZDZhOGUyODI4NWE2YzgxZTVhZTE2NjI1In0seyJob3RlbF9pZCI6NzUxMDAsIm5hbWUiOiJCYXJ1dCBHb2lhIiwiYWNjZXB0ZWRfZmlyc3RfbGluZSI6IuKciO+4jyDQotGD0YDRiyDQsiBCYXJ1dCBHb2lhIiwiaW1hZ2VfdXJsIjoiaHR0cHM6Ly9yYXcuZ2l0aHVidXNlcmNvbnRlbnQuY29tL3B5YXRrb2ZmL21heC1zZWFyY2gtYm90LzdhYzllZmJjYzBmMDQ1NWViMmU5ZDgxZTI1N2NiYTA0YjJkODBkMzcvLmdpdGh1Yi90cmFmZmljLXB1Ymxpc2gvY29tbWVyY2lhbC13YXZlMi10ci1lZzIwLTIwMjYxMTE3Lzc1MTAwLnBuZyIsImltYWdlX3NoYTI1NiI6ImI1ZDVlYzE5M2JlNmE2ZGRkNzU2MWZjMThiNTAxM2U2MjE4OWE5NWRhNmU3NzE4YzkyZWExOGJmNmI3NjZjODIifSx7ImhvdGVsX2lkIjo4MDQ3OCwibmFtZSI6IkNBSkEgYnkgTWF4eCBSb3lhbCIsImFjY2VwdGVkX2ZpcnN0X2xpbmUiOiLinIjvuI8g0KLRg9GA0Ysg0LIgQ0FKQSBieSBNYXh4IFJveWFsIiwiaW1hZ2VfdXJsIjoiaHR0cHM6Ly9yYXcuZ2l0aHVidXNlcmNvbnRlbnQuY29tL3B5YXRrb2ZmL21heC1zZWFyY2gtYm90LzdhYzllZmJjYzBmMDQ1NWViMmU5ZDgxZTI1N2NiYTA0YjJkODBkMzcvLmdpdGh1Yi90cmFmZmljLXB1Ymxpc2gvY29tbWVyY2lhbC13YXZlMi10ci1lZzIwLTIwMjYxMTE3LzgwNDc4LnBuZyIsImltYWdlX3NoYTI1NiI6IjdiYTFlMjJjZThhZDIxZTQ4NjJjNTgzZDg0NGFmNjllNjU4MmY3YmNiYzkyNGMwNzFkMTJhMzVjOTM4ZWU1NmEifSx7ImhvdGVsX2lkIjoyNzY0NiwibmFtZSI6IkJvdGFuaWsgUGxhdGludW0iLCJhY2NlcHRlZF9maXJzdF9saW5lIjoi4pyI77iPINCi0YPRgNGLINCyIEJvdGFuaWsgUGxhdGludW0iLCJpbWFnZV91cmwiOiJodHRwczovL3Jhdy5naXRodWJ1c2VyY29udGVudC5jb20vcHlhdGtvZmYvbWF4LXNlYXJjaC1ib3QvN2FjOWVmYmNjMGYwNDU1ZWIyZTlkODFlMjU3Y2JhMDRiMmQ4MGQzNy8uZ2l0aHViL3RyYWZmaWMtcHVibGlzaC9jb21tZXJjaWFsLXdhdmUyLXRyLWVnMjAtMjAyNjExMTcvMjc2NDYucG5nIiwiaW1hZ2Vfc2hhMjU2IjoiNTk0N2ExZmFhMDE0MDNkNTJhMTMwODcyMTA2ZDVhNTg5NmFmMGQ1NDNjMjFjM2Q3YWY3NWM4NTllM2Q4OWVkNyJ9LHsiaG90ZWxfaWQiOjExNTAsIm5hbWUiOiJEZWxwaGluIERpdmEgUHJlbWllcmUiLCJhY2NlcHRlZF9maXJzdF9saW5lIjoi4pyI77iPINCi0YPRgNGLINCyIERlbHBoaW4gRGl2YSBQcmVtaWVyZSIsImltYWdlX3VybCI6Imh0dHBzOi8vcmF3LmdpdGh1YnVzZXJjb250ZW50LmNvbS9weWF0a29mZi9tYXgtc2VhcmNoLWJvdC83YWM5ZWZiY2MwZjA0NTVlYjJlOWQ4MWUyNTdjYmEwNGIyZDgwZDM3Ly5naXRodWIvdHJhZmZpYy1wdWJsaXNoL2NvbW1lcmNpYWwtd2F2ZTItdHItZWcyMC0yMDI2MTExNy8xMTUwLnBuZyIsImltYWdlX3NoYTI1NiI6IjU2NWE2NGI3ZmMxNTI0MzU1OWU2ZTExYzcyOGMzNTI4OWI4NWVlOTJiNWI2YjA5MzI4NGJhYjM0NDVmNGEzOTcifV0=';
const FEED_EXPORT_INPUT_SHA256='63d32299a21ec083ddb64b0701334a7b77eb629c207dcae1df3751f935304570';
const CANDIDATE_INPUT_B64='W10=';
const CANDIDATE_INPUT_SHA256='4f53cda18c2baa0c0354bb5f9a3ecbe5ed12ab4d8e11ba873c2f11161202b945';
ini_set('display_errors','0');ini_set('log_errors','0');
$out=['operation'=>'commercial-channel-hotel-feeds-20261117-read206','mode'=>'read_bounded_public_channel_post_feed_inputs','ok'=>false,'db_writes'=>0,'provider_calls'=>0,'message_sends'=>0];
function insist(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}

function feed_title(string $s):string{return preg_replace('/[^\pL\pN]+/u','',mb_strtolower(strtr(trim($s),['ё'=>'е','ö'=>'o','Ö'=>'o']),'UTF-8'))??'';}
function post_destination(string $url,string $platform):bool{
 $p=parse_url($url);if(!is_array($p)||($p['scheme']??'')!=='https'||isset($p['query'])||isset($p['fragment'])||isset($p['user'])||isset($p['pass'])||isset($p['port']))return false;
 $pattern=$platform==='telegram'?'~\Ahttps://t\.me/any_toour/[1-9][0-9]*\z~D':'~\Ahttps://max\.ru/(?:channel_anytour|c/-80492244209683)/[A-Za-z0-9_-]{1,100}\z~D';
 return preg_match($pattern,$url)===1;
}
function public_feed_image(string $url,string $sha):bool{
 $p=parse_url($url);if(!is_array($p)||($p['scheme']??'')!=='https'||isset($p['query'])||isset($p['fragment'])||isset($p['user'])||isset($p['pass'])||isset($p['port']))return false;
 $accepted=[
  "https://image-tc.galaxy.tf/wijpeg-bunhs9glqpbhgr07p92hv73nb/028-ls-landscape-104108318.jpg"=>"a739fa8310852f22af0b97552e39c446fdb2ee5c927d2d78f64e7b8f272cc6e0",
  "https://image-tc.galaxy.tf/wijpeg-43nq1ttkef5hcqekzjvfa0g4j/over-view.jpg"=>"e9cf3143b97f6cc1b3d2e693f0c5da62061a06f4e6d8245374cd94c64c83e7a7",
  "https://www.ahstatic.com/photos/b6f4_ho_00_p_1024x768.jpg"=>"d5d901d4ff309da9fbc2f02323e84f7a13068ba2d0877f85706a8ff7f3e15d83",
  "https://d1wo7kaelp5eck.cloudfront.net/sunrise-resorts.com-1611976553/cms/cache/v2/67f3ffae5a3de.jpg/1200x630/fit/80/238e6a950d96e74d0cac4d2e2d44f808.jpg"=>"586ba6b0c901b39ff308558fc7e4f48538b3af9438b90ad9d7efd22d5d7fb154"
 ];
 if(isset($accepted[$url]))return hash_equals($accepted[$url],$sha);
 return ($p['host']??'')==='raw.githubusercontent.com'&&preg_match('~\A/pyatkoff/max-search-bot/[a-f0-9]{40}/\.github/traffic-publish/[A-Za-z0-9_./-]+\.png\z~D',(string)($p['path']??''))===1&&preg_match('/\A[a-f0-9]{64}\z/D',$sha)===1;
}
function channel_feed_build(array $inputs,array $hotels,array $publications):array{
 $byTitle=[];foreach($publications as $p){$cid=(int)($p['post_channel_id']??0);if(!in_array($cid,[9,10],true))continue;$platform=(string)($p['platform']??'');if(!in_array($platform,['telegram','max'],true))continue;$key=feed_title(explode("\n",(string)($p['text']??''),2)[0]);$byTitle[$key][$platform][]=(string)($p['post_url']??'');}
 $out=['rows'=>[],'deferred'=>[],'image_missing_count'=>0];$seen=[];
 foreach($inputs as $r){
  insist(array_diff(array_keys($r),['hotel_id','name','accepted_first_line','image_url','image_sha256'])===[],'channel_feed_input_private_field');
  $id=(int)($r['hotel_id']??0);insist($id>0&&!isset($seen[$id]),'channel_feed_input_duplicate');$seen[$id]=true;
  $h=$hotels[$id]??null;$pair=$byTitle[feed_title((string)($r['accepted_first_line']??''))]??[];$why=null;
  if(!is_array($h)||empty($h['is_active'])||(int)($h['country_id']??0)<=0||(int)($h['region_id']??0)<=0)$why='ACTIVE_NATIVE_CATALOG_MEMBERSHIP_MISSING';
  elseif(count($pair['telegram']??[])!==1||count($pair['max']??[])!==1)$why='CURRENT_CANONICAL_PAIR_NOT_UNIQUE';
  elseif(!post_destination($pair['telegram'][0],'telegram')||!post_destination($pair['max'][0],'max'))$why='PUBLIC_CHANNEL_POST_URL_NOT_PROVEN';
  elseif(!empty($r['image_url'])&&!public_feed_image((string)$r['image_url'],(string)($r['image_sha256']??'')))$why='PUBLIC_IMAGE_GUARD_FAILED';
  elseif(trim((string)($h['region']??''))===''||mb_strlen((string)$h['region'],'UTF-8')>25)$why='PUBLIC_DESTINATION_LABEL_REQUIRED';
  if($why!==null){$out['deferred'][]=['hotel_id'=>$id,'state'=>$why];continue;}
  $image=(string)($r['image_url']??'');if($image==='')$out['image_missing_count']++;
  $out['rows'][]=['hotel_id'=>$id,'name'=>(string)$r['name'],'country_id'=>(int)$h['country_id'],'region_id'=>(int)$h['region_id'],'destination'=>(string)$h['region'],'telegram_post_url'=>$pair['telegram'][0],'max_post_url'=>$pair['max'][0],'image_url'=>$image,'image_missing'=>$image===''];
 }return $out;
}
if(getenv('ANYTOUR_CHANNEL_FEED_IMAGE_FIXTURE')==='1'){
 insist(public_feed_image("https://image-tc.galaxy.tf/wijpeg-bunhs9glqpbhgr07p92hv73nb/028-ls-landscape-104108318.jpg","a739fa8310852f22af0b97552e39c446fdb2ee5c927d2d78f64e7b8f272cc6e0"),'fixture_accepted_image_310');
 insist(public_feed_image("https://image-tc.galaxy.tf/wijpeg-43nq1ttkef5hcqekzjvfa0g4j/over-view.jpg","e9cf3143b97f6cc1b3d2e693f0c5da62061a06f4e6d8245374cd94c64c83e7a7"),'fixture_accepted_image_1971');
 insist(public_feed_image("https://www.ahstatic.com/photos/b6f4_ho_00_p_1024x768.jpg","d5d901d4ff309da9fbc2f02323e84f7a13068ba2d0877f85706a8ff7f3e15d83"),'fixture_accepted_image_67910');
 insist(public_feed_image("https://d1wo7kaelp5eck.cloudfront.net/sunrise-resorts.com-1611976553/cms/cache/v2/67f3ffae5a3de.jpg/1200x630/fit/80/238e6a950d96e74d0cac4d2e2d44f808.jpg","586ba6b0c901b39ff308558fc7e4f48538b3af9438b90ad9d7efd22d5d7fb154"),'fixture_accepted_image_68323');
 insist(!public_feed_image("https://image-tc.galaxy.tf/wijpeg-bunhs9glqpbhgr07p92hv73nb/028-ls-landscape-104108318.jpg",str_repeat('0',64)),'fixture_exact_owner_hash');
 insist(!public_feed_image("https://image-tc.galaxy.tf/wijpeg-bunhs9glqpbhgr07p92hv73nb/028-ls-landscape-104108318.jpg?token=private","a739fa8310852f22af0b97552e39c446fdb2ee5c927d2d78f64e7b8f272cc6e0"),'fixture_no_tokenized_owner_image');
 echo "CHANNEL_POST_FEED_OWNER_IMAGES_OK cases=6 exact_accepted_urls_and_hashes=4 unknown_hash_rejected=1 token_query_rejected=1 provider_calls=0 server_calls=0\n";exit;
}
if(getenv('ANYTOUR_CHANNEL_FEED_FIXTURE')==='1'){
 $r=['hotel_id'=>111,'name'=>'Hotel fixture','accepted_first_line'=>'Fixture hotel','image_url'=>'https://raw.githubusercontent.com/pyatkoff/max-search-bot/'.str_repeat('a',40).'/.github/traffic-publish/fixture.png','image_sha256'=>str_repeat('b',64)];
 $h=[111=>['id'=>111,'is_active'=>1,'country_id'=>1,'region_id'=>5,'region'=>'Хургада']];
 $p=[['post_channel_id'=>10,'platform'=>'telegram','text'=>'Fixture hotel','post_url'=>'https://t.me/any_toour/1'],['post_channel_id'=>9,'platform'=>'max','text'=>'Fixture hotel','post_url'=>'https://max.ru/channel_anytour/fixture']];
 insist(count(channel_feed_build([$r],$h,$p)['rows'])===1,'fixture_valid_pair');
 $swapped=$p;$swapped[0]['post_channel_id']=9;$swapped[1]['post_channel_id']=10;insist(channel_feed_build([$r],$h,$swapped)['rows']===channel_feed_build([$r],$h,$p)['rows'],'fixture_platform_not_registry_integer');
 $wrong=$p;$wrong[0]['post_url']='https://t.me/foreign_channel/1';insist(channel_feed_build([$r],$h,$wrong)['rows']===[],'fixture_wrong_tg');
 $wrong=$p;$wrong[1]['post_url']='https://max.ru/foreign_channel/fixture';insist(channel_feed_build([$r],$h,$wrong)['rows']===[],'fixture_wrong_max');
 foreach(['?token=private','#fragment'] as $suffix){$wrong=$p;$wrong[0]['post_url'].=$suffix;insist(channel_feed_build([$r],$h,$wrong)['rows']===[],'fixture_signed_or_fragment');}
 insist(channel_feed_build([$r],$h,array_merge($p,[$p[0]]))['rows']===[],'fixture_duplicate_current');
 insist(channel_feed_build([$r],$h,[$p[0]])['rows']===[],'fixture_missing_pair');
 $bad=$r;$bad['receipt']='private';$caught=false;try{channel_feed_build([$bad],$h,$p);}catch(RuntimeException $e){$caught=$e->getMessage()==='channel_feed_input_private_field';}insist($caught,'fixture_private_field');
 $badH=$h;$badH[111]['is_active']=0;insist(channel_feed_build([$r],$badH,$p)['rows']===[],'fixture_inactive');
 $bad=$r;$bad['image_url'].='?token=private';insist(channel_feed_build([$bad],$h,$p)['rows']===[],'fixture_private_image');
 $noImage=$r;$noImage['image_url']='';$noImage['image_sha256']='';$d=channel_feed_build([$noImage],$h,$p);insist(count($d['rows'])===1&&$d['image_missing_count']===1,'fixture_explicit_image_gap');
 echo "CHANNEL_POST_FEED_PREPARATION_OK cases=12 platform_from_canonical_channel=1 exact_public_destinations=1 private_fields_rejected=1 duplicate_missing_inactive_deferred=1 image_gap_explicit=1 server_calls=0\n";exit;
}


function targeted_public_post_query(array $inputs):array{
 insist(count($inputs)===1,'target_public_scope_count');
 $lines=[];
 foreach($inputs as $r){$line=$r['accepted_first_line']??null;insist(is_string($line)&&trim($line)!==''&&strlen($line)<=1500&&!str_contains($line,"\n")&&!str_contains($line,"\r"),'target_public_first_line');$lines[]=$line;}
 insist(count(array_unique($lines))===1,'target_public_duplicate_first_line');
 $first="SUBSTRING_INDEX(JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.text')),CHAR(10),1)";
 $sql="SELECT pp.post_channel_id,pc.platform,".$first." AS text,JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.post_url')) AS post_url FROM post_publications pp JOIN post_channels pc ON pc.id=pp.post_channel_id JOIN post_versions v ON v.id=pp.current_version_id AND v.publication_id=pp.id WHERE pp.post_channel_id IN (9,10) AND v.state='sent' AND BINARY ".$first." IN (?) ORDER BY pp.id LIMIT 3";
 return [$sql,$lines];
}

function binding_query(array $inputs):array {
 insist(count($inputs)===1,'binding_input_count');
 $urls=[];foreach($inputs as $r){insist(is_array($r)&&isset($r['hotel_id'],$r['platform'],$r['post_url'])&&(int)$r['hotel_id']>0&&in_array($r['platform'],['telegram','max'],true),'binding_input_fields');$urls[]=$r['post_url'];}
 insist(count(array_unique($urls))===count($urls),'binding_duplicate_url');
 $url="JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.post_url'))";
 $sql="SELECT pp.id AS publication_id,pp.source_id,v.id AS version_id,pc.id AS post_channel_id,pc.platform,p.project_key,pc.bot_key,pc.canonical_bot_username,pc.channel_id,".$url." AS post_url FROM post_publications pp JOIN post_channels pc ON pc.id=pp.post_channel_id JOIN projects p ON p.id=pc.project_id JOIN conversation_sources s ON s.id=pp.source_id AND s.id=pc.source_id AND s.project_id=pc.project_id AND s.channel=pc.platform JOIN post_versions v ON v.id=pp.current_version_id AND v.publication_id=pp.id WHERE pc.id IN (9,10) AND p.project_key='anytour' AND p.is_active=1 AND s.is_active=1 AND pc.is_enabled=1 AND s.handling_mode IN ('manager','ask') AND v.state='sent' AND BINARY ".$url." IN (".implode(',',array_fill(0,count($urls),'?')).") ORDER BY pp.id LIMIT ".(count($urls)+1);
 return [$sql,$urls];
}
function bind_rows(array $inputs,array $rows):array {
 insist(count($rows)===count($inputs),'binding_pair_count');
 $by=[];$seen=[];foreach($rows as $row){$url=(string)$row['post_url'];insist(!isset($by[$url]),'binding_ambiguous_url');$by[$url]=$row;}
 $bound=[];
 foreach($inputs as $r){
  $row=$by[$r['post_url']]??null;$platform=$r['platform'];$bot=$platform==='telegram'?'anyru_bot':'id9704048781_1_bot';
  insist(is_array($row)&&$row['project_key']==='anytour'&&$row['platform']===$platform&&$row['bot_key']===$bot&&$row['canonical_bot_username']===$bot,'binding_project_platform_postbot');
  insist(ctype_digit((string)$row['publication_id'])&&(int)$row['publication_id']>0&&!isset($seen[(string)$row['publication_id']]),'binding_duplicate_private_id');
  $seen[(string)$row['publication_id']]=true;
  $offer=($platform==='telegram'?'tg_':'max_').$r['hotel_id'];
  $alias='h1_'.substr(hash('sha256',"anytour|".$offer."|".$r['post_url']),0,32);
  $bound[]=['hotel_id'=>(int)$r['hotel_id'],'platform'=>$platform,'public_offer_id'=>$offer,'public_route_alias'=>$alias,
   'publication_id'=>(string)$row['publication_id'],'version_id'=>(int)$row['version_id'],'post_channel_id'=>(int)$row['post_channel_id'],
   'source_id'=>(int)$row['source_id'],'project_key'=>'anytour','post_bot_key'=>$bot,'channel_id'=>(string)$row['channel_id'],'post_url'=>$r['post_url']];
 }
 return $bound;
}


if(getenv('ANYTOUR_CHANNEL_BATCH38_SCOPE_FIXTURE')==='1'){
 $in=[['accepted_first_line'=>'Fixture Susesi1543']];
 [$q,$p]=targeted_public_post_query($in);
 insist($p===['Fixture Susesi1543']&&substr_count($q,'?')===1&&str_ends_with($q,' LIMIT 3')&&!str_contains($q,'SELECT pp.id'),'fixture_new1_firstline_limit3');
 $private=[['hotel_id'=>1543,'platform'=>'max','post_url'=>'https://max.ru/channel_anytour/fixture1543']];
 [$q,$p]=binding_query($private);
 insist(count($p)===1&&substr_count($q,'?')===1&&str_ends_with($q,' LIMIT 2')&&str_contains($q,"p.project_key='anytour'")&&!str_contains($q,'receipt_json')&&!str_contains($q,"'$.text'"),'fixture_new1_private_url_limit2');
 $rows=[['publication_id'=>'1','source_id'=>1,'version_id'=>1,'post_channel_id'=>9,'platform'=>'max','project_key'=>'anytour','bot_key'=>'id9704048781_1_bot','canonical_bot_username'=>'id9704048781_1_bot','channel_id'=>'fixture','post_url'=>$private[0]['post_url']]];
 $bound=bind_rows($private,$rows);insist(count($bound)===1&&$bound[0]['hotel_id']===1543&&$bound[0]['public_offer_id']==='max_1543','fixture_new1_alias_identity');
 $rows[0]['bot_key']='id9704048781_2_bot';$caught=false;try{bind_rows($private,$rows);}catch(RuntimeException $e){$caught=$e->getMessage()==='binding_project_platform_postbot';}insist($caught,'fixture_miniapp_not_postbot');
 echo "READ14_BATCH38_NEW1_SCOPE_OK new_cases=3 old_fixtures_rerun=0 server_calls=0\n";exit;
}


function batch20261107_input_scope(array $in):void{
 insist(count($in)===10&&array_column($in,'hotel_id')===[65341,49084,75538,82649,37376,1148,75100,80478,27646,1150],'read17_exact_new1_scope');
 insist(count(array_unique(array_column($in,'accepted_first_line')))===10,'read17_duplicate_firstline');
}
if(getenv('ANYTOUR_CHANNEL_BATCH51_SCOPE_FIXTURE')==='1'){
 $in=json_decode(base64_decode(FEED_EXPORT_INPUT_B64,true),true,9,JSON_THROW_ON_ERROR);batch20261107_input_scope($in);
 foreach($in as $r){[$q,$p]=targeted_public_post_query([$r]);insist(count($p)===1&&substr_count($q,'?')===1&&str_ends_with($q,' LIMIT 3')&&!str_contains($q,'SELECT pp.id'),'read17_new1_singleton_public_query');}
 $bad=$in;$bad[0]['hotel_id']=1422;$rejected=false;try{batch20261107_input_scope($bad);}catch(Throwable $e){$rejected=true;}insist($rejected,'read17_old160_member_excluded');
 foreach($in as $r){[$q,$p]=binding_query([['hotel_id'=>$r['hotel_id'],'platform'=>'max','post_url'=>'https://max.ru/channel_anytour/fixture'.$r['hotel_id']]]);insist(count($p)===1&&substr_count($q,'?')===1&&str_ends_with($q,' LIMIT 2')&&str_contains($q,"p.project_key='anytour'")&&!str_contains($q,'receipt_json')&&!str_contains($q,"'$.text'"),'read17_new1_private_singleton_limit');}
 echo "READ17_NEW1_SCOPE_OK new_cases=3 previous160_read=0 old_fixtures_rerun=0 server_calls=0 provider_calls=0 writes=0\n";exit;
}
try {
    if(PHP_SAPI!=='cli'||trim((string)shell_exec('git rev-parse HEAD'))!==EXPECTED_INSTALLED_SHA)throw new RuntimeException('installed_source_changed');
    // Inspect only the unchanged, previously accepted publishing surface.
    $accepted=[
        "integrations/ChannelPostApiTransport.php"=>"300634d77cb9fddf51adf9869974f956cca6e3f9",
        "migrations/033_channel_post_context.sql"=>"0530458030a4bfe6b6cc55d1e5e441745c29b947",
        "migrations/036_channel_post_member_delivery.sql"=>"1bda463e52b33130ad12752ddef4ee5c92699c05",
        "migrations/040_channel_post_reconciliation.sql"=>"0f24b838eacd45333c5ade9a2991d060488c6035",
        "services/ChannelPostAdminService.php"=>"b80eefc801054bfe6fdb58ec345ecdf687b2e89a",
        "services/ChannelPostAutomationService.php"=>"f5975ab95de5f45e087eb7a0f768f566c5bca648",
        "services/ChannelPostButtonTransport.php"=>"7b6711f1de63000346d8e5789f2dbb7f725535b8",
        "services/ChannelPostEntryService.php"=>"1982ff7368763edf8b7238c3142e3cc28579e991",
        "services/ChannelPostMaxAlbumRecovery.php"=>"de266cd068587cc3b2ff83f308a966ea66887a04",
        "services/ChannelPostMediaService.php"=>"f794efd0f6f24df916e18909ff6281b0bc928433",
        "services/ChannelPostMemberDeliveryService.php"=>"20c1d7984898b2a5996a5f9e20e4d2d8c20b4778",
        "services/ChannelPostReconciliationService.php"=>"43133f576845c14446102dedfffb7f0de2d4ba1c",
        "services/ChannelPostReconciliationState.php"=>"d9583ceca80d1424a44ade3561c21282a23a52f0",
        "services/ChannelPostRegistryService.php"=>"7304af19cc19666f00ca4ead1b04c2bdce719646",
        "services/ChannelPostRuntime.php"=>"2f1dd9b77474fdbaa92f0402f3b0aa170c595474",
        "services/ChannelPostTelegramAlbum.php"=>"faa1a14bc279a4af33936282126bf0d46e343040",
        "services/ChannelPostWebhookConfigService.php"=>"d5fdcb056088f1e3a541b4046325d97eef6417d9",
        "services/ConversationDb.php"=>"bb6281faf55aca7beeae7f8a57389d19751936f7",
        "services/MaxTlsConfig.php"=>"032c0211ee3f39c6f0d27c669abd5b467e321dea",
        "services/MaxTransport.php"=>"2cb4f8786b4704a8e62a3a9c40dc2cc73dad6159",
        "services/ProjectConfig.php"=>"63c17c8103412f8cff8cdcc4bbb062e744106af9",
        "services/ProjectConfiguration.php"=>"07019d473c588e3386ace42977db93092e8112e3",
    ];
    foreach($accepted as $path=>$blob)if(trim((string)shell_exec('git hash-object -- '.escapeshellarg($path)))!==$blob)throw new RuntimeException('publishing_surface_changed');
    ob_start();try {
        require getcwd().'/config.php';
        require_once getcwd().'/services/ChannelPostRuntime.php';
        require_once getcwd().'/services/ChannelPostAdminService.php';
        require_once getcwd().'/services/ChannelPostEntryService.php';
        require_once getcwd().'/services/MaxTransport.php';
        require_once getcwd().'/integrations/ChannelPostApiTransport.php';
        require_once getcwd().'/services/ChannelPostButtonTransport.php';
    } finally {ob_end_clean();}



    require_once getcwd().'/services/HotelDatabase.php';
    insist(HotelDatabase::configured(),'hotel_catalog_connection_not_configured');
    $catalog=HotelDatabase::connection();

    $raw=base64_decode(FEED_EXPORT_INPUT_B64,true);insist(is_string($raw)&&hash('sha256',$raw)===FEED_EXPORT_INPUT_SHA256,'feed_input_hash_changed');
    $inputs=json_decode($raw,true,9,JSON_THROW_ON_ERROR);insist(is_array($inputs),'feed_scope_changed');batch20261107_input_scope($inputs);
    $ids=array_column($inputs,'hotel_id');insist(count(array_unique($ids))===10,'feed_scope_duplicate');
    $catalog->exec('SET TRANSACTION READ ONLY');$catalog->beginTransaction();
    $registry=ConversationDb::connection();$registry->exec('SET TRANSACTION READ ONLY');$registry->beginTransaction();
    $sql='SELECT h.id,h.name,h.is_active,h.country_id,h.region_id,r.name AS region FROM catalog_hotels h LEFT JOIN catalog_regions r ON r.id=h.region_id AND r.country_id=h.country_id WHERE h.id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY h.id';
    $q=$catalog->prepare($sql);$q->execute($ids);$hotels=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $h)$hotels[(int)$h['id']]=$h;
    $built=['rows'=>[],'deferred'=>[],'image_missing_count'=>0];
    foreach($inputs as $input){
      [$publicSql,$publicParams]=targeted_public_post_query([$input]);
      $publicQ=$registry->prepare($publicSql);$publicQ->execute($publicParams);$publications=$publicQ->fetchAll(PDO::FETCH_ASSOC);
      insist(count($publications)<3,'canonical_read_limit_reached');
      $one=channel_feed_build([$input],$hotels,$publications);
      $built['rows']=array_merge($built['rows'],$one['rows']);$built['deferred']=array_merge($built['deferred'],$one['deferred']);$built['image_missing_count']+=$one['image_missing_count'];
    }
    $out+=$built;insist(count($built['rows'])===10&&count($built['deferred'])===0,'new_reviewed_pairs_required');
    $out['private_max_bindings']=[];
    foreach($built['rows'] as $r){
      $input=['hotel_id'=>$r['hotel_id'],'platform'=>'max','post_url'=>$r['max_post_url']];
      [$bindingSql,$bindingParams]=binding_query([$input]);$bindingQ=$registry->prepare($bindingSql);$bindingQ->execute($bindingParams);$bindingRows=$bindingQ->fetchAll(PDO::FETCH_ASSOC);
      insist(count($bindingRows)<2,'new_max_binding_limit_reached');
      $out['private_max_bindings']=array_merge($out['private_max_bindings'],bind_rows([$input],$bindingRows));
    }
    $out['private_binding_count']=count($out['private_max_bindings']);
    // Exact empty candidate scope: all planned FIRST clearances are already retained.
    $candidateRaw=base64_decode(CANDIDATE_INPUT_B64,true);insist(is_string($candidateRaw)&&hash('sha256',$candidateRaw)===CANDIDATE_INPUT_SHA256,'candidate_input_hash_changed');
    $candidates=json_decode($candidateRaw,true,32,JSON_THROW_ON_ERROR);insist(count($candidates)===0&&array_column($candidates,'hotel_id')===[],'candidate_scope_changed');
    $first="SUBSTRING_INDEX(JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.text')),CHAR(10),1)";
    $native="JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.metadata.hotel_catalog_id'))";
    $logical="JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.metadata.logical_id'))";
    $clear=[];
    foreach($candidates as $candidate){
      insist((int)$candidate['hotel_id']>0&&count($candidate['aliases'])>=1&&count($candidate['aliases'])<=12,'candidate_invalid');
      $predicates=['CAST('.$native.' AS UNSIGNED)=?'];$params=[$candidate['hotel_id']];
      if(is_string($candidate['logical_id']??null)){$predicates[]='BINARY '.$logical.'=?';$params[]=$candidate['logical_id'];}
      foreach($candidate['aliases'] as $alias){insist(is_string($alias)&&trim($alias)!=='','alias_invalid');$predicates[]='LOCATE(?,LOWER('.$first.'))>0';$params[]=mb_strtolower($alias,'UTF-8');}
      $sql="SELECT pp.id AS publication_id,pc.platform,v.id AS version_id,v.state,".$first." AS first_line,".$native." AS hotel_catalog_id,".$logical." AS logical_id,JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.post_url')) AS post_url,SHA2(JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.text')),256) AS text_sha256 FROM post_publications pp JOIN post_channels pc ON pc.id=pp.post_channel_id JOIN post_versions v ON v.id=pp.current_version_id AND v.publication_id=pp.id WHERE pp.post_channel_id IN (9,10) AND (".implode(' OR ',$predicates).") ORDER BY pp.id LIMIT 11";
      $statement=$registry->prepare($sql);$statement->execute($params);$found=$statement->fetchAll(PDO::FETCH_ASSOC);insist(count($found)<11,'targeted_match_limit_reached');$matches=[];
      foreach($found as $r){
        $reasons=[];if((int)($r['hotel_catalog_id']??0)===(int)$candidate['hotel_id'])$reasons[]='native_hotel_catalog_id';
        if(is_string($candidate['logical_id']??null)&&($r['logical_id']??null)===$candidate['logical_id'])$reasons[]='exact_logical_id';
        $folded=mb_strtolower((string)$r['first_line'],'UTF-8');foreach($candidate['aliases'] as $alias)if(mb_strpos($folded,mb_strtolower($alias,'UTF-8'),0,'UTF-8')!==false)$reasons[]='literal_case_folded_alias:'.$alias;
        if($reasons===[])continue;
        $r['match_reasons']=array_values(array_unique($reasons));$r['same_text_hash']=is_string($candidate['expected_text_sha256']??null)&&$r['text_sha256']===$candidate['expected_text_sha256'];$matches[]=$r;
      }
      $clear[]=['native_hotel_id'=>$candidate['hotel_id'],'scope'=>$candidate['scope'],'logical_id'=>$candidate['logical_id'],'aliases'=>$candidate['aliases'],'matches'=>$matches,'clear'=>$matches===[],'limit_reached'=>false];
    }
    $out['candidate_clearance']=$clear;$out['candidate_count']=0;$out['read_at_utc']=gmdate('c');
    // Exact original IDs only; existing registry method performs SELECT/config reads.
    $retained=json_decode(base64_decode('W3siaG90ZWxfaWQiOjY1MzQxLCJwbGF0Zm9ybSI6InRlbGVncmFtIiwicHVibGljYXRpb25faWQiOjE1OTIsInZlcnNpb25faWQiOjcxMjAsInBvc3RfdXJsIjoiaHR0cHM6Ly90Lm1lL2FueV90b291ci83MjAiLCJjaGFubmVsX2lkIjoiLTEwMDIyNjI2NjY2MTYiLCJtZXNzYWdlX2lkIjoiNzIwIiwidGV4dF9zaGEyNTYiOiI2ZWUxNTMzMWY0NzZkZTFlOWVmZTQ3ZmRmOWE3NWZlM2YyYmJjMGZlMDk5YTIxMmNjZDc1YjBkZjAxMTZjMGU2IiwicGhvdG9fc2hhMjU2IjoiNGI2MDM5NGI3Y2RlZDFjYzVmOTQyZGZhNDUzNmY0NDM0ODU0NzE1NzA0NjA0ZDJjY2FkODllYmI1OGZiYjBhYSIsImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6ImNkYTMzZTBjNzIzYTA3MmVhNTI4NGNjZDQwNDJlZDc5YzYyODE3NWMxZThjMGRkZWZhMzc1OGEwOGIwMTA4MTYifSx7ImhvdGVsX2lkIjo0OTA4NCwicGxhdGZvcm0iOiJ0ZWxlZ3JhbSIsInB1YmxpY2F0aW9uX2lkIjoxNTkzLCJ2ZXJzaW9uX2lkIjo3MTIxLCJwb3N0X3VybCI6Imh0dHBzOi8vdC5tZS9hbnlfdG9vdXIvNzIxIiwiY2hhbm5lbF9pZCI6Ii0xMDAyMjYyNjY2NjE2IiwibWVzc2FnZV9pZCI6IjcyMSIsInRleHRfc2hhMjU2IjoiM2JmNTIxYTFiYzM2MjVhMmUzNTk3OTRjMjUyZjc5NTNkNDEwY2VlMjE3OTY5OGI5MDNjMmFkNDBiNWM0ZTQzZSIsInBob3RvX3NoYTI1NiI6IjllZmQ3MzdjMTU2YjM3NDlhZThmZmQ1Y2FhOWE4ZmI3NGI1N2M4NzllNzkxZTVmOGExNjRhY2FkNmU2ZDNkMzkiLCJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiI0MGNmMjZlYTg3YWFlYTU4YTUwZDM4Yjg0MzNhMmMxZmM4ZDAwYWM1NTU4NWMwMjQ1NmMzYmU0MzA0ZjYyZDBhIn0seyJob3RlbF9pZCI6NzU1MzgsInBsYXRmb3JtIjoidGVsZWdyYW0iLCJwdWJsaWNhdGlvbl9pZCI6MTU5NCwidmVyc2lvbl9pZCI6NzEyMiwicG9zdF91cmwiOiJodHRwczovL3QubWUvYW55X3Rvb3VyLzcyMiIsImNoYW5uZWxfaWQiOiItMTAwMjI2MjY2NjYxNiIsIm1lc3NhZ2VfaWQiOiI3MjIiLCJ0ZXh0X3NoYTI1NiI6ImM3MTQ1YjkxMmQxZTc3NjUxYWRkMDZjZjZlZjBhOWM1YzRkMDY3ZjAyYjk1YWU5MGU1NGNhODg5ZjNjN2M5MTIiLCJwaG90b19zaGEyNTYiOiI3MTJjZTI1NGExNzQ4YmFhNTEzNGE3Nzc5NTlmMmVlZWU4ODhkZTQ5ZDI5Y2M4MmVmMDdkMGY2YThjMzc2NGI0IiwiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiYWE3MmMxYmM4MDY5ODU5NWRhZGU1NzkxN2Y2M2I1N2Y0OTA4YTlmNDk4YmQxNGNkMzU4NWFhY2E3ZjQwN2E3NSJ9LHsiaG90ZWxfaWQiOjgyNjQ5LCJwbGF0Zm9ybSI6InRlbGVncmFtIiwicHVibGljYXRpb25faWQiOjE1OTUsInZlcnNpb25faWQiOjcxMjMsInBvc3RfdXJsIjoiaHR0cHM6Ly90Lm1lL2FueV90b291ci83MjMiLCJjaGFubmVsX2lkIjoiLTEwMDIyNjI2NjY2MTYiLCJtZXNzYWdlX2lkIjoiNzIzIiwidGV4dF9zaGEyNTYiOiJjNzdkNWEzYzFmNGE3Mjc1ZWVhYTVlY2VkYmZiMGNjY2YyMWZkM2FkZjQ0NjM1ZWYwMDEyOWE0OWFiMDhhMDhhIiwicGhvdG9fc2hhMjU2IjoiYmY0MTkxOTY2OTMxODRlMWQxMTI1NjZlNmVlNWIyMWEwZWI5ODcxNWRjNWYxZjQ2ODI3YzAxOTU4OGRkNmMxYSIsImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6IjAxMTNkNjFhNzU4MWE5Yjg5ODkxNjI3Nzg4NTNjZDM2ZjhmYzM2NmU2YzAxNjAzNTQwNWFmZWU4YzM1ZjUxMDIifSx7ImhvdGVsX2lkIjozNzM3NiwicGxhdGZvcm0iOiJ0ZWxlZ3JhbSIsInB1YmxpY2F0aW9uX2lkIjoxNTk2LCJ2ZXJzaW9uX2lkIjo3MTI0LCJwb3N0X3VybCI6Imh0dHBzOi8vdC5tZS9hbnlfdG9vdXIvNzI0IiwiY2hhbm5lbF9pZCI6Ii0xMDAyMjYyNjY2NjE2IiwibWVzc2FnZV9pZCI6IjcyNCIsInRleHRfc2hhMjU2IjoiNTgwMzA1ZmViNDNjNGVkNmY0NTM1Yzg2NmEzZGM4MDNkOTdjZmIxMDk0MDU0ZWRmMTk5YWRlODlhOThjZTJkZCIsInBob3RvX3NoYTI1NiI6ImMzZDhjODZkY2VhN2UyMDQ5OTM2NzVmMWRkM2NjN2Q4MDU5MzQ1YTUwYjc1ZTZhZjg0MzljMTUyOWY0ZDhlMGQiLCJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiIzZGI1NGNhMDBmMDhjNGY3YmNmNmMzZDUwOGE5ZTE5MTIyYjZmOTY0MjA1MTYzNTliNmQ2OWFkYzZlYzJhMTM4In0seyJob3RlbF9pZCI6MTE0OCwicGxhdGZvcm0iOiJ0ZWxlZ3JhbSIsInB1YmxpY2F0aW9uX2lkIjoxNTk3LCJ2ZXJzaW9uX2lkIjo3MTI1LCJwb3N0X3VybCI6Imh0dHBzOi8vdC5tZS9hbnlfdG9vdXIvNzI1IiwiY2hhbm5lbF9pZCI6Ii0xMDAyMjYyNjY2NjE2IiwibWVzc2FnZV9pZCI6IjcyNSIsInRleHRfc2hhMjU2IjoiMzNhNGFlY2VkNzVhMTRiZGQ4ZmM5M2U2ZGU5MGE1MzFkNjQxZmMzMzM4NTM3YmM2NjFiODg3ODU5M2VhYTFmNCIsInBob3RvX3NoYTI1NiI6IjNkODM5N2M5NmI1Yzk0NmM1N2IyMzNkNGViMzUwMmVhMmFhOWZiYThkNmE4ZTI4Mjg1YTZjODFlNWFlMTY2MjUiLCJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiJkMDE2MzBjNTJlOWJjN2RkNDlhY2M4NDA4MTEzNjczYTM2NDgzZGE4Zjk1NDU3ZGNjMjYyY2NiMmE0NjRkZTg2In0seyJob3RlbF9pZCI6NzUxMDAsInBsYXRmb3JtIjoidGVsZWdyYW0iLCJwdWJsaWNhdGlvbl9pZCI6MTU5OCwidmVyc2lvbl9pZCI6NzEyNiwicG9zdF91cmwiOiJodHRwczovL3QubWUvYW55X3Rvb3VyLzcyNiIsImNoYW5uZWxfaWQiOiItMTAwMjI2MjY2NjYxNiIsIm1lc3NhZ2VfaWQiOiI3MjYiLCJ0ZXh0X3NoYTI1NiI6IjA4NjQxYTM5YzczNGRjODVkYTYyYTkwYTY3ODc3NDMwOWQwN2VjMTBiMWViYmMzYzcyZTgxZWY4MGYzYzZjZjAiLCJwaG90b19zaGEyNTYiOiJiNWQ1ZWMxOTNiZTZhNmRkZDc1NjFmYzE4YjUwMTNlNjIxODlhOTVkYTZlNzcxOGM5MmVhMThiZjZiNzY2YzgyIiwiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiNjExYzYxNmFmZjg0ZjYyMmM1NzlmNzQzNTBmZmMyM2I2YzE5ZGI3YWRhZDVhMmYzMjAwZDA3YTM4YjhjOGM0NiJ9LHsiaG90ZWxfaWQiOjgwNDc4LCJwbGF0Zm9ybSI6InRlbGVncmFtIiwicHVibGljYXRpb25faWQiOjE1OTksInZlcnNpb25faWQiOjcxMjcsInBvc3RfdXJsIjoiaHR0cHM6Ly90Lm1lL2FueV90b291ci83MjciLCJjaGFubmVsX2lkIjoiLTEwMDIyNjI2NjY2MTYiLCJtZXNzYWdlX2lkIjoiNzI3IiwidGV4dF9zaGEyNTYiOiI5ZWIwMGQ4ZDcxNDE4MWNjMjdhNjFhMzAyMDE3MDA2ZWU5MWM4N2QwZTNkNmM5NTQxMTM1ODc4ZjI2ZmQwMTZiIiwicGhvdG9fc2hhMjU2IjoiN2JhMWUyMmNlOGFkMjFlNDg2MmM1ODNkODQ0YWY2OWU2NTgyZjdiY2JjOTI0YzA3MWQxMmEzNWM5MzhlZTU2YSIsImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6IjJiNjVhZDY0MmQxNDBhODhlMTIyYmZiNWE2YzBkYWU5Zjk3M2U2ZDBmYjYzZTAzZTBhNGI0YWE3NzBlY2UyM2QifSx7ImhvdGVsX2lkIjoyNzY0NiwicGxhdGZvcm0iOiJ0ZWxlZ3JhbSIsInB1YmxpY2F0aW9uX2lkIjoxNjAwLCJ2ZXJzaW9uX2lkIjo3MTI4LCJwb3N0X3VybCI6Imh0dHBzOi8vdC5tZS9hbnlfdG9vdXIvNzI4IiwiY2hhbm5lbF9pZCI6Ii0xMDAyMjYyNjY2NjE2IiwibWVzc2FnZV9pZCI6IjcyOCIsInRleHRfc2hhMjU2IjoiNzkwZmRiNDhjNzIzNTZlZDlhNTc4NmM3MTc2MDBhZDMwNDgxYTM3YjJlNDZiMjdjYjVkODQ4NTg0YzZhMjQ4YyIsInBob3RvX3NoYTI1NiI6IjU5NDdhMWZhYTAxNDAzZDUyYTEzMDg3MjEwNmQ1YTU4OTZhZjBkNTQzYzIxYzNkN2FmNzVjODU5ZTNkODllZDciLCJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiI4MGUwOGJmNWZhOGZiYjEzNjA1M2I5OWZiYzY5YjQ2OGM2YWQzMjFjODMyNzYwNTM1NDU4OTI2MTc2NWNjMzU3In0seyJob3RlbF9pZCI6MTE1MCwicGxhdGZvcm0iOiJ0ZWxlZ3JhbSIsInB1YmxpY2F0aW9uX2lkIjoxNjAxLCJ2ZXJzaW9uX2lkIjo3MTI5LCJwb3N0X3VybCI6Imh0dHBzOi8vdC5tZS9hbnlfdG9vdXIvNzI5IiwiY2hhbm5lbF9pZCI6Ii0xMDAyMjYyNjY2NjE2IiwibWVzc2FnZV9pZCI6IjcyOSIsInRleHRfc2hhMjU2IjoiMjk1NDI3NTQzNDhiMmM5MTQyODZhMGFmNTc2NTc1Mjg0N2I5ZTU0ZjY2Nzc0MWRjODEzZjIyMzg3MjM2N2RlMSIsInBob3RvX3NoYTI1NiI6IjU2NWE2NGI3ZmMxNTI0MzU1OWU2ZTExYzcyOGMzNTI4OWI4NWVlOTJiNWI2YjA5MzI4NGJhYjM0NDVmNGEzOTciLCJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiIxOTY0MTE5YjJkOTAxNDNkMmZkOWJhMGJjMzE4MGM2OTc1OWIyNzU4ZmUwYjU1NTZmMmE2NjE0MjdiNWM2ZGViIn0seyJob3RlbF9pZCI6NjUzNDEsInBsYXRmb3JtIjoibWF4IiwicHVibGljYXRpb25faWQiOjE2MDIsInZlcnNpb25faWQiOjcxMzAsInBvc3RfdXJsIjoiaHR0cHM6Ly9tYXgucnUvY2hhbm5lbF9hbnl0b3VyL0FhSHB0bHY3VTdVIiwiY2hhbm5lbF9pZCI6Ii04MDQ5MjI0NDIwOTY4MyIsIm1lc3NhZ2VfaWQiOiJtaWQuZmZmZmI2Y2FmMDYwNmZlZDAxYTFlOWI2NWJmYjUzYjUiLCJ0ZXh0X3NoYTI1NiI6IjZlZTE1MzMxZjQ3NmRlMWU5ZWZlNDdmZGY5YTc1ZmUzZjJiYmMwZmUwOTlhMjEyY2NkNzViMGRmMDExNmMwZTYiLCJwaG90b19zaGEyNTYiOiI0YjYwMzk0YjdjZGVkMWNjNWY5NDJkZmE0NTM2ZjQ0MzQ4NTQ3MTU3MDQ2MDRkMmNjYWQ4OWViYjU4ZmJiMGFhIiwiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiZjYxZDAxODlkNDI2NTY5ZWRhOTBhMmJmYjNmNGE4ODQ4YWI2N2IxZDUwZTVhMTYxZDRmMjllYTQxY2ZiNWJiMiJ9LHsiaG90ZWxfaWQiOjQ5MDg0LCJwbGF0Zm9ybSI6Im1heCIsInB1YmxpY2F0aW9uX2lkIjoxNjAzLCJ2ZXJzaW9uX2lkIjo3MTMxLCJwb3N0X3VybCI6Imh0dHBzOi8vbWF4LnJ1L2NoYW5uZWxfYW55dG91ci9BYUhwdG1WM0tEUSIsImNoYW5uZWxfaWQiOiItODA0OTIyNDQyMDk2ODMiLCJtZXNzYWdlX2lkIjoibWlkLmZmZmZiNmNhZjA2MDZmZWQwMWExZTliNjY1NzcyODM0IiwidGV4dF9zaGEyNTYiOiIzYmY1MjFhMWJjMzYyNWEyZTM1OTc5NGMyNTJmNzk1M2Q0MTBjZWUyMTc5Njk4YjkwM2MyYWQ0MGI1YzRlNDNlIiwicGhvdG9fc2hhMjU2IjoiOWVmZDczN2MxNTZiMzc0OWFlOGZmZDVjYWE5YThmYjc0YjU3Yzg3OWU3OTFlNWY4YTE2NGFjYWQ2ZTZkM2QzOSIsImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6ImExOTJhMTk3NWQ1N2RkNTRiYmY2NjZlYzBhZjdjZTM5MjZlY2JmZmE2NzdiYjYwNmQwZGFmYWFmMGQ1ZDQ1ZmMifSx7ImhvdGVsX2lkIjo3NTUzOCwicGxhdGZvcm0iOiJtYXgiLCJwdWJsaWNhdGlvbl9pZCI6MTYwNCwidmVyc2lvbl9pZCI6NzEzMiwicG9zdF91cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FueXRvdXIvQWFIcHRtN2lDZ1EiLCJjaGFubmVsX2lkIjoiLTgwNDkyMjQ0MjA5NjgzIiwibWVzc2FnZV9pZCI6Im1pZC5mZmZmYjZjYWYwNjA2ZmVkMDFhMWU5YjY2ZWUyMGEwNCIsInRleHRfc2hhMjU2IjoiYzcxNDViOTEyZDFlNzc2NTFhZGQwNmNmNmVmMGE5YzVjNGQwNjdmMDJiOTVhZTkwZTU0Y2E4ODlmM2M3YzkxMiIsInBob3RvX3NoYTI1NiI6IjcxMmNlMjU0YTE3NDhiYWE1MTM0YTc3Nzk1OWYyZWVlZTg4OGRlNDlkMjljYzgyZWYwN2QwZjZhOGMzNzY0YjQiLCJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiJlYmQ3ODgyYjg4YTk1YzlmMGMwNzU0ZDllNjAzNjUxZTM1YWZiZWUyMjZhYzQzYTQ1ZGI4MDA0YzM3NDIxZWQxIn0seyJob3RlbF9pZCI6ODI2NDksInBsYXRmb3JtIjoibWF4IiwicHVibGljYXRpb25faWQiOjE2MDUsInZlcnNpb25faWQiOjcxMzMsInBvc3RfdXJsIjoiaHR0cHM6Ly9tYXgucnUvY2hhbm5lbF9hbnl0b3VyL0FhSHB0bnBzUTcwIiwiY2hhbm5lbF9pZCI6Ii04MDQ5MjI0NDIwOTY4MyIsIm1lc3NhZ2VfaWQiOiJtaWQuZmZmZmI2Y2FmMDYwNmZlZDAxYTFlOWI2N2E2YzQzYmQiLCJ0ZXh0X3NoYTI1NiI6ImM3N2Q1YTNjMWY0YTcyNzVlZWFhNWVjZWRiZmIwY2NjZjIxZmQzYWRmNDQ2MzVlZjAwMTI5YTQ5YWIwOGEwOGEiLCJwaG90b19zaGEyNTYiOiJiZjQxOTE5NjY5MzE4NGUxZDExMjU2NmU2ZWU1YjIxYTBlYjk4NzE1ZGM1ZjFmNDY4MjdjMDE5NTg4ZGQ2YzFhIiwiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiNzBlZDQ5ODY5ZGVkOTdkMjhmYjVkYjE5Mjc4MTY4OGQ3YWVmYTcxN2I5MWU2ZWIyYmY1ZGI2ODY0MTFlM2U0MSJ9LHsiaG90ZWxfaWQiOjM3Mzc2LCJwbGF0Zm9ybSI6Im1heCIsInB1YmxpY2F0aW9uX2lkIjoxNjA2LCJ2ZXJzaW9uX2lkIjo3MTM0LCJwb3N0X3VybCI6Imh0dHBzOi8vbWF4LnJ1L2NoYW5uZWxfYW55dG91ci9BYUhwdG9ZSENWYyIsImNoYW5uZWxfaWQiOiItODA0OTIyNDQyMDk2ODMiLCJtZXNzYWdlX2lkIjoibWlkLmZmZmZiNmNhZjA2MDZmZWQwMWExZTliNjg2MDcwOTU3IiwidGV4dF9zaGEyNTYiOiI1ODAzMDVmZWI0M2M0ZWQ2ZjQ1MzVjODY2YTNkYzgwM2Q5N2NmYjEwOTQwNTRlZGYxOTlhZGU4OWE5OGNlMmRkIiwicGhvdG9fc2hhMjU2IjoiYzNkOGM4NmRjZWE3ZTIwNDk5MzY3NWYxZGQzY2M3ZDgwNTkzNDVhNTBiNzVlNmFmODQzOWMxNTI5ZjRkOGUwZCIsImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6IjYwM2Y4YmE1Njk3MzJlZmNmNTE1NTZjZTcxZTgyYzQ0MDE3Y2U2MWYzYjM5NTEwYmI1N2EwMjI2MTA0MzY4OGYifSx7ImhvdGVsX2lkIjoxMTQ4LCJwbGF0Zm9ybSI6Im1heCIsInB1YmxpY2F0aW9uX2lkIjoxNjA3LCJ2ZXJzaW9uX2lkIjo3MTM1LCJwb3N0X3VybCI6Imh0dHBzOi8vbWF4LnJ1L2NoYW5uZWxfYW55dG91ci9BYUhwdG8tS2VvbyIsImNoYW5uZWxfaWQiOiItODA0OTIyNDQyMDk2ODMiLCJtZXNzYWdlX2lkIjoibWlkLmZmZmZiNmNhZjA2MDZmZWQwMWExZTliNjhmOGE3YThhIiwidGV4dF9zaGEyNTYiOiIzM2E0YWVjZWQ3NWExNGJkZDhmYzkzZTZkZTkwYTUzMWQ2NDFmYzMzMzg1MzdiYzY2MWI4ODc4NTkzZWFhMWY0IiwicGhvdG9fc2hhMjU2IjoiM2Q4Mzk3Yzk2YjVjOTQ2YzU3YjIzM2Q0ZWIzNTAyZWEyYWE5ZmJhOGQ2YThlMjgyODVhNmM4MWU1YWUxNjYyNSIsImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6IjNhZjMxYjM2OTBmZDVmNDY2Mzg5MGYyNDY4NGQ3ZDVkNDFkMmUyYTk4YjBlNzIyOGIyNGNhNzNjMzQ3NmJmNzcifSx7ImhvdGVsX2lkIjo3NTEwMCwicGxhdGZvcm0iOiJtYXgiLCJwdWJsaWNhdGlvbl9pZCI6MTYwOCwidmVyc2lvbl9pZCI6NzEzNiwicG9zdF91cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FueXRvdXIvQWFIcHRwa1pWbjgiLCJjaGFubmVsX2lkIjoiLTgwNDkyMjQ0MjA5NjgzIiwibWVzc2FnZV9pZCI6Im1pZC5mZmZmYjZjYWYwNjA2ZmVkMDFhMWU5YjY5OTE5NTY3ZiIsInRleHRfc2hhMjU2IjoiMDg2NDFhMzljNzM0ZGM4NWRhNjJhOTBhNjc4Nzc0MzA5ZDA3ZWMxMGIxZWJiYzNjNzJlODFlZjgwZjNjNmNmMCIsInBob3RvX3NoYTI1NiI6ImI1ZDVlYzE5M2JlNmE2ZGRkNzU2MWZjMThiNTAxM2U2MjE4OWE5NWRhNmU3NzE4YzkyZWExOGJmNmI3NjZjODIiLCJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiI1YmU3YjgxOGJiMDcwNDcwN2EzNTkxMWZiMzY3OTIyYWRhOTc1MWFkYTU3M2NlZDgwNzExODFkYWNmMzZmYWQ3In0seyJob3RlbF9pZCI6ODA0NzgsInBsYXRmb3JtIjoibWF4IiwicHVibGljYXRpb25faWQiOjE2MDksInZlcnNpb25faWQiOjcxMzcsInBvc3RfdXJsIjoiaHR0cHM6Ly9tYXgucnUvY2hhbm5lbF9hbnl0b3VyL0FhSHB0cUtWVGdrIiwiY2hhbm5lbF9pZCI6Ii04MDQ5MjI0NDIwOTY4MyIsIm1lc3NhZ2VfaWQiOiJtaWQuZmZmZmI2Y2FmMDYwNmZlZDAxYTFlOWI2YTI5NTRlMDkiLCJ0ZXh0X3NoYTI1NiI6IjllYjAwZDhkNzE0MTgxY2MyN2E2MWEzMDIwMTcwMDZlZTkxYzg3ZDBlM2Q2Yzk1NDExMzU4NzhmMjZmZDAxNmIiLCJwaG90b19zaGEyNTYiOiI3YmExZTIyY2U4YWQyMWU0ODYyYzU4M2Q4NDRhZjY5ZTY1ODJmN2JjYmM5MjRjMDcxZDEyYTM1YzkzOGVlNTZhIiwiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiNjBhMDYxNWJiM2JlNjhjNDY0OGMxMjJjYThkZjQwZWRkZjE2ZDliM2QwZTI0YjQ5OWY2YTg2MDNhMDAxZjlmZCJ9LHsiaG90ZWxfaWQiOjI3NjQ2LCJwbGF0Zm9ybSI6Im1heCIsInB1YmxpY2F0aW9uX2lkIjoxNjEwLCJ2ZXJzaW9uX2lkIjo3MTM4LCJwb3N0X3VybCI6Imh0dHBzOi8vbWF4LnJ1L2NoYW5uZWxfYW55dG91ci9BYUhwdHF3NmFPZyIsImNoYW5uZWxfaWQiOiItODA0OTIyNDQyMDk2ODMiLCJtZXNzYWdlX2lkIjoibWlkLmZmZmZiNmNhZjA2MDZmZWQwMWExZTliNmFjM2E2OGU4IiwidGV4dF9zaGEyNTYiOiI3OTBmZGI0OGM3MjM1NmVkOWE1Nzg2YzcxNzYwMGFkMzA0ODFhMzdiMmU0NmIyN2NiNWQ4NDg1ODRjNmEyNDhjIiwicGhvdG9fc2hhMjU2IjoiNTk0N2ExZmFhMDE0MDNkNTJhMTMwODcyMTA2ZDVhNTg5NmFmMGQ1NDNjMjFjM2Q3YWY3NWM4NTllM2Q4OWVkNyIsImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6IjQyODdlZWI3NzMwMzI0ZmYxMDM4ZTMwMWU3OTBlYjQxMTQ1YmM4MTk0NGI0YTQ5OGI3ZDcwMThkNTc2MWYzZjMifSx7ImhvdGVsX2lkIjoxMTUwLCJwbGF0Zm9ybSI6Im1heCIsInB1YmxpY2F0aW9uX2lkIjoxNjExLCJ2ZXJzaW9uX2lkIjo3MTM5LCJwb3N0X3VybCI6Imh0dHBzOi8vbWF4LnJ1L2NoYW5uZWxfYW55dG91ci9BYUhwdHJXbUdYRSIsImNoYW5uZWxfaWQiOiItODA0OTIyNDQyMDk2ODMiLCJtZXNzYWdlX2lkIjoibWlkLmZmZmZiNmNhZjA2MDZmZWQwMWExZTliNmI1YTYxOTcxIiwidGV4dF9zaGEyNTYiOiIyOTU0Mjc1NDM0OGIyYzkxNDI4NmEwYWY1NzY1NzUyODQ3YjllNTRmNjY3NzQxZGM4MTNmMjIzODcyMzY3ZGUxIiwicGhvdG9fc2hhMjU2IjoiNTY1YTY0YjdmYzE1MjQzNTU5ZTZlMTFjNzI4YzM1Mjg5Yjg1ZWU5MmI1YjZiMDkzMjg0YmFiMzQ0NWY0YTM5NyIsImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6IjJhNTgxZDIwMDAyMjdiNjA4NGFhNTg4ODU1NGQ0NDUwY2E0NjRmYTBhMTZjZWVhMjhjY2Q5MjFmNjJjZDJlMmQifV0=',true),true,64,JSON_THROW_ON_ERROR);
    insist(count($retained)===2*count($inputs),'retained_pair_count');
    $readerRegistry=new ChannelPostRegistryService($registry);$out['current_registry_receipts']=[];
    foreach($retained as $expected){
      $p=$readerRegistry->publication((int)$expected['publication_id']);
      insist(is_array($p)&&($p['state']??'')==='sent'&&(int)$p['publication_id']===$expected['publication_id']&&(int)$p['version_id']===$expected['version_id'],'current_original_version_changed');
      insist(($p['platform']??'')===$expected['platform']&&(string)$p['channel_id']===$expected['channel_id']&&(string)$p['external_message_id']===$expected['message_id'],'current_original_identity_changed');
      $s=$p['snapshot'];insist(hash('sha256',(string)($s['text']??''))===$expected['text_sha256']&&($s['post_url']??'')===$expected['post_url'],'current_original_content_changed');
      $media=$s['attachments']??[];insist(count($media)===1&&($media[0]['type']??'')==='image','current_original_photo_missing');
      insist(hash('sha256',ChannelPostButtonTransport::buttonUrl($p))===$expected['button_url_sha256'],'current_original_button_changed');
      insist(($p['channel']['button_text']??'')===$expected['button_text'],'current_original_button_label_changed');
      $receipt=$p['receipt']??[];insist(!empty($receipt['button_verified'])&&(!empty($receipt['content_preserved'])||!empty($receipt['verified'])),'current_button_receipt_missing');
      $out['current_registry_receipts'][]=['hotel_id'=>$expected['hotel_id'],'platform'=>$expected['platform'],'publication_id'=>(int)$p['publication_id'],'version_id'=>(int)$p['version_id'],'text_sha256'=>$expected['text_sha256'],'post_url'=>$expected['post_url'],'button_url'=>ChannelPostButtonTransport::buttonUrl($p),'button_text'=>$expected['button_text'],'image_count'=>1,'original_photo_sha256'=>$expected['photo_sha256'],'current_registry_receipt_verified'=>true,'provider_read_performed'=>false];
    }
    $registry->rollBack();$catalog->rollBack();
    $out['scope_hotels']=10;$out['feed_rows']=count($built['rows']);$out['deferred_count']=count($built['deferred']);$out['installed_sha']=EXPECTED_INSTALLED_SHA;$out['ok']=true;

} catch(Throwable $e){
    if(isset($catalog)&&$catalog->inTransaction())$catalog->rollBack();
    if(isset($registry)&&$registry->inTransaction())$registry->rollBack();
    $out['error']=preg_match('/\A[a-z][a-z0-9_]{1,100}\z/D',$e->getMessage())?$e->getMessage():'bounded_catalog_read_failed';
}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
exit($out['ok']?0:2);

