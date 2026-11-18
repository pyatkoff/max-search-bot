<?php
declare(strict_types=1);
const EXPECTED_INSTALLED_SHA='bd8e248f7138ea75cb9fda2dcf44beb631c633a5';
const FEED_EXPORT_INPUT_B64='W3siYWNjZXB0ZWRfZmlyc3RfbGluZSI6IuKciO+4jyDQotGD0YDRiyDQsiBSb3lhbCBBbGhhbWJyYSBQYWxhY2UiLCJob3RlbF9pZCI6NTU0NSwiaW1hZ2Vfc2hhMjU2IjoiNDJkMmRiYzA1ZTI1YzYwZmIzOGIzOWVlY2Y3MzJkZGMzOWVmNThjMGRlY2RjY2VlYTRlNGNiMjRiMmU5MjlkNCIsImltYWdlX3VybCI6Imh0dHBzOi8vcmF3LmdpdGh1YnVzZXJjb250ZW50LmNvbS9weWF0a29mZi9tYXgtc2VhcmNoLWJvdC9hZDFjM2UwOGU1M2IwMzEwMjM5ZTdjZDZhNmUyY2JhOTNhYzJmMzFjLy5naXRodWIvdHJhZmZpYy1wdWJsaXNoL2NvbW1lcmNpYWwtd2F2ZTYtbmV4dDUwLTIwMjYxMTE4LzU1NDUucG5nIiwibmFtZSI6IlJveWFsIEFsaGFtYnJhIFBhbGFjZSJ9LHsiYWNjZXB0ZWRfZmlyc3RfbGluZSI6IuKciO+4jyDQotGD0YDRiyDQsiBDIE1hdXJpdGl1cyIsImhvdGVsX2lkIjo2NDExMCwiaW1hZ2Vfc2hhMjU2IjoiZTIwMWE5N2UwYjllNTY4YjJlMjRlZDRkMmY1MTk4MGQyMzNlMDUyZTJhYTM2N2MxNmI0ZjRiZDVhZGY2MGIyNyIsImltYWdlX3VybCI6Imh0dHBzOi8vcmF3LmdpdGh1YnVzZXJjb250ZW50LmNvbS9weWF0a29mZi9tYXgtc2VhcmNoLWJvdC9hZDFjM2UwOGU1M2IwMzEwMjM5ZTdjZDZhNmUyY2JhOTNhYzJmMzFjLy5naXRodWIvdHJhZmZpYy1wdWJsaXNoL2NvbW1lcmNpYWwtd2F2ZTYtbmV4dDUwLTIwMjYxMTE4LzY0MTEwLnBuZyIsIm5hbWUiOiJDIE1hdXJpdGl1cyJ9LHsiYWNjZXB0ZWRfZmlyc3RfbGluZSI6IuKciO+4jyDQotGD0YDRiyDQsiBQYXJrIFJvdGFuYSIsImhvdGVsX2lkIjoyNjM1LCJpbWFnZV9zaGEyNTYiOiJkNTRlOTNmMGFiY2RkM2MyMWVhNGY1ODk0OGExY2I3YTY1MmJkYmUwOGIwNDU0M2UyNjNkYTZmMzllOTVkNTU4IiwiaW1hZ2VfdXJsIjoiaHR0cHM6Ly9yYXcuZ2l0aHVidXNlcmNvbnRlbnQuY29tL3B5YXRrb2ZmL21heC1zZWFyY2gtYm90L2FkMWMzZTA4ZTUzYjAzMTAyMzllN2NkNmE2ZTJjYmE5M2FjMmYzMWMvLmdpdGh1Yi90cmFmZmljLXB1Ymxpc2gvY29tbWVyY2lhbC13YXZlNi1uZXh0NTAtMjAyNjExMTgvMjYzNS5wbmciLCJuYW1lIjoiUGFyayBSb3RhbmEifSx7ImFjY2VwdGVkX2ZpcnN0X2xpbmUiOiLinIjvuI8g0KLRg9GA0Ysg0LIgTGUgTcOpcmlkaWVuIER1YmFpIEhvdGVsICYgQ29uZmVyZW5jZSBDZW50cmUiLCJob3RlbF9pZCI6ODkyMywiaW1hZ2Vfc2hhMjU2IjoiYWU3NWViYmMwMjE0N2ZmZjMwZjg0Zjg1ODg3NjFiNjRiZTNjYTZjNDdiMDM1MzczYjMzMWYzZmZiMDNhZDZiNSIsImltYWdlX3VybCI6Imh0dHBzOi8vcmF3LmdpdGh1YnVzZXJjb250ZW50LmNvbS9weWF0a29mZi9tYXgtc2VhcmNoLWJvdC9hZDFjM2UwOGU1M2IwMzEwMjM5ZTdjZDZhNmUyY2JhOTNhYzJmMzFjLy5naXRodWIvdHJhZmZpYy1wdWJsaXNoL2NvbW1lcmNpYWwtd2F2ZTYtbmV4dDUwLTIwMjYxMTE4Lzg5MjMucG5nIiwibmFtZSI6IkxlIE3DqXJpZGllbiBEdWJhaSBIb3RlbCAmIENvbmZlcmVuY2UgQ2VudHJlIn0seyJhY2NlcHRlZF9maXJzdF9saW5lIjoi4pyI77iPINCi0YPRgNGLINCyIFNPIFNvZml0ZWwgTWF1cml0aXVzIiwiaG90ZWxfaWQiOjExNzkyLCJpbWFnZV9zaGEyNTYiOiJiMjE4YzhiZTlmY2ZmZGVlYThhOTA4ODVjMDcxOTkxNDg4YmE2OWZhNjlmMGU2ZDJhMjVhYjVmMTNhZmY3NTY1IiwiaW1hZ2VfdXJsIjoiaHR0cHM6Ly9yYXcuZ2l0aHVidXNlcmNvbnRlbnQuY29tL3B5YXRrb2ZmL21heC1zZWFyY2gtYm90L2FkMWMzZTA4ZTUzYjAzMTAyMzllN2NkNmE2ZTJjYmE5M2FjMmYzMWMvLmdpdGh1Yi90cmFmZmljLXB1Ymxpc2gvY29tbWVyY2lhbC13YXZlNi1uZXh0NTAtMjAyNjExMTgvMTE3OTIucG5nIiwibmFtZSI6IlNPIFNvZml0ZWwgTWF1cml0aXVzIn0seyJhY2NlcHRlZF9maXJzdF9saW5lIjoi4pyI77iPINCi0YPRgNGLINCyIEFsIEJhbmRhciBBcmphYW4gYnkgUm90YW5hIiwiaG90ZWxfaWQiOjU5NTQ4LCJpbWFnZV9zaGEyNTYiOiIyODlkZjAxMjMzOGVmMDU2MDhmMjgzYTIxMjlhMWE2ZDM2MGI1MzUyYmNlYzcwYWE2ZWExNzUyZTJiNDU5ZTM3IiwiaW1hZ2VfdXJsIjoiaHR0cHM6Ly9yYXcuZ2l0aHVidXNlcmNvbnRlbnQuY29tL3B5YXRrb2ZmL21heC1zZWFyY2gtYm90L2FkMWMzZTA4ZTUzYjAzMTAyMzllN2NkNmE2ZTJjYmE5M2FjMmYzMWMvLmdpdGh1Yi90cmFmZmljLXB1Ymxpc2gvY29tbWVyY2lhbC13YXZlNi1uZXh0NTAtMjAyNjExMTgvNTk1NDgucG5nIiwibmFtZSI6IkFsIEJhbmRhciBBcmphYW4gYnkgUm90YW5hIn0seyJhY2NlcHRlZF9maXJzdF9saW5lIjoi4pyI77iPINCi0YPRgNGLINCyIEFsIEphZGRhZiBSb3RhbmEgU3VpdGUgSG90ZWwiLCJob3RlbF9pZCI6NzM5OTUsImltYWdlX3NoYTI1NiI6ImE3YWIyN2YxMmVkNThiMTRiZjk2NTZlZDE5MjlkODAyNGMxMjk1MWM0ODAyMjQxNjUwZGY3ZDVjOGQ5NGQxYmIiLCJpbWFnZV91cmwiOiJodHRwczovL3Jhdy5naXRodWJ1c2VyY29udGVudC5jb20vcHlhdGtvZmYvbWF4LXNlYXJjaC1ib3QvYWQxYzNlMDhlNTNiMDMxMDIzOWU3Y2Q2YTZlMmNiYTkzYWMyZjMxYy8uZ2l0aHViL3RyYWZmaWMtcHVibGlzaC9jb21tZXJjaWFsLXdhdmU2LW5leHQ1MC0yMDI2MTExOC83Mzk5NS5wbmciLCJuYW1lIjoiQWwgSmFkZGFmIFJvdGFuYSBTdWl0ZSBIb3RlbCJ9LHsiYWNjZXB0ZWRfZmlyc3RfbGluZSI6IuKciO+4jyDQotGD0YDRiyDQsiBUaGUgV0IgQWJ1IERoYWJpLCBDdXJpbyBDb2xsZWN0aW9uIGJ5IEhpbHRvbiIsImhvdGVsX2lkIjo3ODIxMCwiaW1hZ2Vfc2hhMjU2IjoiY2YxNzBkMzljMzM5ZjI0ZDE1ZTg1YmI5YjBiMGIzNzI0MzViNzNlYThhMDUwOTc1NjVhNjZmMDYzODY4YzJjNSIsImltYWdlX3VybCI6Imh0dHBzOi8vcmF3LmdpdGh1YnVzZXJjb250ZW50LmNvbS9weWF0a29mZi9tYXgtc2VhcmNoLWJvdC9hZDFjM2UwOGU1M2IwMzEwMjM5ZTdjZDZhNmUyY2JhOTNhYzJmMzFjLy5naXRodWIvdHJhZmZpYy1wdWJsaXNoL2NvbW1lcmNpYWwtd2F2ZTYtbmV4dDUwLTIwMjYxMTE4Lzc4MjEwLnBuZyIsIm5hbWUiOiJUaGUgV0IgQWJ1IERoYWJpLCBDdXJpbyBDb2xsZWN0aW9uIGJ5IEhpbHRvbiJ9XQ==';
const FEED_EXPORT_INPUT_SHA256='0b72cf64d6b4acecb42aee7dfd0a52ce8dc7725182281abbeea1829874772f57';
const CANDIDATE_INPUT_B64='W10=';
const CANDIDATE_INPUT_SHA256='4f53cda18c2baa0c0354bb5f9a3ecbe5ed12ab4d8e11ba873c2f11161202b945';
ini_set('display_errors','0');ini_set('log_errors','0');
$out=['operation'=>'commercial-channel-hotel-feeds-20261118-next174-read6','mode'=>'read_bounded_public_channel_post_feed_inputs','ok'=>false,'db_writes'=>0,'provider_calls'=>0,'message_sends'=>0];
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
 insist(count($in)===8&&array_column($in,'hotel_id')===[5545,64110,2635,8923,11792,59548,73995,78210],'read17_exact_new1_scope');
 insist(count(array_unique(array_column($in,'accepted_first_line')))===8,'read17_duplicate_firstline');
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
    $ids=array_column($inputs,'hotel_id');insist(count(array_unique($ids))===8,'feed_scope_duplicate');
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
    $out+=$built;insist(count($built['rows'])===8&&count($built['deferred'])===0,'new_reviewed_pairs_required');
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
    $retained=json_decode(base64_decode('W3siYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiYzNhNjRlYzI5NzQyNWY5NDdiNTJjZjk0OTlmNDRmMzRmZjFkNWI3MWQ1OTNjZjhiZjU2NjgyZGRmNTMxMTE1OCIsImNoYW5uZWxfaWQiOiItMTAwMjI2MjY2NjYxNiIsImhvdGVsX2lkIjo1NTQ1LCJtZXNzYWdlX2lkIjoiMTA2NSIsInBob3RvX3NoYTI1NiI6IjQyZDJkYmMwNWUyNWM2MGZiMzhiMzllZWNmNzMyZGRjMzllZjU4YzBkZWNkY2NlZWE0ZTRjYjI0YjJlOTI5ZDQiLCJwbGF0Zm9ybSI6InRlbGVncmFtIiwicG9zdF91cmwiOiJodHRwczovL3QubWUvYW55X3Rvb3VyLzEwNjUiLCJwdWJsaWNhdGlvbl9pZCI6MjI4NCwidGV4dF9zaGEyNTYiOiIzZGIwOTFkNDNlZTY4NTY0MzQ4NmI0YjYyMzM5MTI2MWE2Y2M0YzkyMTNiMmZkM2RjYTcwNzkzNGM5YzNmY2RjIiwidmVyc2lvbl9pZCI6NzgxM30seyJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiI3MDY5NzY2NWE3ZjRjZmUyZGU2ZDRiZGI4ZmZlNTk2ZmRkYjEwNmE1MjMyNWUzOGI0MDc5Y2VkYzI2OTY2MzUzIiwiY2hhbm5lbF9pZCI6Ii04MDQ5MjI0NDIwOTY4MyIsImhvdGVsX2lkIjo1NTQ1LCJtZXNzYWdlX2lkIjoibWlkLmZmZmZiNmNhZjA2MDZmZWQwMWExZWJjYjIwZDgzZjE3IiwicGhvdG9fc2hhMjU2IjoiNDJkMmRiYzA1ZTI1YzYwZmIzOGIzOWVlY2Y3MzJkZGMzOWVmNThjMGRlY2RjY2VlYTRlNGNiMjRiMmU5MjlkNCIsInBsYXRmb3JtIjoibWF4IiwicG9zdF91cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FueXRvdXIvQWFIcnl5RFlQeGMiLCJwdWJsaWNhdGlvbl9pZCI6MjI5NCwidGV4dF9zaGEyNTYiOiIzZGIwOTFkNDNlZTY4NTY0MzQ4NmI0YjYyMzM5MTI2MWE2Y2M0YzkyMTNiMmZkM2RjYTcwNzkzNGM5YzNmY2RjIiwidmVyc2lvbl9pZCI6NzgyM30seyJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiIxNTBiYmIyZjY5ZGM1MjUwMDU5NWM2Njk3OTUwMWI5OTc1ZjZkZjE0NWM5ZWE3ZWEyNjIyMWUxM2ZjMGQ0NzU2IiwiY2hhbm5lbF9pZCI6Ii0xMDAyMjYyNjY2NjE2IiwiaG90ZWxfaWQiOjY0MTEwLCJtZXNzYWdlX2lkIjoiMTA2NyIsInBob3RvX3NoYTI1NiI6ImUyMDFhOTdlMGI5ZTU2OGIyZTI0ZWQ0ZDJmNTE5ODBkMjMzZTA1MmUyYWEzNjdjMTZiNGY0YmQ1YWRmNjBiMjciLCJwbGF0Zm9ybSI6InRlbGVncmFtIiwicG9zdF91cmwiOiJodHRwczovL3QubWUvYW55X3Rvb3VyLzEwNjciLCJwdWJsaWNhdGlvbl9pZCI6MjI4NiwidGV4dF9zaGEyNTYiOiJkZDdkZTUxYTcyMDQxZWRhNDBlNjJhMTI1MTRkYjk2NDM5YTU4YjQyOGE0ODYzNTVkMDRkNWEyZTQyZjQyNTRlIiwidmVyc2lvbl9pZCI6NzgxNX0seyJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiJhMjVjNzI0ZTFmNDkzNzg1YWYwYWZhOTUzNTY2ZDljZjhmMjVhNGVjMzY0MDE5ODgzMWEwNjUxMjBjNDBhMDdlIiwiY2hhbm5lbF9pZCI6Ii04MDQ5MjI0NDIwOTY4MyIsImhvdGVsX2lkIjo2NDExMCwibWVzc2FnZV9pZCI6Im1pZC5mZmZmYjZjYWYwNjA2ZmVkMDFhMWViY2IzNGEyNTJjOCIsInBob3RvX3NoYTI1NiI6ImUyMDFhOTdlMGI5ZTU2OGIyZTI0ZWQ0ZDJmNTE5ODBkMjMzZTA1MmUyYWEzNjdjMTZiNGY0YmQ1YWRmNjBiMjciLCJwbGF0Zm9ybSI6Im1heCIsInBvc3RfdXJsIjoiaHR0cHM6Ly9tYXgucnUvY2hhbm5lbF9hbnl0b3VyL0FhSHJ5elNpVXNnIiwicHVibGljYXRpb25faWQiOjIyOTYsInRleHRfc2hhMjU2IjoiZGQ3ZGU1MWE3MjA0MWVkYTQwZTYyYTEyNTE0ZGI5NjQzOWE1OGI0MjhhNDg2MzU1ZDA0ZDVhMmU0MmY0MjU0ZSIsInZlcnNpb25faWQiOjc4MjV9LHsiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiNzNhYmM3MGZmNzZiMjZkZTgyZTk2MTJkM2RiZDg0NmJjMDFlZjFkYjk1NTVlZWQzMDY0NzMwMjFjYmY5MTY1YyIsImNoYW5uZWxfaWQiOiItMTAwMjI2MjY2NjYxNiIsImhvdGVsX2lkIjoyNjM1LCJtZXNzYWdlX2lkIjoiMTA2OSIsInBob3RvX3NoYTI1NiI6ImQ1NGU5M2YwYWJjZGQzYzIxZWE0ZjU4OTQ4YTFjYjdhNjUyYmRiZTA4YjA0NTQzZTI2M2RhNmYzOWU5NWQ1NTgiLCJwbGF0Zm9ybSI6InRlbGVncmFtIiwicG9zdF91cmwiOiJodHRwczovL3QubWUvYW55X3Rvb3VyLzEwNjkiLCJwdWJsaWNhdGlvbl9pZCI6MjI4OCwidGV4dF9zaGEyNTYiOiJmZWMxNDJmN2I1ODMzYTAwN2QwZGQwNmMwZDEzNDk2YmJlY2E3NWQzNjg0YjYwODI2YjM2MGU5MzY2ZjQ5MWZkIiwidmVyc2lvbl9pZCI6NzgxN30seyJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiJiYzNiZWQzNjQ2NTkwZWYyNzYzNzJmMjk2YmZjOGUzNWJhOTljMDIwZmJlYzgxMGIzN2VhZTM2ZTE3OTFiOGNkIiwiY2hhbm5lbF9pZCI6Ii04MDQ5MjI0NDIwOTY4MyIsImhvdGVsX2lkIjoyNjM1LCJtZXNzYWdlX2lkIjoibWlkLmZmZmZiNmNhZjA2MDZmZWQwMWExZWJjYjQ3N2I2NjEzIiwicGhvdG9fc2hhMjU2IjoiZDU0ZTkzZjBhYmNkZDNjMjFlYTRmNTg5NDhhMWNiN2E2NTJiZGJlMDhiMDQ1NDNlMjYzZGE2ZjM5ZTk1ZDU1OCIsInBsYXRmb3JtIjoibWF4IiwicG9zdF91cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FueXRvdXIvQWFIcnkwZDdaaE0iLCJwdWJsaWNhdGlvbl9pZCI6MjI5OCwidGV4dF9zaGEyNTYiOiJmZWMxNDJmN2I1ODMzYTAwN2QwZGQwNmMwZDEzNDk2YmJlY2E3NWQzNjg0YjYwODI2YjM2MGU5MzY2ZjQ5MWZkIiwidmVyc2lvbl9pZCI6NzgyN30seyJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiIzODg1ZmNiZDZkNjNmMjUwMjljYWMwMjFhYTY5NTI1MzkzNDAxM2MwMDJkZmUwOTI3OGFkNDU2MjMyMjA2OTRjIiwiY2hhbm5lbF9pZCI6Ii0xMDAyMjYyNjY2NjE2IiwiaG90ZWxfaWQiOjg5MjMsIm1lc3NhZ2VfaWQiOiIxMDcwIiwicGhvdG9fc2hhMjU2IjoiYWU3NWViYmMwMjE0N2ZmZjMwZjg0Zjg1ODg3NjFiNjRiZTNjYTZjNDdiMDM1MzczYjMzMWYzZmZiMDNhZDZiNSIsInBsYXRmb3JtIjoidGVsZWdyYW0iLCJwb3N0X3VybCI6Imh0dHBzOi8vdC5tZS9hbnlfdG9vdXIvMTA3MCIsInB1YmxpY2F0aW9uX2lkIjoyMjg5LCJ0ZXh0X3NoYTI1NiI6ImVjNjVjNTdmZjExZGU5NDk1NGU5OTU0NzIyODMzYjZiMmY3NDkxMjU0NWQ5MWEwN2Y3ZWViMWEzNzVmMDVjOWEiLCJ2ZXJzaW9uX2lkIjo3ODE4fSx7ImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6ImUwZThhYjcxZjhhOWIyODAzYmJjMDMxODY0YTY5OGNiYmNmNGY2YTJhMTI5MzIxZjNjNmUyYzhlZGY4YmQwODYiLCJjaGFubmVsX2lkIjoiLTgwNDkyMjQ0MjA5NjgzIiwiaG90ZWxfaWQiOjg5MjMsIm1lc3NhZ2VfaWQiOiJtaWQuZmZmZmI2Y2FmMDYwNmZlZDAxYTFlYmNiNTBkOTNhNWUiLCJwaG90b19zaGEyNTYiOiJhZTc1ZWJiYzAyMTQ3ZmZmMzBmODRmODU4ODc2MWI2NGJlM2NhNmM0N2IwMzUzNzNiMzMxZjNmZmIwM2FkNmI1IiwicGxhdGZvcm0iOiJtYXgiLCJwb3N0X3VybCI6Imh0dHBzOi8vbWF4LnJ1L2NoYW5uZWxfYW55dG91ci9BYUhyeTFEWk9sNCIsInB1YmxpY2F0aW9uX2lkIjoyMjk5LCJ0ZXh0X3NoYTI1NiI6ImVjNjVjNTdmZjExZGU5NDk1NGU5OTU0NzIyODMzYjZiMmY3NDkxMjU0NWQ5MWEwN2Y3ZWViMWEzNzVmMDVjOWEiLCJ2ZXJzaW9uX2lkIjo3ODI4fSx7ImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6ImYzM2FkMDBiNTA0OTA3ODVhNjhmZThiMzI5MmZjMjkwOTY2NDliOGQ2ZmRmMjMyYjM2YmU5YWUwMWU5ZjRlYTYiLCJjaGFubmVsX2lkIjoiLTEwMDIyNjI2NjY2MTYiLCJob3RlbF9pZCI6MTE3OTIsIm1lc3NhZ2VfaWQiOiIxMDcxIiwicGhvdG9fc2hhMjU2IjoiYjIxOGM4YmU5ZmNmZmRlZWE4YTkwODg1YzA3MTk5MTQ4OGJhNjlmYTY5ZjBlNmQyYTI1YWI1ZjEzYWZmNzU2NSIsInBsYXRmb3JtIjoidGVsZWdyYW0iLCJwb3N0X3VybCI6Imh0dHBzOi8vdC5tZS9hbnlfdG9vdXIvMTA3MSIsInB1YmxpY2F0aW9uX2lkIjoyMjkwLCJ0ZXh0X3NoYTI1NiI6IjAwZTAyYjlhZDQxNzA3MDQxMDYwYTc2YTUzODYwZTZkMDJhNTRiMzkzM2QyZTY0NTAyZDRmNzRiZWIzNThhMTAiLCJ2ZXJzaW9uX2lkIjo3ODE5fSx7ImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6IjAwMjJlMjhkNGMyYjRlNmNlMTg2NGViZTkwM2ZhMjFiMmY2NjY0N2IyNGJiZTgzMmIxYjg2ZDk0ZjBlNWZlODgiLCJjaGFubmVsX2lkIjoiLTgwNDkyMjQ0MjA5NjgzIiwiaG90ZWxfaWQiOjExNzkyLCJtZXNzYWdlX2lkIjoibWlkLmZmZmZiNmNhZjA2MDZmZWQwMWExZWJjYjVhODk0ZmRjIiwicGhvdG9fc2hhMjU2IjoiYjIxOGM4YmU5ZmNmZmRlZWE4YTkwODg1YzA3MTk5MTQ4OGJhNjlmYTY5ZjBlNmQyYTI1YWI1ZjEzYWZmNzU2NSIsInBsYXRmb3JtIjoibWF4IiwicG9zdF91cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FueXRvdXIvQWFIcnkxcUpUOXciLCJwdWJsaWNhdGlvbl9pZCI6MjMwMCwidGV4dF9zaGEyNTYiOiIwMGUwMmI5YWQ0MTcwNzA0MTA2MGE3NmE1Mzg2MGU2ZDAyYTU0YjM5MzNkMmU2NDUwMmQ0Zjc0YmViMzU4YTEwIiwidmVyc2lvbl9pZCI6NzgyOX0seyJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiI4MDA4Nzk2NGQxMmIxNGM5NjE3OTU5YmQyZmI2MjU1ZmJiOTNjNDgyMmJiN2Q3YWVkOTM0ZmRiODdkMDRjMGYwIiwiY2hhbm5lbF9pZCI6Ii0xMDAyMjYyNjY2NjE2IiwiaG90ZWxfaWQiOjU5NTQ4LCJtZXNzYWdlX2lkIjoiMTA3MiIsInBob3RvX3NoYTI1NiI6IjI4OWRmMDEyMzM4ZWYwNTYwOGYyODNhMjEyOWExYTZkMzYwYjUzNTJiY2VjNzBhYTZlYTE3NTJlMmI0NTllMzciLCJwbGF0Zm9ybSI6InRlbGVncmFtIiwicG9zdF91cmwiOiJodHRwczovL3QubWUvYW55X3Rvb3VyLzEwNzIiLCJwdWJsaWNhdGlvbl9pZCI6MjI5MSwidGV4dF9zaGEyNTYiOiI1Njc5YmYwYjBhMDgwZTI5Y2FiNTA0ODA2MzcwODRjZmNiZTFjNzlkNTE2ZjdkNmI3OWUyZDExZjcxNzI4M2IwIiwidmVyc2lvbl9pZCI6NzgyMH0seyJidXR0b25fdGV4dCI6IuKciO+4jyDQo9C30L3QsNGC0Ywg0YHRgtC+0LjQvNC+0YHRgtGMINGC0YPRgNCwIiwiYnV0dG9uX3VybF9zaGEyNTYiOiJmNjhlMzk4MjEyNGQyOGVmMGVhNTI4NDUzM2JjMDU5MjI5MGVjZWI0NTI1ZWY4MDAxZTdjYjJjY2VkNTE0ZDhmIiwiY2hhbm5lbF9pZCI6Ii04MDQ5MjI0NDIwOTY4MyIsImhvdGVsX2lkIjo1OTU0OCwibWVzc2FnZV9pZCI6Im1pZC5mZmZmYjZjYWYwNjA2ZmVkMDFhMWViY2I2NDEzMzRlMyIsInBob3RvX3NoYTI1NiI6IjI4OWRmMDEyMzM4ZWYwNTYwOGYyODNhMjEyOWExYTZkMzYwYjUzNTJiY2VjNzBhYTZlYTE3NTJlMmI0NTllMzciLCJwbGF0Zm9ybSI6Im1heCIsInBvc3RfdXJsIjoiaHR0cHM6Ly9tYXgucnUvY2hhbm5lbF9hbnl0b3VyL0FhSHJ5MlFUTk9NIiwicHVibGljYXRpb25faWQiOjIzMDEsInRleHRfc2hhMjU2IjoiNTY3OWJmMGIwYTA4MGUyOWNhYjUwNDgwNjM3MDg0Y2ZjYmUxYzc5ZDUxNmY3ZDZiNzllMmQxMWY3MTcyODNiMCIsInZlcnNpb25faWQiOjc4MzB9LHsiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiMWQ5MmRkZThkMTNhNjQxMWJmNDQwOWE5Y2U5OTdhOGRjMDMwM2IyNmVlMWU5MzI2YjQ4NGFmNWM4N2FiOTg3OCIsImNoYW5uZWxfaWQiOiItMTAwMjI2MjY2NjYxNiIsImhvdGVsX2lkIjo3Mzk5NSwibWVzc2FnZV9pZCI6IjEwNzMiLCJwaG90b19zaGEyNTYiOiJhN2FiMjdmMTJlZDU4YjE0YmY5NjU2ZWQxOTI5ZDgwMjRjMTI5NTFjNDgwMjI0MTY1MGRmN2Q1YzhkOTRkMWJiIiwicGxhdGZvcm0iOiJ0ZWxlZ3JhbSIsInBvc3RfdXJsIjoiaHR0cHM6Ly90Lm1lL2FueV90b291ci8xMDczIiwicHVibGljYXRpb25faWQiOjIyOTIsInRleHRfc2hhMjU2IjoiODI3YzAzYTU3MGVkNzdmN2MyOGQwNmMyYjc2ZDQ2ODBlMTAwMWZhMmZkMDVkZWE4NmQxZDA5ZDI1ZDA0NmRjZiIsInZlcnNpb25faWQiOjc4MjF9LHsiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiM2EzOTg4NmU1YzhiNGMyYzE5ZDk1YTUwMDQwZDM2OGZiODQzNDUwMGVhMDhhNDQ2OTJiNDI0MjQ5MmRlYzAwMSIsImNoYW5uZWxfaWQiOiItODA0OTIyNDQyMDk2ODMiLCJob3RlbF9pZCI6NzM5OTUsIm1lc3NhZ2VfaWQiOiJtaWQuZmZmZmI2Y2FmMDYwNmZlZDAxYTFlYmNiNmRhMTcxN2YiLCJwaG90b19zaGEyNTYiOiJhN2FiMjdmMTJlZDU4YjE0YmY5NjU2ZWQxOTI5ZDgwMjRjMTI5NTFjNDgwMjI0MTY1MGRmN2Q1YzhkOTRkMWJiIiwicGxhdGZvcm0iOiJtYXgiLCJwb3N0X3VybCI6Imh0dHBzOi8vbWF4LnJ1L2NoYW5uZWxfYW55dG91ci9BYUhyeTIyaGNYOCIsInB1YmxpY2F0aW9uX2lkIjoyMzAyLCJ0ZXh0X3NoYTI1NiI6IjgyN2MwM2E1NzBlZDc3ZjdjMjhkMDZjMmI3NmQ0NjgwZTEwMDFmYTJmZDA1ZGVhODZkMWQwOWQyNWQwNDZkY2YiLCJ2ZXJzaW9uX2lkIjo3ODMxfSx7ImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6ImM5N2ZkYWJhNGI5YTVjZDIyMWI0MjY0YjlhMmNhNzc3NGViYzBlYzgwOWRhM2E2MjliYzg2NTBiZThlMjY3MmEiLCJjaGFubmVsX2lkIjoiLTEwMDIyNjI2NjY2MTYiLCJob3RlbF9pZCI6NzgyMTAsIm1lc3NhZ2VfaWQiOiIxMDc0IiwicGhvdG9fc2hhMjU2IjoiY2YxNzBkMzljMzM5ZjI0ZDE1ZTg1YmI5YjBiMGIzNzI0MzViNzNlYThhMDUwOTc1NjVhNjZmMDYzODY4YzJjNSIsInBsYXRmb3JtIjoidGVsZWdyYW0iLCJwb3N0X3VybCI6Imh0dHBzOi8vdC5tZS9hbnlfdG9vdXIvMTA3NCIsInB1YmxpY2F0aW9uX2lkIjoyMjkzLCJ0ZXh0X3NoYTI1NiI6ImRkOTcyYTViODQ2NjUwZTFjZjkwOTAzNmIwNTE1ZjFkZjU0YzAxY2Q0NjkxZmEwZDg0ZmQ1N2YwYTk1MzRmMmQiLCJ2ZXJzaW9uX2lkIjo3ODIyfSx7ImJ1dHRvbl90ZXh0Ijoi4pyI77iPINCj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YLRg9GA0LAiLCJidXR0b25fdXJsX3NoYTI1NiI6IjNhNmIzYWQwNDJhZjdkZDcyMTI1M2UxYmM2MzVjYTM2M2UzN2E1OWJjYjU0OWZlYWIxYzE3YThjZjY3ZTIwOTAiLCJjaGFubmVsX2lkIjoiLTgwNDkyMjQ0MjA5NjgzIiwiaG90ZWxfaWQiOjc4MjEwLCJtZXNzYWdlX2lkIjoibWlkLmZmZmZiNmNhZjA2MDZmZWQwMWExZWJjYjc2ZGE0MWIyIiwicGhvdG9fc2hhMjU2IjoiY2YxNzBkMzljMzM5ZjI0ZDE1ZTg1YmI5YjBiMGIzNzI0MzViNzNlYThhMDUwOTc1NjVhNjZmMDYzODY4YzJjNSIsInBsYXRmb3JtIjoibWF4IiwicG9zdF91cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FueXRvdXIvQWFIcnkzYmFRYkkiLCJwdWJsaWNhdGlvbl9pZCI6MjMwMywidGV4dF9zaGEyNTYiOiJkZDk3MmE1Yjg0NjY1MGUxY2Y5MDkwMzZiMDUxNWYxZGY1NGMwMWNkNDY5MWZhMGQ4NGZkNTdmMGE5NTM0ZjJkIiwidmVyc2lvbl9pZCI6NzgzMn1d',true),true,64,JSON_THROW_ON_ERROR);
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
    $out['scope_hotels']=8;$out['feed_rows']=count($built['rows']);$out['deferred_count']=count($built['deferred']);$out['installed_sha']=EXPECTED_INSTALLED_SHA;$out['ok']=true;

} catch(Throwable $e){
    if(isset($catalog)&&$catalog->inTransaction())$catalog->rollBack();
    if(isset($registry)&&$registry->inTransaction())$registry->rollBack();
    $out['error']=preg_match('/\A[a-z][a-z0-9_]{1,100}\z/D',$e->getMessage())?$e->getMessage():'bounded_catalog_read_failed';
}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
exit($out['ok']?0:2);

