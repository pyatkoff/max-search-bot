<?php
declare(strict_types=1);
ini_set('display_errors','0');ini_set('log_errors','0');
$out=['operation'=>'commercial-hotel-inventory-20261102-9-read1','mode'=>'read_public_catalog_candidates','ok'=>false,'db_writes'=>0,'provider_calls'=>0,'message_sends'=>0];
function insist(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
try {
    if(PHP_SAPI!=='cli'||trim((string)shell_exec('git rev-parse HEAD'))!==EXPECTED_INSTALLED_SHA)throw new RuntimeException('installed_source_changed');
    // Inspect only the unchanged, previously accepted publishing surface.
    $accepted=[
        "integrations/ChannelPostApiTransport.php"=>"300634d77cb9fddf51adf9869974f956cca6e3f9",
        "migrations/033_channel_post_context.sql"=>"0530458030a4bfe6b6cc55d1e5e441745c29b947",
        "migrations/036_channel_post_member_delivery.sql"=>"1bda463e52b33130ad12752ddef4ee5c92699c05",
        "migrations/040_channel_post_reconciliation.sql"=>"0f24b838eacd45333c5ade9a2991d060488c6035",
        "services/ChannelPostAdminService.php"=>"b80eefc801054bfe6fdb58ec345ecdf687b2e89a",
        "services/ChannelPostAutomationService.php"=>"a6854a8df4ac57ca2b5a50856786c03205b75357",
        "services/ChannelPostButtonTransport.php"=>"b6c123069810ad490391e825315abd8567c55310",
        "services/ChannelPostEntryService.php"=>"f0e04e9b4e0ed6859ced96279cbf0619931596e6",
        "services/ChannelPostMaxAlbumRecovery.php"=>"de266cd068587cc3b2ff83f308a966ea66887a04",
        "services/ChannelPostMediaService.php"=>"f794efd0f6f24df916e18909ff6281b0bc928433",
        "services/ChannelPostMemberDeliveryService.php"=>"20c1d7984898b2a5996a5f9e20e4d2d8c20b4778",
        "services/ChannelPostReconciliationService.php"=>"e04e9743eb4267aaae4784ad2f4b11388b66f20d",
        "services/ChannelPostReconciliationState.php"=>"d9583ceca80d1424a44ade3561c21282a23a52f0",
        "services/ChannelPostRegistryService.php"=>"79d0f277546eb22528141e835f9276295d6c6cfe",
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
    $q=$catalog->prepare("SELECT id,name FROM catalog_countries WHERE is_active=1 AND (LOWER(name)=? OR LOWER(name)=?) ORDER BY id LIMIT 3");
    $q->execute(['египет','egypt']);$countries=$q->fetchAll(PDO::FETCH_ASSOC);
    insist(count($countries)===1,'egypt_catalog_identity_not_unique');
    $country=(int)$countries[0]['id'];
    $groups=[
        "tucana"=>["tucana","тукана"],
        "alma-bay"=>["alma bay","альма бей"],
        "alora"=>["alora","алора"],
        "anjum"=>["anjum","анджум"],
        "meraki"=>["meraki","мераки"],
        "jaz-aquamarine"=>["jaz aquamarine","джаз аквамарин"],
        "jaz-bluemarine"=>["jaz bluemarine","джаз блюмарин"],
        "jaz-casa-beach"=>["casa del mar beach"],
        "jaz-casa-resort"=>["casa del mar resort"],
        "jaz-makadina"=>["makadina","макадина"],
        "jaz-makadi-oasis"=>["makadi oasis","макади оазис"],
        "jaz-makadi-star"=>["makadi star","макади стар"],
        "jaz-dar-el-madina"=>["dar el madina"],
        "jaz-lamaya"=>["lamaya","ламая"],
        "jaz-solaya"=>["solaya","солая"],
        "steigenberger-aldau"=>["steigenberger al dau","steigenberger aldau"],
        "steigenberger-aqua-magic"=>["steigenberger aqua magic"],
        "steigenberger-pure"=>["steigenberger pure"],
        "baron-palace"=>["baron palace"],
        "baron-resort"=>["baron resort"],
        "rixos-sharm"=>["rixos sharm"],
        "rixos-seagate"=>["rixos premium seagate","rixos seagate"],
        "albatros-palace"=>["albatros palace"],
        "albatros-dana"=>["dana beach"],
        "albatros-white"=>["albatros white beach"],
    ];
    $publications=ConversationDb::connection()->query("SELECT pp.post_channel_id,v.snapshot_json FROM post_publications pp JOIN post_versions v ON v.id=pp.current_version_id WHERE pp.post_channel_id IN (9,10) ORDER BY pp.id LIMIT 2000")->fetchAll(PDO::FETCH_ASSOC);
    $out['candidates']=[];
    foreach($groups as $key=>$aliases){
        $where=[];$args=[$country];
        foreach($aliases as $alias){$where[]='LOWER(h.name) LIKE ?';$args[]='%'.$alias.'%';}
        $sql='SELECT h.id,h.name,h.country_id,h.region_id,h.rating,r.name AS region FROM catalog_hotels h LEFT JOIN catalog_regions r ON r.id=h.region_id AND r.country_id=h.country_id WHERE h.is_active=1 AND h.country_id=? AND ('.implode(' OR ',$where).') ORDER BY h.id LIMIT 12';
        $q=$catalog->prepare($sql);$q->execute($args);$matches=$q->fetchAll(PDO::FETCH_ASSOC);
        foreach($matches as &$hotel){
            $hotel['id']=(int)$hotel['id'];$hotel['existing_registry_matches']=['telegram'=>0,'max'=>0];
            foreach($publications as $publication){
                $s=json_decode($publication['snapshot_json'],true);$first=mb_strtolower(explode("\n",(string)($s['text']??''),2)[0],'UTF-8');
                $same=(int)($s['metadata']['hotel_catalog_id']??0)===$hotel['id'];
                foreach($aliases as $alias)if(mb_strpos($first,$alias,0,'UTF-8')!==false)$same=true;
                if($same)$hotel['existing_registry_matches'][(int)$publication['post_channel_id']===10?'telegram':'max']++;
            }
        }unset($hotel);
        $out['candidates'][]=['key'=>$key,'state'=>count($matches)===1?'unique_catalog_match':'requires_identity_resolution','matches'=>$matches];
    }
    $out['country']=$countries[0];$out['installed_sha']=EXPECTED_INSTALLED_SHA;$out['ok']=true;
} catch(Throwable $e){
    $out['error']=preg_match('/\A[a-z][a-z0-9_]{1,100}\z/D',$e->getMessage())?$e->getMessage():'bounded_catalog_read_failed';
}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
exit($out['ok']?0:2);
