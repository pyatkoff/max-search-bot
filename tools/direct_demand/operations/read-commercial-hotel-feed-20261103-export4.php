<?php
declare(strict_types=1);
ini_set('display_errors','0');ini_set('log_errors','0');
$out=['operation'=>'commercial-hotel-feed-20261103-export4','mode'=>'read_bounded_public_hotel_feed_inputs','ok'=>false,'db_writes'=>0,'provider_calls'=>0,'message_sends'=>0];
function insist(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}

function feed_title(string $s):string{return preg_replace('/[^\pL\pN]+/u','',mb_strtolower(strtr(trim($s),['ё'=>'е','ö'=>'o','Ö'=>'o']),'UTF-8'))??'';}
function feed_image(string $url,string $sha):bool{
 $p=parse_url($url);
 return is_array($p)&&($p['scheme']??'')==='https'&&($p['host']??'')==='raw.githubusercontent.com'&&!isset($p['query'])&&!isset($p['fragment'])&&!isset($p['user'])&&!isset($p['pass'])&&!isset($p['port'])
  &&preg_match('~\A/pyatkoff/max-search-bot/[a-f0-9]{40}/\.github/traffic-publish/[A-Za-z0-9_./-]+\.png\z~D',(string)($p['path']??''))===1&&preg_match('/\A[a-f0-9]{64}\z/D',$sha)===1;
}
function feed_build(array $inputs,array $hotels,array $counts):array{
 $out=['rows'=>[],'deferred'=>[]];$seen=[];
 foreach($inputs as $r){
  insist(array_diff(array_keys($r),['hotel_id','name','legacy_anytour_id','accepted_first_line','image_url','image_sha256'])===[],'feed_input_private_field');
  $id=(int)($r['hotel_id']??0);insist($id>0&&!isset($seen[$id]),'feed_input_duplicate_or_invalid');$seen[$id]=true;
  $h=$hotels[$id]??null;$line=feed_title((string)($r['accepted_first_line']??''));$pair=$counts[$line]??[];
  $why=null;
  if(!is_array($h)||empty($h['is_active'])||(int)($h['region_id']??0)<=0||(int)($h['country_id']??0)<=0)$why='ACTIVE_CATALOG_MEMBERSHIP_MISSING';
  elseif(($pair[9]??0)!==1||($pair[10]??0)!==1)$why='CURRENT_CANONICAL_PAIR_NOT_UNIQUE';
  elseif(empty($r['image_url']))$why='DURABLE_PUBLIC_IMAGE_INPUT_MISSING';
  elseif(!feed_image((string)$r['image_url'],(string)($r['image_sha256']??'')))$why='APPROVED_PUBLIC_IMAGE_GUARD_FAILED';
  elseif(trim((string)($h['region']??''))===''||mb_strlen((string)$h['region'],'UTF-8')>25)$why='PUBLIC_DESTINATION_LABEL_REQUIRED';
  if($why!==null){$out['deferred'][]=['hotel_id'=>$id,'state'=>$why];continue;}
  $out['rows'][]=['hotel_id'=>$id,'name'=>(string)$r['name'],'country_id'=>(int)$h['country_id'],'region_id'=>(int)$h['region_id'],'destination'=>(string)$h['region'],'image_url'=>(string)$r['image_url'],'image_sha256'=>(string)$r['image_sha256'],'paired_material_count'=>1];
 }
 return $out;
}
if(getenv('ANYTOUR_FEED_FIXTURE')==='1'){
 $r=['hotel_id'=>1146,'name'=>'AKRA ANTALYA','legacy_anytour_id'=>516,'accepted_first_line'=>'✈️ Туры в Akra Antalya с перелётом','image_url'=>'https://raw.githubusercontent.com/pyatkoff/max-search-bot/'.str_repeat('a',40).'/.github/traffic-publish/accepted.png','image_sha256'=>str_repeat('b',64)];
 $h=[1146=>['id'=>1146,'is_active'=>1,'country_id'=>4,'region_id'=>11,'region'=>'Анталья']];$c=[feed_title($r['accepted_first_line'])=>[9=>1,10=>1]];
 $d=feed_build([$r],$h,$c);insist(count($d['rows'])===1&&$d['rows'][0]['hotel_id']===1146&&$d['rows'][0]['country_id']===4,'fixture_native_namespace');
 $dup=$c;$dup[feed_title($r['accepted_first_line'])][9]=2;insist(feed_build([$r],$h,$dup)['deferred'][0]['state']==='CURRENT_CANONICAL_PAIR_NOT_UNIQUE','fixture_duplicate_pair');
 $missing=$c;$missing[feed_title($r['accepted_first_line'])][10]=0;insist(count(feed_build([$r],$h,$missing)['rows'])===0,'fixture_missing_pair');
 $inactive=$h;$inactive[1146]['is_active']=0;insist(count(feed_build([$r],$inactive,$c)['rows'])===0,'fixture_inactive');
 $secret=$r;$secret['image_url'].='?token=private';insist(feed_build([$secret],$h,$c)['deferred'][0]['state']==='APPROVED_PUBLIC_IMAGE_GUARD_FAILED','fixture_signed_image');
 foreach([$r['image_url'].'#fragment',str_replace('https://','https://user@',$r['image_url']),str_replace('raw.githubusercontent.com/','raw.githubusercontent.com:443/',$r['image_url'])] as $unsafe){$test=$r;$test['image_url']=$unsafe;insist(count(feed_build([$test],$h,$c)['rows'])===0,'fixture_url_components');}
 $absent=$r;$absent['image_url']=null;insist(feed_build([$absent],$h,$c)['deferred'][0]['state']==='DURABLE_PUBLIC_IMAGE_INPUT_MISSING','fixture_image_absent');
 $bad=$r;$bad['publication_id']=123;$rejected=false;try{feed_build([$bad],$h,$c);}catch(RuntimeException $e){$rejected=$e->getMessage()==='feed_input_private_field';}insist($rejected,'fixture_private_field');
 echo "HOTEL_FEED_EXPORT_PREPARATION_OK cases=10 native_namespace=exact public_fields=whitelisted duplicate_missing_inactive=deferred signed_images=rejected server_calls=0\n";exit;
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
        "services/ChannelPostButtonTransport.php"=>"b6c123069810ad490391e825315abd8567c55310",
        "services/ChannelPostEntryService.php"=>"f0e04e9b4e0ed6859ced96279cbf0619931596e6",
        "services/ChannelPostMaxAlbumRecovery.php"=>"de266cd068587cc3b2ff83f308a966ea66887a04",
        "services/ChannelPostMediaService.php"=>"f794efd0f6f24df916e18909ff6281b0bc928433",
        "services/ChannelPostMemberDeliveryService.php"=>"20c1d7984898b2a5996a5f9e20e4d2d8c20b4778",
        "services/ChannelPostReconciliationService.php"=>"2b559b067a91abb9402a9dc2f3ce75d1ddedc7c3",
        "services/ChannelPostReconciliationState.php"=>"d9583ceca80d1424a44ade3561c21282a23a52f0",
        "services/ChannelPostRegistryService.php"=>"a407757b9ac54a9e286900d34385d519d22b8c84",
        "services/ChannelPostRuntime.php"=>"2f1dd9b77474fdbaa92f0402f3b0aa170c595474",
        "services/ChannelPostTelegramAlbum.php"=>"faa1a14bc279a4af33936282126bf0d46e343040",
        "services/ChannelPostWebhookConfigService.php"=>"d5fdcb056088f1e3a541b4046325d97eef6417d9",
        "services/ConversationDb.php"=>"bb6281faf55aca7beeae7f8a57389d19751936f7",
        "services/MaxTlsConfig.php"=>"032c0211ee3f39c6f0d27c669abd5b467e321dea",
        "services/MaxTransport.php"=>"bfa583167347ff42ed632c52a552e5585bb97de9",
        "services/ProjectConfig.php"=>"538b596981f2c61fbf524e18756bf672578948f6",
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
    $inputs=json_decode($raw,true,16,JSON_THROW_ON_ERROR);insist(is_array($inputs)&&count($inputs)===1,'feed_scope_changed');
    $ids=array_column($inputs,'hotel_id');insist(count(array_unique($ids))===1,'feed_scope_duplicate');
    $catalog->exec('SET TRANSACTION READ ONLY');$catalog->beginTransaction();
    $registry=ConversationDb::connection();$registry->exec('SET TRANSACTION READ ONLY');$registry->beginTransaction();
    $sql='SELECT h.id,h.name,h.is_active,h.country_id,h.region_id,r.name AS region FROM catalog_hotels h LEFT JOIN catalog_regions r ON r.id=h.region_id AND r.country_id=h.country_id WHERE h.id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY h.id';
    $q=$catalog->prepare($sql);$q->execute($ids);$hotels=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $h)$hotels[(int)$h['id']]=$h;
    $publications=$registry->query("SELECT pp.post_channel_id,v.snapshot_json FROM post_publications pp JOIN post_versions v ON v.id=pp.current_version_id AND v.publication_id=pp.id WHERE pp.post_channel_id IN (9,10) AND v.state='sent' ORDER BY pp.id LIMIT 2000")->fetchAll(PDO::FETCH_ASSOC);
    insist(count($publications)<2000,'canonical_read_limit_reached');$counts=[];
    foreach($publications as $p){$s=json_decode((string)$p['snapshot_json'],true);$title=feed_title(explode("\n",(string)($s['text']??''),2)[0]);$cid=(int)$p['post_channel_id'];$counts[$title][$cid]=($counts[$title][$cid]??0)+1;}
    $built=feed_build($inputs,$hotels,$counts);$out+=$built;
    $registry->rollBack();$catalog->rollBack();
    $out['scope_hotels']=1;$out['feed_rows']=count($built['rows']);$out['deferred_count']=count($built['deferred']);$out['installed_sha']=EXPECTED_INSTALLED_SHA;$out['ok']=true;

} catch(Throwable $e){
    if(isset($catalog)&&$catalog->inTransaction())$catalog->rollBack();
    if(isset($registry)&&$registry->inTransaction())$registry->rollBack();
    $out['error']=preg_match('/\A[a-z][a-z0-9_]{1,100}\z/D',$e->getMessage())?$e->getMessage():'bounded_catalog_read_failed';
}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
exit($out['ok']?0:2);
