<?php
declare(strict_types=1);
// Fixed, read-only preflight for the two directly authorized publication targets.
ini_set('display_errors','0');ini_set('log_errors','0');
$out=['operation'=>'tg-owner-context-read-20261102-v1','mode'=>'read_test_context_only','ok'=>false,'post_write_attempts'=>0,'channels'=>[]];
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
        "services/ChannelPostButtonTransport.php"=>"16d4e16bb13058e6563f1e76c0eafdf8b5246110",
        "services/ChannelPostEntryService.php"=>"f0e04e9b4e0ed6859ced96279cbf0619931596e6",
        "services/ChannelPostMaxAlbumRecovery.php"=>"daca76263ab438e93c8a4617edbe66a81f046191",
        "services/ChannelPostMediaService.php"=>"f794efd0f6f24df916e18909ff6281b0bc928433",
        "services/ChannelPostMemberDeliveryService.php"=>"20c1d7984898b2a5996a5f9e20e4d2d8c20b4778",
        "services/ChannelPostReconciliationService.php"=>"35f9261c6d51d0338f4b38c67ce2bc8f85bead70",
        "services/ChannelPostReconciliationState.php"=>"d9583ceca80d1424a44ade3561c21282a23a52f0",
        "services/ChannelPostRegistryService.php"=>"ac9b669173d803d3385a03a5efc7822f181e3839",
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
        require_once getcwd().'/integrations/ChannelPostApiTransport.php';
        require_once getcwd().'/services/ChannelPostButtonTransport.php';
    } finally {ob_end_clean();}

    $pdo=ConversationDb::connection();$pdo->exec('START TRANSACTION READ ONLY');
    $registry=new ChannelPostRegistryService($pdo);
    $q=$pdo->prepare("SELECT c.id,c.channel,c.project_key,c.manager_id,c.status,s.source_key,s.primary_group_id FROM conversations c LEFT JOIN conversation_sources s ON s.id=c.source_id WHERE c.id=1 AND c.project_key='anytour' AND c.channel='telegram' LIMIT 1");$q->execute();$conversation=$q->fetch(PDO::FETCH_ASSOC);
    if(!$conversation)throw new RuntimeException('authorized_test_conversation_unavailable');
    $out['test_conversation']=$conversation;
    $context=$registry->managerContext(1,'anytour',3);$entries=[];
    foreach(array_merge($context['current']?[$context['current']]:[],$context['history']) as $entry){$entries[]=['entry_id'=>$entry['entry_id'],'entered_at'=>$entry['entered_at'],'publication_id'=>$entry['publication_id'],'version_id'=>$entry['version_id'],'source_key'=>$entry['source_key'],'post_url'=>$entry['post_url'],'unavailable'=>$entry['unavailable']];}
    $out['existing_manager_context_entries']=$entries;
    $publication=$registry->publication(101);
    if(!$publication||$publication['platform']!=='telegram'||$publication['bot_key']!=='anyru_bot'||$publication['channel_id']!=='-1002262666616')throw new RuntimeException('exact_publication_unavailable');
    $out['current_rixos_publication']=['publication_id'=>101,'version_id'=>$publication['version_id'],'state'=>$publication['state'],'source_key'=>$publication['source_key'],'post_url'=>$publication['snapshot']['post_url']];
    $q=$pdo->prepare('SELECT COUNT(*) FROM post_entries WHERE conversation_id=? AND publication_id=? AND version_id=?');$q->execute([1,101,(int)$publication['version_id']]);$out['existing_entries_for_current_rixos_version']=(int)$q->fetchColumn();
    $q=$pdo->prepare("SELECT id,direction,sender_type,created_at FROM messages WHERE conversation_id=1 AND channel='telegram' AND text=? ORDER BY id DESC LIMIT 2");$q->execute(['Тест AnyTour. Новая поездка в Rixos Magawish: 2 взрослых, 7 ночей, вылет из Москвы, даты пока не выбраны. Нужен расчёт у менеджера.']);$out['planned_test_message_matches']=$q->fetchAll(PDO::FETCH_ASSOC);
    $out['scope']='authorized_test_conversation1_and_Rixos101_only';$out['provider_api_calls']=0;$out['message_sends']=0;$out['db_writes']=0;$out['installed_pass']='not_proven_by_readonly_metadata';$out['ok']=true;$out['installed_sha']=EXPECTED_INSTALLED_SHA;
} catch(Throwable $e){$out['error']=in_array($e->getMessage(),['installed_source_changed','publishing_surface_changed','authorized_test_conversation_unavailable','exact_publication_unavailable'],true)?$e->getMessage():'bounded_owner_context_read_failed';}
if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;exit($out['ok']?0:2);
