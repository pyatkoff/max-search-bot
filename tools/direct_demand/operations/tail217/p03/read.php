<?php
declare(strict_types=1);
const EXPECTED_INSTALLED_SHA='cc017ee5bb669f4c7c13efc91d5c5812a50e55f2';
const FEED_EXPORT_INPUT_B64='W3siaG90ZWxfaWQiOjc4MzEsIm5hbWUiOiJBbmFudGFyYSBSaXZlcnNpZGUgQmFuZ2tvayBSZXNvcnQiLCJhY2NlcHRlZF9maXJzdF9saW5lIjoi4pyI77iPINCi0YPRgNGLINCyIEFuYW50YXJhIFJpdmVyc2lkZSBCYW5na29rIFJlc29ydCIsImltYWdlX3VybCI6Imh0dHBzOi8vcmF3LmdpdGh1YnVzZXJjb250ZW50LmNvbS9weWF0a29mZi9tYXgtc2VhcmNoLWJvdC8xNTY1NzkxOGVjZGI1ODZkNDYyMjMyZWY3MDRlMmYxMzA2MTE2YTdmLy5naXRodWIvdHJhZmZpYy1wdWJsaXNoL2NvbW1lcmNpYWwtZXhwYW5zaW9uMTAwLTIwMjYxMTE3LXRoaXJkMTAvNzgzMS5wbmciLCJpbWFnZV9zaGEyNTYiOiJhYmZkYTMyNGJiY2ZhMzNkYjVhMzk4OWNhZjBmNjM0ZGM2OWJiOGU4ZmFlM2QzOTMzZWNlMDE2ZjFmYTg5YjliIn0seyJob3RlbF9pZCI6Mjk0OCwibmFtZSI6IkFuYW50YXJhIEh1YSBIaW4gUmVzb3J0IiwiYWNjZXB0ZWRfZmlyc3RfbGluZSI6IuKciO+4jyDQotGD0YDRiyDQsiBBbmFudGFyYSBIdWEgSGluIFJlc29ydCIsImltYWdlX3VybCI6Imh0dHBzOi8vcmF3LmdpdGh1YnVzZXJjb250ZW50LmNvbS9weWF0a29mZi9tYXgtc2VhcmNoLWJvdC8xNTY1NzkxOGVjZGI1ODZkNDYyMjMyZWY3MDRlMmYxMzA2MTE2YTdmLy5naXRodWIvdHJhZmZpYy1wdWJsaXNoL2NvbW1lcmNpYWwtZXhwYW5zaW9uMTAwLTIwMjYxMTE3LXRoaXJkMTAvMjk0OC5wbmciLCJpbWFnZV9zaGEyNTYiOiIxOGEzOGYzNjA2NjVlNDkyM2IxOTAxMDU2YjVkNzQwNzAyNzVhZmEzMGY4NjJmYmUxMTYzOTg4YWQ3MjdmZDdjIn0seyJob3RlbF9pZCI6NTc1MzYsIm5hbWUiOiJBbCBCYWxlZWQgUmVzb3J0IFNhbGFsYWggYnkgQW5hbnRhcmEiLCJhY2NlcHRlZF9maXJzdF9saW5lIjoi4pyI77iPINCi0YPRgNGLINCyIEFsIEJhbGVlZCBSZXNvcnQgU2FsYWxhaCBieSBBbmFudGFyYSIsImltYWdlX3VybCI6Imh0dHBzOi8vcmF3LmdpdGh1YnVzZXJjb250ZW50LmNvbS9weWF0a29mZi9tYXgtc2VhcmNoLWJvdC8xNTY1NzkxOGVjZGI1ODZkNDYyMjMyZWY3MDRlMmYxMzA2MTE2YTdmLy5naXRodWIvdHJhZmZpYy1wdWJsaXNoL2NvbW1lcmNpYWwtZXhwYW5zaW9uMTAwLTIwMjYxMTE3LXRoaXJkMTAvNTc1MzYucG5nIiwiaW1hZ2Vfc2hhMjU2IjoiZWI1ODg5MTUyOWM4M2FlODcwYWFhNDZiZWI0NGRkMDU4ZWFhN2E1NWJmOWJiYjc2M2ZmOTMxYjllOTdhZjE4MCJ9LHsiaG90ZWxfaWQiOjYxMjQxLCJuYW1lIjoiQW5hbnRhcmEgQWwgSmFiYWwgQWwgQWtoZGFyIFJlc29ydCIsImFjY2VwdGVkX2ZpcnN0X2xpbmUiOiLinIjvuI8g0KLRg9GA0Ysg0LIgQW5hbnRhcmEgQWwgSmFiYWwgQWwgQWtoZGFyIFJlc29ydCIsImltYWdlX3VybCI6Imh0dHBzOi8vcmF3LmdpdGh1YnVzZXJjb250ZW50LmNvbS9weWF0a29mZi9tYXgtc2VhcmNoLWJvdC8xNTY1NzkxOGVjZGI1ODZkNDYyMjMyZWY3MDRlMmYxMzA2MTE2YTdmLy5naXRodWIvdHJhZmZpYy1wdWJsaXNoL2NvbW1lcmNpYWwtZXhwYW5zaW9uMTAwLTIwMjYxMTE3LXRoaXJkMTAvNjEyNDEucG5nIiwiaW1hZ2Vfc2hhMjU2IjoiYWFhNTIzNzkwOGJkZTdmYWQ1YTIxMmU0MDhlNzQxYTg1OGMxYTZhODk0NTQ3YTA0MmNiMmJmY2JkMDM1MWNjMCJ9LHsiaG90ZWxfaWQiOjE3NDI0LCJuYW1lIjoiRm91ciBTZWFzb25zIEhvdGVsIElzdGFuYnVsIGF0IHRoZSBCb3NwaG9ydXMiLCJhY2NlcHRlZF9maXJzdF9saW5lIjoi4pyI77iPINCi0YPRgNGLINCyIEZvdXIgU2Vhc29ucyBIb3RlbCBJc3RhbmJ1bCBhdCB0aGUgQm9zcGhvcnVzIiwiaW1hZ2VfdXJsIjoiaHR0cHM6Ly9yYXcuZ2l0aHVidXNlcmNvbnRlbnQuY29tL3B5YXRrb2ZmL21heC1zZWFyY2gtYm90LzE1NjU3OTE4ZWNkYjU4NmQ0NjIyMzJlZjcwNGUyZjEzMDYxMTZhN2YvLmdpdGh1Yi90cmFmZmljLXB1Ymxpc2gvY29tbWVyY2lhbC1leHBhbnNpb24xMDAtMjAyNjExMTctdGhpcmQxMC8xNzQyNC5wbmciLCJpbWFnZV9zaGEyNTYiOiIxOGZjZWNlNTViYWU4NWE1MDY1NmRiNGM1YjIxOGVjZTE4Nzc5ODZiMjIyZTFjOWY1NjhmMDNhMjlmMjc2ZjBmIn0seyJob3RlbF9pZCI6MTc0MjMsIm5hbWUiOiJGb3VyIFNlYXNvbnMgSG90ZWwgSXN0YW5idWwgYXQgU3VsdGFuYWhtZXQiLCJhY2NlcHRlZF9maXJzdF9saW5lIjoi4pyI77iPINCi0YPRgNGLINCyIEZvdXIgU2Vhc29ucyBIb3RlbCBJc3RhbmJ1bCBhdCBTdWx0YW5haG1ldCIsImltYWdlX3VybCI6Imh0dHBzOi8vcmF3LmdpdGh1YnVzZXJjb250ZW50LmNvbS9weWF0a29mZi9tYXgtc2VhcmNoLWJvdC8xNTY1NzkxOGVjZGI1ODZkNDYyMjMyZWY3MDRlMmYxMzA2MTE2YTdmLy5naXRodWIvdHJhZmZpYy1wdWJsaXNoL2NvbW1lcmNpYWwtZXhwYW5zaW9uMTAwLTIwMjYxMTE3LXRoaXJkMTAvMTc0MjMucG5nIiwiaW1hZ2Vfc2hhMjU2IjoiMjI3NWI2MGVjMTJhMzgyNzc0Y2NhZTBiMTc5NTM2MjNhNzhiNWY1MDFlZjUwNDc3Mjc4ZWY4YWQzZmUwZjlhOSJ9LHsiaG90ZWxfaWQiOjIzNzE4LCJuYW1lIjoiQW5hbnRhcmEgSG9pIEFuIFJlc29ydCIsImFjY2VwdGVkX2ZpcnN0X2xpbmUiOiLinIjvuI8g0KLRg9GA0Ysg0LIgQW5hbnRhcmEgSG9pIEFuIFJlc29ydCIsImltYWdlX3VybCI6Imh0dHBzOi8vcmF3LmdpdGh1YnVzZXJjb250ZW50LmNvbS9weWF0a29mZi9tYXgtc2VhcmNoLWJvdC8xNTY1NzkxOGVjZGI1ODZkNDYyMjMyZWY3MDRlMmYxMzA2MTE2YTdmLy5naXRodWIvdHJhZmZpYy1wdWJsaXNoL2NvbW1lcmNpYWwtZXhwYW5zaW9uMTAwLTIwMjYxMTE3LXRoaXJkMTAvMjM3MTgucG5nIiwiaW1hZ2Vfc2hhMjU2IjoiOTJkZDdjNDgxNDdlYjJiYzZjNGY1OTBkNzNjOTJmYTkzYzc0YjM5Y2I4ZjVjMTVjYWUxZWJmYmRmMmMzZmEwMiJ9LHsiaG90ZWxfaWQiOjIyOTQwLCJuYW1lIjoiQW5hbnRhcmEgU2lyIEJhbmkgWWFzIElzbGFuZCBBbCBZYW1tIFZpbGxhIFJlc29ydCIsImFjY2VwdGVkX2ZpcnN0X2xpbmUiOiLinIjvuI8g0KLRg9GA0Ysg0LIgQW5hbnRhcmEgU2lyIEJhbmkgWWFzIElzbGFuZCBBbCBZYW1tIFZpbGxhIFJlc29ydCIsImltYWdlX3VybCI6Imh0dHBzOi8vcmF3LmdpdGh1YnVzZXJjb250ZW50LmNvbS9weWF0a29mZi9tYXgtc2VhcmNoLWJvdC8xNTY1NzkxOGVjZGI1ODZkNDYyMjMyZWY3MDRlMmYxMzA2MTE2YTdmLy5naXRodWIvdHJhZmZpYy1wdWJsaXNoL2NvbW1lcmNpYWwtZXhwYW5zaW9uMTAwLTIwMjYxMTE3LXRoaXJkMTAvMjI5NDAucG5nIiwiaW1hZ2Vfc2hhMjU2IjoiYWM0NTAxNzQ5YWQ0NzliMzQ5Yzg2ZDU2YzkwMTdlZmNjMWRmOTMzY2QxMjM5YTgxODQwYzQzM2RjZjE3ZjgzNCJ9LHsiaG90ZWxfaWQiOjk3MDAxLCJuYW1lIjoiRm91ciBTZWFzb25zIEhvdGVsIEFsZXhhbmRyaWEgYXQgU2FuIFN0ZWZhbm8iLCJhY2NlcHRlZF9maXJzdF9saW5lIjoi4pyI77iPINCi0YPRgNGLINCyIEZvdXIgU2Vhc29ucyBIb3RlbCBBbGV4YW5kcmlhIGF0IFNhbiBTdGVmYW5vIiwiaW1hZ2VfdXJsIjoiaHR0cHM6Ly9yYXcuZ2l0aHVidXNlcmNvbnRlbnQuY29tL3B5YXRrb2ZmL21heC1zZWFyY2gtYm90LzE1NjU3OTE4ZWNkYjU4NmQ0NjIyMzJlZjcwNGUyZjEzMDYxMTZhN2YvLmdpdGh1Yi90cmFmZmljLXB1Ymxpc2gvY29tbWVyY2lhbC1leHBhbnNpb24xMDAtMjAyNjExMTctdGhpcmQxMC85NzAwMS5wbmciLCJpbWFnZV9zaGEyNTYiOiI0YWUwNTdjMjg5ZTk4ZDY4NmNiZTdmYTExMzZiODRiNzlhNDBlOWVlMjY5OGRmMzBhN2FkZDFhOGUxOGMyYWI0In0seyJob3RlbF9pZCI6NzQ1MDksIm5hbWUiOiJGb3VyIFNlYXNvbnMgSG90ZWwgQ2Fpcm8gYXQgTmlsZSBQbGF6YSIsImFjY2VwdGVkX2ZpcnN0X2xpbmUiOiLinIjvuI8g0KLRg9GA0Ysg0LIgRm91ciBTZWFzb25zIEhvdGVsIENhaXJvIGF0IE5pbGUgUGxhemEiLCJpbWFnZV91cmwiOiJodHRwczovL3Jhdy5naXRodWJ1c2VyY29udGVudC5jb20vcHlhdGtvZmYvbWF4LXNlYXJjaC1ib3QvMTU2NTc5MThlY2RiNTg2ZDQ2MjIzMmVmNzA0ZTJmMTMwNjExNmE3Zi8uZ2l0aHViL3RyYWZmaWMtcHVibGlzaC9jb21tZXJjaWFsLWV4cGFuc2lvbjEwMC0yMDI2MTExNy10aGlyZDEwLzc0NTA5LnBuZyIsImltYWdlX3NoYTI1NiI6IjFkZTU1N2YyMDQxZmY5ZWZkMWYzN2QzZWMzODRkM2NmZDVlOWM5MjUxMjk4ZGJmMjQzNjU4NjdiOTg2YzIxNTQifV0=';
const FEED_EXPORT_INPUT_SHA256='48736e110e510207dc370a0e0b36dfc3b6e4b1e184713e3b79483ac27ce8391e';
const CANDIDATE_INPUT_B64='W10=';
const CANDIDATE_INPUT_SHA256='4f53cda18c2baa0c0354bb5f9a3ecbe5ed12ab4d8e11ba873c2f11161202b945';
ini_set('display_errors','0');ini_set('log_errors','0');
$out=['operation'=>'commercial-channel-hotel-feeds-20261117-read203','mode'=>'read_bounded_public_channel_post_feed_inputs','ok'=>false,'db_writes'=>0,'provider_calls'=>0,'message_sends'=>0];
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
 insist(count($in)===10&&array_column($in,'hotel_id')===[7831,2948,57536,61241,17424,17423,23718,22940,97001,74509],'read17_exact_new1_scope');
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
    $retained=json_decode(base64_decode('W3siaG90ZWxfaWQiOjc4MzEsInBsYXRmb3JtIjoidGVsZWdyYW0iLCJwdWJsaWNhdGlvbl9pZCI6MTUzOSwidmVyc2lvbl9pZCI6NzA2NywicG9zdF91cmwiOiJodHRwczovL3QubWUvYW55X3Rvb3VyLzY5NSIsImNoYW5uZWxfaWQiOiItMTAwMjI2MjY2NjYxNiIsIm1lc3NhZ2VfaWQiOiI2OTUiLCJ0ZXh0X3NoYTI1NiI6ImQ4YTQxYTA0ZWJhYTBlOWViYTgyNzljNjk0ODI2ZGRlNmNjOTUyODI1OWMwOGE2ZDk5NmE1ZGM5NGM2NjhiNjkiLCJwaG90b19zaGEyNTYiOiJhYmZkYTMyNGJiY2ZhMzNkYjVhMzk4OWNhZjBmNjM0ZGM2OWJiOGU4ZmFlM2QzOTMzZWNlMDE2ZjFmYTg5YjliIiwiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiYTc1OTVjNmZjZDFlODM3ZGE2MGQxZjJkYmRhZjIxMDkyZjBiZjhiZjQzZGVlMjU4YmNiNDg3MThmNmI4NTVkZCJ9LHsiaG90ZWxfaWQiOjI5NDgsInBsYXRmb3JtIjoidGVsZWdyYW0iLCJwdWJsaWNhdGlvbl9pZCI6MTU0MCwidmVyc2lvbl9pZCI6NzA2OCwicG9zdF91cmwiOiJodHRwczovL3QubWUvYW55X3Rvb3VyLzY5NiIsImNoYW5uZWxfaWQiOiItMTAwMjI2MjY2NjYxNiIsIm1lc3NhZ2VfaWQiOiI2OTYiLCJ0ZXh0X3NoYTI1NiI6IjRkYWZmN2UzYWZkNmMyMTE4NzgyZDIzNjMwYTQzZjM5YmZlMmQ2MTM3MjY5OTRjMWM3ZDliMTBjMzFkMzJhOWEiLCJwaG90b19zaGEyNTYiOiIxOGEzOGYzNjA2NjVlNDkyM2IxOTAxMDU2YjVkNzQwNzAyNzVhZmEzMGY4NjJmYmUxMTYzOTg4YWQ3MjdmZDdjIiwiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiYzZhYjUwZDU1NGIzOTAzNzFlNGRmNWU3ZjcxNTI3YzYzNWNlOTUxNGQ1NDM3OGRhMThhMjBiZWEwNjIwYWYyMSJ9LHsiaG90ZWxfaWQiOjU3NTM2LCJwbGF0Zm9ybSI6InRlbGVncmFtIiwicHVibGljYXRpb25faWQiOjE1NDEsInZlcnNpb25faWQiOjcwNjksInBvc3RfdXJsIjoiaHR0cHM6Ly90Lm1lL2FueV90b291ci82OTciLCJjaGFubmVsX2lkIjoiLTEwMDIyNjI2NjY2MTYiLCJtZXNzYWdlX2lkIjoiNjk3IiwidGV4dF9zaGEyNTYiOiIwNGY0OTg2M2YyNzY5ZTU1NGIyOWZkYjk2ZmI0MGYxZDcxYTc5NWRlMGQ4OTIwN2RmNzYxMmE4YTBhZjg4ODllIiwicGhvdG9fc2hhMjU2IjoiZWI1ODg5MTUyOWM4M2FlODcwYWFhNDZiZWI0NGRkMDU4ZWFhN2E1NWJmOWJiYjc2M2ZmOTMxYjllOTdhZjE4MCIsImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6IjM0MDYxY2M4MzViZmJiZTZhOGVkMjRiNDBkOGIwMTRjOTQ4ODc0ZDMzZmI0OGYyMzQ0Yjg4ZTU3OTU0NDhiZTkifSx7ImhvdGVsX2lkIjo2MTI0MSwicGxhdGZvcm0iOiJ0ZWxlZ3JhbSIsInB1YmxpY2F0aW9uX2lkIjoxNTQyLCJ2ZXJzaW9uX2lkIjo3MDcwLCJwb3N0X3VybCI6Imh0dHBzOi8vdC5tZS9hbnlfdG9vdXIvNjk4IiwiY2hhbm5lbF9pZCI6Ii0xMDAyMjYyNjY2NjE2IiwibWVzc2FnZV9pZCI6IjY5OCIsInRleHRfc2hhMjU2IjoiZDc2Nzc0N2YyOWE1YTU1MWMxNDYwZTYwZDQzODJhNGY5ZDNiMzQ3NWE0YTMzYjcxMTE2YTk3YjkwNTdjZTlhOSIsInBob3RvX3NoYTI1NiI6ImFhYTUyMzc5MDhiZGU3ZmFkNWEyMTJlNDA4ZTc0MWE4NThjMWE2YTg5NDU0N2EwNDJjYjJiZmNiZDAzNTFjYzAiLCJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiJjNTljZGQ2MzZlOTNmMzRjODEzYzgyMDM2NjVhZTAzOWRlY2Q0ZDUyNjJiZGNmNzMyMmI1MmE1MDRmYWM5MzdiIn0seyJob3RlbF9pZCI6MTc0MjQsInBsYXRmb3JtIjoidGVsZWdyYW0iLCJwdWJsaWNhdGlvbl9pZCI6MTU0MywidmVyc2lvbl9pZCI6NzA3MSwicG9zdF91cmwiOiJodHRwczovL3QubWUvYW55X3Rvb3VyLzY5OSIsImNoYW5uZWxfaWQiOiItMTAwMjI2MjY2NjYxNiIsIm1lc3NhZ2VfaWQiOiI2OTkiLCJ0ZXh0X3NoYTI1NiI6ImE2Zjg1ZjU1MjUzZDYzYTU4ZjNlZDdmOGMwZWVkMjlkZjMzOTFmMWRhMmEzNDFmYTczZmMxOGMxZjg0OWE5MzMiLCJwaG90b19zaGEyNTYiOiIxOGZjZWNlNTViYWU4NWE1MDY1NmRiNGM1YjIxOGVjZTE4Nzc5ODZiMjIyZTFjOWY1NjhmMDNhMjlmMjc2ZjBmIiwiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiOTQ1NmY0MWVmM2UzMjkwNzQwYTRkNzUxOTJhNWYyMzBjMjAxYmYzNzU2YzhmMDk4ZTA5NmIyODIyM2QyYTFmNCJ9LHsiaG90ZWxfaWQiOjE3NDIzLCJwbGF0Zm9ybSI6InRlbGVncmFtIiwicHVibGljYXRpb25faWQiOjE1NDQsInZlcnNpb25faWQiOjcwNzIsInBvc3RfdXJsIjoiaHR0cHM6Ly90Lm1lL2FueV90b291ci83MDAiLCJjaGFubmVsX2lkIjoiLTEwMDIyNjI2NjY2MTYiLCJtZXNzYWdlX2lkIjoiNzAwIiwidGV4dF9zaGEyNTYiOiI4NTFmMjFmNjIxYWUwNjk0ZWE4YWQ3ODJhNGU1ZWI1Njg3NjE3YWQ3NWU5NGU5NjZlYmUwMTVhNTE1NmYxZDU0IiwicGhvdG9fc2hhMjU2IjoiMjI3NWI2MGVjMTJhMzgyNzc0Y2NhZTBiMTc5NTM2MjNhNzhiNWY1MDFlZjUwNDc3Mjc4ZWY4YWQzZmUwZjlhOSIsImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6ImY1NjA0ZGMxMTAzNTZlZjczNzVkOWRiYTVkMmY1ZGUzOTk2OGJjZDcyMWQyMGM0NDc5YWU2MDgwZTUxNjBhZjkifSx7ImhvdGVsX2lkIjoyMzcxOCwicGxhdGZvcm0iOiJ0ZWxlZ3JhbSIsInB1YmxpY2F0aW9uX2lkIjoxNTQ1LCJ2ZXJzaW9uX2lkIjo3MDczLCJwb3N0X3VybCI6Imh0dHBzOi8vdC5tZS9hbnlfdG9vdXIvNzAxIiwiY2hhbm5lbF9pZCI6Ii0xMDAyMjYyNjY2NjE2IiwibWVzc2FnZV9pZCI6IjcwMSIsInRleHRfc2hhMjU2IjoiZDZhOWM2OGM4NDk4MWYzOWIwZDE3YjlmYzc3NzNkNTRkY2VhYzkxNjY4OWM4ZThjNWIxYjRlMjY5ZjIyZmEwOSIsInBob3RvX3NoYTI1NiI6IjkyZGQ3YzQ4MTQ3ZWIyYmM2YzRmNTkwZDczYzkyZmE5M2M3NGIzOWNiOGY1YzE1Y2FlMWViZmJkZjJjM2ZhMDIiLCJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiIwYjI1YzEyMGE4MTg3ODJjMjQ2MzMzY2U2OGMzZmY4NTEyNTdlOGUwNDRmMmZkNDg5YTI3YzFmYzM1OTlkZTM4In0seyJob3RlbF9pZCI6MjI5NDAsInBsYXRmb3JtIjoidGVsZWdyYW0iLCJwdWJsaWNhdGlvbl9pZCI6MTU0NiwidmVyc2lvbl9pZCI6NzA3NCwicG9zdF91cmwiOiJodHRwczovL3QubWUvYW55X3Rvb3VyLzcwMiIsImNoYW5uZWxfaWQiOiItMTAwMjI2MjY2NjYxNiIsIm1lc3NhZ2VfaWQiOiI3MDIiLCJ0ZXh0X3NoYTI1NiI6IjFjZjBiZmRkMDM5ZDA1MWYwNTM3NGI2NmQ3OGYyYmNhYzAwODlmMTQ4MzRjNjE4OGY0MWMzODRmZTc5MDgwODkiLCJwaG90b19zaGEyNTYiOiJhYzQ1MDE3NDlhZDQ3OWIzNDljODZkNTZjOTAxN2VmY2MxZGY5MzNjZDEyMzlhODE4NDBjNDMzZGNmMTdmODM0IiwiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiZGE5MTMwMzI1ZWIxZGNmZTc3YjA0OTlkMjEyNTIxNmU2ZmU2OWVkNWQ4Y2RkZmFlNjA0OGVlN2Q0YWNiZTYzNSJ9LHsiaG90ZWxfaWQiOjk3MDAxLCJwbGF0Zm9ybSI6InRlbGVncmFtIiwicHVibGljYXRpb25faWQiOjE1NDcsInZlcnNpb25faWQiOjcwNzUsInBvc3RfdXJsIjoiaHR0cHM6Ly90Lm1lL2FueV90b291ci83MDMiLCJjaGFubmVsX2lkIjoiLTEwMDIyNjI2NjY2MTYiLCJtZXNzYWdlX2lkIjoiNzAzIiwidGV4dF9zaGEyNTYiOiI0NDRmOTdlYzkwYmI3MDg2ODQ3ZTJlNTcwNmE2YzYxYzQyNjkwZWE5Yjk2N2JlYjJlZmY5ZDliYjcwZDUwMGU0IiwicGhvdG9fc2hhMjU2IjoiNGFlMDU3YzI4OWU5OGQ2ODZjYmU3ZmExMTM2Yjg0Yjc5YTQwZTllZTI2OThkZjMwYTdhZGQxYThlMThjMmFiNCIsImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6ImFhYzE3ZGRkZTliZWQxMzk2OWFiMTU5ZThkNzM4MTc2NWFhOTY4ZjA2M2M5ZjlkYTk5NDJlZGI0OTNmNTRhOTcifSx7ImhvdGVsX2lkIjo3NDUwOSwicGxhdGZvcm0iOiJ0ZWxlZ3JhbSIsInB1YmxpY2F0aW9uX2lkIjoxNTQ4LCJ2ZXJzaW9uX2lkIjo3MDc2LCJwb3N0X3VybCI6Imh0dHBzOi8vdC5tZS9hbnlfdG9vdXIvNzA0IiwiY2hhbm5lbF9pZCI6Ii0xMDAyMjYyNjY2NjE2IiwibWVzc2FnZV9pZCI6IjcwNCIsInRleHRfc2hhMjU2IjoiNDhjMjkyYzU1MWExZWNlZThkNjU4ZWFiOTUxOGMzMjdmYmQ2NWIxMGIwNzIzZDkwZjBjYWMyM2U2NDIzODNhYiIsInBob3RvX3NoYTI1NiI6IjFkZTU1N2YyMDQxZmY5ZWZkMWYzN2QzZWMzODRkM2NmZDVlOWM5MjUxMjk4ZGJmMjQzNjU4NjdiOTg2YzIxNTQiLCJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiJkMmU4ZTg0YjViMDY4ZjM0YzBhMzJhYWM2NDQ5YTM5ZTUyN2Y3M2UzYjAwMDQ4N2QyNTgyZGExMTRhYTIwOTdiIn0seyJob3RlbF9pZCI6NzgzMSwicGxhdGZvcm0iOiJtYXgiLCJwdWJsaWNhdGlvbl9pZCI6MTU0OSwidmVyc2lvbl9pZCI6NzA3NywicG9zdF91cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FueXRvdXIvQWFIcFF4N1FXX3MiLCJjaGFubmVsX2lkIjoiLTgwNDkyMjQ0MjA5NjgzIiwibWVzc2FnZV9pZCI6Im1pZC5mZmZmYjZjYWYwNjA2ZmVkMDFhMWU5NDMxZWQwNWJmYiIsInRleHRfc2hhMjU2IjoiZDhhNDFhMDRlYmFhMGU5ZWJhODI3OWM2OTQ4MjZkZGU2Y2M5NTI4MjU5YzA4YTZkOTk2YTVkYzk0YzY2OGI2OSIsInBob3RvX3NoYTI1NiI6ImFiZmRhMzI0YmJjZmEzM2RiNWEzOTg5Y2FmMGY2MzRkYzY5YmI4ZThmYWUzZDM5MzNlY2UwMTZmMWZhODliOWIiLCJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiI2ZTQzNGE0OTE5NWVlOWI4N2I2YjBiZmE2MTBiYWQ4MTYzMzFjMTQwNTI1ZDFkMDcwNzcxYzM3ZjJkNjE3NGI4In0seyJob3RlbF9pZCI6Mjk0OCwicGxhdGZvcm0iOiJtYXgiLCJwdWJsaWNhdGlvbl9pZCI6MTU1MCwidmVyc2lvbl9pZCI6NzA3OCwicG9zdF91cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FueXRvdXIvQWFIcFF5aHJlcDAiLCJjaGFubmVsX2lkIjoiLTgwNDkyMjQ0MjA5NjgzIiwibWVzc2FnZV9pZCI6Im1pZC5mZmZmYjZjYWYwNjA2ZmVkMDFhMWU5NDMyODZiN2E5ZCIsInRleHRfc2hhMjU2IjoiNGRhZmY3ZTNhZmQ2YzIxMTg3ODJkMjM2MzBhNDNmMzliZmUyZDYxMzcyNjk5NGMxYzdkOWIxMGMzMWQzMmE5YSIsInBob3RvX3NoYTI1NiI6IjE4YTM4ZjM2MDY2NWU0OTIzYjE5MDEwNTZiNWQ3NDA3MDI3NWFmYTMwZjg2MmZiZTExNjM5ODhhZDcyN2ZkN2MiLCJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiJjNzM2MzM1NGQwZTFjNjFhNGJlY2Q1YmZmMDIwYjAzYTViNWI2ZTNiODY4ODc2M2FiNWI5NDg0NGJlMDA2MTYwIn0seyJob3RlbF9pZCI6NTc1MzYsInBsYXRmb3JtIjoibWF4IiwicHVibGljYXRpb25faWQiOjE1NTEsInZlcnNpb25faWQiOjcwNzksInBvc3RfdXJsIjoiaHR0cHM6Ly9tYXgucnUvY2hhbm5lbF9hbnl0b3VyL0FhSHBReklHRmZvIiwiY2hhbm5lbF9pZCI6Ii04MDQ5MjI0NDIwOTY4MyIsIm1lc3NhZ2VfaWQiOiJtaWQuZmZmZmI2Y2FmMDYwNmZlZDAxYTFlOTQzMzIwNjE1ZmEiLCJ0ZXh0X3NoYTI1NiI6IjA0ZjQ5ODYzZjI3NjllNTU0YjI5ZmRiOTZmYjQwZjFkNzFhNzk1ZGUwZDg5MjA3ZGY3NjEyYThhMGFmODg4OWUiLCJwaG90b19zaGEyNTYiOiJlYjU4ODkxNTI5YzgzYWU4NzBhYWE0NmJlYjQ0ZGQwNThlYWE3YTU1YmY5YmJiNzYzZmY5MzFiOWU5N2FmMTgwIiwiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiOTBlZTc4YTA2YTYyNmU3ZmU2N2Y5Y2M4OTQ3N2MyNDI3MDA4ZWE3NTIyNGM5YWRiMzE0NzVkMzlmMDRkYzdkYiJ9LHsiaG90ZWxfaWQiOjYxMjQxLCJwbGF0Zm9ybSI6Im1heCIsInB1YmxpY2F0aW9uX2lkIjoxNTUyLCJ2ZXJzaW9uX2lkIjo3MDgwLCJwb3N0X3VybCI6Imh0dHBzOi8vbWF4LnJ1L2NoYW5uZWxfYW55dG91ci9BYUhwUXp0clhmQSIsImNoYW5uZWxfaWQiOiItODA0OTIyNDQyMDk2ODMiLCJtZXNzYWdlX2lkIjoibWlkLmZmZmZiNmNhZjA2MDZmZWQwMWExZTk0MzNiNmI1ZGYwIiwidGV4dF9zaGEyNTYiOiJkNzY3NzQ3ZjI5YTVhNTUxYzE0NjBlNjBkNDM4MmE0ZjlkM2IzNDc1YTRhMzNiNzExMTZhOTdiOTA1N2NlOWE5IiwicGhvdG9fc2hhMjU2IjoiYWFhNTIzNzkwOGJkZTdmYWQ1YTIxMmU0MDhlNzQxYTg1OGMxYTZhODk0NTQ3YTA0MmNiMmJmY2JkMDM1MWNjMCIsImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6ImNkMTU4Mzk1YTVlMmEyZjZlOTU4YzM3YzUwNmM2YTE4YWU0NTJkMTRmN2FkOWMwZWQyODIxOWI2OWE1NDBhNmQifSx7ImhvdGVsX2lkIjoxNzQyNCwicGxhdGZvcm0iOiJtYXgiLCJwdWJsaWNhdGlvbl9pZCI6MTU1MywidmVyc2lvbl9pZCI6NzA4MSwicG9zdF91cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FueXRvdXIvQWFIcFEwVWFZc1kiLCJjaGFubmVsX2lkIjoiLTgwNDkyMjQ0MjA5NjgzIiwibWVzc2FnZV9pZCI6Im1pZC5mZmZmYjZjYWYwNjA2ZmVkMDFhMWU5NDM0NTFhNjJjNiIsInRleHRfc2hhMjU2IjoiYTZmODVmNTUyNTNkNjNhNThmM2VkN2Y4YzBlZWQyOWRmMzM5MWYxZGEyYTM0MWZhNzNmYzE4YzFmODQ5YTkzMyIsInBob3RvX3NoYTI1NiI6IjE4ZmNlY2U1NWJhZTg1YTUwNjU2ZGI0YzViMjE4ZWNlMTg3Nzk4NmIyMjJlMWM5ZjU2OGYwM2EyOWYyNzZmMGYiLCJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiI2YTZlMjgzNWJjMmQ2NjEzNTU0ZjEwYTBkNjYxN2Q2OGQ2MWE3ZjY2N2VjNDFmYTlhYTdmODg4M2E1ZWU0OGU4In0seyJob3RlbF9pZCI6MTc0MjMsInBsYXRmb3JtIjoibWF4IiwicHVibGljYXRpb25faWQiOjE1NTQsInZlcnNpb25faWQiOjcwODIsInBvc3RfdXJsIjoiaHR0cHM6Ly9tYXgucnUvY2hhbm5lbF9hbnl0b3VyL0FhSHBRMDZVRlZVIiwiY2hhbm5lbF9pZCI6Ii04MDQ5MjI0NDIwOTY4MyIsIm1lc3NhZ2VfaWQiOiJtaWQuZmZmZmI2Y2FmMDYwNmZlZDAxYTFlOTQzNGU5NDE1NTUiLCJ0ZXh0X3NoYTI1NiI6Ijg1MWYyMWY2MjFhZTA2OTRlYThhZDc4MmE0ZTVlYjU2ODc2MTdhZDc1ZTk0ZTk2NmViZTAxNWE1MTU2ZjFkNTQiLCJwaG90b19zaGEyNTYiOiIyMjc1YjYwZWMxMmEzODI3NzRjY2FlMGIxNzk1MzYyM2E3OGI1ZjUwMWVmNTA0NzcyNzhlZjhhZDNmZTBmOWE5IiwiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiNGI5NWIyOWU0NmExM2RlODFlYTE4M2E4MmU0MThlMDUwZWE1YWI2ZmI4MDRmZGExNmU3NWQ2YTM5YTc0M2FlNCJ9LHsiaG90ZWxfaWQiOjIzNzE4LCJwbGF0Zm9ybSI6Im1heCIsInB1YmxpY2F0aW9uX2lkIjoxNTU1LCJ2ZXJzaW9uX2lkIjo3MDgzLCJwb3N0X3VybCI6Imh0dHBzOi8vbWF4LnJ1L2NoYW5uZWxfYW55dG91ci9BYUhwUTFoUVF0TSIsImNoYW5uZWxfaWQiOiItODA0OTIyNDQyMDk2ODMiLCJtZXNzYWdlX2lkIjoibWlkLmZmZmZiNmNhZjA2MDZmZWQwMWExZTk0MzU4NTA0MmQzIiwidGV4dF9zaGEyNTYiOiJkNmE5YzY4Yzg0OTgxZjM5YjBkMTdiOWZjNzc3M2Q1NGRjZWFjOTE2Njg5YzhlOGM1YjFiNGUyNjlmMjJmYTA5IiwicGhvdG9fc2hhMjU2IjoiOTJkZDdjNDgxNDdlYjJiYzZjNGY1OTBkNzNjOTJmYTkzYzc0YjM5Y2I4ZjVjMTVjYWUxZWJmYmRmMmMzZmEwMiIsImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6Ijc0MTY1OTM5ZGNmMGFlZjAzZTNkYzZjYzdmNDQ5NjA1OGExZTgwYjhiYWNjNDMzMTk5OGQ5OTg4ODA0MDhjNmUifSx7ImhvdGVsX2lkIjoyMjk0MCwicGxhdGZvcm0iOiJtYXgiLCJwdWJsaWNhdGlvbl9pZCI6MTU1NiwidmVyc2lvbl9pZCI6NzA4NCwicG9zdF91cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FueXRvdXIvQWFIcFEyRjRBYzgiLCJjaGFubmVsX2lkIjoiLTgwNDkyMjQ0MjA5NjgzIiwibWVzc2FnZV9pZCI6Im1pZC5mZmZmYjZjYWYwNjA2ZmVkMDFhMWU5NDM2MTc4MDFjZiIsInRleHRfc2hhMjU2IjoiMWNmMGJmZGQwMzlkMDUxZjA1Mzc0YjY2ZDc4ZjJiY2FjMDA4OWYxNDgzNGM2MTg4ZjQxYzM4NGZlNzkwODA4OSIsInBob3RvX3NoYTI1NiI6ImFjNDUwMTc0OWFkNDc5YjM0OWM4NmQ1NmM5MDE3ZWZjYzFkZjkzM2NkMTIzOWE4MTg0MGM0MzNkY2YxN2Y4MzQiLCJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiI2YTQxOGY2ZDIzNjA4ZTg1OThlNTUwYzlhN2Y1ZjRiZGI4ZWY2OWQ5N2U2ODFkZGMxZjlhMDhiZjM0MGE0N2E1In0seyJob3RlbF9pZCI6OTcwMDEsInBsYXRmb3JtIjoibWF4IiwicHVibGljYXRpb25faWQiOjE1NTcsInZlcnNpb25faWQiOjcwODUsInBvc3RfdXJsIjoiaHR0cHM6Ly9tYXgucnUvY2hhbm5lbF9hbnl0b3VyL0FhSHBRMnI0Y3E4IiwiY2hhbm5lbF9pZCI6Ii04MDQ5MjI0NDIwOTY4MyIsIm1lc3NhZ2VfaWQiOiJtaWQuZmZmZmI2Y2FmMDYwNmZlZDAxYTFlOTQzNmFmODcyYWYiLCJ0ZXh0X3NoYTI1NiI6IjQ0NGY5N2VjOTBiYjcwODY4NDdlMmU1NzA2YTZjNjFjNDI2OTBlYTliOTY3YmViMmVmZjlkOWJiNzBkNTAwZTQiLCJwaG90b19zaGEyNTYiOiI0YWUwNTdjMjg5ZTk4ZDY4NmNiZTdmYTExMzZiODRiNzlhNDBlOWVlMjY5OGRmMzBhN2FkZDFhOGUxOGMyYWI0IiwiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiMmMwMzRkZjk4NzVmY2FmOTgxNzI2OGYzMWM0ZDFlZjBmYWUxY2UwYWY2MGUyZjM1NWQwNmE2NGVkM2Y0M2I1YSJ9LHsiaG90ZWxfaWQiOjc0NTA5LCJwbGF0Zm9ybSI6Im1heCIsInB1YmxpY2F0aW9uX2lkIjoxNTU4LCJ2ZXJzaW9uX2lkIjo3MDg2LCJwb3N0X3VybCI6Imh0dHBzOi8vbWF4LnJ1L2NoYW5uZWxfYW55dG91ci9BYUhwUTNRa1JLWSIsImNoYW5uZWxfaWQiOiItODA0OTIyNDQyMDk2ODMiLCJtZXNzYWdlX2lkIjoibWlkLmZmZmZiNmNhZjA2MDZmZWQwMWExZTk0Mzc0MjQ0NGE2IiwidGV4dF9zaGEyNTYiOiI0OGMyOTJjNTUxYTFlY2VlOGQ2NThlYWI5NTE4YzMyN2ZiZDY1YjEwYjA3MjNkOTBmMGNhYzIzZTY0MjM4M2FiIiwicGhvdG9fc2hhMjU2IjoiMWRlNTU3ZjIwNDFmZjllZmQxZjM3ZDNlYzM4NGQzY2ZkNWU5YzkyNTEyOThkYmYyNDM2NTg2N2I5ODZjMjE1NCIsImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6IjI2MzBkYjg1YTYzNTllMzY1ZGY2NTA2ODQ0MDI4ZWYxY2NkOWJiMGFlMDhjZjk4Njg2NTRkNTYxOGFiOGFmYjcifV0=',true),true,64,JSON_THROW_ON_ERROR);
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

