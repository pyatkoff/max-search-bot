<?php
declare(strict_types=1);
const EXPECTED_INSTALLED_SHA='cc017ee5bb669f4c7c13efc91d5c5812a50e55f2';
const FEED_EXPORT_INPUT_B64='W3siYWNjZXB0ZWRfZmlyc3RfbGluZSI6IuKciO+4jyDQotGD0YDRiyDQsiBNZWxpw6EgVmlucGVhcmwgUGh1IFF1b2MiLCJob3RlbF9pZCI6ODIwNjcsImltYWdlX3NoYTI1NiI6ImQxN2M0ODEwMzRhZmYzYWMzMzQ3Y2Y5YjlkNWRhNDUxZDMzNzA0ZWY1M2ZjZmIxNzAxMWVhN2RlMTA0ZjdjNWEiLCJpbWFnZV91cmwiOiJodHRwczovL3Jhdy5naXRodWJ1c2VyY29udGVudC5jb20vcHlhdGtvZmYvbWF4LXNlYXJjaC1ib3QvZTUzZWVkZTJkZjVkYmM1ZGE1ZjcyYjA0YzBhYzg3Nzc4MTljZDhkYy8uZ2l0aHViL3RyYWZmaWMtcHVibGlzaC9jb21tZXJjaWFsLXdhdmU0LTEwMC0yMDI2MTExNy84MjA2Ny5wbmciLCJuYW1lIjoiTWVsacOhIFZpbnBlYXJsIFBodSBRdW9jIn0seyJhY2NlcHRlZF9maXJzdF9saW5lIjoi4pyI77iPINCi0YPRgNGLINCyIFNoZXJhdG9uIE5oYSBUcmFuZyBIb3RlbCAmIFNwYSIsImhvdGVsX2lkIjo0MDc2LCJpbWFnZV9zaGEyNTYiOiIyY2JlN2YwMmY5ZjkyZDdlMDMzOTFiOGY2OWEwMjhkMDAwZTdhMjgzNmQwYzA1OTA3NmRkZjQ5YTUyYTZjMGMyIiwiaW1hZ2VfdXJsIjoiaHR0cHM6Ly9yYXcuZ2l0aHVidXNlcmNvbnRlbnQuY29tL3B5YXRrb2ZmL21heC1zZWFyY2gtYm90L2U1M2VlZGUyZGY1ZGJjNWRhNWY3MmIwNGMwYWM4Nzc3ODE5Y2Q4ZGMvLmdpdGh1Yi90cmFmZmljLXB1Ymxpc2gvY29tbWVyY2lhbC13YXZlNC0xMDAtMjAyNjExMTcvNDA3Ni5wbmciLCJuYW1lIjoiU2hlcmF0b24gTmhhIFRyYW5nIEhvdGVsICYgU3BhIn0seyJhY2NlcHRlZF9maXJzdF9saW5lIjoi4pyI77iPINCi0YPRgNGLINCyIFNpeCBTZW5zZXMgTmluaCBWYW4gQmF5IiwiaG90ZWxfaWQiOjQwNzcsImltYWdlX3NoYTI1NiI6Ijc3ZDM0Y2IzOTk0MDVmOWVjNWE0YWZhODRkNzA0ODMzMjhiOGI4YTYxMDY5MDIwZDBmNDVmMjRiYWI0ZTZjMzciLCJpbWFnZV91cmwiOiJodHRwczovL3Jhdy5naXRodWJ1c2VyY29udGVudC5jb20vcHlhdGtvZmYvbWF4LXNlYXJjaC1ib3QvZTUzZWVkZTJkZjVkYmM1ZGE1ZjcyYjA0YzBhYzg3Nzc4MTljZDhkYy8uZ2l0aHViL3RyYWZmaWMtcHVibGlzaC9jb21tZXJjaWFsLXdhdmU0LTEwMC0yMDI2MTExNy80MDc3LnBuZyIsIm5hbWUiOiJTaXggU2Vuc2VzIE5pbmggVmFuIEJheSJ9LHsiYWNjZXB0ZWRfZmlyc3RfbGluZSI6IuKciO+4jyDQotGD0YDRiyDQsiBDb3VydHlhcmQgYnkgTWFycmlvdHQgRGFuYW5nIEhhbiBSaXZlciIsImhvdGVsX2lkIjoxMzE4ODQsImltYWdlX3NoYTI1NiI6ImUxNmUwN2JiYWI5NjFkZjVmMmFiZWZjZWYyYzE2Mzc4NDQwZWUzNmQyYTRhNDVmNDU0OTgxZDUxM2IzYjNlZGYiLCJpbWFnZV91cmwiOiJodHRwczovL3Jhdy5naXRodWJ1c2VyY29udGVudC5jb20vcHlhdGtvZmYvbWF4LXNlYXJjaC1ib3QvZTUzZWVkZTJkZjVkYmM1ZGE1ZjcyYjA0YzBhYzg3Nzc4MTljZDhkYy8uZ2l0aHViL3RyYWZmaWMtcHVibGlzaC9jb21tZXJjaWFsLXdhdmU0LTEwMC0yMDI2MTExNy8xMzE4ODQucG5nIiwibmFtZSI6IkNvdXJ0eWFyZCBieSBNYXJyaW90dCBEYW5hbmcgSGFuIFJpdmVyIn0seyJhY2NlcHRlZF9maXJzdF9saW5lIjoi4pyI77iPINCi0YPRgNGLINCyIFZpbnBlYXJsIFNlYWxpbmsgTmhhIFRyYW5nIiwiaG90ZWxfaWQiOjcwNzc4LCJpbWFnZV9zaGEyNTYiOiIwMDllMTM3NDM1N2M2OTFkYTNiODFiOWFmYzE4YmYzZDFmNTViNWI4NDI4YmJkYWMzYjRiMDkwNTkwNDBjMWI0IiwiaW1hZ2VfdXJsIjoiaHR0cHM6Ly9yYXcuZ2l0aHVidXNlcmNvbnRlbnQuY29tL3B5YXRrb2ZmL21heC1zZWFyY2gtYm90L2U1M2VlZGUyZGY1ZGJjNWRhNWY3MmIwNGMwYWM4Nzc3ODE5Y2Q4ZGMvLmdpdGh1Yi90cmFmZmljLXB1Ymxpc2gvY29tbWVyY2lhbC13YXZlNC0xMDAtMjAyNjExMTcvNzA3NzgucG5nIiwibmFtZSI6IlZpbnBlYXJsIFNlYWxpbmsgTmhhIFRyYW5nIn0seyJhY2NlcHRlZF9maXJzdF9saW5lIjoi4pyI77iPINCi0YPRgNGLINCyIFJhZGlzc29uIFJFRCBEYW5hbmciLCJob3RlbF9pZCI6MTI2NDE3LCJpbWFnZV9zaGEyNTYiOiJiNzQ1MTEzMmVjNWYxZjRlMDYyNjFkYzQ3NjFkYThhMWI1MGRkMTg1Y2RkMmIwYTliNmI2NWIxNDk3NDllYjJlIiwiaW1hZ2VfdXJsIjoiaHR0cHM6Ly9yYXcuZ2l0aHVidXNlcmNvbnRlbnQuY29tL3B5YXRrb2ZmL21heC1zZWFyY2gtYm90L2U1M2VlZGUyZGY1ZGJjNWRhNWY3MmIwNGMwYWM4Nzc3ODE5Y2Q4ZGMvLmdpdGh1Yi90cmFmZmljLXB1Ymxpc2gvY29tbWVyY2lhbC13YXZlNC0xMDAtMjAyNjExMTcvMTI2NDE3LnBuZyIsIm5hbWUiOiJSYWRpc3NvbiBSRUQgRGFuYW5nIn0seyJhY2NlcHRlZF9maXJzdF9saW5lIjoi4pyI77iPINCi0YPRgNGLINCyIFB1bGxtYW4gVnVuZyBUYXUiLCJob3RlbF9pZCI6NTIyNTYsImltYWdlX3NoYTI1NiI6ImNjZjY3NjRiMTQyM2M5OTI5YmFlZWU2YWIxZTMyZTExNmUwNWUyYzA3OTQ5N2JiNTA4MGQ5YWJmNTEyMjNmZjIiLCJpbWFnZV91cmwiOiJodHRwczovL3Jhdy5naXRodWJ1c2VyY29udGVudC5jb20vcHlhdGtvZmYvbWF4LXNlYXJjaC1ib3QvZTUzZWVkZTJkZjVkYmM1ZGE1ZjcyYjA0YzBhYzg3Nzc4MTljZDhkYy8uZ2l0aHViL3RyYWZmaWMtcHVibGlzaC9jb21tZXJjaWFsLXdhdmU0LTEwMC0yMDI2MTExNy81MjI1Ni5wbmciLCJuYW1lIjoiUHVsbG1hbiBWdW5nIFRhdSJ9LHsiYWNjZXB0ZWRfZmlyc3RfbGluZSI6IuKciO+4jyDQotGD0YDRiyDQsiBWaW5wZWFybCBXb25kZXJ3b3JsZCBQaHUgUXVvYyIsImhvdGVsX2lkIjo2NTI1MywiaW1hZ2Vfc2hhMjU2IjoiMjg3NWZkNjEzZWM4YTM4MDU3ZWUxYWUzNDRjZDVlNjM4MGFiZDg0YWQ4Zjg3ZWI5YzIyMGE5Njg4ZmM1MTNmMiIsImltYWdlX3VybCI6Imh0dHBzOi8vcmF3LmdpdGh1YnVzZXJjb250ZW50LmNvbS9weWF0a29mZi9tYXgtc2VhcmNoLWJvdC9lNTNlZWRlMmRmNWRiYzVkYTVmNzJiMDRjMGFjODc3NzgxOWNkOGRjLy5naXRodWIvdHJhZmZpYy1wdWJsaXNoL2NvbW1lcmNpYWwtd2F2ZTQtMTAwLTIwMjYxMTE3LzY1MjUzLnBuZyIsIm5hbWUiOiJWaW5wZWFybCBXb25kZXJ3b3JsZCBQaHUgUXVvYyJ9LHsiYWNjZXB0ZWRfZmlyc3RfbGluZSI6IuKciO+4jyDQotGD0YDRiyDQsiBJbnRlckNvbnRpbmVudGFsIEdyYW5kIEhvIFRyYW0iLCJob3RlbF9pZCI6MTU4NjIsImltYWdlX3NoYTI1NiI6IjllZDQ4Yjc0M2IxYjM1YTBhMTYxNjBiYThkY2FhZjQyNzJlYTkwMzE5NTcyZmJhZjExYTE3NWZmNmEwM2ExYzUiLCJpbWFnZV91cmwiOiJodHRwczovL3Jhdy5naXRodWJ1c2VyY29udGVudC5jb20vcHlhdGtvZmYvbWF4LXNlYXJjaC1ib3QvZTUzZWVkZTJkZjVkYmM1ZGE1ZjcyYjA0YzBhYzg3Nzc4MTljZDhkYy8uZ2l0aHViL3RyYWZmaWMtcHVibGlzaC9jb21tZXJjaWFsLXdhdmU0LTEwMC0yMDI2MTExNy8xNTg2Mi5wbmciLCJuYW1lIjoiSW50ZXJDb250aW5lbnRhbCBHcmFuZCBIbyBUcmFtIn0seyJhY2NlcHRlZF9maXJzdF9saW5lIjoi4pyI77iPINCi0YPRgNGLINCyIEFtYW5vaSIsImhvdGVsX2lkIjo0MTQ5NCwiaW1hZ2Vfc2hhMjU2IjoiYTRkMGI1MGI0MGM4NzZlZTI4NGJjNmE1YjdlNzA1YzY3MDRiNWE5MTEyZDZiZTAxNTM1YTJkZmZmODBhYzk4YyIsImltYWdlX3VybCI6Imh0dHBzOi8vcmF3LmdpdGh1YnVzZXJjb250ZW50LmNvbS9weWF0a29mZi9tYXgtc2VhcmNoLWJvdC9lNTNlZWRlMmRmNWRiYzVkYTVmNzJiMDRjMGFjODc3NzgxOWNkOGRjLy5naXRodWIvdHJhZmZpYy1wdWJsaXNoL2NvbW1lcmNpYWwtd2F2ZTQtMTAwLTIwMjYxMTE3LzQxNDk0LnBuZyIsIm5hbWUiOiJBbWFub2kifV0=';
const FEED_EXPORT_INPUT_SHA256='4f8ccbb77c71128775e7cfe4d0776092af65a9f8ca055216f001b8d85e42ab6d';
const CANDIDATE_INPUT_B64='W10=';
const CANDIDATE_INPUT_SHA256='4f53cda18c2baa0c0354bb5f9a3ecbe5ed12ab4d8e11ba873c2f11161202b945';
ini_set('display_errors','0');ini_set('log_errors','0');
$out=['operation'=>'commercial-channel-hotel-feeds-20261118-next123-read34','mode'=>'read_bounded_public_channel_post_feed_inputs','ok'=>false,'db_writes'=>0,'provider_calls'=>0,'message_sends'=>0];
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
 insist(count($in)===10&&array_column($in,'hotel_id')===[82067,4076,4077,131884,70778,126417,52256,65253,15862,41494],'read17_exact_new1_scope');
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
    $retained=json_decode(base64_decode('W3siYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiZDE3NTQ3YjE4MDk5YjQzOGRlMTY5NzJlN2FjMjAyNTJmNGQ3ODFkZDUzZTg3Zjg0ZmJkY2QwYWZlYjhmZThkMyIsImNoYW5uZWxfaWQiOiItMTAwMjI2MjY2NjYxNiIsImhvdGVsX2lkIjo4MjA2NywibWVzc2FnZV9pZCI6Ijk2MiIsInBob3RvX3NoYTI1NiI6ImQxN2M0ODEwMzRhZmYzYWMzMzQ3Y2Y5YjlkNWRhNDUxZDMzNzA0ZWY1M2ZjZmIxNzAxMWVhN2RlMTA0ZjdjNWEiLCJwbGF0Zm9ybSI6InRlbGVncmFtIiwicG9zdF91cmwiOiJodHRwczovL3QubWUvYW55X3Rvb3VyLzk2MiIsInB1YmxpY2F0aW9uX2lkIjoyMDY4LCJ0ZXh0X3NoYTI1NiI6IjkxOTVhNDAzZDg2ZTA1ZjExZjE3MjI4MGM5MTUwNmVjNDdhZDZlMDgzNDBmNDRmNWQ2ZGM0MjkwMDEzODQ4NTIiLCJ2ZXJzaW9uX2lkIjo3NTk3fSx7ImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6IjFjZTVlYzg1MDkxNzNjN2Y2NDc4NGU3ZjA0NDRiNGFiOWIwMjdjN2NhMDZhODFkMTdiOGRlYmQyNzEzMmIyMjUiLCJjaGFubmVsX2lkIjoiLTgwNDkyMjQ0MjA5NjgzIiwiaG90ZWxfaWQiOjgyMDY3LCJtZXNzYWdlX2lkIjoibWlkLmZmZmZiNmNhZjA2MDZmZWQwMWExZWE5Njk2NWQ2MDg2IiwicGhvdG9fc2hhMjU2IjoiZDE3YzQ4MTAzNGFmZjNhYzMzNDdjZjliOWQ1ZGE0NTFkMzM3MDRlZjUzZmNmYjE3MDExZWE3ZGUxMDRmN2M1YSIsInBsYXRmb3JtIjoibWF4IiwicG9zdF91cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FueXRvdXIvQWFIcWxwWmRZSVkiLCJwdWJsaWNhdGlvbl9pZCI6MjA3OCwidGV4dF9zaGEyNTYiOiI5MTk1YTQwM2Q4NmUwNWYxMWYxNzIyODBjOTE1MDZlYzQ3YWQ2ZTA4MzQwZjQ0ZjVkNmRjNDI5MDAxMzg0ODUyIiwidmVyc2lvbl9pZCI6NzYwN30seyJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiI1MWI1YmU4ZTUyYzRjNDAxMjczNzM0MWFhM2FlMDk1NDcxNmMxYmJkODdmNmE3NDY1Zjc3OGRhMzdjOWE4OTE2IiwiY2hhbm5lbF9pZCI6Ii0xMDAyMjYyNjY2NjE2IiwiaG90ZWxfaWQiOjQwNzYsIm1lc3NhZ2VfaWQiOiI5NjMiLCJwaG90b19zaGEyNTYiOiIyY2JlN2YwMmY5ZjkyZDdlMDMzOTFiOGY2OWEwMjhkMDAwZTdhMjgzNmQwYzA1OTA3NmRkZjQ5YTUyYTZjMGMyIiwicGxhdGZvcm0iOiJ0ZWxlZ3JhbSIsInBvc3RfdXJsIjoiaHR0cHM6Ly90Lm1lL2FueV90b291ci85NjMiLCJwdWJsaWNhdGlvbl9pZCI6MjA2OSwidGV4dF9zaGEyNTYiOiI1ODZhYjYyNzIwODRlNmUwMzkzZmU3ZTJmYzNjZDgzZDgyMjE4ODE2ZmMzZmUwMWNkNjU3YzczYzM2NzliNWZkIiwidmVyc2lvbl9pZCI6NzU5OH0seyJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiJlNWNmMzJmZjVhZjc0NWFmYzFhMTE0NjMxZGNmMmQzNGMwZDFkMjY2ZGViMjMyMjdjMDlhYzU1OTg3ZjlkYzEwIiwiY2hhbm5lbF9pZCI6Ii04MDQ5MjI0NDIwOTY4MyIsImhvdGVsX2lkIjo0MDc2LCJtZXNzYWdlX2lkIjoibWlkLmZmZmZiNmNhZjA2MDZmZWQwMWExZWE5NmEwMjY0M2E0IiwicGhvdG9fc2hhMjU2IjoiMmNiZTdmMDJmOWY5MmQ3ZTAzMzkxYjhmNjlhMDI4ZDAwMGU3YTI4MzZkMGMwNTkwNzZkZGY0OWE1MmE2YzBjMiIsInBsYXRmb3JtIjoibWF4IiwicG9zdF91cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FueXRvdXIvQWFIcWxxQW1RNlEiLCJwdWJsaWNhdGlvbl9pZCI6MjA3OSwidGV4dF9zaGEyNTYiOiI1ODZhYjYyNzIwODRlNmUwMzkzZmU3ZTJmYzNjZDgzZDgyMjE4ODE2ZmMzZmUwMWNkNjU3YzczYzM2NzliNWZkIiwidmVyc2lvbl9pZCI6NzYwOH0seyJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiI5ZjljNWNhNTI3Nzc1ODVhZDRhNjUwZGYwMTU3M2ZkNDc5YzQ5MmU4ZDc4NmZhOWY5ODdmMjQ0NjI0ZDJkNTk2IiwiY2hhbm5lbF9pZCI6Ii0xMDAyMjYyNjY2NjE2IiwiaG90ZWxfaWQiOjQwNzcsIm1lc3NhZ2VfaWQiOiI5NjQiLCJwaG90b19zaGEyNTYiOiI3N2QzNGNiMzk5NDA1ZjllYzVhNGFmYTg0ZDcwNDgzMzI4YjhiOGE2MTA2OTAyMGQwZjQ1ZjI0YmFiNGU2YzM3IiwicGxhdGZvcm0iOiJ0ZWxlZ3JhbSIsInBvc3RfdXJsIjoiaHR0cHM6Ly90Lm1lL2FueV90b291ci85NjQiLCJwdWJsaWNhdGlvbl9pZCI6MjA3MCwidGV4dF9zaGEyNTYiOiI5NjY3MzFkMDg4NWE1MzA4NzdkY2JhYzc2ZGYxMDk3YWY5NGQyY2I0NTIzYTE4YjQ0NmUwZjNjOTVmNjNmYTgwIiwidmVyc2lvbl9pZCI6NzU5OX0seyJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiI4MWM3N2VjNmQwM2JmMWE4ZDk2NzAxZWRjODQ3MmY4OTg4MDc0NjhmYzhjOTRjZjUyZDE1ODBkNWQ4YTY2NDNlIiwiY2hhbm5lbF9pZCI6Ii04MDQ5MjI0NDIwOTY4MyIsImhvdGVsX2lkIjo0MDc3LCJtZXNzYWdlX2lkIjoibWlkLmZmZmZiNmNhZjA2MDZmZWQwMWExZWE5NmE5OTE1MDRmIiwicGhvdG9fc2hhMjU2IjoiNzdkMzRjYjM5OTQwNWY5ZWM1YTRhZmE4NGQ3MDQ4MzMyOGI4YjhhNjEwNjkwMjBkMGY0NWYyNGJhYjRlNmMzNyIsInBsYXRmb3JtIjoibWF4IiwicG9zdF91cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FueXRvdXIvQWFIcWxxbVJVRTgiLCJwdWJsaWNhdGlvbl9pZCI6MjA4MCwidGV4dF9zaGEyNTYiOiI5NjY3MzFkMDg4NWE1MzA4NzdkY2JhYzc2ZGYxMDk3YWY5NGQyY2I0NTIzYTE4YjQ0NmUwZjNjOTVmNjNmYTgwIiwidmVyc2lvbl9pZCI6NzYwOX0seyJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiJjNTkyODlmMmJhMWVjN2Y2NzA3ZGNjZGEyMjVhNWI0MDRmMzc0ZGQyMzlkMWFjYjU1YzExNTk5NjAzNDA5OTkwIiwiY2hhbm5lbF9pZCI6Ii0xMDAyMjYyNjY2NjE2IiwiaG90ZWxfaWQiOjEzMTg4NCwibWVzc2FnZV9pZCI6Ijk2NSIsInBob3RvX3NoYTI1NiI6ImUxNmUwN2JiYWI5NjFkZjVmMmFiZWZjZWYyYzE2Mzc4NDQwZWUzNmQyYTRhNDVmNDU0OTgxZDUxM2IzYjNlZGYiLCJwbGF0Zm9ybSI6InRlbGVncmFtIiwicG9zdF91cmwiOiJodHRwczovL3QubWUvYW55X3Rvb3VyLzk2NSIsInB1YmxpY2F0aW9uX2lkIjoyMDcxLCJ0ZXh0X3NoYTI1NiI6IjAwOGU1MjVjOWE0NTllMWY2Nzc0MDYyMzFlMzkwZTE4ZTEyMGViZWMyMTg1MzA1ZWI1NWU5NjhlYzMyZjQwMTMiLCJ2ZXJzaW9uX2lkIjo3NjAwfSx7ImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6IjQ0Y2FiOGQxYjJjMmM0NDkzMzliNmU5MWU3YTQ3ZjAxYmYwYmY3YmU5MGMwNTE2ZmRkZjViY2U2ZTU1ZmE5YzgiLCJjaGFubmVsX2lkIjoiLTgwNDkyMjQ0MjA5NjgzIiwiaG90ZWxfaWQiOjEzMTg4NCwibWVzc2FnZV9pZCI6Im1pZC5mZmZmYjZjYWYwNjA2ZmVkMDFhMWVhOTZiMmY5MDE0ZCIsInBob3RvX3NoYTI1NiI6ImUxNmUwN2JiYWI5NjFkZjVmMmFiZWZjZWYyYzE2Mzc4NDQwZWUzNmQyYTRhNDVmNDU0OTgxZDUxM2IzYjNlZGYiLCJwbGF0Zm9ybSI6Im1heCIsInBvc3RfdXJsIjoiaHR0cHM6Ly9tYXgucnUvY2hhbm5lbF9hbnl0b3VyL0FhSHFsckw1QVUwIiwicHVibGljYXRpb25faWQiOjIwODEsInRleHRfc2hhMjU2IjoiMDA4ZTUyNWM5YTQ1OWUxZjY3NzQwNjIzMWUzOTBlMThlMTIwZWJlYzIxODUzMDVlYjU1ZTk2OGVjMzJmNDAxMyIsInZlcnNpb25faWQiOjc2MTB9LHsiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiYTk1NjY5YzkyOWNjNzVhYWJlYjQ4ZmQ4MTlkNjIyZWRiYTJlNzkxNjU3YjY3OTNiYmE4MDQ4OWI2MGEyNmU3NCIsImNoYW5uZWxfaWQiOiItMTAwMjI2MjY2NjYxNiIsImhvdGVsX2lkIjo3MDc3OCwibWVzc2FnZV9pZCI6Ijk2NiIsInBob3RvX3NoYTI1NiI6IjAwOWUxMzc0MzU3YzY5MWRhM2I4MWI5YWZjMThiZjNkMWY1NWI1Yjg0MjhiYmRhYzNiNGIwOTA1OTA0MGMxYjQiLCJwbGF0Zm9ybSI6InRlbGVncmFtIiwicG9zdF91cmwiOiJodHRwczovL3QubWUvYW55X3Rvb3VyLzk2NiIsInB1YmxpY2F0aW9uX2lkIjoyMDcyLCJ0ZXh0X3NoYTI1NiI6IjAwYzkzMWU4ZTIxOTE0MWFjOTBlOTcyZmQ4MGU5OTk4NzU5NjAzNmIyMmRjOGU4ZGE0MTZjNzM2NDdlNGJlYzMiLCJ2ZXJzaW9uX2lkIjo3NjAxfSx7ImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6ImE5Mjk4NDM2MjUyY2NmMWVmNDhjODBmOTIwMjY1ZGI3YWQyYjFkZDcxZWY4ODQ4Nzk3OWU0ZjJlOGI1ZWY4YTMiLCJjaGFubmVsX2lkIjoiLTgwNDkyMjQ0MjA5NjgzIiwiaG90ZWxfaWQiOjcwNzc4LCJtZXNzYWdlX2lkIjoibWlkLmZmZmZiNmNhZjA2MDZmZWQwMWExZWE5NmJjZTgxZjc0IiwicGhvdG9fc2hhMjU2IjoiMDA5ZTEzNzQzNTdjNjkxZGEzYjgxYjlhZmMxOGJmM2QxZjU1YjViODQyOGJiZGFjM2I0YjA5MDU5MDQwYzFiNCIsInBsYXRmb3JtIjoibWF4IiwicG9zdF91cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FueXRvdXIvQWFIcWxyem9IM1EiLCJwdWJsaWNhdGlvbl9pZCI6MjA4MiwidGV4dF9zaGEyNTYiOiIwMGM5MzFlOGUyMTkxNDFhYzkwZTk3MmZkODBlOTk5ODc1OTYwMzZiMjJkYzhlOGRhNDE2YzczNjQ3ZTRiZWMzIiwidmVyc2lvbl9pZCI6NzYxMX0seyJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiIwZWM0YWQ2Zjc2ZTg1NDQwMGExMGI4NTFlZWYzODA3NDJhZTljYjgwMTFhOWQxMjdmYTQ4NDA1YzE1ZmVmMDNiIiwiY2hhbm5lbF9pZCI6Ii0xMDAyMjYyNjY2NjE2IiwiaG90ZWxfaWQiOjEyNjQxNywibWVzc2FnZV9pZCI6Ijk2NyIsInBob3RvX3NoYTI1NiI6ImI3NDUxMTMyZWM1ZjFmNGUwNjI2MWRjNDc2MWRhOGExYjUwZGQxODVjZGQyYjBhOWI2YjY1YjE0OTc0OWViMmUiLCJwbGF0Zm9ybSI6InRlbGVncmFtIiwicG9zdF91cmwiOiJodHRwczovL3QubWUvYW55X3Rvb3VyLzk2NyIsInB1YmxpY2F0aW9uX2lkIjoyMDczLCJ0ZXh0X3NoYTI1NiI6ImEzNWY2Y2RlODY5NTI0Yzg2NTRjN2JkOWQzYzdmNzhiZmUwMTQ0NTY0ZDBkYWU1OTEyZjA2ZDE0MGVmMjViYzIiLCJ2ZXJzaW9uX2lkIjo3NjAyfSx7ImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6IjdlMjkwZGUxMTlmMTA4MmJlYTRiOThmYWMzMzlmZTNhMjg1MzFmODQyZGY5OGVkMWNlNWFlOWJhNzA4YjFjZjkiLCJjaGFubmVsX2lkIjoiLTgwNDkyMjQ0MjA5NjgzIiwiaG90ZWxfaWQiOjEyNjQxNywibWVzc2FnZV9pZCI6Im1pZC5mZmZmYjZjYWYwNjA2ZmVkMDFhMWVhOTZjNjBjNjJlMSIsInBob3RvX3NoYTI1NiI6ImI3NDUxMTMyZWM1ZjFmNGUwNjI2MWRjNDc2MWRhOGExYjUwZGQxODVjZGQyYjBhOWI2YjY1YjE0OTc0OWViMmUiLCJwbGF0Zm9ybSI6Im1heCIsInBvc3RfdXJsIjoiaHR0cHM6Ly9tYXgucnUvY2hhbm5lbF9hbnl0b3VyL0FhSHFsc1lNWXVFIiwicHVibGljYXRpb25faWQiOjIwODMsInRleHRfc2hhMjU2IjoiYTM1ZjZjZGU4Njk1MjRjODY1NGM3YmQ5ZDNjN2Y3OGJmZTAxNDQ1NjRkMGRhZTU5MTJmMDZkMTQwZWYyNWJjMiIsInZlcnNpb25faWQiOjc2MTJ9LHsiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiNjgzYTExNzQyZjRiMzdkMWY4MGM1NzE1ZmY1MDNjZmE4ZWI3OTE3MWM4Y2VjYmM0NjExMTE3ZDI0ZTg0ZmQwZSIsImNoYW5uZWxfaWQiOiItMTAwMjI2MjY2NjYxNiIsImhvdGVsX2lkIjo1MjI1NiwibWVzc2FnZV9pZCI6Ijk2OCIsInBob3RvX3NoYTI1NiI6ImNjZjY3NjRiMTQyM2M5OTI5YmFlZWU2YWIxZTMyZTExNmUwNWUyYzA3OTQ5N2JiNTA4MGQ5YWJmNTEyMjNmZjIiLCJwbGF0Zm9ybSI6InRlbGVncmFtIiwicG9zdF91cmwiOiJodHRwczovL3QubWUvYW55X3Rvb3VyLzk2OCIsInB1YmxpY2F0aW9uX2lkIjoyMDc0LCJ0ZXh0X3NoYTI1NiI6IjgwMGYzODhhMWQ4NGY5ODBkODBmYjg0NjgwYzYyNjM5Mzc2ZmQyMzE1MjU3NjgxYTU4NTczZGNhNmYyODBiOTMiLCJ2ZXJzaW9uX2lkIjo3NjAzfSx7ImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6IjA4ZDVhOGI3MmUxMGFiYmFjZjZlNmU3Y2ZjZDJlMjM2Y2Q2MGYxOTA1YWQ5NDZiMTAxM2Q3ZTUwZmYxN2FjYWUiLCJjaGFubmVsX2lkIjoiLTgwNDkyMjQ0MjA5NjgzIiwiaG90ZWxfaWQiOjUyMjU2LCJtZXNzYWdlX2lkIjoibWlkLmZmZmZiNmNhZjA2MDZmZWQwMWExZWE5NmNmYWMyMWFkIiwicGhvdG9fc2hhMjU2IjoiY2NmNjc2NGIxNDIzYzk5MjliYWVlZTZhYjFlMzJlMTE2ZTA1ZTJjMDc5NDk3YmI1MDgwZDlhYmY1MTIyM2ZmMiIsInBsYXRmb3JtIjoibWF4IiwicG9zdF91cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FueXRvdXIvQWFIcWxzLXNJYTAiLCJwdWJsaWNhdGlvbl9pZCI6MjA4NCwidGV4dF9zaGEyNTYiOiI4MDBmMzg4YTFkODRmOTgwZDgwZmI4NDY4MGM2MjYzOTM3NmZkMjMxNTI1NzY4MWE1ODU3M2RjYTZmMjgwYjkzIiwidmVyc2lvbl9pZCI6NzYxM30seyJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiI2NDc2OTEyZmY1YzI0MWUxMzgxZTEzMjI3ODZhYWY4NzY1YWFmNzBlOTg2NTYxZjllOGMyYmYwOTc3YzE4MDFjIiwiY2hhbm5lbF9pZCI6Ii0xMDAyMjYyNjY2NjE2IiwiaG90ZWxfaWQiOjY1MjUzLCJtZXNzYWdlX2lkIjoiOTY5IiwicGhvdG9fc2hhMjU2IjoiMjg3NWZkNjEzZWM4YTM4MDU3ZWUxYWUzNDRjZDVlNjM4MGFiZDg0YWQ4Zjg3ZWI5YzIyMGE5Njg4ZmM1MTNmMiIsInBsYXRmb3JtIjoidGVsZWdyYW0iLCJwb3N0X3VybCI6Imh0dHBzOi8vdC5tZS9hbnlfdG9vdXIvOTY5IiwicHVibGljYXRpb25faWQiOjIwNzUsInRleHRfc2hhMjU2IjoiYTM1NzNjMGI0ZjI3OTRiNzIxMTIxNzFhZGFlYjMwZWJkZjVmNzkzYjVjODYzODRmM2IyNzdkZTBjYzExNWJlNiIsInZlcnNpb25faWQiOjc2MDR9LHsiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiMzczNjc1ZDkwNzRhOWVhYzI1YWYzODNmNzMyOTYyZDU2MTY4Y2YxNjczYTZiMmMyYmNhZWFjYTU1MTMzYzk4ZCIsImNoYW5uZWxfaWQiOiItODA0OTIyNDQyMDk2ODMiLCJob3RlbF9pZCI6NjUyNTMsIm1lc3NhZ2VfaWQiOiJtaWQuZmZmZmI2Y2FmMDYwNmZlZDAxYTFlYTk2ZDliMzc4M2MiLCJwaG90b19zaGEyNTYiOiIyODc1ZmQ2MTNlYzhhMzgwNTdlZTFhZTM0NGNkNWU2MzgwYWJkODRhZDhmODdlYjljMjIwYTk2ODhmYzUxM2YyIiwicGxhdGZvcm0iOiJtYXgiLCJwb3N0X3VybCI6Imh0dHBzOi8vbWF4LnJ1L2NoYW5uZWxfYW55dG91ci9BYUhxbHRtemVEdyIsInB1YmxpY2F0aW9uX2lkIjoyMDg1LCJ0ZXh0X3NoYTI1NiI6ImEzNTczYzBiNGYyNzk0YjcyMTEyMTcxYWRhZWIzMGViZGY1Zjc5M2I1Yzg2Mzg0ZjNiMjc3ZGUwY2MxMTViZTYiLCJ2ZXJzaW9uX2lkIjo3NjE0fSx7ImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6IjBlOTJhNjNmNWYzZWY5YTliNmIzNTEzZWUwYjdmZDFhODlkZWEyZjU5MjFlMTE0NWEzMmQzMmZlOGE1MTg4Y2EiLCJjaGFubmVsX2lkIjoiLTEwMDIyNjI2NjY2MTYiLCJob3RlbF9pZCI6MTU4NjIsIm1lc3NhZ2VfaWQiOiI5NzAiLCJwaG90b19zaGEyNTYiOiI5ZWQ0OGI3NDNiMWIzNWEwYTE2MTYwYmE4ZGNhYWY0MjcyZWE5MDMxOTU3MmZiYWYxMWExNzVmZjZhMDNhMWM1IiwicGxhdGZvcm0iOiJ0ZWxlZ3JhbSIsInBvc3RfdXJsIjoiaHR0cHM6Ly90Lm1lL2FueV90b291ci85NzAiLCJwdWJsaWNhdGlvbl9pZCI6MjA3NiwidGV4dF9zaGEyNTYiOiJmZDQzNWZkZWMyNjI4Y2FkZDBiMzQ2MWNmOTBmNzJhNTRhNmViMDJlMTk1N2UzNTMzNmFjOTExMjVmZjdiZjMyIiwidmVyc2lvbl9pZCI6NzYwNX0seyJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiI1ZWJiYWJmMWRlYjk5N2JhMTQ5NWViMzQ1MDFjMzIyYWY4ZTUzMDY0NTE5ZTg1MTRkMDliNWQxZDU3MmE0MjU1IiwiY2hhbm5lbF9pZCI6Ii04MDQ5MjI0NDIwOTY4MyIsImhvdGVsX2lkIjoxNTg2MiwibWVzc2FnZV9pZCI6Im1pZC5mZmZmYjZjYWYwNjA2ZmVkMDFhMWVhOTZlMmJhM2FhYiIsInBob3RvX3NoYTI1NiI6IjllZDQ4Yjc0M2IxYjM1YTBhMTYxNjBiYThkY2FhZjQyNzJlYTkwMzE5NTcyZmJhZjExYTE3NWZmNmEwM2ExYzUiLCJwbGF0Zm9ybSI6Im1heCIsInBvc3RfdXJsIjoiaHR0cHM6Ly9tYXgucnUvY2hhbm5lbF9hbnl0b3VyL0FhSHFsdUs2T3FzIiwicHVibGljYXRpb25faWQiOjIwODYsInRleHRfc2hhMjU2IjoiZmQ0MzVmZGVjMjYyOGNhZGQwYjM0NjFjZjkwZjcyYTU0YTZlYjAyZTE5NTdlMzUzMzZhYzkxMTI1ZmY3YmYzMiIsInZlcnNpb25faWQiOjc2MTV9LHsiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiZjA4YzgxOWUxYzM5MGM0ZWZhM2JhODI3MWFhYWM0NGZkM2FiM2IyMzFmOGVkNjAyNzYxMjQ1M2NhMjVjMGI3NSIsImNoYW5uZWxfaWQiOiItMTAwMjI2MjY2NjYxNiIsImhvdGVsX2lkIjo0MTQ5NCwibWVzc2FnZV9pZCI6Ijk3MSIsInBob3RvX3NoYTI1NiI6ImE0ZDBiNTBiNDBjODc2ZWUyODRiYzZhNWI3ZTcwNWM2NzA0YjVhOTExMmQ2YmUwMTUzNWEyZGZmZjgwYWM5OGMiLCJwbGF0Zm9ybSI6InRlbGVncmFtIiwicG9zdF91cmwiOiJodHRwczovL3QubWUvYW55X3Rvb3VyLzk3MSIsInB1YmxpY2F0aW9uX2lkIjoyMDc3LCJ0ZXh0X3NoYTI1NiI6ImY5NWE5NTkzMjNmZmZkMGFjMzE0MTYwMDRlNDVkMTBjNGM4NjhkMWY2ZmQzY2I0YmMwYTQ4N2FhMWE4ODE0MTMiLCJ2ZXJzaW9uX2lkIjo3NjA2fSx7ImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6IjhlMWVjODIxNjhkODA2OGU3ODA3ZjE3MmIzMGQ1NzFiZjE4NGRlYzVmNDUzYjhlNTYxNTdlYzMzMTljZmM4NjUiLCJjaGFubmVsX2lkIjoiLTgwNDkyMjQ0MjA5NjgzIiwiaG90ZWxfaWQiOjQxNDk0LCJtZXNzYWdlX2lkIjoibWlkLmZmZmZiNmNhZjA2MDZmZWQwMWExZWE5NmVjMmQ0NzA2IiwicGhvdG9fc2hhMjU2IjoiYTRkMGI1MGI0MGM4NzZlZTI4NGJjNmE1YjdlNzA1YzY3MDRiNWE5MTEyZDZiZTAxNTM1YTJkZmZmODBhYzk4YyIsInBsYXRmb3JtIjoibWF4IiwicG9zdF91cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FueXRvdXIvQWFIcWx1d3RSd1kiLCJwdWJsaWNhdGlvbl9pZCI6MjA4NywidGV4dF9zaGEyNTYiOiJmOTVhOTU5MzIzZmZmZDBhYzMxNDE2MDA0ZTQ1ZDEwYzRjODY4ZDFmNmZkM2NiNGJjMGE0ODdhYTFhODgxNDEzIiwidmVyc2lvbl9pZCI6NzYxNn1d',true),true,64,JSON_THROW_ON_ERROR);
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

