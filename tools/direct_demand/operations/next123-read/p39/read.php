<?php
declare(strict_types=1);
const EXPECTED_INSTALLED_SHA='cc017ee5bb669f4c7c13efc91d5c5812a50e55f2';
const FEED_EXPORT_INPUT_B64='W3siYWNjZXB0ZWRfZmlyc3RfbGluZSI6IuKciO+4jyDQotGD0YDRiyDQsiBEdXNpdCBTdWl0ZXMgUmF0Y2hhZGFtcmkgQmFuZ2tvayIsImhvdGVsX2lkIjo0MzQ5NCwiaW1hZ2Vfc2hhMjU2IjoiODZiZmYxZjE5NGFmMWI1ZWFkNjNhZjRmMzBiOTA4ZTM4MzQ3MTM3YzBkZjkxNTZhN2YwMDQzOWQyZDU1MzRiZCIsImltYWdlX3VybCI6Imh0dHBzOi8vcmF3LmdpdGh1YnVzZXJjb250ZW50LmNvbS9weWF0a29mZi9tYXgtc2VhcmNoLWJvdC84N2Q3NTcyNjRlMTNlNDU1YjhkOTY0Nzk4OTVkYWU1ODNlYjVjMjMxLy5naXRodWIvdHJhZmZpYy1wdWJsaXNoL2NvbW1lcmNpYWwtd2F2ZTUtNjUtMjAyNjExMTcvNDM0OTQucG5nIiwibmFtZSI6IkR1c2l0IFN1aXRlcyBSYXRjaGFkYW1yaSBCYW5na29rIn0seyJhY2NlcHRlZF9maXJzdF9saW5lIjoi4pyI77iPINCi0YPRgNGLINCyIEF2YW5pKyBSaXZlcnNpZGUgQmFuZ2tvayBIb3RlbCIsImhvdGVsX2lkIjo1MjA5MCwiaW1hZ2Vfc2hhMjU2IjoiYzAwZDM5ZmQyYjhkNDY5ZTA3YTRmZGY1N2NhMmYxOTY2NTM4NDNjZTkzOTVlMTNlNmI5ZjkzOGIzYWJhOTg0NiIsImltYWdlX3VybCI6Imh0dHBzOi8vcmF3LmdpdGh1YnVzZXJjb250ZW50LmNvbS9weWF0a29mZi9tYXgtc2VhcmNoLWJvdC84N2Q3NTcyNjRlMTNlNDU1YjhkOTY0Nzk4OTVkYWU1ODNlYjVjMjMxLy5naXRodWIvdHJhZmZpYy1wdWJsaXNoL2NvbW1lcmNpYWwtd2F2ZTUtNjUtMjAyNjExMTcvNTIwOTAucG5nIiwibmFtZSI6IkF2YW5pKyBSaXZlcnNpZGUgQmFuZ2tvayBIb3RlbCJ9LHsiYWNjZXB0ZWRfZmlyc3RfbGluZSI6IuKciO+4jyDQotGD0YDRiyDQsiBGdXNpb24gU3VpdGVzIFNhaWdvbiIsImhvdGVsX2lkIjo2NjY2NiwiaW1hZ2Vfc2hhMjU2IjoiODZjODU3YjM0NjAzNjk3ZjhmNTJkZjljMjM5ZjRiNTIxM2RkZjA5MzhjZmExMzgyZjUwNjQ4M2Q3OGYzMDc1MSIsImltYWdlX3VybCI6Imh0dHBzOi8vcmF3LmdpdGh1YnVzZXJjb250ZW50LmNvbS9weWF0a29mZi9tYXgtc2VhcmNoLWJvdC84N2Q3NTcyNjRlMTNlNDU1YjhkOTY0Nzk4OTVkYWU1ODNlYjVjMjMxLy5naXRodWIvdHJhZmZpYy1wdWJsaXNoL2NvbW1lcmNpYWwtd2F2ZTUtNjUtMjAyNjExMTcvNjY2NjYucG5nIiwibmFtZSI6IkZ1c2lvbiBTdWl0ZXMgU2FpZ29uIn0seyJhY2NlcHRlZF9maXJzdF9saW5lIjoi4pyI77iPINCi0YPRgNGLINCyIFJvc2V3b29kIEJhbmdrb2siLCJob3RlbF9pZCI6Njg5NzEsImltYWdlX3NoYTI1NiI6ImMzODliOWZjMWE5ZWM4NjA2MTZhZmJhMDExODRlZTFkYzJhYjA3MWQ2ZmM4NDg2YTUwNjk1NDZlMmQyODA5YmMiLCJpbWFnZV91cmwiOiJodHRwczovL3Jhdy5naXRodWJ1c2VyY29udGVudC5jb20vcHlhdGtvZmYvbWF4LXNlYXJjaC1ib3QvODdkNzU3MjY0ZTEzZTQ1NWI4ZDk2NDc5ODk1ZGFlNTgzZWI1YzIzMS8uZ2l0aHViL3RyYWZmaWMtcHVibGlzaC9jb21tZXJjaWFsLXdhdmU1LTY1LTIwMjYxMTE3LzY4OTcxLnBuZyIsIm5hbWUiOiJSb3Nld29vZCBCYW5na29rIn0seyJhY2NlcHRlZF9maXJzdF9saW5lIjoi4pyI77iPINCi0YPRgNGLINCyIEpXIE1hcnJpb3R0IEhvdGVsIEhhbm9pIiwiaG90ZWxfaWQiOjc0MTE0LCJpbWFnZV9zaGEyNTYiOiI4MzYxNGY4OTU3ODg3ZGNkOTcyMWY1MWY3ZDYwNzQyNzUyOTAyN2Y5ODNlY2M5YTQzNWVkNDBjOGM3MDRkMWY1IiwiaW1hZ2VfdXJsIjoiaHR0cHM6Ly9yYXcuZ2l0aHVidXNlcmNvbnRlbnQuY29tL3B5YXRrb2ZmL21heC1zZWFyY2gtYm90Lzg3ZDc1NzI2NGUxM2U0NTViOGQ5NjQ3OTg5NWRhZTU4M2ViNWMyMzEvLmdpdGh1Yi90cmFmZmljLXB1Ymxpc2gvY29tbWVyY2lhbC13YXZlNS02NS0yMDI2MTExNy83NDExNC5wbmciLCJuYW1lIjoiSlcgTWFycmlvdHQgSG90ZWwgSGFub2kifSx7ImFjY2VwdGVkX2ZpcnN0X2xpbmUiOiLinIjvuI8g0KLRg9GA0Ysg0LIgQ2FwZWxsYSBCYW5na29rIiwiaG90ZWxfaWQiOjc5MzIzLCJpbWFnZV9zaGEyNTYiOiIwN2IwMmY4MjBlZDk0YTJmYjI4MDAwMjQ3NGYwOGQwMDljNmU3MzNjM2QzMDc5Yzc0ODBiNDQ2NDFiOTYzMTZmIiwiaW1hZ2VfdXJsIjoiaHR0cHM6Ly9yYXcuZ2l0aHVidXNlcmNvbnRlbnQuY29tL3B5YXRrb2ZmL21heC1zZWFyY2gtYm90Lzg3ZDc1NzI2NGUxM2U0NTViOGQ5NjQ3OTg5NWRhZTU4M2ViNWMyMzEvLmdpdGh1Yi90cmFmZmljLXB1Ymxpc2gvY29tbWVyY2lhbC13YXZlNS02NS0yMDI2MTExNy83OTMyMy5wbmciLCJuYW1lIjoiQ2FwZWxsYSBCYW5na29rIn0seyJhY2NlcHRlZF9maXJzdF9saW5lIjoi4pyI77iPINCi0YPRgNGLINCyIFBhcmsgSHlhdHQgQmFuZ2tvayIsImhvdGVsX2lkIjo3OTk1NCwiaW1hZ2Vfc2hhMjU2IjoiN2VkMGM2MmI4ZmQ4ODBjNGVjYjJlMzYyZGEwNWNkOTM3YTE1NzU5NDljZjQ2MDc3MjlkZGFhODQ1ZGMzZTFlYiIsImltYWdlX3VybCI6Imh0dHBzOi8vcmF3LmdpdGh1YnVzZXJjb250ZW50LmNvbS9weWF0a29mZi9tYXgtc2VhcmNoLWJvdC84N2Q3NTcyNjRlMTNlNDU1YjhkOTY0Nzk4OTVkYWU1ODNlYjVjMjMxLy5naXRodWIvdHJhZmZpYy1wdWJsaXNoL2NvbW1lcmNpYWwtd2F2ZTUtNjUtMjAyNjExMTcvNzk5NTQucG5nIiwibmFtZSI6IlBhcmsgSHlhdHQgQmFuZ2tvayJ9LHsiYWNjZXB0ZWRfZmlyc3RfbGluZSI6IuKciO+4jyDQotGD0YDRiyDQsiBBdmFuaSBTdWtodW12aXQgQmFuZ2tvayBIb3RlbCIsImhvdGVsX2lkIjo4MDIzMywiaW1hZ2Vfc2hhMjU2IjoiNDk5MjYwOTU5ZjVkMWY3OTZkNmZmZGQ0NzIyYzcxNzY0ZGYzNmZkMTg1YjNkYzlhMmRkOTY4MWQ5YjYwYmVmZSIsImltYWdlX3VybCI6Imh0dHBzOi8vcmF3LmdpdGh1YnVzZXJjb250ZW50LmNvbS9weWF0a29mZi9tYXgtc2VhcmNoLWJvdC84N2Q3NTcyNjRlMTNlNDU1YjhkOTY0Nzk4OTVkYWU1ODNlYjVjMjMxLy5naXRodWIvdHJhZmZpYy1wdWJsaXNoL2NvbW1lcmNpYWwtd2F2ZTUtNjUtMjAyNjExMTcvODAyMzMucG5nIiwibmFtZSI6IkF2YW5pIFN1a2h1bXZpdCBCYW5na29rIEhvdGVsIn0seyJhY2NlcHRlZF9maXJzdF9saW5lIjoi4pyI77iPINCi0YPRgNGLINCyIEtpbXB0b24gTWFhIExhaSBCYW5na29rIiwiaG90ZWxfaWQiOjExMDg0OCwiaW1hZ2Vfc2hhMjU2IjoiZmE3OWE2ODliNDMyODhiNThiNTg2OGUxOTA3M2UyNWY3ZDY0OTI5Zjg4ZTViZGQ4M2Q4YWM4ZmQ4MTYyZGFhYiIsImltYWdlX3VybCI6Imh0dHBzOi8vcmF3LmdpdGh1YnVzZXJjb250ZW50LmNvbS9weWF0a29mZi9tYXgtc2VhcmNoLWJvdC84N2Q3NTcyNjRlMTNlNDU1YjhkOTY0Nzk4OTVkYWU1ODNlYjVjMjMxLy5naXRodWIvdHJhZmZpYy1wdWJsaXNoL2NvbW1lcmNpYWwtd2F2ZTUtNjUtMjAyNjExMTcvMTEwODQ4LnBuZyIsIm5hbWUiOiJLaW1wdG9uIE1hYSBMYWkgQmFuZ2tvayJ9LHsiYWNjZXB0ZWRfZmlyc3RfbGluZSI6IuKciO+4jyDQotGD0YDRiyDQsiBGdXNpb24gT3JpZ2luYWwgU2FpZ29uIENlbnRyZSIsImhvdGVsX2lkIjoxMTE0ODksImltYWdlX3NoYTI1NiI6ImUyZTJiM2YwMmI0ZmMzNzJiMjY2N2E4ZTc1ZTY0ZGM4ZDU4ZjIwNDM3NGFmNmRmNWQ2YzI4ZWVhOWNiNmEwMGYiLCJpbWFnZV91cmwiOiJodHRwczovL3Jhdy5naXRodWJ1c2VyY29udGVudC5jb20vcHlhdGtvZmYvbWF4LXNlYXJjaC1ib3QvODdkNzU3MjY0ZTEzZTQ1NWI4ZDk2NDc5ODk1ZGFlNTgzZWI1YzIzMS8uZ2l0aHViL3RyYWZmaWMtcHVibGlzaC9jb21tZXJjaWFsLXdhdmU1LTY1LTIwMjYxMTE3LzExMTQ4OS5wbmciLCJuYW1lIjoiRnVzaW9uIE9yaWdpbmFsIFNhaWdvbiBDZW50cmUifV0=';
const FEED_EXPORT_INPUT_SHA256='83e5ad1366c67eef33048936fea764457e0faaa6821acc22a807846d50800e63';
const CANDIDATE_INPUT_B64='W10=';
const CANDIDATE_INPUT_SHA256='4f53cda18c2baa0c0354bb5f9a3ecbe5ed12ab4d8e11ba873c2f11161202b945';
ini_set('display_errors','0');ini_set('log_errors','0');
$out=['operation'=>'commercial-channel-hotel-feeds-20261118-next123-read39','mode'=>'read_bounded_public_channel_post_feed_inputs','ok'=>false,'db_writes'=>0,'provider_calls'=>0,'message_sends'=>0];
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
 insist(count($in)===10&&array_column($in,'hotel_id')===[43494,52090,66666,68971,74114,79323,79954,80233,110848,111489],'read17_exact_new1_scope');
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
        "services/ChannelPostAutomationService.php"=>"c6386e155d42bc83970edc95f35b3ebeb5140533",
        "services/ChannelPostButtonTransport.php"=>"7b6711f1de63000346d8e5789f2dbb7f725535b8",
        "services/ChannelPostEntryService.php"=>"1982ff7368763edf8b7238c3142e3cc28579e991",
        "services/ChannelPostMaxAlbumRecovery.php"=>"de266cd068587cc3b2ff83f308a966ea66887a04",
        "services/ChannelPostMediaService.php"=>"f794efd0f6f24df916e18909ff6281b0bc928433",
        "services/ChannelPostMemberDeliveryService.php"=>"20c1d7984898b2a5996a5f9e20e4d2d8c20b4778",
        "services/ChannelPostReconciliationService.php"=>"43133f576845c14446102dedfffb7f0de2d4ba1c",
        "services/ChannelPostReconciliationState.php"=>"d9583ceca80d1424a44ade3561c21282a23a52f0",
        "services/ChannelPostRegistryService.php"=>"94fb1988ac8caf90dc5103aa23c58e7aad6ea691",
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
    $retained=json_decode(base64_decode('W3siYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiYmNjZmEwZTI3NjI3NjA1MjMwMzVmNjVmZGViYTc0NTZlODljYjg5YzY4YWZkYzllMTg5MTg4ODNiNjllMWMxNyIsImNoYW5uZWxfaWQiOiItMTAwMjI2MjY2NjYxNiIsImhvdGVsX2lkIjo0MzQ5NCwibWVzc2FnZV9pZCI6IjEwMDIiLCJwaG90b19zaGEyNTYiOiI4NmJmZjFmMTk0YWYxYjVlYWQ2M2FmNGYzMGI5MDhlMzgzNDcxMzdjMGRmOTE1NmE3ZjAwNDM5ZDJkNTUzNGJkIiwicGxhdGZvcm0iOiJ0ZWxlZ3JhbSIsInBvc3RfdXJsIjoiaHR0cHM6Ly90Lm1lL2FueV90b291ci8xMDAyIiwicHVibGljYXRpb25faWQiOjIxNTcsInRleHRfc2hhMjU2IjoiM2M3NWYxNDUyY2E2YjBiOTNjYmU5NmIzMWM0YTNlY2QwNmE0ZjBlMmJjNTY4OThkYTJhNGE4NDIzMmFiMTE2YiIsInZlcnNpb25faWQiOjc2ODZ9LHsiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiNmI1MGZjZmUxOTc0NmMzOGNhZmNmZGMwYzZhNjJhMDMwNjM5NDk3Y2NiZjQ3N2ViMGU0MzllYTliYTZmM2FhNyIsImNoYW5uZWxfaWQiOiItODA0OTIyNDQyMDk2ODMiLCJob3RlbF9pZCI6NDM0OTQsIm1lc3NhZ2VfaWQiOiJtaWQuZmZmZmI2Y2FmMDYwNmZlZDAxYTFlYWJkZjdhYjE0ZTIiLCJwaG90b19zaGEyNTYiOiI4NmJmZjFmMTk0YWYxYjVlYWQ2M2FmNGYzMGI5MDhlMzgzNDcxMzdjMGRmOTE1NmE3ZjAwNDM5ZDJkNTUzNGJkIiwicGxhdGZvcm0iOiJtYXgiLCJwb3N0X3VybCI6Imh0dHBzOi8vbWF4LnJ1L2NoYW5uZWxfYW55dG91ci9BYUhxdmZlckZPSSIsInB1YmxpY2F0aW9uX2lkIjoyMTY3LCJ0ZXh0X3NoYTI1NiI6IjNjNzVmMTQ1MmNhNmIwYjkzY2JlOTZiMzFjNGEzZWNkMDZhNGYwZTJiYzU2ODk4ZGEyYTRhODQyMzJhYjExNmIiLCJ2ZXJzaW9uX2lkIjo3Njk2fSx7ImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6Ijg0ZmE5NTgwMDRjMjVlNGQ2ZThjM2UwNmFiNTNmODYxMmY1ZGU5YjI4MTJiMDFlODYxNmYxY2FkNWFjNDRiYjEiLCJjaGFubmVsX2lkIjoiLTEwMDIyNjI2NjY2MTYiLCJob3RlbF9pZCI6NTIwOTAsIm1lc3NhZ2VfaWQiOiIxMDAzIiwicGhvdG9fc2hhMjU2IjoiYzAwZDM5ZmQyYjhkNDY5ZTA3YTRmZGY1N2NhMmYxOTY2NTM4NDNjZTkzOTVlMTNlNmI5ZjkzOGIzYWJhOTg0NiIsInBsYXRmb3JtIjoidGVsZWdyYW0iLCJwb3N0X3VybCI6Imh0dHBzOi8vdC5tZS9hbnlfdG9vdXIvMTAwMyIsInB1YmxpY2F0aW9uX2lkIjoyMTU4LCJ0ZXh0X3NoYTI1NiI6IjQ5OTc0MDE1ZDUyYjQzODA3MWQ0OGNjYzRiMjJmZmJmNTI3ZjBhNmI4NjExZGNjYmE1YjU1MmQ1MTRmYWY5Y2YiLCJ2ZXJzaW9uX2lkIjo3Njg3fSx7ImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6IjIyNTlkOTNjOGVhZDUxMzNiZGY3ZGE1Y2MxNmU2ZDU1MTU1ODdlZTUzMjE5YzA1M2FjOGFlMDBlMjY0ZDA5OGYiLCJjaGFubmVsX2lkIjoiLTgwNDkyMjQ0MjA5NjgzIiwiaG90ZWxfaWQiOjUyMDkwLCJtZXNzYWdlX2lkIjoibWlkLmZmZmZiNmNhZjA2MDZmZWQwMWExZWFiZTAyYjk2MzU5IiwicGhvdG9fc2hhMjU2IjoiYzAwZDM5ZmQyYjhkNDY5ZTA3YTRmZGY1N2NhMmYxOTY2NTM4NDNjZTkzOTVlMTNlNmI5ZjkzOGIzYWJhOTg0NiIsInBsYXRmb3JtIjoibWF4IiwicG9zdF91cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FueXRvdXIvQWFIcXZnSzVZMWsiLCJwdWJsaWNhdGlvbl9pZCI6MjE2OCwidGV4dF9zaGEyNTYiOiI0OTk3NDAxNWQ1MmI0MzgwNzFkNDhjY2M0YjIyZmZiZjUyN2YwYTZiODYxMWRjY2JhNWI1NTJkNTE0ZmFmOWNmIiwidmVyc2lvbl9pZCI6NzY5N30seyJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiIyOGYzZDk3Mjg4Y2FlZDViMDY3YmIyOGIwYmJlYWQ2N2ZhZjRlYzIwZGUzYTI3ODAzNzU4NDdhN2ZkZTViNDgwIiwiY2hhbm5lbF9pZCI6Ii0xMDAyMjYyNjY2NjE2IiwiaG90ZWxfaWQiOjY2NjY2LCJtZXNzYWdlX2lkIjoiMTAwNCIsInBob3RvX3NoYTI1NiI6Ijg2Yzg1N2IzNDYwMzY5N2Y4ZjUyZGY5YzIzOWY0YjUyMTNkZGYwOTM4Y2ZhMTM4MmY1MDY0ODNkNzhmMzA3NTEiLCJwbGF0Zm9ybSI6InRlbGVncmFtIiwicG9zdF91cmwiOiJodHRwczovL3QubWUvYW55X3Rvb3VyLzEwMDQiLCJwdWJsaWNhdGlvbl9pZCI6MjE1OSwidGV4dF9zaGEyNTYiOiI2ODAwOGFiYmFlZmJjMWQwYmVkZmFkYjBiNDA1ODgyOTg3MGIzMWJmNTU5NTUwYzc1YTEzNDljMTRkMjU0ZjNkIiwidmVyc2lvbl9pZCI6NzY4OH0seyJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiI1YTk1OWVlNzM2MjdkNjJjZDcyZDE5YjBiM2FlOTZmNGI5ZWU3OTYwZjc1ZTgyYTgwZGNhZDNmOGM5ZTUzZjY1IiwiY2hhbm5lbF9pZCI6Ii04MDQ5MjI0NDIwOTY4MyIsImhvdGVsX2lkIjo2NjY2NiwibWVzc2FnZV9pZCI6Im1pZC5mZmZmYjZjYWYwNjA2ZmVkMDFhMWVhYmUwYzA4MTc1ZSIsInBob3RvX3NoYTI1NiI6Ijg2Yzg1N2IzNDYwMzY5N2Y4ZjUyZGY5YzIzOWY0YjUyMTNkZGYwOTM4Y2ZhMTM4MmY1MDY0ODNkNzhmMzA3NTEiLCJwbGF0Zm9ybSI6Im1heCIsInBvc3RfdXJsIjoiaHR0cHM6Ly9tYXgucnUvY2hhbm5lbF9hbnl0b3VyL0FhSHF2Z3dJRjE0IiwicHVibGljYXRpb25faWQiOjIxNjksInRleHRfc2hhMjU2IjoiNjgwMDhhYmJhZWZiYzFkMGJlZGZhZGIwYjQwNTg4Mjk4NzBiMzFiZjU1OTU1MGM3NWExMzQ5YzE0ZDI1NGYzZCIsInZlcnNpb25faWQiOjc2OTh9LHsiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiMDBmMDRkMGFlZGRlMGQ1YmE4Zjg4YTNlN2Y4YzE2YWVjZTljOWNlNTZlZTAzYzNmMjBjYzg5NTE5NWJhMjUzNyIsImNoYW5uZWxfaWQiOiItMTAwMjI2MjY2NjYxNiIsImhvdGVsX2lkIjo2ODk3MSwibWVzc2FnZV9pZCI6IjEwMDUiLCJwaG90b19zaGEyNTYiOiJjMzg5YjlmYzFhOWVjODYwNjE2YWZiYTAxMTg0ZWUxZGMyYWIwNzFkNmZjODQ4NmE1MDY5NTQ2ZTJkMjgwOWJjIiwicGxhdGZvcm0iOiJ0ZWxlZ3JhbSIsInBvc3RfdXJsIjoiaHR0cHM6Ly90Lm1lL2FueV90b291ci8xMDA1IiwicHVibGljYXRpb25faWQiOjIxNjAsInRleHRfc2hhMjU2IjoiMDNhMjIzYWQxZWZiOTMxNzQ4ZmIwOWM3OWIxMDVlMmNiYjYxZmUwMzFkZWU3MDNjZTJmYmM2MGQ5YmM4MGZiMyIsInZlcnNpb25faWQiOjc2ODl9LHsiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiMjNkOTVjNjkyYjU4MDY3MzdkYjYyOWY1OGViZmE0ZmI3ZTMwODgzODI2OGIyYmM4MzgwMWI0YWIxMmI1ODhlZSIsImNoYW5uZWxfaWQiOiItODA0OTIyNDQyMDk2ODMiLCJob3RlbF9pZCI6Njg5NzEsIm1lc3NhZ2VfaWQiOiJtaWQuZmZmZmI2Y2FmMDYwNmZlZDAxYTFlYWJlMTUzMjMyYjMiLCJwaG90b19zaGEyNTYiOiJjMzg5YjlmYzFhOWVjODYwNjE2YWZiYTAxMTg0ZWUxZGMyYWIwNzFkNmZjODQ4NmE1MDY5NTQ2ZTJkMjgwOWJjIiwicGxhdGZvcm0iOiJtYXgiLCJwb3N0X3VybCI6Imh0dHBzOi8vbWF4LnJ1L2NoYW5uZWxfYW55dG91ci9BYUhxdmhVeU1yTSIsInB1YmxpY2F0aW9uX2lkIjoyMTcwLCJ0ZXh0X3NoYTI1NiI6IjAzYTIyM2FkMWVmYjkzMTc0OGZiMDljNzliMTA1ZTJjYmI2MWZlMDMxZGVlNzAzY2UyZmJjNjBkOWJjODBmYjMiLCJ2ZXJzaW9uX2lkIjo3Njk5fSx7ImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6ImQwMjQ4Y2YxNzBhMjZiOGQyNjZhMDdmNzA3N2E5ZjFmMWVkYWZhNWI2ZDIwMjk0OWQyMTA4OTIxZDhmMTI1NjUiLCJjaGFubmVsX2lkIjoiLTEwMDIyNjI2NjY2MTYiLCJob3RlbF9pZCI6NzQxMTQsIm1lc3NhZ2VfaWQiOiIxMDA2IiwicGhvdG9fc2hhMjU2IjoiODM2MTRmODk1Nzg4N2RjZDk3MjFmNTFmN2Q2MDc0Mjc1MjkwMjdmOTgzZWNjOWE0MzVlZDQwYzhjNzA0ZDFmNSIsInBsYXRmb3JtIjoidGVsZWdyYW0iLCJwb3N0X3VybCI6Imh0dHBzOi8vdC5tZS9hbnlfdG9vdXIvMTAwNiIsInB1YmxpY2F0aW9uX2lkIjoyMTYxLCJ0ZXh0X3NoYTI1NiI6IjkzOTQ5YmQzNDY5OGIxMmE5MzE2YTAwZTU5YTc5MDIwZWIzYjhjMjZkNDAxNjQ4NzM5NDNhM2E1MGRmNmZiMmYiLCJ2ZXJzaW9uX2lkIjo3NjkwfSx7ImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6IjE4OWYyNDIzNDZlMmM0NTMzMGYxY2NhY2VmYzVkMzMzODFkNTAyNjQ3NjcwMGY4NDM2OGY4MWYyMWRkZmUyY2UiLCJjaGFubmVsX2lkIjoiLTgwNDkyMjQ0MjA5NjgzIiwiaG90ZWxfaWQiOjc0MTE0LCJtZXNzYWdlX2lkIjoibWlkLmZmZmZiNmNhZjA2MDZmZWQwMWExZWFiZTFlYWM0Nzg2IiwicGhvdG9fc2hhMjU2IjoiODM2MTRmODk1Nzg4N2RjZDk3MjFmNTFmN2Q2MDc0Mjc1MjkwMjdmOTgzZWNjOWE0MzVlZDQwYzhjNzA0ZDFmNSIsInBsYXRmb3JtIjoibWF4IiwicG9zdF91cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FueXRvdXIvQWFIcXZoNnNSNFkiLCJwdWJsaWNhdGlvbl9pZCI6MjE3MSwidGV4dF9zaGEyNTYiOiI5Mzk0OWJkMzQ2OThiMTJhOTMxNmEwMGU1OWE3OTAyMGViM2I4YzI2ZDQwMTY0ODczOTQzYTNhNTBkZjZmYjJmIiwidmVyc2lvbl9pZCI6NzcwMH0seyJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiJlMTFhMWMyZTg3NTVhYWI4YTZkZmFiOWQzYTE3YjY4M2Y2ZjJkNDliMzViZGRkZmVhYjIwMzM0ZDlhYjRhNGMzIiwiY2hhbm5lbF9pZCI6Ii0xMDAyMjYyNjY2NjE2IiwiaG90ZWxfaWQiOjc5MzIzLCJtZXNzYWdlX2lkIjoiMTAwNyIsInBob3RvX3NoYTI1NiI6IjA3YjAyZjgyMGVkOTRhMmZiMjgwMDAyNDc0ZjA4ZDAwOWM2ZTczM2MzZDMwNzljNzQ4MGI0NDY0MWI5NjMxNmYiLCJwbGF0Zm9ybSI6InRlbGVncmFtIiwicG9zdF91cmwiOiJodHRwczovL3QubWUvYW55X3Rvb3VyLzEwMDciLCJwdWJsaWNhdGlvbl9pZCI6MjE2MiwidGV4dF9zaGEyNTYiOiI0OGRhZDU5ZmY1ZjMxYjZjYzk3MDY2OTZlNmZjOGFiMjllZjQzMTQ4M2RmYjVhNDQxNTZmZTNlODQwMjU0MmU0IiwidmVyc2lvbl9pZCI6NzY5MX0seyJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiI0MWMyOGI0MmJmMjU4NmYzOGUzNzUwYTI3NWQzMGVlZTBjYmQ4MDBmNjY0ODA1ZjI5MTE2YmFmZDVlZmI2MTQzIiwiY2hhbm5lbF9pZCI6Ii04MDQ5MjI0NDIwOTY4MyIsImhvdGVsX2lkIjo3OTMyMywibWVzc2FnZV9pZCI6Im1pZC5mZmZmYjZjYWYwNjA2ZmVkMDFhMWVhYmUyOWYyN2UwOSIsInBob3RvX3NoYTI1NiI6IjA3YjAyZjgyMGVkOTRhMmZiMjgwMDAyNDc0ZjA4ZDAwOWM2ZTczM2MzZDMwNzljNzQ4MGI0NDY0MWI5NjMxNmYiLCJwbGF0Zm9ybSI6Im1heCIsInBvc3RfdXJsIjoiaHR0cHM6Ly9tYXgucnUvY2hhbm5lbF9hbnl0b3VyL0FhSHF2aW55ZmdrIiwicHVibGljYXRpb25faWQiOjIxNzIsInRleHRfc2hhMjU2IjoiNDhkYWQ1OWZmNWYzMWI2Y2M5NzA2Njk2ZTZmYzhhYjI5ZWY0MzE0ODNkZmI1YTQ0MTU2ZmUzZTg0MDI1NDJlNCIsInZlcnNpb25faWQiOjc3MDF9LHsiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiMThmM2ZmODUyZmY5N2Q0NmM0ZmJjODIzMTFhNDk5ZGMzNDM3NzUzMGYxYTEyNTdjODc3NDBjNGMyNzY2OGJjZCIsImNoYW5uZWxfaWQiOiItMTAwMjI2MjY2NjYxNiIsImhvdGVsX2lkIjo3OTk1NCwibWVzc2FnZV9pZCI6IjEwMDgiLCJwaG90b19zaGEyNTYiOiI3ZWQwYzYyYjhmZDg4MGM0ZWNiMmUzNjJkYTA1Y2Q5MzdhMTU3NTk0OWNmNDYwNzcyOWRkYWE4NDVkYzNlMWViIiwicGxhdGZvcm0iOiJ0ZWxlZ3JhbSIsInBvc3RfdXJsIjoiaHR0cHM6Ly90Lm1lL2FueV90b291ci8xMDA4IiwicHVibGljYXRpb25faWQiOjIxNjMsInRleHRfc2hhMjU2IjoiYjQ0YzkzNzVmYTk0ZjNhNjNiNDlhYzk1YTVlNjc1YzQxN2IxMDg4ZjMxYTExNzYzNWM3Yzg0YWQ0NjNlNmU5MCIsInZlcnNpb25faWQiOjc2OTJ9LHsiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiYjljZDcwZGYzYjI2NzA5NmUxYjg4NWRjMDM2ZDNlZTkzM2NmM2MyMmYxMGQxZjU2MDc0NGViZjlkNTk3NDA5NCIsImNoYW5uZWxfaWQiOiItODA0OTIyNDQyMDk2ODMiLCJob3RlbF9pZCI6Nzk5NTQsIm1lc3NhZ2VfaWQiOiJtaWQuZmZmZmI2Y2FmMDYwNmZlZDAxYTFlYWJlMzM2ZjczN2QiLCJwaG90b19zaGEyNTYiOiI3ZWQwYzYyYjhmZDg4MGM0ZWNiMmUzNjJkYTA1Y2Q5MzdhMTU3NTk0OWNmNDYwNzcyOWRkYWE4NDVkYzNlMWViIiwicGxhdGZvcm0iOiJtYXgiLCJwb3N0X3VybCI6Imh0dHBzOi8vbWF4LnJ1L2NoYW5uZWxfYW55dG91ci9BYUhxdmpOdmMzMCIsInB1YmxpY2F0aW9uX2lkIjoyMTczLCJ0ZXh0X3NoYTI1NiI6ImI0NGM5Mzc1ZmE5NGYzYTYzYjQ5YWM5NWE1ZTY3NWM0MTdiMTA4OGYzMWExMTc2MzVjN2M4NGFkNDYzZTZlOTAiLCJ2ZXJzaW9uX2lkIjo3NzAyfSx7ImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6IjcwNDYyZDhmYWI1NTYzZTI0ZDI0MTI2YjMzZGRhZDUwMmY0ZTQxYWI3ZWYyY2Q4OTQ3YzU4MTc0ZDlkMmIwZGMiLCJjaGFubmVsX2lkIjoiLTEwMDIyNjI2NjY2MTYiLCJob3RlbF9pZCI6ODAyMzMsIm1lc3NhZ2VfaWQiOiIxMDA5IiwicGhvdG9fc2hhMjU2IjoiNDk5MjYwOTU5ZjVkMWY3OTZkNmZmZGQ0NzIyYzcxNzY0ZGYzNmZkMTg1YjNkYzlhMmRkOTY4MWQ5YjYwYmVmZSIsInBsYXRmb3JtIjoidGVsZWdyYW0iLCJwb3N0X3VybCI6Imh0dHBzOi8vdC5tZS9hbnlfdG9vdXIvMTAwOSIsInB1YmxpY2F0aW9uX2lkIjoyMTY0LCJ0ZXh0X3NoYTI1NiI6IjMzNmJmNzMzNDE3YTQ0OWUwMzNiMzQ2YzRlYmEzMDYxZDRlZDViNGNkYjdiNDViNWVmZGM3ZDk5YmUyNGUyZWYiLCJ2ZXJzaW9uX2lkIjo3NjkzfSx7ImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6IjA5MmUzZjljMzkzYjczZDM2ZmJjNmM4NGU5MmNmNDE4NmU2ZGE3MjZiZmI0M2QyZjJjY2VmMjE4YWI0ZDVkYWIiLCJjaGFubmVsX2lkIjoiLTgwNDkyMjQ0MjA5NjgzIiwiaG90ZWxfaWQiOjgwMjMzLCJtZXNzYWdlX2lkIjoibWlkLmZmZmZiNmNhZjA2MDZmZWQwMWExZWFiZTNjY2M3YjNhIiwicGhvdG9fc2hhMjU2IjoiNDk5MjYwOTU5ZjVkMWY3OTZkNmZmZGQ0NzIyYzcxNzY0ZGYzNmZkMTg1YjNkYzlhMmRkOTY4MWQ5YjYwYmVmZSIsInBsYXRmb3JtIjoibWF4IiwicG9zdF91cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FueXRvdXIvQWFIcXZqek1lem8iLCJwdWJsaWNhdGlvbl9pZCI6MjE3NCwidGV4dF9zaGEyNTYiOiIzMzZiZjczMzQxN2E0NDllMDMzYjM0NmM0ZWJhMzA2MWQ0ZWQ1YjRjZGI3YjQ1YjVlZmRjN2Q5OWJlMjRlMmVmIiwidmVyc2lvbl9pZCI6NzcwM30seyJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiIxYzY5ZmRiY2MzMDUyMjNkODJkZTE0NjQwM2VjNjliZjNhYTFkZDQzMjgxZTg1MTFlYjZhNzcxMzY3NTU5ZjYzIiwiY2hhbm5lbF9pZCI6Ii0xMDAyMjYyNjY2NjE2IiwiaG90ZWxfaWQiOjExMDg0OCwibWVzc2FnZV9pZCI6IjEwMTAiLCJwaG90b19zaGEyNTYiOiJmYTc5YTY4OWI0MzI4OGI1OGI1ODY4ZTE5MDczZTI1ZjdkNjQ5MjlmODhlNWJkZDgzZDhhYzhmZDgxNjJkYWFiIiwicGxhdGZvcm0iOiJ0ZWxlZ3JhbSIsInBvc3RfdXJsIjoiaHR0cHM6Ly90Lm1lL2FueV90b291ci8xMDEwIiwicHVibGljYXRpb25faWQiOjIxNjUsInRleHRfc2hhMjU2IjoiOWFlMmE3OTViMjIwMGVkZTVjYzcwOGIzZTk4NzE2OGU1YTc5ZjQyZWI2ODVhZDE3ZDdkNzQ3Mzc3YmE0MjRiNSIsInZlcnNpb25faWQiOjc2OTR9LHsiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiOTA5YmQ1YzNjZThjZDhiZDg4NTM4Nzg4OTJlNjE2YjNmYzY1ODA2OGYyODhjZDMzMGFkZjU3MjcyNjU1ZGY0YSIsImNoYW5uZWxfaWQiOiItODA0OTIyNDQyMDk2ODMiLCJob3RlbF9pZCI6MTEwODQ4LCJtZXNzYWdlX2lkIjoibWlkLmZmZmZiNmNhZjA2MDZmZWQwMWExZWFiZTQ3NDcxNDc3IiwicGhvdG9fc2hhMjU2IjoiZmE3OWE2ODliNDMyODhiNThiNTg2OGUxOTA3M2UyNWY3ZDY0OTI5Zjg4ZTViZGQ4M2Q4YWM4ZmQ4MTYyZGFhYiIsInBsYXRmb3JtIjoibWF4IiwicG9zdF91cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FueXRvdXIvQWFIcXZrZEhGSGMiLCJwdWJsaWNhdGlvbl9pZCI6MjE3NSwidGV4dF9zaGEyNTYiOiI5YWUyYTc5NWIyMjAwZWRlNWNjNzA4YjNlOTg3MTY4ZTVhNzlmNDJlYjY4NWFkMTdkN2Q3NDczNzdiYTQyNGI1IiwidmVyc2lvbl9pZCI6NzcwNH0seyJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiIxYjdiNmE5NjBmNGMwMDJkNTdkNDgzNzkzOWQzMjFhNjY0ODEyZjE1MTEwYWEyMmE4NjM3ZTExODY3MjcxNTdjIiwiY2hhbm5lbF9pZCI6Ii0xMDAyMjYyNjY2NjE2IiwiaG90ZWxfaWQiOjExMTQ4OSwibWVzc2FnZV9pZCI6IjEwMTEiLCJwaG90b19zaGEyNTYiOiJlMmUyYjNmMDJiNGZjMzcyYjI2NjdhOGU3NWU2NGRjOGQ1OGYyMDQzNzRhZjZkZjVkNmMyOGVlYTljYjZhMDBmIiwicGxhdGZvcm0iOiJ0ZWxlZ3JhbSIsInBvc3RfdXJsIjoiaHR0cHM6Ly90Lm1lL2FueV90b291ci8xMDExIiwicHVibGljYXRpb25faWQiOjIxNjYsInRleHRfc2hhMjU2IjoiZjdkY2ZkYzBiNTVhMjhiZGFiMjE0MzAzYzJmZTg0OTUwODJhNGViYWM3ZDFhOTc0YTcxNDJhZmVmNmM5NDVjMiIsInZlcnNpb25faWQiOjc2OTV9LHsiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiZjI4M2Y0NTZlZTRjYzQ3OTZlMGU1NGViNTg4YTllMmE0NmYxYjRmZTU1ZTM1OWIwNGRhMGE2MzUzMjRjNjE2MSIsImNoYW5uZWxfaWQiOiItODA0OTIyNDQyMDk2ODMiLCJob3RlbF9pZCI6MTExNDg5LCJtZXNzYWdlX2lkIjoibWlkLmZmZmZiNmNhZjA2MDZmZWQwMWExZWFiZTUwYzM0M2MwIiwicGhvdG9fc2hhMjU2IjoiZTJlMmIzZjAyYjRmYzM3MmIyNjY3YThlNzVlNjRkYzhkNThmMjA0Mzc0YWY2ZGY1ZDZjMjhlZWE5Y2I2YTAwZiIsInBsYXRmb3JtIjoibWF4IiwicG9zdF91cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FueXRvdXIvQWFIcXZsRERROEEiLCJwdWJsaWNhdGlvbl9pZCI6MjE3NiwidGV4dF9zaGEyNTYiOiJmN2RjZmRjMGI1NWEyOGJkYWIyMTQzMDNjMmZlODQ5NTA4MmE0ZWJhYzdkMWE5NzRhNzE0MmFmZWY2Yzk0NWMyIiwidmVyc2lvbl9pZCI6NzcwNX1d',true),true,64,JSON_THROW_ON_ERROR);
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

