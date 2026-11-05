<?php
declare(strict_types=1);
ini_set('display_errors','0');ini_set('log_errors','0');
$out=['operation'=>'commercial-stock-20261105-41-read1','mode'=>'read_bounded_public_catalog_stock','ok'=>false,'db_writes'=>0,'provider_calls'=>0,'message_sends'=>0];
function insist(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}

function stock_public_projection():string{
 return "SELECT pc.platform,JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.metadata.hotel_catalog_id')) AS hotel_catalog_id,LOWER(SUBSTRING_INDEX(JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.text')),CHAR(10),1)) AS first_line FROM post_publications pp JOIN post_channels pc ON pc.id=pp.post_channel_id JOIN post_versions v ON v.id=pp.current_version_id AND v.publication_id=pp.id WHERE pp.post_channel_id IN (9,10) ORDER BY pp.id LIMIT 2000";
}
function stock_public_match_counts(array $publications,int $id,array $aliases):array{
 $counts=['telegram'=>0,'max'=>0];
 foreach($publications as $p){$platform=(string)($p['platform']??'');if(!array_key_exists($platform,$counts))continue;$first=mb_strtolower((string)($p['first_line']??''),'UTF-8');$same=(int)($p['hotel_catalog_id']??0)===$id;foreach($aliases as $alias)if(mb_strpos($first,mb_strtolower($alias,'UTF-8'),0,'UTF-8')!==false)$same=true;if($same)$counts[$platform]++;}
 return $counts;
}
function stock41_groups_ok(array $groups):bool{
 return array_keys($groups)===['papillon-ayscha','papillon-belvil','papillon-zeugma','kaya-belek','kaya-palazzo-golf','titanic-deluxe-golf-belek','cullinan-belek','maxx-royal-kemer','maxx-royal-bodrum','ic-santai-family']&&count($groups)===10;
}
function stock41_targeted_projection(array $ids,array $aliases):array{
 insist(count($ids)<=120&&count($aliases)<=20,'targeted_projection_scope_exceeded');
 foreach($ids as $id)insist(is_int($id)&&$id>0,'invalid_new_native_id');
 $where=[];$args=[];
 if($ids){$where[]="CAST(JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.metadata.hotel_catalog_id')) AS UNSIGNED) IN (".implode(',',array_fill(0,count($ids),'?')).")";$args=$ids;}
 foreach($aliases as $alias){insist(is_string($alias)&&strlen($alias)>8,'narrow_named_alias_required');$where[]="LOWER(SUBSTRING_INDEX(JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.text')),CHAR(10),1)) LIKE ?";$args[]='%'.$alias.'%';}
 insist($where!==[],'empty_new_projection_scope');
 $sql="SELECT pc.platform,JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.metadata.hotel_catalog_id')) AS hotel_catalog_id,LOWER(SUBSTRING_INDEX(JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.text')),CHAR(10),1)) AS first_line FROM post_publications pp JOIN post_channels pc ON pc.id=pp.post_channel_id JOIN post_versions v ON v.id=pp.current_version_id AND v.publication_id=pp.id WHERE pp.post_channel_id IN (9,10) AND (".implode(' OR ',$where).") ORDER BY pp.id LIMIT 241";
 return [$sql,$args];
}
if(getenv('ANYTOUR_STOCK41_SCOPE_FIXTURE')==='1'){
 $g=json_decode(base64_decode(STOCK41_GROUPS_B64,true),true,16,JSON_THROW_ON_ERROR);
 insist(stock41_groups_ok($g)&&$g['papillon-ayscha']===['papillon ayscha']&&$g['maxx-royal-bodrum']===['maxx royal bodrum']&&$g['ic-santai-family']===['ic hotels santai'],'new_fixed_ten_distinct_lookup_inputs');
 $bad=$g;unset($bad['kaya-belek']);insist(!stock41_groups_ok($bad),'missing_new_named_group_stops');
 [$sql,$args]=stock41_targeted_projection([321],['papillon ayscha']);insist(str_contains($sql,' AND (')&&str_contains($sql,'LIMIT 241')&&$args===[321,'%papillon ayscha%']&&!str_contains($sql,'SELECT pp.id')&&!str_contains($sql,'SELECT v.snapshot_json'),'new_named_targeted_projection_excludes_previous159_scope');
 echo "STOCK41_NEW_INPUT_SCOPE_OK new_cases=3 old_stock40_projection_fixtures_rerun=0 server_calls=0 provider_calls=0 writes=0\n";exit;
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
    $catalog->exec('SET TRANSACTION READ ONLY');$catalog->beginTransaction();
    $registry=ConversationDb::connection();$registry->exec('SET TRANSACTION READ ONLY');$registry->beginTransaction();
    $country=4;$countries=[['id'=>4,'name'=>'Турция']];
    $groupRaw=base64_decode(STOCK41_GROUPS_B64,true);insist(is_string($groupRaw)&&hash_equals(STOCK41_GROUPS_SHA256,hash('sha256',$groupRaw)),'new_group_input_hash_changed');
    $groups=json_decode($groupRaw,true,16,JSON_THROW_ON_ERROR);insist(stock41_groups_ok($groups),'new_ten_named_groups_changed');
    $out['candidates']=[];$candidateIds=[];$allAliases=[];
    foreach($groups as $key=>$aliases){
        $where=[];$args=[$country];
        foreach($aliases as $alias){$where[]='LOWER(h.name) LIKE ?';$args[]='%'.$alias.'%';$allAliases[]=$alias;}
        $sql='SELECT h.id,h.name,h.country_id,h.region_id,h.rating,h.is_active,r.name AS region FROM catalog_hotels h LEFT JOIN catalog_regions r ON r.id=h.region_id AND r.country_id=h.country_id WHERE h.is_active=1 AND h.country_id=? AND ('.implode(' OR ',$where).') ORDER BY h.id LIMIT 12';
        $q=$catalog->prepare($sql);$q->execute($args);$matches=$q->fetchAll(PDO::FETCH_ASSOC);
        foreach($matches as &$hotel){$hotel['id']=(int)$hotel['id'];$candidateIds[]=$hotel['id'];}unset($hotel);
        $out['candidates'][]=['key'=>$key,'state'=>count($matches)===1?'unique_catalog_match':'requires_identity_resolution','matches'=>$matches];
    }
    $candidateIds=array_values(array_unique($candidateIds));$allAliases=array_values(array_unique($allAliases));
    [$projectionSql,$projectionArgs]=stock41_targeted_projection($candidateIds,$allAliases);
    $publicationQuery=$registry->prepare($projectionSql);$publicationQuery->execute($projectionArgs);$publications=$publicationQuery->fetchAll(PDO::FETCH_ASSOC);
    insist(count($publications)<241,'targeted_registry_read_limit_reached');
    foreach($out['candidates'] as &$candidate)foreach($candidate['matches'] as &$hotel)$hotel['existing_registry_matches']=stock_public_match_counts($publications,$hotel['id'],$groups[$candidate['key']]);unset($candidate,$hotel);
    $out['lookup_groups']=10;$out['targeted_registry_rows']=count($publications);$out['whole_previous159_registry_read']=false;
    $registry->rollBack();$catalog->rollBack();
    $out['country']=$countries[0];$out['installed_sha']=EXPECTED_INSTALLED_SHA;$out['ok']=true;
} catch(Throwable $e){
    if(isset($catalog)&&$catalog->inTransaction())$catalog->rollBack();
    if(isset($registry)&&$registry->inTransaction())$registry->rollBack();
    $out['error']=preg_match('/\A[a-z][a-z0-9_]{1,100}\z/D',$e->getMessage())?$e->getMessage():'bounded_catalog_read_failed';
}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
exit($out['ok']?0:2);
