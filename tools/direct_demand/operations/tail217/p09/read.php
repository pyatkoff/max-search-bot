<?php
declare(strict_types=1);
const EXPECTED_INSTALLED_SHA='cc017ee5bb669f4c7c13efc91d5c5812a50e55f2';
const FEED_EXPORT_INPUT_B64='W3siaG90ZWxfaWQiOjEwMjgsIm5hbWUiOiJTZWxlY3R1bSBDb2xvdXJzIFJlc29ydCBTaWRlIiwiYWNjZXB0ZWRfZmlyc3RfbGluZSI6IuKciO+4jyDQotGD0YDRiyDQsiBTZWxlY3R1bSBDb2xvdXJzIFJlc29ydCBTaWRlIiwiaW1hZ2VfdXJsIjoiaHR0cHM6Ly9yYXcuZ2l0aHVidXNlcmNvbnRlbnQuY29tL3B5YXRrb2ZmL21heC1zZWFyY2gtYm90LzQ0MjBhY2Y0YmQxZGRjMDI5MGQxZGVlNzEzZGUwNWZlOTIwNjY0ZDkvLmdpdGh1Yi90cmFmZmljLXB1Ymxpc2gvY29tbWVyY2lhbC13YXZlMi1yZW1haW5pbmc3MC0yMDI2MTExNy8xMDI4LnBuZyIsImltYWdlX3NoYTI1NiI6ImU3YjZlYjEyMzA0M2NiNTBhNWIxYjkwZGY2NjA0YjYyMDY5MTIzZGJjYjQzOGNiOWM1YTVlYmM1ZGVlMjhmNWMifSx7ImhvdGVsX2lkIjoxMDcxLCJuYW1lIjoiU2VsZWN0dW0gTm9hIEJlbGVrIiwiYWNjZXB0ZWRfZmlyc3RfbGluZSI6IuKciO+4jyDQotGD0YDRiyDQsiBTZWxlY3R1bSBOb2EgQmVsZWsiLCJpbWFnZV91cmwiOiJodHRwczovL3Jhdy5naXRodWJ1c2VyY29udGVudC5jb20vcHlhdGtvZmYvbWF4LXNlYXJjaC1ib3QvNDQyMGFjZjRiZDFkZGMwMjkwZDFkZWU3MTNkZTA1ZmU5MjA2NjRkOS8uZ2l0aHViL3RyYWZmaWMtcHVibGlzaC9jb21tZXJjaWFsLXdhdmUyLXJlbWFpbmluZzcwLTIwMjYxMTE3LzEwNzEucG5nIiwiaW1hZ2Vfc2hhMjU2IjoiZTAyOGJkNjJjZDY4YTVkOGUwNGIzOGY5MmZmNTMyYTA0MDZiNzM0YjQ1ZGIyMjg2NDBkZGM3NzAwOTcyMjQzMyJ9LHsiaG90ZWxfaWQiOjExNDksIm5hbWUiOiJRdm9yIEtpcm1hbiBQcmVtaXVtIiwiYWNjZXB0ZWRfZmlyc3RfbGluZSI6IuKciO+4jyDQotGD0YDRiyDQsiBRdm9yIEtpcm1hbiBQcmVtaXVtIiwiaW1hZ2VfdXJsIjoiaHR0cHM6Ly9yYXcuZ2l0aHVidXNlcmNvbnRlbnQuY29tL3B5YXRrb2ZmL21heC1zZWFyY2gtYm90LzQ0MjBhY2Y0YmQxZGRjMDI5MGQxZGVlNzEzZGUwNWZlOTIwNjY0ZDkvLmdpdGh1Yi90cmFmZmljLXB1Ymxpc2gvY29tbWVyY2lhbC13YXZlMi1yZW1haW5pbmc3MC0yMDI2MTExNy8xMTQ5LnBuZyIsImltYWdlX3NoYTI1NiI6IjBkN2RmNjk2ODdlNDU5Njg3ZTY2NzU1YTI2MDEyODJjZTVkZjRkYzRkOGEzNmVjZDFmMWU5ODc0ZjhkZmFhNGEifSx7ImhvdGVsX2lkIjoxNTYzLCJuYW1lIjoiUml4b3MgQmVsZGliaSAxNisiLCJhY2NlcHRlZF9maXJzdF9saW5lIjoi4pyI77iPINCi0YPRgNGLINCyIFJpeG9zIEJlbGRpYmkgMTYrIiwiaW1hZ2VfdXJsIjoiaHR0cHM6Ly9yYXcuZ2l0aHVidXNlcmNvbnRlbnQuY29tL3B5YXRrb2ZmL21heC1zZWFyY2gtYm90LzQ0MjBhY2Y0YmQxZGRjMDI5MGQxZGVlNzEzZGUwNWZlOTIwNjY0ZDkvLmdpdGh1Yi90cmFmZmljLXB1Ymxpc2gvY29tbWVyY2lhbC13YXZlMi1yZW1haW5pbmc3MC0yMDI2MTExNy8xNTYzLnBuZyIsImltYWdlX3NoYTI1NiI6IjQyM2Y2N2RhNTYyOWQyNTFiYjc4Y2I5NzljYWNhNjljNDQxNDJjYTNhMGVlMmFlNWViNzU2ZTYxMWQ4MGQ5YTQifSx7ImhvdGVsX2lkIjoyODQ2MCwibmFtZSI6IkFrcmEgViIsImFjY2VwdGVkX2ZpcnN0X2xpbmUiOiLinIjvuI8g0KLRg9GA0Ysg0LIgQWtyYSBWIiwiaW1hZ2VfdXJsIjoiaHR0cHM6Ly9yYXcuZ2l0aHVidXNlcmNvbnRlbnQuY29tL3B5YXRrb2ZmL21heC1zZWFyY2gtYm90LzQ0MjBhY2Y0YmQxZGRjMDI5MGQxZGVlNzEzZGUwNWZlOTIwNjY0ZDkvLmdpdGh1Yi90cmFmZmljLXB1Ymxpc2gvY29tbWVyY2lhbC13YXZlMi1yZW1haW5pbmc3MC0yMDI2MTExNy8yODQ2MC5wbmciLCJpbWFnZV9zaGEyNTYiOiIzMmVkY2Q4NWVlNzMzYTlmYTk4Njk0ZTBlZDEwMzNmZTY0NTk4NGEwODk2N2RlN2VmZTFmYjQwY2Q0NzhhZGJlIn0seyJob3RlbF9pZCI6MTEyMzkxLCJuYW1lIjoiUml4b3MgVGVyc2FuZSBJc3RhbmJ1bCIsImFjY2VwdGVkX2ZpcnN0X2xpbmUiOiLinIjvuI8g0KLRg9GA0Ysg0LIgUml4b3MgVGVyc2FuZSBJc3RhbmJ1bCIsImltYWdlX3VybCI6Imh0dHBzOi8vcmF3LmdpdGh1YnVzZXJjb250ZW50LmNvbS9weWF0a29mZi9tYXgtc2VhcmNoLWJvdC80NDIwYWNmNGJkMWRkYzAyOTBkMWRlZTcxM2RlMDVmZTkyMDY2NGQ5Ly5naXRodWIvdHJhZmZpYy1wdWJsaXNoL2NvbW1lcmNpYWwtd2F2ZTItcmVtYWluaW5nNzAtMjAyNjExMTcvMTEyMzkxLnBuZyIsImltYWdlX3NoYTI1NiI6IjUyNTNlYjE4OTMzOGMxMWNiYTVlOWZmNjVmOTMyY2M2NDk4NmQ1ODg0Yzc1ZWE1OWRmZmUzOTA3N2UwOTlhNTUifSx7ImhvdGVsX2lkIjoxMjcwMDksIm5hbWUiOiJDaGFrcmEgU3BlY2lhbCBDYXZlcyIsImFjY2VwdGVkX2ZpcnN0X2xpbmUiOiLinIjvuI8g0KLRg9GA0Ysg0LIgQ2hha3JhIFNwZWNpYWwgQ2F2ZXMiLCJpbWFnZV91cmwiOiJodHRwczovL3Jhdy5naXRodWJ1c2VyY29udGVudC5jb20vcHlhdGtvZmYvbWF4LXNlYXJjaC1ib3QvNDQyMGFjZjRiZDFkZGMwMjkwZDFkZWU3MTNkZTA1ZmU5MjA2NjRkOS8uZ2l0aHViL3RyYWZmaWMtcHVibGlzaC9jb21tZXJjaWFsLXdhdmUyLXJlbWFpbmluZzcwLTIwMjYxMTE3LzEyNzAwOS5wbmciLCJpbWFnZV9zaGEyNTYiOiI1MDM1ZDUwMjVhN2ViZDMxZDBiOGU3ZTRkZTQxZjBkODY2MTc2MGQ1OTM2MWUzMzNlYTBhNThiNDk5ODBlMDYzIn0seyJob3RlbF9pZCI6MTc2MDMsIm5hbWUiOiJSaXhvcyBQZXJhIElzdGFuYnVsIiwiYWNjZXB0ZWRfZmlyc3RfbGluZSI6IuKciO+4jyDQotGD0YDRiyDQsiBSaXhvcyBQZXJhIElzdGFuYnVsIiwiaW1hZ2VfdXJsIjoiaHR0cHM6Ly9yYXcuZ2l0aHVidXNlcmNvbnRlbnQuY29tL3B5YXRrb2ZmL21heC1zZWFyY2gtYm90LzQ0MjBhY2Y0YmQxZGRjMDI5MGQxZGVlNzEzZGUwNWZlOTIwNjY0ZDkvLmdpdGh1Yi90cmFmZmljLXB1Ymxpc2gvY29tbWVyY2lhbC13YXZlMi1yZW1haW5pbmc3MC0yMDI2MTExNy8xNzYwMy5wbmciLCJpbWFnZV9zaGEyNTYiOiI4MDc5YmE1NDkyZmY3MTE3YmEwYzQxOWQ5ZmNmMDJlY2UxNDQzYzMxNGE3MTE2NzVkNzM0MTdiM2EzZDhmNDI0In0seyJob3RlbF9pZCI6MTc2ODIsIm5hbWUiOiJUaXRhbmljIENpdHkgVGFrc2ltIiwiYWNjZXB0ZWRfZmlyc3RfbGluZSI6IuKciO+4jyDQotGD0YDRiyDQsiBUaXRhbmljIENpdHkgVGFrc2ltIiwiaW1hZ2VfdXJsIjoiaHR0cHM6Ly9yYXcuZ2l0aHVidXNlcmNvbnRlbnQuY29tL3B5YXRrb2ZmL21heC1zZWFyY2gtYm90LzQ0MjBhY2Y0YmQxZGRjMDI5MGQxZGVlNzEzZGUwNWZlOTIwNjY0ZDkvLmdpdGh1Yi90cmFmZmljLXB1Ymxpc2gvY29tbWVyY2lhbC13YXZlMi1yZW1haW5pbmc3MC0yMDI2MTExNy8xNzY4Mi5wbmciLCJpbWFnZV9zaGEyNTYiOiJiZTYwNDMxZGQ3NzI4ZmJhN2M2NjVjMTcwZmI2OWZjZDkwZGRjZDcwMDllOTE1NzIzZWVjMDFhMDBkYTIzM2M0In0seyJob3RlbF9pZCI6NjY0NTAsIm5hbWUiOiJDaGFrcmEgU3VpdGVzIExldmVudCIsImFjY2VwdGVkX2ZpcnN0X2xpbmUiOiLinIjvuI8g0KLRg9GA0Ysg0LIgQ2hha3JhIFN1aXRlcyBMZXZlbnQiLCJpbWFnZV91cmwiOiJodHRwczovL3Jhdy5naXRodWJ1c2VyY29udGVudC5jb20vcHlhdGtvZmYvbWF4LXNlYXJjaC1ib3QvNDQyMGFjZjRiZDFkZGMwMjkwZDFkZWU3MTNkZTA1ZmU5MjA2NjRkOS8uZ2l0aHViL3RyYWZmaWMtcHVibGlzaC9jb21tZXJjaWFsLXdhdmUyLXJlbWFpbmluZzcwLTIwMjYxMTE3LzY2NDUwLnBuZyIsImltYWdlX3NoYTI1NiI6IjdiZDNkNTc1ZmU2MmIzYzUxNmY3MWEzNmE5YWIwMjQ4YTNjYjM0MDI4MTE3OGVlYWExYWQzNTYyNjk5MjkzYmYifV0=';
const FEED_EXPORT_INPUT_SHA256='fe211809f2ba321862858b443c77bbde9258d24ff3b23f5ed359a81374629888';
const CANDIDATE_INPUT_B64='W10=';
const CANDIDATE_INPUT_SHA256='4f53cda18c2baa0c0354bb5f9a3ecbe5ed12ab4d8e11ba873c2f11161202b945';
ini_set('display_errors','0');ini_set('log_errors','0');
$out=['operation'=>'commercial-channel-hotel-feeds-20261117-read209','mode'=>'read_bounded_public_channel_post_feed_inputs','ok'=>false,'db_writes'=>0,'provider_calls'=>0,'message_sends'=>0];
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
 insist(count($in)===10&&array_column($in,'hotel_id')===[1028,1071,1149,1563,28460,112391,127009,17603,17682,66450],'read17_exact_new1_scope');
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
    $retained=json_decode(base64_decode('W3siaG90ZWxfaWQiOjEwMjgsInBsYXRmb3JtIjoidGVsZWdyYW0iLCJwdWJsaWNhdGlvbl9pZCI6MTYzMCwidmVyc2lvbl9pZCI6NzE1OCwicG9zdF91cmwiOiJodHRwczovL3QubWUvYW55X3Rvb3VyLzczOSIsImNoYW5uZWxfaWQiOiItMTAwMjI2MjY2NjYxNiIsIm1lc3NhZ2VfaWQiOiI3MzkiLCJ0ZXh0X3NoYTI1NiI6IjIwZWJiMzM0ODQ2OTVlNmU2NTM0NGU4ODY3MGJmYmE5M2EyMTJkZDI1ZDcwMGM3MDVhMGIyYmU2ODU3MzVkYmUiLCJwaG90b19zaGEyNTYiOiJlN2I2ZWIxMjMwNDNjYjUwYTViMWI5MGRmNjYwNGI2MjA2OTEyM2RiY2I0MzhjYjljNWE1ZWJjNWRlZTI4ZjVjIiwiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiMmEyNTU5YTcxYjI4MDkyN2QxODY3OGRiNjZhNDQ5YWYwYjZjNDMzNWRlODg1ZDAzODFhNTUyODFkYWJmYjViZCJ9LHsiaG90ZWxfaWQiOjEwNzEsInBsYXRmb3JtIjoidGVsZWdyYW0iLCJwdWJsaWNhdGlvbl9pZCI6MTYzMSwidmVyc2lvbl9pZCI6NzE1OSwicG9zdF91cmwiOiJodHRwczovL3QubWUvYW55X3Rvb3VyLzc0MCIsImNoYW5uZWxfaWQiOiItMTAwMjI2MjY2NjYxNiIsIm1lc3NhZ2VfaWQiOiI3NDAiLCJ0ZXh0X3NoYTI1NiI6IjZjZTIzNTEzYmI1NzYxYjdkODRkMjE0ODUwNmQ1MjliYmViYTA2NGMyMTFiYzRlZTViMTFiNGVmNTMwMzY5NmEiLCJwaG90b19zaGEyNTYiOiJlMDI4YmQ2MmNkNjhhNWQ4ZTA0YjM4ZjkyZmY1MzJhMDQwNmI3MzRiNDVkYjIyODY0MGRkYzc3MDA5NzIyNDMzIiwiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiZjk0Y2U1NTc0NzViYTZhODllZTEzZjkzZjQ1ZGY2Y2VjYmRhMGM2YWNiZGVlMjZjODU5MGFkNTFmNTE3MWZlMyJ9LHsiaG90ZWxfaWQiOjExNDksInBsYXRmb3JtIjoidGVsZWdyYW0iLCJwdWJsaWNhdGlvbl9pZCI6MTYzMiwidmVyc2lvbl9pZCI6NzE2MCwicG9zdF91cmwiOiJodHRwczovL3QubWUvYW55X3Rvb3VyLzc0MSIsImNoYW5uZWxfaWQiOiItMTAwMjI2MjY2NjYxNiIsIm1lc3NhZ2VfaWQiOiI3NDEiLCJ0ZXh0X3NoYTI1NiI6IjQwZjY4YWUwNDA0OGEyNzRjYzAxYjUxZTI2MDg1YTM3ODMwZWM0NDE2OWMwNGQ1ZjMwNTlkYTc3MTg1ZWJmZTAiLCJwaG90b19zaGEyNTYiOiIwZDdkZjY5Njg3ZTQ1OTY4N2U2Njc1NWEyNjAxMjgyY2U1ZGY0ZGM0ZDhhMzZlY2QxZjFlOTg3NGY4ZGZhYTRhIiwiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiYjdlZmE2ZTE1ODk3NDE2OGE2N2EyOGNmOWY3ZDEyMTExZGNlNTQzNWE4YzNhNGNiOWI0M2RlYTcwMzk3ZTRiMSJ9LHsiaG90ZWxfaWQiOjE1NjMsInBsYXRmb3JtIjoidGVsZWdyYW0iLCJwdWJsaWNhdGlvbl9pZCI6MTYzMywidmVyc2lvbl9pZCI6NzE2MSwicG9zdF91cmwiOiJodHRwczovL3QubWUvYW55X3Rvb3VyLzc0MiIsImNoYW5uZWxfaWQiOiItMTAwMjI2MjY2NjYxNiIsIm1lc3NhZ2VfaWQiOiI3NDIiLCJ0ZXh0X3NoYTI1NiI6IjIwY2U3YjhkMjg1NzcxOWVkMTYwNGZhOTg5ZTIwOTc5NDUyMzIyNGRmYTgxZjkxM2RhYjRkNTYwOGFlOTc1OTkiLCJwaG90b19zaGEyNTYiOiI0MjNmNjdkYTU2MjlkMjUxYmI3OGNiOTc5Y2FjYTY5YzQ0MTQyY2EzYTBlZTJhZTVlYjc1NmU2MTFkODBkOWE0IiwiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiOWQ0ZDkxNmRlNzYyMGJjMjVhMzE1MjY5NGQyMjhlNmMxZWFlYTBmZTY0MDYxZTY0NWFmMTg2YmM4MTMyN2QxMyJ9LHsiaG90ZWxfaWQiOjI4NDYwLCJwbGF0Zm9ybSI6InRlbGVncmFtIiwicHVibGljYXRpb25faWQiOjE2MzQsInZlcnNpb25faWQiOjcxNjIsInBvc3RfdXJsIjoiaHR0cHM6Ly90Lm1lL2FueV90b291ci83NDMiLCJjaGFubmVsX2lkIjoiLTEwMDIyNjI2NjY2MTYiLCJtZXNzYWdlX2lkIjoiNzQzIiwidGV4dF9zaGEyNTYiOiJmOTE4MmIyYTQ4MmUwMzRlMWM5M2Y2ZjliMmM2NWUzOWI2YWRhNDkyNDJjNzZjOWE1MTkyNmQ5NGNkODE2NzhjIiwicGhvdG9fc2hhMjU2IjoiMzJlZGNkODVlZTczM2E5ZmE5ODY5NGUwZWQxMDMzZmU2NDU5ODRhMDg5NjdkZTdlZmUxZmI0MGNkNDc4YWRiZSIsImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6IjQxY2MyZDNkZGNlNjZhODJiNTE2NmQ4ZGY4NDEyMzhkNDQwMDIxYjhjNzQ2M2FjM2Y2Yjc3N2IwY2QyOGY0M2IifSx7ImhvdGVsX2lkIjoxMTIzOTEsInBsYXRmb3JtIjoidGVsZWdyYW0iLCJwdWJsaWNhdGlvbl9pZCI6MTYzNSwidmVyc2lvbl9pZCI6NzE2MywicG9zdF91cmwiOiJodHRwczovL3QubWUvYW55X3Rvb3VyLzc0NCIsImNoYW5uZWxfaWQiOiItMTAwMjI2MjY2NjYxNiIsIm1lc3NhZ2VfaWQiOiI3NDQiLCJ0ZXh0X3NoYTI1NiI6IjhkZTgxMTkxOTJhYTJkZWZhYTVjZWEwMTI4NGI0NjllMTIxZmY1YzJhNzdlZmQ1Y2JiOTc4ZWRiNDk3ZjE2YmUiLCJwaG90b19zaGEyNTYiOiI1MjUzZWIxODkzMzhjMTFjYmE1ZTlmZjY1ZjkzMmNjNjQ5ODZkNTg4NGM3NWVhNTlkZmZlMzkwNzdlMDk5YTU1IiwiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiZmU0ODA3ZTEzN2VlOTNlMjgzMWY0MDUyODYwMjVjMTA3MWRhNWI5ZDYwNDkwZmVlYWM3MzVlODQzZjA0ODUxZiJ9LHsiaG90ZWxfaWQiOjEyNzAwOSwicGxhdGZvcm0iOiJ0ZWxlZ3JhbSIsInB1YmxpY2F0aW9uX2lkIjoxNjM2LCJ2ZXJzaW9uX2lkIjo3MTY0LCJwb3N0X3VybCI6Imh0dHBzOi8vdC5tZS9hbnlfdG9vdXIvNzQ1IiwiY2hhbm5lbF9pZCI6Ii0xMDAyMjYyNjY2NjE2IiwibWVzc2FnZV9pZCI6Ijc0NSIsInRleHRfc2hhMjU2IjoiMTg4MDhmNGE3YjQ4YjNkZDA5YTE3NzhiOWQ2MDZiNWJkN2RlNjZmZmQwZDNlNjUzNDdmMWEzNmFmMzVkZGY2ZSIsInBob3RvX3NoYTI1NiI6IjUwMzVkNTAyNWE3ZWJkMzFkMGI4ZTdlNGRlNDFmMGQ4NjYxNzYwZDU5MzYxZTMzM2VhMGE1OGI0OTk4MGUwNjMiLCJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiJmNzRlOTU5Zjc5NWZkZGI1OGZlNWQxMTVhZDQyNGI3ZDhjZTA4MDYxNTlmMWQ5YzJmZTYyYWY5N2I5MzJmOWIzIn0seyJob3RlbF9pZCI6MTc2MDMsInBsYXRmb3JtIjoidGVsZWdyYW0iLCJwdWJsaWNhdGlvbl9pZCI6MTYzNywidmVyc2lvbl9pZCI6NzE2NSwicG9zdF91cmwiOiJodHRwczovL3QubWUvYW55X3Rvb3VyLzc0NiIsImNoYW5uZWxfaWQiOiItMTAwMjI2MjY2NjYxNiIsIm1lc3NhZ2VfaWQiOiI3NDYiLCJ0ZXh0X3NoYTI1NiI6IjUwM2U0MjkwZmIyZjUyM2YyODg1MDZjNzk0M2I3OGE2NTQ4MzdiNjY4M2Q3NjhkNWE0OTE2OTRiYzkyODc3ZGEiLCJwaG90b19zaGEyNTYiOiI4MDc5YmE1NDkyZmY3MTE3YmEwYzQxOWQ5ZmNmMDJlY2UxNDQzYzMxNGE3MTE2NzVkNzM0MTdiM2EzZDhmNDI0IiwiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiNzMzOGVjZGJiNDUxOTUyZWQ0YTY1MGU0MWJmZmEyMzJkZWY5MGNmYzQ3ODJlNWMzMzJlMTQwZjcyOTkyZTlmNiJ9LHsiaG90ZWxfaWQiOjE3NjgyLCJwbGF0Zm9ybSI6InRlbGVncmFtIiwicHVibGljYXRpb25faWQiOjE2MzgsInZlcnNpb25faWQiOjcxNjYsInBvc3RfdXJsIjoiaHR0cHM6Ly90Lm1lL2FueV90b291ci83NDciLCJjaGFubmVsX2lkIjoiLTEwMDIyNjI2NjY2MTYiLCJtZXNzYWdlX2lkIjoiNzQ3IiwidGV4dF9zaGEyNTYiOiI0ZGY1ODA0YjVlNWEyNDgxYzUzNTIxYjk1MTRhY2IwMDVkNjkzMjIzZDc5ODY3NGNiNDljY2E4ZjNiZWQ2YmZhIiwicGhvdG9fc2hhMjU2IjoiYmU2MDQzMWRkNzcyOGZiYTdjNjY1YzE3MGZiNjlmY2Q5MGRkY2Q3MDA5ZTkxNTcyM2VlYzAxYTAwZGEyMzNjNCIsImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6Ijg0Yzc3ZDZmMjNjOGEwY2RkM2NiZDk2NjU5ZTUzNjFmYTdjNzYyNGRkZTZmZWVmMjg2MDY3YmRjMjRkNGEzOTQifSx7ImhvdGVsX2lkIjo2NjQ1MCwicGxhdGZvcm0iOiJ0ZWxlZ3JhbSIsInB1YmxpY2F0aW9uX2lkIjoxNjM5LCJ2ZXJzaW9uX2lkIjo3MTY3LCJwb3N0X3VybCI6Imh0dHBzOi8vdC5tZS9hbnlfdG9vdXIvNzQ4IiwiY2hhbm5lbF9pZCI6Ii0xMDAyMjYyNjY2NjE2IiwibWVzc2FnZV9pZCI6Ijc0OCIsInRleHRfc2hhMjU2IjoiYTlhNmU5N2FlMzY3MzZiNjBmNTZmZDY2MDc0NzI3OWNmODdhMDI4YmZjZGIwN2Y1NWY3MTc0ZmZhOTY1ODhiMSIsInBob3RvX3NoYTI1NiI6IjdiZDNkNTc1ZmU2MmIzYzUxNmY3MWEzNmE5YWIwMjQ4YTNjYjM0MDI4MTE3OGVlYWExYWQzNTYyNjk5MjkzYmYiLCJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiJlYjE0ZGZjZmRmOWIyNjdlNzAxNjZjMWFmMGIyZTRiMThiMDJjZDY2MWYwMzYzYzNjYzYzYWMyNGY3MzA3OGY1In0seyJob3RlbF9pZCI6MTAyOCwicGxhdGZvcm0iOiJtYXgiLCJwdWJsaWNhdGlvbl9pZCI6MTY0MCwidmVyc2lvbl9pZCI6NzE2OCwicG9zdF91cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FueXRvdXIvQWFIcDV0eXlibmciLCJjaGFubmVsX2lkIjoiLTgwNDkyMjQ0MjA5NjgzIiwibWVzc2FnZV9pZCI6Im1pZC5mZmZmYjZjYWYwNjA2ZmVkMDFhMWU5ZTZkY2IyNmU3OCIsInRleHRfc2hhMjU2IjoiMjBlYmIzMzQ4NDY5NWU2ZTY1MzQ0ZTg4NjcwYmZiYTkzYTIxMmRkMjVkNzAwYzcwNWEwYjJiZTY4NTczNWRiZSIsInBob3RvX3NoYTI1NiI6ImU3YjZlYjEyMzA0M2NiNTBhNWIxYjkwZGY2NjA0YjYyMDY5MTIzZGJjYjQzOGNiOWM1YTVlYmM1ZGVlMjhmNWMiLCJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiIyMjI3Yzc2N2NiOTY1MDNkMWUzODE4ZmUwOWNhM2MxNDEwZDE4OTJlY2UxOWU5MDIwMjk3ZGM0M2M0MTY3YjRiIn0seyJob3RlbF9pZCI6MTA3MSwicGxhdGZvcm0iOiJtYXgiLCJwdWJsaWNhdGlvbl9pZCI6MTY0MSwidmVyc2lvbl9pZCI6NzE2OSwicG9zdF91cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FueXRvdXIvQWFIcDV1WWZUUHMiLCJjaGFubmVsX2lkIjoiLTgwNDkyMjQ0MjA5NjgzIiwibWVzc2FnZV9pZCI6Im1pZC5mZmZmYjZjYWYwNjA2ZmVkMDFhMWU5ZTZlNjFmNGNmYiIsInRleHRfc2hhMjU2IjoiNmNlMjM1MTNiYjU3NjFiN2Q4NGQyMTQ4NTA2ZDUyOWJiZWJhMDY0YzIxMWJjNGVlNWIxMWI0ZWY1MzAzNjk2YSIsInBob3RvX3NoYTI1NiI6ImUwMjhiZDYyY2Q2OGE1ZDhlMDRiMzhmOTJmZjUzMmEwNDA2YjczNGI0NWRiMjI4NjQwZGRjNzcwMDk3MjI0MzMiLCJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiJjNGJlYzU5YWE0Njg1MGU4NzdhM2M4ZDA3N2FhY2Q1ZGJmMGVjNGUwMDRmZmRmYjk0MzNjMzliNGJiNTBmYmIzIn0seyJob3RlbF9pZCI6MTE0OSwicGxhdGZvcm0iOiJtYXgiLCJwdWJsaWNhdGlvbl9pZCI6MTY0MiwidmVyc2lvbl9pZCI6NzE3MCwicG9zdF91cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FueXRvdXIvQWFIcDV1LXJiZDgiLCJjaGFubmVsX2lkIjoiLTgwNDkyMjQ0MjA5NjgzIiwibWVzc2FnZV9pZCI6Im1pZC5mZmZmYjZjYWYwNjA2ZmVkMDFhMWU5ZTZlZmFiNmRkZiIsInRleHRfc2hhMjU2IjoiNDBmNjhhZTA0MDQ4YTI3NGNjMDFiNTFlMjYwODVhMzc4MzBlYzQ0MTY5YzA0ZDVmMzA1OWRhNzcxODVlYmZlMCIsInBob3RvX3NoYTI1NiI6IjBkN2RmNjk2ODdlNDU5Njg3ZTY2NzU1YTI2MDEyODJjZTVkZjRkYzRkOGEzNmVjZDFmMWU5ODc0ZjhkZmFhNGEiLCJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiIxNWIyN2JjNzgyNWM0OTg1NzM3NDgzYjMwYTlmODViNzcxNWEyYTQxNmQyN2MzNWM5OGVmZTI2OTE5YTBjOTMyIn0seyJob3RlbF9pZCI6MTU2MywicGxhdGZvcm0iOiJtYXgiLCJwdWJsaWNhdGlvbl9pZCI6MTY0MywidmVyc2lvbl9pZCI6NzE3MSwicG9zdF91cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FueXRvdXIvQWFIcDV3STdTX0EiLCJjaGFubmVsX2lkIjoiLTgwNDkyMjQ0MjA5NjgzIiwibWVzc2FnZV9pZCI6Im1pZC5mZmZmYjZjYWYwNjA2ZmVkMDFhMWU5ZTcwMjNiNGJmMCIsInRleHRfc2hhMjU2IjoiMjBjZTdiOGQyODU3NzE5ZWQxNjA0ZmE5ODllMjA5Nzk0NTIzMjI0ZGZhODFmOTEzZGFiNGQ1NjA4YWU5NzU5OSIsInBob3RvX3NoYTI1NiI6IjQyM2Y2N2RhNTYyOWQyNTFiYjc4Y2I5NzljYWNhNjljNDQxNDJjYTNhMGVlMmFlNWViNzU2ZTYxMWQ4MGQ5YTQiLCJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiI0NTAxZjk4NTRmNmE4OGY3MGQ5MmMwZjFlM2MwNWFlODMwOWYxNTMxMTJiMjExMDEzOGViODNhNDg3Y2RkMDMyIn0seyJob3RlbF9pZCI6Mjg0NjAsInBsYXRmb3JtIjoibWF4IiwicHVibGljYXRpb25faWQiOjE2NDQsInZlcnNpb25faWQiOjcxNzIsInBvc3RfdXJsIjoiaHR0cHM6Ly9tYXgucnUvY2hhbm5lbF9hbnl0b3VyL0FhSHA1eFQ0Q0ZBIiwiY2hhbm5lbF9pZCI6Ii04MDQ5MjI0NDIwOTY4MyIsIm1lc3NhZ2VfaWQiOiJtaWQuZmZmZmI2Y2FmMDYwNmZlZDAxYTFlOWU3MTRmODA4NTAiLCJ0ZXh0X3NoYTI1NiI6ImY5MTgyYjJhNDgyZTAzNGUxYzkzZjZmOWIyYzY1ZTM5YjZhZGE0OTI0MmM3NmM5YTUxOTI2ZDk0Y2Q4MTY3OGMiLCJwaG90b19zaGEyNTYiOiIzMmVkY2Q4NWVlNzMzYTlmYTk4Njk0ZTBlZDEwMzNmZTY0NTk4NGEwODk2N2RlN2VmZTFmYjQwY2Q0NzhhZGJlIiwiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiNTQzN2JmODQ1NzUxN2Q2NmE1Y2Q3NzczNzViZjc0Mjg2N2E1ZmM0MmIzOWU0NzU0MGM0NjQyODI3NTY2OWU5NSJ9LHsiaG90ZWxfaWQiOjExMjM5MSwicGxhdGZvcm0iOiJtYXgiLCJwdWJsaWNhdGlvbl9pZCI6MTY0NSwidmVyc2lvbl9pZCI6NzE3MywicG9zdF91cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FueXRvdXIvQWFIcDV4M19CWmsiLCJjaGFubmVsX2lkIjoiLTgwNDkyMjQ0MjA5NjgzIiwibWVzc2FnZV9pZCI6Im1pZC5mZmZmYjZjYWYwNjA2ZmVkMDFhMWU5ZTcxZGZmMDU5OSIsInRleHRfc2hhMjU2IjoiOGRlODExOTE5MmFhMmRlZmFhNWNlYTAxMjg0YjQ2OWUxMjFmZjVjMmE3N2VmZDVjYmI5NzhlZGI0OTdmMTZiZSIsInBob3RvX3NoYTI1NiI6IjUyNTNlYjE4OTMzOGMxMWNiYTVlOWZmNjVmOTMyY2M2NDk4NmQ1ODg0Yzc1ZWE1OWRmZmUzOTA3N2UwOTlhNTUiLCJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiIxZGYzOWQ0ZDkxOGY1Y2Q4M2RkYTQwM2E4MzE0NjIwM2U1YzYxOTU3MmYzY2UwM2FiNzcyNmRiMWQ5ZjE2NDA5In0seyJob3RlbF9pZCI6MTI3MDA5LCJwbGF0Zm9ybSI6Im1heCIsInB1YmxpY2F0aW9uX2lkIjoxNjQ2LCJ2ZXJzaW9uX2lkIjo3MTc0LCJwb3N0X3VybCI6Imh0dHBzOi8vbWF4LnJ1L2NoYW5uZWxfYW55dG91ci9BYUhwNXllT0pEWSIsImNoYW5uZWxfaWQiOiItODA0OTIyNDQyMDk2ODMiLCJtZXNzYWdlX2lkIjoibWlkLmZmZmZiNmNhZjA2MDZmZWQwMWExZTllNzI3OGUyNDM2IiwidGV4dF9zaGEyNTYiOiIxODgwOGY0YTdiNDhiM2RkMDlhMTc3OGI5ZDYwNmI1YmQ3ZGU2NmZmZDBkM2U2NTM0N2YxYTM2YWYzNWRkZjZlIiwicGhvdG9fc2hhMjU2IjoiNTAzNWQ1MDI1YTdlYmQzMWQwYjhlN2U0ZGU0MWYwZDg2NjE3NjBkNTkzNjFlMzMzZWEwYTU4YjQ5OTgwZTA2MyIsImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6ImM3MTNjMGQ2Nzk4ZWNmZmIzY2NkMjQzY2QwMWM5MTEyOTA0NTQyZTNkMTY4ZDE4ZGJiMjM5YzM1OWQyOWMxNTYifSx7ImhvdGVsX2lkIjoxNzYwMywicGxhdGZvcm0iOiJtYXgiLCJwdWJsaWNhdGlvbl9pZCI6MTY0NywidmVyc2lvbl9pZCI6NzE3NSwicG9zdF91cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FueXRvdXIvQWFIcDV6RG9iRmsiLCJjaGFubmVsX2lkIjoiLTgwNDkyMjQ0MjA5NjgzIiwibWVzc2FnZV9pZCI6Im1pZC5mZmZmYjZjYWYwNjA2ZmVkMDFhMWU5ZTczMGU4NmM1OSIsInRleHRfc2hhMjU2IjoiNTAzZTQyOTBmYjJmNTIzZjI4ODUwNmM3OTQzYjc4YTY1NDgzN2I2NjgzZDc2OGQ1YTQ5MTY5NGJjOTI4NzdkYSIsInBob3RvX3NoYTI1NiI6IjgwNzliYTU0OTJmZjcxMTdiYTBjNDE5ZDlmY2YwMmVjZTE0NDNjMzE0YTcxMTY3NWQ3MzQxN2IzYTNkOGY0MjQiLCJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiIyNTljYmVjM2YyYTEwZTAxZmYzN2RjNWQ3Y2M5ZDc3ZGJiMWM1MWExOTE1YzI0YjZlMjI2OGE5NzI5NzA3YzVlIn0seyJob3RlbF9pZCI6MTc2ODIsInBsYXRmb3JtIjoibWF4IiwicHVibGljYXRpb25faWQiOjE2NDgsInZlcnNpb25faWQiOjcxNzYsInBvc3RfdXJsIjoiaHR0cHM6Ly9tYXgucnUvY2hhbm5lbF9hbnl0b3VyL0FhSHA1enBRTTJRIiwiY2hhbm5lbF9pZCI6Ii04MDQ5MjI0NDIwOTY4MyIsIm1lc3NhZ2VfaWQiOiJtaWQuZmZmZmI2Y2FmMDYwNmZlZDAxYTFlOWU3M2E1MDMzNjQiLCJ0ZXh0X3NoYTI1NiI6IjRkZjU4MDRiNWU1YTI0ODFjNTM1MjFiOTUxNGFjYjAwNWQ2OTMyMjNkNzk4Njc0Y2I0OWNjYThmM2JlZDZiZmEiLCJwaG90b19zaGEyNTYiOiJiZTYwNDMxZGQ3NzI4ZmJhN2M2NjVjMTcwZmI2OWZjZDkwZGRjZDcwMDllOTE1NzIzZWVjMDFhMDBkYTIzM2M0IiwiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiMjMwOGQ2N2VmNmQ2ODg5YjkyMWUxMjNlMWY3OGZjN2RlN2NkOWZmMDA1YzJhNGIxMjc3NmIxNjM0NDNjZDYxZiJ9LHsiaG90ZWxfaWQiOjY2NDUwLCJwbGF0Zm9ybSI6Im1heCIsInB1YmxpY2F0aW9uX2lkIjoxNjQ5LCJ2ZXJzaW9uX2lkIjo3MTc3LCJwb3N0X3VybCI6Imh0dHBzOi8vbWF4LnJ1L2NoYW5uZWxfYW55dG91ci9BYUhwNTBPb2F0NCIsImNoYW5uZWxfaWQiOiItODA0OTIyNDQyMDk2ODMiLCJtZXNzYWdlX2lkIjoibWlkLmZmZmZiNmNhZjA2MDZmZWQwMWExZTllNzQzYTg2YWRlIiwidGV4dF9zaGEyNTYiOiJhOWE2ZTk3YWUzNjczNmI2MGY1NmZkNjYwNzQ3Mjc5Y2Y4N2EwMjhiZmNkYjA3ZjU1ZjcxNzRmZmE5NjU4OGIxIiwicGhvdG9fc2hhMjU2IjoiN2JkM2Q1NzVmZTYyYjNjNTE2ZjcxYTM2YTlhYjAyNDhhM2NiMzQwMjgxMTc4ZWVhYTFhZDM1NjI2OTkyOTNiZiIsImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6ImQxOTYwODg0YzhkNmJlY2YxNWEzZDlkMzAzZmZhNWFhMzU4YjhlZTFkYjRkZmYyMmUyNmQ3ZjFjYzUxNTQ0ZGEifV0=',true),true,64,JSON_THROW_ON_ERROR);
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

