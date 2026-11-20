<?php
declare(strict_types=1);
const EXPECTED_INSTALLED_SHA='b4b65daaa472c15426a244b87ede3e6c0394626b';
const FEED_EXPORT_INPUT_B64='W3siYWNjZXB0ZWRfZmlyc3RfbGluZSI6IuKciO+4jyDQotGD0YDRiyDQsiBXYWxkb3JmIEFzdG9yaWEgU2V5Y2hlbGxlcyBQbGF0dGUgSXNsYW5kIiwiaG90ZWxfaWQiOjEwODMxNywiaW1hZ2Vfc2hhMjU2IjoiYWI1MjU3YTgyOTcwY2E3ZDFmMGFlMjMyODQwZmEyMjhlZTQzNDllZWU5ODk5Njc0YmMzOGFmZjZhMDE1NWMyYiIsImltYWdlX3VybCI6Imh0dHBzOi8vcmF3LmdpdGh1YnVzZXJjb250ZW50LmNvbS9weWF0a29mZi9tYXgtc2VhcmNoLWJvdC9hOGFkNDQwYzY5MTBmYTRhZjBiYTUzMzdkNzU5ZGJlNTYyMGMxMzc3Ly5naXRodWIvdHJhZmZpYy1wdWJsaXNoL2NvbW1lcmNpYWwtd2F2ZTYtZmlyc3QxMC0yMDI2MTExNy8xMDgzMTcucG5nIiwibmFtZSI6IldhbGRvcmYgQXN0b3JpYSBTZXljaGVsbGVzIFBsYXR0ZSBJc2xhbmQifSx7ImFjY2VwdGVkX2ZpcnN0X2xpbmUiOiLinIjvuI8g0KLRg9GA0Ysg0LIgRm91ciBTZWFzb25zIFJlc29ydCBTZXljaGVsbGVzIGF0IERlc3JvY2hlcyBJc2xhbmQiLCJob3RlbF9pZCI6MTE4MzYsImltYWdlX3NoYTI1NiI6IjBmOWUzMjUwZTQ5N2JhNTYzYjk1NzcwNDI2NTBkOGFlYmNmMDUzNWUzMmFhNjdjOTRjODFhODM2MmIyZGVmYWUiLCJpbWFnZV91cmwiOiJodHRwczovL3Jhdy5naXRodWJ1c2VyY29udGVudC5jb20vcHlhdGtvZmYvbWF4LXNlYXJjaC1ib3QvYThhZDQ0MGM2OTEwZmE0YWYwYmE1MzM3ZDc1OWRiZTU2MjBjMTM3Ny8uZ2l0aHViL3RyYWZmaWMtcHVibGlzaC9jb21tZXJjaWFsLXdhdmU2LWZpcnN0MTAtMjAyNjExMTcvMTE4MzYucG5nIiwibmFtZSI6IkZvdXIgU2Vhc29ucyBSZXNvcnQgU2V5Y2hlbGxlcyBhdCBEZXNyb2NoZXMgSXNsYW5kIn0seyJhY2NlcHRlZF9maXJzdF9saW5lIjoi4pyI77iPINCi0YPRgNGLINCyIEZvdXIgU2Vhc29ucyBSZXNvcnQgTWF1cml0aXVzIGF0IEFuYWhpdGEiLCJob3RlbF9pZCI6MTE3NDYsImltYWdlX3NoYTI1NiI6ImFhYzMxYzE5ODkyMmQ4MzdjNTIwYjM5YTliNjVhNjBkOTcwN2NkMjE0Njg5ODdhODcwMzczYzliYWQzNDBjYmUiLCJpbWFnZV91cmwiOiJodHRwczovL3Jhdy5naXRodWJ1c2VyY29udGVudC5jb20vcHlhdGtvZmYvbWF4LXNlYXJjaC1ib3QvYThhZDQ0MGM2OTEwZmE0YWYwYmE1MzM3ZDc1OWRiZTU2MjBjMTM3Ny8uZ2l0aHViL3RyYWZmaWMtcHVibGlzaC9jb21tZXJjaWFsLXdhdmU2LWZpcnN0MTAtMjAyNjExMTcvMTE3NDYucG5nIiwibmFtZSI6IkZvdXIgU2Vhc29ucyBSZXNvcnQgTWF1cml0aXVzIGF0IEFuYWhpdGEifSx7ImFjY2VwdGVkX2ZpcnN0X2xpbmUiOiLinIjvuI8g0KLRg9GA0Ysg0LIgRm91ciBTZWFzb25zIFJlc29ydCBTZXljaGVsbGVzIiwiaG90ZWxfaWQiOjExODQyLCJpbWFnZV9zaGEyNTYiOiIwZjM3Y2MxYTZhMTdmZWM1ZDllNWQ0ZTI2MzVmMjBmYTMxNGMyYmMzZDExMjlkZTQ4NmIyZjIwMWRjNzA4ODhhIiwiaW1hZ2VfdXJsIjoiaHR0cHM6Ly9yYXcuZ2l0aHVidXNlcmNvbnRlbnQuY29tL3B5YXRrb2ZmL21heC1zZWFyY2gtYm90L2E4YWQ0NDBjNjkxMGZhNGFmMGJhNTMzN2Q3NTlkYmU1NjIwYzEzNzcvLmdpdGh1Yi90cmFmZmljLXB1Ymxpc2gvY29tbWVyY2lhbC13YXZlNi1maXJzdDEwLTIwMjYxMTE3LzExODQyLnBuZyIsIm5hbWUiOiJGb3VyIFNlYXNvbnMgUmVzb3J0IFNleWNoZWxsZXMifSx7ImFjY2VwdGVkX2ZpcnN0X2xpbmUiOiLinIjvuI8g0KLRg9GA0Ysg0LIgUmFmZmxlcyBTZXljaGVsbGVzIiwiaG90ZWxfaWQiOjExODgyLCJpbWFnZV9zaGEyNTYiOiJiOTdkNjBlMjFhMDYzZGJlOGZkNjRhZDY4MWE5YWUzMzliZmU0YmE5NTdlZjAzMzBiY2I0YTIxM2Y1OGRjYzQ1IiwiaW1hZ2VfdXJsIjoiaHR0cHM6Ly9yYXcuZ2l0aHVidXNlcmNvbnRlbnQuY29tL3B5YXRrb2ZmL21heC1zZWFyY2gtYm90L2E4YWQ0NDBjNjkxMGZhNGFmMGJhNTMzN2Q3NTlkYmU1NjIwYzEzNzcvLmdpdGh1Yi90cmFmZmljLXB1Ymxpc2gvY29tbWVyY2lhbC13YXZlNi1maXJzdDEwLTIwMjYxMTE3LzExODgyLnBuZyIsIm5hbWUiOiJSYWZmbGVzIFNleWNoZWxsZXMifSx7ImFjY2VwdGVkX2ZpcnN0X2xpbmUiOiLinIjvuI8g0KLRg9GA0Ysg0LIgVGhlIFJpdHotQ2FybHRvbiwgRHViYWkgSW50ZXJuYXRpb25hbCBGaW5hbmNpYWwgQ2VudHJlIiwiaG90ZWxfaWQiOjg5ODcsImltYWdlX3NoYTI1NiI6IjliOWRlNzA5NTFjYmRhNjU2YTYyZGJiMDU0NjgxNzY5M2Q0ZGYwM2E4OTZkNzQ1NTYzMDdjOGQyNTBlZDE2ZWQiLCJpbWFnZV91cmwiOiJodHRwczovL3Jhdy5naXRodWJ1c2VyY29udGVudC5jb20vcHlhdGtvZmYvbWF4LXNlYXJjaC1ib3QvYThhZDQ0MGM2OTEwZmE0YWYwYmE1MzM3ZDc1OWRiZTU2MjBjMTM3Ny8uZ2l0aHViL3RyYWZmaWMtcHVibGlzaC9jb21tZXJjaWFsLXdhdmU2LWZpcnN0MTAtMjAyNjExMTcvODk4Ny5wbmciLCJuYW1lIjoiVGhlIFJpdHotQ2FybHRvbiwgRHViYWkgSW50ZXJuYXRpb25hbCBGaW5hbmNpYWwgQ2VudHJlIn0seyJhY2NlcHRlZF9maXJzdF9saW5lIjoi4pyI77iPINCi0YPRgNGLINCyIFRoZSBSaXR6LUNhcmx0b24sIER1YmFpIiwiaG90ZWxfaWQiOjI2ODUsImltYWdlX3NoYTI1NiI6IjcyMWRkZGEzNTczYjM2NWNkNzdlMjc1NWEyMzFiYWU0OWFiY2FjYmNhYjNhMjkwYmE2MGYyMDhjNTgyMDEwOWUiLCJpbWFnZV91cmwiOiJodHRwczovL3Jhdy5naXRodWJ1c2VyY29udGVudC5jb20vcHlhdGtvZmYvbWF4LXNlYXJjaC1ib3QvYThhZDQ0MGM2OTEwZmE0YWYwYmE1MzM3ZDc1OWRiZTU2MjBjMTM3Ny8uZ2l0aHViL3RyYWZmaWMtcHVibGlzaC9jb21tZXJjaWFsLXdhdmU2LWZpcnN0MTAtMjAyNjExMTcvMjY4NS5wbmciLCJuYW1lIjoiVGhlIFJpdHotQ2FybHRvbiwgRHViYWkifSx7ImFjY2VwdGVkX2ZpcnN0X2xpbmUiOiLinIjvuI8g0KLRg9GA0Ysg0LIgVGhlIFJpdHotQ2FybHRvbiBSYXMgQWwgS2hhaW1haCwgQWwgV2FkaSBEZXNlcnQiLCJob3RlbF9pZCI6ODg1OSwiaW1hZ2Vfc2hhMjU2IjoiZTQ4YmIxZGFiOWY5MGQ5Y2E4MjA1ZDc2M2NjNDIzYzM4YWVkZTU4MWEyYmQxOTZlYzU0MThmYjViNjJmZmZhYyIsImltYWdlX3VybCI6Imh0dHBzOi8vcmF3LmdpdGh1YnVzZXJjb250ZW50LmNvbS9weWF0a29mZi9tYXgtc2VhcmNoLWJvdC9hOGFkNDQwYzY5MTBmYTRhZjBiYTUzMzdkNzU5ZGJlNTYyMGMxMzc3Ly5naXRodWIvdHJhZmZpYy1wdWJsaXNoL2NvbW1lcmNpYWwtd2F2ZTYtZmlyc3QxMC0yMDI2MTExNy84ODU5LnBuZyIsIm5hbWUiOiJUaGUgUml0ei1DYXJsdG9uIFJhcyBBbCBLaGFpbWFoLCBBbCBXYWRpIERlc2VydCJ9LHsiYWNjZXB0ZWRfZmlyc3RfbGluZSI6IuKciO+4jyDQotGD0YDRiyDQsiBLYXl1bWFuaXMgTnVzYSBEdWEgUHJpdmF0ZSBWaWxsYSAmIFNwYSIsImhvdGVsX2lkIjoyMzM1LCJpbWFnZV9zaGEyNTYiOiI1ZDg0Zjk5Mjk1MjRiZDU5ZmNmMDg3OWNjYTExMzM4YjllNTZiNjk3ZmU2MTUzODU4MGNiMzMwODgxODFhZWU3IiwiaW1hZ2VfdXJsIjoiaHR0cHM6Ly9yYXcuZ2l0aHVidXNlcmNvbnRlbnQuY29tL3B5YXRrb2ZmL21heC1zZWFyY2gtYm90L2E4YWQ0NDBjNjkxMGZhNGFmMGJhNTMzN2Q3NTlkYmU1NjIwYzEzNzcvLmdpdGh1Yi90cmFmZmljLXB1Ymxpc2gvY29tbWVyY2lhbC13YXZlNi1maXJzdDEwLTIwMjYxMTE3LzIzMzUucG5nIiwibmFtZSI6IktheXVtYW5pcyBOdXNhIER1YSBQcml2YXRlIFZpbGxhICYgU3BhIn0seyJhY2NlcHRlZF9maXJzdF9saW5lIjoi4pyI77iPINCi0YPRgNGLINCyIEp1bWVpcmFoIE9saGFoYWxpIElzbGFuZCIsImhvdGVsX2lkIjo1ODc0NCwiaW1hZ2Vfc2hhMjU2IjoiYjRlNGNkNjA2YjMxNjg1NThmYWEyYTJiMzU4MTljNWUwYjRjZjhjYTczMGRiZDZlZmJmNjQxYzkzNjNhNTRhYiIsImltYWdlX3VybCI6Imh0dHBzOi8vcmF3LmdpdGh1YnVzZXJjb250ZW50LmNvbS9weWF0a29mZi9tYXgtc2VhcmNoLWJvdC9hOGFkNDQwYzY5MTBmYTRhZjBiYTUzMzdkNzU5ZGJlNTYyMGMxMzc3Ly5naXRodWIvdHJhZmZpYy1wdWJsaXNoL2NvbW1lcmNpYWwtd2F2ZTYtZmlyc3QxMC0yMDI2MTExNy81ODc0NC5wbmciLCJuYW1lIjoiSnVtZWlyYWggT2xoYWhhbGkgSXNsYW5kIn1d';
const FEED_EXPORT_INPUT_SHA256='be5c760f56f85cc00b92a6a5971597bb8441909f1ca3fab15c03651ebddafc12';
const CANDIDATE_INPUT_B64='W10=';
const CANDIDATE_INPUT_SHA256='4f53cda18c2baa0c0354bb5f9a3ecbe5ed12ab4d8e11ba873c2f11161202b945';
ini_set('display_errors','0');ini_set('log_errors','0');
$out=['operation'=>'commercial-channel-hotel-feeds-20261118-next174-read1','mode'=>'read_bounded_public_channel_post_feed_inputs','ok'=>false,'db_writes'=>0,'provider_calls'=>0,'message_sends'=>0];
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
 insist(count($in)===10&&array_column($in,'hotel_id')===[108317,11836,11746,11842,11882,8987,2685,8859,2335,58744],'read17_exact_new1_scope');
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
        "integrations/ChannelPostApiTransport.php"=>"704c1587e59a5cff6a33c656296af0cb29b4bfd5",
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
        "services/ChannelPostReconciliationService.php"=>"1fa260e9720a8421227ecbe740bfbe33a42e398d",
        "services/ChannelPostReconciliationState.php"=>"d9583ceca80d1424a44ade3561c21282a23a52f0",
        "services/ChannelPostRegistryService.php"=>"cf42a44257d1a58d1ff516678615cf9d01f45b55",
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
    $retained=json_decode(base64_decode('W3siYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiOTA2ZWJiMzFiYjk5YjllMTMyNGQxZGRkYmQxNDNmZjA0MDk4NjQyN2IxMmFhMDY0NzNlYjI4YWQzMWY5ZTk1MCIsImNoYW5uZWxfaWQiOiItMTAwMjI2MjY2NjYxNiIsImhvdGVsX2lkIjoxMDgzMTcsIm1lc3NhZ2VfaWQiOiIxMDE1IiwicGhvdG9fc2hhMjU2IjoiYWI1MjU3YTgyOTcwY2E3ZDFmMGFlMjMyODQwZmEyMjhlZTQzNDllZWU5ODk5Njc0YmMzOGFmZjZhMDE1NWMyYiIsInBsYXRmb3JtIjoidGVsZWdyYW0iLCJwb3N0X3VybCI6Imh0dHBzOi8vdC5tZS9hbnlfdG9vdXIvMTAxNSIsInB1YmxpY2F0aW9uX2lkIjoyMTg0LCJ0ZXh0X3NoYTI1NiI6ImRjMGJkOTljM2IzYTgxOGUyODI0OGYzZTkzYmJhOTdjODY4NzY1Yjc0ZTgyZjc4ZDkyMWYzYWMwYmE5MWE5Y2EiLCJ2ZXJzaW9uX2lkIjo3NzEzfSx7ImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6ImJhZDgyMDc3ZmRhYWJmZjUxNTMzNWFiMjM5YmZjZGQ1NjFiMjIwNDExMjVkY2Q5N2JjYmJmMzcwZmY2M2E4MWUiLCJjaGFubmVsX2lkIjoiLTgwNDkyMjQ0MjA5NjgzIiwiaG90ZWxfaWQiOjEwODMxNywibWVzc2FnZV9pZCI6Im1pZC5mZmZmYjZjYWYwNjA2ZmVkMDFhMWViNzYzOTZlMDE1NSIsInBob3RvX3NoYTI1NiI6ImFiNTI1N2E4Mjk3MGNhN2QxZjBhZTIzMjg0MGZhMjI4ZWU0MzQ5ZWVlOTg5OTY3NGJjMzhhZmY2YTAxNTVjMmIiLCJwbGF0Zm9ybSI6Im1heCIsInBvc3RfdXJsIjoiaHR0cHM6Ly9tYXgucnUvY2hhbm5lbF9hbnl0b3VyL0FhSHJkamx1QVZVIiwicHVibGljYXRpb25faWQiOjIxOTQsInRleHRfc2hhMjU2IjoiZGMwYmQ5OWMzYjNhODE4ZTI4MjQ4ZjNlOTNiYmE5N2M4Njg3NjViNzRlODJmNzhkOTIxZjNhYzBiYTkxYTljYSIsInZlcnNpb25faWQiOjc3MjN9LHsiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiYzc2MTRkMGNjNGM1ODg3MjUxZDA0N2JiMWFhNTc4MDM3NzVkNzFmZmRjY2U0ZDkwZDJjMmMwN2YzZTkxOTUyMSIsImNoYW5uZWxfaWQiOiItMTAwMjI2MjY2NjYxNiIsImhvdGVsX2lkIjoxMTgzNiwibWVzc2FnZV9pZCI6IjEwMTYiLCJwaG90b19zaGEyNTYiOiIwZjllMzI1MGU0OTdiYTU2M2I5NTc3MDQyNjUwZDhhZWJjZjA1MzVlMzJhYTY3Yzk0YzgxYTgzNjJiMmRlZmFlIiwicGxhdGZvcm0iOiJ0ZWxlZ3JhbSIsInBvc3RfdXJsIjoiaHR0cHM6Ly90Lm1lL2FueV90b291ci8xMDE2IiwicHVibGljYXRpb25faWQiOjIxODUsInRleHRfc2hhMjU2IjoiNTg5NjA1MTg0NjZmY2UzMDMyYzNhZWNkMWM0YzEzM2ZmNWYwOWZjZDU1M2FkMWE5OGQxODYwZDlkYTJjYmM3NSIsInZlcnNpb25faWQiOjc3MTR9LHsiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiNWRjNjIzNWQ1ZGY0MzU3OTI1YzA3M2Y2MWMyNzViOGFmYWI5ZWU3ZTk2ZjQzNDc1MjgyYzQxMTFjM2U3MzA0OCIsImNoYW5uZWxfaWQiOiItODA0OTIyNDQyMDk2ODMiLCJob3RlbF9pZCI6MTE4MzYsIm1lc3NhZ2VfaWQiOiJtaWQuZmZmZmI2Y2FmMDYwNmZlZDAxYTFlYjc2NDJiOTZkZTkiLCJwaG90b19zaGEyNTYiOiIwZjllMzI1MGU0OTdiYTU2M2I5NTc3MDQyNjUwZDhhZWJjZjA1MzVlMzJhYTY3Yzk0YzgxYTgzNjJiMmRlZmFlIiwicGxhdGZvcm0iOiJtYXgiLCJwb3N0X3VybCI6Imh0dHBzOi8vbWF4LnJ1L2NoYW5uZWxfYW55dG91ci9BYUhyZGtLNWJlayIsInB1YmxpY2F0aW9uX2lkIjoyMTk1LCJ0ZXh0X3NoYTI1NiI6IjU4OTYwNTE4NDY2ZmNlMzAzMmMzYWVjZDFjNGMxMzNmZjVmMDlmY2Q1NTNhZDFhOThkMTg2MGQ5ZGEyY2JjNzUiLCJ2ZXJzaW9uX2lkIjo3NzI0fSx7ImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6IjVhZDNjZWQ4OGE0OWRiZDcyNTgzZmJlM2Y1MjE2MWUzZDkwYWNhYjM1Zjg1MTZhYWQyMGQ3YzdjYmY2YTQ5ZWMiLCJjaGFubmVsX2lkIjoiLTEwMDIyNjI2NjY2MTYiLCJob3RlbF9pZCI6MTE3NDYsIm1lc3NhZ2VfaWQiOiIxMDE3IiwicGhvdG9fc2hhMjU2IjoiYWFjMzFjMTk4OTIyZDgzN2M1MjBiMzlhOWI2NWE2MGQ5NzA3Y2QyMTQ2ODk4N2E4NzAzNzNjOWJhZDM0MGNiZSIsInBsYXRmb3JtIjoidGVsZWdyYW0iLCJwb3N0X3VybCI6Imh0dHBzOi8vdC5tZS9hbnlfdG9vdXIvMTAxNyIsInB1YmxpY2F0aW9uX2lkIjoyMTg2LCJ0ZXh0X3NoYTI1NiI6ImIwZjAyYjAxZTBmNjBiNGM0MzYxODk5YWFjNzMzNDJjNDhjOWNmNzRhNmE1NWE2ZDg2YTRjNTlmNmFjZDkxN2EiLCJ2ZXJzaW9uX2lkIjo3NzE1fSx7ImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6IjU5ZTFjNTY1Mzc1OWM2MjIyYzBjY2YxZDhlYzc5YmFhYmY0YTcyYjc5YWVlMWIxYTY4ZTM1ZmY1MDMyNzFkMWUiLCJjaGFubmVsX2lkIjoiLTgwNDkyMjQ0MjA5NjgzIiwiaG90ZWxfaWQiOjExNzQ2LCJtZXNzYWdlX2lkIjoibWlkLmZmZmZiNmNhZjA2MDZmZWQwMWExZWI3NjRjM2Y0MDk0IiwicGhvdG9fc2hhMjU2IjoiYWFjMzFjMTk4OTIyZDgzN2M1MjBiMzlhOWI2NWE2MGQ5NzA3Y2QyMTQ2ODk4N2E4NzAzNzNjOWJhZDM0MGNiZSIsInBsYXRmb3JtIjoibWF4IiwicG9zdF91cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FueXRvdXIvQWFIcmRrd19RSlEiLCJwdWJsaWNhdGlvbl9pZCI6MjE5NiwidGV4dF9zaGEyNTYiOiJiMGYwMmIwMWUwZjYwYjRjNDM2MTg5OWFhYzczMzQyYzQ4YzljZjc0YTZhNTVhNmQ4NmE0YzU5ZjZhY2Q5MTdhIiwidmVyc2lvbl9pZCI6NzcyNX0seyJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiIzOTlhOGY5MmE0N2JjMTEwMmQ1OTc3YzBkNjgzMjIwZWQyOTYwMjA0MmUxM2NiMDU4Y2NjYjQzMjllM2U1NTg5IiwiY2hhbm5lbF9pZCI6Ii0xMDAyMjYyNjY2NjE2IiwiaG90ZWxfaWQiOjExODQyLCJtZXNzYWdlX2lkIjoiMTAxOCIsInBob3RvX3NoYTI1NiI6IjBmMzdjYzFhNmExN2ZlYzVkOWU1ZDRlMjYzNWYyMGZhMzE0YzJiYzNkMTEyOWRlNDg2YjJmMjAxZGM3MDg4OGEiLCJwbGF0Zm9ybSI6InRlbGVncmFtIiwicG9zdF91cmwiOiJodHRwczovL3QubWUvYW55X3Rvb3VyLzEwMTgiLCJwdWJsaWNhdGlvbl9pZCI6MjE4NywidGV4dF9zaGEyNTYiOiI4ZGZjMzM2YTA1OTBmYmE1ODU5YWFmZTlmZDY3MTVkYTA0NjM4YWQwOGI0OTJjNjljODdlMWFmNzkyMmRmZGVlIiwidmVyc2lvbl9pZCI6NzcxNn0seyJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiJhYTQ0YWE3YjNlZjgyYTQzNjU3YThmNWYwOTkyZTE1NDQzNDRkYjczOWU2YjUwMTdkZDY3ZjFjNWM0MWQyMWYxIiwiY2hhbm5lbF9pZCI6Ii04MDQ5MjI0NDIwOTY4MyIsImhvdGVsX2lkIjoxMTg0MiwibWVzc2FnZV9pZCI6Im1pZC5mZmZmYjZjYWYwNjA2ZmVkMDFhMWViNzY1NWEwNzFiOSIsInBob3RvX3NoYTI1NiI6IjBmMzdjYzFhNmExN2ZlYzVkOWU1ZDRlMjYzNWYyMGZhMzE0YzJiYzNkMTEyOWRlNDg2YjJmMjAxZGM3MDg4OGEiLCJwbGF0Zm9ybSI6Im1heCIsInBvc3RfdXJsIjoiaHR0cHM6Ly9tYXgucnUvY2hhbm5lbF9hbnl0b3VyL0FhSHJkbFdnY2JrIiwicHVibGljYXRpb25faWQiOjIxOTcsInRleHRfc2hhMjU2IjoiOGRmYzMzNmEwNTkwZmJhNTg1OWFhZmU5ZmQ2NzE1ZGEwNDYzOGFkMDhiNDkyYzY5Yzg3ZTFhZjc5MjJkZmRlZSIsInZlcnNpb25faWQiOjc3MjZ9LHsiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiYjRlZjk0YTgxMmQ0MzgwNjZlMmQ1ZDhjNGJhMWYzNzAxMzJmYTMyZDA0YTdiMGQ2MTdkYzAzNzA0OWZlYjgyMyIsImNoYW5uZWxfaWQiOiItMTAwMjI2MjY2NjYxNiIsImhvdGVsX2lkIjoxMTg4MiwibWVzc2FnZV9pZCI6IjEwMTkiLCJwaG90b19zaGEyNTYiOiJiOTdkNjBlMjFhMDYzZGJlOGZkNjRhZDY4MWE5YWUzMzliZmU0YmE5NTdlZjAzMzBiY2I0YTIxM2Y1OGRjYzQ1IiwicGxhdGZvcm0iOiJ0ZWxlZ3JhbSIsInBvc3RfdXJsIjoiaHR0cHM6Ly90Lm1lL2FueV90b291ci8xMDE5IiwicHVibGljYXRpb25faWQiOjIxODgsInRleHRfc2hhMjU2IjoiM2ZmNmZmODcxZjNlOTQ2MWJmNTZiMjkzMDQ4Y2I4ODBhMTgxMmQyOTI1NzFkOTlmMWYwYmI4NjcyZTJhNzhjZSIsInZlcnNpb25faWQiOjc3MTd9LHsiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiMTNiMzhmMzcxNGE3OGY0M2U0OTY4ODA2MjQ2ZWQyMjA4ZGQzYzUwMzQzMDgzZGYzNTk0NGZhNWZkMzM0N2IwNCIsImNoYW5uZWxfaWQiOiItODA0OTIyNDQyMDk2ODMiLCJob3RlbF9pZCI6MTE4ODIsIm1lc3NhZ2VfaWQiOiJtaWQuZmZmZmI2Y2FmMDYwNmZlZDAxYTFlYjc2NWVkZDFiM2YiLCJwaG90b19zaGEyNTYiOiJiOTdkNjBlMjFhMDYzZGJlOGZkNjRhZDY4MWE5YWUzMzliZmU0YmE5NTdlZjAzMzBiY2I0YTIxM2Y1OGRjYzQ1IiwicGxhdGZvcm0iOiJtYXgiLCJwb3N0X3VybCI6Imh0dHBzOi8vbWF4LnJ1L2NoYW5uZWxfYW55dG91ci9BYUhyZGw3ZEd6OCIsInB1YmxpY2F0aW9uX2lkIjoyMTk4LCJ0ZXh0X3NoYTI1NiI6IjNmZjZmZjg3MWYzZTk0NjFiZjU2YjI5MzA0OGNiODgwYTE4MTJkMjkyNTcxZDk5ZjFmMGJiODY3MmUyYTc4Y2UiLCJ2ZXJzaW9uX2lkIjo3NzI3fSx7ImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6ImZjNzUzZmMxMzVkYTI2N2MwYzg2NTE5YTQ1NjNjNDVhNTNiODRhNjczYWYwNmUwZjZlNWFlYjczYjQxZDk3ZTEiLCJjaGFubmVsX2lkIjoiLTEwMDIyNjI2NjY2MTYiLCJob3RlbF9pZCI6ODk4NywibWVzc2FnZV9pZCI6IjEwMjAiLCJwaG90b19zaGEyNTYiOiI5YjlkZTcwOTUxY2JkYTY1NmE2MmRiYjA1NDY4MTc2OTNkNGRmMDNhODk2ZDc0NTU2MzA3YzhkMjUwZWQxNmVkIiwicGxhdGZvcm0iOiJ0ZWxlZ3JhbSIsInBvc3RfdXJsIjoiaHR0cHM6Ly90Lm1lL2FueV90b291ci8xMDIwIiwicHVibGljYXRpb25faWQiOjIxODksInRleHRfc2hhMjU2IjoiY2I1MjcxZTQyMjE1Y2U4YmVkMjU2Y2UzODU2NmI4OWI0YWM0OWU1ODc1OGFlZTBhNzg2ZDZlYjBlNjhiZDlmZiIsInZlcnNpb25faWQiOjc3MTh9LHsiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiOGFhZWVmN2RkZWQ4MjQ5Nzg4NmVmZmFlMjEyZDQwOTMyMjFkNTczNDgwYjdjZjViMTcxNTIxODQ5ZDUyYTNmYyIsImNoYW5uZWxfaWQiOiItODA0OTIyNDQyMDk2ODMiLCJob3RlbF9pZCI6ODk4NywibWVzc2FnZV9pZCI6Im1pZC5mZmZmYjZjYWYwNjA2ZmVkMDFhMWViNzY2ODhiMzM3NyIsInBob3RvX3NoYTI1NiI6IjliOWRlNzA5NTFjYmRhNjU2YTYyZGJiMDU0NjgxNzY5M2Q0ZGYwM2E4OTZkNzQ1NTYzMDdjOGQyNTBlZDE2ZWQiLCJwbGF0Zm9ybSI6Im1heCIsInBvc3RfdXJsIjoiaHR0cHM6Ly9tYXgucnUvY2hhbm5lbF9hbnl0b3VyL0FhSHJkbWlMTTNjIiwicHVibGljYXRpb25faWQiOjIxOTksInRleHRfc2hhMjU2IjoiY2I1MjcxZTQyMjE1Y2U4YmVkMjU2Y2UzODU2NmI4OWI0YWM0OWU1ODc1OGFlZTBhNzg2ZDZlYjBlNjhiZDlmZiIsInZlcnNpb25faWQiOjc3Mjh9LHsiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiOTI5NDk2OTVlZDA0MjJkMWI5MjZiYmQ2NDQ4Mzk4YmMxMWJiN2Q1NzRmNDA1ZmQ1NmNkYTEyMWJmYmI2ZTYxZSIsImNoYW5uZWxfaWQiOiItMTAwMjI2MjY2NjYxNiIsImhvdGVsX2lkIjoyNjg1LCJtZXNzYWdlX2lkIjoiMTAyMSIsInBob3RvX3NoYTI1NiI6IjcyMWRkZGEzNTczYjM2NWNkNzdlMjc1NWEyMzFiYWU0OWFiY2FjYmNhYjNhMjkwYmE2MGYyMDhjNTgyMDEwOWUiLCJwbGF0Zm9ybSI6InRlbGVncmFtIiwicG9zdF91cmwiOiJodHRwczovL3QubWUvYW55X3Rvb3VyLzEwMjEiLCJwdWJsaWNhdGlvbl9pZCI6MjE5MCwidGV4dF9zaGEyNTYiOiI3MGYyM2M4ODhmYWU1NmM3NzVkMWFlN2FiMWY2OTM1N2ZjMjQ5N2JiZjIyZDdmMGVjN2ExZWVhZTllMTdiZDY1IiwidmVyc2lvbl9pZCI6NzcxOX0seyJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiJjYmI5NTQ4MmE4YjA4ZDc5MzBkNzkzOTc3OWE4NTE5ZjEzODRiYTg4MjVhMTExNDU0YjZhZTQ2ZmRkOTNhZjFmIiwiY2hhbm5lbF9pZCI6Ii04MDQ5MjI0NDIwOTY4MyIsImhvdGVsX2lkIjoyNjg1LCJtZXNzYWdlX2lkIjoibWlkLmZmZmZiNmNhZjA2MDZmZWQwMWExZWI3NjcxZjI2MTY0IiwicGhvdG9fc2hhMjU2IjoiNzIxZGRkYTM1NzNiMzY1Y2Q3N2UyNzU1YTIzMWJhZTQ5YWJjYWNiY2FiM2EyOTBiYTYwZjIwOGM1ODIwMTA5ZSIsInBsYXRmb3JtIjoibWF4IiwicG9zdF91cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FueXRvdXIvQWFIcmRuSHlZV1EiLCJwdWJsaWNhdGlvbl9pZCI6MjIwMCwidGV4dF9zaGEyNTYiOiI3MGYyM2M4ODhmYWU1NmM3NzVkMWFlN2FiMWY2OTM1N2ZjMjQ5N2JiZjIyZDdmMGVjN2ExZWVhZTllMTdiZDY1IiwidmVyc2lvbl9pZCI6NzcyOX0seyJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiJlY2E5MTk4OGI5MGU4YjYzZWVhNTk2YTk5YzYxNGNhMTNlMWM3ODdmMjY3NjY3YjFkZWVhMzIyOGFjZmEwYjZkIiwiY2hhbm5lbF9pZCI6Ii0xMDAyMjYyNjY2NjE2IiwiaG90ZWxfaWQiOjg4NTksIm1lc3NhZ2VfaWQiOiIxMDIyIiwicGhvdG9fc2hhMjU2IjoiZTQ4YmIxZGFiOWY5MGQ5Y2E4MjA1ZDc2M2NjNDIzYzM4YWVkZTU4MWEyYmQxOTZlYzU0MThmYjViNjJmZmZhYyIsInBsYXRmb3JtIjoidGVsZWdyYW0iLCJwb3N0X3VybCI6Imh0dHBzOi8vdC5tZS9hbnlfdG9vdXIvMTAyMiIsInB1YmxpY2F0aW9uX2lkIjoyMTkxLCJ0ZXh0X3NoYTI1NiI6IjNlMTdkMzYyMTVmMjZmNDk5ODBhN2Q1MTgwODdjZGNmNjZkMTVjMDAzNjM4OTgxMTY3MjJkNzVhNmNjZjRlMDgiLCJ2ZXJzaW9uX2lkIjo3NzIwfSx7ImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6IjU2NmVlZDUyZWQzNDM1YmY3Y2I2ZWRiZjczNjczNmRhOGM0YjI3YmZmMTZhNjQ1M2M0ZDQzNTI2OTBjMmQwZGMiLCJjaGFubmVsX2lkIjoiLTgwNDkyMjQ0MjA5NjgzIiwiaG90ZWxfaWQiOjg4NTksIm1lc3NhZ2VfaWQiOiJtaWQuZmZmZmI2Y2FmMDYwNmZlZDAxYTFlYjc2N2QyNTc3ZTMiLCJwaG90b19zaGEyNTYiOiJlNDhiYjFkYWI5ZjkwZDljYTgyMDVkNzYzY2M0MjNjMzhhZWRlNTgxYTJiZDE5NmVjNTQxOGZiNWI2MmZmZmFjIiwicGxhdGZvcm0iOiJtYXgiLCJwb3N0X3VybCI6Imh0dHBzOi8vbWF4LnJ1L2NoYW5uZWxfYW55dG91ci9BYUhyZG4wbGQtTSIsInB1YmxpY2F0aW9uX2lkIjoyMjAxLCJ0ZXh0X3NoYTI1NiI6IjNlMTdkMzYyMTVmMjZmNDk5ODBhN2Q1MTgwODdjZGNmNjZkMTVjMDAzNjM4OTgxMTY3MjJkNzVhNmNjZjRlMDgiLCJ2ZXJzaW9uX2lkIjo3NzMwfSx7ImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6IjM4Y2E5M2IwNjQ1NDM2NGY1NDQ2NGMyOWVlNTYyZWIyNjJjYWEwYWM0NDhiMjAzM2IwZDdkYmQzMTY1N2RmZTkiLCJjaGFubmVsX2lkIjoiLTEwMDIyNjI2NjY2MTYiLCJob3RlbF9pZCI6MjMzNSwibWVzc2FnZV9pZCI6IjEwMjMiLCJwaG90b19zaGEyNTYiOiI1ZDg0Zjk5Mjk1MjRiZDU5ZmNmMDg3OWNjYTExMzM4YjllNTZiNjk3ZmU2MTUzODU4MGNiMzMwODgxODFhZWU3IiwicGxhdGZvcm0iOiJ0ZWxlZ3JhbSIsInBvc3RfdXJsIjoiaHR0cHM6Ly90Lm1lL2FueV90b291ci8xMDIzIiwicHVibGljYXRpb25faWQiOjIxOTIsInRleHRfc2hhMjU2IjoiMDdiNWE2OGQ3OWEzYjUzMmNkY2NlM2U3NjY0MTJkZDUxODk0Y2Q3ODZjZTU5MjQ3MDdiZmQ2OTI1MDFjY2MwZCIsInZlcnNpb25faWQiOjc3MjF9LHsiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiNGY3ZmNlMTE3MDU3NTRkMzEzNTgxMDdhMDk2YTNlYjFkODBiZTFkZGU2MjQyMDZiYjJhNDI5ZGUzODgwNjVhOCIsImNoYW5uZWxfaWQiOiItODA0OTIyNDQyMDk2ODMiLCJob3RlbF9pZCI6MjMzNSwibWVzc2FnZV9pZCI6Im1pZC5mZmZmYjZjYWYwNjA2ZmVkMDFhMWViNzY4NmE5NTEyZSIsInBob3RvX3NoYTI1NiI6IjVkODRmOTkyOTUyNGJkNTlmY2YwODc5Y2NhMTEzMzhiOWU1NmI2OTdmZTYxNTM4NTgwY2IzMzA4ODE4MWFlZTciLCJwbGF0Zm9ybSI6Im1heCIsInBvc3RfdXJsIjoiaHR0cHM6Ly9tYXgucnUvY2hhbm5lbF9hbnl0b3VyL0FhSHJkb2FwVVM0IiwicHVibGljYXRpb25faWQiOjIyMDIsInRleHRfc2hhMjU2IjoiMDdiNWE2OGQ3OWEzYjUzMmNkY2NlM2U3NjY0MTJkZDUxODk0Y2Q3ODZjZTU5MjQ3MDdiZmQ2OTI1MDFjY2MwZCIsInZlcnNpb25faWQiOjc3MzF9LHsiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiNjE3YmZjYmUyOTQxM2U4YTNhZmZmMTA2NDkzMzliZDRlODdjZjNlN2M2ZWQwN2RjM2RlM2JjY2M2NGYwMWE2MSIsImNoYW5uZWxfaWQiOiItMTAwMjI2MjY2NjYxNiIsImhvdGVsX2lkIjo1ODc0NCwibWVzc2FnZV9pZCI6IjEwMjQiLCJwaG90b19zaGEyNTYiOiJiNGU0Y2Q2MDZiMzE2ODU1OGZhYTJhMmIzNTgxOWM1ZTBiNGNmOGNhNzMwZGJkNmVmYmY2NDFjOTM2M2E1NGFiIiwicGxhdGZvcm0iOiJ0ZWxlZ3JhbSIsInBvc3RfdXJsIjoiaHR0cHM6Ly90Lm1lL2FueV90b291ci8xMDI0IiwicHVibGljYXRpb25faWQiOjIxOTMsInRleHRfc2hhMjU2IjoiYzMwYjA1NWRkMjVhZjAwY2UwZDkyZmI2NTIwODU2M2E3YTIwNGZkYjVhOWU1MjgyYTQwNzlmZDE4MmE1ODU5MSIsInZlcnNpb25faWQiOjc3MjJ9LHsiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiNzlhY2VjZWI3ZmY5MDI3ZTkzYTRiMzk1ZmMwNTNhM2VjNDI4MTI5YzVkNzUwOTEwNTFlNjQ4NTFhN2FkZjYyYSIsImNoYW5uZWxfaWQiOiItODA0OTIyNDQyMDk2ODMiLCJob3RlbF9pZCI6NTg3NDQsIm1lc3NhZ2VfaWQiOiJtaWQuZmZmZmI2Y2FmMDYwNmZlZDAxYTFlYjc2OTA0ZTdjMzciLCJwaG90b19zaGEyNTYiOiJiNGU0Y2Q2MDZiMzE2ODU1OGZhYTJhMmIzNTgxOWM1ZTBiNGNmOGNhNzMwZGJkNmVmYmY2NDFjOTM2M2E1NGFiIiwicGxhdGZvcm0iOiJtYXgiLCJwb3N0X3VybCI6Imh0dHBzOi8vbWF4LnJ1L2NoYW5uZWxfYW55dG91ci9BYUhyZHBCT2ZEYyIsInB1YmxpY2F0aW9uX2lkIjoyMjAzLCJ0ZXh0X3NoYTI1NiI6ImMzMGIwNTVkZDI1YWYwMGNlMGQ5MmZiNjUyMDg1NjNhN2EyMDRmZGI1YTllNTI4MmE0MDc5ZmQxODJhNTg1OTEiLCJ2ZXJzaW9uX2lkIjo3NzMyfV0=',true),true,64,JSON_THROW_ON_ERROR);
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

