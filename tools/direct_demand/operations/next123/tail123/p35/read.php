<?php
declare(strict_types=1);
const EXPECTED_INSTALLED_SHA='cc017ee5bb669f4c7c13efc91d5c5812a50e55f2';
const FEED_EXPORT_INPUT_B64='W3siYWNjZXB0ZWRfZmlyc3RfbGluZSI6IuKciO+4jyDQotGD0YDRiyDQsiBBdmFuaSsgTWFpIEtoYW8gUGh1a2V0IFN1aXRlcyAmIFZpbGxhcyIsImhvdGVsX2lkIjoyMjcxOCwiaW1hZ2Vfc2hhMjU2IjoiNTFkNjIyNjNmNjgyMjE1M2UxMmYxZDUxMDM1Y2Q3NzIyODY1YzFlNjk2NTg5MTI5MzExMjBjYTRkOWIwMTdlZCIsImltYWdlX3VybCI6Imh0dHBzOi8vcmF3LmdpdGh1YnVzZXJjb250ZW50LmNvbS9weWF0a29mZi9tYXgtc2VhcmNoLWJvdC9lNTNlZWRlMmRmNWRiYzVkYTVmNzJiMDRjMGFjODc3NzgxOWNkOGRjLy5naXRodWIvdHJhZmZpYy1wdWJsaXNoL2NvbW1lcmNpYWwtd2F2ZTQtMTAwLTIwMjYxMTE3LzIyNzE4LnBuZyIsIm5hbWUiOiJBdmFuaSsgTWFpIEtoYW8gUGh1a2V0IFN1aXRlcyAmIFZpbGxhcyJ9LHsiYWNjZXB0ZWRfZmlyc3RfbGluZSI6IuKciO+4jyDQotGD0YDRiyDQsiBBbWFyaSBQYXR0YXlhIiwiaG90ZWxfaWQiOjU0MSwiaW1hZ2Vfc2hhMjU2IjoiMGMxZDJhNWY5N2Q2NTgxOTQ4OWQzZWM4ZDA3ZDliZDVjOTA5YzU2YjdiZDVlM2E4MjM1YWFlYTIwYzNmOTNlOCIsImltYWdlX3VybCI6Imh0dHBzOi8vcmF3LmdpdGh1YnVzZXJjb250ZW50LmNvbS9weWF0a29mZi9tYXgtc2VhcmNoLWJvdC9lNTNlZWRlMmRmNWRiYzVkYTVmNzJiMDRjMGFjODc3NzgxOWNkOGRjLy5naXRodWIvdHJhZmZpYy1wdWJsaXNoL2NvbW1lcmNpYWwtd2F2ZTQtMTAwLTIwMjYxMTE3LzU0MS5wbmciLCJuYW1lIjoiQW1hcmkgUGF0dGF5YSJ9LHsiYWNjZXB0ZWRfZmlyc3RfbGluZSI6IuKciO+4jyDQotGD0YDRiyDQsiBDZW50YXJhIEdyYW5kIEJlYWNoIFJlc29ydCBQaHVrZXQiLCJob3RlbF9pZCI6MjAwNiwiaW1hZ2Vfc2hhMjU2IjoiZThjZjM1M2FhYWFlMDA4NDkwNjdkN2Y5MmIxMDkyZTQzMTk1M2JiODg2ZWUwYTliZTkzNmY1ODg4NDI1YTBiNyIsImltYWdlX3VybCI6Imh0dHBzOi8vcmF3LmdpdGh1YnVzZXJjb250ZW50LmNvbS9weWF0a29mZi9tYXgtc2VhcmNoLWJvdC9lNTNlZWRlMmRmNWRiYzVkYTVmNzJiMDRjMGFjODc3NzgxOWNkOGRjLy5naXRodWIvdHJhZmZpYy1wdWJsaXNoL2NvbW1lcmNpYWwtd2F2ZTQtMTAwLTIwMjYxMTE3LzIwMDYucG5nIiwibmFtZSI6IkNlbnRhcmEgR3JhbmQgQmVhY2ggUmVzb3J0IFBodWtldCJ9LHsiYWNjZXB0ZWRfZmlyc3RfbGluZSI6IuKciO+4jyDQotGD0YDRiyDQsiBDYXBlIERhcmEgUmVzb3J0IiwiaG90ZWxfaWQiOjkzODUsImltYWdlX3NoYTI1NiI6IjlmMTU5N2ZmNDM4ZTEwOWE1NTMzNDk2Y2YzNzhmZTc1NmMxNTQ0MDkxZGJjZjhhMjYzYzdkZWEzNzgwYzliMGUiLCJpbWFnZV91cmwiOiJodHRwczovL3Jhdy5naXRodWJ1c2VyY29udGVudC5jb20vcHlhdGtvZmYvbWF4LXNlYXJjaC1ib3QvZTUzZWVkZTJkZjVkYmM1ZGE1ZjcyYjA0YzBhYzg3Nzc4MTljZDhkYy8uZ2l0aHViL3RyYWZmaWMtcHVibGlzaC9jb21tZXJjaWFsLXdhdmU0LTEwMC0yMDI2MTExNy85Mzg1LnBuZyIsIm5hbWUiOiJDYXBlIERhcmEgUmVzb3J0In0seyJhY2NlcHRlZF9maXJzdF9saW5lIjoi4pyI77iPINCi0YPRgNGLINCyIEFuZ3NhbmEgVmlsbGFzIFJlc29ydCBQaHVrZXQiLCJob3RlbF9pZCI6MzAwNiwiaW1hZ2Vfc2hhMjU2IjoiYWZmYjY3N2FhMWY0ODlmY2JjYzhmZGE5NjVkZGNmYTYzNWVjOGFhMDE5YmY5YjMzMDQzNTM4OTQyZmZkMWU2ZSIsImltYWdlX3VybCI6Imh0dHBzOi8vcmF3LmdpdGh1YnVzZXJjb250ZW50LmNvbS9weWF0a29mZi9tYXgtc2VhcmNoLWJvdC9lNTNlZWRlMmRmNWRiYzVkYTVmNzJiMDRjMGFjODc3NzgxOWNkOGRjLy5naXRodWIvdHJhZmZpYy1wdWJsaXNoL2NvbW1lcmNpYWwtd2F2ZTQtMTAwLTIwMjYxMTE3LzMwMDYucG5nIiwibmFtZSI6IkFuZ3NhbmEgVmlsbGFzIFJlc29ydCBQaHVrZXQifSx7ImFjY2VwdGVkX2ZpcnN0X2xpbmUiOiLinIjvuI8g0KLRg9GA0Ysg0LIgQXZhbmkgQ2hhd2VuZyBTYW11aSBIb3RlbCAmIEJlYWNoIENsdWIiLCJob3RlbF9pZCI6ODI0MjIsImltYWdlX3NoYTI1NiI6Ijk5NzAyNmVkNDc2ZDQzNzgwMWQ0YjlmYjc4N2M5MzViYzNlODFlOWI2M2M2MzE5NGJjYWNmODY0ODA3ZDUzOWUiLCJpbWFnZV91cmwiOiJodHRwczovL3Jhdy5naXRodWJ1c2VyY29udGVudC5jb20vcHlhdGtvZmYvbWF4LXNlYXJjaC1ib3QvZTUzZWVkZTJkZjVkYmM1ZGE1ZjcyYjA0YzBhYzg3Nzc4MTljZDhkYy8uZ2l0aHViL3RyYWZmaWMtcHVibGlzaC9jb21tZXJjaWFsLXdhdmU0LTEwMC0yMDI2MTExNy84MjQyMi5wbmciLCJuYW1lIjoiQXZhbmkgQ2hhd2VuZyBTYW11aSBIb3RlbCAmIEJlYWNoIENsdWIifSx7ImFjY2VwdGVkX2ZpcnN0X2xpbmUiOiLinIjvuI8g0KLRg9GA0Ysg0LIgU0FpaSBLb2ggU2FtdWkgVmlsbGFzIDEyKyIsImhvdGVsX2lkIjo4MTE0MCwiaW1hZ2Vfc2hhMjU2IjoiMTc4YTE4YzY1YjIzNDAzZGI2NjNlYTYxZmEyYmM2MjM5ZjRlMTk4ZDZlYThjOTNlNGNkNGI4MjJhZTNhMGU4NyIsImltYWdlX3VybCI6Imh0dHBzOi8vcmF3LmdpdGh1YnVzZXJjb250ZW50LmNvbS9weWF0a29mZi9tYXgtc2VhcmNoLWJvdC9lNTNlZWRlMmRmNWRiYzVkYTVmNzJiMDRjMGFjODc3NzgxOWNkOGRjLy5naXRodWIvdHJhZmZpYy1wdWJsaXNoL2NvbW1lcmNpYWwtd2F2ZTQtMTAwLTIwMjYxMTE3LzgxMTQwLnBuZyIsIm5hbWUiOiJTQWlpIEtvaCBTYW11aSBWaWxsYXMgMTIrIn0seyJhY2NlcHRlZF9maXJzdF9saW5lIjoi4pyI77iPINCi0YPRgNGLINCyIFRoZSBMYW1haSBTYW11aSIsImhvdGVsX2lkIjo4MDIwLCJpbWFnZV9zaGEyNTYiOiJjYmY2ZDVlYmIyOTE3NGY1OWM0ZThmOGMxNTc0MDY5MThlOWNjYjUzMmVlM2RmMDZkOTk2Njg2MzM5OWMxNmUzIiwiaW1hZ2VfdXJsIjoiaHR0cHM6Ly9yYXcuZ2l0aHVidXNlcmNvbnRlbnQuY29tL3B5YXRrb2ZmL21heC1zZWFyY2gtYm90L2U1M2VlZGUyZGY1ZGJjNWRhNWY3MmIwNGMwYWM4Nzc3ODE5Y2Q4ZGMvLmdpdGh1Yi90cmFmZmljLXB1Ymxpc2gvY29tbWVyY2lhbC13YXZlNC0xMDAtMjAyNjExMTcvODAyMC5wbmciLCJuYW1lIjoiVGhlIExhbWFpIFNhbXVpIn0seyJhY2NlcHRlZF9maXJzdF9saW5lIjoi4pyI77iPINCi0YPRgNGLINCyIEFtYXJpIEJhbmdzYWVuIiwiaG90ZWxfaWQiOjUxOTMxLCJpbWFnZV9zaGEyNTYiOiJkMWRjNWY0M2I0MmE3MzU4ZDdjNGJhZmMzNDk3NWNiMjZkNWU5NjU0ZDk2NjZjYjdjNDkyNmFkOGEyYTFlNGVkIiwiaW1hZ2VfdXJsIjoiaHR0cHM6Ly9yYXcuZ2l0aHVidXNlcmNvbnRlbnQuY29tL3B5YXRrb2ZmL21heC1zZWFyY2gtYm90L2U1M2VlZGUyZGY1ZGJjNWRhNWY3MmIwNGMwYWM4Nzc3ODE5Y2Q4ZGMvLmdpdGh1Yi90cmFmZmljLXB1Ymxpc2gvY29tbWVyY2lhbC13YXZlNC0xMDAtMjAyNjExMTcvNTE5MzEucG5nIiwibmFtZSI6IkFtYXJpIEJhbmdzYWVuIn1d';
const FEED_EXPORT_INPUT_SHA256='e00a3d7a2dc0505005957af3bf5790471ccfce97e747a2d951ea3d12d3d57fba';
const CANDIDATE_INPUT_B64='W10=';
const CANDIDATE_INPUT_SHA256='4f53cda18c2baa0c0354bb5f9a3ecbe5ed12ab4d8e11ba873c2f11161202b945';
ini_set('display_errors','0');ini_set('log_errors','0');
$out=['operation'=>'commercial-channel-hotel-feeds-20261118-next123-read35','mode'=>'read_bounded_public_channel_post_feed_inputs','ok'=>false,'db_writes'=>0,'provider_calls'=>0,'message_sends'=>0];
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
 insist(count($in)===9&&array_column($in,'hotel_id')===[22718,541,2006,9385,3006,82422,81140,8020,51931],'read17_exact_new1_scope');
 insist(count(array_unique(array_column($in,'accepted_first_line')))===9,'read17_duplicate_firstline');
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
    $ids=array_column($inputs,'hotel_id');insist(count(array_unique($ids))===9,'feed_scope_duplicate');
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
    $out+=$built;insist(count($built['rows'])===9&&count($built['deferred'])===0,'new_reviewed_pairs_required');
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
    $retained=json_decode(base64_decode('W3siYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiY2VmOTQ4MGUzMTY4MjRhYjBjYzM0Mzk3NmNkNDU2MmVkYmViZDRiMTkwNmVmYzZlZmE4ODRkYThiMDMyYTExYSIsImNoYW5uZWxfaWQiOiItMTAwMjI2MjY2NjYxNiIsImhvdGVsX2lkIjoyMjcxOCwibWVzc2FnZV9pZCI6Ijg5MiIsInBob3RvX3NoYTI1NiI6IjUxZDYyMjYzZjY4MjIxNTNlMTJmMWQ1MTAzNWNkNzcyMjg2NWMxZTY5NjU4OTEyOTMxMTIwY2E0ZDliMDE3ZWQiLCJwbGF0Zm9ybSI6InRlbGVncmFtIiwicG9zdF91cmwiOiJodHRwczovL3QubWUvYW55X3Rvb3VyLzg5MiIsInB1YmxpY2F0aW9uX2lkIjoxOTM4LCJ0ZXh0X3NoYTI1NiI6ImM2YTVkOTc0YTg2ZTY5OTI0M2U5YjMxZGE4MjYxNTk4YzdkNWIwOWMzNThkZjY4OTkxOGRhMzE0ZTA4ZjNhODQiLCJ2ZXJzaW9uX2lkIjo3NDY3fSx7ImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6IjA3OWQxMDFkMWMwZTU3NDFhODQwYTNmN2JlMjE1OGU3MTE4MTY0YWJkOGM4YTU0NGRlZWRhOTY2ZWQ1ZTc5ZDEiLCJjaGFubmVsX2lkIjoiLTgwNDkyMjQ0MjA5NjgzIiwiaG90ZWxfaWQiOjIyNzE4LCJtZXNzYWdlX2lkIjoibWlkLmZmZmZiNmNhZjA2MDZmZWQwMWExZWE5NzY5Yzk0MTYxIiwicGhvdG9fc2hhMjU2IjoiNTFkNjIyNjNmNjgyMjE1M2UxMmYxZDUxMDM1Y2Q3NzIyODY1YzFlNjk2NTg5MTI5MzExMjBjYTRkOWIwMTdlZCIsInBsYXRmb3JtIjoibWF4IiwicG9zdF91cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FueXRvdXIvQWFIcWwybkpRV0UiLCJwdWJsaWNhdGlvbl9pZCI6MjA4OCwidGV4dF9zaGEyNTYiOiJjNmE1ZDk3NGE4NmU2OTkyNDNlOWIzMWRhODI2MTU5OGM3ZDViMDljMzU4ZGY2ODk5MThkYTMxNGUwOGYzYTg0IiwidmVyc2lvbl9pZCI6NzYxN30seyJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiI0OThhYzMwMjMwY2QzZmMwNjZkMTUzZDMxOThjNTllMjJlOGQwMjFhOGVhMTY0NGUzNmExZTVlYWY4N2MwZWE1IiwiY2hhbm5lbF9pZCI6Ii0xMDAyMjYyNjY2NjE2IiwiaG90ZWxfaWQiOjU0MSwibWVzc2FnZV9pZCI6Ijg5MyIsInBob3RvX3NoYTI1NiI6IjBjMWQyYTVmOTdkNjU4MTk0ODlkM2VjOGQwN2Q5YmQ1YzkwOWM1NmI3YmQ1ZTNhODIzNWFhZWEyMGMzZjkzZTgiLCJwbGF0Zm9ybSI6InRlbGVncmFtIiwicG9zdF91cmwiOiJodHRwczovL3QubWUvYW55X3Rvb3VyLzg5MyIsInB1YmxpY2F0aW9uX2lkIjoxOTM5LCJ0ZXh0X3NoYTI1NiI6IjcyMTM1ZmNlMjkyZDcxYTJlMmNjNjI4MTQxZmQwODFiM2NkNWM3N2U1MGZmMzY3NTgyOGVkZDlhNmU2ZDY5NTAiLCJ2ZXJzaW9uX2lkIjo3NDY4fSx7ImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6IjU4ZDhjOGNhYmQ4ZGNiYmYzODU1NWU1NTM1MjlhN2JjMjM2MDYwYTViODU5YmMxOGIyNDliNThhMzU0YmU3NjYiLCJjaGFubmVsX2lkIjoiLTgwNDkyMjQ0MjA5NjgzIiwiaG90ZWxfaWQiOjU0MSwibWVzc2FnZV9pZCI6Im1pZC5mZmZmYjZjYWYwNjA2ZmVkMDFhMWVhOTc3MmNmNGE2NSIsInBob3RvX3NoYTI1NiI6IjBjMWQyYTVmOTdkNjU4MTk0ODlkM2VjOGQwN2Q5YmQ1YzkwOWM1NmI3YmQ1ZTNhODIzNWFhZWEyMGMzZjkzZTgiLCJwbGF0Zm9ybSI6Im1heCIsInBvc3RfdXJsIjoiaHR0cHM6Ly9tYXgucnUvY2hhbm5lbF9hbnl0b3VyL0FhSHFsM0xQU21VIiwicHVibGljYXRpb25faWQiOjIwODksInRleHRfc2hhMjU2IjoiNzIxMzVmY2UyOTJkNzFhMmUyY2M2MjgxNDFmZDA4MWIzY2Q1Yzc3ZTUwZmYzNjc1ODI4ZWRkOWE2ZTZkNjk1MCIsInZlcnNpb25faWQiOjc2MTh9LHsiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiZDg4ZDFkMDNkNTQzNTQ0ZGYwZWQyYmI2ODczZjNhNTk2NjEwNWY4NmM1YWQ0MzM2ZDdiYTgzOWFhYzA0ODViMCIsImNoYW5uZWxfaWQiOiItMTAwMjI2MjY2NjYxNiIsImhvdGVsX2lkIjoyMDA2LCJtZXNzYWdlX2lkIjoiODk0IiwicGhvdG9fc2hhMjU2IjoiZThjZjM1M2FhYWFlMDA4NDkwNjdkN2Y5MmIxMDkyZTQzMTk1M2JiODg2ZWUwYTliZTkzNmY1ODg4NDI1YTBiNyIsInBsYXRmb3JtIjoidGVsZWdyYW0iLCJwb3N0X3VybCI6Imh0dHBzOi8vdC5tZS9hbnlfdG9vdXIvODk0IiwicHVibGljYXRpb25faWQiOjE5NDAsInRleHRfc2hhMjU2IjoiYzI2NjI2ZTA0Njg2Zjk1Y2YyZTYzOTg1Y2IxODhjY2MwNDUzNmNmZWNlNDRiMzBmYTA3OGNiMDE4MjQ4NjYxOSIsInZlcnNpb25faWQiOjc0Njl9LHsiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiNmYxMjJmZDkzOGEzZWQzM2Y2YTQ3ZDVhMDU5NzY1YjkxNjFhZTFmZWY2M2VjYjVkMGQ0MmU1MDlmMDY3MjlmMCIsImNoYW5uZWxfaWQiOiItODA0OTIyNDQyMDk2ODMiLCJob3RlbF9pZCI6MjAwNiwibWVzc2FnZV9pZCI6Im1pZC5mZmZmYjZjYWYwNjA2ZmVkMDFhMWVhOTc3YzBhMzI4ZSIsInBob3RvX3NoYTI1NiI6ImU4Y2YzNTNhYWFhZTAwODQ5MDY3ZDdmOTJiMTA5MmU0MzE5NTNiYjg4NmVlMGE5YmU5MzZmNTg4ODQyNWEwYjciLCJwbGF0Zm9ybSI6Im1heCIsInBvc3RfdXJsIjoiaHR0cHM6Ly9tYXgucnUvY2hhbm5lbF9hbnl0b3VyL0FhSHFsM3dLTW80IiwicHVibGljYXRpb25faWQiOjIwOTAsInRleHRfc2hhMjU2IjoiYzI2NjI2ZTA0Njg2Zjk1Y2YyZTYzOTg1Y2IxODhjY2MwNDUzNmNmZWNlNDRiMzBmYTA3OGNiMDE4MjQ4NjYxOSIsInZlcnNpb25faWQiOjc2MTl9LHsiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiNDE5Njk0NTZmNjNlZTU1OTI1OWI2OTdmZWU3MGMwODRjN2JkOWFmMGE3NjZkMGMyOWY2NTUwZDQ5NDRlMjdiMCIsImNoYW5uZWxfaWQiOiItMTAwMjI2MjY2NjYxNiIsImhvdGVsX2lkIjo5Mzg1LCJtZXNzYWdlX2lkIjoiODk1IiwicGhvdG9fc2hhMjU2IjoiOWYxNTk3ZmY0MzhlMTA5YTU1MzM0OTZjZjM3OGZlNzU2YzE1NDQwOTFkYmNmOGEyNjNjN2RlYTM3ODBjOWIwZSIsInBsYXRmb3JtIjoidGVsZWdyYW0iLCJwb3N0X3VybCI6Imh0dHBzOi8vdC5tZS9hbnlfdG9vdXIvODk1IiwicHVibGljYXRpb25faWQiOjE5NDEsInRleHRfc2hhMjU2IjoiYjQyYjQ2ZTJiY2Q1ZDA1Nzk2ZjgzMDcyM2I1Y2EwMzIxM2Q4NGE3ZTcxNWNmZWI3ZTMyOTc2NGU2ZTg3MzFhZSIsInZlcnNpb25faWQiOjc0NzB9LHsiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiNGQzZmFjOTA5NTcyMzE2NDUwMjMxNjExMjFhNjNlNDBmYmQ1OTg5YWFiYzE5ODgyN2VkZWQxM2RjYTc1MTU1NCIsImNoYW5uZWxfaWQiOiItODA0OTIyNDQyMDk2ODMiLCJob3RlbF9pZCI6OTM4NSwibWVzc2FnZV9pZCI6Im1pZC5mZmZmYjZjYWYwNjA2ZmVkMDFhMWVhOTc4NzM5NDRmNiIsInBob3RvX3NoYTI1NiI6IjlmMTU5N2ZmNDM4ZTEwOWE1NTMzNDk2Y2YzNzhmZTc1NmMxNTQ0MDkxZGJjZjhhMjYzYzdkZWEzNzgwYzliMGUiLCJwbGF0Zm9ybSI6Im1heCIsInBvc3RfdXJsIjoiaHR0cHM6Ly9tYXgucnUvY2hhbm5lbF9hbnl0b3VyL0FhSHFsNGM1UlBZIiwicHVibGljYXRpb25faWQiOjIwOTEsInRleHRfc2hhMjU2IjoiYjQyYjQ2ZTJiY2Q1ZDA1Nzk2ZjgzMDcyM2I1Y2EwMzIxM2Q4NGE3ZTcxNWNmZWI3ZTMyOTc2NGU2ZTg3MzFhZSIsInZlcnNpb25faWQiOjc2MjB9LHsiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiNTFiNTg2NDk2YTI2MTc3MDkzYTE3ZDMxNjU0MTgwNGQ5ODQyMWZmYjY0ZGQyZjAxOWUzODMyMTA4NjcxZDYzNyIsImNoYW5uZWxfaWQiOiItMTAwMjI2MjY2NjYxNiIsImhvdGVsX2lkIjozMDA2LCJtZXNzYWdlX2lkIjoiODk2IiwicGhvdG9fc2hhMjU2IjoiYWZmYjY3N2FhMWY0ODlmY2JjYzhmZGE5NjVkZGNmYTYzNWVjOGFhMDE5YmY5YjMzMDQzNTM4OTQyZmZkMWU2ZSIsInBsYXRmb3JtIjoidGVsZWdyYW0iLCJwb3N0X3VybCI6Imh0dHBzOi8vdC5tZS9hbnlfdG9vdXIvODk2IiwicHVibGljYXRpb25faWQiOjE5NDIsInRleHRfc2hhMjU2IjoiZTY0ZWIwZDA2Njg3OTJlN2RhNmUxMmMwYjYwNTU2YmFlOTAzMmRkYzYxZDg1ZGE0MDUyNzQ2YTExNjlkNjBhYiIsInZlcnNpb25faWQiOjc0NzF9LHsiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiNTYxZmY4NzAwOGI4MTlmYmU1MGMzZmUyNWEzNWYzOGQzYTJkMzI1YjdhZTNjODVlZjQxNWEyOGE4ODE0M2QxNyIsImNoYW5uZWxfaWQiOiItODA0OTIyNDQyMDk2ODMiLCJob3RlbF9pZCI6MzAwNiwibWVzc2FnZV9pZCI6Im1pZC5mZmZmYjZjYWYwNjA2ZmVkMDFhMWVhOTc5MGJjMzllNyIsInBob3RvX3NoYTI1NiI6ImFmZmI2NzdhYTFmNDg5ZmNiY2M4ZmRhOTY1ZGRjZmE2MzVlYzhhYTAxOWJmOWIzMzA0MzUzODk0MmZmZDFlNmUiLCJwbGF0Zm9ybSI6Im1heCIsInBvc3RfdXJsIjoiaHR0cHM6Ly9tYXgucnUvY2hhbm5lbF9hbnl0b3VyL0FhSHFsNUM4T2VjIiwicHVibGljYXRpb25faWQiOjIwOTIsInRleHRfc2hhMjU2IjoiZTY0ZWIwZDA2Njg3OTJlN2RhNmUxMmMwYjYwNTU2YmFlOTAzMmRkYzYxZDg1ZGE0MDUyNzQ2YTExNjlkNjBhYiIsInZlcnNpb25faWQiOjc2MjF9LHsiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiZTJkNGQ3MjY5NDM5NjUxZTRkYWFlYTJiMGJjODY0MGVlY2JhNmMyOWZhNzk3YWRlOGZhZmYwNjY4ZTUxMzUzOSIsImNoYW5uZWxfaWQiOiItMTAwMjI2MjY2NjYxNiIsImhvdGVsX2lkIjo4MjQyMiwibWVzc2FnZV9pZCI6Ijg5NyIsInBob3RvX3NoYTI1NiI6Ijk5NzAyNmVkNDc2ZDQzNzgwMWQ0YjlmYjc4N2M5MzViYzNlODFlOWI2M2M2MzE5NGJjYWNmODY0ODA3ZDUzOWUiLCJwbGF0Zm9ybSI6InRlbGVncmFtIiwicG9zdF91cmwiOiJodHRwczovL3QubWUvYW55X3Rvb3VyLzg5NyIsInB1YmxpY2F0aW9uX2lkIjoxOTQzLCJ0ZXh0X3NoYTI1NiI6IjQ1NDljZDVhN2Q1OTIzOWVkMWZmYjljMzNkMjAzM2IxOGJlYTQ5NzM3NzEwZmEwNTdiNzkwZTZiMTlhNWJmYWIiLCJ2ZXJzaW9uX2lkIjo3NDcyfSx7ImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6IjBlNTUwMTA5M2JmZjEzYzdmODMzNzA1Mjc0ZTI3MzMyNWJkYzYxMzJkN2M2ZjJkODY2MzNlNDEwMGI4NmY2N2YiLCJjaGFubmVsX2lkIjoiLTgwNDkyMjQ0MjA5NjgzIiwiaG90ZWxfaWQiOjgyNDIyLCJtZXNzYWdlX2lkIjoibWlkLmZmZmZiNmNhZjA2MDZmZWQwMWExZWE5Nzk5Y2UzMTViIiwicGhvdG9fc2hhMjU2IjoiOTk3MDI2ZWQ0NzZkNDM3ODAxZDRiOWZiNzg3YzkzNWJjM2U4MWU5YjYzYzYzMTk0YmNhY2Y4NjQ4MDdkNTM5ZSIsInBsYXRmb3JtIjoibWF4IiwicG9zdF91cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FueXRvdXIvQWFIcWw1bk9NVnMiLCJwdWJsaWNhdGlvbl9pZCI6MjA5MywidGV4dF9zaGEyNTYiOiI0NTQ5Y2Q1YTdkNTkyMzllZDFmZmI5YzMzZDIwMzNiMThiZWE0OTczNzcxMGZhMDU3Yjc5MGU2YjE5YTViZmFiIiwidmVyc2lvbl9pZCI6NzYyMn0seyJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiJkNThkNWQ2ZjUyMjk4MzE0ZDFkYzRiMmVmY2JjYmIxZTcwZGFlY2NkMGI3Y2MxNzE2NWE2MTkyZjliOGEwMjU3IiwiY2hhbm5lbF9pZCI6Ii0xMDAyMjYyNjY2NjE2IiwiaG90ZWxfaWQiOjgxMTQwLCJtZXNzYWdlX2lkIjoiODk4IiwicGhvdG9fc2hhMjU2IjoiMTc4YTE4YzY1YjIzNDAzZGI2NjNlYTYxZmEyYmM2MjM5ZjRlMTk4ZDZlYThjOTNlNGNkNGI4MjJhZTNhMGU4NyIsInBsYXRmb3JtIjoidGVsZWdyYW0iLCJwb3N0X3VybCI6Imh0dHBzOi8vdC5tZS9hbnlfdG9vdXIvODk4IiwicHVibGljYXRpb25faWQiOjE5NDQsInRleHRfc2hhMjU2IjoiNzE0Mzc1MzRiOWQ1ZWEyZjA1NGJmY2MxYzYxYTVkNjBhYjVjNThhMzUxYTkyMDUwNDE3NTQzMThkYzc2Y2JlYiIsInZlcnNpb25faWQiOjc0NzN9LHsiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiN2I0NmQyZjBkOGE4MmI0ZWM3YTQyNDNhYWUyZTU3ZTBhNGU2NWU4NWMwNzY2ZTNiOWJkODFjMzAyNGMwODcwMiIsImNoYW5uZWxfaWQiOiItODA0OTIyNDQyMDk2ODMiLCJob3RlbF9pZCI6ODExNDAsIm1lc3NhZ2VfaWQiOiJtaWQuZmZmZmI2Y2FmMDYwNmZlZDAxYTFlYTk3YTU5NTMzYTUiLCJwaG90b19zaGEyNTYiOiIxNzhhMThjNjViMjM0MDNkYjY2M2VhNjFmYTJiYzYyMzlmNGUxOThkNmVhOGM5M2U0Y2Q0YjgyMmFlM2EwZTg3IiwicGxhdGZvcm0iOiJtYXgiLCJwb3N0X3VybCI6Imh0dHBzOi8vbWF4LnJ1L2NoYW5uZWxfYW55dG91ci9BYUhxbDZXVk02VSIsInB1YmxpY2F0aW9uX2lkIjoyMDk0LCJ0ZXh0X3NoYTI1NiI6IjcxNDM3NTM0YjlkNWVhMmYwNTRiZmNjMWM2MWE1ZDYwYWI1YzU4YTM1MWE5MjA1MDQxNzU0MzE4ZGM3NmNiZWIiLCJ2ZXJzaW9uX2lkIjo3NjIzfSx7ImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6IjQ5ZjA1YzkzMjMwOTkwZDc2NDk3MzgwMDk1MWUzNTliZTlmZTM2MjJmNmM1NDBmNGNlYzJkYzVhOTRjMjJjMmUiLCJjaGFubmVsX2lkIjoiLTEwMDIyNjI2NjY2MTYiLCJob3RlbF9pZCI6ODAyMCwibWVzc2FnZV9pZCI6Ijg5OSIsInBob3RvX3NoYTI1NiI6ImNiZjZkNWViYjI5MTc0ZjU5YzRlOGY4YzE1NzQwNjkxOGU5Y2NiNTMyZWUzZGYwNmQ5OTY2ODYzMzk5YzE2ZTMiLCJwbGF0Zm9ybSI6InRlbGVncmFtIiwicG9zdF91cmwiOiJodHRwczovL3QubWUvYW55X3Rvb3VyLzg5OSIsInB1YmxpY2F0aW9uX2lkIjoxOTQ1LCJ0ZXh0X3NoYTI1NiI6ImVlOWI2Y2M1ZWM1MDUxOGQzOGVmYmY1OWZiNGI0YmUxODRjYmIyODA3MzQxYzZkZTRhYjU0MmE0ZTUzNWRjZWMiLCJ2ZXJzaW9uX2lkIjo3NDc0fSx7ImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6IjlmMWJlZGNlNDZhZDU1MDljZDhmMGJkMmUwY2UwNmZlZTZkNDVmOGFmZGM1NDYxMWEwODJjYjMwNjE3OWNjOGMiLCJjaGFubmVsX2lkIjoiLTgwNDkyMjQ0MjA5NjgzIiwiaG90ZWxfaWQiOjgwMjAsIm1lc3NhZ2VfaWQiOiJtaWQuZmZmZmI2Y2FmMDYwNmZlZDAxYTFlYTk3YjEzNTUwYTIiLCJwaG90b19zaGEyNTYiOiJjYmY2ZDVlYmIyOTE3NGY1OWM0ZThmOGMxNTc0MDY5MThlOWNjYjUzMmVlM2RmMDZkOTk2Njg2MzM5OWMxNmUzIiwicGxhdGZvcm0iOiJtYXgiLCJwb3N0X3VybCI6Imh0dHBzOi8vbWF4LnJ1L2NoYW5uZWxfYW55dG91ci9BYUhxbDdFMVVLSSIsInB1YmxpY2F0aW9uX2lkIjoyMDk1LCJ0ZXh0X3NoYTI1NiI6ImVlOWI2Y2M1ZWM1MDUxOGQzOGVmYmY1OWZiNGI0YmUxODRjYmIyODA3MzQxYzZkZTRhYjU0MmE0ZTUzNWRjZWMiLCJ2ZXJzaW9uX2lkIjo3NjI0fSx7ImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6IjlmNzQ4OTVhNDE4YzViYjJjMzUwYzU3MGRjNzBmMmZkMmNmZTk2NDc1NmRlNDZhNzAzYmZjMTU1NDNlMmVmYjAiLCJjaGFubmVsX2lkIjoiLTEwMDIyNjI2NjY2MTYiLCJob3RlbF9pZCI6NTE5MzEsIm1lc3NhZ2VfaWQiOiI5MDAiLCJwaG90b19zaGEyNTYiOiJkMWRjNWY0M2I0MmE3MzU4ZDdjNGJhZmMzNDk3NWNiMjZkNWU5NjU0ZDk2NjZjYjdjNDkyNmFkOGEyYTFlNGVkIiwicGxhdGZvcm0iOiJ0ZWxlZ3JhbSIsInBvc3RfdXJsIjoiaHR0cHM6Ly90Lm1lL2FueV90b291ci85MDAiLCJwdWJsaWNhdGlvbl9pZCI6MTk0NiwidGV4dF9zaGEyNTYiOiJmMGEzYWNjNTllYjk3YWFkYWY1NmVlMzE0Yjk1ODUxNGRmNTA4Y2ZmMDNlY2UyOGUwODhiYmZiN2IwZTgwNGYyIiwidmVyc2lvbl9pZCI6NzQ3NX0seyJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiI3MTllZTcyNTQwZmYxMjJjYmM2MmYxZmI0YmJlN2Q4ZGIxZWVkYjk4YmYzZGIzNjA2ZDlmOGZjOTU1M2Q1NWIwIiwiY2hhbm5lbF9pZCI6Ii04MDQ5MjI0NDIwOTY4MyIsImhvdGVsX2lkIjo1MTkzMSwibWVzc2FnZV9pZCI6Im1pZC5mZmZmYjZjYWYwNjA2ZmVkMDFhMWVhOTdjMzkxNjE5NyIsInBob3RvX3NoYTI1NiI6ImQxZGM1ZjQzYjQyYTczNThkN2M0YmFmYzM0OTc1Y2IyNmQ1ZTk2NTRkOTY2NmNiN2M0OTI2YWQ4YTJhMWU0ZWQiLCJwbGF0Zm9ybSI6Im1heCIsInBvc3RfdXJsIjoiaHR0cHM6Ly9tYXgucnUvY2hhbm5lbF9hbnl0b3VyL0FhSHFsOE9SWVpjIiwicHVibGljYXRpb25faWQiOjIwOTYsInRleHRfc2hhMjU2IjoiZjBhM2FjYzU5ZWI5N2FhZGFmNTZlZTMxNGI5NTg1MTRkZjUwOGNmZjAzZWNlMjhlMDg4YmJmYjdiMGU4MDRmMiIsInZlcnNpb25faWQiOjc2MjV9XQ==',true),true,64,JSON_THROW_ON_ERROR);
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
    $out['scope_hotels']=9;$out['feed_rows']=count($built['rows']);$out['deferred_count']=count($built['deferred']);$out['installed_sha']=EXPECTED_INSTALLED_SHA;$out['ok']=true;

} catch(Throwable $e){
    if(isset($catalog)&&$catalog->inTransaction())$catalog->rollBack();
    if(isset($registry)&&$registry->inTransaction())$registry->rollBack();
    $out['error']=preg_match('/\A[a-z][a-z0-9_]{1,100}\z/D',$e->getMessage())?$e->getMessage():'bounded_catalog_read_failed';
}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
exit($out['ok']?0:2);

