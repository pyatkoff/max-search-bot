<?php
declare(strict_types=1);
require_once __DIR__.'/support/manager-tour-selection.php';
$pdo=deliveryDb();$draft=deliveryDraft();deliverySave($draft);
$preview=ManagerTourSelectionDeliveryService::prepare(7,1,1);$id=$preview['publication']['id'];
deliveryCheck('preview is a complete frozen selection without customer creation',$preview['ok']&&$preview['publication']['cards'][0]['state']==='pending'&&(int)$pdo->query('SELECT COUNT(*) FROM messages WHERE direction="outbound"')->fetchColumn()===0);
deliveryCheck('whole-party price repeated ages and quoted constraints retained',$preview['publication']['cards'][0]['snapshot']['child_ages']===[4,4]&&str_contains($preview['publication']['cards'][0]['text'],'Топливный сбор необходимо проверить')&&str_contains($preview['publication']['cards'][0]['text'],'240 000 ₽ за 4 человека'));
deliveryCheck('derived end date never appears as confirmed return',str_contains($preview['publication']['cards'][0]['text'],'С 5 июня 2027')&&!str_contains($preview['publication']['cards'][0]['text'],'12 июня')&&$preview['publication']['cards'][0]['snapshot']['date_to_basis']==='lodging_estimate');
deliveryCheck('idempotent preview exposes no callback or native identity',ManagerTourSelectionDeliveryService::prepare(7,1,1)['publication']['id']===$id&&!str_contains(json_encode($preview),'tour_like_')&&!str_contains(json_encode($preview),'external_user_id'));
deliveryCheck('colleague cannot prepare or send but can inspect visible publication',ManagerTourSelectionDeliveryService::prepare(8,1,1)['error']==='forbidden'&&ManagerTourSelectionDeliveryService::send(8,1,$id,0)['error']==='forbidden'&&ManagerTourSelectionDeliveryService::get(8,1,$id)['ok']);
deliveryCheck('other conversation cannot access publication',ManagerTourSelectionDeliveryService::get(7,2,$id)['error']==='not_found');
$state=['calls'=>[],'creates'=>0];$transport=deliveryTransport($state);$sent=ManagerTourSelectionDeliveryService::send(7,1,$id,0,$transport,'deliveryImages');
deliveryCheck('one explicit native creation reaches verified delivered state',$sent['ok']&&$sent['publication']['cards'][0]['state']==='delivered'&&$state['creates']===1);
$again=ManagerTourSelectionDeliveryService::send(7,1,$id,0,$transport,'deliveryImages');
deliveryCheck('repeat send and reopen create no second native message',$again['ok']&&$state['creates']===1&&(int)$pdo->query('SELECT COUNT(*) FROM messages WHERE direction="outbound"')->fetchColumn()===1);
deliveryCheck('read markers remain unchanged',(int)$pdo->query('SELECT last_read_message_id FROM manager_conversation_reads')->fetchColumn()===0);
$callback=$pdo->query('SELECT callback_data FROM manager_tour_selection_cards')->fetchColumn();
$incoming=TelegramIncomingAdapter::fromUpdate(['callback_query'=>['id'=>'synthetic-callback','data'=>$callback,'from'=>['id'=>101],'message'=>['message_id'=>7001,'chat'=>['id'=>101,'type'=>'private']]]]);$incoming['project_key']='anytour';
$liked=TourChoiceService::like($pdo,$incoming);
deliveryCheck('real normalized like selects the exact sent quote with all conditions',$liked['ok']&&$liked['offer']['snapshot']['amount']==='240000'&&$liked['offer']['snapshot']['quoted_details']['inclusions']==='Трансфер');
$changed=$draft;$changed['offers'][0]['amount']='250000';deliverySave($changed,1);$second=ManagerTourSelectionDeliveryService::prepare(7,1,2);
deliveryCheck('later edits cannot rewrite previously sent conditions',$second['ok']&&ManagerTourSelectionDeliveryService::get(7,1,$id)['publication']['cards'][0]['snapshot']['amount']==='240000');
deliveryCheck('stale draft revision cannot publish',ManagerTourSelectionDeliveryService::prepare(7,1,1)['error']==='draft_changed');
// Returning to the same quote after an unrelated edit reuses its delivery fence.
deliverySave($draft,2);$same=ManagerTourSelectionDeliveryService::prepare(7,1,3);
deliveryCheck('identical terms across draft versions reuse sent publication',$same['publication']['id']===$id&&$same['publication']['cards'][0]['state']==='delivered');
$extra=$draft;$extra['offers'][]=array_replace($draft['offers'][0],['id'=>str_repeat('b',32),'hotel'=>'Другой отель']);deliverySave($extra,3);$expanded=ManagerTourSelectionDeliveryService::prepare(7,1,4);
deliveryCheck('expanding selection reuses each unchanged offer delivery',$expanded['publication']['id']!==$id&&$expanded['publication']['cards'][0]['state']==='delivered'&&$expanded['publication']['cards'][0]['offer_id']===$same['publication']['cards'][0]['offer_id']);
$pdo->exec('UPDATE conversations SET manager_id=8 WHERE id=1');
deliveryCheck('reassignment blocks old manager before any network preparation',ManagerTourSelectionDeliveryService::send(7,1,$expanded['publication']['id'],1,$transport,'deliveryImages')['error']==='forbidden'&&$state['creates']===1);
$pdo->exec('UPDATE conversations SET manager_id=7 WHERE id=1');
$state['mode']='unknown';$unknown=ManagerTourSelectionDeliveryService::send(7,1,$expanded['publication']['id'],1,$transport,'deliveryImages');
deliveryCheck('missing native result remains unknown without fabricated delivery',$unknown['error']==='delivery_unknown'&&$state['creates']===2&&(int)$pdo->query('SELECT COUNT(*) FROM messages WHERE direction="outbound"')->fetchColumn()===1);
deliveryCheck('unknown creation can never be replayed',ManagerTourSelectionDeliveryService::send(7,1,$expanded['publication']['id'],1,$transport,'deliveryImages')['error']==='delivery_unknown'&&$state['creates']===2);
$pdo->exec('DELETE FROM manager_projects WHERE manager_id=7');
deliveryCheck('revoked visibility also denies history and images',ManagerTourSelectionDeliveryService::history(7,1)['error']==='not_found'&&ManagerTourSelectionDeliveryService::photo(7,1,$id,0,0)===null);

$pdo=deliveryDb();deliverySave(deliveryDraft(),0,2);$max=ManagerTourSelectionDeliveryService::prepare(7,2,1);$pub=$max['publication']['id'];
$q=$pdo->prepare('SELECT callback_data FROM manager_tour_selection_cards WHERE publication_id=?');$q->execute([$pub]);$cb=$q->fetchColumn();
$offer=ManagerTourSelectionPublicationDraft::build(deliveryDraft())['offers'][0];$state=['calls'=>[],'creates'=>0,'readback'=>false,'card'=>TourOfferCardRenderer::render($offer['snapshot'],$offer['presentation'],$cb)];$transport=deliveryTransport($state);
$unverified=ManagerTourSelectionDeliveryService::send(7,2,$pub,0,$transport,'deliveryImages');
deliveryCheck('MAX known MID is retained when native readback is temporarily unavailable',$unverified['error']==='delivery_unverified'&&$unverified['publication']['cards'][0]['state']==='known'&&$state['creates']===1);
$state['readback']=true;$recovered=ManagerTourSelectionDeliveryService::send(7,2,$pub,0,$transport,'deliveryImages');
deliveryCheck('known-MID recovery verifies without another creation',$recovered['ok']&&$recovered['publication']['cards'][0]['state']==='delivered'&&$state['creates']===1);
// A confirmed native creation remains recoverable when the local mirror fails.
$pdo=deliveryDb();deliverySave(deliveryDraft());$prepared=ManagerTourSelectionDeliveryService::prepare(7,1,1);$publication=$prepared['publication']['id'];$state=['calls'=>[],'creates'=>0];$transport=deliveryTransport($state);
$pdo->exec("CREATE TRIGGER reject_selection_mirror BEFORE INSERT ON messages WHEN NEW.direction='outbound' BEGIN SELECT RAISE(ABORT,'synthetic mirror failure'); END");
$failed=false;try {ManagerTourSelectionDeliveryService::send(7,1,$publication,0,$transport,'deliveryImages');} catch (RuntimeException $e) {$failed=true;}
$pdo->exec('DROP TRIGGER reject_selection_mirror');
deliveryCheck('mirror failure retains known creation and rolls back canonical delivery',$failed&&$state['creates']===1&&ManagerTourSelectionDeliveryService::get(7,1,$publication)['publication']['cards'][0]['state']==='known'&&(int)$pdo->query("SELECT COUNT(*) FROM conversation_events WHERE event_type='tour_offer_delivered'")->fetchColumn()===0);
$reconciled=ManagerTourSelectionDeliveryService::send(7,1,$publication,0,$transport,'deliveryImages');
deliveryCheck('recovery atomically records one transcript and one canonical delivery',$reconciled['ok']&&$state['creates']===1&&(int)$pdo->query("SELECT COUNT(*) FROM messages WHERE direction='outbound'")->fetchColumn()===1&&(int)$pdo->query("SELECT COUNT(*) FROM conversation_events WHERE event_type='tour_offer_delivered'")->fetchColumn()===1);
$pdo=deliveryDb();deliverySave(deliveryDraft());$pub=ManagerTourSelectionDeliveryService::prepare(7,1,1)['publication']['id'];$state=['calls'=>[],'creates'=>0,'mode'=>'rejected'];$transport=deliveryTransport($state);
$rejection=ManagerTourSelectionDeliveryService::send(7,1,$pub,0,$transport,'deliveryImages');
deliveryCheck('explicit native rejection is not mislabeled UNKNOWN',$rejection['error']==='delivery_rejected'&&$rejection['publication']['cards'][0]['state']==='rejected'&&(int)$pdo->query("SELECT COUNT(*) FROM messages WHERE direction='outbound'")->fetchColumn()===0);
$state['mode']='success';$retry=ManagerTourSelectionDeliveryService::send(7,1,$pub,0,$transport,'deliveryImages');
deliveryCheck('explicit retry after proved rejection can create exactly one delivered card',$retry['ok']&&$state['creates']===2&&(int)$pdo->query("SELECT COUNT(*) FROM messages WHERE direction='outbound'")->fetchColumn()===1);
$fake=deliveryDraft();$fake['offers'][0]['photos']=[];$validation=ManagerTourSelectionPublicationDraft::build($fake);
deliveryCheck('complete publication refuses missing photos while draft remains editable',$validation['error']==='incomplete_offers'&&in_array('photos',$validation['fields'][0]['fields'],true)&&ManagerTourSelectionDraft::normalize($fake)!==null);
$long=deliveryDraft();foreach (['flights'=>1200,'constraints'=>1200,'inclusions'=>600,'rationale'=>600,'compromise'=>600] as $field=>$length) $long['offers'][0][$field]=str_repeat('А',$length);
deliveryCheck('long native cards fail preview instead of silently truncating conditions',ManagerTourSelectionPublicationDraft::build($long)['error']==='card_too_long');
foreach (['127.0.0.1','10.1.2.3','169.254.169.254','100.64.1.1','192.168.1.1','198.18.0.1','203.0.113.1','224.0.0.1','::1','::ffff:127.0.0.1'] as $ip) deliveryCheck('source address rejects '.$ip,!TourSelectionPhotoStore::publicAddress($ip));
deliveryCheck('globally routable IPv4 passes address classification',TourSelectionPhotoStore::publicAddress('8.8.8.8'));
foreach (['http://photos.example.org/a.jpg','https://127.0.0.1/a.jpg','https://foo.local/a.jpg','https://a.example.org:443/a.jpg','https://u:p@a.example.org/a.jpg','https://a.example.org/a.jpg#fragment',"https://a.example.org/\\@localhost"] as $url) deliveryCheck('unsafe photo source rejected',TourSelectionPhotoStore::source($url)===null);
// Real private filesystem bytes and failure boundary, with no network access.
$photoDirectory=sys_get_temp_dir().'/tour-selection-photos-fixture-'.bin2hex(random_bytes(8));mkdir($photoDirectory,0700);$downloads=0;
$download=static function(string $url) use (&$downloads):string {$downloads++;return file_get_contents(dirname(__DIR__).'/assets/owner-tour-cards/compact-v1/cover.jpg');};
try {
    DiagnosticLogger::setFile(dirname(__DIR__).'/unsafe-public-test.log');$blocked=false;
    try {TourSelectionPhotoStore::image(str_repeat('a',32),0,0,'https://photos.example.org/cover.jpg',$download);} catch (RuntimeException $e) {$blocked=$e->getMessage()==='photo_storage_unavailable';}
    deliveryCheck('unavailable private path never falls back to the public checkout',$blocked&&$downloads===0&&!is_dir(dirname(__DIR__).'/tour-selection-photos'));
    DiagnosticLogger::setFile($photoDirectory.'/events.log');
    $image=TourSelectionPhotoStore::image(str_repeat('a',32),0,0,'https://photos.example.org/cover.jpg',$download);
    $repeat=TourSelectionPhotoStore::image(str_repeat('a',32),0,0,'https://photos.example.org/cover.jpg',$download);
    deliveryCheck('actual JPEG bytes are retained privately and reused without another download',$downloads===1&&$repeat['sha256']===$image['sha256']&&hash_file('sha256',$image['path'])===$image['sha256']&&(fileperms($image['path'])&0777)===0600&&str_starts_with($image['path'],$photoDirectory.'/'));
    $raw=file_get_contents(dirname(__DIR__).'/assets/owner-tour-cards/compact-v1/cover.jpg');
    $upload=['version'=>1,'offer_id'=>str_repeat('a',32),'kind'=>'cover','operation_id'=>str_repeat('c',32),'image_base64'=>base64_encode($raw)];
    $pdo=deliveryDb();deliverySave(deliveryDraft());
    $beforeMessages=(int)$pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn();
    $u=ManagerTourSelectionService::uploadPhoto(7,1,$upload);$url=$u['photo']['url'];
    deliveryCheck('photo file upload retains exact private bytes without a draft or message write',$u['ok']&&$u['sha256']===hash('sha256',$raw)&&ManagerTourSelectionService::get(7,1)['selection']['version']===1&&(int)$pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn()===$beforeMessages);
    $retry=ManagerTourSelectionService::uploadPhoto(7,1,$upload);
    deliveryCheck('same file retry returns the identical immutable reference',$retry===$u);
    $different=$upload;$different['image_base64']=base64_encode(file_get_contents(dirname(__DIR__).'/assets/owner-tour-cards/compact-v1/beach.jpg'));
    deliveryCheck('different bytes cannot replace a completed upload operation',ManagerTourSelectionService::uploadPhoto(7,1,$different)['error']==='operation_mismatch');
    deliveryCheck('readonly and stale draft cannot upload',ManagerTourSelectionService::uploadPhoto(8,1,$upload)['error']==='forbidden'&&ManagerTourSelectionService::uploadPhoto(7,1,array_replace($upload,['version'=>2]))['error']==='draft_changed');
    foreach (['<svg onload="alert(1)"></svg>',str_repeat('x',TourSelectionPhotoStore::MAX_BYTES+1),'not an image'] as $bytes) {
        $bad=array_replace($upload,['operation_id'=>bin2hex(random_bytes(16)),'image_base64'=>base64_encode($bytes)]);
        deliveryCheck('non-image or oversized uploaded bytes are rejected',ManagerTourSelectionService::uploadPhoto(7,1,$bad)['error']==='invalid_photo');
    }
    $withFile=deliveryDraft();$withFile['offers'][0]['photos'][0]=$u['photo'];$saved=deliverySave($withFile,1);
    $foreign=deliverySave($withFile,0,2);
    deliveryCheck('file reference saves only in its original conversation',$saved['ok']&&$foreign['error']==='invalid_photo');
    $prepared=ManagerTourSelectionDeliveryService::prepare(7,1,2);$cardId=$prepared['publication']['id'];
    $cached=ManagerTourSelectionDeliveryService::photo(7,1,$cardId,0,0);
    deliveryCheck('uploaded bytes feed the canonical preview cache without a network download',$prepared['ok']&&$cached['sha256']===hash('sha256',$raw));
    $copy=$pdo->prepare('INSERT INTO manager_tour_selection_drafts(conversation_id,version,draft_json,updated_by_manager_id,updated_at) VALUES(2,1,?,7,?)');$copy->execute([json_encode($withFile),gmdate('Y-m-d H:i:s')]);
    deliveryCheck('legacy forged cross-client reference cannot prepare',ManagerTourSelectionDeliveryService::prepare(7,2,1)['error']==='invalid_photo');
    $asset=parse_url($url);parse_str($asset['query'],$params);$pdo->exec('UPDATE conversations SET manager_id=8 WHERE id=1');
    deliveryCheck('reassignment removes former manager upload permission',ManagerTourSelectionService::uploadPhoto(7,1,$upload)['error']==='forbidden');
    $pdo->exec('DELETE FROM manager_projects WHERE manager_id=7');
    deliveryCheck('project revocation removes private photo access',ManagerTourSelectionService::uploadedPhoto(7,1,$params['draft_photo'])===null);
    file_put_contents($image['path'],'corrupted');$blocked=false;
    try {TourSelectionPhotoStore::image(str_repeat('a',32),0,0,'https://photos.example.org/cover.jpg',$download);} catch (RuntimeException $e) {$blocked=true;}
    deliveryCheck('changed frozen image bytes fail closed without source refresh',$blocked&&$downloads===1);
} finally {
    DiagnosticLogger::setFile('');
    $remove=static function(string $path) use (&$remove):void {if (is_link($path)||is_file($path)) {unlink($path);return;}foreach (scandir($path)?:[] as $part) if ($part!=='.'&&$part!=='..') $remove($path.'/'.$part);rmdir($path);};$remove($photoDirectory);
}
echo "TOTAL {$checks} | PASS {$checks} | FAIL 0\n";
