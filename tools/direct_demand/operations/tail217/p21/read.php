<?php
declare(strict_types=1);
const EXPECTED_INSTALLED_SHA='cc017ee5bb669f4c7c13efc91d5c5812a50e55f2';
const FEED_EXPORT_INPUT_B64='W3siaG90ZWxfaWQiOjY4NSwibmFtZSI6IlNBaWkgTGFndW5hIFBodWtldCIsImFjY2VwdGVkX2ZpcnN0X2xpbmUiOiLinIjvuI8g0KLRg9GA0Ysg0LIgU0FpaSBMYWd1bmEgUGh1a2V0IiwiaW1hZ2VfdXJsIjoiaHR0cHM6Ly9yYXcuZ2l0aHVidXNlcmNvbnRlbnQuY29tL3B5YXRrb2ZmL21heC1zZWFyY2gtYm90LzQ0ZTM2NmQ3MDFlNjUwMTM3NTJlMDY3OTQzMTM1YWUwYWUzMzc0OTAvLmdpdGh1Yi90cmFmZmljLXB1Ymxpc2gvY29tbWVyY2lhbC13YXZlMy04MC0yMDI2MTExNy82ODUucG5nIiwiaW1hZ2Vfc2hhMjU2IjoiZDNlZTZhMjY1MzlkODg4NTU5MWMzNGM2MmY4YmQxMTI3ZmY4NDU2YWRhYWEwZmYzNTVkZDM5NmE2ZDIxMGRkNiJ9LHsiaG90ZWxfaWQiOjY4OSwibmFtZSI6IkxlIE3DqXJpZGllbiBQaHVrZXQgQmVhY2ggUmVzb3J0IiwiYWNjZXB0ZWRfZmlyc3RfbGluZSI6IuKciO+4jyDQotGD0YDRiyDQsiBMZSBNw6lyaWRpZW4gUGh1a2V0IEJlYWNoIFJlc29ydCIsImltYWdlX3VybCI6Imh0dHBzOi8vcmF3LmdpdGh1YnVzZXJjb250ZW50LmNvbS9weWF0a29mZi9tYXgtc2VhcmNoLWJvdC80NGUzNjZkNzAxZTY1MDEzNzUyZTA2Nzk0MzEzNWFlMGFlMzM3NDkwLy5naXRodWIvdHJhZmZpYy1wdWJsaXNoL2NvbW1lcmNpYWwtd2F2ZTMtODAtMjAyNjExMTcvNjg5LnBuZyIsImltYWdlX3NoYTI1NiI6IjY4Zjk4MjQ0NmYzYzU4ZGRkOTJkYjM4ZmVjN2E5OGRlOTFhY2MxNGI3ZDdjNWJlODVjMDg2ZWRlYTkyYTNhODkifSx7ImhvdGVsX2lkIjo2OTcsIm5hbWUiOiJBdmFuaSBQYXR0YXlhIFJlc29ydCIsImFjY2VwdGVkX2ZpcnN0X2xpbmUiOiLinIjvuI8g0KLRg9GA0Ysg0LIgQXZhbmkgUGF0dGF5YSBSZXNvcnQiLCJpbWFnZV91cmwiOiJodHRwczovL3Jhdy5naXRodWJ1c2VyY29udGVudC5jb20vcHlhdGtvZmYvbWF4LXNlYXJjaC1ib3QvNDRlMzY2ZDcwMWU2NTAxMzc1MmUwNjc5NDMxMzVhZTBhZTMzNzQ5MC8uZ2l0aHViL3RyYWZmaWMtcHVibGlzaC9jb21tZXJjaWFsLXdhdmUzLTgwLTIwMjYxMTE3LzY5Ny5wbmciLCJpbWFnZV9zaGEyNTYiOiJkNzJlZGUxZTYyYmViMWZjOGMxMDNiZTJkMTNkMTk0MGU1M2NhM2RhMDVkNWY3MDc4MjlkNTY4ZDRkZTlhNGYwIn0seyJob3RlbF9pZCI6NzYxLCJuYW1lIjoiUHVsbG1hbiBQaHVrZXQgUGFud2EgQmVhY2ggUmVzb3J0IiwiYWNjZXB0ZWRfZmlyc3RfbGluZSI6IuKciO+4jyDQotGD0YDRiyDQsiBQdWxsbWFuIFBodWtldCBQYW53YSBCZWFjaCBSZXNvcnQiLCJpbWFnZV91cmwiOiJodHRwczovL3Jhdy5naXRodWJ1c2VyY29udGVudC5jb20vcHlhdGtvZmYvbWF4LXNlYXJjaC1ib3QvNDRlMzY2ZDcwMWU2NTAxMzc1MmUwNjc5NDMxMzVhZTBhZTMzNzQ5MC8uZ2l0aHViL3RyYWZmaWMtcHVibGlzaC9jb21tZXJjaWFsLXdhdmUzLTgwLTIwMjYxMTE3Lzc2MS5wbmciLCJpbWFnZV9zaGEyNTYiOiJjNGI3ZWFmMjRkOWI4NTYxYTFiMTI2ZmFlMmFiOTJiMjk5NzFjOGI2NjI5ODY5NWFmOTUzMTVkZWFjOTc4NjkxIn0seyJob3RlbF9pZCI6NzkxLCJuYW1lIjoiQW5nc2FuYSBMYWd1bmEgUGh1a2V0IiwiYWNjZXB0ZWRfZmlyc3RfbGluZSI6IuKciO+4jyDQotGD0YDRiyDQsiBBbmdzYW5hIExhZ3VuYSBQaHVrZXQiLCJpbWFnZV91cmwiOiJodHRwczovL3Jhdy5naXRodWJ1c2VyY29udGVudC5jb20vcHlhdGtvZmYvbWF4LXNlYXJjaC1ib3QvNDRlMzY2ZDcwMWU2NTAxMzc1MmUwNjc5NDMxMzVhZTBhZTMzNzQ5MC8uZ2l0aHViL3RyYWZmaWMtcHVibGlzaC9jb21tZXJjaWFsLXdhdmUzLTgwLTIwMjYxMTE3Lzc5MS5wbmciLCJpbWFnZV9zaGEyNTYiOiJjNjUyMjBmY2Q4ZTdiYzhmYzQ5ZDNlMzdjYjJmZDBjM2U4YmRiYzFiMTdmZDEyMTRjNGFkZjY3Y2RjMzNhMjBiIn0seyJob3RlbF9pZCI6MzAyMSwibmFtZSI6IlNoZXJhdG9uIEh1YSBIaW4gUmVzb3J0ICYgU3BhIiwiYWNjZXB0ZWRfZmlyc3RfbGluZSI6IuKciO+4jyDQotGD0YDRiyDQsiBTaGVyYXRvbiBIdWEgSGluIFJlc29ydCAmIFNwYSIsImltYWdlX3VybCI6Imh0dHBzOi8vcmF3LmdpdGh1YnVzZXJjb250ZW50LmNvbS9weWF0a29mZi9tYXgtc2VhcmNoLWJvdC80NGUzNjZkNzAxZTY1MDEzNzUyZTA2Nzk0MzEzNWFlMGFlMzM3NDkwLy5naXRodWIvdHJhZmZpYy1wdWJsaXNoL2NvbW1lcmNpYWwtd2F2ZTMtODAtMjAyNjExMTcvMzAyMS5wbmciLCJpbWFnZV9zaGEyNTYiOiJlODc3Zjk4ZGVjZWNkNjcwYjJjOWRmN2E0ZmIxZmQ0NjQ5OWUxNzI2YzMxMDdjYTY3Yjg3NGVjMmI4ZjVjOTY5In0seyJob3RlbF9pZCI6MjI4MzYsIm5hbWUiOiJSYXlvbmcgTWFycmlvdHQgUmVzb3J0ICYgU3BhIiwiYWNjZXB0ZWRfZmlyc3RfbGluZSI6IuKciO+4jyDQotGD0YDRiyDQsiBSYXlvbmcgTWFycmlvdHQgUmVzb3J0ICYgU3BhIiwiaW1hZ2VfdXJsIjoiaHR0cHM6Ly9yYXcuZ2l0aHVidXNlcmNvbnRlbnQuY29tL3B5YXRrb2ZmL21heC1zZWFyY2gtYm90LzQ0ZTM2NmQ3MDFlNjUwMTM3NTJlMDY3OTQzMTM1YWUwYWUzMzc0OTAvLmdpdGh1Yi90cmFmZmljLXB1Ymxpc2gvY29tbWVyY2lhbC13YXZlMy04MC0yMDI2MTExNy8yMjgzNi5wbmciLCJpbWFnZV9zaGEyNTYiOiIzNmRmYzI3YzYxYzViM2VjYTNmZmY5OWFkYmQ3OWY5YmZmYmY0NzhiZmUwNTczYTY3MjAwMGFiMzljMDg1ZDg5In0seyJob3RlbF9pZCI6MzMzMTAsIm5hbWUiOiJIeWF0dCBSZWdlbmN5IFBodWtldCBSZXNvcnQiLCJhY2NlcHRlZF9maXJzdF9saW5lIjoi4pyI77iPINCi0YPRgNGLINCyIEh5YXR0IFJlZ2VuY3kgUGh1a2V0IFJlc29ydCIsImltYWdlX3VybCI6Imh0dHBzOi8vcmF3LmdpdGh1YnVzZXJjb250ZW50LmNvbS9weWF0a29mZi9tYXgtc2VhcmNoLWJvdC80NGUzNjZkNzAxZTY1MDEzNzUyZTA2Nzk0MzEzNWFlMGFlMzM3NDkwLy5naXRodWIvdHJhZmZpYy1wdWJsaXNoL2NvbW1lcmNpYWwtd2F2ZTMtODAtMjAyNjExMTcvMzMzMTAucG5nIiwiaW1hZ2Vfc2hhMjU2IjoiNmRhMzhmZTVhYzY3NzdhZmZmNzgyNzZlMGM0MzNkZWZhZmRkZTU5YjYyNDA4MzM5NzM3ZDcwMjdmOWVjMzdmMiJ9LHsiaG90ZWxfaWQiOjU3NDY2LCJuYW1lIjoiQXZhbmkrIEh1YSBIaW4gUmVzb3J0IiwiYWNjZXB0ZWRfZmlyc3RfbGluZSI6IuKciO+4jyDQotGD0YDRiyDQsiBBdmFuaSsgSHVhIEhpbiBSZXNvcnQiLCJpbWFnZV91cmwiOiJodHRwczovL3Jhdy5naXRodWJ1c2VyY29udGVudC5jb20vcHlhdGtvZmYvbWF4LXNlYXJjaC1ib3QvNDRlMzY2ZDcwMWU2NTAxMzc1MmUwNjc5NDMxMzVhZTBhZTMzNzQ5MC8uZ2l0aHViL3RyYWZmaWMtcHVibGlzaC9jb21tZXJjaWFsLXdhdmUzLTgwLTIwMjYxMTE3LzU3NDY2LnBuZyIsImltYWdlX3NoYTI1NiI6ImE0NmJlYmU5ZmY0ZmM4YjkzZjU5MjA0NTc1ZGE0NWNhOTc4M2FhMDc2Mjk5ZDUzZWMxNTdiZWZjYjdmYTBkZjgifSx7ImhvdGVsX2lkIjo3MDA1MSwibmFtZSI6IklzbGFuZCBFc2NhcGUgYnkgQnVyYXNhcmkiLCJhY2NlcHRlZF9maXJzdF9saW5lIjoi4pyI77iPINCi0YPRgNGLINCyIElzbGFuZCBFc2NhcGUgYnkgQnVyYXNhcmkiLCJpbWFnZV91cmwiOiJodHRwczovL3Jhdy5naXRodWJ1c2VyY29udGVudC5jb20vcHlhdGtvZmYvbWF4LXNlYXJjaC1ib3QvNDRlMzY2ZDcwMWU2NTAxMzc1MmUwNjc5NDMxMzVhZTBhZTMzNzQ5MC8uZ2l0aHViL3RyYWZmaWMtcHVibGlzaC9jb21tZXJjaWFsLXdhdmUzLTgwLTIwMjYxMTE3LzcwMDUxLnBuZyIsImltYWdlX3NoYTI1NiI6Ijc2MDQ2NjY2YTMzYzI5ZmNjZWRhMDQ4NjE2NzJhYzU3YTA1YWZhOTViMDlkZjRkOTM5YTNkNDY4MzM3ZDc3NGIifV0=';
const FEED_EXPORT_INPUT_SHA256='5306de2a273d5889372aefeaad8ebbca84c93e020bd558e3625db583e67aaa1a';
const CANDIDATE_INPUT_B64='W10=';
const CANDIDATE_INPUT_SHA256='4f53cda18c2baa0c0354bb5f9a3ecbe5ed12ab4d8e11ba873c2f11161202b945';
ini_set('display_errors','0');ini_set('log_errors','0');
$out=['operation'=>'commercial-channel-hotel-feeds-20261117-read221','mode'=>'read_bounded_public_channel_post_feed_inputs','ok'=>false,'db_writes'=>0,'provider_calls'=>0,'message_sends'=>0];
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
 insist(count($in)===10&&array_column($in,'hotel_id')===[685,689,697,761,791,3021,22836,33310,57466,70051],'read17_exact_new1_scope');
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
    $retained=json_decode(base64_decode('W3siaG90ZWxfaWQiOjY4NSwicGxhdGZvcm0iOiJ0ZWxlZ3JhbSIsInB1YmxpY2F0aW9uX2lkIjoxODM0LCJ2ZXJzaW9uX2lkIjo3MzYyLCJwb3N0X3VybCI6Imh0dHBzOi8vdC5tZS9hbnlfdG9vdXIvODQwIiwiY2hhbm5lbF9pZCI6Ii0xMDAyMjYyNjY2NjE2IiwibWVzc2FnZV9pZCI6Ijg0MCIsInRleHRfc2hhMjU2IjoiYzBmNDI4ZTliM2VlYmVmZWMzMWRlMmUzNTBhYzc2MmRlNWIwMTY1OTZjMjc4ZWM3NzI4NjVlNWY5NzU3MmRkZiIsInBob3RvX3NoYTI1NiI6ImQzZWU2YTI2NTM5ZDg4ODU1OTFjMzRjNjJmOGJkMTEyN2ZmODQ1NmFkYWFhMGZmMzU1ZGQzOTZhNmQyMTBkZDYiLCJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiIyNTkwZDA5NDIyZDJjYjUwMzNmMWQ3YzY1NjhkYWVjNTY2NTM2NWI0OTk1MzQyZmI5MzhkMWQ1MDQ3Njk3YjY2In0seyJob3RlbF9pZCI6Njg5LCJwbGF0Zm9ybSI6InRlbGVncmFtIiwicHVibGljYXRpb25faWQiOjE4MzUsInZlcnNpb25faWQiOjczNjMsInBvc3RfdXJsIjoiaHR0cHM6Ly90Lm1lL2FueV90b291ci84NDEiLCJjaGFubmVsX2lkIjoiLTEwMDIyNjI2NjY2MTYiLCJtZXNzYWdlX2lkIjoiODQxIiwidGV4dF9zaGEyNTYiOiIyOTQ1MDE2MjE5MTgzZDlkZmJhMTVmZTU2Y2NmNjFhNDQyMzY5ZmIyZTUyNDkyMDBjMWQ0OGZiNDgzZjVmYTQ1IiwicGhvdG9fc2hhMjU2IjoiNjhmOTgyNDQ2ZjNjNThkZGQ5MmRiMzhmZWM3YTk4ZGU5MWFjYzE0YjdkN2M1YmU4NWMwODZlZGVhOTJhM2E4OSIsImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6IjYzMGZjNWY3YjEyNTU3YTY2NjRlNDAxOWYxM2M2YmFkOTNmZDAzYTE0NTQwODk5ZGJmZjQ3ZjRhYWFiY2I4M2MifSx7ImhvdGVsX2lkIjo2OTcsInBsYXRmb3JtIjoidGVsZWdyYW0iLCJwdWJsaWNhdGlvbl9pZCI6MTgzNiwidmVyc2lvbl9pZCI6NzM2NCwicG9zdF91cmwiOiJodHRwczovL3QubWUvYW55X3Rvb3VyLzg0MiIsImNoYW5uZWxfaWQiOiItMTAwMjI2MjY2NjYxNiIsIm1lc3NhZ2VfaWQiOiI4NDIiLCJ0ZXh0X3NoYTI1NiI6IjgzOGUxYjc2MGNlMTg4NTEwNzZhYjQ4NmY1ZDc3YWUxMjhlMmU5ZjY3ZWU5ZGFhNzZmMTIyYzY2MWNhNWE0NTYiLCJwaG90b19zaGEyNTYiOiJkNzJlZGUxZTYyYmViMWZjOGMxMDNiZTJkMTNkMTk0MGU1M2NhM2RhMDVkNWY3MDc4MjlkNTY4ZDRkZTlhNGYwIiwiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiMjU4ZTg4MjdhMWRhZTkyNTA1Yjk0MzMyOTc2MDUwYjcwMzhkNDZkMWM3NzliN2U1MDg1YmI0OGUwMTgxYTg0OSJ9LHsiaG90ZWxfaWQiOjc2MSwicGxhdGZvcm0iOiJ0ZWxlZ3JhbSIsInB1YmxpY2F0aW9uX2lkIjoxODM3LCJ2ZXJzaW9uX2lkIjo3MzY1LCJwb3N0X3VybCI6Imh0dHBzOi8vdC5tZS9hbnlfdG9vdXIvODQzIiwiY2hhbm5lbF9pZCI6Ii0xMDAyMjYyNjY2NjE2IiwibWVzc2FnZV9pZCI6Ijg0MyIsInRleHRfc2hhMjU2IjoiMzM2ODM1YzRjNTI3ZTY0NGMzNDlhOWRjOTliMzg0OTNiODI3NmM5MDE5N2Y1MjBhOTIzYjhlZmQ0OGZkMGI4ZiIsInBob3RvX3NoYTI1NiI6ImM0YjdlYWYyNGQ5Yjg1NjFhMWIxMjZmYWUyYWI5MmIyOTk3MWM4YjY2Mjk4Njk1YWY5NTMxNWRlYWM5Nzg2OTEiLCJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiJhZDc5YzJhYjJlYTc5MGJlNjcyOWRkY2NkMzhmZTc0NDdlZWQzMGEzOWQzYTBlOWEwNGY2ZDdlMzhlMjI5MWQzIn0seyJob3RlbF9pZCI6NzkxLCJwbGF0Zm9ybSI6InRlbGVncmFtIiwicHVibGljYXRpb25faWQiOjE4MzgsInZlcnNpb25faWQiOjczNjYsInBvc3RfdXJsIjoiaHR0cHM6Ly90Lm1lL2FueV90b291ci84NDQiLCJjaGFubmVsX2lkIjoiLTEwMDIyNjI2NjY2MTYiLCJtZXNzYWdlX2lkIjoiODQ0IiwidGV4dF9zaGEyNTYiOiI2YzYxZGJjNGJmOWZkYzZjOWZjY2Q2YjI4NDRkODJkNjE0ZDIzY2QwZjg5MDFlYmY2MjYyOTM5NmZkMmRiMzI4IiwicGhvdG9fc2hhMjU2IjoiYzY1MjIwZmNkOGU3YmM4ZmM0OWQzZTM3Y2IyZmQwYzNlOGJkYmMxYjE3ZmQxMjE0YzRhZGY2N2NkYzMzYTIwYiIsImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6IjNlM2Y1MTM5OWMzN2I3N2E1MDg4NmU1NzBmYzdmNzkzNWYyNzJjMTZiMjk3Yjk0M2FjMWQwNWFhODc5N2ZjZDMifSx7ImhvdGVsX2lkIjozMDIxLCJwbGF0Zm9ybSI6InRlbGVncmFtIiwicHVibGljYXRpb25faWQiOjE4MzksInZlcnNpb25faWQiOjczNjcsInBvc3RfdXJsIjoiaHR0cHM6Ly90Lm1lL2FueV90b291ci84NDUiLCJjaGFubmVsX2lkIjoiLTEwMDIyNjI2NjY2MTYiLCJtZXNzYWdlX2lkIjoiODQ1IiwidGV4dF9zaGEyNTYiOiIxZjJkM2IzM2FlMThhOGM5ZTcyZmVlZTljZjYwOGY1NTcxMDEyZjhmYWUyN2FkYjQxMTJkOTlhMWJiNjZhMmM1IiwicGhvdG9fc2hhMjU2IjoiZTg3N2Y5OGRlY2VjZDY3MGIyYzlkZjdhNGZiMWZkNDY0OTllMTcyNmMzMTA3Y2E2N2I4NzRlYzJiOGY1Yzk2OSIsImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6IjU5NjdkNTIxOTE2N2RhOTkzMTYwNDY2OTFjMGFjYjU1MGE4YjIxNjE3ZjFjNjYwMDA5MzI1NDI2MzE4NWFiYzEifSx7ImhvdGVsX2lkIjoyMjgzNiwicGxhdGZvcm0iOiJ0ZWxlZ3JhbSIsInB1YmxpY2F0aW9uX2lkIjoxODQwLCJ2ZXJzaW9uX2lkIjo3MzY4LCJwb3N0X3VybCI6Imh0dHBzOi8vdC5tZS9hbnlfdG9vdXIvODQ2IiwiY2hhbm5lbF9pZCI6Ii0xMDAyMjYyNjY2NjE2IiwibWVzc2FnZV9pZCI6Ijg0NiIsInRleHRfc2hhMjU2IjoiZGYyNTlkYzZhYmQ1NjBlMzhhNjU5NDdkYzI3MjE1ZjM2YWEzN2E3NWU2OTJlODc5Zjk2Yjk2YTVkMGMwMTIxMSIsInBob3RvX3NoYTI1NiI6IjM2ZGZjMjdjNjFjNWIzZWNhM2ZmZjk5YWRiZDc5ZjliZmZiZjQ3OGJmZTA1NzNhNjcyMDAwYWIzOWMwODVkODkiLCJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiI5OWYxMmE3OTQyYzk1MjFjNzFjOGRjYjMzYzE4NmM0NmZhMTFmNDJhMzJmYzg2Y2QyMmU0NDQ0OWUwM2ExOTE0In0seyJob3RlbF9pZCI6MzMzMTAsInBsYXRmb3JtIjoidGVsZWdyYW0iLCJwdWJsaWNhdGlvbl9pZCI6MTg0MSwidmVyc2lvbl9pZCI6NzM2OSwicG9zdF91cmwiOiJodHRwczovL3QubWUvYW55X3Rvb3VyLzg0NyIsImNoYW5uZWxfaWQiOiItMTAwMjI2MjY2NjYxNiIsIm1lc3NhZ2VfaWQiOiI4NDciLCJ0ZXh0X3NoYTI1NiI6IjVlMzhiMDFkNGNjN2NhNjJhNThkNmNhYTEzMzE5ZDhiOTlmN2RiNWNkODgzZWNhNDZlN2JjMGZlN2FkYjk3YWEiLCJwaG90b19zaGEyNTYiOiI2ZGEzOGZlNWFjNjc3N2FmZmY3ODI3NmUwYzQzM2RlZmFmZGRlNTliNjI0MDgzMzk3MzdkNzAyN2Y5ZWMzN2YyIiwiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiNzQwOGZiNDA3MDhmYTc4MDRlYTE2ZTM2Y2I1NWE0ZTFjY2E1MDY1ZWIwMDg5ZTMyODU2MmZjNWE0ZDJkZjBiMyJ9LHsiaG90ZWxfaWQiOjU3NDY2LCJwbGF0Zm9ybSI6InRlbGVncmFtIiwicHVibGljYXRpb25faWQiOjE4NDIsInZlcnNpb25faWQiOjczNzAsInBvc3RfdXJsIjoiaHR0cHM6Ly90Lm1lL2FueV90b291ci84NDgiLCJjaGFubmVsX2lkIjoiLTEwMDIyNjI2NjY2MTYiLCJtZXNzYWdlX2lkIjoiODQ4IiwidGV4dF9zaGEyNTYiOiJjNWQyNGIwM2YxNTQ2N2RiNzI1MjE4N2I3NDA1ODk1NmI5ZTRjMzNkNWI4MWI2ZDAxYmFiMmIwZmRlYjRjZTAxIiwicGhvdG9fc2hhMjU2IjoiYTQ2YmViZTlmZjRmYzhiOTNmNTkyMDQ1NzVkYTQ1Y2E5NzgzYWEwNzYyOTlkNTNlYzE1N2JlZmNiN2ZhMGRmOCIsImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6ImFkNzRiNDFiOWM1NGRjMzM2YWM4N2UzNGU2ZmRhOTYyNGVkOTg0NzBiOTZlOThiZTE5NDFmNzhmN2Y3YTQyMDgifSx7ImhvdGVsX2lkIjo3MDA1MSwicGxhdGZvcm0iOiJ0ZWxlZ3JhbSIsInB1YmxpY2F0aW9uX2lkIjoxODQzLCJ2ZXJzaW9uX2lkIjo3MzcxLCJwb3N0X3VybCI6Imh0dHBzOi8vdC5tZS9hbnlfdG9vdXIvODQ5IiwiY2hhbm5lbF9pZCI6Ii0xMDAyMjYyNjY2NjE2IiwibWVzc2FnZV9pZCI6Ijg0OSIsInRleHRfc2hhMjU2IjoiMTQ2NWFlZGJhMDRiNjEzMWVlMjg0NGEwNDEyZTc1OWE5M2E5Mjc2ZGYyNWM5YWQ2NDY4M2FlYTI3OTExMTg1ZiIsInBob3RvX3NoYTI1NiI6Ijc2MDQ2NjY2YTMzYzI5ZmNjZWRhMDQ4NjE2NzJhYzU3YTA1YWZhOTViMDlkZjRkOTM5YTNkNDY4MzM3ZDc3NGIiLCJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiI2ZTExYmQyYWU4YmNkYjU2NGVkMzdiMjA1MjZmZTJmOTczNmRmOTJiZGZlNWViMDZjOTI0OTRlZmYzMDc0NDY4In0seyJob3RlbF9pZCI6Njg1LCJwbGF0Zm9ybSI6Im1heCIsInB1YmxpY2F0aW9uX2lkIjoxODQ0LCJ2ZXJzaW9uX2lkIjo3MzcyLCJwb3N0X3VybCI6Imh0dHBzOi8vbWF4LnJ1L2NoYW5uZWxfYW55dG91ci9BYUhxUnFQZkltcyIsImNoYW5uZWxfaWQiOiItODA0OTIyNDQyMDk2ODMiLCJtZXNzYWdlX2lkIjoibWlkLmZmZmZiNmNhZjA2MDZmZWQwMWExZWE0NmEzZGYyMjZiIiwidGV4dF9zaGEyNTYiOiJjMGY0MjhlOWIzZWViZWZlYzMxZGUyZTM1MGFjNzYyZGU1YjAxNjU5NmMyNzhlYzc3Mjg2NWU1Zjk3NTcyZGRmIiwicGhvdG9fc2hhMjU2IjoiZDNlZTZhMjY1MzlkODg4NTU5MWMzNGM2MmY4YmQxMTI3ZmY4NDU2YWRhYWEwZmYzNTVkZDM5NmE2ZDIxMGRkNiIsImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6IjliOWM5M2YwZjRlYmI2OWU3ZDc0MWM4YjFhODJlMmVlOGRjNTQ2YTJjNTUxYjdkMTM2YTJiZmRiM2VjN2U3YTQifSx7ImhvdGVsX2lkIjo2ODksInBsYXRmb3JtIjoibWF4IiwicHVibGljYXRpb25faWQiOjE4NDUsInZlcnNpb25faWQiOjczNzMsInBvc3RfdXJsIjoiaHR0cHM6Ly9tYXgucnUvY2hhbm5lbF9hbnl0b3VyL0FhSHFScTEzZk4wIiwiY2hhbm5lbF9pZCI6Ii04MDQ5MjI0NDIwOTY4MyIsIm1lc3NhZ2VfaWQiOiJtaWQuZmZmZmI2Y2FmMDYwNmZlZDAxYTFlYTQ2YWQ3NzdjZGQiLCJ0ZXh0X3NoYTI1NiI6IjI5NDUwMTYyMTkxODNkOWRmYmExNWZlNTZjY2Y2MWE0NDIzNjlmYjJlNTI0OTIwMGMxZDQ4ZmI0ODNmNWZhNDUiLCJwaG90b19zaGEyNTYiOiI2OGY5ODI0NDZmM2M1OGRkZDkyZGIzOGZlYzdhOThkZTkxYWNjMTRiN2Q3YzViZTg1YzA4NmVkZWE5MmEzYTg5IiwiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiYzU1YWYwZDNmODYxN2E4NzE4ZGRmNWMwMzI5Yjk3NzcxOWYyNWFjOTIyMjg1MjBiZDJhODhiMTNlYTJiZDg0NCJ9LHsiaG90ZWxfaWQiOjY5NywicGxhdGZvcm0iOiJtYXgiLCJwdWJsaWNhdGlvbl9pZCI6MTg0NiwidmVyc2lvbl9pZCI6NzM3NCwicG9zdF91cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FueXRvdXIvQWFIcVJyYmVYLW8iLCJjaGFubmVsX2lkIjoiLTgwNDkyMjQ0MjA5NjgzIiwibWVzc2FnZV9pZCI6Im1pZC5mZmZmYjZjYWYwNjA2ZmVkMDFhMWVhNDZiNmRlNWZlYSIsInRleHRfc2hhMjU2IjoiODM4ZTFiNzYwY2UxODg1MTA3NmFiNDg2ZjVkNzdhZTEyOGUyZTlmNjdlZTlkYWE3NmYxMjJjNjYxY2E1YTQ1NiIsInBob3RvX3NoYTI1NiI6ImQ3MmVkZTFlNjJiZWIxZmM4YzEwM2JlMmQxM2QxOTQwZTUzY2EzZGEwNWQ1ZjcwNzgyOWQ1NjhkNGRlOWE0ZjAiLCJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiI5NTc4YjQ0NDE2ODY4NjAxZTlhMzdlYzRjYzhkODc3ZGJlNjBkZjJiNzJkYmE5OGRjZGE3MTE4YWI2NDZlM2MxIn0seyJob3RlbF9pZCI6NzYxLCJwbGF0Zm9ybSI6Im1heCIsInB1YmxpY2F0aW9uX2lkIjoxODQ3LCJ2ZXJzaW9uX2lkIjo3Mzc1LCJwb3N0X3VybCI6Imh0dHBzOi8vbWF4LnJ1L2NoYW5uZWxfYW55dG91ci9BYUhxUnNFVEFEVSIsImNoYW5uZWxfaWQiOiItODA0OTIyNDQyMDk2ODMiLCJtZXNzYWdlX2lkIjoibWlkLmZmZmZiNmNhZjA2MDZmZWQwMWExZWE0NmMxMTMwMDM1IiwidGV4dF9zaGEyNTYiOiIzMzY4MzVjNGM1MjdlNjQ0YzM0OWE5ZGM5OWIzODQ5M2I4Mjc2YzkwMTk3ZjUyMGE5MjNiOGVmZDQ4ZmQwYjhmIiwicGhvdG9fc2hhMjU2IjoiYzRiN2VhZjI0ZDliODU2MWExYjEyNmZhZTJhYjkyYjI5OTcxYzhiNjYyOTg2OTVhZjk1MzE1ZGVhYzk3ODY5MSIsImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6IjA0OTAxMjI2MTExNDg1OTU2OTAyMTVkYmUxNzJkY2ZjMTY4MTgwNTY2ODY2YjJjOTI2ODcwODYzNzNhM2JmZDAifSx7ImhvdGVsX2lkIjo3OTEsInBsYXRmb3JtIjoibWF4IiwicHVibGljYXRpb25faWQiOjE4NDgsInZlcnNpb25faWQiOjczNzYsInBvc3RfdXJsIjoiaHR0cHM6Ly9tYXgucnUvY2hhbm5lbF9hbnl0b3VyL0FhSHFSc3BrY1VBIiwiY2hhbm5lbF9pZCI6Ii04MDQ5MjI0NDIwOTY4MyIsIm1lc3NhZ2VfaWQiOiJtaWQuZmZmZmI2Y2FmMDYwNmZlZDAxYTFlYTQ2Y2E2NDcxNDAiLCJ0ZXh0X3NoYTI1NiI6IjZjNjFkYmM0YmY5ZmRjNmM5ZmNjZDZiMjg0NGQ4MmQ2MTRkMjNjZDBmODkwMWViZjYyNjI5Mzk2ZmQyZGIzMjgiLCJwaG90b19zaGEyNTYiOiJjNjUyMjBmY2Q4ZTdiYzhmYzQ5ZDNlMzdjYjJmZDBjM2U4YmRiYzFiMTdmZDEyMTRjNGFkZjY3Y2RjMzNhMjBiIiwiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiNjEyNDQ5NDUyOTIwNzEwYmI0ZGI1OGFhZjlkY2RlMzZmNTc1YzVmY2E0ZmVhNGNiNDMwNTIyZWI5ZWI5N2Y1MSJ9LHsiaG90ZWxfaWQiOjMwMjEsInBsYXRmb3JtIjoibWF4IiwicHVibGljYXRpb25faWQiOjE4NDksInZlcnNpb25faWQiOjczNzcsInBvc3RfdXJsIjoiaHR0cHM6Ly9tYXgucnUvY2hhbm5lbF9hbnl0b3VyL0FhSHFSdFEzRXJjIiwiY2hhbm5lbF9pZCI6Ii04MDQ5MjI0NDIwOTY4MyIsIm1lc3NhZ2VfaWQiOiJtaWQuZmZmZmI2Y2FmMDYwNmZlZDAxYTFlYTQ2ZDQzNzEyYjciLCJ0ZXh0X3NoYTI1NiI6IjFmMmQzYjMzYWUxOGE4YzllNzJmZWVlOWNmNjA4ZjU1NzEwMTJmOGZhZTI3YWRiNDExMmQ5OWExYmI2NmEyYzUiLCJwaG90b19zaGEyNTYiOiJlODc3Zjk4ZGVjZWNkNjcwYjJjOWRmN2E0ZmIxZmQ0NjQ5OWUxNzI2YzMxMDdjYTY3Yjg3NGVjMmI4ZjVjOTY5IiwiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiOTgxNGE0ZWYxZjkyMWU3YTgyNWQ0MzBhYTYwYWUxNmRlZmZiMjZkMDZjMThkMWJlZWM3Yzc1Y2I2ZDczZDg0NSJ9LHsiaG90ZWxfaWQiOjIyODM2LCJwbGF0Zm9ybSI6Im1heCIsInB1YmxpY2F0aW9uX2lkIjoxODUwLCJ2ZXJzaW9uX2lkIjo3Mzc4LCJwb3N0X3VybCI6Imh0dHBzOi8vbWF4LnJ1L2NoYW5uZWxfYW55dG91ci9BYUhxUnQzOFh1MCIsImNoYW5uZWxfaWQiOiItODA0OTIyNDQyMDk2ODMiLCJtZXNzYWdlX2lkIjoibWlkLmZmZmZiNmNhZjA2MDZmZWQwMWExZWE0NmRkZmM1ZWVkIiwidGV4dF9zaGEyNTYiOiJkZjI1OWRjNmFiZDU2MGUzOGE2NTk0N2RjMjcyMTVmMzZhYTM3YTc1ZTY5MmU4NzlmOTZiOTZhNWQwYzAxMjExIiwicGhvdG9fc2hhMjU2IjoiMzZkZmMyN2M2MWM1YjNlY2EzZmZmOTlhZGJkNzlmOWJmZmJmNDc4YmZlMDU3M2E2NzIwMDBhYjM5YzA4NWQ4OSIsImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6Ijg4MjRiOThmN2EzYWFiOGQwZDhjZTcyZjkyZWY1MjU3NzgyYTg2MjU1NjY5NThhNWFmYzY3YzM4Nzc4MzNjMTkifSx7ImhvdGVsX2lkIjozMzMxMCwicGxhdGZvcm0iOiJtYXgiLCJwdWJsaWNhdGlvbl9pZCI6MTg1MSwidmVyc2lvbl9pZCI6NzM3OSwicG9zdF91cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FueXRvdXIvQWFIcVJ1ZkNEcWciLCJjaGFubmVsX2lkIjoiLTgwNDkyMjQ0MjA5NjgzIiwibWVzc2FnZV9pZCI6Im1pZC5mZmZmYjZjYWYwNjA2ZmVkMDFhMWVhNDZlN2MyMGVhOCIsInRleHRfc2hhMjU2IjoiNWUzOGIwMWQ0Y2M3Y2E2MmE1OGQ2Y2FhMTMzMTlkOGI5OWY3ZGI1Y2Q4ODNlY2E0NmU3YmMwZmU3YWRiOTdhYSIsInBob3RvX3NoYTI1NiI6IjZkYTM4ZmU1YWM2Nzc3YWZmZjc4Mjc2ZTBjNDMzZGVmYWZkZGU1OWI2MjQwODMzOTczN2Q3MDI3ZjllYzM3ZjIiLCJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiJlN2Y4ZWZkYjYxNGMxNDQ4NWJjMjNlNGFlOTliZjA4MGU4YTBlOGZiYzI4YTc2M2I2NDFmOTQ4NzhiMzU2MTRlIn0seyJob3RlbF9pZCI6NTc0NjYsInBsYXRmb3JtIjoibWF4IiwicHVibGljYXRpb25faWQiOjE4NTIsInZlcnNpb25faWQiOjczODAsInBvc3RfdXJsIjoiaHR0cHM6Ly9tYXgucnUvY2hhbm5lbF9hbnl0b3VyL0FhSHFSdkZPWEgwIiwiY2hhbm5lbF9pZCI6Ii04MDQ5MjI0NDIwOTY4MyIsIm1lc3NhZ2VfaWQiOiJtaWQuZmZmZmI2Y2FmMDYwNmZlZDAxYTFlYTQ2ZjE0ZTVjN2QiLCJ0ZXh0X3NoYTI1NiI6ImM1ZDI0YjAzZjE1NDY3ZGI3MjUyMTg3Yjc0MDU4OTU2YjllNGMzM2Q1YjgxYjZkMDFiYWIyYjBmZGViNGNlMDEiLCJwaG90b19zaGEyNTYiOiJhNDZiZWJlOWZmNGZjOGI5M2Y1OTIwNDU3NWRhNDVjYTk3ODNhYTA3NjI5OWQ1M2VjMTU3YmVmY2I3ZmEwZGY4IiwiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiMDA5N2ZlNjg5YzliYTQzYTZjMTRlZTBlM2FkNDI2MDk3OGUwZTgwYTY3OGI1M2FmMDdiNTU5MGJhZGVhNjRkZSJ9LHsiaG90ZWxfaWQiOjcwMDUxLCJwbGF0Zm9ybSI6Im1heCIsInB1YmxpY2F0aW9uX2lkIjoxODUzLCJ2ZXJzaW9uX2lkIjo3MzgxLCJwb3N0X3VybCI6Imh0dHBzOi8vbWF4LnJ1L2NoYW5uZWxfYW55dG91ci9BYUhxUnZ5ck1aSSIsImNoYW5uZWxfaWQiOiItODA0OTIyNDQyMDk2ODMiLCJtZXNzYWdlX2lkIjoibWlkLmZmZmZiNmNhZjA2MDZmZWQwMWExZWE0NmZjYWIzMTkyIiwidGV4dF9zaGEyNTYiOiIxNDY1YWVkYmEwNGI2MTMxZWUyODQ0YTA0MTJlNzU5YTkzYTkyNzZkZjI1YzlhZDY0NjgzYWVhMjc5MTExODVmIiwicGhvdG9fc2hhMjU2IjoiNzYwNDY2NjZhMzNjMjlmY2NlZGEwNDg2MTY3MmFjNTdhMDVhZmE5NWIwOWRmNGQ5MzlhM2Q0NjgzMzdkNzc0YiIsImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6IjI4NTRmNzhlNDI2MDE0ZjY1MDBiMzUzZmI4MmNmNDE4N2JhYjkwMDdjMmE2NThiZDI4ZTQxNDdkMzYxY2I5YzUifV0=',true),true,64,JSON_THROW_ON_ERROR);
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

