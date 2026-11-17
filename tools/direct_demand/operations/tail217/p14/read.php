<?php
declare(strict_types=1);
const EXPECTED_INSTALLED_SHA='8281b387f258932601439eb789560f6b2cbabd4d';
const FEED_EXPORT_INPUT_B64='W3siaG90ZWxfaWQiOjM3MTcyLCJuYW1lIjoiVGl0YW5pYyBCdXNpbmVzcyBLYXJ0YWwiLCJhY2NlcHRlZF9maXJzdF9saW5lIjoi4pyI77iPINCi0YPRgNGLINCyIFRpdGFuaWMgQnVzaW5lc3MgS2FydGFsIiwiaW1hZ2VfdXJsIjoiaHR0cHM6Ly9yYXcuZ2l0aHVidXNlcmNvbnRlbnQuY29tL3B5YXRrb2ZmL21heC1zZWFyY2gtYm90LzEzZDhlZTAxNGRkM2Q1OWFjYTAzOGVjNTBjZmRlMzQwYjJlZTA2MmEvLmdpdGh1Yi90cmFmZmljLXB1Ymxpc2gvY29tbWVyY2lhbC13YXZlMi1yZXNlcnZlNjUtMjAyNjExMTcvMzcxNzIucG5nIiwiaW1hZ2Vfc2hhMjU2IjoiMzgzYmQ3ZmVkYmI5NDc3YmI2ODgyMmZiMTU3NTMzMjQ2MTBlZGI0ZTQ1ZTNkNGQzYjZmZDRjNDM0YTRmNjhiYiJ9LHsiaG90ZWxfaWQiOjE3NjgzLCJuYW1lIjoiVGl0YW5pYyBQb3J0IEJha2lya295IiwiYWNjZXB0ZWRfZmlyc3RfbGluZSI6IuKciO+4jyDQotGD0YDRiyDQsiBUaXRhbmljIFBvcnQgQmFraXJrb3kiLCJpbWFnZV91cmwiOiJodHRwczovL3Jhdy5naXRodWJ1c2VyY29udGVudC5jb20vcHlhdGtvZmYvbWF4LXNlYXJjaC1ib3QvMTNkOGVlMDE0ZGQzZDU5YWNhMDM4ZWM1MGNmZGUzNDBiMmVlMDYyYS8uZ2l0aHViL3RyYWZmaWMtcHVibGlzaC9jb21tZXJjaWFsLXdhdmUyLXJlc2VydmU2NS0yMDI2MTExNy8xNzY4My5wbmciLCJpbWFnZV9zaGEyNTYiOiJmMWQ0MDM2Y2JjN2Q5NDhjMTE5ZDM4NjI5N2VkZmIzZWZmNjU5YTQzMGYxNTA2NGJmNmMxYjk3MzNlMTY3NTVlIn0seyJob3RlbF9pZCI6Mjg2NjIsIm5hbWUiOiJUaXRhbmljIEJ1c2luZXNzIEdvbGRlbiBIb3JuIiwiYWNjZXB0ZWRfZmlyc3RfbGluZSI6IuKciO+4jyDQotGD0YDRiyDQsiBUaXRhbmljIEJ1c2luZXNzIEdvbGRlbiBIb3JuIiwiaW1hZ2VfdXJsIjoiaHR0cHM6Ly9yYXcuZ2l0aHVidXNlcmNvbnRlbnQuY29tL3B5YXRrb2ZmL21heC1zZWFyY2gtYm90LzEzZDhlZTAxNGRkM2Q1OWFjYTAzOGVjNTBjZmRlMzQwYjJlZTA2MmEvLmdpdGh1Yi90cmFmZmljLXB1Ymxpc2gvY29tbWVyY2lhbC13YXZlMi1yZXNlcnZlNjUtMjAyNjExMTcvMjg2NjIucG5nIiwiaW1hZ2Vfc2hhMjU2IjoiMWMxZGJmYjYyZDVjMmUxMzk3MzgwYTg5OWM0MWVjYzVkY2FhODNmZjdjNThkNTEwZDQ0N2MzMzRhM2RmNzU4OSJ9LHsiaG90ZWxfaWQiOjgzMDU5LCJuYW1lIjoiU2VsZWN0dW0gQ2l0eSBBdGFzZWhpciIsImFjY2VwdGVkX2ZpcnN0X2xpbmUiOiLinIjvuI8g0KLRg9GA0Ysg0LIgU2VsZWN0dW0gQ2l0eSBBdGFzZWhpciIsImltYWdlX3VybCI6Imh0dHBzOi8vcmF3LmdpdGh1YnVzZXJjb250ZW50LmNvbS9weWF0a29mZi9tYXgtc2VhcmNoLWJvdC8xM2Q4ZWUwMTRkZDNkNTlhY2EwMzhlYzUwY2ZkZTM0MGIyZWUwNjJhLy5naXRodWIvdHJhZmZpYy1wdWJsaXNoL2NvbW1lcmNpYWwtd2F2ZTItcmVzZXJ2ZTY1LTIwMjYxMTE3LzgzMDU5LnBuZyIsImltYWdlX3NoYTI1NiI6ImY1YWQ2MTRmNDdjOWQwN2VkY2FlNWQ4MmMyNjAzZGMwZjljYTllZTk5NDM3MDQ3ZDk2NzIzNThhYzNjMmFiZDkifSx7ImhvdGVsX2lkIjo3NTY1OCwibmFtZSI6IlN0ZWlnZW5iZXJnZXIgUHlyYW1pZHMgQ2Fpcm8iLCJhY2NlcHRlZF9maXJzdF9saW5lIjoi4pyI77iPINCi0YPRgNGLINCyIFN0ZWlnZW5iZXJnZXIgUHlyYW1pZHMgQ2Fpcm8iLCJpbWFnZV91cmwiOiJodHRwczovL3Jhdy5naXRodWJ1c2VyY29udGVudC5jb20vcHlhdGtvZmYvbWF4LXNlYXJjaC1ib3QvMTNkOGVlMDE0ZGQzZDU5YWNhMDM4ZWM1MGNmZGUzNDBiMmVlMDYyYS8uZ2l0aHViL3RyYWZmaWMtcHVibGlzaC9jb21tZXJjaWFsLXdhdmUyLXJlc2VydmU2NS0yMDI2MTExNy83NTY1OC5wbmciLCJpbWFnZV9zaGEyNTYiOiI3NWNhY2U4MDhiMjk1NzIwMzMwYjRkMmRlNGJlZTFkMmFlMjMzMmJhZDM4Yjc3NjNmMGMyZjQ2ZGJiZTgyYjc1In0seyJob3RlbF9pZCI6NjA3ODcsIm5hbWUiOiJTdGVpZ2VuYmVyZ2VyIFRhaHJpciIsImFjY2VwdGVkX2ZpcnN0X2xpbmUiOiLinIjvuI8g0KLRg9GA0Ysg0LIgU3RlaWdlbmJlcmdlciBUYWhyaXIiLCJpbWFnZV91cmwiOiJodHRwczovL3Jhdy5naXRodWJ1c2VyY29udGVudC5jb20vcHlhdGtvZmYvbWF4LXNlYXJjaC1ib3QvMTNkOGVlMDE0ZGQzZDU5YWNhMDM4ZWM1MGNmZGUzNDBiMmVlMDYyYS8uZ2l0aHViL3RyYWZmaWMtcHVibGlzaC9jb21tZXJjaWFsLXdhdmUyLXJlc2VydmU2NS0yMDI2MTExNy82MDc4Ny5wbmciLCJpbWFnZV9zaGEyNTYiOiJjM2MxYjQxMmEyNGFmMzUyODUyYzVjMDg5ZjUwMzM4MDBjMjZkOGRmYjRhZjMzZDVmY2I5NGJjOTM5MjhkMTc0In0seyJob3RlbF9pZCI6MjU5MiwibmFtZSI6IktlbXBpbnNraSBNYWxsIG9mIHRoZSBFbWlyYXRlcyIsImFjY2VwdGVkX2ZpcnN0X2xpbmUiOiLinIjvuI8g0KLRg9GA0Ysg0LIgS2VtcGluc2tpIE1hbGwgb2YgdGhlIEVtaXJhdGVzIiwiaW1hZ2VfdXJsIjoiaHR0cHM6Ly9yYXcuZ2l0aHVidXNlcmNvbnRlbnQuY29tL3B5YXRrb2ZmL21heC1zZWFyY2gtYm90LzEzZDhlZTAxNGRkM2Q1OWFjYTAzOGVjNTBjZmRlMzQwYjJlZTA2MmEvLmdpdGh1Yi90cmFmZmljLXB1Ymxpc2gvY29tbWVyY2lhbC13YXZlMi1yZXNlcnZlNjUtMjAyNjExMTcvMjU5Mi5wbmciLCJpbWFnZV9zaGEyNTYiOiIwOGNlMGE3MDU3YTFhNTY3YjE3NWNhZTU2YTA0MzUxNjdmYTdmYTc3MWEwNWY5NmFjZjAyMDNhYTQ3NGViYWIyIn0seyJob3RlbF9pZCI6MjY4MSwibmFtZSI6IktlbXBpbnNraSBDZW50cmFsIEF2ZW51ZSBEdWJhaSIsImFjY2VwdGVkX2ZpcnN0X2xpbmUiOiLinIjvuI8g0KLRg9GA0Ysg0LIgS2VtcGluc2tpIENlbnRyYWwgQXZlbnVlIER1YmFpIiwiaW1hZ2VfdXJsIjoiaHR0cHM6Ly9yYXcuZ2l0aHVidXNlcmNvbnRlbnQuY29tL3B5YXRrb2ZmL21heC1zZWFyY2gtYm90LzEzZDhlZTAxNGRkM2Q1OWFjYTAzOGVjNTBjZmRlMzQwYjJlZTA2MmEvLmdpdGh1Yi90cmFmZmljLXB1Ymxpc2gvY29tbWVyY2lhbC13YXZlMi1yZXNlcnZlNjUtMjAyNjExMTcvMjY4MS5wbmciLCJpbWFnZV9zaGEyNTYiOiIwZTA4MGY5ZjViNjhlOGQ4MmY4MDE0YzliYTZhNTRiMzllMWVmNmIzYjEyMGUyZjI4MzM1ZDNmMzQ4MjQ4NmIwIn0seyJob3RlbF9pZCI6NDk4NTAsIm5hbWUiOiJLZW1waW5za2kgVGhlIEJvdWxldmFyZCBEdWJhaSIsImFjY2VwdGVkX2ZpcnN0X2xpbmUiOiLinIjvuI8g0KLRg9GA0Ysg0LIgS2VtcGluc2tpIFRoZSBCb3VsZXZhcmQgRHViYWkiLCJpbWFnZV91cmwiOiJodHRwczovL3Jhdy5naXRodWJ1c2VyY29udGVudC5jb20vcHlhdGtvZmYvbWF4LXNlYXJjaC1ib3QvMTNkOGVlMDE0ZGQzZDU5YWNhMDM4ZWM1MGNmZGUzNDBiMmVlMDYyYS8uZ2l0aHViL3RyYWZmaWMtcHVibGlzaC9jb21tZXJjaWFsLXdhdmUyLXJlc2VydmU2NS0yMDI2MTExNy80OTg1MC5wbmciLCJpbWFnZV9zaGEyNTYiOiJjNmU4MDllZGQxNWViNDliYzU5ZjBlMDkzYjU5MGI3ZGYyZGU1NDQ3MDM3YWE2NmViODU5YTEzZTc0MGM5NWU3In0seyJob3RlbF9pZCI6NjUwMzEsIm5hbWUiOiJSYWZmbGVzIFRoZSBQYWxtIER1YmFpIiwiYWNjZXB0ZWRfZmlyc3RfbGluZSI6IuKciO+4jyDQotGD0YDRiyDQsiBSYWZmbGVzIFRoZSBQYWxtIER1YmFpIiwiaW1hZ2VfdXJsIjoiaHR0cHM6Ly9yYXcuZ2l0aHVidXNlcmNvbnRlbnQuY29tL3B5YXRrb2ZmL21heC1zZWFyY2gtYm90LzEzZDhlZTAxNGRkM2Q1OWFjYTAzOGVjNTBjZmRlMzQwYjJlZTA2MmEvLmdpdGh1Yi90cmFmZmljLXB1Ymxpc2gvY29tbWVyY2lhbC13YXZlMi1yZXNlcnZlNjUtMjAyNjExMTcvNjUwMzEucG5nIiwiaW1hZ2Vfc2hhMjU2IjoiYjljZWRkMzQwYTBmM2VhMjc2NTExZTg1OGJjZThiYTk1MWU0ZmI5MzEyNzhlYjBjMGNkOTU3YWZlYjllNWQ1NCJ9XQ==';
const FEED_EXPORT_INPUT_SHA256='42f97fa5c5e1deefde93ff17476e82caf453cb012d65c99adf03f683894f59da';
const CANDIDATE_INPUT_B64='W10=';
const CANDIDATE_INPUT_SHA256='4f53cda18c2baa0c0354bb5f9a3ecbe5ed12ab4d8e11ba873c2f11161202b945';
ini_set('display_errors','0');ini_set('log_errors','0');
$out=['operation'=>'commercial-channel-hotel-feeds-20261117-read214','mode'=>'read_bounded_public_channel_post_feed_inputs','ok'=>false,'db_writes'=>0,'provider_calls'=>0,'message_sends'=>0];
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
 insist(count($in)===10&&array_column($in,'hotel_id')===[37172,17683,28662,83059,75658,60787,2592,2681,49850,65031],'read17_exact_new1_scope');
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
    $retained=json_decode(base64_decode('W3siaG90ZWxfaWQiOjM3MTcyLCJwbGF0Zm9ybSI6InRlbGVncmFtIiwicHVibGljYXRpb25faWQiOjE3MTgsInZlcnNpb25faWQiOjcyNDYsInBvc3RfdXJsIjoiaHR0cHM6Ly90Lm1lL2FueV90b291ci83ODIiLCJjaGFubmVsX2lkIjoiLTEwMDIyNjI2NjY2MTYiLCJtZXNzYWdlX2lkIjoiNzgyIiwidGV4dF9zaGEyNTYiOiJmMzMwY2EyNWNmZGVkOWFhOTNlZjQ2NzMxZjU1MDk5NzM1NTZiZDJiNTIwZThlNWJjOTViMWFmMjQ2MDVlZmMyIiwicGhvdG9fc2hhMjU2IjoiMzgzYmQ3ZmVkYmI5NDc3YmI2ODgyMmZiMTU3NTMzMjQ2MTBlZGI0ZTQ1ZTNkNGQzYjZmZDRjNDM0YTRmNjhiYiIsImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6ImU0OGVlZGY4MDkyNTI1NmJhY2MzYzkyNzU5YWQzZGIxMzgzMGJjODdlMzQ4OWNjMDlhMzc4OTdmZWE1ZjIzYjcifSx7ImhvdGVsX2lkIjoxNzY4MywicGxhdGZvcm0iOiJ0ZWxlZ3JhbSIsInB1YmxpY2F0aW9uX2lkIjoxNzE5LCJ2ZXJzaW9uX2lkIjo3MjQ3LCJwb3N0X3VybCI6Imh0dHBzOi8vdC5tZS9hbnlfdG9vdXIvNzgzIiwiY2hhbm5lbF9pZCI6Ii0xMDAyMjYyNjY2NjE2IiwibWVzc2FnZV9pZCI6Ijc4MyIsInRleHRfc2hhMjU2IjoiZDFjYWNiMjAxNzMzYWU3YzRmYzQzNGEzZTU4NGY3N2I2MDViNzNlODUzY2Y3YTM2MDEzYzcwNDFhMzk2Nzg5MyIsInBob3RvX3NoYTI1NiI6ImYxZDQwMzZjYmM3ZDk0OGMxMTlkMzg2Mjk3ZWRmYjNlZmY2NTlhNDMwZjE1MDY0YmY2YzFiOTczM2UxNjc1NWUiLCJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiI3NWQ1MDRjMTUyMTRlNzU3M2E0YmMzNTQzZThlODNmOGFiZDMwM2I1YTdlNTJhMmQxZjZiMjc2ZmUxMTJjMDA3In0seyJob3RlbF9pZCI6Mjg2NjIsInBsYXRmb3JtIjoidGVsZWdyYW0iLCJwdWJsaWNhdGlvbl9pZCI6MTcyMCwidmVyc2lvbl9pZCI6NzI0OCwicG9zdF91cmwiOiJodHRwczovL3QubWUvYW55X3Rvb3VyLzc4NCIsImNoYW5uZWxfaWQiOiItMTAwMjI2MjY2NjYxNiIsIm1lc3NhZ2VfaWQiOiI3ODQiLCJ0ZXh0X3NoYTI1NiI6IjczNTIxZmI4YzlhNjkxNzExNTUxNGE5NjFiNTdhMTI1YjUwOWMxNDBjN2Q2ZDg2MWI3YTcyNmM4NTFkNzJmZTkiLCJwaG90b19zaGEyNTYiOiIxYzFkYmZiNjJkNWMyZTEzOTczODBhODk5YzQxZWNjNWRjYWE4M2ZmN2M1OGQ1MTBkNDQ3YzMzNGEzZGY3NTg5IiwiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiOGYwZjZlNTA0M2JlNjk4ZGU3ZmY4NTZlMTNjODJhOWEyZmMwYWRlZDRhYmZlYTM4OTNiNDFhYTQzMDI1MWQ2NiJ9LHsiaG90ZWxfaWQiOjgzMDU5LCJwbGF0Zm9ybSI6InRlbGVncmFtIiwicHVibGljYXRpb25faWQiOjE3MjEsInZlcnNpb25faWQiOjcyNDksInBvc3RfdXJsIjoiaHR0cHM6Ly90Lm1lL2FueV90b291ci83ODUiLCJjaGFubmVsX2lkIjoiLTEwMDIyNjI2NjY2MTYiLCJtZXNzYWdlX2lkIjoiNzg1IiwidGV4dF9zaGEyNTYiOiIwMTU2OTQ2NjdhM2RhYmI3NDdhZjc2MjVmYTJkYzE2MDE0OGRhMWE5MDI0MzYyYjk5NTMzZjM4ZjkzYWQ4NDYzIiwicGhvdG9fc2hhMjU2IjoiZjVhZDYxNGY0N2M5ZDA3ZWRjYWU1ZDgyYzI2MDNkYzBmOWNhOWVlOTk0MzcwNDdkOTY3MjM1OGFjM2MyYWJkOSIsImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6IjRhYWMxYTVhMmJhOGE5MGM2YjNkODkxNzA4MDM1MThiYjA2YTNiYzM2MTYyNGNjN2YxMTI0ZmUwYjVmZmMwMGEifSx7ImhvdGVsX2lkIjo3NTY1OCwicGxhdGZvcm0iOiJ0ZWxlZ3JhbSIsInB1YmxpY2F0aW9uX2lkIjoxNzIyLCJ2ZXJzaW9uX2lkIjo3MjUwLCJwb3N0X3VybCI6Imh0dHBzOi8vdC5tZS9hbnlfdG9vdXIvNzg2IiwiY2hhbm5lbF9pZCI6Ii0xMDAyMjYyNjY2NjE2IiwibWVzc2FnZV9pZCI6Ijc4NiIsInRleHRfc2hhMjU2IjoiYzJhMTVhYzk3MTY5YmRjNjIxMDI0MTE4OTY4NjU1MTEyZjk1MWU0ODQwMTNjMTUyZmQxNTRkNDA4Mjc4MDQ2MSIsInBob3RvX3NoYTI1NiI6Ijc1Y2FjZTgwOGIyOTU3MjAzMzBiNGQyZGU0YmVlMWQyYWUyMzMyYmFkMzhiNzc2M2YwYzJmNDZkYmJlODJiNzUiLCJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiI3ZWIzZTliNTczZmUxYjdhZmNiYzI0NjNlMjM0N2NiZWU2MjYwYzM0YThmOTMxYTBkMGM2N2Y5MGE3NWQyNTQ2In0seyJob3RlbF9pZCI6NjA3ODcsInBsYXRmb3JtIjoidGVsZWdyYW0iLCJwdWJsaWNhdGlvbl9pZCI6MTcyMywidmVyc2lvbl9pZCI6NzI1MSwicG9zdF91cmwiOiJodHRwczovL3QubWUvYW55X3Rvb3VyLzc4NyIsImNoYW5uZWxfaWQiOiItMTAwMjI2MjY2NjYxNiIsIm1lc3NhZ2VfaWQiOiI3ODciLCJ0ZXh0X3NoYTI1NiI6IjY4OTRhYWRlYWE2MWE1YTE2MzY4NjMwOTBkMWM1MWEzNjgxOTc0YTA2OGY1NTVhOWJjODcyODUxZDZhMmQzMjEiLCJwaG90b19zaGEyNTYiOiJjM2MxYjQxMmEyNGFmMzUyODUyYzVjMDg5ZjUwMzM4MDBjMjZkOGRmYjRhZjMzZDVmY2I5NGJjOTM5MjhkMTc0IiwiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiZTMxOGM5NmNhZmJkNTUzYjliNjRhMTJkMmE0M2VjYmUzMDlmZmE5Y2IzOTlhN2QwZTE1OGNjMGIxZjU3YTNkYSJ9LHsiaG90ZWxfaWQiOjI1OTIsInBsYXRmb3JtIjoidGVsZWdyYW0iLCJwdWJsaWNhdGlvbl9pZCI6MTcyNCwidmVyc2lvbl9pZCI6NzI1MiwicG9zdF91cmwiOiJodHRwczovL3QubWUvYW55X3Rvb3VyLzc4OCIsImNoYW5uZWxfaWQiOiItMTAwMjI2MjY2NjYxNiIsIm1lc3NhZ2VfaWQiOiI3ODgiLCJ0ZXh0X3NoYTI1NiI6ImJmZTBkZGYwMjUxMzM1ZDkzZmQ2NmQ1ZDM1NjQ4NjUxYTQwYzZiY2ZmY2U5NTQwMjVjMDViMTg4ZWM3MDBmZWEiLCJwaG90b19zaGEyNTYiOiIwOGNlMGE3MDU3YTFhNTY3YjE3NWNhZTU2YTA0MzUxNjdmYTdmYTc3MWEwNWY5NmFjZjAyMDNhYTQ3NGViYWIyIiwiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiMjRlODk4ODNlOTM4NDliYTFlMTY4NjBlN2U1ZWY5YmE1OWIyNjBjM2JmZDU0NTRiOTg4YmIzMWEwM2UyNWQxMSJ9LHsiaG90ZWxfaWQiOjI2ODEsInBsYXRmb3JtIjoidGVsZWdyYW0iLCJwdWJsaWNhdGlvbl9pZCI6MTcyNSwidmVyc2lvbl9pZCI6NzI1MywicG9zdF91cmwiOiJodHRwczovL3QubWUvYW55X3Rvb3VyLzc4OSIsImNoYW5uZWxfaWQiOiItMTAwMjI2MjY2NjYxNiIsIm1lc3NhZ2VfaWQiOiI3ODkiLCJ0ZXh0X3NoYTI1NiI6ImI0NTAyNDEwNzBkZmM3MjYxNDE3YWFmZTE5YTIyMWYyNjVhM2Q3MDYxMWM1ZDRlNTg2YWVkYmJhYjY3NDQxZWEiLCJwaG90b19zaGEyNTYiOiIwZTA4MGY5ZjViNjhlOGQ4MmY4MDE0YzliYTZhNTRiMzllMWVmNmIzYjEyMGUyZjI4MzM1ZDNmMzQ4MjQ4NmIwIiwiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiZTMyYzhiOTAyZmJjMDZlOTk1MTM5MWQwMmRkNzJjMGNmODAxOGJlZTc0NTJkNWVkYmU2Yzk5OWYyOTc0NzNlNCJ9LHsiaG90ZWxfaWQiOjQ5ODUwLCJwbGF0Zm9ybSI6InRlbGVncmFtIiwicHVibGljYXRpb25faWQiOjE3MjYsInZlcnNpb25faWQiOjcyNTQsInBvc3RfdXJsIjoiaHR0cHM6Ly90Lm1lL2FueV90b291ci83OTAiLCJjaGFubmVsX2lkIjoiLTEwMDIyNjI2NjY2MTYiLCJtZXNzYWdlX2lkIjoiNzkwIiwidGV4dF9zaGEyNTYiOiJiMzhhMzM4ODBiM2JhNzI0ODI5NGVhNjk0OTBlNGEzMmRhNmNlY2VlYzVkNzNiZjE3MDZmN2QwNmQ3OWRjMzM2IiwicGhvdG9fc2hhMjU2IjoiYzZlODA5ZWRkMTVlYjQ5YmM1OWYwZTA5M2I1OTBiN2RmMmRlNTQ0NzAzN2FhNjZlYjg1OWExM2U3NDBjOTVlNyIsImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6ImMxNjE1NTRmOWQ2MDcxNjlmOWMzNDhhNjU3NzRjNTIwN2FiZmRmMjZhMzZkZmYxNzQzZjNkM2E2YjU5NDljNTUifSx7ImhvdGVsX2lkIjo2NTAzMSwicGxhdGZvcm0iOiJ0ZWxlZ3JhbSIsInB1YmxpY2F0aW9uX2lkIjoxNzI3LCJ2ZXJzaW9uX2lkIjo3MjU1LCJwb3N0X3VybCI6Imh0dHBzOi8vdC5tZS9hbnlfdG9vdXIvNzkxIiwiY2hhbm5lbF9pZCI6Ii0xMDAyMjYyNjY2NjE2IiwibWVzc2FnZV9pZCI6Ijc5MSIsInRleHRfc2hhMjU2IjoiNDI0Y2NiYWNlNTNhMzEyYTc5ZWM3MzI0ODVkZTJmZmZiNzQ1OGVkYWY3ZDc1YTM1MTdkY2Q0Njc0NWRiNzIyZiIsInBob3RvX3NoYTI1NiI6ImI5Y2VkZDM0MGEwZjNlYTI3NjUxMWU4NThiY2U4YmE5NTFlNGZiOTMxMjc4ZWIwYzBjZDk1N2FmZWI5ZTVkNTQiLCJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiJkZDBmMmFlZmUzNGZkZDczNDYzYjVjOWZhZDNmMGJiOGQ5OGI2ODA0ZmU4OTMzYTZkNTk2N2Q1MDhmZmE3YjcxIn0seyJob3RlbF9pZCI6MzcxNzIsInBsYXRmb3JtIjoibWF4IiwicHVibGljYXRpb25faWQiOjE3MjgsInZlcnNpb25faWQiOjcyNTYsInBvc3RfdXJsIjoiaHR0cHM6Ly9tYXgucnUvY2hhbm5lbF9hbnl0b3VyL0FhSHFDczZYS2l3IiwiY2hhbm5lbF9pZCI6Ii04MDQ5MjI0NDIwOTY4MyIsIm1lc3NhZ2VfaWQiOiJtaWQuZmZmZmI2Y2FmMDYwNmZlZDAxYTFlYTBhY2U5NzJhMmMiLCJ0ZXh0X3NoYTI1NiI6ImYzMzBjYTI1Y2ZkZWQ5YWE5M2VmNDY3MzFmNTUwOTk3MzU1NmJkMmI1MjBlOGU1YmM5NWIxYWYyNDYwNWVmYzIiLCJwaG90b19zaGEyNTYiOiIzODNiZDdmZWRiYjk0NzdiYjY4ODIyZmIxNTc1MzMyNDYxMGVkYjRlNDVlM2Q0ZDNiNmZkNGM0MzRhNGY2OGJiIiwiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiMDdiMDVkNjI1MWQ2ZmRjMzg3ZGZhZmJmOTM0Njg1NzliODdiNDU2MjBiMmIwZDkyZTdjZmIyNDRhZGYxYTFhMyJ9LHsiaG90ZWxfaWQiOjE3NjgzLCJwbGF0Zm9ybSI6Im1heCIsInB1YmxpY2F0aW9uX2lkIjoxNzI5LCJ2ZXJzaW9uX2lkIjo3MjU3LCJwb3N0X3VybCI6Imh0dHBzOi8vbWF4LnJ1L2NoYW5uZWxfYW55dG91ci9BYUhxQ3RpOUJOayIsImNoYW5uZWxfaWQiOiItODA0OTIyNDQyMDk2ODMiLCJtZXNzYWdlX2lkIjoibWlkLmZmZmZiNmNhZjA2MDZmZWQwMWExZWEwYWQ4YmQwNGQ5IiwidGV4dF9zaGEyNTYiOiJkMWNhY2IyMDE3MzNhZTdjNGZjNDM0YTNlNTg0Zjc3YjYwNWI3M2U4NTNjZjdhMzYwMTNjNzA0MWEzOTY3ODkzIiwicGhvdG9fc2hhMjU2IjoiZjFkNDAzNmNiYzdkOTQ4YzExOWQzODYyOTdlZGZiM2VmZjY1OWE0MzBmMTUwNjRiZjZjMWI5NzMzZTE2NzU1ZSIsImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6ImJkNTU1MjU4YzIxNGNhZDNiOTBhNmUyYzg5NDI3N2Q1Y2I0ODZmODQxYTMwM2RlNjQ5MTBlNWI2Zjg3NDNjYWQifSx7ImhvdGVsX2lkIjoyODY2MiwicGxhdGZvcm0iOiJtYXgiLCJwdWJsaWNhdGlvbl9pZCI6MTczMCwidmVyc2lvbl9pZCI6NzI1OCwicG9zdF91cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FueXRvdXIvQWFIcUN1SWVRa28iLCJjaGFubmVsX2lkIjoiLTgwNDkyMjQ0MjA5NjgzIiwibWVzc2FnZV9pZCI6Im1pZC5mZmZmYjZjYWYwNjA2ZmVkMDFhMWVhMGFlMjFlNDI0YSIsInRleHRfc2hhMjU2IjoiNzM1MjFmYjhjOWE2OTE3MTE1NTE0YTk2MWI1N2ExMjViNTA5YzE0MGM3ZDZkODYxYjdhNzI2Yzg1MWQ3MmZlOSIsInBob3RvX3NoYTI1NiI6IjFjMWRiZmI2MmQ1YzJlMTM5NzM4MGE4OTljNDFlY2M1ZGNhYTgzZmY3YzU4ZDUxMGQ0NDdjMzM0YTNkZjc1ODkiLCJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiIxOTM4NThmOTA1ZDE1MDRiNjc0YjliY2JlZjkzYzEyNWFiMmJmN2FjY2FhM2QwZGQ2NWJmNmYzODZlODAxOWYxIn0seyJob3RlbF9pZCI6ODMwNTksInBsYXRmb3JtIjoibWF4IiwicHVibGljYXRpb25faWQiOjE3MzEsInZlcnNpb25faWQiOjcyNTksInBvc3RfdXJsIjoiaHR0cHM6Ly9tYXgucnUvY2hhbm5lbF9hbnl0b3VyL0FhSHFDdXNkQ2wwIiwiY2hhbm5lbF9pZCI6Ii04MDQ5MjI0NDIwOTY4MyIsIm1lc3NhZ2VfaWQiOiJtaWQuZmZmZmI2Y2FmMDYwNmZlZDAxYTFlYTBhZWIxZDBhNWQiLCJ0ZXh0X3NoYTI1NiI6IjAxNTY5NDY2N2EzZGFiYjc0N2FmNzYyNWZhMmRjMTYwMTQ4ZGExYTkwMjQzNjJiOTk1MzNmMzhmOTNhZDg0NjMiLCJwaG90b19zaGEyNTYiOiJmNWFkNjE0ZjQ3YzlkMDdlZGNhZTVkODJjMjYwM2RjMGY5Y2E5ZWU5OTQzNzA0N2Q5NjcyMzU4YWMzYzJhYmQ5IiwiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiNjdmZGE3YzkyNGMyYTdlY2UxOTU0YjU2NWVmNWE5YjBlMDU2NGM5NWM4ZjM5YTQ1Yjg5MTkyNDZjNmQyNGYyYSJ9LHsiaG90ZWxfaWQiOjc1NjU4LCJwbGF0Zm9ybSI6Im1heCIsInB1YmxpY2F0aW9uX2lkIjoxNzMyLCJ2ZXJzaW9uX2lkIjo3MjYwLCJwb3N0X3VybCI6Imh0dHBzOi8vbWF4LnJ1L2NoYW5uZWxfYW55dG91ci9BYUhxQ3ZSN2ItOCIsImNoYW5uZWxfaWQiOiItODA0OTIyNDQyMDk2ODMiLCJtZXNzYWdlX2lkIjoibWlkLmZmZmZiNmNhZjA2MDZmZWQwMWExZWEwYWY0N2I2ZmVmIiwidGV4dF9zaGEyNTYiOiJjMmExNWFjOTcxNjliZGM2MjEwMjQxMTg5Njg2NTUxMTJmOTUxZTQ4NDAxM2MxNTJmZDE1NGQ0MDgyNzgwNDYxIiwicGhvdG9fc2hhMjU2IjoiNzVjYWNlODA4YjI5NTcyMDMzMGI0ZDJkZTRiZWUxZDJhZTIzMzJiYWQzOGI3NzYzZjBjMmY0NmRiYmU4MmI3NSIsImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6IjEyYjYxYWE3MGM0NWJkYzQxNGRmZmU2ZGYwOWE5OGNkYjg4YjRmMzhjNzQzMTIyZTJlMjFlZmJkZmRmNmRjOWMifSx7ImhvdGVsX2lkIjo2MDc4NywicGxhdGZvcm0iOiJtYXgiLCJwdWJsaWNhdGlvbl9pZCI6MTczMywidmVyc2lvbl9pZCI6NzI2MSwicG9zdF91cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FueXRvdXIvQWFIcUN2M3NIZjgiLCJjaGFubmVsX2lkIjoiLTgwNDkyMjQ0MjA5NjgzIiwibWVzc2FnZV9pZCI6Im1pZC5mZmZmYjZjYWYwNjA2ZmVkMDFhMWVhMGFmZGVjMWRmZiIsInRleHRfc2hhMjU2IjoiNjg5NGFhZGVhYTYxYTVhMTYzNjg2MzA5MGQxYzUxYTM2ODE5NzRhMDY4ZjU1NWE5YmM4NzI4NTFkNmEyZDMyMSIsInBob3RvX3NoYTI1NiI6ImMzYzFiNDEyYTI0YWYzNTI4NTJjNWMwODlmNTAzMzgwMGMyNmQ4ZGZiNGFmMzNkNWZjYjk0YmM5MzkyOGQxNzQiLCJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiJiMmFiYzIzZjZjMmY3YTc3MDYwYTQ0NDlmMTUyM2EyMzc3NmIzNDgxMzY5MTE3MDYxNDU0ZGZmN2U1NjBlNGFhIn0seyJob3RlbF9pZCI6MjU5MiwicGxhdGZvcm0iOiJtYXgiLCJwdWJsaWNhdGlvbl9pZCI6MTczNCwidmVyc2lvbl9pZCI6NzI2MiwicG9zdF91cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FueXRvdXIvQWFIcUN3YzFVUEEiLCJjaGFubmVsX2lkIjoiLTgwNDkyMjQ0MjA5NjgzIiwibWVzc2FnZV9pZCI6Im1pZC5mZmZmYjZjYWYwNjA2ZmVkMDFhMWVhMGIwNzM1NTBmMCIsInRleHRfc2hhMjU2IjoiYmZlMGRkZjAyNTEzMzVkOTNmZDY2ZDVkMzU2NDg2NTFhNDBjNmJjZmZjZTk1NDAyNWMwNWIxODhlYzcwMGZlYSIsInBob3RvX3NoYTI1NiI6IjA4Y2UwYTcwNTdhMWE1NjdiMTc1Y2FlNTZhMDQzNTE2N2ZhN2ZhNzcxYTA1Zjk2YWNmMDIwM2FhNDc0ZWJhYjIiLCJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiI3YzRlMDQ2NTkzODZmN2QxNDcxMDViYTBkYjEwZmFhNTEzMTNhYmIwNzliZTJjODQ3NmJlNTdjODhmOWNkM2ZkIn0seyJob3RlbF9pZCI6MjY4MSwicGxhdGZvcm0iOiJtYXgiLCJwdWJsaWNhdGlvbl9pZCI6MTczNSwidmVyc2lvbl9pZCI6NzI2MywicG9zdF91cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FueXRvdXIvQWFIcUN4Qm1RYmsiLCJjaGFubmVsX2lkIjoiLTgwNDkyMjQ0MjA5NjgzIiwibWVzc2FnZV9pZCI6Im1pZC5mZmZmYjZjYWYwNjA2ZmVkMDFhMWVhMGIxMDY2NDFiOSIsInRleHRfc2hhMjU2IjoiYjQ1MDI0MTA3MGRmYzcyNjE0MTdhYWZlMTlhMjIxZjI2NWEzZDcwNjExYzVkNGU1ODZhZWRiYmFiNjc0NDFlYSIsInBob3RvX3NoYTI1NiI6IjBlMDgwZjlmNWI2OGU4ZDgyZjgwMTRjOWJhNmE1NGIzOWUxZWY2YjNiMTIwZTJmMjgzMzVkM2YzNDgyNDg2YjAiLCJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiJiMDMwMzU2NTZiOTJkNzUwNjhkNDc5NTkyNmY4ZjkxMmZlZDI5M2UzMTY5ZWZjMDYzNTA1NWFkNzEzZmNjOTg1In0seyJob3RlbF9pZCI6NDk4NTAsInBsYXRmb3JtIjoibWF4IiwicHVibGljYXRpb25faWQiOjE3MzYsInZlcnNpb25faWQiOjcyNjQsInBvc3RfdXJsIjoiaHR0cHM6Ly9tYXgucnUvY2hhbm5lbF9hbnl0b3VyL0FhSHFDeU8xYWhBIiwiY2hhbm5lbF9pZCI6Ii04MDQ5MjI0NDIwOTY4MyIsIm1lc3NhZ2VfaWQiOiJtaWQuZmZmZmI2Y2FmMDYwNmZlZDAxYTFlYTBiMjNiNTZhMTAiLCJ0ZXh0X3NoYTI1NiI6ImIzOGEzMzg4MGIzYmE3MjQ4Mjk0ZWE2OTQ5MGU0YTMyZGE2Y2VjZWVjNWQ3M2JmMTcwNmY3ZDA2ZDc5ZGMzMzYiLCJwaG90b19zaGEyNTYiOiJjNmU4MDllZGQxNWViNDliYzU5ZjBlMDkzYjU5MGI3ZGYyZGU1NDQ3MDM3YWE2NmViODU5YTEzZTc0MGM5NWU3IiwiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiNGQxYzQzZDlkNWRhNmM5OTMwNDQwYjlmODlmOTk5ZDU1Y2Q1NWQ0NTA3ZWQ4ZTI4M2U1NmE3MjY0MTMxNjcxYSJ9LHsiaG90ZWxfaWQiOjY1MDMxLCJwbGF0Zm9ybSI6Im1heCIsInB1YmxpY2F0aW9uX2lkIjoxNzM3LCJ2ZXJzaW9uX2lkIjo3MjY1LCJwb3N0X3VybCI6Imh0dHBzOi8vbWF4LnJ1L2NoYW5uZWxfYW55dG91ci9BYUhxQ3lfSmRwNCIsImNoYW5uZWxfaWQiOiItODA0OTIyNDQyMDk2ODMiLCJtZXNzYWdlX2lkIjoibWlkLmZmZmZiNmNhZjA2MDZmZWQwMWExZWEwYjJmYzk3NjllIiwidGV4dF9zaGEyNTYiOiI0MjRjY2JhY2U1M2EzMTJhNzllYzczMjQ4NWRlMmZmZmI3NDU4ZWRhZjdkNzVhMzUxN2RjZDQ2NzQ1ZGI3MjJmIiwicGhvdG9fc2hhMjU2IjoiYjljZWRkMzQwYTBmM2VhMjc2NTExZTg1OGJjZThiYTk1MWU0ZmI5MzEyNzhlYjBjMGNkOTU3YWZlYjllNWQ1NCIsImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6IjZkYzYwZmRmNTA5MDM1Y2JmZWZlZWVmZDBjZTUyNGE0ZmUxNDVkZGFjNDE1YjFlZTQ0MGNhYzBjNWIwYTRiOTQifV0=',true),true,64,JSON_THROW_ON_ERROR);
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

