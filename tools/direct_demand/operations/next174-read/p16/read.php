<?php
declare(strict_types=1);
const EXPECTED_INSTALLED_SHA='bd8e248f7138ea75cb9fda2dcf44beb631c633a5';
const FEED_EXPORT_INPUT_B64='W3siYWNjZXB0ZWRfZmlyc3RfbGluZSI6IuKciO+4jyDQotGD0YDRiyDQsiBLYWlTb2wgUm9tYW5jZSBTYWhsIEhhc2hlZXNoIiwiaG90ZWxfaWQiOjM1NywiaW1hZ2Vfc2hhMjU2IjoiZTIyNzYxYmYyODRhOGVkYTZkMGQzYTBiNWE5MzUzNDVjNDNmMGFiYWJjNTdiMGVmYzBjYWFlMDNlZTg5OTA1YSIsImltYWdlX3VybCI6Imh0dHBzOi8vcmF3LmdpdGh1YnVzZXJjb250ZW50LmNvbS9weWF0a29mZi9tYXgtc2VhcmNoLWJvdC8xMGNkMzcxM2UxM2E3NjJjNTU4NTQzNDA3MmY5ZmI2ZmNkMGUyZWZmLy5naXRodWIvdHJhZmZpYy1wdWJsaXNoL2NvbW1lcmNpYWwtd2F2ZTctMjAyNjExMTgvMzU3LnBuZyIsIm5hbWUiOiJLYWlTb2wgUm9tYW5jZSBTYWhsIEhhc2hlZXNoIn0seyJhY2NlcHRlZF9maXJzdF9saW5lIjoi4pyI77iPINCi0YPRgNGLINCyIFNvZml0ZWwgRHViYWkgVGhlIFBhbG0iLCJob3RlbF9pZCI6MjI2NzYsImltYWdlX3NoYTI1NiI6Ijc1ZGMyNGFiOWUyZjU4MTk3MDJmN2Y5OGY1NWE5NjI3NWEyMTY5NjQ4MjI3NTkwMTIyZDI4NWFiMDgwZjk3NjYiLCJpbWFnZV91cmwiOiJodHRwczovL3Jhdy5naXRodWJ1c2VyY29udGVudC5jb20vcHlhdGtvZmYvbWF4LXNlYXJjaC1ib3QvMTBjZDM3MTNlMTNhNzYyYzU1ODU0MzQwNzJmOWZiNmZjZDBlMmVmZi8uZ2l0aHViL3RyYWZmaWMtcHVibGlzaC9jb21tZXJjaWFsLXdhdmU3LTIwMjYxMTE4LzIyNjc2LnBuZyIsIm5hbWUiOiJTb2ZpdGVsIER1YmFpIFRoZSBQYWxtIn0seyJhY2NlcHRlZF9maXJzdF9saW5lIjoi4pyI77iPINCi0YPRgNGLINCyIFRVSSBNYWdpYyBMaWZlIFNhcmlnZXJtZSIsImhvdGVsX2lkIjo1OTA4NCwiaW1hZ2Vfc2hhMjU2IjoiYzg2MjhlM2M0YjY0MTY3MjQ2NjRkNjYxNmNmYzEzZjI4ZGU3NDY4NmQ0MDU2YWMyNzFmNTU4ODlhYjk0NmVjZSIsImltYWdlX3VybCI6Imh0dHBzOi8vcmF3LmdpdGh1YnVzZXJjb250ZW50LmNvbS9weWF0a29mZi9tYXgtc2VhcmNoLWJvdC8xMGNkMzcxM2UxM2E3NjJjNTU4NTQzNDA3MmY5ZmI2ZmNkMGUyZWZmLy5naXRodWIvdHJhZmZpYy1wdWJsaXNoL2NvbW1lcmNpYWwtd2F2ZTctMjAyNjExMTgvNTkwODQucG5nIiwibmFtZSI6IlRVSSBNYWdpYyBMaWZlIFNhcmlnZXJtZSJ9LHsiYWNjZXB0ZWRfZmlyc3RfbGluZSI6IuKciO+4jyDQotGD0YDRiyDQsiBSYWRpc3NvbiBCbHUgUmVzb3J0IEZ1amFpcmFoIiwiaG90ZWxfaWQiOjQ1MTgsImltYWdlX3NoYTI1NiI6ImFjOTE3NzQ4MDBlYjk0ZWQ3MWU3MzU4MzZmODY3MTM0ZjA5NjVjNDI4ZmYzMjUxYzk1MDdmYzQ3Y2ZhNjMyNGYiLCJpbWFnZV91cmwiOiJodHRwczovL3Jhdy5naXRodWJ1c2VyY29udGVudC5jb20vcHlhdGtvZmYvbWF4LXNlYXJjaC1ib3QvMTBjZDM3MTNlMTNhNzYyYzU1ODU0MzQwNzJmOWZiNmZjZDBlMmVmZi8uZ2l0aHViL3RyYWZmaWMtcHVibGlzaC9jb21tZXJjaWFsLXdhdmU3LTIwMjYxMTE4LzQ1MTgucG5nIiwibmFtZSI6IlJhZGlzc29uIEJsdSBSZXNvcnQgRnVqYWlyYWgifSx7ImFjY2VwdGVkX2ZpcnN0X2xpbmUiOiLinIjvuI8g0KLRg9GA0Ysg0LIgU2lkZSBSb3lhbCBTdHlsZSBIb3RlbCIsImhvdGVsX2lkIjo2NTM0MiwiaW1hZ2Vfc2hhMjU2IjoiYTYyNjEwNGNkOGRlMzQwMGY5YzIwN2ZlMWU5MzA4ZGRiMDIzODJkMmNjNTVhNTRjMTNiOTVmYjlkN2RmYTczNSIsImltYWdlX3VybCI6Imh0dHBzOi8vcmF3LmdpdGh1YnVzZXJjb250ZW50LmNvbS9weWF0a29mZi9tYXgtc2VhcmNoLWJvdC8xMGNkMzcxM2UxM2E3NjJjNTU4NTQzNDA3MmY5ZmI2ZmNkMGUyZWZmLy5naXRodWIvdHJhZmZpYy1wdWJsaXNoL2NvbW1lcmNpYWwtd2F2ZTctMjAyNjExMTgvNjUzNDIucG5nIiwibmFtZSI6IlNpZGUgUm95YWwgU3R5bGUgSG90ZWwifSx7ImFjY2VwdGVkX2ZpcnN0X2xpbmUiOiLinIjvuI8g0KLRg9GA0Ysg0LIgTGF6dWxpIEhvdGVsIE1hcnNhIEFsYW0iLCJob3RlbF9pZCI6NzYxMDksImltYWdlX3NoYTI1NiI6ImNjMTQ3NDhkNmVkNDE0YjFlMWI4OTg4ZDA3ZTg4ZGQzMmEyYzg5ODhhZDBmNWJmMjdmODFkZWIxNDhjYWU0ZDUiLCJpbWFnZV91cmwiOiJodHRwczovL3Jhdy5naXRodWJ1c2VyY29udGVudC5jb20vcHlhdGtvZmYvbWF4LXNlYXJjaC1ib3QvMTBjZDM3MTNlMTNhNzYyYzU1ODU0MzQwNzJmOWZiNmZjZDBlMmVmZi8uZ2l0aHViL3RyYWZmaWMtcHVibGlzaC9jb21tZXJjaWFsLXdhdmU3LTIwMjYxMTE4Lzc2MTA5LnBuZyIsIm5hbWUiOiJMYXp1bGkgSG90ZWwgTWFyc2EgQWxhbSJ9LHsiYWNjZXB0ZWRfZmlyc3RfbGluZSI6IuKciO+4jyDQotGD0YDRiyDQsiBTTy8gUmFzIEFsIEtoYWltYWgiLCJob3RlbF9pZCI6MTM1NDQyLCJpbWFnZV9zaGEyNTYiOiIyZmM1ODRhZmM0ZDE4YTQ4YTAzYzYyNDA0ZGFkZjkwNDE1MjgzOGI5ZjI2MDYxMzBjMDBiYjE5YmNkMzMzYTU2IiwiaW1hZ2VfdXJsIjoiaHR0cHM6Ly9yYXcuZ2l0aHVidXNlcmNvbnRlbnQuY29tL3B5YXRrb2ZmL21heC1zZWFyY2gtYm90LzEwY2QzNzEzZTEzYTc2MmM1NTg1NDM0MDcyZjlmYjZmY2QwZTJlZmYvLmdpdGh1Yi90cmFmZmljLXB1Ymxpc2gvY29tbWVyY2lhbC13YXZlNy0yMDI2MTExOC8xMzU0NDIucG5nIiwibmFtZSI6IlNPLyBSYXMgQWwgS2hhaW1haCJ9LHsiYWNjZXB0ZWRfZmlyc3RfbGluZSI6IuKciO+4jyDQotGD0YDRiyDQsiBUVUkgTWFnaWMgTGlmZSBKYWNhcmFuZGEiLCJob3RlbF9pZCI6Mjg0NzAsImltYWdlX3NoYTI1NiI6IjUyNTE2Njg1M2YxNGM0MjhmYWMyMDNiYzE1ZWVlNTQ2YTljZGU0MTNjYTE3NzU3ODc0YTU0MGI4YTM3OTA4MmEiLCJpbWFnZV91cmwiOiJodHRwczovL3Jhdy5naXRodWJ1c2VyY29udGVudC5jb20vcHlhdGtvZmYvbWF4LXNlYXJjaC1ib3QvMTBjZDM3MTNlMTNhNzYyYzU1ODU0MzQwNzJmOWZiNmZjZDBlMmVmZi8uZ2l0aHViL3RyYWZmaWMtcHVibGlzaC9jb21tZXJjaWFsLXdhdmU3LTIwMjYxMTE4LzI4NDcwLnBuZyIsIm5hbWUiOiJUVUkgTWFnaWMgTGlmZSBKYWNhcmFuZGEifSx7ImFjY2VwdGVkX2ZpcnN0X2xpbmUiOiLinIjvuI8g0KLRg9GA0Ysg0LIgUHJpbWEgTGlmZSBNYWthZGkiLCJob3RlbF9pZCI6MzU5LCJpbWFnZV9zaGEyNTYiOiJjMGRmZjQ4ZTZmN2NiYWRkMGU5MDU3ZTFkY2NkYjczYjUyNTIyMTlhNTg1YTEyYTIyMDg0ODZjMjk0YTdkMDRkIiwiaW1hZ2VfdXJsIjoiaHR0cHM6Ly9yYXcuZ2l0aHVidXNlcmNvbnRlbnQuY29tL3B5YXRrb2ZmL21heC1zZWFyY2gtYm90LzEwY2QzNzEzZTEzYTc2MmM1NTg1NDM0MDcyZjlmYjZmY2QwZTJlZmYvLmdpdGh1Yi90cmFmZmljLXB1Ymxpc2gvY29tbWVyY2lhbC13YXZlNy0yMDI2MTExOC8zNTkucG5nIiwibmFtZSI6IlByaW1hIExpZmUgTWFrYWRpIn1d';
const FEED_EXPORT_INPUT_SHA256='ff792483fe7cc07682f76ab040ad07a7e1b7c1ec2a8c2f64d4dc0045aed151f0';
const CANDIDATE_INPUT_B64='W10=';
const CANDIDATE_INPUT_SHA256='4f53cda18c2baa0c0354bb5f9a3ecbe5ed12ab4d8e11ba873c2f11161202b945';
ini_set('display_errors','0');ini_set('log_errors','0');
$out=['operation'=>'commercial-channel-hotel-feeds-20261118-next174-read16','mode'=>'read_bounded_public_channel_post_feed_inputs','ok'=>false,'db_writes'=>0,'provider_calls'=>0,'message_sends'=>0];
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
 insist(count($in)===9&&array_column($in,'hotel_id')===[357,22676,59084,4518,65342,76109,135442,28470,359],'read17_exact_new1_scope');
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
    $retained=json_decode(base64_decode('W3siYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiYTA5ZDg4ODE0ZWNiMTMzZjVkY2UxNWExNmJlYTdhYTRkMzUzNThiZWFlODIzZWExYmQyNDgwZjk1NGRkODNhYiIsImNoYW5uZWxfaWQiOiItMTAwMjI2MjY2NjYxNiIsImhvdGVsX2lkIjozNTcsIm1lc3NhZ2VfaWQiOiIxMTM4IiwicGhvdG9fc2hhMjU2IjoiZTIyNzYxYmYyODRhOGVkYTZkMGQzYTBiNWE5MzUzNDVjNDNmMGFiYWJjNTdiMGVmYzBjYWFlMDNlZTg5OTA1YSIsInBsYXRmb3JtIjoidGVsZWdyYW0iLCJwb3N0X3VybCI6Imh0dHBzOi8vdC5tZS9hbnlfdG9vdXIvMTEzOCIsInB1YmxpY2F0aW9uX2lkIjoyNDMwLCJ0ZXh0X3NoYTI1NiI6ImExOTU0NjhjZDc3ZjJjYzJhNTg3MzIyZDYxYTYxOGI1OGFkMjA1NTM4MmFkYWI1MzJhZGRmNzJjYzNmMGU5MmUiLCJ2ZXJzaW9uX2lkIjo4MTQ4fSx7ImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6IjE1OTFmOGQyMzhiMmI1MWMwZmFhNjRjMTMwNDc5NDU0ZTM3ZDJmZjZkNWFiYzZlZTQwYzYyODdmOWE3NmRhYjkiLCJjaGFubmVsX2lkIjoiLTgwNDkyMjQ0MjA5NjgzIiwiaG90ZWxfaWQiOjM1NywibWVzc2FnZV9pZCI6Im1pZC5mZmZmYjZjYWYwNjA2ZmVkMDFhMWVjMGJkMjhmMWZlMSIsInBob3RvX3NoYTI1NiI6ImUyMjc2MWJmMjg0YThlZGE2ZDBkM2EwYjVhOTM1MzQ1YzQzZjBhYmFiYzU3YjBlZmMwY2FhZTAzZWU4OTkwNWEiLCJwbGF0Zm9ybSI6Im1heCIsInBvc3RfdXJsIjoiaHR0cHM6Ly9tYXgucnUvY2hhbm5lbF9hbnl0b3VyL0FhSHNDOUtQSC1FIiwicHVibGljYXRpb25faWQiOjI0MzksInRleHRfc2hhMjU2IjoiYTE5NTQ2OGNkNzdmMmNjMmE1ODczMjJkNjFhNjE4YjU4YWQyMDU1MzgyYWRhYjUzMmFkZGY3MmNjM2YwZTkyZSIsInZlcnNpb25faWQiOjgxNTd9LHsiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiYTBiM2JmY2FlOTZjZTc2MGUxNTJlMGUyYjA4YjRiMWI3MzIwMmMwYzE2ODhjMzYyZjE4M2E2ZjkxN2IxYmRmMyIsImNoYW5uZWxfaWQiOiItMTAwMjI2MjY2NjYxNiIsImhvdGVsX2lkIjoyMjY3NiwibWVzc2FnZV9pZCI6IjExMzkiLCJwaG90b19zaGEyNTYiOiI3NWRjMjRhYjllMmY1ODE5NzAyZjdmOThmNTVhOTYyNzVhMjE2OTY0ODIyNzU5MDEyMmQyODVhYjA4MGY5NzY2IiwicGxhdGZvcm0iOiJ0ZWxlZ3JhbSIsInBvc3RfdXJsIjoiaHR0cHM6Ly90Lm1lL2FueV90b291ci8xMTM5IiwicHVibGljYXRpb25faWQiOjI0MzEsInRleHRfc2hhMjU2IjoiZjUwZmVhNmNhYTkxNTE5NjcwZjRmN2NjYmVkZTgxNThjN2IxMzZiMzlkMGU5ZDliNWMyZmViZDRjZjQ1N2UxOSIsInZlcnNpb25faWQiOjgxNDl9LHsiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiNTRmMmI1ZWNkZTEwNTI2YzFkYjBmMzY0NGM2YTc5OTcyMDBiN2E0OGE3NDRkNTA0NGVlOGQwZmM0MWVlYWRkMiIsImNoYW5uZWxfaWQiOiItODA0OTIyNDQyMDk2ODMiLCJob3RlbF9pZCI6MjI2NzYsIm1lc3NhZ2VfaWQiOiJtaWQuZmZmZmI2Y2FmMDYwNmZlZDAxYTFlYzBiZGNjNTZhNTAiLCJwaG90b19zaGEyNTYiOiI3NWRjMjRhYjllMmY1ODE5NzAyZjdmOThmNTVhOTYyNzVhMjE2OTY0ODIyNzU5MDEyMmQyODVhYjA4MGY5NzY2IiwicGxhdGZvcm0iOiJtYXgiLCJwb3N0X3VybCI6Imh0dHBzOi8vbWF4LnJ1L2NoYW5uZWxfYW55dG91ci9BYUhzQzl6RmFsQSIsInB1YmxpY2F0aW9uX2lkIjoyNDQwLCJ0ZXh0X3NoYTI1NiI6ImY1MGZlYTZjYWE5MTUxOTY3MGY0ZjdjY2JlZGU4MTU4YzdiMTM2YjM5ZDBlOWQ5YjVjMmZlYmQ0Y2Y0NTdlMTkiLCJ2ZXJzaW9uX2lkIjo4MTU4fSx7ImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6ImZiYWU2MTEwNTg0NzcyYmFkZDc5N2M5NmI5YjU0N2M5MjhkYWYxMWIzNTYxZmU2ZTM5MDQ5ZGM1OWZkMjI0OGEiLCJjaGFubmVsX2lkIjoiLTEwMDIyNjI2NjY2MTYiLCJob3RlbF9pZCI6NTkwODQsIm1lc3NhZ2VfaWQiOiIxMTQwIiwicGhvdG9fc2hhMjU2IjoiYzg2MjhlM2M0YjY0MTY3MjQ2NjRkNjYxNmNmYzEzZjI4ZGU3NDY4NmQ0MDU2YWMyNzFmNTU4ODlhYjk0NmVjZSIsInBsYXRmb3JtIjoidGVsZWdyYW0iLCJwb3N0X3VybCI6Imh0dHBzOi8vdC5tZS9hbnlfdG9vdXIvMTE0MCIsInB1YmxpY2F0aW9uX2lkIjoyNDMyLCJ0ZXh0X3NoYTI1NiI6ImNhY2M1MGE4MWJhNjY3Yzg1MDYyMDNmYzdiY2NjOGNiODRmNjIzMmI5MmE5MDA3ZmNjNmIwMWJmZjI0NDQ5MWIiLCJ2ZXJzaW9uX2lkIjo4MTUwfSx7ImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6ImZlNjYxNzFhZDQ3YzQ3YmJmNWI5NGZhNjg5OGY3MDc2YzY0ZWQzYTY2YmU2OGI5ZTM0MDk2NmQzZTkyMmRhMDEiLCJjaGFubmVsX2lkIjoiLTgwNDkyMjQ0MjA5NjgzIiwiaG90ZWxfaWQiOjU5MDg0LCJtZXNzYWdlX2lkIjoibWlkLmZmZmZiNmNhZjA2MDZmZWQwMWExZWMwYmU2NjkxOGIwIiwicGhvdG9fc2hhMjU2IjoiYzg2MjhlM2M0YjY0MTY3MjQ2NjRkNjYxNmNmYzEzZjI4ZGU3NDY4NmQ0MDU2YWMyNzFmNTU4ODlhYjk0NmVjZSIsInBsYXRmb3JtIjoibWF4IiwicG9zdF91cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FueXRvdXIvQWFIc0MtWnBHTEEiLCJwdWJsaWNhdGlvbl9pZCI6MjQ0MSwidGV4dF9zaGEyNTYiOiJjYWNjNTBhODFiYTY2N2M4NTA2MjAzZmM3YmNjYzhjYjg0ZjYyMzJiOTJhOTAwN2ZjYzZiMDFiZmYyNDQ0OTFiIiwidmVyc2lvbl9pZCI6ODE1OX0seyJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiJiYzY3YTE4Mzc4YTVkNjE1N2E0NzUyZmIwY2ExMzBmNWQ1NTYwMDViMmJjMTVmMWYxNGY5MWNiOTIwMjA0Y2ZhIiwiY2hhbm5lbF9pZCI6Ii0xMDAyMjYyNjY2NjE2IiwiaG90ZWxfaWQiOjQ1MTgsIm1lc3NhZ2VfaWQiOiIxMTQxIiwicGhvdG9fc2hhMjU2IjoiYWM5MTc3NDgwMGViOTRlZDcxZTczNTgzNmY4NjcxMzRmMDk2NWM0MjhmZjMyNTFjOTUwN2ZjNDdjZmE2MzI0ZiIsInBsYXRmb3JtIjoidGVsZWdyYW0iLCJwb3N0X3VybCI6Imh0dHBzOi8vdC5tZS9hbnlfdG9vdXIvMTE0MSIsInB1YmxpY2F0aW9uX2lkIjoyNDMzLCJ0ZXh0X3NoYTI1NiI6IjhlMGFmYjg0YWM4MzA5MGNmYjVjM2Y1MWExZGZhOGMxY2NjOWVjMjRmZjliNWEwNWE4OWZiNGFlNGVkNWQ3YzQiLCJ2ZXJzaW9uX2lkIjo4MTUxfSx7ImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6IjcwYWFlOWNiODI2ZDkzZTEzZTdmN2QyNTBmYTRmMzVkYjQ2NjE0YjU0OGU0ZGVhMjg4MTQ2ZDZjMjdlMjFlMzQiLCJjaGFubmVsX2lkIjoiLTgwNDkyMjQ0MjA5NjgzIiwiaG90ZWxfaWQiOjQ1MTgsIm1lc3NhZ2VfaWQiOiJtaWQuZmZmZmI2Y2FmMDYwNmZlZDAxYTFlYzBiZWZiYTNhYmMiLCJwaG90b19zaGEyNTYiOiJhYzkxNzc0ODAwZWI5NGVkNzFlNzM1ODM2Zjg2NzEzNGYwOTY1YzQyOGZmMzI1MWM5NTA3ZmM0N2NmYTYzMjRmIiwicGxhdGZvcm0iOiJtYXgiLCJwb3N0X3VybCI6Imh0dHBzOi8vbWF4LnJ1L2NoYW5uZWxfYW55dG91ci9BYUhzQy0tNk9ydyIsInB1YmxpY2F0aW9uX2lkIjoyNDQyLCJ0ZXh0X3NoYTI1NiI6IjhlMGFmYjg0YWM4MzA5MGNmYjVjM2Y1MWExZGZhOGMxY2NjOWVjMjRmZjliNWEwNWE4OWZiNGFlNGVkNWQ3YzQiLCJ2ZXJzaW9uX2lkIjo4MTYwfSx7ImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6IjRjMzhlN2U3YzQxNjc5OTJhYWQ4YjFkYzQxMWMzZWQyMmI0OTBkYzc3MjZiZDUwZDNlNmZiZDBhODcyN2VkOWIiLCJjaGFubmVsX2lkIjoiLTEwMDIyNjI2NjY2MTYiLCJob3RlbF9pZCI6NjUzNDIsIm1lc3NhZ2VfaWQiOiIxMTQyIiwicGhvdG9fc2hhMjU2IjoiYTYyNjEwNGNkOGRlMzQwMGY5YzIwN2ZlMWU5MzA4ZGRiMDIzODJkMmNjNTVhNTRjMTNiOTVmYjlkN2RmYTczNSIsInBsYXRmb3JtIjoidGVsZWdyYW0iLCJwb3N0X3VybCI6Imh0dHBzOi8vdC5tZS9hbnlfdG9vdXIvMTE0MiIsInB1YmxpY2F0aW9uX2lkIjoyNDM0LCJ0ZXh0X3NoYTI1NiI6IjA5YjRlZDdjZDI0YmMzY2UxNjc2NTI4YjY1YTA2ZjJlYjYxZDI4NTNlNjU1YjI2ZmU1ZDlmZDVmNTAxNDc4ZDAiLCJ2ZXJzaW9uX2lkIjo4MTUyfSx7ImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6IjZjZDhiMTFhZWRjNTFmODk3NGYxNTkzMDYxNjg2ZmQ4MzdkNDMzOGZjMGEwNDUzNWMzMTQ3YzRlMTlhYTA3YWQiLCJjaGFubmVsX2lkIjoiLTgwNDkyMjQ0MjA5NjgzIiwiaG90ZWxfaWQiOjY1MzQyLCJtZXNzYWdlX2lkIjoibWlkLmZmZmZiNmNhZjA2MDZmZWQwMWExZWMwYmY4YzA1ZTliIiwicGhvdG9fc2hhMjU2IjoiYTYyNjEwNGNkOGRlMzQwMGY5YzIwN2ZlMWU5MzA4ZGRiMDIzODJkMmNjNTVhNTRjMTNiOTVmYjlkN2RmYTczNSIsInBsYXRmb3JtIjoibWF4IiwicG9zdF91cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FueXRvdXIvQWFIc0NfakFYcHMiLCJwdWJsaWNhdGlvbl9pZCI6MjQ0MywidGV4dF9zaGEyNTYiOiIwOWI0ZWQ3Y2QyNGJjM2NlMTY3NjUyOGI2NWEwNmYyZWI2MWQyODUzZTY1NWIyNmZlNWQ5ZmQ1ZjUwMTQ3OGQwIiwidmVyc2lvbl9pZCI6ODE2MX0seyJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiJjZWJlMjFjYjY4OGNkODYwMGU1YzZlOTVkNzdhNzkzNDg5NjAxYzg5M2Y4NDAyYTJlODUxZjc2ZDU5NDBmYTJjIiwiY2hhbm5lbF9pZCI6Ii0xMDAyMjYyNjY2NjE2IiwiaG90ZWxfaWQiOjc2MTA5LCJtZXNzYWdlX2lkIjoiMTE0MyIsInBob3RvX3NoYTI1NiI6ImNjMTQ3NDhkNmVkNDE0YjFlMWI4OTg4ZDA3ZTg4ZGQzMmEyYzg5ODhhZDBmNWJmMjdmODFkZWIxNDhjYWU0ZDUiLCJwbGF0Zm9ybSI6InRlbGVncmFtIiwicG9zdF91cmwiOiJodHRwczovL3QubWUvYW55X3Rvb3VyLzExNDMiLCJwdWJsaWNhdGlvbl9pZCI6MjQzNSwidGV4dF9zaGEyNTYiOiIxNDRkMjRjNjI3MmQ5NDkyN2RlMTFmNzcyZDc1MDNmMTgxYjQ4NGIzMDhkNmQ1NTllOTZjYTY2YWNmNGM1M2ZhIiwidmVyc2lvbl9pZCI6ODE1M30seyJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiJjZmE0YzZmZGNiMGExMjRkNTJiY2VjNThkZTMzOTZiMjM5ODA1MWQ3MmY2ZDcyYzdlMDVlNWJiZWQ4ZjkzNjZhIiwiY2hhbm5lbF9pZCI6Ii04MDQ5MjI0NDIwOTY4MyIsImhvdGVsX2lkIjo3NjEwOSwibWVzc2FnZV9pZCI6Im1pZC5mZmZmYjZjYWYwNjA2ZmVkMDFhMWVjMGMwMjczNzlmZSIsInBob3RvX3NoYTI1NiI6ImNjMTQ3NDhkNmVkNDE0YjFlMWI4OTg4ZDA3ZTg4ZGQzMmEyYzg5ODhhZDBmNWJmMjdmODFkZWIxNDhjYWU0ZDUiLCJwbGF0Zm9ybSI6Im1heCIsInBvc3RfdXJsIjoiaHR0cHM6Ly9tYXgucnUvY2hhbm5lbF9hbnl0b3VyL0FhSHNEQUp6ZWY0IiwicHVibGljYXRpb25faWQiOjI0NDQsInRleHRfc2hhMjU2IjoiMTQ0ZDI0YzYyNzJkOTQ5MjdkZTExZjc3MmQ3NTAzZjE4MWI0ODRiMzA4ZDZkNTU5ZTk2Y2E2NmFjZjRjNTNmYSIsInZlcnNpb25faWQiOjgxNjJ9LHsiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiY2JjMjNhNDdlMWE4ZGJmNjJjYTZhMmQ2MmI1NTE0NjYzY2MzMDllZjI4NTA4ZWE0MGMyNWEwZGE3NDk0MGUyOCIsImNoYW5uZWxfaWQiOiItMTAwMjI2MjY2NjYxNiIsImhvdGVsX2lkIjoxMzU0NDIsIm1lc3NhZ2VfaWQiOiIxMTQ0IiwicGhvdG9fc2hhMjU2IjoiMmZjNTg0YWZjNGQxOGE0OGEwM2M2MjQwNGRhZGY5MDQxNTI4MzhiOWYyNjA2MTMwYzAwYmIxOWJjZDMzM2E1NiIsInBsYXRmb3JtIjoidGVsZWdyYW0iLCJwb3N0X3VybCI6Imh0dHBzOi8vdC5tZS9hbnlfdG9vdXIvMTE0NCIsInB1YmxpY2F0aW9uX2lkIjoyNDM2LCJ0ZXh0X3NoYTI1NiI6ImEyNmEzM2QxYTlmZTFiOGJmNDg3OTY4NjA4NjM2ODYyMDNkMjBkN2U2OWQ0ZThiZTMzNjFmYzY2NGQwMGZlN2QiLCJ2ZXJzaW9uX2lkIjo4MTU0fSx7ImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6ImMyZjYzZGMzMmQzMGNjMjNlZjgwNjQ5ZWU3N2E1NWEzMmIxMTdiNjFmMGFkYmFkNTJjYzQ3YmI5ZWMzYmZhM2MiLCJjaGFubmVsX2lkIjoiLTgwNDkyMjQ0MjA5NjgzIiwiaG90ZWxfaWQiOjEzNTQ0MiwibWVzc2FnZV9pZCI6Im1pZC5mZmZmYjZjYWYwNjA2ZmVkMDFhMWVjMGMwYjk0NzFjZiIsInBob3RvX3NoYTI1NiI6IjJmYzU4NGFmYzRkMThhNDhhMDNjNjI0MDRkYWRmOTA0MTUyODM4YjlmMjYwNjEzMGMwMGJiMTliY2QzMzNhNTYiLCJwbGF0Zm9ybSI6Im1heCIsInBvc3RfdXJsIjoiaHR0cHM6Ly9tYXgucnUvY2hhbm5lbF9hbnl0b3VyL0FhSHNEQXVVY2M4IiwicHVibGljYXRpb25faWQiOjI0NDUsInRleHRfc2hhMjU2IjoiYTI2YTMzZDFhOWZlMWI4YmY0ODc5Njg2MDg2MzY4NjIwM2QyMGQ3ZTY5ZDRlOGJlMzM2MWZjNjY0ZDAwZmU3ZCIsInZlcnNpb25faWQiOjgxNjN9LHsiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiYTNhYzU0Zjc4NzhmNWUyNjQwYmZmNmI5Mjc2ZjhjOTkxMDIzYzk0NDNhNTYzNmViZGRhNTgwNGE4ZGQ0YmIwMSIsImNoYW5uZWxfaWQiOiItMTAwMjI2MjY2NjYxNiIsImhvdGVsX2lkIjoyODQ3MCwibWVzc2FnZV9pZCI6IjExNDUiLCJwaG90b19zaGEyNTYiOiI1MjUxNjY4NTNmMTRjNDI4ZmFjMjAzYmMxNWVlZTU0NmE5Y2RlNDEzY2ExNzc1Nzg3NGE1NDBiOGEzNzkwODJhIiwicGxhdGZvcm0iOiJ0ZWxlZ3JhbSIsInBvc3RfdXJsIjoiaHR0cHM6Ly90Lm1lL2FueV90b291ci8xMTQ1IiwicHVibGljYXRpb25faWQiOjI0MzcsInRleHRfc2hhMjU2IjoiOGMyOTM5OGVlYWFiZjQwZGFjMTI1N2QxNjRkNTAzNTZlNDNjNGYzMTYzOTc3YTJmNTA3MTM1YTIyOTIzYWExZSIsInZlcnNpb25faWQiOjgxNTV9LHsiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiNmJiM2ZiZmM3ZTNkMjQxNmQ5OWMyZGM1NDg0ZmE4NTNiYTk3NWRhM2M3MzQ0MmNlMjQ3OWU2MmE1MGQ0OTQzNSIsImNoYW5uZWxfaWQiOiItODA0OTIyNDQyMDk2ODMiLCJob3RlbF9pZCI6Mjg0NzAsIm1lc3NhZ2VfaWQiOiJtaWQuZmZmZmI2Y2FmMDYwNmZlZDAxYTFlYzBjMTRlZjM0NjkiLCJwaG90b19zaGEyNTYiOiI1MjUxNjY4NTNmMTRjNDI4ZmFjMjAzYmMxNWVlZTU0NmE5Y2RlNDEzY2ExNzc1Nzg3NGE1NDBiOGEzNzkwODJhIiwicGxhdGZvcm0iOiJtYXgiLCJwb3N0X3VybCI6Imh0dHBzOi8vbWF4LnJ1L2NoYW5uZWxfYW55dG91ci9BYUhzREJUdk5HayIsInB1YmxpY2F0aW9uX2lkIjoyNDQ2LCJ0ZXh0X3NoYTI1NiI6IjhjMjkzOThlZWFhYmY0MGRhYzEyNTdkMTY0ZDUwMzU2ZTQzYzRmMzE2Mzk3N2EyZjUwNzEzNWEyMjkyM2FhMWUiLCJ2ZXJzaW9uX2lkIjo4MTY0fSx7ImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6ImIyYWJlNTNkYzRjYzk5NjdhN2U1YzAyY2ViZWZiMzUyMDRlMWM3OGYyNDgwOWJkMWM0NGExNjk3ZmRkZTgzNTAiLCJjaGFubmVsX2lkIjoiLTEwMDIyNjI2NjY2MTYiLCJob3RlbF9pZCI6MzU5LCJtZXNzYWdlX2lkIjoiMTE0NiIsInBob3RvX3NoYTI1NiI6ImMwZGZmNDhlNmY3Y2JhZGQwZTkwNTdlMWRjY2RiNzNiNTI1MjIxOWE1ODVhMTJhMjIwODQ4NmMyOTRhN2QwNGQiLCJwbGF0Zm9ybSI6InRlbGVncmFtIiwicG9zdF91cmwiOiJodHRwczovL3QubWUvYW55X3Rvb3VyLzExNDYiLCJwdWJsaWNhdGlvbl9pZCI6MjQzOCwidGV4dF9zaGEyNTYiOiJhZjU0NGRhNGE1NTYxNGNlYmY2YjdkYTQ2ODI2ZWQ4ODE0YmU5MmNiYjBiZjc4NTFkODJmZjdiMzdkNzI3N2VkIiwidmVyc2lvbl9pZCI6ODE1Nn0seyJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiI0YjMyMTMwZWY1ODYxMmJlYmMwZjY5ZWFmNmM4NjU5OTJjZTI0YmFlMGY5YjM5ZTg3NjYzOTM4Yjk5ODgwM2MzIiwiY2hhbm5lbF9pZCI6Ii04MDQ5MjI0NDIwOTY4MyIsImhvdGVsX2lkIjozNTksIm1lc3NhZ2VfaWQiOiJtaWQuZmZmZmI2Y2FmMDYwNmZlZDAxYTFlYzBjMWUwNjUwZGYiLCJwaG90b19zaGEyNTYiOiJjMGRmZjQ4ZTZmN2NiYWRkMGU5MDU3ZTFkY2NkYjczYjUyNTIyMTlhNTg1YTEyYTIyMDg0ODZjMjk0YTdkMDRkIiwicGxhdGZvcm0iOiJtYXgiLCJwb3N0X3VybCI6Imh0dHBzOi8vbWF4LnJ1L2NoYW5uZWxfYW55dG91ci9BYUhzREI0R1VOOCIsInB1YmxpY2F0aW9uX2lkIjoyNDQ3LCJ0ZXh0X3NoYTI1NiI6ImFmNTQ0ZGE0YTU1NjE0Y2ViZjZiN2RhNDY4MjZlZDg4MTRiZTkyY2JiMGJmNzg1MWQ4MmZmN2IzN2Q3Mjc3ZWQiLCJ2ZXJzaW9uX2lkIjo4MTY1fV0=',true),true,64,JSON_THROW_ON_ERROR);
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

