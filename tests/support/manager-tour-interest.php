<?php
declare(strict_types=1);
require_once __DIR__.'/manager-tour-selection.php';
require_once dirname(__DIR__,2).'/services/ManagerTourSelectionInterestService.php';

function interestSchema(PDO $pdo): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') {
        foreach (['055_manager_tour_selection_links.sql','058_manager_tour_selection_interest.sql'] as $file) $pdo->exec(file_get_contents(dirname(__DIR__,2).'/migrations/'.$file));
        return;
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS manager_tour_selection_links(conversation_id INTEGER PRIMARY KEY,version INTEGER,token TEXT UNIQUE,publication_id TEXT,is_active INTEGER,updated_by_manager_id INTEGER,updated_at TEXT);
CREATE TABLE IF NOT EXISTS manager_tour_selection_link_history(conversation_id INTEGER,version INTEGER,publication_id TEXT,is_active INTEGER,manager_id INTEGER,created_at TEXT,PRIMARY KEY(conversation_id,version));
CREATE TABLE IF NOT EXISTS manager_tour_selection_interest(event_id TEXT PRIMARY KEY,conversation_id INTEGER,publication_id TEXT,link_version INTEGER,visitor_hash TEXT,event_kind TEXT,bucket TEXT,card_index INTEGER,hotel_group INTEGER,platform TEXT,created_at TEXT);');
}
function interestCache(PDO $pdo,string $id): void
{
    $q=$pdo->prepare('SELECT selection_json FROM manager_tour_selection_publications WHERE publication_id=?');$q->execute([$id]);$s=json_decode($q->fetchColumn(),true);
    $q=$pdo->prepare('SELECT offer_id FROM manager_tour_selection_cards WHERE publication_id=? ORDER BY card_index');$q->execute([$id]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);
    foreach ($s['offers'] as $i=>$offer) foreach ([0,1,2] as $photo) TourSelectionPhotoStore::image(TourSelectionPhotoStore::offerKey((int)$ids[$i]),0,$photo,$offer['presentation']['photos'][$photo]['url'],static fn():string=>file_get_contents(dirname(__DIR__,2).'/assets/owner-tour-cards/compact-v1/cover.jpg'));
}
function interestBusiness(PDO $pdo): array
{
    $out=[];foreach (['conversations','managers','messages','manager_conversation_reads','conversation_events','manager_tour_selection_drafts','manager_tour_selection_publications','manager_tour_selection_deliveries','manager_tour_selection_links'] as $table) $out[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY 1')->fetchAll();return $out;
}
function interestScenarios(PDO $pdo): void
{
    interestSchema($pdo);$draft=deliveryDraft();$draft['offers'][0]['hotel_group']=$draft['offers'][0]['id'];
    $other=$draft['offers'][0];$other['id']=str_repeat('b',32);$other['hotel_group']=$draft['offers'][0]['id'];$other['room']='Другой номер';$other['amount']='260000';$draft['offers'][]=$other;
    deliverySave($draft);$publication=ManagerTourSelectionDeliveryService::prepare(7,1,1)['publication'];$id=$publication['id'];interestCache($pdo,$id);
    $share=ManagerTourSelectionShareService::change(7,1,0,$id,true)['share'];$token=substr($share['path'],strlen('/selection/?t='));
    $before=interestBusiness($pdo);$cap=ManagerTourSelectionInterestService::page($token,1,'','Synthetic browser');
    deliveryCheck('ordinary page capability alone records no view and contains no private identity or signing key',(int)$pdo->query('SELECT COUNT(*) FROM manager_tour_selection_interest')->fetchColumn()===0&&!str_contains(json_encode($cap),$id)&&!str_contains(json_encode($cap),'callback_data'));
    $observation=$cap+['kind'=>'page_view','event_id'=>str_repeat('1',32),'visitor_id'=>str_repeat('2',32),'card_index'=>-1,'platform'=>''];
    $record=static fn(array $in):array=>ManagerTourSelectionInterestService::record($in,'Synthetic browser');
    deliveryCheck('visible browser observation is saved independently',$record($observation)===['ok'=>true,'saved'=>true,'duplicate'=>false]);
    deliveryCheck('identical lost-response retry is read back without another event',$record($observation)===['ok'=>true,'saved'=>true,'duplicate'=>true]&&(int)$pdo->query('SELECT COUNT(*) FROM manager_tour_selection_interest')->fetchColumn()===1);
    deliveryCheck('an event ID cannot silently change its terms',$record(array_replace($observation,['kind'=>'hotel_view','card_index'=>0]))['error']==='observation_mismatch');
    $record(array_replace($observation,['event_id'=>str_repeat('3',32)]));
    $record(array_replace($observation,['event_id'=>str_repeat('4',32),'visitor_id'=>str_repeat('5',32)]));
    foreach ([0,1] as $i) $record(array_replace($observation,['kind'=>'hotel_view','card_index'=>$i,'event_id'=>bin2hex(random_bytes(16))]));
    $record(array_replace($observation,['kind'=>'bot_open','card_index'=>1,'platform'=>'max','event_id'=>bin2hex(random_bytes(16))]));
    $summary=ManagerTourSelectionInterestService::summary(7,1,$id)['interest'];
    deliveryCheck('repeat openings and grouped hotels are separate from unique anonymous signals',$summary['totals']['browser']['page_view']===['unique'=>2,'repeat'=>1]&&$summary['totals']['browser']['hotel_view']===['unique'=>1,'repeat'=>1]);
    deliveryCheck('bot-link intent neither invents native likes nor an inquiry',$summary['totals']['browser']['bot_open']['unique']===1&&$summary['native']['liked']===0&&$summary['native']['inquiries']===0);
    deliveryCheck('anonymous observations change no conversation draft message read delivery or native event',interestBusiness($pdo)===$before);
    $bad=[['bucket'=>'preview'],['mac'=>str_repeat('f',64)],['version'=>2],['expires'=>time()-1],['kind'=>'tour_offer_liked'],['card_index'=>20,'kind'=>'hotel_view'],['kind'=>'bot_open','platform'=>'unknown','card_index'=>0],['extra'=>'private']];
    foreach ($bad as $change) deliveryCheck('forged expired wrong-version or unbounded observation is refused',empty($record(array_replace($observation,$change))['ok']));
    deliveryCheck('a forged preview flag is not trusted',ManagerTourSelectionInterestService::page($token,1,'fake','Synthetic browser')['bucket']==='browser');
    parse_str((string)parse_url($share['preview_path'],PHP_URL_QUERY),$preview);
    $pcap=ManagerTourSelectionInterestService::page($token,1,$preview['preview'],'Synthetic browser');
    deliveryCheck('protected preview link produces a separate signed observation scope',$pcap['bucket']==='preview');
    $record(array_replace($observation,$pcap,['event_id'=>bin2hex(random_bytes(16))]));
    ManagerTourSelectionInterestService::page($token,1,'','TelegramBot preview');
    ManagerTourSelectionInterestService::page($token,1,'','SyntheticCrawler');
    $summary=ManagerTourSelectionInterestService::summary(7,1,$id)['interest'];
    deliveryCheck('preview and crawler fetches do not inflate browser views',isset($summary['totals']['preview']['page_fetch'],$summary['totals']['robot']['page_fetch'])&&$summary['totals']['browser']['page_view']===['unique'=>2,'repeat'=>1]);
    deliveryCheck('owner and an already-visible read-only colleague keep canonical visibility',!empty(ManagerTourSelectionInterestService::summary(90,1,$id)['ok'])&&!empty(ManagerTourSelectionInterestService::summary(8,1,$id)['ok']));
    $pdo->exec('DELETE FROM manager_projects WHERE manager_id=8');
    deliveryCheck('revoked project access cannot read the activity',empty(ManagerTourSelectionInterestService::summary(8,1,$id)['ok']));
    $pdo->exec('INSERT INTO manager_projects(manager_id,project_id) VALUES(8,1)');
    deliveryCheck('publication ownership is not replaced by a supplied conversation ID',empty(ManagerTourSelectionInterestService::summary(7,2,$id)['ok']));
    $native=['creates'=>0,'calls'=>[]];$transport=deliveryTransport($native);ManagerTourSelectionDeliveryService::send(7,1,$id,0,$transport,static fn():array=>deliveryImages());
    $q=$pdo->prepare('SELECT callback_data FROM manager_tour_selection_cards WHERE publication_id=? AND card_index=0');$q->execute([$id]);$callback=$q->fetchColumn();
    $in=['project_key'=>'anytour','type'=>'callback','platform'=>'telegram','callback_data'=>$callback,'user'=>['external_user_id'=>'101','chat_id'=>'101'],'raw'=>['callback_query'=>['data'=>$callback,'from'=>['id'=>101],'message'=>['message_id'=>7001,'chat'=>['id'=>101,'type'=>'private']]]]];
    deliveryCheck('confirmed native like still requires its original native envelope',!empty(TourChoiceService::like($pdo,$in)['ok']));
    $offer=ManagerTourSelectionShareService::read($token)['offers'][1];unset($offer['photos'],$offer['bot_links'],$offer['hotel_group']);
    $pdo->beginTransaction();$inquiry=TourChoiceService::webInquiryInTransaction($pdo,2,'202',str_repeat('6',64),['public_offer'=>$offer,'publication_id'=>$id,'version'=>1,'card'=>1]);$pdo->commit();
    $summary=ManagerTourSelectionInterestService::summary(7,1,$id)['interest'];
    deliveryCheck('canonical confirmed like and forwarded native inquiry are aggregate-only and separate',!empty($inquiry['ok'])&&$summary['native']['liked']===1&&$summary['native']['inquiries']===1&&!str_contains(json_encode($summary),'external_user_id'));
    $pdo->exec('UPDATE conversation_sources SET is_active=0 WHERE id=1');
    deliveryCheck('inactive public source refuses even a previously issued observation',$record($observation)['error']==='unavailable');
    deliveryCheck('historical private activity follows the canonical publication read scope',!empty(ManagerTourSelectionInterestService::summary(7,1,$id)['ok'])===!empty(ManagerTourSelectionDeliveryService::get(7,1,$id)['ok']));
    $pdo->exec('UPDATE conversation_sources SET is_active=1 WHERE id=1');
    $q=$pdo->prepare('UPDATE manager_tour_selection_interest SET created_at=? WHERE event_id=?');$q->execute(['2000-01-01 00:00:00',substr(hash('sha256',$token.str_repeat('1',32)),0,32)]);
    $record(array_replace($observation,['event_id'=>bin2hex(random_bytes(16))]));
    deliveryCheck('expired anonymous projection rows are cleaned without deleting canonical receipts',(int)$pdo->query("SELECT COUNT(*) FROM manager_tour_selection_interest WHERE created_at<'2001-01-01'")->fetchColumn()===0&&(int)$pdo->query("SELECT COUNT(*) FROM conversation_events WHERE event_type='tour_web_inquiry'")->fetchColumn()===1);
    $last=$pdo->query('SELECT * FROM manager_tour_selection_interest LIMIT 1')->fetch();
    $insert=$pdo->prepare('INSERT INTO manager_tour_selection_interest(event_id,conversation_id,publication_id,link_version,visitor_hash,event_kind,bucket,card_index,hotel_group,platform,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
    $visitor=hash_hmac('sha256',json_encode(['selection-interest-v1','visitor',$observation['visitor_id']],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),ManagerTourSelectionShareService::interestContext($token,1)['key']);
    $q=$pdo->prepare('SELECT COUNT(*) FROM manager_tour_selection_interest WHERE publication_id=? AND visitor_hash=?');$q->execute([$id,$visitor]);$count=(int)$q->fetchColumn();
    for ($i=$count;$i<100;$i++) $insert->execute([bin2hex(random_bytes(16)),1,$id,1,$visitor,'page_view','browser',-1,-1,'',gmdate('Y-m-d H:i:s')]);
    deliveryCheck('per-visit abuse is bounded but exact retry remains known',$record(array_replace($observation,['event_id'=>bin2hex(random_bytes(16))]))['error']==='rate_limited'&&!empty($record(array_replace($observation,['event_id'=>str_repeat('3',32)]))['duplicate']));
    $pdo->beginTransaction();
    for ($i=0;$i<1005;$i++) $insert->execute([bin2hex(random_bytes(16)),1,$id,1,hash('sha256','bounded-fixture-'.$i),'page_view','browser',-1,-1,'',gmdate('Y-m-d H:i:s')]);
    $pdo->commit();$bounded=ManagerTourSelectionInterestService::summary(7,1,$id)['interest'];
    deliveryCheck('a capped observation read is explicitly partial and never presented as a full zero',$bounded['limited']===true&&$bounded['totals']['browser']['page_view']['unique']>0&&$bounded['native']['inquiries']===1);
    ManagerTourSelectionShareService::change(7,1,1,$id,false);
    deliveryCheck('explicit revoke invalidates already-issued observation capability',$record($observation)['error']==='unavailable'&&ManagerTourSelectionInterestService::page($token,1,'','Synthetic browser')===null);
    deliveryCheck('revoked publication retains its earlier separate signals',ManagerTourSelectionInterestService::summary(7,1,$id)['interest']['native']['inquiries']===1);
}
