<?php
declare(strict_types=1);
const EXPECTED_INSTALLED_SHA='bd8e248f7138ea75cb9fda2dcf44beb631c633a5';
const FEED_EXPORT_INPUT_B64='W3siYWNjZXB0ZWRfZmlyc3RfbGluZSI6IuKciO+4jyDQotGD0YDRiyDQsiBLYXlhIEJlbGVrIiwiaG90ZWxfaWQiOjEyNTMsImltYWdlX3NoYTI1NiI6ImVjMzdkMzNiYWZmMDk1OTBlYjhjMjdjZDE0YmJkYjkzYjY1ZmJiMGFkMDZjOWE0OWVmMjY3ODQwYWVlOGY2YzEiLCJpbWFnZV91cmwiOiJodHRwczovL3Jhdy5naXRodWJ1c2VyY29udGVudC5jb20vcHlhdGtvZmYvbWF4LXNlYXJjaC1ib3QvMTBjZDM3MTNlMTNhNzYyYzU1ODU0MzQwNzJmOWZiNmZjZDBlMmVmZi8uZ2l0aHViL3RyYWZmaWMtcHVibGlzaC9jb21tZXJjaWFsLXdhdmU3LTIwMjYxMTE4LzEyNTMucG5nIiwibmFtZSI6IktheWEgQmVsZWsifSx7ImFjY2VwdGVkX2ZpcnN0X2xpbmUiOiLinIjvuI8g0KLRg9GA0Ysg0LIgU2VudGlkbyBDYXJpYmJlYW4gU29tYSBCYXkiLCJob3RlbF9pZCI6MTQzLCJpbWFnZV9zaGEyNTYiOiIyODZjZmI1ZDQ0YjA1N2E1Y2E1YmQzZDI5MGExNjUyNTdlZDgwY2ZmNGYwMzgyYjE4NmY1MGY2MDU3NzVjMDFjIiwiaW1hZ2VfdXJsIjoiaHR0cHM6Ly9yYXcuZ2l0aHVidXNlcmNvbnRlbnQuY29tL3B5YXRrb2ZmL21heC1zZWFyY2gtYm90LzEwY2QzNzEzZTEzYTc2MmM1NTg1NDM0MDcyZjlmYjZmY2QwZTJlZmYvLmdpdGh1Yi90cmFmZmljLXB1Ymxpc2gvY29tbWVyY2lhbC13YXZlNy0yMDI2MTExOC8xNDMucG5nIiwibmFtZSI6IlNlbnRpZG8gQ2FyaWJiZWFuIFNvbWEgQmF5In0seyJhY2NlcHRlZF9maXJzdF9saW5lIjoi4pyI77iPINCi0YPRgNGLINCyIE5pa2tpIEJlYWNoIFJlc29ydCAmIFNwYSBEdWJhaSIsImhvdGVsX2lkIjo0NjcyNywiaW1hZ2Vfc2hhMjU2IjoiM2NkZmQyNWEzMmNkMWYzZmRiNjAwMzMwMTZkMDQ1ZDgzYTlmZWM5MTk5YzQ0YjA4YzQ0NzM1ZTg5Mjk0Y2Y5NCIsImltYWdlX3VybCI6Imh0dHBzOi8vcmF3LmdpdGh1YnVzZXJjb250ZW50LmNvbS9weWF0a29mZi9tYXgtc2VhcmNoLWJvdC8xMGNkMzcxM2UxM2E3NjJjNTU4NTQzNDA3MmY5ZmI2ZmNkMGUyZWZmLy5naXRodWIvdHJhZmZpYy1wdWJsaXNoL2NvbW1lcmNpYWwtd2F2ZTctMjAyNjExMTgvNDY3MjcucG5nIiwibmFtZSI6Ik5pa2tpIEJlYWNoIFJlc29ydCAmIFNwYSBEdWJhaSJ9LHsiYWNjZXB0ZWRfZmlyc3RfbGluZSI6IuKciO+4jyDQotGD0YDRiyDQsiBUVUkgQmx1ZSBHcmFuZCBBenVyIiwiaG90ZWxfaWQiOjE2ODMsImltYWdlX3NoYTI1NiI6ImYyMDc0Y2ZjZDQ4NWUyYmNlOWFiYWUwYWU0MTBlNmQyMTY0ZWVjYjAxZThmMTkwYTg5OGFjM2IzM2U2ZmQzYjUiLCJpbWFnZV91cmwiOiJodHRwczovL3Jhdy5naXRodWJ1c2VyY29udGVudC5jb20vcHlhdGtvZmYvbWF4LXNlYXJjaC1ib3QvMTBjZDM3MTNlMTNhNzYyYzU1ODU0MzQwNzJmOWZiNmZjZDBlMmVmZi8uZ2l0aHViL3RyYWZmaWMtcHVibGlzaC9jb21tZXJjaWFsLXdhdmU3LTIwMjYxMTE4LzE2ODMucG5nIiwibmFtZSI6IlRVSSBCbHVlIEdyYW5kIEF6dXIifSx7ImFjY2VwdGVkX2ZpcnN0X2xpbmUiOiLinIjvuI8g0KLRg9GA0Ysg0LIgU2hhcm0gR3JhbmQgUGxhemEgUmVzb3J0IiwiaG90ZWxfaWQiOjIzNCwiaW1hZ2Vfc2hhMjU2IjoiNjM3OGZlMzBlODhlNWYxNTI1NDYwZTBhOTA4NmMzZTFkOWZmY2MxYjA1NTg0N2Q3ZTdmOTQyYjc5NmE5YTM2ZiIsImltYWdlX3VybCI6Imh0dHBzOi8vcmF3LmdpdGh1YnVzZXJjb250ZW50LmNvbS9weWF0a29mZi9tYXgtc2VhcmNoLWJvdC8xMGNkMzcxM2UxM2E3NjJjNTU4NTQzNDA3MmY5ZmI2ZmNkMGUyZWZmLy5naXRodWIvdHJhZmZpYy1wdWJsaXNoL2NvbW1lcmNpYWwtd2F2ZTctMjAyNjExMTgvMjM0LnBuZyIsIm5hbWUiOiJTaGFybSBHcmFuZCBQbGF6YSBSZXNvcnQifSx7ImFjY2VwdGVkX2ZpcnN0X2xpbmUiOiLinIjvuI8g0KLRg9GA0Ysg0LIgSW50ZXJDb250aW5lbnRhbCBNaW5hIEFsIEFyYWIiLCJob3RlbF9pZCI6NzY5NDAsImltYWdlX3NoYTI1NiI6IjExZmQ4NzhkY2FjN2RiOTliM2VhM2Y4MjQ3MjM0OGM0MDcwYzQyODZhZDI0Y2UyY2Q1MzIwNWU3NDE0MjIyZTUiLCJpbWFnZV91cmwiOiJodHRwczovL3Jhdy5naXRodWJ1c2VyY29udGVudC5jb20vcHlhdGtvZmYvbWF4LXNlYXJjaC1ib3QvMTBjZDM3MTNlMTNhNzYyYzU1ODU0MzQwNzJmOWZiNmZjZDBlMmVmZi8uZ2l0aHViL3RyYWZmaWMtcHVibGlzaC9jb21tZXJjaWFsLXdhdmU3LTIwMjYxMTE4Lzc2OTQwLnBuZyIsIm5hbWUiOiJJbnRlckNvbnRpbmVudGFsIE1pbmEgQWwgQXJhYiJ9LHsiYWNjZXB0ZWRfZmlyc3RfbGluZSI6IuKciO+4jyDQotGD0YDRiyDQsiBTd2FuZG9yIEtlbWVyIiwiaG90ZWxfaWQiOjE0MjgsImltYWdlX3NoYTI1NiI6Ijk0ZmM0YjhlYWRjYjYwYTliMWE4ZjI0OTU2ZGE3NmMwY2U3MjZmY2U1YjM4MTM1ODgzMjJiYzY4NjY3ZjRjN2IiLCJpbWFnZV91cmwiOiJodHRwczovL3Jhdy5naXRodWJ1c2VyY29udGVudC5jb20vcHlhdGtvZmYvbWF4LXNlYXJjaC1ib3QvMTBjZDM3MTNlMTNhNzYyYzU1ODU0MzQwNzJmOWZiNmZjZDBlMmVmZi8uZ2l0aHViL3RyYWZmaWMtcHVibGlzaC9jb21tZXJjaWFsLXdhdmU3LTIwMjYxMTE4LzE0MjgucG5nIiwibmFtZSI6IlN3YW5kb3IgS2VtZXIifSx7ImFjY2VwdGVkX2ZpcnN0X2xpbmUiOiLinIjvuI8g0KLRg9GA0Ysg0LIgTWFrYWRpIFBhbGFjZSIsImhvdGVsX2lkIjozMDcsImltYWdlX3NoYTI1NiI6ImE0ODgzY2YxYjg5ZWM0NzRhOTNkZjZjMGNjZjcyMTA5OTMyODQzNTYwODQ0M2UwOWI5MTFmNjUzM2I5MmY1MzUiLCJpbWFnZV91cmwiOiJodHRwczovL3Jhdy5naXRodWJ1c2VyY29udGVudC5jb20vcHlhdGtvZmYvbWF4LXNlYXJjaC1ib3QvMTBjZDM3MTNlMTNhNzYyYzU1ODU0MzQwNzJmOWZiNmZjZDBlMmVmZi8uZ2l0aHViL3RyYWZmaWMtcHVibGlzaC9jb21tZXJjaWFsLXdhdmU3LTIwMjYxMTE4LzMwNy5wbmciLCJuYW1lIjoiTWFrYWRpIFBhbGFjZSJ9LHsiYWNjZXB0ZWRfZmlyc3RfbGluZSI6IuKciO+4jyDQotGD0YDRiyDQsiBUaGUgUmV0cmVhdCBQYWxtIER1YmFpIE1HYWxsZXJ5IiwiaG90ZWxfaWQiOjU3NDc2LCJpbWFnZV9zaGEyNTYiOiIyNjZlY2NkNGNkMWM3MjRkNzJiZWQ3MmExYTgyZTAwNTFiZDc0MjZlNzlmYmYzNGUzNWJjOTYxMzYwY2ZjYjBhIiwiaW1hZ2VfdXJsIjoiaHR0cHM6Ly9yYXcuZ2l0aHVidXNlcmNvbnRlbnQuY29tL3B5YXRrb2ZmL21heC1zZWFyY2gtYm90LzEwY2QzNzEzZTEzYTc2MmM1NTg1NDM0MDcyZjlmYjZmY2QwZTJlZmYvLmdpdGh1Yi90cmFmZmljLXB1Ymxpc2gvY29tbWVyY2lhbC13YXZlNy0yMDI2MTExOC81NzQ3Ni5wbmciLCJuYW1lIjoiVGhlIFJldHJlYXQgUGFsbSBEdWJhaSBNR2FsbGVyeSJ9LHsiYWNjZXB0ZWRfZmlyc3RfbGluZSI6IuKciO+4jyDQotGD0YDRiyDQsiBBbmdlbOKAmXMgTWFybWFyaXMiLCJob3RlbF9pZCI6NTY0MjQsImltYWdlX3NoYTI1NiI6IjE1OWVhMTQ3Y2NlY2YxNGVkNzI5NDljODAzZTIwNjg5MTU5ZjRiM2I4OWY0MjU4NmM2NGUyNWFjNzk3OGEyMDgiLCJpbWFnZV91cmwiOiJodHRwczovL3Jhdy5naXRodWJ1c2VyY29udGVudC5jb20vcHlhdGtvZmYvbWF4LXNlYXJjaC1ib3QvMTBjZDM3MTNlMTNhNzYyYzU1ODU0MzQwNzJmOWZiNmZjZDBlMmVmZi8uZ2l0aHViL3RyYWZmaWMtcHVibGlzaC9jb21tZXJjaWFsLXdhdmU3LTIwMjYxMTE4LzU2NDI0LnBuZyIsIm5hbWUiOiJBbmdlbOKAmXMgTWFybWFyaXMifV0=';
const FEED_EXPORT_INPUT_SHA256='a317b039fb1d2a6055f13e28cadb0972072d27e6c710232e2f7258b6887dab62';
const CANDIDATE_INPUT_B64='W10=';
const CANDIDATE_INPUT_SHA256='4f53cda18c2baa0c0354bb5f9a3ecbe5ed12ab4d8e11ba873c2f11161202b945';
ini_set('display_errors','0');ini_set('log_errors','0');
$out=['operation'=>'commercial-channel-hotel-feeds-20261118-next174-read15','mode'=>'read_bounded_public_channel_post_feed_inputs','ok'=>false,'db_writes'=>0,'provider_calls'=>0,'message_sends'=>0];
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
 insist(count($in)===10&&array_column($in,'hotel_id')===[1253,143,46727,1683,234,76940,1428,307,57476,56424],'read17_exact_new1_scope');
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
    $retained=json_decode(base64_decode('W3siYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiMjFlYzlmN2E2NGRlOWM4MmQ1ZWI2MDRmMWVmMGFjZTMxNmY3NzUyYTNhMmRjOWNhYzk5NGI0Y2E0ZTEyZmUyYiIsImNoYW5uZWxfaWQiOiItMTAwMjI2MjY2NjYxNiIsImhvdGVsX2lkIjoxMjUzLCJtZXNzYWdlX2lkIjoiMTEyOCIsInBob3RvX3NoYTI1NiI6ImVjMzdkMzNiYWZmMDk1OTBlYjhjMjdjZDE0YmJkYjkzYjY1ZmJiMGFkMDZjOWE0OWVmMjY3ODQwYWVlOGY2YzEiLCJwbGF0Zm9ybSI6InRlbGVncmFtIiwicG9zdF91cmwiOiJodHRwczovL3QubWUvYW55X3Rvb3VyLzExMjgiLCJwdWJsaWNhdGlvbl9pZCI6MjQxMCwidGV4dF9zaGEyNTYiOiI4NGIzYTkzNDliYTMwYjE3NTg4MWM5ZDAyYjA1NmVhOTk0YmZhNTEyYjc1M2U2NWQ5Njc2NTVlNjYzODBiNzRmIiwidmVyc2lvbl9pZCI6ODEyOH0seyJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiI1MGZhMDdhYTRmMGVmNDE0ODQ4MDE2ZjdlM2MzNGU0OTE0YjIxN2FjOThlYzFkNTYzYzNjYmVmYzMxZWZjMDMwIiwiY2hhbm5lbF9pZCI6Ii04MDQ5MjI0NDIwOTY4MyIsImhvdGVsX2lkIjoxMjUzLCJtZXNzYWdlX2lkIjoibWlkLmZmZmZiNmNhZjA2MDZmZWQwMWExZWMwYTNhNDM0NjQ0IiwicGhvdG9fc2hhMjU2IjoiZWMzN2QzM2JhZmYwOTU5MGViOGMyN2NkMTRiYmRiOTNiNjVmYmIwYWQwNmM5YTQ5ZWYyNjc4NDBhZWU4ZjZjMSIsInBsYXRmb3JtIjoibWF4IiwicG9zdF91cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FueXRvdXIvQWFIc0NqcERSa1EiLCJwdWJsaWNhdGlvbl9pZCI6MjQyMCwidGV4dF9zaGEyNTYiOiI4NGIzYTkzNDliYTMwYjE3NTg4MWM5ZDAyYjA1NmVhOTk0YmZhNTEyYjc1M2U2NWQ5Njc2NTVlNjYzODBiNzRmIiwidmVyc2lvbl9pZCI6ODEzOH0seyJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiJmMGM1YmQ4ODc5YjM0Yjc1ZjAzZDZmZDk5MjJmYjc1ZGExOTEwZWE1OWIyODY2OWU0MTI2ZjZkNGRlMGY3ZGUxIiwiY2hhbm5lbF9pZCI6Ii0xMDAyMjYyNjY2NjE2IiwiaG90ZWxfaWQiOjE0MywibWVzc2FnZV9pZCI6IjExMjkiLCJwaG90b19zaGEyNTYiOiIyODZjZmI1ZDQ0YjA1N2E1Y2E1YmQzZDI5MGExNjUyNTdlZDgwY2ZmNGYwMzgyYjE4NmY1MGY2MDU3NzVjMDFjIiwicGxhdGZvcm0iOiJ0ZWxlZ3JhbSIsInBvc3RfdXJsIjoiaHR0cHM6Ly90Lm1lL2FueV90b291ci8xMTI5IiwicHVibGljYXRpb25faWQiOjI0MTEsInRleHRfc2hhMjU2IjoiZjBjZjFlYTViNWY3MzkxNzg2M2U1Zjg1MTdlYjliOGJiMWZlN2Q2MmFmMzk2ZWMyYmRhMGEwOTg1MTVlZWE5MSIsInZlcnNpb25faWQiOjgxMjl9LHsiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiYjcyNDZlOGQ4YzRjOWYxNzQ5NzVhMzRiYzdkOGI0MzhmNmRhM2ZhZDJmNzUyMTNmMmI5YTM1NTU3ZmY2MDliZCIsImNoYW5uZWxfaWQiOiItODA0OTIyNDQyMDk2ODMiLCJob3RlbF9pZCI6MTQzLCJtZXNzYWdlX2lkIjoibWlkLmZmZmZiNmNhZjA2MDZmZWQwMWExZWMwYTQzN2UzYTQwIiwicGhvdG9fc2hhMjU2IjoiMjg2Y2ZiNWQ0NGIwNTdhNWNhNWJkM2QyOTBhMTY1MjU3ZWQ4MGNmZjRmMDM4MmIxODZmNTBmNjA1Nzc1YzAxYyIsInBsYXRmb3JtIjoibWF4IiwicG9zdF91cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FueXRvdXIvQWFIc0NrTi1Pa0EiLCJwdWJsaWNhdGlvbl9pZCI6MjQyMSwidGV4dF9zaGEyNTYiOiJmMGNmMWVhNWI1ZjczOTE3ODYzZTVmODUxN2ViOWI4YmIxZmU3ZDYyYWYzOTZlYzJiZGEwYTA5ODUxNWVlYTkxIiwidmVyc2lvbl9pZCI6ODEzOX0seyJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiJmNzg2Mzk4ZjM1OTNlMzVhOTg4Y2I5MjM0ZDY2ZWZhNDJjYWQ5ZDY2ZWFmYjQxODQwMTcwZDlmMWEwOWRmZDY4IiwiY2hhbm5lbF9pZCI6Ii0xMDAyMjYyNjY2NjE2IiwiaG90ZWxfaWQiOjQ2NzI3LCJtZXNzYWdlX2lkIjoiMTEzMCIsInBob3RvX3NoYTI1NiI6IjNjZGZkMjVhMzJjZDFmM2ZkYjYwMDMzMDE2ZDA0NWQ4M2E5ZmVjOTE5OWM0NGIwOGM0NDczNWU4OTI5NGNmOTQiLCJwbGF0Zm9ybSI6InRlbGVncmFtIiwicG9zdF91cmwiOiJodHRwczovL3QubWUvYW55X3Rvb3VyLzExMzAiLCJwdWJsaWNhdGlvbl9pZCI6MjQxMiwidGV4dF9zaGEyNTYiOiI2OTBhMmZkNmQ2Y2FjMWU5OGEzNzgzMjQxMDc0N2MwZWI0YTI2MmU3OWMwYzRlYmFiY2E2YTc2MTk1MTQ0ZjlkIiwidmVyc2lvbl9pZCI6ODEzMH0seyJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiJiNjQ1NzEyMTg2Y2YxZDgyNzZjOWFmZjg1ZGI5MTJlNTYxZmExM2VhZWExMTU4NzVlMTY0YjhiYjg5ZTU2MjY2IiwiY2hhbm5lbF9pZCI6Ii04MDQ5MjI0NDIwOTY4MyIsImhvdGVsX2lkIjo0NjcyNywibWVzc2FnZV9pZCI6Im1pZC5mZmZmYjZjYWYwNjA2ZmVkMDFhMWVjMGE0Y2VmMjIyNSIsInBob3RvX3NoYTI1NiI6IjNjZGZkMjVhMzJjZDFmM2ZkYjYwMDMzMDE2ZDA0NWQ4M2E5ZmVjOTE5OWM0NGIwOGM0NDczNWU4OTI5NGNmOTQiLCJwbGF0Zm9ybSI6Im1heCIsInBvc3RfdXJsIjoiaHR0cHM6Ly9tYXgucnUvY2hhbm5lbF9hbnl0b3VyL0FhSHNDa3p2SWlVIiwicHVibGljYXRpb25faWQiOjI0MjIsInRleHRfc2hhMjU2IjoiNjkwYTJmZDZkNmNhYzFlOThhMzc4MzI0MTA3NDdjMGViNGEyNjJlNzljMGM0ZWJhYmNhNmE3NjE5NTE0NGY5ZCIsInZlcnNpb25faWQiOjgxNDB9LHsiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiYjJjNTg3MjEwZmYyN2ZkNDNhNTkxMGE5ZmNiY2VhYzJiM2MyMTZiMWNkNTA1MTk4MjlmMzlmNjFiMWExYmI5ZSIsImNoYW5uZWxfaWQiOiItMTAwMjI2MjY2NjYxNiIsImhvdGVsX2lkIjoxNjgzLCJtZXNzYWdlX2lkIjoiMTEzMSIsInBob3RvX3NoYTI1NiI6ImYyMDc0Y2ZjZDQ4NWUyYmNlOWFiYWUwYWU0MTBlNmQyMTY0ZWVjYjAxZThmMTkwYTg5OGFjM2IzM2U2ZmQzYjUiLCJwbGF0Zm9ybSI6InRlbGVncmFtIiwicG9zdF91cmwiOiJodHRwczovL3QubWUvYW55X3Rvb3VyLzExMzEiLCJwdWJsaWNhdGlvbl9pZCI6MjQxMywidGV4dF9zaGEyNTYiOiI3ODk2OTBjOTZjN2E2NzIzMTY5OTgwNzUzYjUwZjYzMzlkMmE3NGNjZjUwNGZkMmU3ZDZiM2RhNzE4NjU2ZjQ4IiwidmVyc2lvbl9pZCI6ODEzMX0seyJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiJlMDk3M2ZhNTJkYWE2OThjNjhjYjM2Nzc2ZWQ2ZGE5MjBlYmQwNjM1NjYwYjAwMzhkYWQ1MWNhMmFiMzA1MGQ3IiwiY2hhbm5lbF9pZCI6Ii04MDQ5MjI0NDIwOTY4MyIsImhvdGVsX2lkIjoxNjgzLCJtZXNzYWdlX2lkIjoibWlkLmZmZmZiNmNhZjA2MDZmZWQwMWExZWMwYTU2OWM1ZTBkIiwicGhvdG9fc2hhMjU2IjoiZjIwNzRjZmNkNDg1ZTJiY2U5YWJhZTBhZTQxMGU2ZDIxNjRlZWNiMDFlOGYxOTBhODk4YWMzYjMzZTZmZDNiNSIsInBsYXRmb3JtIjoibWF4IiwicG9zdF91cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FueXRvdXIvQWFIc0NsYWNYZzAiLCJwdWJsaWNhdGlvbl9pZCI6MjQyMywidGV4dF9zaGEyNTYiOiI3ODk2OTBjOTZjN2E2NzIzMTY5OTgwNzUzYjUwZjYzMzlkMmE3NGNjZjUwNGZkMmU3ZDZiM2RhNzE4NjU2ZjQ4IiwidmVyc2lvbl9pZCI6ODE0MX0seyJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiI4MmI4MDAwZTQ5NzNjOTYwZWE0YjY3NjBiZmY5NDI2Zjg0NTY2OTMzOGZmNGY1ODY4ZDIwYTFhZmJiNjlkYWU3IiwiY2hhbm5lbF9pZCI6Ii0xMDAyMjYyNjY2NjE2IiwiaG90ZWxfaWQiOjIzNCwibWVzc2FnZV9pZCI6IjExMzIiLCJwaG90b19zaGEyNTYiOiI2Mzc4ZmUzMGU4OGU1ZjE1MjU0NjBlMGE5MDg2YzNlMWQ5ZmZjYzFiMDU1ODQ3ZDdlN2Y5NDJiNzk2YTlhMzZmIiwicGxhdGZvcm0iOiJ0ZWxlZ3JhbSIsInBvc3RfdXJsIjoiaHR0cHM6Ly90Lm1lL2FueV90b291ci8xMTMyIiwicHVibGljYXRpb25faWQiOjI0MTQsInRleHRfc2hhMjU2IjoiNDAyNmU2NzAyMGEzNTMwZjA2MDNhMzlmNzc0NGIwNjhhNjc5ZjM1NzFiYTFiODdhMjc3NTk3YmQ5MjIzODIzZCIsInZlcnNpb25faWQiOjgxMzJ9LHsiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiN2FhZTc1OGVmNGVjNjdjNTY3YjViZTJhMjI4MDNlYmQxNGRiOTZjNmFiZDdkM2JhYjc5N2IyNTM3ZjUxZDFjMCIsImNoYW5uZWxfaWQiOiItODA0OTIyNDQyMDk2ODMiLCJob3RlbF9pZCI6MjM0LCJtZXNzYWdlX2lkIjoibWlkLmZmZmZiNmNhZjA2MDZmZWQwMWExZWMwYTYwMWUzMjZjIiwicGhvdG9fc2hhMjU2IjoiNjM3OGZlMzBlODhlNWYxNTI1NDYwZTBhOTA4NmMzZTFkOWZmY2MxYjA1NTg0N2Q3ZTdmOTQyYjc5NmE5YTM2ZiIsInBsYXRmb3JtIjoibWF4IiwicG9zdF91cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FueXRvdXIvQWFIc0NtQWVNbXciLCJwdWJsaWNhdGlvbl9pZCI6MjQyNCwidGV4dF9zaGEyNTYiOiI0MDI2ZTY3MDIwYTM1MzBmMDYwM2EzOWY3NzQ0YjA2OGE2NzlmMzU3MWJhMWI4N2EyNzc1OTdiZDkyMjM4MjNkIiwidmVyc2lvbl9pZCI6ODE0Mn0seyJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiJhYTEyMGRiZWQ2MjMxY2QzMjZmYjlkNjQyMWYyYjc5MjA4OGVkODcyOWMxNWZiMzY5NDYwZWIzYTI4ZGMyZjc0IiwiY2hhbm5lbF9pZCI6Ii0xMDAyMjYyNjY2NjE2IiwiaG90ZWxfaWQiOjc2OTQwLCJtZXNzYWdlX2lkIjoiMTEzMyIsInBob3RvX3NoYTI1NiI6IjExZmQ4NzhkY2FjN2RiOTliM2VhM2Y4MjQ3MjM0OGM0MDcwYzQyODZhZDI0Y2UyY2Q1MzIwNWU3NDE0MjIyZTUiLCJwbGF0Zm9ybSI6InRlbGVncmFtIiwicG9zdF91cmwiOiJodHRwczovL3QubWUvYW55X3Rvb3VyLzExMzMiLCJwdWJsaWNhdGlvbl9pZCI6MjQxNSwidGV4dF9zaGEyNTYiOiJkM2VmOTZlOTEwYjg0MjI3YWFmMzg4MWNhMDg2NmI4NzZjNzIxODA3YTQ5OTc4MDdmZDM3MWMzOTg5ODVkZTlkIiwidmVyc2lvbl9pZCI6ODEzM30seyJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiI4YTIxZjg3ZTE1MWIzZTgxYTBlNjNhYzZkZjViNDEyNTMzMGJiNzY0YjA0MWExNjFkNTBiYTA1NzcwNjM5NTk5IiwiY2hhbm5lbF9pZCI6Ii04MDQ5MjI0NDIwOTY4MyIsImhvdGVsX2lkIjo3Njk0MCwibWVzc2FnZV9pZCI6Im1pZC5mZmZmYjZjYWYwNjA2ZmVkMDFhMWVjMGE2OTgzMmI4MCIsInBob3RvX3NoYTI1NiI6IjExZmQ4NzhkY2FjN2RiOTliM2VhM2Y4MjQ3MjM0OGM0MDcwYzQyODZhZDI0Y2UyY2Q1MzIwNWU3NDE0MjIyZTUiLCJwbGF0Zm9ybSI6Im1heCIsInBvc3RfdXJsIjoiaHR0cHM6Ly9tYXgucnUvY2hhbm5lbF9hbnl0b3VyL0FhSHNDbW1ESzRBIiwicHVibGljYXRpb25faWQiOjI0MjUsInRleHRfc2hhMjU2IjoiZDNlZjk2ZTkxMGI4NDIyN2FhZjM4ODFjYTA4NjZiODc2YzcyMTgwN2E0OTk3ODA3ZmQzNzFjMzk4OTg1ZGU5ZCIsInZlcnNpb25faWQiOjgxNDN9LHsiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiMmZhYWJlODExMzAzZDUwYjA0ZDg5Y2UzNTRmZmE3MzUwZjk4ZmRhMjE1ODg1YTRhZTVmYWViYmE0Mzk1YjRmOSIsImNoYW5uZWxfaWQiOiItMTAwMjI2MjY2NjYxNiIsImhvdGVsX2lkIjoxNDI4LCJtZXNzYWdlX2lkIjoiMTEzNCIsInBob3RvX3NoYTI1NiI6Ijk0ZmM0YjhlYWRjYjYwYTliMWE4ZjI0OTU2ZGE3NmMwY2U3MjZmY2U1YjM4MTM1ODgzMjJiYzY4NjY3ZjRjN2IiLCJwbGF0Zm9ybSI6InRlbGVncmFtIiwicG9zdF91cmwiOiJodHRwczovL3QubWUvYW55X3Rvb3VyLzExMzQiLCJwdWJsaWNhdGlvbl9pZCI6MjQxNiwidGV4dF9zaGEyNTYiOiI0ODNhYmY1OGMxMjUxNmZhYmNiNmUxZDFlNzc1NjJhZWU1MzVmZjA3Njc5MTZmYzkzYjZhZDU4MDZlZDhmOWZlIiwidmVyc2lvbl9pZCI6ODEzNH0seyJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiJjOGQ3NmIxYjI0OWJkNGFiMGEzMDBiMGNlM2ZlMDJkZDk5YmJiN2MxZmUxNWY0MjEzM2YyMTdmNzA3MGQ4ZWMyIiwiY2hhbm5lbF9pZCI6Ii04MDQ5MjI0NDIwOTY4MyIsImhvdGVsX2lkIjoxNDI4LCJtZXNzYWdlX2lkIjoibWlkLmZmZmZiNmNhZjA2MDZmZWQwMWExZWMwYTcyYTMxZTMyIiwicGhvdG9fc2hhMjU2IjoiOTRmYzRiOGVhZGNiNjBhOWIxYThmMjQ5NTZkYTc2YzBjZTcyNmZjZTViMzgxMzU4ODMyMmJjNjg2NjdmNGM3YiIsInBsYXRmb3JtIjoibWF4IiwicG9zdF91cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FueXRvdXIvQWFIc0NuS2pIakkiLCJwdWJsaWNhdGlvbl9pZCI6MjQyNiwidGV4dF9zaGEyNTYiOiI0ODNhYmY1OGMxMjUxNmZhYmNiNmUxZDFlNzc1NjJhZWU1MzVmZjA3Njc5MTZmYzkzYjZhZDU4MDZlZDhmOWZlIiwidmVyc2lvbl9pZCI6ODE0NH0seyJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiI0MTAzZTUxNjdmM2U3NjgwMjQ2NjdmOTRjODU2MGE1MjI2NDk0NGM2MjJmNDFhM2E3MGYwYmRhZDA5NGExMTczIiwiY2hhbm5lbF9pZCI6Ii0xMDAyMjYyNjY2NjE2IiwiaG90ZWxfaWQiOjMwNywibWVzc2FnZV9pZCI6IjExMzUiLCJwaG90b19zaGEyNTYiOiJhNDg4M2NmMWI4OWVjNDc0YTkzZGY2YzBjY2Y3MjEwOTkzMjg0MzU2MDg0NDNlMDliOTExZjY1MzNiOTJmNTM1IiwicGxhdGZvcm0iOiJ0ZWxlZ3JhbSIsInBvc3RfdXJsIjoiaHR0cHM6Ly90Lm1lL2FueV90b291ci8xMTM1IiwicHVibGljYXRpb25faWQiOjI0MTcsInRleHRfc2hhMjU2IjoiZDFjZmRlOTlhY2I4YTU1MDJjZTA3ZWY3YzA1YTY2OTg3YjUzYjZmNDk3NjNmNGFmMDU2NjMyZDQxYzM4Nzc2MiIsInZlcnNpb25faWQiOjgxMzV9LHsiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiMDAzOTk3NmI4Mzk0ZWQ5OWY5YzhhNWEwOTExODcwN2NiOTM5MDY4NDc1ZmI3MWIzMGEwMzQzZTQ0NTY0MmM1OSIsImNoYW5uZWxfaWQiOiItODA0OTIyNDQyMDk2ODMiLCJob3RlbF9pZCI6MzA3LCJtZXNzYWdlX2lkIjoibWlkLmZmZmZiNmNhZjA2MDZmZWQwMWExZWMwYTdjMGU1MDdjIiwicGhvdG9fc2hhMjU2IjoiYTQ4ODNjZjFiODllYzQ3NGE5M2RmNmMwY2NmNzIxMDk5MzI4NDM1NjA4NDQzZTA5YjkxMWY2NTMzYjkyZjUzNSIsInBsYXRmb3JtIjoibWF4IiwicG9zdF91cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FueXRvdXIvQWFIc0Nud09VSHciLCJwdWJsaWNhdGlvbl9pZCI6MjQyNywidGV4dF9zaGEyNTYiOiJkMWNmZGU5OWFjYjhhNTUwMmNlMDdlZjdjMDVhNjY5ODdiNTNiNmY0OTc2M2Y0YWYwNTY2MzJkNDFjMzg3NzYyIiwidmVyc2lvbl9pZCI6ODE0NX0seyJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiJkYjllNzA1ZWVjYmRkZTBhZWIyYjZjZDVmNTMyZDA5YTE5MjAzZWIwNzI5ZDA4NWZlYjU5ODgxYTQ5YWQwYmI5IiwiY2hhbm5lbF9pZCI6Ii0xMDAyMjYyNjY2NjE2IiwiaG90ZWxfaWQiOjU3NDc2LCJtZXNzYWdlX2lkIjoiMTEzNiIsInBob3RvX3NoYTI1NiI6IjI2NmVjY2Q0Y2QxYzcyNGQ3MmJlZDcyYTFhODJlMDA1MWJkNzQyNmU3OWZiZjM0ZTM1YmM5NjEzNjBjZmNiMGEiLCJwbGF0Zm9ybSI6InRlbGVncmFtIiwicG9zdF91cmwiOiJodHRwczovL3QubWUvYW55X3Rvb3VyLzExMzYiLCJwdWJsaWNhdGlvbl9pZCI6MjQxOCwidGV4dF9zaGEyNTYiOiJmODRhMzAwOTQ3ZWJiNTQ3ZGMyNjliNWVmMzNiNjliNTNlMWUwZWNmNmRmM2FkZjNjY2NkNTA4YTZlMTA0NWQyIiwidmVyc2lvbl9pZCI6ODEzNn0seyJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiI4MzNjMThhZTY1NTEzZTZlMTU0ZDU4MjFiMDg0ZGYzYmQxMTYzOTcwOWI1MmUzMjY1MjgwYmY1NGY1MmI5MGRmIiwiY2hhbm5lbF9pZCI6Ii04MDQ5MjI0NDIwOTY4MyIsImhvdGVsX2lkIjo1NzQ3NiwibWVzc2FnZV9pZCI6Im1pZC5mZmZmYjZjYWYwNjA2ZmVkMDFhMWVjMGE4NTg0NWM0YyIsInBob3RvX3NoYTI1NiI6IjI2NmVjY2Q0Y2QxYzcyNGQ3MmJlZDcyYTFhODJlMDA1MWJkNzQyNmU3OWZiZjM0ZTM1YmM5NjEzNjBjZmNiMGEiLCJwbGF0Zm9ybSI6Im1heCIsInBvc3RfdXJsIjoiaHR0cHM6Ly9tYXgucnUvY2hhbm5lbF9hbnl0b3VyL0FhSHNDb1dFWEV3IiwicHVibGljYXRpb25faWQiOjI0MjgsInRleHRfc2hhMjU2IjoiZjg0YTMwMDk0N2ViYjU0N2RjMjY5YjVlZjMzYjY5YjUzZTFlMGVjZjZkZjNhZGYzY2NjZDUwOGE2ZTEwNDVkMiIsInZlcnNpb25faWQiOjgxNDZ9LHsiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiYzc1NjNjZTQ1OWRiYWE2ZDA2MDM2NWNkODU2NzQ4NzUwMTFjNTRiOWUzOGYzZGZhYmYwM2U0YjEyNmM4YzZmOSIsImNoYW5uZWxfaWQiOiItMTAwMjI2MjY2NjYxNiIsImhvdGVsX2lkIjo1NjQyNCwibWVzc2FnZV9pZCI6IjExMzciLCJwaG90b19zaGEyNTYiOiIxNTllYTE0N2NjZWNmMTRlZDcyOTQ5YzgwM2UyMDY4OTE1OWY0YjNiODlmNDI1ODZjNjRlMjVhYzc5NzhhMjA4IiwicGxhdGZvcm0iOiJ0ZWxlZ3JhbSIsInBvc3RfdXJsIjoiaHR0cHM6Ly90Lm1lL2FueV90b291ci8xMTM3IiwicHVibGljYXRpb25faWQiOjI0MTksInRleHRfc2hhMjU2IjoiNTkwNjE1YTgyODU0MmVlYmNiZWQ5ZGFmMzhmYzUyYjMyODdkNjhjMjIyOWNiODE3YmI4ODQ2NzE1MWYzOTIwNCIsInZlcnNpb25faWQiOjgxMzd9LHsiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiYzdjOTlhODMyYzBhNjc5ZTNjY2RkOTIyODdkYTVhZmY2ZWZhODA3ODYwZDgxNTRhNzIzNTBkNzZlYmRjNTI2YiIsImNoYW5uZWxfaWQiOiItODA0OTIyNDQyMDk2ODMiLCJob3RlbF9pZCI6NTY0MjQsIm1lc3NhZ2VfaWQiOiJtaWQuZmZmZmI2Y2FmMDYwNmZlZDAxYTFlYzBhOGYwMDNmYzIiLCJwaG90b19zaGEyNTYiOiIxNTllYTE0N2NjZWNmMTRlZDcyOTQ5YzgwM2UyMDY4OTE1OWY0YjNiODlmNDI1ODZjNjRlMjVhYzc5NzhhMjA4IiwicGxhdGZvcm0iOiJtYXgiLCJwb3N0X3VybCI6Imh0dHBzOi8vbWF4LnJ1L2NoYW5uZWxfYW55dG91ci9BYUhzQ284QVA4SSIsInB1YmxpY2F0aW9uX2lkIjoyNDI5LCJ0ZXh0X3NoYTI1NiI6IjU5MDYxNWE4Mjg1NDJlZWJjYmVkOWRhZjM4ZmM1MmIzMjg3ZDY4YzIyMjljYjgxN2JiODg0NjcxNTFmMzkyMDQiLCJ2ZXJzaW9uX2lkIjo4MTQ3fV0=',true),true,64,JSON_THROW_ON_ERROR);
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

